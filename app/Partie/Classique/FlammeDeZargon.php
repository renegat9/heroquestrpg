<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\LanceurDes;
use App\Models\InstanceMonstre;
use App\Partie\TamponAnnonces;

/**
 * LA FLAMME DE ZARGON (mode classique, lot E2 — *Prophecy of Telor* q. 13, « Zargon's Flame ») : « All monsters shown on the
 * map have been summoned with Zargon's Flame. They attack and defend as normal, but have no Body Points. When a monster
 * summoned by Zargon's Flame is hit, regardless of how many Body Points of damage are dealt, Zargon calls out any two numbers
 * 1-6 and rolls 1 red die. If the roll result matches one of the numbers called, the monster is unharmed. If not, it is
 * consumed by flame and defeated. If a hero casts Water of Healing on any monster affected by Zargon's Flame, that monster
 * is hit and cannot roll Defend dice. Zargon may only call out one die number to stop that monster from being consumed. »
 *
 * Capacité de profil `flamme_de_zargon` (`InstanceMonstre::CAPACITES_DE_PROFIL`). Lue au seul point de passage des dégâts
 * d'un monstre (`MoteurDegats::infligerAMonstre()`) : tout coup qui porte ({@see touche()}) se joue au dé, quels que soient
 * les PV ; l'Eau de Guérison ({@see SORT}) devient une cible possible contre une figure flambante (`MoteurSorts`), qui la
 * touche sans défense, un seul chiffre pour la sauver (`ResolveurTour::sortUtilitaire()`). Zargon, joué par le moteur,
 * annonce des chiffres tirés au dé (ce qu'il annonce ne change pas la chance : deux faces sur six, ou une).
 */
final class FlammeDeZargon
{
    public const CAPACITE = 'flamme_de_zargon';

    /** Le sort du livret qui frappe une figure flambante : « Water of Healing ». */
    public const SORT = 'Eau de Guérison';

    public function __construct(private readonly LanceurDes $des) {}

    public static function flambe(InstanceMonstre $instance): bool
    {
        return $instance->aCapacite(self::CAPACITE);
    }

    /**
     * Le coup qui porte : Zargon annonce deux chiffres (un seul sous l'Eau de Guérison), puis lance le dé rouge. Annoncé
     * (`flamme_de_zargon`). Rend l'issue.
     *
     * @return array{epargne: bool, nombres: list<int>, de: int}
     */
    public function touche(InstanceMonstre $instance, bool $parEau = false): array
    {
        $nombres = [$this->des->d6()];
        if (! $parEau) {
            $second = $this->des->d6();
            $nombres[] = $second === $nombres[0] ? ($second % 6) + 1 : $second;
        }
        $de = $this->des->d6();
        $issue = ['epargne' => in_array($de, $nombres, true), 'nombres' => $nombres, 'de' => $de];

        if (($groupe = $instance->quete?->groupe) !== null) {
            app(TamponAnnonces::class)->annoncer($groupe, ['type' => 'flamme_de_zargon', 'nom' => $instance->nomAffiche(), 'eau' => $parEau, ...$issue]);
        }

        return $issue;
    }
}
