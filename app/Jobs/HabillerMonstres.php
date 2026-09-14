<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\HabillageMonstres;
use App\Events\EtapePreparation;
use App\Events\EtatGroupeDiffuse;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Partie\EtatGroupe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job IA : habille (renomme/redécrit) les instances de monstres déjà spawnées
 * par le moteur au démarrage de la quête (doc 06 §5, Q6).
 *
 * Le moteur reste autorité sur les stats et le placement : ce job ne fait que
 * poser `habillage.nom` / `habillage.description` sur les instances existantes,
 * groupées par bloc de catalogue (monstre_id). Best effort : sans LLM (ou en
 * cas d'échec), les instances gardent leur nom de catalogue — la quête tourne.
 */
class HabillerMonstres implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $groupeId,
        public readonly int $queteId,
    ) {}

    public function handle(HabillageMonstres $skill, ContexteAssembleur $assembleur): void
    {
        $groupe = Groupe::find($this->groupeId);

        if ($groupe === null) {
            return;
        }

        // Première étape visible de la préparation : l'écran de table affiche
        // où l'on en est plutôt que de laisser le groupe devant un donjon muet.
        broadcast(new EtapePreparation($groupe, 'habillage'));

        // Blocs de monstres présents dans la quête (un habillage par bloc).
        $blocs = InstanceMonstre::query()
            ->where('quete_id', $this->queteId)
            ->where('etat', 'actif')
            ->with('monstre')
            ->get();

        if ($blocs->isEmpty()) {
            // Aucun monstre à habiller (budget de rencontres à 0, quête
            // atypique) : les salles restent à décrire quand même — un donjon
            // sans monstre a toujours des lieux et du mobilier. Sans boss, pas
            // de barks ni de portrait de boss à générer.
            $this->chainerOuverture(avecBoss: false);

            return;
        }

        $aHabiller = $blocs
            ->unique('monstre_id')
            ->map(fn (InstanceMonstre $i) => [
                'monstre_id' => (int) $i->monstre_id,
                'nom_base' => $i->monstre->nom_base,
                'tier' => $i->monstre->tier,
            ])
            ->values()
            ->all();

        try {
            $contexte = $assembleur->assembler($groupe, extra: ['monstres_a_habiller' => $aHabiller]);
            $sortie = $skill->generer($contexte);
        } catch (\Throwable $e) {
            Log::warning('Habillage des monstres impossible — noms de catalogue conservés.', [
                'groupe' => $groupe->id,
                'erreur' => $e->getMessage(),
            ]);

            // ⚠ La chaîne repart QUAND MÊME. C'était le SEUL des quatre chemins
            // de sortie à ne pas la relancer (René, 2026-09-13) : un appel
            // d'habillage en échec — timeout, quota, 500 du fournisseur — coûtait
            // à la quête son OUVERTURE, sa scène, ses barks et le portrait du
            // boss, en silence ; et la barre de préparation restait figée sur
            // « habillage » jusqu'à la fin de la quête, puisque seul
            // GenererVoixQuete diffuse l'étape « pret ». L'habillage est un
            // ornement, la mise en scène de la quête n'en dépend pas : sans lui,
            // RecitsQuete retombe simplement sur les noms de catalogue.
            $this->chainerOuverture(avecBoss: true);

            return;
        }

        $habillages = collect($sortie['habillages'] ?? [])->keyBy(fn ($h) => (int) $h['monstre_id']);

        if ($habillages->isEmpty()) {
            // Sortie vide du skill : rien à appliquer, mais la quête s'ouvre
            // comme les autres — RecitsQuete retombe sur les noms de catalogue.
            $this->chainerOuverture(avecBoss: true);

            return;
        }

        foreach ($blocs as $instance) {
            $h = $habillages->get((int) $instance->monstre_id);

            if ($h === null) {
                continue;
            }

            // ⚠ RELECTURE OBLIGATOIRE avant d'écrire (2026-09-03). `$blocs` a été
            // chargé AVANT l'appel au LLM, qui dure de quelques secondes à
            // plusieurs minutes ; `$instance->habillage` porte donc l'état du
            // donjon tel qu'il était au DÉMARRAGE de la quête. Le réécrire tel
            // quel efface tout ce que la partie a posé entre-temps — et
            // `habillage` ne contient pas que l'habillage : il porte les
            // CONDITIONS des monstres (endormi, saute_tour, terrifié, paralysé,
            // ralenti, enfumé), la brûlure du troll, les dégâts différés, les
            // usages de Dread et `repousse_par`.
            //
            // Mesuré en jeu : un Sceptre de Télékinésie utilisé dans la première
            // minute d'une quête posait bien `saute_tour`, et la condition
            // disparaissait sans une ligne de journal quand le job d'habillage
            // se terminait. Invisible en test — aucun worker n'y tourne en
            // parallèle —, invisible au joueur, et impossible à imputer.
            $instance->refresh();

            $habillage = $instance->habillage ?? [];
            $habillage['nom'] = $h['nom'];
            $habillage['description'] = $h['description'];
            $instance->update(['habillage' => $habillage]);
        }

        // La table rafraîchit les noms affichés.
        broadcast(new EtatGroupeDiffuse($groupe, app(EtatGroupe::class)->payload($groupe->fresh())));

        $this->chainerOuverture(avecBoss: true);
    }

    /**
     * Séquence d'ouverture de la quête — le SEUL point de passage, appelé par
     * les QUATRE sorties de `handle()` (nominale, sortie vide, donjon sans
     * monstre, échec de l'appel).
     *
     * ⚠ C'est cette unicité qui est le correctif du 2026-09-13 : la séquence
     * était recopiée sur trois sorties et ABSENTE de la quatrième — celle du
     * `catch` —, si bien qu'un habillage en échec privait la quête de son
     * ouverture sans que rien ne le signale. Trois copies d'une règle, et la
     * seule qui manquait était celle qu'on ne regardait jamais.
     *
     * ⚠ L'ORDRE EST LA SÉQUENCE. Ces jobs partagent la file `default`, donc
     * l'ordre de dispatch EST l'ordre d'exécution.
     *
     * 1. L'IMAGE DE SCÈNE d'abord : l'écran de table ouvre la quête sur une
     *    carte plein cadre — illustration + texte de mise en scène (René,
     *    2026-08-21). Sans elle, cette carte n'aurait jamais son image sur une
     *    première quête, ce qui la viderait de sa raison d'être.
     * 2. Les RÉCITS, qui doivent citer les monstres par leur nom HABILLÉ (d'où
     *    le chaînage APRÈS l'application, côté sortie nominale). Ils déclenchent
     *    l'ouverture une fois écrits. ⚠ Ils passent devant les PORTRAITS :
     *    chronométré en campagne réelle (2026-08-20), le pack attendait deux
     *    générations d'images et n'arrivait qu'à t+4 min, laissant la quête se
     *    jouer quatre minutes sur le repli générique.
     * 3. Le reste, pur habillage, en arrière-plan.
     *
     * @param  bool  $avecBoss  faux quand la quête n'a AUCUN monstre : ni barks
     *                          ni portrait de boss à générer.
     */
    private function chainerOuverture(bool $avecBoss): void
    {
        GenererImagesQuete::dispatch($this->groupeId, $this->queteId, 'scene');
        GenererRecitsQuete::dispatch($this->groupeId, $this->queteId);

        if (! $avecBoss) {
            return;
        }

        GenererBarksBoss::dispatch($this->queteId);
        GenererImagesQuete::dispatch($this->groupeId, $this->queteId, 'boss');
    }
}
