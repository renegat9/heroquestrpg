<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Partie\AssembleurCarte;
use App\Partie\Grille;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LE PASSAGE SECRET ET SON COMPTEUR DE PITIÉ (René, 2026-08-24 puis 2026-08-27).
 *
 * Une salle peut n'avoir qu'un seul accès, secret — salle-objectif comprise.
 * L'invariant historique « jamais tributaire d'une porte secrète » est tombé :
 * sa justification (« un jet raté figerait le groupe ») est périmée depuis que
 * « Fouiller la zone » est offerte à chaque tour sans aucune limite, et depuis
 * que `battre_en_retraite` (2026-08-21) n'a aucune condition.
 *
 * ⚠ Ce fichier verrouille le DOSAGE, seul garde-fou encore utile :
 *  - au plus UNE salle cachée par donjon (`CouloirsTest` le tient aussi) ;
 *  - une carte sur deux, et non toutes — sans tirage, une feuille éligible
 *    existant toujours, la salle cachée tombait dans 40 donjons sur 40, et
 *    « il y a toujours un passage caché » serait devenu une règle apprise ;
 *  - un COMPTEUR DE PITIÉ, parce qu'un tirage à 50 % pur peut laisser une
 *    campagne entière sans le moindre passage — et une telle série ne se lit
 *    pas comme du hasard : le groupe conclut que la fonctionnalité n'existe pas.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, MobilierSeeder::class]);
});

/** Nombre de salles inatteignables sans franchir une porte secrète. */
function sallesCachees(array $carte): int
{
    $portes = array_map(function (array $p) {
        $p['etat'] = ($p['etat'] ?? '') === 'secrete' ? 'secrete' : 'ouverte';

        return $p;
    }, $carte['portes']);

    $grille = new Grille($carte['cases']);
    $grille->definirPortes($portes);

    $depart = $carte['spawn_heros'][0];
    $cachees = 0;

    foreach ($carte['salles'] as $salle) {
        if ($grille->chemin($depart['x'], $depart['y'], $salle['mediane_x'], $salle['mediane_y']) === null) {
            $cachees++;
        }
    }

    return $cachees;
}

it('la CHANCE pilote réellement le tirage : 0 % n\'en pose jamais, 100 % toujours', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();

    $poses = fn (int $chance) => collect(range(1, 20))
        ->filter(fn (int $i) => $assembleur->assembler($gabarit, $i * 977, $chance)['passage_secret'])
        ->count();

    // Les deux bornes prouvent que le paramètre est LU, là où une mesure autour
    // de 50 % ne distinguerait pas un tirage piloté d'un tirage libre.
    expect($poses(0))->toBe(0)
        ->and($poses(100))->toBe(20);
});

it('cache AU PLUS UNE salle, jamais la salle de départ, et environ une carte sur deux', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();

    $avecPassage = 0;
    $graines = 40;

    foreach (range(1, $graines) as $i) {
        $carte = $assembleur->assembler($gabarit, $i * 977, AssembleurCarte::CHANCE_PASSAGE_SECRET);
        $cachees = sallesCachees($carte);

        // ⚠ Une chaîne de passages cachés transformerait l'exploration en
        // ratissage : une salle au plus, toujours.
        expect($cachees)->toBeLessThanOrEqual(1, "graine {$i} : {$cachees} salles cachées");

        // `passage_secret` doit dire la VÉRITÉ sur la carte rendue : c'est lui
        // que `DemarreurQuete` croit pour tenir le compteur de pitié.
        expect($carte['passage_secret'])->toBe($cachees === 1, "graine {$i}");

        $avecPassage += $cachees;
    }

    // Autour de la moitié — une marge large, parce qu'on teste un dosage et non
    // la qualité du générateur de nombres.
    expect($avecPassage)->toBeGreaterThan((int) ($graines * 0.25))
        ->and($avecPassage)->toBeLessThan((int) ($graines * 0.75));
});

it('le COMPTEUR DE PITIÉ monte de 10 par carte sèche et retombe à 50 dès qu\'un passage tombe', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();

    $chance = AssembleurCarte::CHANCE_PASSAGE_SECRET;
    $serie = 0;
    $pireSerie = 0;

    // On rejoue à la main ce que `DemarreurQuete` fait entre deux quêtes.
    foreach (range(1, 120) as $i) {
        $carte = $assembleur->assembler($gabarit, $i * 7919, $chance);

        if ($carte['passage_secret']) {
            $serie = 0;
            $chance = AssembleurCarte::CHANCE_PASSAGE_SECRET;
        } else {
            $serie++;
            $pireSerie = max($pireSerie, $serie);
            $chance = min(100, $chance + AssembleurCarte::PALIER_PASSAGE_SECRET);
        }
    }

    // ⚠ Le PIRE cas est borné par construction : 50 → 60 → 70 → 80 → 90 → 100,
    // donc jamais plus de CINQ cartes sèches d'affilée. C'est tout l'objet du
    // compteur — sans lui, un tirage à 50 % pur peut sécher dix fois de suite.
    expect($pireSerie)->toBeLessThanOrEqual(5, "série sèche de {$pireSerie} cartes");
});

it('DemarreurQuete tient le compteur sur le GROUPE, quête après quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    // Une campagne neuve démarre au taux de base.
    expect((int) $groupe->fresh()->chance_passage_secret)->toBe(AssembleurCarte::CHANCE_PASSAGE_SECRET);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = App\Models\Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $cachee = sallesCachees($quete->carte->grille) === 1;

    // ⚠ Deux issues, une seule règle : réussi → retour à 50 ; sec → +10. C'est
    // l'écriture EN BASE qui compte, pas le tirage : la perdre remettrait la
    // campagne à 50 % en silence, ce que le compteur existe pour éviter.
    expect((int) $groupe->fresh()->chance_passage_secret)->toBe(
        $cachee
            ? AssembleurCarte::CHANCE_PASSAGE_SECRET
            : AssembleurCarte::CHANCE_PASSAGE_SECRET + AssembleurCarte::PALIER_PASSAGE_SECRET,
    );
});
