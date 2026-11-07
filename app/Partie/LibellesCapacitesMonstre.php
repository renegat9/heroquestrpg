<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\MotsClesAdversaires;

/**
 * Ce que les CAPACITÉS d'un monstre disent au joueur — une seule liste, lue partout où ses stats s'affichent : la fiche
 * de la table (`EtatGroupe.entites[].capacites`), le Guide (`GET /api/guide`, `monstres[].capacites_libelles`) et le
 * livret (`docs/livret/catalogue.sh`).
 *
 * René, 2026-11-06, en partie : « J'ai été attaqué 2 fois durant le tour des méchants, est-ce que l'un des ennemis
 * pouvait m'attaquer en diagonale ? Si c'est le cas, il faudrait l'afficher dans les détails du monstre », puis
 * « partout où on affiche les stats des monstres ». L'Assassin du MJ (« Rôdeur des Profondeurs ») frappait du coin, et
 * la fiche de la table n'en disait rien ; le Guide (`compendium.js`) et le livret (`generer.py`) tenaient chacun leur
 * copie des libellés.
 *
 * Les mots du vocabulaire des adversaires gardent le libellé que `MotsClesAdversaires::CAPACITES` déclare — jamais
 * recopié ici. Registre testé dans les deux sens (`LibellesCapacitesMonstreTest`) : tout mot porté par une ligne du
 * bestiaire se dit, et tout libellé d'ici sert une ligne du bestiaire.
 */
final class LibellesCapacitesMonstre
{
    /** @var array<string, string> */
    public const LIBELLES = [
        'agile' => 'Agile (ignore mobilier et figures)',
        'attaque_diagonale' => 'Attaque en diagonale',
        'charge' => 'Charge',
        'coup_de_corne' => 'Coup de corne (encorne le héros qui finit son tour à son contact)',
        'deux_attaques' => 'Deux attaques (une ou deux cibles)',
        'embuscade' => 'Embuscade (déguisé en coffre jusqu\'à ce qu\'un héros approche)',
        'ethere' => 'Éthéré (touché seulement sur bouclier noir)',
        'etreinte' => 'Étreinte (dégâts automatiques tant qu\'il tient)',
        'frappe_de_zone' => 'Frappe de zone',
        'increvable_une_fois' => 'Increvable une fois (tombe à 1 PV au lieu de 0)',
        'mouvement_apres_sort' => 'Peut encore se déplacer après avoir lancé un sort',
        'racines_entravantes' => 'Racines entravantes (stoppe net)',
        'recompense_reddition' => 'Vaincu, s\'incline et verse une rançon à la bourse commune',
        'resistance_magique' => 'Résistance magique',
        's_accroche' => 'S\'accroche (jeton sur le héros au contact, qui le ralentit)',
        'tacticien' => 'Tacticien (+1 dé sur cible flanquée)',
        'tir_au_choix' => 'Tir au choix : tire à distance sur place, frappe au contact',
        'venimeux' => 'Venimeux (paralysie)',
        'vol_draconique' => 'Vol draconique (traverse les figures, jamais le mobilier)',
        'vol_objet' => 'Vole un objet puis s\'enfuit',
        // Capacités PARAMÉTRÉES : leur valeur est la règle, `pour()` la dit d'abord ; ceci n'est que le repli.
        'spawn' => 'Engendre des rejetons',
        'sort_a_volonte' => 'Sort à volonté (sans limite d\'usage)',
        'garde_personnelle' => 'Garde personnelle : son escorte lance des dés de figurine',
        'reserve_cartes' => 'Tient une main de cartes de sort',
        'reactions_defense' => 'Réactions à usage unique, une fois par rencontre (sans action)',
        'effet_global_quete' => 'Effet de quête',
    ];

    /**
     * Les formes COURTES, pour la colonne « Traits » du livret imprimé (`docs/livret/generer.py`, via `catalogue.sh`) :
     * même source que les phrases, une longueur de tableau. Elles vivaient dans `generer.py` (`LIB_CAP`) jusqu'au
     * 2026-11-06.
     *
     * @var array<string, string>
     */
    public const COURTS = [
        'agile' => 'agile',
        'apparait_a_la_vue' => 'surgit dès qu\'un héros le voit',
        'attaque_diagonale' => 'attaque en diagonale',
        'attaque_mind' => 'frappe l\'esprit (points de Mind)',
        'charge' => 'charge',
        'coup_de_corne' => 'encorne qui finit son tour au contact',
        'deux_attaques' => 'deux attaques',
        'double_sort' => 'deux sorts dans le même tour',
        'effet_global_quete' => 'effet de quête : ses gobelins lancent un dé d\'attaque de plus (voir Gruulob)',
        'embuscade' => 'embusqué dans un coffre',
        'ethere' => 'éthéré',
        'etreinte' => 'étreinte',
        'filet_silencieux' => 'canalise l\'effroi sur qui finit son tour sous ses yeux',
        'flamme_du_dread' => 'crache la flamme du Dread sur sa salle',
        'frappe_de_zone' => 'frappe de zone',
        'fuit_si_affaibli' => 's\'enfuit quand il est affaibli',
        'garde_personnelle' => 'protégé par sa garde personnelle',
        'immunite_conditions' => 'insensible au sommeil et aux tours perdus',
        'immunite_sorts' => 'insensible à tous les sorts',
        'immunite_sorts_elements' => 'insensible aux sorts d\'un élément',
        'immunite_sorts_sauf' => 'seul un élément l\'atteint',
        'increvable_une_fois' => 'tombe à 1 PV au lieu de 0, une fois',
        'metamorphe' => 'change de forme à chaque mort',
        'meteore' => 'riposte par un météore',
        'mouvement_apres_sort' => 'se déplace après son sort',
        'passif_jusqua' => 'immobile jusqu\'à son réveil',
        'racines_entravantes' => 'racines entravantes',
        'reactions_defense' => 'ignore un coup, une fois',
        'recompense_reddition' => 'vaincu, il paie sa rançon',
        'repertoire_complet' => 'lance tout son répertoire',
        'reserve_cartes' => 'une réserve de cartes de sort',
        'resistance_magique' => 'résistance magique',
        's_accroche' => 's\'accroche',
        'sacrifice_de_vrille' => 'sacrifie une vrille pour survivre',
        'sort_a_volonte' => 'un sort à volonté',
        'sorts_uniques' => 'sorts du Dread, une fois chacun par quête',
        'souffle_du_construit' => 'souffle sur sa salle, une fois',
        'spawn' => 'fait surgir des rejetons',
        'synchroformes' => 'change de forme au fil du combat',
        'tacticien' => 'bouge avant ET après son attaque',
        'tir_au_choix' => 'tire à distance, au choix',
        'tourelle' => 'immobile, tire sur ce qu\'elle voit',
        'venimeux' => 'venimeux',
        'vol_draconique' => 'vole par-dessus les figures',
        'vol_objet' => 'vole un objet',
        'vrille_en_fin_de_tour' => 'fait pousser une vrille à chaque tour',
        'vulnerable_uniquement_a' => 'seules certaines armes et certains sorts le blessent',
    ];

    /** Les réactions à usage unique (`reactions_defense`), nommées comme le joueur les voit jouer. */
    private const REACTIONS = [
        'ignore_degats_attaque' => 'ignorer un coup',
        'rompt_sort_actif' => 'rompre un sort qui le gêne',
        'annule_sort_ciblant' => 'annuler un sort qui le vise',
        'redirige_attaque' => 'détourner une attaque',
        'terreur' => 'Terreur (intouchable par le héros dont c\'est le tour)',
        'consomme_magie' => 'consumer la magie d\'un sort (et regagner 2 PV)',
        'reflexion' => 'renvoyer le coup qui l\'achèverait',
        'realite_decalee' => 'décaler la réalité (un sort de Dread au début du tour du MJ)',
    ];

    /**
     * Mots que le joueur n'a pas à lire : une règle de GÉNÉRATION de la carte, pas de jeu — la valeur dit pourquoi.
     *
     * @var array<string, string>
     */
    public const MASQUES = [
        MotsClesAdversaires::RESERVE_A_SON_THEME => 'Décide seulement dans quelles campagnes la créature peut être tirée.',
    ];

    /**
     * Les phrases, dans l'ordre du catalogue. Deux formes existent en base : une liste de mots, ou une map dont les clés
     * NUMÉRIQUES portent un mot et les autres SONT le mot, paramétré par leur valeur.
     *
     * @return list<string>
     */
    public static function pour(mixed $capacites): array
    {
        $phrases = [];

        foreach (self::entrees($capacites) as [$mot, $valeur]) {
            if (isset(self::MASQUES[$mot])) {
                continue;
            }

            $phrases[] = self::parametree($mot, $valeur)
                ?? self::LIBELLES[$mot]
                ?? MotsClesAdversaires::CAPACITES[$mot]['libelle']
                ?? str_replace('_', ' ', $mot);
        }

        return $phrases;
    }

    /**
     * Les formes COURTES du livret, dans l'ordre du catalogue (mots masqués exclus, comme `pour()`).
     *
     * @return list<string>
     */
    public static function courtes(mixed $capacites): array
    {
        $courtes = [];

        foreach (self::entrees($capacites) as [$mot]) {
            if (! isset(self::MASQUES[$mot])) {
                $courtes[] = self::COURTS[$mot] ?? str_replace('_', ' ', $mot);
            }
        }

        return $courtes;
    }

    /**
     * Les mots portés, avec leur valeur (`null` pour un mot nu).
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public static function entrees(mixed $capacites): array
    {
        $entrees = [];

        foreach ((array) $capacites as $cle => $valeur) {
            if (is_int($cle)) {
                if (is_string($valeur)) {
                    $entrees[] = [$valeur, null];
                }

                continue;
            }

            $entrees[] = [(string) $cle, $valeur];
        }

        return $entrees;
    }

    private static function parametree(string $mot, mixed $valeur): ?string
    {
        $liste = fn (array $v) => implode(', ', array_map(fn ($x) => str_replace('_', ' ', (string) $x), $v));

        return match ($mot) {
            'spawn' => is_array($valeur) && isset($valeur['creature']) ? "Engendre des rejetons : {$valeur['creature']}" : null,
            'sort_a_volonte' => is_array($valeur) && isset($valeur['sort']) ? "Sort à volonté : {$valeur['sort']} (sans limite d'usage)" : null,
            'garde_personnelle' => is_array($valeur) && isset($valeur['monstre'])
                ? "Garde personnelle : chaque {$valeur['monstre']} de sa salle lance ".(int) ($valeur['attaque'] ?? 0)
                    .' dés d\'attaque et '.(int) ($valeur['defense'] ?? 0).' de défense'
                : null,
            'reserve_cartes' => is_array($valeur) && $valeur !== []
                ? 'Cartes de sort en main : '.implode(', ', array_map(fn ($sort, $n) => "{$sort} ×{$n}", array_keys($valeur), $valeur))
                : null,
            'reactions_defense' => is_array($valeur) && $valeur !== []
                ? 'Réactions à usage unique, une fois par rencontre (sans action) : '
                    .implode(', ', array_map(fn ($r) => self::REACTIONS[$r] ?? str_replace('_', ' ', (string) $r), $valeur))
                : null,
            'effet_global_quete' => is_array($valeur) && isset($valeur['titre'])
                ? "Effet de quête : {$valeur['titre']} — +".(int) ($valeur['des'] ?? 0).' dé(s) '
                    .(($valeur['volee'] ?? null) === 'defense' ? 'de défense' : 'd\'attaque').', jusqu\'à la fin de la quête'
                : null,
            // Le Seigneur Sorcier de Barak Tor lance la Frayeur DEUX fois : « chacun une seule fois » serait faux pour lui.
            MotsClesAdversaires::SORTS_UNIQUES => is_array($valeur) && $valeur !== []
                ? 'Sorts du Dread (chacun une seule fois par quête, sauf '
                    .implode(', ', array_map(fn ($sort, $n) => "{$sort} {$n} fois", array_keys($valeur), $valeur)).')'
                : 'Sorts du Dread (chacun une seule fois par quête)',
            MotsClesAdversaires::IMMUNITE_SORTS_SAUF => is_array($valeur) ? 'Immunisé à tous les sorts, sauf ceux de '.$liste($valeur) : null,
            MotsClesAdversaires::IMMUNITE_SORTS_ELEMENTS => is_array($valeur) ? 'Insensible aux sorts de '.$liste($valeur) : null,
            MotsClesAdversaires::VULNERABLE_UNIQUEMENT_A => is_array($valeur) ? 'Blessé seulement par : '.$liste($valeur) : null,
            default => null,
        };
    }
}
