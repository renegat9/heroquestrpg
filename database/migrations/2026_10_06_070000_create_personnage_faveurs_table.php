<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faveurs de Hopekins Rest (livret G1504 p. 22-23, Wizards of Morcar) —
 * chantier 1c, décision de René du 2026-10-06 : « récompense de quête
 * séparée, hors arbre de talents ».
 *
 * État DURABLE en base (hard rule CLAUDE.md : jamais en cache) — une faveur
 * acquise par un héros ne peut JAMAIS être recalculée depuis autre chose
 * que cette table : contrairement à un nœud de talent (dérivé du niveau +
 * des choix de grille), une faveur est un don PONCTUEL, tiré une fois à la
 * fin d'une quête (`App\Partie\FaveursHopekins::attribuerFaveurDeFinDeQuete()`).
 *
 * `cle` est le vocabulaire fermé des 5 compétences transcrites
 * (`reference/18_extensions.md` §Wizards of Morcar — cartes TRANSCRITES, §7) :
 * Deadeye, Weapon Expert, Healing Hands, Hold the Line, Peacekeeper — voir
 * `App\Partie\FaveursHopekins::TOUTES`, seule source de ce vocabulaire. Un
 * hero ne peut recevoir une MÊME faveur deux fois (unique), mais peut en
 * accumuler plusieurs sur une longue campagne.
 *
 * `parametre` sert à UNE seule faveur aujourd'hui (Weapon Expert : l'id du
 * type d'arme choisi, lié au premier coup porté après l'acquisition faute de
 * choix explicite côté joueur — voir le docblock de la méthode) ; `null`
 * pour les quatre autres, qui n'ont rien à mémoriser en plus de leur `cle`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnage_faveurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnage_id')->constrained('personnages')->cascadeOnDelete();
            $table->string('cle');
            $table->string('parametre')->nullable();
            $table->timestamps();

            $table->unique(['personnage_id', 'cle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnage_faveurs');
    }
};
