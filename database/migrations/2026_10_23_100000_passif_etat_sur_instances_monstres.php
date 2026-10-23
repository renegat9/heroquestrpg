<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ÉTAT DU PASSIF (Kellar's Keep lot C, 2026-10-23 — `docs/plan-kellars-keep.md`, « Exécution (2026-10-23) ») :
 * un adversaire « dort jusqu'à son déclencheur » (`monstres.capacites.passif_jusqua`) a besoin de se souvenir
 * qu'il s'est éveillé, qu'il a bougé ou qu'il a attaqué. Ces trois drapeaux vivent EN COLONNE, jamais en cache
 * (règle dure : l'état durable n'est jamais en cache) ni dans l'habillage, qui s'écrit par morceaux.
 *
 * Migration ADDITIVE : une colonne JSON nullable. `null` = aucun passif touché (une figure ordinaire, ou une figure
 * dormante qui n'a encore rien vécu) — le lecteur `MoteurDormants` traite l'absence comme « pas éveillée, pas
 * agie, pas attaquée ». Rien à remplir pour les lignes existantes.
 *
 * Forme : `{"eveille": bool, "agi": bool, "attaque": bool}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('instances_monstres', 'passif_etat')) {
            Schema::table('instances_monstres', function (Blueprint $table): void {
                $table->json('passif_etat')->nullable()->after('origine_monstre');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('instances_monstres', 'passif_etat')) {
            Schema::table('instances_monstres', function (Blueprint $table): void {
                $table->dropColumn('passif_etat');
            });
        }
    }
};
