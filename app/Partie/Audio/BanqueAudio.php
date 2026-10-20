<?php

declare(strict_types=1);

namespace App\Partie\Audio;

use App\Agent\Exceptions\AppelLlmException;
use App\Partie\Narration\BibliothequeNarration;

/**
 * Banques d'audio PRÉ-GÉNÉRÉ rangées par position (`{dossier}/{index}.wav`) :
 * répliques du narrateur (`public/audio/narration/{cle}/`) et barks d'archétype
 * (`public/audio/barks/{profil}/{evenement}/`).
 *
 * ⚠ Un fichier n'était relié à sa réplique QUE par son rang dans la liste — jamais
 * par son texte. Modifier, ajouter ou réordonner une variante de `config/` laissait
 * l'ancien fichier en place : la commande le sautait (« déjà présent ») et la table
 * jouait l'ANCIENNE phrase sur le NOUVEAU texte, sans aucune erreur. C'est ce qui
 * imposait un `rm -r` à la main après chaque retouche (2026-10-19 : les répliques
 * `reussite_mixte` disaient « réussi » pour un échec).
 *
 * Désormais chaque `{index}.wav` porte à côté de lui `{index}.sha1`, l'empreinte du
 * texte qu'il prononce (2026-10-20) :
 *  - au RUNTIME, {@see self::url()} ne sert un fichier que si son empreinte est celle
 *    du texte affiché ; sinon `null`, et la table lit le texte en Web Speech ;
 *  - à la GÉNÉRATION, {@see self::synchroniser()} régénère ce dont le texte a changé
 *    et SUPPRIME ce qui ne correspond plus à aucune réplique (rang disparu, réplique
 *    à variables — voir {@see BibliothequeNarration::aDesVariables()}).
 *
 * ⚠ Un fichier SANS empreinte (généré avant le 2026-10-20) n'est jamais servi : rien
 * ne dit ce qu'il prononce. `synchroniser()` l'ADOPTE au premier passage (empreinte
 * du texte actuel, zéro requête TTS) — c'est le seul pari de ce dispositif, et il
 * est fait UNE fois, sur les fichiers qui existaient avant lui.
 *
 * Les boss nommés (manifeste de quête, `GenererBarksBoss`) et la narration dynamique
 * (`narration/dyn/{sha1}.wav`) sont déjà adressés par leur texte : hors périmètre.
 */
final class BanqueAudio
{
    public static function empreinte(string $texte): string
    {
        return sha1($texte);
    }

    /**
     * URL publique de `{racine}/{relatif}` — `relatif` = `{dossier}/{index}.wav` —
     * si ce fichier prononce bien `$texte`, sinon null.
     */
    public static function url(string $racine, string $relatif, string $texte): ?string
    {
        $wav = public_path("audio/{$racine}/{$relatif}");

        return self::prononce($wav, $texte) ? "/audio/{$racine}/{$relatif}" : null;
    }

    /**
     * Met `public/audio/{racine}` en accord avec les répliques déclarées.
     *
     * @param  array<string, list<string>>  $groupes  dossier relatif → répliques, dans l'ordre
     * @param  callable(string $dossier, string $texte): string  $synthetiser  renvoie le WAV
     * @param  (callable(string $ligne): void)|null  $journal
     * @return array{faits: int, a_jour: int, adoptes: int, a_variables: int, supprimes: int, echecs: int}
     */
    public static function synchroniser(
        string $racine,
        array $groupes,
        callable $synthetiser,
        bool $force = false,
        ?callable $journal = null,
    ): array {
        $bilan = ['faits' => 0, 'a_jour' => 0, 'adoptes' => 0, 'a_variables' => 0, 'supprimes' => 0, 'echecs' => 0];
        $journal ??= static fn (string $ligne) => null;

        foreach ($groupes as $dossier => $variantes) {
            $chemin = public_path("audio/{$racine}/{$dossier}");
            $variantes = array_values($variantes);

            foreach ($variantes as $index => $texte) {
                $texte = (string) $texte;
                $wav = "{$chemin}/{$index}.wav";

                // Une réplique à variables n'existe qu'une fois substituée : sa voix
                // lirait les accolades. Jamais synthétisée — et l'ancien fichier part.
                if (BibliothequeNarration::aDesVariables($texte)) {
                    $bilan['a_variables']++;
                    $bilan['supprimes'] += self::supprimer($wav);

                    continue;
                }

                if (! $force && is_file($wav)) {
                    if (self::prononce($wav, $texte)) {
                        $bilan['a_jour']++;

                        continue;
                    }

                    if (! is_file(self::cheminEmpreinte($wav))) {
                        file_put_contents(self::cheminEmpreinte($wav), self::empreinte($texte));
                        $bilan['adoptes']++;

                        continue;
                    }
                    // Empreinte présente mais différente : le texte a changé → on régénère.
                }

                try {
                    $audio = $synthetiser($dossier, $texte);
                } catch (AppelLlmException $e) {
                    $journal("✗ {$dossier}/{$index} : ".$e->getMessage());
                    $bilan['echecs']++;

                    continue;
                }

                if (! is_dir($chemin)) {
                    mkdir($chemin, 0775, true);
                }

                file_put_contents($wav, $audio);
                file_put_contents(self::cheminEmpreinte($wav), self::empreinte($texte));
                $journal("✓ {$dossier}/{$index}  «".mb_strimwidth($texte, 0, 50, '…').'»');
                $bilan['faits']++;
            }

            // Rangs au-delà de la liste : des répliques retirées de `config/`.
            foreach (glob("{$chemin}/*.wav") ?: [] as $fichier) {
                $rang = basename($fichier, '.wav');

                if (ctype_digit($rang) && (int) $rang >= count($variantes)) {
                    $bilan['supprimes'] += self::supprimer($fichier);
                }
            }
        }

        return $bilan;
    }

    /** @param  array{faits: int, a_jour: int, adoptes: int, a_variables: int, supprimes: int, echecs: int}  $bilan */
    public static function resume(string $titre, array $bilan): string
    {
        return "{$titre} : {$bilan['faits']} · à jour : {$bilan['a_jour']} · adoptés : {$bilan['adoptes']}"
            ." · à variables (lues en Web Speech) : {$bilan['a_variables']}"
            ." · supprimés : {$bilan['supprimes']} · échecs : {$bilan['echecs']}";
    }

    private static function prononce(string $wav, string $texte): bool
    {
        $empreinte = self::cheminEmpreinte($wav);

        return is_file($wav) && is_file($empreinte)
            && trim((string) file_get_contents($empreinte)) === self::empreinte($texte);
    }

    private static function cheminEmpreinte(string $wav): string
    {
        return preg_replace('/\.wav$/', '.sha1', $wav) ?? $wav.'.sha1';
    }

    /** Retire un fichier et son empreinte ; 1 si un fichier audio existait. */
    private static function supprimer(string $wav): int
    {
        @unlink(self::cheminEmpreinte($wav));

        return is_file($wav) && unlink($wav) ? 1 : 0;
    }
}
