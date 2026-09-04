<?php

namespace Database\Seeders;

use App\Engine\MotsClesSortDread as Mot;
use App\Models\SortDread;
use App\Partie\MoteurDread;
use Illuminate\Database\Seeder;

/**
 * LES 22 SORTS DE DREAD PORTÉS, un par carte officielle.
 *
 * Source : `dread_spells.pdf` — 29 sorts photographiés par René le 2026-09-04,
 * transcrits carte par carte en **doc 09 §4bis**. Sept ne sont pas portés et
 * sont recensés dans `config/cartes.php` (section `dread`) avec, chacune, la
 * mécanique qui lui manque : *Mind Freeze*, *Werewolf's Curse*, *Rust*,
 * *Ice Wall*, *Skate*, *Dispel*, *Mirror Magic*.
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
        ];

        foreach ($sorts as $sort) {
            SortDread::updateOrCreate(['nom' => $sort['nom']], $sort);
        }
    }
}
