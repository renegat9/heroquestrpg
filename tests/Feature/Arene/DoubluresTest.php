<?php

declare(strict_types=1);

use App\Models\Condition;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Sort;
use App\Partie\Arene\Doublures;
use App\Partie\Arene\RefusArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot A1 — doublures et ÉTANCHÉITÉ (`docs/plan-mode-arene.md` §2).
 *
 * « Il faudrait que ça n'impacte pas le mode campagne et leurs héros » (René, 2026-10-04). Le héros n'entre jamais
 * dans l'arène : sa DOUBLURE y entre, et ce fichier est le test qui garde tout le reste — écrit AVANT toute autre
 * ligne de l'arène. Si un jour un site écrit par erreur sur le héros source, l'empreinte change et il casse.
 *
 * ⚠ Au lot A1, il n'existe encore ni groupe d'arène ni combat d'arène : le « combat » ci-dessous écrit sur la
 * doublure chacune des écritures que le moteur fait sur un héros (plan §1). Dès le lot A2, il sera rejoué par les
 * vraies routes d'un combat d'arène, avec la même empreinte.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class]);
});

/** Les tables qui portent une clé étrangère vers `personnages`, et la colonne qui la porte, lues sur le SCHÉMA. */
function tablesLieesAuxPersonnages(): array
{
    $liees = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        foreach (Schema::getForeignKeys($table) as $cle) {
            if ($cle['foreign_table'] === 'personnages' && $table !== 'personnages') {
                $liees[$table][] = $cle['columns'][0];
            }
        }
    }

    ksort($liees);

    return $liees;
}

/**
 * L'EMPREINTE complète d'un héros : sa ligne et TOUTES les lignes qui le désignent, quelle que soit la table — lue sur
 * le schéma, pas sur une liste à tenir à jour : une table ajoutée demain y entre d'elle-même.
 */
function empreinteDuHeros(int $id): string
{
    $empreinte = ['personnages' => (array) DB::table('personnages')->where('id', $id)->first()];

    foreach (tablesLieesAuxPersonnages() as $table => $colonnes) {
        foreach ($colonnes as $colonne) {
            $empreinte["{$table}.{$colonne}"] = DB::table($table)->where($colonne, $id)->orderBy('id')->get()
                ->map(fn ($ligne) => (array) $ligne)->all();
        }
    }

    return json_encode($empreinte, JSON_THROW_ON_ERROR);
}

/** Un héros de campagne qui a vécu : blessé, engagé, endetté de sorts, avec de l'or, un sac et une malédiction. */
function herosDeCampagneEntame(): Personnage
{
    $joueur = connecterJoueur();
    $groupe = creerGroupe();
    $heros = creerHeros($joueur, $groupe, 'Grimbold', 0, [
        'classe' => 'nain', 'niveau' => 3, 'pv_body' => 3, 'pv_mind' => 1, 'or' => 240,
        'malediction_oracle' => true,
    ]);
    donnerTalent($heros, DB::table('competences')->where('classe', 'nain')->whereNotNull('colonne')->orderBy('id')->value('nom'));

    Inventaire::create(['personnage_id' => $heros->id, 'objet_id' => Objet::where('nom', 'Dague')->value('id'),
        'quantite' => 2, 'emplacement' => 'arme_principale']);
    Inventaire::create(['personnage_id' => $heros->id, 'objet_id' => Objet::where('nom', 'Potion de soin')->value('id'),
        'quantite' => 1, 'emplacement' => 'sac']);

    $sorts = Sort::orderBy('id')->limit(2)->pluck('id');
    $heros->sorts()->attach($sorts[0], ['disponible' => false]); // déjà lancé en campagne
    $heros->sorts()->attach($sorts[1], ['disponible' => true]);

    $heros->conditions()->attach(Condition::where('nom', 'Empoisonné')->value('id'), ['duree' => 2, 'source' => 'piege']);

    return $heros->refresh();
}

it('déclare CHAQUE colonne de personnages, copiée ou non, et rien qui n\'existe pas', function () {
    $colonnes = Schema::getColumnListing('personnages');
    $copiees = Doublures::COLONNES_COPIEES;
    $nonCopiees = array_keys(Doublures::COLONNES_NON_COPIEES);

    expect(array_intersect($copiees, $nonCopiees))->toBe([])
        ->and(array_values(array_diff($colonnes, $copiees, $nonCopiees)))->toBe([], 'colonne de personnages sans décision « copiée ou pas » dans Doublures')
        ->and(array_values(array_diff([...$copiees, ...$nonCopiees], $colonnes)))->toBe([], 'colonne déclarée dans Doublures qui n\'existe plus');
});

it('déclare CHAQUE table liée à personnages, copiée ou non, et rien qui n\'existe pas', function () {
    $liees = array_keys(tablesLieesAuxPersonnages());
    $declarees = [...Doublures::TABLES_COPIEES, ...array_keys(Doublures::TABLES_NON_COPIEES)];

    expect(array_intersect(Doublures::TABLES_COPIEES, array_keys(Doublures::TABLES_NON_COPIEES)))->toBe([])
        ->and(array_values(array_diff($liees, $declarees)))->toBe([], 'table liée à personnages sans décision dans Doublures')
        ->and(array_values(array_diff($declarees, $liees)))->toBe([], 'table déclarée dans Doublures qui ne pointe plus vers personnages');
});

it('copie le héros RAFRAÎCHI : PV pleins, sorts rechargés, sans conditions, sans or ni état de campagne', function () {
    $heros = herosDeCampagneEntame();

    $doublure = app(Doublures::class)->doubler($heros);

    expect($doublure->id)->not->toBe($heros->id)
        ->and($doublure->doublure_de)->toBe($heros->id)
        ->and($doublure->estDoublure())->toBeTrue()
        ->and($doublure->joueur_id)->toBe($heros->joueur_id)
        ->and($doublure->only(['nom', 'classe', 'niveau', 'attribut_body', 'attribut_mind', 'des_attaque', 'des_defense']))
        ->toBe($heros->only(['nom', 'classe', 'niveau', 'attribut_body', 'attribut_mind', 'des_attaque', 'des_defense']))
        ->and($doublure->pv_body)->toBe($heros->pv_body_max)
        ->and($doublure->pv_mind)->toBe($heros->pv_mind_max)
        ->and($doublure->or)->toBe(0)
        ->and($doublure->groupe_actif_id)->toBeNull()
        ->and($doublure->malediction_oracle)->toBeFalse()
        ->and($doublure->groupes()->count())->toBe(0)
        ->and($doublure->conditions()->count())->toBe(0)
        ->and($doublure->sorts()->pluck('sorts.id')->sort()->values()->all())
        ->toBe($heros->sorts()->pluck('sorts.id')->sort()->values()->all())
        ->and($doublure->sorts()->wherePivot('disponible', false)->count())->toBe(0)
        ->and($doublure->competences()->pluck('competences.id')->sort()->values()->all())
        ->toBe($heros->competences()->pluck('competences.id')->sort()->values()->all())
        ->and($doublure->inventaire()->orderBy('id')->get(['objet_id', 'quantite', 'emplacement'])->toArray())
        ->toBe($heros->inventaire()->orderBy('id')->get(['objet_id', 'quantite', 'emplacement'])->toArray());
});

it('ÉTANCHÉITÉ : tout ce que le combat fait à la doublure laisse le héros source identique au bit près', function () {
    $heros = herosDeCampagneEntame();
    $avant = empreinteDuHeros($heros->id);

    $doublures = app(Doublures::class);
    $doublure = $doublures->doubler($heros);

    // Chaque écriture du moteur sur un héros (plan §1), appliquée à la doublure.
    $doublure->update(['pv_body' => 0, 'pv_mind' => 0, 'or' => 75, 'niveau' => 4, 'benediction_oracle' => true]);
    $doublure->inventaire()->whereHas('objet', fn ($q) => $q->where('nom', 'Potion de soin'))->delete(); // potion bue
    $doublure->inventaire()->whereHas('objet', fn ($q) => $q->where('nom', 'Dague'))->decrement('quantite'); // dague lancée
    $doublure->inventaire()->whereHas('objet', fn ($q) => $q->where('nom', 'Dague'))->delete(); // Rouille
    $doublure->sorts()->updateExistingPivot($doublure->sorts()->pluck('sorts.id')->all(), ['disponible' => false]);
    $doublure->conditions()->attach(Condition::where('nom', 'Étourdi')->value('id'), ['duree' => 1, 'source' => 'monstre']);
    $doublure->competences()->detach();
    Inventaire::create(['personnage_id' => $doublure->id, 'objet_id' => Objet::where('nom', 'Dague')->value('id'),
        'quantite' => 1, 'emplacement' => 'sac']); // butin ramassé

    $doublures->supprimer($doublure);

    expect(empreinteDuHeros($heros->id))->toBe($avant)
        ->and(Personnage::whereNotNull('doublure_de')->count())->toBe(0)
        ->and(Inventaire::where('personnage_id', $doublure->id)->count())->toBe(0)
        ->and(DB::table('personnage_sorts')->where('personnage_id', $doublure->id)->count())->toBe(0)
        ->and(DB::table('personnage_competences')->where('personnage_id', $doublure->id)->count())->toBe(0)
        ->and(DB::table('personnage_conditions')->where('personnage_id', $doublure->id)->count())->toBe(0);
});

it('refuse de doubler une doublure', function () {
    $doublure = app(Doublures::class)->doubler(herosDeCampagneEntame());

    expect(fn () => app(Doublures::class)->doubler($doublure))->toThrow(RefusArene::class);
});

it('refuse de supprimer un VRAI héros par le chemin des doublures', function () {
    $heros = herosDeCampagneEntame();
    $avant = empreinteDuHeros($heros->id);

    expect(fn () => app(Doublures::class)->supprimer($heros))->toThrow(RefusArene::class)
        ->and(empreinteDuHeros($heros->id))->toBe($avant);

    // Même une instance trafiquée en mémoire ne passe pas : le verrou relit la base.
    $heros->doublure_de = $heros->id;
    expect(fn () => app(Doublures::class)->supprimer($heros))->toThrow(RefusArene::class)
        ->and(Personnage::whereKey($heros->id)->exists())->toBeTrue();
});

it('le roster ne montre jamais une doublure', function () {
    $heros = herosDeCampagneEntame();
    $doublure = app(Doublures::class)->doubler($heros);

    $ids = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->pluck('id');

    expect($ids->all())->toContain($heros->id)->not->toContain($doublure->id);
});

it('une doublure ne fonde ni ne rejoint jamais une campagne, et ne se supprime pas par l\'API du roster', function () {
    $heros = herosDeCampagneEntame();
    $doublure = app(Doublures::class)->doubler($heros);
    $autre = creerGroupe('table-2');

    $this->postJson('/api/groupes', ['nom' => 'Usurpateurs', 'theme' => 'Cryptes', 'longueur' => 'courte',
        'ton' => 'epique', 'personnage_id' => $doublure->id])
        ->assertUnprocessable();
    $this->postJson("/api/groupes/{$autre->identifiant}/joueurs", ['personnage_id' => $doublure->id])
        ->assertUnprocessable()->assertJsonValidationErrors('personnage_ids');
    $this->deleteJson("/api/personnages/{$doublure->id}")->assertNotFound();

    expect($doublure->refresh()->groupe_actif_id)->toBeNull()
        ->and($autre->personnages()->count())->toBe(0);
});
