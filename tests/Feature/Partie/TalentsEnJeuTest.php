<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Monstre;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\Marche\PhaseMarche;
use App\Partie\MoteurDegats;
use App\Partie\MoteurPieges;
use App\Partie\MoteurPortes;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use App\Partie\Talents;
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
 * LA PREUVE EN JEU — un cas par mécanique de la grille de talents, joué à
 * travers le vrai moteur et non contre le catalogue.
 *
 * Troisième verrou du « aucun talent décoratif » (les deux autres sont dans
 * `GrilleTalentsTest` : le texte affiché, et le registre confronté à ses
 * lecteurs). Précédent explicite du projet : `TraitsExtensionsTest` exerce les
 * traits d'extension EN PARTIE, pas dans la table — une donnée juste au
 * catalogue et jamais lue est exactement le défaut qu'on chasse.
 *
 * ⚠ Corollaire assumé : une mécanique sans cas ici n'a rien à faire dans le
 * seeder. Mieux vaut une case de grille qui reprend un effet déjà prouvé qu'un
 * talent qui ne fait rien — c'est ce qui était arrivé à une quinzaine de nœuds
 * des classes d'extension, câblés sur le NOM du nœud.
 *
 * Prouvées AILLEURS, et ce n'est pas un trou : `bonus_des_attaque_distance`
 * (AttaqueDistanceHerosTest), les passifs conditionnels, `avantage_jet_mind`,
 * `bonus_capacite_sac` et `relance_des_attaque_rates` (CompetencesEffetsTest),
 * leur recâblage nom → mécanique (TalentsRecablesTest).
 *
 * ⚠ COMPTER LES DÉS : `resoudreAttaqueMonstre()` ne publie pas le nombre de dés
 * lancés, seulement `touches` et `boucliers`. On fige donc les faces pour que
 * chaque dé compte — attaque toute en crânes (1-3), défense toute en boucliers
 * blancs (4-5) pour un héros — et le compte devient lisible.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/** Rend au héros ses deux créneaux : un test qui frappe deux fois en a besoin. */
function rejouerLeTour(array $ctx): void
{
    $ctx['etatHeros']->fresh()->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);
}

/** Joue une attaque au contact par la vraie route et rend le payload moteur. */
function frapperLeMonstre(array $ctx, ?array $des = null): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges($des ?? array_fill(0, 24, 4));

    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');
}

/**
 * Une attaque de MONSTRE sur un héros, jouée directement sur le résolveur.
 *
 * Le chemin public passerait par la phase des monstres, qui déplace, choisit sa
 * cible et peut ne pas frapper du tout : impossible d'y mesurer un dé près.
 */
function monstreFrappe(array $args, array $des): array
{
    desFiges($des);

    return (new ReflectionMethod(ResolveurTour::class, 'resoudreAttaqueMonstre'))
        ->invoke(app(ResolveurTour::class), ...$args);
}

// =====================================================================
// FRAPPE
// =====================================================================

it('bonus_des_attaque_contre_tier — le chevalier frappe plus fort les boss, jamais la piétaille', function () {
    // Gobelin : tier `base`. Le talent ne doit RIEN donner.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'chevalier', 'des_attaque' => 2]);
    donnerTalent($ctx['heros'], 'Charge du destrier');

    expect(frapperLeMonstre($ctx)['bonus_tier'])->toBe(0);

    // Le MÊME héros contre un boss : +1 dé. Le rang est lu au CATALOGUE, jamais
    // sur le nom affiché — l'habillage IA rebaptise les créatures, et « Le Noyé
    // de Gorrim » ne dit rien de son rang.
    $boss = Monstre::where('tier', 'boss')->firstOrFail();
    $ctx['instance']->update(['monstre_id' => $boss->id, 'pv_body' => $boss->pv_body]);
    $ctx['instance']->refresh()->load('monstre');
    rejouerLeTour($ctx);

    $resultat = frapperLeMonstre($ctx);

    expect($resultat['bonus_tier'])->toBe(1)
        ->and($resultat['des_attaque_effectifs'])->toBe(3);
});

it('bonus_des_attaque_apres_deplacement — l\'Élan ne vaut qu\'après une vraie course', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker', 'des_attaque' => 2]);
    donnerTalent($ctx['heros'], 'Élan');

    // Immobile : aucune case parcourue, aucun bonus.
    expect(frapperLeMonstre($ctx)['bonus_elan'])->toBe(0);

    // 3 cases parcourues sur les 6 lancées : le seuil de l'effet est atteint.
    rejouerLeTour($ctx);
    $ctx['etatHeros']->fresh()->update(['deplacement_tour' => 6, 'deplacement_restant' => 3, 'a_deplace' => true]);

    $resultat = frapperLeMonstre($ctx);

    expect($resultat['bonus_elan'])->toBe(1)
        ->and($resultat['des_attaque_effectifs'])->toBe(3);
});

it('ignore_defense_monstre — la Flèche perçante retire un dé au DÉFENSEUR', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'elfe', 'des_attaque' => 2]);
    $defense = (int) $ctx['instance']->monstre->defense;

    expect($defense)->toBeGreaterThan(0); // sinon le test ne prouverait rien

    // Un monstre pare sur BOUCLIER NOIR (6) ; le blanc (4) ne lui sert à rien.
    // Deux crânes, puis autant de 6 que le monstre lance de dés : sans le
    // talent il pare tout, avec le talent il lui manque un dé.
    $ctx['instance']->update(['pv_body' => 20]);
    $sans = frapperLeMonstre($ctx, [1, 1, ...array_fill(0, $defense, 6)]);

    expect($sans['defense_ignoree'])->toBe(0)
        ->and($sans['degats'])->toBe(max(0, 2 - $defense));

    donnerTalent($ctx['heros'], 'Flèche perçante');
    rejouerLeTour($ctx);

    $avec = frapperLeMonstre($ctx, [1, 1, ...array_fill(0, $defense, 6)]);

    expect($avec['defense_ignoree'])->toBe(1)
        ->and($avec['degats'])->toBe(max(0, 2 - ($defense - 1)));
});

it('attaque_supplementaire_apres_kill — abattre la cible rend l\'action, une fois par tour', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker', 'des_attaque' => 3]);
    donnerTalent($ctx['heros'], 'Soif de sang');
    $ctx['instance']->update(['pv_body' => 1]);

    // 1 crâne, aucune parade (boucliers blancs, sans effet pour un monstre).
    $resultat = frapperLeMonstre($ctx, [1, 4, 4, ...array_fill(0, 8, 4)]);

    expect($resultat['cible_vaincue'])->toBeTrue()
        ->and($resultat['attaque_supplementaire'] ?? false)->toBeTrue()
        ->and((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue()
        // ⚠ « Une fois par tour » : sans ce compteur, une chaîne de mises à mort
        // offrirait un tour infini — exactement le trou qu'avait eu
        // `bonus_des_attaque_flanc` avant d'avoir le sien.
        ->and((array) $ctx['etatHeros']->fresh()->capacites_tour)->toContain('Soif de sang');
});

it('inflige_condition_sur_touche — la Lame vénéneuse ralentit ce qu\'elle entame, jamais ce qu\'elle rate', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'rogue', 'des_attaque' => 3]);
    donnerTalent($ctx['heros'], 'Lame vénéneuse');

    $sorts = app(MoteurSorts::class);
    $defense = (int) $ctx['instance']->monstre->defense;
    $ctx['instance']->update(['pv_body' => 20]);

    // Coup entièrement PARÉ (boucliers noirs en face) : aucun dégât, aucun venin.
    $rate = frapperLeMonstre($ctx, [1, 4, 4, ...array_fill(0, $defense, 6)]);

    expect($rate['degats'])->toBe(0)
        ->and($sorts->monstreA($ctx['instance']->fresh(), MoteurSorts::MONSTRE_RALENTI))->toBeFalse();

    rejouerLeTour($ctx);

    // Coup qui PASSE : le monstre est ralenti.
    $touche = frapperLeMonstre($ctx, [1, 1, 1, ...array_fill(0, $defense, 4)]);

    expect($touche['degats'])->toBeGreaterThan(0)
        ->and($touche['condition_infligee'])->toBe(MoteurSorts::MONSTRE_RALENTI)
        ->and($sorts->monstreA($ctx['instance']->fresh(), MoteurSorts::MONSTRE_RALENTI))->toBeTrue();
});

// =====================================================================
// DÉGÂTS SUBIS
// =====================================================================

it('reduction_degats — le Cuir tanné retranche 1 à chaque coup, sans jamais passer sous zéro', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $degats = app(MoteurDegats::class);

    expect($degats->infligerAHeros($heros, 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE))->toBe(3);

    donnerTalent($heros, 'Cuir tanné');

    expect($degats->infligerAHeros($heros->refresh(), 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE))->toBe(2)
        // Un coup d'1 dégât est intégralement absorbé — plancher zéro.
        ->and($degats->infligerAHeros($heros->refresh(), 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE))->toBe(0)
        // ⚠ Le SACRIFICE reste plein tarif : une armure qui protège de sa propre
        // décision rendrait la Furie du Berserker gratuite.
        ->and($degats->infligerAHeros($heros->refresh(), 2, MoteurDegats::SOURCE_SACRIFICE))->toBe(2);
});

it('resistance_degats_type — la Chair impie annule le feu, et rien d\'autre', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'warlock']);
    $heros = $ctx['heros'];
    $sorts = app(MoteurSorts::class);

    expect($sorts->absorbeDegat($heros, 'feu'))->toBeFalse();

    donnerTalent($heros, 'Chair impie');

    expect($sorts->absorbeDegat($heros->refresh(), 'feu'))->toBeTrue()
        // ⚠ Aucune charge consommée : un talent permanent ne s'use pas, la
        // seconde lecture doit rendre la même réponse que la première.
        ->and($sorts->absorbeDegat($heros->refresh(), 'feu'))->toBeTrue()
        ->and($sorts->absorbeDegat($heros->refresh(), 'froid'))->toBeFalse();
});

// =====================================================================
// DÉFENSE
// =====================================================================

it('bonus_des_defense_contre_distance — l\'Esquive dansante ne vaut que contre un TIR', function () {
    $ctx = demarrerQueteAvecMonstre('Archer elfe', ['classe' => 'elfe', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Esquive dansante');

    $base = (int) $ctx['heros']->des_defense;
    $des = [1, 1, ...array_fill(0, 12, 4)]; // 2 crânes, puis des boucliers blancs

    // Corps-à-corps : le héros pare avec ses seuls dés.
    $melee = monstreFrappe(
        [$ctx['groupe'], $ctx['instance'], $ctx['etatHeros'], 2, [], 'Archer'], $des,
    );

    expect($melee['boucliers'])->toBe($base);

    // À DISTANCE : un dé de défense de plus.
    $tir = monstreFrappe(
        [$ctx['groupe'], $ctx['instance'], $ctx['etatHeros']->fresh(), 2, [], 'Archer', 'distance'], $des,
    );

    expect($tir['boucliers'])->toBe($base + 1);
});

it('bonus_des_defense_allie_adjacent — la Bannière protège les VOISINS, jamais son porteur', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $chevalier = creerHeros($alice, $groupe, 'Roland', 1, ['classe' => 'chevalier', 'pv_body_max' => 20, 'pv_body' => 20]);
    $compagnon = creerHeros($alice, $groupe, 'Albrecht', 2, ['pv_body_max' => 20, 'pv_body' => 20]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $etatChevalier = $quete->etatsPersonnages()->where('personnage_id', $chevalier->id)->firstOrFail();
    $etatCompagnon = $quete->etatsPersonnages()->where('personnage_id', $compagnon->id)->firstOrFail();

    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update(['revele' => true]);

    $base = (int) $compagnon->des_defense;
    $des = [1, 1, ...array_fill(0, 12, 4)];

    // Le chevalier est LOIN : aucun renfort.
    $etatChevalier->update(['position_x' => (int) $etatCompagnon->position_x + 5]);
    $seul = monstreFrappe([$groupe, $instance, $etatCompagnon->fresh(), 2, [], 'Gobelin'], $des);

    expect($seul['boucliers'])->toBe($base);

    donnerTalent($chevalier, 'Bannière');

    // Au CONTACT : +1 dé de défense pour le compagnon.
    $etatChevalier->update([
        'position_x' => (int) $etatCompagnon->position_x + 1,
        'position_y' => (int) $etatCompagnon->position_y,
    ]);
    $couvert = monstreFrappe([$groupe, $instance, $etatCompagnon->fresh(), 2, [], 'Gobelin'], $des);

    expect($couvert['boucliers'])->toBe($base + 1);

    // ⚠ Le porteur ne se couvre pas lui-même : ce serait un bonus de défense
    // ordinaire, et le joueur ne verrait aucune différence avec « Rempart ».
    $porteur = monstreFrappe([$groupe, $instance, $etatChevalier->fresh(), 2, [], 'Gobelin'], $des);

    expect($porteur['boucliers'])->toBe((int) $chevalier->des_defense);
});

it('malus_des_monstre_adjacent — le Regard qui glace retire un dé au monstre qui frappe', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'pv_body_max' => 20, 'pv_body' => 20]);

    // Attaque toute en crânes, défense toute en boucliers NOIRS (sans effet pour
    // un héros) : `touches` compte donc exactement les dés d'attaque lancés.
    $des = array_fill(0, 4, 1);
    $des = [...$des, ...array_fill(0, 12, 6)];

    $avant = monstreFrappe([$ctx['groupe'], $ctx['instance'], $ctx['etatHeros'], 3, [], 'Gobelin'], $des);

    expect($avant['touches'])->toBe(3);

    donnerTalent($ctx['heros'], 'Regard qui glace');

    $apres = monstreFrappe([$ctx['groupe'], $ctx['instance'], $ctx['etatHeros']->fresh(), 3, [], 'Gobelin'], $des);

    expect($apres['touches'])->toBe(2);
});

// =====================================================================
// MAGIE
// =====================================================================

it('bonus_degats_sort — la Puissance brute ajoute un dégât, mais jamais à un sort entièrement paré', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'magicien']);
    $heros = $ctx['heros'];
    $instance = $ctx['instance'];

    $boule = Sort::where('nom', 'Boule de Feu')->firstOrFail();
    $heros->sorts()->syncWithoutDetaching([$boule->id => ['disponible' => true]]);
    donnerTalent($heros, 'Puissance brute');

    // `sortDegats` valide sa cible contre la liste PUBLIÉE par l'option : c'est
    // elle la liste blanche depuis le ciblage en deux temps.
    $option = ['parametres' => ['cibles' => [
        ['type' => 'monstre', 'id' => (int) $instance->id, 'nom' => $instance->nomAffiche()],
    ]]];
    $parametres = ['cible_id' => (int) $instance->id, 'cible_type' => 'monstre'];

    // ⚠ Le résolveur est résolu APRÈS `desFiges()`, jamais avant : le lanceur
    // de dés est injecté à la CONSTRUCTION, et une instance obtenue plus tôt
    // garde le lanceur aléatoire — le test devient alors muet, ou pire, flottant.
    $methode = new ReflectionMethod(ResolveurTour::class, 'sortDegats');

    // ⚠ Réécrit le 2026-09-02 : Boule de Feu suit sa carte (doc 16 §3bis) —
    // dégâts FIXES, réduits par des d6 bruts, sans dés d'attaque ni parade.
    $fixes = (int) data_get($boule->effet, 'degats_fixes', 2);
    $desResistance = (int) data_get($boule->effet, 'des_resistance', 2);

    // Aucun 5/6 : le montant passe entier, bonus compris.
    $instance->update(['pv_body' => 40]);
    desFiges(array_fill(0, $desResistance, 2));
    $touche = $methode->invoke(app(ResolveurTour::class), $ctx['quete'], $boule, $option, $parametres, $heros->fresh());

    expect($touche['bonus_degats_sort'])->toBe(1)
        ->and($touche['degats'])->toBe($fixes + 1);

    // ⚠ RÉSISTANCE MAXIMALE, et c'est une conséquence assumée du modèle de la
    // carte : le bonus s'ajoute AVANT la réduction, donc les dés peuvent le
    // manger — mais ils sont en nombre fixe. Deux dés annulent au plus 2 points
    // sur 3, et le talent GARANTIT donc le dernier. Il n'y a plus de « sort
    // entièrement paré » dès qu'un bonus est en jeu ; l'ancienne version du test
    // exigeait 0, ce que la règle officielle ne permet plus.
    $instance->refresh();
    desFiges(array_fill(0, $desResistance, 6));
    $reduit = $methode->invoke(app(ResolveurTour::class), $ctx['quete'], $boule, $option, $parametres, $heros->fresh());

    expect($reduit['degats_annules'])->toBe($desResistance)
        ->and($reduit['degats'])->toBe(max(0, $fixes + 1 - $desResistance));
});

it('regain_sort — le Chant runique rend UN sort à chaque monstre abattu', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe', 'des_attaque' => 3]);
    $heros = $ctx['heros'];

    foreach (Sort::orderBy('id')->take(2)->get() as $sort) {
        $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => false]]);
    }

    donnerTalent($heros, 'Chant runique');
    $ctx['instance']->update(['pv_body' => 1]);

    frapperLeMonstre($ctx, [1, 4, 4, ...array_fill(0, 8, 4)]);

    // ⚠ UN seul sort rendu, et pas le grimoire entier : tout rendre à chaque
    // mise à mort supprimerait l'économie de sorts au lieu de l'assouplir.
    expect($heros->sorts()->wherePivot('disponible', true)->count())->toBe(1);
});

it('sacrifice_pv_pour_sort — le Prix du pacte paie 1 PV, et se refuse à 1 PV', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'warlock']);
    $heros = $ctx['heros'];
    $sorts = app(MoteurSorts::class);

    $sort = Sort::orderBy('id')->firstOrFail();
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => false]]);

    // Sans le talent : rien ne se passe, et surtout aucun PV n'est prélevé.
    expect($sorts->sacrifierPourUnSort($heros, $sort))->toBeFalse();

    donnerTalent($heros, 'Prix du pacte');
    $heros->update(['pv_body' => 5]);

    expect($sorts->sacrifierPourUnSort($heros->refresh(), $sort))->toBeTrue()
        ->and((int) $heros->refresh()->pv_body)->toBe(4)
        ->and((bool) $heros->sorts()->whereKey($sort->id)->first()->pivot->disponible)->toBeTrue();

    // ⚠ À 1 PV, le pacte se ferme : un talent d'appoint ne doit pas tuer son
    // porteur — et le menu ne le propose pas non plus.
    $heros->update(['pv_body' => 1]);
    $heros->sorts()->updateExistingPivot($sort->id, ['disponible' => false]);

    expect($sorts->sacrifierPourUnSort($heros->refresh(), $sort))->toBeFalse()
        ->and((int) $heros->refresh()->pv_body)->toBe(1);
});

// =====================================================================
// EXPLORATION
// =====================================================================

it('detection_portes_secretes — Parler à la pierre révèle la porte voisine, sans jet ni action', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain']);
    $carte = $ctx['quete']->carte;
    $portesMoteur = app(MoteurPortes::class);

    $index = null;
    foreach ($portesMoteur->portes($carte) as $i => $porte) {
        if (($porte['etat'] ?? null) === 'secrete') {
            $index = $i;
            break;
        }
    }

    // ⚠ Le générateur GARANTIT au moins une porte secrète par donjon
    // (`CouloirsTest`) : son absence serait une régression, pas un cas à ignorer.
    expect($index)->not->toBeNull();

    $porte = $portesMoteur->portes($carte)[$index];
    $x = (int) $porte['x'];
    $y = (int) $porte['y'];

    // Sans le talent : la porte reste cachée même à une case.
    expect($portesMoteur->detecterSecretesAdjacentes($ctx['groupe'], $carte->fresh(), $ctx['heros'], $x + 1, $y))
        ->toBe([]);

    donnerTalent($ctx['heros'], 'Parler à la pierre');

    $revelees = $portesMoteur->detecterSecretesAdjacentes(
        $ctx['groupe'], $carte->fresh(), $ctx['heros']->fresh(), $x + 1, $y,
    );

    expect($revelees)->not->toBeEmpty()
        ->and((bool) $portesMoteur->portes($carte->fresh())[$index]['revele'])->toBeTrue();
});

it('ignore_terrain_entravant — les Ronces complices ne coupent plus la course sur les chausse-trappes', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'druide']);
    // Même précaution que plus haut : `app()` APRÈS `desFiges()`.
    $methode = new ReflectionMethod(ResolveurTour::class, 'tronquerSurChausseTrappes');

    $depart = ['x' => (int) $ctx['etatHeros']->position_x, 'y' => (int) $ctx['etatHeros']->position_y];
    $piegee = ['x' => $depart['x'] + 1, 'y' => $depart['y']];
    $chemin = [$depart, $piegee, ['x' => $depart['x'] + 2, 'y' => $depart['y']]];

    $carte = $ctx['quete']->carte;
    $grille = $carte->grille;
    $grille['chausse_trappes'] = [$piegee];
    $carte->update(['grille' => $grille]);
    $quete = $ctx['quete']->fresh()->load('carte');

    // Sans le talent, un dé qui n'est pas un bouclier blanc coupe le trajet.
    desFiges(array_fill(0, 10, 1));
    expect($methode->invoke(app(ResolveurTour::class), $quete, $chemin, $ctx['heros']))->toHaveCount(2);

    donnerTalent($ctx['heros'], 'Ronces complices');

    desFiges(array_fill(0, 10, 1));
    expect($methode->invoke(app(ResolveurTour::class), $quete, $chemin, $ctx['heros']->fresh()))->toHaveCount(3);
});

it('fouille_supplementaire — le Fouineur fouille DEUX fois la même salle, les autres une seule', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'explorateur']);
    $quete = $ctx['quete'];
    $heros = $ctx['heros'];

    $quete->marquerTresorFouille(0, (int) $heros->id);

    // Sans le talent, une fouille suffit à fermer la salle pour ce héros.
    expect($quete->fresh()->aFouille(0, (int) $heros->id))->toBeTrue();

    donnerTalent($heros, 'Fouineur');

    // Avec : la salle se rouvre pour lui, une fois — et une seule.
    expect($quete->fresh()->aFouille(0, (int) $heros->id))->toBeFalse();

    $quete->fresh()->marquerTresorFouille(0, (int) $heros->id);

    expect($quete->fresh()->aFouille(0, (int) $heros->id))->toBeTrue();
});

it('relance_jet_mind_rate — l\'Esprit clair rejoue un jet manqué, une seule fois par quête', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine', 'attribut_mind' => 2]);
    donnerTalent($ctx['heros'], 'Esprit clair');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    // Premier jet raté (aucun crâne), relance gagnante (deux crânes).
    desFiges([4, 4, 1, 1, 4, 4, 4, 4]);

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)->json('resultat');

    // `succes` est un NOMBRE de réussites, pas un booléen.
    expect($resultat['jet_relance'] ?? null)->not->toBeNull()
        ->and($resultat['succes'])->toBeGreaterThan(0)
        // La ressource est dépensée : le nœud figure au compteur de quête.
        ->and((array) $ctx['etatHeros']->fresh()->capacites_utilisees)->toContain('Esprit clair');
});

// =====================================================================
// SOUTIEN, BUTIN, MARCHÉ
// =====================================================================

it('soin_allie — la Ballade rend des PV au voisin, le relève, et ne sert qu\'une fois par quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barde = creerHeros($alice, $groupe, 'Lyr', 1, ['classe' => 'barde']);
    $blesse = creerHeros($alice, $groupe, 'Albrecht', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $etatBarde = $quete->etatsPersonnages()->where('personnage_id', $barde->id)->firstOrFail();
    $etatBlesse = $quete->etatsPersonnages()->where('personnage_id', $blesse->id)->firstOrFail();

    // Le barde vient se placer au contact de son compagnon à terre.
    $etatBarde->update([
        'position_x' => (int) $etatBlesse->position_x + 1,
        'position_y' => (int) $etatBlesse->position_y,
    ]);
    $blesse->update(['pv_body' => 0]);
    $etatBlesse->update(['tombe' => true]);

    donnerTalent($barde, 'Ballade apaisante');

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $barde->id);

    $menu = $this->getJson('/api/groupes/table-1/menu?personnage_id='.$barde->id)->assertOk()->json();
    $option = collect($menu['menu']['options'] ?? [])->firstWhere('id', 'soigner_allie');

    expect($option)->not->toBeNull()
        // ⚠ L'option PORTE ses cibles : c'est cette liste que le résolveur
        // revalide, l'identifiant d'option ne dit plus rien de la légalité.
        ->and(collect($option['parametres']['cibles'])->pluck('id')->all())->toContain($blesse->id);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $barde->id);
    desFiges([4]); // d6 = 4 PV rendus

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'soigner_allie', 'parametres' => ['cible_id' => $blesse->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['soin_pv_body'])->toBe(4)
        ->and($resultat['releve'])->toBeTrue()
        ->and((int) $blesse->fresh()->pv_body)->toBe(4)
        ->and((bool) $etatBlesse->fresh()->tombe)->toBeFalse()
        // Une seule fois par quête : le compteur est posé.
        ->and((array) $etatBarde->fresh()->capacites_utilisees)->toContain('Ballade apaisante');
});

it('soin_allie — une cible HORS de la liste publiée est refusée (422)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barde = creerHeros($alice, $groupe, 'Lyr', 1, ['classe' => 'barde']);
    $loin = creerHeros($alice, $groupe, 'Albrecht', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $etatBarde = $quete->etatsPersonnages()->where('personnage_id', $barde->id)->firstOrFail();
    $etatLoin = $quete->etatsPersonnages()->where('personnage_id', $loin->id)->firstOrFail();

    // Un voisin blessé À CÔTÉ pour que l'option soit bien émise, et la vraie
    // cible du test LOIN : c'est la liste blanche qu'on éprouve, pas l'absence
    // d'option.
    $etatLoin->update([
        'position_x' => (int) $etatBarde->position_x + 6,
        'position_y' => (int) $etatBarde->position_y,
    ]);
    $loin->update(['pv_body' => 1]);

    donnerTalent($barde, 'Ballade apaisante');

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $barde->id);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'soigner_allie', 'parametres' => ['cible_id' => $loin->id],
    ])->assertStatus(422);

    expect((int) $loin->fresh()->pv_body)->toBe(1);
});

it('rarete_butin_amelioree — l\'Œil du prix décale la table de rareté du fouilleur', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'explorateur', 'niveau' => 1]);

    // Le talent est lu comme un décalage de NIVEAU sur la courbe unique de
    // `RareteButin` — plutôt qu'une seconde courbe, qui divergerait au prochain
    // rééquilibrage. À niveau 1 le rare pèse 2 %, à niveau 3 il pèse 10 %.
    expect(App\Engine\RareteButin::poids(3)['rare'])
        ->toBeGreaterThan(App\Engine\RareteButin::poids(1)['rare']);

    expect(app(Talents::class)->valeur($ctx['heros'], 'rarete_butin_amelioree'))->toBe(0);

    donnerTalent($ctx['heros'], 'Œil du prix');

    expect(app(Talents::class)->valeur($ctx['heros']->fresh(), 'rarete_butin_amelioree'))->toBe(2);
});

it('remise_marche — le Marchandage baisse les prix de l\'étal pour tout le groupe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);

    $marche = app(PhaseMarche::class);

    $plein = collect($marche->ouvrir($groupe)['inventaire'])->keyBy('objet_id');
    $marche->annuler($groupe->fresh());

    donnerTalent($nain, 'Marchandage');

    $remise = collect($marche->ouvrir($groupe->fresh())['inventaire'])->keyBy('objet_id');

    $temoin = $plein->first(fn ($l) => $l['prix'] >= 20);

    expect($temoin)->not->toBeNull()
        // −15 %, arrondi, jamais en dessous d'une pièce.
        ->and($remise[$temoin['objet_id']]['prix'])->toBe((int) round($temoin['prix'] * 85 / 100))
        ->and($remise[$temoin['objet_id']]['prix'])->toBeLessThan($temoin['prix']);
});

it('desamorcer_piege — un talent OUVRE le désamorçage à une classe qui n\'y avait pas droit', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'rogue']);
    $pieges = app(MoteurPieges::class);

    // Le rogue n'est ni nain ni explorateur, et ne porte pas de trousse à outils.
    expect($pieges->peutDesamorcer($ctx['heros']))->toBeFalse();

    donnerTalent($ctx['heros'], 'Doigts de fée');

    expect($pieges->peutDesamorcer($ctx['heros']->fresh()))->toBeTrue();
});

it('detection_pieges_adjacents — l\'Œil du mineur se lit par MÉCANIQUE, plus par nom de nœud', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain']);
    $pieges = app(MoteurPieges::class);

    expect($pieges->possedeOeilDuMineur($ctx['heros']))->toBeFalse();

    donnerTalent($ctx['heros'], 'Œil du mineur');

    expect($pieges->possedeOeilDuMineur($ctx['heros']->fresh()))->toBeTrue();
});
