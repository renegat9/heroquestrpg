<?php

declare(strict_types=1);

namespace App\Agent\Skills;

/**
 * Skill « menu de choix contextuels » (doc 06 §1, saisie en menus seulement Q3).
 *
 * Génère 2 à 5 options BORNÉES que le moteur sait exécuter :
 *  - action   : action narrative simple, sans mécanique ;
 *  - dialogue : réplique / interaction PNJ, sans mécanique ;
 *  - jet      : tentative résolue par un jet Body/Mind (le skill fixe
 *               attribut + difficulté 1-4, le MOTEUR lance les dés) ;
 *  - attaque  : attaque d'un monstre actif (cible_id = instance) ;
 *  - attente  : passer / observer.
 *
 * Repli garanti (doc 08 §5) : si l'IA échoue après 2 retries, un jeu
 * d'options génériques codées en dur est proposé — Attendre, Fouiller,
 * Continuer — toujours exécutables.
 */
class MenuChoix extends Skill
{
    public const SCHEMA = [
        'type' => 'object',
        'required' => ['options'],
        'properties' => [
            'situation' => [
                'type' => 'string',
                'description' => 'Rappel d\'une phrase de la situation du héros (affiché en tête de menu).',
            ],
            'options' => [
                'type' => 'array',
                'minItems' => 2,
                'maxItems' => 5,
                'items' => [
                    'type' => 'object',
                    'required' => ['id', 'libelle', 'type'],
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'description' => 'Identifiant court unique de l\'option (snake_case).',
                        ],
                        'libelle' => [
                            'type' => 'string',
                            'minLength' => 3,
                            'description' => 'Texte du bouton, ex. « Crocheter la serrure — jet de Body ».',
                        ],
                        'type' => [
                            'type' => 'string',
                            'enum' => ['action', 'dialogue', 'jet', 'attaque', 'deplacement', 'attente'],
                        ],
                        'jet' => [
                            'type' => 'object',
                            'description' => 'Obligatoire si type=jet : paramètres exécutés par le moteur.',
                            'required' => ['attribut', 'difficulte'],
                            'properties' => [
                                'attribut' => ['type' => 'string', 'enum' => ['body', 'mind']],
                                'difficulte' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
                            ],
                        ],
                        'cible_id' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'description' => 'Obligatoire si type=attaque : instance_id d\'un monstre ACTIF de l\'état vivant.',
                        ],
                        'parametres' => [
                            'type' => 'object',
                            'description' => 'Paramètres libres affichés par la manette (ex. portée de déplacement).',
                        ],
                    ],
                ],
            ],
        ],
    ];

    /** Options génériques de repli, toujours exécutables (doc 08 §5). */
    public const OPTIONS_GENERIQUES = [
        ['id' => 'attendre', 'libelle' => 'Attendre et observer', 'type' => 'attente'],
        ['id' => 'fouiller', 'libelle' => 'Fouiller les environs — jet de Mind', 'type' => 'jet', 'jet' => ['attribut' => 'mind', 'difficulte' => 1]],
        ['id' => 'continuer', 'libelle' => 'Continuer prudemment', 'type' => 'action'],
    ];

    public function nomOutil(): string
    {
        return 'proposer_menu_choix';
    }

    public function descriptionOutil(): string
    {
        return 'Propose le menu de choix contextuels du héros : 2 à 5 options bornées que le moteur '
            .'sait exécuter (action, dialogue, jet Body/Mind avec difficulté, attaque, attente).';
    }

    protected function prompt(array $contexte): array
    {
        $system = $this->consignesCommunes($contexte)."\n\n".<<<'TXT'
        Tâche : générer le MENU DE CHOIX du héros indiqué (les joueurs ne tapent jamais
        de texte libre : ils choisissent un bouton).
        Contraintes (vérifiées par le moteur) :
        - 2 à 5 options variées et contextuelles, dont au plus une "attente".
        - Une option "jet" précise attribut (body|mind) et difficulté 1 (facile) à 4
          (très difficile) ; tu PROPOSES le jet, le moteur lance les dés.
        - Une option "attaque" cible un monstre ACTIF de l'état vivant (cible_id =
          instance_id) — n'en propose que s'il y a un monstre actif.
        - Chaque option doit être exécutable telle quelle : pas d'option conditionnelle,
          pas de mécanique inventée.
        TXT;

        $user = $this->contexteEnTexte($contexte, [
            'groupe', 'etat_vivant', 'evenements_recents', 'bible', 'personnage', 'situation',
        ])."\n\nGénère le menu de choix de ce héros en appelant l'outil.";

        return ['system' => $system, 'user' => $user];
    }

    protected function validerMetier(array $sortie, array $contexte): array
    {
        $erreurs = [];
        $ids = [];

        // Cibles légales = monstres actifs de l'état vivant (filtrage avant affichage).
        $ciblesActives = array_map(
            fn ($monstre) => (int) $monstre['instance_id'],
            $contexte['etat_vivant']['quete_courante']['monstres_actifs'] ?? [],
        );

        foreach ($sortie['options'] ?? [] as $i => $option) {
            $id = (string) ($option['id'] ?? '');

            if (in_array($id, $ids, true)) {
                $erreurs[] = "options[{$i}] : id « {$id} » en double.";
            }
            $ids[] = $id;

            if (($option['type'] ?? null) === 'jet' && ! isset($option['jet'])) {
                $erreurs[] = "options[{$i}] : type jet sans paramètres de jet (attribut + difficulté).";
            }

            if (($option['type'] ?? null) === 'attaque') {
                $cible = (int) ($option['cible_id'] ?? 0);

                if (! in_array($cible, $ciblesActives, true)) {
                    $erreurs[] = "options[{$i}] : attaque sur cible #{$cible} qui n'est pas un monstre actif.";
                }
            }
        }

        return $erreurs;
    }

    protected function repli(array $contexte): ?array
    {
        // Repli privilégié : le menu générique construit PAR LE MOTEUR depuis
        // l'état exact (Se déplacer / Attaquer / Fouiller / Attendre), fourni
        // dans le contexte par GenererMenu — toujours exécutable.
        if (isset($contexte['menu_moteur']['options'])) {
            return $contexte['menu_moteur'];
        }

        return [
            'situation' => 'Le danger rôde, le groupe reste sur ses gardes.',
            'options' => self::OPTIONS_GENERIQUES,
        ];
    }
}
