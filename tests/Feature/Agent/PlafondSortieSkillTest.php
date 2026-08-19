<?php

declare(strict_types=1);

use App\Agent\AnthropicClient;
use App\Agent\GeminiClient;
use App\Agent\Skills\HabillageMonstres;
use App\Agent\Skills\RecitsQuete;
use App\Agent\Skills\ResumeCampagne;
use App\Agent\Skills\Skill;
use App\Agent\Skills\SqueletteCampagne;
use Illuminate\Support\Facades\Http;

/**
 * Plafond de sortie PAR SKILL (`services.llm.max_tokens_par_skill`).
 *
 * Le besoin de sortie varie d'un ordre de grandeur entre habiller un monstre
 * (deux phrases) et pré-générer les récits d'une quête (8-12 descriptions de
 * salles d'un seul appel), alors qu'un plafond unique de 4096 valait pour tout.
 *
 * ⚠ Ce qu'on protège n'est pas le coût mais l'INTÉGRITÉ : un plafond atteint
 * ne raccourcit pas le texte, il le coupe au milieu — et un `tool_use` tronqué
 * est un JSON invalide, donc la génération entière qui part au repli. Un pack
 * de récits perdu ne se voit nulle part : la quête se joue simplement avec les
 * textes scriptés, sans une erreur.
 */
it('n’affecte un plafond qu’à des skills qui existent vraiment', function () {
    $outils = collect([
        RecitsQuete::class, SqueletteCampagne::class,
        HabillageMonstres::class, ResumeCampagne::class,
    ])->map(fn (string $classe) => app($classe)->nomOutil())->all();

    $configures = array_keys((array) config('services.llm.max_tokens_par_skill'));

    // Une clé sans skill correspondant serait un réglage DÉCORATIF : personne
    // ne le lirait jamais, et il survivrait au renommage du skill sans bruit.
    expect(array_diff($configures, $outils))->toBe([], 'plafonds réglés pour des skills inexistants');
});

it('transmet le plafond du skill dans la requête Anthropic', function () {
    config()->set('services.anthropic.api_key', 'test');
    config()->set('services.llm.max_tokens_par_skill.ecrire_recits_quete', 8192);

    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'tool_use', 'name' => 'ecrire_recits_quete', 'input' => ['salles' => []]]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ])]);

    plafondAppelDepuis(RecitsQuete::class, AnthropicClient::class);

    Http::assertSent(fn ($r) => $r->data()['max_tokens'] === 8192);
});

it('transmet le plafond du skill dans le generationConfig Gemini', function () {
    config()->set('services.gemini.api_key', 'test');
    config()->set('services.llm.max_tokens_par_skill.ecrire_recits_quete', 8192);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [[
            'functionCall' => ['name' => 'ecrire_recits_quete', 'args' => ['salles' => []]],
        ]]]]],
    ])]);

    plafondAppelDepuis(RecitsQuete::class, GeminiClient::class);

    Http::assertSent(fn ($r) => ($r->data()['generationConfig']['maxOutputTokens'] ?? null) === 8192);
});

/**
 * Joue un `generer()` sur un client PRÉCIS (jamais le décorateur de repli, qui
 * rejouerait l'appel chez l'autre fournisseur et brouillerait l'assertion).
 * La sortie renvoyée par le faux est volontairement invalide : seul le corps
 * de la requête ENVOYÉE nous intéresse, le repli du skill absorbe le reste.
 */
function plafondAppelDepuis(string $skill, string $client): void
{
    $instance = new $skill(app($client), app(App\Agent\ValidationSortie::class));

    expect($instance)->toBeInstanceOf(Skill::class);

    try {
        $instance->generer(['groupe' => ['nom' => 'T', 'theme' => 'T'], 'salles_a_decrire' => []]);
    } catch (Throwable) {
        // sortie non conforme → repli ou exception : sans importance ici.
    }
}
