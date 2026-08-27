<?php

declare(strict_types=1);

use App\Partie\DifficulteBody;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LE PLAFOND DES JETS DE BODY (René, 2026-08-24).
 *
 * Aucune difficulté de Body ne dépasse jamais le meilleur `attribut_body` des
 * héros engagés : un jet que la compagnie ne peut mathématiquement pas gagner
 * n'est pas un obstacle, c'est une impasse déguisée en choix.
 *
 * ⚠ Ce que ce fichier verrouille surtout, c'est le PARTAGE : la valeur brute
 * reste en base (catalogue de mobilier, difficulté d'un levier), et c'est la
 * lecture qui plafonne. Le plafond est mobile — il monte quand un héros achète
 * *Colosse*, il descend quand le costaud quitte le groupe —, donc le figer dans
 * la donnée la ferait mentir dès le niveau suivant.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('plafonne une difficulté brute au meilleur Body du groupe', function () {
    // Magicien : attribut_body 1. C'est le meilleur Body de cette compagnie.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien', 'attribut_body' => 1]);

    expect(DifficulteBody::meilleurBody($ctx['quete']))->toBe(1)
        // Un Seigneur ogre à 10 PV de Body ne peut pas être plus dur que 1 ici.
        ->and(DifficulteBody::plafonnee($ctx['quete'], 10))->toBe(1)
        ->and(DifficulteBody::plafonnee($ctx['quete'], 3))->toBe(1)
        // Une difficulté déjà sous le plafond n'est jamais remontée : le plafond
        // borne, il ne nivelle pas.
        ->and(DifficulteBody::plafonnee($ctx['quete'], 1))->toBe(1);
});

it('suit le groupe : le plafond MONTE avec le meilleur Body', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'attribut_body' => 4]);

    expect(DifficulteBody::plafonnee($ctx['quete'], 10))->toBe(4);

    // Le héros gagne un point d'attribut (c'est ce que fait *Colosse*).
    $ctx['heros']->update(['attribut_body' => 5]);

    expect(DifficulteBody::plafonnee($ctx['quete']->fresh(), 10))->toBe(5);
});

it('retient le MEILLEUR Body, pas celui du héros qui tente', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Gandalf', 1, ['classe' => 'magicien', 'attribut_body' => 1]);
    creerHeros($alice, $groupe, 'Krogar', 2, ['classe' => 'barbare', 'attribut_body' => 4]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = App\Models\Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // ⚠ C'est la COMPAGNIE qui doit pouvoir gagner le jet, pas chacun : le
    // magicien tentera peut-être et échouera, mais la porte n'est pas un mur
    // puisque le barbare est là.
    expect(DifficulteBody::plafonnee($quete, 4))->toBe(4);
});

it('rend la valeur brute quand la question n\'a pas de réponse', function () {
    // Pas de quête : aucune compagnie à interroger. On ne durcit pas le jeu en
    // silence pour une donnée manquante — même prudence que les listes de
    // maîtrises vides, qui valent « aucune restriction ».
    expect(DifficulteBody::plafonnee(null, 3))->toBe(3)
        ->and(DifficulteBody::meilleurBody(null))->toBe(0);
});

it('ne descend jamais sous 1, le plancher d\'un jet de compétence', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien', 'attribut_body' => 0]);

    // `Engine\JetCompetence` refuse une difficulté < 1 (exception). Un héros à
    // 0 en Body ne doit donc pas produire une difficulté 0 : il échouera, c'est
    // tout — « 0 dé = 0 succès » (doc 09 §2).
    expect(DifficulteBody::plafonnee($ctx['quete'], 2))->toBe(1)
        ->and(DifficulteBody::plafonnee($ctx['quete'], 0))->toBe(1);
});
