<?php

declare(strict_types=1);

namespace App\Mcp\Outils;

use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\RefusCampagne;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('importer')]
#[Title('Importer une campagne')]
#[Description(
    'Importe un document de campagne, exporté ou écrit à la main. Si « empreinte » est présente, elle est vérifiée contre '
    .'le document ; le document est ensuite validé et enregistré. Un refus n\'écrit rien et est rendu avec ses chemins.'
)]
#[IsIdempotent]
final class OutilImporter extends Tool
{
    public function handle(Request $request, CampagnesConstruites $campagnes): Response|ResponseFactory
    {
        $entree = $request->validate(['document' => ['required', 'array']]);

        try {
            $campagne = $campagnes->importer($entree['document']);

            return Response::structured([
                'enregistree' => true,
                'campagne' => $campagnes->resume($campagne),
            ]);
        } catch (RefusCampagne $refus) {
            return Response::structured([
                'enregistree' => false,
                'refus' => $refus->refus,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document' => $schema->object()
                ->description('Le fichier de la campagne : le document, avec ou sans « empreinte ».')
                ->required(),
        ];
    }
}
