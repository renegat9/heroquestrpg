<?php

namespace Database\Seeders;

use App\Models\Competence;
use Illuminate\Database\Seeder;

/**
 * GRILLE DE TALENTS — 3 colonnes × 3 lignes par classe (René, 2026-08-23).
 *
 * Chaque classe nomme ses TROIS catégories ; dans une colonne, acquérir la
 * ligne n exige la ligne n−1 de la MÊME colonne. Le `prerequis_id` n'est plus
 * nommé à la main : il se déduit de la position, ce qui rend impossible une
 * chaîne qui traverserait deux colonnes.
 *
 * Ce que la grille remplace : une liste plate de 4 à 7 nœuds par classe, sans
 * thème ni arbitrage — avec 5 à 8 niveaux par campagne, on achetait à peu près
 * tout ce qui existait. Neuf cases pour quatre à sept points, c'est le choix
 * qui redevient un choix : on descend une colonne, on renonce à une autre.
 *
 * Les trois nœuds d'une colonne sont des effets DIFFÉRENTS du même domaine, et
 * non le même chiffre qui grossit (décision de René) : la chaîne est un ordre
 * d'acquisition, pas une montée en puissance.
 *
 * ⚠ Règle non négociable, et c'est celle qui a présidé à toute la refonte :
 * chaque `effet.mecanique` figure dans `App\Engine\MotsClesTalent` et y déclare
 * SON lecteur. `GrilleTalentsTest` le vérifie dans les deux sens, contrôle que
 * le lecteur existe vraiment et que son fichier lit bien la clé, et
 * `TalentsEnJeuTest` en exerce une par une les conséquences en partie.
 *
 * ⚠ Chaque nœud porte une `description` écrite à la main — la phrase de jeu,
 * qui dit le gain, sa condition et sa cadence. Le chiffre, lui, est DÉRIVÉ de
 * `effet` par `MotsClesTalent::avantage()` et jamais saisi : c'est ce qui
 * garantit qu'aucun talent ne promette autre chose que ce qu'il fait.
 */
class CompetenceSeeder extends Seeder
{
    /**
     * Les 36 colonnes : `classe => [ [categorie, icone, noeuds[3]] ×3 ]`.
     *
     * @var array<string, list<array{categorie: string, icone: string, noeuds: list<array<string, mixed>>}>>
     */
    private const GRILLE = [
        // ================================================================
        // BARBARE — frapper fort, encaisser, faire peur.
        // ================================================================
        'barbare' => [
            ['categorie' => 'Furie', 'icone' => 'local_fire_department', 'noeuds' => [
                ['nom' => 'Coup puissant', 'type' => 'actif',
                    'description' => "Une fois par attaque, relance tous les dés d'attaque qui n'ont pas fait de crâne.",
                    'effet' => ['mecanique' => 'relance_des_attaque_rates', 'frequence' => 'une_fois_par_usage']],
                ['nom' => 'Frénésie', 'type' => 'passif',
                    'description' => "+1 dé d'attaque tant que tes PV de Body sont sous la moitié de ton maximum.",
                    'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
                ['nom' => 'Sang qui bout', 'type' => 'actif',
                    'description' => 'Une fois par tour, abattre ta cible te rend ton action : tu frappes une seconde fois.',
                    'effet' => ['mecanique' => 'attaque_supplementaire_apres_kill', 'frequence' => 'une_fois_par_tour']],
            ]],
            ['categorie' => 'Carrure', 'icone' => 'fitness_center', 'noeuds' => [
                ['nom' => 'Carrure', 'type' => 'passif',
                    'description' => '+1 PV de Body maximum, définitivement acquis.',
                    'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Cuir tanné', 'type' => 'passif',
                    'description' => "Chaque coup que tu subis t'inflige 1 dégât de moins, sans jamais descendre sous zéro.",
                    'effet' => ['mecanique' => 'reduction_degats', 'valeur' => 1]],
                ['nom' => 'Colosse', 'type' => 'passif',
                    'description' => '+1 à ton attribut Body : un dé de plus à chacun de tes jets de Body.',
                    'effet' => ['mecanique' => 'bonus_attribut_body', 'valeur' => 1]],
            ]],
            ['categorie' => 'Terreur', 'icone' => 'sentiment_very_dissatisfied', 'noeuds' => [
                ['nom' => 'Intimidation', 'type' => 'passif',
                    'description' => '+1 dé de Mind sur les jets sociaux fondés sur la peur.',
                    'effet' => ['mecanique' => 'avantage_jet_mind', 'valeur' => 1, 'contexte' => 'social_peur']],
                ['nom' => 'Regard qui glace', 'type' => 'passif',
                    'description' => "Les monstres à ton contact attaquent avec 1 dé d'attaque en moins.",
                    'effet' => ['mecanique' => 'malus_des_monstre_adjacent', 'valeur' => 1]],
                ['nom' => 'Fauchaison', 'type' => 'actif',
                    'description' => 'Une fois par quête, une seule attaque frappe tous les monstres à ton contact, diagonales comprises.',
                    'effet' => ['mecanique' => 'attaque_balayee', 'diagonales' => true, 'frequence' => 'une_fois_par_quete']],
            ]],
        ],

        // ================================================================
        // NAIN — la mine, la forge, et la ténacité.
        // ================================================================
        'nain' => [
            ['categorie' => 'Mine', 'icone' => 'visibility', 'noeuds' => [
                ['nom' => 'Œil du mineur', 'type' => 'passif',
                    'description' => "Les pièges cachés des cases voisines se révèlent d'eux-mêmes quand tu arrives.",
                    'effet' => ['mecanique' => 'detection_pieges_adjacents', 'automatique' => true]],
                ['nom' => 'Désamorçage', 'type' => 'actif',
                    'description' => 'Tu peux tenter de neutraliser un piège détecté à ton contact, par un jet de Body.',
                    'effet' => ['mecanique' => 'desamorcer_piege', 'jet' => 'body']],
                ['nom' => 'Parler à la pierre', 'type' => 'passif',
                    'description' => "Les portes secrètes des cases voisines se révèlent d'elles-mêmes, sans jet ni action.",
                    'effet' => ['mecanique' => 'detection_portes_secretes']],
            ]],
            ['categorie' => 'Forge', 'icone' => 'hardware', 'noeuds' => [
                ['nom' => 'Forge', 'type' => 'deblocage',
                    'description' => "Au hub, tu améliores définitivement un équipement du groupe : +1 dé ou une propriété.",
                    'effet' => ['mecanique' => 'forge_amelioration', 'lieu' => 'hub', 'catalogue' => 'forge_ameliorations']],
                ['nom' => 'Solides épaules', 'type' => 'passif',
                    'description' => '+2 emplacements dans ton sac à dos, pour rapporter davantage du donjon.',
                    'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
                ['nom' => 'Marchandage', 'type' => 'passif',
                    'description' => "L'étal du marché baisse ses prix de 15 % pour toute la compagnie.",
                    'effet' => ['mecanique' => 'remise_marche', 'valeur' => 15]],
            ]],
            ['categorie' => 'Ténacité', 'icone' => 'shield', 'noeuds' => [
                ['nom' => 'Garde tenace', 'type' => 'passif',
                    'description' => '+1 dé de défense contre la première attaque que tu subis dans la quête.',
                    'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                // Couvre les DEUX poisons du jeu. Il n'en existait qu'un quand
                // ce nœud a été écrit ; le venin des créatures de Jungles of
                // Delthrak (condition « Envenimé ») est arrivé le 2026-08-09, et
                // un talent nommé « résistance au poison » qui laisse un serpent
                // paralyser son porteur est une promesse rompue.
                ['nom' => 'Sang robuste', 'type' => 'passif',
                    'description' => "Tu résistes aux conditions Empoisonné et Envenimé, d'où qu'elles viennent.",
                    'effet' => ['mecanique' => 'resistance_condition', 'condition_nom' => ['Empoisonné', 'Envenimé']]],
                ['nom' => 'Ancré', 'type' => 'passif',
                    'description' => '+1 à ton attribut Body : un dé de plus à chacun de tes jets de Body.',
                    'effet' => ['mecanique' => 'bonus_attribut_body', 'valeur' => 1]],
            ]],
        ],

        // ================================================================
        // ELFE — la magie apprise, l'œil du tireur, la grâce.
        // ================================================================
        'elfe' => [
            ['categorie' => 'Arcane elfique', 'icone' => 'auto_awesome', 'noeuds' => [
                ['nom' => 'Première magie', 'type' => 'deblocage',
                    'description' => 'Ouvre un domaine de magie : ses 3 sorts rejoignent ton grimoire.',
                    'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1]],
                ['nom' => 'Second élément', 'type' => 'deblocage',
                    'description' => 'Ouvre un second domaine de magie : 3 sorts de plus à ton grimoire.',
                    'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1]],
                ['nom' => 'Chant runique', 'type' => 'passif',
                    'description' => 'Chaque monstre que tu abats te rend un sort épuisé, prêt à être relancé.',
                    'effet' => ['mecanique' => 'regain_sort', 'regain' => 'monstre_vaincu']],
            ]],
            ['categorie' => 'Œil', 'icone' => 'my_location', 'noeuds' => [
                ['nom' => 'Sens aiguisés', 'type' => 'passif',
                    'description' => '+1 dé de Mind sur les jets de perception et de repérage.',
                    'effet' => ['mecanique' => 'avantage_jet_mind', 'valeur' => 1, 'contexte' => 'perception']],
                ['nom' => 'Tir précis', 'type' => 'passif',
                    'description' => "+1 dé d'attaque sur chacun de tes tirs à distance véritables.",
                    'effet' => ['mecanique' => 'bonus_des_attaque_distance', 'valeur' => 1]],
                ['nom' => 'Flèche perçante', 'type' => 'passif',
                    'description' => 'Le monstre que tu vises lance 1 dé de défense de moins contre toi.',
                    'effet' => ['mecanique' => 'ignore_defense_monstre', 'valeur' => 1]],
            ]],
            ['categorie' => 'Grâce', 'icone' => 'alt_route', 'noeuds' => [
                ['nom' => 'Pas léger', 'type' => 'passif',
                    'description' => '+1 case à ton déplacement de base, à chacun de tes tours.',
                    'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Esquive dansante', 'type' => 'passif',
                    'description' => '+1 dé de défense contre les attaques à distance qui te visent.',
                    'effet' => ['mecanique' => 'bonus_des_defense_contre_distance', 'valeur' => 1]],
                ['nom' => 'Fuite gracieuse', 'type' => 'actif',
                    'description' => 'Une fois par tour, scinde ton déplacement : une partie avant ton action, le reste après.',
                    'effet' => ['mecanique' => 'deplacement_scinde', 'frequence' => 'une_fois_par_tour']],
            ]],
        ],

        // ================================================================
        // MAGICIEN — les écoles, le savoir, et l'escrime de fortune.
        // ================================================================
        'magicien' => [
            ['categorie' => 'Écoles', 'icone' => 'auto_awesome', 'noeuds' => [
                ['nom' => 'Écoles', 'type' => 'deblocage',
                    'description' => 'Ouvre un domaine de magie de plus : ses 3 sorts rejoignent ton grimoire.',
                    'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1, 'repetable' => true]],
                ['nom' => 'Réserve arcanique', 'type' => 'passif',
                    'description' => 'Une fois par tour, tu lances un second sort dans le même tour.',
                    'effet' => ['mecanique' => 'sort_supplementaire_par_tour', 'frequence' => 'une_fois_par_tour']],
                ['nom' => 'Puissance brute', 'type' => 'passif',
                    'description' => '+1 dégât à chaque sort offensif qui a passé la défense de sa cible.',
                    'effet' => ['mecanique' => 'bonus_degats_sort', 'valeur' => 1]],
            ]],
            ['categorie' => 'Érudition', 'icone' => 'lightbulb', 'noeuds' => [
                ['nom' => 'Érudition', 'type' => 'passif',
                    'description' => "+1 dé de Mind sur les jets de savoir et d'érudition.",
                    'effet' => ['mecanique' => 'avantage_jet_mind', 'valeur' => 1, 'contexte' => 'savoir']],
                ['nom' => 'Concentration', 'type' => 'actif',
                    'description' => 'Une fois par quête, sacrifie ton tour entier pour récupérer un sort épuisé.',
                    'effet' => ['mecanique' => 'recuperer_sort_epuise', 'cout' => 'tour_complet', 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Contresort', 'type' => 'actif',
                    'description' => "Quand un sort ennemi allait te frapper, un jet de Mind l'annule avant qu'il porte.",
                    'effet' => ['mecanique' => 'annuler_effet_magique', 'jet' => 'mind']],
            ]],
            // Déblocages d'équipement (doc 01 §7) : le magicien du plateau ne
            // porte ni armure ni arme de mêlée sérieuse. Ces deux nœuds lèvent
            // chacune des limites, au prix d'un point de compétence — donc au
            // détriment de sa progression magique. Un vrai arbitrage.
            ['categorie' => 'Escrime de fortune', 'icone' => 'key', 'noeuds' => [
                ['nom' => "Cuir d'apprenti", 'type' => 'deblocage',
                    'description' => 'Débloque les armures légères : le casque et la cotte de mailles.',
                    'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['armure_legere']]],
                ['nom' => 'Escrime de fortune', 'type' => 'deblocage',
                    'description' => 'Débloque les armes de mêlée courantes : épées, rapière, hachette.',
                    'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['arme_courante']]],
                ['nom' => 'Corps entraîné', 'type' => 'passif',
                    'description' => '+1 PV de Body maximum, pour survivre au corps-à-corps que tu viens de choisir.',
                    'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
            ]],
        ],

        // ================================================================
        // BARDE — le refrain, le verbe, la marche.
        // ================================================================
        'barde' => [
            ['categorie' => 'Refrain', 'icone' => 'music_note', 'noeuds' => [
                ['nom' => 'Refrain vaillant', 'type' => 'passif',
                    'description' => '+1 dé de défense contre la première attaque que tu subis dans la quête.',
                    'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Rappel', 'type' => 'actif',
                    'description' => 'Une fois par quête, sacrifie ton tour entier pour récupérer un sort épuisé.',
                    'effet' => ['mecanique' => 'recuperer_sort_epuise', 'cout' => 'tour_complet', 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Second couplet', 'type' => 'passif',
                    'description' => 'Une fois par tour, tu lances un second sort dans le même tour.',
                    'effet' => ['mecanique' => 'sort_supplementaire_par_tour', 'frequence' => 'une_fois_par_tour']],
            ]],
            ['categorie' => 'Verbe', 'icone' => 'record_voice_over', 'noeuds' => [
                ['nom' => 'Beau parleur', 'type' => 'passif',
                    'description' => '+1 dé de Mind sur les jets sociaux fondés sur la peur.',
                    'effet' => ['mecanique' => 'avantage_jet_mind', 'valeur' => 1, 'contexte' => 'social_peur']],
                ['nom' => 'Ballade apaisante', 'type' => 'actif',
                    'description' => 'Une fois par quête, en action, rends 1d6 PV de Body à un compagnon à ton contact.',
                    'effet' => ['mecanique' => 'soin_allie', 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Mot qui blesse', 'type' => 'passif',
                    'description' => "Les monstres à ton contact attaquent avec 1 dé d'attaque en moins.",
                    'effet' => ['mecanique' => 'malus_des_monstre_adjacent', 'valeur' => 1]],
            ]],
            ['categorie' => 'Marche', 'icone' => 'directions_walk', 'noeuds' => [
                ['nom' => 'Marche entraînante', 'type' => 'passif',
                    'description' => '+1 case à ton déplacement de base, à chacun de tes tours.',
                    'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Pas de danse', 'type' => 'passif',
                    'description' => '+1 dé de défense contre les attaques à distance qui te visent.',
                    'effet' => ['mecanique' => 'bonus_des_defense_contre_distance', 'valeur' => 1]],
                ['nom' => 'Havresac de ménestrel', 'type' => 'passif',
                    'description' => '+2 emplacements dans ton sac à dos, pour porter le butin de la troupe.',
                    'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
            ]],
        ],

        // ================================================================
        // DRUIDE — la sève, la communion, le sentier.
        // ================================================================
        'druide' => [
            ['categorie' => 'Sève', 'icone' => 'grass', 'noeuds' => [
                ['nom' => 'Vigueur sylvestre', 'type' => 'passif',
                    'description' => '+1 PV de Body maximum, définitivement acquis.',
                    'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Écorce', 'type' => 'passif',
                    'description' => '+1 dé de défense contre la première attaque que tu subis dans la quête.',
                    'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Sève tenace', 'type' => 'passif',
                    'description' => "Tu résistes aux conditions Empoisonné et Envenimé, d'où qu'elles viennent.",
                    'effet' => ['mecanique' => 'resistance_condition', 'condition_nom' => ['Empoisonné', 'Envenimé']]],
            ]],
            ['categorie' => 'Communion', 'icone' => 'self_improvement', 'noeuds' => [
                ['nom' => 'Communion', 'type' => 'actif',
                    'description' => 'Une fois par quête, sacrifie ton tour entier pour récupérer un sort épuisé.',
                    'effet' => ['mecanique' => 'recuperer_sort_epuise', 'cout' => 'tour_complet', 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Appel de la forêt', 'type' => 'passif',
                    'description' => 'Chaque monstre que tu abats te rend un sort épuisé, prêt à être relancé.',
                    'effet' => ['mecanique' => 'regain_sort', 'regain' => 'monstre_vaincu']],
                ['nom' => 'Verbe ancien', 'type' => 'actif',
                    'description' => "Quand un sort ennemi allait te frapper, un jet de Mind l'annule avant qu'il porte.",
                    'effet' => ['mecanique' => 'annuler_effet_magique', 'jet' => 'mind']],
            ]],
            ['categorie' => 'Sentier', 'icone' => 'forest', 'noeuds' => [
                ['nom' => 'Pas de la forêt', 'type' => 'passif',
                    'description' => '+1 case à ton déplacement de base, à chacun de tes tours.',
                    'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Ronces complices', 'type' => 'passif',
                    'description' => 'Racines entravantes et chausse-trappes ne coupent plus ta course.',
                    'effet' => ['mecanique' => 'ignore_terrain_entravant']],
                ['nom' => 'Regard de la bête', 'type' => 'passif',
                    'description' => '+1 dé de Mind sur les jets de perception et de repérage.',
                    'effet' => ['mecanique' => 'avantage_jet_mind', 'valeur' => 1, 'contexte' => 'perception']],
            ]],
        ],

        // ================================================================
        // WARLOCK — le pacte, la malédiction, la corruption.
        // ================================================================
        'warlock' => [
            ['categorie' => 'Pacte', 'icone' => 'bloodtype', 'noeuds' => [
                ['nom' => 'Pacte', 'type' => 'passif',
                    'description' => '+1 PV de Mind maximum, définitivement acquis.',
                    'effet' => ['mecanique' => 'bonus_pv_mind_max', 'valeur' => 1]],
                ['nom' => 'Volonté noire', 'type' => 'passif',
                    'description' => '+1 à ton attribut Mind : un dé de plus à chacun de tes jets de Mind.',
                    'effet' => ['mecanique' => 'bonus_attribut_mind', 'valeur' => 1]],
                ['nom' => 'Prix du pacte', 'type' => 'actif',
                    'description' => 'En action, paie 1 PV de Body pour rendre un sort épuisé de nouveau lançable.',
                    'effet' => ['mecanique' => 'sacrifice_pv_pour_sort']],
            ]],
            ['categorie' => 'Malédiction', 'icone' => 'coronavirus', 'noeuds' => [
                ['nom' => 'Contresort', 'type' => 'actif',
                    'description' => "Quand un sort ennemi allait te frapper, un jet de Mind l'annule avant qu'il porte.",
                    'effet' => ['mecanique' => 'annuler_effet_magique', 'jet' => 'mind']],
                ['nom' => 'Œil vitreux', 'type' => 'passif',
                    'description' => "Les monstres à ton contact attaquent avec 1 dé d'attaque en moins.",
                    'effet' => ['mecanique' => 'malus_des_monstre_adjacent', 'valeur' => 1]],
                ['nom' => 'Marque du damné', 'type' => 'passif',
                    'description' => '+1 dégât à chaque sort offensif qui a passé la défense de sa cible.',
                    'effet' => ['mecanique' => 'bonus_degats_sort', 'valeur' => 1]],
            ]],
            ['categorie' => 'Corruption', 'icone' => 'local_fire_department', 'noeuds' => [
                ['nom' => "Cuir d'initié", 'type' => 'deblocage',
                    'description' => 'Débloque les armures légères : le casque et la cotte de mailles.',
                    'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['armure_legere']]],
                ['nom' => 'Réserve damnée', 'type' => 'passif',
                    'description' => 'Une fois par tour, tu lances un second sort dans le même tour.',
                    'effet' => ['mecanique' => 'sort_supplementaire_par_tour', 'frequence' => 'une_fois_par_tour']],
                ['nom' => 'Chair impie', 'type' => 'passif',
                    'description' => "Les dégâts de feu ne t'atteignent plus, quelle qu'en soit la source.",
                    'effet' => ['mecanique' => 'resistance_degats_type', 'type_degat' => 'feu']],
            ]],
        ],

        // ================================================================
        // ROGUE — l'ombre, la lame, le butin.
        // ================================================================
        'rogue' => [
            ['categorie' => 'Ombre', 'icone' => 'dark_mode', 'noeuds' => [
                ['nom' => 'Pas léger', 'type' => 'passif',
                    'description' => '+1 case à ton déplacement de base, à chacun de tes tours.',
                    'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Esquive', 'type' => 'passif',
                    'description' => '+1 dé de défense contre la première attaque que tu subis dans la quête.',
                    'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Fuite calculée', 'type' => 'actif',
                    'description' => 'Une fois par tour, scinde ton déplacement : une partie avant ton action, le reste après.',
                    'effet' => ['mecanique' => 'deplacement_scinde', 'frequence' => 'une_fois_par_tour']],
            ]],
            ['categorie' => 'Lame', 'icone' => 'content_cut', 'noeuds' => [
                ['nom' => 'Coup bas', 'type' => 'passif',
                    'description' => "+1 dé d'attaque tant que tes PV de Body sont sous la moitié de ton maximum.",
                    'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
                ['nom' => 'Lame vénéneuse', 'type' => 'passif',
                    'description' => "Un coup qui entame ta cible l'engourdit : le monstre touché devient Ralenti.",
                    'effet' => ['mecanique' => 'inflige_condition_sur_touche', 'condition_monstre' => 'ralenti', 'duree' => 3]],
                ['nom' => 'Coup de grâce', 'type' => 'actif',
                    'description' => 'Une fois par tour, abattre ta cible te rend ton action : tu frappes une seconde fois.',
                    'effet' => ['mecanique' => 'attaque_supplementaire_apres_kill', 'frequence' => 'une_fois_par_tour']],
            ]],
            ['categorie' => 'Butin', 'icone' => 'sell', 'noeuds' => [
                ['nom' => 'Doigts de fée', 'type' => 'actif',
                    'description' => 'Tu peux tenter de neutraliser un piège détecté à ton contact, par un jet de Body.',
                    'effet' => ['mecanique' => 'desamorcer_piege', 'jet' => 'body']],
                ['nom' => 'Poches profondes', 'type' => 'passif',
                    'description' => '+2 emplacements dans ton sac à dos, pour rapporter davantage du donjon.',
                    'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
                ['nom' => 'Receleur', 'type' => 'passif',
                    'description' => "L'étal du marché baisse ses prix de 15 % pour toute la compagnie.",
                    'effet' => ['mecanique' => 'remise_marche', 'valeur' => 15]],
            ]],
        ],

        // ================================================================
        // MOINE — la discipline, le vent, la méditation.
        // ================================================================
        'moine' => [
            ['categorie' => 'Discipline', 'icone' => 'self_improvement', 'noeuds' => [
                ['nom' => 'Souffle discipliné', 'type' => 'passif',
                    'description' => '+1 PV de Body maximum, définitivement acquis.',
                    'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Corps aguerri', 'type' => 'passif',
                    'description' => '+1 à ton attribut Body : un dé de plus à chacun de tes jets de Body.',
                    'effet' => ['mecanique' => 'bonus_attribut_body', 'valeur' => 1]],
                ['nom' => 'Peau de fer', 'type' => 'passif',
                    'description' => "Chaque coup que tu subis t'inflige 1 dégât de moins, sans jamais descendre sous zéro.",
                    'effet' => ['mecanique' => 'reduction_degats', 'valeur' => 1]],
            ]],
            ['categorie' => 'Vent', 'icone' => 'air', 'noeuds' => [
                ['nom' => 'Course du vent', 'type' => 'passif',
                    'description' => '+1 case à ton déplacement de base, à chacun de tes tours.',
                    'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Pas suspendu', 'type' => 'passif',
                    'description' => "Tu traverses sans t'arrêter les cases occupées par les monstres.",
                    'effet' => ['mecanique' => 'franchit_figures']],
                ['nom' => 'Souffle retenu', 'type' => 'passif',
                    'description' => '+1 dé de défense contre les attaques à distance qui te visent.',
                    'effet' => ['mecanique' => 'bonus_des_defense_contre_distance', 'valeur' => 1]],
            ]],
            ['categorie' => 'Méditation', 'icone' => 'lightbulb', 'noeuds' => [
                ['nom' => 'Méditation', 'type' => 'passif',
                    'description' => '+1 dé de Mind sur les jets de savoir et de concentration.',
                    'effet' => ['mecanique' => 'avantage_jet_mind', 'valeur' => 1, 'contexte' => 'savoir']],
                ['nom' => 'Poing de fer', 'type' => 'passif',
                    'description' => "+1 dé d'attaque tant que tes PV de Body sont sous la moitié de ton maximum.",
                    'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
                ['nom' => 'Esprit clair', 'type' => 'actif',
                    'description' => 'Une fois par quête, un jet de Mind manqué est intégralement rejoué.',
                    'effet' => ['mecanique' => 'relance_jet_mind_rate', 'frequence' => 'une_fois_par_quete']],
            ]],
        ],

        // ================================================================
        // CHEVALIER — le serment, la prestance, la croisade.
        // ================================================================
        'chevalier' => [
            ['categorie' => 'Serment', 'icone' => 'shield', 'noeuds' => [
                ['nom' => 'Serment', 'type' => 'passif',
                    'description' => '+1 PV de Body maximum, définitivement acquis.',
                    'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Garde haute', 'type' => 'passif',
                    'description' => '+1 dé de défense contre la première attaque que tu subis dans la quête.',
                    'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Rempart', 'type' => 'passif',
                    'description' => "Chaque coup que tu subis t'inflige 1 dégât de moins, sans jamais descendre sous zéro.",
                    'effet' => ['mecanique' => 'reduction_degats', 'valeur' => 1]],
            ]],
            ['categorie' => 'Prestance', 'icone' => 'groups', 'noeuds' => [
                ['nom' => 'Prestance', 'type' => 'passif',
                    'description' => '+1 dé de Mind sur les jets sociaux fondés sur la peur.',
                    'effet' => ['mecanique' => 'avantage_jet_mind', 'valeur' => 1, 'contexte' => 'social_peur']],
                ['nom' => 'Bannière', 'type' => 'passif',
                    'description' => '+1 dé de défense à chaque héros qui se tient à ton contact — jamais à toi.',
                    'effet' => ['mecanique' => 'bonus_des_defense_allie_adjacent', 'valeur' => 1]],
                ['nom' => 'Appel au ralliement', 'type' => 'actif',
                    'description' => 'Une fois par quête, en action, rends 1d6 PV de Body à un compagnon à ton contact.',
                    'effet' => ['mecanique' => 'soin_allie', 'frequence' => 'une_fois_par_quete']],
            ]],
            ['categorie' => 'Croisade', 'icone' => 'swords', 'noeuds' => [
                ['nom' => "Bras d'acier", 'type' => 'actif',
                    'description' => "Une fois par attaque, relance tous les dés d'attaque qui n'ont pas fait de crâne.",
                    'effet' => ['mecanique' => 'relance_des_attaque_rates', 'frequence' => 'une_fois_par_usage']],
                ['nom' => 'Charge du destrier', 'type' => 'passif',
                    'description' => "+1 dé d'attaque contre les sous-boss et les boss, jamais contre la piétaille.",
                    'effet' => ['mecanique' => 'bonus_des_attaque_contre_tier', 'valeur' => 1, 'tier' => ['sous_boss', 'boss']]],
                ['nom' => 'Barda du croisé', 'type' => 'passif',
                    'description' => '+2 emplacements dans ton sac à dos, pour porter le butin de la troupe.',
                    'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
            ]],
        ],

        // ================================================================
        // BERSERKER — la rage, la carcasse, la charge.
        // ================================================================
        'berserker' => [
            ['categorie' => 'Rage', 'icone' => 'local_fire_department', 'noeuds' => [
                ['nom' => 'Rage froide', 'type' => 'passif',
                    'description' => "+1 dé d'attaque tant que tes PV de Body sont sous la moitié de ton maximum.",
                    'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
                ['nom' => 'Coup sauvage', 'type' => 'actif',
                    'description' => "Une fois par attaque, relance tous les dés d'attaque qui n'ont pas fait de crâne.",
                    'effet' => ['mecanique' => 'relance_des_attaque_rates', 'frequence' => 'une_fois_par_usage']],
                ['nom' => 'Soif de sang', 'type' => 'actif',
                    'description' => 'Une fois par tour, abattre ta cible te rend ton action : tu frappes une seconde fois.',
                    'effet' => ['mecanique' => 'attaque_supplementaire_apres_kill', 'frequence' => 'une_fois_par_tour']],
            ]],
            ['categorie' => 'Carcasse', 'icone' => 'favorite', 'noeuds' => [
                ['nom' => 'Carcasse', 'type' => 'passif',
                    'description' => '+1 PV de Body maximum, définitivement acquis.',
                    'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Cuir de guerre', 'type' => 'passif',
                    'description' => "Chaque coup que tu subis t'inflige 1 dégât de moins, sans jamais descendre sous zéro.",
                    'effet' => ['mecanique' => 'reduction_degats', 'valeur' => 1]],
                ['nom' => 'Cicatrices', 'type' => 'passif',
                    'description' => "Tu résistes aux conditions Empoisonné et Envenimé, d'où qu'elles viennent.",
                    'effet' => ['mecanique' => 'resistance_condition', 'condition_nom' => ['Empoisonné', 'Envenimé']]],
            ]],
            ['categorie' => 'Charge', 'icone' => 'sprint', 'noeuds' => [
                ['nom' => 'Charge', 'type' => 'passif',
                    'description' => '+1 case à ton déplacement de base, à chacun de tes tours.',
                    'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Élan', 'type' => 'passif',
                    'description' => "+1 dé d'attaque si tu as parcouru au moins 3 cases avant de frapper.",
                    'effet' => ['mecanique' => 'bonus_des_attaque_apres_deplacement', 'valeur' => 1, 'cases' => 3]],
                ['nom' => 'Poigne brute', 'type' => 'deblocage',
                    'description' => 'Débloque les armures lourdes : la plate, que ta carte te refusait.',
                    'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['armure_lourde']]],
            ]],
        ],

        // ================================================================
        // EXPLORATEUR — la traque, le butin, l'endurance.
        // ================================================================
        'explorateur' => [
            ['categorie' => 'Traque', 'icone' => 'search', 'noeuds' => [
                ['nom' => 'Cartographe', 'type' => 'passif',
                    'description' => '+1 dé de Mind sur les jets de savoir et de repérage.',
                    'effet' => ['mecanique' => 'avantage_jet_mind', 'valeur' => 1, 'contexte' => 'savoir']],
                ['nom' => 'Crochetage', 'type' => 'actif',
                    'description' => 'Tu peux tenter de neutraliser un piège détecté à ton contact, par un jet de Body.',
                    'effet' => ['mecanique' => 'desamorcer_piege', 'jet' => 'body']],
                ['nom' => 'Lecture des lieux', 'type' => 'passif',
                    'description' => "Les portes secrètes des cases voisines se révèlent d'elles-mêmes, sans jet ni action.",
                    'effet' => ['mecanique' => 'detection_portes_secretes']],
            ]],
            ['categorie' => 'Butin', 'icone' => 'diamond', 'noeuds' => [
                ['nom' => 'Fouineur', 'type' => 'passif',
                    'description' => 'Tu fouilles 1 fois de plus que les autres dans chacune des salles du donjon.',
                    'effet' => ['mecanique' => 'fouille_supplementaire', 'valeur' => 1]],
                ['nom' => 'Œil du prix', 'type' => 'passif',
                    'description' => 'Le mobilier que tu fouilles rend un butin plus rare : 2 niveaux de mieux sur la table.',
                    'effet' => ['mecanique' => 'rarete_butin_amelioree', 'valeur' => 2]],
                ['nom' => 'Bourse pleine', 'type' => 'passif',
                    'description' => "L'étal du marché baisse ses prix de 15 % pour toute la compagnie.",
                    'effet' => ['mecanique' => 'remise_marche', 'valeur' => 15]],
            ]],
            ['categorie' => 'Endurance', 'icone' => 'hiking', 'noeuds' => [
                ['nom' => 'Endurance', 'type' => 'passif',
                    'description' => '+1 PV de Body maximum, définitivement acquis.',
                    'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Longue marche', 'type' => 'passif',
                    'description' => '+1 case à ton déplacement de base, à chacun de tes tours.',
                    'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Barda', 'type' => 'passif',
                    'description' => '+2 emplacements dans ton sac à dos, pour rapporter davantage du donjon.',
                    'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
            ]],
        ],
    ];

    /**
     * LES 17 CAPACITÉS DE CARTE, hors grille.
     *
     * Marquées `innee` : acquises d'emblée et gratuitement, parce qu'au plateau
     * la carte vient avec la figurine (décision de René, 2026-08-12). Leur
     * `description` est le TEXTE DE LA CARTE, traduit sans être réécrit — c'est
     * pourquoi elles n'obéissent pas au style « tu » de la grille.
     *
     * Elles n'ont ni colonne ni rang : on ne les achète pas, elles ne coûtent
     * aucun point, et les faire figurer dans la grille laisserait croire le
     * contraire. Les quatre classes historiques n'en ont aucune, et c'est voulu :
     * aucune carte officielle ne leur en donne.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private const INNEES = [
        'barde' => [
            // « you prefer to stay light on your feet so when you are wearing
            // no "metal" armor and carrying no shield you have 1 extra defend
            // die ». Un BONUS conditionnel, pas une interdiction — libre à lui
            // de s'alourdir.
            ['nom' => 'Léger sur ses pieds', 'type' => 'passif', 'description' => 'Sans armure métallique ni bouclier, +1 dé de défense.', 'effet' => ['mecanique' => 'bonus_des_defense_sans_metal', 'valeur' => 1]],
        ],

        'rogue' => [
            // Les trois capacités de carte (© 2022 Hasbro).
            ['nom' => 'Ambidextrie', 'type' => 'actif', 'description' => "Une fois par tour, en attaquant à l'épée courte ou à la dague, fais une attaque supplémentaire à la dague.", 'effet' => ['mecanique' => 'attaque_supplementaire_arme', 'armes' => ['Dague', 'Épée courte'], 'frequence' => 'une_fois_par_tour']],
            ['nom' => 'Mobilité de combat', 'type' => 'passif', 'description' => 'Tu peux traverser sans être vu les cases occupées par des monstres.', 'effet' => ['mecanique' => 'franchit_figures']],
            ['nom' => 'Frappe opportuniste', 'type' => 'passif', 'description' => "Une fois par tour, +1 dé de combat en attaquant un monstre adjacent à un autre héros.", 'effet' => ['mecanique' => 'bonus_des_attaque_flanc', 'valeur' => 1, 'frequence' => 'une_fois_par_tour']],
        ],

        'moine' => [
            // Les 4 Styles Élémentaires (© 2024 Hasbro) : QUATRE nœuds et non
            // huit, parce que ce sont quatre cartes RECTO-VERSO, et que c'est le
            // style entier qui s'épuise quand on active l'une de ses techniques.
            ['nom' => "Style de l'Air", 'type' => 'actif', 'description' => "Œil du Cyclone : une attaque à mains nues contre TOUS les ennemis adjacents, diagonales comprises. — ou — Dragon Bondissant : réussis automatiquement un saut de piège.", 'effet' => ['mecanique' => 'style_elementaire', 'element' => 'air', 'techniques' => [
                ['nom' => 'Œil du Cyclone', 'effet' => ['mecanique' => 'attaque_balayee', 'mains_nues' => true, 'diagonales' => true]],
                ['nom' => 'Dragon Bondissant', 'effet' => ['mecanique' => 'saut_piege_automatique']],
            ]]],
            ['nom' => 'Style de la Terre', 'type' => 'actif', 'description' => "Force de la Montagne : +2 dés d'attaque à mains nues. — ou — Parler à la Pierre : cherche pièges ET portes secrètes en une seule action.", 'effet' => ['mecanique' => 'style_elementaire', 'element' => 'terre', 'techniques' => [
                ['nom' => 'Force de la Montagne', 'effet' => ['mecanique' => 'bonus_des_attaque_mains_nues', 'valeur' => 2]],
                ['nom' => 'Parler à la Pierre', 'effet' => ['mecanique' => 'fouille_complete']],
            ]]],
            ['nom' => "Style de l'Eau", 'type' => 'actif', 'description' => "Vague Montante : scinde ton déplacement avant et après ton action. — ou — Torrent Tournoyant : annule entièrement les dégâts d'un coup subi.", 'effet' => ['mecanique' => 'style_elementaire', 'element' => 'eau', 'techniques' => [
                ['nom' => 'Vague Montante', 'effet' => ['mecanique' => 'deplacement_scinde']],
                ['nom' => 'Torrent Tournoyant', 'effet' => ['mecanique' => 'annule_degats', 'reaction' => true]],
            ]]],
            // ⚠ Le Feu est VERROUILLÉ tant que les trois autres ne sont pas
            // épuisés — c'est écrit sur la carte de règles, et c'est ce qui
            // fait du Moine une classe qui monte en puissance dans le combat.
            ['nom' => 'Style du Feu', 'type' => 'actif', 'description' => "Esprit Ardent : un rayon droit ou diagonal jusqu'au premier mur ou porte close, 2 PV à chaque ennemi traversé. — ou — Toucher du Brasier : 1 PV à un ennemi adjacent, puis 2 PV à la fin de son prochain tour. Exige que l'Air, la Terre et l'Eau soient épuisés.", 'effet' => ['mecanique' => 'style_elementaire', 'element' => 'feu', 'exige_epuises' => ['air', 'terre', 'eau'], 'techniques' => [
                ['nom' => 'Esprit Ardent', 'effet' => ['mecanique' => 'rayon', 'degats' => 2]],
                ['nom' => 'Toucher du Brasier', 'effet' => ['mecanique' => 'degat_differe', 'immediat' => 1, 'differe' => 2]],
            ]]],
        ],

        'chevalier' => [
            // Les trois capacités de carte (© 2023 Hasbro). Deux exigent le
            // BOUCLIER, que sa carte lui donne au départ.
            ['nom' => 'Inébranlable', 'type' => 'actif', 'description' => "Quand tes PV de Body tomberaient à 0, ils tombent à 1 à la place. Une fois par quête. Exige un bouclier.", 'effet' => ['mecanique' => 'plancher_pv', 'valeur' => 1, 'necessite_bouclier' => true, 'frequence' => 'une_fois_par_quete']],
            ['nom' => 'Parade au bouclier', 'type' => 'actif', 'description' => "Pendant le tour d'un ennemi, quand un héros À TON CONTACT subit des dégâts, annule-les. Une fois par quête. Exige un bouclier.", 'effet' => ['mecanique' => 'annule_degats_voisin', 'necessite_bouclier' => true, 'frequence' => 'une_fois_par_quete']],
            ['nom' => 'Défi du chevalier', 'type' => 'actif', 'description' => "Quand un monstre errant apparaît dans ta salle, tu deviens le fouilleur de la rencontre : il se place à ton contact et t'attaque aussitôt. Une fois par quête.", 'effet' => ['mecanique' => 'defi_errant', 'frequence' => 'une_fois_par_quete']],
        ],

        'berserker' => [
            // Les trois capacités de carte (© 2024 Hasbro). Deux exigent d'être
            // BLESSÉ : c'est une classe qui joue sur sa propre dégradation, et
            // le seuil est un plafond de PV, pas un plancher.
            ['nom' => 'Furie', 'type' => 'actif', 'description' => "En action, perds jusqu'à 2 PV de Body pour attaquer immédiatement, avec autant de dés d'attaque supplémentaires que de PV perdus. Une fois par quête.", 'effet' => ['mecanique' => 'sacrifice_pv_pour_des', 'max' => 2, 'frequence' => 'une_fois_par_quete']],
            ['nom' => 'Représailles', 'type' => 'actif', 'description' => "Quand un monstre adjacent te blesse, attaque-le immédiatement. Utilisable seulement à 5 PV de Body ou moins. Une fois par quête.", 'effet' => ['mecanique' => 'riposte', 'pv_body_max' => 5, 'frequence' => 'une_fois_par_quete']],
            ['nom' => 'Frénésie sanguinaire', 'type' => 'actif', 'description' => "En action, une seule attaque balayée contre tous les monstres adjacents et diagonaux. Utilisable seulement à 3 PV de Body ou moins. Une fois par quête.", 'effet' => ['mecanique' => 'attaque_balayee', 'diagonales' => true, 'pv_body_max' => 3, 'frequence' => 'une_fois_par_quete']],
        ],

        'explorateur' => [
            // Les trois capacités de carte (© 2024 Hasbro), toutes tournées
            // vers le DECK DE TRÉSOR et les pièges.
            ['nom' => 'Chasseur de trésor', 'type' => 'passif', 'description' => "Chaque fois qu'une carte de fouille te rapporte de l'or, tu en trouves 25 de plus.", 'effet' => ['mecanique' => 'bonus_or_tresor', 'valeur' => 25]],
            ['nom' => 'Sixième sens', 'type' => 'actif', 'description' => "Une fois par tour, quand tu tires une carte de piège, remets-la sous le paquet et tire-en une autre.", 'effet' => ['mecanique' => 'repiocher_carte_piege', 'frequence' => 'une_fois_par_tour']],
            ['nom' => 'Sens du piège', 'type' => 'passif', 'description' => "Une fois par tour, en arrivant sur une case voisine d'un piège, tu en es averti. Le piège reste caché et ne se déclenche pas.", 'effet' => ['mecanique' => 'alerte_pieges_adjacents', 'frequence' => 'une_fois_par_tour']],
        ],
    ];

    public function run(): void
    {
        foreach (self::GRILLE as $classe => $colonnes) {
            foreach ($colonnes as $indexColonne => $colonne) {
                $prerequisId = null; // ligne 1 : aucun prérequis

                foreach ($colonne['noeuds'] as $indexRang => $noeud) {
                    $competence = Competence::updateOrCreate(
                        ['classe' => $classe, 'nom' => $noeud['nom']],
                        [
                            'description' => $noeud['description'],
                            'type' => $noeud['type'],
                            'innee' => false,
                            'effet' => $noeud['effet'],
                            'categorie' => $colonne['categorie'],
                            'categorie_icone' => $colonne['icone'],
                            'colonne' => $indexColonne + 1,
                            'rang' => $indexRang + 1,
                            // Le prérequis se DÉDUIT de la position : le nœud
                            // juste au-dessus, dans la même colonne. Nommé à la
                            // main, rien n'empêchait une chaîne de traverser
                            // deux colonnes — ni de manquer.
                            'prerequis_id' => $prerequisId,
                        ],
                    );

                    $prerequisId = $competence->id;
                }
            }
        }

        foreach (self::INNEES as $classe => $cartes) {
            foreach ($cartes as $carte) {
                Competence::updateOrCreate(
                    ['classe' => $classe, 'nom' => $carte['nom']],
                    [
                        'description' => $carte['description'],
                        'type' => $carte['type'],
                        // Capacité de CARTE : acquise d'emblée, sans point.
                        'innee' => true,
                        'effet' => $carte['effet'],
                        // Hors grille : ni colonne, ni rang, ni prérequis.
                        'categorie' => null,
                        'categorie_icone' => null,
                        'colonne' => null,
                        'rang' => null,
                        'prerequis_id' => null,
                    ],
                );
            }
        }
    }

    /**
     * Noms des nœuds de la grille, par classe — lu par la migration qui retire
     * les nœuds de l'ancienne liste plate.
     *
     * @return array<string, list<string>>
     */
    public static function nomsDeLaGrille(): array
    {
        $noms = [];

        foreach (self::GRILLE as $classe => $colonnes) {
            foreach ($colonnes as $colonne) {
                foreach ($colonne['noeuds'] as $noeud) {
                    $noms[$classe][] = $noeud['nom'];
                }
            }
        }

        foreach (self::INNEES as $classe => $cartes) {
            foreach ($cartes as $carte) {
                $noms[$classe][] = $carte['nom'];
            }
        }

        return $noms;
    }
}
