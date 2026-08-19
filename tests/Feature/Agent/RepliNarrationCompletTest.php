<?php

declare(strict_types=1);

use App\Partie\Narration\TempsFort;

/**
 * Le repli scripté couvre-t-il TOUS les temps forts que le moteur peut jouer ?
 *
 * Depuis la bascule du 2026-08-18, `config/narration.php` n'est plus « le
 * filet quand l'IA échoue » : c'est ce qui parle
 *  - pendant les premières secondes de CHAQUE quête (le pack pré-généré est
 *    écrit par un job asynchrone, la partie a déjà commencé) ;
 *  - pendant TOUTE une partie jouée sans clé d'API — le jeu doit rester
 *    jouable sans IA, c'est une règle du projet, pas une dégradation.
 *
 * Depuis que l'IA n'écrit plus que 3 temps forts sur 24
 * (`TempsFort::GENERES_PAR_QUETE` — le reste étant des TIRAGES qu'elle ne
 * peut pas anticiper), les 21 autres ne viennent QUE d'ici. Une clé sans
 * repli produirait une fouille MUETTE, sans erreur nulle part : exactement le
 * genre de silence que ce dépôt traque. On teste la propriété plutôt que de
 * la documenter.
 */
it('offre un repli scripté pour chacun des 24 temps forts', function () {
    $attendues = TempsFort::cles();
    $repli = array_keys((array) config('narration.repli'));

    expect(array_diff($attendues, $repli))->toBe([], 'temps forts sans repli scripté')
        ->and(array_diff($repli, $attendues))->toBe([], 'replis orphelins, plus produits par aucun skill');
});

it('donne au moins deux variantes à chaque temps fort', function () {
    foreach ((array) config('narration.repli') as $cle => $bloc) {
        expect(count($bloc['variantes'] ?? []))
            ->toBeGreaterThanOrEqual(2, "« {$cle} » n'a pas assez de variantes pour ne pas se répéter");
    }
});

/**
 * ⚠ Le point qui mord : une variable que le moteur ne fournira jamais reste
 * DANS LE TEXTE (`BibliothequeNarration::substituer` laisse volontairement
 * intact ce qu'il ne sait pas remplacer, une phrase amputée passant inaperçue
 * là où « {monstre} » saute aux yeux). Le repli doit donc respecter le même
 * contrat que la sortie de l'IA, clé par clé.
 */
it('n’emploie dans le repli que les variables autorisées pour chaque clé', function () {
    foreach ((array) config('narration.repli') as $cle => $bloc) {
        $autorises = TempsFort::variablesDe($cle);

        foreach ($bloc['variantes'] ?? [] as $variante) {
            expect(array_diff(TempsFort::variablesEmployees($variante), $autorises))
                ->toBe([], "« {$cle} » emploie une variable que le moteur ne fournit pas");
        }
    }
});
