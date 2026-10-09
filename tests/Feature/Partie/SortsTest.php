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
use App\Partie\Salles;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Collection;
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
function optionsMenuSorts(Groupe $groupe, JoueurAuthentifiable $joueur, Personnage $hero): Collection
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

/**
 * Entrées de la liste d'une option à sous-choix (`lancer_sort`,
 * `lire_parchemin`, `utiliser_objet`, `se_concentrer`).
 *
 * ⚠ Depuis le 2026-09-01, le menu ne porte plus UNE OPTION PAR SORT : il porte
 * une option qui porte la LISTE des sorts. Les tests interrogent donc la liste,
 * exactement comme le fait la feuille de la manette.
 *
 * @return Illuminate\Support\Collection<int, array<string, mixed>>
 */
function entreesDe($options, string $optionId, string $liste)
{
    $option = collect($options)->firstWhere('id', $optionId);

    return collect($option['parametres'][$liste] ?? []);
}

/** Une entrée de sort par son nom de catalogue. */
function entreeSort($options, string $nom): ?array
{
    return entreesDe($options, 'lancer_sort', 'sorts')
        ->firstWhere('cle', 'sort:'.sortIdParNom($nom));
}


it('propose au menu une option par sort disponible, avec les cibles légales (un sort à cible unique ne vise que ce que dit sa carte)', function () {
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

    // ⚠ UNE option pour les neuf sorts, pas neuf options. C'est la raison
    // d'être du lot : le doc 13 §3.1 fixe « 2 à 5 options claires », et le menu
    // d'un magicien en portait quatorze, dont neuf sorts.
    expect($ids)->toContain('lancer_sort');

    $entrees = entreesDe($options, 'lancer_sort', 'sorts');

    foreach ($mage->sorts()->get() as $sort) {
        expect($entrees->pluck('cle'))->toContain("sort:{$sort->id}");
    }

    // Sort de dégâts à CIBLE UNIQUE : la liste suit la carte (« any one monster »,
    // décision de René, 2026-10-09). La proie visible est là ; le mage, qui voit
    // pourtant sa propre case, n'y figure PAS — il ne peut pas se brûler lui-même.
    $bouleDeFeu = entreeSort($options, 'Boule de Feu');
    $cibles = collect($bouleDeFeu['cibles']);
    expect($bouleDeFeu['sort_id'])->toBe(sortIdParNom('Boule de Feu'))
        ->and($bouleDeFeu['disponible'])->toBeTrue()
        ->and($cibles->pluck('type')->unique()->all())->toBe(['monstre'])
        ->and($cibles->pluck('id')->all())->toBe([$proie->id]);

    // ⚠ Les cibles restent PAR ENTRÉE : un utilitaire ciblé ne vise que des
    // héros. Une liste unique au niveau de l'option serait fausse ici.
    $soin = entreeSort($options, 'Eau de Guérison');
    expect(collect($soin['cibles'])->pluck('type')->unique()->all())->toBe(['heros']);

    // Ni parchemin (sac vide), ni concentration (nœud absent, rien d'épuisé).
    expect($ids)->not->toContain('lire_parchemin')
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

    // ⚠ Modèle de la CARTE depuis le 2026-09-02 (doc 16 §3bis) : 2 dégâts
    // FIXES, puis le monstre lance 2 d6 BRUTS et chaque 5-6 en annule 1. Des dés
    // à 1 : aucune réduction → 2 dégâts → la proie à 1 PV tombe. Plus aucun dé
    // d'attaque ni de défense sur ce chemin.
    desFiges([1, 1]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sortId}", 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.type', 'sort')
        ->assertJsonPath('resultat.sort.nom', 'Boule de Feu')
        ->assertJsonPath('resultat.degats_fixes', 2)
        ->assertJsonPath('resultat.degats_annules', 0)
        ->assertJsonPath('resultat.degats', 2)
        ->assertJsonPath('resultat.cible.type', 'monstre')
        ->assertJsonPath('resultat.cible_vaincue', true)
        ->assertJsonPath('resultat.donjon_nettoye', true); // dernier monstre → victoire

    // La quête ne se clôt plus d'elle-même : le groupe vote la sortie.
    acheverLaQuete($groupe);

    expect($proie->fresh()->etat)->toBe('vaincu')
        ->and((bool) $mage->sorts()->whereKey($sortId)->first()->pivot->disponible)->toBeFalse()
        ->and($groupe->evenements()->where('type', 'combat')->exists())->toBeTrue();
});

it('un sort à CIBLE UNIQUE ne touche jamais un héros, même au contact : le menu et le résolveur refusent (décision de René, 2026-10-09)', function () {
    // Carte : « any one MONSTER » (Fire of Wrath, Sleep). L'ancien tir ami d'un
    // sort à cible unique — un héros frappé à côté du lanceur — n'existe plus.
    [$alice, $groupe, $mage, $quete, , $brunhilde] = demarrerQueteSorts(avecSecond: true);

    // Une proie visible ET un allié au contact : le refus ne doit tenir qu'au
    // TYPE de cible, pas à l'absence d'un monstre ou à une ligne de vue coupée.
    $proie = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $etatMage = $quete->etatsPersonnages()->where('personnage_id', $mage->id)->firstOrFail();
    $contactProie = caseAdjacenteLibre($quete, (int) $etatMage->position_x, (int) $etatMage->position_y);
    $proie->update(['position_x' => $contactProie['x'], 'position_y' => $contactProie['y']]);
    $contactAllie = caseAdjacenteLibre($quete, (int) $etatMage->position_x, (int) $etatMage->position_y);
    $quete->etatsPersonnages()->where('personnage_id', $brunhilde->id)
        ->update(['position_x' => $contactAllie['x'], 'position_y' => $contactAllie['y']]);

    $options = optionsMenuSorts($groupe, $alice, $mage);

    // Le menu ne liste que la proie, pour un sort de dégâts comme pour un mental.
    expect(collect(entreeSort($options, 'Trait de Feu')['cibles'])->pluck('id')->all())->toBe([$proie->id])
        ->and(collect(entreeSort($options, 'Sommeil')['cibles'])->pluck('id')->all())->toBe([$proie->id]);

    // Forcé hors menu, le résolveur refuse le héros — il n'a donc rien à encaisser.
    $pvAvant = (int) $brunhilde->fresh()->pv_body;
    desFiges([1, 6, 6]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Trait de Feu'), 'cible_id' => $brunhilde->id, 'cible_type' => 'heros'],
    ])->assertStatus(422)->assertJsonValidationErrors('parametres');

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Sommeil'), 'cible_id' => $brunhilde->id, 'cible_type' => 'heros'],
    ])->assertStatus(422)->assertJsonValidationErrors('parametres');

    expect((int) $brunhilde->fresh()->pv_body)->toBe($pvAvant);
});

it('un sort de ZONE touche toujours un héros de sa salle, lanceur excepté : c\'est le tir ami qui subsiste (décision de René, 2026-10-09)', function () {
    // Flamme hypnotique : « every figure in the room (EXCEPT for the spellcaster) »
    // jette 1 d6 contre son Mind ; au-dessus, PARALYSÉ. Aucune cible à choisir.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $elfe = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'elfe']);
    $allie = creerHeros($alice, $groupe, 'Brunhilde', 2);
    app(MoteurSorts::class)->attacherElement($elfe, 'elfique');

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Aucun monstre : le scénario ne fait jouer que les héros, et leurs dés.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);

    // L'allié dans la MÊME SALLE que le lanceur (à défaut, au contact).
    $salles = (array) ($quete->carte?->grille['salles'] ?? []);
    $etatElfe = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $elfe->id)->firstOrFail();
    $etatAllie = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $allie->id)->firstOrFail();
    $salle = Salles::indexDe($salles, (int) $etatElfe->position_x, (int) $etatElfe->position_y);

    $place = null;

    foreach ((array) $quete->carte->grille['cases'] as $y => $ligne) {
        foreach (array_keys($ligne) as $x) {
            if ($salle !== null && Salles::indexDe($salles, (int) $x, (int) $y) === $salle && caseQueteLibre($quete, (int) $x, (int) $y)) {
                $place = ['x' => (int) $x, 'y' => (int) $y];
                break 2;
            }
        }
    }

    $place ??= caseAdjacenteLibre($quete, (int) $etatElfe->position_x, (int) $etatElfe->position_y);
    $etatAllie->update(['position_x' => $place['x'], 'position_y' => $place['y']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $elfe->id);
    $flamme = Sort::where('nom', 'Flamme hypnotique')->firstOrFail();

    // Un seul jet : celui de Brunhilde (Mind 2). Un 6 la paralyse. Le lanceur,
    // lui, ne jette RIEN — s'il jetait, le premier dé le toucherait.
    desFiges([6, 6]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$flamme->id}"],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.zone', true);

    expect($allie->fresh()->conditions()->where('nom', 'Paralysé')->exists())->toBeTrue()
        ->and($elfe->fresh()->conditions()->where('nom', 'Paralysé')->exists())->toBeFalse();
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
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Sommeil'), 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
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

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $proie->id]])
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
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Eau de Guérison'), 'cible_id' => $brunhilde->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.soin', 2)
        ->assertJsonPath('resultat.pv_body_apres', 8);

    expect($brunhilde->fresh()->pv_body)->toBe(8);
});

it('Courage donne +2 dés à la PROCHAINE attaque du héros ciblé, puis la condition est consommée', function () {
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
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Courage'), 'cible_id' => $brunhilde->id, 'cible_type' => 'heros'],
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

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $proie->id]])
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

    // ⚠ Un sort épuisé reste DANS LA LISTE, marqué `disponible: false`, pour
    // être grisé à l'écran (René, 2026-09-01) : le faire disparaître laissait
    // croire au joueur qu'il avait perdu le sort. Il n'entre évidemment pas
    // dans la liste blanche que le résolveur accepte.
    $entrees = entreesDe(optionsMenuSorts($groupe, $alice, $mage), 'lancer_sort', 'sorts');
    // Un AUTRE sort reste lançable : un soin vise toujours le lanceur lui-même
    // (Trait de Feu, lui, n'a aucune entrée sans monstre en vue).
    expect($entrees->firstWhere('cle', "sort:{$sortId}")['disponible'])->toBeFalse()
        ->and($entrees->firstWhere('cle', 'sort:'.sortIdParNom('Eau de Guérison'))['disponible'])->toBeTrue();

    // Menu truqué en cache : l'option épuisée forcée → 422, rien ne bouge.
    $proie = $quete->instancesMonstres()->where('etat', 'actif')->orderBy('id')->firstOrFail();
    Cache::put(GenererMenu::cleMenu($groupe->id, (int) $alice->id), [
        'personnage_id' => $mage->id,
        'menu' => ['options' => [[
            'id' => 'lancer_sort', 'libelle' => 'Lancer un sort', 'type' => 'sort',
            'parametres' => ['sorts' => [[
                'cle' => "sort:{$sortId}", 'sort_id' => $sortId, 'nom' => 'Boule de Feu',
                'disponible' => false, // c'est CE drapeau que le résolveur doit refuser
                'cibles' => [['type' => 'monstre', 'id' => $proie->id, 'nom' => 'X']],
            ]]],
        ]]],
    ], now()->addMinutes(10));

    $pvAvant = (int) $proie->pv_body;

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sortId}", 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
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
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)
        ->update(['capacites_utilisees' => json_encode(['Concentration'])]);

    // Victoire éclair : plus de monstre actif, la quête se clôt sur l'action.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.donjon_nettoye', true);

    // La quête ne se clôt plus d'elle-même : le groupe vote la sortie.
    acheverLaQuete($groupe);

    expect($mage->sorts()->wherePivot('disponible', true)->count())->toBe(0);

    // Quête suivante : tout redevient disponible, buffs purgés, S6 réarmée.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    // ⚠ La S6 est réarmée par la NAISSANCE du nouvel `etat_personnage_quete`,
    // plus par une purge de cache : le compteur vit dans l'état de la quête
    // (2026-09-02), donc la quête suivante part vierge sans rien à effacer.
    $etatNeuf = EtatPersonnageQuete::where('quete_id', $groupe->fresh()->quete_courante_id)
        ->where('personnage_id', $mage->id)->firstOrFail();

    expect($mage->sorts()->wherePivot('disponible', true)->count())->toBe(6)
        ->and($mage->conditions()->count())->toBe(0)
        ->and(app(MoteurSorts::class)->concentrationDisponible($mage->fresh(), $etatNeuf))->toBeFalse()
        ->and((array) $etatNeuf->capacites_utilisees)->toBe([]);
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
    // Boule de Feu n'a d'entrée que si un MONSTRE est à viser : on le place au
    // contact du barbare, ligne de vue dégagée.
    $etatBarbare = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $barbare->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etatBarbare->position_x, (int) $etatBarbare->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);
    $pvAvant = (int) $proie->pv_body;

    $options = optionsMenuSorts($groupe, $alice, $barbare);
    // ⚠ Action SÉPARÉE des sorts : un parchemin est DÉTRUIT à l'usage et
    // demande un jet de Mind à un non-lanceur — deux économies contraires ne
    // cohabitent pas dans une même liste (René, 2026-09-01).
    $option = $options->firstWhere('id', 'lire_parchemin');
    $entree = entreesDe($options, 'lire_parchemin', 'parchemins')
        ->firstWhere('cle', "parchemin:{$ligne->id}");

    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('parchemin')
        ->and($entree)->not->toBeNull()
        ->and($entree['inventaire_id'])->toBe($ligne->id)
        ->and($entree['sort_id'])->toBe(sortIdParNom('Boule de Feu'));

    // Jet de Mind 2 dés sans crâne (difficulté 3, Boule de Feu) → échec ;
    // réserve de boucliers pour la phase des monstres qui suit.
    desFiges(array_fill(0, 40, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => "parchemin:{$ligne->id}", 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
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
        Competence::where('classe', 'magicien')->where('nom', 'Concentration')->value('id'),
    );

    $sortId = sortIdParNom('Boule de Feu');
    DB::table('personnage_sorts')
        ->where('personnage_id', $mage->id)->where('sort_id', $sortId)
        ->update(['disponible' => false]);

    $options = optionsMenuSorts($groupe, $alice, $mage);
    $option = $options->firstWhere('id', 'se_concentrer');

    // ⚠ La clé est `sorts`, comme les trois autres listes : le moteur publiait
    // `sorts_epuises` alors que la feuille lisait `sorts`, si bien que la liste
    // du serveur n'était JAMAIS consommée (elle ne marchait que par son repli
    // sur `/moi`). Un seul nom, et la liste redevient la liste blanche.
    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('concentration')
        ->and(entreesDe($options, 'se_concentrer', 'sorts')->pluck('cle')->all())->toBe(["sort:{$sortId}"]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_concentrer',
        'parametres' => ['cle' => "sort:{$sortId}"],
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

it('Réserve arcanique (nœud magicien) permet de lancer un SECOND sort le même tour', function () {
    [$alice, $groupe, $mage, $quete] = demarrerQueteSorts();
    // Rang 2 de la colonne « Écoles » : le prérequis (« Écoles ») ouvrirait un
    // élément et attacherait trois sorts de plus, ce qui n'a rien à voir avec ce
    // qu'on mesure ici — le second sort dans le même tour.
    donnerTalent($mage, 'Réserve arcanique');

    // Un monstre au contact : le 3e sort visera une VRAIE cible, pour que son
    // refus ne tienne qu'au bonus épuisé (un héros n'est plus une cible, 2026-10-09).
    $proie = $quete->instancesMonstres()->where('etat', 'actif')->orderBy('id')->firstOrFail();
    $etatMage = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etatMage->position_x, (int) $etatMage->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    optionsMenuSorts($groupe, $alice, $mage);

    // 1er sort : Courage sur soi-même — consomme le créneau action normal.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Courage'), 'cible_id' => $mage->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.bonus_reserve_arcanique', null);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail();
    expect($etat->fresh()->a_agi)->toBeTrue()
        ->and($etat->fresh()->bonus_sort_utilise)->toBeFalse();

    // Le menu propose ENCORE un sort (le bonus), malgré a_agi déjà pris.
    $options = optionsMenuSorts($groupe, $alice, $mage);
    expect(entreeSort($options, 'Eau de Guérison'))->not->toBeNull()
        // Mais plus d'attaque/désamorçage/équipement (pas concernés par le bonus).
        ->and($options->contains(fn ($o) => ($o['type'] ?? null) === 'equiper'))->toBeFalse();

    // 2e sort : Eau de Guérison — via le bonus de Réserve arcanique.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Eau de Guérison'), 'cible_id' => $mage->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.bonus_reserve_arcanique', true)
        ->assertJsonPath('resultat.type', 'sort');

    expect($etat->fresh()->bonus_sort_utilise)->toBeTrue()
        ->and((bool) $mage->sorts()->whereKey(sortIdParNom('Courage'))->first()->pivot->disponible)->toBeFalse()
        ->and((bool) $mage->sorts()->whereKey(sortIdParNom('Eau de Guérison'))->first()->pivot->disponible)->toBeFalse();

    // Le bonus est consommé : un 3e sort ce tour est refusé, même sur une cible légale.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Trait de Feu'), 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertStatus(422);
});

it('sans Réserve arcanique, un second sort le même tour est refusé (comportement inchangé)', function () {
    [$alice, $groupe, $mage] = demarrerQueteSorts();
    optionsMenuSorts($groupe, $alice, $mage);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Courage'), 'cible_id' => $mage->id, 'cible_type' => 'heros'],
    ])->assertStatus(202);

    // Le menu ne propose plus AUCUN sort (créneau action déjà pris, pas de bonus).
    $options = optionsMenuSorts($groupe, $alice, $mage);
    expect($options->contains(fn ($o) => ($o['type'] ?? null) === 'sort'))->toBeFalse();

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.sortIdParNom('Eau de Guérison'), 'cible_id' => $mage->id, 'cible_type' => 'heros'],
    ])->assertStatus(422);
});
