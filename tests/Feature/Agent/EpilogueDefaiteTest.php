<?php

declare(strict_types=1);

use App\Agent\Skills\ResumeCampagne;
use Illuminate\Support\Facades\Http;

/*
 * Épilogue de campagne (correctifs §5) : même sur un ÉCHEC final, le résumé doit
 * honorer les quêtes déjà remportées — une défaite au bout du chemin n'efface
 * pas les victoires. Sans clé LLM, le repli factuel est utilisé (jeu jouable
 * sans IA) ; il porte déjà le compte des victoires.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]); // → repli déterministe
});

it("crédite les quêtes gagnées dans l'épilogue même en cas d'échec", function () {
    $contexte = [
        'groupe' => ['nom' => 'Les Piliers de Karak', 'theme' => 'un donjon nain englouti', 'ton' => null],
        'cloture' => [
            'issue' => 'echec',
            'or_partage' => 120,
            'nb_quetes' => 3,
            'nb_quetes_terminees' => 2, // 2 victoires AVANT la chute finale
        ],
    ];

    $resume = app(ResumeCampagne::class)->generer($contexte)['resume'];

    expect($resume)->toContain('2 victorieuse')   // les victoires sont honorées…
        ->and($resume)->toContain('échec');        // …sans masquer l'issue réelle
});
