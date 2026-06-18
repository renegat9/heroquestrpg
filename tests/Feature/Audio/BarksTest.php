<?php

declare(strict_types=1);

use App\Events\BarkDiffuse;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\Audio\BanqueBarks;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
});

// --- BanqueBarks (sélection déterministe, sans DB) --------------------------

it('mappe les archétypes vers leur profil de voix, repli « defaut »', function () {
    $banque = new BanqueBarks;

    expect($banque->profil('Gobelin'))->toBe('gobelin')
        ->and($banque->profil('Seigneur'))->toBe('boss')
        ->and($banque->profil('Momie'))->toBe('mort_vivant')
        ->and($banque->profil('Bestiole Inconnue'))->toBe('defaut');
});

it('rend des lignes par profil et retombe sur « defaut » pour un profil absent', function () {
    $banque = new BanqueBarks;

    expect($banque->lignes('gobelin', 'attaque'))->not->toBeEmpty()
        ->and($banque->lignes('boss', 'mort'))->not->toBeEmpty()
        // profil inexistant → lignes du profil defaut (jamais vide)
        ->and($banque->lignes('profil_bidon', 'rate'))->toBe($banque->lignes('defaut', 'rate'));
});

it('produit un bark texte pour une instance, sans URL tant qu\'aucun audio n\'est généré', function () {
    $banque = new BanqueBarks;

    $instance = new InstanceMonstre(['quete_id' => 0]);
    $instance->setRelation('monstre', new Monstre(['nom_base' => 'Gobelin']));

    $bark = $banque->pourInstance($instance, 'touche');

    expect($bark)->not->toBeNull()
        ->and($bark['profil'])->toBe('gobelin')
        ->and($bark['evenement'])->toBe('touche')
        ->and($bark['nom'])->toBe('Gobelin')
        ->and($bark['texte'])->toBeIn($banque->lignes('gobelin', 'touche'))
        ->and($bark['url'])->toBeNull(); // aucun asset présent → repli Web Speech côté front
});

// --- Commande de génération sans clé ---------------------------------------

it('la commande barks:generer sans GEMINI_API_KEY ne génère rien et l\'explique', function () {
    config(['services.gemini.api_key' => null]);

    $this->artisan('barks:generer')
        ->expectsOutputToContain('Web Speech')
        ->assertExitCode(0);
});

// --- Wiring combat : un monstre vaincu diffuse un bark « mort » -------------

it('diffuse un bark « mort » sur le canal de groupe quand un héros tue un monstre', function () {
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);

    Event::fake([BarkDiffuse::class]);
    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$proie->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.cible_vaincue', true);

    Event::assertDispatched(BarkDiffuse::class, fn (BarkDiffuse $e) =>
        $e->evenement === 'mort' && $e->groupe->id === $groupe->id);
});
