<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sorts.element` accueille les TROIS répertoires OPTIONNELS de Wizards of
 * Morcar (livret G1504 p. 11, 2026-10-06) — *Spells of Protection*,
 * *Spells of Detection*, *Spells of Darkness* : « These may replace existing
 * sets of spells that a spellcaster can draw on […]. Spellcasters may change
 * their spells between quests. »
 *
 * Même geste que `2026_08_12_000003_repertoires_de_sorts.php`, qui a ouvert
 * cet enum une première fois pour `elfique`/`barde`/`druide`/`warlock` : la
 * colonne sert de nom de RÉPERTOIRE, pas seulement d'ÉCOLE élémentaire — la
 * réutiliser évite une table de plus pour trois lignes, mais l'enum doit
 * encore s'ouvrir pour les accueillir.
 */
return new class extends Migration
{
    private const AVEC = ['feu', 'eau', 'terre', 'air', 'barde', 'druide', 'warlock', 'elfique', 'parchemin',
        'protection', 'detection', 'tenebres'];

    private const SANS = ['feu', 'eau', 'terre', 'air', 'barde', 'druide', 'warlock', 'elfique', 'parchemin'];

    public function up(): void
    {
        $this->enum(self::AVEC);
    }

    public function down(): void
    {
        $this->enum(self::SANS);
    }

    /**
     * @param  list<string>  $valeurs
     */
    private function enum(array $valeurs): void
    {
        Schema::table('sorts', function (Blueprint $t) use ($valeurs) {
            $t->enum('element', $valeurs)->change();
        });
    }
};
