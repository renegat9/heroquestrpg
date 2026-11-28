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
use App\Partie\MoteurEmbuscade;
use App\Partie\Salles;
use App\Partie\TamponAnnonces;
use App\Partie\ZoneFouille;

/**
 * LES EMBUSCADES DU MODE CLASSIQUE — lot E2, *Wizards of Morcar* p. 4 (G1504) : « Zargon places it onto the board as the object
 * it appears to be (Dreadshifters in this expansion pack appear to be either a chest or a door). » Révélée par :
 * 1. « The first time a hero moves into any of the 8 squares surrounding to this object » — `MoteurEmbuscade::declenchees()`,
 *    déjà joué par la marche (`ResolveurTour`) ;
 * 2. « when a hero searches for traps in the room or corridor where they are located » — {@see parFouille()} ;
 * 3. « If a hero searches for treasure in a room with a hidden Ambush monster, remove the furniture, then place the
 *    corresponding monster next to the treasure-searcher and immediately roll Attack dice » — {@see parTresor()} ;
 * 4. « On Zargon's turn, they replace all hidden Ambush monsters with their model » — {@see auTourDuMJ()}, ceux qui sont sur
 *    le plateau (leur salle découverte, ou leur case de couloir vue).
 * Pose : `monstres[].deguise: "coffre" | {porte: "n"|"s"|"e"|"o"}` (`DemarreurQueteConstruite`), un monstre qui a `embuscade`.
 */
final class EmbuscadesClassiques
{
    public function __construct(private readonly MoteurEmbuscade $embuscade) {}

    /** @return \Illuminate\Support\Collection<int, InstanceMonstre> */
    private function deguises(Quete $quete)
    {
        return $quete->instancesMonstres()->where('etat', 'actif')->whereNotNull('position_x')->with('monstre')->get()
            ->filter(fn (InstanceMonstre $i) => MoteurEmbuscade::estDeguise($i))->values();
    }

    /** @return list<array<string, mixed>> les révélations, annoncées */
    public function parFouille(Groupe $groupe, Quete $quete, Personnage $heros, ZoneFouille $zone): array
    {
        if (! $quete->estConstruite()) {
            return [];
        }
        $reveles = [];
        foreach ($this->deguises($quete) as $instance) {
            if ($zone->contient((int) $instance->position_x, (int) $instance->position_y)) {
                $reveles[] = $this->annoncer($groupe, [...$this->embuscade->reveler($groupe, $quete, $instance, $heros), 'cause' => 'fouille']);
            }
        }

        return $reveles;
    }

    /**
     * La fouille du trésor d'une salle où se cache une embuscade : le meuble disparaît, la figure paraît au contact du
     * fouilleur et frappe aussitôt. Rend les révélations (frappe comprise sous `frappe`).
     *
     * @return list<array<string, mixed>>
     */
    public function parTresor(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $salle): array
    {
        if (! $quete->estConstruite() || $etat->position_x === null) {
            return [];
        }
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $reveles = [];
        foreach ($this->deguises($quete) as $instance) {
            if (Salles::indexDe($salles, (int) $instance->position_x, (int) $instance->position_y) !== $salle) {
                continue;
            }
            $revelation = $this->embuscade->reveler($groupe, $quete, $instance, $heros);
            $place = CaseLibre::proche(FabriqueGrille::pour($quete->fresh(), exceptInstanceId: (int) $instance->id),
                (int) $etat->position_x, (int) $etat->position_y);
            if ($place !== null) {
                $instance->update(['position_x' => $place['x'], 'position_y' => $place['y']]);
            }
            $frappe = app(\App\Partie\ResolveurTour::class)->frappeDuSurgissement($groupe, $instance->fresh()->load('monstre'), $etat->fresh());
            if ($frappe !== null) {
                app(TamponAnnonces::class)->annoncer($groupe, $frappe, ['type' => 'monstre', 'id' => $instance->id, 'nom' => $instance->nomAffiche()]);
            }
            $reveles[] = $this->annoncer($groupe, [...$revelation, 'cause' => 'tresor',
                'position' => ['x' => (int) $instance->fresh()->position_x, 'y' => (int) $instance->fresh()->position_y]]);
        }

        return $reveles;
    }

    /** Au tour du MJ : chaque embuscade sur le plateau (salle découverte, ou case de couloir vue) se révèle. @return list<array<string, mixed>> */
    public function auTourDuMJ(Groupe $groupe, Quete $quete): array
    {
        if (! $quete->estConstruite()) {
            return [];
        }
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $decouvertes = $quete->sallesDecouvertes();
        $vus = app(CouloirsVus::class)->vus($quete) ?? [];
        $premier = $quete->etatsPersonnages()->whereNotNull('position_x')->with('personnage')->first()?->personnage;
        $reveles = [];
        foreach ($this->deguises($quete) as $instance) {
            $x = (int) $instance->position_x;
            $y = (int) $instance->position_y;
            $salle = Salles::indexDe($salles, $x, $y);
            $surLePlateau = $salle !== null ? in_array($salle, $decouvertes, true) : isset($vus["{$x},{$y}"]);
            if ($surLePlateau && $premier !== null) {
                $reveles[] = $this->annoncer($groupe, [...$this->embuscade->reveler($groupe, $quete, $instance, $premier), 'cause' => 'tour_du_mj']);
            }
        }

        return $reveles;
    }

    /**
     * Les fausses portes à publier (`EtatGroupe`), sous la forme d'une porte fermée sur un bord (est ou sud, comme la grille).
     *
     * @return list<array{x: int, y: int, cote: string}>
     */
    public static function faussesPortes(Quete $quete): array
    {
        if (! $quete->estConstruite()) {
            return [];
        }
        $portes = [];
        foreach ($quete->instancesMonstres()->where('etat', 'actif')->whereNotNull('position_x')->get() as $instance) {
            if ($instance->etatDread('deguise') !== MoteurEmbuscade::DEGUISEMENT_PORTE) {
                continue;
            }
            $x = (int) $instance->position_x;
            $y = (int) $instance->position_y;
            $portes[] = match ((string) $instance->etatDread('porte_cote')) {
                'o' => ['x' => $x - 1, 'y' => $y, 'cote' => 'e'],
                'n' => ['x' => $x, 'y' => $y - 1, 'cote' => 's'],
                's' => ['x' => $x, 'y' => $y, 'cote' => 's'],
                default => ['x' => $x, 'y' => $y, 'cote' => 'e'],
            };
        }

        return $portes;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function annoncer(Groupe $groupe, array $payload): array
    {
        app(TamponAnnonces::class)->annoncer($groupe, $payload,
            ['type' => 'monstre', 'id' => $payload['instance_id'] ?? null, 'nom' => (string) ($payload['monstre'] ?? '')]);

        return $payload;
    }
}
