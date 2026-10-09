<?php

namespace Database\Seeders;

use App\Models\Sort;
use Illuminate\Database\Seeder;

/**
 * Les 12 sorts héros (doc 02 §7) — 4 éléments × 3 sorts — plus les sorts
 * `parchemin` (aucune école, n'existent qu'en parchemin, doc 16 §3bis).
 * difficulte_parchemin = succès de Mind requis pour un non-lanceur (S1).
 */
class SortSeeder extends Seeder
{
    public function run(): void
    {
        $sorts = [
            // Feu — offensif
            // ⚠ Les deux sorts de FEU suivent leur carte depuis le 2026-09-02
            // (arbitrage de René) : dégâts FIXES, que la cible réduit en lançant
            // des d6 BRUTS — chaque 5 ou 6 annule 1 point. « It inflicts 2 Body
            // Points of damage. The monster then rolls 2 red dice. For each 5 or
            // 6 rolled, the damage is reduced by 1 point. » (doc 16 §3bis)
            //
            // Nous lancions jusque-là des dés de COMBAT avec défense normale :
            // même fourchette (0-2), probabilités différentes, et surtout un
            // hasard placé du mauvais côté — c'est la cible qui résiste, pas le
            // lanceur qui vise. `defense_applicable: false` parce que les dés
            // rouges REMPLACENT la parade, ils ne s'y ajoutent pas.
            ['element' => 'feu', 'nom' => 'Boule de Feu', 'type' => 'degats', 'difficulte_parchemin' => 3,
                'effet' => ['portee' => 'distance', 'degats_fixes' => 2, 'resistance' => 'des_rouges',
                    'des_resistance' => 2, 'defense_applicable' => false, 'type_degat' => 'feu']],
            ['element' => 'feu', 'nom' => 'Courage', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                // Carte officielle (doc 16 §3bis) : « The next time that hero
                // attacks, they may roll 2 extra combat dice. The spell is
                // broken the moment a monster is no longer in the hero's line
                // of sight. » ⚠ DEUX déclencheurs, pas un — la seconde moitié
                // manquait, alors que son mot-clé et son lecteur existaient
                // déjà (`plus_de_monstre_en_vue`, posé pour les potions du
                // barbare). Le buff survivait donc à la fin du combat et
                // attendait tranquillement la prochaine bagarre.
                'effet' => ['cible' => 'heros', 'bonus_des_attaque' => 2,
                    'duree' => ['prochaine_attaque', 'plus_de_monstre_en_vue'],
                    'condition_appliquee' => 'Renforcé']],
            // « It inflicts 1 Body Point of damage, unless the monster can
            // immediately roll a 5 or 6 using 1 red die. » Même mécanique que la
            // Boule de Feu, à l'échelle 1 : 1 point, 1 dé.
            ['element' => 'feu', 'nom' => 'Trait de Feu', 'type' => 'degats', 'difficulte_parchemin' => 1,
                'effet' => ['portee' => 'distance', 'degats_fixes' => 1, 'resistance' => 'des_rouges',
                    'des_resistance' => 1, 'defense_applicable' => false, 'type_degat' => 'feu']],

            // Eau — contrôle / soin
            // ⚠ Le sort PREND TOUJOURS, et c'est sa POURSUITE qui est contestée
            // (carte doc 16 §3bis, arbitrage de René 2026-09-02) : le monstre
            // tente de rompre sur-le-champ, puis à chacun de ses tours, en
            // lançant 1 d6 par point de Mind — un seul 6 le réveille.
            //
            // Nous faisions l'inverse : un `jet_mind` unique AU LANCER pouvait
            // faire échouer le sort d'emblée, et une fois endormi le monstre ne
            // se réveillait plus jamais autrement qu'en étant attaqué. Les deux
            // moitiés étaient fausses.
            //
            // L'exclusion « may not be used against mummies, zombies, or
            // skeletons » reste obtenue par le Mind 0 de ces trois-là, comme
            // pour tout sort mental — inchangé.
            ['element' => 'eau', 'nom' => 'Sommeil', 'type' => 'mental', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'monstre', 'resistance' => 'rupture_6_par_mind',
                    'condition_appliquee' => 'Endormi', 'fin' => 'reveil_ou_attaque']],
            ['element' => 'eau', 'nom' => 'Voile de Brume', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                // Carte officielle (doc 16 §3bis) : « On the hero's next move,
                // they may move unseen through spaces that are occupied by
                // monsters. »
                //
                // ⚠ Ce n'était pas une nuance mais UN AUTRE SORT : nous posions
                // `inattaquable` (condition « Caché »), c'est-à-dire un héros
                // que les monstres ne pouvaient plus cibler d'un round entier.
                // La carte ne parle pas d'être introuvable, elle parle de
                // PASSER — et sa phrase est mot pour mot celle de la *Mobilité
                // de combat* du Rogue, dont la mécanique `franchit_figures`
                // existait déjà avec son lecteur. « Unseen » est la couleur du
                // passage, comme sur la carte du Rogue, pas une immunité.
                //
                // `ce_tour` comme Traverser la Pierre, et pour la même raison :
                // la durée expire au tour DU PORTEUR, donc « son prochain
                // déplacement » est couvert qu'il ait déjà joué ou non.
                'effet' => ['cible' => 'heros', 'franchit_figures' => true,
                    'duree' => 'ce_tour', 'condition_appliquee' => 'Vaporeux']],
            ['element' => 'eau', 'nom' => 'Eau de Guérison', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'soin_pv_body' => 4]],

            // Terre — défense / soin
            ['element' => 'terre', 'nom' => 'Soin du Corps', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'soin_pv_body' => 4]],
            ['element' => 'terre', 'nom' => 'Traverser la Pierre', 'type' => 'utilitaire', 'difficulte_parchemin' => 1,
                // « Traverse les murs sur TOUT LE DÉPLACEMENT du jet, danger de
                // rester bloqué dans la roche massive » (Witch Lord,
                // reference/18_extensions.md §3). Ce n'est donc pas un saut :
                // le sort pose un buff qui dure le tour, et le héros se déplace
                // normalement — à travers la roche et les portes closes. Finir
                // dans la roche fait tomber le héros.
                // `cout` retiré : facturer le déplacement rendrait le sort
                // inutilisable, puisque c'est le déplacement qui EST l'effet.
                //
                // ⚠ `cible` passe de `soi` à `heros` le 2026-09-02, sur la CARTE
                // que René a fournie (transcrite doc 16 §3bis) : « This spell may
                // be cast on any one hero in your line of sight, INCLUDING
                // YOURSELF. » `soi` était notre choix de portage, pris quand doc
                // 16 §3 portait encore « ⚠ non trouvé » pour ce sort — et il
                // était incohérent avec son voisin de la MÊME liste de
                // parchemins, *Peau de Pierre*, `heros` depuis toujours.
                //
                // ⚠ « once per quest » : la carte confirme notre règle S5, elle
                // ne la contraint pas — TOUT sort est lançable une fois par quête
                // (`personnage_sorts.disponible`, réarmé par `reinitialiserQuete`).
                // Rien à ajouter, et surtout pas une seconde grammaire pour dire
                // ce que le pivot dit déjà.
                //
                // ⚠ Divergence assumée sur la DURÉE : la carte dit « during their
                // NEXT MOVEMENT », nous portons `ce_tour`. Les deux coïncident
                // dans les trois cas réels — le lanceur sur lui-même (agir sans
                // avoir bougé laisse l'allonce entière), l'allié qui n'a pas
                // encore joué, l'allié déjà joué (son buff traverse le round et
                // couvre son prochain tour). Le seul écart : un porteur qui
                // termine son tour SANS bouger perd le sort, là où la carte le
                // lui garderait. ⚠ Le mot-clé `prochain_deplacement` existe
                // depuis le 2026-10-01 (Vent Véloce, errata 2021 B4), mais il ne
                // convient PAS ici : il tombe au PREMIER pas, alors que ce mode
                // doit tenir tout le mouvement et jusqu'au jugement de la roche
                // en fin de tour.
                //
                // ⚠ Rien d'autre à câbler, et c'est ce qui rend le changement
                // sûr : `traverseRoche()` lit le buff SUR SON PORTEUR, et
                // `ce_tour` expire au tour DE CE PORTEUR (ResolveurTour, fin de
                // tour explicite) — pas à celui du lanceur. Un allié bénéficiaire
                // garde donc son mode de déplacement jusqu'à la fin de SON tour,
                // et c'est bien lui que `verifierRocheMortelle()` juge.
                'effet' => ['cible' => 'heros', 'franchit_mur' => true, 'duree' => 'ce_tour', 'condition_appliquee' => 'Intangible']],
            ['element' => 'terre', 'nom' => 'Peau de Pierre', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                // Texte officiel : « 1 dé de défense supplémentaire jusqu'au
                // PREMIER DÉGÂT SUBI » (reference/18_extensions.md §3). On
                // donnait 2 dés pour tout le combat — deux écarts d'un coup.
                'effet' => ['cible' => 'heros', 'bonus_des_defense' => 1, 'duree' => 'premier_degat_subi', 'condition_appliquee' => 'Protégé']],

            // Air — mobilité / puissance
            // `invocation_ephemere` RETIRÉ : clé sans lecteur, et surtout sans
            // source. Le texte officiel ne parle d'aucune invocation — « ouvre
            // une porte au choix OU attaque avec 5 dés de combat » (Kellar's
            // Keep p. 15, reference/18_extensions.md §3). Les 5 dés sont donc
            // exacts ; c'est le second mode, l'ouverture de porte, qui manque
            // encore (à trancher).
            ['element' => 'air', 'nom' => 'Génie', 'type' => 'degats', 'difficulte_parchemin' => 3,
                'effet' => ['portee' => 'distance', 'des_degats' => 5, 'defense_applicable' => true, 'ouvre_porte' => true]],
            ['element' => 'air', 'nom' => 'Vent Véloce', 'type' => 'utilitaire', 'difficulte_parchemin' => 1,
                // « the next time they move » : `prochain_deplacement`, et non
                // plus `ce_tour` — errata 2021 B4 (2026-10-01).
                'effet' => ['cible' => 'heros', 'deplacement_multiplie' => 2, 'duree' => 'prochain_deplacement']],
            ['element' => 'air', 'nom' => 'Tempête', 'type' => 'mental', 'difficulte_parchemin' => 3,
                // « Un monstre choisi passe son prochain tour » (Kellar's Keep
                // p. 15, reference/18_extensions.md §3) : MONO-cible — il n'a
                // jamais été un sort de zone —, et le tour saute ENTIÈREMENT.
                // On lisait auparavant `monstres_zone` (ciblage inexistant) et
                // `empeche_attaque` (le monstre avançait quand même).
                //
                // ⚠ `resistance: aucune` depuis le 2026-09-02. La CARTE (doc 16
                // §3bis) ne laisse au monstre aucun jet : « This spell creates a
                // small whirlwind that envelops one monster of your choice. That
                // monster then misses its next turn. » Le `jet_mind` que nous lui
                // imposions était de notre invention, et il rendait le sort
                // inutile là où il sert le plus — un boss a beaucoup de Mind.
                'effet' => ['cible' => 'monstre', 'resistance' => 'aucune', 'saute_tour' => true, 'duree' => 'prochain_tour']],

            // ================================================================
            // RÉPERTOIRES DE CLASSE (2026-08-12) — Barde, Druide, Warlock.
            //
            // Ces trois classes n'ont PAS d'éléments : leur carte leur donne
            // trois sorts FIXES, acquis d'emblée. `element` sert ici de nom de
            // répertoire plutôt que d'école — la colonne existait, la
            // réutiliser évite une table de plus pour trois lignes.
            //
            // Texte des cartes : reference/18_extensions.md §HasLab Mythic Tier.
            // ================================================================

            // ---- Barde (© 2021 Hasbro) ----
            ['element' => 'barde', 'nom' => 'Conte inspirant', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'exclut_soi' => true, 'bonus_des_attaque' => 1,
                    'duree' => 'prochaine_attaque', 'regain' => 'allie_deux_boucliers_blancs',
                    'condition_appliquee' => 'Renforcé']],
            // Mot pour mot notre Sommeil, exclusion des Mind 0 comprise :
            // « May not be used against mummies, zombies, or skeletons. »
            ['element' => 'barde', 'nom' => 'Berceuse', 'type' => 'mental', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'monstre', 'resistance' => 'jet_mind', 'condition_appliquee' => 'Endormi', 'fin' => 'reveil_ou_attaque']],
            ['element' => 'barde', 'nom' => 'Chant de guérison', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'soin_pv_body' => 2, 'zone' => 'heros_en_vue']],

            // ---- Druide (© 2021 Hasbro) ----
            // ⚠ Le dé de DÉFENSE est inconditionnel ; celui d'ATTAQUE ne vaut
            // qu'« when attacking a monster that you are adjacent to ».
            ['element' => 'druide', 'nom' => 'Métamorphose', 'type' => 'utilitaire', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'soi', 'bonus_des_defense' => 1, 'bonus_des_attaque' => 1,
                    'condition_bonus_attaque' => 'au_contact',
                    'duree' => 'premier_degat_subi', 'regain' => 'body_au_max',
                    'condition_appliquee' => 'Renforcé']],
            // ⚠ Le second mode de la carte — « or search : the pixie reveals
            // all traps and secret doors in any location you can see » — n'est
            // PAS porté : il attend un mode alternatif de sort, comme celui du
            // Génie. Seul le soin est actif.
            ['element' => 'druide', 'nom' => 'Luciole', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'soin_pv_body' => 2]],
            ['element' => 'druide', 'nom' => 'Force vitale', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'soin_pv_body' => 4]],

            // ---- Warlock (© 2021 Hasbro) ----
            // « Cast this spell on an enemies turn AFTER YOU HAVE SUFFERED
            // DAMAGE. Reduce that damage to zero […] » — la moitié annulation
            // passe par MoteurReactions, écrit pour elle. ⚠ La téléportation
            // qui suit (« move instantly to any unoccupied square you can
            // see ») n'est PAS portée : aucun déplacement instantané choisi.
            ['element' => 'warlock', 'nom' => 'Ailes sombres', 'type' => 'utilitaire', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'soi', 'condition_appliquee' => 'Protégé',
                    'reaction' => ['sur' => 'degats_subis', 'action' => 'annule_degats']]],
            ['element' => 'warlock', 'nom' => 'Forme démoniaque', 'type' => 'utilitaire', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'soi', 'bonus_des_attaque' => 1, 'ignore_pieges_fosse' => true,
                    'duree' => 'premier_degat_subi', 'regain' => 'monstre_vaincu',
                    'condition_appliquee' => 'Renforcé']],

            // « This spell causes any one monster to become so fearful that
            // their attacks are reduced to 1 combat die. » ⚠ Un PLAFOND, pas un
            // malus : l'ogre à 4 dés tombe à 1 comme le gobelin à 2.
            // ⚠ Côté HÉROS (tir ami assumé, doc 02 §5), la condition est
            // « Apeuré » — celle du catalogue, déjà lue par
            // `MoteurDread::malusDesAttaqueFrayeur()`. Elle disait « Terrifié »
            // jusqu'au 2026-08-14, un nom qui n'existait NULLE PART : le sort
            // partait en 422 « Condition « Terrifié » absente du catalogue »
            // dès qu'une cible ratait sa résistance, et ne « marchait » donc
            // que quand il échouait. Trouvé en jouant, jamais par les tests.
            //
            // Deux effets distincts pour deux camps, et c'est voulu : le
            // monstre voit son attaque PLAFONNÉE à 1 dé (`terrifie`), le héros
            // subit −1 dé (`Apeuré`).
            ['element' => 'warlock', 'nom' => 'Terreur', 'type' => 'mental', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'monstre', 'resistance' => 'jet_mind',
                    'condition_monstre' => 'terrifie', 'condition_appliquee' => 'Apeuré',
                    'fin' => 'jet_mind_reussi']],

            // ================================================================
            // RÉPERTOIRE ELFIQUE (© 2023 Hasbro, The Mage of the Mirror)
            //
            // L'Elfe choisira 3 sorts parmi celui-ci, au lieu d'une école
            // élémentaire (décision de René, 2026-08-11 — doc 02 §7bis).
            // ================================================================

            // « Reduces any one monster's movement to 1 square per turn. The
            // monster also rolls 1 LESS combat die when it attacks OR DEFENDS.
            // Cannot be less than 1. »
            ['element' => 'elfique', 'nom' => 'Ralentissement', 'type' => 'mental', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'monstre', 'resistance' => 'jet_mind',
                    'condition_monstre' => 'ralenti', 'condition_appliquee' => 'Ralenti',
                    'fin' => 'mort_ou_hors_de_vue']],

            // « […] IF the monster has from 1 to 3 Mind Points. The monster
            // falls asleep IMMEDIATELY. » Aucun jet de résistance : c'est le
            // seuil de Mind qui décide, et un Mind 0 reste hors de portée.
            ['element' => 'elfique', 'nom' => 'Sommeil profond', 'type' => 'mental', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'monstre', 'seuil_mind_max' => 3,
                    'condition_appliquee' => 'Endormi', 'fin' => 'reveil_ou_attaque']],

            // « If an attack against the hero is successful, they roll 1 red
            // die. On a 1, 2, or 3, THE IMAGE is attacked, and the hero suffers
            // no damage. » Annulation AUTOMATIQUE, sur jet — d'où un écouteur
            // (App\Listeners\ImageMiroir) et non une réaction à choix.
            ['element' => 'elfique', 'nom' => 'Image double', 'type' => 'utilitaire', 'difficulte_parchemin' => 3,
                // Leurre DÉFENSIF : « Protégé » et non « Renforcé ». Le héros
                // ne frappe pas mieux, il est plus dur à toucher.
                'effet' => ['cible' => 'heros', 'image_miroir' => true,
                    'duree' => 'fin_du_combat', 'condition_appliquee' => 'Protégé']],

            // « It temporarily stops time for everyone else on the gameboard,
            // enabling the hero to take another turn immediately after their
            // current turn. »
            ['element' => 'elfique', 'nom' => 'Arrêt du temps', 'type' => 'utilitaire', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'heros', 'tour_supplementaire' => true]],

            // « Every figure in the room or corridor (EXCEPT for the
            // spellcaster) must roll 1 red die. A figure that rolls equal to or
            // less than its Mind Points is unaffected. Rolling a number greater
            // than its Mind Points means that the figure is PARALYZED for 3
            // turns — unable to move, attack, or defend. »
            // ⚠ Frappe TOUTE FIGURE, alliés compris : cohérent avec notre tir
            // ami assumé (doc 02 §5, S3).
            ['element' => 'elfique', 'nom' => 'Flamme hypnotique', 'type' => 'mental', 'difficulte_parchemin' => 3,
                // ⚠ Pas de `jet_contre_mind` : la clé a été retirée le 2026-08-13,
                // elle n'était lue par PERSONNE. C'est `zone: salle_du_lanceur`
                // qui route vers `ResolveurTour::sortDeZone()`, dont la règle EST
                // le d6 par figure contre son Mind.
                'effet' => ['cible' => 'soi', 'zone' => 'salle_du_lanceur',
                    'condition_appliquee' => 'Paralysé',
                    'condition_monstre' => 'paralyse']],

            // « The hero can only move and open doors. They cannot attack,
            // search, disarm, cast spells, spring traps, or be affected by
            // attacks or spells, unless the hero chooses to cancel the spell. »
            // Rupture : le plateau lit 9+ sur 2 dés rouges ; nous 5+ sur notre
            // unique d6 (décision de René, 2026-08-12).
            ['element' => 'elfique', 'nom' => 'Évanescence', 'type' => 'utilitaire', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'heros', 'condition_appliquee' => 'Évanescent']],

            // ⚠ DEUX cartes du répertoire ne sont pas portées :
            //  - *Flashback* : rejouer un tour DÉJÀ RÉSOLU suppose un point de
            //    restauration par tour de héros ; nos snapshots existent
            //    (debut_quete, nouveau_tour) mais pas à cette granularité.
            //    Écartée sur décision de René (2026-08-12).
            //  - *Twist Wood* : « any wooden weapon, such as a staff, bow, or
            //    crossbow » — nos monstres n'ont AUCUN objet d'arme, le sort
            //    n'a donc littéralement pas de cible.

            // ---- Sorts qui n'existent QU'EN PARCHEMIN ----
            //
            // ⚠ L'élément `parchemin` n'est pas une école : il n'est dans
            // aucun répertoire, `MoteurSorts::ELEMENTS` ne le contient pas et
            // les routes de création le refusent. Aucun héros ne l'apprend
            // donc — le sort n'arrive que par sa carte, ce qui est exactement
            // ce que dit celle-ci (« This SPELL SCROLL enables a hero to… »).
            // Lui donner une école l'aurait ajouté au grimoire du magicien.
            //
            // « This spell scroll enables a hero to pick cards from the
            // treasure deck, ignoring all wandering monster and hazard cards,
            // until they pick a card showing gold, a potion, gems, or jewels.
            // Alternatively, it can be used to open one chest without harm,
            // disarming any trap on the chest. » (carte © 2023)
            //
            // ⚠ La SECONDE moitié est sans objet chez nous : un coffre n'est
            // jamais piégé (`salles_coffre` verse or, potion ou l'arme unique,
            // sans jet ni carte). Lui inventer un piège pour que le parchemin
            // ait quelque chose à désamorcer serait ajouter une règle au jeu
            // pour servir une carte — l'inverse du travail. Même traitement
            // que la clause « lycanthrope » de la Restauration supérieure.
            ['element' => 'parchemin', 'nom' => 'Trésor sans Péril', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'soi', 'pioche_sans_peril' => true]],

            // « This spell restores all lost Mind Points to the spellcaster or
            // any one hero the spellcaster chooses. » (carte © 2022)
            //
            // ⚠ TOUS les points perdus, donc le maximum — `restaure_pv_mind` et
            // non `soin_pv_mind`, qui est chiffré (Potion de restauration
            // supérieure). ⚠ Et il est CORRECT MAIS DORMANT, comme la branche
            // Mind de `resoudreRelever()` : rien ne réduit `pv_mind` chez nous,
            // le parchemin rendra donc 0 tant qu'aucun effet ne saura entamer
            // l'esprit. Le lecteur est juste, c'est sa SOURCE qui manque.
            ['element' => 'parchemin', 'nom' => 'Récupération Psychique', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'restaure_pv_mind' => true]],

            // « This spell may be cast in a horizontal, vertical, or diagonal
            // direction. The bolt will travel in a straight line until it
            // strikes a wall or closed door. It inflicts 2 Body Points of damage
            // on all heroes or monsters that stand in its path. » (carte © 2023)
            //
            // ⚠ Mot pour mot la ligne de l'*Esprit Ardent* du Moine — « straight
            // or diagonal », « until it meets a wall or closed door », 2 points.
            // La seule différence est la cible : le Moine ne touche que « each
            // ENEMY », l'Éclair « all HEROES or monsters ». D'où `cible: soi`
            // (il ne vise personne, il vise une direction) et un TIR AMI assumé,
            // que l'entrée de menu annonce en nommant les compagnons sur la
            // ligne.
            //
            // ⚠ `degats_fixes` et non `des_degats` : la carte donne un nombre,
            // pas des dés, et rien ne les réduit — ni défense, ni dés rouges.
            ['element' => 'parchemin', 'nom' => 'Éclair', 'type' => 'degats', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'soi', 'rayon' => true, 'degats_fixes' => 2]],

            // « This spell restores up to 3 Body Points to the spellcaster or
            // any one hero of their choice. » (Spell Scroll — Warmth, Frozen
            // Horror, doc 16 §9.1) — un soin FIXE plafonné au maximum, mot pour
            // mot `soin_pv_body` d'Eau de Guérison/Soin du Corps : aucun lecteur
            // neuf, la carte tenait déjà dans le vocabulaire.
            //
            // ⚠ CLAUDE.md le disait depuis le 2026-08-15 : « Warmth is the
            // honest edge case: a plain 3-point heal, portable as-is, set aside
            // only because it belongs to that box. » Elle rejoint le catalogue
            // le jour où le reste de la boîte de glace obtient enfin un examen
            // carte par carte (phase 5, 2026-09-06) — les 4 autres restent
            // écartées, cf. `config/cartes.php`.
            //
            // ⚠ `element: parchemin` (comme Trésor sans Péril/Récupération
            // Psychique/Éclair) et NON `eau` : la carte ne dit nulle part
            // qu'un magicien/elfe l'apprend d'office, et lui donner une école
            // existante l'aurait ajoutée gratuitement au grimoire du premier
            // héros qui choisit l'eau — un sort de plus au départ que la carte
            // ne promet pas.
            //
            // ⚠ `difficulte_parchemin: 2` — la carte ne chiffre aucune
            // difficulté (aucun livret ne le fait, doc 16 §2.1bis) ; alignée sur
            // les deux autres sorts UTILITAIRES `parchemin` déjà semés (Trésor
            // sans Péril, Récupération Psychique), faute d'un nombre imprimé —
            // décision prise à défaut de source, à réviser si une meilleure
            // apparaît.
            ['element' => 'parchemin', 'nom' => 'Chaleur', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'soin_pv_body' => 3]],

            // ================================================================
            // Wizards of Morcar (G1504, livret p. 11 + cartes TRANSCRITES
            // 2026-10-05, reference/18_extensions.md) — TROIS répertoires
            // OPTIONNELS que les CINQ classes de lanceurs peuvent choisir à la
            // place d'un répertoire existant (`MoteurSorts::remplacerElement()`,
            // `MoteurSorts::REPERTOIRES_OPTIONNELS`) : « These may replace
            // existing sets of spells that a spellcaster can draw on (but Elf
            // and Wizard still have one and three sets of spells respectively).
            // Spellcasters may change their spells between quests. »
            //
            // ⚠ LES NEUF cartes sourcées sont portées (2026-10-08) : *Unlearn*
            // (héros, `oublie_sort` + `cible: lanceur_dread`, durable par quête via
            // `OubliSorts`) et *Clairvoyance* (`vision_salle`) s'ajoutent aux cinq
            // d'avant, puis *Future Sight* (`relance_jet`, relance proposée après un
            // jet — `MoteurReactions`) et *Cloak of Shadows* (`pose_ombre`, couche
            // `carte.grille['ombre']` — `MoteurOmbre`). Chaque clé a son lecteur et
            // son test en jeu : voir `docs/regles/sorts-heros.md`.
            // ================================================================

            // ---- Spells of Protection ----
            // WALL OF STONE : « You create a magical wall of stone which
            // covers 2 squares not occupied by figures. The wall has 1 Body
            // Point and 6 Defend dice. Discard when the wall is destroyed. »
            // DEUX cases, comme la carte (René, 2026-10-05 : « covers 2 squares
            // not occupied by figures », annulant son « une case » du
            // 2026-10-04) — `pose_mur_magique` nomme le MOBILIER du catalogue
            // (`MobilierSeeder`) que `ResolveurTour::poserMurMagiqueSort()`
            // pose via le point de passage unique
            // `MoteurMobilier::poserMurMagique()`, en une entrée de deux cases.
            // Aucune `cible` : la paire se choisit via les entrées que
            // `MoteurSorts::entreesPoseMurMagique()` construit, jamais via une
            // liste de figures.
            ['element' => 'protection', 'nom' => 'Mur de Pierre', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['pose_mur_magique' => 'Mur de Pierre']],
            // INVISIBILITY : « Casting this spell makes you invisible until
            // the start of your next turn. While invisible, you may not
            // attack. You cannot be attacked and are immune to all spells. »
            // `cible: soi` (jamais un allié — la carte dit « makes YOU
            // invisible ») ; `condition_appliquee: Caché` pose en un geste les
            // trois clés de la carte (`inattaquable`/`attaque_interdite`/
            // `immunite_sorts`, ConditionSeeder) ; `duree: prochain_tour`
            // couvre exactement « until the start of your next turn », comme
            // Voile de Brume avant elle.
            ['element' => 'protection', 'nom' => 'Invisibilité', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'soi', 'condition_appliquee' => 'Caché', 'duree' => 'prochain_tour']],
            // UNLEARN (héros) : « You may pick one spell caster and force them to
            // discard one spell card at random. The spell is removed from play for
            // the duration of the Quest. » `cible: lanceur_dread` → un Sorcier de
            // Dread en ligne de vue qui lui reste au moins un sort ;
            // `oublie_sort` → UN sort tiré au hasard, rangé dans
            // `sorts_oublies_de_quete` (`OubliSorts`) : durable pour la quête,
            // rien ne le rend à la suivante. Le même mécanisme vaudra contre un
            // héros pour la carte Dread *Unlearn* (vague 2).
            // ⚠ Nom FRANÇAIS au catalogue (2026-10-08) : la ligne « Unlearn » est
            // RENOMMÉE par la migration 2026_10_08_110000 avant ce seeder — un
            // `updateOrCreate` sur le nouveau nom sans cette migration créerait
            // une seconde ligne. Le nom anglais vit dans config/cartes.php.
            ['element' => 'protection', 'nom' => 'Désapprentissage', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'lanceur_dread', 'oublie_sort' => true]],

            // ---- Spells of Detection ----
            // TREASURE HORDE : « You may cast this spell instead of drawing a
            // treasure card to draw 3 treasure cards. You may shuffle any of
            // the drawn cards back into the treasure deck and keep the rest. »
            // `pioche_triple` → `ResolveurTour::piocherTresorConvoite()` : pioche
            // EXACTEMENT 3 cartes, applique celles qui payent, remet
            // automatiquement les dangers/« rien » sous le paquet (même
            // résolution automatique du « may » que `piocherAvecSixiemeSens`,
            // une carte remise ne coûte jamais rien à remettre).
            ['element' => 'detection', 'nom' => 'Trésor convoité', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['pioche_triple' => true]],
            // CLAIRVOYANCE : « You may ask Zargon to lay out the contents of one
            // room anywhere on the board. If the room is empty, you may not try
            // again. Discard after use. » `vision_salle` → une entrée PAR SALLE
            // NON DÉCOUVERTE (`MoteurSorts::entreesVisionSalle()`), résolue par
            // `ResolveurTour::visionSalleSort()` : seule la salle choisie se
            // montre (monstres par nom, nombre de pièges), sans toucher au
            // brouillard. Une salle vide consomme le sort comme une pleine —
            // « may not try again » est tenu par « Discard after use » (S5).
            ['element' => 'detection', 'nom' => 'Clairvoyance', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['vision_salle' => true]],
            // FUTURE SIGHT : « This spell may be cast at any time and does not take
            // an action. You may re-roll all dice for any one attack, defense or
            // movement roll. Discard after use. » (carte © 2026, reference/18)
            // `relance_jet` → AUCUNE entrée de menu (pas d'action) : le sort se
            // joue APRÈS un jet du héros qui le connaît OU qui porte son parchemin,
            // par `MoteurReactions::sourceVisionDuFutur()` (offres d'attaque, de
            // défense et de déplacement) — décision de René, 2026-10-08 : le résultat
            // est montré, le serveur attend la réponse AVANT de l'appliquer. Le
            // grimoire passe d'abord (« Discard after use » = `disponible` à faux, S5,
            // une fois par quête) ; le parchemin, lui, quitte le sac s'il relance.
            // ⚠ `difficulte_parchemin` n'est qu'une valeur de STRUCTURE (colonne non
            // nulle, un parchemin par sort) : `lire_parchemin` ne l'offre jamais.
            ['element' => 'detection', 'nom' => 'Vision du futur', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['relance_jet' => true]],

            // ---- Spells of Darkness ----
            // CHAINS OF DARKNESS : « You may cast this spell on one monster
            // you can see. That monster may not move or attack until the
            // start of your next turn. They may defend or cast spells. »
            // `resistance: aucune` — la carte ne propose AUCUN jet, l'effet
            // prend toujours ; `condition_monstre: enchaine` (nouveau mot-clé,
            // `MoteurSorts::MONSTRE_ENCHAINE`) côté monstre, `condition_appliquee:
            // Enchaîné` (ConditionSeeder) si le tir ami touche un héros (S3).
            ['element' => 'tenebres', 'nom' => 'Chaînes des Ténèbres', 'type' => 'mental', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'monstre', 'resistance' => 'aucune',
                    'condition_monstre' => 'enchaine', 'condition_appliquee' => 'Enchaîné']],
            // ARROWS OF THE NIGHT : « This spell fires magical bolts at any
            // monster you can see. Roll 2 Attack dice. The target defends
            // with as many dice as they have Mind Points. Monsters with 0
            // Mind points may not roll defense. »
            // `resistance: des_mind` (nouveau, `MotsClesSort::RESISTANCE_DES_MIND`) :
            // un combat NORMAL où seul le NOMBRE de dés de défense change de
            // source — `ResolveurTour::sortDegats()` substitue `pv_mind` à la
            // défense habituelle, 0 Mind valant 0 dé, mot pour mot la carte.
            // CLOAK OF SHADOWS : « This spell summons a patch of darkness. Place the
            // Cloak of Shadows tile on the gameboard. Heroes and monsters on the
            // tile may not attack or be attacked. The darkness blocks line of sight
            // into and through it. Place 3 shadow tokens on this card. At the start
            // of the spellcaster's turn, remove a shadow token. The spell ends
            // after the last shadow token is removed. » (carte © 2026)
            // `pose_ombre` → une entrée PAR emplacement légal
            // (`MoteurSorts::entreesPoseOmbre()` / `MoteurOmbre::emplacementsLegaux()`),
            // posé par `MoteurOmbre::poser()` sur `carte.grille['ombre']` ; taille
            // 3×2 mesurée sur le livret p. 4 (voir `MoteurOmbre`), 3 jetons,
            // décompte au début du tour du lanceur.
            ['element' => 'tenebres', 'nom' => "Voile d'ombre", 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['pose_ombre' => true]],
            ['element' => 'tenebres', 'nom' => 'Flèches de la Nuit', 'type' => 'degats', 'difficulte_parchemin' => 2,
                'effet' => ['portee' => 'distance', 'cible' => 'monstre', 'des_degats' => 2, 'resistance' => 'des_mind']],
        ];

        foreach ($sorts as $sort) {
            Sort::updateOrCreate(['nom' => $sort['nom']], $sort);
        }
    }
}
