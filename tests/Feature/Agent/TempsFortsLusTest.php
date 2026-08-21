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

    // ⚠ DEUX routes, pas une. La table de correspondance de ChoixController
    // couvre tout ce qui découle d'un CHOIX de joueur ; la chute et le
    // relèvement d'un héros, eux, sont observés sur la colonne
    // `etat_personnage_quete.tombe` (voir son `booted()`) parce qu'ils sont
    // écrits depuis huit endroits différents — coup de monstre, piège, sort de
    // Dread, jetons de rejeton… Les exclure ici n'affaiblit pas la propriété :
    // le test suivant vérifie qu'ils ont bien, eux aussi, leur lecteur.
    // ⚠ TROIS routes, pas une, et ce test n'en couvre qu'une.
    //
    // `heros_tombe`/`heros_releve` sont observés sur la colonne
    // `etat_personnage_quete.tombe` (voir son `booted()`) : ils sont écrits
    // depuis huit endroits — coup de monstre, piège, sort de Dread, jetons de
    // rejeton — et les câbler un par un, c'est en oublier un au prochain ajout.
    //
    // `boss_vaincu` passe bien par la table de correspondance, mais exige une
    // VRAIE instance en base : `momentFort()` résout le `tier` du CATALOGUE
    // plutôt que de croire le nom affiché, puisque l'habillage IA renomme les
    // créatures et que « Le Noyé de Gorrim » ne dit rien de son rang. Un
    // payload synthétique ne peut pas le prouver — `FinDeQueteNarreeTest` le
    // fait sur le vrai chemin, en abattant un boss.
    $ailleurs = [
        // Observés sur la colonne `etat_personnage_quete.tombe`.
        'heros_tombe', 'heros_releve',
        // Passent bien par la table, mais exigent une VRAIE instance en base
        // (`FinDeQueteNarreeTest` le prouve en abattant un boss).
        'boss_vaincu',
        // Diffusés par la RÉSOLUTION DU VOTE de retraite
        // (`ResolveurTour::narrerRetraite`, appelé par `VoteGroupe`) : ce ne
        // sont pas des résultats d'action mais des décisions collectives.
        'retraite', 'campagne_abandonnee',
    ];
    $jouables = array_diff(TempsFort::cles(), $ailleurs);

    expect(array_diff($jouables, $atteintes))
        ->toBe([], 'temps forts déclarés qu’aucun résultat moteur ne joue jamais');
});

/**
 * La troisième route : la retraite est décidée par un VOTE, pas par une action.
 * Personne ne doit pouvoir retirer `narrerRetraite()` sans qu'un test tombe.
 */
it('confie la retraite à la résolution du vote', function () {
    expect(method_exists(App\Partie\ResolveurTour::class, 'narrerRetraite'))
        ->toBeTrue('le narrateur de retraite a disparu')
        ->and(App\Partie\Votes\VoteGroupe::TYPE_RETRAITE)->toBe('retraite');

    foreach (['retraite', 'campagne_abandonnee'] as $cle) {
        expect(TempsFort::cles())->toContain($cle)
            ->and(config("narration.repli.{$cle}.variantes"))->not->toBeEmpty();
    }
});

/**
 * La seconde route : personne ne doit pouvoir supprimer l'observateur sans
 * qu'un test tombe. C'est lui — et lui seul — qui rend audibles la chute et le
 * relèvement d'un héros.
 */
it('confie la chute et le relèvement d’un héros à l’observateur de colonne', function () {
    $observe = (new ReflectionClass(App\Models\EtatPersonnageQuete::class))->getMethod('booted');

    expect($observe->getDeclaringClass()->getName())
        ->toBe(App\Models\EtatPersonnageQuete::class, 'l’observateur de `tombe` a disparu')
        ->and(class_exists(App\Partie\Narration\AnnonceurChute::class))
        ->toBeTrue('le service qui diffuse la chute a disparu');

    foreach (['heros_tombe', 'heros_releve'] as $cle) {
        expect(TempsFort::cles())->toContain($cle)
            ->and(config("narration.repli.{$cle}.variantes"))->not->toBeEmpty();
    }
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
