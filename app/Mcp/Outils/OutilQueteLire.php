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

#[Name('quete_lire')]
#[Title('Lire une quête')]
#[Description(
    'Lit le document complet d\'UNE quête d\'une campagne : salles ouvertes, portes, blocs, mobilier, monstres, pièges, '
    .'terrain, départ, monstre errant, textes et récompense. Une quête à la fois.'
)]
#[IsReadOnly]
final class OutilQueteLire extends Tool
{
    public function handle(Request $request, CampagnesConstruites $campagnes): Response|ResponseFactory
    {
        $entree = $request->validate([
            'campagne' => ['required', 'string'],
            'quete' => ['required', 'string'],
        ]);

        try {
            return Response::structured($campagnes->lireQuete($entree['campagne'], $entree['quete']));
        } catch (ErreurConstructeur $erreur) {
            return Response::error($erreur->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'campagne' => $schema->string()
                ->description('Le slug de la campagne.')
                ->required(),
            'quete' => $schema->string()
                ->description('Le slug de la quête, tel que campagne_lire le liste.')
                ->required(),
        ];
    }
}
