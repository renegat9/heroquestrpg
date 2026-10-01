<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;

/**
 * Le BESTIAIRE d'une campagne — automatique ou manuel (René, 2026-09-28 :
 * « une option automatique (comme actuellement) ou manuelle (sélection
 * possible d'une ou plusieurs extensions, et si aucune sélection, système de
 * base seulement) »).
 *
 * ⚠ Point de passage UNIQUE pour « quelles boîtes cette campagne joue-t-elle ».
 * Six lecteurs posaient la question chacun à sa façon (`boite === $theme`) :
 * l'achat des rencontres, le monstre errant, le terrain, l'équipement de
 * glace, l'artefact du coffre et le libellé de la table. Avec plusieurs boîtes
 * cochées, `=== $theme` n'a plus de sens — et une copie oubliée aurait laissé
 * entrer par la fenêtre une extension que le joueur n'a pas cochée.
 *
 *  - **Automatique** : UNE boîte, tirée par rotation et figée à la première
 *    quête (`groupes.theme_bestiaire`). Une PRÉFÉRENCE sur le bestiaire commun,
 *    jamais un filtre — le comportement historique, inchangé.
 *  - **Manuel** : les boîtes COCHÉES (`groupes.boites_bestiaire`, liste,
 *    vide comprise). Un FILTRE : jeu de base + nos créatures (`boite = null`,
 *    sans lesquelles le jeu de base n'a ni sous-boss ni boss) + boîtes cochées.
 *    Parmi elles, les cochées gardent la préférence (boss, forts).
 */
final class BestiaireGroupe
{
    public const MODE_AUTO = 'auto';

    public const MODE_MANUEL = 'manuel';

    /** Boîte du jeu de base (`monstres.boite`). */
    public const BOITE_BASE = 'base';

    /**
     * Nom OFFICIEL du jeu de base — « requires HeroQuest Game System to play »
     * (`reference/18_extensions.md` l. 33), jamais une traduction inventée :
     * même règle que `DemarreurQuete::LIBELLES_BOITES`.
     */
    public const LIBELLE_BASE = 'HeroQuest Game System';

    /** @param  list<string>  $boites */
    private function __construct(
        public readonly string $mode,
        public readonly array $boites,
    ) {}

    /** Automatique : `$theme` est la boîte tirée, `null` avant la première quête. */
    public static function auto(?string $theme): self
    {
        return new self(self::MODE_AUTO, $theme === null ? [] : [$theme]);
    }

    /** @param  list<string>  $boites  vide = HeroQuest Game System seul */
    public static function manuel(array $boites): self
    {
        return new self(self::MODE_MANUEL, array_values(array_unique($boites)));
    }

    public static function duGroupe(Groupe $groupe): self
    {
        if ($groupe->boites_bestiaire !== null) {
            return self::manuel((array) $groupe->boites_bestiaire);
        }

        return self::auto(app(DemarreurQuete::class)->themeBestiaireDuGroupe($groupe));
    }

    public function estManuel(): bool
    {
        return $this->mode === self::MODE_MANUEL;
    }

    /**
     * La créature (ou le terrain, l'objet) de cette boîte PEUT-elle apparaître ?
     * Toujours en automatique ; en manuel, jeu de base, `null` et boîtes cochées.
     */
    public function autorise(?string $boite): bool
    {
        if (! $this->estManuel()) {
            return true;
        }

        return $boite === null || $boite === self::BOITE_BASE || in_array($boite, $this->boites, true);
    }

    /**
     * Cette boîte est-elle un THÈME de la campagne — celle tirée en auto, une
     * cochée en manuel ? C'est la PRÉFÉRENCE (boss, forts) et ce qui allume le
     * matériel propre à une boîte (terrain, équipement de glace, artefact).
     */
    public function contient(?string $boite): bool
    {
        return $boite !== null && in_array($boite, $this->boites, true);
    }

    /** Libellé lisible, décidé ici — jamais par le client. */
    public function libelle(): string
    {
        $noms = array_map(
            fn (string $b) => app(DemarreurQuete::class)->libelleBoiteBestiaire($b),
            $this->boites,
        );

        return $this->estManuel()
            ? implode(' + ', [self::LIBELLE_BASE, ...$noms])
            : ($noms[0] ?? '');
    }
}
