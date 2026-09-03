<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Monstres à distance (Phase 2, 3.4) : avec ligne de vue, le monstre TIRE sans
 * avoir besoin d'être adjacent (dés `attaque_distance`) ; au contact il frappe
 * en corps-à-corps (dés de mêlée, moindres). Dés tous à 4 → aucun dégât, MODE
 * déterministe (on vérifie la portée, pas les dégâts).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

it('tire à distance sur un héros en ligne de vue, sans adjacence', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin archer');
    ['quete' => $quete, 'instance' => $archer, 'etatHeros' => $etatHeros] = $ctx;

    $hx = (int) $etatHeros->position_x;
    $hy = (int) $etatHeros->position_y;

    // Repositionne l'archer sur une case à distance (>1) avec ligne de vue dégagée.
    $grille = Grille::depuisCarte($quete->carte);
    $spot = null;
    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true)) {
                continue;
            }
            if (abs($x - $hx) + abs($y - $hy) < 2) {
                continue; // même case ou adjacent
            }
            if ($grille->ligneDeVue($hx, $hy, $x, $y)) {
                $spot = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }
    expect($spot)->not->toBeNull('Aucune case à distance avec ligne de vue sur la carte.');

    $archer->update(['position_x' => $spot['x'], 'position_y' => $spot['y']]);

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'attaque_monstre');

    expect($attaque)->not->toBeNull()
        ->and($attaque['portee'])->toBe('distance');

    // L'archer n'a PAS eu besoin de se coller au héros : il est resté à distance.
    $archer->refresh();
    expect(abs((int) $archer->position_x - $hx) + abs((int) $archer->position_y - $hy))
        ->toBeGreaterThan(1);
});

it('ne tire PAS sur un héros caché derrière une figure interposée (#7)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin archer');
    ['quete' => $quete, 'instance' => $archer, 'etatHeros' => $etatHeros] = $ctx;
    $hx = (int) $etatHeros->position_x;
    $hy = (int) $etatHeros->position_y;

    // Isole la scène : seul l'archer (+ le bloqueur ci-dessous) est actif.
    $quete->instancesMonstres()->whereKeyNot($archer->id)->update(['etat' => 'vaincu']);

    // Alignement DROIT sol : héros — [case intermédiaire] — archer (distance 2).
    $cases = $quete->carte->grille['cases'];
    $sol = fn ($x, $y) => in_array($cases[$y][$x] ?? 'm', ['s', 'p'], true);
    $trio = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if ($sol($hx + $dx, $hy + $dy) && $sol($hx + 2 * $dx, $hy + 2 * $dy)) {
            $trio = [['x' => $hx + $dx, 'y' => $hy + $dy], ['x' => $hx + 2 * $dx, 'y' => $hy + 2 * $dy]];
            break;
        }
    }
    expect($trio)->not->toBeNull('Pas d\'alignement droit sol pour le scénario.');
    [$inter, $spot] = $trio;

    $archer->update(['position_x' => $spot['x'], 'position_y' => $spot['y']]);
    // Figure INTERPOSÉE (un monstre) entre l'archer et le héros.
    InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => Monstre::where('nom_base', 'Orque')->value('id'),
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 0,
        'position_x' => $inter['x'], 'position_y' => $inter['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    desFiges(array_fill(0, 200, 4));
    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // ⚠ Ce que ce test protège est la RÈGLE — une figure interposée coupe la
    // ligne de tir —, pas l'immobilité de l'archer. Depuis le 2026-08-23, sans
    // ligne de mire il va en CHERCHER une (doc 09 §5) au lieu de refermer la
    // distance : il peut donc tirer, mais jamais depuis la case bloquée.
    $actions = collect($reponse->json('resultat.tour_monstres.actions'));
    $tir = $actions->first(fn ($a) => ($a['type'] ?? null) === 'attaque_monstre' && ($a['portee'] ?? null) === 'distance');
    $apres = $archer->fresh();

    if ($tir !== null) {
        expect([(int) $apres->position_x, (int) $apres->position_y])
            ->not->toBe([$spot['x'], $spot['y']], 'il a tiré À TRAVERS la figure interposée');

        return;
    }

    // Sinon il s'est rapproché, l'ancien comportement — tout aussi légitime.
    expect($actions)->not->toBeEmpty();
});

it('au contact, il RECULE pour tirer — et ne frappe en mêlée que s\'il ne peut pas', function () {
    // ⚠ Réécrit le 2026-08-23 : ce test figeait l'ancien comportement (rester
    // collé et frapper à 1 dé). La règle qu'il protège reste vraie — AU CONTACT
    // on utilise les dés de mêlée — mais l'archer cherche désormais à ne pas y
    // être (doc 09 §5). Les deux issues sont légitimes, la position tranche.
    $ctx = demarrerQueteAvecMonstre('Gobelin archer'); // placé AU CONTACT
    $depart = [(int) $ctx['instance']->position_x, (int) $ctx['instance']->position_y];

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'attaque_monstre');
    $apres = $ctx['instance']->fresh();
    $aBouge = [(int) $apres->position_x, (int) $apres->position_y] !== $depart;

    expect($attaque)->not->toBeNull()
        ->and($attaque['portee'])->toBe($aBouge ? 'distance' : 'corps_a_corps');
});

it('vise le héros le PLUS FAIBLE, pas un ordre arbitraire', function () {
    // ⚠ Même défaut que celui corrigé dans `MoteurDread` le 2026-09-02 :
    // `sortBy([$f, $g])` traite chaque callable comme un COMPARATEUR `$f($a, $b)`,
    // pas comme une extraction de clé — une closure à un paramètre y rendait les
    // PV de `$a` en résultat de comparaison, toujours positifs. Sur DEUX cibles,
    // la version boguée rend la SECONDE, quelle qu'elle soit.
    //
    // ⚠ Le montage n'est concluant que si le héros à ACHEVER est le PREMIER de la
    // collection (`etatsPersonnages()`, ordre des id) : monté dans l'autre sens il
    // passe aussi sur le code bogué, et ne prouve donc rien. Vérifié dans les deux
    // états du code, et l'ordre est asserté plus bas pour qu'il ne dérive pas.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $faible = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $robuste = creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    // Un seul monstre en scène : l'archer.
    $archer = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($archer->id)->update(['etat' => 'vaincu']);
    $catalogue = Monstre::where('nom_base', 'Gobelin archer')->firstOrFail();
    $archer->update([
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind, 'etat' => 'actif', 'elite' => false,
    ]);
    $archer->refresh()->load('monstre');

    $etatFaible = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $faible->id)->firstOrFail();
    $etatRobuste = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $robuste->id)->firstOrFail();

    expect($etatFaible->id)->toBeLessThan($etatRobuste->id, 'le héros à achever doit être le PREMIER de la collection');

    $faible->update(['pv_body' => 1]);
    $robuste->update(['pv_body' => 8]);

    // Case de tir : à distance (>1) des DEUX héros, avec ligne de vue sur les
    // deux — sans contact ni angle mort, `replacerTireur()` rend null et l'archer
    // tire d'où il est, donc c'est bien le TRI qui tranche.
    $grille = FabriqueGrille::pour($quete, exceptInstanceId: $archer->id);
    $loin = fn (EtatPersonnageQuete $e, int $x, int $y): bool => abs($x - (int) $e->position_x) + abs($y - (int) $e->position_y) > 1;
    $voit = fn (EtatPersonnageQuete $e, int $x, int $y): bool => $grille->ligneDeVue($x, $y, (int) $e->position_x, (int) $e->position_y, figuresBloquent: true);

    $spot = null;
    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $type) {
            $x = (int) $x;
            $y = (int) $y;

            if (! $grille->estTraversable($x, $y) || ! caseQueteLibre($quete, $x, $y)) {
                continue;
            }
            if ($loin($etatFaible, $x, $y) && $loin($etatRobuste, $x, $y)
                && $voit($etatFaible, $x, $y) && $voit($etatRobuste, $x, $y)) {
                $spot = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }

    expect($spot)->not->toBeNull('Aucune case voyant les DEUX héros à distance sur cette carte.');
    $archer->update(['position_x' => $spot['x'], 'position_y' => $spot['y']]);

    desFiges(array_fill(0, 200, 4)); // que des boucliers blancs : on teste la CIBLE, pas les dégâts

    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
    $reponse = test()->actingAs($bob, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'attaque_monstre');

    expect($attaque)->not->toBeNull()
        ->and($attaque['cible']['personnage_id'])->toBe($faible->id);
});
