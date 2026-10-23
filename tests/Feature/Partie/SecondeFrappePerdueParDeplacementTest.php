<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\MoteurPotions;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * SECONDE FRAPPE PERDUE PAR LE DÉPLACEMENT (décision de René, 2026-10-22).
 *
 * « Une SECONDE frappe en attente fait partie de la MÊME action que la première : un héros
 * qui a déjà frappé peut encore se déplacer, mais bouger lui FAIT PERDRE la seconde frappe. »
 *
 * Ce qui est défendu, et seulement cela :
 *  - le MENU publie la décision (`perd_attaque_supplementaire`) sur chaque option de mouvement,
 *    et ne la publie vraie qu'une fois la frappe partie ;
 *  - le RÉSOLVEUR fait perdre la frappe au geste RÉUSSI, l'annonce au fil, et n'efface pas
 *    une seconde fois la gorgée en fin de tour ;
 *  - un déplacement refusé (422) ne fait rien perdre ;
 *  - la rage ne réarme pas la frappe perdue dans le même tour, et la réarme au tour suivant ;
 *  - la règle ne dépend pas de la SOURCE de la frappe armée (Fléau des Orques comme la gorgée).
 *
 * Toute la route passe par le MENU, comme la manette ; la carte est une salle ouverte à la
 * main, pour qu'un gobelin reste en vue quoi que fasse le héros.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : les parchemins dérivent des sorts.
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

/**
 * Une salle ouverte 7×7 (sol partout), Albrecht barbare en (3,3), une figure en (4,3).
 * Aucune porte ni mur : la figure reste EN VUE du héros quelle que soit sa case d'arrivée.
 *
 * @return array{alice: \App\Auth\JoueurAuthentifiable, groupe: \App\Models\Groupe, heros: \App\Models\Personnage,
 *     quete: Quete, etat: EtatPersonnageQuete, instance: InstanceMonstre}
 */
function sfpSalleOuverte(string $monstre = 'Gobelin', int $pvMonstre = 5): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'barbare']);

    $taille = 7;
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Salle ouverte', 'position_arc' => 1, 'type_jalon' => 'normale',
        'etat' => 'en_cours', 'or_initial' => 0,
    ]);
    Carte::create([
        'quete_id' => $quete->id, 'largeur' => $taille, 'hauteur' => $taille,
        'grille' => [
            'largeur' => $taille, 'hauteur' => $taille,
            'cases' => array_fill(0, $taille, array_fill(0, $taille, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $taille, 'hauteur' => $taille,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'mobilier' => [],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);
    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    $etat = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id, 'position_x' => 3, 'position_y' => 3,
    ]);

    $instance = InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => Monstre::where('nom_base', $monstre)->value('id'),
        'pv_body' => $pvMonstre, 'pv_body_max' => $pvMonstre, 'pv_mind' => 1,
        'position_x' => 4, 'position_y' => 3, 'etat' => 'actif', 'elite' => false, 'revele' => true,
    ]);

    return compact('alice', 'groupe', 'heros', 'quete', 'etat', 'instance');
}

/** Le menu du héros, RÉGÉNÉRÉ comme le fait la manette, puis lu dans le cache : situation et options. @return array<string, mixed> */
function sfpMenuComplet(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id));

    return (array) ($menu['menu'] ?? []);
}

/** Les options du menu, régénéré comme ci-dessus. @return list<array<string, mixed>> */
function sfpMenu(array $ctx): array
{
    return array_values((array) (sfpMenuComplet($ctx)['options'] ?? []));
}

/** @param  list<array<string, mixed>>  $options */
function sfpOption(array $options, string $id): ?array
{
    return collect($options)->firstWhere('id', $id);
}

/**
 * Une case de destination ORTHOGONALEMENT voisine de la figure : le héros peut encore la frapper
 * après s'y être déplacé (la mêlée exige le contact). @param  list<array<string, mixed>>  $options
 */
function sfpDestinationAuContact(array $options, InstanceMonstre $instance): array
{
    $cases = collect(sfpOption($options, 'se_deplacer')['parametres']['destinations'] ?? [])
        ->filter(fn ($d) => abs((int) $d['x'] - (int) $instance->position_x) + abs((int) $d['y'] - (int) $instance->position_y) === 1)
        ->values();

    expect($cases)->not->toBeEmpty('précondition : une case de destination touche la figure');

    return ['x' => (int) $cases[0]['x'], 'y' => (int) $cases[0]['y']];
}

/** Albrecht frappe la figure, le menu d'abord, comme la manette. @return array<string, mixed> */
function sfpFrapper(array $ctx): array
{
    sfpMenu($ctx);

    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');
}

/** Albrecht se déplace vers `$destination` (le menu d'abord). @return array<string, mixed> */
function sfpDeplacer(array $ctx, array $destination): array
{
    sfpMenu($ctx);

    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => $destination,
    ])->assertStatus(202)->json('resultat');
}

/** Pose une potion du sac et la boit (le chemin réel de `boire()`). */
function sfpBoire(array $ctx, string $potion): void
{
    $ligne = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', $potion)->firstOrFail()->id, 'emplacement' => 'sac',
    ]);

    app(MoteurPotions::class)->boire($ctx['heros'], $ligne->fresh());
}

/** Range un objet dans le sac du héros, sans l'utiliser. */
function sfpPoser(array $ctx, string $objet)
{
    return $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', $objet)->firstOrFail()->id, 'emplacement' => 'sac',
    ]);
}

/** Les annonces d'un type donné dans un résultat. */
function sfpAnnonces(array $resultat, string $type): \Illuminate\Support\Collection
{
    return collect($resultat['annonces_automatiques'] ?? [])->where('type', $type);
}

// =====================================================================
// LE PRÉDICAT — un seul, lu par le menu ET le résolveur
// =====================================================================

it('le prédicat « seconde frappe en attente » : frappé, tour pas fini, drapeau posé — et rien d\'autre', function (bool $agi, bool $joue, bool $drapeau, bool $attendu) {
    $etat = new EtatPersonnageQuete(['a_agi' => $agi, 'a_joue' => $joue, 'attaque_supplementaire' => $drapeau]);

    expect(ResolveurTour::secondeFrappeEnAttente($etat))->toBe($attendu);
})->with([
    'frappé, drapeau posé' => [true, false, true, true],
    'pas encore frappé : rien à perdre' => [false, false, true, false],
    'drapeau retombé' => [true, false, false, false],
    'tour fini' => [true, true, true, false],
]);

it('sans état de héros, aucune seconde frappe n\'est en attente', function () {
    expect(ResolveurTour::secondeFrappeEnAttente(null))->toBeFalse();
});

// =====================================================================
// BREUVAGE — frapper, puis bouger
// =====================================================================

it('Breuvage : frapper puis se déplacer fait perdre la seconde frappe — le menu le dit AVANT, le résultat l\'annonce, la fin du tour ne redit pas la gorgée', function () {
    $ctx = sfpSalleOuverte('Gobelin', 5);
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 4)); // boucliers blancs : la frappe manque, la figure tient

    sfpFrapper($ctx);

    expect((bool) $ctx['etat']->fresh()->a_agi)->toBeTrue('précondition : la première frappe a eu lieu')
        ->and((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('précondition : la seconde frappe est en attente');

    // Le MENU publie la décision AVANT le geste…
    $options = sfpMenu($ctx);
    expect(sfpOption($options, 'se_deplacer')['perd_attaque_supplementaire'] ?? null)
        ->toBeTrue('bouger fera perdre la seconde frappe : le menu le dit');

    // …et l'action d'attaque, elle, ne porte pas le champ (il ne décrit que les mouvements).
    $attaque = collect($options)->firstWhere('type', 'attaque');
    expect($attaque)->not->toBeNull('la seconde frappe est offerte')
        ->and(array_key_exists('perd_attaque_supplementaire', $attaque))->toBeFalse();

    // Le déplacement RÉSOLU fait perdre la frappe, et le fil le dit.
    $resultat = sfpDeplacer($ctx, sfpDestinationAuContact($options, $ctx['instance']));

    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeFalse('bouger a fait perdre la seconde frappe')
        ->and(sfpAnnonces($resultat, 'attaque_supplementaire_perdue'))->toHaveCount(1)
        ->and(sfpAnnonces($resultat, 'attaque_supplementaire_perdue')->first()['texte'])
        ->toBe('Albrecht se déplace : son attaque supplémentaire est perdue.');

    // La gorgée n'a plus de marque : la fin du tour ne doit pas la redire.
    $marques = collect($ctx['etat']->fresh()->capacites_tour ?? [])
        ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'breuvage:'));
    expect($marques)->toHaveCount(0, 'la marque du Breuvage est effacée au geste');

    // Plus aucune attaque offerte ce tour.
    expect(collect(sfpMenu($ctx))->where('type', 'attaque'))->toHaveCount(0);

    // Terminer le tour ne redit pas la gorgée : elle a déjà été dite, perdue au geste.
    sfpMenu($ctx);
    $fin = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat');

    expect(sfpAnnonces($fin, 'breuvage_dissipe'))->toHaveCount(0, 'pas de seconde annonce de la même gorgée');
});

it('Boire → se déplacer → frapper → frapper : toujours permis, et AUCUNE question avant la première frappe', function () {
    $ctx = sfpSalleOuverte('Gobelin', 5);
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 4));

    // Avant la première frappe, bouger ne fait rien perdre : le champ est FAUX.
    $options = sfpMenu($ctx);
    expect(sfpOption($options, 'se_deplacer')['perd_attaque_supplementaire'] ?? null)
        ->toBeFalse('avant la première frappe, rien ne se perd');

    $resultat = sfpDeplacer($ctx, sfpDestinationAuContact($options, $ctx['instance']));

    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('bouger avant de frapper ne consomme pas la gorgée')
        ->and(sfpAnnonces($resultat, 'attaque_supplementaire_perdue'))->toHaveCount(0);

    sfpFrapper($ctx); // la première frappe ne consomme pas la gorgée…
    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('la première frappe ne consomme pas la gorgée');

    sfpFrapper($ctx); // …c'est la seconde qui la dépense.
    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeFalse('la seconde frappe a eu lieu');
});

it('un déplacement REFUSÉ (422) ne fait perdre aucune frappe', function () {
    $ctx = sfpSalleOuverte('Gobelin', 5);
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 4));
    sfpFrapper($ctx);

    // Sur place : jamais une destination valide — le résolveur la refuse.
    sfpMenu($ctx);
    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => (int) $ctx['etat']->fresh()->position_x, 'y' => (int) $ctx['etat']->fresh()->position_y],
    ])->assertStatus(422);

    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('le refus n\'a rien fait perdre')
        ->and((bool) $ctx['etat']->fresh()->a_deplace)->toBeFalse('le refus n\'a rien consommé');

    // La seconde frappe est toujours là, et elle a lieu.
    sfpFrapper($ctx);
    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeFalse('la seconde frappe a eu lieu');
});

// =====================================================================
// RAGE GUERRIÈRE — la perte n'est pas réarmée dans le même tour
// =====================================================================

it('Rage guerrière : la frappe perdue par un déplacement n\'est PAS réarmée dans le même tour ; elle se réarme au tour suivant', function () {
    $ctx = sfpSalleOuverte('Gobelin', 5);
    sfpBoire($ctx, 'Potion de rage guerrière');
    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('précondition : la rage arme la seconde frappe');

    desFiges(array_fill(0, 80, 4));
    sfpFrapper($ctx);
    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('précondition : la rage survit à la première frappe');

    $resultat = sfpDeplacer($ctx, sfpDestinationAuContact(sfpMenu($ctx), $ctx['instance']));

    expect(sfpAnnonces($resultat, 'attaque_supplementaire_perdue'))->toHaveCount(1)
        ->and((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeFalse('bouger a fait perdre la seconde frappe');
    expect($ctx['instance']->fresh()->etat)->toBe('actif', 'précondition : la figure est toujours là, la rage ne tombe pas d\'elle-même');

    // Le menu est régénéré (le rythme de vue a lieu à chaque menu) : rien ne se réarme DANS ce tour.
    expect(collect(sfpMenu($ctx))->where('type', 'attaque'))->toHaveCount(0)
        ->and((bool) $ctx['etat']->fresh()->attaque_supplementaire)
        ->toBeFalse('la rage ne réarme pas la frappe perdue dans le même tour');

    // Tour suivant (créneaux remis à zéro, comme `ouvrirNouveauTour()`) : la rage réarme.
    $ctx['etat']->fresh()->update([
        'a_agi' => false, 'a_deplace' => false, 'a_joue' => false,
        'deplacement_tour' => null, 'deplacement_restant' => null,
    ]);
    sfpMenu($ctx);

    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('au tour suivant, la rage réarme la seconde frappe');
});

// =====================================================================
// UNE AUTRE SOURCE — le même drapeau, la même règle
// =====================================================================

it('une AUTRE source — le Fléau des Orques — obéit à la même règle : même perte, même annonce', function () {
    $ctx = sfpSalleOuverte('Orque', 20);
    $ligne = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Fléau des Orques')->firstOrFail()->id, 'emplacement' => 'sac', 'quantite' => 1,
    ]);
    app(Equipement::class)->equiper($ctx['heros'], $ligne->fresh(), 'arme_principale');

    desFiges(array_fill(0, 80, 1)); // crânes : chaque coup touche, l'Orque (20 PV) tient
    sfpFrapper($ctx);

    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)
        ->toBeTrue('précondition : le Fléau arme la seconde frappe contre un Orque');
    expect(sfpOption(sfpMenu($ctx), 'se_deplacer')['perd_attaque_supplementaire'] ?? null)->toBeTrue();

    $resultat = sfpDeplacer($ctx, sfpDestinationAuContact(sfpMenu($ctx), $ctx['instance']));

    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeFalse('bouger fait perdre la seconde frappe du Fléau')
        ->and(sfpAnnonces($resultat, 'attaque_supplementaire_perdue'))->toHaveCount(1);
});

// =====================================================================
// LE MENU DE LA SECONDE FRAPPE — il n'offre que ce que le résolveur accepte (2026-10-22)
// =====================================================================

it('sans cible à portée, la seconde frappe n\'est PAS annoncée comme offerte : la situation dit vrai, aucune frappe n\'est au menu', function () {
    // Une seule figure, à un PV : la première frappe l'abat, et il ne reste plus rien à frapper.
    $ctx = sfpSalleOuverte('Gobelin', 1);
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 1)); // crânes partout : la première frappe touche et tue

    sfpFrapper($ctx);

    expect($ctx['instance']->fresh()->etat)->toBe('vaincu', 'précondition : la première frappe a abattu la seule cible')
        ->and((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('précondition : la seconde frappe est en attente');

    $menu = sfpMenuComplet($ctx);

    expect($menu['situation'] ?? null)->toBe('Aucune cible à portée pour ton attaque supplémentaire.')
        ->and(collect($menu['options'])->where('type', 'attaque'))->toHaveCount(0, 'aucune frappe à offrir')
        ->and(sfpOption($menu['options'], 'attendre'))->not->toBeNull('le héros peut toujours terminer son tour');
});

it('une cible au contact : la seconde frappe est annoncée, son option est au menu, et le résolveur l\'accepte (non-régression)', function () {
    $ctx = sfpSalleOuverte('Gobelin', 5);
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 4)); // boucliers blancs : la frappe manque, la figure tient

    sfpFrapper($ctx);

    $menu = sfpMenuComplet($ctx);

    expect($menu['situation'] ?? null)->toBe('Une attaque supplémentaire vous est offerte ce tour.')
        ->and(sfpOption($menu['options'], 'attaquer')['type'] ?? null)->toBe('attaque');

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeFalse('la seconde frappe a eu lieu');
});

it('Frénésie du Berserker : la frappe balayée n\'est PAS offerte pendant la seconde frappe — une autre action, que le résolveur refuse', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker']);
    $ctx['heros']->update(['pv_body' => 3]); // seuil de la Frénésie : 3 PV de corps ou moins
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 4));

    expect(sfpOption(sfpMenu($ctx), 'frappe_balayee'))->not->toBeNull('précondition : la Frénésie existe avant la première frappe');

    sfpFrapper($ctx);
    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue('précondition : la seconde frappe est en attente');

    $options = sfpMenu($ctx);

    expect(sfpOption($options, 'frappe_balayee'))->toBeNull('la Frénésie ne se joue pas comme seconde frappe');
    expect(sfpOption($options, 'attaquer'))->not->toBeNull('la frappe à l\'arme, elle, reste offerte');

    // Forcée quand même, la Frénésie est refusée par la route (option absente du dernier menu).
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'frappe_balayee'])->assertStatus(422);

    // Chaque option d'ACTION encore au menu est une frappe : rien que le résolveur refuserait.
    collect($options)
        ->filter(fn (array $option) => ResolveurTour::creneauOption((string) $option['type']) === 'action')
        ->each(fn (array $option) => expect($option['type'])->toBe('attaque'));
});

it('Style du Feu du Moine : le rayon et le Brasier ne sont PAS offerts pendant la seconde frappe', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $ctx['etatHeros']->update(['styles_epuises' => ['air', 'terre', 'eau']]); // le Feu se déverrouille
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 4));

    $avant = collect(sfpMenu($ctx))->pluck('id');
    expect($avant->contains('toucher_brasier'))->toBeTrue('précondition : le Brasier existe avant la première frappe')
        ->and($avant->contains(fn (string $id) => str_starts_with($id, 'rayon_')))->toBeTrue('précondition : un rayon existe avant la première frappe');

    sfpFrapper($ctx);
    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue('précondition : la seconde frappe est en attente');

    $options = collect(sfpMenu($ctx));

    expect($options->pluck('id')->contains('toucher_brasier'))->toBeFalse('le Brasier est une autre action')
        ->and($options->pluck('id')->contains(fn (string $id) => str_starts_with($id, 'rayon_')))->toBeFalse('le rayon est une autre action')
        ->and($options->pluck('id')->contains('attaquer'))->toBeTrue('la frappe à l\'arme reste offerte');

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'toucher_brasier', 'parametres' => ['cible_id' => $ctx['instance']->id]])
        ->assertStatus(422);

    $options
        ->filter(fn (array $option) => ResolveurTour::creneauOption((string) $option['type']) === 'action')
        ->each(fn (array $option) => expect($option['type'])->toBe('attaque'));
});

it('invisible pendant la seconde frappe : pas de phrase « offerte », et la raison de l\'interdiction est celle du résolveur', function () {
    $ctx = sfpSalleOuverte('Gobelin', 5);
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 4));
    sfpFrapper($ctx);

    // « Caché » tombe sur le héros APRÈS sa première frappe : le filtre d'invisibilité de
    // `generer()` retire la frappe bonus, et la phrase ne doit pas promettre un bouton disparu.
    $ctx['heros']->conditions()->attach(Condition::where('nom', 'Caché')->value('id'), ['duree' => 1, 'source' => 'test']);

    $menu = sfpMenuComplet($ctx);

    expect($menu['situation'] ?? null)->toContain('invisible')
        ->and($menu['situation'] ?? null)->not->toContain('offerte')
        ->and(collect($menu['options'])->where('type', 'attaque'))->toHaveCount(0, 'aucune frappe à offrir');
});

it('seconde frappe en attente : « Utiliser un objet » reste offert — une potion de soin se boit, la frappe et l\'action ne bougent pas', function () {
    $ctx = sfpSalleOuverte('Gobelin', 5);
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 4));
    sfpFrapper($ctx);

    $ctx['heros']->update(['pv_body' => 3]);
    $soin = sfpPoser($ctx, 'Potion de soin');
    sfpPoser($ctx, 'Potion d\'héroïsme'); // une gorgée de plus au sac : refusée tant que la frappe est armée

    $entrees = collect(sfpOption(sfpMenu($ctx), 'utiliser_objet')['parametres']['objets'] ?? []);

    expect($entrees->firstWhere('cle', "objet:{$soin->id}"))->not->toBeNull('la potion de soin est offerte pendant la seconde frappe')
        ->and($entrees->where('nom', 'Potion d\'héroïsme'))->toHaveCount(0, 'la Potion d\'héroïsme reste refusée tant que la frappe est armée');

    $aAgiAvant = (bool) $ctx['etat']->fresh()->a_agi;

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$soin->id}", 'cible_id' => $ctx['heros']->id, 'cible_type' => 'heros'],
    ])->assertStatus(202);

    expect((int) $ctx['heros']->fresh()->pv_body)->toBeGreaterThan(3, 'le héros est soigné')
        ->and((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('boire ne consomme pas la seconde frappe')
        ->and((bool) $ctx['etat']->fresh()->a_agi)->toBe($aAgiAvant, 'boire ne change pas l\'action');
});

it('seconde frappe en attente : un objet dont le coût est l\'action (Bracelet de Guérison) n\'est PAS offert — l\'action est déjà jouée', function () {
    $ctx = sfpSalleOuverte('Gobelin', 5);
    $ctx['heros']->update(['pv_body' => 3]);
    $bracelet = sfpPoser($ctx, 'Bracelet de Guérison');
    sfpBoire($ctx, 'Potion d\'héroïsme');
    desFiges(array_fill(0, 80, 4));

    $offert = fn (): bool => collect(sfpOption(sfpMenu($ctx), 'utiliser_objet')['parametres']['objets'] ?? [])
        ->contains(fn (array $entree) => $entree['cle'] === "objet:{$bracelet->id}");

    expect($offert())->toBeTrue('précondition : le Bracelet est offert avant toute frappe');

    sfpFrapper($ctx);
    expect((bool) $ctx['etat']->fresh()->attaque_supplementaire)->toBeTrue('précondition : la seconde frappe est en attente');

    expect($offert())->toBeFalse('un objet qui coûte l\'action ne se joue pas une fois l\'action jouée');

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$bracelet->id}", 'cible_id' => $ctx['heros']->id, 'cible_type' => 'heros'],
    ])->assertStatus(422);
});
