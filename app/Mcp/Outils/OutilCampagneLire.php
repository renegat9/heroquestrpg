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

#[Name('campagne_lire')]
#[Title('Lire une campagne')]
#[Description(
    'Lit une campagne construite : titre, auteur, mention, prérequis, plateau, et la liste de ses quêtes RÉSUMÉES '
    .'(rang, slug, titre). Le détail d\'une quête s\'obtient par quete_lire.'
)]
#[IsReadOnly]
final class OutilCampagneLire extends Tool
{
    public function handle(Request $request, CampagnesConstruites $campagnes): Response|ResponseFactory
    {
        $entree = $request->validate(['slug' => ['required', 'string']]);

        try {
            return Response::structured($campagnes->lireSommaire($entree['slug']));
        } catch (ErreurConstructeur $erreur) {
            return Response::error($erreur->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()
                ->description('Le slug de la campagne, tel que `campagnes` le liste.')
                ->required(),
        ];
    }
}
