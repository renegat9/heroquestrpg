<?php

declare(strict_types=1);

use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Partie\MoteurPotions;
use App\Partie\MoteurSorts;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Les 15 potions des cartes officielles (doc 16 §2.1bis).
 *
 * Sept d'entre elles ont demandé un lecteur neuf, et trois portent une
 * RESTRICTION DE CLASSE — une première pour un consommable, que `MoteurPotions`
 * ne savait pas contrôler puisqu'un consommable ne passe jamais par
 * `Equipement::equiper()`.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

/** Met une potion au sac du héros. */
function potionAuSac(Personnage $perso, string $nom): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $perso->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);
}

/** Fait boire directement par le moteur (hors HTTP). */
function boirePotion(Personnage $perso, string $nom, array $parametres = []): array
{
    return app(MoteurPotions::class)->boire($perso, potionAuSac($perso, $nom), $parametres);
}

it('réserve au Barbare les trois potions que sa carte lui réserve', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barbare = creerHeros($alice, $groupe, 'Krogar', 1, ['classe' => 'barbare']);
    $nain = creerHeros($alice, $groupe, 'Borin', 2, ['classe' => 'nain']);

    foreach (['Potion de rage guerrière', 'Potion de peau de givre', 'Potion de force glaciale'] as $potion) {
        // « Only the Barbarian can drink this » — et c'est ICI que la règle est
        // opposable : rien d'autre sur le chemin d'un consommable ne la lit.
        expect(fn () => boirePotion($nain, $potion))->toThrow(ValidationException::class);

        boirePotion($barbare, $potion);
    }

    // L'Elfe a les siennes, et le Barbare n'y touche pas.
    $elfe = creerHeros($alice, $groupe, 'Sylwen', 3, ['classe' => 'elfe']);

    foreach (['Potion de rappel', 'Potion de vision'] as $potion) {
        expect(fn () => boirePotion($barbare, $potion))->toThrow(ValidationException::class);

        boirePotion($elfe, $potion);
    }
});

it('donne EXACTEMENT deux attaques par tour sous Rage guerrière, jamais trois', function () {
    ['heros' => $barbare, 'quete' => $quete, 'etatHeros' => $etat] =
        demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);

    boirePotion($barbare, 'Potion de rage guerrière');

    // Le drapeau est posé à la gorgée…
    expect((bool) $etat->fresh()->attaque_supplementaire)->toBeTrue();

    // …et le crochet de début de tour ne le REPOSE pas au milieu du tour : sans
    // sa garde d'idempotence, chaque action réarmerait la seconde attaque et la
    // potion donnerait des attaques sans fin.
    EtatPersonnageQuete::whereKey($etat->id)->update(['attaque_supplementaire' => false, 'a_agi' => true]);
    app(MoteurSorts::class)->rythmerBuffsDeVue($quete, $etat->fresh());
    expect((bool) $etat->fresh()->attaque_supplementaire)->toBeFalse();

    // Au tour SUIVANT, en revanche, elle se réarme tant qu'un monstre est en vue.
    EtatPersonnageQuete::whereKey($etat->id)->update(['a_agi' => false, 'a_joue' => false, 'a_deplace' => false]);
    app(MoteurSorts::class)->rythmerBuffsDeVue($quete, $etat->fresh());
    expect((bool) $etat->fresh()->attaque_supplementaire)->toBeTrue();
});

it('retire la Peau de givre dès qu\'aucun monstre n\'est en vue', function () {
    ['heros' => $barbare, 'quete' => $quete, 'etatHeros' => $etat, 'instance' => $instance] =
        demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);

    boirePotion($barbare, 'Potion de peau de givre');
    expect(app(MoteurSorts::class)->bonusDes($barbare->fresh(), 'bonus_des_defense'))->toBe(2);

    // ⚠ C'est la LIGNE DE VUE du porteur, pas l'état du donjon : le gobelin
    // tombe, le froid retombe avec lui.
    $instance->update(['etat' => 'vaincu', 'pv_body' => 0]);
    app(MoteurSorts::class)->rythmerBuffsDeVue($quete, $etat->fresh());

    expect(app(MoteurSorts::class)->bonusDes($barbare->fresh(), 'bonus_des_defense'))->toBe(0);
});

it('n\'autorise qu\'UNE Potion de dextérité par tour, et ajoute ses 5 cases', function () {
    ['heros' => $heros, 'etatHeros' => $etat] = demarrerQueteAvecMonstre('Gobelin');

    boirePotion($heros, 'Potion de dextérité');

    expect(app(MoteurSorts::class)->bonusDes($heros->fresh(), 'bonus_deplacement'))->toBe(5)
        ->and(app(MoteurSorts::class)->aBuff($heros->fresh(), 'saut_fosse_automatique'))->toBeTrue()
        ->and((array) $etat->fresh()->capacites_tour)->toContain('Potion de dextérité');

    // « If you purchase more than one of these potions, you may use only one
    // potion per turn. » ⚠ La garde ne vaut QUE pour les potions marquées :
    // brider les quatorze autres inventerait une règle.
    expect(fn () => boirePotion($heros, 'Potion de dextérité'))->toThrow(ValidationException::class);

    boirePotion($heros, 'Potion de bataille'); // non marquée : elle passe
});

it('double les dégâts de la prochaine attaque sous Force glaciale', function () {
    ['heros' => $barbare] = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);

    expect(app(MoteurSorts::class)->multiplicateurDegats($barbare))->toBe(1);

    boirePotion($barbare, 'Potion de force glaciale');

    expect(app(MoteurSorts::class)->multiplicateurDegats($barbare->fresh()))->toBe(2);
});

it('rend un NOMBRE BORNÉ de sorts : trois pour la Magie, un pour le Rappel', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    // `creerHeros` ne pose aucun sort — c'est la route de création qui le fait.
    foreach (['feu', 'eau', 'air'] as $element) {
        app(MoteurSorts::class)->attacherElement($magicien, $element);
    }

    $epuiser = fn () => DB::table('personnage_sorts')
        ->where('personnage_id', $magicien->id)->update(['disponible' => false]);
    $restants = fn () => DB::table('personnage_sorts')
        ->where('personnage_id', $magicien->id)->where('disponible', false)->count();

    $total = DB::table('personnage_sorts')->where('personnage_id', $magicien->id)->count();
    expect($total)->toBeGreaterThan(3); // sinon le test ne prouverait rien

    $epuiser();
    boirePotion($magicien, 'Potion de magie');
    expect($restants())->toBe($total - 3);

    // Le Parchemin de Sorts, lui, les rend TOUS : `restaure_sorts: true`. C'est
    // la différence d'échelle qui fait la valeur de chaque carte.
    $epuiser();
    boirePotion($magicien, 'Parchemin de Sorts');
    expect($restants())->toBe(0);
});

it('laisse le joueur CHOISIR quel sort la Potion de rappel lui rend', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $elfe = creerHeros($alice, $groupe, 'Sylwen', 1, ['classe' => 'elfe']);
    app(MoteurSorts::class)->attacherElement($elfe, 'eau');

    DB::table('personnage_sorts')->where('personnage_id', $elfe->id)->update(['disponible' => false]);

    $sorts = DB::table('personnage_sorts')->where('personnage_id', $elfe->id)
        ->orderBy('sort_id')->pluck('sort_id')->all();
    $voulu = end($sorts); // le DERNIER : un repli par id ne le choisirait pas

    // « Choose wisely which spell to recall! » — sans ce canal, la carte perdrait
    // la seule décision qu'elle contient.
    boirePotion($elfe, 'Potion de rappel', ['sort_ids' => [$voulu]]);

    expect(DB::table('personnage_sorts')->where('personnage_id', $elfe->id)
        ->where('sort_id', $voulu)->value('disponible'))->toBeTruthy();
});

it('ramène Body ET Mind au maximum avec la Restauration supérieure', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $heros->update(['pv_body' => 1, 'pv_mind' => 1]);

    boirePotion($heros, 'Potion de restauration supérieure');
    $heros->refresh();

    // « to the level they were at when the hero started the Quest » : chez nous
    // c'est le MAXIMUM, puisque `DemarreurQuete` remet les deux jauges à leur
    // plafond au lancement de chaque quête.
    expect((int) $heros->pv_body)->toBe((int) $heros->pv_body_max)
        ->and((int) $heros->pv_mind)->toBe((int) $heros->pv_mind_max);
});
