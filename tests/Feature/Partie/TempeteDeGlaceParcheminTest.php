<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\MotsClesReactionMj as Mot;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Models\SortDread;
use App\Partie\JournalCombat;
use App\Partie\ZonesDeSort;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * SPELL SCROLL — ICE STORM (The Frozen Horror, Artifact Reference p. 35 — `reference/18_extensions.md` §3), PORTÉE le
 * 2026-11-13 (plan `docs/plan-parchemins-glace.md`, lot 3) : « This spell creates a blizzard of ice that affects an area
 * 2 squares wide by 2 squares long. Each monster and hero in that area is attacked separately by the spellcaster with 3
 * combat dice. There is no chance to defend. Cannot be used in corridors. »
 *
 * Décisions de René (lot 0) : le joueur CHOISIT le carré ; il tient ENTIÈREMENT dans la salle du lanceur ; il suffit que
 * le lanceur VOIE UNE des quatre cases ; les héros du carré, lanceur compris, sont frappés ; le Brassard de Glace réduit
 * la Tempête lancée par un héros ; difficulté de lecture 2.
 *
 * Le parchemin se lit par la VRAIE route (`/menu`, `/choix`) : une entrée PAR carré, `cle` = `parchemin:{id}:carre:{x},{y}`
 * (le coin haut-gauche), et seules les entrées publiées sont acceptées. Les dés ne servent qu'à la frappe (trois par
 * figure) et au jet de Mind d'un non-lanceur : chaque assertion sur un PV le dit donc.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, // le parchemin dérive du sort
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        SortDreadSeeder::class,
    ]);
});

/**
 * Une quête sur un plateau de `taille` cases, UNE salle (par défaut tout le plateau 7×7), sans portes.
 *
 * Défauts : le magicien Aldric en (3,3) ; l'allié Brunhilde en (4,3), À SA DROITE, dans le carré de coin (3,3) avec le
 * Gobelin en (4,4) ; un Gobelin en (4,4) et un Orque en (1,1), à 3 PV. `$cfg` surcharge :
 *  - `classe` : la classe du lanceur ;
 *  - `figures` : `[nom_base, x, y, pv]` révélées et actives ;
 *  - `allie` : position de Brunhilde, ou `null` pour aucun allié ;
 *  - `lanceur` : position du lanceur ;
 *  - `murs` : cases de roche DANS la salle, `[[x, y], …]` ;
 *  - `salles` : les salles, ou `[]` pour un COULOIR (aucune salle) ;
 *  - `brassard` : Brunhilde porte le Brassard de Glace.
 *
 * @param  array<string, mixed>  $cfg
 * @return array{alice: JoueurAuthentifiable, groupe: \App\Models\Groupe, lanceur: Personnage, allie: ?Personnage, quete: Quete, etat: EtatPersonnageQuete, figures: list<InstanceMonstre>}
 */
function tempeteScene(array $cfg = []): array
{
    $cfg += [
        'classe' => 'magicien',
        'figures' => [['Gobelin', 4, 4, 3], ['Orque', 1, 1, 3]],
        'allie' => ['x' => 4, 'y' => 3],
        'lanceur' => ['x' => 3, 'y' => 3],
        'murs' => [],
        'salles' => null,
        'taille' => [7, 7],
        'brassard' => false,
    ];

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $lanceur = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => $cfg['classe']]);

    // L'allié entre AVANT le départ de la quête : sans état de quête, il ne serait pas une figure du plateau.
    $allie = null;

    if ($cfg['allie'] !== null) {
        $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
        $allie = creerHeros($bob, $groupe, 'Brunhilde', 2);
    }

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $lanceur->id)->firstOrFail();

    [$largeur, $hauteur] = $cfg['taille'];
    $cases = array_fill(0, $hauteur, array_fill(0, $largeur, 's'));

    foreach ($cfg['murs'] as [$mx, $my]) {
        $cases[$my][$mx] = 'm';
    }

    $salles = $cfg['salles'] ?? [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]];

    $quete->carte->update([
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => $hauteur,
            'cases' => $cases, 'salles' => $salles,
            'portes' => [], 'pieges' => [], 'mobilier' => [], 'terrain' => [], 'leviers' => [], 'epreuves' => [],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);
    $quete->update(['salles_decouvertes' => $salles === [] ? [] : [0]]);

    $quete->instancesMonstres()->update(['etat' => 'vaincu', 'revele' => false]);

    $instances = [];

    foreach ($cfg['figures'] as [$nomBase, $x, $y, $pv]) {
        $instances[] = InstanceMonstre::create([
            'quete_id' => $quete->id,
            'monstre_id' => Monstre::where('nom_base', $nomBase)->firstOrFail()->id,
            'pv_body' => $pv, 'pv_body_max' => $pv, 'pv_mind' => 0,
            'etat' => 'actif', 'revele' => true,
            'position_x' => $x, 'position_y' => $y,
        ]);
    }

    $etat->update([
        'position_x' => $cfg['lanceur']['x'], 'position_y' => $cfg['lanceur']['y'],
        'a_joue' => false, 'a_deplace' => false, 'a_agi' => false, 'tombe' => false,
        'deplacement_restant' => null, 'capacites_tour' => null,
    ]);

    if ($allie !== null) {
        EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $allie->id)
            ->update(['position_x' => $cfg['allie']['x'], 'position_y' => $cfg['allie']['y'], 'tombe' => false]);

        if ($cfg['brassard']) {
            Inventaire::create([
                'personnage_id' => $allie->id,
                'objet_id' => Objet::where('nom', 'Brassard de Glace')->value('id'),
                'emplacement' => 'talisman',
                'quantite' => 1,
            ]);
        }
    }

    Cache::forget(GenererMenu::cleMenu($groupe->id, $alice->id));

    return compact('alice', 'groupe', 'lanceur', 'allie', 'quete', 'etat') + ['figures' => $instances];
}

/** Donne le parchemin de Tempête de Glace au sac du héros. */
function tempeteDonner(Personnage $heros): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Parchemin : Tempête de Glace')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);
}

/** Le menu RÉEL du joueur. @return list<array<string, mixed>> */
function tempeteMenu(JoueurAuthentifiable $alice): array
{
    Cache::forget(GenererMenu::cleMenu(\App\Models\Groupe::where('identifiant', 'table-1')->value('id'), $alice->id));

    return test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options');
}

/**
 * Les entrées de CARRÉ du menu pour CE sac (`parchemin:{id}:carre:…`), dans l'ordre publié.
 *
 * @return list<array<string, mixed>>
 */
function tempeteCarres(JoueurAuthentifiable $alice, int $inventaireId): array
{
    $options = collect(tempeteMenu($alice));
    $parchemins = $options->firstWhere('id', 'lire_parchemin')['parametres']['parchemins'] ?? [];

    return collect($parchemins)
        ->filter(fn (array $entree) => str_starts_with((string) $entree['cle'], "parchemin:{$inventaireId}:carre:"))
        ->values()
        ->all();
}

/** Les noms touchés par une entrée de carré, dans l'ordre publié. @return list<string> */
function tempeteNoms(array $entree): array
{
    return collect($entree['touches'])->pluck('nom')->values()->all();
}

/** Lit le parchemin par la route de choix, sur le carré désigné par `$cle`. */
function tempeteLire(Inventaire $ligne, string $cle)
{
    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => $cle],
    ]);
}

// ==================================================================
// LE CATALOGUE ET LA CARTE
// ==================================================================

it('seed la Tempête de Glace : parchemin, zone de figures sur un carré 2×2, hors couloir, 3 dés sans défense', function () {
    $sort = Sort::where('nom', 'Tempête de Glace')->firstOrFail();

    expect($sort->element)->toBe('parchemin')
        ->and($sort->type)->toBe('degats')
        ->and($sort->difficulte_parchemin)->toBe(2)
        ->and($sort->effet)->toMatchArray([
            'cible' => 'figures_zone',
            'zone' => 'carre_2x2',
            'des_degats' => 3,
            'defense_applicable' => false,
            'hors_couloir' => true,
        ]);
});

it('n\'a AUCUNE nature : ni froid ni autre, pour que le Brassard la réduise au lieu de l\'annuler', function () {
    $sort = Sort::where('nom', 'Tempête de Glace')->firstOrFail();

    expect(array_key_exists('type_degat', (array) $sort->effet))->toBeFalse()
        ->and(array_key_exists('type_degat', (array) SortDread::where('nom', 'Tempête de Glace')->firstOrFail()->effet))->toBeFalse();
});

it('dérive un « Parchemin : Tempête de Glace » au sac, comme tout parchemin', function () {
    $objet = Objet::where('nom', 'Parchemin : Tempête de Glace')->firstOrFail();

    expect($objet->categorie)->toBe('parchemin')
        ->and($objet->effet['sort_nom'])->toBe('Tempête de Glace')
        ->and($objet->effet['sort_id'])->toBe(Sort::where('nom', 'Tempête de Glace')->value('id'));
});

it('porte la carte Spell Scroll — Ice Storm : elle désigne le sort, et n\'est plus une dette', function () {
    $carte = collect(config('cartes.parchemins.cartes'))->firstWhere('carte', 'Spell Scroll — Ice Storm');

    expect($carte['sort'] ?? null)->toBe('Tempête de Glace')
        ->and(array_key_exists('manque', $carte))->toBeFalse();
});

it('la Tempête de Glace du HÉROS et celle du DREAD sont deux sorts distincts, dans leurs deux tables', function () {
    $heros = Sort::where('nom', 'Tempête de Glace')->firstOrFail();
    $dread = SortDread::where('nom', 'Tempête de Glace')->firstOrFail();

    expect($heros->getTable())->toBe('sorts')
        ->and($dread->getTable())->toBe('sorts_dread')
        ->and(data_get($dread->effet, 'zone'))->toBe('carre_2x2');
});

// ==================================================================
// EN JEU — l'offre : une entrée PAR carré légal, et seulement ceux-là
// ==================================================================

it('publie EXACTEMENT les carrés attendus : un par coin qui tient dans la salle, voit le lanceur et frappe un monstre', function () {
    $ctx = tempeteScene();
    $ligne = tempeteDonner($ctx['lanceur']);

    $carres = tempeteCarres($ctx['alice'], $ligne->id);

    // Le Gobelin (4,4) est dans les carrés de coin (3,3), (4,3), (3,4), (4,4) ; l'Orque (1,1) dans ceux de (0,0), (1,0),
    // (0,1), (1,1). Tous sont vus du lanceur (3,3). Aucun autre coin ne touche un monstre : aucune entrée.
    $attendus = collect(['0,0', '1,0', '0,1', '1,1', '3,3', '4,3', '3,4', '4,4'])
        ->map(fn (string $coin) => "parchemin:{$ligne->id}:carre:{$coin}")
        ->sort()->values()->all();

    expect(collect($carres)->pluck('cle')->sort()->values()->all())->toBe($attendus);

    foreach ($carres as $entree) {
        [$x, $y] = array_map('intval', explode(',', (string) str_replace("parchemin:{$ligne->id}:carre:", '', $entree['cle'])));

        expect(array_key_exists('cibles', $entree))->toBeFalse('la zone EST la cible : aucune liste de cibles à ouvrir')
            ->and($entree['disponible'])->toBeTrue()
            ->and(collect($entree['cases'])->map(fn ($c) => [$c['x'], $c['y']])->all())
            ->toBe(collect(ZonesDeSort::carre($x, $y))->map(fn ($c) => [$c['x'], $c['y']])->all(), 'les quatre cases du carré, dans l\'ordre de la géométrie')
            ->and($entree['nom'])->toStartWith("Tempête de Glace — carré en {$x},{$y} : ")
            ->and(collect($entree['touches'])->contains('type', 'monstre'))->toBeTrue('un carré publié frappe au moins un monstre');
    }
});

it('le nom du carré dit ce qu\'il touche : le lanceur, son allié et le Gobelin dans le carré de coin (3,3)', function () {
    $ctx = tempeteScene();
    $ligne = tempeteDonner($ctx['lanceur']);

    $carre = collect(tempeteCarres($ctx['alice'], $ligne->id))->firstWhere('cle', "parchemin:{$ligne->id}:carre:3,3");

    expect($carre)->not->toBeNull()
        ->and(tempeteNoms($carre))->toBe(['Aldric', 'Brunhilde', 'Gobelin'], 'héros d\'abord (le lanceur compris), puis monstres')
        ->and($carre['nom'])->toBe('Tempête de Glace — carré en 3,3 : Aldric, Brunhilde, Gobelin')
        ->and(collect($carre['touches'])->pluck('type')->all())->toBe(['heros', 'heros', 'monstre']);
});

it('n\'offre AUCUN carré qui ne frapperait que des héros : un allié seul, sans monstre, n\'ouvre rien', function () {
    $ctx = tempeteScene(['figures' => []]);
    $ligne = tempeteDonner($ctx['lanceur']);

    expect(tempeteCarres($ctx['alice'], $ligne->id))->toBe([]);
});

it('un carré qui DÉBORDE de la salle n\'est jamais offert, même avec un monstre dedans et en vue', function () {
    // La salle n\'occupe que les colonnes 0 à 3 ; la colonne 4 est un couloir. Le Gobelin (4,3) est en vue du magicien
    // (1,3) — mais tout carré qui le touche déborde sur le couloir.
    $ctx = tempeteScene([
        'lanceur' => ['x' => 1, 'y' => 3],
        'figures' => [['Gobelin', 4, 3, 3]],
        'allie' => null,
        'salles' => [['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 7, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
    ]);
    $ligne = tempeteDonner($ctx['lanceur']);

    expect(tempeteCarres($ctx['alice'], $ligne->id))->toBe([]);
});

it('un carré DERRIÈRE une cloison de roche n\'est pas offert : le magicien ne le voit pas, même dans la même salle', function () {
    // Une cloison en colonne 3 (y 0 à 4) coupe la salle ; le magicien est à gauche (1,1), le Gobelin à droite (4,0).
    // Le seul carré qui le touche tient à droite de la cloison : aucune de ses cases n'est en vue.
    $ctx = tempeteScene([
        'lanceur' => ['x' => 1, 'y' => 1],
        'figures' => [['Gobelin', 4, 0, 3]],
        'allie' => null,
        'murs' => [[3, 0], [3, 1], [3, 2], [3, 3], [3, 4]],
    ]);
    $ligne = tempeteDonner($ctx['lanceur']);

    expect(tempeteCarres($ctx['alice'], $ligne->id))->toBe([]);
});

it('un carré qui touche de la ROCHE n\'est pas offert : les coins voisins du mur restent, eux', function () {
    // Un mur en (4,3) : les carrés de coin (3,3) et (4,3) le contiennent, donc ne sont pas jouables. Le Gobelin (4,4)
    // reste touché par (3,4) et (4,4), qui ne le touchent pas.
    $ctx = tempeteScene([
        'figures' => [['Gobelin', 4, 4, 3]],
        'allie' => null,
        'murs' => [[4, 3]],
    ]);
    $ligne = tempeteDonner($ctx['lanceur']);

    expect(collect(tempeteCarres($ctx['alice'], $ligne->id))->pluck('cle')->sort()->values()->all())
        ->toBe([
            "parchemin:{$ligne->id}:carre:3,4",
            "parchemin:{$ligne->id}:carre:4,4",
        ]);
});

it('dans un COULOIR, le magicien n\'a aucun carré — et le parchemin n\'est pas offert du tout', function () {
    $ctx = tempeteScene(['salles' => []]);
    $ligne = tempeteDonner($ctx['lanceur']);

    expect(tempeteCarres($ctx['alice'], $ligne->id))->toBe([])
        ->and(collect(tempeteMenu($ctx['alice']))->firstWhere('id', 'lire_parchemin'))->toBeNull();
});

// ==================================================================
// EN JEU — la frappe : chaque figure à part, trois dés, sans défense
// ==================================================================

it('frappe CHAQUE figure du carré à part, à trois dés sans défense — lanceur et allié compris, le Gobelin tombe', function () {
    $ctx = tempeteScene();
    $ligne = tempeteDonner($ctx['lanceur']);

    // Trois crânes par figure (lanceur, allié, Gobelin). Les trois boucliers NOIRS qui suivent ne servent qu'à une DÉFENSE :
    // aucune figure n'en lance, sans quoi le Gobelin (3 PV) ne tomberait pas.
    desFiges([1, 1, 1, 1, 1, 1, 1, 1, 1, 6, 6, 6]);

    $resultat = tempeteLire($ligne, "parchemin:{$ligne->id}:carre:3,3")
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'parchemin')
        ->assertJsonPath('resultat.lanceur_de_sorts', true)
        ->assertJsonPath('resultat.zone', 'carre_2x2')
        ->assertJsonPath('resultat.consomme', true)
        ->json('resultat');

    expect((int) $ctx['lanceur']->fresh()->pv_body)->toBe(5, 'le lanceur, dans son carré, est frappé : 3 dés, 3 crânes')
        ->and((int) $ctx['allie']->fresh()->pv_body)->toBe(5, 'l\'allié : tir ami, 3 dés, 3 crânes')
        ->and((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(0, 'le Gobelin : 3 dés sans défense, il tombe')
        ->and((int) $ctx['figures'][1]->fresh()->pv_body)->toBe(3, 'l\'Orque, hors du carré, est intact')
        ->and(Inventaire::find($ligne->id))->toBeNull('le parchemin est détruit à la lecture');

    expect(collect($resultat['resultats'])->pluck('cible.nom')->all())->toBe(['Aldric', 'Brunhilde', 'Gobelin'])
        ->and(collect($resultat['resultats'])->pluck('degats')->all())->toBe([3, 3, 3])
        ->and(collect($resultat['resultats'])->last()['cible_vaincue'])->toBeTrue()
        ->and(collect($resultat['cases'])->map(fn ($c) => [$c['x'], $c['y']])->all())->toBe([[3, 3], [4, 3], [3, 4], [4, 4]]);
});

it('le porteur du Brassard de Glace perd 1 de MOINS : la Tempête lancée par un héros est réduite, et le coup le DIT', function () {
    $ctx = tempeteScene(['brassard' => true]);
    $ligne = tempeteDonner($ctx['lanceur']);

    desFiges([1, 1, 1, 1, 1, 1, 1, 1, 1]);

    $resultat = tempeteLire($ligne, "parchemin:{$ligne->id}:carre:3,3")->assertStatus(202)->json('resultat');
    $frappeAllie = collect($resultat['resultats'])->firstWhere('cible.nom', 'Brunhilde');

    expect((int) $ctx['allie']->fresh()->pv_body)->toBe(6, 'Brunhilde porte le Brassard : 3 crânes, réduits de 1 → 2 PV')
        ->and($frappeAllie['degats'])->toBe(2)
        ->and($frappeAllie['modificateurs'] ?? null)->toBe([['source' => 'Brassard de Glace', 'valeur' => -1, 'sur' => 'degats']])
        ->and((int) $ctx['lanceur']->fresh()->pv_body)->toBe(5, 'le lanceur, sans Brassard, perd ses 3')
        ->and((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(0, 'le Gobelin, lui, tombe toujours');

    $fil = collect(app(JournalCombat::class)->depuisResultat($resultat, 'Aldric'))->pluck('texte')->implode(' ¦ ');

    expect($fil)->toContain('Brunhilde : Brassard de Glace réduit le coup de 1');
});

it('le fil annonce la zone, une ligne par figure, et la chute du Gobelin', function () {
    $ctx = tempeteScene();
    $ligne = tempeteDonner($ctx['lanceur']);

    desFiges([1, 1, 1, 1, 1, 1, 1, 1, 1]);

    $resultat = tempeteLire($ligne, "parchemin:{$ligne->id}:carre:3,3")->assertStatus(202)->json('resultat');
    $fil = collect(app(JournalCombat::class)->depuisResultat($resultat, 'Aldric'))->pluck('texte')->implode(' ¦ ');

    expect($fil)->toContain('Tempête de Glace')
        ->toContain('3 figures dans sa zone')
        ->toContain('Aldric subit −3 PV')
        ->toContain('Brunhilde subit −3 PV')
        ->toContain('Gobelin tombe (−3 PV) !');
});

it('un Dispel du MJ (Gretzl, dans le carré) annule la Tempête : sort annulé, parchemin consommé, personne ne perd rien', function () {
    // Un barbare lit le parchemin : son jet de Mind (difficulté 2, Mind 2) se fait avec deux crânes, réussi.
    $ctx = tempeteScene([
        'classe' => 'barbare',
        'figures' => [['Gretzl la Porte-Fléau', 4, 4, 5], ['Orque', 1, 1, 3]],
    ]);
    $ligne = tempeteDonner($ctx['lanceur']);

    desFiges([1, 1]);

    $resultat = tempeteLire($ligne, "parchemin:{$ligne->id}:carre:3,3")->assertStatus(202)->json('resultat');

    expect($resultat['sort_annule'])->toBeTrue()
        ->and($resultat['reactions_mj'][0]['mecanique'])->toBe(Mot::ANNULE_SORT_CIBLANT)
        ->and($resultat)->not->toHaveKey('resultats')
        ->and((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(5, 'Gretzl n\'a rien perdu')
        ->and((int) $ctx['allie']->fresh()->pv_body)->toBe(8, 'son allié n\'a rien perdu : le sort est annulé en entier')
        ->and(Inventaire::find($ligne->id))->toBeNull('lancé donc consommé, même annulé');
});

it('un monstre qui ne se laisse toucher que par le FEU (Kessandria) n\'est ni publié dans le carré, ni frappé', function () {
    $ctx = tempeteScene([
        'figures' => [['Kessandria', 4, 4, 3], ['Gobelin', 4, 3, 3]],
        'allie' => null,
    ]);
    $ligne = tempeteDonner($ctx['lanceur']);

    $carre = collect(tempeteCarres($ctx['alice'], $ligne->id))->firstWhere('cle', "parchemin:{$ligne->id}:carre:3,3");

    expect($carre)->not->toBeNull()
        ->and(tempeteNoms($carre))->toBe(['Aldric', 'Gobelin'], 'Kessandria est exclue de la décision publiée');

    desFiges([1, 1, 1, 1, 1, 1]);

    $resultat = tempeteLire($ligne, "parchemin:{$ligne->id}:carre:3,3")->assertStatus(202)->json('resultat');

    expect((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(3, 'Kessandria garde ses 3 PV')
        ->and((int) $ctx['figures'][1]->fresh()->pv_body)->toBe(0, 'le Gobelin tombe')
        ->and(collect($resultat['resultats'])->pluck('cible.nom')->all())->toBe(['Aldric', 'Gobelin']);
});

// ==================================================================
// EN JEU — la route : une clé forgée n'est jamais jouée, un sort CONNU suit le même générateur
// ==================================================================

it('une clé de carré FORGÉE, hors de la liste, est refusée en 422 — et le parchemin n\'est pas consommé', function () {
    $ctx = tempeteScene();
    $ligne = tempeteDonner($ctx['lanceur']);

    desFiges(array_fill(0, 30, 1));

    // (5,5) : son carré ne contient aucun monstre — jamais offert, donc jamais légal.
    tempeteLire($ligne, "parchemin:{$ligne->id}:carre:5,5")->assertStatus(422);

    expect(Inventaire::find($ligne->id))->not->toBeNull('refusé : rien n\'est consommé')
        ->and((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(3, 'aucune figure n\'est touchée');
});

it('une entrée OFFERTE est toujours acceptée par la route : aucun « Cible requise », aucun 422', function () {
    $ctx = tempeteScene();
    $ligne = tempeteDonner($ctx['lanceur']);

    $entree = collect(tempeteCarres($ctx['alice'], $ligne->id))->firstWhere('cle', "parchemin:{$ligne->id}:carre:4,4");

    expect($entree)->not->toBeNull();

    desFiges(array_fill(0, 30, 1));

    tempeteLire($ligne, $entree['cle'])->assertStatus(202);
});

it('un sort CONNU de carré (même générateur que le parchemin) : entrée `sort:{id}:carre:x,y`, résolue comme le parchemin', function () {
    $ctx = tempeteScene();
    $sort = Sort::where('nom', 'Tempête de Glace')->firstOrFail();
    $ctx['lanceur']->sorts()->attach($sort->id, ['disponible' => true]);

    $entrees = collect(collect(tempeteMenu($ctx['alice']))->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])
        ->filter(fn (array $e) => str_starts_with((string) $e['cle'], "sort:{$sort->id}:carre:"))
        ->values();

    // Les MÊMES huit carrés que le parchemin de la scène : le sort connu passe par le même générateur.
    expect($entrees)->toHaveCount(8);

    desFiges(array_fill(0, 30, 1));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sort->id}:carre:3,3"],
    ])->assertStatus(202)->assertJsonPath('resultat.zone', 'carre_2x2');

    expect((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(0, 'le Gobelin est tombé, comme pour le parchemin');
});

it('la liste suit les figures : un monstre qui entre dans le coin (0,0) ouvre CE carré, et lui seul', function () {
    // Témoin de la décision publiée : aucune entrée sans monstre, et l'entrée apparaît dès qu'un monstre entre dans un coin.
    // Le monstre en (0,0) n'est dans AUCUN autre carré que celui de coin (0,0), vu du lanceur (3,3) par la diagonale.
    $ctx = tempeteScene(['figures' => []]);
    $ligne = tempeteDonner($ctx['lanceur']);

    expect(tempeteCarres($ctx['alice'], $ligne->id))->toBe([]);

    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 3, 'pv_body_max' => 3, 'pv_mind' => 0,
        'etat' => 'actif', 'revele' => true,
        'position_x' => 0, 'position_y' => 0,
    ]);

    expect(collect(tempeteCarres($ctx['alice'], $ligne->id))->pluck('cle')->values()->all())
        ->toBe(["parchemin:{$ligne->id}:carre:0,0"]);
});
