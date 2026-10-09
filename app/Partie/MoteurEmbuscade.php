<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Carte;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;
use Illuminate\Support\Collection;

/**
 * CAPACITÉ *AMBUSH* — le Dreadshifter (Wizards of Morcar, `monstres.capacites
 * = ['embuscade']`).
 *
 * Carte : « Dreadshifters in this expansion pack appear to be either a chest or
 * a door. Place this monster onto the board as the object it appears to be. The
 * first time a hero moves into the 8 squares surrounding this object, replace
 * it with the corresponding Ambush monster miniature. It may move and attack
 * immediately. »
 *
 * ⚠ CE QUI EST PORTÉ, ET CE QUI NE L'EST PAS (écrit, pas oublié) :
 *  - le déguisement en COFFRE : un meuble `Coffre` ordinaire est posé sur la
 *    case du monstre, marqué `embuscade_instance_id` ; l'instance reste
 *    `revele = false` (donc hors de tout ciblage, de tout menu, de toute
 *    phase de monstres) et porte `dread_etat.deguise = 'coffre'` ;
 *  - le déclencheur DE LA CARTE : un héros entre dans l'une des 8 cases
 *    autour de l'objet. Le livret en ajoute trois autres (fouille de pièges,
 *    fouille de trésor avec attaque immédiate, tour du MJ) que la CARTE ne
 *    documente pas : non portés ;
 *  - le déguisement en PORTE n'est PAS porté : une porte est une arête de la
 *    grille, pas un meuble sur une case, et en inventer une serait écrire une
 *    règle de carte que rien ne lit. Un Dreadshifter est donc toujours un
 *    coffre.
 *
 * Tout état est DURABLE : l'instance (`habillage.dread_etat`) et la carte
 * (`grille.mobilier[]`) — jamais le cache — donc un snapshot le restaure.
 */
final class MoteurEmbuscade
{
    public const DEGUISEMENT_COFFRE = 'coffre';

    /** Nom du meuble que le monstre imite (catalogue `mobiliers`). */
    public const MEUBLE_COFFRE = 'Coffre';

    /** La créature est-elle encore déguisée ? */
    public static function estDeguise(InstanceMonstre $instance): bool
    {
        return $instance->etatDread('deguise') !== null;
    }

    /** Une entrée de mobilier est-elle un faux coffre de Dreadshifter ? */
    public static function estFauxMeuble(array $entree): bool
    {
        return ! empty($entree['embuscade_instance_id']);
    }

    /**
     * Pose le déguisement : un coffre sur la case du monstre, l'instance cachée.
     *
     * Sans meuble `Coffre` au catalogue (base de test partielle), rien n'est
     * déguisé et la créature se comporte comme n'importe quel monstre dormant —
     * un repli, pas une règle.
     *
     * @return bool vrai si le monstre est désormais déguisé
     */
    public function deguiser(Quete $quete, InstanceMonstre $instance): bool
    {
        $carte = $quete->carte;
        $coffre = Mobilier::where('nom', self::MEUBLE_COFFRE)->first();

        if ($carte === null || $coffre === null || $instance->position_x === null
            || ! $instance->aCapacite('embuscade')) {
            return false;
        }

        $grille = $carte->grille;
        $mobilier = (array) ($grille['mobilier'] ?? []);
        $x = (int) $instance->position_x;
        $y = (int) $instance->position_y;

        $mobilier[] = [
            'mobilier_id' => $coffre->id,
            'x' => $x,
            'y' => $y,
            'l' => 1,
            'h' => 1,
            'salle' => Salles::indexDe((array) ($grille['salles'] ?? []), $x, $y),
            'embuscade_instance_id' => (int) $instance->id,
        ];
        $grille['mobilier'] = $mobilier;
        $carte->update(['grille' => $grille]);

        $instance->update(['revele' => false]);
        $instance->poserEtatDread('deguise', self::DEGUISEMENT_COFFRE);

        return true;
    }

    /**
     * Les Dreadshifters déguisés dont l'un des 8 voisins vient d'être foulé.
     *
     * @param  list<array{x: int, y: int}>  $casesFoulees
     * @return Collection<int, InstanceMonstre>
     */
    public function declenchees(Quete $quete, array $casesFoulees): Collection
    {
        if ($casesFoulees === []) {
            return collect();
        }

        return $quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get()
            ->filter(function (InstanceMonstre $i) use ($casesFoulees) {
                if (! self::estDeguise($i) || $i->position_x === null) {
                    return false;
                }

                foreach ($casesFoulees as $c) {
                    if (max(abs((int) $c['x'] - (int) $i->position_x), abs((int) $c['y'] - (int) $i->position_y)) === 1) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * « Replace it with the Ambush monster miniature » : le coffre disparaît
     * (pièce marquée détruite, comme toute pièce qui cesse de bloquer), la
     * créature est révélée. Annoncé au journal ET rendu dans le payload.
     *
     * @return array<string, mixed>
     */
    public function reveler(Groupe $groupe, Quete $quete, InstanceMonstre $instance, Personnage $declencheur): array
    {
        $carte = $quete->carte;

        if ($carte instanceof Carte) {
            $grille = $carte->grille;

            foreach ((array) ($grille['mobilier'] ?? []) as $index => $entree) {
                if ((int) ($entree['embuscade_instance_id'] ?? 0) === (int) $instance->id) {
                    $grille['mobilier'][$index]['detruit'] = true;
                }
            }

            $carte->update(['grille' => $grille]);
        }

        $instance->poserEtatDread('deguise', null);
        $instance->update(['revele' => true]);

        $payload = [
            'type' => 'embuscade',
            'monstre' => $instance->nomAffiche(),
            'instance_id' => (int) $instance->id,
            'declencheur' => ['personnage_id' => $declencheur->id, 'nom' => $declencheur->nom],
            'etait' => self::DEGUISEMENT_COFFRE,
            'position' => ['x' => (int) $instance->position_x, 'y' => (int) $instance->position_y],
        ];

        Journal::ajouter($groupe, 'action', $payload, ['type' => 'monstre', 'id' => $instance->id, 'nom' => $instance->nomAffiche()]);

        return $payload;
    }
}
