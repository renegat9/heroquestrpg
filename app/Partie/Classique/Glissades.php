<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\CaseLibre;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurDegats;
use App\Partie\TamponAnnonces;

/**
 * LA GLISSADE — lot E2, *The Crypt of Perpetual Darkness* q. 10 A (G1798 p. 25) : « This square is an undetectable trap. Any
 * hero who moves onto it slides all the way down onto the space marked "X" and their movement is done (but they may still
 * take an action). If a hero slides down and the path to the "X" space is not clear, stop at the nearest available space and
 * roll 2 combat dice to see if any damage is taken by the hero or monster who is landed on. »
 *
 * `regles.glissades: [{x, y, vers: {x, y}}]` : une case invisible (ni piège du catalogue, ni tuile), et sa case d'arrivée. Le
 * trajet du héros s'arrête sur la case (`ResolveurTour::resoudreDeplacement()`, avec la glace et le pont) ; s'il l'ATTEINT, il
 * glisse : sur `vers` si elle est libre, sinon sur la case libre la plus proche, et la figure qui occupe `vers` encaisse
 * 2 dés de combat — un crâne, un point de Corps, sans défense (décision écrite : le livret ne parle d'aucune défense). Son
 * déplacement est fini, son action reste. La salle d'arrivée se révèle.
 */
final class Glissades
{
    public const DES = 2;

    public function __construct(private readonly LanceurDes $des) {}

    /** La case d'arrivée de la glissade qui part de (x, y), ou `null`. @return array{x: int, y: int}|null */
    public static function depuis(Quete $quete, int $x, int $y): ?array
    {
        if (! $quete->estConstruite()) {
            return null;
        }
        foreach ((array) data_get($quete->documentConstruit(), 'regles.glissades', []) as $g) {
            if ((int) ($g['x'] ?? -1) === $x && (int) ($g['y'] ?? -1) === $y && isset($g['vers']['x'], $g['vers']['y'])) {
                return ['x' => (int) $g['vers']['x'], 'y' => (int) $g['vers']['y']];
            }
        }

        return null;
    }

    /** Le rang de la première case de glissade du chemin, ou `null`. @param list<array{x: int, y: int}> $chemin */
    public static function rangDansLeChemin(Quete $quete, array $chemin): ?int
    {
        foreach (array_values($chemin) as $i => $case) {
            if (self::depuis($quete, (int) $case['x'], (int) $case['y']) !== null) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Le héros glisse depuis `$case` : rend la case où il s'arrête et l'annonce `glissade`.
     *
     * @param  array{x: int, y: int}  $case
     * @return array{arrivee: array{x: int, y: int}, payload: array<string, mixed>}|null
     */
    public function glisser(Groupe $groupe, Quete $quete, Personnage $heros, array $case): ?array
    {
        $vers = self::depuis($quete, (int) $case['x'], (int) $case['y']);
        if ($vers === null) {
            return null;
        }
        $grille = FabriqueGrille::pour($quete->fresh(), exceptPersonnageId: (int) $heros->id);
        $payload = ['type' => 'glissade', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom,
            'de' => ['x' => (int) $case['x'], 'y' => (int) $case['y']], 'vers' => $vers];

        if ($grille->estTraversable($vers['x'], $vers['y'])) {
            return ['arrivee' => $vers, 'payload' => $this->annoncer($groupe, $heros, $payload)];
        }

        // « stop at the nearest available space and roll 2 combat dice to see if any damage is taken by the hero or monster
        // who is landed on »
        $arrivee = CaseLibre::proche($grille, $vers['x'], $vers['y']) ?? ['x' => (int) $case['x'], 'y' => (int) $case['y']];
        $faces = [];
        for ($i = 0; $i < self::DES; $i++) {
            $faces[] = $this->des->deCombat();
        }
        $cranes = count(array_filter($faces, fn (FaceDeCombat $f) => $f === FaceDeCombat::Crane));
        $percute = $this->percuter($quete, $vers, $cranes);
        $payload += ['arrivee' => $arrivee, 'bloque' => true, 'faces' => array_map(fn (FaceDeCombat $f) => $f->value, $faces),
            ...($percute !== null ? ['percute' => $percute] : [])];

        return ['arrivee' => $arrivee, 'payload' => $this->annoncer($groupe, $heros, $payload)];
    }

    /** La figure (héros ou monstre) qui occupe la case d'arrivée encaisse les crânes. @return array{nom: string, degats: int}|null */
    private function percuter(Quete $quete, array $case, int $cranes): ?array
    {
        $monstre = $quete->instancesMonstres()->where('etat', 'actif')->get()
            ->first(fn ($m) => collect($m->cases())->contains(fn ($c) => $c['x'] === $case['x'] && $c['y'] === $case['y']));
        if ($monstre !== null) {
            $subis = $cranes > 0 ? (int) app(MoteurDegats::class)->infligerAMonstre($monstre, $cranes, MoteurDegats::SOURCE_PIEGE, ['piege' => 'Glissade'])['degats'] : 0;

            return ['nom' => $monstre->nomAffiche(), 'degats' => $subis];
        }
        $etat = $quete->etatsPersonnages()->where('position_x', $case['x'])->where('position_y', $case['y'])->with('personnage')->first();
        if ($etat?->personnage !== null) {
            $subis = $cranes > 0 ? (int) app(MoteurDegats::class)->infligerAHeros($etat->personnage, $cranes, MoteurDegats::SOURCE_PIEGE, ['piege' => 'Glissade']) : 0;
            if ($subis > 0 && (int) $etat->personnage->fresh()->pv_body === 0) {
                $etat->update(['tombe' => true]);
            }

            return ['nom' => (string) $etat->personnage->nom, 'degats' => $subis];
        }

        return null;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function annoncer(Groupe $groupe, Personnage $heros, array $payload): array
    {
        app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return $payload;
    }
}
