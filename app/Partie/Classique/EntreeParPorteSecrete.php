<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\Salles;
use App\Partie\TamponAnnonces;

/**
 * L'ENTRÉE PAR UNE PORTE SECRÈTE — lot E2, *First Light* q. 9 (FL-Q p. 29) : « All secret doors in this quest have already been
 * discovered. The first time a hero enters a room via a secret door, they roll one additional Attack die that turn. »
 *
 * `regles.bonus_porte_secrete: true`. Le héros qui franchit l'arête d'une porte SECRÈTE (révélée) vers une SALLE, pour la première
 * fois de la quête, porte la marque `porte_secrete` dans `capacites_tour` : un dé d'attaque de plus jusqu'à la fin de son tour
 * (`ResolveurTour::frapper()`). Une fois par héros : `quetes.drapeaux._porte_secrete` (ids). Annonce `bonus_porte_secrete`.
 */
final class EntreeParPorteSecrete
{
    private const MARQUE = 'porte_secrete';

    private const ETAT = '_porte_secrete';

    /** @param  array{x: int, y: int}  $de  @param  array{x: int, y: int}  $vers */
    public function franchir(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, array $de, array $vers): void
    {
        if (! $quete->estConstruite() || data_get($quete->documentConstruit(), 'regles.bonus_porte_secrete') !== true) {
            return;
        }
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        if (Salles::indexDe($salles, (int) $vers['x'], (int) $vers['y']) === null) {
            return;
        }
        $secrete = collect((array) ($quete->carte?->grille['portes'] ?? []))->first(function ($porte) use ($de, $vers) {
            // Une porte SECRÈTE de la carte construite porte la clé `revele` (`CarteConstruite`) ; une porte ordinaire, non.
            if (! array_key_exists('revele', (array) $porte)) {
                return false;
            }
            [$a, $b] = Grille::casesPorte($porte);

            return ((int) $a['x'] === (int) $de['x'] && (int) $a['y'] === (int) $de['y'] && (int) $b['x'] === (int) $vers['x'] && (int) $b['y'] === (int) $vers['y'])
                || ((int) $b['x'] === (int) $de['x'] && (int) $b['y'] === (int) $de['y'] && (int) $a['x'] === (int) $vers['x'] && (int) $a['y'] === (int) $vers['y']);
        });
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        if ($secrete === null || in_array((int) $heros->id, (array) ($drapeaux[self::ETAT] ?? []), true)) {
            return;
        }
        $drapeaux[self::ETAT] = [...(array) ($drapeaux[self::ETAT] ?? []), (int) $heros->id];
        $quete->update(['drapeaux' => $drapeaux]);
        $etat->update(['capacites_tour' => array_values(array_unique([...(array) ($etat->fresh()->capacites_tour ?? []), self::MARQUE]))]);
        app(TamponAnnonces::class)->annoncer($quete->groupe, ['type' => 'bonus_porte_secrete', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom],
            ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);
    }

    /** Le dé de plus, ce tour-ci. */
    public static function bonus(?EtatPersonnageQuete $etat): int
    {
        return $etat !== null && in_array(self::MARQUE, (array) ($etat->fresh()?->capacites_tour ?? []), true) ? 1 : 0;
    }
}
