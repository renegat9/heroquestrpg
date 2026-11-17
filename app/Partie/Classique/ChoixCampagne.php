<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\CampagneConstruite;
use App\Models\Groupe;
use App\Support\Journal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MODE CLASSIQUE — LE CHOIX DE CAMPAGNE AU HUB (fin du lot S1, `docs/plan-mode-classique.md` §6 et §7).
 *
 * Un groupe classique n'est jamais dissous : finir une campagne le ramène au choix de campagne, héros, or et équipement
 * conservés. Une campagne dont le groupe n'a pas une classe exigée (`prerequis_heros`) est montrée GRISÉE avec sa
 * raison, et refusée par la route — la liste et la route lisent la même règle.
 *
 * Point de passage unique de trois décisions : les classes exigées qui manquent au groupe (relues par
 * `DemarreurQueteConstruite::refusDeDepart()`), quand le choix est ouvert, et le choix lui-même.
 *
 * Décision écrite : le choix n'est ouvert que si la campagne du groupe est FINIE, PAS COMMENCÉE (`rang_quete = 1`) ou
 * DISPARUE. Quitter une campagne en cours perdrait sa progression d'un geste — on ne l'offre pas.
 */
final class ChoixCampagne
{
    public function __construct(private readonly CatalogueConstructeur $catalogue) {}

    /**
     * Les classes que `$campagne` exige et qu'aucun héros ACTIF du groupe n'a, par slug.
     *
     * @return list<string>
     */
    public function classesManquantes(Groupe $groupe, CampagneConstruite $campagne): array
    {
        $exigees = array_map('strval', (array) ($campagne->document['prerequis_heros'] ?? []));
        if ($exigees === []) {
            return [];
        }

        $presentes = $groupe->personnages()->wherePivot('actif', true)->pluck('classe')
            ->map(fn ($classe) => CatalogueConstructeur::slug((string) $classe))->all();

        return array_values(array_diff($exigees, $presentes));
    }

    /** « Exige un héros de classe : Barbare, Nain. », ou `null` si le groupe a toutes les classes exigées. */
    public function motifPrerequis(Groupe $groupe, CampagneConstruite $campagne): ?string
    {
        $manquantes = $this->classesManquantes($groupe, $campagne);
        if ($manquantes === []) {
            return null;
        }

        $noms = collect($manquantes)->map(fn (string $slug) => $this->nomClasse($slug))->implode(', ');

        return 'Exige un héros de classe : '.$noms.'.';
    }

    /** La campagne du groupe est-elle jouée en entier ? */
    public function terminee(Groupe $groupe): bool
    {
        $campagne = $groupe->campagneConstruite;

        return $campagne !== null && (int) $groupe->rang_quete > count((array) ($campagne->document['quetes'] ?? []));
    }

    /** Pourquoi ce groupe ne peut pas choisir de campagne maintenant, ou `null` si le choix est ouvert. */
    public function motifFermeture(Groupe $groupe): ?string
    {
        if (! $groupe->estClassique()) {
            return 'Seul un groupe classique choisit une campagne construite.';
        }
        if ($groupe->phase !== 'hub') {
            return 'On ne change de campagne qu\'au hub, entre deux quêtes.';
        }
        if ($groupe->campagneConstruite === null || (int) $groupe->rang_quete <= 1 || $this->terminee($groupe)) {
            return null;
        }

        return 'La campagne « '.$groupe->campagneConstruite->titre.' » est en cours : finissez-la avant d\'en choisir une autre.';
    }

    /** Pourquoi `$campagne` est grisée pour ce groupe, ou `null` si elle est choisissable. */
    public function motifCampagne(Groupe $groupe, CampagneConstruite $campagne): ?string
    {
        if (count((array) ($campagne->document['quetes'] ?? [])) === 0) {
            return 'Aucune quête : rien à jouer.';
        }

        return $this->motifPrerequis($groupe, $campagne);
    }

    /**
     * La liste publiée par `GET /groupes/{id}/campagnes` : chaque campagne avec sa DÉCISION.
     *
     * @return array{choix_ouvert: bool, choix_motif: ?string, campagnes: list<array<string, mixed>>}
     */
    public function publier(Groupe $groupe): array
    {
        $fermeture = $this->motifFermeture($groupe);
        $actuelle = (int) $groupe->campagne_construite_id;

        $campagnes = CampagneConstruite::query()->orderBy('titre')->get()
            ->map(function (CampagneConstruite $campagne) use ($groupe, $actuelle): array {
                $motif = $this->motifCampagne($groupe, $campagne);

                return [
                    'slug' => $campagne->slug,
                    'titre' => $campagne->titre,
                    'auteur' => $campagne->auteur,
                    'nb_quetes' => count((array) ($campagne->document['quetes'] ?? [])),
                    'prerequis_heros' => array_map(
                        fn (string $slug): array => ['slug' => $slug, 'nom' => $this->nomClasse($slug)],
                        array_values(array_map('strval', (array) ($campagne->document['prerequis_heros'] ?? []))),
                    ),
                    'actuelle' => (int) $campagne->id === $actuelle,
                    'choisissable' => $motif === null,
                    'motif' => $motif,
                ];
            })
            ->values()
            ->all();

        return ['choix_ouvert' => $fermeture === null, 'choix_motif' => $fermeture, 'campagnes' => $campagnes];
    }

    /** `groupe.classique` du hub (`EtatGroupe`) : où en est le groupe, et si le choix est ouvert. */
    public function resumeHub(Groupe $groupe): array
    {
        $campagne = $groupe->campagneConstruite;
        $quetes = (array) ($campagne?->document['quetes'] ?? []);
        $rang = (int) $groupe->rang_quete;
        $fermeture = $this->motifFermeture($groupe);

        return [
            'campagne' => $campagne === null ? null : ['slug' => $campagne->slug, 'titre' => $campagne->titre],
            'rang_quete' => $rang,
            'nb_quetes' => count($quetes),
            'prochaine_quete' => isset($quetes[$rang - 1]['titre']) ? (string) $quetes[$rang - 1]['titre'] : null,
            'terminee' => $this->terminee($groupe),
            'choix_ouvert' => $fermeture === null,
            'choix_motif' => $fermeture,
        ];
    }

    /** LE CHOIX : le groupe repart à la quête 1 de `$slug`. Refusé (422) si le choix est fermé ou la campagne grisée. */
    public function choisir(Groupe $groupe, string $slug): Groupe
    {
        if (($fermeture = $this->motifFermeture($groupe)) !== null) {
            throw ValidationException::withMessages(['campagne' => $fermeture]);
        }

        $campagne = CampagneConstruite::where('slug', $slug)->first();
        if ($campagne === null) {
            throw ValidationException::withMessages(['campagne' => "Aucune campagne construite « {$slug} »."]);
        }
        if (($motif = $this->motifCampagne($groupe, $campagne)) !== null) {
            throw ValidationException::withMessages(['campagne' => "« {$campagne->titre} » : {$motif}"]);
        }

        DB::transaction(function () use ($groupe, $campagne): void {
            $groupe->update([
                'campagne_construite_id' => $campagne->id,
                'rang_quete' => 1,
                // `theme` porte le titre affiché au hub, `nb_quetes_total` le nombre réel de quêtes (`GroupesClassiques`).
                'theme' => $campagne->titre,
                'nb_quetes_total' => count((array) ($campagne->document['quetes'] ?? [])),
                // Lot K5h : les drapeaux de campagne appartiennent à une partie de CETTE campagne.
                'drapeaux_campagne' => null,
            ]);

            Journal::ajouter($groupe, 'systeme', [
                'action' => 'campagne_choisie',
                'campagne_construite' => $campagne->slug,
                'titre' => $campagne->titre,
            ]);
        });

        // Un « prêt » donné pour l'ancienne campagne ne vaut pas pour la nouvelle (même clé que `POST /pret`).
        Cache::forget("partie:pret:{$groupe->id}");

        return $groupe->refresh();
    }

    /** « Barbare » : le nom de catalogue de la classe (un slug disparu du catalogue garde son slug), capitale en tête. */
    private function nomClasse(string $slug): string
    {
        return \Illuminate\Support\Str::ucfirst((string) ($this->catalogue->index('classes')[$slug]->nom ?? $slug));
    }
}
