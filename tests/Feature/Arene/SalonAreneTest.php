<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Arene\GroupesArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/**
 * MODE ARÈNE, lot A5 — le salon et ses routes, joués de bout en bout : ouvrir, rejoindre, composer le Defender, se
 * dire prêt, lancer, gagner, rejouer, quitter (`docs/contrat-api.md` §Le salon et les routes d'arène).
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

function herosAreneVia(string $pseudo, string $nom, string $classe = 'barbare', string $mode = 'arene'): array
{
    $joueur = connecterJoueur($pseudo);
    $id = test()->postJson('/api/personnages', ['nom' => $nom, 'classe' => $classe, 'mode' => $mode])->assertCreated()->json('personnage.id');

    return [$joueur, Personnage::findOrFail($id)];
}

function idDuMonstre(string $nom): int
{
    return (int) Monstre::where('nom_base', $nom)->value('id');
}

it('joue un combat de bout en bout par les routes du salon, puis le rejoue et le quitte sans toucher aux héros', function () {
    [$alice, $brakka] = herosAreneVia('alice', 'Brakka');
    [$bob, $borin] = herosAreneVia('bob', 'Borin', 'nain');
    $avant = [empreinteDuHeros($brakka->id), empreinteDuHeros($borin->id)];

    // Alice ouvre, Bob rejoint par le code.
    $this->actingAs($alice, 'joueur');
    $ouverture = $this->postJson('/api/arene', ['personnage_id' => $brakka->id])->assertCreated();
    $code = $ouverture->json('groupe.identifiant');
    $this->actingAs($bob, 'joueur');
    $this->postJson("/api/arene/{$code}/combattants", ['personnage_id' => $borin->id])->assertCreated();

    // La manette de Bob trouve la fiche de SA doublure dans /moi, hors du roster.
    $moi = $this->getJson('/api/moi')->json('joueur');
    expect(collect($moi['doublures'])->pluck('nom')->all())->toBe(['Borin'])
        ->and(collect($moi['personnages'])->pluck('id')->all())->toBe([$borin->id]);

    // Le salon est publié — et le hub de campagne ne l'est pas.
    $etat = $this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('groupe');
    expect($etat['mode'])->toBe('arene')
        ->and($etat['arene']['bandeau'])->toBe(GroupesArene::BANDEAU)
        ->and($etat['arene']['combattants'])->toHaveCount(2)
        ->and(collect($etat['arene']['monstres'])->pluck('nom_base')->all())->toBe(GroupesArene::ROSTER_DEFENDER)
        ->and($etat['arene']['lancement_possible'])->toBeFalse()
        ->and($etat)->not->toHaveKeys(['prets', 'recrutement', 'compagnon_offert', 'depart_possible']);

    // La TABLE (session sans compte) compose le Defender.
    $this->withSession(['table_groupe' => $code]);
    $this->putJson("/api/arene/{$code}/defender", ['monstre_ids' => [idDuMonstre('Gobelin'), idDuMonstre('Orque')]])->assertOk();

    // Lancer avant que tous soient prêts : refusé, avec le motif publié.
    $this->postJson("/api/arene/{$code}/lancer")->assertUnprocessable()
        ->assertJsonValidationErrors(['arene' => 'prêts']);

    $this->actingAs($alice, 'joueur');
    $this->postJson("/api/arene/{$code}/pret", ['pret' => true])->assertOk();
    $this->actingAs($bob, 'joueur');
    $etat = $this->postJson("/api/arene/{$code}/pret", ['pret' => true])->assertOk()->json('groupe.arene');
    expect($etat['lancement_possible'])->toBeTrue();

    $this->postJson("/api/arene/{$code}/lancer")->assertCreated();
    $arene = Groupe::where('identifiant', $code)->firstOrFail();
    expect($arene->phase)->toBe('quete');

    // Victoire : le Defender éteint, puis une fin d'activation.
    $quete = Quete::findOrFail($arene->quete_courante_id);
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'attendre'])->assertSuccessful();

    $salon = $this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene');
    expect($salon['resultat']['issue'])->toBe('victoire')
        ->and($salon['lancement_possible'])->toBeFalse();

    // Revanche : doublures fraîches, Defender gardé, prêts remis à faux, combat joué effacé.
    $anciennes = collect($salon['combattants'])->pluck('personnage_id')->all();
    $salon = $this->postJson("/api/arene/{$code}/rejouer")->assertOk()->json('groupe.arene');
    expect($salon['resultat'])->toBeNull()
        ->and($salon['defender'])->toHaveCount(2)
        ->and(collect($salon['combattants'])->pluck('pret')->unique()->all())->toBe([false])
        ->and(array_intersect(collect($salon['combattants'])->pluck('personnage_id')->all(), $anciennes))->toBe([])
        ->and(collect($salon['combattants'])->pluck('heros_id')->sort()->values()->all())->toBe([$brakka->id, $borin->id]);

    // Quitter : tout part, les héros d'arène sont intacts.
    $this->deleteJson("/api/arene/{$code}")->assertNoContent();
    expect(Groupe::where('identifiant', $code)->exists())->toBeFalse()
        ->and(Personnage::whereNotNull('doublure_de')->count())->toBe(0)
        ->and([empreinteDuHeros($brakka->id), empreinteDuHeros($borin->id)])->toBe($avant);
});

it('refuse un héros de campagne, un monstre hors roster, et les routes d\'arène sur une campagne', function () {
    [$alice, $aventurier] = herosAreneVia('alice', 'Aldric', 'barbare', 'campagne');
    $this->postJson('/api/arene', ['personnage_id' => $aventurier->id])->assertUnprocessable()
        ->assertJsonValidationErrors(['personnage_id' => 'héros de campagne']);

    $champion = Personnage::findOrFail($this->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $code = $this->postJson('/api/arene', ['personnage_id' => $champion->id])->json('groupe.identifiant');

    $this->putJson("/api/arene/{$code}/defender", ['monstre_ids' => [idDuMonstre('Fimir')]])->assertUnprocessable()
        ->assertJsonValidationErrors(['monstre_ids' => 'roster']);

    $campagne = creerGroupe();
    creerHeros($alice, $campagne, 'Témoin', 0);
    $this->putJson("/api/arene/{$campagne->identifiant}/defender", ['monstre_ids' => [idDuMonstre('Gobelin')]])
        ->assertUnprocessable()->assertJsonValidationErrors('arene');
    $this->deleteJson("/api/arene/{$campagne->identifiant}")->assertUnprocessable();
    expect(Groupe::whereKey($campagne->id)->exists())->toBeTrue();
});

it('un nouveau venu ou un nouveau Defender remet tout le monde à « pas prêt »', function () {
    [$alice, $brakka] = herosAreneVia('alice', 'Brakka');
    $code = $this->postJson('/api/arene', ['personnage_id' => $brakka->id])->json('groupe.identifiant');
    $this->putJson("/api/arene/{$code}/defender", ['monstre_ids' => [idDuMonstre('Gobelin')]])->assertOk();
    $this->postJson("/api/arene/{$code}/pret", ['pret' => true])->assertOk();

    $salon = $this->putJson("/api/arene/{$code}/defender", ['monstre_ids' => [idDuMonstre('Orque')]])->json('groupe.arene');
    expect($salon['combattants'][0]['pret'])->toBeFalse();

    $this->postJson("/api/arene/{$code}/pret", ['pret' => true])->assertOk();
    [$bob, $borin] = herosAreneVia('bob', 'Borin', 'nain');
    $this->postJson("/api/arene/{$code}/combattants", ['personnage_id' => $borin->id])->assertCreated();
    $salon = $this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene');
    expect(collect($salon['combattants'])->pluck('pret')->unique()->all())->toBe([false]);
});
