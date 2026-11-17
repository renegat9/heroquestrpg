<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Inventaire;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Support\Journal;
use Illuminate\Support\Facades\DB;

/**
 * L'ÉQUIPEMENT CONFISQUÉ (mode classique, lot K5j, plan §2.3 R4) — point de passage unique de la consigne d'une quête.
 *
 * Source : *Rise of the Dread Moon* (F6646 p. 11) — « All their equipment and valuables have been confiscated », et
 * « Each hero may search this room to retrieve all their confiscated equipment and valuables ». Décision de René
 * (2026-11-17) : TOUT est pris, comme la source — armes, armures, sac, or. Les sorts restent.
 *
 * Les affaires QUITTENT l'inventaire (78 lecteurs le lisent : les y laisser en les « cachant » en aurait oublié) et
 * attendent dans `quetes.consignes`, ligne par ligne, avec tout ce qui fait un exemplaire (charges, améliorations de
 * Forge, réveil d'un brassard). Décisions écrites :
 * - **Rien ne se perd** : à la fin de la quête, quelle qu'elle soit, tout ce qui n'a pas été repris est rendu
 *   (`Quete::booted()` appelle {@see rendreTout()} au changement d'état). Un héros mort en Heroic les retrouve sur sa
 *   ligne, comme ses autres affaires.
 * - **La reprise d'un instantané** saisit et restaure la consigne avec l'inventaire (`Sauvegarde`) : ni perte ni doublon.
 *
 * Mode classique seulement : la règle vit dans `regles.confiscation` d'une quête CONSTRUITE.
 */
final class Confiscation
{
    public function __construct(private readonly Equipement $equipement) {}

    /** L'`id` de la salle où les affaires attendent, ou `null` si la quête ne confisque rien. */
    public function salle(Quete $quete): ?string
    {
        $salle = data_get($quete->documentConstruit(), 'regles.confiscation.salle');

        return $quete->estConstruite() && is_string($salle) ? $salle : null;
    }

    /** Ce héros a-t-il encore des affaires à reprendre ? */
    public function enAttente(Quete $quete, int $personnageId): bool
    {
        $consigne = ((array) $quete->consignes)[(string) $personnageId] ?? null;

        return is_array($consigne) && ($consigne['rendue'] ?? false) !== true;
    }

    /**
     * Au départ : tout l'inventaire et l'or du héros passent à la consigne. Rend l'annonce.
     *
     * @return array<string, mixed>
     */
    public function confisquer(Quete $quete, Personnage $heros): array
    {
        return DB::transaction(function () use ($quete, $heros): array {
            $lignes = $heros->inventaire()->orderBy('id')->get();
            $objets = $lignes->map(fn (Inventaire $l): array => [
                'objet_id' => (int) $l->objet_id,
                'emplacement' => (string) $l->emplacement,
                'quantite' => (int) $l->quantite,
                'charges' => $l->charges,
                'quetes_avant_reveil' => $l->quetes_avant_reveil,
                'ameliorations' => $l->ameliorations,
            ])->values()->all();
            $or = (int) $heros->or;

            // ⚠ Jamais `[...$consignes, id => …]` : l'étalement RENUMÉROTE les clés entières — la consigne du premier
            // héros (clé 506) devenait la clé 0 dès que le second était confisqué (vu en partie réelle, 2026-11-17).
            $consignes = (array) $quete->refresh()->consignes;
            $consignes[(string) $heros->id] = ['or' => $or, 'objets' => $objets, 'rendue' => false];
            $quete->update(['consignes' => $consignes]);
            $heros->inventaire()->delete();
            $heros->update(['or' => 0]);
            $this->equipement->recalculerCombat($heros->fresh());

            // L'appelant l'ANNONCE (`TamponAnnonces`, qui le journalise) : pas de seconde écriture ici.
            return ['type' => 'confiscation', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom,
                'objets' => count($objets), 'or' => $or];
        });
    }

    /**
     * Le héros reprend SES affaires (fouille du trésor de la salle de consigne, ou fin de quête). Rend ce qui a été
     * rendu, ou `null` s'il n'avait rien en attente.
     *
     * @return array{personnage_id: int, nom: string, objets: int, or: int}|null
     */
    public function rendre(Quete $quete, Personnage $heros): ?array
    {
        if (! $this->enAttente($quete, (int) $heros->id)) {
            return null;
        }

        return DB::transaction(function () use ($quete, $heros): array {
            $consignes = (array) $quete->consignes;
            $consigne = $consignes[(string) $heros->id];

            foreach ((array) ($consigne['objets'] ?? []) as $ligne) {
                $heros->inventaire()->create($ligne);
            }
            $heros->update(['or' => (int) $heros->or + (int) ($consigne['or'] ?? 0)]);
            $consignes[(string) $heros->id] = [...$consigne, 'rendue' => true];
            $quete->update(['consignes' => $consignes]);
            $this->equipement->recalculerCombat($heros->fresh());

            return ['personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom,
                'objets' => count((array) ($consigne['objets'] ?? [])), 'or' => (int) ($consigne['or'] ?? 0)];
        });
    }

    /** Fin de la quête : tout ce qui attend encore est rendu à son propriétaire. Journalisé, une ligne par héros. */
    public function rendreTout(Quete $quete): void
    {
        foreach (array_keys((array) $quete->consignes) as $id) {
            $heros = Personnage::find((int) $id);
            if ($heros === null) {
                continue;
            }
            $rendu = $this->rendre($quete, $heros);
            if ($rendu !== null && $quete->groupe !== null) {
                Journal::ajouter($quete->groupe, 'systeme', ['action' => 'consigne_rendue_fin_de_quete', ...$rendu]);
            }
        }
    }
}
