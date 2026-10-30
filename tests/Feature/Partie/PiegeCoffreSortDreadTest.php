<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Inventaire;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\SortDread;
use App\Partie\JournalCombat;
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
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LE COFFRE PIÉGÉ QUI LANCE UN SORT DE DREAD (Spirit Queen's Torment, G0053 quête 2, note C, p. 5 ; quête 6, note B, p. 9 :
 * « If a hero searches for treasure before the trap is disarmed, the Dread spell Ball of Flame is cast on that hero »).
 *
 * EN JEU, avec des dés injectés (`desFiges`) : la fouille passe par `ResolveurTour::appliquerButin()` avec une carte DONNÉE
 * (le tirage est testé à part, à sa couture : `MoteurMobilier::tirerButin()`), comme le tombeau de `TombeauMonstreTest`.
 * Les chiffres du sort (2 PV fixes, deux dés rouges) sont lus au catalogue `SortDreadSeeder`, jamais recopiés.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/** Une salle 16×9 en sol, un héros (Albrecht, 8 PV) en (3, 4), un groupe en quête. */
function piegeCoffreScene(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — le coffre piégé',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => array_fill(0, 9, array_fill(0, 16, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 16, 'hauteur' => 9,
                'theme' => 'generique', 'mediane_x' => 8, 'mediane_y' => 4]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => [],
            'spawn_heros' => [['x' => 3, 'y' => 4]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);
    $etat = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $albrecht->id, 'position_x' => 3, 'position_y' => 4,
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'albrecht' => $albrecht, 'etat' => $etat];
}

/**
 * La fouille d'un coffre, par son point de passage réel (`ResolveurTour::appliquerButin()`, comme `tombeauFouille()`).
 *
 * @param  array<string, mixed>  $carte
 * @return array<string, mixed>
 */
function piegeCoffreFouille(array $ctx, array $carte): array
{
    $methode = new ReflectionMethod(ResolveurTour::class, 'appliquerButin');
    $methode->setAccessible(true);

    return $methode->invoke(
        app(ResolveurTour::class), $carte, ['type' => 'fouille_mobilier'],
        $ctx['groupe'], $ctx['quete'], $ctx['albrecht'], $ctx['etat']->fresh(), false,
    );
}

/** La carte de butin d'un coffre qui porte le piège au sort de Dread. */
function piegeCoffreCarte(): array
{
    return ['issue' => 'piege', 'piege' => 'Piège de Boule de Flammes'];
}

// ---------------------------------------------------------------------
// 1. La donnée : le piège NOMME le sort, il ne recopie aucun chiffre
// ---------------------------------------------------------------------

it('la ligne du piège est boîtée spirit_queen, nomme un sort de dégâts du catalogue, et ne recopie aucun chiffre', function () {
    $piege = Piege::where('nom', 'Piège de Boule de Flammes')->firstOrFail();
    $sort = SortDread::where('nom', 'Boule de Flammes')->firstOrFail();

    expect($piege->boite)->toBe('spirit_queen')
        ->and($piege->effet['sort_dread'])->toBe('Boule de Flammes')
        ->and(array_keys($piege->effet))->toEqualCanonicalizing(['declencheur', 'sort_dread'])
        ->and($sort->type)->toBe('degats')
        ->and($sort->effet['degats_fixes'])->toBe(2);
});

it('la table du Coffre porte une entrée « piège », désignée par le nom de la ligne', function () {
    $fouille = (array) data_get(Mobilier::where('nom', 'Coffre')->firstOrFail()->effet, 'fouille', []);

    expect(collect($fouille)->firstWhere('issue', 'piege')['piege'] ?? null)->toBe('Piège de Boule de Flammes');
});

// ---------------------------------------------------------------------
// 2. En jeu : le sort part, les dés rouges du héros décident
// ---------------------------------------------------------------------

it('EN JEU — fouiller le coffre lance Boule de Flammes : deux dés rouges à 1 ne retirent rien, 2 PV, le tour s\'arrête', function () {
    $ctx = piegeCoffreScene();
    desFiges([1, 1]);

    $fouille = piegeCoffreFouille($ctx, piegeCoffreCarte());
    $declenchement = $fouille['declenchement'];
    $resultat = $declenchement['sort_dread']['resultats'][0];

    expect($declenchement['type'])->toBe('piege_declenche')
        ->and($declenchement['sort_dread']['sort'])->toBe('Boule de Flammes')
        ->and($resultat['degats_bruts'])->toBe(2)
        ->and($resultat['des_rouges'])->toBe([1, 1])
        ->and($resultat['degats'])->toBe(2)
        ->and($declenchement['degats'])->toBe(2)
        ->and($declenchement['fin_de_tour'])->toBeTrue()
        ->and((int) $ctx['albrecht']->fresh()->pv_body)->toBe(6)
        ->and($ctx['etat']->fresh()->a_joue)->toBeTrue();
});

it('EN JEU — deux dés rouges à 5 et 6 annulent chacun un point : le sort est lancé, le héros n\'est pas blessé', function () {
    $ctx = piegeCoffreScene();
    desFiges([5, 6]);

    $fouille = piegeCoffreFouille($ctx, piegeCoffreCarte());
    $resultat = $fouille['declenchement']['sort_dread']['resultats'][0];

    expect($resultat['des_rouges'])->toBe([5, 6])
        ->and($resultat['degats'])->toBe(0)
        ->and((int) $ctx['albrecht']->fresh()->pv_body)->toBe(8);
});

it('EN JEU — un seul 5-6 ne retire qu\'un point : 1 PV perdu sur 2', function () {
    $ctx = piegeCoffreScene();
    desFiges([6, 2]);

    $fouille = piegeCoffreFouille($ctx, piegeCoffreCarte());

    expect($fouille['declenchement']['sort_dread']['resultats'][0]['degats'])->toBe(1)
        ->and((int) $ctx['albrecht']->fresh()->pv_body)->toBe(7);
});

it('EN JEU — un Anneau de Feu absorbe le sort du coffre comme celui d\'un monstre : même point de passage', function () {
    $ctx = piegeCoffreScene();

    Inventaire::create([
        'personnage_id' => $ctx['albrecht']->id,
        'objet_id' => Objet::where('nom', 'Anneau de Feu')->firstOrFail()->id,
        'emplacement' => 'talisman',
        'quantite' => 1,
    ]);

    desFiges([1, 1]);
    $fouille = piegeCoffreFouille($ctx, piegeCoffreCarte());
    $resultat = $fouille['declenchement']['sort_dread']['resultats'][0];

    expect($resultat['absorbe'])->toBeTrue()
        ->and($resultat['degats'])->toBe(0)
        ->and((int) $ctx['albrecht']->fresh()->pv_body)->toBe(8);
});

it('l\'annonce : le fil dit le piège qui se déclenche, puis le sort et la victime', function () {
    $ctx = piegeCoffreScene();
    desFiges([1, 1]);

    $fouille = piegeCoffreFouille($ctx, piegeCoffreCarte());
    $textes = array_map(
        fn (array $ligne) => (string) ($ligne['texte'] ?? ''),
        app(JournalCombat::class)->depuisResultat($fouille, 'Albrecht'),
    );

    expect($textes)->toContain('Piège de Boule de Flammes se déclenche sur Albrecht !')
        ->and(collect($textes)->contains(fn (string $t) => str_contains($t, 'Boule de Flammes') && str_contains($t, 'Le piège')))->toBeTrue()
        ->and(collect($textes)->contains(fn (string $t) => str_contains($t, 'Albrecht') && str_contains($t, '2 PV')))->toBeTrue();
});

// ---------------------------------------------------------------------
// 3. Le thème : le piège ne sort que sous spirit_queen
// ---------------------------------------------------------------------

it('sous un autre thème, le piège ne sort JAMAIS de la table du Coffre (400 tirages)', function () {
    $coffre = Mobilier::where('nom', 'Coffre')->firstOrFail();
    $moteur = app(MoteurMobilier::class);

    $sortis = collect(range(1, 400))
        ->filter(fn () => ($moteur->tirerButin($coffre, 1, [], ['jungles_delthrak'])['piege'] ?? null) === 'Piège de Boule de Flammes')
        ->count();

    expect($sortis)->toBe(0);
});

it('sans thème passé, le piège ne sort pas non plus : seules les pièces sans boîte sont admises', function () {
    $coffre = Mobilier::where('nom', 'Coffre')->firstOrFail();
    $moteur = app(MoteurMobilier::class);

    $sortis = collect(range(1, 400))
        ->filter(fn () => ($moteur->tirerButin($coffre)['piege'] ?? null) !== null)
        ->count();

    expect($sortis)->toBe(0);
});

it('sous spirit_queen, le piège sort de la table du Coffre (400 tirages, poids 1 sur 11)', function () {
    $coffre = Mobilier::where('nom', 'Coffre')->firstOrFail();
    $moteur = app(MoteurMobilier::class);

    $sortis = collect(range(1, 400))
        ->filter(fn () => ($moteur->tirerButin($coffre, 1, [], ['spirit_queen'])['piege'] ?? null) === 'Piège de Boule de Flammes')
        ->count();

    expect($sortis)->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------
// 4. Le câblage : le GROUPE transmet ses boîtes aux DEUX points d'appel
// ---------------------------------------------------------------------

/**
 * Un Coffre au contact d'Albrecht, en (4, 4), et une table de fouille réduite au seul piège : le tirage est certain, seul le
 * thème du groupe décide. Mise en base de TEST uniquement (sqlite :memory:).
 */
function piegeCoffreAuContact(array $ctx): void
{
    $coffre = Mobilier::where('nom', 'Coffre')->firstOrFail();
    $coffre->update(['effet' => ['fouille' => [['issue' => 'piege', 'poids' => 1, 'piege' => 'Piège de Boule de Flammes']]]]);

    $carte = $ctx['quete']->carte;
    $grille = $carte->grille;
    $grille['mobilier'] = [['mobilier_id' => $coffre->id, 'x' => 4, 'y' => 4, 'l' => 1, 'h' => 1]];
    $carte->update(['grille' => $grille]);
}

/** La fouille d'un MEUBLE par son point de passage réel (`ResolveurTour::resoudreFouilleMobilier()`), entrée 0. */
function piegeCoffreFouilleMeuble(array $ctx): array
{
    $methode = new ReflectionMethod(ResolveurTour::class, 'resoudreFouilleMobilier');
    $methode->setAccessible(true);

    return $methode->invoke(
        app(ResolveurTour::class), $ctx['groupe'], $ctx['quete']->fresh()->load('carte'),
        $ctx['albrecht'], $ctx['etat']->fresh(),
        ['id' => 'fouille_mobilier_0', 'type' => 'fouille_mobilier', 'libelle' => 'Fouiller le coffre', 'parametres' => ['index' => 0]],
        ['type' => 'personnage', 'id' => $ctx['albrecht']->id, 'nom' => 'Albrecht'],
    );
}

/** La DESTRUCTION du meuble d'entrée 0, qui en rend le butin par le même thème (`ResolveurTour::fracasserMobilier()`). */
function piegeCoffreFracasse(array $ctx): array
{
    $methode = new ReflectionMethod(ResolveurTour::class, 'fracasserMobilier');
    $methode->setAccessible(true);

    return $methode->invoke(
        app(ResolveurTour::class), $ctx['groupe'], $ctx['quete']->fresh()->load('carte'),
        $ctx['albrecht'], $ctx['etat']->fresh(), 0,
    );
}

it('EN JEU — un groupe en thème spirit_queen : la fouille du coffre tire le piège, et Boule de Flammes frappe le fouilleur', function () {
    $ctx = piegeCoffreScene();
    piegeCoffreAuContact($ctx);
    $ctx['groupe']->update(['boites_bestiaire' => ['spirit_queen']]);

    desFiges([1, 1]);
    $payload = piegeCoffreFouilleMeuble($ctx);

    expect($payload['declenchement']['sort_dread']['sort'])->toBe('Boule de Flammes')
        ->and((int) $ctx['albrecht']->fresh()->pv_body)->toBe(6);
});

it('un groupe SANS thème ne tire jamais ce piège de la même fouille : rien ne part, aucun PV ne bouge', function () {
    $ctx = piegeCoffreScene();
    piegeCoffreAuContact($ctx);
    $ctx['groupe']->update(['boites_bestiaire' => []]);

    desFiges([1, 1]);
    $payload = piegeCoffreFouilleMeuble($ctx);

    expect(array_key_exists('declenchement', $payload))->toBeFalse()
        ->and((int) $ctx['albrecht']->fresh()->pv_body)->toBe(8);
});

it('la DESTRUCTION du coffre rend son butin sous le même thème : spirit_queen, le piège part', function () {
    $ctx = piegeCoffreScene();
    piegeCoffreAuContact($ctx);
    $ctx['groupe']->update(['boites_bestiaire' => ['spirit_queen']]);

    desFiges([1, 1]);
    $payload = piegeCoffreFracasse($ctx);

    expect($payload['butin']['declenchement']['sort_dread']['sort'])->toBe('Boule de Flammes');
});

it('la DESTRUCTION du coffre sans thème ne rend aucun piège', function () {
    $ctx = piegeCoffreScene();
    piegeCoffreAuContact($ctx);
    $ctx['groupe']->update(['boites_bestiaire' => []]);

    desFiges([1, 1]);
    $payload = piegeCoffreFracasse($ctx);

    expect(array_key_exists('declenchement', (array) ($payload['butin'] ?? [])))->toBeFalse();
});
