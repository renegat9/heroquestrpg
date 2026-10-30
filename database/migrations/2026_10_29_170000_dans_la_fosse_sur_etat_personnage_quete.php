<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FOSSE LONGUE (*The Mage of the Mirror* F7539 p. 6, « Long Pit Trap ») : un héros qui tombe dans la fosse y RESTE.
 * « On a subsequent turn, the hero must roll a 5 or 6 on a red die to climb out of the pit. While in the pit, the hero
 * rolls one less combat die when attacking or defending (but never less than one die). »
 *
 * Cet état DOIT survivre à un téléphone rechargé, à un round entier et à une reprise de snapshot : une COLONNE, jamais une
 * clé de cache (règle consolidée, CLAUDE.md « chaque état durable vit en base »). Même patron que `enlise` (Sables
 * mouvants, 2026-10-15) et `piege_a_ecarter`.
 *
 * Forme : {x: int, y: int, piege_id: int} — la case de la fosse où le héros est tombé, et le piège qui l'a fait tomber
 * (le malus et le jet de sortie se lisent au catalogue, jamais recopiés ici). Voir `App\Partie\MoteurFosseLongue`.
 * ADDITIVE : nullable, aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('dans_la_fosse')->nullable()->after('verrou_mental');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('dans_la_fosse');
        });
    }
};
