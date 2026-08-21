<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ChoixController;
use App\Models\Evenement;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
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
use Illuminate\Support\Facades\Http;

/*
 * La fin d'une quête doit se RACONTER.
 *
 * Constaté en campagne réelle le 2026-08-20, sur QUATRE fins consécutives : le
 * boss final tombait — apogée de quatre quêtes — et le journal enchaînait sur
 * le vote de sortie sans un mot. Le pack de chaque quête portait pourtant un
 * `victoire_quete` écrit par l'IA et payé, jamais lu.
 *
 * Deux causes, deux correctifs, deux tests ici :
 *  - `victoire_quete` n'était narré par AUCUN chemin. La table de
 *    correspondance de `ChoixController` le prévoyait, mais aucune fin de quête
 *    ne traverse ce contrôleur : elles passent toutes par `terminerQuete()`.
 *  - la mort d'un boss était noyée dans le silence du combat.
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

it('raconte la victoire, quel que soit le chemin de fin de quête', function () {
    ['groupe' => $groupe] = demarrerQueteAvecMonstre('Gobelin');

    $avant = Evenement::where('groupe_id', $groupe->id)->where('type', 'narration')->count();

    acheverLaQuete($groupe);

    $narrations = Evenement::where('groupe_id', $groupe->id)
        ->where('type', 'narration')->orderBy('sequence')->get();

    expect($narrations->count())->toBeGreaterThan($avant, 'la fin de quête n’a rien narré');

    // Le texte vient du repli scripté (aucune clé d'API ici) : c'est justement
    // le cas nominal d'une partie jouée sans IA, et il doit parler lui aussi.
    $derniere = (string) ($narrations->last()->payload['texte'] ?? '');
    expect($derniere)->not->toBeEmpty()
        ->and(config('narration.repli.victoire_quete.variantes'))->toContain($derniere);
});

/**
 * ⚠ L'ORDRE est le piège du correctif. `diffuserRecit()` journalise, et
 * `Evenement.quete_id` se résout depuis la quête COURANTE du groupe : narrer
 * après le passage au hub rattacherait la victoire à aucune quête, et elle
 * disparaîtrait du journal de cette partie.
 */
it('rattache la narration de victoire à la quête, pas au vide', function () {
    ['groupe' => $groupe, 'quete' => $quete] = demarrerQueteAvecMonstre('Gobelin');

    acheverLaQuete($groupe);

    $victoire = Evenement::where('groupe_id', $groupe->id)
        ->where('type', 'narration')->orderByDesc('sequence')->first();

    expect((int) $victoire->quete_id)->toBe((int) $quete->id)
        ->and($groupe->fresh()->phase)->toBe('hub');
});

/**
 * La mort d'un boss perce le silence du combat — et elle seule. `momentFort()`
 * résout le `tier` du CATALOGUE, jamais le nom affiché : l'habillage IA renomme
 * les créatures, et « Le Noyé de Gorrim » ne dit rien de son rang.
 */
it('perce le silence du combat pour un boss, pas pour un gobelin', function () {
    ['quete' => $quete] = demarrerQueteAvecMonstre('Gobelin');

    $boss = InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => Monstre::where('tier', 'boss')->firstOrFail()->id,
        'pv_body' => 0, 'pv_mind' => 4, 'position_x' => 1, 'position_y' => 1,
        'etat' => 'vaincu', 'revele' => true,
    ]);
    $gobelin = $quete->instancesMonstres()->where('id', '!=', $boss->id)->firstOrFail();

    $cle = function (array $resultat): string {
        $m = new ReflectionMethod(ChoixController::class, 'cleTempsFort');
        $m->setAccessible(true);

        return $m->invoke(app(ChoixController::class), $resultat);
    };

    $frappe = fn (int $id) => ['type' => 'attaque', 'degats' => 3, 'cible_vaincue' => true,
        'cible' => ['instance_id' => $id, 'nom' => 'peu importe le nom affiché']];

    expect($cle($frappe($boss->id)))->toBe('boss_vaincu')
        ->and($cle($frappe($gobelin->id)))->toBe('attaque_mort');
});

/**
 * La frappe balayée abat plusieurs cibles d'un geste et les liste dans
 * `frappes[]` : l'oublier tairait précisément la mort la plus spectaculaire du
 * jeu — celle où le boss tombe au milieu de ses gardes.
 */
it('voit le boss abattu dans une frappe balayée', function () {
    ['quete' => $quete] = demarrerQueteAvecMonstre('Gobelin');

    $boss = InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => Monstre::where('tier', 'boss')->firstOrFail()->id,
        'pv_body' => 0, 'pv_mind' => 4, 'position_x' => 1, 'position_y' => 1,
        'etat' => 'vaincu', 'revele' => true,
    ]);

    $m = new ReflectionMethod(ChoixController::class, 'momentFort');
    $m->setAccessible(true);

    expect($m->invoke(app(ChoixController::class), [
        'type' => 'attaque',
        'frappes' => [
            ['cible_vaincue' => false, 'cible' => ['instance_id' => 999999]],
            ['cible_vaincue' => true, 'cible' => ['instance_id' => $boss->id]],
        ],
    ]))->toBeTrue();
});
