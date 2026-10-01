<?php

declare(strict_types=1);

use App\Engine\Combat;
use App\Engine\Des\LanceurDes;
use App\Engine\Des\LanceurDeterministe;
use App\Engine\ReactionEffet;
use App\Engine\TypeFigurine;
use App\Jobs\GenererMenu;
use App\Models\Epreuve;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\Marche\PhaseMarche;
use App\Partie\MoteurDegats;
use App\Partie\MoteurOracle;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * L'ORACLE (First Light, FL-Q p. 6, lot C) — Bénédiction et Malédiction.
 *
 * Tests EN JEU : les vraies routes/`ResolveurTour` quand c'est possible
 * (`/choix`, `/reaction`, `/marche/...`), le même patron que
 * `ArtefactsSeptCartesTest`/`ReactionHorsTourTest` pour les cas où rejouer
 * tout le détour d'un menu serait plus fragile que l'appel direct au service
 * réel (`MoteurDegats::infligerAHeros()`).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, EpreuveSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

/** Remplace la couche `epreuves` de la carte par UNE entrée, à (x, y). */
function poserEpreuveOracle(Quete $quete, int $x, int $y, int $salle = 0): void
{
    $oracle = Epreuve::where('nom', "L'Oracle de Zargon")->firstOrFail();
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['epreuves'] = [[
        'x' => $x, 'y' => $y, 'epreuve_id' => $oracle->id, 'salle' => $salle, 'tentee_par' => [],
    ]];
    $carte->update(['grille' => $grille]);
    $quete->refresh();
}

it('épreuve réussie : accorde la Bénédiction de l\'Oracle, DURABLE sur le héros', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];

    poserEpreuveOracle($ctx['quete'], (int) $etat->position_x, (int) $etat->position_y);
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);

    expect($heros->fresh()->benediction_oracle)->toBeFalse();

    // Difficulté 3 : 2 dés de Mind par défaut suffiraient au mieux à 2
    // succès. On force donc une réussite via un attribut Mind temporairement
    // généreux plutôt que d'empiler des dés dont le nombre dépend d'un
    // calcul interne (bonus de contexte, talents…).
    $heros->update(['attribut_mind' => 3]);
    desFiges([1, 1, 1]); // trois crânes = trois succès ≥ difficulté 3

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)
        ->json('resultat');

    expect($reponse['succes'])->toBeGreaterThanOrEqual(3)
        ->and($reponse['oracle'])->toBe('benediction');

    expect($heros->fresh()->benediction_oracle)->toBeTrue();
    expect($heros->fresh()->malediction_oracle)->toBeFalse();
});

it('épreuve ratée : pose la Malédiction de l\'Oracle (Mark of Zargon)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];

    poserEpreuveOracle($ctx['quete'], (int) $etat->position_x, (int) $etat->position_y);
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);

    desFiges([4, 4]); // deux boucliers blancs = zéro crâne = zéro succès < 3

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)
        ->json('resultat');

    expect($reponse['succes'])->toBe(0)
        ->and($reponse['oracle'])->toBe('malediction');

    expect($heros->fresh()->malediction_oracle)->toBeTrue();
    expect($heros->fresh()->benediction_oracle)->toBeFalse();
});

it('Bénédiction, option (a) : révèle la salle derrière une porte fermée adjacente, SANS l\'ouvrir', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $quete = $ctx['quete'];
    $heros->update(['benediction_oracle' => true]);

    $carte = $quete->carte;
    $portes = (array) $carte->grille['portes'];
    expect($portes)->not->toBeEmpty();

    // La première porte FERMÉE de la carte, et la case d'embrasure du côté
    // couloir — exactement ce que `MoteurPortes::porteFermeeAdjacente()` sait
    // reconnaître depuis cette case.
    $indexFermee = null;
    foreach ($portes as $i => $p) {
        if (($p['etat'] ?? 'ouverte') === 'fermee' && ($p['verrou']['type'] ?? null) === null) {
            $indexFermee = $i;
            break;
        }
    }
    expect($indexFermee)->not->toBeNull('aucune porte simplement close sur cette carte de test — scénario invalide.');

    $porte = $portes[$indexFermee];
    $embrasure = Grille::caseEmbrasure($porte, (array) $carte->grille['salles']);

    // ⚠ ADJACENT à l'embrasure, jamais SUR elle : `porteFermeeAdjacente()`
    // exige une distance de Manhattan de 1 (la case de la porte elle-même
    // est bloquée tant qu'elle n'est pas ouverte).
    $position = caseAdjacenteLibre($quete, (int) $embrasure['x'], (int) $embrasure['y']);
    $ctx['etatHeros']->update(['position_x' => $position['x'], 'position_y' => $position['y']]);

    // Le menu moteur est re-proposé après le repositionnement (même raison
    // que `ResolutionTourTest` : `/choix` revalide contre le DERNIER menu
    // généré, pas contre la carte courante).
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);

    $cote = (string) ($porte['cote'] ?? 'e');
    $optionId = "oracle_salle_{$porte['x']}_{$porte['y']}_{$cote}";

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => $optionId])
        ->assertStatus(202)
        ->json('resultat');

    expect($reponse['type'])->toBe('oracle_salle')
        ->and($reponse['benediction_oracle'])->toBeFalse();

    expect($heros->fresh()->benediction_oracle)->toBeFalse();

    // La porte reste CLOSE — c'est tout l'écart avec « Ouvrir la porte ».
    $quete->carte->refresh();
    expect($quete->carte->grille['portes'][$indexFermee]['etat'])->toBe('fermee');
});

it('la Bénédiction, option (a), n\'est proposée QUE si le héros la possède', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    expect($ctx['heros']->fresh()->benediction_oracle)->toBeFalse();

    $menu = optionsMenuOracle($ctx['groupe']->id, $ctx['alice']->id, $ctx['heros']->id);
    $oracleOptions = array_filter($menu, fn ($o) => ($o['type'] ?? null) === 'oracle_salle');

    expect($oracleOptions)->toBeEmpty();
});

/** Le menu courant du héros, tel que la manette le reçoit (copie locale du patron d'ArtefactsSeptCartesTest). */
function optionsMenuOracle(int $groupeId, int $joueurId, int $herosId): array
{
    GenererMenu::dispatchSync($groupeId, $joueurId, $herosId);

    return (array) data_get(
        Cache::get(GenererMenu::cleMenu($groupeId, $joueurId)),
        'menu.options', [],
    );
}

it('Bénédiction, option (b) : relance TOUT le jet de Défense, en mieux comme en pire — et se consomme', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $victime = $ctx['heros'];
    $victime->update(['benediction_oracle' => true]);

    app(MoteurDegats::class)->infligerAHeros($victime, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, [
        'instance_id' => (int) $ctx['instance']->id, 'des_attaque' => 3, 'des_defense' => 2,
    ]);

    $attente = $ctx['etatHeros']->fresh()->reaction_en_attente;
    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe(ReactionEffet::RELANCE_BENEDICTION_ORACLE);

    $avant = (int) $victime->fresh()->pv_body;

    // Trois crânes contre deux boucliers blancs → 1 dégât, au lieu des 2 rendus.
    desFiges([1, 1, 1, 4, 4]);

    $reaction = $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $victime->id, 'accepte' => true,
    ])->assertOk()->json('reaction');

    expect($reaction['action'])->toBe(ReactionEffet::RELANCE_BENEDICTION_ORACLE)
        ->and($reaction['degats_annules'])->toBe(2)
        ->and($reaction['degats_relance'])->toBe(1)
        ->and((int) $victime->fresh()->pv_body)->toBe($avant + 1);

    // Dépensée, qu'elle ait servi ou non.
    // ⚠ Réaffecté, pas seulement lu : `$victime` est un objet PHP tenu depuis
    // le début du test, jamais notifié de la consommation faite sur une
    // AUTRE instance à l'intérieur de `relancerBenedictionOracle()` — exactement
    // la distinction que corrige MoteurReactions ci-dessus, ici côté test.
    $victime = $victime->fresh();
    expect($victime->benediction_oracle)->toBeFalse();

    // Un second coup n'offre plus rien.
    app(MoteurDegats::class)->infligerAHeros($victime, 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, [
        'instance_id' => (int) $ctx['instance']->id, 'des_attaque' => 3, 'des_defense' => 2,
    ]);
    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();
});

it('MoteurOracle::appliquerSiMaudit() garde le PIRE pour le héros défenseur — le PLUS de dégâts encaissés', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $heros->update(['malediction_oracle' => true]);
    $etat = $ctx['etatHeros'];

    // Jet d'ORIGINE, construit à la main avec des dés figés à lui seul : 3
    // crânes contre 2 boucliers blancs → 1 dégât.
    $original = (new Combat(new LanceurDeterministe([1, 1, 1, 4, 4])))
        ->resoudreAttaque(desAttaque: 3, desDefense: 2, typeDefenseur: TypeFigurine::Heros, pvBodyDefenseur: 8);

    // RELANCE forcée par Zargon : que des boucliers blancs → 0 crâne, 0 dégât.
    desFiges([4, 4, 4, 4, 4]);

    $res = app(MoteurOracle::class)->appliquerSiMaudit(
        $original, true, 3, 2, $heros, $etat, app(LanceurDes::class),
    );

    // « il garde le résultat le pire pour le héros » : ici, le PLUS de
    // dégâts — c'est donc l'ORIGINE (1 dégât) qui doit être retenue, pas la
    // relance (0 dégât), bien qu'elle soit chronologiquement la plus récente.
    expect($res['applique'])->toBeTrue()
        ->and($res['resultat']->degats)->toBe(1)
        ->and($res['detail']['degats_original'])->toBe(1)
        ->and($res['detail']['degats_relance'])->toBe(0)
        ->and($res['detail']['garde'])->toBe('origine');

    expect($etat->fresh()->malediction_oracle_utilisee)->toBeTrue();

    // Le jeton est dépensé : un second appel ne joue plus cette quête.
    $res2 = app(MoteurOracle::class)->appliquerSiMaudit(
        $original, true, 3, 2, $heros, $etat, app(LanceurDes::class),
    );
    expect($res2['applique'])->toBeFalse();
});

it('MoteurOracle::appliquerSiMaudit() garde le PIRE pour le héros attaquant — le MOINS de dégâts infligés', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $heros->update(['malediction_oracle' => true]);
    $etat = $ctx['etatHeros'];

    // Origine : 3 crânes, le monstre ne pare rien (0 dé de défense) → 3 dégâts.
    $original = (new Combat(new LanceurDeterministe([1, 1, 1])))
        ->resoudreAttaque(desAttaque: 3, desDefense: 0, typeDefenseur: TypeFigurine::Monstre, pvBodyDefenseur: 8);

    // Relance forcée : zéro crâne → 0 dégât infligé au monstre.
    desFiges([4, 4, 4]);

    $res = app(MoteurOracle::class)->appliquerSiMaudit(
        $original, false, 3, 0, $heros, $etat, app(LanceurDes::class),
    );

    // Défenseur = le MONSTRE : le pire pour le héros est le MOINS de dégâts
    // infligés — la relance (0) doit donc l'emporter sur l'origine (3).
    expect($res['resultat']->degats)->toBe(0)
        ->and($res['detail']['garde'])->toBe('relance');
});

it('la Malédiction, bout en bout : le monstre attaque, Zargon joue AVANT l\'application des dégâts et consomme le jeton', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $victime = $ctx['heros'];
    $victime->update(['malediction_oracle' => true]);
    $etat = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $victime->id)->firstOrFail();

    $avant = (int) $victime->pv_body;

    // Le héros termine son tour sans agir → la phase des monstres joue : le
    // gobelin, au contact, attaque. Que des crânes : l'attaque porte, qu'elle
    // ait été relancée ou non.
    desFiges(array_fill(0, 40, 1));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect($etat->fresh()->malediction_oracle_utilisee)->toBeTrue();
    expect((int) $victime->fresh()->pv_body)->toBeLessThan($avant);
});

it('la Malédiction ne joue plus une fois le jeton dépensé cette quête', function () {
    $moteur = app(MoteurOracle::class);
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $heros->update(['malediction_oracle' => true]);
    $etat = $ctx['etatHeros'];

    expect($moteur->jetonDisponible($heros, $etat))->toBeTrue();
    $etat->update(['malediction_oracle_utilisee' => true]);
    expect($moteur->jetonDisponible($heros, $etat))->toBeFalse();
});

it('lève la Malédiction au marché contre un don de 800 po de la bourse COMMUNE', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $heros->update(['malediction_oracle' => true]);
    $groupe->update(['or' => 1000]);

    app(PhaseMarche::class)->ouvrir($groupe->fresh());

    $reponse = $this->postJson('/api/groupes/table-1/marche/lever-malediction', ['personnage_id' => $heros->id])
        ->assertOk()->json();

    expect($reponse['leve'])->toBeTrue()
        ->and($reponse['don'])->toBe(800)
        ->and($reponse['or'])->toBe(200);

    expect($heros->fresh()->malediction_oracle)->toBeFalse();
    expect($groupe->fresh()->or)->toBe(200);
});

it('refuse de lever la Malédiction si la bourse commune ne couvre pas le don', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $heros->update(['malediction_oracle' => true]);
    $groupe->update(['or' => 100]);

    app(PhaseMarche::class)->ouvrir($groupe->fresh());

    $this->postJson('/api/groupes/table-1/marche/lever-malediction', ['personnage_id' => $heros->id])
        ->assertStatus(422);

    expect($heros->fresh()->malediction_oracle)->toBeTrue();
    expect($groupe->fresh()->or)->toBe(100);
});

it('refuse de lever une Malédiction qui n\'existe pas', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    expect($heros->fresh()->malediction_oracle)->toBeFalse();
    $groupe->update(['or' => 1000]);

    app(PhaseMarche::class)->ouvrir($groupe->fresh());

    $this->postJson('/api/groupes/table-1/marche/lever-malediction', ['personnage_id' => $heros->id])
        ->assertStatus(422);
});
