<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les arbres de talents deviennent une GRILLE : 3 colonnes × 3 lignes par
 * classe (René, 2026-08-23).
 *
 * Chaque classe nomme ses trois catégories ; dans une colonne, la ligne n exige
 * la ligne n−1. Quatre colonnes suffisent à porter cela, et `prerequis_id` ne
 * change pas de sens : le seeder le CALCULE désormais depuis la position, au
 * lieu de le nommer à la main — ce qui rend structurellement impossible une
 * chaîne traversant deux colonnes, et `CompetenceController::acquerir()` n'a
 * pas une ligne à changer.
 *
 * ⚠ `colonne`/`rang` restent NULL pour les nœuds `innee` : les capacités de
 * carte ne s'achètent pas, elles viennent avec la figurine. Les faire entrer
 * dans la grille laisserait croire qu'elles coûtent un point. L'index unique
 * les tolère — MariaDB comme SQLite acceptent plusieurs NULL dans un unique.
 *
 * ⚠ Aucune donnée n'est SUPPRIMÉE, et c'est vérifié : la grille est un
 * sur-ensemble strict de l'ancienne liste plate (les 62 nœuds existants s'y
 * retrouvent tous, à leur place). Le nettoyage défensif ci-dessous ne vise donc
 * que des nœuds qu'une base plus ancienne pourrait porter et que le seeder ne
 * recréera pas — sans lui ils resteraient hors grille, invisibles à l'écran
 * mais toujours acquérables par l'API. Il détache les acquisitions avant de
 * supprimer, comme `2026_08_22_000002_retirer_maitrise_lourde_et_poigne`.
 */
return new class extends Migration
{
    /**
     * Nœuds de l'ancienne liste plate que la grille ne reprend pas.
     *
     * Vide aujourd'hui — la refonte n'a rien retiré. La constante existe pour
     * que le retrait d'un nœud, le jour venu, ait un endroit évident où
     * s'écrire plutôt qu'une seconde migration à réinventer.
     *
     * @var list<string>
     */
    private const RETIRES = [];

    public function up(): void
    {
        Schema::table('competences', function (Blueprint $table) {
            // Libellé de la colonne, propre à la classe (« Furie », « Traque »,
            // « Pacte »…) : les catégories sont LIBRES par classe, il n'y a donc
            // pas de vocabulaire commun à contraindre par un enum.
            $table->string('categorie')->nullable()->after('type');
            // Icône Material Symbols de la colonne, dénormalisée sur ses trois
            // nœuds : trois lignes qui partagent une valeur coûtent moins qu'une
            // table de catégories pour 36 entrées, et le front n'a rien à joindre.
            $table->string('categorie_icone')->nullable()->after('categorie');
            $table->unsignedTinyInteger('colonne')->nullable()->after('categorie_icone');
            $table->unsignedTinyInteger('rang')->nullable()->after('colonne');

            $table->unique(['classe', 'colonne', 'rang'], 'competences_position_unique');
        });

        if (self::RETIRES !== []) {
            $ids = DB::table('competences')->whereIn('nom', self::RETIRES)->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table('personnage_competences')->whereIn('competence_id', $ids)->delete();
                DB::table('competences')->whereIn('id', $ids)->delete();
            }
        }
    }

    public function down(): void
    {
        Schema::table('competences', function (Blueprint $table) {
            $table->dropUnique('competences_position_unique');
            $table->dropColumn(['categorie', 'categorie_icone', 'colonne', 'rang']);
        });
    }
};
