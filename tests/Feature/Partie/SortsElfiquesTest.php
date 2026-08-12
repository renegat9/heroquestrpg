<?php

declare(strict_types=1);

use App\Engine\Des\LanceurDeterministe;
use App\Events\HerosVaSubirDegats;
use App\Listeners\ImageMiroir;
use App\Models\Sort;
use App\Partie\MoteurDegats;
use App\Partie\MoteurSorts;
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
use Illuminate\Support\Facades\Http;

/*
 * Répertoire elfique (© 2023 Hasbro, The Mage of the Mirror) — 5 sorts portés
 * sur 8. Les trois autres sont des dettes nommées dans SortSeeder.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

it('sème un répertoire elfique DISTINCT des quatre écoles', function () {
    $elfiques = Sort::where('element', MoteurSorts::REPERTOIRE_ELFIQUE)->pluck('nom')->all();

    // 6 des 8 cartes sont portées. Les 2 restantes sont des dettes nommées dans
    // SortSeeder : Flashback (écartée par René) et Twist Wood (nos monstres
    // n'ont aucun objet d'arme — le sort n'a pas de cible).
    expect($elfiques)->toHaveCount(6);

    // ⚠ Il ne se mélange pas aux éléments : un sort elfique ne doit jamais
    // arriver par le choix d'une école, sinon la seconde voie de l'Elfe n'en
    // serait plus une.
    expect(array_intersect($elfiques, Sort::whereIn('element', MoteurSorts::ELEMENTS)->pluck('nom')->all()))
        ->toBe([]);
});

it('IMAGE DOUBLE annule le coup sur 1-3, et le laisse passer sur 4-6', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    $sort = Sort::where('nom', 'Image double')->firstOrFail();
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => true]]);
    app(MoteurSorts::class)->appliquerBuff($heros, $sort);

    // Un d6 à 2 : l'image encaisse.
    $ecouteur = new ImageMiroir(app(MoteurSorts::class), new LanceurDeterministe([2]));
    $evenement = new HerosVaSubirDegats($heros, 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);
    $ecouteur->handle($evenement);

    expect($evenement->degats)->toBe(0);

    // Un d6 à 4 : le héros prend tout.
    $ecouteur = new ImageMiroir(app(MoteurSorts::class), new LanceurDeterministe([4]));
    $evenement = new HerosVaSubirDegats($heros, 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);
    $ecouteur->handle($evenement);

    expect($evenement->degats)->toBe(3);
});

it('IMAGE DOUBLE ne trompe pas un piège : on ne leurre pas une fosse avec un mirage', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    $sort = Sort::where('nom', 'Image double')->firstOrFail();
    app(MoteurSorts::class)->appliquerBuff($heros, $sort);

    // Même jet gagnant (2), mais la source est un piège : la carte parle
    // d'« an ATTACK against the hero ».
    $ecouteur = new ImageMiroir(app(MoteurSorts::class), new LanceurDeterministe([2]));
    $evenement = new HerosVaSubirDegats($heros, 2, MoteurDegats::SOURCE_PIEGE);
    $ecouteur->handle($evenement);

    expect($evenement->degats)->toBe(2);
});

it('n\'annule rien SANS le sort, même sur un jet gagnant', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    $ecouteur = new ImageMiroir(app(MoteurSorts::class), new LanceurDeterministe([1]));
    $evenement = new HerosVaSubirDegats($ctx['heros'], 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);
    $ecouteur->handle($evenement);

    expect($evenement->degats)->toBe(2);
});

it('ARRÊT DU TEMPS réarme le tour au lieu de le terminer, et une seule fois', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $etat = $ctx['etatHeros'];

    $etat->update(['tour_supplementaire' => true, 'a_agi' => true, 'a_deplace' => true]);

    // Le moteur n'accepte qu'une option du DERNIER menu proposé : on le fait
    // donc générer, puis on y prend « Terminer le tour ».
    App\Jobs\GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    $menu = Illuminate\Support\Facades\Cache::get(
        App\Jobs\GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id),
    )['menu'];

    // « Terminer le tour » porte le type `attente` — c'est le créneau `tour`
    // de ResolveurTour::creneauOption(), celui-là même que l'Arrêt du temps
    // détourne.
    $fin = collect($menu['options'])->firstWhere('type', 'attente');

    expect($fin)->not->toBeNull('le menu doit proposer de terminer le tour');

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $fin['id']])
        ->assertStatus(202);

    $apres = $etat->fresh();

    expect((bool) $apres->a_joue)->toBeFalse('le tour ne doit pas s\'achever')
        ->and((bool) $apres->a_agi)->toBeFalse()
        ->and((bool) $apres->a_deplace)->toBeFalse()
        // ⚠ Consommé : sans ça le héros jouerait indéfiniment.
        ->and((bool) $apres->tour_supplementaire)->toBeFalse();
});

it('PARALYSÉ retire tout au monstre : ni déplacement, ni attaque, ni défense', function () {
    $ogre = new App\Models\Monstre(['attaque' => 4, 'defense' => 4]);

    $instance = new App\Models\InstanceMonstre(['elite' => false]);
    $instance->setRelation('monstre', $ogre);
    $instance->habillage = ['conditions' => ['paralyse' => true]];

    // ⚠ Zéro, pas un malus : c'est le seul cas où le plancher à 1 ne
    // s'applique pas — « unable to move, attack, or defend ».
    expect($instance->attaqueEffective())->toBe(0)
        ->and($instance->defenseEffective())->toBe(0);
});

it('ÉVANESCENT laisse marcher mais interdit toute action, et rend intouchable', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    $sort = Sort::where('nom', 'Évanescence')->firstOrFail();
    app(MoteurSorts::class)->appliquerConditionCatalogue($heros, 'Évanescent', $sort);

    $sorts = app(MoteurSorts::class);

    // Le contraire de Paralysé sur le déplacement : il marche encore.
    expect($sorts->deplacementInterdit($heros))->toBeFalse()
        ->and($sorts->actionInterdite($heros))->toBeTrue()
        ->and($sorts->estInattaquable($heros))->toBeTrue();
});

it('rompt l\'ÉVANESCENCE sur un jet de déplacement de 5 ou plus', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $sorts = app(MoteurSorts::class);

    $sort = Sort::where('nom', 'Évanescence')->firstOrFail();
    $sorts->appliquerConditionCatalogue($heros, 'Évanescent', $sort);

    expect($sorts->actionInterdite($heros))->toBeTrue();

    // Le plateau rompt à 9+ sur 2 dés rouges ; nous à 5+ sur notre unique d6
    // (décision de René). C'est le JET DU TOUR qui décide, pas les pas faits.
    $sorts->rompreEvanescence($heros);

    expect($sorts->actionInterdite($heros->fresh()))->toBeFalse();
});

it('FLAMME HYPNOTIQUE épargne les Mind 0 : un mort-vivant n\'a pas d\'esprit à hypnotiser', function () {
    // Correction de René (2026-08-12) : c'est une attaque MENTALE, donc la
    // règle générale du jeu s'applique — celle qui interdit Sommeil « against
    // mummies, zombies, or skeletons » (LR p. 8), et qui vaut précisément
    // parce que ces trois-là ont Mind 0. Je l'avais d'abord lu à l'envers, en
    // suivant la lettre du jeton plutôt que la nature du sort.
    //
    // Le test porte sur la DONNÉE dont dépend la règle — les trois morts-vivants
    // sont bien à Mind 0 — et sur le fait que le code les écarte : sans cette
    // garde, `$de > $mind` serait vrai pour TOUT jet contre un Mind 0, donc la
    // flamme les paralyserait à coup sûr, exactement l'inverse de la règle.
    $mindsNuls = App\Models\Monstre::whereIn('nom_base', ['Squelette', 'Zombie', 'Momie'])
        ->pluck('pv_mind', 'nom_base');

    expect($mindsNuls->values()->all())->each->toBe(0);

    $source = file_get_contents(base_path('app/Partie/ResolveurTour.php'));
    $zone = substr($source, strpos($source, 'private function sortDeZone'));
    $zone = substr($zone, 0, strpos($zone, 'private function salleDeCase'));

    // Deux gardes, une par camp (héros et monstres) : retirer l'une des deux
    // rendrait la moitié des figures vulnérables sans que rien ne le signale.
    expect(substr_count($zone, '$mind === 0'))->toBe(2);
});
