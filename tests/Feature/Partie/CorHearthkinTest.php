<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\GroupeMercenaire;
use App\Models\Inventaire;
use App\Models\Mercenaire;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * LE COR DES HEARTHKIN (First Light, FL-Q p. 6, lot C) — artefact qui rejoint
 * le pool de coffres et réutilise le système d'alliés existant.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MercenaireSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

it('registre : la carte, l\'objet et le bloc de stats existent — jamais recrutable au hub', function () {
    $carte = collect((array) config('cartes.artefacts.cartes'))->firstWhere('carte', 'The Hearthkin Horn');
    expect($carte)->not->toBeNull()
        ->and($carte['objet'])->toBe('Cor des Hearthkin')
        ->and($carte['paquet'] ?? null)->toBe('First Light');

    $objet = Objet::where('nom', 'Cor des Hearthkin')->first();
    expect($objet)->not->toBeNull()
        ->and($objet->rarete)->toBe('unique')
        ->and((bool) ($objet->effet['invoque_squelettes_hearthkin'] ?? false))->toBeTrue();

    $squelette = Mercenaire::where('nom', 'Squelette Hearthkin')->first();
    expect($squelette)->not->toBeNull()
        ->and((bool) $squelette->octroi_seul)->toBeTrue()
        ->and((int) $squelette->deplacement)->toBe(8)
        ->and((int) $squelette->attaque)->toBe(2)
        ->and((int) $squelette->defense)->toBe(2)
        ->and((int) $squelette->pv_body)->toBe(1)
        ->and((int) $squelette->pv_mind)->toBe(0);

    connecterJoueur('alice');
    $catalogue = $this->getJson('/api/mercenaires')->assertOk()->json('mercenaires');
    expect(collect($catalogue)->pluck('nom')->all())->not->toContain('Squelette Hearthkin');
});

it('refuse le recrutement direct du Squelette, même en ciblant son id', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $squelette = Mercenaire::where('nom', 'Squelette Hearthkin')->firstOrFail();

    $this->postJson("/api/groupes/table-1/mercenaires", ['mercenaire_id' => $squelette->id])
        ->assertStatus(422);

    expect(GroupeMercenaire::where('groupe_id', $groupe->id)->count())->toBe(0);
});

it('souffler dans le cor : un Squelette Hearthkin par héros DEBOUT, chacun dans sa propre zone — et le cor se brise', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    expect($quete)->not->toBeNull();

    $ligne = Inventaire::create([
        'personnage_id' => $albrecht->id,
        'objet_id' => Objet::where('nom', 'Cor des Hearthkin')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $albrecht->id);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}"],
    ])->assertAccepted()
        ->assertJsonPath('resultat.perdu', true);

    $squelettes = GroupeMercenaire::where('groupe_id', $groupe->id)->with('mercenaire')->get();

    // Les DEUX héros debout de la quête reçoivent le leur — pas seulement
    // celui qui a soufflé dans le cor.
    expect($squelettes)->toHaveCount(2);
    expect($squelettes->pluck('mercenaire.nom')->unique()->all())->toBe(['Squelette Hearthkin']);
    expect($squelettes->pluck('recruteur_personnage_id')->sort()->values()->all())
        ->toBe(collect([$albrecht->id, $brunhilde->id])->sort()->values()->all());

    // Chacun sur SA propre case, jamais la même — `caseLibrePourSquelette()`
    // tient `$prises` à travers toute la boucle.
    $cases = $squelettes->map(fn ($s) => "{$s->position_x},{$s->position_y}")->unique();
    expect($cases)->toHaveCount(2);

    // « the horn crumbles to dust » : disparu du sac, donc redevenu trouvable.
    expect(Inventaire::find($ligne->id))->toBeNull();
});

it('un héros TOMBÉ ne reçoit pas de Squelette (NOTRE arbitrage)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Brunhilde est à terre : elle ne pose pas le sien.
    $quete->etatsPersonnages()->where('personnage_id', $brunhilde->id)->update(['tombe' => true]);

    $ligne = Inventaire::create([
        'personnage_id' => $albrecht->id,
        'objet_id' => Objet::where('nom', 'Cor des Hearthkin')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $albrecht->id);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}"],
    ])->assertAccepted();

    $squelettes = GroupeMercenaire::where('groupe_id', $groupe->id)->get();
    expect($squelettes)->toHaveCount(1)
        ->and((int) $squelettes->first()->recruteur_personnage_id)->toBe($albrecht->id);
});

/**
 * Une quête à deux héros où Albrecht souffle dans le cor, par le menu réel.
 *
 * @return array{groupe: \App\Models\Groupe, quete: Quete, albrecht: \App\Models\Personnage, ligne: Inventaire}
 */
function sonnerLeCorHearthkin(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $ligne = Inventaire::create([
        'personnage_id' => $albrecht->id,
        'objet_id' => Objet::where('nom', 'Cor des Hearthkin')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $albrecht->id);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}"],
    ])->assertAccepted();

    return compact('groupe', 'quete', 'albrecht', 'ligne');
}

/** Les squelettes Hearthkin présents dans ce groupe, tous héros confondus. */
function squelettesHearthkinDu(\App\Models\Groupe $groupe): \Illuminate\Support\Collection
{
    return GroupeMercenaire::where('groupe_id', $groupe->id)
        ->whereHas('mercenaire', fn ($q) => $q->where('nom', 'Squelette Hearthkin'))
        ->get();
}

it('le squelette est un allié APPELÉ : posé avec le cor pour marqueur, il quitte la quête gagnée sans entretien', function () {
    ['groupe' => $groupe, 'quete' => $quete, 'albrecht' => $albrecht, 'ligne' => $ligne] = sonnerLeCorHearthkin();

    // Témoin : un mercenaire RECRUTÉ (non appelé) doit payer son entretien et rester.
    $recrue = GroupeMercenaire::create([
        'groupe_id' => $groupe->id,
        'mercenaire_id' => Mercenaire::where('nom', 'Éclaireur')->value('id'),
        'recruteur_personnage_id' => $albrecht->id,
        'pv_body' => 2, 'position_x' => 1, 'position_y' => 1, 'etat' => 'actif',
    ]);
    $groupe->update(['or' => 100]);

    // Le squelette porte le marqueur du cor, comme le Raptor porte celui du brassard.
    $squelettes = squelettesHearthkinDu($groupe);
    expect($squelettes)->toHaveCount(2)
        ->and($squelettes->pluck('invoque_par_objet_id')->unique()->all())->toBe([(int) $ligne->objet_id]);

    $resultat = app(ResolveurTour::class)->terminerQuete($groupe->fresh(), $quete->fresh());

    expect(squelettesHearthkinDu($groupe))->toHaveCount(0)
        ->and(GroupeMercenaire::find($recrue->id))->not->toBeNull()
        // L'entretien ne paie QUE le recruté : un squelette ne doit jamais coûter 10 po.
        ->and(collect($resultat['mercenaires_entretien']['payes'] ?? [])->pluck('nom')->all())->toBe(['Éclaireur'])
        ->and($resultat['mercenaires_entretien']['cout_total'] ?? null)->toBe(10);
});

it('une quête ÉCHOUÉE emporte les squelettes comme les autres alliés appelés', function () {
    ['groupe' => $groupe, 'quete' => $quete] = sonnerLeCorHearthkin();

    expect(squelettesHearthkinDu($groupe))->toHaveCount(2);

    $echec = new ReflectionMethod(ResolveurTour::class, 'echouerQuete');
    $echec->setAccessible(true);
    $echec->invoke(app(ResolveurTour::class), $groupe->fresh(), $quete->fresh());

    expect(squelettesHearthkinDu($groupe))->toHaveCount(0);
});
