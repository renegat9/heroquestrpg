<?php

namespace Database\Seeders;

use App\Models\Competence;
use Illuminate\Database\Seeder;

/**
 * Arbres de compétences — brouillon de départ du doc 01 §6 (~5-6 nœuds par héros).
 * Les clés `effet` sont des identifiants mécaniques destinés au moteur.
 */
class CompetenceSeeder extends Seeder
{
    public function run(): void
    {
        $arbres = [
            'barbare' => [
                ['nom' => 'Carrure', 'type' => 'passif', 'description' => '+1 Point de Body (PV Body max).', 'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Coup puissant', 'type' => 'actif', 'description' => "Une fois par usage, relance les dés d'attaque ratés.", 'effet' => ['mecanique' => 'relance_des_attaque_rates', 'frequence' => 'une_fois_par_usage']],
                // Nom CONSERVÉ volontairement : le seeder identifie un nœud par (classe, nom),
                // le renommer en créerait un nouveau et détacherait celui des héros
                // qui l'ont déjà acquis. Seule sa portée se réduit : le barbare manie
                // désormais les armes à deux mains sans lui.
                ['nom' => 'Maîtrise lourde', 'type' => 'deblocage', 'description' => 'Débloque les armures lourdes (plates).', 'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['armure_lourde']]],
                ['nom' => 'Intimidation', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind sociaux fondés sur la peur.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'social_peur']],
                ['nom' => 'Frénésie', 'type' => 'actif', 'description' => "+1 dé d'attaque tant que tes PV de Body sont sous la moitié.", 'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
            ],
            'nain' => [
                ['nom' => 'Œil du mineur', 'type' => 'passif', 'description' => 'Détecte automatiquement les pièges sur les cases adjacentes.', 'effet' => ['mecanique' => 'detection_pieges_adjacents', 'automatique' => true]],
                ['nom' => 'Désamorçage', 'type' => 'actif', 'description' => 'Tente de neutraliser un piège détecté (jet de Body).', 'effet' => ['mecanique' => 'desamorcer_piege', 'jet' => 'body']],
                ['nom' => 'Garde tenace', 'type' => 'passif', 'description' => "+1 dé de défense contre la première attaque d'un combat.", 'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Forge', 'type' => 'deblocage', 'description' => 'Au hub, améliore définitivement un équipement (+1 dé ou une propriété).', 'effet' => ['mecanique' => 'forge_amelioration', 'lieu' => 'hub', 'catalogue' => 'forge_ameliorations']],
                // Couvre les DEUX poisons du jeu. Il n'en existait qu'un quand
                // ce nœud a été écrit ; le venin des créatures de Jungles of
                // Delthrak (condition « Envenimé ») est arrivé le 2026-08-09, et
                // un talent nommé « résistance au poison » qui laisse un serpent
                // paralyser son porteur est une promesse rompue.
                ['nom' => 'Sang robuste', 'type' => 'passif', 'description' => 'Résistance aux conditions Empoisonné et Envenimé.', 'effet' => ['mecanique' => 'resistance_condition', 'condition_nom' => ['Empoisonné', 'Envenimé']]],
                ['nom' => 'Solides épaules', 'type' => 'passif', 'description' => '+2 emplacements de sac à dos.', 'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
                // Le nain porte déjà l'armure lourde de naissance (ClasseHerosSeeder) ;
                // ce nœud n'ouvre que les armes à deux mains, pour que les grosses
                // armes restent la signature du barbare.
                ['nom' => 'Poigne de forgeron', 'type' => 'deblocage', 'description' => 'Débloque les armes à deux mains (haches et marteaux de bataille).', 'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['arme_deux_mains']]],
            ],
            'elfe' => [
                ['nom' => 'Pas léger', 'type' => 'passif', 'description' => '+1 en déplacement.', 'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Première magie', 'type' => 'deblocage', 'description' => "Ouvre 1 emplacement de sort (les 3 sorts d'un élément).", 'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1]],
                ['nom' => 'Sens aiguisés', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind de perception.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'perception']],
                ['nom' => 'Tir précis', 'type' => 'actif', 'description' => 'Avantage sur une attaque à distance.', 'effet' => ['mecanique' => 'avantage_attaque', 'contexte' => 'distance']],
                ['nom' => 'Second élément', 'type' => 'deblocage', 'description' => 'Apprends un domaine de sort supplémentaire.', 'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1], 'prerequis' => 'Première magie'],
            ],
            'magicien' => [
                ['nom' => 'Réserve arcanique', 'type' => 'passif', 'description' => 'Permet de lancer un second sort au cours du même tour (une fois par tour).', 'effet' => ['mecanique' => 'sort_supplementaire_par_tour', 'frequence' => 'une_fois_par_tour']],
                ['nom' => 'Écoles', 'type' => 'deblocage', 'description' => 'Accès à de nouveaux domaines de magie (Feu, Eau, Terre, Air).', 'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1, 'repetable' => true]],
                ['nom' => 'Concentration', 'type' => 'actif', 'description' => 'Une fois par quête, sacrifie ton tour pour récupérer un sort épuisé.', 'effet' => ['mecanique' => 'recuperer_sort_epuise', 'cout' => 'tour_complet', 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Contresort', 'type' => 'actif', 'description' => 'Annule un effet magique (jet de Mind).', 'effet' => ['mecanique' => 'annuler_effet_magique', 'jet' => 'mind']],
                ['nom' => 'Érudition', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind de savoir et d\'érudition.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'savoir']],
                // Déblocages d'équipement (doc 01 §7) : le magicien du plateau ne
                // porte ni armure ni arme de mêlée sérieuse. Ces deux nœuds lèvent
                // chacune des limites, au prix d'un point de compétence — donc au
                // détriment de sa progression magique. Un vrai arbitrage.
                ['nom' => 'Cuir d\'apprenti', 'type' => 'deblocage', 'description' => 'Débloque les armures légères (casque, cotte de mailles).', 'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['armure_legere']]],
                ['nom' => 'Escrime de fortune', 'type' => 'deblocage', 'description' => 'Débloque les armes de mêlée courantes (épées, lance).', 'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['arme_courante']]],
            ],

            // ================================================================
            // LES 8 CLASSES D'EXTENSION (2026-08-12)
            //
            // Chacune reçoit DEUX choses de nature différente :
            //
            //  1. Ses **capacités de carte**, marquées `innee` : acquises
            //     d'emblée et gratuitement, parce qu'au plateau la carte vient
            //     avec la figurine (décision de René). Leur `description` est
            //     le TEXTE DE LA CARTE, traduit sans être réécrit.
            //  2. Un **arbre de talents de 5 nœuds**, inventé, à parité avec
            //     les quatre classes historiques.
            //
            // ⚠ Règle que ces arbres respectent strictement : ils n'emploient
            // QUE des mécaniques ayant déjà un lecteur (voir
            // CompetenceController::EFFETS_PASSIFS et le moteur). Inventer
            // 40 clés neuves pour 40 nœuds décoratifs est exactement ce que le
            // projet retire depuis des semaines. Toute l'invention mécanique
            // est concentrée dans les capacités innées, qui reçoivent chacune
            // leur lecteur.
            // ================================================================

            'barde' => [
                // Capacité de carte : « you prefer to stay light on your feet
                // so when you are wearing no "metal" armor and carrying no
                // shield you have 1 extra defend die ». Un BONUS conditionnel,
                // pas une interdiction — libre à lui de s'alourdir.
                ['nom' => 'Léger sur ses pieds', 'type' => 'passif', 'innee' => true, 'description' => "Sans armure métallique ni bouclier, +1 dé de défense.", 'effet' => ['mecanique' => 'bonus_des_defense_sans_metal', 'valeur' => 1]],

                ['nom' => 'Marche entraînante', 'type' => 'passif', 'description' => '+1 case de déplacement de base.', 'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Refrain vaillant', 'type' => 'passif', 'description' => "+1 dé de défense contre la première attaque d'un combat.", 'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Rappel', 'type' => 'actif', 'description' => 'Une fois par quête, sacrifie ton tour pour récupérer un sort épuisé.', 'effet' => ['mecanique' => 'recuperer_sort_epuise', 'cout' => 'tour_complet', 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Beau parleur', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind sociaux fondés sur la peur.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'social_peur']],
                ['nom' => 'Second couplet', 'type' => 'actif', 'description' => 'Lance un second sort dans le même tour.', 'effet' => ['mecanique' => 'sort_supplementaire_par_tour', 'valeur' => 1]],
            ],

            'druide' => [
                ['nom' => 'Vigueur sylvestre', 'type' => 'passif', 'description' => '+1 Point de Body (PV Body max).', 'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Pas de la forêt', 'type' => 'passif', 'description' => '+1 case de déplacement de base.', 'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Écorce', 'type' => 'passif', 'description' => "+1 dé de défense contre la première attaque d'un combat.", 'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Sève tenace', 'type' => 'passif', 'description' => 'Résistance aux conditions Empoisonné et Envenimé.', 'effet' => ['mecanique' => 'resistance_condition', 'condition_nom' => ['Empoisonné', 'Envenimé']]],
                ['nom' => 'Communion', 'type' => 'actif', 'description' => 'Une fois par quête, sacrifie ton tour pour récupérer un sort épuisé.', 'effet' => ['mecanique' => 'recuperer_sort_epuise', 'cout' => 'tour_complet', 'frequence' => 'une_fois_par_quete']],
            ],

            'warlock' => [
                ['nom' => 'Pacte', 'type' => 'passif', 'description' => '+1 Point de Mind (PV Mind max).', 'effet' => ['mecanique' => 'bonus_pv_mind_max', 'valeur' => 1]],
                ['nom' => 'Volonté noire', 'type' => 'passif', 'description' => "+1 à l'attribut Mind.", 'effet' => ['mecanique' => 'bonus_attribut_mind', 'valeur' => 1]],
                ['nom' => 'Contresort', 'type' => 'actif', 'description' => 'Annule un effet magique (jet de Mind).', 'effet' => ['mecanique' => 'annuler_effet_magique', 'jet' => 'mind']],
                ['nom' => 'Réserve damnée', 'type' => 'actif', 'description' => 'Lance un second sort dans le même tour.', 'effet' => ['mecanique' => 'sort_supplementaire_par_tour', 'valeur' => 1]],
                ['nom' => "Cuir d'initié", 'type' => 'deblocage', 'description' => 'Débloque les armures légères (casque, cotte de mailles).', 'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['armure_legere']]],
            ],

            'rogue' => [
                // Les trois capacités de carte (© 2022 Hasbro).
                ['nom' => 'Ambidextrie', 'type' => 'actif', 'innee' => true, 'description' => "Une fois par tour, en attaquant à l'épée courte ou à la dague, fais une attaque supplémentaire à la dague.", 'effet' => ['mecanique' => 'attaque_supplementaire_arme', 'armes' => ['Dague', 'Épée courte'], 'frequence' => 'une_fois_par_tour']],
                ['nom' => 'Mobilité de combat', 'type' => 'passif', 'innee' => true, 'description' => 'Tu peux traverser sans être vu les cases occupées par des monstres.', 'effet' => ['mecanique' => 'franchit_figures']],
                ['nom' => 'Frappe opportuniste', 'type' => 'passif', 'innee' => true, 'description' => "Une fois par tour, +1 dé de combat en attaquant un monstre adjacent à un autre héros.", 'effet' => ['mecanique' => 'bonus_des_attaque_flanc', 'valeur' => 1, 'frequence' => 'une_fois_par_tour']],

                ['nom' => 'Pas léger', 'type' => 'passif', 'description' => '+1 case de déplacement de base.', 'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Doigts de fée', 'type' => 'actif', 'description' => 'Tente de neutraliser un piège détecté (jet de Body).', 'effet' => ['mecanique' => 'desamorcer_piege', 'jet' => 'body']],
                ['nom' => 'Coup bas', 'type' => 'passif', 'description' => "+1 dé d'attaque tant que tes PV de Body sont sous la moitié.", 'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
                ['nom' => 'Poches profondes', 'type' => 'passif', 'description' => '+2 emplacements de sac à dos.', 'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
                ['nom' => 'Esquive', 'type' => 'passif', 'description' => "+1 dé de défense contre la première attaque d'un combat.", 'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
            ],

            'moine' => [
                // Les 4 Styles Élémentaires (© 2024 Hasbro) : QUATRE nœuds et
                // non huit, parce que ce sont quatre cartes RECTO-VERSO, et
                // que c'est le style entier qui s'épuise quand on active l'une
                // de ses deux techniques.
                ['nom' => "Style de l'Air", 'type' => 'actif', 'innee' => true, 'description' => "Œil du Cyclone : une attaque à mains nues contre TOUS les ennemis adjacents, diagonales comprises. — ou — Dragon Bondissant : réussis automatiquement un saut de piège.", 'effet' => ['mecanique' => 'style_elementaire', 'element' => 'air', 'techniques' => [
                    ['nom' => 'Œil du Cyclone', 'effet' => ['mecanique' => 'attaque_balayee', 'mains_nues' => true, 'diagonales' => true]],
                    ['nom' => 'Dragon Bondissant', 'effet' => ['mecanique' => 'saut_piege_automatique']],
                ]]],
                ['nom' => 'Style de la Terre', 'type' => 'actif', 'innee' => true, 'description' => "Force de la Montagne : +2 dés d'attaque à mains nues. — ou — Parler à la Pierre : cherche pièges ET portes secrètes en une seule action.", 'effet' => ['mecanique' => 'style_elementaire', 'element' => 'terre', 'techniques' => [
                    ['nom' => 'Force de la Montagne', 'effet' => ['mecanique' => 'bonus_des_attaque_mains_nues', 'valeur' => 2]],
                    ['nom' => 'Parler à la Pierre', 'effet' => ['mecanique' => 'fouille_complete']],
                ]]],
                ['nom' => "Style de l'Eau", 'type' => 'actif', 'innee' => true, 'description' => "Vague Montante : scinde ton déplacement avant et après ton action. — ou — Torrent Tournoyant : annule entièrement les dégâts d'un coup subi.", 'effet' => ['mecanique' => 'style_elementaire', 'element' => 'eau', 'techniques' => [
                    ['nom' => 'Vague Montante', 'effet' => ['mecanique' => 'deplacement_scinde']],
                    ['nom' => 'Torrent Tournoyant', 'effet' => ['mecanique' => 'annule_degats', 'reaction' => true]],
                ]]],
                // ⚠ Le Feu est VERROUILLÉ tant que les trois autres ne sont pas
                // épuisés — c'est écrit sur la carte de règles, et c'est ce qui
                // fait du Moine une classe qui monte en puissance dans le combat.
                ['nom' => 'Style du Feu', 'type' => 'actif', 'innee' => true, 'description' => "Esprit Ardent : un rayon droit ou diagonal jusqu'au premier mur ou porte close, 2 PV à chaque ennemi traversé. — ou — Toucher du Brasier : 1 PV à un ennemi adjacent, puis 2 PV à la fin de son prochain tour. Exige que l'Air, la Terre et l'Eau soient épuisés.", 'effet' => ['mecanique' => 'style_elementaire', 'element' => 'feu', 'exige_epuises' => ['air', 'terre', 'eau'], 'techniques' => [
                    ['nom' => 'Esprit Ardent', 'effet' => ['mecanique' => 'rayon', 'degats' => 2]],
                    ['nom' => 'Toucher du Brasier', 'effet' => ['mecanique' => 'degat_differe', 'immediat' => 1, 'differe' => 2]],
                ]]],

                ['nom' => 'Souffle discipliné', 'type' => 'passif', 'description' => '+1 Point de Body (PV Body max).', 'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Corps aguerri', 'type' => 'passif', 'description' => "+1 à l'attribut Body.", 'effet' => ['mecanique' => 'bonus_attribut_body', 'valeur' => 1]],
                ['nom' => 'Course du vent', 'type' => 'passif', 'description' => '+1 case de déplacement de base.', 'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Méditation', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind de savoir et de concentration.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'savoir']],
                ['nom' => 'Poing de fer', 'type' => 'passif', 'description' => "+1 dé d'attaque tant que tes PV de Body sont sous la moitié.", 'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
            ],

            'chevalier' => [
                // Les trois capacités de carte (© 2023 Hasbro). Deux exigent
                // le BOUCLIER, que sa carte lui donne au départ.
                ['nom' => 'Inébranlable', 'type' => 'actif', 'innee' => true, 'description' => "Quand tes PV de Body tomberaient à 0, ils tombent à 1 à la place. Une fois par quête. Exige un bouclier.", 'effet' => ['mecanique' => 'plancher_pv', 'valeur' => 1, 'necessite_bouclier' => true, 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Parade au bouclier', 'type' => 'actif', 'innee' => true, 'description' => "Pendant le tour d'un ennemi, quand un héros À TON CONTACT subit des dégâts, annule-les. Une fois par quête. Exige un bouclier.", 'effet' => ['mecanique' => 'annule_degats_voisin', 'necessite_bouclier' => true, 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Défi du chevalier', 'type' => 'actif', 'innee' => true, 'description' => "Quand un monstre errant apparaît dans ta salle, tu deviens le fouilleur de la rencontre : il se place à ton contact et t'attaque aussitôt. Une fois par quête.", 'effet' => ['mecanique' => 'defi_errant', 'frequence' => 'une_fois_par_quete']],

                ['nom' => 'Serment', 'type' => 'passif', 'description' => '+1 Point de Body (PV Body max).', 'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Garde haute', 'type' => 'passif', 'description' => "+1 dé de défense contre la première attaque d'un combat.", 'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Prestance', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind sociaux fondés sur la peur.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'social_peur']],
                ['nom' => 'Bras d\'acier', 'type' => 'actif', 'description' => "Une fois par usage, relance les dés d'attaque ratés.", 'effet' => ['mecanique' => 'relance_des_attaque_rates', 'frequence' => 'une_fois_par_usage']],
                ['nom' => 'Barda du croisé', 'type' => 'passif', 'description' => '+2 emplacements de sac à dos.', 'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
            ],

            'berserker' => [
                // Les trois capacités de carte (© 2024 Hasbro). Deux exigent
                // d'être BLESSÉ : c'est une classe qui joue sur sa propre
                // dégradation, et le seuil est un plafond de PV, pas un plancher.
                ['nom' => 'Furie', 'type' => 'actif', 'innee' => true, 'description' => "En action, perds jusqu'à 2 PV de Body pour attaquer immédiatement, avec autant de dés d'attaque supplémentaires que de PV perdus. Une fois par quête.", 'effet' => ['mecanique' => 'sacrifice_pv_pour_des', 'max' => 2, 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Représailles', 'type' => 'actif', 'innee' => true, 'description' => "Quand un monstre adjacent te blesse, attaque-le immédiatement. Utilisable seulement à 5 PV de Body ou moins. Une fois par quête.", 'effet' => ['mecanique' => 'riposte', 'pv_body_max' => 5, 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Frénésie sanguinaire', 'type' => 'actif', 'innee' => true, 'description' => "En action, une seule attaque balayée contre tous les monstres adjacents et diagonaux. Utilisable seulement à 3 PV de Body ou moins. Une fois par quête.", 'effet' => ['mecanique' => 'attaque_balayee', 'diagonales' => true, 'pv_body_max' => 3, 'frequence' => 'une_fois_par_quete']],

                ['nom' => 'Carcasse', 'type' => 'passif', 'description' => '+1 Point de Body (PV Body max).', 'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Rage froide', 'type' => 'passif', 'description' => "+1 dé d'attaque tant que tes PV de Body sont sous la moitié.", 'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
                ['nom' => 'Charge', 'type' => 'passif', 'description' => '+1 case de déplacement de base.', 'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Coup sauvage', 'type' => 'actif', 'description' => "Une fois par usage, relance les dés d'attaque ratés.", 'effet' => ['mecanique' => 'relance_des_attaque_rates', 'frequence' => 'une_fois_par_usage']],
                ['nom' => 'Poigne brute', 'type' => 'deblocage', 'description' => 'Débloque les armures lourdes (plates).', 'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['armure_lourde']]],
            ],

            'explorateur' => [
                // Les trois capacités de carte (© 2024 Hasbro), toutes tournées
                // vers le DECK DE TRÉSOR et les pièges.
                ['nom' => 'Chasseur de trésor', 'type' => 'passif', 'innee' => true, 'description' => "Chaque fois qu'une carte de fouille te rapporte de l'or, tu en trouves 25 de plus.", 'effet' => ['mecanique' => 'bonus_or_tresor', 'valeur' => 25]],
                ['nom' => 'Sixième sens', 'type' => 'actif', 'innee' => true, 'description' => "Une fois par tour, quand tu tires une carte de piège, remets-la sous le paquet et tire-en une autre.", 'effet' => ['mecanique' => 'repiocher_carte_piege', 'frequence' => 'une_fois_par_tour']],
                ['nom' => 'Sens du piège', 'type' => 'passif', 'innee' => true, 'description' => "Une fois par tour, en arrivant sur une case voisine d'un piège, tu en es averti. Le piège reste caché et ne se déclenche pas.", 'effet' => ['mecanique' => 'alerte_pieges_adjacents', 'frequence' => 'une_fois_par_tour']],

                ['nom' => 'Endurance', 'type' => 'passif', 'description' => '+1 Point de Body (PV Body max).', 'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Cartographe', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind de savoir et de repérage.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'savoir']],
                ['nom' => 'Crochetage', 'type' => 'actif', 'description' => 'Tente de neutraliser un piège détecté (jet de Body).', 'effet' => ['mecanique' => 'desamorcer_piege', 'jet' => 'body']],
                ['nom' => 'Barda', 'type' => 'passif', 'description' => '+2 emplacements de sac à dos.', 'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
                ['nom' => 'Longue marche', 'type' => 'passif', 'description' => '+1 case de déplacement de base.', 'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
            ],
        ];

        foreach ($arbres as $classe => $noeuds) {
            foreach ($noeuds as $noeud) {
                $prerequisId = null;

                if (isset($noeud['prerequis'])) {
                    $prerequisId = Competence::where('classe', $classe)
                        ->where('nom', $noeud['prerequis'])
                        ->value('id');
                }

                Competence::updateOrCreate(
                    ['classe' => $classe, 'nom' => $noeud['nom']],
                    [
                        'description' => $noeud['description'] ?? null,
                        'type' => $noeud['type'],
                        // Capacité de CARTE : acquise d'emblée, sans point.
                        'innee' => (bool) ($noeud['innee'] ?? false),
                        'effet' => $noeud['effet'],
                        'prerequis_id' => $prerequisId,
                    ],
                );
            }
        }
    }
}
