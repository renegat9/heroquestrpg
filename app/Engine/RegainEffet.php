<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Vocabulaire du REGAIN — à quel ÉVÉNEMENT un sort rompu redevient lançable.
 *
 * Troisième axe de la vie d'un effet, distinct des deux autres et longtemps
 * confondu avec eux :
 *
 *  1. `effet.duree`   → QUAND le buff s'arrête          (App\Engine\DureeEffet)
 *  2. `personnage_sorts.disponible` → le sort est-il relançable ce combat
 *  3. `effet.regain`  → À QUEL ÉVÉNEMENT il le redevient  ← ce fichier
 *
 * Jusqu'ici (2) ne se rechargeait que par le passage à la quête suivante, ou
 * par deux nœuds d'arbre codés en dur (Concentration rend un sort, Réserve
 * arcanique en accorde un second). Aucune DONNÉE ne pouvait dire « ce sort
 * revient quand… » — les cartes officielles, elles, le disent constamment :
 *
 *  - *Shapeshift* (Druide) : « Regain this spell when your Body Points return
 *    to their starting number »
 *  - *Demonform* (Warlock) : « Regain this spell when you reduce a monster's
 *    Body Points to zero »
 *  - *Inspiring Tale* (Barde) : « Regain this spell when any hero you can see,
 *    excluding yourself, rolls Defend dice that result in 2 white shields »
 *
 * Ces trois sorts partagent la même forme : un buff **rompu par le premier
 * dégât subi** (`DureeEffet::PREMIER_DEGAT_SUBI`, qui existait déjà pour Peau
 * de Pierre) et **regagné sur un événement**. La rupture avait donc son
 * mot-clé ; le retour n'en avait aucun, et c'est le seul trou réel.
 *
 * ⚠ Un regain n'est PAS une durée. Le confondre reviendrait à dire qu'un sort
 * « dure jusqu'à ce que vous tuiez un monstre », ce qui est faux : le buff est
 * fini depuis longtemps, c'est le DROIT DE LE RELANCER qui revient.
 *
 * Référence : `reference/19_mots_cles_effets.md` §Regain.
 */
final class RegainEffet
{
    /**
     * Quand les PV de Body du lanceur reviennent à leur maximum (Shapeshift).
     *
     * Émis par `App\Partie\MoteurDegats` — le même point de passage qui
     * applique les dégâts observe aussi les soins qui referment la jauge.
     */
    public const BODY_AU_MAX = 'body_au_max';

    /** Quand le lanceur réduit un monstre à 0 PV de Body (Demonform). */
    public const MONSTRE_VAINCU = 'monstre_vaincu';

    /**
     * Quand un AUTRE héros en vue obtient 2 boucliers blancs en défense
     * (Inspiring Tale).
     *
     * ⚠ « excluding yourself » : le lanceur ne se recharge pas sur sa propre
     * parade, ce qui en ferait un sort quasi permanent pour un héros à 4 dés
     * de défense.
     */
    public const ALLIE_DEUX_BOUCLIERS_BLANCS = 'allie_deux_boucliers_blancs';

    /** @return list<string> */
    public static function tous(): array
    {
        return [
            self::BODY_AU_MAX,
            self::MONSTRE_VAINCU,
            self::ALLIE_DEUX_BOUCLIERS_BLANCS,
        ];
    }

    /**
     * Événements DÉCLARÉS mais qu'aucune ligne de catalogue ne porte encore,
     * avec la carte qui les attend.
     *
     * Même dispositif que `TypeDegat::SANS_SOURCE` : le projet interdit la clé
     * décorative — un mot déclaré que rien n'applique —, mais il accepte la
     * **dette nommée**, à condition qu'elle dise ce qui lui manque.
     *
     * ✅ Le tableau est VIDE depuis le 2026-08-12 : les trois sorts qui
     * l'attendaient sont semés avec leurs classes.
     *
     * @var array<string, string>
     */
    public const SANS_UTILISATEUR = [
        // ✅ VIDE depuis le 2026-08-12 : les trois regains ont trouvé leur
        // porteur en même temps que leurs classes — `body_au_max` la
        // Métamorphose du Druide, `monstre_vaincu` la Forme démoniaque du
        // Warlock, `allie_deux_boucliers_blancs` le Conte inspirant du Barde.
        // Le tableau reste, et son test avec lui : le jour où un quatrième
        // événement sera déclaré sans sort, il devra s'inscrire ici.
    ];

    public static function estConnu(mixed $regain): bool
    {
        return is_string($regain) && in_array($regain, self::tous(), true);
    }
}
