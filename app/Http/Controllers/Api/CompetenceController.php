<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\EtatGroupeDiffuse;
use App\Http\Controllers\Controller;
use App\Models\Competence;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\EtatGroupe;
use App\Partie\MoteurSorts;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Arbres de compétences (doc 01 §6, contrat docs/contrat-api.md) :
 * catalogue + acquisition d'un nœud à la montée de niveau.
 *
 * Les points ne sont JAMAIS stockés : points = (niveau − 1) − nb de nœuds
 * acquis (Personnage::pointsCompetence). À l'acquisition, les effets
 * passifs CHIFFRÉS du nœud sont appliqués aux colonnes du personnage ;
 * les nœuds `actif`/`deblocage` (et les passifs conditionnels ou lus à la
 * volée, comme l'Œil du mineur par MoteurPieges) sont seulement enregistrés
 * au pivot personnage_competences.
 */
class CompetenceController extends Controller
{
    /**
     * Mapping des effets passifs chiffrés du CompetenceSeeder vers les
     * colonnes de `personnages` : `effet.mecanique` → colonne, incrémentée
     * de `effet.valeur` (+n). Couvre les clés du contrat
     * (attribut_body/attribut_mind/des_attaque/des_defense/pv_body_max/
     * pv_mind_max/deplacement_base) ; les PV courants suivent le maximum.
     *
     * Cas particuliers documentés :
     *  - `bonus_capacite_sac` (Solides épaules) n'a PAS de colonne : dérivé
     *    par App\Partie\Marche\CapaciteSac à chaque calcul de capacité ;
     *  - un passif portant une `condition` (Garde tenace, Frénésie…) n'est
     *    pas un bonus permanent : enregistré seulement, résolu en situation ;
     *  - les mécaniques non chiffrées (avantage_jet_mind,
     *    detection_pieges_adjacents…) sont lues à la volée par le moteur.
     */
    private const EFFETS_PASSIFS = [
        'bonus_attribut_body' => 'attribut_body',
        'bonus_attribut_mind' => 'attribut_mind',
        'bonus_des_attaque' => 'des_attaque',
        'bonus_des_defense' => 'des_defense',
        'bonus_pv_body_max' => 'pv_body_max',
        'bonus_pv_mind_max' => 'pv_mind_max',
        'bonus_deplacement' => 'deplacement_base',
    ];

    /** GET /api/competences — catalogue complet des arbres (contrat). */
    public function catalogue(): JsonResponse
    {
        return response()->json([
            'competences' => Competence::query()
                ->orderBy('classe')
                ->orderBy('id')
                ->get(['id', 'classe', 'nom', 'type', 'effet', 'prerequis_id'])
                ->map(fn (Competence $c) => [
                    'id' => $c->id,
                    'classe' => $c->classe,
                    'nom' => $c->nom,
                    'type' => $c->type,
                    'effet' => $c->effet,
                    'prerequis_id' => $c->prerequis_id,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * POST /api/groupes/{identifiant}/competences — acquiert un nœud d'arbre
     * (contrat) : héros du joueur connecté actif dans CE groupe, classe du
     * nœud = classe du héros, prérequis acquis, point disponible, pas déjà
     * acquis — sinon 422. Rediffuse `.groupe.etat` si le groupe est en quête.
     *
     * Nœud `emplacement_element` (Première magie / Second élément de l'Elfe,
     * Écoles du Magicien — doc 02 §2-3) : `element` choisit l'élément
     * débloqué (défaut eau ; 422 s'il est déjà connu) → les 3 sorts de
     * l'élément sont attachés au héros (MoteurSorts).
     */
    public function acquerir(Request $request, string $identifiant, EtatGroupe $etatGroupe, MoteurSorts $sorts): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'personnage_id' => ['required', 'integer'],
            'competence_id' => ['required', 'integer', Rule::exists('competences', 'id')],
            'element' => ['sometimes', 'string', Rule::in(MoteurSorts::ELEMENTS)],
        ]);

        $personnage = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $donnees['personnage_id'])
            ->where('joueur_id', $joueur->id)
            ->first();

        if ($personnage === null) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        $competence = Competence::findOrFail($donnees['competence_id']);

        if ($competence->classe !== $personnage->classe) {
            throw ValidationException::withMessages([
                'competence_id' => "Ce nœud appartient à l'arbre {$competence->classe}, pas à celui du {$personnage->classe}.",
            ]);
        }

        if ($personnage->competences()->whereKey($competence->id)->exists()) {
            throw ValidationException::withMessages([
                'competence_id' => 'Ce nœud est déjà acquis par ce héros.',
            ]);
        }

        if ($competence->prerequis_id !== null
            && ! $personnage->competences()->whereKey($competence->prerequis_id)->exists()) {
            throw ValidationException::withMessages([
                'competence_id' => 'Prérequis manquant : acquérez d\'abord le nœud parent de l\'arbre.',
            ]);
        }

        if ($personnage->pointsCompetence() < 1) {
            throw ValidationException::withMessages([
                'competence_id' => 'Aucun point de compétence disponible (1 par niveau gagné).',
            ]);
        }

        $elementAttache = null;

        // Nœud de déblocage d'élément : valider l'élément AVANT d'attacher
        // quoi que ce soit (422 → rien n'est acquis, transaction).
        if (($competence->effet['mecanique'] ?? null) === MoteurSorts::MECANIQUE_ELEMENT) {
            $elementAttache = $donnees['element'] ?? MoteurSorts::ELEMENT_DEFAUT;

            if (in_array($elementAttache, $sorts->elementsConnus($personnage), true)) {
                throw ValidationException::withMessages([
                    'element' => "L'élément {$elementAttache} est déjà connu de ce héros : choisissez-en un autre.",
                ]);
            }
        }

        DB::transaction(function () use ($personnage, $competence, $sorts, $elementAttache) {
            $personnage->competences()->attach($competence->id);
            $this->appliquerEffetsPassifs($personnage, $competence);

            if ($elementAttache !== null) {
                $sorts->attacherElement($personnage, $elementAttache);
            }
        });

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'competence_acquise',
            'personnage_id' => $personnage->id,
            'competence_id' => $competence->id,
            'nom' => $competence->nom,
            'type' => $competence->type,
            'element' => $elementAttache,
        ], ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom]);

        // En quête, le nouveau profil du héros (PV max, déplacement…) doit
        // arriver à la table : rediffusion de l'état partagé (contrat).
        if ($groupe->phase === 'quete') {
            broadcast(new EtatGroupeDiffuse($groupe, $etatGroupe->payload($groupe->fresh())));
        }

        $personnage->refresh();

        return response()->json([
            'personnage' => [
                'id' => $personnage->id,
                'nom' => $personnage->nom,
                'niveau' => (int) $personnage->niveau,
                'points_competence' => $personnage->pointsCompetence(),
                'competences' => $personnage->competences()->pluck('competences.id')->all(),
            ],
            'competence' => [
                'id' => $competence->id,
                'nom' => $competence->nom,
                'type' => $competence->type,
                'element' => $elementAttache,
            ],
        ], 201);
    }

    /**
     * Applique les effets passifs CHIFFRÉS d'un nœud aux colonnes du
     * personnage (mapping EFFETS_PASSIFS) ; tout le reste — actif,
     * deblocage, passif conditionnel ou non chiffré — est sans effet ici.
     */
    private function appliquerEffetsPassifs(Personnage $personnage, Competence $competence): void
    {
        if ($competence->type !== 'passif' || isset($competence->effet['condition'])) {
            return;
        }

        $colonne = self::EFFETS_PASSIFS[$competence->effet['mecanique'] ?? ''] ?? null;
        $valeur = (int) ($competence->effet['valeur'] ?? 0);

        if ($colonne === null || $valeur === 0) {
            return;
        }

        $attributs = [$colonne => (int) $personnage->{$colonne} + $valeur];

        // Les PV courants suivent l'augmentation du maximum (comme MonteeNiveau).
        if ($colonne === 'pv_body_max') {
            $attributs['pv_body'] = (int) $personnage->pv_body + $valeur;
        }
        if ($colonne === 'pv_mind_max') {
            $attributs['pv_mind'] = (int) $personnage->pv_mind + $valeur;
        }

        $personnage->update($attributs);
    }
}
