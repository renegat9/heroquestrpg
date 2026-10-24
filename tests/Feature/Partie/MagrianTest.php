<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
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
use Illuminate\Validation\ValidationException;

/*
 * MAGRIAN, LE DREAD WRAITH, ET LE DEFLECT DE GRUZBELLA (chantier EXT-MAGRIAN, 2026-10-23).
 *
 * EN JEU : chaque capacité est jouée par le VRAI moteur, les dés figés (`desFiges`), et déclenchée UNE fois puis
 * plus jamais. Les sorts du mage passent par la route `POST /choix` ; les coups et les dégâts par les méthodes
 * réelles (`ResolveurTour::frapper()`, `MoteurDegats::infligerAMonstre()`, `MoteurDread::realiteDecalee()`).
 * Sources : F6646 p. 33 (Magrian) et F9528 p. 25 (Gruzbella, Deflect).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/**
 * Une quête, un MAGE (feu + eau) et UN monstre du catalogue placé au contact du mage, révélé et actif. `$avecBob`
 * ajoute un second héros AVANT le lancement de la quête (son état de quête est alors créé avec les autres).
 *
 * @return array{alice: mixed, groupe: \App\Models\Groupe, mage: \App\Models\Personnage, bob: ?\App\Models\Personnage, quete: Quete, instance: InstanceMonstre, etat: EtatPersonnageQuete}
 */
function magrianDemarrer(string $nomMonstre = 'Magrian', bool $avecBob = false): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $mage = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);
    $bob = $avecBob ? creerHeros($alice, $groupe, 'Brunhild', 2, ['classe' => 'barbare']) : null;

    foreach (['feu', 'eau'] as $element) {
        app(MoteurSorts::class)->attacherElement($mage, $element);
    }

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $catalogue = Monstre::where('nom_base', $nomMonstre)->firstOrFail();
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update([
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'etat' => 'actif',
        'elite' => false,
        'habillage' => null,
        'capacites_reactives_utilisees' => null,
    ]);
    $instance->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $quete);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    return compact('alice', 'groupe', 'mage', 'bob', 'quete', 'instance', 'etat');
}

/** Un nouveau créneau d'action pour le mage, puis le menu régénéré. */
function magrianMenu(array $ctx): void
{
    $ctx['etat']->fresh()->update(['a_agi' => false, 'a_joue' => false]);
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['mage']->id);
}

/** Le mage lance `$sort` sur `$cible`, par la route réelle. */
function magrianLancer(array $ctx, string $sort, InstanceMonstre $cible)
{
    magrianMenu($ctx);

    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'lancer_sort', 'parametres' => [
        'cle' => 'sort:'.(int) Sort::where('nom', $sort)->value('id'),
        'cible_id' => $cible->id,
        'cible_type' => 'monstre',
    ]]);
}

/** Rend son sort au mage (un seul créneau d'action par tour : on rejoue le tour à la main). */
function magrianRendreLeSort(array $ctx, string $sort): void
{
    $ctx['mage']->sorts()->updateExistingPivot((int) Sort::where('nom', $sort)->value('id'), ['disponible' => true]);
}

/** Les capacités réactives déjà dépensées par l'instance, relues en base. */
function magrianDepensees(InstanceMonstre $instance): array
{
    return (array) $instance->fresh()->capacites_reactives_utilisees;
}

/** Le fil d'un résultat, d'un seul tenant. */
function magrianFil(array $resultat, string $acteur = 'Aldric'): string
{
    return collect(app(JournalCombat::class)->depuisResultat($resultat, $acteur))->pluck('texte')->implode(' ¦ ');
}

/** Ouvre le tour des héros (le début de tour où Terror se pose), par la méthode réelle du résolveur. */
function magrianOuvrirTour(array $ctx): void
{
    $methode = new ReflectionMethod(ResolveurTour::class, 'ouvrirTourDesHeros');
    $methode->setAccessible(true);
    $methode->invoke(app(ResolveurTour::class), $ctx['groupe'], $ctx['quete']);
}

// =====================================================================
// CONSUME MAGIC — « Use immediately after a hero casts a spell on their turn. The spell is canceled.
// Magrian regains 2 Body Points. » (F6646 p. 33)
// =====================================================================

it('CONSUME MAGIC : le sort qui frappe le camp du MJ est annulé, Magrian regagne 2 PV (plafonnés), une seule fois', function () {
    $ctx = magrianDemarrer();
    $ctx['instance']->update(['pv_body' => 3]);
    desFiges(array_fill(0, 300, 4));

    $premier = magrianLancer($ctx, 'Boule de Feu', $ctx['instance'])->assertStatus(202)->json('resultat');

    expect($premier['sort_annule'] ?? false)->toBeTrue()
        ->and($ctx['instance']->fresh()->pv_body)->toBe(5)
        ->and(magrianDepensees($ctx['instance']))->toContain('consomme_magie');
    expect(collect($premier['reactions_mj'] ?? [])->where('mecanique', 'consomme_magie')->first()['soin'] ?? null)->toBe(2);
    expect(magrianFil($premier))->toContain('regagne 2 PV');

    // Une seule fois : le second sort de la rencontre n'est plus annulé.
    magrianRendreLeSort($ctx, 'Boule de Feu');
    $second = magrianLancer($ctx, 'Boule de Feu', $ctx['instance'])->assertStatus(202)->json('resultat');

    expect($second['sort_annule'] ?? false)->toBeFalse()
        ->and(collect($second['reactions_mj'] ?? [])->pluck('mecanique')->all())->not->toContain('consomme_magie');
});

// =====================================================================
// REFLECTION — « Use immediately after Magrian receives damage. Choose a hero in Magrian's line of sight; that
// hero takes the damage instead. » (F6646 p. 33). Politique : au coup qui achèverait la figure.
// =====================================================================

it('REFLECTION : le coup qui achèverait Magrian retombe sur un héros en vue, Magrian ne perd rien, une seule fois', function () {
    $ctx = magrianDemarrer();
    $ctx['instance']->update(['pv_body' => 1]);
    $pvMage = (int) $ctx['mage']->fresh()->pv_body;
    $degats = app(MoteurDegats::class);

    $premier = $degats->infligerAMonstre($ctx['instance']->fresh(), 4, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['mage']);

    expect($premier['reaction'])->toBe('reflexion')
        ->and($premier['pv_body'])->toBe(1)
        ->and($premier['vaincu'])->toBeFalse()
        ->and($premier['reflexion_sur']['personnage_id'])->toBe((int) $ctx['mage']->id)
        ->and((int) $ctx['mage']->fresh()->pv_body)->toBeLessThan($pvMage)
        ->and(magrianDepensees($ctx['instance']))->toContain('reflexion');

    // Une seule fois : le coup suivant qui l'achève le tue.
    $second = $degats->infligerAMonstre($ctx['instance']->fresh(), 4, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['mage']);

    expect($second['reaction'] ?? null)->not->toBe('reflexion')
        ->and($second['vaincu'])->toBeTrue();
});

// =====================================================================
// TERROR — « Use at the start of any one hero's turn. For the remainder of that hero's turn, Magrian cannot
// be the target of spells or attacks. » (F6646 p. 33)
// =====================================================================

it('TERROR : posée au début du tour du héros qui le voit, elle le rend intouchable par ce héros, et tombe avec son tour', function () {
    $ctx = magrianDemarrer();

    magrianOuvrirTour($ctx);

    $vu = $ctx['instance']->fresh();

    expect(app(MoteurSorts::class)->terrorisePour($vu, $ctx['mage']))->toBeTrue()
        ->and($vu->reactionDisponible('terreur'))->toBeFalse();

    // Un coup du héros désigné est refusé au choke-point de la frappe, comme le menu l'a retiré de sa liste.
    expect(fn () => app(ResolveurTour::class)->frapper($ctx['groupe'], $ctx['quete'], $ctx['etat'], $ctx['mage'], $vu))
        ->toThrow(ValidationException::class);

    // La levée à la fin de SON tour (`marquerCreneau()` appelle `leverTerreurs()` pour ce héros).
    app(MoteurSorts::class)->leverTerreurs($ctx['quete'], $ctx['mage']);

    expect(app(MoteurSorts::class)->terrorisePour($ctx['instance']->fresh(), $ctx['mage']))->toBeFalse();
});

// =====================================================================
// SHIFT REALITY — « Use at the start of Zargon's turn. Magrian casts one Dread spell it knows. » (F6646 p. 33)
// =====================================================================

it('SHIFT REALITY : au début du tour du MJ, Magrian lance UN sort de son répertoire, une seule fois', function () {
    $ctx = magrianDemarrer();
    desFiges(array_fill(0, 300, 4));
    $cibles = $ctx['quete']->etatsPersonnages()->where('tombe', false)->with('personnage')->get();

    $gestes = app(MoteurDread::class)->realiteDecalee($ctx['groupe'], $ctx['quete'], $ctx['instance']->fresh(), $cibles);

    expect($gestes)->not->toBeEmpty()
        ->and($gestes[0]['type'])->toBe('reaction_monstre')
        ->and($gestes[0]['mecanique'])->toBe('realite_decalee')
        ->and(["Feux de l'Effroi", "Canaliser l'Effroi", 'Frayeur', 'Invocation de spectres'])->toContain($gestes[0]['sort'])
        ->and($ctx['instance']->fresh()->reactionDisponible('realite_decalee'))->toBeFalse();

    expect(app(MoteurDread::class)->realiteDecalee($ctx['groupe'], $ctx['quete'], $ctx['instance']->fresh(), $cibles))->toBe([]);
});

// =====================================================================
// DEFLECT (Gruzbella) — « Use when Gruzbella would be the target of an attack. You can instead direct the attack
// to any one hero in Gruzbella's surrounding 10 squares. » (F9528 p. 25). Politique : un coup qui POURRAIT
// achever la figure (dés d'attaque ≥ Body restant), vers un héros à 10 cases au plus, autre que l'auteur.
// =====================================================================

it('DEFLECT : un coup qui pourrait achever Gruzbella part vers un héros à 10 cases, qui répond avec SA défense ; une seule fois', function () {
    $ctx = magrianDemarrer('Gruzbella Hammerhand', true);
    $ctx['instance']->update(['pv_body' => 1]);

    $bob = $ctx['bob'];
    $etatBob = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $bob->id)->firstOrFail();
    $pres = caseAdjacenteLibre($ctx['quete'], (int) $ctx['instance']->position_x, (int) $ctx['instance']->position_y);
    $etatBob->update(['position_x' => $pres['x'], 'position_y' => $pres['y']]);

    desFiges(array_fill(0, 300, 4));

    $corps = app(ResolveurTour::class)->frapper($ctx['groupe'], $ctx['quete'], $ctx['etat'], $ctx['mage'], $ctx['instance']->fresh());

    expect($corps['type'])->toBe('attaque')
        ->and($corps)->toHaveKey('detournee_de')
        ->and($corps['cible']['personnage_id'])->toBe((int) $bob->id)
        ->and($corps['reaction_monstre'])->toBe('redirige_attaque')
        ->and($ctx['instance']->fresh()->pv_body)->toBe(1)
        ->and(magrianDepensees($ctx['instance']))->toContain('redirige_attaque');

    // Une seule fois : le coup suivant n'est plus détourné.
    $suivant = app(ResolveurTour::class)->frapper($ctx['groupe'], $ctx['quete'], $ctx['etat'], $ctx['mage'], $ctx['instance']->fresh());

    expect($suivant)->not->toHaveKey('detournee_de');
});

it('DEFLECT ne sort pas pour un coup qui ne pourrait pas achever la figure', function () {
    $ctx = magrianDemarrer('Gruzbella Hammerhand', true);
    $ctx['instance']->update(['pv_body' => 5]);

    $bob = $ctx['bob'];
    $etatBob = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $bob->id)->firstOrFail();
    $pres = caseAdjacenteLibre($ctx['quete'], (int) $ctx['instance']->position_x, (int) $ctx['instance']->position_y);
    $etatBob->update(['position_x' => $pres['x'], 'position_y' => $pres['y']]);

    desFiges(array_fill(0, 300, 4));

    // Le mage a moins de dés d'attaque que les 5 Body de Gruzbella : aucun détournement, la capacité reste intacte.
    $corps = app(ResolveurTour::class)->frapper($ctx['groupe'], $ctx['quete'], $ctx['etat'], $ctx['mage'], $ctx['instance']->fresh());

    expect($corps)->not->toHaveKey('detournee_de')
        ->and($ctx['instance']->fresh()->reactionDisponible('redirige_attaque'))->toBeTrue();
});

it('SHIFT REALITY est jouée par la PHASE DU MJ elle-même, et une Terreur qui survit à son tour tombe au début de cette phase', function () {
    $ctx = magrianDemarrer();
    desFiges(array_fill(0, 400, 4));

    // Une Terreur laissée par un tour mal fermé : le filet de `phaseMonstres()` la lève au début du tour du MJ.
    // La pose réelle (`ouvrirTourDesHeros()`) consomme la capacité : sans cela, la phase enchaîne sur le début de la phase
    // des héros suivante, qui la reposerait à juste titre et masquerait la levée du filet.
    app(MoteurSorts::class)->poserTerreur($ctx['instance']->fresh(), $ctx['mage']);
    $ctx['instance']->fresh()->consommerReaction('terreur');

    $methode = new ReflectionMethod(ResolveurTour::class, 'phaseMonstres');
    $methode->setAccessible(true);
    // `phaseMonstres()` rend `['actions' => [...]]` : c'est la liste des gestes du MJ, dans l'ordre où ils se jouent.
    $actions = (array) ($methode->invoke(app(ResolveurTour::class), $ctx['groupe'], $ctx['quete'])['actions'] ?? []);

    expect(collect($actions)->pluck('mecanique')->filter()->all())->toContain('realite_decalee')
        ->and(app(MoteurSorts::class)->terrorisePour($ctx['instance']->fresh(), $ctx['mage']))->toBeFalse();
});

it('TERROR tombe à la FIN DU TOUR du héros qui la subit (« Terminer le tour »), et pas avant', function () {
    $ctx = magrianDemarrer();
    magrianOuvrirTour($ctx);

    expect(app(MoteurSorts::class)->terrorisePour($ctx['instance']->fresh(), $ctx['mage']))->toBeTrue();

    // Le héros termine son tour par la vraie branche de fin de tour (`marquerCreneau(…, 'tour')`).
    $methode = new ReflectionMethod(ResolveurTour::class, 'marquerCreneau');
    $methode->setAccessible(true);
    $methode->invoke(app(ResolveurTour::class), $ctx['etat']->fresh(), 'tour');

    expect(app(MoteurSorts::class)->terrorisePour($ctx['instance']->fresh(), $ctx['mage']))->toBeFalse();
});

it('MENU : Magrian figure dans la liste d\'attaque AVANT Terror, et n\'y figure plus sous Terror (la liste de l\'option est la liste blanche)', function () {
    $ctx = magrianDemarrer();
    $menu = app(App\Partie\MenuMoteur::class);
    $methode = new ReflectionMethod(App\Partie\MenuMoteur::class, 'ciblesPourArme');
    $methode->setAccessible(true);
    $idsDeLAttaque = fn () => collect($methode->invoke($menu, $ctx['quete'], $ctx['etat']->fresh(), $ctx['mage']->fresh(), null)['attaquer'])
        ->pluck('id')->all();

    // Contrôle positif : sans Terror, Magrian est bien une cible du coup.
    expect($idsDeLAttaque())->toContain($ctx['instance']->id);

    magrianOuvrirTour($ctx);

    expect($idsDeLAttaque())->not->toContain($ctx['instance']->id);
});
