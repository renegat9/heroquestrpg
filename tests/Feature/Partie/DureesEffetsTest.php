<?php

declare(strict_types=1);

use App\Engine\DureeEffet;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Vocabulaire des durées d'effet (reference/19_mots_cles_effets.md).
 *
 * La clé `duree` existait dans les données sans aucun lecteur : les buffs
 * expiraient — quand ils expiraient — sur leur CLÉ D'EFFET, pas sur leur durée.
 * D'où un +2 en défense PERMANENT (aucun chemin ne retirait un bonus de
 * défense) et une Potion de rage « un combat » consommée dès la première
 * attaque. Ces tests verrouillent le sens de chaque mot-clé.
 */
beforeEach(function () {
    Illuminate\Support\Facades\Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([SortSeeder::class, ObjetSeeder::class, ConditionSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Un héros isolé, sans quête : ces tests portent sur l'expiration, pas sur le tour. */
function herosPourDuree(): Personnage
{
    return creerHeros(connecterJoueur('alice'), creerGroupe(), 'Albrecht', 1);
}

/** Applique le buff d'une potion nommée et rend le personnage. */
function buffPotion(Personnage $heros, string $nomPotion): Personnage
{
    app(MoteurSorts::class)->appliquerBuffPotion($heros, Objet::where('nom', $nomPotion)->firstOrFail());

    return $heros->fresh();
}

function bonus(Personnage $heros, string $cle): int
{
    return app(MoteurSorts::class)->bonusDes($heros->fresh(), $cle);
}

it('nomme exactement six mots-clés, et les distingue d\'un décompte de tours', function () {
    expect(DureeEffet::toutes())->toHaveCount(6)
        ->and(DureeEffet::estMotCle('prochaine_defense'))->toBeTrue()
        ->and(DureeEffet::estMotCle('un_combat'))->toBeFalse()   // ancienne orthographe
        ->and(DureeEffet::estMotCle(3))->toBeFalse()
        ->and(DureeEffet::tours(3))->toBe(3)
        ->and(DureeEffet::tours('ce_tour'))->toBe(0)
        ->and(DureeEffet::tours(null))->toBe(0);
});

it('n\'emploie que des durées connues dans TOUT le catalogue', function () {
    $valeurs = collect(Sort::all())->map(fn (Sort $s) => ($s->effet ?? [])['duree'] ?? null)
        ->merge(collect(Objet::all())->map(fn (Objet $o) => ($o->effet ?? [])['duree'] ?? null))
        ->filter()
        ->unique()
        ->values();

    foreach ($valeurs as $valeur) {
        expect(DureeEffet::estMotCle($valeur) || DureeEffet::tours($valeur) > 0)
            ->toBeTrue("Durée inconnue dans le catalogue : « {$valeur} ».");
    }
});

it('retire un buff « prochaine_attaque » quand le porteur attaque, pas avant', function () {
    $heros = herosPourDuree();
    buffPotion($heros, 'Potion de force');

    expect(bonus($heros, 'bonus_des_attaque'))->toBe(2);

    app(MoteurSorts::class)->expirerBuffs($heros, DureeEffet::PROCHAINE_DEFENSE);
    expect(bonus($heros, 'bonus_des_attaque'))->toBe(2, 'une défense ne dépense pas un buff d\'attaque');

    app(MoteurSorts::class)->expirerBuffs($heros, DureeEffet::PROCHAINE_ATTAQUE);
    expect(bonus($heros, 'bonus_des_attaque'))->toBe(0);
});

it('retire un buff « prochaine_defense » quand le porteur se défend — il n\'était JAMAIS retiré', function () {
    $heros = herosPourDuree();
    buffPotion($heros, 'Potion de défense');

    expect(bonus($heros, 'bonus_des_defense'))->toBe(2);

    // Le bug historique : attaquer ne doit rien changer…
    app(MoteurSorts::class)->expirerBuffs($heros, DureeEffet::PROCHAINE_ATTAQUE);
    expect(bonus($heros, 'bonus_des_defense'))->toBe(2);

    // …mais se défendre le dépense, ce qu'aucun chemin ne faisait avant.
    app(MoteurSorts::class)->expirerBuffs($heros, DureeEffet::PROCHAINE_DEFENSE);
    expect(bonus($heros, 'bonus_des_defense'))->toBe(0);
});

it('garde un buff « fin_du_combat » à travers les attaques, et le retire au dernier monstre', function () {
    $heros = herosPourDuree();
    buffPotion($heros, 'Potion de rage');

    expect(bonus($heros, 'bonus_des_attaque'))->toBe(1);

    // Annoncée « un combat », la Potion de rage disparaissait dès la première
    // attaque : elle portait la même clé d'effet que Courage.
    app(MoteurSorts::class)->expirerBuffs($heros, DureeEffet::PROCHAINE_ATTAQUE);
    expect(bonus($heros, 'bonus_des_attaque'))->toBe(1);

    app(MoteurSorts::class)->expirerBuffs($heros, DureeEffet::FIN_DU_COMBAT);
    expect(bonus($heros, 'bonus_des_attaque'))->toBe(0);
});

it('termine le combat quand les monstres ENGAGÉS tombent, pas quand le donjon est vidé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    buffPotion($hero, 'Potion de rage');
    expect(bonus($hero, 'bonus_des_attaque'))->toBe(1);

    // Un seul monstre RÉVÉLÉ, les autres dormants derrière leurs portes.
    $monstres = $quete->instancesMonstres()->get();
    expect($monstres)->not->toBeEmpty();
    $engage = $monstres->first();
    $engage->update(['revele' => true, 'etat' => 'actif']);
    foreach ($monstres->skip(1) as $dormant) {
        $dormant->update(['revele' => false, 'etat' => 'actif']);
    }

    // Le combat continue tant que l'engagé tient : le buff reste.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();
    expect(bonus($hero, 'bonus_des_attaque'))->toBe(1);

    // L'engagé tombe. Il RESTE des monstres actifs dans le donjon (dormants),
    // donc `donjon_nettoye` est faux — le combat, lui, est bel et bien fini.
    $engage->update(['etat' => 'vaincu', 'pv_body' => 0]);
    expect($quete->instancesMonstres()->where('etat', 'actif')->exists())->toBeTrue();

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();
    expect(bonus($hero, 'bonus_des_attaque'))->toBe(0);
});

it('retire « premier_degat_subi » au sang versé, pas au simple jet de défense', function () {
    $heros = herosPourDuree();
    app(MoteurSorts::class)->appliquerBuff($heros, Sort::where('nom', 'Peau de Pierre')->firstOrFail());

    // Texte officiel : 1 dé, jusqu'au PREMIER DÉGÂT SUBI.
    expect(bonus($heros, 'bonus_des_defense'))->toBe(1);

    // Une défense sans dégât ne le consomme pas…
    app(MoteurSorts::class)->expirerBuffs($heros, DureeEffet::PROCHAINE_DEFENSE);
    expect(bonus($heros, 'bonus_des_defense'))->toBe(1);

    // …et un SOIN non plus (les PV montent).
    $heros->update(['pv_body' => $heros->pv_body_max]);
    expect(bonus($heros, 'bonus_des_defense'))->toBe(1);

    // Seule une baisse réelle de PV le dépense — quelle qu'en soit la source,
    // puisque l'observateur est sur le modèle et non sur un chemin d'attaque.
    $heros->update(['pv_body' => $heros->pv_body - 1]);
    expect(bonus($heros, 'bonus_des_defense'))->toBe(0);
});

it('ne pose aucun compteur de tours pour une durée à mot-clé', function () {
    $heros = herosPourDuree();
    buffPotion($heros, 'Potion de force');

    $pivot = DB::table('personnage_conditions')->where('personnage_id', $heros->id)->first();

    // duree 0 = « pas de compteur », l'expiration passe par le déclencheur :
    // decrementerDurees() ne doit donc jamais l'emporter par erreur.
    expect((int) $pivot->duree)->toBe(0);
});

it('applique la MÊME autorité de durée aux buffs de SORT qu\'aux potions', function () {
    $heros = herosPourDuree();

    // Repéré en partie réelle : les buffs de sort passaient par un second
    // système, `dureeBuff()`, qui DEVINAIT la durée d'après la clé d'effet —
    // Traverser la Pierre portait `ce_tour` ET un compteur de 1 tour.
    foreach (['Peau de Pierre', 'Traverser la Pierre', 'Voile de Brume', 'Vent Véloce', 'Courage'] as $nom) {
        DB::table('personnage_conditions')->where('personnage_id', $heros->id)->delete();

        $sort = Sort::where('nom', $nom)->firstOrFail();
        app(MoteurSorts::class)->appliquerBuff($heros, $sort);

        $pivot = DB::table('personnage_conditions')->where('personnage_id', $heros->id)->first();

        // Tous ces sorts déclarent un MOT-CLÉ : aucun ne doit poser de compteur.
        expect(DureeEffet::estMotCle(($sort->effet)['duree'] ?? null))->toBeTrue("{$nom} : durée non mot-clé")
            ->and((int) $pivot->duree)->toBe(0, "{$nom} : compteur de tours parasite");
    }
});

it('expose le bonus des buffs dans l\'état, pour que le joueur le VOIE', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $entite = fn () => collect(app(App\Partie\EtatGroupe::class)->payload($groupe->fresh())['entites'])
        ->firstWhere('id', $hero->id);

    expect($entite()['bonus_des_defense'])->toBe(0);

    // Peau de Pierre : le moteur ajoutait bien +1 au JET, mais rien ne le disait
    // au joueur — la fiche affichait le même chiffre avant et après (constaté en
    // partie réelle, 2026-08-06).
    app(MoteurSorts::class)->appliquerBuff($hero, Sort::where('nom', 'Peau de Pierre')->firstOrFail());

    expect($entite()['bonus_des_defense'])->toBe(1)
        ->and($entite()['bonus_des_attaque'])->toBe(0)
        // La BASE ne bouge pas : le bonus est temporaire, il s'affiche à part.
        ->and($entite()['des_defense'])->toBe((int) $hero->des_defense);
});
