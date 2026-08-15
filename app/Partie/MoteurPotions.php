<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\LanceurDes;
use App\Models\Condition;
use App\Models\Inventaire;
use App\Models\Personnage;
use Illuminate\Validation\ValidationException;

/**
 * Usage des potions / consommables.
 *
 * CANON HeroQuest : une potion se boit à TOUT MOMENT — y compris hors de son
 * tour, pendant le tour d'un monstre (ex. se soigner juste avant un coup
 * fatal) — et ne coûte PAS d'action. Le moteur applique l'effet de l'objet
 * (soin Body/Mind, antidote, buff chiffré) et consomme l'exemplaire.
 */
class MoteurPotions
{
    public function __construct(
        private readonly MoteurSorts $sorts,
        private readonly LanceurDes $des,
    ) {}

    /**
     * Boit une ligne d'inventaire consommable portée par $personnage.
     *
     * @param  array<string, mixed>  $parametres  choix du joueur — `sort_ids`
     *                                            pour les potions qui rendent
     *                                            un nombre limité de sorts.
     * @return array<string, mixed> résumé moteur (effets appliqués)
     */
    public function boire(Personnage $personnage, Inventaire $ligne, array $parametres = []): array
    {
        $objet = $ligne->objet;

        if ($objet === null || $objet->categorie !== 'consommable') {
            throw ValidationException::withMessages([
                'inventaire_id' => "Cet objet n'est pas une potion / un consommable.",
            ]);
        }
        if ((int) $ligne->personnage_id !== (int) $personnage->id) {
            throw ValidationException::withMessages([
                'inventaire_id' => "Cette potion n'est pas dans votre inventaire.",
            ]);
        }

        // RESTRICTION DE CLASSE — trois potions officielles sont réservées au
        // Barbare et deux à l'Elfe (doc 16 §2.1bis). C'est ici qu'elle est
        // opposable, et nulle part ailleurs : un consommable ne passe jamais
        // par `Equipement::equiper()`, donc rien ne l'aurait contrôlé.
        if (! app(Equipement::class)->estAccessible($personnage, $objet)) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » n'est pas pour un {$personnage->classe}.",
            ]);
        }

        $effet = (array) $objet->effet;

        // L'état de quête sert désormais à quatre effets ; on le charge une
        // fois. La potion se boit hors tour, donc il peut être absent (au hub).
        $etat = $personnage->groupeActif?->queteCourante?->etatsPersonnages()
            ->where('personnage_id', $personnage->id)->first();

        // « you may use only one potion per turn » (Potion de dextérité). ⚠ La
        // garde ne vaut QUE pour les potions marquées `une_par_tour` : brider
        // les quatorze autres inventerait une règle qu'aucune carte ne porte.
        if (! empty($effet['une_par_tour']) && $etat !== null
            && in_array($objet->nom, (array) ($etat->capacites_tour ?? []), true)) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » : une seule par tour.",
            ]);
        }

        $applique = [];

        // Soin Body / Mind — plafonné au maximum du héros.
        // Soin ALÉATOIRE (fiole de fouille) : 1d6 PV, plafonné au maximum.
        if (isset($effet['soin_pv_body_de'])) {
            $avant = (int) $personnage->pv_body;
            $de = $this->des->d6();
            $personnage->pv_body = min((int) $personnage->pv_body_max, $avant + $de);
            $applique['soin_pv_body'] = $personnage->pv_body - $avant;
            $applique['de'] = $de;
        }

        if (isset($effet['soin_pv_body'])) {
            $avant = (int) $personnage->pv_body;
            $personnage->pv_body = min((int) $personnage->pv_body_max, $avant + (int) $effet['soin_pv_body']);
            $applique['soin_pv_body'] = $personnage->pv_body - $avant;
        }
        if (isset($effet['soin_pv_mind'])) {
            $avant = (int) $personnage->pv_mind;
            $personnage->pv_mind = min((int) $personnage->pv_mind_max, $avant + (int) $effet['soin_pv_mind']);
            $applique['soin_pv_mind'] = $personnage->pv_mind - $avant;
        }

        // Potion de restauration supérieure : « restores any hero's Body and
        // Mind Points to the level they were at when the hero started the
        // Quest ». Chez nous c'est littéralement le MAXIMUM — `DemarreurQuete`
        // remet les deux jauges à leur plafond au lancement de chaque quête,
        // donc aucun état de départ n'a besoin d'être mémorisé.
        if (! empty($effet['restaure_jauges_depart'])) {
            $applique['soin_pv_body'] = (int) $personnage->pv_body_max - (int) $personnage->pv_body;
            $applique['soin_pv_mind'] = (int) $personnage->pv_mind_max - (int) $personnage->pv_mind;
            $personnage->pv_body = (int) $personnage->pv_body_max;
            $personnage->pv_mind = (int) $personnage->pv_mind_max;
        }

        $personnage->save();

        // Un soin RELÈVE, comme le sort (décision de René, 2026-08-06).
        //
        // Boire est une action gratuite que rien n'interdit à un héros à terre,
        // mais `tombe` n'était jamais touché ici : le compagnon vidait sa fiole,
        // remontait à 4 PV… et restait couché, alors que le même soin lancé en
        // SORT le remettait debout (`ResolveurTour::sortUtilitaire`). Deux
        // chemins pour un même effet ne doivent pas raconter deux règles.
        $this->releverSiSoigne($personnage);

        // Antidote — retire une condition nommée si présente.
        // Potion d'héroïsme : une ATTAQUE SUPPLÉMENTAIRE ce tour-ci — deux
        // attaques au lieu d'une, et non des dés en plus. Même patron que la
        // Réserve arcanique du magicien (un second sort), sur l'état de tour.
        if (! empty($effet['attaque_supplementaire']) && $etat !== null) {
            $etat->update(['attaque_supplementaire' => true]);
            $applique['attaque_supplementaire'] = true;
        }

        // Parchemin de Sorts : « restores all spells that Hero possessed at the
        // beginning of the quest ». À distinguer du nœud Concentration, qui n'en
        // récupère qu'UN — c'est toute la valeur de la carte.
        //
        // Un ENTIER borne le nombre rendu : Potion de magie (3), Potion de
        // rappel (1, Elfe). Le joueur choisit lesquels via `sort_ids` ; sans
        // choix on prend les premiers épuisés, parce qu'une potion qui ne
        // ferait rien faute de paramètre serait pire qu'un choix arbitraire.
        if (! empty($effet['restaure_sorts'])) {
            $applique['sorts_restaures'] = app(MoteurSorts::class)->restaurerSorts(
                $personnage,
                is_int($effet['restaure_sorts']) ? $effet['restaure_sorts'] : null,
                array_map('intval', (array) ($parametres['sort_ids'] ?? [])),
            );
        }

        if (isset($effet['retire_condition'])) {
            $condition = Condition::query()->where('nom', $effet['retire_condition'])->first();
            if ($condition !== null) {
                $personnage->conditions()->detach($condition->id);
                $applique['retire_condition'] = $effet['retire_condition'];
            }
        }

        // Buff — pour TOUTE potion qui porte une `duree`.
        //
        // La condition testait auparavant les deux clés de bonus chiffré, si
        // bien qu'une potion dont l'effet n'était pas un nombre de dés ne
        // posait aucun buff : la relance de la Potion de bataille, le
        // multiplicateur de la Force glaciale, les cases de la Dextérité, la
        // clairvoyance de la Vision n'auraient eu aucun support pour vivre ni
        // pour expirer. On s'appuie sur la réciproque de l'invariant de
        // `MotsClesEquipement::DUREE` : un effet qui déclare quand il s'arrête
        // est un effet qui dure.
        if (isset($effet['duree'])) {
            $applique['buff'] = $this->sorts->appliquerBuffPotion($personnage, $objet)->nom;
        }

        // Potion de vitesse bue APRÈS avoir entamé son mouvement.
        // `ResolveurTour::pointsDeplacement()` sort immédiatement quand
        // `deplacement_restant` est déjà posé : le multiplicateur n'y serait
        // jamais relu, et la potion disparaîtrait sans rien faire. On l'applique
        // donc au restant, sur-le-champ.
        if (! empty($effet['deplacement_multiplie']) && $etat?->deplacement_restant !== null) {
            $restant = (int) $etat->deplacement_restant * (int) $effet['deplacement_multiplie'];
            $etat->update(['deplacement_restant' => $restant]);
            $applique['deplacement_restant'] = $restant;
        }

        // Marque la potion « une par tour » comme bue. Compteur partagé avec les
        // capacités de carte : c'est le même besoin — une fenêtre d'un tour —
        // et il est remis à zéro au même endroit.
        if (! empty($effet['une_par_tour']) && $etat !== null) {
            $etat->update(['capacites_tour' => array_values(array_unique([
                ...(array) ($etat->capacites_tour ?? []), $objet->nom,
            ]))]);
        }

        // Consommation de l'exemplaire.
        if ((int) $ligne->quantite > 1) {
            $ligne->decrement('quantite');
        } else {
            $ligne->delete();
        }

        $personnage->refresh();

        return [
            'type' => 'potion',
            'objet' => $objet->nom,
            'personnage_id' => $personnage->id,
            'effets' => $applique,
            'pv_body' => (int) $personnage->pv_body,
            'pv_body_max' => (int) $personnage->pv_body_max,
            'pv_mind' => (int) $personnage->pv_mind,
            'pv_mind_max' => (int) $personnage->pv_mind_max,
        ];
    }

    /**
     * Remet debout un héros à terre dont le soin vient de rouvrir une jauge.
     *
     * Même condition que le sort de soin (`ResolveurTour::sortUtilitaire`) : on
     * ne relève que si les PV Body repassent AU-DESSUS de zéro — un antidote ou
     * un soin de Mind ne suffit pas à faire tenir un corps sur ses jambes.
     *
     * Cherche l'état de quête du héros dans la quête COURANTE de son groupe : la
     * potion se boit hors tour, donc on ne peut pas compter sur un état déjà
     * chargé par le résolveur.
     */
    private function releverSiSoigne(Personnage $personnage): void
    {
        if ((int) $personnage->pv_body <= 0) {
            return;
        }

        $queteId = $personnage->groupeActif?->quete_courante_id;

        if ($queteId === null) {
            return; // au hub : personne n'est « tombé »
        }

        $personnage->etatsQuete()
            ->where('quete_id', $queteId)
            ->where('tombe', true)
            ->update(['tombe' => false]);
    }
}
