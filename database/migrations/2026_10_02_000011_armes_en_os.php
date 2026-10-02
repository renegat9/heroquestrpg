<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Armes en os (lot B, Against the Ogre Horde p. 8) : « Weapons made of bone
 * are identical to weapons of the same name found in the armory, but bone
 * weapons have no gold coin value and cannot be bought or sold. »
 *
 * `objets.os_de` est le lien DÉCLARÉ vers l'arme ordinaire dont une ligne est
 * la copie en os (son `nom`) — lu une seule fois, par `ObjetSeeder`, pour
 * dériver `effet`/`tag_equipement`/`des_attaque` de la MÊME valeur php que
 * l'arme de base plutôt que de les retaper une seconde fois. `null` pour la
 * quasi-totalité du catalogue.
 *
 * Aucune mécanique neuve à côté : l'invendabilité/l'inachetabilité se lisent
 * sur `rarete = 'unique'` (déjà refusée à la vente par `PhaseMarche`, déjà
 * absente de l'étal, déjà exclue de la Forge — voir `docs/regles/
 * equipement-et-armurerie.md`), pas sur une clé neuve : une arme en os EST
 * fonctionnellement un artefact de coffre (prix 0, jamais achetée), et
 * emprunter ce mécanisme déjà lu évite d'en inventer un second qui ferait la
 * même chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            $table->string('os_de')->nullable()->after('tag_equipement');
        });
    }

    public function down(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            $table->dropColumn('os_de');
        });
    }
};
