<?php

declare(strict_types=1);

use App\Engine\MotsClesEpreuve;
use App\Models\Carte;
use App\Models\Epreuve;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Quete;
use App\Partie\MoteurEpreuves;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;

/*
 * Verrou « aucune épreuve décorative », sur le modèle de `GrilleTalentsTest` :
 * le catalogue `epreuves` et le vocabulaire `MotsClesEpreuve` sont vérifiés
 * l'un CONTRE l'autre, dans les deux sens.
 *
 * ⚠ Ce fichier ne teste PAS que les lecteurs (`ResolveurTour::resoudreEpreuve()`,
 * `MoteurPieges::desarmerSalle()`, `AssembleurCarte::placerEpreuves()`)
 * existent : ce lot ne pose que le catalogue, le vocabulaire et le lecteur de
 * couche de carte — l'intégration carte/menu/résolveur est un autre lot, et
 * c'est LUI qui apportera le test « la méthode existe et nomme la clé »
 * (même esprit que `GrilleTalentsTest::'confronte chaque lecteur DÉCLARÉ…'`).
 */

beforeEach(function () {
    // GabaritQueteSeeder n'est là que pour donner un `gabarit_id` valide à la
    // quête bricolée par `carteAvecEpreuves()` (FK réelle, y compris sous
    // sqlite — `foreign_key_constraints` vaut true par défaut) : aucun test
    // ci-dessous n'assemble une vraie carte procédurale.
    $this->seed([EpreuveSeeder::class, GabaritQueteSeeder::class]);
});

/**
 * Quête + carte minimale, construite À LA MAIN (pas d'assemblage procédural)
 * — juste ce qu'il faut pour donner un `Carte` valide (FK `quete_id`) à
 * `MoteurEpreuves`. Même esprit que `CouloirsTest::groupeAvecCarteMobilier()`,
 * mais gardé LOCAL à ce fichier : le premier passe de vérification (§
 * "Vérification" du lot) exécute ce fichier SEUL, sans que les helpers
 * globaux des autres fichiers de test soient chargés.
 *
 * @param  list<array<string, mixed>>  $epreuvesPosees  couche `grille.epreuves`
 * @return array{0: Groupe, 1: Quete, 2: Carte}
 */
function carteAvecEpreuves(array $epreuvesPosees): array
{
    $groupe = Groupe::create([
        'identifiant' => 'table-epreuves',
        'nom' => 'Les Lames du Crépuscule',
        'theme' => 'Cryptes maudites sous la cité',
        'longueur' => 'courte',
        'nb_quetes_total' => 1,
        'phase' => 'hub',
    ]);

    $gabarit = GabaritQuete::query()->firstOrFail();

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    $carte = Carte::create([
        'quete_id' => $quete->id,
        'largeur' => 5,
        'hauteur' => 5,
        'grille' => [
            'largeur' => 5,
            'hauteur' => 5,
            'cases' => array_fill(0, 5, array_fill(0, 5, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 5, 'hauteur' => 5, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [],
            'leviers' => [],
            'pieges' => [],
            'mobilier' => [],
            'epreuves' => $epreuvesPosees,
            'spawn_heros' => [['x' => 0, 'y' => 0]],
            'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    return [$groupe, $quete, $carte];
}

it('sème les 7 épreuves, chacune avec une description JOUEUR lisible', function () {
    $epreuves = Epreuve::all();

    expect($epreuves)->toHaveCount(7);

    foreach ($epreuves as $epreuve) {
        expect(mb_strlen((string) $epreuve->description))->toBeGreaterThanOrEqual(
            25, "{$epreuve->nom} : description trop courte pour dire ce qu'on voit et ce qu'on tente.",
        );

        expect(in_array($epreuve->attribut, ['body', 'mind'], true))->toBeTrue(
            "{$epreuve->nom} : attribut « {$epreuve->attribut} » hors du vocabulaire body/mind.",
        );

        expect($epreuve->difficulte)->toBeGreaterThanOrEqual(1)
            ->and($epreuve->difficulte)->toBeLessThanOrEqual(4);
    }
});

it('n\'emploie AUCUNE mécanique d\'effet sans lecteur déclaré — et n\'en déclare aucune que personne ne porte', function () {
    $portees = [];

    foreach (Epreuve::all() as $epreuve) {
        $mecanique = $epreuve->effet['mecanique'] ?? null;

        expect(MotsClesEpreuve::connue($mecanique))
            ->toBeTrue("{$epreuve->nom} : mécanique « {$mecanique} » sans lecteur déclaré.");

        $portees[$mecanique] = true;
    }

    // ⚠ Le sens qui compte vraiment : un registre plus riche que le catalogue
    // qui le porte est une clé décorative — exactement ce que ce test existe
    // pour interdire.
    foreach (array_keys(MotsClesEpreuve::MECANIQUES) as $declaree) {
        expect(array_key_exists($declaree, $portees))
            ->toBeTrue("« {$declaree} » est déclarée dans MotsClesEpreuve::MECANIQUES, mais aucune épreuve ne la porte.");
    }
});

it('n\'emploie AUCUNE précondition de pose hors du vocabulaire `PLACEMENTS`', function () {
    foreach (Epreuve::all() as $epreuve) {
        if ($epreuve->exige_placement === null) {
            continue;
        }

        expect(array_key_exists($epreuve->exige_placement, MotsClesEpreuve::PLACEMENTS))
            ->toBeTrue("{$epreuve->nom} : précondition de pose « {$epreuve->exige_placement} » inconnue de MotsClesEpreuve::PLACEMENTS.");
    }

    // Au moins une épreuve exerce réellement la précondition — sinon le
    // vocabulaire lui-même serait décoratif.
    expect(Epreuve::whereNotNull('exige_placement')->count())->toBeGreaterThan(0);
});

it('couvre les trois contextes de jet de Mind et les deux attributs', function () {
    $contextes = Epreuve::whereNotNull('contexte')->pluck('contexte')->unique()->sort()->values()->all();

    expect($contextes)->toBe(['perception', 'savoir', 'social_peur']);

    $attributs = Epreuve::pluck('attribut')->unique()->sort()->values()->all();

    expect($attributs)->toBe(['body', 'mind']);
});

it('rend un libellé JOUEUR non vide pour chaque effet seedé', function () {
    foreach (Epreuve::all() as $epreuve) {
        expect(MotsClesEpreuve::libelle($epreuve->effet))
            ->not->toBe('', "{$epreuve->nom} : aucun libellé affichable pour son effet.");
    }
});

it('MoteurEpreuves::adjacentes() rend une épreuve SUR la case et sur ses voisines orthogonales, jamais en diagonale', function () {
    $fresque = Epreuve::where('nom', 'Fresque en langue morte')->firstOrFail();

    // Une seule épreuve posée en (2, 2) — largement isolée sur la grille 5×5
    // pour que rien d'autre n'interfère avec le calcul de distance.
    [, , $carte] = carteAvecEpreuves([
        ['x' => 2, 'y' => 2, 'epreuve_id' => $fresque->id, 'salle' => 0, 'tentee_par' => []],
    ]);

    $moteur = app(MoteurEpreuves::class);

    // Sur la case elle-même : une épreuve ne bloque pas le passage, on peut
    // s'y arrêter, donc elle doit rester tentable.
    expect(collect($moteur->adjacentes($carte, 2, 2, 5))->pluck('nom')->all())->toBe(['Fresque en langue morte']);

    // Voisine orthogonale (est) : (3, 2).
    expect(collect($moteur->adjacentes($carte, 3, 2, 5))->pluck('nom')->all())->toBe(['Fresque en langue morte']);

    // Voisine orthogonale (sud) : (2, 3).
    expect(collect($moteur->adjacentes($carte, 2, 3, 5))->pluck('nom')->all())->toBe(['Fresque en langue morte']);

    // ⚠ La DIAGONALE (3, 3) n'est PAS orthogonalement adjacente : distance de
    // Manhattan 2, elle ne doit rien rendre.
    expect($moteur->adjacentes($carte, 3, 3, 5))->toBeEmpty();
});

it('MoteurEpreuves::adjacentes() ne rend plus une épreuve déjà tentée par CE héros, et la rend encore à un autre', function () {
    $dalle = Epreuve::where('nom', 'Dalle descellée')->firstOrFail();

    // Le héros 7 a déjà tenté cette épreuve (posée directement avec son id
    // dans `tentee_par`, comme le ferait `marquerTentee()`).
    [, , $carte] = carteAvecEpreuves([
        ['x' => 4, 'y' => 4, 'epreuve_id' => $dalle->id, 'salle' => 0, 'tentee_par' => [7]],
    ]);

    $moteur = app(MoteurEpreuves::class);

    expect($moteur->adjacentes($carte, 4, 4, 7))->toBeEmpty(
        'la dalle, déjà tentée par le héros 7, lui est encore proposée.',
    );

    // ...mais reste ouverte à un compagnon qui ne l'a pas encore tentée : le
    // coût réel est le créneau d'action de CHACUN, pas l'ancrage lui-même.
    $pourHeros9 = $moteur->adjacentes($carte, 4, 4, 9);
    expect(collect($pourHeros9)->pluck('nom')->all())->toBe(['Dalle descellée']);

    // La ligne enrichie porte de quoi être affichée sans jointure supplémentaire.
    $entree = $pourHeros9[0];
    expect($entree['attribut'])->toBe('body')
        ->and($entree['difficulte'])->toBe(2)
        ->and($entree['description'])->not->toBe('');
});

/**
 * Vérifie UNE entrée de vocabulaire (mécanique OU précondition de pose) :
 * le libellé joueur existe, la classe/méthode déclarée en `lecteur` existe
 * vraiment (réflexion), et AU MOINS UN des lecteurs déclarés nomme
 * littéralement la clé dans son propre fichier source.
 *
 * Factorisée pour être appliquée deux fois — sur `MECANIQUES` et sur
 * `PLACEMENTS` — sans dupliquer le corps : les deux vocabulaires ne
 * partagent pas d'espace de noms (aucune collision aujourd'hui), mais rien
 * ne garantit que ça dure, donc on les contrôle SÉPARÉMENT plutôt que de les
 * fusionner dans un seul tableau qui masquerait une collision future.
 *
 * @param  array{lecteur: string|list<string>, libelle: string}  $entree
 */
function verifierLecteurEpreuve(string $cle, array $entree): void
{
    expect($entree['libelle'] ?? '')->not->toBe('', "« {$cle} » : libellé joueur manquant.");

    $nomme = false;

    foreach ((array) $entree['lecteur'] as $lecteur) {
        [$classe, $methode] = explode('::', str_replace('()', '', $lecteur));

        expect(class_exists($classe))->toBeTrue("« {$cle} » : lecteur {$classe} introuvable.");

        $reflexion = new ReflectionClass($classe);

        expect($reflexion->hasMethod($methode))
            ->toBeTrue("« {$cle} » : {$classe}::{$methode}() n'existe pas.");

        $nomme = $nomme
            || str_contains((string) file_get_contents((string) $reflexion->getFileName()), $cle);
    }

    // ⚠ L'assertion qui compte, sur le modèle de
    // `GrilleTalentsTest::'confronte chaque lecteur DÉCLARÉ…'` : AU MOINS UN
    // des lecteurs déclarés doit nommer la clé, pas TOUS. Certains sites
    // APPLIQUENT la règle sans la nommer — un lecteur qui reçoit déjà un
    // booléen (ou un index de salle) n'a aucune raison de reciter la chaîne
    // du vocabulaire. Exiger la présence partout aurait poussé à inliner des
    // clés là où elles n'ont rien à faire.
    //
    // ⚠ `toContain()` prend des AIGUILLES, jamais un message : passer
    // l'explication en second argument en fait une seconde aiguille et
    // l'assertion échoue TOUJOURS — même piège que `toHaveKey`. D'où
    // `toBeTrue()` sur un `str_contains()` déjà résolu.
    expect($nomme)->toBeTrue("« {$cle} » : aucun lecteur déclaré ne nomme cette clé dans son fichier.");
}

it('confronte chaque lecteur DÉCLARÉ — mécanique ET précondition de pose — à la réalité : la méthode existe, et son fichier nomme la clé', function () {
    foreach (MotsClesEpreuve::MECANIQUES as $mecanique => $entree) {
        verifierLecteurEpreuve($mecanique, $entree);
    }

    foreach (MotsClesEpreuve::PLACEMENTS as $placement => $entree) {
        verifierLecteurEpreuve($placement, $entree);
    }
});

it('MoteurEpreuves::marquerTentee() empile l\'id du héros SANS écraser les tentatives déjà posées', function () {
    $fresque = Epreuve::where('nom', 'Fresque en langue morte')->firstOrFail();

    [, , $carte] = carteAvecEpreuves([
        ['x' => 1, 'y' => 1, 'epreuve_id' => $fresque->id, 'salle' => 0, 'tentee_par' => [3]],
    ]);

    $moteur = app(MoteurEpreuves::class);
    $moteur->marquerTentee($carte, 0, 4);

    $tentants = $carte->fresh()->grille['epreuves'][0]['tentee_par'];
    expect($tentants)->toBe([3, 4]);

    expect(MoteurEpreuves::dejaTentee($carte->grille['epreuves'][0], 3))->toBeTrue();
    expect(MoteurEpreuves::dejaTentee($carte->grille['epreuves'][0], 4))->toBeTrue();
    expect(MoteurEpreuves::dejaTentee($carte->grille['epreuves'][0], 5))->toBeFalse();
});
