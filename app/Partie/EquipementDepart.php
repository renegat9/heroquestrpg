<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\ClasseHeros;
use App\Models\Objet;

/**
 * Équipement de départ (parité HeroQuest), et les valeurs de combat qu'il
 * produit. Les 3/2/2/1 dés d'attaque du doc 01 §4 ne sont PAS une force innée :
 * ce sont les armes de départ du plateau. C'est l'arme qui fixe l'attaque —
 * à mains nues, tout héros lance 1 dé (le Moine 2).
 *
 * Une seule table, lue par DEUX consommateurs : la création du héros
 * (`GroupeController::equiperDepart()`) et le guide / livret (`valeurs()`).
 * Elle vivait en constante privée du contrôleur ; le guide affichait donc la
 * base NUE — un barbare à 1 dé d'attaque, un chevalier à 2 de défense —
 * alors que le héros créé en lance 3 et 3 (René, 2026-09-24).
 */
final class EquipementDepart
{
    public const PAR_CLASSE = [
        'barbare' => ['Épée large'],
        // HACHETTE, comme dans la boîte de départ FIRST LIGHT (2024) — choix de
        // René, 2026-09-25. Le livret de 2021 lui donnait une Épée courte,
        // « the shortsword is the starting weapon of the dwarf AND the elf »
        // (LR p. 13) : c'est une DIVERGENCE assumée envers ce livret-là, pas un
        // oubli, et elle ne touche que les héros créés à partir de ce jour —
        // ceux qui existent gardent leur épée (l'équipement de départ n'est
        // donné qu'à la création, aucune migration). La Hachette est sourcée
        // par sa carte d'armurerie (Handaxe, reference/16_armurerie.md
        // §2.1bis). Même attaque (2 dés) ; la différence est qu'elle est
        // `jetable` : lancée, elle est perdue (docs/regles/combat-et-tour.md).
        // ⚠ Plus de Trousse à outils depuis le 2026-08-22 : son dos de carte dit
        // qu'il « désamorce les pièges SANS OUTILS ». La trousse ne lui servait
        // plus à rien et lui mangeait une place de sac.
        'nain' => ['Hachette'],
        'elfe' => ['Épée courte'],
        'magicien' => ['Dague'],
        // Les 8 classes d'extension : arme LUE SUR LA CARTE (« Starting
        // Weapon »), reference/01_personnages.md §4bis.
        'barde' => ['Dague'],
        'druide' => ['Dague'],
        'warlock' => ['Baguette'],
        // Dos de carte : le Rogue « commence avec la bandoulière » (René,
        // 2026-08-22). Elle porte `compte_comme_arme: Dague`, ce qui rend son
        // Ambidextrie littérale dès le premier tour — sa seconde attaque exige
        // une DAGUE, et c'est la bandoulière qui la lui donne sans occuper sa
        // main gauche.
        'rogue' => ['Dague', 'Bandoulière'],
        // Le Moine n'a PAS d'arme de départ, et ce n'est pas un oubli : sa
        // carte n'en donne aucune, ses mains nues SONT son arme (2 dés, contre
        // 1 pour tout le monde).
        'moine' => [],
        // Seul héros du jeu à démarrer avec une ARMURE : sa carte porte
        // « Starting Armor …… Shield », et deux de ses trois capacités exigent
        // « Requires shield ».
        'chevalier' => ['Épée courte', 'Bouclier'],
        'berserker' => ['Épée large'],
        'explorateur' => ['Hachette'],
    ];

    /**
     * Ce que la classe porte en entrant dans sa première quête, et ce que ça
     * lui donne — calculé par les règles nues d'`Equipement` (`attaqueDeLArme`,
     * `defenseDeLaPiece`), celles-là mêmes que `recalculerCombat()` applique au
     * héros créé. Un test compare les deux sur les douze classes.
     *
     * Aucun nœud de talent n'entre ici : un héros neuf n'en a acheté aucun, et
     * aucune capacité INNÉE n'est aujourd'hui un bonus de dés permanent (celles
     * qui en donnent sont conditionnelles, lues en situation).
     *
     * @return array{arme: ?string, pieces: list<string>, des_attaque: int, des_defense: int}
     */
    public static function valeurs(ClasseHeros $classe): array
    {
        $objets = Objet::query()
            ->whereIn('nom', self::PAR_CLASSE[$classe->nom] ?? [])
            ->get()
            ->keyBy('nom');

        // Dans l'ordre de la table : c'est l'ordre d'équipement, donc la
        // première arme est celle de la main droite.
        $portes = collect(self::PAR_CLASSE[$classe->nom] ?? [])
            ->map(fn (string $nom) => $objets->get($nom))
            ->filter(fn (?Objet $o) => $o !== null && in_array($o->emplacement, Equipement::SLOTS, true))
            ->values();

        $arme = $portes->first(fn (Objet $o) => $o->emplacement === 'arme_principale');

        return [
            'arme' => $arme?->nom,
            // Les pièces portées qui ajoutent de la défense (le Bouclier du
            // Chevalier) : c'est ce que le guide nomme à côté du chiffre.
            'pieces' => $portes
                ->filter(fn (Objet $o) => Equipement::defenseDeLaPiece((array) $o->effet) !== 0)
                ->pluck('nom')->values()->all(),
            'des_attaque' => max(0, Equipement::attaqueDeLArme((int) $classe->des_attaque, (array) ($arme?->effet ?? []))),
            'des_defense' => max(0, (int) $classe->des_defense
                + $portes->sum(fn (Objet $o) => Equipement::defenseDeLaPiece((array) $o->effet))),
        ];
    }
}
