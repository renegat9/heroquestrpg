<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Engine\MotsClesSortDread as Mot;
use App\Models\Condition;
use App\Models\SortDread;
use App\Partie\MenuMoteur;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LE VENIN DURE ENFIN — « The effect ends at the end of their next turn »
 * (Jungles of Delthrak, F9907 p. 48, « Venomous »).
 *
 * Le venin se pose PENDANT la phase des monstres. `decrementerDurees()` tourne à la fin du
 * MÊME round, juste après cette phase : une condition posée à 1 tour y tombait aussitôt
 * (« Envenimé prend fin » dans la résolution même du coup), et le héros ne subissait jamais
 * la paralysie — qui doit pourtant couvrir SON prochain tour. Trouvé en partie de vérification
 * (verdict jungle 2026-10-10), noté au rapport de FilDeCombatPartieCibleeTest.
 *
 * Ces tests jouent le round PAR LES VRAIES ROUTES : un test de catalogue aurait passé avant
 * comme après le correctif.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/** Les ids d'options que le menu offre à ce héros, tel que le moteur le génère. */
function optionsDuHeros(array $ctx): array
{
    $menu = app(MenuMoteur::class)->generer($ctx['groupe']->fresh(), $ctx['heros']->fresh());

    return array_column($menu['options'], 'id');
}

/** Un Gobelin au contact, rendu venimeux — il s'agit ici du venin, pas de ce qu'une vraie créature fait d'autre. */
function sceneVenimeuse(): array
{
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['instance']->monstre->update(['capacites' => ['venimeux']]);

    return $ctx;
}

/** Joue « Terminer le tour » par la vraie route avec ces dés, et rend la réponse. */
function terminerLeTour(array $ctx, array $des): Illuminate\Testing\TestResponse
{
    desFiges($des);
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges($des);

    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
}

it('le venin posé par un coup de monstre est ENCORE sur le héros quand son tour s\'ouvre — et l\'immobilise', function () {
    $ctx = sceneVenimeuse();

    // Tout à 1 : trois crânes, aucun bouclier — le coup porte —, puis un 1 au dé du venin (< 5).
    $reponse = terminerLeTour($ctx, array_fill(0, 30, 1));
    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))->firstWhere('type', 'attaque_monstre');

    expect($attaque['venin'])->toBeTrue();

    // ⚠ Le défaut : la condition posée pendant la phase des monstres tombait dans la MÊME résolution.
    $envenime = $ctx['heros']->fresh()->conditions()->where('nom', 'Envenimé')->first();

    expect($envenime)->not->toBeNull('le venin est tombé avant même que le héros ne joue');

    // Et il MORD : le tour qui s'ouvre n'offre plus « Se déplacer », et la route refuse le pas.
    expect(optionsDuHeros($ctx))->not->toContain('se_deplacer')
        ->and(optionsDuHeros($ctx))->toContain('attendre');

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => (int) $ctx['etatHeros']->position_x, 'y' => (int) $ctx['etatHeros']->position_y + 1],
    ])->assertStatus(422);
});

it('« …may still perform actions » : le héros envenimé garde son action — seul son déplacement est pris', function () {
    $ctx = sceneVenimeuse();
    terminerLeTour($ctx, array_fill(0, 30, 1));

    expect($ctx['heros']->fresh()->conditions()->where('nom', 'Envenimé')->exists())->toBeTrue();

    // Le Gobelin est au contact : l'attaque reste offerte…
    expect(optionsDuHeros($ctx))->toContain('attaquer');
});

it('rien d\'inventé pour s\'en défaire : le livret ne donne que le temps et la Potion de sang de serpent', function () {
    $ctx = sceneVenimeuse();
    terminerLeTour($ctx, array_fill(0, 30, 1));

    expect($ctx['heros']->fresh()->conditions()->where('nom', 'Envenimé')->exists())->toBeTrue();

    // « Détruire les entraves » (lianes, Étreinte des Ronces) lève TOUTE condition qui interdit le
    // déplacement : le venin n'est pas une entrave, et aucune action ne le lève dans le livret.
    expect(optionsDuHeros($ctx))->not->toContain('liberer_entraves')
        ->and(app(MoteurSorts::class)->entravesLiberables($ctx['heros']->fresh()))->toHaveCount(0);
});

it('le venin tombe à la fin de SON prochain tour : il couvre son tour, pas un tour de plus', function () {
    $ctx = sceneVenimeuse();
    terminerLeTour($ctx, array_fill(0, 30, 1));

    expect($ctx['heros']->fresh()->conditions()->where('nom', 'Envenimé')->exists())->toBeTrue();

    // Son tour : il l'a passé sans bouger (il ne le pouvait pas). Des 6 partout : le Gobelin ne touche
    // plus, donc aucun nouveau jet de venin ne vient prolonger le premier.
    $reponse = terminerLeTour($ctx, array_fill(0, 30, 6));

    expect($ctx['heros']->fresh()->conditions()->where('nom', 'Envenimé')->exists())
        ->toBeFalse('le venin dure encore après le tour qu\'il devait couvrir')
        ->and(optionsDuHeros($ctx))->toContain('se_deplacer');

    // …et la fin se DIT : « Envenimé prend fin » dans la résolution du round qui la clôt.
    $annonce = collect($reponse->json('resultat.annonces_automatiques') ?? [])
        ->first(fn ($a) => ($a['type'] ?? null) === 'condition_terminee' && ($a['condition'] ?? null) === 'Envenimé');

    expect($annonce)->not->toBeNull('la fin du venin tombe sans un mot');
});

it('un second coup venimeux pendant le tour couvert prolonge le venin d\'un tour — nouveau jeton, nouveau « prochain tour »', function () {
    $ctx = sceneVenimeuse();
    terminerLeTour($ctx, array_fill(0, 30, 1));

    // Son tour passe, le Gobelin le touche et l'envenime de nouveau.
    terminerLeTour($ctx, array_fill(0, 30, 1));

    expect($ctx['heros']->fresh()->conditions()->where('nom', 'Envenimé')->exists())->toBeTrue()
        ->and(optionsDuHeros($ctx))->not->toContain('se_deplacer');

    // Le tour couvert par CE second jeton passé, sans nouveau coup : le venin tombe.
    terminerLeTour($ctx, array_fill(0, 30, 6));

    expect($ctx['heros']->fresh()->conditions()->where('nom', 'Envenimé')->exists())->toBeFalse()
        ->and(optionsDuHeros($ctx))->toContain('se_deplacer');
});

it('compte le venin en TOURS DE LA VICTIME : le tic qui clôt la phase des monstres n\'en est pas un', function () {
    $ctx = sceneVenimeuse();
    $heros = $ctx['heros'];
    $sorts = app(MoteurSorts::class);

    desFiges([1]); // < 5 : le jet de résistance échoue
    expect(app(MoteurDread::class)->appliquerVenin($ctx['instance'], $heros))->toBeTrue();

    $duree = fn () => $heros->fresh()->conditions()->where('nom', 'Envenimé')->first()?->pivot->duree;

    // Le catalogue dit « 1 tour » ; la ligne posée en phase des monstres porte ce tour PLUS le tic
    // de fin de round qui va aussitôt la décompter.
    expect((int) Condition::where('nom', 'Envenimé')->value('duree_defaut'))->toBe(1)
        ->and($duree())->toBe(2);

    $sorts->decrementerDurees($ctx['quete']); // fin du round où le coup est tombé
    expect($duree())->toBe(1, 'le venin doit encore être là pour le tour du héros');

    $sorts->decrementerDurees($ctx['quete']); // fin du round où le héros a joué son tour envenimé
    expect($duree())->toBeNull();
});

it('le venin est la SEULE condition à compteur que la phase des monstres pose sur un héros — Étourdi et Endormi n\'ont pas ce défaut', function () {
    // Même lecture que `MoteurDread::poserConditionHeros()` : une rupture ne porte pas de compteur,
    // sinon la durée du sort, sinon celle du catalogue. Un sort de Dread qui poserait « N tours »
    // devrait passer par `dureeAPoserEnPhaseDesMonstres()` — ce test le dit au premier qui l'ajoute.
    $comptees = SortDread::query()->get()
        ->filter(fn (SortDread $sort) => is_string(data_get($sort->effet, 'condition_appliquee')))
        ->filter(function (SortDread $sort) {
            if (in_array(data_get($sort->effet, 'resistance'), Mot::RESISTANCES_RUPTURE, true)) {
                return false;
            }

            $nom = (string) data_get($sort->effet, 'condition_appliquee');
            $tours = data_get($sort->effet, 'duree_tours') ?? Condition::where('nom', $nom)->value('duree_defaut');

            return (int) $tours > 0;
        })
        ->map(fn (SortDread $sort) => $sort->nom.' → '.data_get($sort->effet, 'condition_appliquee'))
        ->values()->all();

    expect($comptees)->toBe([], 'un sort de Dread pose un compteur de tours : il perdrait un tour (cf. dureeAPoserEnPhaseDesMonstres)');

    // Et les deux conditions qu'on soupçonnait n'ont pas de compteur du tout : leur sortie est un déclencheur.
    expect((int) Condition::where('nom', 'Étourdi')->value('duree_defaut'))->toBe(0)
        ->and((int) Condition::where('nom', 'Endormi')->value('duree_defaut'))->toBe(0);
});
