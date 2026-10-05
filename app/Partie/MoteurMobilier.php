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

            if ($type === null || ! $type->fouillable || self::estDetruite($entree)
                || self::dejaFouille($entree, $personnageId)) {
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
    public function tirerButin(Mobilier $type, int $niveauMoyen = 1, array $tagsAccessibles = []): array
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

        return $this->carteDepuisEntree($entree, $niveauMoyen, $tagsAccessibles);
    }

    /**
     * Traduit une entrée de table en carte de butin.
     *
     * @param  array<string, mixed>  $entree
     * @return array<string, mixed>
     */
    private function carteDepuisEntree(array $entree, int $niveauMoyen, array $tagsAccessibles = []): array
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

            // ⚠ On écarte ce que PERSONNE sur place ne peut utiliser (décision
            // de René, 2026-08-17). Trois potions sont réservées au Barbare,
            // deux à l'Elfe : sans cette garde, un groupe sans barbare passait
            // sa campagne à trouver des potions de rage guerrière — un butin qui
            // n'est ni jouable, ni revendable en quête, et qui prend une place
            // dans le sac.
            //
            // Même règle que les artefacts de classe (`DeckFouille`), et elle
            // vit désormais au même endroit qu'eux : `Equipement`. Liste vide =
            // aucune restriction (fail open), comme partout ailleurs ici.
            if ($tagsAccessibles !== []) {
                $vivier->where(fn ($q) => $q->whereNull('tag_equipement')
                    ->orWhere('tag_equipement', '')
                    ->orWhereIn('tag_equipement', $tagsAccessibles));
            }

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
    /**
     * Meubles DESTRUCTIBLES, encore debout, que ce héros n'a pas encore tenté
     * de mettre en pièces, orthogonalement adjacents à (x, y).
     *
     * Miroir exact de `fouillablesAdjacents()` — même géométrie, même forme de
     * retour — parce que les deux options naissent au même endroit du menu et
     * doivent se comporter pareil.
     *
     * ⚠ `difficulte_destruction` à `null` = INDESTRUCTIBLE : le tombeau est un
     * sarcophage de pierre. On ne propose pas une action qu'aucun jet ne peut
     * gagner.
     *
     * @return list<array{index: int, entree: array<string, mixed>, nom: string, type: Mobilier}>
     */
    public function destructiblesAdjacents(Carte $carte, int $x, int $y, ?int $personnageId = null): array
    {
        $entrees = (array) ($carte->grille['mobilier'] ?? []);

        if ($entrees === []) {
            return [];
        }

        $catalogue = Mobilier::query()
            ->whereIn('id', collect($entrees)->pluck('mobilier_id')->filter()->unique())
            ->whereNotNull('difficulte_destruction')
            ->get(['id', 'nom', 'fouillable', 'difficulte_destruction', 'effet'])
            ->keyBy('id');

        $trouves = [];

        foreach ($entrees as $index => $entree) {
            $type = $catalogue[$entree['mobilier_id'] ?? 0] ?? null;

            if ($type === null || self::estDetruite($entree)
                || self::dejaTenteeDestruction($entree, $personnageId)) {
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
     * La pièce a-t-elle été mise en pièces ?
     *
     * ⚠ Une pièce détruite reste DANS la grille, marquée `detruit`, au lieu
     * d'en être retirée. Deux raisons, et la seconde a failli mordre :
     *  - les index de `mobilier[]` servent d'identifiant d'option dans le menu
     *    (`detruire_mobilier_{index}`), et retirer une entrée décale tous les
     *    suivants — un menu périmé viserait alors le mauvais meuble ;
     *  - un tableau PHP troué se sérialise en OBJET JSON, plus en liste, et le
     *    front qui itère `carte.mobilier` recevrait soudain autre chose.
     *
     * C'est le même choix que les portes, qui gardent leur entrée et changent
     * d'`etat`.
     *
     * @param  array<string, mixed>  $entree
     */
    public static function estDetruite(array $entree): bool
    {
        return (bool) ($entree['detruit'] ?? false);
    }

    /**
     * Ce héros a-t-il déjà tenté de détruire cette pièce ?
     *
     * UNE TENTATIVE PAR HÉROS (décision de René, 2026-08-24) : l'échec ferme
     * l'option à celui qui a essayé, jamais à ses compagnons. Le prix réel est
     * le créneau d'action, et la troupe finit par y arriver — même patron que la
     * fouille (`fouille_par`), et que `quetes.tresors_fouilles` pour les salles.
     *
     * @param  array<string, mixed>  $entree
     */
    public static function dejaTenteeDestruction(array $entree, ?int $personnageId): bool
    {
        if ($personnageId === null) {
            return false;
        }

        return in_array(
            $personnageId,
            array_map('intval', (array) ($entree['destruction_par'] ?? [])),
            true,
        );
    }

    /** Inscrit la tentative de ce héros — réussie ou non, elle est dépensée. */
    public function marquerTentativeDestruction(Carte $carte, int $index, int $personnageId): void
    {
        $grille = $carte->grille;

        if (! isset($grille['mobilier'][$index])) {
            return;
        }

        $deja = array_map('intval', (array) ($grille['mobilier'][$index]['destruction_par'] ?? []));
        $grille['mobilier'][$index]['destruction_par'] = array_values(array_unique([...$deja, $personnageId]));

        $carte->update(['grille' => $grille]);
    }

    /**
     * Met la pièce en pièces : elle cesse de bloquer le mouvement ET la vue.
     *
     * Un seul drapeau suffit parce que `FabriqueGrille::pour()` tient la boucle
     * UNIQUE du mobilier de tout le moteur — une pièce ignorée là l'est pour le
     * déplacement, le ciblage et la ligne de vue d'un seul geste.
     *
     * ⚠ Aucun risque pour l'invariant de connectivité, à l'inverse d'un meuble
     * qu'on POUSSERAIT : retirer un obstacle ne peut qu'ouvrir le donjon.
     */
    public function detruire(Carte $carte, int $index): void
    {
        $grille = $carte->grille;

        if (! isset($grille['mobilier'][$index])) {
            return;
        }

        $grille['mobilier'][$index]['detruit'] = true;

        $carte->update(['grille' => $grille]);
    }

    /**
     * Cette salle contient-elle un meuble de ce TYPE, encore debout (ni
     * détruit) ?
     *
     * Point de passage pour toute règle qui se déclenche par la PRÉSENCE d'un
     * meuble dans la salle plutôt que par son adjacence — *Sly Storage*
     * (FL-Q p. 7, First Light) est la première : « la salle avec une armoire »
     * est un fait sur la SALLE, pas un meuble qu'on fouille soi-même, donc ni
     * `fouillablesAdjacents()` ni `destructiblesAdjacents()` (tous deux
     * géométriques, centrés sur le héros) ne répondent à la question.
     *
     * ⚠ Une pièce `detruit` ne compte plus : une armoire mise en pièces
     * n'offre plus de double tirage, exactement comme elle ne bloque plus la
     * vue ni le passage (`detruire()`).
     */
    public function salleContientType(Carte $carte, int $salle, string $nom): bool
    {
        $entrees = (array) ($carte->grille['mobilier'] ?? []);

        if ($entrees === []) {
            return false;
        }

        $idsDuType = Mobilier::query()->where('nom', $nom)->pluck('id')->all();

        if ($idsDuType === []) {
            return false;
        }

        foreach ($entrees as $entree) {
            if ((int) ($entree['salle'] ?? -1) === $salle
                && in_array((int) ($entree['mobilier_id'] ?? 0), $idsDuType, true)
                && ! self::estDetruite($entree)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Meubles ATTAQUABLES AU COMBAT (PV + défense, 2026-10-04) — la TROISIÈME
     * voie de destruction, après la fouille et le jet de Body : un meuble
     * qu'on frappe comme un monstre jusqu'à épuiser ses PV (Crystal Cluster de
     * *Jungles of Delthrak*, Haut Autel et Coffres du Dread de *Wizards of
     * Morcar* — toutes sourcées « attacked in the normal way »/« as a
     * monster »).
     *
     * ⚠ PAS de garde « une tentative par héros » ici, à la différence de
     * `destructiblesAdjacents()` : un monstre se refrappe sans limite tant
     * qu'il tient debout, et c'est exactement ce que ces sources décrivent —
     * un coup qui ne suffit pas laisse le meuble ENTAMÉ, pas fermé à qui vient
     * de frapper.
     *
     * @return list<array{index: int, entree: array<string, mixed>, nom: string, type: Mobilier}>
     */
    public function attaquablesAdjacents(Carte $carte, int $x, int $y): array
    {
        $entrees = (array) ($carte->grille['mobilier'] ?? []);

        if ($entrees === []) {
            return [];
        }

        $catalogue = Mobilier::query()
            ->whereIn('id', collect($entrees)->pluck('mobilier_id')->filter()->unique())
            ->whereNotNull('pv_body')
            ->get(['id', 'nom', 'pv_body', 'defense_dice', 'effet'])
            ->keyBy('id');

        $trouves = [];

        foreach ($entrees as $index => $entree) {
            $type = $catalogue[$entree['mobilier_id'] ?? 0] ?? null;

            if ($type === null || self::estDetruite($entree)) {
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
     * PV restants d'un meuble attaquable — initialisés aux PV du CATALOGUE
     * tant qu'aucun coup n'a encore été porté (`pv_restants` absent de
     * l'entrée). Même patron que `estDetruite()` : l'état de PARTIE vit dans
     * la grille, le catalogue reste la donnée de référence.
     *
     * @param  array<string, mixed>  $entree
     */
    public static function pvRestants(array $entree, Mobilier $type): int
    {
        return array_key_exists('pv_restants', $entree)
            ? (int) $entree['pv_restants']
            : (int) $type->pv_body;
    }

    /**
     * Inflige `$degats` au meuble d'index `$index`, et le détruit à 0 PV —
     * `detruire()` pose le MÊME drapeau `detruit` que le jet de Body, lu par
     * la boucle UNIQUE de `FabriqueGrille::pour()` : la pièce cesse de bloquer
     * mouvement ET vue d'un seul geste, quelle que soit la voie qui l'a
     * détruite.
     *
     * @return array{pv_restants: int, detruit: bool}
     */
    public function infligerDegats(Carte $carte, int $index, int $degats): array
    {
        $grille = $carte->grille;
        $entree = $grille['mobilier'][$index] ?? null;

        if ($entree === null) {
            return ['pv_restants' => 0, 'detruit' => true];
        }

        $type = Mobilier::find((int) ($entree['mobilier_id'] ?? 0));
        $avant = $type === null ? 0 : self::pvRestants($entree, $type);
        $apres = max(0, $avant - max(0, $degats));

        $grille['mobilier'][$index]['pv_restants'] = $apres;

        if ($apres <= 0) {
            $grille['mobilier'][$index]['detruit'] = true;
        }

        $carte->update(['grille' => $grille]);

        return ['pv_restants' => $apres, 'detruit' => $apres <= 0];
    }

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
