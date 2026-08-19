<?php

declare(strict_types=1);

use App\Partie\Narration\TempsFort;
use App\Http\Controllers\Api\ChoixController;

/**
 * Chaque temps fort PRÉ-GÉNÉRÉ est-il vraiment atteignable en jeu ?
 *
 * Le pack coûte un appel LLM par quête et produit 24 clés × 3 variantes. Si la
 * table de correspondance `résultat moteur → clé` n'en route aucune vers l'une
 * d'elles, cette clé est payée, stockée… et jamais entendue : la « clé
 * décorative » que ce dépôt traque partout.
 *
 * Ce n'est pas théorique. En partie réelle le 2026-08-18, les dix clés de
 * fouille et de mobilier tout juste créées retombaient TOUTES sur
 * « progression » — héritage direct de l'ancien repli, qui n'avait que ce
 * mot-là. Trouver 25 pièces d'or, réveiller un monstre errant ou repartir les
 * mains vides se racontaient par la même phrase : « une salle de plus ».
 */
function cleTempsFortPour(array $resultat): string
{
    $methode = new ReflectionMethod(ChoixController::class, 'cleTempsFort');
    $methode->setAccessible(true);

    return $methode->invoke(app(ChoixController::class), $resultat);
}

it('route un résultat moteur vers CHAQUE temps fort que la pré-génération produit', function () {
    // Résultats moteur représentatifs — types et issues tels que les émettent
    // `ResolveurTour` et `MoteurPieges` (vocabulaire d'issue commun au deck de
    // fouille et au mobilier, cf. CLAUDE.md).
    $resultats = [
        ['type' => 'quete_demarree'],
        ['type' => 'salle_decouverte'],
        ['type' => 'piege_declenche'],
        ['type' => 'reprise'],
        ['type' => 'deplacement'],
        ['type' => 'ouvrir_porte'],
        ['type' => 'actionner_levier'],
        ['type' => 'attaque', 'degats' => 2, 'cible_vaincue' => true],
        ['type' => 'attaque', 'degats' => 2, 'cible_vaincue' => false],
        ['type' => 'attaque', 'degats' => 0],
        ['type' => 'jet', 'issue' => 'reussite'],
        ['type' => 'jet', 'issue' => 'reussite_mixte'],
        ['type' => 'jet', 'issue' => 'echec'],
        ['type' => 'action'],
        ['type' => 'action', 'quete' => ['etat' => 'terminee']],
    ];

    foreach (['tresor', 'potion', 'artefact', 'errant', 'piege', 'rien'] as $issue) {
        $resultats[] = ['type' => 'fouille_tresor', 'issue' => $issue];
    }

    foreach (['tresor', 'objet', 'artefact', 'piege', 'rien'] as $issue) {
        $resultats[] = ['type' => 'fouille_mobilier', 'issue' => $issue];
    }

    $atteintes = array_unique(array_map('cleTempsFortPour', $resultats));
    $jouables = TempsFort::cles();

    expect(array_diff($jouables, $atteintes))
        ->toBe([], 'temps forts déclarés qu’aucun résultat moteur ne joue jamais');
});

/**
 * L'inverse compte autant : une clé que le moteur réclame mais que ni le pack
 * ni `config/narration.php` ne portent renvoie `null`, donc AUCUNE narration —
 * un silence sans erreur, le pire des symptômes.
 */
it('ne réclame aucune clé que personne ne sait produire', function () {
    $jouables = TempsFort::cles();

    foreach ([
        ['type' => 'fouille_tresor', 'issue' => 'issue_inconnue_du_futur'],
        ['type' => 'fouille_mobilier', 'issue' => 'issue_inconnue_du_futur'],
        ['type' => 'type_inconnu_du_futur'],
    ] as $resultat) {
        expect($jouables)->toContain(cleTempsFortPour($resultat));
    }
});
