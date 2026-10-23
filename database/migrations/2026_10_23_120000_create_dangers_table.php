<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATALOGUE DES DANGERS MOBILES AUTONOMES (2026-10-23 — `docs/plan-kellars-keep.md` lot E, `docs/plan-witch-lord.md` lot E,
 * décision Q3/Q6 du 2026-10-15 : une seule mécanique pour le rocher roulant et la brume de la mort).
 *
 * Migration ADDITIVE : une table nouvelle, aucune ligne existante touchée. Les lignes sont semées par `DangerSeeder`
 * (`updateOrCreate` sur `nom`, jamais de purge). Position et état d'un danger EN PARTIE vivent dans
 * `cartes.grille['dangers']` (état durable, jamais en cache) — ce catalogue ne décrit que ce qui est commun à toutes
 * les occurrences : la vitesse, les dégâts, l'arrêt, la destruction, le moment de la pose.
 *
 * `effet` : vocabulaire fermé `App\Engine\MotsClesDanger`. `boite` : même lecture que `terrains.boite` — `null`
 * convient à tout thème, une boîte ne pose le danger que dans la campagne qui joue cette boîte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dangers')) {
            Schema::create('dangers', function (Blueprint $table): void {
                $table->id();
                $table->string('nom')->unique();
                $table->string('boite')->nullable();
                $table->json('effet');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dangers');
    }
};
