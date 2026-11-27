<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use Illuminate\Validation\ValidationException;

/**
 * L'INSUBSTANTIALITÉ — lot E2, *Spirit Queen's Torment* q. 14 (G0053 p. 33) : « Each hero may become insubstantial three
 * times. Each use can be used to ignore any one source of damage, to pass through walls or solid objects on a hero's next
 * move (they die if they end in a solid square), or to roll 2 additional movement dice. »
 *
 * `regles.insubstantialite {usages}` : un compteur PAR HÉROS pour la quête (`quetes.drapeaux._insubstantiel`, usages
 * dépensés), trois usages interchangeables :
 * - IGNORER UN DÉGÂT : une RÉACTION au téléphone (René, 2026-11-27) — `MoteurReactions::proposer()` la dépose pour tout coup,
 *   quelle qu'en soit la source ; acceptée, le coup est défait et un usage part (`ReactionEffet::ANNULE_DEGATS`) ;
 * - TRAVERSER LES MURS : une option de son tour (gratuite), avant de bouger — un marqueur de `capacites_tour` lu par le point
 *   de passage du Traverser la Pierre (`MoteurSorts::traverseRoche()`) pour ce tour. Écart écrit : « they die if they end in
 *   a solid square » ne se joue pas, les destinations publiées n'offrent jamais une case de roche ;
 * - DEUX DÉS DE PLUS : une option gratuite, après le jet et avant le premier pas — deux dés rouges ajoutés au jet du tour.
 */
final class Insubstantialite
{
    private const ETAT = '_insubstantiel';

    public const MARQUEUR_MURS = 'insubstantiel_murs';

    private const MARQUEUR_DES = 'insubstantiel_des';

    public const DES = 2;

    public function __construct(private readonly LanceurDes $des) {}

    public static function usages(Quete $quete): ?int
    {
        if (! $quete->estConstruite()) {
            return null;
        }
        $usages = data_get($quete->documentConstruit(), 'regles.insubstantialite.usages');

        return is_int($usages) ? $usages : null;
    }

    public static function restants(Quete $quete, Personnage $heros): int
    {
        $usages = self::usages($quete);

        return $usages === null ? 0 : max(0, $usages - (int) data_get($quete->relire('drapeaux')->drapeaux, self::ETAT.'.'.$heros->id, 0));
    }

    public static function depenser(Quete $quete, Personnage $heros): void
    {
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::ETAT][$heros->id] = (int) ($drapeaux[self::ETAT][$heros->id] ?? 0) + 1;
        $quete->update(['drapeaux' => $drapeaux]);
    }

    /** Le héros traverse-t-il les murs CE tour-ci ? (lu par `MoteurSorts::traverseRoche()`) */
    public static function murs(Personnage $heros): bool
    {
        $etat = EtatPersonnageQuete::enQuete($heros);

        return $etat !== null && in_array(self::MARQUEUR_MURS, (array) ($etat->capacites_tour ?? []), true);
    }

    /**
     * Les deux usages de son tour qu'il peut prendre MAINTENANT : `murs` et `des`, avant son premier pas.
     *
     * @return list<string>
     */
    public function usagesDuTour(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat): array
    {
        if ($etat->tombe || $etat->a_joue || $etat->a_deplace || $etat->deplacement_restant !== null
            || $etat->position_x === null || self::restants($quete, $heros) <= 0) {
            return [];
        }
        $marques = (array) ($etat->capacites_tour ?? []);

        return array_values(array_filter([
            in_array(self::MARQUEUR_MURS, $marques, true) ? null : 'murs',
            in_array(self::MARQUEUR_DES, $marques, true) || $etat->deplacement_tour === null ? null : 'des',
        ]));
    }

    /**
     * Prend un usage de son tour (`murs` ou `des`), revalidé contre `usagesDuTour()`.
     *
     * @return array<string, mixed> le résultat moteur `insubstantiel`
     */
    public function prendre(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, string $usage): array
    {
        if (! in_array($usage, $this->usagesDuTour($quete, $heros, $etat), true)) {
            throw ValidationException::withMessages(['option_id' => 'Tu ne peux plus devenir insubstantiel ainsi ce tour-ci.']);
        }
        self::depenser($quete, $heros);
        $marque = $usage === 'murs' ? self::MARQUEUR_MURS : self::MARQUEUR_DES;
        $maj = ['capacites_tour' => [...(array) ($etat->capacites_tour ?? []), $marque]];
        $des = [];
        if ($usage === 'des') {
            for ($i = 0; $i < self::DES; $i++) {
                $des[] = $this->des->d6();
            }
            $detail = (array) ($etat->detail_deplacement_tour ?? []);
            $detail['des'] = [...(array) ($detail['des'] ?? []), ...$des];
            $maj += ['deplacement_tour' => (int) $etat->deplacement_tour + array_sum($des), 'detail_deplacement_tour' => $detail];
        }
        $etat->update($maj);

        return ['type' => 'insubstantiel', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom, 'usage' => $usage,
            ...($des !== [] ? ['des' => $des, 'deplacement' => (int) $etat->fresh()->deplacement_tour] : []),
            'restants' => self::restants($quete, $heros)];
    }
}
