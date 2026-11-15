<?php

declare(strict_types=1);

use App\Partie\Bourses;

/*
 * UNE RÈGLE, UN POINT DE PASSAGE — « où va l'or » ne se décide que dans `App\Partie\Bourses`.
 *
 * Ce garde-fou scanne les SOURCES, dans les deux sens :
 *  1. toute écriture de la colonne `or` (increment / decrement / update / forceFill / affectation) hors de `Bourses`
 *     doit être déclarée dans `Bourses::HORS_PASSAGE`, avec la raison pour laquelle le classique ne l'atteint pas ;
 *  2. rien n'y est déclaré qui n'existe plus dans le code.
 *
 * Ce qu'il défend : neuf endroits écrivaient `groupes.or` chacun de son côté. En mode classique (bourse par héros),
 * une pièce versée au pot commun est une pièce que personne ne peut dépenser.
 */

/**
 * Les `Classe::méthode` qui écrivent la colonne `or`, relevées dans `app/`.
 *
 * @return list<string>
 */
function ecrituresDOr(): array
{
    $sites = [];
    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($fichiers as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($fichier->getPathname());
        $positions = [];

        // increment('or' …) / decrement('or' …), et une affectation directe `->or = `, `->or += `, `->or -= `.
        foreach (["/->(?:increment|decrement)\\(\\s*'or'/", '/->or\\s*[-+]?=(?!=)/'] as $motif) {
            preg_match_all($motif, $source, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [, $position]) {
                $positions[] = $position;
            }
        }

        // update([...]) / forceFill([...]) dont le tableau porte la clé `or` — sur une ligne ou sur plusieurs : on
        // suit les crochets jusqu'à la fermeture du tableau.
        preg_match_all('/->(?:update|forceFill|fill)\\(\\s*\\[/', $source, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$appel, $position]) {
            $debut = $position + strlen($appel);
            $profondeur = 1;
            $i = $debut;

            while ($profondeur > 0 && $i < strlen($source)) {
                $profondeur += match ($source[$i]) {
                    '[' => 1,
                    ']' => -1,
                    default => 0,
                };
                $i++;
            }

            if (preg_match("/(?:^|[\\[,\\s])'or'\\s*=>/", substr($source, $debut, $i - $debut)) === 1) {
                $positions[] = $position;
            }
        }

        if ($positions === []) {
            continue;
        }

        preg_match('/^namespace\\s+([^;]+);/m', $source, $espace);
        preg_match('/^(?:final\\s+|abstract\\s+)?class\\s+(\\w+)/m', $source, $classe);
        preg_match_all('/function\\s+(\\w+)\\s*\\(/', $source, $fonctions, PREG_OFFSET_CAPTURE);

        foreach ($positions as $position) {
            $methode = '?';
            foreach ($fonctions[1] as [$nom, $debutFonction]) {
                if ($debutFonction > $position) {
                    break;
                }
                $methode = $nom;
            }

            $sites[] = ($espace[1] ?? '?').'\\'.($classe[1] ?? '?').'::'.$methode;
        }
    }

    $sites = array_values(array_unique($sites));
    sort($sites);

    return $sites;
}

it('aucune écriture d\'or hors de Bourses qui ne soit déclarée, avec sa raison', function () {
    $horsBourses = array_values(array_filter(ecrituresDOr(), fn (string $site) => ! str_starts_with($site, Bourses::class.'::')));

    $nonDeclarees = array_values(array_diff($horsBourses, array_keys(Bourses::HORS_PASSAGE)));

    expect($nonDeclarees)->toBe(
        [],
        'Ces méthodes écrivent la colonne `or` sans passer par App\\Partie\\Bourses. Faites-les verser/payer par Bourses, '
        .'ou déclarez-les dans Bourses::HORS_PASSAGE en disant pourquoi un groupe classique ne peut pas les atteindre : '
        .implode(', ', $nonDeclarees),
    );
});

it('rien n\'est déclaré hors passage qui n\'écrive plus d\'or, et chaque déclaration dit pourquoi', function () {
    $relevees = ecrituresDOr();

    foreach (Bourses::HORS_PASSAGE as $site => $raison) {
        expect($relevees)->toContain($site)
            ->and(mb_strlen($raison))->toBeGreaterThan(20);
    }
});

it('le scan voit bien les écritures : Bourses elle-même en fait partie', function () {
    // Sans ce témoin, un motif cassé rendrait les deux tests ci-dessus verts à vide.
    $relevees = ecrituresDOr();

    expect($relevees)->toContain(Bourses::class.'::verser')
        ->and($relevees)->toContain(Bourses::class.'::payer');
});
