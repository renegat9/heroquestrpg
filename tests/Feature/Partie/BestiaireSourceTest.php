<?php

declare(strict_types=1);

use App\Models\Monstre;
use App\Partie\EffetsGlobauxQuete;
use Database\Seeders\MonstreSeeder;

/**
 * Provenance du BESTIAIRE (reference/16_armurerie.md §4.4-4.6).
 *
 * Trois origines, et elles ne doivent pas se mélanger :
 *
 *  1. les **8 monstres de base + l'Abomination**, dont les stats viennent
 *     désormais des CARTES OFFICIELLES scannées par René
 *     (`reference/20_cartes_monstres.md`, 2026-10-05) — « Valeurs des
 *     cartes, partout ». `sjeng-monsters.pdf` (Ye Olde Inn) les avait sourcées
 *     en premier le 2026-08-09 et reste confirmé sur 3/8 (Gobelin, Squelette,
 *     Orque) ; les cartes CORRIGENT les 4 autres (Zombie, Momie, Guerrier du
 *     Chaos, Gargouille) et ajoutent l'Abomination, jusque-là `⚠ non trouvé` ;
 *  2. les **créatures d'extension**, dont les stats viennent des LIVRETS
 *     officiels via `reference/18_extensions.md` — meilleure source que les
 *     cartes de fans, qui divergent sur plusieurs valeurs ;
 *  3. nos **créations** (Champion, Liche, Seigneur…), qui n'ont pas de carte et
 *     n'en auront pas.
 *
 * Ce fichier fige (1) et (2) : ce sont les seules valeurs opposables, et rien
 * ne doit les faire dériver en silence.
 */
beforeEach(function () {
    $this->seed([MonstreSeeder::class]);
});

/** [déplacement, attaque, défense, body, mind] du catalogue, par nom. */
function statsDe(string $nom): array
{
    $m = Monstre::where('nom_base', $nom)->firstOrFail();

    return [$m->deplacement, $m->attaque, $m->defense, $m->pv_body, $m->pv_mind];
}

it('porte les 8 monstres de base + l\'Abomination exactement comme leurs cartes officielles (reference/20, scans de René 2026-10-05)', function () {
    // Décision de René, 2026-10-05 : « Valeurs des cartes, partout ». Confirme
    // Gobelin/Squelette/Orque contre `sjeng-monsters.pdf`, CORRIGE Zombie/
    // Momie/Guerrier du Chaos/Gargouille, et ajoute l'Abomination — monstre
    // de la boîte de BASE (LR p. 4), plus `⚠ non trouvé`.
    $cartes = [
        'Gobelin' => [10, 2, 1, 1, 1],
        'Squelette' => [6, 2, 2, 1, 0],
        'Zombie' => [5, 2, 3, 1, 0],             // carte : Déplacement 5 (était 4)
        'Orque' => [8, 3, 2, 1, 2],
        'Fimir' => [6, 3, 3, 1, 3],               // nom 1989, pas de carte 2021
        'Momie' => [4, 3, 4, 2, 0],               // carte : Body 2 (était 1)
        'Guerrier du Chaos' => [7, 4, 4, 3, 3],   // carte : Déplacement 7/Attaque 4/Body 3 (était 6/3/1)
        'Gargouille' => [6, 4, 5, 3, 4],          // carte : Défense 5/Body 3 (était 4/1)
        'Abomination' => [6, 3, 3, 2, 3],         // carte, jusque-là absente du catalogue
    ];

    foreach ($cartes as $nom => $attendu) {
        expect(statsDe($nom))->toBe($attendu, "{$nom} : bloc de stats");
    }

    $abomination = Monstre::where('nom_base', 'Abomination')->firstOrFail();
    expect($abomination->tier)->toBe('base')
        ->and($abomination->boite)->toBe('base', 'Abomination : monstre de la boîte de base, comme les 7 autres')
        ->and((array) $abomination->capacites)->toBe([], 'Abomination : aucun texte de capacité sur la carte');
});

// ⚠ PRINCIPE ABANDONNÉ le 2026-10-05 (René) : il y avait ici un test
// « donne 1 SEUL point de Body à tout monstre de base, comme au plateau ».
// Les cartes officielles le contredisent directement — Momie 2, Guerrier du
// Chaos 3, Gargouille 3 — donc ce n'était pas une règle du plateau, c'était ce
// qu'un PDF de fan à 1 Body partout laissait croire. Voir
// `docs/regles/bestiaire-et-rencontres.md` pour ce qui remplace ce garde-fou
// (le `cout`, pas le tier) et le test ci-dessous, qui porte la même intention
// — ne jamais remettre dans le tas des « faibles » un monstre trop endurant
// pour y figurer plusieurs fois — par le mécanisme qui existe réellement.

it('porte les créatures d\'extension telles que les livrets les chiffrent', function () {
    // Valeurs de reference/18_extensions.md, tirées des livrets Hasbro. Quand
    // les cartes de fans divergent (Gremlin des glaces à 2 Body, Ours polaire à
    // 3+3), c'est le LIVRET qui gagne — règle du doc 16.
    $livrets = [
        // Rise of the Dread Moon
        'Cultiste du Dread' => [7, 2, 2, 1, 2],
        'Assassin' => [10, 5, 3, 2, 3],
        'Garde-mage' => [8, 4, 4, 3, 3],
        'Spectre' => [8, 3, 3, 1, 0],
        // ⚠ Defend **3** et non 4 : voir la divergence DÉCLARÉE plus bas.
        'Ombre du Dread' => [9, 6, 3, 5, 5],
        // The Mage of the Mirror
        'Guerrier elfe' => [6, 4, 3, 3, 2],
        'Loup géant' => [9, 6, 3, 5, 1],
        // Sinestra, l'archemage (boss final, quête 9 — p. 33). ⚠ Mind 9 : la
        // plus haute valeur du bestiaire, et c'est la fiche qui le dit.
        'Archimage elfe' => [8, 4, 4, 4, 9],
        'Ogre' => [4, 6, 4, 5, 2],
        // The Frozen Horror
        'Gremlin des glaces' => [10, 2, 3, 3, 3],
        'Yéti' => [8, 3, 3, 5, 2],
        'Horreur des Glaces' => [8, 5, 4, 6, 4],
        // Against the Ogre Horde
        'Ogre guerrier' => [6, 5, 4, 5, 1],
        'Ogre champion' => [6, 5, 4, 6, 1],
        'Ogre commandant' => [4, 6, 5, 6, 2],
        'Seigneur ogre' => [4, 6, 6, 10, 5],
        // Jungles of Delthrak
        'Rejeton putride' => [3, 0, 0, 1, 0],
        'Tisseur putride' => [7, 2, 2, 1, 2],
        'Crâne putride' => [6, 3, 2, 2, 0],
        'Raptor' => [8, 3, 2, 2, 3],
        'Rampant putride' => [7, 4, 4, 3, 4],
        'Serpent géant' => [8, 4, 3, 6, 3],
        'Singe géant' => [8, 4, 3, 7, 5],
    ];

    foreach ($livrets as $nom => $attendu) {
        expect(statsDe($nom))->toBe($attendu, "{$nom} : bloc de stats");
    }
});

it('déclare ses DIVERGENCES de stat, une par une, avec leur raison', function () {
    // ⚠ Ce projet ne seede pas une valeur que les livrets ne sourcent pas. Quand
    // il s'en écarte quand même, l'écart doit être NOMMÉ ici — sans quoi le
    // tableau du dessus se contenterait d'enregistrer la dérive au lieu de la
    // signaler. Une divergence déclarée est un arbitrage ; une divergence
    // silencieuse est un bug qui a l'air d'une donnée.
    $divergences = [
        'Ombre du Dread' => [
            'livret' => [9, 6, 4, 5, 5],   // Dread Wraith, Rise of the Dread Moon
            'chez_nous' => [9, 6, 3, 5, 5],
            'raison' => 'Éthérée : une arme ne la blesse que sur un bouclier noir (1/6) '
                .'contre autant de dés qui parent sur 1/6, donc les dégâts nets valent '
                .'(attaque − défense)/6. À 4 de défense elle était mathématiquement '
                .'invulnérable aux armes en dessous de 5 dés, et encore 30 tours à 5. '
                .'À 3 elle retombe dans la fourchette du Seigneur (15 tours à 5 dés). '
                .'Arbitrage de René, 2026-09-04.',
        ],
    ];

    foreach ($divergences as $nom => $ecart) {
        expect(statsDe($nom))->toBe($ecart['chez_nous'], "{$nom} : la divergence déclarée n'est plus celle du catalogue");
        expect($ecart['chez_nous'])->not->toBe($ecart['livret'], "{$nom} : plus aucune divergence — retirer l'entrée");
        expect($ecart['raison'])->not->toBeEmpty("{$nom} : divergence sans raison écrite");
    }
});

it('porte le Dragon de First Light exactement comme sa carte', function () {
    // Source : carte de monstre « Dragon » photographiée par René
    // (2026-09-30), © 2024 Hasbro — reference/18_extensions.md §6.1bis.
    // « Move 10 · Attack 5 dés · Defend 5 dés · Body 7 · Mind 6. »
    $dragon = Monstre::where('nom_base', 'Dragon')->firstOrFail();

    expect(statsDe('Dragon'))->toBe([10, 5, 5, 7, 6])
        ->and($dragon->tier)->toBe('boss')
        ->and($dragon->boite)->toBe('first_light')
        ->and($dragon->emprise())->toBe(['l' => 1, 'h' => 2], 'Dragon : grand monstre, 2 cases');

    // « The dragon uses Draconic Flight and may cast Ball of Flame at
    // will. » — un seul sort, pas un répertoire de sorcier nommé (voir le
    // commentaire du Champion dans MonstreSeeder), et ce sort échappe au
    // compteur d'usages POUR CE MONSTRE SEUL.
    expect($dragon->archetype_lanceur)->toBeNull()
        ->and($dragon->sorts_dread)->toBe(['Boule de Flammes'])
        ->and(app(App\Partie\MoteurDread::class)->sortAVolonte(
            tap(new App\Models\InstanceMonstre, fn ($i) => $i->setRelation('monstre', $dragon)),
        ))->toBe('Boule de Flammes');
});

it('donne aux créatures à distance leur attaque de tir ET leur malus au contact', function () {
    // « Attack 4 (1 si adjacent) » : deux valeurs distinctes, pas une.
    // L'Orque archer (Against the Ogre Horde p. 8, Q6) a rejoint les deux
    // archers de Jungles le 2026-10-02.
    foreach (['Archer elfe' => 4, 'Gobelin archer' => 2, 'Archer squelette' => 2, 'Orque archer' => 3] as $nom => $tir) {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();

        expect($m->portee)->toBe('distance', "{$nom} : portée")
            ->and((int) $m->attaque_distance)->toBe($tir, "{$nom} : dés en tir")
            ->and((int) $m->attaque)->toBeLessThan($tir, "{$nom} : doit perdre des dés au contact");
    }
});

it('dérive la variante À DISTANCE générique (Q6) de son monstre de base, pour tout thème', function () {
    // Against the Ogre Horde p. 8, verbatim : « Zargon may place a standard
    // monster or a ranged version of that same monster type (in this quest
    // pack, that means skeletons, orcs, and goblins). A ranged monster rolls
    // Attack dice equal to their standard attack score against any
    // non-adjacent target in their line of sight. If their target is
    // adjacent, they roll 1 Attack die. »
    $liens = [
        'Gobelin archer' => 'Gobelin',
        'Archer squelette' => 'Squelette',
        'Orque archer' => 'Orque',
    ];

    foreach ($liens as $nomVariante => $nomBase) {
        $variante = Monstre::where('nom_base', $nomVariante)->firstOrFail();
        $base = Monstre::where('nom_base', $nomBase)->firstOrFail();

        // Le LIEN est déclaré, pas déduit d'une convention de nommage.
        expect($variante->variante_distance_de)->toBe($nomBase, "{$nomVariante} : lien vers sa base")
            ->and($variante->estVarianteDistance())->toBeTrue()
            // La FORMULE : même déplacement/défense/Body/Mind que la base…
            ->and($variante->deplacement)->toBe($base->deplacement, "{$nomVariante} : déplacement")
            ->and($variante->defense)->toBe($base->defense, "{$nomVariante} : défense")
            ->and($variante->pv_body)->toBe($base->pv_body, "{$nomVariante} : Body")
            ->and($variante->pv_mind)->toBe($base->pv_mind, "{$nomVariante} : Mind")
            // …1 SEUL dé au contact…
            ->and((int) $variante->attaque)->toBe(1, "{$nomVariante} : attaque au contact")
            // …et les dés d'attaque STANDARD de la base, à distance.
            ->and((int) $variante->attaque_distance)->toBe((int) $base->attaque, "{$nomVariante} : attaque à distance = attaque standard de la base");

        // GÉNÉRIQUE (Q6, René 2026-10-02) : `boite: null`, utilisable dans
        // TOUS les thèmes — jamais réservée à Jungles of Delthrak ni à Against
        // the Ogre Horde, qui ne fait qu'illustrer la règle.
        expect($variante->boite)->toBeNull("{$nomVariante} : doit être générique (boite null)");
    }

    // Le monstre de base lui-même n'est PAS une variante.
    expect(Monstre::where('nom_base', 'Orque')->firstOrFail()->estVarianteDistance())->toBeFalse();
});

it('n\'accorde aucune capacité que le moteur n\'applique pas', function () {
    // Même règle que pour les clés d'objet : une capacité déclarée sans lecteur
    // est une promesse faite au joueur que rien ne tient. Les traits des
    // extensions qu'on ne sait pas porter (Agile, Venomous, Spawn…) sont donc
    // ABSENTS du seeder, et documentés en reference/16 §4.6.
    $implementees = ['invocation', 'frappe_de_zone', 'regeneration',
        'resistance_magique', 'charge', 'deux_attaques', 'vol', 'peur',
        // Mots-clés de Jungles of Delthrak, portés le 2026-08-10…
        'agile', 'venimeux', 'tacticien', 'racines_entravantes', 'spawn', 's_accroche',
        // …et l'éthéré de Rise of the Dread Moon.
        'ethere',
        // The Frozen Horror (doc 18 §2, plan glace phase 3) : l'étreinte du
        // Yéti (`ResolveurTour::resoudreAttaqueMonstre()`/`jouerMonstre()`/
        // `saignerParConditions()`) et le vol du Gremlin des glaces
        // (`MoteurDread::voler()`).
        'etreinte', 'vol_objet',
        // First Light (carte Dragon, 2026-09-30) : `vol_draconique` (Draconic
        // Flight — `MoteurDread::tentativeVolDraconique()`) et `sort_a_volonte`
        // (Boule de Flammes sans compteur d'usage pour ce monstre seul —
        // `MoteurDread::sortAVolonte()`).
        'vol_draconique', 'sort_a_volonte',
        // MONSTRE À PHASES (chantier transverse 2026-10-04, Against the Ogre
        // Horde p. 6) : `increvable_une_fois` (Sir Ragnar — « the first time
        // […] reduced to 0, instead reduced to 1 »), `reactions_defense`
        // (Resilience/Demon Wings — `ignore_degats_attaque`, UNIQUE mécanique
        // portée de cette famille pour l'instant) et `recompense_reddition`
        // (Gruzbella vaincue : « elle s'incline » et paie 1000 po au lieu de
        // mourir). Les trois sont lues à l'UNIQUE point de passage de la mort
        // d'un monstre, `MoteurDegats::infligerAMonstre()`.
        'increvable_une_fois', 'reactions_defense', 'recompense_reddition',
        // Assassin (Rise of the Dread Moon, carte scannée 2026-10-05, « Each
        // Assassin may attack diagonally. ») : troisième lecteur du mot-clé
        // déjà porté pour les armes longues et les mercenaires —
        // `ResolveurTour::jouerMonstre()` (`$diagonalesMonstre`).
        'attaque_diagonale',
        // Wizards of Morcar, les Sorciers du Dread (G1504 p. 10) : « Each spell may
        // only be used once per quest » — `MoteurDread::reinitialiserUsagesInstance()`
        // / `sortsLancables()` / `consommerUsage()`.
        'sorts_uniques',
        // Wizards of Morcar, vague 2B : l'embuscade du Dreadshifter
        // (`MoteurEmbuscade`, déclencheur de la carte) et le coup de corne du
        // Minotaure (`ResolveurTour::coupsDeCorne()`).
        'embuscade', 'coup_de_corne',
        // Jungles of Delthrak, Gruulob (2026-10-09) : le TIR AU CHOIX d'un monstre de
        // mêlée (`Monstre::aTirAuChoix()`, lu par `ResolveurTour::jouerMonstre()`) et
        // l'EFFET GLOBAL de quête (`EffetsGlobauxQuete`, lu par
        // `InstanceMonstre::bonusEffetGlobalQuete()`).
        'tir_au_choix', 'effet_global_quete'];

    $inconnues = collect(Monstre::all())
        ->flatMap(fn (Monstre $m) => array_map(
            fn ($cle, $valeur) => is_int($cle) ? $valeur : $cle,
            array_keys((array) $m->capacites),
            (array) $m->capacites,
        ))
        ->unique()
        ->reject(fn ($c) => in_array($c, $implementees, true))
        ->values()
        ->all();

    expect($inconnues)->toBe([], 'capacité(s) sans lecteur : '.implode(', ', $inconnues));
});

it('n\'accorde à l\'intérieur de `reactions_defense` que des mécaniques que le moteur applique', function () {
    // Même registre, un niveau plus bas : `reactions_defense` elle-même est
    // déclarée ci-dessus, mais rien n'empêchait d'y glisser un troisième nom
    // (« annule_sort », « redirige_attaque ») sans lecteur — EXACTEMENT le
    // défaut que ce fichier traque pour `capacites` elle-même. Seule
    // `ignore_degats_attaque` est lue par `MoteurDegats::infligerAMonstre()`
    // aujourd'hui (Break/Dispel/Deflect restent nommées, absentes du
    // catalogue — voir `docs/regles/bestiaire-et-rencontres.md`).
    $implementees = ['ignore_degats_attaque'];

    $inconnues = collect(Monstre::all())
        ->flatMap(fn (Monstre $m) => (array) data_get($m->capacites, 'reactions_defense', []))
        ->unique()
        ->reject(fn ($c) => in_array($c, $implementees, true))
        ->values()
        ->all();

    expect($inconnues)->toBe([], 'mécanique(s) réactive(s) sans lecteur : '.implode(', ', $inconnues));
});

it('chaîne un monstre à PHASES sans trou, et termine la chaîne', function () {
    // Registre testé DANS LES DEUX SENS (hard rule) : tout `phase_suivante`
    // déclaré nomme une ligne qui EXISTE, et toute chaîne se termine (pas de
    // boucle, pas de maillon qui pointe vers du vide).
    $phases = Monstre::whereNotNull('phase_suivante')->pluck('phase_suivante', 'nom_base');

    foreach ($phases as $nomBase => $suivante) {
        expect(Monstre::where('nom_base', $suivante)->exists())
            ->toBeTrue("{$nomBase} : sa phase suivante « {$suivante} » n'existe pas au catalogue.");
    }

    // Chaque chaîne connue se termine en un nombre BORNÉ d'étapes (3 au plus,
    // Gretzl/Gruzbella) — une boucle infinie planterait ce test, jamais le jeu.
    foreach (['Gruzbella Hammerhand', 'Spawn of the Pit', 'Gretzl la Porte-Fléau', 'Gruulob, Sorcier Gobelin Corrompu'] as $debut) {
        $nom = $debut;
        $vus = [];

        for ($i = 0; $i < 5; $i++) {
            expect(in_array($nom, $vus, true))->toBeFalse("{$debut} : la chaîne de phases boucle sur {$nom}.");
            $vus[] = $nom;
            $nom = Monstre::where('nom_base', $nom)->firstOrFail()->phase_suivante;

            if ($nom === null) {
                break;
            }
        }

        expect($nom)->toBeNull("{$debut} : la chaîne de phases ne se termine jamais.");
    }
});

it('porte les stats de Gruzbella Hammerhand EXACTEMENT comme ses trois phases (Ogre Horde p. 21)', function () {
    // « Confiante 4/6/5/5/4 → Déterminée 5/5/7/5/4 → Imprudente 6/1/8/5/4 »
    // (A/D/M/B/Mi) — Body et Mind identiques aux trois phases, seules
    // l'Attaque, la Défense et le Déplacement bougent.
    expect(statsDe('Gruzbella Hammerhand'))->toBe([5, 4, 6, 5, 4])
        ->and(statsDe('Gruzbella Déterminée'))->toBe([7, 5, 5, 5, 4])
        ->and(statsDe('Gruzbella Imprudente'))->toBe([8, 6, 1, 5, 4]);

    expect(Monstre::where('nom_base', 'Gruzbella Hammerhand')->firstOrFail()->phase_suivante)
        ->toBe('Gruzbella Déterminée')
        ->and(Monstre::where('nom_base', 'Gruzbella Déterminée')->firstOrFail()->phase_suivante)
        ->toBe('Gruzbella Imprudente')
        ->and(Monstre::where('nom_base', 'Gruzbella Imprudente')->firstOrFail()->phase_suivante)
        ->toBeNull();

    // Vaincue, elle s'incline : 1000 po, jamais une vraie mort — DÉCLARÉE
    // SEULEMENT sur la DERNIÈRE phase (c'est là, et seulement là, qu'elle est
    // lue par le point de passage unique).
    expect(Monstre::where('nom_base', 'Gruzbella Imprudente')->firstOrFail()->capacites['recompense_reddition'] ?? null)
        ->toBe(['or' => 1000]);
});

it('porte les stats de Spawn of the Pit EXACTEMENT comme ses deux phases (Ogre Horde p. 21)', function () {
    // « 4/3/6/4/3 → Enraged 5/1/10/6/1 » (A/D/M/B/Mi).
    expect(statsDe('Spawn of the Pit'))->toBe([6, 4, 3, 4, 3])
        ->and(statsDe('Spawn of the Pit déchaîné'))->toBe([10, 5, 1, 6, 1]);

    expect(Monstre::where('nom_base', 'Spawn of the Pit')->firstOrFail()->phase_suivante)
        ->toBe('Spawn of the Pit déchaîné');
});

it('porte les stats de Gretzl la Porte-Fléau EXACTEMENT comme ses trois phases (Jungles of Delthrak q. 12A)', function () {
    // « Phase 1 M6 A4 D3 B5 Mi6 ; Demonspider M8 A5 D4 B4 Mi3 (Agile,
    // Venomous) ; Demonape M8 A6 D2 B6 Mi1 (Agile) ».
    expect(statsDe('Gretzl la Porte-Fléau'))->toBe([6, 4, 3, 5, 6])
        ->and(statsDe('Demonspider'))->toBe([8, 5, 4, 4, 3])
        ->and(statsDe('Demonape'))->toBe([8, 6, 2, 6, 1]);

    $demonspider = Monstre::where('nom_base', 'Demonspider')->firstOrFail();
    expect(in_array('agile', $demonspider->capacites, true))->toBeTrue()
        ->and(in_array('venimeux', $demonspider->capacites, true))->toBeTrue();

    $demonape = Monstre::where('nom_base', 'Demonape')->firstOrFail();
    expect(in_array('agile', $demonape->capacites, true))->toBeTrue();

    // Le répertoire (3 sorts déjà semés) et la défense réactive restent les
    // MÊMES dans les trois phases — « toujours le même monstre ».
    foreach (['Gretzl la Porte-Fléau', 'Demonspider', 'Demonape'] as $nom) {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();
        expect($m->archetype_lanceur)->toBe('gretzl_porte_fleau', "{$nom} : archétype de sorts")
            ->and(in_array('ignore_degats_attaque', (array) ($m->capacites['reactions_defense'] ?? []), true))
            ->toBeTrue("{$nom} : Demon Wings")
            // « *Gretzl may choose to fire at range at any hero in her line of
            // sight » — l'astérisque porte sur l'attaque des TROIS formes (p. 35).
            ->and(in_array('tir_au_choix', (array) $m->capacites, true))
            ->toBeTrue("{$nom} : tir au choix");
    }
});

it('porte Gruulob, Sorcier Gobelin Corrompu EXACTEMENT comme ses deux formes (Jungles of Delthrak q. 8, p. 27)', function () {
    // « Gruulob, Blighted Goblin Warlock » : Move 6 · Attack 3 · Defend 4 ·
    // Body 4 · Mind 5 ; « Gruulob, Demon Form » : Move 6 · Attack 4 · Defend 5 ·
    // Body 3 · Mind 4 (livret F9907 p. 27, relu à l'image).
    expect(statsDe('Gruulob, Sorcier Gobelin Corrompu'))->toBe([6, 3, 4, 4, 5])
        ->and(statsDe('Gruulob, Forme Démoniaque'))->toBe([6, 4, 5, 3, 4]);

    $phase1 = Monstre::where('nom_base', 'Gruulob, Sorcier Gobelin Corrompu')->firstOrFail();
    $phase2 = Monstre::where('nom_base', 'Gruulob, Forme Démoniaque')->firstOrFail();

    expect($phase1->phase_suivante)->toBe('Gruulob, Forme Démoniaque')
        ->and($phase2->phase_suivante)->toBeNull()
        ->and($phase1->tier)->toBe('boss')
        ->and($phase1->boite)->toBe('jungles_delthrak')
        // Le répertoire de sorts vaut pour les DEUX formes (« still considered the
        // same monster for game effects such as spells ») : l'archétype est posé
        // sur chacune, comme pour Gretzl.
        ->and($phase1->archetype_lanceur)->toBe('gruulob_sorcier_gobelin')
        ->and($phase2->archetype_lanceur)->toBe('gruulob_sorcier_gobelin');

    // Aucune capacité RÉACTIVE dans le livret (aucune `reactions_defense`). Le TIR AU CHOIX
    // vaut pour les DEUX formes (« In both forms… ») ; l'EFFET GLOBAL de quête pour la
    // première seule — c'est elle qui entre dans la quête, figée au démarrage.
    expect($phase1->aTirAuChoix())->toBeTrue('Gruulob : tir au choix, première forme')
        ->and($phase2->aTirAuChoix())->toBeTrue('Gruulob : tir au choix, forme démoniaque')
        ->and(array_key_exists('reactions_defense', (array) $phase1->capacites))->toBeFalse()
        ->and(array_key_exists('effet_global_quete', (array) $phase2->capacites))->toBeFalse()
        ->and($phase1->capacites['effet_global_quete'] ?? null)->toBe([
            'titre' => 'Les gobelins de Gruulob', 'faction' => 'Gobelin', 'volee' => 'attaque', 'des' => 1,
        ]);

    // Le répertoire tient en TROIS sorts déjà portés par leur carte (Summon Orcs
    // dans sa variante gobeline).
    expect(config('archetypes_lanceurs.gruulob_sorcier_gobelin.sorts'))->toBe([
        'Étreinte des Ronces', 'Canaliser l\'Effroi', 'Invocation de gobelins',
    ]);
});

it('déclare tir_au_choix et effet_global_quete là où le livret les porte, et nulle part ailleurs', function () {
    // Registre testé DANS LES DEUX SENS (hard rule) : chaque clé nommée dans
    // `$implementees` est lue par un moteur (liste ci-dessus), et chaque clé lue est
    // déclarée — ici, exactement sur les lignes que le livret désigne. Une clé qui glisserait
    // sur une autre créature changerait le combat sans que rien ne le dise.
    $tirAuChoix = Monstre::all()->filter(fn (Monstre $m) => $m->aTirAuChoix())->pluck('nom_base')->sort()->values()->all();
    $effets = Monstre::all()
        ->filter(fn (Monstre $m) => is_array($m->capacites) && array_key_exists('effet_global_quete', $m->capacites))
        ->pluck('nom_base')->sort()->values()->all();

    // Gretzl (ses trois formes) : « *Gretzl may choose to fire at range… » (p. 35).
    expect($tirAuChoix)->toBe(['Demonape', 'Demonspider', 'Gretzl la Porte-Fléau', 'Gruulob, Forme Démoniaque', 'Gruulob, Sorcier Gobelin Corrompu'])
        ->and($effets)->toBe(['Gruulob, Sorcier Gobelin Corrompu']);
});

it('donne à l\'effet global une faction présente au catalogue, une volée lue et un dé au moins', function () {
    // Même registre, côté PARAMÈTRES : une faction qui ne désigne aucune ligne du catalogue
    // ne toucherait aucun gobelin, et une volée sans lecteur serait une promesse muette.
    $params = Monstre::where('nom_base', 'Gruulob, Sorcier Gobelin Corrompu')->firstOrFail()->capacites['effet_global_quete'];

    expect(Monstre::where('nom_base', $params['faction'])->exists())->toBeTrue("faction « {$params['faction']} » absente du catalogue")
        ->and(EffetsGlobauxQuete::VOLEES)->toContain($params['volee'])
        ->and($params['des'])->toBeGreaterThanOrEqual(1)
        ->and($params['titre'])->not->toBeEmpty();
});

it('donne au Sorcier du Dread (Prophecy of Telor) le répertoire limité par son palier', function () {
    $sorcier = Monstre::where('nom_base', 'Sorcier du Dread')->firstOrFail();

    expect($sorcier->tier)->toBe('sous_boss')
        ->and($sorcier->archetype_lanceur)->toBe('sorcier_dread_telor');

    // Le CATALOGUE déclare les cinq sorts des deux apparitions — c'est le
    // filtre par palier de `MoteurDread::sortsDisponibles()`, pas le
    // catalogue, qui retire les deux sorts `boss` pour un sous-boss.
    expect(config('archetypes_lanceurs.sorcier_dread_telor.sorts'))->toBe([
        'Boule de Flammes', 'Tourmente', 'Frayeur', 'Nuée d\'Effroi', 'Commandement',
    ]);
});

it('donne à Sir Ragnar `increvable_une_fois`, sans jamais le déclarer `phases`', function () {
    // Sa carte ne change PAS de statistiques : elle ne meurt pas une
    // première fois, point. `phase_suivante` doit donc rester `null`.
    $ragnar = Monstre::where('nom_base', 'Sir Ragnar')->firstOrFail();

    expect($ragnar->phase_suivante)->toBeNull()
        ->and(in_array('increvable_une_fois', $ragnar->capacites, true))->toBeTrue()
        ->and($ragnar->tier)->toBe('boss');
});

it('tire Gretzl ou Gruulob comme boss du thème Jungles of Delthrak — jamais une de leurs FORMES', function () {
    // Vérifie l'intégration au générateur : le pool de rencontre finale du
    // gabarit « Confrontation finale » nomme leurs archétypes, leur `boite` les
    // range dans le thème, et la rotation déterministe de
    // `DemarreurQuete::acheterMonstres()` ne peut tirer que leurs PREMIÈRES
    // phases. Une forme suivante porte le même archétype que sa première phase :
    // sans l'exclusion de `Monstre::nomsDeFormeSuivante()`, Demonape ou Gruulob
    // Forme Démoniaque auraient été des boss du thème comme les autres.
    $this->seed([\Database\Seeders\GabaritQueteSeeder::class]);

    $gabarit = \App\Models\GabaritQuete::where('nom', 'Confrontation finale')->firstOrFail();
    $pool = (array) data_get($gabarit->structure, 'rencontre_finale.archetypes', []);

    expect($pool)->toContain('gretzl_porte_fleau')
        ->and($pool)->toContain('gruulob_sorcier_gobelin');

    foreach (['Gretzl la Porte-Fléau', 'Gruulob, Sorcier Gobelin Corrompu'] as $nom) {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();
        expect($m->boite)->toBe('jungles_delthrak', "{$nom} : thème")
            ->and($m->tier)->toBe('boss', "{$nom} : palier");
    }

    // Pest lit chaque argument de `toContain` comme une valeur cherchée : pas de
    // message en second argument.
    expect(Monstre::nomsDeFormeSuivante())
        ->toContain('Demonspider', 'Demonape', 'Gruulob, Forme Démoniaque');

    // Les SEULS boss du thème sont les deux premières phases (§1.3 du plan
    // Delthrak : « actif, mais SANS boss » avant ce chantier).
    $bossDuTheme = Monstre::where('tier', 'boss')
        ->where('boite', 'jungles_delthrak')
        ->whereNotIn('nom_base', Monstre::nomsDeFormeSuivante())
        ->orderBy('nom_base')
        ->pluck('nom_base')
        ->all();

    expect($bossDuTheme)->toBe(['Gretzl la Porte-Fléau', 'Gruulob, Sorcier Gobelin Corrompu']);
    expect(\App\Partie\DemarreurQuete::BOITES_THEMATIQUES)->toContain('jungles_delthrak');
});

it('donne à chaque créature de Jungles le trait que son livret lui prête', function () {
    // Les 4 mots-clés portés (p. 48-49). *Spawn* et la double-action du
    // tacticien restent dehors, faute de mécanique — reference/16 §4.6.
    $traits = [
        // Attaque 0 : il ne frappe pas, il s'accroche (jeton sur la fiche).
        // `venimeux` ajouté le 2026-10-05 (carte « Spawnling », « Venomous.
        // Agile. ») — inerte en pratique (Attaque 0), cité quand même.
        'Rejeton putride' => ['agile', 's_accroche', 'venimeux'],
        'Crâne putride' => ['racines_entravantes'],
        'Raptor' => ['tacticien'],
        'Rampant putride' => ['agile', 'venimeux'],
        'Serpent géant' => ['venimeux'],
        'Singe géant' => ['agile'],
    ];

    foreach ($traits as $nom => $attendus) {
        $capacites = (array) Monstre::where('nom_base', $nom)->firstOrFail()->capacites;

        foreach ($attendus as $trait) {
            expect(in_array($trait, $capacites, true))->toBeTrue("{$nom} devrait porter « {$trait} »");
        }
    }
});

it('route au budget de rencontre, plutôt que d\'interdire le palier, la créature `base` ENDURANTE', function () {
    // Ce qui a fait un anéantissement le 2026-08-10 n'est pas la puissance de
    // frappe : c'est l'ENDURANCE. Le Troll cumulait 3 PV et 4 dés de défense
    // dans un palier où tout le monde avait 1 PV — un barbare lui arrachait
    // 0,98 PV par attaque là où il tuait n'importe quel autre monstre de base
    // d'un coup, pendant que le troll rendait 1,40 PV par coup. Le garde-fou
    // posé ce jour-là était un INTERDIT : aucun monstre `base` à la fois
    // Body ≥ 3 ET Défense ≥ 4.
    //
    // ⚠ PRINCIPE ABANDONNÉ le 2026-10-05 : les cartes officielles donnent
    // exactement ce profil à deux monstres de base SOURCÉS (Guerrier du Chaos
    // 3/4, Gargouille 3/5) — l'interdit contredirait la carte elle-même. La
    // vraie protection n'était jamais le plafond de Body : c'est le `cout`,
    // mesuré par attaques-à-3-dés pour abattre
    // (`docs/regles/bestiaire-et-rencontres.md`), qui route un monstre
    // devenu coûteux vers les « forts » du budget de rencontre
    // (`DemarreurQuete::acheterMonstres()`, seuil `seuil_cout_fort`) —
    // achetés UN SEUL à la fois, jamais mass-achetés dans le tas des
    // « faibles » comme le Troll l'avait été. C'est EXACTEMENT ainsi que
    // l'Assassin (cout 6) est déjà protégé depuis Rise of the Dread Moon.
    $seuil = (int) config('jeu.rencontres.seuil_cout_fort', 3);

    $endurants = Monstre::where('tier', 'base')
        ->where('pv_body', '>=', 3)
        ->where('defense', '>=', 4)
        ->get();

    expect($endurants)->not->toBeEmpty('scénario de test périmé : plus aucun monstre `base` endurant à router');

    foreach ($endurants as $m) {
        expect((int) $m->cout)->toBeGreaterThan($seuil, "{$m->nom_base} : endurant mais pas routé vers les « forts » (cout {$m->cout} <= seuil {$seuil})");
    }

    // …et le coût le plus cher du palier reste sous celui du palier au-dessus
    // (tient encore avec les cartes officielles — Gargouille à 7, Garde-mage/
    // Sorcier du Dread à 8 : à surveiller, pas à figer en dur si une future
    // carte le fait basculer).
    $maxBase = (int) Monstre::where('tier', 'base')->max('cout');
    $minSousBoss = (int) Monstre::where('tier', 'sous_boss')->min('cout');

    expect($maxBase)->toBeLessThan($minSousBoss, 'les paliers se chevauchent en coût');
});

it("donne leurs 2 cases à l'Ogre ET au Loup géant de The Mage of the Mirror", function () {
    // Le livret nomme le Loup géant grande figurine ; l'Ogre garde aussi ses
    // 2 cases (René, 2026-10-04 — `docs/plan-correctifs-2026-10-04.md` C3).
    foreach (['Ogre', 'Loup géant'] as $nom) {
        expect(Monstre::where('nom_base', $nom)->firstOrFail()->grandeTaille())->toBeTrue($nom);
    }
});

it('donne à l\'Assassin `attaque_diagonale`, le seul monstre à porter ce mot-clé', function () {
    // Carte « Assassin » (Rise of the Dread Moon, p15, © 2023, scan de René
    // 2026-10-05) : « Each Assassin may attack diagonally. » Le comportement
    // EN JEU (un Assassin en diagonale attaque, un Gobelin en diagonale non)
    // est testé par `AttaqueDiagonaleMonstreTest` — ce test-ci verrouille
    // seulement le CATALOGUE : la capacité est déclarée au bon endroit et
    // nulle part ailleurs par accident.
    $assassin = Monstre::where('nom_base', 'Assassin')->firstOrFail();
    expect(in_array('attaque_diagonale', (array) $assassin->capacites, true))->toBeTrue();

    $autres = Monstre::where('nom_base', '!=', 'Assassin')
        ->get()
        ->filter(fn (Monstre $m) => in_array('attaque_diagonale', (array) $m->capacites, true))
        ->pluck('nom_base')
        ->all();

    expect($autres)->toBe([], 'monstre(s) inattendu(s) avec `attaque_diagonale` : '.implode(', ', $autres));
});
