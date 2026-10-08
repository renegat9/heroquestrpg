<?php

declare(strict_types=1);

use App\Engine\ReactionEffet;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
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
use Illuminate\Support\Facades\Http;

/*
 * VISION DU FUTUR — Future Sight (Wizards of Morcar, Spells of Detection,
 * 2026-10-08). « This spell may be cast at any time and does not take an action.
 * You may re-roll all dice for any one attack, defense or movement roll. Discard
 * after use. »
 *
 * Décision de René : la relance est proposée JUSTE APRÈS le jet — le résultat est
 * montré, le serveur attend la réponse AVANT de l'appliquer. Trois jets, un test
 * EN JEU chacun : le héros qui frappe (action suspendue puis REJOUÉE), le d6 de
 * son tour (rien ne joue tant qu'il n'a pas répondu), la défense pendant la phase
 * des monstres (le coup est appliqué puis défait — la seule phase qu'on ne peut
 * pas suspendre). Refus par défaut : un téléphone muet ne fige jamais le groupe.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : celui-ci dérive un parchemin par sort.
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, SortSeeder::class,
        ObjetSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/**
 * Quête minimale 7×7, un magicien qui connaît le répertoire `detection` (donc la
 * Vision du futur) en (3,3), un monstre au contact en (4,3).
 *
 * @return array{groupe: App\Models\Groupe, heros: Personnage, quete: Quete, etatHeros: EtatPersonnageQuete, monstre: InstanceMonstre}
 */
function queteVisionDuFutur(bool $connaitLeSort = true, bool $deplacementDejaLance = true): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Lyra', 1, ['classe' => 'magicien']);

    if ($connaitLeSort) {
        app(MoteurSorts::class)->attacherElement($heros, 'detection');
    }

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
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'epreuves' => [], 'mobilier' => [],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    // Le d6 du tour est lancé à l'OUVERTURE du tour, bien avant qu'on attaque : sauf
    // pour les tests du déplacement, il est déjà tombé (un menu régénéré après
    // l'attaque ne relance rien).
    $etat = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => 3, 'position_y' => 3,
        ...($deplacementDejaLance ? [
            'deplacement_tour' => 5,
            'detail_deplacement_tour' => ['base' => 4, 'des' => [1], 'de_annule' => false, 'de_annule_par' => null, 'sans_menace' => false],
        ] : []),
    ]);

    $monstre = InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => Monstre::firstOrFail()->id,
        'pv_body' => 10, 'pv_body_max' => 10, 'pv_mind' => 2,
        'position_x' => 4, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    return ['groupe' => $groupe, 'heros' => $heros, 'quete' => $quete->fresh()->load('carte'),
        'etatHeros' => $etat, 'monstre' => $monstre];
}

/** L'option « Attaquer » à mains nues, telle que le menu la publie. */
function vdf_optionAttaquer(array $ctx): array
{
    return [
        'id' => 'attaquer', 'libelle' => 'Attaquer', 'type' => 'attaque', 'lancer' => false,
        'parametres' => ['arme' => 'arme_principale', 'cibles' => [[
            'id' => $ctx['monstre']->id, 'type' => 'monstre', 'nom' => 'Gobelin', 'nom_base' => 'Gobelin', 'distance' => false,
        ]]],
    ];
}

/** Attaque le monstre : rend soit `jet_en_attente`, soit l'attaque appliquée. */
function vdf_frapperLeMonstre(array $ctx): array
{
    return app(ResolveurTour::class)->resoudre(
        $ctx['groupe']->fresh(), $ctx['heros']->fresh(), vdf_optionAttaquer($ctx), ['cible_id' => $ctx['monstre']->id],
    );
}

function vdf_sortDisponible(Personnage $heros): bool
{
    return (bool) $heros->fresh()->sorts()->where('nom', 'Vision du futur')->first()?->pivot->disponible;
}

function vdf_offreEnAttente(array $ctx): ?array
{
    return $ctx['etatHeros']->fresh()->reaction_en_attente;
}

// =====================================================================
// LE SORT — pas d'action, pas de menu
// =====================================================================

it('n\'a aucune entrée de menu : il ne se lance pas, il se joue après un jet', function () {
    $ctx = queteVisionDuFutur();
    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete'], $ctx['heros']->fresh());
    $noms = collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])->pluck('nom')->all();

    expect($noms)->toContain('Trésor convoité')->and($noms)->not->toContain('Vision du futur');

    // Et son parchemin ne se lit pas non plus.
    $ctx['heros']->inventaire()->create([
        'objet_id' => App\Models\Objet::where('nom', 'Parchemin : Vision du futur')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'consommable',
    ]);
    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete'], $ctx['heros']->fresh());
    expect(collect($options)->firstWhere('id', 'lire_parchemin'))->toBeNull();
});

it('déclare l\'action de réaction et la compte parmi celles qui peuvent relever un héros', function () {
    expect(ReactionEffet::actionsToutes())->toContain(ReactionEffet::RELANCE_JET)
        ->and(ReactionEffet::ACTIONS_RELEVANTES)->toContain(ReactionEffet::RELANCE_JET)
        ->and(ReactionEffet::JETS)->toBe(['attaque', 'defense', 'deplacement']);
});

// =====================================================================
// JET D'ATTAQUE — l'action est SUSPENDUE, rien ne s'applique avant la réponse
// =====================================================================

it('suspend l\'attaque après le jet : rien n\'est appliqué, le jet est montré', function () {
    $ctx = queteVisionDuFutur();

    // 3 dés d'attaque : trois boucliers noirs (aucun crâne) ; 1 dé de défense.
    desFiges([6, 6, 6, 1]);
    $resultat = vdf_frapperLeMonstre($ctx);

    expect($resultat['type'])->toBe(ResolveurTour::TYPE_JET_EN_ATTENTE)
        ->and($resultat['jet'])->toBe('attaque')
        ->and($resultat['faces_attaque'])->toBe(['bouclier_noir', 'bouclier_noir', 'bouclier_noir'])
        ->and($resultat['faces_defense'])->toHaveCount(1)
        ->and($resultat['expire_dans'])->toBe(ReactionEffet::FENETRE_SECONDES);

    // RIEN n'a été appliqué : le monstre est intact, le tour n'est pas consommé…
    expect((int) $ctx['monstre']->fresh()->pv_body)->toBe(10)
        ->and((bool) $ctx['etatHeros']->fresh()->a_joue)->toBeFalse()
        ->and((bool) $ctx['etatHeros']->fresh()->a_agi)->toBeFalse()
        // …et le sort non plus.
        ->and(vdf_sortDisponible($ctx['heros']))->toBeTrue();

    // L'offre vit en base (jamais en cache), avec son jet MONTRÉ.
    $offre = vdf_offreEnAttente($ctx);
    expect($offre['action'])->toBe(ReactionEffet::RELANCE_JET)
        ->and($offre['jet'])->toBe('attaque')
        ->and($offre['des'])->toBe(['bouclier_noir', 'bouclier_noir', 'bouclier_noir'])
        ->and($offre['resume'])->toContain('aucun dégât');

    // Aucune attaque au journal : l'action n'a pas eu lieu.
    expect(Evenement::where('groupe_id', $ctx['groupe']->id)->where('payload->type', 'attaque')->count())->toBe(0);
});

it('REFUSER rejoue l\'attaque avec LE jet vu, et garde le sort', function () {
    $ctx = queteVisionDuFutur();

    // Trois crânes : un bon jet, que le héros garde. Défense du gobelin : un dé blanc (ne pare rien).
    desFiges([1, 1, 1, 4]);
    vdf_frapperLeMonstre($ctx);

    desFiges([]); // plus AUCUN dé ne doit être lancé : la reprise rejoue le jet vu
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), false);

    expect($reponse['active'])->toBeFalse()
        ->and($reponse['resultat']['type'])->toBe('attaque')
        ->and($reponse['resultat']['faces_attaque'])->toBe(['crane', 'crane', 'crane'])
        ->and($reponse['resultat']['degats'])->toBe(3);

    expect((int) $ctx['monstre']->fresh()->pv_body)->toBe(7)
        ->and((bool) $ctx['etatHeros']->fresh()->a_agi)->toBeTrue()
        ->and(vdf_sortDisponible($ctx['heros']))->toBeTrue()
        ->and(vdf_offreEnAttente($ctx))->toBeNull();
});

it('ACCEPTER relance les dés du héros seul, applique le nouveau résultat et consomme le sort', function () {
    $ctx = queteVisionDuFutur();

    // Jet raté : trois boucliers noirs. Défense du monstre : un dé blanc (0 parade).
    desFiges([6, 6, 6, 4]);
    vdf_frapperLeMonstre($ctx);
    expect((int) $ctx['monstre']->fresh()->pv_body)->toBe(10);

    // Nouveaux dés d'attaque : trois crânes. SEULS trois dés sont lancés — la défense
    // du monstre, déjà tombée, n'est pas relancée.
    $lanceur = desFiges([1, 1, 1]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect($reponse['active'])->toBeTrue()
        ->and($reponse['jet'])->toBe('attaque')
        ->and($reponse['resultat']['faces_attaque'])->toBe(['crane', 'crane', 'crane'])
        ->and($reponse['resultat']['faces_defense'])->toBe(['bouclier_blanc'])
        ->and($reponse['resultat']['degats'])->toBe(3);

    expect((int) $ctx['monstre']->fresh()->pv_body)->toBe(7)
        // « Discard after use » : le sort est dépensé.
        ->and(vdf_sortDisponible($ctx['heros']))->toBeFalse()
        ->and(vdf_offreEnAttente($ctx))->toBeNull();

    // Annoncé au journal, avec le nom du sort.
    $lignes = Evenement::where('groupe_id', $ctx['groupe']->id)->where('payload->type', 'reaction')->get();
    expect($lignes)->toHaveCount(1)
        ->and($lignes->first()->payload['texte'])->toContain('relance ses dés d\'attaque');

    // Le sort dépensé, une seconde attaque n'est PLUS suspendue.
    $ctx['etatHeros']->update(['a_agi' => false, 'a_joue' => false]);
    desFiges([1, 1, 1, 4]);
    expect(vdf_frapperLeMonstre($ctx)['type'])->toBe('attaque');
});

it('tant que le jet attend, le héros ne peut rien jouer d\'autre', function () {
    $ctx = queteVisionDuFutur();
    desFiges([6, 6, 6, 4]);
    vdf_frapperLeMonstre($ctx);

    expect(fn () => app(ResolveurTour::class)->resoudre(
        $ctx['groupe']->fresh(), $ctx['heros']->fresh(), ['id' => 'attendre', 'type' => 'attente', 'libelle' => 'Terminer le tour'],
    ))->toThrow(Illuminate\Validation\ValidationException::class, 'Vision du futur');
});

it('sans réponse, la fenêtre écoulée REPREND l\'action avec le jet d\'origine — jamais un groupe figé', function () {
    $ctx = queteVisionDuFutur();
    desFiges([1, 1, 1, 4]);
    vdf_frapperLeMonstre($ctx);

    // Le téléphone ne répond pas : la fenêtre est écoulée.
    $offre = vdf_offreEnAttente($ctx);
    $offre['expire_a'] = now()->subSecond()->toIso8601String();
    $ctx['etatHeros']->update(['reaction_en_attente' => $offre]);

    desFiges([]);
    expect(app(MoteurReactions::class)->rattraperExpiration($ctx['groupe']->fresh()))->toBeTrue();

    expect((int) $ctx['monstre']->fresh()->pv_body)->toBe(7)   // le jet d'origine s'est appliqué
        ->and(vdf_offreEnAttente($ctx))->toBeNull()
        ->and(vdf_sortDisponible($ctx['heros']))->toBeTrue();      // refus par défaut : le sort reste
});

it('une réponse TARDIVE vaut un refus et ne perd pas l\'action', function () {
    $ctx = queteVisionDuFutur();
    desFiges([6, 6, 6, 4]);
    vdf_frapperLeMonstre($ctx);

    $offre = vdf_offreEnAttente($ctx);
    $offre['expire_a'] = now()->subSecond()->toIso8601String();
    $ctx['etatHeros']->update(['reaction_en_attente' => $offre]);

    desFiges([]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect($reponse['active'])->toBeFalse()
        ->and($reponse['resultat']['faces_attaque'])->toBe(['bouclier_noir', 'bouclier_noir', 'bouclier_noir'])
        ->and(vdf_sortDisponible($ctx['heros']))->toBeTrue();
});

it('sans le sort, l\'attaque s\'applique tout de suite, comme avant', function () {
    $ctx = queteVisionDuFutur(connaitLeSort: false);
    desFiges([1, 1, 1, 4]);

    expect(vdf_frapperLeMonstre($ctx)['type'])->toBe('attaque')
        ->and((int) $ctx['monstre']->fresh()->pv_body)->toBe(7);
});

it('publie l\'offre sur l\'état SANS l\'action à rejouer', function () {
    $ctx = queteVisionDuFutur();
    desFiges([6, 6, 6, 4]);
    vdf_frapperLeMonstre($ctx);

    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toHaveKey('reprise');

    $entites = app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['entites'];
    $lyra = collect($entites)->firstWhere('nom', 'Lyra');

    expect($lyra['reaction_en_attente']['action'])->toBe('relance_jet')
        ->and($lyra['reaction_en_attente']['des'])->toHaveCount(3)
        ->and($lyra['reaction_en_attente'])->not->toHaveKey('reprise');
});

// =====================================================================
// JET DE DÉPLACEMENT — le d6 du tour
// =====================================================================

function vdf_ouvrirLeTourDeLyra(array $ctx): array
{
    return (new ReflectionMethod(MenuMoteur::class, 'generer'))
        ->invoke(app(MenuMoteur::class), $ctx['groupe']->fresh(), $ctx['heros']->fresh());
}

it('propose la relance du d6 du tour juste après le lancer, et le menu attend', function () {
    $ctx = queteVisionDuFutur(deplacementDejaLance: false);
    desFiges([2]); // base 4 + 2 = 6
    vdf_ouvrirLeTourDeLyra($ctx);

    $etat = $ctx['etatHeros']->fresh();
    expect((int) $etat->deplacement_tour)->toBe(6)
        ->and($etat->reaction_en_attente['jet'])->toBe('deplacement')
        ->and($etat->reaction_en_attente['des'])->toBe([2])
        ->and($etat->reaction_en_attente['resume'])->toContain('6 cases');

    expect(fn () => app(ResolveurTour::class)->resoudre(
        $ctx['groupe']->fresh(), $ctx['heros']->fresh(),
        ['id' => 'se_deplacer', 'type' => 'deplacement', 'libelle' => 'Se déplacer'], ['x' => 3, 'y' => 4],
    ))->toThrow(Illuminate\Validation\ValidationException::class, 'Vision du futur');
});

it('ACCEPTER relance le d6 : le nouveau total remplace l\'ancien, le sort est dépensé', function () {
    $ctx = queteVisionDuFutur(deplacementDejaLance: false);
    desFiges([2]);
    vdf_ouvrirLeTourDeLyra($ctx);

    desFiges([6]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect($reponse['active'])->toBeTrue()
        ->and($reponse['des_deplacement'])->toBe([6])
        ->and($reponse['total'])->toBe(10);

    $etat = $ctx['etatHeros']->fresh();
    expect((int) $etat->deplacement_tour)->toBe(10)
        ->and($etat->detail_deplacement_tour['des'])->toBe([6])
        ->and($etat->reaction_en_attente)->toBeNull()
        ->and(vdf_sortDisponible($ctx['heros']))->toBeFalse();

    // Le menu suivant annonce la portée DÉCIDÉE par le dernier jet.
    $se_deplacer = collect(vdf_ouvrirLeTourDeLyra($ctx)['options'])->firstWhere('id', 'se_deplacer');
    expect($se_deplacer['parametres']['portee'])->toBe(10);
});

it('REFUSER garde le d6 d\'origine et rend la main', function () {
    $ctx = queteVisionDuFutur(deplacementDejaLance: false);
    desFiges([2]);
    vdf_ouvrirLeTourDeLyra($ctx);

    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), false);

    expect($reponse['active'])->toBeFalse()
        ->and((int) $ctx['etatHeros']->fresh()->deplacement_tour)->toBe(6)
        ->and(vdf_offreEnAttente($ctx))->toBeNull()
        ->and(vdf_sortDisponible($ctx['heros']))->toBeTrue();

    // Le héros joue : l'offre ne bloque plus.
    $options = vdf_ouvrirLeTourDeLyra($ctx)['options'];
    expect(collect($options)->firstWhere('id', 'se_deplacer'))->not->toBeNull();
});

it('le d6 du tour d\'un héros qui ne connaît pas le sort ne propose rien', function () {
    $ctx = queteVisionDuFutur(connaitLeSort: false, deplacementDejaLance: false);
    desFiges([2]);
    vdf_ouvrirLeTourDeLyra($ctx);

    expect(vdf_offreEnAttente($ctx))->toBeNull();
});

// =====================================================================
// JET DE DÉFENSE — phase des monstres : appliqué, puis défait si on relance
// =====================================================================

function vdf_tourDuMonstre(array $ctx): array
{
    $cibles = $ctx['quete']->etatsPersonnages()->with('personnage')->get();

    return (new ReflectionMethod(ResolveurTour::class, 'jouerMonstre'))->invoke(
        app(ResolveurTour::class), $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['monstre'], $cibles,
    );
}

it('propose de relancer la défense quand un monstre blesse, avec les deux volées', function () {
    $ctx = queteVisionDuFutur();

    // Gobelin : 2 dés d'attaque (deux crânes), puis 2 dés de défense (aucun bouclier blanc).
    desFiges([1, 1, 1, 1]);
    $resultat = vdf_tourDuMonstre($ctx);

    expect($resultat['degats'])->toBe(2)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(6);

    $offre = vdf_offreEnAttente($ctx);
    expect($offre['action'])->toBe(ReactionEffet::RELANCE_JET)
        ->and($offre['jet'])->toBe('defense')
        ->and($offre['des'])->toBe(['crane', 'crane'])               // les dés de DÉFENSE du héros
        ->and($offre['des_adverses'])->toBe(['crane', 'crane'])     // l'attaque du monstre
        ->and($offre['touchante'])->toBe('crane')
        ->and($offre['defensive'])->toBe('bouclier_blanc')
        ->and($offre['resume'])->toContain('2 point');
});

it('ACCEPTER rend le coup, relance SEULS les dés de défense du héros contre la même attaque', function () {
    $ctx = queteVisionDuFutur();
    desFiges([1, 1, 1, 1]);
    vdf_tourDuMonstre($ctx);
    expect((int) $ctx['heros']->fresh()->pv_body)->toBe(6);

    // Deux dés de défense seulement : deux boucliers blancs. L'attaque (2 crânes) est gardée.
    desFiges([4, 4]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect($reponse['active'])->toBeTrue()
        ->and($reponse['degats_annules'])->toBe(2)
        ->and($reponse['degats_relance'])->toBe(0)
        ->and($reponse['faces_attaque'])->toBe(['crane', 'crane'])
        ->and($reponse['faces_defense'])->toBe(['bouclier_blanc', 'bouclier_blanc'])
        ->and($reponse['texte'])->toContain('aucun dégât');

    expect((int) $ctx['heros']->fresh()->pv_body)->toBe(8)
        ->and(vdf_sortDisponible($ctx['heros']))->toBeFalse()
        ->and(vdf_offreEnAttente($ctx))->toBeNull();
});

it('REFUSER la défense garde le coup et le sort', function () {
    $ctx = queteVisionDuFutur();
    desFiges([1, 1, 1, 1]);
    vdf_tourDuMonstre($ctx);

    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), false);

    expect($reponse['active'])->toBeFalse()
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(6)
        ->and(vdf_sortDisponible($ctx['heros']))->toBeTrue();
});

it('relancer une défense peut aussi l\'empirer — « en mieux comme en pire »', function () {
    $ctx = queteVisionDuFutur();
    // Défense réussie (deux boucliers blancs) : 0 dégât, donc AUCUNE offre.
    desFiges([1, 1, 4, 4]);
    vdf_tourDuMonstre($ctx);
    expect(vdf_offreEnAttente($ctx))->toBeNull();

    // Un seul bouclier : 1 dégât, offre ; la relance donne zéro bouclier : 2 dégâts.
    $ctx['heros']->update(['pv_body' => 8]);
    desFiges([1, 1, 4, 1]);
    vdf_tourDuMonstre($ctx);
    expect((int) $ctx['heros']->fresh()->pv_body)->toBe(7);

    desFiges([1, 1]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    expect($reponse['degats_relance'])->toBe(2)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(6);
});

it('journalise la relance de défense et la met en scène sur la table', function () {
    $ctx = queteVisionDuFutur();
    desFiges([1, 1, 1, 1]);
    vdf_tourDuMonstre($ctx);
    desFiges([4, 4]);
    $reponse = app(MoteurReactions::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), true);

    $lignes = app(App\Partie\JournalCombat::class)->depuisResultat($reponse, 'Lyra');
    expect(collect($lignes)->pluck('texte')->implode(' '))->toContain('relance ses dés de défense');

    $scenes = app(App\Partie\SceneDeTable::class)->depuisReaction($reponse, $ctx['heros']->fresh());
    expect($scenes)->not->toBeEmpty()->and($scenes[0]['genre'])->toBe('reaction');
});

// =====================================================================
// DE BOUT EN BOUT — les vraies routes : /choix suspend, /reaction reprend
// =====================================================================

it('par les vraies routes : /choix suspend l\'attaque, /reaction la reprend avec des dés neufs', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    ['heros' => $heros, 'instance' => $gobelin, 'alice' => $alice] = $ctx;
    app(MoteurSorts::class)->attacherElement($heros, 'detection');

    $cle = App\Jobs\GenererMenu::cleMenu($ctx['groupe']->id, (int) $alice->id);

    // Ouverture du tour : le d6 tombe, la relance est proposée AVANT tout — on refuse.
    // (Le démarrage de la quête avait déjà ouvert un tour sans le sort : on le rouvre.)
    EtatPersonnageQuete::where('personnage_id', $heros->id)->update(['deplacement_tour' => null, 'detail_deplacement_tour' => null]);
    desFiges(array_fill(0, 300, 3));
    App\Jobs\GenererMenu::dispatchSync($ctx['groupe']->id, (int) $alice->id, (int) $heros->id);
    expect(EtatPersonnageQuete::where('personnage_id', $heros->id)->first()->reaction_en_attente['jet'])->toBe('deplacement');

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => false,
    ])->assertOk()->assertJsonPath('reaction.jet', 'deplacement');

    // Une attaque : tous les dés sur « bouclier noir » — zéro crâne, le jet est raté.
    desFiges(array_fill(0, 300, 6));
    $option = collect(Illuminate\Support\Facades\Cache::get($cle)['menu']['options'])->firstWhere('id', 'attaquer');
    $cible = $option['parametres']['cibles'][0]['id'] ?? $option['parametres']['armes'][0]['cibles'][0]['id'];

    $reponse = test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => array_filter(['cible_id' => $cible, 'cle' => $option['parametres']['armes'][0]['cle'] ?? null]),
    ])->assertAccepted();

    expect($reponse->json('resultat.type'))->toBe('jet_en_attente')
        ->and($reponse->json('resultat.jet'))->toBe('attaque')
        ->and($gobelin->fresh()->etat)->toBe('actif')
        // Le menu n'est PAS consommé : l'action n'a pas eu lieu.
        ->and(Illuminate\Support\Facades\Cache::get($cle))->not->toBeNull();

    // …et le héros ne peut pas jouer autre chose en attendant.
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(422);

    // Il relance : tous les dés deviennent des crânes. L'attaque s'applique ENFIN.
    desFiges(array_fill(0, 300, 1));
    $reaction = test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => true,
    ])->assertOk();

    expect($reaction->json('reaction.active'))->toBeTrue()
        ->and($reaction->json('reaction.resultat.type'))->toBe('attaque')
        ->and($reaction->json('reaction.resultat.degats'))->toBeGreaterThan(0)
        ->and($gobelin->fresh()->etat)->toBe('vaincu')
        ->and(vdf_sortDisponible($heros))->toBeFalse();
});
