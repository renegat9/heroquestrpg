<?php

declare(strict_types=1);

namespace App\Agent\Skills;

/**
 * Skill « détail de quête » (doc 06 §2, juste-à-temps au hub).
 *
 * Le MOTEUR fixe la difficulté : le job calcule le budget de rencontres
 * (score de puissance du groupe) et fournit le catalogue autorisé ;
 * l'IA ne fait que REMPLIR ce budget avec des monstres du catalogue et
 * les habiller (renommer/redécrire sans toucher aux stats, Q6/P3).
 *
 * Contexte attendu en plus du socle ContexteAssembleur :
 *  - catalogue : ['monstres' => [{id, nom_base, tier, cout}], 'objets' => [{id, nom, categorie, rarete}]]
 *  - budget    : int — budget de rencontres calculé par le moteur
 *  - position_arc, type_jalon, gabarit (structure du gabarit retenu)
 *
 * Pas de repli générique : sans détail la quête ne peut pas se lancer,
 * l'exception remonte au job (retry par la file).
 */
class DetailQuete extends Skill
{
    public const SCHEMA = [
        'type' => 'object',
        'required' => ['titre', 'introduction', 'objectif', 'rencontres', 'butin'],
        'properties' => [
            'titre' => ['type' => 'string', 'minLength' => 3],
            'introduction' => [
                'type' => 'string',
                'minLength' => 40,
                'description' => 'Narration d\'entrée de quête, lue sur l\'écran de table.',
            ],
            'objectif' => [
                'type' => 'string',
                'minLength' => 10,
                'description' => 'Objectif jouable de la quête, une phrase claire.',
            ],
            'rencontres' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 8,
                'description' => 'Rencontres remplissant le budget : monstres du catalogue, habillés.',
                'items' => [
                    'type' => 'object',
                    'required' => ['monstre_id', 'nombre', 'nom_habille', 'description_habillee'],
                    'properties' => [
                        'monstre_id' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'description' => 'Id EXACT d\'un monstre du catalogue fourni.',
                        ],
                        'nombre' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
                        'nom_habille' => [
                            'type' => 'string',
                            'minLength' => 2,
                            'description' => 'Nom thématique (habillage) — les stats restent celles du catalogue.',
                        ],
                        'description_habillee' => ['type' => 'string', 'minLength' => 10],
                    ],
                ],
            ],
            'pnj' => [
                'type' => 'array',
                'maxItems' => 4,
                'items' => [
                    'type' => 'object',
                    'required' => ['nom', 'role', 'description'],
                    'properties' => [
                        'nom' => ['type' => 'string', 'minLength' => 2],
                        'role' => ['type' => 'string', 'minLength' => 2],
                        'description' => ['type' => 'string', 'minLength' => 10],
                    ],
                ],
            ],
            'butin' => [
                'type' => 'array',
                'maxItems' => 6,
                'description' => 'Butin de la quête : objets du catalogue uniquement.',
                'items' => [
                    'type' => 'object',
                    'required' => ['objet_id'],
                    'properties' => [
                        'objet_id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ],
            ],
        ],
    ];

    public function nomOutil(): string
    {
        return 'proposer_detail_quete';
    }

    public function descriptionOutil(): string
    {
        return 'Propose le détail jouable de la prochaine quête : titre, introduction, objectif, '
            .'rencontres remplissant le budget imposé (monstres du catalogue habillés), PNJ et butin.';
    }

    protected function prompt(array $contexte): array
    {
        $budget = (int) ($contexte['budget'] ?? 0);
        $positionArc = (int) ($contexte['position_arc'] ?? 1);
        $typeJalon = (string) ($contexte['type_jalon'] ?? 'normale');

        $system = $this->consignesCommunes($contexte)."\n\n".<<<TXT
        Tâche : générer le DÉTAIL JOUABLE de la quête n°{$positionArc} (type : {$typeJalon}).
        Contraintes (vérifiées par le moteur) :
        - BUDGET DE RENCONTRES : {$budget} points. La somme des (coût du monstre × nombre)
          de tes rencontres doit rester ≤ {$budget} et s'en approcher (≥ la moitié).
          Le budget est fixé par le moteur, tu ne le négocies pas.
        - Les monstre_id et objet_id viennent EXCLUSIVEMENT du catalogue fourni.
        - Habille librement (noms, descriptions) sans jamais toucher aux stats.
        - Suis le squelette de campagne : c'est la quête {$positionArc} de l'arc — préfigure
          la menace, exploite les fils narratifs et les faits de la bible.
        - Respecte la structure du gabarit fourni le cas échéant.
        TXT;

        $user = $this->contexteEnTexte($contexte, [
            'groupe', 'squelette', 'etat_vivant', 'evenements_recents', 'bible', 'gabarit', 'catalogue',
        ])."\n\nGénère le détail de cette quête en appelant l'outil.";

        return ['system' => $system, 'user' => $user];
    }

    protected function validerMetier(array $sortie, array $contexte): array
    {
        $erreurs = [];

        $couts = [];
        foreach ($contexte['catalogue']['monstres'] ?? [] as $monstre) {
            $couts[(int) $monstre['id']] = (int) $monstre['cout'];
        }
        $objetsAutorises = array_map(
            fn ($objet) => (int) $objet['id'],
            $contexte['catalogue']['objets'] ?? [],
        );

        // 1. Références au catalogue (ids existants en base).
        $idsMonstres = array_map(fn ($r) => (int) $r['monstre_id'], $sortie['rencontres'] ?? []);
        $idsObjets = array_map(fn ($b) => (int) $b['objet_id'], $sortie['butin'] ?? []);

        $erreurs = [
            ...$erreurs,
            ...$this->validation->validerMonstres($idsMonstres),
            ...$this->validation->validerObjets($idsObjets),
        ];

        // 2. Restriction au catalogue FOURNI (sous-ensemble autorisé) + budget moteur.
        $coutTotal = 0;
        foreach ($sortie['rencontres'] ?? [] as $i => $rencontre) {
            $id = (int) $rencontre['monstre_id'];

            if (! array_key_exists($id, $couts)) {
                $erreurs[] = "rencontres[{$i}] : monstre #{$id} hors du catalogue autorisé pour cette quête.";

                continue;
            }

            $coutTotal += $couts[$id] * (int) $rencontre['nombre'];
        }

        $budget = (int) ($contexte['budget'] ?? 0);

        if ($budget > 0 && $coutTotal > $budget) {
            $erreurs[] = "Budget de rencontres dépassé : {$coutTotal} > {$budget} (retire ou allège des rencontres).";
        }

        if ($budget > 0 && $coutTotal < (int) ceil($budget / 2)) {
            $erreurs[] = "Budget de rencontres sous-utilisé : {$coutTotal} < ".ceil($budget / 2).' (la quête serait trop facile, ajoute des rencontres).';
        }

        foreach ($sortie['butin'] ?? [] as $i => $ligne) {
            if (! in_array((int) $ligne['objet_id'], $objetsAutorises, true)) {
                $erreurs[] = "butin[{$i}] : objet #{$ligne['objet_id']} hors du catalogue autorisé pour cette quête.";
            }
        }

        return $erreurs;
    }
}
