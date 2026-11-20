<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * « Quelle salle contient cette case ? » — la seule réponse du projet.
 *
 * ⚠ La question était posée à QUATRE endroits, chacun avec sa propre boucle :
 * `DemarreurQuete` (pour révéler la salle 0), `AssembleurCarte` (pour placer
 * pièges et épreuves), une closure du brouillard dans `EtatGroupe`, et le filtre
 * des leviers. Elles disaient toutes la même chose, ce qui est précisément le
 * problème : une salle est un rectangle, la règle est trop simple pour qu'on
 * remarque qu'une copie a dérivé — un `<=` au lieu d'un `<` sur un seul des
 * quatre sites, et une case de bord change de salle selon qui la regarde.
 *
 * Le rectangle est en coordonnées de coin haut-gauche + dimensions, comme la
 * grille les stocke ; la borne haute est EXCLUE.
 */
final class Salles
{
    /**
     * Index de la salle contenant (x, y), ou `null` pour un couloir.
     *
     * ⚠ `null` n'est pas une erreur : un couloir n'appartient à aucune salle,
     * et c'est ce qui distingue « hors de toute salle » de « salle 0 ».
     *
     * @param  array<int|string, array{x: int|string, y: int|string, largeur: int|string, hauteur: int|string}>  $salles
     */
    public static function indexDe(array $salles, int $x, int $y): ?int
    {
        foreach ($salles as $i => $salle) {
            if (self::contientCase($salle, $x, $y)) {
                return (int) $i;
            }
        }

        return null;
    }

    /**
     * LES RECTANGLES D'UNE SALLE — le sien, puis ses `parties` (René, 2026-11-19 : « respecte le plan du board »). Le
     * plateau officiel a une salle EN L (au sud-est : x 17-20 sur y 10-12, et x 18-20 sur y 13) ; une salle d'un plateau
     * construit peut donc porter des rectangles de plus. Accepte les deux formes : le moteur (`largeur`/`hauteur`) et le
     * document du constructeur (`l`/`h`). Une carte générée n'a jamais de `parties` : un seul rectangle, comme avant.
     *
     * @param  array<string, mixed>  $salle
     * @return list<array{x: int, y: int, l: int, h: int}>
     */
    public static function rectangles(array $salle): array
    {
        $rectangle = static fn (array $r): array => [
            'x' => (int) $r['x'], 'y' => (int) $r['y'],
            'l' => (int) ($r['largeur'] ?? $r['l'] ?? 0), 'h' => (int) ($r['hauteur'] ?? $r['h'] ?? 0),
        ];

        return [$rectangle($salle), ...array_map($rectangle, array_values((array) ($salle['parties'] ?? [])))];
    }

    /**
     * La case (x, y) est-elle dans CETTE salle (un de ses rectangles) ? Le seul test « case dans une salle » : tout
     * lecteur qui parcourait `x + largeur` à la main passe par ici, sans quoi la partie en L d'une salle serait un
     * couloir pour lui.
     *
     * @param  array<string, mixed>  $salle
     */
    public static function contientCase(array $salle, int $x, int $y): bool
    {
        foreach (self::rectangles($salle) as $r) {
            if ($x >= $r['x'] && $x < $r['x'] + $r['l'] && $y >= $r['y'] && $y < $r['y'] + $r['h']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Toutes les cases d'une salle, rectangle principal d'abord, ligne par ligne.
     *
     * @param  array<string, mixed>  $salle
     * @return list<array{x: int, y: int}>
     */
    public static function cases(array $salle): array
    {
        $cases = [];
        foreach (self::rectangles($salle) as $r) {
            for ($y = $r['y']; $y < $r['y'] + $r['h']; $y++) {
                for ($x = $r['x']; $x < $r['x'] + $r['l']; $x++) {
                    $cases[] = ['x' => $x, 'y' => $y];
                }
            }
        }

        return $cases;
    }

    /**
     * La case (x, y) est-elle dans une salle DÉCOUVERTE, ou dans un couloir ? Point de passage unique de « jamais dans une
     * zone non découverte » : lu par les éthérés et les figures de deux cases qui cherchent où s'arrêter
     * (`ResolveurTour::salleDecouverte()`), et par la place d'une forme qui grandit (`MoteurSynchroformes::ancrer()`).
     *
     * Un couloir (`indexDe()` = `null`) n'est jamais « non découvert » ; la salle de départ l'est toujours (`Quete::sallesDecouvertes()`).
     *
     * @param  array<int|string, array{x: int|string, y: int|string, largeur: int|string, hauteur: int|string}>  $salles
     * @param  list<int>  $decouvertes
     */
    public static function decouverte(array $salles, array $decouvertes, int $x, int $y): bool
    {
        $index = self::indexDe($salles, $x, $y);

        return $index === null || in_array($index, $decouvertes, true);
    }

    /**
     * Vrai si (x, y) tombe dans la salle d'index donné.
     *
     * @param  array<int|string, array{x: int|string, y: int|string, largeur: int|string, hauteur: int|string}>  $salles
     */
    public static function contient(array $salles, int $index, int $x, int $y): bool
    {
        return self::indexDe($salles, $x, $y) === $index;
    }

    /**
     * Les cases du COULOIR où se tient (x, y) : le réseau de cases hors salle, borné par la roche et les portes
     * closes (`Grille::areteBloquee()`), départ compris. Point de passage unique de « le même couloir » : lu par
     * les sorts de zone du MJ (`MoteurDread::casesDuCouloir()` — Nuée d'Effroi, Souffle du Construit) et par le
     * fardeau du porteur d'un talisman maudit (`MoteurFardeau`). Deux copies de cette inondation auraient divergé.
     *
     * @param  array<int|string, array{x: int|string, y: int|string, largeur: int|string, hauteur: int|string}>  $salles
     * @return list<array{x: int, y: int}>
     */
    public static function couloirDe(array $salles, Grille $grille, int $x, int $y): array
    {
        $depart = ['x' => $x, 'y' => $y];
        $vues = [$x.':'.$y => true];
        $file = [$depart];
        $cases = [$depart];

        while ($file !== []) {
            $courante = array_shift($file);

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $courante['x'] + $dx;
                $ny = $courante['y'] + $dy;
                $cle = $nx.':'.$ny;

                if (isset($vues[$cle])
                    || $grille->estRoche($nx, $ny)
                    || $grille->areteBloquee($courante['x'], $courante['y'], $nx, $ny)
                    || self::indexDe($salles, $nx, $ny) !== null) {
                    continue;
                }

                $vues[$cle] = true;
                $file[] = ['x' => $nx, 'y' => $ny];
                $cases[] = ['x' => $nx, 'y' => $ny];
            }
        }

        return $cases;
    }
}
