<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MoteurDegats;

/**
 * LES DÉGÂTS CONVERTIS EN MIND (mode classique, lot K6 — *Spirit Queen's Torment* ; René, 2026-11-19 : « tout dégât »).
 *
 * - q. 8 : « all damage heroes take will affect their Mind Points instead of their Body Points » → `regles.degats_en_mind:
 *   true` : TOUT dégât subi (coups, pièges, poison, sorts de Dread) entame l'Esprit au lieu du Corps ;
 * - q. 9 : « all Orcs are still spirits, and the damage they deal is to Mind Points » → `regles.degats_en_mind: [slugs]` :
 *   seuls les coups des monstres nommés.
 *
 * Décision écrite : le coup se calcule comme d'habitude (dés, réactions, réductions de `MoteurDegats::infligerAHeros()`) —
 * seule la JAUGE change au moment d'écrire. Le prix que s'inflige un héros (`SOURCE_SACRIFICE`, la Furie) n'est pas un
 * dégât « subi » : il reste en Corps. Esprit à 0 = l'état de choc, jamais une chute (décision du 2026-10-01).
 *
 * Point de passage unique : lu par `infligerAHeros()` seulement ; annoncé et publié par `EffetsGlobauxQuete`.
 */
final class DegatsEnMind
{
    /** La règle de la quête construite : `true`, une liste de slugs de monstres, ou `null` (rien à convertir). */
    public static function regle(?Quete $quete): bool|array|null
    {
        if ($quete === null || ! $quete->estConstruite()) {
            return null;
        }
        $regle = data_get($quete->documentConstruit(), 'regles.degats_en_mind');

        return $regle === true || (is_array($regle) && $regle !== []) ? $regle : null;
    }

    /** Ce coup doit-il entamer l'ESPRIT de ce héros plutôt que son Corps ? */
    public function convertit(Personnage $heros, string $source, array $contexte): bool
    {
        if ($source === MoteurDegats::SOURCE_SACRIFICE) {
            return false;
        }
        $regle = self::regle(EtatPersonnageQuete::enQuete($heros)?->quete);
        if ($regle === null) {
            return false;
        }
        if ($regle === true) {
            return true;
        }

        // Une liste : seuls les COUPS des monstres nommés (« the damage they deal »).
        $instance = isset($contexte['instance_id']) ? InstanceMonstre::with('monstre')->find((int) $contexte['instance_id']) : null;

        return $instance?->monstre !== null
            && in_array(CatalogueConstructeur::slug((string) $instance->monstre->nom_base), $regle, true);
    }

    /** La phrase annoncée au départ et affichée au bandeau (`EffetsGlobauxQuete`). */
    public static function texte(bool|array $regle, ?callable $nomDe = null): string
    {
        if ($regle === true) {
            return 'Esprits : tout dégât qu\'un héros subit dans cette quête entame son Esprit, pas son Corps.';
        }
        $noms = array_map(fn (string $slug) => $nomDe !== null ? $nomDe($slug) : $slug, $regle);

        return 'Esprits : les coups de '.implode(', ', $noms).' entament l\'Esprit des héros, pas leur Corps.';
    }
}
