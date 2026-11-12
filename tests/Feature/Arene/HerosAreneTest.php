<?php

declare(strict_types=1);

use App\Models\Personnage;
use App\Partie\Arene\Doublures;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;

/**
 * MODE ARÈNE, lot A6b — le héros DÉDIÉ à l'arène (`docs/plan-mode-arene.md` §2).
 *
 * René, 2026-11-09 : « mettons de côté l'import des personnages de heroquest rpg pour le moment, permettons seulement
 * la création ou l'utilisation d'un héros déjà créé pour l'arène afin d'intégrer les règles tel que dans le livret ».
 * Un héros d'arène naît comme le héros du livret — sa carte de classe, son équipement de départ, ses capacités de
 * carte, niveau 1 — et n'entre jamais en campagne. Il combat par doublure : il reste frais d'un combat à l'autre.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, SortSeeder::class, ObjetSeeder::class]);
});

function creerHerosAreneParApi(array $corps): Personnage
{
    $id = test()->postJson('/api/personnages', $corps)->assertCreated()->json('personnage.id');

    return Personnage::findOrFail($id);
}

it('crée un héros d\'arène comme le héros du livret : niveau 1, carte de classe, équipement de départ, capacités de carte', function (string $classe) {
    connecterJoueur();

    $arene = creerHerosAreneParApi(['nom' => 'Champion', 'classe' => $classe, 'mode' => 'arene']);
    $campagne = creerHerosAreneParApi(['nom' => 'Témoin', 'classe' => $classe]);

    $fiche = ['niveau', 'attribut_body', 'attribut_mind', 'pv_body_max', 'pv_body', 'pv_mind_max', 'pv_mind',
        'des_attaque', 'des_defense', 'deplacement_base'];

    expect($arene->mode)->toBe(Personnage::MODE_ARENE)
        ->and($arene->estHerosArene())->toBeTrue()
        ->and($campagne->mode)->toBe(Personnage::MODE_CAMPAGNE)
        ->and((int) $arene->niveau)->toBe(1)
        ->and($arene->groupe_actif_id)->toBeNull()
        // Même naissance qu'un héros de campagne de la même classe : rien de plus, rien de moins.
        ->and($arene->only($fiche))->toBe($campagne->only($fiche))
        ->and($arene->inventaire()->orderBy('id')->pluck('objet_id')->all())
        ->toBe($campagne->inventaire()->orderBy('id')->pluck('objet_id')->all())
        // Aucun talent de grille : seules les capacités de CARTE, qui viennent avec la figurine.
        ->and($arene->competences()->where('innee', false)->count())->toBe(0)
        ->and($arene->competences()->pluck('competences.id')->sort()->values()->all())
        ->toBe($campagne->competences()->pluck('competences.id')->sort()->values()->all());
})->with(['barbare', 'nain', 'moine']);

it('refuse un mode hors du vocabulaire', function () {
    connecterJoueur();

    $this->postJson('/api/personnages', ['nom' => 'Intrus', 'classe' => 'barbare', 'mode' => 'tournoi'])
        ->assertUnprocessable()->assertJsonValidationErrors('mode');

    expect(Personnage::count())->toBe(0);
});

it('publie la sorte de héros et la DÉCISION « engageable en campagne » dans le roster', function () {
    connecterJoueur();
    $arene = creerHerosAreneParApi(['nom' => 'Champion', 'classe' => 'barbare', 'mode' => 'arene']);
    $campagne = creerHerosAreneParApi(['nom' => 'Aventurier', 'classe' => 'barbare']);

    $roster = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->keyBy('id');

    expect($roster[$arene->id]['mode'])->toBe('arene')
        ->and($roster[$arene->id]['engageable_campagne'])->toBeFalse()
        ->and($roster[$campagne->id]['mode'])->toBe('campagne')
        ->and($roster[$campagne->id]['engageable_campagne'])->toBeTrue();
});

it('un héros d\'arène ne fonde ni ne rejoint jamais une campagne', function () {
    connecterJoueur();
    $arene = creerHerosAreneParApi(['nom' => 'Champion', 'classe' => 'barbare', 'mode' => 'arene']);
    $groupe = creerGroupe('table-2');

    $this->postJson('/api/groupes', ['nom' => 'Usurpateurs', 'theme' => 'Cryptes', 'longueur' => 'courte',
        'personnage_id' => $arene->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['personnage_id' => 'héros d\'arène']);
    $this->postJson("/api/groupes/{$groupe->identifiant}/joueurs", ['personnage_id' => $arene->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['personnage_ids' => 'héros d\'arène']);

    expect($arene->refresh()->groupe_actif_id)->toBeNull()
        ->and($groupe->personnages()->count())->toBe(0)
        ->and(\App\Models\Groupe::count())->toBe(1);
});

it('un héros d\'arène déjà créé se réutilise : chaque combat repart d\'une doublure fraîche', function () {
    connecterJoueur();
    $arene = creerHerosAreneParApi(['nom' => 'Champion', 'classe' => 'barbare', 'mode' => 'arene']);
    $doublures = app(Doublures::class);

    $premiere = $doublures->doubler($arene);
    $premiere->update(['pv_body' => 0]);
    $premiere->inventaire()->delete();
    $doublures->supprimer($premiere);

    $seconde = $doublures->doubler($arene->refresh());

    expect($seconde->pv_body)->toBe($arene->pv_body_max)
        ->and($seconde->inventaire()->count())->toBe($arene->inventaire()->count())
        ->and($seconde->inventaire()->count())->toBeGreaterThan(0)
        ->and($seconde->mode)->toBe(Personnage::MODE_ARENE);
});

it('un héros d\'arène se supprime du roster comme tout héros jamais joué', function () {
    connecterJoueur();
    $arene = creerHerosAreneParApi(['nom' => 'Champion', 'classe' => 'barbare', 'mode' => 'arene']);

    $this->deleteJson("/api/personnages/{$arene->id}")->assertNoContent();

    expect(Personnage::count())->toBe(0);
});
