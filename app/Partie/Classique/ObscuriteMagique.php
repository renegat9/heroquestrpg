<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Salles;

/**
 * L'OBSCURITÉ MAGIQUE (mode classique, lot K6 — *The Crypt of Perpetual Darkness*, G1798 p. 25, q. 10 note C) : « Any hero
 * who cannot see in magical darkness has -2 combat dice the first and second time they attack, and then they may attack
 * as normal. The monsters here do not suffer from this effect. » Contrepoids : la *Crown of Shadows* (« see normally in
 * darkness, both magical and nonmagical » → `vision_nocturne`, `Equipement::voitDansLeNoir()`).
 *
 * `regles.obscurite_magique: {salle: <id d'une salle ouverte>}`. Décisions écrites (le livret ne tranche pas, René les a
 * laissées au choix par défaut le 2026-11-19) :
 * - la zone est la case de l'ATTAQUANT : un héros qui frappe depuis la salle obscure ;
 * - jamais moins d'un dé, comme le malus de la Fosse (`MoteurFosseLongue::appliquerMalus()`) ;
 * - le compteur des deux premières attaques est par héros et par quête (`etat.capacites_utilisees['obscurite_magique']`,
 *   en base) ;
 * - toute frappe d'un héros compte (menu, tir, lancer, Furie, riposte) : le point de passage est `ResolveurTour::frapper()`.
 */
final class ObscuriteMagique
{
    private const COMPTEUR = 'obscurite_magique';

    public const MALUS = 2;

    public const ATTAQUES_GENEES = 2;

    public function __construct(private readonly Equipement $equipement) {}

    /** L'id de la salle obscure de la quête construite, ou `null`. */
    public static function salle(?Quete $quete): ?string
    {
        if ($quete === null || ! $quete->estConstruite()) {
            return null;
        }
        $salle = data_get($quete->documentConstruit(), 'regles.obscurite_magique.salle');

        return is_string($salle) ? $salle : null;
    }

    /** Le malus de CETTE attaque du héros (0 ou 2), sans rien consommer. */
    public function malus(Quete $quete, EtatPersonnageQuete $etat, Personnage $personnage): int
    {
        $salle = self::salle($quete);
        if ($salle === null || $etat->horsPlateau() || $this->equipement->voitDansLeNoir($personnage)) {
            return 0;
        }
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $ici = Salles::indexDe($salles, (int) $etat->position_x, (int) $etat->position_y);
        if ($ici === null || ($salles[$ici]['id'] ?? null) !== $salle) {
            return 0;
        }

        return (int) data_get($etat->capacites_utilisees, self::COMPTEUR, 0) < self::ATTAQUES_GENEES ? self::MALUS : 0;
    }

    /** L'attaque gênée est jouée : le compteur avance (en base). */
    public function compter(EtatPersonnageQuete $etat): void
    {
        $capacites = (array) $etat->capacites_utilisees;
        $capacites[self::COMPTEUR] = (int) ($capacites[self::COMPTEUR] ?? 0) + 1;
        $etat->update(['capacites_utilisees' => $capacites]);
    }
}
