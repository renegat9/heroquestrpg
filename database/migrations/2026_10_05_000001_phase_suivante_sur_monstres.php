<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MONSTRE À PHASES (chantier transverse 2026-10-04, lot C d'Against the Ogre
 * Horde p. 6 : « Some powerful foes adopt new statistics as the heroes battle
 * them. A monster who changes statistics is still considered THE SAME monster
 * for game effects such as spells. »).
 *
 * `phase_suivante` est le lien DÉCLARÉ (nom_base de la créature suivante),
 * exactement le même patron que `variante_distance_de` (2026-10-02) : jamais
 * déduit d'une convention de nommage, lu à l'UNIQUE point de passage où une
 * instance atteint 0 Body — {@see \App\Partie\MoteurDegats::infligerAMonstre()}.
 * `null` (toutes les lignes existantes) = ce bloc de stats n'est pas une phase
 * intermédiaire ; une chaîne de phases se termine par une ligne dont la colonne
 * reste `null`, et C'EST ce `null` qui dit « dernière phase, meurt pour de
 * vrai » plutôt qu'un drapeau séparé.
 *
 * Les trois monstres déjà sourcés qui en ont besoin (Gruzbella Hammerhand 3
 * formes, Spawn of the Pit 2 formes — Against the Ogre Horde p. 21, 27 —, et
 * Gretzl la Porte-Fléau 3 formes — Jungles of Delthrak q. 12A) sont semés par
 * `MonstreSeeder`, qui remplit la colonne pour les lignes concernées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->string('phase_suivante')->nullable()->after('archetype_lanceur');
        });
    }

    public function down(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->dropColumn('phase_suivante');
        });
    }
};
