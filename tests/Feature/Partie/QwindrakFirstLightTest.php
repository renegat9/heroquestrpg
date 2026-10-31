<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\Evenement;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\DemarreurQuete;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\MoteurMeteore;
use App\Partie\MoteurSorts;
use App\Partie\MoteurSynchroformes;
use App\Partie\ResolveurTour;
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

it('porte Qwindrak the Warlock comme sa source : 6/3/6/3/6, boss de First Light, répertoire et météore sourcés', function () {
    $q = Monstre::where('nom_base', 'Qwindrak the Warlock')->firstOrFail();

    expect([$q->deplacement, $q->attaque, $q->defense, $q->pv_body, $q->pv_mind])->toBe([6, 3, 6, 3, 6])
        ->and($q->tier)->toBe('boss')
        ->and($q->boite)->toBe('first_light')
        // Répertoire SOURCÉ (livret de quêtes p. 31, note C) : un archétype nommé, pas une liste recopiée sur la fiche.
        ->and($q->sorts_dread)->toBe([])
        ->and($q->archetype_lanceur)->toBe('qwindrak')
        ->and(config('archetypes_lanceurs.qwindrak.sorts'))->toBe(['Tourmente', "Nuée d'Effroi", 'Éclair de Chaos'])
        ->and(((array) $q->capacites)['meteore'] ?? null)->toBe(['des_attaque' => 2]);

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
    $rencontre = GabaritQuete::where('nom', 'Confrontation finale')->firstOrFail()->structure['rencontre_finale'];

    // Qwindrak entre par son ARCHÉTYPE (boss sorcier, comme Festral et l'Archimage elfe), pas en créature nommée.
    expect($rencontre['archetypes'])->toContain('qwindrak')
        ->and($rencontre['creatures'])->not->toContain('Qwindrak the Warlock')
        ->and($rencontre['creatures'])->not->toContain('Dragon');
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

// ---------------------------------------------------------------------
// Le répertoire PAR L'ORIGINE : « In all forms, Qwindrak knows … » (livret p. 31, note C)
// ---------------------------------------------------------------------

/**
 * Les dés d'un coup qui NE TUE PAS la figure, puis du météore qu'il déclenche. Le héros (3 dés d'attaque par
 * défaut, `creerHeros`) touche trois fois ; la figure PARE tout de ses dés NOIRS (sa défense) ; le météore (2 dés)
 * touche deux fois ; le héros ne pare rien (crânes seuls, aucun bouclier blanc). Le reste de la file est inerte.
 *
 * @return list<int>
 */
function qwDesCoupEtMeteore(int $defenseFigure): array
{
    return [1, 1, 1, ...array_fill(0, $defenseFigure, 6), 1, 1, ...array_fill(0, 20, 1)];
}

/**
 * Un héros frappe la figure par `frapper()`, puis le météore dû est résolu comme en fin d'action. UN SEUL résolveur :
 * la file des météores vit sur lui.
 *
 * @return array{coup: array<string, mixed>, meteores: list<array<string, mixed>>}
 */
function qwFrappeEtMeteore(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, InstanceMonstre $cible): array
{
    $resolveur = app(ResolveurTour::class);
    $groupe = $quete->groupe->fresh();
    $coup = $resolveur->frapper($groupe, $quete, $etat->fresh(), $heros->fresh(), $cible->fresh()->load('monstre'));

    return ['coup' => $coup, 'meteores' => $resolveur->resoudreMeteoresDus($groupe, $quete)];
}

it('une forme de la pioche porte le répertoire de Qwindrak PAR SON ORIGINE, et le palier se lit sur lui', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    qwHeros($quete, 'Albrecht', 1, 4, 2);
    $forme = qwMonstre($quete, 'Gobelin', 3, 2);
    $forme->update(['origine_monstre' => 'Qwindrak the Warlock', 'usages_dread' => 3]);
    $forme = $forme->fresh()->load(['monstre', 'origine']);

    $moteur = app(MoteurDread::class);

    // Le Gobelin ne porte rien de lui-même : tout vient de l'origine.
    expect($forme->monstre->sorts_dread)->toBe([])
        ->and($moteur->repertoireDe($forme))->toEqualCanonicalizing(['Tourmente', "Nuée d'Effroi", 'Éclair de Chaos'])
        ->and($moteur->aUnRepertoire($forme))->toBeTrue();

    // Le PALIER se lit sur la fiche d'origine (boss) : Nuée d'Effroi (palier boss) reste lançable sous une forme de tier base.
    $disponibles = new ReflectionMethod($moteur, 'sortsDisponibles');
    $disponibles->setAccessible(true);

    expect($disponibles->invoke($moteur, $forme, $quete)->pluck('nom')->all())
        ->toContain("Nuée d'Effroi", 'Éclair de Chaos', 'Tourmente');
});

it('le DRAGON, dernière forme, garde sa Boule de Flammes À VOLONTÉ en plus du répertoire de Qwindrak (pas de remplacement)', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    qwHeros($quete, 'Albrecht', 1, 4, 2);
    $dragon = qwMonstre($quete, 'Dragon', 3, 1);
    $dragon->update(['origine_monstre' => 'Qwindrak the Warlock']);
    $dragon = $dragon->fresh()->load(['monstre', 'origine']);

    // Son sort à volonté (fiche du Dragon) reste un sort de SA figure ; le répertoire de Qwindrak s'y ajoute.
    expect(app(MoteurDread::class)->repertoireDe($dragon))
        ->toEqualCanonicalizing(['Boule de Flammes', 'Tourmente', "Nuée d'Effroi", 'Éclair de Chaos'])
        ->and(app(MoteurDread::class)->sortAVolonte($dragon))->toBe('Boule de Flammes');
});

it('une forme de la pioche LANCE, en jeu, un des sorts de Qwindrak : le répertoire et le palier viennent de l\'origine', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$heros] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    $forme = qwMonstre($quete, 'Gobelin', 3, 2);
    $forme->update(['origine_monstre' => 'Qwindrak the Warlock', 'usages_dread' => 3]);
    desFiges(array_fill(0, 60, 1));

    // La résolution réelle du tour de Dread (choix du sort, portée, lancer), par la fiche d'un Gobelin : le sort vient de Qwindrak.
    $action = app(MoteurDread::class)->jouerTourDread(
        $quete->groupe->fresh(), $quete, $forme->fresh()->load(['monstre', 'origine']),
        $quete->etatsPersonnages()->with('personnage')->get(),
    );

    expect($action)->not->toBeNull()
        ->and(MoteurDread::TYPES_SORT_LANCE)->toContain($action['type'] ?? null)
        ->and(['Tourmente', "Nuée d'Effroi", 'Éclair de Chaos'])->toContain($action['sort'] ?? null);
});

it('sans origine, un Gobelin ne porte aucun répertoire : rien ne passe à une figure qui n\'est pas une forme', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    qwHeros($quete, 'Albrecht', 1, 4, 2);
    $gobelin = qwMonstre($quete, 'Gobelin', 3, 2)->fresh()->load(['monstre', 'origine']);

    expect(app(MoteurDread::class)->repertoireDe($gobelin))->toBe([])
        ->and(app(MoteurDread::class)->aUnRepertoire($gobelin))->toBeFalse()
        ->and($gobelin->ligneDeReference()->nom_base)->toBe('Gobelin');
});

// ---------------------------------------------------------------------
// LE MÉTÉORE : une attaque ou un sort d'un HÉROS qui vise la figure, après l'action
// ---------------------------------------------------------------------

it('une attaque de héros contre le Warlock déclenche un météore : 2 dés d\'attaque, défense réelle du héros, dégâts appliqués', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$heros, $etat] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 3, 2);
    $pv = (int) $heros->pv_body;
    desFiges(qwDesCoupEtMeteore(6));

    ['coup' => $coup, 'meteores' => $meteores] = qwFrappeEtMeteore($quete, $heros, $etat, $instance);

    // Le coup du héros est paré en entier : Qwindrak ne tombe pas, et la pioche ne se tire pas.
    expect($coup['touches'])->toBe(3)
        ->and($coup['degats'])->toBe(0)
        ->and($meteores)->toHaveCount(1);

    $m = $meteores[0];

    expect($m['type'])->toBe('attaque_monstre')
        ->and($m['meteore'])->toBeTrue()
        ->and($m['portee'])->toBe('distance')
        ->and($m['monstre'])->toBe('Qwindrak the Warlock')
        ->and($m['faces_attaque'])->toHaveCount(2)     // 2 dés d'attaque, pas les 3 du héros
        ->and($m['faces_defense'])->not->toBeEmpty()  // le héros DÉFEND : sa défense est réellement lancée
        ->and($m['degats'])->toBe(2)                  // deux crânes, aucun bouclier blanc
        ->and((int) $heros->fresh()->pv_body)->toBe($pv - 2);
});

it('une attaque de héros contre une FORME de la pioche déclenche le météore : la forme garde la source', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$heros, $etat] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    $forme = qwMonstre($quete, 'Gargouille', 3, 2);
    $forme->update(['origine_monstre' => 'Qwindrak the Warlock', 'formes_a_venir' => ['Gobelin', 'Orque', 'Abomination', 'Dragon']]);
    desFiges(qwDesCoupEtMeteore(5)); // la Gargouille pare de sa défense 5

    ['meteores' => $meteores] = qwFrappeEtMeteore($quete, $heros, $etat, $forme);

    expect($meteores)->toHaveCount(1)
        ->and($meteores[0]['monstre'])->toBe('Gargouille')
        ->and($meteores[0]['degats'])->toBe(2);
});

it('une attaque de héros contre le DRAGON déclenche le météore ; sa chute n\'en déclenche aucun — la victoire', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$heros, $etat] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    $dragon = qwMonstre($quete, 'Dragon', 3, 1);
    $dragon->update(['origine_monstre' => 'Qwindrak the Warlock']);

    // Le Dragon vivant : le météore part, contre le héros.
    desFiges(qwDesCoupEtMeteore(5));
    ['meteores' => $vivant] = qwFrappeEtMeteore($quete, $heros, $etat, $dragon);

    expect($vivant)->toHaveCount(1)
        ->and($vivant[0]['monstre'])->toBe('Dragon');

    // Le Dragon tombé (1 PV, trois crânes sans parade) : Qwindrak est achevé, la victoire n'a pas de météore.
    $dragon->update(['pv_body' => 1]);
    desFiges(array_fill(0, 8, 1));
    ['coup' => $coup, 'meteores' => $mort] = qwFrappeEtMeteore($quete, $heros, $etat, $dragon);

    expect($coup['cible_vaincue'])->toBeTrue()
        ->and($dragon->fresh()->etat)->toBe('vaincu')
        ->and($mort)->toBe([]);
});

it('le météore est ANNONCÉ : une phrase au fil (« Un météore de … frappe … »), une scène de table « Météore de … »', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$heros, $etat] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 3, 2);
    desFiges(qwDesCoupEtMeteore(6));

    ['meteores' => $meteores] = qwFrappeEtMeteore($quete, $heros, $etat, $instance);

    $lignes = collect(app(JournalCombat::class)->depuisResultat(['meteores' => $meteores], 'Zargon'))
        ->pluck('texte')->implode(' | ');

    expect($lignes)->toContain('Un météore de Qwindrak the Warlock frappe Albrecht (−2 PV)');

    $scenes = app(SceneDeTable::class)->depuisResultat(['meteores' => $meteores], $heros->fresh());

    expect(collect($scenes)->pluck('titre')->all())->toContain('Météore de Qwindrak the Warlock sur Albrecht');

    // Le JOURNAL le garde (rejeu à la reconnexion) : l'attaque journalisée porte `meteore`.
    $journal = Evenement::where('groupe_id', $quete->groupe_id)->get()->pluck('payload');

    expect($journal->contains(fn (array $p) => ($p['meteore'] ?? false) === true))->toBeTrue();
});

it('le météore est résolu en FIN D\'ACTION par `resoudre()` : la réponse du héros le porte dans `meteores`', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$heros] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    // Un second héros pour que le tour ne se ferme pas : la phase des monstres (qui consommerait les dés) ne joue pas.
    qwHeros($quete, 'Brunhilde', 2, 1, 1);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 3, 2);
    desFiges(qwDesCoupEtMeteore(6));

    $option = [
        'id' => 'attaquer', 'libelle' => 'Attaquer', 'type' => 'attaque',
        'parametres' => ['cibles' => [['id' => $instance->id, 'type' => 'monstre', 'nom' => 'Qwindrak the Warlock']]],
    ];

    $resultat = app(ResolveurTour::class)->resoudre($quete->groupe->fresh(), $heros->fresh(), $option, ['cible_id' => $instance->id]);

    expect($resultat['meteores'] ?? [])->toHaveCount(1)
        ->and($resultat['meteores'][0]['degats'])->toBe(2);
});

it('l\'origine est ÉCRITE avec la pioche et REJOUÉE après un snapshot : le répertoire et le météore reviennent', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    qwHeros($quete, 'Albrecht', 1, 4, 2);
    $instance = qwMonstre($quete, 'Qwindrak the Warlock', 1, 1);
    desFiges(qwChaineDes());

    qwFrapperMortel($instance); // première mort : la pioche se tire, et l'origine s'écrit avec elle

    expect($instance->fresh()->origine_monstre)->toBe('Qwindrak the Warlock');

    $groupe = $quete->groupe->fresh();
    $snapshot = app(Sauvegarde::class)->snapshotter($groupe, 'qwindrak-origine');
    app(Sauvegarde::class)->restaurer($groupe, $snapshot);

    $apres = InstanceMonstre::where('quete_id', $quete->id)->firstOrFail()->load(['monstre', 'origine']);

    expect($apres->origine_monstre)->toBe('Qwindrak the Warlock')
        ->and(app(MoteurDread::class)->repertoireDe($apres))->toEqualCanonicalizing(['Tourmente', "Nuée d'Effroi", 'Éclair de Chaos'])
        ->and(app(MoteurMeteore::class)->declencheur($apres))->toBe(['des_attaque' => 2]);
});

/**
 * Le héros lance « Boule de Feu » sur `$cible` par la résolution RÉELLE (`resoudre()`, sort par sort, une fois par
 * quête). Il connaît le sort, et la figure est en vue (même salle).
 *
 * @return array<string, mixed>
 */
function qwLanceBouleDeFeu(Quete $quete, Personnage $heros, InstanceMonstre $cible): array
{
    $sortId = Sort::where('nom', 'Boule de Feu')->firstOrFail()->id;
    $heros->sorts()->syncWithoutDetaching([$sortId => ['disponible' => true]]);

    $option = [
        'id' => 'lancer_sort', 'libelle' => 'Lancer un sort', 'type' => 'sort',
        'parametres' => ['sorts' => [[
            'cle' => "sort:{$sortId}", 'sort_id' => $sortId, 'nom' => 'Boule de Feu', 'disponible' => true,
            'cibles' => [['type' => 'monstre', 'id' => $cible->id, 'nom' => 'X']],
        ]]],
    ];

    return app(ResolveurTour::class)->resoudre($quete->groupe->fresh(), $heros->fresh(), $option, [
        'cle' => "sort:{$sortId}", 'cible_id' => $cible->id, 'cible_type' => 'monstre',
    ]);
}

it('un SORT de héros qui VISE le Warlock lui vaut un météore, à la fin de l\'action', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$heros] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    qwHeros($quete, 'Brunhilde', 2, 1, 1); // le tour ne se ferme pas : la phase des monstres ne joue pas
    $warlock = qwMonstre($quete, 'Qwindrak the Warlock', 3, 2);
    $warlock->update(['pv_body' => 30, 'pv_body_max' => 30]); // il survit à la boule : on regarde le météore
    desFiges(array_fill(0, 40, 1));

    $resultat = qwLanceBouleDeFeu($quete, $heros, $warlock);

    expect($resultat['type'])->toBe('sort')
        ->and($resultat['meteores'] ?? [])->toHaveCount(1)
        ->and($resultat['meteores'][0]['meteore'])->toBeTrue()
        ->and($resultat['meteores'][0]['monstre'])->toBe('Qwindrak the Warlock');
});

it('un sort de héros qui ne VISE PAS le Warlock — même dans la salle — ne déclenche rien', function () {
    $quete = qwQuete(['mmmmmm', 'msssssm', 'msssssm', 'mmmmmmm']);
    [$heros] = qwHeros($quete, 'Albrecht', 1, 4, 2);
    qwHeros($quete, 'Brunhilde', 2, 1, 1);
    $gobelin = qwMonstre($quete, 'Gobelin', 3, 1);
    qwMonstre($quete, 'Qwindrak the Warlock', 3, 2); // dans la salle, en vue, mais hors de la cible du sort
    desFiges(array_fill(0, 40, 1));

    $resultat = qwLanceBouleDeFeu($quete, $heros, $gobelin);

    expect($resultat['type'])->toBe('sort')
        ->and($resultat['meteores'] ?? [])->toBe([]);
});

it('seul un HÉROS déclenche le météore : `meteoreDu()` n\'a que deux appelants, `frapper()` et `sousReactionMj()`', function () {
    // Garde structurel (dans le style d'AdversairesNommesTest, qui lit déjà le source du résolveur) : un allié
    // (mercenaire, compagnon) frappe par `resoudreAttaqueAllie()` / `resoudreAttaqueDAllie()`, jamais par `frapper()`,
    // et aucun sort d'allié ne passe par `sousReactionMj()`. Ajouter un troisième appelant doit donc se voir ici.
    $source = (string) file_get_contents(base_path('app/Partie/ResolveurTour.php'));

    preg_match_all('/\$this->meteoreDu\(/', $source, $appels, PREG_OFFSET_CAPTURE);

    $appelants = [];

    foreach ($appels[0] as [, $position]) {
        preg_match_all('/function (\w+)\(/', substr($source, 0, $position), $fonctions);
        $appelants[] = end($fonctions[1]);
    }

    // Quatre appels : `frapper()` en a TROIS (la voie Vindication, le retour standard, et la frappe DÉTOURNÉE par le Deflect
    // de Gruzbella, chantier EXT-MAGRIAN 2026-10-23 : une attaque de héros résolue contre une figure vaut météore, qu'elle
    // touche ou non), `sousReactionMj()` un. Un allié n'y figure toujours pas.
    expect(collect($appelants)->sort()->values()->all())->toBe(['frapper', 'frapper', 'frapper', 'sousReactionMj'])
        ->and(substr_count($source, 'function resoudreAttaqueAllie('))->toBe(1); // les alliés existent : la garde compte
});

it('le coût de Qwindrak suit la méthode mesurée : formes, puis météore (× 5/3), puis trois sorts, sur la droite des boss', function () {
    $q = Monstre::where('nom_base', 'Qwindrak the Warlock')->firstOrFail();

    $attaques = 0.0;

    foreach (['Qwindrak the Warlock', 'Abomination', 'Gargouille', 'Guerrier du Chaos', 'Gobelin', 'Orque', 'Dragon'] as $nom) {
        $forme = Monstre::where('nom_base', $nom)->firstOrFail();
        $attaques += $forme->pv_body / (1.5 - $forme->defense / 6);
    }

    // Le météore : 2 dés contre les 3 de l'unité du bestiaire, soit 2/3 d'une attaque par attaque ou sort du groupe.
    // Les trois sorts : au plus 3 lancers, une action de héros chacun.
    $equivalent = $attaques * (1 + 2 / 3) + 3;
    // Droite des boss SANS le Seigneur (2026-10-31) : `coutDroiteBoss()`, la seule source de la formule.
    $cout = coutDroiteBoss($equivalent);

    expect((int) $q->cout)->toBe($cout)
        ->and($cout)->toBe(42);
});
