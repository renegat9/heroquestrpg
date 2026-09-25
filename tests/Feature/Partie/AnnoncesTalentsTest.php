<?php

declare(strict_types=1);

use App\Engine\MotsClesTalent;
use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\AnnoncesTalents;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Partie\MoteurPieges;
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
use Illuminate\Support\Facades\Http;

/*
 * « Un talent qui s'active tout seul se VOIT » (René, 2026-09-25) —
 * docs/contrat-api.md. Trois volets :
 *
 *  1. LE REGISTRE — `AnnoncesTalents::MECANIQUES` confronté dans les DEUX
 *     sens à la liste fermée du contrat, et à ses lecteurs déclarés.
 *  2. LA PREUVE EN JEU — un cas par FAMILLE de déclencheur, joué par les
 *     vraies routes : `talents_declenches` porte la bonne entrée ET le fil
 *     produit la ligne `ton: "talent"`.
 *  3. LES MODIFICATEURS (groupe 3) — `des.modificateurs`, publiés là où le
 *     bonus est appliqué, jamais recalculés.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class, SortDreadSeeder::class,
    ]);
});

// =====================================================================
// 1. LE REGISTRE, DANS LES DEUX SENS
// =====================================================================

it('la liste fermée des talents annoncés correspond EXACTEMENT à celle du contrat', function () {
    // Transcrite de docs/contrat-api.md §« Un talent qui s'active tout seul
    // se VOIT » — événement, puis actifs qui partent seuls.
    $duContrat = [
        'detection_pieges_adjacents', 'detection_portes_secretes', 'alerte_pieges_adjacents',
        'bonus_des_defense', 'resistance_condition', 'garde_sort_qui_tue', 'resistance_degats_type',
        'inflige_condition_sur_touche', 'bonus_des_attaque_flanc', 'ignore_terrain_entravant',
        'bonus_or_tresor', 'rarete_butin_amelioree',
        'relance_des_attaque_rates', 'attaque_supplementaire_apres_kill',
        'annuler_effet_magique', 'repiocher_carte_piege',
    ];

    expect(AnnoncesTalents::MECANIQUES)->toEqualCanonicalizing($duContrat);
});

it('chaque mécanique annoncée a un lecteur déclaré dont le FICHIER porte la clé ET le collecteur', function () {
    foreach (AnnoncesTalents::MECANIQUES as $mecanique) {
        $entree = MotsClesTalent::MECANIQUES[$mecanique] ?? null;

        expect($entree)->not->toBeNull("« {$mecanique} » : absente du registre MotsClesTalent.");

        $nomme = false;

        foreach ((array) $entree['lecteur'] as $lecteur) {
            [$classe, $methode] = explode('::', str_replace('()', '', $lecteur));
            $reflexion = new ReflectionClass($classe);
            $contenu = (string) file_get_contents((string) $reflexion->getFileName());

            if (str_contains($contenu, $mecanique) && str_contains($contenu, 'AnnoncesTalents')) {
                $nomme = true;
            }
        }

        expect($nomme)->toBeTrue("« {$mecanique} » : aucun lecteur déclaré n'appelle AnnoncesTalents.");
    }
});

it('refuse d\'annoncer une mécanique HORS de la liste fermée', function () {
    $service = new AnnoncesTalents;
    $carrure = Competence::where('nom', 'Carrure')->firstOrFail(); // bonus_pv_body_max, groupe 3/décoratif : hors liste
    $personnage = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare'])['heros'];

    expect(fn () => $service->annoncer($personnage, $carrure, 'texte quelconque'))
        ->toThrow(LogicException::class);
});

it('un annonceur neuf est vide, et vider() oublie ce qu\'il rendait', function () {
    $service = new AnnoncesTalents;
    $noeud = Competence::where('nom', 'Œil du mineur')->firstOrFail();
    $personnage = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain'])['heros'];

    expect($service->vider())->toBe([]);

    $service->annoncer($personnage, $noeud, 'révèle un piège adjacent');
    $lot = $service->vider();

    expect($lot)->toHaveCount(1)
        ->and($lot[0]['talent'])->toBe('Œil du mineur')
        ->and($lot[0]['mecanique'])->toBe('detection_pieges_adjacents')
        ->and($lot[0]['heros'])->toBe($personnage->nom)
        ->and($lot[0]['personnage_id'])->toBe($personnage->id)
        ->and($service->vider())->toBe([]); // oublié après le premier vider()
});

// =====================================================================
// 2. LA PREUVE EN JEU — une famille par déclencheur
// =====================================================================

/** La ligne `ton: talent` du fil, ou null. */
function ligneTalentDuFil(array $resultat, string $acteurNom): ?array
{
    $lignes = app(JournalCombat::class)->depuisResultat($resultat, $acteurNom);

    return collect($lignes)->firstWhere('ton', 'talent');
}

/**
 * Pose un piège CACHÉ sur la carte de la quête — nommé différemment de
 * `PiegesTest::poserPieges()` pour ne rien redéclarer si les deux fichiers
 * sont chargés dans la même suite (les fonctions top-level sont globales).
 */
function poserUnPiegeCache(Quete $quete, int $x, int $y, string $nom = 'Piège à lances'): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = [['x' => $x, 'y' => $y, 'piege_id' => Piege::where('nom', $nom)->value('id'), 'etat' => 'cache']];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/** Équipe une Arbalète en main droite — nommé pour éviter toute collision globale. */
function equiperArbaleteDeTalent(Personnage $p): Inventaire
{
    $objet = Objet::where('nom', 'Arbalète')->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $p->id, 'objet_id' => $objet->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
}

/** Repositionne un monstre à distance (>=2) en ligne de vue du héros. */
function placerMonstreADistanceDeTalent(Quete $quete, InstanceMonstre $instance, int $hx, int $hy): array
{
    $grille = Grille::depuisCarte($quete->carte);

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true) || abs($x - $hx) + abs($y - $hy) < 2) {
                continue;
            }
            if ($grille->ligneDeVue($hx, $hy, $x, $y)) {
                $instance->update(['position_x' => $x, 'position_y' => $y]);

                return ['x' => $x, 'y' => $y];
            }
        }
    }

    throw new RuntimeException('Aucune case à distance avec ligne de vue trouvée.');
}

it('DÉTECTION — l\'Œil du mineur révèle un piège adjacent : popup ET fil', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain']);
    donnerTalent($ctx['heros'], 'Œil du mineur');

    $adjacente = caseAdjacenteLibre(
        $ctx['quete'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y,
    );
    poserUnPiegeCache($ctx['quete'], $adjacente['x'], $adjacente['y']);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4));

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat');

    $declenche = collect($resultat['talents_declenches'] ?? [])->firstWhere('mecanique', 'detection_pieges_adjacents');

    expect($declenche)->not->toBeNull();
    expect($declenche['talent'])->toBe('Œil du mineur')
        ->and($declenche['heros'])->toBe('Albrecht')
        ->and($declenche['personnage_id'])->toBe($ctx['heros']->id)
        ->and($declenche['effet'])->toBe('révèle un piège adjacent');

    $ligne = ligneTalentDuFil($resultat, $ctx['heros']->nom);
    expect($ligne)->not->toBeNull()
        ->and($ligne['texte'])->toBe('Œil du mineur — Albrecht : révèle un piège adjacent')
        ->and($ligne['talent']['icone'])->toBe('visibility');
});

it('DÉFENSE DE PREMIÈRE ATTAQUE PENDANT LA PHASE DES MONSTRES — Garde tenace se voit', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain', 'des_defense' => 2]);
    donnerTalent($ctx['heros'], 'Garde tenace');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4)); // boucliers partout : combat neutre, déterministe

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat');

    $declenche = collect($resultat['talents_declenches'] ?? [])->firstWhere('mecanique', 'bonus_des_defense');

    expect($declenche)->not->toBeNull()
        ->and($declenche['talent'])->toBe('Garde tenace')
        ->and($declenche['effet'])->toBe('+1 dé de défense, contre la première attaque du combat');

    $ligne = ligneTalentDuFil($resultat, $ctx['heros']->nom);
    expect($ligne)->not->toBeNull()
        ->and($ligne['texte'])->toContain('Garde tenace');

    // Consommée : un second tour ne la redéclenche pas.
    expect($ctx['etatHeros']->fresh()->garde_tenace_utilisee)->toBeTrue();
});

it('RÉSISTANCE DE CONDITION — Sang robuste résiste au venin, et le dit', function () {
    $ctx = demarrerQueteAvecMonstre('Serpent géant', ['classe' => 'nain']);
    donnerTalent($ctx['heros'], 'Sang robuste');

    // 1 : le jet de résistance NATUREL échoue (il faut 5 ou 6) — c'est donc
    // bien le TALENT, et lui seul, qui doit arrêter le venin ici.
    // ⚠ Figer les dés AVANT de résoudre le moteur : il reçoit son lanceur à la
    // construction, et le résoudre d'abord lui laissait le lanceur ALÉATOIRE —
    // un 5 ou un 6 arrêtait alors le venin sans le talent (échec 5 fois sur 8).
    desFiges([1]);
    $mecanisme = app(App\Partie\MoteurDread::class);

    expect($mecanisme->appliquerVenin($ctx['instance'], $ctx['heros']->fresh()))->toBeFalse()
        ->and($ctx['heros']->fresh()->conditions()->where('nom', 'Envenimé')->exists())->toBeFalse();

    $lot = app(AnnoncesTalents::class)->vider();
    $declenche = collect($lot)->firstWhere('mecanique', 'resistance_condition');

    expect($declenche)->not->toBeNull()
        ->and($declenche['talent'])->toBe('Sang robuste')
        ->and($declenche['effet'])->toBe('résiste à Envenimé');
});

it('RELANCE AUTOMATIQUE — Coup puissant relance le dé raté, et le dit', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'niveau' => 2, 'des_attaque' => 1]);
    donnerTalent($ctx['heros'], 'Coup puissant');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    // 1 dé d'attaque : blanc (raté) → relancé en crâne. Défense (1 dé) : blanc.
    desFiges([4, 1, 4]);

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['faces_attaque'][0])->toBe('crane');

    $declenche = collect($resultat['talents_declenches'] ?? [])->firstWhere('mecanique', 'relance_des_attaque_rates');

    expect($declenche)->not->toBeNull()
        ->and($declenche['talent'])->toBe('Coup puissant')
        ->and($declenche['effet'])->toBe("relance 1 dé d'attaque raté");

    $ligne = ligneTalentDuFil($resultat, $ctx['heros']->nom);
    expect($ligne)->not->toBeNull();
});

it('ATTAQUE RENDUE APRÈS UN KILL — Soif de sang se voit', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker', 'des_attaque' => 3]);
    donnerTalent($ctx['heros'], 'Soif de sang');
    $ctx['instance']->update(['pv_body' => 1]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges([1, 4, 4, ...array_fill(0, 8, 4)]); // 1 crâne, aucune parade

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['cible_vaincue'])->toBeTrue();

    $declenche = collect($resultat['talents_declenches'] ?? [])->firstWhere('mecanique', 'attaque_supplementaire_apres_kill');

    expect($declenche)->not->toBeNull()
        ->and($declenche['talent'])->toBe('Soif de sang')
        ->and($declenche['effet'])->toBe('attaque de nouveau après avoir abattu sa cible');
});

it('CONTRESORT — annule Sommeil et le dit, quand la résistance naturelle a échoué', function () {
    // Même montage que DreadTest::demarrerQueteBoss('Champion', mindHeros: 1) —
    // un Champion (sous-boss) placé au contact, PV de Mind à 1 pour que la
    // résistance naturelle échoue de façon déterministe.
    $ctx = demarrerQueteAvecMonstre('Champion', [
        'classe' => 'magicien', 'attribut_mind' => 1, 'pv_mind' => 1, 'pv_mind_max' => 1,
    ]);
    $heros = $ctx['heros'];
    $boss = $ctx['instance'];

    $boss->monstre->update(['sorts_dread' => ['Sommeil'], 'archetype_lanceur' => null]);
    $heros->competences()->attach(
        Competence::where('classe', 'magicien')->where('nom', 'Contresort')->value('id'),
    );

    desFiges([
        1, // Contresort (1 dé Mind) : crâne → réussit → annule l'effet
        ...array_fill(0, 20, 4),
    ]);

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat');

    $declenche = collect($resultat['talents_declenches'] ?? [])->firstWhere('mecanique', 'annuler_effet_magique');

    expect($declenche)->not->toBeNull()
        ->and($declenche['talent'])->toBe('Contresort')
        ->and($declenche['effet'])->toBe('annule un effet magique');

    $ligne = ligneTalentDuFil($resultat, $heros->nom);
    expect($ligne)->not->toBeNull()
        ->and($ligne['texte'])->toContain('Contresort');
});

it('CHASSEUR DE TRÉSOR — +25 pièces d\'or annoncées', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'explorateur']);
    $quete = $ctx['quete'];

    empilerCarteFouille($quete, ['issue' => 'tresor', 'or' => 15]);

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['bonus_or_tresor'])->toBe(25);

    $declenche = collect($resultat['talents_declenches'] ?? [])->firstWhere('mecanique', 'bonus_or_tresor');

    expect($declenche)->not->toBeNull()
        ->and($declenche['talent'])->toBe('Chasseur de trésor')
        ->and($declenche['effet'])->toBe("+25 pièces d'or");
});

it('UN TALENT QUI NE JOUE PAS N\'ÉMET RIEN — aucun popup fantôme', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'des_attaque' => 2]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4));

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat)->not->toHaveKey('talents_declenches');
    expect(ligneTalentDuFil($resultat, $ctx['heros']->nom))->toBeNull();
});

it('une annonce ORPHELINE (résolution refusée avant le vidage) ne fuit pas sur l\'action suivante', function () {
    // Les workers de queue vivent des heures : une annonce restée dans le
    // tampon après un 422 s'afficherait sur l'action suivante — peut-être
    // celle d'un autre groupe. Le tampon est vidé à l'entrée de resoudre().
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'des_attaque' => 2]);
    $noeud = Competence::where('classe', 'nain')->where('nom', 'Œil du mineur')->firstOrFail();
    app(AnnoncesTalents::class)->annoncer($ctx['heros'], $noeud, 'annonce orpheline');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4));

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat)->not->toHaveKey('talents_declenches');
});

// =====================================================================
// 3. MODIFICATEURS DE JET (groupe 3) — publiés là où le bonus s'applique
// =====================================================================

it('MODIFICATEUR — Tir précis publie +1 sur l\'attaque, sans popup', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'elfe', 'niveau' => 2, 'des_attaque' => 2]);
    equiperArbaleteDeTalent($ctx['heros']);
    placerMonstreADistanceDeTalent($ctx['quete'], $ctx['instance'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);
    donnerTalent($ctx['heros'], 'Tir précis');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4));

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['modificateurs'])->toBe([
        ['source' => 'Tir précis', 'valeur' => 1, 'sur' => 'attaque'],
    ]);
    // Groupe 3 : pas de popup.
    expect($resultat)->not->toHaveKey('talents_declenches');

    // Recopié dans `des` par JournalCombat, pour l'affichage sous la volée.
    $des = app(JournalCombat::class)->depuisResultat($resultat, $ctx['heros']->nom)[0]['des'] ?? null;
    expect($des['modificateurs'] ?? null)->toBe([
        ['source' => 'Tir précis', 'valeur' => 1, 'sur' => 'attaque'],
    ]);
});

it('MODIFICATEUR — Regard qui glace publie -1 sur l\'attaque du monstre', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Regard qui glace');

    // Attaque toute en crânes, défense toute en boucliers NOIRS (sans effet
    // pour un héros) : mêmes dés que `TalentsEnJeuTest`, déterministe.
    $des = [...array_fill(0, 4, 1), ...array_fill(0, 12, 6)];
    desFiges($des);

    $resultat = (new ReflectionMethod(App\Partie\ResolveurTour::class, 'resoudreAttaqueMonstre'))
        ->invoke(app(App\Partie\ResolveurTour::class), $ctx['groupe'], $ctx['instance'], $ctx['etatHeros'], 3, [], 'Gobelin');

    expect($resultat['modificateurs'])->toContain(
        ['source' => 'Regard qui glace', 'valeur' => -1, 'sur' => 'attaque'],
    );
    // Groupe 3 : pas de popup pour un modificateur qui joue à chaque coup.
    expect(app(AnnoncesTalents::class)->vider())->toBe([]);
});

it('MODIFICATEUR — la réduction de dégâts subis (Cuir tanné) est publiée par MoteurDegats', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $degats = app(MoteurDegats::class);

    donnerTalent($heros, 'Cuir tanné');

    expect($degats->infligerAHeros($heros->fresh(), 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE))->toBe(2)
        ->and($degats->dernierModificateurs())->toBe([
            ['source' => 'Cuir tanné', 'valeur' => -1, 'sur' => 'degats'],
        ]);

    // Groupe 3 : cette mécanique n'est jamais dans talents_declenches.
    expect(app(AnnoncesTalents::class)->vider())->toBe([]);
});
