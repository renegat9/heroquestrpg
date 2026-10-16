<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Mobilier;
use App\Models\Quete;
use App\Partie\JournalCombat;
use App\Partie\MenuMoteur;
use App\Partie\MoteurCheminee;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Validation\ValidationException;

/*
 * LA CHEMINÉE — HEALING HEARTH (First Light, livret de quêtes p. 7) : « When a hero
 * would draw a treasure card in a room containing a fireplace, they may instead heal
 * 1 Body point. » Même geste que la Mare (`TerrainJungleTest`, section 4) : une
 * fouille de salle, un autre gain, un héros qui a perdu un point.
 *
 * Scènes construites À LA MAIN (une ou deux salles, une cheminée posée par la main) :
 * on éprouve la règle, pas le placement — le placement a ses propres tests dans
 * `MobilierTroisCasesTest`.
 */

beforeEach(function () {
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

/**
 * Une entrée `cartes.grille.mobilier[]` : une cheminée 3×1 dans la salle `$salle`.
 *
 * @return array<string, mixed>
 */
function entreeCheminee(int $x = 0, int $y = 0, int $salle = 0): array
{
    return [
        'mobilier_id' => (int) Mobilier::where('nom', MoteurCheminee::NOM)->value('id'),
        'x' => $x, 'y' => $y, 'l' => 3, 'h' => 1, 'salle' => $salle,
    ];
}

/**
 * Une quête en cours sur une carte de `$largeur` × `$hauteur` cases, découpée en
 * salles `$salles` (`x`, `y`, `largeur`, `hauteur`), un héros posé en `$herosPos`
 * et un second héros qui garde le round ouvert (cf. `TerrainJungleTest`).
 *
 * @param  list<array<string, mixed>>  $mobilier
 * @param  list<string>  $boites  boîtes cochées du bestiaire (mode manuel)
 * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>|null  $salles
 * @param  array<string, mixed>  $herosAttrs
 * @return array{groupe: App\Models\Groupe, quete: Quete, heros: App\Models\Personnage, etatHeros: EtatPersonnageQuete}
 */
function sceneFoyer(
    array $mobilier = [],
    array $boites = ['first_light'],
    array $herosAttrs = [],
    array $herosPos = ['x' => 0, 'y' => 0],
    int $largeur = 6,
    int $hauteur = 6,
    ?array $salles = null,
): array {
    $groupe = creerGroupe('table-foyer-'.uniqid());
    $groupe->update(['boites_bestiaire' => $boites]);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Quête de test — cheminée',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $salles ??= [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur]];

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => $largeur, 'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => $hauteur,
            'cases' => array_fill(0, $hauteur, array_fill(0, $largeur, 's')),
            'salles' => array_map(fn (array $s) => $s + ['theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0], $salles),
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => $mobilier, 'terrain' => [],
            'spawn_heros' => [$herosPos], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);

    $heros = creerHeros(connecterJoueur('foyer-'.uniqid()), $groupe, 'Testeur', 1, $herosAttrs);

    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => $herosPos['x'], 'position_y' => $herosPos['y'], 'tombe' => false, 'a_joue' => false,
    ]);

    $second = creerHeros(connecterJoueur('foyer2-'.uniqid()), $groupe, 'Second', 2);
    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $second->id,
        'position_x' => 0, 'position_y' => $hauteur - 1, 'tombe' => false, 'a_joue' => false,
    ]);

    return ['groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'heros' => $heros, 'etatHeros' => $etatHeros];
}

/** @return list<array<string, mixed>> */
function optionsFoyer(array $scene): array
{
    return app(MenuMoteur::class)->generer($scene['groupe']->fresh(), $scene['heros']->fresh())['options'] ?? [];
}

/** Le geste « se réchauffer » tel que le menu le rend. */
function optionSoinCheminee(): array
{
    return ['id' => 'soin_cheminee', 'libelle' => 'Se réchauffer à la cheminée', 'type' => 'soin_cheminee'];
}

/** @return array<string, mixed> */
function resoudreFoyer(array $scene): array
{
    return app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros']->fresh(), optionSoinCheminee(), [],
    );
}

// =====================================================================
// 1. LE MENU — offert seulement là où la règle s'applique
// =====================================================================

it('offre « Se réchauffer » à la fouille d\'un héros blessé, dans une salle à cheminée, pour un groupe First Light', function () {
    $scene = sceneFoyer([entreeCheminee()]);
    $ids = fn () => collect(optionsFoyer($scene))->pluck('id');

    // Plein Body : la fouille reste, le foyer n'est pas proposé.
    expect($ids())->toContain('fouiller_tresor')->not->toContain('soin_cheminee');

    $scene['heros']->update(['pv_body' => 5]);
    expect($ids())->toContain('fouiller_tresor')->toContain('soin_cheminee');

    $option = collect(optionsFoyer($scene))->firstWhere('id', 'soin_cheminee');
    expect($option['type'])->toBe('soin_cheminee')
        ->and($option['libelle'])->toContain('cheminée');
});

it('n\'offre JAMAIS le foyer sans cheminée dans la salle, ni à un groupe qui ne joue pas First Light', function () {
    $sansCheminee = sceneFoyer([]);
    $sansCheminee['heros']->update(['pv_body' => 5]);
    expect(collect(optionsFoyer($sansCheminee))->pluck('id'))->not->toContain('soin_cheminee');

    // Une cheminée posée, mais un groupe qui joue une AUTRE boîte : la règle ne le concerne pas.
    $autreBoite = sceneFoyer([entreeCheminee()], boites: ['jungles_delthrak']);
    $autreBoite['heros']->update(['pv_body' => 5]);
    expect(collect(optionsFoyer($autreBoite))->pluck('id'))->not->toContain('soin_cheminee');
});

it('ne lit la cheminée que dans SA salle : une pièce voisine sans foyer ne l\'offre pas', function () {
    // Deux salles côte à côte : la cheminée est dans la salle 0 (x 0..2), le héros dans la salle 1 (x 3..5).
    $scene = sceneFoyer(
        [entreeCheminee(x: 0, y: 0, salle: 0)],
        herosPos: ['x' => 4, 'y' => 0],
        salles: [
            ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 6],
            ['x' => 3, 'y' => 0, 'largeur' => 3, 'hauteur' => 6],
        ],
    );
    $scene['heros']->update(['pv_body' => 5]);

    expect(collect(optionsFoyer($scene))->pluck('id'))->toContain('fouiller_tresor')
        ->not->toContain('soin_cheminee');
});

it('ne propose pas le foyer à un héros qui a déjà fouillé la salle', function () {
    $scene = sceneFoyer([entreeCheminee()]);
    $scene['heros']->update(['pv_body' => 5]);
    $scene['quete']->marquerTresorFouille(0, (int) $scene['heros']->id);

    expect(collect(optionsFoyer($scene))->pluck('id'))->not->toContain('soin_cheminee');
});

// =====================================================================
// 2. LE GESTE — un point, la fouille dépensée, aucune carte
// =====================================================================

it('se réchauffer rend 1 PV, dépense la fouille de la salle, ne tire AUCUNE carte — et ne se rejoue pas', function () {
    $scene = sceneFoyer([entreeCheminee()]);
    $scene['heros']->update(['pv_body' => 5]);
    $deckAvant = $scene['quete']->fresh()->deckFouille();

    $payload = resoudreFoyer($scene);

    expect($payload['type'])->toBe('soin_cheminee')
        ->and($payload['soin'])->toBe(1)
        ->and($payload['pv_body_apres'])->toBe(6)
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe(6)
        ->and($scene['quete']->fresh()->aFouille(0, (int) $scene['heros']->id))->toBeTrue()
        ->and($scene['quete']->fresh()->deckFouille())->toBe($deckAvant);

    // La fouille est dépensée : le résolveur refuse un second geste, même à un héros
    // qui a de nouveau perdu un point — et ce refus vient bien de la fouille.
    $scene['etatHeros']->fresh()->update(['a_joue' => false, 'a_agi' => false]);
    $scene['heros']->update(['pv_body' => 5]);

    $refus = null;
    try {
        resoudreFoyer($scene);
    } catch (ValidationException $e) {
        $refus = $e->errors();
    }

    expect($refus['option_id'] ?? [])->toContain('Tu as déjà fouillé cette salle.');
});

it('le soin ne dépasse jamais le maximum : un héros à un point du plein revient au plein, pas au-delà', function () {
    $scene = sceneFoyer([entreeCheminee()]);
    $scene['heros']->update(['pv_body' => 7]);

    $payload = resoudreFoyer($scene);

    expect($payload['soin'])->toBe(1)
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe((int) $scene['heros']->fresh()->pv_body_max);
});

it('le résolveur refuse le foyer à un héros au maximum, à une salle sans cheminée et à un groupe sans First Light', function () {
    $plein = sceneFoyer([entreeCheminee()]);
    expect(fn () => resoudreFoyer($plein))->toThrow(ValidationException::class);

    $sansCheminee = sceneFoyer([]);
    $sansCheminee['heros']->update(['pv_body' => 5]);
    expect(fn () => resoudreFoyer($sansCheminee))->toThrow(ValidationException::class);

    $autreBoite = sceneFoyer([entreeCheminee()], boites: ['jungles_delthrak']);
    $autreBoite['heros']->update(['pv_body' => 5]);
    expect(fn () => resoudreFoyer($autreBoite))->toThrow(ValidationException::class);

    // Aucun de ces refus n'a rien écrit : le héros est resté où il était.
    expect((int) $plein['heros']->fresh()->pv_body)->toBe((int) $plein['heros']->fresh()->pv_body_max)
        ->and((int) $autreBoite['heros']->fresh()->pv_body)->toBe(5);
});

// =====================================================================
// 3. L'ANNONCE — le choix de ne pas piocher se voit au fil
// =====================================================================

it('annonce le soin au journal : le fil dit qu\'on se réchauffe, sans carte de trésor', function () {
    $scene = sceneFoyer([entreeCheminee()]);
    $scene['heros']->update(['pv_body' => 5]);

    $payload = resoudreFoyer($scene);

    $texte = collect(app(JournalCombat::class)->depuisResultat($payload, 'Testeur'))->pluck('texte')->implode(' ');

    expect($texte)->toContain('cheminée')
        ->toContain('+1 PV de Body')
        ->toContain('sans carte de trésor');
});
