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
     * Active jusqu'à `$nombre` salles. Rend, pour chacune, les noms des figures qui s'y lèvent.
     *
     * @return list<array{monstres: list<string>}>
     */
    public function activer(Quete $quete, int $nombre): array
    {
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $decouvertes = $quete->sallesDecouvertes();
        $cachees = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', false)->whereNotNull('position_x')->get()
            ->groupBy(fn (InstanceMonstre $m) => Salles::indexDe($salles, (int) $m->position_x, (int) $m->position_y))
            ->filter(fn ($groupe, $index) => $index !== '' && ! in_array((int) $index, $decouvertes, true));
        $heros = $quete->etatsPersonnages()->where('tombe', false)->whereNotNull('position_x')->get();

        // Les plus proches par le CHEMIN d'abord, puis un dé par salle à lever parmi les PROCHES restantes.
        $distances = $this->distances($quete, $heros);
        $pool = $cachees->keys()->map(fn ($i) => (int) $i)
            ->sortBy(fn (int $i) => collect(Salles::cases($salles[$i]))
                ->map(fn ($c) => $distances["{$c['x']},{$c['y']}"] ?? PHP_INT_MAX)->min() ?? PHP_INT_MAX)
            ->take(self::PROCHES)->values()->all();
        $choisies = [];
        for ($k = 0; $k < $nombre && $pool !== []; $k++) {
            $tire = ($this->des->d6() - 1) % count($pool);
            $choisies[] = $pool[$tire];
            array_splice($pool, $tire, 1);
        }

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
