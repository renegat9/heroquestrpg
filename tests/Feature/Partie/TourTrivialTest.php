<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Jobs\GenererNarration;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Accélération : l'IA (narration + enrichissement du menu) ne se déclenche QUE
 * sur une action notable. Un simple déplacement reste 100 % moteur (instantané).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('un déplacement ne déclenche PAS la narration et propose un menu moteur (sans enrichissement IA)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $dest = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);

    Queue::fake();
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $dest])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'deplacement');

    Queue::assertNotPushed(GenererNarration::class);                 // pas d'IA narrateur
    Queue::assertPushed(GenererMenu::class, fn (GenererMenu $j) => $j->enrichir === false); // menu moteur instantané
});

it('une attaque déclenche la narration et un menu enrichi par l\'IA', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Un monstre affaibli au contact pour rendre l'option d'attaque légale.
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    Queue::fake();
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$proie->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'attaque');

    Queue::assertPushed(GenererNarration::class);                                  // IA narrateur
    Queue::assertPushed(GenererMenu::class, fn (GenererMenu $j) => $j->enrichir === true); // menu enrichi
});
