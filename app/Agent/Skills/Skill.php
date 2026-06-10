<?php

declare(strict_types=1);

namespace App\Agent\Skills;

use App\Agent\AnthropicClient;
use App\Agent\Exceptions\AppelLlmException;
use App\Agent\Exceptions\SortieInvalideException;
use App\Agent\ValidationSortie;
use Illuminate\Support\Facades\Log;

/**
 * Skill du MJ IA — UN SEUL agent MJ, un skill par tâche (doc 08 §6.3).
 *
 * Chaque skill concret définit :
 *  - SCHEMA      : le schéma JSON de sortie (constante de classe) ;
 *  - prompt()    : l'assemblage du prompt depuis le contexte
 *                  (état vivant + extraits de bible + consignes) ;
 *  - validerMetier() : les vérifications au-delà de la forme
 *                  (références catalogue, budget, options exécutables) ;
 *  - repli()     : un contenu générique codé en dur si l'IA échoue
 *                  (null = pas de repli possible, l'exception remonte).
 *
 * generer() orchestre : appel LLM (tool use forcé) → validation schéma +
 * métier → retry (max 2, en réinjectant les erreurs) → repli (doc 08 §5).
 */
abstract class Skill
{
    public const MAX_RETRIES = 2;

    /**
     * Schéma JSON de sortie — chaque skill concret le redéfinit.
     *
     * @var array<string, mixed>
     */
    public const SCHEMA = [];

    public function __construct(
        protected readonly AnthropicClient $client,
        protected readonly ValidationSortie $validation,
    ) {}

    /** Nom de l'outil forcé côté API (snake_case). */
    abstract public function nomOutil(): string;

    /** Description de l'outil (guide le modèle). */
    abstract public function descriptionOutil(): string;

    /** @return array<string, mixed> schéma JSON de sortie du skill (constante SCHEMA) */
    public function schema(): array
    {
        return static::SCHEMA;
    }

    /**
     * Assemble le prompt depuis le contexte fourni par ContexteAssembleur.
     *
     * @param  array<string, mixed>  $contexte
     * @return array{system: string, user: string}
     */
    abstract protected function prompt(array $contexte): array;

    /**
     * Validation métier (références catalogue, contraintes moteur).
     *
     * @param  array<string, mixed>  $sortie
     * @param  array<string, mixed>  $contexte
     * @return list<string> erreurs (vide = valide)
     */
    abstract protected function validerMetier(array $sortie, array $contexte): array;

    /**
     * Contenu générique de repli, codé en dur (doc 08 §5).
     *
     * @param  array<string, mixed>  $contexte
     * @return array<string, mixed>|null null si aucun repli n'a de sens
     */
    protected function repli(array $contexte): ?array
    {
        return null;
    }

    /**
     * Génère une sortie validée, ou le repli, ou lève SortieInvalideException.
     *
     * @param  array<string, mixed>  $contexte
     * @return array<string, mixed>
     *
     * @throws SortieInvalideException
     * @throws AppelLlmException si l'API est injoignable ET qu'aucun repli n'existe
     */
    public function generer(array $contexte): array
    {
        $prompt = $this->prompt($contexte);
        $outil = [
            'name' => $this->nomOutil(),
            'description' => $this->descriptionOutil(),
            'input_schema' => $this->schema(),
        ];

        $messages = [['role' => 'user', 'content' => $prompt['user']]];
        $dernieresErreurs = [];

        for ($tentative = 0; $tentative <= self::MAX_RETRIES; $tentative++) {
            try {
                $sortie = $this->client->genererStructure($prompt['system'], $messages, $outil);
            } catch (AppelLlmException $e) {
                Log::warning("MJ IA [{$this->nomOutil()}] appel LLM échoué", ['erreur' => $e->getMessage()]);

                return $this->repli($contexte)
                    ?? throw $e;
            }

            $erreurs = [
                ...$this->validation->validerSchema($sortie, $this->schema()),
                ...$this->validerMetier($sortie, $contexte),
            ];

            if ($erreurs === []) {
                return $sortie;
            }

            $dernieresErreurs = $erreurs;
            Log::warning("MJ IA [{$this->nomOutil()}] sortie rejetée (tentative ".($tentative + 1).')', [
                'erreurs' => $erreurs,
            ]);

            // Retry : on réinjecte la sortie fautive et les erreurs pour correction.
            $messages[] = ['role' => 'assistant', 'content' => [[
                'type' => 'tool_use',
                'id' => 'retry_'.$tentative,
                'name' => $this->nomOutil(),
                'input' => $sortie,
            ]]];
            $messages[] = ['role' => 'user', 'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => 'retry_'.$tentative,
                'is_error' => true,
                'content' => "Sortie rejetée par le moteur, corrige et rappelle l'outil :\n- "
                    .implode("\n- ", $erreurs),
            ]]];
        }

        return $this->repli($contexte)
            ?? throw new SortieInvalideException(
                "Sortie [{$this->nomOutil()}] invalide après ".(self::MAX_RETRIES + 1).' tentatives.',
                $dernieresErreurs,
            );
    }

    /**
     * Bloc de consignes communes à tous les skills (garde-fous, doc 08).
     *
     * @param  array<string, mixed>  $contexte
     */
    protected function consignesCommunes(array $contexte): string
    {
        $ton = $contexte['groupe']['ton'] ?? null;
        $tonTexte = is_array($ton) ? json_encode($ton, JSON_UNESCAPED_UNICODE) : ($ton ?: 'héroïque classique');

        return <<<TXT
        Tu es le Maître du Jeu (MJ) d'un dungeon crawler fantasy inspiré de HeroQuest.
        Règles absolues :
        - Tu NARRES et tu PROPOSES, le moteur de jeu fait autorité : tu ne résous JAMAIS
          une mécanique (dés, PV, combat, jets) et tu n'inventes AUCUNE statistique.
        - Tout contenu mécanique (monstres, objets, sorts) provient du catalogue fourni :
          tu peux renommer et redécrire (habiller) sans jamais toucher aux effets.
        - Tu ne contredis jamais un fait établi de la bible d'univers. Fait manquant :
          reste vague plutôt que d'inventer.
        - Univers strictement fantasy ; reformule tout élément hors-genre.
        - Réponds en français.
        Ton de la table : {$tonTexte}.
        TXT;
    }

    /**
     * Sérialise le contexte assemblé en texte de prompt.
     *
     * @param  array<string, mixed>  $contexte
     * @param  list<string>  $sections  clés du contexte à inclure, dans l'ordre
     */
    protected function contexteEnTexte(array $contexte, array $sections): string
    {
        $titres = [
            'groupe' => 'GROUPE / CAMPAGNE',
            'etat_vivant' => 'ÉTAT VIVANT (exact, source moteur)',
            'squelette' => 'SQUELETTE DE CAMPAGNE (fil rouge, ne pas contredire)',
            'evenements_recents' => 'ÉVÉNEMENTS RÉCENTS (journal)',
            'bible' => "EXTRAITS DE LA BIBLE D'UNIVERS (faits établis)",
            'catalogue' => 'CATALOGUE DISPONIBLE (seules références autorisées)',
            'resultat_moteur' => 'RÉSULTAT DU MOTEUR (à mettre en récit, ne pas altérer)',
        ];

        $texte = '';
        foreach ($sections as $cle) {
            if (! isset($contexte[$cle]) || $contexte[$cle] === [] || $contexte[$cle] === null) {
                continue;
            }
            $titre = $titres[$cle] ?? mb_strtoupper($cle);
            $contenu = is_string($contexte[$cle])
                ? $contexte[$cle]
                : json_encode($contexte[$cle], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $texte .= "## {$titre}\n{$contenu}\n\n";
        }

        return rtrim($texte);
    }
}
