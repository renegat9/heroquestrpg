<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\DemarreurQuete;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Partie\MoteurSorts;
use App\Partie\MoteurSynchroformes;
use App\Partie\Sauvegarde;
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
use Illuminate\Support\Facades\Http;

/*
 * QWINDRAK THE WARLOCK (First Light, confrontation finale — décision de René, 2026-10-17).
 *
 * Règle du livret (p. 7) : « the warlock assumes one or more distinct creature forms called Synchroforms … When
 * the heroes defeat his Synchroform creature, remove that miniature from the board. Any spell active on Qwindrak
 * ends. … If he transforms, place the new miniature on the square occupied by the previous Synchroform. » Le
 * tirage des formes est celui de la carte de Banjo, validé par René : mélange de cinq formes, le DRAGON au fond.
 * La FLAMME DU DREAD, à l'arrivée du Dragon : « chaque héros dans la même salle que lui lance un dé rouge ; sur 3
 * ou moins, il perd 1 Body Point » (`reference/18` §6.1).
 *
 * Tests EN JEU : la chaîne complète passe par `MoteurDegats::infligerAMonstre()` (le point de passage unique de la
 * mort), la mise à mort réelle d'un coup passe par `ResolveurTour::frapper()`, et la carte est dessinée comme une
 * vraie quête. Chaque test échoue sans le correctif : sans la pioche, Qwindrak meurt à sa première forme.
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

/**
 * Une quête dessinée (`s` sol, `m` mur). Par défaut UNE salle sur tout le plateau ; `$salles` pour en
 * dessiner d'autres. Le plateau est fermé de murs : aucune case n'est une sortie.
 *
 * @param  list<string>  $dessin
 * @param  list<array<string, mixed>>|null  $salles
 */
function qwQuete(array $dessin, ?array $salles = null): Quete
{
    $groupe = creerGroupe('table-qw-'.uniqid());
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();
    $largeur = strlen($dessin[0]);
    $hauteur = count($dessin);
    $salles ??= [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]];

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — Qwindrak',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
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
            'portes' => [],
            'leviers' => [],
            'pieges' => [],
            'mobilier' => [],
            'epreuves' => [],
            'terrain' => [],
            'spawn_heros' => [['x' => 1, 'y' => 1]],
            'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    return $quete->fresh();
}

/** Un héros debout en (x, y), état de quête créé directement. @return array{0: Personnage, 1: EtatPersonnageQuete} */
function qwHeros(Quete $quete, string $nom, int $ordre, int $x, int $y): array
{
    $joueur = connecterJoueur('joueur-'.$nom.'-'.uniqid());
    $personnage = creerHeros($joueur, $quete->groupe, $nom, $ordre);

    $etat = EtatPersonnageQuete::create([
        'personnage_id' => $personnage->id,
        'quete_id' => $quete->id,
        'position_x' => $x,
        'position_y' => $y,
        'tombe' => false,
    ]);

    return [$personnage, $etat];
}

/** Une instance révélée et active du catalogue `$nomBase` en (x, y). */
function qwMonstre(Quete $quete, string $nomBase, int $x, int $y): InstanceMonstre
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

/** Un coup fatal, à l'endroit où les héros le portent : le point de passage unique de la mort. */
function qwFrapperMortel(InstanceMonstre $instance): array
{
    return app(MoteurDegats::class)->infligerAMonstre(
        $instance->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS,
    );
}

/** Les formes de la pioche de René, dans l'ordre où le dé injecté les pose (voir le test de l'ordre). */
const QW_FORMES = ['Abomination', 'Gargouille', 'Guerrier du Chaos', 'Gobelin', 'Orque'];

/**
 * Le plateau de la chaîne complète : une salle de 4 × 2 où un héros se tient, Qwindrak au coin.
 * Dés injectés : `[1,1,1,1]` tire la pioche dans l'ordre Gargouille, Guerrier du Chaos, Gobelin, Orque,
 * Abomination (Fisher–Yates sur un d6 = 1, donc indice 0 à chaque tirage), puis le Dragon ; `5` ensuite,
 * pour le héros de la salle lors de l'arrivée du Dragon (indemne).
 */
function qwChaineDes(): array
{
    return [1, 1, 1, 1, 5, 5, 5, 5, 5, 5, 5, 5];
}

// ---------------------------------------------------------------------
// La fiche : sourcée, et la pioche ne nomme que des lignes qui existent
// ---------------------------------------------------------------------

it('porte Qwindrak the Warlock comme sa source : 6/3/6/3/6, boss de First Light, sans sort non sourcé', function () {
    $q = Monstre::where('nom_base', 'Qwindrak the Warlock')->firstOrFail();

    expect([$q->deplacement, $q->attaque, $q->defense, $q->pv_body, $q->pv_mind])->toBe([6, 3, 6, 3, 6])
        ->and($q->tier)->toBe('boss')
        ->and($q->boite)->toBe('first_light')
        // Répertoire ⚠ non trouvé : aucun sort semé, ni Meteor ni Lightning Bolt de la fiche de Banjo.
        ->and($q->sorts_dread)->toBe([])
        ->and($q->archetype_lanceur)->toBeNull();

    $pioche = ((array) $q->capacites)['synchroformes'];

    expect($pioche['formes'])->toBe(QW_FORMES)
        ->and($pioche['dernier'])->toBe('Dragon');

    // Chaque forme est une ligne EXISTANTE du bestiaire, aux statistiques de sa carte — jamais recopiées.
    foreach ([...$pioche['formes'], $pioche['dernier']] as $forme) {
        expect(Monstre::where('nom_base', $forme)->exists())->toBeTrue("« {$forme} » n'est pas au catalogue");
    }

    expect(Monstre::where('nom_base', 'Guerrier du Chaos')->firstOrFail()->pv_body)->toBe(3);
});

it('retire le Dragon de la rencontre finale : Qwindrak y entre à sa place, le Dragon n\'est jamais acheté seul', function () {
    $creatures = GabaritQuete::where('nom', 'Confrontation finale')->firstOrFail()->structure['rencontre_finale']['creatures'];

    expect($creatures)->toContain('Qwindrak the Warlock')
        ->and($creatures)->not->toContain('Dragon');
});

it('tire la rencontre finale de First Light sur QWINDRAK, pour tout groupe, et jamais le Dragon', function () {
    $structure = GabaritQuete::where('nom', 'Confrontation finale')->firstOrFail()->structure;
    $achat = new ReflectionMethod(DemarreurQuete::class, 'acheterMonstres');
    $achat->setAccessible(true);

    foreach (range(0, 11) as $graine) {
        $achats = $achat->invoke(app(DemarreurQuete::class), $structure, 400, 6, 1, $graine, \App\Partie\BestiaireGroupe::auto('first_light'));
        $boss = collect($achats)->firstWhere('tier', 'boss');

        expect($boss?->nom_base)->toBe('Qwindrak the Warlock', "graine {$graine}")
            ->and(collect($achats)->pluck('nom_base')->all())->not->toContain('Dragon');
    }
});

// ---------------------------------------------------------------------
// La pioche : tirée à la première mort, stockée, le Dragon au fond
// ---------------------------------------------------------------------

it('tire la pioche à la PREMIÈRE mort : cinq formes mélangées, le Dragon au fond, stockées en base', function () {
    $quete = qwQuete([
        'mmmmmm',
        'msssssm',
        'msssssm',
        'mmmmmmm',
    ]);
    qwHeros($quete, 'Albrecht', 1, 4, 2);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 1, 1);
    desFiges([1, 1, 1, 1, 5, 5, 5, 5]);

    $r = qwFrapperMortel($instance);

    // Qwindrak ne meurt pas : il prend la PREMIÈRE forme de la pioche mélangée.
    expect($r['vaincu'])->toBeFalse()
        ->and($r['changement_phase'])->toMatchArray(['avant' => 'Qwindrak the Warlock', 'apres' => 'Gargouille']);

    $instance = $instance->fresh();

    expect($instance->monstre->nom_base)->toBe('Gargouille')
        ->and($instance->etat)->toBe('actif')
        // Le RESTE de la pioche, dans l'ordre tiré, Dragon au fond — et nulle part ailleurs que la base.
        ->and($instance->formes_a_venir)->toBe(['Guerrier du Chaos', 'Gobelin', 'Orque', 'Abomination', 'Dragon']);
});

it('l\'ordre dépend du dé injecté, et le Dragon reste toujours le dernier', function () {
    // Chaque tirage au rejet consomme UN d6 si la valeur est < n (n = 5, 4, 3, 2) : d6 ∈ [1, n].
    $ordres = [];

    foreach ([[1, 1, 1, 1], [2, 3, 1, 2], [4, 4, 2, 2], [3, 2, 1, 1], [2, 1, 2, 1]] as $des) {
        $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
        $instance = qwMonstre($quete, 'Qwindrak the Warlock', 1, 1);
        desFiges($des);

        qwFrapperMortel($instance);

        $instance = $instance->fresh()->load('monstre');
        $file = $instance->formes_a_venir;
        $ordres[] = implode('|', [$instance->monstre->nom_base, ...$file]);

        // La forme courante + la file = les cinq formes, une fois chacune ; le Dragon est le DERNIER de la file.
        expect(array_pop($file))->toBe('Dragon')
            ->and(collect([$instance->monstre->nom_base, ...$file])->sort()->values()->all())
            ->toBe(collect(QW_FORMES)->sort()->values()->all());
    }

    // Des dés différents mènent à des ordres différents : la pioche est bien tirée, pas figée.
    expect(count(array_unique($ordres)))->toBeGreaterThan(1);
});

it('enchaîne la pioche jusqu\'au Dragon, puis la mort du Dragon achève Qwindrak — aucune forme au-delà', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$heros] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 1, 1);
    desFiges(qwChaineDes());

    $attendu = ['Gargouille', 'Guerrier du Chaos', 'Gobelin', 'Orque', 'Abomination', 'Dragon'];
    $ancien = 'Qwindrak the Warlock';

    foreach ($attendu as $suivant) {
        $r = qwFrapperMortel($instance);

        expect($r['vaincu'])->toBeFalse("avant {$suivant}")
            ->and($r['changement_phase'])->toMatchArray(['avant' => $ancien, 'apres' => $suivant]);

        $ancien = $suivant;
        $instance = $instance->fresh()->load('monstre');
    }

    // La forme DRAGON : sa carte, pas une forme de la pioche.
    expect($instance->monstre->nom_base)->toBe('Dragon')
        ->and($instance->monstre->emprise())->toBe(['l' => 1, 'h' => 2])
        ->and((int) $instance->pv_body)->toBe(7)
        ->and($instance->formes_a_venir)->toBe([]);

    // Le Dragon meurt pour de bon : la pioche est épuisée, il n'y a plus de forme.
    $fin = qwFrapperMortel($instance);

    expect($fin['vaincu'])->toBeTrue()
        ->and($fin['changement_phase'])->toBeNull()
        ->and($instance->fresh()->etat)->toBe('vaincu');
});

it('chaque forme de la pioche est ANNONCÉE avec son nom et ses statistiques, et seule la forme Dragon porte la flamme', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    qwHeros($quete, 'Albrecht', 1, 4, 2);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 1, 1);
    desFiges(qwChaineDes());

    $gargouille = qwFrapperMortel($instance)['changement_phase'];
    expect($gargouille['stats']['apres'])->toMatchArray(['attaque' => 4, 'defense' => 5])
        ->and($gargouille)->not->toHaveKey('flamme');

    $instance = $instance->fresh()->load('monstre');

    // Quatre autres formes avant le Dragon : aucune ne souffle la flamme.
    for ($i = 0; $i < 4; $i++) {
        $r = qwFrapperMortel($instance)['changement_phase'];
        expect($r)->not->toHaveKey('flamme');
        $instance = $instance->fresh()->load('monstre');
    }

    $dragon = qwFrapperMortel($instance)['changement_phase'];

    expect($dragon['apres'])->toBe('Dragon')
        ->and($dragon['stats']['apres'])->toMatchArray(['attaque' => 5, 'defense' => 5])
        ->and($dragon)->toHaveKey('flamme');

    // L'annonce dit la forme et ses dés, et la flamme dit le dé de chaque héros de la salle.
    $lignes = collect(app(JournalCombat::class)->depuisResultat(['type' => 'changement_phase', 'phase' => $dragon], 'Zargon'))
        ->pluck('texte')->implode(' | ');

    expect($lignes)->toContain('Dragon')
        ->and($lignes)->toContain('La flamme du Dread')
        ->and($lignes)->toContain('Albrecht : dé ');
});

// ---------------------------------------------------------------------
// Reprise : la même pioche, jamais tirée à nouveau
// ---------------------------------------------------------------------

it('reprend la MÊME pioche après un snapshot et une reprise : rien n\'est tiré une seconde fois', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    qwHeros($quete, 'Albrecht', 1, 4, 2);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 1, 1);
    desFiges(qwChaineDes());

    qwFrapperMortel($instance);
    $file = $instance->fresh()->formes_a_venir;

    $groupe = $quete->groupe->fresh();
    $snapshot = app(Sauvegarde::class)->snapshotter($groupe, 'qwindrak-reprise');
    app(Sauvegarde::class)->restaurer($groupe, $snapshot);

    expect(InstanceMonstre::where('quete_id', $quete->id)->first()->formes_a_venir)->toBe($file);

    // Un nouveau tirage, s'il avait lieu, prendrait d'autres dés : on en donne de tout autres. La forme
    // suivante doit venir de la file gardée, pas d'un nouveau mélange.
    desFiges([6, 6, 6, 6, 6, 6, 5, 5, 5, 5]);
    $suivante = InstanceMonstre::where('quete_id', $quete->id)->firstOrFail()->load('monstre');
    $r = qwFrapperMortel($suivante);

    expect($r['changement_phase']['apres'])->toBe($file[0]);
});

// ---------------------------------------------------------------------
// Les sorts actifs cessent ; les compteurs de la rencontre restent
// ---------------------------------------------------------------------

it('à chaque forme, les sorts ACTIFS sur Qwindrak cessent et sont annoncés ; ses compteurs restent', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 1, 1);
    $instance->update(['usages_dread' => 2, 'capacites_reactives_utilisees' => ['ignore_degats_attaque'], 'sorts_dread_lances' => ['Boule de Flammes']]);
    $instance->refresh();

    app(MoteurSorts::class)->poserConditionMonstre($instance, MoteurSorts::MONSTRE_ENDORMI);
    $instance->refresh()->poserEtatDread('buff_attaque', ['par' => 1, 'des' => 2]);
    desFiges(qwChaineDes());

    $r = qwFrapperMortel($instance->fresh()->load('monstre'));

    expect($r['changement_phase']['sorts_termines'])->toContain('endormi')
        ->and($r['changement_phase']['sorts_termines'])->toContain("bonus d'attaque");

    $apres = $instance->fresh();

    expect(app(MoteurSorts::class)->monstreA($apres, MoteurSorts::MONSTRE_ENDORMI))->toBeFalse()
        ->and($apres->etatDread('buff_attaque'))->toBeNull()
        // Les compteurs de la RENCONTRE ne sont pas des sorts actifs : ils ne se remettent pas à zéro.
        ->and((int) $apres->usages_dread)->toBe(2)
        ->and($apres->capacites_reactives_utilisees)->toBe(['ignore_degats_attaque'])
        ->and($apres->sorts_dread_lances)->toBe(['Boule de Flammes']);

    // La phrase le dit à la table, sans attendre le détail des effets.
    $lignes = collect(app(JournalCombat::class)->depuisResultat(['changement_phase' => $r['changement_phase']], 'Zargon'))
        ->pluck('texte')->implode(' | ');

    expect($lignes)->toContain('Les sorts actifs sur lui cessent');
});

it('une chaîne de PHASES (Gretzl) garde ses sorts actifs : la règle des synchroformes ne déborde pas sur elle', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    $instance = qwMonstre($quete, 'Gretzl la Porte-Fléau', 1, 1);
    app(MoteurSorts::class)->poserConditionMonstre($instance, MoteurSorts::MONSTRE_ENDORMI);

    // Demon Wings (la première mort est ignorée), puis la seconde : Gretzl adopte sa forme suivante.
    qwFrapperMortel($instance);
    $r = qwFrapperMortel($instance->fresh()->load('monstre'));

    expect($r['changement_phase'])->toMatchArray(['avant' => 'Gretzl la Porte-Fléau', 'apres' => 'Demonspider'])
        ->and($r['changement_phase']['sorts_termines'])->toBe([])
        ->and(app(MoteurSorts::class)->monstreA($instance->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeTrue()
        ->and($instance->fresh()->formes_a_venir)->toBeNull();
});

// ---------------------------------------------------------------------
// La place du Dragon est SÛRE
// ---------------------------------------------------------------------

it('la place du Dragon est sûre : son emprise 1 × 2 se pose sur la case libre la plus proche, jamais sur un héros', function () {
    $quete = qwQuete(['mmmmmm', 'msssmm', 'msssmm', 'mmmmmm']);
    qwHeros($quete, 'Albrecht', 1, 1, 2); // occupe la SECONDE case de l'emprise posée en (1, 1)
    $instance = qwMonstre($quete, 'Dragon', 1, 1);

    app(MoteurSynchroformes::class)->ancrer($instance->fresh()->load('monstre'));

    $ancre = $instance->fresh();

    expect([(int) $ancre->position_x, (int) $ancre->position_y])->toBe([2, 1])
        ->and($ancre->monstre->emprise())->toBe(['l' => 1, 'h' => 2]);
});

it('la place du Dragon reste où elle est quand elle est sûre — aucun déplacement inutile', function () {
    $quete = qwQuete(['mmmmmm', 'msssmm', 'msssmm', 'mmmmmm']);
    $instance = qwMonstre($quete, 'Dragon', 1, 1);

    app(MoteurSynchroformes::class)->ancrer($instance->fresh()->load('monstre'));

    expect([(int) $instance->fresh()->position_x, (int) $instance->fresh()->position_y])->toBe([1, 1]);
});

// ---------------------------------------------------------------------
// La flamme du Dread
// ---------------------------------------------------------------------

it('la flamme du Dread : chaque héros DE LA SALLE lance un dé rouge ; 1 à 3 lui coûte 1 PV, les autres salles ne lancent rien', function () {
    // Deux salles : A (x 1-3, y 1-2) et B (x 5, y 1-2), séparées par un mur. Qwindrak en A ; Albrecht en A ; Brunhilde en B.
    $quete = qwQuete(
        ['mmmmmmm', 'msssmsm', 'msssmsm', 'mmmmmmm'],
        [
            ['x' => 1, 'y' => 1, 'largeur' => 3, 'hauteur' => 2, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0],
            ['x' => 5, 'y' => 1, 'largeur' => 1, 'hauteur' => 2, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0],
        ],
    );
    [$albrecht, $etatAlbrecht] = qwHeros($quete, 'Albrecht', 1, 3, 2);
    [$brunhilde, $etatBrunhilde] = qwHeros($quete, 'Brunhilde', 2, 5, 1);
    $pvAlbrecht = (int) $albrecht->pv_body;
    $pvBrunhilde = (int) $brunhilde->pv_body;

    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 2, 1);

    // Pioche : [1,1,1,1]. Puis UN SEUL dé pour la flamme : 2, sur Albrecht. Si Brunhilde (salle B) était lancée,
    // le lanceur serait épuisé et le test casserait : c'est la preuve qu'elle n'est pas dans la salle.
    desFiges([1, 1, 1, 1, 2]);

    for ($i = 0; $i < 6; $i++) {
        $r = qwFrapperMortel($instance->fresh()->load('monstre'));
    }

    $flamme = $r['changement_phase']['flamme'] ?? null;

    expect($flamme)->not->toBeNull()
        ->and($flamme['resultats'])->toHaveCount(1)
        ->and($flamme['resultats'][0]['cible']['nom'])->toBe('Albrecht')
        ->and($flamme['resultats'][0]['des_rouges'])->toBe([2])
        ->and($flamme['resultats'][0]['degats'])->toBe(1);

    expect((int) $albrecht->fresh()->pv_body)->toBe($pvAlbrecht - 1)
        ->and((int) $brunhilde->fresh()->pv_body)->toBe($pvBrunhilde);

    // La table voit le dé : une scène de plus, avec « dé 2 — −1 PV » pour Albrecht.
    $scenes = app(SceneDeTable::class)->depuisResultat(
        ['type' => 'attaque', 'changement_phase' => $r['changement_phase']],
        $albrecht->fresh(),
    );

    $texte = json_encode($scenes, JSON_UNESCAPED_UNICODE);

    expect($texte)->toContain('La flamme du Dread')
        ->and($texte)->toContain('dé 2 — −1 PV');
});

it('la flamme ne blesse pas un héros de la salle qui fait 4 à 6 — « 3 ou moins » et non « 3 ou plus »', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$albrecht] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    $pv = (int) $albrecht->pv_body;
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 1, 1);

    // Pioche [1,1,1,1], puis le dé de la flamme : 4 (indemne). Un 3 blesserait.
    desFiges([1, 1, 1, 1, 4]);

    for ($i = 0; $i < 6; $i++) {
        $r = qwFrapperMortel($instance->fresh()->load('monstre'));
    }

    expect($r['changement_phase']['flamme']['resultats'][0]['degats'])->toBe(0)
        ->and((int) $albrecht->fresh()->pv_body)->toBe($pv);
});

it('EtatGroupe publie la forme COURANTE de la figure (Dragon, emprise 1 × 2), jamais la file de la pioche', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    qwHeros($quete, 'Albrecht', 1, 4, 2);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 1, 1);
    desFiges(qwChaineDes());

    for ($i = 0; $i < 6; $i++) {
        qwFrapperMortel($instance);
        $instance = $instance->fresh()->load('monstre');
    }

    $publie = json_encode(app(\App\Partie\EtatGroupe::class)->payload($quete->groupe->fresh()), JSON_UNESCAPED_UNICODE);

    expect($publie)->toContain('"nom_base":"Dragon"')
        ->and($publie)->toContain('"emprise":{"l":1,"h":2}')
        ->and($publie)->not->toContain('formes_a_venir');
});
