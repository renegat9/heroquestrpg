<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Audio\TtsGemini;
use App\Events\EtapePreparation;
use App\Models\Groupe;
use App\Models\Parametre;
use App\Models\Quete;
use App\Partie\Narration\BibliothequeNarration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pré-génère la VRAIE VOIX DE NARRATEUR des récits de salle d'une quête
 * (`quetes.recits.salles`) — la moitié du pack écrit par
 * {@see GenererRecitsQuete}, chaîné après lui.
 *
 * ⚠ Ne synthétise QUE les descriptions de salle : ce sont les SEULS textes
 * du pack sans placeholder (cf. `RecitsQuete::validerMetier()`), donc les
 * seuls dont le hash (`BibliothequeNarration::cheminDynamique()`) est stable
 * une fois pour toutes. Un temps fort à `{heros}`/`{monstre}`/`{objet}`/`{or}`
 * n'est connu qu'une fois substitué au tirage — le synthétiser ici
 * produirait un fichier que personne ne redemandera jamais avec ce hash
 * précis, en pure perte de quota. Les temps forts restent lus en Web Speech
 * par la table (comportement actuel, inchangé).
 *
 * Toute la synthèse elle-même est déléguée à
 * `BibliothequeNarration::voixDynamique()`, qui met déjà en cache par hash
 * (`public/audio/narration/dyn/{hash}.wav`) et respecte la bascule
 * `Parametre::voix_dynamique_active` : rien de tout ça n'est réécrit ici.
 *
 * Reprenable + arrêt propre (CLAUDE.md, quota Gemini TTS = 100 requêtes/j) :
 * une salle déjà en cache ne déclenche AUCUN appel réseau (boucle
 * elle-même « saute » ce qui existe), et la PREMIÈRE synthèse qui échoue
 * (le cas le plus probable étant un 429 de quota journalier épuisé — voir
 * `TtsGemini::synthetiser()`, qui a déjà réessayé plusieurs fois en
 * interne avant d'abandonner) arrête le job net : retenter les salles
 * suivantes contre un quota très probablement épuisé ne ferait
 * qu'accumuler les mêmes échecs coûteux (jusqu'à ~6 réessais internes
 * chacun). Un run ultérieur (relance manuelle, ou rejouée par une commande
 * future) reprend exactement où celui-ci s'est arrêté.
 */
class GenererVoixQuete implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * ⚠ 15 min, là où ce job héritait du `--timeout=120` du worker — et le
     * dépassait STRUCTURELLEMENT, pas par accident : il synthétise une
     * description par salle (8 sur une quête ordinaire) à ~15 s pièce, sans
     * compter les réessais internes de `TtsGemini` sur un 429. Mesuré le
     * 2026-08-22 sur la vraie pile : tué à 2 min exactement, quête après quête.
     *
     * Ce qu'un simple échec aurait coûté est le vrai motif : le timeout Laravel
     * n'est pas une exception qui remonte, `Worker::kill()` fait un
     * `posix_kill(SIGKILL)` — donc le `finally` de `handle()` NE S'EXÉCUTE PAS
     * et l'étape `pret` n'est jamais diffusée. L'écran de table restait sur sa
     * carte d'ouverture pour toujours, exactement le dégât que ce `finally`
     * annonce vouloir empêcher.
     *
     * ⚠ Doit rester SOUS `queue.connections.database.retry_after` (1200 s) :
     * au-dessus, la file ré-réserve un job encore en cours et facture deux fois
     * la même synthèse — le piège déjà payé par `GenererRecitsQuete`.
     */
    public int $timeout = 900;

    public function __construct(
        public readonly int $queteId,
    ) {}

    public function handle(TtsGemini $tts, BibliothequeNarration $lib): void
    {
        // ⚠ Ce job clôt la séquence de préparation, et c'est pour ça que
        // l'annonce « prêt » est dans un `finally` : il sort par CINQ chemins
        // (pas de clé TTS, bascule Réglages coupée, quête introuvable, quota
        // épuisé en cours de route, ou fin normale). Un seul oubli et l'écran
        // de table resterait en chargement pour toujours, à afficher une étape
        // que plus personne ne joue.
        try {
            $this->synthetiser($tts, $lib);
        } finally {
            // Groupe purgé entre-temps (campagne arrêtée) : rien à annoncer,
            // et surtout pas de seconde exception dans un `finally`.
            if (($groupe = $this->groupe()) !== null) {
                broadcast(new EtapePreparation($groupe, 'pret'));
            }
        }
    }

    /**
     * ⚠ Ceinture du `finally` de `handle()`, qu'un SIGKILL saute. Laravel
     * appelle bien `failed()` AVANT de tuer le process (le gestionnaire SIGALRM
     * passe par `markJobAsFailedIfWillExceedMaxAttempts()`, et `$tries = 1` fait
     * échouer dès la première tentative) : c'est donc le dernier endroit d'où
     * `pret` peut encore partir. Sans lui, tout plafond reste un pari — il
     * suffit d'une quête plus longue que prévu pour figer la table.
     *
     * Best-effort : rien ici ne doit lever, un `failed()` qui explose ne
     * laisserait aucune trace utile.
     */
    public function failed(?Throwable $e): void
    {
        try {
            if (($groupe = $this->groupe()) !== null) {
                broadcast(new EtapePreparation($groupe, 'pret'));
            }
        } catch (Throwable) {
            // Groupe purgé, diffusion morte : la table se dégèlera d'elle-même
            // à la reconnexion, il n'y a plus rien à sauver ici.
        }
    }

    /** Le groupe de cette quête — pour annoncer la fin de préparation. */
    private function groupe(): ?Groupe
    {
        return Quete::find($this->queteId)?->groupe;
    }

    private function synthetiser(TtsGemini $tts, BibliothequeNarration $lib): void
    {
        if (! $tts->estConfigure()) {
            return; // pas de GEMINI_API_KEY : rien à générer, repli Web Speech (comportement actuel).
        }

        if (! $this->voixDynamiqueActive()) {
            return; // bascule Réglages désactivée — même best-effort que GenererBarksBoss.
        }

        $quete = Quete::find($this->queteId);

        if ($quete === null) {
            return;
        }

        broadcast(new EtapePreparation($quete->groupe, 'voix'));

        foreach ((array) data_get($quete->recits, 'salles', []) as $id => $recit) {
            $texte = trim((string) ($recit['texte'] ?? ''));

            if ($texte === '') {
                continue;
            }

            if ($lib->urlDynamiqueSiCache($texte) !== null) {
                continue; // reprise : déjà synthétisé lors d'un run précédent, aucun appel réseau.
            }

            if ($lib->voixDynamique($texte, $tts) === null) {
                // Échec de synthèse — best-effort côté BibliothequeNarration
                // (elle a déjà journalisé le détail). On s'arrête ICI plutôt
                // que d'essayer les salles restantes : cf. docblock de
                // classe, un quota journalier épuisé produirait le même
                // échec, coûteux en réessais internes, sur chacune d'elles.
                Log::warning('Génération voix de quête interrompue — synthèse échouée, arrêt propre (pas de relance en boucle).', [
                    'quete_id' => $this->queteId,
                    'salle' => $id,
                ]);

                return;
            }
        }
    }

    /**
     * Bascule « synthèse vocale IA en cours de partie » (panneau Réglages) —
     * même posture best-effort que `GenererBarksBoss::voixDynamiqueActive()`
     * et `BibliothequeNarration::voixDynamiqueActive()` (privée, non
     * réutilisable ici) : table/base indisponible → repli actif.
     */
    private function voixDynamiqueActive(): bool
    {
        try {
            return Parametre::actuel()->voix_dynamique_active;
        } catch (Throwable) {
            return true;
        }
    }
}
