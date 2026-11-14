<?php

declare(strict_types=1);

namespace App\Mcp\Outils;

use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\ErreurConstructeur;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('exporter')]
#[Title('Exporter une campagne')]
#[Description(
    'Rend le fichier d\'une campagne construite enregistrée : le document plus « empreinte » (sha256 du document canonisé). '
    .'Ce fichier se recharge par importer. Une campagne de grande taille peut être longue à lire : préférer campagne_lire et quete_lire pour le détail.'
)]
#[IsReadOnly]
final class OutilExporter extends Tool
{
    public function handle(Request $request, CampagnesConstruites $campagnes): Response|ResponseFactory
    {
        $entree = $request->validate(['slug' => ['required', 'string']]);

        try {
            $fichier = $campagnes->exporter($entree['slug']) ?? throw ErreurConstructeur::campagneInconnue($entree['slug']);

            return Response::structured($fichier);
        } catch (ErreurConstructeur $erreur) {
            return Response::error($erreur->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()
                ->description('Le slug de la campagne à exporter.')
                ->required(),
        ];
    }
}
