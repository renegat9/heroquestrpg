<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\Mercenaire;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Alliés — mercenaires + compagnon animal (Phase 2, 3.5) : recrutement au hub
 * sur la bourse commune, instanciation au démarrage de quête, phase alliée
 * dédiée (hors initiative héros), consommation en fin de quête.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, MercenaireSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

it('expose le catalogue recrutable via GET /mercenaires', function () {
    connecterJoueur('alice');

    $catalogue = $this->getJson('/api/mercenaires')->assertOk()->json('mercenaires');

    // ⚠ Le Squelette Hearthkin (First Light, lot C) partage ce catalogue
    // sans jamais y figurer — `octroi_seul`, voir `MercenaireController::catalogue()`.
    expect($catalogue)->toHaveCount(Mercenaire::where('octroi_seul', false)->count());
    $premier = $catalogue[0];
    // Trié par prix croissant + bloc de stats complet.
    expect($premier['prix'])->toBeLessThanOrEqual($catalogue[count($catalogue) - 1]['prix']);
    foreach (['id', 'nom', 'type', 'prix', 'deplacement', 'attaque', 'portee', 'defense', 'pv_body', 'animal', 'description'] as $cle) {
        expect($premier)->toHaveKey($cle);
    }
});

it('expose les alliés recrutés au hub dans EtatGroupe.groupe.mercenaires', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $groupe->refresh();
    $mercos = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.mercenaires');

    expect($mercos)->toHaveCount(1)
        ->and($mercos[0]['nom'])->toBe('Fauchard')
        ->and($mercos[0]['animal'])->toBeFalse()
        ->and($mercos[0])->toHaveKeys(['id', 'mercenaire_id', 'type', 'pv_body', 'pv_body_max']);
});

it('recrute un mercenaire au hub en débitant la bourse commune', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $hallebardier = Mercenaire::where('nom', 'Fauchard')->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $hallebardier->id])
        ->assertStatus(201)
        ->assertJsonPath('recrue.nom', 'Fauchard')
        ->assertJsonPath('or', 500 - (int) $hallebardier->prix);

    expect($groupe->fresh()->mercenaires()->count())->toBe(1)
        ->and((int) $groupe->fresh()->or)->toBe(500 - (int) $hallebardier->prix);
});

it('refuse le recrutement hors du hub', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500, 'phase' => 'quete']);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])
        ->assertStatus(422);

    expect($groupe->fresh()->mercenaires()->count())->toBe(0);
});

it('refuse le recrutement si l\'or est insuffisant', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 10]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])
        ->assertStatus(422);

    expect((int) $groupe->fresh()->or)->toBe(10);
});

it('n\'autorise qu\'un seul compagnon animal par groupe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 1000]);

    $loup = Mercenaire::where('animal', true)->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $loup->id])->assertStatus(201);
    // Deuxième animal refusé.
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $loup->id])->assertStatus(422);

    expect($groupe->fresh()->mercenaires()->count())->toBe(1);
});

it('instancie l\'allié au démarrage de quête et l\'expose dans l\'état', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $allie = $groupe->fresh()->mercenaires()->first();
    expect($allie->position_x)->not->toBeNull()
        ->and($allie->etat)->toBe('actif');

    // L'allié figure dans les entités de l'état partagé avec le type « allie ».
    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $allieEntite = collect($etat['entites'])->firstWhere('type', 'allie');
    expect($allieEntite)->not->toBeNull()
        ->and($allieEntite['nom'])->toBe('Fauchard');
});

it('fait jouer l\'allié en phase dédiée : il attaque un monstre adjacent', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $allie = $groupe->fresh()->mercenaires()->first();
    $ax = (int) $allie->position_x;
    $ay = (int) $allie->position_y;

    // Un seul monstre, révélé, placé au contact de l'allié.
    $quete->instancesMonstres()->update(['revele' => true]);
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $contact = caseAdjacenteLibre($quete, $ax, $ay);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'revele' => true]);

    // Dés généreux (peu importe les dégâts) : on vérifie que l'allié AGIT.
    desFiges(array_fill(0, 80, 4));

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $actionsAllies = collect($reponse->json('resultat.tour_allies.actions'));
    expect($actionsAllies->isNotEmpty())->toBeTrue()
        ->and($actionsAllies->contains(fn ($a) => ($a['type'] ?? null) === 'attaque_allie'))->toBeTrue();
});

it('restaure le mercenaire payé à la reprise après un TPK', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    expect($groupe->fresh()->mercenaires()->count())->toBe(1); // instancié au démarrage

    // TPK déterministe : héros à 1 PV, un monstre TRÈS résistant au contact (il
    // survit à l'allié et tue le héros au tour des monstres).
    $hero->update(['pv_body' => 1]);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    $quete->instancesMonstres()->orderBy('id')->firstOrFail()->update([
        'etat' => 'actif', 'revele' => true, 'pv_body' => 15,
        'position_x' => $contact['x'], 'position_y' => $contact['y'],
    ]);

    desFiges(array_fill(0, 80, 1)); // crânes partout : le monstre touche, rien n'est paré
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Quête échouée, retour au hub, allié PURGÉ à l'échec.
    expect($quete->fresh()->etat)->toBe('echouee')
        ->and($groupe->fresh()->phase)->toBe('hub')
        ->and($groupe->fresh()->mercenaires()->count())->toBe(0);

    // Reprise (snapshot debut_quete) : le mercenaire payé revient.
    $this->postJson('/api/groupes/table-1/reprise')->assertOk();

    $recrues = $groupe->fresh()->mercenaires()->with('mercenaire')->get();
    expect($recrues)->toHaveCount(1)
        ->and($recrues->first()->mercenaire->nom)->toBe('Fauchard')
        ->and($recrues->first()->etat)->toBe('actif');
});

it('consomme les alliés en fin de quête (victoire)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    expect($groupe->fresh()->mercenaires()->count())->toBe(1);

    // Victoire : tous les monstres vaincus, le héros termine son tour.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    desFiges(array_fill(0, 20, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Le donjon est nettoyé mais la quête reste ouverte : le groupe vote la
    // sortie quand il a fini de fouiller.
    acheverLaQuete($groupe);

    expect($groupe->fresh()->phase)->toBe('hub')
        // Alliés consommés en fin de quête.
        ->and($groupe->fresh()->mercenaires()->count())->toBe(0);
});

it('donne aux alliés officiels leur Mind et leurs capacités de carte', function () {
    // Les cartes portent CINQ valeurs — Movement, Attack, Defend, Body ET Mind.
    // Notre table n'en gardait que quatre : un allié sans Mind est insensible
    // à la peur et au sommeil sans que personne l'ait décidé.
    $allies = Mercenaire::all()->keyBy('nom');

    // 8 (5 mercenaires humains + 3 compagnons animaux) + le Squelette
    // Hearthkin (First Light, lot C) : même catalogue, jamais recrutable.
    expect($allies)->toHaveCount(9, 'les 5 mercenaires humains, les 3 compagnons animaux et le Squelette Hearthkin');

    expect((int) $allies['Ogre mercenaire']->pv_mind)->toBe(1)
        ->and((int) $allies['Éclaireur']->pv_mind)->toBe(2);

    // Les trois animaux : plus endurants que les humains (5 PV contre 2), et
    // fragiles d'esprit en échange.
    expect((int) $allies['Loup']->pv_body)->toBe(5)
        ->and((int) $allies['Loup']->pv_mind)->toBe(1)
        ->and((bool) $allies['Loup']->animal)->toBeTrue();

    // « can attack diagonally » et « move before and after an attack ».
    expect($allies['Fauchard']->capacites)->toContain('attaque_diagonale')
        ->and($allies['Croc-sabre']->capacites)->toContain('attaque_diagonale')
        ->and($allies['Raptor apprivoisé']->capacites)->toContain('tacticien');

    // Et l'inverse : personne ne frappe en diagonale sans que sa carte le dise.
    expect($allies['Estafier']->capacites ?? [])->not->toContain('attaque_diagonale');
});

it('laisse un allié DIAGONAL frapper une cible que l\'orthogonal n\'atteint pas', function () {
    // La règle vit dans Grille::sontAdjacentes : sans le drapeau, une cible en
    // diagonale est à distance 2 en Manhattan, donc hors de portée.
    $grille = new App\Partie\Grille(['cases' => array_fill(0, 5, array_fill(0, 5, 's'))]);

    expect($grille->sontAdjacentes(2, 2, 3, 3))->toBeFalse()
        ->and($grille->sontAdjacentes(2, 2, 3, 3, true))->toBeTrue()
        // ⚠ Le drapeau élargit d'UNE case, pas plus : deux cases restent hors
        // de portée, en diagonale comme en ligne droite.
        ->and($grille->sontAdjacentes(2, 2, 4, 4, true))->toBeFalse()
        ->and($grille->sontAdjacentes(2, 2, 2, 4, true))->toBeFalse();
});

/** Quête démarrée avec UN allié recruté (Fauchard) et un seul monstre actif. */
function queteAvecAllie(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    test()->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update(['revele' => true]);

    return [$groupe, $quete, $heros, $groupe->fresh()->mercenaires()->first(), $instance->fresh()];
}

it('montre les alliés dans l\'initiative, entre les héros et les monstres — l\'ordre réel du round', function () {
    // René, 2026-10-01 : « ne devrait-on pas voir les alliés dans la barre
    // d'initiative ». Ils jouaient déjà après les héros et avant les monstres
    // (`jouerFinDeRound()`), mais l'initiative publiée les taisait.
    [, , , $allie, $instance] = queteAvecAllie();

    $initiative = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('initiative'));

    expect($initiative->pluck('entite')->all())->toBe(['heros', 'allie', 'monstre'])
        ->and($initiative[1]['id'])->toBe($allie->id)
        ->and($initiative[1]['nom'])->toBe('Fauchard')
        ->and($initiative[2]['id'])->toBe($instance->id);

    // Portrait de chaque unité (René, 2026-10-01 : « afficher le portrait des
    // unités avec leur nom en dessous ») — le même que sa figurine de carte.
    $entites = collect($this->getJson('/api/groupes/table-1/etat')->json('entites'));
    foreach ($initiative as $entree) {
        $type = $entree['entite'] === 'allie' ? 'allie' : $entree['entite'];
        $figurine = $entites->first(fn ($e) => $e['type'] === $type && $e['id'] === $entree['id']);

        expect($entree['image_url'])->toBeString()->not->toBe('')
            ->and($entree['image_url'])->toBe($figurine['image_url']);
    }
});

it('publie les DÉS et les PV d\'un allié qui attaque, pour sa scène de table', function () {
    [, $quete, , $allie, $instance] = queteAvecAllie();
    $contact = caseAdjacenteLibre($quete, (int) $allie->position_x, (int) $allie->position_y);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    desFiges(array_fill(0, 80, 4));

    $attaque = collect($this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat.tour_allies.actions'))
        ->firstWhere('type', 'attaque_allie');

    expect($attaque)->not->toBeNull()
        ->and($attaque['allie_id'])->toBe($allie->id)
        ->and($attaque['faces_attaque'])->not->toBeEmpty()
        ->and($attaque)->toHaveKeys(['faces_defense', 'face_touchante', 'face_defensive']);

    $scene = app(App\Partie\SceneDeTable::class)->depuisResultat($attaque, App\Models\Personnage::firstOrFail())[0];
    $acteur = collect($scene['acteurs'])->firstWhere('role', 'attaquant');

    expect($acteur['pv'])->toBe(['courant' => (int) $allie->fresh()->pv_body, 'max' => (int) $allie->mercenaire->pv_body])
        ->and($scene['jet']['atk'])->not->toBeEmpty();
});

it('fait TRAVERSER un héros à l\'allié dans un couloir d\'une case — il ne reste plus immobile', function () {
    // Test en jeu du 2026-10-01 : Aldric posté dans le couloir d'une case
    // entre le loup et le squelette, le loup est resté `allie_immobile` trois
    // rounds de suite. Un héros traverse la case d'un compagnon ; un allié
    // le peut désormais aussi — sans jamais s'y arrêter.
    [, $quete, $heros, $allie, $instance] = queteAvecAllie();

    // Un couloir d'une case, ligne y = 1 : allié (1,1), héros (2,1), monstre (6,1).
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['cases'] = array_fill(0, 3, array_fill(0, 10, 'm'));
    for ($x = 1; $x <= 8; $x++) {
        $grille['cases'][1][$x] = 's';
    }
    foreach (['portes', 'mobilier', 'pieges', 'leviers', 'epreuves', 'terrain', 'glace', 'salles'] as $couche) {
        $grille[$couche] = [];
    }
    $carte->update(['grille' => $grille]);

    $allie->update(['position_x' => 1, 'position_y' => 1]);
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)
        ->update(['position_x' => 2, 'position_y' => 1]);
    $instance->update(['position_x' => 6, 'position_y' => 1]);

    desFiges(array_fill(0, 80, 4));

    $actions = collect($this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat.tour_allies.actions'));

    // Le Fauchard (Move 7) passe par la case du héros jusqu'au contact (5,1).
    expect($actions->pluck('type')->all())->not->toContain('allie_immobile')
        ->and($actions->firstWhere('type', 'attaque_allie'))->not->toBeNull();

    $allie->refresh();
    expect([(int) $allie->position_x, (int) $allie->position_y])->toBe([5, 1]);
});
