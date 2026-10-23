<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Engine\MotsClesDanger;
use App\Models\Carte;
use App\Models\Danger;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;
use Illuminate\Validation\ValidationException;

/**
 * DANGER MOBILE AUTONOME (2026-10-23) — le rocher roulant de *Kellar's Keep* et la brume de la mort de *Return of
 * the Witch Lord*, UNE mécanique (décision de René, 2026-10-15 : « une seule implémentation »). Vocabulaire :
 * `App\Engine\MotsClesDanger` ; catalogue : `dangers` (`DangerSeeder`) ; état : `cartes.grille['dangers']`.
 *
 * Chaque entrée de la couche est DURABLE (jamais en cache) :
 *  - le **rocher** : `{danger_id, nom, etat, run: [[x,y]…], i, x, y, franchi: [personnage_id…]}`. `run[0]` est la case
 *    déclencheur, `run[last]` le coin où il s'arrête. `etat` ∈ attente (pas encore tombé, invisible) · roule · bloc ;
 *  - la **brume** : `{danger_id, nom, etat, x, y}`, `etat` ∈ actif · detruit.
 *
 * Trois points de passage, un par question :
 *  - `franchissementDuHeros()` : un rocher en attente tombe quand le DERNIER héros debout a franchi sa case ;
 *  - `avancer()` : chaque tour du MJ, en tête de `ResolveurTour::phaseMonstres()` — le rocher roule, la brume glisse ;
 *  - `frapperAvecArme()` / `detruireParSort()` : la seule façon de détruire la brume.
 *
 * ⚠ Ce qui n'est PAS une figure (`$occupees`) : ni le rocher roulant, ni la brume ne bloquent un héros en mouvement.
 * Seul le rocher ARRÊTÉ est un obstacle (bloc permanent, mouvement ET vue — précédent : la Chute de blocs,
 * `FabriqueGrille::pour()`). Une brume-figure aurait muré un couloir d'une case sans issue (aucune source ne dit
 * que les héros la contournent).
 *
 * Décisions écrites (le livret ne les tranche pas) :
 *  - le rocher PASSE les monstres sans les toucher ; la brume aussi (« It does not affect monsters ») ;
 *  - la brume se dirige vers le héros debout le plus proche (distance géométrique, `Grille::distance()`), parmi les
 *    cases de couloir qu'elle atteint en 6 pas au plus ; elle ne termine jamais sur un héros (la case juste avant) ;
 *  - aucun héros debout : elle reste où elle est ;
 *  - les dégâts sont SANS défense (le rocher le dit ; la brume ne lance aucun dé) et ne sont PAS réactifs
 *    (`SOURCE_DANGER_MOBILE` hors de `ReactionEffet::SOURCES_REACTIVES` : un danger de décor, pas un coup reçu) ;
 *  - le rocher roule de la SOMME de deux dés rouges (« roll 2 red dice to see how far »).
 */
final class MoteurDanger
{
    public const ETAT_ATTENTE = 'attente';

    public const ETAT_ROULE = 'roule';

    public const ETAT_BLOC = 'bloc';

    public const ETAT_ACTIF = 'actif';

    public const ETAT_DETRUIT = 'detruit';

    /** Source des dégâts d'un danger : hors `ReactionEffet::SOURCES_REACTIVES`, voir l'en-tête. */
    public const SOURCE = MoteurDegats::SOURCE_DANGER_MOBILE;

    /** Le nombre de cases que la brume peut parcourir en un tour du MJ au plus (`deplacement.cases`). */
    private const MAX_PAS_BRUME = 6;

    /**
     * Les entrées de la couche, dans leur ordre de pose (l'index est l'identité de l'occurrence pour le menu).
     *
     * @return list<array<string, mixed>>
     */
    public function entrees(?Carte $carte): array
    {
        return array_values((array) ($carte?->grille['dangers'] ?? []));
    }

    /**
     * Les cases BLOQUÉES par un rocher arrêté — le seul point de lecture de la couche pour `FabriqueGrille::pour()`.
     *
     * @return list<array{x: int, y: int}>
     */
    public function cellulesBloquees(?Carte $carte): array
    {
        $cases = [];

        foreach ($this->entrees($carte) as $entree) {
            if (($entree['etat'] ?? null) === self::ETAT_BLOC) {
                $cases[] = ['x' => (int) $entree['x'], 'y' => (int) $entree['y']];
            }
        }

        return $cases;
    }

    /**
     * Publication sous le brouillard (`EtatGroupe`) : un danger n'apparaît que sur une case connue. Le rocher en
     * attente n'est JAMAIS publié (c'est un piège qu'on ne voit pas tomber), le rocher détruit non plus.
     *
     * @param  list<list<string>>  $cases  grille DÉJÀ passée au brouillard
     * @return list<array{nom: string, x: int, y: int, etat: string}>
     */
    public function publier(?Carte $carte, array $cases): array
    {
        $publies = [];

        foreach ($this->entrees($carte) as $entree) {
            $etat = (string) ($entree['etat'] ?? '');

            if (! in_array($etat, [self::ETAT_ROULE, self::ETAT_BLOC, self::ETAT_ACTIF], true)) {
                continue;
            }

            $x = (int) $entree['x'];
            $y = (int) $entree['y'];

            if (($cases[$y][$x] ?? 'b') === 'b') {
                continue;
            }

            $publies[] = ['nom' => (string) ($entree['nom'] ?? ''), 'x' => $x, 'y' => $y, 'etat' => $etat];
        }

        return $publies;
    }

    /**
     * LE PASSAGE D'UN HÉROS. Un rocher en attente tombe quand le DERNIER héros debout a franchi sa case déclencheur :
     * « When the last hero passes the square marked A, a giant stone boulder falls from the ceiling » (F4543 p. 12).
     * « Franchi » = la case figure dans le trajet du mouvement sans en être l'arrivée : un héros qui S'ARRÊTE sur A
     * ne l'a pas franchie.
     *
     * Les héros qui ont déjà franchi sont mémorisés dans l'entrée (`franchi`), donc le compte survit aux tours.
     *
     * @param  list<array{x: int, y: int}>  $chemin  cases entrées par le héros dans CE mouvement, dans l'ordre
     * @return array<string, mixed>|null payload journalisé quand un rocher tombe, `null` sinon
     */
    public function franchissementDuHeros(Groupe $groupe, Quete $quete, Personnage $heros, array $chemin): ?array
    {
        $carte = $quete->carte;

        if ($carte === null || $chemin === []) {
            return null;
        }

        $data = (array) $carte->grille;
        $liste = array_values((array) ($data['dangers'] ?? []));
        $debout = $quete->etatsPersonnages()->where('tombe', false)->pluck('personnage_id')
            ->map(fn ($id) => (int) $id)->all();
        $changement = false;
        $chute = null;

        foreach ($liste as $i => $entree) {
            if (($entree['etat'] ?? null) !== self::ETAT_ATTENTE || ! isset($entree['run'][0])) {
                continue;
            }

            $declencheur = $entree['run'][0];
            $franchi = array_map('intval', (array) ($entree['franchi'] ?? []));

            if (! in_array((int) $heros->id, $franchi, true) && $this->franchitCase($chemin, (int) $declencheur[0], (int) $declencheur[1])) {
                $franchi[] = (int) $heros->id;
                $liste[$i]['franchi'] = $franchi;
                $changement = true;
            }

            if ($debout !== [] && array_diff($debout, $franchi) === []) {
                $liste[$i] = [...$liste[$i], 'etat' => self::ETAT_ROULE, 'i' => 0,
                    'x' => (int) $declencheur[0], 'y' => (int) $declencheur[1]];
                $changement = true;
                $chute = ['type' => 'danger_chute', 'danger' => (string) ($entree['nom'] ?? ''),
                    'x' => (int) $declencheur[0], 'y' => (int) $declencheur[1], 'heros' => $heros->nom];
            }
        }

        if (! $changement) {
            return null;
        }

        $data['dangers'] = $liste;
        $carte->update(['grille' => $data]);

        if ($chute === null) {
            return null;
        }

        Journal::ajouter($groupe, 'action', $chute, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return $chute;
    }

    /**
     * LE TOUR DU MJ — chaque danger en mouvement joue, dans l'ordre de sa pose. Appelée en tête de
     * `ResolveurTour::phaseMonstres()`, avant le moindre monstre, comme l'explosion du Fireburst.
     *
     * @return list<array<string, mixed>> payloads journalisés
     */
    public function avancer(Groupe $groupe, Quete $quete): array
    {
        $carte = $quete->carte;

        if ($carte === null) {
            return [];
        }

        $data = (array) $carte->grille;
        $liste = array_values((array) ($data['dangers'] ?? []));
        $payloads = [];
        $changement = false;

        foreach ($liste as $i => $entree) {
            $etat = (string) ($entree['etat'] ?? '');

            if ($etat === self::ETAT_ROULE) {
                [$payload, $nouvelle] = $this->rouler($groupe, $quete, $entree);
            } elseif ($etat === self::ETAT_ACTIF) {
                [$payload, $nouvelle] = $this->glisserBrume($groupe, $quete, $entree);
            } else {
                continue;
            }

            if ($payload !== null) {
                $payloads[] = $payload;
            }

            if ($nouvelle !== $entree) {
                $liste[$i] = $nouvelle;
                $changement = true;
            }
        }

        if ($changement) {
            $data['dangers'] = $liste;
            $carte->update(['grille' => $data]);
        }

        return $payloads;
    }

    /**
     * DÉPLACEMENT — « moves up to N » : le rocher roule de la somme de deux dés rouges ; la brume, au plus de
     * `deplacement.cases` (le reader de la vitesse est ici, et seulement ici).
     *
     * @param  array<string, mixed>  $effet
     */
    public function distance(array $effet, ?LanceurDes $des = null): int
    {
        $deplacement = (array) ($effet['deplacement'] ?? []);

        if (isset($deplacement['des_rouges'])) {
            $des ??= app(LanceurDes::class);
            $total = 0;

            for ($k = 0, $n = (int) $deplacement['des_rouges']; $k < $n; $k++) {
                $total += $des->d6();
            }

            return $total;
        }

        return (int) ($deplacement['cases'] ?? 0);
    }

    /**
     * DÉGÂTS — ce qu'un passage fait à UN héros. `des_combat` : N dés de combat SANS défense, un crâne = 1 PV ;
     * `pv` : un coup sec, sans dé. Passe par `MoteurDegats::infligerAHeros()` (point de passage unique des PV de
     * héros) et rend les PV réellement retirés.
     *
     * @param  array<string, mixed>  $degats  la clé `degats` de `dangers.effet`
     * @return array{degats: int, faces: list<string>}
     */
    public function toucher(Personnage $heros, array $degats): array
    {
        if (isset($degats['des_combat'])) {
            $faces = app(LanceurDes::class)->desCombat((int) $degats['des_combat']);
            $cranes = count(array_filter($faces, fn (FaceDeCombat $f) => $f === FaceDeCombat::Crane));
            $perdus = app(MoteurDegats::class)->infligerAHeros($heros, $cranes, self::SOURCE);

            return ['degats' => $perdus, 'faces' => array_map(fn (FaceDeCombat $f) => $f->value, $faces)];
        }

        $perdus = app(MoteurDegats::class)->infligerAHeros($heros, (int) ($degats['pv'] ?? 0), self::SOURCE);

        return ['degats' => $perdus, 'faces' => []];
    }

    /**
     * DESTRUCTION PAR ARME — la Lame des Esprits détruit la brume ; une arme ordinaire ne lui fait RIEN, et le dit
     * (« Don't tell this to the heroes! » reste une consigne au MJ : le coup se joue, il ne fait pas de dégât).
     * Le coup coûte l'action du héros ; le résolveur en décide, le menu ne l'offre qu'à un héros au contact.
     *
     * @return array<string, mixed> payload journalisé
     */
    public function frapperAvecArme(Groupe $groupe, Quete $quete, Personnage $heros, int $index, ?Objet $arme): array
    {
        $entree = $this->entreeBrume($quete, $index);
        $nom = $arme?->nom ?? 'les poings';
        $destructrices = $this->destructrices($entree);

        if ($arme !== null && in_array($arme->nom, $destructrices, true)) {
            $payload = $this->detruire($quete, $index, $entree, $arme->nom, $heros);
        } else {
            $payload = ['type' => 'danger_sans_effet', 'danger' => (string) $entree['nom'],
                'heros' => $heros->nom, 'arme' => $nom, 'x' => (int) $entree['x'], 'y' => (int) $entree['y']];
        }

        Journal::ajouter($groupe, 'action', $payload, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return $payload;
    }

    /**
     * DESTRUCTION PAR SORT — la Tempête détruit la brume (« can only be destroyed by a tempest spell »). Un autre
     * sort n'a aucun effet sur elle : `null`, et le résolveur le refuse.
     *
     * @return array<string, mixed>|null payload (NON journalisé ici : `ResolveurTour::resoudreSort()` journalise le sort), `null` si le sort ne détruit pas ce danger
     */
    public function detruireParSort(Quete $quete, Personnage $heros, int $index, string $sort): ?array
    {
        $entree = $this->entreeBrume($quete, $index);

        if (! in_array($sort, $this->destructrices($entree), true)) {
            return null;
        }

        // Pas de journal ici : le sort est journalisé par `ResolveurTour::resoudreSort()`, qui journalise son payload.
        return $this->detruire($quete, $index, $entree, $sort, $heros);
    }

    /**
     * Les noms (sorts ET armes) qui détruisent cette brume, lus dans le catalogue — jamais recopiés ici.
     *
     * @return list<string>
     */
    public function destructrices(array $entree): array
    {
        $fiche = Danger::find((int) ($entree['danger_id'] ?? 0));

        return array_values(array_map('strval', (array) ($fiche?->effet['destruction'] ?? [])));
    }

    /**
     * Les cases de COULOIR où la brume peut se trouver : ni mur, ni salle, ni rocher arrêté. Un seul point de lecture
     * de ce « couloir » pour la brume (la salle se lit par `Salles::indexDe()`, comme partout).
     */
    public function brumePeutOccuper(array $grille, int $x, int $y): bool
    {
        $cases = (array) ($grille['cases'] ?? []);

        return ($cases[$y][$x] ?? 'm') !== 'm' && Salles::indexDe((array) ($grille['salles'] ?? []), $x, $y) === null;
    }

    // ------------------------------------------------------------------
    // Mouvements
    // ------------------------------------------------------------------

    /**
     * Le rocher roule de `distance()` cases le long de sa ligne. Chaque héros sur une case qu'il traverse (la case de
     * départ exclue) lance 5 dés de combat, sans défense ; le rocher qui atteint le coin (fin de couloir) s'y ARRÊTE
     * et devient un bloc permanent.
     *
     * @param  array<string, mixed>  $entree
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>}
     */
    private function rouler(Groupe $groupe, Quete $quete, array $entree): array
    {
        $fiche = Danger::find((int) $entree['danger_id']);

        if ($fiche === null) {
            return [null, $entree];
        }

        $effet = (array) $fiche->effet;

        if (($effet['chemin'] ?? null) !== 'ligne') {
            return [null, $entree];
        }

        $run = array_values((array) $entree['run']);
        $i = (int) $entree['i'];
        $dernier = count($run) - 1;
        $distance = $this->distance($effet);
        $fin = min($i + $distance, $dernier);
        // ARRET (`mur`) : le rocher ne s'immobilise en bloc que s'il s'écrase au mur ; `jamais` le laisse en fin de ligne.
        $bloc = ($effet['arret'] ?? null) === 'mur' && $i + $distance >= $dernier;
        $traversees = [];
        $touches = [];

        for ($k = $i + 1; $k <= $fin; $k++) {
            [$x, $y] = [(int) $run[$k][0], (int) $run[$k][1]];
            $traversees[] = ['x' => $x, 'y' => $y];

            foreach ($this->herosSur($quete, $x, $y) as $heros) {
                $touches[] = ['heros' => $heros->nom, ...$this->toucher($heros, (array) ($effet['degats'] ?? []))];
            }
        }

        $payload = ['type' => 'danger_roule', 'danger' => (string) $entree['nom'], 'distance' => $distance,
            'x' => (int) $run[$fin][0], 'y' => (int) $run[$fin][1], 'bloc' => $bloc, 'touches' => $touches];
        Journal::ajouter($groupe, 'action', $payload, null);

        return [$payload, [...$entree, 'i' => $fin, 'x' => (int) $run[$fin][0], 'y' => (int) $run[$fin][1],
            'etat' => $bloc ? self::ETAT_BLOC : self::ETAT_ROULE]];
    }

    /**
     * La brume glisse d'au plus 6 cases de COULOIR, vers le héros debout le plus proche. Elle passe sur les héros
     * qu'elle traverse (-1 PV chacun, sans dé) ; elle ne termine jamais sur un héros (`Grille::distance()` pour le
     * choix, la case juste avant si elle tomberait sur lui). Elle ne revient jamais sur une case dans le même tour.
     *
     * @param  array<string, mixed>  $entree
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>}
     */
    private function glisserBrume(Groupe $groupe, Quete $quete, array $entree): array
    {
        $fiche = Danger::find((int) $entree['danger_id']);
        $carte = $quete->carte;
        $debout = $quete->etatsPersonnages()->where('tombe', false)->with('personnage')->get();

        if ($fiche === null || $carte === null || $debout->isEmpty()) {
            return [null, $entree];
        }

        if (($fiche->effet['chemin'] ?? null) !== 'reseau_de_couloirs') {
            return [null, $entree];
        }

        $grille = (array) $carte->grille;
        $bloquees = [];

        foreach ($this->cellulesBloquees($carte) as $c) {
            $bloquees[$c['x'].','.$c['y']] = true;
        }

        $depart = [(int) $entree['x'], (int) $entree['y']];
        $portee = min($this->distance((array) $fiche->effet), self::MAX_PAS_BRUME);
        $parent = [$depart[0].','.$depart[1] => null];
        $pas = [$depart[0].','.$depart[1] => 0];
        $file = [$depart];

        for ($t = 0; $t < count($file); $t++) {
            [$x, $y] = $file[$t];

            if ($pas[$x.','.$y] >= $portee) {
                continue;
            }

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                $cle = $nx.','.$ny;

                if (isset($pas[$cle]) || isset($bloquees[$cle]) || ! $this->brumePeutOccuper($grille, $nx, $ny)) {
                    continue;
                }

                $pas[$cle] = $pas[$x.','.$y] + 1;
                $parent[$cle] = $x.','.$y;
                $file[] = [$nx, $ny];
            }
        }

        $occupees = [];

        foreach ($debout as $etat) {
            $occupees[(int) $etat->position_x.','.(int) $etat->position_y] = true;
        }

        $geometrie = FabriqueGrille::pour($quete);
        $meilleure = null;

        foreach (array_keys($pas) as $cle) {
            if ($cle === $depart[0].','.$depart[1] || isset($occupees[$cle])) {
                continue;
            }

            [$x, $y] = array_map('intval', explode(',', $cle));
            $proche = null;

            foreach ($debout as $etat) {
                $d = (int) $geometrie->distance($x, $y, (int) $etat->position_x, (int) $etat->position_y);
                $proche = $proche === null ? $d : min($proche, $d);
            }

            $score = [$proche, $pas[$cle], $y, $x];

            if ($meilleure === null || $score < $meilleure['score']) {
                $meilleure = ['score' => $score, 'cle' => $cle];
            }
        }

        if ($meilleure === null) {
            return [null, $entree]; // rien d'atteignable : elle reste (décision écrite)
        }

        // Le trajet, de la case de départ à la case d'arrivée.
        $trajet = [];

        for ($cle = $meilleure['cle']; $cle !== null; $cle = $parent[$cle]) {
            array_unshift($trajet, array_map('intval', explode(',', $cle)));
        }

        $traversees = array_slice($trajet, 1);
        $touches = [];

        foreach ($traversees as [$x, $y]) {
            foreach ($this->herosSur($quete, $x, $y) as $heros) {
                $touches[] = ['heros' => $heros->nom, ...$this->toucher($heros, (array) ($fiche->effet['degats'] ?? []))];
            }
        }

        [$x, $y] = end($trajet);
        $payload = ['type' => 'danger_avance', 'danger' => (string) $entree['nom'], 'x' => $x, 'y' => $y,
            'pas' => count($traversees), 'touches' => $touches];
        Journal::ajouter($groupe, 'action', $payload, null);

        return [$payload, [...$entree, 'x' => $x, 'y' => $y]];
    }

    /**
     * La destruction d'une brume : l'entrée passe `detruit` (elle ne se publie plus et ne touche plus personne).
     *
     * @param  array<string, mixed>  $entree
     * @return array<string, mixed>
     */
    private function detruire(Quete $quete, int $index, array $entree, string $par, Personnage $heros): array
    {
        $carte = $quete->carte;
        $data = (array) $carte->grille;
        $liste = array_values((array) ($data['dangers'] ?? []));
        $liste[$index] = [...$liste[$index], 'etat' => self::ETAT_DETRUIT];
        $data['dangers'] = $liste;
        $carte->update(['grille' => $data]);

        return ['type' => 'danger_detruit', 'danger' => (string) $entree['nom'], 'par' => $par,
            'heros' => $heros->nom, 'x' => (int) $entree['x'], 'y' => (int) $entree['y']];
    }

    /**
     * La brume visée par un index de couche, en ACTIVE. Un index sans brume active est une cible périmée : refus.
     *
     * @return array<string, mixed>
     */
    public function entreeBrume(Quete $quete, int $index): array
    {
        $entree = $this->entrees($quete->carte)[$index] ?? null;

        if ($entree === null || ($entree['etat'] ?? null) !== self::ETAT_ACTIF) {
            throw ValidationException::withMessages(['parametres' => 'Cible invalide : ce danger n\'est plus une cible active.']);
        }

        return $entree;
    }

    /**
     * Les brumes ACTIVES au contact orthogonal du héros en (x, y) : le menu n'en offre que celles-là. L'index est celui
     * de `entrees()`, donc celui que `entreeBrume()` et le résolveur relisent.
     *
     * @return list<array{index: int, nom: string}>
     */
    public function brumesAuContact(?Carte $carte, int $x, int $y): array
    {
        $brumes = [];

        foreach ($this->entrees($carte) as $index => $entree) {
            if (($entree['etat'] ?? null) === self::ETAT_ACTIF && Grille::sontAdjacentes($x, $y, (int) $entree['x'], (int) $entree['y'])) {
                $brumes[] = ['index' => $index, 'nom' => (string) ($entree['nom'] ?? '')];
            }
        }

        return $brumes;
    }

    /** Les héros DEBOUT sur la case (x, y)    /** Les héros DEBOUT sur la case (x, y) — la seule question de passage de la couche. @return list<Personnage> */
    private function herosSur(Quete $quete, int $x, int $y): array
    {
        return $quete->etatsPersonnages()
            ->where('tombe', false)
            ->where('position_x', $x)
            ->where('position_y', $y)
            ->with('personnage')
            ->get()
            ->map(fn (EtatPersonnageQuete $etat) => $etat->personnage)
            ->filter()
            ->values()
            ->all();
    }

    /** Le trajet passe-t-il sur (x, y) sans y finir ? Le déclencheur d'un rocher se franchit, il ne se rejoint pas. */
    private function franchitCase(array $chemin, int $x, int $y): bool
    {
        $derniere = count($chemin) - 1;

        foreach ($chemin as $k => $case) {
            if ((int) $case['x'] === $x && (int) $case['y'] === $y) {
                return $k < $derniere;
            }
        }

        return false;
    }
}
