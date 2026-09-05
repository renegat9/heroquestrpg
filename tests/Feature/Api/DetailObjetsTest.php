<?php

declare(strict_types=1);

use App\Engine\MotsClesEquipement as K;
use App\Models\Inventaire;
use App\Models\Objet;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;

/**
 * LE DÉTAIL D'UNE PIÈCE, en clair (René, 2026-09-04 : « il faudrait pouvoir
 * voir le détail des items »).
 *
 * ⚠ Un objet n'a PAS de description écrite à la main : son `effet` est la seule
 * source de vérité sur ce qu'il fait. `MotsClesEquipement::avantages()` le
 * TRADUIT — comme `MotsClesTalent::avantage()` le fait pour un nœud d'arbre —
 * plutôt que de laisser saisir une phrase qui pourrait contredire la mécanique.
 *
 * ⚠ Et le vocabulaire vit CÔTÉ SERVEUR. `store/game.js` tenait autrefois sa
 * propre table pour les talents, keyée sur des noms de colonne : un `effet` de
 * compétence ne produisait aucune puce, tous les talents s'affichaient sans un
 * chiffre, et personne ne l'avait remarqué. Ce fichier est le garde-fou.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, SortSeeder::class, ObjetSeeder::class]);
});

it('traduit ou tait CHAQUE clé d\'effet du catalogue, sans exception', function () {
    // ⚠ Le verrou qui empêche la table de prendre du retard sur le catalogue :
    // une clé ajoutée demain doit être soit traduite, soit déclarée muette avec
    // sa raison. Sans lui, elle disparaîtrait de la fiche en silence — le mode
    // de panne exact des talents.
    $orphelines = [];

    foreach (Objet::all() as $objet) {
        foreach (array_keys((array) $objet->effet) as $cle) {
            if (! isset(K::LIBELLES[$cle]) && ! isset(K::MUETTES[$cle])) {
                $orphelines[$cle] = true;
            }
        }
    }

    expect(array_keys($orphelines))->toBe([],
        'Clés ni traduites ni déclarées muettes : '.implode(', ', array_keys($orphelines)));
});

it('donne au moins une phrase lisible à CHAQUE objet', function () {
    // Un objet dont on ne peut rien dire est un objet que le joueur ramasse
    // sans savoir pourquoi. Le catalogue interdit déjà l'`effet` vide
    // (GuideTest) ; on interdit ici l'effet INTRADUISIBLE.
    $muets = Objet::all()
        ->filter(fn (Objet $o) => K::avantages((array) $o->effet) === [])
        ->pluck('nom')->all();

    expect($muets)->toBe([], 'Objets sans aucune phrase lisible : '.implode(', ', $muets));
});

it('accorde les pluriels et écrit les énumérations en français', function () {
    // « 3 dé(s) d'attaque » et « une fois par quete » se lisent mal à la table :
    // la marque `(s)` porte l'accord, et les valeurs d'énumération ont leur
    // table plutôt qu'un `str_replace('_', ' ')` qui perd les accents.
    expect(K::avantages(['des_attaque' => 3]))->toBe(["3 dés d'attaque"])
        ->and(K::avantages(['des_attaque' => 1]))->toBe(["1 dé d'attaque"])
        ->and(K::avantages(['frequence' => 'une_fois_par_quete']))->toBe(['Cadence : une fois par quête']);

    // Une clé muette ne produit aucune phrase — et n'en fait pas rater d'autres.
    expect(K::avantages(['sort_nom' => 'Boule de Feu', 'soin_pv_body' => 2]))
        ->toBe(['Rend 2 PV de Body']);
});

it('publie le détail dans /moi, pour le sac ET pour ce qui est porté', function () {
    // ⚠ Les deux, et pas seulement le sac : on doit pouvoir relire ce qu'on
    // porte autant que ce qu'on transporte — c'est même l'équipement porté qui
    // décide des dés.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $epee = Objet::where('nom', 'Épée large')->firstOrFail();
    $potion = Objet::where('nom', 'Potion de soin')->firstOrFail();

    Inventaire::create(['personnage_id' => $heros->id, 'objet_id' => $epee->id,
        'quantite' => 1, 'emplacement' => 'arme_principale']);
    Inventaire::create(['personnage_id' => $heros->id, 'objet_id' => $potion->id,
        'quantite' => 1, 'emplacement' => 'sac']);

    $perso = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))
        ->firstWhere('id', $heros->id);

    expect($perso)->not->toBeNull();

    $arme = collect($perso['equipement']['armes'])->firstWhere('nom', 'Épée large');
    expect($arme['avantages'])->toContain("3 dés d'attaque")
        ->and($arme['avantages'])->toContain('Frappe en diagonale');

    $sac = collect($perso['equipement']['sac'])->firstWhere('nom', 'Potion de soin');
    expect($sac['avantages'])->not->toBeEmpty();
});
