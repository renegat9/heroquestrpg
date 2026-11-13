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
 * SPELL SCROLL — CHILL (The Frozen Horror, Artifact Reference p. 35 — `reference/18_extensions.md` §3), PORTÉE le
 * 2026-11-13 : « This spell causes 1 Body Point of damage to any monster adjacent to the spellcaster (though not
 * diagonally adjacent). The victim cannot defend against the attack. Scroll crumbles to dust once used. »
 *
 * Décisions de René (lot 0, 2026-11-13) : TOUS les monstres des quatre cases orthogonales ; difficulté de lecture 2.
 * Le parchemin se lit par la VRAIE route (`/menu`, `/choix`) : la zone EST la cible, donc aucune sous-liste de cibles,
 * et une entrée n'est offerte que si au moins une figure y est (`touches` publié, la décision du serveur).
 *
 * Les dés ne servent qu'au jet de Mind d'un non-lanceur : la frappe elle-même n'en lance aucun (montant fixe, pas de
 * défense). Chaque assertion sur un PV le dit donc sans dé.
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
 * Une quête sur un plateau 7×7, UNE salle (tout le plateau), le héros au centre (3,3).
 *
 * `$figures` : `[nom_base, x, y, pv]` révélées et actives. Par défaut : un Gobelin en (4,3) et un Orque en (3,2), tous deux
 * ORTHOGONAUX, et un Gobelin en (4,4), DIAGONAL. Les figures du départ de la quête sont mises hors jeu.
 *
 * `$avecCompagne` : une seconde héroïne (Brunhilde) en (2,3), à gauche du lanceur — orthogonale, donc dans la zone.
 *
 * @param  list<array{0: string, 1: int, 2: int, 3: int}>|null  $figures
 * @return array{alice: JoueurAuthentifiable, groupe: \App\Models\Groupe, heros: Personnage, quete: Quete, etat: EtatPersonnageQuete, figures: list<InstanceMonstre>, compagne: ?Personnage}
 */
function morsureScene(string $classe = 'barbare', ?array $figures = null, bool $avecCompagne = false): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => $classe]);

    // La compagne entre AVANT le départ de la quête : sans état de quête, elle ne serait pas une figure du plateau.
    $compagne = null;

    if ($avecCompagne) {
        $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
        $compagne = creerHeros($bob, $groupe, 'Brunhilde', 2);
    }

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();

    $taille = 7;
    $quete->carte->update([
        'largeur' => $taille,
        'hauteur' => $taille,
        'grille' => [
            'largeur' => $taille, 'hauteur' => $taille,
            'cases' => array_fill(0, $taille, array_fill(0, $taille, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $taille, 'hauteur' => $taille, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [], 'pieges' => [], 'mobilier' => [], 'terrain' => [], 'leviers' => [], 'epreuves' => [],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);
    $quete->update(['salles_decouvertes' => [0]]);

    $quete->instancesMonstres()->update(['etat' => 'vaincu', 'revele' => false]);

    $figures ??= [['Gobelin', 4, 3, 3], ['Orque', 3, 2, 3], ['Gobelin', 4, 4, 3]];
    $instances = [];

    foreach ($figures as [$nomBase, $x, $y, $pv]) {
        $instances[] = InstanceMonstre::create([
            'quete_id' => $quete->id,
            'monstre_id' => Monstre::where('nom_base', $nomBase)->firstOrFail()->id,
            'pv_body' => $pv, 'pv_body_max' => $pv, 'pv_mind' => 0,
            'etat' => 'actif', 'revele' => true,
            'position_x' => $x, 'position_y' => $y,
        ]);
    }

    $etat->update([
        'position_x' => 3, 'position_y' => 3,
        'a_joue' => false, 'a_deplace' => false, 'a_agi' => false, 'tombe' => false,
        'deplacement_restant' => null, 'capacites_tour' => null,
    ]);

    if ($compagne !== null) {
        EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $compagne->id)
            ->update(['position_x' => 2, 'position_y' => 3, 'tombe' => false]);
    }

    Cache::forget(GenererMenu::cleMenu($groupe->id, $alice->id));

    return compact('alice', 'groupe', 'heros', 'quete', 'etat', 'compagne') + ['figures' => $instances];
}

/** Donne le parchemin de Morsure du Froid au sac du héros. */
function donnerMorsure(Personnage $heros): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Parchemin : Morsure du Froid')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);
}

/** Le menu RÉEL du héros. @return list<array<string, mixed>> */
function menuMorsure(JoueurAuthentifiable $alice): array
{
    Cache::forget(GenererMenu::cleMenu(\App\Models\Groupe::where('identifiant', 'table-1')->value('id'), $alice->id));

    return test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options');
}

/** L'entrée de parchemin du menu pour CE sac, ou null si elle n'est pas offerte. @return array<string, mixed>|null */
function entreeMorsure(JoueurAuthentifiable $alice, int $inventaireId): ?array
{
    $options = collect(menuMorsure($alice));
    $parchemins = collect($options->firstWhere('id', 'lire_parchemin')['parametres']['parchemins'] ?? []);

    return $parchemins->firstWhere('cle', "parchemin:{$inventaireId}");
}

/** Lit le parchemin par la route de choix (la lecture, puis le résultat). */
function lireMorsure(Inventaire $ligne)
{
    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => "parchemin:{$ligne->id}"],
    ]);
}

// ==================================================================
// LE CATALOGUE ET LA CARTE
// ==================================================================

it('seed la Morsure du Froid : parchemin (aucune école), zone de contact, un point fixe, sans défense, nature froid', function () {
    $sort = Sort::where('nom', 'Morsure du Froid')->firstOrFail();

    expect($sort->element)->toBe('parchemin')
        ->and($sort->type)->toBe('degats')
        ->and($sort->difficulte_parchemin)->toBe(2)
        ->and($sort->effet)->toMatchArray([
            'cible' => 'monstres_zone',
            'zone' => 'contact',
            'degats_fixes' => 1,
            'defense_applicable' => false,
            'type_degat' => 'froid',
        ]);
});

it('dérive un « Parchemin : Morsure du Froid » au sac, comme tout parchemin', function () {
    $objet = Objet::where('nom', 'Parchemin : Morsure du Froid')->firstOrFail();

    expect($objet->categorie)->toBe('parchemin')
        ->and($objet->effet['sort_nom'])->toBe('Morsure du Froid')
        ->and($objet->effet['sort_id'])->toBe(Sort::where('nom', 'Morsure du Froid')->value('id'));
});

it('porte la carte Spell Scroll — Chill : elle désigne le sort, et plus aucune dette', function () {
    $carte = collect(config('cartes.parchemins.cartes'))->firstWhere('carte', 'Spell Scroll — Chill');

    expect($carte['sort'] ?? null)->toBe('Morsure du Froid')
        ->and(array_key_exists('manque', $carte))->toBeFalse();
});

it('la Morsure du Froid du HÉROS et la Morsure de Froid du DREAD sont deux sorts distincts, dans leurs deux tables', function () {
    $heros = Sort::where('nom', 'Morsure du Froid')->firstOrFail();
    $dread = SortDread::where('nom', 'Morsure de Froid')->firstOrFail();

    // Graphies voisines, sorts différents : le héros est un parchemin à une table de sorts, le MJ un sort de Dread.
    expect($heros->getTable())->toBe('sorts')
        ->and($dread->getTable())->toBe('sorts_dread')
        ->and($heros->nom)->not->toBe($dread->nom)
        ->and(data_get($dread->effet, 'zone'))->toBe('contact')
        ->and(data_get($dread->effet, 'type_degat'))->toBe('froid');
});

// ==================================================================
// EN JEU — l'offre, la frappe, le fil
// ==================================================================

it('est OFFERTE avec ses figures : les monstres orthogonaux, jamais le diagonal, et aucune cible à choisir', function () {
    $ctx = morsureScene();
    $ligne = donnerMorsure($ctx['heros']);

    $entree = entreeMorsure($ctx['alice'], $ligne->id);

    expect($entree)->not->toBeNull('la Morsure devrait être offerte : deux monstres sont à son contact')
        ->and($entree)->not->toHaveKey('cibles', 'la zone EST la cible : aucune liste de cibles à ouvrir')
        ->and($entree['nom'])->toContain('Morsure du Froid')
        ->and($entree['touches'])->toHaveCount(2)
        ->and(collect($entree['touches'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$ctx['figures'][0]->id, $ctx['figures'][1]->id])->sort()->values()->all())
        ->and($entree['touches'][0])->toMatchArray(['type' => 'monstre']);
});

it('n\'est PAS offerte sans figure à son contact : un seul monstre, en diagonale, ne suffit pas', function () {
    $ctx = morsureScene('barbare', [['Gobelin', 4, 4, 3]]);
    $ligne = donnerMorsure($ctx['heros']);

    expect(entreeMorsure($ctx['alice'], $ligne->id))->toBeNull('le menu ne propose pas un sort qui ne toucherait rien');
});

it('frappe CHAQUE monstre orthogonal d\'un point ; le diagonal ne perd rien ; le parchemin est consommé', function () {
    $ctx = morsureScene();
    $ligne = donnerMorsure($ctx['heros']);

    // Jet de Mind du barbare (difficulté 2, Mind 2) : deux crânes, succès. La frappe ne lance aucun dé.
    desFiges([1, 1]);

    $resultat = lireMorsure($ligne)->assertStatus(202)
        ->assertJsonPath('resultat.type', 'parchemin')
        ->assertJsonPath('resultat.lanceur_de_sorts', false)
        ->assertJsonPath('resultat.consomme', true)
        ->assertJsonPath('resultat.gaspille', false)
        ->assertJsonPath('resultat.zone', 'contact')
        ->json('resultat');

    [$gobelinHaut, $orque, $gobelinDiag] = $ctx['figures'];

    expect((int) $gobelinHaut->fresh()->pv_body)->toBe(2, 'le Gobelin en (4,3) perd 1 PV')
        ->and((int) $orque->fresh()->pv_body)->toBe(2, 'l\'Orque en (3,2) perd 1 PV')
        ->and((int) $gobelinDiag->fresh()->pv_body)->toBe(3, 'le Gobelin en DIAGONALE n\'est pas touché')
        ->and(Inventaire::find($ligne->id))->toBeNull('le parchemin est détruit à la lecture');

    // Le payload dit ce qui a été frappé, figure par figure, et rien d'autre.
    expect($resultat['sort'])->toMatchArray(['nom' => 'Morsure du Froid', 'element' => 'parchemin'])
        ->and(collect($resultat['resultats'])->pluck('cible.id')->sort()->values()->all())
        ->toBe(collect([$gobelinHaut->id, $orque->id])->sort()->values()->all())
        ->and(collect($resultat['resultats'])->every(fn ($r) => $r['degats'] === 1 && $r['cible']['type'] === 'monstre'))->toBeTrue()
        ->and(collect($resultat['resultats'])->every(fn ($r) => array_key_exists('pv_body_apres', $r)))->toBeTrue()
        ->and(collect($resultat['resultats'])->every(fn ($r) => ($r['cible_vaincue'] ?? null) === false))->toBeTrue();
});

it('ne lance AUCUN dé de frappe : la défense d\'une figure, qui aurait paré, n\'entre pas en jeu', function () {
    // Après le jet de Mind (deux crânes), la file porte deux boucliers NOIRS (6) : une défense du Gobelin les aurait
    // tous bloqués, et le PV resterait à 3. La Morsure ne défend pas, ne lance rien : le Gobelin perd son point.
    $ctx = morsureScene('barbare', [['Gobelin', 4, 3, 3]]);
    $ligne = donnerMorsure($ctx['heros']);

    desFiges([1, 1, 6, 6, 6, 6]);

    lireMorsure($ligne)->assertStatus(202);

    expect((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(2);
});

it('un MAGICIEN lit la Morsure sans jet de Mind (lanceur : le parchemin ne lui demande rien)', function () {
    $ctx = morsureScene('magicien');
    $ligne = donnerMorsure($ctx['heros']);

    $resultat = lireMorsure($ligne)->assertStatus(202)
        ->assertJsonPath('resultat.lanceur_de_sorts', true)
        ->json('resultat');

    expect($resultat)->not->toHaveKey('jet')
        ->and((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(2);
});

it('un héros ORTHOGONAL n\'est PAS touché : la Morsure vise les monstres, jamais les alliés', function () {
    $ctx = morsureScene('magicien', null, avecCompagne: true);
    $ligne = donnerMorsure($ctx['heros']);
    $pvAvant = (int) $ctx['compagne']->fresh()->pv_body;

    lireMorsure($ligne)->assertStatus(202);

    expect((int) $ctx['compagne']->fresh()->pv_body)->toBe($pvAvant, 'Brunhilde est orthogonale, et intacte')
        ->and((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(2, 'le monstre, lui, perd son point');
});

it('un monstre qui ne se laisse toucher que par le FEU (Kessandria) n\'est ni touché, ni publié', function () {
    // « Immune to all spells except fire spells » (Witch Lord, p. 25) : la Morsure est un sort de parchemin, pas de feu.
    $ctx = morsureScene('barbare', [['Kessandria', 4, 3, 3], ['Gobelin', 3, 2, 3]]);
    $ligne = donnerMorsure($ctx['heros']);

    $entree = entreeMorsure($ctx['alice'], $ligne->id);

    expect(collect($entree['touches'])->pluck('nom')->all())->toBe(['Gobelin'], 'Kessandria est exclue de la décision publiée');

    desFiges([1, 1]);
    $resultat = lireMorsure($ligne)->assertStatus(202)->json('resultat');

    expect((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(3, 'Kessandria garde ses 3 PV')
        ->and((int) $ctx['figures'][1]->fresh()->pv_body)->toBe(2)
        ->and(collect($resultat['resultats'])->pluck('cible.nom')->all())->toBe(['Gobelin']);
});

it('une figure qui tombe à 0 PV est dite vaincue, dans le payload comme dans le fil', function () {
    $ctx = morsureScene('barbare', [['Gobelin', 4, 3, 1]]);
    $ligne = donnerMorsure($ctx['heros']);

    desFiges([1, 1]);
    $resultat = lireMorsure($ligne)->assertStatus(202)->json('resultat');

    expect($resultat['resultats'][0]['cible_vaincue'])->toBeTrue()
        ->and($resultat['resultats'][0]['pv_body_apres'])->toBe(0);

    $fil = collect(app(JournalCombat::class)->depuisResultat($resultat, 'Albrecht'))->pluck('texte')->implode(' ¦ ');

    expect($fil)->toContain('Gobelin')->toContain('−1 PV')->toContain('tombe');
});

it('le fil dit CHAQUE figure touchée, en une phrase par figure', function () {
    $ctx = morsureScene();
    $ligne = donnerMorsure($ctx['heros']);

    desFiges([1, 1]);
    $resultat = lireMorsure($ligne)->assertStatus(202)->json('resultat');

    $lignes = collect(app(JournalCombat::class)->depuisResultat($resultat, 'Albrecht'))->pluck('texte');

    expect($lignes->implode(' ¦ '))->toContain('Morsure du Froid')->toContain('Gobelin')->toContain('Orque')
        ->and($lignes->filter(fn ($t) => str_contains($t, '−1 PV')))->toHaveCount(2, 'une ligne par figure frappée');
});

it('un Dispel du MJ (Gretzl, capacité) annule la Morsure qui la balaie : sort annulé, parchemin consommé, l\'autre figure intacte', function () {
    $ctx = morsureScene('barbare', [['Gretzl la Porte-Fléau', 4, 3, 5], ['Orque', 3, 2, 3]]);
    $ligne = donnerMorsure($ctx['heros']);

    desFiges([1, 1]);
    $resultat = lireMorsure($ligne)->assertStatus(202)->json('resultat');

    expect($resultat['sort_annule'])->toBeTrue()
        ->and($resultat['reactions_mj'][0]['mecanique'])->toBe(Mot::ANNULE_SORT_CIBLANT)
        ->and($resultat)->not->toHaveKey('resultats')
        ->and((int) $ctx['figures'][0]->fresh()->pv_body)->toBe(5, 'Gretzl n\'a rien perdu')
        ->and((int) $ctx['figures'][1]->fresh()->pv_body)->toBe(3, 'l\'Orque n\'a rien perdu : le sort est annulé en entier')
        ->and(Inventaire::find($ligne->id))->toBeNull('lancé donc consommé, même annulé');
});

it('une entrée OFFERTE par le menu est toujours acceptée par la route : aucun « Cible requise »', function () {
    $ctx = morsureScene();
    $ligne = donnerMorsure($ctx['heros']);

    expect(entreeMorsure($ctx['alice'], $ligne->id))->not->toBeNull();

    desFiges([1, 1]);

    lireMorsure($ligne)->assertStatus(202);
});

it('le fil dit DANS LE BON SENS une réduction de pièce ou de talent, et un bonus de dégâts, et annonce l\'absorption', function () {
    // Une zone de dégâts dont trois figures illustrent les trois décisions que le fil doit dire : un coup réduit par un
    // talent, un coup RENFORCÉ par la Puissance brute, un héros protégé. Le signe de la valeur décide du verbe.
    $fil = collect(app(JournalCombat::class)->depuisResultat([
        'type' => 'sort',
        'sort' => ['nom' => 'Morsure du Froid', 'element' => 'parchemin', 'type' => 'degats'],
        'zone' => 'contact',
        'resultats' => [
            [
                'cible' => ['type' => 'heros', 'id' => 2, 'nom' => 'Brunhilde'], 'degats' => 1, 'pv_body_apres' => 7,
                'cible_vaincue' => false,
                'modificateurs' => [['source' => 'Cuir tanné', 'valeur' => -1, 'sur' => 'degats']],
            ],
            [
                'cible' => ['type' => 'monstre', 'id' => 9, 'nom' => 'Gobelin'], 'degats' => 2, 'pv_body_apres' => 1,
                'cible_vaincue' => false,
                'modificateurs' => [['source' => 'Puissance brute', 'valeur' => 1, 'sur' => 'degats']],
            ],
            [
                'cible' => ['type' => 'heros', 'id' => 3, 'nom' => 'Lirel'], 'degats' => 0, 'pv_body_apres' => 8,
                'absorbe' => true,
            ],
        ],
    ], 'Aldric'))->pluck('texte')->all();

    expect($fil)->toContain('Brunhilde subit −1 PV')
        ->toContain('Brunhilde : Cuir tanné réduit le coup de 1')
        ->toContain('Gobelin subit −2 PV')
        ->toContain('Gobelin : Puissance brute ajoute 1 au coup')
        ->toContain("Lirel est protégé : Morsure du Froid ne l'atteint pas")
        ->and(collect($fil)->first())->toContain('3 figures');
});
