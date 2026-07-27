<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\ForgeAmelioration;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Personnage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Forge du Nain (nœud d'arbre, doc 01 §6 + doc 04 §4) : améliore
 * DÉFINITIVEMENT un exemplaire d'équipement (`inventaire.ameliorations`),
 * réalisée AU HUB contre de l'or de la bourse commune.
 *
 * Périmètre MVP : seules les 2 améliorations dont l'effet a un sens mécanique
 * déjà câblé (Equipement::appliquerEffet lit `ameliorations`) sont
 * applicables ici — Affûtée (`bonus_des_attaque`) et Renforcée
 * (`bonus_des_defense`). Les 4 autres du catalogue (Perforante, Cruelle,
 * Allégée, Gardée — `annule_boucliers_defense`, `relance_de_attaque_rate`,
 * `annule_malus_deplacement`, `ignore_premier_etat_du_combat`) exigent des
 * mécaniques de combat qui n'existent pas encore dans le moteur : elles
 * restent au catalogue mais sont refusées ici (chantier ouvert, à ne pas
 * confondre avec un bug — voir mémoire projet).
 */
final class Forge
{
    /** Clés d'effet de ForgeAmelioration déjà lues par Equipement::appliquerEffet. */
    private const EFFETS_SUPPORTES = ['bonus_des_attaque', 'bonus_des_defense'];

    /**
     * Applique une amélioration à une ligne d'inventaire (arme/armure non
     * Unique, jamais déjà améliorée), débite la bourse commune du groupe.
     */
    public function appliquer(Groupe $groupe, Inventaire $ligne, ForgeAmelioration $amelioration): Inventaire
    {
        $objet = $ligne->objet;

        if ($objet === null || $objet->categorie !== $amelioration->cible) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$amelioration->nom} » ne peut être appliquée qu'à une pièce de catégorie {$amelioration->cible}.",
            ]);
        }

        if ($objet->rarete === 'unique') {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Un artefact (rareté Unique) ne peut pas être amélioré par la Forge.',
            ]);
        }

        if (($ligne->ameliorations ?? []) !== []) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » a déjà été amélioré : un objet ne l'est qu'une fois.",
            ]);
        }

        $clesEffet = array_keys($amelioration->effet);
        if (array_diff($clesEffet, self::EFFETS_SUPPORTES) !== []) {
            throw ValidationException::withMessages([
                'amelioration_id' => "« {$amelioration->nom} » n'est pas encore disponible : sa mécanique de combat reste à implémenter.",
            ]);
        }

        if ((int) $groupe->or < $amelioration->prix) {
            throw ValidationException::withMessages([
                'amelioration_id' => 'La bourse commune ne couvre pas le prix de cette amélioration.',
            ]);
        }

        return DB::transaction(function () use ($groupe, $ligne, $amelioration, $objet) {
            $groupe->decrement('or', $amelioration->prix);

            $ligne->update(['ameliorations' => [[
                'nom' => $amelioration->nom,
                'effet' => $amelioration->effet,
            ]]]);

            // Déjà équipé : l'amélioration s'applique immédiatement aux colonnes
            // du porteur (même patron qu'un équipement initial — Equipement).
            if (in_array($ligne->emplacement, Equipement::SLOTS, true)) {
                $personnage = $ligne->personnage;
                foreach (self::EFFETS_SUPPORTES as $cle) {
                    $colonne = $cle === 'bonus_des_attaque' ? 'des_attaque' : 'des_defense';
                    $delta = (int) ($amelioration->effet[$cle] ?? 0);
                    if ($delta !== 0) {
                        $personnage->update([$colonne => (int) $personnage->{$colonne} + $delta]);
                    }
                }
            }

            return $ligne->fresh();
        });
    }
}
