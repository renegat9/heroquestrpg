<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réaction HORS TOUR en attente de décision du joueur.
 *
 * *Dark Wings* (Warlock) et *Twisting Torrent* (Moine) s'activent **pendant le
 * tour d'un monstre**, après avoir encaissé. Or la phase des monstres se résout
 * dans la requête d'un AUTRE joueur, à l'intérieur d'une transaction : on ne
 * peut pas la suspendre pour interroger un téléphone. La proposition est donc
 * DÉPOSÉE ici, le joueur répond quand il veut (fenêtre courte), et la réaction
 * défait le coup si elle est acceptée.
 *
 * En BASE et non en cache : c'est la règle consolidée du projet. Une clé de
 * cache qui expire pendant que le joueur réfléchit lui volerait son pouvoir
 * sans rien lui dire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('reaction_en_attente')->nullable()->after('jetons_rejeton');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('reaction_en_attente');
        });
    }
};
