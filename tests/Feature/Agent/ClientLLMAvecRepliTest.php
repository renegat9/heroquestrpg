<?php

declare(strict_types=1);

use App\Agent\ClientLLM;
use App\Agent\ClientLLMAvecRepli;
use App\Agent\Exceptions\AppelLlmException;
use App\Agent\StatutIA;

/**
 * Teste {@see ClientLLMAvecRepli} EN ISOLATION (deux clients LLM fictifs,
 * aucune requête HTTP réelle) : succès direct, repli croisé vers le secours,
 * échec total des deux, et aucun secours configuré (comportement d'avant
 * l'introduction du décorateur, préservé).
 */

/**
 * Client LLM fictif : soit renvoie une réponse fixe, soit lève
 * AppelLlmException — au choix de l'appelant. Compte ses appels.
 */
function clientLlmFictif(bool $echoue, string $reponse = 'ok'): ClientLLM
{
    return new class($echoue, $reponse) implements ClientLLM
    {
        public int $appels = 0;

        public function __construct(
            private readonly bool $echoue,
            private readonly string $reponse,
        ) {}

        public function genererStructure(string $system, array $messages, array $outil, ?string $model = null): array
        {
            $this->appels++;
            if ($this->echoue) {
                throw new AppelLlmException("échec fictif ({$this->reponse})");
            }

            return ['texte' => $this->reponse];
        }

        public function genererTexte(string $system, array $messages, ?string $model = null): string
        {
            $this->appels++;
            if ($this->echoue) {
                throw new AppelLlmException("échec fictif ({$this->reponse})");
            }

            return $this->reponse;
        }

        public function modeleParDefaut(): string
        {
            return 'modele-fictif';
        }
    };
}

it('succès direct du principal : résultat renvoyé, statut nominal', function () {
    $principal = clientLlmFictif(echoue: false, reponse: 'réponse principale');
    $decorateur = new ClientLLMAvecRepli($principal, 'principal-test');

    $resultat = $decorateur->genererTexte('s', [['role' => 'user', 'content' => 'u']]);

    expect($resultat)->toBe('réponse principale')
        ->and($principal->appels)->toBe(1);

    $statut = StatutIA::actuel();
    expect($statut['etat'])->toBe('nominal')
        ->and($statut['fournisseur'])->toBe('principal-test');
});

it('principal en échec, secours répond : résultat du secours renvoyé, statut repli', function () {
    $principal = clientLlmFictif(echoue: true, reponse: 'panne principale');
    $secours = clientLlmFictif(echoue: false, reponse: 'réponse secours');
    $decorateur = new ClientLLMAvecRepli($principal, 'principal-test', $secours, 'secours-test');

    $resultat = $decorateur->genererTexte('s', [['role' => 'user', 'content' => 'u']]);

    expect($resultat)->toBe('réponse secours')
        ->and($principal->appels)->toBe(1)
        ->and($secours->appels)->toBe(1);

    $statut = StatutIA::actuel();
    expect($statut['etat'])->toBe('repli')
        ->and($statut['fournisseur'])->toBe('secours-test')
        ->and($statut['depuis'])->toBe('principal-test');
});

it('les deux échouent : l\'exception du SECOURS remonte (Skill::generer bascule sur son repli), statut indisponible', function () {
    $principal = clientLlmFictif(echoue: true, reponse: 'panne principale');
    $secours = clientLlmFictif(echoue: true, reponse: 'panne secours');
    $decorateur = new ClientLLMAvecRepli($principal, 'principal-test', $secours, 'secours-test');

    $exception = null;
    try {
        $decorateur->genererTexte('s', [['role' => 'user', 'content' => 'u']]);
    } catch (AppelLlmException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and($exception->getMessage())->toContain('panne secours') // celle du SECOURS, pas du principal
        ->and($principal->appels)->toBe(1)
        ->and($secours->appels)->toBe(1);

    $statut = StatutIA::actuel();
    expect($statut['etat'])->toBe('indisponible')
        ->and($statut['tentatives'])->toBe('principal-test+secours-test');
});

it('pas de secours configuré : l\'exception du principal remonte directement (comportement actuel)', function () {
    $principal = clientLlmFictif(echoue: true, reponse: 'panne principale');
    $decorateur = new ClientLLMAvecRepli($principal, 'principal-test');

    $exception = null;
    try {
        $decorateur->genererTexte('s', [['role' => 'user', 'content' => 'u']]);
    } catch (AppelLlmException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and($exception->getMessage())->toContain('panne principale')
        ->and($principal->appels)->toBe(1);

    $statut = StatutIA::actuel();
    expect($statut['etat'])->toBe('indisponible')
        ->and($statut['tentatives'])->toBe('principal-test');
});

it('modeleParDefaut() renvoie celui du principal sans déclencher d\'appel', function () {
    $principal = clientLlmFictif(echoue: false, reponse: 'x');
    $decorateur = new ClientLLMAvecRepli($principal, 'principal-test');

    expect($decorateur->modeleParDefaut())->toBe('modele-fictif')
        ->and($principal->appels)->toBe(0);
});

it('genererStructure() suit le même chemin de repli que genererTexte()', function () {
    $principal = clientLlmFictif(echoue: true, reponse: 'panne');
    $secours = clientLlmFictif(echoue: false, reponse: 'ignoré');
    $decorateur = new ClientLLMAvecRepli($principal, 'principal-test', $secours, 'secours-test');

    $sortie = $decorateur->genererStructure('s', [['role' => 'user', 'content' => 'u']], [
        'name' => 'outil', 'description' => 'd', 'input_schema' => ['type' => 'object'],
    ]);

    expect($sortie)->toBe(['texte' => 'ignoré'])
        ->and(StatutIA::actuel()['etat'])->toBe('repli');
});
