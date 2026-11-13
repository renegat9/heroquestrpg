<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClasseHeros;
use App\Models\Competence;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Piege;
use App\Models\Sort;
use App\Models\SortDread;
use App\Models\Terrain;
use App\Engine\MotsClesEquipement;
use App\Engine\MotsClesTerrain;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\HallArene;
use App\Partie\Arene\ManoeuvreSolo;
use App\Partie\Arene\MonstresContreMonstres;
use App\Partie\Arene\PuissanceArene;
use App\Partie\Arene\TropheesArene;
use App\Partie\DemarreurQuete;
use App\Partie\EquipementDepart;
use App\Partie\Images\BibliothequeImages;
use App\Partie\Marche\PhaseMarche;
use App\Partie\ResolveurTour;
use Illuminate\Http\JsonResponse;

/**
 * Guide / compendium PUBLIC (aucune auth — page /guide de la SPA, accessible
 * depuis l'accueil) : données de RÉFÉRENCE en lecture seule, jamais l'état
 * d'une partie. Classes de héros et leurs talents, bestiaire, équipements,
 * sorts, pièges — les catalogues seedés. Les effets sont renvoyés bruts
 * (JSON mécanique) et mis en forme côté front (compendium.js).
 */
class GuideController extends Controller
{
    public function index(): JsonResponse
    {
        // Rangs de tri stables (indépendants du SGBD : on trie en PHP plutôt
        // que via FIELD(), absent de sqlite utilisé par les tests).
        $rangTier = ['base' => 0, 'sous_boss' => 1, 'boss' => 2];
        $rangElement = ['feu' => 0, 'eau' => 1, 'terre' => 2, 'air' => 3];
        $rangCategorie = ['arme' => 0, 'armure' => 1, 'outil' => 2, 'consommable' => 3, 'parchemin' => 4];

        return response()->json([
            // `tags_equipement` : la maîtrise d'équipement de départ. Sans elle
            // le guide ne disait NULLE PART qu'un magicien ne porte pas
            // d'armure ni qu'un barbare ne manie pas la plate — le joueur ne
            // découvrait la règle qu'au refus, au moment d'équiper. Les tags
            // gagnés par l'arbre de talents sont déductibles côté front depuis
            // `competences.effet` (mecanique `acces_equipement`).
            'classes' => ClasseHeros::query()->orderBy('id')
                // `race` exposée (2026-08-13) : c'est elle qui explique le
                // mouvement de base (doc 01 §4bis-2). Sans elle, un joueur
                // voyait l'Explorateur marcher moins vite qu'un Rogue sans
                // pouvoir deviner que c'est un nain.
                // `objets_autorises` exposé (2026-08-22) : sans lui la page
                // montrait un Moine réduit à son talisman, donc sans AUCUNE
                // arme — les cinq qu'il manie sont nommées, pas déductibles
                // d'un tag.
                ->get(['nom', 'race', 'pv_body', 'pv_mind', 'attr_body', 'attr_mind', 'des_attaque', 'des_defense', 'deplacement_base', 'bonus_sac', 'tags_equipement', 'objets_autorises'])
                // `depart` (2026-09-24) : l'attaque et la défense AVEC l'arme et
                // l'armure de départ. Sans lui la page affichait la base nue —
                // un barbare à 1 dé, alors que son épée large en lance 3 dès la
                // création. Décidé ici, jamais recalculé côté front.
                ->map(fn (ClasseHeros $c) => [...$c->toArray(), 'depart' => EquipementDepart::valeurs($c)])
                ->values()
                ->all(),

            // `innee` exposé (2026-08-13) : une capacité de CARTE est acquise
            // d'emblée et gratuitement, un nœud d'arbre se paie un point. Sans
            // ce drapeau, la page /guide affichait les deux dans la même liste —
            // un joueur lisant « Furie » dans l'arbre du Berserker croyait
            // devoir l'acheter.
            // `categorie`/`colonne`/`rang` : la GRILLE de talents (2026-08-23).
            // Sans eux la page /guide reste une liste plate et ne dit pas à quoi
            // un joueur renonce en descendant une colonne. `avantage` est dérivé
            // de `effet` par `MotsClesTalent` — un seul endroit met les chiffres
            // en forme, ici comme sur la manette.
            'competences' => Competence::query()
                ->orderBy('classe')->orderBy('colonne')->orderBy('rang')->orderBy('id')
                ->get(['id', 'classe', 'nom', 'description', 'type', 'effet', 'prerequis_id', 'innee',
                    'categorie', 'categorie_icone', 'colonne', 'rang', 'cout'])
                ->map(fn (Competence $c) => [
                    ...$c->only(['id', 'classe', 'nom', 'description', 'type', 'effet', 'prerequis_id',
                        'innee', 'categorie', 'categorie_icone', 'colonne', 'rang', 'cout']),
                    'avantage' => $c->avantage(),
                    'avantage_icone' => $c->avantageIcone(),
                ])
                ->values()
                ->all(),

            'monstres' => Monstre::query()
                // `boite` + `boite_libelle` (2026-10-09) : la boîte d'extension d'où
                // vient la créature. Le libellé est celui du serveur
                // (`DemarreurQuete::LIBELLES_BOITES`), jamais un identifiant brut.
                // Un monstre imprimé dans DEUX boîtes (Archer / Guerrier elfe : Mage of the
                // Mirror et Rise of the Dread Moon, 2026-10-15) les nomme toutes, dans
                // `boite_libelle` — `boite` reste la boîte d'origine.
                ->get(['nom_base', 'deplacement', 'attaque', 'defense', 'pv_body', 'pv_mind', 'tier', 'cout', 'capacites', 'boite', 'boites_partagees'])
                ->map(fn (Monstre $m) => [
                    ...$m->only(['nom_base', 'deplacement', 'attaque', 'defense', 'pv_body', 'pv_mind', 'tier', 'cout', 'capacites', 'boite']),
                    // Ses capacités DITES (`LibellesCapacitesMonstre`, 2026-11-06) : la même liste que la fiche de la table
                    // et le livret. `capacites` reste publié brut pour les clients qui le lisaient.
                    'capacites_libelles' => \App\Partie\LibellesCapacitesMonstre::pour($m->capacites),
                    'boite_libelle' => $m->boite === null ? null : collect($m->boites())
                        ->map(fn (string $b) => app(DemarreurQuete::class)->libelleBoiteBestiaire($b))->implode(' · '),
                ])
                ->sortBy(fn ($m) => sprintf('%d|%04d|%s', $rangTier[$m['tier']] ?? 9, $m['cout'], $m['nom_base']))
                ->values()
                ->all(),

            // `tag_equipement` : la maîtrise EXIGÉE pour porter la pièce, pendant
            // de `classes.tags_equipement`. C'est ce couple qui rend la
            // restriction lisible des deux côtés (doc 01 §7).
            'objets' => Objet::query()
                // `metallique` : la MATIÈRE, que le tag ne dit pas (il porte le
                // poids). C'est elle qui ferme la cotte au Druide et au Rogue,
                // et qui coûte au Barde son dé de défense supplémentaire.
                ->get(['nom', 'categorie', 'rarete', 'prix_base', 'emplacement', 'effet', 'tag_equipement', 'metallique'])
                // Le tri lit le prix RÉEL, avant qu'il ne soit retiré : un prix nul
                // ferait passer chaque potion hors marché avant les autres consommables.
                ->sortBy(fn (Objet $o) => sprintf('%d|%06d|%s', $rangCategorie[$o->categorie] ?? 9, $o->prix_base, $o->nom))
                // `avantages` (2026-10-09) : ce que fait la pièce, TRADUIT PAR LE
                // SERVEUR (`MotsClesEquipement::avantages()`, le même texte que le
                // sac du téléphone et le livret). Le client ne retraduit plus la clé
                // mécanique : 43 objets s'affichaient en « relance des attaque ».
                // `prix_base` (2026-10-16) : la DÉCISION du serveur. Un objet HORS MARCHÉ
                // (parchemin, carte de trésor sans prix, potion de soin) n'a pas de prix,
                // puisqu'aucune transaction ne l'utilise — `null`, et le front n'affiche rien.
                // Même prédicat que l'étal (`PhaseMarche::estHorsMarche()`), jamais un second test.
                ->map(fn (Objet $o) => [
                    ...$o->only(['nom', 'categorie', 'rarete', 'emplacement', 'effet', 'tag_equipement', 'metallique']),
                    'prix_base' => PhaseMarche::estHorsMarche($o) ? null : $o->prix_base,
                    'avantages' => MotsClesEquipement::avantages((array) $o->effet),
                ])
                ->values()
                ->all(),

            // `id` exposé : le sélecteur de sorts elfiques (création et rechoix
            // au hub) choisit ICI ses 3 sorts et les renvoie par identifiant —
            // sans lui, la seule liste publique du répertoire serait inutilisable.
            'sorts' => Sort::query()
                ->get(['id', 'element', 'nom', 'type', 'difficulte_parchemin', 'effet'])
                ->sortBy(fn ($s) => sprintf('%d|%s', $rangElement[$s->element] ?? 9, $s->nom))
                ->values()
                ->all(),

            // MOBILIER (2026-10-09) : taille, vue, fouille et, depuis le mobilier
            // attaquable, les points de vie et les dés de défense. Sans cette
            // rubrique, un Haut Autel qui « se combat comme un monstre » n'était
            // décrit nulle part.
            'mobiliers' => Mobilier::query()->orderBy('id')
                ->get(['nom', 'largeur', 'hauteur', 'bloque_vue', 'fouillable', 'difficulte_destruction', 'pv_body', 'defense_dice', 'boite', 'effet'])
                ->map(fn (Mobilier $m) => [
                    ...$m->only(['nom', 'largeur', 'hauteur', 'bloque_vue', 'fouillable', 'difficulte_destruction', 'pv_body', 'defense_dice', 'boite']),
                    'attaquable' => $m->pv_body !== null,
                    // Le COCON (Jungles of Delthrak) : une action d'un héros adjacent le
                    // détruit, sans jet. La DÉCISION est publiée, pas la clé brute.
                    'detruit_par_action' => (bool) (($m->effet ?? [])['detruit_par_action'] ?? false),
                    'boite_libelle' => $m->boite === null ? null : app(DemarreurQuete::class)->libelleBoiteBestiaire((string) $m->boite),
                ])
                ->values()
                ->all(),

            // TERRAINS (2026-10-09) : ce que chaque tuile FAIT, en phrases. La carte les
            // nomme à sa légende ; sans cette rubrique, le guide ne les nommait nulle part.
            // Les phrases viennent du vocabulaire fermé (`MotsClesTerrain::avantages()`).
            'terrains' => Terrain::query()->orderBy('id')
                ->get(['nom', 'cout_deplacement', 'effet', 'boite'])
                ->map(fn (Terrain $t) => [
                    ...$t->only(['nom', 'cout_deplacement', 'boite']),
                    'avantages' => MotsClesTerrain::avantages((array) $t->effet, (int) $t->cout_deplacement),
                    'boite_libelle' => $t->boite === null ? null : app(DemarreurQuete::class)->libelleBoiteBestiaire((string) $t->boite),
                ])
                ->values()
                ->all(),

            // THÈMES de campagne : les boîtes dont le bestiaire peut colorer une
            // campagne (`BOITES_THEMATIQUES`), nommées comme le serveur les nomme.
            'themes' => collect(DemarreurQuete::BOITES_THEMATIQUES)
                ->map(fn (string $cle) => ['cle' => $cle, 'libelle' => DemarreurQuete::LIBELLES_BOITES[$cle] ?? $cle])
                ->values()
                ->all(),

            'pieges' => Piege::query()->orderBy('nom')
                ->get(['nom', 'detectable', 'desarmable', 'usage', 'effet'])
                ->values()
                ->all(),

            // PROVENANCE : les 61 cartes des deux paquets sources (armurerie et
            // artefacts), portées ou non. Sans ça, la page /guide affichait un
            // catalogue sans dire d'où il vient — et surtout, elle taisait les
            // 26 cartes qui existent au plateau et ne tournent pas encore ici.
            // Un test (CartesSourcesTest) confronte ce registre au catalogue
            // dans les deux sens : la provenance affichée ne peut pas dériver.
            'cartes' => $this->cartes(),

            // LE GUIDE DE L'ARÈNE (2026-11-12, « un guide dédié à l'arène ») : l'onglet « Arène » de /guide. Chaque chiffre
            // est lu au point de passage du jeu qui le décide — la page n'en recopie aucun. Contrat : §« Le guide de l'arène ».
            'arene' => $this->arene(),
        ]);
    }

    /**
     * Le bloc `arene` (contrat, §« Le guide de l'arène »). Les listes sont celles du salon et du combat : roster et
     * alliés (`GroupesArene`), puissance (`PuissanceArene`), tuiles (`TropheesArene`), table de fouille
     * (`ResolveurTour::TRESOR_TOURNOI`), manœuvre (`ManoeuvreSolo`), plan du hall (`HallArene`).
     *
     * @return array<string, mixed>
     */
    private function arene(): array
    {
        $groupes = app(GroupesArene::class);
        $puissance = app(PuissanceArene::class);
        $hall = app(HallArene::class);
        $carte = $hall->carte();
        $lot = array_count_values(TropheesArene::LOT);

        return [
            // Le roster du Defender dans l'ordre du livret (p. 16) : `monstresDuRoster()` est la liste blanche du salon.
            // `a_distance` : une version à distance ; sa puissance vaut alors son tir (`duMonstre()`), et `attaque` reste
            // son attaque au contact.
            'roster' => $groupes->monstresDuRoster()->map(fn (Monstre $m) => [
                'id' => (int) $m->id,
                'nom_base' => $m->nom_base,
                'deplacement' => (int) $m->deplacement,
                'attaque' => (int) $m->attaque,
                'defense' => (int) $m->defense,
                'pv_body' => (int) $m->pv_body,
                'puissance' => $puissance->duMonstre($m),
                'a_distance' => $m->aDistance(),
                'emprise' => $m->emprise(),
            ])->values()->all(),

            'allies' => $groupes->alliesPublies(),

            'puissance' => [
                'heros' => $puissance->formuleHeros(),
                'plafond_mvm' => MonstresContreMonstres::PUISSANCE_MAX,
            ],

            // `nombre` : combien d'exemplaires la p. 14 imprime dans le lot de neuf. `cases` : les six cases du plan.
            'trophees' => [
                'tuiles' => collect(TropheesArene::TUILES)->map(fn (array $t, string $cle) => [
                    'cle' => $cle,
                    'libelle' => $t['libelle'],
                    'effet' => $t['effet'],
                    'nombre' => (int) ($lot[$cle] ?? 0),
                ])->values()->all(),
                'cases' => count(TropheesArene::CASES),
                'elan' => TropheesArene::ELAN,
                'cases_elan' => TropheesArene::CASES_ELAN,
                'soin_potion' => TropheesArene::SOIN_POTION,
            ],

            'coffres' => $this->coffresDuTournoi(),

            'manoeuvre' => ['des_attaque' => ManoeuvreSolo::DES_ATTAQUE],

            // Le hall : la carte FIXE (même forme que `carte.cases`), et ses cases remarquables, dans les coordonnées de la
            // grille — celles que `DemarreurArene` et `TropheesArene::poser()` utilisent.
            'hall' => [
                'largeur' => $carte['largeur'],
                'hauteur' => $carte['hauteur'],
                'cases' => $carte['cases'],
                'trophees' => $this->casesDuPlan(TropheesArene::CASES, $hall),
                'coffres' => $this->casesDuPlan(HallArene::COFFRES, $hall),
                'gravats' => $this->casesDuPlan(HallArene::GRAVATS, $hall),
                'depart_challenger' => $carte['spawn_heros'],
                'depart_defender' => $carte['spawn_monstres'],
            ],
        ];
    }

    /**
     * La table 2d6 de la fouille d'un coffre du tournoi (`ResolveurTour::TRESOR_TOURNOI`, livret p. 13), en fourchettes.
     * La clé d'une ligne est son PLAFOND : la fourchette commence juste au-dessus du plafond précédent, et la première
     * commence au plus petit total de deux dés.
     *
     * @return list<array{de: int, a: int, issue: string, texte: string}>
     */
    private function coffresDuTournoi(): array
    {
        $de = 2;
        $lignes = [];

        foreach (ResolveurTour::TRESOR_TOURNOI as $a => $ligne) {
            $lignes[] = ['de' => $de, 'a' => (int) $a, 'issue' => $ligne['issue'], 'texte' => $this->texteCoffre($ligne)];
            $de = (int) $a + 1;
        }

        return $lignes;
    }

    /** Une ligne de la table de fouille, en phrase. Vocabulaire fermé : une issue inconnue lève une erreur, jamais une phrase vide. */
    private function texteCoffre(array $ligne): string
    {
        return match ($ligne['issue']) {
            'piege' => "{$ligne['piege']} : −{$ligne['degats']} PV au fouilleur",
            'objet' => "{$ligne['objet']} : dans le sac de la doublure, perdu avec elle",
            'or' => "{$ligne['or']} po à la bourse du combat",
        };
    }

    /**
     * Des cases de la salle centrale (colonne, rangée) en coordonnées de la grille du hall.
     *
     * @param  list<array{0: int, 1: int}>  $cases
     * @return list<array{x: int, y: int}>
     */
    private function casesDuPlan(array $cases, HallArene $hall): array
    {
        return array_values(array_map(function (array $c) use ($hall): array {
            [$x, $y] = $hall->caseDeLaSalle($c[0], $c[1]);

            return ['x' => $x, 'y' => $y];
        }, $cases));
    }

    /**
     * Registre des paquets de cartes sources (`config/cartes.php`), enrichi du
     * nom d'affichage : une carte portée prend le nom de notre catalogue, une
     * carte écartée garde le nom qu'on lui a donné en français.
     *
     * @return array<string, mixed>
     */
    private function cartes(): array
    {
        $paquets = [];

        // ⚠ QUATRIÈME paquet depuis le 2026-09-03 : les parchemins. Ils ont
        // leur section parce qu'ils DÉRIVENT d'un sort et n'ont pas de ligne
        // d'objet — mais ils ont autant leur place sur /guide, c'est la seule
        // page qui dise au joueur ce qui existe au plateau sans tourner ici.
        // ⚠ CINQUIÈME paquet depuis le 2026-09-04 : les sorts de Dread. Ils
        // n'appartiennent à aucun héros et ne s'achètent nulle part — mais ce
        // sont eux que la table subit, et /guide est la seule page qui dise
        // d'où vient ce qui vous tombe dessus.
        // ⚠ SIXIÈME paquet depuis le 2026-10-08 : les sorts de héros de Wizards
        // of Morcar (trois répertoires optionnels). Même raison que `dread` : des
        // cartes de sort, portées par une ligne de `sorts` et non d'`objets`.
        // Illustrations des cartes de DREAD (2026-10-16) : une requête pour tout le paquet,
        // et l'emblème en repli. Les autres paquets n'en ont pas — leur `image_url` reste `null`.
        $biblio = app(BibliothequeImages::class);
        $idsDread = SortDread::query()->pluck('id', 'nom');

        foreach (['equipement', 'potions', 'artefacts', 'parchemins', 'dread', 'sorts_heros'] as $cle) {
            $paquet = (array) config("cartes.{$cle}", []);

            $paquets[] = [
                'cle' => $cle,
                'libelle' => $paquet['libelle'] ?? $cle,
                'source' => $paquet['source'] ?? null,
                'url' => $paquet['url'] ?? null,
                'cartes' => array_map(static fn (array $c) => [
                    'carte' => $c['carte'],
                    // Un `sort` (parchemin, sort de héros) porte le nom du catalogue :
                    // « une carte portée prend le nom de notre catalogue ».
                    'nom' => $c['objet'] ?? $c['sort_dread'] ?? $c['sort'] ?? $c['nom'] ?? $c['carte'],
                    'paquet' => $c['paquet'] ?? null,
                    // ⚠ Un PARCHEMIN est porté par un `sort`, pas par un
                    // `objet` : il dérive d'une ligne de `sorts` et n'a pas
                    // d'entrée au catalogue d'objets. Ne regarder que `objet`
                    // le faisait passer pour non porté, et le guide réclamait
                    // alors un texte de plateau à une carte qui tourne déjà.
                    // ⚠ Trois clés de portage, trois familles : un `objet` au
                    // catalogue d'objets, un `sort` de héros dont le parchemin
                    // dérive, un `sort_dread` du catalogue de la magie du MJ.
                    // N'en regarder qu'une faisait passer les autres pour non
                    // portées, et le guide réclamait alors un texte de plateau
                    // à des cartes qui tournent déjà.
                    'porte' => isset($c['objet']) || isset($c['sort']) || isset($c['sort_dread']),
                    'texte' => $c['texte'] ?? null,
                    'manque' => $c['manque'] ?? null,
                    // L'illustration de la carte de Dread, emblème `sort_dread` en repli (`null` hors du paquet).
                    'image_url' => isset($c['sort_dread'])
                        ? $biblio->urlSortDread($idsDread[$c['sort_dread']] ?? null, (string) $c['sort_dread'])
                        : null,
                ], (array) ($paquet['cartes'] ?? [])),
            ];
        }

        return $paquets;
    }
}
