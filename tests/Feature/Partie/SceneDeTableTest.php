<?php

declare(strict_types=1);

use App\Engine\MotsClesEquipement;
use App\Engine\ReactionEffet;
use App\Events\SceneTable;
use App\Models\Carte;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\Images\BibliothequeImages;
use App\Partie\JournalCombat;
use App\Partie\SceneDeTable;
use App\Partie\TamponScenes;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\Event;

/**
 * Scènes illustrées de l'écran de table (`.table.scene`).
 *
 * Le moteur rend un résultat structuré que `JournalCombat` APLATIT en texte :
 * les identités y meurent, donc plus aucune image n'y est résolvable. Ces tests
 * fixent l'autre sortie — celle qui sert à MONTRER.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, ObjetSeeder::class,
        PiegeSeeder::class, GabaritQueteSeeder::class, SortSeeder::class, MobilierSeeder::class]);
});

/** Les champs du contrat (docs/contrat-api.md, `.table.scene`). */
const CHAMPS_SCENE = ['genre', 'titre', 'sous_titre', 'acteurs', 'jet', 'deplacement', 'figure', 'objets', 'issue'];

function sceneHeros(string $nom, string $classe = 'nain'): Personnage
{
    static $n = 0;
    $joueur = connecterJoueur('j'.(++$n));

    return creerHeros($joueur, creerGroupe('table-'.$n), $nom, $n, ['classe' => $classe]);
}

/** Une instance de monstre réelle : c'est elle qui porte le portrait dynamique. */
function sceneInstanceMonstre(): InstanceMonstre
{
    static $q = 0;
    $monstre = Monstre::query()->firstOrFail();
    $quete = Quete::create([
        'groupe_id' => creerGroupe('g-mon-'.(++$q))->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Quête d\'essai',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
    ]);

    return InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => $monstre->id,
        'pv_body' => $monstre->pv_body,
        'pv_body_max' => $monstre->pv_body,
        'pv_mind' => $monstre->pv_mind,
        'position_x' => 1, 'position_y' => 1,
        'etat' => 'actif',
    ]);
}

function scenesDe(array $resultat, ?Personnage $acteur = null): array
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

    // ⚠ Depuis le 2026-09-14 l'issue dit aussi POURQUOI : sans le compte,
    // « paré » et « manqué » se ressemblent trop pour apprendre quoi que ce soit
    // du jet qu'on vient de voir.
    expect(scenesDe($base + ['touches' => 0])[0]['issue']['libelle'])->toBe('manqué — aucun crâne')
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
    $objet = Objet::query()->firstOrFail();

    $scene = scenesDe([
        'type' => 'fouille_tresor',
        'issue' => 'potion',
        'objet' => ['id' => $objet->id, 'nom' => $objet->nom, 'categorie' => $objet->categorie],
    ])[0];

    expect($scene['genre'])->toBe('fouille')
        ->and($scene['objets'][0]['nom'])->toBe($objet->nom)
        ->and($scene['issue']['ton'])->toBe('tresor')
        // ⚠ L'issue dit ce qui s'est PASSÉ ; le nom de l'objet est déjà écrit
        // sous son illustration, le répéter faisait doublon à l'écran.
        ->and($scene['issue']['libelle'])->not->toBe($objet->nom)
        ->and($scene['issue']['libelle'])->toContain('empoche');
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

it('met un sort en scène avec sa carte, sans « vs » sur un soin', function () {
    $sort = Sort::query()->firstOrFail();
    $acteur = sceneHeros('Aldric', 'magicien');
    $soigne = sceneHeros('Thora', 'elfe');

    $scene = scenesDe([
        'type' => 'sort',
        'sort' => ['id' => $sort->id, 'nom' => $sort->nom, 'element' => $sort->element, 'type' => $sort->type],
        'cible' => ['personnage_id' => $soigne->id, 'nom' => 'Thora'],
        'soin' => 4,
    ], $acteur)[0];

    expect($scene['genre'])->toBe('sort')
        ->and($scene['objets'][0]['nom'])->toBe($sort->nom)
        ->and($scene['issue']['libelle'])->toBe('+4 PV rendus')
        // ⚠ Aucun rôle `defenseur` : un soin n'est pas un affrontement, et c'est
        // le RÔLE — pas le nombre d'acteurs — qui commande le « vs » à l'écran.
        ->and(collect($scene['acteurs'])->pluck('role')->all())->toBe(['acteur', 'cible']);
});

it('montre le contenu d\'une salle révélée — créatures et mobilier, jamais les pièges', function () {
    $groupe = creerGroupe('g-salle');
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Salle', 'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours',
    ]);
    $meuble = Mobilier::query()->firstOrFail();
    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => 100, 'hauteur' => 100,
        'grille' => [
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 4]],
            'mobiliers' => [
                ['x' => 1, 'y' => 1, 'l' => 1, 'h' => 1, 'mobilier_id' => $meuble->id],
                ['x' => 90, 'y' => 90, 'l' => 1, 'h' => 1, 'mobilier_id' => $meuble->id], // hors salle
            ],
            'pieges' => [['x' => 2, 'y' => 2, 'piege_id' => 1]],
        ],
    ]);

    $scene = app(SceneDeTable::class)->salle($quete->fresh(), 0, []);

    expect($scene['genre'])->toBe('salle')
        // Le meuble DE la salle, pas celui d'à côté.
        ->and($scene['objets'])->toHaveCount(1)
        ->and($scene['objets'][0]['nom'])->toBe($meuble->nom)
        // ⚠ Les pièges restent cachés jusqu'à la fouille : les montrer ici
        // retournerait la règle.
        ->and(collect($scene['objets'])->pluck('nom')->all())->not->toContain('Fosse');
});

it('ne montre rien d\'une salle vide — le récit suffit', function () {
    $groupe = creerGroupe('g-vide');
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Vide', 'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours',
    ]);
    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => 100, 'hauteur' => 100,
        'grille' => ['salles' => [['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 3]], 'mobiliers' => []],
    ]);

    expect(app(SceneDeTable::class)->salle($quete->fresh(), 0, []))->toBeNull();
});

it('dit qu\'un héros tombé reste RELEVABLE, et le relèvement aussi', function () {
    // ⚠ À 0 PV un héros est TOMBÉ, pas mort (P1/C4). Sans cette mention la table
    // croit la partie finie pour lui.
    $heros = sceneHeros('Grom', 'barbare');
    $scenes = app(SceneDeTable::class);

    $chute = $scenes->chute($heros, true);
    $releve = $scenes->chute($heros, false);

    expect($chute['genre'])->toBe('chute')
        ->and($chute['sous_titre'])->toContain('Relevable')
        ->and($chute['issue']['ton'])->toBe('mort')
        ->and($releve['titre'])->toContain('se relève')
        ->and($releve['issue']['ton'])->toBe('tresor');
});

it('publie les mêmes champs de contrat sur TOUS les genres', function () {
    // Le test dans les deux sens, étendu aux genres qui n'ont ni acteur ni dé :
    // une scène de salle ne doit pas inventer de clé, ni en perdre une.
    $heros = sceneHeros('Borin');
    $groupe = creerGroupe('g-tous');
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'T', 'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours',
    ]);
    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => 100, 'hauteur' => 100,
        'grille' => [
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 3]],
            'mobiliers' => [['x' => 1, 'y' => 1, 'l' => 1, 'h' => 1,
                'mobilier_id' => Mobilier::query()->firstOrFail()->id]],
        ],
    ]);
    $scenes = app(SceneDeTable::class);

    $debutDeTour = $scenes->deplacement($heros, ['base' => 5, 'des' => [4], 'bonus_equipement' => 0,
        'malus' => 0, 'total_jet' => 9, 'multiplicateur' => 1, 'bonus_potion' => 0, 'portee' => 9]);

    foreach ([$scenes->chute($heros, true), $scenes->salle($quete->fresh(), 0, []), $debutDeTour] as $scene) {
        expect(array_keys($scene))->toEqualCanonicalizing(CHAMPS_SCENE)
            ->and($scene['genre'])->toBeIn(SceneDeTable::GENRES);
    }
});

it('accorde l\'issue d\'une salle avec le nombre de créatures', function () {
    // ⚠ « 1 créature à l'intérieur » suivi de « Elles vous ont vus » se
    // contredisait à l'écran — vu en partie réelle le 2026-09-14.
    $groupe = creerGroupe('g-accord');
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'A', 'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours',
    ]);
    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 100, 'hauteur' => 100,
        'grille' => ['salles' => [['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 4]], 'mobiliers' => []],
    ]);
    $scenes = app(SceneDeTable::class);
    $un = sceneInstanceMonstre();

    expect($scenes->salle($quete->fresh(), 0, [$un])['sous_titre'])->toBe('1 créature à l\'intérieur')
        ->and($scenes->salle($quete->fresh(), 0, [$un])['issue']['libelle'])->toBe('Elle vous a vus')
        ->and($scenes->salle($quete->fresh(), 0, [$un, sceneInstanceMonstre()])['issue']['libelle'])
        ->toBe('Elles vous ont vus');
});

it('affiche les EFFETS d\'un objet trouvé, pas seulement sa catégorie', function () {
    // ⚠ Les phrases viennent de `MotsClesEquipement::avantages()`, le vocabulaire
    // déjà utilisé par l'étal, le sac et le menu d'action. En réécrire ici ferait
    // dériver l'écran de table au premier mot-clé qui change.
    $epee = Objet::where('nom', 'Épée large')->firstOrFail();

    $scene = scenesDe([
        'type' => 'fouille_mobilier', 'issue' => 'objet',
        'objet' => ['id' => $epee->id, 'nom' => $epee->nom, 'categorie' => $epee->categorie],
    ])[0];

    expect($scene['objets'][0]['detail'])
        ->toBe(implode(' · ', MotsClesEquipement::avantages((array) $epee->effet)))
        ->and($scene['objets'][0]['detail'])->toContain('dés d\'attaque');
});

it('affiche le bloc de stats des créatures d\'une salle', function () {
    $groupe = creerGroupe('g-stats');
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'S', 'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours',
    ]);
    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 100, 'hauteur' => 100,
        'grille' => ['salles' => [['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 4]], 'mobiliers' => []],
    ]);
    $instance = sceneInstanceMonstre();
    $m = $instance->monstre;

    $scene = app(SceneDeTable::class)->salle($quete->fresh(), 0, [$instance]);

    expect($scene['objets'][0]['detail'])
        ->toContain('Att '.$m->attaque)
        ->toContain('Déf '.$m->defense)
        // ⚠ Les PV viennent de l'INSTANCE (elle a pu être blessée), le reste du
        // catalogue — l'habillage de l'IA ne touche jamais aux chiffres.
        ->toContain($instance->pv_body.' PV')
        ->toContain('dépl. '.$m->deplacement);
});

it('met la chute EN TAMPON, pour qu\'elle passe après le coup qui l\'a causée', function () {
    // ⚠ DÉFAUT MESURÉ (René, 2026-09-14). L'observateur de `tombe` se déclenche
    // au moment où les PV touchent zéro — AU MILIEU de la résolution —, alors que
    // la scène de l'attaque ne part qu'une fois le tour résolu. La table montrait
    // le héros à terre AVANT le coup qui l'y avait mis.
    Event::fake([SceneTable::class]);
    $heros = sceneHeros('Grom', 'barbare');
    $tampon = app(TamponScenes::class);

    $tampon->ajouter($heros->groupeActif ?? creerGroupe('g-tampon'),
        app(SceneDeTable::class)->chute($heros, true));

    // Rien n'est parti tant qu'on n'a pas vidé : c'est tout l'intérêt.
    Event::assertNotDispatched(SceneTable::class);

    $tampon->vider();
    Event::assertDispatched(SceneTable::class, 1);

    // Idempotent : un second vidage ne rediffuse rien.
    $tampon->vider();
    Event::assertDispatched(SceneTable::class, 1);
});

it('dit ce que le jet RAPPORTE, pas seulement qu\'il est réussi', function () {
    // ⚠ René, 2026-09-14 : « pour les jets d'attribut, il faudrait aussi dire ce
    // que donne le résultat ». « Réussi » ne dit pas ce qu'on gagne.
    $parchemin = Objet::query()->firstOrFail(); // n'importe quelle pièce du catalogue

    $or = scenesDe([
        'type' => 'jet', 'libelle' => 'Desceller la dalle — jet de Body',
        'jet' => ['succes' => 2, 'difficulte' => 2], 'or' => 100,
    ])[0];
    expect($or['issue']['libelle'])->toBe("+100 pièces d'or pour le groupe");

    $objet = scenesDe([
        'type' => 'jet', 'libelle' => 'Grimoire à demi calciné — jet de Mind',
        'jet' => ['succes' => 2, 'difficulte' => 2],
        'objet' => ['id' => $parchemin->id, 'nom' => $parchemin->nom],
    ])[0];
    expect($objet['issue']['libelle'])->toBe($parchemin->nom)
        // L'objet gagné mérite son illustration, comme toute trouvaille.
        ->and($objet['objets'][0]['nom'])->toBe($parchemin->nom);

    $fouille = scenesDe([
        'type' => 'jet', 'libelle' => 'Fouiller la zone — jet de Mind',
        'jet' => ['succes' => 1, 'difficulte' => 1],
        'pieges_reveles' => [['x' => 1, 'y' => 1]],
        'portes_revelees' => [['x' => 2, 'y' => 2], ['x' => 3, 'y' => 3]],
    ])[0];
    expect($fouille['issue']['libelle'])->toBe('1 piège repéré · 2 passages secrets');

    // ⚠ Une mécanique sans lecteur retombe sur « réussi » — jamais sur une
    // phrase inventée.
    $muet = scenesDe([
        'type' => 'jet', 'libelle' => 'Épreuve inconnue',
        'jet' => ['succes' => 3, 'difficulte' => 3],
    ])[0];
    expect($muet['issue']['libelle'])->toBe('réussi');
});

it('dit les dégâts ET la mise hors de combat sur un coup fatal', function () {
    // ⚠ René, 2026-09-14 : « tu affiches −2 PV, mais faudrait-il pas afficher
    // −2 PV, l'adversaire est défait ? » Un coup fatal ne disait que « est
    // terrassé » : on perdait le chiffre, qui est la moitié de l'information.
    $instance = sceneInstanceMonstre();

    $scene = scenesDe([
        'type' => 'attaque',
        'cible' => ['instance_id' => $instance->id, 'nom' => 'Orque'],
        'touches' => 3, 'boucliers' => 0, 'degats' => 2, 'cible_vaincue' => true,
        'faces_attaque' => ['crane'], 'faces_defense' => [],
        'face_touchante' => 'crane', 'face_defensive' => 'bouclier_noir',
    ])[0];

    expect($scene['issue']['libelle'])->toBe('−2 PV · Orque est terrassé');
});

it('dit POURQUOI un coup n\'a rien fait — paré ou manqué, et avec quoi', function () {
    $instance = sceneInstanceMonstre();
    $base = ['type' => 'attaque', 'cible' => ['instance_id' => $instance->id, 'nom' => 'Orque'], 'degats' => 0];

    expect(scenesDe($base + ['touches' => 0, 'boucliers' => 0])[0]['issue']['libelle'])
        ->toBe('manqué — aucun crâne')
        ->and(scenesDe($base + ['touches' => 2, 'boucliers' => 2])[0]['issue']['libelle'])
        ->toBe('paré — 2 boucliers');
});

it('réunit une frappe BALAYÉE en UNE scène, une vignette par cible', function () {
    // ⚠ Une scène, pas une par cible : trois popups d'affilée pour un seul geste
    // noieraient la table, et la file n'en garde qu'une en attente de toute façon.
    $a = sceneInstanceMonstre();
    $b = sceneInstanceMonstre();

    $scene = scenesDe([
        'type' => 'attaque_balayee', 'capacite' => 'Fauchaison', 'cibles' => 2, 'vaincus' => 1,
        'frappes' => [
            ['cible' => ['instance_id' => $a->id, 'nom' => 'Gobelin'], 'degats' => 1,
                'touches' => 1, 'boucliers' => 0, 'cible_vaincue' => true],
            ['cible' => ['instance_id' => $b->id, 'nom' => 'Orque'], 'degats' => 0,
                'touches' => 0, 'boucliers' => 0],
        ],
    ])[0];

    expect($scene['genre'])->toBe('attaque')
        ->and($scene['titre'])->toContain('Fauchaison')
        ->and($scene['objets'])->toHaveCount(2)
        ->and($scene['objets'][0]['detail'])->toContain('terrassé')
        ->and($scene['objets'][1]['detail'])->toBe('manqué — aucun crâne')
        ->and($scene['issue']['libelle'])->toBe('1 abattu');
});

it('aligne toutes les figures atteintes par un sort de ZONE', function () {
    $sort = Sort::query()->firstOrFail();
    $a = sceneInstanceMonstre();
    $b = sceneInstanceMonstre();

    $scene = scenesDe([
        'type' => 'sort',
        'sort' => ['id' => $sort->id, 'nom' => 'Flamme hypnotique', 'element' => 'elfique'],
        'zone' => true, 'salle' => 0,
        'touches' => [
            ['type' => 'monstre', 'instance_id' => $a->id, 'nom' => 'Gobelin', 'de' => 5],
            ['type' => 'monstre', 'instance_id' => $b->id, 'nom' => 'Orque', 'de' => 6],
        ],
    ])[0];

    expect($scene['sous_titre'])->toBe('2 figures atteintes')
        // la carte du sort, puis une vignette par figure touchée
        ->and($scene['objets'])->toHaveCount(3)
        ->and($scene['objets'][1]['detail'])->toBe('atteint');
});

it('met en scène un levier actionné — et dit qu\'on peut réessayer', function () {
    // ⚠ Le forçage est RETENTABLE SANS LIMITE. Le dire fait partie du message :
    // sans cela un échec se lit comme un cul-de-sac et le groupe s'éloigne d'un
    // mécanisme qu'il aurait pu retenter. Même arbitrage que le fil de combat.
    $ok = scenesDe([
        'type' => 'actionner_levier', 'force' => true,
        'jet' => ['succes' => 2, 'difficulte' => 2],
        'portes_ouvertes' => [['x' => 3, 'y' => 4]],
    ])[0];
    $ko = scenesDe([
        'type' => 'actionner_levier', 'force' => false,
        'jet' => ['succes' => 0, 'difficulte' => 2], 'portes_ouvertes' => [],
    ])[0];

    expect($ok['titre'])->toContain('levier')
        ->and($ok['issue']['libelle'])->toBe("1 porte s'ouvre")
        ->and($ok['objets'][0]['detail'])->toBe('actionné')
        ->and($ko['issue']['libelle'])->toContain('réessayer');
});

it('montre le meuble fracassé et ce qu\'il rendait', function () {
    // ⚠ Le butin d'un meuble est NICHÉ sous `butin` et jamais fusionné à plat :
    // le payload d'un jet porte déjà son propre `issue` (le résultat du DÉ), et
    // les mettre au même niveau écrasait l'un par l'autre.
    $meuble = Mobilier::where('nom', 'Coffre')->first()
        ?? Mobilier::query()->firstOrFail();

    $scene = scenesDe([
        'type' => 'jet', 'libelle' => 'Fracasser '.$meuble->nom.' — jet de Body',
        'jet' => ['succes' => 2, 'difficulte' => 2],
        'mobilier' => $meuble->nom, 'detruit' => true,
        'butin' => ['issue' => 'tresor', 'or' => 45],
    ])[0];

    expect($scene['objets'][0]['nom'])->toBe($meuble->nom)
        // Une tournure SANS accord : aucun genre n'est lisible au catalogue.
        ->and($scene['objets'][0]['detail'])->toBe('en morceaux')
        ->and($scene['issue']['libelle'])->toBe("+45 pièces d'or pour le groupe");
});

it('distingue une potion bue sur SOI d\'une potion tendue à un voisin', function () {
    // ⚠ Le moteur le dit déjà : `porteur_id` n'est présent que lorsque la potion
    // CHANGE DE MAIN, et vaut null sur soi. On ne redéduit rien — c'est le
    // payload qui tranche.
    $grom = sceneHeros('Grom', 'barbare');
    $thora = sceneHeros('Thora', 'elfe');
    $potion = Objet::where('nom', 'Potion de soin')->first()
        ?? Objet::query()->firstOrFail();

    $soi = scenesDe([
        'type' => 'potion', 'objet' => $potion->nom,
        'personnage_id' => $grom->id, 'porteur_id' => null,
        'effets' => ['soin_pv_body' => 4],
    ], $grom)[0];

    $voisin = scenesDe([
        'type' => 'potion', 'objet' => $potion->nom,
        'personnage_id' => $thora->id, 'porteur_id' => $grom->id,
        'effets' => ['soin_pv_body' => 3],
    ], $grom)[0];

    expect($soi['genre'])->toBe('objet')
        ->and($soi['titre'])->toBe('Grom boit '.$potion->nom)
        ->and($soi['sous_titre'])->toBeNull()
        ->and($soi['acteurs'])->toHaveCount(1)
        ->and($soi['issue']['libelle'])->toContain('+4 PV de Body')
        // Le porteur ET le buveur, dans cet ordre.
        ->and($voisin['titre'])->toBe('Grom tend '.$potion->nom.' à Thora')
        ->and($voisin['sous_titre'])->toBe('À un héros adjacent')
        ->and(collect($voisin['acteurs'])->pluck('role')->all())->toBe(['acteur', 'cible']);
});

it('dit le soin RÉELLEMENT rendu, pas celui promis par la carte', function () {
    // Boire une potion de 4 PV à un point du maximum n'en rend qu'un : c'est
    // `effets.soin_pv_body` que le moteur calcule, jamais la valeur de la carte.
    $grom = sceneHeros('Grom', 'barbare');
    $potion = Objet::query()->firstOrFail();

    $rien = scenesDe(['type' => 'potion', 'objet' => $potion->nom,
        'personnage_id' => $grom->id, 'effets' => []], $grom)[0];

    expect($rien['issue']['ton'])->toBe('info')
        ->and($rien['issue']['libelle'])->toContain('jauges étaient pleines');
});

/** Le jet de début de tour, avec des valeurs par défaut sans aucun ajustement. */
function sceneDeplacement(Personnage $heros, array $d): array
{
    return app(SceneDeTable::class)->deplacement($heros, $d + [
        'bonus_equipement' => 0, 'de_annule' => false, 'de_annule_par' => null,
        'multiplicateur' => 1, 'bonus_potion' => 0,
    ]);
}

it('annonce le début du tour avec le dé et le calcul du déplacement', function () {
    $grom = sceneHeros('Grom', 'barbare');

    $scene = sceneDeplacement($grom, ['base' => 5, 'des' => [4], 'total_jet' => 9, 'portee' => 9]);

    expect($scene['genre'])->toBe('deplacement')
        ->and($scene['titre'])->toBe('Au tour de Grom')
        // Élision devant une voyelle, accentuée comprise.
        ->and(sceneDeplacement(sceneHeros('Aldric', 'magicien'), ['base' => 4, 'des' => [6], 'total_jet' => 10, 'portee' => 10])['titre'])
        ->toBe("Au tour d'Aldric")
        ->and(sceneDeplacement(sceneHeros('Éowyn', 'elfe'), ['base' => 5, 'des' => [1], 'total_jet' => 6, 'portee' => 6])['titre'])
        ->toBe("Au tour d'Éowyn")
        ->and($scene['sous_titre'])->toBe('Jet de déplacement')
        ->and($scene['acteurs'])->toHaveCount(1)
        ->and($scene['jet'])->toBeNull()
        ->and($scene['deplacement'])->toBe(['des' => [4], 'calcul' => 'base 5 + dé 4 = 9', 'de_annule' => false, 'de_annule_par' => null])
        ->and($scene['issue'])->toBe(['ton' => 'info', 'libelle' => '9 cases ce tour']);
});

it('nomme chaque ajustement du jet au lieu de les fondre dans le total', function () {
    // ⚠ C'est précisément ce que l'option `se_deplacer` ne sait pas faire : elle
    // reconstitue le dé par `total − base`, et affiche donc un dé faux dès que
    // l'Armure de plates, des Raquettes ou un second dé s'en mêlent.
    $sylvaine = sceneHeros('Sylvaine', 'elfe');

    // Bottes elfiques (2 dés) ET Armure de plates (le d6 ne compte pas) : les
    // DEUX dés restent publiés dans `des`, mais aucun ne rejoint le calcul —
    // le porteur n'avance que de sa base (contrat, 2026-09-24).
    $bottes = sceneDeplacement($sylvaine,
        ['base' => 7, 'des' => [3, 5], 'de_annule' => true, 'de_annule_par' => 'Armure de plates', 'total_jet' => 7, 'portee' => 7]);

    expect($bottes['deplacement']['calcul'])->toBe('base 7 (Armure de plates : le dé ne compte pas) = 7')
        ->and($bottes['deplacement']['des'])->toBe([3, 5])
        ->and($bottes['deplacement']['de_annule'])->toBeTrue()
        ->and($bottes['deplacement']['de_annule_par'])->toBe('Armure de plates')
        ->and($bottes['sous_titre'])->toBe('Jet de déplacement — 2 dés');

    // Raquettes, Vent Véloce, potion de dextérité : les cases de la potion
    // s'ajoutent APRÈS le multiplicateur, comme dans le résolveur.
    $tout = sceneDeplacement($sylvaine, ['base' => 5, 'bonus_equipement' => 2, 'des' => [4],
        'total_jet' => 11, 'multiplicateur' => 2, 'bonus_potion' => 5, 'portee' => 27]);

    expect($tout['deplacement']['calcul'])->toBe('(base 5 + 2 (équipement) + dé 4) × 2 + 5 (potion) = 27');
});

it('ne montre pas une soustraction qui contredit le plancher d\'une case', function () {
    // « le total ne descend jamais sous une case » : une base de 0 avec le dé
    // ANNULÉ donnerait 0 sur le papier, le jet en vaut 1, et la phrase doit le
    // dire plutôt que d'afficher « = 1 » après un calcul qui fait 0.
    $borin = sceneHeros('Borin');

    $scene = sceneDeplacement($borin,
        ['base' => 0, 'des' => [4], 'de_annule' => true, 'de_annule_par' => 'Armure de plates', 'total_jet' => 1, 'portee' => 1]);

    expect($scene['deplacement']['calcul'])->toBe('base 0 (Armure de plates : le dé ne compte pas) → au moins 1 = 1')
        ->and($scene['issue']['libelle'])->toBe('1 case ce tour');
});

it('a une icône côté table pour CHAQUE genre, et aucune pour un genre inconnu', function () {
    // Le registre dans les deux sens, jusqu'à l'écran : un genre sans icône
    // s'afficherait sous l'éclair générique, une icône sans genre serait une
    // clé décorative.
    $vue = file_get_contents(resource_path('js/components/table/SceneEvenement.vue'));
    preg_match('/const ICONE_GENRE = \{(.*?)\};/s', $vue, $bloc);
    preg_match_all('/^\s*(\w+):/m', $bloc[1] ?? '', $cles);

    expect($cles[1])->toEqualCanonicalizing(SceneDeTable::GENRES);
});

it('fait attendre la table la fin de la MARCHE de la figurine — seulement si elle a marché', function () {
    // `figure` veut dire « attends ce trajet » : publiée SEULEMENT pour une
    // figurine qui a marché dans la même résolution. La scène arrive souvent
    // AVANT l'état qui porte les trajets (deux workers) : la table ne peut pas
    // deviner qu'une marche est en route, le serveur le lui dit.
    $borin = sceneHeros('Borin');
    $instance = sceneInstanceMonstre();
    $coup = [
        'type' => 'attaque_monstre', 'monstre' => 'Gobelin', 'instance_id' => $instance->id,
        'cible' => ['personnage_id' => $borin->id, 'nom' => 'Borin'],
        'touches' => 1, 'boucliers' => 0, 'degats' => 1,
    ];
    $scenes = app(SceneDeTable::class);

    expect($scenes->depuisResultat($coup, $borin, ['monstre:'.$instance->id])[0]['figure'])
        ->toBe('monstre:'.$instance->id)
        // Déjà au contact, il n'a pas marché : rien à attendre.
        ->and($scenes->depuisResultat($coup, $borin, [])[0]['figure'])->toBeNull()
        // Un autre a marché, pas lui : rien à attendre non plus.
        ->and($scenes->depuisResultat($coup, $borin, ['heros:'.$borin->id])[0]['figure'])->toBeNull()
        // La chute n'en décide pas seule : c'est le tampon qui connaît la résolution.
        ->and($scenes->chute($borin, true)['figure'])->toBeNull();
});

it('fait attendre la CHUTE d\'un héros qui a marché (piège en chemin), pas celle d\'un héros frappé sur place', function () {
    Event::fake([SceneTable::class]);
    $borin = sceneHeros('Borin');
    $groupe = creerGroupe('g-tampon');
    $tampon = new TamponScenes;

    $tampon->ajouter($groupe, app(SceneDeTable::class)->chute($borin, true), 'heros:'.$borin->id);
    $tampon->vider(['heros:'.$borin->id]);
    $tampon->ajouter($groupe, app(SceneDeTable::class)->chute($borin, true), 'heros:'.$borin->id);
    $tampon->vider([]);

    $figures = Event::dispatched(SceneTable::class)
        ->map(fn (array $appel) => $appel[0]->scene['figure'])->values()->all();

    expect($figures)->toBe(['heros:'.$borin->id, null]);
});

it('met en scène une réaction ACCEPTÉE depuis une manette — plancher et dé de l\'artefact', function () {
    $grom = sceneHeros('Grom', 'barbare');

    $scenes = app(SceneDeTable::class)->depuisReaction([
        'type' => 'reaction', 'active' => true, 'personnage' => 'Grom', 'victime' => 'Grom',
        'victime_id' => $grom->id, 'sort' => 'Cendres du Phénix',
        'action' => ReactionEffet::PLANCHER_PV, 'degats_annules' => 0,
        'artefact' => 'Cendres du Phénix', 'de_artefact' => 3, 'artefact_perdu' => false,
    ], $grom);

    expect($scenes)->toHaveCount(1);
    $scene = $scenes[0];

    expect(array_keys($scene))->toEqualCanonicalizing(CHAMPS_SCENE)
        ->and($scene['genre'])->toBe('reaction')
        ->and($scene['titre'])->toBe('Cendres du Phénix')
        ->and($scene['sous_titre'])->toBe('Grom réagit')
        ->and($scene['figure'])->toBeNull() // une réaction ne suit jamais une marche
        ->and($scene['objets'][0]['detail'])->toBe('dé 3 — conservé')
        // Le MÊME texte que le fil de combat : une réaction se dit d'une façon.
        ->and($scene['issue']['libelle'])->toBe("Grom reste à 1 PV — dé 3 : l'artefact est conservé");
});

it('montre le héros PROTÉGÉ par une parade, et la riposte comme une vraie frappe', function () {
    $chevalier = sceneHeros('Roland', 'chevalier');
    $borin = sceneHeros('Borin');
    $instance = sceneInstanceMonstre();
    $scenes = app(SceneDeTable::class);

    $parade = $scenes->depuisReaction([
        'type' => 'reaction', 'active' => true, 'personnage' => 'Roland', 'victime' => 'Borin',
        'victime_id' => $borin->id, 'sort' => 'Parade au bouclier',
        'action' => ReactionEffet::ANNULE_DEGATS_VOISIN, 'degats_annules' => 2,
    ], $chevalier)[0];

    expect($parade['sous_titre'])->toBe('Roland protège Borin')
        ->and(collect($parade['acteurs'])->pluck('role')->all())->toBe(['acteur', 'cible'])
        ->and($parade['issue']['libelle'])->toBe('2 dégâts annulés pour Borin');

    // Représailles : la frappe elle-même, avec ses dés, et le nom de la réaction.
    $riposte = $scenes->depuisReaction([
        'type' => 'reaction', 'active' => true, 'personnage' => 'Borin', 'sort' => 'Représailles',
        'action' => ReactionEffet::RIPOSTE,
        'frappe' => ['type' => 'attaque', 'cible' => ['instance_id' => $instance->id, 'nom' => 'Gobelin'],
            'touches' => 2, 'boucliers' => 0, 'degats' => 1],
    ], $borin);

    expect($riposte)->toHaveCount(1)
        ->and($riposte[0]['genre'])->toBe('attaque')
        ->and($riposte[0]['sous_titre'])->toBe('Représailles');

    // Refusée ou sans objet : aucune scène.
    expect($scenes->depuisReaction(['type' => 'reaction', 'active' => false], $borin))->toBe([]);
});
