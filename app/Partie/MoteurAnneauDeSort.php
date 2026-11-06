<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\MotsClesEquipement;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;

/**
 * L'ANNEAU DE SORT — carte © 2024 (*First Light*) : « While wearing this ring, you may cast one of the spells you know
 * twice in one quest instead of the usual one-time use. The spell may not be cast twice on the same turn. At the
 * beginning of each quest, you must declare which spell is stored in the ring. »
 *
 * René, 2026-11-05 : la déclaration se fait au PREMIER TOUR du porteur, par une fenêtre IMPOSÉE (« forcer un popup ») ;
 * un porteur qui ne lance pas de sorts n'en voit aucune. Avant, l'anneau gardait d'office le premier sort lancé.
 *
 * ⚠ Seul lecteur et seul écrivain de `etat_personnage_quete.anneau_de_sort` :
 *  - `null`                        : fenêtre ouverte (premier tour de la quête) ;
 *  - `{sort_id, nom, relance}`     : déclaré ; `relance` = le second lancer est encore dû ;
 *  - `{ferme: true}`               : le premier tour est passé sans déclaration — l'anneau ne sert pas cette quête.
 * « Pas deux fois dans le même tour » n'est pas ici : c'est la règle commune de `MoteurSorts::lanceCeTour()`.
 */
final class MoteurAnneauDeSort
{
    public const TYPE = 'anneau_de_sort';

    public function __construct(
        private readonly MoteurCharges $charges,
        private readonly OrdreDuTour $ordreDuTour,
    ) {}

    /** L'anneau PORTÉ (« while wearing ») par ce héros, ou `null`. */
    public function anneau(Personnage $personnage, ?EtatPersonnageQuete $etat = null): ?Inventaire
    {
        return $this->charges->pieceActive($personnage, MotsClesEquipement::SORT_NON_EPUISE, $etat);
    }

    /**
     * Les sorts qu'il peut y garder : ceux qu'il connaît et n'a pas épuisés — LA liste blanche de l'option.
     *
     * @return list<array{cle: string, sort_id: int, nom: string, element: string|null, sort_type: string|null, disponible: bool}>
     */
    public function sortsDeclarables(Personnage $personnage): array
    {
        return $personnage->sorts()->wherePivot('disponible', true)->orderBy('sorts.id')->get()
            ->map(fn ($sort) => [
                'cle' => "sort:{$sort->id}",
                'sort_id' => (int) $sort->id,
                'nom' => (string) $sort->nom,
                'element' => $sort->element,
                'sort_type' => $sort->type,
                'disponible' => true,
            ])->values()->all();
    }

    /**
     * La déclaration est-elle DUE maintenant ? Fenêtre ouverte, anneau porté, au moins un sort à garder, et c'est le
     * tour de ce héros, debout, qui n'a pas fini.
     */
    public function declarationAttendue(Groupe $groupe, Personnage $personnage, EtatPersonnageQuete $etat): bool
    {
        return $etat->anneau_de_sort === null
            && ! $etat->tombe
            && ! $etat->a_joue
            && $this->anneau($personnage, $etat) !== null
            && $this->sortsDeclarables($personnage) !== []
            && $this->ordreDuTour->estSonTour($groupe, (int) $personnage->id);
    }

    /**
     * Le menu FORCÉ de la déclaration, ou `null`. Une seule option, marquée `imposee` : la manette ouvre la liste d'elle-
     * même, sans retour. Même court-circuit que les sables mouvants (`MoteurSables::menuForce()`).
     *
     * @return array{situation: string, options: list<array<string, mixed>>}|null
     */
    public function menuForce(Groupe $groupe, Personnage $personnage, EtatPersonnageQuete $etat): ?array
    {
        if (! $this->declarationAttendue($groupe, $personnage, $etat)) {
            return null;
        }

        return [
            'situation' => 'Votre Anneau de Sort attend : choisissez le sort qu\'il garde pour cette quête. '
                .'Vous pourrez le lancer deux fois, mais jamais deux fois dans le même tour.',
            'options' => [[
                'id' => self::TYPE,
                'libelle' => 'Choisir le sort gardé par l\'Anneau de Sort',
                // Littéral, pas `self::TYPE` : le registre des annonces (`JournalCombat::TYPES`) lit les sources.
                'type' => 'anneau_de_sort',
                'imposee' => true,
                'parametres' => ['sorts' => $this->sortsDeclarables($personnage)],
            ]],
        ];
    }

    /**
     * Déclare le sort gardé. `$entree` vient de la liste blanche de l'option (`ResolveurTour::entreeChoisie()`). Gratuit :
     * le tour continue.
     *
     * @param  array<string, mixed>  $entree
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    public function declarer(Groupe $groupe, Personnage $personnage, EtatPersonnageQuete $etat, array $entree, array $acteur): array
    {
        $sort = ['id' => (int) $entree['sort_id'], 'nom' => (string) $entree['nom']];
        $etat->update(['anneau_de_sort' => ['sort_id' => $sort['id'], 'nom' => $sort['nom'], 'relance' => true]]);

        $payload = ['type' => 'anneau_de_sort', 'sort' => $sort];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Le sort qu'on vient de lancer est-il GARDÉ par l'anneau ? Oui pour le sort déclaré, une fois, anneau porté. Le
     * second lancer est alors dépensé : le suivant épuisera le sort comme d'habitude.
     */
    public function garder(Personnage $personnage, EtatPersonnageQuete $etat, int $sortId): bool
    {
        $declaration = (array) ($etat->anneau_de_sort ?? []);

        if ((int) ($declaration['sort_id'] ?? 0) !== $sortId || empty($declaration['relance'])
            || $this->anneau($personnage, $etat) === null) {
            return false;
        }

        $etat->update(['anneau_de_sort' => [...$declaration, 'relance' => false]]);

        return true;
    }

    /**
     * Fin du premier tour : la manche se rouvre, la fenêtre se ferme pour tous ceux qui n'ont rien déclaré — porteurs
     * ou non (un anneau équipé plus tard ne se déclare plus : « at the beginning of each quest »).
     */
    public function fermerFenetres(Quete $quete): void
    {
        foreach ($quete->etatsPersonnages()->whereNull('anneau_de_sort')->get() as $etat) {
            $etat->update(['anneau_de_sort' => ['ferme' => true]]);
        }
    }
}
