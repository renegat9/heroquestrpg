<?php

declare(strict_types=1);

use App\Models\Condition;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Partie\Grille;
use App\Partie\MoteurDread;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Les mots-clés de capacité de *Jungles of Delthrak* (livret p. 48-49, règles
 * citées dans `reference/18_extensions.md`), portés le 2026-08-10.
 *
 * Un trait au catalogue ne prouve rien : `BestiaireSourceTest` vérifie qu'ils
 * sont DÉCLARÉS, ce fichier vérifie qu'ils AGISSENT. C'est la distinction que
 * le projet paie cher quand il l'oublie — `attaque_second_rang`, `jetable`, la
 * Potion d'héroïsme injouable.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

// ---------------------------------------------------------------------------
// Agile — « ignore terrain gênant, mobilier et héros en se déplaçant »
// ---------------------------------------------------------------------------

it('ouvre le chemin au mobilier ET aux figures, sans ouvrir les murs', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $grille = Grille::depuisCarte($ctx['quete']->carte);

    // Une case de sol libre, et son voisin, pour y poser un obstacle.
    $cases = $ctx['quete']->carte->grille['cases'];
    [$x, $y] = [null, null];
    foreach ($cases as $j => $ligne) {
        foreach ($ligne as $i => $c) {
            if (in_array($c, ['s', 'p'], true)
                && in_array($cases[$j][$i + 1] ?? 'm', ['s', 'p'], true)
                && in_array($cases[$j][$i + 2] ?? 'm', ['s', 'p'], true)) {
                [$x, $y] = [$i, $j];
                break 2;
            }
        }
    }
    expect($x)->not->toBeNull('Pas d\'alignement de 3 cases de sol.');

    // Un meuble ET une figure sur la case voisine. On vise CETTE case : un
    // trajet plus long serait contourné par le BFS et ne prouverait rien.
    $grille->obstruer([['x' => $x + 1, 'y' => $y]]);
    $grille->occuper([['x' => $x + 1, 'y' => $y]]);
    expect($grille->chemin($x, $y, $x + 1, $y))->toBeNull();

    // Agile : mobilier et figures cessent de barrer.
    $grille->autoriserFranchissement();
    expect($grille->chemin($x, $y, $x + 1, $y))->not->toBeNull();

    // …mais pas les murs : une case hors carte reste inatteignable.
    expect($grille->chemin($x, $y, -1, -1))->toBeNull();
});

// ---------------------------------------------------------------------------
// Racines entravantes — « le mouvement du héros est stoppé net »
// ---------------------------------------------------------------------------

it('arrête le héros SUR la première case adjacente au gardien', function () {
    $ctx = demarrerQueteAvecMonstre('Crâne putride');
    $etat = $ctx['etatHeros'];
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Un couloir de 3 cases droit devant, gardien collé à la 2ᵉ.
    $cases = $ctx['quete']->carte->grille['cases'];
    $sol = fn ($x, $y) => in_array($cases[$y][$x] ?? 'm', ['s', 'p'], true);

    $axe = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if ($sol($hx + $dx, $hy + $dy) && $sol($hx + 2 * $dx, $hy + 2 * $dy) && $sol($hx + 3 * $dx, $hy + 3 * $dy)) {
            $axe = [$dx, $dy];
            break;
        }
    }
    expect($axe)->not->toBeNull('Pas de couloir droit de 3 cases.');
    [$dx, $dy] = $axe;

    // Le gardien se place PERPENDICULAIREMENT à la 2ᵉ case, pour être adjacent
    // au trajet sans le bloquer physiquement.
    $ctx['instance']->update([
        'position_x' => $hx + 2 * $dx + $dy,
        'position_y' => $hy + 2 * $dy + $dx,
    ]);

    if (! $sol((int) $ctx['instance']->position_x, (int) $ctx['instance']->position_y)) {
        $this->markTestSkipped('Géométrie de carte défavorable à ce tirage.');
    }

    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => 6]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => $hx + 3 * $dx, 'y' => $hy + 3 * $dy],
    ])->assertStatus(202);

    // Il visait la 3ᵉ case ; les racines l'ont saisi. On n'exige pas une case
    // précise — le BFS choisit sa route — mais la RÈGLE : il n'est pas arrivé,
    // et il s'est arrêté AU CONTACT du gardien (dessus, pas avant : s'arrêter
    // une case plus tôt l'empêcherait de frapper la créature).
    $arrive = $etat->fresh();
    $gardien = $ctx['instance']->fresh();

    expect([(int) $arrive->position_x, (int) $arrive->position_y])
        ->not->toBe([$hx + 3 * $dx, $hy + 3 * $dy], 'les racines n\'ont pas arrêté le héros')
        ->and(abs((int) $gardien->position_x - (int) $arrive->position_x)
            + abs((int) $gardien->position_y - (int) $arrive->position_y))
        ->toBe(1, 'le héros doit s\'arrêter AU CONTACT du gardien');
});

// ---------------------------------------------------------------------------
// Venimeux — « paralysie, sauf 5-6 sur un dé rouge »
// ---------------------------------------------------------------------------

it('paralyse sur un jet raté, et le héros ne peut plus bouger', function () {
    $ctx = demarrerQueteAvecMonstre('Serpent géant');
    $heros = $ctx['heros'];

    // 1 → le jet de résistance échoue (il faut 5 ou 6). Le moteur est résolu
    // APRÈS `desFiges` : il capture le lanceur à sa construction.
    desFiges([1]);
    expect(app(MoteurDread::class)->appliquerVenin($ctx['instance'], $heros))->toBeTrue();

    $envenime = Condition::where('nom', 'Envenimé')->firstOrFail();
    expect($heros->fresh()->conditions()->whereKey($envenime->id)->exists())->toBeTrue();

    // `deplacement_interdit` était une clé de catalogue SANS lecteur : un héros
    // « immobilisé » marchait comme si de rien n'était. Elle mord désormais.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => (int) $ctx['etatHeros']->position_x, 'y' => (int) $ctx['etatHeros']->position_y + 1],
    ])->assertStatus(422);
});

it('laisse passer sur 5 ou 6, et ne s\'applique pas aux créatures sans venin', function () {
    $ctx = demarrerQueteAvecMonstre('Serpent géant');

    // ⚠ Le moteur capture le lanceur À SA CONSTRUCTION : il faut le résoudre
    // APRÈS avoir figé les dés, sinon on éprouve l'ancienne file.
    foreach ([5, 6] as $jet) {
        desFiges([$jet]);
        expect(app(MoteurDread::class)->appliquerVenin($ctx['instance'], $ctx['heros']))
            ->toBeFalse("un {$jet} doit résister");
    }

    // Un gobelin n'a pas de venin : aucun dé n'est même lancé (file vide).
    $ctx['instance']->update(['monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id]);
    desFiges([]);
    expect(app(MoteurDread::class)->appliquerVenin($ctx['instance']->fresh()->load('monstre'), $ctx['heros']))
        ->toBeFalse();
});

// ---------------------------------------------------------------------------
// Tacticien — « +1 dé contre une cible flanquée par un autre monstre »
// ---------------------------------------------------------------------------

it('ne voit un flanc que s\'il y a un SECOND monstre au contact', function () {
    $ctx = demarrerQueteAvecMonstre('Raptor');
    $dread = app(MoteurDread::class);
    $etat = $ctx['etatHeros'];

    // Seul au contact : le raptor n'est pas son propre flanc.
    expect($dread->cibleFlanquee($ctx['quete'], $ctx['instance'], $etat))->toBeFalse();

    // Un complice sur une autre case adjacente au héros.
    $libre = caseAdjacenteLibre($ctx['quete'], (int) $etat->position_x, (int) $etat->position_y);
    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 1, 'pv_mind' => 1,
        'position_x' => $libre['x'], 'position_y' => $libre['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    expect($dread->cibleFlanquee($ctx['quete'], $ctx['instance'], $etat->fresh()))->toBeTrue();
});

it('ignore un monstre VAINCU ou non révélé pour le flanc', function () {
    $ctx = demarrerQueteAvecMonstre('Raptor');
    $etat = $ctx['etatHeros'];
    $libre = caseAdjacenteLibre($ctx['quete'], (int) $etat->position_x, (int) $etat->position_y);

    $complice = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 1, 'pv_mind' => 1,
        'position_x' => $libre['x'], 'position_y' => $libre['y'],
        'etat' => 'vaincu', 'revele' => true,
    ]);

    $dread = app(MoteurDread::class);
    expect($dread->cibleFlanquee($ctx['quete'], $ctx['instance'], $etat))->toBeFalse();

    // Un dormant derrière une porte jamais ouverte ne flanque personne non plus.
    $complice->update(['etat' => 'actif', 'revele' => false]);
    expect($dread->cibleFlanquee($ctx['quete'], $ctx['instance'], $etat))->toBeFalse();
});
