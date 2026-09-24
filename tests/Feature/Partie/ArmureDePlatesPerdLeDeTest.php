<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\ForgeAmelioration;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Forge;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ForgeAmeliorationSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * L'Armure de plates FAIT PERDRE LE DÉ (contrat, René 2026-09-24).
 *
 * Décision finale, sur la carte OFFICIELLE 2021 (« +2 dés de défense, mais 1
 * seul dé rouge de mouvement ») : au plateau un héros lance DEUX dés de
 * mouvement et la Plate Mail lui en retire UN ; chez nous (base de classe +
 * UN SEUL d6), retirer un dé retire LE SEUL dé — le porteur avance de sa BASE
 * SEULE. Remplace un premier arbitrage (`malus_deplacement: 2`, un chiffre
 * retranché du total) qui sortait en réalité de la conversion fan Sjeye, pas
 * de la carte.
 *
 * Le dé est quand même LANCÉ — Évanescence le lit, l'usure des Bottes
 * elfiques le lit, et le joueur doit voir ce qu'il aurait eu — seul son
 * résultat ne compte plus dans le total. Ce fichier fige : la face RÉELLE
 * toujours publiée, la survie du détail à une régénération de menu dans le
 * même tour, et les trois exemptions à la règle : Chevalier, Allégée (sur son
 * exemplaire), Armure de Borin (« unlike normal plate mail […] does not slow
 * down its wearer », LR p. 7).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([
        ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        ForgeAmeliorationSeeder::class,
    ]);
});

/** Équipe l'objet nommé directement dans le slot armure — on teste le
 *  déplacement, pas la maîtrise d'équipement (qui a ses propres tests). */
function equiperArmurePourAnnulation(Personnage $heros, string $nomObjet): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => 'armure',
        'quantite' => 1,
    ]);
}

/**
 * Démarre une quête pour un héros seul (deplacement_base = 4 par défaut) et
 * renvoie de quoi piloter son tour.
 *
 * @return array{alice: App\Auth\JoueurAuthentifiable, groupe: App\Models\Groupe, hero: Personnage, quete: Quete, etat: EtatPersonnageQuete}
 */
function demarrerPourAnnulation(string $classe = 'nain'): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => $classe]);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    // Tour « vierge » : le prochain appel au menu relance le dé du tour.
    $etat->update(['deplacement_tour' => null, 'detail_deplacement_tour' => null, 'a_joue' => false]);

    return compact('alice', 'groupe', 'hero', 'quete', 'etat');
}

/** Régénère le menu et renvoie l'option `se_deplacer` (`type: deplacement`). */
function optionDeplacementAnnulation(int $groupeId, int $joueurId, int $heroId): array
{
    GenererMenu::dispatchSync($groupeId, $joueurId, $heroId);
    $menu = Cache::get(GenererMenu::cleMenu($groupeId, $joueurId))['menu'];

    return collect($menu['options'])->firstWhere('type', 'deplacement');
}

it('publie la face RÉELLE du dé sous une Armure de plates, et fait avancer de la BASE seule', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'hero' => $hero, 'etat' => $etat] = demarrerPourAnnulation();
    equiperArmurePourAnnulation($hero, 'Armure de plates');

    desFiges([6]); // un beau 6 : LANCÉ, mais qui ne doit compter pour rien.

    $dep = optionDeplacementAnnulation($groupe->id, (int) $alice->id, (int) $hero->id);

    expect($dep['parametres']['base'])->toBe(4)
        ->and($dep['parametres']['de'])->toBe(6)          // le dé réellement tombé
        ->and($dep['parametres']['des'])->toBe([6])
        ->and($dep['parametres']['de_annule'])->toBeTrue()
        ->and($dep['parametres']['de_annule_par'])->toBe('Armure de plates')
        ->and($dep['parametres']['portee'])->toBe(4)      // la BASE seule, le 6 ignoré
        ->and((int) $etat->fresh()->deplacement_tour)->toBe(4);
});

it('un dé fort ne rattrape rien : le total reste la base, quelle que soit la face', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'hero' => $hero, 'etat' => $etat] = demarrerPourAnnulation();
    equiperArmurePourAnnulation($hero, 'Armure de plates');

    desFiges([1]); // un jet faible : le résultat doit être IDENTIQUE au 6 ci-dessus.

    $dep = optionDeplacementAnnulation($groupe->id, (int) $alice->id, (int) $hero->id);

    expect($dep['parametres']['de'])->toBe(1)
        ->and($dep['parametres']['de_annule'])->toBeTrue()
        ->and($dep['parametres']['portee'])->toBe(4)
        ->and((int) $etat->fresh()->deplacement_tour)->toBe(4);
});

it('épargne au Chevalier la perte du dé en Armure de plates', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'hero' => $hero, 'etat' => $etat] = demarrerPourAnnulation('chevalier');
    equiperArmurePourAnnulation($hero, 'Armure de plates');

    desFiges([6]);

    $dep = optionDeplacementAnnulation($groupe->id, (int) $alice->id, (int) $hero->id);

    expect($dep['parametres']['de'])->toBe(6)
        ->and($dep['parametres']['de_annule'])->toBeFalse()
        ->and($dep['parametres']['de_annule_par'])->toBeNull()
        ->and($dep['parametres']['portee'])->toBe(10) // 4 + 6, le dé compte intégralement
        ->and((int) $etat->fresh()->deplacement_tour)->toBe(10);
});

it('une plate Allégée fait recompter le d6 SUR SON EXEMPLAIRE, sans jamais le nommer ailleurs', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'hero' => $hero, 'etat' => $etat] = demarrerPourAnnulation();
    $ligne = equiperArmurePourAnnulation($hero, 'Armure de plates');

    $groupe->update(['or' => 5000]);
    $amelioration = ForgeAmelioration::where('nom', 'Allégée')->firstOrFail();
    app(Forge::class)->appliquer($groupe->fresh(), $ligne, $amelioration);

    desFiges([6]);

    $dep = optionDeplacementAnnulation($groupe->id, (int) $alice->id, (int) $hero->id);

    expect($dep['parametres']['de'])->toBe(6)
        ->and($dep['parametres']['de_annule'])->toBeFalse()
        ->and($dep['parametres']['de_annule_par'])->toBeNull()
        ->and($dep['parametres']['portee'])->toBe(10);
});

it('Armure de Borin garde le dé — « unlike normal plate mail, does not slow down its wearer »', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'hero' => $hero, 'etat' => $etat] = demarrerPourAnnulation();
    equiperArmurePourAnnulation($hero, 'Armure de Borin');

    desFiges([6]);

    $dep = optionDeplacementAnnulation($groupe->id, (int) $alice->id, (int) $hero->id);

    expect($dep['parametres']['de'])->toBe(6)
        ->and($dep['parametres']['de_annule'])->toBeFalse()
        ->and($dep['parametres']['de_annule_par'])->toBeNull()
        ->and($dep['parametres']['portee'])->toBe(10);
});

it('le détail persisté survit à une régénération du menu dans le même tour', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'hero' => $hero, 'etat' => $etat] = demarrerPourAnnulation();
    equiperArmurePourAnnulation($hero, 'Armure de plates');

    desFiges([6]);
    $premier = optionDeplacementAnnulation($groupe->id, (int) $alice->id, (int) $hero->id);

    // Un second dé, prêt à être piochè SI le menu relançait : il ne doit
    // JAMAIS être servi. C'est tout le point de la colonne — sans elle, un
    // menu régénéré en cours de tour perdrait la face réelle du premier jet.
    desFiges([2]);
    $second = optionDeplacementAnnulation($groupe->id, (int) $alice->id, (int) $hero->id);

    expect($second['parametres'])->toBe($premier['parametres'])
        ->and($etat->fresh()->detail_deplacement_tour)->toBe([
            'base' => 4, 'des' => [6], 'de_annule' => true, 'de_annule_par' => 'Armure de plates',
        ]);
});

it('confronte `de_annule` à ce que Deplacement::calculer() fait réellement — dans les deux sens', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'hero' => $hero, 'etat' => $etat] = demarrerPourAnnulation();
    equiperArmurePourAnnulation($hero, 'Armure de plates');

    desFiges([5]);
    $dep = optionDeplacementAnnulation($groupe->id, (int) $alice->id, (int) $hero->id);

    // Sens 1 : la DÉCISION publiée EST celle que rend `Equipement`, jamais
    // une seconde décision prise ailleurs dans le menu.
    $annuleReel = app(Equipement::class)->deDeplacementAnnule($hero->fresh());
    expect($dep['parametres']['de_annule'])->toBe($annuleReel);

    // Sens 2 : quand le dé est annulé, le total écrit en base
    // (`deplacement_tour`) est EXACTEMENT la base — jamais la base ajustée du
    // dé publié, plancher à 1 — jamais un chiffre retranché du jet.
    $attendu = $dep['parametres']['de_annule']
        ? max(1, $dep['parametres']['base'])
        : max(1, $dep['parametres']['base'] + $dep['parametres']['des'][0]);
    expect((int) $etat->fresh()->deplacement_tour)->toBe($attendu);
});
