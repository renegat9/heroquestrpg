<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\DonObjet;
use App\Partie\Fouille\DeckFouille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Armes en os (lot B, Against the Ogre Horde p. 8) : « Weapons made of bone
 * are identical to weapons of the same name found in the armory, but bone
 * weapons have no gold coin value and cannot be bought or sold. »
 *
 * Deux armes : la hache de bataille en os (table de trésor du tournoi, p. 13)
 * et l'épée longue en os (quête 4, note C) — DÉRIVÉES de leur arme ordinaire
 * (`ObjetSeeder::$armeEnOs`), `rarete: unique` plutôt qu'une clé neuve
 * « invendable » (voir le commentaire de la migration `armes_en_os`).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Un groupe à deux héros, avec une quête démarrée (pour le deck de fouille). */
function demarrerGroupeOsse(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    return [$alice, $groupe, $hero, $quete];
}

dataset('armes en os', [
    ['Hache de bataille en os', 'Hache de bataille'],
    ['Épée longue en os', 'Épée longue'],
]);

it('dérive l\'arme en os de son arme ordinaire : mêmes dés, prix nul, pas de métal', function (string $nomOs, string $nomBase) {
    $os = Objet::where('nom', $nomOs)->firstOrFail();
    $base = Objet::where('nom', $nomBase)->firstOrFail();

    expect($os->os_de)->toBe($nomBase, 'le lien vers la base doit être déclaré')
        ->and((int) $os->prix_base)->toBe(0, '« no gold coin value »')
        ->and($os->rarete)->toBe('unique')
        ->and($os->metallique)->toBeFalse('l\'os n\'est pas un métal')
        ->and($base->metallique)->toBeTrue('l\'arme ordinaire, elle, EST en métal — le contraste fait la preuve')
        ->and($os->boite)->toBe('horde_ogre')
        ->and($os->categorie)->toBe('arme')
        ->and($os->tag_equipement)->toBe($base->tag_equipement, 'même maîtrise requise que l\'arme ordinaire')
        // MÊMES dés que l'arme ordinaire : « identical to weapons of the
        // same name found in the armory ».
        ->and((array) $os->effet)->toBe((array) $base->effet, 'effet identique à l\'arme ordinaire');
})->with('armes en os');

it('n\'expose aucune arme en os à l\'étal ni à la revente, et refuse de la vendre', function (string $nomOs) {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 1000]);
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $os = Objet::where('nom', $nomOs)->firstOrFail();
    $ligne = Inventaire::create([
        'personnage_id' => $hero->id, 'objet_id' => $os->id, 'emplacement' => 'sac', 'quantite' => 1,
    ]);
    Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => Objet::where('nom', 'Dague')->firstOrFail()->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    $reponse = test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/marche')->assertCreated();

    // Ni à l'achat (aucun profil ne stocke d'unique)…
    expect(collect($reponse->json('inventaire'))->pluck('nom'))->not->toContain($nomOs);

    // …ni dans la liste vendable du panier : proposer un bouton qui échoue
    // serait un piège.
    $vendables = collect($reponse->json('paniers.0.inventaire'));
    expect($vendables->pluck('nom'))->toContain('Dague')
        ->and($vendables->pluck('nom'))->not->toContain($nomOs);

    // Et le refus est côté MOTEUR, pas seulement côté affichage — même garde
    // que pour tout artefact `unique` (`PhaseMarche::REFUS_VENTE_UNIQUE`).
    test()->actingAs($alice, 'joueur')->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [],
        'ventes' => [['inventaire_id' => $ligne->id]],
    ])->assertStatus(422)->assertJsonValidationErrors('ventes');

    expect(Inventaire::whereKey($ligne->id)->exists())->toBeTrue('l\'arme en os n\'a pas dû être vendue');
})->with('armes en os');

it('se donne normalement entre héros, comme n\'importe quel autre objet (le prix n\'entre pas en jeu)', function (string $nomOs) {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $donneur = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'barbare']);
    $receveur = creerHeros($alice, $groupe, 'Brunhilde', 2, ['classe' => 'nain']);

    $os = Objet::where('nom', $nomOs)->firstOrFail();
    $ligne = Inventaire::create([
        'personnage_id' => $donneur->id, 'objet_id' => $os->id, 'emplacement' => 'sac', 'quantite' => 1,
    ]);

    app(DonObjet::class)->donner($donneur->fresh(), $ligne->fresh(), $receveur->fresh());

    $ligne->refresh();
    expect($ligne->personnage_id)->toBe($receveur->id)
        ->and($ligne->objet_id)->toBe($os->id);
})->with('armes en os');

it('ne tombe du coffre à artefact QUE dans un thème Against the Ogre Horde', function (string $nomOs) {
    [, $groupe, $hero, $quete] = demarrerGroupeOsse();
    $groupe->update(['theme_bestiaire' => 'jungles_delthrak']);

    $os = Objet::where('nom', $nomOs)->firstOrFail();

    // Toutes les autres armes/armures uniques déjà détenues : sans le filtre
    // de boîte, l'arme en os serait la seule candidate restante.
    foreach (Objet::where('rarete', 'unique')->whereIn('categorie', ['arme', 'armure'])
        ->where('id', '!=', $os->id)->get() as $autre) {
        Inventaire::create([
            'personnage_id' => $hero->id,
            'objet_id' => $autre->id, 'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }

    $choix = app(DeckFouille::class)->construire($quete->gabarit, $quete->carte->grille, $groupe->fresh(), 1);

    expect($choix['artefact_objet_id'])->not->toBe($os->id);
})->with('armes en os');

it('reste tirable EN campagne Against the Ogre Horde — le thème n\'écarte pas, il resserre', function (string $nomOs) {
    [, $groupe, $hero, $quete] = demarrerGroupeOsse();
    $groupe->update(['theme_bestiaire' => 'horde_ogre']);

    $os = Objet::where('nom', $nomOs)->firstOrFail();

    foreach (Objet::where('rarete', 'unique')->whereIn('categorie', ['arme', 'armure'])
        ->where('id', '!=', $os->id)->get() as $autre) {
        Inventaire::create([
            'personnage_id' => $hero->id,
            'objet_id' => $autre->id, 'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }

    $choix = app(DeckFouille::class)->construire($quete->gabarit, $quete->carte->grille, $groupe->fresh(), 1);

    expect($choix['artefact_objet_id'])->toBe($os->id);
})->with('armes en os');

it('ne figure jamais parmi les armes SANS carte de l\'armurerie', function (string $nomOs, string $nomBase) {
    // ⚠ Les armes en os ne sont PAS sourcées par `equipments.pdf` (elles n'ont
    // pas de prix) mais par le livret de quêtes — même famille que le Cor des
    // Hearthkin dans `config/cartes.php` (section `artefacts`), pas la section
    // `equipement`. Ce test fige la bonne section.
    $cartesArtefacts = array_column((array) config('cartes.artefacts.cartes'), 'objet');
    $cartesEquipement = array_column((array) config('cartes.equipement.cartes'), 'objet');

    expect($cartesArtefacts)->toContain($nomOs)
        ->and($cartesEquipement)->not->toContain($nomOs);
})->with('armes en os');
