<?php

declare(strict_types=1);

use App\Agent\Audio\TtsGemini;
use App\Agent\Skills\Narration;
use App\Events\NarrationDiffusee;
use App\Jobs\GenererBarksBoss;
use App\Models\Parametre;
use App\Partie\Audio\BanqueBarks;
use App\Partie\Narration\BibliothequeNarration;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]); // force le repli scripté
});

it('fournit une réplique de cérémonie de lancement (variante du catalogue)', function () {
    $c = (new BibliothequeNarration)->lancement();

    expect($c['cle'])->toBe('lancement')
        ->and($c['texte'])->toBeIn(config('narration.lancement.variantes'))
        ->and($c['ambiance'])->toBe('epique')
        // url : null si l'asset n'est pas généré, sinon chemin de la voix narrateur
        ->and($c['url'] === null || str_starts_with($c['url'], '/audio/narration/lancement/'))->toBeTrue();
});

it('fournit une réplique de repli par temps fort, null si la clé est inconnue', function () {
    $lib = new BibliothequeNarration;

    expect($lib->repli('quete_demarree')['texte'])->toBeIn(config('narration.repli.quete_demarree.variantes'))
        ->and($lib->repli('victoire_quete')['ambiance'])->toBe('victoire')
        ->and($lib->repli('salle_decouverte')['texte'])->toBeIn(config('narration.repli.salle_decouverte.variantes'))
        ->and($lib->repli('piege_declenche')['texte'])->toBeIn(config('narration.repli.piege_declenche.variantes'))
        ->and($lib->repli('cle_bidon'))->toBeNull();
});

it('la narration de repli (sans LLM) renvoie une variante scriptée selon le résultat moteur', function () {
    $sortie = app(Narration::class)->generer(['resultat_moteur' => ['type' => 'quete_demarree']]);

    expect($sortie['texte'])->toBeIn(config('narration.repli.quete_demarree.variantes'));

    $combat = app(Narration::class)->generer([
        'resultat_moteur' => ['type' => 'attaque', 'degats' => 2, 'cible_vaincue' => true],
    ]);
    expect($combat['texte'])->toBeIn(config('narration.repli.attaque_mort.variantes'));
});

it('diffuse la cérémonie de lancement au démarrage de quête', function () {
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    Event::fake([NarrationDiffusee::class]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    Event::assertDispatched(NarrationDiffusee::class, fn (NarrationDiffusee $e) =>
        in_array($e->texte, config('narration.lancement.variantes'), true)
        && $e->groupe->id === $groupe->id);
});

it('la commande narration:generer sans GEMINI_API_KEY ne génère rien et l\'explique', function () {
    config(['services.gemini.api_key' => null]);

    $this->artisan('narration:generer')
        ->expectsOutputToContain('Web Speech')
        ->assertExitCode(0);
});

// --- Bascule « synthèse vocale IA en cours de partie » (panneau Réglages) --

it('n\'appelle pas Gemini TTS quand la synthèse vocale IA dynamique est désactivée', function () {
    config(['services.gemini.api_key' => 'cle-test']);
    Parametre::actuel()->update(['voix_dynamique_active' => false]);

    $url = (new BibliothequeNarration)->voixDynamique('Texte désactivé '.uniqid(), app(TtsGemini::class));

    expect($url)->toBeNull();
    Http::assertNothingSent();
});

// Note : ce fichier fake le HTTP globalement dans son beforeEach (bascule
// hors bascule désactivée, `Http::fake()` sans argument). Un `Http::fake([...])`
// PLUS SPÉCIFIQUE posé ensuite dans un test s'AJOUTE à ce stub générique sans
// le remplacer (Illuminate\Http\Client\Factory::fake() FUSIONNE toujours) ;
// comme le stub générique du beforeEach répond à TOUTE requête et a été
// enregistré EN PREMIER, c'est TOUJOURS lui qui répond (résolution au premier
// stub qui matche). On vérifie donc l'APPEL (Http::assertSent, sur la requête
// réellement envoyée — indépendant de la réponse retournée), pas la valeur de
// retour qui dépendrait d'une réponse applicative réaliste.
it('tente l\'appel Gemini TTS par défaut (bascule non désactivée)', function () {
    config(['services.gemini.api_key' => 'cle-test']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['inlineData' => ['data' => base64_encode('PCMDATA')]]]]]],
    ])]);

    (new BibliothequeNarration)->voixDynamique('Texte dynamique par défaut '.uniqid(), app(TtsGemini::class));

    Http::assertSent(fn ($r) => str_contains($r->url(), 'generateContent'));
});

it('envoie la voix narrateur surchargée (Parametre::narration_voix) à Gemini TTS', function () {
    config(['services.gemini.api_key' => 'cle-test']);
    Parametre::actuel()->update(['narration_voix' => 'Puck']);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['inlineData' => ['data' => base64_encode('PCMDATA')]]]]]],
    ])]);

    (new BibliothequeNarration)->voixDynamique('Texte voix surchargée '.uniqid(), app(TtsGemini::class));

    // Chemin JSON vérifié dans TtsGemini.php:66-69.
    Http::assertSent(fn ($r) => $r['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Puck');
});

it('GenererBarksBoss n\'appelle aucun TTS quand la synthèse vocale IA dynamique est désactivée', function () {
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);

    // Démarre une quête et bascule le premier monstre en boss nommé
    // (Seigneur, tier 'boss') — pendant tout ce setup, aucune clé Gemini
    // n'est configurée : aucun appel TTS ne peut fuiter dans les assertions.
    ['quete' => $quete] = demarrerQueteAvecMonstre('Seigneur');

    config(['services.gemini.api_key' => 'cle-test']);
    Parametre::actuel()->update(['voix_dynamique_active' => false]);
    Http::fake(); // aucune requête ne doit partir

    (new GenererBarksBoss($quete->id))->handle(app(TtsGemini::class), app(BanqueBarks::class));

    Http::assertNothingSent();
});
