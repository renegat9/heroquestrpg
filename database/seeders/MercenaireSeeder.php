<?php

namespace Database\Seeders;

use App\Models\Mercenaire;
use Illuminate\Database\Seeder;

/**
 * Alliés recrutables (doc 14 §3.5) — les CINQ officiels, sourcés sur carte
 * (© 2023 Hasbro, numérisation du 2026-08-11).
 *
 * Cinq mercenaires humains et TROIS compagnons animaux (Loup, Croc-sabre,
 * Raptor), tous sourcés sur carte. La règle « un seul animal par groupe »
 * s'applique aux trois derniers.
 */
class MercenaireSeeder extends Seeder
{
    public function run(): void
    {
        // Les CINQ alliés OFFICIELS (© 2023 Hasbro), numérisés par René le
        // 2026-08-11 — reference/01_personnages.md §4quater. Ils remplacent les
        // trois que nous avions inventés (Archer mercenaire, Hallebardier, Loup
        // fidèle) : le sourcé chasse l'inventé, comme partout ailleurs dans le
        // projet.
        //
        // ⚠ Leur déplacement est en CASES : la carte dit « Movement Squares »
        // là où celle d'un héros dit « 2 Red Dice ». C'est donc un mouvement
        // FIXE, sans dé — ce que `deplacement` exprime déjà.
        //
        // Tous à 2 PV de Body sauf l'ogre : ce sont des figures qui tombent
        // vite, et c'est le prix de leur puissance de feu. Le Striker monte à
        // 5 dés de défense, davantage que n'importe quel héros ou monstre.
        $mercenaires = [
            ['nom' => 'Éclaireur', 'type' => 'eclaireur',
                'deplacement' => 9, 'attaque' => 2, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 2, 'prix' => 50, 'animal' => false,
                'description' => 'Ce mercenaire possède la capacité du Nain à détecter et désamorcer les pièges.'],
            // « This mercenary uses a crossbow. When attacking adjacent
            // monsters, they use a broadsword. » Exactement notre couple
            // `portee: distance` + arme de contact distincte, déjà employé par
            // l'Archer elfe du bestiaire.
            ['nom' => 'Arbalétrier', 'type' => 'arbaletrier',
                'deplacement' => 6, 'attaque' => 3, 'portee' => 'distance', 'attaque_distance' => 3,
                'defense' => 3, 'pv_body' => 2, 'pv_mind' => 2, 'prix' => 75, 'animal' => false,
                'description' => 'Arbalète à distance ; au contact, il dégaine une épée large.'],
            ['nom' => 'Fauchard', 'type' => 'fauchard',
                'deplacement' => 6, 'attaque' => 3, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 2, 'prix' => 75, 'animal' => false,
                'capacites' => ['attaque_diagonale'],
                'description' => 'Sa hampe lui permet de frapper en diagonale.'],
            ['nom' => 'Estafier', 'type' => 'estafier',
                'deplacement' => 5, 'attaque' => 4, 'defense' => 5, 'pv_body' => 2, 'pv_mind' => 2, 'prix' => 100, 'animal' => false,
                'description' => 'Bretteur à deux haches : la meilleure défense du jeu, sur deux points de vie.'],
            ['nom' => 'Ogre mercenaire', 'type' => 'ogre',
                'deplacement' => 8, 'attaque' => 4, 'defense' => 4, 'pv_body' => 4, 'pv_mind' => 1, 'prix' => 150, 'animal' => false,
                'description' => 'Brute louée à prix d\'or : lent d\'esprit, mais quatre points de vie et quatre dés.'],

            // ---- Les TROIS compagnons animaux (fournis par René, 2026-08-12)
            //
            // ⚠ Ils corrigent une affirmation du commit précédent : j'avais
            // écrit qu'aucune carte officielle ne proposait d'animal, et c'était
            // faux. Ils sont nettement plus endurants que les mercenaires
            // humains — 5 PV de Body contre 2 — et compensent par un Mind de 1
            // qui les rend fragiles aux sorts mentaux.
            //
            // ⚠ `prix` est LA SEULE VALEUR DE NOUS : les cartes n'en portent
            // pas. Calées sur l'échelle officielle (Éclaireur 50 → Ogre 150)
            // d'après leur puissance ; à régler en partie.
            ['nom' => 'Loup', 'type' => 'compagnon',
                'deplacement' => 10, 'attaque' => 3, 'defense' => 2, 'pv_body' => 5, 'pv_mind' => 1, 'prix' => 125, 'animal' => true,
                'capacites' => ['attaque_diagonale'],
                'description' => 'Rapide et mordant, il frappe aussi en diagonale.'],
            ['nom' => 'Croc-sabre', 'type' => 'compagnon',
                'deplacement' => 10, 'attaque' => 2, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 1, 'prix' => 125, 'animal' => true,
                'capacites' => ['attaque_diagonale'],
                'description' => 'Fauve massif : mieux protégé que le loup, il frappe aussi en diagonale.'],
            // « Move before and after an attack » : exactement le second
            // mouvement du `tacticien`, déjà écrit pour les monstres de Jungles
            // of Delthrak. Le raptor du bestiaire a d'ailleurs le même trait.
            ['nom' => 'Raptor apprivoisé', 'type' => 'compagnon',
                'deplacement' => 8, 'attaque' => 2, 'defense' => 2, 'pv_body' => 3, 'pv_mind' => 3, 'prix' => 100, 'animal' => true,
                'capacites' => ['attaque_diagonale', 'tacticien'],
                'description' => 'Mordeur fuyant : il se déplace avant ET après son attaque.'],
        ];

        // Purge des trois inventés : `updateOrCreate` seul les laisserait en
        // base à côté des officiels, et le marché en proposerait huit.
        Mercenaire::whereNotIn('nom', array_column($mercenaires, 'nom'))->delete();

        foreach ($mercenaires as $m) {
            Mercenaire::updateOrCreate(['nom' => $m['nom']], $m);
        }
    }
}
