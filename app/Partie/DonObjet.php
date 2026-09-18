<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Inventaire;
use App\Models\Personnage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Don d'un objet entre deux héros du groupe, AU HUB (doc 01 §7).
 *
 * Comblait le dernier trou d'inventaire du projet : rien ne permettait de
 * transmettre une pièce à un allié. Un artefact tombé au mauvais héros restait
 * inutilisable à vie, et une potion trouvée par le barbare ne pouvait pas
 * rejoindre le magicien.
 *
 * **L'objet appartient au GROUPE**, pas au héros qui l'a ramassé (décision de
 * René) : même une arme `unique` circule. Elle reste invendable et inforgeable
 * — donner n'est pas vendre.
 *
 * Deux régimes, symétriques de {@see RangementObjet} :
 *  - **consommable** → on décrémente la pile du donneur et on empile chez le
 *    receveur (une potion identique fusionne avec la sienne) ;
 *  - **tout le reste** → on **DÉPLACE la ligne** (`personnage_id`), on n'en
 *    recrée pas une. C'est essentiel : la ligne porte ses `ameliorations` de
 *    Forge, qu'un create/delete perdrait silencieusement — le héros recevrait
 *    une épée ordinaire au lieu de l'épée Affûtée qu'on lui a tendue.
 *
 * Cette mécanique vit désormais dans {@see self::transferer()}, appelée par
 * `donner()` (chemin unitaire, hub) ET par {@see SeanceEchange} (chemin
 * multiple et bidirectionnel, en quête) : UN SEUL point de passage pour le
 * mouvement lui-même, que le contrôle qui l'entoure soit unitaire ou net.
 */
final class DonObjet
{
    /**
     * Transfère `$quantite` exemplaires de `$ligne` vers `$receveur`.
     *
     * Le donneur peut avoir le sac EN DÉPASSEMENT (un butin de quête passe
     * outre la capacité) : donner est justement la façon de régulariser. Le
     * receveur, lui, est vérifié strictement — sinon on déplacerait le problème.
     */
    public function donner(Personnage $donneur, Inventaire $ligne, Personnage $receveur, int $quantite = 1): void
    {
        $objet = $ligne->objet;

        if ($objet === null || (int) $ligne->personnage_id !== (int) $donneur->id) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Cet objet n\'est pas dans l\'inventaire de ce héros.',
            ]);
        }

        if ((int) $donneur->id === (int) $receveur->id) {
            throw ValidationException::withMessages([
                'vers_personnage_id' => 'Ce héros possède déjà cet objet.',
            ]);
        }

        // Une pièce PORTÉE ne se donne pas directement : la déséquiper d'abord
        // révoque proprement ses dés chez le donneur (Equipement::desequiper),
        // au lieu de les lui laisser en douce.
        if (in_array($ligne->emplacement, Equipement::SLOTS, true)) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » est équipé : déséquipe-le avant de le donner.",
            ]);
        }

        $quantite = max(1, $quantite);

        if ($quantite > (int) $ligne->quantite) {
            throw ValidationException::withMessages([
                'quantite' => 'Ce héros n\'en possède pas autant.',
            ]);
        }

        if (! RangementObjet::peutRanger($receveur, $objet, $quantite)) {
            // Tourné sans pronom : le genre d'un héros n'est nulle part en base,
            // et « il » se trompait une fois sur deux.
            throw ValidationException::withMessages([
                'vers_personnage_id' => "Le sac de {$receveur->nom} est plein : « {$objet->nom} » n'y tient pas.",
            ]);
        }

        DB::transaction(fn () => $this->transferer($ligne, $receveur, $quantite));
    }

    /**
     * La MÉCANIQUE pure du transfert — extraite de `donner()` le 2026-09-17
     * pour que la SÉANCE d'échange en quête ({@see SeanceEchange}) puisse
     * l'appeler PLUSIEURS fois (un mouvement par pièce, dans les deux sens)
     * sous UNE SEULE validation déjà faite sur l'ÉTAT FINAL des deux sacs :
     * deux sacs pleins qui échangent deux armures est légal au canon, et
     * pourtant aucun contrôle pièce par pièce (`peutRanger()` avant chaque
     * mouvement) ne le laisserait passer, dans quelque ordre que ce soit.
     *
     * ⚠ Ni contrôle de propriété ni de capacité ici — c'est tout l'intérêt de
     * l'extraction : `donner()` reste le chemin UNITAIRE qui les fait (don au
     * hub), la séance les fait à son échelle à elle (le NET des deux sacs)
     * puis appelle CETTE méthode pour chaque mouvement. Ne jamais dupliquer
     * la pile fusionnée / ligne déplacée ci-dessous — c'est précisément ce
     * qui préserve les `ameliorations` de Forge.
     */
    public function transferer(Inventaire $ligne, Personnage $receveur, int $quantite = 1): void
    {
        $objet = $ligne->objet;
        $quantite = max(1, $quantite);

        if ($objet->emplacement === 'consommable') {
            // Pile : on retire au donneur, on empile chez le receveur.
            if ((int) $ligne->quantite <= $quantite) {
                $ligne->delete();
            } else {
                $ligne->decrement('quantite', $quantite);
            }

            RangementObjet::ranger($objet, (int) $receveur->id, $quantite);

            return;
        }

        // Non consommable : la LIGNE change de propriétaire, améliorations
        // de Forge comprises. Elle atterrit au sac — jamais équipée d'office.
        $ligne->update(['personnage_id' => $receveur->id, 'emplacement' => 'sac']);
    }
}
