<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;
use App\Models\PersonnageVisiteHopekins;
use App\Models\Quete;
use App\Partie\FaveursHopekins;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * LE CHOIX DE HOPEKINS REST AU HUB (René, 2026-10-16 : « Choisissons la faveur au hub si les extensions qui
 * les utilisent sont utilisées »). Livret G1504 p. 21 et 23 : « At the start of each quest, a hero may select
 * one location to visit and receive its Boon of Heroism (if available). A hero may only benefit from one boon
 * at a time. » Chaque joueur choisit pour SON héros, à chaque tour de hub, ou renonce. Le tirage au hasard de
 * fin de quête n'existe plus. Tout passe par les vraies routes : /moi, PUT …/faveurs/lieu, POST …/pret,
 * GET …/etat ; l'état est en base, jamais en cache.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, MercenaireSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Une campagne Morcar GARDIEN (deux quêtes achevées) au hub, avec `$nbHeros` héros d'Alice. L'offre du compagnon
 * animal est close (ce n'est pas le sujet). La dernière quête achevée ouvre le tour de Hopekins Rest.
 *
 * @return array{alice: mixed, groupe: Groupe, heros: list<Personnage>}
 */
function hubChoixCampagne(int $nbHeros = 1, string $classe = 'barbare'): array
{
    $alice = connecterJoueur('alice');
    $groupe = jouerMorcar(creerGroupe());
    $groupe->update(['compagnon_offert' => 'refuse']);

    $heros = [];
    foreach (['Albrecht', 'Brynn', 'Cara'] as $rang => $nom) {
        if ($rang >= $nbHeros) {
            break;
        }

        $heros[] = creerHeros($alice, $groupe, $nom, $rang + 1, ['classe' => $classe]);
    }

    rendreGardien($groupe);

    return ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros];
}

/** Le héros tel que `/moi` le publie. */
function hubChoixMoi(Personnage $heros): array
{
    $moi = test()->getJson('/api/moi')->assertOk()->json('joueur.personnages');

    return collect($moi)->firstWhere('id', $heros->id);
}

/** La visite de Hopekins Rest que `/moi` publie à ce héros (`null` : rien à décider). */
function hubChoixVisite(Personnage $heros): ?array
{
    return hubChoixMoi($heros)['visite_hopekins'];
}

/** Pose le choix du héros par la vraie route (`null` = il renonce). */
function hubChoixPoser(Personnage $heros, ?string $lieu, string $identifiant = 'table-1')
{
    return test()->putJson("/api/groupes/{$identifiant}/faveurs/lieu", [
        'personnage_id' => $heros->id,
        'lieu' => $lieu,
    ]);
}

/** « Prêt ! » du héros, par la vraie route. */
function hubChoixPret(Personnage $heros, string $identifiant = 'table-1')
{
    return test()->postJson("/api/groupes/{$identifiant}/pret", [
        'personnage_id' => $heros->id,
        'pret' => true,
    ]);
}

// ---------------------------------------------------------------------------
// LE PRÉDICAT — « la campagne joue-t-elle Wizards of Morcar ? » et le tour de hub.
// ---------------------------------------------------------------------------

it('HORS Morcar : après une quête GAGNÉE, aucun choix n\'est publié, la route refuse, et AUCUNE faveur n\'est tirée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $groupe = $ctx['groupe'];
    $groupe->update(['boites_bestiaire' => []]); // jeu de base seul : aucune boîte Morcar
    rendreGardien($groupe, 1);
    acheverLaQuete($groupe);

    expect($groupe->fresh()->estGardien())->toBeTrue()
        ->and(FaveursHopekins::campagneAvecMorcar($groupe->fresh()))->toBeFalse()
        ->and(FaveursHopekins::tourDeHub($groupe->fresh()))->toBeNull()
        ->and(PersonnageFaveur::count())->toBe(0)
        ->and(hubChoixVisite($ctx['heros']))->toBeNull();

    hubChoixPoser($ctx['heros'], FaveursHopekins::DEADEYE)->assertStatus(422)->assertJsonValidationErrors(['phase']);
    expect(PersonnageFaveur::count())->toBe(0);
});

it('AVEC Morcar : la quête GAGNÉE ouvre le tour — neuf lieux publiés, aucune faveur tirée, Arcane Library fermée à un barbare', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $groupe = jouerMorcar($ctx['groupe']);
    rendreGardien($groupe, 1);
    acheverLaQuete($groupe);

    expect(PersonnageFaveur::count())->toBe(0);

    $visite = hubChoixVisite($ctx['heros']);
    $lieux = collect($visite['lieux']);

    // Le tour suit la quête ACHEVÉE la plus récente (ici la vraie, la fictive du Gardien ayant été créée après elle).
    $derniere = (int) Quete::where('groupe_id', $ctx['groupe']->id)->where('etat', 'terminee')->orderByDesc('id')->value('id');
    expect($visite['quete_id'])->toBe($derniere)
        ->and($lieux->pluck('cle')->all())->toBe(FaveursHopekins::TOUTES)
        ->and($lieux->where('disponible', true)->count())->toBe(8)
        ->and($lieux->firstWhere('cle', FaveursHopekins::ARCANE_LIBRARY))
        ->toMatchArray(['disponible' => false, 'motif' => 'Réservée aux lanceurs de sorts, à qui il reste un répertoire à prendre.']);
});

it('LE TOUR SE FERME au départ de la quête suivante : la phase n\'est plus « hub », rien à choisir', function () {
    ['heros' => [$heros], 'groupe' => $groupe] = hubChoixCampagne();
    expect(hubChoixVisite($heros))->not->toBeNull();

    $groupe->update(['phase' => 'quete']);

    expect(hubChoixVisite($heros))->toBeNull();
    hubChoixPoser($heros, FaveursHopekins::DEADEYE)->assertStatus(422)->assertJsonValidationErrors(['phase']);
});

// ---------------------------------------------------------------------------
// LE CHOIX — écrit en base, publié, revalidé.
// ---------------------------------------------------------------------------

it('CHOISIR : le lieu devient la faveur tenue, la décision est écrite EN BASE, et /moi ne la redemande plus', function () {
    ['heros' => [$heros]] = hubChoixCampagne();
    $avant = hubChoixMoi($heros);

    hubChoixPoser($heros, FaveursHopekins::DEWDROP_INN)
        ->assertOk()
        ->assertJsonPath('lieu', FaveursHopekins::DEWDROP_INN)
        ->assertJsonPath('visite_hopekins', null);

    expect(PersonnageFaveur::where('personnage_id', $heros->id)->pluck('cle')->all())->toBe([FaveursHopekins::DEWDROP_INN])
        ->and(PersonnageVisiteHopekins::where('personnage_id', $heros->id)->value('lieu'))->toBe(FaveursHopekins::DEWDROP_INN);

    // L'effet passe par le point de passage des plafonds : +2 PV de Body maximum.
    $apres = hubChoixMoi($heros);
    expect($apres['pv_body_max'])->toBe($avant['pv_body_max'] + 2)
        ->and($apres['visite_hopekins'])->toBeNull()
        ->and(collect($apres['faveurs'])->pluck('cle')->all())->toBe([FaveursHopekins::DEWDROP_INN]);
});

it('LISTE BLANCHE : une compétence tenue par un autre héros du groupe est refusée, avec le motif, et les autres lieux restent ouverts', function () {
    ['heros' => [$albrecht, $brynn]] = hubChoixCampagne(2);
    PersonnageFaveur::create(['personnage_id' => $albrecht->id, 'cle' => FaveursHopekins::DEADEYE]);

    expect(collect(hubChoixVisite($brynn)['lieux'])->firstWhere('cle', FaveursHopekins::DEADEYE))
        ->toMatchArray(['disponible' => false, 'motif' => 'Tenue par Albrecht.']);

    hubChoixPoser($brynn, FaveursHopekins::DEADEYE)
        ->assertStatus(422)
        ->assertJsonPath('errors.lieu.0', 'Tenue par Albrecht.');
    expect(PersonnageFaveur::where('personnage_id', $brynn->id)->count())->toBe(0);

    hubChoixPoser($brynn, FaveursHopekins::WITCHS_HUT)->assertOk();
});

it('LISTE BLANCHE : un lieu hors de la liste est refusé — la bibliothèque à un barbare, un nom inconnu, un héros d\'un autre joueur', function () {
    ['heros' => [$heros], 'groupe' => $groupe] = hubChoixCampagne();

    hubChoixPoser($heros, FaveursHopekins::ARCANE_LIBRARY)
        ->assertStatus(422)
        ->assertJsonPath('errors.lieu.0', 'Réservée aux lanceurs de sorts, à qui il reste un répertoire à prendre.');
    hubChoixPoser($heros, 'marche_noire')->assertStatus(422)->assertJsonValidationErrors(['lieu']);

    // Un héros qui n'est pas à CE joueur ne choisit pas pour lui. Bob est créé SANS être connecté : Alice reste
    // la joueuse qui agit.
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $etranger = creerHeros($bob, $groupe, 'Etranger', 9);
    hubChoixPoser($etranger, FaveursHopekins::DEADEYE)->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);

    expect(PersonnageFaveur::count())->toBe(0);
});

it('UN CHOIX PAR TOUR : une seconde décision au même hub est refusée ; le tour suivant rouvre le choix, la faveur tenue reste tenue', function () {
    ['heros' => [$heros], 'groupe' => $groupe] = hubChoixCampagne();

    hubChoixPoser($heros, FaveursHopekins::DEADEYE)->assertOk();
    hubChoixPoser($heros, FaveursHopekins::WITCHS_HUT)
        ->assertStatus(422)
        ->assertJsonPath('errors.lieu.0', 'Ce héros a déjà choisi son lieu pour cette quête.');

    $tour = ouvrirTourDeHub($groupe);
    $visite = hubChoixVisite($heros);

    expect($visite['quete_id'])->toBe($tour->id)
        // Deadeye est celle qu'il tient : il ne la reçoit pas deux fois.
        ->and(collect($visite['lieux'])->firstWhere('cle', FaveursHopekins::DEADEYE))
        ->toMatchArray(['disponible' => false, 'motif' => 'Vous la tenez déjà.']);

    hubChoixPoser($heros, FaveursHopekins::DEADEYE)->assertStatus(422);
    // La potion est instantanée : elle ne relâche pas Deadeye.
    hubChoixPoser($heros, FaveursHopekins::WITCHS_HUT)->assertOk();
    expect(PersonnageFaveur::where('personnage_id', $heros->id)->pluck('cle')->all())->toBe([FaveursHopekins::DEADEYE]);
});

it('REMPLACEMENT : un lieu choisi relâche la faveur tenue (livret : « one boon at a time ») — le +2 PV tombe, et l\'annonce le dit', function () {
    ['heros' => [$heros], 'groupe' => $groupe] = hubChoixCampagne();
    $base = hubChoixMoi($heros)['pv_body_max'];

    hubChoixPoser($heros, FaveursHopekins::DEWDROP_INN)->assertOk();
    expect(hubChoixMoi($heros)['pv_body_max'])->toBe($base + 2);

    ouvrirTourDeHub($groupe);
    $reponse = hubChoixPoser($heros, FaveursHopekins::MERCENARY_GUILD)->assertOk()->json();

    expect(PersonnageFaveur::where('personnage_id', $heros->id)->pluck('cle')->all())->toBe([FaveursHopekins::MERCENARY_GUILD])
        ->and(hubChoixMoi($heros)['pv_body_max'])->toBe($base)
        ->and($reponse['detail'])->toStartWith("{$heros->nom} perd Dewdrop Inn.");
});

it('REFUS : renoncer est un choix (« may ») — aucune faveur, et le héros peut enfin se déclarer prêt', function () {
    ['heros' => [$heros]] = hubChoixCampagne();

    hubChoixPret($heros)->assertStatus(422);
    expect((string) hubChoixPret($heros)->json('errors.pret.0'))->toContain('peut visiter un lieu de Hopekins Rest');

    hubChoixPoser($heros, null)->assertOk()->assertJsonPath('lieu', null);

    expect(PersonnageFaveur::count())->toBe(0)
        ->and(PersonnageVisiteHopekins::where('personnage_id', $heros->id)->value('lieu'))->toBeNull()
        ->and(hubChoixVisite($heros))->toBeNull();

    hubChoixPret($heros)->assertOk();
});

it('PRÊT : Weapon Expert garde son sous-choix — le lieu posé, « Prêt ! » reste refusé jusqu\'au type d\'arme', function () {
    ['heros' => [$heros]] = hubChoixCampagne();

    hubChoixPoser($heros, FaveursHopekins::WEAPON_EXPERT)->assertOk();
    expect(collect(hubChoixMoi($heros)['faveurs'])->firstWhere('cle', FaveursHopekins::WEAPON_EXPERT)['choix'])->not->toBeNull();

    hubChoixPret($heros)->assertStatus(422);
    expect((string) hubChoixPret($heros)->json('errors.pret.0'))->toContain('type d\'arme');

    $epee = Objet::where('nom', 'Épée longue')->value('id');
    test()->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
        'personnage_id' => $heros->id, 'objet_id' => $epee,
    ])->assertOk();

    hubChoixPret($heros)->assertOk();
});

it('DURABLE : le tour et le choix survivent à une cache vidée — rien de ce tour n\'est gardé en cache', function () {
    ['heros' => [$heros]] = hubChoixCampagne();

    Cache::flush();
    expect(hubChoixVisite($heros))->not->toBeNull();

    hubChoixPoser($heros, FaveursHopekins::PEACEKEEPER)->assertOk();

    Cache::flush();
    expect(hubChoixVisite($heros))->toBeNull()
        ->and(collect(hubChoixMoi($heros)['faveurs'])->pluck('cle')->all())->toBe([FaveursHopekins::PEACEKEEPER]);
});

// ---------------------------------------------------------------------------
// LE REPLI — une campagne EN COURS (faveurs tirées avant ce changement).
// ---------------------------------------------------------------------------

it('REPLI : un tour déjà tranché par l\'ancien tirage n\'est pas rejoué ; le hub suivant ouvre le choix', function () {
    ['heros' => [$heros], 'groupe' => $groupe] = hubChoixCampagne();
    $tranche = (int) Quete::where('groupe_id', $groupe->id)->where('etat', 'terminee')->orderByDesc('id')->value('id');

    // La migration pose ce marqueur sur la quête achevée la plus récente de chaque groupe.
    DB::table('groupes')->where('id', $groupe->id)->update(['hopekins_quete_tranchee_id' => $tranche]);

    expect(hubChoixVisite($heros))->toBeNull();

    ouvrirTourDeHub($groupe);

    expect(hubChoixVisite($heros)['quete_id'])->not->toBe($tranche);
});

// ---------------------------------------------------------------------------
// L'ANNONCE — la table et la manette disent ce qui a été choisi, et qui doit encore choisir.
// ---------------------------------------------------------------------------

it('ANNONCE : groupe.hopekins dit à la table ce que chaque héros a choisi, et qui doit encore choisir', function () {
    ['heros' => [$albrecht, $brynn], 'groupe' => $groupe] = hubChoixCampagne(2);

    hubChoixPoser($albrecht, FaveursHopekins::DEWDROP_INN)->assertOk();

    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.hopekins');

    expect($hub['quete_id'])->toBe((int) Quete::where('groupe_id', $groupe->id)->where('etat', 'terminee')->orderByDesc('id')->value('id'))
        ->and($hub['visites'])->toHaveCount(1)
        ->and($hub['visites'][0])->toMatchArray([
            'personnage' => 'Albrecht', 'lieu' => FaveursHopekins::DEWDROP_INN, 'libelle' => 'Dewdrop Inn',
        ])
        ->and($hub['visites'][0]['effet'])->toBe(FaveursHopekins::EFFETS[FaveursHopekins::DEWDROP_INN])
        ->and($hub['visites'][0]['detail'])->toContain('PV de Body maximum')
        ->and($hub['en_attente'])->toBe([['personnage_id' => $brynn->id, 'personnage' => 'Brynn']]);

    hubChoixPoser($brynn, null)->assertOk();

    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.hopekins');

    expect($hub['visites'])->toHaveCount(2)
        ->and($hub['visites'][1])->toMatchArray(['personnage' => 'Brynn', 'lieu' => null, 'libelle' => null])
        ->and($hub['en_attente'])->toBe([]);
});

// ---------------------------------------------------------------------------
// AUCUN TIRAGE RÉSIDUEL.
// ---------------------------------------------------------------------------

it('AUCUN TIRAGE : la méthode de tirage et son éligibilité ont disparu du service', function () {
    expect(method_exists(FaveursHopekins::class, 'attribuerFaveurDeFinDeQuete'))->toBeFalse()
        ->and(method_exists(FaveursHopekins::class, 'candidatsDeTirage'))->toBeFalse()
        ->and(method_exists(FaveursHopekins::class, 'choisirLieu'))->toBeTrue();
});
