<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Partie\CaseLibre;
use App\Partie\FabriqueGrille;
use App\Partie\TamponAnnonces;

/**
 * LE TOURNOI DE WORLD'S END DANS UNE CAMPAGNE CLASSIQUE (lots TC3-TC4, `docs/plan-tournoi-classique.md`) — ce que l'arène
 * ne connaît pas : les VAGUES de la quête 1 (« When a wave of combatants is defeated, immediately add the next wave to
 * the Defender tile and continue the battle round with the new combatants. Heroes maintain activation. Trophy tiles
 * don't replenish between waves », F9528 p. 21) et la fin d'un tournoi gagné.
 *
 * `quete.tournoi.vagues: [[{slug, …profil}], …]` — les vagues SUIVANTES (la première est `quete.monstres`), posées sur les
 * cases de la tuile du Defender (`grille.tournoi.camp_defender`), ou au plus près. Le rang de la prochaine vague est un
 * état durable : `quetes.drapeaux._vague_tournoi`.
 */
final class TournoiClassique
{
    public const DRAPEAU_VAGUE = '_vague_tournoi';

    public function __construct(private readonly CatalogueConstructeur $catalogue) {}

    /**
     * Le Defender vient de tomber : la vague suivante entre, s'il en reste. Rend l'annonce `vague_tournoi`, ou `null`
     * (aucune vague : le tournoi est gagné).
     *
     * @return array<string, mixed>|null
     */
    public function vagueSuivante(Groupe $groupe, Quete $quete): ?array
    {
        $vagues = array_values((array) data_get($quete->documentConstruit(), 'tournoi.vagues', []));
        $rang = (int) data_get($quete->relire('drapeaux')->drapeaux, self::DRAPEAU_VAGUE, 0);
        if (! isset($vagues[$rang])) {
            return null;
        }
        $drapeaux = (array) $quete->drapeaux;
        $drapeaux[self::DRAPEAU_VAGUE] = $rang + 1;
        $quete->update(['drapeaux' => $drapeaux]);

        $cases = array_values((array) data_get($quete->carte?->grille, 'tournoi.camp_defender', []));
        $index = $this->catalogue->index('monstres');
        $entres = [];
        foreach (array_values((array) $vagues[$rang]) as $i => $pose) {
            $monstre = $index[(string) ($pose['slug'] ?? '')] ?? null;
            $cible = $cases[$i % max(1, count($cases))] ?? null;
            $place = $monstre === null || $cible === null ? null
                : CaseLibre::iciOuProche(FabriqueGrille::pour($quete->fresh()), (int) $cible['x'], (int) $cible['y']);
            if ($place === null) {
                continue;
            }
            $pvBody = isset($pose['pv_body']) ? (int) $pose['pv_body'] : (int) $monstre->pv_body;
            $instance = $quete->instancesMonstres()->create([
                'monstre_id' => $monstre->id, 'pv_body' => $pvBody, 'pv_body_max' => $pvBody,
                'pv_mind' => isset($pose['pv_mind']) ? (int) $pose['pv_mind'] : (int) $monstre->pv_mind,
                'position_x' => $place['x'], 'position_y' => $place['y'],
                'etat' => 'actif', 'elite' => false, 'revele' => true,
                // Le Defender n'est vaincu qu'avec sa DERNIÈRE vague : chaque vague est une cible de la fin `vaincre`. Sans
                // cela, la première tombée, la quête se disait « objectif accompli » et ouvrait la sortie (essai, 2026-11-28).
                'objectif' => true,
                'habillage' => InstanceMonstre::habillageDeQuete((array) $pose, $this->catalogue),
            ]);
            $entres[] = $instance->nomAffiche();
        }

        $payload = ['type' => 'vague_tournoi', 'numero' => $rang + 2, 'monstres' => $entres];
        app(TamponAnnonces::class)->annoncer($groupe, $payload);

        return $payload;
    }
}
