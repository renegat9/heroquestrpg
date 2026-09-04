<?php

declare(strict_types=1);

use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Salles;
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
use Illuminate\Support\Facades\Http;

/**
 * LES CARTES DE DREAD, EN JEU — une preuve par mécanique.
 *
 * Doc 09 §4bis : 22 des 29 cartes de `dread_spells.pdf` sont portées, et le
 * projet tient qu'« une mécanique sans cas ici n'est pas seedée ». Ce fichier
 * les joue par les vraies routes, comme `TalentsEnJeuTest` le fait pour la
 * grille de talents — le catalogue et le vocabulaire, eux, sont verrouillés
 * par `SortsDreadSourcesTest`.
 *
 * ⚠ Rappel de l'ordre des dés, qui décide de la lisibilité de chaque test :
 * le sort consomme ses dés d'abord, puis la rupture IMMÉDIATE (« can be broken
 * immediately », 1 d6 par point de Mind), puis — après la phase des monstres —
 * la rupture d'OUVERTURE DE TOUR. Trois jets, dans cet ordre.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

// ------------------------------------------------------------------
// Helpers locaux
// ------------------------------------------------------------------

/**
 * Prépare une quête où le monstre nommé ne connaît QUE le sort demandé.
 *
 * @return array{alice: mixed, groupe: mixed, heros: Personnage, quete: Quete, instance: InstanceMonstre, etatHeros: EtatPersonnageQuete}
 */
function lanceurAvecSort(string $sort, string $monstre = 'Seigneur', array $herosAttrs = []): array
{
    $ctx = demarrerQueteAvecMonstre($monstre, $herosAttrs);

    $ctx['instance']->monstre->update(['sorts_dread' => [$sort], 'archetype_lanceur' => null]);
    // ⚠ `pvBodyMax()` retombe sur le bloc du monstre d'origine quand la colonne
    // n'est pas posée : sans ça, un soin ou une Fuite raisonnent sur le mauvais
    // maximum.
    $ctx['instance']->update(['pv_body_max' => (int) $ctx['instance']->monstre->pv_body]);
    $ctx['instance']->refresh()->load('monstre');

    return $ctx;
}

/** Déclenche la phase des monstres et rend les actions jouées. */
function tourDreadAvec(array $ctx, array $des): array
{
    desFiges([...$des, ...array_fill(0, 200, 4)]);

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    return collect($reponse->json('resultat.tour_monstres.actions'))->all();
}

/** Le sort nommé dans les actions du tour. */
function actionDuSort(array $actions, string $nom): ?array
{
    return collect($actions)->firstWhere('sort', $nom);
}

/** Index de la salle d'une case de la quête. */
function salleDe(Quete $quete, int $x, int $y): ?int
{
    return Salles::indexDe((array) ($quete->carte?->grille['salles'] ?? []), $x, $y);
}

// ==================================================================
// DÉGÂTS
// ==================================================================

it("Tempête de feu brûle TOUTE LA SALLE, épargne le lanceur et n'oublie pas les monstres", function () {
    // « 3 Body Points of damage on all heroes AND MONSTERS in the same room
    // with the spellcaster. THE SPELLCASTER IS UNAFFECTED. »
    // ⚠ Notre version d'avant frappait la case du lanceur + 4 orthogonales avec
    // 2 dés de combat : ni la bonne zone, ni la bonne résolution, et elle
    // n'épargnait personne puisqu'elle ignorait les monstres.
    $ctx = lanceurAvecSort('Tempête de feu');
    ['quete' => $quete, 'instance' => $boss, 'heros' => $heros, 'etatHeros' => $etat] = $ctx;

    $salle = salleDe($quete, (int) $boss->position_x, (int) $boss->position_y);
    expect($salle)->not->toBeNull();
    expect(salleDe($quete, (int) $etat->position_x, (int) $etat->position_y))->toBe($salle);

    // Un comparse dans la même salle : il doit brûler avec les héros.
    $comparse = Monstre::where('nom_base', 'Gobelin')->firstOrFail();
    $voisine = caseAdjacenteLibre($quete, (int) $boss->position_x, (int) $boss->position_y);
    $sbire = InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => $comparse->id,
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => $comparse->pv_mind,
        'position_x' => $voisine['x'], 'position_y' => $voisine['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    $pvAvant = (int) $heros->pv_body;
    $pvBoss = (int) $boss->pv_body;

    // 2 dés rouges à 1 pour le héros (aucune réduction), puis 2 pour le sbire.
    $actions = tourDreadAvec($ctx, [1, 1, 1, 1]);
    $sort = actionDuSort($actions, 'Tempête de feu');

    expect($sort)->not->toBeNull()
        ->and($sort['resultats'][0]['degats_bruts'])->toBe(3)
        ->and((int) $heros->fresh()->pv_body)->toBe(max(0, $pvAvant - 3))
        // Le lanceur est épargné : « the spellcaster is unaffected ».
        ->and((int) $boss->fresh()->pv_body)->toBe($pvBoss)
        // …mais pas son sbire.
        ->and($sort['monstres_touches'])->not->toBeEmpty()
        ->and((int) $sbire->fresh()->pv_body)->toBe(0);
});

it('Tempête de feu est REFUSÉE en couloir', function () {
    // « Not used in corridors » — et un couloir n'a pas d'index de salle, donc
    // sans ce refus la zone serait simplement vide et l'usage partirait pour
    // rien.
    $ctx = lanceurAvecSort('Tempête de feu');
    ['quete' => $quete, 'instance' => $boss] = $ctx;

    $couloir = null;
    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $type) {
            if (in_array($type, ['s', 'p'], true)
                && caseQueteLibre($quete, (int) $x, (int) $y)
                && salleDe($quete, (int) $x, (int) $y) === null) {
                $couloir = ['x' => (int) $x, 'y' => (int) $y];
                break 2;
            }
        }
    }

    expect($couloir)->not->toBeNull('Aucune case de couloir libre sur cette carte.');
    $boss->update($couloir + ['position_x' => $couloir['x'], 'position_y' => $couloir['y']]);

    expect(actionDuSort(tourDreadAvec($ctx, []), 'Tempête de feu'))->toBeNull();
});

it('Éclair de Chaos frappe en LIGNE, sans défense possible', function () {
    // « The bolt will travel in a straight line until it strikes a wall or
    // closed door. It inflicts 2 Body Points on all heroes or monsters that
    // stand in its path. » Le lecteur existait déjà (`App\Partie\Rayon`) : c'est
    // la même phrase que le parchemin d'Éclair, au mot près.
    $ctx = lanceurAvecSort('Éclair de Chaos');
    ['heros' => $heros] = $ctx;

    $pvAvant = (int) $heros->pv_body;
    $sort = actionDuSort(tourDreadAvec($ctx, []), 'Éclair de Chaos');

    expect($sort)->not->toBeNull()
        ->and($sort['cases_affectees'])->not->toBeEmpty()
        // Montant FIXE : aucun dé d'attaque, aucun jet de défense.
        ->and($sort['resultats'][0]['degats_fixes'])->toBeTrue()
        ->and((int) $heros->fresh()->pv_body)->toBe(max(0, $pvAvant - 2));
});

it('Morsure de Froid ne touche PAS en diagonale', function () {
    // « adjacent to the spellcaster (THOUGH NOT DIAGONALLY ADJACENT) » : la
    // parenthèse est toute la carte, et c'est elle qui la distingue d'un
    // contact ordinaire.
    $ctx = lanceurAvecSort('Morsure de Froid');
    ['quete' => $quete, 'instance' => $boss, 'heros' => $heros, 'etatHeros' => $etat] = $ctx;

    $dx = (int) $boss->position_x + 1;
    $dy = (int) $boss->position_y + 1;

    if (! caseQueteLibre($quete, $dx, $dy)) {
        $this->markTestSkipped('Pas de diagonale libre sur cette carte.');
    }

    $etat->update(['position_x' => $dx, 'position_y' => $dy]);
    $pvAvant = (int) $heros->pv_body;

    expect(actionDuSort(tourDreadAvec($ctx, []), 'Morsure de Froid'))->toBeNull()
        ->and((int) $heros->fresh()->pv_body)->toBe($pvAvant);
});

it("Canaliser l'Effroi lit ses PALIERS sur le d6 brut", function () {
    // « On 1, 2, or 3 = the hero resists. On 4 or 5 = the hero loses 1 Body
    // Point. On 6+ = the hero loses 2 Body Points. »
    $ctx = lanceurAvecSort("Canaliser l'Effroi");
    ['heros' => $heros] = $ctx;

    $pvAvant = (int) $heros->pv_body;
    $sort = actionDuSort(tourDreadAvec($ctx, [4]), "Canaliser l'Effroi");

    expect($sort['resultats'][0]['de'])->toBe(4)
        ->and($sort['resultats'][0]['degats'])->toBe(1)
        ->and((int) $heros->fresh()->pv_body)->toBe($pvAvant - 1);
});

it("Canaliser l'Effroi : un 3 laisse le héros indemne, un 6 coûte 2 PV", function () {
    $ctx = lanceurAvecSort("Canaliser l'Effroi");
    ['heros' => $heros] = $ctx;
    $pvAvant = (int) $heros->pv_body;

    expect(actionDuSort(tourDreadAvec($ctx, [3]), "Canaliser l'Effroi")['resultats'][0]['degats'])->toBe(0)
        ->and((int) $heros->fresh()->pv_body)->toBe($pvAvant);

    // Second tour, même lanceur (il lui reste des usages) : un 6 fait 2 PV.
    $ctx['quete']->etatsPersonnages()->update(['a_joue' => false]);
    expect(actionDuSort(tourDreadAvec($ctx, [6]), "Canaliser l'Effroi")['resultats'][0]['degats'])->toBe(2);
});

it("Canaliser l'Effroi gagne +1 par LANCEUR DU MÊME SORT au contact", function () {
    // « For each monster adjacent to the caster THAT CAN CAST THIS SPELL, add
    // 1 point to the die total. » ⚠ Pas « n'importe quel monstre » : c'est ce
    // qui fait de la carte une récompense pour avoir groupé ses lanceurs.
    $ctx = lanceurAvecSort("Canaliser l'Effroi");
    ['quete' => $quete, 'instance' => $boss, 'heros' => $heros] = $ctx;

    $cultiste = Monstre::where('nom_base', 'Cultiste du Dread')->firstOrFail();
    $voisine = caseAdjacenteLibre($quete, (int) $boss->position_x, (int) $boss->position_y);
    InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => $cultiste->id,
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 2,
        'position_x' => $voisine['x'], 'position_y' => $voisine['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    // Le Cultiste porte l'archétype `culte_effroi`, qui connaît le sort : un 3
    // devient donc un 4, et le palier bascule de « résiste » à « 1 PV ».
    $sort = actionDuSort(tourDreadAvec($ctx, [3]), "Canaliser l'Effroi");

    expect($sort['resultats'][0]['lanceurs_adjacents'])->toBe(1)
        ->and($sort['resultats'][0]['total'])->toBe(4)
        ->and($sort['resultats'][0]['degats'])->toBe(1);
});

// ==================================================================
// CONTRÔLE
// ==================================================================

it('Sommeil PREND toujours, et se rompt sur un 6 à l\'ouverture du tour', function () {
    // « The spell can be broken immediately or ON A FUTURE TURN by the hero
    // rolling 1 red die for each of their Mind Points. If a 6 is rolled, the
    // spell is broken. »
    // ⚠ Notre Sommeil était un `jet_mind` AU LANCER : il pouvait rater d'emblée
    // et, une fois posé, ne se levait jamais tout seul.
    $ctx = lanceurAvecSort('Sommeil', herosAttrs: ['attribut_mind' => 1]);
    ['heros' => $heros] = $ctx;

    // 1 = rupture immédiate ratée ; 6 = rupture de l'ouverture du tour, réussie.
    $actions = tourDreadAvec($ctx, [1, 6]);
    $sort = actionDuSort($actions, 'Sommeil');

    expect($sort['resultats'][0]['effet_applique'])->toBeTrue()
        ->and($sort['resultats'][0]['rupture_immediate']['rompu'])->toBeFalse();

    // La rupture d'ouverture de tour est JOURNALISÉE : un jet que personne ne
    // voit n'a pas eu lieu pour la table.
    $rupture = collect($actions)->firstWhere('type', 'rupture_sort_dread');
    expect($rupture)->not->toBeNull()
        ->and($rupture['condition'])->toBe('Endormi')
        ->and($rupture['rompu'])->toBeTrue()
        ->and($rupture['seuil'])->toBe(6);

    expect($heros->fresh()->conditions()->where('nom', 'Endormi')->exists())->toBeFalse();
});

it('Sommeil tient tant qu\'aucun 6 ne tombe', function () {
    $ctx = lanceurAvecSort('Sommeil', herosAttrs: ['attribut_mind' => 1]);
    ['heros' => $heros] = $ctx;

    tourDreadAvec($ctx, [1, 1]);

    expect($heros->fresh()->conditions()->where('nom', 'Endormi')->exists())->toBeTrue();
});

it('Tourmente fait SAUTER le tour suivant, sans aucun jet', function () {
    // « That hero then misses their next turn. » Aucune résistance.
    // ⚠ `perd_prochain_tour` vivait au catalogue SANS LECTEUR depuis la création
    // de la table : un héros étourdi jouait normalement.
    $ctx = lanceurAvecSort('Tourmente', 'Champion');
    ['heros' => $heros, 'quete' => $quete] = $ctx;

    $actions = tourDreadAvec($ctx, []);

    expect(actionDuSort($actions, 'Tourmente')['resultats'][0]['effet_applique'])->toBeTrue();

    // Le tour suivant s'ouvre : la condition est consommée ET le créneau grillé.
    $perdu = collect($actions)->firstWhere('type', 'tour_perdu');
    expect($perdu)->not->toBeNull()
        ->and($perdu['cause'])->toBe('Étourdi');

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $heros->id)->firstOrFail();

    expect((bool) $etat->a_joue)->toBeTrue()
        ->and($heros->fresh()->conditions()->where('nom', 'Étourdi')->exists())->toBeFalse();
});

it("Nuée d'Effroi paralyse TOUS les héros de la salle", function () {
    // « This spell paralyzes ALL heroes located in the same room or corridor. »
    $ctx = lanceurAvecSort("Nuée d'Effroi", herosAttrs: ['attribut_mind' => 1]);
    ['quete' => $quete, 'heros' => $heros] = $ctx;

    // Aucun 6 : ni la rupture immédiate, ni celle de l'ouverture ne libèrent.
    $sort = actionDuSort(tourDreadAvec($ctx, [1, 1]), "Nuée d'Effroi");

    expect($sort)->not->toBeNull()
        ->and($sort['cases_affectees'])->not->toBeEmpty()
        ->and($sort['resultats'][0]['effet_applique'])->toBeTrue()
        ->and($heros->fresh()->conditions()->where('nom', 'Paralysé')->exists())->toBeTrue();
});

it('Choc Mental PLAFONNE la défense à 1 dé (et non à zéro)', function () {
    // « The hero DEFENDS WITH 1 COMBAT DIE. » Toute la différence avec la Nuée
    // d'Effroi, qui, elle, supprime la défense.
    $ctx = lanceurAvecSort('Choc Mental', herosAttrs: ['attribut_mind' => 1]);
    ['heros' => $heros] = $ctx;

    tourDreadAvec($ctx, [1, 1]);

    expect($heros->fresh()->conditions()->where('nom', 'Esprit brisé')->exists())->toBeTrue();

    $sorts = app(App\Partie\MoteurSorts::class);
    expect($sorts->desDefenseHeros($heros->fresh()))->toBe(1);

    // …et l'attaque, elle, tombe à zéro : « cannot move or attack ».
    expect(app(App\Partie\MoteurDread::class)->plafondDesAttaque($heros->fresh()))->toBe(0);
});

it("Feux de l'Effroi donne +1 dé aux monstres et se rompt sur 5-6, à UN dé", function () {
    // « All monsters roll one additional Attack die when attacking the affected
    // hero. […] rolling 1 red die. On a roll of 5 or 6, the spell is broken. »
    // ⚠ Seule carte dont la rupture ne se joue PAS sur le Mind : un
    // `rupture_6_par_mind` déguisé rendrait le sort presque inébranlable pour un
    // barbare (Mind 1) et trivial pour un magicien.
    $ctx = lanceurAvecSort("Feux de l'Effroi", herosAttrs: ['attribut_mind' => 4]);
    ['heros' => $heros] = $ctx;

    // 4 = rupture immédiate ratée (seuil 5). UN seul dé, malgré Mind 4.
    $actions = tourDreadAvec($ctx, [4, 4]);
    $sort = actionDuSort($actions, "Feux de l'Effroi");

    expect($sort['resultats'][0]['rupture_immediate']['seuil'])->toBe(5)
        ->and($sort['resultats'][0]['rupture_immediate']['faces'])->toHaveCount(1);

    expect($heros->fresh()->conditions()->where('nom', 'Désigné')->exists())->toBeTrue()
        ->and(app(App\Partie\MoteurDread::class)->bonusAttaqueContre($heros->fresh()))->toBe(1);
});

it('Étreinte des Ronces immobilise sur un crâne, et se coupe à la hache', function () {
    // « They must roll 1 combat die. If they roll a SKULL, they suffer 1 Body
    // Point of damage and are restrained. The targeted hero or another adjacent
    // hero can spend an action to destroy the vines. »
    // ⚠ Premier producteur d'*Immobilisé*, et premier lecteur de son
    // `fin: liberation` — la condition dormait au catalogue, posée par personne.
    $ctx = lanceurAvecSort('Étreinte des Ronces', 'Champion');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = $ctx;

    $pvAvant = (int) $heros->pv_body;
    $sort = actionDuSort(tourDreadAvec($ctx, [1]), 'Étreinte des Ronces'); // 1 = crâne

    expect($sort['resultats'][0]['crane'])->toBeTrue()
        ->and((int) $heros->fresh()->pv_body)->toBe($pvAvant - 1)
        ->and($heros->fresh()->conditions()->where('nom', 'Immobilisé')->exists())->toBeTrue();

    // L'option de libération apparaît au menu, et elle porte ses cibles.
    App\Jobs\GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);
    $menu = Illuminate\Support\Facades\Cache::get(
        App\Jobs\GenererMenu::cleMenu($groupe->id, (int) $alice->id),
    );
    $option = collect($menu['menu']['options'])->firstWhere('type', 'liberer_entraves');

    expect($option)->not->toBeNull()
        ->and($option['parametres']['cibles'][0]['soi'])->toBeTrue();

    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', [
            'option_id' => $option['id'],
            'parametres' => ['cible_id' => (int) $heros->id],
        ])->assertStatus(202);

    expect($heros->fresh()->conditions()->where('nom', 'Immobilisé')->exists())->toBeFalse();
});

// ==================================================================
// DESTRUCTION
// ==================================================================

it('Rouille DÉTRUIT la pièce de métal la plus chère, pour de bon', function () {
    // « causes any one metal sword or helmet to become so thin, brittle, and
    // useless that IT CAN NEVER BE USED AGAIN. »
    // ⚠ Le seul sort du paquet dont l'effet survit à la quête (arbitrage de
    // René, 2026-09-04). Il n'y a ni condition, ni rupture : rien à annuler.
    $ctx = lanceurAvecSort('Rouille');
    ['heros' => $heros] = $ctx;

    $epee = App\Models\Objet::where('nom', 'Épée longue')->firstOrFail();
    App\Models\Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $epee->id,
        'quantite' => 1, 'emplacement' => 'arme_principale',
    ]);
    app(App\Partie\Equipement::class)->recalculerCombat($heros->refresh());
    $desAvant = (int) $heros->des_attaque;

    $sort = actionDuSort(tourDreadAvec($ctx, []), 'Rouille');

    expect($sort)->not->toBeNull()
        ->and($sort['resultats'][0]['objet_detruit'])->toBe('Épée longue')
        ->and($sort['resultats'][0]['emplacement'])->toBe('arme_principale');

    // La ligne d'inventaire est partie, et les DÉS ont suivi : `des_attaque` est
    // une colonne tenue à jour à l'équipement, pas un calcul à la volée — la
    // supprimer sans recalculer laisserait le héros frapper avec une épée qu'il
    // n'a plus.
    expect($heros->fresh()->inventaire()->where('objet_id', $epee->id)->exists())->toBeFalse()
        ->and((int) $heros->fresh()->des_attaque)->toBeLessThan($desAvant);
});

it('Rouille épargne les ARTEFACTS et le bois', function () {
    // « NOT EFFECTIVE AGAINST ARTIFACTS » — et le Bâton n'est pas en métal,
    // donc immunisé sans qu'aucune exception n'ait été écrite pour lui.
    $ctx = lanceurAvecSort('Rouille');
    ['heros' => $heros] = $ctx;

    $baton = App\Models\Objet::where('nom', 'Bâton')->firstOrFail();
    $artefact = App\Models\Objet::where('rarete', 'unique')
        ->where('emplacement', 'arme_principale')->firstOrFail();

    App\Models\Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $artefact->id,
        'quantite' => 1, 'emplacement' => 'arme_principale',
    ]);
    App\Models\Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $baton->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);

    // Aucune prise : le sort n'est même pas choisi, il ne brûle pas d'usage.
    expect(actionDuSort(tourDreadAvec($ctx, []), 'Rouille'))->toBeNull()
        ->and($heros->fresh()->inventaire()->count())->toBe(2);
});

it('Rouille ne touche pas le SAC, seulement ce qui est porté', function () {
    // Zargon désigne une pièce qu'il voit ; rouiller une épée de rechange au
    // fond d'un havresac ne se lit pas sur le plateau.
    $ctx = lanceurAvecSort('Rouille');
    ['heros' => $heros] = $ctx;

    $epee = App\Models\Objet::where('nom', 'Épée longue')->firstOrFail();
    App\Models\Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $epee->id,
        'quantite' => 1, 'emplacement' => 'sac',
    ]);

    expect(actionDuSort(tourDreadAvec($ctx, []), 'Rouille'))->toBeNull()
        ->and($heros->fresh()->inventaire()->where('objet_id', $epee->id)->exists())->toBeTrue();
});

// ==================================================================
// INVOCATION, RÉANIMATION, SOIN
// ==================================================================

it('Invocation de morts-vivants tire sa COMPOSITION sur un d6', function () {
    // « On 1 or 2 = 4 skeletons. On 3 or 4 = 3 skeletons, 2 zombies. On 5 or
    // 6 = 2 zombies, 2 mummies. »
    // ⚠ Notre version invoquait deux squelettes, point : la table du dé est ce
    // qui sépare un renfort d'une bascule de combat.
    $ctx = lanceurAvecSort('Invocation de morts-vivants');
    ['quete' => $quete, 'instance' => $boss, 'etatHeros' => $etat] = $ctx;

    // L'invocation ne part jamais AU CONTACT : on éloigne le héros.
    $loin = null;
    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $type) {
            if (in_array($type, ['s', 'p'], true) && caseQueteLibre($quete, (int) $x, (int) $y)
                && abs((int) $x - (int) $boss->position_x) + abs((int) $y - (int) $boss->position_y) >= 4) {
                $loin = ['x' => (int) $x, 'y' => (int) $y];
                break 2;
            }
        }
    }
    expect($loin)->not->toBeNull();
    $etat->update(['position_x' => $loin['x'], 'position_y' => $loin['y']]);

    $sort = actionDuSort(tourDreadAvec($ctx, [5]), 'Invocation de morts-vivants');

    expect($sort)->not->toBeNull()->and($sort['de'])->toBe(5);

    $noms = collect($sort['invoques'])->pluck('monstre')->countBy();
    // 5 → 2 zombies + 2 momies, dans la limite des cases libres adjacentes.
    expect($noms->keys()->all())->toEqualCanonicalizing(
        array_values(array_unique($noms->keys()->all())),
    );
    expect(collect($sort['invoques']))->not->toBeEmpty()
        ->and($noms->keys()->every(fn ($n) => in_array($n, ['Zombie', 'Momie'], true)))->toBeTrue();
});

it('Réanimation relève les morts-vivants VAINCUS de la salle du lanceur', function () {
    // « Reanimate ALL DEFEATED skeletons, zombies, or mummies in the same room
    // as the spellcaster, with all lost Body Points restored. »
    // ⚠ Le seul sort du paquet qui rende une victoire réversible, et il ne
    // demandait rien de neuf : nos instances vaincues restent en base.
    $ctx = lanceurAvecSort('Réanimation');
    ['quete' => $quete, 'instance' => $boss] = $ctx;

    $squelette = Monstre::where('nom_base', 'Squelette')->firstOrFail();
    $voisine = caseAdjacenteLibre($quete, (int) $boss->position_x, (int) $boss->position_y);
    $mort = InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => $squelette->id,
        'pv_body' => 0, 'pv_body_max' => $squelette->pv_body, 'pv_mind' => 0,
        'position_x' => $voisine['x'], 'position_y' => $voisine['y'],
        'etat' => 'vaincu', 'revele' => true,
    ]);

    $sort = actionDuSort(tourDreadAvec($ctx, []), 'Réanimation');

    expect($sort)->not->toBeNull()
        ->and($sort['releves'])->toHaveCount(1);

    $mort->refresh();
    expect($mort->etat)->toBe('actif')
        ->and((int) $mort->pv_body)->toBe((int) $squelette->pv_body);
});

it('Apaisement rend au plus ce qui a été PERDU', function () {
    // « Restores UP TO 3 lost Body Points to the spellcaster or any one
    // monster » : le plafond est ce qui manque, jamais le maximum.
    $ctx = lanceurAvecSort('Apaisement', 'Champion');
    ['instance' => $boss] = $ctx;

    $max = (int) $boss->pv_body_max;
    $boss->update(['pv_body' => $max - 1]); // un seul PV perdu

    $sort = actionDuSort(tourDreadAvec($ctx, []), 'Apaisement');

    expect($sort)->not->toBeNull()
        ->and($sort['soin'])->toBe(1)
        ->and($sort['sur_soi'])->toBeTrue()
        ->and((int) $boss->fresh()->pv_body)->toBe($max);
});

it('un soin ne part PAS quand personne n\'est blessé', function () {
    // Un usage dépensé pour un journal vide est le défaut que la Tempête de feu
    // portait avant que le choix et la résolution ne lisent la même zone.
    $ctx = lanceurAvecSort('Apaisement', 'Champion');

    expect(actionDuSort(tourDreadAvec($ctx, []), 'Apaisement'))->toBeNull();
});
