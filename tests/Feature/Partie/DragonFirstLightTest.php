<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\MoteurDread;
use Illuminate\Database\Eloquent\Collection;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Lot A — First Light (docs/plan-first-light.md, décisions René 2026-09-30) :
 * la carte « Dragon » — « The dragon uses Draconic Flight and may cast Ball
 * of Flame at will. » Deux mécaniques, deux tests EN JEU, avant la donnée
 * (ordre imposé par CLAUDE.md : vocabulaire → lecteur → test en jeu → donnée) :
 *
 *  - `sort_a_volonte` : Boule de Flammes échappe au compteur `usages_dread`
 *    pour CE monstre seul (contraste explicite avec le Spectre, bridé malgré
 *    sa carte à lui aussi dire « at will » — divergence assumée).
 *  - `vol_draconique` : Draconic Flight traverse les FIGURES en approche
 *    (jamais le mobilier ni les murs), sans jamais finir sur une case
 *    occupée.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Couloir d'une case de haut, `$largeur` cases de large — pour contrôler
 * EXACTEMENT qui se trouve entre le Dragon et sa cible, sans dépendre d'une
 * carte générée procéduralement (même patron que
 * `tests/Feature/Partie/TerrainCarteTest.php::queteAvecCarteEtTerrain()`,
 * copié ici localement : ce fichier tourne aussi seul, par chemin direct).
 */
function queteCouloir(int $largeur): Quete
{
    $groupe = creerGroupe('table-dragon-'.uniqid());
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — Draconic Flight',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $largeur,
        'hauteur' => 1,
        'grille' => [
            'largeur' => $largeur,
            'hauteur' => 1,
            'cases' => [array_fill(0, $largeur, 's')],
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [],
            'leviers' => [],
            'pieges' => [],
            'mobilier' => [],
            'epreuves' => [],
            'terrain' => [],
            'spawn_heros' => [['x' => 0, 'y' => 0]],
            'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    return $quete->fresh();
}

/** Héros debout à (x, 0), état de quête créé directement (pas de passage par l'API). */
function heroEnCouloir(Quete $quete, string $nom, int $ordre, int $x): array
{
    $joueur = connecterJoueur('joueur-'.$nom.'-'.uniqid());
    $personnage = creerHeros($joueur, $quete->groupe, $nom, $ordre);

    $etat = EtatPersonnageQuete::create([
        'personnage_id' => $personnage->id,
        'quete_id' => $quete->id,
        'position_x' => $x,
        'position_y' => 0,
        'tombe' => false,
    ]);

    return [$personnage, $etat];
}

// ---------------------------------------------------------------------
// sort_a_volonte — Boule de Flammes du Dragon ignore le compteur de sorts
// ---------------------------------------------------------------------

it('le Dragon lance Boule de Flammes même à 0 usage restant — sort_a_volonte', function () {
    $ctx = demarrerQueteAvecMonstre('Dragon');
    $instance = $ctx['instance']->fresh()->load('monstre');

    expect($instance->monstre->capacites)->toHaveKey('sort_a_volonte')
        ->and(app(MoteurDread::class)->sortAVolonte($instance))->toBe('Boule de Flammes');

    // Compteur épuisé comme si le Dragon avait déjà tout dépensé ce combat.
    $instance->update(['usages_dread' => 0]);
    $instance->refresh();

    desFiges(array_fill(0, 200, 4)); // aucun impact sur le dé rouge de résistance (2-3), peu importe ici

    $cibles = Collection::make([$ctx['etatHeros']]);

    foreach (range(1, 3) as $tour) {
        $resultat = app(MoteurDread::class)->jouerTourDread(
            $ctx['groupe'], $ctx['quete'], $instance->fresh()->load('monstre'), $cibles,
        );

        expect($resultat)->not->toBeNull()
            ->and($resultat['type'])->toBe('sort_dread', "tour {$tour}")
            ->and($resultat['sort'])->toBe('Boule de Flammes', "tour {$tour}");
    }

    // ⚠ Jamais décrémenté : c'est la DIFFÉRENCE avec tout le reste du
    // bestiaire — le compteur reste à 0 parce qu'il n'a jamais été entamé
    // pour CE sort, pas parce qu'il a été reconstitué.
    expect($instance->fresh()->usages_dread)->toBe(0);
});

it('le Spectre, lui, reste bridé à 1 usage malgré sa carte « à volonté » (divergence assumée)', function () {
    // Contraste délibéré avec le Dragon : `USAGES_BASE` documente cette
    // divergence depuis le 2026-09-04 (« un spectre par salle canaliserait
    // sinon à chaque tour de monstre ») — rien de ce lot ne doit la changer.
    $ctx = demarrerQueteAvecMonstre('Spectre');
    $instance = $ctx['instance']->fresh()->load('monstre');

    expect(app(MoteurDread::class)->sortAVolonte($instance))->toBeNull();

    $instance->update(['usages_dread' => 0]);
    $instance->refresh();

    desFiges(array_fill(0, 50, 4));

    $resultat = app(MoteurDread::class)->jouerTourDread(
        $ctx['groupe'], $ctx['quete'], $instance->fresh()->load('monstre'), Collection::make([$ctx['etatHeros']]),
    );

    // Compteur à sec, pas de sort gratuit : comportement de base (approche
    // au contact, pas de sort), donc aucune action Dread.
    expect($resultat)->toBeNull();
});

// ---------------------------------------------------------------------
// vol_draconique — Draconic Flight traverse les figures, jamais le mobilier
// ---------------------------------------------------------------------

it('Draconic Flight traverse un héros interposé pour frapper la cible, sans jamais s\'arrêter sur lui', function () {
    $quete = queteCouloir(5);

    // Dragon(0,0) — vide(1,0) — Blocage(2,0) — vide(3,0) — Cible(4,0). Un seul
    // rang de cases : aucun détour n'existe, un cheminement ORDINAIRE
    // (figures bloquantes) ne peut pas dépasser le héros du milieu. Le
    // blocage n'est PAS adjacent au Dragon au départ (distance 2) : sinon le
    // Dragon le considérerait comme une cible déjà au contact et renoncerait
    // au vol avant même de regarder plus loin (même garde que la Charge).
    [$blocage, $etatBlocage] = heroEnCouloir($quete, 'Blocage', 1, 2);
    [$cible, $etatCible] = heroEnCouloir($quete, 'Cible', 2, 4);

    // Le bloc catalogue du Dragon, allégé de sa magie pour isoler le
    // mouvement : seule `vol_draconique` reste en jeu dans ce test.
    $catalogue = Monstre::where('nom_base', 'Dragon')->firstOrFail();
    $catalogue->update(['capacites' => ['vol_draconique'], 'sorts_dread' => []]);
    $catalogue->refresh();

    $instance = InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'position_x' => 0,
        'position_y' => 0,
        'etat' => 'actif',
        'elite' => false,
        'revele' => true,
        'usages_dread' => 0,
        'invocation_dread_utilisee' => false,
        'fuite_dread_utilisee' => false,
    ]);

    desFiges([
        1, 1, 1, 1, 1, // 5 crânes — attaque du Dragon (5 dés, sans bonus : la carte n'en donne pas)
        4, 4,          // défense du héros, aucun succès
        ...array_fill(0, 50, 4),
    ]);

    $pvAvant = (int) $cible->pv_body;

    $resultat = app(MoteurDread::class)->jouerTourDread(
        // ⚠ `$etatBlocage` n'est PAS dans `$cibles` : le Dragon ne le
        // considère pas comme une cible à frapper (il reste un simple
        // OBSTACLE sur le trajet), exactement comme `ResolveurTour` ne passe
        // que les héros « debout non cachés ». S'il figurait dans la liste,
        // étant plus proche, il deviendrait lui-même la cible choisie — ce
        // n'est pas ce que ce test vérifie : il vérifie que le Dragon peut
        // ATTEINDRE une cible au-delà d'une figure interposée.
        $quete->groupe, $quete, $instance->fresh()->load('monstre'), Collection::make([$etatCible]),
    );

    expect($resultat)->not->toBeNull()
        ->and($resultat['type'])->toBe('vol_draconique')
        ->and($resultat['cible']['personnage_id'])->toBe($cible->id);

    $instance->refresh();
    $etatBlocageApres = $etatBlocage->fresh();
    $etatCibleApres = $etatCible->fresh();

    $dragonX = (int) $instance->position_x;
    $dragonY = (int) $instance->position_y;

    // Il est arrivé AU CONTACT de la cible…
    expect(abs($dragonX - (int) $etatCibleApres->position_x) + abs($dragonY - (int) $etatCibleApres->position_y))
        ->toBe(1)
        // … en ayant traversé la case du héros bloquant, jamais en s'y arrêtant.
        ->and([$dragonX, $dragonY])
        ->not->toBe([(int) $etatBlocageApres->position_x, (int) $etatBlocageApres->position_y])
        // Le héros bloquant n'a ni bougé ni rien subi : il a seulement été
        // traversé.
        ->and((int) $etatBlocageApres->position_x)->toBe(2)
        ->and($etatBlocageApres->tombe)->toBeFalse()
        // Et la cible a bien été frappée.
        ->and((int) $cible->fresh()->pv_body)->toBeLessThan($pvAvant);
});

it('Draconic Flight ne fait rien de spécial quand le Dragon est déjà au contact', function () {
    $quete = queteCouloir(2);
    [$cible, $etatCible] = heroEnCouloir($quete, 'Cible', 1, 1);

    $catalogue = Monstre::where('nom_base', 'Dragon')->firstOrFail();
    $catalogue->update(['capacites' => ['vol_draconique'], 'sorts_dread' => []]);
    $catalogue->refresh();

    $instance = InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'position_x' => 0,
        'position_y' => 0,
        'etat' => 'actif',
        'elite' => false,
        'revele' => true,
        'usages_dread' => 0,
        'invocation_dread_utilisee' => false,
        'fuite_dread_utilisee' => false,
    ]);

    $resultat = app(MoteurDread::class)->jouerTourDread(
        $quete->groupe, $quete, $instance->fresh()->load('monstre'), Collection::make([$etatCible]),
    );

    // Déjà au contact : rien à traverser, le comportement de base
    // (approche + attaque normale, tenu par ResolveurTour) prend le relais.
    $apres = $instance->fresh();
    expect($resultat)->toBeNull();
    expect([(int) $apres->position_x, (int) $apres->position_y])->toBe([0, 0]);
});
