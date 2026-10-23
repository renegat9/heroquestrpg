<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\ForgeAmelioration;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Forge;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use App\Partie\Talents;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\ForgeAmeliorationSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * LOT B DES POUVOIRS FINAUX (phase 2, 2026-10-23) — la preuve EN JEU de cinq pouvoirs :
 * Tunnelier, Chef-d'œuvre, Mémoire parfaite, Flair du pisteur, Trésor caché.
 *
 * Chaque cas passe par la vraie route (`/menu`, `/choix`, `/forge`, `/moi`) ou par le
 * résolveur, jamais par le catalogue. Les pioches sont posées sur le paquet (`pvxPaquet`)
 * pour que le résultat soit lisible : une carte, un effet.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class,
        SortDreadSeeder::class, ObjetSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class, ForgeAmeliorationSeeder::class,
    ]);
});

// =====================================================================
// Aides — préfixe `pvx` : les fonctions sont globales à toute la suite Pest.
// =====================================================================

/**
 * Une quête lancée pour un héros de la classe donnée, la salle de départ NETTOYÉE (aucun monstre actif révélé,
 * donc fouillable). `$talent` : le nœud acquis, avec sa chaîne de prérequis.
 *
 * @return array{alice: \App\Auth\JoueurAuthentifiable, groupe: Groupe, heros: Personnage, quete: Quete, etat: EtatPersonnageQuete}
 */
function pvxQueteNette(string $classe, ?string $talent = null): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Grom', 1, ['classe' => $classe]);

    if ($talent !== null) {
        donnerTalent($heros, $talent);
    }

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();

    return compact('alice', 'groupe', 'heros', 'quete', 'etat');
}

/** Le menu du héros, tel que la route le rend, après génération (comme en partie). */
function pvxOptions(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return test()->getJson('/api/groupes/table-1/menu?personnage_id='.$ctx['heros']->id)
        ->assertOk()->json('menu.options') ?? [];
}

/** Joue une option du menu par la VRAIE route, après génération du menu. */
function pvxJouer(array $ctx, string $optionId, array $parametres = []): TestResponse
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => $optionId]
        + ($parametres === [] ? [] : ['parametres' => $parametres]));
}

/** Rend au héros ses deux créneaux : un cas qui joue deux actions en a besoin. */
function pvxRejouer(array $ctx): void
{
    $ctx['etat']->fresh()->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);
}

/** Pose des cartes de fouille AU SOMMET du paquet, dans l'ordre donné (la première est tirée la première). */
function pvxPaquet(Quete $quete, array ...$cartes): void
{
    $quete->update(['deck_fouille' => [...$cartes, ...$quete->deckFouille()]]);
    $quete->refresh();
}

/** Remplace le paquet entier : un test qui compte les cartes a besoin d'un paquet dont il connaît la composition. */
function pvxPaquetSeul(Quete $quete, array ...$cartes): void
{
    $quete->update(['deck_fouille' => array_values($cartes)]);
    $quete->refresh();
}

/** La carte du dessous du paquet, lue sur l'état durable. */
function pvxDessous(Quete $quete): array
{
    return collect($quete->fresh()->deckFouille())->last();
}

/** Épuise un sort du grimoire du héros (la liste « à récupérer » se remplit). */
function pvxEpuiserUnSort(Personnage $heros, ?int $sauf = null): object
{
    $sort = $heros->sorts()->when($sauf !== null, fn ($q) => $q->where('sorts.id', '!=', $sauf))
        ->orderBy('sorts.id')->firstOrFail();
    $heros->sorts()->updateExistingPivot($sort->id, ['disponible' => false]);

    return $sort;
}

/** La case de roche (`m`) la plus proche de l'origine de la carte : une fin de mouvement dans la pierre. */
function pvxCaseDeRoche(array $cases): array
{
    foreach ($cases as $y => $ligne) {
        foreach ($ligne as $x => $code) {
            if ($code === 'm') {
                return [(int) $x, (int) $y];
            }
        }
    }

    throw new RuntimeException('Aucune case de roche sur la carte — scénario invalide.');
}

/** Un nain au hub, avec le Chef-d'œuvre et 500 po à la bourse commune. */
function pvxNainAuHub(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donnerTalent($nain, "Chef-d'œuvre");

    return compact('alice', 'groupe', 'nain');
}

/** Une épée courte dans le sac du nain : une pièce forgeable (arme, non unique, non améliorée). */
function pvxSacEpee(Personnage $nain): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $nain->id,
        'objet_id' => Objet::where('nom', 'Épée courte')->firstOrFail()->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
}

/** Le bloc de forge publié par `/moi` pour une pièce du sac. */
function pvxFormeForge(int $inventaireId): array
{
    $sac = collect(test()->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.equipement.sac'));

    return (array) $sac->firstWhere('inventaire_id', $inventaireId);
}

/** Forge par la vraie route, au hub. */
function pvxForger(Personnage $nain, Inventaire $ligne): TestResponse
{
    return test()->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id,
        'inventaire_id' => $ligne->id,
        'amelioration_id' => ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail()->id,
    ]);
}

// =====================================================================
// 1. TUNNELIER (nain·Mine) — l'effet du sort, pour ce tour, au prix de l'action
// =====================================================================

it('Tunnelier — le menu le propose au coût d\'action, et son effet est celui du sort Traverser la Pierre', function () {
    $ctx = pvxQueteNette('nain', 'Tunnelier');

    $option = collect(pvxOptions($ctx))->firstWhere('id', 'tunnelier');
    expect($option)->not->toBeNull()
        ->and($option['creneau'])->toBe('action');

    pvxJouer($ctx, 'tunnelier')->assertStatus(202);

    $heros = $ctx['heros']->fresh();
    $etat = $ctx['etat']->fresh();

    expect(app(MoteurSorts::class)->traverseRoche($heros))->toBeTrue()
        ->and((bool) $etat->a_agi)->toBeTrue()
        ->and((array) $etat->capacites_utilisees)->toContain('Tunnelier');

    // Le buff EST celui du sort : même source, même condition. Rien n'a été recopié.
    expect($heros->conditions()->first()->pivot->source)->toBe('sort:Traverser la Pierre');
});

it('Tunnelier — une seule fois par quête : retiré du menu, refusé au second usage', function () {
    $ctx = pvxQueteNette('nain', 'Tunnelier');

    pvxJouer($ctx, 'tunnelier')->assertStatus(202);
    pvxRejouer($ctx);

    expect(collect(pvxOptions($ctx))->pluck('id')->all())->not->toContain('tunnelier');
    pvxJouer($ctx, 'tunnelier')->assertStatus(422);
});

it('Tunnelier — la pierre se referme à la fin du tour, comme pour le sort', function () {
    $ctx = pvxQueteNette('nain', 'Tunnelier');

    pvxJouer($ctx, 'tunnelier')->assertStatus(202);
    expect(app(MoteurSorts::class)->traverseRoche($ctx['heros']->fresh()))->toBeTrue();

    pvxJouer($ctx, 'attendre')->assertStatus(202);

    expect(app(MoteurSorts::class)->traverseRoche($ctx['heros']->fresh()))->toBeFalse();
});

it('Tunnelier — finir son tour dans la roche fait tomber le héros, comme le sort', function () {
    $ctx = pvxQueteNette('nain', 'Tunnelier');
    pvxJouer($ctx, 'tunnelier')->assertStatus(202);

    [$x, $y] = pvxCaseDeRoche($ctx['quete']->fresh()->carte->grille['cases']);
    $ctx['etat']->fresh()->update(['position_x' => $x, 'position_y' => $y]);

    pvxJouer($ctx, 'attendre')->assertStatus(202);

    expect((bool) $ctx['etat']->fresh()->tombe)->toBeTrue()
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(0);
});

it('Tunnelier — sans le nœud, rien n\'est offert', function () {
    $ctx = pvxQueteNette('nain');

    expect(collect(pvxOptions($ctx))->pluck('id')->all())->not->toContain('tunnelier');
});

// =====================================================================
// 2. CHEF-D'ŒUVRE (nain·Forge) — une amélioration gratuite à chaque passage au hub
// =====================================================================

it('Chef-d\'œuvre — au hub qui suit une quête, l\'amélioration est publiée à 0 et posée sans débit', function () {
    ['groupe' => $groupe, 'nain' => $nain] = pvxNainAuHub();
    rendreGardien($groupe, 1);
    $ligne = pvxSacEpee($nain);

    $forme = pvxFormeForge($ligne->id);
    expect(collect($forme['forge_catalogue'])->every(fn ($o) => $o['prix'] === 0 && $o['gratuite'] === true))->toBeTrue();

    pvxForger($nain, $ligne)->assertCreated()
        ->assertJsonPath('gratuite', true)
        ->assertJsonPath('groupe.or', 500);

    expect($nain->fresh()->chef_oeuvre_quete_id)->toBe(app(Forge::class)->passageCourant($groupe->fresh()));
});

it('Chef-d\'œuvre — une seule fois par passage : la seconde amélioration est payée au prix du catalogue', function () {
    ['groupe' => $groupe, 'nain' => $nain] = pvxNainAuHub();
    rendreGardien($groupe, 1);
    $premiere = pvxSacEpee($nain);
    $seconde = pvxSacEpee($nain);
    $prix = (int) ForgeAmelioration::where('nom', 'Affûtée')->value('prix');

    pvxForger($nain, $premiere)->assertCreated()->assertJsonPath('gratuite', true);

    expect((int) $groupe->fresh()->or)->toBe(500);
    $catalogue = collect(pvxFormeForge($seconde->id)['forge_catalogue']);
    expect($catalogue->every(fn ($o) => $o['gratuite'] === false && $o['prix'] > 0))->toBeTrue()
        ->and((int) $catalogue->firstWhere('nom', 'Affûtée')['prix'])->toBe($prix);

    pvxForger($nain, $seconde)->assertCreated()
        ->assertJsonPath('gratuite', false)
        ->assertJsonPath('groupe.or', 500 - $prix);
});

it('Chef-d\'œuvre — un nouveau passage, après une nouvelle quête, rouvre la gratuité', function () {
    ['groupe' => $groupe, 'nain' => $nain] = pvxNainAuHub();
    rendreGardien($groupe, 1);
    pvxForger($nain, pvxSacEpee($nain))->assertCreated()->assertJsonPath('gratuite', true);

    rendreGardien($groupe, 1); // une seconde quête achevée : un nouveau passage au hub.

    pvxForger($nain, pvxSacEpee($nain))->assertCreated()->assertJsonPath('gratuite', true);
    expect((int) $groupe->fresh()->or)->toBe(500);
});

it('Chef-d\'œuvre — le hub qui précède la première quête n\'est pas un passage : on paie', function () {
    ['groupe' => $groupe, 'nain' => $nain] = pvxNainAuHub();
    $ligne = pvxSacEpee($nain);

    $forme = pvxFormeForge($ligne->id);
    expect(collect($forme['forge_catalogue'])->every(fn ($o) => $o['gratuite'] === false && $o['prix'] > 0))->toBeTrue();

    pvxForger($nain, $ligne)->assertCreated()->assertJsonPath('gratuite', false);
    expect((int) $groupe->fresh()->or)->toBeLessThan(500);
});

// =====================================================================
// 3. MÉMOIRE PARFAITE (magicien·Érudition) — un geste libre, une fois par quête
// =====================================================================

/** Un magicien en quête, avec son grimoire (trois sorts de feu) et le nœud acquis. */
function pvxMagicien(): array
{
    $ctx = pvxQueteNette('magicien', 'Mémoire parfaite');
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'feu');

    return $ctx;
}

it('Mémoire parfaite — récupère un sort épuisé sans passer le tour ni l\'action', function () {
    $ctx = pvxMagicien();
    $sort = pvxEpuiserUnSort($ctx['heros']);

    $option = collect(pvxOptions($ctx))->firstWhere('id', 'memoire_parfaite');
    expect($option)->not->toBeNull()
        ->and($option['creneau'])->toBe('interaction');

    $cle = collect($option['parametres']['sorts'])->firstWhere('sort_id', $sort->id)['cle'];

    pvxJouer($ctx, 'memoire_parfaite', ['cle' => $cle])->assertStatus(202)
        ->assertJsonPath('resultat.geste_libre', true);

    expect((bool) DB::table('personnage_sorts')->where('personnage_id', $ctx['heros']->id)->where('sort_id', $sort->id)->value('disponible'))
        ->toBeTrue()
        ->and((bool) $ctx['etat']->fresh()->a_joue)->toBeFalse()
        ->and((bool) $ctx['etat']->fresh()->a_agi)->toBeFalse()
        ->and((array) $ctx['etat']->fresh()->capacites_utilisees)->toContain('Mémoire parfaite')
        ->and((array) $ctx['etat']->fresh()->capacites_utilisees)->not->toContain('Concentration');
});

it('Mémoire parfaite — sa fenêtre est la sienne : Se concentrer reste offert, et se joue, après elle', function () {
    $ctx = pvxMagicien();
    $premier = pvxEpuiserUnSort($ctx['heros']);
    $cle = collect(collect(pvxOptions($ctx))->firstWhere('id', 'memoire_parfaite')['parametres']['sorts'])
        ->firstWhere('sort_id', $premier->id)['cle'];
    pvxJouer($ctx, 'memoire_parfaite', ['cle' => $cle])->assertStatus(202);

    $magie = app(MoteurSorts::class);
    expect($magie->concentrationDisponible($ctx['heros']->fresh(), $ctx['etat']->fresh()))->toBeTrue()
        ->and($magie->memoireDisponible($ctx['heros']->fresh(), $ctx['etat']->fresh()))->toBeFalse();

    // Se concentrer a sa propre fenêtre : elle se joue, elle sacrifie le tour, et Mémoire reste épuisée.
    $autre = pvxEpuiserUnSort($ctx['heros'], sauf: $premier->id);
    pvxRejouer($ctx);
    $cleConc = collect(collect(pvxOptions($ctx))->firstWhere('id', 'se_concentrer')['parametres']['sorts'])
        ->firstWhere('sort_id', $autre->id)['cle'];
    pvxJouer($ctx, 'se_concentrer', ['cle' => $cleConc])->assertStatus(202);

    expect($magie->concentrationDisponible($ctx['heros']->fresh(), $ctx['etat']->fresh()))->toBeFalse()
        ->and($magie->memoireDisponible($ctx['heros']->fresh(), $ctx['etat']->fresh()))->toBeFalse();
});

it('Mémoire parfaite — une fois par quête : retirée du menu, refusée au second usage', function () {
    $ctx = pvxMagicien();
    $premier = pvxEpuiserUnSort($ctx['heros']);
    $cle = collect(collect(pvxOptions($ctx))->firstWhere('id', 'memoire_parfaite')['parametres']['sorts'])
        ->firstWhere('sort_id', $premier->id)['cle'];
    pvxJouer($ctx, 'memoire_parfaite', ['cle' => $cle])->assertStatus(202);

    $autre = pvxEpuiserUnSort($ctx['heros'], sauf: $premier->id);
    $cleAutre = "sort:{$autre->id}";

    expect(collect(pvxOptions($ctx))->pluck('id')->all())->not->toContain('memoire_parfaite');
    pvxJouer($ctx, 'memoire_parfaite', ['cle' => $cleAutre])->assertStatus(422);
});

// =====================================================================
// 4. FLAIR DU PISTEUR (explorateur·Traque) — une fouille négative est refaite
// =====================================================================

it('Flair du pisteur — un piège tiré est écarté : il ne se déclenche pas, et la carte suivante est gardée', function () {
    $ctx = pvxQueteNette('explorateur', 'Flair du pisteur');
    // Le Sixième sens (innée de l'explorateur, une fois par tour) repiocherait ce piège avant le Flair : il est
    // déjà dépensé ici, pour que seul le Flair soit mis à l'épreuve.
    $ctx['etat']->update(['capacites_tour' => ['Sixième sens']]);
    pvxPaquet($ctx['quete'], ['issue' => 'piege'], ['issue' => 'tresor', 'or' => 15, 'carte' => 'or15']);
    $pv = (int) $ctx['heros']->fresh()->pv_body;

    $resultat = pvxJouer($ctx, 'fouiller_tresor')->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('tresor')
        ->and($resultat)->not->toHaveKey('declenchement')
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($pv);

    // La carte écartée a sa place habituelle : sous le paquet (`DeckFouille::SORT_PAR_ISSUE`).
    expect(pvxDessous($ctx['quete'])['issue'])->toBe('piege');

    // Et le talent se VOIT : le fil reçoit l'annonce, pas seulement le résultat.
    expect(collect($resultat['talents_declenches'] ?? [])->pluck('mecanique')->all())->toContain('refaire_fouille_negative');
});

it('Flair du pisteur — un monstre errant écarté ne surgit pas, et la fouille reprend', function () {
    $ctx = pvxQueteNette('explorateur', 'Flair du pisteur');
    $avant = InstanceMonstre::where('quete_id', $ctx['quete']->id)->count();
    pvxPaquet($ctx['quete'], ['issue' => 'errant'], ['issue' => 'tresor', 'or' => 15, 'carte' => 'or15']);

    $resultat = pvxJouer($ctx, 'fouiller_tresor')->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('tresor')
        ->and($resultat)->not->toHaveKey('monstre')
        ->and(InstanceMonstre::where('quete_id', $ctx['quete']->id)->count())->toBe($avant);
});

it('Flair du pisteur — la seconde carte est GARDÉE, même négative : un piège tiré en reprise se déclenche', function () {
    $ctx = pvxQueteNette('explorateur', 'Flair du pisteur');
    // Le Sixième sens s'applique à chaque tirage, reprise comprise : déjà dépensé ici, pour isoler la règle du Flair.
    $ctx['etat']->update(['capacites_tour' => ['Sixième sens']]);
    pvxPaquet($ctx['quete'], ['issue' => 'rien'], ['issue' => 'piege']);

    $resultat = pvxJouer($ctx, 'fouiller_tresor')->assertStatus(202)->json('resultat');

    expect($resultat)->toHaveKey('declenchement');
});

it('sans Flair du pisteur, la même pioche déclenche le piège : le talent est la seule cause du changement', function () {
    $ctx = pvxQueteNette('barbare');
    pvxPaquet($ctx['quete'], ['issue' => 'piege'], ['issue' => 'tresor', 'or' => 15, 'carte' => 'or15']);

    $resultat = pvxJouer($ctx, 'fouiller_tresor')->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('piege')
        ->and($resultat)->toHaveKey('declenchement');
});

it('Flair du pisteur — un piège écarté remonte sous le paquet une seule fois, et la reprise sort du paquet', function () {
    $ctx = pvxQueteNette('explorateur', 'Flair du pisteur');
    $ctx['etat']->update(['capacites_tour' => ['Sixième sens']]);
    pvxPaquetSeul($ctx['quete'], ['issue' => 'piege', 'carte' => 'A'], ['issue' => 'rien', 'carte' => 'R'], ['issue' => 'tresor', 'or' => 25, 'carte' => 'B'], ['issue' => 'tresor', 'or' => 15, 'carte' => 'C']);

    pvxJouer($ctx, 'fouiller_tresor')->assertStatus(202);

    // A (piège) est tiré puis écarté : il remonte sous le paquet, une seule fois. R, tiré en reprise, est gardé et sort
    // du paquet (un « rien » n'y revient pas). Le reste attend dans l'ordre.
    $paquet = collect($ctx['quete']->fresh()->deckFouille());
    expect($paquet->pluck('carte')->all())->toBe(['B', 'C', 'A'])
        ->and($paquet->where('issue', 'piege'))->toHaveCount(1);
});

it('Flair du pisteur — la règle est la même pour un meuble : une table négative est tirée à nouveau une fois', function () {
    $ctx = pvxQueteNette('explorateur', 'Flair du pisteur');
    $tirages = [['issue' => 'rien'], ['issue' => 'tresor', 'or' => 25, 'carte' => 'meuble']];
    $appels = 0;
    $tirer = function () use (&$appels, $tirages): array {
        return $tirages[$appels++];
    };

    $methode = new ReflectionMethod(ResolveurTour::class, 'piocherAvecFlair');
    [$carte] = $methode->invoke(app(ResolveurTour::class), $ctx['heros']->fresh(), $ctx['etat']->fresh(), $tirer);

    expect($appels)->toBe(2)
        ->and($carte['carte'])->toBe('meuble');
});

it('Flair du pisteur — un trésor dont l\'objet n\'existe pas au catalogue est un résultat négatif, refait une fois', function () {
    $ctx = pvxQueteNette('explorateur', 'Flair du pisteur');
    $tirages = [['issue' => 'potion', 'objet_id' => 987654, 'carte' => 'fantome'], ['issue' => 'tresor', 'or' => 25, 'carte' => 'reel']];
    $appels = 0;
    $tirer = function () use (&$appels, $tirages): array {
        return $tirages[$appels++];
    };

    $methode = new ReflectionMethod(ResolveurTour::class, 'piocherAvecFlair');
    [$carte] = $methode->invoke(app(ResolveurTour::class), $ctx['heros']->fresh(), $ctx['etat']->fresh(), $tirer);

    expect($appels)->toBe(2)
        ->and($carte['carte'])->toBe('reel');
});

// =====================================================================
// 5. TRÉSOR CACHÉ (explorateur·Butin) — deux cartes, la meilleure gardée
// =====================================================================

it('Trésor caché — deux cartes tirées, la meilleure est gardée, l\'autre retourne sous le paquet', function () {
    $ctx = pvxQueteNette('explorateur', 'Trésor caché');
    $potion = Objet::where('nom', 'Potion de guérison')->firstOrFail()->id;
    pvxPaquet($ctx['quete'], ['issue' => 'tresor', 'or' => 15, 'carte' => 'or15'], ['issue' => 'potion', 'objet_id' => $potion, 'carte' => 'soin']);

    $resultat = pvxJouer($ctx, 'fouiller_tresor_cache')->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('potion')
        ->and($resultat['tresor_cache'])->toBeTrue();

    // Le trésor de 15 po, écarté, n'a pas disparu : il retourne sous le paquet.
    expect(pvxDessous($ctx['quete'])['carte'])->toBe('or15');
});

it('Trésor caché — à égalité de rang, le trésor le plus riche en or est gardé', function () {
    $ctx = pvxQueteNette('explorateur', 'Trésor caché');
    pvxPaquet($ctx['quete'], ['issue' => 'tresor', 'or' => 15, 'carte' => 'petit'], ['issue' => 'tresor', 'or' => 25, 'carte' => 'gros']);

    pvxJouer($ctx, 'fouiller_tresor_cache')->assertStatus(202);

    // Le gros est gardé : c'est le petit qui retourne sous le paquet.
    expect(pvxDessous($ctx['quete'])['carte'])->toBe('petit');
});

it('Trésor caché — à égalité de valeur, la PREMIÈRE carte tirée est gardée', function () {
    $ctx = pvxQueteNette('explorateur', 'Trésor caché');
    pvxPaquet($ctx['quete'], ['issue' => 'tresor', 'or' => 25, 'carte' => 'premiere'], ['issue' => 'tresor', 'or' => 25, 'carte' => 'seconde']);

    pvxJouer($ctx, 'fouiller_tresor_cache')->assertStatus(202);

    expect(pvxDessous($ctx['quete'])['carte'])->toBe('seconde');
});

it('Trésor caché — une fois par quête : le nœud est consommé, et il n\'est plus offert', function () {
    $ctx = pvxQueteNette('explorateur', 'Trésor caché');
    pvxPaquet($ctx['quete'], ['issue' => 'rien'], ['issue' => 'rien']);

    pvxJouer($ctx, 'fouiller_tresor_cache')->assertStatus(202);

    expect((array) $ctx['etat']->fresh()->capacites_utilisees)->toContain('Trésor caché')
        ->and(app(Talents::class)->disponible($ctx['heros']->fresh(), $ctx['etat']->fresh(), 'fouille_meilleure_carte'))->toBeFalse()
        ->and(collect(pvxOptions($ctx))->pluck('id')->all())->not->toContain('fouiller_tresor_cache');
});

it('Trésor caché — la carte gardée négative est refaite une fois par le Flair, et le paquet ne duplique rien', function () {
    $ctx = pvxQueteNette('explorateur', 'Trésor caché');
    // Le Flair vient avec le nœud Trésor caché : il faut les deux, dans le même héros.
    donnerTalent($ctx['heros'], 'Flair du pisteur');
    pvxPaquetSeul(
        $ctx['quete'],
        ['issue' => 'rien', 'carte' => 'R1'],   // tiré en premier : c'est elle qui est gardée (à égalité de rang)…
        ['issue' => 'rien', 'carte' => 'R2'],   // …tiré en second : écartée, elle retourne sous le paquet, une fois
        ['issue' => 'tresor', 'or' => 25, 'carte' => 'B'],
        ['issue' => 'tresor', 'or' => 15, 'carte' => 'C'],
    );

    $resultat = pvxJouer($ctx, 'fouiller_tresor_cache')->assertStatus(202)->json('resultat');

    // R1 est négative : le Flair la refait. La reprise, B, est gardée quelle qu'elle soit.
    expect($resultat['issue'])->toBe('tresor');

    // Le paquet : B est sorti (gardé), R2 est remonté une seule fois, C attend en tête.
    $paquet = collect($ctx['quete']->fresh()->deckFouille());
    expect($paquet->pluck('carte')->all())->toBe(['C', 'R2'])
        ->and($paquet->where('carte', 'R2'))->toHaveCount(1);
});

// =====================================================================
// 6. Le lot est déclaré dans le registre, dans les deux sens (contrôle croisé, sans second catalogue)
// =====================================================================

it('les cinq pouvoirs du lot B ont un lecteur déclaré, et les quatre mécaniques neuves sont portées par un nœud', function () {
    $portees = \App\Models\Competence::all()->map(fn ($c) => $c->effet['mecanique'] ?? null)->all();

    foreach (['tunnelier', 'forge_gratuite', 'refaire_fouille_negative', 'fouille_meilleure_carte'] as $mecanique) {
        expect(\App\Engine\MotsClesTalent::connue($mecanique))->toBeTrue("« {$mecanique} » n'est pas déclarée.");
        expect(in_array($mecanique, $portees, true))->toBeTrue("« {$mecanique} » n'est portée par aucun nœud.");
    }
});
