<?php

declare(strict_types=1);

use App\Partie\Grille;

/*
 * LE CONTACT EST ORTHOGONAL, PARTOUT — un seul point de passage (chantier
 * 2026-10-23, décision de René).
 *
 * Source : livret de règles 2021, p. 10 — « you may attack any monster that you
 * are adjacent to (next to, in front of, or behind, not diagonal) ». Les
 * diagonales ne comptent QUE là où une carte ou un texte les nomme, et alors
 * par le paramètre `$diagonales` de `Grille::sontAdjacentes()` — jamais par une
 * formule recopiée à la main.
 *
 * Trois verrous :
 *  1. la table de vérité du point de passage, diagonale comprise ou non ;
 *  2. le GARDE-FOU : aucune formule de contact écrite en ligne dans `app/`
 *     (sauf `Grille.php`, qui EST le point de passage) — « deux copies d'une
 *     règle trop simple pour qu'on la voie dériver » (CLAUDE.md) ;
 *  3. le détecteur lui-même, éprouvé sur des cas connus : un garde-fou qui ne
 *     voit rien réussirait pour de mauvaises raisons.
 */

/**
 * Jetons PHP significatifs (sans espaces ni commentaires), avec leur ligne.
 * Lire par jetons plutôt que par expression régulière : une formule coupée sur
 * deux lignes se voit comme une seule, et un commentaire ne déclenche rien.
 *
 * @return list<array{t: string, l: int}>
 */
function contactJetonsSignificatifs(string $source): array
{
    $jetons = [];
    $ligne = 1;

    foreach (token_get_all($source) as $jeton) {
        if (is_array($jeton)) {
            $ligne = $jeton[2];

            if (in_array($jeton[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }

            $jetons[] = ['t' => $jeton[1], 'l' => $jeton[2]];
        } else {
            $jetons[] = ['t' => $jeton, 'l' => $ligne];
        }
    }

    return $jetons;
}

/** Index de la parenthèse fermante correspondant à la parenthèse ouverte en `$ouverte`, ou null. */
function contactFinParenthese(array $jetons, int $ouverte): ?int
{
    $profondeur = 0;

    for ($i = $ouverte, $n = count($jetons); $i < $n; $i++) {
        if ($jetons[$i]['t'] === '(') {
            $profondeur++;
        } elseif ($jetons[$i]['t'] === ')' && --$profondeur === 0) {
            return $i;
        }
    }

    return null;
}

/**
 * Les formes de contact écrites EN LIGNE dans `$source`, avec leur ligne.
 *
 *  1. abs(A) + abs(B) OP 1           — la formule Manhattan recopiée
 *  2. max(abs(A), abs(B)) OP 1       — la formule Tchebychev recopiée
 *  3. abs(A) OP 1 && abs(B) OP 1     — le carré de 8 cases recopié
 *  4. $v + $w OP 1                   — la même règle, en variables ($ex + $ey === 1)
 *  5. max($v, $w) OP 1               — idem, Tchebychev en variables
 *  6. distance(…) OP 1               — la règle recopiée derrière une méthode
 *
 * Ne sont PAS des contacts, donc jamais signalés : un classement par distance
 * (`max(abs(…))` ou `sortBy(abs(…) + abs(…))`, sans comparaison à 1), un
 * décompte, une dimension (`abs($b - $a) + 1`), un seuil autre que 1.
 *
 * @return list<array{ligne: int, forme: string}>
 */
function contactFormulesEnLigne(string $source): array
{
    $jetons = contactJetonsSignificatifs($source);
    $n = count($jetons);

    $t = static fn (int $k): string => $jetons[$k]['t'] ?? '';
    $estOp = static fn (int $k): bool => in_array($t($k), ['===', '!==', '==', '!=', '<>', '<=', '>=', '<', '>'], true);
    $estUn = static fn (int $k): bool => $t($k) === '1';
    $estVariable = static fn (int $k): bool => str_starts_with($t($k), '$');
    // `abs(` ouvert en `$k` : index juste APRÈS sa parenthèse fermante, ou null.
    $absEn = static function (int $k) use ($jetons, $t): ?int {
        if ($t($k) !== 'abs' || $t($k + 1) !== '(') {
            return null;
        }

        $fin = contactFinParenthese($jetons, $k + 1);

        return $fin === null ? null : $fin + 1;
    };

    $trouvees = [];

    for ($i = 0; $i < $n; $i++) {
        $forme = null;

        if ($t($i) === 'abs' && ($a = $absEn($i)) !== null && $t($a) === '+'
            && ($b = $absEn($a + 1)) !== null && $estOp($b) && $estUn($b + 1)) {
            $forme = 'abs(…) + abs(…) OP 1';
        } elseif ($t($i) === 'max' && $t($i + 1) === '(' && ($a = $absEn($i + 2)) !== null && $t($a) === ','
            && ($b = $absEn($a + 1)) !== null && $t($b) === ')' && $estOp($b + 1) && $estUn($b + 2)) {
            $forme = 'max(abs(…), abs(…)) OP 1';
        } elseif ($t($i) === 'abs' && ($a = $absEn($i)) !== null && $estOp($a) && $estUn($a + 1)
            && $t($a + 2) === '&&' && ($b = $absEn($a + 3)) !== null && $estOp($b) && $estUn($b + 1)) {
            $forme = 'abs(…) OP 1 && abs(…) OP 1';
        } elseif ($estVariable($i) && $t($i + 1) === '+' && $estVariable($i + 2) && $estOp($i + 3) && $estUn($i + 4)) {
            $forme = '$v + $w OP 1';
        } elseif ($t($i) === 'max' && $t($i + 1) === '(' && $estVariable($i + 2) && $t($i + 3) === ','
            && $estVariable($i + 4) && $t($i + 5) === ')' && $estOp($i + 6) && $estUn($i + 7)) {
            $forme = 'max($v, $w) OP 1';
        } elseif ($t($i) === 'distance' && $t($i + 1) === '(') {
            $fin = contactFinParenthese($jetons, $i + 1);

            if ($fin !== null && $estOp($fin + 1) && $estUn($fin + 2)) {
                $forme = 'distance(…) OP 1';
            }
        }

        if ($forme !== null) {
            $trouvees[] = ['ligne' => $jetons[$i]['l'], 'forme' => $forme];
        }
    }

    return $trouvees;
}

// ---------------------------------------------------------------------
// 1. Le point de passage
// ---------------------------------------------------------------------

it('Grille::sontAdjacentes() est orthogonal par défaut, et ne s\'ouvre aux diagonales que sur demande', function () {
    // Une figure en (2, 2).
    expect(Grille::sontAdjacentes(2, 2, 3, 2))->toBeTrue()   // à côté
        ->and(Grille::sontAdjacentes(2, 2, 2, 3))->toBeTrue()   // derrière
        ->and(Grille::sontAdjacentes(2, 2, 1, 2))->toBeTrue()   // devant
        ->and(Grille::sontAdjacentes(2, 2, 3, 3))->toBeFalse()  // en diagonale : pas un contact
        ->and(Grille::sontAdjacentes(2, 2, 2, 2))->toBeFalse()  // la même case
        ->and(Grille::sontAdjacentes(2, 2, 4, 2))->toBeFalse(); // deux cases plus loin

    // Les 8 cases, seulement quand une carte ou un texte les nomme.
    expect(Grille::sontAdjacentes(2, 2, 3, 3, true))->toBeTrue()
        ->and(Grille::sontAdjacentes(2, 2, 3, 2, true))->toBeTrue()
        ->and(Grille::sontAdjacentes(2, 2, 2, 2, true))->toBeFalse()
        ->and(Grille::sontAdjacentes(2, 2, 4, 4, true))->toBeFalse();
});

// ---------------------------------------------------------------------
// 2. Le garde-fou : aucune copie de la formule dans app/
// ---------------------------------------------------------------------

it('aucune formule de contact n\'est écrite en ligne dans app/ : le point de passage est Grille::sontAdjacentes()', function () {
    // Exceptions NOMMÉES, avec leur raison. Une exception qui ne sert plus est
    // périmée : le test ci-dessous l'exige, pour qu'elle se retire.
    $exceptions = [
        [
            'fichier' => 'app/Partie/EtatGroupe.php',
            'contient' => 'abs($h[\'x\'] - $x) <= 1 && abs($h[\'y\'] - $y) <= 1',
            'raison' => 'Brouillard : le mur qu\'un héros TOUCHE reste visible, y compris en diagonale. '
                .'C\'est un AFFICHAGE, pas un contact (EtatGroupe::appliquerBrouillard, ne pas toucher).',
        ],
    ];

    $racine = str_replace('\\', '/', base_path()).'/';
    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));

    $analyses = 0;
    $trouvees = [];
    $utilisees = [];

    foreach ($fichiers as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }

        $relatif = str_replace($racine, '', str_replace('\\', '/', $fichier->getPathname()));

        if ($relatif === 'app/Partie/Grille.php') {
            continue; // le point de passage lui-même
        }

        $analyses++;
        $source = (string) file_get_contents($fichier->getPathname());
        $lignes = explode("\n", $source);

        foreach (contactFormulesEnLigne($source) as ['ligne' => $ligne, 'forme' => $forme]) {
            $texte = trim($lignes[$ligne - 1] ?? '');

            $exception = collect($exceptions)->first(
                fn (array $e) => $e['fichier'] === $relatif && str_contains($texte, $e['contient']),
            );

            if ($exception !== null) {
                $utilisees[] = $exception['fichier'].' :: '.$exception['contient'];

                continue;
            }

            $trouvees[] = "{$relatif}:{$ligne} ({$forme}) — {$texte}";
        }
    }

    // Une vérification qui ne lit aucun fichier réussirait pour rien.
    expect($analyses)->toBeGreaterThan(100);

    expect($trouvees)->toBe([]);

    $inutilisees = collect($exceptions)
        ->map(fn (array $e) => $e['fichier'].' :: '.$e['contient'])
        ->diff(array_unique($utilisees))
        ->values()
        ->all();

    expect($inutilisees)->toBe([]);
});

// ---------------------------------------------------------------------
// 3. Le détecteur, éprouvé sur des cas connus
// ---------------------------------------------------------------------

it('le détecteur attrape chaque forme de contact, même coupée sur deux lignes', function () {
    $formes = [
        'abs($a - $b) + abs($c - $d) === 1',
        "abs(\$a - \$b)\n        + abs(\$c - \$d) !== 1",
        'max(abs($a - $b), abs($c - $d)) === 1',
        'abs($a - $b) <= 1 && abs($c - $d) <= 1',
        '$ex + $ey === 1',
        '$this->distance($instance, $c) === 1',
    ];

    foreach ($formes as $code) {
        expect(contactFormulesEnLigne("<?php\n\$x = {$code};"))->not->toBeEmpty($code);
    }
});

it('le détecteur ignore les distances, les classements, les commentaires et les seuils autres que 1', function () {
    $sans = [
        '$d = abs($a - $b) + abs($c - $d);',              // une distance, pas un contact
        '$s = [max(abs($a - $b), abs($c - $d)), $y];',    // un classement
        '// abs($a - $b) + abs($c - $d) === 1',           // un commentaire
        'if (abs($a - $b) + abs($c - $d) > 2) {}',        // un seuil autre que le contact
        '$score = $voisins * 100 - (abs($c - $x) + abs($c2 - $y));',
    ];

    foreach ($sans as $code) {
        expect(contactFormulesEnLigne("<?php\n{$code}"))->toBeEmpty($code);
    }
});
