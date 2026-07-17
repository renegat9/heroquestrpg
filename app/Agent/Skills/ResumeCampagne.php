<?php

declare(strict_types=1);

namespace App\Agent\Skills;

/**
 * Skill « résumé de campagne » (doc 05 §6, étape 3 de la clôture).
 *
 * Appelé UNE FOIS par le job CloturerCampagne, AVANT la purge : produit la
 * mémoire durable et COMPACTE de l'aventure, écrite dans l'historique de
 * chaque fiche de personnage (personnage_historique.resume). Le prompt
 * s'appuie sur le journal (événements récents), le squelette de campagne
 * (plan_campagne) et les statistiques réelles de clôture fournies par le
 * job (section `cloture` : nb de quêtes, issue, or partagé).
 *
 * Repli sans LLM (doc 08 §5) : un résumé FACTUEL dérivé des statistiques
 * réelles — « Campagne {nom} ({theme}) : N quêtes, issue, or partagé » —
 * la clôture n'est jamais bloquée par l'IA.
 */
class ResumeCampagne extends Skill
{
    public const SCHEMA = [
        'type' => 'object',
        'required' => ['resume'],
        'properties' => [
            'resume' => [
                'type' => 'string',
                'minLength' => 40,
                'description' => 'Résumé compact de la campagne, 3 à 6 phrases : menace affrontée, '
                    .'moments marquants, issue (victoire/échec/abandon) et partage du butin.',
            ],
        ],
    ];

    public function nomOutil(): string
    {
        return 'proposer_resume_campagne';
    }

    public function descriptionOutil(): string
    {
        return 'Rédige le résumé compact (3 à 6 phrases) de la campagne qui s\'achève, pour '
            .'l\'historique durable des fiches de personnages — fidèle au journal, sans rien inventer.';
    }

    protected function prompt(array $contexte): array
    {
        $system = $this->consignesCommunes($contexte)."\n\n".<<<'TXT'
        Tâche : RÉSUMER la campagne qui s'achève en 3 à 6 phrases compactes, pour
        l'historique durable des personnages (toutes les autres données seront purgées).
        Contraintes :
        - Fidélité stricte au journal et au fil rouge : menace, quêtes marquantes,
          issue réelle (victoire, échec ou abandon) — n'invente RIEN.
        - Les STATISTIQUES DE CLÔTURE fournies (nombre de quêtes, issue, or partagé)
          sont des faits accomplis : le résumé doit y rester conforme.
        - HONORE les quêtes DÉJÀ REMPORTÉES (`cloture.nb_quetes_terminees`), MÊME
          en cas d'échec ou d'abandon final : une défaite au bout du chemin
          n'efface pas les victoires acquises — mentionne-les. Ne présente pas une
          campagne à victoires comme un échec total.
        - Pas de mécanique chiffrée (dés, PV) : un récit court, au passé, mémorable.
        TXT;

        $user = $this->contexteEnTexte($contexte, [
            'groupe', 'squelette', 'evenements_recents', 'bible', 'cloture',
        ])."\n\nRédige le résumé de campagne en appelant l'outil.";

        return ['system' => $system, 'user' => $user];
    }

    protected function validerMetier(array $sortie, array $contexte): array
    {
        // Pas de référence catalogue : le résumé est purement narratif et les
        // faits chiffrés (issue, or) sont écrits par le moteur dans l'historique.
        return [];
    }

    /**
     * Résumé factuel sans LLM, depuis les statistiques réelles du journal
     * (section `cloture` assemblée par CloturerCampagne).
     */
    protected function repli(array $contexte): ?array
    {
        $cloture = $contexte['cloture'] ?? [];
        $nom = (string) ($contexte['groupe']['nom'] ?? 'sans nom');
        $theme = (string) ($contexte['groupe']['theme'] ?? 'thème inconnu');

        $nbQuetes = (int) ($cloture['nb_quetes'] ?? 0);
        $nbVictoires = (int) ($cloture['nb_quetes_terminees'] ?? 0);
        $orPartage = (int) ($cloture['or_partage'] ?? 0);

        $issue = match ($cloture['issue'] ?? null) {
            'victoire' => 'victoire — la menace a été vaincue',
            'echec' => 'échec — le groupe est tombé face à la menace',
            default => 'abandon — le groupe a renoncé en chemin',
        };

        $resume = sprintf(
            'Campagne %s (%s) : %d quête(s) menée(s) dont %d victorieuse(s), %s, %d pièce(s) d\'or partagée(s) entre les héros.',
            $nom,
            $theme,
            $nbQuetes,
            $nbVictoires,
            $issue,
            $orPartage,
        );

        return ['resume' => $resume];
    }
}
