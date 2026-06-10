<?php

namespace App\Providers;

use App\Agent\Memoire\Embeddings;
use App\Agent\Memoire\EmbeddingsNuls;
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

        // Embeddings de la bible RAG : factice tant que le fournisseur réel
        // (API distante ou modèle local) n'est pas choisi — doc 11 §14.2.
        $this->app->bind(Embeddings::class, EmbeddingsNuls::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
