<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Exceptions\AppelLlmException;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Décorateur de repli automatique inter-fournisseurs : enveloppe le client LLM
 * PRINCIPAL (choisi par `AppServiceProvider`) et retente une fois avec le
 * client de SECOURS (l'AUTRE fournisseur, s'il a une clé) avant d'abandonner.
 * Implémente la même interface {@see ClientLLM} : `Skill::generer()` ne
 * change pas d'une ligne — son unique appel `genererStructure()`/
 * `genererTexte()` tente désormais principal-puis-secours en interne, et ne
 * relance `AppelLlmException` que si LES DEUX ont échoué — exactement le
 * signal que `Skill::generer()` sait déjà traiter (bascule sur son
 * `repli()` codé en dur).
 *
 * Alimente {@see StatutIA} à chaque tentative (succès / repli / échec total),
 * pour affichage dans le panneau Réglages (bandeau de statut IA).
 */
final class ClientLLMAvecRepli implements ClientLLM
{
    public function __construct(
        private readonly ClientLLM $principal,
        private readonly string $principalNom,
        private readonly ?ClientLLM $secours = null,
        private readonly ?string $secoursNom = null,
    ) {}

    public function genererStructure(string $system, array $messages, array $outil, ?string $model = null): array
    {
        return $this->avecRepli(fn (ClientLLM $c) => $c->genererStructure($system, $messages, $outil, $model));
    }

    public function genererTexte(string $system, array $messages, ?string $model = null): string
    {
        return $this->avecRepli(fn (ClientLLM $c) => $c->genererTexte($system, $messages, $model));
    }

    public function modeleParDefaut(): string
    {
        return $this->principal->modeleParDefaut(); // affiché avant tout appel réel
    }

    private function avecRepli(Closure $appel): mixed
    {
        try {
            $resultat = $appel($this->principal);
            StatutIA::signalerSucces($this->principalNom);

            return $resultat;
        } catch (AppelLlmException $e) {
            Log::warning("MJ IA [{$this->principalNom}] indisponible, repli tenté.", ['erreur' => $e->getMessage()]);

            if ($this->secours === null) {
                StatutIA::signalerEchecTotal($this->principalNom, $e->getMessage());

                throw $e;
            }

            try {
                $resultat = $appel($this->secours);
                StatutIA::signalerRepli($this->principalNom, $this->secoursNom, $e->getMessage());

                return $resultat;
            } catch (AppelLlmException $e2) {
                StatutIA::signalerEchecTotal("{$this->principalNom}+{$this->secoursNom}", $e2->getMessage());

                throw $e2; // Skill::generer() bascule sur repli() codé en dur — inchangé
            }
        }
    }
}
