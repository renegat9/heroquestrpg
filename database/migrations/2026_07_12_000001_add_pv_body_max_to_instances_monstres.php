<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            // PV max PROPRE à l'instance : les boss/sous-boss adaptent leurs PV à
            // la taille du groupe (au lieu d'un 10 fixe), et le +1 élite y est
            // intégré. Nullable → repli sur les PV du catalogue pour d'éventuelles
            // lignes créées avant cette colonne (InstanceMonstre::pvBodyMax()).
            $table->unsignedInteger('pv_body_max')->nullable()->after('pv_body');
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn('pv_body_max');
        });
    }
};
