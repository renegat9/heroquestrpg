<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Fouille\DeckFouille;
use Illuminate\Validation\ValidationException;

/**
 * DEUX CARTES, UNE GARDÉE — lot E2, *Wizards of Morcar* q. 3 A (G1504 p. 25) : « The first hero to search for treasure may
 * take two treasure cards and choose one to keep. »
 *
 * Effet de note `cartes_au_choix {nombre?}` (2 par défaut, 3 au plus) : les cartes sont tirées du paquet de la quête et
 * gardées en base (`quetes.drapeaux._cartes_au_choix`, par héros) ; tant que le héros n'a pas choisi, son menu est FORCÉ —
 * une option par carte (`garder_carte_{k}`, gratuite) — et le résolveur applique la carte gardée comme une carte de fouille
 * (`ResolveurTour::appliquerButin()`) ; les autres retournent sous le paquet (comme le Trésor caché, `DeckFouille::sortDe()`).
 * Contrairement au Trésor caché, c'est le JOUEUR qui choisit (« choose one to keep »).
 */
final class CartesAuChoix
{
    private const ETAT = '_cartes_au_choix';

    public const MAX = 3;

    /** @return list<array<string, mixed>> les cartes tirées */
    public function tirer(Quete $quete, Personnage $heros, int $nombre): array
    {
        $cartes = [];
        for ($i = 0; $i < max(2, min(self::MAX, $nombre)); $i++) {
            $carte = app(DeckFouille::class)->piocher($quete->fresh());
            if (! empty($carte['deck_vide'])) {
                break;
            }
            $cartes[] = $carte;
        }
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::ETAT][$heros->id] = $cartes;
        $quete->update(['drapeaux' => $drapeaux]);

        return $cartes;
    }

    /** @return list<array<string, mixed>> les cartes en attente de ce héros */
    public static function enAttente(Quete $quete, Personnage $heros): array
    {
        return array_values((array) data_get($quete->relire('drapeaux')->drapeaux, self::ETAT.'.'.$heros->id, []));
    }

    /** Ce que la carte donne, en mots (la manette ne déduit rien). */
    public static function libelle(array $carte): string
    {
        return match ((string) ($carte['issue'] ?? 'rien')) {
            'tresor' => (int) ($carte['or'] ?? 0).' pièces d\'or',
            'potion', 'objet', 'artefact' => (string) (Objet::find((int) ($carte['objet_id'] ?? 0))?->nom ?? 'un objet'),
            'errant' => 'un monstre errant',
            'piege' => 'un piège',
            'monstre' => 'une créature',
            default => 'rien',
        };
    }

    /**
     * Le héros garde la carte `$k` : rendue au résolveur ; les autres retournent sous le paquet.
     *
     * @return array<string, mixed> la carte gardée
     */
    public function garder(Quete $quete, Personnage $heros, int $k): array
    {
        $cartes = self::enAttente($quete, $heros);
        if (! isset($cartes[$k])) {
            throw ValidationException::withMessages(['option_id' => 'Aucune carte à garder ainsi.']);
        }
        foreach ($cartes as $i => $autre) {
            if ($i !== $k && DeckFouille::sortDe($autre) === DeckFouille::RETIREE_DU_PAQUET) {
                $quete->remettreSousLePaquet($autre);
            }
        }
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        unset($drapeaux[self::ETAT][$heros->id]);
        $quete->update(['drapeaux' => $drapeaux]);

        return $cartes[$k];
    }
}
