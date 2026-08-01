<?php

declare(strict_types=1);

use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'attaque vient de l'ARME ÉQUIPÉE (doc 03 §8), plus de la classe.
 *
 * Les 3/2/2/1 dés d'attaque du doc 01 §4 n'étaient pas une force innée : ce
 * sont les armes de départ du plateau (épée large, épée courte, épée courte,
 * dague). Comme le code AJOUTAIT l'arme achetée à cette valeur, un barbare
 * avec une épée large arrivait à 6 dés et le marché n'était plus qu'une
 * inflation.
 *
 * Cette migration rattrape les héros DÉJÀ CRÉÉS : elle leur donne l'arme de
 * départ de leur classe s'ils n'en portent aucune, puis recale `des_attaque`
 * sur l'arme portée. La puissance de départ reste rigoureusement identique
 * (barbare 1 + épée large → 3) : seule la suite change.
 */
return new class extends Migration
{
    private const ARME_DEPART = [
        'barbare' => 'Épée large',
        'nain' => 'Épée courte',
        'elfe' => 'Épée courte',
        'magicien' => 'Dague',
        'magicienne' => 'Dague',
    ];

    public function up(): void
    {
        if (Objet::query()->count() === 0) {
            return; // catalogue non semé (base neuve) : rien à rattraper
        }

        foreach (Personnage::all() as $personnage) {
            $arme = Inventaire::where('personnage_id', $personnage->id)
                ->where('emplacement', 'arme_principale')
                ->with('objet')
                ->first();

            if ($arme === null) {
                $objet = Objet::where('nom', self::ARME_DEPART[$personnage->classe] ?? null)->first();

                if ($objet !== null) {
                    $arme = Inventaire::create([
                        'personnage_id' => $personnage->id,
                        'objet_id' => $objet->id,
                        'emplacement' => 'arme_principale',
                    ]);
                    $arme->setRelation('objet', $objet);
                }
            }

            $attaque = (int) ($arme?->objet?->effet['des_attaque'] ?? 1);

            foreach ((array) ($arme?->ameliorations ?? []) as $amelioration) {
                $attaque += (int) ($amelioration['effet']['bonus_des_attaque'] ?? 0);
            }

            DB::table('personnages')->where('id', $personnage->id)->update(['des_attaque' => max(0, $attaque)]);
        }
    }

    public function down(): void
    {
        // Irréversible sans ambiguïté (on ne sait plus distinguer l'arme de
        // départ d'une arme achetée) : on ne tente pas de rétablir les
        // anciennes valeurs de classe.
    }
};
