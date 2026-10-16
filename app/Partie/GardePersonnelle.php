<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Quete;

/**
 * LA GARDE PERSONNELLE d'un chef nommé — *Against the Ogre Horde*, quête 8 (F9528 p. 35) :
 *
 *   « The Dread Warriors in the room are Festral's personal guard. They each roll 5 Attack dice
 *   and 5 Defend dice. »
 *
 * Une stat de FIGURINE qu'un chef impose à une part de son escorte — pas un sort, donc pas un buff
 * de `habillage.dread_etat` posé par un lanceur (`buff_defense`/`buff_attaque` : des BONUS qui
 * tombent au tour du lanceur), mais un REMPLACEMENT de la fiche : les Guerriers du Dread de sa salle
 * lancent CINQ dés, quel que soit leur bloc de catalogue (Attaque 4, Défense 4).
 *
 * La capacité `garde_personnelle` du chef (`monstres.capacites`) nomme la créature et les dés :
 * `['monstre' => 'Guerrier du Chaos', 'attaque' => 5, 'defense' => 5]`. {@see self::etablir()} la
 * pose, UNE fois, au placement de la quête, sur les créatures de ce nom qui partagent LA SALLE du
 * chef — les errants qui arrivent ensuite n'en sont pas (« in the room »). L'état vit dans
 * `habillage.dread_etat.garde_personnelle` de chaque garde, donc dans le snapshot et jamais en
 * cache ; `InstanceMonstre::attaqueEffective()` / `defenseEffective()` le lisent — au SEUL endroit
 * qui compose les dés d'un monstre.
 *
 * Le bonus d'ÉLITE (+1, règle 3.6, la nôtre) s'ajoute au chiffre du livret comme il s'ajoute à celui
 * du catalogue : le livret dit « each roll 5 », pas « jamais plus de 5 ».
 */
final class GardePersonnelle
{
    /** La capacité de chef qui porte la garde. */
    public const CAPACITE = 'garde_personnelle';

    /** La clé de `habillage.dread_etat` posée sur chaque figure de la garde. */
    public const ETAT = 'garde_personnelle';

    /**
     * Pose les dés de la garde sur chaque figure concernée de la quête qui vient d'être placée.
     *
     * @param  array<string, mixed>  $carte  la grille de la quête (`salles`), telle que `DemarreurQuete` vient de la créer
     * @return int  combien de figures ont reçu leurs dés
     */
    public static function etablir(Quete $quete, array $carte): int
    {
        $salles = (array) ($carte['salles'] ?? []);
        $instances = $quete->instancesMonstres()->with('monstre')->get();
        $posees = 0;

        foreach ($instances as $chef) {
            $garde = $chef->capaciteParametree(self::CAPACITE);

            if (! is_array($garde) || $chef->position_x === null) {
                continue;
            }

            $salleDuChef = Salles::indexDe($salles, (int) $chef->position_x, (int) $chef->position_y);

            // Un chef en couloir n'a pas de « room » : personne n'est sa garde.
            if ($salleDuChef === null) {
                continue;
            }

            foreach ($instances as $figure) {
                if ($figure->id === $chef->id
                    || $figure->position_x === null
                    || $figure->monstre?->nom_base !== ($garde['monstre'] ?? null)
                    || Salles::indexDe($salles, (int) $figure->position_x, (int) $figure->position_y) !== $salleDuChef) {
                    continue;
                }

                $figure->poserEtatDread(self::ETAT, [
                    'par' => (int) $chef->id,
                    'attaque' => (int) ($garde['attaque'] ?? 0),
                    'defense' => (int) ($garde['defense'] ?? 0),
                ]);
                $posees++;
            }
        }

        return $posees;
    }
}
