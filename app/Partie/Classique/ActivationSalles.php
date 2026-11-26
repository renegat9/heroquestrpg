<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\LanceurDes;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\MoteurPortes;
use App\Partie\ResolveurTour;
use App\Partie\Salles;
use App\Partie\TamponAnnonces;

/**
 * L'ACTIVATION DES SALLES (mode classique, lot E2 — *Against the Ogre Horde* q. 10, « Flight to the Surface ») : « On each
 * of their turns, Zargon may activate all the monsters in one or two rooms, regardless of whether the door to that room
 * has been opened. Zargon rolls one red die. On a roll result of 1-5, one room may be activated. On a roll result of 6,
 * two rooms may be activated. The contents of the room are then laid out, and the door is opened. Any monsters in the
 * activated room may move on that turn. » Et : « If a wandering monster card is drawn, Zargon may activate one room. »
 *
 * `regles.activation_salles: true`. Au début de chaque tour du MJ ({@see auTourDuMJ()}) et à la place de la carte de
 * monstre errant ({@see activer()}, `ResolveurTour::appliquerButin()`). Le livret laisse Zargon choisir (« may ») ; le
 * moteur joue Zargon (René, 2026-11-24 : « j'irais avec un hasard… les 3 salles dont le CHEMIN est plus proche des
 * joueurs ») — il tire au dé parmi les {@see PROCHES} salles encore cachées qui abritent des monstres et dont le chemin
 * depuis un héros debout est le plus court ({@see distances()}) : le MJ presse les héros qui fuient, sans qu'on puisse
 * prévoir laquelle. Au-delà de {@see PORTEE} pas, une salle ne se lève pas ; sans salle à portée, le monstre errant de la
 * quête surgit à la place (René, 2026-11-26). Sa salle se découvre (`ResolveurTour::revelerSalleEn()`), ses portes closes
 * s'ouvrent (`ouvrirPorteParNote()`), ses monstres jouent dès ce tour.
 */
final class ActivationSalles
{
    /** Le nombre de salles, les plus proches des héros, parmi lesquelles le dé choisit. */
    public const PROCHES = 3;

    /**
     * La PORTÉE, en pas de chemin depuis un héros debout jusqu'à la case la plus proche de la salle : au-delà, la salle
     * ne se lève pas (René, 2026-11-26 : « une limite… si les salles sont vraiment loin »). Douze pas : un peu plus
     * qu'un tour de marche d'un orque (8) ou d'un gobelin (10), assez pour que les figures levées pèsent sur la fuite.
     * Aucune salle à portée : un monstre errant à la place, au tour du MJ comme sur la carte de fouille.
     */
    public const PORTEE = 12;

    public function __construct(private readonly LanceurDes $des) {}

    public static function actif(Quete $quete): bool
    {
        return $quete->estConstruite() && data_get($quete->documentConstruit(), 'regles.activation_salles') === true;
    }

    /**
     * Le début du tour du MJ. Une salle à portée : le dé rouge, puis une ou deux salles (`salles_activees`). Aucune
     * (toutes trop loin, ou toutes levées) : les monstres errants de la quête près d'un héros debout tiré au dé, qui
     * jouent ce tour avec les autres (`errant_au_tour_du_mj`). Rend l'annonce, ou `null` (règle absente, aucun errant).
     */
    public function auTourDuMJ(Groupe $groupe, Quete $quete): ?array
    {
        if (! self::actif($quete)) {
            return null;
        }
        if (! $this->aUneSalleAPortee($quete)) {
            $payload = $this->errantAuTourDuMJ($quete);
        } else {
            $de = $this->des->d6();
            $payload = ['type' => 'salles_activees', 'de' => $de, 'salles' => $this->activer($quete, $de === 6 ? 2 : 1)];
        }
        if ($payload !== null) {
            app(TamponAnnonces::class)->annoncer($groupe, $payload);
        }

        return $payload;
    }

    /** Reste-t-il une salle cachée habitée à {@see PORTEE} d'un héros debout ? Sinon, un monstre errant à sa place. */
    public function aUneSalleAPortee(Quete $quete): bool
    {
        return $this->candidates($quete)[1] !== [];
    }

    /**
     * Au tour du MJ, sans salle à portée : les errants de la quête, posés près d'un héros debout (un dé s'ils sont
     * plusieurs), par `ResolveurTour::poserErrants()`.
     *
     * @return array{type: string, heros: string, monstres: list<string>}|null
     */
    private function errantAuTourDuMJ(Quete $quete): ?array
    {
        $heros = $quete->etatsPersonnages()->where('tombe', false)->whereNotNull('position_x')->with('personnage')->get()->values();
        if ($heros->isEmpty()) {
            return null;
        }
        $vise = $heros[$heros->count() === 1 ? 0 : ($this->des->d6() - 1) % $heros->count()];
        $poses = app(ResolveurTour::class)->poserErrants($quete->fresh(), $vise);
        if ($poses === []) {
            return null;
        }

        return [
            'type' => 'errant_au_tour_du_mj',
            'heros' => (string) ($vise->personnage?->nom ?? 'un héros'),
            'monstres' => array_map(fn (InstanceMonstre $m) => $m->nomAffiche(), $poses),
        ];
    }

    /**
     * Le plus court CHEMIN (en pas) d'un héros debout jusqu'à chaque case du plateau. Les murs et la roche barrent ; les
     * portes, non — « regardless of whether the door to that room has been opened » —, sauf un passage secret encore
     * introuvé ; ni figure ni meuble n'entre dans le calcul (`Grille::depuisCarte()` ne les pose pas). Une case qu'aucun
     * chemin n'atteint n'a pas de distance.
     *
     * @param  iterable<\App\Models\EtatPersonnageQuete>  $heros
     * @return array<string, int> "x,y" → pas
     */
    private function distances(Quete $quete, iterable $heros): array
    {
        $grille = (array) $quete->carte->grille;
        foreach ((array) ($grille['portes'] ?? []) as $i => $porte) {
            if (($porte['etat'] ?? null) !== MoteurPortes::ETAT_SECRETE || ($porte['revele'] ?? false)) {
                $grille['portes'][$i]['etat'] = MoteurPortes::ETAT_OUVERTE;
            }
        }
        $plan = Grille::depuisCarte(new \App\Models\Carte(['grille' => $grille]));

        $distances = [];
        foreach ($heros as $h) {
            $distances["{$h->position_x},{$h->position_y}"] = 0;
            foreach ($plan->casesAtteignables((int) $h->position_x, (int) $h->position_y, 999) as $cle => $chemin) {
                $distances[$cle] = min($distances[$cle] ?? PHP_INT_MAX, count($chemin));
            }
        }

        return $distances;
    }

    /**
     * Les monstres actifs encore cachés, groupés par l'index de leur salle non découverte.
     *
     * @return \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, InstanceMonstre>>
     */
    private function sallesCachees(Quete $quete): \Illuminate\Support\Collection
    {
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $decouvertes = $quete->sallesDecouvertes();

        return $quete->instancesMonstres()->where('etat', 'actif')->where('revele', false)->whereNotNull('position_x')->get()
            ->groupBy(fn (InstanceMonstre $m) => Salles::indexDe($salles, (int) $m->position_x, (int) $m->position_y))
            ->filter(fn ($groupe, $index) => $index !== '' && ! in_array((int) $index, $decouvertes, true));
    }

    /**
     * Les salles qui peuvent se lever : cachées, habitées, à {@see PORTEE} pas d'un héros debout au plus ; les
     * {@see PROCHES} plus proches par le CHEMIN ({@see distances()}).
     *
     * @return array{0: \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, InstanceMonstre>>, 1: list<int>}
     */
    private function candidates(Quete $quete): array
    {
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $cachees = $this->sallesCachees($quete);
        if ($cachees->isEmpty()) {
            return [$cachees, []];
        }
        $distances = $this->distances($quete, $quete->etatsPersonnages()->where('tombe', false)->whereNotNull('position_x')->get());
        $pool = $cachees->keys()->map(fn ($i) => (int) $i)
            ->mapWithKeys(fn (int $i) => [$i => collect(Salles::cases($salles[$i]))
                ->map(fn ($c) => $distances["{$c['x']},{$c['y']}"] ?? PHP_INT_MAX)->min() ?? PHP_INT_MAX])
            ->filter(fn (int $pas) => $pas <= self::PORTEE)
            ->sort()->keys()->take(self::PROCHES)->values()->all();

        return [$cachees, $pool];
    }

    /**
     * Jusqu'à `$nombre` salles DISTINCTES parmi `$pool`, un dé par salle tant qu'il reste le choix : trois candidates, deux
     * faces chacune ; deux, trois faces chacune ; une seule, elle se lève sans dé. Moins de candidates que de salles
     * demandées (un 6 avec une seule salle restante) : toutes se lèvent, et rien de plus.
     *
     * @param  list<int>  $pool
     * @return list<int>
     */
    private function tirer(array $pool, int $nombre): array
    {
        $choisies = [];
        while (count($choisies) < $nombre && $pool !== []) {
            $tire = count($pool) === 1 ? 0 : ($this->des->d6() - 1) % count($pool);
            $choisies[] = $pool[$tire];
            array_splice($pool, $tire, 1);
        }

        return $choisies;
    }

    /**
     * Active jusqu'à `$nombre` salles. Rend, pour chacune, les noms des figures qui s'y lèvent.
     *
     * @return list<array{monstres: list<string>}>
     */
    public function activer(Quete $quete, int $nombre): array
    {
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        [$cachees, $pool] = $this->candidates($quete);
        $choisies = $this->tirer($pool, $nombre);

        $resolveur = app(ResolveurTour::class);
        $activees = [];
        foreach ($choisies as $index) {
            $noms = $cachees[$index]->map(fn (InstanceMonstre $m) => $m->nomAffiche())->values()->all();
            // « the door is opened » : chaque porte close de la salle.
            foreach ((array) ($quete->carte->fresh()->grille['portes'] ?? []) as $i => $porte) {
                if (($porte['etat'] ?? null) === MoteurPortes::ETAT_FERMEE
                    && collect(Grille::casesPorte($porte))->contains(fn ($c) => Salles::indexDe($salles, (int) $c['x'], (int) $c['y']) === $index)) {
                    $resolveur->ouvrirPorteParNote($quete->fresh(), (int) $i);
                }
            }
            $premiere = Salles::cases($salles[$index])[0];
            $resolveur->revelerSalleEn($quete->fresh(), (int) $premiere['x'], (int) $premiere['y']);
            $activees[] = ['monstres' => $noms];
        }

        return $activees;
    }
}
