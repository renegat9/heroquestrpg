<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CatalogueConstructeur;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\MoteurDread;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LES EXTENSIONS ET FIRST LIGHT EN MODE CLASSIQUE — lot E1 (2026-11-22) : ce que la relecture des dix derniers livrets
 * demande à trois livrets ou plus. Plateau inventé (`DocumentExemple`).
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
        ObjetSeeder::class, SortSeeder::class, SortDreadSeeder::class]);
});

/** La campagne d'essai, salle de départ vide (une salle occupée ne se fouille pas), fin « sortir ». */
function campagneE1(array $quete = []): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0] = array_replace($campagne['quetes'][0], [
        'monstres' => [],
        'fin' => ['type' => 'sortir', 'libelle' => 'Ressortir.'],
    ], $quete);

    return $campagne;
}

function codesE1(array $campagne): array
{
    return array_column(app(ValidationCampagne::class)->valider($campagne), 'code');
}

function herosE1(string $joueur, string $nom): Personnage
{
    $compte = connecterJoueur($joueur);

    return Personnage::create([
        'joueur_id' => $compte->id, 'nom' => $nom, 'classe' => $nom === 'Albrecht' ? 'barbare' : 'nain', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 30, 'pv_body' => 30, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
}

/** Albrecht et Brunhild, chacun avec sa bourse ; Albrecht a la main. */
function partieE1ADeux(object $test, array $campagne): array
{
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $albrecht = herosE1('alice', 'Albrecht');
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $albrecht->id])
        ->assertCreated()->json('groupe.identifiant');
    $brunhild = herosE1('bob', 'Brunhild');
    $test->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $brunhild->id])->assertSuccessful();
    $test->actingAs(\App\Auth\JoueurAuthentifiable::find($albrecht->joueur_id), 'joueur');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();

    return ['code' => $code, 'groupe' => $groupe, 'quete' => Quete::findOrFail($groupe->refresh()->quete_courante_id),
        'albrecht' => $albrecht, 'brunhild' => $brunhild];
}

/** @return array{code: string, groupe: Groupe, quete: Quete, heros: Personnage} */
function partieE1(object $test, array $campagne, string $classe = 'barbare'): array
{
    $campagne['prerequis_heros'] = [];
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => $classe, 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 30, 'pv_body' => 30, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();

    return ['code' => $code, 'groupe' => $groupe, 'quete' => Quete::findOrFail($groupe->refresh()->quete_courante_id), 'heros' => $heros];
}

// ---------------------------------------------------------------- 1. le monstre errant en plusieurs figures

it('valide un monstre errant en objet : nombre, profil d\'auteur ; refuse le reste', function () {
    $ok = campagneE1(['monstre_errant' => ['slug' => 'squelette', 'nombre' => 2, 'nom' => 'Sentinelle', 'pv_body' => 2]]);
    expect(codesE1($ok))->toBe([]);

    expect(codesE1(campagneE1(['monstre_errant' => ['slug' => 'squelette', 'nombre' => 7]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE1(campagneE1(['monstre_errant' => ['slug' => 'inconnu']])))->toBe([ValidationCampagne::CATALOGUE_INCONNU])
        // Il surgit : ni sommeil ni refuge.
        ->and(codesE1(campagneE1(['monstre_errant' => ['slug' => 'squelette', 'inactif' => true]])))->not->toBe([])
        ->and(codesE1(campagneE1(['monstre_errant' => ['nombre' => 2]])))->not->toBe([]);
});

it('la carte « monstre errant » fait sortir TOUTES les figures de l\'auteur, à son profil, et chacune au contact frappe', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieE1($this, campagneE1([
        'monstre_errant' => ['slug' => 'squelette', 'nombre' => 2, 'nom' => 'Sentinelle de pierre', 'pv_body' => 3],
    ]));
    empilerCarteFouille($quete, ['issue' => 'errant']);
    Cache::forget(GenererMenu::cleDuHeros($groupe, $heros));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();

    $resultat = $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->assertJsonPath('resultat.issue', 'errant')->json('resultat');

    $sortis = $quete->instancesMonstres()->get();
    expect($sortis)->toHaveCount(2)
        ->and($sortis->pluck('pv_body')->all())->toBe([3, 3])
        ->and($sortis->map->nomAffiche()->unique()->values()->all())->toBe(['Sentinelle de pierre'])
        ->and($resultat['monstres'])->toHaveCount(2)
        ->and($resultat['monstre']['instance_id'])->toBe($resultat['monstres'][0]['instance_id'])
        // Les deux naissent au contact (huit voisines libres) : la première frappe sous `frappe_errant`, la seconde
        // sous `frappes_errants`.
        ->and($resultat)->toHaveKey('frappe_errant')
        ->and($resultat['frappes_errants'] ?? [])->toHaveCount(1);

    $fil = collect(app(\App\Partie\JournalCombat::class)->depuisResultat($resultat, 'Albrecht'))
        ->pluck('texte')->implode(' | ');
    expect($fil)->toContain('Sentinelle de pierre ×2 surgissent du coffre que fouille Albrecht');
});

it('un errant écrit en simple slug sort toujours UNE figure, sans profil', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieE1($this, campagneE1(['monstre_errant' => 'squelette']));
    empilerCarteFouille($quete, ['issue' => 'errant']);
    Cache::forget(GenererMenu::cleDuHeros($groupe, $heros));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();

    $resultat = $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'fouiller_tresor'])->assertStatus(202)->json('resultat');

    expect($quete->instancesMonstres()->count())->toBe(1)
        ->and($resultat)->not->toHaveKey('monstres')
        ->and($resultat)->not->toHaveKey('frappes_errants');
});

// ---------------------------------------------------------------- 3. sorts de Dread et capacités du profil

it('valide les sorts de Dread et les capacités d\'un profil ; refuse un sort inconnu, un doublon, une capacité hors liste', function () {
    $sorts = array_column(app(CatalogueConstructeur::class)->palette('sorts_dread'), 'slug');
    $monstre = fn (array $profil) => campagneE1(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3] + $profil]]);

    expect(codesE1($monstre(['sorts_dread' => [$sorts[0], $sorts[1]], 'capacites' => ['attaque_diagonale', 'deux_sorts_premier_tour']])))->toBe([])
        ->and(codesE1($monstre(['sorts_dread' => ['sort-inconnu']])))->toBe([ValidationCampagne::CATALOGUE_INCONNU])
        ->and(codesE1($monstre(['sorts_dread' => [$sorts[0], $sorts[0]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE1($monstre(['capacites' => ['voler']])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
});

it('les sorts de l\'auteur REMPLACENT le répertoire de la fiche, un usage par sort ; les capacités s\'ajoutent', function () {
    $catalogue = app(CatalogueConstructeur::class);
    $sorts = array_slice($catalogue->palette('sorts_dread'), 0, 3);
    ['quete' => $quete] = partieE1($this, campagneE1([
        'monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'nom' => 'Vilor',
            'sorts_dread' => array_column($sorts, 'slug'), 'capacites' => ['attaque_diagonale', 'deux_sorts_par_tour']]],
    ]));

    $vilor = $quete->instancesMonstres()->with('monstre')->firstOrFail();
    $dread = app(MoteurDread::class);

    expect($dread->repertoireDe($vilor))->toBe(array_column($sorts, 'nom'))
        ->and((int) $vilor->usages_dread)->toBe(3)
        ->and($vilor->aCapacite('attaque_diagonale'))->toBeTrue()
        ->and($vilor->aCapacite('double_sort'))->toBeTrue()
        ->and($dread->doubleSortDisponible($vilor, false))->toBeTrue();

    // Une figure SANS profil garde sa fiche (l'orque ne lance rien, ne frappe pas en diagonale).
    $nu = InstanceMonstre::create(['quete_id' => $quete->id, 'monstre_id' => Monstre::where('nom_base', 'Orque')->value('id'),
        'pv_body' => 1, 'pv_mind' => 2, 'position_x' => 8, 'position_y' => 3, 'etat' => 'actif']);
    expect($dread->repertoireDe($nu->load('monstre')))->toBe([])
        ->and($nu->aCapacite('attaque_diagonale'))->toBeFalse();
});

it('« deux sorts au premier tour » est la forme paramétrée du double sort', function () {
    $instance = new InstanceMonstre(['habillage' => ['profil_quete' => ['capacites' => ['deux_sorts_premier_tour']]]]);
    $instance->setRelation('monstre', new Monstre(['capacites' => []]));

    expect($instance->capaciteParametree('double_sort'))->toBe(['premier_tour' => true])
        ->and(app(MoteurDread::class)->doubleSortDisponible($instance, true))->toBeTrue()
        ->and(app(MoteurDread::class)->doubleSortDisponible($instance, false))->toBeFalse();
});

// ---------------------------------------------------------------- 2. l'or d'une note partagé

it('valide `partage` sur l\'effet `or` (vocabulaire des récompenses), refuse le reste', function () {
    $note = fn (array $or) => campagneE1(['notes' => [['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
        'alors' => [['effet' => 'or', 'or' => 600] + $or]]]]);

    expect(codesE1($note([])))->toBe([])
        ->and(codesE1($note(['partage' => 'groupe'])))->toBe([])
        ->and(codesE1($note(['partage' => 'par_heros'])))->toBe([])
        ->and(codesE1($note(['partage' => 'tous'])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
});

it('l\'or d\'un coffre « divided among all surviving heroes » va aux bourses des héros de la quête', function (?string $partage, array $attendu, string $phrase) {
    $campagne = campagneE1(['notes' => [['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
        'alors' => [['effet' => 'or', 'or' => 601] + ($partage === null ? [] : ['partage' => $partage])]]]]);
    ['code' => $code, 'groupe' => $groupe, 'albrecht' => $albrecht, 'brunhild' => $brunhild] = partieE1ADeux($this, $campagne);
    Cache::forget(GenererMenu::cleDuHeros($groupe, $albrecht));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();

    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'fouiller_tresor'])->assertStatus(202)->assertJsonPath('resultat.issue', 'note');

    expect([(int) $albrecht->fresh()->or, (int) $brunhild->fresh()->or])->toBe($attendu)
        ->and((int) $groupe->fresh()->or)->toBe(0);
    $note = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()->first(fn ($e) => ($e->payload['type'] ?? null) === 'note_de_quete');
    $fil = collect(app(\App\Partie\JournalCombat::class)->depuisResultat($note->payload, 'Albrecht'))->pluck('texte')->implode(' | ');
    expect($fil)->toContain($phrase);
})->with([
    'au fouilleur' => [null, [601, 0], 'Albrecht trouve 601 pièces d\'or'],
    // Le reste de la division (1 pièce) va au premier dans l'ordre du tour.
    'partagé' => ['groupe', [301, 300], 'Le groupe trouve 601 pièces d\'or (Albrecht +301, Brunhild +300)'],
    'à chacun' => ['par_heros', [601, 601], 'Chaque héros reçoit 601 pièces d\'or'],
]);

// ---------------------------------------------------------------- 4. le coffre piégé de l'auteur

function coffreE1(array $coffre, array $alors = [['effet' => 'or', 'or' => 50]]): array
{
    return campagneE1([
        'coffres_pieges' => [$coffre],
        'notes' => [['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']], 'alors' => $alors]],
    ]);
}

function choixE1(object $test, array $partie, string $option): \Illuminate\Testing\TestResponse
{
    Cache::forget(GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $test->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();

    return $test->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => $option]);
}

function optionsE1(object $test, array $partie): array
{
    Cache::forget(GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));

    return collect($test->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id')->all();
}

it('valide un coffre piégé : salle ouverte, piège de coffre, dégâts, toute la salle ; refuse le reste', function () {
    expect(codesE1(coffreE1(['salle' => 'entree'])))->toBe([])
        ->and(codesE1(coffreE1(['salle' => 'entree', 'piege' => 'fiole-de-poison', 'degats' => 2, 'toute_la_salle' => true])))->toBe([])
        ->and(codesE1(coffreE1(['salle' => 'crypte'])))->toBe([ValidationCampagne::SALLE_INCONNUE])
        ->and(codesE1(coffreE1(['salle' => 'entree', 'piege' => 'fosse'])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE1(coffreE1(['salle' => 'entree', 'piege' => 'piege-de-boule-de-flammes', 'degats' => 2])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE1(coffreE1(['salle' => 'entree', 'toute_la_salle' => true])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE1(coffreE1(['salle' => 'entree', 'degats' => 11])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
});

it('fouiller le trésor AVANT de désamorcer déclenche le coffre : dégâts fixes, fin du tour, puis la note', function () {
    $partie = partieE1($this, coffreE1(['salle' => 'entree', 'degats' => 2]));

    $resultat = choixE1($this, $partie, 'fouiller_tresor')->assertStatus(202)->assertJsonPath('resultat.issue', 'note')->json('resultat');

    expect((int) $partie['heros']->fresh()->pv_body)->toBe(28)
        ->and((int) $partie['heros']->fresh()->or)->toBe(50)
        ->and($resultat['pieges_declenches'][0]['degats'])->toBe(2)
        // Le tour s'arrête (seul en jeu, le round suivant s'ouvre aussitôt : on lit la décision publiée).
        ->and($resultat['pieges_declenches'][0]['fin_de_tour'])->toBeTrue();

    // Une seule fois : il est déclenché.
    expect(app(\App\Partie\Classique\CoffresPieges::class)->actif($partie['quete']->fresh(), 0))->toBeNull();
});

it('la fouille des pièges le TROUVE, le Nain le DÉSAMORCE, et la fouille du trésor ne blesse plus personne', function () {
    app()->instance(\App\Engine\Des\LanceurDes::class, new \App\Engine\Des\LanceurDeterministe(array_fill(0, 60, 1)));
    $partie = partieE1($this, coffreE1(['salle' => 'entree', 'degats' => 3]), 'nain');

    expect(optionsE1($this, $partie))->not->toContain('desamorcer_coffre');
    $jet = choixE1($this, $partie, 'fouiller')->assertStatus(202)->json('resultat');
    expect($jet['coffre_piege_trouve']['nom'])->toBe('Piège de coffre')
        ->and($jet['a_trouve'])->toBeTrue();
    $fil = collect(app(\App\Partie\JournalCombat::class)->depuisResultat($jet, 'Albrecht'))->pluck('texte')->implode(' | ');
    expect($fil)->toContain('découvre un piège sur le coffre');

    // Le tour suivant : le menu propose de le désamorcer (un dé, seul le bouclier noir rate).
    \App\Models\EtatPersonnageQuete::query()->whereKey($partie['quete']->etatsPersonnages()->first()->id)
        ->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);
    expect(optionsE1($this, $partie))->toContain('desamorcer_coffre');
    choixE1($this, $partie, 'desamorcer_coffre')->assertStatus(202)->assertJsonPath('resultat.desarme', true);

    \App\Models\EtatPersonnageQuete::query()->whereKey($partie['quete']->etatsPersonnages()->first()->id)
        ->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);
    $fouille = choixE1($this, $partie, 'fouiller_tresor')->assertStatus(202)->json('resultat');
    expect((int) $partie['heros']->fresh()->pv_body)->toBe(30)
        ->and($fouille)->not->toHaveKey('pieges_declenches');
});

it('« toute la salle » : chaque héros de la salle subit les dégâts, une ligne chacun', function () {
    $campagne = coffreE1(['salle' => 'entree', 'degats' => 2, 'toute_la_salle' => true]);
    ['code' => $code, 'groupe' => $groupe, 'albrecht' => $albrecht, 'brunhild' => $brunhild] = partieE1ADeux($this, $campagne);
    Cache::forget(GenererMenu::cleDuHeros($groupe, $albrecht));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();

    $resultat = $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'fouiller_tresor'])->assertStatus(202)->json('resultat');

    expect([(int) $albrecht->fresh()->pv_body, (int) $brunhild->fresh()->pv_body])->toBe([28, 28])
        ->and($resultat['pieges_declenches'][0]['zone'])->toBeTrue();
    $fil = collect(app(\App\Partie\JournalCombat::class)->depuisResultat($resultat, 'Albrecht'))->pluck('texte')->implode(' | ');
    expect($fil)->toContain('Brunhild encaisse −2 PV')->toContain('Albrecht encaisse −2 PV');
});
