<?php

declare(strict_types=1);

use App\Models\Monstre;
use App\Partie\Images\BibliothequeImages;
use Database\Seeders\MonstreSeeder;
use Illuminate\Support\Str;

/*
 * Apparence des monstres NOMMÉS dans le prompt d'image (2026-10-31) — `config/apparences_monstres.php`.
 *
 * Défaut (René) : le prompt ne contenait que le NOM, et Gemini dessinait un démon cornu pour tous les méchants
 * nommés. Le registre se tient DANS LES DEUX SENS : chaque monstre qui doit avoir une apparence (boss, sous-boss,
 * jeton emprunté) en a une, et chaque apparence désigne un monstre du catalogue semé. Aucun test ne génère d'image.
 */

/**
 * Les monstres qui DOIVENT avoir une apparence : les boss et sous-boss, et tout monstre dont le jeton
 * (`symboles_monstres.cles`) n'est pas le slug de son nom — il emprunte la figurine d'un autre.
 *
 * @return list<string>
 */
function monstresQuiDoiventAvoirUneApparence(): array
{
    $cles = config('symboles_monstres.cles');

    return Monstre::query()->get()
        ->filter(fn (Monstre $m) => in_array($m->tier, ['boss', 'sous_boss'], true)
            || ($cles[$m->nom_base] ?? Str::slug($m->nom_base)) !== Str::slug($m->nom_base))
        ->pluck('nom_base')
        ->values()
        ->all();
}

it('chaque monstre qui doit avoir une apparence en a une', function () {
    $this->seed(MonstreSeeder::class);
    $registre = config('apparences_monstres');

    foreach (monstresQuiDoiventAvoirUneApparence() as $nom) {
        expect(array_key_exists($nom, $registre))->toBeTrue("« {$nom} » est un boss, un sous-boss ou porte un jeton emprunté, et n'a aucune apparence");
    }
});

it('chaque apparence désigne un monstre du catalogue semé : aucune clé morte', function () {
    $this->seed(MonstreSeeder::class);
    $catalogue = Monstre::query()->pluck('nom_base')->all();

    foreach (array_keys(config('apparences_monstres')) as $nom) {
        expect(in_array($nom, $catalogue, true))->toBeTrue("« {$nom} » (apparences_monstres) n'existe pas au catalogue");
    }
});

it('une apparence est une phrase française non vide, sans chiffre (Gemini peint les chiffres)', function () {
    foreach (config('apparences_monstres') as $nom => $apparence) {
        expect(trim((string) $apparence))->not->toBe('', "apparence vide : « {$nom} »");
        expect(preg_match('/\d/', (string) $apparence))->toBe(0, "chiffre dans l'apparence de « {$nom} »");
    }
});

it('le prompt d\'un monstre nommé porte son apparence et la consigne, sans son nom ni accolade restante', function () {
    $prompt = (new BibliothequeImages)->promptMonstre('Ulag', 'sous_boss');

    expect($prompt)->toContain(config('apparences_monstres.Ulag'))
        ->toContain('aucune inscription')
        ->not->toContain('Ulag')
        ->not->toContain('{');
});

it('un monstre sans apparence garde son gabarit générique, nom et tier compris, avec la consigne', function () {
    $prompt = (new BibliothequeImages)->promptMonstre('Zombie', 'base');

    expect(array_key_exists('Zombie', config('apparences_monstres')))->toBeFalse()
        ->and($prompt)->toContain('Zombie')
        ->and($prompt)->toContain('(tier base)')
        ->and($prompt)->toContain('créature menaçante en pied')
        ->and($prompt)->toContain('aucune inscription');
});

it('tout prompt de monstre du catalogue, nommé ou non, interdit l\'inscription', function () {
    $this->seed(MonstreSeeder::class);
    $biblio = new BibliothequeImages;

    foreach (Monstre::query()->get() as $m) {
        expect(str_contains($biblio->promptMonstre($m->nom_base, (string) $m->tier), 'aucune inscription'))
            ->toBeTrue("« {$m->nom_base} » : pas de consigne d'inscription");
    }
});

it('les gabarits lisent leur champ : {nom} et {tier} pour le générique, {apparence} pour le nommé', function () {
    expect(config('images.gabarits.monstre'))->toContain('{nom}')
        ->and(config('images.gabarits.monstre'))->toContain('{tier}')
        ->and(config('images.gabarits.monstre_nomme'))->toContain('{apparence}');
});
