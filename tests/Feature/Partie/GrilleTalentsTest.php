<?php

declare(strict_types=1);

use App\Engine\MotsClesTalent;
use App\Models\Competence;
use Database\Seeders\CompetenceSeeder;

/*
 * LA GRILLE DE TALENTS — 3 colonnes × 3 lignes par classe (René, 2026-08-23).
 *
 * Ce fichier tient les trois verrous « aucun talent décoratif » :
 *
 *  1. LE TEXTE — chaque nœud dit au joueur ce qu'il achète : une `description`
 *     écrite à la main (le gain, sa condition, sa cadence) ET un `avantage`
 *     chiffré DÉRIVÉ de `effet`, donc jamais en désaccord avec la mécanique.
 *  2. LE REGISTRE — `MotsClesTalent::MECANIQUES` est vérifié dans les DEUX
 *     sens, et son `lecteur` déclaré est confronté à la réalité : la classe et
 *     la méthode existent, et le fichier lit bien la clé. C'est cette dernière
 *     assertion qui manquait à `CapacitesInnees`, qui déclarait ses lecteurs
 *     sans que rien ne vérifie que le lien tenait.
 *  3. LA PREUVE EN JEU — `TalentsEnJeuTest`, un cas par mécanique, joué à
 *     travers le vrai moteur.
 *
 * Ce que la grille remplace : une liste plate où une quinzaine de talents des
 * classes d'extension portaient la bonne mécanique et ne faisaient RIEN, parce
 * que les lecteurs du moteur étaient câblés sur le NOM du nœud.
 */

beforeEach(function () {
    $this->seed(CompetenceSeeder::class);
});

it('donne à chaque classe une grille COMPLÈTE de 3 colonnes × 3 lignes', function () {
    $parClasse = Competence::where('innee', false)->get()->groupBy('classe');

    expect($parClasse->keys()->sort()->values()->all())->toBe([
        'barbare', 'barde', 'berserker', 'chevalier', 'druide', 'elfe',
        'explorateur', 'magicien', 'moine', 'nain', 'rogue', 'warlock',
    ]);

    foreach ($parClasse as $classe => $noeuds) {
        // ⚠ EXACTEMENT neuf, et plus un plancher : la dette « le barbare seul à
        // 4 nœuds » (2026-08-22) est soldée par la refonte, et un plancher
        // laisserait revenir une classe plus pauvre que les autres sans que rien
        // ne le signale.
        expect($noeuds->count())->toBe(9, "La classe {$classe} a {$noeuds->count()} nœuds au lieu de 9.");

        foreach ([1, 2, 3] as $colonne) {
            $rangs = $noeuds->where('colonne', $colonne)->sortBy('rang')->values();

            expect($rangs->pluck('rang')->all())
                ->toBe([1, 2, 3], "La colonne {$colonne} de {$classe} n'a pas ses trois lignes.");

            // Une seule catégorie par colonne, et elle est nommée.
            expect($rangs->pluck('categorie')->unique()->all())->toHaveCount(1);
            expect((string) $rangs->first()->categorie)->not->toBe('');
            expect((string) $rangs->first()->categorie_icone)->not->toBe('');
        }
    }
});

it('chaîne chaque ligne à celle du dessus, DANS SA COLONNE, et jamais ailleurs', function () {
    foreach (Competence::where('innee', false)->get() as $noeud) {
        if ((int) $noeud->rang === 1) {
            expect($noeud->prerequis_id)->toBeNull(
                "{$noeud->classe} / {$noeud->nom} : une première ligne ne peut pas avoir de prérequis.",
            );

            continue;
        }

        $parent = Competence::find($noeud->prerequis_id);

        expect($parent)->not->toBeNull("{$noeud->classe} / {$noeud->nom} : prérequis manquant.");

        // ⚠ Le prérequis est le nœud JUSTE AU-DESSUS, dans la MÊME colonne et
        // la MÊME classe. Nommé à la main, rien n'empêchait une chaîne de
        // traverser deux colonnes — ce qui aurait rendu une colonne
        // inaccessible sans en acheter une autre.
        expect([$parent->classe, (int) $parent->colonne, (int) $parent->rang])
            ->toBe([$noeud->classe, (int) $noeud->colonne, (int) $noeud->rang - 1]);
    }
});

it('laisse les capacités de CARTE hors de la grille — elles ne s\'achètent pas', function () {
    $innees = Competence::where('innee', true)->get();

    // 1 Barde, 3 Rogue, 4 Moine (les 4 Styles), 3 Chevalier, 3 Berserker,
    // 3 Explorateur. Les 4 classes historiques n'en ont aucune : aucune carte
    // officielle ne leur en donne.
    expect($innees)->toHaveCount(17);

    foreach ($innees as $carte) {
        expect($carte->colonne)->toBeNull("{$carte->nom} : une capacité de carte n'a pas de colonne.");
        expect($carte->rang)->toBeNull("{$carte->nom} : une capacité de carte n'a pas de rang.");
        expect($carte->prerequis_id)->toBeNull("{$carte->nom} : une capacité de carte ne s'achète pas.");
    }

    foreach (['barbare', 'nain', 'elfe', 'magicien'] as $historique) {
        expect(Competence::where('classe', $historique)->where('innee', true)->count())->toBe(0);
    }
});

it('n\'emploie AUCUNE mécanique sans lecteur déclaré — et n\'en déclare aucune que personne ne porte', function () {
    $portees = [];

    foreach (Competence::all() as $noeud) {
        $mecanique = $noeud->effet['mecanique'] ?? null;

        expect(MotsClesTalent::connue($mecanique))
            ->toBeTrue("{$noeud->classe} / {$noeud->nom} : mécanique « {$mecanique} » sans lecteur déclaré.");

        $portees[$mecanique] = true;

        // Les techniques du Moine vivent DANS la carte de style : elles passent
        // le même contrôle, sinon quatre cartes en cacheraient huit.
        foreach ((array) ($noeud->effet['techniques'] ?? []) as $technique) {
            $interne = $technique['effet']['mecanique'] ?? null;

            expect(MotsClesTalent::connue($interne))
                ->toBeTrue("{$technique['nom']} : technique sans lecteur déclaré.");

            $portees[$interne] = true;
        }
    }

    foreach (array_keys(MotsClesTalent::MECANIQUES) as $declaree) {
        expect(array_key_exists($declaree, $portees))
            ->toBeTrue("« {$declaree} » est déclarée lue, mais aucun nœud ne la porte.");
    }
});

it('confronte chaque lecteur DÉCLARÉ à la réalité : la méthode existe, et son fichier lit la clé', function () {
    foreach (MotsClesTalent::MECANIQUES as $mecanique => $entree) {
        expect($entree['libelle'] ?? '')->not->toBe('', "« {$mecanique} » : libellé joueur manquant.");
        expect($entree['icone'] ?? '')->not->toBe('', "« {$mecanique} » : icône manquante.");

        $nomme = false;

        foreach ((array) $entree['lecteur'] as $lecteur) {
            [$classe, $methode] = explode('::', str_replace('()', '', $lecteur));

            expect(class_exists($classe))->toBeTrue("« {$mecanique} » : lecteur {$classe} introuvable.");

            $reflexion = new ReflectionClass($classe);

            expect($reflexion->hasMethod($methode))
                ->toBeTrue("« {$mecanique} » : {$classe}::{$methode}() n'existe pas.");

            $nomme = $nomme
                || str_contains((string) file_get_contents((string) $reflexion->getFileName()), $mecanique);
        }

        // ⚠ L'assertion qui compte, et la seule que l'ancien registre ne
        // passait pas : au moins un des lecteurs déclarés NOMME la mécanique.
        // Un registre dont aucun lecteur ne connaît la chaîne est exactement la
        // décoration que la refonte retire.
        //
        // « Au moins un » et non « tous » : certains sites APPLIQUENT la règle
        // sans la nommer — `Grille::autoriserFranchissement()` ne reçoit qu'un
        // booléen. Exiger la chaîne partout aurait poussé à inliner des clés là
        // où elles n'ont rien à faire.
        //
        // ⚠ `toContain()` prend des AIGUILLES, jamais un message : passer
        // l'explication en second argument en fait une seconde aiguille, et
        // l'assertion échoue toujours. Même piège que `toHaveKey`.
        expect($nomme)->toBeTrue("« {$mecanique} » : aucun lecteur déclaré ne nomme cette clé.");
    }
});

it('dit à chaque joueur ce que son talent lui apporte : une phrase de jeu ET un chiffre', function () {
    foreach (Competence::all() as $noeud) {
        $etiquette = "{$noeud->classe} / {$noeud->nom}";
        $description = (string) $noeud->description;

        // Une phrase de jeu, pas un mot-clé : elle doit tenir debout à la
        // lecture, sur la feuille de talent comme dans le guide.
        expect(mb_strlen($description))->toBeGreaterThanOrEqual(
            25, "{$etiquette} : description trop courte pour dire quoi que ce soit.",
        );

        // ⚠ Le CHIFFRE de l'effet doit figurer dans la phrase. C'est ce qui
        // empêche une description de vieillir en silence quand la valeur change
        // — le joueur lirait « +1 dé » là où le moteur en donne deux.
        if (isset($noeud->effet['valeur'])) {
            expect(str_contains($description, (string) $noeud->effet['valeur']))
                ->toBeTrue("{$etiquette} : la description ne cite pas sa valeur ({$noeud->effet['valeur']}).");
        }

        // Idem pour la CADENCE : « une fois par quête » est la moitié de ce que
        // vaut une capacité, et l'omettre en fait une promesse illimitée.
        $cadences = [
            'une_fois_par_quete' => 'par quête',
            'une_fois_par_tour' => 'par tour',
            'une_fois_par_usage' => 'par attaque',
        ];

        $cadence = $cadences[$noeud->effet['frequence'] ?? ''] ?? null;

        if ($cadence !== null) {
            expect(str_contains(mb_strtolower($description), $cadence))
                ->toBeTrue("{$etiquette} : la description ne dit pas sa cadence ({$cadence}).");
        }

        expect($noeud->avantage())->not->toBe('', "{$etiquette} : aucun avantage chiffré affichable.");
    }
});

it('publie la grille sur /api/competences, catégories et avantages compris', function () {
    connecterJoueur('alice');

    $catalogue = $this->getJson('/api/competences')->assertOk()->json('competences');

    $carrure = collect($catalogue)->firstWhere(fn ($c) => $c['classe'] === 'barbare' && $c['nom'] === 'Carrure');

    expect($carrure['categorie'])->toBe('Carrure')
        ->and($carrure['colonne'])->toBe(2)
        ->and($carrure['rang'])->toBe(1)
        ->and($carrure['prerequis_id'])->toBeNull()
        ->and($carrure['avantage'])->toBe('+1 PV de Body maximum')
        ->and($carrure['innee'])->toBeFalse();

    // Un nœud conditionnel dit sa condition, un nœud cadencé dit sa cadence.
    $garde = collect($catalogue)->firstWhere(fn ($c) => $c['classe'] === 'nain' && $c['nom'] === 'Garde tenace');
    expect($garde['avantage'])->toBe('+1 dé de défense, contre la première attaque du combat');

    $coup = collect($catalogue)->firstWhere(fn ($c) => $c['classe'] === 'barbare' && $c['nom'] === 'Coup puissant');
    expect($coup['avantage'])->toBe("Relance les dés d'attaque ratés, une fois par attaque");

    // Toute entrée publiée porte de quoi être rendue à l'écran.
    foreach ($catalogue as $ligne) {
        expect($ligne['avantage'])->not->toBe('')
            ->and($ligne['avantage_icone'])->not->toBe('');
    }
});
