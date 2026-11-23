<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\MotsClesQuete;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Grille;

/**
 * LE RÉCEPTACLE OÙ L'ON JETTE UN OBJET (mode classique, lot E2 — *Against the Ogre Horde* q. 8 : « The heroes cannot exit
 * the quest until the Pit of Dread has been destroyed. They do so by throwing Festral's Ring of Power into the Pit »).
 *
 * `regles.receptacle_objets: [{x, y}]` — les cases de la Fosse. Un héros qui se tient sur l'une d'elles ou à son contact
 * (`Grille::sontAdjacentes()`, murs compris), et qui porte un objet qu'une note attend (`objet_jete`, cible `objet`), peut
 * l'y jeter : l'objet quitte son sac et la note joue (le drapeau, la sortie qui s'ouvre). Le menu ne l'offre que pour un
 * objet attendu ; le résolveur relit la même liste ({@see jetables()}).
 */
final class ReceptacleObjets
{
    public function __construct(private readonly NotesDeQuete $notes) {}

    /**
     * Les lignes du sac que ce héros peut jeter là où il se tient.
     *
     * @return list<array{ligne: Inventaire, slug: string}>
     */
    public function jetables(Quete $quete, Personnage $heros, ?EtatPersonnageQuete $etat): array
    {
        $cases = (array) data_get($quete->documentConstruit(), 'regles.receptacle_objets', []);
        if ($cases === [] || ! $quete->estConstruite() || $etat === null || $etat->position_x === null || $etat->tombe) {
            return [];
        }
        $x = (int) $etat->position_x;
        $y = (int) $etat->position_y;
        $aPortee = collect($cases)->contains(fn ($c) => ((int) $c['x'] === $x && (int) $c['y'] === $y)
            || Grille::sontAdjacentes((int) $c['x'], (int) $c['y'], $x, $y, carte: $quete->carte));
        if (! $aPortee) {
            return [];
        }

        return $heros->inventaire()->with('objet')->get()
            ->filter(fn (Inventaire $l) => $l->objet !== null && app(Equipement::class)->retirable($l))
            ->map(fn (Inventaire $l) => ['ligne' => $l, 'slug' => CatalogueConstructeur::slug((string) $l->objet->nom)])
            ->filter(fn (array $j) => $this->notes->attend($quete, MotsClesQuete::OBJET_JETE, ['objet' => $j['slug']], $heros))
            ->unique('slug')->values()->all();
    }

    /** @return array{type: string, nom: string, objet: string}|null `null` : cet objet ne se jette pas ici */
    public function jeter(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $inventaireId): ?array
    {
        $jet = collect($this->jetables($quete, $heros, $etat))->first(fn (array $j) => (int) $j['ligne']->id === $inventaireId);
        if ($jet === null) {
            return null;
        }
        $nom = (string) $jet['ligne']->objet->nom;
        if ((int) $jet['ligne']->quantite > 1) {
            $jet['ligne']->decrement('quantite');
        } else {
            $jet['ligne']->delete();
        }
        $this->notes->signaler($quete, MotsClesQuete::OBJET_JETE, ['objet' => $jet['slug']], $heros);

        return ['type' => 'objet_jete', 'nom' => $heros->nom, 'objet' => $nom];
    }
}
