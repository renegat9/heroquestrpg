<?php

declare(strict_types=1);

namespace App\Partie\Votes;

use App\Events\EtatGroupeDiffuse;
use App\Events\VoteLance;
use App\Events\VoteMaj;
use App\Events\VoteResultat;
use App\Models\Groupe;
use App\Models\Joueur;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use App\Support\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Votes de groupe (doc 05 §5, contrat docs/contrat-api.md) — UN SEUL vote
 * actif par groupe, en cache (+ journal). Types MVP :
 *
 *  - `retrait_joueur` : en quête seulement ; le joueur visé NE VOTE PAS ;
 *    majorité stricte requise, ÉGALITÉ = IL RESTE. S'il est retiré, il
 *    emporte sa part de l'or d'AVANT la quête (`quetes.or_initial` ÷ nb de
 *    joueurs membres) versée à son personnage, puis ses personnages sont
 *    détachés (groupe_actif_id null, pivot actif=false).
 *
 *  - `choix_groupe` : question + options libres (posées par le MJ ou un
 *    joueur) ; résolu à complétude des votants, MAJORITÉ SIMPLE. Choix
 *    documenté : en cas d'ÉGALITÉ, la PREMIÈRE option au décompte stable
 *    gagne (ordre de déclaration des options) — déterministe et sans
 *    relance, à raffiner en playtest si besoin.
 *
 * Hors quête, pas de vote : départ LIBRE au hub (part du pot commun ÷
 * membres présents).
 */
final class VoteGroupe
{
    /** Durée de vie d'un vote sans résolution (séance de jeu). */
    public const TTL_MINUTES = 360;

    public function __construct(private readonly EtatGroupe $etatGroupe) {}

    /** Clé du cache du vote actif d'un groupe. */
    public static function cle(int $groupeId): string
    {
        return "partie:vote:{$groupeId}";
    }

    /**
     * Lance un vote (POST votes). 422 si un vote est déjà actif.
     *
     * @param  array<string, mixed>  $donnees  {type, question?, options?, cible_joueur_id?}
     * @return array<string, mixed> vote (payload public)
     */
    public function lancer(Groupe $groupe, Joueur $joueur, array $donnees): array
    {
        if (Cache::has(self::cle($groupe->id))) {
            throw ValidationException::withMessages([
                'type' => 'Un vote est déjà en cours dans ce groupe.',
            ]);
        }

        $membres = $this->membres($groupe);

        $vote = match ($donnees['type']) {
            'retrait_joueur' => $this->preparerRetrait($groupe, $donnees, $membres),
            'choix_groupe' => $this->preparerChoix($donnees, $membres),
        };

        $vote['lance_par'] = (int) $joueur->id;
        $vote['bulletins'] = [];

        Cache::put(self::cle($groupe->id), $vote, now()->addMinutes(self::TTL_MINUTES));

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'vote_lance',
            'type' => $vote['type'],
            'question' => $vote['question'],
            'cible_joueur_id' => $vote['cible_joueur_id'],
            'lance_par' => $vote['lance_par'],
        ]);

        $payload = $this->payload($vote);
        broadcast(new VoteLance($groupe, ['vote' => $payload]));

        return $payload;
    }

    /**
     * Bulletin d'un joueur (POST bulletin) ; à COMPLÉTUDE des votants, le
     * vote est résolu.
     *
     * @return array<string, mixed> {decompte, exprimes, attendus, resultat?}
     */
    public function voter(Groupe $groupe, Joueur $joueur, string $optionId): array
    {
        $vote = Cache::get(self::cle($groupe->id));

        if (! is_array($vote)) {
            throw ValidationException::withMessages(['option_id' => 'Aucun vote en cours dans ce groupe.']);
        }

        if (! in_array((int) $joueur->id, $vote['votants'], true)) {
            throw ValidationException::withMessages([
                'option_id' => 'Vous ne participez pas à ce vote (le joueur visé ne vote pas).',
            ]);
        }

        if (! collect($vote['options'])->contains(fn ($o) => $o['id'] === $optionId)) {
            throw ValidationException::withMessages(['option_id' => 'Cette option ne fait pas partie du vote.']);
        }

        // Re-voter remplace le bulletin tant que le vote n'est pas résolu.
        $vote['bulletins'][(string) $joueur->id] = $optionId;

        $exprimes = count($vote['bulletins']);
        $attendus = count($vote['votants']);
        $decompte = $this->decompte($vote);

        $maj = ['decompte' => $decompte, 'exprimes' => $exprimes, 'attendus' => $attendus];

        if ($exprimes < $attendus) {
            Cache::put(self::cle($groupe->id), $vote, now()->addMinutes(self::TTL_MINUTES));
            broadcast(new VoteMaj($groupe, $maj));

            return [...$maj, 'resultat' => null];
        }

        // Complétude → résolution, puis le vote est clos quoi qu'il arrive.
        Cache::forget(self::cle($groupe->id));
        broadcast(new VoteMaj($groupe, $maj));

        $resultat = $this->resoudre($groupe, $vote, $decompte);

        return [...$maj, 'resultat' => $resultat];
    }

    /**
     * Vote actif (GET votes), ou null.
     *
     * @return array<string, mixed>|null
     */
    public function actif(Groupe $groupe): ?array
    {
        $vote = Cache::get(self::cle($groupe->id));

        return is_array($vote) ? $this->payload($vote) : null;
    }

    /**
     * Départ LIBRE entre les quêtes (POST depart, doc 05 §5) : pas de vote,
     * le joueur emporte sa part égale du pot commun (pot ÷ membres présents)
     * vers son personnage, qui retourne au roster.
     *
     * @return array{part: int}
     */
    public function departLibre(Groupe $groupe, Joueur $joueur): array
    {
        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'groupe' => 'En quête, le départ exige un vote de groupe (retrait_joueur).',
            ]);
        }

        $membres = $this->membres($groupe);

        if (! $membres->contains('id', $joueur->id)) {
            throw ValidationException::withMessages([
                'identifiant' => 'Vous n\'avez aucun héros actif dans ce groupe.',
            ]);
        }

        $part = intdiv((int) $groupe->or, max(1, $membres->count()));

        $this->detacherJoueur($groupe, (int) $joueur->id, $part);

        Journal::ajouter($groupe->fresh(), 'systeme', [
            'action' => 'depart_joueur',
            'joueur_id' => (int) $joueur->id,
            'part' => $part,
        ]);

        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe->fresh())));

        return ['part' => $part];
    }

    // ------------------------------------------------------------------
    // Préparation
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $donnees
     * @param  Collection<int, Joueur>  $membres
     * @return array<string, mixed>
     */
    private function preparerRetrait(Groupe $groupe, array $donnees, Collection $membres): array
    {
        if ($groupe->phase !== 'quete') {
            throw ValidationException::withMessages([
                'type' => 'Le retrait par vote n\'existe qu\'en quête — au hub, le départ est libre (POST depart).',
            ]);
        }

        $cible = $membres->firstWhere('id', (int) ($donnees['cible_joueur_id'] ?? 0));

        if ($cible === null) {
            throw ValidationException::withMessages([
                'cible_joueur_id' => 'Le joueur visé n\'est pas membre de ce groupe.',
            ]);
        }

        // Le joueur visé NE VOTE PAS (doc 05 §5).
        $votants = $membres->pluck('id')->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $cible->id)
            ->values()
            ->all();

        if ($votants === []) {
            throw ValidationException::withMessages([
                'cible_joueur_id' => 'Aucun autre joueur pour voter ce retrait.',
            ]);
        }

        return [
            'type' => 'retrait_joueur',
            'question' => $donnees['question'] ?? "Retirer {$cible->pseudo} du groupe ?",
            'options' => [
                ['id' => 'oui', 'libelle' => 'Retirer du groupe'],
                ['id' => 'non', 'libelle' => 'Garder dans le groupe'],
            ],
            'cible_joueur_id' => (int) $cible->id,
            'votants' => $votants,
        ];
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @param  Collection<int, Joueur>  $membres
     * @return array<string, mixed>
     */
    private function preparerChoix(array $donnees, Collection $membres): array
    {
        $options = collect($donnees['options'] ?? [])
            ->map(fn ($o) => ['id' => (string) $o['id'], 'libelle' => (string) $o['libelle']])
            ->values();

        if ($options->count() < 2 || $options->pluck('id')->unique()->count() !== $options->count()) {
            throw ValidationException::withMessages([
                'options' => 'Un choix de groupe exige au moins deux options aux identifiants distincts.',
            ]);
        }

        return [
            'type' => 'choix_groupe',
            'question' => (string) ($donnees['question'] ?? ''),
            'options' => $options->all(),
            'cible_joueur_id' => null,
            'votants' => $membres->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Résolution
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $vote
     * @param  array<string, int>  $decompte
     * @return array{option_id: string, applique: bool}
     */
    private function resoudre(Groupe $groupe, array $vote, array $decompte): array
    {
        if ($vote['type'] === 'retrait_joueur') {
            // Majorité STRICTE requise ; égalité = il reste (doc 05 §5).
            $applique = $decompte['oui'] > $decompte['non'];
            $resultat = ['option_id' => $applique ? 'oui' : 'non', 'applique' => $applique];

            $part = $applique ? $this->retirerJoueur($groupe, (int) $vote['cible_joueur_id']) : null;

            Journal::ajouter($groupe->fresh(), 'systeme', [
                'action' => 'vote_resultat',
                'type' => $vote['type'],
                'cible_joueur_id' => $vote['cible_joueur_id'],
                'decompte' => $decompte,
                'part' => $part,
                ...$resultat,
            ]);

            broadcast(new VoteResultat($groupe, $resultat));

            if ($applique) {
                broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe->fresh())));
            }

            return $resultat;
        }

        // choix_groupe : majorité simple ; égalité = PREMIÈRE option au
        // décompte stable (le décompte suit l'ordre de déclaration).
        $max = max($decompte);
        $gagnante = (string) array_search($max, $decompte, true);

        $resultat = ['option_id' => $gagnante, 'applique' => true];

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'vote_resultat',
            'type' => $vote['type'],
            'question' => $vote['question'],
            'decompte' => $decompte,
            ...$resultat,
        ]);

        broadcast(new VoteResultat($groupe, $resultat));

        return $resultat;
    }

    /**
     * Applique le retrait : part de l'or d'AVANT la quête (or_initial ÷ nb
     * de joueurs membres, cible comprise) versée au personnage du joueur
     * retiré, puis détachement de tous ses personnages du groupe.
     */
    private function retirerJoueur(Groupe $groupe, int $cibleJoueurId): int
    {
        $quete = $groupe->queteCourante;
        $membres = $this->membres($groupe)->count();

        $part = intdiv((int) ($quete?->or_initial ?? 0), max(1, $membres));

        // La bourse commune ne peut pas devenir négative (butin déjà dépensé) :
        // la part est plafonnée à l'or restant.
        $part = min($part, (int) $groupe->or);

        $this->detacherJoueur($groupe, $cibleJoueurId, $part, $quete?->id);

        return $part;
    }

    /**
     * Détache tous les personnages d'un joueur du groupe, en versant la part
     * d'or au premier (les personnages restent dans le roster du joueur,
     * doc 05 §5) — atomique.
     */
    private function detacherJoueur(Groupe $groupe, int $joueurId, int $part, ?int $queteId = null): void
    {
        DB::transaction(function () use ($groupe, $joueurId, $part, $queteId) {
            $personnages = $groupe->personnages()
                ->wherePivot('actif', true)
                ->where('joueur_id', $joueurId)
                ->orderBy('groupe_personnages.ordre_initiative')
                ->get();

            if ($part > 0 && $personnages->isNotEmpty()) {
                $personnages->first()->increment('or', $part);
                $groupe->decrement('or', min($part, (int) $groupe->or));
            }

            foreach ($personnages as $personnage) {
                $groupe->personnages()->updateExistingPivot($personnage->id, ['actif' => false]);
                $personnage->update(['groupe_actif_id' => null]);
            }

            // En quête : ses héros quittent le donjon (sinon le tour des
            // monstres attendrait des héros partis).
            if ($queteId !== null) {
                $groupe->queteCourante?->etatsPersonnages()
                    ->whereIn('personnage_id', $personnages->pluck('id'))
                    ->delete();
            }
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Décompte stable : les options dans leur ordre de déclaration.
     *
     * @param  array<string, mixed>  $vote
     * @return array<string, int>
     */
    private function decompte(array $vote): array
    {
        $decompte = [];

        foreach ($vote['options'] as $option) {
            $decompte[$option['id']] = 0;
        }

        foreach ($vote['bulletins'] as $optionId) {
            $decompte[$optionId]++;
        }

        return $decompte;
    }

    /**
     * Payload public du vote (sans le détail nominatif des bulletins).
     *
     * @param  array<string, mixed>  $vote
     * @return array<string, mixed>
     */
    private function payload(array $vote): array
    {
        return [
            'type' => $vote['type'],
            'question' => $vote['question'],
            'options' => $vote['options'],
            'cible_joueur_id' => $vote['cible_joueur_id'],
            'lance_par' => $vote['lance_par'] ?? null,
            'attendus' => count($vote['votants']),
            'exprimes' => count($vote['bulletins'] ?? []),
            'decompte' => $this->decompte($vote),
        ];
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
