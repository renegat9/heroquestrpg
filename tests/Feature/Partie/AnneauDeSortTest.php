<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Sort;
use App\Partie\Equipement;
use App\Partie\MoteurSorts;
use App\Partie\Sauvegarde;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * L'ANNEAU DE SORT (carte © 2024, René 2026-11-05) : « At the beginning of each quest, you must declare which spell is
 * stored in the ring » — une fenêtre IMPOSÉE au premier tour du porteur qui lance des sorts ; le sort déclaré se lance
 * deux fois dans la quête, « may not be cast twice on the same turn ».
 * LA BAGUETTE DE RAPPEL : « allows the Elf or Wizard to cast two separate and different spells ».
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Équipe une pièce dans un emplacement, sans garde-fou. */
function porterPieceAnneau(Personnage $p, string $nom, string $emplacement): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'quantite' => 1,
        'emplacement' => $emplacement,
    ]);
}

/** Un magicien (feu + eau), au contact d'une Momie gonflée à 10 PV : elle encaisse deux Boules de Feu. */
function magicienALAnneau(bool $anneau = true, string $classe = 'magicien'): array
{
    $ctx = demarrerQueteAvecMonstre('Momie', ['classe' => $classe]);
    $moteur = app(MoteurSorts::class);
    $moteur->attacherElement($ctx['heros'], 'feu');
    $moteur->attacherElement($ctx['heros'], 'eau');

    $ctx['instance']->update(['pv_body' => 10, 'pv_body_max' => 10]);

    if ($anneau) {
        porterPieceAnneau($ctx['heros'], 'Anneau de Sort', 'talisman');
    }

    // Dés figés : 4 = bouclier blanc. La Momie ne résiste pas au feu, et ses coups ne passent pas.
    desFiges(array_fill(0, 400, 4));

    return $ctx;
}

/** @return array<string, mixed> le menu publié pour ce héros */
function menuDeAnneau(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];
}

function etatAnneau(array $ctx): EtatPersonnageQuete
{
    return EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $ctx['heros']->id)->firstOrFail();
}

function bouleAnneau(): Sort
{
    return Sort::where('nom', 'Boule de Feu')->firstOrFail();
}

/** Lance la Boule de Feu sur la Momie, par la vraie route. */
function lancerBouleAnneau(array $ctx): Illuminate\Testing\TestResponse
{
    menuDeAnneau($ctx);

    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.bouleAnneau()->id, 'cible_id' => $ctx['instance']->id, 'cible_type' => 'monstre'],
    ]);
}

/** Finit le tour du héros par la vraie route : les monstres jouent, la manche se rouvre. */
function finirLeTourAnneau(array $ctx): void
{
    menuDeAnneau($ctx);
    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
}

function declarerBouleAnneau(array $ctx): Illuminate\Testing\TestResponse
{
    menuDeAnneau($ctx);

    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'anneau_de_sort',
        'parametres' => ['cle' => 'sort:'.bouleAnneau()->id],
    ]);
}

function disponibleAuGrimoireAnneau(Personnage $p, Sort $sort): bool
{
    return (bool) $p->sorts()->wherePivot('sorts.id', $sort->id)->first()?->pivot->disponible;
}

it('EN JEU — au premier tour, le porteur qui lance des sorts ne voit QUE la déclaration, imposée', function () {
    $ctx = magicienALAnneau();
    $menu = menuDeAnneau($ctx);

    expect($menu['options'])->toHaveCount(1)
        ->and($menu['options'][0]['id'])->toBe('anneau_de_sort')
        ->and($menu['options'][0]['imposee'])->toBeTrue()
        ->and($menu['options'][0]['creneau'] ?? null)->toBe('interaction')
        ->and(collect($menu['options'][0]['parametres']['sorts'])->pluck('nom'))->toContain('Boule de Feu')
        ->and(collect($menu['options'][0]['parametres']['sorts'])->pluck('disponible')->unique()->all())->toBe([true])
        ->and($menu['situation'])->toContain('Anneau de Sort');

    // Un autre geste, même forgé, est refusé tant que la déclaration est due.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(422);
});

it('EN JEU — déclarer est gratuit ; le sort déclaré se lance deux fois, jamais deux fois dans le même tour', function () {
    $ctx = magicienALAnneau();
    // La Baguette de Rappel donne un second sort : c'est elle qui permettrait de relancer dans le même tour.
    porterPieceAnneau($ctx['heros'], 'Baguette de Rappel', 'arme_secondaire');

    declarerBouleAnneau($ctx)->assertStatus(202)->assertJsonPath('resultat.type', 'anneau_de_sort');

    $etat = etatAnneau($ctx);
    expect($etat->anneau_de_sort)->toBe(['sort_id' => bouleAnneau()->id, 'nom' => 'Boule de Feu', 'relance' => true])
        ->and((bool) $etat->a_agi)->toBeFalse()
        ->and(Evenement::where('groupe_id', $ctx['groupe']->id)->get()
            ->contains(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'anneau_de_sort'))->toBeTrue()
        // Le menu redevient ordinaire.
        ->and(collect(menuDeAnneau($ctx)['options'])->pluck('id'))->toContain('lancer_sort');

    // Premier lancer : gardé par l'anneau.
    lancerBouleAnneau($ctx)->assertStatus(202)->assertJsonPath('resultat.sort_preserve', 'anneau_de_sort');
    expect(disponibleAuGrimoireAnneau($ctx['heros'], bouleAnneau()))->toBeTrue()
        ->and(etatAnneau($ctx)->anneau_de_sort['relance'])->toBeFalse();

    // Même tour, second sort de la baguette : la Boule est GRISÉE, un autre sort reste offert.
    $sorts = collect(collect(menuDeAnneau($ctx)['options'])->firstWhere('id', 'lancer_sort')['parametres']['sorts']);
    expect($sorts->firstWhere('nom', 'Boule de Feu')['disponible'])->toBeFalse()
        ->and($sorts->firstWhere('nom', 'Courage')['disponible'])->toBeTrue();
    lancerBouleAnneau($ctx)->assertStatus(422);

    // Tour suivant, par la vraie route : la Boule revient, et ce second lancer l'épuise.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
    $reponse = lancerBouleAnneau($ctx)->assertStatus(202);
    expect($reponse->json('resultat.sort_preserve'))->toBeNull()
        ->and(disponibleAuGrimoireAnneau($ctx['heros'], bouleAnneau()))->toBeFalse();
});

it('un porteur qui ne lance pas de sorts ne voit aucune fenêtre', function () {
    $ctx = demarrerQueteAvecMonstre('Momie', ['classe' => 'barbare']);
    porterPieceAnneau($ctx['heros'], 'Anneau de Sort', 'talisman');
    desFiges(array_fill(0, 400, 4));

    $menu = menuDeAnneau($ctx);
    expect(collect($menu['options'])->pluck('id'))->not->toContain('anneau_de_sort')
        ->and(collect($menu['options'])->contains(fn ($o) => ! empty($o['imposee'])))->toBeFalse();
});

it('la fenêtre se ferme à la fin du premier tour : un anneau équipé ensuite ne garde rien', function () {
    $ctx = magicienALAnneau(anneau: false);

    finirLeTourAnneau($ctx);
    expect(etatAnneau($ctx)->anneau_de_sort)->toBe(['ferme' => true]);

    porterPieceAnneau($ctx['heros'], 'Anneau de Sort', 'talisman');
    expect(collect(menuDeAnneau($ctx)['options'])->pluck('id'))->not->toContain('anneau_de_sort');

    $reponse = lancerBouleAnneau($ctx)->assertStatus(202);
    expect($reponse->json('resultat.sort_preserve'))->toBeNull()
        ->and(disponibleAuGrimoireAnneau($ctx['heros'], bouleAnneau()))->toBeFalse();
});

it('la reprise garde le sort déclaré et le second lancer dépensé', function () {
    $ctx = magicienALAnneau();
    declarerBouleAnneau($ctx)->assertStatus(202);
    lancerBouleAnneau($ctx)->assertStatus(202);

    $snapshot = app(Sauvegarde::class)->snapshotter($ctx['groupe']->fresh(), 'test');
    etatAnneau($ctx)->update(['anneau_de_sort' => null]);
    app(Sauvegarde::class)->restaurer($ctx['groupe']->fresh(), $snapshot);

    expect(etatAnneau($ctx)->anneau_de_sort)->toBe(['sort_id' => bouleAnneau()->id, 'nom' => 'Boule de Feu', 'relance' => false]);
});

it('la Baguette de Rappel est à l\'Elfe et au Magicien, et à personne d\'autre', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $baguette = Objet::where('nom', 'Baguette de Rappel')->firstOrFail();
    $equipement = app(Equipement::class);

    foreach (['elfe' => true, 'magicien' => true, 'warlock' => false, 'druide' => false, 'barbare' => false] as $classe => $attendu) {
        $heros = creerHeros($alice, $groupe, "Essai {$classe}", 1, ['classe' => $classe]);
        expect($equipement->estAccessible($heros, $baguette))->toBe($attendu, $classe);
    }
});
