<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\EtatGroupeDiffuse;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Jobs\GenererMenu;
use App\Jobs\GenererNarration;
use App\Engine\Des\LanceurDes;
use App\Jobs\HabillerMonstres;
use App\Partie\Narration\BibliothequeNarration;
use Illuminate\Support\Facades\Cache;
use App\Models\Carte;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
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
        private readonly BibliothequeNarration $narration,
        private readonly LanceurDes $des,
    ) {}

    /**
     * Variance élite (3.6) : à l'apparition, un monstre de BASE a une chance
     * fixe de devenir « élite » (bonus +1/+1/+1). Les sous-boss/boss ne sont
     * jamais élites (déjà calibrés par leur tier). Tirage via le lanceur
     * injectable → déterministe en test.
     */
    private function roulerElite(Monstre $monstre): bool
    {
        // Inactif (ou non-base) : ne consomme AUCUN dé → scénarios déterministes.
        if (! config('jeu.elite.actif') || ($monstre->tier ?? 'base') !== 'base') {
            return false;
        }

        return $this->des->d6() >= (int) config('jeu.elite.seuil_d6', 6);
    }

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
                $px = $carte['spawn_monstres'][$i]['x'];
                $py = $carte['spawn_monstres'][$i]['y'];

                $elite = $this->roulerElite($monstre);

                InstanceMonstre::create([
                    'quete_id' => $quete->id,
                    'monstre_id' => $monstre->id,
                    // Stats catalogue jamais altérées ; le +1 PV élite est porté par l'instance.
                    'pv_body' => $monstre->pv_body + ($elite ? InstanceMonstre::BONUS_ELITE : 0),
                    'pv_mind' => $monstre->pv_mind,
                    'position_x' => $px,
                    'position_y' => $py,
                    'etat' => 'actif',
                    'elite' => $elite,
                    // Dormant tant que sa salle n'est pas découverte ; les monstres
                    // de la salle de départ (rare) sont visibles d'emblée.
                    'revele' => $this->salleDe($carte['salles'] ?? [], $px, $py) === 0,
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

            // Alliés recrutés (3.5) : instanciés sur les cases de spawn restantes
            // après les héros (juste à côté du groupe), PV réinitialisés.
            $slot = $heros->count();
            foreach (GroupeMercenaire::where('groupe_id', $groupe->id)->with('mercenaire')->orderBy('id')->get() as $allie) {
                if (! isset($carte['spawn_heros'][$slot])) {
                    break; // pas de case de spawn libre : l'allié reste en réserve
                }

                $allie->update([
                    'pv_body' => (int) $allie->mercenaire->pv_body,
                    'position_x' => $carte['spawn_heros'][$slot]['x'],
                    'position_y' => $carte['spawn_heros'][$slot]['y'],
                    'etat' => 'actif',
                ]);
                $slot++;
            }

            $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

            return $quete;
        });

        // Usages de Dread réarmés pour cette nouvelle quête (MoteurDread).
        $this->dread->reinitialiserUsages($quete);

        // La salle de départ (où les héros apparaissent) est déjà « connue » :
        // elle est couverte par la narration de démarrage de quête. Les salles
        // suivantes seront décrites à la première entrée (ResolveurTour).
        Cache::put(ResolveurTour::cleSallesDecouvertes($quete->id), [0], now()->addMinutes(360));

        // Budget de monstres ERRANTS dédié (doc 14 §3.2) — distinct du budget de
        // rencontre : seul « Fouiller — trésor » le dépense. Aucune fouille de
        // trésor déjà faite.
        Cache::put(
            ResolveurTour::cleBudgetErrant($quete->id),
            (int) data_get($gabarit->structure, 'budget_errant', 0),
            now()->addMinutes(360),
        );

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

        // Cérémonie de lancement (tous prêts → c'est parti) : réplique scriptée
        // du narrateur, jouée IMMÉDIATEMENT (vraie voix si l'asset existe), AVANT
        // la narration d'ambiance de l'IA. Toujours disponible, sans LLM.
        $ceremonie = $this->narration->lancement();
        broadcast(new NarrationDiffusee(
            $groupe, $ceremonie['texte'],
            ambiance: $ceremonie['ambiance'], queteId: $quete->id, url: $ceremonie['url'],
        ));

        // Mise en récit + menus par jobs (repli garanti : l'API ne dépend pas du LLM).
        broadcast(new MjReflechit($groupe, true));
        GenererNarration::dispatch($groupe->id, [
            'type' => 'quete_demarree',
            'titre' => $quete->titre,
            'type_jalon' => $typeJalon,
        ]);

        // Habillage IA des monstres spawnés (Q6) : renomme/redécrit les
        // instances sans toucher aux stats — best effort, sans bloquer le jeu.
        HabillerMonstres::dispatch($groupe->id, $quete->id);

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

    /**
     * Index de la salle contenant (x, y) dans la liste des salles assemblées,
     * ou null (couloir). Sert à décider la visibilité initiale des monstres.
     *
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     */
    private function salleDe(array $salles, int $x, int $y): ?int
    {
        foreach ($salles as $i => $s) {
            if ($x >= (int) $s['x'] && $x < (int) $s['x'] + (int) $s['largeur']
                && $y >= (int) $s['y'] && $y < (int) $s['y'] + (int) $s['hauteur']) {
                return (int) $i;
            }
        }

        return null;
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
            // Indice optionnel (3.8) : un sorcier nommé désigné pour la rencontre
            // finale. Absent → comportement d'origine (leader de coût du tier).
            $archetypeFinal = data_get($structure, 'rencontre_finale.archetype');

            $final = null;
            if (is_string($archetypeFinal) && $archetypeFinal !== '') {
                $final = Monstre::query()
                    ->where('tier', $tierFinal)
                    ->where('archetype_lanceur', $archetypeFinal)
                    ->orderByDesc('cout')->orderBy('id')->first();
            }

            // Repli : archétype non demandé ou introuvable → leader de coût du tier.
            $final ??= Monstre::query()->where('tier', $tierFinal)->orderByDesc('cout')->orderBy('id')->first();

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
