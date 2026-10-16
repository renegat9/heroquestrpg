<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * Les phrases du fil de combat pour la MALÉDICTION DU LOUP-GAROU (*The Mage of the Mirror*, F7539 p. 10-11) :
 * le jet de deux dés rouges au début du tour, le loup qui marche et qui mord pendant le tour de Zargon, le retour
 * sous forme humaine, et le dé rouge du lancer du sort.
 *
 * Un TRAIT de {@see JournalCombat}, comme {@see LignesSorciersDuDread} : `info()`, `desDuJet()` et le registre
 * {@see JournalCombat::TYPES} sont partagés, sans second formateur. Chaque effet est AUTOMATIQUE — le jet, la
 * transformation, la morsure — et « un effet automatique que rien n'annonce est injouable » : la phrase est écrite
 * ici, d'après ce que le moteur a DÉCIDÉ, jamais redéduite des dés.
 */
trait LignesMaledictionLoup
{
    /**
     * Le jet de début de tour d'un héros maudit : deux dés rouges, leur somme, et la forme qui en sort.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string, des?: array<string, mixed>}>
     */
    private function maledictionJet(array $a): array
    {
        $nom = (string) ($a['nom'] ?? 'Le héros');
        $faces = array_values((array) ($a['faces'] ?? []));
        $total = (int) ($a['total'] ?? array_sum($faces));
        $jet = implode(' + ', $faces).' = '.$total;

        if (! empty($a['transforme'])) {
            return [[
                'texte' => "{$nom}, maudit, lance {$jet} sur ses deux dés rouges : il se transforme en loup — Zargon le jouera à son tour",
                'ton' => 'chute',
            ]];
        }

        return [[
            'texte' => "{$nom}, maudit, lance {$jet} sur ses deux dés rouges : il reste sous forme humaine",
            'ton' => 'info',
        ]];
    }

    /**
     * Le loup qui AVANCE pendant le tour de Zargon (`HerosJoueParLeMj`, étape `deplacement`).
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function loupDeplacement(array $a): array
    {
        $loup = (string) ($a['personnage'] ?? 'Le loup');
        $cible = $a['vers_heros'] ?? null;
        $depart = (array) ($a['de'] ?? []);
        $arrivee = (array) ($a['vers'] ?? []);

        if ($depart === [] || $depart === $arrivee) {
            return [$this->info("{$loup}, en loup, reste sur place")];
        }

        return [$this->info("{$loup}, en loup, bondit".($cible !== null ? " vers {$cible}" : ' sur sa proie'))];
    }

    /**
     * La morsure du loup sur un héros à son contact (`HerosJoueParLeMj`, étape `attaque`, 6 dés d'attaque du Loup géant).
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string, des?: array<string, mixed>}>
     */
    private function loupAttaque(array $a): array
    {
        $loup = (string) ($a['personnage'] ?? 'Le loup');
        $cible = (string) ($a['cible']['nom'] ?? 'un héros');
        $degats = (int) ($a['degats'] ?? 0);

        $ligne = match (true) {
            ! empty($a['cible_tombee']) => ['texte' => "{$loup}, en loup, mord {$cible} — {$degats} PV, et {$cible} tombe !", 'ton' => 'chute'],
            $degats > 0 => ['texte' => "{$loup}, en loup, mord {$cible} (−{$degats} PV)", 'ton' => 'subit'],
            default => ['texte' => "{$cible} esquive la morsure de {$loup}", 'ton' => 'pare'],
        };

        if (($des = $this->desDuJet($a, $loup)) !== null) {
            $ligne['des'] = $des;
        }

        return [$ligne];
    }

    /**
     * Le retour sous forme humaine, à la fin du tour de Zargon : le héros reprend son corps et son contrôle.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function loupReprendForme(array $a): array
    {
        $nom = (string) ($a['nom'] ?? 'Le héros');

        return [[
            'texte' => "{$nom} reprend forme humaine : il retrouve son corps, et son joueur reprend la main",
            'ton' => 'succes',
        ]];
    }

    /**
     * Le DÉ ROUGE du lancer de la malédiction (`MoteurDread::sortDreadControle()`, `de_malediction`) : le 6 seul
     * annule le sort. Le dé se dessine ici — `desJetUnilateral()` lirait « 5-6 » pour un dé de dégâts, faux pour lui.
     *
     * @param  array<string, mixed>  $r  l'entrée de `resultats[]` du sort
     * @return array{texte: string, ton: string, des: array<string, mixed>}
     */
    private function ligneDeMalediction(array $r, string $nomSort, string $cible): array
    {
        $face = (int) $r['de_malediction'];
        $sansEffet = empty($r['effet_applique']);

        return [
            'texte' => $sansEffet
                ? "{$cible} résiste à {$nomSort} : le dé rouge fait {$face}, le sort est sans effet"
                : "{$cible} n'a pas résisté à {$nomSort} : le dé rouge fait {$face} — il est maudit",
            'ton' => $sansEffet ? 'pare' : 'subit',
            'des' => $this->avecModificateurs([
                'atk' => [],
                'def' => [$face],
                'touchante' => null,
                'defensive' => [6],
                'attaquant' => null,
                'defenseur' => $cible,
                'touches' => null,
                'boucliers' => null,
                'libelle_def' => $sansEffet ? 'résiste' : 'cède',
            ], $r),
        ];
    }
}
