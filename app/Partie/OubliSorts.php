<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\LanceurDes;
use App\Models\Quete;
use Illuminate\Support\Facades\DB;

/**
 * POINT DE PASSAGE UNIQUE des sorts OUBLIÉS pour la durée d'une quête —
 * *Unlearn* (Wizards of Morcar, *Spells of Protection*, 2026-10-08).
 *
 * Lu par `MoteurSorts::options()` (héros : grisé, jamais lançable) et par
 * `MoteurDread::sortsDisponibles()` (Sorcier : répertoire amputé) ; écrit par
 * `ResolveurTour::oublierSortSort()` seul. Une règle, un point de passage.
 *
 * Mécanisme GÉNÉRIQUE sur la cible : un héros ou un monstre. La carte Dread
 * *Unlearn* du High Mage (vague 2) s'y branchera contre un héros sans nouvelle
 * table.
 */
final class OubliSorts
{
    public const CIBLE_PERSONNAGE = 'personnage';
    public const CIBLE_INSTANCE = 'instance_monstre';

    public const SOURCE_SORT = 'sort';
    public const SOURCE_DREAD = 'sort_dread';

    public function oublier(Quete $quete, string $cibleType, int $cibleId, string $source, string $nom): void
    {
        DB::table('sorts_oublies_de_quete')->insertOrIgnore([
            'quete_id' => $quete->id,
            'cible_type' => $cibleType,
            'cible_id' => $cibleId,
            'source' => $source,
            'nom' => $nom,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Indice UNIFORME dans [0, $n[, tiré au d6 PAR REJET — jamais `random_int()` :
     * la partie doit rester rejouable depuis le lanceur injecté (tests compris).
     * Chaque tirage forme un nombre en base 6 sur assez de chiffres pour couvrir
     * $n ; on rejette tout ce qui dépasse, donc aucun biais de modulo.
     *
     * POINT DE PASSAGE UNIQUE du « au hasard » : l'*Unlearn* des héros
     * (`ResolveurTour::oublierSortSort()`), celui du High Mage et le *Thieving
     * Wind* du Storm Master tirent tous d'ici.
     */
    public function indiceAleatoire(LanceurDes $des, int $n): int
    {
        $chiffres = max(1, (int) ceil(log(max($n, 2), 6)));

        // Borné : un lanceur déterministe qui rejetterait toujours la même face
        // ne doit JAMAIS bloquer la partie. Au-delà de 64 rejets (probabilité
        // négligeable avec de vrais dés), on retient le dernier indice valide.
        for ($essai = 0; $essai < 64; $essai++) {
            $valeur = 0;

            for ($i = 0; $i < $chiffres; $i++) {
                $valeur = $valeur * 6 + ($des->d6() - 1);
            }

            if ($valeur < $n) {
                return $valeur;
            }
        }

        return max(0, $n - 1);
    }

    /**
     * @return list<string> noms des sorts oubliés pour CETTE quête
     */
    public function oublies(Quete $quete, string $cibleType, int $cibleId, string $source): array
    {
        return DB::table('sorts_oublies_de_quete')
            ->where('quete_id', $quete->id)
            ->where('cible_type', $cibleType)
            ->where('cible_id', $cibleId)
            ->where('source', $source)
            ->orderBy('id')
            ->pluck('nom')
            ->map(fn ($nom) => (string) $nom)
            ->all();
    }
}
