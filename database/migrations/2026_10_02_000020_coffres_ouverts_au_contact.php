<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COFFRES ET CAISSE : on les fouille AU CONTACT, plus en fouillant la salle
 * (René, 2026-10-02 : « Je veux que la recherche de coffre ou de caisse se
 * fassent seulement quand on est adjacent et non quand on cherche la salle »).
 *
 * `quetes.coffres_ouverts` : les salles dont le COFFRE DÉSIGNÉ (salle du fond,
 * passages secrets) a été ouvert. Jusqu'ici l'état se DÉDUISAIT de
 * `tresors_fouilles` — un coffre était plein tant que personne n'avait fouillé
 * la salle. Le coffre se fouillant désormais au contact, les deux gestes se
 * séparent et il faut un état à lui, en base (règle du projet : jamais de
 * cache pour un état de partie).
 *
 * Remplissage : une salle à coffre déjà fouillée sous l'ancienne règle a vu son
 * coffre ouvert — on la reporte, pour qu'aucun coffre ne se REMPLISSE dans une
 * quête en cours.
 *
 * Et la Caisse de ravitaillement devient `fouillable` : c'est désormais par
 * l'action « Fouiller : Caisse de ravitaillement » qu'elle rend ses potions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('coffres_ouverts')->nullable()->after('salles_coffre');
        });

        foreach (DB::table('quetes')->get(['id', 'tresors_fouilles', 'salles_coffre', 'salle_artefact']) as $q) {
            $fouillees = array_map(
                fn ($e) => (int) explode(':', (string) $e)[0],
                (array) (json_decode((string) $q->tresors_fouilles, true) ?: []),
            );
            $coffres = array_map('intval', (array) (json_decode((string) $q->salles_coffre, true) ?: []));

            if ($q->salle_artefact !== null) {
                $coffres[] = (int) $q->salle_artefact;
            }

            $ouverts = array_values(array_unique(array_intersect($coffres, $fouillees)));

            DB::table('quetes')->where('id', $q->id)->update(['coffres_ouverts' => json_encode($ouverts)]);
        }

        DB::table('mobiliers')->where('nom', 'Caisse de ravitaillement')->update(['fouillable' => true]);
    }

    public function down(): void
    {
        DB::table('mobiliers')->where('nom', 'Caisse de ravitaillement')->update(['fouillable' => false]);

        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('coffres_ouverts');
        });
    }
};
