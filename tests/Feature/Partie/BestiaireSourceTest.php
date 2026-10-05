<?php

declare(strict_types=1);

use App\Models\Monstre;
use Database\Seeders\MonstreSeeder;

/**
 * Provenance du BESTIAIRE (reference/16_armurerie.md §4.4-4.6).
 *
 * Trois origines, et elles ne doivent pas se mélanger :
 *
 *  1. les **8 monstres de base**, dont les stats viennent des cartes monstre
 *     (`sjeng-monsters.pdf`) et sont recoupées par deux passages des livrets ;
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

it('porte les 8 monstres de base exactement comme leurs cartes', function () {
    $cartes = [
        'Gobelin' => [10, 2, 1, 1, 1],
        'Squelette' => [6, 2, 2, 1, 0],
        'Zombie' => [4, 2, 3, 1, 0],
        'Orque' => [8, 3, 2, 1, 2],
        'Fimir' => [6, 3, 3, 1, 3],
        'Momie' => [4, 3, 4, 1, 0],
        'Guerrier du Chaos' => [6, 3, 4, 1, 3],
        'Gargouille' => [6, 4, 4, 1, 4],
    ];

    foreach ($cartes as $nom => $attendu) {
        expect(statsDe($nom))->toBe($attendu, "{$nom} : bloc de stats");
    }
});

it('donne 1 SEUL point de Body à tout monstre de base, comme au plateau', function () {
    // C'est le cœur du design : les héros encaissent (4 à 8 Body), la piétaille
    // tombe d'un coup réussi. On donnait 2 ou 3 aux plus costauds, ce qui
    // écrasait la lisibilité des paliers sous_boss/boss.
    //
    // Plus d'exception : le Troll y a séjourné un jour, et un test de jeu a
    // montré qu'un monstre à 3 PV dans un palier où tout le monde en a 1
    // transforme la première rencontre en anéantissement (2026-08-10).
    $trop = Monstre::where('tier', 'base')
        ->where('pv_body', '>', 1)
        ->pluck('nom_base')
        ->all();

    // Les créatures d'extension du palier `base` sont, elles, plus robustes :
    // leurs fiches officielles le disent (Archer elfe 3 Body, Raptor 2…).
    $extensions = ['Gremlin des glaces', 'Archer elfe', 'Guerrier elfe', 'Assassin',
        'Raptor', 'Crâne putride'];

    expect(array_values(array_diff($trop, $extensions)))->toBe([]);
});

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
        'increvable_une_fois', 'reactions_defense', 'recompense_reddition'];

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
    foreach (['Gruzbella Hammerhand', 'Spawn of the Pit', 'Gretzl la Porte-Fléau'] as $debut) {
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
            ->toBeTrue("{$nom} : Demon Wings");
    }
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

it('tire Gretzl comme boss du thème Jungles of Delthrak', function () {
    // Vérifie l'intégration au générateur (chantier 2026-10-04) : le pool de
    // rencontre finale du gabarit « Confrontation finale » nomme son
    // archétype, son `boite` la range bien dans le thème, et son archétype
    // est bien le SEUL candidat de ce thème — la rotation déterministe de
    // `DemarreurQuete::acheterMonstres()` n'a donc aucun autre tirage
    // possible dès que le thème d'une campagne est `jungles_delthrak`.
    $this->seed([\Database\Seeders\GabaritQueteSeeder::class]);

    $gabarit = \App\Models\GabaritQuete::where('nom', 'Confrontation finale')->firstOrFail();
    $pool = (array) data_get($gabarit->structure, 'rencontre_finale.archetypes', []);

    expect($pool)->toContain('gretzl_porte_fleau');

    $gretzl = Monstre::where('nom_base', 'Gretzl la Porte-Fléau')->firstOrFail();
    expect($gretzl->boite)->toBe('jungles_delthrak')
        ->and($gretzl->tier)->toBe('boss');

    // Aucun AUTRE candidat `boss` de ce thème n'existe encore (§1.3 du plan
    // Delthrak : « actif, mais SANS boss ») — Gretzl (ses trois PHASES,
    // toutes de tier `boss`/`boite jungles_delthrak`) est donc le seul nom
    // que la rotation d'`acheterMonstres()` peut jamais tirer pour ce thème.
    $sesPhases = ['Gretzl la Porte-Fléau', 'Demonspider', 'Demonape'];
    $autresBossDuTheme = Monstre::where('tier', 'boss')
        ->where('boite', 'jungles_delthrak')
        ->whereNotIn('nom_base', $sesPhases)
        ->count();

    expect($autresBossDuTheme)->toBe(0);
    expect(\App\Partie\DemarreurQuete::BOITES_THEMATIQUES)->toContain('jungles_delthrak');
});

it('donne à chaque créature de Jungles le trait que son livret lui prête', function () {
    // Les 4 mots-clés portés (p. 48-49). *Spawn* et la double-action du
    // tacticien restent dehors, faute de mécanique — reference/16 §4.6.
    $traits = [
        // Attaque 0 : il ne frappe pas, il s'accroche (jeton sur la fiche).
        'Rejeton putride' => ['agile', 's_accroche'],
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

it('interdit au palier `base` la créature ENDURANTE — celle qu\'on ne peut pas tuer', function () {
    // Ce qui a fait un anéantissement le 2026-08-10 n'est pas la puissance de
    // frappe : c'est l'ENDURANCE. Le Troll cumulait 3 PV et 4 dés de défense
    // dans un palier où tout le monde a 1 PV — un barbare lui arrachait 0,98 PV
    // par attaque là où il tue n'importe quel autre monstre de base d'un coup,
    // pendant que le troll rendait 1,40 PV par coup.
    //
    // À l'inverse, l'Assassin frappe à 5 dés et c'est très bien : avec 2 PV et
    // 3 dés de défense, il meurt en deux coups. Une brute de verre est un
    // danger jouable ; une brute qui encaisse est un sous-boss déguisé, et le
    // budget de rencontre la lâche sur des héros de niveau 1 sans talent.
    $endurants = Monstre::where('tier', 'base')
        ->where('pv_body', '>=', 3)
        ->where('defense', '>=', 4)
        ->pluck('nom_base')
        ->all();

    expect($endurants)->toBe([], 'créature(s) trop endurantes pour le palier base : '.implode(', ', $endurants));

    // …et le coût le plus cher du palier reste sous celui du palier au-dessus.
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
