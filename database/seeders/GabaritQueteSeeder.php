<?php

namespace Database\Seeders;

use App\Models\GabaritQuete;
use Illuminate\Database\Seeder;

/**
 * Gabarits de quête de départ (doc 06 §2) : la structure est garantie par le
 * gabarit, le MJ IA remplit (narration, PNJ, habillage), le moteur assemble
 * le contenu mécanique au budget de rencontres (budget en points de `cout`
 * du bestiaire, multiplié par le score de puissance du groupe — doc 06 §2).
 */
class GabaritQueteSeeder extends Seeder
{
    public function run(): void
    {
        $gabarits = [
            [
                'nom' => 'Exploration simple',
                'type_jalon' => 'normale',
                'structure' => [
                    'objectif' => 'atteindre_et_recuperer', // objet/PNJ au fond du donjon
                    // ⚠ « certains objectifs de quête majeurs MARQUÉS PAR LE
                    // GABARIT » — le troisième déclencheur de montée de niveau
                    // (doc 01 §5), resté sans lecteur jusqu'au 2026-09-04.
                    // C'est la moitié « éligibilité » : ce drapeau dit que
                    // ramener l'objet du fond VAUT un niveau. L'autre moitié
                    // est la cadence (`CadenceNiveaux`), qui décide lesquelles
                    // de ces quêtes comptent — sans elle une campagne très
                    // longue distribuerait vingt niveaux là où la doc en vise
                    // huit.
                    'objectif_majeur' => true,
                    // Plancher à 5 salles : en dessous, l'arbre ne peut pas se
                    // replier assez pour offrir une paire de salles voisines mais
                    // non reliées — donc AUCUNE boucle, donc aucune porte secrète
                    // (0 % à 3 salles, 59 % à 4, 100 % à partir de 5).
                    'salles' => ['min' => 5, 'max' => 8],
                    'jalons' => ['entree', 'obstacle_median', 'salle_objectif'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2], // ex. passage discret vs frontal
                    ],
                    'budget_rencontres' => ['base' => 6, 'par_salle' => true],
                    // Deck de cartes de fouille (doc 14 §3.2) — remplace l'ancien
                    // tirage pondéré, qui remappait un d6 sur des poids et biaisait
                    // la distribution dès que leur total n'était pas 6. Ici la
                    // composition est GARANTIE : on pioche, et seules les cartes qui
                    // disent de revenir sous le paquet (errants, pièges) y reviennent —
                    // le reste sort pour la quête (`DeckFouille::SORT_PAR_ISSUE`).
                    //
                    // Règle de dimensionnement : somme(cartes) > salles.max, sinon
                    // la dernière salle devient déductible (« il ne reste qu'une
                    // carte, c'est forcément le piège »). DeckFouille complète en
                    // « rien » si le compte est trop juste.
                    //
                    // `or_coffre` : ce que verse le coffre désigné quand aucune arme
                    // unique n'est disponible pour la quête.
                    'deck_fouille' => [
                        // Paquet de trésor : 24 cartes au TOTAL, confirmé (livret 2021,
                        // boîte F2847 « Contents » ; First Light G0978 p. 4). ⚠ La
                        // RÉPARTITION par carte n'est sourcée nulle part (⚠ non trouvé :
                        // aucune liste officielle par carte) : elle reste à confirmer par
                        // le décompte du paquet photographié par René. Montants sourcés
                        // par ces photos (reference/16 §7.4) : gemme 35, or 15, or 25,
                        // bijoux 50. Aucune quantité ne se change sans source.
                        'cartes' => [
                            'gemme' => 2,          // 35 po
                            'or_25' => 2,
                            'or_15' => 2,
                            'bijoux' => 2,         // 50 po
                            'piege_trou' => 2,
                            'piege_fleches' => 2,
                            'potion_soin' => 3,
                            'potion_heroisme' => 1,
                            'potion_force' => 1,
                            'potion_defense' => 1,
                            'errant' => 6,
                        ],
                        'or' => 25,                // repli si un montant manque
                        'or_coffre' => 90,
                        // Potions du COFFRE ORDINAIRE (derrière une porte secrète), lues par
                        // `DeckFouille::carteCoffre()` une fois sur deux. Ce ne sont PAS des
                        // cartes du paquet : celle-ci donne la Fiole de soin.
                        'potions' => ['Potion de soin', 'Potion de soin mineur'],
                    ],
                    'pieges' => ['min' => 1, 'max' => 2],
                    // ÉPREUVES (2026-08-24) : les ancrages à jet d'attribut. Sans
                    // elles, le moteur n'émet qu'un seul jet — la fouille de zone —
                    // et les contextes `savoir`/`social_peur` n'ont aucun producteur.
                    'epreuves' => ['min' => 1, 'max' => 2],
                    // TERRAIN (doc 18 §4, phase 6a) : même format que
                    // pieges/epreuves — le gabarit dit COMBIEN, le thème du
                    // groupe (`groupes.theme_bestiaire`) dit LESQUELS
                    // (`AssembleurCarte::placerTerrains()`). ⚠ Inerte à ce
                    // jour : les 7 terrains sourcés sont tous
                    // `boite = horreur_des_glaces`, une boîte absente de
                    // `DemarreurQuete::BOITES_THEMATIQUES` tant que ses règles
                    // restent incomplètes — voir le rapport de la phase 6a.
                    'terrains' => ['min' => 1, 'max' => 2, 'tunnels' => ['min' => 0, 'max' => 1],
                        // SABLES MOUVANTS (F7539 p. 29) : COMBIEN de bandes — le thème `mage_du_miroir`
                        // dit SI (`terrains.boite`), `placerTerrains()` abandonne la pose plutôt que de
                        // casser la connectivité d'une salle.
                        'sables' => ['min' => 0, 'max' => 1],
                        'trappes' => ['min' => 0, 'max' => 1],
                        // COURANTS CONTRAIRES (Spirit Queen q. 11 B, lot H des restes, 2026-10-29) : COMBIEN de SALLES
                        // entières. Le thème `spirit_queen` dit SI (`terrains.boite`) ; sans lui, aucun tirage.
                        'courants' => ['min' => 0, 'max' => 1],
                        // SOL MEUBLE (Spirit Queen q. 10 note B, lot H des restes, 2026-10-29) : COMBIEN de salles entières. Même
                        // règle que les courants : le thème dit SI.
                        'sol_meuble' => ['min' => 0, 'max' => 1],
                        // COURANT PORTEUR (Spirit Queen q. 11 note C, lot H des restes, 2026-10-29) : COMBIEN de salles entières.
                        'courants_porteurs' => ['min' => 0, 'max' => 1],
                        // VENTS DE COULOIR (Spirit Queen q. 12, lot H des restes, 2026-10-29) : 0 ou 1 — la carte a TOUS ses couloirs
                        // ventés, ou aucun (« strong air currents move through the hallways »). Le thème dit SI (`terrains.boite`).
                        'vents' => ['min' => 0, 'max' => 1]],
                    // LEVIERS (2026-09-06, phase 6b) : même format que
                    // pieges/epreuves/terrains — le gabarit dit COMBIEN,
                    // `AssembleurCarte::placerLeviers()` choisit LA porte à
                    // verrouiller et la case du levier, et renonce plutôt que
                    // de forcer si aucune case n'est atteignable sans elle.
                    // ⚠ Contrairement à `terrains`, AUCUN filtre de thème — un
                    // levier a sa place dans n'importe quel donjon (arbitrage
                    // René 2026-09-06). Valeurs sobres : un donjon n'est pas
                    // une salle des machines, une porte verrouillée reste
                    // l'exception dans une quête d'exploration ordinaire.
                    'leviers' => ['min' => 0, 'max' => 1],
                    'butin' => ['or_base' => 50],
                ],
            ],
            [
                // MISSION « SECOURIR » (chantier 3b, 2026-10-04 — généralise
                // Gothar, Frozen Horror quête 3, p. 19 : « escort the
                // Barbarian… If the Barbarian dies, Gothar is automatically
                // captured » — même famille que le Prospecteur et la
                // Princesse Millandriel de *The Mage of the Mirror*).
                //
                // Reprend la structure d'« Exploration simple » — le captif
                // est posé dans la salle-ARTEFACT (`DeckFouille::construire()`,
                // la salle la plus profonde du graphe quand il n'y a pas de
                // boss à viser), le même emplacement qu'un coffre à artefact
                // ordinaire, jamais une seconde case choisie à part —
                // `App\Partie\DemarreurQuete::demarrer()` lit `objectif:
                // 'secourir'` pour y poser le captif au lieu du coffre.
                'nom' => 'Mission de sauvetage',
                'type_jalon' => 'normale',
                'structure' => [
                    'objectif' => 'secourir',
                    // Pas de troisième déclencheur de montée de niveau : une
                    // mission de sauvetage est un contenu additionnel, pas le
                    // jalon qui structure l'arc — contrairement à
                    // « Exploration simple », dont René a fait le porteur de
                    // ce rôle (doc 01 §5).
                    'objectif_majeur' => false,
                    'salles' => ['min' => 5, 'max' => 8],
                    'jalons' => ['entree', 'obstacle_median', 'salle_objectif'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2],
                    ],
                    'budget_rencontres' => ['base' => 6, 'par_salle' => true],
                    'deck_fouille' => [
                        // Paquet de trésor : 24 cartes au TOTAL, confirmé (livret 2021,
                        // boîte F2847 « Contents » ; First Light G0978 p. 4). ⚠ La
                        // RÉPARTITION par carte n'est sourcée nulle part (⚠ non trouvé :
                        // aucune liste officielle par carte) : elle reste à confirmer par
                        // le décompte du paquet photographié par René. Montants sourcés
                        // par ces photos (reference/16 §7.4) : gemme 35, or 15, or 25,
                        // bijoux 50. Aucune quantité ne se change sans source.
                        'cartes' => [
                            'gemme' => 2,
                            'or_25' => 2,
                            'or_15' => 2,
                            'bijoux' => 2,
                            'piege_trou' => 2,
                            'piege_fleches' => 2,
                            'potion_soin' => 3,
                            'potion_heroisme' => 1,
                            'potion_force' => 1,
                            'potion_defense' => 1,
                            'errant' => 6,
                        ],
                        'or' => 25,
                        'or_coffre' => 90,
                        // Potions du COFFRE ORDINAIRE (derrière une porte secrète), lues par
                        // `DeckFouille::carteCoffre()` une fois sur deux. Ce ne sont PAS des
                        // cartes du paquet : celle-ci donne la Fiole de soin.
                        'potions' => ['Potion de soin', 'Potion de soin mineur'],
                    ],
                    'pieges' => ['min' => 1, 'max' => 2],
                    'epreuves' => ['min' => 1, 'max' => 2],
                    'terrains' => ['min' => 1, 'max' => 2, 'tunnels' => ['min' => 0, 'max' => 1],
                        // SABLES MOUVANTS (F7539 p. 29) : COMBIEN de bandes — le thème `mage_du_miroir`
                        // dit SI (`terrains.boite`), `placerTerrains()` abandonne la pose plutôt que de
                        // casser la connectivité d'une salle.
                        'sables' => ['min' => 0, 'max' => 1],
                        'trappes' => ['min' => 0, 'max' => 1],
                        // COURANTS CONTRAIRES (Spirit Queen q. 11 B, lot H des restes, 2026-10-29) : COMBIEN de SALLES
                        // entières. Le thème `spirit_queen` dit SI (`terrains.boite`) ; sans lui, aucun tirage.
                        'courants' => ['min' => 0, 'max' => 1],
                        // SOL MEUBLE (Spirit Queen q. 10 note B, lot H des restes, 2026-10-29) : COMBIEN de salles entières. Même
                        // règle que les courants : le thème dit SI.
                        'sol_meuble' => ['min' => 0, 'max' => 1],
                        // COURANT PORTEUR (Spirit Queen q. 11 note C, lot H des restes, 2026-10-29) : COMBIEN de salles entières.
                        'courants_porteurs' => ['min' => 0, 'max' => 1],
                        // VENTS DE COULOIR (Spirit Queen q. 12, lot H des restes, 2026-10-29) : 0 ou 1 — la carte a TOUS ses couloirs
                        // ventés, ou aucun (« strong air currents move through the hallways »). Le thème dit SI (`terrains.boite`).
                        'vents' => ['min' => 0, 'max' => 1]],
                    'leviers' => ['min' => 0, 'max' => 1],
                    'butin' => ['or_base' => 50],
                ],
            ],
            [
                'nom' => 'Antre du sous-boss',
                'type_jalon' => 'sous_boss',
                'structure' => [
                    'objectif' => 'vaincre_sous_boss',
                    'salles' => ['min' => 6, 'max' => 9],
                    'jalons' => ['entree', 'point_de_non_retour', 'antre'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2],
                        ['apres' => 'point_de_non_retour', 'branches' => 2], // affaiblir le boss vs y aller
                    ],
                    'budget_rencontres' => ['base' => 8, 'par_salle' => true],
                    'deck_fouille' => [
                        // Paquet de trésor : 24 cartes au TOTAL, confirmé (livret 2021,
                        // boîte F2847 « Contents » ; First Light G0978 p. 4). ⚠ La
                        // RÉPARTITION par carte n'est sourcée nulle part (⚠ non trouvé :
                        // aucune liste officielle par carte) : elle reste à confirmer par
                        // le décompte du paquet photographié par René. Montants sourcés
                        // par ces photos (reference/16 §7.4) : gemme 35, or 15, or 25,
                        // bijoux 50. Aucune quantité ne se change sans source.
                        'cartes' => [
                            'gemme' => 2,          // 35 po
                            'or_25' => 2,
                            'or_15' => 2,
                            'bijoux' => 2,         // 50 po
                            'piege_trou' => 2,
                            'piege_fleches' => 2,
                            'potion_soin' => 3,
                            'potion_heroisme' => 1,
                            'potion_force' => 1,
                            'potion_defense' => 1,
                            'errant' => 6,
                        ],
                        'or' => 25,                // repli si un montant manque
                        'or_coffre' => 180,
                        // Potions du COFFRE ORDINAIRE (derrière une porte secrète), lues par
                        // `DeckFouille::carteCoffre()` une fois sur deux. Ce ne sont PAS des
                        // cartes du paquet : celle-ci donne la Fiole de soin.
                        'potions' => ['Potion de soin', 'Potion de restauration', 'Potion de bataille'],
                    ],
                    // ⚠ POOL de sorciers nommés, tiré au sort à chaque quête
                    // (René, 2026-09-04). Le champ existait depuis la 3.8 et
                    // n'avait JAMAIS été rempli : le repli prenait le leader de
                    // coût du palier, si bien qu'aucun lanceur nommé n'était
                    // jamais apparu en partie. Une liste plutôt qu'une valeur
                    // unique, sinon toutes les campagnes finiraient sur le même
                    // adversaire — le défaut du pool de salles, un cran plus
                    // haut.
                    //
                    // Les sous-boss lanceurs du bestiaire : le Garde-mage (Boule de
                    // Flammes, Tourmente) ; Grak et Balur du jeu de base (2026-10-31).
                    'rencontre_finale' => [
                        'tier' => 'sous_boss',
                        'escorte_budget' => 4,
                        // SORCIER DU DREAD (Prophecy of Telor, chantier monstre
                        // à phases 2026-10-04) les rejoint : sous-boss générique
                        // à répertoire limité (Q3, Telor) — son archétype se
                        // déclare complet, le filtre par palier fait le reste.
                        'archetypes' => [
                            'garde_magus', 'sorcier_dread_telor',
                            // GRAK (jeu de base, quête 6, F2847 PDF 8 — 2026-10-31) : sous-boss lanceur, trois sorts
                            // du livret (Frayeur, Sommeil, Tourmente). « Fear, sleep, and tempest ».
                            'grak',
                            // BALUR (jeu de base, quête 8, F2847 PDF 10 — 2026-10-31) : sous-boss, six sorts UNE FOIS
                            // chacun (`sorts_uniques`), et l'immunité au feu. « Fire spells do not affect him. »
                            'balur_mage_du_feu',
                            // LES QUATRE LIEUTENANTS DE MORCAR (Wizards of Morcar,
                            // vague 2C, 2026-10-08) : Maître des orages, Haut mage,
                            // Mage de guerre orque, Nécromancien — sous-boss, un par
                            // quête dans le livret G1504 (« the next Lieutenant of
                            // Morcar you must defeat », p. 28). La Gardienne, elle,
                            // est le boss du gabarit final.
                            'orages_morcar', 'haut_mage_morcar', 'guerriere_orque_morcar', 'necromancien_morcar',
                            // BAKABRI LE CRUEL (Jungles of Delthrak, quête 15 voie C, 2026-10-15) :
                            // sous-boss lanceur — Étreinte des Ronces, Canaliser l'Effroi, Éclair de Chaos,
                            // et le tir au choix de Gruulob. Le livret l'envoie vers la quête 16 : il
                            // n'est pas le dernier mot de sa voie.
                            'bakabri_le_cruel',
                            // XENLOTH, « the Dread Mage » (Against the Ogre Horde q. 9, F9528 p. 37) :
                            // sous-boss (René, 2026-10-02), Mind Lock et Mind Burst, 5 cartes de chaque.
                            'xenloth_mage_dread',
                            // GRAGOR, « the evil magician » de la Grande Citadelle (Kellar's Keep q. 6, 2026-10-23) : sous-boss,
                            // cinq sorts nommés par le livret — deux de palier boss, filtrés (voir `config/archetypes_lanceurs.php`).
                            'gragor_kellar',
                            // KAVRA, sorcière brigande (Spirit Queen's Torment, quête 3, G0053 p. 11 ; 2026-10-23) : sous-boss
                            // sorcier, Éclair de Chaos + Boule de Flammes, double sort au premier tour seulement. Boîte `spirit_queen`
                            // (thème allumé le 2026-10-23) : son archétype se tire sous ce thème, la préférence de boîte le désigne.
                            'kavra_sorciere',
                            // LES QUATRE LANCEURS NOMMÉS DE THE CRYPT OF PERPETUAL DARKNESS (thème allumé le 2026-10-23) :
                            // Sorcière des Marais (quête 3), Buubhealxea (quête 5), Roi Archaloneus (quête 6), Kedrick
                            // Gilbane (quête 9). Chacun porte `reserve_a_son_theme` : jamais tiré hors de la Crypte, même
                            // quand ce pool n'a aucun autre candidat du thème (`DemarreurQuete::acheterMonstres()`).
                            'sorciere_des_marais', 'buubhealxea_reine_gobeline', 'archaloneus_roi_momie', 'kedrick_gilbane',
                        ],
                        // ⚠ …et les ONZE brutes du palier, qui n'ont pas de
                        // répertoire et ne pouvaient donc pas être nommées.
                        // C'est là que vit la diversité du sous-boss : la
                        // double attaque de l'Ours, le venin et la ponte des créatures de Delthrak.
                        //
                        // ⚠ Doralf et Spawn of the Pit (Against the Ogre Horde,
                        // chantier monstre à phases 2026-10-04) les rejoignent.
                        // Seule la PREMIÈRE phase de Spawn of the Pit est nommée
                        // ici : sa forme « déchaînée » n'est accessible que par
                        // transformation en jeu (`monstres.phase_suivante`),
                        // jamais achetée directement — sans quoi la rencontre
                        // pourrait démarrer DÉJÀ dans sa seconde forme, ce que
                        // sa carte ne permet pas.
                        'creatures' => [
                            // ULAG (jeu de base, quête 3, F2847 PDF 5 — 2026-10-31) : brute sans sort, « the orc warlord ».
                            'Ulag', 'Ours polaire de guerre', 'Yéti', 'Ogre',
                            'Ogre guerrier', 'Ogre champion', 'Loup géant',
                            'Rampant putride', 'Serpent géant', 'Singe géant',
                            'Doralf', 'Spawn of the Pit',
                            // KELLAR'S KEEP (2026-10-23) : le squelette-roi (q. 5, éveil par attaque de sa salle), le sorcier
                            // Borokk (q. 9, attaque de l'esprit) et Bellthor (Witch Lord q. 5, éveil quand tout le groupe est dans
                            // sa salle) — tous les trois sous-boss, nommés en créatures : aucun répertoire de sorts.
                            'Squelette-roi nain', 'Borokk', 'Bellthor',
                            // Le MÉTAMORPHE (F4543 p. 25 note A, 2026-10-23) : sous-boss sans répertoire, qui revient à chaque mort
                            // (sa règle est sur la fiche, `capacites.metamorphe`) — la rencontre le tire comme les trois autres.
                            'Métamorphe',
                            // MINOTAURE (Wizards of Morcar, 2026-10-08) : brute sans
                            // répertoire (coup de corne), « Brak-Fellorn » du livret.
                            'Minotaure',
                            // LES TROIS SOUS-BOSS NOMMÉS DE 2026-10-15 : l'EFFIGIE gardienne
                            // (Against the Ogre Horde q. 4 — tourelle, immunisée aux sorts, posée en
                            // salle seulement), la DEATHSPINNER (Jungles of Delthrak q. 3 — un
                            // Canaliser l'Effroi gratuit sur chaque héros qui finit son tour en sa
                            // vue) et le GOLEM Cœur-de-Forge (q. 6 — un souffle de salle, une fois).
                            // Aucune n'a de répertoire de sorcier : elles se nomment en créatures.
                            'Effigie gardienne', 'Deathspinner la Reine du Fléau', 'Golem Cœur-de-Forge',
                            // LA CRYPTE, sous-boss sans répertoire (thème allumé le 2026-10-23) : le Squelette de minotaure
                            // (quête 4), la Momie-naine (quête 8) et le CRÂNE DU DREAD (tourelle, quête 9 note A) — réservés à
                            // leur thème (`reserve_a_son_theme`), comme l'Effigie gardienne, tourelle de la même famille.
                            'Squelette de minotaure', 'Momie-naine', 'Crâne du Dread',
                            // LA LÉGION DU WITCH LORD (*Return of the Witch Lord*, chantier 2026-10-23) : les sous-boss du
                            // thème `witch_lord`. Skulmar (chef de la Légion, q. 7), Kessandria (reine sorcière, q. 9 —
                            // sous-boss par le précédent des lieutenants de Morcar), la Garde du Dread (q. 9-10), le
                            // Cavalier-esprit (q. 2). Aucun n'a de répertoire d'archétype : ils se nomment en créatures.
                            'Skulmar', 'Kessandria', 'Garde du Dread', 'Cavalier-esprit',
                            // ABRAXUS (First Light, quête 9, PDF 18 — 2026-10-31) : le général de Qwindrak, sous-boss de sa boîte
                            // `first_light`, sans répertoire (une arbalète, la diagonale). Le thème `first_light` le préfère
                            // (`DemarreurQuete::acheterMonstres()`), et la Garde du Dread, partagée avec cette boîte, s'y joint.
                            'Abraxus',
                        ],
                    ],
                    'pieges' => ['min' => 2, 'max' => 3],
                    'epreuves' => ['min' => 1, 'max' => 2],
                    // TERRAIN — même remarque que ci-dessus (inerte tant que
                    // la boîte de glace n'est pas rallumée).
                    'terrains' => ['min' => 2, 'max' => 3, 'tunnels' => ['min' => 0, 'max' => 1],
                        // SABLES MOUVANTS (F7539 p. 29) : COMBIEN de bandes — le thème `mage_du_miroir`
                        // dit SI (`terrains.boite`), `placerTerrains()` abandonne la pose plutôt que de
                        // casser la connectivité d'une salle.
                        'sables' => ['min' => 0, 'max' => 1],
                        'trappes' => ['min' => 0, 'max' => 1],
                        // COURANTS CONTRAIRES (Spirit Queen q. 11 B, lot H des restes, 2026-10-29) : COMBIEN de SALLES
                        // entières. Le thème `spirit_queen` dit SI (`terrains.boite`) ; sans lui, aucun tirage.
                        'courants' => ['min' => 0, 'max' => 1],
                        // SOL MEUBLE (Spirit Queen q. 10 note B, lot H des restes, 2026-10-29) : COMBIEN de salles entières. Même
                        // règle que les courants : le thème dit SI.
                        'sol_meuble' => ['min' => 0, 'max' => 1],
                        // COURANT PORTEUR (Spirit Queen q. 11 note C, lot H des restes, 2026-10-29) : COMBIEN de salles entières.
                        'courants_porteurs' => ['min' => 0, 'max' => 1],
                        // VENTS DE COULOIR (Spirit Queen q. 12, lot H des restes, 2026-10-29) : 0 ou 1 — la carte a TOUS ses couloirs
                        // ventés, ou aucun (« strong air currents move through the hallways »). Le thème dit SI (`terrains.boite`).
                        'vents' => ['min' => 0, 'max' => 1]],
                    // LEVIERS — voir le commentaire du gabarit « Exploration
                    // simple ». Un antre de sous-boss gagne à garder au moins
                    // une antichambre gardée par une porte verrouillée.
                    'leviers' => ['min' => 1, 'max' => 1],
                    'butin' => ['or_base' => 120],
                ],
            ],
            [
                'nom' => 'Confrontation finale',
                'type_jalon' => 'boss_final',
                'structure' => [
                    'objectif' => 'vaincre_boss_final',
                    'salles' => ['min' => 7, 'max' => 10],
                    'jalons' => ['entree', 'epreuve', 'antichambre', 'salle_du_trone'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2],
                        ['apres' => 'epreuve', 'branches' => 2],
                    ],
                    'budget_rencontres' => ['base' => 10, 'par_salle' => true],
                    'deck_fouille' => [
                        // Paquet de trésor : 24 cartes au TOTAL, confirmé (livret 2021,
                        // boîte F2847 « Contents » ; First Light G0978 p. 4). ⚠ La
                        // RÉPARTITION par carte n'est sourcée nulle part (⚠ non trouvé :
                        // aucune liste officielle par carte) : elle reste à confirmer par
                        // le décompte du paquet photographié par René. Montants sourcés
                        // par ces photos (reference/16 §7.4) : gemme 35, or 15, or 25,
                        // bijoux 50. Aucune quantité ne se change sans source.
                        'cartes' => [
                            'gemme' => 2,          // 35 po
                            'or_25' => 2,
                            'or_15' => 2,
                            'bijoux' => 2,         // 50 po
                            'piege_trou' => 2,
                            'piege_fleches' => 2,
                            'potion_soin' => 3,
                            'potion_heroisme' => 1,
                            'potion_force' => 1,
                            'potion_defense' => 1,
                            'errant' => 6,
                        ],
                        'or' => 25,                // repli si un montant manque
                        'or_coffre' => 300,
                        // Potions du COFFRE ORDINAIRE (derrière une porte secrète), lues par
                        // `DeckFouille::carteCoffre()` une fois sur deux. Ce ne sont PAS des
                        // cartes du paquet : celle-ci donne la Fiole de soin.
                        'potions' => ['Potion de soin', 'Potion de bataille', 'Antidote au venin'],
                    ],
                    // ⚠ Les bosses lanceurs de la rotation, tirés au sort (même raison
                    // qu'au jalon sous-boss). Chacun impose une lecture différente du combat
                    // final : le Seigneur Sorcier de Barak Tor (jeu de base) invoque les morts et
                    // tente la Tempête, Magrian terrifie et appelle des spectres, l'Horreur des
                    // Glaces gèle et se soigne, l'Archimage elfe lâche ses loups.
                    //
                    // ⚠ Le Seigneur et ses deux pairs de nos créations (Liche, Sorcier des Tempêtes)
                    // sont RETIRÉS le 2026-10-31 (René : « Enlève nos créations et mets ceux du jeu de
                    // base ») : le Witch Lord du jeu de base ferme la campagne « jeu de base seul ».
                    // Le `cout` sert toujours à une seule chose : le budget de rencontre que le boss
                    // laisse aux sbires (`DemarreurQuete`).
                    'rencontre_finale' => [
                        'tier' => 'boss',
                        'escorte_budget' => 6,
                        // ⚠ `horreur_glacee` est REVENU le 2026-09-06. Il avait été
                        // retiré le 2026-09-04 parce que trois des six sorts de sa
                        // carte n'étaient pas portés — « un boss final à moitié
                        // écrit n'a rien à faire en tête d'affiche ». Les six le
                        // sont désormais (Gel de l'Esprit, Mur de Glace, Patinage
                        // ont rejoint Morsure de Froid, Tempête de Glace et
                        // Apaisement), et `BOITES_INCOMPLETES` est vide.
                        //
                        // ⚠ Le pool n'est PAS filtré ici : c'est `DemarreurQuete`
                        // qui restreint les candidats au thème du groupe. L'écrire
                        // deux fois ferait diverger les deux copies — un seul point
                        // de passage, comme partout ailleurs.
                        'archetypes' => [
                            // LE SEIGNEUR SORCIER DE BARAK TOR (le Witch Lord du JEU DE BASE, quête 14, F2847 PDF 16 —
                            // 2026-10-31) : le boss final de la boîte de base, `boite` = 'base' — donc le seul candidat de
                            // la campagne « jeu de base seul » (`BestiaireGroupe::autorise()` laisse passer 'base').
                            'seigneur_sorcier_barak_tor',
                            // MAGRIAN, LE DREAD WRAITH (boss FINAL de Rise of the Dread Moon, quête 10 — chantier EXT-MAGRIAN, 2026-10-23) : son
                            // archétype À SON NOM remplace celui de l'Ombre (qui n'entre plus ici : sous-boss de la boîte). Réservé à
                            // son thème (`reserve_a_son_theme`) : jamais tiré hors de Rise of the Dread Moon.
                            'magrian', 'archimage_elfe', 'horreur_glacee',
                            // GRETZL LA PORTE-FLÉAU (Jungles of Delthrak, chantier
                            // monstre à phases 2026-10-04) : premier boss du thème
                            // `jungles_delthrak`, resté sans boss depuis le premier
                            // portage. Seule sa PREMIÈRE phase est nommée — ses
                            // formes « Demonspider »/« Demonape » ne s'atteignent
                            // qu'en jeu, par `monstres.phase_suivante`.
                            'gretzl_porte_fleau',
                            // GRUULOB, SORCIER GOBELIN CORROMPU (Jungles of Delthrak,
                            // quête 8, 2026-10-09) : second boss du thème, à deux formes.
                            // Seule sa PREMIÈRE forme est nommée ici — « Gruulob, Forme
                            // Démoniaque » ne s'atteint qu'en jeu, par `phase_suivante`
                            // (et `Monstre::nomsDeFormeSuivante()` l'écarte de la rotation).
                            'gruulob_sorcier_gobelin',
                            // LA GARDIENNE (Artificer, Wizards of Morcar, 2026-10-08) :
                            // SEUL boss de la boîte — « The Keeper […] When the Keeper
                            // is defeated, she vanishes » (G1504 p. 38, quête 9). Les
                            // quatre autres sorciers sont des sous-boss (jalon
                            // ci-dessus). Sous le thème, le pool thématique se réduit
                            // à elle : la campagne finit sur la Gardienne.
                            'artificiere_morcar',
                            // FESTRAL, « the Dread Sorcerer » (Against the Ogre Horde q. 8, F9528 p. 35) :
                            // BOSS (René, 2026-10-02 : lanceur de boss, comme l'Archimage elfe) — Mind
                            // Burst, Mind Lock et Dominate, 3 cartes de chaque ; sa garde de Guerriers du
                            // Dread lance 5 dés d'attaque et 5 de défense (`GardePersonnelle`). Sous le
                            // thème `horde_ogre` le pool se resserre à ses quatre figures de boîte —
                            // Gruzbella, le Commandant, le Seigneur et lui : une préférence, jamais un
                            // filtre. Nexrael (tier `base`) n'est dans AUCUN pool : il entre par l'achat
                            // des « forts », comme toute créature de signature d'une boîte.
                            'festral_sorcier_dread',
                            // QWINDRAK THE WARLOCK (First Light, 2026-10-17 ; archétype le 2026-10-22) : boss
                            // sorcier, le patron d'Archimage elfe et de Festral — un répertoire NOMMÉ par le
                            // livret (« In all forms, Qwindrak knows the Dread spells … », p. 31) et donc un
                            // archétype (`config/archetypes_lanceurs.php`). Le DRAGON, dernière forme de sa
                            // pioche, n'entre jamais seul : c'est Qwindrak qui entre, avec ses formes.
                            'qwindrak',
                            // FELLMARAK, LE ROI SORCIER (Prophecy of Telor, 2026-10-23) : boss sorcier de sa
                            // boîte, répertoire NOMMÉ par le livret (« Fellmarak knows the following Dread
                            // spells … », G0052 p. 31) → archétype `fellmarak_roi_sorcier`. Sous le thème
                            // `prophecy_telor` le pool se réduit à lui ; ailleurs il n'est jamais tiré. Sa
                            // mort à 0 Body est un écart assumé au livret (René, 2026-10-23).
                            'fellmarak_roi_sorcier',
                            // NELATH, LA REINE DES ESPRITS (Spirit Queen's Torment, quête 14, G0053 p. 33 ; 2026-10-23) : boss
                            // sorcier de sa boîte, répertoire NOMMÉ par le livret (Commandement, Frayeur, Éclair de Chaos, Tempête
                            // de feu) → archétype `nelath_reine_des_esprits`. Deux sorts par tour (`double_sort`). Boîte `spirit_queen`
                            // (thème allumé le 2026-10-23) : son archétype se tire sous ce thème. Sa résolution
                            // « sauvée » est ÉCARTÉE (René, 2026-10-23) : elle meurt à 0 Body comme tout boss.
                            'nelath_reine_des_esprits',
                            // VENIM LE DRAGON (The Crypt of Perpetual Darkness, quête 10 — chantier EXT-CRYPTE, 2026-10-23) :
                            // le boss final de sa boîte, répertoire NOMMÉ par le livret (« Venim has the following spells »,
                            // p. 25) → archétype `venim_dragon`, qui inclut Souffle acide. Le thème est ALLUMÉ (le 2026-10-23,
                            // `BOITES_THEMATIQUES`, index 10) : sous lui, le pool se réduit à Venim
                            // (`DemarreurQuete::acheterMonstres()`) ; ailleurs, `reserve_a_son_theme` le garde hors de tout pool.
                            'venim_dragon',
                        ],
                        // Les bosses sans répertoire : ils frappent, c'est tout,
                        // et c'est une lecture du combat final aussi.
                        // ⚠ Le DRAGON (First Light, 2026-09-30) n'entre plus ici : c'est la dernière
                        // forme de Qwindrak (pioche `synchroformes`, `MonstreSeeder`), jamais une
                        // rencontre achetée seule.
                        //
                        // ⚠ GRUZBELLA HAMMERHAND et SIR RAGNAR (chantier monstre à
                        // phases 2026-10-04) le rejoignent, même raison : ni l'une
                        // ni l'autre n'a de répertoire de sorts. Seule la première
                        // phase de Gruzbella est nommée, même garde-fou que
                        // Spawn of the Pit ci-dessus (le jalon sous-boss).
                        'creatures' => [
                            'Ogre commandant', 'Seigneur ogre',
                            'Gruzbella Hammerhand', 'Sir Ragnar',
                            // LE SCION FLEUR-POURRIE (Jungles of Delthrak, quête 14 voie B, 2026-10-15) :
                            // le boss final du livret, immobile (Move 0 : posé en salle seulement) —
                            // sans répertoire de sorcier, donc nommé en créature, comme le Dragon.
                            'Scion Fleur-Pourrie',
                            // LE SEIGNEUR SORCIER (*Return of the Witch Lord*, q. 10, chantier 2026-10-23) : le boss FINAL du
                            // thème `witch_lord` — il ferme la campagne (« there must be no escape for him »). Le thème
                            // resserre ce pool sur ses boss, et il n'a que lui : la rotation le désigne donc à coup sûr.
                            'Seigneur Sorcier',
                            // LA GARGOUILLE GARDIENNE de Grin's Crag (Kellar's Keep q. 10, 2026-10-23) : le DERNIER obstacle de la
                            // boîte, dont la mort ouvre la porte de sortie. Immunisée à tous les sorts, 4 Body. Boss, parce que la
                            // boîte n'en a pas d'autre : c'est elle qui ferme le thème `kellars_keep`.
                            'Gargouille gardienne',
                        ],
                    ],
                    'pieges' => ['min' => 2, 'max' => 4],
                    'epreuves' => ['min' => 1, 'max' => 2],
                    // TERRAIN — même remarque que ci-dessus (inerte tant que
                    // la boîte de glace n'est pas rallumée).
                    'terrains' => ['min' => 2, 'max' => 4, 'tunnels' => ['min' => 1, 'max' => 2],
                        'sables' => ['min' => 0, 'max' => 1],
                        'trappes' => ['min' => 0, 'max' => 1],
                        // COURANTS CONTRAIRES (Spirit Queen q. 11 B, lot H des restes, 2026-10-29) : COMBIEN de SALLES
                        // entières. Le thème `spirit_queen` dit SI (`terrains.boite`) ; sans lui, aucun tirage.
                        'courants' => ['min' => 0, 'max' => 1],
                        // SOL MEUBLE (Spirit Queen q. 10 note B, lot H des restes, 2026-10-29) : COMBIEN de salles entières. Même
                        // règle que les courants : le thème dit SI.
                        'sol_meuble' => ['min' => 0, 'max' => 1],
                        // COURANT PORTEUR (Spirit Queen q. 11 note C, lot H des restes, 2026-10-29) : COMBIEN de salles entières.
                        'courants_porteurs' => ['min' => 0, 'max' => 1],
                        // VENTS DE COULOIR (Spirit Queen q. 12, lot H des restes, 2026-10-29) : 0 ou 1 — la carte a TOUS ses couloirs
                        // ventés, ou aucun (« strong air currents move through the hallways »). Le thème dit SI (`terrains.boite`).
                        'vents' => ['min' => 0, 'max' => 1]],
                    // LEVIERS — voir le commentaire du gabarit « Exploration
                    // simple ». Le donjon final est le plus grand (7-10
                    // salles) : deux antichambres verrouillées avant la salle
                    // du trône restent sobres au regard de sa taille.
                    'leviers' => ['min' => 1, 'max' => 2],
                    'butin' => ['or_base' => 300],
                ],
            ],
        ];

        // updateOrCreate (et non create) : les gabarits sont des données de
        // RÉFÉRENCE, re-semables sur une base vivante quand leur structure
        // évolue — comme ObjetSeeder. Le `create` d'origine échouait sur la
        // contrainte d'unicité du nom, laissant l'ancienne structure en place.
        foreach ($gabarits as $gabarit) {
            GabaritQuete::updateOrCreate(['nom' => $gabarit['nom']], $gabarit);
        }
    }
}
