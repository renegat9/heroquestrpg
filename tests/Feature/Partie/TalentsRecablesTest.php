<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use App\Partie\Talents;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * LES TALENTS MUETS PARLENT — recâblage nom → mécanique (2026-08-23).
 *
 * Jusqu'ici les lecteurs du moteur étaient branchés sur le NOM du nœud :
 * `'Garde tenace'`, `'Coup puissant'`, `'Intimidation'`, `'Réserve arcanique'`,
 * `'Concentration'`, `'Désamorçage'`, `'Tir précis'`, `'Œil du mineur'`,
 * `'Contresort'`, `'Forge'`. Une quinzaine de nœuds des classes d'extension
 * portaient la BONNE `effet.mecanique` et ne faisaient rien du tout — le seeder
 * promettait pourtant en commentaire de n'employer « QUE des mécaniques ayant
 * déjà un lecteur ». C'était vrai de la mécanique, faux du câblage.
 *
 * ⚠ Chaque cas est joué sur une classe qui n'est PAS celle du nom historique.
 * C'est tout l'objet du fichier : si un seul lecteur revenait au câblage par
 * nom, ce test tomberait — et lui seul.
 *
 * ⚠ Un cas PAR TEST (jeux de données Pest) et jamais une boucle : chaque cas
 * monte sa propre quête, donc son propre groupe « table-1 », et deux dans la
 * même fonction se heurtent à l'unicité du code de groupe.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class]);
});

/** Pose un menu d'un seul JET, avec son contexte — le vrai menu n'en produit pas. */
function menuDeJet(array $ctx, string $id, string $contexte): void
{
    Cache::put(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id), [
        'personnage_id' => $ctx['heros']->id,
        'menu' => ['options' => [[
            'id' => $id, 'libelle' => 'Jet de Mind', 'type' => 'jet',
            'jet' => ['attribut' => 'mind', 'difficulte' => 1, 'contexte' => $contexte],
        ]]],
    ], now()->addMinutes(60));
}

/** Une attaque de monstre sur le héros, dés figés — le compte de dés se lit dessus. */
function coupDuMonstre(array $ctx, array $des, ?App\Models\EtatPersonnageQuete $cible = null): array
{
    desFiges($des);

    return (new ReflectionMethod(ResolveurTour::class, 'resoudreAttaqueMonstre'))->invoke(
        app(ResolveurTour::class),
        $ctx['groupe'], $ctx['instance'], $cible ?? $ctx['etatHeros'], 2, [], 'Gobelin',
    );
}

it('donne le dé de défense de « Garde tenace » à un nœud qui ne porte pas ce nom', function (string $classe, string $noeud) {
    $ctx = demarrerQueteAvecMonstre('Gobelin', [
        'classe' => $classe, 'pv_body_max' => 20, 'pv_body' => 20,
    ]);

    $des = [1, 1, ...array_fill(0, 12, 4)]; // 2 crânes, puis des boucliers blancs

    // ⚠ La base se MESURE, elle ne se déduit pas de `des_defense` : le Barde
    // porte « Léger sur ses pieds », une capacité de carte qui lui donne déjà un
    // dé tant qu'il n'a ni métal ni bouclier. Partir de la colonne aurait fait
    // échouer le test sur une addition parfaitement correcte.
    $base = (int) coupDuMonstre($ctx, $des)['boucliers'];

    // La PREMIÈRE attaque de la quête vient d'être consommée : on la rouvre pour
    // rejouer le même coup, cette fois avec le talent.
    $ctx['etatHeros']->fresh()->update(['garde_tenace_utilisee' => false]);
    donnerTalent($ctx['heros'], $noeud);

    $avec = coupDuMonstre($ctx, $des, $ctx['etatHeros']->fresh());

    expect($avec['bonus_garde_tenace'])->toBe(1)
        ->and($avec['boucliers'])->toBe($base + 1);
})->with([
    ['rogue', 'Esquive'],
    ['chevalier', 'Garde haute'],
    ['druide', 'Écorce'],
    ['barde', 'Refrain vaillant'],
]);

it('donne le dé de Mind du CONTEXTE, quel que soit le nom du nœud', function (string $classe, string $noeud, string $contexte) {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => $classe, 'attribut_mind' => 2]);

    menuDeJet($ctx, 'jeter_un_oeil', $contexte);
    desFiges(array_fill(0, 10, 4));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'jeter_un_oeil'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.bonus_avantage_mind', 0)
        ->assertJsonPath('resultat.des_lances', 2);

    donnerTalent($ctx['heros'], $noeud);
    $ctx['etatHeros']->fresh()->update(['a_joue' => false, 'a_agi' => false]);

    menuDeJet($ctx, 'jeter_un_oeil', $contexte);
    desFiges(array_fill(0, 10, 4));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'jeter_un_oeil'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.bonus_avantage_mind', 1)
        ->assertJsonPath('resultat.des_lances', 3);
})->with([
    ['chevalier', 'Prestance', 'social_peur'],
    ['barde', 'Beau parleur', 'social_peur'],
    ['moine', 'Méditation', 'savoir'],
    ['explorateur', 'Cartographe', 'savoir'],
    ['druide', 'Regard de la bête', 'perception'],
]);

it('relance les dés ratés comme « Coup puissant », sous un autre nom', function (string $classe, string $noeud) {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => $classe, 'des_attaque' => 2]);
    $ctx['instance']->update(['pv_body' => 20]);
    $defense = (int) $ctx['instance']->monstre->defense;

    // Deux ratés, puis deux crânes en RELANCE, puis la défense du monstre
    // (boucliers blancs : sans effet pour lui).
    $des = [4, 4, 1, 1, ...array_fill(0, $defense + 2, 4)];

    $frapper = function () use ($ctx, $des) {
        GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
        desFiges($des);

        return test()->postJson('/api/groupes/table-1/choix', [
            'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
        ])->assertStatus(202)->json('resultat');
    };

    // Sans le talent, les deux ratés restent des ratés : aucun dégât.
    expect($frapper()['degats'])->toBe(0);

    donnerTalent($ctx['heros'], $noeud);
    $ctx['etatHeros']->fresh()->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);

    expect($frapper()['degats'])->toBe(2);
})->with([
    ['berserker', 'Coup sauvage'],
    ['chevalier', "Bras d'acier"],
]);

it('ouvre le second sort du tour comme « Réserve arcanique », sous un autre nom', function (string $classe, string $noeud) {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => $classe]);
    $heros = $ctx['heros'];

    $sort = Sort::orderBy('id')->firstOrFail();
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => true]]);

    // Le créneau d'action est DÉJÀ dépensé : seul le bonus peut rouvrir un sort.
    $ctx['etatHeros']->fresh()->update(['a_agi' => true, 'a_joue' => false, 'bonus_sort_utilise' => false]);

    $typesDuMenu = function () use ($ctx, $heros) {
        GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);

        return collect(test()->getJson('/api/groupes/table-1/menu?personnage_id='.$heros->id)
            ->assertOk()->json('menu.options') ?? [])->pluck('type')->all();
    };

    expect($typesDuMenu())->not->toContain('sort');

    donnerTalent($heros, $noeud);

    expect($typesDuMenu())->toContain('sort');
})->with([
    ['warlock', 'Réserve damnée'],
    ['barde', 'Second couplet'],
]);

it('récupère un sort épuisé comme « Concentration » — la garde de classe est tombée', function (string $classe, string $noeud) {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => $classe]);
    $heros = $ctx['heros'];
    $sorts = app(MoteurSorts::class);

    $sort = Sort::orderBy('id')->firstOrFail();
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => false]]);

    expect($sorts->concentrationDisponible($heros, $ctx['etatHeros']))->toBeFalse();

    donnerTalent($heros, $noeud);

    expect($sorts->concentrationDisponible($heros->fresh(), $ctx['etatHeros']))->toBeTrue();

    // Et l'option arrive bien au menu du JOUEUR, pas seulement au moteur : le
    // contrôleur refuse toute option absente du dernier menu.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);

    expect(collect($this->getJson('/api/groupes/table-1/menu?personnage_id='.$heros->id)
        ->assertOk()->json('menu.options') ?? [])->pluck('id')->all())
        ->toContain('se_concentrer');
})->with([
    ['druide', 'Communion'],
    ['barde', 'Rappel'],
]);

it('VERBE ANCIEN (druide) contre-sorte comme le « Contresort » du magicien', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'druide']);
    $talents = app(Talents::class);

    expect($talents->a($ctx['heros'], 'annuler_effet_magique'))->toBeFalse();

    donnerTalent($ctx['heros'], 'Verbe ancien');

    // ⚠ C'est cette lecture que `MoteurDread::sortDreadControle()` fait
    // désormais, là où elle cherchait le nœud nommé « Contresort ».
    expect($talents->a($ctx['heros']->fresh(), 'annuler_effet_magique'))->toBeTrue();
});
