<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\InstanceMonstre;

/**
 * LE MÉTÉORE DE QWINDRAK (livret de quêtes First Light p. 31, note C : « In all forms, Qwindrak knows the Dread
 * spells Tempest, Cloud of Dread, and Lightning Bolt. Each time a hero casts a spell or makes an attack against
 * him, Qwindrak hurls a meteor at them. Roll 2 Attack dice for each meteor. »).
 *
 * Le mot-clé `meteore` est porté par la FICHE D'ORIGINE : chaque forme de la pioche le garde
 * (`InstanceMonstre::ligneDeReference()`). Ce service porte la lecture du mot-clé, et ELLE SEULE : c'est le
 * `declencheur()` de `MotsClesAdversaires::METEORE`, appelé par `ResolveurTour::frapper()` (attaque d'un héros)
 * et `ResolveurTour::sousReactionMj()` (sort d'un héros qui vise la figure).
 *
 * Ce que le service NE FAIT PAS, et pourquoi : il ne résout rien. Les dés, la défense du héros, les dégâts et le
 * journal viennent de `ResolveurTour::resoudreAttaqueMonstre()` — la résolution d'attaque d'un monstre, réutilisée
 * telle quelle. Une seconde copie du combat aurait dérivé (une règle de défense corrigée d'un côté seulement).
 *
 * Règles de déclenchement (texte du livret, et décisions de René du 2026-10-22) :
 *  - seuls les HÉROS déclenchent : jamais un allié, un mercenaire ni un compagnon ;
 *  - une ATTAQUE d'un héros dont la cible est la figure, réussie ou non ;
 *  - un SORT d'un héros dont les cibles visées comprennent la figure ; un sort qui ne la vise pas ne déclenche rien ;
 *  - le météore part APRÈS la résolution de l'action du héros, si la figure est encore là, sous une forme
 *    quelconque (la nouvelle forme arrivée par ce coup compte) ; la mort du Dragon achève Qwindrak : pas de météore ;
 *  - aucune exception : ni action consommée, ni budget, ni garde « peut agir ».
 */
final class MoteurMeteore
{
    /**
     * Le paramètre du météore que porte la figure (`['des_attaque' => 2]`), ou `null` si sa fiche de référence
     * ne le porte pas. Lu sur la fiche d'ORIGINE quand il y en a une : toutes les formes de Qwindrak le gardent.
     *
     * @return array{des_attaque: int}|null
     */
    public function declencheur(InstanceMonstre $cible): ?array
    {
        $param = $cible->capaciteParametreeDeReference('meteore');

        if (! is_array($param) || (int) ($param['des_attaque'] ?? 0) <= 0) {
            return null;
        }

        return ['des_attaque' => (int) $param['des_attaque']];
    }
}
