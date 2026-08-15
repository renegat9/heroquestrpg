<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Competence;
use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;

/**
 * Les quatre STYLES ÉLÉMENTAIRES du Moine (Path of the Wandering Monk, 2023 —
 * `reference/18_extensions.md` §Path of the Wandering Monk).
 *
 * Carte *Using Elemental Styles*, mot pour mot :
 *
 *  - « Each Elemental Style contains two techniques that can be found on either
 *    side of its card. **Once per turn**, choose one technique to activate.
 *    After you use a technique, **that Elemental Style is exhausted**. »
 *  - « The Elemental Style of **Fire cannot be used until you have exhausted
 *    Air, Earth, and Water**. »
 *  - « If there are **no monsters in your line of sight at the start of your
 *    turn**, recover all exhausted Elemental Styles. »
 *
 * Trois règles, trois rythmes différents, et c'est ce qui vaut à ce moteur
 * d'exister plutôt que de vivre en morceaux dans `MenuMoteur` : le style
 * s'épuise à l'usage, l'activation est limitée par TOUR, et la recharge dépend
 * de l'état du plateau. Le Feu verrouillé fait le reste : le Moine ne frappe
 * son coup le plus fort qu'au bout d'un vrai combat.
 */
final class StylesElementaires
{
    /** Mécanique du nœud « carte de style » (CompetenceSeeder). */
    public const MECANIQUE = 'style_elementaire';

    /** Marqueur du compteur par TOUR : un seul style activé par tour. */
    public const ACTIVATION_DU_TOUR = 'Style élémentaire';

    public function __construct(private readonly CapacitesInnees $capacites) {}

    /**
     * Les cartes de style du héros (vide pour tout le monde sauf le Moine).
     *
     * @return list<Competence>
     */
    public function cartes(Personnage $personnage): array
    {
        return $personnage->competences()
            ->get(['competences.id', 'competences.nom', 'competences.description', 'competences.effet'])
            ->filter(fn (Competence $c) => ($c->effet['mecanique'] ?? null) === self::MECANIQUE)
            ->values()
            ->all();
    }

    /** Éléments déjà dépensés dans cette quête (et pas encore récupérés). */
    public function epuises(?EtatPersonnageQuete $etat): array
    {
        return array_values((array) ($etat?->styles_epuises ?? []));
    }

    /**
     * Le style est-il ACTIVABLE maintenant : non épuisé, verrou du Feu levé, et
     * aucun style déjà activé ce tour ?
     */
    public function activable(
        Personnage $personnage,
        ?EtatPersonnageQuete $etat,
        Competence $style,
        bool $horsTour = false,
    ): bool {
        if ($etat === null) {
            return false;
        }

        $epuises = $this->epuises($etat);
        $element = (string) ($style->effet['element'] ?? '');

        if (in_array($element, $epuises, true)) {
            return false;
        }

        // « Once per turn, choose ONE technique to activate » : le compteur par
        // tour porte sur l'activation, pas sur la carte — deux styles dans le
        // même tour, ce serait deux techniques.
        // ⚠ …et pas HORS TOUR : *Torrent Tournoyant* s'active « when you take
        // damage », donc pendant le tour d'un monstre. « Once per turn » régit
        // ce qu'on fait de SON tour ; seul l'épuisement de l'Eau la limite.
        if (! $horsTour && $this->capacites->dejaUtilisee($etat, self::ACTIVATION_DU_TOUR, 'capacites_tour')) {
            return false;
        }

        // Verrou du Feu : il attend que les trois autres soient tombés.
        foreach ((array) ($style->effet['exige_epuises'] ?? []) as $requis) {
            if (! in_array((string) $requis, $epuises, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrouve une technique par sa mécanique, avec la carte qui la porte —
     * point d'entrée des résolveurs, qui ne connaissent que leur propre effet.
     *
     * @return array{style: Competence, technique: array<string, mixed>}|null
     */
    public function technique(Personnage $personnage, string $mecanique): ?array
    {
        foreach ($this->cartes($personnage) as $style) {
            foreach ((array) ($style->effet['techniques'] ?? []) as $technique) {
                if (($technique['effet']['mecanique'] ?? null) === $mecanique) {
                    return ['style' => $style, 'technique' => $technique];
                }
            }
        }

        return null;
    }

    /**
     * ARBITRE des mécaniques à DEUX sources — `attaque_balayee` est à la fois
     * la *Frénésie sanguinaire* du Berserker (capacité innée « once per quest »)
     * et l'*Œil du Cyclone* du Moine (technique du Style de l'Air). Le menu
     * comme le résolveur ont besoin de la même réponse : d'où vient le pouvoir,
     * et à quelles conditions.
     *
     * Rend `null` si le héros ne peut pas l'employer maintenant, quelle que soit
     * la source.
     *
     * @return array{nom: string, effet: array<string, mixed>, style: Competence|null, mecanique: string}|null
     */
    public function sourceActivable(
        Personnage $personnage,
        ?EtatPersonnageQuete $etat,
        string $mecanique,
        bool $horsTour = false,
    ): ?array {
        // 1. Capacité de carte ordinaire (Berserker, Rogue, Explorateur…).
        if ($this->capacites->disponible($personnage, $etat, $mecanique)) {
            $noeud = $this->capacites->noeud($personnage, $mecanique);

            return ['nom' => (string) $noeud->nom, 'effet' => (array) $noeud->effet,
                'style' => null, 'mecanique' => $mecanique];
        }

        // 2. Technique d'un Style Élémentaire encore activable.
        $trouvee = $this->technique($personnage, $mecanique);

        if ($trouvee !== null && $this->activable($personnage, $etat, $trouvee['style'], $horsTour)) {
            return ['nom' => (string) ($trouvee['technique']['nom'] ?? $trouvee['style']->nom),
                'effet' => (array) ($trouvee['technique']['effet'] ?? []),
                'style' => $trouvee['style'], 'mecanique' => $mecanique];
        }

        return null;
    }

    /**
     * Dépense la source rendue par `sourceActivable()` — le style entier si
     * c'en est un, le compteur de la capacité sinon.
     *
     * @param  array{style: Competence|null, mecanique: string}  $source
     */
    public function depenser(
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $source,
        bool $horsTour = false,
    ): void {
        if ($source['style'] !== null) {
            $this->activer($etat, $source['style'], $horsTour);

            return;
        }

        $this->capacites->consommer($personnage, $etat, $source['mecanique']);
    }

    /**
     * Dépense le style : l'élément part aux épuisés et le tour est marqué.
     *
     * ⚠ C'est le STYLE ENTIER qui tombe, pas la technique — les deux faces
     * d'une même carte, on ne peut en jouer qu'une.
     */
    public function activer(EtatPersonnageQuete $etat, Competence $style, bool $horsTour = false): void
    {
        $epuises = $this->epuises($etat);
        $epuises[] = (string) ($style->effet['element'] ?? '');

        $modifications = ['styles_epuises' => array_values(array_unique($epuises))];

        // Une technique jouée hors tour ne consomme pas l'activation du tour
        // à venir : elle n'a pas été prise sur SON tour.
        if (! $horsTour) {
            $duTour = (array) ($etat->capacites_tour ?? []);
            $duTour[] = self::ACTIVATION_DU_TOUR;
            $modifications['capacites_tour'] = array_values(array_unique($duTour));
        }

        $etat->update($modifications);
    }

    /**
     * Marque une technique comme ACTIVE pour le tour — celles dont l'effet ne
     * se résout pas sur-le-champ mais change la suite du tour (*Vague
     * Montante*, qui rend au héros le droit de bouger après avoir agi).
     *
     * Le marqueur vit avec les capacités du tour : il tombe au même moment.
     */
    public function marquerActive(EtatPersonnageQuete $etat, string $mecanique): void
    {
        $duTour = (array) ($etat->capacites_tour ?? []);
        $duTour[] = $mecanique;

        $etat->update(['capacites_tour' => array_values(array_unique($duTour))]);
    }

    public function estActiveCeTour(?EtatPersonnageQuete $etat, string $mecanique): bool
    {
        return in_array($mecanique, (array) ($etat?->capacites_tour ?? []), true);
    }

    /**
     * DÉBUT DE TOUR — « If there are no monsters in your line of sight at the
     * start of your turn, recover all exhausted Elemental Styles. »
     *
     * Pris au mot : la ligne de vue du héros, pas l'état général du donjon. Un
     * Moine qui décroche derrière un mur récupère ; un Moine qui voit encore la
     * bête, non — c'est la règle qui l'oblige à doser ses styles pendant que le
     * combat dure.
     *
     * Idempotent, et sans effet hors début de tour : appelé aussi bien à la
     * génération du menu qu'à la résolution, les deux devant montrer la MÊME
     * disponibilité.
     */
    public function recupererSiHorsDeVue(Quete $quete, EtatPersonnageQuete $etat): void
    {
        if ($this->epuises($etat) === []
            || $etat->a_joue || $etat->a_agi || $etat->a_deplace
            || $etat->position_x === null) {
            return;
        }

        // Le prédicat vit dans `MoteurSorts` depuis que les potions officielles
        // de rage guerrière et de peau de givre en ont besoin elles aussi
        // (durée `plus_de_monstre_en_vue`). Deux implémentations de « un monstre
        // me voit » finiraient par diverger.
        if (! app(MoteurSorts::class)->monstreEnVue($quete, $etat)) {
            $etat->update(['styles_epuises' => []]);
        }
    }
}
