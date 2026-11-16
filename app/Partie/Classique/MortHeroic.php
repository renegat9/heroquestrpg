<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Quete;
use App\Support\Journal;

/**
 * LA MORT EN MODE HEROIC (lot H1, `docs/plan-mode-classique.md` §10, Q2 et Q7) — point de passage unique de la mort d'un
 * héros. Rien d'autre dans le projet ne tue un héros : en Story (tous les autres groupes), un héros à terre ne meurt
 * jamais (`ResolveurTour::relevementsStory()`).
 *
 * Sources, lues au début du lot comme Q7 l'exigeait :
 * - *Jungles of Delthrak* (F9907 p. 8-9), mode Heroic : « heroes do not die when their Body Points are reduced to 0 but
 *   are instead incapacitated. […] At the start of that hero's next turn, they take a skull tile […]. If an
 *   incapacitated hero starts their turn with the skull tile in their possession, they die. » Tout soin le ramène et
 *   lui fait défausser le crâne (`EtatPersonnageQuete::booted()`).
 * - Livret de règles de *First Light* (G0978, « Dead Heroes ») : « if you die, you are out of the game for the rest of
 *   that quest. You may rename your hero and play them as a new character in the next quest. » Et : une quête se
 *   termine quand « all four heroes die ».
 *
 * Décisions écrites :
 * - « Au début de son tour suivant » se lit à l'OUVERTURE DU ROUND, après la phase de Zargon — le moment où le moteur
 *   place déjà tout ce qui se joue « au début du tour » d'un héros à terre (`relevementsStory()`, même interprétation).
 *   Tombé au round N, il prend le crâne à l'ouverture du round N+1 et meurt à celle du round N+2 s'il est toujours à
 *   terre : ses compagnons ont un round entier pour le soigner.
 * - Le mort sort de la quête (sa ligne d'état est supprimée, comme celle d'un héros sorti) mais RESTE dans le groupe
 *   jusqu'à la fin de la quête : son joueur suit la partie (`entites[].mort`). Au retour au hub, il quitte le groupe
 *   ({@see retirerLesMorts()}) ; son joueur crée un héros neuf et le rejoint (Q7). La ligne `personnages` n'est JAMAIS
 *   supprimée : `mort_le` la marque, le roster la montre, rien ne l'engage plus.
 * - Une quête perdue par TPK en Heroic : tous les héros sont à terre, personne ne peut plus les soigner — ils meurent
 *   tous (« all four heroes die »). Une reprise d'instantané ne ressuscite personne (`Sauvegarde`).
 */
final class MortHeroic
{
    /**
     * À l'ouverture du round, pour chaque héros à terre d'un groupe Heroic : il meurt s'il a déjà le crâne, sinon il le
     * prend. Rend les annonces, dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    public function cranes(Groupe $groupe, Quete $quete): array
    {
        if (! $groupe->estHeroic()) {
            return [];
        }

        $annonces = [];

        foreach ($quete->etatsPersonnages()->where('tombe', true)->with('personnage')->orderBy('id')->get() as $etat) {
            if ($etat->personnage === null) {
                continue;
            }

            if ($etat->crane) {
                $annonces[] = $this->mourir($groupe, $quete, $etat);

                continue;
            }

            $etat->update(['crane' => true]);
            $payload = [
                'type' => 'crane_pose',
                'personnage_id' => (int) $etat->personnage_id,
                'nom' => (string) $etat->personnage->nom,
            ];
            Journal::ajouter($groupe, 'action', $payload);
            $annonces[] = $payload;
        }

        return $annonces;
    }

    /**
     * Une quête perdue par TPK en Heroic : chaque héros encore à terre meurt. Rend les annonces.
     *
     * @return list<array<string, mixed>>
     */
    public function tousMorts(Groupe $groupe, Quete $quete): array
    {
        if (! $groupe->estHeroic()) {
            return [];
        }

        return $quete->etatsPersonnages()->where('tombe', true)->with('personnage')->orderBy('id')->get()
            ->map(fn (EtatPersonnageQuete $etat) => $this->mourir($groupe, $quete, $etat))
            ->all();
    }

    /**
     * LA MORT : le héros est marqué mort (définitif) et sort de la quête. Rend l'annonce.
     *
     * @return array<string, mixed>
     */
    public function mourir(Groupe $groupe, Quete $quete, EtatPersonnageQuete $etat): array
    {
        $heros = $etat->personnage;
        $affaires = $this->laisserLesAffaires($quete, $etat);
        $heros?->update(['mort_le' => now()]);
        $etat->delete();

        $payload = [
            'type' => 'heros_mort',
            'personnage_id' => (int) $etat->personnage_id,
            'nom' => (string) ($heros->nom ?? ''),
            // `au_sol` : un compagnon était là, les affaires attendent qu'on les ramasse ; `volees` : personne, un monstre
            // s'en empare (lot H1b).
            'affaires' => $affaires,
        ];
        Journal::ajouter($groupe, 'action', $payload);

        return $payload;
    }

    /**
     * LES AFFAIRES DU MORT (lot H1b) — livret de règles de *First Light* (G0978, « Dead Heroes ») : elles « can be
     * picked up by any other hero in the room with you, no action required », et « if there are no other heroes in the
     * room or corridor with you when you die, a nearby monster skulks in, snatches your possessions, and absconds! The
     * possessions are removed from the game ».
     *
     * La zone est celle de la fouille (`ZoneFouille` : la salle, ou le couloir, en entier). Un compagnon y compte s'il
     * est DEBOUT (décision écrite : un héros à terre ne chasse pas un voleur). Les affaires au sol sont posées à la case
     * du mort (`Quete::poserDepouille()`) ; volées, elles RESTENT sur la ligne du mort, hors du jeu — rien n'est
     * supprimé.
     */
    private function laisserLesAffaires(Quete $quete, EtatPersonnageQuete $etat): string
    {
        $grille = (array) ($quete->carte?->grille ?? []);
        if ($etat->horsPlateau() || $grille === []) {
            return 'volees';
        }

        $zone = \App\Partie\ZoneFouille::de($grille, (int) $etat->position_x, (int) $etat->position_y);
        $temoin = $quete->etatsPersonnages()
            ->where('id', '!=', $etat->id)
            ->where('tombe', false)
            ->whereNotNull('position_x')
            ->get()
            ->contains(fn (EtatPersonnageQuete $autre) => $zone->contient((int) $autre->position_x, (int) $autre->position_y));

        if (! $temoin) {
            return 'volees';
        }

        $quete->poserDepouille([
            'personnage_id' => (int) $etat->personnage_id,
            'nom' => (string) ($etat->personnage->nom ?? ''),
            'x' => (int) $etat->position_x,
            'y' => (int) $etat->position_y,
        ]);

        return 'au_sol';
    }

    /**
     * Au retour au hub, les morts quittent le groupe : leur place se libère pour le héros neuf de leur joueur. Rien
     * n'est supprimé — le pivot passe à inactif, le héros n'est plus engagé nulle part.
     */
    public function retirerLesMorts(Groupe $groupe): void
    {
        $morts = $groupe->personnages()->wherePivot('actif', true)->whereNotNull('personnages.mort_le')->get();

        foreach ($morts as $mort) {
            $groupe->personnages()->updateExistingPivot($mort->id, ['actif' => false]);
            $mort->update(['groupe_actif_id' => null]);

            Journal::ajouter($groupe, 'systeme', [
                'action' => 'heros_mort_retire',
                'personnage_id' => (int) $mort->id,
                'nom' => (string) $mort->nom,
            ]);
        }
    }
}
