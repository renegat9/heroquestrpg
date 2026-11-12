<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Models\Groupe;
use App\Models\Mercenaire;
use App\Models\Monstre;
use App\Models\Personnage;
use Illuminate\Support\Facades\DB;

/**
 * MODE ARÈNE, lot T3 — la PUISSANCE D'ÉQUIPE (livret F9528 p. 15-16, `reference/18_extensions.md` §Mode tournoi).
 *
 * Point de passage UNIQUE de la question « combien vaut cette figure, cette équipe ? », lu par le salon (publication,
 * monstres abordables), par la composition du Defender et par le lancement.
 *
 * - Un HÉROS vaut **1 + les dés de sa meilleure attaque** : « Each hero begins with a power of 1, then adds the Attack
 *   dice of their strongest attack » — son attaque d'arme (`des_attaque`) ou celle d'un de ses sorts (`des_degats`) :
 *   l'exemple du livret compte le sort du Génie pour 6 (5 dés + 1).
 * - Un MONSTRE vaut **ses dés d'attaque** : la colonne `Total` du roster (p. 16) vaut `#` × `Attack Dice`, et l'équipe
 *   d'exemple totalise 15 (3 gobelins à 2, 2 gobelins archers à 2, un Ogre guerrier à 5).
 * - Le Defender doit valoir **au plus** le Challenger : « must be less than or equal to the Challenger team's power ».
 */
final class PuissanceArene
{
    public function duHeros(Personnage $heros): int
    {
        $meilleurSort = (int) DB::table('personnage_sorts')
            ->join('sorts', 'sorts.id', '=', 'personnage_sorts.sort_id')
            ->where('personnage_sorts.personnage_id', $heros->id)
            ->pluck('sorts.effet')
            ->map(fn ($effet) => (int) ((json_decode((string) $effet, true) ?: [])['des_degats'] ?? 0))
            ->max();

        return 1 + max((int) $heros->des_attaque, $meilleurSort);
    }

    public function duMonstre(Monstre $monstre): int
    {
        return (int) $monstre->attaque;
    }

    /**
     * Un ALLIÉ (lot A4) vaut comme un héros : 1 + les dés de sa meilleure attaque, au contact ou à distance
     * (`docs/plan-mode-arene.md` §2 : « 1 + ses dés d'attaque, même règle qu'un héros »).
     */
    public function duMercenaire(Mercenaire $mercenaire): int
    {
        return 1 + max((int) $mercenaire->attaque, (int) ($mercenaire->attaque_distance ?? 0));
    }

    /** Le Challenger : la somme de ses combattants (les doublures engagées) et de leurs alliés. */
    public function duChallenger(Groupe $arene): int
    {
        $combattants = $arene->personnages()->wherePivot('actif', true)->get();
        $allies = Mercenaire::whereIn('id', $combattants->pluck('pivot.allie_arene_id')->filter()->unique())->get()->keyBy('id');

        return (int) $combattants->sum(fn (Personnage $p) => $this->duHeros($p)
            + ($allies->has($p->pivot->allie_arene_id) ? $this->duMercenaire($allies->get($p->pivot->allie_arene_id)) : 0));
    }

    /**
     * Le Defender composé, ou une liste d'ids du catalogue.
     *
     * @param  list<int>|null  $monstreIds
     */
    public function duDefender(Groupe $arene, ?array $monstreIds = null): int
    {
        $ids = array_map('intval', $monstreIds ?? ($arene->arene_defender ?? []));
        $attaques = Monstre::whereIn('id', array_unique($ids))->get()->keyBy('id');

        return array_sum(array_map(fn (int $id) => $attaques->has($id) ? $this->duMonstre($attaques->get($id)) : 0, $ids));
    }
}
