<?php

declare(strict_types=1);

use App\Mcp\ServeurConstructeur;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Tests\Feature\Classique\Mcp\TransportCapture;

/*
 * Le serveur lui-même (lot P1) : enregistré en stdio seulement, sans aucune route HTTP, et qui répond en JSON-RPC brut.
 * Le transport stdio réel (sortie standard propre) n'est pas testable ici : il est éprouvé à la main, hors Pest.
 */

/** Passe une requête JSON-RPC brute au serveur, et rend les messages qu'il envoie, décodés. */
function dialogueMcp(string ...$requetes): array
{
    $transport = new TransportCapture;
    $serveur = app()->make(ServeurConstructeur::class, ['transport' => $transport]);
    $serveur->start();
    foreach ($requetes as $requete) {
        $serveur->handle($requete);
    }

    return array_map(fn (string $message): array => json_decode($message, true, flags: JSON_THROW_ON_ERROR), $transport->messages);
}

it('est enregistré sous le nom « constructeur », en stdio seulement', function () {
    expect(Mcp::getLocalServer('constructeur'))->not->toBeNull()
        ->and(array_keys(Mcp::servers()))->toContain('constructeur');
});

it('n\'ouvre aucune route HTTP pour le serveur', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'mcp'));

    expect($routes)->toBeEmpty();
});

it('la commande mcp:start existe et refuse un nom de serveur inconnu', function () {
    $this->artisan('mcp:start', ['handle' => 'absent'])->assertExitCode(1);
});

it('porte un nom, une version et des instructions en français', function () {
    $contexte = app()->make(ServeurConstructeur::class, ['transport' => new TransportCapture])->createContext();

    expect($contexte->implementation->name)->toBe('Constructeur de campagnes HeroQuest')
        ->and($contexte->implementation->version)->toBe('0.1.0')
        ->and($contexte->instructions)->toContain('catalogues')
        ->and($contexte->instructions)->toContain('campagne_ecrire')
        ->and($contexte->instructions)->toContain('refusé');
});

it('répond à initialize par une réponse JSON-RPC valide', function () {
    [$reponse] = dialogueMcp(json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ]));

    expect($reponse['jsonrpc'])->toBe('2.0')
        ->and($reponse['id'])->toBe(1)
        ->and($reponse['result']['serverInfo']['name'])->toBe('Constructeur de campagnes HeroQuest');
});

it('liste les neuf outils par tools/list, en JSON-RPC brut', function () {
    [$reponse] = dialogueMcp(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']));

    expect(collect($reponse['result']['tools'])->pluck('name')->all())->toBe([
        'catalogues', 'campagnes', 'campagne_lire', 'quete_lire', 'campagne_ecrire',
        'quete_ecrire', 'valider', 'exporter', 'importer',
    ]);
});

it('appelle un outil par tools/call et rend son contenu', function () {
    [$reponse] = dialogueMcp(json_encode([
        'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
        'params' => ['name' => 'valider', 'arguments' => ['document' => ['format' => 1]]],
    ]));

    expect($reponse['result']['isError'])->toBeFalse()
        ->and(array_keys($reponse['result']['structuredContent']))->toBe(['refus'])
        ->and($reponse['result']['structuredContent']['refus'])->not->toBeEmpty();
});
