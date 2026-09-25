<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\Equipement;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\MoteurCharges;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Les deux mécaniques ouvertes le 2026-08-09 : **charges** et **économie de
 * sorts**. Elles bloquaient à elles seules sept cartes des deux paquets.
 *
 * Une charge dit « cet exemplaire-ci a N utilisations » — ce que
 * `inventaire.quantite` (une pile d'exemplaires identiques) ne savait pas
 * exprimer. L'économie de sorts dit quand un sort épuisé peut revenir, là où le
 * pivot n'avait qu'un booléen remis à zéro par quête.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Pose une pièce dans un emplacement donné, sans passer par les garde-fous. */
function poser(Personnage $p, string $nom, string $emplacement): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

/**
 * Éloigne la cible du héros, sur une case de sol en ligne de vue dégagée.
 * (Copie locale : les fonctions d'un fichier Pest ne sont visibles qu'une fois
 * ce fichier chargé, donc jamais fiables d'un fichier de test à l'autre.)
 */
function eloignerPour(Quete $quete, InstanceMonstre $instance, int $hx, int $hy): void
{
    $grille = Grille::depuisCarte($quete->carte);

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true) || abs($x - $hx) + abs($y - $hy) < 2) {
                continue;
            }

            if ($grille->ligneDeVue($hx, $hy, $x, $y)) {
                $instance->update(['position_x' => $x, 'position_y' => $y]);

                return;
            }
        }
    }

    throw new RuntimeException('Aucune case à distance avec ligne de vue.');
}

/** Donne des sorts au magicien : `creerHeros` n'en attache aucun. */
function armerDeSorts(Personnage $mage): void
{
    $moteur = app(MoteurSorts::class);
    $moteur->attacherElement($mage, 'feu');
    $moteur->attacherElement($mage, 'eau');
}

/**
 * Premier sort du MENU qui porte au moins une cible légale — le menu est la
 * seule source de vérité sur ce qu'un sort peut viser.
 *
 * @return array{0: int, 1: array<string, mixed>}
 */
function premierSortCiblable(array $ctx, Personnage $mage): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $mage->id);

    // ⚠ Depuis le 2026-09-01 le menu porte UNE option « Lancer un sort » qui
    // porte la liste : on cherche donc une ENTRÉE ciblable, pas une option.
    $option = collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'])
        ->firstWhere('id', 'lancer_sort');

    $entree = collect($option['parametres']['sorts'] ?? [])
        ->first(fn ($e) => ($e['disponible'] ?? false) && ! empty($e['cibles']));

    expect($entree)->not->toBeNull('Aucun sort ciblable au menu.');

    return [(int) $entree['sort_id'], $entree['cibles'][0]];
}

/**
 * Remet le héros en début de tour. On requête la ligne plutôt que d'appeler
 * `update()` sur l'instance du contexte : après une action, la phase des
 * monstres et la fin de round ont pu la réécrire, et un modèle périmé
 * réécrirait des colonnes obsolètes.
 */
function rearmerTour(array $ctx, Personnage $heros): void
{
    EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)
        ->where('personnage_id', $heros->id)
        ->update([
            'a_agi' => false, 'a_joue' => false, 'a_deplace' => false,
            'bonus_sort_utilise' => false, 'tombe' => false,
            'deplacement_tour' => null, 'deplacement_restant' => null,
        ]);
}

/** Épuise tous les sorts du héros (comme s'il les avait tous lancés). */
function epuiserSorts(Personnage $p): void
{
    DB::table('personnage_sorts')->where('personnage_id', $p->id)->update(['disponible' => false]);
}

// ---------------------------------------------------------------------------
// Charges — le compteur lui-même
// ---------------------------------------------------------------------------

it('démarre plein sans que la ligne d\'inventaire ait été initialisée', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $elfe = creerHeros($alice, $groupe, 'Lindir', 1, ['classe' => 'elfe']);

    $ligne = poser($elfe, 'Arc elfique de Vindication', 'sac');
    $charges = app(MoteurCharges::class);

    // `charges` en base vaut null : « jamais entamé », pas « épuisé ». C'est ce
    // qui permet à tous les chemins qui créent une ligne d'inventaire (marché,
    // coffre, don, butin) d'ignorer complètement la notion de charge.
    expect($ligne->charges)->toBeNull()
        ->and($charges->restantes($ligne->load('objet')))->toBe(4)
        ->and($charges->disponible($ligne))->toBeTrue();
});

it('rend null pour un objet sans charges, et le laisse toujours disponible', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $ligne = poser($heros, 'Épée large', 'sac')->load('objet');
    $charges = app(MoteurCharges::class);

    // La quasi-totalité du catalogue est illimitée : les appelants ne doivent
    // pas avoir à savoir si la pièce qu'ils manipulent a des charges.
    expect($charges->restantes($ligne))->toBeNull()
        ->and($charges->disponible($ligne))->toBeTrue()
        ->and($charges->consommer($ligne))->toBeTrue()
        ->and($ligne->fresh()->charges)->toBeNull(); // rien n'a été écrit
});

it('décompte jusqu\'à zéro, puis DÉTRUIT l\'objet — et le dit', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    // ⚠ L'Anneau de Sort a quitté ce test le 2026-09-03 : sa carte dit « cast
    // one spell twice in the same QUEST », donc il porte désormais une FENÊTRE
    // et non des charges. L'Anneau de Feu, lui, énonce un total fini — « the
    // next two Dread fire spells » — et reste l'exemple juste pour ce test-ci,
    // qui parle des charges.
    $ligne = poser($magicien, 'Anneau de Feu', 'talisman')->load('objet');
    $charges = app(MoteurCharges::class);

    expect($charges->consommer($ligne))->toBeTrue()
        ->and($charges->restantes($ligne->fresh()))->toBe(1)
        ->and($charges->disponible($ligne->fresh()))->toBeTrue()
        // La dernière charge sert encore : l'effet s'applique…
        ->and($charges->consommer($ligne->fresh()->load('objet')))->toBeTrue()
        // …puis l'anneau se BRISE (René, 2026-09-16). Il n'est plus « inerte au
        // sac » : c'est ce qui le rend de nouveau trouvable dans les coffres.
        ->and(Inventaire::find($ligne->id))->toBeNull();

    // Et la disparition est annoncée — un artefact qui quitte la main d'un
    // héros sans un mot est un effet automatique que rien n'annonce.
    $annonce = Evenement::where('groupe_id', $groupe->id)->where('type', 'combat')->get()
        ->first(fn ($e) => ($e->payload['type'] ?? null) === 'objet_detruit');

    expect($annonce)->not->toBeNull()
        ->and($annonce->payload['objet'])->toBe('Anneau de Feu')
        ->and(app(JournalCombat::class)->depuisResultat($annonce->payload, 'Aldric')[0]['texte'])
        ->toBe('Anneau de Feu de Aldric est épuisé et se brise');
});

// ---------------------------------------------------------------------------
// Arc elfique de Vindication — 3 PV par flèche, 4 flèches, puis il se brise
// (arbitrage de René, 2026-09-16, qui remplace la mort instantanée de la carte)
// ---------------------------------------------------------------------------

/** Prépare un elfe armé de l'arc, la cible éloignée et en ligne de vue. */
function elfeArme(): array
{
    $ctx = demarrerQueteAvecMonstre('Momie', ['classe' => 'elfe']);
    $ctx['arc'] = poser($ctx['heros'], 'Arc elfique de Vindication', 'arme_principale');
    app(Equipement::class)->recalculerCombat($ctx['heros']->refresh());

    eloignerPour($ctx['quete'], $ctx['instance'],
        (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    return $ctx;
}

/** Tire une flèche, le dé de défense du monstre étant forcé à $face. */
function tirer(array $ctx, int $face): TestResponse
{
    desFiges(array_fill(0, 10, $face));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges([$face]);

    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ]);
}

it('inflige 3 PV par flèche, sans jet d\'attaque ni défense', function () {
    $ctx = elfeArme();
    $ctx['instance']->update(['pv_body' => 5, 'pv_body_max' => 5]);

    // 1 = crâne, pas de bouclier noir : la flèche porte ses 3 PV, et la cible
    // en a 5 — elle tient. Plus de mort instantanée.
    $reponse = tirer($ctx, 1)->assertStatus(202)
        ->assertJsonPath('resultat.vindication', true)
        ->assertJsonPath('resultat.degats', 3)
        ->assertJsonPath('resultat.pv_body_apres', 2)
        ->assertJsonPath('resultat.cible_vaincue', false)
        ->assertJsonPath('resultat.fleches_restantes', 3);

    // …et le fil le DIT : `fleches_restantes` était publié, jamais lu (2026-09-25).
    $lignes = collect(app(JournalCombat::class)->depuisResultat($reponse->json('resultat'), 'Sylvan'))->pluck('texte');
    expect($lignes->implode(' | '))->toContain('3 flèches restantes à Sylvan');

    expect((int) $ctx['instance']->fresh()->pv_body)->toBe(2)
        ->and($ctx['instance']->fresh()->etat)->toBe('actif')
        ->and((int) $ctx['arc']->fresh()->charges)->toBe(3);
});

it('abat une cible qui n\'a pas plus de 3 PV', function () {
    $ctx = elfeArme();
    $ctx['instance']->update(['pv_body' => 2, 'pv_body_max' => 2]);

    // Les dégâts affichés sont ceux RÉELLEMENT retirés, pas les 3 promis.
    tirer($ctx, 1)->assertStatus(202)
        ->assertJsonPath('resultat.degats', 2)
        ->assertJsonPath('resultat.cible_vaincue', true);

    expect($ctx['instance']->fresh()->etat)->toBe('vaincu');
});

it('s\'arrête sur un bouclier noir — mais la flèche part quand même', function () {
    $ctx = elfeArme();
    $pvAvant = (int) $ctx['instance']->pv_body;

    // 6 = bouclier noir : la cible arrête la flèche. Quatre FLÈCHES, pas quatre
    // coups au but — le carquois se vide dans les deux cas.
    tirer($ctx, 6)->assertStatus(202)
        ->assertJsonPath('resultat.degats', 0)
        ->assertJsonPath('resultat.cible_vaincue', false)
        ->assertJsonPath('resultat.fleches_restantes', 3);

    expect((int) $ctx['instance']->fresh()->pv_body)->toBe($pvAvant)
        ->and((int) $ctx['arc']->fresh()->charges)->toBe(3);
});

it('se BRISE à la dernière flèche, et le fil le dit APRÈS le tir', function () {
    $ctx = elfeArme();
    $ctx['instance']->update(['pv_body' => 5, 'pv_body_max' => 5]);
    $ctx['arc']->update(['charges' => 1]); // la dernière flèche

    tirer($ctx, 1)->assertStatus(202)
        ->assertJsonPath('resultat.degats', 3)
        ->assertJsonPath('resultat.fleches_restantes', 0);

    // « 4 flèches, après l'arc est détruit » : plus d'arc vide qui retomberait
    // sur des dés d'arme ordinaire.
    expect(Inventaire::find($ctx['arc']->id))->toBeNull();

    // ⚠ L'ORDRE : détruire pendant la dépense de la flèche journalisait « l'arc
    // se brise » AVANT « l'elfe tire » — la chute avant le coup, encore.
    $types = Evenement::where('groupe_id', $ctx['groupe']->id)->where('type', 'combat')
        ->orderBy('sequence')->get()->map(fn ($e) => $e->payload['type'] ?? null)
        ->filter(fn ($t) => in_array($t, ['attaque', 'objet_detruit'], true))->values()->all();

    expect(array_slice($types, -2))->toBe(['attaque', 'objet_detruit']);
});

// ---------------------------------------------------------------------------
// Économie de sorts
// ---------------------------------------------------------------------------

it('la Baguette de Rappel accorde un SECOND sort par tour, comme le nœud', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $magicien = $ctx['heros'];
    armerDeSorts($magicien);

    // Le héros a déjà agi : sans la baguette, aucun sort ne serait proposé.
    $ctx['etatHeros']->update(['a_agi' => true]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $magicien->id);
    $sansObjet = collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'])
        ->where('type', 'sort');
    expect($sansObjet)->toBeEmpty();

    poser($magicien, 'Baguette de Rappel', 'talisman');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $magicien->id);
    $avecObjet = collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'])
        ->where('type', 'sort');

    // Le MENU doit connaître la source : le résolveur accepterait le sort, mais
    // le contrôleur refuse toute option absente du dernier menu.
    expect($avecObjet)->not->toBeEmpty();
});

it('l\'Anneau de Sort épargne UN sort, contre sa charge', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $magicien = $ctx['heros'];
    armerDeSorts($magicien);
    $anneau = poser($magicien, 'Anneau de Sort', 'talisman');

    desFiges(array_fill(0, 30, 4));
    [$sortId, $cible] = premierSortCiblable($ctx, $magicien);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sortId}", 'cible_id' => $cible['id'], 'cible_type' => $cible['type'] ?? 'monstre'],
    ])->assertStatus(202)->assertJsonPath('resultat.sort_preserve', 'anneau_de_sort');

    $sort = $magicien->sorts()->wherePivot('sorts.id', $sortId)->firstOrFail();

    // Le sort reste lançable, et la charge est partie.
    expect((bool) $magicien->sorts()->wherePivot('sorts.id', $sort->id)->first()?->pivot->disponible)->toBeTrue()
        ->and((int) $anneau->fresh()->charges)->toBe(0);
});

// ---------------------------------------------------------------------------
// Types de dégâts — le feu (App\Engine\TypeDegat)
// ---------------------------------------------------------------------------

it('l\'Anneau de Feu annule INTÉGRALEMENT un sort de feu, deux fois', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $magicien = $ctx['heros'];
    armerDeSorts($magicien);

    // Le magicien se vise lui-même : le tir ami est délibéré (doc 02 §5, S3),
    // c'est le chemin le plus court pour éprouver l'immunité.
    $anneau = poser($magicien, 'Anneau de Feu', 'talisman');
    $pvAvant = (int) $magicien->pv_body;

    $boule = Sort::where('nom', 'Boule de Feu')->firstOrFail();

    foreach ([1, 2] as $tour) {
        rearmerTour($ctx, $magicien);
        $magicien->sorts()->updateExistingPivot($boule->id, ['disponible' => true]);

        GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $magicien->id);
        desFiges(array_fill(0, 30, 1)); // que des crânes : sans l'anneau, ça fait mal

        test()->postJson('/api/groupes/table-1/choix', [
            'option_id' => 'lancer_sort',
            'parametres' => ['cle' => "sort:{$boule->id}", 'cible_id' => $magicien->id, 'cible_type' => 'heros'],
        ])->assertStatus(202)
            ->assertJsonPath('resultat.immunite_degat', 'feu')
            ->assertJsonPath('resultat.degats', 0);
    }

    // Deux sorts encaissés sans une égratignure, et l'anneau tombe en cendres :
    // détruit au dernier usage (René, 2026-09-16), ce que la carte dit mot pour
    // mot — « the ring turns to ash ».
    expect((int) $magicien->fresh()->pv_body)->toBe($pvAvant)
        ->and($anneau->fresh())->toBeNull();

    // Le troisième passe : « the ring turns to ash after the second spell ».
    rearmerTour($ctx, $magicien);
    $magicien->sorts()->updateExistingPivot($boule->id, ['disponible' => true]);
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $magicien->id);
    desFiges(array_fill(0, 30, 1));

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$boule->id}", 'cible_id' => $magicien->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)->assertJsonMissingPath('resultat.immunite_degat');

    expect((int) $magicien->fresh()->pv_body)->toBeLessThan($pvAvant);
});

it('ne protège que du FEU : un sort d\'une autre nature passe', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $magicien = $ctx['heros'];
    armerDeSorts($magicien);
    $anneau = poser($magicien, 'Anneau de Feu', 'talisman');

    // Génie : 5 dés, élément air, AUCUN `type_degat` — donc neutre.
    $magicien->sorts()->syncWithoutDetaching([
        Sort::where('nom', 'Génie')->firstOrFail()->id => ['disponible' => true],
    ]);
    $genie = Sort::where('nom', 'Génie')->firstOrFail();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $magicien->id);
    desFiges(array_fill(0, 30, 1));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$genie->id}", 'cible_id' => $magicien->id, 'cible_type' => 'heros', 'mode' => 'degats'],
    ])->assertStatus(202)->assertJsonMissingPath('resultat.immunite_degat');

    // La charge est intacte : un anneau de feu ne se dépense pas sur autre chose.
    expect((int) ($anneau->fresh()->charges ?? 2))->toBe(2);
});

it('un sort de feu BRÛLE le monstre et lui coupe la régénération', function () {
    $ctx = demarrerQueteAvecMonstre('Troll', ['classe' => 'magicien']);
    $magicien = $ctx['heros'];
    armerDeSorts($magicien);

    expect((bool) $ctx['instance']->fresh()->brule)->toBeFalse();

    $boule = Sort::where('nom', 'Boule de Feu')->firstOrFail();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $magicien->id);
    desFiges(array_fill(0, 30, 4)); // boucliers : le troll survit, mais il a pris le feu

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$boule->id}", 'cible_id' => $ctx['instance']->id, 'cible_type' => 'monstre'],
    ])->assertStatus(202);

    // « Damage done by fire is permanent and cannot be regenerated. »
    expect((bool) $ctx['instance']->fresh()->brule)->toBeTrue();

    // Et la régénération ne repart pas : on blesse, on laisse jouer le monstre,
    // ses PV ne remontent pas.
    $instance = $ctx['instance']->fresh();
    $instance->update(['pv_body' => 1]);

    app(MoteurDread::class)->jouerTourDread(
        $ctx['groupe'], $ctx['quete'], $instance,
        $ctx['quete']->etatsPersonnages()->get(),
    );

    expect((int) $instance->fresh()->pv_body)->toBe(1); // aucun PV regagné
});

/*
 * ⚠ TROIS TESTS RETIRÉS le 2026-09-03, avec les artefacts qu'ils exerçaient.
 *
 * *Parchemin de Sorts*, *Baguette de Galimatias* et *Sceptre de Mémoire*
 * venaient du paquet fan Ye Olde Inn ; aucune carte officielle ne les couvre et
 * ils ont quitté le catalogue (migration `retirer_artefacts_hors_source`).
 *
 * Ce que deviennent leurs règles, pour qu'aucune ne se perde en silence :
 *  - `restaure_sorts` survit sous sa forme CHIFFRÉE, portée par deux potions
 *    officielles (Potion de magie 3, Potion de rappel 1) — voir
 *    `PotionsOfficiellesTest`. Seule la forme « tous » disparaît, faute de carte.
 *  - `sort_non_epuise_sur_bouclier_noir` a été REVERSÉ sur les talents
 *    `regain_sort` (arbitrage de René), puis abandonné le 2026-09-25 : ces
 *    talents gardent le sort qui tue (`garde_sort_qui_tue`). Il y bridait un regain qui se
 *    déclenchait à chaque monstre abattu. Éprouvé par `TalentsEnJeuTest`.
 */
