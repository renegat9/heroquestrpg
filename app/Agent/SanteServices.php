<?php

declare(strict_types=1);

namespace App\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * État observable PAR SERVICE EXTERNE (ou interne) de la page Système
 * (demande de René, 2026-10-02 : « voir l'état des services externes, s'il y
 * a toujours du crédit disponible et que le service fonctionne »). Même
 * patron que {@see StatutIA} (qui ne couvre que le LLM texte du MJ et sa
 * bascule anthropic/gemini) : ici un enregistrement PAR SERVICE (Anthropic,
 * Gemini texte/TTS/image, Voyage, Qdrant), alimenté depuis le point de
 * passage UNIQUE de chaque client où l'issue HTTP finale est connue.
 *
 * ⚠ AUCUN fournisseur n'expose le crédit prépayé restant — vérifié dans la
 * documentation officielle (2026-10-02) : pas d'endpoint de solde chez
 * Anthropic/Gemini/Voyage, et l'API Admin d'Anthropic (coûts historiques,
 * clé admin) n'est de toute façon pas accessible à ce compte. La seule chose
 * observable est l'ISSUE du dernier appel réel : {@see self::classer()} range
 * cette issue dans une catégorie, que `SystemeController` utilise ENSUITE
 * pour INFÉRER un verdict de crédit — un signal, jamais une lecture de solde,
 * et le payload le dit en toutes lettres (jamais maquillé en certitude).
 *
 * Cache PARTAGÉ (`CACHE_STORE=database`) entre `queue`/`queue-jeu` (qui
 * écrivent pendant les jobs IA et les commandes de génération hors-ligne) et
 * `app` (qui lit pour `GET /api/systeme`). `Cache::forever` : pas de TTL à
 * gérer, toujours écrasé au dernier essai réel.
 *
 * Best-effort ABSOLU (même règle que {@see TraceurConsommation} face à ses
 * dépendances externes) : un échec d'écriture de cette télémétrie ne doit
 * JAMAIS faire échouer un appel du MJ ou une commande de génération. Les deux
 * méthodes d'écriture avalent donc elles-mêmes leurs exceptions.
 */
final class SanteServices
{
    private const PREFIXE = 'sante:service:';

    /** Les six services suivis — doit rester synchrone avec `SystemeController`. */
    public const SERVICES = ['anthropic', 'gemini_texte', 'gemini_tts', 'gemini_image', 'voyage', 'qdrant'];

    /** Longueur max du message d'échec conservé. */
    private const LONGUEUR_MESSAGE = 300;

    public static function signalerSucces(string $service, string $origine = 'jeu'): void
    {
        self::ecrire($service, function (array $etat) use ($origine) {
            $etat['derniere_reussite'] = now()->toIso8601String();
            $etat['derniere_reussite_origine'] = $origine;

            return $etat;
        });
    }

    /**
     * $origine distingue un appel de JEU (skill réel, génération hors-ligne)
     * d'un appel de TEST explicite (`POST /api/systeme/tester`) — sert à
     * `SystemeController::verdictCredit()` à afficher la bonne provenance du
     * signal de crédit plutôt que de laisser croire que le joueur a consommé
     * du crédit à son insu.
     */
    public static function signalerEchec(string $service, int $statutHttp, ?string $corps, string $origine = 'jeu'): void
    {
        self::ecrire($service, function (array $etat) use ($service, $statutHttp, $corps, $origine) {
            $etat['dernier_echec'] = [
                'a' => now()->toIso8601String(),
                'statut_http' => $statutHttp,
                'categorie' => self::classer($service, $statutHttp, $corps),
                // Jamais la clé : les erreurs standard des trois fournisseurs
                // ne la contiennent jamais (elle part en en-tête, pas dans le
                // corps), et on tronque de toute façon.
                'message' => mb_substr(self::messageDepuisCorps($corps), 0, self::LONGUEUR_MESSAGE),
                'origine' => $origine,
            ];

            return $etat;
        });
    }

    /**
     * Retague le dernier événement connu (succès OU échec, le plus récent des
     * deux) comme venant d'un TEST explicite plutôt que d'un appel de jeu —
     * appelé par `SystemeController::tester()` juste après un appel réel dont
     * le client a déjà enregistré l'issue par défaut en `origine: 'jeu'`.
     * Évite de dupliquer l'appel HTTP pour recalculer un statut/corps déjà
     * connu du client.
     */
    public static function marquerDernierCommeTest(string $service): void
    {
        self::ecrire($service, function (array $etat) {
            $succesA = $etat['derniere_reussite'] ?? null;
            $echecA = $etat['dernier_echec']['a'] ?? null;

            if ($succesA !== null && ($echecA === null || $succesA >= $echecA)) {
                $etat['derniere_reussite_origine'] = 'test';
            } elseif ($echecA !== null) {
                $etat['dernier_echec']['origine'] = 'test';
            }

            return $etat;
        });
    }

    /** @return array<string, mixed> */
    public static function etat(string $service): array
    {
        return Cache::get(self::cle($service), [
            'derniere_reussite' => null,
            'derniere_reussite_origine' => null,
            'dernier_echec' => null,
        ]);
    }

    /**
     * Classifie une réponse HTTP en échec en une catégorie actionnable.
     * ⚠ HEURISTIQUE documentée, pas un contrat garanti des fournisseurs :
     * seule la forme Anthropic (`error.type` explicite) est stable et
     * sourcée (doc fournisseur, 2026-10-02). La distinction quota JOURNALIER
     * vs limite de débit TRANSITOIRE chez Gemini (tous deux des 429
     * `RESOURCE_EXHAUSTED`) s'appuie sur la présence d'un `quotaMetric`
     * mentionnant un cycle "par jour" dans `error.details` — nullement
     * garanti stable par Google, donc documentée ici comme best-effort :
     * à défaut de métrique identifiable, un 429 Gemini est rangé en
     * `limite_debit` (le cas le plus fréquent en jeu).
     */
    public static function classer(string $service, int $statutHttp, ?string $corps): string
    {
        if ($statutHttp === 0) {
            return 'indisponible'; // jamais de réponse HTTP : réseau/timeout
        }

        $json = $corps !== null ? json_decode($corps, true) : null;
        $json = is_array($json) ? $json : [];
        $message = mb_strtolower((string) (data_get($json, 'error.message') ?? data_get($json, 'error') ?? ''));

        if ($service === 'anthropic') {
            $type = (string) data_get($json, 'error.type', '');

            return match (true) {
                $type === 'authentication_error', $type === 'permission_error' => 'cle_invalide',
                $type === 'billing_error' => 'credit_epuise',
                $type === 'rate_limit_error' => 'limite_debit',
                $type === 'overloaded_error', $type === 'api_error' => 'indisponible',
                // Un 400 invalid_request_error dont le message parle du solde
                // de crédit est aussi un épuisement (doc Anthropic 2026-10-02).
                $type === 'invalid_request_error' && str_contains($message, 'credit') => 'credit_epuise',
                $statutHttp >= 500 || $statutHttp === 529 => 'indisponible',
                $statutHttp === 429 => 'limite_debit',
                $statutHttp === 401 || $statutHttp === 403 => 'cle_invalide',
                default => 'autre',
            };
        }

        if (str_starts_with($service, 'gemini')) {
            $statutTexte = (string) data_get($json, 'error.status', '');

            if ($statutTexte === 'API_KEY_INVALID' || $statutHttp === 401 || $statutHttp === 403 || str_contains($message, 'api key')) {
                return 'cle_invalide';
            }

            if ($statutTexte === 'RESOURCE_EXHAUSTED' || $statutHttp === 429) {
                foreach ((array) data_get($json, 'error.details', []) as $detail) {
                    foreach ((array) ($detail['violations'] ?? []) as $violation) {
                        $metrique = mb_strtolower((string) ($violation['quotaMetric'] ?? ''));
                        if (str_contains($metrique, 'perday') || str_contains($metrique, 'per_day') || str_contains($metrique, 'daily')) {
                            return 'quota_atteint';
                        }
                    }
                }

                return 'limite_debit';
            }

            if ($statutHttp >= 500) {
                return 'indisponible';
            }

            if (str_contains($message, 'credit') || str_contains($message, 'billing')) {
                return 'credit_epuise';
            }

            return 'autre';
        }

        // Voyage, Qdrant et tout service futur : leurs formes d'erreur exactes
        // ne sont pas sourcées — classification générique sur le seul code HTTP.
        return match (true) {
            $statutHttp === 401 || $statutHttp === 403 => 'cle_invalide',
            $statutHttp === 429 => 'limite_debit',
            $statutHttp >= 500 => 'indisponible',
            str_contains($message, 'credit') || str_contains($message, 'billing') => 'credit_epuise',
            default => 'autre',
        };
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutateur
     */
    private static function ecrire(string $service, \Closure $mutateur): void
    {
        try {
            Cache::forever(self::cle($service), $mutateur(self::etat($service)));
        } catch (Throwable $e) {
            Log::warning('Santé service non enregistrée (best effort) — appel non affecté.', [
                'service' => $service,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    private static function cle(string $service): string
    {
        return self::PREFIXE.$service;
    }

    private static function messageDepuisCorps(?string $corps): string
    {
        if ($corps === null || trim($corps) === '') {
            return 'réponse sans corps exploitable';
        }

        $json = json_decode($corps, true);

        if (is_array($json)) {
            $message = data_get($json, 'error.message') ?? data_get($json, 'error');
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return mb_substr($corps, 0, self::LONGUEUR_MESSAGE);
    }
}
