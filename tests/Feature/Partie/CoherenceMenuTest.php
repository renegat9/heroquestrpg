<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\EtatGroupe;
use App\Partie\MenuMoteur;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Cohérence menu ⇄ plateau (correctifs §2.1/§2.2) : le menu moteur ne propose
 * que des options réellement jouables sur l'état COURANT, et le résolveur
 * revérifie les invariants (cible active ET visible) même si un menu périmé
 * la contient encore.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Menu moteur régénéré pour le héros depuis l'état exact. */
function menuMoteurPour(Groupe $groupe, Personnage $heros): array
{
    desFiges(array_fill(0, 20, 4));

    return app(MenuMoteur::class)->generer($groupe->fresh(), $heros->fresh());
}

it('n\'offre PAS d\'attaque sur un monstre adjacent DORMANT (non révélé)', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');

    // Le monstre au contact redevient dormant (salle non découverte).
    $instance->update(['revele' => false]);

    $menu = menuMoteurPour($groupe, $heros);
    $attaques = array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'attaque');

    expect($attaques)->toBe([]);
});

it('offre l\'attaque quand le même monstre adjacent est RÉVÉLÉ', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');
    $instance->update(['revele' => true]);

    $menu = menuMoteurPour($groupe, $heros);
    $attaques = array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'attaque');

    expect($attaques)->not->toBe([]);
});

it('le résolveur refuse une attaque contre un monstre non révélé, même si un menu périmé la propose', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');
    $instance->update(['revele' => false]);

    // Menu périmé (d'avant que la salle redevienne dormante) mémorisé en cache.
    Cache::put(GenererMenu::cleMenu($groupe->id, (int) $alice->id), [
        'personnage_id' => $heros->id,
        'menu' => ['options' => [[
            'id' => 'attaquer', 'libelle' => 'Attaquer', 'type' => 'attaque',
            'parametres' => ['cibles' => [['id' => $instance->id, 'type' => 'monstre', 'nom' => 'Gobelin']]],
        ]]],
    ], now()->addMinutes(60));

    desFiges(array_fill(0, 20, 1));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $instance->id]])
        ->assertStatus(422);

    // La cible n'a pas été touchée (elle reste à ses PV pleins).
    expect((int) $instance->fresh()->pv_body)->toBe((int) $instance->pv_body);
});

it('refuse une cible ABSENTE des cibles proposées, même parfaitement valide par ailleurs', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');

    // Depuis le ciblage en deux temps, c'est `parametres.cibles` qui porte la
    // légalité : l'identifiant d'option ne désigne plus une cible, donc la
    // valider contre le menu ne valide plus rien. Ici le monstre visé est
    // actif, révélé ET au contact — sans cette garde, l'attaque passerait, et
    // avec elle n'importe quel monstre de la quête, hors portée et hors vue.
    Cache::put(GenererMenu::cleMenu($groupe->id, (int) $alice->id), [
        'personnage_id' => $heros->id,
        'menu' => ['options' => [[
            'id' => 'attaquer', 'libelle' => 'Attaquer', 'type' => 'attaque',
            'parametres' => ['cibles' => [['id' => $instance->id + 9000, 'type' => 'monstre', 'nom' => 'Autre']]],
        ]]],
    ], now()->addMinutes(60));

    desFiges(array_fill(0, 20, 1));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $instance->id]])
        ->assertStatus(422);

    expect((int) $instance->fresh()->pv_body)->toBe((int) $instance->pv_body);
});

it('masque « Se déplacer » quand le héros est totalement bloqué (aucune case libre)', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'quete' => $quete, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');

    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // On bouche chaque case orthogonale ENCORE LIBRE avec un monstre actif :
    // combinées aux murs, les 4 directions deviennent infranchissables.
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if (caseQueteLibre($quete, $hx + $dx, $hy + $dy)) {
            InstanceMonstre::create([
                'quete_id' => $quete->id,
                'monstre_id' => $instance->monstre_id,
                'pv_body' => 2,
                'pv_mind' => 0,
                'position_x' => $hx + $dx,
                'position_y' => $hy + $dy,
                'etat' => 'actif',
                'revele' => true,
            ]);
        }
    }

    $menu = menuMoteurPour($groupe, $heros);
    $deplacements = array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'deplacement');

    expect($deplacements)->toBe([])
        // « Terminer le tour » reste toujours proposé (jamais de menu vide).
        ->and(array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'attente'))->not->toBe([]);
});

it('propose « Se déplacer » dès qu\'au moins une case adjacente est libre', function () {
    ['groupe' => $groupe, 'heros' => $heros] = demarrerQueteAvecMonstre('Gobelin');

    $menu = menuMoteurPour($groupe, $heros);
    $deplacements = array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'deplacement');

    expect($deplacements)->not->toBe([]);
});

it('ne propose AUCUNE action une fois le tour joué (a_joue), même si les créneaux ne sont pas tous marqués (correctifs A1)', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'quete' => $quete] = demarrerQueteAvecMonstre('Gobelin');

    // Action terminante (relever / concentration / « Terminer le tour ») : pose
    // a_joue=true SANS marquer a_deplace ni a_agi. Le menu doit refléter la fin
    // du tour, pas rouvrir des créneaux fantômes.
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)
        ->update(['a_joue' => true, 'a_deplace' => false, 'a_agi' => false]);

    $menu = menuMoteurPour($groupe, $heros);

    expect($menu['options'])->toBe([])
        ->and($menu['situation'])->toContain('terminé');
});

it('retire un monstre vaincu de l\'état partagé — plus sur la carte manette/table (correctifs A2)', function () {
    ['groupe' => $groupe, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');
    $instance->update(['revele' => true]);

    $etatGroupe = app(EtatGroupe::class);

    $avant = collect($etatGroupe->payload($groupe->fresh())['entites'])->where('type', 'monstre')->pluck('id');
    expect($avant)->toContain($instance->id);

    $instance->update(['etat' => 'vaincu']);

    $apres = collect($etatGroupe->payload($groupe->fresh())['entites'])->where('type', 'monstre')->pluck('id');
    expect($apres)->not->toContain($instance->id);
});

/*
 * René, 2026-09-11, en partie réelle : « le déplacement n'affiche pas dans les
 * actions quand on est entouré même si des amis sont présents et qui peuvent
 * être traversés ». `MenuMoteur::peutSeDeplacer()` refaisait sa PROPRE boucle
 * d'occupation et comptait tout héros DEBOUT comme un mur — la règle du
 * plateau permet pourtant de le traverser (pas de s'y arrêter) depuis le
 * 2026-09-04. `FabriqueGrille::pour(…, franchitAllies: true)` est désormais le
 * seul point de passage de cette question, comme pour le résolveur.
 */

it('garde « Se déplacer » pour un héros ENTOURÉ D\'ALLIÉS, même sans aucun pas immédiat libre', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $voisins = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    // Force les 4 voisins en SOL, plus une échappée à 2 pas à l'est — atteignable
    // seulement en traversant le compagnon planté sur (hx+1, hy).
    $carte = $quete->carte;
    $grille = $carte->grille;
    foreach ($voisins as [$dx, $dy]) {
        $grille['cases'][$hy + $dy][$hx + $dx] = 's';
    }
    $grille['cases'][$hy][$hx + 2] = 's';
    $carte->update(['grille' => $grille]);
    $quete->refresh();

    // Quatre compagnons plantés sur les 4 cases adjacentes — de simples états
    // de quête, comme `DeplacementTest::« DÉPASSE un compagnon »`.
    $pseudos = ['bob', 'carol', 'dave', 'erin'];
    foreach ($voisins as $i => [$dx, $dy]) {
        $joueur = JoueurAuthentifiable::create([
            'pseudo' => $pseudos[$i], 'identifiant' => $pseudos[$i], 'mot_de_passe' => 'secret',
        ]);
        $allie = creerHeros($joueur, $groupe, "Allié{$i}", 10 + $i);
        EtatPersonnageQuete::create([
            'quete_id' => $quete->id, 'personnage_id' => $allie->id,
            'position_x' => $hx + $dx, 'position_y' => $hy + $dy,
        ]);
    }

    $menu = menuMoteurPour($groupe, $hero);
    expect(collect($menu['options'])->firstWhere('type', 'deplacement'))->not->toBeNull();

    // …mais s'ARRÊTER sur un allié reste refusé : seul le PASSAGE s'ouvre, pas
    // le partage de case (« DÉPASSE un compagnon », `DeplacementTest.php`).
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => null, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);
    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => $hx + 1, 'y' => $hy],
    ])->assertStatus(422)->assertJsonPath(
        'errors.parametres.0',
        'On traverse une figure, on ne s\'arrête pas dessus : cette case est occupée.',
    );
});

it('perd « Se déplacer » pour le MÊME héros quand ses 4 voisins sont des MURS', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    $carte = $quete->carte;
    $grille = $carte->grille;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $grille['cases'][$hy + $dy][$hx + $dx] = 'm';
    }
    $carte->update(['grille' => $grille]);
    $quete->refresh();

    $menu = menuMoteurPour($groupe, $hero);
    expect(collect($menu['options'])->firstWhere('type', 'deplacement'))->toBeNull();
});
