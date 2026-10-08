<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Mercenaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
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
 * Décision de recrutement au hub (`App\Partie\RecrutementHub`, 2026-10-08).
 *
 * Le serveur publie, héros par héros, le PRIX RÉEL (remise de Potion de charme
 * comprise) et le VERDICT : la manette affiche la ligne de son héros sans rien
 * recalculer. Et le POST applique la MÊME décision : ce que la manette grise, le
 * serveur le refuse ; ce qu'elle montre permis, le serveur le prend au prix publié.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, MercenaireSeeder::class,
        SortDreadSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** La décision publiée au hub pour CE héros sur CET allié (`groupe.recrutement`). */
function decisionRecrutement(Groupe $groupe, Personnage $hero, Mercenaire $mercenaire): array
{
    $offres = app(EtatGroupe::class)->payload($groupe->fresh())['groupe']['recrutement']['offres'];
    $offre = collect($offres)->firstWhere('mercenaire_id', $mercenaire->id);

    return collect($offre['decisions'])->firstWhere('personnage_id', $hero->id);
}

/** Une Potion de charme dans le sac du héros. */
function fioleDeCharme(Personnage $hero): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => Objet::where('nom', 'Potion de charme')->firstOrFail()->id,
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);
}

it('publie le prix RÉEL du héros : la remise de la Potion de charme est dans le prix décidé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);
    $groupe->update(['or' => 500]);
    $fauchard = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $prix = (int) $fauchard->prix;

    expect(decisionRecrutement($groupe, $hero, $fauchard))
        ->toMatchArray(['prix' => $prix, 'prix_catalogue' => $prix, 'rabais_po' => 0, 'recrutable' => true, 'motif' => null]);

    $this->postJson('/api/groupes/table-1/potions/boire-au-hub', [
        'personnage_id' => $hero->id,
        'inventaire_id' => fioleDeCharme($hero)->id,
    ])->assertOk();

    // La décision publiée baisse de 25 : la manette n'a qu'à l'afficher.
    expect(decisionRecrutement($groupe, $hero, $fauchard))
        ->toMatchArray(['prix' => $prix - 25, 'prix_catalogue' => $prix, 'rabais_po' => 25, 'recrutable' => true]);
});

it('le verdict publié EST celui du POST : au-dessus du prix remisé seulement, le recrutement passe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);
    $fauchard = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $prix = (int) $fauchard->prix;
    $groupe->update(['or' => $prix - 25]);

    // Sans la potion : la manette doit griser, et le serveur refuse avec la même phrase.
    $decision = decisionRecrutement($groupe, $hero, $fauchard);
    expect($decision['recrutable'])->toBeFalse()
        ->and($decision['motif'])->toContain('Or insuffisant');

    $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => $fauchard->id, 'personnage_id' => $hero->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['mercenaire_id']);
    expect((int) $groupe->fresh()->or)->toBe($prix - 25);

    // Potion bue : le même or suffit, la décision passe au vert…
    $this->postJson('/api/groupes/table-1/potions/boire-au-hub', [
        'personnage_id' => $hero->id,
        'inventaire_id' => fioleDeCharme($hero)->id,
    ])->assertOk();

    $decision = decisionRecrutement($groupe, $hero, $fauchard);
    expect($decision['recrutable'])->toBeTrue()->and($decision['motif'])->toBeNull();

    // …et le POST débite le prix PUBLIÉ, puis consomme UN rabais.
    $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => $fauchard->id, 'personnage_id' => $hero->id,
    ])->assertStatus(201)->assertJsonPath('or', 0);

    expect($hero->fresh()->recrutements_a_rabais)->toBe(2);
});

it('le plafond de quatre par héros est publié comme motif, et le POST refuse le cinquième', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);
    $groupe->update(['or' => 5000]);
    $fauchard = Mercenaire::where('nom', 'Fauchard')->firstOrFail();

    foreach (range(1, 4) as $_) {
        $this->postJson('/api/groupes/table-1/mercenaires', [
            'mercenaire_id' => $fauchard->id, 'personnage_id' => $hero->id,
        ])->assertStatus(201);
    }

    $decision = decisionRecrutement($groupe, $hero, $fauchard);
    expect($decision['recrutable'])->toBeFalse()
        ->and($decision['motif'])->toContain('a déjà engagé 4 mercenaires');

    $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => $fauchard->id, 'personnage_id' => $hero->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);
});
