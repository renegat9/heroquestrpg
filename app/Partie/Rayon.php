<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\InstanceMonstre;
use App\Models\Quete;

/**
 * La LIGNE d'un rayon : les cases qu'il parcourt, et ce qui s'y tient.
 *
 * Trois lecteurs la demandent — le résolveur du Moine (*Esprit Ardent*), le
 * menu qui affiche son cadran de visée, et le parchemin d'*Éclair*. Elle était
 * écrite deux fois quand ils n'étaient que deux : `ResolveurTour::casesDuRayon()`
 * et `MenuMoteur::directionsDeRayon()` marchaient la même ligne chacun de son
 * côté, avec le commentaire « le résolveur recalcule » pour toute garantie. En
 * ajouter une troisième aurait fait du menu un menteur au premier ajustement —
 * il annonce des ennemis touchés, et c'est le résolveur qui frappe.
 *
 * Les deux règles de la ligne viennent du même texte, mot pour mot sur la carte
 * du Moine comme sur le parchemin : « This beam may be **straight or diagonal**
 * and continues until it meets a **wall or closed door**. » Les figures ne
 * l'arrêtent pas — elles la subissent.
 */
final class Rayon
{
    /**
     * Les huit directions : « straight or diagonal ».
     *
     * @var array<string, array{0: int, 1: int, 2: string}>
     */
    public const DIRECTIONS = [
        'n' => [0, -1, 'au nord'], 's' => [0, 1, 'au sud'],
        'e' => [1, 0, "à l'est"], 'o' => [-1, 0, "à l'ouest"],
        'ne' => [1, -1, 'au nord-est'], 'no' => [-1, -1, 'au nord-ouest'],
        'se' => [1, 1, 'au sud-est'], 'so' => [-1, 1, 'au sud-ouest'],
    ];

    /**
     * Les cases parcourues depuis (x, y), du premier pas jusqu'au mur ou à la
     * porte close — la case de départ EXCLUE : on ne se foudroie pas soi-même.
     *
     * @return list<array{x: int, y: int}>
     */
    public static function cases(Grille $grille, int $x, int $y, string $direction): array
    {
        if (! isset(self::DIRECTIONS[$direction])) {
            return [];
        }

        [$dx, $dy] = self::DIRECTIONS[$direction];
        $cases = [];

        while (true) {
            $sx = $x + $dx;
            $sy = $y + $dy;

            if ($grille->estRoche($sx, $sy) || $grille->porteBloqueEntre($x, $y, $sx, $sy)) {
                return $cases;
            }

            $cases[] = ['x' => $sx, 'y' => $sy];
            $x = $sx;
            $y = $sy;
        }
    }

    /**
     * Cadran de visée : par direction, combien de monstres et quels héros se
     * tiennent sur la ligne.
     *
     * ⚠ Les héros sont RENDUS, pas filtrés. L'*Esprit Ardent* ne touche que les
     * ennemis et les ignore ; l'*Éclair*, lui, frappe « all heroes or monsters
     * that stand in its path », et le joueur doit voir qui il va griller AVANT
     * de choisir sa direction — un effet automatique que rien n'annonce est
     * injouable.
     *
     * @return array<string, array{monstres: int, heros: list<string>}>
     */
    public static function cadran(Quete $quete, int $x, int $y): array
    {
        $grille = FabriqueGrille::pour($quete);

        $monstres = $quete->instancesMonstres()
            ->where('etat', 'actif')->where('revele', true)->with('monstre')->get();

        $heros = $quete->etatsPersonnages()->where('tombe', false)->with('personnage')->get();

        $cadran = [];

        foreach (array_keys(self::DIRECTIONS) as $code) {
            $nbMonstres = 0;
            $noms = [];

            foreach (self::cases($grille, $x, $y, $code) as $case) {
                $nbMonstres += $monstres->filter(function (InstanceMonstre $i) use ($case) {
                    $e = $i->monstre->emprise();

                    return $i->position_x !== null
                        && $case['x'] >= (int) $i->position_x && $case['x'] < (int) $i->position_x + $e['l']
                        && $case['y'] >= (int) $i->position_y && $case['y'] < (int) $i->position_y + $e['h'];
                })->count();

                foreach ($heros as $etat) {
                    if ((int) $etat->position_x === $case['x'] && (int) $etat->position_y === $case['y']
                        && $etat->personnage !== null) {
                        $noms[] = (string) $etat->personnage->nom;
                    }
                }
            }

            $cadran[$code] = ['monstres' => $nbMonstres, 'heros' => array_values(array_unique($noms))];
        }

        return $cadran;
    }
}
