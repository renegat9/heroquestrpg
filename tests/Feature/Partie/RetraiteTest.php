<?php

declare(strict_types=1);

use App\Events\NarrationDiffusee;
use App\Models\Evenement;
use App\Models\Quete;
use App\Partie\MenuMoteur;
use App\Partie\Votes\VoteGroupe;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * BATTRE EN RETRAITE (René, 2026-08-21).
 *
 * `quitter_donjon` ne dit que « on a fini, on rentre » : il exige l'objectif
 * accompli ou le donjon vidé. Un groupe en train de PERDRE ne pouvait donc ni
 * gagner ni partir — vécu en campagne réelle avec deux héros à terre, deux
 * survivants fragiles et le boss debout : la seule issue mécanique était de
 * tomber entièrement.
 *
 * La retraite ouvre un vote à TROIS issues, parce que décrocher n'a de sens
 * que si l'on sait pour aller où.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Force le décompte d'un vote de retraite déjà ouvert, sans passer par 4 bulletins. */
function resoudreRetraite(App\Models\Groupe $groupe, array $decompte): array
{
    $vote = app(VoteGroupe::class)->actif($groupe);
    $m = new ReflectionMethod(VoteGroupe::class, 'resoudre');
    $m->setAccessible(true);

    // `voter()` vide le vote du cache à complétude ; en appelant `resoudre()`
    // directement il faut le faire soi-même, sinon le vote suivant se heurte à
    // « un vote est déjà en cours ».
    $resultat = $m->invoke(app(VoteGroupe::class), $groupe->fresh(), $vote, $decompte);
    Illuminate\Support\Facades\Cache::forget(VoteGroupe::cle($groupe->id));

    return $resultat;
}

it('propose la retraite MÊME quand tout va mal — c’est son seul intérêt', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'quete' => $quete] = demarrerQueteAvecMonstre('Gobelin');

    // Situation exacte de la campagne : un monstre debout, objectif hors
    // d'atteinte. `quitter_donjon` est fermé, la retraite doit être ouverte.
    // ⚠ `demarrerQueteAvecMonstre` rend le groupe d'AVANT le démarrage : sans
    // `fresh()`, MenuMoteur le croit encore au hub et ne propose rien du donjon.
    $menu = app(MenuMoteur::class)->generer($groupe->fresh(), $heros);
    $ids = collect($menu['options'])->pluck('id')->all();

    expect($ids)->toContain('battre_en_retraite')
        ->and($ids)->not->toContain('quitter_donjon');
});

it('recommence la quête à la majorité, et le dit', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'quete' => $quete] = demarrerQueteAvecMonstre('Gobelin');

    // On abîme l'état : c'est ce que la retraite doit défaire.
    $heros->update(['pv_body' => 1]);

    app(VoteGroupe::class)->lancerRetraite($groupe, ['type' => 'personnage', 'id' => $heros->id]);
    $resultat = resoudreRetraite($groupe, ['recommencer' => 3, 'arreter' => 0, 'continuer' => 1]);

    expect($resultat['option_id'])->toBe('recommencer')
        ->and($resultat['applique'])->toBeTrue()
        ->and($groupe->fresh()->phase)->toBe('quete')
        ->and($heros->fresh()->pv_body)->toBeGreaterThan(1);

    $textes = Evenement::where('groupe_id', $groupe->id)->where('type', 'narration')
        ->get()->map(fn ($e) => (string) ($e->payload['texte'] ?? ''))->all();

    expect(array_intersect($textes, config('narration.repli.retraite.variantes')))
        ->not->toBeEmpty('la retraite n’a pas été racontée');
});

it('arrête la campagne à la majorité', function () {
    ['groupe' => $groupe, 'heros' => $heros] = demarrerQueteAvecMonstre('Gobelin');
    Event::fake([NarrationDiffusee::class]);

    // ⚠ L'id est capturé AVANT : la clôture purge la campagne, et le modèle en
    // mémoire ne pointe plus sur rien après coup.
    $groupeId = (int) $groupe->id;

    app(VoteGroupe::class)->lancerRetraite($groupe, ['type' => 'personnage', 'id' => $heros->id]);
    $resultat = resoudreRetraite($groupe, ['recommencer' => 0, 'arreter' => 3, 'continuer' => 1]);

    expect($resultat['option_id'])->toBe('arreter')
        ->and($resultat['applique'])->toBeTrue();

    // ⚠ Le récit se vérifie sur la DIFFUSION, pas sur le journal : la clôture
    // purge la campagne, événements compris. Les joueurs entendent bien la
    // fin — elle est simplement effacée juste après, ce qui est le propre
    // d'une campagne qu'on arrête.
    Event::assertDispatched(NarrationDiffusee::class, function (NarrationDiffusee $e) {
        return in_array($e->texte, config('narration.repli.campagne_abandonnee.variantes'), true);
    });
});

/**
 * ⚠ Les deux issues effacent ou terminent la partie de TOUT LE MONDE : une
 * minorité ne doit jamais pouvoir les imposer. Toute égalité fait continuer,
 * même une égalité entre les deux issues destructrices.
 */
it('continue sur toute égalité, y compris entre recommencer et arrêter', function () {
    ['groupe' => $groupe, 'heros' => $heros] = demarrerQueteAvecMonstre('Gobelin');
    $queteAvant = $groupe->fresh()->quete_courante_id;

    foreach ([
        ['recommencer' => 2, 'arreter' => 0, 'continuer' => 2],
        ['recommencer' => 2, 'arreter' => 2, 'continuer' => 0],
    ] as $decompte) {
        app(VoteGroupe::class)->lancerRetraite($groupe, ['type' => 'personnage', 'id' => $heros->id]);
        $resultat = resoudreRetraite($groupe, $decompte);

        expect($resultat['option_id'])->toBe('continuer')
            ->and($resultat['applique'])->toBeFalse()
            ->and($groupe->fresh()->quete_courante_id)->toBe($queteAvant);
    }
});

/**
 * Best-effort : sans snapshot de départ, la retraite échoue mais la partie
 * continue. Mieux vaut un vote sans effet qu'un groupe laissé à moitié défait.
 */
it('ne casse rien quand la quête n’a aucun point de départ', function () {
    ['groupe' => $groupe, 'heros' => $heros] = demarrerQueteAvecMonstre('Gobelin');

    App\Models\Snapshot::where('groupe_id', $groupe->id)->delete();

    app(VoteGroupe::class)->lancerRetraite($groupe, ['type' => 'personnage', 'id' => $heros->id]);
    $resultat = resoudreRetraite($groupe, ['recommencer' => 3, 'arreter' => 0, 'continuer' => 0]);

    expect($resultat['applique'])->toBeFalse()
        ->and($resultat)->toHaveKey('echec')
        ->and(Quete::find($groupe->fresh()->quete_courante_id)?->etat)->toBe('en_cours');
});
