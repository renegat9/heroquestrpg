<?php

declare(strict_types=1);

namespace App\Partie\Narration;

/**
 * Vocabulaire FERMÉ des temps forts narrés — et, pour chacun, les variables
 * que le moteur sait vraiment fournir.
 *
 * Trois producteurs doivent s'accorder sur cette liste, et c'est parce qu'ils
 * ne s'accordaient pas qu'elle existe (constaté en partie réelle le
 * 2026-08-18) : le skill de pré-génération produisait dix clés de fouille que
 * la table de correspondance de `ChoixController` ne routait vers rien — des
 * répliques payées, stockées, jamais entendues, pendant qu'une trouvaille de
 * 25 pièces d'or se racontait « une salle de plus ».
 *
 * ⚠ Une variable que le moteur ne fournit pas reste TELLE QUELLE dans le texte
 * (`BibliothequeNarration::substituer` ne vide jamais ce qu'il ne sait pas
 * remplacer : une phrase amputée passe inaperçue à la relecture, « {monstre} »
 * saute aux yeux). D'où une liste blanche PAR CLÉ, et non globale.
 */
final class TempsFort
{
    /** Seules variables qui existeront jamais — même convention que `{nom}` (config/barks.php). */
    public const VARIABLES = ['heros', 'monstre', 'objet', 'or'];

    /**
     * Temps forts que l'IA écrit POUR CETTE QUÊTE (décision de René,
     * 2026-08-18), parce qu'ils ne dépendent d'aucun tirage et parlent de CE
     * donjon-là :
     *  - `fouille_artefact` : l'arme unique du donjon, sommet de la quête ;
     *  - `quete_demarree` / `victoire_quete` : les deux jalons du récit.
     *
     * ⚠ Tout le RESTE est générique, et c'est un raisonnement, pas une
     * économie : le résultat d'une fouille est un TIRAGE. L'IA ne peut pas
     * savoir à l'avance si le héros trouvera de l'or, une potion ou un
     * monstre errant, donc écrire sur mesure les cinq issues possibles ne
     * produit rien qu'une table fixe ne dise aussi bien — pour ~5 500 tokens
     * de sortie et deux à trois minutes de génération par quête.
     */
    public const GENERES_PAR_QUETE = ['fouille_artefact', 'quete_demarree', 'victoire_quete'];

    /**
     * Les clés et leurs variables légales.
     *
     * Quelques choix qui méritent leur ligne : `attaque_*` n'en porte AUCUNE
     * — l'attaquant peut être un héros comme un monstre, et nommer le mauvais
     * est pire que ne nommer personne ; `porte_ouverte` reste impersonnel,
     * une porte pouvant s'ouvrir par un levier ou par la mort d'un gardien.
     */
    public const VARIABLES_PAR_CLE = [
        'quete_demarree' => [],
        'salle_decouverte' => [],
        'piege_declenche' => ['heros'],
        'fouille_artefact' => ['heros', 'objet'],
        'reprise' => [],
        'deplacement' => [],
        'victoire_quete' => [],
        'attaque_mort' => [],
        // Mort d'un boss ou d'un sous-boss. Clé DISTINCTE d'`attaque_mort`, et
        // pour une raison précise : celle-ci ne porte aucune variable, parce
        // qu'on ne sait pas si le frappeur est un héros ou un monstre. Ici on
        // le sait — c'est un héros qui abat une créature NOMMÉE, et la nommer
        // est tout l'intérêt.
        'boss_vaincu' => ['monstre'],
        // Chute et relèvement d'un héros. Rien ne les disait : en campagne
        // réelle une héroïne est restée à terre 22 minutes sans un mot.
        'heros_tombe' => ['heros'],
        'heros_releve' => ['heros'],
        'attaque_touche' => [],
        'attaque_pare' => [],
        'reussite' => [],
        'reussite_mixte' => [],
        'echec' => [],
        'progression' => [],
        'fouille_tresor' => ['heros', 'or'],
        'fouille_potion' => ['heros', 'objet'],
        'fouille_errant' => ['heros', 'monstre'],
        'fouille_piege' => ['heros'],
        'fouille_rien' => ['heros'],
        'mobilier_objet' => ['heros', 'objet'],
        'mobilier_piege' => ['heros'],
        'mobilier_rien' => ['heros'],
        'porte_ouverte' => [],
        'levier_actionne' => ['heros'],
    ];

    /** @return list<string> */
    public static function cles(): array
    {
        return array_keys(self::VARIABLES_PAR_CLE);
    }

    /** Variables légales d'une clé ; liste vide pour une clé inconnue. @return list<string> */
    public static function variablesDe(string $cle): array
    {
        return self::VARIABLES_PAR_CLE[$cle] ?? [];
    }

    /** Variables employées dans un texte, dans l'ordre d'apparition. @return list<string> */
    public static function variablesEmployees(string $texte): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $texte, $trouvees);

        return $trouvees[1];
    }
}
