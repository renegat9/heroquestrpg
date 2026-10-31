<?php

declare(strict_types=1);

use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Mercenaire;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\SortDread;
use App\Partie\JournalCombat;
use App\Partie\MoteurDread;
use App\Partie\VerrouMental;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * UN ROUND OÙ AUCUN HÉROS NE PEUT AGIR NE FIGE PLUS LE GROUPE (René, 2026-10-31 : « on le corrige »).
 *
 * Le trou, nommé le 2026-10-16 dans `docs/regles/sorts-dread.md` : un héros perd son tour à l'OUVERTURE du round
 * (gel de *Mind Lock*, Étourdi de *Tourmente*, loup de la malédiction). S'il est le seul debout, aucun menu ne
 * s'ouvre et rien ne déclenche la phase suivante : le groupe reste figé, sans erreur. La garde ne couvrait que le
 * gel, et elle ne lisait pas l'état : deux héros, dont un gelé, rejouaient la phase des monstres alors que le second
 * avait la main.
 *
 * Ce fichier joue les rounds par les vraies routes (`POST /choix`, `GET /menu`), et compte les rounds OUVERTS dans le
 * journal : un rejeu de la phase des monstres est un round ouvert de plus.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MobilierSeeder::class,
        MonstreSeeder::class, MercenaireSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

// ------------------------------------------------------------------
// Helpers locaux (préfixés `rsh` : les fichiers de test partagent l'espace des fonctions)
// ------------------------------------------------------------------

/**
 * Deux héros (Albrecht, puis Brunhild), côte à côte, au contact du monstre nommé — seul monstre actif de la quête.
 * `$cartes` pose la réserve de Dread du monstre (un Sorcier qui gèle).
 *
 * @param  array<string, int>|null  $cartes
 * @return array<string, mixed>
 */
function rshDeuxHeros(string $nomMonstre, ?array $cartes = null): array
{
    $alice = connecterJoueur('alice');
    $bob = connecterJoueur('bob');
    $groupe = creerGroupe();
    $h1 = creerHeros($alice, $groupe, 'Albrecht', 1);
    $h2 = creerHeros($bob, $groupe, 'Brunhild', 2);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $catalogue = Monstre::where('nom_base', $nomMonstre)->firstOrFail();
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update([
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'etat' => 'actif',
        'elite' => false,
    ]);
    $instance->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $quete);

    $etat1 = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h1->id)->firstOrFail();
    $etat2 = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h2->id)->firstOrFail();

    $contact = caseAdjacenteLibre($quete, (int) $etat1->position_x, (int) $etat1->position_y);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    $voisine = caseAdjacenteLibre($quete, (int) $etat1->position_x, (int) $etat1->position_y);
    $etat2->update(['position_x' => $voisine['x'], 'position_y' => $voisine['y']]);

    if ($cartes !== null) {
        $instance->refresh()->load('monstre');
        $instance->poserEtatDread('cartes', $cartes);
    }

    return [
        'alice' => $alice, 'bob' => $bob, 'groupe' => $groupe->fresh(), 'quete' => $quete->refresh(),
        'h1' => $h1, 'h2' => $h2, 'etat1' => $etat1->refresh(), 'etat2' => $etat2->refresh(),
        'instance' => $instance->refresh()->load('monstre'),
    ];
}

/** Gèle le héros à la main, comme un *Mind Lock* qui aurait pris `$nets` crânes nets (sans le lancer). */
function rshGeler(array $ctx, Personnage $heros, InstanceMonstre $lanceur, int $nets): void
{
    $sort = SortDread::where('nom', 'Verrou mental')->firstOrFail();
    $etat = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $heros->id)->firstOrFail();
    app(VerrouMental::class)->enregistrer($etat, $lanceur, $sort, $nets);

    $heros->conditions()->attach(Condition::where('nom', 'Verrou mental')->firstOrFail()->id, [
        'duree' => 0, 'source' => 'sort_dread:Verrou mental',
    ]);
}

/** Le nombre de rounds OUVERTS dans ce groupe : un par phase des héros, et un de plus à chaque rejeu. */
function rshRoundsOuverts(Groupe $groupe): int
{
    return Evenement::where('groupe_id', $groupe->id)->where('type', 'systeme')->get()
        ->filter(fn (Evenement $e) => ($e->payload['action'] ?? null) === 'nouveau_tour')
        ->count();
}

// ==================================================================
// 1. UN HÉROS SEUL — le trou tel qu'il était
// ==================================================================

it('TOURMENTE sur un héros SEUL : son tour est perdu, la phase des monstres se rejoue, et son menu s\'ouvre au round suivant', function () {
    // Une seule Tourmente (usages 1) : le rejeu est un tour de Zargon de plus, et un Garde-mage qui gardait son
    // budget la relancerait à chaque rejeu — ce test ne mesure que le trou, pas la répétition.
    $ctx = demarrerQueteAvecMonstre('Garde-mage');
    $ctx['instance']->monstre->update(['sorts_dread' => ['Tourmente'], 'archetype_lanceur' => null]);
    $ctx['instance']->update(['usages_dread' => 1]);
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = $ctx;

    $avant = rshRoundsOuverts($groupe);

    desFiges(array_fill(0, 300, 4));
    $reponse = test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $actions = collect($reponse->json('resultat.tour_monstres.actions'));

    expect(data_get($actions->firstWhere('sort', 'Tourmente'), 'resultats.0.effet_applique'))->toBeTrue()
        ->and($actions->where('type', 'tour_perdu'))->toHaveCount(1)
        // Deux rounds ouverts : celui du tour perdu, puis celui de la phase rejouée.
        ->and(rshRoundsOuverts($groupe) - $avant)->toBe(2)
        ->and($actions->where('type', 'phase_rejouee'))->toHaveCount(1);

    // Le round suivant lui rend son menu, et l'Étourdi a pris fin en étant consommé.
    expect($heros->fresh()->conditions()->where('nom', 'Étourdi')->exists())->toBeFalse();

    $menu = test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();

    expect($menu->json('menu'))->not->toBeNull()
        ->and($menu->json('personnage_id'))->toBe($heros->id);

    // Le fil DIT les deux choses : le tour perdu, puis que Zargon joue encore. Un effet automatique muet est injouable.
    $fil = collect(app(JournalCombat::class)->depuisResultat($reponse->json('resultat'), 'Albrecht'))
        ->pluck('texte')->implode(' ¦ ');

    expect($fil)->toContain('Albrecht est encore étourdi — il passe son tour')
        ->and($fil)->toContain('Aucun héros ne peut agir : Zargon joue encore');

    // Et le rejeu est au journal, pour un rafraîchissement ou une manette arrivée en retard.
    expect(Evenement::where('groupe_id', $groupe->id)->get()
        ->contains(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'phase_rejouee'))->toBeTrue();
});

it('DONJON VIDÉ : un héros seul étourdi ne fige pas la sortie — le round se rejoue jusqu\'à ce qu\'il ait la main', function () {
    // Aucun monstre actif : `jouerFinDeRound()` ouvre le round par sa branche « donjon nettoyé », qui doit garder
    // la même garde que la phase des monstres.
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'instance' => $gobelin] = $ctx;
    $gobelin->update(['etat' => 'vaincu']);
    $heros->conditions()->attach(Condition::where('nom', 'Étourdi')->value('id'), ['duree' => 0, 'source' => 'sort_dread:Tourmente']);

    $avant = rshRoundsOuverts($groupe);

    desFiges(array_fill(0, 300, 4));
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect(rshRoundsOuverts($groupe) - $avant)->toBe(2)
        ->and($heros->fresh()->conditions()->where('nom', 'Étourdi')->exists())->toBeFalse();

    $menu = test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();

    expect($menu->json('personnage_id'))->toBe($heros->id);
});

// ==================================================================
// 2. DEUX HÉROS — celui qui perd son tour ne bloque PAS son compagnon
// ==================================================================

it('ÉTOURDI à DEUX héros : le héros étourdi perd son tour, son compagnon joue — rien n\'est rejoué', function () {
    // Brunhild peut encore jouer : la phase des monstres ne doit PAS se rejouer. Le test lit un seul round ouvert,
    // un seul tour perdu, et le menu de Brunhild.
    $ctx = rshDeuxHeros('Gobelin');
    ['alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'h1' => $h1, 'h2' => $h2] = $ctx;

    $h1->conditions()->attach(Condition::where('nom', 'Étourdi')->value('id'), ['duree' => 0, 'source' => 'sort_dread:Tourmente']);
    $avant = rshRoundsOuverts($groupe);

    desFiges(array_fill(0, 300, 4));
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    desFiges(array_fill(0, 300, 4));
    $reponse = test()->actingAs($bob, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect(rshRoundsOuverts($groupe) - $avant)->toBe(1)
        ->and(collect($reponse->json('resultat.tour_monstres.actions'))->where('type', 'tour_perdu'))->toHaveCount(1)
        ->and(collect($reponse->json('resultat.tour_monstres.actions'))->where('type', 'phase_rejouee'))->toHaveCount(0)
        ->and($h1->fresh()->conditions()->where('nom', 'Étourdi')->exists())->toBeFalse();

    $menu = test()->actingAs($bob, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();

    expect($menu->json('personnage_id'))->toBe($h2->id);
});

it('un ALLIÉ libre compte comme quelqu\'un qui peut agir : son héros étourdi ne fait rejouer personne, et l\'allié joue', function () {
    // Le code existant : `acteurActif()` désigne l'allié d'un héros qui a joué, tant que l'allié n'a pas joué. Il reçoit
    // son menu (sur la manette de son héros), donc le groupe ne se fige pas et Zargon ne rejoue pas. Écart nommé avec
    // le loup, qui consomme aussi son allié (`MaledictionLoupGarou::ouvrirTour()`) — `docs/regles/combat-et-tour.md`.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe('table-1', 20);
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    test()->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $merc->id])->assertStatus(201);
    oublierQuetesFictivesDeGardien($groupe);
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update(['revele' => true]);

    // L'allié sur une case libre à côté d'Albrecht : c'est là qu'un allié qui peut agir se tient.
    $allie = $groupe->fresh()->mercenaires()->firstOrFail();
    $etatHeros = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();
    $libre = caseAdjacenteLibre($quete, (int) $etatHeros->position_x, (int) $etatHeros->position_y);
    $allie->update(['position_x' => $libre['x'], 'position_y' => $libre['y']]);

    // Étourdi posé maintenant : il ne consomme le tour qu'à l'OUVERTURE du round suivant.
    $heros->conditions()->attach(Condition::where('nom', 'Étourdi')->value('id'), ['duree' => 0, 'source' => 'sort_dread:Tourmente']);
    $avant = rshRoundsOuverts($groupe);

    // Le héros passe, son allié joue (il passe aussi), puis la phase des monstres.
    desFiges(array_fill(0, 300, 4));
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
    desFiges(array_fill(0, 300, 4));
    $reponse = test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre_allie'])->assertStatus(202);

    // Le round s'ouvre : Albrecht perd son tour, mais son allié a la main — rien n'est rejoué.
    expect(rshRoundsOuverts($groupe) - $avant)->toBe(1)
        ->and(collect($reponse->json('resultat.tour_monstres.actions'))->where('type', 'tour_perdu'))->toHaveCount(1)
        ->and(collect($reponse->json('resultat.tour_monstres.actions'))->where('type', 'phase_rejouee'))->toHaveCount(0);

    $menu = test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();

    expect($menu->json('allie_id'))->toBe((int) $allie->id);
});

it('MIND LOCK à DEUX héros : le gelé perd son tour, son compagnon joue — la phase des monstres ne se rejoue PAS', function () {
    // AVANT le 2026-10-31 : la garde ne regardait que la présence d'un gel, pas qui pouvait encore jouer. Le round
    // s'ouvrait avec Albrecht gelé ET Brunhild libre, la phase se rejouait tout de même, et Brunhild attendait deux
    // tours de Zargon pour agir. Tous les dés valent 4 : Xenloth n'obtient aucun crâne, donc aucun nouveau gel —
    // seul le gel de l'ouverture compte ici.
    $ctx = rshDeuxHeros('Xenloth', ['Verrou mental' => 5, 'Explosion mentale' => 0]);
    ['alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'h1' => $h1, 'h2' => $h2, 'instance' => $xenloth] = $ctx;

    // Albrecht gelé par deux crânes nets : deux tours perdus, deux cartes prêtées.
    rshGeler($ctx, $h1, $xenloth, 2);

    $avant = rshRoundsOuverts($groupe);

    desFiges(array_fill(0, 300, 4));
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    desFiges(array_fill(0, 300, 4));
    $reponse = test()->actingAs($bob, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect(rshRoundsOuverts($groupe) - $avant)->toBe(1)
        ->and(collect($reponse->json('resultat.tour_monstres.actions'))->where('type', 'verrou_mental_tour'))->toHaveCount(1);

    // Brunhild a la main : son menu s'ouvre. Albrecht, gelé, n'en a pas.
    $menuBrunhild = test()->actingAs($bob, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();

    expect($menuBrunhild->json('menu'))->not->toBeNull()
        ->and($menuBrunhild->json('personnage_id'))->toBe($h2->id);

    expect(test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->json('menu'))->toBeNull();
});
