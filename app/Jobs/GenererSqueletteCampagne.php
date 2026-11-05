<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Audio\TtsGemini;
use App\Agent\Exceptions\AppelLlmException;
use App\Agent\Exceptions\SortieInvalideException;
use App\Agent\Memoire\BibleQdrant;
use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\SqueletteCampagne;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Models\Groupe;
use App\Partie\BestiaireGroupe;
use App\Partie\CatalogueExtensions;
use App\Partie\Narration\BibliothequeNarration;
use App\Support\Journal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job IA : squelette de campagne à la création du groupe (doc 06 §2, Q10).
 *
 * Génère le fil rouge (prémisse, menace, jalons, fils narratifs), le
 * persiste dans groupes.plan_campagne (socle stable, doc 07 §2), amorce
 * la bible Qdrant avec le thème (best effort), puis diffuse la prémisse
 * sur l'écran de table.
 *
 * Bestiaire automatique (René, 2026-11-05) : le MJ reçoit la liste des
 * extensions et le tirage fait à la création ; les boîtes qu'il rend
 * remplacent ce tirage (`BestiaireGroupe::adopterChoixDuMj()`). Sans réponse
 * (thème vague, LLM indisponible, pas de clé), le tirage reste. Dans tous les
 * cas, le bestiaire retenu est écrit au journal.
 */
class GenererSqueletteCampagne implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $groupeId) {}

    public function handle(SqueletteCampagne $skill, ContexteAssembleur $assembleur, BibleQdrant $bible, TtsGemini $tts, BibliothequeNarration $narration, CatalogueExtensions $extensions): void
    {
        $groupe = Groupe::findOrFail($this->groupeId);

        broadcast(new MjReflechit($groupe, true));

        try {
            $contexte = $assembleur->assembler($groupe, requeteScene: $groupe->theme);
            $contexte['extensions'] = $extensions->pourSquelette($groupe);

            try {
                $squelette = $skill->generer($contexte);
            } catch (AppelLlmException|SortieInvalideException $e) {
                // Repli silencieux (contrat : l'API ne dépend jamais du LLM) :
                // sans squelette, la campagne reste jouable — les jalons sont
                // dérivés par défaut (DemarreurQuete) et le fil rouge pourra
                // être regénéré plus tard.
                Log::warning('Squelette de campagne indisponible (LLM) — la campagne démarre sans fil rouge.', [
                    'groupe_id' => $groupe->id,
                    'erreur' => $e->getMessage(),
                ]);

                Journal::ajouter($groupe, 'systeme', ['action' => 'squelette_campagne_indisponible']);
                $this->annoncerBestiaire($groupe);

                $texteRepli = 'La campagne commence : le groupe se rassemble, prêt à répondre à l\'appel de l\'aventure.';
                $evenementRepli = Journal::ajouter($groupe, 'narration', ['texte' => $texteRepli]);
                broadcast(new NarrationDiffusee(
                    $groupe,
                    $texteRepli,
                    ambiance: 'mystere',
                    sequence: $evenementRepli->sequence,
                ));

                return;
            }

            // Le choix du bestiaire n'est PAS du fil rouge : retiré avant d'écrire le
            // squelette, que les skills suivants reçoivent comme « à ne pas contredire ».
            $choisies = array_values(array_unique(array_map('strval', (array) ($squelette['extensions'] ?? []))));
            unset($squelette['extensions']);

            if ($contexte['extensions']['choix_ouvert'] && $choisies !== []
                && ! BestiaireGroupe::adopterChoixDuMj($groupe, $choisies)) {
                Log::info('Bestiaire du MJ non retenu : une quête a démarré pendant l\'écriture du squelette, le tirage reste.', [
                    'groupe_id' => $groupe->id,
                    'choisies' => $choisies,
                ]);
            }
            $this->annoncerBestiaire($groupe);

            $groupe->plan_campagne = $squelette;
            $groupe->save();

            // Illustration du lieu de repos (hub), depuis la prémisse — fond.
            GenererImageHub::dispatch($this->groupeId);

            Journal::ajouter($groupe, 'systeme', [
                'action' => 'squelette_campagne_genere',
                'menace' => $squelette['menace']['nom'] ?? null,
                'nb_jalons' => count($squelette['jalons'] ?? []),
            ]);

            $this->amorcerBible($bible, $groupe, $squelette);

            // Prologue : prémisse en vraie voix de narrateur (synthèse + cache,
            // best-effort). L'URL sera ré-exposée par EtatGroupe → l'écran de
            // table peut afficher/relire le prologue même s'il ouvre plus tard.
            $premisse = (string) $squelette['premisse'];
            $evenementPremisse = Journal::ajouter($groupe, 'narration', ['texte' => $premisse]);
            broadcast(new NarrationDiffusee(
                $groupe, $premisse, ambiance: 'mystere',
                url: $narration->voixDynamique($premisse, $tts),
                sequence: $evenementPremisse->sequence,
            ));
        } finally {
            broadcast(new MjReflechit($groupe, false));
        }
    }

    /**
     * Le bestiaire RETENU, au journal : choisi par le MJ selon le thème, ou tiré au hasard. Rien pour un bestiaire coché
     * par les joueurs (ils l'ont choisi eux-mêmes) ni pour une campagne en rotation historique.
     */
    private function annoncerBestiaire(Groupe $groupe): void
    {
        $bestiaire = BestiaireGroupe::duGroupe($groupe);

        if (! in_array($bestiaire->mode, [BestiaireGroupe::MODE_MJ, BestiaireGroupe::MODE_TIRAGE], true)) {
            return;
        }

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'bestiaire_de_campagne',
            'mode' => $bestiaire->mode,
            'boites' => $bestiaire->boites,
            'libelle' => $bestiaire->libelle(),
        ]);
    }

    /**
     * Amorce de bible (doc 12 §7, ingestion « à la création ») — best effort :
     * si Qdrant est indisponible, la campagne démarre quand même.
     *
     * @param  array<string, mixed>  $squelette
     */
    private function amorcerBible(BibleQdrant $bible, Groupe $groupe, array $squelette): void
    {
        try {
            $bible->assurerCollection();

            $entrees = [[
                'type' => 'evenement',
                'titre' => 'Prémisse de la campagne',
                'contenu' => (string) $squelette['premisse'],
            ], [
                'type' => 'pnj',
                'titre' => (string) ($squelette['menace']['nom'] ?? 'La grande menace'),
                'contenu' => (string) ($squelette['menace']['description'] ?? ''),
                'statut' => 'hostile',
            ]];

            foreach ($squelette['fils_narratifs'] ?? [] as $fil) {
                $entrees[] = [
                    'type' => 'promesse',
                    'titre' => (string) $fil['titre'],
                    'contenu' => (string) $fil['description'],
                ];
            }

            $bible->upsert($groupe->id, $entrees);
        } catch (Throwable $e) {
            Log::warning('Amorce de bible impossible (Qdrant indisponible ?) — squelette persisté sans bible.', [
                'groupe_id' => $groupe->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
