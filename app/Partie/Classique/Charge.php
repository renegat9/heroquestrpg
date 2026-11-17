<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\TamponAnnonces;

/**
 * LA CHARGE À PORTER ET À RAMENER (mode classique, lot K5k, plan §2.3 R5 et §2.4 F4) — point de passage unique de son
 * état (`quetes.charge` : `{nom, x, y, porteur_id, sortie}`).
 *
 * Décisions de René (2026-11-17) : le porteur lance UN dé de déplacement au lieu de deux ; il agit normalement ; s'il
 * tombe, un autre héros la reprend sur sa case ; la quête est terminée dès que tous les héros encore vivants sont
 * sortis. Décision écrite : avec la fin `ramener`, la quête n'est GAGNÉE que si la charge est sortie avec un porteur —
 * sinon elle est perdue et se rejoue.
 */
final class Charge
{
    /** L'état de la charge de la quête, ou `null` si elle n'en a pas. */
    public function etat(Quete $quete): ?array
    {
        $charge = $quete->charge;

        return is_array($charge) && $quete->estConstruite() ? $charge : null;
    }

    /** Au départ de la quête : la charge posée par l'auteur, au sol. */
    public function poser(Quete $quete): void
    {
        $doc = data_get($quete->documentConstruit(), 'charge');
        if (is_array($doc) && isset($doc['nom'], $doc['x'], $doc['y'])) {
            $quete->update(['charge' => [
                'nom' => (string) $doc['nom'], 'x' => (int) $doc['x'], 'y' => (int) $doc['y'],
                'porteur_id' => null, 'sortie' => false,
            ]]);
        }
    }

    /** Le héros porte-t-il la charge ? Lu par le jet de déplacement (un dé de moins). */
    public function porte(Quete $quete, int $personnageId): bool
    {
        return (int) ($this->etat($quete)['porteur_id'] ?? 0) === $personnageId;
    }

    /** Peut-il la PRENDRE ? Debout, sur sa case ou au contact (murs compris), et elle est au sol. Menu ET résolveur. */
    public function prenable(Quete $quete, EtatPersonnageQuete $etat): bool
    {
        $charge = $this->etat($quete);
        if ($charge === null || $charge['porteur_id'] !== null || $charge['sortie'] || $etat->tombe || $etat->horsPlateau()) {
            return false;
        }
        $x = (int) $etat->position_x;
        $y = (int) $etat->position_y;

        return ($x === (int) $charge['x'] && $y === (int) $charge['y'])
            || Grille::sontAdjacentes($x, $y, (int) $charge['x'], (int) $charge['y'], carte: $quete->carte);
    }

    /** @return array<string, mixed> l'annonce */
    public function prendre(Quete $quete, Personnage $heros): array
    {
        $charge = $this->etat($quete);
        $quete->update(['charge' => [...$charge, 'porteur_id' => (int) $heros->id]]);

        return ['type' => 'charge_prise', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom, 'charge' => $charge['nom']];
    }

    /** Le porteur tombe : la charge reste sur SA case. Annoncé. */
    public function lacherSiPorteur(Quete $quete, EtatPersonnageQuete $etat): void
    {
        if (! $this->porte($quete, (int) $etat->personnage_id) || $etat->horsPlateau()) {
            return;
        }
        $charge = $this->etat($quete);
        $quete->update(['charge' => [...$charge, 'porteur_id' => null, 'x' => (int) $etat->position_x, 'y' => (int) $etat->position_y]]);

        if ($quete->groupe !== null) {
            app(TamponAnnonces::class)->annoncer($quete->groupe, [
                'type' => 'charge_lachee', 'personnage_id' => (int) $etat->personnage_id,
                'nom' => (string) ($etat->personnage?->nom ?? ''), 'charge' => $charge['nom'],
            ]);
        }
    }

    /** Le porteur sort du donjon : la charge sort avec lui. */
    public function sortirAvec(Quete $quete, int $personnageId): void
    {
        if ($this->porte($quete, $personnageId)) {
            $quete->update(['charge' => [...$this->etat($quete), 'sortie' => true]]);
        }
    }

    /** Fin `ramener` : la charge a-t-elle quitté le donjon ? */
    public function ramenee(Quete $quete): bool
    {
        return (bool) ($this->etat($quete)['sortie'] ?? false);
    }
}
