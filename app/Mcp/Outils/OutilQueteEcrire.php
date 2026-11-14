<?php

declare(strict_types=1);

namespace App\Mcp\Outils;

use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\ErreurConstructeur;
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

#[Name('quete_ecrire')]
#[Title('Écrire une quête')]
#[Description(
    'Remplace dans une campagne la quête de même slug, ou l\'ajoute (à rang, sinon à la fin), puis enregistre la campagne '
    .'ENTIÈRE, revalidée. Un refus n\'écrit rien et est rendu avec son chemin (quetes.N.…). rang : 1 = première quête ; '
    .'avec un rang, une quête existante est déplacée à ce rang.'
)]
#[IsIdempotent]
final class OutilQueteEcrire extends Tool
{
    public function handle(Request $request, CampagnesConstruites $campagnes): Response|ResponseFactory
    {
        $entree = $request->validate([
            'campagne' => ['required', 'string'],
            'quete' => ['required', 'array'],
            'rang' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $campagne = $campagnes->ecrireQuete(
                $entree['campagne'],
                $entree['quete'],
                isset($entree['rang']) ? (int) $entree['rang'] : null,
            );

            return Response::structured([
                'enregistree' => true,
                'campagne' => $campagnes->resume($campagne),
            ]);
        } catch (RefusCampagne $refus) {
            return Response::structured([
                'enregistree' => false,
                'refus' => $refus->refus,
            ]);
        } catch (ErreurConstructeur $erreur) {
            return Response::error($erreur->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'campagne' => $schema->string()
                ->description('Le slug de la campagne qui reçoit la quête.')
                ->required(),
            'quete' => $schema->object()
                ->description('Le document de la quête (l\'objet « quete » du format 1).')
                ->required(),
            'rang' => $schema->integer()
                ->description('Le rang de la quête, 1 pour la première. Facultatif : sans lui, une quête nouvelle va à la fin et une existante garde sa place.')
                ->min(1),
        ];
    }
}
