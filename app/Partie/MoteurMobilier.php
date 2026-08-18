<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\RareteButin;
use App\Models\Carte;
use App\Models\Mobilier;
use App\Models\Objet;

/**
 * Fouille du MOBILIER de salle (doc 17).
 *
 * Un coffre, un tombeau, une armoire posés sur la carte sont des objets qu'on
 * ouvre — pas du décor (décision de René, 2026-08-07). Le drapeau
 * `mobiliers.fouillable` existait depuis la création de la table sans aucun
 * lecteur : sept types sur huit le portent, et aucun ne s'ouvrait. Un joueur
 * voyait un coffre au milieu de la salle et ne pouvait rien en faire.
 *
 * Un meuble se fouille **une seule fois pour tout le groupe** — c'est un objet
 * physique, pas une table de trésor : le premier qui l'ouvre le vide. C'est la
 * différence avec la fouille de SALLE, qui est une par héros (chacun cherche
 * dans son coin). L'état vit dans la grille de la carte (`mobilier[i].fouille`),
 * comme celui des portes, donc il survit aux snapshots sans nouvelle colonne.
 *
 * Le mobilier bloquant le passage, on le fouille depuis une case ADJACENTE :
 * on ne peut pas se tenir dessus.
 */
final class MoteurMobilier
{
    /**
     * Meubles fouillables, non encore fouillés, orthogonalement adjacents à
     * (x, y) — index dans la grille + entrée + libellé du catalogue.
     *
     * @return list<array{index: int, entree: array<string, mixed>, nom: string}>
     */
    public function fouillablesAdjacents(Carte $carte, int $x, int $y, ?int $personnageId = null): array
    {
        $entrees = (array) ($carte->grille['mobilier'] ?? []);

        if ($entrees === []) {
            return [];
        }

        $catalogue = Mobilier::query()
            ->whereIn('id', collect($entrees)->pluck('mobilier_id')->filter()->unique())
            ->get(['id', 'nom', 'fouillable', 'effet'])
            ->keyBy('id');

        $trouves = [];

        foreach ($entrees as $index => $entree) {
            $type = $catalogue[$entree['mobilier_id'] ?? 0] ?? null;

            if ($type === null || ! $type->fouillable || self::dejaFouille($entree, $personnageId)) {
                continue;
            }

            if ($this->adjacentAEmprise($entree, $x, $y)) {
                $trouves[] = [
                    'index' => (int) $index,
                    'entree' => $entree,
                    'nom' => (string) $type->nom,
                    'type' => $type,
                ];
            }
        }

        return $trouves;
    }

    /**
     * Ce héros a-t-il déjà fouillé ce meuble ?
     *
     * UNE FOIS PAR HÉROS depuis le 2026-08-17 (décision de René), comme une
     * salle : le premier arrivé n'épuise plus la pièce pour tout le groupe.
     *
     * ⚠ L'ancien drapeau booléen `fouille` est encore lu, et il vaut pour TOUT
     * LE MONDE : une quête déjà en cours au moment du changement garde des
     * meubles marqués ainsi, et les rouvrir d'un coup aurait rendu à ses héros
     * une fouille qu'ils avaient déjà dépensée.
     *
     * @param  array<string, mixed>  $entree
     */
    private static function dejaFouille(array $entree, ?int $personnageId): bool
    {
        if (! empty($entree['fouille'])) {
            return true; // format ancien : épuisé pour le groupe
        }

        if ($personnageId === null) {
            return false;
        }

        return in_array($personnageId, array_map('intval', (array) ($entree['fouille_par'] ?? [])), true);
    }

    /**
     * Marque le meuble comme fouillé PAR CE HÉROS — les autres gardent la leur.
     *
     * On empile les identifiants dans `fouille_par` plutôt que de poser un
     * booléen : c'est le même mécanisme que `quetes.tresors_fouilles` pour les
     * salles, et pour la même raison — le premier fouilleur ne doit pas fermer
     * la pièce à ses compagnons.
     */
    public function marquerFouille(Carte $carte, int $index, int $personnageId): void
    {
        $grille = $carte->grille;

        if (! isset($grille['mobilier'][$index])) {
            return;
        }

        $deja = array_map('intval', (array) ($grille['mobilier'][$index]['fouille_par'] ?? []));
        $grille['mobilier'][$index]['fouille_par'] = array_values(array_unique([...$deja, $personnageId]));

        $carte->update(['grille' => $grille]);
    }

    /**
     * Tire le butin d'un meuble dans SA table (`mobiliers.effet.fouille`), et
     * rend une carte de la même forme que celles du deck de fouille — pour que
     * `ResolveurTour::appliquerButin()` l'applique sans rien savoir d'où elle
     * vient.
     *
     * Tirage PONDÉRÉ (`poids`), et non uniforme : c'est ce qui permet à un
     * coffre de payer souvent et à un trône de décevoir la moitié du temps.
     *
     * Un meuble sans table déclarée rend `rien` — un catalogue incomplet ne doit
     * pas fabriquer de butin fantôme.
     *
     * @return array<string, mixed>
     */
    public function tirerButin(Mobilier $type, int $niveauMoyen = 1): array
    {
        $table = array_values(array_filter(
            (array) ($type->effet['fouille'] ?? []),
            fn ($e) => is_array($e) && (int) ($e['poids'] ?? 0) > 0,
        ));

        if ($table === []) {
            return ['issue' => 'rien', 'sans_table' => true];
        }

        $total = array_sum(array_map(fn ($e) => (int) $e['poids'], $table));
        $tirage = random_int(1, $total);
        $entree = $table[0];

        foreach ($table as $candidate) {
            $tirage -= (int) $candidate['poids'];

            if ($tirage <= 0) {
                $entree = $candidate;
                break;
            }
        }

        return $this->carteDepuisEntree($entree, $niveauMoyen);
    }

    /**
     * Traduit une entrée de table en carte de butin.
     *
     * @param  array<string, mixed>  $entree
     * @return array<string, mixed>
     */
    private function carteDepuisEntree(array $entree, int $niveauMoyen): array
    {
        $issue = (string) ($entree['issue'] ?? 'rien');

        if ($issue === 'tresor') {
            [$min, $max] = array_pad((array) ($entree['or'] ?? []), 2, null);
            $min = max(0, (int) ($min ?? 0));
            $max = max($min, (int) ($max ?? $min));

            return ['issue' => 'tresor', 'or' => random_int($min, $max)];
        }

        if ($issue === 'piege') {
            // Le NOM voyage avec la carte : `appliquerButin()` le résout en
            // catalogue. Lire « Piège de coffre » en ouvrant un tombeau casserait
            // la fiction, et le barème est le même de toute façon.
            return ['issue' => 'piege', 'piege' => (string) ($entree['piege'] ?? '')];
        }

        if ($issue === 'objet') {
            // ⚠ Jamais un `unique` : les artefacts n'ont qu'une seule source,
            // le coffre désigné de la quête, et ils sont uniques PAR GROUPE.
            // Un meuble qui en distribuerait viderait cette règle.
            $vivier = Objet::query()
                ->whereIn('categorie', (array) ($entree['categories'] ?? []))
                ->where('rarete', '!=', 'unique');

            // TIRAGE EN DEUX TEMPS depuis le 2026-08-17 (décision de René) :
            // d'abord la RARETÉ, pondérée par le niveau moyen du groupe, puis la
            // pièce, uniformément dans cette rareté.
            //
            // Le tirage était uniforme sur tout le vivier : un établi
            // d'alchimiste rendait une Potion de restauration supérieure (800 po)
            // aussi souvent qu'une Potion de soin (100), et un groupe de niveau 8
            // continuait de trouver des dagues. La progression ne se lisait nulle
            // part dans le butin.
            $disponibles = (clone $vivier)->distinct()->pluck('rarete')
                ->map(fn ($r) => (string) $r)->all();

            $rarete = RareteButin::tirer($disponibles, $niveauMoyen);

            $objet = $rarete === null
                ? null
                : $vivier->where('rarete', $rarete)->inRandomOrder()->first();

            return $objet === null
                ? ['issue' => 'rien', 'objet_indisponible' => true]
                : ['issue' => 'objet', 'objet_id' => (int) $objet->id, 'rarete' => $rarete];
        }

        return ['issue' => 'rien'];
    }

    /**
     * (x, y) touche-t-il l'EMPRISE du meuble par un côté ?
     *
     * L'emprise compte, pas l'origine : un tombeau 1×2 se fouille depuis l'une
     * ou l'autre de ses deux cases voisines, sans quoi la moitié d'un grand
     * meuble serait inatteignable.
     *
     * @param  array<string, mixed>  $entree
     */
    private function adjacentAEmprise(array $entree, int $x, int $y): bool
    {
        $ox = (int) $entree['x'];
        $oy = (int) $entree['y'];

        for ($dx = 0; $dx < (int) ($entree['l'] ?? 1); $dx++) {
            for ($dy = 0; $dy < (int) ($entree['h'] ?? 1); $dy++) {
                if (abs($ox + $dx - $x) + abs($oy + $dy - $y) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
