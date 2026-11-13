<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use App\Partie\MoteurDegats;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\DB;

/**
 * MODE ARÈNE, lot A6 — le palmarès et les statistiques par héros (`docs/plan-mode-arene.md` §5, AQ4) : combats,
 * victoires, ennemis vaincus par NOM DE CATALOGUE au coup de grâce, crédités au héros SOURCE — jamais à la doublure,
 * jamais à la campagne.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    $this->joueur = connecterJoueur();
});

/** Un combat lancé : un héros d'arène du joueur connecté contre `$defender`. */
function combatPourPalmares(array $defender): array
{
    $heros = Personnage::findOrFail(test()->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $doublure = $arenes->engager($arene, $heros);
    $quete = app(DemarreurArene::class)->demarrer($arene, array_map(fn ($n) => (int) Monstre::where('nom_base', $n)->value('id'), $defender));

    return [$arene->refresh(), $quete->refresh(), $heros, $doublure];
}

it('le coup de grâce et la victoire sont crédités au héros SOURCE, par nom de catalogue', function () {
    [$arene, $quete, $heros, $doublure] = combatPourPalmares(['Gobelin', 'Gobelin', 'Orque']);
    $degats = app(MoteurDegats::class);

    // Les trois figures tombent sous les coups de la doublure (habillage posé : le palmarès lit le nom de CATALOGUE).
    foreach (InstanceMonstre::where('quete_id', $quete->id)->get() as $instance) {
        $instance->update(['habillage' => ['nom' => 'Le Rôdeur']]);
        $degats->infligerAMonstre($instance->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $doublure);
    }

    $this->getJson("/api/groupes/{$arene->identifiant}/menu")->assertOk();
    $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => 'attendre'])->assertSuccessful();
    expect($quete->refresh()->etat)->toBe('terminee');

    $stats = collect($this->getJson('/api/moi')->json('joueur.personnages'))->firstWhere('id', $heros->id)['arene'];
    expect($stats['combats'])->toBe(1)
        ->and($stats['victoires'])->toBe(1)
        ->and($stats['ennemis_vaincus'])->toBe([
            ['nom_base' => 'Gobelin', 'nombre' => 2],
            ['nom_base' => 'Orque', 'nombre' => 1],
        ])
        // Jamais de ligne pour la doublure.
        ->and(DB::table('arene_statistiques')->where('personnage_id', $doublure->id)->exists())->toBeFalse();

    $combat = $this->getJson('/api/arene/palmares')->assertOk()->json('combats.0');
    expect($combat['issue'])->toBe('victoire')
        ->and($combat['combattants'])->toBe([['heros_id' => $heros->id, 'nom' => 'Brakka', 'classe' => 'barbare']])
        ->and($combat['defender'])->toBe(['Gobelin', 'Gobelin', 'Orque'])
        ->and($combat['rounds'])->toBeGreaterThanOrEqual(1);
});

it('une défaite compte un combat, sans victoire', function () {
    [$arene, $quete, $heros, $doublure] = combatPourPalmares(['Orque']);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $doublure->id)->first();
    $doublure->update(['pv_body' => 1]);
    InstanceMonstre::where('quete_id', $quete->id)->update([
        'position_x' => $etat->position_x + 1, 'position_y' => $etat->position_y, 'active_ce_round' => false,
    ]);
    desFiges(array_fill(0, 200, 1));

    $this->getJson("/api/groupes/{$arene->identifiant}/menu")->assertOk();
    // LA LETTRE (2026-11-12) : le héros seul manœuvre d'abord ; il passe, puis son activation appelle la riposte de l'Orque.
    $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => 'attendre'])->assertSuccessful();
    $this->getJson("/api/groupes/{$arene->identifiant}/menu")->assertOk();
    $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => 'attendre'])->assertSuccessful();
    expect($quete->refresh()->etat)->toBe('echouee');

    $stats = collect($this->getJson('/api/moi')->json('joueur.personnages'))->firstWhere('id', $heros->id)['arene'];
    expect($stats)->toBe(['combats' => 1, 'victoires' => 0, 'ennemis_vaincus' => []])
        ->and($this->getJson('/api/arene/palmares')->json('combats.0.issue'))->toBe('defaite');
});

it('un héros de campagne n\'a pas de statistiques d\'arène, et la campagne n\'écrit jamais au palmarès', function () {
    $this->seed([Database\Seeders\GabaritQueteSeeder::class, Database\Seeders\TuileSeeder::class]);
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    app(MoteurDegats::class)->infligerAMonstre($ctx['instance'], 99, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['heros']);

    expect(DB::table('arene_statistiques')->count())->toBe(0)
        ->and(DB::table('arene_combats')->count())->toBe(0)
        ->and(collect($this->getJson('/api/moi')->json('joueur.personnages'))->firstWhere('id', $ctx['heros']->id)['arene'])->toBeNull();
});
