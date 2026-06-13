<?php

declare(strict_types=1);

use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Habillage IA des monstres (doc 06 §5, Q6) : DemarreurQuete dispatch
 * HabillerMonstres, qui renomme/redécrit les instances déjà spawnées par le
 * moteur — sans toucher aux stats ni au nombre. File synchrone en test.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/**
 * Fake Anthropic qui DRESSE exactement les monstre_id présents dans la requête
 * (extraits du corps), pour que validerMetier accepte la sortie. Tout autre
 * appel (narration, menu, Qdrant) → réponse vide → repli, sans effet.
 */
function fakeHabillage(): void
{
    Http::fake(function ($request) {
        $data = $request->data();
        $toolName = $data['tool_choice']['name'] ?? null;

        if (str_contains($request->url(), 'anthropic') && $toolName === 'habiller_monstres') {
            // Les monstre_id à habiller apparaissent comme valeurs dans le bloc
            // « MONSTRES À HABILLER » du prompt utilisateur (corps décodé).
            $contenu = $data['messages'][0]['content'] ?? '';
            preg_match_all('/"monstre_id":\s*(\d+)/', is_string($contenu) ? $contenu : json_encode($contenu), $m);
            $ids = array_values(array_unique(array_map('intval', $m[1])));

            return Http::response([
                'stop_reason' => 'tool_use',
                'content' => [[
                    'type' => 'tool_use',
                    'name' => 'habiller_monstres',
                    'input' => ['habillages' => array_map(fn ($id) => [
                        'monstre_id' => $id,
                        'nom' => 'Écumeur des cryptes',
                        'description' => 'Une silhouette tordue, née de la malédiction qui ronge la cité.',
                    ], $ids)],
                ]],
            ]);
        }

        return Http::response([], 200);
    });
    config()->set('services.anthropic.api_key', 'cle-test');
}

function demarrerQueteSimple(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    return [$alice, $groupe, $heros, $quete];
}

it('habille les instances spawnées (nom + description) sans changer stats ni nombre', function () {
    fakeHabillage();
    [, , , $quete] = demarrerQueteSimple();

    $instances = $quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get();
    expect($instances)->not->toBeEmpty();

    foreach ($instances as $instance) {
        expect($instance->habillage['nom'] ?? null)->toBe('Écumeur des cryptes')
            ->and($instance->habillage['description'] ?? '')->not->toBeEmpty()
            ->and($instance->pv_body)->toBe($instance->monstre->pv_body); // stats catalogue intactes
    }

    // Le job ne crée ni ne supprime aucune instance (moteur autorité sur le nombre).
    expect($quete->instancesMonstres()->count())->toBe($instances->count());
});

it('sans LLM joignable, conserve les noms de catalogue (repli, jeu jouable)', function () {
    config()->set('services.anthropic.api_key', null);
    Http::fake(['api.anthropic.com/*' => Http::response([], 500), '*' => Http::response([], 200)]);

    [, , , $quete] = demarrerQueteSimple();

    foreach ($quete->instancesMonstres()->get() as $instance) {
        expect($instance->habillage['nom'] ?? null)->toBeNull();
    }
});
