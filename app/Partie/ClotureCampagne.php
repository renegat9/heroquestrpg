<?php

declare(strict_types=1);

namespace App\Partie;

use App\Agent\Memoire\BibleQdrant;
use App\Events\ClotureMaj;
use App\Events\ClotureOuverte;
use App\Events\EtatGroupeDiffuse;
use App\Jobs\CloturerCampagne;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Joueur;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Snapshot;
use App\Partie\Images\BibliothequeImages;
use App\Partie\Marche\PhaseMarche;
use App\Partie\Votes\VoteGroupe;
use App\Support\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Clôture de campagne (doc 05 §6, doc 12 §8, contrat docs/contrat-api.md).
 *
 * La FENÊTRE de clôture vit en CACHE serveur (comme le marché : rien n'est
 * appliqué avant la confirmation de TOUS les joueurs membres). Ouverture :
 *  - automatique à la victoire du BOSS FINAL (ResolveurTour::terminerQuete,
 *    broadcast `.cloture.ouverte`) → issue `victoire` ;
 *  - manuelle par un membre AU HUB (fin décidée) → issue DÉRIVÉE de l'état :
 *    `victoire` si le boss final a été vaincu, `echec` si la dernière quête
 *    est échouée (TPK), sinon `abandon`. L'issue ne dépend JAMAIS du seul
 *    drapeau client — une fin gagnée/perdue ne peut donc être mal étiquetée ;
 *  - pour un `echec` (doc 05 §6 : résumé d'historique = échec), l'or à partager
 *    est `quetes.or_initial` de la quête échouée, PLAFONNÉ à l'or restant (le
 *    butin de la mission perdue est perdu) ; `abandon: true` reste réservé à
 *    une campagne réellement échouée.
 * 422 si une quête est en cours.
 *
 * Quand tous confirment, la finalisation part en job (CloturerCampagne) :
 * réassignations, or réparti, résumé AVANT purge, historique, détachement,
 * puis purge complète (données + caches + bible Qdrant best-effort).
 *
 * purger() est aussi le nettoyage SILENCIEUX d'un groupe vide (doc 05 §6) :
 * VoteGroupe l'appelle au départ du dernier joueur, sans résumé ni broadcast.
 */
final class ClotureCampagne
{
    /** Durée de vie d'une fenêtre de clôture abandonnée (séance de jeu). */
    public const TTL_MINUTES = 360;

    public function __construct(
        private readonly EtatGroupe $etatGroupe,
        private readonly BibleQdrant $bible,
    ) {}

    /** Clé du cache de la fenêtre de clôture d'un groupe. */
    public static function cle(int $groupeId): string
    {
        return "partie:cloture:{$groupeId}";
    }

    /**
     * Ouverture MANUELLE par un membre (POST cloture). 422 si une quête est
     * en cours, si la fenêtre est déjà ouverte, ou si `abandon` est demandé
     * sans quête échouée.
     *
     * @return array<string, mixed> EtatCloture
     */
    public function ouvrir(Groupe $groupe, bool $abandon = false): array
    {
        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'groupe' => 'La clôture ne peut s\'ouvrir qu\'au hub : terminez (ou abandonnez après échec) la quête en cours.',
            ]);
        }

        if (Cache::has(self::cle($groupe->id))) {
            throw ValidationException::withMessages([
                'groupe' => 'Une fenêtre de clôture est déjà ouverte pour ce groupe.',
            ]);
        }

        // L'issue est DÉRIVÉE de l'état de la campagne (jamais du seul drapeau
        // client) pour qu'une fin gagnée OU perdue ne soit jamais mal étiquetée :
        //  - boss final vaincu       → victoire (pot complet) ;
        //  - dernière quête échouée  → echec (or_initial plafonné, butin perdu) ;
        //  - sinon (fin décidée saine) → abandon (pot complet).
        // Garde-fou historique : un TPK sur la quête finale se clôturait en
        // « abandon » si le client omettait `abandon: true` — désormais « echec ».
        if ($groupe->quetes()->where('type_jalon', 'boss_final')->where('etat', 'terminee')->exists()) {
            return $this->creerFenetre($groupe, 'victoire', (int) $groupe->or);
        }

        $derniere = $groupe->quetes()->orderByDesc('position_arc')->first();
        $echec = $derniere !== null && $derniere->etat === 'echouee';

        // `abandon: true` reste réservé à une campagne RÉELLEMENT échouée (doc 05 §6).
        if ($abandon && ! $echec) {
            throw ValidationException::withMessages([
                'abandon' => 'L\'abandon n\'est possible qu\'après une quête échouée (TPK, doc 05 §6).',
            ]);
        }

        if ($echec) {
            // Or d'AVANT la mission, plafonné à l'or restant (butin déjà dépensé).
            return $this->creerFenetre($groupe, 'echec', min((int) $derniere->or_initial, (int) $groupe->or));
        }

        return $this->creerFenetre($groupe, 'abandon', (int) $groupe->or);
    }

    /**
     * Ouverture AUTOMATIQUE à la victoire du boss final (appelée par
     * ResolveurTour::terminerQuete) — null si une fenêtre est déjà ouverte.
     *
     * @return array<string, mixed>|null EtatCloture
     */
    public function ouvrirVictoire(Groupe $groupe): ?array
    {
        if (Cache::has(self::cle($groupe->id))) {
            return null;
        }

        return $this->creerFenetre($groupe, 'victoire', (int) $groupe->or);
    }

    /**
     * EtatCloture courant (GET cloture), ou null si aucune fenêtre ouverte.
     *
     * @return array<string, mixed>|null
     */
    public function etat(Groupe $groupe): ?array
    {
        $phase = Cache::get(self::cle($groupe->id));

        return is_array($phase) ? $this->payload($groupe, $phase) : null;
    }

    /**
     * Réassigne un équipement (PUT repartition) : la ligne d'inventaire doit
     * appartenir à un héros ACTIF du groupe, le destinataire doit être un
     * héros ACTIF du groupe. ANNULE TOUTES les confirmations (le partage a
     * changé, chacun doit revalider).
     *
     * @return array<string, mixed> EtatCloture
     */
    public function reassigner(Groupe $groupe, int $inventaireId, int $personnageId): array
    {
        $phase = $this->phaseOuverte($groupe);

        $actifs = $this->herosActifs($groupe)->pluck('id');

        $ligne = Inventaire::query()
            ->whereKey($inventaireId)
            ->whereIn('personnage_id', $actifs)
            ->first();

        if ($ligne === null) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Cet équipement n\'appartient pas à un héros actif du groupe.',
            ]);
        }

        if (! $actifs->contains($personnageId)) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Le destinataire doit être un héros actif du groupe.',
            ]);
        }

        $phase['reassignations'][(string) $inventaireId] = $personnageId;
        $phase['confirmations'] = array_map(fn () => false, $phase['confirmations']);

        Cache::put(self::cle($groupe->id), $phase, now()->addMinutes(self::TTL_MINUTES));

        $etat = $this->payload($groupe, $phase);
        broadcast(new ClotureMaj($groupe, $etat));

        return $etat;
    }

    /**
     * Confirmation du joueur (POST confirmation). Quand TOUS les joueurs
     * membres ont confirmé : la fenêtre se ferme et la FINALISATION part en
     * job (CloturerCampagne) — réassignations, or, résumé, historique, purge.
     *
     * @return array{finalise: bool, cloture: array<string, mixed>|null}
     */
    public function confirmer(Groupe $groupe, Joueur $joueur): array
    {
        $phase = $this->phaseOuverte($groupe);

        $phase['confirmations'][(string) $joueur->id] = true;

        $tousConfirmes = ! in_array(false, $phase['confirmations'], true);

        if (! $tousConfirmes) {
            Cache::put(self::cle($groupe->id), $phase, now()->addMinutes(self::TTL_MINUTES));

            $etat = $this->payload($groupe, $phase);
            broadcast(new ClotureMaj($groupe, $etat));

            return ['finalise' => false, 'cloture' => $etat];
        }

        // Tous confirmés → la fenêtre est consommée, la finalisation part en
        // job avec la photo de la phase (réassignations, issue, or).
        Cache::forget(self::cle($groupe->id));

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'cloture_confirmee',
            'issue' => $phase['issue'],
            'or_a_partager' => $phase['or_a_partager'],
        ]);

        CloturerCampagne::dispatch($groupe->id, $phase);

        return ['finalise' => true, 'cloture' => null];
    }

    /** Annule la fenêtre (DELETE cloture) : rien n'est appliqué. */
    public function annuler(Groupe $groupe): void
    {
        $this->phaseOuverte($groupe);

        Cache::forget(self::cle($groupe->id));

        Journal::ajouter($groupe, 'systeme', ['action' => 'cloture_annulee']);

        // Les clients re-GET la clôture (404 = fenêtre fermée) sur cet état.
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe->fresh())));
    }

    // ------------------------------------------------------------------
    // Répartition (réutilisée par le job CloturerCampagne)
    // ------------------------------------------------------------------

    /**
     * Parts ÉGALES de l'or entre les héros actifs, le reste réparti unité
     * par unité aux premiers (ordre d'initiative — contrat EtatCloture).
     *
     * @return list<array{personnage_id: int, nom: string, joueur_id: int, montant: int}>
     */
    public function parts(Groupe $groupe, int $or): array
    {
        $heros = $this->herosActifs($groupe);

        if ($heros->isEmpty()) {
            return [];
        }

        $base = intdiv($or, $heros->count());
        $reste = $or % $heros->count();

        return $heros->values()->map(fn (Personnage $p, int $i) => [
            'personnage_id' => (int) $p->id,
            'nom' => $p->nom,
            'joueur_id' => (int) $p->joueur_id,
            'montant' => $base + ($i < $reste ? 1 : 0),
        ])->all();
    }

    /**
     * Purge COMPLÈTE des données du groupe (doc 12 §8) : quetes (et leurs
     * cartes, instances de monstres, etat_personnage_quete), evenements,
     * snapshots, caches de phase `partie:*`, le groupe lui-même — puis les
     * points Qdrant du group_id en BEST-EFFORT. Les personnages survivent,
     * détachés (groupe_actif_id null), avec or, inventaire et historique.
     *
     * Silencieuse (aucun broadcast, aucun résumé) : c'est aussi le nettoyage
     * automatique d'un GROUPE VIDE au départ du dernier joueur (doc 05 §6).
     */
    public function purger(Groupe $groupe): void
    {
        $groupeId = (int) $groupe->id;

        // Relevés AVANT détachement, pour vider les caches par joueur/héros.
        $joueurIds = $groupe->personnages()->pluck('joueur_id')->unique()->values();
        $personnageIds = $groupe->personnages()->pluck('personnages.id')->values();

        DB::transaction(function () use ($groupe) {
            // Détachement : les personnages retournent au roster (doc 05 §6).
            $groupe->personnagesActifs()->update(['groupe_actif_id' => null]);
            $groupe->update(['quete_courante_id' => null]);

            $queteIds = $groupe->quetes()->pluck('id');

            Evenement::where('groupe_id', $groupe->id)->delete();
            Snapshot::where('groupe_id', $groupe->id)->delete();
            EtatPersonnageQuete::whereIn('quete_id', $queteIds)->delete();
            InstanceMonstre::whereIn('quete_id', $queteIds)->delete();
            Carte::whereIn('quete_id', $queteIds)->delete();
            Quete::whereIn('id', $queteIds)->delete();

            $groupe->personnages()->detach();
            $groupe->delete();
        });

        // Caches de phase `partie:*` du groupe.
        Cache::forget(PhaseMarche::cle($groupeId));
        Cache::forget(VoteGroupe::cle($groupeId));
        Cache::forget(self::cle($groupeId));
        Cache::forget(EtatGroupe::cleMjReflechit($groupeId));

        foreach ($joueurIds as $joueurId) {
            Cache::forget(GenererMenu::cleMenu($groupeId, (int) $joueurId));
        }

        foreach ($personnageIds as $personnageId) {
            Cache::forget(MoteurSorts::cleConcentration($groupeId, (int) $personnageId));
        }

        // Bible Qdrant du group_id — best-effort : si Qdrant est injoignable,
        // la purge relationnelle reste acquise (doc 12 §8).
        try {
            $this->bible->purgerGroupe($groupeId);
        } catch (Throwable $e) {
            Log::warning('Purge Qdrant impossible (Qdrant indisponible ?) — données relationnelles purgées.', [
                'groupe_id' => $groupeId,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed> EtatCloture
     */
    private function creerFenetre(Groupe $groupe, string $issue, int $or): array
    {
        $confirmations = [];
        foreach ($this->membres($groupe) as $joueur) {
            $confirmations[(string) $joueur->id] = false;
        }

        if ($confirmations === []) {
            throw ValidationException::withMessages([
                'groupe' => 'Aucun joueur membre : rien à clôturer (le groupe vide se purge au départ).',
            ]);
        }

        $phase = [
            'issue' => $issue,
            'or_a_partager' => $or,
            'reassignations' => [],
            'confirmations' => $confirmations,
        ];

        Cache::put(self::cle($groupe->id), $phase, now()->addMinutes(self::TTL_MINUTES));

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'cloture_ouverte',
            'issue' => $issue,
            'or_a_partager' => $or,
        ]);

        $etat = $this->payload($groupe, $phase);
        broadcast(new ClotureOuverte($groupe, $etat));

        return $etat;
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseOuverte(Groupe $groupe): array
    {
        $phase = Cache::get(self::cle($groupe->id));

        if (! is_array($phase)) {
            throw ValidationException::withMessages([
                'groupe' => 'Aucune fenêtre de clôture ouverte pour ce groupe.',
            ]);
        }

        return $phase;
    }

    /**
     * Payload EtatCloture du contrat — parts et équipements recalculés en
     * direct (les réassignations en attente sont reflétées).
     *
     * @param  array<string, mixed>  $phase
     * @return array<string, mixed>
     */
    private function payload(Groupe $groupe, array $phase): array
    {
        $pseudos = Joueur::whereIn('id', array_keys($phase['confirmations']))->pluck('pseudo', 'id');

        return [
            'issue' => $phase['issue'],
            'or_a_partager' => (int) $phase['or_a_partager'],
            'parts' => $this->parts($groupe, (int) $phase['or_a_partager']),
            'equipements' => $this->equipements($groupe, $phase['reassignations']),
            'confirmations' => collect($phase['confirmations'])
                ->map(fn (bool $confirme, $joueurId) => [
                    'joueur_id' => (int) $joueurId,
                    'pseudo' => (string) ($pseudos[(int) $joueurId] ?? ''),
                    'confirme' => $confirme,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Équipements de TOUS les héros actifs, avec les réassignations en
     * attente appliquées (contrat EtatCloture).
     *
     * @param  array<string, int>  $reassignations  inventaire_id => personnage_id
     * @return list<array<string, mixed>>
     */
    private function equipements(Groupe $groupe, array $reassignations): array
    {
        return Inventaire::query()
            ->with('objet')
            ->whereIn('personnage_id', $this->herosActifs($groupe)->pluck('id'))
            ->orderBy('id')
            ->get()
            ->map(fn (Inventaire $ligne) => [
                'inventaire_id' => (int) $ligne->id,
                'nom' => $ligne->objet->nom,
                'categorie' => $ligne->objet->categorie,
                'rarete' => $ligne->objet->rarete,
                'image_url' => app(BibliothequeImages::class)->urlObjet($ligne->objet_id, $ligne->objet->nom),
                'personnage_id' => (int) ($reassignations[(string) $ligne->id] ?? $ligne->personnage_id),
            ])
            ->values()
            ->all();
    }

    /**
     * Héros actifs du groupe, par ordre d'initiative (les « premiers » du
     * reste de la répartition).
     *
     * @return Collection<int, Personnage>
     */
    private function herosActifs(Groupe $groupe): Collection
    {
        return $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->orderBy('personnages.id')
            ->get();
    }

    /**
     * Joueurs membres : ceux qui ont au moins un héros ACTIF dans le groupe.
     *
     * @return Collection<int, Joueur>
     */
    private function membres(Groupe $groupe): Collection
    {
        $ids = $groupe->personnages()
            ->wherePivot('actif', true)
            ->pluck('joueur_id')
            ->unique();

        return Joueur::whereIn('id', $ids)->orderBy('id')->get();
    }
}
