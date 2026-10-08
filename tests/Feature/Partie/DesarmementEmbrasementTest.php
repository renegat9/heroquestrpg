<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\MenuMoteur;
use App\Partie\MoteurPieges;
use App\Partie\MoteurSorts;
use App\Partie\ZoneFouille;
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
 * Piège d'embrasement (Fireburst Trap) désamorcé en DÉFAUSSANT un sort (Magic
 * Reference Chart, Wizards of Morcar, relu à l'image 2026-10-08) : « If a hero
 * in the room with a Fireburst token discards a Tempest spell or any Water
 * Spell, the trap is disarmed. »
 *
 * Test EN JEU : l'option vient du MENU (une par sort légal), la résolution passe
 * par `POST /choix`, le sort est épuisé, le jeton ne explose jamais. Les
 * refus (hors zone, sort de feu, jeton déjà consommé, sort déjà défaussé)
 * sont revalidés par le résolveur.
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

/**
 * Quête démarrée avec deux héros (le second empêche la phase des monstres de
 * se déclencher après l'action du premier) — même patron que PiegesTest.
 *
 * @return array{0: \App\Models\Personnage, 1: \App\Models\Groupe, 2: Quete, 3: EtatPersonnageQuete}
 */
function embrasementDemarrerQuete(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    return [$hero, $groupe, $quete, $etat];
}

/** Remplace les pièges de la carte par UN jeton d'embrasement, à `(x, y)`, dans l'état donné. */
function embrasementPoserJeton(Quete $quete, int $x, int $y, string $etat): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = [[
        'x' => $x,
        'y' => $y,
        'piege_id' => Piege::where('nom', "Piège d'embrasement")->value('id'),
        'etat' => $etat,
    ]];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/** Une case libre HORS de la zone (salle ou couloir) de `(x0, y0)`, ou null. */
function embrasementCaseHorsZone(Quete $quete, int $x0, int $y0): ?array
{
    $grille = (array) $quete->carte->grille;
    $zone = ZoneFouille::de($grille, $x0, $y0);

    for ($x = 0; $x < 60; $x++) {
        for ($y = 0; $y < 60; $y++) {
            if (! $zone->contient($x, $y) && caseQueteLibre($quete, $x, $y)) {
                return ['x' => $x, 'y' => $y];
            }
        }
    }

    return null;
}

/** Les options de désarmement publiées par le MENU du héros. */
function embrasementOptions(\App\Models\Groupe $groupe, \App\Models\Personnage $hero): array
{
    // `generer()` rend `{situation, options}` : les options sont sous `options`.
    $menu = app(MenuMoteur::class)->generer($groupe->fresh(), $hero->fresh());

    return collect($menu['options'])->where('type', 'desarmer_embrasement')->values()->all();
}

/**
 * Republie le DERNIER menu du héros (le cache que `POST /choix` relit comme liste
 * blanche) : à refaire après avoir posé le jeton, puisque le menu de départ de
 * la quête ne le connaît pas encore — même patron que PiegesTest.
 */
function embrasementRegenererMenu(\App\Models\Groupe $groupe, \App\Models\Personnage $hero): void
{
    GenererMenu::dispatchSync($groupe->id, (int) $hero->joueur_id, (int) $hero->id);
}

/** Les payloads du journal du groupe, décodés (une seule forme, quel que soit le cast). */
function embrasementJournal(int $groupeId): array
{
    return Evenement::where('groupe_id', $groupeId)->get()
        ->map(fn ($e) => is_string($e->payload) ? json_decode($e->payload, true) : $e->payload)
        ->all();
}

it('le menu offre le désamorçage pour chaque sort légal d\'un héros dans la zone du jeton — et pour rien d\'autre', function () {
    [$hero, $groupe, $quete, $etat] = embrasementDemarrerQuete();

    $x = (int) $etat->position_x;
    $y = (int) $etat->position_y;
    embrasementPoserJeton($quete, $x, $y, MoteurPieges::ETAT_AMORCE);

    // Trois sorts d'Eau (Sommeil, Voile de Brume, Eau de Guérison), Tempête
    // (élément air, nommé par la carte) et une Boule de Feu, qui ne compte pas.
    app(MoteurSorts::class)->attacherElement($hero, 'eau');
    $hero->sorts()->syncWithoutDetaching([
        Sort::where('nom', 'Tempête')->value('id') => ['disponible' => true],
        Sort::where('nom', 'Boule de Feu')->value('id') => ['disponible' => true],
    ]);

    $options = embrasementOptions($groupe, $hero);

    expect($options)->toHaveCount(4)
        ->and(collect($options)->pluck('parametres.piege_index')->unique()->all())->toBe([0])
        ->and(collect($options)->pluck('parametres.sort_id'))
        ->not->toContain(Sort::where('nom', 'Boule de Feu')->value('id'));
});

it('la résolution désarme le jeton : le sort est épuisé, le jeton passe désarmé, et il n\'explose jamais', function () {
    [$hero, $groupe, $quete, $etat] = embrasementDemarrerQuete();

    embrasementPoserJeton($quete, (int) $etat->position_x, (int) $etat->position_y, MoteurPieges::ETAT_AMORCE);
    app(MoteurSorts::class)->attacherElement($hero, 'eau');

    $sommeil = Sort::where('nom', 'Sommeil')->firstOrFail();
    embrasementRegenererMenu($groupe, $hero);
    $option = collect(embrasementOptions($groupe, $hero))->firstWhere('parametres.sort_id', $sommeil->id);
    expect($option)->not->toBeNull();

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'piege_desarme_embrasement')
        ->assertJsonPath('resultat.sort.nom', 'Sommeil');

    // Le sort est défaussé pour cette quête, le jeton est désarmé…
    expect((bool) $hero->fresh()->sorts()->where('sorts.id', $sommeil->id)->first()->pivot->disponible)->toBeFalse()
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_DESARME);

    // …et la phase des monstres ne le fait JAMAIS exploser.
    expect(app(MoteurPieges::class)->explosionsFireburstEnAttente($groupe, $quete->fresh()))->toBe([]);

    // Annoncé : le journal porte l'événement, pas seulement la réponse HTTP.
    expect(collect(embrasementJournal($groupe->id))->pluck('type'))->toContain('piege_desarme_embrasement');
});

it('un sort déjà défaussé cette quête ne désarme plus rien : la même option est refusée', function () {
    [$hero, $groupe, $quete, $etat] = embrasementDemarrerQuete();

    embrasementPoserJeton($quete, (int) $etat->position_x, (int) $etat->position_y, MoteurPieges::ETAT_AMORCE);
    app(MoteurSorts::class)->attacherElement($hero, 'eau');

    $sommeil = Sort::where('nom', 'Sommeil')->firstOrFail();
    embrasementRegenererMenu($groupe, $hero);
    $option = collect(embrasementOptions($groupe, $hero))->firstWhere('parametres.sort_id', $sommeil->id);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])->assertStatus(202);

    // La MÊME option est encore dans le menu mis en cache : c'est le RÉSOLVEUR
    // qui la refuse, le jeton étant désarmé et le sort défaussé.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])
        ->assertStatus(422);
});

it('un jeton déjà consommé (explosé ou désarmé) n\'est plus offert, ni accepté', function () {
    [$hero, $groupe, $quete, $etat] = embrasementDemarrerQuete();

    embrasementPoserJeton($quete, (int) $etat->position_x, (int) $etat->position_y, MoteurPieges::ETAT_DECLENCHE);
    app(MoteurSorts::class)->attacherElement($hero, 'eau');

    expect(embrasementOptions($groupe, $hero))->toBe([])
        ->and(app(MoteurPieges::class)->jetonsEmbrasementLegaux($quete->fresh(), $etat->fresh()))->toBe([]);
});

it('un héros HORS de la zone du jeton ne peut pas le désarmer : ni le menu, ni le résolveur', function () {
    [$hero, $groupe, $quete, $etat] = embrasementDemarrerQuete();

    $x = (int) $etat->position_x;
    $y = (int) $etat->position_y;
    embrasementPoserJeton($quete, $x, $y, MoteurPieges::ETAT_AMORCE);
    app(MoteurSorts::class)->attacherElement($hero, 'eau');

    // L'option est publiée TANT QUE le héros est dans la zone…
    $sommeil = Sort::where('nom', 'Sommeil')->firstOrFail();
    embrasementRegenererMenu($groupe, $hero);
    expect(collect(embrasementOptions($groupe, $hero))->pluck('parametres.sort_id'))->toContain($sommeil->id);

    // …puis le héros s'éloigne, et l'option reste dans le menu mis en cache :
    // c'est le RÉSOLVEUR qui doit la refuser (défense en profondeur).
    $horsZone = embrasementCaseHorsZone($quete->fresh(), $x, $y);
    expect($horsZone)->not->toBeNull();
    $etat->update(['position_x' => $horsZone['x'], 'position_y' => $horsZone['y']]);

    expect(embrasementOptions($groupe, $hero))->toBe([]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "desarmer_embrasement_0_{$sommeil->id}",
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['option_id']);

    expect($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_AMORCE)
        ->and((bool) $hero->fresh()->sorts()->where('sorts.id', $sommeil->id)->first()->pivot->disponible)->toBeTrue();
});

it('un héros qui ne connaît que des sorts de Feu ne peut pas désarmer le piège', function () {
    [$hero, $groupe, $quete, $etat] = embrasementDemarrerQuete();

    embrasementPoserJeton($quete, (int) $etat->position_x, (int) $etat->position_y, MoteurPieges::ETAT_AMORCE);
    app(MoteurSorts::class)->attacherElement($hero, 'feu');

    expect(embrasementOptions($groupe, $hero))->toBe([]);
});

it('seuls Tempête et les sorts d\'élément Eau sont des sorts désarmants : la carte nomme le premier, l\'élément le second', function () {
    expect(MoteurPieges::sortDesarmeEmbrasement(Sort::where('nom', 'Tempête')->firstOrFail()))->toBeTrue()
        ->and(MoteurPieges::sortDesarmeEmbrasement(Sort::where('nom', 'Sommeil')->firstOrFail()))->toBeTrue()
        ->and(MoteurPieges::sortDesarmeEmbrasement(Sort::where('nom', 'Eau de Guérison')->firstOrFail()))->toBeTrue()
        ->and(MoteurPieges::sortDesarmeEmbrasement(Sort::where('nom', 'Boule de Feu')->firstOrFail()))->toBeFalse()
        ->and(MoteurPieges::sortDesarmeEmbrasement(Sort::where('nom', 'Soin du Corps')->firstOrFail()))->toBeFalse();
});
