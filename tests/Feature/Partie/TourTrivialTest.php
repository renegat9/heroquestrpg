<?php

declare(strict_types=1);

use App\Events\NarrationDiffusee;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use App\Partie\MenuMoteur;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Bascule 2026-08-18 (« l'IA fabrique la quête, elle ne la joue plus ») :
 * plus d'appel LLM en cours de partie, ni pour la narration (résolue
 * SYNCHRONEMENT désormais — pack de quête ou repli scripté) ni pour le menu
 * (toujours celui du moteur, l'ancien enrichissement IA — skill MenuChoix,
 * `GenererMenu::fusionner()` — a disparu avec le paramètre `enrichir`).
 *
 * Ce fichier vérifiait l'ancienne distinction « trivial (moteur seul) vs
 * notable (IA) ». Ce qui reste à garantir, une fois l'IA totalement retirée
 * du runtime : un déplacement ET un combat restent tous deux des tours
 * INSTANTANÉS sans narration (§ ChoixController) ; et le menu publié est
 * TOUJOURS, mot pour mot, celui du moteur — plus aucun mécanisme ne peut
 * plus ni l'enrichir ni l'amputer.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('un déplacement ne déclenche aucune narration et publie le menu moteur', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $dest = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);

    Event::fake([NarrationDiffusee::class]);
    Queue::fake();
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $dest])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'deplacement');

    Event::assertNotDispatched(NarrationDiffusee::class);
    Queue::assertPushed(GenererMenu::class);
});

it('une attaque qui ne termine PAS le combat (le monstre survit) reste instantanée, sans narration', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Un monstre robuste au contact (PV élevés — il encaisse le coup sans
    // tomber) : `etat` reste `actif`, donc $enCombat (ChoixController) reste
    // vrai APRÈS l'attaque aussi, quel que soit le résultat du jet.
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 50, 'revele' => true]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Event::fake([NarrationDiffusee::class]);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $proie->id]])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'attaque');

    expect($proie->fresh()->etat)->toBe('actif'); // le combat continue…
    Event::assertNotDispatched(NarrationDiffusee::class); // … donc pas de narration.
});

it('le coup qui ACHÈVE le dernier monstre révélé rouvre la narration (le combat vient de finir)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Dernier monstre actif, à 1 PV : le coup qui l'achève fait tomber
    // $enCombat à false (plus aucun monstre actif+révélé) — l'action, bien
    // que de type `attaque`, redevient donc un temps fort narré.
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1, 'revele' => true]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    Event::fake([NarrationDiffusee::class]);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $proie->id]])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'attaque');

    expect($proie->fresh()->etat)->toBe('vaincu');
    // Résolue SYNCHRONEMENT (pack de quête ou repli scripté) — plus de LLM.
    Event::assertDispatched(NarrationDiffusee::class);
});

it('le menu publié est exactement celui du moteur, jamais enrichi ni amputé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    Quete::findOrFail($groupe->fresh()->quete_courante_id);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $idsAttendus = collect(app(MenuMoteur::class)->generer($groupe->fresh(), $hero->fresh())['options'])
        ->pluck('id')->all();

    expect(collect($menu['options'])->pluck('id')->all())->toBe($idsAttendus);
});
