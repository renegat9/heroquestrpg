<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Mobilier;
use App\Models\Quete;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\MenuMoteur;
use App\Partie\MoteurMobilier;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * FRACASSER UN OBSTACLE (2026-08-24) — deuxième des trois emplois donnés à
 * `attribut_body`, qui ne touchait jusque-là que deux situations rares (un
 * piège détecté au contact, une fosse sur le trajet) et laissait trois nœuds de
 * la grille de talents — *Colosse*, *Ancré*, *Corps aguerri* — achetables et
 * quasi sans effet.
 *
 * Voir `MoteurMobilier::destructiblesAdjacents()`, `ResolveurTour::fracasserMobilier()`
 * et la branche `parametres.mobilier` de `resoudreJet()`.
 *
 * ⚠ Même parti que `RepousserTest` : une quête MINIMALE (carte 7×7 tout en sol)
 * plutôt qu'un donjon procédural. Un donjon généré ne garantit ni qu'un meuble
 * se trouve au contact du héros, ni lequel — la suite deviendrait intermittente
 * au gré des graines, alors qu'on éprouve ici une mécanique, pas un placement.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/**
 * Quête minimale (7×7 tout en sol), un héros en (3,3), et UNE pièce de mobilier
 * posée en (4,3) — donc orthogonalement au contact.
 *
 * @return array{alice: JoueurAuthentifiable, groupe: \App\Models\Groupe, heros: \App\Models\Personnage, quete: Quete, etatHeros: EtatPersonnageQuete, type: Mobilier}
 */
function queteAvecMeuble(string $nomMeuble, array $herosAttrs = [], array $entreeSup = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);

    $type = Mobilier::where('nom', $nomMeuble)->firstOrFail();

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => 7,
        'hauteur' => 7,
        'grille' => [
            'largeur' => 7, 'hauteur' => 7,
            'cases' => array_fill(0, 7, array_fill(0, 7, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'epreuves' => [],
            'mobilier' => [[
                'mobilier_id' => $type->id,
                // Emprise 1×1 quel que soit le catalogue : ce fichier éprouve la
                // destruction, pas la géométrie des emprises (déjà couverte).
                'x' => 4, 'y' => 3, 'l' => 1, 'h' => 1, 'salle' => 0,
                ...$entreeSup,
            ]],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => 3, 'position_y' => 3,
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete->fresh()->load('carte'), 'etatHeros' => $etatHeros, 'type' => $type];
}

/** Les options de destruction réellement proposées à ce héros. */
function optionsDestruction(array $ctx, ?\App\Models\Personnage $heros = null, ?EtatPersonnageQuete $etat = null): array
{
    $methode = new ReflectionMethod(MenuMoteur::class, 'generer');
    $menu = $methode->invoke(app(MenuMoteur::class), $ctx['groupe']->fresh(), ($heros ?? $ctx['heros'])->fresh());

    return collect($menu['options'] ?? [])
        ->filter(fn (array $o) => str_starts_with((string) $o['id'], 'detruire_mobilier_'))
        ->values()
        ->all();
}

/** Fracasse le meuble d'index 0 avec des dés figés, et rend le payload. */
function fracasser(array $ctx, array $des, ?\App\Models\Personnage $heros = null, ?EtatPersonnageQuete $etat = null): array
{
    $heros ??= $ctx['heros'];
    $etat ??= $ctx['etatHeros'];

    desFiges($des);

    $option = [
        'id' => 'detruire_mobilier_0',
        'libelle' => 'Fracasser',
        'type' => 'jet',
        'jet' => ['attribut' => 'body', 'difficulte' => 1],
        'parametres' => ['mobilier' => 0, 'nom' => $ctx['type']->nom],
    ];

    // ⚠ `app()` APRÈS `desFiges()` : le lanceur est injecté à la construction,
    // et une instance obtenue plus tôt garderait le lanceur aléatoire.
    return (new ReflectionMethod(ResolveurTour::class, 'resoudreJet'))->invoke(
        app(ResolveurTour::class),
        $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $heros->fresh(), $etat->fresh(),
        $option, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom],
    );
}

// =====================================================================
// CE QUE LA DESTRUCTION CHANGE SUR LA CARTE
// =====================================================================

it('une pièce fracassée cesse de bloquer le MOUVEMENT et la VUE', function () {
    // La bibliothèque est le seul meuble qui bloque les DEUX (`bloque_vue`).
    $ctx = queteAvecMeuble('Bibliothèque', ['classe' => 'barbare']);

    $avant = FabriqueGrille::pour($ctx['quete']);
    expect($avant->estTraversable(4, 3))->toBeFalse()
        // La vue est coupée par-dessus : un tireur en (3,3) ne voit pas (5,3).
        ->and($avant->ligneDeVue(3, 3, 5, 3))->toBeFalse();

    $payload = fracasser($ctx, [1, 1, 1, 1, ...array_fill(0, 10, 4)]);

    expect($payload['detruit'] ?? false)->toBeTrue();

    // ⚠ Un seul drapeau suffit parce que `FabriqueGrille::pour()` tient la boucle
    // UNIQUE du mobilier de tout le moteur : l'écarter là l'écarte pour le
    // déplacement, le ciblage et la ligne de vue d'un seul geste.
    $apres = FabriqueGrille::pour($ctx['quete']->fresh()->load('carte'));
    expect($apres->estTraversable(4, 3))->toBeTrue()
        ->and($apres->ligneDeVue(3, 3, 5, 3))->toBeTrue();
});

it('une pièce fracassée disparaît de la carte publiée à la table', function () {
    $ctx = queteAvecMeuble('Table', ['classe' => 'barbare']);

    $avant = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    expect(collect($avant['carte']['mobilier'])->pluck('nom'))->toContain('Table');

    fracasser($ctx, [1, 1, 1, 1, ...array_fill(0, 10, 4)]);

    // Continuer à la dessiner ferait croire au groupe qu'un obstacle barre encore
    // le passage — alors que le moteur, lui, laisse déjà passer.
    $apres = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    expect(collect($apres['carte']['mobilier'])->pluck('nom'))->not->toContain('Table');
});

// =====================================================================
// LE TROC : UNE DERNIÈRE FOUILLE
// =====================================================================

it('une pièce FOUILLABLE détruite rend une dernière fouille, même déjà vidée par tout le groupe', function () {
    // Le coffre paie souvent (`MobilierSeeder`), et il est fouillable.
    $ctx = queteAvecMeuble('Coffre', ['classe' => 'barbare'], [
        // ⚠ Le héros l'a DÉJÀ fouillé — et c'est tout l'intérêt du cas : la
        // destruction rend une fouille de plus, sans quoi fracasser un coffre
        // vidé ne serait qu'une perte d'action.
        'fouille_par' => [1, 2, 3, 4],
    ]);

    $payload = fracasser($ctx, [1, 1, 1, 1, ...array_fill(0, 20, 4)]);

    expect($payload['detruit'] ?? false)->toBeTrue()
        ->and($payload['fouille_finale'] ?? false)->toBeTrue()
        // Le butin passe par `appliquerButin()` : son issue vient du même
        // vocabulaire que le deck de fouille et les autres meubles.
        //
        // ⚠ Il est NICHÉ sous `butin` et non fusionné à plat, parce que le
        // payload d'un jet porte DÉJÀ une clé `issue` — celle du dé (réussite /
        // réussite mixte / échec). Fusionnés, le tirage écrasait le jet : le
        // client lisait « tresor » là où il attendait « reussite ». Trouvé par
        // ce test même, sur le cas de la pièce non fouillable.
        ->and($payload['butin'])->toHaveKey('issue')
        ->and($payload['issue'])->toBe('reussite')
        // ⚠ Et JAMAIS un décompte de deck : le meuble tire dans sa propre table,
        // il n'a pas touché à la pioche de la quête.
        ->and($payload['butin'])->not->toHaveKey('deck_restant');
});

it('une pièce NON fouillable ne rend rien — on ouvre le passage, c\'est tout', function () {
    $ctx = queteAvecMeuble('Table', ['classe' => 'barbare']); // seule pièce non fouillable

    $payload = fracasser($ctx, [1, 1, 1, 1, ...array_fill(0, 10, 4)]);

    expect($payload['detruit'] ?? false)->toBeTrue()
        ->and($payload)->not->toHaveKey('fouille_finale')
        ->and($payload)->not->toHaveKey('butin');
});

// =====================================================================
// CE QUI N'EST PAS PROPOSÉ
// =====================================================================

it('le TOMBEAU n\'est jamais proposé : `difficulte_destruction` nulle veut dire indestructible', function () {
    $ctx = queteAvecMeuble('Tombeau', ['classe' => 'barbare']);

    expect($ctx['type']->difficulte_destruction)->toBeNull()
        // ⚠ `null` n'est pas « pas encore renseigné » : c'est un sarcophage de
        // pierre. Proposer l'action reviendrait à offrir un jet que rien ne peut
        // gagner — la difficulté n'existe même pas.
        ->and(optionsDestruction($ctx))->toBe([]);
});

it('une pièce déjà FRACASSÉE n\'est plus proposée', function () {
    $ctx = queteAvecMeuble('Coffre', ['classe' => 'barbare']);

    expect(optionsDestruction($ctx))->toHaveCount(1);

    app(MoteurMobilier::class)->detruire($ctx['quete']->carte, 0);

    expect(optionsDestruction($ctx))->toBe([]);
});

// =====================================================================
// UNE TENTATIVE PAR HÉROS, ET LE PLAFOND
// =====================================================================

it('une tentative par héros : l\'échec ferme l\'option à celui qui a essayé, jamais aux autres', function () {
    $ctx = queteAvecMeuble('Coffre', ['classe' => 'barbare']);

    // Un second héros, engagé dans la même quête, au contact du même meuble.
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brunhilde = creerHeros($bob, $ctx['groupe'], 'Brunhilde', 2, ['classe' => 'barbare']);
    $etatBrunhilde = EtatPersonnageQuete::create([
        'quete_id' => $ctx['quete']->id, 'personnage_id' => $brunhilde->id,
        'position_x' => 4, 'position_y' => 2,
    ]);

    // Albrecht échoue : que des boucliers blancs, aucun crâne.
    $payload = fracasser($ctx, array_fill(0, 20, 4));

    expect($payload['detruit'] ?? false)->toBeFalse()
        ->and(MoteurMobilier::estDetruite($ctx['quete']->fresh()->carte->grille['mobilier'][0]))->toBeFalse();

    // L'option a disparu POUR LUI…
    expect(optionsDestruction($ctx))->toBe([]);

    // …et reste offerte à sa compagne, qui n'a rien tenté. Le prix réel de
    // l'échec est le créneau d'action dépensé, pas la fermeture du meuble.
    expect(optionsDestruction($ctx, $brunhilde, $etatBrunhilde))->toHaveCount(1);
});

it('la difficulté publiée est PLAFONNÉE au meilleur Body du groupe', function () {
    // Trône : difficulté brute 3. Un magicien seul (Body 1) ne peut pas gagner
    // un jet à 3 — le plafond le ramène à 1.
    $ctx = queteAvecMeuble('Trône', ['classe' => 'magicien', 'attribut_body' => 1]);

    expect($ctx['type']->difficulte_destruction)->toBe(3);

    $options = optionsDestruction($ctx);

    expect($options)->toHaveCount(1)
        ->and($options[0]['jet']['difficulte'])->toBe(1)
        // ⚠ La difficulté EFFECTIVE figure dans le libellé : c'est elle que le
        // joueur lit, et il ne doit pas y trouver le 3 du catalogue.
        ->and(str_contains((string) $options[0]['libelle'], 'difficulté 1'))->toBeTrue();

    // ⚠ La valeur BRUTE reste intacte en base : le plafond est mobile (il monte
    // avec *Colosse*, descend quand le costaud s'en va), le figer dans le
    // catalogue le ferait mentir dès le niveau suivant.
    expect((int) Mobilier::where('nom', 'Trône')->value('difficulte_destruction'))->toBe(3);
});
