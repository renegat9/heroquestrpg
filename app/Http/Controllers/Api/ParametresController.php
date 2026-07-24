<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\StatutIA;
use App\Http\Controllers\Controller;
use App\Models\Parametre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Réglages globaux du serveur (panneau « Réglages » — écran de Narrateur/
 * table, docs/contrat-api.md §Paramètres globaux) : fournisseur/modèle IA,
 * bascules RAG/voix dynamique/illustrations, voix du narrateur, équilibrage
 * des rencontres. Portée GLOBALE (pas par groupe/table), persistée dans
 * l'unique ligne `parametres` ({@see Parametre::actuel()}).
 *
 * Routes PUBLIQUES (aucune autorisation, comme GET /api/guide) : le bouton
 * Réglages doit fonctionner depuis /narrateur avant même l'ouverture d'une
 * table, donc avant qu'aucune session (table ou joueur) n'existe. Le modèle
 * de confiance de l'appli est déjà celui d'un LAN entre amis sans mot de
 * passe narrateur ; aucune clé API n'est jamais renvoyée, seulement leur
 * présence/absence et les choix de fournisseur/modèle.
 *
 * `PUT` accepte une mise à jour PARTIELLE : seuls les champs présents dans
 * le corps de la requête sont modifiés (le panneau actuel envoie tout d'un
 * bloc, mais le contrat n'impose pas de corps complet).
 */
class ParametresController extends Controller
{
    /**
     * Voix Gemini déjà utilisées ailleurs dans le projet (config/barks.php,
     * config/narration.php) — proposées dans le panneau, PAS une liste
     * inventée : `narration_voix` accepte librement toute autre voix Gemini
     * valide (pas de Rule::in strict côté validation).
     */
    private const VOIX_NARRATEUR = ['Puck', 'Fenrir', 'Charon', 'Orus', 'Iapetus'];

    /** GET /api/parametres */
    public function index(): JsonResponse
    {
        return response()->json($this->payload(Parametre::actuel()));
    }

    /** PUT /api/parametres — mise à jour PARTIELLE (voir doc de classe). */
    public function mettreAJour(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'llm_provider' => ['sometimes', 'nullable', Rule::in(['anthropic', 'gemini'])],
            'modele_anthropic' => ['sometimes', 'nullable', 'string', 'max:120'],
            'modele_gemini' => ['sometimes', 'nullable', 'string', 'max:120'],
            'rag_actif' => ['sometimes', 'boolean'],
            'voix_dynamique_active' => ['sometimes', 'boolean'],
            'images_actif' => ['sometimes', 'boolean'],
            'narration_voix' => ['sometimes', 'nullable', 'string', 'max:60'],
            'rencontres_forts_par_quete' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rencontres_forts_escalade_arc' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rencontres_seuil_cout_fort' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rencontres_boss_pv_adaptatif' => ['sometimes', 'nullable', 'boolean'],
            'rencontres_taille_reference' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        // Un fournisseur explicitement choisi doit avoir une clé API serveur —
        // `null` reste accepté (efface la surcharge, retour au choix .env).
        if (array_key_exists('llm_provider', $donnees) && $donnees['llm_provider'] !== null) {
            $cle = $donnees['llm_provider'] === 'gemini'
                ? config('services.gemini.api_key')
                : config('services.anthropic.api_key');

            if (blank($cle)) {
                throw ValidationException::withMessages([
                    'llm_provider' => "Aucune clé API serveur configurée pour « {$donnees['llm_provider']} ».",
                ]);
            }
        }

        // Chaîne vide = remise au défaut .env (même règle qu'un champ laissé
        // vide dans le formulaire) : convertie en NULL avant écriture, plutôt
        // que stockée comme surcharge littérale.
        foreach (['modele_anthropic', 'modele_gemini', 'narration_voix'] as $champ) {
            if (($donnees[$champ] ?? null) === '') {
                $donnees[$champ] = null;
            }
        }

        $parametres = Parametre::actuel();
        $parametres->update($donnees);

        return response()->json($this->payload($parametres->fresh()));
    }

    /**
     * Forme ParametresIA (docs/contrat-api.md) : sépare l'éditable (surcharge
     * actuelle, possiblement NULL) du calculé (valeur EFFECTIVE, défauts
     * .env, disponibilité des fournisseurs, statut du dernier appel IA).
     *
     * @return array<string, mixed>
     */
    private function payload(Parametre $p): array
    {
        $rencontresDefaut = [
            'forts_par_quete' => (int) config('jeu.rencontres.forts_par_quete', 1),
            'forts_escalade_arc' => (int) config('jeu.rencontres.forts_escalade_arc', 0),
            'seuil_cout_fort' => (int) config('jeu.rencontres.seuil_cout_fort', 3),
            'boss_pv_adaptatif' => (bool) config('jeu.rencontres.boss_pv_adaptatif', true),
            'taille_reference' => (int) config('jeu.rencontres.taille_reference', 4),
        ];

        return [
            // --- IA (serveur, globale, live au prochain job) ---
            'llm_provider' => $p->llm_provider ?: config('services.llm.provider'),
            'fournisseurs_disponibles' => array_values(array_filter([
                filled(config('services.anthropic.api_key')) ? 'anthropic' : null,
                filled(config('services.gemini.api_key')) ? 'gemini' : null,
            ])),
            'modele_anthropic' => $p->modele_anthropic,
            'modele_anthropic_defaut' => (string) config('services.anthropic.model', 'claude-sonnet-4-6'),
            'modele_gemini' => $p->modele_gemini,
            'modele_gemini_defaut' => (string) config('services.gemini.model_texte', 'gemini-3.1-flash-lite'),
            'rag_actif' => (bool) $p->rag_actif,
            'voix_dynamique_active' => (bool) $p->voix_dynamique_active,
            // Fournisseur d'embeddings : lecture seule (jamais éditable, voir AppServiceProvider).
            'bible_semantique' => filled(config('services.voyage.api_key')) ? 'voyage' : 'lexical',
            'statut_ia' => StatutIA::actuel(),

            // --- Illustrations (serveur, globale, runtime uniquement) ---
            'images_actif' => (bool) $p->images_actif,

            // --- Voix du narrateur (serveur, globale) ---
            'narration_voix' => $p->narration_voix,
            'narration_voix_defaut' => (string) config('narration.voix.voix', 'Iapetus'),
            'narration_voix_options' => self::VOIX_NARRATEUR,

            // --- Équilibrage des rencontres (serveur, globale, prochaine quête) ---
            'rencontres' => [
                'forts_par_quete' => $p->rencontres_forts_par_quete ?? $rencontresDefaut['forts_par_quete'],
                'forts_escalade_arc' => $p->rencontres_forts_escalade_arc ?? $rencontresDefaut['forts_escalade_arc'],
                'seuil_cout_fort' => $p->rencontres_seuil_cout_fort ?? $rencontresDefaut['seuil_cout_fort'],
                'boss_pv_adaptatif' => $p->rencontres_boss_pv_adaptatif ?? $rencontresDefaut['boss_pv_adaptatif'],
                'taille_reference' => $p->rencontres_taille_reference ?? $rencontresDefaut['taille_reference'],
            ],
            'rencontres_defaut' => $rencontresDefaut,
        ];
    }
}
