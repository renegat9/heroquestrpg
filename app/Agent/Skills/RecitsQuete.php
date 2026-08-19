<?php

declare(strict_types=1);

namespace App\Agent\Skills;

use App\Partie\Narration\TempsFort;

/**
 * Skill « récits de quête » — l'UNIQUE appel narratif d'une quête (décision de
 * René, 2026-08-18 : l'IA FABRIQUE la quête, elle ne la joue plus). Lancé une
 * fois au démarrage par `GenererRecitsQuete`, chaîné après `HabillerMonstres`
 * pour que les salles puissent nommer les créatures avec leur nom HABILLÉ.
 *
 * Il produit exactement ce qui est PROPRE À CETTE QUÊTE :
 *  - une description par salle du donjon ;
 *  - les trois temps forts qui ne dépendent d'aucun tirage
 *    ({@see TempsFort::GENERES_PAR_QUETE}) : la découverte de l'arme unique,
 *    l'ouverture et la victoire.
 *
 * ⚠ Tout le reste est GÉNÉRIQUE (`config/narration.php`), et c'est un
 * raisonnement, pas une économie (René, 2026-08-18) : le résultat d'une
 * fouille est un TIRAGE. L'IA ne peut pas savoir à l'avance si le héros
 * trouvera de l'or, une potion ou un monstre errant ; écrire sur mesure les
 * cinq issues possibles ne dit rien qu'une table fixe ne dise aussi bien — et
 * cela coûtait ~5 500 tokens de sortie et deux à trois minutes de génération
 * par quête. `BibliothequeNarration::pourQuete()` retombant déjà seule sur la
 * config pour toute clé absente du pack, il n'y a rien à câbler : il suffit de
 * ne plus les produire.
 *
 * Repli (doc 08 §5) : salles décrites depuis `repli.salle_decouverte`, aucun
 * temps fort — la config les porte déjà toutes les vingt-quatre.
 */
class RecitsQuete extends Skill
{
    /**
     * Vocabulaire d'ambiance d'UNE réplique narrée (teinte suggérée à l'écran
     * de table). À ne pas confondre avec `groupe.ambiance` (EtatGroupe), qui
     * pilote la boucle MUSICALE et parle de SCÈNES : hub/exploration/combat/boss.
     */
    private const AMBIANCES = ['calme', 'tension', 'combat', 'victoire', 'defaite', 'mystere'];

    public const SCHEMA = [
        'type' => 'object',
        'required' => ['salles', 'temps_forts'],
        'properties' => [
            'salles' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 20,
                'items' => [
                    'type' => 'object',
                    'required' => ['salle', 'texte', 'entree', 'ambiance'],
                    'properties' => [
                        'salle' => [
                            'type' => 'integer',
                            'minimum' => 0,
                            'description' => 'Id EXACT de la salle décrite (voir SALLES À DÉCRIRE).',
                        ],
                        'texte' => [
                            'type' => 'string',
                            'minLength' => 40,
                            'maxLength' => 500,
                            'description' => 'Description du LIEU et de ses occupants, 2 à 4 phrases, '
                                .'SANS aucune variable : ce que les héros y font n\'est jamais décrit ici.',
                        ],
                        'entree' => [
                            'type' => 'string',
                            'minLength' => 15,
                            'maxLength' => 160,
                            'description' => 'UNE phrase courte nommant le héros qui franchit le seuil, '
                                .'via la variable {heros} — obligatoire, et seule variable autorisée. '
                                .'Ex. « {heros} pousse la porte et découvre la salle voûtée. »',
                        ],
                        'ambiance' => [
                            'type' => 'string',
                            'enum' => self::AMBIANCES,
                            'description' => 'Teinte d\'ambiance de cette salle.',
                        ],
                    ],
                ],
            ],
            'temps_forts' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 3,
                'items' => [
                    'type' => 'object',
                    'required' => ['cle', 'ambiance', 'variantes'],
                    'properties' => [
                        'cle' => [
                            'type' => 'string',
                            'enum' => TempsFort::GENERES_PAR_QUETE,
                        ],
                        'ambiance' => ['type' => 'string', 'enum' => self::AMBIANCES],
                        'variantes' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'maxItems' => 3,
                            'items' => ['type' => 'string', 'minLength' => 30, 'maxLength' => 400],
                        ],
                    ],
                ],
            ],
        ],
    ];

    public function nomOutil(): string
    {
        return 'ecrire_recits_quete';
    }

    public function descriptionOutil(): string
    {
        return 'Écrit les récits propres à cette quête : une description par salle du donjon, '
            .'et les trois temps forts qui lui appartiennent (découverte de l\'arme unique, '
            .'ouverture, victoire).';
    }

    protected function prompt(array $contexte): array
    {
        $variables = implode(' · ', array_map(
            fn (string $c) => $c.' → '.(TempsFort::variablesDe($c) === []
                ? 'aucune variable'
                : '{'.implode('} {', TempsFort::variablesDe($c)).'}'),
            TempsFort::GENERES_PAR_QUETE,
        ));

        $system = $this->consignesCommunes($contexte)."\n\n".<<<TXT
        Tâche : écrire, une fois pour toutes, les récits PROPRES à cette quête.
        Ils sont figés dès le démarrage et rejoués tels quels en cours de partie.

        A. UNE DESCRIPTION PAR SALLE, en deux morceaux complémentaires :
           - `texte` : le LIEU — architecture, atmosphère, mobilier présent,
             créatures qui s'y trouvent (par leur nom habillé). JAMAIS ce que
             les héros y font, jamais de deuxième personne : l'ordre
             d'exploration n'est pas connu d'avance, et n'importe lequel des
             héros peut ouvrir cette porte. AUCUNE variable : ce texte doit
             rester identique à lui-même pour que la vraie voix du narrateur
             puisse en être enregistrée à l'avance.
           - `entree` : UNE phrase, celle qui nomme l'arrivant, avec {heros}.
             Elle sera dite juste avant `texte`, et seulement là où la voix
             enregistrée n'est pas disponible.
           Une entrée par salle listée, ni plus ni moins, avec l'id EXACT.
           Une salle plus profonde peut porter une tension plus lourde, sans
           systématisme. Une salle marquée « coffre » peut laisser DEVINER un
           trésor sans le révéler : il reste à chercher. Une salle sans
           mobilier ni monstre est un lieu à part entière, pas un vide.

        B. LES TROIS TEMPS FORTS DE CETTE QUÊTE, 3 variantes chacun :
           - fouille_artefact : l'instant où l'arme unique du donjon est mise
             au jour. C'est le sommet de la quête — écris-le comme tel.
           - quete_demarree : l'entrée du groupe dans ce donjon précis.
           - victoire_quete : le silence qui retombe une fois l'objectif atteint.
           Variables autorisées, par clé : {$variables}. N'en emploie AUCUNE
           autre : une variable inconnue du moteur resterait affichée telle
           quelle à l'écran.

        Cohérence stricte avec le squelette de campagne et la bible (mêmes
        factions, même thème, même registre).
        TXT;

        $user = $this->contexteEnTexte($contexte, ['groupe', 'squelette', 'bible'])
            ."\n\n## SALLES À DÉCRIRE (id, thème, profondeur dans l'arbre, mobilier, monstres, coffre)\n"
            .json_encode($contexte['salles_a_decrire'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nAppelle l'outil avec une description par salle ci-dessus, et les trois temps forts.";

        return ['system' => $system, 'user' => $user];
    }

    protected function validerMetier(array $sortie, array $contexte): array
    {
        return [
            ...$this->validerSalles($sortie, $contexte),
            ...$this->validerTempsForts($sortie),
        ];
    }

    /**
     * @param  array<string, mixed>  $sortie
     * @param  array<string, mixed>  $contexte
     * @return list<string>
     */
    private function validerSalles(array $sortie, array $contexte): array
    {
        $erreurs = [];

        $attendues = collect($contexte['salles_a_decrire'] ?? [])
            ->pluck('salle')->map(fn ($id) => (int) $id)->all();
        $recues = collect($sortie['salles'] ?? [])
            ->pluck('salle')->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($attendues, $recues) as $manquante) {
            $erreurs[] = "Salle {$manquante} n'a reçu aucune description.";
        }

        foreach (array_diff($recues, $attendues) as $inconnue) {
            $erreurs[] = "Salle {$inconnue} ne fait partie d'aucune salle de cette quête.";
        }

        foreach (array_count_values($recues) as $id => $nb) {
            if ($nb > 1) {
                $erreurs[] = "Salle {$id} décrite {$nb} fois (une seule description attendue).";
            }
        }

        foreach ($sortie['salles'] ?? [] as $entree) {
            $id = $entree['salle'] ?? '?';
            $texte = (string) ($entree['texte'] ?? '');
            $arrivee = (string) ($entree['entree'] ?? '');

            // ⚠ Les deux moitiés ont des contraintes INVERSES, et c'est tout
            // l'intérêt du découpage : `texte` doit être figé pour qu'un audio
            // puisse en être pré-généré (indexé par hash) ; `entree` doit
            // nommer l'arrivant, donc porter {heros} — et ne sert que là où
            // cet audio n'existe pas.
            if (TempsFort::variablesEmployees($texte) !== []) {
                $erreurs[] = "Salle {$id} : le texte de salle ne doit porter aucune variable "
                    .'(il est figé pour que sa voix soit enregistrable à l\'avance).';
            }

            $variables = TempsFort::variablesEmployees($arrivee);
            if (! in_array('heros', $variables, true)) {
                $erreurs[] = "Salle {$id} : la phrase d'entrée doit nommer l'arrivant via {heros}.";
            }
            if (array_diff($variables, ['heros']) !== []) {
                $erreurs[] = "Salle {$id} : la phrase d'entrée n'admet que {heros}.";
            }
        }

        return $erreurs;
    }

    /**
     * @param  array<string, mixed>  $sortie
     * @return list<string>
     */
    private function validerTempsForts(array $sortie): array
    {
        $erreurs = [];
        $recues = [];

        foreach ($sortie['temps_forts'] ?? [] as $bloc) {
            $cle = (string) ($bloc['cle'] ?? '');
            $recues[] = $cle;

            if (! in_array($cle, TempsFort::GENERES_PAR_QUETE, true)) {
                $erreurs[] = "Temps fort « {$cle} » : cette quête n'en écrit que "
                    .implode(', ', TempsFort::GENERES_PAR_QUETE).'.';

                continue;
            }

            $autorisees = TempsFort::variablesDe($cle);

            foreach ($bloc['variantes'] ?? [] as $variante) {
                $illegales = array_diff(TempsFort::variablesEmployees((string) $variante), $autorisees);

                if ($illegales !== []) {
                    $erreurs[] = "Temps fort « {$cle} » : variable(s) {".implode('}, {', $illegales)
                        .'} que le moteur ne fournit pas pour cette clé.';
                }
            }
        }

        foreach (array_diff(TempsFort::GENERES_PAR_QUETE, $recues) as $manquant) {
            $erreurs[] = "Temps fort « {$manquant} » manquant.";
        }

        return $erreurs;
    }

    protected function repli(array $contexte): ?array
    {
        $variantes = array_values((array) config('narration.repli.salle_decouverte.variantes', []));
        $ambiance = (string) config('narration.repli.salle_decouverte.ambiance', 'mystere');
        $ids = array_values(array_unique(array_map(
            fn ($s) => (int) ($s['salle'] ?? 0),
            $contexte['salles_a_decrire'] ?? [],
        )));

        $salles = [];
        foreach ($ids as $i => $id) {
            if ($variantes === []) {
                break;
            }

            $salles[] = [
                'salle' => $id,
                'texte' => (string) $variantes[$i % count($variantes)],
                // Pas de phrase d'entrée générique ici : `config/narration.php`
                // porte déjà `salle_decouverte`, et en fabriquer une seconde
                // ferait dire deux fois la même chose.
                'entree' => '',
                'ambiance' => $ambiance,
            ];
        }

        // Aucun temps fort : les 24 clés sont déjà couvertes par la config,
        // vers laquelle `BibliothequeNarration::pourQuete()` retombe seule.
        return ['salles' => $salles, 'temps_forts' => []];
    }
}
