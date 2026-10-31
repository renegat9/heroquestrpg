<?php

declare(strict_types=1);

use App\Engine\MotsClesAdversaires;

/*
 * LE GUIDE NOMME CHAQUE CAPACITÉ D'ADVERSAIRE (vu le 2026-10-31).
 *
 * Les capacités des monstres sont traduites côté client par `resources/js/compendium.js` (`capacitesVersChips()`, table
 * `CAPACITES`). Une clé qu'il ne connaît pas retombe sur la clé brute (« immunite sorts sauf ») : c'est ce que montrait
 * le guide pour Kessandria, Balur et le Seigneur Sorcier, et il disait « chacun une seule fois » d'un boss qui lance la
 * Frayeur deux fois. Chaque mot du vocabulaire fermé `MotsClesAdversaires` doit donc avoir son libellé dans la table.
 */

it('nomme dans la table CAPACITES du guide chaque mot du vocabulaire des adversaires', function () {
    $traducteur = file_get_contents(resource_path('js/compendium.js'));
    $debut = strpos($traducteur, 'const CAPACITES = {');
    expect($debut)->not->toBeFalse('table CAPACITES introuvable dans compendium.js');
    $table = substr($traducteur, $debut, strpos($traducteur, "\n};", $debut) - $debut);

    $mots = array_values(array_filter(
        (new ReflectionClass(MotsClesAdversaires::class))->getConstants(),
        fn ($valeur) => is_string($valeur) && preg_match('/^[a-z_]+$/', $valeur),
    ));
    expect($mots)->not->toBeEmpty();

    foreach ($mots as $mot) {
        expect(preg_match('/(^|\s)'.preg_quote($mot, '/').'\s*:/m', $table))
            ->toBe(1, "« {$mot} » n'a pas de libellé dans CAPACITES (resources/js/compendium.js)");
    }
});
