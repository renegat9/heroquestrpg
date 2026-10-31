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

it('résout le répertoire complet de l\'archétype pour un lanceur nommé (sorts_uniques : le palier ne retire rien)', function () {
    // Balur (sous-boss, jeu de base) porte six sorts UNE FOIS chacun (`sorts_uniques`) : le répertoire entier, Fuite
    // (palier boss) comprise, lui est ouvert quel que soit son palier. Ancien porteur : le Sorcier des Tempêtes, retiré.
    $sorts = repertoireDe('Balur');

    expect($sorts)
        ->toContain('Tempête de feu')
        ->toContain('Fuite')
        ->toContain("Invocation d'orques")
        // L'invocation des morts-vivants appartient au Seigneur Sorcier de Barak Tor, pas à Balur.
        ->not->toContain('Invocation de morts-vivants');
});

it('donne au Seigneur Sorcier de Barak Tor son propre répertoire (invocation + contrôle)', function () {
    $sorts = repertoireDe('Seigneur Sorcier de Barak Tor'); // archétype seigneur_sorcier_barak_tor

    expect($sorts)
        ->toContain('Invocation de morts-vivants')
        ->toContain('Commandement')
        ->not->toContain('Tempête de feu');
});

it('retombe sur la liste sorts_dread propre quand aucun archétype n\'est défini', function () {
    // ⚠ Le Champion gardait cette liste brute jusqu'à son retrait (2026-10-31). Le Dragon de First Light la porte
    // désormais seul : sa carte ne nomme qu'UN sort. Une branche que plus aucune donnée n'emprunte est une branche
    // dont on ne sait plus si elle marche — celle-ci a encore son porteur.
    $sorts = repertoireDe('Dragon');

    expect($sorts)->toContain('Boule de Flammes');
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
        $creatures = (array) data_get($gabarit->structure, 'rencontre_finale.creatures', []);

        expect($pool)->not->toBeEmpty("{$gabarit->nom} : aucun archétype de rencontre finale.");

        // ⚠ L'INVARIANT qui aurait attrapé la régression du 2026-09-04 : le pool
        // ne se déclarait qu'en archétypes, or seuls les lanceurs en ont un —
        // sur 13 sous-boss, DEUX pouvaient apparaître, et les onze exclus
        // étaient les plus caractéristiques du bestiaire. Une créature d'un
        // palier qui ne peut jamais être tirée est une donnée morte ; l'écarter
        // doit être un choix ÉCRIT, pas un effet de bord de la mécanique de
        // sélection.
        $atteignables = Monstre::where('tier', $tier)
            ->where(fn ($q) => $q->whereIn('archetype_lanceur', $pool)->orWhereIn('nom_base', $creatures))
            ->pluck('nom_base')->all();

        // ⚠ Une créature d'une boîte DÉSACTIVÉE a le droit d'être inatteignable :
        // c'est un choix écrit (`BOITES_INCOMPLETES`), pas un effet de bord.
        $desactivees = Monstre::whereIn('boite', array_keys(DemarreurQuete::BOITES_INCOMPLETES))
            ->pluck('nom_base')->all();

        // ⚠ Une créature RETIRÉE DES POOLS par décision écrite a le même droit, nommée ici avec sa raison (retirer un contenu
        // est un choix écrit, jamais une omission). Vide depuis le 2026-10-24 : l'Ombre du Dread, seule créature qui y figurait
        // (remplacée par Magrian dans « Confrontation finale »), a été retirée du catalogue sur décision de René.
        $retiresParDecision = [];

        // ⚠ Une PHASE qui n'est pas la première d'une chaîne a aussi le droit
        // d'être inatteignable À L'ACHAT (chantier monstre à phases,
        // 2026-10-04) : Gruzbella Déterminée/Imprudente, Spawn of the Pit
        // déchaîné, Demonspider/Demonape ne s'atteignent qu'EN JEU, par
        // `monstres.phase_suivante` — jamais achetées directement, sans quoi
        // une rencontre pourrait démarrer déjà transformée. C'est un choix
        // écrit (la colonne elle-même), pas un effet de bord : toute ligne
        // nommée par le `phase_suivante` D'UNE AUTRE ligne est exemptée.
        $phasesSuivantes = Monstre::whereNotNull('phase_suivante')->pluck('phase_suivante')->all();

        // ⚠ Une FORME DE PIOCHE (`synchroformes`, Qwindrak the Warlock, 2026-10-17) a le même droit : le
        // Dragon est la DERNIÈRE forme de la pioche, jamais achetée seule (elle ne s'atteint qu'en jeu, à la
        // mort de la forme précédente). Lu sur la fiche qui porte la pioche, jamais recopié ici.
        $formesDePioche = Monstre::all()->flatMap(function (Monstre $m): array {
            $decl = ((array) ($m->capacites ?? []))['synchroformes'] ?? null;

            return is_array($decl)
                ? [...array_map('strval', (array) ($decl['formes'] ?? [])), (string) ($decl['dernier'] ?? '')]
                : [];
        })->all();

        $orphelins = Monstre::where('tier', $tier)->pluck('nom_base')
            ->reject(fn ($n) => in_array($n, $atteignables, true) || in_array($n, $desactivees, true)
                || in_array($n, $phasesSuivantes, true) || in_array($n, $formesDePioche, true)
                || in_array($n, $retiresParDecision, true))
            ->values()->all();

        expect($orphelins)->toBe([],
            "{$gabarit->nom} : créatures de palier {$tier} qu'aucune quête ne peut faire apparaître — "
            .implode(', ', $orphelins));

        foreach ($creatures as $nom) {
            expect(Monstre::where('nom_base', $nom)->where('tier', $tier)->exists())
                ->toBeTrue("{$gabarit->nom} : « {$nom} » n'existe pas au palier {$tier}.");
        }

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

it('donne une BOÎTE à chaque créature, et aucune de fantaisie', function () {
    // ⚠ La donnée existait déjà, mais seulement en COMMENTAIRE : `MonstreSeeder`
    // groupe ses créatures par boîte depuis le portage de la doc 18, et personne
    // ne pouvait la lire. `null` n'est pas un trou — il vaut « aucune boîte »,
    // le cas de nos propres blocs de stats, qui conviennent à tout thème.
    // ⚠ Les boîtes DÉSACTIVÉES restent des boîtes connues : leurs créatures sont
    // toujours au catalogue comme blocs de stats, c'est le THÈME qui est retiré.
    $connues = array_merge(
        DemarreurQuete::BOITES_THEMATIQUES,
        array_keys(DemarreurQuete::BOITES_INCOMPLETES),
        ['base'],
    );

    foreach (Monstre::all() as $m) {
        if ($m->boite !== null) {
            expect(in_array($m->boite, $connues, true))
                ->toBeTrue("{$m->nom_base} : boîte « {$m->boite} » inconnue.");
        }
    }

    // Chaque boîte thématique a au moins une créature, sinon le thème qu'elle
    // nomme ne changerait jamais rien.
    foreach (DemarreurQuete::BOITES_THEMATIQUES as $boite) {
        expect(Monstre::where('boite', $boite)->exists())
            ->toBeTrue("La boîte « {$boite} » ne contient aucune créature.");
    }

    // ⚠ Une boîte désactivée ne doit PAS être proposée comme thème, et sa
    // désactivation doit porter une raison écrite. Les deux listes ne se
    // recoupent jamais : c'est ce qui empêche de « réactiver » une boîte par
    // inadvertance en la remettant dans la rotation.
    foreach (DemarreurQuete::BOITES_INCOMPLETES as $boite => $raison) {
        expect(in_array($boite, DemarreurQuete::BOITES_THEMATIQUES, true))
            ->toBeFalse("La boîte « {$boite} » est déclarée incomplète ET proposée comme thème.");
        expect($raison)->not->toBeEmpty("La boîte « {$boite} » est désactivée sans raison écrite.");
        expect(Monstre::where('boite', $boite)->exists())
            ->toBeTrue("La boîte « {$boite} » est désactivée mais n'existe pas.");
    }

    // …et les méchants du JEU DE BASE (boîte `base`) appartiennent à toutes : ce sont eux qui
    // garantissent qu'un pool ne se vide jamais, quel que soit le thème.
    expect(Monstre::where('boite', 'base')->pluck('nom_base')->all())
        ->toContain('Ulag', 'Grak', 'Balur', 'Seigneur Sorcier de Barak Tor');
});

it('fait tourner le THÈME par groupe, et le tient toute la campagne', function () {
    $demarreur = app(DemarreurQuete::class);

    // Deux groupes voisins ne descendent pas dans le même bestiaire…
    $themes = [];
    for ($id = 1; $id <= count(DemarreurQuete::BOITES_THEMATIQUES); $id++) {
        $themes[] = $demarreur->themeBestiaire($id);
    }
    expect(array_unique($themes))->toHaveCount(count(DemarreurQuete::BOITES_THEMATIQUES));

    // …et le thème d'un groupe ne bouge pas : c'est un placement, pas une
    // pioche. Passer de la banquise à la jungle entre deux portes n'aurait
    // aucun sens.
    expect($demarreur->themeBestiaire(3))->toBe($demarreur->themeBestiaire(3));
});

it('fait venir du THÈME les quelques FORTS, sans toucher à la masse de faibles', function () {
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    // On cherche le groupe dont le thème est la jungle, la boîte la mieux
    // pourvue en créatures de tier `base`.
    $graine = null;
    for ($id = 1; $id <= 10; $id++) {
        if ($demarreur->themeBestiaire($id) === 'jungles_delthrak') {
            $graine = $id;
            break;
        }
    }
    expect($graine)->not->toBeNull();

    $achats = $methode->invoke($demarreur, [], 40, 12, 1, $graine);
    $boites = collect($achats)->pluck('boite');

    // La signature de la boîte est présente…
    expect($boites->contains('jungles_delthrak'))->toBeTrue();

    // …et le fond commun aussi : le thème est une PRÉFÉRENCE sur les forts, pas
    // un filtre sur toute la rencontre. Un donjon entièrement thématique serait
    // impossible pour les boîtes pauvres en créatures de base — celle des
    // glaces n'en a qu'une.
    expect($boites->contains('base'))->toBeTrue();
});

it('facture PLUS CHER une créature éthérée, à tous les paliers', function () {
    // ⚠ Une éthérée ne se blesse à l'arme que sur un bouclier noir (1/6) au lieu
    // d'un crâne (3/6) : mesurée sur le Dread Wraith à 5 dés d'attaque, elle
    // tient SIX fois plus longtemps qu'un bloc identique non éthéré. Son prix
    // doit le dire (René, 2026-09-04), sans quoi le budget de rencontre la paie
    // au tarif d'un monstre ordinaire et lui adjoint une escorte complète.
    $demarreur = app(DemarreurQuete::class);

    $ombre = Monstre::where('nom_base', 'Magrian')->firstOrFail();
    $seigneurSorcier = Monstre::where('nom_base', 'Seigneur Sorcier de Barak Tor')->firstOrFail();

    expect($demarreur->coutEffectif($ombre))
        ->toBe((int) ceil((int) $ombre->cout * DemarreurQuete::RATIO_COUT_ETHERE))
        ->toBeGreaterThan((int) $ombre->cout);

    // …et une créature ordinaire paie son prix affiché.
    expect($demarreur->coutEffectif($seigneurSorcier))->toBe((int) $seigneurSorcier->cout);

    // ⚠ Le SPECTRE aussi : il est éthéré, de tier `base`, et acheté comme sbire
    // ordinaire. Ne majorer que le boss aurait laissé le même défaut un palier
    // plus bas — c'est pour cela que `coutEffectif()` est un point de passage et
    // pas une ligne dans l'achat de la rencontre finale.
    $spectre = Monstre::where('nom_base', 'Spectre')->firstOrFail();
    expect($demarreur->coutEffectif($spectre))->toBeGreaterThan((int) $spectre->cout);
});

it('fait TOURNER la rencontre finale, sans hasard, et selon le THÈME', function () {
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    $gabarit = App\Models\GabaritQuete::where('type_jalon', 'boss_final')->firstOrFail();
    $structure = $gabarit->structure;

    // ⚠ Depuis que le THÈME resserre le pool (2026-09-04), le boss ne varie plus
    // d'un jalon à l'autre DANS une campagne — il varie d'une CAMPAGNE à
    // l'autre, ce qui est le sens même d'un thème : on ne passe pas de la
    // banquise à la jungle entre deux portes.
    $parGroupe = [];
    for ($groupe = 1; $groupe <= 5; $groupe++) {
        $parGroupe[$groupe] = $methode->invoke($demarreur, $structure, 30, 5, 1, $groupe)[0]->nom_base;
    }

    expect(count(array_unique($parGroupe)))->toBeGreaterThan(1);

    // …et il est STABLE : rejouer la même quête doit rendre le même boss. C'est
    // un PLACEMENT, pas une pioche — même raison que `salle_artefact`, qu'une
    // reprise ne re-tire jamais. Sans cela, « Recommencer la quête » deviendrait
    // un bouton pour changer d'adversaire jusqu'au plus commode.
    expect($methode->invoke($demarreur, $structure, 30, 5, 1, 3)[0]->nom_base)->toBe($parGroupe[3]);

    // ⚠ Et quand le thème ne propose AUCUN boss, le pool entier reprend la main
    // et la rotation joue à plein sur la position d'arc — sans quoi une boîte
    // sans boss (la jungle n'en a pas) fermerait toutes ses quêtes sur le même
    // adversaire par accident plutôt que par choix.
    $sansBoss = null;
    for ($id = 1; $id <= 10; $id++) {
        if ($demarreur->themeBestiaire($id) === 'jungles_delthrak') {
            $sansBoss = $id;
            break;
        }
    }
    expect($sansBoss)->not->toBeNull();

    $parPosition = [];
    for ($position = 1; $position <= 6; $position++) {
        $parPosition[] = $methode->invoke($demarreur, $structure, 30, 5, $position, $sansBoss)[0]->nom_base;
    }
    expect(count(array_unique($parPosition)))->toBeGreaterThan(1);
});

it('assigne le lanceur nommé demandé comme rencontre finale (indice de gabarit)', function () {
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    // Gabarit demandant un boss « seigneur_sorcier_barak_tor » → le Seigneur Sorcier de Barak Tor (jeu de base).
    $achats = $methode->invoke(
        $demarreur,
        ['rencontre_finale' => ['tier' => 'boss', 'archetype' => 'seigneur_sorcier_barak_tor']],
        30,
        5,
        1, // positionArc
    );
    expect($achats[0]->nom_base)->toBe('Seigneur Sorcier de Barak Tor');

    // Sans indice d'archétype : le LEADER DE COÛT du palier. On ne fige pas son
    // nom — le bestiaire s'est enrichi des créatures d'extension le 2026-08-10,
    // et le Seigneur ogre (coût 22) a pris la tête ; Qwindrak (coût 42, 2026-10-31)
    // la prend à son tour. Ce que le test garde, c'est la règle : à défaut
    // d'archétype, on prend le boss le plus cher du palier. SANS plafond de budget :
    // `acheterMonstres()` achète la rencontre finale même au-dessus du budget
    // (« toujours présente, même si elle dépasse le budget »). Un plafond à 30 ici
    // ne tenait que parce que le leader valait 27.
    $plusCher = Monstre::where('tier', 'boss')
        ->orderByDesc('cout')->orderBy('id')
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
    // Le Seigneur Sorcier de Barak Tor a sorts_dread = [] mais l'archétype seigneur_sorcier_barak_tor
    // doit lui ouvrir son répertoire : il lance un de ses sorts au tour des monstres.
    $ctx = demarrerQueteAvecMonstre('Seigneur Sorcier de Barak Tor', ['attribut_mind' => 1, 'pv_mind' => 1, 'pv_mind_max' => 1]);

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $sortLance = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'sort_dread');

    $repertoire = config('archetypes_lanceurs.seigneur_sorcier_barak_tor.sorts');

    expect($sortLance)->not->toBeNull()
        ->and($sortLance['sort'])->toBeIn($repertoire);
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
    // Le Sorcier du Dread (sous_boss, Prophecy of Telor) porte l'archétype `sorcier_dread_telor`, dont le répertoire
    // liste Commandement et Nuée d'Effroi — deux sorts de palier boss. `palier` n'avait aucun lecteur : le sous-boss
    // commandait les héros dès la première quête.
    $sorts = repertoireDe('Sorcier du Dread');

    expect(config('archetypes_lanceurs.sorcier_dread_telor.sorts'))->toContain('Commandement', "Nuée d'Effroi")
        ->and($sorts)
        ->toContain('Frayeur')
        ->toContain('Tourmente')
        // Palier `base` : un sous-boss y a droit, c'est le sens du minimum.
        ->toContain('Boule de Flammes')
        // Palier `boss` : refusés au sous-boss.
        ->not->toContain('Commandement')
        ->not->toContain("Nuée d'Effroi");
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
