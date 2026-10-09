<?php

namespace Database\Seeders;

use App\Engine\MotsClesSortDread as Mot;
use App\Models\SortDread;
use App\Partie\MoteurDread;
use Illuminate\Database\Seeder;

/**
 * LES 26 SORTS DE DREAD PORTÉS, un par carte officielle.
 *
 * Source : `dread_spells.pdf` — 29 sorts photographiés par René le 2026-09-04,
 * transcrits carte par carte en **doc 09 §4bis**. Trois ne sont pas portés et
 * sont recensés dans `config/cartes.php` (section `dread`) avec, chacune, la
 * mécanique qui lui manque : *Werewolf's Curse*, *Dispel*, *Mirror Magic*.
 *
 * ⚠ *Mind Freeze*, *Ice Wall* et *Skate* ont rejoint le catalogue le
 * 2026-09-06 (plan glace, phase 2 — `docs/plan-glace-et-degats-mind.md`) :
 * les mécaniques qui leur manquaient (dégâts de Mind, terrain destructible à
 * compteur, mode de déplacement pour un monstre) sont désormais écrites. Seule
 * l'« état de choc » de *Mind Freeze* reste une dette nommée — une section du
 * livret Frozen Horror que le projet n'a pas.
 *
 * ⚠ Rien ici n'est inventé. Le **Trait de Chaos** — notre seul sort sans carte —
 * a quitté le catalogue par migration ; il portait la frappe à distance des
 * répertoires, que trois cartes réelles reprennent (*Ball of Flame*, *Channel
 * Dread*, *Lightning Bolt*).
 *
 * Deux valeurs restent NÔTRES et sont marquées comme telles :
 *  - le **palier** (`base` / `sous_boss` / `boss`, un tier MINIMUM) : aucune
 *    carte ne porte de rang, c'est notre traduction de la phrase du §4 « les
 *    sous-boss lancent déjà les sorts mineurs, le boss final ajoute les sorts
 *    vilains ». Le palier `base` n'est pas un relâchement : les extensions
 *    donnent expressément la magie à des créatures ordinaires (doc 18).
 *  - les **usages** par rencontre (MoteurDread::USAGES_*). Les cartes disent le
 *    plus souvent « once per quest » PAR SORT ; nous gardons un budget global,
 *    qui produit la même rareté sans un compteur par sort et par instance.
 *
 * Le vocabulaire d'`effet` est fermé (`App\Engine\MotsClesSortDread`), confronté
 * à ce fichier dans les deux sens par `SortsDreadSourcesTest`.
 */
class SortDreadSeeder extends Seeder
{
    public function run(): void
    {
        $sorts = [

            // ============================================================
            // DÉGÂTS
            // ============================================================

            // « It inflicts 2 Body Points of damage. The hero then rolls 2 red
            // dice. For each 5 or 6 rolled, the damage is reduced by 1 point. »
            // Mot pour mot la carte héros du même nom (doc 16 §3bis) : c'est le
            // même sort, vu de l'autre bord de la table, donc le même lecteur.
            ['nom' => 'Boule de Flammes', 'palier' => 'base', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'degats_fixes' => 2,
                    'resistance' => Mot::RESISTANCE_DES_ROUGES,
                    'des_resistance' => 2,
                    'defense_applicable' => false,
                    'type_degat' => 'feu',
                ]],

            // « a roomful of fire […] 3 Body Points on all heroes AND MONSTERS
            // in the same room with the spellcaster. The spellcaster is
            // unaffected. […] Not used in corridors. »
            // ⚠ « Fire OR CHAOS FIRE spells » : l'Anneau de Feu protège aussi
            // des sorts du MJ, pas seulement de ceux des héros.
            ['nom' => 'Tempête de feu', 'palier' => 'sous_boss', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'zone' => Mot::ZONE_SALLE,
                    'degats_fixes' => 3,
                    'resistance' => Mot::RESISTANCE_DES_ROUGES,
                    'des_resistance' => 2,
                    'defense_applicable' => false,
                    'type_degat' => 'feu',
                    'touche_monstres' => true,
                    'epargne_lanceur' => true,
                    'hors_couloir' => true,
                ]],

            // « horizontal, vertical, or diagonal […] until it strikes a wall or
            // closed door. 2 Body Points on all heroes or monsters in its path. »
            // Le lecteur existait déjà : `App\Partie\Rayon`, écrit pour le
            // parchemin d'Éclair, dont la carte porte la même phrase.
            ['nom' => 'Éclair de Chaos', 'palier' => 'sous_boss', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'zone' => Mot::ZONE_RAYON,
                    'degats_fixes' => 2,
                    'defense_applicable' => false,
                    'touche_monstres' => true,
                ]],

            // « 1 Body Point to any one hero or monster adjacent to the
            // spellcaster (though NOT DIAGONALLY adjacent). The victim cannot
            // defend against the attack. »
            // ⚠ Première SOURCE de `TypeDegat::FROID`, déclaré sans source
            // jusqu'ici. Conséquence assumée : le Bracelet de Glace et l'Anneau
            // de Chaleur deviennent portables — ils ne le sont pas encore.
            ['nom' => 'Morsure de Froid', 'palier' => 'base', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'zone' => Mot::ZONE_CONTACT,
                    'degats_fixes' => 1,
                    'defense_applicable' => false,
                    'type_degat' => 'froid',
                ]],

            // « Roll 1 red die. For each monster adjacent to the caster THAT CAN
            // CAST THIS SPELL, add 1 point to the die total. On 1-3 the hero
            // resists ; on 4-5 loses 1 Body Point ; on 6+ loses 2. »
            // La carte la plus portée des extensions : sept adversaires nommés
            // la connaissent, et le Specter la lance à volonté (doc 18).
            ['nom' => 'Canaliser l\'Effroi', 'palier' => 'base', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'resistance' => Mot::RESISTANCE_PALIERS_D6,
                    'paliers' => ['3' => 0, '5' => 1, '6' => 2],
                    'bonus_lanceurs_adjacents' => true,
                    'defense_applicable' => false,
                ]],

            // « a blizzard of ice […] an area 2 squares wide by 2 squares long.
            // Each monster and hero in that area is attacked separately by the
            // spellcaster with 3 combat dice. There is no chance to defend.
            // Cannot be used in corridors. »
            ['nom' => 'Tempête de Glace', 'palier' => 'boss', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'zone' => Mot::ZONE_CARRE_2X2,
                    'des_degats' => 3,
                    'defense_applicable' => false,
                    'type_degat' => 'froid',
                    'touche_monstres' => true,
                    'hors_couloir' => true,
                ]],

            // ============================================================
            // MIND (plan glace, phase 2 — 2026-09-06)
            // ============================================================

            // « The hero rolls 1 combat die per Mind Point they possess before
            // the attack. If at least one white shield is rolled, they have
            // 1 Mind Point remaining. If not, Mind is reduced to zero and the
            // hero enters a state of shock. »
            // ⚠ Le nombre de dés est la JAUGE `pv_mind` (les points POSSÉDÉS),
            // jamais l'attribut : `MoteurDread::sortDreadMind()` le lit sur le
            // personnage, pas sur `attribut_mind`. Résolution dédiée — ni
            // `des_degats`/`degats_fixes` (le sort ne blesse pas un nombre
            // fixe, il FIXE le résultat à 1 ou 0), ni jet de défense.
            // ⚠ « entre en état de choc » délègue à une section du livret
            // Frozen Horror que nous n'avons pas — DETTE NOMMÉE, non portée.
            // Ce qui EST porté : Mind à zéro fait tomber le héros, exactement
            // comme à 0 Body (arbitrage de René, 2026-09-06, phase 1 du plan) —
            // `MoteurDegats::infligerMindAHeros()` le fait déjà, sans qu'il y
            // ait besoin d'inventer l'« état de choc » pour que le sort ait un
            // effet réel en jeu.
            ['nom' => "Gel de l'Esprit", 'palier' => 'boss', 'type' => Mot::TYPE_MIND,
                'effet' => [
                    'resistance' => Mot::RESISTANCE_BOUCLIER_BLANC_PAR_MIND,
                ]],

            // ============================================================
            // CONTRÔLE
            // ============================================================

            // « unable to move, attack, or defend themself. The spell can be
            // broken immediately or on a future turn by the hero rolling 1 red
            // die for each of their Mind Points. If a 6 is rolled, the spell is
            // broken. »
            // ⚠ Le sort PREND TOUJOURS. Notre version en faisait un `jet_mind`
            // au lancer : il pouvait rater d'emblée et, une fois posé, ne se
            // levait jamais tout seul. C'est mot pour mot la règle donnée au
            // Sommeil des héros le 2026-09-02, côté monstres.
            ['nom' => 'Sommeil', 'palier' => 'sous_boss', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'condition_appliquee' => 'Endormi',
                    'resistance' => Mot::RESISTANCE_RUPTURE_PAR_MIND,
                ]],

            // « so fearful that they may ONLY USE 1 ATTACK DIE. »
            // ⚠ Un PLAFOND, pas un malus : *Apeuré* portait `malus_des_attaque`,
            // ce qui ne coûtait qu'un dé au barbare qui en lance cinq.
            ['nom' => 'Frayeur', 'palier' => 'sous_boss', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'condition_appliquee' => 'Apeuré',
                    'resistance' => Mot::RESISTANCE_RUPTURE_PAR_MIND,
                ]],

            // « a small whirlwind that envelops one hero of your choice. That
            // hero then misses their next turn. » Aucun jet — comme sa jumelle
            // côté héros (doc 16 §3bis), à qui nous avions ajouté un `jet_mind`
            // que sa carte n'a pas.
            ['nom' => 'Tourmente', 'palier' => 'base', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'condition_appliquee' => 'Étourdi',
                    'resistance' => Mot::RESISTANCE_AUCUNE,
                ]],

            // « puts any one hero under Zargon's control […] Zargon, on their
            // turn, can move the hero as a monster and attack other heroes. »
            // ⚠ Plus de `duree_tours` : la carte ne donne aucune durée, elle
            // donne une condition de rupture.
            ['nom' => 'Commandement', 'palier' => 'boss', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'condition_appliquee' => 'Commandé',
                    'resistance' => Mot::RESISTANCE_RUPTURE_PAR_MIND,
                ]],

            // « paralyzes ALL heroes located in the same ROOM OR CORRIDOR […]
            // each victim rolling 1 red die for each of their Mind Points. »
            // *Paralysé* portait déjà les trois interdits de la carte.
            ['nom' => 'Nuée d\'Effroi', 'palier' => 'boss', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'zone' => Mot::ZONE_SALLE_OU_COULOIR,
                    'condition_appliquee' => 'Paralysé',
                    'resistance' => Mot::RESISTANCE_RUPTURE_PAR_MIND,
                ]],

            // « paralyzes one hero within the spellcaster's line of sight. This
            // hero cannot move or attack. THE HERO DEFENDS WITH 1 COMBAT DIE. »
            // ⚠ Condition nouvelle, et non *Paralysé* : toute la différence tient
            // en un mot — le paralysé ne défend pas du tout, celui-ci défend à 1.
            ['nom' => 'Choc Mental', 'palier' => 'boss', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'condition_appliquee' => 'Esprit brisé',
                    'resistance' => Mot::RESISTANCE_RUPTURE_PAR_MIND,
                ]],

            // « eerie tongues of ghostly light. ALL MONSTERS ROLL ONE ADDITIONAL
            // ATTACK DIE when attacking the affected hero. […] rolling 1 red die.
            // On a roll of 5 or 6, the spell is broken. »
            // ⚠ Seule carte dont la rupture ne se joue PAS sur le Mind.
            ['nom' => 'Feux de l\'Effroi', 'palier' => 'base', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'condition_appliquee' => 'Désigné',
                    'resistance' => Mot::RESISTANCE_RUPTURE_5_6,
                ]],

            // « ensnare them with vines. […] They must roll 1 combat die. If they
            // roll a SKULL, they suffer 1 Body Point of damage and are
            // restrained, unable to move from that square. The targeted hero or
            // another adjacent hero can spend an action to destroy the vines. »
            // ⚠ Elle donne son premier producteur à *Immobilisé*, et son premier
            // lecteur au `fin: liberation` que la condition porte, inerte, depuis
            // la création de la table.
            ['nom' => 'Étreinte des Ronces', 'palier' => 'base', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'condition_appliquee' => 'Immobilisé',
                    'resistance' => Mot::RESISTANCE_DES_COMBAT_CRANE,
                    'degats_fixes' => 1,
                ]],

            // ============================================================
            // INVOCATION
            // ============================================================

            // « Roll 1 red die : on 1-2 = 4 skeletons ; on 3-4 = 3 skeletons,
            // 2 zombies ; on 5-6 = 2 zombies, 2 mummies. »
            // ⚠ Notre version invoquait deux squelettes, point. La table du dé
            // est ce qui sépare un renfort d'une bascule de combat.
            ['nom' => 'Invocation de morts-vivants', 'palier' => 'boss', 'type' => Mot::TYPE_INVOCATION,
                'effet' => [
                    'table_d6' => [
                        ['jusqu_a' => 2, 'invoque' => ['Squelette' => 4]],
                        ['jusqu_a' => 4, 'invoque' => ['Squelette' => 3, 'Zombie' => 2]],
                        ['jusqu_a' => 6, 'invoque' => ['Zombie' => 2, 'Momie' => 2]],
                    ],
                ]],

            // « on 1, 2 or 3 = 4 orcs ; on 4 or 5 = 5 orcs ; on 6 = 6 orcs. »
            ['nom' => 'Invocation d\'orques', 'palier' => 'boss', 'type' => Mot::TYPE_INVOCATION,
                'effet' => [
                    'table_d6' => [
                        ['jusqu_a' => 3, 'invoque' => ['Orque' => 4]],
                        ['jusqu_a' => 5, 'invoque' => ['Orque' => 5]],
                        ['jusqu_a' => 6, 'invoque' => ['Orque' => 6]],
                    ],
                ]],

            // VARIANTE GOBELINE de *Summon Orcs* — Gruulob, Sorcier Gobelin Corrompu
            // (Jungles of Delthrak, note C, livret F9907 p. 27) : « Summon Orcs*
            // (*Summons the same number of Goblins instead) ». Même table que
            // l'Invocation d'orques ci-dessus, donc même nombre de sbires au même
            // jet, mais des GOBELINS. Une ligne de catalogue de plus — comme *Rust*
            // a deux lignes, « Rouille » et « Corrosion » — et jamais un paramètre
            // de l'orque : les deux créatures n'ont pas les mêmes chiffres (Gobelin
            // 10/2/1/1/1, Orque 8/3/2/1/2), et le registre `config/cartes.php` range
            // celle-ci sous sa propre source. Palier boss, comme la version orque.
            ['nom' => 'Invocation de gobelins', 'palier' => 'boss', 'type' => Mot::TYPE_INVOCATION,
                'effet' => [
                    'table_d6' => [
                        ['jusqu_a' => 3, 'invoque' => ['Gobelin' => 4]],
                        ['jusqu_a' => 5, 'invoque' => ['Gobelin' => 5]],
                        ['jusqu_a' => 6, 'invoque' => ['Gobelin' => 6]],
                    ],
                ]],

            // « a number of giant wolves […] 1-2 = 1 ; 3-4 = 2 ; 5-6 = 3. »
            // Le *Loup géant* est au bestiaire depuis le portage des extensions
            // (doc 18 p. 693) — sous-boss, d'où un renfort peu nombreux mais dur.
            ['nom' => 'Invocation de loups', 'palier' => 'sous_boss', 'type' => Mot::TYPE_INVOCATION,
                'effet' => [
                    'table_d6' => [
                        ['jusqu_a' => 2, 'invoque' => ['Loup géant' => 1]],
                        ['jusqu_a' => 4, 'invoque' => ['Loup géant' => 2]],
                        ['jusqu_a' => 6, 'invoque' => ['Loup géant' => 3]],
                    ],
                ]],

            // « on 1 or 2 = 1 Specter ; on 3, 4, or 5 = 2 Specters ; on 6 = 3. »
            ['nom' => 'Invocation de spectres', 'palier' => 'boss', 'type' => Mot::TYPE_INVOCATION,
                'effet' => [
                    'table_d6' => [
                        ['jusqu_a' => 2, 'invoque' => ['Spectre' => 1]],
                        ['jusqu_a' => 5, 'invoque' => ['Spectre' => 2]],
                        ['jusqu_a' => 6, 'invoque' => ['Spectre' => 3]],
                    ],
                ]],

            // « reanimate ALL DEFEATED skeletons, zombies, or mummies IN THE SAME
            // ROOM as the spellcaster. These monsters rise from the floor, with
            // all lost Body Points restored, and attack the heroes again. »
            // Nos instances vaincues restent en base (`etat != actif`) : il n'y
            // avait rien à inventer, seulement à les relever.
            ['nom' => 'Réanimation', 'palier' => 'boss', 'type' => Mot::TYPE_INVOCATION,
                'effet' => [
                    'zone' => Mot::ZONE_SALLE,
                    'reanime' => ['Squelette', 'Zombie', 'Momie'],
                ]],

            // ============================================================
            // SOIN
            // ============================================================

            // « restores up to 3 lost Body Points to the spellcaster or any one
            // monster. »
            ['nom' => 'Apaisement', 'palier' => 'sous_boss', 'type' => Mot::TYPE_SOIN,
                'effet' => ['soin' => 3]],

            // « may be cast only on monsters. It restores up to 6 lost Body
            // Points to either the spellcaster or any monster WITHIN THE
            // SPELLCASTER'S LINE OF SIGHT. »
            ['nom' => 'Restauration de l\'Effroi', 'palier' => 'boss', 'type' => Mot::TYPE_SOIN,
                'effet' => ['soin' => 6, 'ligne_de_vue' => true]],

            // ============================================================
            // DESTRUCTION
            // ============================================================

            // « This spell causes any one metal sword or helmet to become so
            // thin, brittle, and useless that it can never be used again. NOT
            // EFFECTIVE AGAINST ARTIFACTS. »
            //
            // ⚠ Portée le 2026-09-04 sur arbitrage de René (« c'est correct
            // qu'un joueur puisse perdre un objet »). C'est le seul sort du
            // paquet dont l'effet SURVIT À LA QUÊTE : la pièce quitte
            // l'inventaire pour de bon.
            //
            // ⚠ Divergence assumée, une seule et elle est petite : la carte
            // énumère « sword or helmet », notre catalogue n'a aucune notion
            // d'« épée », et une hache de bataille rouille exactement comme une
            // épée longue. On lit donc « une pièce de MÉTAL en main ou sur la
            // tête » — inventer une colonne pour exclure la hachette aurait été
            // une donnée à usage purement cosmétique. Le Bâton, la Baguette et
            // l'Arbalète sont de bois : ils sont immunisés sans qu'on ait eu à
            // l'écrire.
            ['nom' => 'Rouille', 'palier' => 'boss', 'type' => Mot::TYPE_DESTRUCTION,
                'effet' => [
                    'detruit' => [
                        'metallique' => true,
                        'emplacements' => ['arme_principale', 'arme_secondaire', 'casque'],
                        // « Not effective against artifacts » : la carte le dit,
                        // la donnée le dit aussi.
                        'epargne_artefacts' => true,
                    ],
                ]],

            // ============================================================
            // TERRAIN / DÉPLACEMENT (plan glace, phase 2 — 2026-09-06)
            // ============================================================

            // « Zargon may place up to 4 spaces of solid ice on the board.
            // These spaces block movement, but not line of sight. Each space
            // of ice lasts as long as the spellcaster can see it, or until it
            // has taken a total of 5 skulls from attacks made against it. »
            // ⚠ « bloque le déplacement, pas la vue » est MOT POUR MOT la
            // séparation `bloque_mouvement`/`bloque_vue` du 2026-08-05 — mais
            // la pose se fait EN COURS DE QUÊTE, sur sa propre couche
            // `carte.grille['glace']` (précédent : `chausse_trappes`), jamais
            // sur le catalogue `terrains` (qui est posé une fois, à
            // l'assemblage, et dont une entrée figurerait à tort dans le pool
            // de tirage statique de `AssembleurCarte::placerTerrains()`).
            // `MoteurDread::planMurDeGlace()` choisit les cases ET vérifie
            // qu'aucune n'isole une case aujourd'hui accessible (invariant dur
            // du projet) ; `endommagerMurDeGlace()` tient le compteur de
            // crânes ; `entretienMurDeGlace()`, rejoué à chaque tour du
            // lanceur, retire les cases qu'il ne voit plus.
            ['nom' => 'Mur de Glace', 'palier' => 'boss', 'type' => Mot::TYPE_TERRAIN,
                'effet' => [
                    'cases_max' => 4,
                    'cranes_rupture' => 5,
                ]],

            // « The spellcaster skates 12 spaces this turn, moving through
            // spaces occupied by heroes and monsters. This effect lasts for
            // one turn. »
            // ⚠ MODE DE DÉPLACEMENT POUR UN MONSTRE — `franchit_figures`
            // n'existait que côté héros (Voile de Brume) ; le déplacement des
            // monstres est piloté par le moteur, sans buff qui le module.
            // `Grille::autoriserFranchissementFigures()` porte donc SA propre
            // implémentation (distincte d'`autoriserFranchissement()`, qui
            // lève AUSSI le mobilier — la carte ne parle que des figures).
            // `MoteurDread::planPatinage()` cible le héros en vue le plus
            // proche PAR UN CHEMIN qui traverse les figures, plafonné à
            // `cases`, puis recule jusqu'à la dernière case RÉELLEMENT libre
            // (même raisonnement que `ResolveurTour::derniereCaseOuSArreter()` :
            // traverser n'est pas s'arrêter).
            ['nom' => 'Patinage', 'palier' => 'boss', 'type' => Mot::TYPE_DEPLACEMENT,
                'effet' => [
                    'cases' => 12,
                ]],

            // ============================================================
            // ÉVASION
            // ============================================================

            // « disappear and instantly teleport to a secret destination known
            // only to Zargon. This safe place is marked on the quest map. »
            // ⚠ Nos donjons sont procéduraux et ne portent aucun refuge marqué :
            // la destination est la case libre la plus ÉLOIGNÉE des héros. Même
            // intention, seule lecture possible ici. Le nom français historique
            // est conservé — `instances_monstres.fuite_dread_utilisee` le porte.
            ['nom' => 'Fuite', 'palier' => 'boss', 'type' => Mot::TYPE_FUITE,
                'effet' => ['teleportation' => MoteurDread::FUITE_CASE_ELOIGNEE]],

            // ============================================================
            // WIZARDS OF MORCAR — les trois Sorciers du Dread de la vague 2A
            // (Storm Master, High Mage, Necromancer — 2026-10-08)
            // ============================================================
            //
            // Source : les 18 cartes de `drive/wizards-of-morcar/rendu/{storm_master,
            // high_mage,necromancer}_spells_p*.png`, transcrites en doc 18
            // §« Wizards of Morcar — cartes TRANSCRITES », et le livret G1504 p. 10 :
            // « Each Sorcerer may cast one spell per turn instead of attacking. Each
            // spell may only be used once per quest. »
            //
            // ⚠ PALIER `base` pour tous : aucune carte ne porte de rang, et le Sorcier
            // reçoit « a full set of six spells » QUEL QUE SOIT notre tier — un palier
            // minimum plus haut aurait amputé en silence le répertoire d'un Sorcier
            // qu'on rangerait demain en sous-boss. La rareté n'est plus portée par le
            // palier mais par `sorts_uniques` (chaque sort une seule fois par quête).
            //
            // ⚠ DEUX cartes ont un doublon exact déjà au catalogue et ne sont donc PAS
            // reseedées : *Fear* (Necromancer) = « Frayeur » (même plafond d'un dé
            // d'attaque, même rupture 1 d6 par point de Mind / 6), *Escape* (High Mage) =
            // « Fuite » (« teleport to a secret destination known only to Zargon »).

            // -- Storm Master (Boroush) ---------------------------------

            // « channels the power of a lightning storm in a straight, orthogonal line
            // of 6 squares. Anyone hit must defend normally against 3 combat dice.
            // Resolve each attack separately. » + carton : un mur magique l'annule.
            ['nom' => 'Foudroiement', 'palier' => 'base', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'zone' => Mot::ZONE_RAYON,
                    'rayon_orthogonal' => true,
                    'portee_rayon' => 6,
                    'des_degats' => 3,
                    'defense_applicable' => true,
                    'touche_monstres' => true,
                ]],

            // « splits the ground asunder in a straight, orthogonal line of 6 squares.
            // […] All those caught will suffer 1 Body Point of damage as if they had
            // fallen into a pit trap. » 1 PV, aucun jet (la fosse du catalogue : 1 PV).
            ['nom' => 'Tremblement de terre', 'palier' => 'base', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'zone' => Mot::ZONE_RAYON,
                    'rayon_orthogonal' => true,
                    'portee_rayon' => 6,
                    'degats_fixes' => 1,
                    'defense_applicable' => false,
                    'touche_monstres' => true,
                ]],

            // « a magical wall of ice which covers two squares unoccupied by figures.
            // The wall has 1 Body Point and rolls 6 Defend dice. »
            ['nom' => 'Muraille de glace', 'palier' => 'base', 'type' => Mot::TYPE_MUR_MAGIQUE,
                'effet' => ['pose_mur_magique' => 'Mur de Glace']],

            // « This spell must be targeted at one hero. The hero loses one piece of
            // equipment chosen at random. » Toute pièce PORTÉE, artefact compris :
            // la carte n'en exempte aucun (la Rouille, elle, l'écrit).
            ['nom' => 'Vent voleur', 'palier' => 'base', 'type' => Mot::TYPE_DESTRUCTION,
                'effet' => [
                    'detruit' => [
                        'emplacements' => ['arme_principale', 'arme_secondaire', 'casque', 'armure', 'talisman', 'bottes'],
                        'au_hasard' => true,
                    ],
                ]],

            // « cast […] on a character they can see who is in a straight line in
            // front of them. That character is then forced back in a straight line
            // of squares until they hit a wall, another figure, fall down a pit trap
            // or trigger another trap. »
            ['nom' => 'Ouragan', 'palier' => 'base', 'type' => Mot::TYPE_REPOUSSEMENT,
                'effet' => ['repousse' => true]],

            // « fills a room with blinding sleet. Characters in that room may not
            // move, make ranged attacks or cast spells until the start of Zargon's
            // next turn. » La salle du lanceur (« a room » : la carte ne dit pas
            // laquelle ; une salle, jamais un couloir). Aucun jet.
            ['nom' => 'Grésil aveuglant', 'palier' => 'base', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'zone' => Mot::ZONE_SALLE,
                    'hors_couloir' => true,
                    'condition_appliquee' => 'Grésil aveuglant',
                    'resistance' => Mot::RESISTANCE_AUCUNE,
                ]],

            // -- High Mage (Zanrath) ------------------------------------

            // « a magical wall of flame which covers two squares not occupied by
            // figures within the Spellcaster's line of sight. »
            ['nom' => 'Muraille de flammes', 'palier' => 'base', 'type' => Mot::TYPE_MUR_MAGIQUE,
                'effet' => ['pose_mur_magique' => 'Mur de Feu', 'ligne_de_vue' => true]],

            // « fire magical tendrils from the Spellcaster's fingertips. They entangle
            // one target who may not move or attack until the tendrils are destroyed.
            // Tendrils have 1 Body Point and roll 4 Defend dice. »
            ['nom' => 'Liens magiques', 'palier' => 'base', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'condition_appliquee' => 'Ligoté',
                    'resistance' => Mot::RESISTANCE_AUCUNE,
                ]],

            // « any ONE piece of METAL equipment to decay to the point of uselessness.
            // It is not effective against artifacts. » VARIANTE de la Rouille (carte
            // de base : « sword or helmet ») : ici toute pièce de métal, armure
            // comprise. Même lecteur (`detruit`), emplacements élargis.
            ['nom' => 'Corrosion', 'palier' => 'base', 'type' => Mot::TYPE_DESTRUCTION,
                'effet' => [
                    'detruit' => [
                        'metallique' => true,
                        'emplacements' => ['arme_principale', 'arme_secondaire', 'casque', 'armure'],
                        'epargne_artefacts' => true,
                    ],
                ]],

            // « on one figure to affect it with a frightening terror. Zargon will move
            // this figure on its next turn. The affected figure may not attack or cast
            // spells. » Aucun jet de résistance sur la carte.
            ['nom' => 'Possession', 'palier' => 'base', 'type' => Mot::TYPE_CONTROLE,
                'effet' => [
                    'condition_appliquee' => 'Possédé',
                    'resistance' => Mot::RESISTANCE_AUCUNE,
                ]],

            // « pick one spell caster and force them to discard 1 spell card at
            // random. The spell is removed from play for the duration of the quest. »
            ['nom' => 'Désapprentissage', 'palier' => 'base', 'type' => Mot::TYPE_OUBLI,
                'effet' => ['oubli' => true]],

            // -- Necromancer (Fanrax) -----------------------------------

            // « summons a mummy. Place a mummy in any square adjacent to the Spellcaster. »
            ['nom' => 'Invocation de momie', 'palier' => 'base', 'type' => Mot::TYPE_INVOCATION,
                'effet' => ['invoque' => ['Momie' => 1]]],

            // « hurls a magical skull at any opponent they can see. The skull explodes
            // into a fireball. Roll 2 Attack dice. The target may defend normally. »
            ['nom' => 'Crânes maudits', 'palier' => 'base', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'des_degats' => 2,
                    'defense_applicable' => true,
                    'type_degat' => 'feu',
                ]],

            // « Cast this spell after a monster has been killed (no action required).
            // The monster is replaced with a skeleton which can move and attack
            // immediately. » Sort RÉACTIF : voir `MoteurDread::reactionsALaMort()`.
            ['nom' => 'Relève des morts', 'palier' => 'base', 'type' => Mot::TYPE_REACTION,
                'effet' => ['reaction' => 'mort_de_monstre']],

            // « Hurl this spell at one target within the Spellcaster's line of sight to
            // cause them to instantly lose 1 Body point. » Ni dé ni parade.
            ['nom' => 'Trait de mort', 'palier' => 'base', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'degats_fixes' => 1,
                    'defense_applicable' => false,
                ]],

            // « summons up to 2 skeletons that appear immediately anywhere within sight
            // of the Spellcaster. »
            ['nom' => 'Appel des squelettes', 'palier' => 'base', 'type' => Mot::TYPE_INVOCATION,
                'effet' => ['invoque' => ['Squelette' => 2], 'invoque_en_vue' => true]],

            // ============================================================
            // WIZARDS OF MORCAR — ORC WARCASTER (Nyashak) ET ARTIFICER (la
            // Gardienne), vague 2B, 2026-10-08. Cartes : reference/18 §1,
            // rendus `warcaster_spells_p*` et `artificer_spells_p*`. Les
            // paliers sont NÔTRES (aucune carte ne porte de rang) : mesurés
            // sur le `cout` de ce que le sort pose ou frappe — les renforts de
            // 4 points ou moins `base`, une créature de 6-7 points ou un effet
            // qui retourne la salle `sous_boss`/`boss`. Les deux sorciers sont de
            // tier `boss` : aucun filtre ne les prive d'un de leurs six sorts.
            // ============================================================

            // « places up to 2 Orcs on spaces they can see. […] They may move and
            // attack immediately unless they have already done so this turn. »
            // ⚠ VARIANTE : « taken from those not in play OR FROM ANYWHERE ON THE
            // BOARD » — nous n'avons pas de réserve de figurines, les orques
            // arrivent donc toujours NEUFS ; reprendre un orque du plateau pour le
            // déplacer n'est pas porté (il serait équivalent ou plus faible).
            ['nom' => 'Appel des orques', 'palier' => 'base', 'type' => Mot::TYPE_INVOCATION,
                'effet' => ['invoque' => ['Orque' => 2], 'invoque_en_vue' => true, 'activation_immediate' => true]],

            // « places up to 4 Goblins […] » — même carte, quatre Gobelins.
            ['nom' => 'Appel des gobelins', 'palier' => 'base', 'type' => Mot::TYPE_INVOCATION,
                'effet' => ['invoque' => ['Gobelin' => 4], 'invoque_en_vue' => true, 'activation_immediate' => true]],

            // « send an invisible spirit to attack any one character ON THE BOARD.
            // The spirit attacks once with 4 Attack dice. The character attacked
            // defends as normal. »
            ['nom' => 'Esprit de vengeance', 'palier' => 'boss', 'type' => Mot::TYPE_DEGATS,
                'effet' => [
                    'des_degats' => 4,
                    'defense_applicable' => true,
                    'sans_ligne_de_vue' => true,
                ]],

            // « the spellcaster and all Orcs in the same room roll 1 extra combat
            // die in defense until the start of spellcaster's next turn. May only
            // be cast in a room. »
            ['nom' => 'Bouclier de protection', 'palier' => 'sous_boss', 'type' => Mot::TYPE_RENFORT,
                'effet' => [
                    'buff_faction' => ['faction' => 'Orque', 'defense' => 1, 'inclut_lanceur' => true, 'duree' => 'prochain_tour_lanceur'],
                    'hors_couloir' => true,
                ]],

            // « all Orcs in the same room as the Spellcaster roll an extra Attack
            // die for this turn only. May only be cast in a room. »
            ['nom' => 'Lames aiguisées', 'palier' => 'sous_boss', 'type' => Mot::TYPE_RENFORT,
                'effet' => [
                    'buff_faction' => ['faction' => 'Orque', 'attaque' => 1, 'inclut_lanceur' => false, 'duree' => 'ce_round'],
                    'hors_couloir' => true,
                ]],

            // « chooses an Orc they can see […]. The Orc moves and attacks twice on
            // this turn only. This spell may not be cast on an Orc that has already
            // moved or attacked. »
            ['nom' => 'Orque berserker', 'palier' => 'boss', 'type' => Mot::TYPE_RENFORT,
                'effet' => ['double_tour' => ['faction' => 'Orque']]],

            // « may immediately cast this spell in response to being reduced to 0
            // body points. Roll 1 red die. 1-2 Ignored. 3-5 Place a Gargoyle in the
            // spellcaster's space. 6 The air chills. Each hero in the same room or
            // corridor loses 2 body points. »
            ['nom' => 'Implorer les puissances du Dread', 'palier' => 'boss', 'type' => Mot::TYPE_REACTION,
                'effet' => [
                    'reaction' => 'zero_pv_du_lanceur',
                    'zone' => Mot::ZONE_SALLE_OU_COULOIR,
                    'sur_zero_pv' => [
                        ['jusqu_a' => 2, 'issue' => 'ignoree'],
                        ['jusqu_a' => 5, 'issue' => 'invoque', 'invoque' => ['Gargouille' => 1]],
                        ['jusqu_a' => 6, 'issue' => 'froid', 'pv_perdus' => 2],
                    ],
                ]],

            // « keeps this spell face up and places 3 shadow tokens on it. When the
            // Spellcaster takes any amount of damage, remove 1 shadow token
            // instead. The spell is broken after the last shadow token is removed. »
            ['nom' => 'Parchemins de Morcar', 'palier' => 'sous_boss', 'type' => Mot::TYPE_AMELIORATION,
                'effet' => ['jetons_ombre' => 3]],

            // « keeps this spell face up and may roll 2 extra combat dice when
            // attacking. If an attack from the spellcaster does not result in the
            // enemy losing at least 1 Body Point, the spell is broken. » — c'est ce
            // sort qui produit la ligne « Attack 4+2* » du tableau des monstres.
            ['nom' => 'Marteau de la Ruine', 'palier' => 'sous_boss', 'type' => Mot::TYPE_AMELIORATION,
                'effet' => ['bonus_attaque' => 2, 'se_brise_sans_degat' => true]],

            // « rolls 1 red die for each other figure in the same room or corridor.
            // If the roll is equal to or greater than a target's Mind Points, they
            // lose 1 Body Point and the Spellcaster recovers 1 Body Point. »
            ['nom' => 'Drain de vie', 'palier' => 'boss', 'type' => Mot::TYPE_DRAIN,
                'effet' => [
                    'zone' => Mot::ZONE_SALLE_OU_COULOIR,
                    'drain' => ['pv_perdus' => 1, 'pv_rendus' => 1],
                ]],

            // « conjures up a fearsome creature of stone […]. The Spellcaster places a
            // Golem on a space they can see. » Aucune activation immédiate : la carte
            // n'en dit rien, le Golem joue à la phase suivante.
            ['nom' => 'Invocation de golem', 'palier' => 'sous_boss', 'type' => Mot::TYPE_INVOCATION,
                'effet' => ['invoque' => ['Golem' => 1], 'invoque_en_vue' => true]],

            // « Once a normal object, now a creature of nightmares. The Spellcaster
            // places a Dreadshifter on a space they can see. » Posé en créature,
            // jamais déguisé : l'embuscade est celle du coffre de départ.
            ['nom' => 'Appel du Dreadshifter', 'palier' => 'sous_boss', 'type' => Mot::TYPE_INVOCATION,
                'effet' => ['invoque' => ['Dreadshifter' => 1], 'invoque_en_vue' => true]],
        ];

        foreach ($sorts as $sort) {
            SortDread::updateOrCreate(['nom' => $sort['nom']], $sort);
        }
    }
}
