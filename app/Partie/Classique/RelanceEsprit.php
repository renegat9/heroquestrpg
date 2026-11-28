<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\TamponAnnonces;

/**
 * LA RELANCE DE L'ESPRIT — lot E2, *First Light* q. 10 (FL-Q p. 31) : « Each hero may reroll all dice in a roll involving Mind
 * Points once during this quest. »
 *
 * `regles.relance_esprit: true`. Décision écrite (contrat) : la relance part D'ELLE-MÊME au premier jet d'Esprit RATÉ du héros
 * dans la quête — un héros relance toujours un échec, et un jet hors de son tour ne laisse pas le temps d'un menu (même
 * politique « à la première occasion » que la malédiction de l'Oracle, `MoteurOracle`). Le second résultat est gardé.
 * Les jets : ceux où le héros résiste avec son Esprit (`MoteurDread` : contre-sort, `jet_mind`, oppositions d'Esprit). État :
 * `quetes.drapeaux._relance_esprit` (ids des héros qui l'ont dépensée). Annonce `relance_esprit {nom}`.
 */
final class RelanceEsprit
{
    private const ETAT = '_relance_esprit';

    /**
     * Lance le jet ; s'il rate et que la relance est due, le relance une fois et rend le second.
     *
     * @template T
     *
     * @param  \Closure(): T  $lancer
     * @param  \Closure(T): bool  $rate  le jet est-il un échec POUR LE HÉROS ?
     * @return T
     */
    public function lancer(Personnage $heros, \Closure $lancer, \Closure $rate): mixed
    {
        $jet = $lancer();
        if (! $rate($jet)) {
            return $jet;
        }
        $quete = $this->queteDe($heros);
        if ($quete === null || ! $quete->estConstruite() || data_get($quete->documentConstruit(), 'regles.relance_esprit') !== true) {
            return $jet;
        }
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        if (in_array((int) $heros->id, (array) ($drapeaux[self::ETAT] ?? []), true)) {
            return $jet;
        }
        $drapeaux[self::ETAT] = [...(array) ($drapeaux[self::ETAT] ?? []), (int) $heros->id];
        $quete->update(['drapeaux' => $drapeaux]);
        app(TamponAnnonces::class)->annoncer($quete->groupe, ['type' => 'relance_esprit', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom],
            ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return $lancer();
    }

    private function queteDe(Personnage $heros): ?Quete
    {
        return Quete::query()->where('etat', 'en_cours')
            ->whereHas('etatsPersonnages', fn ($q) => $q->where('personnage_id', $heros->id))
            ->latest('id')->first();
    }
}
