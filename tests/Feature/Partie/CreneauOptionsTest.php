<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MenuMoteur;
use App\Partie\ResolveurTour;
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
 * CRÉNEAU PUBLIÉ (contrat « creneau — chaque option dit ce qu'elle coûte »,
 * 2026-09-18) : chaque option de `MenuMoteur::generer()` porte désormais
 * `creneau`, la valeur que rend `ResolveurTour::creneauOption()` pour son
 * `type` — publiée TELLE QUELLE, jamais recalculée dans `MenuMoteur`. Le
 * client (`ActionTab.vue`) LIT ce champ pour afficher ∞ sur les interactions
 * gratuites et pour griser un bouton, il ne le re-dérive plus — voir
 * `MenuSousChoixTest.php` pour la disparition du miroir JS qui a menti trois
 * fois (`actionner_levier`, `objet_libre`, le bonus d'héroïsme).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/** Menu moteur régénéré pour le héros depuis l'état exact (patron de `CoherenceMenuTest`). */
function menuAvecCreneaux(Groupe $groupe, Personnage $heros): array
{
    desFiges(array_fill(0, 20, 4));

    return app(MenuMoteur::class)->generer($groupe->fresh(), $heros->fresh());
}

it('chaque option publiée porte un `creneau` non nul', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $menu = menuAvecCreneaux($ctx['groupe'], $ctx['heros']);

    expect($menu['options'])->not->toBe([]);

    foreach ($menu['options'] as $option) {
        expect($option['creneau'] ?? null)
            ->not->toBeNull("l'option « {$option['id']} » (type {$option['type']}) ne porte aucun creneau")
            ->toBeIn(['mouvement', 'action', 'interaction', 'tour']);
    }
});

it('un déplacement sort en `mouvement`', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $menu = menuAvecCreneaux($ctx['groupe'], $ctx['heros']);

    $option = collect($menu['options'])->firstWhere('type', 'deplacement');

    expect($option)->not->toBeNull()->and($option['creneau'])->toBe('mouvement');
});

it('une attaque sort en `action`', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $menu = menuAvecCreneaux($ctx['groupe'], $ctx['heros']);

    $option = collect($menu['options'])->firstWhere('type', 'attaque');

    expect($option)->not->toBeNull()->and($option['creneau'])->toBe('action');
});

it('« battre en retraite » sort en `interaction`', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $menu = menuAvecCreneaux($ctx['groupe'], $ctx['heros']);

    $option = collect($menu['options'])->firstWhere('type', 'retraite');

    expect($option)->not->toBeNull()->and($option['creneau'])->toBe('interaction');
});

it('« quitter le donjon » (sortie) sort en `interaction`', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    // Repli anti-blocage de `generer()` : plus aucun monstre actif ouvre
    // « Quitter le donjon », même sans objectif accompli.
    $ctx['instance']->update(['etat' => 'vaincu']);

    $menu = menuAvecCreneaux($ctx['groupe'], $ctx['heros']);
    $option = collect($menu['options'])->firstWhere('type', 'sortie');

    expect($option)->not->toBeNull()->and($option['creneau'])->toBe('interaction');
});

it('« ouvrir la porte » sort en `interaction`', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = $quete->etatsPersonnages()->where('personnage_id', $hero->id)->firstOrFail();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Même format qu'un vrai `PortesExplorationTest` : une porte simplement
    // fermée (pas verrouillée), adjacente au héros.
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['portes'] = [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'fermee']];
    $carte->update(['grille' => $grille]);

    $menu = menuAvecCreneaux($groupe, $hero);
    $option = collect($menu['options'])->firstWhere('type', 'ouvrir_porte');

    expect($option)->not->toBeNull()->and($option['creneau'])->toBe('interaction');
});

it('« jeter » sort en `interaction`', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    Inventaire::create([
        'personnage_id' => $ctx['heros']->id,
        'objet_id' => Objet::where('nom', 'Épée courte')->value('id'),
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    $menu = menuAvecCreneaux($ctx['groupe'], $ctx['heros']);
    $option = collect($menu['options'])->firstWhere('type', 'jeter');

    expect($option)->not->toBeNull()->and($option['creneau'])->toBe('interaction');
});

it('« utiliser un objet » (objet_libre) sort en `interaction`', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    Inventaire::create([
        'personnage_id' => $ctx['heros']->id,
        'objet_id' => Objet::where('nom', 'Potion de soin')->value('id'),
        'emplacement' => 'consommable', 'quantite' => 1,
    ]);

    $menu = menuAvecCreneaux($ctx['groupe'], $ctx['heros']);
    $option = collect($menu['options'])->firstWhere('type', 'objet_libre');

    expect($option)->not->toBeNull()->and($option['creneau'])->toBe('interaction');
});

it('« Vague Montante » (style) sort en `interaction`', function () {
    // Style de l'Eau du Moine, connu dès la création (`StylesElementairesTest`).
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);

    $menu = menuAvecCreneaux($ctx['groupe'], $ctx['heros']);
    $option = collect($menu['options'])->firstWhere('id', 'style_vague');

    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('style')
        ->and($option['creneau'])->toBe('interaction');
});

it('confronte le `creneau` publié à `ResolveurTour::creneauOption()` pour le même type — dans les deux sens', function () {
    // Un Moine plutôt qu'un barbare : sa carte de style ajoute `style_vague`
    // (type `style`) à la panoplie déjà large (déplacement, attaque, jet,
    // retraite…), ce qui élargit le SENS 1 sans rien retirer au SENS 2.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $menu = menuAvecCreneaux($ctx['groupe'], $ctx['heros']);

    // SENS 1 — chaque option d'un menu RÉEL colle exactement à la fonction
    // pure : la garantie que `MenuMoteur` APPELLE `creneauOption()` plutôt que
    // d'en tenir une copie qui pourrait diverger en silence.
    expect($menu['options'])->not->toBe([]);

    foreach ($menu['options'] as $option) {
        expect($option['creneau'])->toBe(
            ResolveurTour::creneauOption((string) $option['type']),
            "option « {$option['id']} » (type {$option['type']})",
        );
    }

    // SENS 2 — la table ELLE-MÊME, indépendamment de tout menu généré : un
    // type qui existe dans les vraies options du moteur doit rendre EXACTEMENT
    // la valeur du contrat, jamais un défaut qui masquerait demain une dérive
    // du `match` de `creneauOption()` que le SENS 1 seul ne verrait pas (un
    // type absent du menu de CE test ne casserait pas le SENS 1).
    expect(ResolveurTour::creneauOption('deplacement'))->toBe('mouvement')
        ->and(ResolveurTour::creneauOption('franchissement'))->toBe('mouvement')
        ->and(ResolveurTour::creneauOption('ouvrir_porte'))->toBe('interaction')
        ->and(ResolveurTour::creneauOption('sortie'))->toBe('interaction')
        ->and(ResolveurTour::creneauOption('retraite'))->toBe('interaction')
        ->and(ResolveurTour::creneauOption('style'))->toBe('interaction')
        ->and(ResolveurTour::creneauOption('objet_libre'))->toBe('interaction')
        ->and(ResolveurTour::creneauOption('jeter'))->toBe('interaction')
        ->and(ResolveurTour::creneauOption('concentration'))->toBe('tour')
        ->and(ResolveurTour::creneauOption('relever'))->toBe('tour')
        ->and(ResolveurTour::creneauOption('attente'))->toBe('tour')
        ->and(ResolveurTour::creneauOption('attaque'))->toBe('action')
        ->and(ResolveurTour::creneauOption('sort'))->toBe('action');
});
