<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accès à l'équipement par TAG de maîtrise (doc 01 §7).
 *
 * Achève un design déjà déclaré mais jamais branché : le nœud « Maîtrise
 * lourde » porte depuis toujours `effet = {mecanique: acces_equipement, tags:
 * [arme_deux_mains, armure_lourde]}`, et **aucun lecteur n'existait dans
 * `app/`** — le moteur testait à la place un drapeau `necessite_maitrise_lourde`
 * codé en dur sur trois objets. Résultat : le magicien pouvait porter l'épée
 * large et la cotte de mailles, et rien ne distinguait les classes à l'achat.
 *
 * Le modèle est maintenant : chaque pièce porte UN tag de maîtrise, chaque
 * classe en autorise un ensemble de base, et les nœuds `acces_equipement` en
 * ajoutent. Le drapeau en dur disparaît au profit des tags `arme_deux_mains` /
 * `armure_lourde`, que le nœud barbare débloque — comportement identique pour
 * les quatre classes existantes, sauf le magicien, désormais bridé (canon
 * HeroQuest : dague et bâton, aucune armure).
 */
return new class extends Migration
{
    /**
     * Tag de maîtrise par objet. Le `deux_mains` mécanique (pas de bouclier)
     * reste INDÉPENDANT du tag : le Bâton des Sept Sceaux est à deux mains ET
     * `arme_legere`, donc jouable par le magicien à qui il est destiné.
     */
    private const TAGS = [
        'arme_legere' => ['Dague', 'Bâton', 'Bâton des Sept Sceaux'],
        'arme_courante' => ['Épée courte', 'Lance', 'Épée large', 'Kriss du Fossoyeur', "Lame d'Aube"],
        'arme_distance' => ['Arbalète', 'Arbalète des Murmures'],
        'arme_deux_mains' => ['Hache de bataille', 'Marteau du Gardien de Pierre',
            'Hache du Roi sous la Montagne', 'Fendoir des Titans'],
        'armure_legere' => ['Casque', 'Cotte de mailles'],
        'bouclier' => ['Bouclier'],
        'armure_lourde' => ['Armure de plates'],
    ];

    /** Tags autorisés SANS aucun nœud (profil « canon HeroQuest »). */
    private const BASE = [
        'barbare' => ['arme_legere', 'arme_courante', 'arme_distance', 'armure_legere', 'bouclier'],
        'nain' => ['arme_legere', 'arme_courante', 'arme_distance', 'armure_legere', 'bouclier'],
        'elfe' => ['arme_legere', 'arme_courante', 'arme_distance', 'armure_legere', 'bouclier'],
        // Le magicien du plateau ne porte NI armure NI arme de mêlée sérieuse.
        // Ses deux nœuds de déblocage lèvent chacune des deux limites.
        'magicien' => ['arme_legere'],
    ];

    public function up(): void
    {
        Schema::table('objets', function (Blueprint $table) {
            // Null = pièce sans exigence de maîtrise (outils, consommables,
            // parchemins) : toujours portable.
            $table->string('tag_equipement')->nullable()->after('emplacement');
        });

        Schema::table('classes_heros', function (Blueprint $table) {
            $table->json('tags_equipement')->nullable()->after('bonus_sac');
        });

        // Rétro-remplissage : les catalogues sont des données de référence,
        // re-semées par ailleurs, mais une base vivante ne doit pas attendre le
        // prochain `db:seed` pour appliquer les restrictions.
        foreach (self::TAGS as $tag => $noms) {
            \DB::table('objets')->whereIn('nom', $noms)->update(['tag_equipement' => $tag]);
        }

        foreach (self::BASE as $classe => $tags) {
            \DB::table('classes_heros')->where('nom', $classe)->update(['tags_equipement' => json_encode($tags)]);
        }
    }

    public function down(): void
    {
        Schema::table('objets', fn (Blueprint $table) => $table->dropColumn('tag_equipement'));
        Schema::table('classes_heros', fn (Blueprint $table) => $table->dropColumn('tags_equipement'));
    }
};
