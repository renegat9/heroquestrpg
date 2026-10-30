<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Epreuve;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * PARCHEMINS MODIFIÉS de Prophecy of Telor (plan des restes, lot B, 2026-10-29).
 *
 * G0052 PDF 15 et 17 : un parchemin de Soin du Corps ou d'Éclair « modified to be cast three
 * times before the scroll crumbles to dust », un d'Eau de Guérison « twice ». Ils passent par
 * le modèle des charges (`MoteurCharges`, l'arc à quatre flèches), pas par un second compteur :
 * une charge par lecture — réussie ou gaspillée (S1) —, l'exemplaire s'effrite (annoncé) à la
 * dernière. Décision B1 de René : ils ne sortent du butin que sous le thème Telor, rareté rare.
 *
 * Dés figés (LanceurDeterministe) : 1-3 = crâne, 4-5 = bouclier blanc, 6 = bouclier noir.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Le menu MOTEUR re-proposé au joueur pour ce héros. */
function menuParcheminModifie(Groupe $groupe, JoueurAuthentifiable $joueur, Personnage $hero): Collection
{
    GenererMenu::dispatchSync($groupe->id, (int) $joueur->id, (int) $hero->id);

    return collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $joueur->id))['menu']['options']);
}

/** Quête démarrée avec une magicienne BLESSÉE (un soin n'est offert que s'il y a des PV à rendre). */
function queteParcheminModifie(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $mage = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $mage->update(['pv_body' => max(1, (int) $mage->pv_body_max - 3)]);

    return [$alice, $groupe, $mage, $quete];
}

function donnerParcheminModifie(Personnage $hero, string $nom, array $attributs = []): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => Objet::where('nom', $nom)->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
        ...$attributs,
    ]);
}

function lireParcheminModifie(Inventaire $ligne, Personnage $cible): array
{
    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => "parchemin:{$ligne->id}", 'cible_id' => $cible->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)->json('resultat');
}

it('sème les trois parchemins modifiés du livret, leurs usages, leur boîte et leur rareté', function () {
    $attendus = [
        'Parchemin modifié : Soin du Corps' => 3,
        'Parchemin modifié : Éclair' => 3,
        'Parchemin modifié : Eau de Guérison' => 2,
    ];

    foreach ($attendus as $nom => $usages) {
        $objet = Objet::where('nom', $nom)->first();

        expect($objet)->not->toBeNull("{$nom} manque au catalogue")
            ->and($objet->categorie)->toBe('parchemin')
            ->and($objet->rarete)->toBe('rare')
            ->and($objet->boite)->toBe('prophecy_telor')
            ->and((int) $objet->effet['charges'])->toBe($usages)
            ->and($objet->effet['sort_nom'])->toBe(str_replace('Parchemin modifié : ', '', $nom));
    }
});

it('se lit, garde le parchemin, dépense UNE charge et le dit — au menu comme au fil', function () {
    [$alice, $groupe, $mage, $quete] = queteParcheminModifie();
    $ligne = donnerParcheminModifie($mage, 'Parchemin modifié : Soin du Corps');

    $entree = collect(collect(menuParcheminModifie($groupe, $alice, $mage))
        ->firstWhere('id', 'lire_parchemin')['parametres']['parchemins'] ?? [])
        ->firstWhere('cle', "parchemin:{$ligne->id}");

    // Le menu dit ce qu'il reste à CET exemplaire, décidé par le serveur.
    expect($entree)->not->toBeNull()
        ->and($entree['nom'])->toBe('Soin du Corps (3 usages restants)');

    desFiges(array_fill(0, 60, 4));
    $resultat = lireParcheminModifie($ligne, $mage);

    expect($resultat['type'])->toBe('parchemin')
        ->and($resultat['charges_depensees'][0]['restantes'])->toBe(2)
        ->and($resultat['charges_depensees'][0]['max'])->toBe(3)
        ->and($resultat['charges_depensees'][0]['detruit'])->toBeFalse()
        ->and(Inventaire::find($ligne->id))->not->toBeNull()
        ->and((int) Inventaire::find($ligne->id)->charges)->toBe(2);
});

it('s\'effrite à la DERNIÈRE lecture : la ligne quitte le sac, annoncée APRÈS la lecture', function () {
    [$alice, $groupe, $mage] = queteParcheminModifie();
    $ligne = donnerParcheminModifie($mage, 'Parchemin modifié : Eau de Guérison', ['charges' => 1]);

    menuParcheminModifie($groupe, $alice, $mage);
    desFiges(array_fill(0, 60, 4));
    $resultat = lireParcheminModifie($ligne, $mage);

    expect($resultat['charges_depensees'][0]['detruit'])->toBeTrue()
        ->and(Inventaire::find($ligne->id))->toBeNull();

    // L'ordre du journal : la lecture, PUIS l'objet qui se brise — jamais l'inverse.
    $types = Evenement::query()->orderBy('sequence')->get()
        ->map(fn ($e) => $e->payload['type'] ?? null)
        ->filter(fn ($t) => in_array($t, ['parchemin', 'objet_detruit'], true))
        ->values()->all();

    expect($types)->toContain('objet_detruit')
        ->and(array_search('objet_detruit', $types, true))->toBeGreaterThan(array_search('parchemin', $types, true));
});

it('dans une PILE, seul l\'exemplaire entamé s\'effrite : le suivant repart neuf', function () {
    [$alice, $groupe, $mage] = queteParcheminModifie();
    $ligne = donnerParcheminModifie($mage, 'Parchemin modifié : Eau de Guérison', ['charges' => 1, 'quantite' => 2]);

    menuParcheminModifie($groupe, $alice, $mage);
    desFiges(array_fill(0, 60, 4));
    lireParcheminModifie($ligne, $mage);

    $reste = Inventaire::find($ligne->id);

    expect($reste)->not->toBeNull()
        ->and((int) $reste->quantite)->toBe(1)
        ->and($reste->charges)->toBeNull(); // jamais entamé = 2 usages pleins
});

it('une lecture RATÉE d\'un non-lanceur dépense quand même une charge (S1)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barbare = creerHeros($alice, $groupe, 'Albrecht', 1); // non-lanceur : jet de Mind

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $barbare->update(['pv_body' => max(1, (int) $barbare->pv_body_max - 3)]);
    $ligne = donnerParcheminModifie($barbare, 'Parchemin modifié : Soin du Corps');

    menuParcheminModifie($groupe, $alice, $barbare);
    desFiges(array_fill(0, 60, 4)); // aucun crâne : jet de Mind raté

    $resultat = lireParcheminModifie($ligne, $barbare);

    expect($resultat['gaspille'])->toBeTrue()
        ->and((int) Inventaire::find($ligne->id)->charges)->toBe(2);
});

it('la relance de Vision du futur passe par le même point de passage (un parchemin ordinaire reste détruit)', function () {
    [, , $mage] = queteParcheminModifie();
    $ordinaire = donnerParcheminModifie($mage, 'Parchemin : Soin du Corps');
    $modifie = donnerParcheminModifie($mage, 'Parchemin modifié : Soin du Corps');

    $sorts = app(MoteurSorts::class);
    $sorts->consommerParchemin($ordinaire->load('objet'));
    $sorts->consommerParchemin($modifie->load('objet'));

    expect(Inventaire::find($ordinaire->id))->toBeNull()
        ->and((int) Inventaire::find($modifie->id)->charges)->toBe(2);
});

it('ne sort du Grimoire que sous le thème Telor (B1) : boîté hors thème = jamais tiré', function () {
    $this->seed(EpreuveSeeder::class);

    // Le vivier ne garde QUE les trois parchemins modifiés : tout autre parchemin passe `unique`
    // (exclu du butin). Le tirage devient donc binaire : modifié ou rien.
    Objet::query()->where('categorie', 'parchemin')->where('nom', 'not like', 'Parchemin modifié%')
        ->update(['rarete' => 'unique']);

    $tirer = function (?array $boites): array {
        $alice = connecterJoueur('alice');
        $groupe = creerGroupe();
        $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

        if ($boites !== null) {
            $groupe->update(['boites_bestiaire' => $boites]);
        }

        test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
        $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
        $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

        $grille = $quete->carte->grille;
        $grille['epreuves'] = [[
            'x' => (int) $etat->position_x,
            'y' => (int) $etat->position_y,
            'epreuve_id' => Epreuve::where('nom', 'Grimoire à demi calciné')->value('id'),
            'salle' => 0,
            'tentee_par' => [],
        ]];
        $quete->carte->update(['grille' => $grille]);

        GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
        desFiges([1, 1, 1, 1]); // réussite du jet de Mind

        return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
            ->assertStatus(202)->json('resultat');
    };

    $horsTheme = $tirer(['witch_lord']);

    expect($horsTheme['objet_indisponible'] ?? false)->toBeTrue('un parchemin boîté Telor sort hors de son thème');
});

it('sort du Grimoire sous le thème Telor', function () {
    $this->seed(EpreuveSeeder::class);

    Objet::query()->where('categorie', 'parchemin')->where('nom', 'not like', 'Parchemin modifié%')
        ->update(['rarete' => 'unique']);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['boites_bestiaire' => ['prophecy_telor']]);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    $grille = $quete->carte->grille;
    $grille['epreuves'] = [[
        'x' => (int) $etat->position_x,
        'y' => (int) $etat->position_y,
        'epreuve_id' => Epreuve::where('nom', 'Grimoire à demi calciné')->value('id'),
        'salle' => 0,
        'tentee_par' => [],
    ]];
    $quete->carte->update(['grille' => $grille]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    desFiges([1, 1, 1, 1]);

    $resultat = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['objet']['nom'] ?? '')->toStartWith('Parchemin modifié : ');
});
