<?php

declare(strict_types=1);

namespace App\Partie\Narration;

use App\Agent\Audio\TtsGemini;
use App\Models\Parametre;
use App\Models\Quete;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Répliques scriptées du narrateur (config/narration.php) : cérémonie de
 * lancement + repli par temps fort, avec variantes. Renvoie toujours le TEXTE
 * et, si l'asset a été pré-généré, l'URL audio de la vraie voix de narrateur
 * (sinon null → lecture navigateur côté table).
 *
 * Gère aussi le chemin de cache de la narration DYNAMIQUE de l'IA (synthèse au
 * vol par GenererNarration), indexée par hash du texte.
 */
final class BibliothequeNarration
{
    /**
     * Réplique de cérémonie de lancement (variante aléatoire).
     *
     * @return array{cle: string, texte: string, ambiance: string, url: ?string}
     */
    public function lancement(): array
    {
        $variantes = array_values((array) config('narration.lancement.variantes', []));
        $ambiance = (string) config('narration.lancement.ambiance', 'epique');
        $index = $variantes === [] ? 0 : array_rand($variantes);

        return [
            'cle' => 'lancement',
            'texte' => (string) ($variantes[$index] ?? ''),
            'ambiance' => $ambiance,
            'url' => $this->urlScript('lancement', $index),
        ];
    }

    /**
     * Réplique de repli pour un temps fort (variante aléatoire), ou null si la
     * clé est inconnue.
     *
     * @return array{cle: string, texte: string, ambiance: string, url: ?string}|null
     */
    public function repli(string $cle): ?array
    {
        $variantes = array_values((array) config("narration.repli.{$cle}.variantes", []));

        if ($variantes === []) {
            return null;
        }

        $index = array_rand($variantes);

        return [
            'cle' => $cle,
            'texte' => (string) $variantes[$index],
            'ambiance' => (string) config("narration.repli.{$cle}.ambiance", 'tension'),
            'url' => $this->urlScript($cle, $index),
        ];
    }

    /**
     * Réplique d'un temps fort en PRIVILÉGIANT les récits pré-générés de la
     * quête (`quetes.recits`), puis en retombant sur `config/narration.php`.
     *
     * C'est le point d'entrée unique du runtime depuis la bascule du
     * 2026-08-18 : plus aucune narration n'est produite par le LLM en cours de
     * partie, elle est PIOCHÉE dans le pack écrit au démarrage de la quête. La
     * quête pouvant démarrer avant que le job de pré-génération n'ait rendu, le
     * repli scripté reste indispensable — ce n'est pas une sécurité théorique,
     * c'est le cas nominal des premières secondes.
     *
     * @param  array<string, string|int>  $remplacements  placeholders `{heros}`,
     *                                                    `{monstre}`, `{objet}`, `{or}`
     * @return array{cle: string, texte: string, ambiance: string, url: ?string}|null
     */
    public function pourQuete(?Quete $quete, string $cle, array $remplacements = []): ?array
    {
        $variantes = $quete?->recitsTempsFort($cle) ?? [];

        if ($variantes === []) {
            $repli = $this->repli($cle);

            return $repli === null
                ? null
                : [...$repli, 'texte' => $this->substituer($repli['texte'], $remplacements)];
        }

        $texte = $this->substituer($variantes[array_rand($variantes)], $remplacements);

        return [
            'cle' => $cle,
            'texte' => $texte,
            'ambiance' => $quete?->ambianceTempsFort($cle)
                ?? (string) config("narration.repli.{$cle}.ambiance", 'tension'),
            // Un texte à placeholders n'a JAMAIS d'audio pré-généré (il n'est
            // connu qu'ici, une fois substitué) : le hash ne tombe pas et la
            // table lit en Web Speech. Seules les descriptions de salle, fixes,
            // portent la vraie voix du narrateur. Aucun cas particulier à
            // écrire : la recherche par hash fait déjà exactement ça.
            'url' => $this->urlDynamiqueSiCache($texte),
        ];
    }

    /**
     * Description pré-générée d'une SALLE. `null` si le pack n'en a pas — la
     * découverte de salle retombe alors sur le temps fort `salle_decouverte`.
     *
     * ⚠ LE TEXTE SUIT CE QUE LA VOIX PERMET (règle de René, 2026-08-18), et
     * les deux formes sont produites d'avance pour ça :
     *  - l'audio du narrateur EXISTE pour cette salle → on sert le texte
     *    FIGÉ, celui-là même qui a été enregistré. Y glisser le nom du héros
     *    le désynchroniserait de sa bande-son : on entendrait une phrase et
     *    on en lirait une autre ;
     *  - il n'existe PAS (pas de clé Gemini, TTS coupé, quota épuisé) → la
     *    table lira de toute façon en voix de navigateur, à partir du texte.
     *    Nommer l'arrivant ne coûte alors plus rien, et c'est ce que la
     *    phrase `entree` est là pour faire.
     *
     * La décision se prend donc sur un FAIT — ce fichier audio est-il là ? —
     * et non sur un réglage à tenir en cohérence. Rien à recâbler le jour où
     * le quota se vide en cours de campagne : les salles déjà enregistrées
     * gardent la vraie voix, les suivantes nomment le héros.
     *
     * @param  array<string, string|int>  $remplacements
     * @return array{cle: string, texte: string, ambiance: string, url: ?string}|null
     */
    public function salle(?Quete $quete, int $salle, array $remplacements = []): ?array
    {
        $recit = $quete?->recitSalle($salle);

        if ($recit === null) {
            return null;
        }

        $fixe = (string) $recit['texte'];
        $url = $this->urlDynamiqueSiCache($fixe);
        $entree = trim((string) ($recit['entree'] ?? ''));

        // ⚠ La phrase d'entrée n'est servie QUE si l'on sait vraiment qui
        // entre. Toutes les révélations n'ont pas de découvreur : une porte
        // ouverte par la mort de son gardien n'en a aucun. Sans cette garde,
        // `{heros}` ressortait tel quel à l'écran — vu en partie réelle le
        // 2026-08-19, deux salles sur trois décrites par « {heros} pénètre
        // dans la salle voûtée ». La substitution laisse volontairement
        // intact ce qu'elle ne sait pas remplacer ; c'est ici qu'il faut
        // décider de ne pas le dire du tout.
        $arrivant = trim((string) ($remplacements['heros'] ?? ''));

        return [
            'cle' => "salle_{$salle}",
            'texte' => $url !== null || $entree === '' || $arrivant === ''
                ? $fixe
                : $this->substituer($entree, $remplacements).' '.$fixe,
            'ambiance' => (string) ($recit['ambiance'] ?? 'mystere'),
            'url' => $url,
        ];
    }

    /**
     * Substitue les placeholders `{cle}` — même convention que les répliques de
     * monstres (`config/barks.php` et son `{nom}`). Un placeholder sans valeur
     * est LAISSÉ TEL QUEL plutôt que vidé : une phrase amputée passerait
     * inaperçue à la relecture, « {monstre} » saute aux yeux.
     *
     * @param  array<string, string|int>  $remplacements
     */
    private function substituer(string $texte, array $remplacements): string
    {
        if ($remplacements === []) {
            return $texte;
        }

        return str_replace(
            array_map(fn ($cle) => '{'.$cle.'}', array_keys($remplacements)),
            array_map('strval', array_values($remplacements)),
            $texte,
        );
    }

    /** URL publique de l'audio scripté (cle/index) s'il existe, sinon null. */
    public function urlScript(string $cle, int $index): ?string
    {
        $rel = "narration/{$cle}/{$index}.wav";

        return is_file(public_path("audio/{$rel}")) ? "/audio/{$rel}" : null;
    }

    /**
     * Chemin de cache de la narration dynamique de l'IA (par hash du texte).
     *
     * @return array{rel: string, absolu: string, url: string}
     */
    public function cheminDynamique(string $texte): array
    {
        $hash = sha1($texte);
        $rel = "narration/dyn/{$hash}.wav";

        return [
            'rel' => $rel,
            'absolu' => public_path("audio/{$rel}"),
            'url' => "/audio/{$rel}",
        ];
    }

    /** URL de la narration dynamique si déjà en cache, sinon null. */
    public function urlDynamiqueSiCache(string $texte): ?string
    {
        $c = $this->cheminDynamique($texte);

        return is_file($c['absolu']) ? $c['url'] : null;
    }

    /**
     * Vraie voix de narrateur pour un texte DYNAMIQUE (narration IA, prémisse) :
     * renvoie l'URL du cache si présent, sinon synthétise (Gemini, voix
     * narrateur), met en cache et renvoie l'URL. Best-effort : sans clé /
     * désactivé / sur échec → null (lecture navigateur côté table).
     */
    public function voixDynamique(string $texte, TtsGemini $tts): ?string
    {
        if (! $this->voixDynamiqueActive() || ! $tts->estConfigure()) {
            return null;
        }

        if (($cache = $this->urlDynamiqueSiCache($texte)) !== null) {
            return $cache;
        }

        $voix = $this->voixNarrateur();
        $style = (string) config('narration.voix.style', 'une voix de conteur, maître de jeu');

        try {
            $wav = $tts->synthetiser($texte, $voix, $style);
        } catch (Throwable $e) {
            Log::warning('Synthèse voix narrateur (dynamique) impossible — lecture navigateur.', [
                'erreur' => $e->getMessage(),
            ]);

            return null;
        }

        $cible = $this->cheminDynamique($texte);
        $dossier = dirname($cible['absolu']);

        if (! is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }

        file_put_contents($cible['absolu'], $wav);

        return $cible['url'];
    }

    /**
     * Bascule « synthèse vocale IA en cours de partie » du panneau Réglages
     * (Parametre::voix_dynamique_active — narration dynamique ET barks de
     * boss, voir aussi `GenererBarksBoss`) : protège le quota Gemini TTS
     * (100 req/j). Best-effort, repli actif (comportement d'aujourd'hui) si
     * la table est absente/indisponible.
     */
    private function voixDynamiqueActive(): bool
    {
        try {
            return Parametre::actuel()->voix_dynamique_active;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Voix Gemini EFFECTIVE du narrateur : surcharge du panneau Réglages
     * (Parametre::narration_voix) si présente, sinon config/narration.php
     * ('Iapetus'). Best-effort : table absente/base indisponible → repli sur
     * le comportement actuel (config uniquement).
     *
     * Utilisée par la synthèse dynamique (voixDynamique ci-dessus) ET par la
     * commande OFFLINE `narration:generer` (GenererNarrationAudio) —
     * contrairement aux illustrations, la génération offline DOIT suivre la
     * surcharge : c'est le seul moyen de propager un changement de voix aux
     * répliques scriptées déjà pré-générées.
     */
    public function voixNarrateur(): string
    {
        $defaut = (string) config('narration.voix.voix', 'Iapetus');

        try {
            return Parametre::actuel()->narration_voix ?: $defaut;
        } catch (Throwable) {
            return $defaut;
        }
    }
}
