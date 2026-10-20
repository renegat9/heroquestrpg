<?php

declare(strict_types=1);

use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Partie\Audio\BanqueBarks;
use App\Partie\Narration\BibliothequeNarration;
use Illuminate\Support\Facades\Http;

// Un fichier audio pré-généré n'était relié à sa réplique QUE par son rang :
// retoucher une variante laissait l'ancien fichier jouer l'ancienne phrase sur le
// nouveau texte (2026-10-19, `reussite_mixte` qui disait « réussi »). Chaque
// `{i}.wav` porte désormais `{i}.sha1`, l'empreinte du texte qu'il prononce.

function banqueTemporaire(): string
{
    $public = sys_get_temp_dir().'/banque-audio-'.uniqid();
    app()->usePublicPath($public);

    return $public;
}

function deposer(string $public, string $relatif, string $contenu, ?string $texte = null): void
{
    $wav = "{$public}/audio/{$relatif}";
    @mkdir(dirname($wav), 0775, true);
    file_put_contents($wav, $contenu);

    if ($texte !== null) {
        file_put_contents(preg_replace('/\.wav$/', '.sha1', $wav), sha1($texte));
    }
}

function fauxTts(): void
{
    config(['services.gemini.api_key' => 'cle-test']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['inlineData' => ['data' => base64_encode('NOUVEAU')]]]]]],
    ])]);
}

describe('au runtime, un fichier n\'est joué que s\'il prononce le texte affiché', function () {
    it('refuse le fichier d\'une version antérieure de la réplique', function () {
        $public = banqueTemporaire();
        deposer($public, 'narration/porte_ouverte/0.wav', 'ANCIEN', 'La porte grince.');
        config(['narration.repli' => ['porte_ouverte' => ['variantes' => ['La porte cède dans un fracas.']]]]);

        expect((new BibliothequeNarration)->repli('porte_ouverte')['url'])->toBeNull();
    });

    it('refuse un fichier sans empreinte : rien ne dit ce qu\'il prononce', function () {
        $public = banqueTemporaire();
        deposer($public, 'narration/porte_ouverte/0.wav', 'HERITE');
        config(['narration.repli' => ['porte_ouverte' => ['variantes' => ['La porte grince.']]]]);

        expect((new BibliothequeNarration)->repli('porte_ouverte')['url'])->toBeNull();
    });

    it('vaut aussi pour les barks d\'archétype', function () {
        $public = banqueTemporaire();
        deposer($public, 'barks/brute/touche/0.wav', 'ANCIEN', 'Grrr !');
        config(['barks.archetypes' => ['Bestiole' => 'brute'], 'barks.lignes.brute.touche' => ['Aïe, misérable !']]);

        $instance = new InstanceMonstre(['quete_id' => 0]);
        $instance->setRelation('monstre', new Monstre(['nom_base' => 'Bestiole']));

        expect((new BanqueBarks)->pourInstance($instance, 'touche')['url'])->toBeNull();
    });
});

describe('narration:generer met la banque en accord avec config/narration.php', function () {
    beforeEach(function () {
        $this->public = banqueTemporaire();
        fauxTts();
        config(['narration.lancement.variantes' => []]);
    });

    it('régénère une réplique dont le texte a changé, sans --force', function () {
        deposer($this->public, 'narration/porte_ouverte/0.wav', 'ANCIEN', 'La porte grince.');
        config(['narration.repli' => ['porte_ouverte' => ['variantes' => ['La porte cède dans un fracas.']]]]);

        $this->artisan('narration:generer')->assertExitCode(0);

        expect(file_get_contents("{$this->public}/audio/narration/porte_ouverte/0.wav"))->toEndWith('NOUVEAU')
            ->and((new BibliothequeNarration)->repli('porte_ouverte')['url'])->toBe('/audio/narration/porte_ouverte/0.wav');
    });

    it('laisse intact, sans requête, un fichier à jour', function () {
        deposer($this->public, 'narration/porte_ouverte/0.wav', 'VOIX', 'La porte grince.');
        config(['narration.repli' => ['porte_ouverte' => ['variantes' => ['La porte grince.']]]]);

        $this->artisan('narration:generer')->expectsOutputToContain('à jour : 1')->assertExitCode(0);

        Http::assertNothingSent();
        expect(file_get_contents("{$this->public}/audio/narration/porte_ouverte/0.wav"))->toBe('VOIX');
    });

    it('ADOPTE un fichier d\'avant les empreintes, sans requête TTS', function () {
        deposer($this->public, 'narration/porte_ouverte/0.wav', 'HERITE');
        config(['narration.repli' => ['porte_ouverte' => ['variantes' => ['La porte grince.']]]]);

        $this->artisan('narration:generer')->expectsOutputToContain('adoptés : 1')->assertExitCode(0);

        Http::assertNothingSent();
        expect((new BibliothequeNarration)->repli('porte_ouverte')['url'])->toBe('/audio/narration/porte_ouverte/0.wav');
    });

    it('supprime le fichier d\'une réplique à variables et celui d\'un rang retiré', function () {
        deposer($this->public, 'narration/fouille_tresor/0.wav', 'ACCOLADES');
        deposer($this->public, 'narration/fouille_tresor/1.wav', 'VOIX', 'Un éclat doré.');
        deposer($this->public, 'narration/fouille_tresor/2.wav', 'RETIREE', 'Une réplique retirée.');
        config(['narration.repli' => ['fouille_tresor' => ['variantes' => [
            '{heros} trouve {or} pièces d\'or.',
            'Un éclat doré.',
        ]]]]);

        $this->artisan('narration:generer')->expectsOutputToContain('supprimés : 2')->assertExitCode(0);

        $dossier = "{$this->public}/audio/narration/fouille_tresor";
        expect(is_file("{$dossier}/0.wav"))->toBeFalse()
            ->and(is_file("{$dossier}/1.wav"))->toBeTrue()
            ->and(is_file("{$dossier}/2.wav"))->toBeFalse()
            ->and(is_file("{$dossier}/2.sha1"))->toBeFalse();
    });

    it('ne touche à rien hors des dossiers de répliques déclarées (voix testées, narration dynamique)', function () {
        deposer($this->public, 'narration/test/Puck.wav', 'TEST');
        deposer($this->public, 'narration/dyn/abc.wav', 'DYN');
        config(['narration.repli' => []]);

        $this->artisan('narration:generer')->assertExitCode(0);

        expect(is_file("{$this->public}/audio/narration/test/Puck.wav"))->toBeTrue()
            ->and(is_file("{$this->public}/audio/narration/dyn/abc.wav"))->toBeTrue();
    });
});

it('barks:generer régénère un bark retouché avec la voix de son profil', function () {
    $public = banqueTemporaire();
    fauxTts();
    deposer($public, 'barks/brute/touche/0.wav', 'ANCIEN', 'Grrr !');
    config([
        'barks.profils' => ['brute' => ['voix' => 'Fenrir', 'style' => 'une voix de brute'], 'defaut' => ['voix' => 'Puck']],
        'barks.lignes' => ['brute' => ['touche' => ['Aïe, misérable !']]],
    ]);

    $this->artisan('barks:generer')->assertExitCode(0);

    expect(file_get_contents("{$public}/audio/barks/brute/touche/0.wav"))->toEndWith('NOUVEAU');
    Http::assertSent(fn ($r) => $r['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Fenrir');
});
