<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\SortDreadSeeder;

/*
 * LE JEU DE BASE (F2847, décision de René du 2026-10-31 : « Enlève nos créations et mets ceux du jeu de base »).
 *
 * Quatre méchants nommés du livret de quêtes de la boîte de base, à la place des six créations retirées :
 * Ulag (quête 3, PDF 5), Grak (quête 6, PDF 8), Balur (quête 8, PDF 10), le Seigneur Sorcier de Barak Tor
 * (quête 14, PDF 16). Ici : la campagne « jeu de base seul » (mode manuel, aucune boîte cochée) ferme sur le
 * Seigneur Sorcier de Barak Tor, et son jalon sous-boss ne tire que Ulag, Grak ou Balur. Les stats et les
 * écarts nommés sont figés dans `BestiaireSourceTest`.
 */

beforeEach(function () {
    $this->seed([SortDreadSeeder::class, MonstreSeeder::class, GabaritQueteSeeder::class]);
});

/**
 * La rencontre finale d'un gabarit, achetée comme le fait `DemarreurQuete` : même méthode, bestiaire explicite.
 *
 * @return list<Monstre>
 */
function achatsDuJeuDeBase(string $typeJalon, int $budget, int $positionArc, int $graine): array
{
    $gabarit = GabaritQuete::where('type_jalon', $typeJalon)->firstOrFail();
    $structure = ['rencontre_finale' => (array) data_get($gabarit->structure, 'rencontre_finale')];

    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    return $methode->invoke($demarreur, $structure, $budget, 20, $positionArc, $graine, BestiaireGroupe::manuel([]));
}

it('le jeu de base seul (mode manuel, aucune boîte cochée) ferme sur le Seigneur Sorcier de Barak Tor, à chaque position d\'arc et pour chaque groupe', function () {
    foreach ([1, 2, 5] as $arc) {
        foreach ([1, 2, 3, 7] as $graine) {
            $achats = achatsDuJeuDeBase('boss_final', 40, $arc, $graine);

            expect($achats)->not->toBeEmpty()
                ->and($achats[0]->nom_base)->toBe('Seigneur Sorcier de Barak Tor');
        }
    }
});

it('le jeu de base seul : le jalon sous-boss ne tire que Ulag, Grak ou Balur — jamais une créature d\'une autre boîte', function () {
    foreach ([1, 2, 3, 4, 5] as $arc) {
        foreach ([1, 2, 3, 7] as $graine) {
            $achats = achatsDuJeuDeBase('sous_boss', 30, $arc, $graine);

            expect($achats)->not->toBeEmpty()
                ->and(['Ulag', 'Grak', 'Balur'])->toContain($achats[0]->nom_base);
        }
    }
});

it('les méchants du jeu de base portent leur répertoire et leur immunité tels que le livret les écrit', function () {
    // Balur : « which he may use once during the quest » = sorts_uniques ; six sorts, « Fire spells do not affect him ».
    $balur = Monstre::where('nom_base', 'Balur')->firstOrFail();
    expect((array) $balur->capacites)->toHaveKey('immunite_sorts_elements')
        ->and(in_array('sorts_uniques', (array) $balur->capacites, true))->toBeTrue()
        ->and((array) config('archetypes_lanceurs.balur_mage_du_feu.sorts'))->toHaveCount(6)
        ->and($balur->archetype_lanceur)->toBe('balur_mage_du_feu');

    // Grak : trois sorts, un par tour (le budget de son palier borne le reste, divergence nommée).
    expect((array) config('archetypes_lanceurs.grak.sorts'))->toBe(['Frayeur', 'Sommeil', 'Tourmente']);

    // Le Seigneur Sorcier de Barak Tor : cinq sorts, et AUCUNE immunité écrite (écart nommé, sans la Lame des Esprits).
    $witch = Monstre::where('nom_base', 'Seigneur Sorcier de Barak Tor')->firstOrFail();
    expect((array) config('archetypes_lanceurs.seigneur_sorcier_barak_tor.sorts'))->toHaveCount(5)
        ->and((array) $witch->capacites)->toBe([])
        ->and($witch->tier)->toBe('boss');
});

it('ne porte plus nos six créations, ni dans le catalogue ni dans les registres (René, 2026-10-31)', function () {
    $nos = ['Champion', 'Seigneur', 'Chamane Gobelin', 'Liche', 'Sorcier des Tempêtes', 'Troll'];

    $archetypesRetires = ['seigneur_du_chaos', 'necromancien', 'maitre_tempetes', 'chaman_orque'];

    expect(Monstre::whereIn('nom_base', $nos)->count())->toBe(0)
        ->and(array_values(array_intersect(array_keys((array) config('barks.archetypes')), $nos)))->toBe([])
        ->and(array_values(array_intersect(array_keys((array) config('symboles_monstres.absents')), $nos)))->toBe([])
        ->and(array_values(array_intersect(array_keys((array) config('archetypes_lanceurs')), $archetypesRetires)))->toBe([]);
});

it('la migration de retrait supprime nos créations SANS instance, et garde celle qu\'une campagne référence (René, 2026-10-31)', function () {
    $migration = require database_path('migrations/2026_10_31_110000_retirer_createurs_du_jeu_de_base.php');

    // Deux créations rejouées dans l'ancien catalogue : une libre (le Troll), une référencée par une instance (la Liche).
    $libre = Monstre::where('nom_base', 'Garde-mage')->firstOrFail()->replicate();
    $libre->nom_base = 'Troll';
    $libre->save();

    $referencee = Monstre::where('nom_base', 'Garde-mage')->firstOrFail()->replicate();
    $referencee->nom_base = 'Liche';
    $referencee->save();

    $groupe = creerGroupe();
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — retrait des créations',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);
    InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => $referencee->id,
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 0,
        'position_x' => 3, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    $migration->up();

    expect(Monstre::where('nom_base', 'Troll')->exists())->toBeFalse()
        ->and(Monstre::where('nom_base', 'Liche')->exists())->toBeTrue();
});
