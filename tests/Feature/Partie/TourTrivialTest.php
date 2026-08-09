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
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1, 'revele' => true]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    Queue::fake();
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $proie->id]])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'attaque');

    Queue::assertPushed(GenererNarration::class);                                  // IA narrateur
    Queue::assertPushed(GenererMenu::class, fn (GenererMenu $j) => $j->enrichir === true); // menu enrichi
});

it('ne laisse l\'IA ni inventer une action, ni masquer une option du moteur', function () {
    // L'IA proposait ses options avec l'id de son choix ; il n'était renommé
    // qu'en cas de collision avec une option RÉELLEMENT émise ce tour-ci. Elle
    // pouvait donc porter un id mécanique que le moteur venait de retirer
    // (« fouiller_tresor » sur une salle déjà fouillée) : l'option passait le
    // contrôle de légalité et n'échouait qu'au fond du résolveur.
    $job = new ReflectionClass(GenererMenu::class);
    $fusionner = $job->getMethod('fusionner');
    $fusionner->setAccessible(true);

    $instance = $job->newInstanceWithoutConstructor();

    $moteur = ['options' => [
        ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'],
        // `fouiller` est émis par le moteur en type `jet` : non protégé par
        // l'étape 1, il ne parvient au joueur que si l'IA le recopie.
        ['id' => 'fouiller', 'libelle' => 'Fouiller la zone', 'type' => 'jet'],
    ]];
    $ia = ['options' => [
        ['id' => 'fouiller', 'libelle' => 'Scruter les recoins', 'type' => 'jet'],
        ['id' => 'fouiller_tresor', 'libelle' => 'Fouiller le coffre', 'type' => 'action'],
        ['id' => 'ouvrir_porte_3_4_e', 'libelle' => 'Forcer la porte', 'type' => 'action'],
    ]];

    $fusion = $fusionner->invoke($instance, $moteur, $ia, true);
    $ids = array_column($fusion['options'], 'id');

    // Les options de couleur de l'IA sont conservées, mais SOUS UN ID NEUTRE.
    // TOUTES les options du moteur passent, quel que soit leur type — `fouiller`
    // est un `jet`, et ne survivait auparavant que si l'IA le recopiait.
    expect($ids)->toContain('se_deplacer')
        ->and($ids)->toContain('fouiller')
        // Rien de ce que l'IA a inventé n'entre au menu.
        ->and($ids)->not->toContain('fouiller_tresor')
        ->and($ids)->not->toContain('ouvrir_porte_3_4_e')
        ->and(collect($ids)->filter(fn ($i) => str_starts_with($i, 'ia_'))->count())->toBe(0);

    // …mais l'habillage IA est bien emprunté sur l'option du moteur.
    expect(collect($fusion['options'])->firstWhere('id', 'fouiller')['libelle'])
        ->toBe('Scruter les recoins');
});
