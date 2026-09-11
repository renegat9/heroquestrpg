<?php

declare(strict_types=1);

use App\Models\Quete;
use App\Partie\Grille;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LE MUR QUI SE DESSINAIT COMME UN COULOIR OUVERT (René, partie réelle,
 * 2026-09-10) : « les murs ayant un passage secret étaient OUVERTS
 * vis-à-vis les passages secrets ; il faudrait que ce soit un MUR jusqu'à ce
 * qu'un passage secret soit trouvé par la fouille. »
 *
 * Le correctif du 2026-09-10 réglait ça avec un DÉGUISEMENT d'affichage
 * (`etat: 'mur'`, posé par `EtatGroupe::portes()`) : la porte secrète restait
 * dans le payload, habillée en mur.
 *
 * ⚠ Ce fichier teste désormais la VERSION 2026-09-11 de ce même correctif,
 * rendue possible par le blocage PAR CASE (`Grille::caseEmbrasure()`, René,
 * après avoir joué : « la porte doit être centrale à sa case, bloquant
 * l'entrée dans sa case tant qu'elle n'est pas ouverte »). Puisque la porte
 * bloque maintenant une VRAIE case, cette case peut se peindre en ROCHE
 * (`m`) dans `carte.cases` — indiscernable d'un mur ordinaire par
 * construction, pas seulement par accord de vocabulaire. Le déguisement
 * `etat: 'mur'` n'a donc plus de raison d'être : une porte secrète non
 * révélée ne figure PLUS DU TOUT dans `carte.portes` (ni masquée, ni sous
 * aucune autre forme), exactement comme un mur de roche n'y figure jamais.
 *
 * ⚠ Helpers LOCAUX et nommés distinctement de ceux de `PortesExplorationTest`
 * (`demarrerExplo`/`poserPortes`) : ces fonctions globales ne sont chargées
 * que si Pest lit AUSSI ce fichier-là dans la même exécution. Ce fichier doit
 * pouvoir tourner seul (`./vendor/bin/pest tests/Feature/Partie/PorteSecreteAffichageTest.php`).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class]);
});

/**
 * Quête démarrée avec deux héros (le second empêche la phase des monstres de
 * se déclencher après l'action du premier) — même geste que
 * `PortesExplorationTest::demarrerExplo()`, dupliqué ici sous un autre nom
 * pour rester autonome.
 *
 * @return array{0: Quete, 1: \App\Models\EtatPersonnageQuete}
 */
function demarrerExploPourteSecrete(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = $quete->etatsPersonnages()->firstOrFail();

    return [$quete, $etat];
}

/** Fixe les portes de la carte (le reste — cases/salles/leviers — n'est pas touché). */
function fixerPortesSecretes(Quete $quete, array $portes): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['portes'] = $portes;
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

it('ne publie PAS une porte secrète non révélée dans carte.portes, et peint sa case d\'embrasure en roche', function () {
    [$quete, $etat] = demarrerExploPourteSecrete();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    fixerPortesSecretes($quete, [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'secrete', 'revele' => false]]);

    $embrasure = Grille::caseEmbrasure(
        ['x' => $hx, 'y' => $hy, 'cote' => 'e'],
        (array) ($quete->fresh()->carte->grille['salles'] ?? []),
    );

    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $portes = $partage['carte']['portes'];
    $porte = collect($portes)->first(fn ($p) => $p['x'] === $hx && $p['y'] === $hy && ($p['cote'] ?? null) === 'e');

    // Rien à publier pour ce cas : c'est la case, pas une entrée de `portes[]`,
    // qui dit « ceci est bloqué » — régression inverse de l'ancien défaut
    // (où la porte disparaissait purement et simplement du payload) exclue
    // par l'assertion sur `cases` juste en dessous.
    expect($porte)->toBeNull()
        ->and($partage['carte']['cases'][$embrasure['y']][$embrasure['x']])->toBe('m');
});

it('LE bug (2026-09-10) : le moteur bloque toujours le passage — des DEUX côtés (2026-09-11)', function () {
    [$quete, $etat] = demarrerExploPourteSecrete();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    fixerPortesSecretes($quete, [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'secrete', 'revele' => false]]);

    // Le payload ne dit plus rien ; le moteur, lui, raisonne toujours sur
    // l'état RÉEL stocké en base (`secrete`) et bloque — la case d'embrasure
    // est inoccupable, des DEUX côtés (le durcissement du 2026-09-11 : avant
    // lui, seul le pas venu du couloir était protégé).
    $carte = $quete->fresh()->carte;
    $grille = Grille::depuisCarte($carte);
    $embrasure = Grille::caseEmbrasure(['x' => $hx, 'y' => $hy, 'cote' => 'e'], (array) ($carte->grille['salles'] ?? []));

    expect($grille->porteBloqueEntre($hx, $hy, $hx + 1, $hy))->toBeTrue()
        ->and($grille->estTraversable($embrasure['x'], $embrasure['y']))->toBeFalse();
});

it('la porte de LIAISON SUPPLÉMENTAIRE peint elle aussi sa case en roche, même quand les DEUX salles sont déjà explorées', function () {
    // ⚠ Le cas précis vu en partie réelle. Une porte secrète issue de
    // `secretiserUneAreteDArbre()` (arbre couvrant) cache une salle qui n'a
    // QUE cet accès — le brouillard suffit à masquer ce qu'il y a derrière.
    // Mais une porte de `liaisonsSupplementaires()` (boucle) relie deux
    // salles qui ont CHACUNE leur propre accès normal : les deux sont déjà
    // explorées, donc déjà DÉCOUVERTES au sens du brouillard, et les deux
    // côtés de l'arête seraient déjà `'s'` visibles SANS le correctif de
    // cette case. C'est précisément lui qui produisait « un couloir
    // parfaitement continu à l'écran » avant le 2026-09-10.
    [$quete, $etat] = demarrerExploPourteSecrete();

    // Carte synthétique MINIMALE, entièrement contrôlée : deux salles 2×1
    // strictement adjacentes (x=0-1 et x=2-3, y=1), séparées par UNE arête
    // secrète. Aucune autre géométrie ne doit interférer avec la mesure.
    $carte = $quete->carte;
    $carte->update([
        'largeur' => 4,
        'hauteur' => 3,
        'grille' => [
            'cases' => [
                ['m', 'm', 'm', 'm'],
                ['s', 's', 's', 's'],
                ['m', 'm', 'm', 'm'],
            ],
            'salles' => [
                ['x' => 0, 'y' => 1, 'largeur' => 2, 'hauteur' => 1],
                ['x' => 2, 'y' => 1, 'largeur' => 2, 'hauteur' => 1],
            ],
            'portes' => [
                ['x' => 1, 'y' => 1, 'cote' => 'e', 'etat' => 'secrete', 'revele' => false],
            ],
            'leviers' => [],
            'pieges' => [],
        ],
    ]);
    $quete->load('carte');

    // Les DEUX salles sont marquées découvertes — chacune via son propre
    // accès, sans jamais passer par la porte secrète (exactement le scénario
    // d'une boucle `liaisonsSupplementaires()`).
    $quete->update(['salles_decouvertes' => [0, 1]]);
    $etat->update(['position_x' => 0, 'position_y' => 1]);

    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();

    // La case (1,1) — bord droit de la salle GAUCHE (x=[0,1]), l'embrasure —
    // se peint en roche : c'est elle, et non plus une entrée `portes[]`, qui
    // dit « c'est bloqué » malgré un brouillard qui ne masque plus rien ici.
    // (La case (2,1), bord gauche de la salle droite, est l'AUTRE candidate —
    // `caseEmbrasure()` s'arrête à la première salle qui réclame une case, et
    // la salle gauche est déclarée en premier : c'est elle qui gagne. Les
    // deux salles se touchant sur cette arête, l'une ou l'autre aurait été
    // correcte — seule compte l'unicité du choix.)
    expect($partage['carte']['cases'][1])->toBe(['s', 'm', 's', 's']);

    $porte = collect($partage['carte']['portes'])->first(fn ($p) => $p['x'] === 1 && $p['y'] === 1 && ($p['cote'] ?? null) === 'e');
    expect($porte)->toBeNull();

    // Et le moteur continue de bloquer, sur la carte réelle — des deux côtés.
    $grille = Grille::depuisCarte($quete->fresh()->carte);
    expect($grille->porteBloqueEntre(1, 1, 2, 1))->toBeTrue()
        ->and($grille->estTraversable(1, 1))->toBeFalse();
});

it('après une fouille réussie, la porte est publiée FERMÉE (case et arête redeviennent franchissables une fois ouverte)', function () {
    [$quete, $etat] = demarrerExploPourteSecrete();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    fixerPortesSecretes($quete, [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'secrete', 'revele' => false]]);

    desFiges([1, 4]); // Mind 2 dés : 1 crâne → réussite (difficulté 1, cf. PortesExplorationTest)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'reussite')
        ->assertJsonPath('resultat.portes_revelees.0.x', $hx);

    // ⚠ Une fouille réussie rend une porte FERMÉE, pas ouverte (arbitrage de
    // René, 2026-09-11 : « un passage secret trouvé devrait l'afficher comme
    // une porte fermée, on peut maintenant interagir avec pour l'ouvrir »).
    // Elle réapparaît alors dans `portes[]` — trouver n'est pas franchir, et
    // sa case reste bloquée (des deux côtés) tant que personne ne l'ouvre.
    $carte = $quete->fresh()->carte;
    $embrasure = Grille::caseEmbrasure(['x' => $hx, 'y' => $hy, 'cote' => 'e'], (array) ($carte->grille['salles'] ?? []));

    $portes = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.portes');
    $porte = collect($portes)->first(fn ($p) => $p['x'] === $hx && $p['y'] === $hy && ($p['cote'] ?? null) === 'e');

    expect($porte)->not->toBeNull()
        ->and($porte['etat'])->toBe('fermee')
        ->and($porte['embrasure'])->toBe(['x' => $embrasure['x'], 'y' => $embrasure['y']])
        ->and(Grille::depuisCarte($carte)->porteBloqueEntre($hx, $hy, $hx + 1, $hy))->toBeTrue()
        ->and(Grille::depuisCarte($carte)->estTraversable($embrasure['x'], $embrasure['y']))->toBeFalse();

    // …et elle s'ouvre alors comme n'importe quelle porte fermée. C'est la
    // moitié utile de l'arbitrage : sans elle, on aurait juste rendu un
    // passage trouvé inutilisable.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "ouvrir_porte_{$hx}_{$hy}_e"])
        ->assertStatus(202);

    $carteOuverte = $quete->fresh()->carte;
    expect(Grille::depuisCarte($carteOuverte)->porteBloqueEntre($hx, $hy, $hx + 1, $hy))->toBeFalse()
        ->and(Grille::depuisCarte($carteOuverte)->estTraversable($embrasure['x'], $embrasure['y']))->toBeTrue();
});

it('un mur de roche ordinaire et une case d\'embrasure de porte secrète non révélée sont RIGOUREUSEMENT indiscernables', function () {
    [$quete, $etat] = demarrerExploPourteSecrete();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    fixerPortesSecretes($quete, [
        ['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'secrete', 'revele' => false],
        ['x' => $hx, 'y' => $hy, 'cote' => 's', 'etat' => 'fermee'],
    ]);

    $carte = $quete->fresh()->carte;
    $salles = (array) ($carte->grille['salles'] ?? []);
    $embrasureSecrete = Grille::caseEmbrasure(['x' => $hx, 'y' => $hy, 'cote' => 'e'], $salles);
    $embrasureFermee = Grille::caseEmbrasure(['x' => $hx, 'y' => $hy, 'cote' => 's'], $salles);

    $partage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $portes = collect($partage['carte']['portes']);

    // Le passage secret n'a PLUS AUCUNE entrée dans `portes[]` (ni côté 'e',
    // ni sous quelque forme que ce soit) — exactement comme un mur de roche
    // ordinaire : la distinction résiduelle qu'un déguisement laissait
    // (« une entrée `mur` isolée » repérable en comptant les entrées) a
    // disparu avec le déguisement lui-même.
    expect($portes->first(fn ($p) => ($p['cote'] ?? null) === 'e' && $p['x'] === $hx && $p['y'] === $hy))->toBeNull()
        // La porte FERMÉE ordinaire, elle, reste publiée normalement.
        ->and($portes->first(fn ($p) => ($p['cote'] ?? null) === 's' && $p['x'] === $hx && $p['y'] === $hy)['etat'])->toBe('fermee');

    // Et sa case se lit dans `cases` comme n'importe quel mur : 'm', sans
    // rien qui la distingue d'un mur voisin qui n'a jamais caché de porte.
    expect($partage['carte']['cases'][$embrasureSecrete['y']][$embrasureSecrete['x']])->toBe('m')
        // La case de la porte FERMÉE ordinaire, elle, reste du sol — seule
        // l'entrée `portes[]` (et son battant) la distinguent d'un mur.
        ->and($partage['carte']['cases'][$embrasureFermee['y']][$embrasureFermee['x']])->toBe('s');
});
