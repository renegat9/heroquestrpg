<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Snapshot;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\HallArene;
use App\Partie\Arene\RefusArene;
use App\Partie\FabriqueGrille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/**
 * MODE ARÈNE, lot T1 — le hall fixe et le démarrage d'un combat (`docs/plan-tournoi-worlds-end.md` §4, T1).
 *
 * Le hall n'est pas un cas particulier du moteur : la même grille (`FabriqueGrille::pour()`), le même état de table
 * (`EtatGroupe`), le même menu (`MenuMoteur`) le lisent. Ce fichier le prouve en jouant dessus par les vraies routes.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    $this->joueur = connecterJoueur();
});

/** Un combat d'arène prêt à démarrer : `$n` héros d'arène du joueur connecté, engagés par leur doublure. */
function areneAvecHeros(int $n = 2): array
{
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $heros = [];
    $doublures = [];

    foreach (array_slice(['barbare', 'nain', 'elfe', 'magicien'], 0, $n) as $i => $classe) {
        $corps = ['nom' => "Champion {$i}", 'classe' => $classe, 'mode' => 'arene'];
        if ($classe === 'elfe') {
            $corps['elements'] = ['air'];
        }
        if ($classe === 'magicien') {
            $corps['elements'] = ['feu', 'eau', 'terre'];
        }
        $id = test()->postJson('/api/personnages', $corps)->assertCreated()->json('personnage.id');
        $heros[] = Personnage::findOrFail($id);
        $doublures[] = $arenes->engager($arene, $heros[$i]);
    }

    return [$arene, $heros, $doublures];
}

function idsMonstres(string ...$noms): array
{
    return array_map(fn (string $nom) => (int) Monstre::where('nom_base', $nom)->value('id'), $noms);
}

it('dessine un hall d\'une seule salle, sans porte, aux deux tuiles de départ disjointes et sur le sol', function () {
    $carte = app(HallArene::class)->carte();
    $cle = fn (array $c) => "{$c['x']},{$c['y']}";

    $challenger = array_map($cle, $carte['spawn_heros']);
    $defender = array_map($cle, $carte['spawn_monstres']);

    expect($carte['salles'])->toHaveCount(1)
        ->and($carte['portes'])->toBe([])
        ->and($carte['escalier'])->toBeNull()
        ->and(count($challenger))->toBeGreaterThanOrEqual(8)
        ->and(count($defender))->toBeGreaterThanOrEqual(8)
        ->and(array_intersect($challenger, $defender))->toBe([])
        ->and(count(array_unique($challenger)))->toBe(count($challenger));

    foreach ([...$carte['spawn_heros'], ...$carte['spawn_monstres']] as $c) {
        expect($carte['cases'][$c['y']][$c['x']])->toBe('s');
    }

    // Les deux tuiles se font face : tout le Challenger est à l'ouest de tout le Defender, avec du champ entre eux.
    $maxOuest = max(array_column($carte['spawn_heros'], 'x'));
    $minEst = min(array_column($carte['spawn_monstres'], 'x'));
    expect($minEst - $maxOuest)->toBeGreaterThan(6);
});

it('démarre un combat d\'arène : hall, doublures et Defender sur leurs tuiles, sans gabarit, sans instantané', function () {
    [$arene, $heros, $doublures] = areneAvecHeros(2);

    $quete = app(DemarreurArene::class)->demarrer($arene, idsMonstres('Gobelin', 'Orque', 'Gobelin'));

    $carte = app(HallArene::class)->carte();
    $tuileHeros = array_map(fn ($c) => "{$c['x']},{$c['y']}", $carte['spawn_heros']);
    $tuileMonstres = array_map(fn ($c) => "{$c['x']},{$c['y']}", $carte['spawn_monstres']);

    expect($quete->gabarit_id)->toBeNull()
        ->and($quete->etat)->toBe('en_cours')
        ->and($arene->refresh()->phase)->toBe('quete')
        ->and($arene->quete_courante_id)->toBe($quete->id)
        ->and(Carte::where('quete_id', $quete->id)->exists())->toBeTrue()
        ->and(Snapshot::where('groupe_id', $arene->id)->count())->toBe(0);

    $etats = $quete->etatsPersonnages()->get();
    expect($etats->pluck('personnage_id')->sort()->values()->all())
        ->toBe(collect($doublures)->pluck('id')->sort()->values()->all());
    foreach ($etats as $etat) {
        expect($tuileHeros)->toContain("{$etat->position_x},{$etat->position_y}");
    }

    $instances = InstanceMonstre::where('quete_id', $quete->id)->with('monstre')->get();
    expect($instances)->toHaveCount(3);
    foreach ($instances as $instance) {
        expect($tuileMonstres)->toContain("{$instance->position_x},{$instance->position_y}")
            ->and($instance->pv_body)->toBe((int) $instance->monstre->pv_body)
            ->and($instance->elite)->toBeFalse()
            // Noms originaux (René) : aucun habillage en arène.
            ->and($instance->nomAffiche())->toBe($instance->monstre->nom_base);
    }

    // Les héros d'arène, eux, ne sont jamais entrés dans le hall.
    foreach ($heros as $h) {
        expect($quete->etatsPersonnages()->where('personnage_id', $h->id)->exists())->toBeFalse();
    }
});

it('le moteur joue sur le hall : grille, état de table, menu et déplacement par les vraies routes', function () {
    [$arene, , $doublures] = areneAvecHeros(1);
    $quete = app(DemarreurArene::class)->demarrer($arene, idsMonstres('Orque'));

    // La grille se construit par LE point de passage, sans cas particulier.
    $grille = FabriqueGrille::pour($quete->refresh());
    $etat = $quete->etatsPersonnages()->firstOrFail();
    expect($grille->estRoche((int) $etat->position_x, (int) $etat->position_y))->toBeFalse()
        ->and($grille->estTraversable((int) $etat->position_x + 1, (int) $etat->position_y))->toBeTrue();

    $this->getJson("/api/groupes/{$arene->identifiant}/etat")->assertOk();

    $menu = $this->getJson("/api/groupes/{$arene->identifiant}/menu")->assertOk()->json('menu');
    expect($menu)->not->toBeNull();
    $options = collect($menu['options'] ?? []);
    // Le menu n'offre jamais ce que l'arène refuse : ni fouille de zone, ni vote de sortie, ni retraite.
    expect($options->pluck('id')->all())->not->toContain('fouiller')
        ->not->toContain('quitter_donjon')
        ->not->toContain('battre_en_retraite');
    $deplacer = $options->firstWhere('id', 'se_deplacer');
    expect($deplacer)->not->toBeNull()
        ->and($deplacer['parametres']['destinations'] ?? [])->not->toBeEmpty();

    // Un pas vers l'est, vers le Defender : une destination que le serveur a publiée.
    $cible = collect($deplacer['parametres']['destinations'])->sortByDesc('x')->first();
    $this->postJson("/api/groupes/{$arene->identifiant}/choix", [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => $cible['x'], 'y' => $cible['y']],
    ])->assertSuccessful();

    $etat->refresh();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([(int) $cible['x'], (int) $cible['y']])
        ->and($doublures[0]->refresh()->estDoublure())->toBeTrue();
});

it('refuse de démarrer une campagne, un hall sans combattant, un vrai héros ou un Defender vide', function () {
    $campagne = creerGroupe();
    expect(fn () => app(DemarreurArene::class)->demarrer($campagne, idsMonstres('Gobelin')))->toThrow(RefusArene::class);

    $vide = app(GroupesArene::class)->ouvrir();
    expect(fn () => app(DemarreurArene::class)->demarrer($vide, idsMonstres('Gobelin')))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    [$arene] = areneAvecHeros(1);
    expect(fn () => app(DemarreurArene::class)->demarrer($arene, []))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    $intrus = creerHeros($this->joueur, creerGroupe('table-9'), 'Intrus', 3);
    $arene->personnages()->attach($intrus->id, ['ordre_initiative' => 9, 'actif' => true]);
    expect(fn () => app(DemarreurArene::class)->demarrer($arene, idsMonstres('Gobelin')))->toThrow(RefusArene::class)
        ->and(Quete::where('groupe_id', $arene->id)->count())->toBe(0);
});

it('ÉTANCHÉITÉ : démarrer, jouer puis purger un combat laisse les héros d\'arène identiques', function () {
    [$arene, $heros] = areneAvecHeros(2);
    $avant = array_map(fn (Personnage $h) => empreinteDuHeros($h->id), $heros);

    app(DemarreurArene::class)->demarrer($arene, idsMonstres('Gobelin', 'Gobelin'));
    $this->getJson("/api/groupes/{$arene->identifiant}/menu")->assertOk();
    app(GroupesArene::class)->purger($arene->refresh());

    expect(array_map(fn (Personnage $h) => empreinteDuHeros($h->id), $heros))->toBe($avant)
        ->and(Groupe::whereKey($arene->id)->exists())->toBeFalse()
        ->and(Quete::where('groupe_id', $arene->id)->count())->toBe(0)
        ->and(Personnage::whereNotNull('doublure_de')->count())->toBe(0);
});
