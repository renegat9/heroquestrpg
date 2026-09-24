<?php

declare(strict_types=1);

/*
 * Script de MESURE (René, 2026-09-18 : « pour les salles coffres, on
 * pourrait limiter les grosseurs de salle possible »), pas un test de
 * comportement isolé : il rejoue, sur un GRAND échantillon de cartes, le
 * même câblage que `DemarreurQuete::demarrer()` (la fermeture passée à
 * `AssembleurCarte::assembler()` construit le deck de fouille ET désigne les
 * salles-coffre AVANT que le mobilier ne soit posé), puis compte combien de
 * salles ainsi désignées portent RÉELLEMENT un `Coffre` sur la carte.
 *
 * Mesuré par René en partie, AVANT tout correctif : 123 salles-au-coffre sur
 * 60 cartes, 112 servies (91 %), 11 renoncements — la salle désignée était
 * trop exiguë pour porter son coffre sans passer sous le plancher de cases
 * jouables (§2.12 ter). La mesure ci-dessous vise 100 %, ou explique
 * précisément pourquoi le reste est irréductible.
 */

use App\Models\GabaritQuete;
use App\Models\Mobilier;
use App\Partie\AssembleurCarte;
use App\Partie\Fouille\DeckFouille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;

beforeEach(function () {
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class]);
});

it('mesure le service des salles-au-coffre sur un grand échantillon de cartes', function () {
    $assembleur = app(AssembleurCarte::class);
    $deck = app(DeckFouille::class);
    $coffreId = Mobilier::where('nom', 'Coffre')->firstOrFail()->id;
    $groupe = creerGroupe();

    $gabarits = GabaritQuete::query()->orderBy('id')->get();
    expect($gabarits)->not->toBeEmpty();

    $nbCartes = 90; // « au moins 60 cartes » demandé — marge pour absorber les gabarits sans salle-coffre

    $demandees = 0;
    $servies = 0;
    $manquantes = [];
    $formesDemandees = [];
    $formesTouteCarte = [];

    foreach (range(1, $nbCartes) as $n) {
        $gabarit = $gabarits[$n % $gabarits->count()];
        $graine = 900000 + $n * 7919;

        // MÊME câblage que `DemarreurQuete::demarrer()` : la fermeture construit
        // le deck (donc désigne salle_artefact + salles_coffre) ENTRE la pose
        // des portes et celle du mobilier — jamais après coup.
        $fouille = null;
        $carte = $assembleur->assembler(
            $gabarit, $graine, 60, null,
            function (array $partielle) use ($gabarit, $groupe, $n, $deck, &$fouille): array {
                $fouille = $deck->construire($gabarit, $partielle, $groupe, $n);

                return $fouille['salles_coffre'];
            },
        );

        foreach ($carte['salles'] as $s) {
            $formesTouteCarte["{$s['largeur']}x{$s['hauteur']}"] = true;
        }

        foreach (array_unique($fouille['salles_coffre'] ?? []) as $salle) {
            $demandees++;
            $s = $carte['salles'][$salle];
            $forme = "{$s['largeur']}x{$s['hauteur']}";
            $formesDemandees[$forme] = ($formesDemandees[$forme] ?? 0) + 1;

            $aUnCoffre = collect($carte['mobilier'] ?? [])->contains(
                fn (array $m) => (int) $m['salle'] === $salle && (int) $m['mobilier_id'] === $coffreId,
            );

            if ($aUnCoffre) {
                $servies++;
            } else {
                $manquantes[] = "carte {$n} (graine {$graine}), salle {$salle}, forme {$forme}, "
                    .'aire='.((int) $s['largeur'] - 2) * ((int) $s['hauteur'] - 2);
            }
        }
    }

    dump([
        'cartes' => $nbCartes,
        'salles_coffre_demandees' => $demandees,
        'salles_coffre_servies' => $servies,
        'taux' => $demandees > 0 ? round($servies / $demandees * 100, 1).'%' : 'n/a',
        'formes_des_salles_coffre' => $formesDemandees,
        'formes_distinctes_sur_TOUTES_les_cartes' => count($formesTouteCarte),
        'manquantes' => $manquantes,
    ]);

    expect($demandees)->toBeGreaterThan(60)
        // La garantie visée par ce brief : 100 %, pas « la plupart ». Le
        // détail de `manquantes` (vide ci-dessus si le test est vert) nomme
        // la salle fautive plutôt que de laisser un pourcentage muet.
        ->and($manquantes)->toBeEmpty()
        ->and($servies)->toBe($demandees);
});
