<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'Armure de plates FAIT PERDRE LE DÉ (contrat, 2026-09-24).
 *
 * `deplacement_tour` ne mémorisait que le TOTAL du jet de déplacement.
 * `de` était ensuite RECONSTITUÉ comme `total − base` dans `MenuMoteur` :
 * avec l'Armure de plates, un vrai 5 s'affichait « dé 3 » (ancienne clé
 * chiffrée `malus_deplacement: 2`, retirée), et un dé annulé (le porteur
 * n'avance que de sa base) faisait carrément disparaître le dé (`de: null`).
 * Aucun cache ne pouvait réparer ça (règle consolidée du projet : tout état
 * durable vit en base) — il fallait une colonne.
 *
 * `detail_deplacement_tour` porte le détail RÉEL du jet, écrit une seule fois
 * au lancer (même garde d'unicité que `deplacement_tour` : `MenuMoteur`
 * n'écrit que si la colonne est encore `null`) : `{base, des, de_annule,
 * de_annule_par}`. `des` sont les faces réellement tombées, jamais
 * reconstituées ; `de_annule` est la DÉCISION que le d6 ne compte pas ce
 * tour, `de_annule_par` le nom de la pièce qui l'impose, sortis du même
 * point de passage (`Equipement::detailDeDeplacementAnnule()`), jamais d'une
 * seconde recherche.
 *
 * ⚠ Ne voyage PAS dans le snapshot (`Sauvegarde`), pour la même raison que
 * `deplacement_tour` lui-même n'y voyage pas déjà : un snapshot `nouveau_tour`
 * est pris juste après `ouvrirNouveauTour()`, qui a remis les deux colonnes à
 * `null` pour tout le groupe — il n'y a donc jamais de détail à sauvegarder à
 * l'instant où l'instantané est pris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('detail_deplacement_tour')->nullable()->after('deplacement_restant');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('detail_deplacement_tour');
        });
    }
};
