<?php

declare(strict_types=1);

use App\Agent\StatutIA;
use App\Models\Parametre;

/**
 * Réglages globaux du serveur (GET/PUT /api/parametres) : route PUBLIQUE,
 * comme /api/guide — doit fonctionner depuis /narrateur avant même
 * l'ouverture d'une table (docs/contrat-api.md §Paramètres globaux).
 */
beforeEach(function () {
    // Baseline déterministe pour les 3 clés serveur, indépendante de ce que
    // le .env de dev de ce dépôt configure réellement (même contrainte que
    // les autres tests Feature de ce projet, ex. GeminiClientTest).
    config([
        'services.anthropic.api_key' => null,
        'services.gemini.api_key' => null,
        'services.voyage.api_key' => null,
    ]);
});

it('accessible sans session de table ni compte joueur', function () {
    $this->getJson('/api/parametres')->assertOk();
    $this->putJson('/api/parametres', [])->assertOk();
});

it('crée la ligne de réglages par défaut au premier accès, sans changer le comportement actuel', function () {
    expect(Parametre::query()->count())->toBe(0);

    $data = $this->getJson('/api/parametres')->assertOk()->json();

    expect(Parametre::query()->count())->toBe(1)
        ->and($data['llm_provider'])->toBe(config('services.llm.provider'))
        ->and($data['fournisseurs_disponibles'])->toBe([])
        ->and($data['modele_anthropic'])->toBeNull()
        ->and($data['modele_gemini'])->toBeNull()
        ->and($data['rag_actif'])->toBeTrue()
        ->and($data['voix_dynamique_active'])->toBeTrue()
        ->and($data['images_actif'])->toBeTrue()
        ->and($data['narration_voix'])->toBeNull()
        ->and($data['narration_voix_defaut'])->toBe('Iapetus')
        ->and($data['narration_voix_options'])->toBe(['Puck', 'Fenrir', 'Charon', 'Orus', 'Iapetus'])
        ->and($data['bible_semantique'])->toBe('lexical')
        ->and($data['statut_ia'])->toBe(['etat' => 'inconnu'])
        ->and($data['rencontres'])->toBe($data['rencontres_defaut']);
});

it('met à jour l\'intégralité des réglages en un seul appel', function () {
    config(['services.gemini.api_key' => 'cle-test']);

    $this->putJson('/api/parametres', [
        'llm_provider' => 'gemini',
        'modele_gemini' => 'gemini-3-pro',
        'modele_anthropic' => 'claude-test',
        'rag_actif' => false,
        'voix_dynamique_active' => false,
        'images_actif' => false,
        'narration_voix' => 'Puck',
        'rencontres_forts_par_quete' => 2,
        'rencontres_forts_escalade_arc' => 1,
        'rencontres_seuil_cout_fort' => 4,
        'rencontres_boss_pv_adaptatif' => false,
        'rencontres_taille_reference' => 5,
    ])->assertOk()->assertJson([
        'llm_provider' => 'gemini',
        'modele_gemini' => 'gemini-3-pro',
        'modele_anthropic' => 'claude-test',
        'rag_actif' => false,
        'voix_dynamique_active' => false,
        'images_actif' => false,
        'narration_voix' => 'Puck',
        'rencontres' => [
            'forts_par_quete' => 2,
            'forts_escalade_arc' => 1,
            'seuil_cout_fort' => 4,
            'boss_pv_adaptatif' => false,
            'taille_reference' => 5,
        ],
    ]);

    $p = Parametre::actuel()->fresh();
    expect($p->rencontres_boss_pv_adaptatif)->toBeFalse()
        ->and($p->rencontres_taille_reference)->toBe(5);
});

it('met à jour un sous-ensemble de réglages sans toucher aux autres (PUT partiel)', function () {
    config(['services.gemini.api_key' => 'cle-test']);

    $this->putJson('/api/parametres', ['images_actif' => false])
        ->assertOk()
        ->assertJsonPath('images_actif', false)
        ->assertJsonPath('rag_actif', true); // inchangé (défaut)

    $this->putJson('/api/parametres', ['llm_provider' => 'gemini', 'modele_gemini' => 'gemini-3-pro'])
        ->assertOk()
        ->assertJsonPath('llm_provider', 'gemini')
        ->assertJsonPath('modele_gemini', 'gemini-3-pro')
        ->assertJsonPath('images_actif', false); // toujours désactivé par le PUT précédent

    $parametres = Parametre::actuel()->fresh();
    expect($parametres->images_actif)->toBeFalse()
        ->and($parametres->llm_provider)->toBe('gemini')
        ->and($parametres->modele_gemini)->toBe('gemini-3-pro');
});

it('remet un champ modèle au défaut .env via une chaîne vide', function () {
    config(['services.gemini.api_key' => 'cle-test']);
    Parametre::actuel()->update(['modele_gemini' => 'gemini-3-pro']);

    $this->putJson('/api/parametres', ['modele_gemini' => ''])
        ->assertOk()
        ->assertJsonPath('modele_gemini', null);

    expect(Parametre::actuel()->fresh()->modele_gemini)->toBeNull();
});

it('rejette un fournisseur sans clé API serveur (422)', function () {
    $this->putJson('/api/parametres', ['llm_provider' => 'gemini'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('llm_provider');
});

it('rejette une énumération ou un type invalide (422)', function () {
    $this->putJson('/api/parametres', ['llm_provider' => 'openai'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('llm_provider');

    $this->putJson('/api/parametres', ['rag_actif' => 'oui'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rag_actif');

    $this->putJson('/api/parametres', ['rencontres_forts_par_quete' => -1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rencontres_forts_par_quete');
});

it('expose la surcharge des rencontres dans le sous-objet effectif', function () {
    Parametre::actuel()->update(['rencontres_forts_par_quete' => 3]);

    $data = $this->getJson('/api/parametres')->assertOk()->json();

    expect($data['rencontres']['forts_par_quete'])->toBe(3)
        ->and($data['rencontres_defaut']['forts_par_quete'])->toBe((int) config('jeu.rencontres.forts_par_quete', 1));
});

it('expose le statut IA courant du cache (StatutIA)', function () {
    StatutIA::signalerRepli('anthropic', 'gemini', 'panne réseau');

    $data = $this->getJson('/api/parametres')->assertOk()->json();

    expect($data['statut_ia']['etat'])->toBe('repli')
        ->and($data['statut_ia']['fournisseur'])->toBe('gemini')
        ->and($data['statut_ia']['depuis'])->toBe('anthropic');
});
