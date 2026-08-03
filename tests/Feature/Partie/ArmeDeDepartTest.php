<?php

declare(strict_types=1);

use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\Equipement;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\Http;

/**
 * L'attaque vient de l'ARME ÉQUIPÉE (doc 03 §8), comme au plateau — elle ne
 * s'AJOUTE plus à une valeur de classe qui encodait déjà l'arme de départ.
 *
 * Les 3/2/2/1 dés du doc 01 §4 restent les valeurs de départ, mais produites
 * par l'équipement initial : la puissance d'ouverture est inchangée, alors que
 * l'équipement acheté cesse d'être une inflation pure (un barbare avec une
 * épée large montait à 6 dés).
 */
beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, SortSeeder::class]);
});

/** Crée un héros par le VRAI parcours (POST /api/personnages). */
function creerHerosParApi(string $nom, string $classe): Personnage
{
    $charge = ['nom' => $nom, 'classe' => $classe];

    if ($classe === 'elfe') {
        $charge['elements'] = ['air'];
    }
    if (in_array($classe, ['magicien', 'magicienne'], true)) {
        $charge['elements'] = ['feu', 'eau', 'terre'];
    }

    test()->postJson('/api/personnages', $charge)->assertCreated();

    return Personnage::where('nom', $nom)->firstOrFail();
}

it('donne à chaque classe son arme de départ et la valeur d\'attaque du plateau', function (string $classe, string $arme, int $attaque) {
    connecterJoueur('alice');
    $hero = creerHerosParApi('Test', $classe);

    $porte = $hero->fresh()->inventaire()->where('emplacement', 'arme_principale')->with('objet')->first();

    expect($porte?->objet?->nom)->toBe($arme)
        ->and((int) $hero->fresh()->des_attaque)->toBe($attaque)
        // Défense : 2 pour tous, sans armure (confirmé par René).
        ->and((int) $hero->fresh()->des_defense)->toBe(2);
})->with([
    ['barbare', 'Épée large', 3],
    ['nain', 'Hachette', 2],   // arme de départ du nain au plateau
    ['elfe', 'Épée courte', 2],
    ['magicien', 'Dague', 1],
]);

it('donne au Nain sa trousse à outils, pour que le désamorçage serve dès la 1re quête', function () {
    connecterJoueur('alice');
    $hero = creerHerosParApi('Thora', 'nain');

    $noms = $hero->fresh()->inventaire()->with('objet')->get()->pluck('objet.nom');

    expect($noms)->toContain('Trousse à outils');
});

it('REMPLACE l\'attaque quand on change d\'arme, au lieu de l\'additionner', function () {
    connecterJoueur('alice');
    $hero = creerHerosParApi('Bram', 'barbare');

    expect((int) $hero->fresh()->des_attaque)->toBe(3); // épée large de départ

    // Une hache de bataille (4 dés) doit donner 4, pas 3 + 4.
    $hache = Objet::where('nom', 'Hache de bataille')->firstOrFail();
    $ligne = $hero->inventaire()->create(['objet_id' => $hache->id, 'emplacement' => 'sac']);

    // Maîtrise lourde requise par la hache : on la contourne en équipant une
    // arme légère pour vérifier la sémantique de remplacement.
    $dague = Objet::where('nom', 'Dague')->firstOrFail();
    $ligneDague = $hero->inventaire()->create(['objet_id' => $dague->id, 'emplacement' => 'sac']);

    app(Equipement::class)->equiper($hero->fresh(), $ligneDague);

    // Dague = 1 dé. Si l'ancien cumul persistait, on obtiendrait 4.
    expect((int) $hero->fresh()->des_attaque)->toBe(1);

    expect($ligne->fresh()->emplacement)->toBe('sac'); // la hache est restée au sac
});

it('retombe à 1 dé — à mains nues — quand le héros déséquipe son arme', function () {
    connecterJoueur('alice');
    $hero = creerHerosParApi('Bram', 'barbare');

    $arme = $hero->fresh()->inventaire()->where('emplacement', 'arme_principale')->firstOrFail();
    app(Equipement::class)->desequiper($hero->fresh(), $arme);

    expect((int) $hero->fresh()->des_attaque)->toBe(1)
        ->and((int) $hero->fresh()->des_defense)->toBe(2);
});

it('cumule l\'armure sur la défense, sans toucher à l\'attaque', function () {
    connecterJoueur('alice');
    $hero = creerHerosParApi('Bram', 'barbare');

    $casque = Objet::where('nom', 'Casque')->firstOrFail();
    $ligne = $hero->inventaire()->create(['objet_id' => $casque->id, 'emplacement' => 'sac']);
    app(Equipement::class)->equiper($hero->fresh(), $ligne);

    expect((int) $hero->fresh()->des_defense)->toBe(3) // 2 de base + 1
        ->and((int) $hero->fresh()->des_attaque)->toBe(3); // inchangée
});

it('n\'expose plus de route de génération de portrait', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    // Retirée (décision de René) : plus de génération d'image côté joueurs.
    // Les héros gardent leur illustration de CLASSE via BibliothequeImages.
    $this->postJson("/api/personnages/{$hero->id}/portrait")->assertNotFound();
});
