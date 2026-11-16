<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CatalogueConstructeur;
use App\Partie\Classique\RefusCampagne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le constructeur de campagnes, lot K1 (`docs/plan-mode-classique.md` §8, `docs/contrat-api.md` « Constructeur de
 * campagnes (lot K1) »). Routes PUBLIQUES comme `/api/parametres`. Mince : toute la règle vit dans les services
 * (`CampagnesConstruites`, `ValidationCampagne`, `CatalogueConstructeur`), que le serveur MCP appellera aussi.
 */
class ConstructeurController extends Controller
{
    public function __construct(
        private readonly CampagnesConstruites $campagnes,
        private readonly CatalogueConstructeur $catalogue,
    ) {}

    /** GET /api/constructeur/catalogues */
    public function catalogues(): JsonResponse
    {
        return response()->json($this->catalogue->payload());
    }

    /** GET /api/constructeur/campagnes */
    public function campagnes(): JsonResponse
    {
        return response()->json(['campagnes' => $this->campagnes->lister()]);
    }

    /** GET /api/constructeur/campagnes/{slug} */
    public function lire(string $slug): JsonResponse
    {
        $document = $this->campagnes->lire($slug);

        return $document === null ? $this->introuvable($slug) : response()->json($document);
    }

    /** PUT /api/constructeur/campagnes/{slug} — crée ou remplace ; 422 `{message, refus[]}` si le document est refusé. */
    public function enregistrer(Request $request, string $slug): JsonResponse
    {
        try {
            $campagne = $this->campagnes->enregistrer(self::document($request), $slug);
        } catch (RefusCampagne $refusé) {
            return response()->json([
                'message' => 'Campagne refusée : '.count($refusé->refus).' refus.',
                'refus' => $refusé->refus,
            ], 422);
        }

        return response()->json(['campagne' => $this->campagnes->resume($campagne)]);
    }

    /** POST /api/constructeur/validation — rend les refus, sans rien enregistrer. */
    public function valider(Request $request): JsonResponse
    {
        return response()->json(['refus' => $this->campagnes->valider(self::document($request))]);
    }

    /**
     * Le document TEL QU'IL A ÉTÉ ENVOYÉ, lu dans le corps brut de la requête.
     *
     * ⚠ Jamais `$request->json()` : Laravel y a déjà fait passer `ConvertEmptyStringsToNull`, qui change tout texte vide
     * en `null`. Un texte d'ouverture vide — permis par le format — arrivait donc `null` et la validation le refusait
     * (« un texte est attendu ») ; une `mention` vide devenait `null` sans qu'on l'ait demandé. Trouvé en éprouvant
     * l'écran du constructeur le 2026-11-16 : le serveur MCP, qui ne passe pas par ce middleware, ne l'avait jamais vu.
     * Un document se valide tel que l'auteur l'a écrit.
     *
     * @return array<mixed>
     */
    private static function document(Request $request): array
    {
        $document = json_decode($request->getContent(), true);

        return is_array($document) ? $document : [];
    }

    /** GET /api/constructeur/campagnes/{slug}/export — le document plus `empreinte`, en fichier téléchargeable. */
    public function exporter(string $slug): JsonResponse
    {
        $fichier = $this->campagnes->exporter($slug);
        if ($fichier === null) {
            return $this->introuvable($slug);
        }

        return response()
            ->json($fichier)
            ->header('Content-Disposition', 'attachment; filename="'.$slug.'.json"');
    }

    private function introuvable(string $slug): JsonResponse
    {
        return response()->json(['message' => "Aucune campagne construite « $slug »."], 404);
    }
}
