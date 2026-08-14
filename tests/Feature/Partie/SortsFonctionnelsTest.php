<?php

declare(strict_types=1);

use App\Engine\MotsClesSort;
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
    'condition_bonus_attaque', // MoteurSorts::bonusDes() — dé conditionnel (au contact)
    'regain',                  // MoteurSorts::regagnerSorts() — App\Engine\RegainEffet
    'reaction',                // MoteurReactions::sortReactifDisponible() — hors tour
    'ignore_pieges_fosse',     // MoteurPieges::declencher() via MoteurSorts::aBuff()
    'condition_monstre',       // ResolveurTour::appliquerEffetMental() — MoteurSorts::CONDITIONS_MONSTRE
    'seuil_mind_max',          // ResolveurTour::sortMental() — s'applique SANS jet sous le seuil
    'image_miroir',            // App\Listeners\ImageMiroir — écouteur de HerosVaSubirDegats
    'tour_supplementaire',     // ResolveurTour::marquerCreneau() — le tour recommence
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
        'tour_supplementaire', 'image_miroir'];

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
        ->and(Sort::count())->toBe(27);
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
    $cibles = collect(app(MoteurSorts::class)->options($groupe->fresh(), $quete->fresh(), $lanceur->fresh()))
        ->firstWhere('id', "sort_{$soin->id}")['parametres']['cibles'] ?? [];
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

    $attaque = collect($options)->firstWhere('id', "sort_{$genie->id}");
    $portes = collect($options)->filter(
        fn (array $o) => str_starts_with((string) $o['id'], "sort_{$genie->id}_porte_")
    );

    // Mode 1 : l'attaque, avec ses cibles légales.
    expect($attaque)->not->toBeNull()
        ->and($attaque['parametres'])->toHaveKey('sort_id');

    // Mode 2 : une option par porte fermée d'une salle découverte. Le texte
    // officiel dit « ouvre une porte AU CHOIX » : aucune adjacence requise,
    // c'est ce qui permet de dégager un passage bloqué par des figures.
    expect($portes)->not->toBeEmpty();
    foreach ($portes as $option) {
        expect($option['parametres']['mode'])->toBe('ouvre_porte')
            ->and($option['parametres'])->toHaveKey('porte')
            ->and($option['parametres']['sort_id'])->toBe($genie->id);
    }

    // Les libellés doivent être DISTINCTS : six « ouvrir une porte à distance »
    // identiques revenaient à choisir au hasard (constaté en partie réelle).
    $libelles = $portes->pluck('libelle');
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

    $option = collect(app(MoteurSorts::class)->options($groupe->fresh(), $quete, $hero->fresh()))
        ->first(fn (array $o) => str_starts_with((string) $o['id'], "sort_{$genie->id}_porte_"));
    expect($option)->not->toBeNull();

    // Le mode porte ne porte AUCUN `cible_id` : le garde-fou de ligne de vue,
    // qui s'exécutait avant l'aiguillage, le rejetait donc systématiquement par
    // « Cible requise : parametres.cible_id » — chaque ouverture à distance
    // échouait (constaté en partie réelle, 2026-08-06).
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])
        ->assertAccepted()
        ->assertJsonPath('resultat.mode', 'ouvre_porte');

    // …et la porte visée est bien ouverte sur la carte.
    $p = $option['parametres']['porte'];
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
    $catalogue = App\Models\Condition::pluck('nom')->all();

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
