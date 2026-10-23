<?php

declare(strict_types=1);

use App\Partie\Narration\BibliothequeNarration;
use Illuminate\Support\Facades\Http;

// --- Répliques à variables : jamais d'audio pré-généré (2026-10-20) ---
// 73 des 184 répliques portent `{heros}`, `{or}`… : synthétisées telles quelles,
// la voix lisait les accolades, et `repli()` servait ce fichier pendant que le
// TEXTE, lui, était substitué. Elles coûtaient aussi 73 requêtes d'un quota de 100/jour.

it('reconnaît une réplique à variables', function () {
    expect(BibliothequeNarration::aDesVariables('{heros} trouve {or} pièces d\'or.'))->toBeTrue()
        ->and(BibliothequeNarration::aDesVariables('La porte grince.'))->toBeFalse();
});

it('narration:generer ne synthétise PAS les répliques à variables', function () {
    $public = sys_get_temp_dir().'/narration-'.uniqid();
    app()->usePublicPath($public);
    config([
        'services.gemini.api_key' => 'cle-test',
        'narration.lancement.variantes' => ['Que la quête commence.'],
        'narration.repli' => ['fouille_tresor' => ['variantes' => [
            '{heros} trouve {or} pièces d\'or.',
            'Un éclat doré au fond du coffre.',
        ]]],
    ]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['inlineData' => ['data' => base64_encode('PCMDATA')]]]]]],
    ])]);

    $this->artisan('narration:generer')
        ->expectsOutputToContain('à variables (lues en Web Speech) : 1')
        ->assertExitCode(0);

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), '{heros}'));
    expect(is_file("{$public}/audio/narration/fouille_tresor/0.wav"))->toBeFalse()
        ->and(is_file("{$public}/audio/narration/fouille_tresor/1.wav"))->toBeTrue();
});

it('repli() ne sert pas l\'audio d\'une réplique à variables, même laissé par une génération antérieure', function () {
    $public = sys_get_temp_dir().'/narration-'.uniqid();
    app()->usePublicPath($public);
    mkdir("{$public}/audio/narration/fouille_tresor", 0775, true);
    file_put_contents("{$public}/audio/narration/fouille_tresor/0.wav", 'ANCIEN');
    config(['narration.repli' => ['fouille_tresor' => ['variantes' => ['{heros} trouve {or} pièces d\'or.']]]]);

    $sortie = (new BibliothequeNarration)->pourQuete(null, 'fouille_tresor', ['heros' => 'Borin', 'or' => 50]);

    expect($sortie['texte'])->toBe('Borin trouve 50 pièces d\'or.')
        ->and($sortie['url'])->toBeNull();
});

it('repli() sert toujours l\'audio d\'une réplique sans variables', function () {
    $public = sys_get_temp_dir().'/narration-'.uniqid();
    app()->usePublicPath($public);
    mkdir("{$public}/audio/narration/porte_ouverte", 0775, true);
    file_put_contents("{$public}/audio/narration/porte_ouverte/0.wav", 'VOIX');
    file_put_contents("{$public}/audio/narration/porte_ouverte/0.sha1", sha1('La porte grince.'));
    config(['narration.repli' => ['porte_ouverte' => ['variantes' => ['La porte grince.']]]]);

    expect((new BibliothequeNarration)->repli('porte_ouverte')['url'])->toBe('/audio/narration/porte_ouverte/0.wav');
});
