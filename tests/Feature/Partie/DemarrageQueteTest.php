<?php

declare(strict_types=1);

use App\Events\NarrationDiffusee;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Quete;
use App\Partie\EtatGroupe;
use App\Partie\ScorePuissance;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use App\Support\Journal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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
        // Révélation par salle : les monstres (salles autres que celle de départ)
        // sont DORMANTS au démarrage → absents des entités et de l'initiative.
        ->and(collect($etat['entites'])->where('type', 'monstre'))->toHaveCount(0)
        ->and($etat['initiative'][0])->toMatchArray(['entite' => 'heros', 'id' => $a->id, 'a_joue' => false])
        ->and($etat['initiative'][1])->toMatchArray(['entite' => 'heros', 'id' => $b->id, 'a_joue' => false])
        ->and(collect($etat['initiative'])->where('entite', 'monstre'))->toHaveCount(0)
        // « MJ réfléchit » reste VRAI après le démarrage : depuis B1, il ne
        // s'éteint qu'une fois la narration de lancement LUE par la table
        // (POST table/lecture-terminee), pas dès sa génération. Aucune table
        // n'a lu ici → toujours vrai (le premier joueur attend le narrateur).
        ->and($etat['mj_reflechit'])->toBeTrue();

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

it('refuse de démarrer la quête à qui n\'est ni membre ni la table du groupe', function () {
    connecterJoueur('intrus'); // connecté, mais aucun héros actif dans ce groupe
    creerGroupe();

    // Autorisation membre-OU-table : un intrus (ni l'un ni l'autre) → 403.
    $this->postJson('/api/groupes/table-1/quetes')->assertStatus(403);
});

it('laisse le NARRATEUR (session de table, sans compte) démarrer la quête', function () {
    // Un groupe avec un héros, mais on N'est PAS connecté comme joueur : on ouvre
    // seulement la TABLE (comme le fait l'écran narrateur). Le bouton « Lancer la
    // quête » de la table doit alors marcher (avant : 401 → faux « déconnecté »).
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/deconnexion'); // on quitte le rôle joueur

    $this->postJson('/api/table', ['code' => 'table-1'])->assertOk(); // session de table
    $this->postJson('/api/groupes/table-1/quetes')
        ->assertCreated()
        ->assertJsonPath('quete.etat', 'en_cours');
});

it('remet les héros à plein PV au démarrage de CHAQUE quête suivante (P2, doc 01 §13)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe(nbQuetes: 2);
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete1 = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Blessures encaissées en quête (simulées directement).
    $hero->update(['pv_body' => 2, 'pv_mind' => 0]);

    // Quête 1 remportée (tous les monstres vaincus) → retour au hub.
    $quete1->instancesMonstres()->update(['etat' => 'vaincu']);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.quete.etat', 'terminee');

    expect($groupe->fresh()->phase)->toBe('hub');

    // Toujours blessé AU HUB : pas de récupération par repos (P2) tant que
    // la quête suivante n'a pas démarré.
    expect((int) $hero->fresh()->pv_body)->toBe(2);

    // Quête 2 démarrée → remis À PLEIN (récupération intégrale entre deux quêtes).
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $hero->refresh();
    expect((int) $hero->pv_body)->toBe($hero->pv_body_max)
        ->and((int) $hero->pv_mind)->toBe($hero->pv_mind_max);
});

it('numérote les narrations en séquence — anti-inversion si un job lent répond après une plus récente', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe(nbQuetes: 2);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    Event::fake([NarrationDiffusee::class]);

    // Cérémonie de lancement (DemarreurQuete) : diffusée IMMÉDIATEMENT, mais
    // désormais journalisée et séquencée comme toute narration.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    Event::assertDispatched(NarrationDiffusee::class, fn (NarrationDiffusee $e) => $e->sequence !== null);
    // Le démarrage diffuse AU MOINS deux narrations (cérémonie de lancement
    // immédiate + narration IA du job GenererNarration, exécuté en sync dans
    // les tests) : la plus récente en séquence est celle qui doit faire foi.
    $sequenceMax = collect(Event::dispatched(NarrationDiffusee::class))->max(fn ($args) => $args[0]->sequence);

    // EtatGroupe expose la DERNIÈRE narration en séquence, jamais une plus
    // ancienne — même si elle a été journalisée après coup (job en retard).
    Journal::ajouter($groupe->fresh(), 'narration', ['texte' => 'Un très vieux souvenir, journalisé en retard.']);
    $vieilEvenement = Evenement::where('groupe_id', $groupe->id)->where('type', 'narration')->orderByDesc('sequence')->first();
    // On le force à une séquence ANTÉRIEURE à la cérémonie (simule un job dont
    // le dispatch était plus vieux mais dont le traitement a traîné).
    $vieilEvenement->update(['sequence' => 0]);

    $etat = app(EtatGroupe::class)->payload($groupe->fresh());
    $derniere = Evenement::where('groupe_id', $groupe->id)->where('type', 'narration')->orderByDesc('sequence')->first();

    expect($etat['narration_sequence'])->toBe($sequenceMax)
        ->and($etat['narration_sequence'])->toBeGreaterThan($vieilEvenement->sequence)
        ->and($derniere->id)->not->toBe($vieilEvenement->id);
});
