<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\MoteurPortes;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * DEUX PORTES CLOSES AU CONTACT D'UN MÊME HÉROS (partie réelle, 2026-10-17).
 * Un héros dans un coin entre deux portes closes ne voyait qu'UNE option « Ouvrir la
 * porte » : le menu ne donnait que la première, le harnais affichait l'autre « à
 * portée », et un choix forgé sur elle était refusé (422). Le menu offre désormais
 * UNE OPTION PAR PORTE, et le résolveur n'accepte que ce que cette liste contient.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class]);
});

/** Un héros seul, quête démarrée : [alice, groupe, héros, quête, état]. */
function demarrerQueteDuCoin(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    return [$alice, $groupe, $hero, $quete, $etat];
}

/**
 * Pose le héros dans le COIN intérieur (xMin+1, yMin+1) d'une salle assez grande, et
 * deux portes sur ses deux murs : NORD, sur le mur du haut (case (xMin+1, yMin)), et
 * OUEST, sur le mur de gauche (case (xMin, yMin+1)). Vérifié ci-dessous, pas supposé :
 * si la carte ne donne pas ce coin-là, le test échoue en le disant.
 *
 * @param  array<string, mixed>  $surNord  surcharge de la porte nord (état, verrou…)
 * @param  array<string, mixed>  $surOuest  surcharge de la porte ouest
 * @return array{hx: int, hy: int, nord: array<string, mixed>, ouest: array<string, mixed>}
 */
function placerHeroAuCoin(Quete $quete, EtatPersonnageQuete $etat, array $surNord = [], array $surOuest = []): array
{
    $salle = collect((array) $quete->carte->grille['salles'])
        ->first(fn ($s) => (int) $s['largeur'] >= 3 && (int) $s['hauteur'] >= 3);
    expect($salle)->not->toBeNull('aucune salle assez grande pour un coin — scénario invalide.');

    $xMin = (int) $salle['x'];
    $yMin = (int) $salle['y'];

    $nord = array_merge(['x' => $xMin + 1, 'y' => $yMin, 'cote' => 's', 'etat' => 'fermee'], $surNord);
    $ouest = array_merge(['x' => $xMin, 'y' => $yMin + 1, 'cote' => 'e', 'etat' => 'fermee'], $surOuest);

    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['portes'] = [$nord, $ouest];
    $grille['leviers'] = [];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');

    $etat->update(['position_x' => $xMin + 1, 'position_y' => $yMin + 1]);

    // Le coin est bien celui qu'on croit : chaque embrasure est la case du mur, à
    // distance 1 du héros. Sinon ce test ne prouverait rien.
    $salles = (array) $quete->carte->grille['salles'];
    expect(Grille::caseEmbrasure($nord, $salles))->toBe(['x' => $xMin + 1, 'y' => $yMin])
        ->and(Grille::caseEmbrasure($ouest, $salles))->toBe(['x' => $xMin, 'y' => $yMin + 1]);

    return ['hx' => $xMin + 1, 'hy' => $yMin + 1, 'nord' => $nord, 'ouest' => $ouest];
}

/** L'identifiant d'option d'un geste sur cette porte (`ouvrir_porte_{x}_{y}_{cote}`…). */
function idGestePorte(string $geste, array $porte): string
{
    return "{$geste}_{$porte['x']}_{$porte['y']}_{$porte['cote']}";
}

/** Les options du menu courant du héros, libellés compris (le menu est recalculé). */
function optionsDuMenu(\App\Models\Groupe $groupe, JoueurAuthentifiable $alice, \App\Models\Personnage $hero): array
{
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    return (array) (Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'] ?? []);
}

/** Le libellé de l'option d'identifiant $id parmi ces options, ou null. */
function libelleDe(array $options, string $id): ?string
{
    $option = collect($options)->firstWhere('id', $id);

    return $option['libelle'] ?? null;
}

/** Les identifiants d'option du menu courant du héros. */
function idsMenuDuHeros(\App\Models\Groupe $groupe, JoueurAuthentifiable $alice, \App\Models\Personnage $hero): array
{
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    return collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'] ?? [])
        ->pluck('id')->all();
}

it('offre UNE option « Ouvrir la porte » PAR porte close : un héros dans un coin en voit deux', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteDuCoin();
    ['nord' => $nord, 'ouest' => $ouest] = placerHeroAuCoin($quete, $etat);

    $options = optionsDuMenu($groupe, $alice, $hero);
    $ids = array_column($options, 'id');

    expect($ids)->toContain(idGestePorte('ouvrir_porte', $nord))
        ->and($ids)->toContain(idGestePorte('ouvrir_porte', $ouest));

    // Deux portes offertes : chaque libellé dit LAQUELLE, par sa direction vue de la case du héros.
    expect(libelleDe($options, idGestePorte('ouvrir_porte', $nord)))->toBe('Ouvrir la porte au nord')
        ->and(libelleDe($options, idGestePorte('ouvrir_porte', $ouest)))->toBe("Ouvrir la porte à l'ouest");

    // Chacune ouvre SA porte, et seulement la sienne.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => idGestePorte('ouvrir_porte', $ouest)])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'ouvrir_porte')
        ->assertJsonPath('resultat.porte.x', $ouest['x'])
        ->assertJsonPath('resultat.porte.y', $ouest['y']);

    $portes = $quete->fresh()->carte->grille['portes'];
    expect($portes[0]['etat'])->toBe('fermee')
        ->and($portes[1]['etat'])->toBe('ouverte');

    // La porte nord reste offerte, et s'ouvre à son tour.
    expect(idsMenuDuHeros($groupe, $alice, $hero))->toContain(idGestePorte('ouvrir_porte', $nord));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => idGestePorte('ouvrir_porte', $nord)])
        ->assertStatus(202)
        ->assertJsonPath('resultat.porte.x', $nord['x'])
        ->assertJsonPath('resultat.porte.y', $nord['y']);

    expect($quete->fresh()->carte->grille['portes'][0]['etat'])->toBe('ouverte');
});

it('la Bénédiction de l\'Oracle se porte sur CHAQUE porte, et ne les ouvre pas', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteDuCoin();
    ['nord' => $nord, 'ouest' => $ouest] = placerHeroAuCoin($quete, $etat);
    $hero->update(['benediction_oracle' => true]);

    $options = optionsDuMenu($groupe, $alice, $hero);
    $ids = array_column($options, 'id');

    expect($ids)->toContain(idGestePorte('oracle_salle', $nord))
        ->and($ids)->toContain(idGestePorte('oracle_salle', $ouest))
        ->and(libelleDe($options, idGestePorte('oracle_salle', $nord)))->toBe("Bénédiction de l'Oracle — révéler la salle au nord")
        ->and(libelleDe($options, idGestePorte('oracle_salle', $ouest)))->toBe("Bénédiction de l'Oracle — révéler la salle à l'ouest");

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => idGestePorte('oracle_salle', $ouest)])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'oracle_salle');

    // Révéler une salle n'ouvre aucune porte : les deux restent closes.
    $portes = $quete->fresh()->carte->grille['portes'];
    expect($portes[0]['etat'])->toBe('fermee')
        ->and($portes[1]['etat'])->toBe('fermee');
});

it('une porte SECRÈTE non trouvée reste un mur : ni ouvrable, ni révélable par l\'Oracle', function () {
    // La SECRÈTE est la seule porte au contact (le nord, déjà ouvert, est hors jeu) : c'est
    // le cas où l'ancien menu la retenait et offrait l'Oracle sur un passage inconnu.
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteDuCoin();
    ['ouest' => $ouest] = placerHeroAuCoin($quete, $etat, ['etat' => 'ouverte'], ['etat' => 'secrete', 'revele' => false]);
    $hero->update(['benediction_oracle' => true]);

    $ids = idsMenuDuHeros($groupe, $alice, $hero);

    // Aucune porte offerte : la secrète n'existe pas encore pour le héros, et la seule
    // autre est ouverte. Ni ouverture, ni Oracle sur un passage que personne n'a trouvé.
    expect($ids)->not->toContain(idGestePorte('ouvrir_porte', $ouest))
        ->and($ids)->not->toContain(idGestePorte('oracle_salle', $ouest))
        ->and(array_values(array_filter($ids, fn ($id) => str_starts_with($id, 'oracle_salle_') || str_starts_with($id, 'ouvrir_porte_'))))
        ->toBe([]);
});

it('une porte de pierre s\'offre PAR porte, et ne s\'ouvre jamais à main nue', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteDuCoin();
    $pierre = ['verrou' => ['type' => 'pierre']];
    ['nord' => $nord, 'ouest' => $ouest] = placerHeroAuCoin($quete, $etat, $pierre, $pierre);
    $hero->update(['des_attaque' => 3]);

    $options = optionsDuMenu($groupe, $alice, $hero);
    $ids = array_column($options, 'id');

    expect($ids)->toContain(idGestePorte('forcer_porte_pierre', $nord))
        ->and($ids)->toContain(idGestePorte('forcer_porte_pierre', $ouest))
        ->and($ids)->not->toContain(idGestePorte('ouvrir_porte', $nord))
        ->and(libelleDe($options, idGestePorte('forcer_porte_pierre', $nord)))
        ->toBe("Pousser la porte de pierre au nord — 3 dés d'attaque (2 crânes requis)")
        ->and(libelleDe($options, idGestePorte('forcer_porte_pierre', $ouest)))
        ->toBe("Pousser la porte de pierre à l'ouest — 3 dés d'attaque (2 crânes requis)");
});

it('portesFermeesAdjacentes rend les portes closes du contact, dans l\'ordre, et ni les ouvertes ni les secrètes', function () {
    // Coin (1,1) d'une salle 0..5 : nord (1,0) et ouest (0,1) sont les murs du contact.
    $carte = new Carte();
    $carte->grille = [
        'salles' => [['x' => 0, 'y' => 0, 'largeur' => 6, 'hauteur' => 6]],
        'portes' => [
            ['x' => 1, 'y' => 0, 'cote' => 's', 'etat' => 'fermee'],    // 0 : close, au contact
            ['x' => 0, 'y' => 1, 'cote' => 'e', 'etat' => 'secrete'],   // 1 : secrète non trouvée
            ['x' => 1, 'y' => 0, 'cote' => 's', 'etat' => 'ouverte'],   // 2 : ouverte
        ],
    ];

    $closes = app(MoteurPortes::class)->portesFermeesAdjacentes($carte, 1, 1);

    expect($closes)->toHaveCount(1)
        ->and($closes[0]['index'])->toBe(0)
        ->and(app(MoteurPortes::class)->portesDePierreAdjacentes($carte, 1, 1))->toBe([]);
});

it('une seule porte offerte garde son libellé d\'origine : pas de direction', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteDuCoin();
    // Le nord est déjà ouvert : seule l'ouest reste offerte, et une seule porte ne se nomme pas.
    ['ouest' => $ouest] = placerHeroAuCoin($quete, $etat, ['etat' => 'ouverte']);
    $hero->update(['benediction_oracle' => true]);

    $options = optionsDuMenu($groupe, $alice, $hero);

    expect(libelleDe($options, idGestePorte('ouvrir_porte', $ouest)))->toBe('Ouvrir la porte')
        ->and(libelleDe($options, idGestePorte('oracle_salle', $ouest)))->toBe("Bénédiction de l'Oracle — révéler la salle");
});

it('avec plusieurs portes, le « (clé) » reste en fin de libellé, après la direction', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteDuCoin();
    $objetId = (int) Objet::query()->orderBy('id')->value('id');
    ['nord' => $nord, 'ouest' => $ouest] = placerHeroAuCoin($quete, $etat, [], [
        'etat' => 'verrouillee', 'verrou' => ['type' => 'cle', 'objet_id' => $objetId],
    ]);
    Inventaire::create(['personnage_id' => $hero->id, 'objet_id' => $objetId, 'emplacement' => 'sac', 'quantite' => 1]);

    $options = optionsDuMenu($groupe, $alice, $hero);

    expect(libelleDe($options, idGestePorte('ouvrir_porte', $nord)))->toBe('Ouvrir la porte au nord')
        ->and(libelleDe($options, idGestePorte('ouvrir_porte', $ouest)))->toBe("Ouvrir la porte à l'ouest (clé)");
});

it('un libellé à direction survit au menu publié et relu, sans aucun appel réseau : aucune IA dans le menu', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerQueteDuCoin();
    ['nord' => $nord, 'ouest' => $ouest] = placerHeroAuCoin($quete, $etat);

    $appelsAvant = count(Http::recorded());

    // Bâtir le menu (publication : `GenererMenu::publier()`) puis le servir (`GET /menu`).
    $options = optionsDuMenu($groupe, $alice, $hero);
    $lu = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options');

    // Rien ne sort du moteur pour fabriquer ni servir le menu : aucun appel LLM ni réseau,
    // donc aucun habillage possible d'un libellé mécanique.
    expect(count(Http::recorded()))->toBe($appelsAvant)
        ->and(libelleDe($options, idGestePorte('ouvrir_porte', $nord)))->toBe('Ouvrir la porte au nord')
        ->and(libelleDe($lu, idGestePorte('ouvrir_porte', $nord)))->toBe('Ouvrir la porte au nord')
        ->and(libelleDe($lu, idGestePorte('ouvrir_porte', $ouest)))->toBe("Ouvrir la porte à l'ouest");
});
