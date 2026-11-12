<?php

declare(strict_types=1);

use App\Engine\ReactionEffet;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\TropheesArene;
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
use Illuminate\Support\Facades\Cache;

/**
 * MODE ARÈNE, lot T4 (seconde étape) — les tuiles de COMBAT, la RIPOSTE et la potion « avant de mourir », des deux
 * côtés (F9528 p. 14 ; usage du Defender décidé par René le 2026-11-09).
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    $this->joueur = connecterJoueur();
});

/**
 * Un barbare d'arène, `$monstre` à son contact, des réserves posées à la main, plus aucune tuile au sol.
 *
 * @return array{0: App\Models\Groupe, 1: Quete, 2: Personnage, 3: EtatPersonnageQuete, 4: InstanceMonstre}
 */
function duelTrophees(string $monstre, array $challenger = [], array $defender = []): array
{
    $heros = Personnage::findOrFail(test()->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $doublure = $arenes->engager($arene, $heros);
    $quete = app(DemarreurArene::class)->demarrer($arene, [(int) Monstre::where('nom_base', $monstre)->value('id')]);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $doublure->id)->firstOrFail();
    $instance = InstanceMonstre::where('quete_id', $quete->id)->with('monstre')->firstOrFail();

    $instance->update(['position_x' => $etat->position_x + 1, 'position_y' => $etat->position_y, 'active_ce_round' => true]);
    $quete->update(['trophees_arene' => ['cases' => [], 'challenger' => $challenger, 'defender' => $defender]]);
    $doublure->refresh();

    foreach ($arene->personnages()->pluck('joueur_id')->unique() as $joueurId) {
        Cache::forget(App\Jobs\GenererMenu::cleMenu($arene->id, (int) $joueurId));
    }

    return [$arene->refresh(), $quete->refresh(), $doublure, $etat->refresh(), $instance->refresh()];
}

function reserveCombat(Quete $quete, string $camp): array
{
    return app(TropheesArene::class)->reserve($quete->fresh(), $camp);
}

it('CRÂNE du Challenger : offert sur un jet qui en profite, la réponse rejoue l\'attaque avec le crâne', function () {
    [$arene, $quete, $doublure, $etat, $momie] = duelTrophees('Momie', challenger: ['crane']);
    $resolveur = app(App\Partie\ResolveurTour::class);
    $pvAvant = (int) $momie->pv_body;

    // 1 crâne et 2 ratés ; la momie ne sort que des boucliers BLANCS (inutiles à un monstre) : 1 dégât sur 2 PV, le coup
    // n'est pas gagné — le crâne de la tuile l'achève.
    desFiges([1, 6, 6, 4, 4, 4, 4, 4, 4, 4]);
    $menu = collect($this->getJson("/api/groupes/{$arene->identifiant}/menu")->json('menu.options'));
    $attaquer = $menu->firstWhere('type', 'attaque');
    expect($attaquer)->not->toBeNull();
    $cible = collect($attaquer['parametres']['cibles'] ?? [])->first();
    $parametres = array_filter(['cible_id' => $cible['id'] ?? $momie->id, 'cle' => $cible['cle'] ?? null]);

    $r = $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => $attaquer['id'], 'parametres' => $parametres])
        ->assertSuccessful()->json('resultat');

    expect($r['type'])->toBe('jet_en_attente')
        ->and($etat->fresh()->reaction_en_attente['action'])->toBe(ReactionEffet::TROPHEE_CRANE)
        ->and((int) $momie->fresh()->pv_body)->toBe($pvAvant);

    $this->postJson("/api/groupes/{$arene->identifiant}/reaction", ['accepte' => true, 'personnage_id' => $doublure->id])->assertSuccessful();

    expect((int) $momie->fresh()->pv_body)->toBe(max(0, $pvAvant - 2))
        ->and(reserveCombat($quete, TropheesArene::CHALLENGER))->toBe([]);
});

it('BOUCLIER BLANC du Challenger : le coup du monstre est réduit, le héros tombé se relève', function () {
    [$arene, $quete, $doublure, $etat, $orque] = duelTrophees('Orque', challenger: ['bouclier_blanc']);
    $doublure->update(['pv_body' => 1]);

    app(MoteurDegats::class)->infligerAHeros($doublure->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, ['instance_id' => $orque->id, 'monstre' => 'Orque']);
    // La chute est posée par le résolveur après le coup ; ici, à la main.
    $etat->update(['tombe' => true]);
    expect($etat->fresh()->reaction_en_attente['action'])->toBe(ReactionEffet::TROPHEE_BOUCLIER);

    $this->postJson("/api/groupes/{$arene->identifiant}/reaction", ['accepte' => true, 'personnage_id' => $doublure->id])->assertSuccessful();

    expect((int) $doublure->fresh()->pv_body)->toBe(1)
        ->and($etat->fresh()->tombe)->toBeFalse()
        ->and(reserveCombat($quete, TropheesArene::CHALLENGER))->toBe([]);
});

it('RIPOSTE du Challenger : offerte même en diagonale, elle frappe l\'assaillant et la tuile part', function () {
    [$arene, $quete, $doublure, $etat, $orque] = duelTrophees('Orque', challenger: ['riposte']);
    $orque->update(['position_x' => $etat->position_x + 1, 'position_y' => $etat->position_y + 1]);

    app(MoteurDegats::class)->infligerAHeros($doublure->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, ['instance_id' => $orque->id, 'monstre' => 'Orque']);
    $offre = $etat->fresh()->reaction_en_attente;
    expect($offre['action'])->toBe(ReactionEffet::RIPOSTE)
        ->and($offre['trophee'])->toBe('riposte');

    desFiges(array_fill(0, 50, 1)); // tout crâne : l'orque (1 PV) ne pare rien
    $this->postJson("/api/groupes/{$arene->identifiant}/reaction", ['accepte' => true, 'personnage_id' => $doublure->id])->assertSuccessful();

    expect($orque->fresh()->etat)->toBe('vaincu')
        ->and(reserveCombat($quete, TropheesArene::CHALLENGER))->toBe([]);
});

it('POTION du Challenger à 0 PV : proposée parmi les soins d\'urgence, elle relève le héros', function () {
    [$arene, $quete, $doublure, $etat, $orque] = duelTrophees('Orque', challenger: ['potion']);
    $doublure->update(['pv_body' => 1]);

    app(MoteurDegats::class)->infligerAHeros($doublure->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, ['instance_id' => $orque->id, 'monstre' => 'Orque']);
    $offre = $etat->fresh()->reaction_en_attente;
    expect($offre['action'])->toBe(ReactionEffet::SOIN_URGENCE)
        ->and(collect($offre['soins'])->pluck('cle')->all())->toContain('trophee:potion');
    $etat->update(['tombe' => true]);

    $this->postJson("/api/groupes/{$arene->identifiant}/reaction", ['accepte' => true, 'soin' => 'trophee:potion', 'personnage_id' => $doublure->id])->assertSuccessful();

    expect((int) $doublure->fresh()->pv_body)->toBe(2)
        ->and($etat->fresh()->tombe)->toBeFalse()
        ->and(reserveCombat($quete, TropheesArene::CHALLENGER))->toBe([]);
});

it('DEFENDER : bouclier noir quand le coup le blesse, crâne quand son coup n\'est pas déjà gagné', function () {
    [$arene, $quete] = duelTrophees('Momie', defender: ['bouclier_noir', 'crane']);
    $trophees = app(TropheesArene::class);

    // Défense : le coup ne blesse pas → rien ; il blesse → un bouclier noir.
    expect($trophees->boucliersDuDefender($arene, $quete, 'Momie', 0))->toBe(0)
        ->and($trophees->boucliersDuDefender($arene, $quete, 'Momie', 1))->toBe(1);

    // Attaque : déjà gagnée (la cible tombe) → rien ; 1 crâne contre 1 bouclier → +1 crâne.
    expect($trophees->cranesDuDefender($arene, $quete, 'Momie', 3, 0, 3, 3))->toBe(0)
        ->and($trophees->cranesDuDefender($arene, $quete, 'Momie', 1, 1, 0, 8))->toBe(1)
        ->and(reserveCombat($quete, TropheesArene::DEFENDER))->toBe([]);
});

it('DEFENDER : la figure tombée à 0 boit sa potion avant de mourir', function () {
    [$arene, $quete, $doublure, , $momie] = duelTrophees('Momie', defender: ['potion']);

    $issue = app(MoteurDegats::class)->infligerAMonstre($momie, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $doublure);

    expect($issue['vaincu'])->toBeFalse()
        ->and((int) $momie->fresh()->pv_body)->toBe(min(2, $momie->pvBodyMax()))
        ->and($momie->fresh()->etat)->toBe('actif')
        ->and(reserveCombat($quete, TropheesArene::DEFENDER))->toBe([]);
});

it('DEFENDER : la figure blessée au contact riposte aussitôt', function () {
    [$arene, $quete, $doublure, $etat, $momie] = duelTrophees('Momie', defender: ['riposte']);
    $pvHeros = (int) $doublure->pv_body;

    desFiges(array_fill(0, 80, 1)); // tout crâne : chaque coup porte
    $menu = collect($this->getJson("/api/groupes/{$arene->identifiant}/menu")->json('menu.options'));
    $attaquer = $menu->firstWhere('type', 'attaque');
    $cible = collect($attaquer['parametres']['cibles'] ?? [])->first();
    $parametres = array_filter(['cible_id' => $cible['id'] ?? $momie->id, 'cle' => $cible['cle'] ?? null]);
    $r = $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => $attaquer['id'], 'parametres' => $parametres])
        ->assertSuccessful()->json('resultat');

    expect($r['riposte_defender'] ?? null)->not->toBeNull()
        ->and((int) $doublure->fresh()->pv_body)->toBeLessThan($pvHeros)
        ->and(reserveCombat($quete, TropheesArene::DEFENDER))->toBe([]);
});
