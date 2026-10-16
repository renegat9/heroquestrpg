<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;

/**
 * La moitié « APPARITION À LA VUE » du patron TOURELLE (René, 2026-10-15) — le *Dread Skull* de
 * *The Crypt of Perpetual Darkness* (G1798, composants p. 5 et quête 9, note A p. 23) :
 *
 *   « As soon as a hero sees a Dread Skull, place it on the board. Immediately, and on Zargon's
 *   turn, the Dread Skull makes a ranged attack with the strength of 2 combat dice at a hero it
 *   can see with its green eye beams. The skull has 1 Body Point, 0 Defend Dice, and disappears
 *   once it sustains damage. »
 *
 * L'autre moitié — la figure immobile qui fait feu chaque tour du MJ — est `Monstre::estTourelle()`,
 * lue par `ResolveurTour::jouerMonstreEveille()` ; elle sert aussi le *Guardian Effigy*, qui n'est
 * pas caché. UN mécanisme, deux cartes : ce fichier n'ajoute que ce que le crâne a en plus.
 *
 * ⚠ DÈS QU'IL EST VU (René) : donc pendant la marche d'un héros — chaque case du chemin compte —
 * comme à l'ouverture d'une porte ou à n'importe quelle action qui change ce qu'un héros voit.
 * `ResolveurTour` interroge ce moteur aux deux endroits, et joue le tour de la figure SUR LE COUP
 * (`declencherApparition()`, même patron que l'embuscade du Dreadshifter).
 *
 * « Vu » = ligne de vue du héros jusqu'à la case du crâne, figures qui n'en coupent pas la vue
 * (on voit un monstre derrière un autre ; on ne lui tire pas dessus — le tir, lui, exige la ligne de
 * tir figures bloquantes, dans `tirerSiCibleEnVue()`). Murs, mobilier opaque et portes closes
 * la coupent : `Grille::ligneDeVue()`.
 *
 * ⚠ État DURABLE : `instances_monstres.revele` (colonne) — jamais le cache. Le crâne attend
 * `revele = false` ; `ResolveurTour::revelerSalle()` ne le révèle PAS à l'ouverture de la porte (il
 * n'est pas un dormant : il paraît au regard, et tire aussitôt).
 *
 * ⚠ AUCUNE LIGNE DE CATALOGUE ne porte encore `apparait_a_la_vue` : le Dread Skull appartient à une
 * boîte qui n'est pas un thème (`MotsClesAdversaires::SANS_DONNEE`). Le mécanisme est testé EN JEU
 * (`AdversairesNommesTest`) avec une ligne de catalogue de test.
 */
final class MoteurTourelle
{
    /** La figure attend-elle cachée, pour paraître au premier regard d'un héros ? */
    public static function apparaitALaVue(InstanceMonstre $instance): bool
    {
        return $instance->aCapacite('apparait_a_la_vue');
    }

    /**
     * Les figures cachées que l'une des `$cases` VOIT — chacune avec le héros qui l'a vue en
     * premier (`null` si les cases ne portent pas d'identifiant).
     *
     * @param  list<array{x: int, y: int, personnage_id?: int}>  $cases  les cases d'où l'on regarde
     * @return list<array{instance: InstanceMonstre, personnage_id: int|null}>
     */
    public function apparitionsEnVue(Quete $quete, array $cases): array
    {
        if ($cases === []) {
            return [];
        }

        $caches = $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', false)
            ->with('monstre')
            ->orderBy('id')
            ->get()
            ->filter(fn (InstanceMonstre $i) => self::apparaitALaVue($i) && $i->position_x !== null)
            ->values();

        if ($caches->isEmpty()) {
            return [];
        }

        $grille = FabriqueGrille::pour($quete);
        $vues = [];

        foreach ($caches as $instance) {
            foreach ($cases as $case) {
                if ($grille->ligneDeVue(
                    (int) $case['x'], (int) $case['y'], (int) $instance->position_x, (int) $instance->position_y,
                )) {
                    $vues[] = [
                        'instance' => $instance,
                        'personnage_id' => isset($case['personnage_id']) ? (int) $case['personnage_id'] : null,
                    ];

                    break;
                }
            }
        }

        return $vues;
    }

    /**
     * « Place it on the board » : la figure paraît. Annoncé au journal ET rendu dans le payload,
     * que l'appelant complète de l'action que la figure joue aussitôt.
     *
     * @return array<string, mixed>
     */
    public function reveler(Groupe $groupe, InstanceMonstre $instance, Personnage $declencheur): array
    {
        $instance->update(['revele' => true]);

        $payload = [
            'type' => 'apparition_a_la_vue',
            'monstre' => $instance->nomAffiche(),
            'instance_id' => (int) $instance->id,
            'declencheur' => ['personnage_id' => (int) $declencheur->id, 'nom' => $declencheur->nom],
            'position' => ['x' => (int) $instance->position_x, 'y' => (int) $instance->position_y],
        ];

        Journal::ajouter($groupe, 'action', $payload, ['type' => 'monstre', 'id' => $instance->id, 'nom' => $instance->nomAffiche()]);

        return $payload;
    }
}
