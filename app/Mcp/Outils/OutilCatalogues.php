<?php

declare(strict_types=1);

namespace App\Mcp\Outils;

use App\Partie\Classique\CatalogueConstructeur;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('catalogues')]
#[Title('Catalogues du constructeur')]
#[Description(
    'Liste, par couche (mobilier, monstres, pièges, terrain), les slugs qu\'une quête peut poser ; les classes de héros '
    .'(prerequis_heros) ; et les vocabulaires fermés (rotations, cotes, types de porte, partages). À appeler en premier : '
    .'tout slug d\'un document vient de cette liste.'
)]
#[IsReadOnly]
final class OutilCatalogues extends Tool
{
    public function handle(CatalogueConstructeur $catalogue): Response|ResponseFactory
    {
        return Response::structured($catalogue->payload());
    }
}
