<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\CaseLibre;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use App\Partie\MoteurDegats;
use App\Partie\TamponAnnonces;
use Illuminate\Validation\ValidationException;

/**
 * LES PORTAILS — lot E2, *First Light* q. 2 (FL-Q p. 13) : « These are open doorways. Each portal has a Dread Glyph number
 * carved on the capstone. […] As a hero passes through a portal, they must declare a number between 1-7. Place that hero's
 * miniature on one of the two squares on either side of the corresponding portal. Using a portal counts as one square of
 * movement. If both squares are occupied, the hero loses 1 Body Point and is placed on the nearest square of their choice. If a
 * hero passes through a portal two times on the same turn, they lose 1 Body Point and their turn immediately ends. The first
 * time a portal is used, set up the room as depicted. Monsters may move through portals to any other portal already used by
 * heroes. »
 *
 * `regles.portails: [{porte: index, numero 1-7}]` — des portes (ouvertes) de la quête. Franchir l'arête d'un portail arrête la
 * marche sur la case d'avant (`ResolveurTour::signalerEntrees()`), les cases au-delà rendues au déplacement ; le choix attend
 * en base (`quetes.drapeaux._portail`) et le menu est FORCÉ : `traverser_portail_{n}` (gratuit). Le héros paraît sur la
 * première case libre des deux du portail choisi (sinon : 1 PV, la case libre la plus proche — décision écrite : le moteur
 * choisit la plus proche), un pas de son déplacement est compté ; deux passages le même tour : 1 PV et fin du tour
 * (`FinDeTourForcee`). Les portails utilisés sont retenus (`quetes.drapeaux._portails_utilises`) : les monstres hors de
 * portée passent par eux ({@see voyageDuMonstre()}).
 */
final class Portails
{
    private const ATTENTE = '_portail';

    private const UTILISES = '_portails_utilises';

    private const MARQUEUR_TOUR = 'portail_passe';

    /** @return list<array{numero: int, porte: int, cases: array{0: array{x: int, y: int}, 1: array{x: int, y: int}}}> */
    public static function portails(Quete $quete): array
    {
        if (! $quete->estConstruite()) {
            return [];
        }
        $portes = (array) ($quete->carte?->grille['portes'] ?? []);
        $liste = [];
        foreach ((array) data_get($quete->documentConstruit(), 'regles.portails', []) as $p) {
            $porte = $portes[(int) ($p['porte'] ?? -1)] ?? null;
            if ($porte === null) {
                continue;
            }
            [$a, $b] = Grille::casesPorte($porte);
            $liste[] = ['numero' => (int) $p['numero'], 'porte' => (int) $p['porte'],
                'cases' => [['x' => (int) $a['x'], 'y' => (int) $a['y']], ['x' => (int) $b['x'], 'y' => (int) $b['y']]]];
        }

        return $liste;
    }

    /** Le portail dont l'arête sépare `$a` et `$b`, ou `null`. @return array<string, mixed>|null */
    public static function entre(Quete $quete, array $a, array $b): ?array
    {
        foreach (self::portails($quete) as $p) {
            [$c1, $c2] = $p['cases'];
            if (($c1 == $a && $c2 == $b) || ($c1 == $b && $c2 == $a)) {
                return $p;
            }
        }

        return null;
    }

    /** Le numéro du portail franchi par ce héros, en attente de son choix, ou `null`. */
    public static function enAttente(Quete $quete, Personnage $heros): ?int
    {
        $n = data_get($quete->relire('drapeaux')->drapeaux, self::ATTENTE.'.'.$heros->id);

        return $n === null ? null : (int) $n;
    }

    /** @return list<int> les numéros offerts au héros qui franchit un portail */
    public function choix(Quete $quete, Personnage $heros): array
    {
        if (self::enAttente($quete, $heros) === null) {
            return [];
        }

        return collect(self::portails($quete))->pluck('numero')->unique()->sort()->values()->all();
    }

    /**
     * Le héros vient de franchir l'arête du portail `$portail` : il s'arrête sur `$avant` (la case d'avant), `$rendu` cases
     * rendues à son déplacement, et le choix attend.
     *
     * @param  array{x: int, y: int}  $avant
     */
    public function interrompre(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, array $portail, array $avant, int $rendu): void
    {
        $restant = max(0, (int) $etat->fresh()->deplacement_restant + $rendu);
        $etat->update(['position_x' => (int) $avant['x'], 'position_y' => (int) $avant['y'], 'deplacement_restant' => $restant,
            'a_deplace' => $restant <= 0]);
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::ATTENTE][$heros->id] = (int) $portail['numero'];
        $quete->update(['drapeaux' => $drapeaux]);
    }

    /** @return array<string, mixed> le résultat moteur `portail` */
    public function traverser(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $numero): array
    {
        $depuis = self::enAttente($quete, $heros);
        $vise = collect(self::portails($quete))->firstWhere('numero', $numero);
        if ($depuis === null || $vise === null) {
            throw ValidationException::withMessages(['option_id' => 'Aucun portail à traverser ainsi.']);
        }
        $grille = FabriqueGrille::pour($quete->fresh(), exceptPersonnageId: (int) $heros->id);
        $place = collect($vise['cases'])->first(fn (array $c) => $grille->estTraversable($c['x'], $c['y']));
        $blesse = 0;
        // « If both squares are occupied, the hero loses 1 Body Point and is placed on the nearest square »
        if ($place === null) {
            $place = CaseLibre::proche($grille, $vise['cases'][0]['x'], $vise['cases'][0]['y']) ?? ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
            $blesse += (int) app(MoteurDegats::class)->infligerAHeros($heros, 1, MoteurDegats::SOURCE_PIEGE, ['piege' => 'Portail']);
        }
        // « If a hero passes through a portal two times on the same turn, they lose 1 Body Point and their turn immediately ends »
        $marques = (array) ($etat->fresh()->capacites_tour ?? []);
        $deuxieme = in_array(self::MARQUEUR_TOUR, $marques, true);
        if ($deuxieme) {
            $blesse += (int) app(MoteurDegats::class)->infligerAHeros($heros, 1, MoteurDegats::SOURCE_PIEGE, ['piege' => 'Portail']);
        }
        $restant = max(0, (int) $etat->fresh()->deplacement_restant - 1); // « Using a portal counts as one square of movement »
        $etat->update(['position_x' => $place['x'], 'position_y' => $place['y'], 'deplacement_restant' => $restant, 'a_deplace' => $restant <= 0,
            'capacites_tour' => array_values(array_unique([...$marques, self::MARQUEUR_TOUR]))]);
        if ($deuxieme) {
            FinDeTourForcee::demander($etat);
        }
        if ((int) $heros->fresh()->pv_body === 0) {
            $etat->update(['tombe' => true]);
        }
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        unset($drapeaux[self::ATTENTE][$heros->id]);
        $drapeaux[self::UTILISES] = array_values(array_unique([...(array) ($drapeaux[self::UTILISES] ?? []), $depuis, $numero]));
        $quete->update(['drapeaux' => $drapeaux]);

        return ['type' => 'portail', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom, 'depuis' => $depuis,
            'numero' => $numero, 'x' => $place['x'], 'y' => $place['y'], 'degats' => $blesse, 'fin_de_tour' => $deuxieme];
    }

    /**
     * « Monsters may move through portals to any other portal already used by heroes » : un monstre hors de portée par le
     * chemin normal qui peut gagner l'une des cases d'un portail déjà utilisé ressort sur une case d'un AUTRE portail utilisé,
     * le plus près du héros le plus proche. Rend l'annonce `portail_monstre`, ou `null`.
     *
     * @param  array{0: mixed, 1: list<array{x: int, y: int}>}|null  $meilleure
     * @return array<string, mixed>|null
     */
    public function voyageDuMonstre(Groupe $groupe, Quete $quete, InstanceMonstre $instance, Grille $grille, ?array $meilleure): ?array
    {
        $utilises = (array) data_get($quete->relire('drapeaux')->drapeaux, self::UTILISES, []);
        $portails = collect(self::portails($quete))->filter(fn (array $p) => in_array($p['numero'], $utilises, true))->values();
        $portee = $instance->deplacementEffectif();
        if ($portails->count() < 2 || $portee <= 0 || $instance->position_x === null
            || ($meilleure !== null && count($meilleure[1]) <= $portee)) {
            return null;
        }
        $mx = (int) $instance->position_x;
        $my = (int) $instance->position_y;
        $entree = null;
        foreach ($portails as $p) {
            foreach ($p['cases'] as $c) {
                $chemin = ($c['x'] === $mx && $c['y'] === $my) ? [] : $grille->chemin($mx, $my, $c['x'], $c['y']);
                if ($chemin !== null && count($chemin) < $portee && ($entree === null || count($chemin) < $entree[1])) {
                    $entree = [$p, count($chemin)];
                }
            }
        }
        if ($entree === null) {
            return null;
        }
        $heros = $quete->etatsPersonnages()->where('tombe', false)->whereNotNull('position_x')->get();
        $sortie = null;
        foreach ($portails as $p) {
            if ($p['numero'] === $entree[0]['numero']) {
                continue;
            }
            foreach ($p['cases'] as $c) {
                if (! $grille->estTraversable($c['x'], $c['y'])) {
                    continue;
                }
                $distance = $heros->min(fn ($h) => abs((int) $h->position_x - $c['x']) + abs((int) $h->position_y - $c['y']));
                if ($distance !== null && ($sortie === null || $distance < $sortie[2])) {
                    $sortie = [$c['x'], $c['y'], $distance, $p['numero']];
                }
            }
        }
        if ($sortie === null) {
            return null;
        }
        $instance->update(['position_x' => $sortie[0], 'position_y' => $sortie[1]]);
        $payload = ['type' => 'portail_monstre', 'monstre' => $instance->nomAffiche(), 'instance_id' => (int) $instance->id,
            'depuis' => (int) $entree[0]['numero'], 'numero' => (int) $sortie[3], 'x' => $sortie[0], 'y' => $sortie[1]];
        app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'monstre', 'id' => $instance->id, 'nom' => $instance->nomAffiche()]);

        return $payload;
    }
}
