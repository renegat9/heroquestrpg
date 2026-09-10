<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalogue de référence du TERRAIN (doc 18 §4, The Frozen Horror) — données
 * seedées, jamais modifiées en jeu, même statut que `Mobilier`/`Piege`/`Epreuve`.
 *
 * Cinquième couche de la carte : contrairement aux quatre précédentes (pièges,
 * leviers, mobilier, épreuves), qui répondent toutes à « qu'y a-t-il ICI ? »,
 * le terrain répond à « que COÛTE cette case, et que se passe-t-il quand on la
 * traverse ou qu'on y reste ? ».
 *
 * `cout_deplacement` (défaut 1) est ce qui rend la Rivière Gelée possible
 * (2 cases de déplacement par case franchie, doc 18 §4). ⚠ Il n'affecte QUE le
 * parcours pondéré (`Grille::casesAtteignables()`/`chemin()`) — jamais
 * `Grille::distance()`, qui reste géométrique (portée, adjacence : une flèche
 * n'est pas ralentie par la glace).
 *
 * `bloque_mouvement` et `bloque_vue` sont DEUX propriétés INDÉPENDANTES, comme
 * sur `Mobilier` — ne JAMAIS les refusionner ni dériver l'une de l'autre
 * (c'est l'arbitrage du 2026-08-05, relire `docs/regles/carte-donjon.md`).
 * Aucun des 7 terrains sourcés à ce jour ne bloque l'un ou l'autre (ce sont
 * des dangers de sol, pas des murs) ; la colonne tient pour le prochain qui le
 * fera (le Mur de Glace, sort du boss, bloque le mouvement SANS bloquer la
 * vue — texte de carte, doc 18 §4).
 *
 * `effet` porte le vocabulaire des règles (jets, résultats, dégâts,
 * téléportation…) — lu par un autre agent, pas par cette phase-ci.
 *
 * `boite` (2026-09-06, phase 6a) filtre le PLACEMENT par thème de campagne
 * (`AssembleurCarte::placerTerrains()`), exactement comme `Monstre::$boite`
 * filtre déjà les monstres achetés (`DemarreurQuete::acheterMonstres()`) :
 * `null` veut dire « aucune boîte, convient à tout thème », jamais un trou.
 * Les 7 terrains sourcés valent tous `horreur_des_glaces` — une boîte
 * volontairement absente de `DemarreurQuete::BOITES_THEMATIQUES` tant que ses
 * règles restent incomplètes, ce qui laisse cette couche posée mais INERTE en
 * jeu réel jusqu'à sa réactivation (assumé, pas un oubli).
 */
class Terrain extends Model
{
    protected $table = 'terrains';

    protected $fillable = [
        'nom',
        'nom_anglais',
        'cout_deplacement',
        'bloque_mouvement',
        'bloque_vue',
        'effet',
        // Boîte d'extension d'origine (2026-09-06, phase 6a) — même colonne,
        // même lecture que `Monstre::$boite` : `null` = « aucune boîte,
        // convient à tout thème », jamais un trou. Les 7 lignes sourcées
        // valent toutes `horreur_des_glaces`.
        'boite',
    ];

    protected function casts(): array
    {
        return [
            'cout_deplacement' => 'integer',
            'bloque_mouvement' => 'boolean',
            'bloque_vue' => 'boolean',
            'effet' => 'array',
        ];
    }
}
