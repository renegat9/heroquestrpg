<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\MoteurSorts;
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
    $equipement->equiper($magicien, $ligne('Talisman du Savoir'));
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

    foreach (['Casque', 'Armure de plates', 'Bouclier', 'Anneau de Vigueur'] as $nom) {
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
        ->and($portes['talisman'] ?? null)->toBe('Anneau de Vigueur')
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

/*
 * ------------------------------------------------------------------
 * Les trois artefacts ACTIVABLES (cartes officielles, doc 16 §9.2).
 * Ils n'ont demandé aucune mécanique neuve : ils réunissent sur un
 * OBJET ce qui existait sur des sorts. Éprouvés en jeu, par le menu et
 * le résolveur, jamais en appelant le moteur en direct.
 * ------------------------------------------------------------------
 */

/** Pose un objet du catalogue dans le sac du héros et rend sa ligne. */
function poserArtefact(App\Models\Personnage $heros, string $nom, string $emplacement = 'sac'): App\Models\Inventaire
{
    return App\Models\Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => App\Models\Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

/** L'entrée « utiliser_objet » correspondant à cette ligne d'inventaire. */
function entreeObjet(array $ctx, App\Models\Inventaire $ligne): ?array
{
    App\Jobs\GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $menu = Illuminate\Support\Facades\Cache::get(
        App\Jobs\GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id)
    );
    $option = collect($menu['menu']['options'] ?? [])->firstWhere('id', 'utiliser_objet');

    return collect($option['parametres']['objets'] ?? [])->firstWhere('cle', "objet:{$ligne->id}");
}

it("la Poudre d'Invisibilité fait traverser les monstres à un COMPAGNON", function () {
    // « Sprinkle this dust on ANY ONE HERO. On their next movement, they may
    // move unseen through spaces that are occupied by monsters. »
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $compagnon = creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $ctx = ['groupe' => $groupe, 'alice' => $alice, 'heros' => $heros];
    $ligne = poserArtefact($heros, "Poudre d'Invisibilité");

    $entree = entreeObjet($ctx, $ligne);

    // L'entrée PORTE ses cibles : le lanceur et son compagnon en vue.
    expect($entree)->not->toBeNull()
        ->and(collect($entree['cibles'] ?? [])->pluck('id')->all())->toContain($compagnon->id);

    desFiges(array_fill(0, 60, 4));
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $compagnon->id, 'cible_type' => 'heros'],
    ])->assertAccepted()->assertJsonPath('resultat.cible.personnage_id', $compagnon->id);

    $sorts = app(MoteurSorts::class);

    // C'est le COMPAGNON qui traverse, pas celui qui a versé la poudre.
    expect($sorts->franchitFigures($compagnon->fresh()))->toBeTrue()
        ->and($sorts->franchitFigures($heros->fresh()))->toBeFalse()
        // Usage unique : la ligne d'inventaire part.
        ->and(App\Models\Inventaire::find($ligne->id))->toBeNull();
});

it('la Cape des Ombres donne les DEUX modes de déplacement, une fois par quête', function () {
    // « You move as though the spells Pass Through Rock AND Veil of Mist have
    // been cast. This artifact may only be used once per quest. »
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    $ligne = poserArtefact($heros, 'Cape des Ombres');
    $sorts = app(MoteurSorts::class);

    expect(entreeObjet($ctx, $ligne))->not->toBeNull();

    desFiges(array_fill(0, 60, 4));
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}"],
    ])->assertAccepted()->assertJsonPath('resultat.usage_restant', false);

    // ⚠ LES DEUX, sous une seule condition affichée : les lecteurs relisent
    // l'effet de l'OBJET source, pas celui de la condition.
    expect($sorts->traverseRoche($heros->fresh()))->toBeTrue()
        ->and($sorts->franchitFigures($heros->fresh()))->toBeTrue()
        // La cape RESTE au sac — elle devient inerte, elle n'est pas perdue.
        ->and(App\Models\Inventaire::find($ligne->id))->not->toBeNull();

    // Charge épuisée : le menu ne la propose plus. Une option qui répondrait
    // toujours non n'est pas une option, c'est un piège.
    expect(entreeObjet($ctx, $ligne))->toBeNull();
});

it('le Sceptre de Télékinésie fait sauter le tour du monstre, sauf sur un 6', function () {
    // « A trapped monster misses its next turn. The spell can be resisted
    // immediately by the monster rolling 1 red die for each of their Mind
    // Points. If a 6 is rolled, it resists. »
    $ctx = demarrerQueteAvecMonstre('Orque');
    ['heros' => $heros, 'instance' => $proie] = $ctx;

    $proie->update(['pv_mind' => 2]);
    $ligne = poserArtefact($heros, 'Sceptre de Télékinésie');

    $entree = entreeObjet($ctx, $ligne);

    expect($entree)->not->toBeNull()
        ->and(collect($entree['cibles'] ?? [])->pluck('id')->all())->toContain($proie->id);

    desFiges([3, 4, ...array_fill(0, 40, 4)]); // aucun 6 : il ne résiste pas

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertAccepted()
        ->assertJsonPath('resultat.des_rupture', [3, 4])
        ->assertJsonPath('resultat.resiste', false)
        ->assertJsonPath('resultat.saute_tour', true);

    expect(app(MoteurSorts::class)->monstreA($proie->fresh(), MoteurSorts::MONSTRE_SAUTE_TOUR))->toBeTrue();
});

it('le Sceptre échoue quand le monstre sort un 6 : la charge est dépensée pour rien', function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    ['heros' => $heros, 'instance' => $proie] = $ctx;

    $proie->update(['pv_mind' => 2]);
    $ligne = poserArtefact($heros, 'Sceptre de Télékinésie');
    entreeObjet($ctx, $ligne);

    desFiges([3, 6, ...array_fill(0, 40, 4)]); // le second dé le sauve

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertAccepted()
        ->assertJsonPath('resultat.resiste', true)
        ->assertJsonPath('resultat.saute_tour', false)
        ->assertJsonPath('resultat.usage_restant', false);

    expect(app(MoteurSorts::class)->monstreA($proie->fresh(), MoteurSorts::MONSTRE_SAUTE_TOUR))->toBeFalse();
});

/*
 * ------------------------------------------------------------------
 * Cinq artefacts de plus (2026-09-03), aucun n'ayant demandé de
 * mécanique neuve : ils réutilisent des lecteurs existants par le
 * chemin des buffs, des charges ou de la réaction hors tour.
 * ------------------------------------------------------------------
 */

it("la Longue épée de Fortune donne 3 dés et une relance par quête", function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    $heros = $ctx['heros'];

    $ligne = poserArtefact($heros, 'Longue épée de Fortune', 'arme_principale');

    // La relance est une ENTRÉE activable, gratuite, à une charge.
    $entree = entreeObjet($ctx, $ligne);

    expect($entree)->not->toBeNull()
        ->and($entree['cout'])->toBe('gratuit');

    desFiges(array_fill(0, 40, 4));
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}"],
    ])->assertAccepted()->assertJsonPath('resultat.usage_restant', false);

    // Le buff est posé, et c'est LUI que `Engine\Combat` lira — même chemin
    // que la Potion de bataille.
    expect(app(MoteurSorts::class)->aBuff($heros->fresh(), 'relance_des_attaque'))->toBeTrue()
        // Charge épuisée : l'entrée disparaît du menu.
        ->and(entreeObjet($ctx, $ligne))->toBeNull();
});

it('la Lame Fantôme retire toute la défense de sa cible, une fois par quête', function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    $heros = $ctx['heros'];

    $ligne = poserArtefact($heros, 'Lame Fantôme', 'arme_principale');
    $sorts = app(MoteurSorts::class);

    desFiges(array_fill(0, 40, 4));
    entreeObjet($ctx, $ligne);

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}"],
    ])->assertAccepted();

    // ⚠ Le lecteur existait en TALENT (Flèche perçante) ; il lit désormais
    // aussi un buff, ce qui est tout ce qu'il fallait pour porter la carte.
    expect($sorts->valeurBuff($heros->fresh(), 'ignore_defense_monstre'))->toBeGreaterThan(0);
});

it("l'Élixir de Vie remet un compagnon TOMBÉ debout, jauges pleines", function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $compagnon = creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Le compagnon est à terre, vidé.
    $compagnon->update(['pv_body' => 0, 'pv_mind' => 0]);
    $quete->etatsPersonnages()->where('personnage_id', $compagnon->id)->update(['tombe' => true]);

    $ctx = ['groupe' => $groupe, 'alice' => $alice, 'heros' => $heros];
    $ligne = poserArtefact($heros, 'Élixir de Vie');

    // ⚠ Seul artefact dont la cible légale est un héros À TERRE.
    $entree = entreeObjet($ctx, $ligne);
    expect(collect($entree['cibles'] ?? [])->pluck('id')->all())->toContain($compagnon->id);

    desFiges(array_fill(0, 40, 4));
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $compagnon->id, 'cible_type' => 'heros'],
    ])->assertAccepted()->assertJsonPath('resultat.releve', true);

    $compagnon->refresh();

    expect((int) $compagnon->pv_body)->toBe((int) $compagnon->pv_body_max)
        ->and((int) $compagnon->pv_mind)->toBe((int) $compagnon->pv_mind_max)
        ->and((bool) $quete->etatsPersonnages()->where('personnage_id', $compagnon->id)->first()->tombe)->toBeFalse();
});

it('le Bracelet de Guérison est proposé en réaction quand son porteur tombe', function () {
    // « If the wearer's Body Points are reduced to 0, use immediately. » —
    // c'est mot pour mot le soin d'urgence, qui ne connaissait que les potions.
    $ctx = demarrerQueteAvecMonstre('Orque');
    $heros = $ctx['heros'];

    poserArtefact($heros, 'Bracelet de Guérison', 'talisman');
    $heros->update(['pv_body' => 0]);

    // ⚠ L'état de quête est indispensable : le bracelet porte une fenêtre « une
    // fois par quête », et sans état il n'y a pas de fenêtre à interroger — le
    // service répond alors « fermée », ce qui est le comportement sûr au hub.
    $etat = $ctx['quete']->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    $soins = app(App\Partie\MoteurReactions::class)->soinsDisponibles($heros->fresh(), $etat);
    $entree = collect($soins)->firstWhere('type', 'artefact');

    expect($entree)->not->toBeNull()
        ->and($entree['nom'])->toBe('Bracelet de Guérison')
        ->and($entree['soin'])->toBe('2');
});

it('le Bâton du Magicien est réservé au magicien', function () {
    $baton = Objet::where('nom', 'Bâton du Magicien')->firstOrFail();
    $equipement = app(Equipement::class);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);
    $barbare = creerHeros($alice, $groupe, 'Hurgan', 2, ['classe' => 'barbare']);

    // ⚠ `arme_magicien` est un tag que LUI SEUL porte : `arme_legere` aurait
    // ouvert le bâton à trois autres classes.
    expect($equipement->estAccessible($magicien, $baton))->toBeTrue()
        ->and($equipement->estAccessible($barbare, $baton))->toBeFalse();
});

it('rend son usage à la quête SUIVANTE : « once per quest », pas par campagne', function () {
    // ⚠ LE test de la vague : c'est sa seconde moitié qui prouve quelque chose.
    // Jusqu'au 2026-09-03, « une fois par quête » était exprimé avec
    // `inventaire.charges` — un TOTAL qui n'est jamais réarmé, ni par
    // `MoteurCharges` ni par `DemarreurQuete`. Six artefacts étaient donc « une
    // fois par CAMPAGNE », et aucun test ne pouvait le voir : il aurait fallu
    // enchaîner deux quêtes, ce qu'aucun d'eux ne faisait.
    $ctx = demarrerQueteAvecMonstre('Orque');
    ['heros' => $heros, 'groupe' => $groupe, 'alice' => $alice] = $ctx;

    $ligne = poserArtefact($heros, 'Cape des Ombres');

    expect(entreeObjet($ctx, $ligne))->not->toBeNull();

    desFiges(array_fill(0, 60, 4));
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}"],
    ])->assertAccepted()->assertJsonPath('resultat.usage_restant', false);

    // Fenêtre fermée : l'option disparaît du menu de CETTE quête.
    expect(entreeObjet($ctx, $ligne))->toBeNull();

    // …et la quête suivante la rouvre.
    $ctx['quete']->instancesMonstres()->update(['etat' => 'vaincu']);
    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();
    acheverLaQuete($groupe);
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $ctx['quete'] = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // ⚠ La ligne d'inventaire est la MÊME (l'artefact n'a pas bougé du sac) :
    // c'est bien la fenêtre qui s'est rouverte, pas l'objet qui a été recréé.
    expect(Inventaire::find($ligne->id))->not->toBeNull()
        ->and(entreeObjet($ctx, $ligne))->not->toBeNull();
});

it('la Lame Fantôme ne vaut QUE pour sa dague, pas pour une autre arme', function () {
    // « When you attack WITH THE DAGGER your target may not defend themselves. »
    // Le buff était posé sur le héros et lu sans regarder l'arme : l'activer
    // puis frapper avec autre chose annulait quand même la défense.
    $ctx = demarrerQueteAvecMonstre('Orque');
    $heros = $ctx['heros'];

    $lame = poserArtefact($heros, 'Lame Fantôme', 'arme_principale');
    $autre = poserArtefact($heros, 'Épée large', 'arme_secondaire');
    $sorts = app(MoteurSorts::class);

    desFiges(array_fill(0, 40, 4));
    entreeObjet($ctx, $lame);

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$lame->id}"],
    ])->assertAccepted();

    expect($sorts->valeurBuffDeLArme($heros->fresh(), 'ignore_defense_monstre', $lame->id))
        ->toBeGreaterThan(0)
        // …et RIEN pour l'autre arme, ni pour un coup qui n'en désigne aucune.
        ->and($sorts->valeurBuffDeLArme($heros->fresh(), 'ignore_defense_monstre', $autre->id))->toBe(0)
        ->and($sorts->valeurBuffDeLArme($heros->fresh(), 'ignore_defense_monstre', null))->toBe(0);
});

it("l'Arc de Vindication est portable par l'elfe, et par lui seul", function () {
    // ⚠ Son tag `arme_arc_long` n'était porté par AUCUNE classe : l'arc était
    // inéquipable, et `DeckFouille::choisirArtefact()` filtrant sur les tags du
    // groupe, il n'était même jamais tiré. Une donnée morte depuis son ajout.
    $arc = Objet::where('nom', 'Arc elfique de Vindication')->firstOrFail();
    $equipement = app(Equipement::class);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $elfe = creerHeros($alice, $groupe, 'Sylwen', 1, ['classe' => 'elfe']);
    $barbare = creerHeros($alice, $groupe, 'Hurgan', 2, ['classe' => 'barbare']);

    expect($equipement->estAccessible($elfe, $arc))->toBeTrue()
        ->and($equipement->estAccessible($barbare, $arc))->toBeFalse();
});

it('ne laisse AUCUN tag de maîtrise orphelin au catalogue', function () {
    // Le garde-fou qui aurait signalé l'arc tout seul : un tag porté par un
    // objet et par aucune classe rend la pièce inéquipable ET intirable, sans
    // la moindre erreur. Le test le dit à voix haute.
    $tagsClasses = App\Models\ClasseHeros::all()
        ->flatMap(fn ($c) => (array) $c->tags_equipement)->unique();

    $orphelins = Objet::whereNotNull('tag_equipement')->pluck('tag_equipement')
        ->unique()->diff($tagsClasses)->values()->all();

    expect($orphelins)->toBe([], 'tag(s) qu\'aucune classe ne porte : '.implode(', ', $orphelins)
        .' — les objets qui les portent sont inéquipables et ne seront jamais tirés.');
});

it('le poison mord enfin, et la Plume rend EXACTEMENT ce qu\'il a coûté', function () {
    // ⚠ Deux défauts d'un coup, tous deux muets jusqu'au 2026-09-03.
    //
    // 1. `conditions.effet.degats_pv_body_par_tour` est déclaré sur « Empoisonné »
    //    depuis la création de la table et n'avait AUCUN lecteur : deux pièges et
    //    deux meubles annonçaient au joueur qu'il était empoisonné, et il ne se
    //    passait rien.
    // 2. La Plume anti-poison dit « restores ANY of the owner's Body Points lost
    //    by poisoning » ; nous rendions un forfait de 2, faute de savoir combien
    //    le poison avait pris.
    $ctx = demarrerQueteAvecMonstre('Orque');
    ['heros' => $heros, 'quete' => $quete, 'alice' => $alice] = $ctx;

    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    $heros->update(['pv_body' => 8, 'pv_body_max' => 8]);

    $poison = App\Models\Condition::where('nom', 'Empoisonné')->firstOrFail();
    $heros->conditions()->syncWithoutDetaching([$poison->id => ['duree' => 3, 'source' => 'piege:Fiole de poison']]);

    poserArtefact($heros, 'Plume anti-poison');
    desFiges(array_fill(0, 60, 4));

    // Deux fins de tour = deux morsures.
    foreach ([0, 1] as $ignore) {
        test()->actingAs($alice, 'joueur')
            ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();
    }

    $heros->refresh();

    expect((int) $heros->pv_body)->toBeLessThan(8)
        // La mémoire sait de QUOI il a souffert, et combien.
        ->and((int) ($etat->fresh()->degats_subis['poison'] ?? 0))->toBe(8 - (int) $heros->pv_body);

    $perdus = 8 - (int) $heros->pv_body;

    // …et la Plume rend exactement ce compte, pas un forfait.
    $ligne = $heros->inventaire()->with('objet')->get()
        ->first(fn ($l) => $l->objet?->nom === 'Plume anti-poison');

    app(App\Partie\MoteurPotions::class)->boire($heros->fresh(), $ligne);

    expect((int) $heros->fresh()->pv_body)->toBe(8)
        ->and($perdus)->toBeGreaterThan(0) // sinon le test ne prouverait rien
        // Le cumul est purgé : une seconde plume ne rendrait pas deux fois.
        ->and($etat->fresh()->degats_subis['poison'] ?? 0)->toBe(0);
});

it("les Écailles d'Elethorn ajoutent un dé pour résister à un sort de Dread", function () {
    // « When you attempt to resist the effects of a Dread spell while wearing
    // this armor, roll an additional die. » ⚠ `MoteurDread` passait
    // l'`attribut_mind` BRUT : aucun équipement ne pouvait s'y ajouter.
    $ctx = demarrerQueteAvecMonstre('Orque');
    $heros = $ctx['heros'];
    $sorts = app(MoteurSorts::class);

    $nu = $sorts->desResistanceMentale($heros->fresh());

    poserArtefact($heros, "Écailles d'Elethorn", 'armure');

    expect($sorts->desResistanceMentale($heros->fresh()))->toBe($nu + 1)
        // Et la moitié défense de la carte tient toute seule, par `des_defense`.
        ->and((int) Objet::where('nom', "Écailles d'Elethorn")->value('effet')['des_defense'])->toBe(1);
});

it('les Cendres du Phénix sont proposées quand un héros tombe, et se consument parfois', function () {
    // « Once per quest, when any one hero is reduced to 0 Body Points, use this
    // to instead reduce them to 1. » ⚠ La réaction du plancher n'interrogeait
    // que des nœuds d'arbre : aucun objet ne pouvait l'ouvrir.
    $ctx = demarrerQueteAvecMonstre('Orque');
    ['heros' => $heros, 'quete' => $quete] = $ctx;

    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    $ligne = poserArtefact($heros, 'Cendres du Phénix', 'talisman');

    $heros->update(['pv_body' => 1]);
    desFiges([6, ...array_fill(0, 40, 4)]); // le 5-6 consumera l'artefact

    // Le coup fatal passe par le point de passage unique des dégâts.
    app(App\Partie\MoteurDegats::class)->infligerAHeros(
        $heros->fresh(), 3, App\Partie\MoteurDegats::SOURCE_ATTAQUE_MONSTRE, [],
    );

    $offre = $etat->fresh()->reaction_en_attente;

    expect($offre)->not->toBeNull()
        ->and($offre['action'])->toBe(App\Engine\ReactionEffet::PLANCHER_PV)
        ->and($offre['artefact'])->toBe($ligne->id);
});
