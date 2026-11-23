<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Bourses;
use App\Partie\Equipement;
use App\Partie\Marche\PhaseMarche;
use App\Partie\RangementObjet;
use App\Partie\Salles;

/**
 * LE MARCHÉ SOUTERRAIN EN PLEINE QUÊTE (mode classique, lot E2 — *Rise of the Dread Moon* p. 2 et q. 2 G, q. 8) : « A
 * long-time ally of Mentor, Zerese, has set up shop here, allowing the heroes immediate access to the Underground Market. »
 * « The Underground Market sells the new items listed below, all potions with gold coin values in the alchemy deck, and
 * all items available in the armory […] Heroes may sell items to the Underground Market […] half the item's listed cost
 * (rounded down). » Les livrets sont maîtres (René, 2026-11-23) : il faut pouvoir y acheter en quête.
 *
 * `regles.marche_en_quete: id de salle`. Le marché du hub est une phase de GROUPE (tous les paniers confirmés) : en pleine
 * quête, chaque héros de la salle achète et vend POUR LUI, de sa bourse, sans attendre les autres. Ce qu'on vend est
 * l'étal du hub, au même prix (`PhaseMarche::etal()`) ; ce qu'on revend suit les mêmes refus (hors marché, artefact,
 * pièce inamovible) et le même prix (`PhaseMarche::reventePour()`). Décisions écrites :
 *  - un geste LIBRE (« immediate access ») : ouvrir l'étal, acheter, vendre, le refermer ne coûtent aucun créneau ;
 *  - pas de stock en quête (le stock est celui d'une phase de hub) ;
 *  - l'étal ouvert est un état du TOUR du héros (`capacites_tour`) : un menu, pas un état de partie.
 * Le menu n'offre que ce que la bourse paie et que le sac peut ranger ; le résolveur relit les mêmes listes.
 */
final class MarcheSouterrain
{
    public const MARQUEUR = 'etal_ouvert';

    public function __construct(
        private readonly PhaseMarche $marche,
        private readonly Bourses $bourses,
        private readonly Equipement $equipement,
    ) {}

    /** Le héros se tient-il dans la salle du marché ? */
    public function present(Quete $quete, ?EtatPersonnageQuete $etat): bool
    {
        $id = $quete->estConstruite() ? data_get($quete->documentConstruit(), 'regles.marche_en_quete') : null;
        if (! is_string($id) || $etat === null || $etat->position_x === null || $etat->tombe || $quete->carte === null) {
            return false;
        }
        $index = Salles::indexDe((array) ($quete->carte->grille['salles'] ?? []), (int) $etat->position_x, (int) $etat->position_y);

        return $index !== null && data_get($quete->carte->grille, "salles.{$index}.id") === $id;
    }

    public function ouvert(?EtatPersonnageQuete $etat): bool
    {
        return $etat !== null && in_array(self::MARQUEUR, (array) ($etat->capacites_tour ?? []), true);
    }

    /** @return array{type: string} le résultat, `ouvrir_etal` ou `fermer_etal` */
    public function basculer(EtatPersonnageQuete $etat, bool $ouvert): array
    {
        $marqueurs = array_values(array_diff((array) ($etat->capacites_tour ?? []), [self::MARQUEUR]));
        $etat->update(['capacites_tour' => $ouvert ? [...$marqueurs, self::MARQUEUR] : $marqueurs]);

        return $ouvert ? ['type' => 'ouvrir_etal'] : ['type' => 'fermer_etal'];
    }

    /**
     * Ce que ce héros peut acheter, là, maintenant : l'étal, ce que sa bourse paie, ce que son sac range.
     *
     * @return list<array{objet_id: int, nom: string, prix: int}>
     */
    public function achats(Groupe $groupe, Personnage $heros): array
    {
        $solde = $this->bourses->solde($groupe, $heros);
        $objets = Objet::findMany(array_column($this->marche->etal($groupe), 'objet_id'))->keyBy('id');

        return collect($this->marche->etal($groupe))
            ->filter(fn (array $a) => (int) $a['prix'] <= $solde && isset($objets[$a['objet_id']])
                && RangementObjet::peutRanger($heros, $objets[$a['objet_id']], 1))
            ->map(fn (array $a) => ['objet_id' => (int) $a['objet_id'], 'nom' => (string) $a['nom'], 'prix' => (int) $a['prix']])
            ->values()->all();
    }

    /**
     * Ce que ce héros peut revendre : ses lignes, sauf hors marché, artefact ou pièce inamovible.
     *
     * @return list<array{inventaire_id: int, nom: string, prix: int}>
     */
    public function ventes(Groupe $groupe, Personnage $heros): array
    {
        $etal = collect($this->marche->etal($groupe))->keyBy('objet_id');

        return $heros->inventaire()->with('objet')->get()
            ->filter(fn (Inventaire $l) => $l->objet !== null && PhaseMarche::motifRefusVente($l->objet) === null
                && ! PhaseMarche::estHorsMarche($l->objet) && $this->equipement->retirable($l))
            ->map(fn (Inventaire $l) => ['inventaire_id' => (int) $l->id, 'nom' => (string) $l->objet->nom,
                'prix' => PhaseMarche::reventePour($l->objet, $etal[$l->objet_id]['prix'] ?? null)])
            ->values()->all();
    }

    /** @return array{type: string, nom: string, prix: int}|null `null` : cet achat n'est pas offert */
    public function acheter(Groupe $groupe, Personnage $heros, int $objetId): ?array
    {
        $achat = collect($this->achats($groupe, $heros))->firstWhere('objet_id', $objetId);
        if ($achat === null) {
            return null;
        }
        $this->bourses->payer($groupe, $achat['prix'], $heros);
        RangementObjet::ranger(Objet::findOrFail($objetId), (int) $heros->id, 1);
        $this->equipement->recalculerCombat($heros->fresh());

        return ['type' => 'acheter_etal', 'nom' => $achat['nom'], 'prix' => $achat['prix']];
    }

    /** @return array{type: string, nom: string, prix: int}|null `null` : cette vente n'est pas offerte */
    public function vendre(Groupe $groupe, Personnage $heros, int $inventaireId): ?array
    {
        $vente = collect($this->ventes($groupe, $heros))->firstWhere('inventaire_id', $inventaireId);
        $ligne = $vente === null ? null : Inventaire::find($inventaireId);
        if ($ligne === null) {
            return null;
        }
        if ((int) $ligne->quantite > 1) {
            $ligne->decrement('quantite');
        } else {
            $ligne->delete();
        }
        $this->bourses->verser($groupe, $vente['prix'], $heros);
        $this->equipement->recalculerCombat($heros->fresh());

        return ['type' => 'vendre_etal', 'nom' => $vente['nom'], 'prix' => $vente['prix']];
    }
}
