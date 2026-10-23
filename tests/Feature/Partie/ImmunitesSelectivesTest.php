<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\JournalCombat;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
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
use Illuminate\Support\Facades\Http;

/*
 * LES IMMUNITÉS SÉLECTIVES (*Return of the Witch Lord*, chantier 2026-10-23, décision Q5 : les TROIS formes ensemble).
 *
 *  - (a) `immunite_sorts_sauf` — Kessandria : « immune to all spells except fire spells » ;
 *  - (b) `vulnerable_uniquement_a` — le Seigneur Sorcier : « can only be harmed by four things » ;
 *  - (c) `invulnerable_casse_arme` — les statues d'orcs : invulnérables, l'arme qui les frappe se brise,
 *        sauf la Lame des Esprits. NON SEMÉES (`MotsClesAdversaires::SANS_DONNEE`) : la forme est testée ici
 *        sur une figure de TEST, jamais seedée.
 *
 * Chaque forme est éprouvée EN JEU : le menu n'offre pas le sort refusé, le coup se joue et ne fait rien,
 * l'arme brisée quitte l'inventaire, et tout cela est DIT. Les cas-preuves échouent sans le correctif.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    // ⚠ SortSeeder AVANT ObjetSeeder : celui-ci dérive un parchemin par sort.
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/**
 * Une quête minimale : UNE salle 16×9 tout en sol, un héros en (1, 4).
 *
 * @param  list<array{x: int, y: int}>  $murs
 * @return array{alice: mixed, groupe: Groupe, quete: Quete, albrecht: Personnage}
 */
function wlScene(array $herosAttrs = [], array $murs = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — Return of the Witch Lord',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $cases = array_fill(0, 9, array_fill(0, 16, 's'));
    foreach ($murs as $m) {
        $cases[$m['y']][$m['x']] = 'm';
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 16, 'hauteur' => 9,
                'theme' => 'generique', 'mediane_x' => 8, 'mediane_y' => 4]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => [],
            'spawn_heros' => [['x' => 1, 'y' => 4]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $albrecht->id, 'position_x' => 1, 'position_y' => 4,
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'albrecht' => $albrecht];
}

/** Pose une figure du catalogue (ou un fixture), révélée, ses usages de Dread armés comme en jeu. */
function wlPoser(array $ctx, string $nom, int $x, int $y): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nom)->firstOrFail();

    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => $x, 'position_y' => $y, 'etat' => 'actif', 'revele' => true,
    ]);
    $instance->load('monstre');
    app(\App\Partie\MoteurDread::class)->reinitialiserUsagesInstance($instance, $ctx['quete']);

    return $instance->fresh()->load('monstre');
}

/** Un héros mage, avec les écoles données (un sort de chaque école est alors offert au menu). */
function wlMage(array $ecoles): array
{
    $ctx = wlScene(['classe' => 'magicien']);

    foreach ($ecoles as $ecole) {
        app(MoteurSorts::class)->attacherElement($ctx['albrecht'], $ecole);
    }

    return $ctx;
}

/** Les noms des cibles que le MENU offre au héros pour le sort `$nomSort`. */
function wlCibles(array $ctx, string $nomSort): array
{
    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['albrecht']->fresh());
    $entree = collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])
        ->first(fn ($e) => $e['nom'] === $nomSort);

    return $entree === null ? [] : array_map(fn ($c) => $c['nom'], $entree['cibles'] ?? []);
}

/**
 * Le héros frappe la figure par le résolveur — le SEUL point de passage des coups — dés figés à crâne
 * (chaque dé d'attaque porte, aucun bouclier ne protège la figure).
 *
 * @return array<string, mixed>
 */
function wlFrappe(array $ctx, InstanceMonstre $cible): array
{
    desFiges(array_fill(0, 80, 1));

    $etat = $ctx['quete']->etatsPersonnages()->where('personnage_id', $ctx['albrecht']->id)->firstOrFail();

    return app(ResolveurTour::class)->frapper(
        $ctx['groupe'], $ctx['quete']->fresh()->load('carte'), $etat,
        $ctx['albrecht']->fresh(), $cible->fresh()->load('monstre'),
    );
}

/** Le texte que le fil de combat donne à un coup. */
function wlDit(array $payload): string
{
    return implode(' ¦ ', array_column((new JournalCombat)->depuisEvenements([[$payload, 'Albrecht']]), 'texte'));
}

function wlEquiper(array $ctx, string $nomObjet): Inventaire
{
    return $ctx['albrecht']->inventaire()->create([
        'objet_id' => Objet::where('nom', $nomObjet)->value('id'), 'emplacement' => 'arme_principale',
    ]);
}

// =====================================================================
// (a) — L'IMMUNITÉ PAR FAMILLE : Kessandria, « all spells except fire »
// =====================================================================

it('(a) SORTS — Kessandria est immunisée à tout sort SAUF le feu : le menu ne lui offre pas Génie (air), et offre Boule de Feu', function () {
    $ctx = wlMage(['feu', 'air']);
    wlPoser($ctx, 'Kessandria', 3, 4);

    expect(wlCibles($ctx, 'Génie'))->not->toContain('Kessandria')
        ->and(wlCibles($ctx, 'Boule de Feu'))->toContain('Kessandria');
});

it('(a) L\'échelle, lue à son point de passage unique : totale → famille → sources', function () {
    $moteur = app(MoteurSorts::class);
    $feu = Sort::where('nom', 'Boule de Feu')->firstOrFail();   // élément feu, dégâts
    $eau = Sort::where('nom', 'Sommeil')->firstOrFail();        // élément eau, mental
    $air = Sort::where('nom', 'Génie')->firstOrFail();          // élément air, dégâts

    expect($moteur->sortRefuseSur(['totale' => true, 'sauf' => null, 'sources' => null], $feu))->toBeTrue()
        ->and($moteur->sortRefuseSur(['totale' => false, 'sauf' => ['feu'], 'sources' => null], $feu))->toBeFalse()
        ->and($moteur->sortRefuseSur(['totale' => false, 'sauf' => ['feu'], 'sources' => null], $eau))->toBeTrue()
        // (b) : la liste de sources ne vise que les sorts de DÉGÂTS — un sommeil n'est pas une « source de dégâts ».
        ->and($moteur->sortRefuseSur(['totale' => false, 'sauf' => null, 'sources' => ['Boule de Feu']], $feu))->toBeFalse()
        ->and($moteur->sortRefuseSur(['totale' => false, 'sauf' => null, 'sources' => ['Boule de Feu']], $air))->toBeTrue()
        ->and($moteur->sortRefuseSur(['totale' => false, 'sauf' => null, 'sources' => ['Boule de Feu']], $eau))->toBeFalse();
});

// =====================================================================
// (b) — LA LISTE BLANCHE DE SOURCES : le Seigneur Sorcier
// =====================================================================

it('(b) SORTS — le Seigneur Sorcier ne se vise que par ses sources : Génie est refusé, Boule de Feu et Trait de Feu passent', function () {
    $ctx = wlMage(['feu', 'air']);
    wlPoser($ctx, 'Seigneur Sorcier', 3, 4);

    expect(wlCibles($ctx, 'Génie'))->not->toContain('Seigneur Sorcier')
        ->and(wlCibles($ctx, 'Boule de Feu'))->toContain('Seigneur Sorcier')
        ->and(wlCibles($ctx, 'Trait de Feu'))->toContain('Seigneur Sorcier');
});

it('(b) SORTS — un sort qui ne BLESSE pas (Sommeil) n\'est pas arrêté par la liste de sources : le livret ne le vise pas', function () {
    $ctx = wlMage(['feu', 'air', 'eau']);
    wlPoser($ctx, 'Seigneur Sorcier', 3, 4);

    expect(wlCibles($ctx, 'Sommeil'))->toContain('Seigneur Sorcier');
});

it('(b) COUP — une arme hors liste (Épée longue) se joue, ne fait AUCUN dégât, et le dit « sans effet »', function () {
    $ctx = wlScene();
    wlEquiper($ctx, 'Épée longue');
    $seigneur = wlPoser($ctx, 'Seigneur Sorcier', 2, 4);
    $pvAvant = (int) $seigneur->pv_body;

    $payload = wlFrappe($ctx, $seigneur);

    expect($payload['sans_effet'])->toBe('source')
        ->and($payload['degats'])->toBe(0)
        ->and($payload['arme_brisee'])->toBeNull()
        ->and((int) $seigneur->fresh()->pv_body)->toBe($pvAvant)
        ->and(wlDit($payload))->toContain('cette arme ne le blesse pas');
});

it('(b) COUP — la Lame des Esprits, dans la liste, le blesse pour de bon : aucun sans-effet', function () {
    $ctx = wlScene();
    wlEquiper($ctx, 'Lame des Esprits');
    $seigneur = wlPoser($ctx, 'Seigneur Sorcier', 2, 4);
    $pvAvant = (int) $seigneur->pv_body;

    $payload = wlFrappe($ctx, $seigneur);

    expect($payload['sans_effet'])->toBeNull()
        ->and((int) $payload['degats'])->toBeGreaterThan(0)
        ->and((int) $seigneur->fresh()->pv_body)->toBeLessThan($pvAvant);
});

it('(b) COUP — mains nues : rien dans la liste, donc aucun dégât, dit', function () {
    $ctx = wlScene();
    $seigneur = wlPoser($ctx, 'Seigneur Sorcier', 2, 4);

    $payload = wlFrappe($ctx, $seigneur);

    expect($payload['sans_effet'])->toBe('source')->and($payload['degats'])->toBe(0);
});

// =====================================================================
// (c) — INVULNÉRABLE ET BRISE L'ARME : la forme des statues (non semée)
// =====================================================================

/** Une figure de TEST à la façon des statues d'orcs — jamais seedée (`MotsClesAdversaires::SANS_DONNEE`). */
function wlStatueDeTest(): void
{
    Monstre::create([
        'nom_base' => 'Statue de test', 'deplacement' => 0, 'attaque' => 0, 'defense' => 6,
        'pv_body' => 2, 'pv_mind' => 0, 'tier' => 'sous_boss', 'boite' => 'witch_lord', 'cout' => 0,
        'capacites' => ['immunite_sorts', 'invulnerable_casse_arme' => ['Lame des Esprits']],
        'sorts_dread' => [],
    ]);
}

it('(c) STATUE — invulnérable : aucun dégât, l\'arme qui la frappe SE BRISE et quitte l\'inventaire, dit', function () {
    wlStatueDeTest();
    $ctx = wlScene();
    $epee = wlEquiper($ctx, 'Épée longue');
    $statue = wlPoser($ctx, 'Statue de test', 2, 4);

    $payload = wlFrappe($ctx, $statue);

    expect($payload['sans_effet'])->toBe('invulnerable')
        ->and($payload['degats'])->toBe(0)
        ->and($payload['arme_brisee'])->toBe('Épée longue')
        ->and(Inventaire::whereKey($epee->id)->exists())->toBeFalse()
        ->and($ctx['albrecht']->inventaire()->where('emplacement', 'arme_principale')->exists())->toBeFalse()
        ->and(wlDit($payload))->toContain('Épée longue se brise');
});

it('(c) STATUE — la Lame des Esprits (exemptée) ne se brise pas, et ne blesse pas non plus', function () {
    wlStatueDeTest();
    $ctx = wlScene();
    $lame = wlEquiper($ctx, 'Lame des Esprits');
    $statue = wlPoser($ctx, 'Statue de test', 2, 4);

    $payload = wlFrappe($ctx, $statue);

    expect($payload['sans_effet'])->toBe('invulnerable')
        ->and($payload['degats'])->toBe(0)
        ->and($payload['arme_brisee'])->toBeNull()
        ->and(Inventaire::whereKey($lame->id)->exists())->toBeTrue()
        ->and(wlDit($payload))->not->toContain('se brise')
        ->and(wlDit($payload))->toContain('sans l\'entamer');
});

it('(c) la statue n\'est semée nulle part : la forme existe, la donnée non (et c\'est écrit)', function () {
    $porteuses = Monstre::all()->filter(fn (Monstre $m) => array_key_exists('invulnerable_casse_arme', (array) $m->capacites)
        || in_array('invulnerable_casse_arme', (array) $m->capacites, true));

    expect($porteuses)->toHaveCount(0);
});

// =====================================================================
// LE REGISTRE — chaque clé nouvelle a bien un lecteur et une donnée (ou une absence écrite)
// =====================================================================

it('(a)(b) le Seigneur et Kessandria portent leurs listes EXACTEMENT comme le livret les écrit', function () {
    $cap = fn (string $nom) => (array) Monstre::where('nom_base', $nom)->value('capacites');

    expect($cap('Seigneur Sorcier')['vulnerable_uniquement_a'])
        ->toBe(['Lame des Esprits', 'Trait de Feu', 'Boule de Feu', 'Dague de jet magique'])
        ->and($cap('Kessandria')['immunite_sorts_sauf'])->toBe(['feu']);
});
