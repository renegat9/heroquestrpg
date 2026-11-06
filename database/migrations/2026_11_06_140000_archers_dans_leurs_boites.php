<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les trois archers suivent les boîtes qui impriment leurs figurines (René, 2026-11-06 : « appliquer ces créatures
 * aux extensions où elles sont présentes »), au lieu d'être disponibles dans toute campagne (Q6, 2026-10-02).
 *
 * Against the Ogre Horde (contenu p. 3, légende p. 19) : Goblin, Orc et Skeleton Archers. Jungles of Delthrak
 * (contenu p. 2, légende p. 6) : Goblin et Skeleton Archers. Aucune autre boîte n'en contient. `boite` porte la
 * première, `boites_partagees` la seconde, comme l'Archer elfe. Même valeurs que `MonstreSeeder`.
 *
 * Effet : une campagne filtrée (manuel, choix du MJ, tirage) ne pose plus la variante à distance si aucune de ces
 * boîtes n'est retenue, et garde alors le monstre standard. La rotation historique (Fg, test) n'est pas un filtre et
 * les garde partout.
 */
return new class extends Migration
{
    private const BOITES = [
        'Gobelin archer' => ['horde_ogre', 'jungles_delthrak'],
        'Archer squelette' => ['horde_ogre', 'jungles_delthrak'],
        'Orque archer' => ['horde_ogre'],
    ];

    public function up(): void
    {
        foreach (self::BOITES as $nom => $boites) {
            $partagees = array_slice($boites, 1);
            DB::table('monstres')->where('nom_base', $nom)->update([
                'boite' => $boites[0],
                'boites_partagees' => $partagees === [] ? null : json_encode($partagees),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('monstres')->whereIn('nom_base', array_keys(self::BOITES))
            ->update(['boite' => null, 'boites_partagees' => null]);
    }
};
