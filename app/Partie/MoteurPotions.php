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
    public function __construct(private readonly MoteurSorts $sorts) {}

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

        // Antidote — retire une condition nommée si présente.
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
}
