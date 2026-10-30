<?php

declare(strict_types=1);

use App\Engine\MotsClesPiege;
use App\Models\Piege;
use Database\Seeders\PiegeSeeder;

/*
 * REGISTRE DES CLÉS D'EFFET DES PIÈGES (`App\Engine\MotsClesPiege`), testé DANS LES DEUX SENS contre les lignes RÉELLES
 * (`PiegeSeeder` → `pieges.effet`, y compris les branches `aleatoire`) :
 *   - toute clé semée est déclarée (au vocabulaire, ou en dette nommée `SANS_LECTEUR`) ;
 *   - toute clé déclarée est semée, ou figure dans `SANS_DONNEE` avec sa raison ;
 *   - chaque lecteur nommé EXISTE, et la clé est écrite dans le fichier d'au moins un de ses lecteurs — un nom de méthode
 *     qui ne lit rien ne passe pas pour un lecteur ;
 *   - une dette `SANS_LECTEUR` reste réellement sans lecteur : si on la branche, le test le dit, et il faut la déplacer.
 */

beforeEach(function () {
    $this->seed([PiegeSeeder::class]);
});

/**
 * Toutes les clés d'effet semées sur les lignes de piège, branches `aleatoire` comprises.
 *
 * @return list<string>
 */
function clesSemeesSurLesPieges(): array
{
    $cles = [];

    foreach (Piege::query()->get() as $piege) {
        foreach ((array) $piege->effet as $cle => $valeur) {
            $cles[$cle] = true;

            if ($cle === 'aleatoire') {
                foreach ((array) $valeur as $branche) {
                    foreach (array_keys((array) $branche) as $sousCle) {
                        $cles[$sousCle] = true;
                    }
                }
            }
        }
    }

    return array_keys($cles);
}

it('REGISTRE (sens 1) : toute clé semée sur une ligne de piège est déclarée, au vocabulaire ou en dette nommée', function () {
    $nonDeclarees = array_values(array_filter(
        clesSemeesSurLesPieges(),
        fn (string $cle) => ! MotsClesPiege::connue($cle),
    ));

    expect($nonDeclarees)->toBe([]);
});

it('REGISTRE (sens 2) : toute clé déclarée est semée, ou figure dans SANS_DONNEE avec sa raison', function () {
    $semees = clesSemeesSurLesPieges();

    $fantomes = array_values(array_filter(
        array_keys(MotsClesPiege::VOCABULAIRE),
        fn (string $cle) => ! in_array($cle, $semees, true) && ! array_key_exists($cle, MotsClesPiege::SANS_DONNEE),
    ));

    expect($fantomes)->toBe([]);

    foreach (MotsClesPiege::SANS_DONNEE as $cle => $raison) {
        expect(in_array($cle, $semees, true))->toBeFalse()
            ->and(strlen($raison))->toBeGreaterThan(40);
    }
});

it('chaque lecteur nommé existe, et le PREMIER lecteur de la clé la LIT (data_get, effet[], issue[])', function () {
    foreach (MotsClesPiege::VOCABULAIRE as $cle => $entree) {
        $lecteurs = array_map('trim', explode('+', (string) $entree['lecteur']));

        expect($lecteurs)->not->toBe([], "la clé « {$cle} » n'a aucun lecteur nommé");

        $fichiers = [];

        foreach ($lecteurs as $lecteur) {
            [$classe, $methode] = explode('::', rtrim($lecteur, '()'));

            expect(class_exists($classe))->toBeTrue("« {$cle} » : la classe {$classe} n'existe pas")
                ->and(method_exists($classe, $methode))->toBeTrue("« {$cle} » : {$classe}::{$methode}() n'existe pas");

            $fichiers[] = (string) (new ReflectionClass($classe))->getFileName();
        }

        // Le PREMIER lecteur nommé est celui qui lit la clé, et son fichier doit la LIRE — pas seulement l'écrire : `sort_dread`
        // figure aussi comme clé de PAYLOAD (`$payload['sort_dread'] = …`) dans le même fichier, et un mot seul ne prouve rien.
        // Formes de lecture admises : `data_get(…, 'clé')`, `effet['clé']`, `issue['clé']`.
        $contenu = (string) file_get_contents($fichiers[0]);
        $lue = preg_match("/data_get\\([^;]*?'{$cle}'/", $contenu) === 1
            || str_contains($contenu, "effet['{$cle}']")
            || str_contains($contenu, "issue['{$cle}']");

        expect($lue)->toBeTrue(
            "« {$cle} » n'est pas LUE dans le fichier de son premier lecteur (".basename($fichiers[0]).')',
        );
    }
});

it('une dette SANS_LECTEUR est réelle : la clé est semée, sa raison est une phrase, et aucun lecteur de piège ne la lit', function () {
    $semees = clesSemeesSurLesPieges();
    $fichiersDeLecture = [
        app_path('Partie/MoteurPieges.php'),
        app_path('Partie/AssembleurCarte.php'),
        app_path('Partie/ResolveurTour.php'),
        app_path('Partie/MoteurMobilier.php'),
    ];

    foreach (MotsClesPiege::SANS_LECTEUR as $cle => $raison) {
        expect(in_array($cle, $semees, true))->toBeTrue("la dette « {$cle} » ne figure sur aucune ligne : elle serait fictive")
            ->and(strlen($raison))->toBeGreaterThan(40);

        foreach ($fichiersDeLecture as $fichier) {
            expect(str_contains((string) file_get_contents($fichier), "'{$cle}'"))
                ->toBeFalse("la clé « {$cle} » est lue dans " . basename($fichier) . " : sortez-la de SANS_LECTEUR");
        }
    }
});

it('le Piège de Boule de Flammes figure au registre, avec son lecteur de sort', function () {
    $entree = MotsClesPiege::VOCABULAIRE['sort_dread'];

    expect($entree['lecteur'])->toContain('App\Partie\MoteurDread::lancerSortDePiege()')
        ->and(Piege::where('nom', 'Piège de Boule de Flammes')->firstOrFail()->effet)->toHaveKey('sort_dread');
});
