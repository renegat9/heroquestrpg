<?php

declare(strict_types=1);

namespace App\Mcp\Outils;

use App\Partie\Classique\CampagnesConstruites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('valider')]
#[Title('Valider une campagne')]
#[Description(
    'Contrôle un document de campagne construite SANS rien enregistrer. Rend la liste des refus (chemin, code, message) ; '
    .'une liste vide signifie que le document est valide.'
)]
#[IsReadOnly]
final class OutilValider extends Tool
{
    public function handle(Request $request, CampagnesConstruites $campagnes): Response|ResponseFactory
    {
        $entree = $request->validate(['document' => ['required', 'array']]);

        return Response::structured(['refus' => $campagnes->valider($entree['document'])]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document' => $schema->object()
                ->description('Le document de la campagne à contrôler (l\'objet « campagne » du format 1).')
                ->required(),
        ];
    }
}
