<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\ConsommationIa;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Télémétrie de consommation LLM du MJ IA (lot « télémétrie », René
 * 2026-08-18) — jusqu'ici AUCUN compteur de tokens n'existait ({@see
 * StatutIA} ne retient que le DERNIER essai, rien d'agrégé). Le projet réduit
 * drastiquement ses appels IA (~145/quête → 2, lot « récits pré-générés ») ;
 * sans ce traceur, impossible de VÉRIFIER le gain, seulement de l'espérer.
 *
 * Enregistré en SINGLETON (voir `AppServiceProvider::register()`) : l'état
 * (contexte annoncé + compteur de tentative) doit survivre entre l'appel qui
 * annonce le contexte et le/les appel(s) HTTP qui suivent, au sein d'un même
 * job — un worker de queue traite ses jobs SÉQUENTIELLEMENT (voir CLAUDE.md
 * sur `queue`/`queue-jeu`), donc aucune concurrence sur cet état partagé.
 *
 * Deux méthodes, deux moments :
 *  - {@see self::pourGroupe()} : annonce du CONTEXTE (quel groupe, quel
 *    skill) avant tout appel — typiquement depuis `Skill::generer()`, qui
 *    connaît `nomOutil()` et lit `groupe_id` dans le contexte assemblé (voir
 *    `ContexteAssembleur`).
 *  - {@see self::enregistrer()} : versement de l'usage d'UNE réponse HTTP
 *    RÉELLE, appelé depuis le point de passage unique de chaque client
 *    (`AnthropicClient::appeler()`, `GeminiClient::appeler()`). ⚠ Compter à
 *    ce niveau, et pas à celui de `Skill::generer()`, est ce qui rend
 *    visibles les RETRIES (`Skill::MAX_RETRIES` = 2, jusqu'à 3 appels
 *    facturés pour une seule sortie) et le FAILOVER CROISÉ
 *    (`ClientLLMAvecRepli`, qui rejoue l'appel complet chez l'AUTRE
 *    fournisseur) — deux mécanismes coûteux et jusqu'ici invisibles.
 *
 * Best-effort ABSOLU (même règle que `BibleQdrant`/`BibliothequeNarration`
 * face à leurs dépendances externes) : une écriture de télémétrie qui échoue
 * ne doit JAMAIS faire échouer un appel du MJ. Toujours enveloppée dans un
 * try/catch, jamais relancée.
 */
final class TraceurConsommation
{
    private ?int $groupeId = null;

    private string $skill = 'inconnu';

    /**
     * Compte les réponses HTTP facturées depuis le dernier {@see
     * self::pourGroupe()} — 1 = premier essai, 2+ = retry `Skill::generer()`
     * ou failover `ClientLLMAvecRepli` (les deux rejouent un appel complet,
     * donc comptent identiquement : le but est de voir « combien d'appels
     * a coûté cette génération », pas d'où vient chacun).
     */
    private int $tentative = 0;

    public function pourGroupe(?int $groupeId, string $skill): void
    {
        $this->groupeId = $groupeId;
        $this->skill = $skill;
        $this->tentative = 0;
    }

    /**
     * Verse l'usage d'UNE réponse HTTP réellement reçue d'un fournisseur.
     * Les clients normalisent leur usage brut (forme différente par
     * fournisseur) avant d'appeler ceci.
     *
     * @param  array{entree: int, sortie: int, cache?: int|null}  $usage
     */
    public function enregistrer(string $fournisseur, string $modele, array $usage): void
    {
        $this->tentative++;

        try {
            ConsommationIa::create([
                'groupe_id' => $this->groupeId,
                'skill' => $this->skill,
                'fournisseur' => $fournisseur,
                'modele' => $modele,
                'tokens_entree' => (int) ($usage['entree'] ?? 0),
                'tokens_sortie' => (int) ($usage['sortie'] ?? 0),
                'tokens_cache' => isset($usage['cache']) ? (int) $usage['cache'] : null,
                'tentative' => $this->tentative,
            ]);
        } catch (Throwable $e) {
            Log::warning('Télémétrie IA non enregistrée (best effort) — appel LLM non affecté.', [
                'fournisseur' => $fournisseur,
                'skill' => $this->skill,
                'groupe_id' => $this->groupeId,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
