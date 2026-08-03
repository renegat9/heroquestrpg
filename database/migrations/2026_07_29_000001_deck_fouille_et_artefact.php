<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deck de cartes de fouille + coffre à artefact (doc 04 §4/§6, doc 14 §3.2).
 *
 * Remplace le tirage pondéré `tresor_a_risque` (un d6 remappé sur des poids,
 * biaisé dès que leur total ≠ 6) par un vrai deck sans remise, bâti au
 * démarrage de la quête : la composition est alors garantie, plus seulement
 * probable.
 *
 * `budget_errant` quitte le cache au passage. C'était le dernier état de jeu
 * durable à y vivre avec un TTL — même famille que le brouillard d'exploration
 * (verdict §2.16), dont la perte figeait tout le groupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            // Pioche ORDONNÉE, index 0 = sommet. Chaque carte est
            // auto-suffisante ({issue, or?, objet_id?}) : piocher est un simple
            // array_shift, sans dé, et un snapshot restaure exactement le futur
            // de la quête.
            $table->json('deck_fouille')->nullable()->after('tresors_fouilles');

            // Salle abritant l'artefact (index dans carte.grille.salles) : la
            // plus profonde depuis le départ. Ne consomme aucune carte du deck.
            $table->unsignedSmallInteger('salle_artefact')->nullable()->after('deck_fouille');

            // L'arme unique attribuée à cette quête — null = aucune disponible,
            // le coffre verse alors `deck_fouille.or_coffre` du gabarit.
            $table->foreignId('artefact_objet_id')->nullable()->after('salle_artefact')
                ->constrained('objets')->nullOnDelete();

            $table->unsignedSmallInteger('budget_errant')->nullable()->after('artefact_objet_id');
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('artefact_objet_id');
            // `budget_errant` a sa propre migration de suppression (2026_08_04) :
            // le lister ici casserait un rollback complet.
            $table->dropColumn(['deck_fouille', 'salle_artefact']);
        });
    }
};
