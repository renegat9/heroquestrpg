<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Engine\MotsClesTerrain;
use App\Models\Carte;
use App\Models\ClasseHeros;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Inventaire;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\MenuMoteur;
use App\Partie\MoteurSables;
use App\Partie\ResolveurTour;
use App\Partie\Salles;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/*
 * SABLES MOUVANTS — *The Mage of the Mirror* (F7539 p. 29, quête 8, note C), porté comme
 * TERRAIN du catalogue (René, 2026-10-15). Ce fichier joue la règle PAR LES VRAIES ROUTES
 * (`GET /menu`, `POST /choix`) : le saut réussi, le saut raté avec le choix des deux objets,
 * le saut raté faute d'objets (le héros tombe, la mort définitive est refusée), la sortie au
 * tour suivant — plus le placement sur N graines, qui ne casse jamais la connectivité.
 *
 * Scène à la main : une carte 7×7 toute en sol, une bande de sable de 2 rangées (y = 3 et 4)
 * sur x = 0..4, laissant les colonnes 5 et 6 libres — le contournement. Albrecht attend en
 * (2, 2), juste au-dessus de la bande ; la case d'en face est (2, 5). Bertrand, du MÊME joueur,
 * garde le round ouvert en (6, 6).
 *
 * Dés : le d6 de déplacement est tiré à la première génération de menu (4 ici, soit 8 cases),
 * puis le dé du saut — 6 = bouclier noir (réussite), 1 = crâne (échec) —, puis des 4.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

function idSables(): int
{
    return (int) Terrain::where('nom', 'Sables mouvants')->firstOrFail()->id;
}

/**
 * @return array{alice: JoueurAuthentifiable, bob: JoueurAuthentifiable, groupe: App\Models\Groupe, quete: Quete, albrecht: Personnage, bertrand: Personnage}
 */
function sablesScene(array $herosAttrs = []): array
{
    $alice = connecterJoueur('alice');
    // Bertrand a SON joueur : un joueur n'a qu'un héros actif par groupe, et le round se ferme
    // par la vraie route (`MenuCourant` cherche le héros de CE joueur).
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);
    $bertrand = creerHeros($bob, $groupe, 'Bertrand', 2);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — sables mouvants',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $terrain = [];
    foreach ([3, 4] as $y) {
        foreach (range(0, 4) as $x) {
            $terrain[] = ['x' => $x, 'y' => $y, 'terrain_id' => idSables()];
        }
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 7, 'hauteur' => 7,
        'grille' => [
            'largeur' => 7, 'hauteur' => 7,
            'cases' => array_fill(0, 7, array_fill(0, 7, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => $terrain,
            'spawn_heros' => [['x' => 2, 'y' => 2]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $albrecht->id, 'position_x' => 2, 'position_y' => 2,
    ]);
    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $bertrand->id, 'position_x' => 6, 'position_y' => 6,
    ]);

    return ['alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'),
        'albrecht' => $albrecht, 'bertrand' => $bertrand];
}

function sablesEtat(array $ctx, string $qui = 'albrecht'): EtatPersonnageQuete
{
    return EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $ctx[$qui]->id)->firstOrFail();
}

/** Le menu publié par la VRAIE route de rattrapage. */
function sablesMenu(): array
{
    return test()->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu') ?? [];
}

function sablesOption(array $menu, string $id): ?array
{
    return collect($menu['options'] ?? [])->firstWhere('id', $id);
}

function sablesChoisir(string $option, array $parametres = [])
{
    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => $option, 'parametres' => $parametres]);
}

/** Le dé du saut (6 = bouclier noir, 1 = crâne), puis des 4 : aucune autre face n'est jouée par hasard. */
function sablesDe(int $valeur): void
{
    desFiges([$valeur, ...array_fill(0, 80, 4)]);
}

function sablesDonner(Personnage $heros, string $objet, string $emplacement = 'sac', int $quantite = 1): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $objet)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => $quantite,
    ]);
}

// =====================================================================
// 1. LES DONNÉES — sourcées p. 29, et seulement elles
// =====================================================================

it('seed les Sables mouvants : un terrain qui BLOQUE la marche, jamais la vue, sous le thème mage_du_miroir', function () {
    $sables = Terrain::where('nom', 'Sables mouvants')->firstOrFail();

    expect($sables->boite)->toBe('mage_du_miroir')
        ->and($sables->bloque_mouvement)->toBeTrue('on le franchit d\'un saut, on ne le traverse pas')
        ->and($sables->bloque_vue)->toBeFalse('un trou de sable ne coupe pas la vue')
        ->and($sables->effet)->toBe(['saut_par_dessus' => 2, 'saut_reussi_sur' => 'bouclier_noir', 'enlise' => 2]);

    // Les trois clés sont au vocabulaire fermé, avec un lecteur chacune.
    foreach (['saut_par_dessus', 'saut_reussi_sur', 'enlise'] as $cle) {
        expect(MotsClesTerrain::connue($cle))->toBeTrue($cle);
    }
});

it('dit la règle en phrases au guide et à la légende — le serveur traduit, le client affiche', function () {
    $sables = Terrain::where('nom', 'Sables mouvants')->firstOrFail();
    $phrases = implode(' | ', MotsClesTerrain::avantages((array) $sables->effet, (int) $sables->cout_deplacement));

    expect($phrases)->toContain('2 cases de large')
        ->and($phrases)->toContain('un bouclier noir')
        ->and($phrases)->toContain('abandonne 2 objets de son choix')
        ->and($phrases)->not->toContain('sans effet à ce jour');
});

it('les quatre gabarits déclarent COMBIEN de bandes de sable (le thème dit si)', function () {
    foreach (GabaritQuete::all() as $gabarit) {
        expect(data_get($gabarit->structure, 'terrains.sables.max'))->not->toBeNull("gabarit {$gabarit->id} sans terrains.sables");
    }
});

// =====================================================================
// 2. LE MENU — le saut n'est offert que s'il est jouable
// =====================================================================

it('offre le saut depuis la case voisine, avec la case d\'en face décidée par le serveur', function () {
    $ctx = sablesScene();
    desFiges(array_fill(0, 80, 4));

    $option = sablesOption(sablesMenu(), 'sauter_sables_2_3');

    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('saut_sables')
        ->and($option['creneau'])->toBe('mouvement')
        ->and($option['parametres']['vers'])->toBe(['x' => 2, 'y' => 5])
        ->and($option['parametres']['cout'])->toBe(3, '2 cases de sable + la réception')
        ->and($option['parametres']['enlise'])->toBe(2);
});

it('n\'offre pas le saut ailleurs qu\'en face de la profondeur de la bande', function () {
    $ctx = sablesScene();
    desFiges(array_fill(0, 80, 4));
    $etat = sablesEtat($ctx);
    $sables = app(MoteurSables::class);
    $heros = $ctx['albrecht'];

    // Pas voisin : en (5, 2), la bande est en diagonale.
    $etat->update(['position_x' => 5, 'position_y' => 2]);
    expect($sables->sauts($ctx['quete'], $heros, $etat->fresh()))->toBe([]);

    // Au BOUT de la bande, le long de sa longueur : 5 cases de sable, pas 2 — le livret ne décrit
    // pas de saut par-dessus plus de deux cases.
    $etat->update(['position_x' => 5, 'position_y' => 3]);
    expect($sables->sauts($ctx['quete'], $heros, $etat->fresh()))->toBe([]);

    // De l'autre côté, le saut inverse existe : (2, 5) → (2, 2).
    $etat->update(['position_x' => 2, 'position_y' => 5]);
    $sauts = $sables->sauts($ctx['quete'], $heros, $etat->fresh());
    expect($sauts)->toHaveCount(1)->and($sauts[0]['vers'])->toBe(['x' => 2, 'y' => 2]);
});

it('n\'offre pas le saut quand la case de réception est occupée — ni sans de quoi payer 3 cases', function () {
    $ctx = sablesScene();
    desFiges(array_fill(0, 80, 4));

    // Bertrand se tient sur la case d'en face : l'option disparaît du menu.
    sablesEtat($ctx, 'bertrand')->update(['position_x' => 2, 'position_y' => 5]);
    expect(sablesOption(sablesMenu(), 'sauter_sables_2_3'))->toBeNull();

    // La réception libre de nouveau, mais il ne reste que 2 points : le résolveur refuserait.
    sablesEtat($ctx, 'bertrand')->update(['position_x' => 6, 'position_y' => 6]);
    sablesEtat($ctx)->update(['deplacement_tour' => 2, 'deplacement_restant' => 2]);
    $menu = app(MenuMoteur::class)->generer($ctx['groupe']->fresh(), $ctx['albrecht']->fresh());
    expect(sablesOption($menu, 'sauter_sables_2_3'))->toBeNull();
});

it('refuse un saut forgé : le contrôleur (option absente du menu) ET le résolveur (ligne illégale)', function () {
    $ctx = sablesScene();
    desFiges(array_fill(0, 80, 4));
    sablesMenu();

    sablesChoisir('sauter_sables_0_3')->assertStatus(422);

    $forge = ['id' => 'sauter_sables_0_3', 'libelle' => 'x', 'type' => 'saut_sables',
        'parametres' => ['sable' => ['x' => 0, 'y' => 3]]];

    expect(fn () => app(ResolveurTour::class)->resoudre($ctx['groupe']->fresh(), $ctx['albrecht']->fresh(), $forge))
        ->toThrow(ValidationException::class);
});

it('la case de sable n\'est ni un passage ni un arrêt : le Dijkstra la contourne, la marche coûte le détour', function () {
    $ctx = sablesScene();
    $grille = FabriqueGrille::pour($ctx['quete'], exceptPersonnageId: $ctx['albrecht']->id);

    expect($grille->estTraversable(2, 3))->toBeFalse('pas de passage')
        ->and($grille->arretInterdit(2, 3))->toBeTrue('pas d\'arrêt')
        ->and($grille->estTraversable(5, 3))->toBeTrue('le contournement reste libre');

    // De (2, 2) à (2, 5) : 3 en x, 3 en y, 3 en x = 9 pas — contre 3 points pour sauter.
    expect(isset($grille->casesAtteignables(2, 2, 8)['2,5']))->toBeFalse('8 points ne suffisent pas pour contourner')
        ->and(isset($grille->casesAtteignables(2, 2, 9)['2,5']))->toBeTrue('9 points contournent')
        ->and(array_intersect(array_keys($grille->casesAtteignables(2, 2, 30)), ['2,3', '2,4', '0,3', '4,4']))->toBe([]);

    // …et aucune destination offerte au menu n'est un sable.
    desFiges(array_fill(0, 80, 4));
    $destinations = sablesOption(sablesMenu(), 'se_deplacer')['parametres']['destinations'];
    foreach ($destinations as $d) {
        expect($d['y'] >= 3 && $d['y'] <= 4 && $d['x'] <= 4)->toBeFalse("destination sur du sable : ({$d['x']},{$d['y']})");
    }
});

// =====================================================================
// 3. LE SAUT RÉUSSI — bouclier noir
// =====================================================================

it('un bouclier noir pose le héros sur la case d\'en face et lui laisse le reste de son mouvement', function () {
    $ctx = sablesScene();
    desFiges(array_fill(0, 80, 4));
    sablesMenu(); // tire le d6 de déplacement : 4 + 4 = 8 cases

    sablesDe(6);
    $reponse = sablesChoisir('sauter_sables_2_3')->assertStatus(202);

    $reponse->assertJsonPath('resultat.type', 'saut_sables')
        ->assertJsonPath('resultat.franchi', true)
        ->assertJsonPath('resultat.faces', ['bouclier_noir'])
        ->assertJsonPath('resultat.vers', ['x' => 2, 'y' => 5])
        // Le dé, dessiné par la manette : un dé unilatéral dont la face qui GAGNE est le bouclier noir.
        ->assertJsonPath('des.defensive', 'bouclier_noir')
        ->assertJsonPath('des.def', ['bouclier_noir']);

    $etat = sablesEtat($ctx);
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([2, 5])
        ->and($etat->enlise)->toBeNull()
        ->and((int) $etat->deplacement_restant)->toBe(5, '8 cases moins les 3 du saut')
        ->and($etat->a_joue)->toBeFalse();
});

// =====================================================================
// 4. LE SAUT RATÉ — s'enliser, choisir deux objets, finir le tour
// =====================================================================

it('un saut raté enlise le héros, force le menu, et lui laisse CHOISIR les deux objets à abandonner', function () {
    $ctx = sablesScene();
    $albrecht = $ctx['albrecht'];
    $epee = sablesDonner($albrecht, 'Épée courte');
    $bouclier = sablesDonner($albrecht, 'Bouclier');
    $potions = sablesDonner($albrecht, 'Potion de soin', 'consommable', 2);

    desFiges(array_fill(0, 80, 4));
    sablesMenu();

    sablesDe(1); // un crâne : « any other result means the hero lands in the quicksand »
    sablesChoisir('sauter_sables_2_3')->assertStatus(202)
        ->assertJsonPath('resultat.type', 'saut_sables')
        ->assertJsonPath('resultat.franchi', false)
        ->assertJsonPath('resultat.a_defausser', 2)
        ->assertJsonPath('resultat.tombe', false)
        ->assertJsonPath('resultat.vers', ['x' => 2, 'y' => 3]);

    // Il est DANS le sable, enlisé — état durable en base —, et son tour n'est pas fini.
    $etat = sablesEtat($ctx);
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([2, 3])
        ->and($etat->enlise['phase'])->toBe('defausse')
        ->and($etat->enlise['a_defausser'])->toBe(2)
        ->and($etat->enlise['vers'])->toBe(['x' => 2, 'y' => 5])
        ->and($etat->a_joue)->toBeFalse();

    // MENU FORCÉ : une seule option, la défausse, dont la liste blanche nomme TOUT ce qu'il porte.
    $menu = sablesMenu();
    expect($menu['options'])->toHaveCount(1)
        ->and($menu['options'][0]['id'])->toBe('defausser_sables')
        ->and($menu['options'][0]['type'])->toBe('defausse_sables')
        ->and($menu['options'][0]['creneau'])->toBe('interaction')
        ->and($menu['situation'])->toContain('abandonnez 2 objets');

    $entrees = collect($menu['options'][0]['parametres']['objets'])->keyBy('inventaire_id');
    expect($entrees->keys()->sort()->values()->all())->toBe(collect([$epee->id, $bouclier->id, $potions->id])->sort()->values()->all())
        ->and($entrees[$potions->id]['quantite'])->toBe(2);

    // Rien d'autre n'est jouable : ni l'attente, ni le déplacement.
    sablesChoisir('attendre')->assertStatus(422);
    sablesChoisir('se_deplacer', ['x' => 2, 'y' => 2])->assertStatus(422);

    // Une clé FORGÉE est refusée…
    sablesChoisir('defausser_sables', ['cle' => 'objet:999999'])->assertStatus(422);
    sablesChoisir('defausser_sables', [])->assertStatus(422);

    // …le héros CHOISIT : l'épée d'abord. Le tour continue, il lui en reste un à abandonner.
    sablesDe(4);
    sablesChoisir('defausser_sables', ['cle' => "objet:{$epee->id}"])->assertStatus(202)
        ->assertJsonPath('resultat.type', 'defausse_sables')
        ->assertJsonPath('resultat.objet', 'Épée courte')
        ->assertJsonPath('resultat.restantes', 1)
        ->assertJsonPath('resultat.fin_de_tour', false);

    expect(Inventaire::whereKey($epee->id)->exists())->toBeFalse()
        ->and(sablesEtat($ctx)->a_joue)->toBeFalse()
        ->and(sablesMenu()['options'])->toHaveCount(1);

    // Une potion de la pile de deux : UN exemplaire part, la ligne survit — c'est le dernier, le tour s'achève.
    sablesChoisir('defausser_sables', ['cle' => "objet:{$potions->id}"])->assertStatus(202)
        ->assertJsonPath('resultat.objet', 'Potion de soin')
        ->assertJsonPath('resultat.restantes', 0)
        ->assertJsonPath('resultat.fin_de_tour', true);

    expect((int) $potions->fresh()->quantite)->toBe(1)
        ->and(Inventaire::whereKey($bouclier->id)->exists())->toBeTrue('ce qu\'il n\'a pas choisi, il le garde');

    $etat = sablesEtat($ctx);
    expect($etat->a_joue)->toBeTrue('« This ends the hero\'s turn. »')
        ->and($etat->enlise['phase'])->toBe('sables')
        ->and($etat->enlise['a_defausser'])->toBe(0)
        ->and($etat->tombe)->toBeFalse();
});

it('abandonner une pièce PORTÉE est permis (le livret nomme armes et armures) et recalcule les dés du héros', function () {
    $ctx = sablesScene();
    $albrecht = $ctx['albrecht'];
    $epee = sablesDonner($albrecht, 'Épée courte', 'arme_principale');
    $bouclier = sablesDonner($albrecht, 'Bouclier', 'arme_secondaire');
    app(App\Partie\Equipement::class)->recalculerCombat($albrecht->fresh());
    expect((int) $albrecht->fresh()->des_attaque)->toBe(2)->and((int) $albrecht->fresh()->des_defense)->toBe(3);

    desFiges(array_fill(0, 80, 4));
    sablesMenu();
    sablesDe(1);
    sablesChoisir('sauter_sables_2_3')->assertStatus(202);

    $entrees = collect(sablesMenu()['options'][0]['parametres']['objets'])->keyBy('inventaire_id');
    expect($entrees[$epee->id]['porte'])->toBeTrue()
        ->and($entrees[$epee->id]['detail'])->toBe('porté');

    sablesDe(4);
    sablesChoisir('defausser_sables', ['cle' => "objet:{$epee->id}"])->assertStatus(202);
    sablesChoisir('defausser_sables', ['cle' => "objet:{$bouclier->id}"])->assertStatus(202)
        ->assertJsonPath('resultat.fin_de_tour', true);

    // Plus d'épée, plus de bouclier : les dés suivent, sans attendre un prochain équipement.
    $albrecht = $albrecht->fresh();
    expect((int) $albrecht->des_attaque)->toBe(1)
        ->and((int) $albrecht->des_defense)->toBe((int) ClasseHeros::where('nom', 'barbare')->value('des_defense'));
});

it('faute de deux objets, le héros ne peut pas « avoid death » : il tombe à 0 PV, à terre — pas de mort définitive', function () {
    $ctx = sablesScene();
    $albrecht = $ctx['albrecht'];
    $seul = sablesDonner($albrecht, 'Épée courte'); // UN objet : ce n'est pas assez

    desFiges(array_fill(0, 80, 4));
    sablesMenu();
    sablesDe(1);

    sablesChoisir('sauter_sables_2_3')->assertStatus(202)
        ->assertJsonPath('resultat.franchi', false)
        ->assertJsonPath('resultat.tombe', true)
        ->assertJsonPath('resultat.objets_disponibles', 1)
        ->assertJsonPath('resultat.fin_de_tour', true);

    $etat = sablesEtat($ctx);
    expect($etat->tombe)->toBeTrue()
        ->and((int) $albrecht->fresh()->pv_body)->toBe(0)
        ->and($etat->a_joue)->toBeTrue('le tour s\'achève')
        ->and($etat->enlise['phase'])->toBe('sables')
        ->and([(int) $etat->position_x, (int) $etat->position_y])->toBe([2, 3])
        ->and(Inventaire::whereKey($seul->id)->exists())->toBeTrue('il n\'a rien payé : le sacrifice ne l\'a pas sauvé');

    // Relevable comme tout héros à terre : la mort définitive est refusée dans ce jeu.
    $lignes = collect(app(JournalCombat::class)->depuisResultat([
        'type' => 'saut_sables', 'terrain' => 'Sables mouvants', 'faces' => ['crane'], 'franchi' => false,
        'tombe' => true, 'objets_disponibles' => 1,
    ], 'Albrecht'))->pluck('texte')->implode(' ');
    expect($lignes)->toContain('tombe à 0 PV');
});

it('le héros tombé dans les sables se RELÈVE comme tout autre — et reste enlisé, il ressortira à son tour', function () {
    $ctx = sablesScene();
    sablesDonner($ctx['albrecht'], 'Épée courte'); // un seul objet : il tombera

    desFiges(array_fill(0, 80, 4));
    sablesMenu();
    sablesDe(1);
    sablesChoisir('sauter_sables_2_3')->assertStatus(202)->assertJsonPath('resultat.tombe', true);

    // Bertrand (autre joueur) vient au contact de la case de sable, côté départ.
    sablesEtat($ctx, 'bertrand')->update(['position_x' => 2, 'position_y' => 2]);
    test()->actingAs($ctx['bob'], 'joueur');
    sablesDe(4);
    Cache::forget(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['bob']->id)); // Bertrand a changé de case : son menu est recalculé

    $option = sablesOption(sablesMenu(), "relever_{$ctx['albrecht']->id}");
    expect($option)->not->toBeNull('le menu offre de le relever : une case de sable n\'est pas « occupée par une autre figure »');

    sablesChoisir($option['id'])->assertStatus(202);

    $etat = sablesEtat($ctx);
    expect($etat->tombe)->toBeFalse()
        ->and((int) $ctx['albrecht']->fresh()->pv_body)->toBe(1)
        ->and($etat->enlise['phase'])->toBe('sables', 'relevé, mais toujours dans le sable')
        ->and([(int) $etat->position_x, (int) $etat->position_y])->toBe([2, 3]);

    // Bertrand termine son tour ; au tour suivant Albrecht n'a qu'un geste : se hisser dehors.
    sablesChoisir('attendre')->assertStatus(202);
    test()->actingAs($ctx['alice'], 'joueur');
    expect(collect(sablesMenu()['options'])->pluck('id')->all())->toBe(['sortir_des_sables']);
});

it('sans AUCUN objet non plus : tombé, 0 PV', function () {
    $ctx = sablesScene();
    desFiges(array_fill(0, 80, 4));
    sablesMenu();
    sablesDe(1);

    sablesChoisir('sauter_sables_2_3')->assertStatus(202)->assertJsonPath('resultat.tombe', true);

    expect(sablesEtat($ctx)->tombe)->toBeTrue()
        ->and((int) $ctx['albrecht']->fresh()->pv_body)->toBe(0);
});

it('une potion bue hors menu avant de payer ne laisse pas le héros bloqué : il ne lui reste rien à abandonner, il sombre', function () {
    $ctx = sablesScene();
    $albrecht = $ctx['albrecht'];
    $a = sablesDonner($albrecht, 'Épée courte');
    $b = sablesDonner($albrecht, 'Bouclier');

    desFiges(array_fill(0, 80, 4));
    sablesMenu();
    sablesDe(1);
    sablesChoisir('sauter_sables_2_3')->assertStatus(202)->assertJsonPath('resultat.a_defausser', 2);

    // Le sac se vide hors menu (un geste libre, un vol…) : la liste de la phase est vide.
    $a->delete();
    $b->delete();
    Cache::forget(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id)); // ce que fait toute action : le menu est recalculé
    $menu = sablesMenu();
    expect($menu['options'][0]['libelle'])->toContain('Sombrer')
        ->and($menu['options'][0]['parametres']['objets'])->toBe([]);

    sablesChoisir('defausser_sables')->assertStatus(202)
        ->assertJsonPath('resultat.tombe', true)
        ->assertJsonPath('resultat.fin_de_tour', true);

    expect(sablesEtat($ctx)->tombe)->toBeTrue();
});

// =====================================================================
// 5. LA SORTIE — au tour suivant, sur la case d'en face
// =====================================================================

it('au tour SUIVANT le héros enlisé n\'a qu\'un choix : se hisser sur la case d\'en face — et son tour s\'achève', function () {
    $ctx = sablesScene();
    $albrecht = $ctx['albrecht'];
    $a = sablesDonner($albrecht, 'Épée courte');
    $b = sablesDonner($albrecht, 'Bouclier');

    desFiges(array_fill(0, 80, 4));
    sablesMenu();
    sablesDe(1);
    sablesChoisir('sauter_sables_2_3')->assertStatus(202);
    sablesDe(4);
    sablesChoisir('defausser_sables', ['cle' => "objet:{$a->id}"])->assertStatus(202);
    sablesChoisir('defausser_sables', ['cle' => "objet:{$b->id}"])->assertStatus(202);
    expect(sablesEtat($ctx)->a_joue)->toBeTrue();

    // Bertrand joue le sien (c'est un AUTRE joueur) : le round se ferme, un nouveau tour s'ouvre,
    // Albrecht ouvre le suivant.
    test()->actingAs($ctx['bob'], 'joueur');
    sablesDe(4);
    sablesChoisir('attendre')->assertStatus(202);
    test()->actingAs($ctx['alice'], 'joueur');

    $etat = sablesEtat($ctx);
    expect($etat->a_joue)->toBeFalse('nouveau round')
        ->and($etat->enlise)->not->toBeNull('l\'enlisement survit au round, en base')
        ->and([(int) $etat->position_x, (int) $etat->position_y])->toBe([2, 3]);

    $menu = sablesMenu();
    expect($menu['options'])->toHaveCount(1)
        ->and($menu['options'][0]['id'])->toBe('sortir_des_sables')
        ->and($menu['options'][0]['type'])->toBe('sortie_sables')
        ->and($menu['options'][0]['creneau'])->toBe('tour')
        ->and($menu['options'][0]['parametres']['vers'])->toBe(['x' => 2, 'y' => 5]);

    $reponse = sablesChoisir('sortir_des_sables')->assertStatus(202);
    $reponse->assertJsonPath('resultat.type', 'sortie_sables')
        ->assertJsonPath('resultat.vers', ['x' => 2, 'y' => 5]);

    $etat = sablesEtat($ctx);
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([2, 5])
        ->and($etat->enlise)->toBeNull()
        ->and($etat->a_joue)->toBeTrue('« This ends the hero\'s turn. »');
});

it('la phase des monstres joue autour d\'un héros enlisé : un monstre vient au contact et le frappe, aucun n\'entre dans le sable', function () {
    $ctx = sablesScene();
    $albrecht = $ctx['albrecht'];
    $a = sablesDonner($albrecht, 'Épée courte');
    $b = sablesDonner($albrecht, 'Bouclier');

    // Un gobelin tout près (2, 0), un autre loin (6, 0) : l'un peut venir sur (2, 2), que le héros
    // vient de quitter, au contact de la case (2, 3) où il va s'enliser.
    $catalogue = App\Models\Monstre::where('nom_base', 'Gobelin')->firstOrFail();
    $proche = null;
    foreach ([[2, 0], [6, 0]] as [$x, $y]) {
        $instance = App\Models\InstanceMonstre::create([
            'quete_id' => $ctx['quete']->id, 'monstre_id' => $catalogue->id, 'pv_body' => $catalogue->pv_body,
            'pv_mind' => $catalogue->pv_mind, 'position_x' => $x, 'position_y' => $y, 'etat' => 'actif', 'revele' => true,
        ]);
        $proche ??= $instance;
    }

    desFiges(array_fill(0, 80, 4));
    sablesMenu();
    sablesDe(1);
    sablesChoisir('sauter_sables_2_3')->assertStatus(202);
    sablesDe(4);
    sablesChoisir('defausser_sables', ['cle' => "objet:{$a->id}"])->assertStatus(202);
    sablesChoisir('defausser_sables', ['cle' => "objet:{$b->id}"])->assertStatus(202);

    // Bertrand finit son tour : la phase des monstres se joue (dés à 4 : ni touche, ni dégât).
    test()->actingAs($ctx['bob'], 'joueur');
    sablesDe(4);
    $reponse = sablesChoisir('attendre')->assertStatus(202);
    test()->actingAs($ctx['alice'], 'joueur');

    $etat = sablesEtat($ctx);
    expect($etat->enlise)->not->toBeNull('toujours enlisé')
        ->and([(int) $etat->position_x, (int) $etat->position_y])->toBe([2, 3])
        ->and($etat->tombe)->toBeFalse()
        ->and(str_contains(json_encode($reponse->json('resultat')), 'attaque_monstre'))->toBeTrue('un monstre a frappé le héros enlisé');

    // Aucun monstre n'est entré dans le sable.
    $sables = array_map(fn ($c) => "{$c['x']},{$c['y']}", sablesDeLaCarte(['terrain' => $ctx['quete']->carte->grille['terrain']]));
    foreach ($ctx['quete']->instancesMonstres()->get() as $m) {
        expect(in_array("{$m->position_x},{$m->position_y}", $sables, true))->toBeFalse("monstre {$m->id} dans le sable");
    }
    expect([(int) $proche->fresh()->position_x, (int) $proche->fresh()->position_y])->toBe([2, 2], 'au contact, côté départ');
});

it('la case d\'en face OCCUPÉE à la sortie : il reste enlisé, le menu le dit et ne propose que de finir le tour', function () {
    $ctx = sablesScene();
    sablesEtat($ctx)->update([
        'position_x' => 2, 'position_y' => 3,
        'enlise' => ['phase' => 'sables', 'a_defausser' => 0, 'sable' => ['x' => 2, 'y' => 3],
            'vers' => ['x' => 2, 'y' => 5], 'terrain' => 'Sables mouvants'],
    ]);
    sablesEtat($ctx, 'bertrand')->update(['position_x' => 2, 'position_y' => 5]);

    $menu = app(MenuMoteur::class)->generer($ctx['groupe']->fresh(), $ctx['albrecht']->fresh());

    expect(collect($menu['options'])->pluck('id')->all())->toBe(['attendre'])
        ->and($menu['situation'])->toContain('occupée');

    // Le résolveur le refuse de même : une sortie forgée sur la case occupée est un 422.
    expect(fn () => app(ResolveurTour::class)->resoudre(
        $ctx['groupe']->fresh(), $ctx['albrecht']->fresh(),
        ['id' => 'sortir_des_sables', 'libelle' => 'x', 'type' => 'sortie_sables'],
    ))->toThrow(ValidationException::class);
});

it('le RÉSOLVEUR refuse tout autre geste à un héros enlisé, même forgé', function () {
    $ctx = sablesScene();
    sablesEtat($ctx)->update([
        'position_x' => 2, 'position_y' => 3,
        'enlise' => ['phase' => 'defausse', 'a_defausser' => 2, 'sable' => ['x' => 2, 'y' => 3],
            'vers' => ['x' => 2, 'y' => 5], 'terrain' => 'Sables mouvants'],
    ]);

    foreach ([['id' => 'se_deplacer', 'type' => 'deplacement'], ['id' => 'attendre', 'type' => 'attente'],
        ['id' => 'sortir_des_sables', 'type' => 'sortie_sables']] as $option) {
        expect(fn () => app(ResolveurTour::class)->resoudre(
            $ctx['groupe']->fresh(), $ctx['albrecht']->fresh(), $option + ['libelle' => 'x'], ['x' => 2, 'y' => 2],
        ))->toThrow(ValidationException::class, '', "option {$option['id']}");
    }
});

// =====================================================================
// 6. PUBLIÉ, ANNONCÉ, SAUVEGARDÉ
// =====================================================================

it('publie le sable (bloquant, phrases du serveur) et l\'enlisement du héros, et le fil le dit', function () {
    $ctx = sablesScene();
    $albrecht = $ctx['albrecht'];
    sablesDonner($albrecht, 'Épée courte');
    sablesDonner($albrecht, 'Bouclier');

    $etatAvant = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    $sable = collect($etatAvant['carte']['terrain'])->firstWhere(fn ($t) => $t['x'] === 2 && $t['y'] === 3);
    expect($sable['nom'])->toBe('Sables mouvants')
        ->and($sable['bloque_mouvement'])->toBeTrue()
        ->and($sable['bloque_vue'])->toBeFalse()
        ->and(implode(' ', $sable['avantages']))->toContain('bouclier noir')
        ->and(collect($etatAvant['entites'])->firstWhere('nom', 'Albrecht')['enlise'])->toBeNull();

    desFiges(array_fill(0, 80, 4));
    sablesMenu();
    sablesDe(1);
    $reponse = sablesChoisir('sauter_sables_2_3')->assertStatus(202);

    $etatApres = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    expect(collect($etatApres['entites'])->firstWhere('nom', 'Albrecht')['enlise'])
        ->toBe(['a_defausser' => 2, 'vers' => ['x' => 2, 'y' => 5], 'terrain' => 'Sables mouvants']);

    // Le fil de combat (construit sur le RÉSULTAT) dit le jet et l'enlisement.
    $texte = collect(app(JournalCombat::class)->depuisResultat($reponse->json('resultat'), 'Albrecht'))->pluck('texte')->implode(' ');
    expect($texte)->toContain('retombe dans')->and($texte)->toContain('abandonner 2 objets');
});

it('ne publie le sable QUE sous un brouillard levé', function () {
    $ctx = sablesScene();
    $cases = array_fill(0, 7, array_fill(0, 7, 's'));
    $cases[3][1] = 'b'; // une case de sable masquée

    $methode = new ReflectionMethod(EtatGroupe::class, 'terrain');
    $methode->setAccessible(true);
    $publies = collect($methode->invoke(app(EtatGroupe::class), $ctx['quete']->carte, $cases));

    expect($publies)->toHaveCount(9)
        ->and($publies->contains(fn ($t) => $t['x'] === 1 && $t['y'] === 3))->toBeFalse();
});

it('l\'enlisement est porté par le snapshot : une reprise ne fait pas sortir un héros des sables sans qu\'il ait payé', function () {
    $ctx = sablesScene();
    $enlise = ['phase' => 'sables', 'a_defausser' => 0, 'sable' => ['x' => 2, 'y' => 3],
        'vers' => ['x' => 2, 'y' => 5], 'terrain' => 'Sables mouvants'];
    sablesEtat($ctx)->update(['position_x' => 2, 'position_y' => 3, 'enlise' => $enlise]);

    $snapshot = app(App\Partie\Sauvegarde::class)->snapshotter($ctx['groupe']->fresh(), 'nouveau_tour');
    sablesEtat($ctx)->update(['enlise' => null, 'position_x' => 2, 'position_y' => 2]);

    app(App\Partie\Sauvegarde::class)->restaurer($ctx['groupe']->fresh(), $snapshot);

    $etat = sablesEtat($ctx);
    expect($etat->enlise)->toBe($enlise)
        ->and([(int) $etat->position_x, (int) $etat->position_y])->toBe([2, 3]);
});

// =====================================================================
// 7. LE PLACEMENT — sous le thème, jamais sans ; jamais de connectivité cassée
// =====================================================================

function sablesAssembler(int $graine, ?string $theme = 'mage_du_miroir', int $bandes = 1): array
{
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
    $gabarit->structure = [...$gabarit->structure, 'terrains' => ['min' => 0, 'max' => 0, 'sables' => ['min' => $bandes, 'max' => $bandes]]];

    return app(AssembleurCarte::class)->assembler(
        $gabarit, $graine, bestiaire: $theme === null ? null : BestiaireGroupe::auto($theme),
    );
}

/** @return list<array{x: int, y: int}> */
function sablesDeLaCarte(array $carte): array
{
    $id = idSables();

    return array_values(array_map(
        fn ($t) => ['x' => (int) $t['x'], 'y' => (int) $t['y']],
        array_filter($carte['terrain'], fn ($t) => (int) $t['terrain_id'] === $id),
    ));
}

it('ne pose des sables que sous le thème mage_du_miroir — et en pose sous lui', function () {
    foreach ([null, 'horreur_des_glaces', 'jungles_delthrak', 'horde_ogre'] as $theme) {
        foreach ([3, 11, 42] as $graine) {
            expect(sablesDeLaCarte(sablesAssembler($graine, $theme)))->toBe([], "thème {$theme}, graine {$graine}");
        }
    }

    $avecBande = 0;
    foreach (range(1, 40) as $graine) {
        $avecBande += sablesDeLaCarte(sablesAssembler($graine)) !== [] ? 1 : 0;
    }

    expect($avecBande)->toBeGreaterThan(10, 'sur 40 graines, au moins un quart des donjons portent une bande');
});

it('ne pose aucune bande quand le gabarit ne dit pas COMBIEN (le thème dit si, le gabarit dit combien)', function () {
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
    $structure = $gabarit->structure;
    unset($structure['terrains']['sables']);
    $gabarit->structure = $structure;

    foreach ([5, 17, 29] as $graine) {
        $carte = app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: BestiaireGroupe::auto('mage_du_miroir'));

        expect(sablesDeLaCarte($carte))->toBe([], "graine {$graine}");
    }
});

it('est déterministe : deux donjons de même graine portent la même bande', function () {
    foreach ([7, 21, 33] as $graine) {
        expect(sablesDeLaCarte(sablesAssembler($graine)))->toBe(sablesDeLaCarte(sablesAssembler($graine)));
    }
});

it('une bande ne casse JAMAIS la connectivité, laisse un plancher de cases libres et une vraie ligne de saut (60 graines)', function () {
    $minimum = (new ReflectionClassConstant(AssembleurCarte::class, 'CASES_JOUABLES_MINIMUM'))->getValue();
    $idsBloquants = Mobilier::where('bloque_mouvement', true)->pluck('id')->all();
    $bandes = 0;

    foreach (range(1, 60) as $graine) {
        $carte = sablesAssembler($graine);
        $sables = sablesDeLaCarte($carte);

        if ($sables === []) {
            continue;
        }

        $bandes++;
        $cles = array_flip(array_map(fn ($c) => "{$c['x']},{$c['y']}", $sables));
        $grille = new Grille($carte['cases']);
        $bloquees = $cles;

        foreach ($carte['mobilier'] as $m) {
            if (in_array($m['mobilier_id'], $idsBloquants, true)) {
                foreach ($grille->cellulesEmprise($m['x'], $m['y'], $m['l'], $m['h']) as $c) {
                    $bloquees["{$c['x']},{$c['y']}"] = true;
                }
            }
        }

        // Une seule bande : 2 rangées de profondeur, toutes dans la MÊME salle, jamais la 0.
        $salles = array_unique(array_map(fn ($c) => Salles::indexDe($carte['salles'], $c['x'], $c['y']), $sables));
        expect($salles)->toHaveCount(1, "graine {$graine}")
            ->and(reset($salles))->not->toBe(0, "graine {$graine} : sable dans la salle de départ");

        // Du SOL, hors piège, meuble, levier, épreuve — et jamais devant une porte, un levier ou une épreuve.
        $autres = [];
        foreach ($carte['pieges'] as $p) { $autres[] = [$p['x'], $p['y']]; }
        foreach ($carte['leviers'] ?? [] as $l) { $autres[] = [$l['x'], $l['y']]; }
        foreach ($carte['epreuves'] ?? [] as $e) { $autres[] = [$e['x'], $e['y']]; }
        // Le PARVIS : on agit sur une porte, un levier, une épreuve au contact (pas sur un piège).
        $parvis = [];
        foreach ($carte['leviers'] ?? [] as $l) { $parvis[] = [$l['x'], $l['y']]; }
        foreach ($carte['epreuves'] ?? [] as $e) { $parvis[] = [$e['x'], $e['y']]; }
        foreach ($carte['portes'] as $porte) {
            foreach (Grille::casesPorte($porte) as $c) { $parvis[] = [(int) $c['x'], (int) $c['y']]; }
        }

        foreach ($sables as $c) {
            expect($carte['cases'][$c['y']][$c['x']])->toBe('s', "graine {$graine}");
            foreach ($autres as [$x, $y]) {
                expect([$c['x'], $c['y']])->not->toBe([(int) $x, (int) $y], "graine {$graine} : sable sur une autre couche");
            }
            foreach ($parvis as [$x, $y]) {
                expect(abs($c['x'] - $x) + abs($c['y'] - $y))->not->toBe(1, "graine {$graine} : sable devant une porte/levier/épreuve");
            }
        }

        // CONNEXITÉ de toute la carte : chaque case de sol non bloquée reste atteignable depuis le départ.
        $depart = $carte['spawn_heros'][0];
        $vus = ["{$depart['x']},{$depart['y']}" => true];
        $pile = [[(int) $depart['x'], (int) $depart['y']]];
        while ($pile !== []) {
            [$x, $y] = array_pop($pile);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $k = ($x + $dx).','.($y + $dy);
                if (isset($vus[$k]) || isset($bloquees[$k]) || ($carte['cases'][$y + $dy][$x + $dx] ?? 'm') !== 's') {
                    continue;
                }
                $vus[$k] = true;
                $pile[] = [$x + $dx, $y + $dy];
            }
        }
        $libres = 0;
        foreach ($carte['cases'] as $y => $ligne) {
            foreach ($ligne as $x => $v) {
                $libres += ($v === 's' && ! isset($bloquees["{$x},{$y}"])) ? 1 : 0;
            }
        }
        expect(count($vus))->toBe($libres, "graine {$graine} : la carte n'est plus connexe");

        // PLANCHER : la salle de la bande garde de quoi jouer.
        $salle = $carte['salles'][reset($salles)];
        $interieur = 0;
        for ($r = 0; $r < $salle['hauteur']; $r++) {
            for ($c = 0; $c < $salle['largeur']; $c++) {
                $k = ($salle['x'] + $c).','.($salle['y'] + $r);
                $interieur += (($carte['cases'][$salle['y'] + $r][$salle['x'] + $c] ?? 'm') === 's' && ! isset($bloquees[$k])) ? 1 : 0;
            }
        }
        expect($interieur)->toBeGreaterThanOrEqual($minimum, "graine {$graine} : salle injouable");

        // Une LIGNE DE SAUT au moins : départ, deux sables, réception — tout en sol libre.
        $ligne = false;
        foreach ($sables as $c) {
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $departLigne = ['x' => $c['x'] - $dx, 'y' => $c['y'] - $dy];
                $deux = ['x' => $c['x'] + $dx, 'y' => $c['y'] + $dy];
                $arrivee = ['x' => $c['x'] + 2 * $dx, 'y' => $c['y'] + 2 * $dy];
                $libre = fn (array $p) => ($carte['cases'][$p['y']][$p['x']] ?? 'm') === 's' && ! isset($bloquees["{$p['x']},{$p['y']}"]);

                $ligne = $ligne || ($libre($departLigne) && isset($cles["{$deux['x']},{$deux['y']}"]) && $libre($arrivee));
            }
        }
        expect($ligne)->toBeTrue("graine {$graine} : aucune ligne de saut — le sable ne serait qu'un mur");
    }

    expect($bandes)->toBeGreaterThan(10, 'le test ne prouve rien si presque aucune bande n\'a été posée');
});
