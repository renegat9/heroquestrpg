<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Migration 2026_10_08_110000 : « Unlearn » devient « Désapprentissage » par
 * RENOMMAGE de la donnée (même `id`, mêmes pivots), jamais par une seconde ligne.
 * Additive, rejouable, et qui refuse de fusionner deux lignes qui existent déjà.
 */

/** La migration, chargée comme le fait `migrate` (classe anonyme). */
function migrationRenommageUnlearn(): object
{
    return require database_path('migrations/2026_10_08_110000_renommer_unlearn_en_desapprentissage.php');
}

/** Une ligne de sort de héros, comme `SortSeeder` les écrit. */
function sortProtectionBrut(string $nom): void
{
    DB::table('sorts')->insert([
        'element' => 'protection',
        'nom' => $nom,
        'type' => 'utilitaire',
        'difficulte_parchemin' => 2,
        'effet' => json_encode(['cible' => 'lanceur_dread', 'oublie_sort' => true]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('renomme la ligne en place (même id), sans seconde ligne, et se rejoue sans rien casser', function () {
    sortProtectionBrut('Unlearn');
    $id = (int) DB::table('sorts')->where('nom', 'Unlearn')->value('id');

    migrationRenommageUnlearn()->up();

    expect(DB::table('sorts')->where('nom', 'Unlearn')->exists())->toBeFalse()
        ->and((int) DB::table('sorts')->where('nom', 'Désapprentissage')->value('id'))->toBe($id)
        ->and(DB::table('sorts')->count())->toBe(1);

    // Rejouée (base déjà renommée) : rien à faire, aucune erreur, aucune copie.
    migrationRenommageUnlearn()->up();

    expect(DB::table('sorts')->where('nom', 'Désapprentissage')->count())->toBe(1)
        ->and(DB::table('sorts')->count())->toBe(1);
});

it('sans ligne « Unlearn » (base neuve), ne fait rien', function () {
    migrationRenommageUnlearn()->up();

    expect(DB::table('sorts')->count())->toBe(0);
});

it('REFUSE de fusionner : si les deux noms existent, elle lève une erreur et ne touche à rien', function () {
    sortProtectionBrut('Unlearn');
    sortProtectionBrut('Désapprentissage');

    expect(fn () => migrationRenommageUnlearn()->up())->toThrow(RuntimeException::class);

    expect(DB::table('sorts')->where('nom', 'Unlearn')->exists())->toBeTrue()
        ->and(DB::table('sorts')->where('nom', 'Désapprentissage')->exists())->toBeTrue()
        ->and(DB::table('sorts')->count())->toBe(2);
});

it('suit le renommage dans les oublis de quête déjà écrits pour un sort de héros', function () {
    $this->seed([\Database\Seeders\GabaritQueteSeeder::class]);
    $groupe = creerGroupe();
    rendreGardien($groupe, 1);
    $queteId = (int) \App\Models\Quete::where('groupe_id', $groupe->id)->value('id');

    sortProtectionBrut('Unlearn');
    DB::table('sorts_oublies_de_quete')->insert([
        'quete_id' => $queteId, 'cible_type' => 'personnage', 'cible_id' => 1,
        'source' => 'sort', 'nom' => 'Unlearn', 'created_at' => now(), 'updated_at' => now(),
    ]);

    migrationRenommageUnlearn()->up();

    expect(DB::table('sorts_oublies_de_quete')->where('nom', 'Unlearn')->exists())->toBeFalse()
        ->and(DB::table('sorts_oublies_de_quete')->where('nom', 'Désapprentissage')->count())->toBe(1);
});
