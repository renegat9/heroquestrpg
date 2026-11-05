<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
    }
}
