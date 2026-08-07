<?php

declare(strict_types=1);

namespace App\Partie;

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
        private readonly \App\Engine\Des\LanceurDes $des,
    ) {}

    /**
     * Boit une ligne d'inventaire consommable portée par $personnage.
     *
     * @return array<string, mixed> résumé moteur (effets appliqués)
     */
    public function boire(Personnage $personnage, Inventaire $ligne): array
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

        $effet = (array) $objet->effet;
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
        if (! empty($effet['attaque_supplementaire'])) {
            $etat = $personnage->groupeActif?->queteCourante?->etatsPersonnages()
                ->where('personnage_id', $personnage->id)->first();

            if ($etat !== null) {
                $etat->update(['attaque_supplementaire' => true]);
                $applique['attaque_supplementaire'] = true;
            }
        }

        if (isset($effet['retire_condition'])) {
            $condition = Condition::query()->where('nom', $effet['retire_condition'])->first();
            if ($condition !== null) {
                $personnage->conditions()->detach($condition->id);
                $applique['retire_condition'] = $effet['retire_condition'];
            }
        }

        // Buff chiffré (Potion de rage : bonus_des_attaque) — via le système de
        // buffs des conditions (consommé à la prochaine attaque comme Courage).
        if (isset($effet['bonus_des_attaque']) || isset($effet['bonus_des_defense'])) {
            $applique['buff'] = $this->sorts->appliquerBuffPotion($personnage, $objet)->nom;
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
