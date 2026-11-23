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
 * monstre errant ({@see activer()}, `ResolveurTour::appliquerButin()`). Le livret laisse Zargon choisir (« may ») ;
 * décision écrite : le moteur active la salle encore cachée qui abrite des monstres et se trouve la plus PROCHE d'un
 * héros debout — le MJ presse les héros qui fuient. Sa salle se découvre (`ResolveurTour::revelerSalleEn()`), ses portes
 * closes s'ouvrent (`ouvrirPorteParNote()`), ses monstres jouent dès ce tour.
 */
final class ActivationSalles
{
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

        $choisies = $cachees->keys()->map(fn ($i) => (int) $i)
            ->sortBy(fn (int $i) => $heros->map(fn ($h) => collect(Salles::cases($salles[$i]))
                ->map(fn ($c) => abs($c['x'] - (int) $h->position_x) + abs($c['y'] - (int) $h->position_y))->min())->min() ?? PHP_INT_MAX)
            ->take($nombre)->values();

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
