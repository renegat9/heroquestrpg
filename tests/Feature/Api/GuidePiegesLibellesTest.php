<?php

declare(strict_types=1);

use App\Engine\MotsClesPiege;
use App\Models\Piege;
use Database\Seeders\PiegeSeeder;

/*
 * LE GUIDE DIT CHAQUE CLÉ D'EFFET DE PIÈGE (vu à l'écran le 2026-10-30).
 *
 * Les effets des pièges sont traduits côté client par `resources/js/compendium.js` (`effetVersChips()`). Une clé qu'il ne
 * connaît pas retombe sur un repli « clé : valeur » — et une valeur STRUCTURÉE y devient « [object Object] » : c'est ce que
 * montrait la carte de la Fosse longue (`saut_long`, `dans_la_fosse`), à côté de « mare : oui » et « sort dread : … ».
 * Chaque clé du vocabulaire fermé des pièges (`MotsClesPiege`) doit donc être NOMMÉE dans le traducteur : dans une de ses
 * tables de libellés, ou dans un rendu à part.
 */

it('nomme dans le traducteur du guide chaque clé d\'effet de piège semée', function () {
    $this->seed(PiegeSeeder::class);
    $traducteur = file_get_contents(resource_path('js/compendium.js'));

    $semees = Piege::all()->flatMap(fn (Piege $p) => array_keys((array) $p->effet))->unique()->values();
    expect($semees)->not->toBeEmpty();

    foreach ($semees as $cle) {
        expect(MotsClesPiege::connue($cle))->toBeTrue("clé « {$cle} » hors du vocabulaire fermé");
        // Nommée comme clé d'une table (`cle:`), entre quotes (un ensemble de rendus à part), ou lue en propriété (`effet.cle`).
        $nommee = preg_match('/(^|\s)'.preg_quote($cle, '/').'\s*:/m', $traducteur)
            || str_contains($traducteur, "'{$cle}'")
            || str_contains($traducteur, "effet.{$cle}");
        expect($nommee)->toBeTrue("le guide ne sait pas dire la clé de piège « {$cle} » : elle sortirait en mot brut, ou en [object Object]");
    }
});
