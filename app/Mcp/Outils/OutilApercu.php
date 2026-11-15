<?php

declare(strict_types=1);

namespace App\Mcp\Outils;

use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\CarteRefusee;
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

#[Name('apercu')]
#[Title('Aperçu d\'une carte')]
#[Description(
    'Rend en TEXTE la carte d\'UNE quête : une ligne par rangée de cases (`.` sol, `#` roche, `+` porte, `<` entrée, `M` monstre…), '
    .'puis une légende qui donne le slug et la case de chaque élément. C\'est la carte que le moteur jouera, produite par le même '
    .'producteur. Lecture seule : à utiliser après une écriture, pour relire ce qu\'on vient de poser.'
)]
#[IsReadOnly]
final class OutilApercu extends Tool
{
    public function handle(Request $request, CampagnesConstruites $campagnes, CarteConstruite $carte): Response|ResponseFactory
    {
        $entree = $request->validate([
            'campagne' => ['required', 'string'],
            'quete' => ['required', 'string'],
        ]);

        try {
            $quete = $campagnes->lireQuete($entree['campagne'], $entree['quete']);
            $campagne = $campagnes->lire($entree['campagne']) ?? throw ErreurConstructeur::campagneInconnue($entree['campagne']);

            return Response::text($carte->apercu($campagne, $quete));
        } catch (ErreurConstructeur $erreur) {
            return Response::error($erreur->getMessage());
        } catch (CarteRefusee $refus) {
            // Une campagne enregistrée avant un contrôle ajouté depuis peut ne plus passer la validation : pas de carte à rendre.
            return Response::error($refus->getMessage());
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
