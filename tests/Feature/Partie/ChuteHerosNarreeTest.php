<?php

declare(strict_types=1);

use App\Models\Evenement;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * La chute et le relèvement d'un héros se DISENT.
 *
 * En campagne réelle (2026-08-20), une héroïne est restée à terre vingt-deux
 * minutes sans qu'une seule ligne ne le signale — et aucun temps fort
 * n'existait pour ça. Un personnage joueur qui s'écroule est le moment le plus
 * dramatique de la partie.
 *
 * ⚠ Ce que ces tests protègent vraiment, c'est le CHOIX D'ACCROCHE :
 * `tombe => true` est écrit depuis huit endroits (ResolveurTour ×4,
 * MoteurPieges, MoteurDread ×3). L'annonce est donc observée sur la COLONNE et
 * non câblée aux appelants — sinon le prochain chemin de dégâts ajouté serait
 * muet, sans que rien ne le dise. On éprouve ici deux chemins d'écriture
 * DIFFÉRENTS pour que l'observateur reste la seule réponse possible.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Textes de narration du groupe, dans l'ordre. @return list<string> */
function narrations(int $groupeId): array
{
    return Evenement::where('groupe_id', $groupeId)->where('type', 'narration')
        ->orderBy('sequence')->get()
        ->map(fn (Evenement $e) => (string) ($e->payload['texte'] ?? ''))->all();
}

it('annonce la chute d’un héros, quel que soit l’écrivain de la colonne', function () {
    ['groupe' => $groupe, 'etatHeros' => $etat, 'heros' => $heros] = demarrerQueteAvecMonstre('Gobelin');

    $avant = count(narrations($groupe->id));

    // Écriture DIRECTE de la colonne : on ne passe par aucun résolveur, ce qui
    // est exactement le point — n'importe lequel des huit chemins doit parler.
    $etat->update(['tombe' => true]);

    $apres = narrations($groupe->id);

    expect(count($apres))->toBeGreaterThan($avant, 'la chute n’a rien narré');
    expect(end($apres))->toContain($heros->nom);
});

it('annonce le relèvement, et ne parle que sur un vrai changement', function () {
    ['groupe' => $groupe, 'etatHeros' => $etat, 'heros' => $heros] = demarrerQueteAvecMonstre('Gobelin');

    $etat->update(['tombe' => true]);
    $apresChute = count(narrations($groupe->id));

    // Ré-écrire la MÊME valeur ne doit rien dire : `wasChanged()` protège des
    // sauvegardes répétées que le résolveur enchaîne sur un même tour.
    $etat->update(['tombe' => true]);
    expect(narrations($groupe->id))->toHaveCount($apresChute, 'une écriture sans changement a parlé');

    $etat->update(['tombe' => false]);
    $apres = narrations($groupe->id);

    expect(count($apres))->toBeGreaterThan($apresChute, 'le relèvement n’a rien narré');
    expect(end($apres))->toContain($heros->nom);
});

/**
 * Best-effort : l'annonce se produit au milieu d'une transaction de résolution
 * de tour. Une narration qui échoue ne doit jamais faire échouer le coup qui
 * vient d'être porté.
 */
it('ne fait pas échouer une chute quand aucun texte n’existe', function () {
    ['groupe' => $groupe, 'etatHeros' => $etat] = demarrerQueteAvecMonstre('Gobelin');

    config(['narration.repli.heros_tombe.variantes' => []]);

    $etat->update(['tombe' => true]);

    expect($etat->fresh()->tombe)->toBeTrue('la chute elle-même a été perdue');
});
