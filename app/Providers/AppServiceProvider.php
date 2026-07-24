<?php

namespace App\Providers;

use App\Agent\AnthropicClient;
use App\Agent\ClientLLM;
use App\Agent\ClientLLMAvecRepli;
use App\Agent\GeminiClient;
use App\Agent\Memoire\Embeddings;
use App\Agent\Memoire\EmbeddingsNuls;
use App\Agent\Memoire\EmbeddingsVoyage;
use App\Engine\Des\LanceurAleatoire;
use App\Engine\Des\LanceurDes;
use App\Models\Parametre;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Hasard du moteur : aléatoire en prod, remplaçable par le lanceur
        // déterministe en test (le moteur fait autorité sur toute mécanique).
        $this->app->bind(LanceurDes::class, LanceurAleatoire::class);

        // Embeddings de la bible RAG (doc 11 §6) : Voyage AI dès que la clé
        // est renseignée, sinon repli lexical factice (dev sans clé).
        // ⚠ Les deux n'ont pas la même dimension : passer de l'un à l'autre
        // impose de recréer la collection Qdrant (bible vide en dev → ok).
        // Fournisseur d'embeddings volontairement NON pilotable depuis le
        // panneau Réglages (contrairement à ClientLLM ci-dessous) : changer
        // de fournisseur en cours de campagne casserait la collection Qdrant
        // existante (dimensions vectorielles différentes).
        $this->app->bind(Embeddings::class, function () {
            return config('services.voyage.api_key')
                ? new EmbeddingsVoyage
                : new EmbeddingsNuls;
        });

        // Fournisseur LLM du MJ IA (histoire + narration) — choix GLOBAL,
        // piloté par le panneau Réglages (Parametre::actuel()->llm_provider,
        // persisté en base) avec repli sur LLM_PROVIDER (.env) si aucune
        // surcharge n'est enregistrée : tous les skills reçoivent le client
        // choisi. `bind()` (pas `singleton()`) : réévalué à CHAQUE résolution,
        // donc à CHAQUE job — un changement de réglage s'applique sans
        // redémarrer aucun conteneur.
        //
        // Repli automatique INTER-FOURNISSEURS À L'EXÉCUTION (pas seulement à
        // la résolution du binding) : si l'appel au fournisseur PRINCIPAL
        // échoue vraiment (panne API, clé révoquée, modèle retiré…), une
        // seule retentative avec l'AUTRE fournisseur avant d'abandonner à
        // l'IA — voir {@see \App\Agent\ClientLLMAvecRepli}.
        $this->app->bind(ClientLLM::class, function () {
            try {
                $parametres = Parametre::actuel();
            } catch (Throwable) {
                // Table absente (migration pas encore jouée) ou base
                // indisponible : repli intégral sur le comportement .env.
                $parametres = null;
            }

            $anthropicDispo = filled(config('services.anthropic.api_key'));
            $geminiDispo = filled(config('services.gemini.api_key'));
            $providerVoulu = $parametres?->llm_provider ?: config('services.llm.provider');

            $principalNom = ($providerVoulu === 'gemini' && $geminiDispo) ? 'gemini' : 'anthropic';
            $principal = $principalNom === 'gemini'
                ? $this->app->make(GeminiClient::class, ['model' => $parametres?->modele_gemini ?: null])
                : $this->app->make(AnthropicClient::class, ['model' => $parametres?->modele_anthropic ?: null]);

            // Repli croisé : l'AUTRE fournisseur, s'il a une clé — construit avec sa propre surcharge.
            $secoursNom = null;
            $secours = null;
            if ($principalNom === 'anthropic' && $geminiDispo) {
                $secoursNom = 'gemini';
                $secours = $this->app->make(GeminiClient::class, ['model' => $parametres?->modele_gemini ?: null]);
            } elseif ($principalNom === 'gemini' && $anthropicDispo) {
                $secoursNom = 'anthropic';
                $secours = $this->app->make(AnthropicClient::class, ['model' => $parametres?->modele_anthropic ?: null]);
            }

            return new ClientLLMAvecRepli($principal, $principalNom, $secours, $secoursNom);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
