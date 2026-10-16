<?php

declare(strict_types=1);

use App\Models\GroupeMercenaire;
use App\Models\Groupe;
use App\Models\Mercenaire;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;
use App\Partie\EtatGroupe;
use App\Partie\FaveursHopekins;
use App\Partie\MoteurSorts;
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
use Illuminate\Support\Facades\Http;

/*
 * DURÉE des faveurs de Hopekins Rest — livret G1504 p. 22-23 (décision de René, 2026-10-16 :
 * « suivre le livret pour la DURÉE ») :
 *   « At the start of each quest, a hero may select one location to visit and receive its Boon of
 *     Heroism (if available). A hero may only benefit from one boon at a time. »
 *   « This Mercenary returns to the guild if you ever choose another boon. »
 * Une faveur est TENUE jusqu'à ce que son porteur en reçoive une AUTRE. Recevoir une faveur relâche
 * celles qu'il tient : leur effet cesse dans la même opération (le +2 PV tombe, le mercenaire part,
 * le répertoire sort). Le choix se fait AU HUB, par chaque héros (René, 2026-10-16) : ces tests le posent
 * par la vraie route `PUT …/faveurs/lieu`.
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

// ---------------------------------------------------------------------------
// Aides — propres à ce fichier (les fonctions de test sont globales : préfixe `duree`).
// ---------------------------------------------------------------------------

/**
 * Fait CHOISIR le lieu `$cle` au héros de rang `$rangHeros` (parmi les actifs, par id) par la VRAIE route, au hub.
 * Un seul choix par tour : si ce héros a déjà décidé à ce tour, une nouvelle quête gagnée rouvre le hub.
 *
 * @return array<string, mixed>
 */
function dureeChoisir(Groupe $groupe, string $cle, int $rangHeros = 0): array
{
    $heros = $groupe->personnages()->wherePivot('actif', true)->orderBy('personnages.id')->get()->values()[$rangHeros];
    $tour = FaveursHopekins::tourDeHub($groupe->fresh());

    if ($tour !== null && FaveursHopekins::visiteDecidee($heros, $tour)) {
        ouvrirTourDeHub($groupe);
    }

    return test()->putJson("/api/groupes/{$groupe->identifiant}/faveurs/lieu", [
        'personnage_id' => $heros->id,
        'lieu' => $cle,
    ])->assertOk()->json();
}

/** Les clés que ce héros TIENT en base, dans l'ordre de réception. */
function dureeTenues(Personnage $heros): array
{
    return PersonnageFaveur::where('personnage_id', $heros->id)->orderBy('id')->pluck('cle')->all();
}

/** Un groupe GARDIEN, sans offre de compagnon (hors sujet ici), et son premier héros. */
function dureeGroupe(string $classe = 'barbare'): array
{
    $alice = connecterJoueur('alice');
    $groupe = jouerMorcar(creerGroupe());
    $groupe->update(['compagnon_offert' => 'refuse']);
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => $classe]);
    rendreGardien($groupe);

    return compact('alice', 'groupe', 'heros');
}

// ---------------------------------------------------------------------------
// La durée est écrite dans la fiche, en clair.
// ---------------------------------------------------------------------------

it('chaque faveur TENUE dit jusqu\'à quand elle tient ; la potion, instantanée, ne le dit pas', function () {
    foreach (FaveursHopekins::TOUTES as $cle) {
        if (in_array($cle, FaveursHopekins::INSTANTANEES, true)) {
            expect(FaveursHopekins::effet($cle))->not->toContain('Tenue jusqu');

            continue;
        }

        expect(FaveursHopekins::effet($cle))->toContain(FaveursHopekins::TENUE);
    }
});

// ---------------------------------------------------------------------------
// Une faveur tenue résiste aux choix des AUTRES héros.
// ---------------------------------------------------------------------------

it('une compétence tenue reste à son porteur : un autre héros du groupe ne peut pas la recevoir', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $albrecht] = dureeGroupe();
    $brunhilde = creerHeros($alice, $groupe, 'Brunhilde', 2);

    // Albrecht reçoit Deadeye (premier candidat), puis Brunhilde reçoit Weapon Expert.
    dureeChoisir($groupe, FaveursHopekins::DEADEYE, 0);
    dureeChoisir($groupe, FaveursHopekins::WEAPON_EXPERT, 1);

    expect(dureeTenues($albrecht->fresh()))->toBe([FaveursHopekins::DEADEYE])
        ->and(dureeTenues($brunhilde->fresh()))->toBe([FaveursHopekins::WEAPON_EXPERT]);

    // Deadeye est tenue par Albrecht : elle n'est choisissable par personne d'autre (« 1 available »).
    expect(collect(FaveursHopekins::lieuxPour($groupe->fresh(), $brunhilde->fresh()))->firstWhere('cle', FaveursHopekins::DEADEYE))
        ->toMatchArray(['disponible' => false, 'motif' => 'Tenue par Albrecht.']);
});

// ---------------------------------------------------------------------------
// Recevoir une autre faveur relâche celle qu'on tient, et son effet cesse.
// ---------------------------------------------------------------------------

it('recevoir une autre faveur relâche le +2 PV de Dewdrop Inn : le maximum redescend, l\'annonce le dit', function () {
    ['groupe' => $groupe, 'heros' => $heros] = dureeGroupe();

    $gagne = dureeChoisir($groupe, FaveursHopekins::DEWDROP_INN);
    expect($gagne['detail'])->toBe('Albrecht passe à 10 PV de Body maximum.')
        ->and($heros->fresh()->pv_body_max)->toBe(10);

    // Deadeye est la seule compétence encore libre pour lui : elle remplace Dewdrop Inn.
    $remplacee = dureeChoisir($groupe, FaveursHopekins::DEADEYE);

    expect(dureeTenues($heros->fresh()))->toBe([FaveursHopekins::DEADEYE])
        ->and($heros->fresh()->pv_body_max)->toBe(8)
        ->and($heros->fresh()->pv_body)->toBeLessThanOrEqual(8)
        ->and($remplacee['detail'])->toBe('Albrecht perd Dewdrop Inn. Albrecht revient à 8 PV de Body maximum.');

    // La fiche (manette, /moi) ne montre plus que ce qui est tenu.
    $fiche = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->firstWhere('id', $heros->id);
    expect(collect($fiche['faveurs'])->pluck('cle')->all())->toBe([FaveursHopekins::DEADEYE]);

    // Et le hub annonce le choix de ce tour, avec ce qu'il a défait.
    $hub = app(EtatGroupe::class)->payload($groupe->fresh());
    expect($hub['groupe']['hopekins']['visites'][0]['detail'])->toContain('perd Dewdrop Inn');
});

it('le mercenaire offert RETOURNE à la guilde quand la faveur qui le tient est relâchée', function () {
    ['groupe' => $groupe, 'heros' => $heros] = dureeGroupe();

    dureeChoisir($groupe, FaveursHopekins::MERCENARY_GUILD);

    // Le recrutement gratuit tel que `MercenaireController` le lie (même appel, même lien).
    $recrue = GroupeMercenaire::create([
        'groupe_id' => $groupe->id,
        'mercenaire_id' => Mercenaire::where('nom', 'Fauchard')->value('id'),
        'recruteur_personnage_id' => $heros->id,
        'pv_body' => 2,
        'etat' => 'actif',
    ]);
    FaveursHopekins::lierMercenaireOffert($heros, $recrue);

    expect(FaveursHopekins::mercenaireGratuitDisponible($heros->fresh(), $groupe->id))->toBeFalse();

    $relachee = dureeChoisir($groupe, FaveursHopekins::DEADEYE);

    expect(GroupeMercenaire::whereKey($recrue->id)->exists())->toBeFalse()
        ->and($relachee['detail'])->toContain('Son mercenaire offert retourne à la guilde.');

    // Le droit de recruter gratuitement n'existe plus : Mercenary Guild n'est plus tenue.
    expect(dureeTenues($heros->fresh()))->toBe([FaveursHopekins::DEADEYE]);
});

it('la bibliothèque arcanique : son répertoire supplémentaire part avec elle', function () {
    ['alice' => $alice, 'groupe' => $groupe] = dureeGroupe('magicien');
    $magicien = Personnage::where('nom', 'Albrecht')->firstOrFail();

    foreach (['feu', 'eau', 'terre'] as $element) {
        app(MoteurSorts::class)->attacherElement($magicien, $element);
    }

    dureeChoisir($groupe, FaveursHopekins::ARCANE_LIBRARY);

    // Le choix du joueur au hub (`PUT …/faveurs/arcane-library`) : répertoire pris, sorts attachés.
    PersonnageFaveur::where('personnage_id', $magicien->id)->update(['parametre' => 'protection']);
    app(MoteurSorts::class)->attacherElement($magicien, 'protection');
    expect($magicien->fresh()->sorts()->where('element', 'protection')->count())->toBeGreaterThan(0);

    $relachee = dureeChoisir($groupe, FaveursHopekins::DEADEYE);

    expect($magicien->fresh()->sorts()->where('element', 'protection')->count())->toBe(0)
        ->and($magicien->fresh()->sorts()->whereIn('element', ['feu', 'eau', 'terre'])->count())->toBeGreaterThan(0)
        ->and($relachee['detail'])->toContain('Il perd son répertoire de sorts supplémentaire.');
});

it('l\'effet tient à la faveur tenue : Deadeye, une fois relâchée, ne lève plus la ligne de vue de ce héros', function () {
    ['groupe' => $groupe, 'heros' => $heros] = dureeGroupe();

    dureeChoisir($groupe, FaveursHopekins::DEADEYE);
    expect(app(FaveursHopekins::class)->figuresBloquentPour($heros->fresh()))->toBeFalse();

    dureeChoisir($groupe, FaveursHopekins::PEACEKEEPER);
    expect(app(FaveursHopekins::class)->figuresBloquentPour($heros->fresh()))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Une faveur HÉRITÉE (acquise avant ce changement, plusieurs par héros) : repli écrit.
// ---------------------------------------------------------------------------

it('une faveur héritée reste en vigueur, et la PROCHAINE réception les relâche toutes : un héros ne tient qu\'une à la fois', function () {
    ['groupe' => $groupe, 'heros' => $heros] = dureeGroupe();

    // Trois faveurs acquises sous l'ancien régime (cumul permis).
    foreach ([FaveursHopekins::DEADEYE, FaveursHopekins::PEACEKEEPER, FaveursHopekins::HOLD_THE_LINE] as $cle) {
        PersonnageFaveur::create(['personnage_id' => $heros->id, 'cle' => $cle]);
    }

    expect(dureeTenues($heros->fresh()))->toHaveCount(3)
        ->and(app(FaveursHopekins::class)->figuresBloquentPour($heros->fresh()))->toBeFalse();

    $recue = dureeChoisir($groupe, FaveursHopekins::DEWDROP_INN);

    expect(dureeTenues($heros->fresh()))->toBe([FaveursHopekins::DEWDROP_INN])
        ->and($recue['detail'])->toStartWith('Albrecht perd Deadeye, Peacekeeper et Hold the Line.');
});

// ---------------------------------------------------------------------------
// La potion (Witch's Hut) est instantanée : elle ne remplace rien.
// ---------------------------------------------------------------------------

it('la potion du Witch\'s Hut ne relâche PAS la faveur tenue : elle est remise dans le sac, c\'est tout', function () {
    ['groupe' => $groupe, 'heros' => $heros] = dureeGroupe();

    dureeChoisir($groupe, FaveursHopekins::DEADEYE);
    $potion = dureeChoisir($groupe, FaveursHopekins::WITCHS_HUT);

    expect($potion['detail'])->toBe('Albrecht reçoit une Potion de guérison.')
        ->and(dureeTenues($heros->fresh()))->toBe([FaveursHopekins::DEADEYE])
        ->and(PersonnageFaveur::where('cle', FaveursHopekins::WITCHS_HUT)->count())->toBe(0);
});
