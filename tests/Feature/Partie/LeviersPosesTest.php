<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\GabaritQuete;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\Grille;
use App\Partie\MoteurPortes;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * LE TEST QUI MANQUAIT (2026-09-10) — `placerLeviers()` exigeait jusqu'ici des
 * positions explicites (`structure.leviers[] = {x, y, levier_id}`) qu'AUCUN
 * gabarit ne pouvait fournir (la carte est générée à l'exécution) : la couche
 * rendait toujours `[]`. Le verrou/levier était pourtant câblé de bout en bout
 * côté consommation (`MoteurPortes::leviersAdjacents()`, l'option
 * `actionner_levier` de `MenuMoteur`, `ResolveurTour::resoudreActionnerLevier()`,
 * la publication fogged d'`EtatGroupe`) — du code écrit, testé, jamais atteint
 * une seule fois en jeu. `placerLeviers()` est maintenant PROCÉDURALE, sur le
 * patron de `placerPieges()`/`placerMobilier()`/`placerEpreuves()`/
 * `placerTerrains()` : le gabarit dit COMBIEN (`structure.leviers.min/max`),
 * l'assembleur CHOISIT la porte à verrouiller (une arête de l'ARBRE COUVRANT,
 * jamais une boucle) et la case du levier.
 *
 * ⚠ Les leviers ne sont PAS thématiques (René, 2026-09-06) — contrairement au
 * terrain de glace, aucun filtre `boite`/`theme`.
 *
 * Ce fichier prouve, sur la génération RÉELLE (pas des positions posées à la
 * main comme le fait déjà `PortesExplorationTest` pour la moitié
 * « consommation ») :
 *   1-2. un levier est effectivement posé dans un donjon ORDINAIRE, et la
 *        porte qu'il verrouille porte `verrou = {type: levier, levier_id}` ;
 *   3.   L'INVARIANT DUR : le levier reste atteignable depuis la salle de
 *        départ sans franchir AUCUNE porte verrouillée (pas seulement la
 *        sienne — voir le commentaire de ce test) ;
 *   4.   le format de sortie reste `{x, y, levier_id}`, sans index de salle ;
 *   5.   `actionner_levier` est RÉELLEMENT offert au contact d'un levier
 *        PROCÉDURALEMENT posé, et ouvre la porte — la preuve de bout en bout
 *        que l'option, jamais atteinte jusqu'ici, l'est enfin ;
 *   6.   les leviers se posent quel que soit le thème du groupe ;
 *   +    le taux de renoncement au verrou, mesuré, et forcé au besoin.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class]);
});

/** Gabarit par `type_jalon` — nom volontairement distinct de `gabaritNormal()`
 *  (CouloirsTest.php) : deux fonctions globales de même nom, dans deux
 *  fichiers de test chargés par le même process Pest, casseraient au
 *  chargement (redéclaration fatale). */
function gabaritDeJalon(string $typeJalon): GabaritQuete
{
    return GabaritQuete::query()->where('type_jalon', $typeJalon)->firstOrFail();
}

/** Plafond de pas large mais SÛR pour une BFS qui couvre toute la carte — même
 *  formule que `placerLeviers()`. */
function porteeMaxPourGrille(array $cases): int
{
    return count($cases) * count($cases[0] ?? []);
}

/**
 * La porte qui porte `verrou.levier_id === $id`, ou null.
 *
 * @param  list<array<string, mixed>>  $portes
 * @return array<string, mixed>|null
 */
function porteDuLevier(array $portes, string $levierId): ?array
{
    foreach ($portes as $porte) {
        if (($porte['verrou']['type'] ?? null) === 'levier' && ($porte['verrou']['levier_id'] ?? null) === $levierId) {
            return $porte;
        }
    }

    return null;
}

/**
 * L'invariant, modélisé comme le joue vraiment un groupe : PAS « toutes les
 * portes verrouillées ouvertes à la fois » (trop laxiste — ça validerait un
 * levier qui dépend en fait d'un autre), NI « toutes bloquées à la fois »
 * (trop strict — ça REJETTE à tort une dépendance séquentielle parfaitement
 * jouable : lever A ouvre la salle où se trouve le levier B, qui ouvre la
 * suite). C'est un point fixe par vagues : on part sans rien tiré, on calcule
 * l'atteignable, tout levier ATTEINT à cette vague est « tiré » (sa porte
 * s'ouvre), on recalcule, on recommence — jusqu'à ce que plus rien ne bouge.
 * Un VRAI blocage circulaire (A derrière B, B derrière A) ne bouge jamais dès
 * la première vague ; une chaîne séquentielle (A ouvre la salle de B) se
 * résout en autant de vagues que de maillons. Le test échoue seulement si des
 * leviers restent à jamais hors d'atteinte.
 *
 * @param  list<list<string>>  $cases
 * @param  list<array<string, mixed>>  $portes
 * @param  list<array{x: int, y: int, levier_id: string}>  $leviers
 * @return list<string>  levier_id restés HORS d'atteinte au point fixe (vide = tout est atteignable)
 */
function leviersJamaisAtteints(array $cases, array $portes, array $leviers, array $depart): array
{
    $porteeMax = porteeMaxPourGrille($cases);
    $tires = []; // levier_id => true

    do {
        $bouge = false;

        $portesTest = array_map(function (array $p) use ($tires) {
            $verrouille = ($p['etat'] ?? null) === MoteurPortes::ETAT_VERROUILLEE;
            $dejaTire = $verrouille && isset($tires[$p['verrou']['levier_id'] ?? null]);
            $p['etat'] = ($verrouille && ! $dejaTire) ? MoteurPortes::ETAT_VERROUILLEE : MoteurPortes::ETAT_OUVERTE;

            return $p;
        }, $portes);

        $grille = new Grille($cases);
        $grille->definirPortes($portesTest);
        $atteignables = $grille->casesAtteignables((int) $depart['x'], (int) $depart['y'], $porteeMax);

        foreach ($leviers as $levier) {
            if (isset($tires[$levier['levier_id']])) {
                continue;
            }
            if (isset($atteignables["{$levier['x']},{$levier['y']}"])) {
                $tires[$levier['levier_id']] = true;
                $bouge = true;
            }
        }
    } while ($bouge);

    return collect($leviers)->pluck('levier_id')->diff(array_keys($tires))->values()->all();
}

// ---------------------------------------------------------------------
// 1-2-4. Le défaut corrigé : un levier est RÉELLEMENT posé, verrouille SA
//        porte, au format canonique {x, y, levier_id}.
// ---------------------------------------------------------------------

it('pose au moins un levier sur un donjon ORDINAIRE, chacun verrouillant sa propre porte au format canonique', function () {
    $gabarit = gabaritDeJalon('normale'); // « Exploration simple » — leviers.min=0, max=1 : PAS garanti à chaque graine
    $assembleur = app(AssembleurCarte::class);

    $auMoinsUn = false;
    $leviersExamines = 0;

    for ($graine = 0; $graine < 40; $graine++) {
        $carte = $assembleur->assembler($gabarit, $graine);

        foreach ($carte['leviers'] as $levier) {
            $auMoinsUn = true;
            $leviersExamines++;

            // Format INCHANGÉ : {x, y, levier_id}, RIEN d'autre — pas d'index
            // de salle (EtatGroupe le dérive des coordonnées, cf. terrain).
            expect($levier)->toHaveKeys(['x', 'y', 'levier_id'])
                ->and(count($levier))->toBe(3, "graine {$graine} : le levier porte une clé en trop — {$levier['levier_id']}");

            $porte = porteDuLevier($carte['portes'], $levier['levier_id']);
            expect($porte)->not->toBeNull("graine {$graine} : aucune porte ne référence le levier {$levier['levier_id']}")
                ->and($porte['etat'])->toBe(MoteurPortes::ETAT_VERROUILLEE);
        }
    }

    expect($auMoinsUn)->toBeTrue('aucun levier posé sur 40 graines du gabarit « Exploration simple » — la couche resterait morte en pratique malgré le correctif')
        ->and($leviersExamines)->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------
// 3. L'INVARIANT DUR : atteignable sans lever AUCUN verrou, sur des dizaines
//    de graines.
// ---------------------------------------------------------------------

it('chaque levier posé reste atteignable depuis la salle de départ — aucun ne dépend d\'un blocage circulaire', function () {
    // ⚠ Ma première version de ce test bloquait TOUTES les portes verrouillées
    // À LA FOIS et exigeait que chaque levier soit déjà atteignable dans cet
    // état figé. Elle a trouvé un « échec » dès la graine 8 du gabarit boss :
    // levier-1, posé DANS la salle 1, la salle 1 n'étant accessible que par la
    // porte que levier-2 déverrouille. Rejeu à la main : levier-2 se trouve
    // dans un couloir ORDINAIRE (porte simplement fermée, jamais verrouillée)
    // directement accessible depuis le départ — ce n'est donc PAS un blocage,
    // c'est une dépendance SÉQUENTIELLE parfaitement jouable (on tire A, la
    // salle de B s'ouvre, on tire B) — exactement le genre de petite énigme de
    // donjon qu'un levier existe pour offrir. Le test précédent était trop
    // strict et signalait un faux positif.
    //
    // Le bon modèle simule donc la partie par VAGUES (`leviersJamaisAtteints`) :
    // rien de tiré au départ, on calcule l'atteignable, tout levier ATTEINT
    // est tiré, on recalcule, jusqu'à un point fixe. Une chaîne séquentielle
    // se résout en plusieurs vagues ; un VRAI blocage circulaire (A derrière
    // B ET B derrière A) ne bouge JAMAIS dès la première — c'est CE cas-là que
    // `placerLeviers()` doit exclure, pas la dépendance séquentielle.
    $assembleur = app(AssembleurCarte::class);
    $gabarits = [gabaritDeJalon('sous_boss'), gabaritDeJalon('boss_final')];

    $leviersExamines = 0;

    foreach ($gabarits as $gabarit) {
        for ($graine = 0; $graine < 40; $graine++) {
            $carte = $assembleur->assembler($gabarit, $graine);

            if ($carte['leviers'] === []) {
                continue;
            }

            $leviersExamines += count($carte['leviers']);

            $bloques = leviersJamaisAtteints($carte['cases'], $carte['portes'], $carte['leviers'], $carte['spawn_heros'][0]);

            expect($bloques)->toBe([], sprintf(
                'gabarit « %s », graine %d : levier(s) %s jamais atteignables même en tirant tout ce qui devient accessible — blocage circulaire.',
                $gabarit->nom, $graine, implode(', ', $bloques)
            ));
        }
    }

    expect($leviersExamines)->toBeGreaterThan(20, "seulement {$leviersExamines} leviers échantillonnés — trop peu pour que l'invariant prouve quoi que ce soit");
});

// ---------------------------------------------------------------------
// 6. AUCUN filtre de thème — contrairement au terrain de glace.
// ---------------------------------------------------------------------

it('pose les leviers À L\'IDENTIQUE quel que soit le thème du groupe — ils ne sont pas thématiques', function () {
    $gabarit = gabaritDeJalon('boss_final'); // le gabarit qui en demande le plus (1 à 2)
    $assembleur = app(AssembleurCarte::class);

    // Graine cherchée (pas fixée à l'aveugle) : il en faut une qui pose
    // vraiment au moins un levier, sinon le test ne prouve rien.
    $graine = null;
    $reference = null;

    for ($g = 0; $g < 60; $g++) {
        $leviers = $assembleur->assembler($gabarit, $g, AssembleurCarte::CHANCE_PASSAGE_SECRET, null)['leviers'];
        if ($leviers !== []) {
            $graine = $g;
            $reference = $leviers;
            break;
        }
    }

    expect($graine)->not->toBeNull('aucune graine, sur 60 essais, ne pose de levier — impossible de prouver l\'indépendance au thème');

    // `horreur_des_glaces` : le SEUL thème dont le catalogue Terrain porte des
    // entrées (`boite`) — s'il y en a un qui pourrait perturber les leviers par
    // un effet de bord (une case de terrain qui grignoterait un candidat), ce
    // serait celui-là. `null` (aucun thème) et deux thèmes de la rotation
    // active complètent l'échantillon.
    $themes = [null, 'dread_moon', 'horde_ogre', 'horreur_des_glaces'];

    foreach ($themes as $theme) {
        $leviers = $assembleur->assembler($gabarit, $graine, AssembleurCarte::CHANCE_PASSAGE_SECRET, $theme)['leviers'];
        expect($leviers)->toBe($reference, 'thème « '.($theme ?? 'null')." » a changé la pose des leviers — ils ne sont pourtant pas thématiques (arbitrage René, 2026-09-06)");
    }
});

// ---------------------------------------------------------------------
// Taux de renoncement — vivant sur le papier, mort en pratique ?
// ---------------------------------------------------------------------

it('mesure le taux de renoncement au verrou, et le FORCE au besoin pour prouver que le contrôle s\'active', function () {
    $gabarit = gabaritDeJalon('sous_boss'); // leviers.min = max = 1 : en veut TOUJOURS exactement un
    $assembleur = app(AssembleurCarte::class);

    $total = 60;
    $renonces = 0;

    for ($graine = 0; $graine < $total; $graine++) {
        if ($assembleur->assembler($gabarit, $graine)['leviers'] === []) {
            $renonces++;
        }
    }

    // Rapporté tel quel, sans normaliser vers un seuil arbitraire : « toujours »
    // dirait que la fonctionnalité reste morte en pratique malgré le
    // correctif ; « jamais » dirait que le contrôle d'abandon n'a jamais eu
    // l'occasion de s'exercer sur un donjon réel.
    fwrite(STDERR, sprintf(
        "\n[LeviersPosesTest] taux de renoncement (gabarit sous-boss, %d graines) : %d/%d (%.1f%%)\n",
        $total, $renonces, $total, $renonces / $total * 100
    ));

    if ($renonces === 0) {
        // Le mécanisme n'a jamais eu l'occasion de refuser sur ce gabarit :
        // on le FORCE, même patron que `TerrainCarteTest` pour son propre
        // renoncement (via Reflection sur la méthode privée, cases/salles/
        // portes construites à la main). Donjon à 2 salles, UNE SEULE arête
        // (l'arbre couvrant ne peut pas boucler en dessous de 5 salles) :
        // verrouiller l'unique porte isole TOUT le reste, et la case de départ
        // — seule case restée atteignable — est exclue des candidats (jamais
        // dans la salle 0). Renoncement garanti, quelle que soit la graine.
        $cases = [['s', 's']];
        $salles = [
            ['x' => 0, 'y' => 0, 'largeur' => 1, 'hauteur' => 1],
            ['x' => 1, 'y' => 0, 'largeur' => 1, 'hauteur' => 1],
        ];
        $portes = [['x' => 0, 'y' => 0, 'cote' => 'e', 'etat' => MoteurPortes::ETAT_FERMEE]];
        $structure = ['leviers' => ['min' => 1, 'max' => 1]];

        $methode = new ReflectionMethod(AssembleurCarte::class, 'placerLeviers');
        $methode->setAccessible(true);

        for ($graine = 0; $graine < 20; $graine++) {
            // `placerLeviers()` prend `$portes` PAR RÉFÉRENCE (4ᵉ paramètre) :
            // `invoke()` ne le supporte pas pour un paramètre par référence,
            // il faut passer par `invokeArgs()` avec une vraie variable dans
            // le tableau d'arguments.
            $portesArg = $portes;
            $resultat = $methode->invokeArgs($assembleur, [$structure, $cases, $salles, &$portesArg, 2, fn () => $graine]);
            expect($resultat)->toBe([], "graine {$graine} : le renoncement forcé n'a PAS renoncé — le mécanisme d'abandon ne s'active plus");
        }
    }

    expect($renonces)->toBeLessThan($total, "renonce sur {$total}/{$total} graines du gabarit sous-boss — la fonctionnalité resterait morte en pratique malgré le correctif");
});

// ---------------------------------------------------------------------
// 5. `actionner_levier` RÉELLEMENT offert au contact d'un levier
//    PROCÉDURALEMENT posé, et qui ouvre la porte — bout en bout.
// ---------------------------------------------------------------------

it('« Actionner le levier » est proposé au contact d\'un levier posé par la génération réelle, et ouvre la porte liée', function () {
    // `PortesExplorationTest` prouve déjà que la CONSOMMATION marche pour un
    // levier posé À LA MAIN (`poserPortes()`) : ce test-ci prouve que la
    // GÉNÉRATION en produit un que la manette peut vraiment atteindre — la
    // moitié qui n'avait jamais été exercée en partie réelle.
    //
    // La graine de carte de `DemarreurQuete::demarrer()` est
    // `crc32("{identifiant}:{position_arc}")` (position_arc=1 pour la première
    // quête, gabarit « Exploration simple »). On choisit donc l'IDENTIFIANT du
    // groupe pour tomber sur une graine qui pose un levier — sans toucher au
    // moteur ni au tirage réel d'une partie.
    $gabarit = gabaritDeJalon('normale');
    $assembleur = app(AssembleurCarte::class);

    $identifiant = null;
    $carteAttendue = null;
    $contact = null;

    for ($i = 0; $i < 150; $i++) {
        $candidat = "levpose-{$i}";
        $graine = crc32("{$candidat}:1");
        $carte = $assembleur->assembler($gabarit, $graine); // chance/thème par défaut = ceux d'un groupe neuf (chance_passage_secret=50, theme_bestiaire=null au 1er démarrage)

        if ($carte['leviers'] === []) {
            continue;
        }

        $levier = $carte['leviers'][0];
        $cases = $carte['cases'];
        $occupees = collect([...$carte['spawn_heros'], ...$carte['spawn_monstres']])
            ->map(fn (array $p) => "{$p['x']},{$p['y']}")->flip();

        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $nx = (int) $levier['x'] + $dx;
            $ny = (int) $levier['y'] + $dy;
            if (($cases[$ny][$nx] ?? 'm') === 's' && ! isset($occupees["{$nx},{$ny}"])) {
                $contact = ['x' => $nx, 'y' => $ny];
                break;
            }
        }

        if ($contact !== null) {
            $identifiant = $candidat;
            $carteAttendue = $carte;
            break;
        }
    }

    expect($identifiant)->not->toBeNull('aucun identifiant de groupe, sur 150 essais, ne produit une carte avec un levier ET une case de contact libre');

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe($identifiant);
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    // Un second héros : sans lui, l'action du seul héros actif ferait
    // basculer le tour en phase monstres, qui n'a rien à faire dans ce test
    // (même raison que `demarrerExplo()` dans PortesExplorationTest).
    $bob = App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob-levier', 'identifiant' => 'bob-levier', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson("/api/groupes/{$identifiant}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // La quête réellement démarrée doit porter EXACTEMENT la carte prédite —
    // sinon la graine ne correspond plus (chance/thème d'un groupe neuf
    // différents de l'hypothèse) et le reste du test ne prouve rien.
    expect($quete->carte->grille['leviers'])->toBe($carteAttendue['leviers']);

    $levier = $quete->carte->grille['leviers'][0];
    $porte = porteDuLevier($quete->carte->grille['portes'], $levier['levier_id']);
    expect($porte)->not->toBeNull()->and($porte['etat'])->toBe(MoteurPortes::ETAT_VERROUILLEE);

    // Téléporte le héros au contact prédit — comme `demarrerQueteAvecMonstre()`
    // le fait pour un monstre : ce n'est PAS le déplacement qu'on prouve ici,
    // mais l'option et sa résolution, une fois sur place.
    $etat = $quete->etatsPersonnages()->where('personnage_id', $hero->id)->firstOrFail();
    $etat->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $optionId = "actionner_levier_{$levier['x']}_{$levier['y']}";
    $options = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options']);

    // ⚠ La preuve manquante depuis toujours : cette option vient d'un levier
    // POSÉ PAR LA GÉNÉRATION RÉELLE, pas d'une position écrite à la main.
    expect($options->pluck('id'))->toContain($optionId);

    desFiges(array_fill(0, 8, 1)); // que des crânes : le jet de Body réussit à coup sûr

    $this->postJson("/api/groupes/{$identifiant}/choix", ['option_id' => $optionId])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'actionner_levier')
        ->assertJsonPath('resultat.force', true);

    $porteApres = porteDuLevier($quete->fresh()->carte->grille['portes'], $levier['levier_id']);
    expect($porteApres['etat'])->toBe(MoteurPortes::ETAT_OUVERTE);
});
