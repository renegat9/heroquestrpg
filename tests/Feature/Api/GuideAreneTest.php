<?php

declare(strict_types=1);

use App\Models\Monstre;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\HallArene;
use App\Partie\Arene\ManoeuvreSolo;
use App\Partie\Arene\MonstresContreMonstres;
use App\Partie\Arene\PuissanceArene;
use App\Partie\Arene\TropheesArene;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;

/*
 * LE GUIDE DE L'ARÈNE (2026-11-12) : le bloc `arene` de GET /api/guide, lu par l'onglet « Arène » de /guide.
 *
 * Chaque chiffre du bloc vient d'un point de passage du jeu. Ces tests le confrontent à ces points de passage, dans les
 * deux sens quand une liste est déclarée : le roster du Defender, les alliés, les tuiles, la table de fouille, le hall.
 */
beforeEach(function () {
    $this->seed([MonstreSeeder::class, MercenaireSeeder::class]);
});

it('publie le bloc arene avec toutes ses clés, sans authentification', function () {
    $arene = $this->getJson('/api/guide')->assertOk()->json('arene');

    expect($arene)->toHaveKeys(['roster', 'allies', 'puissance', 'trophees', 'coffres', 'manoeuvre', 'hall'])
        ->and($arene['puissance'])->toHaveKeys(['heros', 'plafond_mvm'])
        ->and($arene['trophees'])->toHaveKeys(['tuiles', 'cases', 'elan', 'cases_elan', 'soin_potion'])
        ->and($arene['hall'])->toHaveKeys(['largeur', 'hauteur', 'cases', 'trophees', 'coffres', 'gravats', 'depart_challenger', 'depart_defender']);
});

it('liste le roster du Defender dans l\'ordre du livret, chaque puissance décidée par PuissanceArene', function () {
    $roster = $this->getJson('/api/guide')->json('arene.roster');

    expect(array_column($roster, 'nom_base'))->toBe(GroupesArene::ROSTER_DEFENDER)
        ->and($roster[0])->toHaveKeys(['id', 'nom_base', 'deplacement', 'attaque', 'defense', 'pv_body', 'puissance', 'a_distance', 'emprise']);

    $fiches = app(GroupesArene::class)->monstresDuRoster()->keyBy('nom_base');
    foreach ($roster as $ligne) {
        expect($ligne['puissance'])->toBe(app(PuissanceArene::class)->duMonstre($fiches[$ligne['nom_base']]));
    }
});

it('dit quelles figures tirent à distance, et la place de chaque ogre (deux cases)', function () {
    $roster = collect($this->getJson('/api/guide')->json('arene.roster'))->keyBy('nom_base');

    // Les trois versions à distance (Ogre Horde p. 8) : leur puissance vaut leur tir, leur attaque reste celle du contact.
    expect($roster->where('a_distance', true)->keys()->sort()->values()->all())
        ->toBe(['Archer squelette', 'Gobelin archer', 'Orque archer']);

    expect($roster['Gobelin archer']['attaque'])->toBe(1)
        ->and($roster['Gobelin archer']['puissance'])->toBe(2);

    // Les grands ogres couvrent deux cases ; l'Ogre guerrier tient sur UNE (René, 2026-10-29, MonstreSeeder). Le guide le dit
    // par l'emprise publiée, jamais par le nom.
    foreach (['Ogre champion', 'Ogre commandant'] as $ogre) {
        expect($roster[$ogre]['emprise'])->toBe(['l' => 1, 'h' => 2]);
    }
    expect($roster['Ogre guerrier']['emprise'])->toBe(['l' => 1, 'h' => 1])
        ->and($roster['Gobelin']['emprise'])->toBe(['l' => 1, 'h' => 1]);
});

it('publie les alliés recrutables du catalogue, puissance comme un héros', function () {
    $allies = $this->getJson('/api/guide')->json('arene.allies');
    $attendus = app(GroupesArene::class)->alliesDuCatalogue();

    expect(array_column($allies, 'id'))->toBe($attendus->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->and($allies)->not->toBeEmpty();

    foreach ($allies as $allie) {
        $mercenaire = $attendus->firstWhere('id', $allie['id']);
        expect($mercenaire->octroi_seul)->toBeFalse()
            ->and($allie['puissance'])->toBe(app(PuissanceArene::class)->duMercenaire($mercenaire))
            ->and($allie)->toHaveKeys(['id', 'nom', 'deplacement', 'attaque', 'attaque_distance', 'defense', 'pv_body', 'animal', 'puissance']);
    }
});

it('publie au guide les mêmes alliés et le même roster que le salon, dans le même ordre', function () {
    $salon = app(GroupesArene::class)->publier(app(GroupesArene::class)->ouvrir());
    $guide = $this->getJson('/api/guide')->json('arene');

    // Une seule forme pour les alliés (`GroupesArene::alliesPublies()`), une seule liste blanche pour le roster.
    expect($guide['allies'])->toBe($salon['allies'])
        ->and(array_column($guide['roster'], 'nom_base'))->toBe(array_column($salon['monstres'], 'nom_base'));
});

it('formule la puissance du héros et le plafond du Monster vs Monster depuis les constantes du jeu', function () {
    $puissance = $this->getJson('/api/guide')->json('arene.puissance');

    expect($puissance['heros'])->toBe(PuissanceArene::BASE_HEROS.' + sa meilleure attaque')
        ->and($puissance['plafond_mvm'])->toBe(MonstresContreMonstres::PUISSANCE_MAX);
});

it('publie les tuiles trophées : libellé et effet du vocabulaire, nombre = occurrences du lot (somme 9)', function () {
    $tuiles = $this->getJson('/api/guide')->json('arene.trophees.tuiles');

    expect(array_column($tuiles, 'cle'))->toBe(array_keys(TropheesArene::TUILES));

    foreach ($tuiles as $tuile) {
        $reference = TropheesArene::TUILES[$tuile['cle']];
        expect($tuile['libelle'])->toBe($reference['libelle'])
            ->and($tuile['effet'])->toBe($reference['effet'])
            ->and($tuile['nombre'])->toBe(count(array_filter(TropheesArene::LOT, fn (string $c) => $c === $tuile['cle'])));
    }

    expect(array_sum(array_column($tuiles, 'nombre')))->toBe(9)->toBe(count(TropheesArene::LOT));
    expect($this->getJson('/api/guide')->json('arene.trophees'))
        ->toMatchArray(['cases' => 6, 'elan' => TropheesArene::ELAN, 'cases_elan' => 2, 'soin_potion' => 2]);
});

it('couvre la table de fouille de 2 à 12 sans trou ni recouvrement, et dit chaque issue en phrase', function () {
    $coffres = $this->getJson('/api/guide')->json('arene.coffres');

    expect($coffres[0]['de'])->toBe(2)
        ->and(end($coffres)['a'])->toBe(12);

    $totaux = [];
    foreach ($coffres as $i => $ligne) {
        if ($i > 0) {
            expect($ligne['de'])->toBe($coffres[$i - 1]['a'] + 1);
        }
        expect($ligne['de'])->toBeLessThanOrEqual($ligne['a']);
        for ($t = $ligne['de']; $t <= $ligne['a']; $t++) {
            $totaux[] = $t;
        }
    }
    expect($totaux)->toBe(range(2, 12));

    expect($coffres[0]['texte'])->toBe('Piège explosif : −3 PV au fouilleur')
        ->and($coffres[0]['issue'])->toBe('piege')
        ->and($coffres[4]['texte'])->toBe('100 po à la bourse du combat')
        ->and($coffres[3]['issue'])->toBe('objet')
        ->and($coffres[3]['texte'])->toContain('Hache de bataille en os');
});

it('place le hall : six trophées, deux coffres, deux gravats, deux tuiles de départ de seize cases', function () {
    $hall = $this->getJson('/api/guide')->json('arene.hall');

    expect($hall['trophees'])->toHaveCount(6)
        ->and($hall['coffres'])->toHaveCount(2)
        ->and($hall['gravats'])->toHaveCount(2)
        ->and($hall['depart_challenger'])->toHaveCount(16)
        ->and($hall['depart_defender'])->toHaveCount(16);

    // La carte est celle du démarreur : une case de sol pour les trophées et les coffres, de la roche pour les gravats.
    expect($hall['cases'])->toHaveCount($hall['hauteur']);
    foreach ([...$hall['trophees'], ...$hall['coffres']] as $case) {
        expect($hall['cases'][$case['y']][$case['x']])->toBe('s');
    }
    foreach ($hall['gravats'] as $case) {
        expect($hall['cases'][$case['y']][$case['x']])->toBe('m');
    }

    // Les coordonnées publiées sont celles que le démarreur et le tirage de tuiles utilisent.
    $hallArene = app(HallArene::class);
    $trophees = array_map(fn (array $c) => $hallArene->caseDeLaSalle($c[0], $c[1]), TropheesArene::CASES);
    expect(array_map(fn (array $c) => [$c['x'], $c['y']], $hall['trophees']))->toBe($trophees);
});

it('publie la manœuvre du combattant seul depuis sa constante', function () {
    expect($this->getJson('/api/guide')->json('arene.manoeuvre'))->toBe(['des_attaque' => ManoeuvreSolo::DES_ATTAQUE]);
});

it('chaque nom du roster existe au catalogue, et le Fimir de 1989 n\'y figure pas', function () {
    // Le roster est une liste blanche déclarée : ses quatorze noms doivent tous exister dans la base.
    $noms = collect($this->getJson('/api/guide')->json('arene.roster'))->pluck('nom_base')->all();

    expect(Monstre::whereIn('nom_base', GroupesArene::ROSTER_DEFENDER)->distinct()->pluck('nom_base')->all())
        ->toEqualCanonicalizing(GroupesArene::ROSTER_DEFENDER)
        ->and($noms)->not->toContain('Fimir');
});
