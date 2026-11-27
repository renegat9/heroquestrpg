<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\MotsClesEquipement;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\TamponAnnonces;

/**
 * L'APNÉE — lot E2, *Spirit Queen's Torment* q. 11 (G0053 p. 27) : « Inform the heroes they are holding their breath. They
 * should mark each turn that passes on their sheet, and if seven turns pass without finding a source of air, they die! »
 * ; note A : « An orb allows the hero to breathe underwater. […] The orbs lose their enchantment once this quest is
 * complete. »
 *
 * `regles.apnee {tours}`. Au début de chaque tour d'un héros debout SUR le plateau (même crochet que le fardeau,
 * `MenuMoteur`) : s'il porte un objet `respire_sous_l_eau` (l'Orbe bleue), il respire et rien ne compte ; sinon son compteur
 * avance d'un tour et la table l'annonce. Au tour qui dépasse `tours`, il se noie : il meurt (mode Story : il tombe, à terre
 * — la même lecture que la crevasse). L'orbe est un objet de quête : retirée du sac à la fin (`objet_de_quete`).
 *
 * Décisions écrites : seuls les tours SANS orbe comptent, et ils s'additionnent (rendre son orbe fait reprendre l'apnée là où
 * elle en était) ; un héros hors plateau (devant l'entrée) ne compte pas encore. État durable : `quetes.drapeaux._apnee`
 * (`{personnage_id: tours retenus}`), un marqueur de `capacites_tour` pour ne compter qu'une fois par tour.
 */
final class Apnee
{
    private const ETAT = '_apnee';

    private const MARQUEUR = 'apnee';

    public static function tours(Quete $quete): ?int
    {
        if (! $quete->estConstruite()) {
            return null;
        }
        $tours = data_get($quete->documentConstruit(), 'regles.apnee.tours');

        return is_int($tours) ? $tours : null;
    }

    public static function respire(Personnage $heros): bool
    {
        return $heros->inventaire()->with('objet')->get()
            ->contains(fn ($ligne) => ! empty(((array) $ligne->objet?->effet)[MotsClesEquipement::RESPIRE_SOUS_L_EAU] ?? null));
    }

    /** @return array<string, mixed>|null l'annonce, ou `null` si rien ne se joue à ce tour */
    public function debutDeTour(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat): ?array
    {
        $max = self::tours($quete);
        if ($max === null || $etat->tombe || $etat->position_x === null
            || in_array(self::MARQUEUR, (array) ($etat->capacites_tour ?? []), true)) {
            return null;
        }
        $etat->update(['capacites_tour' => [...(array) ($etat->capacites_tour ?? []), self::MARQUEUR]]);
        if (self::respire($heros)) {
            return null;
        }

        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $retenus = (int) ($drapeaux[self::ETAT][$heros->id] ?? 0) + 1;
        $drapeaux[self::ETAT][$heros->id] = $retenus;
        $quete->update(['drapeaux' => $drapeaux]);

        $payload = ['type' => 'apnee', 'nom' => (string) $heros->nom, 'personnage_id' => (int) $heros->id,
            'tour' => $retenus, 'max' => $max, 'noye' => $retenus > $max];

        if ($payload['noye']) {
            if ($groupe->mode_mort === Groupe::MORT_STORY) {
                $etat->update(['tombe' => true, 'a_joue' => true]); // mode Story : on ne meurt pas, on tombe (décision écrite)
            } else {
                $heros->update(['pv_body' => 0]);
                app(MortHeroic::class)->mourir($groupe, $quete, $etat->fresh());
            }
        }

        app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return $payload;
    }
}
