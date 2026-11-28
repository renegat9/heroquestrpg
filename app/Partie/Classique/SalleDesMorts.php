<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\LanceurDes;
use App\Models\InstanceMonstre;
use App\Partie\Salles;

/**
 * LA SALLE DES MORTS — lot E2, *First Light* q. 10 A (FL-Q p. 31) : « This is the Hall of the Dead. When an undead monster would
 * be defeated, roll one movement die. Add 1 to the roll result for every other undead monster in the room. On a roll result of 6
 * or more, the defeated monster is unharmed. »
 *
 * `regles.salle_des_morts: {salle: id, morts_vivants: [slug]}` — l'auteur nomme les monstres morts-vivants (le livret de base :
 * « any undead creature (skeleton, zombie, or mummy) »), jamais deviné ici. Lu par `MoteurDegats` au moment où le coup
 * achèverait la figure, après l'increvable : épargnée, elle garde ses points (« unharmed »).
 */
final class SalleDesMorts
{
    public function __construct(private readonly LanceurDes $des) {}

    /** @return array{de: int, bonus: int, epargne: bool}|null `null` : la règle ne s'applique pas à cette figure */
    public function jet(InstanceMonstre $instance): ?array
    {
        $quete = $instance->quete;
        if ($quete === null || ! $quete->estConstruite() || $instance->position_x === null) {
            return null;
        }
        $regle = data_get($quete->documentConstruit(), 'regles.salle_des_morts');
        if (! is_array($regle)) {
            return null;
        }
        $morts = array_map('strval', (array) ($regle['morts_vivants'] ?? []));
        $estMort = fn (InstanceMonstre $m) => in_array(CatalogueConstructeur::slug((string) $m->monstre?->nom_base), $morts, true);
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $index = collect($salles)->search(fn ($s) => ($s['id'] ?? null) === ($regle['salle'] ?? null));
        if ($index === false || ! $estMort($instance) || Salles::indexDe($salles, (int) $instance->position_x, (int) $instance->position_y) !== $index) {
            return null;
        }
        $autres = $quete->instancesMonstres()->where('etat', 'actif')->whereKeyNot($instance->id)->whereNotNull('position_x')->with('monstre')->get()
            ->filter(fn (InstanceMonstre $m) => $estMort($m) && Salles::indexDe($salles, (int) $m->position_x, (int) $m->position_y) === $index)->count();
        $de = $this->des->d6();

        return ['de' => $de, 'bonus' => $autres, 'epargne' => $de + $autres >= 6];
    }
}
