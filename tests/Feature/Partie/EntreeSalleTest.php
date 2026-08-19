<?php

declare(strict_types=1);

use App\Models\Quete;
use App\Partie\Narration\BibliothequeNarration;

/**
 * La phrase d'ENTRÉE d'une salle ne doit jamais sortir non substituée.
 *
 * Une salle du pack porte deux textes : `texte`, figé, dont la voix du
 * narrateur peut être enregistrée à l'avance ; et `entree`, qui nomme
 * l'arrivant via `{heros}`. Mais toutes les révélations n'ont pas de
 * découvreur — une porte qui s'ouvre à la mort de son gardien n'en a aucun.
 *
 * Trouvé en partie réelle le 2026-08-19, à quatre joueurs : deux salles sur
 * trois se sont annoncées par « {heros} pénètre dans la salle voûtée ».
 * `revelerSalle()` ne recevait pas le héros, et la substitution laisse
 * volontairement intact ce qu'elle ne sait pas remplacer — c'est ce qui rend
 * le défaut VISIBLE plutôt que silencieux, mais il faut alors savoir se taire.
 */
function salleAvecEntree(): Quete
{
    $quete = new Quete;
    $quete->recits = ['salles' => ['4' => [
        'texte' => 'La salle voûtée suinte d’humidité, ses piliers rongés par le salpêtre.',
        'entree' => '{heros} pousse la porte et découvre la salle voûtée.',
        'ambiance' => 'mystere',
    ]]];

    return $quete;
}

it('nomme l’arrivant quand on sait qui entre', function () {
    $recit = app(BibliothequeNarration::class)->salle(salleAvecEntree(), 4, ['heros' => 'Torvald']);

    expect($recit['texte'])->toStartWith('Torvald pousse la porte')
        ->and($recit['texte'])->toContain('suinte d’humidité');
});

it('tait la phrase d’entrée quand aucun découvreur n’est connu', function () {
    $recit = app(BibliothequeNarration::class)->salle(salleAvecEntree(), 4);

    // Le texte figé SEUL — surtout pas la phrase d'entrée avec son {heros} nu.
    expect($recit['texte'])->toBe('La salle voûtée suinte d’humidité, ses piliers rongés par le salpêtre.');
});

/**
 * Le filet général : quelle que soit la combinaison de variables fournies,
 * aucun récit de salle ne doit jamais sortir avec une accolade non résolue.
 * C'est cette assertion-là qui aurait attrapé le défaut du 2026-08-19.
 */
it('ne laisse JAMAIS d’accolade non substituée dans un récit de salle', function () {
    $lib = app(BibliothequeNarration::class);

    foreach ([[], ['monstre' => 'Gobelin'], ['heros' => 'Torvald'], ['heros' => '']] as $remplacements) {
        $recit = $lib->salle(salleAvecEntree(), 4, $remplacements);

        expect($recit['texte'])->not->toMatch('/\{[a-z_]+\}/');
    }
});
