<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Sort;

/**
 * Validation des sorties du MJ IA (doc 08 §2).
 *
 * Deux niveaux, le schéma garantit la FORME, pas la VÉRACITÉ :
 *  1. conformité au schéma JSON du skill (types, required, enums, bornes) ;
 *  2. vérification des références au catalogue (ids de monstres / objets /
 *     sorts existants) et des contraintes moteur (budget, options exécutables),
 *     déléguée au skill via Skill::validerMetier().
 *
 * En cas d'échec, le skill relance la génération (max 2 retries) puis se
 * replie sur un contenu générique codé en dur quand il en a un (doc 08 §5).
 */
class ValidationSortie
{
    /**
     * Valide une sortie contre un schéma JSON (sous-ensemble utile :
     * type, properties, required, items, enum, minimum, maximum,
     * minItems, maxItems, minLength).
     *
     * @param  array<string, mixed>  $sortie
     * @param  array<string, mixed>  $schema
     * @return list<string> erreurs (vide = conforme)
     */
    public function validerSchema(array $sortie, array $schema): array
    {
        $erreurs = [];
        $this->validerNoeud($sortie, $schema, '$', $erreurs);

        return $erreurs;
    }

    /**
     * Références au catalogue : tous les ids de monstres existent-ils ?
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function validerMonstres(array $ids): array
    {
        return $this->validerReferences($ids, Monstre::class, 'monstre');
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function validerObjets(array $ids): array
    {
        return $this->validerReferences($ids, Objet::class, 'objet');
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function validerSorts(array $ids): array
    {
        return $this->validerReferences($ids, Sort::class, 'sort');
    }

    /**
     * @param  list<int>  $ids
     * @param  class-string  $modele
     * @return list<string>
     */
    private function validerReferences(array $ids, string $modele, string $libelle): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $ids)));

        if ($ids === []) {
            return [];
        }

        $existants = $modele::query()->whereIn('id', $ids)->pluck('id')->all();
        $manquants = array_diff($ids, array_map(intval(...), $existants));

        return array_map(
            fn (int $id) => "Référence catalogue inconnue : {$libelle} #{$id}.",
            array_values($manquants),
        );
    }

    /**
     * @param  list<string>  $erreurs
     */
    private function validerNoeud(mixed $valeur, array $schema, string $chemin, array &$erreurs): void
    {
        $type = $schema['type'] ?? null;

        if ($type !== null && ! $this->typeCorrespond($valeur, $type)) {
            $erreurs[] = "{$chemin} : type attendu {$type}, reçu ".get_debug_type($valeur).'.';

            return; // inutile de descendre dans un nœud du mauvais type
        }

        if (isset($schema['enum']) && ! in_array($valeur, $schema['enum'], true)) {
            $erreurs[] = "{$chemin} : valeur hors enum (".implode(', ', array_map(strval(...), $schema['enum'])).').';
        }

        if (is_string($valeur) && isset($schema['minLength']) && mb_strlen(trim($valeur)) < $schema['minLength']) {
            $erreurs[] = "{$chemin} : chaîne trop courte (min {$schema['minLength']}).";
        }

        if ((is_int($valeur) || is_float($valeur)) && isset($schema['minimum']) && $valeur < $schema['minimum']) {
            $erreurs[] = "{$chemin} : {$valeur} < minimum {$schema['minimum']}.";
        }

        if ((is_int($valeur) || is_float($valeur)) && isset($schema['maximum']) && $valeur > $schema['maximum']) {
            $erreurs[] = "{$chemin} : {$valeur} > maximum {$schema['maximum']}.";
        }

        if ($type === 'object' && is_array($valeur)) {
            foreach ($schema['required'] ?? [] as $cle) {
                if (! array_key_exists($cle, $valeur)) {
                    $erreurs[] = "{$chemin}.{$cle} : propriété requise manquante.";
                }
            }

            foreach ($schema['properties'] ?? [] as $cle => $sousSchema) {
                if (array_key_exists($cle, $valeur)) {
                    $this->validerNoeud($valeur[$cle], $sousSchema, "{$chemin}.{$cle}", $erreurs);
                }
            }
        }

        if ($type === 'array' && is_array($valeur)) {
            $nb = count($valeur);

            if (isset($schema['minItems']) && $nb < $schema['minItems']) {
                $erreurs[] = "{$chemin} : {$nb} élément(s), minimum {$schema['minItems']}.";
            }

            if (isset($schema['maxItems']) && $nb > $schema['maxItems']) {
                $erreurs[] = "{$chemin} : {$nb} élément(s), maximum {$schema['maxItems']}.";
            }

            if (isset($schema['items'])) {
                foreach (array_values($valeur) as $i => $element) {
                    $this->validerNoeud($element, $schema['items'], "{$chemin}[{$i}]", $erreurs);
                }
            }
        }
    }

    private function typeCorrespond(mixed $valeur, string $type): bool
    {
        return match ($type) {
            'object' => is_array($valeur) && ! array_is_list($valeur) || $valeur === [],
            'array' => is_array($valeur) && array_is_list($valeur),
            'string' => is_string($valeur),
            'integer' => is_int($valeur),
            'number' => is_int($valeur) || is_float($valeur),
            'boolean' => is_bool($valeur),
            'null' => $valeur === null,
            default => true,
        };
    }
}
