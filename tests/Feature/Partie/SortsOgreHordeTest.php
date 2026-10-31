<?php

declare(strict_types=1);

use App\Engine\MotsClesSortDread as Mot;
use App\Events\EtatGroupeDiffuse;
use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Quete;
use App\Models\SortDread;
use App\Partie\BestiaireGroupe;
use App\Partie\CartesDread;
use App\Partie\ControleMj;
use App\Partie\DemarreurQuete;
use App\Partie\EtatGroupe;
use App\Partie\Equipement;
use App\Partie\FabriqueGrille;
use App\Partie\GardePersonnelle;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Partie\SceneDeTable;
use App\Partie\Sauvegarde;
use App\Partie\VerrouMental;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * LES SORCIERS DU DREAD d'*Against the Ogre Horde* (F9528 p. 10-11, 31, 35, 37) — Nexrael, Xenloth,
 * Festral et leurs trois sorts : *Mind Burst*, *Mind Lock*, *Dominate*.
 *
 * Même méthode que `SortsDreadCartesTest` : le catalogue et le vocabulaire sont verrouillés DANS LES DEUX
 * SENS (premier bloc), puis chaque mécanique est JOUÉE par les vraies routes de la manette — le MJ ne
 * lance aucun dé qu'un test n'ait fixé (`desFiges()`).
 *
 * ⚠ L'ordre des dés décide de la lisibilité de chaque cas. d6 : 1-3 crâne, 4-5 bouclier BLANC, 6 noir. Un jet
 * en opposition lance d'abord les dés du SORCIER (autant que son Mind), puis ceux du HÉROS (autant que le
 * sien) ; le reste de la file est rempli de 4 (bouclier blanc : jamais un crâne, jamais un coup qui porte).
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
// Helpers locaux (préfixés : les fichiers de test partagent l'espace des fonctions)
// ------------------------------------------------------------------

/** [déplacement, attaque, défense, body, mind] d'un bloc de catalogue. */
function ogresStats(string $nom): array
{
    $m = Monstre::where('nom_base', $nom)->firstOrFail();

    return [(int) $m->deplacement, (int) $m->attaque, (int) $m->defense, (int) $m->pv_body, (int) $m->pv_mind];
}

/**
 * Une quête où le monstre nommé est LE seul, au contact de l'unique héros — et où il n'a QUE les cartes données
 * (le stock se règle sort par sort : un Sorcier à court d'une carte choisit une autre).
 *
 * @param  array<string, int>|null  $cartes
 * @return array<string, mixed>
 */
function ogresQuete(string $monstre, array $herosAttrs = [], ?array $cartes = null): array
{
    $ctx = demarrerQueteAvecMonstre($monstre, $herosAttrs);

    if ($cartes !== null) {
        $ctx['instance']->poserEtatDread('cartes', $cartes);
        $ctx['instance']->refresh()->load('monstre');
    }

    return $ctx;
}

/**
 * La phase des monstres, déclenchée par la fin du tour de l'unique héros. Rend la RÉPONSE entière.
 *
 * @param  list<int>  $des
 */
function ogresTour(array $ctx, array $des): array
{
    desFiges([...$des, ...array_fill(0, 300, 4)]);

    return test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->json();
}

/** Les actions du tour des monstres d'une réponse. */
function ogresActions(array $reponse): array
{
    return array_values((array) data_get($reponse, 'resultat.tour_monstres.actions', []));
}

/** L'action de sort de Dread portant ce nom, ou `null`. */
function ogresSort(array $actions, string $nom): ?array
{
    return collect($actions)->firstWhere('sort', $nom);
}

/**
 * Une quête à DEUX héros (Albrecht, puis Brunhild) autour du monstre nommé : tous trois côte à côte.
 *
 * @param  array<string, mixed>  $attrs1
 * @param  array<string, mixed>  $attrs2
 * @return array<string, mixed>
 */
function ogresQuete2(string $nomMonstre, array $attrs1 = [], array $attrs2 = [], ?array $cartes = null, ?Closure $avant = null): array
{
    $alice = connecterJoueur('alice');
    $bob = connecterJoueur('bob');
    $groupe = creerGroupe();
    $h1 = creerHeros($alice, $groupe, 'Albrecht', 1, $attrs1);
    $h2 = creerHeros($bob, $groupe, 'Brunhild', 2, $attrs2);

    if ($avant !== null) {
        $avant($h1, $h2);
    }

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $catalogue = Monstre::where('nom_base', $nomMonstre)->firstOrFail();
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update([
        'monstre_id' => $catalogue->id, 'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind, 'etat' => 'actif', 'elite' => false,
    ]);
    $instance->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $quete);

    $etat1 = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h1->id)->firstOrFail();
    $etat2 = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h2->id)->firstOrFail();

    $voisine = caseAdjacenteLibre($quete, (int) $etat1->position_x, (int) $etat1->position_y);
    $etat2->update(['position_x' => $voisine['x'], 'position_y' => $voisine['y']]);
    $contact = caseAdjacenteLibre($quete, (int) $etat1->position_x, (int) $etat1->position_y);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    if ($cartes !== null) {
        $instance->refresh()->load('monstre');
        $instance->poserEtatDread('cartes', $cartes);
    }

    return [
        'alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'quete' => $quete,
        'h1' => $h1, 'h2' => $h2, 'etat1' => $etat1->refresh(), 'etat2' => $etat2->refresh(),
        'instance' => $instance->refresh()->load('monstre'),
    ];
}

/** La phase des monstres d'un groupe à deux héros : Albrecht passe, puis Brunhild (dés fixés pour la phase seule). */
function ogresTour2(array $ctx, array $des): array
{
    desFiges(array_fill(0, 300, 4));
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    desFiges([...$des, ...array_fill(0, 300, 4)]);

    return test()->actingAs($ctx['bob'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->json();
}

/** Gèle ce héros à la main, comme le ferait un *Mind Lock* gagné — pour les cas qui ne jouent pas le lancer. */
function ogresGeler(array $ctx, $heros, InstanceMonstre $lanceur, int $nets): int
{
    $sort = SortDread::where('nom', 'Verrou mental')->firstOrFail();
    $etat = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $heros->id)->firstOrFail();
    $prises = app(VerrouMental::class)->enregistrer($etat, $lanceur, $sort, $nets);

    $heros->conditions()->attach(Condition::where('nom', 'Verrou mental')->firstOrFail()->id, [
        'duree' => 0, 'source' => 'sort_dread:Verrou mental',
    ]);

    return $prises;
}

/** Achats d'une rencontre (méthode privée → réflexion), pour un bestiaire donné. */
function ogresAchats(BestiaireGroupe $bestiaire, array $structure, int $positionArc, int $graine, int $budget = 40): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    return $methode->invoke($demarreur, $structure, $budget, 12, $positionArc, $graine, $bestiaire);
}

// ==================================================================
// 1. LE CATALOGUE ET LE VOCABULAIRE — dans les deux sens
// ==================================================================

it('recense les TROIS cartes d\'Against the Ogre Horde au registre, portées, chacune au catalogue', function () {
    $cartes = collect(config('cartes.dread.cartes'))->where('paquet', 'Against the Ogre Horde')->values();

    expect($cartes->pluck('carte')->all())->toBe(['Mind Lock', 'Dominate', 'Mind Burst'])
        ->and($cartes->pluck('sort_dread')->all())->toBe(['Verrou mental', 'Domination', 'Explosion mentale']);

    foreach ($cartes as $carte) {
        expect(SortDread::where('nom', $carte['sort_dread'])->exists())->toBeTrue("{$carte['carte']} : absente du catalogue.");
    }

    // …et AUCUNE de ces cartes n'est restée en dette nommée : elles sont portées en entier.
    expect($cartes->whereNull('sort_dread'))->toBeEmpty();
});

it('porte les trois sorts avec leur type, leur palier, leur résistance et le destin de leur carte', function () {
    $verrou = SortDread::where('nom', 'Verrou mental')->firstOrFail();
    $domination = SortDread::where('nom', 'Domination')->firstOrFail();
    $explosion = SortDread::where('nom', 'Explosion mentale')->firstOrFail();

    expect($verrou->type)->toBe(Mot::TYPE_CONTROLE)
        ->and($verrou->effet)->toMatchArray([
            'resistance' => Mot::RESISTANCE_OPPOSITION_NETTE, 'gel_par_crane' => 1, 'liberation_cranes' => 3,
            'carte' => Mot::CARTE_PRETEE, 'condition_appliquee' => 'Verrou mental',
        ])
        ->and($domination->type)->toBe(Mot::TYPE_DOMINATION)
        ->and($domination->effet)->toMatchArray([
            'resistance' => Mot::RESISTANCE_OPPOSITION_NETTE, 'cranes_requis' => 2, 'carte' => Mot::CARTE_PRETEE,
        ])
        ->and($explosion->type)->toBe(Mot::TYPE_MIND)
        ->and($explosion->effet)->toMatchArray([
            'resistance' => Mot::RESISTANCE_OPPOSITION_ECART, 'carte' => Mot::CARTE_DEFAUSSEE,
        ]);
});

it('confronte `resistance` (opposition) et `carte` aux sorts DANS LES DEUX SENS', function () {
    $resistances = SortDread::all()->map(fn ($s) => data_get($s->effet, 'resistance'))->filter()->unique()->all();

    // Chaque forme d'opposition déclarée est portée par un sort…
    foreach (Mot::RESISTANCES_OPPOSITION as $mot) {
        expect(in_array($mot, $resistances, true))->toBeTrue("« {$mot} » est déclarée sans aucun sort qui la porte.");
        expect(in_array($mot, Mot::RESISTANCES, true))->toBeTrue("« {$mot} » n'est pas au vocabulaire des résistances.");
    }

    // …et `carte` ne dit que des valeurs déclarées, chacune employée.
    $employees = SortDread::all()->map(fn ($s) => data_get($s->effet, 'carte'))->filter()->unique()->values()->all();

    expect(array_diff($employees, Mot::CARTES))->toBe([], 'valeur de `carte` hors vocabulaire')
        ->and(array_diff(Mot::CARTES, $employees))->toBe([], 'valeur de `carte` déclarée mais jamais employée');
});

it('porte les stats des trois Sorciers EXACTEMENT comme leurs blocs du livret (p. 31, 35, 37)', function () {
    // [déplacement, attaque, défense, body, mind] — relus sur le RENDU PNG de chaque page :
    // Nexrael 3 dés / 4 dés / 8 / 1 / 5 ; Festral 4 / 5 / 6 / 3 / 8 ; Xenloth 2 / 4 / 6 / 1 / 4.
    expect(ogresStats('Nexrael'))->toBe([8, 3, 4, 1, 5])
        ->and(ogresStats('Festral'))->toBe([6, 4, 5, 3, 8])
        ->and(ogresStats('Xenloth'))->toBe([6, 2, 4, 1, 4]);
});

it('range chaque Sorcier au palier que sa mesure et la décision de René lui donnent', function () {
    $nexrael = Monstre::where('nom_base', 'Nexrael')->firstOrFail();

    expect($nexrael->tier)->toBe('base')
        ->and($nexrael->boite)->toBe('horde_ogre')
        // Un « fort » (au plus un par quête), jamais dans la masse des faibles.
        ->and((int) $nexrael->cout)->toBeGreaterThan((int) config('jeu.rencontres.seuil_cout_fort', 3))
        ->and(Monstre::where('nom_base', 'Xenloth')->firstOrFail()->tier)->toBe('sous_boss')
        ->and(Monstre::where('nom_base', 'Festral')->firstOrFail()->tier)->toBe('boss');
});

it('donne à CHAQUE Sorcier une réserve de cartes qui épouse son répertoire, dans les deux sens', function () {
    $attendues = [
        'Nexrael' => ['Explosion mentale' => 4],
        'Xenloth' => ['Verrou mental' => 5, 'Explosion mentale' => 5],
        'Festral' => ['Explosion mentale' => 3, 'Verrou mental' => 3, 'Domination' => 3],
    ];

    foreach ($attendues as $nom => $reserve) {
        $monstre = Monstre::where('nom_base', $nom)->firstOrFail();
        $repertoire = config("archetypes_lanceurs.{$monstre->archetype_lanceur}.sorts");

        expect($monstre->capacites['reserve_cartes'])->toBe($reserve, "{$nom} : réserve ≠ livret")
            // Chaque sort du répertoire a une réserve, chaque réserve nomme un sort du répertoire.
            ->and(collect($repertoire)->sort()->values()->all())->toBe(collect(array_keys($reserve))->sort()->values()->all(), "{$nom} : répertoire ≠ réserve")
            ->and(config("archetypes_lanceurs.{$monstre->archetype_lanceur}.porteur"))->toBe($nom);
    }

    // Tout monstre qui porte `reserve_cartes` est l'un de ces trois : aucune réserve orpheline.
    expect(Monstre::all()->filter(fn ($m) => array_key_exists('reserve_cartes', (array) $m->capacites))->pluck('nom_base')->sort()->values()->all())
        ->toBe(['Festral', 'Nexrael', 'Xenloth']);
});

it('met Xenloth au pool des sous-boss et Festral à celui des boss, Nexrael dans aucun', function () {
    $sousBoss = (array) data_get(GabaritQuete::where('nom', 'Antre du sous-boss')->firstOrFail()->structure, 'rencontre_finale.archetypes');
    $boss = (array) data_get(GabaritQuete::where('nom', 'Confrontation finale')->firstOrFail()->structure, 'rencontre_finale.archetypes');

    expect($sousBoss)->toContain('xenloth_mage_dread')->not->toContain('festral_sorcier_dread')
        ->and($boss)->toContain('festral_sorcier_dread')->not->toContain('xenloth_mage_dread')
        ->and([...$sousBoss, ...$boss])->not->toContain('nexrael_apprenti');
});

it('FAIT APPARAÎTRE Festral au boss final du thème Horde ogre, Xenloth au sous-boss, Nexrael parmi les forts', function () {
    $bestiaire = BestiaireGroupe::auto('horde_ogre');

    $boss = collect(range(1, 8))->map(fn (int $graine) => ogresAchats(
        $bestiaire, ['rencontre_finale' => ['tier' => 'boss', 'archetypes' => ['seigneur_sorcier_barak_tor', 'festral_sorcier_dread']]], 1, $graine,
    )[0]->nom_base)->unique();

    expect($boss)->toContain('Festral');

    $sousBoss = ogresAchats($bestiaire, ['rencontre_finale' => ['tier' => 'sous_boss', 'archetypes' => ['xenloth_mage_dread']]], 1, 1);
    expect($sousBoss[0]->nom_base)->toBe('Xenloth');

    // Nexrael : tier `base`, `horde_ogre`, « fort » — la créature de signature du thème, en tête des forts.
    $achats = collect(ogresAchats($bestiaire, [], 1, 1))->pluck('nom_base');
    expect($achats)->toContain('Nexrael');
});

// ==================================================================
// 2. MIND BURST — l'opposition de crânes, la carte défaussée, le lanceur qui paie
// ==================================================================

it('MIND BURST : le Sorcier fait plus de crânes — le héros perd l\'ÉCART en Mind, et la carte est DÉFAUSSÉE', function () {
    // « The combatant with the most rolled skulls then inflicts Mind Point damage on their opponent.
    // The damage dealt is equal to the difference in skulls. »
    $ctx = ogresQuete('Nexrael');
    ['heros' => $heros, 'instance' => $nexrael] = $ctx;

    expect(CartesDread::restantes($nexrael, 'Explosion mentale'))->toBe(4);

    // Nexrael (Mind 5) : 3 crânes ; le héros (Mind 2) : 1 crâne. Écart 2 → Mind 2 → 0.
    $reponse = ogresTour($ctx, [1, 1, 1, 4, 4, 1, 4]);
    $actions = ogresActions($reponse);
    $sort = ogresSort($actions, 'Explosion mentale');

    // Règle commune (p. 10) : UN sort par tour, et « may not make a weapon attack in the same turn ».
    expect(collect($actions)->where('type', 'sort_dread')->count())->toBe(1)
        ->and(collect($actions)->where('type', 'attaque_monstre')->count())->toBe(0)
        ->and(collect($actions)->where('type', 'deplacement_monstre')->count())->toBe(0);

    expect($sort)->not->toBeNull()
        ->and($sort['lanceur']['nom'])->toBe('Nexrael')
        ->and($sort['resultats'][0])->toMatchArray([
            'touches' => 3, 'boucliers' => 1, 'ecart_cranes' => 2, 'degats_mind' => 2,
            'pv_mind_apres' => 0, 'entre_en_choc' => true, 'degats_mind_lanceur' => 0,
        ])
        ->and((int) $heros->fresh()->pv_mind)->toBe(0)
        ->and($heros->fresh()->estEnChoc())->toBeTrue()
        // « Each time they cast Mind Burst, they discard one of their spell cards. »
        ->and(CartesDread::restantes($nexrael->fresh(), 'Explosion mentale'))->toBe(3);
});

it('MIND BURST : le HÉROS fait plus de crânes — c\'est le SORCIER qui perd du Mind, « lanceur compris »', function () {
    // « Because the wizard rolled two more skulls than their opponent, the Dread Sorcerer takes 2 Mind
    // Points of damage. » Héros à Mind 4 (≤ 5 : Nexrael le vise), 3 crânes contre 0.
    $ctx = ogresQuete('Nexrael', ['attribut_mind' => 4, 'pv_mind_max' => 4, 'pv_mind' => 4]);
    ['heros' => $heros, 'instance' => $nexrael] = $ctx;

    $sort = ogresSort(ogresActions(ogresTour($ctx, [4, 4, 4, 4, 4, 1, 1, 1, 4])), 'Explosion mentale');

    expect($sort['resultats'][0])->toMatchArray([
        'touches' => 0, 'boucliers' => 3, 'ecart_cranes' => -3, 'degats_mind' => 0,
        'degats_mind_lanceur' => 3, 'pv_mind_lanceur_apres' => 2, 'lanceur_en_choc' => false,
    ])
        ->and((int) $heros->fresh()->pv_mind)->toBe(4)
        // Le PREMIER producteur de Mind côté monstre : la jauge de l'instance a baissé.
        ->and((int) $nexrael->fresh()->pv_mind)->toBe(2);
});

it('MIND BURST : un Mind à zéro met le SORCIER en état de choc — 1 dé d\'attaque, 2 de défense (p. 9)', function () {
    // Mind 5 → 2 (déjà entamé), héros Mind 2 : 2 dés chacun, le héros fait 2 crânes contre 0 → −2 → 0.
    $ctx = ogresQuete('Nexrael');
    ['instance' => $nexrael] = $ctx;
    $nexrael->update(['pv_mind' => 2]);

    expect($nexrael->fresh()->load('monstre')->defenseEffective())->toBe(4);

    $sort = ogresSort(ogresActions(ogresTour($ctx, [4, 4, 1, 1])), 'Explosion mentale');

    expect($sort['resultats'][0])->toMatchArray(['ecart_cranes' => -2, 'degats_mind_lanceur' => 2, 'lanceur_en_choc' => true]);

    $apres = $nexrael->fresh()->load('monstre');

    expect((int) $apres->pv_mind)->toBe(0)
        ->and($apres->estEnChoc())->toBeTrue()
        // « can only roll […] 1 Attack die, and 2 Defend dice » : Nexrael (3 / 4) est plafonné.
        ->and($apres->attaqueEffective())->toBe(1)
        ->and($apres->defenseEffective())->toBe(2);
});

it('une créature SANS Mind n\'est jamais « en choc » : c\'est sa nature, pas une perte', function () {
    $momie = Monstre::where('nom_base', 'Momie')->firstOrFail();
    $instance = new InstanceMonstre(['pv_mind' => 0]);
    $instance->setRelation('monstre', $momie);

    expect((int) $momie->pv_mind)->toBe(0)
        ->and($instance->estEnChoc())->toBeFalse();

    // Le producteur ne retire rien à qui n'a rien : `0 → 0`.
    $vraie = new InstanceMonstre(['pv_mind' => 0]);
    expect(app(MoteurDegats::class)->infligerMindAMonstre($vraie, 3, MoteurDegats::SOURCE_SORT_DREAD_MIND))->toBe(0);
});

it('MIND BURST n\'est PAS lancé sur un héros dont le Mind dépasse celui du Sorcier — il le perdrait', function () {
    $ctx = ogresQuete('Nexrael', ['attribut_mind' => 6, 'pv_mind_max' => 6, 'pv_mind' => 6]);
    ['instance' => $nexrael] = $ctx;

    $actions = ogresActions(ogresTour($ctx, []));

    expect(ogresSort($actions, 'Explosion mentale'))->toBeNull()
        // Aucune carte brûlée pour un jet qu'il perdrait.
        ->and(CartesDread::restantes($nexrael->fresh(), 'Explosion mentale'))->toBe(4);
});

it('à COURT de cartes un Sorcier ne lance plus : la réserve EST son budget', function () {
    $ctx = ogresQuete('Nexrael', cartes: ['Explosion mentale' => 1]);
    ['instance' => $nexrael] = $ctx;

    ogresTour($ctx, [1, 1, 1, 4, 4, 1, 4]);

    expect(CartesDread::restantes($nexrael->fresh(), 'Explosion mentale'))->toBe(0)
        ->and(CartesDread::total($nexrael->fresh()))->toBe(0)
        ->and(app(MoteurDread::class)->usagesRestants($nexrael->fresh(), $ctx['quete']))->toBe(0);

    // Le round suivant : plus de carte, plus de sort — il se bat comme une créature ordinaire.
    $ctx['quete']->etatsPersonnages()->update(['a_joue' => false]);
    $actions = ogresActions(ogresTour($ctx, []));

    expect(ogresSort($actions, 'Explosion mentale'))->toBeNull();
});

it('UN LANCEUR DE TIER BASE LANCE : la phase des monstres appelle `jouerTourDread()` dès qu\'il a un répertoire', function () {
    // ⚠ Le défaut que Nexrael a mis au jour : le tier `base` n'ouvrait jamais cette porte, si bien que le
    // Cultiste du Dread (répertoire, archétype, usage réarmé…) ne lançait JAMAIS rien en partie.
    $ctx = demarrerQueteAvecMonstre('Cultiste du Dread');

    expect($ctx['instance']->monstre->tier)->toBe('base');

    $actions = ogresActions(ogresTour($ctx, []));
    $sorts = collect($actions)->where('type', 'sort_dread')->pluck('sort')->all();

    expect(array_intersect($sorts, ["Canaliser l'Effroi", "Feux de l'Effroi"]))->not->toBeEmpty('le Cultiste (tier base) ne lance rien');
});

// ==================================================================
// 3. MIND LOCK — le gel, les cartes, la libération, la mort du lanceur
// ==================================================================

it('MIND LOCK : un crâne NET = un tour gelé = une carte prêtée ; chaque tour perdu en rend une, puis le héros est libre', function () {
    // Xenloth sans carte de Mind Burst : il verrouille. « For each skull scored by the attacker, the
    // defender is frozen for one turn. » Xenloth (Mind 4) : 3 crânes ; le héros (Mind 2) : 1 bouclier
    // blanc — 2 crânes NETS (décision de René), donc 2 tours gelés et 2 cartes prêtées.
    $ctx = ogresQuete('Xenloth', cartes: ['Verrou mental' => 5, 'Explosion mentale' => 0]);
    ['heros' => $heros, 'instance' => $xenloth, 'quete' => $quete] = $ctx;

    $reponse = ogresTour($ctx, [1, 1, 1, 4, 4, 1]);
    $actions = ogresActions($reponse);
    $sort = ogresSort($actions, 'Verrou mental');

    expect($sort['resultats'][0])->toMatchArray([
        'touches' => 3, 'boucliers' => 1, 'cranes_nets' => 2, 'effet_applique' => true, 'tours_geles' => 2,
    ]);

    $tours = collect($actions)->where('type', 'verrou_mental_tour')->values();

    // DEUX tours perdus, un par carte : l'ouverture du round suivant, puis celui d'après.
    expect($tours)->toHaveCount(2)
        ->and($tours[0])->toMatchArray(['cartes_avant' => 2, 'cartes_apres' => 1, 'libere' => false])
        // La tentative de libération lance AUTANT DE DÉS que de Mind possédés (2), seuil 3 : impossible ici.
        ->and($tours[0]['tentative'])->toMatchArray(['des' => 2, 'seuil' => 3, 'cranes' => 0, 'libere' => false])
        ->and($tours[1])->toMatchArray(['cartes_avant' => 1, 'libere' => true, 'raison' => 'cartes_rendues'])
        ->and($tours[1]['tentative'])->toBeNull();   // la dernière carte rendue : aucun dé pour rien

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();

    // Libre — et le groupe n'est PAS figé : le héros a la main (le round se rejoue tout seul tant que personne ne peut jouer).
    expect($etat->verrou_mental)->toBeNull()
        ->and($heros->fresh()->conditions()->where('nom', 'Verrou mental')->exists())->toBeFalse()
        ->and((bool) $etat->a_joue)->toBeFalse()
        // Les cartes prêtées sont RENTRÉES en main : 5 − 2 prêtées + 2 rendues.
        ->and(CartesDread::restantes($xenloth->fresh(), 'Verrou mental'))->toBe(5);
});

it('MIND LOCK : trois crânes ou plus sur autant de dés que de Mind BRISENT l\'emprise, et rendent toutes les cartes restantes', function () {
    // Héros Mind 4 (4 dés). Xenloth 4 dés : 4 crânes ; le héros : 1 bouclier blanc → 3 crânes nets, 3 cartes.
    $ctx = ogresQuete('Xenloth', ['attribut_mind' => 4, 'pv_mind_max' => 4, 'pv_mind' => 4], ['Verrou mental' => 5, 'Explosion mentale' => 0]);
    ['instance' => $xenloth] = $ctx;

    // Dés : Xenloth [1,1,1,1], héros [4,6,6,6] (un seul blanc : le noir ne pare pas un héros),
    // puis la tentative de libération du héros : [1,1,1,4] = 3 crânes.
    $actions = ogresActions(ogresTour($ctx, [1, 1, 1, 1, 4, 6, 6, 6, 1, 1, 1, 4]));
    $sort = ogresSort($actions, 'Verrou mental');
    $tour = collect($actions)->firstWhere('type', 'verrou_mental_tour');

    expect($sort['resultats'][0])->toMatchArray(['cranes_nets' => 3, 'tours_geles' => 3])
        ->and($tour)->toMatchArray(['cartes_avant' => 3, 'libere' => true, 'raison' => 'cranes'])
        ->and($tour['tentative'])->toMatchArray(['des' => 4, 'cranes' => 3, 'seuil' => 3, 'libere' => true])
        // 5 − 3 prêtées + 1 rendue au tour perdu + 2 rendues d'un coup à la libération.
        ->and(CartesDread::restantes($xenloth->fresh(), 'Verrou mental'))->toBe(5);
});

it('MIND LOCK : aucun crâne net, « the spell has no effect » — rien n\'est posé, aucune carte ne quitte la main', function () {
    $ctx = ogresQuete('Xenloth', ['attribut_mind' => 4, 'pv_mind_max' => 4, 'pv_mind' => 4], ['Verrou mental' => 5, 'Explosion mentale' => 0]);
    ['heros' => $heros, 'instance' => $xenloth] = $ctx;

    // Xenloth 4 crânes ; le héros 4 boucliers blancs : 0 net.
    $sort = ogresSort(ogresActions(ogresTour($ctx, [1, 1, 1, 1, 4, 4, 4, 4])), 'Verrou mental');

    expect($sort['resultats'][0])->toMatchArray(['cranes_nets' => 0, 'effet_applique' => false])
        ->and($heros->fresh()->conditions()->where('nom', 'Verrou mental')->exists())->toBeFalse()
        ->and(CartesDread::restantes($xenloth->fresh(), 'Verrou mental'))->toBe(5);
});

it('MIND LOCK : on ne prête JAMAIS plus de cartes qu\'il n\'en reste au Sorcier', function () {
    $ctx = ogresQuete('Xenloth', ['attribut_mind' => 4, 'pv_mind_max' => 4, 'pv_mind' => 4], ['Verrou mental' => 1, 'Explosion mentale' => 0]);

    // 3 crânes nets… mais une seule carte en main : un tour de gel, pas trois.
    $sort = ogresSort(ogresActions(ogresTour($ctx, [1, 1, 1, 1, 4, 6, 6, 6])), 'Verrou mental');

    expect($sort['resultats'][0])->toMatchArray(['cranes_nets' => 3, 'tours_geles' => 1]);
});

it('un héros GELÉ défend à 1 dé, ne bouge ni n\'agit, et sa fiche dit COMBIEN de tours il lui reste', function () {
    $ctx = ogresQuete('Xenloth', cartes: ['Verrou mental' => 5, 'Explosion mentale' => 0]);
    ['heros' => $heros, 'instance' => $xenloth, 'groupe' => $groupe] = $ctx;

    expect(ogresGeler($ctx, $heros, $xenloth, 2))->toBe(2);

    $heros = $heros->fresh();

    // Les quatre interdits de la carte, lus par les lecteurs qui existent déjà.
    expect(app(MoteurSorts::class)->desDefenseHeros($heros))->toBe(1)
        ->and(app(MoteurSorts::class)->deplacementInterdit($heros))->toBeTrue()
        ->and(app(MoteurDread::class)->plafondDesAttaque($heros))->toBe(0)
        ->and(CartesDread::restantes($xenloth->fresh(), 'Verrou mental'))->toBe(3);

    // La fiche (manette ET table) le publie, décidé par le serveur.
    $publie = collect(app(EtatGroupe::class)->payload($groupe->fresh())['entites'])->where('type', 'heros')->firstWhere('id', $heros->id);
    $condition = collect($publie['conditions'])->firstWhere('nom', 'Verrou mental');

    expect($condition)->not->toBeNull()
        ->and($condition['effet_source'])->toContain('Gelé pour 2 tours')
        ->and($condition['description'])->toContain('1 dé de combat');
});

it('MIND LOCK : la mort du Sorcier LÈVE le gel sur-le-champ, et le fil le dit (p. 10)', function () {
    // « If a Dread Sorcerer has an active spell and is killed, the effects of that spell immediately end. »
    $ctx = ogresQuete2('Xenloth', cartes: ['Verrou mental' => 5, 'Explosion mentale' => 0]);
    ['h1' => $h1, 'h2' => $h2, 'instance' => $xenloth, 'quete' => $quete] = $ctx;

    ogresGeler($ctx, $h1, $xenloth, 2);

    // Xenloth au contact de BRUNHILD (qui l'attaque) : il était posé au contact d'Albrecht.
    $etatBrunhild = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h2->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etatBrunhild->position_x, (int) $etatBrunhild->position_y);
    $xenloth->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    // Albrecht, gelé, a perdu son tour : c'est Brunhild qui joue.
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h1->id)->update(['a_joue' => true]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['bob']->id, (int) $h2->id);
    desFiges([1, 1, 1, 4, 4, 4, 4, ...array_fill(0, 200, 4)]);

    $reponse = test()->actingAs($ctx['bob'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $xenloth->id],
    ])->assertAccepted();

    expect($xenloth->fresh()->etat)->toBe('vaincu');

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h1->id)->firstOrFail();

    expect($etat->verrou_mental)->toBeNull()
        ->and($h1->fresh()->conditions()->where('nom', 'Verrou mental')->exists())->toBeFalse();

    $annonce = collect($reponse->json('resultat.annonces_automatiques'))->firstWhere('type', 'verrou_mental_leve');
    $fil = collect(app(JournalCombat::class)->depuisResultat($reponse->json('resultat'), 'Brunhild'))->pluck('texte')->implode(' ¦ ');

    expect($annonce)->toMatchArray(['nom' => 'Albrecht', 'raison' => 'lanceur_vaincu'])
        ->and($fil)->toContain('Xenloth est tombé')->toContain('Verrou mental de Albrecht cède');
});

it('le gel et les cartes SURVIVENT à une reprise de snapshot (colonne et habillage, jamais le cache)', function () {
    $ctx = ogresQuete('Xenloth', cartes: ['Verrou mental' => 5, 'Explosion mentale' => 0]);
    ['heros' => $heros, 'instance' => $xenloth, 'quete' => $quete, 'groupe' => $groupe] = $ctx;

    ogresGeler($ctx, $heros, $xenloth, 2);
    $snapshot = app(Sauvegarde::class)->snapshotter($groupe->fresh(), 'nouveau_tour');

    // Le monde bouge : le gel est levé, la réserve rendue.
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();
    app(VerrouMental::class)->lever($etat);
    CartesDread::rendre($xenloth->fresh(), 'Verrou mental', 2);

    app(Sauvegarde::class)->restaurer($groupe->fresh(), $snapshot);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();

    expect($etat->verrou_mental)->toMatchArray(['lanceur_id' => $xenloth->id, 'cartes' => 2, 'sort' => 'Verrou mental'])
        ->and($heros->fresh()->conditions()->where('nom', 'Verrou mental')->exists())->toBeTrue()
        ->and(CartesDread::restantes(InstanceMonstre::with('monstre')->findOrFail($xenloth->id), 'Verrou mental'))->toBe(3);
});

// ==================================================================
// 4. DOMINATE — le MJ joue le héros, par la couture de Possession
// ==================================================================

it('DOMINATE : 2 crânes nets ou plus — le MJ joue le héros TOUT DE SUITE et lui fait frapper son compagnon', function () {
    // « Zargon […] may immediately take that hero's full movement and action […] including attacking other
    // heroes. At the end of the Dread Sorcerer's turn, the spell ends. » Festral n'a plus que cette carte.
    $ctx = ogresQuete2('Festral', ['pv_mind_max' => 2, 'pv_mind' => 2, 'attribut_mind' => 2], ['pv_mind_max' => 4, 'pv_mind' => 4, 'attribut_mind' => 4],
        cartes: ['Domination' => 3, 'Verrou mental' => 0, 'Explosion mentale' => 0]);
    ['h1' => $h1, 'h2' => $h2, 'instance' => $festral, 'quete' => $quete] = $ctx;

    $pvAvant = (int) $h2->fresh()->pv_body;

    // Festral (Mind 8) : 8 crânes ; Albrecht (Mind 2, le plus faible) : 2 boucliers blancs → 6 nets.
    // Puis le coup d'Albrecht dominé : 3 crânes contre les 2 dés de défense de Brunhild (aucun bouclier).
    $reponse = ogresTour2($ctx, [1, 1, 1, 1, 1, 1, 1, 1, 4, 4, 1, 1, 1, 1, 1]);
    $actions = ogresActions($reponse);
    $sort = ogresSort($actions, 'Domination');

    expect($sort['resultats'][0])->toMatchArray([
        'cible' => ['personnage_id' => $h1->id, 'nom' => 'Albrecht'],
        'touches' => 8, 'boucliers' => 2, 'cranes_nets' => 6, 'cranes_requis' => 2,
        'effet_applique' => true, 'domine' => true, 'fin_domination' => true,
    ]);

    $dominees = $sort['resultats'][0]['actions_domine'];
    $coup = collect($dominees)->firstWhere('type', 'domination_attaque');

    expect($coup)->toMatchArray([
        'personnage' => 'Albrecht', 'cible' => ['personnage_id' => $h2->id, 'nom' => 'Brunhild'], 'touches' => 3, 'boucliers' => 0, 'degats' => 3,
    ])
        ->and((int) $h2->fresh()->pv_body)->toBe($pvAvant - 3)
        // Rien ne reste posé : le sort est FINI avec le tour du Sorcier, le héros n'est ni gelé ni marqué.
        ->and($h1->fresh()->conditions()->count())->toBe(0)
        ->and(EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h1->id)->firstOrFail()->verrou_mental)->toBeNull()
        // La carte est prêtée le temps du sort, puis rendue : la main de Festral n'est pas entamée.
        ->and(CartesDread::restantes($festral->fresh(), 'Domination'))->toBe(3);

    // Le fil dit le jet, la domination ET le coup — avec le héros dominé pour acteur.
    $fil = collect(app(JournalCombat::class)->depuisResultat($reponse['resultat'], 'Brunhild'));
    $textes = $fil->pluck('texte')->implode(' ¦ ');

    expect($textes)->toContain('Albrecht est DOMINÉ par Festral')
        ->and($textes)->toContain('Albrecht, dominé, frappe Brunhild (−3 PV)')
        ->and($fil->firstWhere(fn ($l) => str_contains($l['texte'], 'est DOMINÉ'))['des'] ?? null)->not->toBeNull();

    // La table voit le même coup : une scène d'attaque entre deux HÉROS.
    $scenes = collect(app(SceneDeTable::class)->depuisResultat($reponse['resultat'], $h2->fresh()));
    $attaque = $scenes->firstWhere(fn ($s) => str_contains((string) ($s['titre'] ?? ''), 'dominé'));

    expect($attaque)->not->toBeNull()
        ->and($attaque['genre'])->toBe('attaque')
        ->and(collect($attaque['acteurs'])->pluck('nom')->all())->toBe(['Albrecht', 'Brunhild']);
});

it('DOMINATE : moins de deux crânes nets, « nothing happens » — ni marche, ni coup, ni carte perdue', function () {
    $ctx = ogresQuete2('Festral', ['pv_mind_max' => 2, 'pv_mind' => 2, 'attribut_mind' => 2], ['pv_mind_max' => 4, 'pv_mind' => 4, 'attribut_mind' => 4],
        cartes: ['Domination' => 3, 'Verrou mental' => 0, 'Explosion mentale' => 0]);
    ['h2' => $h2, 'instance' => $festral] = $ctx;

    $pvAvant = (int) $h2->fresh()->pv_body;

    // Festral : aucun crâne (8 boucliers blancs).
    $sort = ogresSort(ogresActions(ogresTour2($ctx, [4, 4, 4, 4, 4, 4, 4, 4, 4, 4])), 'Domination');

    expect($sort['resultats'][0])->toMatchArray(['cranes_nets' => 0, 'effet_applique' => false])
        ->and($sort['resultats'][0])->not->toHaveKey('actions_domine')
        ->and((int) $h2->fresh()->pv_body)->toBe($pvAvant)
        ->and(CartesDread::restantes($festral->fresh(), 'Domination'))->toBe(3);
});

it('DOMINATE : Dominate n\'est lancé que s\'il y a un AUTRE héros à frapper — un héros seul debout n\'a personne', function () {
    $ctx = ogresQuete('Festral', cartes: ['Domination' => 3, 'Verrou mental' => 0, 'Explosion mentale' => 0]);

    expect(ogresSort(ogresActions(ogresTour($ctx, [])), 'Domination'))->toBeNull();
});

it('DOMINATE : un héros LANCEUR dominé lance son SORT sur un compagnon (tir ami), et le dépense réellement', function () {
    // « Zargon may move, fight, cast spells… » — le sort de dégâts du répertoire du héros, jeté sur l'autre héros
    // en ligne de vue : la même résolution que si son joueur l'avait lancé, y compris le tir ami.
    $ctx = ogresQuete2('Festral',
        ['classe' => 'magicien', 'pv_mind_max' => 2, 'pv_mind' => 2, 'attribut_mind' => 2],
        ['pv_mind_max' => 4, 'pv_mind' => 4, 'attribut_mind' => 4],
        cartes: ['Domination' => 3, 'Verrou mental' => 0, 'Explosion mentale' => 0],
        avant: function ($h1) {
            $h1->sorts()->attach(App\Models\Sort::where('nom', 'Boule de Feu')->firstOrFail()->id, ['disponible' => true]);
        },
    );
    ['h1' => $h1, 'h2' => $h2] = $ctx;

    $pvAvant = (int) $h2->fresh()->pv_body;

    // 8 crânes contre 2 boucliers ; puis les 2 dés rouges de résistance de Brunhild : deux 1 (aucune réduction).
    $reponse = ogresTour2($ctx, [1, 1, 1, 1, 1, 1, 1, 1, 4, 4, 1, 1]);
    $sort = ogresSort(ogresActions($reponse), 'Domination');
    $lance = collect($sort['resultats'][0]['actions_domine'])->firstWhere('type', 'domination_sort');

    expect($lance)->not->toBeNull()
        ->and($lance['sort'])->toBe('Boule de Feu')
        ->and($lance['resultat']['tir_ami'])->toBeTrue()
        ->and($lance['resultat']['degats'])->toBe(2)
        ->and((int) $h2->fresh()->pv_body)->toBe($pvAvant - 2)
        // Le sort d'Albrecht est ÉPUISÉ : « chaque sort est lançable une fois par quête ».
        ->and((bool) $h1->fresh()->sorts()->firstWhere('nom', 'Boule de Feu')->pivot->disponible)->toBeFalse();

    // Le fil dit QUI lance (le héros dominé, pas l'acteur de la requête).
    $fil = collect(app(JournalCombat::class)->depuisResultat($reponse['resultat'], 'Brunhild'))->pluck('texte')->implode(' ¦ ');
    expect($fil)->toContain('Albrecht, dominé, lance Boule de Feu sur Brunhild');
});

it('LA COUTURE « le MJ joue un héros » est UNE : Possession et Domination passent par `HerosJoueParLeMj`', function () {
    $source = fn (string $f) => (string) file_get_contents(base_path($f));
    $corps = function (string $classe, string $methode): string {
        $r = new ReflectionMethod($classe, $methode);
        $lignes = file($r->getFileName());

        return implode('', array_slice($lignes, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    };

    // Possession n'a plus sa propre marche : sa méthode délègue à la couture…
    expect($corps(MoteurDread::class, 'jouerHerosPossede'))->toContain('HerosJoueParLeMj::class')->toContain('ControleMj::possession()')
        ->not->toContain('repousserFigure')->not->toContain('cheminVersCaseAdjacente')
        // …qui est le SEUL endroit à marcher sous le contrôle du MJ…
        ->and($source('app/Partie/HerosJoueParLeMj.php'))->toContain('repousserFigure')
        // …Domination l'emprunte aussi, et le Commandement n'a plus sa propre copie du coup d'un héros sur un autre.
        ->and($source('app/Partie/MoteurDread.php'))->toContain('ControleMj::domination()')
        ->and($corps(MoteurDread::class, 'jouerHerosSousCommandement'))->toContain('frapperUnHeros')->not->toContain('resoudreAttaque');

    // Les deux profils sont les seuls clients, et diffèrent par ce qu'ils PERMETTENT.
    $possession = App\Partie\ControleMj::possession();
    $domination = App\Partie\ControleMj::domination();

    expect([$possession->cible, $possession->deplacement, $possession->attaque, $possession->sorts])
        ->toBe(['monstre', 'base', false, false])
        ->and([$domination->cible, $domination->deplacement, $domination->attaque, $domination->sorts])
        ->toBe(['heros', 'complet', true, true]);
});

// ==================================================================
// 5. LA GARDE PERSONNELLE de Festral — 5 dés d'attaque et 5 de défense
// ==================================================================

it('les Guerriers du Dread de la salle de Festral lancent 5 dés d\'attaque et 5 de défense — pas les autres', function () {
    // « The Dread Warriors in the room are Festral's personal guard. They each roll 5 Attack dice and 5 Defend dice. »
    $ctx = demarrerQueteAvecMonstre('Festral');
    ['quete' => $quete, 'instance' => $festral] = $ctx;

    $guerrier = Monstre::where('nom_base', 'Guerrier du Chaos')->firstOrFail();
    $salles = (array) $quete->carte->grille['salles'];
    $salleDuChef = App\Partie\Salles::indexDe($salles, (int) $festral->position_x, (int) $festral->position_y);

    $poser = function (array $case) use ($quete, $guerrier) {
        return InstanceMonstre::create([
            'quete_id' => $quete->id, 'monstre_id' => $guerrier->id, 'pv_body' => 3, 'pv_body_max' => 3, 'pv_mind' => 3,
            'position_x' => $case['x'], 'position_y' => $case['y'], 'etat' => 'actif', 'revele' => true,
        ]);
    };

    // Un garde dans la salle du chef…
    $garde = $poser(caseAdjacenteLibre($quete, (int) $festral->position_x, (int) $festral->position_y));

    // …et un autre dans une AUTRE salle (un errant, un renfort : pas « in the room »).
    $ailleurs = null;
    foreach ($salles as $i => $salle) {
        if ($i === $salleDuChef) {
            continue;
        }
        for ($x = (int) $salle['x']; $x < (int) $salle['x'] + (int) $salle['largeur'] && $ailleurs === null; $x++) {
            for ($y = (int) $salle['y']; $y < (int) $salle['y'] + (int) $salle['hauteur']; $y++) {
                if (caseQueteLibre($quete, $x, $y)) {
                    $ailleurs = $poser(['x' => $x, 'y' => $y]);
                    break;
                }
            }
        }
        if ($ailleurs !== null) {
            break;
        }
    }

    expect($ailleurs)->not->toBeNull('aucune autre salle libre dans ce scénario');
    expect($garde->fresh()->load('monstre')->attaqueEffective())->toBe(4);

    expect(GardePersonnelle::etablir($quete->refresh(), $quete->carte->grille))->toBe(1);

    $garde = $garde->fresh()->load('monstre');
    $ailleurs = $ailleurs->fresh()->load('monstre');

    expect($garde->attaqueEffective())->toBe(5)
        ->and($garde->defenseEffective())->toBe(5)
        ->and($ailleurs->attaqueEffective())->toBe(4)
        ->and($ailleurs->defenseEffective())->toBe(4);
});

it('la garde de Festral est ACHETÉE avant les autres forts, et POSÉE dans sa salle', function () {
    $bestiaire = BestiaireGroupe::auto('horde_ogre');
    $structure = ['rencontre_finale' => ['tier' => 'boss', 'archetypes' => ['festral_sorcier_dread']]];

    $achats = ogresAchats($bestiaire, $structure, 1, 1);

    expect($achats[0]->nom_base)->toBe('Festral')
        ->and(collect($achats)->pluck('nom_base'))->toContain('Guerrier du Chaos');

    // Les positions : la garde, tombée hors de la salle du chef par la répartition, y est ÉCHANGÉE.
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'positionsDesMonstres');
    $methode->setAccessible(true);

    $carte = [
        'salles' => [['x' => 0, 'y' => 0, 'largeur' => 5, 'hauteur' => 5], ['x' => 20, 'y' => 20, 'largeur' => 5, 'hauteur' => 5]],
        // 0 = chef (salle 1), 1 = garde (salle 0), 2 = un gobelin (salle 1).
        'spawn_monstres' => [['x' => 21, 'y' => 21], ['x' => 2, 'y' => 2], ['x' => 22, 'y' => 22]],
    ];
    $monstres = [
        Monstre::where('nom_base', 'Festral')->firstOrFail(),
        Monstre::where('nom_base', 'Guerrier du Chaos')->firstOrFail(),
        Monstre::where('nom_base', 'Gobelin')->firstOrFail(),
    ];

    $positions = $methode->invoke($demarreur, $monstres, $carte);

    expect($positions[0])->toBe(['x' => 21, 'y' => 21])   // le chef n'a pas bougé
        ->and($positions[1])->toBe(['x' => 22, 'y' => 22]) // la garde est dans la salle du chef
        ->and($positions[2])->toBe(['x' => 2, 'y' => 2]);  // et le gobelin lui a cédé sa place
});

// ==================================================================
// 6. LE SORCIER QUI A LANCÉ PEUT MARCHER — ET LE HÉROS DOMINÉ TIRE (2026-10-16)
// ==================================================================
//
// F9528 p. 10 : « If a Dread Sorcerer casts a spell on their turn, they may take their movement but may not make
// a weapon attack in the same turn. » Décision de René : le sort part de la case où le Sorcier se tient, puis il
// avance vers le héros le plus proche, sans frapper (`ResolveurTour::avancerApresSortDread()`).
// *Domination* : « move, fight, cast spells, and perform any other action as the Dominated hero » — le héros
// dominé tire avec son arme ÉQUIPÉE (`HerosJoueParLeMj::jouer()`), jamais une copie de la frappe du héros.

/**
 * Pose le Sorcier à `$distMin` cases ou plus de son héros, en ligne de vue (figures comprises) et avec un chemin
 * jusqu'à une case libre à son contact : la seule position où il lance ET doit marcher ensuite.
 *
 * @return array{x: int, y: int}
 */
function sorcierPoserAPortee(Quete $quete, InstanceMonstre $sorcier, EtatPersonnageQuete $heros, int $distMin): array
{
    $hx = (int) $heros->position_x;
    $hy = (int) $heros->position_y;
    $grille = FabriqueGrille::pour($quete, exceptInstanceId: $sorcier->id);
    $contact = caseAdjacenteLibre($quete, $hx, $hy);

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true) || abs($x - $hx) + abs($y - $hy) < $distMin) {
                continue;
            }

            if (! caseQueteLibre($quete, $x, $y)
                || ! $grille->ligneDeVue($x, $y, $hx, $hy, figuresBloquent: true)
                || $grille->chemin($x, $y, $contact['x'], $contact['y']) === null) {
                continue;
            }

            $sorcier->update(['position_x' => $x, 'position_y' => $y]);

            return ['x' => $x, 'y' => $y];
        }
    }

    throw new RuntimeException('Aucune case à distance, en ligne de vue et avec un chemin : scénario invalide.');
}

/** Pose le héros `$cible` à `$distMin` cases ou plus du `$tireur`, en ligne de vue (figures comprises). */
function domineEloigner(Quete $quete, EtatPersonnageQuete $tireur, EtatPersonnageQuete $cible, int $distMin): void
{
    $tx = (int) $tireur->position_x;
    $ty = (int) $tireur->position_y;
    $grille = FabriqueGrille::pour($quete, exceptPersonnageId: $tireur->personnage_id);

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true) || abs($x - $tx) + abs($y - $ty) < $distMin) {
                continue;
            }

            if (caseQueteLibre($quete, $x, $y) && $grille->ligneDeVue($tx, $ty, $x, $y, figuresBloquent: true)) {
                $cible->update(['position_x' => $x, 'position_y' => $y]);

                return;
            }
        }
    }

    throw new RuntimeException('Aucune case à distance en ligne de vue pour le tir : scénario invalide.');
}

/** Met une arme dans la main droite du héros, et recalcule ses dés comme à la vraie création. */
function domineArmeEnMain(\App\Models\Personnage $heros, string $nomArme): void
{
    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nomArme)->firstOrFail()->id,
        'emplacement' => 'arme_principale',
        'quantite' => 1,
    ]);

    app(Equipement::class)->recalculerCombat($heros->fresh());
}

it('LE SORCIER QUI A LANCÉ MARCHE : Nexrael jette Explosion mentale à distance, puis avance vers son héros SANS le frapper', function () {
    Event::fake([EtatGroupeDiffuse::class]);

    $ctx = ogresQuete('Nexrael', ['pv_mind' => 1, 'pv_mind_max' => 1], ['Explosion mentale' => 4]);
    ['quete' => $quete, 'instance' => $nexrael, 'etatHeros' => $etatHeros] = $ctx;
    $depart = sorcierPoserAPortee($quete, $nexrael, $etatHeros, 3);

    $actions = ogresActions(ogresTour($ctx, []));
    $rang = array_search('sort_dread', array_column($actions, 'type'), true);

    // La marche SUIT le sort, dans le même tour, publiée comme toute marche de monstre (un trajet, pas un saut).
    expect($rang)->not->toBeFalse('Nexrael n\'a pas lancé de sort')
        ->and($actions[$rang + 1] ?? null)->toMatchArray(['type' => 'deplacement_monstre', 'id' => $nexrael->id, 'depart' => $depart])
        ->and(count($actions[$rang + 1]['chemin'] ?? []))->toBeGreaterThanOrEqual(1)
        // Jamais de coup d'arme après un sort.
        ->and(array_column($actions, 'type'))->not->toContain('attaque_monstre');

    // Il s'arrête au contact du héros, sans l'avoir frappé.
    $etatHeros->refresh();
    $nexrael->refresh();
    expect(abs((int) $nexrael->position_x - (int) $etatHeros->position_x) + abs((int) $nexrael->position_y - (int) $etatHeros->position_y))->toBe(1);

    // La table voit la marche case par case (la figure ne se téléporte pas).
    Event::assertDispatched(EtatGroupeDiffuse::class, fn (EtatGroupeDiffuse $e) => collect($e->mouvements)->contains(
        fn (array $m) => $m['type'] === 'monstre' && (int) $m['id'] === (int) $nexrael->id && count($m['chemin']) >= 1,
    ));
});

it('LE SORCIER AU CONTACT lance puis ne frappe PAS : ni coup d\'arme, ni marche à faire', function () {
    $ctx = ogresQuete('Nexrael', ['pv_mind' => 1, 'pv_mind_max' => 1], ['Explosion mentale' => 4]);

    $types = array_column(ogresActions(ogresTour($ctx, [])), 'type');

    expect($types)->toContain('sort_dread')
        ->and($types)->not->toContain('attaque_monstre')
        ->and($types)->not->toContain('deplacement_monstre');
});

it('SANS la capacité `mouvement_apres_sort`, un lanceur ne marche PAS après son sort (seuls les Sorciers de l\'Ogre Horde la portent)', function () {
    Monstre::where('nom_base', 'Nexrael')->update(['capacites' => ['reserve_cartes' => ['Explosion mentale' => 4]]]);

    $ctx = ogresQuete('Nexrael', ['pv_mind' => 1, 'pv_mind_max' => 1], ['Explosion mentale' => 4]);
    $depart = sorcierPoserAPortee($ctx['quete'], $ctx['instance'], $ctx['etatHeros'], 3);

    $types = array_column(ogresActions(ogresTour($ctx, [])), 'type');

    expect($types)->toContain('sort_dread')->not->toContain('deplacement_monstre')
        ->and([(int) $ctx['instance']->fresh()->position_x, (int) $ctx['instance']->fresh()->position_y])->toBe([$depart['x'], $depart['y']]);
});

it('DOMINATE, ARBALÈTE : le héros dominé TIRE sur un compagnon en ligne de vue, sans marcher, et le fil dit « tire »', function () {
    $ctx = ogresQuete2('Festral',
        ['pv_mind_max' => 2, 'pv_mind' => 2, 'attribut_mind' => 2],
        ['pv_mind_max' => 4, 'pv_mind' => 4, 'attribut_mind' => 4],
        cartes: ['Domination' => 3, 'Verrou mental' => 0, 'Explosion mentale' => 0],
        avant: fn ($h1) => domineArmeEnMain($h1, 'Arbalète'),
    );
    ['h1' => $h1, 'h2' => $h2, 'etat1' => $etat1, 'etat2' => $etat2, 'quete' => $quete] = $ctx;
    domineEloigner($quete, $etat1->refresh(), $etat2->refresh(), 3);

    $pvAvant = (int) $h2->fresh()->pv_body;
    $positionAvant = [(int) $etat1->fresh()->position_x, (int) $etat1->fresh()->position_y];

    // Les dés de l'ARME (l'arbalète) — jamais ceux du profil mains nues.
    $des = (int) $h1->fresh()->des_attaque;
    expect($des)->toBeGreaterThan(app(Equipement::class)->desAttaqueAvec($h1->fresh(), null));

    // Festral : 8 crânes contre les 2 boucliers d'Albrecht → Domination. Puis les dés de tir d'Albrecht, tous des
    // crânes, et les 2 dés de défense de Brunhild, des crânes (aucun bouclier).
    $reponse = ogresTour2($ctx, [1, 1, 1, 1, 1, 1, 1, 1, 4, 4, ...array_fill(0, $des, 1), 1, 1]);
    $actionsDomine = ogresSort(ogresActions($reponse), 'Domination')['resultats'][0]['actions_domine'];
    $coup = collect($actionsDomine)->firstWhere('type', 'domination_attaque');

    expect($coup)->toMatchArray([
        'personnage' => 'Albrecht', 'cible' => ['personnage_id' => $h2->id, 'nom' => 'Brunhild'],
        'portee' => 'distance', 'touches' => $des, 'boucliers' => 0, 'degats' => $des,
    ])
        // Il n'a PAS marché : il tire de la case où il est, rien à raconter de son trajet.
        ->and(collect($actionsDomine)->firstWhere('type', 'domination_deplacement'))->toBeNull()
        ->and([(int) $etat1->fresh()->position_x, (int) $etat1->fresh()->position_y])->toBe($positionAvant)
        ->and((int) $h2->fresh()->pv_body)->toBe($pvAvant - $des)
        // L'arbalète reste en main : un tir ne la perd pas.
        ->and($h1->fresh()->inventaire()->where('emplacement', 'arme_principale')->count())->toBe(1);

    // Le fil dit « tire » — la phrase du tir d'un héros.
    $textes = collect(app(JournalCombat::class)->depuisResultat($reponse['resultat'], 'Brunhild'))->pluck('texte')->implode(' ¦ ');
    expect($textes)->toContain("Albrecht, dominé, tire sur Brunhild (−{$des} PV)");

    // La table : la scène du coup porte la même étiquette « À distance ».
    $scene = collect(app(SceneDeTable::class)->depuisResultat($reponse['resultat'], $h2->fresh()))
        ->firstWhere(fn ($s) => str_contains((string) ($s['titre'] ?? ''), 'dominé'));
    expect($scene['sous_titre'] ?? '')->toContain('À distance');
});

it('DOMINATE, ARBALÈTE AU CONTACT : le héros dominé frappe À MAINS NUES — l\'arbalète refuse le contact (inutilisable_adjacent)', function () {
    $ctx = ogresQuete2('Festral',
        ['pv_mind_max' => 2, 'pv_mind' => 2, 'attribut_mind' => 2],
        ['pv_mind_max' => 4, 'pv_mind' => 4, 'attribut_mind' => 4],
        cartes: ['Domination' => 3, 'Verrou mental' => 0, 'Explosion mentale' => 0],
        avant: fn ($h1) => domineArmeEnMain($h1, 'Arbalète'),
    );
    ['h1' => $h1, 'h2' => $h2] = $ctx;

    // Brunhild est au contact (placement de `ogresQuete2`). Le profil mains nues : le dé de la classe seule.
    $nus = app(Equipement::class)->desAttaqueAvec($h1->fresh(), null);
    $pvAvant = (int) $h2->fresh()->pv_body;

    $reponse = ogresTour2($ctx, [1, 1, 1, 1, 1, 1, 1, 1, 4, 4, ...array_fill(0, $nus, 1), 1, 1]);
    $coup = collect(ogresSort(ogresActions($reponse), 'Domination')['resultats'][0]['actions_domine'])
        ->firstWhere('type', 'domination_attaque');

    expect($coup)->toMatchArray(['touches' => $nus, 'degats' => $nus])
        ->and($coup)->not->toHaveKey('portee')
        ->and($coup['faces_attaque'])->toHaveCount($nus)
        ->and((int) $h2->fresh()->pv_body)->toBe($pvAvant - $nus);
});

it('DOMINATE, DAGUE (jetable) : Zargon ne la LANCE jamais — il avance et frappe au contact, la dague reste en main', function () {
    $ctx = ogresQuete2('Festral',
        ['pv_mind_max' => 2, 'pv_mind' => 2, 'attribut_mind' => 2],
        ['pv_mind_max' => 4, 'pv_mind' => 4, 'attribut_mind' => 4],
        cartes: ['Domination' => 3, 'Verrou mental' => 0, 'Explosion mentale' => 0],
        avant: fn ($h1) => domineArmeEnMain($h1, 'Dague'),
    );
    ['h1' => $h1, 'h2' => $h2, 'etat1' => $etat1, 'etat2' => $etat2, 'quete' => $quete] = $ctx;
    domineEloigner($quete, $etat1->refresh(), $etat2->refresh(), 3);

    $pvAvant = (int) $h2->fresh()->pv_body;
    $des = (int) $h1->fresh()->des_attaque;

    $reponse = ogresTour2($ctx, [1, 1, 1, 1, 1, 1, 1, 1, 4, 4, ...array_fill(0, $des, 1), 1, 1]);
    $actionsDomine = ogresSort(ogresActions($reponse), 'Domination')['resultats'][0]['actions_domine'];

    $marche = collect($actionsDomine)->firstWhere('type', 'domination_deplacement');
    $coup = collect($actionsDomine)->firstWhere('type', 'domination_attaque');

    // Il marche jusqu'au contact (deux pas), puis frappe — au corps à corps, sans `portee`.
    $etat1->refresh();
    $etat2->refresh();

    expect($marche)->not->toBeNull()
        ->and(abs((int) $etat1->position_x - (int) $etat2->position_x) + abs((int) $etat1->position_y - (int) $etat2->position_y))->toBe(1)
        ->and($coup)->not->toBeNull()
        ->and($coup)->not->toHaveKey('portee')
        ->and((int) $h2->fresh()->pv_body)->toBe($pvAvant - (int) $coup['degats'])
        // La dague n'a jamais quitté la main : une arme jetée se perd, une arme frappée non.
        ->and($h1->fresh()->inventaire()->where('emplacement', 'arme_principale')->count())->toBe(1);
});

it('LA COUTURE : seule Domination TIRE — Possession et la forme du loup-garou ne prennent jamais une arme à distance', function () {
    expect(ControleMj::domination()->tir)->toBeTrue()
        ->and(ControleMj::possession()->tir)->toBeFalse()
        ->and(ControleMj::loupGarou()->tir)->toBeFalse();
});
