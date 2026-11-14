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

#[Name('campagne_ecrire')]
#[Title('Écrire une campagne')]
#[Description(
    'Crée ou remplace une campagne construite ENTIÈRE à partir de son document (format 1). Le document est validé d\'abord : '
    .'un document refusé n\'écrit RIEN, et ses refus sont rendus, chacun avec son chemin. Pour une seule quête, préférer '
    .'quete_ecrire. Un fichier exporté (avec « empreinte ») passe par importer, pas par ici.'
)]
#[IsIdempotent]
final class OutilCampagneEcrire extends Tool
{
    public function handle(Request $request, CampagnesConstruites $campagnes): Response|ResponseFactory
    {
        $entree = $request->validate(['document' => ['required', 'array']]);

        try {
            $campagne = $campagnes->enregistrer($entree['document']);

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
                ->description('Le document de la campagne (l\'objet « campagne » du format 1, sans clé englobante).')
                ->required(),
        ];
    }
}
