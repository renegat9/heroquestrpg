<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Random\Engine\Mt19937;
use Random\Randomizer;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Le hasard « métier » servi par le conteneur (le tirage des boîtes d'un bestiaire automatique,
        // `BestiaireGroupe::tirer()`) suit une GRAINE fixe en test : un groupe créé par l'API reçoit les
        // mêmes boîtes à chaque passage.
        $this->app->instance(Randomizer::class, new Randomizer(new Mt19937(2026)));

        // AUCUNE REQUÊTE RÉELLE (2026-11-20) : un test qui oublie `Http::fake()` partait sur Anthropic, Gemini ou
        // Voyage avec les vraies clés du .env (voir phpunit.xml). Toute requête non fakée lève désormais une
        // exception au lieu de sortir en silence.
        Http::preventStrayRequests();
    }
}
