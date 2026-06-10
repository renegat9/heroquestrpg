<?php

declare(strict_types=1);

namespace App\Agent\Skills;

/**
 * Skill « narration » (doc 06 §1, étape 5 : mise en récit du résultat moteur).
 *
 * Reçoit dans le contexte le `resultat_moteur` (jet, attaque, choix…) déjà
 * résolu : le texte produit DOIT refléter fidèlement ce résultat — l'IA met
 * en récit, elle ne réinterprète jamais les chiffres (doc 08 §2).
 *
 * Repli générique (doc 08 §5) : une narration sobre dérivée du résultat
 * moteur, pour que la boucle de jeu ne se bloque jamais.
 */
class Narration extends Skill
{
    public const SCHEMA = [
        'type' => 'object',
        'required' => ['texte'],
        'properties' => [
            'texte' => [
                'type' => 'string',
                'minLength' => 20,
                'description' => 'Narration du MJ, 2 à 5 phrases, lue (TTS) sur l\'écran de table.',
            ],
            'ambiance' => [
                'type' => 'string',
                'enum' => ['calme', 'tension', 'combat', 'victoire', 'defaite', 'mystere'],
                'description' => 'Teinte d\'ambiance suggérée pour l\'écran de table (optionnel).',
            ],
        ],
    ];

    public function nomOutil(): string
    {
        return 'proposer_narration';
    }

    public function descriptionOutil(): string
    {
        return 'Met en récit la situation courante et le dernier résultat du moteur '
            .'(jet, attaque, choix) en 2 à 5 phrases immersives, sans altérer les faits mécaniques.';
    }

    protected function prompt(array $contexte): array
    {
        $system = $this->consignesCommunes($contexte)."\n\n".<<<'TXT'
        Tâche : NARRER la suite de la scène en 2 à 5 phrases immersives.
        Contraintes :
        - Le RÉSULTAT DU MOTEUR fourni est un fait accompli : raconte exactement ce
          résultat (réussite, échec, dégâts, monstre vaincu…), sans le modifier ni
          mentionner les mécaniques chiffrées (pas de « 2 crânes », pas de stats).
        - Une « réussite mixte » se narre comme un succès À COÛT : l'action aboutit
          mais avec une complication narrative (sans conséquence mécanique inventée).
        - Continuité stricte avec les événements récents et la bible.
        TXT;

        $user = $this->contexteEnTexte($contexte, [
            'groupe', 'squelette', 'etat_vivant', 'evenements_recents', 'bible', 'resultat_moteur',
        ])."\n\nNarre la suite de la scène en appelant l'outil.";

        return ['system' => $system, 'user' => $user];
    }

    protected function validerMetier(array $sortie, array $contexte): array
    {
        // Pas de référence catalogue à vérifier : la véracité mécanique est
        // garantie en amont (le résultat moteur est déjà résolu et journalisé).
        return [];
    }

    protected function repli(array $contexte): ?array
    {
        $resultat = $contexte['resultat_moteur'] ?? [];

        $texte = match ($resultat['issue'] ?? null) {
            'reussite' => 'Le geste est sûr et la tentative réussit : le groupe reprend l\'avantage et peut poursuivre.',
            'reussite_mixte' => 'La tentative aboutit, mais quelque chose accroche au passage — le groupe progresse, sur ses gardes.',
            'echec' => 'La tentative échoue. Un silence pesant s\'installe, et le groupe doit envisager une autre approche.',
            default => 'L\'aventure suit son cours : le groupe progresse prudemment dans la pénombre, attentif au moindre bruit.',
        };

        if (($resultat['type'] ?? null) === 'attaque') {
            $texte = ($resultat['degats'] ?? 0) > 0
                ? 'Le coup porte et l\'adversaire accuse le choc'.(($resultat['cible_vaincue'] ?? false) ? ' avant de s\'effondrer.' : ', mais reste menaçant.')
                : 'L\'attaque est parée de justesse : l\'adversaire tient bon et le combat continue.';
        }

        return ['texte' => $texte, 'ambiance' => 'tension'];
    }
}
