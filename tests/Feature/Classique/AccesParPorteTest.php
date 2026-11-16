<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * ENTRER ET SORTIR PAR UNE PORTE (René, 2026-11-14 : « dans certaines extensions ce sont des portes qui font l'entrée
 * et la sortie en quête »). Un ACCÈS — `depart`, `sortie` — est un escalier ou une porte sur le mur d'une salle ouverte.
 *
 *  1. Entrée par une porte : la carte n'a pas d'escalier, les héros partent de la case devant la porte.
 *  2. Une porte d'accès n'est PAS une porte du donjon : son bord reste muré et elle n'entre pas dans `carte.portes` —
 *     rien, ni la main, ni une clé, ni un sort, ne peut l'ouvrir.
 *  3. La sortie est distincte de l'entrée quand la quête le dit ; sinon on sort par où l'on est entré.
 *  4. EN JEU : on ne quitte le donjon que depuis la case de SORTIE, objectif accompli.
 *
 * Plateau et quête : ceux de `DocumentExemple`, INVENTÉS. `entree` = 2..6 × 2..5, `galerie` = 7..10 × 2..5 (elles se touchent).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

/** L'exemple, où l'on entre par une porte à l'ouest de `entree` et où l'on sort par une porte au sud de `galerie`. */
function campagneAPortes(?array $sortie = ['type' => 'porte', 'x' => 10, 'y' => 5, 'cote' => 's']): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['depart'] = ['type' => 'porte', 'x' => 2, 'y' => 3, 'cote' => 'o'];
    $campagne['quetes'][0]['sortie'] = $sortie;

    return $campagne;
}

function refusDAcces(array $campagne): array
{
    return app(ValidationCampagne::class)->valider($campagne);
}

it('entre par une porte : pas d\'escalier, les héros partent de la case devant elle, son bord reste muré', function () {
    $campagne = campagneAPortes();
    expect(refusDAcces($campagne))->toBe([]);

    $produit = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0]);
    $carte = $produit['carte'];

    expect($carte['escalier'])->toBeNull()
        ->and($carte['spawn_heros'][0])->toBe(['x' => 2, 'y' => 3])
        // Les héros suivants s'installent au plus près, dans la salle d'entrée.
        ->and(max(abs($carte['spawn_heros'][1]['x'] - 2), abs($carte['spawn_heros'][1]['y'] - 3)))->toBe(1)
        // Le bord ouest de (2, 3) et le bord sud de (10, 5) restent MURÉS : ce sont des portes vers le dehors…
        ->and(collect($carte['murs'])->contains(['x' => 1, 'y' => 3, 'cote' => 'e']))->toBeTrue()
        ->and(collect($carte['murs'])->contains(['x' => 10, 'y' => 5, 'cote' => 's']))->toBeTrue()
        // …et aucune des deux n'est une porte du donjon : les trois portes ordinaires de la quête, rien de plus.
        ->and($carte['portes'])->toHaveCount(3);

    // Chaque porte d'accès est le bord `cote` de sa case : c'est là que l'écran la dessine, sans rien recalculer.
    expect($produit['acces']['entree'])->toBe(['type' => 'porte', 'cases' => [['x' => 2, 'y' => 3]],
        'porte' => ['x' => 2, 'y' => 3, 'cote' => 'o']])
        ->and($produit['acces']['sortie'])->toBe(['type' => 'porte', 'cases' => [['x' => 10, 'y' => 5]],
            'porte' => ['x' => 10, 'y' => 5, 'cote' => 's']]);
});

it('sort par où l\'on est entré quand la quête ne déclare pas de sortie, par porte comme par escalier', function () {
    $parPorte = campagneAPortes(null);
    $produit = app(CarteConstruite::class)->produire($parPorte, $parPorte['quetes'][0]);
    expect($produit['acces']['sortie'])->toBe($produit['acces']['entree']);

    $parEscalier = DocumentExemple::campagne();
    $produit = app(CarteConstruite::class)->produire($parEscalier, $parEscalier['quetes'][0]);
    expect($produit['acces']['sortie']['type'])->toBe('escalier')
        ->and($produit['acces']['sortie']['cases'])->toHaveCount(4)
        ->and($produit['carte']['escalier'])->toBe(['x' => 2, 'y' => 4, 'l' => 2, 'h' => 2]);
});

it('accepte un escalier de sortie distinct de l\'escalier d\'entrée', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['sortie'] = ['type' => 'escalier', 'x' => 9, 'y' => 4];
    expect(refusDAcces($campagne))->toBe([]);

    $produit = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0]);
    expect($produit['acces']['sortie']['cases'])->toBe([['x' => 9, 'y' => 4], ['x' => 10, 'y' => 4], ['x' => 9, 'y' => 5], ['x' => 10, 'y' => 5]])
        ->and(app(CarteConstruite::class)->apercu($campagne, $campagne['quetes'][0]))->toContain('> sortie : escalier, coin (9, 4), 2×2');
});

it('refuse un accès mal posé, en nommant le chemin', function (array $acces, string $cle, string $code, ?string $chemin = null) {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0][$cle] = $acces;

    $refus = refusDAcces($campagne);
    expect(array_column($refus, 'code'))->toContain($code)
        ->and(collect($refus)->firstWhere('code', $code)['chemin'])->toStartWith($chemin ?? "quetes.0.{$cle}");
})->with([
    'type inconnu' => [['type' => 'trappe', 'x' => 2, 'y' => 3], 'depart', ValidationCampagne::VALEUR_INVALIDE],
    'porte sans côté' => [['type' => 'porte', 'x' => 2, 'y' => 3], 'depart', ValidationCampagne::CLE_MANQUANTE],
    'porte dans une salle fermée' => [['type' => 'porte', 'x' => 4, 'y' => 10, 'cote' => 's'], 'sortie', ValidationCampagne::DEPART_INVALIDE],
    'porte dont le côté reste dans la salle' => [['type' => 'porte', 'x' => 3, 'y' => 3, 'cote' => 'e'], 'depart', ValidationCampagne::PORTE_SANS_BORD],
    'porte sur un mur déjà percé par une porte du donjon' => [['type' => 'porte', 'x' => 3, 'y' => 2, 'cote' => 'n'], 'sortie', ValidationCampagne::CASE_PARTAGEE],
    // Les accès sont contrôlés AVANT le mobilier : c'est le meuble, posé ensuite sur la case de la porte, qui est refusé
    // — comme pour un meuble posé sur l'escalier.
    'porte dont la case porte un meuble' => [['type' => 'porte', 'x' => 6, 'y' => 5, 'cote' => 's'], 'sortie', ValidationCampagne::CASE_PARTAGEE, 'quetes.0.mobilier.0'],
    'sortie qui recouvre l\'escalier d\'entrée' => [['type' => 'escalier', 'x' => 3, 'y' => 4], 'sortie', ValidationCampagne::CASE_PARTAGEE],
    'escalier de sortie à cheval sur un mur' => [['type' => 'escalier', 'x' => 6, 'y' => 2], 'sortie', ValidationCampagne::DEPART_HORS_SALLE],
]);

it('EN JEU : on entre par une porte, et on ne quitte le donjon que depuis la porte de SORTIE', function () {
    app(CampagnesConstruites::class)->enregistrer(campagneAPortes());
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    $etat = fn () => $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    $menu = function () use ($groupe, $code, $heros) {
        Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));

        return collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'));
    };

    // Le héros attend HORS DU PLATEAU, devant la porte d'entrée, et y entre ; la quête a figé ses accès.
    expect($etat()->horsPlateau())->toBeTrue();
    $menu();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'entrer_donjon'])->assertAccepted();
    expect([$etat()->position_x, $etat()->position_y])->toBe([2, 3])
        ->and($quete->sortieDuDonjon())->toBe(['type' => 'porte', 'cases' => [['x' => 10, 'y' => 5]]])
        ->and($quete->nomDeLaSortie())->toBe('la porte de sortie');

    // Objectif accompli, mais il est devant la porte d'ENTRÉE : aucune sortie offerte, le résolveur la refuse.
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    expect($menu()->pluck('id'))->not->toContain('sortir_donjon')->not->toContain('quitter_donjon');
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertStatus(422);
    expect($this->getJson("/api/groupes/{$code}/etat")->json('quete.sortie.consigne'))->toBe('Objectif accompli : quittez le donjon par la porte de sortie.');

    // Devant la porte de SORTIE : l'option est là, à son nom, et il quitte le donjon.
    $etat()->update(['position_x' => 10, 'position_y' => 5]);
    $option = $menu()->firstWhere('id', 'sortir_donjon');
    expect($option['libelle'])->toBe('Quitter le donjon par la porte de sortie');
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted();

    expect($quete->refresh()->etat)->toBe('terminee')
        ->and($groupe->refresh()->phase)->toBe('hub');

    $annonce = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()
        ->first(fn ($e) => $e->type === 'action' && ($e->payload['type'] ?? null) === 'sortie_en_personne');
    expect(app(\App\Partie\JournalCombat::class)->depuisResultat($annonce->payload, 'Albrecht')[0]['texte'])
        ->toBe('Albrecht quitte le donjon par la porte de sortie — plus personne ne reste dans le donjon');
});


it('montre la porte d\'entrée dès le départ, et la porte de sortie seulement quand sa salle est découverte', function () {
    app(CampagnesConstruites::class)->enregistrer(campagneAPortes());
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail(Groupe::where('identifiant', $code)->value('quete_courante_id'));

    // Au départ : seule la salle d'entrée est connue. La porte d'entrée est là, sur le bord ouest de (2, 3) ; la sortie, non.
    $carte = $this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('carte');
    expect($carte['escalier'])->toBeNull()
        ->and($carte['acces'])->toBe([[
            'role' => 'entree', 'type' => 'porte', 'libelle' => 'Porte d\'entrée',
            'x' => 2, 'y' => 3, 'l' => 1, 'h' => 1, 'cote' => 'o', 'symbole_url' => null,
        ]]);

    // La galerie (salle 1) est découverte : la porte de sortie apparaît, sur le bord sud de (10, 5).
    $quete->update(['salles_decouvertes' => [0, 1]]);
    $acces = collect($this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('carte.acces'))->keyBy('role');
    expect($acces->keys()->all())->toBe(['entree', 'sortie'])
        ->and($acces['sortie']['libelle'])->toBe('Porte de sortie')
        ->and([$acces['sortie']['x'], $acces['sortie']['y'], $acces['sortie']['cote']])->toBe([10, 5, 's']);
});

it('ne publie aucun repère d\'accès pour une quête qui entre et sort par son escalier', function () {
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();

    // L'escalier d'entrée est déjà dessiné par `carte.escalier` : rien à ajouter.
    $carte = $this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('carte');
    expect($carte['acces'])->toBe([])
        ->and($carte['escalier'])->not->toBeNull();
});

/*
 * UNE PORTE DE SORTIE DANS UN COULOIR (René, 2026-11-16 : « la porte de sortie peut aussi être dans un corridor »).
 * Elle se pose contre quelque chose — le bord du plateau, de la roche (salle fermée) ou un bloc — jamais au milieu du
 * sol. L'entrée reste dans une salle. Repères de l'exemple : `reserve` (14..16 × 2..4) est FERMÉE dans la quête 1, un
 * bloc est en (11, 7), le plateau fait 20 × 14.
 */
it('accepte une porte de sortie dans un couloir, contre le bord du plateau, de la roche ou un bloc', function (array $sortie) {
    $campagne = campagneAPortes($sortie);

    expect(refusDAcces($campagne))->toBe([]);

    $produit = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0]);
    expect($produit['acces']['sortie']['cases'])->toBe([['x' => $sortie['x'], 'y' => $sortie['y']]]);
})->with([
    'contre le bord du plateau' => [['type' => 'porte', 'x' => 19, 'y' => 7, 'cote' => 'e']],
    'contre une salle fermée' => [['type' => 'porte', 'x' => 13, 'y' => 2, 'cote' => 'e']],
    'contre un bloc' => [['type' => 'porte', 'x' => 12, 'y' => 7, 'cote' => 'o']],
]);

it('accepte aussi une FAUSSE porte de sortie dans un couloir', function () {
    $campagne = campagneAPortes();
    $campagne['quetes'][0]['fausses_sorties'] = [['x' => 19, 'y' => 7, 'cote' => 'e']];

    expect(refusDAcces($campagne))->toBe([]);
});

it('refuse une porte de couloir qui donne sur du sol', function (string $cle, array $acces, string $code) {
    $campagne = campagneAPortes();
    $campagne['quetes'][0][$cle] = $acces;

    $refus = refusDAcces($campagne);
    expect(collect($refus)->contains(fn (array $r) => $r['code'] === $code && str_starts_with($r['chemin'], "quetes.0.{$cle}")))
        ->toBeTrue(json_encode($refus, JSON_UNESCAPED_UNICODE));
})->with([
    'sortie qui donne sur le couloir' => ['sortie', ['type' => 'porte', 'x' => 12, 'y' => 2, 'cote' => 'e'], ValidationCampagne::PORTE_SANS_BORD],
    'sortie qui donne sur une salle ouverte' => ['sortie', ['type' => 'porte', 'x' => 11, 'y' => 3, 'cote' => 'o'], ValidationCampagne::PORTE_SANS_BORD],
    'entrée qui donne sur le couloir' => ['depart', ['type' => 'porte', 'x' => 12, 'y' => 2, 'cote' => 'e'], ValidationCampagne::PORTE_SANS_BORD],
]);

it('EN JEU : on quitte le donjon depuis la case de couloir devant la porte de sortie', function () {
    app(CampagnesConstruites::class)->enregistrer(campagneAPortes(['type' => 'porte', 'x' => 19, 'y' => 7, 'cote' => 'e']));
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->update(['position_x' => 19, 'position_y' => 7]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));

    $option = collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'))->firstWhere('id', 'sortir_donjon');
    expect($option['libelle'])->toBe('Quitter le donjon par la porte de sortie');

    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'sortie_en_personne');
    expect($quete->refresh()->etat)->toBe('terminee');
});

/*
 * UNE PORTE D'ENTRÉE DANS UN COULOIR (René, 2026-11-16 : « la porte d'entrée peut aussi être sur un mur d'un
 * corridor »). Mêmes règles qu'une porte de sortie de couloir ; les héros s'installent dans CE couloir, et aucune salle
 * n'est connue d'emblée : la salle 0 n'est plus « la salle de départ », seulement la première salle ouverte.
 */
it('entre par une porte de couloir : les héros s\'installent dans le couloir, au plus près en pas', function () {
    $campagne = campagneAPortes(null);
    $campagne['quetes'][0]['depart'] = ['type' => 'porte', 'x' => 19, 'y' => 7, 'cote' => 'e'];

    expect(refusDAcces($campagne))->toBe([]);

    $produit = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0]);
    $spawns = $produit['carte']['spawn_heros'];
    $salles = $produit['carte']['salles'];

    expect($produit['acces']['salle_depart'])->toBeNull()
        ->and($produit['carte']['escalier'])->toBeNull()
        ->and($spawns[0])->toBe(['x' => 19, 'y' => 7])
        // Toutes au couloir, jamais dans une salle, jamais sur la roche ni le bloc (11, 7).
        ->and(collect($spawns)->every(fn (array $c) => \App\Partie\Salles::indexDe($salles, $c['x'], $c['y']) === null
            && $produit['carte']['cases'][$c['y']][$c['x']] === 's'))->toBeTrue()
        // Au plus près EN PAS : la deuxième place touche la porte.
        ->and(abs($spawns[1]['x'] - 19) + abs($spawns[1]['y'] - 7))->toBe(1)
        // Sans sortie déclarée, on ressort par cette même porte.
        ->and($produit['acces']['sortie'])->toBe($produit['acces']['entree']);

    // Une entrée en salle garde sa salle de départ.
    $enSalle = campagneAPortes();
    expect(app(CarteConstruite::class)->produire($enSalle, $enSalle['quetes'][0])['acces']['salle_depart'])->toBe(0);
});

it('EN JEU : entré par un couloir, aucune salle n\'est découverte, le couloir se voit, et l\'on ressort par la même porte', function () {
    $campagne = campagneAPortes(null);
    $campagne['quetes'][0]['depart'] = ['type' => 'porte', 'x' => 19, 'y' => 7, 'cote' => 'e'];
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();

    // Le héros attend devant la porte de couloir ; aucune salle n'est connue, et le gobelin de `entree` (la salle 0 de
    // la carte) dort. Il entre : le voilà sur la case de couloir devant la porte.
    expect($etat->horsPlateau())->toBeTrue();
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'entrer_donjon'])->assertAccepted();
    $etat->refresh();
    expect([$etat->position_x, $etat->position_y])->toBe([19, 7])
        ->and($quete->sallesDecouvertes())->toBe([])
        ->and(InstanceMonstre::where('quete_id', $quete->id)->where('revele', true)->count())->toBe(0);

    // Le couloir sous ses pieds se voit ; l'intérieur de `entree` reste sous le brouillard.
    $carte = $this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('carte');
    expect($carte['cases'][7][19])->toBe('s')
        ->and($carte['cases'][7][18])->toBe('s')
        ->and($carte['cases'][3][4])->toBe('b')
        ->and(collect($carte['acces'])->firstWhere('role', 'entree_sortie')['cote'] ?? null)->toBe('e');

    // Il a de quoi marcher : le menu offre un déplacement.
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $menu = collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'));
    expect($menu->firstWhere('id', 'se_deplacer')['parametres']['destinations'] ?? [])->not->toBeEmpty();

    // Objectif accompli, depuis la même case, au tour suivant : il ressort par la porte d'entrée.
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    $etat->update(['a_joue' => false, 'a_deplace' => false, 'a_agi' => false]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'sortie_en_personne');
    expect($quete->refresh()->etat)->toBe('terminee');
});

/*
 * HORS DU PLATEAU (René, 2026-11-16 : « respectons la règle hors plateau » — *Kellar's Keep*, F4543 : « the heroes line
 * up outside the door »). Par une porte d'entrée, les héros attendent DEHORS : pas de case, un menu réduit à entrer ou
 * attendre ; entrer coûte une case du déplacement du tour ; une case de porte occupée fait attendre le suivant.
 */
function partieHorsPlateau(object $test, int $nbHeros = 2): array
{
    app(CampagnesConstruites::class)->enregistrer(campagneAPortes());
    $joueurs = [];
    $heros = [];
    foreach (array_slice(['alice', 'bruno'], 0, $nbHeros) as $i => $nom) {
        $joueurs[$i] = connecterJoueur($nom);
        $heros[$i] = Personnage::create([
            'joueur_id' => $joueurs[$i]->id, 'nom' => ['Albrecht', 'Brunehaut'][$i], 'classe' => 'barbare', 'niveau' => 1,
            'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
            'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
        ]);
    }
    $test->actingAs($joueurs[0], 'joueur');
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros[0]->id])
        ->assertCreated()->json('groupe.identifiant');
    if ($nbHeros > 1) {
        $test->actingAs($joueurs[1], 'joueur')->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $heros[1]->id])->assertSuccessful();
        $test->actingAs($joueurs[0], 'joueur');
    }
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    return compact('code', 'groupe', 'quete', 'joueurs', 'heros');
}

it('EN JEU : par une porte d\'entrée, les héros attendent hors du plateau ; entrer coûte une case', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieHorsPlateau($this, 1);
    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros[0]->id)->firstOrFail();

    // Dehors : sans case, publié comme tel, posé nulle part.
    expect($etat->horsPlateau())->toBeTrue();
    $entite = collect($this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('entites'))->firstWhere('id', $heros[0]->id);
    expect($entite['hors_plateau'])->toBeTrue()
        ->and($entite['sorti'])->toBeFalse()
        ->and($entite['x'])->toBeNull();

    // Son menu : entrer, ou attendre — rien d'autre.
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros[0]));
    $options = collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'));
    expect($options->pluck('id')->all())->toBe(['entrer_donjon', 'attendre']);
    $entrer = $options->firstWhere('id', 'entrer_donjon');
    expect($entrer['creneau'])->toBe('mouvement')
        ->and($entrer['parametres']['case'])->toBe(['x' => 2, 'y' => 3]);
    $portee = (int) $entrer['parametres']['portee'];

    // Il entre : la case de la porte, une case de moins, et il peut continuer à marcher.
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'entrer_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'entree_donjon')
        ->assertJsonPath('resultat.deplacement_restant', $portee - 1);
    $etat->refresh();
    expect([$etat->position_x, $etat->position_y])->toBe([2, 3])
        ->and((int) $etat->deplacement_restant)->toBe($portee - 1);

    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros[0]));
    $options = collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'));
    expect($options->firstWhere('id', 'se_deplacer')['libelle'] ?? null)->toBe('Continuer à se déplacer')
        ->and($options->pluck('id'))->not->toContain('entrer_donjon');

    // Déjà dedans : le résolveur refuse une seconde entrée.
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'entrer_donjon'])->assertStatus(422);
});

it('EN JEU : une porte d\'entrée bouchée fait attendre le suivant, et un héros dehors n\'est la cible de personne', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros, 'joueurs' => $joueurs] = partieHorsPlateau($this);
    $etatDe = fn (int $i) => $quete->etatsPersonnages()->where('personnage_id', $heros[$i]->id)->firstOrFail();

    // Un gobelin réveillé en (0, 1) — à côté de (0, 0), la case qu'un `(int) null` donnerait au héros de dehors.
    InstanceMonstre::where('quete_id', $quete->id)->update(['position_x' => 0, 'position_y' => 1, 'revele' => true]);
    $pv = (int) $heros[1]->fresh()->pv_body;
    $jouer = function (int $i, string $option) use ($code, $groupe, $heros, $joueurs) {
        $this->actingAs($joueurs[$i], 'joueur');
        Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros[$i]));
        $menu = $this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu');
        $this->postJson("/api/groupes/{$code}/choix", ['option_id' => $option])->assertAccepted();

        return $menu;
    };

    // Le premier entre et s'arrête sur le seuil ; le second, dehors, ne peut pas entrer : il n'a que « Terminer le
    // tour ». Les deux tours finis, la phase des monstres joue — personne ne frappe celui qui n'est pas sur le plateau.
    $jouer(0, 'entrer_donjon');
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'attendre'])->assertAccepted();
    $menu = $jouer(1, 'attendre');
    expect(collect($menu['options'])->pluck('id')->all())->toBe(['attendre'])
        ->and($menu['situation'])->toContain('quelqu\'un se tient sur le seuil');

    $frappes = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()
        ->filter(fn ($e) => str_contains(json_encode($e->payload, JSON_UNESCAPED_UNICODE), 'Brunehaut')
            && ($e->acteur['type'] ?? null) === 'monstre');
    expect($frappes)->toHaveCount(0, json_encode($frappes->map(fn ($e) => $e->payload)->values(), JSON_UNESCAPED_UNICODE))
        ->and((int) $heros[1]->fresh()->pv_body)->toBe($pv)
        ->and($etatDe(1)->horsPlateau())->toBeTrue();
});
