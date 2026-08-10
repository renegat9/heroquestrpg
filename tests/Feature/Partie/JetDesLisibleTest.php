<?php

declare(strict_types=1);

use App\Engine\Combat;
use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDeterministe;
use App\Engine\TypeFigurine;
use App\Partie\JournalCombat;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Un jet AFFICHABLE : les faces seules ne suffisent pas.
 *
 * Un dé n'est un succès que relativement à qui le lance — un bouclier blanc
 * pare pour un héros et ne pare rien pour un monstre, un crâne touche sauf
 * contre un éthéré. La manette repliait les deux couleurs de bouclier sur une
 * même icône : un héros frappant un gobelin voyait « parer » des boucliers
 * blancs qui n'avaient rien paré, et lisait 3 parades là où le moteur en
 * comptait 1. La face gagnante est donc publiée PAR LE MOTEUR, jamais
 * redéduite côté client.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class]);
});

it('publie la face qui PARE selon le camp du défenseur', function () {
    $des = new LanceurDeterministe([
        1, 1, 1,   // attaque : 3 crânes
        4, 6,      // défense : 1 bouclier blanc, 1 bouclier noir
    ]);

    // Défenseur MONSTRE : seul le noir pare → 3 − 1 = 2 dégâts.
    $r = (new Combat($des))->resoudreAttaque(3, 2, TypeFigurine::Monstre, 5);

    expect($r->boucliers)->toBe(1)
        ->and($r->degats)->toBe(2)
        ->and($r->faceDefensive)->toBe(FaceDeCombat::BouclierNoir)
        ->and($r->faceTouchante)->toBe(FaceDeCombat::Crane);

    // …et le payload le dit, pour que la manette entoure le bon dé.
    expect($r->pourJournal())->toMatchArray([
        'faces_attaque' => ['crane', 'crane', 'crane'],
        'faces_defense' => ['bouclier_blanc', 'bouclier_noir'],
        'face_touchante' => 'crane',
        'face_defensive' => 'bouclier_noir',
    ]);
});

it('bascule la face qui TOUCHE contre un éthéré', function () {
    $des = new LanceurDeterministe([1, 6, 1, 4]);

    // Contre un éthéré, c'est le bouclier NOIR qui touche (Rise of the Dread
    // Moon) : sur crâne/noir/crâne, une seule touche — celle que la manette
    // aurait affichée en « raté » si elle avait cherché des crânes.
    $r = (new Combat($des))->resoudreAttaque(3, 1, TypeFigurine::Monstre, 5, defenseurEthere: true);

    expect($r->touches)->toBe(1)
        ->and($r->pourJournal()['face_touchante'])->toBe('bouclier_noir');
});

it('attache le jet À LA LIGNE du fil, avec les deux noms', function () {
    $lignes = (new JournalCombat)->depuisResultat([
        'type' => 'attaque',
        'cible' => ['nom' => 'Gobelin'],
        'degats' => 2,
        'touches' => 3,
        'boucliers' => 1,
        'faces_attaque' => ['crane', 'crane', 'crane'],
        'faces_defense' => ['bouclier_blanc', 'bouclier_noir'],
        'face_touchante' => 'crane',
        'face_defensive' => 'bouclier_noir',
    ], 'Krogar');

    expect($lignes)->toHaveCount(1);

    // Sans les DEUX noms, la manette affichait deux rangées de dés sans dire
    // laquelle appartenait à qui — elles ne différaient que par leur opacité.
    expect($lignes[0]['des'])->toMatchArray([
        'attaquant' => 'Krogar',
        'defenseur' => 'Gobelin',
        'touchante' => 'crane',
        'defensive' => 'bouclier_noir',
        'atk' => ['crane', 'crane', 'crane'],
        'def' => ['bouclier_blanc', 'bouclier_noir'],
    ]);
});

it('attache aussi le jet du MONSTRE qui frappe — l\'historique n\'est pas à sens unique', function () {
    // L'overlay de révélation ne montrait que SA propre action : un joueur
    // n'avait jamais vu un seul dé du monstre qui le tuait. Le fil, lui, passe
    // par la phase des monstres.
    $lignes = (new JournalCombat)->depuisResultat([
        'tour_monstres' => ['actions' => [[
            'type' => 'attaque_monstre',
            'monstre' => 'Troll',
            'cible' => ['nom' => 'Aldric'],
            'degats' => 2,
            'touches' => 3,
            'boucliers' => 1,
            'faces_attaque' => ['crane', 'crane', 'crane', 'bouclier_noir'],
            'faces_defense' => ['bouclier_blanc', 'crane'],
            'face_touchante' => 'crane',
            'face_defensive' => 'bouclier_blanc',
        ]]],
    ], 'Aldric');

    $avecDes = array_values(array_filter($lignes, fn ($l) => isset($l['des'])));

    expect($avecDes)->toHaveCount(1)
        ->and($avecDes[0]['des']['attaquant'])->toBe('Troll')
        ->and($avecDes[0]['des']['defenseur'])->toBe('Aldric')
        // Le héros défend : c'est le BLANC qui compte pour lui.
        ->and($avecDes[0]['des']['defensive'])->toBe('bouclier_blanc');
});

it('n\'invente aucun dé quand il n\'y en a pas eu', function () {
    // Dague de jet magique : dégâts fixes, aucun jet. Afficher des dés vides
    // laisserait croire à un lancer qui n'a pas eu lieu.
    $lignes = (new JournalCombat)->depuisResultat([
        'type' => 'attaque',
        'cible' => ['nom' => 'Orque'],
        'degats' => 1,
        'faces_attaque' => [],
        'faces_defense' => [],
    ], 'Krogar');

    expect($lignes[0])->not->toHaveKey('des');
});

it('rejoue le fil du combat depuis la BASE — un rafraîchissement ne l\'efface plus', function () {
    // Le store ne remplissait `journalCombat` qu'en direct (`.combat.journal`) :
    // recharger la manette repartait sur un fil vide. Tolérable pour du texte,
    // pas pour l'historique des jets, qui devient alors introuvable.
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $groupe = $ctx['groupe'];
    $quete = $ctx['quete'];

    App\Models\Evenement::create([
        'groupe_id' => $groupe->id,
        'quete_id' => $quete->id,
        'sequence' => 9001,
        'type' => 'combat',
        'acteur' => ['nom' => 'Krogar'],
        'payload' => [
            'type' => 'attaque',
            'cible' => ['nom' => 'Gobelin'],
            'degats' => 2,
            'touches' => 3,
            'boucliers' => 1,
            'faces_attaque' => ['crane', 'crane', 'crane'],
            'faces_defense' => ['bouclier_blanc', 'bouclier_noir'],
            'face_touchante' => 'crane',
            'face_defensive' => 'bouclier_noir',
        ],
    ]);

    // `fresh()` : l'instance rendue par le helper date d'AVANT le lancement,
    // sa `phase` est encore « hub » en mémoire — et `payload()` n'expose la
    // quête (donc le fil) qu'en phase « quete ».
    $etat = app(App\Partie\EtatGroupe::class)->payload($groupe->fresh());

    $avecDes = array_values(array_filter($etat['journal_combat'] ?? [], fn ($l) => isset($l['des'])));

    expect($avecDes)->not->toBeEmpty();
    expect(end($avecDes)['des'])->toMatchArray([
        'attaquant' => 'Krogar',
        'defenseur' => 'Gobelin',
        'defensive' => 'bouclier_noir',
    ]);
});
