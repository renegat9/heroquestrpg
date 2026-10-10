<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Fouille de zone : l'issue dit ce que la RECHERCHE a donné (verdict Morcar, 2026-10-09 § 2).
 *  - `reussite` : quelque chose a été trouvé (`a_trouve: true`) ;
 *  - `rien`     : le jet a réussi et ne trouve rien (`succes: true`, `a_trouve: false`) ;
 *  - `echec`    : le jet a raté (`succes: false`, `a_trouve: false`).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class]);
});

/** Quête à deux héros ; Albrecht a la main. Zone vidée : ni piège, ni porte. @return array{0: JoueurAuthentifiable, 1: \App\Models\Groupe, 2: \App\Models\Personnage, 3: Quete, 4: EtatPersonnageQuete} */
function demarrerFouilleIssue(array $portes = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = [];
    $grille['portes'] = $portes;
    $carte->update(['grille' => $grille]);
    $quete->load('carte');

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    return [$alice, $groupe, $hero, $quete, $etat];
}

it('une recherche RÉUSSIE qui ne trouve rien porte `issue: rien`, jamais `reussite`', function () {
    [, , , , $etat] = demarrerFouilleIssue();

    desFiges([1, 4]); // Mind 2 dés : un crâne → le jet réussit (difficulté 1)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.succes', 1)
        ->assertJsonPath('resultat.a_trouve', false)
        ->assertJsonPath('resultat.issue', 'rien');
});

it('une recherche RATÉE porte `issue: echec` et rien trouvé', function () {
    demarrerFouilleIssue();

    desFiges([4, 6]); // aucun crâne : le jet échoue

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.succes', 0)
        ->assertJsonPath('resultat.a_trouve', false)
        ->assertJsonPath('resultat.issue', 'echec');
});

it('une recherche qui TROUVE une porte secrète porte `issue: reussite` et `a_trouve: true`', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerFouilleIssue();

    // Une porte secrète juste à côté du héros, dans sa zone de fouille.
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $grille = $quete->carte->grille;
    $grille['portes'] = [['x' => $hx + 1, 'y' => $hy, 'etat' => 'secrete', 'revele' => false]];
    $quete->carte->update(['grille' => $grille]);
    $quete->load('carte');
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([1, 4]);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.a_trouve', true)
        ->assertJsonPath('resultat.issue', 'reussite');
});

it('le fil distingue l\'ÉCHEC (rien n\'est établi) de la réussite sans trouvaille (zone sûre), et `des.boucliers` compte les crânes', function () {
    [, , $hero] = demarrerFouilleIssue();

    desFiges([1, 4]); // un crâne : réussite, rien trouvé
    $reussi = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])->assertStatus(202)->json('resultat');
    $fil = app(\App\Partie\JournalCombat::class)->depuisResultat($reussi, $hero->nom);

    expect(collect($fil)->pluck('texte')->implode(' | '))->toContain('aucun piège ni passage secret');

    // Le bloc de dés : `boucliers` = nombre de crânes lancés (1), jamais un drapeau sans face derrière.
    $des = collect($fil)->pluck('des')->filter()->first();
    if ($des !== null) {
        expect($des['boucliers'])->toBe(count(array_filter($des['def'], fn ($f) => $f === 'crane')));
    }

    $echec = ['option_id' => 'fouiller', 'type' => 'jet', 'succes' => 0, 'issue' => 'echec', 'libelle' => 'Fouiller la zone'];
    $texte = collect(app(\App\Partie\JournalCombat::class)->depuisResultat($echec, $hero->nom))->pluck('texte')->implode(' | ');
    expect($texte)->toContain('jet raté')->and($texte)->not->toContain('aucun piège');
});
