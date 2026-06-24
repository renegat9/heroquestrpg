<?php

namespace App\Providers;

use App\Agent\AnthropicClient;
use App\Agent\ClientLLM;
use App\Agent\GeminiClient;
use App\Agent\Memoire\Embeddings;
use App\Agent\Memoire\EmbeddingsNuls;
use App\Agent\Memoire\EmbeddingsVoyage;
use App\Engine\Des\LanceurAleatoire;
use App\Engine\Des\LanceurDes;
use Illuminate\Support\ServiceProvider;

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
        $this->app->bind(Embeddings::class, function () {
            return config('services.voyage.api_key')
                ? new EmbeddingsVoyage
                : new EmbeddingsNuls;
        });

        // Fournisseur LLM du MJ IA (histoire + narration) — choix GLOBAL via
        // LLM_PROVIDER : tous les skills reçoivent le client choisi. Gemini
        // seulement s'il est demandé ET que la clé est présente, sinon repli
        // Anthropic (jouabilité préservée ; le TTS reste Gemini par ailleurs).
        $this->app->bind(ClientLLM::class, function () {
            return config('services.llm.provider') === 'gemini' && filled(config('services.gemini.api_key'))
                ? $this->app->make(GeminiClient::class)
                : $this->app->make(AnthropicClient::class);
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
