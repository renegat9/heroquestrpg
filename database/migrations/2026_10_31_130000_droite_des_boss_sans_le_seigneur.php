<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LA DROITE DES BOSS SANS LE SEIGNEUR (René, 2026-10-31 : « Oui applique cette droite »).
 *
 * Le `cout` des boss se lisait sur une droite ancrée sur le Seigneur, créature retirée le même jour (migration
 * `2026_10_31_110000`). Elle est remplacée par la droite des trois repères qui ne l'ont jamais été : Ogre commandant (N 9,0 →
 * 15), Seigneur ogre (N 20,0 → 22), Gruzbella (N 21,25 → 23). Formule : cout = (13335 + 940 × N) / 1454, arrondi au plus proche
 * (`coutDroiteBoss()` dans tests/Pest.php, la seule source pour les tests). `docs/regles/bestiaire-et-rencontres.md`.
 *
 * Douze lignes changent, dix boss. Les trois repères et les boss déjà sur la droite (Dragon, Gruulob, Archimage, Artificière,
 * Gargouille gardienne, les deux Seigneur Sorcier) ne bougent pas, et le seeder écrit exactement ces valeurs.
 *
 * Seule la colonne `cout` bouge. Elle n'est lue qu'au PLACEMENT d'une rencontre (`DemarreurQuete`) : une instance vivante et
 * une quête en cours gardent leur budget, et aucune ligne n'est créée ni supprimée.
 */
return new class extends Migration
{
    /** cout après le chantier : ce que le seeder écrit. */
    private const COUTS = [
        'Sir Ragnar' => 13,
        'Horreur des Glaces' => 14,
        'Magrian' => 18,
        'Festral' => 12,
        'Venim le Dragon' => 13,
        'Gretzl la Porte-Fléau' => 19,
        'Demonspider' => 19,
        'Demonape' => 19,
        'Scion Fleur-Pourrie' => 21,
        'Qwindrak the Warlock' => 42,
        'Fellmarak le Roi Sorcier' => 14,
        'Nelath, la Reine des Esprits' => 14,
    ];

    /** cout avant le chantier, pour `down()`. */
    private const COUTS_AVANT = [
        'Sir Ragnar' => 14,
        'Horreur des Glaces' => 16,
        'Magrian' => 20,
        'Festral' => 11,
        'Venim le Dragon' => 16,
        'Gretzl la Porte-Fléau' => 18,
        'Demonspider' => 18,
        'Demonape' => 18,
        'Scion Fleur-Pourrie' => 22,
        'Qwindrak the Warlock' => 38,
        'Fellmarak le Roi Sorcier' => 16,
        'Nelath, la Reine des Esprits' => 16,
    ];

    public function up(): void
    {
        $this->fixerCouts(self::COUTS);
    }

    public function down(): void
    {
        $this->fixerCouts(self::COUTS_AVANT);
    }

    /**
     * Une base neuve (avant `db:seed`) n'a aucune de ces lignes : l'update ne touche rien, ce n'est pas une erreur.
     *
     * @param  array<string, int>  $couts
     */
    private function fixerCouts(array $couts): void
    {
        foreach ($couts as $nom => $cout) {
            DB::table('monstres')->where('nom_base', $nom)->update(['cout' => $cout]);
        }
    }
};
