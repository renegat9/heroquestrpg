<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Fouille\DeckFouille;
use App\Partie\Salles;

/**
 * LA PLANQUE (mode classique, lot E2 — *Rise of the Dread Moon* p. 7, « Hideouts ») : « Each hideout comes equipped with
 * an Alchemist's Bench, may be searched for treasure once per quest, and is safe from threats. Any Wandering Monster or
 * Hazard cards drawn when searching the hideout do not take effect and are immediately returned to the bottom of the
 * deck. As an action once per quest while in the hideout, each hero may roll one red die and restore that number of
 * points, divided among their Body Points and Mind Points (not exceeding their totals for each). The hero decides the
 * distribution between the two pools of points. »
 *
 * `regles.planque: id de salle`. Point de passage unique des quatre règles :
 *  - {@see fouilleFermee()} : UNE fouille du trésor par quête, tous héros confondus (menu ET résolveur) ;
 *  - {@see carteSansEffet()} : la carte de monstre errant ou de danger tirée là ne joue pas, et retourne sous le paquet ;
 *  - {@see soinDisponible()} / {@see lancer()} : l'action, une fois par quête et par héros — le dé rouge ;
 *  - {@see repartitions()} / {@see repartir()} : le héros choisit ensuite la part du Corps (le reste va à l'Esprit),
 *    parmi la liste que le menu publie et que le résolveur revalide.
 * « Safe from threats » ne dit rien de plus que les cartes : aucune autre règle n'est ajoutée (décision écrite).
 *
 * État durable : `quetes.drapeaux._planque` `{soignes: [id], jets: {id: n}}` (clé réservée).
 */
final class Planque
{
    public const DRAPEAU = '_planque';

    public function __construct(private readonly LanceurDes $des) {}

    /** L'index de la salle-planque de la quête, ou `null`. */
    public function index(Quete $quete): ?int
    {
        $id = $quete->estConstruite() ? data_get($quete->documentConstruit(), 'regles.planque') : null;
        if (! is_string($id) || $quete->carte === null) {
            return null;
        }
        foreach ((array) ($quete->carte->grille['salles'] ?? []) as $i => $salle) {
            if (($salle['id'] ?? null) === $id) {
                return (int) $i;
            }
        }

        return null;
    }

    public function contient(Quete $quete, ?EtatPersonnageQuete $etat): bool
    {
        $index = $this->index($quete);

        return $index !== null && $etat !== null && $etat->position_x !== null
            && Salles::indexDe((array) ($quete->carte->grille['salles'] ?? []), (int) $etat->position_x, (int) $etat->position_y) === $index;
    }

    /** La planque a déjà été fouillée cette quête, par qui que ce soit : elle ne se fouille plus. */
    public function fouilleFermee(Quete $quete, int $salle): bool
    {
        return $this->index($quete) === $salle && in_array($salle, $quete->tresorsFouilles(), true);
    }

    /**
     * La carte tirée dans la planque ne joue pas : monstre errant ou danger (« Hazard »). Une carte que le tirage n'a pas
     * déjà remise sous le paquet y retourne (`DeckFouille::sortDe()`).
     */
    public function carteSansEffet(Quete $quete, int $salle, array $carte): bool
    {
        if ($this->index($quete) !== $salle || ! in_array($carte['issue'] ?? null, ['errant', 'piege'], true)) {
            return false;
        }
        if (DeckFouille::sortDe($carte) !== DeckFouille::REMISE_SOUS_LE_PAQUET) {
            $quete->remettreSousLePaquet($carte);
        }

        return true;
    }

    /** L'action de soin s'offre : dans la planque, pas encore prise cette quête, un point perdu, aucun jet en attente. */
    public function soinDisponible(Quete $quete, Personnage $heros, ?EtatPersonnageQuete $etat): bool
    {
        $etat_ = $this->etat($quete);

        return $this->contient($quete, $etat) && ! in_array((int) $heros->id, $etat_['soignes'], true)
            && ((int) $heros->pv_body < (int) $heros->pv_body_max || (int) $heros->pv_mind < (int) $heros->pv_mind_max);
    }

    /** Le dé rouge déjà lancé, en attente de répartition, ou `null`. */
    public function jetEnAttente(Quete $quete, Personnage $heros): ?int
    {
        $jet = $this->etat($quete)['jets'][(string) $heros->id] ?? null;

        return $jet === null ? null : (int) $jet;
    }

    /**
     * Les répartitions possibles de `$jet` : la part du Corps, le reste à l'Esprit, sans dépasser un maximum. Quand les
     * points perdus ne suffisent pas à tout recevoir, l'excédent se perd ; deux répartitions qui rendent la même chose
     * n'en font qu'une.
     *
     * @return list<array{corps: int, esprit: int}>
     */
    public function repartitions(Personnage $heros, int $jet): array
    {
        $manqueCorps = max(0, (int) $heros->pv_body_max - (int) $heros->pv_body);
        $manqueEsprit = max(0, (int) $heros->pv_mind_max - (int) $heros->pv_mind);
        $liste = [];
        for ($corps = min($jet, $manqueCorps); $corps >= 0; $corps--) {
            $esprit = min($jet - $corps, $manqueEsprit);
            $liste["{$corps}:{$esprit}"] = ['corps' => $corps, 'esprit' => $esprit];
        }
        // Une répartition qui rend MOINS qu'une autre sur les deux jauges ne s'offre pas.
        $liste = array_filter($liste, fn (array $r) => ! array_filter($liste,
            fn (array $a) => $a !== $r && $a['corps'] >= $r['corps'] && $a['esprit'] >= $r['esprit']));

        return array_values($liste);
    }

    /**
     * L'action : le dé rouge. Une seule répartition possible se joue aussitôt ; sinon le jet attend le choix du héros.
     *
     * @return array{jet: int, repartitions: list<array{corps: int, esprit: int}>, applique: ?array{corps: int, esprit: int}}
     */
    public function lancer(Quete $quete, Personnage $heros): array
    {
        $jet = $this->des->d6();
        $etat = $this->etat($quete);
        $etat['soignes'][] = (int) $heros->id;
        $repartitions = $this->repartitions($heros, $jet);
        $applique = null;
        if (count($repartitions) <= 1) {
            $applique = $repartitions[0] ?? ['corps' => 0, 'esprit' => 0];
            $this->appliquer($heros, $applique);
        } else {
            $etat['jets'][(string) $heros->id] = $jet;
        }
        $this->ecrire($quete, $etat);

        return ['jet' => $jet, 'repartitions' => $repartitions, 'applique' => $applique];
    }

    /** Le choix du héros, revalidé contre la liste publiée. `null` : ce choix n'en fait pas partie. */
    public function repartir(Quete $quete, Personnage $heros, int $corps): ?array
    {
        $jet = $this->jetEnAttente($quete, $heros);
        $choix = $jet === null ? null : collect($this->repartitions($heros, $jet))->firstWhere('corps', $corps);
        if ($choix === null) {
            return null;
        }
        $this->appliquer($heros, $choix);
        $etat = $this->etat($quete);
        unset($etat['jets'][(string) $heros->id]);
        $this->ecrire($quete, $etat);

        return $choix + ['jet' => $jet];
    }

    private function appliquer(Personnage $heros, array $choix): void
    {
        $heros->update([
            'pv_body' => min((int) $heros->pv_body_max, (int) $heros->pv_body + $choix['corps']),
            'pv_mind' => min((int) $heros->pv_mind_max, (int) $heros->pv_mind + $choix['esprit']),
        ]);
    }

    /** @return array{soignes: list<int>, jets: array<string, int>} */
    private function etat(Quete $quete): array
    {
        $brut = (array) data_get($quete->relire('drapeaux')->drapeaux, self::DRAPEAU, []);

        return ['soignes' => array_map('intval', (array) ($brut['soignes'] ?? [])), 'jets' => (array) ($brut['jets'] ?? [])];
    }

    private function ecrire(Quete $quete, array $etat): void
    {
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::DRAPEAU] = $etat;
        $quete->update(['drapeaux' => $drapeaux]);
    }
}
