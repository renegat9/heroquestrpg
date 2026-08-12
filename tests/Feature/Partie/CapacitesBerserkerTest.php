<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Partie\MoteurDegats;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Les trois capacités de carte du BERSERKER (© 2024 Hasbro), en jeu.
 *
 * C'est la classe qui joue sa propre dégradation : deux de ses trois capacités
 * ne s'ouvrent QU'EN ÉTANT BLESSÉ, et la troisième se paie en PV. Les tester au
 * catalogue ne prouverait rien — ce qui compte est qu'elles soient PROPOSÉES au
 * bon moment (le menu est la seule porte d'entrée du contrôleur) et qu'elles
 * coûtent ce qu'elles promettent.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/** Le menu moteur du héros, tel que le contrôleur le validera. */
function menuDe(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];
}

it('FURIE offre une option par PV sacrifiable, et jamais plus que ce qui laisse debout', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker']);

    $ids = collect(menuDe($ctx)['options'])->pluck('id');

    // « lose UP TO 2 Body Points » : deux montants, donc deux options — le
    // joueur doit pouvoir payer 1 quand il ne veut (ou ne peut) pas payer 2.
    expect($ids)->toContain('furie_1')->toContain('furie_2');

    // À 2 PV, sacrifier 2 le coucherait avant qu'il frappe : seul le montant
    // payable reste offert (lecture de portage, voir ResolveurTour::payerLaFurie).
    $ctx['heros']->update(['pv_body' => 2]);
    $ids = collect(menuDe($ctx)['options'])->pluck('id');

    expect($ids)->toContain('furie_1')->not->toContain('furie_2');

    // À 1 PV, il n'a plus rien à verser.
    $ctx['heros']->update(['pv_body' => 1]);
    $ids = collect(menuDe($ctx)['options'])->pluck('id');

    expect($ids)->not->toContain('furie_1')->not->toContain('furie_2');
});

it('FURIE paie les PV avant de frapper et ajoute autant de dés', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker', 'des_attaque' => 3]);
    $heros = $ctx['heros'];
    $avant = (int) $heros->pv_body;

    menuDe($ctx);
    desFiges(array_fill(0, 20, 4)); // boucliers blancs : jet neutre, on lit les dés

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'furie_2',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    // Les 2 PV sont versés, et ils reviennent en dés d'attaque : 3 + 2.
    expect((int) $heros->fresh()->pv_body)->toBe($avant - 2);
    $reponse->assertJsonPath('resultat.des_attaque_effectifs', 5)
        ->assertJsonPath('resultat.furie', 2);
});

it('FURIE ne sert qu\'UNE FOIS par quête', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker']);

    menuDe($ctx);
    desFiges(array_fill(0, 40, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'furie_1',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    // Le compteur vit sur l'état de quête : dépensée, elle disparaît du menu.
    $ctx['etatHeros']->fresh()->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);

    expect(collect(menuDe($ctx)['options'])->pluck('id'))->not->toContain('furie_1');
});

it('n\'offre la FURIE à personne d\'autre : c\'est une capacité de carte', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin'); // barbare par défaut

    expect(collect(menuDe($ctx)['options'])->pluck('id'))
        ->toContain('attaquer')
        ->not->toContain('furie_1');
});

it('refuse une FURIE forgée dans un menu périmé quand la capacité est absente', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin'); // barbare : aucune capacité

    Cache::put(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id), [
        'personnage_id' => $ctx['heros']->id,
        'menu' => ['options' => [[
            'id' => 'furie_2', 'libelle' => 'Furie', 'type' => 'attaque',
            'parametres' => ['furie' => 2, 'cibles' => [
                ['id' => $ctx['instance']->id, 'type' => 'monstre', 'nom' => 'Gobelin'],
            ]],
        ]]],
    ], now()->addMinutes(60));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'furie_2',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(422);

    // Et surtout : aucun PV perdu pour une capacité qu'il n'a pas.
    expect((int) $ctx['heros']->fresh()->pv_body)->toBe((int) $ctx['heros']->pv_body_max);
});

it('REPRÉSAILLES est proposée quand un monstre AU CONTACT blesse un Berserker entamé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker']);
    $heros = $ctx['heros'];

    // Au-dessus de 5 PV, la capacité est fermée : « Cannot be used unless you
    // have 5 or fewer Body Points. »
    $heros->update(['pv_body' => 7]);
    app(MoteurDegats::class)->infligerAHeros($heros, 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);

    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();

    // Le coup qui le fait passer à 5 ouvre la capacité : le seuil se lit sur
    // les PV D'APRÈS, c'est bien le coup encaissé qui la déclenche.
    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);

    $attente = $ctx['etatHeros']->fresh()->reaction_en_attente;

    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe('riposte')
        ->and($attente['instance_id'])->toBe($ctx['instance']->id);
});

it('REPRÉSAILLES rend le coup sans annuler celui qu\'on a pris', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker', 'des_attaque' => 3]);
    $heros = $ctx['heros'];
    $heros->update(['pv_body' => 5]);

    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);

    expect((int) $heros->fresh()->pv_body)->toBe(3);

    desFiges(array_fill(0, 20, 1)); // crânes partout : la riposte porte

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => true,
    ])->assertOk()
        ->assertJsonPath('reaction.active', true)
        // ⚠ Le Berserker encaisse ET rend : aucun PV restitué.
        ->assertJsonPath('reaction.degats_annules', 0)
        ->assertJsonPath('reaction.frappe.cible_vaincue', true);

    expect((int) $heros->fresh()->pv_body)->toBe(3)
        ->and($ctx['instance']->fresh()->etat)->toBe('vaincu')
        // Dépensée pour la quête.
        ->and(app(App\Partie\CapacitesInnees::class)
            ->disponible($heros->fresh(), $ctx['etatHeros']->fresh(), 'riposte'))->toBeFalse();
});

it('ne propose pas de REPRÉSAILLES à un Berserker mis à terre', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker']);
    $heros = $ctx['heros'];
    $heros->update(['pv_body' => 2]);

    // Un héros à 0 PV est à terre : il ne rend pas de coup. *Inébranlable*
    // couvre ce cas-là, pas celle-ci.
    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);

    expect((int) $heros->fresh()->pv_body)->toBe(0)
        ->and($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();
});

it('ne propose pas de REPRÉSAILLES contre un tireur hors de contact', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker']);
    $heros = $ctx['heros'];
    $heros->update(['pv_body' => 4]);

    // « damage from an ADJACENT monster » : le monstre s'éloigne, plus rien à
    // riposter — on ne rend pas un coup à travers la salle.
    $ctx['instance']->update([
        'position_x' => (int) $ctx['etatHeros']->position_x + 4,
        'position_y' => (int) $ctx['etatHeros']->position_y + 4,
    ]);

    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);

    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();
});

it('n\'ouvre les capacités de sang qu\'une fois le Berserker BLESSÉ', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker']);
    $capacites = app(App\Partie\CapacitesInnees::class);

    // Représailles : « 5 or fewer Body Points », Frénésie : « 3 or fewer ».
    $ctx['heros']->update(['pv_body' => 6]);

    expect($capacites->disponible($ctx['heros']->fresh(), $ctx['etatHeros'], 'riposte'))->toBeFalse();

    $ctx['heros']->update(['pv_body' => 5]);

    expect($capacites->disponible($ctx['heros']->fresh(), $ctx['etatHeros'], 'riposte'))->toBeTrue();

    // Les trois capacités sont bien innées : acquises avec la figurine.
    $innees = $ctx['heros']->competences()->where('innee', true)->pluck('nom')->sort()->values()->all();

    expect($innees)->toBe(['Frénésie sanguinaire', 'Furie', 'Représailles']);
    expect(Competence::where('classe', 'berserker')->where('innee', true)->count())->toBe(3);
});
