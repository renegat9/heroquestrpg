<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * VOCABULAIRE FERMÉ DES COMPÉTENCES — toutes : les nœuds de la grille de
 * talents comme les capacités de carte (`competences.innee`).
 *
 * Même garde-fou que `MotsClesEquipement`, `DureeEffet`, `MotsClesSort` et
 * `ReactionEffet`, et pour la même raison : un talent annoncé au joueur que
 * rien n'applique est une promesse non tenue.
 *
 * ⚠ Ce registre remplace `CapacitesInnees::MECANIQUES` (2026-08-23). Les deux
 * listes coexistaient et l'ancienne ne couvrait que les cartes, alors qu'un
 * nœud d'arbre porte désormais des mécaniques de carte (le barbare achète
 * `attaque_balayee`, le moine `franchit_figures`) : deux vocabulaires pour un
 * seul pivot, c'est la dérive que le projet retire partout ailleurs.
 *
 * Une entrée porte TROIS choses, et les trois sont obligatoires :
 *
 *  - `lecteur` — la couture moteur qui l'applique. `GrilleTalentsTest` vérifie
 *    que la classe et la méthode existent, ET que le fichier du lecteur
 *    contient littéralement la clé. Un lecteur déclaré qui ne lit pas la
 *    mécanique est exactement la décoration qu'on chasse : `CapacitesInnees`
 *    déclarait bien ses lecteurs, rien ne vérifiait que le lien tenait.
 *  - `libelle` — ce que le JOUEUR lit. Le vocabulaire d'affichage vit ici et
 *    non dans une table parallèle du front : `store/game.js` en tenait une
 *    (`EFFETS_PASSIFS`) keyée sur des noms de COLONNES, si bien qu'un `effet`
 *    de compétence n'y produisait jamais la moindre puce — tous les talents
 *    s'affichaient sans un seul chiffre.
 *  - `icone` — Material Symbols, rendu par `Vignette`/`MSym`.
 *
 * @see \App\Partie\Talents  l'accesseur runtime (possession, valeur, cadence)
 */
final class MotsClesTalent
{
    /**
     * @var array<string, array{lecteur: string|list<string>, libelle: string, icone: string}>
     */
    public const MECANIQUES = [
        // ================================================================
        // Bonus permanents chiffrés — appliqués aux COLONNES du personnage
        // à l'acquisition (CompetenceController::EFFETS_PASSIFS).
        // ================================================================
        'bonus_pv_body_max' => [
            'lecteur' => 'App\Http\Controllers\Api\CompetenceController::appliquerEffetsPassifs()',
            'libelle' => 'PV de Body maximum',
            'icone' => 'favorite',
        ],
        'bonus_pv_mind_max' => [
            'lecteur' => 'App\Http\Controllers\Api\CompetenceController::appliquerEffetsPassifs()',
            'libelle' => 'PV de Mind maximum',
            'icone' => 'psychology',
        ],
        'bonus_attribut_body' => [
            'lecteur' => 'App\Http\Controllers\Api\CompetenceController::appliquerEffetsPassifs()',
            'libelle' => 'à l\'attribut Body',
            'icone' => 'fitness_center',
        ],
        'bonus_attribut_mind' => [
            'lecteur' => 'App\Http\Controllers\Api\CompetenceController::appliquerEffetsPassifs()',
            'libelle' => 'à l\'attribut Mind',
            'icone' => 'psychology',
        ],
        'bonus_deplacement' => [
            'lecteur' => 'App\Http\Controllers\Api\CompetenceController::appliquerEffetsPassifs()',
            'libelle' => 'case de déplacement',
            'icone' => 'directions_walk',
        ],
        'bonus_capacite_sac' => [
            'lecteur' => 'App\Partie\Marche\CapaciteSac::pour()',
            'libelle' => 'emplacement de sac',
            'icone' => 'backpack',
        ],

        // ================================================================
        // Dés de combat — permanents (sans `condition`) ou conditionnels
        // (lus en situation, jamais versés dans la colonne).
        // ================================================================
        'bonus_des_attaque' => [
            'lecteur' => ['App\Partie\Equipement::recalculerCombat()', 'App\Partie\ResolveurTour::frapper()'],
            'libelle' => 'dé d\'attaque',
            'icone' => 'swords',
        ],
        'bonus_des_defense' => [
            'lecteur' => ['App\Partie\Equipement::recalculerCombat()', 'App\Partie\ResolveurTour::resoudreAttaqueMonstre()'],
            'libelle' => 'dé de défense',
            'icone' => 'shield',
        ],
        'bonus_des_attaque_distance' => [
            'lecteur' => 'App\Partie\ResolveurTour::frapper()',
            'libelle' => 'dé d\'attaque à distance',
            'icone' => 'my_location',
        ],
        'bonus_des_attaque_contre_tier' => [
            'lecteur' => 'App\Partie\ResolveurTour::frapper()',
            'libelle' => 'dé d\'attaque contre les monstres d\'élite',
            'icone' => 'crisis_alert',
        ],
        'bonus_des_attaque_apres_deplacement' => [
            'lecteur' => 'App\Partie\ResolveurTour::frapper()',
            'libelle' => 'dé d\'attaque après une course',
            'icone' => 'sprint',
        ],
        'bonus_des_defense_contre_distance' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreAttaqueMonstre()',
            'libelle' => 'dé de défense contre les tirs',
            'icone' => 'shield_moon',
        ],
        'bonus_des_defense_allie_adjacent' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreAttaqueMonstre()',
            'libelle' => 'dé de défense aux héros à ton contact',
            'icone' => 'groups',
        ],
        'ignore_defense_monstre' => [
            'lecteur' => 'App\Partie\ResolveurTour::frapper()',
            'libelle' => 'dé de défense au monstre visé',
            'icone' => 'shield_with_heart',
        ],

        // ================================================================
        // Actifs de frappe.
        // ================================================================
        'relance_des_attaque_rates' => [
            'lecteur' => 'App\Partie\ResolveurTour::frapper()',
            'libelle' => 'relance les dés d\'attaque ratés',
            'icone' => 'casino',
        ],
        'attaque_supplementaire_apres_kill' => [
            'lecteur' => 'App\Partie\ResolveurTour::frapper()',
            'libelle' => 'attaque à nouveau après avoir abattu ta cible',
            'icone' => 'swap_calls',
        ],
        'inflige_condition_sur_touche' => [
            'lecteur' => 'App\Partie\ResolveurTour::frapper()',
            'libelle' => 'inflige une condition au monstre touché',
            'icone' => 'coronavirus',
        ],

        // ================================================================
        // Dégâts subis — interception par MoteurDegats (HerosVaSubirDegats).
        // ================================================================
        'reduction_degats' => [
            'lecteur' => 'App\Partie\MoteurDegats::infligerAHeros()',
            'libelle' => 'dégât à chaque coup subi',
            'icone' => 'health_and_safety',
        ],
        'resistance_degats_type' => [
            'lecteur' => 'App\Partie\MoteurSorts::absorbeDegat()',
            'libelle' => 'annule les dégâts',
            'icone' => 'local_fire_department',
        ],

        // ================================================================
        // Jets de compétence.
        // ================================================================
        'avantage_jet_mind' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreJet()',
            'libelle' => 'dé de Mind',
            'icone' => 'lightbulb',
        ],
        'relance_jet_mind_rate' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreJet()',
            'libelle' => 'relance un jet de Mind raté',
            'icone' => 'restart_alt',
        ],
        'resistance_condition' => [
            'lecteur' => 'App\Models\Competence::resisteA()',
            'libelle' => 'résistance',
            'icone' => 'vaccines',
        ],

        // ================================================================
        // Magie.
        // ================================================================
        'emplacement_element' => [
            'lecteur' => 'App\Partie\MoteurSorts::attacherElement()',
            'libelle' => 'domaine de magie',
            'icone' => 'auto_awesome',
        ],
        'sort_supplementaire_par_tour' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudre()',
            'libelle' => 'un second sort dans le même tour',
            'icone' => 'auto_fix_high',
        ],
        'recuperer_sort_epuise' => [
            'lecteur' => 'App\Partie\MoteurSorts::concentrationDisponible()',
            'libelle' => 'récupère un sort épuisé',
            'icone' => 'self_improvement',
        ],
        'annuler_effet_magique' => [
            'lecteur' => 'App\Partie\MoteurDread::sortDreadControle()',
            'libelle' => 'annule un effet magique',
            'icone' => 'block',
        ],
        'bonus_degats_sort' => [
            'lecteur' => 'App\Partie\ResolveurTour::sortDegats()',
            'libelle' => 'dégât aux sorts offensifs',
            'icone' => 'bolt',
        ],
        'regain_sort' => [
            'lecteur' => 'App\Partie\MoteurSorts::regagnerSorts()',
            'libelle' => 'récupère un sort',
            'icone' => 'autorenew',
        ],
        'sacrifice_pv_pour_sort' => [
            'lecteur' => 'App\Partie\MoteurSorts::sacrifierPourUnSort()',
            'libelle' => 'échange 1 PV de Body contre un sort récupéré',
            'icone' => 'bloodtype',
        ],

        // ================================================================
        // Exploration, pièges, terrain.
        // ================================================================
        'detection_pieges_adjacents' => [
            'lecteur' => 'App\Partie\MoteurPieges::detecterAdjacents()',
            'libelle' => 'détecte les pièges adjacents',
            'icone' => 'visibility',
        ],
        'desamorcer_piege' => [
            'lecteur' => ['App\Partie\MoteurPieges::peutDesamorcer()', 'App\Partie\ResolveurTour::resoudreDesamorcage()'],
            'libelle' => 'désamorce les pièges',
            'icone' => 'build',
        ],
        'detection_portes_secretes' => [
            'lecteur' => 'App\Partie\MoteurPortes::detecterSecretesAdjacentes()',
            'libelle' => 'détecte les portes secrètes adjacentes',
            'icone' => 'door_front',
        ],
        'ignore_terrain_entravant' => [
            'lecteur' => 'App\Partie\ResolveurTour::tronquerSurRacines()',
            'libelle' => 'ignore racines et chausse-trappes',
            'icone' => 'grass',
        ],
        'fouille_supplementaire' => [
            'lecteur' => 'App\Models\Quete::aFouille()',
            'libelle' => 'une fouille de plus par salle',
            'icone' => 'search',
        ],

        // ================================================================
        // Butin et marché.
        // ================================================================
        'bonus_or_tresor' => [
            'lecteur' => 'App\Partie\ResolveurTour::appliquerButin()',
            'libelle' => 'pièces d\'or de plus sur chaque trésor',
            'icone' => 'paid',
        ],
        'rarete_butin_amelioree' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreFouilleMobilier()',
            'libelle' => 'butin d\'une rareté supérieure',
            'icone' => 'diamond',
        ],
        'remise_marche' => [
            'lecteur' => 'App\Partie\Marche\PhaseMarche::ouvrir()',
            'libelle' => '% de remise au marché',
            'icone' => 'sell',
        ],
        'repiocher_carte_piege' => [
            'lecteur' => 'App\Partie\ResolveurTour::piocherAvecSixiemeSens()',
            'libelle' => 'repioche une carte de piège',
            'icone' => 'replay',
        ],

        // ================================================================
        // Soutien.
        // ================================================================
        'soin_allie' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreSoinAllie()',
            'libelle' => 'soigne un héros à ton contact',
            'icone' => 'healing',
        ],
        'malus_des_monstre_adjacent' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreAttaqueMonstre()',
            'libelle' => 'dé d\'attaque aux monstres à ton contact',
            'icone' => 'sentiment_very_dissatisfied',
        ],

        // ================================================================
        // Équipement.
        // ================================================================
        'acces_equipement' => [
            'lecteur' => 'App\Partie\Equipement::verifierAccesEquipement()',
            'libelle' => 'maîtrise',
            'icone' => 'key',
        ],
        'forge_amelioration' => [
            'lecteur' => 'App\Http\Controllers\Api\ForgeController::appliquer()',
            'libelle' => 'améliore un équipement au hub',
            'icone' => 'hardware',
        ],

        // ================================================================
        // CAPACITÉS DE CARTE (`competences.innee`) — acquises d'emblée avec
        // la figurine. Elles vivent dans le même registre depuis 2026-08-23 :
        // un nœud d'arbre peut porter la même mécanique.
        // ================================================================
        'bonus_des_defense_sans_metal' => [
            'lecteur' => 'App\Partie\MoteurSorts::desDefenseHeros()',
            'libelle' => 'dé de défense sans armure métallique ni bouclier',
            'icone' => 'shield',
        ],
        'attaque_supplementaire_arme' => [
            'lecteur' => 'App\Partie\ResolveurTour::ambidextrie()',
            'libelle' => 'une attaque de plus à la dague',
            'icone' => 'swords',
        ],
        'franchit_figures' => [
            // ⚠ `Grille::autoriserFranchissement()` APPLIQUE la règle, mais ne
            // connaît pas la mécanique : elle reçoit un booléen. Le lecteur est
            // donc le site qui LIT la clé, et le test a servi à s'en apercevoir
            // — la déclaration précédente désignait un fichier où le mot
            // « franchit_figures » n'a jamais figuré.
            'lecteur' => 'App\Partie\ResolveurTour::resoudreDeplacement()',
            'libelle' => 'traverse les cases occupées',
            'icone' => 'directions_run',
        ],
        'bonus_des_attaque_flanc' => [
            'lecteur' => 'App\Partie\ResolveurTour::frapper()',
            'libelle' => 'dé d\'attaque contre un monstre pris à revers',
            'icone' => 'swords',
        ],
        'plancher_pv' => [
            'lecteur' => ['App\Engine\ReactionEffet::actions()', 'App\Partie\MoteurReactions::resoudre()'],
            'libelle' => 'PV auquel tu restes debout au lieu de tomber',
            'icone' => 'health_and_safety',
        ],
        'annule_degats_voisin' => [
            'lecteur' => ['App\Engine\ReactionEffet::actions()', 'App\Partie\MoteurReactions::proposerAuVoisin()'],
            'libelle' => 'annule les dégâts d\'un héros à ton contact',
            'icone' => 'shield_person',
        ],
        'defi_errant' => [
            'lecteur' => ['App\Engine\ReactionEffet::actions()', 'App\Partie\MoteurReactions::proposerDefi()'],
            'libelle' => 'attire à toi le monstre errant qui surgit',
            'icone' => 'flag',
        ],
        'sacrifice_pv_pour_des' => [
            'lecteur' => 'App\Partie\ResolveurTour::payerLaFurie()',
            'libelle' => 'échange des PV de Body contre des dés d\'attaque',
            'icone' => 'bloodtype',
        ],
        'riposte' => [
            'lecteur' => 'App\Partie\MoteurReactions::riposter()',
            'libelle' => 'riposte au monstre qui te blesse',
            'icone' => 'undo',
        ],
        'attaque_balayee' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreAttaqueBalayee()',
            'libelle' => 'frappe tous les monstres adjacents d\'un coup',
            'icone' => 'cyclone',
        ],
        'style_elementaire' => [
            'lecteur' => 'App\Partie\StylesElementaires::sourceActivable()',
            'libelle' => 'style élémentaire',
            'icone' => 'self_improvement',
        ],
        'saut_piege_automatique' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreFranchissement()',
            'libelle' => 'franchit un piège sans jet',
            'icone' => 'moving',
        ],
        'bonus_des_attaque_mains_nues' => [
            'lecteur' => 'App\Partie\ResolveurTour::activerPoingDeMontagne()',
            'libelle' => 'dé d\'attaque à mains nues',
            'icone' => 'sports_martial_arts',
        ],
        'fouille_complete' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreJet()',
            'libelle' => 'fouille pièges et portes secrètes en une action',
            'icone' => 'search',
        ],
        'deplacement_scinde' => [
            'lecteur' => 'App\Partie\ResolveurTour::marquerCreneau()',
            'libelle' => 'scinde ton déplacement avant et après ton action',
            'icone' => 'alt_route',
        ],
        'annule_degats' => [
            'lecteur' => ['App\Engine\ReactionEffet::actions()', 'App\Partie\MoteurReactions::proposer()'],
            'libelle' => 'annule les dégâts d\'un coup subi',
            'icone' => 'water_drop',
        ],
        'rayon' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreRayon()',
            'libelle' => 'dégâts à chaque ennemi traversé par le rayon',
            'icone' => 'line_end_arrow',
        ],
        'degat_differe' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreDegatDiffere()',
            'libelle' => 'dégâts différés à la fin du prochain tour',
            'icone' => 'timer',
        ],
        'alerte_pieges_adjacents' => [
            'lecteur' => 'App\Partie\MoteurPieges::controlerChemin()',
            'libelle' => 'te fait avertir des pièges voisins',
            'icone' => 'warning',
        ],
    ];

    /** Cadences déclarées par `effet.frequence` → texte joueur. */
    private const FREQUENCES = [
        'une_fois_par_quete' => 'une fois par quête',
        'une_fois_par_tour' => 'une fois par tour',
        'une_fois_par_usage' => 'une fois par attaque',
    ];

    /** `effet.condition` (passifs conditionnels) → texte joueur. */
    private const CONDITIONS = [
        'pv_body_sous_moitie' => 'sous la moitié de tes PV de Body',
        'premiere_attaque_du_combat' => 'contre la première attaque du combat',
    ];

    /** `effet.contexte` des jets de Mind → texte joueur. */
    private const CONTEXTES = [
        'social_peur' => 'sur les jets sociaux fondés sur la peur',
        'perception' => 'sur les jets de perception',
        'savoir' => 'sur les jets de savoir',
        'distance' => 'à distance',
    ];

    /** Une mécanique déclarée l'est-elle ici ? */
    public static function connue(?string $mecanique): bool
    {
        return $mecanique !== null && isset(self::MECANIQUES[$mecanique]);
    }

    /** Icône Material Symbols de la mécanique, ou l'icône générique. */
    public static function icone(array $effet): string
    {
        return self::MECANIQUES[$effet['mecanique'] ?? '']['icone'] ?? 'hub';
    }

    /**
     * L'AVANTAGE chiffré, dérivé de `effet` — jamais saisi à la main, donc
     * jamais en désaccord avec la mécanique. C'est le pendant court de la
     * `description` écrite à la main : le joueur lit la phrase de jeu ET le
     * chiffre, et peut décider sans ouvrir le guide.
     *
     * Rend `''` pour une mécanique inconnue : le contrôle de vocabulaire est
     * le travail du test, pas celui de l'affichage.
     */
    public static function avantage(array $effet): string
    {
        $mecanique = $effet['mecanique'] ?? null;
        $entree = self::MECANIQUES[$mecanique] ?? null;

        if ($entree === null) {
            return '';
        }

        $texte = self::corps($mecanique, $entree['libelle'], $effet);

        foreach (self::qualificatifs($effet) as $qualificatif) {
            $texte .= ', '.$qualificatif;
        }

        return $texte;
    }

    /**
     * Le corps de la phrase : « +1 dé d'attaque », « −1 dégât en moins… »,
     * ou le libellé seul quand la mécanique ne porte aucun nombre.
     */
    private static function corps(string $mecanique, string $libelle, array $effet): string
    {
        // Mécaniques dont le paramètre n'est pas un nombre mais une liste.
        if ($mecanique === 'resistance_condition') {
            $noms = implode(' et ', (array) ($effet['condition_nom'] ?? []));

            return $noms === '' ? $libelle : "{$libelle} aux conditions {$noms}";
        }

        if ($mecanique === 'acces_equipement') {
            $tags = implode(', ', array_map(
                static fn (string $tag): string => str_replace('_', ' ', $tag),
                (array) ($effet['tags'] ?? []),
            ));

            return $tags === '' ? $libelle : "{$libelle} : {$tags}";
        }

        if ($mecanique === 'resistance_degats_type') {
            $type = $effet['type_degat'] ?? null;

            return $type === null ? $libelle : "{$libelle} de {$type}";
        }

        if ($mecanique === 'inflige_condition_sur_touche') {
            $nom = $effet['condition_nom'] ?? null;

            return $nom === null ? $libelle : "inflige « {$nom} » au monstre touché";
        }

        if ($mecanique === 'remise_marche') {
            return ((int) ($effet['valeur'] ?? 0)).' % de remise au marché';
        }

        $valeur = $effet['valeur'] ?? null;

        if ($valeur === null || ! is_numeric($valeur)) {
            return ucfirst($libelle);
        }

        $valeur = (int) $valeur;

        // Les mécaniques qui RETRANCHENT quelque chose à l'adversaire ou aux
        // dégâts subis : le nombre est un gain pour le héros, mais la phrase
        // doit dire ce qui diminue. Le libellé porte déjà « en moins ».
        // ⚠ Le SIGNE porte la soustraction, le libellé ne la répète pas : « −1
        // dégât en moins » se lisait deux fois et disait le contraire une fois.
        $soustractif = in_array($mecanique, [
            'reduction_degats', 'ignore_defense_monstre', 'malus_des_monstre_adjacent',
        ], true);

        $signe = $soustractif ? '−' : ($valeur >= 0 ? '+' : '−');

        return $signe.abs($valeur).' '.$libelle;
    }

    /** Condition, contexte et cadence, ajoutés en queue de phrase. */
    private static function qualificatifs(array $effet): array
    {
        $suite = [];

        if (isset(self::CONDITIONS[$effet['condition'] ?? ''])) {
            $suite[] = self::CONDITIONS[$effet['condition']];
        }

        if (isset(self::CONTEXTES[$effet['contexte'] ?? ''])) {
            $suite[] = self::CONTEXTES[$effet['contexte']];
        }

        if (isset($effet['tier'])) {
            $suite[] = 'contre les '.implode(' et ', array_map(
                static fn (string $t): string => str_replace('_', '-', $t),
                (array) $effet['tier'],
            ));
        }

        if (isset(self::FREQUENCES[$effet['frequence'] ?? ''])) {
            $suite[] = self::FREQUENCES[$effet['frequence']];
        }

        return $suite;
    }
}
