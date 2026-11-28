<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\LanceurDes;
use App\Engine\MotsClesEquipement;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MoteurSorts;
use App\Partie\Salles;
use App\Partie\TamponAnnonces;

/**
 * LE PONT DE CORDE — lot E2, *Jungles of Delthrak* q. 15 C (F9907 p. 41) : « A rope bridge hangs over a vast chasm. If a hero
 * moves onto a square of the rope bridge during their turn, they must roll one red die. They make this roll once per turn.
 * If they begin their turn on a rope bridge square and do not move onto another rope bridge square, they do not have to
 * roll. 1-2: The hero loses their footing and slips! Their turn immediately ends as they cling for dear life. They cannot
 * take any actions or defend against attacks until the start of their next turn. 3-6: The hero steps nimbly and may
 * continue their movement (if they have movement remaining). Monsters and heroes under the effects of a Spiderstep Potion
 * are not affected by the rope bridge. »
 *
 * `regles.pont_de_corde: [{x, y, l, h}]`. Lu par la marche du héros (`ResolveurTour::resoudreDeplacement()`, avec la glace
 * qui tronque le trajet) : la PREMIÈRE case de pont du trajet lance le dé (marqueur `capacites_tour`, une fois par tour).
 * Glissé, il reste sur cette case, son tour s'arrête (`FinDeTourForcee`) et il ne se défend plus (`MoteurSorts::defenseNulle()`) jusqu'au
 * début de son prochain tour — le marqueur `agrippe` meurt avec `capacites_tour`, remis à zéro à l'ouverture du round.
 * L'Élixir de pas d'araignée (buff `ignore_terrain_entravant`) l'en dispense ; les monstres ne sont jamais concernés.
 */
final class PontDeCorde
{
    public const MARQUEUR_JET = 'pont_de_corde';

    public const MARQUEUR_AGRIPPE = 'pont_de_corde_agrippe';

    /** « 1-2: The hero loses their footing and slips! » */
    public const GLISSE_JUSQU_A = 2;

    public function __construct(private readonly LanceurDes $des) {}

    public static function sur(Quete $quete, int $x, int $y): bool
    {
        if (! $quete->estConstruite()) {
            return false;
        }
        foreach ((array) data_get($quete->documentConstruit(), 'regles.pont_de_corde', []) as $rect) {
            if (Salles::contientCase((array) $rect, $x, $y)) {
                return true;
            }
        }

        return false;
    }

    /** Le héros, glissé, s'agrippe-t-il au pont (aucune défense jusqu'à son prochain tour) ? */
    public static function agrippe(Personnage $heros): bool
    {
        $etat = EtatPersonnageQuete::enQuete($heros);

        return $etat !== null && in_array(self::MARQUEUR_AGRIPPE, (array) ($etat->capacites_tour ?? []), true);
    }

    /**
     * Le jet du trajet, SANS rien écrire : la première case de pont du chemin, si le dé est dû ce tour (une fois par tour,
     * jamais sous l'Élixir de pas d'araignée). Rend `{index, case, de, glisse}` — `index` : le rang de la case dans le
     * chemin —, ou `null`. Le résolveur l'APPLIQUE ensuite ({@see appliquer()}), s'il a bien atteint la case.
     *
     * @param  list<array{x: int, y: int}>  $chemin
     * @return array{index: int, case: array{x: int, y: int}, de: int, glisse: bool}|null
     */
    public function jet(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, array $chemin): ?array
    {
        if (! $quete->estConstruite() || in_array(self::MARQUEUR_JET, (array) ($etat->fresh()?->capacites_tour ?? []), true)) {
            return null;
        }
        foreach (array_values($chemin) as $i => $case) {
            if (! self::sur($quete, (int) $case['x'], (int) $case['y'])) {
                continue;
            }
            if (app(MoteurSorts::class)->aBuff($heros, MotsClesEquipement::IGNORE_TERRAIN_ENTRAVANT)) {
                return null;
            }
            $de = $this->des->d6();

            return ['index' => $i, 'case' => ['x' => (int) $case['x'], 'y' => (int) $case['y']], 'de' => $de, 'glisse' => $de <= self::GLISSE_JUSQU_A];
        }

        return null;
    }

    /**
     * Le jet compte : marqué pour le tour ; glissé, le héros s'agrippe (aucune défense jusqu'à son prochain tour) et son tour
     * s'arrête (`FinDeTourForcee`). Annoncé à la table.
     *
     * @param  array{index: int, case: array{x: int, y: int}, de: int, glisse: bool}  $jet
     * @return array<string, mixed> l'annonce `pont_de_corde`
     */
    public function appliquer(Groupe $groupe, Personnage $heros, EtatPersonnageQuete $etat, array $jet): array
    {
        $etat->refresh();
        $etat->update(['capacites_tour' => array_values(array_unique([...(array) ($etat->capacites_tour ?? []), self::MARQUEUR_JET,
            ...($jet['glisse'] ? [self::MARQUEUR_AGRIPPE] : [])]))]);
        if ($jet['glisse']) {
            FinDeTourForcee::demander($etat);
        }

        $payload = ['type' => 'pont_de_corde', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom,
            'de' => (int) $jet['de'], 'glisse' => (bool) $jet['glisse'], 'x' => $jet['case']['x'], 'y' => $jet['case']['y']];
        app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return $payload;
    }
}
