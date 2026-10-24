<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\MotsClesAdversaires;
use App\Models\Carte;
use App\Models\Evenement;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\MoteurDegats;
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
 * LE MÉTAMORPHE (Kellar's Keep, F4543 p. 25, quête 9, note A) — « Every time it is killed, shuffle all the monster cards and take
 * the top card. This is the new form the monster takes. [...] The monster can be permanently killed only if the new card drawn
 * matches the monster's most recent form. »
 *
 * Éprouvé par le VRAI point de passage de la mort (`MoteurDegats::infligerAMonstre()`), avec les dés injectés (`desFiges`). Le
 * tirage est uniforme sur les huit cartes de base (`OubliSorts::indiceAleatoire()`, rejet à deux dés) : un couple de d6 choisit
 * la carte. Les helpers portent le préfixe `mt`.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/** Le paquet DÉCLARÉ sur la fiche du métamorphe, dans l'ordre déclaré : les cartes tirables (à chaque mort). */
function mtPaquet(): array
{
    $noms = Monstre::where('nom_base', 'Métamorphe')->firstOrFail()->capacites['metamorphe']['paquet'];

    return collect($noms)->map(fn (string $nom) => Monstre::where('nom_base', $nom)->firstOrFail())->values()->all();
}

/** Le couple de d6 qui fait tirer l'indice `$i` du paquet à `indiceAleatoire()` (deux chiffres, base 6, rejet au-delà de 7). */
function mtDesPourIndice(int $i): array
{
    return [intdiv($i, 6) + 1, ($i % 6) + 1];
}

/** Une quête minimale : une salle ouverte de 6 × 4 cases, où la figure se tient. */
function mtQuete(): Quete
{
    $groupe = creerGroupe('table-mt-'.uniqid());
    $dessin = ['ssssss', 'ssssss', 'ssssss', 'ssssss'];

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — métamorphe',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 6, 'hauteur' => 4,
        'grille' => [
            'largeur' => 6, 'hauteur' => 4,
            'cases' => array_map(fn (string $ligne) => str_split($ligne), $dessin),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 6, 'hauteur' => 4, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [], 'pieges' => [], 'mobilier' => [],
            'terrain' => [], 'dangers' => [], 'spawn_heros' => [['x' => 0, 'y' => 0]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    return $quete->fresh()->load('carte');
}

/** Une instance révélée et active du catalogue `$nomBase` en (x, y) — le métamorphe, ou une figure ordinaire. */
function mtFigure(Quete $quete, string $nomBase, int $x = 2, int $y = 1): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nomBase)->firstOrFail();

    return InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'position_x' => $x,
        'position_y' => $y,
        'etat' => 'actif',
        'elite' => false,
        'revele' => true,
        'usages_dread' => 0,
        'invocation_dread_utilisee' => false,
        'fuite_dread_utilisee' => false,
    ]);
}

/** Un coup fatal, là où les héros le portent : le point de passage unique de la mort. */
function mtMourir(InstanceMonstre $instance): array
{
    return app(MoteurDegats::class)->infligerAMonstre(
        $instance->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS,
    );
}

/** Les événements `metamorphe_tirage` du groupe, dans l'ordre. */
function mtTirages(Quete $quete): array
{
    return Evenement::query()
        ->where('groupe_id', $quete->groupe_id)
        ->get()
        ->filter(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'metamorphe_tirage')
        ->map(fn (Evenement $e) => $e->payload)
        ->values()
        ->all();
}

// =====================================================================
// LE PAQUET ET LA FORME DE DÉPART
// =====================================================================

it('le paquet déclaré est exactement les huit cartes 2021 de la boîte de base : pas le Fimir (1989), pas le Métamorphe', function () {
    $noms = array_map(fn (Monstre $m) => $m->nom_base, mtPaquet());

    expect($noms)->toHaveCount(8)
        ->and($noms)->toContain('Abomination')
        ->and($noms)->not->toContain('Fimir')
        ->and($noms)->not->toContain('Métamorphe')
        ->and(count(array_unique($noms)))->toBe(8);
});

it('chaque carte du paquet déclaré existe au catalogue : une carte absente ne sort jamais en silence', function () {
    foreach (mtPaquet() as $carte) {
        expect($carte)->toBeInstanceOf(Monstre::class);
    }
});

it('le métamorphe sort de l\'Abomination : fiche 6/3/3/2/3, sous-boss de Kellar\'s Keep, `cout` 16', function () {
    $m = Monstre::where('nom_base', 'Métamorphe')->firstOrFail();

    expect([(int) $m->deplacement, (int) $m->attaque, (int) $m->defense, (int) $m->pv_body, (int) $m->pv_mind])
        ->toBe([6, 3, 3, 2, 3])
        ->and($m->tier)->toBe('sous_boss')
        ->and($m->boite)->toBe('kellars_keep')
        ->and((int) $m->cout)->toBe(16)
        ->and($m->capacites['metamorphe']['forme_de_depart'])->toBe('Abomination');
});

// =====================================================================
// LA MORT — une carte égale la forme qui tombe : mort pour de bon ; une autre : une nouvelle forme
// =====================================================================

it('une carte tirée ÉGALE à la forme qui tombe (l\'Abomination, sa forme de départ) : la mort est DÉFINITIVE', function () {
    $quete = mtQuete();
    $instance = mtFigure($quete, 'Métamorphe');
    $i = collect(mtPaquet())->search(fn (Monstre $m) => $m->nom_base === 'Abomination');
    desFiges(mtDesPourIndice((int) $i));

    $r = mtMourir($instance);

    expect($r['vaincu'])->toBeTrue()
        ->and($r['changement_phase'])->toBeNull()
        ->and(mtTirages($quete)[0])->toMatchArray(['carte' => 'Abomination', 'tire' => 'Abomination', 'definitif' => true]);
});

it('une carte tirée DIFFÉRENTE devient la nouvelle forme, à la même place : la figure ne meurt pas', function () {
    $quete = mtQuete();
    $instance = mtFigure($quete, 'Métamorphe', 3, 2);
    $i = collect(mtPaquet())->search(fn (Monstre $m) => $m->nom_base === 'Gobelin');
    desFiges(mtDesPourIndice((int) $i));

    $r = mtMourir($instance);
    $apres = $instance->fresh();

    expect($r['vaincu'])->toBeFalse()
        ->and($r['changement_phase'])->toMatchArray(['avant' => 'Métamorphe', 'apres' => 'Gobelin'])
        ->and($apres->monstre->nom_base)->toBe('Gobelin')
        ->and([(int) $apres->position_x, (int) $apres->position_y])->toBe([3, 2])
        ->and($apres->origine_monstre)->toBe('Métamorphe')
        ->and(mtTirages($quete)[0])->toMatchArray(['carte' => 'Abomination', 'tire' => 'Gobelin', 'definitif' => false]);
});

it('la carte tirée devient la carte COURANTE : sa mort suivante ne se compare plus qu\'à elle', function () {
    $quete = mtQuete();
    $instance = mtFigure($quete, 'Métamorphe');
    $gobelin = (int) collect(mtPaquet())->search(fn (Monstre $m) => $m->nom_base === 'Gobelin');
    $abomination = (int) collect(mtPaquet())->search(fn (Monstre $m) => $m->nom_base === 'Abomination');

    // 1re mort : Abomination → Gobelin (une autre carte). 2de mort : Gobelin → Gobelin (même carte : mort pour de bon).
    desFiges([...mtDesPourIndice($gobelin), ...mtDesPourIndice($gobelin)]);

    mtMourir($instance);
    $r = mtMourir($instance->fresh()->load('monstre'));

    expect($r['vaincu'])->toBeTrue()
        ->and(mtTirages($quete)[1])->toMatchArray(['carte' => 'Gobelin', 'tire' => 'Gobelin', 'definitif' => true])
        ->and($abomination)->not->toBe($gobelin);
});

it('une figure ORDINAIRE (sans métamorphe) ne tire aucune carte et meurt comme avant', function () {
    $quete = mtQuete();
    $instance = mtFigure($quete, 'Gobelin');

    $r = mtMourir($instance);

    expect($r['vaincu'])->toBeTrue()
        ->and(mtTirages($quete))->toBe([]);
});

// =====================================================================
// LE FIL — chaque tirage est annoncé, la mort pour de bon comme la nouvelle forme
// =====================================================================

it('chaque tirage est ANNONCÉ dans le journal (carte de départ, carte tirée, mort définitive ou non)', function () {
    $quete = mtQuete();
    $instance = mtFigure($quete, 'Métamorphe');
    $gobelin = (int) collect(mtPaquet())->search(fn (Monstre $m) => $m->nom_base === 'Gobelin');
    desFiges(mtDesPourIndice($gobelin));

    mtMourir($instance);

    expect(mtTirages($quete))->toHaveCount(1)
        ->and(mtTirages($quete)[0]['tire'])->toBe('Gobelin');
});

// =====================================================================
// LE REGISTRE — la clé est déclarée, son lecteur nommé, et la clé est lue dans le fichier du lecteur
// =====================================================================

it('REGISTRE : `metamorphe` est déclaré à lecteur réel, et le fichier du lecteur porte la clé', function () {
    expect(MotsClesAdversaires::CAPACITES)->toHaveKey(MotsClesAdversaires::METAMORPHE)
        ->and(MotsClesAdversaires::CAPACITES[MotsClesAdversaires::METAMORPHE]['lecteur'])
            ->toBe('App\Partie\MoteurSynchroformes::prochaine');

    $source = (string) file_get_contents(base_path('app/Partie/MoteurSynchroformes.php'));

    expect($source)->toContain("'metamorphe'");
});
