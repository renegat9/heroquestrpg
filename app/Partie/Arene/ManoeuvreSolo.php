<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Partie\MoteurSorts;
use App\Support\Journal;

/**
 * MODE ARÈNE, lot T5 — le COMBATTANT SEUL (livret F9528 p. 16) :
 *
 * « In addition to their own turn, they may take a basic maneuver after each opponent's turn. A basic maneuver
 * consists of either moving up to their movement speed or rolling 2 Attack dice against an adjacent creature (if they
 * can usually attack diagonally, they may do so in the basic maneuver as well). A solo combatant may not take a basic
 * maneuver if they are incapacitated or would not be able to take their action (for example, under the Tempest
 * spell)… A combatant who becomes a solo combatant maintains its activation token. »
 *
 * Les deux camps y ont droit ; ce service répond aux questions qu'ils partagent (qui est SEUL, qui peut manœuvrer) et
 * ouvre la manœuvre du Challenger. Celle du Defender est jouée par le moteur (`ResolveurTour::manoeuvreDuDefender()`).
 *
 * ⚠ Lecture nommée — « after each opponent's turn » quand le combattant seul n'a PAS encore joué son activation du
 * round : l'activation alternée la lui donne juste après celle de l'adversaire. On ne lui donne pas EN PLUS une
 * manœuvre à ce moment-là (deux gestes d'affilée) : sa propre activation tient lieu de réponse. La manœuvre n'est
 * ouverte qu'une fois son activation du round jouée (`a_joue`), et c'est toujours vrai à partir de là.
 *
 * ⚠ « would not be able to take their action » : un héros dont le tour a été perdu ce round (Étourdi par *Tempest*,
 * gelé par *Mind Lock*) n'a jamais ouvert son menu — son jet de déplacement du round n'existe pas
 * (`deplacement_tour` nul). C'est la marque lue ici.
 */
final class ManoeuvreSolo
{
    /** « rolling 2 Attack dice » */
    public const DES_ATTAQUE = 2;

    public const TYPE_ATTAQUE = 'manoeuvre_attaque';

    /** Ce que le menu forcé du Challenger offre, et la liste que le résolveur revalide. */
    public const TYPES_PERMIS = ['deplacement', 'attente', 'trophee_elan', self::TYPE_ATTAQUE];

    /** Conditions de monstre qui l'empêchent d'agir : pas de manœuvre non plus. */
    public const CONDITIONS_INCAPACITANTES = [
        MoteurSorts::MONSTRE_ENDORMI,
        MoteurSorts::MONSTRE_SAUTE_TOUR,
        MoteurSorts::MONSTRE_PARALYSE,
        MoteurSorts::MONSTRE_ENCHAINE,
    ];

    public function __construct(private readonly MoteurSorts $sorts) {}

    /** Le héros SEUL debout du Challenger, ou `null` s'ils sont plusieurs (ou aucun). */
    public function challengerSolo(Groupe $groupe, Quete $quete): ?EtatPersonnageQuete
    {
        $actifs = $groupe->personnages()->wherePivot('actif', true)->pluck('personnages.id')->map(fn ($id) => (int) $id)->all();
        $debout = $quete->etatsPersonnages()->where('tombe', false)->whereIn('personnage_id', $actifs)->get();

        // Un allié debout (lot A4) est un combattant de plus : le héros n'est pas seul.
        $allies = \App\Models\GroupeMercenaire::where('groupe_id', $groupe->id)->where('etat', 'actif')->whereNotNull('position_x')
            ->whereIn('recruteur_personnage_id', $debout->pluck('personnage_id'))->exists();

        return $debout->count() === 1 && ! $allies ? $debout->first() : null;
    }

    /** La figure SEULE du Defender encore en jeu, ou `null`. */
    public function defenderSolo(Quete $quete): ?InstanceMonstre
    {
        $actives = $quete->instancesMonstres()->where('etat', 'actif')->whereNull('controle_par')->with('monstre')->get();

        return $actives->count() === 1 ? $actives->first() : null;
    }

    /** La figure du Defender peut-elle agir (ni endormie, ni paralysée, ni enchaînée, ni privée de son tour) ? */
    public function defenderPeutManoeuvrer(InstanceMonstre $instance): bool
    {
        foreach (self::CONDITIONS_INCAPACITANTES as $condition) {
            if ($this->sorts->monstreA($instance, $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Le Defender vient de finir une activation : si le Challenger n'a plus qu'un héros debout, qui a déjà joué son
     * activation du round et peut agir, sa manœuvre s'ouvre. Il reprend la main (`a_joue` levé, l'action marquée
     * prise : seul le déplacement reste à prendre, plus la frappe à 2 dés du menu forcé), et `OrdreDuTour` la lui
     * donne comme à tout héros qui a entamé son activation.
     */
    public function ouvrirPourChallenger(Groupe $groupe, Quete $quete): bool
    {
        if (! $groupe->estArene() || $quete->fresh()?->etat !== 'en_cours'
            || ! $quete->instancesMonstres()->where('etat', 'actif')->exists()) {
            return false;
        }

        $etat = $this->challengerSolo($groupe, $quete);

        if ($etat === null || ! $etat->a_joue || $etat->manoeuvre_arene || $etat->deplacement_tour === null) {
            return false;
        }

        $personnage = $etat->personnage;

        if ($personnage === null
            || ($this->sorts->deplacementInterdit($personnage) && $this->sorts->raisonAttaqueInterdite($personnage) !== null)) {
            return false;
        }

        $etat->update([
            'a_joue' => false, 'a_deplace' => false, 'a_agi' => true,
            'deplacement_restant' => null, 'manoeuvre_arene' => true,
        ]);

        Journal::ajouter($groupe, 'action', [
            'type' => 'manoeuvre_solo', 'camp' => TropheesArene::CHALLENGER, 'nom' => $personnage->nom,
            'geste' => 'ouverte',
        ], ['type' => 'heros', 'personnage_id' => $personnage->id, 'nom' => $personnage->nom]);

        return true;
    }

    /**
     * La manœuvre s'est refermée (le héros a « joué ») : la colonne retombe. Vrai quand une manœuvre vient de se
     * refermer — ce n'était pas une activation, l'adversaire n'y répond donc pas par la sienne (« after each opponent's
     * TURN »).
     */
    public function fermer(Quete $quete): bool
    {
        return $quete->etatsPersonnages()->where('manoeuvre_arene', true)->where('a_joue', true)->update(['manoeuvre_arene' => false]) > 0;
    }
}
