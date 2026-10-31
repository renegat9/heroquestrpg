<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les AJUSTEMENTS du jeu de base (René, 2026-10-31 : « Apporte les ajustements nécessaires » sur les points restés ouverts
 * après le retrait de nos créations, `docs/regles/bestiaire-et-rencontres.md`). Quatre lignes de CATALOGUE existantes, un
 * pool de gabarit, et rien de partie :
 *
 *  - **Grak** : `sorts_uniques` nu. « Fire, sleep, tempest » une fois chacun (règle de base, LR p. 20-21), trois lancers au
 *    plus, un par tour. L'ancien budget de palier (2) ne disait pas quel sort était épuisé.
 *  - **Le Seigneur Sorcier de Barak Tor** : `sorts_uniques` à compteur — `['Frayeur' => 2]` (« fear (which he can cast
 *    twice) », F2847 LQ p. 31). Budget 6. Le choix de forme est écrit dans `docs/regles/sorts-dread.md`.
 *  - **Archimage elfe** : `cout` 17 → 12, remesuré sur les repères restants de la droite des boss (`MonstreSeeder`).
 *  - **Garde du Dread** : `boites_partagees` = `first_light` (First Light, quête 4, PDF 11 — même bloc de stats, relu sur le rendu).
 *  - Gabarit **« Antre du sous-boss »** : Abraxus entre dans le pool de sous-boss (First Light, quête 9, PDF 18). La ligne
 *    d'Abraxus elle-même est une INSERTION : elle vient de `MonstreSeeder` (`db:seed --class=MonstreSeeder`, upsert).
 *
 * ⚠ Le budget d'une instance est armé à son PLACEMENT (`MoteurDread::reinitialiserUsagesInstance()`) : une instance
 * vivante de Grak ou de Barak Tor garderait son ancien `usages_dread` jusqu'à sa prochaine pose, et ses sorts déjà
 * lancés ne sont pas dans `sorts_dread_lances`. À vérifier sur la vraie base AVANT de jouer cette migration (lecture
 * seule), comme pour Nelath (`2026_10_29_120000_nelath_chaque_sort_une_fois`).
 */
return new class extends Migration
{
    private const GABARIT_SOUS_BOSS = 'Antre du sous-boss';

    public function up(): void
    {
        DB::table('monstres')->where('nom_base', 'Grak')
            ->update(['capacites' => json_encode(['sorts_uniques'])]);

        DB::table('monstres')->where('nom_base', 'Seigneur Sorcier de Barak Tor')
            ->update(['capacites' => json_encode(['sorts_uniques' => ['Frayeur' => 2]])]);

        DB::table('monstres')->where('nom_base', 'Archimage elfe')
            ->update(['cout' => 12]);

        DB::table('monstres')->where('nom_base', 'Garde du Dread')
            ->update(['boites_partagees' => json_encode(['first_light'])]);

        $this->editerCreatures(
            fn (array $creatures) => in_array('Abraxus', $creatures, true) ? $creatures : [...$creatures, 'Abraxus'],
        );
    }

    public function down(): void
    {
        DB::table('monstres')->where('nom_base', 'Grak')
            ->update(['capacites' => json_encode([])]);

        DB::table('monstres')->where('nom_base', 'Seigneur Sorcier de Barak Tor')
            ->update(['capacites' => json_encode([])]);

        DB::table('monstres')->where('nom_base', 'Archimage elfe')
            ->update(['cout' => 17]);

        DB::table('monstres')->where('nom_base', 'Garde du Dread')
            ->update(['boites_partagees' => null]);

        $this->editerCreatures(
            fn (array $creatures) => array_values(array_filter($creatures, fn (string $nom) => $nom !== 'Abraxus')),
        );
    }

    /**
     * La liste nommée `rencontre_finale.creatures` du gabarit « Antre du sous-boss », réécrite par `$transformer`.
     * Un gabarit absent (base neuve, avant `db:seed`) n'a rien à corriger : ce n'est pas une erreur.
     *
     * @param  callable(list<string>): list<string>  $transformer
     */
    private function editerCreatures(callable $transformer): void
    {
        $gabarit = DB::table('gabarits_quete')->where('nom', self::GABARIT_SOUS_BOSS)->first();

        if ($gabarit === null) {
            return;
        }

        $structure = json_decode((string) $gabarit->structure, true, 512, JSON_THROW_ON_ERROR);
        $creatures = (array) ($structure['rencontre_finale']['creatures'] ?? []);

        $structure['rencontre_finale']['creatures'] = array_values($transformer($creatures));

        DB::table('gabarits_quete')->where('id', $gabarit->id)
            ->update(['structure' => json_encode($structure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
};
