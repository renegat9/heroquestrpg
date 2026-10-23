<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\Evenement;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\DemarreurQuete;
use App\Partie\MoteurDread;
use App\Partie\Salles;
use App\Partie\Sauvegarde;
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
use Illuminate\Support\Facades\Http;

/*
 * PASSIF JUSQU'À DÉCLENCHEUR — Kellar's Keep lot C (2026-10-23) et Witch Lord q. 5.
 *
 * Chaque déclencheur est éprouvé EN JEU, par les vraies routes (`GET /menu`, `POST /choix`) sur une quête minimale :
 * une grande salle, des figures posées à la main. Les preuves échouent sans le correctif : le commentaire dit ce que
 * l'ancien code aurait fait (un dormant qui jouait, une statue frappable, un Mind qui ne bougeait pas).
 *
 * Les helpers portent un préfixe `kel` : ils ne partagent aucun nom avec les autres fichiers de test.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : celui-ci dérive un parchemin par sort.
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/**
 * Une quête minimale. Sans `$salles`, UNE salle 16×9 couvre tout ; avec, les rectangles donnés et le reste est couloir.
 *
 * @param  list<array{x: int, y: int}>  $murs
 * @param  list<array<string, int|string>>|null  $salles
 * @param  array<string, array{0: int, 1: int}>  $positions
 * @return array{alice: JoueurAuthentifiable, groupe: App\Models\Groupe, quete: Quete, albrecht: Personnage, bertrand: ?Personnage}
 */
function kelScene(int $nbHeros = 1, array $herosAttrs = [], array $murs = [], ?array $salles = null, array $positions = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);
    $bertrand = null;
    $bob = null;

    if ($nbHeros > 1) {
        $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
        $bertrand = creerHeros($bob, $groupe, 'Bertrand', 2);
    }

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — passifs de Kellar\'s Keep',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $cases = array_fill(0, 9, array_fill(0, 16, 's'));

    foreach ($murs as $m) {
        $cases[$m['y']][$m['x']] = 'm';
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => $cases,
            'salles' => $salles ?? [['x' => 0, 'y' => 0, 'largeur' => 16, 'hauteur' => 9,
                'theme' => 'generique', 'mediane_x' => 8, 'mediane_y' => 4]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => [],
            'spawn_heros' => [['x' => 1, 'y' => 4]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $albrecht->id,
        'position_x' => $positions['albrecht'][0] ?? 1, 'position_y' => $positions['albrecht'][1] ?? 4,
    ]);

    if ($bertrand !== null) {
        EtatPersonnageQuete::create([
            'quete_id' => $quete->id, 'personnage_id' => $bertrand->id,
            'position_x' => $positions['bertrand'][0] ?? 1, 'position_y' => $positions['bertrand'][1] ?? 5,
        ]);
    }

    return ['alice' => $alice, 'bob' => $bob ?? null, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'),
        'albrecht' => $albrecht, 'bertrand' => $bertrand];
}

/** Pose une figure du catalogue, révélée, ses usages de Dread armés comme en jeu. */
function kelPoser(array $ctx, string $nom, int $x, int $y, array $extra = []): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nom)->firstOrFail();

    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => $x, 'position_y' => $y, 'etat' => 'actif', 'revele' => true,
        ...$extra,
    ]);
    $instance->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $ctx['quete']);

    return $instance->fresh()->load('monstre');
}

/** La phase des monstres, déclenchée par la fin du tour du héros d'Alice. Rend la réponse entière. */
function kelAttendre(array $ctx, array $des = []): array
{
    // Des blancs derrière les dés imposés : ni crâne ni bouclier noir, rien ne tombe à l'improviste.
    desFiges([...$des, ...array_fill(0, 300, 4)]);

    // Chaque héros finit son tour ; la phase des monstres ne se joue qu'à la fin du DERNIER.
    $reponse = [];

    foreach (array_filter([$ctx['alice'], $ctx['bob'] ?? null]) as $joueur) {
        test()->actingAs($joueur, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();
        $reponse = test()->actingAs($joueur, 'joueur')
            ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
            ->assertStatus(202)
            ->json();
    }

    return $reponse;
}

/** Les actions de la phase des monstres, à plat. */
function kelActions(array $reponse): array
{
    return collect($reponse['resultat']['tour_monstres']['actions'] ?? [])
        ->flatMap(fn ($a) => ($a['type'] ?? null) === 'actions_composites' ? $a['actions'] : [$a])
        ->values()->all();
}

/** Les actions de CETTE figure (par son nom affiché), à plat. */
function kelActionsDe(array $reponse, string $nom): array
{
    return collect(kelActions($reponse))->filter(fn ($a) => ($a['monstre'] ?? null) === $nom)->values()->all();
}

/** Les ids des figures que le menu OFFRE à l'action « attaquer » (liste directe, ou ventilée par arme). */
function kelCiblesAttaque(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['albrecht']->id);

    $menu = test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu') ?? [];

    return collect($menu['options'] ?? [])->where('id', 'attaquer')
        ->flatMap(function (array $option): array {
            $parametres = (array) ($option['parametres'] ?? []);
            $cibles = (array) ($parametres['cibles'] ?? []);

            foreach ((array) ($parametres['armes'] ?? []) as $arme) {
                $cibles = [...$cibles, ...(array) ($arme['cibles'] ?? [])];
            }

            return array_map(fn (array $c) => (int) $c['id'], $cibles);
        })
        ->values()->all();
}

/** Tente une frappe de l'unique héros sur une figure, par la vraie route. */
function kelFrapper(array $ctx, InstanceMonstre $cible): \Illuminate\Testing\TestResponse
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['albrecht']->id);

    return test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $cible->id],
    ]);
}

// =====================================================================
// 1. LE SQUELETTE-ROI NAIN — « do not move or attack until one of the skeletons has been attacked »
// =====================================================================

it('DORT : un squelette-roi sans attaque subie ne bouge pas au tour du MJ, et reste endormi', function () {
    $ctx = kelScene();
    $roi = kelPoser($ctx, 'Squelette-roi nain', 10, 4);

    $reponse = kelAttendre($ctx);

    // Un monstre ordinaire aurait marché vers Albrecht (Move 6) : celui-ci ne fait rien.
    expect($roi->fresh()->position_x)->toBe(10)
        ->and($roi->fresh()->position_y)->toBe(4)
        ->and(kelActionsDe($reponse, 'Squelette-roi nain'))->toBe([])
        ->and($roi->fresh()->passif_etat)->toBeNull();
});

it('ÉVEILLE : frapper un squelette-roi réveille toute sa salle, qui joue au TOUR SUIVANT du MJ', function () {
    $ctx = kelScene(positions: ['albrecht' => [4, 3]]);
    $frappe = kelPoser($ctx, 'Squelette-roi nain', 5, 3);
    $second = kelPoser($ctx, 'Squelette-roi nain', 9, 5);

    // Le héros frappe le premier : des blancs partout, aucun dégât — seul compte l'éveil.
    $frapper = kelFrapper($ctx, $frappe);
    $frapper->assertStatus(202);

    // L'éveil est écrit en colonne, pour les deux figures de la salle.
    expect($frappe->fresh()->passif_etat['eveille'] ?? false)->toBeTrue()
        ->and($second->fresh()->passif_etat['eveille'] ?? false)->toBeTrue();

    // Au tour du MJ, le second, qui n'a jamais été frappé, marche vers le héros.
    $reponse = kelAttendre($ctx);

    expect($second->fresh()->position_x)->not->toBe(9)
        ->and(kelActionsDe($reponse, 'Squelette-roi nain'))->not->toBe([]);
});

it('ANNONCE : l\'éveil par attaque est dit au fil, avec sa cause', function () {
    $ctx = kelScene(positions: ['albrecht' => [4, 3]]);
    $frappe = kelPoser($ctx, 'Squelette-roi nain', 5, 3);

    $reponse = kelFrapper($ctx, $frappe)->assertStatus(202)->json();

    // Dans le RÉSULTAT (le fil en direct se bâtit dessus), et dans le journal, avec sa cause.
    $rendus = collect($reponse['resultat']['annonces_automatiques'] ?? [])->where('type', 'monstre_s_eveille');

    $fils = Evenement::where('groupe_id', $ctx['groupe']->id)->get()->pluck('payload')
        ->filter(fn ($p) => ($p['type'] ?? null) === 'monstre_s_eveille');

    expect($rendus)->not->toBeEmpty()
        ->and($rendus->first()['cause'])->toBe('attaque')
        ->and($fils)->not->toBeEmpty()
        ->and($fils->first()['cause'])->toBe('attaque');
});

// =====================================================================
// 2. LA GARGOUILLE-STATUE — « cannot be harmed until it has either moved or attacked a hero »
// =====================================================================

it('INTOUCHABLE : la statue n\'est ni offerte au menu d\'attaque, ni frappable — et elle dort au tour du MJ', function () {
    $ctx = kelScene(positions: ['albrecht' => [4, 4]]);
    $statue = kelPoser($ctx, 'Gargouille-statue', 5, 4);

    expect(kelCiblesAttaque($ctx))->not->toContain($statue->id);

    // Même forcée par la route : le choke-point `frapper()` refuse.
    kelFrapper($ctx, $statue)->assertStatus(422);

    $reponse = kelAttendre($ctx);

    expect($statue->fresh()->position_x)->toBe(5)
        ->and(kelActionsDe($reponse, 'Gargouille-statue'))->toBe([])
        ->and($statue->fresh()->etat)->toBe('actif');
});

it('ÉVEILLE PAR LA FOUILLE : chercher le trésor de sa salle réveille la statue, qui joue au tour suivant — puis devient frappable', function () {
    $ctx = kelScene(positions: ['albrecht' => [4, 4]]);
    $statue = kelPoser($ctx, 'Gargouille-statue', 5, 4);

    // Le héros fouille le trésor de la salle (route réelle), ce qui réveille la statue…
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['albrecht']->id);
    $fouille = test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json();

    // L'éveil est RENDU dans le résultat de la fouille (le fil en direct), cause « fouille ».
    expect(collect($fouille['resultat']['annonces_automatiques'] ?? [])->where('type', 'monstre_s_eveille')->pluck('cause')->all())
        ->toBe(['fouille']);

    expect($statue->fresh()->passif_etat['eveille'] ?? false)->toBeTrue();

    // …et au tour du MJ elle agit : elle marche (ou frappe). Son premier geste lève l'intouchabilité : le menu la
    // redevient une cible, comme le dit le livret (« cannot be harmed until it has either moved or attacked »).
    $reponse = kelAttendre($ctx);

    expect(kelActionsDe($reponse, 'Gargouille-statue'))->not->toBe([])
        ->and($statue->fresh()->passif_etat['agi'] ?? false)->toBeTrue()
        ->and(kelCiblesAttaque($ctx))->toContain($statue->id);
});

// =====================================================================
// 3. BELLTHOR — « does not move or attack on its turn until all of the heroes are in the room together »
// =====================================================================

it('BELLTHOR : il ne joue pas tant qu\'un héros est hors de sa salle, et s\'éveille au tour du MJ où le groupe est réuni', function () {
    // Deux salles séparées par un couloir (x = 8). Bertrand est dans le couloir, Albrecht dans la salle de Bellthor.
    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 8, 'hauteur' => 9, 'theme' => 'generique', 'mediane_x' => 4, 'mediane_y' => 4],
        ['x' => 9, 'y' => 0, 'largeur' => 7, 'hauteur' => 9, 'theme' => 'generique', 'mediane_x' => 12, 'mediane_y' => 4],
    ];
    $ctx = kelScene(2, salles: $salles, positions: ['albrecht' => [1, 4], 'bertrand' => [8, 4]]);
    $bellthor = kelPoser($ctx, 'Bellthor', 6, 4);

    $reponse = kelAttendre($ctx);

    expect($bellthor->fresh()->position_x)->toBe(6)
        ->and(kelActionsDe($reponse, 'Bellthor'))->toBe([]);

    // Bertrand rejoint la salle : au tour suivant du MJ, Bellthor s'éveille et agit ce tour-ci.
    EtatPersonnageQuete::where('personnage_id', $ctx['bertrand']->id)->update(['position_x' => 5, 'position_y' => 5]);

    $reponse = kelAttendre($ctx);

    expect($bellthor->fresh()->passif_etat['eveille'] ?? false)->toBeTrue()
        ->and(kelActionsDe($reponse, 'Bellthor'))->not->toBe([]);
});

it('LE CHOKE-POINT : `frapper()` refuse une figure intouchable MÊME hors menu (balayage, technique : pas de liste blanche)', function () {
    $ctx = kelScene(positions: ['albrecht' => [4, 4]]);
    $statue = kelPoser($ctx, 'Gargouille-statue', 5, 4);
    $etat = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $ctx['albrecht']->id)->firstOrFail();

    // Aucun menu n'est consulté : la frappe est appelée directement, comme le ferait une autre technique de combat.
    expect(fn () => app(App\Partie\ResolveurTour::class)->frapper($ctx['groupe']->fresh(), $ctx['quete']->fresh(), $etat, $ctx['albrecht']->fresh(), $statue->fresh()))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'ne peut pas être blessé');

    expect($statue->fresh()->pv_body)->toBe($statue->pv_body);
});

it('BELLTHOR : intouchable jusqu\'à son premier coup — la frappe est refusée tant qu\'il n\'a pas attaqué', function () {
    $ctx = kelScene(positions: ['albrecht' => [4, 4]]);
    $bellthor = kelPoser($ctx, 'Bellthor', 5, 4, ['passif_etat' => ['eveille' => true, 'agi' => false, 'attaque' => false]]);

    expect(kelCiblesAttaque($ctx))->not->toContain($bellthor->id);
    kelFrapper($ctx, $bellthor)->assertStatus(422);
});

it('LE SOUFFLE : un Bellthor éveillé qui vient de frapper souffle aussitôt — six dés, un seul geste après le coup', function () {
    $ctx = kelScene(positions: ['albrecht' => [4, 4]]);
    kelPoser($ctx, 'Bellthor', 5, 4, ['passif_etat' => ['eveille' => true, 'agi' => false, 'attaque' => false]]);

    // Tout blanc : ni coup au corps ni crâne au souffle — seule compte la présence de l'attaque de l'esprit, à six dés.
    $reponse = kelAttendre($ctx);

    $souffle = collect(kelActionsDe($reponse, 'Bellthor'))->firstWhere('type', 'attaque_mind_monstre');

    expect($souffle)->not->toBeNull()
        ->and(count($souffle['des']))->toBe(6)
        ->and($souffle['perdus'])->toBe(0);
});

it('LE SOUFFLE : Bellthor n\'expire pas de Mind sans attaque physique du tour — le souffle vient APRÈS son coup', function () {
    $ctx = kelScene(positions: ['albrecht' => [1, 4]]);
    // Éveillé, mais loin du héros : il marche sans frapper, donc pas de souffle.
    $bellthor = kelPoser($ctx, 'Bellthor', 9, 4, ['passif_etat' => ['eveille' => true, 'agi' => false, 'attaque' => false]]);

    $reponse = kelAttendre($ctx, array_fill(0, 6, 1));

    expect(collect(kelActionsDe($reponse, 'Bellthor'))->pluck('type')->all())->not->toContain('attaque_mind_monstre')
        ->and($ctx['albrecht']->fresh()->pv_mind)->toBe($ctx['albrecht']->pv_mind);
});

// =====================================================================
// 4. BOROKK — « On each of his turns, he attacks the mind of any hero in the same room » (2 dés de combat)
// =====================================================================

it('ÉVEIL SANS FAUX FIL : un squelette déjà éveillé, attaqué HORS salle, n\'annonce rien de nouveau', function () {
    // Une salle 0..7 ; le reste est couloir. Le squelette a déjà joué : éveillé, il a marché jusque dans le couloir.
    $salles = [['x' => 0, 'y' => 0, 'largeur' => 8, 'hauteur' => 9, 'theme' => 'generique', 'mediane_x' => 4, 'mediane_y' => 4]];
    $ctx = kelScene(salles: $salles, positions: ['albrecht' => [9, 4]]);
    $roi = kelPoser($ctx, 'Squelette-roi nain', 10, 4, ['passif_etat' => ['eveille' => true, 'agi' => true, 'attaque' => false]]);

    $reponse = kelFrapper($ctx, $roi)->assertStatus(202)->json();

    // Sans le filtre, l'attaque du couloir réannonçait « s'éveille » pour une figure déjà éveillée.
    expect(collect($reponse['resultat']['annonces_automatiques'] ?? [])->where('type', 'monstre_s_eveille'))->toBeEmpty();
});

it('ESPRIT : deux crânes sur deux dés = deux Mind perdus, dits au fil et dans la réponse', function () {
    $ctx = kelScene();
    $ctx['albrecht']->update(['pv_mind' => 6]);
    kelPoser($ctx, 'Borokk', 12, 4);

    // Borokk est loin : aucune frappe de corps. Un dé blanc en tête (tirage existant de la phase, non changé ici), puis les deux
    // dés de son esprit : deux crânes.
    $reponse = kelAttendre($ctx, [4, 1, 1]);

    $mind = collect(kelActionsDe($reponse, 'Borokk'))->firstWhere('type', 'attaque_mind_monstre');

    expect($mind)->not->toBeNull()
        ->and($mind['cranes'])->toBe(2)
        ->and($mind['perdus'])->toBe(2)
        ->and($ctx['albrecht']->fresh()->pv_mind)->toBe(4)
        ->and($ctx['albrecht']->fresh()->pv_body)->toBe($ctx['albrecht']->pv_body);
});

it('CHOC : à 0 Mind le héros entre en état de choc — il n\'est pas mort', function () {
    $ctx = kelScene();
    $ctx['albrecht']->update(['pv_mind' => 2]);
    kelPoser($ctx, 'Borokk', 12, 4);

    // Un dé est tiré en tête de la phase des monstres, avant l'esprit (tirage existant, non changé ici) : le blanc le absorbe.
    $reponse = kelAttendre($ctx, [4, 1, 1]);

    $mind = collect(kelActionsDe($reponse, 'Borokk'))->firstWhere('type', 'attaque_mind_monstre');

    expect($ctx['albrecht']->fresh()->pv_mind)->toBe(0)
        ->and($mind['choc'])->toBeTrue()
        ->and($ctx['albrecht']->fresh()->pv_body)->toBeGreaterThan(0);
});

// =====================================================================
// 5. GRAGOR — cinq sorts déclarés ; le palier sous-boss n'en lance que trois (écrit dans l'archétype)
// =====================================================================

it('GRAGOR : son répertoire porte les cinq sorts du livret, et seuls ceux de palier sous-boss ou base sont lancés', function () {
    $ctx = kelScene(positions: ['albrecht' => [4, 4]]);
    $gragor = kelPoser($ctx, 'Gragor', 9, 4);

    expect(app(MoteurDread::class)->repertoireDe($gragor))
        ->toEqualCanonicalizing(["Invocation d'orques", 'Frayeur', 'Rouille', 'Boule de Flammes', 'Éclair de Chaos']);

    // Gragor (sous-boss) joue sa phase : aucun des deux sorts de palier BOSS ne sort jamais.
    $lances = [];

    for ($tour = 0; $tour < 3; $tour++) {
        // Un sort de Dread porte son NOM DE SORT (`sort`), non l'attaquant : on lit toute la phase, Gragor étant seul.
        $reponse = kelAttendre($ctx, [4, 4, 1, 1]);
        $lances = [...$lances, ...collect(kelActions($reponse))->pluck('sort')->filter()->all()];
    }

    // Non vide : il lance bien ses sorts (sinon ce test ne prouverait rien du palier).
    expect($lances)->not->toBeEmpty()
        ->and($lances)->not->toContain("Invocation d'orques")
        ->and($lances)->not->toContain('Rouille');

    // Le FILTRE PAR PALIER, lu directement : un sous-boss ne dispose ni d'Invocation d'orques ni de Rouille (boss).
    $filtre = new ReflectionMethod(MoteurDread::class, 'sortsDisponibles');
    $disponibles = collect($filtre->invoke(app(MoteurDread::class), $gragor->fresh()->load('monstre'), $ctx['quete']->fresh()))
        ->map(fn ($sort) => is_object($sort) ? $sort->nom : (string) $sort)->all();

    expect($disponibles)->toContain('Frayeur', 'Boule de Flammes', 'Éclair de Chaos')
        ->and($disponibles)->not->toContain("Invocation d'orques", 'Rouille');
});

// =====================================================================
// 6. LA DURABILITÉ — un dormant éveillé le reste à la reprise (colonne, jamais le cache)
// =====================================================================

it('REPRISE : un squelette-roi éveillé reste éveillé dans un snapshot restauré', function () {
    $ctx = kelScene(positions: ['albrecht' => [4, 3]]);
    $frappe = kelPoser($ctx, 'Squelette-roi nain', 5, 3);
    kelFrapper($ctx, $frappe)->assertStatus(202);

    $sauvegarde = app(Sauvegarde::class);
    $snapshot = $sauvegarde->snapshotter($ctx['groupe']->fresh(), 'kellars-passif');

    // La partie continue : le squelette est remis à neuf en base (éveil effacé), puis on restaure le snapshot.
    InstanceMonstre::where('quete_id', $ctx['quete']->id)->update(['passif_etat' => null]);
    $sauvegarde->restaurer($ctx['groupe']->fresh(), $snapshot);

    $restaure = InstanceMonstre::where('quete_id', $ctx['quete']->fresh()->id)
        ->whereHas('monstre', fn ($q) => $q->where('nom_base', 'Squelette-roi nain'))->first();

    expect($restaure?->passif_etat['eveille'] ?? false)->toBeTrue();
});

// =====================================================================
// 7. LE PLACEMENT — un dormant se pose EN SALLE (sans salle : ni porte, ni fouille, ni groupe à réunir)
// =====================================================================

it('PLACEMENT : une figure à passif échange sa case de couloir contre une case de salle libre — jamais un doublon', function () {
    $statue = Monstre::where('nom_base', 'Gargouille-statue')->firstOrFail();
    $gobelin = Monstre::where('nom_base', 'Gobelin')->firstOrFail();

    // Deux cases de couloir (x = 10 hors de la salle 0..7) puis une case de salle, pour la statue et le gobelin.
    $carte = [
        'salles' => [['x' => 0, 'y' => 0, 'largeur' => 8, 'hauteur' => 9]],
        'spawn_monstres' => [['x' => 10, 'y' => 1], ['x' => 9, 'y' => 2], ['x' => 3, 'y' => 3]],
        'spawn_figures_fixes' => [],
    ];

    $placees = new ReflectionMethod(DemarreurQuete::class, 'positionsDesMonstres');
    $positions = $placees->invoke(app(DemarreurQuete::class), [$statue, $gobelin, $gobelin], $carte);

    // La statue (première) se trouve désormais dans la salle ; aucune case n'est attribuée deux fois.
    expect(Salles::indexDe($carte['salles'], (int) $positions[0]['x'], (int) $positions[0]['y']))->toBe(0)
        ->and(collect($positions)->map(fn ($p) => "{$p['x']},{$p['y']}")->unique()->count())->toBe(3);
});
