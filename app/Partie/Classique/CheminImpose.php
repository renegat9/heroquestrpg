<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\FabriqueGrille;
use App\Partie\ResolveurTour;
use App\Partie\TamponAnnonces;

/**
 * LE CHEMIN IMPOSÉ (mode classique, lot E2 — *Prophecy of Telor* q. 6, « The Ruined Temple of Gilgarreth ») : « Tell the
 * players that the bearer must go first each turn. […] You move the bearer's miniature 9 squares for the first four turns.
 * Count them out exactly along the path indicated to land on the square matching the turn number. This uses the bearer's
 * movement, but they may still perform actions. » Et la note C : « The bearer must immediately roll 2 red dice. Move the
 * hero that number of squares along the path shown ».
 *
 * `regles.chemin_impose {porteur: slug d'objet, cases: [{x, y}…] (le chemin dans l'ordre), arrets: [rang…]}` :
 *  - le héros qui PORTE l'objet `porteur` joue en premier à chaque round (`OrdreDuTour`, {@see porteurId()}) ;
 *  - au début de son tour N (N = `tours_mj` + 1), tant qu'il reste un arrêt, il est mené le long du chemin jusqu'à la case
 *    `cases[arrets[N-1]]` ({@see debutDeTour()}) : son déplacement est pris (`a_deplace`), son action reste ; les cases
 *    foulées signalent leurs notes comme une marche (`ResolveurTour::apresMarcheImposee()`) ;
 *  - l'effet de note `avancer_sur_chemin {des}` le fait avancer du total des dés ({@see avancer()}).
 * Une porte close que le chemin franchit s'ouvre à son passage. Une case d'arrivée prise : il s'arrête sur la dernière case
 * libre avant elle. Hors du plateau (il attend à la porte
 * d'entrée), il part d'avant la première case.
 */
final class CheminImpose
{
    public const MARQUEUR = 'chemin_impose';

    public function __construct(private readonly LanceurDes $des) {}

    /** @return array{porteur: string, cases: list<array{x: int, y: int}>, arrets: list<int>}|null */
    public static function regle(?Quete $quete): ?array
    {
        if ($quete === null || ! $quete->estConstruite()) {
            return null;
        }
        $regle = data_get($quete->documentConstruit(), 'regles.chemin_impose');

        return is_array($regle) ? $regle : null;
    }

    /** L'id du héros actif du groupe qui porte l'objet du chemin (sac compris), ou `null`. */
    public static function porteurId(Groupe $groupe, ?Quete $quete): ?int
    {
        $regle = self::regle($quete);
        if ($regle === null) {
            return null;
        }
        $objet = app(CatalogueConstructeur::class)->index('objets')[(string) $regle['porteur']] ?? null;
        if ($objet === null) {
            return null;
        }
        $id = $groupe->personnages()->wherePivot('actif', true)
            ->whereHas('inventaire', fn ($q) => $q->where('objet_id', $objet->id))
            ->value('personnages.id');

        return $id === null ? null : (int) $id;
    }

    /** Le début du tour du porteur : mené jusqu'à l'arrêt du tour, s'il en reste un. Rend l'annonce, ou `null`. */
    public function debutDeTour(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat): ?array
    {
        $regle = self::regle($quete);
        if ($regle === null || $etat->tombe || in_array(self::MARQUEUR, (array) ($etat->capacites_tour ?? []), true)
            || self::porteurId($groupe, $quete) !== (int) $heros->id) {
            return null;
        }
        $tour = (int) $quete->tours_mj + 1;
        $arret = $regle['arrets'][$tour - 1] ?? null;
        if ($arret === null) {
            return null;
        }
        $etat->update(['capacites_tour' => [...(array) ($etat->capacites_tour ?? []), self::MARQUEUR]]);
        $marche = $this->mener($groupe, $quete, $heros, $etat, (int) $arret);
        if ($marche === null) {
            return null;
        }
        // « This uses the bearer's movement, but they may still perform actions. »
        $etat->fresh()->update(['a_deplace' => true]);
        $payload = ['type' => 'chemin_impose', 'nom' => $heros->nom, 'tour' => $tour, ...$marche];
        app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return $payload;
    }

    /**
     * L'effet `avancer_sur_chemin` : le héros, s'il est sur le chemin, avance du total de `$nombreDes` dés.
     *
     * @return array{faces: list<int>, total: int, cases?: int, x?: int, y?: int}|null `null` s'il n'est pas sur le chemin
     */
    public function avancer(Quete $quete, Personnage $heros, int $nombreDes): ?array
    {
        $regle = self::regle($quete);
        $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->first();
        if ($regle === null || $etat === null || $this->rang($regle, $etat) === null) {
            return null;
        }
        $faces = [];
        for ($i = 0; $i < max(1, $nombreDes); $i++) {
            $faces[] = $this->des->d6();
        }
        $total = array_sum($faces);
        $marche = $this->mener($quete->groupe, $quete, $heros, $etat, (int) $this->rang($regle, $etat) + $total, notes: false);

        return ['faces' => $faces, 'total' => $total, ...($marche ?? [])];
    }

    /** Le rang du héros sur le chemin : -1 hors du plateau, `null` sur une case hors du chemin. */
    private function rang(array $regle, EtatPersonnageQuete $etat): ?int
    {
        if ($etat->position_x === null) {
            return -1;
        }
        foreach ($regle['cases'] as $i => $c) {
            if ((int) $c['x'] === (int) $etat->position_x && (int) $c['y'] === (int) $etat->position_y) {
                return (int) $i;
            }
        }

        return null;
    }

    /**
     * Mène le héros jusqu'au rang `$vise` (borné au bout du chemin), ou jusqu'à la dernière case libre avant. Rend le
     * nombre de cases parcourues et l'arrivée, ou `null` s'il ne bouge pas. `$notes` : les cases foulées signalent leurs
     * notes (le début de tour) ; un effet de note qui fait avancer ne relance pas les notes (comme `teleporter`).
     *
     * @return array{cases: int, x: int, y: int}|null
     */
    private function mener(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $vise, bool $notes = true): ?array
    {
        $regle = (array) self::regle($quete);
        $depuis = $this->rang($regle, $etat);
        if ($depuis === null) {
            return null;
        }
        $vise = min($vise, count($regle['cases']) - 1);
        $grille = FabriqueGrille::pour($quete->fresh());
        while ($vise > $depuis && ! $grille->estTraversable((int) $regle['cases'][$vise]['x'], (int) $regle['cases'][$vise]['y'])) {
            $vise--;
        }
        if ($vise <= $depuis) {
            return null;
        }
        $avant = $etat->position_x === null ? null : ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
        $chemin = array_map(fn (array $c) => ['x' => (int) $c['x'], 'y' => (int) $c['y']], array_slice($regle['cases'], $depuis + 1, $vise - $depuis));
        $arrivee = end($chemin);
        // « Count them out exactly along the path indicated » : une porte close que le chemin franchit s'ouvre sur son passage.
        $resolveur = app(ResolveurTour::class);
        $pas = $avant === null ? $chemin : [$avant, ...$chemin];
        foreach ((array) ($quete->carte?->grille['portes'] ?? []) as $i => $porte) {
            $bords = \App\Partie\Grille::casesPorte($porte);
            for ($k = 1; $k < count($pas); $k++) {
                $paire = [$pas[$k - 1], $pas[$k]];
                if (in_array($bords[0], $paire, true) && in_array($bords[1], $paire, true)) {
                    $resolveur->ouvrirPorteParNote($quete->fresh(), (int) $i);
                }
            }
        }
        $etat->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);
        app(ResolveurTour::class)->apresMarcheImposee($groupe, $quete->fresh(), $heros, $etat->fresh(), $avant, $chemin, $notes);

        return ['cases' => count($chemin), 'x' => $arrivee['x'], 'y' => $arrivee['y']];
    }
}
