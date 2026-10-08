<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\MenuMoteur;
use App\Partie\MoteurOmbre;
use App\Partie\MoteurSorts;
use App\Partie\Rayon;
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
 * VOILE D'OMBRE — *Cloak of Shadows* (Wizards of Morcar, Spells of Darkness,
 * 2026-10-08). « Heroes and monsters on the tile may not attack or be attacked.
 * The darkness blocks line of sight into and through it. Place 3 shadow tokens
 * on this card. At the start of the spellcaster's turn, remove a shadow token.
 * The spell ends after the last shadow token is removed. »
 *
 * Quête MINIMALE (carte 9×7 tout en sol, une seule salle) : on éprouve une
 * mécanique, pas un placement. Chaque test emprunte les VRAIS lecteurs
 * (`MoteurSorts::options()`, `FabriqueGrille::pour()`, `MenuMoteur::generer()`).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : celui-ci dérive un parchemin par sort.
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, SortSeeder::class,
        ObjetSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/**
 * @return array{alice: mixed, groupe: App\Models\Groupe, heros: App\Models\Personnage, quete: Quete, etatHeros: EtatPersonnageQuete}
 */
function queteVoileDOmbre(int $herosX = 1, int $herosY = 3): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Lyra', 1, ['classe' => 'magicien']);
    app(MoteurSorts::class)->attacherElement($heros, 'tenebres');

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test', 'position_arc' => 1, 'type_jalon' => 'normale',
        'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 9, 'hauteur' => 7,
        'grille' => [
            'largeur' => 9, 'hauteur' => 7,
            'cases' => array_fill(0, 7, array_fill(0, 9, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 9, 'hauteur' => 7,
                'theme' => 'generique', 'mediane_x' => 4, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'epreuves' => [], 'mobilier' => [],
            'spawn_heros' => [['x' => $herosX, 'y' => $herosY]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    $etat = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => $herosX, 'position_y' => $herosY,
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete->fresh()->load('carte'), 'etatHeros' => $etat];
}

function vdo_monstreDansVoile(array $ctx, int $x, int $y): InstanceMonstre
{
    return InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => $x, 'position_y' => $y, 'etat' => 'actif', 'revele' => true,
    ]);
}

/** Les entrées « Voile d'ombre » du menu de sorts. */
function vdo_entreesVoile(array $ctx): array
{
    $options = app(MoteurSorts::class)->options(
        $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh(),
    );

    return collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])
        ->filter(fn ($e) => str_starts_with((string) $e['nom'], "Voile d'ombre"))
        ->values()->all();
}

/**
 * Lance le voile sur le PREMIER emplacement offert qui couvre toutes les cases
 * de `$couvre` (le plus proche du lanceur, sinon). Le menu plafonne et trie par
 * proximité : un test ne présume donc pas d'un rectangle exact, il dit ce qu'il
 * veut couvrir.
 *
 * @param  list<array{0: int, 1: int}>  $couvre
 */
function vdo_lancerVoile(array $ctx, array $couvre = []): array
{
    $quete = $ctx['quete']->fresh()->load('carte');
    $heros = $ctx['heros']->fresh();
    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $quete, $heros);
    $sortOption = collect($options)->firstWhere('id', 'lancer_sort');

    $entree = collect($sortOption['parametres']['sorts'])
        ->filter(fn ($e) => ($e['mode'] ?? null) === 'pose_ombre')
        ->first(function ($e) use ($couvre) {
            $cases = array_map(fn ($c) => $c['x'].','.$c['y'], $e['cases']);

            return collect($couvre)->every(fn ($c) => in_array($c[0].','.$c[1], $cases, true));
        });
    expect($entree)->not->toBeNull('Aucun emplacement offert ne couvre '.json_encode($couvre).'.');

    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();

    return (new ReflectionMethod(ResolveurTour::class, 'resoudreSort'))->invoke(
        app(ResolveurTour::class),
        $ctx['groupe']->fresh(), $quete, $heros, $etat, $sortOption, ['cle' => $entree['cle']],
        ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom],
    );
}

/** Le premier voile de la couche, tel que posé. */
function vdo_voilePose(array $ctx): array
{
    return $ctx['quete']->carte->fresh()->grille['ombre'][0];
}

function vdo_optionsMenuHeros(array $ctx): array
{
    return (new ReflectionMethod(MenuMoteur::class, 'generer'))
        ->invoke(app(MenuMoteur::class), $ctx['groupe']->fresh(), $ctx['heros']->fresh())['options'] ?? [];
}

// =====================================================================
// LE MENU — une entrée par emplacement légal, 3×2, jamais hors de vue
// =====================================================================

it('offre des emplacements 3×2 / 2×3, plafonnés, tous visibles du lanceur', function () {
    $ctx = queteVoileDOmbre();
    $entrees = vdo_entreesVoile($ctx);

    expect($entrees)->not->toBeEmpty()
        ->and(count($entrees))->toBeLessThanOrEqual(MoteurOmbre::MAX_ENTREES);

    $grille = FabriqueGrille::pour($ctx['quete']);

    foreach ($entrees as $e) {
        expect($e['mode'])->toBe('pose_ombre')->and($e['cases'])->toHaveCount(6);

        $xs = array_column($e['cases'], 'x');
        $ys = array_column($e['cases'], 'y');
        $l = max($xs) - min($xs) + 1;
        $h = max($ys) - min($ys) + 1;
        expect([$l, $h] === [3, 2] || [$l, $h] === [2, 3])->toBeTrue();

        foreach ($e['cases'] as $c) {
            expect($grille->ligneDeVue(1, 3, $c['x'], $c['y']))->toBeTrue();
        }
    }
});

it('n\'offre aucun emplacement quand une case de la zone est cachée par un mur', function () {
    $ctx = queteVoileDOmbre();
    $data = $ctx['quete']->carte->grille;
    // Un mur plein de haut en bas à x=4 : le lanceur (x=1) ne voit rien au-delà.
    foreach (range(0, 6) as $y) {
        $data['cases'][$y][4] = 'm';
    }
    $ctx['quete']->carte->update(['grille' => $data]);

    foreach (vdo_entreesVoile($ctx) as $e) {
        expect(max(array_column($e['cases'], 'x')))->toBeLessThan(4);
    }
});

// =====================================================================
// LA POSE — couche durable, 3 jetons, sort épuisé
// =====================================================================

it('pose un voile de 3 jetons sur la couche carte.grille[ombre] et épuise le sort', function () {
    $ctx = queteVoileDOmbre();
    $payload = vdo_lancerVoile($ctx);
    $voile = vdo_voilePose($ctx);

    expect($payload['mode'])->toBe('pose_ombre')
        ->and($payload['ombre'])->toBe(['x' => $voile['x'], 'y' => $voile['y'], 'l' => $voile['l'], 'h' => $voile['h'], 'jetons' => 3])
        ->and($payload['texte'])->toContain('3 jetons')
        ->and($voile['jetons'])->toBe(3)
        ->and($voile['lanceur_id'])->toBe($ctx['heros']->id)
        ->and($ctx['quete']->carte->fresh()->grille['ombre'])->toHaveCount(1);

    expect((bool) $ctx['heros']->fresh()->sorts()->where('nom', "Voile d'ombre")->first()?->pivot->disponible)->toBeFalse();
});

it('refuse un emplacement que le menu n\'a pas offert', function () {
    $ctx = queteVoileDOmbre();
    $quete = $ctx['quete']->fresh()->load('carte');
    $etat = $quete->etatsPersonnages()->firstOrFail();

    // Trop petit : quatre cases seulement.
    expect(fn () => app(MoteurOmbre::class)->poser($quete, $ctx['heros'], $etat, [
        ['x' => 3, 'y' => 2], ['x' => 4, 'y' => 2], ['x' => 3, 'y' => 3], ['x' => 4, 'y' => 3],
    ]))->toThrow(Illuminate\Validation\ValidationException::class);

    // Six cases mais ailleurs que sur un rectangle légal (hors carte).
    expect(fn () => app(MoteurOmbre::class)->poser($quete, $ctx['heros'], $etat,
        MoteurOmbre::casesDuRectangle(8, 5, 3, 2)))->toThrow(Illuminate\Validation\ValidationException::class);

    expect($ctx['quete']->carte->fresh()->grille['ombre'] ?? [])->toBe([]);
});

// =====================================================================
// LA VUE — « into and through », lue par FabriqueGrille seule
// =====================================================================

it('bloque la ligne de vue vers l\'ombre, à travers elle, et depuis elle', function () {
    $ctx = queteVoileDOmbre();
    expect(FabriqueGrille::pour($ctx['quete'])->ligneDeVue(1, 3, 8, 3))->toBeTrue();

    vdo_lancerVoile($ctx, [[4, 3]]);
    $grille = FabriqueGrille::pour($ctx['quete']->fresh()->load('carte'));

    // « into » : une case DANS le voile, même adjacente au lanceur, n'est plus vue…
    expect($grille->ligneDeVue(1, 3, 4, 3))->toBeFalse()
        // « through » : derrière le voile non plus…
        ->and($grille->ligneDeVue(1, 3, 8, 3))->toBeFalse()
        // …et depuis l'intérieur on ne voit pas non plus au-dehors (symétrie).
        ->and($grille->ligneDeVue(4, 3, 8, 3))->toBeFalse()
        // Une ligne qui ne le touche pas reste dégagée.
        ->and($grille->ligneDeVue(1, 6, 8, 6))->toBeTrue()
        ->and($grille->ligneDeVue(1, 0, 8, 0))->toBeTrue()
        // On MARCHE sous l'ombre : ce n'est pas un obstacle.
        ->and($grille->estTraversable(4, 3))->toBeTrue();
});

it('retire de la ligne de tir un monstre sous le voile (aucun sort ne peut le viser)', function () {
    $ctx = queteVoileDOmbre(4, 3);
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'feu');
    $monstre = vdo_monstreDansVoile($ctx, 6, 3);

    $cibles = fn () => collect(collect(app(MoteurSorts::class)->options(
        $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh(),
    ))->firstWhere('id', 'lancer_sort')['parametres']['sorts'])
        ->firstWhere('nom', 'Boule de Feu')['cibles'] ?? [];
    $monstres = fn () => collect($cibles())->where('type', 'monstre')->pluck('id');

    expect($monstres())->toContain($monstre->id);

    vdo_lancerVoile($ctx, [[6, 3]]); // le monstre est dedans
    expect($monstres())->not->toContain($monstre->id);
});

// =====================================================================
// « NE PEUT NI ATTAQUER NI ÊTRE ATTAQUÉ » — un prédicat, plusieurs portes
// =====================================================================

it('refuse de frapper un monstre sous le voile, et ne le propose dans aucune liste de cibles', function () {
    $ctx = queteVoileDOmbre(2, 3);
    $monstre = vdo_monstreDansVoile($ctx, 3, 3);

    $cibles = fn () => collect(vdo_optionsMenuHeros($ctx))->where('type', 'attaque')
        ->flatMap(fn ($o) => [
            ...($o['parametres']['cibles'] ?? []),
            ...collect($o['parametres']['armes'] ?? [])->flatMap(fn ($a) => $a['cibles'] ?? [])->all(),
        ])
        ->pluck('id');

    expect($cibles())->toContain($monstre->id); // sans voile : au contact, donc visé

    $data = $ctx['quete']->carte->grille;
    $data['ombre'] = [['x' => 3, 'y' => 2, 'l' => 3, 'h' => 2, 'jetons' => 3, 'lanceur_id' => 999]];
    $ctx['quete']->carte->update(['grille' => $data]);

    expect($cibles())->not->toContain($monstre->id);

    expect(fn () => app(ResolveurTour::class)->frapper(
        $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['etatHeros']->fresh(),
        $ctx['heros']->fresh(), $monstre,
    ))->toThrow(Illuminate\Validation\ValidationException::class, 'voile d\'ombre');
});

it('interdit d\'attaquer à un héros qui se tient sous le voile : menu et résolveur, une seule règle', function () {
    $ctx = queteVoileDOmbre(4, 3);
    $monstre = vdo_monstreDansVoile($ctx, 7, 3); // hors voile, mais au contact de personne
    $data = $ctx['quete']->carte->grille;
    $data['ombre'] = [['x' => 3, 'y' => 2, 'l' => 3, 'h' => 2, 'jetons' => 3, 'lanceur_id' => 999]];
    $ctx['quete']->carte->update(['grille' => $data]);

    $heros = $ctx['heros']->fresh();
    expect(app(MoteurSorts::class)->attaqueInterdite($heros))->toBeTrue()
        ->and(app(MoteurSorts::class)->raisonAttaqueInterdite($heros))->toContain('voile d\'ombre');

    // Aucune option d'attaque au menu…
    $types = collect(vdo_optionsMenuHeros($ctx))->pluck('type')->all();
    foreach (MenuMoteur::TYPES_ATTAQUE_HEROS as $t) {
        expect($types)->not->toContain($t);
    }

    // …et le résolveur la refuse si un client l'envoie quand même.
    expect(fn () => (new ReflectionMethod(ResolveurTour::class, 'resoudre'))->invoke(
        app(ResolveurTour::class), $ctx['groupe']->fresh(), $heros,
        ['id' => 'attaquer', 'type' => 'attaque', 'parametres' => []], ['cible_id' => $monstre->id],
    ))->toThrow(Illuminate\Validation\ValidationException::class);

    // Sorti du voile, il peut de nouveau frapper : la règle suit la CASE.
    $ctx['etatHeros']->update(['position_x' => 1]);
    expect(app(MoteurSorts::class)->attaqueInterdite($heros->fresh()))->toBeFalse();
});

it('un héros sous le voile n\'est la cible d\'aucun monstre', function () {
    $ctx = queteVoileDOmbre(4, 3);
    $monstre = vdo_monstreDansVoile($ctx, 7, 3);
    $data = $ctx['quete']->carte->grille;
    $data['ombre'] = [['x' => 3, 'y' => 2, 'l' => 3, 'h' => 2, 'jetons' => 3, 'lanceur_id' => 999]];
    $ctx['quete']->carte->update(['grille' => $data]);

    expect(app(MoteurSorts::class)->estInattaquable($ctx['heros']->fresh()))->toBeTrue();

    // Hors du voile : redevenu une cible.
    $ctx['etatHeros']->update(['position_x' => 1]);
    expect(app(MoteurSorts::class)->estInattaquable($ctx['heros']->fresh()))->toBeFalse();
});

it('un monstre sous le voile peut marcher mais ne frappe pas', function () {
    $ctx = queteVoileDOmbre(1, 3);
    // Voile posé autour du monstre, héros à son contact (hors voile).
    $monstre = vdo_monstreDansVoile($ctx, 2, 3);
    $data = $ctx['quete']->carte->grille;
    $data['ombre'] = [['x' => 2, 'y' => 2, 'l' => 3, 'h' => 2, 'jetons' => 3, 'lanceur_id' => 999]];
    $ctx['quete']->carte->update(['grille' => $data]);

    $cibles = $ctx['quete']->etatsPersonnages()->with('personnage')->get();
    $resultat = (new ReflectionMethod(ResolveurTour::class, 'jouerMonstre'))->invoke(
        app(ResolveurTour::class), $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $monstre, $cibles,
    );

    expect($resultat['type'])->toBe('monstre_dans_l_ombre')
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe((int) $ctx['heros']->pv_body_max);
});

it('un rayon traverse le voile sans en frapper les occupants', function () {
    $ctx = queteVoileDOmbre(0, 3);
    $data = $ctx['quete']->carte->grille;
    $data['ombre'] = [['x' => 3, 'y' => 2, 'l' => 3, 'h' => 2, 'jetons' => 3, 'lanceur_id' => 999]];
    $ctx['quete']->carte->update(['grille' => $data]);

    $cases = collect(Rayon::cases(FabriqueGrille::pour($ctx['quete']->fresh()->load('carte')), 0, 3, 'e'))
        ->map(fn ($c) => $c['x'])->all();

    // Les cases x=3..5 sont sous le voile : exclues. Le rayon continue au-delà.
    expect($cases)->toBe([1, 2, 6, 7, 8]);
});

// =====================================================================
// LE DÉCOMPTE — au début du tour du LANCEUR, 3 jetons, puis plus rien
// =====================================================================

it('retire un jeton au début du tour du lanceur, une fois par round, puis dissipe le voile', function () {
    $ctx = queteVoileDOmbre();
    vdo_lancerVoile($ctx);

    $jetons = fn () => collect($ctx['quete']->carte->fresh()->grille['ombre'] ?? [])->first()['jetons'] ?? 0;
    $nouveauRound = function () use ($ctx) {
        $ctx['quete']->etatsPersonnages()->update(['a_joue' => false, 'capacites_tour' => null]);
    };

    // Le tour du lanceur s'ouvre : menu généré (le décompte est UNE fois par round).
    vdo_optionsMenuHeros($ctx);
    expect($jetons())->toBe(2);
    vdo_optionsMenuHeros($ctx); // un menu recalculé ne retire rien
    expect($jetons())->toBe(2);

    $nouveauRound();
    vdo_optionsMenuHeros($ctx);
    expect($jetons())->toBe(1);

    $nouveauRound();
    vdo_optionsMenuHeros($ctx);
    expect($ctx['quete']->carte->fresh()->grille['ombre'])->toBe([]); // « ends after the last token »

    // Annoncé au journal à chaque jeton, dissipation comprise.
    $textes = App\Models\Evenement::where('groupe_id', $ctx['groupe']->id)
        ->where('payload->type', 'ombre_decompte')->orderBy('sequence')->get()
        ->map(fn ($e) => $e->payload['texte'])->all();
    expect($textes)->toHaveCount(3)
        ->and($textes[2])->toContain('se dissipe');

    // La vue est rendue : la ligne de tir est de nouveau dégagée.
    expect(FabriqueGrille::pour($ctx['quete']->fresh()->load('carte'))->ligneDeVue(1, 3, 7, 3))->toBeTrue();
});

it('un lanceur TOMBÉ ne garde pas le voile jusqu\'à la fin de la quête', function () {
    $ctx = queteVoileDOmbre();
    vdo_lancerVoile($ctx);
    $ctx['etatHeros']->update(['tombe' => true]);

    $quete = $ctx['quete']->fresh()->load('carte');
    for ($i = 0; $i < 3; $i++) {
        app(MoteurOmbre::class)->lanceursTombes($ctx['groupe']->fresh(), $quete->fresh()->load('carte'));
    }

    expect($ctx['quete']->carte->fresh()->grille['ombre'])->toBe([]);
});

// =====================================================================
// LE PAYLOAD — publié, avec son compteur, derrière le brouillard
// =====================================================================

it('publie le voile et son compteur dans EtatGroupe', function () {
    $ctx = queteVoileDOmbre();
    expect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['carte']['ombre'])->toBe([]);

    vdo_lancerVoile($ctx);
    $voile = vdo_voilePose($ctx);
    $ombre = app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['carte']['ombre'];

    expect($ombre)->toHaveCount(1)
        ->and($ombre[0])->toMatchArray(['x' => $voile['x'], 'y' => $voile['y'], 'l' => $voile['l'], 'h' => $voile['h'],
            'jetons' => 3, 'jetons_max' => 3, 'lanceur' => 'Lyra'])
        ->and($ombre[0])->not->toHaveKey('lanceur_id');
});

it('journalise la pose avec le texte du sort et le met en scène sur la table', function () {
    $ctx = queteVoileDOmbre();
    $payload = vdo_lancerVoile($ctx);

    $lignes = app(App\Partie\JournalCombat::class)->depuisResultat($payload, 'Lyra');
    expect(collect($lignes)->pluck('texte')->implode(' '))->toContain('voile de ténèbres');

    $scenes = app(App\Partie\SceneDeTable::class)->depuisResultat($payload, $ctx['heros']->fresh(), []);
    expect($scenes)->not->toBeEmpty()
        ->and($scenes[0]['sous_titre'])->toBe('Zone d\'ombre');
});

it('lit un seul voile par lanceur et n\'en pose pas un second', function () {
    $ctx = queteVoileDOmbre();
    vdo_lancerVoile($ctx);
    $quete = $ctx['quete']->fresh()->load('carte');
    $etat = $quete->etatsPersonnages()->firstOrFail();

    expect(fn () => app(MoteurOmbre::class)->poser($quete, $ctx['heros'], $etat, MoteurOmbre::casesDuRectangle(0, 5, 3, 2)))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('se lit aussi en parchemin : une entrée par emplacement légal, chacune portant son parchemin', function () {
    $ctx = queteVoileDOmbre();
    $ctx['heros']->inventaire()->create([
        'objet_id' => App\Models\Objet::where('nom', "Parchemin : Voile d'ombre")->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'consommable',
    ]);

    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh());
    $entrees = collect(collect($options)->firstWhere('id', 'lire_parchemin')['parametres']['parchemins']);

    expect($entrees)->not->toBeEmpty()
        ->and($entrees->every(fn ($e) => ($e['mode'] ?? null) === 'pose_ombre' && isset($e['inventaire_id'])
            && str_starts_with($e['cle'], 'parchemin:')))->toBeTrue();

    $etat = $ctx['quete']->etatsPersonnages()->firstOrFail();
    $payload = (new ReflectionMethod(ResolveurTour::class, 'resoudreParchemin'))->invoke(
        app(ResolveurTour::class), $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh(), $etat,
        collect($options)->firstWhere('id', 'lire_parchemin'), ['cle' => $entrees->first()['cle']],
        ['type' => 'personnage', 'id' => $ctx['heros']->id, 'nom' => 'Lyra'],
    );

    expect($payload['mode'])->toBe('pose_ombre')
        ->and($ctx['quete']->carte->fresh()->grille['ombre'])->toHaveCount(1);
});
