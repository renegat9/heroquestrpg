<?php

declare(strict_types=1);

use App\Agent\AnthropicClient;
use App\Agent\ClientLLM;
use App\Agent\GeminiClient;
use App\Agent\Skills\ResumeCampagne;
use App\Agent\Skills\Skill;
use App\Agent\TraceurConsommation;
use App\Agent\ValidationSortie;
use App\Models\ConsommationIa;
use App\Models\GabaritQuete;
use App\Models\Quete;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Télémétrie de consommation LLM (App\Agent\TraceurConsommation,
 * App\Models\ConsommationIa) — jusqu'ici AUCUN compteur de tokens n'existait
 * ({@see \App\Agent\StatutIA} ne retient que le DERNIER essai). Ces tests
 * couvrent : le comptage à CHAQUE réponse HTTP (donc les retries de
 * Skill::generer() et le failover ClientLLMAvecRepli, invisibles autrement),
 * le versement d'usage normalisé par les deux clients, l'annonce automatique
 * du contexte par Skill::generer() (sans dépendre des jobs), l'agrégat
 * exposé au panneau Réglages, et le best-effort absolu (une panne
 * d'écriture ne casse jamais un appel LLM).
 */
beforeEach(function () {
    config([
        'services.anthropic.api_key' => 'cle-test',
        'services.gemini.api_key' => null, // pas de secours : un seul fournisseur en jeu par test
    ]);
});

it('TraceurConsommation::enregistrer() écrit une ligne et incrémente la tentative depuis pourGroupe()', function () {
    $traceur = app(TraceurConsommation::class);

    $traceur->pourGroupe(42, 'mon_skill_de_test');
    $traceur->enregistrer('anthropic', 'claude-test', ['entree' => 100, 'sortie' => 20, 'cache' => 5]);
    $traceur->enregistrer('anthropic', 'claude-test', ['entree' => 30, 'sortie' => 8]);

    $lignes = ConsommationIa::orderBy('id')->get();

    expect($lignes)->toHaveCount(2)
        ->and($lignes[0]->groupe_id)->toBe(42)
        ->and($lignes[0]->skill)->toBe('mon_skill_de_test')
        ->and($lignes[0]->tokens_entree)->toBe(100)
        ->and($lignes[0]->tokens_sortie)->toBe(20)
        ->and($lignes[0]->tokens_cache)->toBe(5)
        ->and($lignes[0]->tentative)->toBe(1)
        ->and($lignes[1]->tentative)->toBe(2)   // 2e appel depuis le même pourGroupe() = tentative 2
        ->and($lignes[1]->tokens_cache)->toBeNull(); // absente de l'usage fourni

    // Une nouvelle annonce de contexte repart à 1.
    $traceur->pourGroupe(null, 'autre_skill');
    $traceur->enregistrer('gemini', 'gemini-test', ['entree' => 5, 'sortie' => 1]);
    expect(ConsommationIa::query()->latest('id')->first())
        ->tentative->toBe(1)
        ->groupe_id->toBeNull()
        ->skill->toBe('autre_skill');
});

it('AnthropicClient verse l\'usage normalisé de la réponse HTTP au traceur', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 250, 'output_tokens' => 60, 'cache_read_input_tokens' => 12],
        ]),
    ]);

    app(TraceurConsommation::class)->pourGroupe(7, 'test_anthropic');

    $client = app()->make(AnthropicClient::class);
    $texte = $client->genererTexte('system', [['role' => 'user', 'content' => 'salut']]);

    expect($texte)->toBe('ok');

    $ligne = ConsommationIa::query()->latest('id')->first();
    expect($ligne)->not->toBeNull()
        ->and($ligne->fournisseur)->toBe('anthropic')
        ->and($ligne->groupe_id)->toBe(7)
        ->and($ligne->skill)->toBe('test_anthropic')
        ->and($ligne->tokens_entree)->toBe(250)
        ->and($ligne->tokens_sortie)->toBe(60)
        ->and($ligne->tokens_cache)->toBe(12)
        ->and($ligne->tentative)->toBe(1);
});

it('GeminiClient verse l\'usage normalisé de la réponse HTTP au traceur', function () {
    config(['services.gemini.api_key' => 'cle-test']);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'ok']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 22],
        ]),
    ]);

    app(TraceurConsommation::class)->pourGroupe(9, 'test_gemini');

    $client = app()->make(GeminiClient::class);
    $texte = $client->genererTexte('system', [['role' => 'user', 'content' => 'salut']]);

    expect($texte)->toBe('ok');

    $ligne = ConsommationIa::query()->latest('id')->first();
    expect($ligne)->not->toBeNull()
        ->and($ligne->fournisseur)->toBe('gemini')
        ->and($ligne->groupe_id)->toBe(9)
        ->and($ligne->tokens_entree)->toBe(80)
        ->and($ligne->tokens_sortie)->toBe(22)
        ->and($ligne->tokens_cache)->toBeNull(); // pas de cachedContentTokenCount dans cette réponse
});

it('Skill::generer() annonce automatiquement (groupe_id, nomOutil) au traceur — sans que le job y touche', function () {
    $groupe = creerGroupe();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'tool_use',
            'content' => [[
                'type' => 'tool_use',
                'name' => 'proposer_resume_campagne',
                'input' => ['resume' => 'Un résumé de campagne suffisamment long pour passer la validation du schéma.'],
            ]],
            'usage' => ['input_tokens' => 500, 'output_tokens' => 90],
        ]),
    ]);

    $contexte = [
        'groupe_id' => $groupe->id,
        'groupe' => ['nom' => $groupe->nom, 'theme' => $groupe->theme, 'ton' => null],
        'cloture' => ['issue' => 'victoire', 'or_partage' => 50, 'nb_quetes' => 1, 'nb_quetes_terminees' => 1],
    ];

    $sortie = app(ResumeCampagne::class)->generer($contexte);

    expect($sortie['resume'])->toContain('résumé de campagne');

    $ligne = ConsommationIa::query()->latest('id')->first();
    expect($ligne)->not->toBeNull()
        ->and($ligne->groupe_id)->toBe($groupe->id)
        ->and($ligne->skill)->toBe('proposer_resume_campagne') // ResumeCampagne::nomOutil(), pas un nom de job
        ->and($ligne->fournisseur)->toBe('anthropic')
        ->and($ligne->tentative)->toBe(1);
});

it('les retries de Skill::generer() produisent une ligne PAR appel HTTP facturé (tentative croissante)', function () {
    // Skill jetable, sans dépendre des skills réels (interdits à cet agent) :
    // valide le mécanisme générique de Skill::generer(), pas un skill précis.
    // Sortie TOUJOURS rejetée par validerMetier() → épuise les 3 tentatives
    // (Skill::MAX_RETRIES = 2) puis lève, mais chaque tentative a bien
    // déclenché un appel HTTP réel et donc une ligne de télémétrie.
    $skill = new class(app(ClientLLM::class), app(ValidationSortie::class), app(TraceurConsommation::class)) extends Skill
    {
        public const SCHEMA = [
            'type' => 'object',
            'required' => ['x'],
            'properties' => ['x' => ['type' => 'string']],
        ];

        public function nomOutil(): string
        {
            return 'skill_test_retries';
        }

        public function descriptionOutil(): string
        {
            return 'Skill de test — mesure les retries.';
        }

        protected function prompt(array $contexte): array
        {
            return ['system' => 'system de test', 'user' => 'user de test'];
        }

        protected function validerMetier(array $sortie, array $contexte): array
        {
            return ['toujours invalide, pour forcer les 3 tentatives'];
        }
    };

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'tool_use',
            'content' => [[
                'type' => 'tool_use',
                'name' => 'skill_test_retries',
                'input' => ['x' => 'peu importe'],
            ]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ]),
    ]);

    expect(fn () => $skill->generer(['groupe_id' => 3]))
        ->toThrow(\App\Agent\Exceptions\SortieInvalideException::class);

    $lignes = ConsommationIa::where('skill', 'skill_test_retries')->orderBy('id')->get();

    expect($lignes)->toHaveCount(3) // 1 + Skill::MAX_RETRIES
        ->and($lignes->pluck('tentative')->all())->toBe([1, 2, 3])
        ->and($lignes->pluck('groupe_id')->unique()->all())->toBe([3]);
});

it('ConsommationIa::agregat() additionne les totaux et calcule la moyenne par quête', function () {
    $groupe = creerGroupe();
    $gabarit = GabaritQuete::create(['nom' => 'Gabarit conso-test', 'type_jalon' => 'normale', 'structure' => []]);
    Quete::create([
        'groupe_id' => $groupe->id, 'gabarit_id' => $gabarit->id, 'titre' => 'Quête test',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours',
    ]);
    Quete::create([
        'groupe_id' => $groupe->id, 'gabarit_id' => $gabarit->id, 'titre' => 'Quête test 2',
        'position_arc' => 2, 'type_jalon' => 'normale', 'etat' => 'terminee',
    ]);

    ConsommationIa::create(['groupe_id' => $groupe->id, 'skill' => 's1', 'fournisseur' => 'anthropic', 'modele' => 'm', 'tokens_entree' => 100, 'tokens_sortie' => 20, 'tentative' => 1]);
    ConsommationIa::create(['groupe_id' => $groupe->id, 'skill' => 's1', 'fournisseur' => 'anthropic', 'modele' => 'm', 'tokens_entree' => 50, 'tokens_sortie' => 10, 'tentative' => 2]);
    ConsommationIa::create(['groupe_id' => null, 'skill' => 'test_connectivite', 'fournisseur' => 'gemini', 'modele' => 'm', 'tokens_entree' => 10, 'tokens_sortie' => 3, 'tentative' => 1]);

    $agregat = ConsommationIa::agregat();

    expect($agregat['tokens_entree'])->toBe(160)
        ->and($agregat['tokens_sortie'])->toBe(33)
        ->and($agregat['appels'])->toBe(3)
        ->and($agregat['appels_retries'])->toBe(1) // une seule ligne à tentative > 1
        ->and($agregat['nb_quetes_mesurees'])->toBe(2)
        ->and($agregat['moyenne_par_quete']['appels'])->toBe(1.5)
        ->and($agregat['moyenne_par_quete']['tokens_entree'])->toBe(80.0)
        ->and($agregat['depuis'])->not->toBeNull();
});

it('ConsommationIa::agregat() ne divise jamais par zéro sans quête en base', function () {
    ConsommationIa::create(['groupe_id' => null, 'skill' => 's', 'fournisseur' => 'anthropic', 'modele' => 'm', 'tokens_entree' => 40, 'tokens_sortie' => 5, 'tentative' => 1]);

    $agregat = ConsommationIa::agregat();

    expect($agregat['nb_quetes_mesurees'])->toBe(0)
        ->and($agregat['moyenne_par_quete']['appels'])->toBe(1.0) // dénominateur plancher à 1
        ->and($agregat['moyenne_par_quete']['tokens_entree'])->toBe(40.0);
});

it('best-effort absolu : une écriture de télémétrie en échec ne casse jamais l\'appel LLM', function () {
    Schema::drop('consommation_ia'); // simule une télémétrie indisponible (table absente)

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'toujours en jeu']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    app(TraceurConsommation::class)->pourGroupe(1, 'peu-importe');
    $texte = app()->make(AnthropicClient::class)->genererTexte('s', [['role' => 'user', 'content' => 'u']]);

    // L'appel LLM aboutit normalement malgré la télémétrie hors service.
    expect($texte)->toBe('toujours en jeu');
});

it('GET /api/parametres expose consommation_ia (route publique, sans session)', function () {
    ConsommationIa::create(['groupe_id' => null, 'skill' => 's', 'fournisseur' => 'anthropic', 'modele' => 'm', 'tokens_entree' => 12, 'tokens_sortie' => 4, 'tentative' => 1]);

    $data = $this->getJson('/api/parametres')->assertOk()->json();

    expect($data['consommation_ia']['tokens_entree'])->toBe(12)
        ->and($data['consommation_ia']['tokens_sortie'])->toBe(4)
        ->and($data['consommation_ia']['appels'])->toBe(1)
        ->and($data['consommation_ia'])->toHaveKeys(['appels_retries', 'nb_quetes_mesurees', 'moyenne_par_quete', 'depuis']);
});
