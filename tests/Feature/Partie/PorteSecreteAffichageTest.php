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
 * `Grille::porteBloqueEntre()` bloquait déjà tout ce qui n'est pas `ouverte`,
 * secrète comprise. Le défaut vivait entièrement côté AFFICHAGE :
 * `EtatGroupe::portes()` RETIRAIT purement et simplement la porte secrète non
 * révélée du payload. Les deux cases qu'elle sépare sont du SOL — et pour une
 * porte de LIAISON SUPPLÉMENTAIRE (`AssembleurCarte::liaisonsSupplementaires()`,
 * une boucle par-dessus l'arbre couvrant), les DEUX salles qu'elle relie ont
 * chacune leur propre accès normal : elles sont donc déjà explorées et
 * visibles des deux côtés, le brouillard ne masque plus rien du tout. Sans la
 * porte, il ne restait RIEN pour dire que le passage était bloqué — un
 * couloir d'apparence parfaitement continue, avec une arête invisible que le
 * résolveur refusait sans explication (« le menu ne propose jamais ce que le
 * résolveur refusera », CLAUDE.md).
 *
 * Correctif : la porte secrète non révélée reste dans le payload, déguisée en
 * MUR (`etat: 'mur'`) — un état d'AFFICHAGE posé par `EtatGroupe::portes()`,
 * jamais écrit en base, absent de `MoteurPortes::ETAT_*`.
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

it('publie une porte secrète non révélée comme un MUR, jamais avec `secrete`/`revele`/`verrou`/`image_url`', function () {
    [$quete, $etat] = demarrerExploPourteSecrete();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    fixerPortesSecretes($quete, [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'secrete', 'revele' => false]]);

    $portes = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.portes');
    $porte = collect($portes)->first(fn ($p) => $p['x'] === $hx && $p['y'] === $hy && ($p['cote'] ?? null) === 'e');

    expect($porte)->not->toBeNull('la porte secrète a disparu du payload — régression inverse (ancien défaut)')
        ->and($porte['etat'])->toBe('mur')
        ->and($porte)->not->toHaveKey('secrete')
        ->and($porte)->not->toHaveKey('revele')
        ->and($porte)->not->toHaveKey('verrou')
        ->and($porte)->not->toHaveKey('image_url');

    // Rien de plus que ce qu'un mur ordinaire pourrait justifier : x, y, cote, etat.
    $clefs = collect(array_keys($porte))->sort()->values()->all();
    expect($clefs)->toBe(['cote', 'etat', 'x', 'y']);
});

it('LE bug : le moteur bloque toujours le passage derrière le mur affiché (cohérence moteur/affichage)', function () {
    [$quete, $etat] = demarrerExploPourteSecrete();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    fixerPortesSecretes($quete, [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'secrete', 'revele' => false]]);

    // Le payload dit « mur » ; le moteur, lui, raisonne toujours sur l'état
    // RÉEL stocké en base (`secrete`) et bloque — c'est exactement la
    // cohérence que ce ticket exige : ce que la table montre doit annoncer ce
    // que le résolveur va décider, jamais le contraire.
    $grille = Grille::depuisCarte($quete->fresh()->carte);
    expect($grille->porteBloqueEntre($hx, $hy, $hx + 1, $hy))->toBeTrue()
        // Les deux cases, elles, restent bien du sol — ce n'est pas une case
        // murée, seule l'ARÊTE bloque (doc 14 §3.1).
        ->and($grille->estTraversable($hx, $hy))->toBeTrue()
        ->and($grille->estTraversable($hx + 1, $hy))->toBeTrue();
});

it('la porte de LIAISON SUPPLÉMENTAIRE reste un mur même quand les DEUX salles sont déjà explorées', function () {
    // ⚠ Le cas précis vu en partie réelle. Une porte secrète issue de
    // `secretiserUneAreteDArbre()` (arbre couvrant) cache une salle qui n'a
    // QUE cet accès — le brouillard suffit à masquer ce qu'il y a derrière.
    // Mais une porte de `liaisonsSupplementaires()` (boucle) relie deux
    // salles qui ont CHACUNE leur propre accès normal : les deux sont déjà
    // explorées, donc déjà DÉCOUVERTES au sens du brouillard, et les deux
    // côtés de l'arête sont déjà `'s'` visibles. Un test qui ne construirait
    // qu'une salle cachée derrière la porte manquerait précisément ce cas —
    // c'est lui qui produisait « un couloir parfaitement continu à l'écran ».
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

    // Le brouillard ne masque effectivement RIEN ici : les deux côtés de
    // l'arête sont visibles — c'est précisément le cas où seul l'affichage de
    // la porte pouvait encore dire « c'est bloqué ».
    expect($partage['carte']['cases'][1])->toBe(['s', 's', 's', 's']);

    $porte = collect($partage['carte']['portes'])->first(fn ($p) => $p['x'] === 1 && $p['y'] === 1 && ($p['cote'] ?? null) === 'e');
    expect($porte)->not->toBeNull('la porte a disparu de la carte alors que les deux salles sont visibles — le couloir redevient continu')
        ->and($porte['etat'])->toBe('mur');

    // Et le moteur continue de bloquer, sur la carte réelle.
    expect(Grille::depuisCarte($quete->fresh()->carte)->porteBloqueEntre(1, 1, 2, 1))->toBeTrue();
});

it('après une fouille réussie, la porte n\'est plus masquée et devient franchissable', function () {
    [$quete, $etat] = demarrerExploPourteSecrete();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    fixerPortesSecretes($quete, [['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'secrete', 'revele' => false]]);

    desFiges([1, 4]); // Mind 2 dés : 1 crâne → réussite (difficulté 1, cf. PortesExplorationTest)

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'reussite')
        ->assertJsonPath('resultat.portes_revelees.0.x', $hx);

    // ⚠ `MoteurPortes::revelerSecretes()` écrit l'état RÉEL à `ouverte` (pas
    // `secrete`) — comportement déjà verrouillé par
    // `PortesExplorationTest` (« révèle par « Fouiller la zone » »), et ce
    // correctif n'y touche pas. Cette moitié du test vérifie donc ce qui est
    // VRAI après CE correctif : le déguisement tombe (`mur` a disparu) et le
    // passage s'ouvre réellement — pas la couleur exacte du rendu, qui suit
    // l'état réel publié tel quel (voir le rapport final : un déguisement
    // révélé par une VRAIE fouille rend comme une porte ouverte ordinaire,
    // pas en violet « secrète », faute d'un marqueur persistant distinct —
    // hors périmètre de ce correctif, signalé plutôt que maquillé).
    $portes = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.portes');
    $porte = collect($portes)->first(fn ($p) => $p['x'] === $hx && $p['y'] === $hy && ($p['cote'] ?? null) === 'e');

    expect($porte)->not->toBeNull()
        ->and($porte['etat'])->not->toBe('mur')
        ->and(Grille::depuisCarte($quete->fresh()->carte)->porteBloqueEntre($hx, $hy, $hx + 1, $hy))->toBeFalse();
});

it('un mur de roche ordinaire et une porte secrète non révélée sont indiscernables dans le payload', function () {
    [$quete, $etat] = demarrerExploPourteSecrete();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    fixerPortesSecretes($quete, [
        ['x' => $hx, 'y' => $hy, 'cote' => 'e', 'etat' => 'secrete', 'revele' => false],
        ['x' => $hx, 'y' => $hy, 'cote' => 's', 'etat' => 'fermee'],
    ]);

    $portes = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.portes'));
    $mur = $portes->first(fn ($p) => ($p['cote'] ?? null) === 'e' && $p['x'] === $hx && $p['y'] === $hy);
    $fermee = $portes->first(fn ($p) => ($p['cote'] ?? null) === 's' && $p['x'] === $hx && $p['y'] === $hy);

    expect($mur['etat'])->toBe('mur')
        ->and($fermee['etat'])->toBe('fermee');

    // ⚠ Distinction résiduelle ASSUMÉE, pas maquillée : un mur de roche
    // ordinaire (case `m`) n'a AUCUNE entrée dans `carte.portes` — cette
    // arête-ci EN a une, parce qu'il existe un mécanisme de fouille dessus.
    // Ce test vérifie donc ce qui EST atteignable : aucun champ de l'entrée
    // ne la trahit. Elle porte exactement le même vocabulaire (x, y, cote,
    // etat) qu'une porte `fermee` ordinaire, sans rien de plus — pas de champ
    // qui dirait « cherche ici ».
    $clefsMur = collect(array_keys($mur))->sort()->values()->all();
    expect($clefsMur)->toBe(['cote', 'etat', 'x', 'y'])
        ->and($mur)->not->toHaveKey('image_url')
        ->and($fermee)->toHaveKey('image_url'); // une VRAIE porte, elle, en a une — la différence est ATTENDUE ici
});
