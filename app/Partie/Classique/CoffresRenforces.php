<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Mobilier;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\Salles;

/**
 * LES COFFRES RENFORCÉS — lot E2, *Wizards of Morcar* p. 4 (G1504) : « All chests in this expansion pack are Reinforced.
 * Contents of chests are not found until the first time a hero adjacent to that chest searches the room. Heroes who are not
 * adjacent to the chest draw from the treasure deck instead. Each room may still only be searched one time per hero. »
 *
 * Clé de CAMPAGNE `coffres_renforces: true` (« all chests in this expansion pack »). Dans une salle qui contient un coffre
 * (meuble « Coffre »), la note `fouille_tresor` de l'auteur — le contenu du coffre — ne joue que pour un héros au CONTACT
 * d'un coffre de la salle (`Grille::sontAdjacentes()`) ; les autres tirent une carte du paquet, et la note attend. Une salle
 * sans coffre n'est pas concernée. Point de passage : la fouille du trésor (`ResolveurTour`), avant la note.
 */
final class CoffresRenforces
{
    public const MEUBLE = 'Coffre';

    public static function actifs(Quete $quete): bool
    {
        return $quete->estConstruite() && data_get($quete->groupe?->campagneConstruite?->document, 'coffres_renforces') === true;
    }

    /** La note de la fouille de cette salle doit-elle attendre (salle à coffre, héros pas au contact) ? */
    public function noteRetenue(Quete $quete, EtatPersonnageQuete $etat, int $salle): bool
    {
        if (! self::actifs($quete) || $etat->position_x === null) {
            return false;
        }
        $grille = (array) ($quete->carte?->grille ?? []);
        $salles = (array) ($grille['salles'] ?? []);
        $idCoffre = Mobilier::where('nom', self::MEUBLE)->value('id');
        $coffres = collect((array) ($grille['mobilier'] ?? []))
            ->filter(fn (array $m) => (int) ($m['mobilier_id'] ?? 0) === (int) $idCoffre && empty($m['detruit'])
                && isset($m['x'], $m['y']) && Salles::indexDe($salles, (int) $m['x'], (int) $m['y']) === $salle);
        if ($coffres->isEmpty()) {
            return false;
        }

        return ! $coffres->contains(function (array $m) use ($etat, $quete): bool {
            foreach (Salles::cases(['x' => (int) $m['x'], 'y' => (int) $m['y'], 'l' => max(1, (int) ($m['l'] ?? 1)), 'h' => max(1, (int) ($m['h'] ?? 1))]) as $c) {
                if (Grille::sontAdjacentes((int) $etat->position_x, (int) $etat->position_y, $c['x'], $c['y'], carte: $quete->carte)) {
                    return true;
                }
            }

            return false;
        });
    }
}
