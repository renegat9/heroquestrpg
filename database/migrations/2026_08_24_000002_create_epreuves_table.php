<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epreuves', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->text('description'); // phrase JOUEUR : ce qu'on voit, ce qu'on tente (affichée telle quelle sur la manette)
            $table->enum('attribut', ['body', 'mind']); // le jet tenté au contact — jamais les deux, l'épreuve tranche à la pose
            // 1 à 4 succès requis (même échelle que `sorts.difficulte_parchemin` et
            // `App\Engine\JetCompetence::resoudre()`) : PAS un nombre de dés, un
            // seuil de RÉUSSITES sur les dés lancés.
            $table->unsignedTinyInteger('difficulte');
            // Recoupe `App\Engine\MotsClesTalent::CONTEXTES` (savoir/perception/
            // social_peur/distance) : c'est ce qui donne enfin un PRODUCTEUR aux
            // talents bonus_attribut_mind/avantage_jet_mind conditionnés sur ces
            // contextes — jusqu'ici déclarés et jamais déclenchés faute de jet à
            // filtrer. Nullable : les deux épreuves de Body n'en portent aucun,
            // rien dans le catalogue de talents ne conditionne encore un jet de
            // Body par contexte.
            $table->string('contexte')->nullable();
            // ⚠ PRÉCONDITION DE POSE, pas un effet : dit où l'épreuve a le DROIT
            // d'être placée sur la carte (ex. `piege_dans_la_salle` — inutile de
            // désarmer une salle qui n'a rien à désarmer). Ne pas confondre avec
            // `effet`, qui dit ce que la réussite PRODUIT une fois posée.
            $table->string('exige_placement')->nullable();
            $table->json('effet'); // {mecanique, valeur?} — vocabulaire fermé, voir App\Engine\MotsClesEpreuve
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epreuves');
    }
};
