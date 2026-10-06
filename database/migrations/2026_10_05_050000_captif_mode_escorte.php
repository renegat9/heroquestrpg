<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier « captifs-jetons » (décision de René du 2026-10-05,
 * `docs/plan-chantiers-transverses-2026-10-04.md` §Décisions du 2026-10-05) —
 * UN SECOND MODE de captif pour la mission « secourir ».
 *
 * Gothar (*The Frozen Horror* p. 19/37) a une FIGURINE sur le plateau et un
 * bloc de stats sourcé : libéré, il rejoue comme un allié ordinaire
 * (`etat: 'actif'`), relevable, attaquable, escorté case par case jusqu'à
 * l'escalier (mode `'figurine'`, le seul qui existait jusqu'ici, implicite).
 *
 * Le Prospecteur et la Princesse Millandriel (*The Mage of the Mirror*,
 * livret F7539 p. 4) sont des TUILES SANS CARTE : « This tile represents the
 * old prospector who acts as an ally and is controlled by the hero who finds
 * him » — aucun Move/Attack/Defend/Body/Mind à sourcer, jamais une figurine.
 * Libérés, ils sont PORTÉS par le héros libérateur (`etat: 'porte'`, un
 * QUATRIÈME état qui rejoint `'actif'/'vaincu'/'captif'`) : aucun tour,
 * aucune case propre sur la grille, aucune cible pour les monstres — les
 * trois lecteurs qui filtrent `etat: 'actif'` (figure, ciblage, ordre du
 * tour) et celui qui filtre `etat: 'captif'` (occupation de la case,
 * libération) excluent donc `'porte'` SANS un seul `if` supplémentaire.
 * `position_x`/`position_y` ne sont plus jamais réécrits une fois ce mode
 * atteint : ils restent la case d'ORIGINE, relue si le porteur tombe
 * (« monsters take the prospector to room D », p. 23) pour une RECAPTURE
 * (`etat` repasse à `'captif'`, {@see \App\Partie\ResolveurTour::reprendreCaptifsPortes()}).
 *
 * `mercenaires.mode_captif` déclare CE vocabulaire fermé — deux valeurs,
 * lues au seul moment où il compte : `ResolveurTour::resoudreLibererCaptif()`
 * choisit l'état posé à la libération (`'actif'` ou `'porte'`) sur cette
 * seule colonne. Partout ailleurs, c'est la valeur de `etat` elle-même qui
 * distingue les deux modes — {@see \App\Models\Quete::captifLibereEtVivant()}
 * lit la position du CAPTIF si `'actif'`, celle de son PORTEUR si `'porte'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercenaires', function (Blueprint $table) {
            $table->enum('mode_captif', ['figurine', 'escorte'])->default('figurine')->after('captif');
        });

        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->enum('etat', ['actif', 'vaincu', 'captif', 'porte'])->default('actif')->change();
        });
    }

    public function down(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->enum('etat', ['actif', 'vaincu', 'captif'])->default('actif')->change();
        });

        Schema::table('mercenaires', function (Blueprint $table) {
            $table->dropColumn('mode_captif');
        });
    }
};
