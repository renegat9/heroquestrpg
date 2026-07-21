<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Sorts des héros (doc 02, contrat docs/contrat-api.md) — tout par les menus :
 * attribution par ÉLÉMENTS (magicien à la création, elfe via les nœuds
 * d'arbre), récupération 1×/quête (S5) réinitialisée par DemarreurQuete,
 * résolution moteur par type (degats à distance + tir ami S3, mental S2
 * binaire, utilitaires en conditions), parchemins consommés dans tous les
 * cas (S1) et Concentration (S6) qui sacrifie le tour.
 *
 * Le hasard est figé par desFiges (LanceurDeterministe) — valeurs de d6 :
 * 1-3 = crâne, 4-5 = bouclier blanc, 6 = bouclier noir.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, // les parchemins dérivent des sorts
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

function sortIdParNom(string $nom): int
{
    return (int) Sort::where('nom', $nom)->value('id');
}

/**
 * Options du menu MOTEUR re-proposé à un joueur pour un héros.
 */
function optionsMenuSorts(Groupe $groupe, JoueurAuthentifiable $joueur, Personnage $hero): \Illuminate\Support\Collection
{
    GenererMenu::dispatchSync($groupe->id, (int) $joueur->id, (int) $hero->id);

    return collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $joueur->id))['menu']['options']);
}

/**
 * Quête démarrée avec un héros de CLASSE magicien (stats du helper, les
 * sorts feu+eau attachés comme à la création) et, au besoin, un second
 * héros barbare contrôlé par bob.
 *
 * @return array{0: JoueurAuthentifiable, 1: Groupe, 2: Personnage, 3: Quete, 4: ?JoueurAuthentifiable, 5: ?Personnage}
 */
function demarrerQueteSorts(bool $avecSecond = false): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $mage = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    $moteur = app(MoteurSorts::class);
    $moteur->attacherElement($mage, 'feu');
    $moteur->attacherElement($mage, 'eau');

    $bob = null;
    $brunhilde = null;

    if ($avecSecond) {
        $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
        $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2);
    }

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    return [$alice, $groupe, $mage, $quete, $bob, $brunhilde];
}

it('attache au magicien les 9 sorts de ses 3 éléments à la création (parité HeroQuest, défaut feu+eau+terre, validation stricte)', function () {
    connecterJoueur('alice');
    creerGroupe();

    // Défaut : feu + eau + terre (doc 02 §2, parité jeu de base).
    $this->postJson('/api/groupes/table-1/joueurs', ['nom' => 'Mage A', 'classe' => 'magicien'])->assertOk();
    $defaut = Personnage::where('nom', 'Mage A')->firstOrFail();

    expect($defaut->sorts()->count())->toBe(9)
        ->and($defaut->sorts()->pluck('element')->unique()->sort()->values()->all())->toBe(['eau', 'feu', 'terre'])
        ->and($defaut->sorts()->wherePivot('disponible', true)->count())->toBe(9);

    // Choix explicite de 3 éléments.
    $this->postJson('/api/groupes/table-1/joueurs', [
        'nom' => 'Mage B', 'classe' => 'magicien', 'elements' => ['terre', 'air', 'feu'],
    ])->assertOk();
    $choisi = Personnage::where('nom', 'Mage B')->firstOrFail();

    expect($choisi->sorts()->pluck('element')->unique()->sort()->values()->all())->toBe(['air', 'feu', 'terre']);

    // Validation : exactement 3 éléments DISTINCTS du catalogue.
    $this->postJson('/api/groupes/table-1/joueurs', ['nom' => 'X', 'classe' => 'magicien', 'elements' => ['feu', 'feu', 'eau']])
        ->assertStatus(422);
    $this->postJson('/api/groupes/table-1/joueurs', ['nom' => 'X', 'classe' => 'magicien', 'elements' => ['feu', 'eau', 'lave']])
        ->assertStatus(422);
    $this->postJson('/api/groupes/table-1/joueurs', ['nom' => 'X', 'classe' => 'magicien', 'elements' => ['feu', 'eau']])
        ->assertStatus(422); // 2 = ancien quota, désormais refusé

    // Un barbare n'a aucun sort (parchemins seulement).
    $this->postJson('/api/groupes/table-1/joueurs', ['nom' => 'Brute', 'classe' => 'barbare'])->assertOk();
    expect(Personnage::where('nom', 'Brute')->firstOrFail()->sorts()->count())->toBe(0);

    // GET /api/moi expose le répertoire (contrat).
    $moi = $this->getJson('/api/moi')->assertOk()->json();
    $persos = collect($moi['joueur']['personnages']);
    $sorts = collect($persos->firstWhere('nom', 'Mage A')['sorts']);
    expect($sorts)->toHaveCount(9)
        ->and($sorts->first())->toHaveKeys(['sort_id', 'nom', 'element', 'type', 'disponible']);
});

it("attache à l'elfe les 3 sorts de son unique élément à la création (parité HeroQuest, défaut eau, validation stricte)", function () {
    connecterJoueur('alice');
    creerGroupe();

    // Défaut : eau (1 élément, doc 02 §2).
    $this->postJson('/api/groupes/table-1/joueurs', ['nom' => 'Elfe A', 'classe' => 'elfe'])->assertOk();
    $defaut = Personnage::where('nom', 'Elfe A')->firstOrFail();

    expect($defaut->sorts()->count())->toBe(3)
        ->and($defaut->sorts()->pluck('element')->unique()->all())->toBe(['eau']);

    // Choix explicite d'un élément.
    $this->postJson('/api/groupes/table-1/joueurs', [
        'nom' => 'Elfe B', 'classe' => 'elfe', 'elements' => ['air'],
    ])->assertOk();
    expect(Personnage::where('nom', 'Elfe B')->firstOrFail()->sorts()->pluck('element')->unique()->all())->toBe(['air']);

    // Validation : exactement 1 élément (0 ou 2 refusés).
    $this->postJson('/api/groupes/table-1/joueurs', ['nom' => 'X', 'classe' => 'elfe', 'elements' => ['feu', 'eau']])
        ->assertStatus(422);
    $this->postJson('/api/groupes/table-1/joueurs', ['nom' => 'X', 'classe' => 'elfe', 'elements' => ['lave']])
        ->assertStatus(422);
});

it("attache les 3 sorts de l'élément choisi à l'acquisition de Première magie (défaut eau, 422 si déjà connu)", function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $elfe = creerHeros($alice, $groupe, 'Elwen', 1, ['classe' => 'elfe', 'niveau' => 2]); // 1 point

    $premiere = Competence::where('classe', 'elfe')->where('nom', 'Première magie')->firstOrFail();

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $elfe->id, 'competence_id' => $premiere->id, 'element' => 'air',
    ])->assertCreated()->assertJsonPath('competence.element', 'air');

    expect($elfe->sorts()->count())->toBe(3)
        ->and($elfe->sorts()->pluck('element')->unique()->all())->toBe(['air']);

    // Second élément : élément DÉJÀ CONNU → 422, rien n'est acquis.
    $elfe->update(['niveau' => 3]); // un nouveau point
    $second = Competence::where('classe', 'elfe')->where('nom', 'Second élément')->firstOrFail();

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $elfe->id, 'competence_id' => $second->id, 'element' => 'air',
    ])->assertStatus(422);
    expect($elfe->competences()->count())->toBe(1)
        ->and($elfe->sorts()->count())->toBe(3);

    // Sans `element` : défaut eau (contrat).
    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $elfe->id, 'competence_id' => $second->id,
    ])->assertCreated()->assertJsonPath('competence.element', 'eau');

    expect($elfe->sorts()->count())->toBe(6)
        ->and($elfe->sorts()->pluck('element')->unique()->sort()->values()->all())->toBe(['air', 'eau']);
});

it('propose au menu une option par sort disponible, avec les cibles légales (monstres ET héros — tir ami S3)', function () {
    [$alice, $groupe, $mage, $quete] = demarrerQueteSorts();

    // Un sort offensif ne vise que ce qui est DANS LA LIGNE DE VUE : on isole un
    // monstre et on le place au contact du mage (case adjacente ⇒ vue dégagée)
    // pour un scénario déterministe.
    $proie = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $etatMage = $quete->etatsPersonnages()->where('personnage_id', $mage->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etatMage->position_x, (int) $etatMage->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    $options = optionsMenuSorts($groupe, $alice, $mage);
    $ids = $options->pluck('id');

    foreach ($mage->sorts()->get() as $sort) {
        expect($ids)->toContain("sort_{$sort->id}");
    }

    // Sort de dégâts : monstres actifs ET héros dans les cibles légales (tir ami
    // S3) — ici la proie visible et le mage lui-même (une figure voit sa case).
    $bouleDeFeu = $options->firstWhere('id', 'sort_'.sortIdParNom('Boule de Feu'));
    $cibles = collect($bouleDeFeu['parametres']['cibles']);
    expect($bouleDeFeu['type'])->toBe('sort')
        ->and($bouleDeFeu['parametres']['sort_id'])->toBe(sortIdParNom('Boule de Feu'))
        ->and($cibles->where('type', 'monstre')->pluck('id'))->toContain($proie->id)
        ->and($cibles->where('type', 'heros')->pluck('id'))->toContain($mage->id);

    // Utilitaire ciblé : héros seulement.
    $soin = $options->firstWhere('id', 'sort_'.sortIdParNom('Eau de Guérison'));
    expect(collect($soin['parametres']['cibles'])->pluck('type')->unique()->all())->toBe(['heros']);

    // Ni parchemin (sac vide), ni concentration (nœud absent, rien d'épuisé).
    expect($ids->contains(fn ($id) => str_starts_with($id, 'parchemin_')))->toBeFalse()
        ->and($ids)->not->toContain('se_concentrer');
});

it('résout Boule de Feu à distance : dés du catalogue contre la défense, monstre vaincu, sort épuisé', function () {
    [$alice, $groupe, $mage, $quete] = demarrerQueteSorts();

    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);

    // Ligne de vue nécessaire : place la proie au contact du mage (adjacent).
    $etatMage = $quete->etatsPersonnages()->where('personnage_id', $mage->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etatMage->position_x, (int) $etatMage->position_y);
    $proie->update(['pv_body' => 1, 'position_x' => $contact['x'], 'position_y' => $contact['y']]);

    $sortId = sortIdParNom('Boule de Feu');
    optionsMenuSorts($groupe, $alice, $mage);

    // 2 dés de dégâts (effet JSON du catalogue) : 2 crânes ; défense du
    // monstre en boucliers blancs (seuls les NOIRS comptent pour lui) → tué.
    desFiges([1, 1, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "sort_{$sortId}",
        'parametres' => ['cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.type', 'sort')
        ->assertJsonPath('resultat.sort.nom', 'Boule de Feu')
        ->assertJsonPath('resultat.des_degats', 2)
        ->assertJsonPath('resultat.cible.type', 'monstre')
        ->assertJsonPath('resultat.cible_vaincue', true)
        ->assertJsonPath('resultat.quete.etat', 'terminee'); // dernier monstre → victoire

    expect($proie->fresh()->etat)->toBe('vaincu')
        ->and((bool) $mage->sorts()->whereKey($sortId)->first()->pivot->disponible)->toBeFalse()
        ->and($groupe->evenements()->where('type', 'combat')->exists())->toBeTrue();
});

it('permet le tir ami (S3) : un héros ciblé par un sort de dégâts se défend et encaisse', function () {
    [$alice, $groupe, $mage, , , $brunhilde] = demarrerQueteSorts(avecSecond: true);

    optionsMenuSorts($groupe, $alice, $mage);

    // Trait de Feu : 1 dé (crâne) ; défense du héros 2 dés en boucliers
    // NOIRS — un héros ne compte que les blancs → 1 dégât.
    desFiges([1, 6, 6]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'sort_'.sortIdParNom('Trait de Feu'),
        'parametres' => ['cible_id' => $brunhilde->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.tir_ami', true)
        ->assertJsonPath('resultat.cible.personnage_id', $brunhilde->id)
        ->assertJsonPath('resultat.degats', 1)
        ->assertJsonPath('resultat.pv_body_apres', 7);

    expect($brunhilde->fresh()->pv_body)->toBe(7);
});

it("endort un monstre (Sommeil raté au jet de Mind) : il ne joue pas, et l'attaquer le réveille", function () {
    [$alice, $groupe, $mage, $quete] = demarrerQueteSorts();

    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_mind' => 2]);

    // Le mage a déjà utilisé son créneau de déplacement ; il lance Sommeil
    // (action) — le tour NE se termine plus tout seul : il TERMINE ensuite.
    $etat->update(['a_deplace' => true]);
    optionsMenuSorts($groupe, $alice, $mage);

    // Résistance : 2 dés de Mind (PV de Mind du monstre) sans crâne → subit.
    // Réserve de boucliers : la phase des monstres ne sort aucun crâne.
    desFiges(array_fill(0, 30, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'sort_'.sortIdParNom('Sommeil'),
        'parametres' => ['cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.effet_applique', true)
        ->assertJsonPath('resultat.condition', 'Endormi')
        ->assertJsonPath('resultat.mind_cible', 2);

    $moteur = app(MoteurSorts::class);
    expect($moteur->monstreA($proie->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeTrue();

    // Le mage TERMINE son tour → phase des monstres : l'endormi NE JOUE PAS.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $mage->id);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.tour_monstres.actions.0.action', 'endormi');

    expect($moteur->monstreA($proie->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeTrue()
        ->and([(int) $proie->fresh()->position_x, (int) $proie->fresh()->position_y])
        ->toBe([$contact['x'], $contact['y']]); // pas bougé

    // Nouveau tour : une attaque (même à 0 dégât) le RÉVEILLE.
    desFiges(array_fill(0, 30, 4)); // aucun crâne nulle part
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $mage->id);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$proie->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.degats', 0);

    expect($moteur->monstreA($proie->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeFalse()
        ->and($mage->fresh()->pv_body)->toBe(8); // le monstre réveillé n'a sorti aucun crâne
});

it('soigne +4 PV Body plafonnés au maximum (Eau de Guérison)', function () {
    [$alice, $groupe, $mage, , , $brunhilde] = demarrerQueteSorts(avecSecond: true);

    $brunhilde->update(['pv_body' => 6]); // max 8 → le soin de 4 est plafonné à +2

    optionsMenuSorts($groupe, $alice, $mage);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'sort_'.sortIdParNom('Eau de Guérison'),
        'parametres' => ['cible_id' => $brunhilde->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.soin', 2)
        ->assertJsonPath('resultat.pv_body_apres', 8);

    expect($brunhilde->fresh()->pv_body)->toBe(8);
});

it("Courage donne +2 dés à la PROCHAINE attaque du héros ciblé, puis la condition est consommée", function () {
    [$alice, $groupe, $mage, $quete, $bob, $brunhilde] = demarrerQueteSorts(avecSecond: true);

    // Un seul monstre, affaibli, au contact de Brunhilde (3 dés d'attaque).
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);

    $etatB = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $brunhilde->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etatB->position_x, (int) $etatB->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1]);

    // Le mage a déjà utilisé son déplacement ; il lance Courage (action) sur
    // Brunhilde puis TERMINE son tour → c'est ensuite au tour de Brunhilde.
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->update(['a_deplace' => true]);
    optionsMenuSorts($groupe, $alice, $mage);

    // Le mage lance Courage sur Brunhilde (aucun dé).
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'sort_'.sortIdParNom('Courage'),
        'parametres' => ['cible_id' => $brunhilde->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.condition', 'Renforcé')
        ->assertJsonPath('resultat.source', 'sort:Courage');

    expect($brunhilde->conditions()->count())->toBe(1);

    // Le mage TERMINE son tour → l'initiative passe à Brunhilde.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $mage->id);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Brunhilde attaque : 3 + 2 = 5 dés lancés, le buff est consommé.
    $this->actingAs($bob, 'joueur');
    desFiges([1, 4, 4, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$proie->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.bonus_des_attaque', 2)
        ->assertJsonPath('resultat.cible_vaincue', true);

    expect(count($reponse->json('resultat.faces_attaque')))->toBe(5)
        ->and($brunhilde->conditions()->count())->toBe(0); // consommé à l'attaque
});

it('retire un sort épuisé du menu, et le forcer hors menu est un 422 (le moteur fait autorité)', function () {
    [$alice, $groupe, $mage, $quete] = demarrerQueteSorts();

    $sortId = sortIdParNom('Boule de Feu');
    DB::table('personnage_sorts')
        ->where('personnage_id', $mage->id)->where('sort_id', $sortId)
        ->update(['disponible' => false]);

    $ids = optionsMenuSorts($groupe, $alice, $mage)->pluck('id');
    expect($ids)->not->toContain("sort_{$sortId}")
        ->and($ids)->toContain('sort_'.sortIdParNom('Trait de Feu')); // les autres restent

    // Menu truqué en cache : l'option épuisée forcée → 422, rien ne bouge.
    $proie = $quete->instancesMonstres()->where('etat', 'actif')->orderBy('id')->firstOrFail();
    Cache::put(GenererMenu::cleMenu($groupe->id, (int) $alice->id), [
        'personnage_id' => $mage->id,
        'menu' => ['options' => [[
            'id' => "sort_{$sortId}", 'libelle' => 'Lancer Boule de Feu', 'type' => 'sort',
            'parametres' => ['sort_id' => $sortId, 'cibles' => [['type' => 'monstre', 'id' => $proie->id, 'nom' => 'X']]],
        ]]],
    ], now()->addMinutes(10));

    $pvAvant = (int) $proie->pv_body;

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "sort_{$sortId}",
        'parametres' => ['cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertStatus(422);

    expect((int) $proie->fresh()->pv_body)->toBe($pvAvant);
});

it('réinitialise sorts, buffs et Concentration au démarrage de la quête suivante (S5)', function () {
    [$alice, $groupe, $mage, $quete] = demarrerQueteSorts();

    // Simule une quête éprouvante : tout épuisé, un buff porté, Concentration consommée.
    DB::table('personnage_sorts')->where('personnage_id', $mage->id)->update(['disponible' => false]);
    $mage->conditions()->attach(
        Condition::where('nom', 'Renforcé')->value('id'),
        ['duree' => 0, 'source' => 'sort:Peau de Pierre'],
    );
    Cache::forever(MoteurSorts::cleConcentration($groupe->id, $mage->id), true);

    // Victoire éclair : plus de monstre actif, la quête se clôt sur l'action.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.quete.etat', 'terminee');

    expect($mage->sorts()->wherePivot('disponible', true)->count())->toBe(0);

    // Quête suivante : tout redevient disponible, buffs purgés, S6 réarmée.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    expect($mage->sorts()->wherePivot('disponible', true)->count())->toBe(6)
        ->and($mage->conditions()->count())->toBe(0)
        ->and((bool) Cache::get(MoteurSorts::cleConcentration($groupe->id, $mage->id), false))->toBeFalse();
});

it('consomme le parchemin du non-lanceur même quand le jet de Mind échoue : gaspillé, sans effet (S1)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barbare = creerHeros($alice, $groupe, 'Albrecht', 1); // attribut_mind 2, non-lanceur

    $ligne = Inventaire::create([
        'personnage_id' => $barbare->id,
        'objet_id' => Objet::where('nom', 'Parchemin : Boule de Feu')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);
    $proie = $quete->instancesMonstres()->where('etat', 'actif')->orderBy('id')->firstOrFail();
    $pvAvant = (int) $proie->pv_body;

    $options = optionsMenuSorts($groupe, $alice, $barbare);
    $option = $options->firstWhere('id', "parchemin_{$ligne->id}");
    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('parchemin')
        ->and($option['libelle'])->toContain('Utiliser un parchemin')
        ->and($option['parametres']['inventaire_id'])->toBe($ligne->id)
        ->and($option['parametres']['sort_id'])->toBe(sortIdParNom('Boule de Feu'));

    // Jet de Mind 2 dés sans crâne (difficulté 3, Boule de Feu) → échec ;
    // réserve de boucliers pour la phase des monstres qui suit.
    desFiges(array_fill(0, 40, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "parchemin_{$ligne->id}",
        'parametres' => ['cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.type', 'parchemin')
        ->assertJsonPath('resultat.lanceur_de_sorts', false)
        ->assertJsonPath('resultat.jet.difficulte', 3)
        ->assertJsonPath('resultat.jet.issue', 'echec')
        ->assertJsonPath('resultat.consomme', true)
        ->assertJsonPath('resultat.gaspille', true)
        ->assertJsonMissingPath('resultat.degats'); // aucun effet résolu

    expect(Inventaire::find($ligne->id))->toBeNull() // consommé dans TOUS les cas
        ->and((int) $proie->fresh()->pv_body)->toBe($pvAvant);
});

it('Se concentrer (S6) sacrifie le tour, récupère UN sort épuisé au choix, une seule fois par quête', function () {
    [$alice, $groupe, $mage, $quete] = demarrerQueteSorts(avecSecond: true);

    $mage->competences()->attach(
        Competence::where('classe', 'magicien')->where('nom', MoteurSorts::NOEUD_CONCENTRATION)->value('id'),
    );

    $sortId = sortIdParNom('Boule de Feu');
    DB::table('personnage_sorts')
        ->where('personnage_id', $mage->id)->where('sort_id', $sortId)
        ->update(['disponible' => false]);

    $option = optionsMenuSorts($groupe, $alice, $mage)->firstWhere('id', 'se_concentrer');
    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('concentration')
        ->and($option['parametres']['sorts_epuises'])->toBe([['sort_id' => $sortId, 'nom' => 'Boule de Feu']]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_concentrer',
        'parametres' => ['sort_id' => $sortId],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.type', 'concentration')
        ->assertJsonPath('resultat.sort_recupere.nom', 'Boule de Feu')
        ->assertJsonPath('resultat.tour_sacrifie', true);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail();
    expect((bool) $mage->sorts()->whereKey($sortId)->first()->pivot->disponible)->toBeTrue()
        ->and($etat->a_joue)->toBeTrue(); // le tour est sacrifié

    // Une seule fois par quête : même avec un sort épuisé, plus d'option.
    DB::table('personnage_sorts')
        ->where('personnage_id', $mage->id)->where('sort_id', $sortId)
        ->update(['disponible' => false]);

    expect(optionsMenuSorts($groupe, $alice, $mage)->pluck('id'))->not->toContain('se_concentrer');
});
