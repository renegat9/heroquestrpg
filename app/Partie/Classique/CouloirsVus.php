<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Quete;
use App\Partie\FabriqueGrille;
use App\Partie\Salles;

/**
 * LE BROUILLARD DES COULOIRS d'une carte construite (mode classique, décision de René du 2026-11-17 : « respecter le
 * plateau »). Au plateau, on place ce qu'un héros VOIT en regardant le long d'un couloir ; une salle se révèle entière
 * quand sa porte s'ouvre. Ouvrir une porte ne révèle donc plus TOUS les couloirs (la « limite connue » de 2026-11-15) :
 * seulement les cases de couloir qu'un héros a en ligne de vue (`Grille::ligneDeVue()`, murs en arêtes et portes closes
 * compris), cumulées dans `quetes.couloirs_vus` — un état durable, jamais le cache.
 *
 * Point de passage unique de cette mémoire : mise à jour après chaque résolution (`ResolveurTour::resoudre()`) et au
 * départ de la quête ; lue par le brouillard (`EtatGroupe::appliquerBrouillard()`). Une quête sans la colonne (`null` :
 * démarrée avant ce lot, ou générée) garde l'ancien brouillard.
 */
final class CouloirsVus
{
    /** Les cases de couloir déjà vues, `["x,y" => true]`, ou `null` si la quête n'a pas ce brouillard. */
    public function vus(Quete $quete): ?array
    {
        $vus = $quete->couloirs_vus;

        return $quete->estConstruite() && is_array($vus) ? array_fill_keys(array_map('strval', $vus), true) : null;
    }

    /** Ajoute ce que chaque héros sur le plateau voit maintenant. N'écrit que si quelque chose de neuf est vu. */
    public function mettreAJour(Quete $quete): void
    {
        if (! $quete->estConstruite() || $quete->carte === null) {
            return;
        }

        $vus = $this->vus($quete) ?? [];
        $avant = count($vus);
        $grilleCarte = (array) $quete->carte->grille;
        $cases = (array) ($grilleCarte['cases'] ?? []);
        $salles = (array) ($grilleCarte['salles'] ?? []);
        $heros = $quete->etatsPersonnages()->whereNotNull('position_x')->get(['position_x', 'position_y']);

        if ($heros->isNotEmpty()) {
            $grille = FabriqueGrille::pour($quete);
            foreach ($cases as $y => $ligne) {
                foreach ((array) $ligne as $x => $case) {
                    $cle = "{$x},{$y}";
                    if ($case === 'm' || isset($vus[$cle]) || Salles::indexDe($salles, (int) $x, (int) $y) !== null) {
                        continue;
                    }
                    foreach ($heros as $h) {
                        if ($grille->ligneDeVue((int) $h->position_x, (int) $h->position_y, (int) $x, (int) $y)) {
                            $vus[$cle] = true;
                            break;
                        }
                    }
                }
            }
        }

        if (count($vus) !== $avant || $quete->couloirs_vus === null) {
            $quete->update(['couloirs_vus' => array_keys($vus)]);
        }
    }
}
