<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FAVEURS DE HOPEKINS REST CHOISIES AU HUB (René, 2026-10-16 : « Choisissons la faveur au hub si
 * les extensions qui les utilisent sont utilisées »). Livret G1504 p. 21 et 23 : « At the start of
 * each quest, a hero may select one location to visit and receive its Boon of Heroism (if
 * available). » Le tirage au hasard de fin de quête est retiré pour les campagnes Wizards of Morcar.
 *
 * 1. `personnage_visites_hopekins` — LA DÉCISION de chaque héros à chaque tour de hub : la quête
 *    achevée qui précède le hub (`quete_id`) et le lieu choisi (`lieu`, une clé de
 *    `FaveursHopekins::TOUTES`), `null` quand il choisit de n'en visiter aucun (« may », livret).
 *    Une ligne = décidé ; l'absence de ligne = à choisir. Unique sur (héros, quête) : un héros ne
 *    choisit qu'UNE fois par tour. État durable en base, jamais en cache (CLAUDE.md).
 *
 * 2. `groupes.hopekins_quete_tranchee_id` — REPLI pour les campagnes EN COURS. Avant ce changement,
 *    la faveur d'une quête gagnée était TIRÉE à la fin de cette quête : le tour de hub qui suit
 *    était déjà pourvu. Le choix ne doit donc s'ouvrir qu'au PROCHAIN hub (« le choix s'ouvre à la
 *    prochaine phase de hub »), jamais en plus d'une faveur déjà tirée. La colonne marque, pour
 *    chaque groupe, la dernière quête achevée dont le tour est déjà tranché : la migration la pose
 *    sur la quête achevée la plus récente de chaque groupe qui en a une. Les faveurs déjà TENUES ne
 *    sont jamais retirées : seul le choix futur change. Un groupe neuf a `null` : son premier tour
 *    s'ouvre à la deuxième quête gagnée (Gardien).
 *
 * La colonne est sans FK sous SQLite (tests), avec FK `nullOnDelete` ailleurs : le même patron que
 * `groupes.quete_courante_id` (dépendance circulaire groupes ↔ quetes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnage_visites_hopekins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnage_id')->constrained('personnages')->cascadeOnDelete();
            $table->foreignId('quete_id')->constrained('quetes')->cascadeOnDelete();
            // Clé de `FaveursHopekins::TOUTES` ; `null` = le héros n'a visité aucun lieu à ce tour.
            $table->string('lieu')->nullable();
            $table->timestamps();

            $table->unique(['personnage_id', 'quete_id']);
        });

        Schema::table('groupes', function (Blueprint $table) {
            $table->unsignedBigInteger('hopekins_quete_tranchee_id')->nullable()->index();
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('groupes', function (Blueprint $table) {
                $table->foreign('hopekins_quete_tranchee_id')->references('id')->on('quetes')->nullOnDelete();
            });
        }

        // Repli : la quête achevée la plus récente de chaque groupe ferme son tour (déjà tranché
        // par le tirage d'avant). Sous-requête corrélée, valide sous MariaDB comme sous SQLite.
        DB::statement(
            "UPDATE groupes SET hopekins_quete_tranchee_id = "
            ."(SELECT MAX(q.id) FROM quetes q WHERE q.groupe_id = groupes.id AND q.etat = 'terminee')"
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('groupes', function (Blueprint $table) {
                $table->dropForeign(['hopekins_quete_tranchee_id']);
            });
        }

        Schema::table('groupes', function (Blueprint $table) {
            $table->dropIndex(['hopekins_quete_tranchee_id']);
            $table->dropColumn('hopekins_quete_tranchee_id');
        });

        Schema::dropIfExists('personnage_visites_hopekins');
    }
};
