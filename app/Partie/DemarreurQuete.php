<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\EtatGroupeDiffuse;
use App\Events\MjReflechit;
use App\Jobs\GenererMenu;
use App\Jobs\GenererNarration;
use App\Models\Carte;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\MoteurDread;
use App\Support\Journal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Démarrage de la quête suivante (POST /api/groupes/{identifiant}/quetes) —
 * entièrement MOTEUR, sans dépendre du LLM (le MJ IA habillera ensuite,
 * narration et menus arrivant par jobs avec repli garanti).
 *
 *  1. gabarit choisi selon le type de jalon (position dans l'arc, doc 06 §4 :
 *     jalon du squelette de campagne si présent, sinon boss_final à la
 *     dernière quête, normale ailleurs) ;
 *  2. carte assemblée depuis les tuiles (AssembleurCarte) ;
 *  3. monstres spawnés AU BUDGET : budget = score de puissance du groupe
 *     × escalade d'arc × facteur de jalon, dépensé en points de `cout` du
 *     bestiaire (doc 06 §2 — le moteur fixe la difficulté, P3) ;
 *  4. initiative figée pour toute la quête (C1) : héros dans l'ordre
 *     d'arrivée (pivot ordre_initiative renuméroté), monstres après ;
 *  5. etat_personnage_quete créé (positions de spawn, a_joue=false) ;
 *  6. sorts des héros actifs RÉINITIALISÉS (S5 : tout redevient disponible
 *     au démarrage d'une quête ; buffs de sorts purgés ; usage de
 *     Concentration réarmé — MoteurSorts) ;
 *  7. groupe passé en phase « quete », journal, broadcast `.groupe.etat`,
 *     dispatch GenererNarration + GenererMenu (un par héros actif).
 */
final class DemarreurQuete
{
    public function __construct(
        private readonly AssembleurCarte $assembleur,
        private readonly ScorePuissance $puissance,
        private readonly EtatGroupe $etatGroupe,
        private readonly MoteurSorts $sorts,
        private readonly MoteurDread $dread,
        private readonly Sauvegarde $sauvegarde,
    ) {}

    public function demarrer(Groupe $groupe): Quete
    {
        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'groupe' => 'Une quête est déjà en cours : terminez-la avant d\'en démarrer une autre.',
            ]);
        }

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->orderBy('personnages.id')
            ->get();

        if ($heros->isEmpty()) {
            throw ValidationException::withMessages([
                'groupe' => 'Aucun héros actif dans le groupe : impossible de démarrer une quête.',
            ]);
        }

        $positionArc = (int) $groupe->quetes()->count() + 1;
        $typeJalon = $this->typeJalon($groupe, $positionArc);
        $gabarit = $this->choisirGabarit($typeJalon);
        $carte = $this->assembleur->assembler($gabarit);
        $budget = $this->budgetRencontres($groupe, $positionArc, $typeJalon);
        $monstres = $this->acheterMonstres($gabarit->structure ?? [], $budget, count($carte['spawn_monstres']));

        if (count($carte['spawn_heros']) < $heros->count()) {
            throw new RuntimeException('Carte assemblée trop petite pour les héros du groupe.');
        }

        $quete = DB::transaction(function () use ($groupe, $heros, $gabarit, $carte, $monstres, $positionArc, $typeJalon) {
            $quete = Quete::create([
                'groupe_id' => $groupe->id,
                'gabarit_id' => $gabarit->id,
                'titre' => "Quête {$positionArc} — {$gabarit->nom}",
                'position_arc' => $positionArc,
                'type_jalon' => $typeJalon,
                'etat' => 'en_cours',
                'or_initial' => $groupe->or,
            ]);

            Carte::create([
                'quete_id' => $quete->id,
                'largeur' => $carte['largeur'],
                'hauteur' => $carte['hauteur'],
                'grille' => $carte,
            ]);

            foreach ($monstres as $i => $monstre) {
                InstanceMonstre::create([
                    'quete_id' => $quete->id,
                    'monstre_id' => $monstre->id,
                    'pv_body' => $monstre->pv_body, // stats du catalogue, jamais altérées
                    'pv_mind' => $monstre->pv_mind,
                    'position_x' => $carte['spawn_monstres'][$i]['x'],
                    'position_y' => $carte['spawn_monstres'][$i]['y'],
                    'etat' => 'actif',
                ]);
            }

            // Initiative figée pour toute la quête (C1) : renumérotation 1..n.
            foreach ($heros as $i => $personnage) {
                $groupe->personnages()->updateExistingPivot($personnage->id, ['ordre_initiative' => $i + 1]);

                // Récupération par quête (S5/S6) : sorts disponibles, buffs
                // de sorts purgés, Concentration réarmée.
                $this->sorts->reinitialiserQuete($groupe, $personnage);

                $quete->etatsPersonnages()->create([
                    'personnage_id' => $personnage->id,
                    'position_x' => $carte['spawn_heros'][$i]['x'],
                    'position_y' => $carte['spawn_heros'][$i]['y'],
                    'a_joue' => false,
                    'tombe' => false,
                ]);
            }

            $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

            return $quete;
        });

        // Usages de Dread réarmés pour cette nouvelle quête (MoteurDread).
        $this->dread->reinitialiserUsages($quete);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'quete_demarree',
            'quete_id' => $quete->id,
            'titre' => $quete->titre,
            'position_arc' => $positionArc,
            'type_jalon' => $typeJalon,
            'budget' => $budget,
            'nb_monstres' => count($monstres),
        ]);

        // Snapshot `debut_quete` (contrat « Snapshots & reprise ») : l'état
        // vivant complet, base du « recharger » après TPK (doc 05 §6).
        $this->sauvegarde->snapshotter($groupe->refresh(), Sauvegarde::ETIQUETTE_DEBUT_QUETE);

        // Toute mutation d'état → journal puis broadcast `.groupe.etat` (contrat).
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe)));

        // Mise en récit + menus par jobs (repli garanti : l'API ne dépend pas du LLM).
        broadcast(new MjReflechit($groupe, true));
        GenererNarration::dispatch($groupe->id, [
            'type' => 'quete_demarree',
            'titre' => $quete->titre,
            'type_jalon' => $typeJalon,
        ]);

        foreach ($heros as $personnage) {
            GenererMenu::dispatch($groupe->id, (int) $personnage->joueur_id, (int) $personnage->id);
        }

        return $quete;
    }

    /**
     * Type du jalon courant : squelette de campagne si présent, sinon
     * boss_final à la dernière quête de l'arc, normale ailleurs.
     */
    private function typeJalon(Groupe $groupe, int $positionArc): string
    {
        foreach ($groupe->plan_campagne['jalons'] ?? [] as $jalon) {
            if ((int) ($jalon['position'] ?? 0) === $positionArc) {
                return in_array($jalon['type'] ?? null, ['sous_boss', 'boss_final'], true)
                    ? $jalon['type']
                    : 'normale';
            }
        }

        return $positionArc >= (int) $groupe->nb_quetes_total ? 'boss_final' : 'normale';
    }

    private function choisirGabarit(string $typeJalon): GabaritQuete
    {
        return GabaritQuete::query()->where('type_jalon', $typeJalon)->orderBy('id')->first()
            ?? GabaritQuete::query()->orderBy('id')->first()
            ?? throw new RuntimeException('Aucun gabarit de quête en base — seeder les gabarits avant de démarrer.');
    }

    /**
     * Budget de rencontres en points de `cout` du bestiaire (doc 06 §2) :
     * score de puissance × escalade d'arc (+15 %/quête) × facteur de jalon.
     */
    private function budgetRencontres(Groupe $groupe, int $positionArc, string $typeJalon): int
    {
        $facteurJalon = match ($typeJalon) {
            'sous_boss' => 1.25,
            'boss_final' => 1.5,
            default => 1.0,
        };

        $escalade = 1.0 + 0.15 * ($positionArc - 1);

        return (int) round($this->puissance->calculer($groupe) * $escalade * $facteurJalon);
    }

    /**
     * Dépense le budget en monstres du catalogue : la rencontre finale du
     * gabarit (tier sous_boss/boss) est achetée d'abord — toujours présente,
     * même si elle dépasse le budget — puis le reste est rempli en glouton
     * (le monstre de base le plus cher encore abordable), dans la limite des
     * positions de spawn de la carte.
     *
     * @param  array<string, mixed>  $structure
     * @return list<Monstre>
     */
    private function acheterMonstres(array $structure, int $budget, int $maxSpawns): array
    {
        $achats = [];
        $restant = $budget;

        $tierFinal = data_get($structure, 'rencontre_finale.tier');
        if (is_string($tierFinal)) {
            $final = Monstre::query()->where('tier', $tierFinal)->orderByDesc('cout')->orderBy('id')->first();

            if ($final !== null) {
                $achats[] = $final;
                $restant = max(0, $restant - (int) $final->cout);
            }
        }

        /** @var Collection<int, Monstre> $base */
        $base = Monstre::query()->where('tier', 'base')->orderByDesc('cout')->orderBy('id')->get();

        while (count($achats) < $maxSpawns) {
            $monstre = $base->first(fn (Monstre $m) => (int) $m->cout <= $restant && (int) $m->cout > 0);

            if ($monstre === null) {
                break; // budget épuisé
            }

            $achats[] = $monstre;
            $restant -= (int) $monstre->cout;
        }

        if ($achats === []) {
            throw new RuntimeException('Bestiaire vide ou budget nul : aucune rencontre générée.');
        }

        return $achats;
    }
}
