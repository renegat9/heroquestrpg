<?php

declare(strict_types=1);

use App\Jobs\GenererImagesQuete;
use App\Jobs\HabillerMonstres;
use App\Models\Monstre;
use App\Partie\EtatGroupe;
use App\Partie\Images\BibliothequeImages;
use App\Partie\MoteurDegats;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * LE PORTRAIT DE BOSS D'UNE QUÊTE MONTRE LE BON MONSTRE (René, 2026-11-05 : « Corrige les prompts des boss et assure toi
 * que leur tokens est représentatif du bon monstre »).
 *
 *  1. Le prompt décrit l'APPARENCE du livret (`config/apparences_monstres.php`), jamais un nom : le nom d'habillage
 *     (« Le Cœur-Liane Éveillé ») faisait dessiner un démon cornu, et Gemini l'écrivait dans l'image.
 *  2. Le portrait se range PAR FORME : après une mue, la nouvelle forme ne montre plus le portrait de l'ancienne. Pour
 *     un boss sans jeton de livret, ce portrait EST son jeton sur la table (`DungeonGrid` : `symbole_url ?? img`).
 *  3. L'habillage IA reçoit l'apparence, pour que sa description (l'ambiance du portrait) garde la même créature.
 *  4. Les jetons de Borokk et Gragor : la figure du sorcier du Dread, que leur livret désigne.
 */

beforeEach(function () {
    // UN seul faux HTTP, posé avant tout appel : un second `Http::fake()` ajoute ses réponses APRÈS celles du premier, et
    // un premier fourre-tout masquerait Gemini. Gemini rend une image ; le reste, une réponse vide (repli).
    Http::fake(fn (Request $r) => str_contains($r->url(), 'generativelanguage')
        ? Http::response(['candidates' => [['content' => ['parts' => [
            ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNGBYTES')]],
        ]]]]])
        : Http::response([], 200));
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MobilierSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

afterEach(function () {
    $racine = $GLOBALS['portraits_public'] ?? null;

    if ($racine !== null && is_dir($racine)) {
        exec('rm -rf '.escapeshellarg($racine));
    }
});

/** `public_path()` détourné vers un dossier jetable : un test qui écrit dans l'arbre de travail peut le détruire. */
function portraitsPublicJetable(): string
{
    $dossier = sys_get_temp_dir().'/hq-portraits-'.bin2hex(random_bytes(4));
    mkdir($dossier.'/images', 0775, true);
    app()->usePublicPath($dossier);
    $GLOBALS['portraits_public'] = $dossier;

    return $dossier;
}

/** Gemini « configuré » : le faux HTTP du `beforeEach` lui rend une image ; les prompts se lisent par `portraitsPromptsEnvoyes()`. */
function portraitsGeminiFactice(): void
{
    config(['services.gemini.api_key' => 'cle-test', 'services.gemini.model_image' => 'gemini-2.5-flash-image']);
}

/** @return list<string> */
function portraitsPromptsEnvoyes(): array
{
    return Http::recorded()
        ->map(fn (array $paire) => $paire[0])
        ->filter(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))
        ->map(fn (Request $r) => (string) ($r['contents'][0]['parts'][0]['text'] ?? ''))
        ->values()->all();
}

it('le prompt du portrait de boss décrit l\'APPARENCE du livret, jamais un nom, et interdit toute inscription', function () {
    $biblio = app(BibliothequeImages::class);

    $prompt = $biblio->promptBoss(
        'Gretzl la Porte-Fléau',
        'boss',
        'Le Cœur-Liane Éveillé se dresse, couvert de lianes qui suintent. Gretzl la Porte-Fléau ricane.',
        ['Le Cœur-Liane Éveillé'],
    );

    expect($prompt)->toContain($biblio->apparenceMonstre('Gretzl la Porte-Fléau'))
        ->and($prompt)->toContain('aucune inscription')
        ->and($prompt)->toContain('couvert de lianes qui suintent')
        ->and($prompt)->not->toContain('Gretzl')
        ->and($prompt)->not->toContain('Cœur-Liane');

    // Sans habillage : l'apparence seule, sans phrase d'ambiance vide.
    expect($biblio->promptBoss('Venim le Dragon', 'boss', null))
        ->toContain('un dragon')
        ->not->toContain('ambiance')
        ->not->toContain('Venim');
});

it('EN JEU — le portrait est rangé PAR FORME : après la mue de Gretzl, Demonspider ne montre plus le portrait de Gretzl', function () {
    portraitsPublicJetable();

    $ctx = demarrerQueteAvecMonstre('Gretzl la Porte-Fléau');
    ['groupe' => $groupe, 'quete' => $quete, 'instance' => $gretzl] = $ctx;
    $gretzl->update([
        'habillage' => ['nom' => 'Le Cœur-Liane Éveillé', 'description' => 'Le Cœur-Liane Éveillé suinte une sève noire.'],
        'pv_body' => 1,
        'capacites_reactives_utilisees' => ['ignore_degats_attaque'],
    ]);

    portraitsGeminiFactice();
    app()->call([new GenererImagesQuete((int) $groupe->id, (int) $quete->id, 'boss'), 'handle']);

    $biblio = app(BibliothequeImages::class);
    $idGretzl = (int) Monstre::where('nom_base', 'Gretzl la Porte-Fléau')->value('id');
    $portrait = $biblio->cheminDyn('monstre', $biblio->cleDynMonstre((int) $gretzl->id, $idGretzl));

    // Le prompt envoyé : l'apparence, ni le nom d'habillage ni celui du catalogue.
    $prompts = portraitsPromptsEnvoyes();
    expect(collect($prompts)->contains(fn (string $p) => str_contains($p, $biblio->apparenceMonstre('Gretzl la Porte-Fléau'))))->toBeTrue()
        ->and(collect($prompts)->contains(fn (string $p) => str_contains($p, 'Gretzl') || str_contains($p, 'Cœur-Liane')))->toBeFalse();

    // Écrit sous la clé de la forme, et servi pour elle (état publié à la table).
    $imageDe = fn () => collect(app(EtatGroupe::class)->payload($groupe->fresh())['entites'] ?? [])
        ->first(fn (array $e) => ($e['type'] ?? null) === 'monstre' && (int) $e['id'] === (int) $gretzl->id)['image_url'] ?? null;

    expect(is_file($portrait['absolu']))->toBeTrue()
        ->and($imageDe())->toBe($portrait['url']);

    // La mue, par le point de passage de la mort : Gretzl devient Demonspider.
    app(MoteurDegats::class)->infligerAMonstre($gretzl->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($gretzl->fresh()->load('monstre')->monstre->nom_base)->toBe('Demonspider');

    // L'ancien portrait est toujours sur le disque, mais Demonspider ne le montre plus : l'image de SON catalogue.
    expect(is_file($portrait['absolu']))->toBeTrue()
        ->and($imageDe())->not->toBe($portrait['url'])
        ->and($imageDe())->toBe($biblio->urlMonstreCatalogue((int) $gretzl->fresh()->monstre_id, 'Demonspider'));
});

it('effacer le portrait d\'une instance emporte TOUTES ses formes, et jamais celles d\'une autre instance', function () {
    $racine = portraitsPublicJetable().'/images/dyn/monstre';
    mkdir($racine, 0775, true);

    foreach (['12.png', '12-5.png', '12-5.webp', '12-9.png', '123-5.png', '123.png'] as $fichier) {
        file_put_contents("{$racine}/{$fichier}", 'x');
    }

    expect(app(BibliothequeImages::class)->supprimerDyn('monstre', 12))->toBeTrue();

    $restants = collect(scandir($racine))->reject(fn (string $f) => in_array($f, ['.', '..'], true))->sort()->values()->all();
    expect($restants)->toBe(['123-5.png', '123.png']);
});

it('l\'habillage IA reçoit l\'APPARENCE de chaque monstre, pour que sa description garde la même créature', function () {
    $ctx = demarrerQueteAvecMonstre('Gretzl la Porte-Fléau');

    config(['services.anthropic.api_key' => 'cle-test']);
    app()->call([new HabillerMonstres((int) $ctx['groupe']->id, (int) $ctx['quete']->id), 'handle']);

    $contenus = Http::recorded()
        ->map(fn (array $paire) => $paire[0])
        ->filter(fn (Request $r) => ($r->data()['tool_choice']['name'] ?? null) === 'habiller_monstres')
        ->map(fn (Request $r) => json_encode($r->data()['messages'] ?? [], JSON_UNESCAPED_UNICODE))
        ->implode(' ');

    $attendue = app(BibliothequeImages::class)->apparenceMonstre('Gretzl la Porte-Fléau');
    expect($contenus)->toContain('apparence')
        ->and($contenus)->toContain($attendue);
});

it('les jetons de Borokk et Gragor sont ceux du SORCIER du Dread que leur livret désigne (F4543 p. 10 et 13)', function () {
    $biblio = app(BibliothequeImages::class);

    // « Place Borokk, an evil Dread sorcerer » (p. 13) ; « Use the Dread sorcerer figure to represent Gragor » (p. 10).
    // Leurs STATS sont celles d'un Dread warrior : c'est ce qui avait fait poser le jeton du guerrier.
    foreach (['Borokk', 'Gragor'] as $nom) {
        expect(config('symboles_monstres.cles')[$nom])->toBe('dread-sorcerer', $nom)
            ->and($biblio->urlSymboleMonstre($nom))->toContain('/dread-sorcerer.');
    }
});
