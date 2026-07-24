<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\AnthropicClient;
use App\Agent\Audio\TtsGemini;
use App\Agent\Exceptions\AppelLlmException;
use App\Agent\GeminiClient;
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

    /**
     * Phrase d'exemple FIXE du test d'écoute d'une voix de narrateur : permet
     * le cache par voix (public/audio/narration/test/{voix}.wav) — réécouter
     * la même voix ne re-dépense pas le quota Gemini TTS (100 req/jour).
     */
    private const TEXTE_ECHANTILLON = 'Les torches vacillent, héros — voici la voix qui guidera votre aventure.';

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
     * POST /api/parametres/test {fournisseur, modele?} — test de connectivité
     * RÉEL : un mini-appel LLM synchrone vers le fournisseur PRÉCIS demandé
     * (jamais via le décorateur de repli — on veut savoir si LUI répond),
     * avec le modèle du formulaire (même non enregistré : c'est justement ce
     * qu'on veut valider avant d'enregistrer), sinon surcharge → défaut .env.
     * Timeout court (15 s) pour un bouton réactif. Ne touche PAS StatutIA :
     * le bandeau reflète les appels du JEU, pas les tests manuels.
     */
    public function tester(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'fournisseur' => ['required', Rule::in(['anthropic', 'gemini'])],
            'modele' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $fournisseur = $donnees['fournisseur'];
        $cle = $fournisseur === 'gemini'
            ? config('services.gemini.api_key')
            : config('services.anthropic.api_key');

        if (blank($cle)) {
            throw ValidationException::withMessages([
                'fournisseur' => "Aucune clé API serveur configurée pour « {$fournisseur} ».",
            ]);
        }

        $surcharge = Parametre::actuel();
        $modele = ($donnees['modele'] ?? null)
            ?: ($fournisseur === 'gemini' ? $surcharge->modele_gemini : $surcharge->modele_anthropic)
            ?: null;

        $client = $fournisseur === 'gemini'
            ? app()->make(GeminiClient::class, ['model' => $modele, 'timeout' => 15])
            : app()->make(AnthropicClient::class, ['model' => $modele, 'timeout' => 15]);

        $depart = microtime(true);

        try {
            $texte = $client->genererTexte(
                'Tu es un test de connectivité. Réponds uniquement « OK ».',
                [['role' => 'user', 'content' => 'Réponds uniquement « OK ».']],
            );

            return response()->json([
                'ok' => true,
                'fournisseur' => $fournisseur,
                'modele' => $client->modeleParDefaut(),
                'duree_ms' => (int) round((microtime(true) - $depart) * 1000),
                'extrait' => mb_substr(trim($texte), 0, 60),
            ]);
        } catch (AppelLlmException $e) {
            return response()->json([
                'ok' => false,
                'fournisseur' => $fournisseur,
                'modele' => $client->modeleParDefaut(),
                'duree_ms' => (int) round((microtime(true) - $depart) * 1000),
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/parametres/test-voix {voix?} — synthétise la phrase d'exemple
     * avec la voix Gemini demandée (celle du formulaire, même non enregistrée),
     * sinon surcharge → défaut config. Mise en cache par voix : réécouter est
     * gratuit. Réponse : {ok: true, voix, url} à jouer côté panneau, ou
     * {ok: false, voix, erreur}. 422 si GEMINI_API_KEY absente. (La voix du
     * NAVIGATEUR, elle, se teste côté client — aucun serveur impliqué.)
     */
    public function testerVoix(Request $request, TtsGemini $tts): JsonResponse
    {
        $donnees = $request->validate([
            'voix' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        if (! $tts->estConfigure()) {
            throw ValidationException::withMessages([
                'voix' => 'GEMINI_API_KEY absente : pas de synthèse — la table lit alors le texte via la voix du navigateur.',
            ]);
        }

        $voix = ($donnees['voix'] ?? null)
            ?: (Parametre::actuel()->narration_voix ?: (string) config('narration.voix.voix', 'Iapetus'));

        // Nom de voix → nom de fichier SÛR (jamais de traversée de chemin).
        $slug = preg_replace('/[^A-Za-z0-9_-]/', '', $voix) ?: 'defaut';
        $rel = "audio/narration/test/{$slug}.wav";
        $absolu = public_path($rel);

        if (! is_file($absolu)) {
            $style = (string) config('narration.voix.style', 'une voix de conteur, maître de jeu');

            try {
                $wav = $tts->synthetiser(self::TEXTE_ECHANTILLON, $voix, $style);
            } catch (AppelLlmException $e) {
                return response()->json(['ok' => false, 'voix' => $voix, 'erreur' => $e->getMessage()]);
            }

            if (! is_dir(dirname($absolu))) {
                mkdir(dirname($absolu), 0775, true);
            }
            file_put_contents($absolu, $wav);
        }

        return response()->json(['ok' => true, 'voix' => $voix, 'url' => '/'.$rel]);
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
