<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Héros TOMBÉ (correctifs §3) : un héros à terre ne bloque plus le passage ni la
 * ligne de vue (on l'enjambe), mais reste SECOURABLE (relever) seulement si
 * aucune autre figure n'occupe sa case et qu'un allié est adjacent.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/**
 * Quête à deux héros d'alice, un tombé à côté de l'autre. Renvoie l'état vivant
 * utile : le sauveteur (debout), le tombé, sa case, la quête.
 *
 * @return array{quete: Quete, joueurId: int, sauveteur: EtatPersonnageQuete, tombe: EtatPersonnageQuete, caseTombe: array{x:int,y:int}}
 */
function deuxHerosUnTombe(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $sauveteur = creerHeros($alice, $groupe, 'Sauveteur', 1);
    $tombeHeros = creerHeros($alice, $groupe, 'Tombé', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    // On dégage les monstres : on isole la mécanique du héros tombé.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);

    $etatSauveteur = $quete->etatsPersonnages()->where('personnage_id', $sauveteur->id)->firstOrFail();
    $sx = (int) $etatSauveteur->position_x;
    $sy = (int) $etatSauveteur->position_y;

    // Case libre adjacente au sauveteur → on y couche le héros tombé.
    $caseTombe = caseAdjacenteLibre($quete, $sx, $sy);
    $etatTombe = $quete->etatsPersonnages()->where('personnage_id', $tombeHeros->id)->firstOrFail();
    $etatTombe->update(['position_x' => $caseTombe['x'], 'position_y' => $caseTombe['y'], 'tombe' => true]);

    return ['quete' => $quete, 'joueurId' => (int) $alice->id, 'sauveteur' => $etatSauveteur->fresh(), 'tombe' => $etatTombe->fresh(), 'caseTombe' => $caseTombe];
}

it('n\'empêche pas un héros de se déplacer SUR la case d\'un allié tombé', function () {
    ['quete' => $quete, 'joueurId' => $joueurId, 'sauveteur' => $sauveteur, 'caseTombe' => $caseTombe] = deuxHerosUnTombe();

    // Menu de déplacement (le d6 d'allonge est figé) puis on va SUR la case du tombé.
    desFiges(array_fill(0, 20, 4));
    GenererMenu::dispatchSync($quete->groupe_id, $joueurId, $sauveteur->personnage_id);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => $caseTombe['x'], 'y' => $caseTombe['y']],
    ])->assertStatus(202)->assertJsonPath('resultat.type', 'deplacement');

    expect($sauveteur->fresh()->only(['position_x', 'position_y']))
        ->toBe(['position_x' => $caseTombe['x'], 'position_y' => $caseTombe['y']]);
});

it('relève un allié tombé quand sa case est libre et le sauveteur adjacent', function () {
    ['quete' => $quete, 'joueurId' => $joueurId, 'sauveteur' => $sauveteur, 'tombe' => $tombe] = deuxHerosUnTombe();

    desFiges(array_fill(0, 20, 4));
    GenererMenu::dispatchSync($quete->groupe_id, $joueurId, $sauveteur->personnage_id);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => "relever_{$tombe->personnage_id}",
    ])->assertStatus(202)->assertJsonPath('resultat.type', 'relever');

    expect($tombe->fresh()->tombe)->toBeFalse()
        ->and((int) $tombe->personnage->fresh()->pv_body)->toBe(1);
});

it('refuse de relever si une autre figure occupe la case du tombé', function () {
    ['quete' => $quete, 'joueurId' => $joueurId, 'sauveteur' => $sauveteur, 'tombe' => $tombe, 'caseTombe' => $caseTombe] = deuxHerosUnTombe();

    // Un monstre actif révélé se tient SUR la case du tombé (rendue franchissable).
    $quete->instancesMonstres()->orderBy('id')->firstOrFail()->update([
        'etat' => 'actif', 'revele' => true,
        'position_x' => $caseTombe['x'], 'position_y' => $caseTombe['y'],
    ]);

    desFiges(array_fill(0, 20, 4));
    GenererMenu::dispatchSync($quete->groupe_id, $joueurId, $sauveteur->personnage_id);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => "relever_{$tombe->personnage_id}",
    ])->assertStatus(422);

    expect($tombe->fresh()->tombe)->toBeTrue(); // toujours à terre
});
