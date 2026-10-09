<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\EtatGroupe;
use App\Partie\MenuMoteur;
use App\Partie\MoteurReactions;
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
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * PARCHEMINS DE WIZARDS OF MORCAR (2026-10-08) — deux défauts signalés en partie,
 * et la vérification des neuf parchemins de la vague 1 :
 *
 *  1. Le parchemin de Mur de Pierre ne se résolvait pas : son entrée de menu n'avait
 *     ni `mode` ni `cases`. Il passe désormais par les MÊMES paires que le sort, et
 *     par le même point de passage (`ResolveurTour::poserMurMagiqueSort()`).
 *  2. Le parchemin de Vision du futur n'était jamais proposé. C'est une RÉACTION : le
 *     héros qui PORTE le parchemin reçoit la relance exactement comme celui qui
 *     connaît le sort, et le parchemin quitte le sac s'il relance (jamais s'il refuse).
 *
 * Tous les tests passent par le résolveur PUBLIC (`resoudre()`, ce que `POST /choix`
 * appelle) et, pour les deux défauts, par les vraies routes. Les fonctions sont
 * préfixées `pm_` : ce fichier ne dépend d'aucune fonction d'un autre fichier de test.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : celui-ci dérive un parchemin par sort.
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, SortSeeder::class,
        ObjetSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/** Les neuf sorts de héros de Wizards of Morcar dont le parchemin doit se jouer. */
const PM_NEUF_PARCHEMINS = [
    'Mur de Pierre', 'Invisibilité', 'Désapprentissage', 'Trésor convoité', 'Clairvoyance',
    'Chaînes des Ténèbres', 'Flèches de la Nuit', 'Vision du futur', "Voile d'ombre",
];

/**
 * Quête minimale (sol partout), un magicien LANCEUR en (3,3). Par défaut UNE salle
 * 7×7. `$salles` permet la Clairvoyance (deux salles, la corridor de la colonne 3).
 *
 * @param  list<array<string, mixed>>|null  $salles
 * @return array{alice: App\Auth\JoueurAuthentifiable, groupe: Groupe, heros: Personnage, quete: Quete, etatHeros: EtatPersonnageQuete}
 */
function pm_quete(?array $salles = null): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Lyra', 1, ['classe' => 'magicien']);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test', 'position_arc' => 1, 'type_jalon' => 'normale',
        'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 7, 'hauteur' => 7,
        'grille' => [
            'largeur' => 7, 'hauteur' => 7,
            'cases' => array_fill(0, 7, array_fill(0, 7, 's')),
            'salles' => $salles ?? [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'epreuves' => [], 'mobilier' => [],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    // Le d6 du tour est TOMBÉ à l'ouverture du tour (comme `VisionDuFuturTest`) : un
    // menu régénéré après une attaque ne doit pas relancer un dé que rien n'a demandé.
    $etat = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id, 'position_x' => 3, 'position_y' => 3,
        'deplacement_tour' => 5,
        'detail_deplacement_tour' => ['base' => 4, 'des' => [1], 'de_annule' => false, 'de_annule_par' => null, 'sans_menace' => false],
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete->fresh()->load('carte'), 'etatHeros' => $etat];
}

/** Donne au héros l'exemplaire au sac du parchemin de `$nomSort`. */
function pm_donner(array $ctx, string $nomSort): Inventaire
{
    return $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', "Parchemin : {$nomSort}")->firstOrFail()->id,
        'quantite' => 1,
        'emplacement' => 'consommable',
    ]);
}

/** Le menu que le moteur publie maintenant au héros (sorts + parchemins). */
function pm_menu(array $ctx): array
{
    return app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh());
}

/** L'option « Lire un parchemin », ou null si le menu n'en propose aucun. */
function pm_lire(array $ctx): ?array
{
    return collect(pm_menu($ctx))->firstWhere('id', 'lire_parchemin');
}

/** Les entrées de « Lire un parchemin » qui portent CET exemplaire du sac. */
function pm_entrees(array $ctx, Inventaire $ligne): array
{
    return collect(pm_lire($ctx)['parametres']['parchemins'] ?? [])
        ->where('inventaire_id', $ligne->id)
        ->values()
        ->all();
}

/**
 * Joue une entrée de « Lire un parchemin » par le résolveur PUBLIC — la même
 * entrée que `POST /choix`. La première cible légale de l'entrée, s'il y en a une.
 *
 * @param  array<string, mixed>  $entree
 */
function pm_jouer(array $ctx, array $entree): array
{
    $cible = $entree['cibles'][0] ?? null;

    return app(ResolveurTour::class)->resoudre(
        $ctx['groupe']->fresh(),
        $ctx['heros']->fresh(),
        pm_lire($ctx),
        ['cle' => $entree['cle'], ...($cible !== null ? ['cible_id' => $cible['id'], 'cible_type' => $cible['type']] : [])],
    );
}

/** Un gobelin révélé, au contact du héros (4,3) par défaut : une cible d'attaque. */
function pm_gobelin(array $ctx, int $x = 4, int $y = 3): InstanceMonstre
{
    return InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 10, 'pv_body_max' => 10, 'pv_mind' => 2,
        'position_x' => $x, 'position_y' => $y, 'etat' => 'actif', 'revele' => true,
    ]);
}

/** Un monstre dont l'archétype porte un répertoire de sorts : un vrai Sorcier de Dread. */
function pm_sorcier(array $ctx, int $x, int $y): InstanceMonstre
{
    $monstre = Monstre::whereNotNull('archetype_lanceur')->get()
        ->first(fn (Monstre $m) => ! empty(config("archetypes_lanceurs.{$m->archetype_lanceur}.sorts")))
        ?? throw new RuntimeException('Aucun Sorcier de Dread au catalogue de test.');

    return InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => $monstre->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => $x, 'position_y' => $y, 'etat' => 'actif', 'revele' => true,
    ]);
}

/** L'option « Attaquer » à mains nues contre `$instance`, telle que le menu la publie. */
function pm_optionAttaquer(InstanceMonstre $instance): array
{
    return [
        'id' => 'attaquer', 'libelle' => 'Attaquer', 'type' => 'attaque', 'lancer' => false,
        'parametres' => ['arme' => 'arme_principale', 'cibles' => [[
            'id' => $instance->id, 'type' => 'monstre', 'nom' => 'Gobelin', 'nom_base' => 'Gobelin', 'distance' => false,
        ]]],
    ];
}

function pm_frapper(array $ctx, InstanceMonstre $instance): array
{
    return app(ResolveurTour::class)->resoudre(
        $ctx['groupe']->fresh(), $ctx['heros']->fresh(), pm_optionAttaquer($instance), ['cible_id' => $instance->id],
    );
}

/** Le menu d'un tour d'ouverture : le d6 de déplacement tombe, sa relance éventuelle est posée. */
function pm_ouvrirTour(array $ctx): array
{
    return (new ReflectionMethod(MenuMoteur::class, 'generer'))
        ->invoke(app(MenuMoteur::class), $ctx['groupe']->fresh(), $ctx['heros']->fresh());
}

/** Un tour de monstre contre le héros (phase des monstres), pour la relance de DÉFENSE. */
function pm_tourDuMonstre(array $ctx, InstanceMonstre $instance): array
{
    $cibles = $ctx['quete']->fresh()->etatsPersonnages()->with('personnage')->get();

    return (new ReflectionMethod(ResolveurTour::class, 'jouerMonstre'))->invoke(
        app(ResolveurTour::class), $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $instance, $cibles,
    );
}

function pm_offre(array $ctx): ?array
{
    return $ctx['etatHeros']->fresh()->reaction_en_attente;
}

/** Le parchemin est-il encore au sac ? */
function pm_auSac(Inventaire $ligne): bool
{
    return Inventaire::whereKey($ligne->id)->exists();
}

// =====================================================================
// LE REGISTRE — les neuf parchemins existent, et chacun a son objet
// =====================================================================

it('les neuf parchemins de Morcar sont au catalogue, un par sort, et portent le bon sort', function () {
    foreach (PM_NEUF_PARCHEMINS as $nom) {
        $sort = Sort::where('nom', $nom)->first();

        expect($sort)->not->toBeNull("Le sort « {$nom} » n'existe pas au catalogue.");

        $parchemin = Objet::where('nom', "Parchemin : {$nom}")->first();

        expect($parchemin)->not->toBeNull("Aucun parchemin « {$nom} » au catalogue.")
            ->and((int) data_get($parchemin->effet, 'sort_id'))->toBe($sort->id);
    }
});

// =====================================================================
// LA VÉRIFICATION DES NEUF — chacun est proposé quelque part et joue son effet
// =====================================================================

/**
 * Pour chacun des neuf : le parchemin au sac est PROPOSÉ (une entrée de « Lire un
 * parchemin », ou, pour Vision du futur, la source de la relance que le résolveur lit),
 * puis JOUÉ par le résolveur public, et consommé. Un parchemin qu'aucun chemin ne
 * propose fait échouer ce test, nommé.
 */
it('chacun des neuf parchemins de Wizards of Morcar est proposé quelque part, et joue son effet', function (string $nomSort) {
    // Deux salles : la salle 0 est connue, la salle 1 ne l'est pas (Clairvoyance).
    // Un Sorcier de Dread révélé en (1,3), dans la salle connue, en ligne de vue.
    $ctx = pm_quete([
        ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 7, 'theme' => 'generique', 'mediane_x' => 1, 'mediane_y' => 3],
        ['x' => 4, 'y' => 0, 'largeur' => 3, 'hauteur' => 7, 'theme' => 'generique', 'mediane_x' => 5, 'mediane_y' => 3],
    ]);
    $ctx['quete']->update([
        'salles_decouvertes' => [0],
        'deck_fouille' => [['issue' => 'tresor', 'or' => 40], ['issue' => 'piege', 'piege' => 'Fosse'],
            ['issue' => 'tresor', 'or' => 25], ['issue' => 'rien']],
    ]);
    pm_sorcier($ctx, 1, 3);
    $ligne = pm_donner($ctx, $nomSort);

    if ($nomSort === 'Vision du futur') {
        // Pas un menu : c'est la RÉACTION qui le lit au sac, à chaque jet.
        $source = app(MoteurReactions::class)->sourceVisionDuFutur($ctx['heros']->fresh());

        expect($source)->not->toBeNull("« {$nomSort} » n'est proposé nulle part : aucune relance ne le lit au sac.")
            ->and($source['inventaire_id'])->toBe($ligne->id);

        return;
    }

    $entrees = pm_entrees($ctx, $ligne);

    expect($entrees)->not->toBeEmpty("« {$nomSort} » n'est proposé nulle part : aucune entrée de « Lire un parchemin » ne le porte.");

    $payload = pm_jouer($ctx, $entrees[0]);

    expect($payload['type'])->toBe('parchemin')
        ->and($payload['sort']['nom'])->toBe($nomSort)
        ->and($payload['consomme'])->toBeTrue()
        ->and(pm_auSac($ligne))->toBeFalse();

    pm_effetAttendu($nomSort, $payload);
})->with(PM_NEUF_PARCHEMINS);

/** L'effet propre à chaque parchemin — ce que la carte promet, lu dans le payload. */
function pm_effetAttendu(string $nomSort, array $payload): void
{
    match ($nomSort) {
        'Mur de Pierre' => expect($payload['mode'] ?? null)->toBe('pose_mur_magique')
            ->and($payload['mobilier']['nom'] ?? null)->toBe('Mur de Pierre'),
        'Invisibilité' => expect($payload['condition'] ?? null)->toBe('Caché'),
        'Désapprentissage' => expect($payload['mode'] ?? null)->toBe('oubli_sort')
            ->and($payload['sort_oublie'] ?? null)->toBeString()->not->toBeEmpty(),
        'Trésor convoité' => expect($payload['tresor_convoite'] ?? null)->toBeTrue(),
        'Clairvoyance' => expect($payload['mode'] ?? null)->toBe('vision_salle')
            ->and($payload['salle'] ?? null)->toBe(1),
        'Chaînes des Ténèbres' => expect($payload['sort']['type'] ?? null)->toBe('mental'),
        'Flèches de la Nuit' => expect($payload['sort']['type'] ?? null)->toBe('degats'),
        "Voile d'ombre" => expect($payload['mode'] ?? null)->toBe('pose_ombre'),
        default => throw new RuntimeException("Pas d'effet attendu pour « {$nomSort} »."),
    };
}

// =====================================================================
// DÉFAUT 1 — Mur de Pierre : le parchemin pose la paire CHOISIE, comme le sort
// =====================================================================

it('le parchemin de Mur de Pierre offre les MÊMES paires que le sort, et pose la paire choisie', function () {
    $ctx = pm_quete();
    $ligne = pm_donner($ctx, 'Mur de Pierre');

    $entrees = pm_entrees($ctx, $ligne);

    // Quatre voisines libres du lanceur en (3,3), trois suites chacune : douze paires.
    expect($entrees)->toHaveCount(12)
        ->and(collect($entrees)->pluck('mode')->unique()->all())->toBe(['pose_mur_magique'])
        ->and(collect($entrees)->pluck('cle')->every(fn (string $cle) => str_starts_with($cle, "parchemin:{$ligne->id}:mur:")))->toBeTrue();

    $entree = collect($entrees)->firstWhere('cases', [['x' => 4, 'y' => 3], ['x' => 4, 'y' => 4]]);
    expect($entree)->not->toBeNull('La paire (4,3)-(4,4) n\'est pas offerte par le parchemin.');

    $payload = pm_jouer($ctx, $entree);

    expect($payload['type'])->toBe('parchemin')
        ->and($payload['mode'])->toBe('pose_mur_magique')
        ->and($payload['cases'])->toBe([['x' => 4, 'y' => 3], ['x' => 4, 'y' => 4]])
        ->and($payload['mobilier']['nom'])->toBe('Mur de Pierre')
        ->and($payload['mobilier']['pv_body'])->toBe(1)
        ->and($payload['mobilier']['defense_dice'])->toBe(6)
        ->and($payload['consomme'])->toBeTrue()
        ->and($payload['gaspille'])->toBeFalse();

    // Le mur est posé dans la carte (UNE entrée, deux cases), et le parchemin est parti.
    $murId = Mobilier::where('nom', 'Mur de Pierre')->value('id');
    $murs = collect($ctx['quete']->fresh()->load('carte')->carte->grille['mobilier'])->where('mobilier_id', $murId);

    expect($murs)->toHaveCount(1)
        ->and($murs->first()['l'] * $murs->first()['h'])->toBe(2)
        ->and(pm_auSac($ligne))->toBeFalse();

    // Une seule paire par parchemin : le menu ne propose plus de mur, il n'en reste pas.
    expect(pm_lire($ctx))->toBeNull();
});

it('par les vraies routes : /choix pose le mur que le menu publie pour le parchemin', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    ['heros' => $heros, 'alice' => $alice, 'groupe' => $groupe] = $ctx;

    $ligne = $heros->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Parchemin : Mur de Pierre')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'consommable',
    ]);

    // Ouverture du tour : le d6 tombe. Aucun parchemin de Mur ne relance quoi que ce soit.
    EtatPersonnageQuete::where('personnage_id', $heros->id)->update(['deplacement_tour' => null, 'detail_deplacement_tour' => null]);
    desFiges(array_fill(0, 300, 3));
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);

    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'];
    $entree = collect(collect($menu)->firstWhere('id', 'lire_parchemin')['parametres']['parchemins'] ?? [])
        ->firstWhere('mode', 'pose_mur_magique');

    expect($entree)->not->toBeNull('Le menu publié ne propose aucune paire pour le parchemin de Mur de Pierre.');

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => $entree['cle']],
    ])->assertAccepted();

    $murId = Mobilier::where('nom', 'Mur de Pierre')->value('id');

    expect(Inventaire::whereKey($ligne->id)->exists())->toBeFalse()
        ->and(collect(Quete::find($ctx['quete']->id)->carte->grille['mobilier'])->where('mobilier_id', $murId))->toHaveCount(1);
});

// =====================================================================
// CLAIRVOYANCE — le parchemin montre la salle choisie, sans lever le brouillard
// =====================================================================

it('le parchemin de Clairvoyance offre les salles ignorées, et ne montre que celle qu\'on choisit', function () {
    $ctx = pm_quete([
        ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 7, 'theme' => 'generique', 'mediane_x' => 1, 'mediane_y' => 3],
        ['x' => 4, 'y' => 0, 'largeur' => 3, 'hauteur' => 7, 'theme' => 'generique', 'mediane_x' => 5, 'mediane_y' => 3],
    ]);
    $ctx['quete']->update(['salles_decouvertes' => [0]]);

    // Un monstre CACHÉ dans la salle inconnue, un autre dans la salle connue.
    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 2,
        'position_x' => 5, 'position_y' => 2, 'etat' => 'actif', 'revele' => false,
    ]);
    $ligne = pm_donner($ctx, 'Clairvoyance');

    // Salle 0 déjà vue : jamais offerte. Salle 1 : à deux cases à l'EST.
    $entrees = pm_entrees($ctx, $ligne);
    expect($entrees)->toHaveCount(1)
        ->and($entrees[0]['salle'])->toBe(1)
        ->and($entrees[0]['cle'])->toBe("parchemin:{$ligne->id}:salle:1")
        ->and($entrees[0]['nom'])->toContain('au est, à 2 cases');

    $payload = pm_jouer($ctx, $entrees[0]);

    // Le monstre CACHÉ de la salle 1 est bien dans la salle choisie — et SEULEMENT lui.
    expect($payload['mode'])->toBe('vision_salle')
        ->and($payload['salle'])->toBe(1)
        ->and($payload['vide'])->toBeFalse()
        ->and($payload['monstres'])->toHaveCount(1)
        ->and($payload['texte'])->toContain('1 monstre');

    // Information, pas exploration : le groupe ne voit toujours que la salle 0.
    expect($ctx['quete']->fresh()->sallesDecouvertes())->toBe([0])
        ->and(pm_auSac($ligne))->toBeFalse();
});

// =====================================================================
// DÉFAUT 2 — Vision du futur : une RÉACTION, lue au sac par le porteur
// =====================================================================

it('le porteur du parchemin est proposé la relance d\'ATTAQUE : suspendue, puis ACCEPTER retire le parchemin du sac', function () {
    $ctx = pm_quete();
    $gobelin = pm_gobelin($ctx);
    $ligne = pm_donner($ctx, 'Vision du futur');

    // Le sort n'est PAS connu : seul le parchemin porte la relance.
    expect($ctx['heros']->fresh()->sorts()->where('nom', 'Vision du futur')->exists())->toBeFalse();

    // Trois boucliers noirs : un jet raté, défense du gobelin sans effet.
    desFiges([6, 6, 6, 4]);
    $resultat = pm_frapper($ctx, $gobelin);

    expect($resultat['type'])->toBe(ResolveurTour::TYPE_JET_EN_ATTENTE)
        ->and($resultat['jet'])->toBe('attaque')
        ->and((int) $gobelin->fresh()->pv_body)->toBe(10);

    // L'offre vit en base, nommée comme la source (un parchemin), et le parchemin est encore là.
    $offre = pm_offre($ctx);
    expect($offre['action'])->toBe('relance_jet')
        ->and($offre['parchemin'])->toBeTrue()
        ->and($offre['nom'])->toBe('parchemin de Vision du futur')
        ->and($offre['inventaire_id'])->toBe($ligne->id)
        ->and(pm_auSac($ligne))->toBeTrue();

    // La table ne publie JAMAIS l'identifiant de la ligne du sac.
    $publie = collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['entites'])->firstWhere('nom', 'Lyra');
    expect($publie['reaction_en_attente']['parchemin'])->toBeTrue()
        ->and($publie['reaction_en_attente'])->not->toHaveKey('inventaire_id')
        ->and($publie['reaction_en_attente'])->not->toHaveKey('reprise');

    // Accepter : trois crânes, le gobelin encaisse, et le parchemin quitte le sac.
    desFiges([1, 1, 1]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect($reponse['active'])->toBeTrue()
        ->and($reponse['resultat']['faces_attaque'])->toBe(['crane', 'crane', 'crane'])
        ->and((int) $gobelin->fresh()->pv_body)->toBe(7)
        ->and(pm_auSac($ligne))->toBeFalse()
        ->and(pm_offre($ctx))->toBeNull();

    // Le sort n'a jamais été connu : aucun sort du grimoire n'a été touché.
    expect($ctx['heros']->fresh()->sorts()->count())->toBe(0);
});

it('REFUSER la relance du parchemin garde le jet d\'origine, et le parchemin reste au sac', function () {
    $ctx = pm_quete();
    $gobelin = pm_gobelin($ctx);
    $ligne = pm_donner($ctx, 'Vision du futur');

    desFiges([6, 6, 6, 4]);
    pm_frapper($ctx, $gobelin);

    desFiges([]); // rien de neuf ne doit être lancé : l'attaque reprend avec le jet vu
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), false);

    expect($reponse['active'])->toBeFalse()
        ->and($reponse['resultat']['faces_attaque'])->toBe(['bouclier_noir', 'bouclier_noir', 'bouclier_noir'])
        ->and((int) $gobelin->fresh()->pv_body)->toBe(10)
        ->and(pm_auSac($ligne))->toBeTrue();
});

it('la fenêtre écoulée REPREND l\'attaque du porteur avec son jet, et le parchemin reste au sac', function () {
    $ctx = pm_quete();
    $gobelin = pm_gobelin($ctx);
    $ligne = pm_donner($ctx, 'Vision du futur');

    desFiges([1, 1, 1, 4]);
    pm_frapper($ctx, $gobelin);

    $offre = pm_offre($ctx);
    $offre['expire_a'] = now()->subSecond()->toIso8601String();
    $ctx['etatHeros']->update(['reaction_en_attente' => $offre]);

    desFiges([]);
    expect(app(MoteurReactions::class)->rattraperExpiration($ctx['groupe']->fresh()))->toBeTrue();

    expect((int) $gobelin->fresh()->pv_body)->toBe(7)   // le jet d'origine s'est appliqué
        ->and(pm_offre($ctx))->toBeNull()
        ->and(pm_auSac($ligne))->toBeTrue();              // refus par défaut : le parchemin reste
});

it('si le parchemin a disparu entre l\'offre et la réponse, la relance est REFUSÉE — jamais une relance gratuite', function () {
    $ctx = pm_quete();
    $gobelin = pm_gobelin($ctx);
    $ligne = pm_donner($ctx, 'Vision du futur');

    desFiges([6, 6, 6, 4]);
    pm_frapper($ctx, $gobelin);

    $ligne->delete();

    desFiges([]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect($reponse['active'])->toBeFalse()
        ->and($reponse['resultat']['faces_attaque'])->toBe(['bouclier_noir', 'bouclier_noir', 'bouclier_noir'])
        ->and((int) $gobelin->fresh()->pv_body)->toBe(10);
});

it('le GRIMOIRE passe avant le sac : un héros qui connaît le sort ET porte le parchemin dépense le sort, pas le parchemin', function () {
    $ctx = pm_quete();
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'detection');
    $gobelin = pm_gobelin($ctx);
    $ligne = pm_donner($ctx, 'Vision du futur');

    desFiges([6, 6, 6, 4]);
    pm_frapper($ctx, $gobelin);

    $offre = pm_offre($ctx);
    expect($offre['parchemin'])->toBeFalse()
        ->and($offre['inventaire_id'] ?? null)->toBeNull();

    desFiges([1, 1, 1]);
    app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect(pm_auSac($ligne))->toBeTrue()
        ->and((bool) $ctx['heros']->fresh()->sorts()->where('nom', 'Vision du futur')->first()?->pivot->disponible)->toBeFalse();
});

it('le porteur du parchemin est proposé la relance du d6 de DÉPLACEMENT, et ACCEPTER le retire du sac', function () {
    $ctx = pm_quete();
    // Une menace dans la salle : sans monstre, le dé vaut 4 SANS être lancé (« table sans menace »).
    pm_gobelin($ctx);
    $ligne = pm_donner($ctx, 'Vision du futur');

    // Ouverture du tour : le d6 n'est pas encore tombé. Base 4 + 2 = 6 cases, et le menu
    // attend la réponse avant tout.
    EtatPersonnageQuete::where('personnage_id', $ctx['heros']->id)->update(['deplacement_tour' => null, 'detail_deplacement_tour' => null]);
    desFiges([2]);
    pm_ouvrirTour($ctx);

    $etat = $ctx['etatHeros']->fresh();
    expect((int) $etat->deplacement_tour)->toBe(6)
        ->and($etat->reaction_en_attente['jet'])->toBe('deplacement')
        ->and($etat->reaction_en_attente['parchemin'])->toBeTrue();

    desFiges([6]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect($reponse['active'])->toBeTrue()
        ->and($reponse['des_deplacement'])->toBe([6])
        ->and($reponse['total'])->toBe(10)
        ->and((int) $ctx['etatHeros']->fresh()->deplacement_tour)->toBe(10)
        ->and(pm_auSac($ligne))->toBeFalse();
});

it('le porteur du parchemin est proposé la relance de DÉFENSE, et ACCEPTER le retire du sac', function () {
    $ctx = pm_quete();
    $gobelin = pm_gobelin($ctx);
    $ligne = pm_donner($ctx, 'Vision du futur');

    // Gobelin : deux crânes ; défense du héros : aucun bouclier blanc. Deux coups : 6 PV.
    desFiges([1, 1, 1, 1]);
    $resultat = pm_tourDuMonstre($ctx, $gobelin);

    expect($resultat['degats'])->toBe(2)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(6);

    $offre = pm_offre($ctx);
    expect($offre['jet'])->toBe('defense')
        ->and($offre['parchemin'])->toBeTrue()
        ->and($offre['nom'])->toBe('parchemin de Vision du futur');

    desFiges([4, 4]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect($reponse['active'])->toBeTrue()
        ->and($reponse['degats_annules'])->toBe(2)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(8)
        ->and(pm_auSac($ligne))->toBeFalse();
});

it('Vision du futur n\'est JAMAIS dans « Lire un parchemin » : elle se joue après un jet, pas en lisant', function () {
    $ctx = pm_quete();
    pm_donner($ctx, 'Vision du futur');

    expect(pm_lire($ctx))->toBeNull();
});

it('par les vraies routes : un porteur est proposé la relance, /choix suspend l\'attaque, /reaction la reprend et retire le parchemin', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    ['heros' => $heros, 'instance' => $gobelin, 'alice' => $alice, 'groupe' => $groupe] = $ctx;

    $ligne = $heros->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Parchemin : Vision du futur')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'consommable',
    ]);

    // Ouverture du tour : le d6 tombe, la relance est proposée AVANT tout — on refuse.
    EtatPersonnageQuete::where('personnage_id', $heros->id)->update(['deplacement_tour' => null, 'detail_deplacement_tour' => null]);
    desFiges(array_fill(0, 300, 3));
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);
    expect(EtatPersonnageQuete::where('personnage_id', $heros->id)->first()->reaction_en_attente['jet'])->toBe('deplacement');

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => false,
    ])->assertOk()->assertJsonPath('reaction.jet', 'deplacement');

    // L'attaque : tous les dés sur « bouclier noir » — le jet est raté.
    desFiges(array_fill(0, 300, 6));
    $cle = GenererMenu::cleMenu($groupe->id, (int) $alice->id);
    $option = collect(Cache::get($cle)['menu']['options'])->firstWhere('id', 'attaquer');
    $cible = $option['parametres']['cibles'][0]['id'] ?? $option['parametres']['armes'][0]['cibles'][0]['id'];

    $choix = test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => array_filter(['cible_id' => $cible, 'cle' => $option['parametres']['armes'][0]['cle'] ?? null]),
    ])->assertAccepted();

    expect($choix->json('resultat.type'))->toBe('jet_en_attente')
        ->and($gobelin->fresh()->etat)->toBe('actif')
        ->and(EtatPersonnageQuete::where('personnage_id', $heros->id)->first()->reaction_en_attente['parchemin'])->toBeTrue();

    // Il relance : tous les dés deviennent des crânes. L'attaque s'applique, le parchemin part.
    desFiges(array_fill(0, 300, 1));
    $reaction = test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => true,
    ])->assertOk();

    expect($reaction->json('reaction.active'))->toBeTrue()
        ->and($reaction->json('reaction.resultat.type'))->toBe('attaque')
        ->and($reaction->json('reaction.resultat.degats'))->toBeGreaterThan(0)
        ->and($gobelin->fresh()->etat)->toBe('vaincu')
        ->and(pm_auSac($ligne))->toBeFalse();
});
