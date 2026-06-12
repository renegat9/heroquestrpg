<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\EtatGroupeDiffuse;
use App\Events\MjReflechit;
use App\Jobs\GenererMenu;
use App\Jobs\GenererNarration;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Snapshot;
use App\Support\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Snapshots automatiques & reprise (contrat « Snapshots & reprise »,
 * doc 12 §4, doc 05 §6 TPK).
 *
 * Le moteur sérialise l'ÉTAT VIVANT complet dans `snapshots`
 * (`groupe_id, sequence_evenement, etat JSON` — l'étiquette vit dans le
 * JSON, la table n'a pas de colonne dédiée) : groupe (or, phase,
 * quete_courante_id), quête, carte (grille + état des pièges), instances
 * de monstres (PV, positions, états, conditions d'habillage),
 * etat_personnage_quete, et pour chaque héros actif : PV, sorts
 * (disponible), conditions (durées + sources), inventaire complet.
 *
 * Points de prise : `debut_quete` (DemarreurQuete) et `nouveau_tour`
 * (ResolveurTour, après la phase des monstres). Rétention playtest : le
 * `debut_quete` de la quête courante + le DERNIER `nouveau_tour` ; tout
 * est purgé à la fin (victoire) de la quête.
 *
 * Le journal d'événements n'est JAMAIS touché (source de vérité, doc 07) :
 * `sequence_evenement` marque simplement le dernier événement inclus
 * (chargement rapide = snapshot + événements depuis).
 */
final class Sauvegarde
{
    public const ETIQUETTE_DEBUT_QUETE = 'debut_quete';

    public const ETIQUETTE_NOUVEAU_TOUR = 'nouveau_tour';

    public function __construct(private readonly EtatGroupe $etatGroupe) {}

    // ------------------------------------------------------------------
    // Prise de snapshot + rétention
    // ------------------------------------------------------------------

    /**
     * Sérialise l'état vivant complet du groupe, puis applique la rétention :
     * `debut_quete` remplace tous les snapshots existants du groupe (nouvelle
     * quête = nouvelle base) ; `nouveau_tour` remplace le `nouveau_tour`
     * précédent de la même quête (seul le dernier est conservé).
     */
    public function snapshotter(Groupe $groupe, string $etiquette): Snapshot
    {
        $snapshot = Snapshot::create([
            'groupe_id' => $groupe->id,
            'sequence_evenement' => (int) $groupe->evenements()->max('sequence'),
            'etat' => $this->serialiser($groupe, $etiquette),
        ]);

        $this->appliquerRetention($groupe, $snapshot);

        return $snapshot;
    }

    /** Fin de quête (victoire) : les snapshots de la quête sont purgés. */
    public function purgerQuete(Groupe $groupe, Quete $quete): void
    {
        Snapshot::query()
            ->where('groupe_id', $groupe->id)
            ->get()
            ->filter(fn (Snapshot $s) => (int) data_get($s->etat, 'quete.id') === (int) $quete->id)
            ->each(fn (Snapshot $s) => $s->delete());
    }

    private function appliquerRetention(Groupe $groupe, Snapshot $nouveau): void
    {
        $anciens = Snapshot::query()
            ->where('groupe_id', $groupe->id)
            ->whereKeyNot($nouveau->id)
            ->get();

        $etiquette = (string) data_get($nouveau->etat, 'etiquette');
        $queteId = (int) data_get($nouveau->etat, 'quete.id');

        $anciens
            ->filter(fn (Snapshot $s) => match ($etiquette) {
                // Nouvelle quête : tout l'historique de snapshots est remplacé.
                self::ETIQUETTE_DEBUT_QUETE => true,
                // Pendant la quête : seul le DERNIER nouveau_tour survit.
                self::ETIQUETTE_NOUVEAU_TOUR => data_get($s->etat, 'etiquette') === self::ETIQUETTE_NOUVEAU_TOUR
                    && (int) data_get($s->etat, 'quete.id') === $queteId,
                default => false,
            })
            ->each(fn (Snapshot $s) => $s->delete());
    }

    // ------------------------------------------------------------------
    // Reprise (POST /groupes/{identifiant}/reprise)
    // ------------------------------------------------------------------

    /**
     * Choisit le snapshot (défaut : `debut_quete` de la dernière quête
     * échouée — le « recharger » après TPK, doc 05 §6) puis restaure.
     * 422 si une quête est en cours et NON échouée, ou si aucun snapshot
     * ne correspond.
     */
    public function reprendre(Groupe $groupe, ?int $snapshotId = null): Snapshot
    {
        $queteCourante = $groupe->queteCourante;

        if ($groupe->phase === 'quete' && $queteCourante !== null && $queteCourante->etat === 'en_cours') {
            throw ValidationException::withMessages([
                'groupe' => 'Une quête est en cours : impossible de recharger en pleine partie.',
            ]);
        }

        $snapshot = $snapshotId !== null
            ? $groupe->snapshots()->find($snapshotId)
            : $this->snapshotParDefaut($groupe);

        if ($snapshot === null) {
            throw ValidationException::withMessages([
                'snapshot_id' => 'Aucun snapshot disponible pour reprendre la partie.',
            ]);
        }

        $this->restaurer($groupe, $snapshot);

        return $snapshot;
    }

    /**
     * Réécrit TOUT l'état vivant depuis le snapshot, en transaction
     * (delete + insert pour les collections), repasse la quête `en_cours`
     * et le groupe en phase `quete`, journalise la reprise (le journal
     * n'est JAMAIS tronqué — doc 07), rediffuse `.groupe.etat` et relance
     * narration + menus (repli moteur garanti sans LLM).
     */
    public function restaurer(Groupe $groupe, Snapshot $snapshot): void
    {
        $etat = $snapshot->etat;

        DB::transaction(function () use ($groupe, $etat) {
            $this->restaurerQuete($etat['quete'], $etat['carte'] ?? null);
            $this->restaurerMonstres((int) $etat['quete']['id'], $etat['instances_monstres'] ?? []);
            $this->restaurerEtatsPersonnages((int) $etat['quete']['id'], $etat['etat_personnage_quete'] ?? []);

            foreach ($etat['heros'] ?? [] as $heros) {
                $this->restaurerHeros($heros);
            }

            $groupe->update([
                'or' => (int) data_get($etat, 'groupe.or', $groupe->or),
                'phase' => 'quete',
                'quete_courante_id' => (int) $etat['quete']['id'],
            ]);
        });

        $groupe->refresh();

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'reprise',
            'snapshot_id' => $snapshot->id,
            'etiquette' => data_get($snapshot->etat, 'etiquette'),
            'quete_id' => (int) data_get($snapshot->etat, 'quete.id'),
        ]);

        // Toute mutation d'état → journal puis broadcast `.groupe.etat` (contrat).
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe)));

        // Mise en récit + menus par jobs (repli garanti : l'API ne dépend pas du LLM).
        broadcast(new MjReflechit($groupe, true));
        GenererNarration::dispatch($groupe->id, [
            'type' => 'reprise',
            'etiquette' => data_get($snapshot->etat, 'etiquette'),
        ]);

        foreach ($this->herosActifs($groupe) as $personnage) {
            GenererMenu::dispatch($groupe->id, (int) $personnage->joueur_id, (int) $personnage->id);
        }
    }

    /** Snapshot `debut_quete` de la dernière quête échouée du groupe. */
    private function snapshotParDefaut(Groupe $groupe): ?Snapshot
    {
        $echouee = $groupe->quetes()->where('etat', 'echouee')->orderByDesc('id')->first();

        if ($echouee === null) {
            return null;
        }

        return $groupe->snapshots()
            ->orderByDesc('id')
            ->get()
            ->first(fn (Snapshot $s) => data_get($s->etat, 'etiquette') === self::ETIQUETTE_DEBUT_QUETE
                && (int) data_get($s->etat, 'quete.id') === (int) $echouee->id);
    }

    // ------------------------------------------------------------------
    // Sérialisation de l'état vivant
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function serialiser(Groupe $groupe, string $etiquette): array
    {
        $quete = $groupe->queteCourante;

        if ($quete === null) {
            throw new \RuntimeException('Snapshot impossible : aucune quête courante.');
        }

        return [
            'etiquette' => $etiquette,
            'groupe' => [
                'or' => (int) $groupe->or,
                'phase' => $groupe->phase,
                'quete_courante_id' => $groupe->quete_courante_id,
            ],
            'quete' => [
                'id' => $quete->id,
                'titre' => $quete->titre,
                'position_arc' => (int) $quete->position_arc,
                'type_jalon' => $quete->type_jalon,
                'branche_active' => $quete->branche_active,
                'etat' => $quete->etat,
                'or_initial' => $quete->or_initial,
            ],
            // Grille complète, état des pièges inclus (cachés compris).
            'carte' => $quete->carte === null ? null : [
                'largeur' => (int) $quete->carte->largeur,
                'hauteur' => (int) $quete->carte->hauteur,
                'grille' => $quete->carte->grille,
            ],
            'instances_monstres' => $quete->instancesMonstres()->orderBy('id')->get()
                ->map(fn (InstanceMonstre $i) => [
                    'id' => $i->id,
                    'monstre_id' => $i->monstre_id,
                    'pv_body' => (int) $i->pv_body,
                    'pv_mind' => (int) $i->pv_mind,
                    'position_x' => $i->position_x,
                    'position_y' => $i->position_y,
                    'etat' => $i->etat,
                    'habillage' => $i->habillage, // reskin + conditions des monstres
                ])->values()->all(),
            'etat_personnage_quete' => $quete->etatsPersonnages()->orderBy('id')->get()
                ->map(fn (EtatPersonnageQuete $e) => [
                    'personnage_id' => $e->personnage_id,
                    'position_x' => $e->position_x,
                    'position_y' => $e->position_y,
                    'a_joue' => (bool) $e->a_joue,
                    'tombe' => (bool) $e->tombe,
                ])->values()->all(),
            'heros' => $this->herosActifs($groupe)
                ->map(fn (Personnage $p) => $this->serialiserHeros($p))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiserHeros(Personnage $personnage): array
    {
        return [
            'id' => $personnage->id,
            'pv_body' => (int) $personnage->pv_body,
            'pv_body_max' => (int) $personnage->pv_body_max,
            'pv_mind' => (int) $personnage->pv_mind,
            'pv_mind_max' => (int) $personnage->pv_mind_max,
            'or' => (int) $personnage->or,
            'sorts' => $personnage->sorts()->orderBy('sorts.id')->get()
                ->map(fn ($sort) => [
                    'sort_id' => $sort->id,
                    'disponible' => (bool) $sort->pivot->disponible,
                ])->values()->all(),
            'conditions' => $personnage->conditions()->orderBy('conditions.id')->get()
                ->map(fn ($condition) => [
                    'condition_id' => $condition->id,
                    'duree' => (int) $condition->pivot->duree,
                    'source' => $condition->pivot->source,
                ])->values()->all(),
            'inventaire' => $personnage->inventaire()->orderBy('id')->get()
                ->map(fn ($ligne) => [
                    'objet_id' => $ligne->objet_id,
                    'emplacement' => $ligne->emplacement,
                    'quantite' => (int) $ligne->quantite,
                    'ameliorations' => $ligne->ameliorations,
                ])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Restauration (delete + insert pour les collections)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $quete
     * @param  array<string, mixed>|null  $carte
     */
    private function restaurerQuete(array $quete, ?array $carte): void
    {
        Quete::findOrFail($quete['id'])->update([
            'titre' => $quete['titre'],
            'position_arc' => $quete['position_arc'],
            'type_jalon' => $quete['type_jalon'],
            'branche_active' => $quete['branche_active'],
            'etat' => 'en_cours', // la quête repasse en cours (doc 05 §6)
            'or_initial' => $quete['or_initial'],
        ]);

        if ($carte !== null) {
            Carte::updateOrCreate(
                ['quete_id' => $quete['id']],
                ['largeur' => $carte['largeur'], 'hauteur' => $carte['hauteur'], 'grille' => $carte['grille']],
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $instances
     */
    private function restaurerMonstres(int $queteId, array $instances): void
    {
        InstanceMonstre::query()->where('quete_id', $queteId)->delete();

        foreach ($instances as $instance) {
            // forceFill pour conserver l'id d'origine (références stables
            // dans le journal et les menus régénérés).
            (new InstanceMonstre)->forceFill([
                'id' => $instance['id'],
                'quete_id' => $queteId,
                'monstre_id' => $instance['monstre_id'],
                'pv_body' => $instance['pv_body'],
                'pv_mind' => $instance['pv_mind'],
                'position_x' => $instance['position_x'],
                'position_y' => $instance['position_y'],
                'etat' => $instance['etat'],
                'habillage' => $instance['habillage'],
            ])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $etats
     */
    private function restaurerEtatsPersonnages(int $queteId, array $etats): void
    {
        EtatPersonnageQuete::query()->where('quete_id', $queteId)->delete();

        foreach ($etats as $etat) {
            EtatPersonnageQuete::create([
                'personnage_id' => $etat['personnage_id'],
                'quete_id' => $queteId,
                'position_x' => $etat['position_x'],
                'position_y' => $etat['position_y'],
                'a_joue' => $etat['a_joue'],
                'tombe' => $etat['tombe'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $heros
     */
    private function restaurerHeros(array $heros): void
    {
        $personnage = Personnage::find($heros['id']);

        if ($personnage === null) {
            return; // héros parti depuis le snapshot — on n'invente rien
        }

        $personnage->update([
            'pv_body' => $heros['pv_body'],
            'pv_body_max' => $heros['pv_body_max'],
            'pv_mind' => $heros['pv_mind'],
            'pv_mind_max' => $heros['pv_mind_max'],
            'or' => $heros['or'],
        ]);

        DB::table('personnage_sorts')->where('personnage_id', $personnage->id)->delete();
        DB::table('personnage_sorts')->insert(array_map(fn (array $s) => [
            'personnage_id' => $personnage->id,
            'sort_id' => $s['sort_id'],
            'disponible' => $s['disponible'],
        ], $heros['sorts'] ?? []));

        DB::table('personnage_conditions')->where('personnage_id', $personnage->id)->delete();
        DB::table('personnage_conditions')->insert(array_map(fn (array $c) => [
            'personnage_id' => $personnage->id,
            'condition_id' => $c['condition_id'],
            'duree' => $c['duree'],
            'source' => $c['source'],
        ], $heros['conditions'] ?? []));

        $personnage->inventaire()->delete();
        foreach ($heros['inventaire'] ?? [] as $ligne) {
            $personnage->inventaire()->create([
                'objet_id' => $ligne['objet_id'],
                'emplacement' => $ligne['emplacement'],
                'quantite' => $ligne['quantite'],
                'ameliorations' => $ligne['ameliorations'],
            ]);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Personnage>
     */
    private function herosActifs(Groupe $groupe): \Illuminate\Database\Eloquent\Collection
    {
        return $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->orderBy('personnages.id')
            ->get();
    }
}
