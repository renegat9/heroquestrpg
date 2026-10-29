<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Mobilier;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\EtatGroupe;
use App\Partie\Images\BibliothequeImages;
use App\Partie\MoteurPieges;
use App\Partie\MoteurPortes;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * SYMBOLES DE LIVRET — le PICTOGRAMME officiel de chaque case (chantier symboles, René, 2026-10-24).
 *
 * Un symbole se dessine SUR la carte, dans la silhouette de sa famille ; l'illustration reste dans la légende. Le serveur
 * publie `symbole_url` (toujours présent, `null` par défaut) et le client ne compose aucune URL. Sans fichier, `null` : le
 * client garde l'icône Material Symbols. Un emblème SVG ici masquerait cette icône, donc jamais.
 *
 * ⚠ Ces tests POSENT des fichiers. Le vrai `public/images/livrets/` contient — ou contiendra — les symboles officiels, qui
 * sont dans le dépôt (René, 2026-10-24) : aucun test ne doit y toucher. Chaque test détourne `public_path()` vers un dossier
 * jetable (même discipline que ImagesMobilierLivretTest), et les faux symboles n'en sortent jamais.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class, TerrainSeeder::class]);
});

/** Détourne `public_path()` vers un dossier jetable : aucun fichier de ce test n'atteint `public/images/livrets/`. */
function symbolesDossierJetable(): string
{
    $dossier = sys_get_temp_dir().'/hq-symboles-'.bin2hex(random_bytes(4));
    mkdir($dossier.'/images', 0775, true);
    app()->usePublicPath($dossier);

    return $dossier;
}

/** Pose un faux symbole dans le dossier jetable. Seule l'existence du fichier compte pour l'accesseur. */
function symbolesPoser(string $relatif): void
{
    $chemin = public_path('images/livrets/'.$relatif);

    if (! is_dir(dirname($chemin))) {
        mkdir(dirname($chemin), 0775, true);
    }

    file_put_contents($chemin, 'WEBP');
}

/** Une quête démarrée par un héros, et sa carte. La salle de départ est toujours découverte, donc toujours visible. */
function symbolesQuete(): Quete
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    return Quete::findOrFail($groupe->fresh()->quete_courante_id);
}

/** Une case de la salle de départ, décalée depuis son coin haut-gauche : visible par construction. */
function symbolesCaseDepart(array $grille, int $dx, int $dy): array
{
    $salle = $grille['salles'][0];

    return ['x' => (int) $salle['x'] + $dx, 'y' => (int) $salle['y'] + $dy];
}

/**
 * Remplace les couches par les entrées voulues, pour que la réponse ne contienne QUE ce que le test a posé. La quête
 * générée peut déjà porter du mobilier ou des pièges dans la salle de départ : ils fausseraient les assertions.
 */
function symbolesPoserCouches(Quete $quete, array $couches): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;

    foreach (['mobilier', 'pieges', 'terrain', 'leviers', 'dangers'] as $vide) {
        $grille[$vide] = [];
    }

    foreach ($couches as $cle => $valeur) {
        $grille[$cle] = $valeur;
    }

    $carte->update(['grille' => $grille]);
}

// =====================================================================
// 1. LE POINT DE PASSAGE — l'accesseur
// =====================================================================

it('rend null sans fichier : le repli est l\'icône Material, jamais un emblème', function () {
    symbolesDossierJetable();
    $biblio = app(BibliothequeImages::class);

    expect($biblio->urlSymboleMobilier('Table'))->toBeNull()
        ->and($biblio->urlSymboleTerrain('Mare'))->toBeNull()
        ->and($biblio->urlSymbolePiege('Fosse', 'detecte'))->toBeNull()
        ->and($biblio->urlSymbolePorte('fermee', null))->toBeNull()
        ->and($biblio->urlSymboleLevier())->toBeNull()
        ->and($biblio->urlSymboleEscalier())->toBeNull()
        ->and($biblio->urlSymboleDanger('Rocher roulant', 'roule'))->toBeNull();
});

it('rend l\'URL publique du .webp sous le SLUG du nom de catalogue', function () {
    symbolesDossierJetable();
    symbolesPoser('mobiliers/forge-des-nains.webp');
    symbolesPoser('mobiliers/etabli-dalchimiste.webp');
    $biblio = app(BibliothequeImages::class);

    expect($biblio->urlSymboleMobilier('Forge des nains'))->toBe('/images/livrets/mobiliers/forge-des-nains.webp')
        // Str::slug retire l'apostrophe, comme pour le reste de la bibliothèque (`20-etabli-dalchimiste`).
        ->and($biblio->urlSymboleMobilier("Établi d'alchimiste"))->toBe('/images/livrets/mobiliers/etabli-dalchimiste.webp');
});

it('ne sert QUE le .webp : un .png seul ne donne aucun symbole', function () {
    symbolesDossierJetable();
    symbolesPoser('terrains/mare.png');

    expect(app(BibliothequeImages::class)->urlSymboleTerrain('Mare'))->toBeNull();
});

it('ne sert jamais un type hors du vocabulaire fermé, ni une clé vide, ni une traversée de dossier', function () {
    symbolesDossierJetable();
    symbolesPoser('bestiaire/gobelin.webp');    // hors du vocabulaire : un dossier qui existe ne fait pas un type
    symbolesPoser('mobiliers/table.webp');
    $biblio = app(BibliothequeImages::class);

    expect($biblio->urlSymboleLivret('bestiaire', 'gobelin'))->toBeNull()
        ->and($biblio->urlSymboleLivret('../mobiliers', 'table'))->toBeNull()
        ->and($biblio->urlSymboleLivret('mobiliers', '  '))->toBeNull()
        ->and($biblio->urlSymboleLivret('mobiliers', 'Table'))->toBe('/images/livrets/mobiliers/table.webp');
});

it('donne à la porte de PIERRE fermée son symbole, et à la même porte forcée celui de son état', function () {
    symbolesDossierJetable();
    foreach (['fermee', 'ouverte', 'verrouillee', 'secrete', 'verrou-pierre'] as $cle) {
        symbolesPoser("portes/{$cle}.webp");
    }
    $biblio = app(BibliothequeImages::class);

    // ⚠ La clé `verrou_pierre` est slugée : le fichier se nomme `verrou-pierre.webp`, jamais `verrou_pierre.webp`.
    expect($biblio->urlSymbolePorte('fermee', MoteurPortes::VERROU_PIERRE))->toBe('/images/livrets/portes/verrou-pierre.webp')
        ->and($biblio->urlSymbolePorte('ouverte', MoteurPortes::VERROU_PIERRE))->toBe('/images/livrets/portes/ouverte.webp')
        ->and($biblio->urlSymbolePorte('fermee', 'cle'))->toBe('/images/livrets/portes/fermee.webp')
        // Une porte à condition reste une porte fermée : aucun symbole propre n'est déclaré pour elle.
        ->and($biblio->urlSymbolePorte('fermee', MoteurPortes::VERROU_CONDITION))->toBe('/images/livrets/portes/fermee.webp')
        ->and($biblio->urlSymbolePorte('verrouillee', 'levier'))->toBe('/images/livrets/portes/verrouillee.webp')
        ->and($biblio->urlSymbolePorte('secrete', null))->toBe('/images/livrets/portes/secrete.webp');
});

it('ne donne un symbole de piège que dans les trois états où une icône se lit sur la case', function () {
    symbolesDossierJetable();
    symbolesPoser('pieges/fosse.webp');
    $biblio = app(BibliothequeImages::class);

    expect($biblio->urlSymbolePiege('Fosse', 'detecte'))->toBe('/images/livrets/pieges/fosse.webp')
        ->and($biblio->urlSymbolePiege('Fosse', 'desarme'))->toBe('/images/livrets/pieges/fosse.webp')
        // Le fichier EXISTE ici : seul l'état décide. Un trou ouvert, une mare, un bloc tombé se dessinent sans symbole.
        ->and($biblio->urlSymbolePiege('Fosse', 'fosse_ouverte'))->toBeNull()
        ->and($biblio->urlSymbolePiege('Fosse', 'mare_acide'))->toBeNull()
        ->and($biblio->urlSymbolePiege('Fosse', 'bloc'))->toBeNull()
        ->and($biblio->urlSymbolePiege('Fosse', 'declenche'))->toBeNull();
});

it('ne donne un symbole au danger mobile que tant qu\'il roule : un rocher arrêté est un bloc', function () {
    symbolesDossierJetable();
    symbolesPoser('dangers/rocher-roulant.webp');
    $biblio = app(BibliothequeImages::class);

    expect($biblio->urlSymboleDanger('Rocher roulant', 'roule'))->toBe('/images/livrets/dangers/rocher-roulant.webp')
        ->and($biblio->urlSymboleDanger('Rocher roulant', 'actif'))->toBe('/images/livrets/dangers/rocher-roulant.webp')
        ->and($biblio->urlSymboleDanger('Rocher roulant', 'bloc'))->toBeNull();
});

// =====================================================================
// 2. LA PUBLICATION — chaque couche porte `symbole_url`, décidé par le serveur
// =====================================================================

it('publie symbole_url sur CHAQUE entrée des couches, toujours présent, et null sans fichier', function () {
    symbolesDossierJetable();
    $quete = symbolesQuete();
    $grille = $quete->carte->grille;
    $table = Mobilier::where('nom', 'Table')->value('id');
    $fosse = Piege::where('nom', 'Fosse')->value('id');
    $mare = Terrain::where('nom', 'Mare')->value('id');
    $a = symbolesCaseDepart($grille, 1, 1);
    $b = symbolesCaseDepart($grille, 2, 1);
    $c = symbolesCaseDepart($grille, 1, 2);

    symbolesPoserCouches($quete, [
        'mobilier' => [['x' => $a['x'], 'y' => $a['y'], 'mobilier_id' => $table, 'l' => 1, 'h' => 1, 'salle' => 0]],
        'pieges' => [['x' => $b['x'], 'y' => $b['y'], 'piege_id' => $fosse, 'etat' => 'detecte']],
        'terrain' => [['x' => $c['x'], 'y' => $c['y'], 'terrain_id' => $mare]],
        'leviers' => [['x' => $a['x'], 'y' => $c['y'] + 1, 'difficulte' => 2]],
        'dangers' => [['nom' => 'Rocher roulant', 'x' => $b['x'], 'y' => $c['y'] + 1, 'etat' => 'roule']],
        'escalier' => ['x' => $grille['salles'][0]['x'], 'y' => $grille['salles'][0]['y'], 'l' => 2, 'h' => 2],
    ]);

    $carte = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte');

    foreach (['mobilier', 'pieges', 'terrain', 'leviers', 'dangers'] as $couche) {
        expect($carte[$couche])->not->toBe([], "la couche {$couche} n'est pas publiée : le test serait vide");

        foreach ($carte[$couche] as $entree) {
            expect(array_key_exists('symbole_url', $entree))->toBeTrue("{$couche} publié sans symbole_url");
            expect($entree['symbole_url'])->toBeNull("{$couche} : un symbole sans fichier");
        }
    }

    expect(array_key_exists('symbole_url', $carte['escalier']))->toBeTrue('escalier sans symbole_url')
        ->and($carte['escalier']['symbole_url'])->toBeNull();
});

it('publie l\'URL du symbole quand le fichier existe, pour chaque famille', function () {
    symbolesDossierJetable();
    foreach (['mobiliers/table.webp', 'pieges/fosse.webp', 'terrains/mare.webp', 'leviers/levier.webp',
        'dangers/rocher-roulant.webp', 'escalier/escalier.webp'] as $relatif) {
        symbolesPoser($relatif);
    }
    $quete = symbolesQuete();
    $grille = $quete->carte->grille;
    $table = Mobilier::where('nom', 'Table')->value('id');
    $fosse = Piege::where('nom', 'Fosse')->value('id');
    $mare = Terrain::where('nom', 'Mare')->value('id');
    $a = symbolesCaseDepart($grille, 1, 1);
    $b = symbolesCaseDepart($grille, 2, 1);

    symbolesPoserCouches($quete, [
        'mobilier' => [['x' => $a['x'], 'y' => $a['y'], 'mobilier_id' => $table, 'l' => 1, 'h' => 1, 'salle' => 0]],
        'pieges' => [['x' => $b['x'], 'y' => $b['y'], 'piege_id' => $fosse, 'etat' => 'detecte']],
        'terrain' => [['x' => $a['x'], 'y' => $b['y'] + 1, 'terrain_id' => $mare]],
        'leviers' => [['x' => $b['x'], 'y' => $b['y'] + 1, 'difficulte' => 2]],
        'dangers' => [['nom' => 'Rocher roulant', 'x' => $a['x'], 'y' => $b['y'] + 2, 'etat' => 'roule']],
        'escalier' => ['x' => $grille['salles'][0]['x'], 'y' => $grille['salles'][0]['y'], 'l' => 2, 'h' => 2],
    ]);

    $carte = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte');

    expect($carte['mobilier'][0]['symbole_url'])->toBe('/images/livrets/mobiliers/table.webp')
        ->and($carte['pieges'][0]['symbole_url'])->toBe('/images/livrets/pieges/fosse.webp')
        ->and($carte['terrain'][0]['symbole_url'])->toBe('/images/livrets/terrains/mare.webp')
        ->and($carte['leviers'][0]['symbole_url'])->toBe('/images/livrets/leviers/levier.webp')
        ->and($carte['dangers'][0]['symbole_url'])->toBe('/images/livrets/dangers/rocher-roulant.webp')
        ->and($carte['escalier']['symbole_url'])->toBe('/images/livrets/escalier/escalier.webp');
});

it('un BLOC FIXE porte son symbole sur son ANCRE seulement ; une case isolée porte le sien', function () {
    symbolesDossierJetable();
    symbolesPoser('terrains/mare.webp');
    symbolesPoser('terrains/glace-glissante.webp');
    $quete = symbolesQuete();
    $grille = $quete->carte->grille;
    $mare = Terrain::where('nom', 'Mare')->value('id');
    $glace = Terrain::where('nom', 'Glace glissante')->value('id');
    $a = symbolesCaseDepart($grille, 1, 1);

    // Un bloc de Mare 2×2 (quatre cases d'un même groupe) et une Glace glissante isolée, à côté.
    symbolesPoserCouches($quete, ['terrain' => [
        ['x' => $a['x'], 'y' => $a['y'], 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => $a['x'] + 1, 'y' => $a['y'], 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => $a['x'], 'y' => $a['y'] + 1, 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => $a['x'] + 1, 'y' => $a['y'] + 1, 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => $a['x'] + 3, 'y' => $a['y'], 'terrain_id' => $glace],
    ]]);

    $terrain = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.terrain');

    $plaque = array_values(array_filter($terrain, fn (array $e) => $e['groupe_id'] === 'bloc-1'));
    $avecSymbole = array_values(array_filter($plaque, fn (array $e) => $e['symbole_url'] !== null));
    $isolee = array_values(array_filter($terrain, fn (array $e) => $e['groupe_id'] === null));

    expect($plaque)->toHaveCount(4)
        ->and($avecSymbole)->toHaveCount(1, 'une plaque = UN symbole')
        ->and($avecSymbole[0]['ancre'])->toBeTrue('le symbole doit être posé sur l\'ancre')
        ->and($avecSymbole[0]['symbole_url'])->toBe('/images/livrets/terrains/mare.webp')
        ->and($isolee)->toHaveCount(1)
        ->and($isolee[0]['symbole_url'])->toBe('/images/livrets/terrains/glace-glissante.webp');
});

it('ne publie AUCUN symbole pour un piège caché : ni entrée, ni URL dans la réponse (le brouillard d\'abord)', function () {
    symbolesDossierJetable();
    symbolesPoser('pieges/fosse.webp');
    $quete = symbolesQuete();
    $grille = $quete->carte->grille;
    $fosse = Piege::where('nom', 'Fosse')->value('id');
    $visible = symbolesCaseDepart($grille, 1, 1);

    // Une case de la salle de départ (visible), et une case de la DERNIÈRE salle, que le brouillard masque.
    $derniere = $grille['salles'][count($grille['salles']) - 1];

    symbolesPoserCouches($quete, ['pieges' => [
        ['x' => $visible['x'], 'y' => $visible['y'], 'piege_id' => $fosse, 'etat' => 'cache'],
        ['x' => (int) $derniere['x'], 'y' => (int) $derniere['y'], 'piege_id' => $fosse, 'etat' => 'cache'],
    ]]);

    $reponse = $this->getJson('/api/groupes/table-1/etat')->assertOk();

    expect($reponse->json('carte.pieges'))->toBe([], 'un piège caché est publié')
        ->and($reponse->getContent())->not->toContain('/images/livrets/pieges/', 'un symbole de piège caché a fui');
});

it('porte le symbole de la porte selon son ÉTAT, sans l\'appliquer à une porte d\'un autre état', function () {
    symbolesDossierJetable();
    symbolesPoser('portes/verrou-pierre.webp');
    symbolesPoser('portes/fermee.webp');
    $carte = new Carte(['grille' => [
        'salles' => [['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 3]],
        'portes' => [
            ['x' => 2, 'y' => 1, 'cote' => 'e', 'etat' => 'fermee', 'verrou' => ['type' => 'pierre']],
            ['x' => 2, 'y' => 0, 'cote' => 'e', 'etat' => 'fermee'],
        ],
    ]]);

    $methode = new ReflectionMethod(EtatGroupe::class, 'portes');
    $methode->setAccessible(true);
    $portes = $methode->invoke(app(EtatGroupe::class), $carte);

    expect($portes[0]['symbole_url'])->toBe('/images/livrets/portes/verrou-pierre.webp', 'la porte de pierre fermée')
        ->and($portes[1]['symbole_url'])->toBe('/images/livrets/portes/fermee.webp', 'une porte fermée ordinaire');
});

it('LIT chaque état de piège que le serveur publie, dans la table de la carte ET dans l\'aperçu de salle', function () {
    // ⚠ `retient` (lianes qui tiennent un héros) manquait à ces deux tables : la table de la carte écartait le piège en
    // silence, et le héros immobilisé disparaissait. Le test relit les deux sources et exige que chaque état publié par
    // le moteur y figure. Il ne vérifie que ce sens-là : une clé lue sans être publiée ne casse rien.
    $publies = [
        MoteurPieges::ETAT_DETECTE, MoteurPieges::ETAT_DESARME, MoteurPieges::ETAT_DECLENCHE,
        MoteurPieges::ETAT_FOSSE_OUVERTE, MoteurPieges::ETAT_MARE, MoteurPieges::ETAT_BLOC, MoteurPieges::ETAT_RETIENT,
    ];

    foreach (['resources/js/store/game.js', 'resources/js/components/carte/ApercuSalle.vue'] as $fichier) {
        $source = file_get_contents(base_path($fichier));
        $debut = strpos($source, 'const PIEGE_ETATS = {');

        expect($debut)->not->toBeFalse("{$fichier} : la table PIEGE_ETATS a disparu");

        $bloc = substr($source, $debut, strpos($source, '};', $debut) - $debut);

        foreach ($publies as $etat) {
            expect(str_contains($bloc, "{$etat}:"))->toBeTrue("{$fichier} écarte en silence l'état « {$etat} » publié par le serveur");
        }
    }
});

// =====================================================================
// 5. LA ROTATION — décidée par le serveur (`symbole_rotation`), jamais par le client (correctifs symboles, 2026-10-24)
// =====================================================================

it('tourne le pictogramme quand le meuble est posé à l\'envers de son catalogue, et seulement alors', function () {
    // Le râtelier se dessine DEBOUT au catalogue (1×3) ; l'assembleur le pose aussi couché (3×1). Un carré ne se tourne jamais.
    $raclette = Mobilier::where('nom', "Râtelier d'armes")->firstOrFail();
    $bibliotheque = Mobilier::where('nom', 'Bibliothèque')->firstOrFail();
    $table = Mobilier::where('nom', 'Table')->firstOrFail();
    $coffre = Mobilier::where('nom', 'Coffre')->firstOrFail();

    expect(\App\Partie\MoteurMobilier::rotationSymbole(1, 3, $raclette))->toBe(0)           // debout, comme au catalogue
        ->and(\App\Partie\MoteurMobilier::rotationSymbole(3, 1, $raclette))->toBe(90)       // couché : la transposée
        ->and(\App\Partie\MoteurMobilier::rotationSymbole(3, 1, $bibliotheque))->toBe(0)    // couchée, comme au catalogue
        ->and(\App\Partie\MoteurMobilier::rotationSymbole(1, 3, $bibliotheque))->toBe(90)   // dressée : la transposée
        ->and(\App\Partie\MoteurMobilier::rotationSymbole(2, 3, $table))->toBe(90)          // table 3×2 dressée
        ->and(\App\Partie\MoteurMobilier::rotationSymbole(3, 2, $table))->toBe(0)           // table 3×2 couchée
        ->and(\App\Partie\MoteurMobilier::rotationSymbole(1, 1, $coffre))->toBe(0);         // un carré se lit dans les deux sens
});

it('publie symbole_rotation sur CHAQUE meuble : le râtelier couché tourne, le même debout et le carré non', function () {
    symbolesDossierJetable();
    $quete = symbolesQuete();
    $grille = $quete->carte->grille;
    $raclette = Mobilier::where('nom', "Râtelier d'armes")->value('id');
    $coffre = Mobilier::where('nom', 'Coffre')->value('id');
    $a = symbolesCaseDepart($grille, 1, 1);
    $b = symbolesCaseDepart($grille, 3, 1);
    $c = symbolesCaseDepart($grille, 1, 4);

    // `salle => 0` : la salle de départ est toujours découverte, donc ces meubles sont publiés.
    symbolesPoserCouches($quete, [
        'mobilier' => [
            ['x' => $a['x'], 'y' => $a['y'], 'mobilier_id' => $raclette, 'l' => 1, 'h' => 3, 'salle' => 0],
            ['x' => $b['x'], 'y' => $b['y'], 'mobilier_id' => $raclette, 'l' => 3, 'h' => 1, 'salle' => 0],
            ['x' => $c['x'], 'y' => $c['y'], 'mobilier_id' => $coffre, 'l' => 1, 'h' => 1, 'salle' => 0],
        ],
    ]);

    $meubles = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.mobilier');

    foreach ($meubles as $meuble) {
        expect(array_key_exists('symbole_rotation', $meuble))->toBeTrue('meuble publié sans symbole_rotation');
    }

    $parEmprise = collect($meubles)->mapWithKeys(fn (array $m) => ["{$m['nom']} {$m['l']}x{$m['h']}" => $m['symbole_rotation']]);

    expect($parEmprise->all())->toBe([
        "Râtelier d'armes 1x3" => 0,
        "Râtelier d'armes 3x1" => 90,
        'Coffre 1x1' => 0,
    ]);
});

it('une PORTE publie symbole_rotation : 90 entre l\'est et l\'ouest, 0 au nord-sud, et la clé est toujours là', function () {
    // René (2026-10-24) : « la porte devrait être retournée de 90 degrés quand elle est reliée à l'est ou à l'ouest ». Le
    // dessin du livret est une porte de mur HORIZONTAL (battant couché, nord-sud) : la décision se lit sur les deux cases
    // de la jonction, pas sur une supposition. Une porte ouverte n'a pas de dessin, mais sa rotation reste publiée.
    symbolesDossierJetable();
    symbolesPoser('portes/fermee.webp');
    $quete = symbolesQuete();
    $a = symbolesCaseDepart($quete->carte->grille, 1, 1);

    $carte = new Carte(['grille' => ['portes' => [
        ['x' => $a['x'], 'y' => $a['y'], 'cote' => 'e', 'etat' => 'fermee'],        // relie (x,y) à (x+1,y) : est-ouest
        ['x' => $a['x'] + 2, 'y' => $a['y'], 'cote' => 's', 'etat' => 'fermee'],    // relie (x,y) à (x,y+1) : nord-sud
        ['x' => $a['x'], 'y' => $a['y'] + 2, 'cote' => 'e', 'etat' => 'ouverte'],   // ouverte : sans dessin, rotation publiée
    ]]]);

    $methode = new ReflectionMethod(EtatGroupe::class, 'portes');
    $methode->setAccessible(true);
    $portes = $methode->invoke(app(EtatGroupe::class), $carte);

    expect(array_column($portes, 'symbole_rotation'))->toBe([90, 0, 90]);
});

it('une plaque RÉPÉTÉE (motif) porte son pictogramme sur CHAQUE case ; un BLOC FIXE ne le porte que sur son ancre, étendu sur son emprise', function () {
    // René (2026-10-24) : « pour les terrains, l'icône doit se répéter sur chaque case ». La Mare, elle, n'a qu'UN dessin (le
    // socle 2×2 du livret) : étendu sur ses quatre cases, pas quatre petits bassins.
    symbolesDossierJetable();
    symbolesPoser('terrains/jungle-entravante.webp');
    symbolesPoser('terrains/mare.webp');
    $quete = symbolesQuete();
    $grille = $quete->carte->grille;
    $jungle = Terrain::where('nom', 'Jungle entravante')->value('id');
    $mare = Terrain::where('nom', 'Mare')->value('id');
    $a = symbolesCaseDepart($grille, 1, 1);
    $b = symbolesCaseDepart($grille, 1, 3);

    symbolesPoserCouches($quete, ['terrain' => [
        ['x' => $a['x'], 'y' => $a['y'], 'terrain_id' => $jungle, 'groupe_id' => 'motif-1'],
        ['x' => $a['x'] + 1, 'y' => $a['y'], 'terrain_id' => $jungle, 'groupe_id' => 'motif-1'],
        ['x' => $a['x'] + 2, 'y' => $a['y'], 'terrain_id' => $jungle, 'groupe_id' => 'motif-1'],
        ['x' => $b['x'], 'y' => $b['y'], 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => $b['x'] + 1, 'y' => $b['y'], 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => $b['x'], 'y' => $b['y'] + 1, 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => $b['x'] + 1, 'y' => $b['y'] + 1, 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
    ]]);

    $terrain = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.terrain');
    $motif = array_values(array_filter($terrain, fn (array $e) => $e['groupe_id'] === 'motif-1'));
    $bloc = array_values(array_filter($terrain, fn (array $e) => $e['groupe_id'] === 'bloc-1'));
    $dessines = array_values(array_filter($bloc, fn (array $e) => $e['symbole_url'] !== null));

    expect(array_column($motif, 'symbole_url'))->toBe(array_fill(0, 3, '/images/livrets/terrains/jungle-entravante.webp'), 'un motif porte son dessin sur chaque case')
        ->and(array_column($motif, 'etendue'))->toBe([null, null, null], 'un motif répété n\'a plus d\'étendue')
        ->and($dessines)->toHaveCount(1, 'un bloc fixe n\'a qu\'un dessin')
        ->and($dessines[0]['ancre'])->toBeTrue('le dessin du bloc se pose sur son ancre')
        ->and($dessines[0]['symbole_url'])->toBe('/images/livrets/terrains/mare.webp')
        ->and($dessines[0]['etendue'])->toBe(['x' => $b['x'], 'y' => $b['y'], 'l' => 2, 'h' => 2], 'le dessin couvre l\'emprise 2×2');
});

it('sans fichier, une plaque répétée garde son icône Material UNIQUE sur l\'ancre, et l\'étendu de son repli ne bouge pas', function () {
    // Le repli du livret est un droit : sans pictogramme, rien n'est publié case par case. La ligne de trois garde son
    // étendue : l'icône de repli se centre sur la ligne, comme avant le chantier.
    symbolesDossierJetable();
    $quete = symbolesQuete();
    $jungle = Terrain::where('nom', 'Jungle entravante')->value('id');
    $a = symbolesCaseDepart($quete->carte->grille, 1, 1);

    symbolesPoserCouches($quete, ['terrain' => [
        ['x' => $a['x'], 'y' => $a['y'], 'terrain_id' => $jungle, 'groupe_id' => 'motif-1'],
        ['x' => $a['x'] + 1, 'y' => $a['y'], 'terrain_id' => $jungle, 'groupe_id' => 'motif-1'],
        ['x' => $a['x'] + 2, 'y' => $a['y'], 'terrain_id' => $jungle, 'groupe_id' => 'motif-1'],
    ]]);

    $motif = array_values(array_filter(
        $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.terrain'),
        fn (array $e) => $e['groupe_id'] === 'motif-1',
    ));
    $ancres = array_values(array_filter($motif, fn (array $e) => $e['ancre']));

    expect(array_column($motif, 'symbole_url'))->toBe([null, null, null])
        ->and($ancres)->toHaveCount(1, 'une icône par groupe')
        ->and($ancres[0]['etendue'])->toBe(['x' => $a['x'], 'y' => $a['y'], 'l' => 3, 'h' => 1], 'le repli centre son icône sur la ligne');
});

// =====================================================================
// 3. LE JETON DE MONSTRE — `symbole_url` sur chaque figure révélée (chantier jetons, 2026-10-29)
// =====================================================================

/** Une figure du catalogue posée et révélée sur une case de la salle de départ. */
function symbolesPoserMonstre(Quete $quete, string $nomBase, int $dx = 2, int $dy = 1): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nomBase)->firstOrFail();
    $case = symbolesCaseDepart($quete->carte->grille, $dx, $dy);

    return InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => $case['x'], 'position_y' => $case['y'], 'etat' => 'actif', 'revele' => true,
    ]);
}

/** Le `symbole_url` de la figure de ce nom, tel que l'ÉTAT le publie dans `entites` — jamais relu depuis la base. */
function symbolesSymboleMonstre(string $nomBase): ?string
{
    $entites = test()->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites');
    $figure = collect($entites)->first(fn (array $e) => $e['type'] === 'monstre' && $e['nom_base'] === $nomBase);

    expect($figure)->not->toBeNull("aucune figure {$nomBase} publiée : le test serait vide")
        ->and(array_key_exists('symbole_url', $figure))->toBeTrue('symbole_url absent de la figure publiée');

    return $figure['symbole_url'];
}

it('publie symbole_url sur chaque figure révélée, et null tant qu\'il n\'y a pas de fichier', function () {
    symbolesDossierJetable();
    symbolesPoserMonstre(symbolesQuete(), 'Gobelin');

    expect(symbolesSymboleMonstre('Gobelin'))->toBeNull();
});

it('publie l\'URL du jeton quand le fichier existe, et la retire avec lui', function () {
    symbolesDossierJetable();
    symbolesPoserMonstre(symbolesQuete(), 'Gobelin');

    // Un FAUX fichier, jamais une vraie image : seule son existence compte pour l'accesseur. La clé vient de la table
    // des correspondances (`config/symboles_monstres.php` : le Gobelin y porte le jeton « goblin » du livret), sinon du slug.
    $cle = config('symboles_monstres.cles.Gobelin') ?? \Illuminate\Support\Str::slug('Gobelin');
    symbolesPoser("monstres/{$cle}.webp");
    expect(symbolesSymboleMonstre('Gobelin'))->toBe("/images/livrets/monstres/{$cle}.webp");

    unlink(public_path("images/livrets/monstres/{$cle}.webp"));
    expect(symbolesSymboleMonstre('Gobelin'))->toBeNull();
});

it('rend à une VARIANTE le jeton de sa figure, par la clé de config/symboles_monstres.php (nom de catalogue → clé)', function () {
    symbolesDossierJetable();
    config()->set('symboles_monstres.cles', ['Gobelin' => 'guerrier-du-chaos']);
    symbolesPoser('monstres/guerrier-du-chaos.webp');
    symbolesPoserMonstre(symbolesQuete(), 'Gobelin');

    expect(symbolesSymboleMonstre('Gobelin'))->toBe('/images/livrets/monstres/guerrier-du-chaos.webp')
        // Une figure absente de la table garde la clé de son nom de catalogue : ici aucun fichier, donc `null`.
        ->and(app(BibliothequeImages::class)->urlSymboleMonstre('Squelette'))->toBeNull();

    unlink(public_path('images/livrets/monstres/guerrier-du-chaos.webp'));
});

it('ne publie RIEN d\'une figure non révélée, même quand son jeton existe', function () {
    symbolesDossierJetable();
    $cachee = symbolesPoserMonstre(symbolesQuete(), 'Gobelin');
    $cachee->update(['revele' => false]);
    symbolesPoser('monstres/gobelin.webp');

    $entites = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites');

    expect(collect($entites)->where('type', 'monstre')->pluck('id')->all())->not->toContain($cachee->id);

    unlink(public_path('images/livrets/monstres/gobelin.webp'));
});
