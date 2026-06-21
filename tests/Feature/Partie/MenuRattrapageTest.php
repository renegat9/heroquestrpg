<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Rattrapage du menu (GET /menu) : à la reconnexion, la manette récupère le
 * menu courant — régénéré instantanément si c'est le tour du héros et qu'il
 * n'est plus en cache (sinon on resterait bloqué sur « en attente »).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('régénère le menu absent quand c\'est le tour du héros (reconnexion solo)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    // Simule un menu consommé / expiré.
    Cache::forget(GenererMenu::cleMenu($groupe->id, (int) $alice->id));

    $r = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json();

    expect($r['menu'])->not->toBeNull()
        ->and($r['menu']['options'])->not->toBeEmpty()
        ->and($r['personnage_id'])->toBe($hero->id);
});

it('renvoie le menu déjà en cache sans le régénérer', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated(); // menu mis en cache au démarrage

    $r = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json();
    expect($r['menu'])->not->toBeNull();
});

it('ne renvoie pas de menu si le héros a déjà joué (pas son tour)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    Cache::forget(GenererMenu::cleMenu($groupe->id, (int) $alice->id));
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)
        ->update(['a_joue' => true]);

    $r = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json();
    expect($r['menu'])->toBeNull();
});
