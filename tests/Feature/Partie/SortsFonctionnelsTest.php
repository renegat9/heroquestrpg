<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\MotsClesSort;
use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Objet;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurSorts;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Les sorts et parchemins sont-ils FONCTIONNELS ?
 *
 * Même garde-fou que `ObjetsFonctionnelsTest` : chaque clé de `sorts.effet` est
 * une promesse faite au joueur. On fige l'inventaire en deux ensembles — celles
 * qu'un moteur lit, et celles qu'on sait INERTES — pour qu'ajouter une clé au
 * seeder force une décision : lui écrire un lecteur, ou la déclarer décorative.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class,
        ConditionSeeder::class]);
});

/** Clés lues par le moteur (audit du 2026-08-06, fichier applicatif en regard). */
const CLES_SORT_ACTIVES = [
    'des_degats',            // ResolveurTour::sortDegats()
    'portee',                // ciblage à distance
    'soin_pv_body',          // sort utilitaire de soin
    'bonus_des_attaque',     // MoteurSorts::bonusDes()
    'bonus_des_defense',     // idem
    'condition_appliquee',   // appliquerConditionCatalogue()
    'duree',                 // DureeEffet / expirerBuffs()
    'deplacement_multiplie', // multiplicateurDeplacement()
    'franchit_mur',          // ResolveurTour, franchissement
    'saute_tour',            // condition posée sur le monstre (Tempête)
    'ouvre_porte',           // second mode (Génie) : MoteurSorts::optionsPorteAuChoix()
    'cible',                 // MoteurSorts::ciblesLegales()
    'defense_applicable',    // ResolveurTour::sortDegats(), pilote le jet de défense
    'resistance',            // ResolveurTour::sortMental(), pilote la résistance
    // NATURE du dégât (App\Engine\TypeDegat) : lue par MoteurSorts::absorbeDegat()
    // — l'Anneau de Feu annule un sort de feu — et par ResolveurTour, qui marque
    // `brule` sur le monstre touché pour lui couper la régénération.
    'type_degat',
    // ---- Répertoires de classe (2026-08-12) ----
    'exclut_soi',              // MoteurSorts::ciblesLegales() — « excluding yourself »
    'zone',                    // ResolveurTour::soinDeZone() — soin à tous les héros vus
    // ---- Sort qui n'existe QU'EN PARCHEMIN (2026-09-04) ----
    'pioche_sans_peril',       // ResolveurTour::piocherSansPeril() — Trésor sans Péril
    'restaure_pv_mind',        // ResolveurTour::sortUtilitaire() — Récupération Psychique
    'rayon',                   // ResolveurTour::rayonDeSort() — Éclair (une DIRECTION, pas une cible)
    'condition_bonus_attaque', // MoteurSorts::bonusDes() — dé conditionnel (au contact)
    'regain',                  // MoteurSorts::regagnerSorts() — App\Engine\RegainEffet
    'reaction',                // MoteurReactions::sortReactifDisponible() — hors tour
    'ignore_pieges_fosse',     // MoteurPieges::declencher() via MoteurSorts::aBuff()
    'condition_monstre',       // ResolveurTour::appliquerEffetMental() — MoteurSorts::CONDITIONS_MONSTRE
    'seuil_mind_max',          // ResolveurTour::sortMental() — s'applique SANS jet sous le seuil
    'image_miroir',            // App\Listeners\ImageMiroir — écouteur de HerosVaSubirDegats
    'tour_supplementaire',     // ResolveurTour::marquerCreneau() — le tour recommence
    'franchit_figures',        // MoteurSorts::franchitFigures() — Voile de Brume, mode de déplacement
    'degats_fixes',            // ResolveurTour::sortDegats() — montant FIXE, sans dés d'attaque (sorts de feu)
    'des_resistance',          // ResolveurTour::reduireParDesRouges() — d6 bruts, chaque 5-6 annule 1 dégât
];

/**
 * Clés SANS lecteur, tolérées en connaissance de cause.
 */
const CLES_SORT_INERTES = [
    'fin', // descriptif (le réveil est câblé dans reveillerHeros)
];

it('n\'introduit aucune clé d\'effet inconnue dans le catalogue de sorts', function () {
    $cles = collect(Sort::all())
        ->flatMap(fn (Sort $s) => array_keys((array) $s->effet))
        ->unique()->sort()->values()->all();

    $connues = collect(CLES_SORT_ACTIVES)->merge(CLES_SORT_INERTES)->all();
    $inconnues = array_values(array_diff($cles, $connues));

    expect($inconnues)->toBe([], implode(', ', $inconnues)
        .' — clé(s) de sort sans lecteur connu. Écris-lui un lecteur, ou ajoute-la '
        .'à CLES_SORT_INERTES en connaissance de cause.');
});

it('donne à chaque sort un effet mécanique que le moteur sait appliquer', function () {
    // Un sort qui ne fait ni dégâts, ni soin, ni condition, ni bonus, ni
    // déplacement est un sort qu'on lance pour rien.
    $agissantes = ['des_degats', 'soin_pv_body', 'condition_appliquee', 'bonus_des_attaque',
        'bonus_des_defense', 'deplacement_multiplie', 'franchit_mur', 'saute_tour', 'ouvre_porte',
        // Un sort de RÉACTION agit hors tour (Ailes sombres) : son effet n'est
        // ni un dégât ni un buff, il annule un coup — mais il agit bel et bien.
        'reaction',
        // Une condition posée sur un MONSTRE agit (Terreur, Ralentissement) :
        // elle plafonne ou réduit ses dés, lus par InstanceMonstre.
        'condition_monstre',
        // Un tour de plus (Arrêt du temps) et une annulation automatique
        // (Image double) agissent, sans être ni dégât ni buff de dés.
        'tour_supplementaire', 'image_miroir',
        // Les sorts de feu n'ont plus de `des_degats` depuis le 2026-09-02 :
        // leur montant est FIXE et c'est la cible qui lance (doc 16 §3bis).
        'degats_fixes',
        // Piocher dans le deck de fouille agit : le *Trésor sans Péril* ne
        // touche aucune statistique, il remplit la bourse.
        'pioche_sans_peril',
        // ⚠ `restaure_pv_mind` agit — il écrit `pv_mind` — même si rien ne
        // réduit cette jauge aujourd'hui. Le lecteur est correct, c'est sa
        // source qui manque ; même statut que la branche Mind de `relever`.
        'restaure_pv_mind',
        // Un rayon agit : il traverse la ligne et frappe tout ce qui s'y tient.
        'rayon'];

    foreach (Sort::all() as $sort) {
        expect(array_intersect($agissantes, array_keys((array) $sort->effet)))
            ->not->toBeEmpty("{$sort->nom} : aucun effet mécanique applicable.");
    }
});

it('ne garde AUCUN sort que le seeder ne déclare pas', function () {
    // ⚠ Trouvé en partie réelle le 2026-08-13, pas par les tests : la base
    // MariaDB portait un « Eau de Guérison elfique » absent du seeder — donc
    // absent d'une installation neuve. Le sélecteur de sorts elfiques
    // l'offrait, et deux groupes n'auraient pas eu le même répertoire selon la
    // date de leur base. Un seeder qui n'efface pas ne suffit pas : il faut
    // aussi que rien ne survive à côté de lui.
    $attendus = collect(Sort::all())->groupBy('element')->map->count();

    expect($attendus[MoteurSorts::REPERTOIRE_ELFIQUE] ?? 0)->toBe(6)
        // ⚠ 30 depuis le 2026-09-04 : *Trésor sans Péril*, *Récupération
        // Psychique* et *Éclair*, les trois sorts à n'exister qu'en parchemin
        // (élément `parchemin`, aucune école).
        ->and(Sort::count())->toBe(30);
});

it('n\'expose de sorts qu\'aux classes lanceuses', function () {
    expect(MoteurSorts::LANCEURS)->toBe(['magicien', 'elfe', 'barde', 'druide', 'warlock'])
        ->and(MoteurSorts::LANCEURS)->not->toContain('barbare')
        ->and(MoteurSorts::LANCEURS)->not->toContain('nain');
});

it('ne déclare lanceuse aucune classe dont les sorts ne seraient pas semés', function () {
    // ⚠ La vraie règle n'est pas la liste, c'est ce qu'elle promet : un lanceur
    // réussit ses parchemins d'office (doc 02 §6) et se voit offrir « Lancer un
    // sort ». Le déclarer sans qu'aucun sort ne lui soit accessible en ferait un
    // lanceur sans magie — un mensonge que `/moi` et le menu relaieraient
    // jusqu'à la manette. On vérifie donc en base, pas sur la constante.
    foreach (MoteurSorts::LANCEURS as $classe) {
        $repertoire = MoteurSorts::REPERTOIRES_CLASSE[$classe] ?? null;

        $sorts = $repertoire !== null
            ? Sort::where('element', $repertoire)->count()
            // Le magicien pioche dans les quatre écoles élémentaires, l'elfe
            // dans une école OU le répertoire elfique : dans les deux cas la
            // preuve est qu'il existe des sorts qu'il peut atteindre.
            : Sort::whereIn('element', array_merge(MoteurSorts::ELEMENTS, [MoteurSorts::REPERTOIRE_ELFIQUE]))->count();

        expect($sorts)->toBeGreaterThan(0, "{$classe} est déclarée lanceuse sans qu'aucun sort ne lui soit semé.");
    }
});

it('n\'emploie que des cibles, coûts et résistances déclarés', function () {
    foreach (Sort::all() as $sort) {
        $effet = (array) $sort->effet;

        // ⚠ toContain() de Pest accepte PLUSIEURS valeurs : y glisser un message
        // le transformerait en second élément à chercher. On passe donc par
        // in_array + message sur toBeTrue().
        foreach ([['cible', MotsClesSort::CIBLES],
            ['resistance', MotsClesSort::RESISTANCES]] as [$cle, $vocabulaire]) {
            if (! isset($effet[$cle])) {
                continue;
            }

            expect(in_array($effet[$cle], $vocabulaire, true))
                ->toBeTrue("{$sort->nom} : {$cle} « {$effet[$cle]} » hors vocabulaire.");
        }
    }
});

it('recense explicitement les mots dont la mécanique n\'existe pas', function () {
    // Une dette déclarée est une dette qu'on peut retrouver. Ce test tombe le
    // jour où l'un de ces mots est implémenté : c'est le rappel de le retirer
    // de NON_IMPLEMENTES et de le documenter comme acquis.
    expect(MotsClesSort::NON_IMPLEMENTES)->toHaveKeys(['monstres_zone', 'invocation_ephemere'])
        ->and(MotsClesSort::estNonImplemente('monstres_zone'))->toBeTrue()
        ->and(MotsClesSort::estNonImplemente('cout'))->toBeFalse();

    // …mais AUCUN sort ne doit plus s'appuyer dessus. Tempête portait
    // `monstres_zone` alors que le texte officiel dit « un monstre choisi »
    // (Kellar's Keep p. 15) : la dette était en réalité une erreur de donnée.
    foreach (Sort::all() as $sort) {
        foreach ((array) $sort->effet as $cle => $valeur) {
            expect(MotsClesSort::estNonImplemente($cle))
                ->toBeFalse("{$sort->nom} : s'appuie sur « {$cle} », non implémenté.");

            if ($cle === 'cible') {
                expect(MotsClesSort::estNonImplemente((string) $valeur))
                    ->toBeFalse("{$sort->nom} : cible « {$valeur} », non implémentée.");
            }
        }
    }
});

it('fait traverser la roche tout le tour, et fait tomber qui y finit son mouvement', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'elfe']);
    app(MoteurSorts::class)->attacherElement($hero, 'terre');

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $hero->id)->firstOrFail();

    // Sans le sort, la roche barre le passage…
    $grille = FabriqueGrille::pour($quete);
    $roche = null;
    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if ($c !== 's') {
                $roche = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }
    expect($roche)->not->toBeNull()
        ->and($grille->estTraversable($roche['x'], $roche['y']))->toBeFalse()
        ->and($grille->estRoche($roche['x'], $roche['y']))->toBeTrue();

    // …avec le buff, elle ne barre plus rien.
    app(MoteurSorts::class)->appliquerBuff($hero, Sort::where('nom', 'Traverser la Pierre')->firstOrFail());
    expect(app(MoteurSorts::class)->traverseRoche($hero->fresh()))->toBeTrue();

    $traversante = FabriqueGrille::pour($quete, traverseRoche: true);
    expect($traversante->estTraversable($roche['x'], $roche['y']))->toBeTrue();

    // Terminer son mouvement DANS la roche fait tomber le héros (décision de
    // René : notre moteur n'a pas de mort instantanée, seulement `tombe`).
    $etat->update(['position_x' => $roche['x'], 'position_y' => $roche['y'], 'a_joue' => false]);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();

    expect((bool) $etat->fresh()->tombe)->toBeTrue()
        ->and((int) $hero->fresh()->pv_body)->toBe(0);
});

it('exige une ligne de vue pour TOUT sort, et laisse toujours le lanceur se cibler', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $lanceur = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'elfe']);
    $compagnon = creerHeros($alice, $groupe, 'Brunhilde', 2);
    app(MoteurSorts::class)->attacherElement($lanceur, 'terre');

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $etatL = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $lanceur->id)->firstOrFail();
    $etatC = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $compagnon->id)->firstOrFail();

    // On enferme le compagnon DANS la roche : aucune ligne de vue possible.
    $roche = null;
    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if ($c !== 's') {
                $roche = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }
    $etatC->update(['position_x' => $roche['x'], 'position_y' => $roche['y']]);

    $soin = Sort::where('nom', 'Soin du Corps')->firstOrFail();
    $cibles = collect(collect(app(MoteurSorts::class)->options($groupe->fresh(), $quete->fresh(), $lanceur->fresh()))
        ->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])
        ->firstWhere('cle', "sort:{$soin->id}")['cibles'] ?? [];
    $ids = collect($cibles)->pluck('id')->all();

    // Le lanceur se voit toujours lui-même — « may be cast on any one hero,
    // INCLUDING YOURSELF » (Heal Body, LR p. 8).
    expect($ids)->toContain($lanceur->id);

    // …mais un compagnon hors de vue n'est plus ciblable. La ligne de vue est
    // exigée pour TOUT sort, pas seulement les offensifs (LR p. 14) : on
    // soignait auparavant à travers les murs, à l'autre bout du donjon.
    expect($ids)->not->toContain($compagnon->id);
});

it('propose les DEUX modes de Génie : attaquer ou ouvrir une porte à distance', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'magicien']);

    // Le magicien doit connaître l'Air pour disposer de Génie.
    app(MoteurSorts::class)->attacherElement($hero, 'air');

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $options = app(MoteurSorts::class)->options($groupe->fresh(), $quete, $hero->fresh());
    $genie = Sort::where('nom', 'Génie')->firstOrFail();

    // ⚠ Les deux modes sont désormais deux ENTRÉES de la même liste, et c'est
    // le mode porte qui gonflait le plus le menu : une option par porte fermée.
    $entrees = collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? []);

    $attaque = $entrees->firstWhere('cle', "sort:{$genie->id}");
    $portes = $entrees->filter(
        fn (array $e) => str_starts_with((string) $e['cle'], "sort:{$genie->id}:porte:")
    );

    // Mode 1 : l'attaque, avec ses cibles légales.
    expect($attaque)->not->toBeNull()
        ->and($attaque)->toHaveKey('sort_id');

    // Mode 2 : une option par porte fermée d'une salle découverte. Le texte
    // officiel dit « ouvre une porte AU CHOIX » : aucune adjacence requise,
    // c'est ce qui permet de dégager un passage bloqué par des figures.
    expect($portes)->not->toBeEmpty();
    foreach ($portes as $entree) {
        expect($entree['mode'])->toBe('ouvre_porte')
            ->and($entree)->toHaveKey('porte')
            ->and($entree['sort_id'])->toBe($genie->id);
    }

    // Les libellés doivent être DISTINCTS : six « ouvrir une porte à distance »
    // identiques revenaient à choisir au hasard (constaté en partie réelle).
    $libelles = $portes->pluck('nom');
    expect($libelles->unique()->count())->toBe($libelles->count());
});

it('résout le mode « ouvrir une porte » sans exiger de cible-figurine', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'magicien']);
    app(MoteurSorts::class)->attacherElement($hero, 'air');

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $genie = Sort::where('nom', 'Génie')->firstOrFail();

    $entree = collect(collect(app(MoteurSorts::class)->options($groupe->fresh(), $quete, $hero->fresh()))
        ->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])
        ->first(fn (array $e) => str_starts_with((string) $e['cle'], "sort:{$genie->id}:porte:"));
    expect($entree)->not->toBeNull();

    // Le mode porte ne porte AUCUN `cible_id` : le garde-fou de ligne de vue,
    // qui s'exécutait avant l'aiguillage, le rejetait donc systématiquement par
    // « Cible requise : parametres.cible_id » — chaque ouverture à distance
    // échouait (constaté en partie réelle, 2026-08-06).
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => $entree['cle']],
    ])
        ->assertAccepted()
        ->assertJsonPath('resultat.mode', 'ouvre_porte');

    // …et la porte visée est bien ouverte sur la carte.
    $p = $entree['porte'];
    $ouverte = collect($quete->fresh()->carte->grille['portes'])
        ->first(fn (array $x) => (int) $x['x'] === $p['x'] && (int) $x['y'] === $p['y']
            && (string) ($x['cote'] ?? 'e') === $p['cote']);
    expect($ouverte['etat'])->toBe('ouverte');
});

it('donne un parchemin par sort, chacun résoluble et de difficulté synchronisée', function () {
    $sorts = Sort::all();
    $parchemins = Objet::where('categorie', 'parchemin')->get();

    expect($parchemins)->toHaveCount($sorts->count());

    foreach ($parchemins as $parchemin) {
        $effet = (array) $parchemin->effet;
        $sort = Sort::find($effet['sort_id'] ?? 0);

        // `sort_id` est ce que lit resoudreParchemin() : sans lui, le parchemin
        // est irrésoluble et l'action est refusée en 422.
        expect($sort)->not->toBeNull("{$parchemin->nom} : sort_id introuvable.")
            ->and((int) ($effet['difficulte_non_lanceur'] ?? -1))
            ->toBe((int) $sort->difficulte_parchemin, "{$parchemin->nom} : difficulté affichée ≠ lancée.")
            ->and((int) $sort->difficulte_parchemin)->toBeGreaterThan(0)
            ->and((int) $sort->difficulte_parchemin)->toBeLessThanOrEqual(3);
    }
});

it('ne nomme aucune condition qui n\'existe pas au catalogue', function () {
    // ⚠ Trouvé en PARTIE RÉELLE le 2026-08-14, pas par les tests : le sort
    // *Terreur* du Warlock posait une condition « Terrifié » absente du
    // catalogue. Il partait donc en 422 dès qu'une cible RATAIT sa résistance,
    // et ne paraissait fonctionner que lorsqu'il échouait — le pire des
    // masques. Les tests vérifiaient que chaque CLÉ d'effet a un lecteur ;
    // aucun ne vérifiait que la VALEUR désigne quelque chose de réel.
    $catalogue = Condition::pluck('nom')->all();

    foreach (Sort::all() as $sort) {
        $nom = $sort->effet['condition_appliquee'] ?? null;

        if ($nom === null) {
            continue;
        }

        expect(in_array($nom, $catalogue, true))
            ->toBeTrue("{$sort->nom} : condition « {$nom} » absente du catalogue.");
    }

    // Même garde pour les conditions posées sur un MONSTRE : elles ne vivent
    // pas dans `conditions` mais dans le vocabulaire du moteur.
    foreach (Sort::all() as $sort) {
        $nom = $sort->effet['condition_monstre'] ?? null;

        if ($nom === null) {
            continue;
        }

        expect(in_array($nom, MoteurSorts::CONDITIONS_MONSTRE, true))
            ->toBeTrue("{$sort->nom} : condition de monstre « {$nom} » hors vocabulaire.");
    }
});

it('Traverser la Pierre se lance sur le LANCEUR ou un autre héros en vue', function () {
    // Texte de la carte (fourni par René, 2026-09-02) : « This spell may be cast
    // on any one hero in your line of sight, INCLUDING YOURSELF. The target may
    // move through walls during their next movement. »
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $lanceur = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'elfe']);

    // ⚠ DEUX joueurs, et ce n'est pas cosmétique : le menu est mis en cache par
    // JOUEUR (`GenererMenu::cleMenu($groupe, $joueur)`), donc deux héros du même
    // compte se partagent un seul emplacement et s'écrasent l'un l'autre.
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $compagnon = creerHeros($bob, $groupe, 'Brunhilde', 2);

    app(MoteurSorts::class)->attacherElement($lanceur, 'terre');

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    desFiges(array_fill(0, 200, 4));

    $sort = Sort::where('nom', 'Traverser la Pierre')->firstOrFail();
    $sorts = app(MoteurSorts::class);

    $entree = collect(collect($sorts->options($groupe->fresh(), $quete->fresh(), $lanceur->fresh()))
        ->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])
        ->firstWhere('cle', "sort:{$sort->id}");

    // Le sort PORTE désormais une liste : tant qu'il était `cible: soi`, il
    // partait du deuxième niveau du menu, sans rien à viser.
    $ids = collect($entree['cibles'] ?? [])->pluck('id')->all();

    expect($ids)->toContain($lanceur->id)
        ->and($ids)->toContain($compagnon->id);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $lanceur->id);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sort->id}", 'cible_id' => $compagnon->id, 'cible_type' => 'heros'],
    ])->assertAccepted();

    // C'est le COMPAGNON qui traverse, pas le lanceur.
    expect($sorts->traverseRoche($compagnon->fresh()))->toBeTrue()
        ->and($sorts->traverseRoche($lanceur->fresh()))->toBeFalse();

    // ⚠ Ce qui rend le sort jouable sur un allié : `ce_tour` expire au tour de
    // son PORTEUR, pas de son lanceur — `expirerBuffs()` reçoit le héros dont le
    // tour s'achève. Sans cela le sort serait un cadeau vide, éteint avant que
    // le bénéficiaire ait pu faire un pas.
    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();

    expect($sorts->traverseRoche($compagnon->fresh()))->toBeTrue();

    // …et il le perd bien à la fin du SIEN.
    GenererMenu::dispatchSync($groupe->id, (int) $bob->id, (int) $compagnon->id);
    test()->actingAs($bob, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();

    expect($sorts->traverseRoche($compagnon->fresh()))->toBeFalse();
});

/*
 * ------------------------------------------------------------------
 * Alignement sur les CARTES OFFICIELLES de sort (photos de René,
 * 2026-09-02 — transcrites doc 16 §3bis). Trois écarts, chacun vérifié
 * en jeu et non dans le catalogue.
 * ------------------------------------------------------------------
 */

it('Courage tombe aussi quand plus aucun monstre n\'est en vue, pas seulement à l\'attaque', function () {
    // « The spell is broken the moment a monster is no longer in the hero's
    // line of sight. » Cette moitié de la carte n'était pas portée : le buff
    // survivait au combat et attendait la bagarre suivante.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    ['heros' => $heros, 'quete' => $quete, 'instance' => $gobelin] = $ctx;

    app(MoteurSorts::class)->attacherElement($heros, 'feu');
    app(MoteurSorts::class)->appliquerBuff($heros, Sort::where('nom', 'Courage')->firstOrFail());

    $sorts = app(MoteurSorts::class);
    expect($sorts->aBuff($heros->fresh(), 'bonus_des_attaque'))->toBeTrue();

    // Le monstre tombe : plus rien en vue → le buff doit partir de lui-même.
    $gobelin->update(['etat' => 'vaincu']);
    desFiges(array_fill(0, 200, 4));

    test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();

    expect($sorts->aBuff($heros->fresh(), 'bonus_des_attaque'))->toBeFalse();
});

it('Tempête fait sauter le tour du monstre SANS jet de résistance', function () {
    // « That monster then misses its next turn. » Aucun jet sur la carte ;
    // nous imposions un `jet_mind`, ce qui rendait le sort d'autant plus
    // inutile que la cible était coriace.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    ['heros' => $heros, 'instance' => $gobelin, 'quete' => $quete] = $ctx;

    app(MoteurSorts::class)->attacherElement($heros, 'air');

    // Mind élevé : sous l'ancienne règle, la résistance passait souvent.
    $gobelin->update(['pv_mind' => 6]);

    $tempete = Sort::where('nom', 'Tempête')->firstOrFail();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 200, 6)); // les pires dés possibles : sans jet, ils ne servent pas

    $reponse = test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$tempete->id}", 'cible_id' => $gobelin->id, 'cible_type' => 'monstre'],
    ])->assertAccepted();

    expect($reponse->json('resultat.sans_jet'))->toBeTrue()
        ->and($reponse->json('resultat.effet_applique'))->toBeTrue()
        ->and($gobelin->fresh()->habillage['conditions'] ?? [])->toHaveKey('saute_tour');
});

it('Voile de Brume fait TRAVERSER les monstres, et n\'autorise pas à s\'arrêter dessus', function () {
    // « On the hero's next move, they may move unseen through spaces that are
    // occupied by monsters. » Nous posions `inattaquable` — un tout autre sort.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    ['heros' => $heros, 'quete' => $quete, 'instance' => $gobelin, 'etatHeros' => $etat] = $ctx;

    app(MoteurSorts::class)->attacherElement($heros, 'eau');

    $sorts = app(MoteurSorts::class);
    expect($sorts->franchitFigures($heros->fresh()))->toBeFalse();

    $sorts->appliquerBuff($heros, Sort::where('nom', 'Voile de Brume')->firstOrFail());
    expect($sorts->franchitFigures($heros->fresh()))->toBeTrue()
        // ⚠ Ce n'est PAS de l'invisibilité : le héros reste ciblable.
        ->and($sorts->estInattaquable($heros->fresh()))->toBeFalse();

    desFiges(array_fill(0, 200, 4));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);

    // Le gobelin est au contact (helper) : sa case est traversable, pas habitable.
    // ⚠ Le message est asserté, pas seulement le 422 : sans le buff la même
    // requête échoue AUSSI, mais pour « destination inaccessible » — le test
    // ne prouverait alors rien du garde-fou qu'il vise.
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => (int) $gobelin->position_x, 'y' => (int) $gobelin->position_y],
    ])->assertStatus(422)->assertJsonPath(
        'errors.parametres.0',
        'On traverse une figure, on ne s\'arrête pas dessus : cette case est occupée.',
    );
});

/*
 * ------------------------------------------------------------------
 * Pouvoirs ORIGINAUX des cartes (arbitrage de René, 2026-09-02) : les
 * sorts de feu infligent un montant FIXE que la cible réduit à coups
 * de d6 bruts, et Sommeil se rompt sur un 6 — sur-le-champ puis à
 * chaque tour du monstre.
 * ------------------------------------------------------------------
 */

it('Boule de Feu inflige 2 dégâts fixes, dont chaque 5 ou 6 des dés rouges en annule 1', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'magicien']);
    ['heros' => $mage, 'instance' => $proie, 'groupe' => $groupe] = $ctx;

    app(MoteurSorts::class)->attacherElement($mage, 'feu');
    $proie->update(['pv_body' => 5, 'pv_body_max' => 5]);

    $sort = Sort::where('nom', 'Boule de Feu')->firstOrFail();
    GenererMenu::dispatchSync($groupe->id, (int) $ctx['alice']->id, (int) $mage->id);

    // UN seul 5 sur les deux dés rouges → 1 dégât annulé sur 2.
    desFiges([5, 2]);

    $reponse = test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sort->id}", 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertAccepted();

    $reponse->assertJsonPath('resultat.degats_fixes', 2)
        ->assertJsonPath('resultat.des_resistance', [5, 2])
        ->assertJsonPath('resultat.degats_annules', 1)
        ->assertJsonPath('resultat.degats', 1);

    expect((int) $proie->fresh()->pv_body)->toBe(4);
});

it('Trait de Feu est entièrement annulé par un seul 5 ou 6', function () {
    // « It inflicts 1 Body Point of damage, UNLESS the monster can immediately
    // roll a 5 or 6 using 1 red die. » Un dé, un point : tout ou rien.
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'magicien']);
    ['heros' => $mage, 'instance' => $proie, 'groupe' => $groupe] = $ctx;

    app(MoteurSorts::class)->attacherElement($mage, 'feu');
    $proie->update(['pv_body' => 5, 'pv_body_max' => 5]);

    $sort = Sort::where('nom', 'Trait de Feu')->firstOrFail();
    GenererMenu::dispatchSync($groupe->id, (int) $ctx['alice']->id, (int) $mage->id);

    desFiges([6]); // le monstre encaisse zéro

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sort->id}", 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertAccepted()
        ->assertJsonPath('resultat.degats_annules', 1)
        ->assertJsonPath('resultat.degats', 0);

    expect((int) $proie->fresh()->pv_body)->toBe(5);
});

it('Sommeil prend TOUJOURS, et le monstre tente de rompre sur-le-champ', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'magicien']);
    ['heros' => $mage, 'instance' => $proie, 'groupe' => $groupe] = $ctx;

    app(MoteurSorts::class)->attacherElement($mage, 'eau');
    $proie->update(['pv_mind' => 2]); // 2 dés de rupture

    $sort = Sort::where('nom', 'Sommeil')->firstOrFail();
    $sorts = app(MoteurSorts::class);

    GenererMenu::dispatchSync($groupe->id, (int) $ctx['alice']->id, (int) $mage->id);
    desFiges([3, 4]); // aucun 6 : il reste endormi

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sort->id}", 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertAccepted()
        // ⚠ Aucun jet de résistance AU LANCER : le sort prend, point.
        ->assertJsonPath('resultat.sans_jet', true)
        ->assertJsonPath('resultat.effet_applique', true)
        ->assertJsonPath('resultat.rupture_immediate', false)
        ->assertJsonPath('resultat.des_rupture', [3, 4]);

    expect($sorts->monstreA($proie->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeTrue();
});

it('Sommeil est rompu sur-le-champ dès qu\'un 6 sort', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'magicien']);
    ['heros' => $mage, 'instance' => $proie, 'groupe' => $groupe] = $ctx;

    app(MoteurSorts::class)->attacherElement($mage, 'eau');
    $proie->update(['pv_mind' => 2]);

    $sort = Sort::where('nom', 'Sommeil')->firstOrFail();
    GenererMenu::dispatchSync($groupe->id, (int) $ctx['alice']->id, (int) $mage->id);
    desFiges([2, 6]); // le second dé le réveille aussitôt

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sort->id}", 'cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertAccepted()->assertJsonPath('resultat.rupture_immediate', true);

    expect(app(MoteurSorts::class)->monstreA($proie->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeFalse();
});

it('un monstre endormi retente la rupture à CHACUN de ses tours', function () {
    // La moitié qui manquait complètement : une fois endormi, le monstre ne se
    // réveillait plus jamais autrement qu'en étant attaqué.
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'magicien']);
    ['instance' => $proie, 'groupe' => $groupe, 'alice' => $alice] = $ctx;

    $sorts = app(MoteurSorts::class);
    $proie->update(['pv_mind' => 1]); // 1 dé par tentative
    $sorts->poserConditionMonstre($proie, MoteurSorts::MONSTRE_ENDORMI);

    // Tour 1 : pas de 6 → il dort encore, et le journal porte le jet.
    desFiges([2, ...array_fill(0, 60, 4)]);
    $reponse = test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();

    $actions = collect($reponse->json('resultat.tour_monstres.actions'));
    expect($actions->firstWhere('type', 'monstre_endormi'))->not->toBeNull()
        ->and($actions->firstWhere('type', 'monstre_endormi')['des_rupture'])->toBe([2])
        ->and($sorts->monstreA($proie->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeTrue();

    // Tour 2 : un 6 → il se réveille ET joue son tour dans la foulée.
    desFiges([6, ...array_fill(0, 60, 4)]);
    $reponse = test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();

    $actions = collect($reponse->json('resultat.tour_monstres.actions'));

    expect($sorts->monstreA($proie->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeFalse()
        ->and($actions->firstWhere('type', 'monstre_endormi'))->toBeNull();
});

it('un monstre ENDORMI ne se défend plus : « it cannot move, attack, or defend itself »', function () {
    // René, 2026-09-02 : « après tout, il dort ». Dernier écart de la carte de
    // Sommeil, et il vaut pour les TROIS chemins de frappe puisqu'ils passent
    // tous par `defenseEffective()`.
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'magicien']);
    ['instance' => $proie, 'groupe' => $groupe, 'alice' => $alice, 'heros' => $heros] = $ctx;

    $sorts = app(MoteurSorts::class);
    $defenseCatalogue = (int) $proie->monstre->defense;

    expect($defenseCatalogue)->toBeGreaterThan(0)
        ->and($proie->defenseEffective())->toBe($defenseCatalogue);

    $sorts->poserConditionMonstre($proie, MoteurSorts::MONSTRE_ENDORMI);

    expect($proie->fresh()->defenseEffective())->toBe(0);

    // …et en jeu : le héros frappe, la volée de défense est VIDE, puis le coup
    // le réveille — dans cet ordre.
    $proie->update(['pv_body' => 9, 'pv_body_max' => 9]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);

    desFiges(array_fill(0, 60, 1)); // que des crânes : rien à parer de toute façon

    $reponse = test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cible_id' => $proie->id, 'cible_type' => 'monstre'],
    ])->assertAccepted();

    expect($reponse->json('resultat.faces_defense'))->toBe([])
        ->and($sorts->monstreA($proie->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeFalse();
});
