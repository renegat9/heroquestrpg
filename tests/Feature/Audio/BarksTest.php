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

it('produit un bark texte pour une instance, sans URL quand aucun audio n\'existe', function () {
    // Profil synthétique sans asset sur disque → la résolution doit retomber sur
    // le texte (url null), indépendamment de la banque réellement générée.
    config([
        'barks.archetypes' => ['Bestiole Test' => 'profil_sans_audio'],
        'barks.lignes.profil_sans_audio.touche' => ['Ouille de test !'],
    ]);

    $banque = new BanqueBarks;

    $instance = new InstanceMonstre(['quete_id' => 0]);
    $instance->setRelation('monstre', new Monstre(['nom_base' => 'Bestiole Test']));

    $bark = $banque->pourInstance($instance, 'touche');

    expect($bark)->not->toBeNull()
        ->and($bark['profil'])->toBe('profil_sans_audio')
        ->and($bark['evenement'])->toBe('touche')
        ->and($bark['nom'])->toBe('Bestiole Test')
        ->and($bark['texte'])->toBe('Ouille de test !')
        ->and($bark['url'])->toBeNull(); // aucun asset → repli Web Speech côté front
});

it('renvoie l\'URL de l\'asset quand le fichier audio existe', function () {
    // Crée un asset factice pour un profil/événement isolé, puis nettoie.
    $rel = 'profil_asset_test/touche';
    $dir = public_path("audio/barks/{$rel}");
    @mkdir($dir, 0775, true);
    file_put_contents("{$dir}/0.wav", 'RIFF');

    config([
        'barks.archetypes' => ['Bestiole Asset' => 'profil_asset_test'],
        'barks.lignes.profil_asset_test.touche' => ['Avec audio !'],
    ]);

    $instance = new InstanceMonstre(['quete_id' => 0]);
    $instance->setRelation('monstre', new Monstre(['nom_base' => 'Bestiole Asset']));

    $bark = (new BanqueBarks)->pourInstance($instance, 'touche');

    expect($bark['url'])->toBe('/audio/barks/profil_asset_test/touche/0.wav');

    @unlink("{$dir}/0.wav");
    @rmdir($dir);
    @rmdir(public_path('audio/barks/profil_asset_test'));
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
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1, 'revele' => true]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);

    Event::fake([BarkDiffuse::class]);
    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$proie->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.cible_vaincue', true);

    Event::assertDispatched(BarkDiffuse::class, fn (BarkDiffuse $e) =>
        $e->evenement === 'mort' && $e->groupe->id === $groupe->id);
});
