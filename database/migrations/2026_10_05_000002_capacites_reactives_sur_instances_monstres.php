<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacités réactives « à usage unique sans action » du monstre à phases
 * (chantier transverse 2026-10-04) : *Resilience* (Gruzbella, Against the
 * Ogre Horde p. 6) et *Demon Wings* (Gretzl, Jungles of Delthrak q. 12A)
 * ignorent intégralement les dégâts d'une attaque, UNE fois pour toute la
 * rencontre — même famille que *Break* / *Dispel* (annule un sort la ciblant,
 * {@see \App\Partie\MoteurSorts::poserConditionMonstre()}) et que
 * `increvable_une_fois` (Sir Ragnar, Rise of the Dread Moon p. 31 : « The
 * first time Sir Ragnar's Body Points are reduced to 0, they are instead
 * reduced to 1 »).
 *
 * Suit le patron déjà en place pour les sorts de Dread (`usages_dread` et
 * ses deux verrous dédiés, 2026-09-01) plutôt que d'ajouter une colonne par
 * mécanique : contrairement à l'Invocation et à la Fuite (deux verrous fixes
 * pour TOUT lanceur), l'ENSEMBLE des mécaniques réactives varie d'un monstre
 * nommé à l'autre (Gruzbella en a deux portées ici, Gretzl deux autres) — un
 * tableau JSON des noms déjà dépensés est donc le même choix que
 * `etat_personnage_quete.capacites_utilisees` côté héros, pas une régression
 * vers le cache : la colonne EST l'état durable.
 *
 * Réarmée par `MoteurDread::reinitialiserUsagesInstance()`, au même instant
 * que `usages_dread` — placement = nouvelle rencontre, jamais remise à zéro
 * par un changement de phase (« la même instance » garde ses usages déjà
 * dépensés d'une phase à l'autre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->json('capacites_reactives_utilisees')->nullable()->after('fuite_dread_utilisee');
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn('capacites_reactives_utilisees');
        });
    }
};
