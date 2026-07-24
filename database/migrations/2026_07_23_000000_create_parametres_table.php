<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages globaux du serveur (panneau « Réglages » — écran de Narrateur/
 * table) : fournisseur/modèle IA, bascules RAG/voix dynamique/illustrations,
 * voix du narrateur, équilibrage des rencontres. UNE SEULE ligne (singleton,
 * {@see \App\Models\Parametre::actuel()}) — colonnes NULLABLES = « suit le
 * défaut .env/config » tant qu'aucune surcharge n'a été enregistrée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametres', function (Blueprint $table) {
            $table->id();
            $table->string('llm_provider')->nullable(); // anthropic | gemini | NULL (suit .env)
            $table->string('modele_anthropic')->nullable();
            $table->string('modele_gemini')->nullable();
            $table->boolean('rag_actif')->default(true);
            $table->boolean('voix_dynamique_active')->default(true);
            // Illustrations IA (portraits/scènes/hub) — runtime uniquement, voir section dédiée.
            $table->boolean('images_actif')->default(true);
            // Voix Gemini du narrateur — NULL = suit config/narration.php ('Iapetus').
            $table->string('narration_voix')->nullable();
            // Équilibrage des rencontres (config/jeu.php, déjà « réglable » selon
            // docs/contrat-api.md) — NULL = suit .env. boss_pv_adaptatif en nullable
            // boolean (tri-état : NULL suit .env, true/false = surcharge explicite).
            $table->unsignedTinyInteger('rencontres_forts_par_quete')->nullable();
            $table->unsignedTinyInteger('rencontres_forts_escalade_arc')->nullable();
            $table->unsignedTinyInteger('rencontres_seuil_cout_fort')->nullable();
            $table->boolean('rencontres_boss_pv_adaptatif')->nullable();
            $table->unsignedTinyInteger('rencontres_taille_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres');
    }
};
