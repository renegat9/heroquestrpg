<?php

declare(strict_types=1);

use App\Engine\MotsClesEquipement;
use App\Events\SceneTable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\JournalCombat;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurFardeau;
use App\Partie\Salles;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * Le FARDEAU du porteur — Talisman du Savoir maudit (chantier RESTES-TALISMAN, 2026-10-29), dés injectés, vraies routes.
 *
 * Source : Prophecy of Telor p. 10, « The Talisman Bearer's Burden » — deux dés rouges au début de CHAQUE tour du porteur ;
 * total ≥ Mind actuel : rien ; total < Mind actuel : 1 PV de Body perdu ; +1 par héros dans la même salle ou le même
 * couloir, +2 par héros adjacent. Le menu du porteur déclenche le jet (précédent : le voile d'ombre).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

/** Le talisman de TEST : le fardeau seul, portée de la malédiction incluse (la ligne catalogue vient en dernier). */
function fardeauObjet(): Objet
{
    return Objet::create([
        'nom' => 'Talisman maudit de fardeau d\'essai',
        'categorie' => 'armure',
        'rarete' => 'unique',
        'prix_base' => 800,
        'emplacement' => 'talisman',
        'effet' => [
            'bonus_pv_mind_max' => 1,
            MotsClesEquipement::INAMOVIBLE => true,
            MotsClesEquipement::FARDEAU_DEBUT_DE_TOUR => [
                'des' => 2, 'perte_pv_body' => 1, 'compagnon_meme_zone' => 1, 'compagnon_adjacent' => 2,
            ],
        ],
    ]);
}

/**
 * Une quête : Albrecht (porteur, 5 de Mind, talisman de test PORTÉ) et `$compagnons` héros de plus.
 *
 * @return array{alice: mixed, groupe: Groupe, heros: Personnage, quete: Quete, etat: EtatPersonnageQuete, autres: list<Personnage>, etats: list<EtatPersonnageQuete>}
 */
function fardeauQuete(int $compagnons = 0): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['pv_mind' => 5, 'pv_mind_max' => 5]);
    $autres = [];

    foreach (array_slice(['Berthe', 'Conrad'], 0, $compagnons) as $i => $nom) {
        $autres[] = creerHeros($alice, $groupe, $nom, $i + 2);
    }

    $ligne = Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => fardeauObjet()->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
    (new Equipement)->equiper($heros->refresh(), $ligne);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatDe = fn (Personnage $p) => EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $p->id)->firstOrFail();

    // Le démarrage ouvre DÉJÀ le premier tour du porteur (il est le premier en initiative) : son fardeau y a été lancé
    // avec les dés RÉELS, avant le placement, et la manche est marquée. On efface ce premier jet (journal et marqueur)
    // et on remet des PV connus : chaque test part d'une manche neuve, aux dés qu'il injecte.
    Evenement::where('groupe_id', $groupe->id)->get()
        ->filter(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'fardeau_porteur')
        ->each(fn (Evenement $e) => $e->delete());
    $etatDe($heros)->update(['capacites_tour' => []]);
    $heros->update(['pv_body' => 8, 'pv_mind' => 5]);

    return [
        'alice' => $alice,
        'groupe' => $groupe,
        'heros' => $heros,
        'quete' => $quete,
        'etat' => $etatDe($heros),
        'autres' => $autres,
        'etats' => array_map($etatDe, $autres),
    ];
}

/**
 * Place le porteur (et un compagnon) dans la salle de départ, sur des cases libres, par balayage déterministe.
 * `seul` : pas de compagnon ; `adjacent` : compagnon au contact orthogonal ; `loin` : même salle, sans contact.
 */
function fardeauPlacer(Quete $quete, EtatPersonnageQuete $porteur, ?EtatPersonnageQuete $compagnon = null, string $ou = 'seul'): void
{
    $salle = $quete->carte->grille['salles'][0];
    $decalage = $ou === 'loin' ? 2 : 1;

    for ($y = (int) $salle['y'] + 1; $y < (int) $salle['y'] + (int) $salle['hauteur'] - 1; $y++) {
        for ($x = (int) $salle['x'] + 1; $x < (int) $salle['x'] + (int) $salle['largeur'] - $decalage; $x++) {
            if (! caseQueteLibre($quete, $x, $y)) {
                continue;
            }

            if ($ou === 'seul') {
                $porteur->update(['position_x' => $x, 'position_y' => $y]);

                return;
            }

            if (caseQueteLibre($quete, $x + $decalage, $y)) {
                $porteur->update(['position_x' => $x, 'position_y' => $y]);
                $compagnon?->update(['position_x' => $x + $decalage, 'position_y' => $y]);

                return;
            }
        }
    }

    throw new RuntimeException('Aucune case de la salle de départ ne convient à ce placement.');
}

/** Les payloads de fardeau du journal de la quête, dans l'ordre. */
function fardeauJournal(Groupe $groupe): array
{
    return Evenement::where('groupe_id', $groupe->id)->orderBy('sequence')->get()
        ->map(fn (Evenement $e) => $e->payload)
        ->filter(fn ($p) => is_array($p) && ($p['type'] ?? null) === 'fardeau_porteur')
        ->values()
        ->all();
}

it('sous son Mind : le porteur perd 1 PV de Body au début de son tour, et le jet est au journal', function () {
    $q = fardeauQuete();
    fardeauPlacer($q['quete'], $q['etat']);
    $avant = (int) $q['heros']->refresh()->pv_body;
    desFiges([1, 2, ...array_fill(0, 60, 6)]); // 1 + 2 = 3 < Mind 5

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    $jets = fardeauJournal($q['groupe']);

    expect($jets)->toHaveCount(1)
        ->and($jets[0]['des'])->toBe([1, 2])
        ->and($jets[0]['total'])->toBe(3)
        ->and($jets[0]['mind'])->toBe(5)
        ->and($jets[0]['perd_pv_body'])->toBeTrue()
        ->and((int) $q['heros']->refresh()->pv_body)->toBe($avant - 1);
});

it('au Mind ou au-dessus : rien ne se passe, et le jet reste au journal', function () {
    $q = fardeauQuete();
    fardeauPlacer($q['quete'], $q['etat']);
    $avant = (int) $q['heros']->refresh()->pv_body;
    desFiges([3, 3, ...array_fill(0, 60, 6)]); // 3 + 3 = 6 ≥ Mind 5

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    $jets = fardeauJournal($q['groupe']);

    expect($jets)->toHaveCount(1)
        ->and($jets[0]['total'])->toBe(6)
        ->and($jets[0]['perd_pv_body'])->toBeFalse()
        ->and((int) $q['heros']->refresh()->pv_body)->toBe($avant);
});

it('un compagnon DANS LA MÊME SALLE ajoute 1 au total', function () {
    $q = fardeauQuete(1);
    fardeauPlacer($q['quete'], $q['etat'], $q['etats'][0], 'loin');
    desFiges([2, 2, ...array_fill(0, 60, 6)]); // 2 + 2 = 4 ; +1 de compagnon = 5 : pas sous le Mind 5

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    $jets = fardeauJournal($q['groupe']);

    expect($jets[0]['compagnons'])->toBe(1)
        ->and($jets[0]['total'])->toBe(5)
        ->and($jets[0]['perd_pv_body'])->toBeFalse();
});

it('un compagnon ADJACENT ajoute 2 — et 1 de plus s\'il est aussi dans la salle (le texte dit « and »)', function () {
    $q = fardeauQuete(1);
    fardeauPlacer($q['quete'], $q['etat'], $q['etats'][0], 'adjacent');
    desFiges([1, 1, ...array_fill(0, 60, 6)]); // 1 + 1 = 2 ; +1 (salle) +2 (contact) = 5 : pas sous le Mind 5

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    $jets = fardeauJournal($q['groupe']);

    expect($jets[0]['compagnons'])->toBe(3)
        ->and($jets[0]['total'])->toBe(5)
        ->and($jets[0]['perd_pv_body'])->toBeFalse();
});

it('sans compagnon, le même jet de 2 perd : c\'est le bonus, pas le hasard, qui sauve', function () {
    $q = fardeauQuete(0);
    fardeauPlacer($q['quete'], $q['etat'], null, 'seul');
    desFiges([1, 1, ...array_fill(0, 60, 6)]);

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    $jets = fardeauJournal($q['groupe']);

    expect($jets[0]['compagnons'])->toBe(0)
        ->and($jets[0]['total'])->toBe(2)
        ->and($jets[0]['perd_pv_body'])->toBeTrue();
});

it('un compagnon ADJACENT TOMBÉ ne compte pas : il ne fait pas compagnie', function () {
    $q = fardeauQuete(1);
    $q['etats'][0]->update(['tombe' => true]);
    fardeauPlacer($q['quete'], $q['etat'], $q['etats'][0], 'adjacent');
    desFiges([1, 1, ...array_fill(0, 60, 6)]);

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    $jets = fardeauJournal($q['groupe']);

    expect($jets[0]['compagnons'])->toBe(0)
        ->and($jets[0]['perd_pv_body'])->toBeTrue();
});

it('une seule fois par manche : un menu régénéré ne relance pas le jet', function () {
    $q = fardeauQuete();
    fardeauPlacer($q['quete'], $q['etat']);
    $avant = (int) $q['heros']->refresh()->pv_body;
    desFiges([1, 2, ...array_fill(0, 60, 6)]);

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);
    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    expect(fardeauJournal($q['groupe']))->toHaveCount(1)
        ->and((int) $q['heros']->refresh()->pv_body)->toBe($avant - 1);
});

it('un porteur TOMBÉ ne lance pas : il ne joue pas, donc ne subit pas son début de tour', function () {
    $q = fardeauQuete();
    fardeauPlacer($q['quete'], $q['etat']);
    $q['etat']->update(['tombe' => true]);
    desFiges([1, 1, ...array_fill(0, 60, 6)]);

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    expect(fardeauJournal($q['groupe']))->toBe([]);
});

it('à 0 PV de Body, le porteur TOMBE : le fardeau peut lui faire perdre conscience', function () {
    $q = fardeauQuete();
    fardeauPlacer($q['quete'], $q['etat']);
    $q['heros']->update(['pv_body' => 1]);
    desFiges([1, 1, ...array_fill(0, 60, 6)]);

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    $jets = fardeauJournal($q['groupe']);

    expect($jets[0]['tombe'])->toBeTrue()
        ->and((int) $q['heros']->refresh()->pv_body)->toBe(0)
        ->and($q['etat']->refresh()->tombe)->toBeTrue();
});

it('une pièce au SAC ne pèse pas : le fardeau ne lit que la pièce PORTÉE', function () {
    $q = fardeauQuete();
    fardeauPlacer($q['quete'], $q['etat']);
    // État simulé : la ligne n'est plus portée (le lecteur ne doit regarder que les emplacements `Equipement::SLOTS`).
    Inventaire::where('personnage_id', $q['heros']->id)->where('emplacement', 'talisman')->update(['emplacement' => 'sac']);
    desFiges([1, 1, ...array_fill(0, 60, 6)]);

    expect(app(MoteurFardeau::class)->debutDeTour($q['groupe'], $q['quete'], $q['heros']->refresh(), $q['etat']->refresh()))
        ->toBeNull()
        ->and(fardeauJournal($q['groupe']))->toBe([]);
});

it('annoncé au fil et à la table : la ligne du fil et la scène `fardeau`', function () {
    $q = fardeauQuete();
    fardeauPlacer($q['quete'], $q['etat']);
    desFiges([1, 2, ...array_fill(0, 60, 6)]);
    Event::fake([SceneTable::class]);

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    $scenes = Event::dispatched(SceneTable::class)
        ->map(fn (array $appel) => $appel[0]->scene)
        ->filter(fn (array $scene) => ($scene['genre'] ?? null) === 'fardeau')
        ->values();

    $lignes = app(JournalCombat::class)->depuisResultat(fardeauJournal($q['groupe'])[0], 'Albrecht');

    expect($scenes)->toHaveCount(1)
        ->and($scenes[0]['issue']['ton'])->toBe('echec')
        ->and(collect($lignes)->pluck('texte')->implode(' '))->toContain('PV de Body');
});

it('la carte dit le jet entier AVANT qu\'on la porte : deux dés, le seuil, les compagnons', function () {
    $phrases = implode(' | ', MotsClesEquipement::avantages([
        MotsClesEquipement::FARDEAU_DEBUT_DE_TOUR => ['des' => 2, 'perte_pv_body' => 1, 'compagnon_meme_zone' => 1, 'compagnon_adjacent' => 2],
    ]));

    expect($phrases)->toContain('lance 2 dés rouges')
        ->and($phrases)->toContain('total sous son Mind actuel = 1 PV de Body perdu')
        ->and($phrases)->toContain('+1 par compagnon dans sa salle ou son couloir')
        ->and($phrases)->toContain('+2 par compagnon adjacent');
});

/**
 * Deux cases contiguës de COULOIR, libres, qui appartiennent au même couloir (sans porte entre elles) : [x, y, x2].
 *
 * @return array{0: int, 1: int, 2: int}
 */
function fardeauPaireDeCouloir(Quete $quete): array
{
    $salles = (array) $quete->carte->grille['salles'];

    foreach ((array) $quete->carte->grille['cases'] as $y => $ligne) {
        foreach (array_keys((array) $ligne) as $x) {
            $x = (int) $x;
            $y = (int) $y;

            if (Salles::indexDe($salles, $x, $y) !== null || Salles::indexDe($salles, $x + 1, $y) !== null
                || ! caseQueteLibre($quete, $x, $y) || ! caseQueteLibre($quete, $x + 1, $y)) {
                continue;
            }

            $meme = collect(Salles::couloirDe($salles, FabriqueGrille::pour($quete), $x, $y))
                ->contains(fn (array $c) => $c['x'] === $x + 1 && $c['y'] === $y);

            if ($meme) {
                return [$x, $y, $x + 1];
            }
        }
    }

    throw new RuntimeException('La carte n\'offre aucune paire de cases de couloir contiguës.');
}

it('un compagnon dans le MÊME COULOIR (et au contact) : +1 de zone, +2 d\'adjacence — la zone de couloir compte', function () {
    $q = fardeauQuete(1);
    [$x, $y, $x2] = fardeauPaireDeCouloir($q['quete']);
    $q['etat']->update(['position_x' => $x, 'position_y' => $y]);
    $q['etats'][0]->update(['position_x' => $x2, 'position_y' => $y]);
    desFiges([1, 1, ...array_fill(0, 60, 6)]); // 1 + 1 = 2 ; +1 (couloir) +2 (contact) = 5 : pas sous le Mind 5

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['heros']->id);

    $jets = fardeauJournal($q['groupe']);

    expect($jets[0]['compagnons'])->toBe(3)
        ->and($jets[0]['total'])->toBe(5)
        ->and($jets[0]['perd_pv_body'])->toBeFalse();
});
