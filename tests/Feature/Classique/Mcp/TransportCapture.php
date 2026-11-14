<?php

declare(strict_types=1);

namespace Tests\Feature\Classique\Mcp;

use Closure;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * Un transport de TEST : il garde chaque message que le serveur envoie, au lieu de l'écrire sur la sortie standard. Permet
 * de lire la réponse JSON-RPC brute produite par `Server::handle()`, sans passer par le processus stdio.
 */
final class TransportCapture implements Transport
{
    /** @var list<string> */
    public array $messages = [];

    public function onReceive(Closure $handler): void
    {
        //
    }

    public function run()
    {
        return null;
    }

    public function send(string $message): void
    {
        $this->messages[] = $message;
    }

    public function stream(Closure $stream): void
    {
        $stream();
    }
}
