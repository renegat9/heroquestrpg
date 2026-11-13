<?php

declare(strict_types=1);

use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement as LigneJournal;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\ManoeuvreSolo;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/**
 * MODE ARÈNE — LA LETTRE DU LIVRET pour le combattant seul (F9528 p. 12-13 ; décision de René, 2026-11-12) : « In addition
 * to their own turn, they may take a basic maneuver after each opponent's turn… A combatant who becomes a solo combatant
 * maintains its activation token. » Le héros seul reçoit sa manœuvre après CHAQUE activation du Defender, qu'il ait déjà
 * joué la sienne ou non ; la manœuvre ne consomme pas son activation. Par les vraies routes (`GET /menu`, `POST /choix`).
 *
 * ⚠ Ce fichier REPRODUIT le défaut d'abord : avant le correctif, un héros seul face à une figure seule ne recevait
 * JAMAIS sa manœuvre (l'ouverture exigeait `a_joue`, et l'ouverture de round le remet à faux).
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

/**
 * Un combattant SEUL dès le premier round : une seule doublure (Aldra, barbare) engagée, contre `$defender`. Le Defender
 * ouvre donc le combat — et son activation d'ouverture est celle qui doit donner sa manœuvre au héros.
 *
 * @param  list<array{0: string, 1: int}>  $conditions  couples (nom de condition, durée) posés sur le héros avant le round
 * @return array{arene: Groupe, quete: \App\Models\Quete, alice: mixed, a: \App\Models\Personnage}
 */
function lettreCombatSeul(array $defender, array $conditions = []): array
{
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();

    $alice = connecterJoueur('alice');
    $ha = \App\Models\Personnage::findOrFail(test()->postJson('/api/personnages', ['nom' => 'Aldra', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $a = $arenes->engager($arene, $ha);

    // Conditions posées AVANT l'ouverture du round : la porte d'ouverture les lit déjà.
    foreach ($conditions as [$nom, $duree]) {
        $a->conditions()->attach(Condition::where('nom', $nom)->value('id'), ['duree' => $duree, 'source' => 'test']);
    }

    $ids = array_map(fn (string $nom) => (int) Monstre::where('nom_base', $nom)->value('id'), $defender);
    $quete = app(DemarreurArene::class)->demarrer($arene, $ids);

    return ['arene' => $arene->refresh(), 'quete' => $quete->refresh(), 'alice' => $alice, 'a' => $a];
}

/** L'état de quête du héros de `$c`, relu en base à chaque appel. */
function lettreEtat(array $c): EtatPersonnageQuete
{
    return EtatPersonnageQuete::where('quete_id', $c['quete']->id)->where('personnage_id', $c['a']->id)->firstOrFail();
}

/** Combien d'ouvertures (`geste`) de manœuvre le journal porte pour ce groupe. */
function lettreManoeuvres(int $groupeId, string $geste): int
{
    return LigneJournal::where('groupe_id', $groupeId)->get()
        ->filter(fn ($l) => ($l->payload['type'] ?? null) === 'manoeuvre_solo' && ($l->payload['geste'] ?? null) === $geste)
        ->count();
}

/** Les volées de dés d'une réponse, à n'importe quelle profondeur. */
function lettreVolees(array $donnees): array
{
    $volees = [];
    $parcourir = function (array $noeud) use (&$parcourir, &$volees): void {
        if (isset($noeud['faces_attaque']) && is_array($noeud['faces_attaque'])) {
            $volees[] = $noeud['faces_attaque'];
        }
        foreach ($noeud as $enfant) {
            if (is_array($enfant)) {
                $parcourir($enfant);
            }
        }
    };
    $parcourir($donnees);

    return $volees;
}

/** Le menu courant du héros de `$c` (le cache est jeté : chaque test lit l'état qu'il vient de poser). */
function lettreMenu(array $c): array
{
    test()->actingAs($c['alice'], 'joueur');

    return test()->getJson("/api/groupes/{$c['arene']->identifiant}/menu")->assertOk()->json('menu');
}

it('1 CONTRE 1 : la figure seule ouvre le round, et le héros seul reçoit SA manœuvre avant d\'avoir joué son activation', function () {
    $c = lettreCombatSeul(['Gobelin']);
    $id = $c['arene']->identifiant;

    // Le Defender ouvre le round : le héros, seul, manœuvre — il n'a pas encore joué son activation.
    expect(lettreEtat($c)->manoeuvre_arene)->toBeTrue()
        ->and(lettreEtat($c)->a_joue)->toBeFalse();

    $menu = lettreMenu($c);
    expect(array_diff(array_column($menu['options'], 'type'), ManoeuvreSolo::TYPES_PERMIS))->toBe([])
        ->and($menu['situation'])->toContain('manœuvre');

    // Il la passe : son menu NORMAL revient, et son activation est toujours à jouer.
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(lettreEtat($c)->manoeuvre_arene)->toBeFalse()
        ->and(lettreEtat($c)->a_joue)->toBeFalse();

    $normal = lettreMenu($c);
    expect($normal['situation'])->not->toContain('manœuvre')
        ->and(array_column($normal['options'], 'type'))->toContain('deplacement')
        ->and(array_column($normal['options'], 'type'))->not->toContain(ManoeuvreSolo::TYPE_ATTAQUE);

    // Il joue son activation : la figure répond par SA manœuvre, le round se rouvre, et le héros reçoit de nouveau la sienne.
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(lettreManoeuvres($c['arene']->id, 'defender'))->toBe(1)
        ->and(lettreEtat($c)->a_joue)->toBeFalse()
        ->and(lettreEtat($c)->manoeuvre_arene)->toBeTrue();
});

it('1 CONTRE 1 : un pas fait en manœuvre ne consomme ni l\'activation ni le déplacement du tour', function () {
    $c = lettreCombatSeul(['Gobelin']);
    $id = $c['arene']->identifiant;

    $menuManoeuvre = lettreMenu($c);
    $portee = collect($menuManoeuvre['options'])->firstWhere('id', 'se_deplacer')['parametres'];
    $destination = collect($portee['destinations'])->firstWhere('cout', 1);
    expect($destination)->not->toBeNull();

    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => $destination['x'], 'y' => $destination['y']]])
        ->assertSuccessful();
    expect(lettreEtat($c)->manoeuvre_arene)->toBeTrue()
        ->and(lettreEtat($c)->a_joue)->toBeFalse();

    // Terminer la manœuvre : le déplacement de la manœuvre retombe, l'activation repart de zéro.
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(lettreEtat($c)->manoeuvre_arene)->toBeFalse()
        ->and(lettreEtat($c)->a_joue)->toBeFalse()
        ->and(lettreEtat($c)->a_deplace)->toBeFalse()
        ->and(lettreEtat($c)->deplacement_restant)->toBeNull();

    $normal = collect(lettreMenu($c)['options'])->firstWhere('id', 'se_deplacer')['parametres'];
    // La portée rendue à l'activation est celle de la manœuvre d'avant le pas : l'allonce entière, pas le reliquat.
    expect($normal['portee'])->toBe($portee['portee']);
});

it('1 CONTRE 1, AU CONTACT : la manœuvre d\'avant l\'activation frappe à 2 dés, et l\'activation reste à jouer', function () {
    $c = lettreCombatSeul(['Gobelin']);
    $id = $c['arene']->identifiant;
    $gobelin = InstanceMonstre::where('quete_id', $c['quete']->id)->firstOrFail();
    $gobelin->update(['position_x' => lettreEtat($c)->position_x + 1, 'position_y' => lettreEtat($c)->position_y]);
    Illuminate\Support\Facades\Cache::forget(App\Jobs\GenererMenu::cleMenu($c['arene']->id, (int) $c['alice']->id));

    $frappe = collect(lettreMenu($c)['options'])->firstWhere('type', ManoeuvreSolo::TYPE_ATTAQUE);
    expect($frappe)->not->toBeNull()
        ->and(collect($frappe['parametres']['cibles'])->pluck('id')->all())->toContain($gobelin->id);

    desFiges(array_fill(0, 200, 4));
    $reponse = $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'manoeuvre_attaquer', 'parametres' => ['cible_id' => $gobelin->id]])
        ->assertSuccessful();

    expect(lettreVolees($reponse->json('resultat') ?? [])[0] ?? null)->toHaveCount(ManoeuvreSolo::DES_ATTAQUE)
        ->and(lettreEtat($c)->manoeuvre_arene)->toBeFalse()
        ->and(lettreEtat($c)->a_joue)->toBeFalse();
});

it('CONTRE DEUX FIGURES : le héros qui a joué garde son activation, et la manœuvre qui suit ne la lui retire pas', function () {
    $c = lettreCombatSeul(['Gobelin', 'Gobelin']);
    $id = $c['arene']->identifiant;

    // Round 1 : la première figure ouvre, le héros manœuvre (il passe), joue son activation ; la seconde répond.
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(lettreEtat($c)->manoeuvre_arene)->toBeFalse();

    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(lettreEtat($c)->a_joue)->toBeTrue()
        ->and(lettreEtat($c)->manoeuvre_arene)->toBeTrue();

    $menu = lettreMenu($c);
    expect(array_diff(array_column($menu['options'], 'type'), ManoeuvreSolo::TYPES_PERMIS))->toBe([]);

    // Il passe la manœuvre : son activation, déjà jouée, le reste — et le round se rouvre, la première figure ouvre.
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(lettreEtat($c)->manoeuvre_arene)->toBeTrue()
        ->and(lettreEtat($c)->a_joue)->toBeFalse();
});

it('un héros ÉTOURDI, dont le tour est perdu ce round, ne reçoit pas de manœuvre', function () {
    $c = lettreCombatSeul(['Gobelin']);
    $id = $c['arene']->identifiant;

    // Étourdi posé avant la fin du round 1 : `ouvrirTourDesHeros()` le consomme à l'ouverture du round suivant.
    $c['a']->conditions()->attach(Condition::where('nom', 'Étourdi')->value('id'), ['duree' => 0, 'source' => 'sort_dread:Tourmente']);

    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();

    // La trace du journal, dans l'ordre : entre l'Étourdi consommé et le round suivant, la figure joue — sans manœuvre.
    $trace = LigneJournal::where('groupe_id', $c['arene']->id)->orderBy('sequence')->get()
        ->map(fn ($l) => ($l->payload['type'] ?? null) === 'manoeuvre_solo' ? 'manoeuvre'
            : (($l->payload['type'] ?? null) === 'tour_perdu' ? 'tour_perdu'
            : (($l->payload['action'] ?? null) === 'nouveau_tour' ? 'nouveau_tour' : null)))
        ->filter()->values()->all();

    $perdu = array_search('tour_perdu', $trace, true);
    expect($perdu)->not->toBeFalse();

    $tours = array_keys(array_filter($trace, fn (string $t, int $i) => $t === 'nouveau_tour' && $i > $perdu, ARRAY_FILTER_USE_BOTH));
    expect(count($tours))->toBeGreaterThanOrEqual(2);

    $entreLesDeux = array_slice($trace, $tours[0] + 1, $tours[1] - $tours[0] - 1);
    expect($entreLesDeux)->not->toContain('manoeuvre');

    // Le round d'après, le héros a son tour : il reçoit enfin sa manœuvre.
    expect(lettreEtat($c)->manoeuvre_arene)->toBeTrue();
});

it('1 CONTRE 1 : la frappe de manœuvre qui achève la dernière figure donne la victoire, sans relais du Defender', function () {
    $c = lettreCombatSeul(['Gobelin']);
    $id = $c['arene']->identifiant;
    $gobelin = InstanceMonstre::where('quete_id', $c['quete']->id)->firstOrFail();
    $gobelin->update(['position_x' => lettreEtat($c)->position_x + 1, 'position_y' => lettreEtat($c)->position_y, 'pv_body' => 1]);
    Illuminate\Support\Facades\Cache::forget(App\Jobs\GenererMenu::cleMenu($c['arene']->id, (int) $c['alice']->id));

    $frappe = collect(lettreMenu($c)['options'])->firstWhere('type', ManoeuvreSolo::TYPE_ATTAQUE);
    expect($frappe)->not->toBeNull();

    desFiges(array_fill(0, 200, 1)); // tout crâne : la figure à 1 PV tombe
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'manoeuvre_attaquer', 'parametres' => ['cible_id' => $gobelin->id]])
        ->assertSuccessful();

    // La manœuvre se referme, la figure est morte : le combat est gagné, et le Defender n'a pas à répondre.
    expect($c['quete']->refresh()->etat)->toBe('terminee')
        ->and(lettreEtat($c)->manoeuvre_arene)->toBeFalse()
        ->and(lettreManoeuvres($c['arene']->id, 'defender'))->toBe(0);
});

it('1 CONTRE 1 : un héros PARALYSÉ, privé d\'action, ne reçoit pas de manœuvre — pas même une frappe à 2 dés', function () {
    $c = lettreCombatSeul(['Gobelin'], [['Paralysé', 3]]);

    // La figure seule ouvre le round : le héros paralysé ne peut ni agir ni frapper — aucune manœuvre ne s'ouvre.
    expect(lettreEtat($c)->manoeuvre_arene)->toBeFalse()
        ->and(lettreManoeuvres($c['arene']->id, 'ouverte'))->toBe(0);

    $menu = lettreMenu($c);
    expect(array_column($menu['options'], 'type'))->not->toContain(ManoeuvreSolo::TYPE_ATTAQUE)
        ->and($menu['situation'])->not->toContain('manœuvre');
});

it('POTIONS PENDANT LA MANŒUVRE : la potion de trophée et une potion du sac sont offertes, pas un objet « à l\'action », et boire ne referme pas la manœuvre', function () {
    // René, 2026-11-12 : boire ne coûte pas l'action — ce n'est pas un geste de manœuvre. Avant, le menu forcé ne gardait
    // que se déplacer / frapper à 2 dés / élan / passer : ni la potion de trophée, ni celles du sac.
    $c = lettreCombatSeul(['Orque']);
    $id = $c['arene']->identifiant;
    $c['a']->update(['pv_body' => max(1, (int) $c['a']->pv_body_max - 3)]);
    $c['quete']->update(['trophees_arene' => [...(array) $c['quete']->trophees_arene, \App\Partie\Arene\TropheesArene::CHALLENGER => [\App\Partie\Arene\TropheesArene::POTION]]]);
    foreach (['Potion de soin', 'Eau bénite'] as $nom) {
        \App\Models\Inventaire::create(['personnage_id' => $c['a']->id, 'objet_id' => \App\Models\Objet::where('nom', $nom)->value('id'), 'emplacement' => 'consommable', 'quantite' => 1]);
    }

    expect(lettreEtat($c)->manoeuvre_arene)->toBeTrue();
    // La scène est posée à la main APRÈS le démarrage : le menu calculé alors est jeté, comme le ferait une mutation.
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleMenu($c['arene']->id, (int) $c['alice']->id));

    test()->actingAs($c['alice'], 'joueur');
    $options = collect(test()->getJson("/api/groupes/{$id}/menu")->json('menu.options'));
    $objets = collect($options->firstWhere('id', 'utiliser_objet')['parametres']['objets'] ?? []);

    expect($options->pluck('id')->all())->toContain('trophee_potion')
        ->and($objets->pluck('nom')->all())->toContain('Potion de soin')
        ->and($objets->pluck('nom')->all())->not->toContain('Eau bénite');

    // Boire la potion de trophée : +2 PV, et la manœuvre reste ouverte.
    $avant = (int) $c['a']->fresh()->pv_body;
    test()->postJson("/api/groupes/{$id}/choix", ['option_id' => 'trophee_potion'])->assertSuccessful();
    expect((int) $c['a']->fresh()->pv_body)->toBe($avant + 2)
        ->and(lettreEtat($c)->manoeuvre_arene)->toBeTrue();

    // L'eau bénite, forgée, est refusée par la liste blanche de l'option.
    $eau = \App\Models\Inventaire::where('personnage_id', $c['a']->id)->whereHas('objet', fn ($q) => $q->where('nom', 'Eau bénite'))->value('id');
    test()->getJson("/api/groupes/{$id}/menu")->assertOk();
    test()->postJson("/api/groupes/{$id}/choix", ['option_id' => 'utiliser_objet', 'parametres' => ['cle' => "objet:{$eau}"]])->assertUnprocessable();
    expect(lettreEtat($c)->manoeuvre_arene)->toBeTrue();
});
