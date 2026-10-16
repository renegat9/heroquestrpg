<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIND LOCK (*Against the Ogre Horde* F9528 p. 10) : « For each skull scored by the
 * attacker, the defender is frozen for one turn. […] That hero takes a Mind Lock card
 * for each turn they are affected, returning one card to Zargon at the end of each of
 * their turns spent frozen. »
 *
 * Les cartes que le héros tient, ET le Sorcier à qui elles reviennent, sont un état
 * DURABLE : il traverse un round entier (le sort se lance à la phase des monstres, le tour
 * perdu est celui du round suivant), un téléphone rechargé et une reprise de snapshot.
 * Une COLONNE, jamais une clé de cache (règle consolidée, CLAUDE.md), sur le patron de
 * `enlise` (Sables mouvants, 2026-10-15) et `piege_a_ecarter`.
 *
 * Pourquoi le lanceur y figure : « If a Dread Sorcerer has an active spell and is killed,
 * the effects of that spell immediately end » (p. 10) — la mort du Sorcier doit retrouver
 * QUI il tenait gelé, et la carte rendue doit retourner dans SA main, pas dans celle d'un
 * autre Sorcier de la quête.
 *
 * Forme : {lanceur_id: int, sort: string, cartes: int} — voir `App\Partie\VerrouMental`.
 * ADDITIVE : nullable, aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('verrou_mental')->nullable()->after('enlise');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('verrou_mental');
        });
    }
};
