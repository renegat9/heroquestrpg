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
it('offre un repli scripté pour chacun des temps forts déclarés', function () {
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
 * ⚠ Un plancher UNIFORME ne suffit pas, et c'est ce que la campagne a montré :
 * les clés qui tombent à chaque jet de dés et à chaque coup porté n'en avaient
 * que deux, quand les clés rares en avaient trois. Mesuré sur une partie
 * réelle (2026-08-20) : 293 narrations pour 94 textes distincts, le plus
 * fréquent lu DIX-HUIT FOIS dans la même quête.
 *
 * Le plancher doit donc suivre la FRÉQUENCE d'usage. Les étoffer ne coûte rien
 * — ces répliques ne sont jamais synthétisées en audio et vivent en config.
 */
it('exige plus de variantes des temps forts les plus fréquents', function () {
    $planchers = [
        // Tombent à chaque jet et à chaque échange de coups.
        'reussite' => 8, 'reussite_mixte' => 8, 'echec' => 8, 'progression' => 8,
        'attaque_mort' => 8, 'attaque_touche' => 8, 'attaque_pare' => 8,
        // Une fouille par héros et par salle : fréquent sans être continu.
        'fouille_tresor' => 5, 'fouille_potion' => 5, 'fouille_errant' => 5,
        'fouille_piege' => 5, 'fouille_rien' => 5,
        'mobilier_objet' => 5, 'mobilier_piege' => 5, 'mobilier_rien' => 5,
        // Rares mais marquants : on ne veut pas entendre deux fois la même
        // phrase pour la chute d'un compagnon dans la même partie.
        'heros_tombe' => 5, 'heros_releve' => 5, 'boss_vaincu' => 5,
    ];

    foreach ($planchers as $cle => $minimum) {
        expect(count(config("narration.repli.{$cle}.variantes") ?? []))
            ->toBeGreaterThanOrEqual($minimum, "« {$cle} » se répétera : il en faut au moins {$minimum}");
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
