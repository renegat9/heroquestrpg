<?php

declare(strict_types=1);

use App\Partie\Images\BibliothequeImages;
use App\Partie\JournalCombat;
use App\Partie\SceneDeTable;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;

/**
 * Scènes illustrées de l'écran de table (`.table.scene`).
 *
 * Le moteur rend un résultat structuré que `JournalCombat` APLATIT en texte :
 * les identités y meurent, donc plus aucune image n'y est résolvable. Ces tests
 * fixent l'autre sortie — celle qui sert à MONTRER.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, ObjetSeeder::class,
        PiegeSeeder::class, GabaritQueteSeeder::class]);
});

/** Les champs du contrat (docs/contrat-api.md, `.table.scene`). */
const CHAMPS_SCENE = ['genre', 'titre', 'sous_titre', 'acteurs', 'jet', 'objets', 'issue'];

function sceneHeros(string $nom, string $classe = 'nain'): App\Models\Personnage
{
    static $n = 0;
    $joueur = connecterJoueur('j'.(++$n));

    return creerHeros($joueur, creerGroupe('table-'.$n), $nom, $n, ['classe' => $classe]);
}

/** Une instance de monstre réelle : c'est elle qui porte le portrait dynamique. */
function sceneInstanceMonstre(): App\Models\InstanceMonstre
{
    static $q = 0;
    $monstre = App\Models\Monstre::query()->firstOrFail();
    $quete = App\Models\Quete::create([
        'groupe_id' => creerGroupe('g-mon-'.(++$q))->id,
        'gabarit_id' => App\Models\GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Quête d\'essai',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
    ]);

    return App\Models\InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => $monstre->id,
        'pv_body' => $monstre->pv_body,
        'pv_body_max' => $monstre->pv_body,
        'pv_mind' => $monstre->pv_mind,
        'position_x' => 1, 'position_y' => 1,
        'etat' => 'actif',
    ]);
}

function scenesDe(array $resultat, ?App\Models\Personnage $acteur = null): array
{
    $acteur ??= sceneHeros('Borin');

    return app(SceneDeTable::class)->depuisResultat($resultat, $acteur);
}

it('monte une scène d\'attaque avec les deux portraits et la volée réelle', function () {
    $instance = sceneInstanceMonstre();

    $scene = scenesDe([
        'type' => 'attaque',
        'cible' => ['instance_id' => $instance->id, 'nom' => 'Écumeur des cryptes'],
        'touches' => 2, 'boucliers' => 1, 'degats' => 1,
        'faces_attaque' => ['crane', 'crane', 'bouclier_blanc'],
        'faces_defense' => ['bouclier_noir', 'crane'],
        'face_touchante' => 'crane', 'face_defensive' => 'bouclier_noir',
    ])[0];

    expect($scene['genre'])->toBe('attaque')
        ->and($scene['titre'])->toContain('Borin')->and($scene['titre'])->toContain('Écumeur des cryptes')
        ->and($scene['acteurs'])->toHaveCount(2)
        ->and($scene['issue']['ton'])->toBe('degats')
        // ⚠ la face gagnante vient du MOTEUR et n'est jamais redéduite : un
        // bouclier blanc pare pour un héros et rien du tout pour un monstre.
        ->and($scene['jet']['defensive'])->toBe('bouclier_noir')
        ->and($scene['jet']['atk'])->toHaveCount(3);
});

it('nomme un coup paré autrement qu\'un coup manqué', function () {
    $instance = sceneInstanceMonstre();
    $base = [
        'type' => 'attaque',
        'cible' => ['instance_id' => $instance->id, 'nom' => 'Orque'],
        'degats' => 0,
    ];

    expect(scenesDe($base + ['touches' => 0])[0]['issue']['libelle'])->toBe('manqué')
        ->and(scenesDe($base + ['touches' => 2])[0]['issue']['libelle'])->toBe('paré');
});

it('montre le piège et sa victime, y compris quand il est IMBRIQUÉ dans un déplacement', function () {
    // ⚠ Les pièges marchés en chemin arrivent sous `pieges_declenches` (au
    // pluriel) : c'est la clé qui n'avait été couverte que d'un côté du
    // journal, et un héros tombait dans une fosse sans une seule ligne.
    $perso = sceneHeros('Grom', 'barbare');

    $scenesTirees = scenesDe([
        'type' => 'deplacement',
        'pieges_declenches' => [[
            'type' => 'piege_declenche',
            'piege' => ['nom' => 'Fosse'],
            'personnage' => ['id' => $perso->id, 'nom' => 'Grom'],
            'degats' => 1,
            'immobilise' => true,
        ]],
    ], $perso);

    expect($scenesTirees)->toHaveCount(1);
    $scene = $scenesTirees[0];

    expect($scene['genre'])->toBe('piege')
        ->and($scene['titre'])->toContain('Fosse')
        ->and($scene['objets'][0]['detail'])->toContain('immobilise')
        ->and($scene['objets'][0]['image_url'])->not->toBeEmpty();
});

it('montre ce que la fouille a sorti du paquet', function () {
    $objet = App\Models\Objet::query()->firstOrFail();

    $scene = scenesDe([
        'type' => 'fouille_tresor',
        'issue' => 'potion',
        'objet' => ['id' => $objet->id, 'nom' => $objet->nom, 'categorie' => $objet->categorie],
    ])[0];

    expect($scene['genre'])->toBe('fouille')
        ->and($scene['objets'][0]['nom'])->toBe($objet->nom)
        ->and($scene['issue']['ton'])->toBe('tresor');
});

it('reste MUET sur ce qui ne se montre pas', function () {
    // Le déplacement est muet par principe — raconter chaque pas noierait le
    // reste, et une scène vide serait une clé décorative de plus.
    expect(scenesDe(['type' => 'deplacement']))->toBe([])
        ->and(scenesDe(['type' => 'attente']))->toBe([])
        ->and(scenesDe(['type' => 'attaque']))->toBe([]); // sans cible : rien à montrer
});

it('parcourt le tour des monstres comme le journal, sans le recopier', function () {
    // ⚠ Les deux sorties partagent `JournalCombat::actionsDuTour()` : deux
    // parcours dériveraient au premier type de phase ajouté.
    $instance = sceneInstanceMonstre();
    $perso = sceneHeros('Thora', 'elfe');

    $resultat = [
        'type' => 'attente',
        'tour_monstres' => ['actions' => [[
            'type' => 'attaque_monstre',
            'monstre' => 'Forgé-de-Braise',
            'instance_id' => $instance->id,
            'cible' => ['personnage_id' => $perso->id, 'nom' => 'Thora'],
            'touches' => 2, 'boucliers' => 0, 'degats' => 2,
            'faces_attaque' => ['crane', 'crane'], 'faces_defense' => ['crane'],
            'face_touchante' => 'crane', 'face_defensive' => 'bouclier_blanc',
        ]]],
    ];

    expect(JournalCombat::actionsDuTour($resultat))->toHaveCount(2);

    $scene = scenesDe($resultat, $perso)[0];
    expect($scene['titre'])->toContain('Forgé-de-Braise')
        ->and($scene['acteurs'][0]['role'])->toBe('attaquant')
        ->and($scene['acteurs'][1]['role'])->toBe('defenseur');
});

it('publie TOUS les champs du contrat, et rien de plus', function () {
    // ⚠ Le test DANS LES DEUX SENS : rien d'émis qui ne soit au contrat, rien
    // au contrat qui ne soit émis. C'est ce qui empêche une clé décorative de
    // s'installer — un champ que personne ne lit est une promesse morte.
    $instance = sceneInstanceMonstre();

    $scene = scenesDe([
        'type' => 'attaque',
        'cible' => ['instance_id' => $instance->id, 'nom' => 'Gobelin'],
        'touches' => 1, 'boucliers' => 0, 'degats' => 1,
        'faces_attaque' => ['crane'], 'faces_defense' => [],
        'face_touchante' => 'crane', 'face_defensive' => 'bouclier_noir',
    ])[0];

    expect(array_keys($scene))->toEqualCanonicalizing(CHAMPS_SCENE);

    foreach ($scene['acteurs'] as $acteur) {
        expect(array_keys($acteur))->toEqualCanonicalizing(['role', 'nom', 'image_url', 'pv'])
            // Jamais de cadre vide : la chaîne de repli finit sur un emblème SVG.
            ->and($acteur['image_url'])->toBeString()->not->toBeEmpty();
    }
});

it('résout une image même sans illustration générée (emblème de repli)', function () {
    // Le jeu tourne sans clé d'IA — règle du projet, pas mode dégradé. Une
    // scène doit donc rester montrable sur une installation vierge.
    $images = app(BibliothequeImages::class);

    expect($images->urlHeros(4242, 'barbare'))->toBeString()->not->toBeEmpty()
        ->and($images->urlMonstre(4242, null, null))->toContain('/api/placeholder/');
});
