<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use Illuminate\Support\Facades\Cache;

/**
 * Construit le payload « EtatGroupe » du contrat (docs/contrat-api.md) —
 * réutilisé par GET /api/groupes/{identifiant}/etat ET par le broadcast
 * `.groupe.etat` (EtatGroupeDiffuse) : une seule source de forme.
 *
 * En phase hub : quete/carte sont null, entites/initiative vides.
 */
final class EtatGroupe
{
    /** Clé du cache de l'indicateur « MJ réfléchit » (écrite par MjReflechit). */
    public static function cleMjReflechit(int $groupeId): string
    {
        return "partie:mj_reflechit:{$groupeId}";
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Groupe $groupe): array
    {
        $quete = $groupe->phase === 'quete' ? $groupe->queteCourante : null;

        return [
            'groupe' => [
                'id' => $groupe->id,
                'identifiant' => $groupe->identifiant,
                'nom' => $groupe->nom,
                'phase' => $groupe->phase,
                'or' => (int) $groupe->or,
                'etat' => $groupe->etat,
            ],
            'quete' => $quete === null ? null : [
                'id' => $quete->id,
                'titre' => $quete->titre,
                'position_arc' => $quete->position_arc,
                'type_jalon' => $quete->type_jalon,
                'etat' => $quete->etat,
            ],
            'carte' => $this->carte($quete),
            'entites' => $quete === null ? [] : [...$this->heros($groupe, $quete), ...$this->monstres($quete)],
            'initiative' => $quete === null ? [] : $this->initiative($groupe, $quete),
            'narration' => $this->derniereNarration($groupe),
            'mj_reflechit' => (bool) Cache::get(self::cleMjReflechit($groupe->id), false),
        ];
    }

    /**
     * Carte jouable — seules les cases sont exposées (les pièges non
     * déclenchés restent secrets côté serveur).
     *
     * @return array{largeur: int, hauteur: int, cases: list<list<string>>}|null
     */
    private function carte(?Quete $quete): ?array
    {
        $carte = $quete?->carte;

        if ($carte === null) {
            return null;
        }

        return [
            'largeur' => (int) $carte->largeur,
            'hauteur' => (int) $carte->hauteur,
            'cases' => $carte->grille['cases'] ?? [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function heros(Groupe $groupe, Quete $quete): array
    {
        $etats = $quete->etatsPersonnages()->get()->keyBy('personnage_id');

        return $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get()
            ->map(function (Personnage $p) use ($etats) {
                $etat = $etats->get($p->id);

                return [
                    'type' => 'heros',
                    'id' => $p->id,
                    'nom' => $p->nom,
                    'classe' => $p->classe,
                    'x' => $etat?->position_x,
                    'y' => $etat?->position_y,
                    'pv_body' => (int) $p->pv_body,
                    'pv_body_max' => (int) $p->pv_body_max,
                    'pv_mind' => (int) $p->pv_mind,
                    'pv_mind_max' => (int) $p->pv_mind_max,
                    'tombe' => (bool) ($etat?->tombe ?? false),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monstres(Quete $quete): array
    {
        return $quete->instancesMonstres()
            ->with('monstre')
            ->orderBy('id')
            ->get()
            ->map(fn (InstanceMonstre $i) => [
                'type' => 'monstre',
                'id' => $i->id,
                'nom' => $i->habillage['nom'] ?? $i->monstre->nom_base,
                'x' => $i->position_x,
                'y' => $i->position_y,
                'pv_body' => (int) $i->pv_body,
                'pv_body_max' => (int) $i->monstre->pv_body,
                'etat' => $i->etat,
            ])
            ->values()
            ->all();
    }

    /**
     * Ordre du tour figé (C1) : héros par ordre d'initiative, monstres après.
     *
     * @return list<array{entite: string, id: int, nom: string, a_joue: bool}>
     */
    private function initiative(Groupe $groupe, Quete $quete): array
    {
        $etats = $quete->etatsPersonnages()->get()->keyBy('personnage_id');

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get()
            ->map(fn (Personnage $p) => [
                'entite' => 'heros',
                'id' => $p->id,
                'nom' => $p->nom,
                'a_joue' => (bool) ($etats->get($p->id)?->a_joue ?? false),
            ]);

        $monstres = $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->with('monstre')
            ->orderBy('id')
            ->get()
            ->map(fn (InstanceMonstre $i) => [
                'entite' => 'monstre',
                'id' => $i->id,
                'nom' => $i->habillage['nom'] ?? $i->monstre->nom_base,
                'a_joue' => false, // les monstres jouent en bloc après les héros (C2)
            ]);

        return [...$heros->values()->all(), ...$monstres->values()->all()];
    }

    private function derniereNarration(Groupe $groupe): ?string
    {
        $payload = Evenement::query()
            ->where('groupe_id', $groupe->id)
            ->where('type', 'narration')
            ->orderByDesc('sequence')
            ->value('payload');

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return $payload['texte'] ?? null;
    }
}
