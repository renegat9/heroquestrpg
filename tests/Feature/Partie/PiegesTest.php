<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Pièges (doc 10, contrat docs/contrat-api.md) — tout passe par les menus :
 * déclenchement en marchant sur un piège CACHÉ (la fosse interrompt le
 * déplacement), révélation par la fouille, désamorçage (Nain / Trousse à
 * outils, jet de Body 1 — échec = déclenché sur le désamorceur) et
 * franchissement d'une fosse détectée (jet de Body 2 — échec = chute).
 * Les pièges cachés n'apparaissent JAMAIS dans EtatGroupe.carte.pieges.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class]);
});

/**
 * REMPLACE les pièges de la carte par ceux du scénario (les pièges posés par
 * l'assembleur sont écartés : le test contrôle exactement le terrain).
 *
 * @param  list<array{x: int, y: int, nom: string, etat: string}>  $entrees
 */
function poserPieges(Quete $quete, array $entrees): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;

    $grille['pieges'] = array_map(fn (array $e) => [
        'x' => $e['x'],
        'y' => $e['y'],
        'piege_id' => Piege::where('nom', $e['nom'])->value('id'),
        'etat' => $e['etat'],
    ], $entrees);

    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/**
 * Direction de saut valide depuis (x, y) : la case adjacente (fosse) ET la
 * case suivante dans le même alignement (réception) sont libres.
 *
 * @return array{fosse: array{x: int, y: int}, reception: array{x: int, y: int}}
 */
function alignementFranchissable(Quete $quete, int $x, int $y): array
{
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if (caseQueteLibre($quete, $x + $dx, $y + $dy) && caseQueteLibre($quete, $x + 2 * $dx, $y + 2 * $dy)) {
            return [
                'fosse' => ['x' => $x + $dx, 'y' => $y + $dy],
                'reception' => ['x' => $x + 2 * $dx, 'y' => $y + 2 * $dy],
            ];
        }
    }

    throw new RuntimeException('Aucun alignement de 2 cases libres — scénario de test invalide.');
}

/**
 * Quête démarrée avec deux héros (le second empêche la phase des monstres
 * de se déclencher après l'action du premier).
 *
 * @param  array<string, mixed>  $attributs  attributs du héros d'Alice
 * @return array{0: JoueurAuthentifiable, 1: Groupe, 2: Personnage, 3: Quete, 4: EtatPersonnageQuete}
 */
function demarrerQueteAvecHeros(array $attributs = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, $attributs);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    return [$alice, $groupe, $hero, $quete, $etat];
}

it('déclenche un piège caché traversé : dégâts du catalogue, usage unique consommé', function () {
    [, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'cache']]);

    desFiges([3]); // 1d6 de déplacement

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $cible,
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.type', 'deplacement')
        ->assertJsonPath('resultat.interrompu', false) // pas une fosse : on finit le déplacement
        ->assertJsonPath('resultat.pieges_declenches.0.piege.nom', 'Piège à lances')
        ->assertJsonPath('resultat.pieges_declenches.0.degats', 1)
        ->assertJsonPath('resultat.pieges_declenches.0.pv_body_apres', 7)
        ->assertJsonPath('resultat.pieges_declenches.0.immobilise', false);

    expect($hero->fresh()->pv_body)->toBe(7)
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('declenche');

    // Le piège déclenché devient public dans EtatGroupe.carte ; les héros
    // exposent leur niveau (contrat).
    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    // (image_url retiré : dépend des assets générés, hors périmètre du test)
    expect(collect($partage['carte']['pieges'])->map(fn ($p) => collect($p)->except('image_url')->all())->all())->toBe([
        ['x' => $cible['x'], 'y' => $cible['y'], 'etat' => 'declenche', 'nom' => 'Piège à lances'],
    ])->and($partage['entites'][0]['niveau'])->toBe(1);
});

it('arrête le déplacement sur une fosse cachée : immobilisé, la fosse persiste', function () {
    [, , $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $saut = alignementFranchissable($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $saut['fosse']['x'], 'y' => $saut['fosse']['y'], 'nom' => 'Fosse', 'etat' => 'cache']]);

    desFiges([3]); // 1d6 de déplacement (2 cases demandées, 4+3 disponibles)

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $saut['reception'],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.interrompu', true)
        ->assertJsonPath('resultat.vers.x', $saut['fosse']['x'])
        ->assertJsonPath('resultat.vers.y', $saut['fosse']['y'])
        ->assertJsonPath('resultat.pieges_declenches.0.degats', 1)
        ->assertJsonPath('resultat.pieges_declenches.0.immobilise', true);

    $etat->refresh();
    expect($etat->position_x)->toBe($saut['fosse']['x'])
        ->and($etat->position_y)->toBe($saut['fosse']['y'])
        ->and($hero->fresh()->pv_body)->toBe(7)
        // Persistante : la fosse reste en jeu, désormais visible (`detecte`).
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('detecte');
});

it('révèle par la fouille les pièges cachés proches — jamais les lointains', function () {
    [, , , $quete, $etat] = demarrerQueteAvecHeros();

    $x = (int) $etat->position_x;
    $y = (int) $etat->position_y;
    $proche = caseAdjacenteLibre($quete, $x, $y);

    // Une case traversable à plus de 3 cases (rayon de fouille) du fouilleur.
    $cases = $quete->carte->grille['cases'];
    $lointaine = null;
    foreach ($cases as $cy => $ligne) {
        foreach ($ligne as $cx => $case) {
            if (in_array($case, ['s', 'p'], true) && abs($cx - $x) + abs($cy - $y) > 3) {
                $lointaine = ['x' => $cx, 'y' => $cy];
                break 2;
            }
        }
    }
    expect($lointaine)->not->toBeNull();

    poserPieges($quete, [
        ['x' => $proche['x'], 'y' => $proche['y'], 'nom' => 'Fosse', 'etat' => 'cache'],
        ['x' => $lointaine['x'], 'y' => $lointaine['y'], 'nom' => 'Piège à lances', 'etat' => 'cache'],
    ]);

    desFiges([1, 4]); // Mind 2 dés : 1 crâne → réussite (difficulté 1)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'reussite')
        ->assertJsonPath('resultat.pieges_reveles.0.nom', 'Fosse')
        ->assertJsonCount(1, 'resultat.pieges_reveles');

    // Seul le piège révélé apparaît dans l'état partagé : le lointain reste
    // CACHÉ et n'y figure jamais (contrat).
    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($partage['carte']['pieges'])->map(fn ($p) => collect($p)->except('image_url')->all())->all())->toBe([
        ['x' => $proche['x'], 'y' => $proche['y'], 'etat' => 'detecte', 'nom' => 'Fosse'],
    ]);

    $pieges = $quete->fresh()->carte->grille['pieges'];
    expect($pieges[0]['etat'])->toBe('detecte')
        ->and($pieges[1]['etat'])->toBe('cache');
});

it('laisse le Nain désamorcer un piège détecté adjacent : jet de Body 1 réussi → désarmé', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(['classe' => 'nain']);

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'detecte']]);

    // Re-proposition du menu moteur : il contient maintenant Désamorcer.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id));
    $option = collect($menu['menu']['options'])->firstWhere('id', "desamorcer_{$cible['x']}_{$cible['y']}");
    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('desamorcage')
        ->and($option['jet'])->toBe(['attribut' => 'body', 'difficulte' => 1])
        ->and($option['parametres'])->toBe(['piege' => ['x' => $cible['x'], 'y' => $cible['y']]]);

    desFiges([1, 4, 4, 4]); // Body 4 dés : 1 crâne → réussite (difficulté 1)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'desamorcage')
        ->assertJsonPath('resultat.desarme', true)
        ->assertJsonPath('resultat.piege.nom', 'Piège à lances');

    expect($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('desarme')
        ->and($hero->fresh()->pv_body)->toBe(8); // indemne

    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect($partage['carte']['pieges'][0]['etat'])->toBe('desarme');
});

it('déclenche le piège sur le désamorceur quand le jet échoue', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(['classe' => 'nain']);

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'detecte']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([4, 4, 4, 4]); // Body 4 dés : 0 crâne → échec sec

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "desamorcer_{$cible['x']}_{$cible['y']}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.desarme', false)
        ->assertJsonPath('resultat.declenchement.contexte', 'desamorcage_rate')
        ->assertJsonPath('resultat.declenchement.degats', 1)
        ->assertJsonPath('resultat.declenchement.pv_body_apres', 7);

    expect($hero->fresh()->pv_body)->toBe(7)
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('declenche'); // usage unique consommé
});

it('refuse le désamorçage sans Nain ni trousse — et l\'offre au porteur de la Trousse à outils', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(); // barbare

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'detecte']]);

    // Barbare sans trousse : aucune option Désamorcer dans le menu moteur…
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids->contains(fn ($id) => str_starts_with($id, 'desamorcer_')))->toBeFalse();

    // … et forcer l'option hors menu est illégal (le moteur fait autorité).
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "desamorcer_{$cible['x']}_{$cible['y']}",
    ])->assertStatus(422);

    // Avec la Trousse à outils au sac (effet permet_desamorcage), l'option apparaît.
    Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => Objet::where('nom', 'Trousse à outils')->value('id'),
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids)->toContain("desamorcer_{$cible['x']}_{$cible['y']}");
});

it('franchit une fosse détectée sur un jet de Body 2 réussi : le héros atterrit de l\'autre côté', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(); // barbare : Franchir, pas Désamorcer

    $saut = alignementFranchissable($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $saut['fosse']['x'], 'y' => $saut['fosse']['y'], 'nom' => 'Fosse', 'etat' => 'detecte']]);
    $etat->update(['deplacement_tour' => 6]); // allonce connue → coût du saut vérifiable

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id));
    $option = collect($menu['menu']['options'])->firstWhere('id', "franchir_{$saut['fosse']['x']}_{$saut['fosse']['y']}");
    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('franchissement')
        ->and($option['jet'])->toBe(['attribut' => 'body', 'difficulte' => 2]);

    desFiges([1, 1, 4, 4]); // Body 4 dés : 2 crânes → réussite (difficulté 2)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'franchissement')
        ->assertJsonPath('resultat.franchi', true)
        ->assertJsonPath('resultat.vers.x', $saut['reception']['x'])
        ->assertJsonPath('resultat.vers.y', $saut['reception']['y'])
        // Sauter fait partie du MOUVEMENT (E3) : 2 cases payées sur les 6.
        ->assertJsonPath('resultat.deplacement_restant', 4);

    $etat->refresh();
    expect($etat->position_x)->toBe($saut['reception']['x'])
        ->and($etat->position_y)->toBe($saut['reception']['y'])
        ->and($hero->fresh()->pv_body)->toBe(8) // indemne
        // Il reste des points → le mouvement n'est PAS fini : on peut continuer.
        ->and($etat->deplacement_restant)->toBe(4)
        ->and($etat->a_deplace)->toBeFalse()
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('detecte'); // la fosse reste en jeu
});

it('fait chuter le héros dans la fosse quand le franchissement échoue', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $saut = alignementFranchissable($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $saut['fosse']['x'], 'y' => $saut['fosse']['y'], 'nom' => 'Fosse', 'etat' => 'detecte']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([4, 4, 4, 4]); // 0 crâne → échec → chute (effet de la fosse)

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "franchir_{$saut['fosse']['x']}_{$saut['fosse']['y']}",
    ])->assertStatus(202)
        ->assertJsonPath('resultat.franchi', false)
        ->assertJsonPath('resultat.declenchement.contexte', 'franchissement_rate')
        ->assertJsonPath('resultat.declenchement.degats', 1)
        ->assertJsonPath('resultat.vers.x', $saut['fosse']['x'])
        ->assertJsonPath('resultat.vers.y', $saut['fosse']['y']);

    $etat->refresh();
    expect($etat->position_x)->toBe($saut['fosse']['x'])
        ->and($etat->position_y)->toBe($saut['fosse']['y'])
        ->and($hero->fresh()->pv_body)->toBe(7)
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('detecte'); // persistante
});
