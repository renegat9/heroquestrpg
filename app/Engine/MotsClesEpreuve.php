<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * VOCABULAIRE FERMÉ DES ÉPREUVES — les ancrages posés sur la carte du donjon
 * auxquels un héros à leur contact tente un jet d'attribut (Body ou Mind).
 *
 * Même garde-fou que `MotsClesTalent`, `MotsClesEquipement`, `DureeEffet` et
 * les autres registres du projet, et pour la même raison : une épreuve
 * annoncée au joueur que rien n'applique est une promesse non tenue.
 *
 * Deux vocabulaires cohabitent ici, et ils ne se confondent JAMAIS :
 *
 *  - `MECANIQUES` répond à « qu'est-ce que la RÉUSSITE produit ? » — c'est
 *    `epreuves.effet.mecanique`.
 *  - `PLACEMENTS` répond à « où l'épreuve a-t-elle le DROIT d'exister ? » —
 *    c'est `epreuves.exige_placement`, une PRÉCONDITION DE POSE lue au moment
 *    d'assembler la carte, jamais au moment de résoudre le jet. Une épreuve
 *    « Autel fêlé » qui désarme les pièges de la salle n'a rien à désarmer
 *    dans une salle qui n'en a aucun — `exige_placement` est ce qui empêche
 *    ce non-sens d'être posé en premier lieu.
 *
 * Une entrée de `MECANIQUES` porte DEUX choses, et les deux sont
 * obligatoires :
 *
 *  - `lecteur` — la couture moteur qui applique l'effet. Pas encore écrite
 *    pour ce lot (`App\Partie\ResolveurTour::resoudreEpreuve()` et
 *    `App\Partie\MoteurPieges::desarmerSalle()` n'existent pas au moment où
 *    ce fichier est créé) : elles sont déclarées quand même, à dessein — le
 *    test de registre qui vérifiera classe/méthode/mention littérale (sur le
 *    modèle de `GrilleTalentsTest`) viendra avec elles, pas avant. Ce fichier-
 *    ci ne teste QUE l'appartenance au vocabulaire, dans les deux sens.
 *  - `libelle` — le gabarit de phrase que le JOUEUR lit, avec `{valeur}` en
 *    jeton de substitution pour les mécaniques chiffrées. Même raison que
 *    `MotsClesTalent::avantage()` : un texte dérivé de l'effet ne peut pas
 *    partir en désaccord avec ce que le moteur applique réellement.
 *
 * @see \App\Models\Epreuve  le catalogue seedé (doc 01 §5)
 */
final class MotsClesEpreuve
{
    /**
     * @var array<string, array{lecteur: string|list<string>, libelle: string}>
     */
    public const MECANIQUES = [
        // Une réussite paie en or direct, versé à la bourse commune — le même
        // geste qu'une carte `tresor` du deck de fouille.
        'or' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreEpreuve()',
            'libelle' => '{valeur} pièces d\'or',
        ],

        // Une réussite rend un objet tiré au catalogue (même tirage en deux
        // temps — rareté puis pièce — que `App\Engine\RareteButin`, pour ne
        // pas dupliquer une seconde courbe de progression).
        'objet' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreEpreuve()',
            'libelle' => 'un objet',
        ],

        // Le seul mécanisme du jeu qui rend un PARCHEMIN hors marché et hors
        // bibliothèque de mobilier — cohérent avec « Grimoire à demi calciné »,
        // la seule épreuve à le porter.
        'parchemin' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreEpreuve()',
            'libelle' => 'un parchemin',
        ],

        // Redonne des PV de Body à TOUT LE GROUPE (pas seulement au héros qui a
        // réussi le jet) — c'est ce qui distingue une épreuve d'un soin de
        // potion, personnel par construction.
        'soin_groupe' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreEpreuve()',
            'libelle' => '{valeur} PV de Body rendus à tout le groupe',
        ],

        // Purge une condition active portée par le héros qui a réussi le jet
        // — le pendant mécanique d'une inscription qui « libère » plutôt
        // qu'elle ne blesse.
        'retire_condition' => [
            'lecteur' => 'App\Partie\ResolveurTour::resoudreEpreuve()',
            'libelle' => 'libère le héros d\'une condition active',
        ],

        // ⚠ Lecteur DIFFÉRENT des cinq autres : ce n'est pas un gain remis au
        // héros, c'est une action sur la CARTE (la salle où l'épreuve est
        // posée). D'où la précondition `piege_dans_la_salle` côté
        // `PLACEMENTS` — sans elle, l'effet resterait déclaré et sans cible
        // dans la moitié des donjons.
        'desarme_pieges_salle' => [
            'lecteur' => 'App\Partie\MoteurPieges::desarmerSalle()',
            'libelle' => 'désarme tous les pièges encore actifs de la salle',
        ],
    ];

    /**
     * Vocabulaire de `epreuves.exige_placement` — PRÉCONDITION DE POSE, lue
     * par l'assembleur de carte au moment de choisir où poser l'épreuve,
     * jamais par le résolveur de jet.
     *
     * @var array<string, array{lecteur: string, libelle: string}>
     */
    public const PLACEMENTS = [
        // « Autel fêlé » (mécanique `desarme_pieges_salle`) : ne se pose que
        // dans une salle qui contient déjà au moins un piège, sans quoi la
        // récompense de la réussite n'aurait rien à faire.
        'piege_dans_la_salle' => [
            'lecteur' => 'App\Partie\AssembleurCarte::placerEpreuves()',
            'libelle' => 'seulement dans une salle qui contient un piège',
        ],
    ];

    /** Une mécanique d'effet déclarée l'est-elle ici ? */
    public static function connue(?string $mecanique): bool
    {
        return $mecanique !== null && isset(self::MECANIQUES[$mecanique]);
    }

    /**
     * Le texte JOUEUR de l'effet, `{valeur}` substitué quand `effet.valeur`
     * est posée. Rend `''` pour une mécanique inconnue : le contrôle de
     * vocabulaire est le travail du test, pas celui de l'affichage (même
     * parti pris que `MotsClesTalent::avantage()`).
     */
    public static function libelle(array $effet): string
    {
        $mecanique = $effet['mecanique'] ?? null;
        $entree = self::MECANIQUES[$mecanique] ?? null;

        if ($entree === null) {
            return '';
        }

        if (! isset($effet['valeur'])) {
            return $entree['libelle'];
        }

        return str_replace('{valeur}', (string) $effet['valeur'], $entree['libelle']);
    }
}
