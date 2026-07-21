<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\ResolveurTour;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Vague 2 — modèle de porte + exploration (doc 14 §3.1/3.2/3.3, contrat) :
 * portes secrètes/verrouillées (pathfinding + ligne de vue), fouille de la zone,
 * verrous (clé / monstres vaincus / levier), et « Fouiller — trésor » (table
 * risque/récompense, monstre errant sur budget dédié, piège éphémère).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class]);
});

/**
 * Fixe les portes et leviers de la carte (les éléments générés sont écartés :
 * le test contrôle exactement le terrain). Les pièges restent inchangés.
 *
 * @param  list<array<string, mixed>>  $portes
 * @param  list<array{x: int, y: int, levier_id: string}>  $leviers
 */
function poserPortes(Quete $quete, array $portes, array $leviers = []): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['portes'] = $portes;
    $grille['leviers'] = $leviers;
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/** Pose des pièges de scénario (remplace ceux de l'assembleur). */
function poserPiegesExplo(Quete $quete, array $entrees): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = array_map(fn (array $e) => [
        'x' => $e['x'], 'y' => $e['y'],
        'piege_id' => Piege::where('nom', $e['nom'])->value('id'),
        'etat' => $e['etat'],
    ], $entrees);
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/**
 * Quête démarrée avec deux héros (le second empêche la phase des monstres de
 * se déclencher après l'action du premier).
 *
 * @return array{0: JoueurAuthentifiable, 1: \App\Models\Groupe, 2: \App\Models\Personnage, 3: Quete, 4: EtatPersonnageQuete}
 */
function demarrerExplo(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    return [$alice, $groupe, $hero, $quete, $etat];
}

it('révèle par « Fouiller la zone » une porte secrète ET un piège, et n\'invoque aucun errant', function () {
    [, , , $quete, $etat] = demarrerExplo();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Une porte secrète + un piège caché à portée de fouille (rayon 3).
    poserPortes($quete, [['x' => $hx + 1, 'y' => $hy, 'etat' => 'secrete', 'revele' => false]]);
    poserPiegesExplo($quete, [['x' => $hx, 'y' => $hy + 1, 'nom' => 'Piège à lances', 'etat' => 'cache']]);

    $avantMonstres = $quete->instancesMonstres()->count();

    desFiges([1, 4]); // Mind 2 dés : 1 crâne → réussite (difficulté 1)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'reussite')
        ->assertJsonPath('resultat.portes_revelees.0.x', $hx + 1)
        ->assertJsonPath('resultat.pieges_reveles.0.nom', 'Piège à lances');

    $grille = $quete->fresh()->carte->grille;
    expect($grille['portes'][0]['etat'])->toBe('ouverte')
        ->and($grille['portes'][0]['revele'])->toBeTrue()
        ->and($grille['pieges'][0]['etat'])->toBe('detecte')
        // Fouiller la zone n'invoque JAMAIS de monstre errant (doc 14 §3.2).
        ->and($quete->fresh()->instancesMonstres()->count())->toBe($avantMonstres);
});

it('bloque le pathfinding et la ligne de vue derrière une porte verrouillée (état partagé)', function () {
    [, , , $quete, $etat] = demarrerExplo();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    // Porte verrouillée sur l'ARÊTE est du héros (entre (hx,hy) et (hx+1,hy)).
    poserPortes($quete, [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'verrouillee', 'verrou' => ['type' => 'cle', 'objet_id' => 1]]]);

    // La porte figure dans l'état partagé avec son côté + son verrou (aucune
    // case 'p' : elle vit sur la cloison, pas sur une case).
    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($partage['carte']['portes'])->first(fn ($p) => $p['x'] === $hx && $p['y'] === $hy && ($p['cote'] ?? null) === 'e'))
        ->toMatchArray(['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'verrouillee', 'verrou' => 'cle']);

    // Elle barre le PAS est ET la vue sur l'arête, sans prendre de case (les
    // deux côtés restent du sol traversable).
    $grille = Grille::depuisCarte($quete->fresh()->carte);
    expect($grille->porteBloqueEntre($hx, $hy, $hx + 1, $hy))->toBeTrue()
        ->and($grille->estTraversable($hx + 1, $hy))->toBeTrue()
        ->and($grille->ligneDeVue($hx, $hy, $hx + 1, $hy))->toBeFalse();
});

it('ouvre SANS clé une porte simplement fermée, sans consommer son tour (E2)', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerExplo();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $etat->update(['deplacement_tour' => 6, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    // Une porte simplement CLOSE (aucun verrou) sur l'ARÊTE est du héros.
    poserPortes($quete, [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'fermee']]);

    // Elle barre le PAS est (arête) sans prendre de case.
    expect(Grille::depuisCarte($quete->fresh()->carte)->porteBloqueEntre($hx, $hy, $hx + 1, $hy))->toBeTrue();

    // … et le menu propose de l'ouvrir À LA MAIN (pas de mention de clé).
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $optionId = "ouvrir_porte_{$hx}_{$hy}_e";
    $option = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])
        ->firstWhere('id', $optionId);
    expect($option)->not->toBeNull()
        ->and($option['libelle'])->toBe('Ouvrir la porte');

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $optionId])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'ouvrir_porte')
        ->assertJsonPath('resultat.cause', 'main');

    // La porte est ouverte (état persistant) : l'arête ne bloque plus.
    expect($quete->fresh()->carte->grille['portes'][0]['etat'])->toBe('ouverte')
        ->and(Grille::depuisCarte($quete->fresh()->carte)->porteBloqueEntre($hx, $hy, $hx + 1, $hy))->toBeFalse();

    // Ouvrir est une INTERACTION LIBRE : aucun créneau consommé, le déplacement
    // du tour est intact → on s'arrête devant, on ouvre, on continue.
    $etat->refresh();
    expect($etat->a_deplace)->toBeFalse()
        ->and($etat->a_agi)->toBeFalse()
        ->and($etat->a_joue)->toBeFalse();
});

it('ouvre une porte verrouillée par CLÉ : le porteur de l\'objet utilise l\'option au contact', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerExplo();

    $objetId = (int) Objet::query()->orderBy('id')->value('id');
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    poserPortes($quete, [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'verrouillee', 'verrou' => ['type' => 'cle', 'objet_id' => $objetId]]]);

    // Sans la clé : aucune option d'ouverture.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids->contains(fn ($id) => str_starts_with($id, 'ouvrir_porte_')))->toBeFalse();

    // Avec la clé au sac, l'option apparaît…
    Inventaire::create(['personnage_id' => $hero->id, 'objet_id' => $objetId, 'emplacement' => 'sac', 'quantite' => 1]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $optionId = "ouvrir_porte_{$hx}_{$hy}_e";
    expect(collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id'))
        ->toContain($optionId);

    // … et l'ouvre (état persistant).
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $optionId])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'ouvrir_porte')
        ->assertJsonPath('resultat.cause', 'cle');

    expect($quete->fresh()->carte->grille['portes'][0]['etat'])->toBe('ouverte');
});

it('ouvre la porte liée quand le héros actionne le LEVIER au contact', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerExplo();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    poserPortes(
        $quete,
        [['x' => $hx + 2, 'y' => $hy, 'etat' => 'verrouillee', 'verrou' => ['type' => 'levier', 'levier_id' => 'L1']]],
        [['x' => $hx + 1, 'y' => $hy, 'levier_id' => 'L1']],
    );

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $optionId = "actionner_levier_" . ($hx + 1) . "_{$hy}";
    expect(collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id'))
        ->toContain($optionId);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $optionId])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'actionner_levier')
        ->assertJsonPath('resultat.portes_ouvertes.0.x', $hx + 2);

    expect($quete->fresh()->carte->grille['portes'][0]['etat'])->toBe('ouverte');
});

it('ouvre AUTOMATIQUEMENT une porte « monstres_vaincus » quand le gardien tombe', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin'); // pv 1, défense 1, au contact du héros
    $alice = $ctx['alice'];
    $groupe = $ctx['groupe'];
    $hero = $ctx['heros'];
    $quete = $ctx['quete'];
    $instance = $ctx['instance'];

    poserPortes($quete, [[
        'x' => 0, 'y' => 0, 'etat' => 'verrouillee',
        'verrou' => ['type' => 'monstres_vaincus', 'instances' => [$instance->id]],
    ]]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([1, 1, 1, 4]); // 3 dés d'attaque (3 crânes) vs 1 dé de défense (bouclier blanc → 0 blocage monstre)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$instance->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.cible_vaincue', true);

    // Le gardien est vaincu → la porte s'ouvre toute seule (hook post-combat).
    expect($quete->fresh()->carte->grille['portes'][0]['etat'])->toBe('ouverte');
});

it('« Fouiller — trésor » verse de l\'or au groupe sur l\'issue trésor', function () {
    [, $groupe, , , ] = demarrerExplo();

    desFiges([1]); // d6=1 → trésor (poids 2/2/1/1 : trésor sur 1-2)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'fouille_tresor')
        ->assertJsonPath('resultat.issue', 'tresor')
        ->assertJsonPath('resultat.or', 30);

    expect((int) $groupe->fresh()->or)->toBe(30);
});

it('« Fouiller — trésor » peut ne rien donner', function () {
    [, $groupe, , $quete, ] = demarrerExplo();

    desFiges([3]); // d6=3 → rien (sur 3-4)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'rien');

    expect((int) $groupe->fresh()->or)->toBe(0);
});

it('« Fouiller — trésor » applique un piège ÉPHÉMÈRE au fouilleur, sans le poser sur la grille', function () {
    [, , $hero, $quete, ] = demarrerExplo();

    $avantPieges = count($quete->carte->grille['pieges'] ?? []);

    desFiges([6]); // d6=6 → piège (sur 6)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'piege')
        ->assertJsonPath('resultat.declenchement.ephemere', true)
        ->assertJsonPath('resultat.declenchement.degats', 1);

    expect((int) $hero->fresh()->pv_body)->toBe(7) // 8 − 1
        // Aucun piège ajouté durablement à la grille (éphémère).
        ->and(count($quete->fresh()->carte->grille['pieges'] ?? []))->toBe($avantPieges);
});

it('« Fouiller — trésor » fait surgir un monstre errant (budget dédié) qui joue au tour des monstres', function () {
    $ctx = demarrerExplo();
    $alice = $ctx[0];
    $groupe = $ctx[1];
    $quete = $ctx[3];

    $budgetAvant = (int) Cache::get(ResolveurTour::cleBudgetErrant($quete->id));
    expect($budgetAvant)->toBeGreaterThan(0); // initialisé par DemarreurQuete

    $instancesAvant = $quete->instancesMonstres()->count();

    // d6=5 → errant ; le moins cher du bestiaire (Gobelin, coût 1) est instancié.
    desFiges([5]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'errant');

    $instanceId = $reponse->json('resultat.monstre.instance_id');
    expect($instanceId)->not->toBeNull();

    $errant = $quete->fresh()->instancesMonstres()->whereKey($instanceId)->first();
    expect($errant)->not->toBeNull()
        ->and($errant->etat)->toBe('actif')
        ->and((bool) $errant->revele)->toBeTrue()
        ->and($quete->fresh()->instancesMonstres()->count())->toBe($instancesAvant + 1)
        // Budget errant décompté.
        ->and((int) Cache::get(ResolveurTour::cleBudgetErrant($quete->id)))->toBe($budgetAvant - (int) $errant->monstre->cout);

    // Fouiller est une ACTION (a_agi) mais NE termine plus le tour tout seul :
    // alice TERMINE, puis Bob TERMINE → phase des monstres (l'errant agit).
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $ctx[2]->id);
    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $bob = JoueurAuthentifiable::where('identifiant', 'bob')->firstOrFail();
    test()->actingAs($bob, 'joueur');

    // Dés généreux pour ne pas dépendre de la position exacte de l'errant
    // (attaque s'il est au contact, sinon déplacement) ni du nb de menus régénérés.
    desFiges(array_fill(0, 50, 4));
    $tour = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $actions = collect($tour->json('resultat.tour_monstres.actions'));
    expect($actions->isNotEmpty())->toBeTrue()
        ->and($actions->contains(fn ($a) => ($a['monstre'] ?? null) === 'Gobelin'))->toBeTrue();
});
