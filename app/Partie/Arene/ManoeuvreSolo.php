<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Partie\MoteurAnneauDeSort;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Support\Journal;

/**
 * MODE ARÈNE, lot T5 — le COMBATTANT SEUL (livret F9528 p. 12-13, 16) :
 *
 * « In addition to their own turn, they may take a basic maneuver after each opponent's turn. A basic maneuver
 * consists of either moving up to their movement speed or rolling 2 Attack dice against an adjacent creature (if they
 * can usually attack diagonally, they may do so in the basic maneuver as well). A solo combatant may not take a basic
 * maneuver if they are incapacitated or would not be able to take their action (for example, under the Tempest
 * spell)… A combatant who becomes a solo combatant maintains its activation token. »
 *
 * LA LETTRE, décision de René (2026-11-12) qui remplace la « lecture nommée » du lot T5 : le combattant seul prend sa
 * manœuvre après CHAQUE activation adverse, qu'il ait déjà joué SA activation du round ou non. Une manœuvre ne consomme
 * pas son activation (« maintains its activation token ») : elle se referme, et l'activation reprend là où elle en était.
 * Symétriquement, la figure seule du Defender manœuvre après chaque activation de héros (`ResolveurTour::manoeuvreDuDefender()`).
 *
 * Deux états, à ne pas confondre, tous deux en colonne :
 *  - `manoeuvre_arene` : la MAIN est à la manœuvre, seule (`OrdreDuTour`) ; le menu se restreint (`MenuMoteur::menuDeManoeuvre()`)
 *    et le résolveur revalide (`ResolveurTour::resoudre()`) ;
 *  - `a_joue` : l'activation du round est-elle jouée ? L'ouverture de la manœuvre ne la lève JAMAIS.
 *
 * ⚠ « would not be able to take their action » : un héros dont le tour a été perdu ce round (Étourdi par *Tempest*, gelé
 * par *Mind Lock*) a son activation posée à l'ouverture du round, sans jet de déplacement (`deplacement_tour` nul).
 * C'est la marque lue par `peutManoeuvrer()`.
 */
final class ManoeuvreSolo
{
    /** « rolling 2 Attack dice » */
    public const DES_ATTAQUE = 2;

    public const TYPE_ATTAQUE = 'manoeuvre_attaque';

    /** Ce que le menu forcé du Challenger offre, et la liste que le résolveur revalide. */
    public const TYPES_PERMIS = ['deplacement', 'attente', 'trophee_elan', self::TYPE_ATTAQUE];

    /** Les gestes qui REFERMENT la manœuvre : « Passer / Terminer la manœuvre », et la frappe à 2 dés (qui la referme). */
    public const TYPES_FERMANTS = ['attente', self::TYPE_ATTAQUE];

    /** Conditions de monstre qui l'empêchent d'agir : pas de manœuvre non plus. */
    public const CONDITIONS_INCAPACITANTES = [
        MoteurSorts::MONSTRE_ENDORMI,
        MoteurSorts::MONSTRE_SAUTE_TOUR,
        MoteurSorts::MONSTRE_PARALYSE,
        MoteurSorts::MONSTRE_ENCHAINE,
    ];

    /**
     * Conditions d'un HÉROS qui lui ôtent son propre tour : le moteur le joue ou le saute à sa place
     * (`ResolveurTour::resoudre()`, avant tout geste du joueur). Pas de manœuvre pour lui.
     */
    public const CONDITIONS_HEROS_SANS_MANOEUVRE = ['Endormi', 'Commandé', 'Possédé'];

    public function __construct(private readonly MoteurSorts $sorts, private readonly MoteurDread $dread) {}

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
     * Le héros peut-il manœuvrer, à l'instant ? Un seul prédicat, lu à l'ouverture (`ouvrirPourChallenger()`).
     *
     * Non pour : un héros tombé ; un héros dont le TOUR EST PERDU ce round (Étourdi, gelé, transformé — son activation
     * est posée à l'ouverture du round, sans jet de déplacement) ; un héros que le moteur joue ou saute à sa place
     * (Endormi, Commandé, Possédé) ; un héros qu'une règle force avant tout geste (`forceUnGeste()`, ou la déclaration
     * de l'Anneau de Sort) ; un héros privé d'ACTION (Paralysé, Évanescent : « would not be able to take their action »,
     * `actionInterdite()`) : le résolveur ne lit que `raisonAttaqueInterdite()` pour la frappe, donc c'est ICI qu'on refuse
     * la manœuvre ; un héros qui ne peut ni se déplacer ni frapper.
     */
    public function peutManoeuvrer(Groupe $groupe, EtatPersonnageQuete $etat): bool
    {
        $personnage = $etat->personnage;

        if ($personnage === null || $etat->tombe || $etat->manoeuvre_arene) {
            return false;
        }

        if ($etat->a_joue && $etat->deplacement_tour === null) {
            return false;
        }

        foreach (self::CONDITIONS_HEROS_SANS_MANOEUVRE as $condition) {
            if ($this->dread->herosSousCondition($personnage, $condition)) {
                return false;
            }
        }

        if (self::forceUnGeste($etat) || app(MoteurAnneauDeSort::class)->declarationAttendue($groupe, $personnage, $etat)) {
            return false;
        }

        if ($this->sorts->actionInterdite($personnage)) {
            return false;
        }

        return ! ($this->sorts->deplacementInterdit($personnage) && $this->sorts->raisonAttaqueInterdite($personnage) !== null);
    }

    /**
     * Une règle impose-t-elle au héros un geste AVANT tout autre — enlisé dans les sables, chute de blocs à écarter, vent
     * de couloir ? Ce menu forcé ne se joue pas dans une manœuvre : `menuDeManoeuvre()` n'en garderait aucune option.
     */
    public static function forceUnGeste(EtatPersonnageQuete $etat): bool
    {
        return $etat->enlise !== null || $etat->piege_a_ecarter !== null || $etat->vent_choix !== null;
    }

    /** Ce geste referme-t-il la manœuvre (et non l'activation) ? Le prédicat unique, lu par le résolveur. */
    public static function referme(string $type): bool
    {
        return in_array($type, self::TYPES_FERMANTS, true);
    }

    /**
     * Le Defender vient de finir une activation : si le Challenger n'a plus qu'un héros debout, et qu'il peut manœuvrer
     * (`peutManoeuvrer()`), sa manœuvre s'ouvre — qu'il ait déjà joué son activation du round ou non. La main lui revient
     * (`manoeuvre_arene`, lu par `OrdreDuTour`) ; son déplacement de manœuvre repart de zéro. `a_joue` n'est pas touché.
     */
    public function ouvrirPourChallenger(Groupe $groupe, Quete $quete): bool
    {
        if (! $groupe->estArene() || $quete->fresh()?->etat !== 'en_cours'
            || ! $quete->instancesMonstres()->where('etat', 'actif')->exists()) {
            return false;
        }

        $etat = $this->challengerSolo($groupe, $quete);

        if ($etat === null || ! $this->peutManoeuvrer($groupe, $etat)) {
            return false;
        }

        $etat->update(['manoeuvre_arene' => true, 'a_deplace' => false, 'deplacement_restant' => null]);

        $personnage = $etat->personnage;

        Journal::ajouter($groupe, 'action', [
            'type' => 'manoeuvre_solo', 'camp' => TropheesArene::CHALLENGER, 'nom' => $personnage->nom,
            'geste' => 'ouverte',
        ], ['type' => 'heros', 'personnage_id' => $personnage->id, 'nom' => $personnage->nom]);

        return true;
    }

    /**
     * La manœuvre vient de se REFERMER : son geste de fin est résolu (« Passer / Terminer la manœuvre », la frappe à 2
     * dés, ou une règle qui coupe le héros). La main revient à l'activation, et l'allonce de la manœuvre ne compte pas
     * pour elle : elle repart de zéro. Rend `true` si une manœuvre vient de se refermer — ce n'était pas une activation,
     * l'adversaire n'y répond donc pas par la sienne (« after each opponent's TURN »).
     */
    public function fermer(Quete $quete): bool
    {
        return $quete->etatsPersonnages()->where('manoeuvre_arene', true)
            ->update(['manoeuvre_arene' => false, 'a_deplace' => false, 'deplacement_restant' => null]) > 0;
    }
}
