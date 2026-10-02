<?php

declare(strict_types=1);

use App\Agent\SanteFileAttente;
use App\Agent\SanteServices;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * GET /api/systeme — voir docs/contrat-api.md « Système ». Route PUBLIQUE,
 * comme /api/parametres : doit fonctionner sans compte ni session de table.
 *
 * `CACHE_STORE=array` en test (phpunit.xml) persiste pour tout le process :
 * on vide explicitement à chaque test pour ne pas hériter de l'état
 * `SanteServices`/`SanteFileAttente` d'un test précédent.
 */
beforeEach(function () {
    Cache::flush();

    // Baseline déterministe pour les 3 clés serveur (même règle que ParametresTest).
    config([
        'services.anthropic.api_key' => null,
        'services.gemini.api_key' => null,
        'services.voyage.api_key' => null,
    ]);

    // ⚠ PAS de `Http::fake()` générique ici : Laravel essaie ses règles de
    // fake dans l'ORDRE d'enregistrement et s'arrête à la première qui
    // correspond — un `Http::fake()` sans argument posé ici matcherait TOUT
    // en premier et rendrait inopérant tout `Http::fake([...])` plus précis
    // posé ensuite dans un test (vécu : ça a fait échouer la moitié de ce
    // fichier). Chaque test pose donc SA PROPRE règle, complète, en un seul
    // appel `Http::fake([...])` — avec un `'*' => ...` de repli dans le même
    // appel pour la sonde Qdrant (sinon réellement injoignable : le nom
    // `qdrant` ne résout nulle part dans le conteneur de test).

    // Reverb : port fermé sur loopback → échec de connexion RAPIDE et
    // déterministe, sans dépendre d'une résolution DNS du nom de service
    // docker-compose ("reverb") qui n'existe pas dans le conteneur de test.
    config([
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => 1,
    ]);
});

it('accessible sans session de table ni compte joueur', function () {
    Http::fake();
    $this->getJson('/api/systeme')->assertOk();
});

it('répond la forme EtatSysteme avec les 11 services attendus', function () {
    Http::fake();
    $data = $this->getJson('/api/systeme')->assertOk()->json();

    expect($data)->toHaveKeys(['genere_a', 'services', 'consommation', 'avertissements']);

    $ids = collect($data['services'])->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing([
        'anthropic', 'gemini_texte', 'gemini_tts', 'gemini_image', 'voyage',
        'qdrant', 'mariadb', 'reverb', 'queue_queue', 'queue_queue-jeu', 'backups',
    ]);

    foreach ($data['services'] as $s) {
        expect($s)->toHaveKeys(['id', 'libelle', 'famille', 'etat', 'detail', 'latence_ms', 'derniere_reussite', 'dernier_echec', 'credit', 'console_url'])
            ->and($s['famille'])->toBeIn(['externe', 'interne'])
            ->and($s['etat'])->toBeIn(['ok', 'degrade', 'panne', 'non_configure', 'inconnu'])
            ->and($s['credit'])->toHaveKeys(['etat', 'source', 'explication']);
    }
});

it('sans aucune clé serveur : les 4 services LLM/embeddings sont non_configure, jamais panne', function () {
    Http::fake();
    $data = $this->getJson('/api/systeme')->assertOk()->json();
    $parId = collect($data['services'])->keyBy('id');

    foreach (['anthropic', 'gemini_texte', 'gemini_tts', 'gemini_image', 'voyage'] as $id) {
        expect($parId[$id]['etat'])->toBe('non_configure')
            ->and($parId[$id]['credit']['etat'])->toBe('inconnu');
    }
});

it('clés configurées + sondes gratuites OK : anthropic et les 3 sous-services Gemini sont ok', function () {
    config([
        'services.anthropic.api_key' => 'sk-ant-TEST-KEY',
        'services.gemini.api_key' => 'sk-gem-TEST-KEY',
    ]);

    Http::fake([
        'api.anthropic.com/v1/models' => Http::response(['data' => []], 200),
        'generativelanguage.googleapis.com/*' => Http::response([
            'models' => [
                ['name' => 'models/'.config('services.gemini.model_texte')],
                ['name' => 'models/'.config('services.gemini.model')],
                ['name' => 'models/'.config('services.gemini.model_image')],
            ],
        ], 200),
        '*' => Http::response([], 200), // Qdrant et tout le reste non ciblé explicitement
    ]);

    $reponse = $this->getJson('/api/systeme')->assertOk();
    $data = $reponse->json();
    $parId = collect($data['services'])->keyBy('id');

    expect($parId['anthropic']['etat'])->toBe('ok')
        ->and($parId['gemini_texte']['etat'])->toBe('ok')
        ->and($parId['gemini_tts']['etat'])->toBe('ok')
        ->and($parId['gemini_image']['etat'])->toBe('ok')
        ->and($parId['mariadb']['etat'])->toBe('ok')
        ->and($parId['reverb']['etat'])->toBe('panne'); // port fermé, volontaire (voir beforeEach)

    // Jamais les clés dans la réponse.
    expect($reponse->getContent())
        ->not->toContain('sk-ant-TEST-KEY')
        ->not->toContain('sk-gem-TEST-KEY');
});

it('un dernier appel Anthropic réel classé credit_epuise fait passer l\'état à panne même si la sonde gratuite répond', function () {
    config(['services.anthropic.api_key' => 'sk-ant-SECRET-NE-JAMAIS-FUITER']);

    SanteServices::signalerEchec('anthropic', 400, json_encode([
        'error' => ['type' => 'billing_error', 'message' => 'Your credit balance is too low to access the Claude API.'],
    ]));

    Http::fake([
        // La sonde gratuite, elle, répond : une clé invalide n'est pas la panne ici.
        'api.anthropic.com/v1/models' => Http::response(['data' => []], 200),
        '*' => Http::response([], 200), // Qdrant
    ]);

    $reponse = $this->getJson('/api/systeme')->assertOk();
    $data = $reponse->json();
    $anthropic = collect($data['services'])->firstWhere('id', 'anthropic');

    expect($anthropic['etat'])->toBe('panne')
        ->and($anthropic['credit']['etat'])->toBe('epuise')
        ->and($anthropic['credit']['source'])->toBe('dernier_appel')
        ->and($anthropic['dernier_echec']['categorie'])->toBe('credit_epuise')
        ->and($anthropic['dernier_echec']['message'])->toContain('credit balance');

    expect($reponse->getContent())->not->toContain('sk-ant-SECRET-NE-JAMAIS-FUITER');

    // Avertissement remonté en tête de payload.
    expect(collect($data['avertissements'])->contains(fn ($a) => str_contains($a, 'Anthropic')))->toBeTrue();
});

it('un succès plus récent qu\'un échec antérieur efface le verdict de crédit épuisé', function () {
    config(['services.anthropic.api_key' => 'sk-ant-TEST']);

    SanteServices::signalerEchec('anthropic', 400, json_encode(['error' => ['type' => 'billing_error', 'message' => 'low credit']]));
    // Un appel plus récent a réussi (ex. crédit rechargé entre temps).
    SanteServices::signalerSucces('anthropic');

    Http::fake(['api.anthropic.com/v1/models' => Http::response(['data' => []], 200), '*' => Http::response([], 200)]);

    $data = $this->getJson('/api/systeme')->assertOk()->json();
    $anthropic = collect($data['services'])->firstWhere('id', 'anthropic');

    expect($anthropic['credit']['etat'])->toBe('ok')
        ->and($anthropic['etat'])->toBe('ok');
});

it('un worker dont le version_code diffère du code actuel est affiché degrade, et remonté en avertissement', function () {
    Http::fake();
    $actuel = (int) SanteFileAttente::versionCodeActuel();

    Cache::forever('sante:worker:temps-reel,default', [
        'queue' => 'temps-reel,default',
        'pid' => 4242,
        'demarre_a' => now()->subHour()->toIso8601String(),
        'version_code' => (string) max(0, $actuel - 999999),
        'vu_a' => now()->toIso8601String(),
    ]);

    $data = $this->getJson('/api/systeme')->assertOk()->json();
    $worker = collect($data['services'])->firstWhere('id', 'queue_queue');

    expect($worker['etat'])->toBe('degrade')
        ->and($worker['detail'])->toContain('redémarrer');

    expect(collect($data['avertissements'])->contains(fn ($a) => str_contains($a, 'queue')))->toBeTrue();
});

it('un worker sans battement depuis plus de 5 minutes est affiché en panne', function () {
    Http::fake();
    Cache::forever('sante:worker:temps-reel', [
        'queue' => 'temps-reel',
        'pid' => 1,
        'demarre_a' => now()->subHours(2)->toIso8601String(),
        'version_code' => SanteFileAttente::versionCodeActuel(),
        'vu_a' => now()->subMinutes(10)->toIso8601String(),
    ]);

    $data = $this->getJson('/api/systeme')->assertOk()->json();
    $worker = collect($data['services'])->firstWhere('id', 'queue_queue-jeu');

    expect($worker['etat'])->toBe('panne');
});

it('un worker jamais vu est affiché inconnu, pas panne', function () {
    Http::fake();
    $data = $this->getJson('/api/systeme')->assertOk()->json();
    $worker = collect($data['services'])->firstWhere('id', 'queue_queue');

    expect($worker['etat'])->toBe('inconnu');
});

it('la réponse est mise en cache ~60s : un second appel ne re-sonde pas les fournisseurs', function () {
    config(['services.anthropic.api_key' => 'sk-ant-TEST']);
    Http::fake(['api.anthropic.com/v1/models' => Http::response(['data' => []], 200), '*' => Http::response([], 200)]);

    $this->getJson('/api/systeme')->assertOk();
    $apresPremierAppel = count(Http::recorded());
    expect($apresPremierAppel)->toBeGreaterThan(0);

    $this->getJson('/api/systeme')->assertOk();
    expect(count(Http::recorded()))->toBe($apresPremierAppel); // aucune sonde HTTP supplémentaire : servi du cache
});
