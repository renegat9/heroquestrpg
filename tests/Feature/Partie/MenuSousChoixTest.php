<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Partie\MenuMoteur;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * UNE ACTION, PUIS UN SOUS-CHOIX (René, 2026-09-01).
 *
 * Le doc de conception fixe une règle fondatrice (doc 13 §3.1) :
 *
 *     « Le menu est l'unité d'interaction. Le joueur ne tape jamais de texte :
 *       il choisit parmi 2 à 5 options claires. »
 *
 * Mesuré en partie réelle le 2026-08-30, le menu d'un magicien niveau 1 portait
 * QUATORZE options, dont NEUF sorts. C'est la même leçon que le ciblage, un
 * cran plus haut : l'option ne doit pas ÊTRE le sort, elle doit PORTER la
 * liste des sorts.
 *
 * ⚠ Et la liste EST la liste blanche. Sans revalidation, un client lancerait
 * un sort de son répertoire AVEC LES CIBLES D'UN AUTRE — hors ligne de vue et
 * hors du typage de cible que le moteur avait calculé pour ce sort-là.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, CompetenceSeeder::class, ConditionSeeder::class]);
});

/** Un magicien réellement armé : la classe seule n'attache aucun sort. */
function magicienArme(): array
{
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);

    foreach (['feu', 'eau', 'terre'] as $element) {
        app(App\Partie\MoteurSorts::class)->attacherElement($ctx['heros'], $element);
    }

    return $ctx;
}

/** Le menu publié pour ce héros, tel que `ChoixController` le relira. */
function menuPublie($ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'];
}

it('tient le plafond du doc 13 : un magicien de niveau 1 a peu d\'options', function () {
    $ctx = magicienArme();

    $options = collect(menuPublie($ctx));

    // ⚠ LA raison d'être du lot. Avant, neuf boutons « Lancer … » à eux seuls.
    expect($options->where('type', 'sort')->count())->toBe(1, 'un seul point d\'entrée pour toute la magie');

    // Le menu entier reste lisible d'un coup d'œil. La borne est large — le
    // doc dit « 2 à 5 » et le moteur ajoute déplacement, fouilles et fin de
    // tour — mais elle attrape toute nouvelle inflation par option-par-chose.
    expect($options->count())->toBeLessThanOrEqual(9, 'menu : '.$options->pluck('id')->implode(', '));

    // Et les neuf sorts sont bien là, dans la LISTE.
    $sorts = collect($options->firstWhere('id', 'lancer_sort')['parametres']['sorts']);
    expect($sorts)->toHaveCount(9)
        ->and($sorts->pluck('cle')->unique())->toHaveCount(9, 'chaque entrée a une clé propre');
});

it('refuse une clé ABSENTE de la liste', function () {
    $ctx = magicienArme();
    menuPublie($ctx);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => 'sort:99999'],
    ])->assertStatus(422);
});

it('refuse une clé SANS clé du tout — l\'option ne se joue pas à vide', function () {
    $ctx = magicienArme();
    menuPublie($ctx);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'lancer_sort'])
        ->assertStatus(422);
});

it('refuse une cible légale POUR UN AUTRE SORT', function () {
    // ⚠ Le cœur du danger. `ciblesLegales()` calcule une liste DIFFÉRENTE par
    // sort : monstres + héros pour un sort de dégâts, héros seuls pour un soin.
    // Si le résolveur lisait les cibles de l'OPTION au lieu de celles de
    // l'entrée, on soignerait un gobelin — ou pire, on frapperait hors de vue.
    $ctx = magicienArme();
    $options = menuPublie($ctx);

    $sorts = collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts']);
    $soin = $sorts->first(fn ($e) => ($e['sort_type'] ?? null) === 'utilitaire' && ! empty($e['cibles']));
    $degats = $sorts->first(fn ($e) => ($e['sort_type'] ?? null) === 'degats' && ! empty($e['cibles']));

    expect($soin)->not->toBeNull()->and($degats)->not->toBeNull();

    // Un monstre est une cible légale du sort de DÉGÂTS, jamais du soin.
    $monstre = collect($degats['cibles'])->firstWhere('type', 'monstre');
    expect($monstre)->not->toBeNull()
        ->and(collect($soin['cibles'])->where('type', 'monstre'))->toBeEmpty();

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => $soin['cle'], 'cible_id' => $monstre['id'], 'cible_type' => 'monstre'],
    ])->assertStatus(422);
});

it('la liste d\'objets ne garde que le GRATUIT quand le héros a agi', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['heros' => $heros, 'etatHeros' => $etat] = $ctx;

    $potion = App\Models\Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => App\Models\Objet::where('nom', 'Potion de soin')->value('id'),
        'emplacement' => 'consommable', 'quantite' => 1,
    ]);

    // Avant d'agir : la potion est là.
    $avant = collect(collect(menuPublie($ctx))->firstWhere('id', 'utiliser_objet')['parametres']['objets'] ?? []);
    expect($avant->pluck('cle'))->toContain("objet:{$potion->id}")
        ->and($avant->firstWhere('cle', "objet:{$potion->id}")['cout'])->toBe('gratuit');

    // ⚠ Après avoir agi, l'option SURVIT — elle est gratuite, et une potion se
    // boit justement après avoir frappé. C'est ce que l'ancien emplacement de
    // l'appel, sous `! $aAgi`, rendait impossible.
    $etat->update(['a_agi' => true]);

    $apres = collect(collect(menuPublie($ctx))->firstWhere('id', 'utiliser_objet')['parametres']['objets'] ?? []);
    expect($apres->pluck('cle'))->toContain("objet:{$potion->id}")
        ->and($apres->pluck('cout')->unique()->all())->toBe(['gratuit'], 'plus rien qui coûte l\'action');
});

it('écarte de la liste la potion réservée à une AUTRE classe', function () {
    // ⚠ On FILTRE, on ne badge pas : proposer un choix que la résolution
    // refusera est pire que ne rien proposer (patron `soinsDisponibles()`).
    // `/moi` continue de badger, lui — porter la potion d'un compagnon reste
    // permis.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);

    $barbare = App\Models\Objet::where('tag_equipement', 'potion_barbare')->firstOrFail();
    $ligne = App\Models\Inventaire::create([
        'personnage_id' => $ctx['heros']->id, 'objet_id' => $barbare->id,
        'emplacement' => 'consommable', 'quantite' => 1,
    ]);

    $objets = collect(collect(menuPublie($ctx))->firstWhere('id', 'utiliser_objet')['parametres']['objets'] ?? []);

    expect($objets->pluck('cle'))->not->toContain("objet:{$ligne->id}");
});

it('le miroir des créneaux de la manette dit la MÊME chose que le moteur', function () {
    // ⚠ `ActionTab.creneauConsomme()` se déclare miroir de
    // `ResolveurTour::creneauOption()`. Il avait dérivé sur DEUX types :
    // `actionner_levier`, devenu payant côté serveur le 2026-08-24, et
    // `objet_libre`, que le front ignorait — il grisait donc l'option gratuite
    // dès que le héros avait agi. Un miroir se prouve, il ne se déclare pas.
    $vue = file_get_contents(base_path('resources/js/components/manette/ActionTab.vue'));
    $bloc = substr($vue, strpos($vue, 'function creneauConsomme'));
    $bloc = substr($bloc, 0, strpos($bloc, "\n}\n"));

    // ⚠ On part du `switch`, pas du début de la fonction : les gardes du haut
    // (`if (!moi) return false;`, `if (moi.a_joue) return true;`) sont elles
    // aussi des `return` et décalaient le découpage d'un cran.
    $bloc = substr($bloc, strpos($bloc, 'switch ('));

    // Le `switch` range les types en TROIS groupes, séparés par leurs `return`.
    // On les lit tels quels plutôt que de deviner : c'est la seule façon de
    // comparer ce que le front fait VRAIMENT.
    $groupes = preg_split('/return [^;]+;/', $bloc);
    $casesDe = function (string $morceau): array {
        preg_match_all("/case '([a-z_]+)':/", $morceau, $m);

        return $m[1];
    };

    // 0 = jusqu'au premier `return` (les types de MOUVEMENT), puis les gratuits,
    // puis les terminants ; le reste tombe sur le `default` = action.
    $mouvement = $casesDe($groupes[0] ?? '');
    $libres = array_merge($casesDe($groupes[1] ?? ''), $casesDe($groupes[2] ?? ''));

    $reflet = new ReflectionMethod(App\Partie\ResolveurTour::class, 'creneauOption');
    $reflet->setAccessible(true);
    $resolveur = app(App\Partie\ResolveurTour::class);

    $types = ['deplacement', 'franchissement', 'ouvrir_porte', 'actionner_levier', 'sortie',
        'retraite', 'style', 'objet_libre', 'objet', 'concentration', 'relever', 'attente',
        'attaque', 'sort', 'parchemin', 'jet', 'poussee'];

    foreach ($types as $type) {
        $serveur = $reflet->invoke($resolveur, $type);
        $manette = in_array($type, $mouvement, true) ? 'mouvement'
            : (in_array($type, $libres, true) ? 'libre' : 'action');

        $attendu = match ($serveur) {
            'mouvement' => 'mouvement',
            'interaction', 'tour' => 'libre',
            default => 'action',
        };

        expect($manette)->toBe($attendu, "« {$type} » : serveur = {$serveur}, manette = {$manette}");
    }
});
