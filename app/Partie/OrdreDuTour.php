<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use Illuminate\Support\Collection;

/**
 * À QUI est-ce le tour ? — point de passage unique.
 *
 * Le premier héros de l'ordre d'initiative FIGÉ (C1) qui soit encore debout et
 * n'ait pas joué ce round — OU, depuis le 2026-10-04 (chantier 3a, décision de
 * René : « un allié est TOUJOURS joué par son joueur »), l'allié qu'il
 * contrôle, si ce héros a déjà joué mais que son allié ne l'a pas encore fait :
 * l'allié joue DANS le tour du héros qui le contrôle, juste après lui — jamais
 * dans une phase dédiée après tous les héros.
 *
 * ⚠ Cette lecture vivait en DEUX exemplaires, `ChoixController::estSonTour()`
 * et `ResolveurTour::verifierInitiative()`, et le docblock du contrôleur
 * assumait la copie : « les deux doivent simplement dire non au même moment ».
 * Le 2026-09-16, le jet de déplacement a dû apprendre la même chose — il se
 * lançait pour TOUS les héros dès que leur menu était calculé, donc au début du
 * round, alors que René voulait le voir « au début d'un tour de joueur ». Une
 * troisième copie d'une règle assez simple pour que personne ne remarque l'une
 * dériver, c'est le défaut le plus répété du projet : la règle est ici —
 * désormais `acteurActif()`, dont `herosActifId()` n'est qu'une projection.
 *
 * ⚠ **Allié dont le contrôleur est TOMBÉ : décision nommée** (il n'existe pas
 * de suivi de présence/déconnexion d'un JOUEUR côté serveur — seul le
 * narrateur a un heartbeat, `table:active:{id}` — donc « absent » n'est, pour
 * ce point de passage, observable qu'à travers « tombé »). Un héros tombé est
 * SAUTÉ par la boucle ci-dessous, lui ET l'allié qu'il contrôle : l'allié
 * N'AGIT PAS ce round (il attend, jamais un transfert de contrôle à un autre
 * joueur) — jamais un allié qui bloque le tour. Dès que son héros se relève,
 * l'allié reprend son tour normal, juste après lui, au round suivant.
 * → docs/regles/combat-et-tour.md
 */
final class OrdreDuTour
{
    /**
     * L'id du héros dont c'est le tour, ou `null` si plus aucun n'attend (phase
     * des monstres, tous tombés, hors quête, ou c'est le tour d'un ALLIÉ —
     * {@see self::allieActifId()}).
     *
     * @param  Collection<int, EtatPersonnageQuete>|null  $etats  déjà chargés par l'appelant, sinon relus
     */
    public function herosActifId(Groupe $groupe, ?Collection $etats = null): ?int
    {
        $acteur = $this->acteurActif($groupe, $etats);

        return $acteur !== null && $acteur['type'] === 'heros' ? $acteur['personnage_id'] : null;
    }

    public function estSonTour(Groupe $groupe, int $personnageId, ?Collection $etats = null): bool
    {
        return $this->herosActifId($groupe, $etats) === $personnageId;
    }

    /**
     * L'id de l'ALLIÉ (`groupe_mercenaires.id`) dont c'est le tour, ou `null`.
     *
     * @param  Collection<int, EtatPersonnageQuete>|null  $etats
     */
    public function allieActifId(Groupe $groupe, ?Collection $etats = null): ?int
    {
        $acteur = $this->acteurActif($groupe, $etats);

        return $acteur !== null && $acteur['type'] === 'allie' ? $acteur['allie_id'] : null;
    }

    public function estSonTourAllie(Groupe $groupe, int $allieId, ?Collection $etats = null): bool
    {
        return $this->allieActifId($groupe, $etats) === $allieId;
    }

    /**
     * QUI a la main, à l'instant — un héros, ou l'allié qu'il contrôle.
     *
     * Parcourt l'ordre d'initiative FIGÉ (C1) ; pour le premier héros debout
     * ({@see EtatPersonnageQuete::$tombe} faux) : s'il n'a pas encore joué ce
     * round, c'est SON tour ; s'il a déjà joué mais contrôle un allié actif qui
     * n'a pas encore joué, c'est le tour de CET allié ; sinon (pas d'allié, ou
     * allié déjà joué), ce héros est COMPLET pour ce round — on continue vers
     * le suivant. Un seul traversal : héros et allié partagent la même place
     * dans la file, jamais deux files séparées qui pourraient diverger.
     *
     * @param  Collection<int, EtatPersonnageQuete>|null  $etats  déjà chargés par l'appelant, sinon relus
     * @return array{type: 'heros', personnage_id: int, allie_id?: never}|array{type: 'allie', allie_id: int, personnage_id: int}|null
     */
    public function acteurActif(Groupe $groupe, ?Collection $etats = null): ?array
    {
        $etats ??= $groupe->queteCourante?->etatsPersonnages()->get();

        if ($etats === null) {
            return null;
        }

        $ordre = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->pluck('personnages.id');

        foreach ($ordre as $id) {
            $etat = $etats->firstWhere('personnage_id', $id);

            // Héros tombé : sauté, LUI et l'allié qu'il contrôle (voir le
            // docblock de la classe — « jamais un allié qui bloque le tour »).
            if ($etat === null || $etat->tombe) {
                continue;
            }

            if (! $etat->a_joue) {
                return ['type' => 'heros', 'personnage_id' => (int) $id];
            }

            $allie = $this->allieEnAttente($groupe, (int) $id);

            if ($allie !== null) {
                return ['type' => 'allie', 'allie_id' => (int) $allie->id, 'personnage_id' => (int) $id];
            }
        }

        return null;
    }

    /**
     * Le premier allié (ordre d'id, comme les sbires contrôlés) contrôlé par ce
     * héros qui n'a pas encore joué ce round — `null` s'il n'en contrôle
     * aucun, ou si tous ont déjà joué. Un héros qui en contrôle plusieurs
     * (deux mercenaires recrutés) les fait donc jouer un à un, chacun juste
     * après l'autre, toujours avant que l'initiative ne passe au héros suivant.
     */
    public function allieEnAttente(Groupe $groupe, int $heroId): ?GroupeMercenaire
    {
        return GroupeMercenaire::where('groupe_id', $groupe->id)
            ->where('recruteur_personnage_id', $heroId)
            ->where('etat', 'actif')
            ->where('a_joue', false)
            ->whereNotNull('position_x')
            ->orderBy('id')
            ->first();
    }
}
