<?php

declare(strict_types=1);

use App\Engine\MotsClesMobilier;
use App\Models\Carte;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\MenuMoteur;
use App\Partie\MoteurPieges;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Validation\ValidationException;

/*
 * JUNGLES OF DELTHRAK — la CARTE (livret F9907 p. 4-5, lots B, D, E du plan
 * `docs/plan-delthrak-execution-2026-10-09.md`) : terrain gênant, Mare,
 * Brasier, Cocon, Piège de lianes. Scènes construites À LA MAIN (une salle, une
 * rangée ou un carré de sol) — on éprouve une règle, pas un placement ; le
 * placement a ses propres tests tout en bas.
 */

beforeEach(function () {
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

/**
 * @param  list<array{x: int, y: int, terrain_id: int}>  $terrain
 * @param  list<array<string, mixed>>  $mobilier
 * @param  list<array<string, mixed>>  $pieges
 * @return array{groupe: App\Models\Groupe, quete: Quete, heros: App\Models\Personnage, etatHeros: EtatPersonnageQuete}
 */
function sceneJungle(int $largeur, int $hauteur, array $herosPos, array $terrain = [], array $mobilier = [], array $pieges = [], array $herosAttrs = []): array
{
    $groupe = creerGroupe('table-jungle-'.uniqid());

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Quête de test — jungle',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => $largeur, 'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => $hauteur,
            'cases' => array_fill(0, $hauteur, array_fill(0, $largeur, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur,
                'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => $pieges, 'mobilier' => $mobilier, 'terrain' => $terrain,
            'spawn_heros' => [$herosPos], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);

    $joueur = connecterJoueur('jungle-'.uniqid());
    $heros = creerHeros($joueur, $groupe, 'Testeur', 1, $herosAttrs);

    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => $herosPos['x'], 'position_y' => $herosPos['y'], 'tombe' => false, 'a_joue' => false,
    ]);

    // Un second héros qui n'a pas joué garde le ROUND ouvert (cf. TerrainEnJeuTest).
    $second = creerHeros(connecterJoueur('jungle2-'.uniqid()), $groupe, 'Second', 2);
    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $second->id,
        'position_x' => 0, 'position_y' => $hauteur - 1, 'tombe' => false, 'a_joue' => false,
    ]);

    return ['groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'heros' => $heros, 'etatHeros' => $etatHeros];
}

function idTerrain(string $nom): int
{
    return (int) Terrain::where('nom', $nom)->firstOrFail()->id;
}

function deplacerJungle(array $scene, int $x, int $y): array
{
    return app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros']->fresh(),
        ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'], ['x' => $x, 'y' => $y],
    );
}

function optionsJungle(array $scene): array
{
    return app(MenuMoteur::class)->generer($scene['groupe']->fresh(), $scene['heros']->fresh())['options'] ?? [];
}

// =====================================================================
// 1. LES DONNÉES — sourcées p. 4, et seulement elles
// =====================================================================

it('seed les 5 terrains de la jungle : trois terrains gênants à 2 cases, la Mare et le Brasier', function () {
    $jungle = Terrain::where('boite', 'jungles_delthrak')->orderBy('id')->get()->keyBy('nom');

    expect($jungle->keys()->all())->toBe(['Sable entravant', 'Toile entravante', 'Jungle entravante', 'Mare', 'Brasier']);

    foreach (['Sable entravant', 'Toile entravante', 'Jungle entravante'] as $nom) {
        expect($jungle[$nom]->cout_deplacement)->toBe(2, $nom)
            ->and($jungle[$nom]->effet['entravant'] ?? null)->toBeTrue($nom);
    }

    foreach (['Mare', 'Brasier'] as $nom) {
        expect($jungle[$nom]->cout_deplacement)->toBe(1, $nom)
            ->and($jungle[$nom]->bloque_mouvement)->toBeFalse($nom)
            ->and($jungle[$nom]->bloque_vue)->toBeFalse($nom) // « does not block line of sight »
            ->and($jungle[$nom]->effet['interdit_arret'] ?? null)->toBeTrue($nom);
    }

    expect($jungle['Mare']->effet['soin_a_la_fouille'])->toBe(1)
        ->and($jungle['Brasier']->effet['sur'])->toBe(['crane' => ['degats_pv_body' => 1]])
        ->and($jungle['Brasier']->effet['type_degat'])->toBe('feu');
});

it('seed le Cocon (obstacle de toile, une action sans jet) et le Piège de lianes, tous deux de la boîte jungle', function () {
    $cocon = Mobilier::where('nom', 'Cocon')->firstOrFail();

    expect($cocon->boite)->toBe('jungles_delthrak')
        ->and($cocon->bloque_mouvement)->toBeTrue()   // « cannot be moved through »
        ->and($cocon->bloque_vue)->toBeTrue()          // « block line of sight »
        ->and($cocon->pv_body)->toBeNull()             // pas un meuble attaquable
        ->and($cocon->difficulte_destruction)->toBeNull() // pas de jet de Body
        ->and(MotsClesMobilier::detruitParAction((array) $cocon->effet))->toBeTrue();

    $lianes = Piege::where('nom', 'Piège de lianes')->firstOrFail();

    expect($lianes->boite)->toBe('jungles_delthrak')
        ->and($lianes->detectable)->toBeTrue()
        ->and($lianes->desarmable)->toBe('oui')
        ->and($lianes->usage)->toBe('unique') // jamais `persistant` : ce n'est pas une fosse
        ->and($lianes->effet['des_combat'])->toBe(1)
        ->and($lianes->effet['retient'])->toBe('Immobilisé');

    expect(app(MoteurPieges::class)->estFosse($lianes))->toBeFalse();
});

it('tient le vocabulaire de mobilier DANS LES DEUX SENS', function () {
    $portees = [];

    foreach (Mobilier::all() as $meuble) {
        foreach (array_keys((array) $meuble->effet) as $cle) {
            expect(MotsClesMobilier::connue($cle))->toBeTrue("{$meuble->nom} : clé « {$cle} » sans lecteur déclaré.");
            $portees[$cle] = true;
        }
    }

    foreach (MotsClesMobilier::VOCABULAIRE as $cle => $entree) {
        expect($portees)->toHaveKey($cle);

        [$classe, $methode] = explode('::', str_replace('()', '', $entree['lecteur']));
        $reflexion = new ReflectionClass($classe);

        expect($reflexion->hasMethod($methode))->toBeTrue("« {$cle} » : {$classe}::{$methode}() n'existe pas.")
            ->and(str_contains((string) file_get_contents((string) $reflexion->getFileName()), $cle))
            ->toBeTrue("« {$cle} » : le fichier du lecteur ne nomme pas la clé.");
    }
});

// =====================================================================
// 2. LE TERRAIN GÊNANT — 2 cases, ignoré par Agile, par le talent
// =====================================================================

it('fait payer 2 cases au terrain gênant, sur le parcours pondéré, jamais sur la distance', function () {
    $scene = sceneJungle(6, 1, ['x' => 0, 'y' => 0], [
        ['x' => 2, 'y' => 0, 'terrain_id' => idTerrain('Sable entravant')],
        ['x' => 3, 'y' => 0, 'terrain_id' => idTerrain('Toile entravante')],
    ]);

    $grille = FabriqueGrille::pour($scene['quete'], exceptPersonnageId: $scene['heros']->id);
    $chemin = $grille->chemin(0, 0, 5, 0);

    expect($grille->coutChemin($chemin))->toBe(7)   // 1 + 2 + 2 + 1 + 1
        ->and($grille->distance(0, 0, 5, 0))->toBe(5) // la portée reste géométrique
        ->and($grille->estEntravant(2, 0))->toBeTrue()
        ->and($grille->estEntravant(1, 0))->toBeFalse();
});

it('AGILE ignore le terrain gênant, mais pas la Rivière gelée — la levée est unique et ciblée', function () {
    $scene = sceneJungle(6, 1, ['x' => 0, 'y' => 0], [
        ['x' => 2, 'y' => 0, 'terrain_id' => idTerrain('Jungle entravante')],
        ['x' => 3, 'y' => 0, 'terrain_id' => idTerrain('Rivière gelée')],
    ]);

    $grille = FabriqueGrille::pour($scene['quete'], exceptPersonnageId: $scene['heros']->id);
    expect($grille->coutDeplacement(2, 0))->toBe(2);

    $grille->autoriserFranchissement(); // le mode d'un monstre Agile (`MoteurDread`, `ResolveurTour`)

    expect($grille->coutDeplacement(2, 0))->toBe(1, 'le terrain gênant ne coûte plus rien')
        ->and($grille->coutDeplacement(3, 0))->toBe(2, 'la rivière gelée, elle, coûte toujours');
});

it('le talent ignore_terrain_entravant traverse le terrain gênant sans payer — et le serveur publie la DÉCISION', function () {
    $terrain = [
        ['x' => 2, 'y' => 0, 'terrain_id' => idTerrain('Sable entravant')],
        ['x' => 3, 'y' => 0, 'terrain_id' => idTerrain('Toile entravante')],
    ];

    desFiges(array_fill(0, 40, 4));
    $sans = sceneJungle(6, 1, ['x' => 0, 'y' => 0], $terrain);
    deplacerJungle($sans, 4, 0);
    $restantSans = (int) $sans['etatHeros']->fresh()->deplacement_restant;

    desFiges(array_fill(0, 40, 4));
    $avec = sceneJungle(6, 1, ['x' => 0, 'y' => 0], $terrain, herosAttrs: ['classe' => 'druide']);
    donnerTalent($avec['heros'], 'Ronces complices');

    expect(app(EtatGroupe::class)->payload($avec['groupe']->fresh())['entites'] ?? null)->not->toBeNull();

    $resultat = deplacerJungle($avec, 4, 0);
    $restantAvec = (int) $avec['etatHeros']->fresh()->deplacement_restant;

    // 1 + 2 + 2 + 1 = 6 sans le talent, 4 avec : deux points de plus.
    expect($restantAvec - $restantSans)->toBe(2)
        ->and($resultat['distance'])->toBe(4);

    $entites = collect(app(EtatGroupe::class)->payload($avec['groupe']->fresh())['entites']);
    $heros = $entites->firstWhere('nom', 'Testeur');
    expect($heros['ignore_terrain_entravant'] ?? null)->toBeTrue();

    $publie = collect(app(EtatGroupe::class)->payload($avec['groupe']->fresh())['carte']['terrain']);
    expect($publie->every(fn (array $t) => $t['entravant'] === true && $t['interdit_arret'] === false))->toBeTrue();
});

// =====================================================================
// 3. MARE et BRASIER — on les traverse, on n'y finit pas
// =====================================================================

it('traverse une Mare ou un Brasier sans s\'y arrêter, et ne les offre jamais comme destination', function () {
    $scene = sceneJungle(6, 1, ['x' => 0, 'y' => 0], [
        ['x' => 2, 'y' => 0, 'terrain_id' => idTerrain('Mare')],
        ['x' => 4, 'y' => 0, 'terrain_id' => idTerrain('Brasier')],
    ]);

    $grille = FabriqueGrille::pour($scene['quete'], exceptPersonnageId: $scene['heros']->id);
    $atteignables = $grille->casesAtteignables(0, 0, 10);

    expect($atteignables)->not->toHaveKey('2,0')->not->toHaveKey('4,0')
        ->and($atteignables)->toHaveKey('5,0')
        ->and($grille->arretInterdit(2, 0))->toBeTrue()
        ->and($grille->estTraversable(2, 0))->toBeTrue();

    // La vue n'est jamais coupée par l'un ni l'autre.
    expect($grille->ligneDeVue(0, 0, 5, 0))->toBeTrue();
});

it('refuse de finir un mouvement sur une Mare, et l\'aperçu le dit avant la validation', function () {
    desFiges(array_fill(0, 40, 4));
    $scene = sceneJungle(6, 1, ['x' => 0, 'y' => 0], [['x' => 2, 'y' => 0, 'terrain_id' => idTerrain('Mare')]]);

    expect(fn () => deplacerJungle($scene, 2, 0))->toThrow(ValidationException::class);

    $apercu = app(ResolveurTour::class)->apercuDeplacement(
        $scene['quete']->fresh(), $scene['heros']->fresh(), $scene['etatHeros']->fresh(), 2, 0,
    );
    expect($apercu['atteignable'])->toBeFalse();

    // En revanche on la traverse.
    deplacerJungle($scene, 4, 0);
    expect((int) $scene['etatHeros']->fresh()->position_x)->toBe(4);
});

it('le Brasier brûle le héros sur un crâne (1 PV), pas sur un bouclier — et chaque jet est publié et annoncé', function () {
    $terrain = [['x' => 2, 'y' => 0, 'terrain_id' => idTerrain('Brasier')]];

    // Aucun monstre actif : « Unthreatened Movement » — le d6 de déplacement ne
    // se lance pas (il compte 4). Le premier dé tiré est donc celui du brasier.
    desFiges([1, 4, 4, 4, 4, 4]);
    $brule = sceneJungle(6, 1, ['x' => 0, 'y' => 0], $terrain);
    $pv = (int) $brule['heros']->pv_body;
    $payload = deplacerJungle($brule, 4, 0);

    expect((int) $brule['heros']->fresh()->pv_body)->toBe($pv - 1)
        ->and($payload['terrain']['nom'])->toBe('Brasier')
        ->and($payload['terrain']['degats'])->toBe(1)
        ->and($payload['terrain_jets'])->toHaveCount(1)
        ->and($payload['terrain_jets'][0]['face'])->toBe('crane');

    $lignes = app(JournalCombat::class)->depuisResultat($payload, 'Testeur');
    expect(collect($lignes)->pluck('texte')->implode(' '))->toContain('Brasier')
        ->and(collect($lignes)->pluck('ton'))->toContain('degats');

    desFiges(array_fill(0, 40, 4));
    $indemne = sceneJungle(6, 1, ['x' => 0, 'y' => 0], $terrain);
    $pv = (int) $indemne['heros']->pv_body;
    $payload = deplacerJungle($indemne, 4, 0);

    expect((int) $indemne['heros']->fresh()->pv_body)->toBe($pv)
        ->and($payload['terrain_jets'][0]['degats'])->toBe(0)
        ->and($payload)->not->toHaveKey('terrain');

    // Un dé lancé sans effet se dit aussi.
    expect(collect(app(JournalCombat::class)->depuisResultat($payload, 'Testeur'))->pluck('texte')->implode(' '))->toContain('aucun mal');
});

it('le Brasier brûle aussi le MONSTRE — « any creature » —, ce que la rivière gelée épargne', function () {
    $scene = sceneJungle(8, 1, ['x' => 0, 'y' => 0], [
        ['x' => 4, 'y' => 0, 'terrain_id' => idTerrain('Brasier')],
        ['x' => 5, 'y' => 0, 'terrain_id' => idTerrain('Rivière gelée')],
    ]);

    $catalogue = Monstre::where('nom_base', 'Squelette')->firstOrFail();
    $instance = InstanceMonstre::create([
        'quete_id' => $scene['quete']->id, 'monstre_id' => $catalogue->id,
        'pv_body' => 3, 'pv_body_max' => 3, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => 7, 'position_y' => 0, 'etat' => 'actif', 'revele' => true,
    ]);

    desFiges(array_fill(0, 20, 1)); // crânes : la rivière blesserait un héros, pas lui

    $methode = new ReflectionMethod(ResolveurTour::class, 'blesserMonstreSurLeChemin');
    $payload = $methode->invoke(
        app(ResolveurTour::class), $scene['groupe']->fresh(), $scene['quete']->fresh()->load('carte'), $instance,
        [['x' => 6, 'y' => 0], ['x' => 5, 'y' => 0], ['x' => 4, 'y' => 0], ['x' => 3, 'y' => 0]],
        ['type' => 'monstre', 'id' => $instance->id, 'nom' => 'Squelette'],
    );

    expect($payload['jets'])->toHaveCount(1, 'un seul jet : le brasier, jamais la rivière')
        ->and($payload['jets'][0]['terrain'])->toBe('Brasier')
        ->and((int) $instance->fresh()->pv_body)->toBe(2);

    $lignes = app(JournalCombat::class)->depuisResultat($payload, 'Squelette');
    expect(collect($lignes)->pluck('texte')->implode(' '))->toContain('Brasier');
});

it('un monstre ne finit pas non plus son déplacement sur une Mare', function () {
    $scene = sceneJungle(8, 1, ['x' => 0, 'y' => 0], [['x' => 4, 'y' => 0, 'terrain_id' => idTerrain('Mare')]]);

    $grille = FabriqueGrille::pour($scene['quete'], exceptInstanceId: 999);
    $atteignables = $grille->casesAtteignables(7, 0, 6);

    expect($atteignables)->not->toHaveKey('4,0')->toHaveKey('3,0');
});

// =====================================================================
// 4. LA MARE À LA FOUILLE — 1 PV au lieu d'une carte
// =====================================================================

it('offre « Boire à la mare » à la fouille d\'un héros blessé, jamais à un héros au maximum', function () {
    $scene = sceneJungle(6, 6, ['x' => 0, 'y' => 0], [['x' => 3, 'y' => 3, 'terrain_id' => idTerrain('Mare')]]);

    $ids = fn () => collect(optionsJungle($scene))->pluck('id');

    expect($ids())->toContain('fouiller_tresor')->not->toContain('boire_mare');

    $scene['heros']->update(['pv_body' => 5]);
    expect($ids())->toContain('fouiller_tresor')->toContain('boire_mare');

    // Pas de mare dans la salle : pas d'option.
    $sansMare = sceneJungle(6, 6, ['x' => 0, 'y' => 0]);
    $sansMare['heros']->update(['pv_body' => 5]);
    expect(collect(optionsJungle($sansMare))->pluck('id'))->not->toContain('boire_mare');
});

it('boire à la mare rend 1 PV, dépense la fouille de la salle, ne tire AUCUNE carte — et ne se rejoue pas', function () {
    $scene = sceneJungle(6, 6, ['x' => 0, 'y' => 0], [['x' => 3, 'y' => 3, 'terrain_id' => idTerrain('Mare')]]);
    $scene['heros']->update(['pv_body' => 5]);

    $option = ['id' => 'boire_mare', 'libelle' => 'Boire à la mare', 'type' => 'boire_mare'];
    $deckAvant = $scene['quete']->fresh()->deckFouille();

    $payload = app(ResolveurTour::class)->resoudre($scene['groupe']->fresh(), $scene['heros']->fresh(), $option, []);

    expect($payload['type'])->toBe('boire_mare')
        ->and($payload['soin'])->toBe(1)
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe(6)
        ->and($scene['quete']->fresh()->aFouille(0, (int) $scene['heros']->id))->toBeTrue()
        ->and($scene['quete']->fresh()->deckFouille())->toBe($deckAvant);

    expect(collect(app(JournalCombat::class)->depuisResultat($payload, 'Testeur'))->pluck('texte')->implode(' '))
        ->toContain('mare');

    // La fouille est dépensée : le résolveur refuse un second geste, même sur un héros blessé.
    $scene['etatHeros']->fresh()->update(['a_joue' => false, 'a_agi' => false]);
    expect(fn () => app(ResolveurTour::class)->resoudre($scene['groupe']->fresh(), $scene['heros']->fresh(), $option, []))
        ->toThrow(ValidationException::class);
});

it('le résolveur refuse la mare à un héros qui n\'a rien perdu', function () {
    $scene = sceneJungle(6, 6, ['x' => 0, 'y' => 0], [['x' => 3, 'y' => 3, 'terrain_id' => idTerrain('Mare')]]);

    expect(fn () => app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros']->fresh(),
        ['id' => 'boire_mare', 'libelle' => 'Boire', 'type' => 'boire_mare'], [],
    ))->toThrow(ValidationException::class);
});

// =====================================================================
// 5. LE COCON — une action, aucun jet
// =====================================================================

it('détruit un Cocon adjacent d\'UNE action, sans jet de dé, et il cesse de bloquer mouvement ET vue', function () {
    $cocon = Mobilier::where('nom', 'Cocon')->firstOrFail();
    $scene = sceneJungle(6, 1, ['x' => 1, 'y' => 0], mobilier: [[
        'mobilier_id' => $cocon->id, 'x' => 2, 'y' => 0, 'l' => 1, 'h' => 1, 'salle' => 0,
    ]]);

    $grille = FabriqueGrille::pour($scene['quete'], exceptPersonnageId: $scene['heros']->id);
    expect($grille->estTraversable(2, 0))->toBeFalse()
        ->and($grille->ligneDeVue(1, 0, 4, 0))->toBeFalse();

    $options = collect(optionsJungle($scene));
    $option = $options->firstWhere('id', 'detruire_par_action_0');

    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('detruire_par_action')
        ->and($option)->not->toHaveKey('jet')
        ->and($options->pluck('id'))->not->toContain('attaquer_mobilier_0')
        ->and($options->pluck('id'))->not->toContain('detruire_mobilier_0');

    // AUCUN dé : une file vide ferait exploser le moindre tirage.
    desFiges([]);
    $payload = app(ResolveurTour::class)->resoudre($scene['groupe']->fresh(), $scene['heros']->fresh(), $option, $option['parametres']);

    expect($payload['type'])->toBe('detruire_par_action')
        ->and($payload['detruit'])->toBeTrue();

    $apres = FabriqueGrille::pour($scene['quete']->fresh()->load('carte'), exceptPersonnageId: $scene['heros']->id);
    expect($apres->estTraversable(2, 0))->toBeTrue()
        ->and($apres->ligneDeVue(1, 0, 4, 0))->toBeTrue();

    expect(collect(app(EtatGroupe::class)->payload($scene['groupe']->fresh())['carte']['mobilier'])->pluck('nom'))->not->toContain('Cocon');
    expect(collect(app(JournalCombat::class)->depuisResultat($payload, 'Testeur'))->pluck('texte')->implode(' '))->toContain('Cocon');
});

it('n\'offre pas de détruire un Cocon hors de portée, et le résolveur le refuse', function () {
    $cocon = Mobilier::where('nom', 'Cocon')->firstOrFail();
    $scene = sceneJungle(6, 1, ['x' => 0, 'y' => 0], mobilier: [[
        'mobilier_id' => $cocon->id, 'x' => 3, 'y' => 0, 'l' => 1, 'h' => 1, 'salle' => 0,
    ]]);

    expect(collect(optionsJungle($scene))->pluck('id'))->not->toContain('detruire_par_action_0');

    expect(fn () => app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros']->fresh(),
        ['id' => 'detruire_par_action_0', 'libelle' => 'x', 'type' => 'detruire_par_action', 'parametres' => ['mobilier' => 0]], ['mobilier' => 0],
    ))->toThrow(ValidationException::class);
});

// =====================================================================
// 6. LE PIÈGE DE LIANES — esquive sur bouclier, tenu sur crâne, libéré d'une action
// =====================================================================

function lianesPosees(array $herosAttrs = []): array
{
    $lianes = Piege::where('nom', 'Piège de lianes')->firstOrFail();

    return sceneJungle(6, 1, ['x' => 0, 'y' => 0], pieges: [
        ['x' => 2, 'y' => 0, 'piege_id' => $lianes->id, 'etat' => 'cache'],
    ], herosAttrs: $herosAttrs);
}

it('esquive les lianes sur un bouclier : aucun dégât, le héros CONTINUE, le piège est désormais connu', function () {
    // Sans monstre actif le d6 de déplacement ne se lance pas : le premier dé
    // tiré est celui des lianes — ici un bouclier blanc.
    desFiges([4, 4, 4, 4, 4, 4]);
    $scene = lianesPosees();
    $pv = (int) $scene['heros']->pv_body;

    $payload = deplacerJungle($scene, 4, 0);

    $etat = $scene['etatHeros']->fresh();
    expect((int) $etat->position_x)->toBe(4, 'la course continue au-delà du piège')
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pv)
        ->and($payload['pieges_esquives'])->toHaveCount(1)
        ->and($payload['pieges_declenches'])->toBe([])
        ->and($etat->a_joue)->toBeFalse('une esquive ne ferme pas le tour')
        ->and($scene['quete']->fresh()->carte->grille['pieges'][0]['etat'])->toBe('detecte');

    $lignes = collect(app(JournalCombat::class)->depuisResultat($payload['pieges_esquives'][0], 'Testeur'));
    expect($lignes->pluck('texte')->implode(' '))->toContain('esquive');
});

it('un crâne : 1 PV, le héros est TENU (Immobilisé), son tour se ferme, les lianes restent affichées', function () {
    desFiges([1, 4, 4, 4, 4, 4]); // premier dé tiré = celui des lianes : un crâne
    $scene = lianesPosees();
    $pv = (int) $scene['heros']->pv_body;

    $payload = deplacerJungle($scene, 4, 0);

    $etat = $scene['etatHeros']->fresh();
    $entree = $scene['quete']->fresh()->carte->grille['pieges'][0];

    expect((int) $etat->position_x)->toBe(2, 'arrêté SUR la case des lianes')
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pv - 1)
        ->and($payload['pieges_declenches'][0]['retenu'])->toBeTrue()
        ->and($entree['etat'])->toBe('retient')
        ->and($entree['retenu'])->toBe((int) $scene['heros']->id)
        ->and($scene['heros']->fresh()->conditions->pluck('nom')->all())->toContain('Immobilisé')
        ->and($etat->a_joue)->toBeTrue('« Their turn immediately ends »');

    // La carte le montre, sous son propre état.
    $publie = collect(app(EtatGroupe::class)->payload($scene['groupe']->fresh())['carte']['pieges']);
    expect($publie->firstWhere('etat', 'retient'))->not->toBeNull();

    $lignes = collect(app(JournalCombat::class)->depuisResultat($payload['pieges_declenches'][0], 'Testeur'))->pluck('texte')->implode(' ');
    expect($lignes)->toContain('retenu par les lianes');
});

it('un héros tenu ne peut plus se déplacer, mais peut détruire les lianes (une action) — et le piège disparaît', function () {
    desFiges([1, 4, 4, 4, 4, 4]); // premier dé tiré = celui des lianes : un crâne
    $scene = lianesPosees();
    deplacerJungle($scene, 4, 0);

    // Nouveau tour : rouvre les créneaux sans toucher aux conditions.
    $scene['etatHeros']->fresh()->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false, 'deplacement_restant' => null]);

    $options = collect(optionsJungle($scene));
    expect($options->pluck('id'))->not->toContain('se_deplacer')
        ->and($options->pluck('id'))->toContain('liberer_entraves');

    $option = $options->firstWhere('id', 'liberer_entraves');
    $payload = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros']->fresh(), $option, ['cible_id' => $scene['heros']->id],
    );

    expect($scene['heros']->fresh()->conditions->pluck('nom')->all())->not->toContain('Immobilisé')
        ->and($payload['lianes_detruites'])->toHaveCount(1)
        ->and($scene['quete']->fresh()->carte->grille['pieges'][0]['etat'])->toBe('desarme');

    expect(collect(app(JournalCombat::class)->depuisResultat($payload, 'Testeur'))->pluck('texte')->implode(' '))->toContain('lianes');
});

it('un voisin au contact libère aussi le héros tenu', function () {
    desFiges([1, 4, 4, 4, 4, 4]); // premier dé tiré = celui des lianes : un crâne
    $scene = lianesPosees();
    deplacerJungle($scene, 4, 0);

    // Le second héros (en (0,0)) vient se placer au contact du héros tenu en (2,0).
    $second = $scene['quete']->etatsPersonnages()->where('personnage_id', '!=', $scene['heros']->id)->firstOrFail();
    $second->update(['position_x' => 3, 'position_y' => 0, 'a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);

    $menu = app(MenuMoteur::class)->generer($scene['groupe']->fresh(), $second->personnage->fresh());
    $option = collect($menu['options'])->firstWhere('id', 'liberer_entraves');

    expect($option)->not->toBeNull()
        ->and(collect($option['parametres']['cibles'])->pluck('id')->all())->toContain((int) $scene['heros']->id);
});

it('Immobilisé est bien la condition du catalogue, sans compteur de tours', function () {
    $condition = Condition::where('nom', 'Immobilisé')->firstOrFail();

    expect((int) $condition->duree_defaut)->toBe(0)
        ->and($condition->effet['deplacement_interdit'])->toBeTrue();
});

// =====================================================================
// 7. LE PLACEMENT — sous le thème, jamais sans, plancher de cases libres
// =====================================================================

function assemblerJungle(int $graine, ?string $theme = 'jungles_delthrak'): array
{
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
    $gabarit->structure = [...$gabarit->structure, 'terrains' => ['min' => 8, 'max' => 8]];

    return app(AssembleurCarte::class)->assembler(
        $gabarit, $graine, bestiaire: $theme === null ? null : BestiaireGroupe::auto($theme),
    );
}

it('ne pose le terrain, le cocon et les lianes de la jungle que sous le thème jungles_delthrak', function () {
    $idsJungle = Terrain::where('boite', 'jungles_delthrak')->pluck('id')->all();
    $idCocon = Mobilier::where('nom', 'Cocon')->value('id');
    $idLianes = Piege::where('nom', 'Piège de lianes')->value('id');

    foreach ([null, 'horreur_des_glaces', 'horde_ogre'] as $theme) {
        foreach ([3, 11, 42] as $graine) {
            $carte = assemblerJungle($graine, $theme);

            foreach ($carte['terrain'] as $t) {
                expect(in_array($t['terrain_id'], $idsJungle, true))->toBeFalse("thème {$theme} : terrain de jungle");
            }
            expect(collect($carte['mobilier'])->contains(fn ($m) => $m['mobilier_id'] === $idCocon))->toBeFalse("thème {$theme} : cocon")
                ->and(collect($carte['pieges'])->contains(fn ($p) => $p['piege_id'] === $idLianes))->toBeFalse("thème {$theme} : lianes");
        }
    }

    // Preuve positive, sur quelques graines : chacun finit par sortir.
    $vus = ['terrain' => false, 'mare_ou_brasier' => false, 'cocon' => false, 'lianes' => false];
    $idsSansArret = Terrain::whereIn('nom', ['Mare', 'Brasier'])->pluck('id')->all();

    foreach (range(1, 40) as $graine) {
        $carte = assemblerJungle($graine);
        $vus['terrain'] = $vus['terrain'] || collect($carte['terrain'])->contains(fn ($t) => in_array($t['terrain_id'], $idsJungle, true));
        $vus['mare_ou_brasier'] = $vus['mare_ou_brasier'] || collect($carte['terrain'])->contains(fn ($t) => in_array($t['terrain_id'], $idsSansArret, true));
        $vus['cocon'] = $vus['cocon'] || collect($carte['mobilier'])->contains(fn ($m) => $m['mobilier_id'] === $idCocon);
        $vus['lianes'] = $vus['lianes'] || collect($carte['pieges'])->contains(fn ($p) => $p['piege_id'] === $idLianes);
    }

    expect($vus)->toBe(['terrain' => true, 'mare_ou_brasier' => true, 'cocon' => true, 'lianes' => true]);
});

it('garde un PLANCHER de cases libres : Mares et Brasiers (sans arrêt) + meubles ne dépassent jamais la réserve de jouabilité', function () {
    $minimum = (new ReflectionClassConstant(AssembleurCarte::class, 'CASES_JOUABLES_MINIMUM'))->getValue();
    $idsSansArret = Terrain::whereIn('nom', ['Mare', 'Brasier'])->pluck('id')->all();
    $poses = 0;

    foreach (range(1, 60) as $graine) {
        $carte = assemblerJungle($graine);
        $grille = new Grille($carte['cases']);

        foreach ($carte['salles'] as $i => $salle) {
            $interieur = 0;
            for ($r = 0; $r < $salle['hauteur']; $r++) {
                for ($c = 0; $c < $salle['largeur']; $c++) {
                    $interieur += ($carte['cases'][$salle['y'] + $r][$salle['x'] + $c] ?? 'm') === 's' ? 1 : 0;
                }
            }

            $meubles = 0;
            foreach ($carte['mobilier'] as $m) {
                if (($m['salle'] ?? null) === $i) {
                    $meubles += count($grille->cellulesEmprise($m['x'], $m['y'], $m['l'], $m['h']));
                }
            }

            $sansArret = collect($carte['terrain'])->filter(fn ($t) => in_array($t['terrain_id'], $idsSansArret, true)
                && \App\Partie\Salles::indexDe($carte['salles'], $t['x'], $t['y']) === $i)->count();

            if ($sansArret === 0) {
                continue;
            }

            $poses += $sansArret;
            expect($interieur - $meubles - $sansArret)->toBeGreaterThanOrEqual($minimum, "graine {$graine}, salle {$i}");
        }
    }

    expect($poses)->toBeGreaterThan(0, 'le test ne prouve rien si aucune Mare/Brasier n\'a jamais été posé');
});

it('ne pose jamais de terrain de la jungle dans la salle de départ', function () {
    $ids = Terrain::where('boite', 'jungles_delthrak')->pluck('id')->all();

    foreach (range(1, 25) as $graine) {
        $carte = assemblerJungle($graine);
        $salle0 = $carte['salles'][0];

        foreach ($carte['terrain'] as $t) {
            if (in_array($t['terrain_id'], $ids, true)) {
                expect(\App\Partie\Salles::indexDe($carte['salles'], $t['x'], $t['y']))->not->toBe(0);
            }
        }
    }
});
