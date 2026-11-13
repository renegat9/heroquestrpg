<?php

declare(strict_types=1);

use App\Engine\ReactionEffet;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/*
 * MODE ARÈNE — le MENU DÉPOSÉ AVANT une réaction hors tour ne doit pas survivre à la réaction (validation de la PR #2,
 * 2026-11-12). Un héros accepte un bouclier blanc (ou une riposte) : la tuile quitte la réserve du Challenger, mais le
 * menu en cache, calculé avant, offrait encore l'élan avec cette tuile — 422 « Cette tuile n'est plus dans la réserve
 * du Challenger. ». `POST /reaction` relance désormais les menus, sauf pour la reprise d'une attaque suspendue.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    $this->joueur = connecterJoueur();
});

/**
 * Un barbare d'arène face à `$monstre`, au contact (en diagonale si demandé), la réserve du Challenger posée à la main,
 * et les menus en cache jetés : le point de départ d'un menu qui sera calculé AVANT la réaction.
 *
 * @param  list<string>  $tuilesChallenger
 * @return array{0: App\Models\Groupe, 1: Quete, 2: Personnage, 3: EtatPersonnageQuete, 4: InstanceMonstre}
 */
function ouvrirDuelDeTuiles(string $monstre, array $tuilesChallenger, bool $diagonale = false): array
{
    $heros = Personnage::findOrFail(test()->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $doublure = $arenes->engager($arene, $heros);
    $quete = app(DemarreurArene::class)->demarrer($arene, [(int) Monstre::where('nom_base', $monstre)->value('id')]);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $doublure->id)->firstOrFail();
    $instance = InstanceMonstre::where('quete_id', $quete->id)->with('monstre')->firstOrFail();

    $instance->update([
        'position_x' => $etat->position_x + 1,
        'position_y' => $etat->position_y + ($diagonale ? 1 : 0),
        'active_ce_round' => true,
    ]);
    $quete->update(['trophees_arene' => ['cases' => [], 'challenger' => $tuilesChallenger, 'defender' => []]]);
    $doublure->refresh();

    foreach ($arene->personnages()->pluck('joueur_id')->unique() as $joueurId) {
        Cache::forget(GenererMenu::cleMenu($arene->id, (int) $joueurId));
    }

    return [$arene->refresh(), $quete->refresh(), $doublure, $etat->refresh(), $instance->refresh()];
}

/** Les tuiles que l'option « élan » du menu offre encore (liste blanche de `resoudreTropheeElan()`). */
function tuilesElanOfferies(array $options): array
{
    $elan = collect($options)->firstWhere('type', 'trophee_elan');

    return collect($elan['parametres']['tuiles'] ?? [])->pluck('cle')->all();
}

it('BOUCLIER BLANC accepté : le menu d\'après ne propose plus la tuile défaussée dans l\'élan', function () {
    [$arene, , $doublure, $etat, $orque] = ouvrirDuelDeTuiles('Orque', ['bouclier_blanc']);
    $id = $arene->identifiant;

    // Le menu calculé AVANT le coup : l'élan offre la tuile.
    expect(tuilesElanOfferies($this->getJson("/api/groupes/{$id}/menu")->json('menu.options')))->toContain('bouclier_blanc');

    // Un coup de monstre blesse le héros : offre de bouclier blanc.
    app(MoteurDegats::class)->infligerAHeros($doublure->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, ['instance_id' => $orque->id, 'monstre' => 'Orque']);
    expect($etat->fresh()->reaction_en_attente['action'])->toBe(ReactionEffet::TROPHEE_BOUCLIER);

    $this->postJson("/api/groupes/{$id}/reaction", ['accepte' => true, 'personnage_id' => $doublure->id])->assertSuccessful();

    // La tuile a quitté la réserve : le menu qui suit ne l'offre plus.
    expect(tuilesElanOfferies($this->getJson("/api/groupes/{$id}/menu")->json('menu.options')))->not->toContain('bouclier_blanc');
});

it('RIPOSTE acceptée (frappée en diagonale) : la tuile quitte l\'élan du menu d\'après', function () {
    [$arene, , $doublure, $etat, $orque] = ouvrirDuelDeTuiles('Orque', ['riposte'], diagonale: true);
    $id = $arene->identifiant;
    // L'orque survit à la riposte : la fin de combat (tout l'arène éteinte) effacerait le menu et ne testerait rien.
    $orque->update(['pv_body' => 40]);

    expect(tuilesElanOfferies($this->getJson("/api/groupes/{$id}/menu")->json('menu.options')))->toContain('riposte');

    app(MoteurDegats::class)->infligerAHeros($doublure->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, ['instance_id' => $orque->id, 'monstre' => 'Orque']);
    expect($etat->fresh()->reaction_en_attente['action'])->toBe(ReactionEffet::RIPOSTE);

    desFiges(array_fill(0, 50, 1));
    $this->postJson("/api/groupes/{$id}/reaction", ['accepte' => true, 'personnage_id' => $doublure->id])->assertSuccessful();

    expect(tuilesElanOfferies($this->getJson("/api/groupes/{$id}/menu")->json('menu.options')))->not->toContain('riposte');
});

it('la reprise d\'une attaque suspendue (crâne) n\'est relancée qu\'UNE fois : ExecutionChoix la relance déjà', function () {
    // Garde-fou de l'exception : `POST /reaction` ne doit pas relancer une seconde fois une attaque reprise, qui
    // consomme son menu. Un seul héros actif → une seule relance attendue.
    [$arene, , $doublure, $etat, $momie] = ouvrirDuelDeTuiles('Momie', ['crane']);
    $id = $arene->identifiant;

    desFiges([1, 6, 6, 4, 4, 4, 4, 4, 4, 4]);
    // LA LETTRE (2026-11-12) : le héros seul manœuvre d'abord, à l'ouverture du round — il passe, son activation reste à jouer.
    jouerEnArene($this->joueur, $id, 'attendre')->assertSuccessful();
    $menu = collect($this->getJson("/api/groupes/{$id}/menu")->json('menu.options'));
    $attaquer = $menu->firstWhere('type', 'attaque');
    $cible = collect($attaquer['parametres']['cibles'] ?? [])->first();
    $parametres = array_filter(['cible_id' => $cible['id'] ?? $momie->id, 'cle' => $cible['cle'] ?? null]);
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => $attaquer['id'], 'parametres' => $parametres])->assertSuccessful();
    expect($etat->fresh()->reaction_en_attente['action'])->toBe(ReactionEffet::TROPHEE_CRANE);

    Queue::fake([GenererMenu::class]);
    $this->postJson("/api/groupes/{$id}/reaction", ['accepte' => true, 'personnage_id' => $doublure->id])->assertSuccessful();

    Queue::assertPushed(GenererMenu::class, 1);
});
