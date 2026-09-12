<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MoteurPotions;
use App\Partie\MoteurSorts;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * CIBLE ADJACENTE POUR LES POTIONS (René, 2026-09-11, en jouant) :
 * « lorsqu'on utilise une potion, il faudrait pouvoir cibler le joueur actuel
 * OU un joueur adjacent ». `MotsClesSort::CIBLE_HEROS_ADJACENT`.
 *
 * ⚠ LE POINT QUI COMPTE : la restriction de classe suit qui BOIT, pas qui
 * sort la fiole du sac (`MoteurPotions::boire()`, 4ᵉ paramètre). Testé dans
 * les DEUX sens — Krogar (barbare) → Aldric (magicien) refusé, Aldric →
 * Krogar accepté — parce que le code d'avant aurait inversé les deux résultats.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

/** Met une potion au sac de $porteur. */
function potionAuSacDe(Personnage $porteur, string $nom): App\Models\Inventaire
{
    return App\Models\Inventaire::create([
        'personnage_id' => $porteur->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
}

/** L'entrée « utiliser_objet » du menu de $acteur pour cette ligne. */
function entreeObjetDe(App\Models\Groupe $groupe, App\Auth\JoueurAuthentifiable $joueur, Personnage $acteur, App\Models\Inventaire $ligne): ?array
{
    GenererMenu::dispatchSync($groupe->id, (int) $joueur->id, (int) $acteur->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $joueur->id));
    $option = collect($menu['menu']['options'] ?? [])->firstWhere('id', 'utiliser_objet');

    return collect($option['parametres']['objets'] ?? [])->firstWhere('cle', "objet:{$ligne->id}");
}

/** Groupe à deux héros, quête démarrée, positions adjacentes garanties. */
function deuxHerosAdjacents(string $classeA, string $classeB): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $krogar = creerHeros($alice, $groupe, 'Krogar', 1, ['classe' => $classeA]);
    $aldric = creerHeros($alice, $groupe, 'Aldric', 2, ['classe' => $classeB]);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $etatKrogar = $quete->etatsPersonnages()->where('personnage_id', $krogar->id)->firstOrFail();
    $etatAldric = $quete->etatsPersonnages()->where('personnage_id', $aldric->id)->firstOrFail();

    $etatAldric->update([
        'position_x' => (int) $etatKrogar->position_x + 1,
        'position_y' => (int) $etatKrogar->position_y,
    ]);

    return compact('alice', 'groupe', 'quete', 'krogar', 'aldric', 'etatKrogar', 'etatAldric');
}

it('non-régression : un héros SEUL continue de se boire sa potion via le menu', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $heros->update(['pv_body' => 4]);
    $ligne = potionAuSacDe($heros, 'Potion de soin mineur');

    $entree = entreeObjetDe($groupe, $alice, $heros, $ligne);

    // Seul au monde : la liste ne contient QUE lui-même.
    expect($entree)->not->toBeNull()
        ->and(collect($entree['cibles'] ?? [])->pluck('id')->all())->toBe([$heros->id]);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $heros->id, 'cible_type' => 'heros'],
    ])->assertAccepted()->assertJsonPath('resultat.potion.personnage_id', $heros->id);

    expect((int) $heros->fresh()->pv_body)->toBe(6) // +2, plafonné à 8
        ->and(App\Models\Inventaire::find($ligne->id))->toBeNull();
});

it('un héros peut tendre sa potion à un VOISIN orthogonal', function () {
    $ctx = deuxHerosAdjacents('barbare', 'magicien');
    ['groupe' => $groupe, 'alice' => $alice, 'krogar' => $krogar, 'aldric' => $aldric] = $ctx;

    $aldric->update(['pv_body' => 2]);
    $ligne = potionAuSacDe($krogar, 'Potion de soin mineur');

    $entree = entreeObjetDe($groupe, $alice, $krogar, $ligne);

    expect($entree)->not->toBeNull()
        ->and(collect($entree['cibles'] ?? [])->pluck('id')->all())
        ->toEqualCanonicalizing([$krogar->id, $aldric->id]);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $aldric->id, 'cible_type' => 'heros'],
    ])->assertAccepted()
        ->assertJsonPath('resultat.potion.personnage_id', $aldric->id)
        ->assertJsonPath('resultat.potion.porteur_id', $krogar->id);

    // ⚠ L'EFFET ATTERRIT SUR LA CIBLE, jamais sur le porteur.
    expect((int) $aldric->fresh()->pv_body)->toBe(4) // +2
        ->and((int) $krogar->fresh()->pv_body)->toBe($krogar->pv_body_max)
        // La fiole part du sac du PORTEUR.
        ->and(App\Models\Inventaire::find($ligne->id))->toBeNull();
});

it('ne peut PAS cibler un héros à deux cases ni en diagonale', function () {
    $ctx = deuxHerosAdjacents('barbare', 'magicien');
    ['groupe' => $groupe, 'alice' => $alice, 'krogar' => $krogar, 'aldric' => $aldric, 'etatAldric' => $etatAldric, 'etatKrogar' => $etatKrogar] = $ctx;

    // Deux cases plus loin (même axe) : hors adjacence.
    $etatAldric->update(['position_x' => (int) $etatKrogar->position_x + 2, 'position_y' => (int) $etatKrogar->position_y]);

    $ligne = potionAuSacDe($krogar, 'Potion de soin mineur');
    $entree = entreeObjetDe($groupe, $alice, $krogar, $ligne);

    expect(collect($entree['cibles'] ?? [])->pluck('id')->all())->toBe([$krogar->id]);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $aldric->id, 'cible_type' => 'heros'],
    ])->assertStatus(422);

    // En diagonale (distance 1 sur les deux axes) : toujours hors adjacence,
    // l'adjacence de ce ciblage est ORTHOGONALE (`Grille::sontAdjacentes()`,
    // `$diagonales` par défaut à `false`).
    $etatAldric->update([
        'position_x' => (int) $etatKrogar->position_x + 1,
        'position_y' => (int) $etatKrogar->position_y + 1,
    ]);

    $entreeDiag = entreeObjetDe($groupe, $alice, $krogar, $ligne);
    expect(collect($entreeDiag['cibles'] ?? [])->pluck('id')->all())->toBe([$krogar->id]);
});

it('un héros TOMBÉ adjacent n\'est pas une cible légale (pas de tombeAdmis ici)', function () {
    $ctx = deuxHerosAdjacents('barbare', 'magicien');
    ['groupe' => $groupe, 'alice' => $alice, 'krogar' => $krogar, 'aldric' => $aldric, 'etatAldric' => $etatAldric] = $ctx;

    $aldric->update(['pv_body' => 0]);
    $etatAldric->update(['tombe' => true]);

    $ligne = potionAuSacDe($krogar, 'Potion de soin mineur');
    $entree = entreeObjetDe($groupe, $alice, $krogar, $ligne);

    expect(collect($entree['cibles'] ?? [])->pluck('id')->all())->toBe([$krogar->id]);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $aldric->id, 'cible_type' => 'heros'],
    ])->assertStatus(422);
});

// ⚠ Les deux tests suivants forment une PAIRE délibérée : même potion, mêmes
// deux classes, ordre d'acteur inversé. Chacun ne fait agir QUE le héros dont
// c'est le tour (l'initiative est figée pour la quête, doc 03 §…) — c'est
// TOUJOURS le PORTEUR qui « Utilise un objet », jamais le destinataire, donc
// c'est toujours le porteur qui doit être en tête d'initiative dans le test.
// Le code d'AVANT ce correctif aurait inversé les deux verdicts.

it('LE POINT QUI COMPTE : un porteur légitime ne peut PAS faire boire un voisin d\'une autre classe', function () {
    // Krogar (Barbare, légitime, ordre 1 — c'est SON tour) tend sa Potion de
    // rage guerrière à Aldric (magicien, ordre 2) adjacent : la carte dit
    // « Only the Barbarian can drink this » — c'est ALDRIC qui boirait, donc
    // c'est LUI qu'elle refuse, même si le PORTEUR est parfaitement légitime.
    $ctx = deuxHerosAdjacents('barbare', 'magicien');
    ['groupe' => $groupe, 'alice' => $alice, 'krogar' => $krogar, 'aldric' => $aldric, 'etatAldric' => $etatAldric] = $ctx;

    $ligne = potionAuSacDe($krogar, 'Potion de rage guerrière');
    $entree = entreeObjetDe($groupe, $alice, $krogar, $ligne);

    // Le menu ne l'offre même pas : Aldric n'apparaît pas dans la liste
    // blanche (« le menu n'offre jamais ce que le résolveur va refuser »).
    expect(collect($entree['cibles'] ?? [])->pluck('id')->all())->toBe([$krogar->id]);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $aldric->id, 'cible_type' => 'heros'],
    ])->assertStatus(422);
    expect((bool) $etatAldric->fresh()->attaque_supplementaire)->toBeFalse();

    // Même en court-circuitant le menu pour appeler le moteur directement
    // avec Aldric en cible : `boire()` refuse pour SA classe, pas celle de Krogar.
    expect(fn () => app(MoteurPotions::class)->boire($krogar, $ligne->fresh(), [], $aldric))
        ->toThrow(ValidationException::class, "« Potion de rage guerrière » n'est pas pour un magicien.");
});

it('LE MIROIR : un porteur de la MAUVAISE classe peut tendre la potion à un voisin qui, lui, peut la boire', function () {
    // Ordre d'acteur inversé : c'est cette fois le MAGICIEN (ordre 1 — son
    // tour) qui porte la potion — trouvée en fouille, par exemple — et la
    // tend au BARBARE (ordre 2) adjacent. L'ANCIEN code, qui vérifiait le
    // PORTEUR plutôt que le buveur, aurait refusé ceci à tort : le magicien
    // n'a pas la classe requise, mais ce n'est pas lui qui boit.
    $ctx = deuxHerosAdjacents('magicien', 'barbare');
    ['groupe' => $groupe, 'alice' => $alice, 'krogar' => $porteur, 'aldric' => $buveur, 'etatAldric' => $etatBuveur] = $ctx;

    $ligne = potionAuSacDe($porteur, 'Potion de rage guerrière');
    $entree = entreeObjetDe($groupe, $alice, $porteur, $ligne);

    expect(collect($entree['cibles'] ?? [])->pluck('id')->all())->toBe([$buveur->id]);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $buveur->id, 'cible_type' => 'heros'],
    ])->assertAccepted()->assertJsonPath('resultat.potion.personnage_id', $buveur->id);

    expect((bool) $etatBuveur->fresh()->attaque_supplementaire)
        ->toBeTrue('le drapeau doit atterrir sur le Barbare, qui a bu — pas sur le magicien, qui a servi');
});

it('la Potion de vision (Elfe) — citée par René — a du sens tendue à un voisin', function () {
    $ctx = deuxHerosAdjacents('nain', 'elfe');
    ['groupe' => $groupe, 'alice' => $alice, 'krogar' => $nain, 'aldric' => $elfe] = $ctx;

    // Le Nain porte la fiole (trouvée en chemin), l'Elfe adjacent la boit :
    // seul LUI peut, et c'est bien LUI que `ciblesObjet()` doit proposer.
    $ligne = potionAuSacDe($nain, 'Potion de vision');
    $entree = entreeObjetDe($groupe, $alice, $nain, $ligne);

    expect(collect($entree['cibles'] ?? [])->pluck('id')->all())->toBe([$elfe->id]);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $elfe->id, 'cible_type' => 'heros'],
    ])->assertAccepted()->assertJsonPath('resultat.potion.personnage_id', $elfe->id);

    expect(app(MoteurSorts::class)->aBuff($elfe->fresh(), 'revele_pieges_et_portes_en_vue'))->toBeTrue()
        ->and(app(MoteurSorts::class)->aBuff($nain->fresh(), 'revele_pieges_et_portes_en_vue'))->toBeFalse();
});
