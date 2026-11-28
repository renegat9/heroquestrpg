<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\CaseLibre;
use App\Partie\FabriqueGrille;
use App\Partie\TamponAnnonces;
use Illuminate\Validation\ValidationException;

/**
 * LE LABYRINTHE — lot E2, *Wizards of Morcar* q. 7 (G1504 p. 33) : « The tunnels in this quest are the entrances into and out
 * of the labyrinth. A hero that moves into a tunnel on their turn rolls two red dice and chooses one, then places their figure
 * on or adjacent to a tunnel with the matching number. If they roll a number that matches the entrance they are on, they move
 * to room E. Monsters who travel the Labyrinth may emerge into any discovered area without rolling. » ; p. 13 : « If a hero or
 * monster exits a tunnel and no unoccupied landing spaces are available, place them in the closest unoccupied space. »
 *
 * `regles.labyrinthe: {tunnels: [{numero 1-6, x, y}], sortie: {x, y}}` — `sortie` : une case de la salle E. Le trajet du héros
 * s'arrête sur un tunnel (`ResolveurTour::resoudreDeplacement()`) ; il y lance deux dés rouges (`entrer()`), et son
 * déplacement est fini. Le jet attend en base (`quetes.drapeaux._labyrinthe`) ; le menu lui offre un choix par dé
 * (`sortir_labyrinthe_{de}`, gratuit), que le résolveur revalide (`sortir()`) : il ressort au plus près du tunnel de ce
 * numéro — ou de la sortie, s'il a tiré le numéro de son entrée. Un numéro sans tunnel le ramène à son entrée.
 *
 * LES MONSTRES (« Monsters who travel the Labyrinth may emerge into any discovered area without rolling ») : au tour du MJ,
 * un monstre qui n'atteindrait aucun héros par le chemin normal ce tour-ci mais peut gagner un tunnel s'y engouffre et
 * ressort, sans dé, au contact du héros le plus proche qui se tient dans une zone découverte (salle découverte, case de
 * couloir vue) — Zargon jouant pour gagner ({@see voyageDuMonstre()}). Il attaque ensuite comme d'ordinaire.
 */
final class Labyrinthe
{
    private const ETAT = '_labyrinthe';

    public function __construct(private readonly LanceurDes $des) {}

    /** @return list<array{numero: int, x: int, y: int}> */
    public static function tunnels(Quete $quete): array
    {
        if (! $quete->estConstruite()) {
            return [];
        }

        return array_values(array_map(fn ($t) => ['numero' => (int) $t['numero'], 'x' => (int) $t['x'], 'y' => (int) $t['y']],
            (array) data_get($quete->documentConstruit(), 'regles.labyrinthe.tunnels', [])));
    }

    /** Le tunnel de la case (x, y), ou `null`. @return array{numero: int, x: int, y: int}|null */
    public static function tunnelEn(Quete $quete, int $x, int $y): ?array
    {
        foreach (self::tunnels($quete) as $t) {
            if ($t['x'] === $x && $t['y'] === $y) {
                return $t;
            }
        }

        return null;
    }

    /** Le rang du premier tunnel du chemin, ou `null`. @param list<array{x: int, y: int}> $chemin */
    public static function rangDansLeChemin(Quete $quete, array $chemin): ?int
    {
        foreach (array_values($chemin) as $i => $case) {
            if (self::tunnelEn($quete, (int) $case['x'], (int) $case['y']) !== null) {
                return $i;
            }
        }

        return null;
    }

    /** Le jet en attente de ce héros, ou `null`. @return array{depuis: int, des: list<int>}|null */
    public static function enAttente(Quete $quete, Personnage $heros): ?array
    {
        $jet = data_get($quete->relire('drapeaux')->drapeaux, self::ETAT.'.'.$heros->id);

        return is_array($jet) ? $jet : null;
    }

    /**
     * Le héros entre dans le tunnel de `$case` : deux dés rouges, gardés en base pour son choix.
     *
     * @param  array{x: int, y: int}  $case
     * @return array<string, mixed>|null l'annonce `labyrinthe_jet`
     */
    public function entrer(Groupe $groupe, Quete $quete, Personnage $heros, array $case): ?array
    {
        $tunnel = self::tunnelEn($quete, (int) $case['x'], (int) $case['y']);
        if ($tunnel === null || self::enAttente($quete, $heros) !== null) {
            return null;
        }
        $des = [$this->des->d6(), $this->des->d6()];
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::ETAT][$heros->id] = ['depuis' => $tunnel['numero'], 'des' => $des];
        $quete->update(['drapeaux' => $drapeaux]);

        $payload = ['type' => 'labyrinthe_jet', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom,
            'depuis' => $tunnel['numero'], 'des' => $des];
        app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return $payload;
    }

    /**
     * Les choix du héros en attente : un par valeur de dé, avec où elle mène.
     *
     * @return list<array{de: int, vers: string}>
     */
    public function choix(Quete $quete, Personnage $heros): array
    {
        $jet = self::enAttente($quete, $heros);
        if ($jet === null) {
            return [];
        }
        $choix = [];
        foreach (array_values(array_unique(array_map('intval', $jet['des']))) as $de) {
            $choix[] = ['de' => $de, 'vers' => $de === (int) $jet['depuis'] ? 'la sortie du labyrinthe'
                : (collect(self::tunnels($quete))->contains('numero', $de) ? "le tunnel {$de}" : 'son entrée (aucun tunnel '.$de.')')];
        }

        return $choix;
    }

    /**
     * Le monstre voyage-t-il par le labyrinthe ce tour-ci ? S'il le fait, il est posé à sa sortie et l'annonce est rendue.
     *
     * @param  array{0: mixed, 1: list<array{x: int, y: int}>}|null  $meilleure  la cible et le chemin normaux (`cibleLaPlusProche()`)
     * @return array<string, mixed>|null l'annonce `labyrinthe_monstre`
     */
    public function voyageDuMonstre(Groupe $groupe, Quete $quete, \App\Models\InstanceMonstre $instance, \App\Partie\Grille $grille, ?array $meilleure): ?array
    {
        $tunnels = self::tunnels($quete);
        $portee = $instance->deplacementEffectif();
        if ($tunnels === [] || $portee <= 0 || $instance->position_x === null
            || ($meilleure !== null && count($meilleure[1]) <= $portee)) {
            return null;
        }
        $mx = (int) $instance->position_x;
        $my = (int) $instance->position_y;
        $entree = null;
        foreach ($tunnels as $t) {
            $chemin = ($t['x'] === $mx && $t['y'] === $my) ? [] : $grille->chemin($mx, $my, $t['x'], $t['y']);
            if ($chemin !== null && count($chemin) <= $portee && ($entree === null || count($chemin) < $entree[1])) {
                $entree = [$t, count($chemin)];
            }
        }
        if ($entree === null) {
            return null;
        }

        // « any discovered area » : au contact du héros le plus proche qui se tient dans une zone découverte.
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $decouvertes = $quete->sallesDecouvertes();
        $vus = app(CouloirsVus::class)->vus($quete) ?? [];
        $sortie = null;
        foreach ($quete->etatsPersonnages()->where('tombe', false)->whereNotNull('position_x')->get() as $heros) {
            $hx = (int) $heros->position_x;
            $hy = (int) $heros->position_y;
            $salle = \App\Partie\Salles::indexDe($salles, $hx, $hy);
            if (! ($salle !== null ? in_array($salle, $decouvertes, true) : isset($vus["{$hx},{$hy}"]))) {
                continue;
            }
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $cx = $hx + $dx;
                $cy = $hy + $dy;
                $distance = abs($cx - (int) $entree[0]['x']) + abs($cy - (int) $entree[0]['y']);
                if ($grille->estTraversable($cx, $cy) && \App\Partie\Grille::sontAdjacentes($hx, $hy, $cx, $cy, carte: $quete->carte)
                    && ($sortie === null || $distance < $sortie[2])) {
                    $sortie = [$cx, $cy, $distance];
                }
            }
        }
        if ($sortie === null) {
            return null;
        }
        $instance->update(['position_x' => $sortie[0], 'position_y' => $sortie[1]]);

        $payload = ['type' => 'labyrinthe_monstre', 'monstre' => $instance->nomAffiche(), 'instance_id' => (int) $instance->id,
            'depuis' => (int) $entree[0]['numero'], 'x' => $sortie[0], 'y' => $sortie[1]];
        app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'monstre', 'id' => $instance->id, 'nom' => $instance->nomAffiche()]);

        return $payload;
    }

    /** @return array<string, mixed> le résultat moteur `labyrinthe_sortie` */
    public function sortir(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $de): array
    {
        $jet = self::enAttente($quete, $heros);
        if ($jet === null || ! in_array($de, array_map('intval', $jet['des']), true)) {
            throw ValidationException::withMessages(['option_id' => 'Aucun jet du labyrinthe à jouer ainsi.']);
        }
        $sortie = data_get($quete->documentConstruit(), 'regles.labyrinthe.sortie');
        $tunnels = collect(self::tunnels($quete));
        $vise = $de === (int) $jet['depuis']
            ? ['x' => (int) $sortie['x'], 'y' => (int) $sortie['y']]
            : ($tunnels->firstWhere('numero', $de) ?? $tunnels->firstWhere('numero', (int) $jet['depuis']));
        $grille = FabriqueGrille::pour($quete->fresh(), exceptPersonnageId: (int) $heros->id);
        // « on or adjacent to a tunnel with the matching number […] the closest unoccupied space »
        $place = CaseLibre::iciOuProche($grille, (int) $vise['x'], (int) $vise['y']) ?? ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
        $etat->update(['position_x' => $place['x'], 'position_y' => $place['y']]);

        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        unset($drapeaux[self::ETAT][$heros->id]);
        $quete->update(['drapeaux' => $drapeaux]);

        return ['type' => 'labyrinthe_sortie', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom, 'de' => $de,
            'vers' => $de === (int) $jet['depuis'] ? 'sortie' : 'tunnel', 'x' => $place['x'], 'y' => $place['y']];
    }
}
