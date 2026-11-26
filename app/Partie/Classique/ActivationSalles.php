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
 * prévoir laquelle. Sa salle se découvre (`ResolveurTour::revelerSalleEn()`), ses portes closes
 * s'ouvrent (`ouvrirPorteParNote()`), ses monstres jouent dès ce tour.
 */
final class ActivationSalles
{
    /** Le nombre de salles, les plus proches des héros, parmi lesquelles le dé choisit. */
    public const PROCHES = 3;

    public function __construct(private readonly LanceurDes $des) {}

    public static function actif(Quete $quete): bool
    {
        return $quete->estConstruite() && data_get($quete->documentConstruit(), 'regles.activation_salles') === true;
    }

    /** Le dé rouge du tour du MJ, puis une ou deux salles. Rend l'annonce, ou `null` hors de cette règle. */
    public function auTourDuMJ(Groupe $groupe, Quete $quete): ?array
    {
        if (! self::actif($quete)) {
            return null;
        }
        // Plus aucune salle cachée ne garde de monstre : Zargon n'a plus rien à lever, ni dé ni annonce à chaque tour.
        if (! $this->resteUneSalle($quete)) {
            return null;
        }
        $de = $this->des->d6();
        $payload = ['type' => 'salles_activees', 'de' => $de, 'salles' => $this->activer($quete, $de === 6 ? 2 : 1)];
        app(TamponAnnonces::class)->annoncer($groupe, $payload);

        return $payload;
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
     * Reste-t-il une salle cachée qui garde des monstres ? Sinon, le tour du MJ ne lance rien et la carte de monstre
     * errant redevient un monstre errant (« Zargon MAY activate one room », René, 2026-11-26) : `ResolveurTour::appliquerButin()`.
     */
    public function resteUneSalle(Quete $quete): bool
    {
        return $this->sallesCachees($quete)->isNotEmpty();
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
        $cachees = $this->sallesCachees($quete);
        $heros = $quete->etatsPersonnages()->where('tombe', false)->whereNotNull('position_x')->get();

        // Les plus proches par le CHEMIN d'abord, puis un dé par salle à lever parmi les PROCHES restantes.
        $distances = $this->distances($quete, $heros);
        $pool = $cachees->keys()->map(fn ($i) => (int) $i)
            ->sortBy(fn (int $i) => collect(Salles::cases($salles[$i]))
                ->map(fn ($c) => $distances["{$c['x']},{$c['y']}"] ?? PHP_INT_MAX)->min() ?? PHP_INT_MAX)
            ->take(self::PROCHES)->values()->all();
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
