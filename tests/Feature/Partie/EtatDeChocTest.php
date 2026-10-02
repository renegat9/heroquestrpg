<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\Equipement;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
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

/**
 * ÉTAT DE CHOC (*Against the Ogre Horde* p. 9, Hasbro — confirmée applicable
 * à toute créature ; René, 2026-10-01, qui REVIENT sur son arbitrage du
 * 2026-09-06 : « un héros à 0 Mind tombe »). « When a creature reaches 0 Mind
 * Points, they go into shock. [...] they can only roll one red movement die,
 * 1 Attack die, and 2 Defend dice. (Armor, weapons, and artifacts do not
 * increase the Attack or Defend dice [...].) The creature's Attack and
 * Defend dice can be temporarily increased by some spells [...]. If the
 * creature later restores Mind Points, they are no longer in shock. »
 *
 * Voir aussi `docs/regles/sorts-dread.md` et, pour le déplacement (même
 * lecture que `deplacement_sans_d6`, l'Armure de plates), les tests ajoutés
 * à `ArmureDePlatesPerdLeDeTest.php` — un seul harnais, une seule décision.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([
        ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, MobilierSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
    ]);
});

/** Équipe une épée large, une cotte de mailles et un casque — « broadsword +
 *  chain mail + helmet » — directement en base, puis recalcule les colonnes
 *  de combat EXACTEMENT comme l'équipement le ferait au hub. */
function armerLourdement(Personnage $heros): void
{
    Inventaire::insert([
        ['personnage_id' => $heros->id, 'objet_id' => Objet::where('nom', 'Épée large')->value('id'),
            'emplacement' => 'arme_principale', 'quantite' => 1],
        ['personnage_id' => $heros->id, 'objet_id' => Objet::where('nom', 'Cotte de mailles')->value('id'),
            'emplacement' => 'armure', 'quantite' => 1],
        ['personnage_id' => $heros->id, 'objet_id' => Objet::where('nom', 'Casque')->value('id'),
            'emplacement' => 'casque', 'quantite' => 1],
    ]);
    app(Equipement::class)->recalculerCombat($heros->fresh());
}

it("un héros en choc n'attaque plus qu'avec 1 dé, même équipé d'une épée large, d'une cotte de mailles et d'un casque", function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['heros' => $heros, 'etatHeros' => $etat, 'quete' => $quete, 'instance' => $instance, 'groupe' => $groupe] = $ctx;

    armerLourdement($heros);
    $heros->refresh();

    // Preuve que l'équipement vaut bien plus que le plafond de choc AVANT de
    // geler l'esprit — sinon le test ne prouverait rien.
    expect((int) $heros->des_attaque)->toBeGreaterThan(1);

    $heros->update(['pv_mind' => 0]);
    expect($heros->fresh()->estEnChoc())->toBeTrue();

    desFiges([1, 1]); // 1 dé d'attaque (choc), 1 dé de défense (Gobelin, catalogue)

    $payload = app(ResolveurTour::class)->frapper(
        $groupe, $quete->fresh(), $etat->fresh(), $heros->fresh(), $instance->fresh(),
    );

    expect($payload['des_attaque_effectifs'])->toBe(1);
});

it('un bonus de SORT (Courage) continue de s\'ajouter PAR-DESSUS le plafond d\'attaque du choc', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['heros' => $heros, 'etatHeros' => $etat, 'quete' => $quete, 'instance' => $instance, 'groupe' => $groupe] = $ctx;

    armerLourdement($heros);
    $heros->update(['pv_mind' => 0]);

    // Courage : +2 dés d'attaque, posé via la condition générique « Renforcé »
    // (SortSeeder) — attaché directement, sans repasser par tout le cycle de
    // lancer, pour isoler ce que `frapper()` fait du buff.
    $renforce = Condition::where('nom', 'Renforcé')->firstOrFail();
    $heros->conditions()->attach($renforce->id, ['duree' => 1, 'source' => 'sort:Courage']);

    desFiges([1, 2, 3, 1]); // 1 (choc) + 2 (Courage) = 3 dés d'attaque, 1 défense Gobelin

    $payload = app(ResolveurTour::class)->frapper(
        $groupe, $quete->fresh(), $etat->fresh(), $heros->fresh(), $instance->fresh(),
    );

    expect($payload['des_attaque_effectifs'])->toBe(3); // 1 (plafond) + 2 (Courage)
});

it('un héros en choc ne défend plus qu\'avec 2 dés, même en cotte de mailles et casque', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    armerLourdement($heros);
    $heros->refresh();
    expect((int) $heros->des_defense)->toBeGreaterThan(2);

    $heros->update(['pv_mind' => 0]);

    expect(app(MoteurSorts::class)->desDefenseHeros($heros->fresh()))->toBe(2);
});

it('un bonus de SORT (Peau de Pierre) continue de s\'ajouter PAR-DESSUS le plafond de défense du choc', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    armerLourdement($heros);
    $heros->update(['pv_mind' => 0]);

    $protege = Condition::where('nom', 'Protégé')->firstOrFail();
    $heros->conditions()->attach($protege->id, ['duree' => 1, 'source' => 'sort:Peau de Pierre']);

    expect(app(MoteurSorts::class)->desDefenseHeros($heros->fresh()))->toBe(3); // 2 (plafond) + 1 (Peau de Pierre)
});

it('regagner 1 point de Mind lève le choc et restaure IMMÉDIATEMENT les dés normaux, sans étape supplémentaire', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['heros' => $heros, 'etatHeros' => $etat, 'quete' => $quete, 'instance' => $instance, 'groupe' => $groupe] = $ctx;

    armerLourdement($heros);
    $heros->refresh();
    $desAttaqueNormal = (int) $heros->des_attaque;
    $desDefenseNormal = app(MoteurSorts::class)->desDefenseHeros($heros);

    $heros->update(['pv_mind' => 0]);
    expect($heros->fresh()->estEnChoc())->toBeTrue()
        ->and(app(MoteurSorts::class)->desDefenseHeros($heros->fresh()))->toBe(2);

    // Un seul point regagné (pas forcément le maximum) suffit à lever le choc
    // — « if the creature later restores Mind Points, they are no longer in
    // shock ». Aucun recalcul à déclencher à la main : `estEnChoc()` est lu
    // au moment du jet, jamais depuis une colonne figée.
    $heros->update(['pv_mind' => 1]);
    expect($heros->fresh()->estEnChoc())->toBeFalse()
        ->and((int) $heros->fresh()->des_attaque)->toBe($desAttaqueNormal)
        ->and(app(MoteurSorts::class)->desDefenseHeros($heros->fresh()))->toBe($desDefenseNormal);

    desFiges(array_fill(0, $desAttaqueNormal + 1, 1)); // dés normaux (attaque) + 1 défense Gobelin

    $payload = app(ResolveurTour::class)->frapper(
        $groupe, $quete->fresh(), $etat->fresh(), $heros->fresh(), $instance->fresh(),
    );

    expect($payload['des_attaque_effectifs'])->toBe($desAttaqueNormal);
});

it('publie `entites[].en_choc`, distinct de `tombe` — le héros reste DEBOUT', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $heros->update(['pv_mind' => 0]);

    $etat = test()->getJson('/api/groupes/table-1/etat')->assertOk()->json();

    $moi = collect($etat['entites'])->firstWhere('id', $heros->id);

    expect($moi)->not->toBeNull()
        ->and($moi['en_choc'])->toBeTrue()
        ->and($moi['tombe'])->toBeFalse()
        ->and($moi['des_attaque'])->toBe(1)
        ->and($moi['des_defense'])->toBe(2);
});

it('n\'est PAS un TPK : un groupe entièrement en choc reste `debout`, pas `tombe`', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $heros->update(['pv_mind' => 0]);

    $verdict = app(ResolveurTour::class)->verdictDeChute($ctx['groupe']->fresh(), $ctx['quete']->fresh());

    expect($verdict)->toBe('debout');
});

it('la migration lève le choc Mind-only (tombe=true, pv_mind=0, pv_body>0) et laisse un VRAI tombé Body intact', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];
    $quete = $ctx['quete'];
    $groupe = $ctx['groupe'];

    // État HÉRITÉ de l'ancienne règle (2026-09-06) : un héros encore debout
    // au corps, mais marqué `tombe` par un Gel de l'Esprit d'avant le
    // 2026-10-01.
    $heros->update(['pv_body' => 5, 'pv_mind' => 0]);
    $etat->update(['tombe' => true]);

    // Second héros : un VRAI tombé (0 Body ET 0 Mind) — ne doit PAS être
    // relevé par cette migration, qui ne lève que le cas Mind-SEUL.
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $autre = creerHeros($bob, $groupe, 'Brunhilde', 2, ['classe' => 'nain', 'pv_body' => 0, 'pv_mind' => 0]);
    $etatAutre = EtatPersonnageQuete::create([
        'personnage_id' => $autre->id,
        'quete_id' => $quete->id,
        'position_x' => 0,
        'position_y' => 0,
        'tombe' => true,
    ]);

    (require database_path('migrations/2026_10_01_000005_leve_choc_mind_un_fallen.php'))->up();

    expect($etat->fresh()->tombe)->toBeFalse()      // Mind-only : relevé
        ->and($etatAutre->fresh()->tombe)->toBeTrue(); // vrai tombé Body : intact

    // Idempotente : un second passage ne change plus rien et n'explose pas.
    (require database_path('migrations/2026_10_01_000005_leve_choc_mind_un_fallen.php'))->up();
    expect($etat->fresh()->tombe)->toBeFalse()
        ->and($etatAutre->fresh()->tombe)->toBeTrue();
});
