<?php

declare(strict_types=1);

namespace App\Partie;

use App\Http\Controllers\Api\TableController;
use App\Models\Carte;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\Narration\BibliothequeNarration;
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

        $narrateurActif = TableController::narrateurActif($groupe);
        $preambuleGroupe = [
            'id' => $groupe->id,
            'identifiant' => $groupe->identifiant,
            'nom' => $groupe->nom,
            'phase' => $groupe->phase,
            'or' => (int) $groupe->or,
            'etat' => $groupe->etat,
            'narrateur_actif' => $narrateurActif,
            // Scène sonore courante (boucle d'ambiance jouée par la table).
            'ambiance' => $this->sceneAmbiance($groupe, $quete),
        ];

        // En phase hub : expose les statuts « prêt » des personnages actifs.
        if ($groupe->phase === 'hub') {
            $prets = Cache::get("partie:pret:{$groupe->id}", []);
            $personnagesActifs = $groupe->personnages()
                ->wherePivot('actif', true)
                ->pluck('personnages.id')
                ->all();
            $preambuleGroupe['prets'] = array_map(
                fn (int $pid) => ['personnage_id' => $pid, 'pret' => (bool) ($prets[$pid] ?? false)],
                $personnagesActifs,
            );

            // Prologue de campagne (prémisse + menace) : exposé au hub pour que
            // l'écran de table l'affiche/le relise — `auto` (true tant qu'aucune
            // quête n'a eu lieu) déclenche l'ouverture automatique au lancement.
            $premisse = data_get($groupe->plan_campagne, 'premisse');
            if (is_string($premisse) && $premisse !== '') {
                $preambuleGroupe['prologue'] = [
                    'texte' => $premisse,
                    'url' => app(BibliothequeNarration::class)->urlDynamiqueSiCache($premisse),
                    'menace' => data_get($groupe->plan_campagne, 'menace'),
                    'auto' => ! $groupe->quetes()->exists(),
                ];
            }
        }

        return [
            'groupe' => $preambuleGroupe,
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
     * Scène sonore courante, dérivée de l'état (pour la boucle d'ambiance de
     * la table). En quête : `boss` si un boss/sous-boss est actif, `combat`
     * s'il reste un monstre actif, sinon `exploration`. Au hub : `victoire`
     * après le boss final vaincu (fin de campagne), `defaite` après un TPK
     * (dernière quête échouée), sinon `hub`.
     */
    private function sceneAmbiance(Groupe $groupe, ?Quete $quete): string
    {
        if ($quete !== null) {
            // Seuls les monstres RÉVÉLÉS comptent pour l'ambiance (dormants = exploration).
            $actifs = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true);

            if ((clone $actifs)->whereHas('monstre', fn ($q) => $q->whereIn('tier', ['sous_boss', 'boss']))->exists()) {
                return 'boss';
            }

            return $actifs->exists() ? 'combat' : 'exploration';
        }

        $derniere = $groupe->quetes()->orderByDesc('position_arc')->first();

        if ($derniere !== null) {
            if ($derniere->etat === 'echouee') {
                return 'defaite';
            }
            if ($derniere->etat === 'terminee' && $derniere->type_jalon === 'boss_final') {
                return 'victoire';
            }
        }

        return 'hub';
    }

    /**
     * Carte jouable — cases + pièges CONNUS (détectés / désarmés /
     * déclenchés) : les pièges encore cachés n'y figurent JAMAIS, la table
     * ne doit pas les montrer (contrat).
     *
     * @return array{largeur: int, hauteur: int, cases: list<list<string>>, pieges: list<array{x: int, y: int, etat: string, nom: string}>}|null
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
            'pieges' => $this->pieges($carte),
        ];
    }

    /**
     * @return list<array{x: int, y: int, etat: string, nom: string}>
     */
    private function pieges(Carte $carte): array
    {
        $connus = collect($carte->grille['pieges'] ?? [])
            ->filter(fn (array $entree) => in_array($entree['etat'] ?? null, [
                MoteurPieges::ETAT_DETECTE, MoteurPieges::ETAT_DESARME, MoteurPieges::ETAT_DECLENCHE,
            ], true));

        $noms = Piege::query()
            ->whereIn('id', $connus->pluck('piege_id')->filter()->unique())
            ->pluck('nom', 'id');

        return $connus
            ->map(fn (array $entree) => [
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                'etat' => (string) $entree['etat'],
                'nom' => $noms[$entree['piege_id']] ?? 'Piège',
            ])
            ->values()
            ->all();
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
                    'niveau' => (int) $p->niveau,
                    'x' => $etat?->position_x,
                    'y' => $etat?->position_y,
                    'pv_body' => (int) $p->pv_body,
                    'pv_body_max' => (int) $p->pv_body_max,
                    'pv_mind' => (int) $p->pv_mind,
                    'pv_mind_max' => (int) $p->pv_mind_max,
                    'tombe' => (bool) ($etat?->tombe ?? false),
                    'conditions' => $this->conditionsHeros($p),
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
            ->where('revele', true) // les monstres dormants (salle non découverte) restent cachés
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
                'conditions' => $this->conditionsMonstre($i),
            ])
            ->values()
            ->all();
    }

    /**
     * Conditions actives d'un héros (pivot personnage_conditions).
     *
     * @return list<array{nom: string, duree: int}>
     */
    private function conditionsHeros(Personnage $personnage): array
    {
        return $personnage->conditions()
            ->get()
            ->map(fn (\App\Models\Condition $c) => [
                'nom' => $c->nom,
                'duree' => (int) $c->pivot->duree,
            ])
            ->values()
            ->all();
    }

    /**
     * Conditions actives d'un monstre (habillage.conditions JSON).
     *
     * @return list<array{nom: string, duree: int}>
     */
    private function conditionsMonstre(InstanceMonstre $instance): array
    {
        $conditions = [];

        foreach ((array) data_get($instance->habillage, 'conditions', []) as $cle => $valeur) {
            if ($valeur) {
                $conditions[] = ['nom' => (string) $cle, 'duree' => 0];
            }
        }

        return $conditions;
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
            ->where('revele', true) // les monstres dormants ne figurent pas dans l'initiative
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
