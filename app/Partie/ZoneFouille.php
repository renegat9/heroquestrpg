<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * La ZONE qu'une fouille couvre : la salle ou le couloir où se tient le
 * fouilleur — en entier, sans rayon ni ligne de vue (René, 2026-09-27 : « la
 * fouille de piège ou de passage secret se fait seulement dans la salle ou
 * corridor actuel du joueur, sans tenir compte du line of sight »). C'est la
 * règle du livret : on fouille « the room or corridor you are in ».
 *
 * ⚠ Remplace un RAYON de 3 cases (Manhattan) filtré par la LIGNE DE VUE. La
 * vue avait été ajoutée le 2026-09-18 parce qu'une fouille révélait un piège
 * derrière une porte fermée ; la zone règle ce cas mieux qu'elle — ce qui est
 * derrière une porte appartient à une AUTRE salle ou à un couloir — sans
 * laisser un meuble haut ou un coin de salle cacher un piège de la pièce même.
 *
 * Une SALLE est son rectangle, mur compris (c'est là que sont percées les
 * embrasures). Un COULOIR est la composante connexe des cases de sol hors de
 * toute salle qui contient le fouilleur. Une porte appartient à la zone si
 * l'UNE de ses deux cases (`Grille::casesPorte()`) y tombe : on trouve un
 * passage secret des deux côtés du mur.
 *
 * ⚠ Le rectangle est testé salle par salle, pas par `Salles::indexDe()` : deux
 * salles accolées PARTAGENT leur mur, et `indexDe()` rend la première — une
 * porte dans ce mur serait alors introuvable depuis l'autre salle.
 */
final class ZoneFouille
{
    /**
     * @param  array{x: int, y: int, largeur: int, hauteur: int}|null  $salle
     * @param  array<string, true>  $couloir  cases « x,y » (vide pour une salle)
     */
    private function __construct(
        private readonly ?array $salle,
        private readonly array $couloir,
    ) {}

    /**
     * @param  array<string, mixed>  $grille  `cartes.grille` (cases + salles)
     */
    public static function de(array $grille, int $x, int $y): self
    {
        $salles = (array) ($grille['salles'] ?? []);
        $index = Salles::indexDe($salles, $x, $y);

        if ($index !== null) {
            $s = $salles[$index];

            return new self(
                ['x' => (int) $s['x'], 'y' => (int) $s['y'], 'largeur' => (int) $s['largeur'], 'hauteur' => (int) $s['hauteur']],
                [],
            );
        }

        // Couloir : flot depuis le fouilleur sur le sol hors de toute salle.
        $cases = (array) ($grille['cases'] ?? []);
        $couloir = [];
        $file = [[$x, $y]];

        while ($file !== []) {
            [$cx, $cy] = array_pop($file);
            $cle = "{$cx},{$cy}";

            if (isset($couloir[$cle]) || ($cases[$cy][$cx] ?? 'm') === 'm'
                || Salles::indexDe($salles, $cx, $cy) !== null) {
                continue;
            }

            $couloir[$cle] = true;
            $file[] = [$cx + 1, $cy];
            $file[] = [$cx - 1, $cy];
            $file[] = [$cx, $cy + 1];
            $file[] = [$cx, $cy - 1];
        }

        return new self(null, $couloir);
    }

    /**
     * Cases du COULOIR (vide pour une salle) — Hurricane Trap (Wizards of
     * Morcar) en a besoin pour déterminer l'AXE du couloir (le sens du recul)
     * et ses bornes ; aucun autre consommateur de cette classe n'a besoin de
     * sortir du simple test `contient()`, d'où cet accesseur à part plutôt
     * qu'un champ public.
     *
     * @return list<array{x: int, y: int}>
     */
    public function cellulesCouloir(): array
    {
        if ($this->salle !== null) {
            return [];
        }

        return array_map(function (string $cle) {
            [$x, $y] = array_map('intval', explode(',', $cle));

            return ['x' => $x, 'y' => $y];
        }, array_keys($this->couloir));
    }

    public function contient(int $x, int $y): bool
    {
        if ($this->salle !== null) {
            return $x >= $this->salle['x'] && $x < $this->salle['x'] + $this->salle['largeur']
                && $y >= $this->salle['y'] && $y < $this->salle['y'] + $this->salle['hauteur'];
        }

        return isset($this->couloir["{$x},{$y}"]);
    }

    /** Une porte est dans la zone si l'une de ses deux cases y tombe. */
    public function contientPorte(array $porte): bool
    {
        foreach (Grille::casesPorte($porte) as $case) {
            if ($this->contient($case['x'], $case['y'])) {
                return true;
            }
        }

        return false;
    }
}
