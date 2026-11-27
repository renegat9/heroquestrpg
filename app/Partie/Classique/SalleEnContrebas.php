<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use App\Partie\FabriqueGrille;
use App\Partie\Salles;

/**
 * LA SALLE EN CONTREBAS — lot E2, *Prophecy of Telor* q. 9 C (G0052 p. 13) : « This room is sunken down and can only be
 * accessed by a hero or monster moving on the stairs. Treat the stairs as a single square on which only one miniature may
 * be placed at a time. Any miniature on a gallery square can see any miniature in the altar room and vice versa. A hero may
 * jump into the room below but loses 1 Body Point. »
 *
 * `regles.salle_en_contrebas {salle, galerie: [{x, y, l, h}…]}` :
 * - l'ESCALIER est une porte ouverte de l'auteur entre sa case et la galerie (`portes[]`, `ouverte: true`) : c'est le seul
 *   passage, et une case ne porte qu'une figure — rien de neuf à jouer ;
 * - la VUE : la carte porte la couche `vues_croisees` (galerie ↔ salle, `CarteConstruite`), lue par `Grille::depuisCarte()` :
 *   tir, sort, figures — tout ce qui lit `ligneDeVue()` ;
 * - le SAUT : un héros sur une case de la galerie saute sur une case libre de la salle qui la touche par un côté (`sauts()`),
 *   le mouvement de son tour, et perd {@see DEGATS} point de Corps (`ResolveurTour::resoudreSautContrebas()`). Les monstres
 *   ne sautent pas (« A hero may jump »).
 *
 * Écart écrit : le saut prend tout le déplacement du tour, comme la descente dans une fosse ; l'action reste.
 */
final class SalleEnContrebas
{
    public const DEGATS = 1;

    /** @return array{salle: string, galerie: list<array{x: int, y: int, l: int, h: int}>}|null */
    public static function regle(Quete $quete): ?array
    {
        if (! $quete->estConstruite()) {
            return null;
        }
        $regle = data_get($quete->documentConstruit(), 'regles.salle_en_contrebas');

        return is_array($regle) && is_string($regle['salle'] ?? null) && is_array($regle['galerie'] ?? null) ? $regle : null;
    }

    /**
     * Les cases de la galerie, depuis les rectangles de la règle.
     *
     * @param  array<string, mixed>  $regle
     * @return list<array{x: int, y: int}>
     */
    public static function galerie(array $regle): array
    {
        $cases = [];
        foreach ((array) ($regle['galerie'] ?? []) as $r) {
            $cases = [...$cases, ...Salles::cases(['x' => (int) $r['x'], 'y' => (int) $r['y'], 'l' => (int) $r['l'], 'h' => (int) $r['h']])];
        }

        return array_values(array_unique($cases, SORT_REGULAR));
    }

    /**
     * Où ce héros peut sauter : les cases LIBRES de la salle en contrebas qui touchent par un côté la case de galerie où il
     * se tient. Vide hors de la galerie, ou sans place en bas. Lu par le menu ET par le résolveur.
     *
     * @return list<array{x: int, y: int}>
     */
    public function sauts(Quete $quete, EtatPersonnageQuete $etat): array
    {
        $regle = self::regle($quete);
        if ($regle === null || $etat->position_x === null || $etat->tombe) {
            return [];
        }
        $x = (int) $etat->position_x;
        $y = (int) $etat->position_y;
        if (! in_array(['x' => $x, 'y' => $y], self::galerie($regle), true)) {
            return [];
        }

        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $salle = collect($salles)->first(fn ($s) => ($s['id'] ?? null) === $regle['salle']);
        if ($salle === null) {
            return [];
        }
        $grille = FabriqueGrille::pour($quete, exceptPersonnageId: (int) $etat->personnage_id);
        $sauts = [];
        // Par-dessus le mur, en contrebas : les quatre voisines par un côté, jamais une diagonale.
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            if (Salles::contientCase($salle, $x + $dx, $y + $dy) && $grille->estTraversable($x + $dx, $y + $dy)) {
                $sauts[] = ['x' => $x + $dx, 'y' => $y + $dy];
            }
        }

        return $sauts;
    }
}
