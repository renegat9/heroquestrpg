<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cinquième emplacement porté — le TALISMAN — et retrait des 7 artefacts
 * inventés (décision de René, 2026-08-09).
 *
 * Le catalogue d'artefacts est désormais la conversion du paquet de cartes
 * `sjeng-artefacts.pdf` (Ye Olde Inn), comme l'armurerie l'est du paquet
 * d'équipement (`reference/16_armurerie.md` §2.2 et §9). Les 7 artefacts qu'on
 * avait inventés — Lame d'Aube, Kriss du Fossoyeur, Arbalète des Murmures,
 * Bâton des Sept Sceaux, Marteau du Gardien de Pierre, Hache du Roi sous la
 * Montagne, Fendoir des Titans — n'ont **aucun équivalent officiel** : ce
 * n'étaient que des armes à dés croissants, là où les vrais artefacts ont
 * chacun un pouvoir propre. Ils sont supprimés, avec leurs lignes d'inventaire
 * et les pointeurs de quête qui les désignaient.
 *
 * Le slot `talisman` accueille les bijoux de classe (amulette, brassards,
 * capuche, runes) : ils n'ont ni dés d'attaque ni dés de défense — ils
 * augmentent les PV maximum —, donc ni le slot `armure` ni le slot `casque` ne
 * leur convenait sans leur faire concurrencer une vraie armure.
 */
return new class extends Migration
{
    private const AVEC = ['arme_principale', 'arme_secondaire', 'casque', 'armure', 'talisman', 'sac', 'consommable'];

    private const SANS = ['arme_principale', 'arme_secondaire', 'casque', 'armure', 'sac', 'consommable'];

    /** Les 7 artefacts inventés, retirés du catalogue. */
    private const INVENTES = [
        "Lame d'Aube",
        'Kriss du Fossoyeur',
        'Arbalète des Murmures',
        'Bâton des Sept Sceaux',
        'Marteau du Gardien de Pierre',
        'Hache du Roi sous la Montagne',
        'Fendoir des Titans',
    ];

    public function up(): void
    {
        $this->enums(self::AVEC);

        $ids = DB::table('objets')->whereIn('nom', self::INVENTES)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Ordre imposé par les clés étrangères : d'abord ce qui POINTE vers
        // l'objet, l'objet en dernier. Une quête en cours qui désignait un de
        // ces artefacts verra son coffre verser de l'or à la place — le repli
        // que DeckFouille applique déjà quand aucun artefact n'est disponible.
        DB::table('quetes')->whereIn('artefact_objet_id', $ids)->update(['artefact_objet_id' => null]);
        DB::table('inventaire')->whereIn('objet_id', $ids)->delete();
        DB::table('objets')->whereIn('id', $ids)->delete();
    }

    /**
     * Irréversible côté DONNÉES : les objets supprimés sont re-semables
     * (`ObjetSeeder`), mais les lignes d'inventaire qui les portaient sont
     * perdues. Seul le schéma revient en arrière.
     */
    public function down(): void
    {
        DB::table('inventaire')->where('emplacement', 'talisman')->update(['emplacement' => 'sac']);
        DB::table('objets')->where('emplacement', 'talisman')->update(['emplacement' => 'armure']);

        $this->enums(self::SANS);
    }

    /**
     * @param  list<string>  $valeurs
     */
    private function enums(array $valeurs): void
    {
        foreach (['objets', 'inventaire'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($valeurs) {
                $t->enum('emplacement', $valeurs)->change();
            });
        }
    }
};
