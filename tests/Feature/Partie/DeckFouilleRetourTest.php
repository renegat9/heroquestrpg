<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\BestiaireGroupe;
use App\Partie\Fouille\DeckFouille;
use App\Partie\Sauvegarde;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * LE SORT DES CARTES DE FOUILLE (2026-10-16) — « Return this card to the bottom of
 * the deck » contre « Do not return this card to the deck ». Lu par
 * `DeckFouille::sortDe()`, appliqué par `Quete::piocherCarte()`.
 *
 * Avant cette date, TOUTE carte piochée repartait sous le paquet : l'or et les
 * potions revenaient dans la même quête, et le paquet ne s'épuisait jamais. Ces
 * tests échouent sur ce comportement.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // Même socle que DeckFouilleTest (un test de fouille a besoin des mêmes catalogues).
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class]);
});

/**
 * Quête démarrée avec deux héros (le second empêche la phase des monstres de
 * s'enchaîner). Les monstres du donjon sont vaincus d'emblée : le test porte sur le
 * paquet, pas sur la rencontre, et une pièce occupée refuserait « Fouiller — trésor ».
 *
 * @return array{0: Groupe, 1: Personnage, 2: Quete, 3: EtatPersonnageQuete}
 */
function retourDemarrer(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['pv_body' => 60, 'pv_body_max' => 60]);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2, ['pv_body' => 60, 'pv_body_max' => 60]);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    return [$groupe, $hero, $quete, $etat];
}

/**
 * Salles où « Fouiller — trésor » tire dans le paquet : ni le départ, ni une salle
 * désignée SANS coffre physique (celle-là rend son coffre, sans pioche).
 *
 * @return list<int>
 */
function retourSallesDuPaquet(Quete $quete): array
{
    $grille = $quete->carte->grille;

    return array_values(array_filter(
        array_keys((array) $grille['salles']),
        function (int $salle) use ($quete, $grille): bool {
            if ($salle === 0) {
                return false;
            }

            if (! $quete->estSalleCoffre($salle)) {
                return true;
            }

            $idCoffre = \App\Models\Mobilier::where('nom', 'Coffre')->value('id');

            return collect((array) $grille['mobilier'])
                ->contains(fn ($m) => (int) ($m['mobilier_id'] ?? 0) === (int) $idCoffre && (int) ($m['salle'] ?? -1) === $salle);
        },
    ));
}

/**
 * Pose le fouilleur sur une case de SOL de la salle (la médiane d'abord), la
 * découvre, et régénère son menu : « Fouiller — trésor » est alors offerte.
 */
function retourVersSalle(Quete $quete, EtatPersonnageQuete $etat, int $salle): void
{
    $grille = $quete->carte->grille;
    $s = $grille['salles'][$salle];

    $candidates = [[(int) ($s['mediane_x'] ?? -1), (int) ($s['mediane_y'] ?? -1)]];
    for ($y = (int) $s['y']; $y < (int) $s['y'] + (int) $s['hauteur']; $y++) {
        for ($x = (int) $s['x']; $x < (int) $s['x'] + (int) $s['largeur']; $x++) {
            $candidates[] = [$x, $y];
        }
    }

    $sol = collect($candidates)->first(fn (array $c) => ($grille['cases'][$c[1]][$c[0]] ?? 'm') === 's');
    expect($sol)->not->toBeNull("salle {$salle} sans aucune case de sol");

    $etat->refresh();
    $etat->update([
        'position_x' => $sol[0], 'position_y' => $sol[1],
        'a_joue' => false, 'a_agi' => false, 'a_deplace' => false,
    ]);
    $quete->marquerSalleDecouverte($salle);

    GenererMenu::dispatchSync(
        $quete->groupe_id,
        (int) Personnage::findOrFail($etat->personnage_id)->joueur_id,
        (int) $etat->personnage_id,
    );
}

/** Fouille la salle `$salle` par la VRAIE route et rend le payload `resultat`. */
function retourFouilleDans(Quete $quete, EtatPersonnageQuete $etat, int $salle): array
{
    // Un errant posé à la fouille précédente ne doit pas occuper la pièce suivante.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    retourVersSalle($quete->fresh(), $etat, $salle);

    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->json('resultat');
}

/** Issues du paquet, sommet en tête — ce qui reste à tirer. */
function retourIssues(Quete $quete): array
{
    return collect($quete->fresh()->deckFouille())->pluck('issue')->all();
}

/** Signature d'un paquet : même composition = même signature, quel que soit l'ordre. */
function retourSignature(array $deck): array
{
    return collect($deck)->map(fn (array $c) => json_encode($c))->sort()->values()->all();
}

// ---------------------------------------------------------------------------
// Le registre, dans les deux sens
// ---------------------------------------------------------------------------

it('tient le registre des sorts dans les deux sens : chaque issue que le paquet peut tirer a son sort, et chaque sort déclaré peut sortir', function () {
    [$groupe, , $quete] = retourDemarrer();
    $service = app(DeckFouille::class);
    $carte = $quete->carte->grille;

    $produites = [];

    foreach (GabaritQuete::all() as $gabarit) {
        foreach ([null, BestiaireGroupe::manuel(['wizards_of_morcar']), BestiaireGroupe::auto('jungles_delthrak')] as $theme) {
            foreach ($service->construire($gabarit, $carte, $groupe, 1, $theme)['deck'] as $c) {
                $produites[(string) $c['issue']] = true;
            }
        }
    }

    // Le remplissage « rien » n'apparaît que si le paquet est plus petit que le donjon :
    // aucun gabarit n'y arrive, donc on le force avec un donjon de 40 salles.
    $grand = ['salles' => array_fill(0, 40, ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 3])];
    foreach ($service->construire(GabaritQuete::where('nom', 'Exploration simple')->firstOrFail(), $grand, $groupe, 1)['deck'] as $c) {
        $produites[(string) $c['issue']] = true;
    }

    // Dans les deux sens : une issue produite sans sort, OU un sort déclaré pour une
    // issue que le paquet ne tire jamais, font échouer le test.
    expect(array_keys($produites))->toEqualCanonicalizing(array_keys(DeckFouille::SORT_PAR_ISSUE));
});

it('range chaque issue avec son sort écrit, et une issue inconnue sort du paquet', function (string $issue, string $sort) {
    expect(DeckFouille::sortDe(['issue' => $issue]))->toBe($sort);
})->with([
    'errant (monstre errant : « Return this card to the bottom »)' => ['errant', DeckFouille::REMISE_SOUS_LE_PAQUET],
    'piege (Hazard! et Magical Trap, Poison : même famille)' => ['piege', DeckFouille::REMISE_SOUS_LE_PAQUET],
    'tresor (or, gemme, bijoux : « Do not return »)' => ['tresor', DeckFouille::RETIREE_DU_PAQUET],
    'potion (« Do not return »)' => ['potion', DeckFouille::RETIREE_DU_PAQUET],
    'objet (trésor-valeur de Delthrak, gardé comme l\'or)' => ['objet', DeckFouille::RETIREE_DU_PAQUET],
    'rien (ni carte officielle ni ordre de retour)' => ['rien', DeckFouille::RETIREE_DU_PAQUET],
    'artefact (jamais tiré du paquet : repli sûr)' => ['artefact', DeckFouille::RETIREE_DU_PAQUET],
    'issue inconnue (repli sûr)' => ['inconnue', DeckFouille::RETIREE_DU_PAQUET],
]);

// ---------------------------------------------------------------------------
// En jeu, par les vraies routes
// ---------------------------------------------------------------------------

it('EN JEU — l\'or tiré sort du paquet et ne revient jamais ; l\'errant et le piège, eux, tournent', function () {
    [$groupe, , $quete, $etat] = retourDemarrer();

    $or = ['issue' => 'tresor', 'or' => 25, 'carte' => 'or_25'];
    $quete->update(['deck_fouille' => [$or, ['issue' => 'errant'], ['issue' => 'piege', 'variante' => 'trou']]]);

    $salles = retourSallesDuPaquet($quete->fresh());
    expect(count($salles))->toBeGreaterThanOrEqual(4, 'carte sans quatre salles du paquet');

    $orAvant = (int) $groupe->fresh()->or;

    // 1. L'or : tiré, il sort du paquet.
    $r = retourFouilleDans($quete, $etat, $salles[0]);
    expect($r['issue'])->toBe('tresor')
        ->and($r['or'])->toBe(25)
        ->and(retourIssues($quete))->toBe(['errant', 'piege']);

    // 2. L'errant : tiré, il repart SOUS le paquet.
    $r = retourFouilleDans($quete, $etat, $salles[1]);
    expect($r['issue'])->toBe('errant')
        ->and(retourIssues($quete))->toBe(['piege', 'errant']);

    // 3. Le piège : tiré, il repart SOUS le paquet.
    $r = retourFouilleDans($quete, $etat, $salles[2]);
    expect($r['issue'])->toBe('piege')
        ->and(retourIssues($quete))->toBe(['errant', 'piege']);

    // 4. Le paquet a tourné : l'errant revient, et l'or ne reparaît pas.
    $r = retourFouilleDans($quete, $etat, $salles[3]);
    expect($r['issue'])->toBe('errant')
        ->and(retourIssues($quete))->toBe(['piege', 'errant'])
        ->and((int) $groupe->fresh()->or)->toBe($orAvant + 25);
});

it('EN JEU — une potion tirée sort du paquet ; ne restant que des errants, le paquet ne s\'épuise pas', function () {
    [, $hero, $quete, $etat] = retourDemarrer();

    $potion = Objet::where('nom', 'Potion de soin')->firstOrFail();
    $quete->update(['deck_fouille' => [
        ['issue' => 'potion', 'objet_id' => $potion->id, 'carte' => 'potion_soin'],
        ['issue' => 'errant'],
    ]]);

    $salles = retourSallesDuPaquet($quete->fresh());
    expect(count($salles))->toBeGreaterThanOrEqual(3, 'carte sans trois salles du paquet');

    $r = retourFouilleDans($quete, $etat, $salles[0]);
    expect($r['issue'])->toBe('potion')
        ->and(retourIssues($quete))->toBe(['errant'])
        ->and(Inventaire::where('personnage_id', $hero->id)->where('objet_id', $potion->id)->exists())->toBeTrue();

    // Il ne reste que l'errant : chaque tirage le remet sous le paquet, qui ne s'épuise pas.
    foreach (array_slice($salles, 1, 2) as $salle) {
        $r = retourFouilleDans($quete, $etat, $salle);

        expect($r['issue'])->toBe('errant')
            ->and(retourIssues($quete))->toBe(['errant']);
    }
});

it('EN JEU — une reprise rend le paquet tel qu\'il était à CE snapshot : un or déjà tiré à ce moment n\'y revient pas ; un or tiré depuis, la reprise le remet dans le paquet', function () {
    [$groupe, , $quete, $etat] = retourDemarrer();

    $or = ['issue' => 'tresor', 'or' => 15, 'carte' => 'or_15'];
    $quete->update(['deck_fouille' => [$or, ['issue' => 'errant']]]);

    $salles = retourSallesDuPaquet($quete->fresh());
    expect(count($salles))->toBeGreaterThanOrEqual(2, 'carte sans deux salles du paquet');

    $sauvegarde = app(Sauvegarde::class);
    $avantLOr = $sauvegarde->snapshotter($groupe->fresh(), Sauvegarde::ETIQUETTE_NOUVEAU_TOUR);

    retourFouilleDans($quete, $etat, $salles[0]);
    expect(retourIssues($quete))->toBe(['errant']);

    $apresLOr = $sauvegarde->snapshotter($groupe->fresh(), Sauvegarde::ETIQUETTE_NOUVEAU_TOUR);

    retourFouilleDans($quete, $etat, $salles[1]);

    // Reprise depuis un snapshot POSTÉRIEUR au tirage : l'or reste sorti du paquet.
    $sauvegarde->restaurer($groupe->fresh(), $apresLOr);
    expect(retourSignature($quete->fresh()->deckFouille()))->toBe(retourSignature([['issue' => 'errant']]));

    // Reprise depuis un snapshot ANTÉRIEUR au tirage : l'or est de nouveau dans le paquet.
    $sauvegarde->restaurer($groupe->fresh(), $avantLOr);
    expect(retourSignature($quete->fresh()->deckFouille()))->toBe(retourSignature([$or, ['issue' => 'errant']]));
});

it('EN JEU — paquet vide : « Fouiller — trésor » reste offerte et acceptée, rend « rien », et ne touche à rien', function () {
    [$groupe, $hero, $quete, $etat] = retourDemarrer();

    $quete->update(['deck_fouille' => []]);

    $salles = retourSallesDuPaquet($quete->fresh());
    retourVersSalle($quete->fresh(), $etat, $salles[0]);

    // Le menu OFFRE la fouille…
    GenererMenu::dispatchSync($groupe->id, (int) Personnage::findOrFail($hero->id)->joueur_id, (int) $hero->id);
    $options = (array) data_get(Cache::get(GenererMenu::cleMenu($groupe->id, (int) Personnage::findOrFail($hero->id)->joueur_id)), 'menu.options', []);
    expect(collect($options)->pluck('id')->all())->toContain('fouiller_tresor');

    // …et le résolveur l'ACCEPTE : un paquet vide n'est pas une erreur, c'est un « rien ».
    $r = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->json('resultat');

    expect($r['issue'])->toBe('rien')
        ->and($r['deck_vide'])->toBeTrue()
        ->and(retourIssues($quete))->toBe([])
        ->and((int) $groupe->fresh()->or)->toBe(0);
});
