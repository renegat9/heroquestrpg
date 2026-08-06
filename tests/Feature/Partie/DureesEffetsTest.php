<?php

declare(strict_types=1);

use App\Engine\DureeEffet;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Vocabulaire des durées d'effet (reference/19_durees_effets.md).
 *
 * La clé `duree` existait dans les données sans aucun lecteur : les buffs
 * expiraient — quand ils expiraient — sur leur CLÉ D'EFFET, pas sur leur durée.
 * D'où un +2 en défense PERMANENT (aucun chemin ne retirait un bonus de
 * défense) et une Potion de rage « un combat » consommée dès la première
 * attaque. Ces tests verrouillent le sens de chaque mot-clé.
 */
beforeEach(function () {
    $this->seed([SortSeeder::class, ObjetSeeder::class, ConditionSeeder::class]);
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

it('nomme exactement cinq mots-clés, et les distingue d\'un décompte de tours', function () {
    expect(DureeEffet::toutes())->toHaveCount(5)
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

it('ne pose aucun compteur de tours pour une durée à mot-clé', function () {
    $heros = herosPourDuree();
    buffPotion($heros, 'Potion de force');

    $pivot = DB::table('personnage_conditions')->where('personnage_id', $heros->id)->first();

    // duree 0 = « pas de compteur », l'expiration passe par le déclencheur :
    // decrementerDurees() ne doit donc jamais l'emporter par erreur.
    expect((int) $pivot->duree)->toBe(0);
});
