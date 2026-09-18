<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\ForgeAmelioration;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\ForgeAmeliorationSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * ÉCHANGER AVEC UN ALLIÉ ADJACENT / JETER UN OBJET DU SAC (doc 01 §7,
 * `docs/contrat-api.md` « Gérer son inventaire EN QUÊTE », 2026-09-17).
 *
 * ⚠ RÉVISION du 2026-09-17 (décisions de René) — les deux gestes ont divergé,
 * et ce fichier a été réécrit avec eux :
 *
 *  - **`jeter` est GRATUIT** (créneau `interaction`) et donc répétable dans le
 *    même tour, y compris APRÈS avoir agi. Écart ASSUMÉ avec doc 01 §7, qui
 *    range les trois gestes sous « coûte l'action du tour » : au prix d'une
 *    action par pièce, se délester devant un coffre coûtait le tour entier et
 *    la « tension de gestion » du même paragraphe devenait une punition. Il
 *    porte en plus une QUANTITÉ, bornée par la ligne relue en base.
 *  - **`echanger` est la SÉANCE du canon** — « transférer armes/armures ENTRE
 *    LES DEUX INVENTAIRES » : bidirectionnelle, multiple, pour UNE action.
 *    L'option porte `parametres.allies[]` (plus d'objets), et le corps envoie
 *    `transferts[]`. La première livraison avait importé la forme du don au
 *    hub — unidirectionnelle et à l'unité —, c'est-à-dire la forme du service
 *    et non celle de la règle.
 *
 * ⚠ La capacité se juge sur l'ÉTAT FINAL, et le seuil n'est pas
 * `final ≤ capacité` : un sac peut être légitimement en dépassement (un butin
 * passe outre), et `DonObjet` dit que « donner est justement la façon de
 * régulariser ». D'où `final ≤ capacité` OU `final ≤ occupation de départ`.
 *
 * ⚠ La mécanique de transfert (fusion de pile, ligne DÉPLACÉE pour préserver
 * les `ameliorations` de Forge) reste celle de `DonObjet`, réutilisée et
 * jamais redite : c'est le CONTRÔLE qui change d'échelle, pas le transfert.
 *
 * ⚠ `cle` est une chaîne OPAQUE (`"objet:{id}"`, `"heros:{id}"`), jamais
 * l'entier nu : `ChoixController` valide `parametres.cle` en `string`, et un
 * entier y produisait un 422 systématique — mesuré en jeu.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        ForgeAmeliorationSeeder::class]);
});

/**
 * Ligne d'inventaire de $porteur. `$emplacement` par défaut à `sac` — les
 * CONSOMMABLES (potions, parchemins) vivent réellement en `consommable`
 * (`RangementObjet::ranger()`), jamais en `sac` : un test qui les y mettrait
 * quand même masquerait exactement le défaut que ce fichier vérifie.
 */
function objetDansSac(Personnage $porteur, string $nom, int $quantite = 1, string $emplacement = 'sac'): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $porteur->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => $quantite,
    ]);
}

/**
 * Groupe à deux héros du MÊME joueur, quête démarrée, positions ADJACENTES
 * garanties (patron de `PotionCibleAdjacenteTest::deuxHerosAdjacents()`).
 * Albrecht (ordre 1) est TOUJOURS celui qui agit dans les tests ci-dessous —
 * l'initiative est figée pour la quête, ce doit être son tour.
 *
 * @return array{alice: JoueurAuthentifiable, groupe: Groupe, quete: Quete, albrecht: Personnage, bertrand: Personnage}
 */
function deuxHerosVoisins(string $classeA = 'barbare', string $classeB = 'barbare'): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => $classeA]);
    $bertrand = creerHeros($alice, $groupe, 'Bertrand', 2, ['classe' => $classeB]);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $etatAlbrecht = $quete->etatsPersonnages()->where('personnage_id', $albrecht->id)->firstOrFail();
    $quete->etatsPersonnages()->where('personnage_id', $bertrand->id)->update([
        'position_x' => (int) $etatAlbrecht->position_x + 1,
        'position_y' => (int) $etatAlbrecht->position_y,
    ]);

    return compact('alice', 'groupe', 'quete', 'albrecht', 'bertrand');
}

/** L'option $optionId du menu publié à $acteur (régénéré à la demande). */
function optionPubliee(Groupe $groupe, JoueurAuthentifiable $joueur, Personnage $acteur, string $optionId): ?array
{
    GenererMenu::dispatchSync($groupe->id, (int) $joueur->id, (int) $acteur->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $joueur->id));

    return collect($menu['menu']['options'] ?? [])->firstWhere('id', $optionId);
}

/** L'entrée `parametres.objets[]` de $option dont l'`inventaire_id` est $id. */
function entreeParInventaireId(?array $option, int $id): ?array
{
    return collect($option['parametres']['objets'] ?? [])->firstWhere('inventaire_id', $id);
}

/** Sac non consommable saturé (même patron que `DonObjetTest`) — 12 babioles. */
function saturerSac(Personnage $personnage): void
{
    $babiole = Objet::where('categorie', '!=', 'consommable')->where('rarete', 'commun')->firstOrFail();

    for ($i = 0; $i < 12; $i++) {
        Inventaire::create([
            'personnage_id' => $personnage->id, 'objet_id' => $babiole->id,
            'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }
}

it('« jeter » est absent sac vide, « échanger » absent sans allié adjacent', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = $ctx;

    // Seul au monde, sac vide : aucun des deux gestes n'a de quoi s'exercer.
    expect(optionPubliee($groupe, $alice, $heros, 'jeter'))->toBeNull()
        ->and(optionPubliee($groupe, $alice, $heros, 'echanger'))->toBeNull();

    objetDansSac($heros, 'Épée courte');

    // Le sac n'est plus vide : « jeter » apparaît. Toujours seul au monde :
    // « échanger » reste absent, il n'y a personne à qui donner.
    expect(optionPubliee($groupe, $alice, $heros, 'jeter'))->not->toBeNull()
        ->and(optionPubliee($groupe, $alice, $heros, 'echanger'))->toBeNull();
});


/** L'entrée `parametres.allies[]` de $option pour l'allié $id. */
function entreeAllie(?array $option, int $id): ?array
{
    return collect($option['parametres']['allies'] ?? [])->firstWhere('cle', "heros:{$id}");
}

it('« jeter » publie « cle » au format `objet:{id}` et la pile, consommable compris', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = $ctx;

    // ⚠ `emplacement === 'consommable'`, pas `'sac'` : c'est là que vit
    // réellement une potion (`RangementObjet::ranger()`), et c'est le cas que
    // le filtre `=== 'sac'` de la première livraison ratait entièrement.
    $ligne = objetDansSac($heros, 'Potion de soin', 3, 'consommable');

    $entree = entreeParInventaireId(optionPubliee($groupe, $alice, $heros, 'jeter'), $ligne->id);

    expect($entree)->not->toBeNull()
        // LE FORMAT QUI COMPTE : un entier nu fait échouer la validation
        // `parametres.cle` de `ChoixController` (`string`) AVANT le résolveur
        // — mesuré en jeu, 422 systématique.
        ->and($entree['cle'])->toBe("objet:{$ligne->id}")
        // La pile est publiée : c'est elle qui borne le champ numérique.
        ->and($entree['quantite'])->toBe(3);
});

it('« jeter » est GRATUIT : répétable dans le même tour, et encore offert APRÈS avoir agi', function () {
    // R1 (René, 2026-09-17). Le contraire de ce que testait la première
    // livraison : jeter coûtait l'action, donc un sac plein devant un coffre
    // coûtait le tour entier — la « tension de gestion » du doc 01 §7 devenait
    // une punition.
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'quete' => $quete] = $ctx;

    $a = objetDansSac($heros, 'Épée courte');
    $b = objetDansSac($heros, 'Bouclier');

    $jeter = fn (Inventaire $l) => test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'jeter',
        'parametres' => ['cle' => "objet:{$l->id}"],
    ]);

    expect(entreeParInventaireId(optionPubliee($groupe, $alice, $heros, 'jeter'), $a->id))->not->toBeNull();
    $jeter($a)->assertAccepted()->assertJsonPath('resultat.type', 'jeter');

    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();

    // LE POINT : aucun créneau consommé, donc un second jet dans le MÊME tour.
    expect((bool) $etat->fresh()->a_agi)->toBeFalse()
        ->and((bool) $etat->fresh()->a_joue)->toBeFalse()
        ->and(entreeParInventaireId(optionPubliee($groupe, $alice, $heros, 'jeter'), $b->id))->not->toBeNull();

    $jeter($b)->assertAccepted();
    expect(Inventaire::whereKey($a->id)->exists())->toBeFalse()
        ->and(Inventaire::whereKey($b->id)->exists())->toBeFalse();

    // ⚠ Et il reste offert APRÈS avoir agi — c'est tout l'intérêt de l'avoir
    // sorti de la garde `! $aAgi` du menu : gratuit mais masqué une fois le
    // coup porté, il l'aurait été pour rien.
    $c = objetDansSac($heros, 'Épée courte');
    $etat->update(['a_agi' => true]);

    expect(entreeParInventaireId(optionPubliee($groupe, $alice, $heros, 'jeter'), $c->id))->not->toBeNull();
    $jeter($c)->assertAccepted();
    expect(Inventaire::whereKey($c->id)->exists())->toBeFalse();
});

it('« jeter » prend une QUANTITÉ, bornée par la ligne en base', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = $ctx;

    $ligne = objetDansSac($heros, 'Potion de soin', 5, 'consommable');
    $cle = "objet:{$ligne->id}";

    // Au-delà de la pile : refusé. ⚠ Le `max` publié n'est qu'un affichage —
    // la vraie borne est relue en base, un champ numérique étant la plus
    // facile des whitelists à contourner.
    expect(optionPubliee($groupe, $alice, $heros, 'jeter'))->not->toBeNull();
    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'jeter', 'parametres' => ['cle' => $cle, 'quantite' => 6],
    ])->assertStatus(422);
    expect((int) $ligne->fresh()->quantite)->toBe(5);

    // Sous la pile : elle diminue d'autant, la ligne survit.
    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'jeter', 'parametres' => ['cle' => $cle, 'quantite' => 3],
    ])->assertAccepted()->assertJsonPath('resultat.quantite', 3);
    expect((int) $ligne->fresh()->quantite)->toBe(2);

    // Toute la pile : la ligne disparaît.
    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'jeter', 'parametres' => ['cle' => $cle, 'quantite' => 2],
    ])->assertAccepted();
    expect(Inventaire::whereKey($ligne->id)->exists())->toBeFalse();
});

it('la SÉANCE publie les deux sacs, leurs capacités et l\'encombrement par pièce', function () {
    $ctx = deuxHerosVoisins();
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht, 'bertrand' => $bertrand] = $ctx;

    $arme = objetDansSac($albrecht, 'Épée courte');
    $potion = objetDansSac($albrecht, 'Potion de soin', 2, 'consommable');
    $sien = objetDansSac($bertrand, 'Bouclier');

    $entree = entreeAllie(optionPubliee($groupe, $alice, $albrecht, 'echanger'), $bertrand->id);

    expect($entree)->not->toBeNull()
        ->and($entree['nom'])->toBe('Bertrand')
        ->and(collect($entree['mon_sac'])->pluck('inventaire_id')->all())->toBe([$arme->id, $potion->id])
        ->and(collect($entree['son_sac'])->pluck('inventaire_id')->all())->toBe([$sien->id])
        // ⚠ `encombrant` est publié PAR PIÈCE pour que la manette additionne
        // des entiers sans re-dériver « un consommable ne compte pas ».
        ->and(collect($entree['mon_sac'])->firstWhere('inventaire_id', $arme->id)['encombrant'])->toBeTrue()
        ->and(collect($entree['mon_sac'])->firstWhere('inventaire_id', $potion->id)['encombrant'])->toBeFalse()
        ->and($entree['ma_capacite'])->toHaveKeys(['occupation', 'max'])
        ->and($entree['sa_capacite'])->toHaveKeys(['occupation', 'max']);
});

it('la SÉANCE transfère dans les DEUX SENS en une seule action', function () {
    $ctx = deuxHerosVoisins();
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht, 'bertrand' => $bertrand, 'quete' => $quete] = $ctx;

    $aMoi = objetDansSac($albrecht, 'Épée courte');
    $aLui = objetDansSac($bertrand, 'Bouclier');

    expect(optionPubliee($groupe, $alice, $albrecht, 'echanger'))->not->toBeNull();

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'echanger',
        'parametres' => ['cle' => "heros:{$bertrand->id}", 'transferts' => [
            ['inventaire_id' => $aMoi->id, 'vers_personnage_id' => $bertrand->id, 'quantite' => 1],
            ['inventaire_id' => $aLui->id, 'vers_personnage_id' => $albrecht->id, 'quantite' => 1],
        ]],
    ])->assertAccepted()->assertJsonPath('resultat.type', 'echanger');

    // Les deux pièces ont changé de main, pour UNE action — c'est la séance
    // du canon (« transférer armes/armures ENTRE LES DEUX INVENTAIRES »),
    // pas un don unidirectionnel répété.
    expect((int) $aMoi->fresh()->personnage_id)->toBe($bertrand->id)
        ->and((int) $aLui->fresh()->personnage_id)->toBe($albrecht->id);

    $etatA = $quete->etatsPersonnages()->where('personnage_id', $albrecht->id)->firstOrFail();
    $etatB = $quete->etatsPersonnages()->where('personnage_id', $bertrand->id)->firstOrFail();
    expect((bool) $etatA->a_agi)->toBeTrue()->and((bool) $etatB->a_agi)->toBeFalse();
});

it('la SÉANCE accepte un échange croisé entre deux sacs PLEINS', function () {
    // ⚠ LE CAS QUI JUSTIFIE TOUTE LA SÉANCE. Deux sacs saturés qui échangent
    // deux pièces est légal au canon (la capacité porte sur l'état FINAL),
    // et pourtant AUCUN ordre d'application ne passe un contrôle pièce par
    // pièce : `DonObjet::donner()` vérifie `peutRanger()` avant chaque
    // mouvement, donc le premier échoue quel que soit le sens.
    $ctx = deuxHerosVoisins();
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht, 'bertrand' => $bertrand] = $ctx;

    saturerSac($albrecht);
    saturerSac($bertrand);
    $aMoi = objetDansSac($albrecht, 'Épée courte');
    $aLui = objetDansSac($bertrand, 'Bouclier');

    expect(optionPubliee($groupe, $alice, $albrecht, 'echanger'))->not->toBeNull();

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'echanger',
        'parametres' => ['cle' => "heros:{$bertrand->id}", 'transferts' => [
            ['inventaire_id' => $aMoi->id, 'vers_personnage_id' => $bertrand->id, 'quantite' => 1],
            ['inventaire_id' => $aLui->id, 'vers_personnage_id' => $albrecht->id, 'quantite' => 1],
        ]],
    ])->assertAccepted();

    expect((int) $aMoi->fresh()->personnage_id)->toBe($bertrand->id)
        ->and((int) $aLui->fresh()->personnage_id)->toBe($albrecht->id);
});

it('la SÉANCE refuse un net qui déborde, NOMME le sac, et n\'applique RIEN', function () {
    $ctx = deuxHerosVoisins();
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht, 'bertrand' => $bertrand] = $ctx;

    saturerSac($bertrand);
    $un = objetDansSac($albrecht, 'Épée courte');
    $deux = objetDansSac($albrecht, 'Bouclier');

    expect(optionPubliee($groupe, $alice, $albrecht, 'echanger'))->not->toBeNull();

    $reponse = test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'echanger',
        'parametres' => ['cle' => "heros:{$bertrand->id}", 'transferts' => [
            ['inventaire_id' => $un->id, 'vers_personnage_id' => $bertrand->id, 'quantite' => 1],
            ['inventaire_id' => $deux->id, 'vers_personnage_id' => $bertrand->id, 'quantite' => 1],
        ]],
    ])->assertStatus(422);

    // Le refus NOMME le sac qui déborde : un 422 muet laisserait le joueur
    // deviner laquelle de ses pièces posait problème.
    expect(json_encode($reponse->json()))->toContain('Bertrand');

    // ATOMIQUE : rien n'a bougé, pas même le premier transfert qui, seul,
    // aurait pu passer.
    expect((int) $un->fresh()->personnage_id)->toBe($albrecht->id)
        ->and((int) $deux->fresh()->personnage_id)->toBe($albrecht->id);
});

it('un sac DÉJÀ en dépassement peut se délester, mais pas recevoir davantage', function () {
    // ⚠ Le seuil n'est pas `final ≤ capacité` : un butin de quête passe outre
    // la capacité, et `DonObjet` dit que « donner est justement la façon de
    // régulariser ». Le seuil naïf aurait interdit POUR TOUJOURS à ce héros
    // d'utiliser la séance — refusé pour un état qu'il vient d'améliorer.
    $ctx = deuxHerosVoisins();
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht, 'bertrand' => $bertrand, 'quete' => $quete] = $ctx;

    saturerSac($albrecht); // 12 pièces encombrantes, très au-delà de la capacité
    $aMoi = objetDansSac($albrecht, 'Épée courte');
    $aLui = objetDansSac($bertrand, 'Bouclier');

    expect(optionPubliee($groupe, $alice, $albrecht, 'echanger'))->not->toBeNull();

    // Se DÉLESTER : accepté, bien qu'Albrecht finisse encore au-dessus.
    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'echanger',
        'parametres' => ['cle' => "heros:{$bertrand->id}", 'transferts' => [
            ['inventaire_id' => $aMoi->id, 'vers_personnage_id' => $bertrand->id, 'quantite' => 1],
        ]],
    ])->assertAccepted();
    expect((int) $aMoi->fresh()->personnage_id)->toBe($bertrand->id);

    // ⚠ Nouveau TOUR : la séance coûte l'action (contrairement à jeter), donc
    // `echanger` a disparu du menu — c'est correct, et c'est ce qui distingue
    // les deux gestes. On rouvre le créneau pour éprouver la seconde moitié
    // de la règle.
    $quete->etatsPersonnages()->where('personnage_id', $albrecht->id)
        ->update(['a_agi' => false, 'a_joue' => false]);

    // RECEVOIR une pièce de plus : refusé, il s'aggraverait.
    expect(optionPubliee($groupe, $alice, $albrecht, 'echanger'))->not->toBeNull();
    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'echanger',
        'parametres' => ['cle' => "heros:{$bertrand->id}", 'transferts' => [
            ['inventaire_id' => $aLui->id, 'vers_personnage_id' => $albrecht->id, 'quantite' => 1],
        ]],
    ])->assertStatus(422);
    expect((int) $aLui->fresh()->personnage_id)->toBe($bertrand->id);
});

it('la SÉANCE refuse un allié NON ADJACENT, au menu comme à la résolution', function () {
    $ctx = deuxHerosVoisins();
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht, 'bertrand' => $bertrand, 'quete' => $quete] = $ctx;

    $ligne = objetDansSac($albrecht, 'Épée courte');
    expect(entreeAllie(optionPubliee($groupe, $alice, $albrecht, 'echanger'), $bertrand->id))->not->toBeNull();

    // Bertrand s'éloigne ENTRE la génération du menu et la soumission : sans
    // contrôle de distance à la résolution (et pas seulement `tombe`), il
    // resterait un destinataire accepté.
    $etatA = $quete->etatsPersonnages()->where('personnage_id', $albrecht->id)->firstOrFail();
    $quete->etatsPersonnages()->where('personnage_id', $bertrand->id)->update([
        'position_x' => (int) $etatA->position_x + 3, 'position_y' => (int) $etatA->position_y,
    ]);

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'echanger',
        'parametres' => ['cle' => "heros:{$bertrand->id}", 'transferts' => [
            ['inventaire_id' => $ligne->id, 'vers_personnage_id' => $bertrand->id, 'quantite' => 1],
        ]],
    ])->assertStatus(422);

    expect((int) $ligne->fresh()->personnage_id)->toBe($albrecht->id);
});

it('la SÉANCE refuse une ligne qui n\'appartient à aucun des deux héros', function () {
    $ctx = deuxHerosVoisins();
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht, 'bertrand' => $bertrand] = $ctx;

    $etrangere = objetDansSac(creerHeros($alice, $groupe, 'Chlotilde', 3), 'Épée courte');
    objetDansSac($albrecht, 'Bouclier');

    expect(optionPubliee($groupe, $alice, $albrecht, 'echanger'))->not->toBeNull();

    // ⚠ Un transfert est un triplet qu'un client peut inventer entièrement :
    // la liste publiée est la whitelist, chaque `inventaire_id` est revalidé.
    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'echanger',
        'parametres' => ['cle' => "heros:{$bertrand->id}", 'transferts' => [
            ['inventaire_id' => $etrangere->id, 'vers_personnage_id' => $bertrand->id, 'quantite' => 1],
        ]],
    ])->assertStatus(422);

    expect((int) $etrangere->fresh()->personnage_id)->not->toBe($bertrand->id);
});

it('une pièce ÉQUIPÉE n\'est jamais offerte, ni à jeter ni à la séance', function () {
    $ctx = deuxHerosVoisins();
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht, 'bertrand' => $bertrand] = $ctx;

    $arme = objetDansSac($albrecht, 'Épée courte');
    (new Equipement)->equiper($albrecht, $arme);
    expect($arme->fresh()->emplacement)->toBe('arme_principale');

    $potion = objetDansSac($albrecht, 'Potion de soin', 1, 'consommable');

    $jeter = optionPubliee($groupe, $alice, $albrecht, 'jeter');
    $entree = entreeAllie(optionPubliee($groupe, $alice, $albrecht, 'echanger'), $bertrand->id);

    expect(collect($jeter['parametres']['objets'])->pluck('inventaire_id')->all())->toBe([$potion->id])
        ->and(collect($entree['mon_sac'])->pluck('inventaire_id')->all())->toBe([$potion->id]);
});

it('la SÉANCE préserve les améliorations de Forge, sans duplication de ligne', function () {
    $ctx = deuxHerosVoisins();
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht, 'bertrand' => $bertrand] = $ctx;

    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();
    $ligne = objetDansSac($albrecht, 'Épée courte');
    $ligne->update(['ameliorations' => [['id' => $affutee->id, 'nom' => 'Affûtée', 'effet' => $affutee->effet]]]);

    expect(optionPubliee($groupe, $alice, $albrecht, 'echanger'))->not->toBeNull();

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'echanger',
        'parametres' => ['cle' => "heros:{$bertrand->id}", 'transferts' => [
            ['inventaire_id' => $ligne->id, 'vers_personnage_id' => $bertrand->id, 'quantite' => 1],
        ]],
    ])->assertAccepted();

    // Recréer la ligne (au lieu de la DÉPLACER) perdrait l'amélioration en
    // silence — le piège précis que la mécanique de `DonObjet` évite, et que
    // la séance réutilise au lieu de la redire.
    $apres = $ligne->fresh();
    expect((int) $apres->personnage_id)->toBe($bertrand->id)
        ->and($apres->emplacement)->toBe('sac')
        ->and($apres->ameliorations)->toHaveCount(1)
        ->and($apres->ameliorations[0]['nom'])->toBe('Affûtée')
        ->and(Inventaire::where('objet_id', $ligne->objet_id)->count())->toBe(1);
});
