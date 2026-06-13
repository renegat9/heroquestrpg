<?php

declare(strict_types=1);

namespace App\Agent\Skills;

/**
 * Skill « habillage des monstres » (doc 06 §5, Q6 : « l'IA renomme et redécrit
 * sans toucher aux stats »).
 *
 * Le moteur a DÉJÀ spawné les instances au budget de rencontre (DemarreurQuete,
 * P3) : ce skill ne crée ni ne supprime rien, il fournit seulement un nom et une
 * description thématiques POUR CHAQUE bloc de monstre présent (par monstre_id).
 * Le job applique ensuite l'habillage aux instances correspondantes.
 *
 * Repli (doc 08 §5) : aucun habillage — les instances gardent leur nom de
 * catalogue, la quête reste parfaitement jouable.
 */
class HabillageMonstres extends Skill
{
    public const SCHEMA = [
        'type' => 'object',
        'required' => ['habillages'],
        'properties' => [
            'habillages' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'required' => ['monstre_id', 'nom', 'description'],
                    'properties' => [
                        'monstre_id' => ['type' => 'integer', 'description' => 'Id du bloc de monstre du catalogue à habiller.'],
                        'nom' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60, 'description' => 'Nom thématique donné à ce monstre (ex. gobelin → écumeur des cryptes).'],
                        'description' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 240, 'description' => 'Brève description d\'ambiance, cohérente avec le thème et la bible.'],
                    ],
                ],
            ],
        ],
    ];

    public function nomOutil(): string
    {
        return 'habiller_monstres';
    }

    public function descriptionOutil(): string
    {
        return 'Donne un nom et une description thématiques à chaque bloc de monstre '
            .'présent dans la quête, sans en changer les statistiques (habillage Q6).';
    }

    protected function prompt(array $contexte): array
    {
        $system = $this->consignesCommunes($contexte)."\n\n".<<<'TXT'
        Tâche : HABILLER les monstres de la quête courante selon le thème de la
        campagne. Pour CHAQUE bloc fourni (identifié par monstre_id), propose un
        nom et une courte description qui collent au thème et à la bible.
        Contraintes :
        - Renomme/redécris uniquement : tu ne touches à AUCUNE statistique.
        - Un habillage par monstre_id fourni, pas davantage, pas moins.
        - Cohérence avec le squelette et la bible (mêmes factions, même registre).
        TXT;

        $user = $this->contexteEnTexte($contexte, ['groupe', 'squelette', 'bible'])
            ."\n\n## MONSTRES À HABILLER (blocs présents, ne pas modifier les stats)\n"
            .json_encode($contexte['monstres_a_habiller'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nAppelle l'outil avec un habillage par monstre_id.";

        return ['system' => $system, 'user' => $user];
    }

    protected function validerMetier(array $sortie, array $contexte): array
    {
        $erreurs = [];
        $idsAttendus = collect($contexte['monstres_a_habiller'] ?? [])->pluck('monstre_id')->map(fn ($id) => (int) $id)->all();
        $idsRecus = collect($sortie['habillages'] ?? [])->pluck('monstre_id')->map(fn ($id) => (int) $id)->all();

        foreach ($idsRecus as $id) {
            if (! in_array($id, $idsAttendus, true)) {
                $erreurs[] = "monstre_id {$id} ne fait pas partie des monstres de la quête.";
            }
        }

        return $erreurs;
    }

    protected function repli(array $contexte): ?array
    {
        // Pas d'habillage : les instances gardent leur nom de catalogue.
        return ['habillages' => []];
    }
}
