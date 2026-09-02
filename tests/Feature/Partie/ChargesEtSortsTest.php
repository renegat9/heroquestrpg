<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\Equipement;
use App\Partie\Grille;
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

it('décompte jusqu\'à zéro, puis refuse', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    $ligne = poser($magicien, 'Anneau de Sort', 'talisman')->load('objet');
    $charges = app(MoteurCharges::class);

    expect($charges->consommer($ligne))->toBeTrue()
        ->and($charges->restantes($ligne->fresh()))->toBe(0)
        ->and($charges->disponible($ligne->fresh()))->toBeFalse()
        // Épuisé : l'objet RESTE en inventaire, il ne fait simplement plus rien.
        ->and($charges->consommer($ligne->fresh()))->toBeFalse()
        ->and($ligne->fresh())->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Arc elfique de Vindication — 4 flèches, mort instantanée
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

it('tue la cible d\'emblée, quels que soient ses PV', function () {
    $ctx = elfeArme();

    // 1 = crâne, donc pas de bouclier noir : la momie tombe d'un coup.
    tirer($ctx, 1)->assertStatus(202)
        ->assertJsonPath('resultat.vindication', true)
        ->assertJsonPath('resultat.cible_vaincue', true)
        ->assertJsonPath('resultat.fleches_restantes', 3);

    expect((int) $ctx['instance']->fresh()->pv_body)->toBe(0)
        ->and($ctx['instance']->fresh()->etat)->toBe('vaincu')
        ->and((int) $ctx['arc']->fresh()->charges)->toBe(3);
});

it('épargne la cible sur un bouclier noir — mais la flèche part quand même', function () {
    $ctx = elfeArme();
    $pvAvant = (int) $ctx['instance']->pv_body;

    // 6 = bouclier noir : le monstre survit. La carte donne quatre FLÈCHES,
    // pas quatre morts — le carquois se vide dans les deux cas.
    tirer($ctx, 6)->assertStatus(202)
        ->assertJsonPath('resultat.cible_vaincue', false)
        ->assertJsonPath('resultat.fleches_restantes', 3);

    expect((int) $ctx['instance']->fresh()->pv_body)->toBe($pvAvant)
        ->and((int) $ctx['arc']->fresh()->charges)->toBe(3);
});

it('redevient une arme ordinaire une fois le carquois vide', function () {
    $ctx = demarrerQueteAvecMonstre('Momie', ['classe' => 'elfe']);
    $arc = poser($ctx['heros'], 'Arc elfique de Vindication', 'arme_principale');
    $arc->update(['charges' => 0]); // carquois vide
    app(Equipement::class)->recalculerCombat($ctx['heros']->refresh());

    eloignerPour($ctx['quete'], $ctx['instance'],
        (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    desFiges(array_fill(0, 30, 4)); // boucliers blancs : combat neutre
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 30, 4));

    // Plus de mort instantanée : l'attaque repasse par les dés de l'arc (2).
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)
        ->assertJsonMissingPath('resultat.vindication')
        ->assertJsonPath('resultat.des_attaque_effectifs', 2);

    expect((int) $arc->fresh()->charges)->toBe(0); // rien de plus n'a été retiré
});

// ---------------------------------------------------------------------------
// Économie de sorts
// ---------------------------------------------------------------------------

it('le Parchemin de Sorts rend TOUS les sorts épuisés', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $magicien = $ctx['heros'];
    armerDeSorts($magicien);
    epuiserSorts($magicien);

    $parchemin = poser($magicien, 'Parchemin de Sorts', 'consommable');

    // Le nœud Concentration n'en récupère qu'UN, au prix du tour : la
    // différence d'échelle est toute la valeur de cette carte.
    // ⚠ Par le MENU depuis le 2026-09-01 — `POST /potions` n'existe plus.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $magicien->id);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$parchemin->id}"],
    ])->assertAccepted();

    $indisponibles = $magicien->sorts()->wherePivot('disponible', false)->count();

    expect($indisponibles)->toBe(0)
        ->and(Inventaire::find($parchemin->id))->toBeNull(); // consommé
});

it('la Baguette de Galimatias rend les sorts EN L\'ENFILANT, une seule fois', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $magicien = $ctx['heros'];
    armerDeSorts($magicien);
    epuiserSorts($magicien);

    $equipement = app(Equipement::class);
    $ligne = poser($magicien, 'Baguette de Galimatias', 'sac');

    $equipement->equiper($magicien, $ligne);
    expect($magicien->sorts()->wherePivot('disponible', false)->count())->toBe(0);

    // La charge est dépensée : déséquiper puis rééquiper ne redonne rien.
    // Sans elle, la baguette serait une fontaine à sorts.
    epuiserSorts($magicien);
    $equipement->desequiper($magicien->refresh(), $ligne->fresh());
    $equipement->equiper($magicien->refresh(), $ligne->fresh());

    expect($magicien->sorts()->wherePivot('disponible', false)->count())->toBeGreaterThan(0)
        // …et le +2 Mind, lui, est un PASSIF : il revient avec la pièce.
        ->and((int) $magicien->fresh()->pv_mind_max)->toBeGreaterThan((int) $ctx['heros']->pv_mind_max - 2);
});

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

it('le Sceptre de Mémoire épargne le sort sur un bouclier noir, pas autrement', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $magicien = $ctx['heros'];
    armerDeSorts($magicien);
    poser($magicien, 'Sceptre de Mémoire', 'talisman');

    // Le jet du sceptre est consommé APRÈS la résolution du sort : la file est
    // neutre, on ne teste ici que le fait qu'il roule et n'use aucune charge.
    desFiges(array_fill(0, 30, 4));
    [$sortId, $cible] = premierSortCiblable($ctx, $magicien);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sortId}", 'cible_id' => $cible['id'], 'cible_type' => $cible['type'] ?? 'monstre'],
    ])->assertStatus(202)->assertJsonPath('resultat.jet_memoire', 'bouclier_blanc');

    // Le sceptre est illimité — c'est le dé qui limite, pas une charge.
    expect($magicien->fresh()->inventaire()->where('emplacement', 'talisman')->first()->charges)->toBeNull();
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

    // Deux sorts encaissés sans une égratignure, et l'anneau est vide.
    expect((int) $magicien->fresh()->pv_body)->toBe($pvAvant)
        ->and((int) $anneau->fresh()->charges)->toBe(0);

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
