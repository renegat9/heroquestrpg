<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use Closure;

/**
 * LES OPÉRATIONS CIBLÉES SUR UNE QUÊTE — modifier un document sans le réécrire en entier
 * (`docs/plan-mode-classique.md` §8 bis, lot P2). Écrit pour le serveur MCP (`quete_modifier`), où une IA change une
 * porte ou ajoute un monstre sans renvoyer toute la quête ; l'écran du constructeur passera par ici aussi.
 *
 * Aucune règle de FORMAT ici : ces opérations ne font que modifier une COPIE du document, par un chemin pointé relatif
 * à la quête. C'est {@see CampagnesConstruites::modifierQuete()} qui enregistre ensuite la campagne entière, donc qui
 * la revalide (`ValidationCampagne`). Une opération fautive est nommée par son rang et n'écrit rien.
 *
 * Vocabulaire FERMÉ : {@see OPERATIONS}. Un autre nom est refusé, jamais ignoré.
 */
final class OperationsDeQuete
{
    /** Les opérations offertes. */
    public const OPERATIONS = ['ajouter', 'retirer', 'remplacer'];

    /** Les seules clés d'une opération. Une clé inconnue est refusée, jamais ignorée. */
    public const CLES = ['op', 'chemin', 'valeur'];

    /** Le slug désigne la quête : il ne se change pas par une opération (ce serait une autre quête). */
    private const CHAMP_SLUG = 'slug';

    /**
     * Les clés FACULTATIVES d'une quête, et ce qu'elles valent quand elles sont absentes. Une opération peut les viser
     * comme si elles étaient là (« ajouter » une première note à une quête qui n'en a pas) ; restées vides, elles ne
     * sont pas écrites dans le document.
     */
    private const FACULTATIVES = ['notes' => []];

    /**
     * Applique les opérations, dans l'ordre, sur une copie de la quête. Rend la quête modifiée ; la source n'est pas touchée.
     *
     * @param  array<string, mixed>  $quete
     * @param  array<int, mixed>  $operations
     * @return array<string, mixed>
     *
     * @throws ErreurConstructeur à la première opération fautive, nommée par son rang (1 = la première)
     */
    public function appliquer(array $quete, array $operations): array
    {
        $ajoutees = [];
        foreach (self::FACULTATIVES as $cle => $defaut) {
            if (! array_key_exists($cle, $quete)) {
                $quete[$cle] = $defaut;
                $ajoutees[$cle] = $defaut;
            }
        }

        foreach (array_values($operations) as $indice => $operation) {
            try {
                $quete = $this->appliquerUne($quete, $operation);
            } catch (ErreurConstructeur $erreur) {
                throw new ErreurConstructeur('Opération n°'.($indice + 1).' : '.$erreur->getMessage());
            }
        }

        // Une clé facultative posée pour l'occasion et restée telle quelle ne s'écrit pas : le document d'une quête
        // sans note reste un document sans clé `notes`.
        foreach ($ajoutees as $cle => $defaut) {
            if ($quete[$cle] === $defaut) {
                unset($quete[$cle]);
            }
        }

        return $quete;
    }

    /**
     * @param  array<string, mixed>  $quete
     * @return array<string, mixed>
     *
     * @throws ErreurConstructeur
     */
    private function appliquerUne(array $quete, mixed $operation): array
    {
        if (! is_array($operation) || array_is_list($operation)) {
            throw new ErreurConstructeur('ce n\'est pas un objet { op, chemin, valeur }.');
        }

        $inconnues = array_diff(array_keys($operation), self::CLES);
        if ($inconnues !== []) {
            throw new ErreurConstructeur(
                'clé inconnue « '.implode('», « ', array_map('strval', $inconnues)).' » (attendues : op, chemin, valeur).',
            );
        }

        $op = $operation['op'] ?? null;
        if (! is_string($op) || ! in_array($op, self::OPERATIONS, true)) {
            $recu = match (true) {
                $op === null => 'absent',
                is_scalar($op) => (string) $op,
                default => gettype($op),
            };

            throw new ErreurConstructeur('« op » doit valoir ajouter, retirer ou remplacer (reçu : '.$recu.').');
        }

        $chemin = $operation['chemin'] ?? null;
        if (! is_string($chemin) || $chemin === '') {
            throw new ErreurConstructeur('« chemin » manque ou est vide.');
        }

        $segments = explode('.', $chemin);
        if (in_array('', $segments, true)) {
            throw new ErreurConstructeur('le chemin « '.$chemin.' » a un segment vide.');
        }
        if ($segments[0] === self::CHAMP_SLUG) {
            throw new ErreurConstructeur('le slug désigne la quête : une opération ne le change pas (quete_ecrire pour une autre quête).');
        }

        $aUneValeur = array_key_exists('valeur', $operation);
        if ($op === 'retirer' && $aUneValeur) {
            throw new ErreurConstructeur('retirer n\'a pas de « valeur » : il retire l\'élément désigné par « '.$chemin.' ».');
        }
        if ($op !== 'retirer' && ! $aUneValeur) {
            throw new ErreurConstructeur($op.' exige une « valeur ».');
        }

        return match ($op) {
            'remplacer' => $this->remplacer($quete, $segments, $chemin, $operation['valeur']),
            'ajouter' => $this->ajouter($quete, $segments, $chemin, $operation['valeur']),
            'retirer' => $this->retirer($quete, $segments, $chemin),
        };
    }

    /**
     * @param  array<string, mixed>  $quete
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private function remplacer(array $quete, array $segments, string $chemin, mixed $valeur): array
    {
        return $this->au($quete, $segments, $chemin, static fn (mixed $ancienne): mixed => $valeur);
    }

    /**
     * @param  array<string, mixed>  $quete
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private function ajouter(array $quete, array $segments, string $chemin, mixed $valeur): array
    {
        return $this->au($quete, $segments, $chemin, static function (mixed $cible) use ($chemin, $valeur): array {
            if (! is_array($cible) || ! array_is_list($cible)) {
                throw new ErreurConstructeur('ajouter vise une liste, et « '.$chemin.' » n\'en est pas une.');
            }

            return [...$cible, $valeur];
        });
    }

    /**
     * Retire l'élément d'index désigné par le DERNIER segment, dans la liste que désignent les autres.
     *
     * @param  array<string, mixed>  $quete
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private function retirer(array $quete, array $segments, string $chemin): array
    {
        $indice = $segments[count($segments) - 1];
        $conteneur = array_slice($segments, 0, -1);

        return $this->au($quete, $conteneur, $chemin, static function (mixed $liste) use ($chemin, $indice): array {
            if (! is_array($liste) || ! array_is_list($liste)) {
                throw new ErreurConstructeur('retirer vise un élément de liste (par exemple « monstres.0 »), et « '.$chemin.' » n\'en désigne pas.');
            }
            if (! ctype_digit($indice) || (int) $indice >= count($liste)) {
                throw new ErreurConstructeur('le chemin « '.$chemin.' » ne désigne pas un élément : cette liste en a '.count($liste).'.');
            }
            unset($liste[(int) $indice]);

            return array_values($liste);
        });
    }

    /**
     * Rend l'arbre `$noeud` où le nœud désigné par `$segments` a été remplacé par le résultat de `$action`. Chaque segment
     * doit exister : la descente lève une erreur nommée sinon, avant toute écriture.
     *
     * @param  list<string>  $segments
     * @param  Closure(mixed): mixed  $action
     *
     * @throws ErreurConstructeur
     */
    private function au(mixed $noeud, array $segments, string $chemin, Closure $action): mixed
    {
        if ($segments === []) {
            return $action($noeud);
        }
        if (! is_array($noeud)) {
            throw new ErreurConstructeur('le chemin « '.$chemin.' » traverse une valeur qui n\'a pas de champs.');
        }

        $cle = self::cleDans($noeud, $segments[0], $chemin);
        $noeud[$cle] = $this->au($noeud[$cle], array_slice($segments, 1), $chemin, $action);

        return $noeud;
    }

    /**
     * @param  array<array-key, mixed>  $noeud
     *
     * @throws ErreurConstructeur
     */
    private static function cleDans(array $noeud, string $segment, string $chemin): int|string
    {
        if (array_is_list($noeud)) {
            if (ctype_digit($segment) && (int) $segment < count($noeud)) {
                return (int) $segment;
            }

            throw new ErreurConstructeur('le chemin « '.$chemin.' » ne désigne pas un élément : cette liste en a '.count($noeud).'.');
        }
        if (array_key_exists($segment, $noeud)) {
            return $segment;
        }

        throw new ErreurConstructeur('le chemin « '.$chemin.' » n\'existe pas dans la quête (pas de champ « '.$segment.' »).');
    }
}
