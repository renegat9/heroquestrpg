<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Grille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/**
 * Les ARTEFACTS du paquet (reference/16_armurerie.md §9).
 *
 * Ils ont remplacé 7 armes inventées qui ne faisaient que monter en dés (4, 5,
 * puis 6). Un vrai artefact a un POUVOIR, et ce fichier vérifie que chacun de
 * ces pouvoirs est réellement appliqué — sans quoi on aurait juste échangé des
 * chiffres décoratifs contre des noms officiels.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Attaque la cible en passant par le menu, comme un vrai client : le
 * contrôleur refuse toute option absente du DERNIER menu publié.
 */
function attaquer(array $ctx): TestResponse
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ]);
}

/**
 * Éloigne la cible du héros, sur une case de sol en ligne de vue dégagée : la
 * dague magique « cannot be used on an adjacent target ».
 */
function eloignerCible(Quete $quete, InstanceMonstre $instance, int $hx, int $hy): void
{
    $grille = Grille::depuisCarte($quete->carte);

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true) || abs($x - $hx) + abs($y - $hy) < 2) {
                continue;
            }

            if ($grille->ligneDeVue($hx, $hy, $x, $y)) {
                $instance->update(['position_x' => $x, 'position_y' => $y]);

                return;
            }
        }
    }

    throw new RuntimeException('Aucune case à distance avec ligne de vue.');
}

/** Met l'objet directement dans la main du héros et recalcule ses dés. */
function armerDe(Personnage $personnage, string $nom): void
{
    Inventaire::create([
        'personnage_id' => $personnage->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => 'arme_principale',
        'quantite' => 1,
    ]);

    app(Equipement::class)->recalculerCombat($personnage->refresh());
}

// ---------------------------------------------------------------------------
// Lame des Esprits — « three combat dice OR four against undead »
// ---------------------------------------------------------------------------

it('la Lame des Esprits lance 4 dés contre un mort-vivant, 3 contre le reste', function () {
    // Momie : l'un des trois noms que la carte énumère.
    $ctx = demarrerQueteAvecMonstre('Momie');
    armerDe($ctx['heros'], 'Lame des Esprits');

    desFiges(array_fill(0, 30, 4)); // que des boucliers : le résultat n'importe pas

    attaquer($ctx)->assertStatus(202)->assertJsonPath('resultat.des_attaque_effectifs', 4);
});

it('la Lame des Esprits reste à 3 dés contre une créature vivante', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    armerDe($ctx['heros'], 'Lame des Esprits');

    desFiges(array_fill(0, 30, 4));

    attaquer($ctx)->assertStatus(202)->assertJsonPath('resultat.des_attaque_effectifs', 3);
});

// ---------------------------------------------------------------------------
// Fléau des Orques — « You may attack TWICE if you are fighting Orcs »
// ---------------------------------------------------------------------------

it('le Fléau des Orques accorde une SECONDE attaque contre un orque', function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    armerDe($ctx['heros'], 'Fléau des Orques');

    desFiges(array_fill(0, 40, 4)); // boucliers : l'orque survit et reste ciblable

    attaquer($ctx)->assertStatus(202)->assertJsonPath('resultat.attaque_supplementaire', true);

    $etat = $ctx['etatHeros']->fresh();
    expect((bool) $etat->a_agi)->toBeTrue()          // le créneau d'action est bien pris…
        ->and((bool) $etat->attaque_supplementaire)->toBeTrue(); // …mais une frappe reste due

    // Et cette seconde frappe est acceptée, alors que le créneau est consommé.
    attaquer($ctx)->assertStatus(202);

    // Elle ne s'enchaîne PAS indéfiniment : le bonus est retombé.
    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse();
});

it('le Fléau des Orques n\'accorde rien contre une autre créature', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    armerDe($ctx['heros'], 'Fléau des Orques');

    desFiges(array_fill(0, 30, 4));

    attaquer($ctx)->assertStatus(202)->assertJsonMissingPath('resultat.attaque_supplementaire');

    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Dague de jet magique — « always inflicts one Body Point of damage »
// ---------------------------------------------------------------------------

it('la Dague de jet magique inflige 1 PV sans lancer le moindre dé', function () {
    $ctx = demarrerQueteAvecMonstre('Orque'); // 2 PV : il survit au point unique
    armerDe($ctx['heros'], 'Dague de jet magique');

    // « It cannot be used on an adjacent target » : on éloigne la cible, sinon
    // le moteur refuse (à raison) l'attaque au contact.
    eloignerCible($ctx['quete'], $ctx['instance'],
        (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    $pvAvant = (int) $ctx['instance']->fresh()->pv_body;

    // Le menu d'abord (il lance le d6 de déplacement), PUIS la file vidée :
    // si l'attaque lançait le moindre dé, elle exploserait.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges([]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.degats', 1)
        ->assertJsonPath('resultat.faces_attaque', [])
        ->assertJsonPath('resultat.faces_defense', []);

    expect((int) $ctx['instance']->fresh()->pv_body)->toBe($pvAvant - 1);
});

// ---------------------------------------------------------------------------
// Talismans — « adds 2 Body points and 1 Mind point to the … totals »
// ---------------------------------------------------------------------------

it('un talisman de classe relève les jauges MAXIMALES, et les reprend au retrait', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barbare = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'barbare']);

    $bodyMax = (int) $barbare->pv_body_max;
    $mindMax = (int) $barbare->pv_mind_max;

    $equipement = app(Equipement::class);
    $ligne = Inventaire::create([
        'personnage_id' => $barbare->id,
        'objet_id' => Objet::where('nom', 'Amulette du Nord')->firstOrFail()->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    $equipement->equiper($barbare, $ligne);
    $barbare->refresh();

    // Les points sont DONNÉS, pas seulement le plafond relevé : « adds 2 Body
    // points … to the totals ». Un héros à PV pleins reste à PV pleins.
    expect((int) $barbare->pv_body_max)->toBe($bodyMax + 2)
        ->and((int) $barbare->pv_mind_max)->toBe($mindMax + 1)
        ->and((int) $barbare->pv_body)->toBe($bodyMax + 2);

    // Le talisman ne touche pas aux dés : ce n'est pas une armure.
    expect((int) $barbare->des_defense)->toBe(2);

    $equipement->desequiper($barbare, $ligne->fresh());
    $barbare->refresh();

    expect((int) $barbare->pv_body_max)->toBe($bodyMax)
        ->and((int) $barbare->pv_body)->toBe($bodyMax); // écrêté, jamais au-dessus du max
});

it('réserve chaque talisman à sa classe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    $equipement = app(Equipement::class);

    $ligne = fn (string $nom) => Inventaire::create([
        'personnage_id' => $magicien->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    // La capuche est la sienne…
    $mindMax = (int) $magicien->pv_mind_max;
    $equipement->equiper($magicien, $ligne('Capuche du Magister'));
    expect((int) $magicien->fresh()->pv_mind_max)->toBe($mindMax + 1);

    // …l'amulette du barbare, non.
    expect(fn () => $equipement->equiper($magicien, $ligne('Amulette du Nord')))
        ->toThrow(ValidationException::class);
});

it('n\'empêche pas le talisman de cohabiter avec l\'armure et le casque', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $nain = creerHeros($alice, $groupe, 'Borin', 1, ['classe' => 'nain']);

    $equipement = app(Equipement::class);

    foreach (['Casque', 'Armure de plates', 'Bouclier', 'Runes naines'] as $nom) {
        $equipement->equiper($nain, Inventaire::create([
            'personnage_id' => $nain->id,
            'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
            'emplacement' => 'sac', 'quantite' => 1,
        ]));
    }

    $portes = $nain->fresh()->inventaire()->with('objet')->get()
        ->mapWithKeys(fn ($l) => [$l->emplacement => $l->objet->nom]);

    // Cinq emplacements distincts : le talisman n'a chassé personne.
    expect($portes['casque'] ?? null)->toBe('Casque')
        ->and($portes['armure'] ?? null)->toBe('Armure de plates')
        ->and($portes['arme_secondaire'] ?? null)->toBe('Bouclier')
        ->and($portes['talisman'] ?? null)->toBe('Runes naines')
        ->and((int) $nain->fresh()->des_defense)->toBe(6);
});

// ---------------------------------------------------------------------------
// Le coffre du fond
// ---------------------------------------------------------------------------

it('ne propose au coffre que des artefacts PORTABLES, jamais un consommable', function () {
    // La Fiole de soin est `unique` elle aussi, mais c'est une carte du deck de
    // fouille : le coffre le plus profond du donjon ne doit pas verser une
    // fiole à usage unique après une quête entière d'exploration.
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    $artefact = Objet::find($ctx['quete']->artefact_objet_id);

    expect($artefact)->not->toBeNull()
        ->and($artefact->rarete)->toBe('unique')
        ->and($artefact->categorie)->toBeIn(['arme', 'armure']);
});

it('expose le talisman sur la fiche du héros (/moi)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $elfe = creerHeros($alice, $groupe, 'Lindir', 1, ['classe' => 'elfe']);

    app(Equipement::class)->equiper($elfe, Inventaire::create([
        'personnage_id' => $elfe->id,
        'objet_id' => Objet::where('nom', 'Brassards elfiques')->firstOrFail()->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]));

    // Sans cette clé, la manette affichait le héros sans son talisman et ne
    // proposait aucun moyen de le retirer.
    $perso = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))
        ->firstWhere('id', $elfe->id);

    expect($perso['equipement']['talisman']['nom'] ?? null)->toBe('Brassards elfiques');
});
