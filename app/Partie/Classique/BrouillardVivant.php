<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Partie\Salles;

/**
 * LE BROUILLARD VIVANT (mode classique, lot E2 — *The Frozen Horror* q. 7 note D, q. 9 note B) : « Whenever a hero
 * attacks a monster, the hero first rolls a combat die to see if they attack a fog likeness instead of a real monster. On
 * a roll of a black shield or skull, the hero is confused and attacks a fog likeness. (The hero wastes their attack.)
 * Only on a roll of a white shield does the hero see a real monster. The monsters always see the heroes correctly. »
 *
 * `regles.brouillard_vivant: [id de salle]`. Lu au point de passage de toute frappe de héros (`ResolveurTour::frapper()`)
 * quand le monstre visé est dans une de ces salles ; un leurre gaspille l'attaque (zéro dé), et le résultat le dit
 * (`brouillard: {face, leurre}`).
 */
final class BrouillardVivant
{
    public function __construct(private readonly LanceurDes $des) {}

    /** @return array{face: string, leurre: bool}|null `null` hors brouillard */
    public function detourne(Quete $quete, InstanceMonstre $cible): ?array
    {
        $salles = (array) data_get($quete->documentConstruit(), 'regles.brouillard_vivant', []);
        if ($salles === [] || ! $quete->estConstruite() || $cible->position_x === null) {
            return null;
        }
        $index = Salles::indexDe((array) ($quete->carte?->grille['salles'] ?? []), (int) $cible->position_x, (int) $cible->position_y);
        $id = $index === null ? null : data_get($quete->carte?->grille, "salles.{$index}.id");
        if ($id === null || ! in_array($id, $salles, true)) {
            return null;
        }
        $face = $this->des->deCombat();

        return ['face' => $face->value, 'leurre' => $face !== FaceDeCombat::BouclierBlanc];
    }
}
