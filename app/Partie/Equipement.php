<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\Marche\CapaciteSac;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Équiper / déséquiper une pièce d'équipement (doc 01 §7).
 *
 * Choix d'implémentation : les effets CHIFFRÉS de combat de l'objet
 * (`des_attaque`, `des_defense`) sont appliqués aux COLONNES du personnage à
 * l'équipement et révoqués au déséquipement — même patron que les nœuds de
 * compétence (App\Http\Controllers\Api\CompetenceController). Ainsi le moteur
 * de combat, la fiche (/moi), le score de puissance et le budget de rencontre
 * lisent tous automatiquement l'équipement via les colonnes, sans calcul
 * « effectif » dupliqué partout.
 *
 * Les autres propriétés d'un objet (jetable, attaque_diagonale, portee…) sont
 * des comportements de ciblage/portée, hors périmètre de ce service : elles ne
 * modifient pas les dés et seront lues à la volée par le moteur si/quand
 * elles sont implémentées. Exception : `necessite_maitrise_lourde` (armes
 * deux mains / armures lourdes) EST vérifiée ici — nœud barbare Maîtrise
 * lourde requis, sinon 422 (doc 01 §6).
 */
final class Equipement
{
    /** Emplacements « portés » (par opposition à sac / consommable). */
    public const SLOTS = ['arme_principale', 'arme_secondaire', 'armure'];

    /** Clés d'`effet` d'objet appliquées comme delta de colonne au personnage. */
    private const COLONNES = [
        'des_attaque' => 'des_attaque',
        'des_defense' => 'des_defense',
    ];

    /**
     * Clés d'`effet` d'amélioration de Forge (ForgeAmeliorationSeeder, préfixe
     * `bonus_` — même convention que CompetenceController::EFFETS_PASSIFS)
     * appliquées comme delta de colonne au personnage.
     */
    private const AMELIORATIONS_COLONNES = [
        'bonus_des_attaque' => 'des_attaque',
        'bonus_des_defense' => 'des_defense',
    ];

    /**
     * Équipe une ligne d'inventaire du SAC dans l'emplacement naturel de l'objet
     * (objet.emplacement). L'occupant actuel du slot repart au sac (auto-swap :
     * capacité de sac neutre, une pièce sort, une pièce entre).
     */
    public function equiper(Personnage $personnage, Inventaire $ligne): Inventaire
    {
        $objet = $this->objetDeLaLigne($personnage, $ligne);

        if ($ligne->emplacement !== 'sac') {
            throw ValidationException::withMessages(['inventaire_id' => 'Cet objet n\'est pas dans le sac.']);
        }

        $slot = $objet->emplacement;
        if (! in_array($slot, self::SLOTS, true)) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » n'est pas une pièce d'équipement.",
            ]);
        }

        $this->verifierMains($personnage, $objet);
        $this->verifierMaitriseLourde($personnage, $objet);

        return DB::transaction(function () use ($personnage, $ligne, $objet, $slot) {
            // Auto-swap : l'occupant actuel du slot retourne au sac (effet révoqué).
            $occupant = $personnage->inventaire()->where('emplacement', $slot)->with('objet')->first();
            if ($occupant !== null) {
                $this->appliquerEffet($personnage, $occupant->objet, $occupant->ameliorations ?? [], -1);
                $occupant->update(['emplacement' => 'sac']);
            }

            $ligne->update(['emplacement' => $slot]);
            $this->appliquerEffet($personnage->refresh(), $objet, $ligne->ameliorations ?? [], 1);

            return $ligne->fresh();
        });
    }

    /**
     * Déséquipe une pièce portée : elle retourne au sac (si la capacité le
     * permet) et son effet de combat est révoqué.
     */
    public function desequiper(Personnage $personnage, Inventaire $ligne): Inventaire
    {
        $objet = $this->objetDeLaLigne($personnage, $ligne);

        if (! in_array($ligne->emplacement, self::SLOTS, true)) {
            throw ValidationException::withMessages(['inventaire_id' => 'Cet objet n\'est pas équipé.']);
        }

        if (CapaciteSac::occupation($personnage) + 1 > CapaciteSac::pour($personnage)) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Sac plein : fais de la place avant de déséquiper.',
            ]);
        }

        return DB::transaction(function () use ($personnage, $ligne, $objet) {
            $this->appliquerEffet($personnage, $objet, $ligne->ameliorations ?? [], -1);
            $ligne->update(['emplacement' => 'sac']);

            return $ligne->fresh();
        });
    }

    /**
     * Incompatibilité main(s) (doc 01 §7) : une arme à deux mains et un bouclier
     * ne coexistent pas. Rejet explicite (pas d'auto-déséquipement croisé).
     */
    private function verifierMains(Personnage $personnage, Objet $aEquiper): void
    {
        $portes = $personnage->inventaire()->whereIn('emplacement', self::SLOTS)->with('objet')->get();
        $estDeuxMains = fn (?Objet $o) => (bool) ($o?->effet['deux_mains'] ?? false);
        $estBouclier = fn (?Objet $o) => (bool) ($o?->effet['incompatible_deux_mains'] ?? false);

        if ($estDeuxMains($aEquiper) && $portes->contains(fn ($l) => $estBouclier($l->objet))) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$aEquiper->nom} » se manie à deux mains — déséquipe d'abord ton bouclier.",
            ]);
        }

        if ($estBouclier($aEquiper) && $portes->contains(fn ($l) => $estDeuxMains($l->objet))) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Tu manies une arme à deux mains — impossible d\'y ajouter un bouclier.',
            ]);
        }
    }

    /**
     * Maîtrise lourde (nœud barbare, CompetenceSeeder) : les armes à deux mains
     * et armures lourdes du catalogue (`effet.necessite_maitrise_lourde`)
     * exigent ce nœud pour être équipées.
     */
    private function verifierMaitriseLourde(Personnage $personnage, Objet $objet): void
    {
        if (! (bool) ($objet->effet['necessite_maitrise_lourde'] ?? false)) {
            return;
        }

        $possede = $personnage->competences()->where('nom', 'Maîtrise lourde')->exists();

        if (! $possede) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » exige le nœud Maîtrise lourde du Barbare.",
            ]);
        }
    }

    /**
     * Applique (signe +1) ou révoque (signe −1) les deltas de combat de l'objet
     * ET de ses éventuelles améliorations de Forge (`inventaire.ameliorations`,
     * App\Partie\Forge) sur les colonnes du personnage. Jamais négatif (garde-fou).
     *
     * @param  list<array{nom: string, effet: array<string, mixed>}>  $ameliorations
     */
    private function appliquerEffet(Personnage $personnage, ?Objet $objet, array $ameliorations, int $signe): void
    {
        $parColonne = [];

        foreach (self::COLONNES as $cleEffet => $colonne) {
            $parColonne[$colonne] = ($parColonne[$colonne] ?? 0) + (int) ($objet?->effet[$cleEffet] ?? 0);
        }

        foreach (self::AMELIORATIONS_COLONNES as $cleEffet => $colonne) {
            foreach ($ameliorations as $amelioration) {
                $parColonne[$colonne] = ($parColonne[$colonne] ?? 0) + (int) ($amelioration['effet'][$cleEffet] ?? 0);
            }
        }

        $deltas = [];
        foreach ($parColonne as $colonne => $delta) {
            $delta *= $signe;
            if ($delta !== 0) {
                $deltas[$colonne] = max(0, (int) $personnage->{$colonne} + $delta);
            }
        }

        if ($deltas !== []) {
            $personnage->update($deltas);
        }
    }

    private function objetDeLaLigne(Personnage $personnage, Inventaire $ligne): Objet
    {
        if ($ligne->personnage_id !== $personnage->id || $ligne->objet === null) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Objet introuvable dans l\'inventaire de ce héros.',
            ]);
        }

        return $ligne->objet;
    }
}
