<?php

declare(strict_types=1);

use App\Engine\Des\LanceurDes;
use App\Engine\Des\LanceurDeterministe;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use App\Partie\ClotureCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Régression : une campagne GAGNÉE (boss final vaincu) doit se clôturer en
 * `victoire`, jamais en `abandon`. Bug constaté en partie 3-quêtes : la
 * clôture était enregistrée « abandon » alors que le boss final était mort.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

function figerDesCloture(array $valeurs): LanceurDeterministe
{
    $lanceur = new LanceurDeterministe($valeurs);
    app()->instance(LanceurDes::class, $lanceur);

    return $lanceur;
}

it('le coup fatal au boss final ouvre AUTOMATIQUEMENT une clôture victoire (flux API réel)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe('table-1', nbQuetes: 1); // 1 quête → position 1 = boss_final
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    expect($quete->type_jalon)->toBe('boss_final');

    // Dernier monstre, affaibli, au contact du héros.
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1, 'revele' => true]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);
    figerDesCloture([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$proie->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.quete.etat', 'terminee');

    expect($groupe->fresh()->phase)->toBe('hub');

    // Exactement ce que fait le narrateur : GET clôture après le boss.
    $this->getJson('/api/groupes/table-1/cloture')
        ->assertOk()
        ->assertJsonPath('issue', 'victoire');
});

it('ouverture MANUELLE au hub après boss final vaincu → issue victoire', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe('table-1', nbQuetes: 1);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => \App\Models\GabaritQuete::where('type_jalon', 'boss_final')->value('id'),
        'titre' => 'Confrontation finale',
        'position_arc' => 1,
        'type_jalon' => 'boss_final',
        'etat' => 'terminee',
        'or_initial' => 0,
    ]);

    $etat = app(ClotureCampagne::class)->ouvrir($groupe->fresh());
    expect($etat['issue'])->toBe('victoire');
});

it('un TPK sur la quête finale clôturé SANS drapeau → echec (or plafonné), pas abandon', function () {
    // L'inverse du bug : une fin PERDUE ne doit pas non plus être mal étiquetée.
    // Le narrateur ferme la campagne (POST /cloture, sans `abandon`) après un TPK.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe('table-1', nbQuetes: 1);
    $groupe->update(['or' => 80]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => \App\Models\GabaritQuete::where('type_jalon', 'boss_final')->value('id'),
        'titre' => 'Confrontation finale',
        'position_arc' => 1,
        'type_jalon' => 'boss_final',
        'etat' => 'echouee',
        'or_initial' => 200,
    ]);

    $this->postJson('/api/groupes/table-1/cloture')
        ->assertCreated()
        ->assertJsonPath('issue', 'echec')      // et NON 'abandon'
        ->assertJsonPath('or_a_partager', 80);  // or_initial 200 plafonné à l'or restant
});
