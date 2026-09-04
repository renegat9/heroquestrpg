<?php

declare(strict_types=1);

use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Models\SortDread;
use App\Partie\DemarreurQuete;
use App\Partie\MoteurDread;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
 * Sorciers ennemis nommés à deck dédié (Phase 2, 3.8 — doc 09 §4).
 * Un monstre avec `archetype_lanceur` résout le RÉPERTOIRE COMPLET de l'archétype
 * (config/archetypes_lanceurs.php) plutôt que sa liste `sorts_dread` propre.
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

/** Noms de sorts disponibles pour une instance (méthode privée → réflexion). */
function repertoireDe(string $nomMonstre): array
{
    $monstre = Monstre::where('nom_base', $nomMonstre)->firstOrFail();

    $instance = new InstanceMonstre(['elite' => false]);
    $instance->setRelation('monstre', $monstre);

    $moteur = app(MoteurDread::class);
    $methode = new ReflectionMethod($moteur, 'sortsDisponibles');
    $methode->setAccessible(true);

    /** @var Collection<int, SortDread> $sorts */
    $sorts = $methode->invoke($moteur, $instance, new Quete);

    return $sorts->pluck('nom')->all();
}

it('résout le répertoire complet de l\'archétype pour un lanceur nommé', function () {
    $sorts = repertoireDe('Sorcier des Tempêtes'); // archétype maitre_tempetes

    expect($sorts)
        ->toContain('Tempête de feu')
        // ⚠ L'*Éclair de Chaos* (carte *Lightning Bolt*) a remplacé le *Trait de
        // Chaos* le 2026-09-04 : ce dernier était notre seule invention du
        // catalogue de Dread, et il portait à lui seul la frappe à distance du
        // répertoire du Maître des tempêtes.
        ->toContain('Éclair de Chaos')
        ->toContain('Fuite')
        // L'invocation appartient au Nécromancien, pas au Maître des tempêtes.
        ->not->toContain('Invocation de morts-vivants');
});

it('donne au Nécromancien son propre répertoire (invocation + contrôle)', function () {
    $sorts = repertoireDe('Liche'); // archétype necromancien

    expect($sorts)
        ->toContain('Invocation de morts-vivants')
        ->toContain('Sommeil')
        ->not->toContain('Tempête de feu');
});

it('retombe sur la liste sorts_dread propre quand aucun archétype n\'est défini', function () {
    // ⚠ Le CHAMPION depuis le 2026-09-04, plus le Seigneur : celui-ci a reçu un
    // archétype pour entrer dans le pool de rencontre finale. Le Champion reste
    // donc le seul porteur EN PRODUCTION du repli de `repertoireSorts()`, et
    // c'est délibéré — une branche que plus aucune donnée n'emprunte est une
    // branche dont on ne sait plus si elle marche.
    $sorts = repertoireDe('Champion');

    expect($sorts)
        ->toContain('Sommeil')
        ->toContain('Tempête de feu');
});

it('remplit un POOL d\'archétypes dans chaque gabarit à rencontre finale', function () {
    // ⚠ LE VERROU ANTI-RÉGRESSION du 2026-09-04. `rencontre_finale.archetype`
    // existait depuis la 3.8, fonctionnait, et **aucun gabarit ne l'avait jamais
    // rempli** : le repli prenait donc toujours le leader de coût du palier, le
    // Seigneur fermait TOUTES les quêtes, et pas un seul lanceur nommé n'avait
    // jamais été tiré en partie. C'est la leçon des leviers — un champ qui
    // marche mais que personne ne remplit est aussi muet qu'un champ sans
    // lecteur, et rien ne le signale.
    $gabarits = App\Models\GabaritQuete::all();
    expect($gabarits)->not->toBeEmpty();

    $avecFinale = $gabarits->filter(fn ($g) => data_get($g->structure, 'rencontre_finale.tier') !== null);
    expect($avecFinale)->not->toBeEmpty();

    foreach ($avecFinale as $gabarit) {
        $tier = (string) data_get($gabarit->structure, 'rencontre_finale.tier');
        $pool = (array) data_get($gabarit->structure, 'rencontre_finale.archetypes', []);

        expect($pool)->not->toBeEmpty("{$gabarit->nom} : aucun archétype de rencontre finale.");

        foreach ($pool as $cle) {
            // L'archétype existe…
            expect(config("archetypes_lanceurs.{$cle}"))
                ->not->toBeNull("{$gabarit->nom} : archétype « {$cle} » inconnu.");

            // …et il est porté par une créature DU BON PALIER, sans quoi le
            // tirage ne le trouverait jamais et retomberait en silence sur le
            // leader de coût.
            expect(Monstre::where('archetype_lanceur', $cle)->where('tier', $tier)->exists())
                ->toBeTrue("{$gabarit->nom} : « {$cle} » n'est porté par aucun monstre de palier {$tier}.");
        }
    }
});

it('facture PLUS CHER une créature éthérée, à tous les paliers', function () {
    // ⚠ Une éthérée ne se blesse à l'arme que sur un bouclier noir (1/6) au lieu
    // d'un crâne (3/6) : mesurée sur l'Ombre du Dread à 5 dés d'attaque, elle
    // tient SIX fois plus longtemps qu'un bloc identique non éthéré. Son prix
    // doit le dire (René, 2026-09-04), sans quoi le budget de rencontre la paie
    // au tarif d'un monstre ordinaire et lui adjoint une escorte complète.
    $demarreur = app(DemarreurQuete::class);

    $ombre = Monstre::where('nom_base', 'Ombre du Dread')->firstOrFail();
    $liche = Monstre::where('nom_base', 'Liche')->firstOrFail();

    expect($demarreur->coutEffectif($ombre))
        ->toBe((int) ceil((int) $ombre->cout * DemarreurQuete::RATIO_COUT_ETHERE))
        ->toBeGreaterThan((int) $ombre->cout);

    // …et une créature ordinaire paie son prix affiché.
    expect($demarreur->coutEffectif($liche))->toBe((int) $liche->cout);

    // ⚠ Le SPECTRE aussi : il est éthéré, de tier `base`, et acheté comme sbire
    // ordinaire. Ne majorer que le boss aurait laissé le même défaut un palier
    // plus bas — c'est pour cela que `coutEffectif()` est un point de passage et
    // pas une ligne dans l'achat de la rencontre finale.
    $spectre = Monstre::where('nom_base', 'Spectre')->firstOrFail();
    expect($demarreur->coutEffectif($spectre))->toBeGreaterThan((int) $spectre->cout);
});

it('fait TOURNER la rencontre finale dans le pool, sans hasard', function () {
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    $pool = ['necromancien', 'maitre_tempetes', 'spectre_effroi', 'horreur_glacee', 'archimage_elfe'];
    $structure = ['rencontre_finale' => ['tier' => 'boss', 'archetypes' => $pool]];
    $attendus = Monstre::whereIn('archetype_lanceur', $pool)->pluck('nom_base')->all();

    $parPosition = [];
    for ($position = 1; $position <= 6; $position++) {
        $parPosition[$position] = $methode->invoke($demarreur, $structure, 30, 5, $position, 0)[0]->nom_base;
    }

    // Chaque adversaire sort DU pool…
    foreach ($parPosition as $nom) {
        expect(in_array($nom, $attendus, true))->toBeTrue("« {$nom} » ne fait pas partie du pool.");
    }

    // …et l'adversaire CHANGE d'un jalon à l'autre : c'est ce qui distingue une
    // rotation d'un `first()` déguisé, et c'est tout l'objet du correctif.
    expect(count(array_unique($parPosition)))->toBeGreaterThan(1);

    // ⚠ Et il est STABLE : rejouer la même quête doit rendre le même boss. Le
    // boss final est un PLACEMENT, pas une pioche — même raison que
    // `salle_artefact`, qu'une reprise ne re-tire jamais. Sans cela,
    // « Recommencer la quête » deviendrait un bouton pour changer d'adversaire
    // jusqu'à tomber sur le plus commode.
    expect($methode->invoke($demarreur, $structure, 30, 5, 3, 0)[0]->nom_base)
        ->toBe($parPosition[3]);

    // …et deux GROUPES ne suivent pas la même succession.
    $autreGroupe = [];
    for ($position = 1; $position <= 6; $position++) {
        $autreGroupe[$position] = $methode->invoke($demarreur, $structure, 30, 5, $position, 2)[0]->nom_base;
    }
    expect($autreGroupe)->not->toBe($parPosition);
});

it('assigne le lanceur nommé demandé comme rencontre finale (indice de gabarit)', function () {
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    // Gabarit demandant un boss « necromancien » → la Liche, pas le Seigneur.
    $achats = $methode->invoke(
        $demarreur,
        ['rencontre_finale' => ['tier' => 'boss', 'archetype' => 'necromancien']],
        30,
        5,
        1, // positionArc
    );
    expect($achats[0]->nom_base)->toBe('Liche');

    // Sans indice d'archétype : le LEADER DE COÛT du palier. On ne fige pas son
    // nom — le bestiaire s'est enrichi des créatures d'extension le 2026-08-10,
    // et le Seigneur ogre (coût 22) a pris la tête. Ce que le test garde, c'est
    // la règle : à défaut d'archétype, on prend le boss le plus cher abordable.
    $plusCher = Monstre::where('tier', 'boss')
        ->where('cout', '<=', 30)
        ->orderByDesc('cout')
        ->firstOrFail();

    $achatsDefaut = $methode->invoke(
        $demarreur,
        ['rencontre_finale' => ['tier' => 'boss']],
        30,
        5,
        1, // positionArc
    );
    expect($achatsDefaut[0]->nom_base)->toBe($plusCher->nom_base);
});

it('fait lancer en jeu un sort du répertoire de l\'archétype (champ sorts_dread vide)', function () {
    // Le Sorcier des Tempêtes a sorts_dread = [] mais l'archétype maitre_tempetes
    // doit lui ouvrir son répertoire : il lance un de ses sorts au tour des monstres.
    $ctx = demarrerQueteAvecMonstre('Sorcier des Tempêtes', ['attribut_mind' => 1, 'pv_mind' => 1, 'pv_mind_max' => 1]);

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $sortLance = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'sort_dread');

    $repertoire = config('archetypes_lanceurs.maitre_tempetes.sorts');

    expect($sortLance)->not->toBeNull()
        ->and($sortLance['sort'])->toBeIn($repertoire)
        ->and($sortLance['sort'])->not->toBe('Invocation de morts-vivants');
});

it('garantit que chaque sort d\'archétype existe dans le catalogue SortDread', function () {
    $catalogue = SortDread::pluck('nom')->all();

    foreach (config('archetypes_lanceurs') as $archetype) {
        foreach ($archetype['sorts'] as $nom) {
            expect($catalogue)->toContain($nom);
        }
    }
});

it('filtre le répertoire de l\'archétype par PALIER : le sous-boss n\'a pas les sorts vilains', function () {
    // Le Chamane Gobelin (sous_boss) porte l'archétype `chaman_orque`, dont le
    // répertoire liste Commandement — un sort de palier boss. `palier` n'avait
    // aucun lecteur : le sous-boss commandait les héros dès la première quête.
    $sorts = repertoireDe('Chamane Gobelin');

    expect(config('archetypes_lanceurs.chaman_orque.sorts'))->toContain('Commandement')
        ->and($sorts)
        ->toContain('Frayeur')
        ->toContain('Sommeil')
        // Palier `base` : un sous-boss y a droit, c'est le sens du minimum.
        ->toContain("Canaliser l'Effroi")
        // Palier `boss` : refusés au Chamane Gobelin, sous-boss.
        ->not->toContain('Commandement')
        ->not->toContain("Invocation d'orques");
});

it('connaît le palier de CHAQUE sort du catalogue (aucun rang muet)', function () {
    // Le filtre lit `MoteurDread::RANG_PALIER` et retombe sur 0 pour un palier
    // inconnu — fail open, comme partout ailleurs. Encore faut-il qu'aucun sort
    // semé ne prenne ce chemin : un palier absent de la table rendrait le sort
    // lançable par n'importe quel tier, en silence.
    $connus = array_keys((new ReflectionClass(MoteurDread::class))->getConstant('RANG_PALIER'));

    foreach (SortDread::all() as $sort) {
        expect($connus)->toContain($sort->palier);
    }
});
