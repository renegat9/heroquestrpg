<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UNLEARN (Wizards of Morcar, *Spells of Protection*, 2026-10-08) — « You may
 * pick one spell caster and force them to discard one spell card at random.
 * The spell is removed from play for the duration of the Quest. »
 *
 * Un sort oublié est un ÉTAT DURABLE par QUÊTE : `personnage_sorts.disponible`
 * revient à `true` à chaque `DemarreurQuete::reinitialiserQuete()`, il ne peut
 * donc pas porter ce verrou (cf. `docs/regles/sorts-heros.md`). Une ligne par
 * (quête, cible, source, sort) : la quête la range, la fin de quête la rend
 * caduque sans rien effacer — « for the duration of the Quest ».
 *
 * - `cible_type` : `personnage` (un héros, source `sort`) ou `instance_monstre`
 *   (un Sorcier, source `sort_dread`). La source sépare les deux répertoires,
 *   qui portent des noms communs (*Unlearn* existe côté héros ET côté Dread).
 * - `nom` : le nom du sort, comme `sorts.nom` / `sorts_dread.nom` — la même
 *   clé que `MoteurDread::repertoireSorts()` et que le pivot héros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sorts_oublies_de_quete', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quete_id')->constrained('quetes')->cascadeOnDelete();
            $t->string('cible_type', 20);
            $t->unsignedBigInteger('cible_id');
            $t->string('source', 20);
            $t->string('nom', 120);
            $t->timestamps();

            $t->unique(['quete_id', 'cible_type', 'cible_id', 'source', 'nom'], 'sorts_oublies_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sorts_oublies_de_quete');
    }
};
