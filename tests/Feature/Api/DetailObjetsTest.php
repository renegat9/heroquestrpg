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

it('nomme les créatures visées par un bonus de dés, au lieu de « certaines créatures »', function () {
    // La Lame des Esprits porte `{des, noms}` : la seule valeur structurée du
    // vocabulaire. Le gabarit générique la réduisait à « contre certaines
    // créatures » — moins précis que la table recopiée du livret, retirée le
    // 2026-09-16 au profit de ce traducteur.
    $lame = Objet::where('nom', 'Lame des Esprits')->firstOrFail();

    expect(K::avantages((array) $lame->effet))
        ->toContain("4 dés d'attaque contre Squelette, Zombie, Momie")
        ->not->toContain("Dés d'attaque accrus contre certaines créatures");

    // Une potion se boit OU se tend : le libellé doit dire les deux, sinon le
    // lecteur croit qu'on ne peut pas la boire soi-même.
    expect(K::avantages(['cible' => 'heros_adjacent']))->toBe(['Cible : soi ou un héros adjacent']);

    // Mal formée, la valeur retombe sur le gabarit générique plutôt que sur rien.
    expect(K::avantages(['des_attaque_contre' => ['noms' => []]]))
        ->toBe(["Dés d'attaque accrus contre certaines créatures"]);
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

it('dit ce que disent les CARTES d\'artefact, pas une approximation', function () {
    // Confrontation aux cartes officielles (Drive, photos de René) le
    // 2026-09-16 : ces libellés étaient faux ou trop vagues alors que le moteur,
    // lui, suivait la carte. Chaque attente cite la carte qui la fonde.
    $texte = fn (string $nom) => implode(' · ', K::avantages((array) Objet::where('nom', $nom)->firstOrFail()->effet));

    // Rabbit Boots — « roll anything but a black shield on 1 combat die ».
    expect($texte('Bottes de Lièvre'))->toContain('échoue seulement sur un bouclier noir')
        ->not->toContain('sans jet');

    // Ring of Return — « returns all heroes that the ring wearer can see ».
    expect($texte('Anneau du Retour'))->toContain('tous les héros que le porteur voit')
        // et plus de « Cible : soi-même » qui contredisait l'effet
        ->not->toContain('Cible');

    // Bone Wand — « control all skeletons in one room for one turn ».
    expect($texte('Baguette d\'Os'))->toContain('chaque Squelette de la salle')
        ->not->toContain('Cible');

    // Raven's Talon — « reroll any 1 Attack die that lands on a black shield ».
    expect($texte('Serre du Corbeau'))->toContain('Relance 1 dé d\'attaque tombé sur un bouclier noir');

    // Phoenix Ash — « on a 5 or 6, this artifact is lost ».
    expect($texte('Cendres du Phénix'))->toContain('se consume sur 5 ou 6');

    // The Scales of Elethorn — « when you attempt to resist the effects of a
    // Dread spell […] roll an additional die ».
    expect($texte('Écailles d\'Elethorn'))->toContain('résister aux sorts du maître du donjon');

    // Arc de Vindication — arbitrage de René (2026-09-16), qui remplace la carte.
    expect($texte('Arc elfique de Vindication'))
        ->toContain('Chaque flèche inflige 3 PV, sauf si la cible tire un bouclier noir')
        ->toContain("4 utilisations, puis l'objet se brise")
        ->not->toContain("dés d'attaque");

    // « à volonté » ne précède plus une cadence qui le dément.
    expect($texte('Sceptre de Télékinésie'))->not->toContain('à volonté')
        ->toContain('Cadence : une fois par quête');
});
