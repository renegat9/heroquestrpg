<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Carte;
use App\Models\Epreuve;

/**
 * Lecteur de la couche de carte `cartes.grille['epreuves']` — les ancrages
 * posés sur le donjon auxquels un héros à leur contact tente un jet
 * d'attribut (doc 01 §5).
 *
 * Calqué sur `MoteurMobilier` : même format de couche (une liste d'entrées
 * dans `grille`, l'état de progression écrit directement dedans plutôt que
 * dans une colonne, pour survivre aux snapshots sans migration supplémentaire
 * — voir le commentaire de `MoteurMobilier` sur ce choix), même découpage en
 * quatre méthodes.
 *
 * ⚠ DIFFÉRENCE avec le mobilier, et elle est voulue : une épreuve n'a PAS
 * d'emprise à plusieurs cases (`l`/`h`) et ne bloque rien — c'est un ancrage,
 * pas un meuble. Un héros peut se tenir DESSUS, contrairement à un coffre ou
 * une bibliothèque ; `adjacentes()` traite donc la case elle-même comme
 * valide, pas seulement ses quatre voisines.
 *
 * ⚠ AUTRE différence, cruciale : `MoteurMobilier` referme un meuble pour TOUT
 * LE GROUPE dès la première fouille (c'est un objet physique qu'on vide).
 * Une épreuve, elle, se retente PAR HÉROS — chacun mesure son propre
 * attribut contre le même ancrage, et le coût réel est le CRÉNEAU d'action
 * dépensé, pas l'ancrage lui-même. D'où `tentee_par`, qui empile des ids
 * exactement comme `fouille_par`, mais sans l'équivalent du drapeau booléen
 * hérité — cette mécanique est neuve, il n'existe aucun format ancien à
 * respecter.
 */
final class MoteurEpreuves
{
    /**
     * Les entrées brutes de la couche `epreuves` de la carte, telles
     * qu'écrites par l'assembleur — aucun enrichissement catalogue ici,
     * c'est le rôle d'`adjacentes()`.
     *
     * @return list<array<string, mixed>>
     */
    public function epreuves(Carte $carte): array
    {
        return array_values((array) ($carte->grille['epreuves'] ?? []));
    }

    /**
     * Épreuves orthogonalement adjacentes À (x, y) OU SUR (x, y), que ce
     * héros n'a pas encore tentées — enrichies de leur ligne de catalogue
     * pour que l'appelant (menu, résolveur) n'ait pas à refaire la jointure.
     *
     * ⚠ « OU sur la case » : une épreuve ne bloque pas le passage (contraire
     * du mobilier fouillable, qu'on ne peut qu'aborder de côté), donc un
     * héros qui s'arrête littéralement dessus doit pouvoir la tenter — sans
     * ce cas la moitié des ancrages posés en cul-de-sac serait inatteignable.
     *
     * @return list<array{index: int, entree: array<string, mixed>, nom: string, description: string, attribut: string, difficulte: int, contexte: ?string, effet: array<string, mixed>}>
     */
    public function adjacentes(Carte $carte, int $x, int $y, ?int $personnageId = null): array
    {
        $entrees = $this->epreuves($carte);

        if ($entrees === []) {
            return [];
        }

        $catalogue = Epreuve::query()
            ->whereIn('id', collect($entrees)->pluck('epreuve_id')->filter()->unique())
            ->get(['id', 'nom', 'description', 'attribut', 'difficulte', 'contexte', 'effet'])
            ->keyBy('id');

        $trouvees = [];

        foreach ($entrees as $index => $entree) {
            $type = $catalogue[$entree['epreuve_id'] ?? 0] ?? null;

            if ($type === null || self::dejaTentee($entree, $personnageId)) {
                continue;
            }

            $dx = (int) $entree['x'] - $x;
            $dy = (int) $entree['y'] - $y;

            if (abs($dx) + abs($dy) > 1) {
                continue;
            }

            $trouvees[] = [
                'index' => (int) $index,
                'entree' => $entree,
                'nom' => (string) $type->nom,
                'description' => (string) $type->description,
                'attribut' => (string) $type->attribut,
                'difficulte' => (int) $type->difficulte,
                'contexte' => $type->contexte,
                'effet' => (array) $type->effet,
            ];
        }

        return $trouvees;
    }

    /**
     * Ce héros a-t-il déjà tenté cette épreuve ?
     *
     * UNE TENTATIVE PAR HÉROS, QUEL QUE SOIT LE RÉSULTAT — un échec compte
     * autant qu'une réussite. C'est le créneau d'action dépensé qui fait la
     * difficulté réelle de l'épreuve, pas un nombre de tentatives : sans
     * cette règle, un groupe bloqué sur un jet difficile le retenterait en
     * boucle avec chaque héros jusqu'à obtenir la réussite garantie.
     *
     * @param  array<string, mixed>  $entree
     */
    public static function dejaTentee(array $entree, ?int $personnageId): bool
    {
        if ($personnageId === null) {
            return false;
        }

        return in_array($personnageId, array_map('intval', (array) ($entree['tentee_par'] ?? [])), true);
    }

    /**
     * Marque l'épreuve comme tentée PAR CE HÉROS — les autres gardent la
     * leur, exactement comme `MoteurMobilier::marquerFouille()` empile
     * `fouille_par` plutôt que de poser un booléen global.
     */
    public function marquerTentee(Carte $carte, int $index, int $personnageId): void
    {
        $grille = $carte->grille;

        if (! isset($grille['epreuves'][$index])) {
            return;
        }

        $deja = array_map('intval', (array) ($grille['epreuves'][$index]['tentee_par'] ?? []));
        $grille['epreuves'][$index]['tentee_par'] = array_values(array_unique([...$deja, $personnageId]));

        $carte->update(['grille' => $grille]);
    }
}
