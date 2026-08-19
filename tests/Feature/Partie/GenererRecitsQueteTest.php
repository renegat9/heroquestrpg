<?php

declare(strict_types=1);

use App\Models\Quete;
use App\Partie\Narration\TempsFort;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Pipeline complet de pré-génération des récits (lot « récits pré-générés »,
 * décision de René 2026-08-18) : DemarreurQuete -> HabillerMonstres ->
 * GenererRecitsQuete -> GenererVoixQuete, tout en file SYNCHRONE (phpunit.xml
 * force QUEUE_CONNECTION=sync), donc `POST .../quetes` exécute toute la
 * chaîne avant de répondre.
 *
 * ⚠ GenererRecitsQuete ne fait plus qu'UN SEUL appel LLM (RecitsQuete a
 * fusionné les deux skills précédents) : le fake ci-dessous ne répond donc
 * qu'à DEUX outils forcés — habillage des monstres, puis le pack unique de
 * récits — là où il en fallait trois avant la fusion.
 *
 * Même socle de seeders et même style de fake HTTP que
 * tests/Feature/Partie/HabillageMonstresTest.php (le job voisin dont celui-ci
 * hérite le point d'accroche), pour rester cohérent avec le reste du dépôt.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

const NOM_HABILLE_TEST_RECITS = 'Écumeur des cryptes';

/**
 * Fake Anthropic répondant aux DEUX outils forcés successivement dispatchés
 * au démarrage d'une quête : habillage des monstres, puis `ecrire_recits_quete`
 * (salles + les 3 temps forts propres à cette quête). `$captures->salles`
 * reçoit le contenu brut envoyé à `ecrire_recits_quete` (objet plutôt que
 * référence : plus sûr à travers la fermeture ci-dessous), pour vérifier que
 * le contexte contient bien les noms HABILLÉS (pas ceux du catalogue).
 */
function fakeChainePreGeneration(stdClass $captures): void
{
    Http::fake(function ($request) use ($captures) {
        if (! str_contains($request->url(), 'anthropic')) {
            return Http::response([], 200);
        }

        $data = $request->data();
        $outil = $data['tool_choice']['name'] ?? null;
        $contenu = $data['messages'][0]['content'] ?? '';
        $contenuTexte = is_string($contenu) ? $contenu : json_encode($contenu, JSON_UNESCAPED_UNICODE);

        if ($outil === 'habiller_monstres') {
            preg_match_all('/"monstre_id":\s*(\d+)/', $contenuTexte, $m);
            $ids = array_values(array_unique(array_map('intval', $m[1])));

            return Http::response(['stop_reason' => 'tool_use', 'content' => [[
                'type' => 'tool_use', 'name' => 'habiller_monstres',
                'input' => ['habillages' => array_map(fn ($id) => [
                    'monstre_id' => $id,
                    'nom' => NOM_HABILLE_TEST_RECITS,
                    'description' => 'Une silhouette tordue, née de la malédiction qui ronge la cité.',
                ], $ids)],
            ]]]);
        }

        if ($outil === 'ecrire_recits_quete') {
            $captures->salles = $contenuTexte;
            preg_match_all('/"salle":\s*(\d+)/', $contenuTexte, $m);
            $ids = array_values(array_unique(array_map('intval', $m[1])));

            return Http::response(['stop_reason' => 'tool_use', 'content' => [[
                'type' => 'tool_use', 'name' => 'ecrire_recits_quete',
                'input' => [
                    'salles' => array_map(fn ($id) => [
                        'salle' => $id,
                        'texte' => "Une chambre de pierre humide où résonne un lointain écho (salle {$id}).",
                        'entree' => '{heros} pousse la porte et découvre la salle.',
                        'ambiance' => 'mystere',
                    ], $ids),
                    'temps_forts' => tempsFortsCompletsPourJob(),
                ],
            ]]]);
        }

        return Http::response([], 200);
    });

    config()->set('services.anthropic.api_key', 'cle-test');
}

/** Les 3 temps forts que cette quête écrit encore, chacun avec des variantes valides. */
function tempsFortsCompletsPourJob(): array
{
    return array_map(fn ($cle) => [
        'cle' => $cle,
        'ambiance' => 'tension',
        'variantes' => [
            "Variante A du temps fort {$cle}, assez longue pour passer la validation du schéma.",
            "Variante B du temps fort {$cle}, différente et tout aussi détaillée que la première.",
            "Variante C du temps fort {$cle}, la dernière des trois exigées par le schéma.",
        ],
    ], TempsFort::GENERES_PAR_QUETE);
}

function demarrerQuetePourRecits(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    return [$alice, $groupe, $heros, $quete];
}

it('écrit un pack recits complet : une description par salle de la carte, citant les monstres HABILLÉS, et les 3 temps forts propres à la quête', function () {
    $captures = new stdClass();
    fakeChainePreGeneration($captures);

    [, , , $quete] = demarrerQuetePourRecits();
    $quete->refresh();

    $nbSalles = count((array) data_get($quete->carte->grille, 'salles', []));
    expect($nbSalles)->toBeGreaterThan(0);

    $recits = $quete->recits;
    expect($recits)->toBeArray()
        ->and($recits['salles'] ?? null)->toBeArray()
        ->and($recits['temps_forts'] ?? null)->toBeArray();

    // Contrat exact de CLAUDE.md : "salles": {"0": {"texte", "entree", "ambiance"}, ...}
    // — une entrée par salle, aucune salle oubliée ni inventée. (Les clés
    // repassent en int au décodage JSON — PHP ne distingue pas la clé
    // string "0" de l'int 0 — donc on compare normalisé sur les deux bords.)
    $idsAttendus = range(0, $nbSalles - 1);
    expect(collect(array_keys($recits['salles']))->map(fn ($k) => (int) $k)->sort()->values()->all())
        ->toBe(collect($idsAttendus)->sort()->values()->all());

    foreach ($recits['salles'] as $entree) {
        expect($entree['texte'])->toBeString()->not->toBeEmpty()
            ->and($entree['entree'])->toBeString()->not->toBeEmpty()
            ->and($entree['ambiance'])->toBeString()->not->toBeEmpty();
    }

    // Le pack "temps_forts" ne contient plus QUE les 3 clés que l'IA écrit
    // encore pour cette quête (TempsFort::GENERES_PAR_QUETE) — les 21 autres
    // viennent désormais uniquement de config/narration.php.
    expect(collect(array_keys($recits['temps_forts']))->sort()->values()->all())
        ->toBe(collect(TempsFort::GENERES_PAR_QUETE)->sort()->values()->all());

    // Le contexte envoyé à ecrire_recits_quete cite le nom HABILLÉ — preuve
    // que GenererRecitsQuete est bien chaîné APRÈS l'application de
    // l'habillage (et pas avant, ce qui aurait laissé passer le nom de catalogue).
    expect($captures->salles ?? null)->toContain(NOM_HABILLE_TEST_RECITS);

    // Lecteur du socle (app/Models/Quete.php) : recitSalle() sait relire ce
    // qui vient d'être écrit.
    expect($quete->recitSalle(0))->not->toBeNull();
});

it('sans LLM joignable, écrit quand même un pack (repli scripté) — la quête reste jouable', function () {
    config()->set('services.anthropic.api_key', null);
    Http::fake(['api.anthropic.com/*' => Http::response([], 500), '*' => Http::response([], 200)]);

    [, , , $quete] = demarrerQuetePourRecits();
    $quete->refresh();

    $nbSalles = count((array) data_get($quete->carte->grille, 'salles', []));
    $recits = $quete->recits;

    // Repli RecitsQuete::repli() : toujours une entrée par salle, texte tiré
    // de config/narration.php ; pas de phrase d'entrée générique (celle-là ne
    // sert que là où la voix enregistrée manque — BibliothequeNarration::salle()).
    expect($recits['salles'])->toHaveCount($nbSalles);
    foreach ($recits['salles'] as $entree) {
        expect($entree['texte'])->toBeIn(config('narration.repli.salle_decouverte.variantes'));
    }

    // ⚠ AUCUN temps fort en repli, et c'est voulu (CLAUDE.md, décision de
    // René 2026-08-18) : config/narration.php porte déjà les 24 clés, en
    // écrire ici ferait doublon avec BibliothequeNarration::pourQuete().
    expect($recits['temps_forts'])->toBe([]);

    // Le moteur retombe correctement sur ce pack pour la salle (les
    // descriptions de salle N'ONT PAS de repli au niveau du modèle — c'est
    // RecitsQuete::repli() qui les fournit dès l'écriture du pack) et sur
    // config/narration.php pour un temps fort (pack vide -> repli scripté,
    // même lecteur qu'en jeu réel).
    expect($quete->recitSalle(0))->not->toBeNull()
        ->and($quete->recitsTempsFort('quete_demarree'))->toBeEmpty();
});
