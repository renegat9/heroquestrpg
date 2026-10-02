<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\AnthropicClient;
use App\Agent\Exceptions\AppelLlmException;
use App\Agent\GeminiClient;
use App\Agent\Memoire\BibleQdrant;
use App\Agent\Memoire\EmbeddingsVoyage;
use App\Agent\SanteFileAttente;
use App\Agent\SanteServices;
use App\Agent\TraceurConsommation;
use App\Http\Controllers\Controller;
use App\Models\ConsommationIa;
use App\Models\Parametre;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Page « Système » (René, 2026-10-02) : « voir l'état des services externes,
 * s'il y a toujours du crédit disponible et que le service fonctionne ».
 *
 * ⚠ AUCUN fournisseur (Anthropic, Gemini, Voyage) n'expose le crédit prépayé
 * restant — vérifié dans leur documentation officielle (2026-10-02) : pas
 * d'endpoint de solde, et l'API Admin d'Anthropic (coûts historiques) exige
 * une clé admin distincte, inaccessible à un compte individuel, et ne donne
 * de toute façon qu'un historique, pas un solde. Le verdict de crédit affiché
 * ici est donc TOUJOURS une INFÉRENCE, jamais une lecture — construite à
 * partir de (1) la classification de l'issue du dernier appel réel
 * ({@see SanteServices}), (2) un test payant explicite que le joueur
 * déclenche lui-même (`POST /api/systeme/tester`), (3) notre propre
 * télémétrie de tokens ({@see ConsommationIa::agregat()}). Chaque verdict
 * porte son `explication` en toutes lettres plutôt que de se faire passer
 * pour une certitude — hard rule « le serveur publie la DÉCISION » : c'est
 * aussi vrai d'un aveu d'incertitude que d'un fait positif.
 *
 * Routes PUBLIQUES, comme `/api/parametres` et `/api/guide` : la page doit
 * s'ouvrir depuis la table sans compte ni mot de passe narrateur (LAN entre
 * amis). Jamais aucune clé API dans la réponse.
 *
 * Sondes automatiques GRATUITES (jamais de crédit consommé) mises en cache
 * ~60 s (`Cache::remember`) pour qu'une page laissée ouverte (rafraîchie
 * toutes les 30 s côté front) ne martèle ni les fournisseurs ni Qdrant/MariaDB.
 * Chaque sonde est isolée dans son propre try/catch avec un timeout court
 * (~3 s) : un fournisseur en panne ne doit jamais empêcher d'afficher l'état
 * des autres services, ni casser la page.
 */
class SystemeController extends Controller
{
    private const TIMEOUT_SONDE = 3;

    /** GET /api/systeme */
    public function index(): JsonResponse
    {
        return response()->json(Cache::remember('systeme:payload', 60, fn () => $this->construirePayload()));
    }

    /**
     * POST /api/systeme/tester {service} — contrepartie PAYANTE et EXPLICITE
     * des sondes gratuites de `index()` : un mini-appel RÉEL (donc facturé)
     * au service précis demandé, déclenché uniquement sur action du joueur,
     * jamais automatiquement. Le résultat retague la dernière entrée
     * `SanteServices` du service en `origine: 'test'` (voir
     * `SanteServices::marquerDernierCommeTest()`) pour que le verdict de
     * crédit affiche honnêtement d'où vient son signal.
     */
    /**
     * Services éligibles à CET endpoint — `SanteServices::SERVICES` moins
     * `qdrant` : interne, sans test payant possible (pas de notion de crédit,
     * sondé gratuitement par `index()` via `BibleQdrant::infoSante()`).
     */
    private const SERVICES_TESTABLES_ENDPOINT = ['anthropic', 'gemini_texte', 'gemini_tts', 'gemini_image', 'voyage'];

    public function tester(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'service' => ['required', Rule::in(self::SERVICES_TESTABLES_ENDPOINT)],
        ]);
        $service = $donnees['service'];

        // TTS/Image : quotas gratuits mesurés en dizaines/centaines de
        // requêtes PAR JOUR (ex. Gemini TTS ~100/jour) partagés par TOUTE la
        // table — jamais brûlés par un bouton qu'on peut cliquer en boucle.
        // `test-voix` (Réglages) reste le chemin légitime pour la voix (mis
        // en cache par voix) ; aucun équivalent bon marché n'existe pour une
        // image, donc 422 explicite plutôt qu'un appel caché.
        if (in_array($service, ['gemini_tts', 'gemini_image'], true)) {
            return response()->json([
                'ok' => false,
                'service' => $service,
                'erreur' => $service === 'gemini_tts'
                    ? "Test désactivé pour la voix : le quota Gemini TTS (~100 requêtes/jour) est partagé par toute la table. Utilisez « Écouter » dans Réglages (mis en cache par voix) plutôt qu'un test à répétition."
                    : "Test désactivé pour les images : aucun appel bon marché n'existe chez ce fournisseur pour une simple vérification. Laissez le jeu générer une illustration réelle, ou vérifiez la console Google AI Studio.",
            ], 422);
        }

        if ($service === 'voyage') {
            return $this->testerVoyage();
        }

        return $this->testerLlm($service);
    }

    private function testerVoyage(): JsonResponse
    {
        if (blank(config('services.voyage.api_key'))) {
            throw ValidationException::withMessages(['service' => 'Aucune clé Voyage AI configurée.']);
        }

        app(TraceurConsommation::class)->pourGroupe(null, 'test_systeme');
        $depart = microtime(true);

        try {
            $vecteur = (new EmbeddingsVoyage)->vecteur('test de connectivité — page Système', requete: true);
            SanteServices::marquerDernierCommeTest('voyage');

            return response()->json([
                'ok' => true,
                'service' => 'voyage',
                'duree_ms' => (int) round((microtime(true) - $depart) * 1000),
                'extrait' => count($vecteur).' dimensions reçues',
            ]);
        } catch (AppelLlmException $e) {
            SanteServices::marquerDernierCommeTest('voyage');

            return response()->json([
                'ok' => false,
                'service' => 'voyage',
                'duree_ms' => (int) round((microtime(true) - $depart) * 1000),
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    private function testerLlm(string $service): JsonResponse
    {
        $fournisseur = $service === 'gemini_texte' ? 'gemini' : 'anthropic';
        $cle = $fournisseur === 'gemini' ? config('services.gemini.api_key') : config('services.anthropic.api_key');

        if (blank($cle)) {
            throw ValidationException::withMessages([
                'service' => "Aucune clé API serveur configurée pour « {$fournisseur} ».",
            ]);
        }

        $client = $fournisseur === 'gemini'
            ? app()->make(GeminiClient::class, ['timeout' => 15])
            : app()->make(AnthropicClient::class, ['timeout' => 15]);

        app(TraceurConsommation::class)->pourGroupe(null, 'test_systeme');
        $depart = microtime(true);

        try {
            // max_tokens: 1 côté Anthropic (le test ne veut payer aucun jeton
            // de sortie) ; Gemini n'a pas d'équivalent exposé ici, l'appel
            // texte libre (non structuré) reste déjà minimal.
            $texte = $fournisseur === 'anthropic'
                ? $client->genererTexte('Tu es un test de connectivité. Réponds uniquement « OK ».', [['role' => 'user', 'content' => 'Réponds uniquement « OK ».']], maxTokens: 1)
                : $client->genererTexte('Tu es un test de connectivité. Réponds uniquement « OK ».', [['role' => 'user', 'content' => 'Réponds uniquement « OK ».']]);

            SanteServices::marquerDernierCommeTest($service);

            return response()->json([
                'ok' => true,
                'service' => $service,
                'duree_ms' => (int) round((microtime(true) - $depart) * 1000),
                'extrait' => mb_substr(trim($texte), 0, 60),
            ]);
        } catch (AppelLlmException $e) {
            SanteServices::marquerDernierCommeTest($service);

            return response()->json([
                'ok' => false,
                'service' => $service,
                'duree_ms' => (int) round((microtime(true) - $depart) * 1000),
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function construirePayload(): array
    {
        $probeGemini = $this->sondeGemini();

        $services = [
            $this->serviceAnthropic(),
            $this->serviceGeminiSous('gemini_texte', 'Gemini (texte)', (string) (Parametre::actuel()->modele_gemini ?: config('services.gemini.model_texte')), $probeGemini, 'le jeu tourne sans, avec narration scriptée'),
            $this->serviceGeminiSous('gemini_tts', 'Gemini (voix / TTS)', (string) config('services.gemini.model'), $probeGemini, 'les barks se lisent via la voix du navigateur'),
            $this->serviceGeminiSous('gemini_image', 'Gemini (images)', (string) config('services.gemini.model_image'), $probeGemini, "les illustrations retombent sur les icônes"),
            $this->serviceVoyage(),
            $this->serviceQdrant(),
            $this->serviceMariaDb(),
            $this->serviceReverb(),
            $this->serviceQueue('queue', 'temps-reel,default'),
            $this->serviceQueue('queue-jeu', 'temps-reel'),
            $this->serviceBackups(),
        ];

        return [
            'genere_a' => now()->toIso8601String(),
            'services' => $services,
            'consommation' => ConsommationIa::agregat(),
            'avertissements' => $this->avertissements($services),
        ];
    }

    // -------------------------------------------------------------------
    // Services externes
    // -------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function serviceAnthropic(): array
    {
        $cle = config('services.anthropic.api_key');
        if (blank($cle)) {
            return $this->nonConfigure('anthropic', 'Anthropic (Claude)', 'externe',
                'Non configuré — le jeu tourne sans, avec narration scriptée.',
                'https://console.anthropic.com/settings/billing');
        }

        $depart = microtime(true);
        $probe = null;
        $erreurReseau = null;
        try {
            $probe = Http::withHeaders([
                'x-api-key' => $cle,
                'anthropic-version' => '2023-06-01',
            ])->timeout(self::TIMEOUT_SONDE)
                ->get(rtrim((string) config('services.anthropic.base_url', 'https://api.anthropic.com'), '/').'/v1/models');
        } catch (Throwable $e) {
            $erreurReseau = $e->getMessage();
        }
        $latence = (int) round((microtime(true) - $depart) * 1000);

        $sante = SanteServices::etat('anthropic');
        $credit = $this->verdictCredit($sante);

        if ($erreurReseau !== null) {
            $etat = 'panne';
            $detail = "Injoignable (réseau/timeout) : {$erreurReseau}";
        } elseif ($probe->failed()) {
            $categorie = SanteServices::classer('anthropic', $probe->status(), $probe->body());
            $etat = $categorie === 'limite_debit' ? 'degrade' : 'panne';
            $detail = match ($categorie) {
                'cle_invalide' => 'Clé API invalide ou révoquée (sonde gratuite GET /v1/models).',
                'limite_debit' => 'Limite de débit atteinte à l\'instant (sonde GET /v1/models).',
                default => "Sonde GET /v1/models en échec (HTTP {$probe->status()}).",
            };
        } else {
            $etat = 'ok';
            $detail = 'Clé valide, API joignable (sonde gratuite GET /v1/models — ne mesure pas le crédit).';
        }

        [$etat, $detail] = $this->ajusterSelonCredit($etat, $detail, $credit);

        return $this->fabriquerService('anthropic', 'Anthropic (Claude)', 'externe', $etat, $detail, $latence, $sante, $credit, 'https://console.anthropic.com/settings/billing');
    }

    /** @return array{configuree: bool, erreur?: string, statut?: int, corps?: string, modeles?: list<string>, latence_ms?: int} */
    private function sondeGemini(): array
    {
        $cle = config('services.gemini.api_key');
        if (blank($cle)) {
            return ['configuree' => false];
        }

        $depart = microtime(true);
        try {
            $reponse = Http::timeout(self::TIMEOUT_SONDE)
                ->withHeaders(['x-goog-api-key' => $cle])
                ->get(rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com'), '/').'/v1beta/models');
        } catch (Throwable $e) {
            return ['configuree' => true, 'erreur' => $e->getMessage(), 'latence_ms' => (int) round((microtime(true) - $depart) * 1000)];
        }
        $latence = (int) round((microtime(true) - $depart) * 1000);

        if ($reponse->failed()) {
            return ['configuree' => true, 'statut' => $reponse->status(), 'corps' => $reponse->body(), 'latence_ms' => $latence];
        }

        $modeles = collect((array) $reponse->json('models', []))
            ->pluck('name')
            ->map(fn ($nom) => basename((string) $nom))
            ->values()
            ->all();

        return ['configuree' => true, 'statut' => 200, 'modeles' => $modeles, 'latence_ms' => $latence];
    }

    /**
     * @param  array{configuree: bool, erreur?: string, statut?: int, corps?: string, modeles?: list<string>, latence_ms?: int}  $probe
     * @return array<string, mixed>
     */
    private function serviceGeminiSous(string $id, string $libelle, string $modeleConfigure, array $probe, string $phraseReplis): array
    {
        if (! $probe['configuree']) {
            return $this->nonConfigure($id, $libelle, 'externe', "Non configuré — {$phraseReplis}.", 'https://aistudio.google.com/');
        }

        $sante = SanteServices::etat($id);
        $credit = $this->verdictCredit($sante);

        if (isset($probe['erreur'])) {
            $etat = 'panne';
            $detail = "Injoignable (réseau/timeout) : {$probe['erreur']}";
        } elseif (($probe['statut'] ?? 0) !== 200) {
            $categorie = SanteServices::classer($id, $probe['statut'] ?? 0, $probe['corps'] ?? null);
            $etat = $categorie === 'limite_debit' ? 'degrade' : 'panne';
            $detail = "Sonde gratuite GET /v1beta/models en échec (HTTP {$probe['statut']}, {$categorie}).";
        } elseif ($modeleConfigure !== '' && ! in_array($modeleConfigure, $probe['modeles'] ?? [], true)) {
            $etat = 'degrade';
            $detail = "API joignable, mais le modèle configuré « {$modeleConfigure} » n'apparaît pas dans la liste /v1beta/models.";
        } else {
            $etat = 'ok';
            $detail = 'Clé valide, API joignable, modèle configuré présent (sonde gratuite — ne mesure pas le crédit).';
        }

        [$etat, $detail] = $this->ajusterSelonCredit($etat, $detail, $credit);

        return $this->fabriquerService($id, $libelle, 'externe', $etat, $detail, $probe['latence_ms'] ?? null, $sante, $credit, 'https://aistudio.google.com/');
    }

    /** @return array<string, mixed> */
    private function serviceVoyage(): array
    {
        $cle = config('services.voyage.api_key');
        if (blank($cle)) {
            return $this->nonConfigure('voyage', 'Voyage AI (embeddings)', 'externe',
                'Non configuré — la bible RAG retombe sur une similarité lexicale, sans appel externe.',
                'https://dashboard.voyageai.com/');
        }

        $sante = SanteServices::etat('voyage');
        $credit = $this->verdictCredit($sante);

        // Aucune sonde gratuite documentée chez Voyage (API embeddings
        // uniquement — un appel réel consomme des jetons) : l'état s'appuie
        // SEULEMENT sur le dernier appel réel (jeu ou test), jamais sondé
        // automatiquement.
        $dernierEvenementEstEchec = $this->dernierEvenementEstEchec($sante);

        $etat = match (true) {
            $credit['etat'] === 'epuise' => 'panne',
            $credit['etat'] === 'quota_atteint' => 'degrade',
            $dernierEvenementEstEchec => 'degrade',
            $sante['derniere_reussite'] !== null => 'ok',
            default => 'inconnu',
        };
        $detail = match ($etat) {
            'ok' => 'Dernier appel réel réussi — pas de sonde gratuite disponible chez ce fournisseur.',
            'degrade', 'panne' => 'Dernier appel réel en échec — voir le détail ci-dessous.',
            default => "Aucun appel réel enregistré depuis le dernier redémarrage du cache — pas de sonde gratuite disponible ; jouez une action qui consulte la bible, ou utilisez « Tester ».",
        };

        return $this->fabriquerService('voyage', 'Voyage AI (embeddings)', 'externe', $etat, $detail, null, $sante, $credit, 'https://dashboard.voyageai.com/');
    }

    // -------------------------------------------------------------------
    // Services internes
    // -------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function serviceQdrant(): array
    {
        $depart = microtime(true);
        try {
            $info = app(BibleQdrant::class)->infoSante();
            $latence = (int) round((microtime(true) - $depart) * 1000);
            SanteServices::signalerSucces('qdrant');

            $etat = $info['existe'] ? 'ok' : 'degrade';
            $detail = $info['existe']
                ? "Collection « bible » joignable — {$info['points']} point(s), statut {$info['statut']}."
                : 'Qdrant joignable, mais la collection « bible » n\'existe pas encore (créée au premier usage du RAG).';
        } catch (Throwable $e) {
            $latence = (int) round((microtime(true) - $depart) * 1000);
            SanteServices::signalerEchec('qdrant', 0, $e->getMessage());
            $etat = 'panne';
            $detail = "Injoignable : {$e->getMessage()}";
        }

        $sante = SanteServices::etat('qdrant');
        $creditInterne = ['etat' => 'inconnu', 'source' => 'aucune', 'explication' => 'Service auto-hébergé, sans notion de crédit.'];

        return $this->fabriquerService('qdrant', 'Qdrant (bible RAG)', 'interne', $etat, $detail, $latence, $sante, $creditInterne, null);
    }

    /** @return array<string, mixed> */
    private function serviceMariaDb(): array
    {
        $depart = microtime(true);
        try {
            DB::select('select 1');
            $latence = (int) round((microtime(true) - $depart) * 1000);
            $etat = 'ok';
            $detail = 'Connexion et requête de test en ordre.';
        } catch (Throwable $e) {
            $latence = (int) round((microtime(true) - $depart) * 1000);
            $etat = 'panne';
            $detail = "Injoignable : {$e->getMessage()}";
        }

        return $this->serviceInterneSimple('mariadb', 'MariaDB', $etat, $detail, $latence);
    }

    /** @return array<string, mixed> */
    private function serviceReverb(): array
    {
        $host = (string) config('broadcasting.connections.reverb.options.host', env('REVERB_HOST', 'reverb'));
        $port = (int) config('broadcasting.connections.reverb.options.port', env('REVERB_PORT', 8080));

        $depart = microtime(true);
        $connexion = @fsockopen($host, $port, $errno, $errstr, self::TIMEOUT_SONDE);
        $latence = (int) round((microtime(true) - $depart) * 1000);

        if ($connexion) {
            fclose($connexion);
            $etat = 'ok';
            $detail = "Port {$host}:{$port} joignable (TCP).";
        } else {
            $etat = 'panne';
            $detail = "Injoignable sur {$host}:{$port}".($errstr !== '' ? " ({$errstr})" : '').'.';
        }

        return $this->serviceInterneSimple('reverb', 'Reverb (temps réel)', $etat, $detail, $latence);
    }

    /** @return array<string, mixed> */
    private function serviceQueue(string $nomDocker, string $chaineQueue): array
    {
        $battement = SanteFileAttente::etat($chaineQueue);
        $enAttente = null;
        $echecs24h = null;
        try {
            $enAttente = DB::table('jobs')->whereIn('queue', explode(',', $chaineQueue))->count();
            $echecs24h = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            // Table absente (install fraîche) : pas bloquant pour l'état du worker.
        }

        if ($battement === null) {
            $detail = "Aucun battement de ce worker depuis le dernier redémarrage du cache — vérifiez qu'il tourne (docker compose ps).";

            return $this->serviceInterneSimple("queue_{$nomDocker}", "Worker « {$nomDocker} »", 'inconnu', $detail, null);
        }

        // abs() : selon la version de Carbon, diffInMinutes() peut rendre un
        // écart SIGNÉ (négatif quand l'argument est dans le passé) — on veut
        // toujours une ANCIENNETÉ positive, jamais son sens.
        $vuDepuisMin = (int) abs(now()->diffInMinutes(Carbon::parse($battement['vu_a'])));
        $codePerime = $battement['version_code'] !== SanteFileAttente::versionCodeActuel();

        $etat = match (true) {
            $vuDepuisMin > 5 => 'panne',
            $codePerime => 'degrade',
            default => 'ok',
        };

        $detailBase = match (true) {
            $vuDepuisMin > 5 => "Aucun worker vu depuis {$vuDepuisMin} min — probablement arrêté.",
            $codePerime => 'Exécute un code plus ancien que le code actuel — redémarrer : docker compose restart queue queue-jeu.',
            default => "Actif (pid {$battement['pid']}), code à jour, vu il y a {$vuDepuisMin} min.",
        };

        $compteurs = [];
        if ($enAttente !== null) {
            $compteurs[] = "{$enAttente} job(s) en attente";
        }
        if ($echecs24h !== null) {
            $compteurs[] = "{$echecs24h} échec(s) sur 24 h (toutes files)";
        }
        $detail = $compteurs !== [] ? $detailBase.' — '.implode(', ', $compteurs).'.' : $detailBase;

        return $this->serviceInterneSimple("queue_{$nomDocker}", "Worker « {$nomDocker} »", $etat, $detail, null, $battement['vu_a']);
    }

    /** @return array<string, mixed> */
    private function serviceBackups(): array
    {
        $dossier = base_path('backups');

        if (! is_dir($dossier)) {
            return $this->serviceInterneSimple('backups', 'Sauvegardes', 'inconnu',
                "Dossier « backups/ » introuvable depuis ce conteneur ({$dossier}) — impossible de vérifier l'âge de la dernière sauvegarde depuis l'API ; utilisez image-tools/sauvegarder.sh --lister.", null);
        }

        $plusRecent = null;
        foreach (scandir($dossier) ?: [] as $nom) {
            if ($nom === '.' || $nom === '..') {
                continue;
            }
            $mtime = @filemtime($dossier.'/'.$nom);
            if ($mtime !== false && ($plusRecent === null || $mtime > $plusRecent['mtime'])) {
                $plusRecent = ['nom' => $nom, 'mtime' => $mtime];
            }
        }

        if ($plusRecent === null) {
            return $this->serviceInterneSimple('backups', 'Sauvegardes', 'panne',
                "« backups/ » existe mais est vide — aucune sauvegarde n'a jamais été prise (image-tools/sauvegarder.sh).", null);
        }

        $ageJours = (int) floor((time() - $plusRecent['mtime']) / 86400);
        $etat = $ageJours > 7 ? 'degrade' : 'ok';
        $detail = "Dernière sauvegarde « {$plusRecent['nom']} », il y a {$ageJours} jour(s) (chemin vu depuis ce conteneur : {$dossier}).";

        return $this->serviceInterneSimple('backups', 'Sauvegardes', $etat, $detail, null, Carbon::createFromTimestamp($plusRecent['mtime'])->toIso8601String());
    }

    // -------------------------------------------------------------------
    // Aides communes
    // -------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function nonConfigure(string $id, string $libelle, string $famille, string $detail, ?string $console): array
    {
        return [
            'id' => $id, 'libelle' => $libelle, 'famille' => $famille,
            'etat' => 'non_configure', 'detail' => $detail,
            'latence_ms' => null, 'derniere_reussite' => null, 'dernier_echec' => null,
            'credit' => ['etat' => 'inconnu', 'source' => 'aucune', 'explication' => 'Non configuré.'],
            'console_url' => $console,
        ];
    }

    /** @return array<string, mixed> */
    private function serviceInterneSimple(string $id, string $libelle, string $etat, string $detail, ?int $latenceMs, ?string $derniereReussite = null): array
    {
        return [
            'id' => $id, 'libelle' => $libelle, 'famille' => 'interne',
            'etat' => $etat, 'detail' => $detail, 'latence_ms' => $latenceMs,
            'derniere_reussite' => $derniereReussite, 'dernier_echec' => null,
            'credit' => ['etat' => 'inconnu', 'source' => 'aucune', 'explication' => 'Service interne, sans notion de crédit.'],
            'console_url' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $sante
     * @param  array<string, mixed>  $credit
     * @return array<string, mixed>
     */
    private function fabriquerService(string $id, string $libelle, string $famille, string $etat, string $detail, ?int $latenceMs, array $sante, array $credit, ?string $console): array
    {
        return [
            'id' => $id, 'libelle' => $libelle, 'famille' => $famille,
            'etat' => $etat, 'detail' => $detail, 'latence_ms' => $latenceMs,
            'derniere_reussite' => $sante['derniere_reussite'] ?? null,
            'dernier_echec' => $this->echecPublic($sante),
            'credit' => $credit,
            'console_url' => $console,
        ];
    }

    /** @param  array<string, mixed>  $sante @return array<string, mixed>|null */
    private function echecPublic(array $sante): ?array
    {
        $e = $sante['dernier_echec'] ?? null;
        if ($e === null) {
            return null;
        }

        return [
            'a' => $e['a'],
            'statut_http' => $e['statut_http'],
            'categorie' => $e['categorie'],
            'message' => $e['message'],
        ];
    }

    /** @param  array<string, mixed>  $sante */
    private function dernierEvenementEstEchec(array $sante): bool
    {
        $succesA = $sante['derniere_reussite'] ?? null;
        $echecA = $sante['dernier_echec']['a'] ?? null;

        return $echecA !== null && ($succesA === null || $echecA > $succesA);
    }

    /**
     * Verdict de CRÉDIT inféré depuis l'historique `SanteServices` du
     * service — jamais une lecture de solde (voir doc de classe).
     *
     * @param  array<string, mixed>  $sante
     * @return array{etat: string, source: string, explication: string}
     */
    private function verdictCredit(array $sante): array
    {
        $succesA = $sante['derniere_reussite'] ?? null;
        $echec = $sante['dernier_echec'] ?? null;

        if ($echec !== null && ($succesA === null || $echec['a'] > $succesA)) {
            $source = ($echec['origine'] ?? 'jeu') === 'test' ? 'test' : 'dernier_appel';

            return match ($echec['categorie']) {
                'credit_epuise' => ['etat' => 'epuise', 'source' => $source, 'explication' => "Le dernier appel réel a échoué avec un signal de crédit épuisé : « {$echec['message']} ». Vérifiez le solde sur la console du fournisseur."],
                'quota_atteint' => ['etat' => 'quota_atteint', 'source' => $source, 'explication' => "Le dernier appel réel a échoué sur un quota (probablement journalier) atteint : « {$echec['message']} »."],
                default => ['etat' => 'inconnu', 'source' => $source, 'explication' => "Le dernier appel réel a échoué pour une raison sans rapport avec le crédit ({$echec['categorie']}) : « {$echec['message']} »."],
            };
        }

        if ($succesA !== null) {
            $source = ($sante['derniere_reussite_origine'] ?? 'jeu') === 'test' ? 'test' : 'dernier_appel';

            return ['etat' => 'ok', 'source' => $source, 'explication' => "Dernier appel réel réussi — aucun signal d'épuisement. Aucun fournisseur n'expose de solde réel : vérifiez la console pour le crédit exact."];
        }

        return ['etat' => 'inconnu', 'source' => 'aucune', 'explication' => "Aucun appel réel enregistré depuis le dernier redémarrage du cache — jouez une action IA, ou utilisez « Tester » pour obtenir un premier signal."];
    }

    /**
     * Le crédit prime sur la sonde de connectivité gratuite : un service
     * joignable (clé valide) mais dont le dernier appel RÉEL (qui, lui,
     * consomme du crédit) a été refusé pour épuisement reste affiché en
     * panne/dégradé — la sonde ne pouvant pas le détecter elle-même.
     *
     * @param  array{etat: string, source: string, explication: string}  $credit
     * @return array{0: string, 1: string}
     */
    private function ajusterSelonCredit(string $etat, string $detail, array $credit): array
    {
        if ($credit['etat'] === 'epuise') {
            return ['panne', $detail.' Crédit probablement épuisé (voir le dernier appel réel ci-dessous).'];
        }
        if ($credit['etat'] === 'quota_atteint' && $etat === 'ok') {
            return ['degrade', $detail.' Quota atteint sur le dernier appel réel.'];
        }

        return [$etat, $detail];
    }

    /**
     * Bandeau d'avertissements (front, bas de la page) — stale workers, vieille
     * sauvegarde, crédit épuisé : les trois cas que René a nommément demandé
     * à voir remonter, même quand le service concerné n'est qu'en `degrade`
     * (un worker à redémarrer ou une sauvegarde vieille de 10 jours ne sont
     * pas des PANNES, mais méritent le bandeau).
     *
     * @param  list<array<string, mixed>>  $services
     * @return list<string>
     */
    private function avertissements(array $services): array
    {
        $alertes = [];

        foreach ($services as $s) {
            $estWorkerOuSauvegarde = str_starts_with($s['id'], 'queue_') || $s['id'] === 'backups';

            if ($s['etat'] === 'panne') {
                $alertes[] = "{$s['libelle']} : {$s['detail']}";
            } elseif ($s['credit']['etat'] === 'epuise') {
                $alertes[] = "{$s['libelle']} : crédit probablement épuisé.";
            } elseif ($s['etat'] === 'degrade' && $estWorkerOuSauvegarde) {
                $alertes[] = "{$s['libelle']} : {$s['detail']}";
            }
        }

        return $alertes;
    }
}
