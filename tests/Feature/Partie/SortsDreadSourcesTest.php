<?php

declare(strict_types=1);

use App\Engine\MotsClesSortDread as Mot;
use App\Models\Monstre;
use App\Models\SortDread;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\SortDreadSeeder;

/**
 * « Les cartes sont la source » est une PROPRIÉTÉ, pas une phrase de
 * documentation — et c'est ce fichier qui la rend vérifiable pour la magie du
 * MJ (doc 09 §4bis, `dread_spells.pdf`, 29 cartes).
 *
 * Trois verrous, calqués sur ceux que l'armurerie et l'arbre de talents ont
 * déjà :
 *
 *  1. Le REGISTRE (`config/cartes.php`, section `dread`) est confronté au
 *     catalogue DANS LES DEUX SENS. Un sort en base sans carte serait une
 *     invention — le *Trait de Chaos* en était une, et c'est ce contrôle qui
 *     l'aurait dit dès le premier jour.
 *  2. Le VOCABULAIRE (`MotsClesSortDread`) est confronté au seeder dans les
 *     deux sens, et chaque lecteur déclaré doit exister ET nommer sa clé dans
 *     son propre fichier. C'est l'assertion qui manquait à `CapacitesInnees`,
 *     où quatre lecteurs pointaient un fichier où le mot n'apparaissait pas.
 *  3. Les RÉPERTOIRES : tout archétype a un porteur, tout sort nommé existe.
 */
beforeEach(function () {
    $this->seed([MonstreSeeder::class, SortDreadSeeder::class]);
});

// ------------------------------------------------------------------
// 1. Le registre des cartes, dans les deux sens
// ------------------------------------------------------------------

it('recense les 29 cartes de dread_spells.pdf, portées et non portées', function () {
    $cartes = collect(config('cartes.dread.cartes'));

    expect($cartes)->toHaveCount(29);

    // 23 depuis le 2026-09-04 : la *Rouille* a été portée sur arbitrage de
    // René — « c'est correct qu'un joueur puisse perdre un objet ».
    $portees = $cartes->whereNotNull('sort_dread');
    expect($portees)->toHaveCount(23);

    // Chaque carte écartée dit son texte de plateau ET la mécanique qui lui
    // manque : une carte non portée est une dette NOMMÉE, pas un oubli.
    foreach ($cartes->whereNull('sort_dread') as $carte) {
        expect($carte['texte'] ?? '')->not->toBeEmpty("{$carte['carte']} : texte de carte manquant")
            ->and($carte['manque'] ?? '')->not->toBeEmpty("{$carte['carte']} : mécanique manquante non dite")
            ->and($carte['nom'] ?? '')->not->toBeEmpty("{$carte['carte']} : nom français manquant");
    }
});

it('toute carte marquée PORTÉE existe au catalogue', function () {
    $catalogue = SortDread::pluck('nom')->all();

    foreach (collect(config('cartes.dread.cartes'))->whereNotNull('sort_dread') as $carte) {
        expect(in_array($carte['sort_dread'], $catalogue, true))
            ->toBeTrue("{$carte['carte']} → « {$carte['sort_dread']} » absent du catalogue.");
    }
});

it('aucun sort de Dread n\'existe SANS carte', function () {
    // ⚠ Le sens qui compte. Le *Trait de Chaos* vivait ici depuis le premier
    // jour sans qu'aucune carte ne le décrive : il montait la courbe de
    // puissance d'un répertoire, et c'est tout ce qu'on savait de lui.
    $cartes = collect(config('cartes.dread.cartes'))->pluck('sort_dread')->filter()->all();
    $orphelins = SortDread::pluck('nom')->reject(fn ($n) => in_array($n, $cartes, true))->all();

    expect($orphelins)->toBe([], 'Sorts de Dread sans carte source : '.implode(', ', $orphelins));
});

// ------------------------------------------------------------------
// 2. Le vocabulaire fermé, et ses lecteurs
// ------------------------------------------------------------------

it('n\'emploie que des types, paliers, résistances et zones DÉCLARÉS', function () {
    foreach (SortDread::all() as $sort) {
        expect(in_array($sort->type, Mot::TYPES, true))
            ->toBeTrue("{$sort->nom} : type « {$sort->type} » hors vocabulaire.");

        expect(in_array($sort->palier, ['base', 'sous_boss', 'boss'], true))
            ->toBeTrue("{$sort->nom} : palier « {$sort->palier} » inconnu.");

        $resistance = data_get($sort->effet, 'resistance');
        if ($resistance !== null) {
            expect(in_array($resistance, Mot::RESISTANCES, true))
                ->toBeTrue("{$sort->nom} : résistance « {$resistance} » sans lecteur.");
        }

        $zone = data_get($sort->effet, 'zone');
        if ($zone !== null) {
            expect(in_array($zone, Mot::ZONES, true))
                ->toBeTrue("{$sort->nom} : zone « {$zone} » sans lecteur.");
        }
    }
});

it('confronte le vocabulaire d\'effet au seeder DANS LES DEUX SENS', function () {
    $employes = [];

    foreach (SortDread::all() as $sort) {
        foreach (array_keys((array) $sort->effet) as $cle) {
            $employes[$cle] = true;

            expect(array_key_exists($cle, Mot::MECANIQUES))
                ->toBeTrue("{$sort->nom} : clé d'effet « {$cle} » non déclarée dans MotsClesSortDread.");
        }
    }

    // …et l'autre sens : un mot déclaré que personne n'emploie est une clé
    // décorative, exactement ce que cette classe existe pour empêcher.
    $inutilises = array_diff(array_keys(Mot::MECANIQUES), array_keys($employes));

    expect($inutilises)->toBe([], 'Mots déclarés sans aucun sort qui les porte : '.implode(', ', $inutilises));
});

it('chaque lecteur déclaré existe ET nomme sa clé dans son propre fichier', function () {
    foreach (Mot::MECANIQUES as $cle => $meta) {
        [$classe, $methode] = explode('::', $meta['lecteur']);

        expect(class_exists($classe))->toBeTrue("{$cle} : classe lectrice {$classe} introuvable.");
        expect(method_exists($classe, $methode))
            ->toBeTrue("{$cle} : méthode {$meta['lecteur']} introuvable.");

        // ⚠ L'assertion qui manquait à `CapacitesInnees` : quatre de ses
        // lecteurs pointaient un fichier où le mot n'apparaissait nulle part.
        $fichier = (new ReflectionClass($classe))->getFileName();
        expect(str_contains((string) file_get_contents((string) $fichier), $cle))
            ->toBeTrue("{$cle} : {$classe} est déclaré lecteur mais ne nomme jamais la clé.");

        expect($meta['libelle'] ?? '')->not->toBeEmpty("{$cle} : libellé manquant.");
    }
});

it('ne déclare AUCUN mot non implémenté (les cartes écartées le sont en entier)', function () {
    // Une carte à moitié portée serait une règle promise au joueur et jamais
    // tenue : les sept écartées le sont ENTIÈREMENT, aucune ne laisse un mot
    // orphelin dans le catalogue.
    expect(Mot::NON_IMPLEMENTES)->toBe([]);
});

// ------------------------------------------------------------------
// 3. Les répertoires
// ------------------------------------------------------------------

it('n\'invoque que des créatures qui EXISTENT au bestiaire', function () {
    // ⚠ Un nom mal orthographié dans une `table_d6` ne lève aucune erreur :
    // `invoquerSbires()` retombe sur « n'importe quel monstre de base », et un
    // Seigneur appellerait des gobelins en croyant lever des momies. Le repli
    // existe pour qu'une donnée manquante n'annule pas le sort — pas pour
    // couvrir une faute de frappe.
    $bestiaire = Monstre::pluck('nom_base')->all();

    foreach (SortDread::whereNotNull('effet')->get() as $sort) {
        foreach ((array) data_get($sort->effet, 'table_d6', []) as $ligne) {
            foreach (array_keys((array) data_get($ligne, 'invoque', [])) as $creature) {
                expect(in_array($creature, $bestiaire, true))
                    ->toBeTrue("{$sort->nom} invoque « {$creature} », absent du bestiaire.");
            }
        }

        foreach ((array) data_get($sort->effet, 'reanime', []) as $creature) {
            expect(in_array($creature, $bestiaire, true))
                ->toBeTrue("{$sort->nom} relève « {$creature} », absent du bestiaire.");
        }
    }

    // …et la carte *Summon Wolves* dit « GIANT wolves », pas « wolves » : la
    // nuance est dans son texte, elle doit être dans la donnée (René, 2026-09-04).
    $loups = SortDread::where('nom', 'Invocation de loups')->firstOrFail();
    $creatures = collect((array) data_get($loups->effet, 'table_d6', []))
        ->flatMap(fn ($l) => array_keys((array) data_get($l, 'invoque', [])))
        ->unique()->values()->all();

    expect($creatures)->toBe(['Loup géant']);
});

it('donne un PORTEUR à chaque archétype de lanceur', function () {
    foreach (array_keys((array) config('archetypes_lanceurs')) as $cle) {
        expect(Monstre::where('archetype_lanceur', $cle)->exists())
            ->toBeTrue("L'archétype « {$cle} » n'est porté par aucun monstre du catalogue.");
    }
});

it('ne nomme dans un répertoire que des sorts qui EXISTENT', function () {
    $catalogue = SortDread::pluck('nom')->all();

    foreach ((array) config('archetypes_lanceurs') as $cle => $archetype) {
        foreach ((array) ($archetype['sorts'] ?? []) as $nom) {
            expect(in_array($nom, $catalogue, true))
                ->toBeTrue("Archétype « {$cle} » : le sort « {$nom} » n'existe pas au catalogue.");
        }

        expect($archetype['porteur'] ?? '')->not->toBeEmpty("Archétype « {$cle} » : porteur non documenté.");
    }

    // …et les listes brutes des gabarits élites, qui ne passent pas par un
    // archétype : elles se sont déjà retrouvées à citer un sort supprimé.
    foreach (Monstre::whereNotNull('sorts_dread')->get() as $monstre) {
        foreach ((array) $monstre->sorts_dread as $nom) {
            expect(in_array($nom, $catalogue, true))
                ->toBeTrue("{$monstre->nom_base} : le sort « {$nom} » n'existe pas au catalogue.");
        }
    }
});

it('donne des usages de Dread à TOUT monstre qui a un répertoire', function () {
    // ⚠ Le piège que le palier `base` a ouvert : `reinitialiserUsagesInstance()`
    // ne réarmait que les sous-boss et les boss. Le Cultiste du Dread, le
    // Spectre et le Tisseur putride sont de tier `base` — sans usage, leur
    // répertoire n'aurait jamais rien produit, et rien ne l'aurait signalé.
    $lanceursDeBase = Monstre::where('tier', 'base')
        ->whereNotNull('archetype_lanceur')
        ->pluck('nom_base');

    expect($lanceursDeBase)->not->toBeEmpty();

    foreach ($lanceursDeBase as $nom) {
        expect(App\Partie\MoteurDread::USAGES_BASE)->toBeGreaterThan(0, "{$nom} : aucun usage de Dread.");
    }
});
