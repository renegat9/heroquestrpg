<?php

declare(strict_types=1);

use App\Events\EtapePreparation;
use App\Jobs\GenererRecitsQuete;
use App\Jobs\GenererVoixQuete;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * L'étape « pret » CLÔT la séquence de préparation : c'est elle qui referme la
 * carte plein écran d'`OuvertureQuete.vue`. Elle n'est diffusée que d'UN seul
 * endroit — le `finally` de {@see GenererVoixQuete} — ce qui en fait un point
 * unique de défaillance : rien ne la rattrape, et la table reste en chargement
 * pour toujours quand elle manque.
 *
 * Mesuré sur la pile réelle le 2026-08-22 : le job héritait du `--timeout=120`
 * du worker et le dépassait STRUCTURELLEMENT (une synthèse par salle, ~15 s
 * pièce, 8 à 10 salles) — tué à 2 min exactement, quête après quête. Or le
 * timeout Laravel n'est pas une exception qui remonte : `Worker::kill()` fait
 * un `posix_kill(SIGKILL)`, donc le `finally` NE S'EXÉCUTE PAS.
 *
 * Ces tests verrouillent les deux moitiés du correctif : un plafond assez haut
 * pour que le cas nominal passe, et un `failed()` pour que le cas anormal
 * annonce quand même la fin.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

it('déclare un plafond assez large pour une quête entière, et le job le plus long ne dépasse pas retry_after', function () {
    $voix = new ReflectionClass(GenererVoixQuete::class);
    $recits = new ReflectionClass(GenererRecitsQuete::class);

    $plafondVoix = $voix->getDefaultProperties()['timeout'] ?? null;
    $plafondRecits = $recits->getDefaultProperties()['timeout'] ?? null;

    // Déclaré : sans ça le job hérite du `--timeout` du worker (120 s en
    // production, docker-compose.yml) et meurt au milieu de la synthèse.
    expect($plafondVoix)->toBeInt()
        ->and($plafondVoix)->toBeGreaterThan(120);

    // ⚠ Et SOUS `retry_after`, sinon la file ré-réserve un job encore en cours
    // et facture la même synthèse deux fois — le piège déjà payé par
    // GenererRecitsQuete le 2026-08-18.
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect($retryAfter)->toBeGreaterThan(max((int) $plafondVoix, (int) $plafondRecits));
});

it('annonce « pret » même quand le job échoue — un SIGKILL saute le finally', function () {
    Event::fake([EtapePreparation::class]);

    ['quete' => $quete] = demarrerQueteAvecMonstre('Gobelin');

    (new GenererVoixQuete($quete->id))->failed(new RuntimeException('timeout simulé'));

    Event::assertDispatched(
        EtapePreparation::class,
        fn (EtapePreparation $e) => $e->etape === 'pret' && $e->groupe->is($quete->groupe)
    );
});

it('n’explose pas quand le groupe a été purgé entre-temps (campagne arrêtée)', function () {
    Event::fake([EtapePreparation::class]);

    // Quête inexistante : `groupe()` renvoie null, `failed()` doit se taire.
    (new GenererVoixQuete(999_999))->failed(new RuntimeException('timeout simulé'));

    Event::assertNotDispatched(EtapePreparation::class);
});
