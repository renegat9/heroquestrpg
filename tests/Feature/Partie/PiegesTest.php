<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurPieges;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Pièges (doc 10, contrat docs/contrat-api.md) — tout passe par les menus :
 * déclenchement en marchant sur un piège CACHÉ (la fosse interrompt le
 * déplacement), révélation par la fouille, désamorçage (Nain / Trousse à
 * outils, jet de Body 1 — échec = déclenché sur le désamorceur) et
 * franchissement d'une fosse détectée (jet de Body 2 — échec = chute).
 * Les pièges cachés n'apparaissent JAMAIS dans EtatGroupe.carte.pieges.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class]);
});

/**
 * REMPLACE les pièges de la carte par ceux du scénario (les pièges posés par
 * l'assembleur sont écartés : le test contrôle exactement le terrain).
 *
 * @param  list<array{x: int, y: int, nom: string, etat: string}>  $entrees
 */
function poserPieges(Quete $quete, array $entrees): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;

    $grille['pieges'] = array_map(fn (array $e) => [
        'x' => $e['x'],
        'y' => $e['y'],
        'piege_id' => Piege::where('nom', $e['nom'])->value('id'),
        'etat' => $e['etat'],
    ], $entrees);

    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/**
 * Direction de saut valide depuis (x, y) : la case adjacente (fosse) ET la
 * case suivante dans le même alignement (réception) sont libres.
 *
 * @return array{fosse: array{x: int, y: int}, reception: array{x: int, y: int}}
 */
function alignementFranchissable(Quete $quete, int $x, int $y): array
{
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if (caseQueteLibre($quete, $x + $dx, $y + $dy) && caseQueteLibre($quete, $x + 2 * $dx, $y + 2 * $dy)) {
            return [
                'fosse' => ['x' => $x + $dx, 'y' => $y + $dy],
                'reception' => ['x' => $x + 2 * $dx, 'y' => $y + 2 * $dy],
            ];
        }
    }

    throw new RuntimeException('Aucun alignement de 2 cases libres — scénario de test invalide.');
}

/**
 * Quête démarrée avec deux héros (le second empêche la phase des monstres
 * de se déclencher après l'action du premier).
 *
 * @param  array<string, mixed>  $attributs  attributs du héros d'Alice
 * @return array{0: JoueurAuthentifiable, 1: Groupe, 2: Personnage, 3: Quete, 4: EtatPersonnageQuete}
 */
function demarrerQueteAvecHeros(array $attributs = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, $attributs);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    return [$alice, $groupe, $hero, $quete, $etat];
}

/**
 * Cherche depuis (hx,hy) une ligne droite de 3 cases libres avec une case
 * perpendiculaire libre au 2e pas (où poser le piège caché, HORS chemin).
 */
function trouverSceneCourse(Quete $quete, int $hx, int $hy): ?array
{
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $p1 = ['x' => $hx + $dx, 'y' => $hy + $dy];
        $p2 = ['x' => $hx + 2 * $dx, 'y' => $hy + 2 * $dy];
        $p3 = ['x' => $hx + 3 * $dx, 'y' => $hy + 3 * $dy];
        if (! caseQueteLibre($quete, $p1['x'], $p1['y']) || ! caseQueteLibre($quete, $p2['x'], $p2['y']) || ! caseQueteLibre($quete, $p3['x'], $p3['y'])) {
            continue;
        }
        foreach ([[$dy, $dx], [-$dy, -$dx]] as [$px, $py]) {
            $piege = ['x' => $p2['x'] + $px, 'y' => $p2['y'] + $py];
            if (caseQueteLibre($quete, $piege['x'], $piege['y'])
                && abs($piege['x'] - $hx) + abs($piege['y'] - $hy) > 1
                && abs($piege['x'] - $p1['x']) + abs($piege['y'] - $p1['y']) > 1) {
                return ['p2' => $p2, 'p3' => $p3, 'piege' => $piege];
            }
        }
    }

    return null;
}

it('interrompt la course d\'un Nain (Œil du mineur) quand un piège devient adjacent, en gardant les points restants', function () {
    [, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(['classe' => 'nain']);
    $hero->competences()->syncWithoutDetaching([
        Competence::where('classe', 'nain')->where('nom', 'Œil du mineur')->value('id'),
    ]);

    // On CHERCHE une case de départ offrant la géométrie voulue au lieu de
    // dépendre du placement initial : ce test porte sur la mécanique du piège,
    // pas sur la disposition de la carte (les spawns ont bougé avec le
    // correctif §2.12). Le héros est ensuite déplacé sur cette case.
    $salle = (array) data_get($quete->carte->grille, 'salles.0', []);

    $candidats = [['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y]];
    for ($y = (int) $salle['y']; $y < (int) $salle['y'] + (int) $salle['hauteur']; $y++) {
        for ($x = (int) $salle['x']; $x < (int) $salle['x'] + (int) $salle['largeur']; $x++) {
            $candidats[] = ['x' => $x, 'y' => $y];
        }
    }

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $scene = null;

    foreach ($candidats as $depart) {
        $scene = trouverSceneCourse($quete, $depart['x'], $depart['y']);
        if ($scene !== null) {
            [$hx, $hy] = [$depart['x'], $depart['y']];
            break;
        }
    }

    $etat->update(['position_x' => $hx, 'position_y' => $hy]);

    expect($scene)->not->toBeNull('Pas de géométrie ligne droite + perpendiculaire libre pour le scénario.');

    poserPieges($quete, [['x' => $scene['piege']['x'], 'y' => $scene['piege']['y'], 'nom' => 'Piège à lances', 'etat' => 'cache']]);

    // Allonce du tour fixée (6) pour une assertion déterministe des points restants.
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => null, 'a_deplace' => false]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $scene['p3'], // vise LOIN…
    ])->assertStatus(202);

    // …mais s'arrête au 2e pas, où le piège devient adjacent : arrêt SOUPLE
    // (détection), points restants CONSERVÉS, piège révélé, PV intacts.
    $reponse->assertJsonPath('resultat.type', 'deplacement')
        ->assertJsonPath('resultat.vers', $scene['p2'])
        ->assertJsonPath('resultat.interrompu', true)
        ->assertJsonPath('resultat.arret_detection', true)
        ->assertJsonPath('resultat.distance', 2)
        ->assertJsonPath('resultat.deplacement_restant', 4)
        ->assertJsonPath('resultat.pieges_declenches', [])
        ->assertJsonPath('resultat.pieges_reveles.0.nom', 'Piège à lances');

    $etat->refresh();
    expect((int) $etat->position_x)->toBe($scene['p2']['x'])
        ->and((int) $etat->position_y)->toBe($scene['p2']['y'])
        ->and((bool) $etat->a_deplace)->toBeFalse() // il lui reste des points : peut désamorcer/contourner/continuer
        ->and((int) $hero->fresh()->pv_body)->toBe($hero->pv_body_max) // rien déclenché
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('detecte');
});

it('déclenche un piège caché traversé : 1 dé de combat, un crâne = 1 PV, usage unique consommé, tour terminé', function () {
    [, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'cache']]);

    // ⚠ Le dé de déplacement d'Albrecht est déjà FIXÉ (roulé au démarrage de la
    // quête, `DemarreurQuete::demarrer()`, avant que ce test ne contrôle le
    // lanceur) : une seule case suffit largement, ce n'est jamais lui qui
    // manque. La file ne sert donc qu'au dé de combat DU PIÈGE (2 = crâne),
    // puis au dé de MOUVEMENT DE BRUNHILDE — son tour commence dans la MÊME
    // requête, puisque le piège vient de fermer TOUT le tour d'Albrecht
    // (livret p. 14) et que `ChoixController::choisir()` régénère le menu de
    // chaque héros actif après chaque choix (`OrdreDuTour::estSonTour()`).
    desFiges([2, 4]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $cible,
    ])->assertStatus(202);

    // « Their turn immediately ends » (livret p. 14, 2026-09-24) : les TROIS
    // pièges de sol arrêtent la course NET, désormais, y compris ceux qui ne
    // sont pas la fosse — auparavant seule `immobilise` déclenchait l'arrêt
    // dur et le piège à lances laissait le héros continuer sa marche.
    $reponse->assertJsonPath('resultat.type', 'deplacement')
        ->assertJsonPath('resultat.interrompu', true)
        ->assertJsonPath('resultat.vers', $cible)
        ->assertJsonPath('resultat.pieges_declenches.0.piege.nom', 'Piège à lances')
        ->assertJsonPath('resultat.pieges_declenches.0.degats', 1)
        ->assertJsonPath('resultat.pieges_declenches.0.faces', ['crane'])
        ->assertJsonPath('resultat.pieges_declenches.0.touches', 1)
        ->assertJsonPath('resultat.pieges_declenches.0.bloc_permanent', false)
        ->assertJsonPath('resultat.pieges_declenches.0.pv_body_apres', 7)
        ->assertJsonPath('resultat.pieges_declenches.0.immobilise', false);

    $etat->refresh();
    expect($hero->fresh()->pv_body)->toBe(7)
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('declenche')
        // Le tour ENTIER se ferme (livret p. 14) : `a_joue` — pas seulement
        // `a_deplace` — sinon le héros pourrait encore agir après le coup.
        // C'est `a_joue` que `MenuMoteur::generer()` consulte pour décider
        // qu'il n'y a plus rien à proposer ce tour-ci (§combat-et-tour).
        ->and((bool) $etat->a_joue)->toBeTrue()
        ->and((bool) $etat->a_deplace)->toBeTrue();

    // Le piège déclenché devient public dans EtatGroupe.carte ; les héros
    // exposent leur niveau (contrat).
    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    // (image_url retiré : dépend des assets générés, hors périmètre du test)
    expect(collect($partage['carte']['pieges'])->map(fn ($p) => collect($p)->except('image_url')->all())->all())->toBe([
        ['x' => $cible['x'], 'y' => $cible['y'], 'etat' => 'declenche', 'nom' => 'Piège à lances'],
    ])->and($partage['entites'][0]['niveau'])->toBe(1);
});

it('arrête le déplacement sur une fosse cachée : la fosse persiste, le tour se termine', function () {
    [, , $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $saut = alignementFranchissable($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $saut['fosse']['x'], 'y' => $saut['fosse']['y'], 'nom' => 'Fosse', 'etat' => 'cache']]);

    desFiges([3]); // 1d6 de déplacement (2 cases demandées, 4+3 disponibles) — pas de dé de combat : la Fosse n'en lance pas

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $saut['reception'],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.interrompu', true)
        ->assertJsonPath('resultat.vers.x', $saut['fosse']['x'])
        ->assertJsonPath('resultat.vers.y', $saut['fosse']['y'])
        ->assertJsonPath('resultat.pieges_declenches.0.degats', 1)
        ->assertJsonPath('resultat.pieges_declenches.0.immobilise', true)
        ->assertJsonPath('resultat.pieges_declenches.0.bloc_permanent', false);

    $etat->refresh();
    expect($etat->position_x)->toBe($saut['fosse']['x'])
        ->and($etat->position_y)->toBe($saut['fosse']['y'])
        ->and($hero->fresh()->pv_body)->toBe(7)
        // Persistante : la fosse reste en jeu, désormais visible — sous son
        // PROPRE état, jamais `detecte` (René, 2026-09-27 : un trou ouvert ne
        // doit pas se lire comme un piège détecté intact).
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_FOSSE_OUVERTE)
        // « Their turn immediately ends » (livret p. 14, 2026-09-24) : la
        // fosse ne se contentait QUE d'immobiliser le déplacement avant cette
        // date — désormais le tour ENTIER se ferme, comme les deux autres
        // pièges de sol (alignement demandé explicitement, René/coordinateur).
        ->and((bool) $etat->a_joue)->toBeTrue();
});

it('révèle par la fouille TOUS les pièges cachés de la salle, même loin — jamais ceux d\'une autre zone', function () {
    // René, 2026-09-27 : « la fouille de piège ou de passage secret se fait
    // seulement dans la salle ou corridor actuel du joueur, sans tenir compte
    // du line of sight ». Jusque-là : un rayon de 3 cases filtré par la vue.
    [, , , $quete, $etat] = demarrerQueteAvecHeros();

    $x = (int) $etat->position_x;
    $y = (int) $etat->position_y;
    $grille = $quete->carte->grille;
    $salle = $grille['salles'][App\Partie\Salles::indexDe($grille['salles'], $x, $y)];

    // La case de sol de SA salle la plus éloignée du fouilleur.
    $loin = null;
    for ($cy = $salle['y'] + 1; $cy < $salle['y'] + $salle['hauteur'] - 1; $cy++) {
        for ($cx = $salle['x'] + 1; $cx < $salle['x'] + $salle['largeur'] - 1; $cx++) {
            if (caseQueteLibre($quete, $cx, $cy)
                && ($loin === null || abs($cx - $x) + abs($cy - $y) > abs($loin['x'] - $x) + abs($loin['y'] - $y))) {
                $loin = ['x' => $cx, 'y' => $cy];
            }
        }
    }

    // Une case de sol HORS de sa salle, juste derrière l'une de ses portes.
    $dehors = null;
    foreach ($grille['portes'] as $porte) {
        foreach (App\Partie\Grille::casesPorte($porte) as $c) {
            $dedans = $c['x'] >= $salle['x'] && $c['x'] < $salle['x'] + $salle['largeur']
                && $c['y'] >= $salle['y'] && $c['y'] < $salle['y'] + $salle['hauteur'];
            if (! $dedans && $grille['cases'][$c['y']][$c['x']] !== 'm') {
                $dehors = $c;
                break 2;
            }
        }
    }
    expect($loin)->not->toBeNull()->and($dehors)->not->toBeNull();

    poserPieges($quete, [
        ['x' => $loin['x'], 'y' => $loin['y'], 'nom' => 'Fosse', 'etat' => 'cache'],
        ['x' => $dehors['x'], 'y' => $dehors['y'], 'nom' => 'Piège à lances', 'etat' => 'cache'],
    ]);

    desFiges([1, 4]); // Mind 2 dés : 1 crâne → réussite (difficulté 1)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'reussite')
        ->assertJsonPath('resultat.pieges_reveles.0.nom', 'Fosse')
        ->assertJsonCount(1, 'resultat.pieges_reveles');

    // Seul le piège révélé apparaît dans l'état partagé : celui d'à côté reste
    // CACHÉ et n'y figure jamais (contrat).
    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($partage['carte']['pieges'])->map(fn ($p) => collect($p)->except('image_url')->all())->all())->toBe([
        ['x' => $loin['x'], 'y' => $loin['y'], 'etat' => 'detecte', 'nom' => 'Fosse'],
    ]);

    $pieges = $quete->fresh()->carte->grille['pieges'];
    expect($pieges[0]['etat'])->toBe('detecte')
        ->and($pieges[1]['etat'])->toBe('cache');
});

it('laisse le Nain désamorcer un piège détecté adjacent : jet de Body 1 réussi → désarmé', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(['classe' => 'nain']);

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'detecte']]);

    // Re-proposition du menu moteur : il contient maintenant Désamorcer.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id));
    $option = collect($menu['menu']['options'])->firstWhere('id', "desamorcer_{$cible['x']}_{$cible['y']}");
    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('desamorcage')
        ->and($option['jet'])->toBe(['attribut' => 'body', 'difficulte' => 1])
        ->and($option['parametres'])->toBe(['piege' => ['x' => $cible['x'], 'y' => $cible['y']]]);

    desFiges([1, 4, 4, 4]); // Body 4 dés : 1 crâne → réussite (difficulté 1)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'desamorcage')
        ->assertJsonPath('resultat.desarme', true)
        ->assertJsonPath('resultat.piege.nom', 'Piège à lances');

    expect($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('desarme')
        ->and($hero->fresh()->pv_body)->toBe(8); // indemne

    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect($partage['carte']['pieges'][0]['etat'])->toBe('desarme');
});

it('déclenche le piège sur le désamorceur quand le jet échoue', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(['classe' => 'nain']);

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'detecte']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    // ⚠ Le Nain « désamorce sans outils » (dos de carte) : UN dé, et seul le
    // bouclier noir (6) fait échouer. Ce n'est plus un jet de Body — lui
    // appliquer le jet ordinaire aurait vidé la mention de sa substance.
    // Puis le dé de combat DU PIÈGE (1, contrat 2026-09-24) : 1 = crâne. Et
    // enfin le dé de MOUVEMENT DE BRUNHILDE : l'échec ferme TOUT le tour
    // d'Albrecht (livret p. 14), donc c'est désormais SON tour qui commence
    // dans la même requête (voir le commentaire du test précédent).
    desFiges([6, 1, 4]);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "desamorcer_{$cible['x']}_{$cible['y']}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.desarme', false)
        ->assertJsonPath('resultat.declenchement.contexte', 'desamorcage_rate')
        ->assertJsonPath('resultat.declenchement.degats', 1)
        ->assertJsonPath('resultat.declenchement.faces', ['crane'])
        ->assertJsonPath('resultat.declenchement.pv_body_apres', 7);

    $etat->refresh();
    expect($hero->fresh()->pv_body)->toBe(7)
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('declenche') // usage unique consommé
        // « Their turn immediately ends » (livret p. 14) : le désamorceur
        // n'est jamais SUR la case du piège, mais son tour se ferme quand
        // même — le déclenchement compte, pas seulement le fait d'y marcher.
        ->and((bool) $etat->a_joue)->toBeTrue();
});

it('Désamorçage (nœud) épargne le déclenchement sur un jet raté', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(['classe' => 'nain']);
    $hero->competences()->attach(
        Competence::where('classe', 'nain')->where('nom', 'Désamorçage')->value('id'),
    );

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'detecte']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    // ⚠ Le Nain « désamorce sans outils » (dos de carte) : UN dé, et seul le
    // bouclier noir (6) fait échouer. Ce n'est plus un jet de Body — lui
    // appliquer le jet ordinaire aurait vidé la mention de sa substance.
    desFiges([6]);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "desamorcer_{$cible['x']}_{$cible['y']}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.desarme', false)
        ->assertJsonPath('resultat.declenchement', null);

    expect($hero->fresh()->pv_body)->toBe(8) // indemne : pas de déclenchement
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('detecte'); // reste détecté, retentable
});

it('refuse le désamorçage sans Nain ni trousse — et l\'offre au porteur de la Trousse à outils', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(); // barbare

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'detecte']]);

    // Barbare sans trousse : aucune option Désamorcer dans le menu moteur…
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids->contains(fn ($id) => str_starts_with($id, 'desamorcer_')))->toBeFalse();

    // … et forcer l'option hors menu est illégal (le moteur fait autorité).
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "desamorcer_{$cible['x']}_{$cible['y']}",
    ])->assertStatus(422);

    // Avec la Trousse à outils au sac (effet permet_desamorcage), l'option apparaît.
    Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => Objet::where('nom', 'Trousse à outils')->value('id'),
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids)->toContain("desamorcer_{$cible['x']}_{$cible['y']}");
});

it('franchit une fosse détectée sur un jet de Body 2 réussi : le héros atterrit de l\'autre côté', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(); // barbare : Franchir, pas Désamorcer

    $saut = alignementFranchissable($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $saut['fosse']['x'], 'y' => $saut['fosse']['y'], 'nom' => 'Fosse', 'etat' => 'detecte']]);
    $etat->update(['deplacement_tour' => 6]); // allonce connue → coût du saut vérifiable

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id));
    $option = collect($menu['menu']['options'])->firstWhere('id', "franchir_{$saut['fosse']['x']}_{$saut['fosse']['y']}");
    expect($option)->not->toBeNull()
        ->and($option['type'])->toBe('franchissement')
        ->and($option['jet'])->toBe(['attribut' => 'body', 'difficulte' => 2]);

    desFiges([1, 1, 4, 4]); // Body 4 dés : 2 crânes → réussite (difficulté 2)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'franchissement')
        ->assertJsonPath('resultat.franchi', true)
        ->assertJsonPath('resultat.vers.x', $saut['reception']['x'])
        ->assertJsonPath('resultat.vers.y', $saut['reception']['y'])
        // Sauter fait partie du MOUVEMENT (E3) : 2 cases payées sur les 6.
        ->assertJsonPath('resultat.deplacement_restant', 4);

    $etat->refresh();
    expect($etat->position_x)->toBe($saut['reception']['x'])
        ->and($etat->position_y)->toBe($saut['reception']['y'])
        ->and($hero->fresh()->pv_body)->toBe(8) // indemne
        // Il reste des points → le mouvement n'est PAS fini : on peut continuer.
        ->and($etat->deplacement_restant)->toBe(4)
        ->and($etat->a_deplace)->toBeFalse()
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('detecte'); // la fosse reste en jeu
});

it('fait chuter le héros dans la fosse quand le franchissement échoue', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $saut = alignementFranchissable($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $saut['fosse']['x'], 'y' => $saut['fosse']['y'], 'nom' => 'Fosse', 'etat' => 'detecte']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    // 0 crâne → échec → chute (effet de la fosse, sans dé de combat propre),
    // puis le dé de MOUVEMENT DE BRUNHILDE : la chute ferme TOUT le tour
    // d'Albrecht (livret p. 14), donc c'est son tour à elle qui commence
    // dans la même requête (voir le commentaire du premier test de ce fichier
    // à consommer plusieurs dés dans la même requête).
    desFiges([4, 4, 4, 4, 4]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "franchir_{$saut['fosse']['x']}_{$saut['fosse']['y']}",
    ])->assertStatus(202)
        ->assertJsonPath('resultat.franchi', false)
        ->assertJsonPath('resultat.declenchement.contexte', 'franchissement_rate')
        ->assertJsonPath('resultat.declenchement.degats', 1)
        ->assertJsonPath('resultat.vers.x', $saut['fosse']['x'])
        ->assertJsonPath('resultat.vers.y', $saut['fosse']['y']);

    $etat->refresh();
    expect($etat->position_x)->toBe($saut['fosse']['x'])
        ->and($etat->position_y)->toBe($saut['fosse']['y'])
        ->and($hero->fresh()->pv_body)->toBe(7)
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_FOSSE_OUVERTE); // persistante, ouverte
});

/** Pose une porte sur une arête, en remplaçant celles de l'assembleur. */
function poserPortePiege(Quete $quete, array $portes): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['portes'] = $portes;
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

it('ignore la ligne de vue DANS la zone : un piège masqué par un meuble haut se révèle', function () {
    // Historique : le 2026-09-18, René avait vu une fouille révéler un piège
    // derrière une porte fermée — d'où une ligne de vue ajoutée au rayon. La
    // ZONE (2026-09-27) règle ce cas autrement (derrière une porte, c'est une
    // autre salle ou un couloir, voir le test précédent) et sans l'effet de
    // bord : dans la pièce même, un meuble ou un coin ne cache plus rien.
    [, , , $quete, $etat] = demarrerQueteAvecHeros();

    $x = (int) $etat->position_x;
    $y = (int) $etat->position_y;

    // Mur de roche fictif collé au héros (côté est), piège juste derrière :
    // même salle, hors de vue.
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['cases'][$y][$x + 1] = 'm';
    $carte->update(['grille' => $grille]);
    $quete->load('carte');

    poserPieges($quete, [['x' => $x + 2, 'y' => $y, 'nom' => 'Fosse', 'etat' => 'cache']]);

    desFiges([1, 4]); // Mind : réussite de la fouille

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'reussite')
        ->assertJsonCount(1, 'resultat.pieges_reveles');

    expect($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('detecte');
});

/*
 * LES TROIS PIÈGES DE SOL, ENFIN TELS QUE LE LIVRET LES DÉCRIT (2026-09-24).
 *
 * `AssembleurCarte::placerPieges()` posait LE PREMIER piège du catalogue pour
 * CHAQUE case tirée — `Piege::orderBy('id')->value('id')`, soit la Fosse.
 * Mesuré sur toutes les cartes en base : 6 pièges, 6 fosses. La Chute de
 * blocs et le Piège à lances n'étaient JAMAIS placés, et même posée, la
 * Chute de blocs n'aurait rien bloqué (`bloque_passage` sans lecteur).
 */

it('tire les TROIS types de pièges de sol sur un échantillon de cartes, jamais un piège de coffre', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();

    $noms = Piege::pluck('nom', 'id');
    $piegesDeCoffre = ['Aiguille empoisonnée', 'Fiole de poison', 'Piège de coffre'];
    $comptes = ['Fosse' => 0, 'Piège à lances' => 0, 'Chute de blocs' => 0];
    $total = 0;

    // 60 cartes au moins (consigne) — un nombre premier par carte pour ne
    // jamais retomber sur la même graine que `PassageSecretTest`.
    foreach (range(1, 60) as $i) {
        $carte = $assembleur->assembler($gabarit, $i * 104729);

        foreach ($carte['pieges'] as $piege) {
            $nom = $noms[$piege['piege_id']] ?? null;

            expect($nom)->not->toBeNull()
                ->and(in_array($nom, $piegesDeCoffre, true))->toBeFalse(
                    "Un piège de COFFRE ({$nom}) a été posé à l'assemblage — son cycle est la fouille du trésor, jamais la carte.",
                );

            $comptes[$nom] = ($comptes[$nom] ?? 0) + 1;
            $total++;
        }
    }

    expect($total)->toBeGreaterThanOrEqual(60)
        ->and($comptes['Fosse'])->toBeGreaterThan(0)
        ->and($comptes['Piège à lances'])->toBeGreaterThan(0)
        ->and($comptes['Chute de blocs'])->toBeGreaterThan(0);
});

it('le tirage des pièges de sol est un registre testé dans les deux sens', function () {
    // Sens 1 : le vivier de `placerPieges()` (tout piège dont l'effet n'a PAS
    // `declencheur: ouverture_tresor`) contient EXACTEMENT les pièges de
    // sol du livret — rien de plus. Un piège de coffre qui perdrait sa clé
    // `declencheur` par erreur se retrouverait posé sur la carte, hors de son
    // cycle (fouille du trésor).
    //
    // ⚠ Depuis Against the Ogre Horde (lot B, 2026-10-02), deux pièges de sol
    // supplémentaires existent dans le CATALOGUE — « Fosse des ténèbres » (une
    // variante de la Fosse) et « Lame balançoire » (un piège à ZONE) — mais
    // tous deux portent `boite: 'horde_ogre'` : ils n'entrent JAMAIS dans ce
    // vivier pour un groupe sans ce thème (filtrée par `AssembleurCarte::placerPieges()`,
    // AssembleurCarteOgreHordeTest le vérifie côté placement). La « Lame
    // balançoire » en est de toute façon EXCLUE pour une seconde raison : elle
    // porte `effet.zone_lames`, donc elle n'est posée QUE par
    // `placerLameBalanciere()`, jamais par le tirage par case isolée ici.
    $sol = Piege::query()
        ->get()
        ->reject(fn (Piege $p) => data_get($p->effet, 'declencheur') === 'ouverture_tresor')
        ->reject(fn (Piege $p) => data_get($p->effet, 'zone_lames') !== null)
        ->pluck('nom')
        ->sort()
        ->values()
        ->all();

    expect($sol)->toBe(['Chute de blocs', 'Fosse', 'Fosse des ténèbres', 'Piège à lances']);

    // Sens 2 : aucun des quatre n'a, par erreur, un `declencheur` qui
    // l'écarterait à tort du tirage de sol.
    foreach (['Fosse', 'Piège à lances', 'Chute de blocs', 'Fosse des ténèbres'] as $nom) {
        expect(data_get(Piege::where('nom', $nom)->value('effet'), 'declencheur'))->toBeNull();
    }

    // Et la Lame balançoire, elle, porte bien `zone_lames` — c'est ce qui la
    // retire du vivier générique ci-dessus.
    expect(data_get(Piege::where('nom', 'Lame balançoire')->value('effet'), 'zone_lames'))->not->toBeNull();
});

it('un BLOC PERMANENT bloque le passage ET la vue, lu par FabriqueGrille::pour()', function () {
    // Test DÉCOUPLÉ du déclenchement (couvert par le test suivant) : ici on
    // pose directement l'état `bloc` pour isoler la seule question « la
    // boucle unique de FabriqueGrille lit-elle bien cette case ? ».
    [, , , $quete, $etat] = demarrerQueteAvecHeros();

    $x = (int) $etat->position_x;
    $y = (int) $etat->position_y;
    $saut = alignementFranchissable($quete, $x, $y); // deux cases alignées, libres
    $bloc = $saut['fosse'];
    $apres = $saut['reception'];

    poserPieges($quete, [['x' => $bloc['x'], 'y' => $bloc['y'], 'nom' => 'Chute de blocs', 'etat' => MoteurPieges::ETAT_BLOC]]);

    $grille = FabriqueGrille::pour($quete->fresh());

    expect($grille->estTraversable($bloc['x'], $bloc['y']))->toBeFalse('le bloc doit bloquer le PASSAGE, comme un mur')
        // Ligne de vue tracée D'UN CÔTÉ à L'AUTRE du bloc, aligné : la case du
        // bloc est strictement ENTRE les deux extrémités, donc la coupe si
        // elle est opaque — exactement le test déjà appliqué au mobilier haut
        // et au mur de glace.
        ->and($grille->ligneDeVue($x, $y, $apres['x'], $apres['y']))->toBeFalse('le bloc doit bloquer la VUE, comme un mur');
});

it('déclenche la Chute de blocs : 3 dés de combat sans défense, bloc permanent, le héros doit s\'écarter avant que son tour ne se termine', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $depart = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
    $scene = alignementFranchissable($quete, $depart['x'], $depart['y']);
    $bloc = $scene['fosse'];       // la case où tombe le bloc (1 pas)
    $avancer = $scene['reception']; // la case suivante dans le même sens (2 pas) — libre par construction du scénario

    poserPieges($quete, [['x' => $bloc['x'], 'y' => $bloc['y'], 'nom' => 'Chute de blocs', 'etat' => 'cache']]);

    // ⚠ Le dé de déplacement d'Albrecht est déjà FIXÉ au démarrage de la
    // quête (voir le premier test de ce fichier à consommer plusieurs dés
    // dans la même requête) : la file ne sert donc qu'aux 3 dés de combat
    // SANS DÉFENSE (contrat 2026-09-24) : 2 crânes (1, 2) + 1 bouclier noir
    // (6) → 2 PV de dégâts. Albrecht garde la main après (il doit s'écarter),
    // donc PAS de dé de Brunhilde ici — voir plus bas, à sa fermeture réelle.
    desFiges([1, 2, 6]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $bloc,
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.type', 'deplacement')
        ->assertJsonPath('resultat.interrompu', true)
        ->assertJsonPath('resultat.vers', $bloc)
        ->assertJsonPath('resultat.pieges_declenches.0.piege.nom', 'Chute de blocs')
        ->assertJsonPath('resultat.pieges_declenches.0.faces', ['crane', 'crane', 'bouclier_noir'])
        ->assertJsonPath('resultat.pieges_declenches.0.touches', 2)
        ->assertJsonPath('resultat.pieges_declenches.0.degats', 2)
        ->assertJsonPath('resultat.pieges_declenches.0.bloc_permanent', true)
        // La manette reçoit ces trois dés DÉJÀ mis en forme : ils vivent nichés
        // dans `pieges_declenches`, et `des` restait `null` (vu en live, 2026-09-25).
        ->assertJsonPath('des.atk', ['crane', 'crane', 'bouclier_noir']);

    $etat->refresh();
    expect($hero->fresh()->pv_body)->toBe(6)
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_BLOC)
        // Le déplacement est fini (il est arrêté sur le bloc), mais le TOUR,
        // lui, n'est PAS encore terminé : le héros doit d'abord choisir où
        // s'écarter (livret p. 14) — à la différence de la fosse et du piège
        // à lances, qui ferment le tour immédiatement.
        ->and((bool) $etat->a_deplace)->toBeTrue()
        ->and((bool) $etat->a_joue)->toBeFalse()
        ->and($etat->piege_a_ecarter)->not->toBeNull();

    $attente = $etat->piege_a_ecarter;
    expect(['x' => $attente['x'], 'y' => $attente['y']])->toBe($bloc);

    $cases = collect($attente['cases']);
    expect($cases)->toHaveCount(2) // reculer + avancer, tous deux libres par construction du scénario
        ->and($cases->firstWhere(fn ($c) => $c['sens'] === 'reculer'))->toBe(['x' => $depart['x'], 'y' => $depart['y'], 'sens' => 'reculer'])
        ->and($cases->firstWhere(fn ($c) => $c['sens'] === 'avancer'))->toBe(['x' => $avancer['x'], 'y' => $avancer['y'], 'sens' => 'avancer']);

    // Tant qu'il n'a pas choisi, le menu ne contient QUE `s_ecarter_du_bloc`
    // — et c'est CETTE liste blanche (portée par l'option) que le résolveur
    // revalide, pas une reconstruction depuis la colonne.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id));
    expect(collect($menu['menu']['options'])->pluck('id')->all())->toBe(['s_ecarter_du_bloc']);

    $option = collect($menu['menu']['options'])->firstWhere('id', 's_ecarter_du_bloc');
    expect($option['creneau'])->toBe('tour')
        ->and(collect($option['parametres']['cases']))->toHaveCount(2);

    // Une case HORS liste blanche est un 422 — le résolveur fait autorité.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 's_ecarter_du_bloc',
        'parametres' => ['x' => $depart['x'] + 37, 'y' => $depart['y'] + 41],
    ])->assertStatus(422);

    // Rien n'a bougé, rien n'est effacé après le refus.
    $etat->refresh();
    expect($etat->piege_a_ecarter)->not->toBeNull();

    // RECULER : le choix ferme le tour ENTIER (livret p. 14) — c'est
    // maintenant au tour de Brunhilde de recevoir son dé de mouvement, dans
    // la même requête (`OrdreDuTour::estSonTour()`).
    desFiges([4]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 's_ecarter_du_bloc',
        'parametres' => $depart,
    ])->assertStatus(202)
        ->assertJsonPath('resultat.type', 's_ecarter_du_bloc')
        ->assertJsonPath('resultat.sens', 'reculer')
        ->assertJsonPath('resultat.vers', $depart);

    $etat->refresh();
    expect((int) $etat->position_x)->toBe($depart['x'])
        ->and((int) $etat->position_y)->toBe($depart['y'])
        ->and($etat->piege_a_ecarter)->toBeNull()
        ->and((bool) $etat->a_joue)->toBeTrue();

    // Le bloc reste sur la carte, publié à tous, dessiné comme un bloc de
    // pierre (pas un piège caché) — et bloque toujours le passage.
    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $piegePartage = collect($partage['carte']['pieges'])->firstWhere('x', $bloc['x']);
    expect($piegePartage)->not->toBeNull()
        ->and($piegePartage['etat'])->toBe('bloc')
        ->and(FabriqueGrille::pour($quete->fresh())->estTraversable($bloc['x'], $bloc['y']))->toBeFalse();
});

it('le piège à lances disparaît (usage consommé) après s\'être déclenché', function () {
    [, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'cache']]);

    // Le dé de mouvement d'Albrecht est déjà fixé au démarrage de la quête —
    // la file sert au dé de combat DU PIÈGE (6 = bouclier noir, 0 dégât) puis
    // au dé de mouvement DE BRUNHILDE (le déclenchement ferme tout le tour
    // d'Albrecht, livret p. 14 — voir le premier test de ce fichier).
    desFiges([6, 4]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $cible,
    ])->assertStatus(202)
        ->assertJsonPath('resultat.pieges_declenches.0.touches', 0)
        ->assertJsonPath('resultat.pieges_declenches.0.degats', 0);

    // « there are no spear trap tiles » (livret p. 14) : une fois déclenché,
    // il ne reste rien à désamorcer ni à franchir — `declenche`, à jamais.
    $piege = $quete->fresh()->carte->grille['pieges'][0];
    expect($piege['etat'])->toBe('declenche');

    // Et il n'apparaît donc plus adjacent, offrant Désamorcer/Franchir.
    expect(app(MoteurPieges::class)->detectesAdjacents($quete->fresh()->carte, $cible['x'], $cible['y']))->toBe([]);
});

/*
 * UN PIÈGE CONNU NE SE CONTOURNE PLUS GRATUITEMENT (René, 2026-09-27 : « il est
 * plutôt facile de contourner les trappes plutôt que de les désamorcer ou sauter
 * par-dessus »). Jusque-là `controlerChemin()` ne déclenchait que les pièges
 * `cache` : un piège DÉTECTÉ se foulait sans rien subir, ce qui vidait de sens
 * le saut et le désamorçage — dans un couloir d'une case comme ailleurs.
 */

it('déclenche un piège DÉTECTÉ sur lequel le héros marche sciemment', function () {
    [, , $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $cible['x'], 'y' => $cible['y'], 'nom' => 'Piège à lances', 'etat' => 'detecte']]);

    // Dé de combat DU PIÈGE (2 = crâne), puis dé de mouvement de Brunhilde :
    // le déclenchement ferme tout le tour d'Albrecht (livret p. 14).
    desFiges([2, 4]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $cible,
    ])->assertStatus(202)
        ->assertJsonPath('resultat.interrompu', true)
        ->assertJsonPath('resultat.pieges_declenches.0.piege.nom', 'Piège à lances')
        ->assertJsonPath('resultat.pieges_declenches.0.degats', 1);

    expect($hero->fresh()->pv_body)->toBe(7)
        ->and($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe('declenche')
        ->and((bool) $etat->fresh()->a_joue)->toBeTrue();
});

it('fait CONTOURNER un piège connu par le trajet quand un détour est payable', function () {
    [, , $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Une ligne H → piège → arrivée, et la ligne parallèle libre à côté :
    // le détour coûte 4 points au lieu de 2.
    $scene = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        foreach ([[$dy, $dx], [-$dy, -$dx]] as [$px, $py]) {
            $cases = [
                'piege' => ['x' => $hx + $dx, 'y' => $hy + $dy],
                'arrivee' => ['x' => $hx + 2 * $dx, 'y' => $hy + 2 * $dy],
            ];
            $libres = [
                $cases['piege'], $cases['arrivee'],
                ['x' => $hx + $px, 'y' => $hy + $py],
                ['x' => $hx + $dx + $px, 'y' => $hy + $dy + $py],
                ['x' => $hx + 2 * $dx + $px, 'y' => $hy + 2 * $dy + $py],
            ];
            if (collect($libres)->every(fn ($c) => caseQueteLibre($quete, $c['x'], $c['y']))) {
                $scene = $cases;
                break 2;
            }
        }
    }
    expect($scene)->not->toBeNull('scénario de test invalide : aucun détour libre autour du héros');

    poserPieges($quete, [['x' => $scene['piege']['x'], 'y' => $scene['piege']['y'], 'nom' => 'Fosse', 'etat' => 'detecte']]);
    $etat->update(['deplacement_tour' => 6]);

    $apercu = $this->postJson('/api/groupes/table-1/deplacement/apercu', $scene['arrivee'])->assertOk();
    expect($apercu->json('pieges'))->toBe([])
        ->and($apercu->json('cout'))->toBe(4);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $scene['arrivee'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.pieges_declenches', [])
        ->assertJsonPath('resultat.vers', $scene['arrivee']);

    expect($hero->fresh()->pv_body)->toBe(8)
        ->and($etat->fresh()->deplacement_restant)->toBe(2);
});

it('laisse sauter une Chute de blocs DÉTECTÉE (livret : sautée avant déclenchement), jamais un Piège à lances', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros();

    $depart = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
    $saut = alignementFranchissable($quete, $depart['x'], $depart['y']);
    poserPieges($quete, [['x' => $saut['fosse']['x'], 'y' => $saut['fosse']['y'], 'nom' => 'Chute de blocs', 'etat' => 'detecte']]);
    $etat->update(['deplacement_tour' => 6]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids)->toContain("franchir_{$saut['fosse']['x']}_{$saut['fosse']['y']}");

    // Saut RATÉ (aucun crâne sur 4 dés de Body), puis les 3 dés de la chute
    // (boucliers noirs : 0 dégât). Le héros tombe SUR le bloc : il doit s'en
    // écarter, exactement comme s'il l'avait déclenché en marchant.
    desFiges([4, 4, 4, 4, 6, 6, 6]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "franchir_{$saut['fosse']['x']}_{$saut['fosse']['y']}",
    ])->assertStatus(202)
        ->assertJsonPath('resultat.franchi', false)
        ->assertJsonPath('resultat.declenchement.bloc_permanent', true);

    $etat->refresh();
    expect($quete->fresh()->carte->grille['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_BLOC)
        ->and($etat->piege_a_ecarter)->not->toBeNull()
        ->and(collect($etat->piege_a_ecarter['cases'])->firstWhere('sens', 'reculer'))
        ->toBe(['x' => $depart['x'], 'y' => $depart['y'], 'sens' => 'reculer'])
        // Le tour attend le choix d'écart : il n'est pas encore fermé.
        ->and((bool) $etat->a_joue)->toBeFalse();

    // Le Piège à lances, lui, ne se saute pas (livret p. 14 : désamorcer seulement).
    poserPieges($quete, [['x' => $saut['fosse']['x'], 'y' => $saut['fosse']['y'], 'nom' => 'Piège à lances', 'etat' => 'detecte']]);
    $lances = Piege::where('nom', 'Piège à lances')->first();
    expect(app(MoteurPieges::class)->estFranchissable($lances))->toBeFalse()
        ->and(app(MoteurPieges::class)->estFosse(Piege::where('nom', 'Chute de blocs')->first()))->toBeFalse();
});

it('publie une fosse DÉCLENCHÉE sous son propre état : sautable, jamais désamorçable (René, 2026-09-27)', function () {
    // Un Nain : s'il voyait « Désamorcer » sur une fosse ouverte, ce serait ici.
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteAvecHeros(['classe' => 'nain']);

    $saut = alignementFranchissable($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPieges($quete, [['x' => $saut['fosse']['x'], 'y' => $saut['fosse']['y'], 'nom' => 'Fosse', 'etat' => MoteurPieges::ETAT_FOSSE_OUVERTE]]);
    $etat->update(['deplacement_tour' => 6]);

    // La carte partagée la montre sous son état — distincte d'un piège détecté intact.
    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($partage['carte']['pieges'])->pluck('etat')->all())->toBe([MoteurPieges::ETAT_FOSSE_OUVERTE]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');

    expect($ids)->toContain("franchir_{$saut['fosse']['x']}_{$saut['fosse']['y']}")
        ->and($ids)->not->toContain("desamorcer_{$saut['fosse']['x']}_{$saut['fosse']['y']}");
});
