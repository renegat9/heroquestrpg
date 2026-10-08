<?php

declare(strict_types=1);

use App\Models\Personnage;
use App\Partie\JournalCombat;
use App\Partie\SceneDeTable;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Les CINQ événements des pièges magiques de Wizards of Morcar (jeton
 * d'embrasement posé, explosion, téléportation, ouragan, désamorçage) doivent
 * être ANNONCÉS : une ligne dans le fil (`JournalCombat::ligneAction()`) et une
 * scène à la table (`SceneDeTable::depuisAction()`). Un effet automatique que
 * rien n'annonce est injouable (CLAUDE.md, règle « effet automatique »).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, MercenaireSeeder::class,
        SortDreadSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Les payloads réels des cinq événements, tels que `MoteurPieges` les publie. */
function annoncesPayloads(Personnage $hero, Personnage $autre): array
{
    $piege = ['nom' => "Piège d'embrasement", 'x' => 3, 'y' => 4];
    $acteur = ['id' => $hero->id, 'nom' => $hero->nom];

    return [
        'amorce' => [
            'type' => 'piege_amorce', 'contexte' => 'deplacement', 'piege' => $piege,
            'personnage' => $acteur, 'degats' => 0, 'pv_body_apres' => 8, 'tombe' => false,
            'immobilise' => false, 'bloc_permanent' => false,
        ],
        'explosion' => [
            'type' => 'piege_explosion', 'piege' => $piege,
            'cibles' => [
                ['type' => 'heros', 'personnage_id' => $hero->id, 'nom' => $hero->nom, 'degats' => 2, 'pv_body_apres' => 6, 'tombe' => false],
                ['type' => 'heros', 'personnage_id' => $autre->id, 'nom' => $autre->nom, 'degats' => 0, 'pv_body_apres' => 8, 'tombe' => false],
                ['type' => 'monstre', 'instance_id' => 1, 'nom' => 'Gobelin', 'degats' => 1, 'vaincu' => true],
            ],
        ],
        'teleporte' => [
            'type' => 'piege_teleporte', 'contexte' => 'deplacement', 'piege' => ['nom' => 'Piège de téléportation', 'x' => 1, 'y' => 1],
            'personnage' => $acteur, 'destination' => ['x' => 9, 'y' => 9], 'degats' => 0,
            'pv_body_apres' => 8, 'tombe' => false, 'immobilise' => false, 'bloc_permanent' => false,
        ],
        // Refus : même type, SANS `destination` (`MoteurPieges::declencherTeleportation()`).
        'teleporte_echoue' => [
            'type' => 'piege_teleporte', 'contexte' => 'deplacement', 'piege' => ['nom' => 'Piège de téléportation', 'x' => 1, 'y' => 1],
            'personnage' => $acteur, 'degats' => 0, 'pv_body_apres' => 8, 'tombe' => false,
            'immobilise' => false, 'bloc_permanent' => false, 'teleportation_echouee' => true,
        ],
        'bourrasque' => [
            'type' => 'piege_bourrasque', 'contexte' => 'deplacement',
            'piege' => ['nom' => "Piège de l'ouragan", 'x' => 2, 'y' => 2],
            'personnage' => $acteur, 'destination' => ['x' => 6, 'y' => 2], 'degats' => 0,
            'pv_body_apres' => 8, 'tombe' => false, 'immobilise' => false, 'bloc_permanent' => false,
            'repousses' => [['personnage_id' => $autre->id, 'nom' => $autre->nom, 'de' => ['x' => 3, 'y' => 2], 'vers' => ['x' => 7, 'y' => 2]]],
        ],
        'desarme' => [
            'type' => 'piege_desarme_embrasement', 'contexte' => 'sort',
            'piege' => ['nom' => "Piège d'embrasement", 'x' => 3, 'y' => 4],
            'personnage' => $acteur, 'sort' => ['id' => 1, 'nom' => 'Sommeil'],
            'degats' => 0, 'pv_body_apres' => 8, 'tombe' => false, 'immobilise' => false, 'bloc_permanent' => false,
        ],
    ];
}

it('chaque événement des pièges magiques a sa LIGNE dans le fil, et aucun ne reste muet', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $autre = creerHeros($alice, $groupe, 'Brunhilde', 2);

    $fil = app(JournalCombat::class);
    $textes = fn (array $payload): string => collect($fil->depuisResultat($payload, 'Albrecht'))
        ->pluck('texte')->implode(' ¦ ');

    $p = annoncesPayloads($hero, $autre);

    expect($textes($p['amorce']))->toContain('Albrecht déclenche', 'jeton de feu couve', 'tour du MJ');
    expect($textes($p['explosion']))
        ->toContain('explose sur toute la salle')
        ->toContain('Albrecht encaisse −2 PV')
        ->toContain('Brunhilde pare le feu')
        ->toContain('Gobelin est vaincu');
    expect($textes($p['teleporte']))->toContain('téléporte Albrecht', 'tour se termine');
    expect($textes($p['teleporte_echoue']))->toContain('reste armé');
    expect($textes($p['bourrasque']))->toContain('un ouragan dévale le couloir', 'Brunhilde est rejeté en arrière');
    expect($textes($p['desarme']))->toContain('Albrecht défausse « Sommeil »', 'désamorcé');

    // Aucune ligne vide : chaque événement dit quelque chose.
    foreach ($p as $nom => $payload) {
        expect($fil->depuisResultat($payload, 'Albrecht'))->not->toBeEmpty("événement « {$nom} » muet");
    }
});

it('chaque événement des pièges magiques a sa SCÈNE à la table, de genre « piege »', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $autre = creerHeros($alice, $groupe, 'Brunhilde', 2);

    $scenes = app(SceneDeTable::class);
    $p = annoncesPayloads($hero, $autre);

    $unique = fn (array $payload) => collect($scenes->depuisResultat($payload, $hero->fresh()));

    foreach (['amorce', 'explosion', 'teleporte', 'teleporte_echoue', 'bourrasque', 'desarme'] as $nom) {
        $liste = $unique($p[$nom]);

        expect($liste)->toHaveCount(1, "événement « {$nom} » : une scène attendue")
            ->and($liste->first()['genre'])->toBe('piege');
    }

    expect($unique($p['explosion'])->first())
        ->toMatchArray(['titre' => "Piège d'embrasement explose !", 'sous_titre' => '3 cible(s) touchée(s)'])
        ->and($unique($p['explosion'])->first()['acteurs'])->toHaveCount(2);

    expect($unique($p['desarme'])->first())
        ->toMatchArray(['titre' => "Piège d'embrasement désamorcé", 'sous_titre' => "Albrecht défausse « Sommeil »"])
        ->and($unique($p['desarme'])->first()['issue']['ton'])->toBe('succes');

    expect($unique($p['bourrasque'])->first()['issue']['libelle'])->toBe('1 autre(s) héros rejeté(s)');
    expect($unique($p['teleporte_echoue'])->first()['issue']['libelle'])->toBe('piège resté armé');
});
