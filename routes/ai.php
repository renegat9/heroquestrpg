<?php

declare(strict_types=1);

use App\Mcp\ServeurConstructeur;
use Laravel\Mcp\Facades\Mcp;

/*
 * Serveurs MCP (laravel/mcp). Un seul, pour le constructeur de campagnes (MODE CLASSIQUE, lot P1,
 * `docs/contrat-api.md` « Serveur MCP du constructeur ») : transport STDIO seulement, lancé par
 * `php artisan mcp:start constructeur`. Pas de route HTTP : aucune authentification à écrire.
 */
Mcp::local('constructeur', ServeurConstructeur::class);
