<?php

namespace Database\Seeders;

use App\Models\Sort;
use Illuminate\Database\Seeder;

/**
 * Les 12 sorts héros (doc 02 §7) — 4 éléments × 3 sorts.
 * difficulte_parchemin = succès de Mind requis pour un non-lanceur (S1).
 */
class SortSeeder extends Seeder
{
    public function run(): void
    {
        $sorts = [
            // Feu — offensif
            ['element' => 'feu', 'nom' => 'Boule de Feu', 'type' => 'degats', 'difficulte_parchemin' => 3,
                'effet' => ['portee' => 'distance', 'des_degats' => 2, 'defense_applicable' => true, 'type_degat' => 'feu']],
            ['element' => 'feu', 'nom' => 'Courage', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'bonus_des_attaque' => 2, 'duree' => 'prochaine_attaque', 'condition_appliquee' => 'Renforcé']],
            ['element' => 'feu', 'nom' => 'Trait de Feu', 'type' => 'degats', 'difficulte_parchemin' => 1,
                'effet' => ['portee' => 'distance', 'des_degats' => 1, 'defense_applicable' => true, 'type_degat' => 'feu']],

            // Eau — contrôle / soin
            ['element' => 'eau', 'nom' => 'Sommeil', 'type' => 'mental', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'monstre', 'resistance' => 'jet_mind', 'condition_appliquee' => 'Endormi', 'fin' => 'reveil_ou_attaque']],
            ['element' => 'eau', 'nom' => 'Voile de Brume', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'condition_appliquee' => 'Caché', 'duree' => 'prochain_tour']],
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
                'effet' => ['cible' => 'soi', 'franchit_mur' => true, 'duree' => 'ce_tour', 'condition_appliquee' => 'Intangible']],
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
                'effet' => ['cible' => 'heros', 'deplacement_multiplie' => 2, 'duree' => 'ce_tour']],
            ['element' => 'air', 'nom' => 'Tempête', 'type' => 'mental', 'difficulte_parchemin' => 3,
                // « Un monstre choisi passe son prochain tour » (Kellar's Keep
                // p. 15, reference/18_extensions.md §3) : MONO-cible — il n'a
                // jamais été un sort de zone —, et le tour saute ENTIÈREMENT.
                // On lisait auparavant `monstres_zone` (ciblage inexistant) et
                // `empeche_attaque` (le monstre avançait quand même).
                'effet' => ['cible' => 'monstre', 'resistance' => 'jet_mind', 'saute_tour' => true, 'duree' => 'prochain_tour']],

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
        ];

        foreach ($sorts as $sort) {
            Sort::updateOrCreate(['nom' => $sort['nom']], $sort);
        }
    }
}
