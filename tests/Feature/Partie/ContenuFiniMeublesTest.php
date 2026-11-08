<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Mobilier;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\JournalCombat;
use App\Partie\MoteurMobilier;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * CONTENU FINI DES MEUBLES (René, 2026-11-07, après le bilan `docs/bilan-economie-2026-11-07.md`).
 *
 * - Chaque résultat d'une table de meuble, « rien » excepté, n'en sort qu'UNE fois par meuble (`fois` pour plus) : ce
 *   qui reste se partage la chance libérée, et le meuble dont il ne reste que « rien » est VIDE — il quitte le menu.
 * - LE COFFRE (`un_seul_butin`) : « fouillé seulement 1 fois avec 1 résultat seulement (artefact ou or) ». Son premier
 *   butin le vide pour tout le groupe ; un piège ne le vide pas et rend sa fouille au héros.
 * - L'or des meubles hors coffres est divisé par deux.
 *
 * Les tables sont écrites en BASE de test (sqlite jetable) : c'est la VRAIE fouille qui tourne, par la route.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/**
 * Une vraie quête à deux héros ; un meuble du type `$nom` posé AU CONTACT d'Albrecht, dans la salle de départ, qui
 * n'est la salle d'aucun coffre désigné (sauf `$salleCoffre`). `$table` remplace la table du catalogue ; `$grille`
 * complète l'entrée de grille (un `tirees` déjà inscrit, par exemple).
 *
 * @param  list<array<string, mixed>>|null  $table
 * @param  array<string, mixed>  $grille
 * @return array{groupe: Groupe, hero: Personnage, brunhilde: Personnage, quete: Quete, etat: EtatPersonnageQuete, type: Mobilier}
 */
function contenuFiniScene(string $nom, ?array $table = null, array $grille = [], bool $salleCoffre = false): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'barbare']);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->update(['salles_coffre' => $salleCoffre ? [0] : [], 'salle_artefact' => null, 'coffres_ouverts' => []]);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    $type = Mobilier::where('nom', $nom)->firstOrFail();

    if ($table !== null) {
        $type->update(['effet' => [...(array) $type->effet, 'fouille' => $table]]);
    }

    $axe = placerHerosSurAxeDegage($quete, $etat, 2);
    $etat->refresh();

    $carte = $quete->carte;
    $g = $carte->grille;
    $g['mobilier'] = [[
        'mobilier_id' => (int) $type->id,
        'x' => (int) $etat->position_x + $axe['dx'],
        'y' => (int) $etat->position_y + $axe['dy'],
        'l' => 1, 'h' => 1,
        'salle' => 0,
    ] + $grille];
    $carte->update(['grille' => $g]);

    $etat->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);

    return ['groupe' => $groupe, 'hero' => $hero, 'brunhilde' => $brunhilde, 'quete' => $quete->fresh(), 'etat' => $etat->fresh(), 'type' => $type->fresh()];
}

/** Albrecht fouille le meuble par la route réelle (menu regénéré, choix posté). @return array<string, mixed> */
function contenuFiniFouiller(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['hero']->joueur_id, (int) $ctx['hero']->id);

    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_mobilier_0'])
        ->assertStatus(202)->json('resultat');
}

/** L'entrée de grille du meuble, relue en base. @return array<string, mixed> */
function contenuFiniEntree(array $ctx): array
{
    return (array) (($ctx['quete']->fresh()->carte->grille['mobilier'] ?? [])[0] ?? []);
}

/** Les meubles qu'un héros pourrait fouiller depuis la case d'Albrecht (le menu les lit ici). */
function contenuFiniOfferts(array $ctx, int $personnageId): array
{
    $etat = $ctx['etat']->fresh();

    return app(MoteurMobilier::class)->fouillablesAdjacents(
        $ctx['quete']->fresh()->carte, (int) $etat->position_x, (int) $etat->position_y, $personnageId,
    );
}

it('le TIRAGE retire les résultats déjà sortis de CE meuble, jamais « rien », et respecte `fois`', function () {
    $mm = app(MoteurMobilier::class);
    $armoire = Mobilier::where('nom', 'Armoire')->firstOrFail();
    $indexOr = collect($armoire->effet['fouille'])->search(fn (array $e) => $e['issue'] === 'tresor');

    // L'or de l'armoire déjà trouvé : il ne sort plus, en 200 tirages.
    $issues = collect(range(1, 200))->map(fn () => $mm->tirerButin($armoire, 1, [], [], [$indexOr => 1])['issue'])->unique();
    expect($issues->contains('tresor'))->toBeFalse();

    // Le trône dont l'or est pris ne rend plus que « rien » : il est vide.
    $trone = Mobilier::where('nom', 'Trône')->firstOrFail();
    expect($mm->tirerButin($trone, 1, [], [], [0 => 1])['issue'])->toBe('rien')
        ->and($mm->resteUnButin($trone, [0 => 1]))->toBeFalse()
        ->and($mm->resteUnButin($trone, []))->toBeTrue();

    // La Table des brasseurs : « three potions can be found » — la troisième sort encore, pas une quatrième.
    $brasseurs = Mobilier::where('nom', 'Table des brasseurs')->firstOrFail();
    expect($mm->resteUnButin($brasseurs, [0 => 2], ['spirit_queen']))->toBeTrue()
        ->and($mm->resteUnButin($brasseurs, [0 => 3], ['spirit_queen']))->toBeFalse();

    // L'index de l'entrée voyage avec la carte, pour le décompte.
    expect($mm->tirerButin($trone))->toHaveKey('table_index');
});

it('l\'exemple de René — « 3 potions + piège + rien » : un sac de cinq jetons, dont seul « rien » retourne au sac', function () {
    $mm = app(MoteurMobilier::class);
    $sac = new Mobilier;
    $sac->effet = ['fouille' => [
        ['issue' => 'objet', 'poids' => 1, 'objet' => 'Potion de soin', 'fois' => 3],
        ['issue' => 'piege', 'poids' => 1, 'piege' => 'Aiguille empoisonnée'],
        ['issue' => 'rien', 'poids' => 1],
    ]];
    $partPotion = fn (array $tirees) => collect(range(1, 1200))
        ->filter(fn () => $mm->tirerButin($sac, 1, [], [], $tirees)['issue'] === 'objet')->count() / 1200;

    // Plein : 3 jetons potion sur 5 (60 %). Deux potions prises : 1 sur 3 (33 %). Trois prises : plus jamais.
    expect($partPotion([]))->toBeGreaterThan(0.52)->toBeLessThan(0.68)
        ->and($partPotion([0 => 2]))->toBeGreaterThan(0.26)->toBeLessThan(0.41)
        ->and($partPotion([0 => 3]))->toEqual(0);

    // Les trois potions et le piège sortis : il ne reste que « rien », le meuble est vide.
    expect($mm->resteUnButin($sac, [0 => 3]))->toBeTrue()
        ->and($mm->resteUnButin($sac, [0 => 3, 1 => 1]))->toBeFalse()
        ->and($mm->tirerButin($sac, 1, [], [], [0 => 3, 1 => 1])['issue'])->toBe('rien');
});

it('le catalogue : or des meubles divisé par deux, coffre à un seul butin, trois potions chez les brasseurs', function () {
    $or = fn (string $nom) => collect(Mobilier::where('nom', $nom)->firstOrFail()->effet['fouille'])->firstWhere('issue', 'tresor')['or'];

    expect($or('Trône'))->toBe([18, 38])
        ->and($or('Tombeau'))->toBe([15, 35])
        ->and($or('Armoire'))->toBe([8, 20])
        ->and($or('Bibliothèque'))->toBe([5, 12])
        ->and($or('Coffre'))->toBe([25, 60]);

    $coffre = Mobilier::where('nom', 'Coffre')->firstOrFail();
    expect(MoteurMobilier::unSeulButin($coffre))->toBeTrue()
        ->and(array_column($coffre->effet['fouille'], 'issue'))->toBe(['tresor', 'piege']);

    // Aucun autre meuble n'est à un seul butin.
    expect(Mobilier::all()->filter(fn (Mobilier $m) => MoteurMobilier::unSeulButin($m))->pluck('nom')->all())->toBe(['Coffre']);

    $potion = collect(Mobilier::where('nom', 'Table des brasseurs')->firstOrFail()->effet['fouille'])->firstWhere('issue', 'objet');
    expect($potion['fois'])->toBe(3);
});

it('EN JEU — le dernier butin sorti, le meuble est VIDE : il quitte le menu de TOUS, et le fil le dit', function () {
    $ctx = contenuFiniScene('Trône', [['issue' => 'tresor', 'poids' => 1, 'or' => [10, 10]]], ['tirees' => []]);

    // Avant : le trône est offert à Brunhilde, qui ne l'a jamais fouillé.
    expect(contenuFiniOfferts($ctx, (int) $ctx['brunhilde']->id))->toHaveCount(1);

    $resultat = contenuFiniFouiller($ctx);

    expect($resultat['issue'])->toBe('tresor')
        ->and($resultat['or'])->toBe(10)
        ->and($resultat['meuble_vide'])->toBeTrue()
        ->and($resultat)->not->toHaveKey('table_index');

    $entree = contenuFiniEntree($ctx);
    expect(MoteurMobilier::estVide($entree))->toBeTrue()
        ->and(MoteurMobilier::tireesDe($entree))->toBe([0 => 1]);

    // Brunhilde ne l'a jamais fouillé, et il ne lui est plus offert.
    expect(contenuFiniOfferts($ctx, (int) $ctx['brunhilde']->id))->toBe([]);

    $lignes = array_column(app(JournalCombat::class)->depuisResultat($resultat, 'Albrecht'), 'texte');
    expect($lignes)->toContain('Le trône est désormais vide');
});

it('« rien » ne s\'use jamais : compté cent fois, il sort encore, et l\'or à côté de lui aussi', function () {
    $mm = app(MoteurMobilier::class);
    $trone = Mobilier::where('nom', 'Trône')->firstOrFail();
    $indexRien = collect($trone->effet['fouille'])->search(fn (array $e) => $e['issue'] === 'rien');

    $issues = collect(range(1, 200))->map(fn () => $mm->tirerButin($trone, 1, [], [], [$indexRien => 100])['issue'])->unique()->sort()->values()->all();

    expect($issues)->toBe(['rien', 'tresor'])
        ->and($mm->resteUnButin($trone, [$indexRien => 100]))->toBeTrue();
});

it('EN JEU — le résultat déjà sorti cède sa chance aux autres : l\'or pris, seul le piège reste à tirer', function () {
    // Poids 5 contre 1 : sans le contenu fini, l'or sortirait cinq fois sur six.
    $ctx = contenuFiniScene('Tombeau', [
        ['issue' => 'tresor', 'poids' => 5, 'or' => [10, 10]],
        ['issue' => 'piege', 'poids' => 1, 'piege' => 'Aiguille empoisonnée'],
    ], ['tirees' => [0 => 1]]);

    $resultat = contenuFiniFouiller($ctx);

    expect($resultat['issue'])->toBe('piege')
        ->and($resultat['meuble_vide'])->toBeTrue()
        ->and(MoteurMobilier::tireesDe(contenuFiniEntree($ctx)))->toBe([0 => 1, 1 => 1]);
});

it('EN JEU — LE COFFRE ne rend qu\'UN butin : de l\'or, puis il est vide pour tout le groupe', function () {
    // Le coffre du catalogue : or et piège de Spirit Queen's Torment, qui n'entre pas hors de son thème.
    $ctx = contenuFiniScene('Coffre');
    $avant = (int) $ctx['groupe']->fresh()->or;

    $resultat = contenuFiniFouiller($ctx);

    expect($resultat['issue'])->toBe('tresor')
        ->and($resultat['or'])->toBeGreaterThanOrEqual(25)->toBeLessThanOrEqual(60)
        ->and($resultat['meuble_vide'])->toBeTrue()
        ->and((int) $ctx['groupe']->fresh()->or)->toBe($avant + (int) $resultat['or'])
        ->and(contenuFiniOfferts($ctx, (int) $ctx['brunhilde']->id))->toBe([]);
});

it('EN JEU — un PIÈGE ne vide pas le coffre : il sort, et le héros garde sa fouille', function () {
    // `fois` 2 : le coffre de ce test garde un piège après le premier, pour lire ce qui reste.
    $ctx = contenuFiniScene('Coffre', [['issue' => 'piege', 'poids' => 1, 'piege' => 'Aiguille empoisonnée', 'fois' => 2]], ['tirees' => []]);

    $resultat = contenuFiniFouiller($ctx);

    expect($resultat['issue'])->toBe('piege')
        ->and($resultat)->not->toHaveKey('meuble_vide');

    $entree = contenuFiniEntree($ctx);
    expect(MoteurMobilier::estVide($entree))->toBeFalse()
        ->and(MoteurMobilier::tireesDe($entree))->toBe([0 => 1])
        // La fouille est RENDUE : Albrecht peut rouvrir le coffre.
        ->and(contenuFiniOfferts($ctx, (int) $ctx['hero']->id))->toHaveCount(1);
});

it('EN JEU — le COFFRE DÉSIGNÉ de la salle rend sa récompense, puis il est vide', function () {
    $ctx = contenuFiniScene('Coffre', null, [], salleCoffre: true);

    $resultat = contenuFiniFouiller($ctx);

    expect($resultat['coffre_quete'])->toBeTrue()
        ->and($resultat['meuble_vide'])->toBeTrue()
        ->and(MoteurMobilier::estVide(contenuFiniEntree($ctx)))->toBeTrue()
        ->and(contenuFiniOfferts($ctx, (int) $ctx['brunhilde']->id))->toBe([]);
});

it('un meuble VIDE mis en pièces ne rend plus de dernière fouille : il n\'a plus de fond', function () {
    $ctx = contenuFiniScene('Armoire', null, ['fouille' => true]);

    $methode = new ReflectionMethod(ResolveurTour::class, 'fracasserMobilier');
    $methode->setAccessible(true);
    $payload = $methode->invoke(app(ResolveurTour::class), $ctx['groupe'], $ctx['quete'], $ctx['hero'], $ctx['etat'], 0);

    expect($payload['detruit'])->toBeTrue()
        ->and($payload)->not->toHaveKey('butin');
});

it('le fil nomme le meuble vidé avec son article', function () {
    $journal = app(JournalCombat::class);
    $texte = fn (string $meuble) => implode(' ¦ ', array_column($journal->depuisResultat(
        ['type' => 'fouille_mobilier', 'mobilier' => $meuble, 'issue' => 'rien', 'meuble_vide' => true], 'Albrecht'), 'texte'));

    expect($texte('Armoire'))->toContain("L'armoire est désormais vide")
        ->and($texte('Bibliothèque'))->toContain('La bibliothèque est désormais vide')
        ->and($texte('Établi d\'alchimiste'))->toContain("L'établi d'alchimiste est désormais vide")
        ->and($texte('Coffre'))->toContain('Le coffre est désormais vide');
});
