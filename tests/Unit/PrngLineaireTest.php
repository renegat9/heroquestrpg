<?php

declare(strict_types=1);

use App\Partie\Aleatoire\PrngLineaire;

/**
 * Qualité du mélange (test de jeu 2026-08-05).
 *
 * Les bits de POIDS FAIBLE d'un LCG à module 2^31 sont périodiques : `% 2`
 * donne `010101…`, `% 4` donne `0123 0123…`. `melanger()` tirait son indice
 * ainsi, si bien que le mélange laissait la TÊTE de liste quasi intacte — là
 * où elle compte : première carte de fouille du donjon, premiers pièges placés.
 *
 * Ces tests portent sur la DISTRIBUTION, pas sur une valeur figée : le PRNG
 * reste déterministe à graine égale (reproductibilité des cartes et des
 * snapshots), mais le résultat doit ressembler à un tirage.
 */
describe('PrngLineaire — qualité du mélange', function () {

    it('reste déterministe à graine égale', function () {
        $items = range(1, 24);

        expect((new PrngLineaire(4242))->melanger($items))
            ->toBe((new PrngLineaire(4242))->melanger($items));
    });

    it('conserve exactement les mêmes éléments', function () {
        $items = range(1, 24);
        $melange = (new PrngLineaire(7))->melanger($items);

        sort($melange);
        expect($melange)->toBe($items)
            ->and((new PrngLineaire(7))->melanger($items))->not->toBe($items);
    });

    it('ne laisse PAS la tête de liste à sa place (biais du deck de fouille)', function () {
        // Modèle du deck : 2 gemmes en tête, 6 errants en queue — comme
        // DeckFouille::cartes() les construit.
        $modele = array_merge(array_fill(0, 2, 'gemme'), array_fill(0, 16, 'autre'), array_fill(0, 6, 'errant'));

        $tetes = [];
        for ($s = 0; $s < 2000; $s++) {
            $tetes[] = (new PrngLineaire(crc32("groupe-{$s}:1:fouille")))->melanger($modele)[0];
        }

        $part = fn (string $t) => count(array_keys($tetes, $t, true)) / count($tetes);

        // Attendu : gemme 2/24 ≈ 8,3 %, errant 6/24 = 25 %. Avant correctif la
        // gemme sortait à 25,6 % (×3) et l'errant à 17,2 %.
        expect($part('gemme'))->toBeLessThan(0.13)
            ->and($part('errant'))->toBeGreaterThan(0.19);
    });

    it('mélange les deux moitiés d\'un pool concaténé (placement des pièges)', function () {
        // AssembleurCarte fusionne [...milieux de couloir, ...cases en salle] en
        // UN pool justement pour ne plus mettre tous les pièges en couloir : si
        // le mélange laisse la tête en place, le correctif est décoratif.
        $pool = array_merge(array_fill(0, 40, 'couloir'), array_fill(0, 40, 'salle'));

        $couloirs = 0;
        $total = 0;
        for ($s = 0; $s < 1500; $s++) {
            foreach (array_slice((new PrngLineaire(crc32("g{$s}")))->melanger($pool), 0, 8) as $c) {
                $total++;
                $couloirs += $c === 'couloir' ? 1 : 0;
            }
        }

        // Attendu 50 %. Avant correctif : 62,7 %.
        expect($couloirs / $total)->toBeGreaterThan(0.44)->toBeLessThan(0.56);
    });

    it('ne tire pas ses indices sur les bits de poids faible', function () {
        // Garde-fou direct : `suivant() % 2` EST périodique (010101…). Le
        // mélange ne doit donc jamais s'en servir — on le vérifie par l'absence
        // d'alternance stricte des positions finales sur des listes de 2.
        $p = new PrngLineaire(1);
        $bits = [];
        for ($i = 0; $i < 12; $i++) {
            $bits[] = $p->suivant() % 2;
        }
        expect($bits)->toBe([0, 1, 0, 1, 0, 1, 0, 1, 0, 1, 0, 1]); // le défaut est bien là…

        // …mais melanger() n'en hérite pas : sur 400 graines, une paire ne doit
        // pas être inversée une fois sur deux de façon strictement alternée.
        $premiers = [];
        for ($s = 0; $s < 400; $s++) {
            $premiers[] = (new PrngLineaire($s))->melanger(['a', 'b'])[0];
        }

        $alternances = 0;
        for ($i = 1; $i < count($premiers); $i++) {
            $alternances += $premiers[$i] !== $premiers[$i - 1] ? 1 : 0;
        }

        expect($alternances)->toBeLessThan(380); // 399 = alternance parfaite
    });
});
