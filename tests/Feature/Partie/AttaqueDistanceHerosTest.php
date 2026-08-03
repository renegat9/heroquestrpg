<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Quete;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Attaque à distance des HÉROS (Arbalète, ObjetSeeder `effet.portee: distance`,
 * `inutilisable_adjacent`) : jusqu'ici seuls monstres/mercenaires tiraient à
 * distance (3.4) — un héros armé d'une arme à distance peut désormais viser un
 * monstre non adjacent en ligne de vue dégagée. Tir précis (nœud elfe) ajoute
 * +1 dé d'attaque sur un tir véritable (non adjacent).
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

function equipeArbalete(App\Models\Personnage $p): Inventaire
{
    $objet = Objet::where('nom', 'Arbalète')->firstOrFail();
    $ligne = Inventaire::create([
        'personnage_id' => $p->id, 'objet_id' => $objet->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);

    return $ligne;
}

/** Repositionne le premier monstre actif sur une case à distance (>1) en ligne de vue du héros. */
function placerMonstreADistance(Quete $quete, InstanceMonstre $instance, int $hx, int $hy): array
{
    $grille = Grille::depuisCarte($quete->carte);

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true) || abs($x - $hx) + abs($y - $hy) < 2) {
                continue;
            }
            if ($grille->ligneDeVue($hx, $hy, $x, $y)) {
                $instance->update(['position_x' => $x, 'position_y' => $y]);

                return ['x' => $x, 'y' => $y];
            }
        }
    }

    throw new RuntimeException('Aucune case à distance avec ligne de vue trouvée.');
}

it('un héros armé d\'une Arbalète attaque un monstre non adjacent en ligne de vue', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    equipeArbalete($ctx['heros']);
    $spot = placerMonstreADistance($ctx['quete'], $ctx['instance'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];
    expect(collect($menu['options'])->firstWhere('id', "attaquer_{$ctx['instance']->id}"))->not->toBeNull();

    desFiges(array_fill(0, 20, 4)); // boucliers partout : combat neutre

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$ctx['instance']->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.portee', 'distance');

    expect($ctx['instance']->fresh())->not->toBeNull(); // aucune erreur d'adjacence
});

it('refuse le tir sans ligne de vue dégagée (figure interposée)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    equipeArbalete($ctx['heros']);
    $hx = (int) $ctx['etatHeros']->position_x;
    $hy = (int) $ctx['etatHeros']->position_y;

    $cases = $ctx['quete']->carte->grille['cases'];
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

    $ctx['instance']->update(['position_x' => $spot['x'], 'position_y' => $spot['y']]);
    App\Models\InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => App\Models\Monstre::where('nom_base', 'Orque')->value('id'),
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 0,
        'position_x' => $inter['x'], 'position_y' => $inter['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$ctx['instance']->id}"])
        ->assertStatus(422);
});

it('refuse d\'attaquer un monstre non adjacent SANS arme à distance équipée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    placerMonstreADistance($ctx['quete'], $ctx['instance'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    // Menu périmé forçant l'option (situation hors ligne de mire du menu moteur).
    Cache::put(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id), [
        'personnage_id' => $ctx['heros']->id,
        'menu' => ['options' => [[
            'id' => "attaquer_{$ctx['instance']->id}", 'libelle' => 'Attaquer', 'type' => 'attaque', 'cible_id' => $ctx['instance']->id,
        ]]],
    ], now()->addMinutes(60));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$ctx['instance']->id}"])
        ->assertStatus(422);
});

it('refuse d\'utiliser l\'Arbalète au corps-à-corps (inutilisable_adjacent)', function () {
    // demarrerQueteAvecMonstre place le monstre AU CONTACT.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    equipeArbalete($ctx['heros']);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$ctx['instance']->id}"])
        ->assertStatus(422);
});

it('Tir précis (+1 dé) s\'applique sur un tir à distance véritable, jamais au corps-à-corps', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'elfe', 'niveau' => 2, 'des_attaque' => 2]);
    equipeArbalete($ctx['heros']);
    placerMonstreADistance($ctx['quete'], $ctx['instance'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $ctx['heros']->id,
        'competence_id' => Competence::where('classe', 'elfe')->where('nom', 'Tir précis')->value('id'),
    ])->assertCreated();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$ctx['instance']->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.bonus_tir_precis', 1)
        ->assertJsonPath('resultat.portee', 'distance')
        // Ligne d'inventaire créée directement (hors Equipement::equiper) : le
        // delta de l'objet n'est pas sur la colonne, seul le bonus du nœud compte.
        ->assertJsonPath('resultat.des_attaque_effectifs', 3); // 2 base + 1 Tir précis
});

it('lance une hache à main sur une cible à distance, et la PERD', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain']);
    $hero = $ctx['heros'];

    // Le nain troque tout pour la hache à main (jetable, perdue au lancer).
    $hero->inventaire()->delete();
    $hache = Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => App\Models\Objet::where('nom', 'Hachette')->firstOrFail()->id,
        'emplacement' => 'arme_principale',
        'quantite' => 1,
    ]);
    app(App\Partie\Equipement::class)->recalculerCombat($hero->refresh());
    expect($hero->fresh()->des_attaque)->toBe(2);

    placerMonstreADistance($ctx['quete'], $ctx['instance'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'])->pluck('id');
    expect($ids)->toContain("lancer_{$ctx['instance']->id}");

    desFiges(array_fill(0, 20, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "lancer_{$ctx['instance']->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'attaque')
        ->assertJsonPath('resultat.lancer.arme', 'Hachette')
        ->assertJsonPath('resultat.lancer.perdue', true);

    // L'arme quitte la main POUR DE BON : le héros retombe à mains nues.
    expect(Inventaire::whereKey($hache->id)->exists())->toBeFalse()
        ->and($hero->fresh()->des_attaque)->toBe(1);
});
