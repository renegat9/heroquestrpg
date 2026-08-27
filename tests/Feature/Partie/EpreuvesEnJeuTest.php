<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Epreuve;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\Marche\CapaciteSac;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * LA PREUVE EN JEU DES ÉPREUVES — même statut que `TalentsEnJeuTest` : le
 * catalogue (`EpreuveSeeder`) et le registre (`MotsClesEpreuve`,
 * `EpreuvesCatalogueTest`) peuvent être irréprochables sur le papier et ne
 * rien faire une fois la carte assemblée. Ce fichier joue chaque mécanique
 * par les VRAIES routes (`POST /api/groupes/table-1/choix`), jamais en
 * appelant `ResolveurTour::resoudreEpreuve()` à la main.
 *
 * ⚠ LE POINT DE TOUT LE CHANTIER, et il faut le dire tel quel : avant les
 * épreuves, le moteur n'émettait qu'UN SEUL jet hors combat — « Fouiller la
 * zone », toujours de contexte `perception`. Six nœuds de la grille de
 * talents (`Intimidation`, `Érudition`, `Prestance`, `Beau parleur`,
 * `Méditation`, `Cartographe`) promettent un dé de Mind supplémentaire sur
 * les contextes `savoir` et `social_peur` — des contextes qu'aucun jet ne
 * proposait jamais. Ces nœuds étaient donc de la pure décoration : achetables,
 * jamais lus. Les tests « LA PREUVE DU CHANTIER » plus bas sont ceux qui
 * ferment ce trou, pour un talent de chaque contexte.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    // ⚠ ORDRE : SortSeeder AVANT ObjetSeeder — les parchemins sont générés
    // depuis `Sort::all()` en fin d'ObjetSeeder (commentaire du seeder
    // lui-même). Sans lui, la mécanique « parchemin » de l'épreuve
    // « Grimoire à demi calciné » ne trouverait aucune pièce à rendre.
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, SortSeeder::class, ObjetSeeder::class, CompetenceSeeder::class,
        ConditionSeeder::class, EpreuveSeeder::class]);
});

/**
 * Remplace la couche `epreuves` de la carte — même patron que
 * `poserPortes()`/`poserPiegesExplo()` de `PortesExplorationTest`, gardé
 * LOCAL à ce fichier (l'énoncé du lot demande un helper qui écrit
 * directement `carte.grille['epreuves']` plutôt que de dépendre du
 * placement procédural de `AssembleurCarte::placerEpreuves()`).
 *
 * @param  list<array<string, mixed>>  $entrees
 */
function poserEpreuve(Quete $quete, array $entrees): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['epreuves'] = $entrees;
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/**
 * Remplace la couche `pieges` de la carte — même patron que
 * `poserPiegesExplo()`, renommé pour ne jamais entrer en collision avec la
 * fonction homonyme de `PortesExplorationTest` (les deux fichiers cohabitent
 * dans le même process Pest).
 *
 * @param  list<array{x: int, y: int, nom: string, etat: string}>  $entrees
 */
function poserPiegesPourEpreuve(Quete $quete, array $entrees): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = array_map(fn (array $e) => [
        'x' => $e['x'], 'y' => $e['y'],
        'piege_id' => Piege::where('nom', $e['nom'])->value('id'),
        'etat' => $e['etat'],
    ], $entrees);
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/**
 * Index de la salle (carte.grille.salles) contenant (x, y) — même calcul que
 * la méthode privée `AssembleurCarte::salleDe()`, en lecture seule, pour
 * poser une épreuve/un piège dans la bonne salle depuis un test.
 */
function salleIndexDe(Quete $quete, int $x, int $y): int
{
    foreach ($quete->carte->grille['salles'] as $i => $salle) {
        if ($x >= $salle['x'] && $x < $salle['x'] + $salle['largeur']
            && $y >= $salle['y'] && $y < $salle['y'] + $salle['hauteur']) {
            return (int) $i;
        }
    }

    throw new RuntimeException("Aucune salle ne contient ({$x}, {$y}).");
}

/**
 * Démarre une quête RÉELLE (carte procédurale, route `/quetes`) avec DEUX
 * héros — Albrecht qui agit, Brunhilde qui ne joue jamais dans ce fichier
 * mais tient le groupe en vie et sert de second angle pour « une tentative
 * PAR héros » — puis pose l'épreuve nommée `$nomEpreuve` EXACTEMENT sur la
 * case de spawn d'Albrecht : aucune adjacence à calculer, le jet est
 * immédiatement disponible sans dépenser le créneau de déplacement.
 *
 * On ne teste PAS ici le placement procédural (`AssembleurCarte::placerEpreuves()`
 * a son propre test, ailleurs) : seul le RÉSULTAT une fois posée nous
 * intéresse.
 *
 * @return array{alice: JoueurAuthentifiable, groupe: Groupe, hero: Personnage,
 *     quete: Quete, etat: EtatPersonnageQuete, bob: JoueurAuthentifiable,
 *     heroBob: Personnage, etatBob: EtatPersonnageQuete}
 */
function demarrerAvecEpreuve(string $nomEpreuve, array $herosAttrs = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroBob = creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $etatBob = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroBob->id)->firstOrFail();

    $epreuve = Epreuve::where('nom', $nomEpreuve)->firstOrFail();
    poserEpreuve($quete, [[
        'x' => (int) $etat->position_x,
        'y' => (int) $etat->position_y,
        'epreuve_id' => $epreuve->id,
        'salle' => salleIndexDe($quete, (int) $etat->position_x, (int) $etat->position_y),
        'tentee_par' => [],
    ]]);

    return compact('alice', 'groupe', 'hero', 'quete', 'etat', 'bob', 'heroBob', 'etatBob');
}

// =====================================================================
// UN CAS PAR EFFET
// =====================================================================

it('mécanique « or » : la bourse commune du groupe monte de la valeur EXACTE de l\'épreuve', function () {
    $ctx = demarrerAvecEpreuve('Dalle descellée'); // Body, difficulté 2, effet {or, 100}
    $avant = (int) $ctx['groupe']->fresh()->or;

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);
    // 4 dés de Body (attribut_body par défaut) : 2 crânes → réussite (difficulté 2).
    desFiges([1, 1, 4, 4]);

    $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('reussite')
        ->and($resultat['or'])->toBe(100);

    expect((int) $ctx['groupe']->fresh()->or)->toBe($avant + 100);
});

it('mécanique « objet » : une ligne d\'inventaire apparaît chez le héros qui a réussi', function () {
    $ctx = demarrerAvecEpreuve('Mécanisme gripé'); // Body, difficulté 3, effet {objet}
    $avant = Inventaire::where('personnage_id', $ctx['hero']->id)->count();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);
    // 4 dés de Body : 3 crânes → réussite (difficulté 3).
    desFiges([1, 1, 1, 4]);

    $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('reussite')
        ->and($resultat['objet']['nom'])->not->toBe('')
        ->and($resultat['objet']['categorie'])->toBe('consommable');

    expect(Inventaire::where('personnage_id', $ctx['hero']->id)->count())->toBe($avant + 1);
});

it('mécanique « parchemin » : rend une pièce de catégorie parchemin, jamais une consommable ordinaire', function () {
    $ctx = demarrerAvecEpreuve('Grimoire à demi calciné'); // Mind, difficulté 2, contexte savoir, effet {parchemin}

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);
    // 2 dés de Mind (attribut_mind par défaut, aucun talent) : 2 crânes → réussite.
    desFiges([1, 1]);

    $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('reussite')
        ->and($resultat['objet']['categorie'])->toBe('parchemin');

    expect(Inventaire::where('personnage_id', $ctx['hero']->id)
        ->whereHas('objet', fn ($q) => $q->where('categorie', 'parchemin'))->exists())->toBeTrue();
});

it('mécanique « soin_groupe » : TOUS les héros de la quête regagnent des PV, et un héros TOMBÉ se relève', function () {
    // Mind 4 pour Albrecht (le défaut, 2, ne suffirait pas à la difficulté 3
    // de « Crâne accusateur » — ce n'est pas ce qu'on teste ici).
    $ctx = demarrerAvecEpreuve('Crâne accusateur', ['attribut_mind' => 4]);

    // ⚠ Le pv_body passé à `creerHeros()` ne survit PAS au démarrage de
    // quête : `DemarreurQuete` remet chaque héros à son plein PV d'entrée en
    // donjon (comme au plateau). Il faut donc abîmer les héros APRÈS le
    // `postJson('/quetes')` de `demarrerAvecEpreuve()`, pas avant — sans ça
    // Albrecht est déjà au max et sa « guérison » de +2 ne rend rien
    // (`rendus === 0`), absent de `soin_groupe` par construction.
    $ctx['hero']->update(['pv_body' => 5]);
    // Brunhilde est TOMBÉE, à 0 PV — le soin de groupe doit la relever.
    $ctx['heroBob']->update(['pv_body' => 0]);
    $ctx['etatBob']->update(['tombe' => true]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);
    // 4 dés de Mind : 3 crânes → réussite (difficulté 3).
    desFiges([1, 1, 1, 4]);

    $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('reussite');

    $soins = collect($resultat['soin_groupe']);
    expect($soins->firstWhere('personnage_id', $ctx['hero']->id)['soin_pv_body'])->toBe(2)
        ->and($soins->firstWhere('personnage_id', $ctx['heroBob']->id)['soin_pv_body'])->toBe(2);

    expect((int) $ctx['hero']->fresh()->pv_body)->toBe(7)   // 5 + 2
        ->and((int) $ctx['heroBob']->fresh()->pv_body)->toBe(2) // 0 + 2
        ->and($ctx['etatBob']->fresh()->tombe)->toBeFalse();     // relevé
});

it('mécanique « retire_condition » : purge les conditions à DURÉE du héros qui a réussi', function () {
    $ctx = demarrerAvecEpreuve('Inscription menaçante'); // Mind, difficulté 2, contexte social_peur

    // Empoisonné (duree_defaut 3 > 0) : c'est une condition « à durée », donc
    // une cible légitime. On l'attache directement au pivot, sans passer par
    // un combat — seul l'effet de l'épreuve nous intéresse ici.
    $empoisonne = Condition::where('nom', 'Empoisonné')->firstOrFail();
    $ctx['hero']->conditions()->attach($empoisonne->id, ['duree' => 3, 'source' => 'test']);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);
    // 2 dés de Mind (attribut_mind par défaut) : 2 crânes → réussite (difficulté 2).
    desFiges([1, 1]);

    $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('reussite')
        ->and($resultat['retire_condition'])->toBe(['Empoisonné']);

    expect($ctx['hero']->conditions()->wherePivot('duree', '>', 0)->count())->toBe(0);
});

it('mécanique « desarme_pieges_salle » : désarme TOUS les pièges de la salle, ceux encore cachés compris', function () {
    $ctx = demarrerAvecEpreuve('Autel fêlé'); // Mind, difficulté 2, contexte perception
    $quete = $ctx['quete'];

    $salleIndex = (int) $quete->fresh()->carte->grille['epreuves'][0]['salle'];
    $salle = $quete->carte->grille['salles'][$salleIndex];

    // Deux coins de LA MÊME salle que l'ancrage : un piège encore CACHÉ
    // (jamais détecté par le groupe), un déjà DÉTECTÉ. « Tous les pièges
    // ENCORE ACTIFS » doit couvrir les deux états, pas seulement celui qu'on
    // voit déjà sur la carte.
    poserPiegesPourEpreuve($quete, [
        ['x' => (int) $salle['x'], 'y' => (int) $salle['y'], 'nom' => 'Fosse', 'etat' => 'cache'],
        [
            'x' => (int) $salle['x'] + max(0, (int) $salle['largeur'] - 1),
            'y' => (int) $salle['y'] + max(0, (int) $salle['hauteur'] - 1),
            'nom' => 'Piège à lances', 'etat' => 'detecte',
        ],
    ]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);
    // 2 dés de Mind (attribut_mind par défaut) : 2 crânes → réussite (difficulté 2).
    desFiges([1, 1]);

    $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('reussite')
        ->and($resultat['desarme_pieges_salle'])->toHaveCount(2);

    $pieges = $quete->fresh()->carte->grille['pieges'];
    expect($pieges[0]['etat'])->toBe('desarme')
        ->and($pieges[1]['etat'])->toBe('desarme');
});

// =====================================================================
// INVARIANTS
// =====================================================================

it('une tentative par héros : l\'option disparaît pour qui a tenté, reste offerte à un autre', function () {
    $ctx = demarrerAvecEpreuve('Dalle descellée');

    // Brunhilde se tient AUSSI sur l'ancrage : sans ça, l'épreuve ne lui
    // serait même pas adjacente et le test ne prouverait qu'une question de
    // distance, pas la règle « un essai par héros ».
    $ctx['etatBob']->update(['position_x' => $ctx['etat']->position_x, 'position_y' => $ctx['etat']->position_y]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);

    // Avant toute tentative, l'ancrage est bien dans le menu d'Albrecht — la
    // prémisse du test, pas seulement sa conclusion.
    $avant = collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'])
        ->pluck('id');
    expect($avant->contains('epreuve_0'))->toBeTrue();

    // Un échec compte AUTANT qu'une réussite (MoteurEpreuves::dejaTentee()) :
    // 0 crâne sur les 4 dés de Body.
    desFiges([4, 4, 4, 4]);

    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'echec');

    // Le menu d'Albrecht, régénéré après son choix, ne propose plus l'épreuve…
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);
    $optionsAlbrecht = collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'])
        ->pluck('id');
    expect($optionsAlbrecht->contains('epreuve_0'))->toBeFalse();

    // … mais Brunhilde, qui ne l'a jamais tentée, la voit toujours : seul le
    // CRÉNEAU d'Albrecht est dépensé, pas l'ancrage lui-même.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['bob']->id, (int) $ctx['heroBob']->id);
    $optionsBrunhilde = collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['bob']->id))['menu']['options'])
        ->pluck('id');
    expect($optionsBrunhilde->contains('epreuve_0'))->toBeTrue();
});

it('l\'échec ne donne RIEN mais consomme quand même le créneau d\'ACTION', function () {
    $ctx = demarrerAvecEpreuve('Dalle descellée');

    $orAvant = (int) $ctx['groupe']->fresh()->or;

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);
    // 0 crâne sur 4 dés de Body → échec sec (loin de la difficulté 2).
    desFiges([4, 4, 4, 4]);

    $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('echec')
        // resoudreEpreuve() n'est appelé QUE sur réussite : aucune clé d'effet
        // ne doit apparaître dans le payload d'un jet raté.
        ->and($resultat)->not->toHaveKey('or');

    expect((int) $ctx['groupe']->fresh()->or)->toBe($orAvant);

    // C'est le CRÉNEAU d'action qui fait le prix réel de l'épreuve, pas son
    // succès : le jet type « jet » retombe sur le créneau par défaut
    // (`ResolveurTour::creneauOption()`), donc `a_agi` doit être vrai même
    // sur un échec.
    expect($ctx['etat']->fresh()->a_agi)->toBeTrue();
});

it(
    'sac plein : l\'objet trouvé n\'est jamais perdu — mais « objet »/« parchemin » ne peuvent en fait JAMAIS déborder',
    function () {
        // ⚠ ÉCART CONSTATÉ AVEC LE PLAN DU LOT — signalé ici ET dans le rapport
        // de la tâche : le plan attend un cas « sac plein → sac_deborde: true ».
        // En pratique, `ResolveurTour::epreuveObjet()` ne tire JAMAIS que dans
        // les catégories `consommable` (mécanique « objet ») ou `parchemin`
        // (mécanique « parchemin ») — et CHAQUE ligne de ces deux catégories
        // porte `emplacement = 'consommable'` dans `ObjetSeeder`.
        // `RangementObjet::peutRanger()` rend TOUJOURS `true` pour un objet dont
        // l'`emplacement` catalogue est `consommable` (doc 01 §7 : « les
        // consommables […] sont illimités ») — le sac est plein ou vide, ça ne
        // change rien pour ces deux mécaniques. `sac_deborde` n'est donc
        // JAMAIS atteignable par une épreuve telle qu'elle est câblée
        // aujourd'hui, contrairement au coffre d'artefact (qui peut remettre
        // une ARME, elle bien comptée au sac). Ce test prouve donc la moitié
        // vraie de l'invariant — l'objet n'est jamais perdu — et documente
        // pourquoi l'autre moitié ne peut pas être exercée sans toucher au
        // moteur (hors périmètre de ce lot de tests).
        $ctx = demarrerAvecEpreuve('Mécanisme gripé'); // Body, difficulté 3, effet {objet}

        // On remplit RÉELLEMENT le sac (des armes, comptées à l'emplacement
        // « sac ») jusqu'à sa capacité — pour prouver que même sac plein,
        // « objet » ne bronche pas.
        $dague = Objet::where('nom', 'Dague')->firstOrFail();
        $capacite = CapaciteSac::pour($ctx['hero']);
        for ($i = 0; $i < $capacite; $i++) {
            Inventaire::create(['personnage_id' => $ctx['hero']->id, 'objet_id' => $dague->id, 'emplacement' => 'sac', 'quantite' => 1]);
        }
        expect(CapaciteSac::occupation($ctx['hero']))->toBe($capacite); // sac RÉELLEMENT plein

        GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['hero']->id);
        // 4 dés de Body : 3 crânes → réussite (difficulté 3).
        desFiges([1, 1, 1, 4]);

        $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
            ->assertStatus(202)->json('resultat');

        expect($resultat['issue'])->toBe('reussite')
            // L'objet est bien remis (jamais perdu)…
            ->and($resultat['objet']['nom'])->not->toBe('')
            // … et `sac_deborde` n'apparaît pas : voir le commentaire ci-dessus.
            ->and($resultat)->not->toHaveKey('sac_deborde');

        expect(Inventaire::where('personnage_id', $ctx['hero']->id)
            ->where('objet_id', $resultat['objet']['id'])->exists())->toBeTrue();
    },
);

// =====================================================================
// LA PREUVE DU CHANTIER — les contextes `savoir` et `social_peur`
// n'avaient AUCUN producteur avant les épreuves ; six talents de la grille
// ne se déclenchaient donc JAMAIS. Un cas par contexte, chacun comparant le
// MÊME jet SANS puis AVEC le nœud, dans le MÊME groupe, sur le MÊME ancrage
// — rien d'autre que le talent ne doit varier entre les deux mesures.
// =====================================================================

it('LA PREUVE DU CHANTIER — Érudition (magicien) ajoute un dé de Mind sur un jet de contexte « savoir »; sans le nœud, aucun', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'magicien']);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2, ['classe' => 'magicien']);
    // Seule Brunhilde achète le nœud : Albrecht sert de témoin « sans ».
    donnerTalent($brunhilde, 'Érudition');

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatAlbrecht = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $albrecht->id)->firstOrFail();
    $etatBrunhilde = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $brunhilde->id)->firstOrFail();

    // « Fresque en langue morte » : Mind, difficulté 2, contexte SAVOIR.
    $fresque = Epreuve::where('nom', 'Fresque en langue morte')->firstOrFail();
    poserEpreuve($quete, [[
        'x' => (int) $etatAlbrecht->position_x, 'y' => (int) $etatAlbrecht->position_y,
        'epreuve_id' => $fresque->id, 'salle' => 0, 'tentee_par' => [],
    ]]);

    // ── SANS le nœud (Albrecht, premier dans l'ordre d'initiative) ──
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $albrecht->id);
    desFiges([1, 1]); // 2 dés (attribut_mind = 2), AUCUN bonus.

    $sansTalent = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($sansTalent['bonus_avantage_mind'])->toBe(0)
        ->and($sansTalent['des_lances'])->toBe(2);

    // Albrecht rend la main : « Terminer le tour » (créneau `tour`).
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $albrecht->id);
    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // ── AVEC le nœud (Brunhilde) : même ancrage catalogue, sur SA case ──
    // L'entrée déjà posée porte `tentee_par: []` — Brunhilde ne l'a jamais
    // tentée, donc `adjacentes()` la lui offre dès qu'elle est sur la case.
    $etatBrunhilde->update(['position_x' => $etatAlbrecht->position_x, 'position_y' => $etatAlbrecht->position_y]);

    // ⚠ Rejouer sur `test()` reste authentifié comme ALICE tant qu'on ne
    // change pas explicitement de session — sans ce ré-arment, le POST
    // suivant validerait l'option contre le DERNIER MENU D'ALICE (déjà
    // consommé) et 422 en « Option illégale ». Brunhilde appartient à Bob.
    test()->actingAs($bob, 'joueur');

    GenererMenu::dispatchSync($groupe->id, (int) $bob->id, (int) $brunhilde->id);
    desFiges([1, 1, 4]); // 2 dés + 1 (Érudition) : le 3e ne compte pas comme crâne, sans importance ici.

    $avecTalent = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($avecTalent['bonus_avantage_mind'])->toBe(1)
        ->and($avecTalent['des_lances'])->toBe(3); // attribut_mind (2) + 1 : LA preuve du chantier.
});

it('LA PREUVE DU CHANTIER — Prestance (chevalier) ajoute un dé de Mind sur un jet de contexte « social_peur »; sans le nœud, aucun', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'chevalier']);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2, ['classe' => 'chevalier']);
    donnerTalent($brunhilde, 'Prestance');

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatAlbrecht = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $albrecht->id)->firstOrFail();
    $etatBrunhilde = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $brunhilde->id)->firstOrFail();

    // « Inscription menaçante » : Mind, difficulté 2, contexte SOCIAL_PEUR.
    $inscription = Epreuve::where('nom', 'Inscription menaçante')->firstOrFail();
    poserEpreuve($quete, [[
        'x' => (int) $etatAlbrecht->position_x, 'y' => (int) $etatAlbrecht->position_y,
        'epreuve_id' => $inscription->id, 'salle' => 0, 'tentee_par' => [],
    ]]);

    // ── SANS le nœud (Albrecht) ──
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $albrecht->id);
    desFiges([1, 1]); // 2 dés (attribut_mind = 2), aucun bonus.

    $sansTalent = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($sansTalent['bonus_avantage_mind'])->toBe(0)
        ->and($sansTalent['des_lances'])->toBe(2);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $albrecht->id);
    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // ── AVEC le nœud (Brunhilde) ──
    $etatBrunhilde->update(['position_x' => $etatAlbrecht->position_x, 'position_y' => $etatAlbrecht->position_y]);

    // ⚠ Voir le commentaire jumeau du test Érudition : sans ce ré-arment de
    // session, le prochain POST reste authentifié comme Alice et 422 contre
    // son propre menu déjà consommé.
    test()->actingAs($bob, 'joueur');

    GenererMenu::dispatchSync($groupe->id, (int) $bob->id, (int) $brunhilde->id);
    desFiges([1, 1, 4]); // 2 dés + 1 (Prestance).

    $avecTalent = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($avecTalent['bonus_avantage_mind'])->toBe(1)
        ->and($avecTalent['des_lances'])->toBe(3); // attribut_mind (2) + 1.
});
