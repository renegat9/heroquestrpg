<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Partie\AssembleurCarte;
use App\Partie\Grille;
use App\Partie\MoteurPieges;
use App\Partie\MoteurPortes;
use InvalidArgumentException;

/**
 * Le producteur de carte d'une quête construite (MODE CLASSIQUE, lot K2 — `docs/plan-mode-classique.md` §4).
 *
 * Rend EXACTEMENT les clés de premier niveau de {@see AssembleurCarte::assembler()} : tout l'aval (`FabriqueGrille::pour()`,
 * brouillard, menus, table, manette) reste inchangé. Ce producteur lit un document et ne tire aucun nombre aléatoire ;
 * `AssembleurCarte` n'est ni repris ni modifié.
 *
 * Un document qui ne passe pas {@see ValidationCampagne} est REFUSÉ ({@see CarteRefusee}), jamais réparé.
 *
 * La géométrie (un mur est une case) est celle de `docs/contrat-api.md` « Géométrie : une salle, son anneau, ses couloirs ».
 *
 * Décisions de forme, une fois pour toutes :
 * - `theme` : `'generique'` sur chaque salle. C'est le thème par défaut (celui que lit `GenererRecitsQuete` sans valeur)
 *   et le seul qui ne prétend rien : `'boss'` ferait décrire une salle de boss à l'IA.
 * - `aretes` : `[]`. Une arête relie deux SALLES par une jonction de couloir ; `DeckFouille` la lit pour les coffres
 *   derrière une porte secrète, et `MoteurSorts` pour l'option « ouvrir la porte » par sort. Or tout ce qui n'est pas
 *   dans une salle est couloir : les portes donnent sur un même champ continu, et le graphe des salles n'est pas défini
 *   par la géométrie seule. Le fabriquer serait inventer. Conséquence (rapport K2) : ces deux options sont muettes sur
 *   une carte construite tant que K5 ne tranche pas.
 * - `passage_secret` : vrai s'il existe au moins une porte `secrete`. `DemarreurQuete` le lit pour son compteur de pitié :
 *   une carte qui porte un passage secret ne doit pas compter comme une carte sans.
 * - `spawn_monstres`, `spawn_figures_fixes` : `[]`. Les monstres voyagent dans `monstres` (hors de `carte`), que le lot K5
 *   posera au démarrage ; aucun tirage de `DemarreurQuete` ne doit s'y ajouter.
 * - `leviers`, `epreuves`, `dangers` : `[]`. Le format de quête ne les décrit pas encore.
 * - `spawn_heros` : l'escalier d'abord, puis les autres cases libres de l'intérieur de la salle de départ, de la plus
 *   proche de l'escalier à la plus lointaine (même ordre que `AssembleurCarte::spawnsDepuisEscalier()`), plafonné à
 *   `AssembleurCarte::MAX_SPAWNS_HEROS`. « Libre » : ni meuble, ni monstre, ni piège, ni terrain.
 */
final class CarteConstruite
{
    /** Thème posé sur chaque salle : voir le docblock de la classe. */
    public const THEME_SALLE = 'generique';

    public function __construct(
        private readonly ValidationCampagne $validation,
        private readonly CatalogueConstructeur $catalogue,
    ) {}

    /**
     * @param  array<string, mixed>  $campagne  le document de la campagne (format 1), sans clé englobante
     * @param  array<string, mixed>  $quete     une des quêtes de `$campagne['quetes']`, à l'identique
     * @return array{carte: array<string, mixed>, monstres: list<array{monstre_id: int, x: int, y: int}>}
     *
     * @throws CarteRefusee si le document ne passe pas la validation
     * @throws InvalidArgumentException si la quête n'appartient pas à ce document
     */
    public function produire(array $campagne, array $quete): array
    {
        $refus = $this->validation->valider($campagne);
        if ($refus !== []) {
            throw new CarteRefusee($refus);
        }
        if (! in_array($quete, $campagne['quetes'], true)) {
            throw new InvalidArgumentException("La quête donnée n'est pas une quête de cette campagne.");
        }

        $largeur = (int) $campagne['plateau']['largeur'];
        $hauteur = (int) $campagne['plateau']['hauteur'];
        $salles = array_values($campagne['plateau']['salles']);
        $ouvertes = array_fill_keys($quete['salles_ouvertes'], true);

        // Salle 0 = celle qui contient le départ (ouverte : la validation l'exige) ; les autres suivent l'ordre du plateau.
        $idDepart = self::interieurEn($salles, (int) $quete['depart']['x'], (int) $quete['depart']['y']);
        $ordre = [];
        foreach ($salles as $salle) {
            if ($salle['id'] === $idDepart) {
                array_unshift($ordre, $salle);
            } elseif (isset($ouvertes[$salle['id']])) {
                $ordre[] = $salle;
            }
        }
        $indexSalle = [];
        foreach ($ordre as $index => $salle) {
            $indexSalle[$salle['id']] = $index;
        }

        // Cases : roche partout ; intérieur d'une salle OUVERTE et couloir (tout ce qui n'est dans aucune salle) = sol.
        $cases = array_fill(0, $hauteur, array_fill(0, $largeur, 'm'));
        for ($y = 0; $y < $hauteur; $y++) {
            for ($x = 0; $x < $largeur; $x++) {
                $interieur = self::interieurEn($salles, $x, $y);
                $sol = $interieur !== null ? isset($ouvertes[$interieur]) : ! self::dansUneRect($salles, $x, $y);
                if ($sol) {
                    $cases[$y][$x] = 's';
                }
            }
        }

        // Portes : l'embrasure s'ouvre dans l'anneau ; l'arête moteur est celle que `Grille` lit.
        // JONCTIONS (`aretes`) : quelles salles chaque porte relie. Le moteur le lit pour le sort « ouvrir une porte au
        // choix » (`MoteurSorts::entreesPorteAuChoix()`), qui n'offre que les portes d'une salle DÉCOUVERTE. Une porte
        // entre deux salles qui partagent un mur relie ces deux salles ; une porte qui donne sur un COULOIR ne relie que
        // sa propre salle — un couloir n'est pas une salle, et le moteur ne retient pas quels couloirs ont été vus.
        // ⚠ Limite assumée : la porte d'une salle encore inconnue, vue depuis le couloir, n'est donc pas offerte au sort
        // (elle s'ouvre toujours à la main, au contact). L'offrir demanderait de les offrir TOUTES dès le premier tour,
        // ce qui révélerait le donjon. À revoir quand les couloirs auront un état de découverte (lot K5).
        $portes = [];
        $aretes = [];
        $passageSecret = false;
        foreach ($quete['portes'] as $porte) {
            $geo = self::porteMoteur($porte);
            $cases[$geo['embrasure']['y']][$geo['embrasure']['x']] = 's';

            $salleA = $indexSalle[self::interieurEn($salles, (int) $porte['x'], (int) $porte['y'])];
            $dehors = self::caseDehors($porte);
            $idDehors = self::interieurEn($salles, $dehors['x'], $dehors['y']);
            $salleB = $idDehors !== null && isset($indexSalle[$idDehors]) ? $indexSalle[$idDehors] : $salleA;
            $seuil = ['x' => $geo['arete']['x'], 'y' => $geo['arete']['y']];
            $aretes[] = ['a' => $salleA, 'b' => $salleB, 'porte_a' => $seuil, 'porte_b' => $seuil];

            $secrete = $porte['type'] === 'secrete';
            $passageSecret = $passageSecret || $secrete;
            $entree = [
                'x' => $geo['arete']['x'], 'y' => $geo['arete']['y'], 'cote' => $geo['arete']['cote'],
                'etat' => $secrete ? MoteurPortes::ETAT_SECRETE : MoteurPortes::ETAT_FERMEE,
            ];
            if ($secrete) {
                $entree['revele'] = false;
            }
            // Comme dans l'assembleur : la porte porte le numéro de SA jonction ; aucune boucle n'est déclarée ici.
            $entree['jonction'] = count($aretes) - 1;
            $entree['boucle'] = false;
            $portes[] = $entree;
        }

        // Blocs : des cases de couloir remises en roche.
        foreach ($quete['blocs'] as $bloc) {
            $cases[(int) $bloc['y']][(int) $bloc['x']] = 'm';
        }

        $sallesMoteur = [];
        foreach ($ordre as $salle) {
            $x = $salle['x'] - 1;
            $y = $salle['y'] - 1;
            $w = $salle['l'] + 2;
            $h = $salle['h'] + 2;
            $sallesMoteur[] = [
                'x' => $x, 'y' => $y, 'largeur' => $w, 'hauteur' => $h, 'theme' => self::THEME_SALLE,
                'mediane_x' => $x + intdiv($w, 2), 'mediane_y' => $y + intdiv($h, 2),
            ];
        }

        $escalier = ['x' => (int) $quete['depart']['x'], 'y' => (int) $quete['depart']['y'], 'l' => 2, 'h' => 2];
        $occupees = [];
        foreach (Grille::cellulesEmprise($escalier['x'], $escalier['y'], 2, 2) as $c) {
            $occupees["{$c['x']},{$c['y']}"] = true;
        }

        $catalogueMobilier = $this->catalogue->index('mobilier');
        $mobilier = [];
        foreach ($quete['mobilier'] as $meuble) {
            $type = $catalogueMobilier[$meuble['slug']];
            [$l, $h] = ValidationCampagne::empriseMeuble((int) $type->largeur, (int) $type->hauteur, (int) $meuble['rotation']);
            $x = (int) $meuble['x'];
            $y = (int) $meuble['y'];
            $mobilier[] = [
                'mobilier_id' => (int) $type->id, 'x' => $x, 'y' => $y, 'l' => $l, 'h' => $h,
                'salle' => $indexSalle[self::interieurEn($salles, $x, $y)],
            ];
            foreach (Grille::cellulesEmprise($x, $y, $l, $h) as $c) {
                $occupees["{$c['x']},{$c['y']}"] = true;
            }
        }

        $cataloguePieges = $this->catalogue->index('pieges');
        $pieges = [];
        foreach ($quete['pieges'] as $piege) {
            $x = (int) $piege['x'];
            $y = (int) $piege['y'];
            $pieges[] = [
                'x' => $x, 'y' => $y, 'piege_id' => (int) $cataloguePieges[$piege['slug']]->id,
                'etat' => MoteurPieges::ETAT_CACHE,
            ];
            $occupees["$x,$y"] = true;
        }

        $catalogueTerrain = $this->catalogue->index('terrain');
        $terrain = [];
        foreach ($quete['terrain'] as $tuile) {
            $x = (int) $tuile['x'];
            $y = (int) $tuile['y'];
            $terrain[] = ['x' => $x, 'y' => $y, 'terrain_id' => (int) $catalogueTerrain[$tuile['slug']]->id];
            $occupees["$x,$y"] = true;
        }

        $catalogueMonstres = $this->catalogue->index('monstres');
        $monstres = [];
        foreach ($quete['monstres'] as $monstre) {
            $x = (int) $monstre['x'];
            $y = (int) $monstre['y'];
            $monstres[] = ['monstre_id' => (int) $catalogueMonstres[$monstre['slug']]->id, 'x' => $x, 'y' => $y];
            $occupees["$x,$y"] = true;
        }

        $salleDepart = $sallesMoteur === [] ? null : $ordre[0];

        return [
            'carte' => [
                'largeur' => $largeur,
                'hauteur' => $hauteur,
                'cases' => $cases,
                'salles' => $sallesMoteur,
                'portes' => $portes,
                'aretes' => $aretes,
                'passage_secret' => $passageSecret,
                'leviers' => [],
                'pieges' => $pieges,
                'mobilier' => $mobilier,
                'epreuves' => [],
                'terrain' => $terrain,
                'dangers' => [],
                'escalier' => $escalier,
                'spawn_heros' => self::spawnsHeros($salleDepart, $escalier, $occupees),
                'spawn_monstres' => [],
                'spawn_figures_fixes' => [],
            ],
            'monstres' => $monstres,
        ];
    }

    /**
     * La carte, en texte, pour relire une quête sans écran : une ligne par rangée, puis une légende qui donne le slug et la
     * case de chaque élément.
     *
     * `#` roche (mur, bloc, salle fermée) · `.` sol · `+` embrasure d'une porte normale · `?` embrasure d'une porte
     * secrète · `<` escalier de départ · `M` monstre · `m` meuble (toute son emprise) · `^` piège · `~` terrain.
     *
     * @param  array<string, mixed>  $campagne
     * @param  array<string, mixed>  $quete
     *
     * @throws CarteRefusee
     */
    public function apercu(array $campagne, array $quete): string
    {
        $produit = $this->produire($campagne, $quete);
        $carte = $produit['carte'];

        $grille = [];
        foreach ($carte['cases'] as $y => $ligne) {
            $grille[$y] = array_map(static fn (string $case): string => $case === 's' ? '.' : '#', $ligne);
        }
        foreach ($carte['portes'] as $porte) {
            $embrasure = Grille::caseEmbrasure($porte, $carte['salles']);
            $grille[$embrasure['y']][$embrasure['x']] = $porte['etat'] === MoteurPortes::ETAT_SECRETE ? '?' : '+';
        }
        foreach ($carte['terrain'] as $tuile) {
            $grille[$tuile['y']][$tuile['x']] = '~';
        }
        foreach ($carte['pieges'] as $piege) {
            $grille[$piege['y']][$piege['x']] = '^';
        }
        foreach ($carte['mobilier'] as $meuble) {
            foreach (Grille::cellulesEmprise($meuble['x'], $meuble['y'], $meuble['l'], $meuble['h']) as $c) {
                $grille[$c['y']][$c['x']] = 'm';
            }
        }
        foreach (Grille::cellulesEmprise($carte['escalier']['x'], $carte['escalier']['y'], 2, 2) as $c) {
            $grille[$c['y']][$c['x']] = '<';
        }
        foreach ($produit['monstres'] as $monstre) {
            $grille[$monstre['y']][$monstre['x']] = 'M';
        }

        $rangees = array_map(static fn (array $ligne): string => implode('', $ligne), $grille);

        return implode("\n", $rangees)."\n\nLégende :\n".implode("\n", $this->legende($quete));
    }

    /**
     * Les coordonnées moteur d'une porte K1 : l'embrasure (la case de l'anneau) et l'arête `{x, y, cote}` que lit `Grille`.
     * La table est celle de `docs/contrat-api.md` (« Géométrie ») ; `CarteConstruiteTest` la vérifie contre
     * `Grille::caseEmbrasure()` pour les quatre côtés.
     *
     * @param  array{x: int, y: int, cote: string}  $porte
     * @return array{embrasure: array{x: int, y: int}, arete: array{x: int, y: int, cote: string}}
     */
    public static function porteMoteur(array $porte): array
    {
        $x = (int) $porte['x'];
        $y = (int) $porte['y'];

        return match ($porte['cote']) {
            'n' => ['embrasure' => ['x' => $x, 'y' => $y - 1], 'arete' => ['x' => $x, 'y' => $y - 2, 'cote' => 's']],
            's' => ['embrasure' => ['x' => $x, 'y' => $y + 1], 'arete' => ['x' => $x, 'y' => $y + 1, 'cote' => 's']],
            'e' => ['embrasure' => ['x' => $x + 1, 'y' => $y], 'arete' => ['x' => $x + 1, 'y' => $y, 'cote' => 'e']],
            'o' => ['embrasure' => ['x' => $x - 1, 'y' => $y], 'arete' => ['x' => $x - 2, 'y' => $y, 'cote' => 'e']],
        };
    }

    /**
     * La case située JUSTE DEHORS d'une porte K1 : au-delà de son embrasure, dans le sens de son côté. Un couloir, ou
     * l'intérieur de la salle voisine quand les deux salles partagent le mur.
     *
     * @param  array{x: int, y: int, cote: string}  $porte
     * @return array{x: int, y: int}
     */
    public static function caseDehors(array $porte): array
    {
        $x = (int) $porte['x'];
        $y = (int) $porte['y'];

        return match ($porte['cote']) {
            'n' => ['x' => $x, 'y' => $y - 2],
            's' => ['x' => $x, 'y' => $y + 2],
            'e' => ['x' => $x + 2, 'y' => $y],
            'o' => ['x' => $x - 2, 'y' => $y],
        };
    }

    /**
     * La légende : une ligne par élément, avec son slug et sa case. Les portes et les blocs n'ont pas de slug ; ils sont
     * nommés par leur case. L'errant n'est pas sur la carte : il est signalé à part.
     *
     * @param  array<string, mixed>  $quete
     * @return list<string>
     */
    private function legende(array $quete): array
    {
        $lignes = [];
        foreach ($quete['portes'] as $porte) {
            $geo = self::porteMoteur($porte);
            $nature = $porte['type'] === 'secrete' ? '? porte secrète' : '+ porte normale';
            $lignes[] = "  $nature : intérieur ({$porte['x']}, {$porte['y']}), côté {$porte['cote']}, "
                ."embrasure ({$geo['embrasure']['x']}, {$geo['embrasure']['y']})";
        }
        foreach ($quete['blocs'] as $bloc) {
            $lignes[] = "  # bloc : case ({$bloc['x']}, {$bloc['y']})";
        }
        $lignes[] = "  < escalier de départ : coin ({$quete['depart']['x']}, {$quete['depart']['y']}), 2×2";
        foreach ($quete['mobilier'] as $meuble) {
            $type = $this->catalogue->index('mobilier')[$meuble['slug']];
            [$l, $h] = ValidationCampagne::empriseMeuble((int) $type->largeur, (int) $type->hauteur, (int) $meuble['rotation']);
            $lignes[] = "  m {$meuble['slug']} : ancre ({$meuble['x']}, {$meuble['y']}), rotation {$meuble['rotation']}, "
                ."emprise {$l}×{$h}";
        }
        foreach ($quete['monstres'] as $monstre) {
            $lignes[] = "  M {$monstre['slug']} : case ({$monstre['x']}, {$monstre['y']})";
        }
        foreach ($quete['pieges'] as $piege) {
            $lignes[] = "  ^ {$piege['slug']} : case ({$piege['x']}, {$piege['y']})";
        }
        foreach ($quete['terrain'] as $tuile) {
            $lignes[] = "  ~ {$tuile['slug']} : case ({$tuile['x']}, {$tuile['y']})";
        }
        if (($quete['monstre_errant'] ?? null) !== null) {
            $lignes[] = "  monstre errant (hors carte) : {$quete['monstre_errant']}";
        }

        return $lignes;
    }

    /**
     * `spawn_heros` : l'escalier d'abord (ses quatre cases, ligne par ligne), puis les autres cases libres de l'intérieur de
     * la salle de départ, de la plus proche de l'escalier à la plus lointaine. À égalité, l'ordre de lecture départage
     * (tri stable).
     *
     * @param  array{id: string, x: int, y: int, l: int, h: int}  $salleDepart
     * @param  array{x: int, y: int, l: int, h: int}  $escalier
     * @param  array<string, true>  $occupees  « x,y » des cases déjà prises (escalier compris)
     * @return list<array{x: int, y: int}>
     */
    private static function spawnsHeros(?array $salleDepart, array $escalier, array $occupees): array
    {
        $marches = [];
        foreach (Grille::cellulesEmprise($escalier['x'], $escalier['y'], $escalier['l'], $escalier['h']) as $c) {
            $marches[] = $c;
        }
        if ($salleDepart === null) {
            return $marches;
        }

        $libres = [];
        for ($y = $salleDepart['y']; $y < $salleDepart['y'] + $salleDepart['h']; $y++) {
            for ($x = $salleDepart['x']; $x < $salleDepart['x'] + $salleDepart['l']; $x++) {
                if (! isset($occupees["$x,$y"])) {
                    $libres[] = ['x' => $x, 'y' => $y];
                }
            }
        }

        $distance = static fn (array $p): int => max(
            max(0, $escalier['x'] - $p['x'], $p['x'] - ($escalier['x'] + $escalier['l'] - 1)),
            max(0, $escalier['y'] - $p['y'], $p['y'] - ($escalier['y'] + $escalier['h'] - 1)),
        );
        usort($libres, static fn (array $a, array $b): int => $distance($a) <=> $distance($b));

        return array_slice([...$marches, ...$libres], 0, AssembleurCarte::MAX_SPAWNS_HEROS);
    }

    /** L'id de la salle dont l'INTÉRIEUR contient (x, y), ou `null`. Les intérieurs ne se chevauchent pas (validé). */
    private static function interieurEn(array $salles, int $x, int $y): ?string
    {
        foreach ($salles as $salle) {
            if ($x >= $salle['x'] && $x < $salle['x'] + $salle['l'] && $y >= $salle['y'] && $y < $salle['y'] + $salle['h']) {
                return (string) $salle['id'];
            }
        }

        return null;
    }

    /** (x, y) est-elle dans le rectangle moteur d'une salle (mur compris), ouverte ou non ? */
    private static function dansUneRect(array $salles, int $x, int $y): bool
    {
        foreach ($salles as $salle) {
            if ($x >= $salle['x'] - 1 && $x <= $salle['x'] + $salle['l'] && $y >= $salle['y'] - 1 && $y <= $salle['y'] + $salle['h']) {
                return true;
            }
        }

        return false;
    }
}
