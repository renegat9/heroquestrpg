<?php

declare(strict_types=1);

namespace App\Mcp\Outils;

use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\ErreurConstructeur;
use App\Partie\Classique\OperationsDeQuete;
use App\Partie\Classique\RefusCampagne;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

/**
 * Modifie UNE quête par opérations ciblées (MODE CLASSIQUE, lot P2 — `docs/plan-mode-classique.md` §8 bis). Le document de la
 * quête n'est pas renvoyé en entier : l'IA désigne ce qu'elle change, par un chemin relatif à la quête.
 *
 * Aucune règle ici : l'outil prend l'entrée et rend la réponse. Les opérations sont celles de
 * {@see OperationsDeQuete}, appliquées et enregistrées par {@see CampagnesConstruites::modifierQuete()} — la campagne
 * entière est revalidée. Une opération fautive, un chemin inconnu ou un refus de validation n'écrivent RIEN.
 */
#[Name('quete_modifier')]
#[Title('Modifier une quête')]
#[Description(
    'Modifie UNE quête par opérations ordonnées, sans renvoyer tout son document : `ajouter` (ajoute `valeur` à la fin d\'une '
    .'liste), `retirer` (retire l\'élément d\'index donné, par exemple `monstres.2`), `remplacer` (remplace la valeur désignée). '
    .'Les opérations s\'appliquent dans l\'ordre, sur une copie. La campagne est ensuite enregistrée ENTIÈRE, revalidée : une '
    .'opération fautive, ou un refus de validation, n\'écrit RIEN.'
)]
final class OutilQueteModifier extends Tool
{
    public function handle(Request $request, CampagnesConstruites $campagnes): Response|ResponseFactory
    {
        $entree = $request->validate([
            'campagne' => ['required', 'string'],
            'quete' => ['required', 'string'],
            'operations' => ['required', 'array', 'min:1'],
        ]);

        try {
            // L'outil ne porte ni la lecture, ni les opérations, ni la validation : tout est au service.
            $campagne = $campagnes->modifierQuete($entree['campagne'], $entree['quete'], $entree['operations']);

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
                ->description('Le slug de la campagne qui contient la quête.')
                ->required(),
            'quete' => $schema->string()
                ->description('Le slug de la quête à modifier, tel que campagne_lire le liste.')
                ->required(),
            'operations' => $schema->array()
                ->description(
                    'Les opérations, dans l\'ordre. Chacune est {op, chemin, valeur}. `op` : ajouter (valeur à la fin de la liste '
                    .'désignée par chemin), retirer (l\'élément désigné par chemin, par exemple « monstres.2 » ; pas de valeur), '
                    .'remplacer (la valeur désignée par chemin). `chemin` : pointé, relatif à la quête (« monstres », « monstres.2 », '
                    .'« titre », « fin.libelle », « depart »). Pour remplacer par « rien » : valeur null (« sortie », « recompense »).'
                )
                ->items($schema->object([
                    'op' => $schema->string()
                        ->description('ajouter, retirer ou remplacer.')
                        ->enum(OperationsDeQuete::OPERATIONS)
                        ->required(),
                    'chemin' => $schema->string()
                        ->description('Le chemin pointé, relatif à la quête. Ne commence pas par « slug ».')
                        ->required(),
                    'valeur' => $schema->union(['string', 'integer', 'number', 'boolean', 'object', 'array', 'null'])
                        ->description('La valeur posée. Exigée par ajouter et remplacer ; interdite par retirer.'),
                ])->withoutAdditionalProperties())
                ->min(1)
                ->required(),
        ];
    }
}
