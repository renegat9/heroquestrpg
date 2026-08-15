<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GroupeController;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\Equipement;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ObjetSeeder;
use Illuminate\Validation\ValidationException;

/**
 * Conformité de l'armurerie au JEU DE PLATEAU (reference/16_armurerie.md,
 * extrait sourcé des livrets Avalon Hill 2021).
 *
 * Deux ensembles, comme pour les clés d'effet : ce que la source ATTESTE (et
 * qui doit tenir), et ce dont on sait qu'on DIVERGE (et qui doit rester la
 * liste exacte qu'on a acceptée). Ajouter une arme au seeder casse donc le
 * test tant qu'on n'a pas tranché : sourcée, ou divergence assumée.
 *
 * ⚠ Deux NIVEAUX DE SOURCE, à ne pas mélanger (doc 16 §2) : les **livrets**
 * (§2.1) ne donnent aucun prix et presque aucun nombre de dés ; ces valeurs
 * vivent sur les **cartes équipement** (§2.2), un composant cartonné absent des
 * PDF. Le premier test ci-dessous ne teste que le niveau « livret » ; le test
 * de conversion (« convertit les cartes… ») fige le niveau « carte », qui n'est
 * opposable qu'au paquet lui-même.
 */
// ClasseHerosSeeder AUSSI : c'est lui qui porte `tags_equipement`, et le
// contrôle d'accès est « fail open » quand une classe n'en déclare aucun (une
// donnée de référence manquante ne doit jamais rendre un héros injouable). Sans
// ce seeder, les tests de restriction passeraient donc sans rien vérifier.
beforeEach(function () {
    $this->seed([ObjetSeeder::class, ClasseHerosSeeder::class]);
});

/** Pose une pièce du catalogue dans le sac du héros, prête à être équipée. */
function auSac(int $personnageId, string $nom): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $personnageId,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
}

/** Effet d'un objet du catalogue, par son nom. */
function effetDe(string $nom): array
{
    return (array) Objet::where('nom', $nom)->firstOrFail()->effet;
}

it('respecte les effets ATTESTÉS par les livrets', function () {
    // Dague : 1 dé, déduit de la carte magicien (LR p. 6).
    expect(effetDe('Dague')['des_attaque'])->toBe(1);

    // Bâton ET épée longue : le livret nomme EXACTEMENT ces deux-là — « Some
    // long weapons, like the staff and the longsword, allow you to attack
    // diagonally » (LR p. 14). Les deux doivent porter la diagonale…
    expect(effetDe('Bâton')['attaque_diagonale'])->toBeTrue()
        ->and(effetDe('Épée longue')['attaque_diagonale'])->toBeTrue();

    // …et rien d'autre ne doit se l'attribuer sans source. La hache de bataille
    // la portait : elle devenait la meilleure arme du jeu sur les deux axes.
    expect(effetDe('Hache de bataille')['attaque_diagonale'] ?? false)->toBeFalse();

    // Épée large = Broadsword, « the most powerful starting weapon » (LR p. 13)
    // — donc au-dessus de l'épée courte, seule comparaison que le texte permet.
    expect(effetDe('Épée large')['des_attaque'])
        ->toBeGreaterThan(effetDe('Épée courte')['des_attaque']);

    // …et elle n'est PAS citée parmi les armes longues à diagonale (LR p. 14) :
    // le diagramme lui oppose justement le bâton.
    expect(effetDe('Épée large')['attaque_diagonale'] ?? false)->toBeFalse();

    // Hache de bataille : « both hands », donc pas de bouclier avec.
    expect(effetDe('Hache de bataille')['deux_mains'])->toBeTrue();

    // Arbalète : « daggers and crossbows [...] hit a monster from a distance ».
    expect(effetDe('Arbalète')['portee'])->toBe('distance');

    // Armure de plates : +2 dés de défense (valeur de Borin's Armor, LR p. 7),
    // et elle RALENTIT son porteur — « unlike normal plate mail, this [...]
    // does not slow down its wearer » dit en creux que la normale, si.
    expect(effetDe('Armure de plates')['des_defense'])->toBe(2)
        ->and(effetDe('Armure de plates')['malus_deplacement'])->toBeGreaterThan(0);

    // Trousse à outils : permet le désamorçage (LR p. 19).
    expect(effetDe('Trousse à outils')['permet_desamorcage'])->toBeTrue();
});

it('donne aux héros l\'arme de départ des livrets', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();

    // « The shortsword is the starting weapon of the dwarf and the elf »,
    // « the broadsword is the most powerful starting weapon » (barbare),
    // dague pour le magicien (LR p. 6, p. 13).
    $attendu = [
        'barbare' => 'Épée large',
        'nain' => 'Épée courte',   // donnait une Hachette, arme inexistante au plateau
        'elfe' => 'Épée courte',
        'magicien' => 'Dague',
    ];

    $reflet = new ReflectionClass(GroupeController::class);
    $depart = $reflet->getConstant('EQUIPEMENT_DEPART');

    foreach ($attendu as $classe => $arme) {
        expect($depart[$classe][0] ?? null)->toBe($arme, "arme de départ du {$classe}");
    }
});

it('ne compte plus AUCUNE pièce hors source', function () {
    // C'était le gain principal du passage au paquet officiel (2026-08-15), et
    // ce test l'énonce : la liste `$horsSource` était longue de 17 noms — la
    // canne, les arcs, le fouet, l'espadon… — que le paquet FAN ajoutait
    // librement et qu'aucun livret ni aucune carte Hasbro ne nomme. Elle est
    // désormais VIDE, et doit le rester.
    //
    // Deux niveaux de source, désormais réunis : les livrets (§2.1) et les
    // photos du matériel officiel (§2.1bis). Une pièce qui apparaîtrait ici
    // sans figurer dans l'un des deux est une invention.
    $sources = [
        // Attestés par leur nom dans les livrets ET par une carte.
        'Dague', 'Bâton', 'Épée courte', 'Épée large', 'Épée longue', 'Arbalète',
        'Bouclier', 'Casque', 'Armure de plates', 'Trousse à outils',
        // Attestés par une carte officielle seulement — ce qui suffit : les
        // livrets ne donnent ni prix ni dés, la carte donne les deux.
        'Hachette', 'Hache de bataille', 'Cotte de mailles', 'Rapière', 'Brassards',
        'Baguette', 'Bandoulière', 'Chausse-trappes', 'Bombe fumigène', 'Eau bénite',
    ];

    $catalogue = Objet::whereIn('categorie', ['arme', 'armure', 'outil'])
        ->where('rarete', '!=', 'unique')   // les artefacts sont un autre débat (§10)
        ->pluck('nom')->all();

    $inconnus = array_values(array_diff($catalogue, $sources));

    expect($inconnus)->toBe([], 'pièce sans carte ni livret : '.implode(', ', $inconnus));

    // Et l'inverse : une pièce attestée ne doit pas disparaître du catalogue.
    expect(array_values(array_diff($sources, $catalogue)))->toBe([]);
});

it('convertit les cartes équipement en prix et en dés', function () {
    // Niveau de source « CARTE OFFICIELLE » (reference/16_armurerie.md
    // §2.1bis) : les 20 photos du paquet Hasbro, carte par carte. Ces valeurs
    // sont figées ici pour que la conversion soit opposable aux photos — sans
    // ça personne ne peut relire un prix, et le catalogue avait déjà dérivé
    // deux fois (épée large à 350, soit le prix de deux armes supérieures ;
    // bâton à 2 dés quand sa carte en donne 1).
    //
    // [prix, dés d'attaque, dés de défense]
    $cartes = [
        'Dague' => [25, 1, 0],
        'Bâton' => [100, 1, 0],
        'Baguette' => [125, 2, 0],
        'Épée courte' => [150, 2, 0],
        'Hachette' => [200, 2, 0],
        'Rapière' => [250, 2, 0],
        'Épée large' => [250, 3, 0],
        'Épée longue' => [350, 3, 0],
        'Arbalète' => [350, 3, 0],
        'Hache de bataille' => [450, 4, 0],
        'Casque' => [125, 0, 1],
        'Bouclier' => [150, 0, 1],
        'Cotte de mailles' => [500, 0, 1],
        'Brassards' => [550, 0, 1],
        'Armure de plates' => [850, 0, 2],
    ];

    // Le matériel n'a ni dés ni défense : on ne vérifie que son prix.
    $materiel = ['Chausse-trappes' => 100, 'Bombe fumigène' => 100,
        'Trousse à outils' => 250, 'Bandoulière' => 300, 'Eau bénite' => 400];

    foreach ($materiel as $nom => $prix) {
        expect((int) Objet::where('nom', $nom)->firstOrFail()->prix_base)->toBe($prix, "{$nom} : prix");
    }

    foreach ($cartes as $nom => [$prix, $attaque, $defense]) {
        $piece = Objet::where('nom', $nom)->firstOrFail();
        $effet = (array) $piece->effet;

        expect((int) $piece->prix_base)->toBe($prix, "{$nom} : prix")
            ->and((int) ($effet['des_attaque'] ?? 0))->toBe($attaque, "{$nom} : dés d'attaque")
            ->and((int) ($effet['des_defense'] ?? 0))->toBe($defense, "{$nom} : dés de défense");
    }
});

it('cumule casque + armure de corps + bouclier jusqu\'aux 6 dés du plateau', function () {
    // « [Borin's Armor] may be combined with the helmet and/or shield »
    // (LR p. 7). Tant que casque et cotte partageaient le slot `armure`, on
    // plafonnait à 5 et le casque n'était qu'un achat qu'on jetait ensuite.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $nain = creerHeros($alice, $groupe, 'Borin', 1, ['classe' => 'nain']); // seul à porter la plate d'emblée

    $equipement = app(Equipement::class);

    foreach (['Casque', 'Armure de plates', 'Bouclier'] as $nom) {
        $equipement->equiper($nain, auSac($nain->id, $nom));
    }

    // 2 (base commune aux quatre classes, LR p. 21) + 1 + 2 + 1.
    expect((int) $nain->fresh()->des_defense)->toBe(6);

    // …et chaque pièce est bien dans SON emplacement : un casque rangé dans
    // `armure` interdirait la cotte, ce qui était tout le problème.
    $portes = $nain->fresh()->inventaire()->with('objet')->get()
        ->mapWithKeys(fn ($l) => [$l->emplacement => $l->objet->nom]);

    expect($portes['casque'] ?? null)->toBe('Casque')
        ->and($portes['armure'] ?? null)->toBe('Armure de plates')
        ->and($portes['arme_secondaire'] ?? null)->toBe('Bouclier');
});

it('ouvre les Brassards à TOUT LE MONDE, magicien compris', function () {
    // Renversement du 2026-08-15, dicté par la carte officielle : « These
    // hardened leather bracers give you 1 extra Defend die. May be combined
    // with the helmet and/or shield. » — pas un mot sur la classe.
    //
    // Nous en faisions la pièce RÉSERVÉE au magicien (`armure_magicien`, repris
    // du paquet fan) : c'était le seul équipement défensif qu'il pouvait
    // porter, et personne d'autre n'y touchait. Les deux moitiés de cette règle
    // étaient fausses. Le magicien les garde — rien ne l'en exclut — mais le
    // barbare aussi.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);
    $barbare = creerHeros($alice, $groupe, 'Albrecht', 2, ['classe' => 'barbare']);

    $equipement = app(Equipement::class);

    $equipement->equiper($magicien, auSac($magicien->id, 'Brassards'));
    expect((int) $magicien->fresh()->des_defense)->toBe(3);

    $equipement->equiper($barbare, auSac($barbare->id, 'Brassards'));
    expect((int) $barbare->fresh()->des_defense)->toBe(3);

    // La symétrie tient toujours : l'armure ORDINAIRE reste fermée au magicien,
    // qui n'a `armure_legere` dans aucune de ses maîtrises.
    expect(fn () => $equipement->equiper($magicien, auSac($magicien->id, 'Cotte de mailles')))
        ->toThrow(ValidationException::class);
});

it('recense les mécaniques que le plateau n\'atteste pas', function () {
    // Ni l'une ni l'autre n'apparaît dans les livrets (reference/16 §10) :
    //  - la dague officielle est groupée avec l'arbalète comme arme à distance,
    //    et rien ne dit qu'elle se perd — le parallèle va même contre ;
    //  - « inutilisable au contact » n'est écrit nulle part pour l'arbalète.
    // On les conserve (choix de jeu), mais elles sont NOMMÉES ici pour qu'un
    // relecteur sache que ce ne sont pas des lectures du livret.
    expect(effetDe('Dague')['jetable'] ?? false)->toBeTrue()
        ->and(effetDe('Arbalète')['inutilisable_adjacent'] ?? false)->toBeTrue();
});
