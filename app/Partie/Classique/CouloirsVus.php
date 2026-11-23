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

        $this->revelerDerriereLesPortesOuvertes($quete, $heros);
    }

    /**
     * PORTE OUVERTE AU DÉPART (lot E1) : « the monsters activate when the highlighted door enters a hero's line of sight »
     * (F9907 q. 3). Personne ne l'ouvre, donc rien ne révèle sa salle à l'ouverture : elle se révèle quand un héros VOIT une
     * case de l'autre côté de la porte — par `ResolveurTour::revelerSalleEn()`, comme une ouverture.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\EtatPersonnageQuete>  $heros
     */
    private function revelerDerriereLesPortesOuvertes(Quete $quete, $heros): void
    {
        $grilleCarte = (array) $quete->carte->grille;
        $salles = (array) ($grilleCarte['salles'] ?? []);
        $portes = array_filter((array) ($grilleCarte['portes'] ?? []), fn ($p) => ! empty($p['ouverte_au_depart']));
        if ($portes === [] || $heros->isEmpty()) {
            return;
        }
        $grille = null;
        foreach ($portes as $porte) {
            foreach ([['x' => (int) $porte['x'], 'y' => (int) $porte['y']], CarteConstruite::caseDehors($porte)] as $c) {
                $salle = Salles::indexDe($salles, $c['x'], $c['y']);
                if ($salle === null || in_array($salle, $quete->fresh()->sallesDecouvertes(), true)) {
                    continue;
                }
                $grille ??= FabriqueGrille::pour($quete);
                foreach ($heros as $h) {
                    if ($grille->ligneDeVue((int) $h->position_x, (int) $h->position_y, $c['x'], $c['y'])) {
                        app(\App\Partie\ResolveurTour::class)->revelerSalleEn($quete->fresh(), $c['x'], $c['y']);
                        break;
                    }
                }
            }
        }
    }
}
