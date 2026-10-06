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
 *
 * ⚠ NEUVIÈME ligne depuis le 2026-09-30 (First Light, lot C) : le Squelette
 * Hearthkin partage ce catalogue (même bloc de stats, même table
 * `groupe_mercenaires`, même purge de fin de quête) mais `octroi_seul: true`
 * — il n'est jamais recrutable au hub, voir `MercenaireController::catalogue()`.
 *
 * ⚠ DIXIÈME ligne depuis le 2026-10-04 (chantier 3b, mission « secourir ») :
 * Gothar, captif de *The Frozen Horror* — `octroi_seul: true` (même garde)
 * ET `captif: true` (jamais joué comme un mercenaire ordinaire : posé
 * `etat: 'captif'` par `DemarreurQuete`, il attend d'être LIBÉRÉ en jeu).
 * `mode_captif: 'figurine'` (explicite, même si c'est la valeur par défaut) :
 * une fois libéré il devient un allié ordinaire, comme n'importe quel autre.
 *
 * ⚠ ONZIÈME et DOUZIÈME lignes depuis le 2026-10-05 (chantier
 * « captifs-jetons », René : « Le Prospecteur et la Princesse Millandriel…
 * nouveau mode de captif escorté »). *The Mage of the Mirror* (livret F7539
 * p. 4) les décrit comme des TUILES SANS CARTE — « This tile represents the
 * old prospector who acts as an ally and is controlled by the hero who
 * finds him » / « Princess Millandriel[l]… acts as an ally and is
 * controlled by the hero who finds her » — AUCUN Move/Attack/Defend/Body/
 * Mind nulle part dans le livret (ni texte de quête p. 7-33, ni carte : ils
 * n'en ont pas). `deplacement`/`attaque`/`defense`/`pv_body`/`pv_mind`/`prix`
 * valent donc `0` : pas une valeur SOURCÉE (CLAUDE.md, « ne jamais seeder
 * une valeur que les livrets ne sourcent pas »), une COLONNE jamais lue pour
 * ce mode — `mode_captif: 'escorte'` fait sortir ces deux lignes de tout
 * calcul de combat avant qu'elles n'y entrent (aucune figurine, aucun tour,
 * aucune cible). `octroi_seul: true` ET `captif: true`, même garde que
 * Gothar. Recapture à sa case d'origine si le porteur tombe : « monsters
 * take the prospector to room D » (p. 23, quête 4) — généralisée à
 * Millandriel (même description de tuile, même mode).
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

            // ---- SQUELETTE HEARTHKIN (First Light, FL-Q p. 6, lot C 2026-09-30) —
            // « Hearthkin Skeleton — Move 8 · Attack 2 · Defend 2 · Body 1 ·
            // Mind 0. » `octroi_seul: true` : jamais recruté au hub contre de
            // l'or — il n'existe que par l'action du Cor des Hearthkin, en
            // quête (`ResolveurTour::resoudreCorHearthkin()`), un par héros
            // debout. `prix` reste 0 pour la même raison que `prix_base` sur
            // l'objet : aucune carte n'en donne un, et `octroi_seul` empêche
            // de toute façon ce chiffre d'atteindre un achat réel.
            ['nom' => 'Squelette Hearthkin', 'type' => 'squelette_hearthkin',
                'deplacement' => 8, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 0, 'prix' => 0,
                'animal' => false, 'octroi_seul' => true,
                'description' => 'Un squelette dressé par le Cor des Hearthkin ; il quitte le jeu en fin de quête.'],

            // ---- GOTHAR — captif de la mission « secourir » (The Frozen
            // Horror, quête 3, p. 19/37, chantier 3b 2026-10-04) —
            // « Gothar — Move 6 · Attack 1 · Defend 2 · Body 2 · Mind 4 ».
            // Jamais recruté au hub (`octroi_seul`, même garde que le
            // Squelette Hearthkin) : posé sur la carte par `DemarreurQuete`
            // quand le gabarit tiré est « Mission de sauvetage »
            // (`structure.objectif = 'secourir'`), `groupe_mercenaires.etat`
            // démarrant à `'captif'` plutôt que `'actif'` — un héros au
            // contact doit encore le LIBÉRER avant qu'il ne joue.
            //
            ['nom' => 'Gothar', 'type' => 'captif_gothar',
                'deplacement' => 6, 'attaque' => 1, 'defense' => 2, 'pv_body' => 2, 'pv_mind' => 4, 'prix' => 0,
                'animal' => false, 'octroi_seul' => true, 'captif' => true, 'mode_captif' => 'figurine',
                'description' => 'Un barbare captif, prisonnier du donjon — à libérer et à ramener vivant à l\'escalier.'],

            // ---- LE PROSPECTEUR et LA PRINCESSE MILLANDRIEL — captifs
            // ESCORTÉS de *The Mage of the Mirror* (F7539 p. 4, quêtes 4 et
            // 10, chantier « captifs-jetons » 2026-10-05). Tuiles SANS
            // carte : aucune stat à sourcer, voir le docblock de classe —
            // `mode_captif: 'escorte'` fait de ce `0` partout une colonne
            // ignorée plutôt qu'une valeur inventée.
            ['nom' => 'Le Prospecteur', 'type' => 'captif_prospecteur',
                'deplacement' => 0, 'attaque' => 0, 'defense' => 0, 'pv_body' => 0, 'pv_mind' => 0, 'prix' => 0,
                'animal' => false, 'octroi_seul' => true, 'captif' => true, 'mode_captif' => 'escorte',
                'description' => 'Le vieux prospecteur, seul à savoir reconnaître le lunarium véritable — à libérer et porter jusqu\'à l\'escalier.'],
            ['nom' => 'Princesse Millandriel', 'type' => 'captif_millandriel',
                'deplacement' => 0, 'attaque' => 0, 'defense' => 0, 'pv_body' => 0, 'pv_mind' => 0, 'prix' => 0,
                'animal' => false, 'octroi_seul' => true, 'captif' => true, 'mode_captif' => 'escorte',
                'description' => 'La fille captive de la reine Terrellia — à libérer et porter jusqu\'à l\'escalier.'],
        ];

        // Purge des trois inventés : `updateOrCreate` seul les laisserait en
        // base à côté des officiels, et le marché en proposerait huit.
        Mercenaire::whereNotIn('nom', array_column($mercenaires, 'nom'))->delete();

        foreach ($mercenaires as $m) {
            Mercenaire::updateOrCreate(['nom' => $m['nom']], $m);
        }
    }
}
