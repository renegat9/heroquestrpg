<?php

declare(strict_types=1);

use App\Models\Inventaire;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Partie\MoteurMobilier;
use App\Partie\MoteurPotions;
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

/*
 * SPIRIT QUEEN'S TORMENT — la POTION DE FORTUNE (lot I-b, chantier EXT-SPIRIT, 2026-10-23), EN JEU.
 *
 * G0053 p. 13 (quête 4, note B) : « If a hero drinks one, they roll 1 combat die. A different effect takes place for each
 * roll result. On a white shield: they roll 1 red die and restore that many Body Points. On a skull: they immediately
 * take on the effects of a Potion of Battle. On a black shield: they lose 1 Body Point. »
 *
 * Dés injectés : d6 1-3 = crâne, 4-5 = bouclier blanc, 6 = bouclier noir (`FaceDeCombat::depuisD6`). Le d6 de soin suit.
 * Le livret ne nomme pas la potion : « Potion de fortune » est notre libellé, les trois effets sont ceux du texte.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

/** Un héros du groupe, blessé à `$pv` PV de Body sur `$max`, avec UNE potion de fortune dans son sac. */
function heroAvecFortune(int $pv, int $max): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['pv_body' => $pv, 'pv_body_max' => $max]);

    $ligne = Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Potion de fortune')->firstOrFail()->id,
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);

    return [$heros, $ligne];
}

it('bouclier BLANC : la potion rend un d6 de Body (1 dé rouge, « that many Body Points »)', function () {
    [$heros, $ligne] = heroAvecFortune(2, 8);

    // d6 = 4 : bouclier blanc. Puis le d6 de soin = 3.
    desFiges([4, 3]);

    $reponse = app(MoteurPotions::class)->boire($heros, $ligne);

    expect($reponse['effets']['tirage'])->toBe(['face' => 'bouclier_blanc', 'source' => 'soin'])
        ->and($reponse['effets']['soin_pv_body'])->toBe(3)
        ->and((int) $heros->fresh()->pv_body)->toBe(5, 'de 2 PV, un d6 de 3 : 5');
});

it('CRÂNE : la potion donne les effets d\'une POTION DE BATAILLE, son buff posé sous son nom', function () {
    [$heros, $ligne] = heroAvecFortune(5, 8);

    // d6 = 1 : crâne.
    desFiges([1]);

    $reponse = app(MoteurPotions::class)->boire($heros, $ligne);

    expect($reponse['effets']['tirage'])->toBe(['face' => 'crane', 'source' => 'Potion de bataille'])
        ->and($reponse['effets']['buff'])->toBe('Renforcé', 'la condition de la Potion de bataille, lue au catalogue')
        ->and((int) $heros->fresh()->pv_body)->toBe(5, 'un crâne ne soigne ni ne blesse')
        // Le buff se pose sous le NOM de la Potion de bataille, pas sous celui de la fiole (source de la condition).
        ->and($heros->fresh()->conditions->contains(fn ($c) => str_ends_with((string) $c->pivot->source, 'Potion de bataille')))
        ->toBeTrue('source du buff : la Potion de bataille');
});

it('bouclier NOIR : la potion fait perdre 1 PV de Body à qui la boit (source « breuvage », annoncée)', function () {
    [$heros, $ligne] = heroAvecFortune(5, 8);

    // d6 = 6 : bouclier noir.
    desFiges([6]);

    $reponse = app(MoteurPotions::class)->boire($heros, $ligne);

    expect($reponse['effets']['tirage'])->toBe(['face' => 'bouclier_noir', 'source' => 'perte'])
        ->and($reponse['effets']['perte_pv_body'])->toBe(1)
        ->and((int) $heros->fresh()->pv_body)->toBe(4);
});

it('la potion est UNE fiole : bue, elle quitte le sac', function () {
    [$heros, $ligne] = heroAvecFortune(5, 8);

    desFiges([4, 2]);
    app(MoteurPotions::class)->boire($heros, $ligne->fresh());

    expect(Inventaire::where('personnage_id', $heros->id)
        ->where('objet_id', Objet::where('nom', 'Potion de fortune')->value('id'))->count())->toBe(0);
});

it('la table des brasseurs (boîte spirit_queen) rend la potion NOMMÉE, jamais une potion tirée au hasard', function () {
    // Le livret désigne la potion (« three potions can be found ») : la table la donne telle quelle, sans tirage par
    // catégorie. La table porte la boîte spirit_queen : elle n'entre au donjon qu'avec le thème de la campagne.
    expect(Mobilier::where('nom', 'Table des brasseurs')->value('boite'))->toBe('spirit_queen');

    $entree = ['issue' => 'objet', 'poids' => 1, 'objet' => 'Potion de fortune'];
    $methode = new ReflectionMethod(MoteurMobilier::class, 'carteDepuisEntree');
    $methode->setAccessible(true);

    $carte = $methode->invoke(app(MoteurMobilier::class), $entree, 1, []);

    expect($carte['issue'])->toBe('objet')
        ->and($carte['objet_id'])->toBe((int) Objet::where('nom', 'Potion de fortune')->value('id'));

    // Un nom absent du catalogue ne fabrique pas d'objet fantôme.
    $absente = $methode->invoke(app(MoteurMobilier::class), ['issue' => 'objet', 'poids' => 1, 'objet' => 'Inconnue'], 1, []);
    expect($absente)->toBe(['issue' => 'rien', 'objet_indisponible' => true]);
});

it('la potion de fortune ne se vend pas (hors marché) et son prix n\'est pas inventé', function () {
    $potion = Objet::where('nom', 'Potion de fortune')->firstOrFail();

    expect((int) $potion->prix_base)->toBe(0)
        ->and(array_key_exists('hors_marche', (array) $potion->effet))->toBeTrue()
        ->and((array) $potion->effet['tirage_de_combat'])->toHaveKeys(['bouclier_blanc', 'crane', 'bouclier_noir']);
});
