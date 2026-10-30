<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\MotsClesEquipement;
use App\Engine\MotsClesSortDread;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\SortDread;
use Illuminate\Support\Collection;

/**
 * LES SORTS DE DREAD DU PORTEUR — ce qu'un héros porteur d'un objet maudit peut LANCER sur les monstres (Talisman du
 * Savoir maudit, Prophecy of Telor p. 10 : « the bearer … knows two Dread spells … Each spell may only be cast once per
 * quest »).
 *
 * Une seule décision, lue par le menu (`MenuMoteur`, option `lancer_sort_dread`) ET par le résolveur
 * (`ResolveurTour::resoudreSortDreadPorteur()`) :
 *  - `entrees()` : les sorts encore jouables — un sort épuisé pour la quête n'est plus offert du tout, et un sort sans
 *    cible légale non plus. Le menu ne propose rien que le résolveur refuserait ;
 *  - `cibles()` : ce qu'un sort peut viser en ce moment ;
 *  - `directionVers()` : la direction du rayon qui atteint un monstre — la seule question géométrique du rayon.
 *
 * L'EFFET n'est jamais recopié ici : il se lit au catalogue (`SortDread::effet`) par le résolveur. Rayon
 * (`zone: rayon`) : les figures alignées sur une des huit directions, jusqu'au mur ou à la porte close, comme le rayon
 * du héros (`Rayon::cases()`). Salle (`zone: salle`, `hors_couloir`) : les figures de la salle du porteur, jamais en
 * couloir. Les figures immunisées aux sorts (`Monstre::immuniseAuxSorts()`) ne sont jamais des cibles, comme pour la
 * zone du MJ (`MoteurDread::monstresDuSort()`).
 */
final class SortsDreadPorteur
{
    /** Préfixe du compteur `capacites_utilisees` : « ce sort est épuisé pour cette quête » (colonne, durable). */
    public const COMPTEUR = 'sort_dread_porteur:';

    /**
     * Les entrées jouables pour ce porteur, dans l'ordre où son objet les déclare.
     *
     * @return list<array{cle: string, nom: string, zone: string, cibles: list<array{instance_id: int, nom: string}>}>
     */
    public function entrees(Quete $quete, EtatPersonnageQuete $etat, Personnage $porteur): array
    {
        $noms = [];

        foreach ($porteur->inventaire()->with('objet')->whereIn('emplacement', Equipement::SLOTS)->get() as $ligne) {
            foreach ((array) ($ligne->objet?->effet[MotsClesEquipement::SORTS_DREAD_PORTEUR] ?? []) as $nom) {
                $noms[(string) $nom] = true;
            }
        }

        $utilises = (array) ($etat->capacites_utilisees ?? []);
        $entrees = [];

        foreach (array_keys($noms) as $nom) {
            // Un nom absent du catalogue est une faute de données : il lève ici, il ne disparaît pas en silence.
            $sort = SortDread::where('nom', $nom)->firstOrFail();

            if (in_array(self::COMPTEUR.$nom, $utilises, true)) {
                continue; // une fois par quête : épuisé, donc plus offert du tout
            }

            $cibles = $this->cibles($quete, $etat, $sort);

            if ($cibles === null) {
                continue; // aucune cible légale en ce moment : pas d'entrée
            }

            $entrees[] = [
                'cle' => 'sort_dread:'.$nom,
                'nom' => $nom,
                'zone' => (string) data_get($sort->effet, 'zone'),
                'cibles' => $cibles,
            ];
        }

        return $entrees;
    }

    /**
     * Ce que ce sort peut viser en ce moment, ou `null` s'il n'a aucune cible jouable.
     *
     * @return list<array{instance_id: int, nom: string}>|null  `[]` pour un sort de SALLE (rien à désigner : la salle
     *                                                          entière), la liste des figures pour un RAYON
     */
    public function cibles(Quete $quete, EtatPersonnageQuete $etat, SortDread $sort): ?array
    {
        if ($etat->position_x === null || $etat->position_y === null) {
            return null;
        }

        $zone = (string) data_get($sort->effet, 'zone');
        $figures = $this->figuresAtteignables($quete);

        if ($zone === MotsClesSortDread::ZONE_RAYON) {
            $cibles = $figures
                ->filter(fn (InstanceMonstre $m) => $this->directionVers($quete, $etat, $m) !== null)
                ->map(fn (InstanceMonstre $m) => ['instance_id' => (int) $m->id, 'nom' => $m->nomAffiche()])
                ->values()
                ->all();

            return $cibles === [] ? null : $cibles;
        }

        if ($zone === MotsClesSortDread::ZONE_SALLE) {
            $salles = (array) ($quete->carte?->grille['salles'] ?? []);
            $salle = Salles::indexDe($salles, (int) $etat->position_x, (int) $etat->position_y);

            // Une salle ne se lance que d'une salle : un porteur en couloir n'a pas de « salle » à balayer.
            if ($salle === null) {
                return null;
            }

            $dansLaSalle = $figures->contains(
                fn (InstanceMonstre $m) => Salles::indexDe($salles, (int) $m->position_x, (int) $m->position_y) === $salle,
            );

            return $dansLaSalle ? [] : null;
        }

        return null;
    }

    /**
     * La direction du rayon qui atteint ce monstre, ou `null` si le monstre n'est pas aligné sur l'une des huit
     * directions, ou si un mur ou une porte close le cache avant lui.
     */
    public function directionVers(Quete $quete, EtatPersonnageQuete $etat, InstanceMonstre $monstre): ?string
    {
        $x = (int) $etat->position_x;
        $y = (int) $etat->position_y;
        $dx = (int) $monstre->position_x - $x;
        $dy = (int) $monstre->position_y - $y;

        if (($dx === 0 && $dy === 0) || ($dx !== 0 && $dy !== 0 && abs($dx) !== abs($dy))) {
            return null;
        }

        $pasX = $dx <=> 0;
        $pasY = $dy <=> 0;

        foreach (Rayon::DIRECTIONS as $cle => [$directionX, $directionY]) {
            if ($directionX !== $pasX || $directionY !== $pasY) {
                continue;
            }

            foreach (Rayon::cases(FabriqueGrille::pour($quete), $x, $y, (string) $cle) as $case) {
                if ($case['x'] === (int) $monstre->position_x && $case['y'] === (int) $monstre->position_y) {
                    return (string) $cle;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Les figures que les sorts de Dread peuvent ATTEINDRE : actives, révélées (une figure derrière une porte jamais
     * ouverte n'est pas une cible), placées, et pas immunisées aux sorts.
     *
     * @return Collection<int, InstanceMonstre>
     */
    public function figuresAtteignables(Quete $quete): Collection
    {
        return $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)
            ->with('monstre')->get()
            ->filter(fn (InstanceMonstre $m) => $m->position_x !== null && ! $m->monstre->immuniseAuxSorts())
            ->values();
    }
}
