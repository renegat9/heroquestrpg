<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Events\MouvementAnime;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Déplacement : l'allonce du tour (base + 1d6, doc 03 §3) est lancée UNE fois
 * et exposée dans le menu, pour que le joueur la voie avant de choisir sa case.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('le menu expose l\'allonce (base + 1d6) lancée une seule fois par tour', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1); // deplacement_base = 4

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    // Force un nouveau tour « vierge » puis fige le d6 à 4.
    $etat->update(['deplacement_tour' => null, 'a_joue' => false]);
    desFiges([4]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $dep = collect($menu['options'])->firstWhere('type', 'deplacement');

    expect($dep['parametres']['base'])->toBe(4)
        ->and($dep['parametres']['de'])->toBe(4)
        ->and($dep['parametres']['portee'])->toBe(8)        // 4 + 1d6(4)
        ->and((int) $etat->fresh()->deplacement_tour)->toBe(8);

    // Régénérer le menu ne RELANCE pas le dé (allonce stable sur le tour).
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    expect((int) $etat->fresh()->deplacement_tour)->toBe(8);
});

it('Armure de plates : le héros perd 2 cases de déplacement (encombrement)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1); // deplacement_base = 4

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    // Armure ENFILÉE directement : on teste la règle de déplacement, pas la
    // maîtrise d'équipement (qui a ses propres tests).
    $this->seed(ObjetSeeder::class);
    Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => Objet::where('nom', 'Armure de plates')->firstOrFail()->id,
        'emplacement' => 'armure',
        'quantite' => 1,
    ]);

    $etat->update(['deplacement_tour' => null, 'a_joue' => false]);
    desFiges([6]); // base 4 + d6 6 − malus 2 = 8
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    $dep = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])
        ->firstWhere('type', 'deplacement');

    // Le malus n'avait JAMAIS joué : aucun appelant ne le passait au moteur, et
    // l'armure la plus chère du jeu n'avait que des avantages. Il vaut
    // aujourd'hui 2 cases — « a 2 square movement penalty » (carte Plate Mail)
    // — et non plus la suppression du d6, qui coûtait 3,5 cases en moyenne ET
    // rendait le déplacement déterministe.
    expect($dep['parametres']['base'])->toBe(4)
        ->and($dep['parametres']['portee'])->toBe(8)
        ->and((int) $etat->fresh()->deplacement_tour)->toBe(8);
});

it('déplacement fractionné : un pas laisse des points, on peut CONTINUER à se déplacer (E1)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2); // 2nd héros : le tour ne passe pas aux monstres

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $etatA->update(['deplacement_tour' => 8, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);
    $cible = caseAdjacenteLibre($quete, (int) $etatA->position_x, (int) $etatA->position_y);

    // 1) Un pas (1 case) sur 8 → il reste 7 points : le mouvement N'EST PAS fini.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202)
        ->assertJsonPath('resultat.deplacement_restant', 7);
    $etatA->refresh();
    expect($etatA->deplacement_restant)->toBe(7)
        ->and($etatA->a_deplace)->toBeFalse()
        ->and($etatA->a_agi)->toBeFalse()
        ->and($etatA->a_joue)->toBeFalse();

    // 2) Le menu régénéré propose ENCORE le déplacement (« Continuer »), à la
    //    portée restante, plus les actions et « Terminer le tour ».
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $dep = collect($menu['options'])->firstWhere('type', 'deplacement');
    expect($dep)->not->toBeNull()
        ->and($dep['parametres']['portee'])->toBe(7)
        ->and(collect($menu['options'])->firstWhere('type', 'attente'))->not->toBeNull();

    // 3) Terminer le tour → a_joue.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);
    expect($etatA->fresh()->a_joue)->toBeTrue();
});

it('diffuse le trajet du héros (.mouvement.anime) pour l\'animation case-par-case (E4)', function () {
    Event::fake([MouvementAnime::class]);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2); // pas de phase monstres

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $etatA->update(['deplacement_tour' => 8, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    $depart = ['x' => (int) $etatA->position_x, 'y' => (int) $etatA->position_y];
    $cible = caseAdjacenteLibre($quete, $depart['x'], $depart['y']);

    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202);

    Event::assertDispatched(MouvementAnime::class, function ($e) use ($groupe, $heroA, $depart, $cible) {
        $mv = collect($e->mouvements)->firstWhere('id', $heroA->id);

        return $e->groupe->id === $groupe->id
            && $mv !== null
            && $mv['type'] === 'heros'
            && $mv['depart'] === $depart
            && end($mv['chemin']) === $cible; // le chemin se termine sur la case visée
    });
});

it('SACRIFIE le déplacement restant quand on agit après avoir bougé, sans terminer le tour pour autant', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $etatA->update(['deplacement_tour' => 8, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);
    $cible = caseAdjacenteLibre($quete, (int) $etatA->position_x, (int) $etatA->position_y);

    // Un pas → il reste 7 points.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202);
    expect($etatA->fresh()->deplacement_restant)->toBe(7);

    // Puis « Fouiller » (action) : le mouvement entamé est PERDU — règle du
    // plateau, on se déplace puis on agit, ou l'inverse, jamais les trois
    // (décision de René, 2026-08-07 ; ce test verrouillait l'intercalation).
    // Le tour n'est pas terminé pour autant : le joueur garde la main.
    desFiges(array_fill(0, 20, 1));
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202);
    $etatA->refresh();
    expect($etatA->deplacement_restant)->toBe(0)
        ->and($etatA->a_deplace)->toBeTrue()
        ->and($etatA->a_agi)->toBeTrue()
        ->and($etatA->a_joue)->toBeFalse();

    // Le menu régénéré n'offre PLUS de déplacement (allonce sacrifiée), mais
    // toujours « Terminer le tour » — le héros n'est jamais sans issue.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    expect(collect($menu['options'])->firstWhere('type', 'deplacement'))->toBeNull()
        ->and(collect($menu['options'])->firstWhere('id', 'attendre'))->not->toBeNull();

    // Le joueur DÉCIDE de terminer → a_joue.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);
    expect($etatA->fresh()->a_joue)->toBeTrue();
});

it('laisse se déplacer SUR la case d\'un monstre vaincu (il quitte le plateau, ne bloque plus)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $etatA->update(['deplacement_tour' => 6, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    // Un monstre VAINCU (pv 0) posé sur une case adjacente au héros, révélé —
    // il devait avoir « quitté le plateau ». (La quête garde d'autres monstres
    // actifs, donc elle reste en cours.)
    $cible = caseAdjacenteLibre($quete, (int) $etatA->position_x, (int) $etatA->position_y);
    $mort = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $mort->update(['position_x' => $cible['x'], 'position_y' => $cible['y'], 'pv_body' => 0, 'etat' => 'vaincu', 'revele' => true]);

    // Absent de l'état partagé → absent de la carte manette, qui ne peut donc
    // plus bloquer sa case (c'était le bogue signalé).
    $entites = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites');
    expect(collect($entites)->where('type', 'monstre')->pluck('id')->all())->not->toContain($mort->id);

    // Et le moteur ACCEPTE de se déplacer sur cette case.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202)
        ->assertJsonPath('resultat.vers', $cible);
    expect([(int) $etatA->fresh()->position_x, (int) $etatA->fresh()->position_y])
        ->toBe([$cible['x'], $cible['y']]);
});

it('permet d\'AGIR PUIS de se déplacer (action avant mouvement), le mouvement reste offert', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $etatA->update(['deplacement_tour' => 6, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    // 1) Le héros AGIT d'abord (fouiller) — aucun déplacement encore fait.
    desFiges(array_fill(0, 20, 4));
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])->assertStatus(202);
    $etatA->refresh();
    expect($etatA->a_agi)->toBeTrue()
        ->and($etatA->a_deplace)->toBeFalse()
        ->and($etatA->a_joue)->toBeFalse();

    // Le menu propose ENCORE « Se déplacer » (le créneau mouvement est intact).
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);
    expect(collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->firstWhere('type', 'deplacement'))
        ->not->toBeNull();

    // 2) PUIS il se déplace : accepté, la figurine bouge.
    $cible = caseAdjacenteLibre($quete, (int) $etatA->position_x, (int) $etatA->position_y);
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202)
        ->assertJsonPath('resultat.vers', $cible);
    $etatA->refresh();
    expect((int) $etatA->position_x)->toBe($cible['x'])
        ->and((int) $etatA->position_y)->toBe($cible['y']);
});

it('sacrifie le déplacement restant quand on AGIT après avoir commencé à bouger', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $hero->id)->firstOrFail();

    // Mouvement ENTAMÉ : 8 points au tour, 5 encore en poche.
    $etat->update(['deplacement_tour' => 8, 'deplacement_restant' => 5, 'a_deplace' => false, 'a_agi' => false]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])->assertStatus(202);

    // Règle du plateau : se déplacer PUIS agir, ou agir PUIS se déplacer —
    // jamais les trois. Le reste de l'allonce est perdu.
    $etat->refresh();
    expect((int) $etat->deplacement_restant)->toBe(0)
        ->and((bool) $etat->a_deplace)->toBeTrue()
        ->and((bool) $etat->a_agi)->toBeTrue();
});

it('garde le déplacement ENTIER quand on agit AVANT d\'avoir bougé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $hero->id)->firstOrFail();

    // Aucun mouvement entamé : le d6 du tour n'est même pas lancé.
    $etat->update(['deplacement_tour' => null, 'deplacement_restant' => null,
        'a_deplace' => false, 'a_agi' => false]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])->assertStatus(202);

    // Agir d'abord ne coûte RIEN au déplacement : il reste entier.
    $etat->refresh();
    expect((bool) $etat->a_agi)->toBeTrue()
        ->and((bool) $etat->a_deplace)->toBeFalse();
});

it('diffuse aussi le trajet du MONSTRE, pour qu\'il ne se téléporte pas sur la table', function () {
    Event::fake([MouvementAnime::class]);

    // ⚠ Le trajet du monstre est enregistré AVANT la branche d'attaque
    // (ResolveurTour), pour couvrir « s'approche PUIS frappe » dans le même
    // tour. Sans ce test, seule la moitié héros de `mouvementsAnime` était
    // verrouillée — et c'est la moitié monstre qu'on regarde à la table.
    ['groupe' => $groupe, 'quete' => $quete, 'instance' => $instance, 'etatHeros' => $etatHeros, 'alice' => $alice]
        = demarrerQueteAvecMonstre('Gobelin');

    // Éloigner le monstre : au contact il frappe sans bouger, et il n'y a alors
    // aucun trajet à animer — la situation ne se teste pas toute seule.
    $loin = null;
    for ($d = 3; $d <= 6 && $loin === null; $d++) {
        foreach ([[$d, 0], [0, $d], [-$d, 0], [0, -$d]] as [$dx, $dy]) {
            $x = (int) $etatHeros->position_x + $dx;
            $y = (int) $etatHeros->position_y + $dy;
            if ($x >= 0 && $y >= 0 && caseQueteLibre($quete, $x, $y)) {
                $loin = ['x' => $x, 'y' => $y];
                break;
            }
        }
    }

    expect($loin)->not->toBeNull('aucune case libre à distance pour éloigner le monstre');
    $instance->update(['position_x' => $loin['x'], 'position_y' => $loin['y']]);

    // Le héros termine son tour → phase des monstres → le gobelin s'approche.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    Event::assertDispatched(MouvementAnime::class, function ($e) use ($groupe, $instance, $loin) {
        $mv = collect($e->mouvements)->firstWhere('type', 'monstre');

        return $e->groupe->id === $groupe->id
            && $mv !== null
            && (int) $mv['id'] === (int) $instance->id
            && $mv['depart'] === $loin
            // Un chemin, pas un saut : au moins une case parcourue.
            && is_array($mv['chemin']) && count($mv['chemin']) >= 1;
    });
});
