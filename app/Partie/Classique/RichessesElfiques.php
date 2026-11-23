<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\MotsClesQuete;
use App\Models\Quete;
use App\Partie\MoteurMobilier;

/**
 * LES RICHESSES ELFIQUES (mode classique, lot E2 — *The Mage of the Mirror* p. 5, règle 5) : « If a quest in this book
 * does not specifically call out a reward (or lack thereof) for a room containing a treasure chest then the first time
 * that room is searched for treasure award that Hero 200 gold coins. »
 *
 * `regles.richesses_des_coffres: N` (pièces d'or). Lu par la fouille du trésor (`ResolveurTour`), APRÈS les notes : une
 * salle dont une note de l'auteur dit la fouille du trésor (récompense ou « nothing ») ne paie jamais — même jouée,
 * même sous un drapeau absent : c'est le livret qui « call out » la salle, pas l'état du jeu. Sinon, la PREMIÈRE fouille
 * de la salle par qui que ce soit, dans une salle où l'auteur a posé un Coffre, verse N pièces au fouilleur — à la place
 * de la carte, comme une note.
 */
final class RichessesElfiques
{
    public function __construct(private readonly MoteurMobilier $mobilier) {}

    /** Les pièces que cette fouille verse, ou `null` (la carte se tire). */
    public function montant(Quete $quete, int $salle, bool $premiereFouilleDeLaSalle): ?int
    {
        $montant = (int) data_get($quete->documentConstruit(), 'regles.richesses_des_coffres', 0);
        if ($montant <= 0 || ! $premiereFouilleDeLaSalle || ! $quete->estConstruite() || $quete->carte === null
            || ! $this->mobilier->salleContientType($quete->carte, $salle, 'Coffre')) {
            return null;
        }
        $id = data_get($quete->carte->grille, "salles.{$salle}.id");
        foreach ((array) data_get($quete->documentConstruit(), 'notes', []) as $note) {
            if (data_get($note, 'quand.declencheur') === MotsClesQuete::FOUILLE_TRESOR && data_get($note, 'quand.cible.salle') === $id) {
                return null;
            }
        }

        return $montant;
    }
}
