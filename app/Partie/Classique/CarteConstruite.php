<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Danger;
use App\Partie\AssembleurCarte;
use App\Partie\Grille;
use App\Partie\MoteurDanger;
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
 * GÉOMÉTRIE — MURS EN ARÊTES (René, 2026-11-14 : « si on veut respecter le plateau, les portes entre deux salles ne
 * sont pas une case mais le bord commun entre deux cases »). Une salle du plateau `{x, y, l, h}` est exactement ses
 * cases : pas d'anneau de murs autour. Deux salles peuvent se toucher ; tout ce qui n'est dans aucune salle est
 * couloir ; une salle fermée est de la roche. Les MURS sont le pourtour de chaque salle ouverte, posés sur les arêtes
 * (`carte.murs`), et une porte est une arête de ce pourtour (`arete: true`), sans case d'embrasure.
 *
 * Décisions de forme, une fois pour toutes :
 * - `theme` : `'generique'` sur chaque salle. C'est le thème par défaut (celui que lit `GenererRecitsQuete` sans valeur)
 *   et le seul qui ne prétend rien : `'boss'` ferait décrire une salle de boss à l'IA.
 * - `aretes` : une jonction par porte (voir `produire()`), lue par `MoteurSorts` pour le sort « ouvrir une porte au choix ».
 * - `passage_secret` : vrai s'il existe au moins une porte `secrete`. `DemarreurQuete` le lit pour son compteur de pitié :
 *   une carte qui porte un passage secret ne doit pas compter comme une carte sans.
 * - `spawn_monstres`, `spawn_figures_fixes` : `[]`. Les monstres voyagent dans `monstres` (hors de `carte`), que le lot K5
 *   posera au démarrage ; aucun tirage de `DemarreurQuete` ne doit s'y ajouter.
 * - `leviers`, `epreuves`, `dangers` : `[]`. Le format de quête ne les décrit pas encore.
 * - `spawn_heros` : l'escalier d'abord, puis les autres cases libres de l'intérieur de la salle de départ, de la plus
 *   proche de l'escalier à la plus lointaine (même ordre que `AssembleurCarte::spawnsDepuisEscalier()`), plafonné à
 *   `AssembleurCarte::MAX_SPAWNS_HEROS`. « Libre » : ni meuble, ni monstre, ni piège, ni terrain. Entrée par une porte
 *   de COULOIR : la case devant elle, puis les cases libres du même couloir, au plus près en pas (`spawnsCouloir()`).
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
        // SALLES SPÉCIALES (lot K6, R11) : EN TÊTE de la liste — la première salle qui contient une case la possède
        // (`interieurEn()`). Une salle du plateau qu'elles touchent n'est jamais ouverte (la validation le refuse) : son
        // reste est de la roche, et le mur se pose au bord de la salle spéciale, comme entre toute salle et la roche.
        $speciales = array_values((array) ($quete['salles_speciales'] ?? []));
        $salles = [...$speciales, ...array_values($campagne['plateau']['salles'])];
        $ouvertes = array_fill_keys([...$quete['salles_ouvertes'], ...array_column($speciales, 'id')], true);

        // Salle 0 = celle qui contient le départ (ouverte : la validation l'exige) ; les autres suivent l'ordre du plateau.
        // Une porte d'ENTRÉE dans un COULOIR (René, 2026-11-16) n'est dans aucune salle : `$idDepart` vaut `null`, aucune
        // salle n'est « de départ », et la salle 0 n'est que la première salle ouverte du plateau — `acces.salle_depart`
        // le dit, pour que la quête ne la tienne pas pour découverte (`Quete::sallesDecouvertes()`).
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

        // Cases : une salle OUVERTE et le couloir (tout ce qui n'est dans aucune salle) sont du sol ; une salle FERMÉE est
        // de la roche. Aucune case n'est un mur : les murs sont des arêtes, plus bas.
        $cases = array_fill(0, $hauteur, array_fill(0, $largeur, 'm'));
        $estSol = static function (int $x, int $y) use ($salles, $ouvertes, $largeur, $hauteur): bool {
            if ($x < 0 || $y < 0 || $x >= $largeur || $y >= $hauteur) {
                return false;
            }
            $salle = self::interieurEn($salles, $x, $y);

            return $salle === null || isset($ouvertes[$salle]);
        };
        for ($y = 0; $y < $hauteur; $y++) {
            for ($x = 0; $x < $largeur; $x++) {
                if ($estSol($x, $y)) {
                    $cases[$y][$x] = 's';
                }
            }
        }

        // Portes : chacune est le bord commun de sa case et de la case sur laquelle elle donne.
        // JONCTIONS (`aretes`) : quelles salles chaque porte relie. Le moteur le lit pour le sort « ouvrir une porte au
        // choix » (`MoteurSorts::entreesPorteAuChoix()`), qui n'offre que les portes d'une salle DÉCOUVERTE. Une porte
        // entre deux salles qui partagent un mur relie ces deux salles ; une porte qui donne sur un COULOIR ne relie que
        // sa propre salle — un couloir n'est pas une salle, et le moteur ne retient pas quels couloirs ont été vus.
        // ⚠ Limite assumée : la porte d'une salle encore inconnue, vue depuis le couloir, n'est donc pas offerte au sort
        // (elle s'ouvre toujours à la main, au contact). L'offrir demanderait de les offrir TOUTES dès le premier tour,
        // ce qui révélerait le donjon. À revoir quand les couloirs auront un état de découverte (lot K5).
        $portes = [];
        $aretes = [];
        $bordsDePorte = [];
        $passageSecret = false;
        foreach ($quete['portes'] as $porte) {
            $geo = self::porteMoteur($porte);

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
                // SUR UNE ARÊTE : la porte est le bord commun de deux cases, sans embrasure (`Grille::porteSurArete()`).
                'arete' => true,
            ];
            $bordsDePorte[Grille::cleArete((int) $porte['x'], (int) $porte['y'], $dehors['x'], $dehors['y'])] = true;
            if ($secrete) {
                $entree['revele'] = false;
            }
            // VERROU (lot K5a) : la forme que `MoteurPortes` lit déjà sur une carte générée. Une porte `note` ou `cle` est
            // VERROUILLÉE (cadenas) ; la pierre et la condition restent des portes CLOSES qu'un jet ouvre.
            if (is_array($porte['verrou'] ?? null)) {
                $entree = [...$entree, ...$this->verrouMoteur((array) $porte['verrou'])];
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

        // MURS : le pourtour de chaque salle OUVERTE, là où il donne sur du sol (un couloir, ou une autre salle ouverte)
        // et où aucune porte n'est posée. Face à la roche ou au bord du plateau, la roche suffit. Un mur partagé par
        // deux salles n'est écrit qu'une fois. Un bloc est de la roche : il se pose après, et rien ne le mure.
        $murs = [];
        $vus = [];
        foreach ($ordre as $salle) {
            for ($y = $salle['y']; $y < $salle['y'] + $salle['h']; $y++) {
                for ($x = $salle['x']; $x < $salle['x'] + $salle['l']; $x++) {
                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                        [$nx, $ny] = [$x + $dx, $y + $dy];
                        if (self::interieurEn($salles, $nx, $ny) === $salle['id'] || ! $estSol($nx, $ny)) {
                            continue;
                        }
                        $cle = Grille::cleArete($x, $y, $nx, $ny);
                        if (isset($vus[$cle]) || isset($bordsDePorte[$cle])) {
                            continue;
                        }
                        $vus[$cle] = true;
                        // La forme des portes : l'arête EST ou SUD de la case la plus à gauche / la plus haute.
                        $murs[] = $dx !== 0
                            ? ['x' => min($x, $nx), 'y' => $y, 'cote' => 'e']
                            : ['x' => $x, 'y' => min($y, $ny), 'cote' => 's'];
                    }
                }
            }
        }

        $sallesMoteur = [];
        foreach ($ordre as $salle) {
            // Sans anneau : le rectangle moteur d'une salle est exactement ses cases.
            $x = $salle['x'];
            $y = $salle['y'];
            $w = $salle['l'];
            $h = $salle['h'];
            $sallesMoteur[] = [
                // `id` : le nom que l'AUTEUR donne à la salle. Le moteur compte en index ; les notes de quête, elles,
                // désignent une salle par ce nom (`NotesDeQuete::cibleAtteinte()`).
                'id' => $salle['id'],
                'x' => $x, 'y' => $y, 'largeur' => $w, 'hauteur' => $h, 'theme' => self::THEME_SALLE,
                'mediane_x' => $x + intdiv($w, 2), 'mediane_y' => $y + intdiv($h, 2),
            ];
        }

        // ACCÈS : par où l'on entre et par où l'on sort ({@see self::acces()}). L'ENTRÉE ancre les héros : les marches
        // d'un escalier, ou la case devant une porte d'entrée. La carte ne porte un `escalier` que si l'on entre par un
        // escalier ; par une porte, il vaut `null` — le repli que tous ses lecteurs connaissent déjà.
        $acces = self::acces($quete);
        $ancre = $acces['entree']['ancre'];
        $escalier = $acces['entree']['type'] === ValidationCampagne::ACCES_ESCALIER ? $ancre : null;
        $occupees = [];
        foreach (Grille::cellulesEmprise($ancre['x'], $ancre['y'], $ancre['l'], $ancre['h']) as $c) {
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
                // Lot K5d : les drapeaux de l'auteur, lus par `MoteurPieges::trouvable()` / `desarmable()`. Posés
                // seulement quand ils valent true — jamais une clé `false` décorative.
                ...(($piege['introuvable'] ?? false) === true ? ['introuvable' => true] : []),
                ...(($piege['indesarmable'] ?? false) === true ? ['indesarmable' => true] : []),
            ];
            $occupees["$x,$y"] = true;
        }

        $catalogueTerrain = $this->catalogue->index('terrain');
        $terrain = [];
        foreach ($quete['terrain'] as $tuile) {
            $x = (int) $tuile['x'];
            $y = (int) $tuile['y'];
            $terrain[] = [
                'x' => $x, 'y' => $y, 'terrain_id' => (int) $catalogueTerrain[$tuile['slug']]->id,
                ...(isset($tuile['paire_id']) ? ['paire_id' => (string) $tuile['paire_id']] : []),
                // E5 : posée au premier pas d'un héros — lue par `EtatGroupe::terrain()` contre les cases foulées.
                ...(($tuile['cachee'] ?? false) === true ? ['cachee' => true] : []),
            ];
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

        $dangers = [];
        if (($quete['dangers'] ?? []) !== []) {
            $idsDanger = Danger::query()->pluck('id', 'nom')->all();
            foreach ($quete['dangers'] as $danger) {
                if (($danger['type'] ?? null) === 'rocher') {
                    $run = array_values(array_map(static fn (array $c): array => [(int) $c['x'], (int) $c['y']], $danger['run']));
                    $dangers[] = [
                        'danger_id' => isset($idsDanger['Rocher roulant']) ? (int) $idsDanger['Rocher roulant'] : null,
                        'nom' => 'Rocher roulant',
                        'etat' => MoteurDanger::ETAT_ATTENTE,
                        'run' => $run,
                        'i' => 0,
                        'x' => $run[0][0],
                        'y' => $run[0][1],
                        'franchi' => [],
                    ];
                } elseif (($danger['type'] ?? null) === 'brume') {
                    $dangers[] = [
                        'danger_id' => isset($idsDanger['Brume de la mort']) ? (int) $idsDanger['Brume de la mort'] : null,
                        'nom' => 'Brume de la mort',
                        'etat' => MoteurDanger::ETAT_ACTIF,
                        'x' => (int) $danger['x'],
                        'y' => (int) $danger['y'],
                    ];
                }
            }
        }

        $salleDepart = $sallesMoteur === [] ? null : $ordre[0];

        return [
            'carte' => [
                'largeur' => $largeur,
                'hauteur' => $hauteur,
                'cases' => $cases,
                'salles' => $sallesMoteur,
                'portes' => $portes,
                // MURS EN ARÊTES : `[{x, y, cote}]`, lus par `Grille::definirMurs()`, le brouillard et l'écran.
                'murs' => $murs,
                'aretes' => $aretes,
                'passage_secret' => $passageSecret,
                'leviers' => [],
                'pieges' => $pieges,
                'mobilier' => $mobilier,
                'epreuves' => [],
                'terrain' => $terrain,
                'dangers' => $dangers,
                'escalier' => $escalier,
                'spawn_heros' => $idDepart === null
                    ? self::spawnsCouloir($ancre, $cases, $salles, $occupees)
                    : self::spawnsHeros($salleDepart, $ancre, $occupees),
                'spawn_monstres' => [],
                'spawn_figures_fixes' => [],
            ],
            'monstres' => $monstres,
            // HORS de `carte` (dont les clés restent celles de l'assembleur) : par où l'on entre et par où l'on sort.
            // `DemarreurQueteConstruite` les fige sur la quête (`quetes.acces`), `Quete::sortieDuDonjon()` les lit.
            'acces' => [
                // L'index de la salle de DÉPART — 0, celle qui contient l'entrée — ou `null` quand on entre par une porte
                // de couloir. Lu par `Quete::sallesDecouvertes()` (la salle de départ est connue d'emblée, aucune autre)
                // et par le démarrage (un monstre de la salle de départ est vu d'emblée).
                'salle_depart' => $idDepart === null ? null : 0,
                'entree' => ['type' => $acces['entree']['type'], 'cases' => $acces['entree']['cases'], 'porte' => $acces['entree']['porte']],
                'sortie' => ['type' => $acces['sortie']['type'], 'cases' => $acces['sortie']['cases'], 'porte' => $acces['sortie']['porte']],
                'fausses_sorties' => array_map(
                    static fn (array $f): array => ['type' => $f['type'], 'cases' => $f['cases'], 'porte' => $f['porte'], 'revelee' => false],
                    $acces['fausses_sorties'],
                ),
                // QUÊTE DOUBLE (lot K5p) : le passage de la principale vers sa seconde moitié — clé absente sinon.
                ...($acces['passage'] !== null
                    ? ['passage' => ['type' => $acces['passage']['type'], 'cases' => $acces['passage']['cases'], 'porte' => $acces['passage']['porte']]]
                    : []),
            ],
        ];
    }

    /**
     * Le verrou d'une porte construite, dans la forme du moteur (`MoteurPortes`) : `etat` et `verrou`.
     *
     * @param  array<string, mixed>  $verrou  `{type: note|cle|pierre|condition, objet?, condition?}` (validé)
     * @return array{etat: string, verrou: array<string, mixed>}
     */
    private function verrouMoteur(array $verrou): array
    {
        return match ($verrou['type']) {
            MoteurPortes::VERROU_NOTE => ['etat' => MoteurPortes::ETAT_VERROUILLEE, 'verrou' => ['type' => MoteurPortes::VERROU_NOTE]],
            MoteurPortes::VERROU_CLE => ['etat' => MoteurPortes::ETAT_VERROUILLEE, 'verrou' => [
                'type' => MoteurPortes::VERROU_CLE,
                'objet_id' => (int) $this->catalogue->index('objets')[(string) $verrou['objet']]->id,
            ]],
            MoteurPortes::VERROU_PIERRE => ['etat' => MoteurPortes::ETAT_FERMEE, 'verrou' => ['type' => MoteurPortes::VERROU_PIERRE]],
            MoteurPortes::VERROU_CONDITION => ['etat' => MoteurPortes::ETAT_FERMEE, 'verrou' => [
                'type' => MoteurPortes::VERROU_CONDITION,
                'nom' => (string) $verrou['condition'],
                ...MoteurPortes::CONDITIONS[(string) $verrou['condition']],
            ]],
        };
    }

    /**
     * La carte, en texte, pour relire une quête sans écran : une ligne par rangée, puis une légende qui donne le slug et la
     * case de chaque élément.
     *
     * `#` roche (bloc, salle fermée) · `.` sol · `+` case qui porte une porte normale · `?` une porte secrète (le côté
     * est dans la légende ; les murs, des arêtes, ne se tracent pas ici) · `<` entrée · `>` sortie (si elle est distincte) · `M` monstre · `m` meuble (toute son emprise) · `^` piège · `~` terrain.
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
        // Une porte est un BORD de case : un caractère par case ne peut pas la tracer. On marque la case qui la porte ;
        // la légende dit de quel côté elle est. (Un élément posé sur cette case s'écrit par-dessus, plus bas.)
        foreach ($quete['portes'] as $porte) {
            $grille[(int) $porte['y']][(int) $porte['x']] = $porte['type'] === 'secrete' ? '?' : '+';
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
        // ACCÈS : `<` les cases d'entrée, `>` celles de sortie quand elle est distincte de l'entrée, `x` celles d'une
        // fausse porte de sortie (l'aperçu est pour l'AUTEUR : il les distingue, les joueurs non).
        foreach ($produit['acces']['fausses_sorties'] as $f) {
            foreach ($f['cases'] as $c) {
                $grille[$c['y']][$c['x']] = 'x';
            }
        }
        foreach ($produit['acces']['sortie']['cases'] as $c) {
            $grille[$c['y']][$c['x']] = '>';
        }
        foreach ($produit['acces']['entree']['cases'] as $c) {
            $grille[$c['y']][$c['x']] = '<';
        }
        foreach ($produit['monstres'] as $monstre) {
            $grille[$monstre['y']][$monstre['x']] = 'M';
        }

        $rangees = array_map(static fn (array $ligne): string => implode('', $ligne), $grille);

        return implode("\n", $rangees)."\n\nLégende :\n".implode("\n", $this->legende($quete));
    }

    /**
     * L'ARÊTE moteur d'une porte du format : `{x, y, cote}` désigne le bord de la case (x, y) du côté `cote` ; le moteur
     * nomme une arête par sa case la plus à gauche (côté `e`) ou la plus haute (côté `s`). `CarteConstruiteTest` vérifie
     * que `Grille::casesPorte()` rend bien les deux cases attendues, pour les quatre côtés.
     *
     * @param  array{x: int, y: int, cote: string}  $porte
     * @return array{arete: array{x: int, y: int, cote: string}}
     */
    public static function porteMoteur(array $porte): array
    {
        $x = (int) $porte['x'];
        $y = (int) $porte['y'];

        return ['arete' => match ($porte['cote']) {
            'n' => ['x' => $x, 'y' => $y - 1, 'cote' => 's'],
            's' => ['x' => $x, 'y' => $y, 'cote' => 's'],
            'e' => ['x' => $x, 'y' => $y, 'cote' => 'e'],
            'o' => ['x' => $x - 1, 'y' => $y, 'cote' => 'e'],
        }];
    }

    /**
     * LES ACCÈS d'une quête : l'entrée (`depart`) et la sortie (`sortie`, ou l'entrée quand elle est `null`).
     *
     * Chacun rend son `type` (`escalier` | `porte`), son `ancre` (le rectangle d'où partent les héros : le bloc 2×2 des
     * marches, ou la case devant la porte), ses `cases` (celles d'où un héros entre ou quitte le donjon) et, pour une
     * porte, `porte` = `{x, y, cote}`. Une porte d'accès mène HORS du donjon : son bord reste muré (ou face à la roche),
     * et elle n'entre pas dans `carte.portes` — rien ne peut l'ouvrir.
     *
     * @param  array<string, mixed>  $quete
     * @return array{entree: array<string, mixed>, sortie: array<string, mixed>}
     */
    public static function acces(array $quete): array
    {
        $un = static function (array $acces): array {
            $x = (int) $acces['x'];
            $y = (int) $acces['y'];

            if (($acces['type'] ?? ValidationCampagne::ACCES_ESCALIER) === ValidationCampagne::ACCES_PORTE) {
                return [
                    'type' => ValidationCampagne::ACCES_PORTE,
                    'ancre' => ['x' => $x, 'y' => $y, 'l' => 1, 'h' => 1],
                    'cases' => [['x' => $x, 'y' => $y]],
                    // La porte d'accès est le bord de la case (x, y) du côté `cote` : l'écran la dessine sur ce bord.
                    'porte' => ['x' => $x, 'y' => $y, 'cote' => (string) $acces['cote']],
                ];
            }

            return [
                'type' => ValidationCampagne::ACCES_ESCALIER,
                'ancre' => ['x' => $x, 'y' => $y, 'l' => 2, 'h' => 2],
                'cases' => Grille::cellulesEmprise($x, $y, 2, 2),
                'porte' => null,
            ];
        };

        $entree = $un((array) $quete['depart']);

        return [
            'entree' => $entree,
            'sortie' => is_array($quete['sortie'] ?? null) ? $un($quete['sortie']) : $entree,
            // FAUSSES PORTES DE SORTIE : des portes d'accès comme les autres, qui ne s'ouvrent pas. `revelee` passe à
            // vrai quand un héros a essayé d'en sortir (`Quete::revelerFausseSortie()`) ; avant, rien ne la distingue.
            'fausses_sorties' => array_map(
                static fn (array $porte): array => $un(['type' => ValidationCampagne::ACCES_PORTE] + $porte),
                array_values((array) ($quete['fausses_sorties'] ?? [])),
            ),
            // QUÊTE DOUBLE (lot K5p) : le passage de la PRINCIPALE vers l'autre moitié ; la seconde passe par son départ.
            'passage' => is_array(data_get($quete, 'double.passage')) ? $un((array) $quete['double']['passage']) : null,
        ];
    }

    /**
     * La case sur laquelle DONNE une porte du format : la voisine de (x, y) du côté `cote`, à un pas. Un couloir, ou une
     * case de la salle voisine quand les deux salles se touchent.
     *
     * @param  array{x: int, y: int, cote: string}  $porte
     * @return array{x: int, y: int}
     */
    public static function caseDehors(array $porte): array
    {
        $x = (int) $porte['x'];
        $y = (int) $porte['y'];

        return match ($porte['cote']) {
            'n' => ['x' => $x, 'y' => $y - 1],
            's' => ['x' => $x, 'y' => $y + 1],
            'e' => ['x' => $x + 1, 'y' => $y],
            'o' => ['x' => $x - 1, 'y' => $y],
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
            $dehors = self::caseDehors($porte);
            $nature = $porte['type'] === 'secrete' ? '? porte secrète' : '+ porte normale';
            if (is_array($porte['verrou'] ?? null)) {
                $nature .= ' verrouillée ('.($porte['verrou']['type'] ?? '?')
                    .(isset($porte['verrou']['objet']) ? ' '.$porte['verrou']['objet'] : '')
                    .(isset($porte['verrou']['condition']) ? ' '.$porte['verrou']['condition'] : '').')';
            }
            $lignes[] = "  $nature : case ({$porte['x']}, {$porte['y']}), côté {$porte['cote']}, "
                ."donne sur ({$dehors['x']}, {$dehors['y']})";
        }
        foreach ($quete['blocs'] as $bloc) {
            $lignes[] = "  # bloc : case ({$bloc['x']}, {$bloc['y']})";
        }
        $acces = self::acces($quete);
        $dire = static fn (array $a): string => $a['type'] === ValidationCampagne::ACCES_PORTE
            ? "porte, intérieur ({$a['porte']['x']}, {$a['porte']['y']}), côté {$a['porte']['cote']}"
            : "escalier, coin ({$a['ancre']['x']}, {$a['ancre']['y']}), 2×2";
        if (is_array($quete['sortie'] ?? null)) {
            $lignes[] = '  < entrée : '.$dire($acces['entree']);
            $lignes[] = '  > sortie : '.$dire($acces['sortie']);
        } else {
            $lignes[] = '  < entrée et sortie : '.$dire($acces['entree']);
        }
        foreach ($acces['fausses_sorties'] as $fausse) {
            $lignes[] = '  x FAUSSE porte de sortie (ne s\'ouvre pas) : '.$dire($fausse);
        }
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

        // NOTES (lot K3) : hors carte elles aussi, mais c'est ce que l'auteur veut relire — quand, sur quoi, et quoi.
        foreach ((array) ($quete['notes'] ?? []) as $note) {
            $cible = (array) data_get($note, 'quand.cible', []);
            $sur = collect($cible)->map(fn ($valeur, $cle) => $cle.' '.(is_array($valeur)
                ? '('.implode(', ', $valeur).')'
                : $valeur))->implode(', ');
            $effets = collect((array) ($note['alors'] ?? []))->pluck('effet')->implode(', ');
            $condition = data_get($note, 'quand.si_drapeau') !== null ? ', si « '.data_get($note, 'quand.si_drapeau').' »' : '';
            $repete = ($note['une_fois'] ?? true) ? '' : ' (à chaque fois)';

            $lignes[] = '  note '.($note['repere'] ?? '?').' : '.data_get($note, 'quand.declencheur')." — {$sur}{$condition} → {$effets}{$repete}";
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

    /**
     * `spawn_heros` d'une ENTRÉE EN COULOIR (René, 2026-11-16 : « la porte d'entrée peut aussi être sur un mur d'un
     * corridor ») : la case devant la porte d'abord, puis les cases libres du MÊME couloir, de la plus proche à la plus
     * lointaine EN PAS (parcours en largeur, orthogonal : un héros ne s'installe pas de l'autre côté d'un mur). Le
     * parcours ne quitte jamais le couloir — ni salle, ni roche, ni bloc — et passe par-dessus une case prise sans s'y
     * installer. Plafonné à `AssembleurCarte::MAX_SPAWNS_HEROS`, comme le départ en salle.
     *
     * @param  array{x: int, y: int, l: int, h: int}  $ancre
     * @param  list<list<string>>  $cases  `s` sol, `m` roche — blocs déjà posés
     * @param  list<array<string, mixed>>  $salles  les salles du PLATEAU (ouvertes et fermées)
     * @param  array<string, true>  $occupees
     * @return list<array{x: int, y: int}>
     */
    private static function spawnsCouloir(array $ancre, array $cases, array $salles, array $occupees): array
    {
        $depart = ['x' => $ancre['x'], 'y' => $ancre['y']];
        $places = [$depart];
        $vues = ["{$depart['x']},{$depart['y']}" => true];
        $file = [$depart];

        while ($file !== [] && count($places) < AssembleurCarte::MAX_SPAWNS_HEROS) {
            $c = array_shift($file);
            foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
                $nx = $c['x'] + $dx;
                $ny = $c['y'] + $dy;
                if (isset($vues["$nx,$ny"]) || ($cases[$ny][$nx] ?? 'm') !== 's' || self::interieurEn($salles, $nx, $ny) !== null) {
                    continue;
                }
                $vues["$nx,$ny"] = true;
                $file[] = ['x' => $nx, 'y' => $ny];
                if (! isset($occupees["$nx,$ny"])) {
                    $places[] = ['x' => $nx, 'y' => $ny];
                }
            }
        }

        return array_slice($places, 0, AssembleurCarte::MAX_SPAWNS_HEROS);
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

}
