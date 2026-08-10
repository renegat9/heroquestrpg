<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHARGES : un exemplaire d'objet à N utilisations (décision de René, 2026-08-09).
 *
 * Le catalogue ne savait dire qu'une chose : combien d'exemplaires identiques on
 * possède (`inventaire.quantite`, la pile de potions). Il ne savait pas dire
 * combien de fois CET exemplaire-ci peut encore servir — un arc à quatre
 * flèches, un anneau à usage unique. C'était le blocage nommé pour sept cartes
 * des deux paquets (`reference/16_armurerie.md` §9.1).
 *
 * La colonne vit sur `inventaire`, pas sur `objets` : deux héros peuvent porter
 * le même modèle d'arc avec un nombre de flèches différent. `objets.effet.charges`
 * ne donne que la valeur INITIALE.
 *
 * `null` ne veut pas dire « épuisé » mais « jamais entamé » : une ligne créée
 * avant cette migration, ou par n'importe lequel des chemins qui peuplent
 * l'inventaire (marché, coffre, don, butin), démarre donc pleine sans qu'on ait
 * eu à modifier ces chemins. `MoteurCharges::restantes()` fait la lecture.
 *
 * À zéro, l'objet devient INERTE — il reste en inventaire mais son effet ne
 * s'applique plus, ce qui est le texte même de la carte de l'arc elfique :
 * « There are only 4 arrows with this bow. It becomes useless afterwards. »
 * Rien n'est détruit : un objet qui disparaîtrait tout seul du sac serait une
 * surprise, pas une règle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventaire', function (Blueprint $table) {
            $table->unsignedInteger('charges')->nullable()->after('quantite');
        });
    }

    public function down(): void
    {
        Schema::table('inventaire', function (Blueprint $table) {
            $table->dropColumn('charges');
        });
    }
};
