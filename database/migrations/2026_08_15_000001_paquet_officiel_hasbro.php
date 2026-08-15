<?php

declare(strict_types=1);

use App\Models\Personnage;
use App\Partie\Equipement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'armurerie passe au PAQUET OFFICIEL Hasbro (décision de René, 2026-08-15).
 *
 * René a fourni les photos des cartes réelles — 20 d'équipement, 15 de potions,
 * chacune tamponnée © 2021, 2022 ou 2023 Hasbro. Elles sont transcrites carte
 * par carte en `reference/16_armurerie.md` §2.1bis, et priment sur la
 * conversion fan de §2.2 qui servait jusqu'ici de source : son auteur écrit
 * lui-même « I have changed some item costs and functionality ».
 *
 * Douze pièces n'ont AUCUNE carte officielle. Ce ne sont pas des oublis du
 * matériel : la canne, la fronde, le fouet, les arcs, la hallebarde, la masse,
 * le fléau, l'espadon, l'épée bâtarde, la lance et la cape de protection
 * viennent du paquet fan, qui ajoutait librement des armes n'ayant jamais
 * existé au plateau. Elles sortent du catalogue.
 *
 * ⚠ Ce que le précédent de 2026-08-09 avait oublié, et qu'on ajoute ici : les
 * DÉS DE COMBAT sont recalculés pour chaque héros touché. `personnages`
 * mémorise `des_attaque` / `des_defense` en colonnes ; un `DELETE` brut sur
 * l'inventaire les laisse figés sur une arme qui n'existe plus, et le héros
 * frappe pendant des quêtes avec une épée bâtarde fantôme.
 */
return new class extends Migration
{
    /** Les douze pièces qu'aucune carte officielle n'atteste. */
    private const HORS_PAQUET = [
        'Canne',
        'Fronde',
        'Fouet',
        'Arc court',
        'Arc long',
        'Lance',
        'Hallebarde',
        'Masse',
        'Fléau',
        'Espadon',
        'Épée bâtarde',
        'Cape de protection',
    ];

    /**
     * Trois potions de notre invention, remplacées par leur équivalent
     * officiel : l'Antidote par l'Antidote au venin, la Potion d'esprit clair
     * et la Potion de rage par les potions de restauration et de bataille. Les
     * gabarits de quête qui les citaient ont été repointés dans le même lot.
     */
    private const POTIONS_REMPLACEES = [
        'Antidote',
        "Potion d'esprit clair",
        'Potion de rage',
    ];

    public function up(): void
    {
        $noms = [...self::HORS_PAQUET, ...self::POTIONS_REMPLACEES];
        $ids = DB::table('objets')->whereIn('nom', $noms)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Les porteurs, RELEVÉS AVANT la suppression : après, plus rien ne dit
        // qui tenait quoi.
        $porteurs = DB::table('inventaire')->whereIn('objet_id', $ids)
            ->pluck('personnage_id')->unique();

        // Ordre imposé par les clés étrangères (`inventaire.objet_id` est
        // `restrictOnDelete`) : d'abord ce qui POINTE vers l'objet, l'objet en
        // dernier.
        DB::table('quetes')->whereIn('artefact_objet_id', $ids)->update(['artefact_objet_id' => null]);
        DB::table('inventaire')->whereIn('objet_id', $ids)->delete();
        DB::table('objets')->whereIn('id', $ids)->delete();

        // Un héros désarmé doit le VOIR : ses dés reviennent à ceux de sa
        // classe, et la manette le montre au tour suivant.
        $equipement = app(Equipement::class);

        foreach (Personnage::whereIn('id', $porteurs)->get() as $personnage) {
            $equipement->recalculerCombat($personnage);
        }
    }

    /**
     * Irréversible côté DONNÉES, comme le retrait des artefacts inventés : les
     * objets se resèment (`ObjetSeeder`), les lignes d'inventaire détruites ne
     * se reconstituent pas. Rien à défaire côté schéma.
     */
    public function down(): void {}
};
