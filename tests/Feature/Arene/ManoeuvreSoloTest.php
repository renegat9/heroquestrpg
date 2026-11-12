<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Evenement as LigneJournal;
use App\Partie\Arene\ManoeuvreSolo;
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

/**
 * MODE ARÈNE, lot T5 — le COMBATTANT SEUL (livret F9528 p. 16) : « In addition to their own turn, they may take a basic
 * maneuver after each opponent's turn » — se déplacer jusqu'à son mouvement OU lancer 2 dés d'attaque contre une
 * créature au contact. Les deux camps, par les vraies routes (`combatADeux()` et `jouerEnArene()` d'ActivationAlterneeTest).
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

/** Toutes les volées d'attaque (`faces_attaque`) d'une réponse, à n'importe quelle profondeur. */
function voleesDe(array $donnees): array
{
    $volees = [];

    $parcourir = function (array $noeud) use (&$parcourir, &$volees): void {
        if (isset($noeud['faces_attaque']) && is_array($noeud['faces_attaque'])) {
            $volees[] = $noeud['faces_attaque'];
        }

        foreach ($noeud as $enfant) {
            if (is_array($enfant)) {
                $parcourir($enfant);
            }
        }
    };
    $parcourir($donnees);

    return $volees;
}

function manoeuvresAuJournal(int $groupeId): int
{
    return LigneJournal::where('groupe_id', $groupeId)->get()
        ->filter(fn ($l) => ($l->payload['type'] ?? null) === 'manoeuvre_solo')->count();
}

it('DEFENDER SEUL : après l\'activation d\'un héros, sa dernière figure marche vers le plus proche', function () {
    $c = combatADeux(['Gobelin']);
    $gobelin = InstanceMonstre::where('quete_id', $c['quete']->id)->first();
    // Le gobelin a ouvert le round (son jeton) ; on le renvoie au fond de sa tuile, loin de tout héros.
    $tuile = app(App\Partie\Arene\HallArene::class)->tuileDeDepart(ouest: false);
    $fond = end($tuile);
    $gobelin->update(['position_x' => $fond['x'], 'position_y' => $fond['y']]);
    $avant = ['x' => $fond['x'], 'y' => $fond['y']];

    jouerEnArene($c['alice'], $c['arene']->identifiant, 'attendre')->assertSuccessful();

    $gobelin->refresh();
    expect([$gobelin->position_x, $gobelin->position_y])->not->toBe([$avant['x'], $avant['y']])
        ->and(manoeuvresAuJournal($c['arene']->id))->toBe(1)
        ->and($gobelin->active_ce_round)->toBeTrue();
});

it('DEFENDER SEUL : au contact, la manœuvre frappe à 2 dés — pas avec ses dés de catalogue', function () {
    $c = combatADeux(['Orque']);
    $quete = $c['quete'];
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['a']->id)->first();
    $orque = InstanceMonstre::where('quete_id', $quete->id)->with('monstre')->first();
    expect((int) $orque->monstre->attaque)->toBeGreaterThan(ManoeuvreSolo::DES_ATTAQUE);
    $orque->update(['position_x' => $etatA->position_x + 1, 'position_y' => $etatA->position_y]);
    desFiges(array_fill(0, 200, 4));

    $reponse = jouerEnArene($c['alice'], $c['arene']->identifiant, 'attendre')->assertSuccessful();

    expect(voleesDe($reponse->json()))->toHaveCount(1)
        ->and(voleesDe($reponse->json())[0])->toHaveCount(ManoeuvreSolo::DES_ATTAQUE);
});

it('CHALLENGER SEUL : son activation jouée, il manœuvre après CHAQUE activation du Defender', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);
    $quete = $c['quete'];
    $id = $c['arene']->identifiant;
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['b']->id)->update(['tombe' => true]);
    $etatA = fn () => EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['a']->id)->first();

    // Alice joue son activation : un gobelin répond, puis SA manœuvre s'ouvre (elle reprend la main).
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(jetonsDuDefender($quete))->toBe(2)
        ->and($etatA()->manoeuvre_arene)->toBeTrue()
        ->and($etatA()->a_joue)->toBeFalse();

    $this->actingAs($c['alice'], 'joueur');
    $menu = $this->getJson("/api/groupes/{$id}/menu")->json('menu');
    $types = collect($menu['options'])->pluck('type')->unique()->values()->all();
    expect(array_diff($types, ManoeuvreSolo::TYPES_PERMIS))->toBe([])
        ->and($types)->toContain('deplacement')
        ->and($menu['situation'])->toContain('manœuvre');

    // Une option hors manœuvre est refusée par le résolveur.
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'trophee_potion'])->assertUnprocessable();

    // Elle passe : le gobelin suivant s'active, et la manœuvre se rouvre.
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(jetonsDuDefender($quete))->toBe(3)
        ->and($etatA()->manoeuvre_arene)->toBeTrue();

    // Elle passe encore : le Defender est à court, le round se referme et se rouvre (une figure) — elle a son
    // activation à jouer, pas une manœuvre.
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(jetonsDuDefender($quete))->toBe(1)
        ->and($etatA()->manoeuvre_arene)->toBeFalse()
        ->and($etatA()->a_joue)->toBeFalse();
});

it('CHALLENGER SEUL : la frappe de manœuvre lance 2 dés contre une figure au contact, puis disparaît au premier pas', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);
    $quete = $c['quete'];
    $id = $c['arene']->identifiant;
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['b']->id)->update(['tombe' => true]);
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();

    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['a']->id)->first();
    $cible = InstanceMonstre::where('quete_id', $quete->id)->where('etat', 'actif')->where('active_ce_round', false)->first();
    $cible->update(['position_x' => $etatA->position_x + 1, 'position_y' => $etatA->position_y]);
    Illuminate\Support\Facades\Cache::forget(App\Jobs\GenererMenu::cleMenu($c['arene']->id, (int) $c['alice']->id));

    $this->actingAs($c['alice'], 'joueur');
    $menu = $this->getJson("/api/groupes/{$id}/menu")->json('menu');
    $frappe = collect($menu['options'])->firstWhere('type', ManoeuvreSolo::TYPE_ATTAQUE);
    expect($frappe)->not->toBeNull()
        ->and(collect($frappe['parametres']['cibles'])->pluck('id')->all())->toContain($cible->id);

    desFiges(array_fill(0, 200, 4));
    $reponse = $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'manoeuvre_attaquer', 'parametres' => ['cible_id' => $cible->id]])
        ->assertSuccessful();

    // La volée d'Alice : 2 dés, quelle que soit son arme (le barbare en lance 3).
    expect(voleesDe($reponse->json('resultat') ?? [])[0] ?? null)->toHaveCount(ManoeuvreSolo::DES_ATTAQUE);
});

it('pas de manœuvre pour une équipe de plusieurs combattants debout', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);

    jouerEnArene($c['alice'], $c['arene']->identifiant, 'attendre')->assertSuccessful();

    expect(EtatPersonnageQuete::where('quete_id', $c['quete']->id)->where('manoeuvre_arene', true)->count())->toBe(0)
        ->and(manoeuvresAuJournal($c['arene']->id))->toBe(0);
});

it('CHALLENGER SEUL : un pas fait, la frappe de manœuvre n\'est plus offerte ni acceptée', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);
    $quete = $c['quete'];
    $id = $c['arene']->identifiant;
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['b']->id)->update(['tombe' => true]);
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();

    $this->actingAs($c['alice'], 'joueur');
    $menu = $this->getJson("/api/groupes/{$id}/menu")->json('menu');
    $destination = collect(collect($menu['options'])->firstWhere('id', 'se_deplacer')['parametres']['destinations'])
        ->firstWhere('cout', 1);
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => $destination['x'], 'y' => $destination['y']]])
        ->assertSuccessful();

    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['a']->id)->first();
    $cible = InstanceMonstre::where('quete_id', $quete->id)->where('etat', 'actif')->where('active_ce_round', false)->first();
    $cible->update(['position_x' => $etatA->position_x + 1, 'position_y' => $etatA->position_y]);
    Illuminate\Support\Facades\Cache::forget(App\Jobs\GenererMenu::cleMenu($c['arene']->id, (int) $c['alice']->id));

    $menu = $this->getJson("/api/groupes/{$id}/menu")->json('menu');
    expect(collect($menu['options'])->firstWhere('type', ManoeuvreSolo::TYPE_ATTAQUE))->toBeNull();
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'manoeuvre_attaquer', 'parametres' => ['cible_id' => $cible->id]])
        ->assertUnprocessable();
});

it('MORT : dans l\'arène, « Relever » n\'est ni offert ni accepté', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);
    $quete = $c['quete'];
    $id = $c['arene']->identifiant;
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['a']->id)->first();
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['b']->id)
        ->update(['tombe' => true, 'position_x' => $etatA->position_x, 'position_y' => $etatA->position_y + 1]);
    Illuminate\Support\Facades\Cache::forget(App\Jobs\GenererMenu::cleMenu($c['arene']->id, (int) $c['alice']->id));

    $this->actingAs($c['alice'], 'joueur');
    $menu = $this->getJson("/api/groupes/{$id}/menu")->json('menu');
    expect(collect($menu['options'])->where('type', 'relever')->all())->toBe([]);

    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => "relever_{$c['b']->id}"])->assertUnprocessable();
    expect(EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['b']->id)->value('tombe'))->toBeTruthy();
});
