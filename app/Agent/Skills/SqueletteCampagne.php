<?php

declare(strict_types=1);

namespace App\Agent\Skills;

/**
 * Skill « squelette de campagne » (doc 06 §2, génération en deux temps, Q10).
 *
 * Appelé UNE FOIS à la création du groupe : produit un squelette léger —
 * prémisse, grande menace / boss final, jalons (sous-boss) espacés selon la
 * longueur, quelques fils narratifs. Persisté dans groupes.plan_campagne
 * (socle stable, doc 07 §2) et amorcé dans la bible.
 *
 * Pas de repli générique : sans squelette la campagne ne peut pas démarrer,
 * l'exception remonte au job (retry par la file).
 */
class SqueletteCampagne extends Skill
{
    public const SCHEMA = [
        'type' => 'object',
        'required' => ['premisse', 'menace', 'jalons', 'fils_narratifs'],
        'properties' => [
            'premisse' => [
                'type' => 'string',
                'minLength' => 40,
                'description' => 'Prémisse de la campagne, 2-4 phrases : situation initiale et appel à l\'aventure.',
            ],
            'menace' => [
                'type' => 'object',
                'required' => ['nom', 'description'],
                'properties' => [
                    'nom' => ['type' => 'string', 'minLength' => 2],
                    'description' => [
                        'type' => 'string',
                        'minLength' => 20,
                        'description' => 'La grande menace / le boss final de la campagne (narratif uniquement, AUCUNE stat).',
                    ],
                ],
            ],
            'jalons' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 6,
                'description' => 'Jalons de l\'arc : sous-boss intermédiaires + exactement un boss_final à la dernière quête.',
                'items' => [
                    'type' => 'object',
                    'required' => ['position', 'type', 'titre', 'description'],
                    'properties' => [
                        'position' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'description' => 'Numéro de quête (1..nb_quetes_total) où tombe ce jalon.',
                        ],
                        'type' => ['type' => 'string', 'enum' => ['sous_boss', 'boss_final']],
                        'titre' => ['type' => 'string', 'minLength' => 2],
                        'description' => ['type' => 'string', 'minLength' => 10],
                    ],
                ],
            ],
            'fils_narratifs' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 4,
                'description' => 'Fils rouges à tisser au fil des quêtes (mystères, PNJ récurrents, promesses).',
                'items' => [
                    'type' => 'object',
                    'required' => ['titre', 'description'],
                    'properties' => [
                        'titre' => ['type' => 'string', 'minLength' => 2],
                        'description' => ['type' => 'string', 'minLength' => 10],
                    ],
                ],
            ],
        ],
    ];

    public function nomOutil(): string
    {
        return 'proposer_squelette_campagne';
    }

    public function descriptionOutil(): string
    {
        return 'Propose le squelette léger de la campagne : prémisse, grande menace (boss final), '
            .'jalons de sous-boss espacés sur l\'arc, et fils narratifs. Narratif uniquement.';
    }

    protected function prompt(array $contexte): array
    {
        $nbQuetes = (int) ($contexte['groupe']['nb_quetes_total'] ?? 1);
        $nbSousBoss = $this->nbSousBossAttendu($nbQuetes);

        $system = $this->consignesCommunes($contexte)."\n\n".<<<TXT
        Tâche : générer le SQUELETTE de la campagne (génération en deux temps — ici
        seulement le fil rouge léger ; le détail de chaque quête sera généré plus tard).
        Contraintes structurelles (vérifiées par le moteur) :
        - La campagne compte exactement {$nbQuetes} quête(s).
        - Exactement UN jalon de type "boss_final", en position {$nbQuetes} (dernière quête).
        - Environ {$nbSousBoss} jalon(s) "sous_boss", espacés régulièrement AVANT le final
          (positions strictement inférieures à {$nbQuetes}, toutes distinctes).
        - Le squelette est NARRATIF : aucune statistique, aucun monstre précis du
          catalogue — seulement des idées que les quêtes concrétiseront.
        TXT;

        $user = $this->contexteEnTexte($contexte, ['groupe', 'bible'])
            ."\n\nGénère le squelette de campagne pour ce groupe en appelant l'outil.";

        return ['system' => $system, 'user' => $user];
    }

    protected function validerMetier(array $sortie, array $contexte): array
    {
        $erreurs = [];
        $nbQuetes = (int) ($contexte['groupe']['nb_quetes_total'] ?? 1);
        $jalons = $sortie['jalons'] ?? [];

        $finals = array_values(array_filter($jalons, fn ($j) => ($j['type'] ?? null) === 'boss_final'));

        if (count($finals) !== 1) {
            $erreurs[] = 'Il faut exactement un jalon de type boss_final ('.count($finals).' reçu(s)).';
        } elseif ((int) $finals[0]['position'] !== $nbQuetes) {
            $erreurs[] = "Le boss_final doit être en position {$nbQuetes} (dernière quête), reçu position {$finals[0]['position']}.";
        }

        $positions = [];
        foreach ($jalons as $jalon) {
            $position = (int) ($jalon['position'] ?? 0);

            if ($position > $nbQuetes) {
                $erreurs[] = "Jalon en position {$position} hors de l'arc (max {$nbQuetes}).";
            }

            if (($jalon['type'] ?? null) === 'sous_boss' && $position >= $nbQuetes) {
                $erreurs[] = "Un sous_boss ne peut pas occuper la dernière quête (position {$position}).";
            }

            if (in_array($position, $positions, true)) {
                $erreurs[] = "Deux jalons partagent la position {$position}.";
            }
            $positions[] = $position;
        }

        return $erreurs;
    }

    /**
     * Cadence indicative des sous-boss selon la longueur (doc 06 §4,
     * table à ajuster en playtest).
     */
    private function nbSousBossAttendu(int $nbQuetes): int
    {
        return match (true) {
            $nbQuetes <= 1 => 0,
            $nbQuetes <= 5 => 1,
            $nbQuetes <= 10 => 2,
            $nbQuetes <= 15 => 3,
            default => 4,
        };
    }
}
