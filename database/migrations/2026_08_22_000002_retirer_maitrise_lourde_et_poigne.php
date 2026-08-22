<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le barbare et le nain ne paient plus l'un pour l'autre (René, 2026-08-22).
 *
 * Ils étaient MIROIRS : chacun avait sa spécialité gratuite et achetait celle
 * de l'autre — le barbare maniait le deux-mains et payait « Maîtrise lourde »
 * pour la plate, le nain portait la plate et payait « Poigne de forgeron » pour
 * le deux-mains. René supprime la symétrie : les deux ont tout dès le niveau 1,
 * et les deux nœuds disparaissent.
 *
 * ⚠ `CompetenceSeeder` utilise `updateOrCreate` et ne PURGE pas — c'est
 * délibéré, un `personnage_competences` référence l'id du nœud et une purge
 * détacherait en silence ceux que des héros ont déjà acquis. Les retirer du
 * seeder ne suffit donc pas : les lignes survivraient dans toute base existante,
 * et l'arbre continuerait de proposer un nœud qui n'ouvre plus rien.
 *
 * Les acquisitions éventuelles partent avec : le point dépensé revient de
 * lui-même, les points disponibles se DÉRIVANT du niveau moins les nœuds pris
 * (aucune colonne de solde à recréditer).
 */
return new class extends Migration
{
    private const RETIRES = ['Maîtrise lourde', 'Poigne de forgeron'];

    public function up(): void
    {
        $ids = DB::table('competences')->whereIn('nom', self::RETIRES)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Les acquisitions d'abord : la contrainte de clé étrangère refuserait
        // de supprimer un nœud encore référencé.
        DB::table('personnage_competences')->whereIn('competence_id', $ids)->delete();
        DB::table('competences')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // Irréversible à dessein : recréer les nœuds leur donnerait de nouveaux
        // ids, sans rendre à quiconque celui qu'il avait acquis. Un rollback
        // suivi d'un `db:seed` les recrée proprement s'ils reviennent un jour.
    }
};
