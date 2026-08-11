<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les 8 classes d'extension entrent en base, avec de quoi porter leurs
 * capacités de carte.
 *
 * Trois enums verrouillaient la porte — `classes_heros.nom`,
 * `personnages.classe`, `competences.classe` : semer une neuvième classe
 * échouait au niveau SQL, avant même d'atteindre le seeder.
 *
 * **`competences.innee`** — les capacités officielles (Stalwart, Enrage,
 * Ambidextrous…) sont ACQUISES D'EMBLÉE, comme au plateau où la carte vient
 * avec la figurine (décision de René, 2026-08-12). On les range dans
 * `competences` plutôt que dans une table neuve : tout l'outillage existe déjà
 * — le pivot `personnage_competences`, la lecture par `$personnage->competences()`,
 * `Competence::resisteA()`, l'onglet d'arbre de la manette. Un nœud `innee` est
 * simplement attaché à la création et ne coûte aucun point.
 *
 * **`etat_personnage_quete.capacites_utilisees`** — la plupart de ces cartes
 * disent « **once per quest** ». Un booléen par capacité serait 24 colonnes ;
 * on garde donc la liste des capacités déjà dépensées.
 * ⚠ `garde_tenace_utilisee` N'EST PAS fondu là-dedans, et ce n'est pas un
 * oubli : ce nœud se recharge à chaque COMBAT, pas à chaque quête. Deux
 * cadences, deux colonnes.
 */
return new class extends Migration
{
    private const CLASSES = [
        'barbare', 'nain', 'elfe', 'magicien',
        'barde', 'druide', 'warlock', 'rogue',
        'moine', 'chevalier', 'berserker', 'explorateur',
    ];

    private const BASE = ['barbare', 'nain', 'elfe', 'magicien'];

    public function up(): void
    {
        $this->enums(self::CLASSES);

        Schema::table('competences', function (Blueprint $table) {
            $table->boolean('innee')->default(false)->after('type');
        });

        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('capacites_utilisees')->nullable()->after('garde_tenace_utilisee');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('capacites_utilisees');
        });

        Schema::table('competences', function (Blueprint $table) {
            $table->dropColumn('innee');
        });

        $this->enums(self::BASE);
    }

    /**
     * @param  list<string>  $valeurs
     */
    private function enums(array $valeurs): void
    {
        Schema::table('classes_heros', function (Blueprint $t) use ($valeurs) {
            $t->enum('nom', $valeurs)->change();
        });

        Schema::table('personnages', function (Blueprint $t) use ($valeurs) {
            $t->enum('classe', $valeurs)->change();
        });

        Schema::table('competences', function (Blueprint $t) use ($valeurs) {
            $t->enum('classe', $valeurs)->change();
        });
    }
};
