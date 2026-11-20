<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\LanceurDes;
use App\Engine\Des\FaceDeCombat;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MoteurPortes;

/**
 * LE VERROU DE LA PORTE DE SORTIE (René, 2026-11-20 : « il y a déjà des portes barrées, il faudrait pouvoir mettre des
 * conditions pour la porte de sortie comme les portes barrées ») — point de passage unique, lu par le menu
 * (`MenuMoteur`, l'option « Quitter le donjon »), le résolveur (`ResolveurTour`, la sortie en personne) et les notes
 * (`ouvrir_porte` sur `"sortie"`).
 *
 * Le même vocabulaire que les portes (`portes[].verrou`, `MoteurPortes::VERROUS_DE_PORTE`), posé sur `quete.sortie` et figé
 * au départ dans `quetes.acces.sortie.verrou` (état durable : `deverrouillee`, en colonne, jamais en cache) :
 *  - `note` — scellée, rien qu'un héros puisse faire : seule une note l'ouvre (*Kellar's Keep* q. 10 : « magically locked
 *    and does not open until the gargoyle is dead ») ;
 *  - `cle` — passe qui porte l'objet ;
 *  - `condition` — CHAQUE héros qui veut passer lance les dés (*Kellar's Keep* q. 9 : « When any hero except the dwarf
 *    attempts to pass through the gate, tell them to roll 2 red dice. That hero may pass through the gate only if… If the
 *    hero fails to open the gate, their turn ends ») : un succès fait passer CE héros, un échec finit son tour, la porte ne
 *    reste pas ouverte pour les suivants ;
 *  - `pierre` — forcée une fois (deux crânes sur les dés d'attaque de base), elle reste ouverte.
 */
final class VerrouDeSortie
{
    public const LIBRE = 'libre';

    public const SCELLEE = 'scellee';

    public const SANS_CLE = 'sans_cle';

    public const JET_CONDITION = 'jet_condition';

    public const JET_PIERRE = 'jet_pierre';

    public function __construct(private readonly MoteurPortes $portes) {}

    /** Le verrou ENCORE fermé de la sortie, ou `null` (pas de verrou, ou déjà ouvert). */
    public function verrou(Quete $quete): ?array
    {
        if (data_get($quete->acces, 'sortie.deverrouillee') === true) {
            return null;
        }
        $verrou = data_get($quete->acces, 'sortie.verrou');

        return is_array($verrou) ? $verrou : null;
    }

    /** Ce que la porte de sortie demande à CE héros. Menu et résolveur. */
    public function statut(Quete $quete, Personnage $heros): string
    {
        $verrou = $this->verrou($quete);

        return match ($verrou['type'] ?? null) {
            null => self::LIBRE,
            MoteurPortes::VERROU_NOTE => self::SCELLEE,
            MoteurPortes::VERROU_CLE => $this->portes->possedeCle($heros, $verrou) ? self::LIBRE : self::SANS_CLE,
            MoteurPortes::VERROU_CONDITION => self::JET_CONDITION,
            MoteurPortes::VERROU_PIERRE => (int) $heros->des_attaque >= MoteurPortes::DES_MINIMUM_PORTE_PIERRE ? self::JET_PIERRE : self::SCELLEE,
            default => self::SCELLEE,
        };
    }

    /** Le libellé de ce qui se lance, décidé ici (la manette ne le recalcule pas) ; `null` sans jet. */
    public function libelleDuJet(Quete $quete, Personnage $heros): ?string
    {
        $verrou = $this->verrou($quete);

        return match ($this->statut($quete, $heros)) {
            self::JET_CONDITION => MoteurPortes::libelleCondition($verrou, $heros),
            self::JET_PIERRE => (int) $heros->des_attaque.' dés d\'attaque, deux crânes forcent la porte',
            default => null,
        };
    }

    /**
     * Le jet d'un héros qui tente de passer : `{reussi, faces, total?, valeur?}`. Une porte de pierre forcée reste ouverte
     * pour tous ; une condition ne vaut que pour le héros qui l'a réussie.
     *
     * @return array<string, mixed>
     */
    public function tenter(Quete $quete, Personnage $heros, LanceurDes $des): array
    {
        $verrou = (array) $this->verrou($quete);

        if ($this->statut($quete, $heros) === self::JET_PIERRE) {
            $faces = $des->desCombat((int) $heros->des_attaque);
            $cranes = count(array_filter($faces, fn (FaceDeCombat $f) => $f->estCrane()));
            $reussi = $cranes >= 2;
            if ($reussi) {
                $this->deverrouiller($quete);
            }

            return ['verrou' => MoteurPortes::VERROU_PIERRE, 'reussi' => $reussi, 'cranes' => $cranes,
                'faces' => array_map(fn (FaceDeCombat $f) => $f->value, $faces)];
        }

        $faces = [];
        for ($i = 0; $i < MoteurPortes::desPourHeros($verrou, $heros); $i++) {
            $faces[] = $des->d6(); // dés ROUGES
        }
        $total = array_sum($faces);
        $valeur = MoteurPortes::valeurDeComparaison($verrou, $heros);

        return ['verrou' => MoteurPortes::VERROU_CONDITION, 'reussi' => MoteurPortes::franchit($verrou, $total, $valeur),
            'faces' => $faces, 'total' => $total, 'valeur' => $valeur,
            'attribut' => $verrou['attribut'] ?? null, 'comparaison' => $verrou['comparaison'] ?? null];
    }

    /** Ouvre la sortie pour de bon (note `ouvrir_porte` sur `"sortie"`, porte de pierre forcée). Vrai si elle était fermée. */
    public function deverrouiller(Quete $quete): bool
    {
        if ($this->verrou($quete) === null) {
            return false;
        }
        $acces = (array) $quete->acces;
        $acces['sortie']['deverrouillee'] = true;
        $quete->update(['acces' => $acces]);

        return true;
    }
}
