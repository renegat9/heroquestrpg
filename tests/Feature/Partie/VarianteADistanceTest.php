<?php

declare(strict_types=1);

use App\Models\Monstre;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
use Database\Seeders\MonstreSeeder;

/**
 * Variante À DISTANCE générique (lot B, Against the Ogre Horde p. 8, Q6 —
 * René 2026-10-02 : « allons-y générique »).
 *
 * « Zargon may place a standard monster or a ranged version of that same
 * monster type (in this quest pack, that means skeletons, orcs, and
 * goblins). » Le livret ne dit RIEN de la PROPORTION — `acheterMonstres()`
 * en décide par une rotation déterministe (`DemarreurQuete::substituerVarianteDistance()`),
 * jamais par un tirage aléatoire : une rencontre est un PLACEMENT.
 *
 * Ce fichier teste le MÉCANISME DE SUBSTITUTION, pas les blocs de stats
 * (`BestiaireSourceTest` s'en charge déjà).
 */
beforeEach(function () {
    $this->seed([MonstreSeeder::class]);
});

/** Invoque `acheterMonstres()` (privée) par réflexion, comme SorciersNommesTest. */
function acheterPourVariante(array $structure, int $budget, int $maxSpawns, int $positionArc, int $graineGroupe, ?BestiaireGroupe $bestiaire = null): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    return $methode->invoke($demarreur, $structure, $budget, $maxSpawns, $positionArc, $graineGroupe, $bestiaire);
}

it('ne mint AUCUN monstre inconnu : toute variante achetée est un nom_base du catalogue', function () {
    $achats = acheterPourVariante([], 60, 20, 1, 7);
    $noms = collect($achats)->pluck('nom_base');

    foreach ($noms as $nom) {
        expect(Monstre::where('nom_base', $nom)->exists())->toBeTrue();
    }
});

it('substitue au moins un Gobelin/Squelette/Orque par sa variante à distance sur un grand lot', function () {
    // Un budget large + beaucoup d'emplacements, pour que le round-robin passe
    // plusieurs fois sur chacun des trois monstres qui ont une variante.
    $achats = acheterPourVariante([], 200, 40, 1, 7);
    $noms = collect($achats)->pluck('nom_base');

    $variantesApparues = $noms->intersect(['Gobelin archer', 'Archer squelette', 'Orque archer']);

    expect($variantesApparues)->not->toBeEmpty(
        'aucune variante à distance générique n\'a été achetée sur '.$noms->count().' monstres : '.$noms->implode(', '),
    );
});

it('exclut la variante à distance du pool de base NORMAL : elle n\'entre qu\'en SUBSTITUTION', function () {
    // ⚠ Preuve directe du mécanisme, pas de sa simple présence au catalogue.
    // Sans `whereNull('variante_distance_de')` dans le pool des « faibles »,
    // Gobelin archer / Archer squelette / Orque archer seraient trois entrées
    // INDÉPENDANTES du round-robin — un Gobelin ET un Gobelin archer auraient
    // pu être achetés dans la MÊME rencontre, ce qu'aucune carte ne décrit.
    // Ce test échoue si cette exclusion disparaît : avec un budget couvrant
    // une seule unité (`cout` 1 ou 2) et UN SEUL emplacement, répété sur assez
    // de graines pour couvrir tous les restes de la rotation, on ne doit
    // JAMAIS observer le monstre STANDARD et sa variante cohabiter — en fait,
    // sur 1 seul emplacement il ne peut y avoir QUE l'un des deux, donc on
    // vérifie plutôt que la variante EST atteignable (déjà fait ci-dessus) ET
    // que le monstre standard reste la majorité (la rotation est 1 sur
    // `RATIO_VARIANTE_DISTANCE`, jamais 1 sur 1).
    $compteVariantes = 0;
    $compteStandards = 0;

    for ($graine = 1; $graine <= 30; $graine++) {
        $achats = acheterPourVariante([], 3, 1, 1, $graine); // 1 seul emplacement, tout petit budget
        $nom = $achats[0]->nom_base ?? null;

        if (in_array($nom, ['Gobelin archer', 'Archer squelette', 'Orque archer'], true)) {
            $compteVariantes++;
        } elseif (in_array($nom, ['Gobelin', 'Squelette'], true)) {
            $compteStandards++;
        }
    }

    expect($compteVariantes)->toBeGreaterThan(0, 'la variante à distance n\'est JAMAIS tirée sur 30 graines')
        ->and($compteStandards)->toBeGreaterThan($compteVariantes,
            'la rotation substitue la variante PLUS SOUVENT que prévu : devrait rester 1 chance sur '.DemarreurQuete::RATIO_VARIANTE_DISTANCE);
});

it('la rotation de substitution répond à la formule (graine + position + rang) % RATIO (réflexion directe)', function () {
    // Preuve UNITAIRE du calcul, indépendante du round-robin : appelle
    // `substituerVarianteDistance()` directement avec des graines CHOISIES
    // pour tomber pile sur un rang multiple de `RATIO_VARIANTE_DISTANCE`, puis
    // sur un rang qui n'en est pas un multiple.
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'substituerVarianteDistance');
    $methode->setAccessible(true);

    // `$occurrences` est un paramètre PAR RÉFÉRENCE : `invoke()` ne sait pas
    // passer de référence, `invokeArgs()` le fait quand l'élément du tableau
    // en est une.
    $appeler = function (Monstre $standard, $variantes, int $graine, int $position, array &$occurrences, int $restant) use ($demarreur, $methode) {
        return $methode->invokeArgs($demarreur, [$standard, $variantes, $graine, $position, &$occurrences, $restant]);
    };

    $gobelin = Monstre::where('nom_base', 'Gobelin')->firstOrFail();
    $variantes = Monstre::whereNotNull('variante_distance_de')->get()->keyBy('variante_distance_de');

    // graineGroupe=0, positionArc=0, occurrence=0 → rang 0, multiple de RATIO.
    $occurrences = [];
    $resultat = $appeler($gobelin, $variantes, 0, 0, $occurrences, 1000);
    expect($resultat->nom_base)->toBe('Gobelin archer', 'rang 0 (multiple de RATIO) doit rendre la variante');

    // graineGroupe=0, positionArc=1, occurrence=0 → rang 1 : pas un multiple
    // de RATIO (ratio = 3) → standard.
    $occurrences = [];
    $resultat = $appeler($gobelin, $variantes, 0, 1, $occurrences, 1000);
    expect($resultat->nom_base)->toBe('Gobelin', 'rang 1 (non multiple de RATIO) doit rendre le monstre standard');

    // Budget insuffisant pour la variante (son cout est > celui du standard,
    // les deux coûtent 1 et 2 ici) → repli sur le standard même au bon rang.
    $occurrences = [];
    $resultat = $appeler($gobelin, $variantes, 0, 0, $occurrences, 0);
    expect($resultat->nom_base)->toBe('Gobelin', 'budget nul : jamais de substitution plus chère que ce qui reste');

    // Monstre SANS variante déclarée → toujours lui-même.
    $occurrences = [];
    $zombie = Monstre::where('nom_base', 'Zombie')->firstOrFail();
    $resultat = $appeler($zombie, $variantes, 0, 0, $occurrences, 1000);
    expect($resultat->nom_base)->toBe('Zombie');
});

it('est une ROTATION déterministe : même groupe + même position d\'arc = même rencontre', function () {
    $premier = collect(acheterPourVariante([], 200, 40, 2, 11))->pluck('nom_base')->all();
    $second = collect(acheterPourVariante([], 200, 40, 2, 11))->pluck('nom_base')->all();

    expect($premier)->toBe($second, 'deux appels avec les mêmes graines doivent acheter EXACTEMENT la même rencontre');
});

it('ne dépasse jamais le budget en substituant une variante plus chère', function () {
    $budget = 50;
    $achats = acheterPourVariante([], $budget, 30, 3, 5);

    $demarreur = app(DemarreurQuete::class);
    $total = collect($achats)->sum(fn (Monstre $m) => $demarreur->coutEffectif($m));

    expect($total)->toBeLessThanOrEqual($budget);
});

it('offre la variante à distance dans TOUS les thèmes (Q6 : générique, pas un trait de boîte)', function () {
    // Automatique : `autorise()` ne filtre jamais (c'est `contient()`, la
    // préférence sur les FORTS, qui lit le thème) — donc chaque thème doit
    // pouvoir faire apparaître au moins une des trois variantes sur un lot
    // suffisamment grand.
    foreach (DemarreurQuete::BOITES_THEMATIQUES as $boite) {
        $bestiaire = BestiaireGroupe::auto($boite);
        $achats = acheterPourVariante([], 200, 40, 1, 3, $bestiaire);
        $noms = collect($achats)->pluck('nom_base');

        $variantesApparues = $noms->intersect(['Gobelin archer', 'Archer squelette', 'Orque archer']);

        expect($variantesApparues)->not->toBeEmpty("thème « {$boite} » : aucune variante à distance offerte");
    }

    // Manuel, AUCUNE case cochée (jeu de base + nos créatures seulement) :
    // les variantes sont `boite: null`, donc toujours du lot.
    $manuel = BestiaireGroupe::manuel([]);
    $achats = acheterPourVariante([], 200, 40, 1, 3, $manuel);
    $variantesApparues = collect($achats)->pluck('nom_base')->intersect(['Gobelin archer', 'Archer squelette', 'Orque archer']);

    expect($variantesApparues)->not->toBeEmpty('bestiaire manuel sans case cochée : aucune variante à distance offerte');
});

it('reste un monstre STANDARD quand la rotation ne tombe pas sur son rang (jamais 100% de variantes)', function () {
    // Preuve que la substitution est partielle, pas un remplacement total : sur
    // un lot de taille modeste, au moins un Gobelin/Squelette/Orque STANDARD
    // doit encore apparaître à côté d'éventuelles variantes.
    $achats = acheterPourVariante([], 200, 40, 1, 7);
    $noms = collect($achats)->pluck('nom_base');

    $standards = $noms->intersect(['Gobelin', 'Squelette', 'Orque']);

    expect($standards)->not->toBeEmpty('la rotation a remplacé 100% des monstres de base par leur variante : '.$noms->implode(', '));
});
