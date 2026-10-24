<?php

declare(strict_types=1);

use App\Engine\MotsClesReactionMj as Mot;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Quete;
use App\Models\Snapshot;
use App\Models\Sort;
use App\Models\SortDread;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\JournalCombat;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Partie\Rayon;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * LE MJ RÉAGIT AU SORT D'UN HÉROS (René, 2026-10-15) — la couture `App\Partie\ReactionsMj`,
 * appelée par `ResolveurTour::lancerSort()`.
 *
 * Quatre cartes y sont branchées : *Dispel* de Gretzl (capacité), *Dispel* et *Mirror Magic*
 * (sorts de Dread de Sinestra), *Break* de Gruzbella (capacité, après l'effet). Deux familles
 * de tests, comme partout dans ce projet :
 *
 *  1. le REGISTRE — `MotsClesReactionMj` et les cartes du catalogue se confrontent DANS LES
 *     DEUX SENS ; rien de déclaré sans lecteur ni sans monstre qui le porte ;
 *  2. EN JEU — le mage lance un VRAI sort par la VRAIE route (`POST /choix`), les dés sont
 *     figés, et chaque assertion échoue sans la couture : le sort partirait, le monstre
 *     encaisserait, rien ne serait annoncé.
 *
 * Dés figés (`desFiges`) : valeurs de d6 consommées DANS L'ORDRE — pour *Dispel* le MJ
 * d'abord, puis le héros, puis (si le sort part) la résistance de la cible.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

// =====================================================================
// Outillage
// =====================================================================

/**
 * Une quête, un MAGE (feu + eau, l'élément `elfique` en plus pour la zone) et UN monstre du
 * catalogue au contact. `$repertoire` isole ce que le monstre sait lancer (il n'a plus
 * d'archétype) — même geste que `forcerSortUnique()` de SortsGlaceTest.
 *
 * @param  array<string, mixed>  $herosAttrs
 * @param  list<string>|null  $repertoire
 * @return array{alice: mixed, groupe: \App\Models\Groupe, mage: \App\Models\Personnage, quete: Quete, instance: InstanceMonstre, etat: EtatPersonnageQuete}
 */
function rmjDemarrer(string $nomMonstre, array $herosAttrs = [], ?array $repertoire = null): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $mage = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien', ...$herosAttrs]);

    foreach (['feu', 'eau'] as $element) {
        app(MoteurSorts::class)->attacherElement($mage, $element);
    }

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $catalogue = Monstre::where('nom_base', $nomMonstre)->firstOrFail();

    if ($repertoire !== null) {
        $catalogue->update(['sorts_dread' => $repertoire, 'archetype_lanceur' => null]);
    }

    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update([
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'etat' => 'actif',
        'elite' => false,
        'habillage' => null,
    ]);
    $instance->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $quete);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    return compact('alice', 'groupe', 'mage', 'quete', 'instance', 'etat');
}

/** Un nouveau créneau d'action pour le mage, puis le menu moteur régénéré (la liste blanche). */
function rmjMenu(array $ctx): void
{
    $ctx['etat']->fresh()->update(['a_agi' => false, 'a_joue' => false]);
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['mage']->id);
}

function rmjSortId(string $nom): int
{
    return (int) Sort::where('nom', $nom)->value('id');
}

/** Le mage lance `$sort` — sur `$cible` s'il en faut une (un sort de zone n'en demande pas). */
function rmjLancer(array $ctx, string $sort, ?InstanceMonstre $cible = null)
{
    rmjMenu($ctx);

    $parametres = ['cle' => 'sort:'.rmjSortId($sort)];

    if ($cible !== null) {
        $parametres += ['cible_id' => $cible->id, 'cible_type' => 'monstre'];
    }

    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'lancer_sort', 'parametres' => $parametres]);
}

/** Rend son sort au mage (un seul créneau d'action par tour : on rejoue le tour à la main). */
function rmjRendreLeSort(array $ctx, string $sort): void
{
    $ctx['mage']->sorts()->updateExistingPivot(rmjSortId($sort), ['disponible' => true]);
}

/** Le fil d'un résultat, d'un seul tenant. */
function rmjFil(array $resultat, string $acteur = 'Aldric'): string
{
    return collect(app(JournalCombat::class)->depuisResultat($resultat, $acteur))->pluck('texte')->implode(' ¦ ');
}

function rmjDisponible(array $ctx, string $sort): bool
{
    return (bool) $ctx['mage']->sorts()->whereKey(rmjSortId($sort))->firstOrFail()->pivot->disponible;
}

/** Les capacités réactives déjà dépensées par l'instance, relues en base. */
function rmjDepensees(InstanceMonstre $instance): array
{
    return (array) $instance->fresh()->capacites_reactives_utilisees;
}

// =====================================================================
// 1. LE REGISTRE — dans les deux sens
// =====================================================================

it('ne laisse entrer dans `reactions_defense` que des mécaniques DÉCLARÉES, et déclare ce que quelqu\'un porte', function () {
    $portees = Monstre::all()
        ->flatMap(fn (Monstre $m) => (array) data_get($m->capacites, 'reactions_defense', []))
        ->unique()->values()->all();

    // Sens 1 : rien de porté sans déclaration.
    $inconnues = array_diff($portees, array_keys(Mot::CAPACITES));
    expect($inconnues)->toBe([], 'Mécanique(s) réactive(s) hors vocabulaire : '.implode(', ', $inconnues));

    // Sens 2 : rien de déclaré sans monstre qui le porte — la clé décorative.
    $inutilisees = array_diff(array_keys(Mot::CAPACITES), $portees);
    expect($inutilisees)->toBe([], 'Mécanique(s) déclarée(s) sans aucun monstre : '.implode(', ', $inutilisees));
});

it('chaque lecteur déclaré existe ET nomme sa clé dans son propre fichier', function () {
    foreach (Mot::CAPACITES as $cle => $meta) {
        [$classe, $methode] = explode('::', $meta['lecteur']);

        expect(method_exists($classe, $methode))->toBeTrue("{$cle} : {$meta['lecteur']} introuvable.");
        expect(str_contains((string) file_get_contents((string) (new ReflectionClass($classe))->getFileName()), $cle))
            ->toBeTrue("{$cle} : {$classe} est déclaré lecteur mais ne nomme jamais la clé.");
        expect($meta['libelle'] ?? '')->not->toBeEmpty("{$cle} : libellé manquant.");
    }
});

it('ne garde AUCUNE dette : Deflect et Consume Magic sont portés (EXT-MAGRIAN, 2026-10-23), et aucune donnée n\'emploie une clé non portée', function () {
    // Vide par décision : le registre reste là pour une réaction nommée par un livret et non portée (voir le docblock).
    expect(Mot::NON_IMPLEMENTEES)->toBe([]);

    foreach (Mot::NON_IMPLEMENTEES as $cle => $raison) {
        expect($raison)->not->toBeEmpty("{$cle} : la dette n'est pas écrite.");

        foreach (Monstre::all() as $monstre) {
            expect(in_array($cle, (array) data_get($monstre->capacites, 'reactions_defense', []), true))
                ->toBeFalse("{$monstre->nom_base} porte « {$cle} », que le moteur ne sait pas jouer.");
            expect(array_key_exists($cle, (array) $monstre->capacites))
                ->toBeFalse("{$monstre->nom_base} porte « {$cle} », que le moteur ne sait pas jouer.");
        }
    }

    // Magrian est semée en entier (chantier EXT-MAGRIAN, 2026-10-23) : chacune de ses réactions est DÉCLARÉE dans le
    // registre (le sens « déclaré ⊇ porté » est déjà couvert ci-dessus). Sa fiche complète est figée dans BestiaireSourceTest.
    $magrian = Monstre::where('nom_base', 'Magrian')->firstOrFail();

    expect(array_diff((array) data_get($magrian->capacites, 'reactions_defense', []), array_keys(Mot::CAPACITES)))->toBe([]);
});

it('porte Dispel dans les TROIS phases de Gretzl et Break dans les TROIS de Gruzbella', function () {
    foreach (['Gretzl la Porte-Fléau', 'Demonspider', 'Demonape'] as $phase) {
        expect((array) data_get(Monstre::where('nom_base', $phase)->firstOrFail()->capacites, 'reactions_defense'))
            ->toContain(Mot::ANNULE_SORT_CIBLANT, Mot::IGNORE_DEGATS_ATTAQUE);
    }

    foreach (['Gruzbella Hammerhand', 'Gruzbella Déterminée', 'Gruzbella Imprudente'] as $phase) {
        expect((array) data_get(Monstre::where('nom_base', $phase)->firstOrFail()->capacites, 'reactions_defense'))
            ->toContain(Mot::ROMPT_SORT_ACTIF, Mot::IGNORE_DEGATS_ATTAQUE);
    }
});

it('seede Dissipation et Magie Miroir en sorts de RÉACTION, jamais choisis comme action du tour', function () {
    foreach (['Dissipation' => 'sort_de_heros', 'Magie Miroir' => 'sort_visant_le_lanceur'] as $nom => $declencheur) {
        $sort = SortDread::where('nom', $nom)->firstOrFail();

        expect($sort->type)->toBe('reaction')
            ->and(data_get($sort->effet, 'reaction'))->toBe($declencheur);
    }

    // Sinestra les connaît toutes les deux (livret p. 33), à côté de ses cinq sorts d'action.
    expect(config('archetypes_lanceurs.archimage_elfe.sorts'))->toContain('Dissipation', 'Magie Miroir');

    // ⚠ `sortUtilisable()` les refuse : un tour de Sinestra n'en joue jamais une comme action.
    $ctx = rmjDemarrer('Archimage elfe');
    $ctx['instance']->update(['pv_body' => 4]);

    for ($tour = 1; $tour <= 4; $tour++) {
        desFiges(array_fill(0, 200, 4));
        $instance = $ctx['instance']->fresh()->load('monstre');
        $instance->update(['usages_dread' => 3]);

        $action = app(MoteurDread::class)->jouerTourDread(
            $ctx['groupe'], $ctx['quete']->fresh(), $instance->fresh()->load('monstre'),
            new Collection([$ctx['etat']->fresh()]),
        );

        $sorts = ($action['type'] ?? null) === 'actions_composites'
            ? collect($action['actions'])->pluck('sort')
            : collect([$action['sort'] ?? null]);

        expect($sorts->intersect(['Dissipation', 'Magie Miroir'])->all())->toBe([]);
    }
});

// =====================================================================
// 2. DISPEL DE GRETZL — la capacité : annule le sort qui la prend pour cible
// =====================================================================

it('Gretzl annule le sort qui la prend pour cible — lancé donc épuisé, sans effet, une seule fois', function () {
    $ctx = rmjDemarrer('Gretzl la Porte-Fléau');
    $gretzl = $ctx['instance'];

    desFiges(array_fill(0, 30, 1));

    $resultat = rmjLancer($ctx, 'Boule de Feu', $gretzl)->assertStatus(202)->json('resultat');

    // La DÉCISION est publiée, jamais redérivée.
    expect($resultat['sort_annule'])->toBeTrue()
        ->and($resultat['cible'])->toMatchArray(['type' => 'monstre', 'instance_id' => $gretzl->id])
        ->and($resultat['reactions_mj'])->toHaveCount(1)
        ->and($resultat['reactions_mj'][0])->toMatchArray([
            'issue' => 'annule',
            'mecanique' => Mot::ANNULE_SORT_CIBLANT,
            'carte' => null,
        ])
        ->and($resultat['reactions_mj'][0]['par']['instance_id'])->toBe($gretzl->id)
        ->and($resultat)->not->toHaveKey('degats');

    // Le sort a bien été LANCÉ : épuisé pour la quête (« immediately after the hero casts »),
    // mais Gretzl n'a pas perdu un point de Body — ni brûlé, ni touché.
    expect(rmjDisponible($ctx, 'Boule de Feu'))->toBeFalse()
        ->and((int) $gretzl->fresh()->pv_body)->toBe(5)
        ->and((bool) $gretzl->fresh()->brule)->toBeFalse()
        // La capacité est dépensée POUR LA RENCONTRE — en colonne, pas en cache.
        ->and(rmjDepensees($gretzl))->toBe([Mot::ANNULE_SORT_CIBLANT]);

    // « may use each ability once » : le sort SUIVANT part. Trait de Feu : 1 dégât fixe, la
    // cible lance 1 d6 brut (le dé figé à 1 n'annule rien).
    $suivant = rmjLancer($ctx, 'Trait de Feu', $gretzl)->assertStatus(202)->json('resultat');

    expect($suivant)->not->toHaveKey('sort_annule')
        ->and($suivant)->not->toHaveKey('reactions_mj')
        ->and($suivant['degats'])->toBe(1)
        ->and((int) $gretzl->fresh()->pv_body)->toBe(4);
});

it('Gretzl laisse passer un sort qui ne la vise pas : la capacité reste entière', function () {
    $ctx = rmjDemarrer('Gretzl la Porte-Fléau');
    $gobelin = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 1, 'etat' => 'actif', 'revele' => true,
    ]);
    $contact = caseAdjacenteLibre($ctx['quete'], (int) $ctx['etat']->position_x, (int) $ctx['etat']->position_y);
    $gobelin->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    desFiges(array_fill(0, 30, 1));

    $resultat = rmjLancer($ctx, 'Boule de Feu', $gobelin)->assertStatus(202)->json('resultat');

    expect($resultat)->not->toHaveKey('reactions_mj')
        ->and($resultat['degats'])->toBe(2)
        ->and(rmjDepensees($ctx['instance']))->toBe([]);
});

it('Gretzl annule aussi un sort de ZONE qui la balaie, en entier', function () {
    $ctx = rmjDemarrer('Gretzl la Porte-Fléau');
    $ctx['mage']->sorts()->attach(rmjSortId('Flamme hypnotique'), ['disponible' => true]);

    desFiges(array_fill(0, 30, 6)); // un 6 passerait n'importe quel Mind : le sort, lui, ne part pas

    $resultat = rmjLancer($ctx, 'Flamme hypnotique')->assertStatus(202)->json('resultat');

    expect($resultat['sort_annule'])->toBeTrue()
        ->and($resultat)->not->toHaveKey('touches')
        ->and($resultat['reactions_mj'][0]['mecanique'])->toBe(Mot::ANNULE_SORT_CIBLANT)
        ->and(app(MoteurSorts::class)->monstreA($ctx['instance']->fresh(), MoteurSorts::MONSTRE_PARALYSE))->toBeFalse();
});

it('Gretzl annule un parchemin d\'Éclair qui la traverse — le parchemin est consommé quand même', function () {
    $ctx = rmjDemarrer('Gretzl la Porte-Fléau');
    $quete = $ctx['quete'];
    $hx = (int) $ctx['etat']->position_x;
    $hy = (int) $ctx['etat']->position_y;

    $ligne = null;

    foreach (Rayon::DIRECTIONS as $code => [$dx, $dy]) {
        if (caseQueteLibre($quete, $hx + $dx, $hy + $dy) && caseQueteLibre($quete, $hx + 2 * $dx, $hy + 2 * $dy)) {
            $ligne = ['code' => $code, 'dx' => $dx, 'dy' => $dy];
            break;
        }
    }

    expect($ligne)->not->toBeNull();

    $ctx['instance']->update(['position_x' => $hx + 2 * $ligne['dx'], 'position_y' => $hy + 2 * $ligne['dy']]);

    $parchemin = Inventaire::create([
        'personnage_id' => $ctx['mage']->id,
        'objet_id' => Objet::where('nom', 'Parchemin : Éclair')->firstOrFail()->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['mage']->id);
    $options = (array) data_get(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id)), 'menu.options', []);
    $entree = collect(collect($options)->firstWhere('id', 'lire_parchemin')['parametres']['parchemins'] ?? [])
        ->firstWhere('direction', $ligne['code']);

    expect($entree)->not->toBeNull();

    desFiges(array_fill(0, 30, 4));

    $resultat = test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin', 'parametres' => ['cle' => $entree['cle']],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['sort_annule'])->toBeTrue()
        ->and($resultat['reactions_mj'][0]['mecanique'])->toBe(Mot::ANNULE_SORT_CIBLANT)
        ->and($resultat)->not->toHaveKey('touches')
        ->and((int) $ctx['instance']->fresh()->pv_body)->toBe(5)
        // « Consommé dans TOUS les cas » (S1) : un sort annulé est un sort lancé.
        ->and(Inventaire::whereKey($parchemin->id)->exists())->toBeFalse();
});

it('un lancer que la route REFUSE ne dépense aucune capacité du MJ', function () {
    $ctx = rmjDemarrer('Gretzl la Porte-Fléau');
    $gretzl = $ctx['instance'];

    // Le menu a été généré avec Gretzl au contact… puis elle recule hors de vue : le
    // garde-fou de ligne de vue refuse le lancer. La couture est APRÈS cette validation.
    rmjMenu($ctx);

    $grille = FabriqueGrille::pour($ctx['quete']->fresh(), exceptInstanceId: $gretzl->id);
    $hors = null;

    foreach ($ctx['quete']->carte->grille['cases'] as $y => $rangee) {
        foreach ($rangee as $x => $case) {
            if (in_array($case, ['s', 'p'], true) && caseQueteLibre($ctx['quete'], $x, $y)
                && ! $grille->ligneDeVue((int) $ctx['etat']->position_x, (int) $ctx['etat']->position_y, $x, $y)) {
                $hors = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }

    expect($hors)->not->toBeNull('aucune case hors de vue sur cette carte');
    $gretzl->update(['position_x' => $hors['x'], 'position_y' => $hors['y']]);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.rmjSortId('Boule de Feu'), 'cible_id' => $gretzl->id, 'cible_type' => 'monstre'],
    ])->assertStatus(422);

    expect(rmjDepensees($gretzl))->toBe([])
        ->and(rmjDisponible($ctx, 'Boule de Feu'))->toBeTrue();
});

// =====================================================================
// 3. DISPEL — le sort de Dread : Mind + 1 dé rouge de chaque côté
// =====================================================================

it('Dissipation annule le sort quand le total du MJ est PLUS HAUT (le MJ lance d\'abord)', function () {
    $ctx = rmjDemarrer('Archimage elfe', repertoire: ['Dissipation']);
    $sinestra = $ctx['instance'];
    expect((int) $sinestra->usages_dread)->toBe(3);

    // MJ : Mind 9 + 3 = 12. Héros (Mind 2) : 2 + 4 = 6.
    desFiges([3, 4, ...array_fill(0, 30, 1)]);

    $resultat = rmjLancer($ctx, 'Boule de Feu', $sinestra)->assertStatus(202)->json('resultat');

    expect($resultat['sort_annule'])->toBeTrue()
        ->and($resultat['reactions_mj'][0])->toMatchArray(['issue' => 'annule', 'mecanique' => 'dissipe', 'carte' => 'Dissipation'])
        ->and($resultat['reactions_mj'][0]['jet']['lanceur'])->toMatchArray(['mind' => 9, 'de' => 3, 'total' => 12])
        ->and($resultat['reactions_mj'][0]['jet']['heros'])->toMatchArray(['mind' => 2, 'de' => 4, 'total' => 6])
        ->and((int) $sinestra->fresh()->pv_body)->toBe(4)
        ->and(rmjDisponible($ctx, 'Boule de Feu'))->toBeFalse()
        // Un sort de Dread coûte UN usage — et la carte n'est jouée qu'une fois par rencontre.
        ->and((int) $sinestra->fresh()->usages_dread)->toBe(2)
        ->and(rmjDepensees($sinestra))->toBe(['dissipe']);
});

it('Dissipation ÉCHOUE à l\'égalité (« higher ») : le sort part, la carte est dépensée', function () {
    $ctx = rmjDemarrer('Archimage elfe', ['attribut_mind' => 9, 'pv_mind' => 9, 'pv_mind_max' => 9], repertoire: ['Dissipation']);
    $sinestra = $ctx['instance'];

    // MJ : 9 + 4 = 13. Héros : 9 + 4 = 13 — égalité, le héros l'emporte. Puis Boule de Feu :
    // la cible lance 2 d6 bruts (1, 1) : aucune réduction → 2 points.
    desFiges([4, 4, 1, 1, ...array_fill(0, 30, 1)]);

    $resultat = rmjLancer($ctx, 'Boule de Feu', $sinestra)->assertStatus(202)->json('resultat');

    expect($resultat)->not->toHaveKey('sort_annule')
        ->and($resultat['reactions_mj'][0]['issue'])->toBe('resiste')
        ->and($resultat['reactions_mj'][0]['jet']['lanceur']['total'])->toBe(13)
        ->and($resultat['reactions_mj'][0]['jet']['heros']['total'])->toBe(13)
        ->and($resultat['degats'])->toBe(2)
        ->and((int) $sinestra->fresh()->pv_body)->toBe(2)
        ->and((int) $sinestra->fresh()->usages_dread)->toBe(2);

    // Une fois par rencontre : même une dissipation ratée a joué la carte.
    desFiges(array_fill(0, 30, 1));
    rmjRendreLeSort($ctx, 'Boule de Feu');
    $encore = rmjLancer($ctx, 'Boule de Feu', $sinestra)->assertStatus(202)->json('resultat');

    expect($encore)->not->toHaveKey('reactions_mj');
});

it('un lanceur qui n\'est PAS visé dissipe ce qu\'il voit — et seulement s\'il peut lancer un sort', function () {
    $ctx = rmjDemarrer('Archimage elfe', repertoire: ['Dissipation']);
    $sinestra = $ctx['instance'];

    $gobelin = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 1, 'etat' => 'actif', 'revele' => true,
    ]);
    $contact = caseAdjacenteLibre($ctx['quete'], (int) $ctx['etat']->position_x, (int) $ctx['etat']->position_y);
    $gobelin->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    // Endormie : une dormeuse ne lance rien.
    app(MoteurSorts::class)->poserConditionMonstre($sinestra, MoteurSorts::MONSTRE_ENDORMI);
    desFiges(array_fill(0, 30, 1));
    $dort = rmjLancer($ctx, 'Boule de Feu', $gobelin)->assertStatus(202)->json('resultat');
    expect($dort)->not->toHaveKey('reactions_mj')
        ->and((int) $sinestra->fresh()->usages_dread)->toBe(3);

    // Réveillée, elle voit le héros : le sort visait un GOBELIN, c'est elle qui le dissipe.
    app(MoteurSorts::class)->retirerConditionMonstre($sinestra->fresh(), MoteurSorts::MONSTRE_ENDORMI);
    rmjRendreLeSort($ctx, 'Boule de Feu');
    desFiges([6, 1, ...array_fill(0, 30, 1)]);
    $voit = rmjLancer($ctx, 'Boule de Feu', $gobelin)->assertStatus(202)->json('resultat');

    expect($voit['sort_annule'])->toBeTrue()
        ->and($voit['reactions_mj'][0]['par']['instance_id'])->toBe($sinestra->id)
        // Le premier Boule de Feu (Sinestra endormie) a coûté 2 PV au gobelin ; le second, dissipé, rien.
        ->and((int) $gobelin->fresh()->pv_body)->toBe(3);
});

it('un lanceur qui ne VOIT PAS le héros ne dissipe rien', function () {
    $ctx = rmjDemarrer('Archimage elfe', repertoire: ['Dissipation']);
    $sinestra = $ctx['instance'];

    $gobelin = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 1, 'etat' => 'actif', 'revele' => true,
    ]);
    $contact = caseAdjacenteLibre($ctx['quete'], (int) $ctx['etat']->position_x, (int) $ctx['etat']->position_y);
    $gobelin->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    // Une case de la carte d'où l'on ne voit pas le héros.
    $grille = FabriqueGrille::pour($ctx['quete']->fresh(), exceptInstanceId: $sinestra->id);
    $hors = null;

    foreach ($ctx['quete']->carte->grille['cases'] as $y => $rangee) {
        foreach ($rangee as $x => $case) {
            if (in_array($case, ['s', 'p'], true) && caseQueteLibre($ctx['quete'], $x, $y)
                && ! $grille->ligneDeVue((int) $ctx['etat']->position_x, (int) $ctx['etat']->position_y, $x, $y)) {
                $hors = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }

    expect($hors)->not->toBeNull();
    $sinestra->update(['position_x' => $hors['x'], 'position_y' => $hors['y']]);

    desFiges(array_fill(0, 30, 1));
    $resultat = rmjLancer($ctx, 'Boule de Feu', $gobelin)->assertStatus(202)->json('resultat');

    expect($resultat)->not->toHaveKey('reactions_mj')
        ->and((int) $sinestra->fresh()->usages_dread)->toBe(3)
        ->and((int) $gobelin->fresh()->pv_body)->toBe(3);
});

it('Dissipation ne gaspille pas la carte sur un sort qui ne vise AUCUN monstre (un soin, un soutien)', function () {
    $ctx = rmjDemarrer('Archimage elfe', repertoire: ['Dissipation']);
    $ctx['mage']->update(['pv_body' => 5]);

    rmjMenu($ctx);
    desFiges(array_fill(0, 30, 1));

    $resultat = test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:'.rmjSortId('Eau de Guérison'), 'cible_id' => $ctx['mage']->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)->json('resultat');

    expect($resultat)->not->toHaveKey('reactions_mj')
        ->and($resultat['soin'])->toBeGreaterThan(0)
        ->and((int) $ctx['instance']->fresh()->usages_dread)->toBe(3);
});

// =====================================================================
// 4. MIRROR MAGIC — le sort retombe sur son lanceur
// =====================================================================

it('Magie Miroir renvoie Boule de Feu sur le mage : il subit l\'effet destiné au lanceur du MJ', function () {
    $ctx = rmjDemarrer('Archimage elfe', repertoire: ['Magie Miroir']);
    $sinestra = $ctx['instance'];
    $pvAvant = (int) $ctx['mage']->fresh()->pv_body;

    // La résistance du MAGE à son propre sort : 2 d6 bruts (1, 1) — aucune réduction.
    desFiges(array_fill(0, 30, 1));

    $resultat = rmjLancer($ctx, 'Boule de Feu', $sinestra)->assertStatus(202)->json('resultat');

    expect($resultat['sort_renvoye'])->toBeTrue()
        ->and($resultat)->not->toHaveKey('sort_annule')
        ->and($resultat['reactions_mj'][0])->toMatchArray(['issue' => 'renvoie', 'mecanique' => 'renvoie', 'carte' => 'Magie Miroir'])
        // L'effet est celui du sort SUR LE HÉROS (la même branche que tout tir sur un compagnon).
        ->and($resultat['cible'])->toMatchArray(['type' => 'heros', 'personnage_id' => $ctx['mage']->id])
        ->and($resultat['degats'])->toBe(2)
        ->and((int) $ctx['mage']->fresh()->pv_body)->toBe($pvAvant - 2)
        // …et Sinestra n'a rien subi.
        ->and((int) $sinestra->fresh()->pv_body)->toBe(4)
        ->and(rmjDisponible($ctx, 'Boule de Feu'))->toBeFalse()
        ->and((int) $sinestra->fresh()->usages_dread)->toBe(2)
        ->and(rmjDepensees($sinestra))->toBe(['renvoie']);

    // Une fois par rencontre (« each spell once per quest ») : le sort suivant l'atteint.
    rmjRendreLeSort($ctx, 'Trait de Feu');
    $suivant = rmjLancer($ctx, 'Trait de Feu', $sinestra)->assertStatus(202)->json('resultat');

    expect($suivant)->not->toHaveKey('sort_renvoye')
        ->and((int) $sinestra->fresh()->pv_body)->toBe(3);
});

it('Magie Miroir renvoie aussi un sort MENTAL : le mage s\'endort de son propre Sommeil', function () {
    $ctx = rmjDemarrer('Archimage elfe', repertoire: ['Magie Miroir']);

    desFiges(array_fill(0, 30, 4));

    $resultat = rmjLancer($ctx, 'Sommeil', $ctx['instance'])->assertStatus(202)->json('resultat');

    expect($resultat['sort_renvoye'])->toBeTrue()
        ->and($resultat['cible']['type'])->toBe('heros')
        ->and($resultat['condition'])->toBe('Endormi')
        ->and($ctx['mage']->fresh()->conditions()->where('nom', 'Endormi')->exists())->toBeTrue()
        ->and(app(MoteurSorts::class)->monstreA($ctx['instance']->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeFalse();
});

it('Magie Miroir ne vaut que pour un sort lancé SUR le lanceur : une zone qui le balaie passe', function () {
    $ctx = rmjDemarrer('Archimage elfe', repertoire: ['Magie Miroir']);
    $ctx['mage']->sorts()->attach(rmjSortId('Flamme hypnotique'), ['disponible' => true]);

    desFiges(array_fill(0, 30, 1));

    $resultat = rmjLancer($ctx, 'Flamme hypnotique')->assertStatus(202)->json('resultat');

    expect($resultat)->not->toHaveKey('sort_renvoye')
        ->and($resultat)->not->toHaveKey('reactions_mj')
        ->and((int) $ctx['instance']->fresh()->usages_dread)->toBe(3);
});

it('ORDRE : Sinestra, qui connaît les deux cartes, RENVOIE le sort qui la vise plutôt que de le dissiper', function () {
    $ctx = rmjDemarrer('Archimage elfe'); // son vrai répertoire (archétype `archimage_elfe`)
    $sinestra = $ctx['instance'];

    desFiges(array_fill(0, 30, 1));

    $resultat = rmjLancer($ctx, 'Boule de Feu', $sinestra)->assertStatus(202)->json('resultat');

    expect($resultat['sort_renvoye'])->toBeTrue()
        ->and(collect($resultat['reactions_mj'])->pluck('mecanique')->all())->toBe(['renvoie']);

    // Le sort suivant : Magie Miroir est jouée, c'est Dissipation qui prend le relais.
    rmjRendreLeSort($ctx, 'Trait de Feu');
    desFiges([6, 1, ...array_fill(0, 30, 1)]);
    $suivant = rmjLancer($ctx, 'Trait de Feu', $sinestra)->assertStatus(202)->json('resultat');

    expect($suivant['sort_annule'])->toBeTrue()
        ->and($suivant['reactions_mj'][0]['mecanique'])->toBe('dissipe')
        // Deux cartes jouées : deux usages sur trois.
        ->and((int) $sinestra->fresh()->usages_dread)->toBe(1);
});

// =====================================================================
// 5. BREAK DE GRUZBELLA — défait le sort APRÈS l'effet
// =====================================================================

it('Gruzbella met fin au Sommeil qui vient de la prendre : l\'effet est levé, la capacité dépensée', function () {
    $ctx = rmjDemarrer('Gruzbella Hammerhand');
    $gruzbella = $ctx['instance'];

    // Sommeil PREND toujours ; la rupture immédiate roule 1 d6 par point de Mind (4) — aucun 6.
    desFiges(array_fill(0, 30, 4));

    $resultat = rmjLancer($ctx, 'Sommeil', $gruzbella)->assertStatus(202)->json('resultat');

    expect($resultat)->not->toHaveKey('sort_annule')
        ->and($resultat['reactions_mj'])->toHaveCount(1)
        ->and($resultat['reactions_mj'][0])->toMatchArray(['issue' => 'rompu', 'mecanique' => Mot::ROMPT_SORT_ACTIF, 'carte' => null])
        ->and($resultat['reactions_mj'][0]['conditions_levees'])->toBe([['cle' => 'endormi', 'libelle' => 'endormi']])
        // LA VÉRITÉ du payload : la condition n'est plus en vigueur une fois le sort lancé.
        ->and($resultat['effet_applique'])->toBeFalse()
        ->and($resultat['rompu_par_mj'])->toBeTrue()
        ->and(app(MoteurSorts::class)->monstreA($gruzbella->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeFalse()
        ->and(rmjDepensees($gruzbella))->toBe([Mot::ROMPT_SORT_ACTIF]);

    // Une seule fois : le Sommeil suivant tient.
    rmjRendreLeSort($ctx, 'Sommeil');
    $suivant = rmjLancer($ctx, 'Sommeil', $gruzbella)->assertStatus(202)->json('resultat');

    expect($suivant)->not->toHaveKey('reactions_mj')
        ->and($suivant['effet_applique'])->toBeTrue()
        ->and(app(MoteurSorts::class)->monstreA($gruzbella->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeTrue();
});

it('Gruzbella ne dépense PAS Break quand le sort ne prend pas (rien à rompre)', function () {
    $ctx = rmjDemarrer('Gruzbella Hammerhand');
    $gruzbella = $ctx['instance'];

    // Sommeil prend, puis la rupture IMMÉDIATE roule un 6 : le monstre est déjà réveillé.
    desFiges([6, ...array_fill(0, 30, 4)]);

    $resultat = rmjLancer($ctx, 'Sommeil', $gruzbella)->assertStatus(202)->json('resultat');

    expect($resultat)->not->toHaveKey('reactions_mj')
        ->and($resultat['rupture_immediate'])->toBeTrue()
        ->and(rmjDepensees($gruzbella))->toBe([]);
});

it('Break ne touche pas ce qui était déjà posé : seul l\'effet de CE sort est levé', function () {
    $ctx = rmjDemarrer('Gruzbella Hammerhand');
    $gruzbella = $ctx['instance'];
    app(MoteurSorts::class)->poserConditionMonstre($gruzbella, MoteurSorts::MONSTRE_RALENTI, 3);

    desFiges(array_fill(0, 30, 4));
    $resultat = rmjLancer($ctx, 'Sommeil', $gruzbella)->assertStatus(202)->json('resultat');

    expect($resultat['reactions_mj'][0]['conditions_levees'])->toBe([['cle' => 'endormi', 'libelle' => 'endormi']])
        ->and(app(MoteurSorts::class)->monstreA($gruzbella->fresh(), MoteurSorts::MONSTRE_RALENTI))->toBeTrue();
});

// =====================================================================
// 6. ANNONCE — le fil, le rejeu, la table : un effet automatique que rien n'annonce est injouable
// =====================================================================

it('le fil, le rejeu et la table disent la capacité qui annule le sort', function () {
    $ctx = rmjDemarrer('Gretzl la Porte-Fléau');
    $ctx['instance']->update(['habillage' => ['nom' => 'Gretzl']]);

    desFiges(array_fill(0, 30, 1));
    $resultat = rmjLancer($ctx, 'Boule de Feu', $ctx['instance'])->assertStatus(202)->json('resultat');

    $fil = rmjFil($resultat);

    expect($fil)->toContain('Aldric lance Boule de Feu sur Gretzl')
        ->and($fil)->toContain('Gretzl annule Boule de Feu')
        ->and($fil)->toContain('capacité à usage unique');

    // Le JOUEUR ARRIVÉ EN RETARD lit le même fil, relu du journal.
    $rejoue = collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['journal_combat'])->pluck('texte')->implode(' ¦ ');
    expect($rejoue)->toContain('Gretzl annule Boule de Feu');

    // La TABLE : la scène du sort dit qu'il est annulé, une scène de réaction dit par qui.
    $scenes = app(SceneDeTable::class)->depuisResultat($resultat, $ctx['mage']->fresh());
    $genres = collect($scenes)->pluck('genre')->all();

    expect($genres)->toBe(['sort', 'reaction'])
        ->and($scenes[0]['issue']['libelle'])->toBe('Boule de Feu est annulé')
        ->and($scenes[1]['titre'])->toBe('Défense réactive')
        ->and($scenes[1]['issue']['libelle'])->toContain('Gretzl annule Boule de Feu')
        ->and($scenes[1]['genre'])->toBeIn(SceneDeTable::GENRES);
});

it('le fil dit les DEUX totaux de Dissipation, et le renvoi de Magie Miroir', function () {
    $ctx = rmjDemarrer('Archimage elfe', repertoire: ['Dissipation']);
    $ctx['instance']->update(['habillage' => ['nom' => 'Sinestra']]);

    desFiges([3, 4, ...array_fill(0, 30, 1)]);
    $annule = rmjLancer($ctx, 'Boule de Feu', $ctx['instance'])->assertStatus(202)->json('resultat');

    expect(rmjFil($annule))
        ->toContain('Sinestra — Dissipation (sans action) : Mind 9 + dé 3 = 12 contre Mind 2 + dé 4 = 6 — Boule de Feu est annulé');

    // Une dissipation ratée se lit aussi, AVANT l'effet qui suit.
    rmjRendreLeSort($ctx, 'Trait de Feu');
    $ctx['instance']->monstre->update(['sorts_dread' => ['Dissipation']]);
    $ctx['instance']->fresh()->update(['capacites_reactives_utilisees' => [], 'usages_dread' => 3]);
    $ctx['mage']->update(['pv_mind' => 9, 'attribut_mind' => 9, 'pv_mind_max' => 9]);
    desFiges([1, 6, 1, ...array_fill(0, 30, 1)]);
    $resiste = rmjLancer($ctx, 'Trait de Feu', $ctx['instance'])->assertStatus(202)->json('resultat');

    $fil = rmjFil($resiste);
    expect($fil)->toContain('Mind 9 + dé 1 = 10 contre Mind 9 + dé 6 = 15 — le sort tient')
        ->and(strpos($fil, 'le sort tient'))->toBeLessThan(strpos($fil, 'Aldric lance Trait de Feu'));
});

it('le fil dit ce que Magie Miroir fait au MAGE, pas « Aldric lance Boule de Feu sur Aldric »', function () {
    $ctx = rmjDemarrer('Archimage elfe', repertoire: ['Magie Miroir']);
    $ctx['instance']->update(['habillage' => ['nom' => 'Sinestra']]);

    desFiges(array_fill(0, 30, 1));
    $resultat = rmjLancer($ctx, 'Boule de Feu', $ctx['instance'])->assertStatus(202)->json('resultat');
    $fil = rmjFil($resultat);

    expect($fil)->toContain('Aldric lance Boule de Feu sur Sinestra')
        ->and($fil)->toContain('Sinestra — Magie Miroir (sans action) : Boule de Feu est renvoyé sur son lanceur !')
        ->and($fil)->toContain('Aldric subit son propre Boule de Feu (−2 PV)')
        ->and($fil)->not->toContain('sur Aldric');

    $scenes = app(SceneDeTable::class)->depuisResultat($resultat, $ctx['mage']->fresh());

    expect(collect($scenes)->pluck('genre')->all())->toBe(['sort', 'reaction'])
        // Un seul portrait pour le mage : le sort retombe sur son lanceur.
        ->and(collect($scenes[0]['acteurs'])->pluck('role')->all())->toBe(['acteur'])
        ->and($scenes[1]['titre'])->toBe('Magie Miroir');
});

it('le fil dit Break APRÈS l\'effet du sort : « s\'endort… », puis la capacité', function () {
    $ctx = rmjDemarrer('Gruzbella Hammerhand');
    $ctx['instance']->update(['habillage' => ['nom' => 'Gruzbella']]);

    desFiges(array_fill(0, 30, 4));
    $resultat = rmjLancer($ctx, 'Sommeil', $ctx['instance'])->assertStatus(202)->json('resultat');
    $fil = rmjFil($resultat);

    expect($fil)->toContain('Gruzbella subit Sommeil (Endormi)…')
        ->and($fil)->toContain('Gruzbella met fin au sort : endormi')
        ->and(strpos($fil, 'subit Sommeil'))->toBeLessThan(strpos($fil, 'met fin au sort'));
});

// =====================================================================
// 7. DURABLE — le compteur est en colonne, et monte dans le snapshot
// =====================================================================

it('la reprise ne rend PAS sa capacité réactive au monstre qui l\'a déjà jouée', function () {
    $ctx = rmjDemarrer('Gretzl la Porte-Fléau');
    $gretzl = $ctx['instance'];

    desFiges(array_fill(0, 30, 1));
    rmjLancer($ctx, 'Boule de Feu', $gretzl)->assertStatus(202);
    expect(rmjDepensees($gretzl))->toBe([Mot::ANNULE_SORT_CIBLANT]);

    // Le compteur manquait au snapshot : une reprise RENDAIT à Gretzl sa Dispel (et sa
    // Résilience, avant elle) — GRATUITEMENT, sans une erreur nulle part.
    app(Sauvegarde::class)->snapshotter($ctx['groupe']->fresh(), 'nouveau_tour');
    $gretzl->update(['capacites_reactives_utilisees' => []]);

    $snapshot = Snapshot::where('groupe_id', $ctx['groupe']->id)->orderByDesc('id')->firstOrFail();
    app(Sauvegarde::class)->restaurer($ctx['groupe']->fresh(), $snapshot);

    expect(rmjDepensees($gretzl))->toBe([Mot::ANNULE_SORT_CIBLANT]);
});
