<?php

namespace Database\Seeders;

use App\Models\Condition;
use Illuminate\Database\Seeder;

/**
 * Catalogue d'états (doc 01 §10).
 * duree_defaut en tours ; 0 = jusqu'à une condition de fin (résistance, relève, réveil…).
 * type mental → les monstres Mind 0 y sont immunisés (logique moteur).
 */
class ConditionSeeder extends Seeder
{
    /**
     * CE QU'EST CHAQUE CONDITION, en clair — ce que la fiche du héros (`EtatGroupe`)
     * publie avec son nom. « Vaporeux » et « Intangible » n'en avaient aucune
     * (verdict Morcar, 2026-10-09 § 5) : une condition qui se pose sans dire ce
     * qu'elle fait se lit comme un effet ignoré.
     *
     * Sources : les cartes transcrites (`reference/16_armurerie.md` §3bis pour les
     * sorts, `reference/18_extensions.md` §3 pour les répertoires de boîtes), les
     * livrets pour les objets et les monstres (`reference/18_extensions.md`), et les
     * commentaires de chaque entrée ci-dessous. Une condition sans description fait
     * échouer le seeder ; `CatalogueDescriptionsTest` le vérifie sur le catalogue.
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'Empoisonné' => "Perd 1 point de Body à chaque tour, pendant 3 tours. Le Sang robuste (nain) permet d'y résister.",
        'Étourdi' => "Perd son prochain tour : le héros ne joue pas, et la condition est consommée à ce tour.",
        'Apeuré' => "Ne lance plus qu'un dé d'attaque au maximum, jusqu'à la rupture du sort.",
        'Endormi' => "Il saute son tour et ne peut ni se déplacer ni agir. Une attaque subie le réveille.",
        'Commandé' => "Un Sorcier de Dread le contrôle : il joue son tour à sa place, jusqu'à la rupture du sort.",
        'Ralenti' => "Son déplacement est réduit de 2 cases, pendant 3 tours.",
        'Immobilisé' => "Il ne peut plus se déplacer. Le héros lui-même, ou un compagnon adjacent, peut dépenser une action pour détruire l'étreinte.",
        'Esprit brisé' => "Choc mental : il ne peut ni se déplacer ni attaquer, et ne défend qu'avec 1 dé de combat, jusqu'à la rupture du sort.",
        'Désigné' => "Tout monstre qui l'attaque lance un dé d'attaque de plus. Il n'est ni entravé ni affaibli.",
        'Caché' => "Invisible jusqu'au début de son prochain tour : il ne peut pas attaquer, mais personne ne peut l'attaquer et aucun sort ne l'atteint.",
        'Enchaîné' => "Il ne peut ni se déplacer ni attaquer jusqu'au début de son prochain tour. Il peut encore défendre et lancer des sorts.",
        'Vaporeux' => "Lors de son prochain déplacement, il traverse les cases occupées par les monstres sans jamais s'y arrêter. Ce n'est pas de l'invisibilité : il reste visible et attaquable. La condition dure jusqu'à la fin de son tour.",
        'Perce-armure' => "Sa prochaine attaque ignore la défense de la cible.",
        'Main sûre' => "Sa prochaine attaque lui permet de relancer ses dés d'attaque.",
        'Clairvoyance' => "Il voit les pièges et les portes secrètes dans sa ligne de vue, jusqu'à ce qu'il subisse au moins 1 point de Body de dégâts.",
        'Renforcé' => "Renforcé par un sort ou un objet : un bonus temporaire (dés d'attaque ou de défense en plus, relance, déplacement doublé…) qui dure jusqu'à la fin de l'effet qui l'a donné. Le détail exact de CE bonus est indiqué à côté, selon sa source.",
        'Protégé' => "Bonus de défense : il lance des dés de défense supplémentaires, jusqu'à la fin de l'effet qui l'a donné.",
        'Intangible' => "Lors de son déplacement, il traverse murs et roche, portes closes comprises. S'il termine son mouvement dans la roche, il tombe. La condition dure jusqu'à la fin de son tour.",
        'Tombé' => "À terre (0 point de Body) : il ne peut plus agir, mais il n'est jamais mort. Un allié peut le relever.",
        'Envenimé' => "Paralysé par le venin : il ne peut pas se déplacer, jusqu'à la fin du tour suivant.",
        'Agrippé' => "Étreint par une créature : il perd 2 points de Body automatiquement à chaque tour, et ne peut ni se déplacer ni agir, jusqu'à la mort de l'un des deux.",
        'Paralysé' => "Paralysé pendant 3 tours : il ne peut ni se déplacer, ni attaquer, ni défendre.",
        'Évanescent' => "Il ne peut que se déplacer et ouvrir les portes : ni attaque, ni fouille, ni désamorçage, ni sort. Il ne déclenche pas les pièges, et ni attaques ni sorts ne l'atteignent.",
        'Insensible au feu' => "La prochaine attaque de feu magique (sort, piège ou monstre) n'a aucun effet sur lui (Potion de résistance au feu).",
        'Résistance arcanique' => "Le prochain sort qui lui infligerait des dégâts est sans effet (Potion de résistance à la magie).",
        'Esprit vif' => "Il peut lancer deux sorts pendant ce tour (Potion de prédisposition magique).",
        "Pas d'araignée" => "Il traverse sans gêne les cases à mobilier, le terrain entravant, les figures et les fosses révélées, jusqu'au premier point de dégâts subi.",
        'Grésil aveuglant' => "Aveuglé par le grésil : il ne peut ni se déplacer, ni lancer de sorts, ni tirer à distance, et n'attaque que ce qui est à son contact, jusqu'au prochain tour du MJ.",
        'Ligoté' => "Pris dans des liens magiques : il ne peut ni se déplacer ni attaquer avant d'avoir tranché les liens (1 point de Body, 4 dés de défense). Il pare encore les autres coups.",
        'Possédé' => "Un monstre le prend en main : au prochain tour, le MJ joue ce héros à sa place. Il ne peut ni attaquer ni lancer de sorts.",
    ];

    public function run(): void
    {
        $conditions = [
            ['nom' => 'Empoisonné', 'type' => 'physique', 'duree_defaut' => 3,
                'effet' => ['degats_pv_body_par_tour' => 1, 'resistance_possible' => 'Sang robuste']],
            // ⚠ `duree_defaut` passe de 1 à 0 le 2026-09-04, quand
            // `perd_prochain_tour` a enfin reçu son lecteur. Un compteur d'un
            // tour et un effet consommé au tour suivant sont deux sorties pour
            // la même condition, et elles se COURAIENT APRÈS :
            // `decrementerDurees()` retirait l'Étourdi à la fin du round, juste
            // avant que l'ouverture du tour ne vienne le lire. Le tour n'était
            // jamais perdu. 0 = « pas de compteur, la sortie est un
            // déclencheur » — ici, la consommation du tour.
            ['nom' => 'Étourdi', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['perd_prochain_tour' => true]],
            // ⚠ « may ONLY USE 1 Attack die » (carte *Fear*, doc 09 §4bis) :
            // c'est un PLAFOND, pas un malus. `malus_des_attaque: 1` ne coûtait
            // qu'un dé au barbare qui en lance cinq, là où la carte le ramène au
            // même dé unique que tout le monde. La règle est en outre déjà
            // écrite ainsi côté MONSTRES depuis toujours — `terrifie` y fait
            // `min($des, 1)` dans `InstanceMonstre::apresConditions()`. Les deux
            // bords de la table disent enfin la même chose.
            ['nom' => 'Apeuré', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['des_attaque_max' => 1, 'interdit_avancer_vers_menace' => true, 'fin' => 'rupture_du_sort']],
            ['nom' => 'Endormi', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['hors_combat' => true, 'fin' => 'reveil_ou_attaque']],
            // ⚠ `duree_defaut` passe de 1 à 0 le 2026-09-04 : la carte *Command*
            // ne donne AUCUNE durée, elle donne une condition de rupture (1 d6
            // par point de Mind, un 6 libère). Un compteur d'un tour rendait le
            // sort le plus cruel du paquet strictement inoffensif.
            ['nom' => 'Commandé', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['controle_par_ennemi' => true, 'fin' => 'rupture_du_sort']],
            ['nom' => 'Ralenti', 'type' => 'physique', 'duree_defaut' => 3,
                'effet' => ['malus_deplacement' => 2]],
            // *Étreinte des Ronces* (carte *Creeping Grasp*) lui donne enfin son
            // premier PRODUCTEUR, et à son `fin: liberation` son premier
            // lecteur : « the targeted hero or another adjacent hero can spend
            // an action to destroy the vines ». La condition dormait au
            // catalogue depuis la création de la table, posée par personne.
            ['nom' => 'Immobilisé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['deplacement_interdit' => true, 'fin' => 'liberation']],
            // ---- Deux conditions nées des cartes de Dread (2026-09-04) ----
            //
            // *Choc Mental* (carte *Mind Blast*) : « This hero cannot move or
            // attack. THE HERO DEFENDS WITH 1 COMBAT DIE. »
            // ⚠ Ce n'est PAS *Paralysé*, et toute la différence tient en un mot :
            // le paralysé ne défend pas du tout (`defense_nulle`), celui-ci
            // défend à un dé. D'où `des_attaque_max: 0` (il ne frappe plus) et
            // `des_defense_max: 1`, tous deux lus par le seul calcul de défense
            // et d'attaque qui fasse foi.
            ['nom' => 'Esprit brisé', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['deplacement_interdit' => true, 'des_attaque_max' => 0,
                    'des_defense_max' => 1, 'fin' => 'rupture_du_sort']],
            // *Feux de l'Effroi* (carte *Dreadlights*) : « All monsters roll one
            // additional Attack die when attacking the affected hero. »
            // Le héros n'est ni entravé ni affaibli — il est DÉSIGNÉ, et c'est
            // l'adversaire qui gagne le dé. La seule condition du catalogue dont
            // l'effet s'applique à quelqu'un d'autre que son porteur.
            ['nom' => 'Désigné', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['bonus_des_attaque_ennemie' => 1, 'fin' => 'rupture_du_sort']],
            // ⚠ « Caché » a RETROUVÉ un producteur le 2026-10-06 : *Invisibility*
            // (Spells of Protection, Wizards of Morcar) — « makes you
            // invisible until the start of your next turn. While invisible,
            // you may not attack. You cannot be attacked and are immune to
            // all spells. » `inattaquable` (déjà là, inchangé depuis 2026-09-02
            // pour « Évanescent ») couvre « cannot be attacked » ; les deux
            // clés suivantes sont NEUVES pour cette carte : `attaque_interdite`
            // (« may not attack », lecteur `ResolveurTour::frapper()`) et
            // `immunite_sorts` (« immune to all spells », lecteur
            // `MoteurSorts::ciblesLegales()`). Rien d'autre n'est retiré au
            // héros — il fouille, désamorce et lance encore ses sorts.
            ['nom' => 'Caché', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['inattaquable' => true, 'attaque_interdite' => true,
                    'immunite_sorts' => true, 'fin' => 'prochain_tour']],
            // *Chains of Darkness* (Spells of Darkness) côté HÉROS, en tir ami
            // (S3) : « may not move or attack until the start of your next
            // turn. They may defend or cast spells. » Même DEUX clés que
            // « Caché » pour l'attaque et le déplacement, mais ni
            // `inattaquable` ni `immunite_sorts` — rien dans la carte n'empêche
            // de VISER ce héros, seulement de le laisser agir.
            ['nom' => 'Enchaîné', 'type' => 'mental', 'duree_defaut' => 1,
                'effet' => ['deplacement_interdit' => true, 'attaque_interdite' => true, 'fin' => 'prochain_tour']],
            // Voile de Brume : comme « Intangible » pour Traverser la Pierre,
            // c'est un MODE DE DÉPLACEMENT, et il faut que le joueur le lise
            // comme tel — « tu passes à travers les monstres », pas « on ne te
            // voit plus ».
            ['nom' => 'Vaporeux', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['franchit_figures' => true, 'fin' => 'fin_du_tour']],
            // ⚠ Deux conditions de plus le 2026-09-03, pour la même raison qui a
            // fait scinder « Renforcé » en trois : la Lame Fantôme et la Longue
            // épée de Fortune posaient toutes deux « Renforcé », dont l'effet
            // catalogue est `bonus_des: attaque`. Le joueur lisait donc « +dés
            // d'attaque » alors qu'il avait reçu une annulation de défense ou une
            // relance. Une condition doit dire ce qu'elle fait.
            ['nom' => 'Perce-armure', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['ignore_defense' => true, 'fin' => 'prochaine_attaque']],
            ['nom' => 'Main sûre', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['relance_attaque' => true, 'fin' => 'prochaine_attaque']],
            // Potion de vision (Elfe) : voir les pièges et les portes secrètes
            // en ligne de vue, « until the Elf suffers at least 1 Body Point of
            // damage ». La fin est portée par la `duree` de la potion
            // (`premier_degat_subi`), pas par un compteur de tours — d'où 0.
            ['nom' => 'Clairvoyance', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['revele_pieges_et_portes_en_vue' => true, 'fin' => 'premier_degat_subi']],
            // ⚠ Trois conditions là où il n'y en avait qu'UNE. « Renforcé »
            // couvrait aussi bien un bonus d'attaque qu'un bonus de défense
            // qu'un mode de déplacement — son propre effet l'avouait :
            // `attaque_ou_defense_selon_source`. Un joueur voyant « Renforcé »
            // sur sa fiche ne pouvait pas savoir s'il frappait plus fort ou
            // s'il encaissait mieux, ni quand ça expirait (les durées diffèrent
            // : prochaine attaque vs premier dégât subi). Remonté par un joueur
            // en campagne réelle, 2026-08-20.
            ['nom' => 'Renforcé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['bonus_des' => 'attaque', 'fin' => 'duree_du_sort']],
            ['nom' => 'Protégé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['bonus_des' => 'defense', 'fin' => 'duree_du_sort']],
            // Traverser la Pierre : ce n'est pas un renforcement mais un MODE
            // DE DÉPLACEMENT (la roche et les portes closes cessent de barrer
            // le chemin). L'appeler « Renforcé » ne décrivait rien.
            ['nom' => 'Intangible', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['franchit_mur' => true, 'fin' => 'fin_du_tour']],
            // « Tombé, jamais mort » (mode Story de Jungles of Delthrak, décision de
            // René, 2026-10-09 — `docs/regles/vocabulaires-effets.md`) : aucune clé de
            // mort ici. `mort_si_non_releve` a été retirée : elle n'avait aucun lecteur.
            ['nom' => 'Tombé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['hors_combat' => true, 'occupe_sa_case' => true, 'relevable' => true, 'fin' => 'releve_ou_fin_de_combat']],
            // Venin (Jungles of Delthrak, p. 48) : « dégât = paralysie, jet de
            // 1 dé rouge pour résister sur 5-6, sinon jeton venin jusqu'à la
            // fin du tour suivant ». `deplacement_interdit` est CÂBLÉ depuis le
            // 2026-08-10 — MenuMoteur retire « Se déplacer », ResolveurTour
            // refuse le mouvement —, ce qui rend du même coup `Immobilisé`
            // réellement immobilisant.
            ['nom' => 'Envenimé', 'type' => 'physique', 'duree_defaut' => 1,
                'effet' => ['deplacement_interdit' => true, 'fin' => 'prochain_tour']],

            // Étreinte du Yéti (The Frozen Horror, doc 18 §2) : « dès qu'il
            // inflige au moins 1 Body Point, agrippe le héros dans une étreinte
            // qui inflige 2 Body Points AUTOMATIQUES (sans jet de défense, sans
            // action possible pour la victime) à chaque tour suivant du MJ,
            // jusqu'à la mort du héros ou celle du Yéti ». Rien de neuf à
            // câbler : `degats_pv_body_par_tour` saigne déjà (poison,
            // `ResolveurTour::saignerParConditions()`), et `deplacement_interdit`
            // + `action_interdite` privent déjà des deux créneaux (Paralysé).
            // `degats_pv_body_par_tour_source` distingue la SOURCE du poison —
            // sans quoi `degats_subis` mélangerait les deux jauges de saignement
            // et la Plume anti-poison rendrait à l'un des PV perdus à l'autre.
            // `fin: liberation` reste DESCRIPTIF (comme pour Immobilisé) : la
            // libération réelle est portée par `saignerParConditions()` et
            // `ResolveurTour::libererEtreintesOrphelines()`, pas par ce mot.
            ['nom' => 'Agrippé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['degats_pv_body_par_tour' => 2, 'degats_pv_body_par_tour_source' => 'etreinte',
                    'deplacement_interdit' => true, 'action_interdite' => true, 'fin' => 'liberation']],

            // Flamme hypnotique (répertoire elfique, © 2023) : « paralyzed for
            // 3 turns — unable to move, attack, or defend ». Les trois d'un
            // coup, et `defense_nulle` est une SUPPRESSION, pas un malus : la
            // figure ne lance aucun dé.
            ['nom' => 'Paralysé', 'type' => 'mental', 'duree_defaut' => 3,
                'effet' => ['deplacement_interdit' => true, 'action_interdite' => true,
                    'defense_nulle' => true, 'fin' => 'duree']],

            // Évanescence (répertoire elfique) : « The hero can only move and
            // open doors. They cannot attack, search, disarm, cast spells,
            // spring traps, or be affected by attacks or spells. »
            // ⚠ Le contraire de Paralysé sur le déplacement : il marche, mais
            // ne peut RIEN faire d'autre — et rien ne peut le toucher.
            ['nom' => 'Évanescent', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['action_interdite' => true, 'inattaquable' => true,
                    'ignore_pieges' => true, 'fin' => 'jet_de_deplacement_eleve']],

            // ===== Wizards of Morcar (doc 18, 2026-10-06) — trois conditions
            // DÉDIÉES plutôt qu'un « Renforcé » générique : c'est précisément
            // la confusion qu'une carte de joueur a signalée en partie réelle
            // (2026-08-20, voir « Renforcé »/« Protégé » ci-dessus) — un
            // buveur de Potion of Fire Resistance ne doit pas lire « Renforcé »
            // sur sa fiche en se demandant s'il frappe plus fort. L'`effet`
            // ci-dessous est purement d'AFFICHAGE (même patron que
            // « Clairvoyance ») : le mécanisme réel est relu sur l'OBJET
            // source par `MoteurSorts::effetSortSource()`, jamais sur cette
            // ligne.
            ['nom' => 'Insensible au feu', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['immunite_degat' => 'feu', 'fin' => 'premier_coup_absorbe']],
            ['nom' => 'Résistance arcanique', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['annule_prochain_sort_degats' => true, 'fin' => 'premier_sort_absorbe']],
            ['nom' => 'Esprit vif', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['second_sort_par_tour' => true, 'fin' => 'duree_du_sort']],

            // Spiderstep Elixir (Jungles of Delthrak, p. 2) : « move unaffected
            // through squares containing revealed pit traps, hindering terrain,
            // furniture, and monsters. This potion's effects end if you suffer
            // any amount of damage. » Même patron que « Clairvoyance » : l'`effet`
            // est d'AFFICHAGE, les mécanismes sont relus sur l'OBJET source
            // (`MoteurSorts::mobilierFranchi()`, `terrainEntravantIgnore()`,
            // `franchitFigures()`, `MoteurPieges::declencher()`), et la fin est
            // portée par la `duree` de la potion (`premier_degat_subi`).
            ['nom' => "Pas d'araignée", 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['franchit_mobilier' => true, 'ignore_terrain_entravant' => true,
                    'franchit_figures' => true, 'franchit_fosses_revelees' => true, 'fin' => 'premier_degat_subi']],

            // ===== Wizards of Morcar — les Sorciers du Dread (2026-10-08) =====
            //
            // *Blinding Sleet* (Storm Master) : « Characters in that room may not
            // move, make ranged attacks or cast spells until the start of Zargon's
            // next turn. Those characters can only attack and defend against
            // adjacent enemies. »
            // Trois interdits, TROIS lecteurs : `deplacement_interdit`
            // (MenuMoteur/ResolveurTour, depuis 2026-08-10), `sorts_interdits`
            // (`MoteurSorts::sortsInterdits()`, lu par MenuMoteur ET le résolveur) et
            // `tir_interdit` (`MoteurSorts::tirInterdit()`, lu par
            // `ciblesPourArme()` ET `frapper()`). `fin: debut_tour_mj` : levée en
            // tête de `phaseMonstres()` (`leverConditionsDeDebutDeTourMJ()`).
            // ⚠ « …and DEFEND against adjacent enemies » n'est PAS porté, et c'est
            // écrit : la condition tombe AVANT le tour de Zargon, donc aucun monstre
            // n'attaque un héros sous grésil — la phrase n'a rien à régir dans ce
            // moteur (aucun lecteur ne la lirait jamais). Une clé qui ne servirait
            // jamais est exactement ce que le projet refuse.
            ['nom' => 'Grésil aveuglant', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['deplacement_interdit' => true, 'sorts_interdits' => true,
                    'tir_interdit' => true, 'fin' => 'debut_tour_mj']],

            // *Strands of Binding* (High Mage) : « They entangle one target who may
            // not move or attack until the tendrils are destroyed. Tendrils have
            // 1 Body Point and roll 4 Defend dice. The target may defend against
            // other attacks. » Pas de `defense_nulle` : la cible PARE les autres
            // coups. `liens_defense` (4) est lu par `ResolveurTour::resoudreAttaqueLiens()`
            // et par le menu (`MoteurSorts::liensDe()`) ; `attaque_interdite` par
            // `MoteurSorts::raisonAttaqueInterdite()`, avec son motif propre.
            ['nom' => 'Ligoté', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['deplacement_interdit' => true, 'attaque_interdite' => true,
                    'raison_attaque' => '{nom} est ligoté par des liens magiques : il ne peut pas attaquer avant de les avoir tranchés.',
                    'liens_defense' => 4, 'fin' => 'liens_detruits']],

            // *Possess* (High Mage) : « Zargon will move this figure on its next
            // turn. The affected figure may not attack or cast spells. » Lue par
            // NOM comme *Commandé* (`ResolveurTour` → `MoteurDread::jouerHerosPossede()`),
            // qui prend le tour du héros et retire la condition : un tour, pas plus.
            ['nom' => 'Possédé', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['controle_par_ennemi' => true, 'fin' => 'prochain_tour_du_heros']],
        ];

        foreach ($conditions as $condition) {
            // Une condition sans description ne s'écrit pas : le catalogue n'a pas de trou.
            $description = self::DESCRIPTIONS[$condition['nom']]
                ?? throw new \LogicException("Condition « {$condition['nom']} » sans description (ConditionSeeder::DESCRIPTIONS).");

            Condition::updateOrCreate(['nom' => $condition['nom']], $condition + ['description' => $description]);
        }
    }
}
