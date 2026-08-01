<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\Marche\CapaciteSac;

/**
 * Rangement d'un objet dans l'inventaire d'un héros — extrait de
 * `PhaseMarche::rangerAchat()` pour être partagé avec le butin de fouille
 * (`ResolveurTour::resoudreFouilleTresor`), jusque-là incapable de donner le
 * moindre objet : achat et restauration de snapshot étaient les deux SEULS
 * chemins de création d'une ligne d'inventaire du projet.
 *
 * Deux régimes, inchangés :
 *  - **consommable** → s'empile sur la ligne existante (`quantite`), et ne
 *    compte JAMAIS dans la capacité du sac (doc 01 §7) ;
 *  - **tout le reste** → une ligne par exemplaire au SAC, pour que chaque
 *    pièce porte ses propres `ameliorations` de Forge.
 */
final class RangementObjet
{
    /**
     * Range `$quantite` exemplaires et rend la ligne touchée (la DERNIÈRE créée
     * pour un non-consommable).
     */
    public static function ranger(Objet $objet, int $personnageId, int $quantite = 1): Inventaire
    {
        $quantite = max(1, $quantite);

        if ($objet->emplacement === 'consommable') {
            $ligne = Inventaire::query()
                ->where('personnage_id', $personnageId)
                ->where('objet_id', $objet->id)
                ->where('emplacement', 'consommable')
                ->first();

            if ($ligne !== null) {
                $ligne->increment('quantite', $quantite);

                return $ligne->fresh();
            }

            return Inventaire::create([
                'personnage_id' => $personnageId,
                'objet_id' => $objet->id,
                'emplacement' => 'consommable',
                'quantite' => $quantite,
            ]);
        }

        $derniere = null;

        for ($i = 0; $i < $quantite; $i++) {
            $derniere = Inventaire::create([
                'personnage_id' => $personnageId,
                'objet_id' => $objet->id,
                'emplacement' => 'sac',
                'quantite' => 1,
            ]);
        }

        return $derniere;
    }

    /**
     * Le sac accueillerait-il ces exemplaires SANS dépasser la capacité ?
     * Toujours vrai pour un consommable (hors capacité).
     *
     * Sert à signaler un débordement, pas à l'interdire : un artefact trouvé
     * en quête est remis même sac plein (le héros peut l'équiper pour
     * régulariser, cf. le drapeau `sac_deborde` du payload de fouille).
     */
    public static function peutRanger(Personnage $personnage, Objet $objet, int $quantite = 1): bool
    {
        if ($objet->emplacement === 'consommable') {
            return true;
        }

        return CapaciteSac::occupation($personnage) + max(1, $quantite) <= CapaciteSac::pour($personnage);
    }
}
