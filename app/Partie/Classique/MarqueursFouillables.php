<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Grille;
use Illuminate\Validation\ValidationException;

/**
 * LES MARQUEURS FOUILLABLES — lot E2, *First Light* q. 1 (FL-Q p. 11) : « Ambushed Scouts – Skull tiles represent fallen scouts.
 * They do not affect hero movement. A hero can use an action to search an adjacent scout. They roll one red die. 1-2: They find
 * nothing. 3-4: They find 15 gold coins. 5-6: They find 40 gold coins. The first time a scout is searched, read the following
 * aloud […]. After a scout has been searched, remove the tile from the board. »
 *
 * `quete.marqueurs {cases: [{x, y}], libelle, alors}` : des tuiles sans obstacle, dessinées sur la carte (`carte.marqueurs`, hors
 * brouillard). Un héros au CONTACT d'un marqueur encore là peut le fouiller pour son action (`fouiller_marqueur_{x}_{y}`) ; les
 * effets se jouent comme ceux d'une note (un `jet`, un `en_ordre` pour « the first time »), puis le marqueur disparaît. État
 * durable : `quetes.drapeaux._marqueurs_fouilles` (`["x,y", …]`).
 */
final class MarqueursFouillables
{
    private const ETAT = '_marqueurs_fouilles';

    /** Les marqueurs encore sur le plateau. @return list<array{x: int, y: int}> */
    public static function restants(Quete $quete): array
    {
        if (! $quete->estConstruite()) {
            return [];
        }
        $fouilles = (array) data_get($quete->drapeaux, self::ETAT, []);

        return array_values(array_filter(array_map(fn ($c) => ['x' => (int) $c['x'], 'y' => (int) $c['y']],
            (array) data_get($quete->documentConstruit(), 'marqueurs.cases', [])),
            fn (array $c) => ! in_array("{$c['x']},{$c['y']}", $fouilles, true)));
    }

    /** Le libellé de l'action, de l'auteur. */
    public static function libelle(Quete $quete): string
    {
        return (string) data_get($quete->documentConstruit(), 'marqueurs.libelle', 'Fouiller');
    }

    /** Les marqueurs que ce héros touche (contact orthogonal). @return list<array{x: int, y: int}> */
    public function aPortee(Quete $quete, EtatPersonnageQuete $etat): array
    {
        if ($etat->tombe || $etat->position_x === null) {
            return [];
        }

        return array_values(array_filter(self::restants($quete), fn (array $c) => Grille::sontAdjacentes(
            (int) $etat->position_x, (int) $etat->position_y, $c['x'], $c['y'], carte: $quete->carte)
            || ((int) $etat->position_x === $c['x'] && (int) $etat->position_y === $c['y'])));
    }

    /** @return array<string, mixed> le résultat moteur `fouille_marqueur` */
    public function fouiller(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $x, int $y): array
    {
        if (! in_array(['x' => $x, 'y' => $y], $this->aPortee($quete, $etat), true)) {
            throw ValidationException::withMessages(['option_id' => 'Rien à fouiller ici.']);
        }
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::ETAT] = array_values(array_unique([...(array) ($drapeaux[self::ETAT] ?? []), "{$x},{$y}"]));
        $quete->update(['drapeaux' => $drapeaux]);
        $note = app(NotesDeQuete::class)->jouerEffets($quete->fresh(), (array) data_get($quete->documentConstruit(), 'marqueurs.alors', []), 'marqueur', $heros);

        return ['type' => 'fouille_marqueur', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom,
            'libelle' => self::libelle($quete), 'x' => $x, 'y' => $y, 'note' => $note];
    }
}
