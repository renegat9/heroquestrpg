<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Alliés — mercenaires + compagnon animal (Phase 2, 3.5) : recrutement au hub
 * sur la bourse commune, instanciation au démarrage de quête, phase alliée
 * dédiée (hors initiative héros), consommation en fin de quête.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, MercenaireSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

it('expose le catalogue recrutable via GET /mercenaires', function () {
    connecterJoueur('alice');

    $catalogue = $this->getJson('/api/mercenaires')->assertOk()->json('mercenaires');

    expect($catalogue)->toHaveCount(Mercenaire::count());
    $premier = $catalogue[0];
    // Trié par prix croissant + bloc de stats complet.
    expect($premier['prix'])->toBeLessThanOrEqual($catalogue[count($catalogue) - 1]['prix']);
    foreach (['id', 'nom', 'type', 'prix', 'deplacement', 'attaque', 'portee', 'defense', 'pv_body', 'animal', 'description'] as $cle) {
        expect($premier)->toHaveKey($cle);
    }
});

it('expose les alliés recrutés au hub dans EtatGroupe.groupe.mercenaires', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Hallebardier')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $groupe->refresh();
    $mercos = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.mercenaires');

    expect($mercos)->toHaveCount(1)
        ->and($mercos[0]['nom'])->toBe('Hallebardier')
        ->and($mercos[0]['animal'])->toBeFalse()
        ->and($mercos[0])->toHaveKeys(['id', 'mercenaire_id', 'type', 'pv_body', 'pv_body_max']);
});

it('recrute un mercenaire au hub en débitant la bourse commune', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $hallebardier = Mercenaire::where('nom', 'Hallebardier')->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $hallebardier->id])
        ->assertStatus(201)
        ->assertJsonPath('recrue.nom', 'Hallebardier')
        ->assertJsonPath('or', 500 - (int) $hallebardier->prix);

    expect($groupe->fresh()->mercenaires()->count())->toBe(1)
        ->and((int) $groupe->fresh()->or)->toBe(500 - (int) $hallebardier->prix);
});

it('refuse le recrutement hors du hub', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500, 'phase' => 'quete']);

    $merc = Mercenaire::where('nom', 'Hallebardier')->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])
        ->assertStatus(422);

    expect($groupe->fresh()->mercenaires()->count())->toBe(0);
});

it('refuse le recrutement si l\'or est insuffisant', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 10]);

    $merc = Mercenaire::where('nom', 'Hallebardier')->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])
        ->assertStatus(422);

    expect((int) $groupe->fresh()->or)->toBe(10);
});

it('n\'autorise qu\'un seul compagnon animal par groupe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 1000]);

    $loup = Mercenaire::where('animal', true)->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $loup->id])->assertStatus(201);
    // Deuxième animal refusé.
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $loup->id])->assertStatus(422);

    expect($groupe->fresh()->mercenaires()->count())->toBe(1);
});

it('instancie l\'allié au démarrage de quête et l\'expose dans l\'état', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Hallebardier')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $allie = $groupe->fresh()->mercenaires()->first();
    expect($allie->position_x)->not->toBeNull()
        ->and($allie->etat)->toBe('actif');

    // L'allié figure dans les entités de l'état partagé avec le type « allie ».
    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $allieEntite = collect($etat['entites'])->firstWhere('type', 'allie');
    expect($allieEntite)->not->toBeNull()
        ->and($allieEntite['nom'])->toBe('Hallebardier');
});

it('fait jouer l\'allié en phase dédiée : il attaque un monstre adjacent', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Hallebardier')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $allie = $groupe->fresh()->mercenaires()->first();
    $ax = (int) $allie->position_x;
    $ay = (int) $allie->position_y;

    // Un seul monstre, révélé, placé au contact de l'allié.
    $quete->instancesMonstres()->update(['revele' => true]);
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $contact = caseAdjacenteLibre($quete, $ax, $ay);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'revele' => true]);

    // Dés généreux (peu importe les dégâts) : on vérifie que l'allié AGIT.
    desFiges(array_fill(0, 80, 4));

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $actionsAllies = collect($reponse->json('resultat.tour_allies.actions'));
    expect($actionsAllies->isNotEmpty())->toBeTrue()
        ->and($actionsAllies->contains(fn ($a) => ($a['type'] ?? null) === 'attaque_allie'))->toBeTrue();
});

it('restaure le mercenaire payé à la reprise après un TPK', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Hallebardier')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    expect($groupe->fresh()->mercenaires()->count())->toBe(1); // instancié au démarrage

    // TPK déterministe : héros à 1 PV, un monstre TRÈS résistant au contact (il
    // survit à l'allié et tue le héros au tour des monstres).
    $hero->update(['pv_body' => 1]);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    $quete->instancesMonstres()->orderBy('id')->firstOrFail()->update([
        'etat' => 'actif', 'revele' => true, 'pv_body' => 15,
        'position_x' => $contact['x'], 'position_y' => $contact['y'],
    ]);

    desFiges(array_fill(0, 80, 1)); // crânes partout : le monstre touche, rien n'est paré
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Quête échouée, retour au hub, allié PURGÉ à l'échec.
    expect($quete->fresh()->etat)->toBe('echouee')
        ->and($groupe->fresh()->phase)->toBe('hub')
        ->and($groupe->fresh()->mercenaires()->count())->toBe(0);

    // Reprise (snapshot debut_quete) : le mercenaire payé revient.
    $this->postJson('/api/groupes/table-1/reprise')->assertOk();

    $recrues = $groupe->fresh()->mercenaires()->with('mercenaire')->get();
    expect($recrues)->toHaveCount(1)
        ->and($recrues->first()->mercenaire->nom)->toBe('Hallebardier')
        ->and($recrues->first()->etat)->toBe('actif');
});

it('consomme les alliés en fin de quête (victoire)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Hallebardier')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    expect($groupe->fresh()->mercenaires()->count())->toBe(1);

    // Victoire : tous les monstres vaincus, le héros termine son tour.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    desFiges(array_fill(0, 20, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect($groupe->fresh()->phase)->toBe('hub')
        // Alliés consommés en fin de quête.
        ->and($groupe->fresh()->mercenaires()->count())->toBe(0);
});
