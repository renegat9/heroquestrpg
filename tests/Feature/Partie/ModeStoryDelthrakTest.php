<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Sort;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * MODE STORY — *Jungles of Delthrak*, livret F9907 p. 5 (décision de René du
 * 2026-10-09 : on garde « tombé, jamais mort », et on ajoute ce que le mode Story
 * écrit et que nous ne faisions pas).
 *
 * Deux règles, chacune testée EN JEU — par les vraies routes, pas par un appel de
 * service qui contournerait la boucle de tour :
 *
 *  1. « Incapacitated heroes gain 1 Body Point if Zargon has no monsters active and
 *     at least one other hero is not incapacitated. » — relèvement à l'ouverture du
 *     round (`ResolveurTour::relevementsStory()`).
 *  2. « If a hero spellcaster dies, they can immediately heal themselves by casting
 *     an available healing spell, regardless of whether they had previously used an
 *     action on their turn. » — déjà portée par le SOIN D'URGENCE de
 *     `MoteurReactions` (2026-08-13) : ce fichier la PINE pour les sorts, que les
 *     tests de potions ne couvraient pas.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/**
 * Une quête à DEUX héros : Albrecht (alice, celui que demarrerQueteAvecMonstre crée)
 * et Roland (bob), posé à côté d'Albrecht. Le monstre du contexte est celui qu'on
 * veut tester (vaincu, dormant ou actif selon le cas).
 *
 * @return array<string, mixed>
 */
function storyDeuxHeros(string $nomMonstre = 'Gobelin'): array
{
    $ctx = demarrerQueteAvecMonstre($nomMonstre);

    $bob = App\Auth\JoueurAuthentifiable::create([
        'pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret',
    ]);
    $roland = creerHeros($bob, $ctx['groupe'], 'Roland', 2);

    $albrecht = $ctx['etatHeros'];
    $contact = caseAdjacenteLibre($ctx['quete'], (int) $albrecht->position_x, (int) $albrecht->position_y);
    $etatRoland = EtatPersonnageQuete::create([
        'personnage_id' => $roland->id, 'quete_id' => $ctx['quete']->id,
        'position_x' => $contact['x'], 'position_y' => $contact['y'],
    ]);

    return $ctx + ['bob' => $bob, 'roland' => $roland, 'etatRoland' => $etatRoland];
}

/** Fait tomber Albrecht, comme le ferait un coup : 0 PV de Body, état « tombé ». */
function storyFaireTomber(App\Models\Personnage $heros, EtatPersonnageQuete $etat): void
{
    $heros->update(['pv_body' => 0]);
    $etat->update(['tombe' => true]);
}

/**
 * Roland termine son tour, par la vraie route de choix. Son menu n'existe pas encore :
 * il est entré dans la quête APRÈS son démarrage, et `GenererMenu` ne le produit qu'au
 * moment où c'est son tour. Albrecht étant à terre, c'est bien Roland qui a la main.
 */
function storyTerminerTourRoland(array $ctx): Illuminate\Testing\TestResponse
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['bob']->id, (int) $ctx['roland']->id);

    return test()->actingAs($ctx['bob'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre']);
}

/* ==================================================================
 * 1. RELÈVEMENT — « gain 1 Body Point if Zargon has no monsters active and at
 *    least one other hero is not incapacitated »
 * ================================================================== */

it('relève le héros à terre à l\'ouverture du round quand aucun monstre n\'est actif et qu\'un autre tient debout', function () {
    $ctx = storyDeuxHeros('Gobelin');
    $ctx['instance']->update(['etat' => 'vaincu']);
    storyFaireTomber($ctx['heros'], $ctx['etatHeros']);

    // Roland joue son tour : c'est le dernier acteur, le round se referme, la
    // phase de Zargon est vide, le round s'ouvre.
    $reponse = storyTerminerTourRoland($ctx)->assertSuccessful();

    // Il gagne 1 point de Corps et se relève — c'est l'annonce du round qui le dit.
    $ctx['etatHeros']->refresh();
    expect((int) $ctx['heros']->fresh()->pv_body)->toBe(1)
        ->and((bool) $ctx['etatHeros']->tombe)->toBeFalse();

    $relevement = collect($reponse->json('resultat.captifs_repris'))->firstWhere('type', 'regain_corps');
    expect($relevement)->not->toBeNull('le relèvement doit être ANNONCÉ dans la réponse')
        ->and($relevement['nom'])->toBe('Albrecht')
        ->and($relevement['pv_body'])->toBe(1);

    // Il relève le round : il joue ce tour-ci (créneaux remis à zéro pour lui aussi).
    expect((bool) $ctx['etatHeros']->fresh()->a_joue)->toBeFalse();
});

it('annonce le relèvement au fil de combat, pas seulement dans le JSON', function () {
    $ctx = storyDeuxHeros('Gobelin');
    $ctx['instance']->update(['etat' => 'vaincu']);
    storyFaireTomber($ctx['heros'], $ctx['etatHeros']);

    $reponse = storyTerminerTourRoland($ctx)->assertSuccessful();

    // Le fil est formé par le MÊME formateur que la diffusion temps réel : une
    // annonce qui n'y passe pas est une annonce que la table ne voit jamais.
    $lignes = app(JournalCombat::class)->depuisResultat($reponse->json('resultat'), 'Roland');
    $textes = collect($lignes)->pluck('texte')->all();

    expect($textes)->toContain('Albrecht regagne un point de Corps et se relève');
});

it('ne relève PAS un héros tant qu\'un monstre ACTIF et RÉVÉLÉ est en jeu', function () {
    $ctx = storyDeuxHeros('Gobelin');
    storyFaireTomber($ctx['heros'], $ctx['etatHeros']);

    // Le monstre est au contact d'Albrecht, révélé, actif. Figé : aucun dé ne blesse.
    desFiges(array_fill(0, 200, 4));

    $reponse = storyTerminerTourRoland($ctx)->assertSuccessful();

    expect((bool) $ctx['etatHeros']->fresh()->tombe)->toBeTrue()
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(0)
        ->and(collect($reponse->json('resultat.captifs_repris') ?? [])->firstWhere('type', 'regain_corps'))->toBeNull();
});

it('compte un monstre DORMANT derrière une porte close comme absent — la définition de la fin de combat', function () {
    $ctx = storyDeuxHeros('Gobelin');
    // Actif (pas vaincu) mais jamais révélé : `Zargon has no monsters active` vaut
    // ici ce que vaut la fin de combat (René, 2026-08-06), pas « plus rien dans le donjon ».
    $ctx['instance']->update(['revele' => false]);
    storyFaireTomber($ctx['heros'], $ctx['etatHeros']);

    storyTerminerTourRoland($ctx)->assertSuccessful();

    expect((bool) $ctx['etatHeros']->fresh()->tombe)->toBeFalse()
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(1);
});

it('ne relève personne quand TOUT le groupe est à terre : c\'est le TPK, le livret le dit', function () {
    $ctx = storyDeuxHeros('Gobelin');
    $ctx['instance']->update(['etat' => 'vaincu']);
    storyFaireTomber($ctx['heros'], $ctx['etatHeros']);
    storyFaireTomber($ctx['roland'], $ctx['etatRoland']);

    // « If all heroes are incapacitated, then they've died in their attempt to complete
    // the quest » : aucun héros debout, donc aucune condition du relèvement. Appelé
    // directement, car un groupe entièrement à terre ne joue plus aucun tour pour
    // ouvrir le round par la route.
    $relevements = (new ReflectionMethod(ResolveurTour::class, 'relevementsStory'));
    $relevements->setAccessible(true);

    expect($relevements->invoke(app(ResolveurTour::class), $ctx['groupe']->fresh(), $ctx['quete']->fresh()))->toBe([])
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(0)
        ->and((bool) $ctx['etatHeros']->fresh()->tombe)->toBeTrue();
});

/* ==================================================================
 * 2. LE LANCEUR QUI TOMBE SE SOIGNE D'UN SORT — réaction hors tour, « immediately »,
 *    « regardless of whether they had previously used an action on their turn »
 * ================================================================== */

/** Donne au héros un sort de soin disponible — *Soin du Corps* (cible heros, 4 PV). */
function storyArmerSoin(App\Models\Personnage $heros): Sort
{
    $sort = Sort::where('nom', 'Soin du Corps')->firstOrFail();
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => true]]);

    return $sort;
}

it('propose au lanceur qui TOMBE son sort de soin, et le remet debout', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $sort = storyArmerSoin($heros);
    $heros->update(['pv_body' => 1]);

    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);
    $ctx['etatHeros']->fresh()->update(['tombe' => true]);

    $attente = $ctx['etatHeros']->fresh()->reaction_en_attente;

    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe('soin_urgence')
        ->and(collect($attente['soins'])->pluck('cle')->all())->toBe(["sort:{$sort->id}"]);

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => true, 'soin' => "sort:{$sort->id}",
    ])->assertOk()->assertJsonPath('reaction.debout', true);

    // Debout, et le sort est DÉPENSÉ : c'est ce qui empêche de le relancer à chaque coup.
    expect((int) $heros->fresh()->pv_body)->toBeGreaterThan(0)
        ->and((bool) $ctx['etatHeros']->fresh()->tombe)->toBeFalse()
        ->and($heros->fresh()->sorts()->wherePivot('disponible', true)->whereKey($sort->id)->exists())->toBeFalse();
});

it('propose le sort même quand le lanceur a DÉJÀ joué son tour', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $sort = storyArmerSoin($heros);
    $heros->update(['pv_body' => 1]);

    // Il a utilisé son action : « regardless of whether they had previously used an
    // action on their turn ». Le soin d'urgence ne s'y intéresse pas.
    $ctx['etatHeros']->update(['a_joue' => true, 'a_agi' => true]);

    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);

    expect($ctx['etatHeros']->fresh()->reaction_en_attente['soins'][0]['cle'] ?? null)->toBe("sort:{$sort->id}");
});

it('laisse le lanceur à terre et GARDE le sort s\'il refuse', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $sort = storyArmerSoin($heros);
    $heros->update(['pv_body' => 1]);

    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);
    $ctx['etatHeros']->fresh()->update(['tombe' => true]);

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => false,
    ])->assertOk()->assertJsonPath('reaction.active', false);

    expect((int) $heros->fresh()->pv_body)->toBe(0)
        ->and($heros->fresh()->sorts()->wherePivot('disponible', true)->whereKey($sort->id)->exists())->toBeTrue();
});

it('n\'offre PAS un sort déjà dépensé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $sort = storyArmerSoin($heros);
    $heros->sorts()->updateExistingPivot($sort->id, ['disponible' => false]);
    $heros->update(['pv_body' => 1]);

    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);

    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();
});
