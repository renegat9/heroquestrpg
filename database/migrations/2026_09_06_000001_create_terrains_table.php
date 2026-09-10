<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue de TERRAIN (doc 18 §4, The Frozen Horror) — cinquième couche de la
 * carte, sur le patron EXACT de `mobiliers` (migration
 * `2026_08_04_000002_create_mobiliers_table` + les trois qui l'ont complétée).
 *
 * La grille ne connaissait jusqu'ici que `m`/`s` ; les couches posées dessus
 * (pièges, leviers, mobilier, épreuves) répondent toutes à « qu'y a-t-il ICI ? ».
 * Aucune ne répond à « que COÛTE cette case, et que se passe-t-il quand on la
 * traverse ou qu'on y reste ? » — la question que pose toute la boîte de glace
 * (Rivière Gelée, Glace glissante…). D'où `cout_deplacement` : c'est lui qui
 * rend la Rivière Gelée possible (2 cases de déplacement par case, doc 18 §4).
 *
 * `bloque_mouvement`/`bloque_vue` sont DEUX drapeaux INDÉPENDANTS, exactement
 * comme sur `mobiliers` (migration `separer_bloque_mouvement_bloque_vue_mobilier`,
 * 2026-08-05) — jamais dérivés l'un de l'autre. Aucun des 7 terrains sourcés à
 * ce jour ne bloque ni l'un ni l'autre (ce sont des dangers de sol, pas des
 * murs), mais la colonne existe pour le prochain qui le fera : un Mur de Glace
 * (Ice Wall, sort du boss) bloque le mouvement SANS bloquer la vue — texte de
 * carte, doc 18 §4 — et c'est très exactement la séparation que cette
 * migration pose, pas une par anticipation vague.
 *
 * `effet` (json) porte le VOCABULAIRE des règles (jets, résultats, dégâts,
 * téléportation…) que le prochain agent câblera — cette migration et son
 * seeder ne posent que les FONDATIONS (données, placement, lecture de grille,
 * publication), aucune règle de jeu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terrains', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->string('nom_anglais');
            // Défaut 1 = une case ordinaire (même coût qu'un sol nu). Seule la
            // Rivière Gelée vaut 2 à ce jour.
            $table->unsignedTinyInteger('cout_deplacement')->default(1);
            $table->boolean('bloque_mouvement')->default(false);
            $table->boolean('bloque_vue')->default(false);
            $table->json('effet')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terrains');
    }
};
