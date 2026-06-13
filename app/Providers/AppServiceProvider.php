<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
