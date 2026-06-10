<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Exceptions\AppelLlmException;
use App\Agent\Exceptions\SortieInvalideException;
use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\DetailQuete;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Quete;
use App\Support\Journal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Job IA : détail jouable de la prochaine quête, juste-à-temps au hub
 * (doc 06 §2, Q10).
 *
 * Le MOTEUR fixe la difficulté : ce job calcule le score de puissance du
 * groupe → budget de rencontres, choisit le gabarit du jalon, et fournit
 * le catalogue autorisé. Le skill DetailQuete ne fait que REMPLIR ce
 * budget (P3 / Q6). Le job persiste ensuite la quête + les instances de
 * monstres, bascule le groupe en phase « quete », journalise, diffuse
 * l'introduction et dispatch un menu par joueur.
 */
class GenererDetailQuete implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Coût de budget par défaut si un monstre n'a pas de coût renseigné. */
    private const COUT_DEFAUT = 1;

    public function __construct(public readonly int $groupeId) {}

    public function handle(DetailQuete $skill, ContexteAssembleur $assembleur): void
    {
        $groupe = Groupe::findOrFail($this->groupeId);

        broadcast(new MjReflechit($groupe, true));

        try {
            $positionArc = (int) $groupe->quetes()->count() + 1;
            $typeJalon = $this->typeJalon($groupe, $positionArc);
            $gabarit = $this->choisirGabarit($typeJalon);
            $budget = $this->budgetRencontres($groupe, $positionArc, $typeJalon);
            $catalogue = $this->catalogue();

            $contexte = $assembleur->assembler($groupe, requeteScene: $groupe->theme, extra: [
                'position_arc' => $positionArc,
                'type_jalon' => $typeJalon,
                'budget' => $budget,
                'gabarit' => $gabarit->structure,
                'catalogue' => $catalogue,
            ]);

            try {
                $detail = $skill->generer($contexte);
            } catch (AppelLlmException|SortieInvalideException $e) {
                // Repli silencieux (contrat : l'API ne dépend jamais du LLM) :
                // le démarrage MOTEUR (App\Partie\DemarreurQuete, POST quetes)
                // assemble une quête jouable sans habillage IA.
                Log::warning('Détail de quête IA indisponible — démarrage moteur seul (POST quetes).', [
                    'groupe_id' => $groupe->id,
                    'erreur' => $e->getMessage(),
                ]);

                return;
            }

            $quete = $this->persister($groupe, $gabarit, $detail, $positionArc, $typeJalon);

            Journal::ajouter($groupe, 'systeme', [
                'action' => 'quete_generee',
                'quete_id' => $quete->id,
                'titre' => $quete->titre,
                'position_arc' => $positionArc,
                'type_jalon' => $typeJalon,
                'budget' => $budget,
            ]);

            broadcast(new NarrationDiffusee($groupe, (string) $detail['introduction'], ambiance: 'mystere', queteId: $quete->id));

            // Premier menu de la quête pour chaque héros actif (canal privé).
            foreach ($groupe->personnages()->wherePivot('actif', true)->get() as $personnage) {
                GenererMenu::dispatch($groupe->id, (int) $personnage->joueur_id, (int) $personnage->id);
            }
        } finally {
            broadcast(new MjReflechit($groupe, false));
        }
    }

    /**
     * Type du jalon courant d'après le squelette (sinon quête normale).
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

        return 'normale';
    }

    private function choisirGabarit(string $typeJalon): GabaritQuete
    {
        return GabaritQuete::query()->where('type_jalon', $typeJalon)->inRandomOrder()->first()
            ?? GabaritQuete::query()->inRandomOrder()->first()
            ?? throw new RuntimeException('Aucun gabarit de quête en base — seeder les gabarits avant de générer.');
    }

    /**
     * Budget de rencontres = score de puissance du groupe × escalade d'arc
     * (doc 06 §2). Formule de DÉPART pour playtest (question ouverte 06 §10.5) :
     *   par héros actif : 2×niveau + dés d'attaque + dés de défense.
     */
    private function budgetRencontres(Groupe $groupe, int $positionArc, string $typeJalon): int
    {
        $puissance = 0;

        foreach ($groupe->personnages()->wherePivot('actif', true)->get() as $personnage) {
            $puissance += 2 * (int) $personnage->niveau
                + (int) $personnage->des_attaque
                + (int) $personnage->des_defense;
        }

        $puissance = max($puissance, 4); // plancher : groupe minimal

        $escalade = 1.0 + 0.15 * ($positionArc - 1); // montée vers les boss
        $facteurJalon = match ($typeJalon) {
            'sous_boss' => 1.25,
            'boss_final' => 1.5,
            default => 1.0,
        };

        return (int) round($puissance * $escalade * $facteurJalon);
    }

    /**
     * Catalogue autorisé fourni au skill : seules références utilisables
     * (doc 06 §5 — l'IA habille, ne crée jamais).
     *
     * @return array{monstres: list<array<string, mixed>>, objets: list<array<string, mixed>>}
     */
    private function catalogue(): array
    {
        return [
            'monstres' => Monstre::query()
                ->orderBy('tier')
                ->get(['id', 'nom_base', 'tier', 'cout'])
                ->map(fn (Monstre $m) => [
                    'id' => $m->id,
                    'nom_base' => $m->nom_base,
                    'tier' => $m->tier,
                    'cout' => (int) ($m->cout ?? self::COUT_DEFAUT),
                ])
                ->values()
                ->all(),
            'objets' => Objet::query()
                ->get(['id', 'nom', 'categorie', 'rarete'])
                ->map(fn (Objet $o) => [
                    'id' => $o->id,
                    'nom' => $o->nom,
                    'categorie' => $o->categorie,
                    'rarete' => $o->rarete,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Persiste la quête, ses instances de monstres (stats du catalogue,
     * habillage IA) et bascule le groupe en phase « quete ».
     *
     * @param  array<string, mixed>  $detail
     */
    private function persister(Groupe $groupe, GabaritQuete $gabarit, array $detail, int $positionArc, string $typeJalon): Quete
    {
        return DB::transaction(function () use ($groupe, $gabarit, $detail, $positionArc, $typeJalon) {
            $quete = Quete::create([
                'groupe_id' => $groupe->id,
                'gabarit_id' => $gabarit->id,
                'titre' => $detail['titre'],
                'position_arc' => $positionArc,
                'type_jalon' => $typeJalon,
                'branche_active' => null,
                'etat' => 'en_cours',
                'or_initial' => $groupe->or,
            ]);

            $monstres = Monstre::query()
                ->whereIn('id', array_map(fn ($r) => (int) $r['monstre_id'], $detail['rencontres']))
                ->get()
                ->keyBy('id');

            foreach ($detail['rencontres'] as $rencontre) {
                $monstre = $monstres[(int) $rencontre['monstre_id']];

                for ($i = 0; $i < (int) $rencontre['nombre']; $i++) {
                    InstanceMonstre::create([
                        'quete_id' => $quete->id,
                        'monstre_id' => $monstre->id,
                        'pv_body' => $monstre->pv_body, // stats du catalogue, jamais modifiées par l'IA
                        'pv_mind' => $monstre->pv_mind,
                        'etat' => 'actif',
                        'habillage' => [
                            'nom' => $rencontre['nom_habille'],
                            'description' => $rencontre['description_habillee'],
                        ],
                    ]);
                }
            }

            $groupe->update([
                'phase' => 'quete',
                'quete_courante_id' => $quete->id,
            ]);

            return $quete;
        });
    }
}
