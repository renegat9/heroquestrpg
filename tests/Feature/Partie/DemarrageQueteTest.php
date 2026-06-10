<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\Groupe;
use App\Models\Quete;
use App\Partie\ScorePuissance;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Démarrage d'une quête jouable (POST /api/groupes/{identifiant}/quetes) —
 * flux complet sur sqlite, file synchrone, AUCUN appel LLM : sans clé
 * Anthropic, narration et menus tombent sur le repli moteur (contrat :
 * « l'API ne dépend jamais du LLM »).
 */

beforeEach(function () {
    Http::fake(); // aucun appel réseau réel (Anthropic, Qdrant)
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('démarre une quête jouable : carte assemblée, monstres au budget, initiative figée', function () {
    $joueur = connecterJoueur('alice');

    // Création de la campagne via l'API : le job squelette échoue en repli
    // SILENCIEUX sans clé LLM — la campagne reste jouable.
    $this->postJson('/api/groupes', [
        'identifiant' => 'table-1',
        'nom' => 'Les Lames',
        'theme' => 'Cryptes maudites',
        'longueur' => 'courte',
    ])->assertCreated();

    $groupe = Groupe::where('identifiant', 'table-1')->firstOrFail();

    // Deux héros du roster du joueur rejoignent le groupe par l'API.
    $a = creerHeros($joueur, $groupe, 'Albrecht', 1);
    $b = creerHeros($joueur, $groupe, 'Brunhilde', 2);
    $groupe->personnages()->detach([$a->id, $b->id]); // ré-attachés par la route ci-dessous
    $a->update(['groupe_actif_id' => null]);
    $b->update(['groupe_actif_id' => null]);

    $this->postJson('/api/groupes/table-1/joueurs', ['personnage_ids' => [$a->id, $b->id]])
        ->assertOk();

    // Au hub : EtatGroupe du contrat, sections de quête vides.
    $this->getJson('/api/groupes/table-1/etat')
        ->assertOk()
        ->assertJsonPath('groupe.phase', 'hub')
        ->assertJsonPath('quete', null)
        ->assertJsonPath('carte', null)
        ->assertJsonPath('entites', [])
        ->assertJsonPath('initiative', []);

    $budgetAttendu = app(ScorePuissance::class)->calculer($groupe); // arc 1, jalon normal

    $reponse = $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $reponse->assertJsonPath('quete.etat', 'en_cours')
        ->assertJsonPath('quete.position_arc', 1)
        ->assertJsonPath('quete.type_jalon', 'normale');

    $groupe->refresh();
    $quete = Quete::findOrFail($reponse->json('quete.id'));

    expect($groupe->phase)->toBe('quete')
        ->and($groupe->quete_courante_id)->toBe($quete->id);

    // Carte assemblée depuis les tuiles : grille rectangulaire cohérente.
    $carte = Carte::where('quete_id', $quete->id)->firstOrFail();
    expect($carte->grille['cases'])->toHaveCount($carte->hauteur)
        ->and($carte->grille['cases'][0])->toHaveCount($carte->largeur)
        ->and($carte->grille['salles'])->not->toBeEmpty()
        ->and($carte->grille['pieges'])->not->toBeEmpty(); // gabarit : min 1 piège

    // Monstres spawnés AU BUDGET (coût du bestiaire × score de puissance) :
    // le budget est entièrement dépensé (le moins cher coûte 1) et chaque
    // instance est positionnée sur la carte.
    $instances = $quete->instancesMonstres()->with('monstre')->get();
    expect($instances)->not->toBeEmpty()
        ->and($instances->sum(fn ($i) => (int) $i->monstre->cout))->toBe($budgetAttendu)
        ->and($instances->every(fn ($i) => $i->position_x !== null && $i->position_y !== null))->toBeTrue()
        ->and($instances->every(fn ($i) => $i->pv_body === $i->monstre->pv_body))->toBeTrue();

    // État de quête par héros : position de spawn, personne n'a joué.
    $etats = $quete->etatsPersonnages()->get();
    expect($etats)->toHaveCount(2)
        ->and($etats->every(fn ($e) => $e->position_x !== null && ! $e->a_joue && ! $e->tombe))->toBeTrue();

    // Initiative figée (C1) : héros 1..n dans l'ordre d'arrivée, monstres après.
    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect($etat['groupe']['phase'])->toBe('quete')
        ->and($etat['quete']['id'])->toBe($quete->id)
        ->and($etat['carte']['largeur'])->toBe($carte->largeur)
        ->and(collect($etat['entites'])->where('type', 'heros'))->toHaveCount(2)
        ->and(collect($etat['entites'])->where('type', 'monstre'))->toHaveCount($instances->count())
        ->and($etat['initiative'][0])->toMatchArray(['entite' => 'heros', 'id' => $a->id, 'a_joue' => false])
        ->and($etat['initiative'][1])->toMatchArray(['entite' => 'heros', 'id' => $b->id, 'a_joue' => false])
        ->and($etat['initiative'][2]['entite'])->toBe('monstre')
        ->and($etat['mj_reflechit'])->toBeFalse();

    // Narration de repli (file synchrone, pas de LLM) : le jeu reste raconté.
    expect($etat['narration'])->toBeString()->not->toBeEmpty();

    // Menu de repli MOTEUR mémorisé pour le joueur : Se déplacer / Fouiller / Attendre.
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, $joueur->id));
    expect($menu)->not->toBeNull();
    $ids = collect($menu['menu']['options'])->pluck('id')->all();
    expect($ids)->toContain('se_deplacer')->toContain('fouiller')->toContain('attendre');

    // L'API Anthropic n'a JAMAIS été appelée (même via la fake) : sans clé,
    // les skills basculent en repli avant tout appel réseau.
    Http::assertNotSent(fn ($requete) => str_contains($requete->url(), 'anthropic'));
});

it('refuse de démarrer une quête quand une est déjà en cours', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($joueur, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $this->postJson('/api/groupes/table-1/quetes')->assertStatus(422);
});

it('démarre la dernière quête de l\'arc en boss final, avec le boss du bestiaire', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe('table-finale', nbQuetes: 1); // quête unique = affrontement final

    creerHeros($joueur, $groupe, 'Albrecht', 1);

    $reponse = $this->postJson('/api/groupes/table-finale/quetes')->assertCreated();
    $reponse->assertJsonPath('quete.type_jalon', 'boss_final');

    $quete = Quete::findOrFail($reponse->json('quete.id'));
    $tiers = $quete->instancesMonstres()->with('monstre')->get()->map(fn ($i) => $i->monstre->tier)->all();

    expect($tiers)->toContain('boss');
});

it('exige un héros actif dans le groupe pour démarrer la quête', function () {
    connecterJoueur('intrus');
    creerGroupe();

    $this->postJson('/api/groupes/table-1/quetes')->assertStatus(422);
});
