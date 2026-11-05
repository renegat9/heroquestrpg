<?php

declare(strict_types=1);

namespace App\Agent\Skills;

use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
use App\Partie\JalonsCampagne;

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
        'required' => ['premisse', 'menace', 'jalons', 'fils_narratifs', 'extensions'],
        'properties' => [
            // Le bestiaire de la campagne (René, 2026-11-05) : les boîtes que le THÈME évoque, ou rien s'il est trop
            // vague — le tirage fait à la création reste alors. Lu par `GenererSqueletteCampagne`, jamais gardé dans
            // le squelette. ⚠ L'unicité est vérifiée par `validerMetier()` : le validateur de schéma ne lit pas `uniqueItems`.
            'extensions' => [
                'type' => 'array',
                'maxItems' => BestiaireGroupe::BOITES_CHOISIES_MAX,
                'description' => 'Identifiants des extensions que le thème du groupe évoque clairement (1 à 3), ou liste vide.',
                'items' => ['type' => 'string', 'enum' => DemarreurQuete::BOITES_THEMATIQUES],
            ],
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
        $nbSousBoss = JalonsCampagne::nbSousBossAttendu($nbQuetes);

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
        $system .= $this->consigneExtensions($contexte['extensions'] ?? null);

        $user = $this->contexteEnTexte($contexte, ['groupe', 'bible'])
            .$this->catalogueExtensionsEnTexte($contexte['extensions'] ?? null)
            ."\n\nGénère le squelette de campagne pour ce groupe en appelant l'outil.";

        return ['system' => $system, 'user' => $user];
    }

    /**
     * La consigne du bestiaire. Choix OUVERT (un tirage, aucune quête jouée) : le MJ choisit selon le thème, ou laisse
     * le tirage. Choix FERMÉ (cochées par les joueurs, ou déjà joué) : il écrit pour les boîtes retenues.
     *
     * @param  array{choix_ouvert: bool, retenues: list<string>, catalogue: array<string, string>}|null  $extensions
     */
    private function consigneExtensions(?array $extensions): string
    {
        if ($extensions === null) {
            return "\n- Rends `extensions` vide.";
        }

        $retenues = implode(', ', array_map(
            fn (string $b) => app(DemarreurQuete::class)->libelleBoiteBestiaire($b),
            $extensions['retenues'],
        ));
        $max = BestiaireGroupe::BOITES_CHOISIES_MAX;

        if (! $extensions['choix_ouvert']) {
            return $retenues === ''
                ? "\n\nBestiaire : la campagne joue avec le jeu de base seul, aucune extension. Rends `extensions` VIDE."
                : "\n\nBestiaire : la campagne joue avec le jeu de base et {$retenues}. Rends `extensions` VIDE. "
                    .'Prémisse, menace et jalons doivent coller au décor et aux adversaires de ces extensions (section EXTENSIONS).';
        }

        return <<<TXT


        Bestiaire (section EXTENSIONS) : chaque extension apporte son décor et ses créatures, qui s'ajoutent au jeu de base.
        - Mets dans `extensions` de 1 à {$max} identifiant(s) d'extension que le THÈME du groupe évoque CLAIREMENT
          (un lieu, un peuple, une créature, une ambiance). N'en ajoute pas pour faire nombre.
        - Si le thème n'en évoque clairement aucune, rends `extensions` VIDE : la campagne jouera alors avec celles
          tirées au hasard, {$retenues}.
        - Prémisse, menace et jalons doivent coller au décor et aux adversaires des extensions retenues (les tiennes,
          ou à défaut le tirage).
        TXT;
    }

    /**
     * @param  array{choix_ouvert: bool, retenues: list<string>, catalogue: array<string, string>}|null  $extensions
     */
    private function catalogueExtensionsEnTexte(?array $extensions): string
    {
        if ($extensions === null) {
            return '';
        }

        $lignes = [];
        foreach ($extensions['catalogue'] as $boite => $description) {
            $lignes[] = "- `{$boite}` : {$description}";
        }

        return "\n\n## EXTENSIONS (identifiant : description)\n".implode("\n", $lignes);
    }

    protected function validerMetier(array $sortie, array $contexte): array
    {
        $erreurs = [];

        $extensions = (array) ($sortie['extensions'] ?? []);
        if (count($extensions) !== count(array_unique($extensions))) {
            $erreurs[] = 'Une extension est citée deux fois dans `extensions`.';
        }
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
}
