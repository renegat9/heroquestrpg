<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\MenuMoteur;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Against the Ogre Horde — lot B, les QUATRE éléments de carte sourcés du
 * livret F9528 p. 4-5 : porte de pierre, lame balançoire, fosse des
 * ténèbres, caisse de ravitaillement. `docs/plan-ogre-horde.md` §3 lot B,
 * `docs/contrat-api.md` §Pièges / §Portes & exploration / §Mobilier.
 *
 * Terrain construit À LA MAIN (même patron que `poserPieges()`/`poserPortes()`
 * de PiegesTest.php/PortesExplorationTest.php, réutilisés ici tels quels —
 * Pest charge tous les fichiers de test, les fonctions top-level sont donc
 * globales) : la résolution se teste sur un scénario contrôlé, le THÈME et le
 * PLACEMENT procédural se mesurent séparément (dernière section).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, MobilierSeeder::class, ObjetSeeder::class, CompetenceSeeder::class]);
});

/** Démarre une quête à TROIS héros (il en faut trois pour prouver qu'une lame
 *  balançoire ne touche QUE les héros de sa zone, pas un quatrième témoin). */
function demarrerQueteATroisHeros(array $attributsA = [], array $attributsB = [], array $attributsC = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $a = creerHeros($alice, $groupe, 'Albrecht', 1, $attributsA);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $b = creerHeros($bob, $groupe, 'Brunhilde', 2, $attributsB);

    $carl = JoueurAuthentifiable::create(['pseudo' => 'carl', 'identifiant' => 'carl', 'mot_de_passe' => 'secret']);
    $c = creerHeros($carl, $groupe, 'Cedric', 3, $attributsC);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $a->id)->firstOrFail();

    // $bob/$carl en fin de tuple (compatibilité des destructurations déjà
    // écrites) : nécessaires pour `test()->actingAs()` quand un scénario fait
    // agir Brunhilde ou Cedric par une REQUÊTE SÉPARÉE — `connecterJoueur()`
    // n'a été appelé que pour Alice, la session de test reste la sienne tant
    // que rien ne la change explicitement.
    return [$alice, $groupe, $a, $b, $c, $quete, $etatA, $bob, $carl];
}

/** Remplace les portes de la carte par celles du scénario — même patron que
 *  `poserPortes()` de PortesExplorationTest.php, redéfini ici en évitant toute
 *  dépendance d'ordre de chargement entre fichiers de test. */
function poserPortesOgre(Quete $quete, array $portes): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['portes'] = $portes;
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/** Pose UNE Lame balançoire (trigger + zone verticale de 3 cases) à $x,$y. */
function poserLameBalanciere(Quete $quete, int $x, int $y, string $etat = 'cache'): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = [[
        'x' => $x, 'y' => $y,
        'piege_id' => Piege::where('nom', 'Lame balançoire')->value('id'),
        'etat' => $etat,
        'zone' => [['x' => $x, 'y' => $y - 1], ['x' => $x, 'y' => $y], ['x' => $x, 'y' => $y + 1]],
    ]];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/** Pose UNE Fosse des ténèbres à $x,$y. */
function poserFosseDesTenebres(Quete $quete, int $x, int $y, string $etat = 'cache'): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = [[
        'x' => $x, 'y' => $y,
        'piege_id' => Piege::where('nom', 'Fosse des ténèbres')->value('id'),
        'etat' => $etat,
    ]];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/** Équipe l'objet nommé directement dans le slot armure (comme
 *  ArmureDePlatesPerdLeDeTest.php) — on teste les dégâts de chute, pas la
 *  maîtrise d'équipement. */
function equiperArmureOgre(Personnage $heros, string $nomObjet): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => 'armure',
        'quantite' => 1,
    ]);
}

function poserCaisseDeRavitaillement(Quete $quete, int $salle): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $caisse = Mobilier::where('nom', 'Caisse de ravitaillement')->firstOrFail();
    $grille['mobilier'] = [[
        'mobilier_id' => $caisse->id, 'x' => 0, 'y' => 0, 'l' => 1, 'h' => 1, 'salle' => $salle,
    ]];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

// ===================================================================
// PORTE DE PIERRE (livret p. 4)
// ===================================================================

/**
 * Place une porte de pierre « sur » la case du héros, côté est : exactement
 * le patron de `poserPortes()` (PortesExplorationTest.php) — ni l'une ni
 * l'autre des deux `casesPorte()` ne tombe sur un vrai mur de salle dans ce
 * terrain de test, donc `Grille::caseEmbrasure()` retombe sur son repli
 * `$b` = (x+1, y), à distance 1 du héros posté en (x, y). C'est ce choix de
 * coordonnées — jamais (hx+1, hy) — qui rend la porte RÉELLEMENT adjacente.
 */
function poserPorteDePierre(Quete $quete, int $hx, int $hy): void
{
    poserPortesOgre($quete, [[
        'x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'fermee',
        'verrou' => ['type' => 'pierre'], 'jonction' => 7,
    ]]);
}

it('offre « forcer la porte de pierre » à un héros qui lance ≥ 2 dés, jamais à un héros sous 2 dés', function () {
    [$alice, $groupe, $hero, , , $quete, $etat] = demarrerQueteATroisHeros(['des_attaque' => 2]);
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    poserPorteDePierre($quete, $hx, $hy);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids->contains(fn ($id) => str_starts_with($id, 'forcer_porte_pierre_')))->toBeTrue();

    // Sous 2 dés (le magicien, 1 dé) : l'option DISPARAÎT — « le menu n'offre
    // pas ce que le résolveur refuse ».
    $hero->update(['des_attaque' => 1]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids->contains(fn ($id) => str_starts_with($id, 'forcer_porte_pierre_')))->toBeFalse();

    // Et forcer l'option HORS menu reste illégal (le moteur fait autorité,
    // même règle que le désamorçage) : le résolveur refuse pour la même
    // raison que le menu ne l'offre pas.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "forcer_porte_pierre_{$hx}_{$hy}_e",
        'parametres' => ['porte' => ['x' => $hx, 'y' => $hy, 'cote' => 'e']],
    ])->assertStatus(422);
});

it('moins de 2 crânes ne force pas la porte de pierre, qui reste fermée et retentable', function () {
    [$alice, $groupe, $hero, , , $quete, $etat] = demarrerQueteATroisHeros(['des_attaque' => 2]);
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    poserPorteDePierre($quete, $hx, $hy);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    // 2 dés, AUCUN crâne (deux boucliers blancs).
    desFiges([4, 5]);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "forcer_porte_pierre_{$hx}_{$hy}_e",
        'parametres' => ['porte' => ['x' => $hx, 'y' => $hy, 'cote' => 'e']],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.type', 'forcer_porte_pierre')
        ->assertJsonPath('resultat.cranes', 0)
        ->assertJsonPath('resultat.reussi', false);

    expect($quete->fresh()->carte->grille['portes'][0]['etat'])->toBe('fermee');
});

it('2 crânes ouvrent la porte de pierre POUR DE BON (persistant, comme toute porte)', function () {
    [$alice, $groupe, $hero, , , $quete, $etat] = demarrerQueteATroisHeros(['des_attaque' => 2]);
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    poserPorteDePierre($quete, $hx, $hy);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([1, 2]); // 2 crânes sur 2 dés
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "forcer_porte_pierre_{$hx}_{$hy}_e",
        'parametres' => ['porte' => ['x' => $hx, 'y' => $hy, 'cote' => 'e']],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.cranes', 2)
        ->assertJsonPath('resultat.reussi', true);

    expect($quete->fresh()->carte->grille['portes'][0]['etat'])->toBe('ouverte');
});

// ===================================================================
// LAME BALANÇOIRE (livret p. 4-5)
// ===================================================================

it('la lame balançoire frappe CHAQUE héros de sa zone, défense normale, jamais un héros hors zone', function () {
    [$alice, $groupe, $a, $b, $c, $quete, $etatA] = demarrerQueteATroisHeros(
        ['des_defense' => 0, 'pv_body' => 10, 'pv_body_max' => 10],
        ['des_defense' => 0, 'pv_body' => 10, 'pv_body_max' => 10],
        ['des_defense' => 0, 'pv_body' => 10, 'pv_body_max' => 10],
    );
    $hx = (int) $etatA->position_x;
    $hy = (int) $etatA->position_y;

    // Trigger à (hx+1, hy) ; zone verticale : (hx+1, hy-1), (hx+1, hy), (hx+1, hy+1).
    poserLameBalanciere($quete, $hx + 1, $hy, 'cache');

    $etatB = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $b->id)->firstOrFail();
    $etatC = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c->id)->firstOrFail();
    $etatB->update(['position_x' => $hx + 1, 'position_y' => $hy - 1]); // DANS la zone
    $etatC->update(['position_x' => $hx + 10, 'position_y' => $hy + 10]); // HORS zone, loin

    // Albrecht marche SUR le déclencheur (hx+1, hy), qui est AUSSI dans la
    // zone — des_defense=0 pour les trois : aucun dé de défense consommé,
    // seuls les 2 dés d'attaque de la lame comptent.
    desFiges([1, 1, 1, 1]); // 2 crânes × 2 dés d'attaque, pour CHACUNE des deux cibles (Albrecht + Brunhilde)
    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => $hx + 1, 'y' => $hy],
    ])->assertStatus(202);

    $declenchement = $reponse->json('resultat.pieges_declenches.0');
    expect($declenchement)->not->toBeNull()
        ->and($declenchement['zone'])->toBeTrue()
        ->and($declenchement['piege']['nom'])->toBe('Lame balançoire');

    $cibles = collect($declenchement['cibles']);
    expect($cibles->count())->toBe(2) // Albrecht + Brunhilde, jamais Cedric
        ->and($cibles->pluck('personnage.nom'))->toContain('Albrecht', 'Brunhilde')
        ->and($cibles->pluck('personnage.nom'))->not->toContain('Cedric');

    expect($c->fresh()->pv_body)->toBe(10); // intact, hors zone
    expect($a->fresh()->pv_body)->toBeLessThan(10); // dans la zone, a encaissé
});

it('désamorçage de la lame balançoire : le Nain réussit sans dé, un autre héros joue UN dé de combat (bouclier/crâne)', function () {
    [$alice, $groupe, $hero, , , $quete, $etat] = demarrerQueteATroisHeros(['classe' => 'nain']);
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    poserLameBalanciere($quete, $hx + 1, $hy, 'detecte');
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    // Le Nain : succès automatique, AUCUN dé à fournir (une file de dés VIDE
    // ferait planter le test si le code en consommait un par erreur).
    desFiges([]);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'desamorcer_'.($hx + 1).'_'.$hy,
        'parametres' => ['piege' => ['x' => $hx + 1, 'y' => $hy]],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.methode', 'nain_automatique')
        ->assertJsonPath('resultat.succes', true);

    expect($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('desarme');
});

it('trousse à outils : un bouclier désamorce la lame balançoire, sans la déclencher', function () {
    [$alice, $groupe, $hero, , , $quete, $etat] = demarrerQueteATroisHeros(['classe' => 'barbare']);
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => Objet::where('nom', 'Trousse à outils')->value('id'),
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    poserLameBalanciere($quete, $hx + 1, $hy, 'detecte');
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([4]); // bouclier blanc : désamorcée
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'desamorcer_'.($hx + 1).'_'.$hy,
        'parametres' => ['piege' => ['x' => $hx + 1, 'y' => $hy]],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.methode', 'trousse_de_combat')
        ->assertJsonPath('resultat.succes', true);

    expect($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('desarme');
});

it('trousse à outils : un crâne déclenche la zone entière de la lame balançoire, qui reste armée', function () {
    [$alice, $groupe, $hero, $voisin, $tiers, $quete, $etat] = demarrerQueteATroisHeros(
        ['classe' => 'barbare', 'des_defense' => 0, 'pv_body' => 10, 'pv_body_max' => 10],
    );
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => Objet::where('nom', 'Trousse à outils')->value('id'),
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    poserLameBalanciere($quete, $hx + 1, $hy, 'detecte');

    // Brunhilde et Cedric, écartés EXPLICITEMENT de la zone (leur spawn
    // procédural pourrait sinon y tomber par hasard) : ce test porte sur le
    // DÉSAMORÇAGE, pas sur le balayage collatéral — déjà couvert par le test
    // de sélectivité ci-dessus. Sans ça, une file de dés figée à UNE seule
    // valeur (le dé de désamorçage) s'épuiserait sur une cible imprévue.
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $voisin->id)
        ->update(['position_x' => $hx + 50, 'position_y' => $hy + 50]);
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $tiers->id)
        ->update(['position_x' => $hx + 60, 'position_y' => $hy + 60]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([1]); // crâne : la lame se déclenche
    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'desamorcer_'.($hx + 1).'_'.$hy,
        'parametres' => ['piege' => ['x' => $hx + 1, 'y' => $hy]],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.methode', 'trousse_de_combat')
        ->assertJsonPath('resultat.succes', false);

    expect($reponse->json('resultat.declenchement.zone'))->toBeTrue();
    // Le désamorceur agit DEPUIS une case adjacente : il n'est pas forcément
    // dans la zone lui-même, mais rien ne change au fait que le piège reste
    // armé (aucune transition d'état) — le texte ne connaît qu'un
    // déclenchement, jamais un épuisement.
    expect($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('detecte');
});

// ===================================================================
// FOSSE DES TÉNÈBRES (livret p. 5)
// ===================================================================

it('la fosse des ténèbres n\'est JAMAIS désamorçable : l\'option n\'apparaît pas au menu', function () {
    [$alice, $groupe, $hero, , , $quete, $etat] = demarrerQueteATroisHeros(['classe' => 'nain']);
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $cible = caseAdjacenteLibre($quete, $hx, $hy);

    poserFosseDesTenebres($quete, $cible['x'], $cible['y'], 'detecte');

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids->contains(fn ($id) => str_starts_with($id, 'desamorcer_')))->toBeFalse();

    // Et le résolveur refuse même une tentative forcée hors menu.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "desamorcer_{$cible['x']}_{$cible['y']}",
    ])->assertStatus(422);
});

it('dégâts de chute de la fosse des ténèbres : 1 PV sans armure, 2 PV en armure métallique, 3 PV en Armure de plates', function (
    ?string $armure, int $degatsAttendus,
) {
    [$alice, $groupe, $hero, , , $quete, $etat] = demarrerQueteATroisHeros(['pv_body' => 10, 'pv_body_max' => 10]);

    if ($armure !== null) {
        equiperArmureOgre($hero, $armure);
    }

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $cible = caseAdjacenteLibre($quete, $hx, $hy);
    poserFosseDesTenebres($quete, $cible['x'], $cible['y'], 'cache');

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => $cible['x'], 'y' => $cible['y']],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.pieges_declenches.0.degats', $degatsAttendus);

    expect($hero->fresh()->pv_body)->toBe(10 - $degatsAttendus);
})->with([
    'sans armure' => [null, 1],
    'Brassards — cuir, errata B1, PAS du métal' => ['Brassards', 1],
    'Cotte de mailles — métal' => ['Cotte de mailles', 2],
    'Armure de plates' => ['Armure de plates', 3],
]);

// ===================================================================
// CAISSE DE RAVITAILLEMENT (livret p. 5)
// ===================================================================

it('la caisse de ravitaillement donne 4 Potions de guérison au PREMIER chercheur, et un tirage normal ensuite', function () {
    [$alice, $groupe, $a, $b, , $quete, $etatA, $bob] = demarrerQueteATroisHeros();
    $salle = (int) \App\Partie\Salles::indexDe(
        $quete->carte->grille['salles'], (int) $etatA->position_x, (int) $etatA->position_y,
    );
    poserCaisseDeRavitaillement($quete, $salle);

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'caisse_ravitaillement')
        ->assertJsonPath('resultat.caisse_ravitaillement', true);

    $objets = collect($reponse->json('resultat.objets'));
    expect($objets)->toHaveCount(4)
        ->and($objets->pluck('objet.nom')->unique()->all())->toBe(['Potion de guérison']);

    expect(Inventaire::where('personnage_id', $a->id)
        ->where('objet_id', Objet::where('nom', 'Potion de guérison')->value('id'))
        ->sum('quantite'))->toBeGreaterThanOrEqual(4);

    // Le SECOND chercheur de la même salle ne retombe plus sur la caisse —
    // carte empilée connue pour un tirage déterministe.
    empilerCarteFouille($quete, ['issue' => 'rien']);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202); // referme le tour d'Albrecht sans agir une seconde fois

    $etatB = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $b->id)->firstOrFail();
    expect($etatB->position_x)->not->toBeNull();

    // Brunhilde agit par une requête SÉPARÉE : la session de test reste celle
    // d'Alice tant que rien ne la change — il faut `actingAs` le joueur de
    // Bob explicitement (même raison que le tuple étendu de
    // `demarrerQueteATroisHeros()`).
    test()->actingAs($bob, 'joueur');
    GenererMenu::dispatchSync($groupe->id, (int) $bob->id, (int) $b->id);

    $reponseB = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202);
    expect($reponseB->json('resultat.issue'))->not->toBe('caisse_ravitaillement');
});

// ===================================================================
// THÈME — gating des quatre éléments (dans les DEUX sens)
// ===================================================================

it('les quatre éléments Against the Ogre Horde ne sont JAMAIS posés hors du thème horde_ogre', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();

    $nomsPieges = Piege::pluck('nom', 'id');
    $nomsMobilier = Mobilier::pluck('nom', 'id');

    foreach (range(1, 30) as $i) {
        $carte = $assembleur->assembler($gabarit, $i * 1299721); // premier, hors-thème (bestiaire=null)

        foreach ($carte['pieges'] as $piege) {
            expect($nomsPieges[$piege['piege_id']] ?? null)
                ->not->toBe('Lame balançoire')->not->toBe('Fosse des ténèbres');
        }
        foreach ($carte['mobilier'] as $meuble) {
            expect($nomsMobilier[$meuble['mobilier_id']] ?? null)->not->toBe('Caisse de ravitaillement');
        }
        foreach ($carte['portes'] as $porte) {
            expect($porte['verrou']['type'] ?? null)->not->toBe('pierre');
        }
    }
});

it('les quatre éléments Against the Ogre Horde APPARAISSENT quand le thème du groupe inclut horde_ogre', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();
    $bestiaire = BestiaireGroupe::auto('horde_ogre');

    $nomsPieges = Piege::pluck('nom', 'id');
    $nomsMobilier = Mobilier::pluck('nom', 'id');

    $vus = ['Lame balançoire' => false, 'Fosse des ténèbres' => false, 'Caisse de ravitaillement' => false, 'pierre' => false];

    foreach (range(1, 40) as $i) {
        $carte = $assembleur->assembler($gabarit, $i * 1299721, bestiaire: $bestiaire);
        $nombreSalles = count($carte['salles']);

        foreach ($carte['pieges'] as $piege) {
            $nom = $nomsPieges[$piege['piege_id']] ?? null;
            if (isset($vus[$nom])) {
                $vus[$nom] = true;
            }
        }
        foreach ($carte['mobilier'] as $meuble) {
            if (($nomsMobilier[$meuble['mobilier_id']] ?? null) === 'Caisse de ravitaillement') {
                $vus['Caisse de ravitaillement'] = true;
            }
        }
        foreach ($carte['portes'] as $porte) {
            if (($porte['verrou']['type'] ?? null) === 'pierre') {
                $vus['pierre'] = true;

                // ⚠ GARANTIE « jamais le seul chemin » : une porte de pierre
                // n'est posée QUE sur une arête de BOUCLE, jamais l'arbre
                // couvrant — `jonction` indexe `carte['aretes']` dans le MÊME
                // ordre que `construireArbre()` les a produites
                // ([...arbre (n-1 arêtes), ...boucles]).
                expect((int) $porte['jonction'])->toBeGreaterThanOrEqual($nombreSalles - 1);
            }
        }
    }

    expect($vus)->toBe(['Lame balançoire' => true, 'Fosse des ténèbres' => true, 'Caisse de ravitaillement' => true, 'pierre' => true]);
});
