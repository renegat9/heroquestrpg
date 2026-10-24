<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Fouille\DeckFouille;
use App\Partie\MoteurPieges;
use App\Partie\MoteurTourelle;
use App\Partie\ResolveurTour;
use App\Partie\Salles;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * THE CRYPT OF PERPETUAL DARKNESS, EN JEU (chantier EXT-CRYPTE, 2026-10-23, thème allumé le même jour).
 * Une preuve par mécanique, par les vraies couches : le souffle à l'acide (`MoteurDread`), la mare (`MoteurPieges`),
 * le crâne (`MoteurTourelle`), la lance (`ResolveurTour::desArmeContre()`), la souris (menu + `ResolveurTour`).
 * Les montages reprennent ceux de `SortsDreadCartesTest` et de `PorteDoubleAdjacenteTest`, sans en dépendre : les
 * fonctions d'un fichier Pest sont globales, d'où les noms préfixés `crypte`.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

// ------------------------------------------------------------------
// Helpers (préfixe `crypte`)
// ------------------------------------------------------------------

/** Un héros seul en quête, avec un monstre nommé au contact : la même base que `demarrerQueteAvecMonstre()`. */
function crypteLanceur(string $sort, array $herosAttrs = []): array
{
    $ctx = demarrerQueteAvecMonstre('Seigneur', $herosAttrs);

    $ctx['instance']->monstre->update(['sorts_dread' => [$sort], 'archetype_lanceur' => null]);
    $ctx['instance']->update(['pv_body_max' => (int) $ctx['instance']->monstre->pv_body]);
    $ctx['instance']->refresh()->load('monstre');

    return $ctx;
}

/** Déclenche la phase des monstres avec des dés figés ; rend les actions jouées. */
function crypteTour(array $ctx, array $des): array
{
    desFiges([...$des, ...array_fill(0, 200, 4)]);

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    return collect($reponse->json('resultat.tour_monstres.actions'))->all();
}

/** Le sort nommé dans les actions du tour, ou null. */
function crypteAction(array $actions, string $sort): ?array
{
    return collect($actions)->firstWhere('sort', $sort);
}

/** Index de la salle d'une case de la quête. */
function crypteSalle(Quete $quete, int $x, int $y): ?int
{
    return Salles::indexDe((array) ($quete->carte?->grille['salles'] ?? []), $x, $y);
}

/**
 * Une immunité à UNE nature de dégât, portée au talisman, sans charge : un test-only, comme l'Anneau de Feu sans ses
 * deux charges. `absorbeDegat()` la lit telle quelle — c'est le seul lecteur de la nature acide.
 */
function crypteImmunite(Personnage $p, string $nature): void
{
    $objet = Objet::create([
        'nom' => "Huile d'essai ({$nature})", 'categorie' => 'armure', 'metallique' => false,
        'rarete' => 'unique', 'prix_base' => 0, 'emplacement' => 'talisman', 'tag_equipement' => null,
        'effet' => ['immunite_degat' => $nature],
    ]);

    Inventaire::create([
        'personnage_id' => $p->id, 'objet_id' => $objet->id, 'emplacement' => 'talisman', 'quantite' => 1,
    ]);
}

/** Un héros seul en quête : [alice, groupe, heros, quete, etat]. */
function crypteQuete(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();

    return compact('alice', 'groupe', 'heros', 'quete', 'etat');
}

/**
 * Une porte FERMÉE sur le mur EST d'une salle (3 × 3 au moins), le héros juste à l'intérieur, et une salle
 * SYNTHÉTIQUE ajoutée à l'est, derrière la porte. Convention de `Grille::casesPorte()` : une porte « e » occupe sa
 * case et désigne la case qui suit vers l'est ; les deux côtés sont donc la porte et la case lointaine. La carte de
 * test ne donne pas toujours une pièce derrière une porte : on la fabrique, puis on vérifie qu'elle est bien celle que
 * la porte désigne — sinon le test ne prouverait rien.
 *
 * @return array{porte: array<string, mixed>, option: string, synthese: int, lointaine: array{x: int, y: int}}
 */
function cryptePorteDerriereUneSalle(Quete $quete, EtatPersonnageQuete $etat): array
{
    $salles = (array) $quete->carte->grille['salles'];
    $salle = collect($salles)->first(fn ($s) => (int) $s['largeur'] >= 3 && (int) $s['hauteur'] >= 3);
    expect($salle)->not->toBeNull('aucune salle assez grande — scénario invalide.');

    $xMin = (int) $salle['x'];
    $yMin = (int) $salle['y'];
    $largeur = (int) $salle['largeur'];

    // Le mur est de la salle : sa dernière colonne. Le héros se tient juste à l'intérieur, à côté de la porte.
    $xPorte = $xMin + $largeur - 1;
    $yPorte = $yMin + 1;

    // La salle derrière : à l'est du mur, elle contient la case lointaine de la porte.
    $salles[] = ['x' => $xPorte + 1, 'y' => $yPorte - 1, 'largeur' => 3, 'hauteur' => 2];
    $synthese = array_key_last($salles);

    $porte = ['x' => $xPorte, 'y' => $yPorte, 'cote' => 'e', 'etat' => 'fermee'];
    $lointaine = ['x' => $xPorte + 1, 'y' => $yPorte];

    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['salles'] = $salles;
    $grille['portes'] = [$porte];
    $grille['leviers'] = [];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');

    $etat->update(['position_x' => $xPorte - 1, 'position_y' => $yPorte]);

    expect(Salles::indexDe($salles, $lointaine['x'], $lointaine['y']))->toBe($synthese, 'la case derrière la porte n\'est pas dans la salle fabriquée.')
        ->and(Salles::indexDe($salles, $xPorte - 1, $yPorte))->not->toBe($synthese);

    return [
        'porte' => $porte,
        'option' => "lacher_souris_{$porte['x']}_{$porte['y']}_{$porte['cote']}",
        'synthese' => $synthese,
        'lointaine' => $lointaine,
    ];
}

/** Donne au héros une souris familière au sac (une charge, comme le catalogue). */
function crypteSouris(Personnage $heros): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Souris familière')->firstOrFail()->id,
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);
}

/** Les identifiants d'option du menu courant du héros (menu recalculé, comme la manette le reçoit). */
function crypteIdsMenu(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'] ?? [])
        ->pluck('id')->all();
}

/**
 * Une mare d'acide posée sur une case libre à côté du héros, à l'état « caché ». Rend le contexte et la case.
 *
 * @return array{x: int, y: int, carte: \App\Models\Carte}
 */
function crypteMare(Quete $quete, EtatPersonnageQuete $etat): array
{
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $mare = Piege::where('nom', "Mare d'acide")->firstOrFail();

    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = [['x' => $contact['x'], 'y' => $contact['y'], 'piege_id' => $mare->id, 'etat' => 'cache']];
    $carte->update(['grille' => $grille]);
    $carte->refresh();

    return ['x' => (int) $contact['x'], 'y' => (int) $contact['y'], 'carte' => $carte];
}

/** Un pas du héros sur la case de la mare : le VRAI seam du déplacement (`controlerChemin()`). */
function crypteMarcheSur(array $ctx, array $mare): array
{
    $etat = $ctx['etatHeros']->fresh();

    return app(MoteurPieges::class)->controlerChemin(
        $ctx['groupe'],
        $mare['carte']->fresh(),
        $ctx['heros'],
        $etat,
        [['x' => $mare['x'], 'y' => $mare['y']]],
        ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y],
    );
}

// ==================================================================
// LE SOUFFLE ACIDE (Dread) — « Firestorm but with acid damage », G1798 p. 25
// ==================================================================

it('le Souffle acide brûle TOUTE la salle à l\'acide, épargne le lanceur et touche les monstres comme la Tempête de feu', function () {
    $ctx = crypteLanceur('Souffle acide');
    ['quete' => $quete, 'instance' => $boss, 'heros' => $heros, 'etatHeros' => $etat] = $ctx;

    expect(crypteSalle($quete, (int) $boss->position_x, (int) $boss->position_y))
        ->toBe(crypteSalle($quete, (int) $etat->position_x, (int) $etat->position_y));

    // Un comparse dans la même salle : il doit être touché avec les héros.
    $voisine = caseAdjacenteLibre($quete, (int) $boss->position_x, (int) $boss->position_y);
    $sbire = InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 0,
        'position_x' => $voisine['x'], 'position_y' => $voisine['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    $pvAvant = (int) $heros->pv_body;
    $pvBoss = (int) $boss->pv_body;

    $sort = crypteAction(crypteTour($ctx, [1, 1, 1, 1]), 'Souffle acide');

    expect($sort)->not->toBeNull()
        ->and($sort['resultats'][0]['degats_bruts'])->toBe(3)
        ->and((int) $heros->fresh()->pv_body)->toBe(max(0, $pvAvant - 3))
        ->and((int) $boss->fresh()->pv_body)->toBe($pvBoss)
        ->and((int) $sbire->fresh()->pv_body)->toBe(0);
});

it('l\'Anneau de Feu NE protège PAS du Souffle acide : une autre nature passe', function () {
    $ctx = crypteLanceur('Souffle acide');
    $heros = $ctx['heros'];

    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Anneau de Feu')->firstOrFail()->id,
        'emplacement' => 'talisman', 'quantite' => 1,
    ]);

    $pvAvant = (int) $heros->pv_body;
    $sort = crypteAction(crypteTour($ctx, [1, 1, 1, 1]), 'Souffle acide');

    expect($sort['resultats'][0]['absorbe'] ?? false)->toBeFalse()
        ->and((int) $heros->fresh()->pv_body)->toBe(max(0, $pvAvant - 3));
});

it('une immunité d\'ACIDE absorbe le Souffle acide, sans charge, et le dit au journal', function () {
    $ctx = crypteLanceur('Souffle acide');
    $heros = $ctx['heros'];
    crypteImmunite($heros, 'acide');

    $pvAvant = (int) $heros->pv_body;
    $sort = crypteAction(crypteTour($ctx, [1, 1, 1, 1]), 'Souffle acide');

    expect($sort['resultats'][0]['absorbe'])->toBeTrue()
        ->and($sort['resultats'][0]['degats'])->toBe(0)
        ->and((int) $heros->fresh()->pv_body)->toBe($pvAvant);
});

// ==================================================================
// LA MARE D'ACIDE (piège de sol) — G1798 p. 5
// ==================================================================

it('la Mare d\'acide : un crâne = 1 PV, et elle RESTE armée — chaque pas la déclenche de nouveau', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', []);
    $mare = crypteMare($ctx['quete'], $ctx['etatHeros']);
    $heros = $ctx['heros'];
    $pvAvant = (int) $heros->fresh()->pv_body;

    // Premier pas : un crâne (1-3 = crâne) → 1 PV d'acide, la mare passe à `mare_acide`.
    desFiges([1]);
    $premier = crypteMarcheSur($ctx, $mare);

    expect($premier['declenchements'])->toHaveCount(1)
        ->and($premier['declenchements'][0]['type'])->toBe('piege_declenche')
        ->and($premier['declenchements'][0]['degats'])->toBe(1)
        ->and((int) $heros->fresh()->pv_body)->toBe($pvAvant - 1)
        ->and($mare['carte']->fresh()->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_MARE);

    // Second pas, un bouclier : aucun dégât — mais la mare se déclenche encore (« affects any hero who moves onto it »).
    desFiges([4]);
    $second = crypteMarcheSur($ctx, $mare);

    expect($second['declenchements'])->toHaveCount(1)
        ->and($second['declenchements'][0]['degats'])->toBe(0)
        ->and($mare['carte']->fresh()->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_MARE);
});

it('une immunité d\'acide protège du pas sur la mare, et la mare reste armée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', []);
    $mare = crypteMare($ctx['quete'], $ctx['etatHeros']);
    crypteImmunite($ctx['heros'], 'acide');
    $pvAvant = (int) $ctx['heros']->fresh()->pv_body;

    desFiges([1]);
    $resultat = crypteMarcheSur($ctx, $mare);

    expect($resultat['declenchements'][0]['absorbe'])->toBeTrue()
        ->and($resultat['declenchements'][0]['degats'])->toBe(0)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($pvAvant)
        ->and($mare['carte']->fresh()->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_MARE);
});

it('la Mare n\'est PAS une fosse : ni immobilisée, ni touchée par les protections contre les fosses, sautable, non désarmable', function () {
    $mare = Piege::where('nom', "Mare d'acide")->firstOrFail();
    $fosse = Piege::where('nom', 'Fosse')->firstOrFail();
    $moteur = app(MoteurPieges::class);

    expect($moteur->estMare($mare))->toBeTrue()
        ->and($moteur->estFosse($mare))->toBeFalse()
        ->and($moteur->estFosse($fosse))->toBeTrue()
        ->and($moteur->estFranchissable($mare))->toBeTrue()
        ->and($mare->desarmable)->toBe('non')
        ->and($mare->effet['type_degat'])->toBe('acide')
        ->and($mare->boite)->toBe('crypte_tenebres');
});

// ==================================================================
// LE CRÂNE DU DREAD (tourelle, apparaît à la vue) — G1798 p. 5 et quête 9 note A
// ==================================================================

it('le Crâne du Dread est une tourelle immobile à 1 PV, sans défense, réservée à la Crypte', function () {
    $crane = Monstre::where('nom_base', 'Crâne du Dread')->firstOrFail();

    expect($crane->pv_body)->toBe(1)
        ->and($crane->defense)->toBe(0)
        ->and($crane->attaque)->toBe(2)
        ->and($crane->deplacement)->toBe(0)
        ->and($crane->tier)->toBe('sous_boss')
        ->and($crane->boite)->toBe('crypte_tenebres')
        ->and((int) $crane->cout)->toBe(8)
        ->and($crane->estTourelle())->toBeTrue()
        ->and($crane->estImmobile())->toBeTrue()
        ->and($crane->reserveASonTheme())->toBeTrue();
});

it('le Crâne paraît au premier regard d\'un héros, et pas avant', function () {
    $ctx = demarrerQueteAvecMonstre('Crâne du Dread', []);
    $instance = $ctx['instance'];
    $instance->update(['revele' => false]);

    $vues = app(MoteurTourelle::class)->apparitionsEnVue($ctx['quete'], [[
        'x' => (int) $ctx['etatHeros']->position_x,
        'y' => (int) $ctx['etatHeros']->position_y,
        'personnage_id' => (int) $ctx['heros']->id,
    ]]);

    expect($vues)->toHaveCount(1)
        ->and($vues[0]['instance']->id)->toBe($instance->id)
        ->and($vues[0]['personnage_id'])->toBe((int) $ctx['heros']->id);

    // Sans regard — la case du crâne hors de toute ligne de vue — il ne paraît pas.
    expect(app(MoteurTourelle::class)->apparitionsEnVue($ctx['quete'], []))->toBe([]);
});

// ==================================================================
// LA LANCE DU DRAGON, LA COURONNE DES TÉNÈBRES — G1798 p. 4
// ==================================================================

it('la Lance du Dragon frappe à 4 dés un dragon nommé (le Dragon, Venim), à 0 contre le reste', function () {
    $lance = Objet::where('nom', 'Lance du Dragon')->firstOrFail();
    $resolveur = app(ResolveurTour::class);
    $methode = new ReflectionMethod($resolveur, 'desArmeContre');
    $methode->setAccessible(true);

    $instance = function (string $nom): InstanceMonstre {
        $i = new InstanceMonstre(['elite' => false]);
        $i->setRelation('monstre', Monstre::where('nom_base', $nom)->firstOrFail());

        return $i;
    };

    expect($methode->invoke($resolveur, $lance, $instance('Venim le Dragon')))->toBe(4)
        ->and($methode->invoke($resolveur, $lance, $instance('Dragon')))->toBe(4)
        ->and($methode->invoke($resolveur, $lance, $instance('Gobelin')))->toBe(0)
        ->and((int) $lance->effet['des_attaque'])->toBe(3)
        ->and($lance->effet['attaque_diagonale'])->toBeTrue()
        ->and($lance->classe_interdite)->toBe('magicien');
});

it('la Lance du Dragon est interdite au magicien, et seulement à lui', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $lance = Objet::where('nom', 'Lance du Dragon')->firstOrFail();

    expect(app(Equipement::class)->estAccessible($ctx['heros'], $lance))->toBeFalse();

    $ctx['heros']->update(['classe' => 'nain']);

    expect(app(Equipement::class)->estAccessible($ctx['heros']->fresh(), $lance))->toBeTrue();
});

it('la Couronne des Ténèbres porte son dé de défense au casque ; sa vision dans le noir est écartée', function () {
    $couronne = Objet::where('nom', 'Couronne des Ténèbres')->firstOrFail();

    expect($couronne->emplacement)->toBe('casque')
        ->and($couronne->effet['des_defense'])->toBe(1)
        ->and(array_key_exists('vision_nocturne', (array) $couronne->effet))->toBeFalse()
        ->and($couronne->boite)->toBe('crypte_tenebres');
});

// ==================================================================
// LES ADVERSAIRES NOMMÉS — stats relues sur le livret, cout MESURÉS
// ==================================================================

it('porte les sept adversaires nommés exactement comme leur livret, avec les coûts mesurés', function () {
    // Move, Attack, Defend, Body, Mind (G1798, pages imprimées 11, 13, 15, 17, 21, 23, 25).
    $fiches = [
        'Sorcière des Marais' => [4, 2, 3, 2, 3],
        'Squelette de minotaure' => [6, 3, 3, 2, 0],
        'Buubhealxea, reine gobeline' => [8, 3, 3, 3, 2],
        'Roi Archaloneus' => [6, 4, 4, 4, 3],
        'Momie-naine' => [5, 4, 5, 4, 0],
        'Kedrick Gilbane' => [7, 5, 5, 4, 4],
        'Venim le Dragon' => [7, 5, 5, 4, 4],
    ];

    foreach ($fiches as $nom => $stats) {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();

        expect([$m->deplacement, $m->attaque, $m->defense, $m->pv_body, $m->pv_mind])->toBe($stats, $nom)
            ->and($m->boite)->toBe('crypte_tenebres', $nom);
    }

    // Venim : boss, la seule grande figure. Les six autres : sous-boss, au plancher du palier (leur mesure est sous la droite).
    expect(Monstre::where('nom_base', 'Venim le Dragon')->value('tier'))->toBe('boss')
        ->and(Monstre::where('boite', 'crypte_tenebres')->where('tier', 'boss')->pluck('nom_base')->all())->toBe(['Venim le Dragon']);

    foreach (['Sorcière des Marais', 'Squelette de minotaure', 'Buubhealxea, reine gobeline', 'Roi Archaloneus', 'Momie-naine', 'Kedrick Gilbane'] as $nom) {
        expect(Monstre::where('nom_base', $nom)->value('tier'))->toBe('sous_boss', $nom)
            ->and((int) Monstre::where('nom_base', $nom)->value('cout'))->toBe(8, $nom);
    }

    // Le coût de Venim suit la méthode mesurée : 4 / (1,5 − 5/6) = 6 attaques, sur la droite des boss (Seigneur 15 → 20).
    $venim = Monstre::where('nom_base', 'Venim le Dragon')->firstOrFail();
    $attaques = $venim->pv_body / (1.5 - $venim->defense / 6);

    expect((int) round(20 + 0.5 * ($attaques - 15)))->toBe((int) $venim->cout)
        ->and((int) $venim->cout)->toBe(16);
});

it('chaque lanceur nommé de la Crypte a son répertoire du livret, et tous ses sorts existent au catalogue', function () {
    $repertoires = [
        'sorciere_des_marais' => ['Éclair de Chaos', 'Commandement', 'Sommeil', 'Tourmente', 'Rouille', 'Fuite'],
        'buubhealxea_reine_gobeline' => ['Commandement', 'Frayeur', 'Fuite'],
        'archaloneus_roi_momie' => ['Sommeil', 'Rouille', 'Commandement'],
        'kedrick_gilbane' => ['Nuée d\'Effroi', 'Frayeur', 'Tempête de feu', 'Commandement'],
        'venim_dragon' => ['Nuée d\'Effroi', 'Frayeur', 'Commandement', 'Sommeil', 'Souffle acide'],
    ];

    foreach ($repertoires as $archetype => $sorts) {
        expect(config("archetypes_lanceurs.{$archetype}.sorts"))->toBe($sorts, $archetype);

        foreach ($sorts as $sort) {
            expect(\App\Models\SortDread::where('nom', $sort)->exists())->toBeTrue("« {$sort} » n'existe pas au catalogue.");
        }
    }
});

// ==================================================================
// LA SOURIS FAMILIÈRE — G1798 quête 2 note B ; quête 5 note C
// ==================================================================

it('la souris n\'est offerte que devant une porte fermée, et seulement si le héros la porte', function () {
    $ctx = crypteQuete();
    $porte = cryptePorteDerriereUneSalle($ctx['quete'], $ctx['etat']);

    expect(collect(crypteIdsMenu($ctx))->filter(fn ($id) => str_starts_with((string) $id, 'lacher_souris'))->all())->toBe([]);

    crypteSouris($ctx['heros']);

    expect(crypteIdsMenu($ctx))->toContain($porte['option']);
});

it('lâcher la souris découvre la salle DERRIÈRE la porte sans l\'ouvrir, sans réveiller ses monstres, et se brise', function () {
    $ctx = crypteQuete();
    $quete = $ctx['quete'];
    $porte = cryptePorteDerriereUneSalle($quete, $ctx['etat']);
    $ligne = crypteSouris($ctx['heros']);

    // Un monstre DERRIÈRE la porte, caché : il ne doit pas se révéler.
    $derriere = InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 0,
        'position_x' => $porte['lointaine']['x'], 'position_y' => $porte['lointaine']['y'],
        'etat' => 'actif', 'revele' => false,
    ]);

    crypteIdsMenu($ctx);
    $reponse = test()->postJson('/api/groupes/table-1/choix', ['option_id' => $porte['option']])
        ->assertStatus(202)
        ->json('resultat');

    expect($reponse['type'])->toBe('lacher_souris')
        ->and($reponse['souris_epuisee'])->toBeTrue()
        ->and($reponse['monstres'])->toContain($derriere->fresh()->nomAffiche());

    // La salle derrière est découverte — et elle seule (le brouillard n'est levé que là).
    expect($quete->fresh()->sallesDecouvertes())->toContain($porte['synthese']);

    // La porte reste CLOSE : c'est la différence avec « Ouvrir la porte ».
    expect($quete->fresh()->carte->grille['portes'][0]['etat'])->toBe('fermee');

    // Le monstre ne s'éveille pas : son drapeau de révélation ne bouge pas.
    expect((bool) $derriere->fresh()->revele)->toBeFalse();

    // La souris a été consommée (une charge) et s'est brisée à zéro : la ligne d'inventaire n'existe plus.
    expect(Inventaire::find($ligne->id))->toBeNull();
});

it('le groupe ne retombe pas sur une souris : le deck de fouille ne la reprend que s\'il n\'en a pas, sous la Crypte', function () {
    $ctx = crypteQuete();
    $gabarit = \App\Models\GabaritQuete::where('type_jalon', 'normale')->firstOrFail();
    $carte = ['salles' => (array) $ctx['quete']->carte->grille['salles']];

    $compte = function (string $theme) use ($ctx, $gabarit, $carte): int {
        $deck = app(DeckFouille::class)->construire($gabarit, $carte, $ctx['groupe'], 1, \App\Partie\BestiaireGroupe::auto($theme))['deck'];

        return collect($deck)->where('carte', 'familiar_mouse')->count();
    };

    expect($compte('crypte_tenebres'))->toBe(1)
        ->and($compte('dread_moon'))->toBe(0);

    crypteSouris($ctx['heros']);

    expect($compte('crypte_tenebres'))->toBe(0);
});
