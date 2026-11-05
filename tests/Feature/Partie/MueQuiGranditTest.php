<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Images\BibliothequeImages;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Partie\ResolveurTour;
use App\Partie\SceneDeTable;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * LA MUE QUI GRANDIT (2026-10-31, « Continue avec les correctifs ») : Gretzl la Porte-Fléau (1×1) devient Demonspider (1×2)
 * à 0 Body, puis Demonape (1×2). Une forme de deux cases ne se pose jamais sur une figure : si son emprise ne tient pas sur
 * l'ancre, elle se pose sur la case la plus proche où elle tient, dans une salle découverte, et la table le dit
 * (« in the nearest open square to her », Delthrak F9907 PDF 18). Règle : `MoteurSynchroformes::ancrer()`.
 *
 * EN JEU : le coup part par `ResolveurTour::frapper()`, le résolveur que la manette appelle, dés injectés. Les mues de
 * même emprise, qui ne doivent jamais bouger, passent par le point de passage unique de la mort (`infligerAMonstre()`).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, GabaritQueteSeeder::class,
        MobilierSeeder::class, MonstreSeeder::class, ObjetSeeder::class, PiegeSeeder::class, SortDreadSeeder::class,
        SortSeeder::class, TuileSeeder::class]);
});

/** Les six figures passées à 1×2 le 2026-10-31 (seeder + migration `2026_10_31_140000_six_figures_deux_cases`). */
function mueLesSix(): array
{
    return ['Doralf', 'Gruzbella Hammerhand', 'Gruzbella Déterminée', 'Gruzbella Imprudente', 'Demonspider', 'Demonape'];
}

/**
 * Une quête à la main, sur un dessin (`m` = roche, `s` = sol) et une liste de salles. La salle 0 est toujours découverte
 * (`Quete::sallesDecouvertes()`) ; les autres ne le sont que si `$decouvertes` les nomme.
 *
 * @param  list<string>  $dessin
 * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
 * @param  list<int>  $decouvertes
 */
function mueQuete(array $dessin, array $salles, array $decouvertes = []): Quete
{
    $groupe = creerGroupe('table-mue-'.uniqid());
    $largeur = strlen($dessin[0]);
    $hauteur = count($dessin);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — la mue qui grandit',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
        'salles_decouvertes' => $decouvertes,
    ]);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'cases' => array_map(fn (string $ligne) => str_split($ligne), $dessin),
            'salles' => $salles,
            'portes' => [], 'leviers' => [], 'pieges' => [], 'mobilier' => [], 'epreuves' => [], 'terrain' => [],
            'spawn_heros' => [['x' => 1, 'y' => 1]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    return $quete->fresh();
}

/** Un héros seul sur sa case (x, y). */
function mueHeros(Quete $quete, int $x, int $y): array
{
    $personnage = creerHeros(connecterJoueur('mue-'.uniqid()), $quete->groupe, 'Albrecht', 1);

    $etat = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $personnage->id,
        'position_x' => $x, 'position_y' => $y, 'tombe' => false, 'a_joue' => false,
    ]);

    return [$personnage, $etat];
}

/**
 * Une figure révélée et active du catalogue `$nomBase` en (x, y). `$reactionsUtilisees` : défenses déjà jouées à cette
 * rencontre (la Résilience de Gretzl, par exemple) ; `$pvBody` : PV de départ, sinon ceux du catalogue.
 *
 * @param  list<string>  $reactionsUtilisees
 */
function mueFigure(Quete $quete, string $nomBase, int $x, int $y, array $reactionsUtilisees = [], ?int $pvBody = null): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nomBase)->firstOrFail();

    $instance = InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => $catalogue->id,
        'pv_body' => $pvBody ?? $catalogue->pv_body,
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

    $instance->update(['capacites_reactives_utilisees' => $reactionsUtilisees]);

    return $instance->fresh()->load('monstre');
}

/**
 * Le coup du héros qui achève la figure, par le résolveur réel (`ResolveurTour::frapper()`). Dés injectés : des crânes, qui
 * touchent partout et ne laissent aucune parade.
 */
function mueFrapper(Personnage $heros, EtatPersonnageQuete $etat, InstanceMonstre $cible): array
{
    desFiges(array_fill(0, 60, 1));

    return app(ResolveurTour::class)->frapper(
        $cible->quete->groupe->fresh(),
        $cible->quete->fresh()->load('carte'),
        $etat->fresh(),
        $heros->fresh(),
        $cible->fresh()->load('monstre'),
        acteur: ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom],
    );
}

/** Les cases « x,y » couvertes par une emprise ancrée en (x, y) — l'ancre est le coin haut-gauche. */
function mueCasesDe(int $x, int $y, array $emprise): array
{
    $cases = [];

    for ($dy = 0; $dy < $emprise['h']; $dy++) {
        for ($dx = 0; $dx < $emprise['l']; $dx++) {
            $cases[] = ($x + $dx).','.($y + $dy);
        }
    }

    return $cases;
}

/** La figure partage-t-elle une case avec un héros debout ou une autre figure active ? */
function mueChevauche(InstanceMonstre $figure): bool
{
    $figure = $figure->fresh()->load('monstre');
    $mes = mueCasesDe((int) $figure->position_x, (int) $figure->position_y, $figure->monstre->emprise());
    $occupees = [];

    foreach ($figure->quete->etatsPersonnages()->get() as $etat) {
        $occupees[] = (int) $etat->position_x.','.(int) $etat->position_y;
    }

    $autres = $figure->quete->instancesMonstres()->where('etat', 'actif')->whereKeyNot($figure->id)->with('monstre')->get();

    foreach ($autres as $autre) {
        $occupees = array_merge($occupees, mueCasesDe((int) $autre->position_x, (int) $autre->position_y, $autre->monstre->emprise()));
    }

    return array_intersect($mes, $occupees) !== [];
}

it('EN JEU — Gretzl tuée contre un mur : Demonspider se pose sur la case libre la plus proche, et la table le dit', function () {
    // Gretzl en (2,2), roche sous elle : sa case basse (2,3) ne la loge pas. Le héros en (3,2) la frappe.
    // Candidats à distance 1 : (1,1) et (2,1) — à égalité, l'ordre de lecture (y puis x) prend (1,1).
    $quete = mueQuete(
        ['mmmmmmm', 'msssssm', 'msssssm', 'mmmmmmm'],
        [['x' => 1, 'y' => 1, 'largeur' => 5, 'hauteur' => 2]],
    );
    [$heros, $etat] = mueHeros($quete, 3, 2);
    $gretzl = mueFigure($quete, 'Gretzl la Porte-Fléau', 2, 2, ['ignore_degats_attaque'], 1);

    $payload = mueFrapper($heros, $etat, $gretzl);

    expect($payload['cible_vaincue'])->toBeFalse()
        ->and($payload['changement_phase'])->toMatchArray(['avant' => 'Gretzl la Porte-Fléau', 'apres' => 'Demonspider'])
        ->and($payload['changement_phase']['deplacement'])->toBe(['de' => ['x' => 2, 'y' => 2], 'vers' => ['x' => 1, 'y' => 1]]);

    $gretzl->refresh()->load('monstre');
    expect($gretzl->monstre->nom_base)->toBe('Demonspider')
        ->and($gretzl->etat)->toBe('actif')
        ->and([(int) $gretzl->position_x, (int) $gretzl->position_y])->toBe([1, 1], 'écrit en base, pas seulement rendu')
        ->and(mueChevauche($gretzl))->toBeFalse('deux figures ne se chevauchent jamais');

    // Annoncé au fil (journal) ET à la table (scène de transformation).
    $lignes = collect(app(JournalCombat::class)->depuisResultat($payload, 'Albrecht'))->pluck('texte')->implode(' ¦ ');
    expect($lignes)->toContain('Faute de place sur sa case, Demonspider se pose sur les cases libres les plus proches.');

    $scenes = (new SceneDeTable(app(BibliothequeImages::class)))->depuisResultat($payload, $heros);
    $transformation = collect($scenes)->firstWhere('genre', 'transformation');
    expect($transformation['sous_titre'])->toContain('se pose plus loin faute de place');
});

it('EN JEU — Gretzl tuée en salle libre : elle reste sur sa case, emprise 1 × 2, et rien n\'est annoncé de déplacement', function () {
    $quete = mueQuete(
        ['mmmmmmm', 'msssssm', 'msssssm', 'msssssm', 'mmmmmmm'],
        [['x' => 1, 'y' => 1, 'largeur' => 5, 'hauteur' => 3]],
    );
    [$heros, $etat] = mueHeros($quete, 3, 2);
    $gretzl = mueFigure($quete, 'Gretzl la Porte-Fléau', 2, 2, ['ignore_degats_attaque'], 1);

    $payload = mueFrapper($heros, $etat, $gretzl);

    expect($payload['changement_phase'])->toMatchArray(['avant' => 'Gretzl la Porte-Fléau', 'apres' => 'Demonspider'])
        ->and($payload['changement_phase'])->not->toHaveKey('deplacement');

    $gretzl->refresh()->load('monstre');
    expect($gretzl->monstre->nom_base)->toBe('Demonspider')
        ->and([(int) $gretzl->position_x, (int) $gretzl->position_y])->toBe([2, 2])
        ->and($gretzl->monstre->emprise())->toBe(['l' => 1, 'h' => 2])
        ->and(mueChevauche($gretzl))->toBeFalse();

    $lignes = collect(app(JournalCombat::class)->depuisResultat($payload, 'Albrecht'))->pluck('texte')->implode(' ¦ ');
    expect($lignes)->not->toContain('Faute de place');
});

it('aucune case où la forme tienne sur la carte découverte : elle n\'est pas prise, la figure tombe, rien ne chevauche', function () {
    // Une seule case de sol : Demonspider ne peut tenir nulle part, ni sur (1,1) ni ailleurs.
    $quete = mueQuete(['mmm', 'msm', 'mmm'], [['x' => 1, 'y' => 1, 'largeur' => 1, 'hauteur' => 1]]);
    $gretzl = mueFigure($quete, 'Gretzl la Porte-Fléau', 1, 1, ['ignore_degats_attaque'], 1);

    $resultat = app(MoteurDegats::class)->infligerAMonstre($gretzl, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($resultat['vaincu'])->toBeTrue()
        ->and($resultat['changement_phase'])->toBeNull();

    $gretzl->refresh()->load('monstre');
    expect($gretzl->etat)->toBe('vaincu')
        ->and($gretzl->monstre->nom_base)->toBe('Gretzl la Porte-Fléau', 'la forme n\'est pas prise')
        ->and([(int) $gretzl->position_x, (int) $gretzl->position_y])->toBe([1, 1]);
});

it('EN JEU — une case d\'une salle NON découverte n\'est jamais la place d\'une mue ; ouverte, elle l\'est', function () {
    // Salle 0 : (1,1)-(2,1), où se tiennent Gretzl et le héros. Salle 1 : (3..5, 1..2), fermée. La seule place qui tient
    // (3,1) est dans la salle 1. Fermée : la figure tombe. Ouverte : elle s'y pose, à distance 2.
    $dessin = ['mmmmmmm', 'msssssm', 'mmmsssm', 'mmmmmmm'];
    $salles = [
        ['x' => 1, 'y' => 1, 'largeur' => 2, 'hauteur' => 1],
        ['x' => 3, 'y' => 1, 'largeur' => 3, 'hauteur' => 2],
    ];

    $fermee = mueQuete($dessin, $salles, []);
    [$heros, $etat] = mueHeros($fermee, 2, 1);
    $gretzl = mueFigure($fermee, 'Gretzl la Porte-Fléau', 1, 1, ['ignore_degats_attaque'], 1);

    $payload = mueFrapper($heros, $etat, $gretzl);

    expect($payload['cible_vaincue'])->toBeTrue()
        ->and($payload['changement_phase'])->toBeNull();
    expect([(int) $gretzl->fresh()->position_x, (int) $gretzl->fresh()->position_y])->toBe([1, 1]);

    $ouverte = mueQuete($dessin, $salles, [1]);
    [$heros2, $etat2] = mueHeros($ouverte, 2, 1);
    $gretzl2 = mueFigure($ouverte, 'Gretzl la Porte-Fléau', 1, 1, ['ignore_degats_attaque'], 1);

    $payload2 = mueFrapper($heros2, $etat2, $gretzl2);

    expect($payload2['changement_phase']['deplacement'])->toBe(['de' => ['x' => 1, 'y' => 1], 'vers' => ['x' => 3, 'y' => 1]])
        ->and($gretzl2->fresh()->load('monstre')->monstre->nom_base)->toBe('Demonspider')
        ->and(mueChevauche($gretzl2->fresh()))->toBeFalse();
});

it('EN JEU — salle pleine : la forme se pose dans le COULOIR le plus proche, et personne n\'est repoussé (René, 2026-11-05)', function () {
    // Salle 0 : une rangée (1..3, 1), Gretzl en (1,1), deux héros en (2,1) et (3,1). Un couloir descend de (3,2) à (3,3).
    // Demonspider (1 × 2) ne tient nulle part dans la salle sans écarter un héros : René garde la règle du livret (« the
    // nearest open square »), la forme se pose dans le couloir ou la salle explorée la plus proche, à distance 2.
    $quete = mueQuete(
        ['mmmmm', 'msssm', 'mmmsm', 'mmmsm', 'mmmmm'],
        [['x' => 1, 'y' => 1, 'largeur' => 3, 'hauteur' => 1]],
    );
    [$heros, $etat] = mueHeros($quete, 2, 1);
    [, $etatVoisin] = mueHeros($quete, 3, 1);
    $gretzl = mueFigure($quete, 'Gretzl la Porte-Fléau', 1, 1, ['ignore_degats_attaque'], 1);

    $payload = mueFrapper($heros, $etat, $gretzl);

    expect($payload['changement_phase'])->toMatchArray(['avant' => 'Gretzl la Porte-Fléau', 'apres' => 'Demonspider'])
        ->and($payload['changement_phase']['deplacement'])->toBe(['de' => ['x' => 1, 'y' => 1], 'vers' => ['x' => 3, 'y' => 2]])
        ->and(mueChevauche($gretzl->fresh()))->toBeFalse();

    // Les héros n'ont pas bougé : aucune poussée.
    expect([(int) $etat->fresh()->position_x, (int) $etat->fresh()->position_y])->toBe([2, 1])
        ->and([(int) $etatVoisin->fresh()->position_x, (int) $etatVoisin->fresh()->position_y])->toBe([3, 1]);
});

it('une mue de MÊME emprise ne bouge pas : Gruzbella Hammerhand (1 × 2) → Déterminée (1 × 2)', function () {
    $quete = mueQuete(
        ['mmmmmmm', 'msssssm', 'msssssm', 'msssssm', 'mmmmmmm'],
        [['x' => 1, 'y' => 1, 'largeur' => 5, 'hauteur' => 3]],
    );
    $gruzbella = mueFigure($quete, 'Gruzbella Hammerhand', 2, 1, ['ignore_degats_attaque'], 1);
    expect($gruzbella->monstre->emprise())->toBe(['l' => 1, 'h' => 2]);

    $resultat = app(MoteurDegats::class)->infligerAMonstre($gruzbella, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($resultat['changement_phase'])->toMatchArray(['avant' => 'Gruzbella Hammerhand', 'apres' => 'Gruzbella Déterminée'])
        ->and($resultat['changement_phase'])->not->toHaveKey('deplacement');
    expect([(int) $gruzbella->fresh()->position_x, (int) $gruzbella->fresh()->position_y])->toBe([2, 1]);
});

it('une mue de MÊME emprise ne bouge pas : Demonspider (1 × 2) → Demonape (1 × 2)', function () {
    $quete = mueQuete(
        ['mmmmmmm', 'msssssm', 'msssssm', 'msssssm', 'mmmmmmm'],
        [['x' => 1, 'y' => 1, 'largeur' => 5, 'hauteur' => 3]],
    );
    $demonspider = mueFigure($quete, 'Demonspider', 2, 1, ['ignore_degats_attaque'], 1);

    $resultat = app(MoteurDegats::class)->infligerAMonstre($demonspider, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($resultat['changement_phase'])->toMatchArray(['avant' => 'Demonspider', 'apres' => 'Demonape'])
        ->and($resultat['changement_phase'])->not->toHaveKey('deplacement');
    expect([(int) $demonspider->fresh()->position_x, (int) $demonspider->fresh()->position_y])->toBe([2, 1]);
});

it('une mue de MÊME emprise ne bouge pas : Spawn of the Pit (1 × 1) → déchaîné (1 × 1)', function () {
    $quete = mueQuete(
        ['mmmmmmm', 'msssssm', 'msssssm', 'msssssm', 'mmmmmmm'],
        [['x' => 1, 'y' => 1, 'largeur' => 5, 'hauteur' => 3]],
    );
    $spawn = mueFigure($quete, 'Spawn of the Pit', 2, 2, [], 1);

    $resultat = app(MoteurDegats::class)->infligerAMonstre($spawn, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($resultat['changement_phase'])->toMatchArray(['avant' => 'Spawn of the Pit', 'apres' => 'Spawn of the Pit déchaîné'])
        ->and($resultat['changement_phase'])->not->toHaveKey('deplacement');
    expect([(int) $spawn->fresh()->position_x, (int) $spawn->fresh()->position_y])->toBe([2, 2]);
});

it('les six figures sont 1 × 2 au catalogue, et leur jeton est un OVALE (registre `symboles_monstres`)', function () {
    foreach (mueLesSix() as $nom) {
        expect(Monstre::where('nom_base', $nom)->firstOrFail()->emprise())->toBe(['l' => 1, 'h' => 2], $nom)
            ->and(config('symboles_monstres.ovales'))->toContain($nom)
            ->and(config('symboles_monstres.absents'))->not->toHaveKey($nom)
            ->and(app(BibliothequeImages::class)->formeSymboleMonstre($nom))->toBe('ovale', $nom);
    }
});

it('la migration `2026_10_31_140000_six_figures_deux_cases` pose 1 × 2 sur les six déjà en base, et revient en arrière', function () {
    $migration = require database_path('migrations/2026_10_31_140000_six_figures_deux_cases.php');

    DB::table('monstres')->whereIn('nom_base', mueLesSix())->update(['grande_taille' => null]);

    $migration->up();
    foreach (mueLesSix() as $nom) {
        expect(Monstre::where('nom_base', $nom)->firstOrFail()->emprise())->toBe(['l' => 1, 'h' => 2], $nom);
    }

    $migration->down();
    foreach (mueLesSix() as $nom) {
        expect(Monstre::where('nom_base', $nom)->firstOrFail()->emprise())->toBe(['l' => 1, 'h' => 1], $nom);
    }
});
