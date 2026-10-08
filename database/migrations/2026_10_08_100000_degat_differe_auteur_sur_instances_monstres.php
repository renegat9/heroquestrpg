<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auteur de la braise du *Toucher du Brasier* (chantier 1c, Wizards of Morcar,
 * 2026-10-08) — la faveur « Peacekeeper » (livret G1504 p. 22-23 : « you reduce
 * to 0 Body Points » ; 25 po par monstre achevé) doit créditer le héros qui a
 * posé la braise quand c'est elle qui achève la cible, à la fin de son tour.
 *
 * `degat_differe` ne gardait que le MONTANT : l'auteur, perdu, faisait de cette
 * mise à mort la seule de ses 12 voies qui ne créditait personne. Colonne
 * durable (CLAUDE.md : jamais en cache) ; `null` quand la braise est éteinte
 * ou posée sans héros identifiable. `nullOnDelete` : un héros supprimé ne laisse
 * pas de référence morte — la braise tombe alors sans crédit, sans erreur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->foreignId('degat_differe_personnage_id')
                ->nullable()
                ->after('degat_differe')
                ->constrained('personnages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('degat_differe_personnage_id');
        });
    }
};
