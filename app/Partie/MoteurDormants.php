<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use Illuminate\Support\Collection;

/**
 * PASSIF JUSQU'À DÉCLENCHEUR — les adversaires qui DORMENT (Kellar's Keep lot C, 2026-10-23 ; Witch Lord q. 5).
 *
 * Une créature dormante est VISIBLE : elle est sur la carte, publiée, ciblée si on la frappe. Ce qui la
 * distingue d'une figure ordinaire, c'est qu'elle ne bouge ni n'attaque tant que son déclencheur ne s'est pas
 * produit (`monstres.capacites.passif_jusqua`). Ce n'est donc PAS l'embuscade du Dreadshifter (`MoteurEmbuscade`),
 * qui se cache sous un meuble, ni la pose à la vue du Dread Skull (`MoteurTourelle`), qui n'apparaît qu'au
 * premier regard : ici tout le monde voit la figure, personne ne la voit AGIR.
 *
 * Trois déclencheurs, UN seul mécanisme, lus ici et nulle part ailleurs :
 *  - `attaque_du_groupe` — un monstre de sa SALLE est attaqué (une attaque déclarée, réussie ou non) : tous les
 *    dormants de cette salle s'éveillent, et jouent au TOUR SUIVANT du MJ (squelettes des rois nains, q. 5 n.B :
 *    « Then on Zargon's next turn, they all attack »). Déclenché par `ResolveurTour::frapper()`.
 *  - `groupe_dans_la_salle` — tous les héros debout sont dans sa salle : elle s'éveille au tour du MJ où la
 *    condition est vraie, et joue CE tour-là (Bellthor, F4193 q. 5 n.B : « does not move or attack on its turn
 *    until all of the heroes are in the room together »). Lu au début de sa phase, `ResolveurTour::phaseMonstres()`.
 *  - `fouille_de_la_salle` — un héros fouille le trésor de sa salle : elle s'éveille (gargouille-statue, q. 1 n.C :
 *    « If a hero searches for treasure before the trap has been disarmed, the gargoyle springs to life »). ÉCART
 *    DE CALENDRIER ÉCRIT : le livret dit « and immediately attacks » ; ici elle joue au tour suivant du MJ, comme
 *    les squelettes. Déclenché par `ResolveurTour::resoudreFouilleTresor()`. Le piège du coffre (désamorçable)
 *    n'est pas porté : le trésor de salle en tient lieu.
 *
 * INTOUCHABLE (`intouchable_jusqua`) : « cannot be harmed until it has either moved or attacked a hero » (la
 * statue, `action`) ; « cannot be harmed until after it attacks » (Bellthor, `attaque`). Tant que la condition
 * n'est pas remplie, la figure n'est la cible d'AUCUNE attaque : `estIntouchable()` est lu aux mêmes endroits que
 * le voile d'ombre (`MoteurOmbre::contientMonstre()`) — la liste du menu ET le choke-point `frapper()`, un seul
 * prédicat — et la liste de sorts (`MoteurSorts::ciblesLegales()`).
 *
 * UN DORMANT SE POSE EN SALLE (`DemarreurQuete::positionsDesMonstres()`) : sans salle, pas de porte à fermer, pas
 * de fouille à faire, pas de groupe à réunir — la figure bloquerait un couloir sans jamais pouvoir s'éveiller.
 *
 * Tout état est DURABLE : `instances_monstres.passif_etat` (colonne, migration `2026_10_23_100000`), repris par le
 * snapshot — jamais le cache.
 */
final class MoteurDormants
{
    /** Clé de la capacité paramétrée, lue ici — `MotsClesAdversaires::PASSIF_JUSQUA`. */
    public const CAPACITE = 'passif_jusqua';

    /** Le déclencheur d'une figure à passif, ou `null` si elle n'en porte pas. */
    public function declencheur(InstanceMonstre $instance): ?string
    {
        $passif = $instance->capaciteParametree(self::CAPACITE);

        return is_array($passif) && is_string($passif['declencheur'] ?? null) ? $passif['declencheur'] : null;
    }

    /**
     * Dort-elle encore ? Une figure à passif qui n'a pas été éveillée ne joue pas ; une figure sans passif ne dort jamais.
     * Lecteur déclaré dans `MotsClesAdversaires::CAPACITES` (`App\Partie\MoteurDormants::estDormant`).
     */
    public function estDormant(InstanceMonstre $instance): bool
    {
        return $this->declencheur($instance) !== null && ! $this->etat($instance)['eveille'];
    }

    /** Un coup est-il sans effet sur cette figure, parce qu'elle n'a pas encore bougé ou attaqué ? */
    public function estIntouchable(InstanceMonstre $instance): bool
    {
        $passif = $instance->capaciteParametree(self::CAPACITE);
        $jusqua = is_array($passif) ? ($passif['intouchable_jusqua'] ?? null) : null;

        if ($jusqua === null) {
            return false;
        }

        $etat = $this->etat($instance);

        return match ($jusqua) {
            'action' => ! $etat['agi'],
            'attaque' => ! $etat['attaque'],
            default => false,
        };
    }

    /**
     * L'état du passif, lu en colonne. `null` (aucun passif touché) vaut « pas éveillée, pas agie, pas attaquée ».
     *
     * @return array{eveille: bool, agi: bool, attaque: bool}
     */
    public function etat(InstanceMonstre $instance): array
    {
        $etat = (array) ($instance->passif_etat ?? []);

        return [
            'eveille' => (bool) ($etat['eveille'] ?? false),
            'agi' => (bool) ($etat['agi'] ?? false),
            'attaque' => (bool) ($etat['attaque'] ?? false),
        ];
    }

    /**
     * Le monstre vient de BOUGER (`attaque` = false) ou d'ATTAQUER (`attaque` = true). Levée de l'intouchabilité qui
     * s'en remet à un geste. Sans passif, rien à écrire.
     */
    public function marquerGeste(InstanceMonstre $instance, bool $attaque): void
    {
        if ($this->declencheur($instance) === null) {
            return;
        }

        $etat = $this->etat($instance);

        if ($etat['agi'] && (! $attaque || $etat['attaque'])) {
            return;
        }

        $instance->update(['passif_etat' => [
            'eveille' => $etat['eveille'],
            'agi' => true,
            'attaque' => $etat['attaque'] || $attaque,
        ]]);
    }

    /**
     * Un héros attaque `$cible` : si elle porte `attaque_du_groupe`, tous les dormants de sa SALLE (ou elle seule,
     * hors salle) s'éveillent. Rend un fil par figure éveillée, à ANNONCER par l'appelant (tampon d'annonces).
     *
     * @return list<array<string, mixed>>
     */
    public function attaqueSubie(Quete $quete, InstanceMonstre $cible): array
    {
        if ($this->declencheur($cible) !== 'attaque_du_groupe') {
            return [];
        }

        $salle = $this->salleDe($quete, $cible);

        // Hors salle, la figure seule : et seulement si elle DORT encore. Une figure éveillée qui a marché jusque dans un
        // couloir, puis été attaquée, n'a rien à réveiller — sans ce filtre elle annonçait un faux « s'éveille ».
        $membres = $salle === null
            ? collect([$cible])->filter(fn (InstanceMonstre $m) => $this->estDormant($m))->values()
            : $this->dormantsDeLaSalle($quete, $salle, 'attaque_du_groupe');

        return $this->eveiller($membres, 'attaque');
    }

    /**
     * Au tour du MJ, un dormant `groupe_dans_la_salle` s'éveille dès que tous les héros debout sont dans SA salle.
     * Rend le fil de l'éveil (ou `null` si rien ne se passe — la figure reste immobile, sans rien à dire).
     *
     * @return array<string, mixed>|null
     */
    public function reveilDuTourMJ(Quete $quete, InstanceMonstre $instance): ?array
    {
        if ($this->declencheur($instance) !== 'groupe_dans_la_salle' || ! $this->estDormant($instance)) {
            return null;
        }

        $salle = $this->salleDe($quete, $instance);

        if ($salle === null) {
            return null;
        }

        $debout = $quete->etatsPersonnages()->where('tombe', false)->get();

        if ($debout->isEmpty() || $debout->contains(fn (EtatPersonnageQuete $e) => $this->salleDeHeros($quete, $e) !== $salle)) {
            return null;
        }

        return $this->eveiller(collect([$instance]), 'groupe_reuni')[0] ?? null;
    }

    /**
     * Un héros vient de fouiller le trésor de la salle `$salle` : ses dormants `fouille_de_la_salle` s'éveillent.
     *
     * @return list<array<string, mixed>>
     */
    public function fouilleDeSalle(Quete $quete, int $salle): array
    {
        return $this->eveiller($this->dormantsDeLaSalle($quete, $salle, 'fouille_de_la_salle'), 'fouille');
    }

    /** Les dormants d'un déclencheur donné, dans la salle `$salle`, encore endormis. @return Collection<int, InstanceMonstre> */
    private function dormantsDeLaSalle(Quete $quete, int $salle, string $declencheur): Collection
    {
        return $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)->with('monstre')->orderBy('id')->get()
            ->filter(fn (InstanceMonstre $m) => $this->declencheur($m) === $declencheur
                && $this->estDormant($m)
                && $this->salleDe($quete, $m) === $salle)
            ->values();
    }

    /**
     * Écrit l'éveil de chaque figure et rend un fil par figure éveillée.
     *
     * ⚠ L'éveil ne fait AGIR personne sur l'instant : la figure joue à sa phase du MJ, selon son déclencheur
     * (tour suivant pour `attaque_du_groupe` et `fouille_de_la_salle`, ce tour-là pour `groupe_dans_la_salle`).
     *
     * @param  Collection<int, InstanceMonstre>  $figures
     * @return list<array<string, mixed>>
     */
    private function eveiller(Collection $figures, string $cause): array
    {
        $fils = [];

        foreach ($figures as $figure) {
            $etat = $this->etat($figure);
            $figure->update(['passif_etat' => [...$etat, 'eveille' => true]]);

            $fils[] = [
                'type' => 'monstre_s_eveille',
                'monstre' => $figure->nomAffiche(),
                'id' => $figure->id,
                'cause' => $cause,
            ];
        }

        return $fils;
    }

    /** La salle d'une figure (index dans `grille.salles`), ou `null` pour un couloir ou une figure non posée. */
    public function salleDe(Quete $quete, InstanceMonstre $instance): ?int
    {
        if ($instance->position_x === null || $instance->position_y === null) {
            return null;
        }

        return Salles::indexDe($this->salles($quete), (int) $instance->position_x, (int) $instance->position_y);
    }

    /** La salle d'un héros (index dans `grille.salles`), ou `null` pour un couloir. */
    public function salleDeHeros(Quete $quete, EtatPersonnageQuete $etat): ?int
    {
        return Salles::indexDe($this->salles($quete), (int) $etat->position_x, (int) $etat->position_y);
    }

    /** @return array<int|string, array{x: int|string, y: int|string, largeur: int|string, hauteur: int|string}> */
    private function salles(Quete $quete): array
    {
        return (array) data_get($quete->carte?->grille, 'salles', []);
    }
}
