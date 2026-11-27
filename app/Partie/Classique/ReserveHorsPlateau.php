<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Partie\FabriqueGrille;
use Illuminate\Support\Collection;

/**
 * LA ZONE HORS DU PLATEAU — lot E2, *Prophecy of Telor* q. 2 (G0052 p. 5) : « Zargon, for the remainder of the quest, at the
 * start of each of your turns, place 2 Goblins and 1 Orc to the side of the board in the area marked B. Treat area B as a
 * normal room. Heroes cannot enter into it or attack monsters in area B. If a hero stands on the square marked C, you may
 * attack them. »
 *
 * `regles.reserve_hors_plateau {x, y}` : la case C, au bord du plateau, par où la zone touche le donjon. Les figures de la
 * zone sont des instances SANS case, à l'état `ETAT` : aucune lecture des figures actives ne les voit (cible, contact, zone,
 * salle vidée, fin sur `vaincre`) — c'est ce qui rend vraie la phrase « heroes cannot attack monsters in area B ».
 * - L'effet de note `en_reserve` les y place (`ajouter()`).
 * - Au tour de Zargon, après les figures du plateau (`ResolveurTour::jouerLaReserve()`) : un héros debout sur la case C est
 *   frappé par UNE figure de la zone ; sinon, tant que la case est libre, la première figure entre sur elle puis joue son tour.
 *
 * Écart écrit : la figure qui entre se pose sur C sans y dépenser le pas qui la sépare de la zone ; elle joue ensuite son
 * déplacement entier.
 */
final class ReserveHorsPlateau
{
    public const ETAT = 'reserve';

    public function __construct(private readonly CatalogueConstructeur $catalogue) {}

    /** @return array{x: int, y: int}|null la case d'entrée, ou `null` si la quête n'a pas de zone hors du plateau */
    public static function regle(Quete $quete): ?array
    {
        if (! $quete->estConstruite()) {
            return null;
        }
        $case = data_get($quete->documentConstruit(), 'regles.reserve_hors_plateau');

        return is_array($case) && isset($case['x'], $case['y']) ? ['x' => (int) $case['x'], 'y' => (int) $case['y']] : null;
    }

    /**
     * Place des figures dans la zone : `{slug, nombre?, …profil}`, chaque `nombre` déplié.
     *
     * @param  list<array<string, mixed>>  $poses
     * @return list<string> les noms affichés des figures placées
     */
    public function ajouter(Quete $quete, array $poses): array
    {
        if (self::regle($quete) === null) {
            return [];
        }
        $monstres = $this->catalogue->index('monstres');
        $noms = [];

        foreach ($poses as $pose) {
            $monstre = $monstres[(string) ($pose['slug'] ?? '')] ?? null;
            if ($monstre === null) {
                continue;
            }
            $pvBody = isset($pose['pv_body']) ? (int) $pose['pv_body'] : (int) $monstre->pv_body;
            $pvMind = isset($pose['pv_mind']) ? (int) $pose['pv_mind'] : (int) $monstre->pv_mind;

            for ($n = 0; $n < max(1, (int) ($pose['nombre'] ?? 1)); $n++) {
                $instance = $quete->instancesMonstres()->create([
                    'monstre_id' => $monstre->id,
                    'pv_body' => $pvBody,
                    'pv_body_max' => $pvBody,
                    'pv_mind' => $pvMind,
                    'position_x' => null,
                    'position_y' => null,
                    'etat' => self::ETAT,
                    'elite' => false,
                    'revele' => false,
                    'habillage' => InstanceMonstre::habillageDeQuete((array) $pose, $this->catalogue),
                ]);
                $noms[] = $instance->nomAffiche();
            }
        }

        return $noms;
    }

    /** @return Collection<int, InstanceMonstre> les figures de la zone, dans l'ordre où elles y sont arrivées */
    public function figures(Quete $quete): Collection
    {
        return $quete->instancesMonstres()->where('etat', self::ETAT)->with('monstre')->orderBy('id')->get();
    }

    /** Le héros debout sur la case C — celui qu'une figure de la zone peut frapper —, ou `null`. */
    public function herosSurLaCase(Quete $quete): ?EtatPersonnageQuete
    {
        $case = self::regle($quete);

        return $case === null ? null : $quete->etatsPersonnages()->where('tombe', false)
            ->where('position_x', $case['x'])->where('position_y', $case['y'])->with('personnage')->first();
    }

    /**
     * La première figure de la zone entre sur la case C, si elle est libre. Rend la figure entrée (active, vue), ou `null` :
     * zone vide, ou case prise.
     */
    public function entrer(Quete $quete): ?InstanceMonstre
    {
        $case = self::regle($quete);
        $figure = $case === null ? null : $quete->instancesMonstres()->where('etat', self::ETAT)->orderBy('id')->first();

        if ($figure === null || ! FabriqueGrille::pour($quete->fresh())->estTraversable($case['x'], $case['y'])) {
            return null;
        }

        $figure->update(['etat' => 'actif', 'revele' => true, 'position_x' => $case['x'], 'position_y' => $case['y']]);

        return $figure->fresh()->load('monstre');
    }

    /**
     * Ce que la table montre : la case C et les figures qui attendent dans la zone.
     *
     * @return array{x: int, y: int, noms: list<string>}|null
     */
    public function etat(Quete $quete): ?array
    {
        $case = self::regle($quete);

        return $case === null ? null : [...$case, 'noms' => $this->figures($quete)->map(fn (InstanceMonstre $i) => $i->nomAffiche())->values()->all()];
    }
}
