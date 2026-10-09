<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jungles of Delthrak — le BUTIN (chantier A, 2026-10-09). Trois ajouts,
 * tous ADDITIFS (aucune ligne existante n'est touchée) :
 *
 *  1. `objets.categorie` gagne la valeur `tresor` — l'*Emerald Heart of
 *     Delthrak* (« can be sold for 75 gold coins ») et l'*Ancient Dwarven
 *     Relic* (« is worth 50 gold coins ») ne sont ni une arme, ni une armure,
 *     ni un outil, ni un consommable : ce sont des trésors-valeurs, que le
 *     marché achète à leur valeur ENTIÈRE (`effet.valeur_marchande`) et ne
 *     vend jamais. Une catégorie à part est ce qui les tient hors de tout
 *     vivier de butin tiré par catégorie (`MoteurMobilier`, épreuves) et hors
 *     du menu « Boire » (qui ne reconnaît que `consommable`).
 *
 *  2. `inventaire.quetes_avant_reveil` — l'état DORMANT du *Fangwarden Armlet* :
 *     « If the Raptor is defeated, the armlet's power goes dormant. Its power
 *     replenishes if the hero completes two quests without its assistance. »
 *     `null` = éveillé ; N > 0 = dormant, N quêtes terminées avant le réveil.
 *     Une colonne sur la LIGNE d'inventaire (jamais du cache : CLAUDE.md « tout
 *     état durable vit en base ») — elle suit le brassard quand on le donne.
 *
 *  3. `groupe_mercenaires.invoque_par_objet_id` — marque un allié APPELÉ par
 *     un objet (le Raptor du brassard) : il n'est pas recruté, il ne survit pas
 *     à la quête (sans quoi « une fois par quête » ouvrirait un second Raptor à
 *     chaque quête), et sa mort est ce qui endort l'objet. On stocke l'`objets.id`
 *     du CATALOGUE et non la ligne d'inventaire : une reprise de snapshot
 *     recrée les lignes avec de nouveaux identifiants.
 */
return new class extends Migration
{
    private const AVEC = ['arme', 'armure', 'outil', 'consommable', 'parchemin', 'tresor'];

    private const SANS = ['arme', 'armure', 'outil', 'consommable', 'parchemin'];

    public function up(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            $table->enum('categorie', self::AVEC)->change();
        });

        Schema::table('inventaire', function (Blueprint $table) {
            $table->unsignedTinyInteger('quetes_avant_reveil')->nullable()->after('charges');
        });

        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->unsignedBigInteger('invoque_par_objet_id')->nullable()->after('recruteur_personnage_id');
        });
    }

    public function down(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->dropColumn('invoque_par_objet_id');
        });

        Schema::table('inventaire', function (Blueprint $table) {
            $table->dropColumn('quetes_avant_reveil');
        });

        // Les trésors déjà semés sortent du catalogue avec la valeur d'enum :
        // leurs lignes d'inventaire partent d'abord, sans quoi elles
        // pointeraient vers rien.
        $ids = DB::table('objets')->where('categorie', 'tresor')->pluck('id');
        DB::table('inventaire')->whereIn('objet_id', $ids)->delete();
        DB::table('objets')->whereIn('id', $ids)->delete();

        Schema::table('objets', function (Blueprint $table) {
            $table->enum('categorie', self::SANS)->change();
        });
    }
};
