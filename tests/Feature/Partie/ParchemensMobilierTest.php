<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\RareteButin;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Marche\PhaseMarche;
use App\Partie\MoteurMobilier;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * PARCHEMINS : on ne les achète plus, on les TROUVE (décision de René, 2026-10-16 :
 * « les parchemins ne sont plus vendus ni achetés au marché ; on les trouve en fouillant
 * le mobilier, d'abord la bibliothèque et l'établi d'alchimiste »).
 *
 * Trois choses à tenir, et chacune a son test :
 *  1. le MARCHÉ n'en vend ni n'en rachète, par les routes réelles et à tous les profils ;
 *  2. la BIBLIOTHÈQUE et l'ÉTABLI D'ALCHIMISTE en rendent, par leur table de fouille ;
 *  3. un parchemin trouvé entre au SAC du fouilleur, annoncé, et ne se revend plus.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : celui-ci dérive un parchemin par sort.
    // ClasseHerosSeeder porte `tags_equipement` ; sans lui le filtre de maîtrise
    // passe à vide (fail open) et ces tests seraient verts sans rien tester.
    $this->seed([
        SortSeeder::class, MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class,
    ]);
});

/** Une ligne au sac d'un héros, par le nom du catalogue (un parchemin vit en `consommable`). */
function pfDonner(int $personnageId, string $nomObjet, string $emplacement = 'consommable'): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $personnageId,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

/**
 * Une quête démarrée PAR LA ROUTE, un second héros pour que la phase des monstres
 * ne s'enchaîne pas, et un meuble de type `$nomMeuble` posé à CÔTÉ du premier héros
 * (une case ajoutée à la carte, comme `DeckFouilleTest`). Le menu est généré, et
 * l'option « Fouiller : … » du meuble doit s'y trouver.
 *
 * @return array{0: JoueurAuthentifiable, 1: Groupe, 2: \App\Models\Personnage, 3: Quete, 4: int}
 */
function pfMeubleAuContact(string $nomMeuble): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    $grille = $quete->carte->grille;
    $px = (int) $etat->position_x;
    $py = (int) $etat->position_y;
    $x = $px + 1 < (int) $grille['largeur'] ? $px + 1 : $px - 1;

    $index = count($grille['mobilier'] ?? []);
    $grille['mobilier'][] = [
        'mobilier_id' => Mobilier::where('nom', $nomMeuble)->firstOrFail()->id,
        'x' => $x, 'y' => $py, 'l' => 1, 'h' => 1, 'salle' => 0,
    ];
    $quete->carte->update(['grille' => $grille]);

    // Salle du meuble visible, comme la fouille réelle le suppose (DeckFouilleTest).
    $quete->marquerSalleDecouverte(0);
    $etat->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];

    $ids = collect($menu['options'])->pluck('id')->all();

    // ⚠ `toContain` de Pest prend N valeurs ATTENDUES, jamais un message : on teste en booléen.
    expect(in_array("fouiller_mobilier_{$index}", $ids, true))->toBeTrue(
        "« Fouiller : {$nomMeuble} » absent du menu : le meuble de test n'est pas au contact.");

    return [$alice, $groupe, $hero, $quete, $index];
}

// ---------------------------------------------------------------------------
// 1. LA DONNÉE — la bibliothèque et l'établi rendent un parchemin
// ---------------------------------------------------------------------------

it('la BIBLIOTHÈQUE et l\'ÉTABLI D\'ALCHIMISTE ont chacun une chance de parchemin dans leur table, et gardent leur « rien »', function () {
    foreach (['Bibliothèque', 'Établi d\'alchimiste'] as $nom) {
        $table = collect(Mobilier::where('nom', $nom)->firstOrFail()->effet['fouille'] ?? []);

        $parchemins = $table->where('issue', 'objet')
            ->filter(fn (array $e) => ($e['categories'] ?? []) === ['parchemin']);

        expect($parchemins->sum('poids'))->toBeGreaterThan(0, "{$nom} : aucune chance de parchemin dans sa table de fouille.");
        expect(in_array('rien', $table->pluck('issue')->all(), true))->toBeTrue("{$nom} : le parchemin a mangé le « rien ».");
    }

    // L'établi reste un établi : des fioles d'abord, le parchemin en plus.
    $categoriesEtabli = collect(Mobilier::where('nom', 'Établi d\'alchimiste')->firstOrFail()->effet['fouille'])
        ->where('issue', 'objet')->pluck('categories')->flatten()->unique()->sort()->values()->all();

    expect($categoriesEtabli)->toBe(['consommable', 'parchemin']);
});

it('un meuble qui rend un parchemin le tire dans le catalogue RÉEL : au moins un sur quelques centaines de fouilles', function () {
    $mm = app(MoteurMobilier::class);

    foreach (['Bibliothèque' => 1, 'Établi d\'alchimiste' => 8] as $nom => $niveau) {
        $meuble = Mobilier::where('nom', $nom)->firstOrFail();

        $tires = collect(range(1, 400))
            ->map(fn () => $mm->tirerButin($meuble, $niveau))
            ->where('issue', 'objet');

        $parchemins = Objet::whereIn('id', $tires->pluck('objet_id')->unique())->get();
        $parchemins = $parchemins->where('categorie', 'parchemin');

        expect($parchemins)->not->toBeEmpty("{$nom} (niveau {$niveau}) : aucun parchemin sur 400 fouilles.");

        // La rareté suit la difficulté du sort (commun / peu commun / rare), jamais « unique ».
        foreach ($parchemins as $p) {
            expect($p->rarete)->toBeIn(RareteButin::ECHELLE, "{$p->nom} : rareté hors de l'échelle de butin.");
        }
    }
});

// ---------------------------------------------------------------------------
// 2. LE MARCHÉ n'en vend ni n'en rachète
// ---------------------------------------------------------------------------

it('l\'étal ne vend AUCUN parchemin, quel que soit le profil demandé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Krogar', 1, ['classe' => 'barbare']);

    // Le catalogue en a bien : sinon le test ne prouve rien.
    expect(Objet::where('categorie', 'parchemin')->count())->toBeGreaterThan(0);

    foreach (['village', 'bourg', 'cite', 'marche_noir'] as $profil) {
        Cache::flush();

        $etal = collect(app(PhaseMarche::class)->ouvrir($groupe->fresh(), $profil)['inventaire']);

        expect(in_array('parchemin', $etal->pluck('categorie')->all(), true))->toBeFalse(
            "profil « {$profil} » : un parchemin est encore à l'étal.");
    }
});

it('refuse d\'ACHETER un parchemin par la route : 422, comme un objet hors étal', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 1000]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [['objet_id' => Objet::where('nom', 'Parchemin : Soin du Corps')->firstOrFail()->id]],
        'ventes' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('achats');
});

it('refuse, à la CONFIRMATION, l\'achat d\'un parchemin venu d\'une phase ouverte AVANT ce changement', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 1000]);
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    // Le cache garde le parchemin dans le panier d'Alice : c'est l'application qui doit le refuser.
    $parchemin = Objet::where('nom', 'Parchemin : Soin du Corps')->firstOrFail();
    $phase = Cache::get(PhaseMarche::cle($groupe->id));
    $phase['paniers'][(string) $alice->id]['achats'] = [[
        'objet_id' => $parchemin->id,
        'nom' => $parchemin->nom,
        'quantite' => 1,
        'prix_unitaire' => 100,
        'total' => 100,
        'personnage_id' => $hero->id,
    ]];
    Cache::put(PhaseMarche::cle($groupe->id), $phase, now()->addMinutes(60));

    $this->postJson('/api/groupes/table-1/marche/confirmation')
        ->assertStatus(422)
        ->assertJsonValidationErrors('achats');

    expect(Inventaire::where('personnage_id', $hero->id)->where('objet_id', $parchemin->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 3. LE PARCHEMIN TROUVÉ : au sac, annoncé, et invendable
// ---------------------------------------------------------------------------

it('un parchemin trouvé à l\'établi entre au SAC du fouilleur, est nommé dans le résultat, et ne se revend pas au marché', function () {
    // La table de l'établi est réduite au parchemin POUR CE TEST, parce que le tirage
    // réel est pondéré et ne le donnerait pas à coup sûr. Le catalogue réel est pincé
    // à part (« la table de l'établi rend un parchemin »).
    Mobilier::where('nom', 'Établi d\'alchimiste')->firstOrFail()->update(['effet' => ['fouille' => [
        ['issue' => 'objet', 'poids' => 1, 'categories' => ['parchemin']],
    ]]]);

    [$alice, $groupe, $hero, $quete, $index] = pfMeubleAuContact('Établi d\'alchimiste');

    $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => "fouiller_mobilier_{$index}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'fouille_mobilier')
        ->assertJsonPath('resultat.issue', 'objet')
        ->assertJsonPath('resultat.objet.categorie', 'parchemin')
        ->json('resultat');

    expect($resultat['objet']['nom'])->toStartWith('Parchemin : ');

    $ligne = Inventaire::where('personnage_id', $hero->id)
        ->whereHas('objet', fn ($q) => $q->where('categorie', 'parchemin'))
        ->first();

    expect($ligne)->not->toBeNull('le parchemin trouvé n\'est pas entré au sac du fouilleur')
        ->and($ligne->emplacement)->toBe('consommable');

    // Le groupe rentre au hub : le parchemin est au sac, et il ne doit pas être à vendre.
    // ⚠ `refresh()` d'abord : l'instance a été créée au hub, et Eloquent ne réécrit
    // pas un attribut « inchangé » en mémoire alors que la quête l'a basculé en base.
    $groupe->refresh()->update(['phase' => 'hub']);

    $reponse = test()->postJson('/api/groupes/table-1/marche');
    expect($reponse->status())->toBe(201, 'ouverture du marché refusée : '.$reponse->getContent());

    expect(in_array('parchemin', collect($reponse->json('inventaire'))->pluck('categorie')->all(), true))->toBeFalse(
        'un parchemin est encore à l\'étal');

    $vendables = collect($reponse->json('paniers'))->flatMap(fn (array $p) => $p['inventaire'])->pluck('categorie')->all();
    expect(in_array('parchemin', $vendables, true))->toBeFalse('un parchemin figure dans la liste vendable du panier');

    test()->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [],
        'ventes' => [['inventaire_id' => $ligne->id]],
    ])->assertStatus(422)->assertJsonValidationErrors('ventes');

    expect(Inventaire::whereKey($ligne->id)->exists())->toBeTrue('le parchemin refusé à la vente a pourtant quitté le sac');
});

it('un parchemin ne se revend pas, même au sac d\'un héros qui le possédait avant la phase', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $possede = pfDonner($hero->id, 'Parchemin : Soin du Corps');

    $reponse = $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    expect(collect($reponse->json('paniers'))->flatMap(fn (array $p) => $p['inventaire'])->pluck('inventaire_id')->all())
        ->not->toContain($possede->id);

    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [],
        'ventes' => [['inventaire_id' => $possede->id]],
    ])->assertStatus(422)->assertJsonValidationErrors('ventes');

    expect(Inventaire::whereKey($possede->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 4. LA MIGRATION — jouée dans les deux sens sur la table d'AVANT (production)
// ---------------------------------------------------------------------------

it('la migration de l\'établi pose le parchemin SANS le dupliquer, et `down` ne retire que lui', function () {
    // Une migration qu'on n'a jamais jouée à l'envers n'est pas testée : on la charge telle quelle.
    $migration = require database_path('migrations/2026_10_16_160000_alchimiste_parchemin_fouille.php');

    // La base de production : la table de l'établi AVANT ce changement, posée à la main.
    $avant = [
        ['issue' => 'objet', 'poids' => 5, 'categories' => ['consommable']],
        ['issue' => 'piege', 'poids' => 2, 'piege' => 'Fiole de poison'],
        ['issue' => 'rien', 'poids' => 3],
    ];

    $id = (int) Mobilier::where('nom', 'Établi d\'alchimiste')->value('id');
    DB::table('mobiliers')->where('id', $id)->update(['effet' => json_encode(['fouille' => $avant])]);

    $migration->up();
    $migration->up(); // deux fois : pas de doublon

    $apres = Mobilier::find($id)->effet['fouille'];

    expect(count($apres))->toBe(4)
        ->and(collect($apres)->where('issue', 'objet')->pluck('categories')->filter(fn ($c) => $c === ['parchemin'])->count())->toBe(1)
        ->and(collect($apres)->firstWhere('issue', 'piege')['piege'])->toBe('Fiole de poison');

    // Le parchemin vient APRÈS les fioles et AVANT le piège : l'ordre de la fiche de l'établi.
    expect(array_column($apres, 'issue'))->toBe(['objet', 'objet', 'piege', 'rien']);

    $migration->down();

    expect(Mobilier::find($id)->effet['fouille'])->toEqual($avant);
});
