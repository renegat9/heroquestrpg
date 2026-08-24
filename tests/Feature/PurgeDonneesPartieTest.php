<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Joueur;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * `partie:purger` remet la base à « aucune partie jouée » — et RIEN d'autre.
 *
 * Elle existe à la place d'un drapeau `est_test` en base (René, 2026-08-23) :
 * le harnais joue exprès sur les vraies routes, donc un drapeau posé par le
 * client serait oublié, et un drapeau posé par le serveur exigerait un chemin
 * de code réservé aux tests — ce que « pas de mode démo » interdit ici.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ Bac à sable : la purge d'une campagne efface aussi ses illustrations,
    // et `public_path()` pointe sinon sur le vrai dossier de la machine.
    $GLOBALS['public_purge'] = sys_get_temp_dir().'/hq-purge-'.uniqid();
    mkdir($GLOBALS['public_purge'], 0775, true);
    app()->usePublicPath($GLOBALS['public_purge']);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

afterEach(function () {
    $racine = $GLOBALS['public_purge'] ?? null;

    if ($racine !== null && is_dir($racine)) {
        exec('rm -rf '.escapeshellarg($racine));
    }
});

it('REFUSE de tourner en environnement testing', function () {
    // La base de test ne décrit pas la machine : purger n'y a aucun sens, et
    // laisser la porte ouverte est ce qui a coûté 148 images le 2026-08-22.
    $this->artisan('partie:purger', ['--supprimer' => true])->assertExitCode(1);
});

it('sans --supprimer, elle inventorie et ne touche à rien', function () {
    demarrerQueteAvecMonstre('Gobelin');
    app()['env'] = 'local'; // la commande refuse `testing` — voir son garde-fou

    $this->artisan('partie:purger')->assertSuccessful();

    expect(Groupe::count())->toBe(1)
        ->and(Quete::count())->toBe(1)
        ->and(Personnage::count())->toBeGreaterThan(0);
});

it('purge campagnes, quêtes et héros — en gardant comptes, catalogues et réglages', function () {
    demarrerQueteAvecMonstre('Gobelin');
    app()['env'] = 'local';

    $monstres = Monstre::count();
    $objets = Objet::count();

    $this->artisan('partie:purger', ['--supprimer' => true])->assertSuccessful();

    expect(Groupe::count())->toBe(0)
        ->and(Quete::count())->toBe(0)
        ->and(Personnage::count())->toBe(0)
        // Les dépendants partent en cascade (déclarée dans les migrations).
        ->and(DB::table('inventaire')->count())->toBe(0)
        ->and(DB::table('personnage_sorts')->count())->toBe(0)
        // ⚠ Sans --tout, le compte survit : c'est un identifiant de connexion.
        ->and(Joueur::count())->toBeGreaterThan(0)
        // Les catalogues sont des données de RÉFÉRENCE, jamais du test.
        ->and(Monstre::count())->toBe($monstres)
        ->and(Objet::count())->toBe($objets);
});

it('--tout emporte en plus les comptes et la télémétrie', function () {
    demarrerQueteAvecMonstre('Gobelin');
    app()['env'] = 'local';
    DB::table('consommation_ia')->insert([
        'fournisseur' => 'anthropic', 'modele' => 'claude-sonnet-4-6', 'skill' => 'habiller_monstres',
        'tokens_entree' => 1, 'tokens_sortie' => 1, 'tokens_cache' => 0, 'tentative' => 1,
        'created_at' => now(),
    ]);

    $this->artisan('partie:purger', ['--supprimer' => true, '--tout' => true])->assertSuccessful();

    expect(Joueur::count())->toBe(0)
        ->and(DB::table('consommation_ia')->count())->toBe(0)
        ->and(Monstre::count())->toBeGreaterThan(0); // les catalogues, toujours
});
