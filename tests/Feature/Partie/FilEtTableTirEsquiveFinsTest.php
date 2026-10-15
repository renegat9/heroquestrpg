<?php

declare(strict_types=1);

use App\Events\SceneTable;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\EtatGroupe;
use App\Partie\ExecutionChoix;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\MoteurSorts;
use App\Partie\SceneDeTable;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * LE FIL ET LA TABLE — quatre trous relevés à la lecture du fil de combat et de l'écran de table
 * (règle dure : « un effet automatique que rien n'annonce est injouable »).
 *
 *   1. un TIR de héros ou d'allié se lisait comme un coup de mêlée (seul le monstre disait « tire ») ;
 *   2. l'ESQUIVE des lianes n'avait aucune scène sur l'écran de table ;
 *   3. la FIN d'Évanescence, d'Étourdi et des immunités à usage unique tombait en silence ;
 *   4. le VENIN restait affiché sur un héros dont le tour couvert était passé.
 *
 * Le fil se vérifie sur le RÉSULTAT ENTIER de l'action (celui que la route renvoie et que
 * `ExecutionChoix` diffuse), jamais sur un sous-payload isolé.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/** Le fil d'un résultat, d'un seul tenant. */
function ftxFil(array $resultat, string $acteur = 'Albrecht'): string
{
    return collect(app(JournalCombat::class)->depuisResultat($resultat, $acteur))->pluck('texte')->implode(' ¦ ');
}

/** Le fil que verrait un joueur arrivé en retard (le journal du groupe, relu par l'état publié). */
function ftxFilRejoue(array $ctx): string
{
    return collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['journal_combat'])->pluck('texte')->implode(' ¦ ');
}

// =====================================================================
// 1. LE TIR — héros, arme jetée, allié : « X tire sur Y », comme le monstre
// =====================================================================

/** Repositionne le monstre sur une case à distance (>1) en ligne de vue du héros. */
function ftxPlacerADistance(Quete $quete, InstanceMonstre $instance, int $hx, int $hy): void
{
    $grille = Grille::depuisCarte($quete->carte);

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (in_array($c, ['s', 'p'], true) && abs($x - $hx) + abs($y - $hy) >= 2 && $grille->ligneDeVue($hx, $hy, $x, $y)) {
                $instance->update(['position_x' => $x, 'position_y' => $y]);

                return;
            }
        }
    }

    throw new RuntimeException('Aucune case à distance avec ligne de vue.');
}

it('dit « tire sur » pour un TIR de héros ou d\'allié — touche, manque, parade, coup fatal et coup absorbé', function () {
    $coup = fn (string $type, string $portee, array $plus = []) => array_merge([
        'type' => $type, 'allie' => 'Archer', 'portee' => $portee, 'cible' => ['nom' => 'Gobelin'],
        'degats' => 0, 'touches' => 0, 'boucliers' => 0,
    ], $plus);

    foreach (['attaque' => 'Albrecht', 'attaque_allie' => 'Archer'] as $type => $tireur) {
        expect(ftxFil($coup($type, 'distance', ['degats' => 2, 'touches' => 2]), 'Albrecht'))->toContain("{$tireur} tire sur Gobelin (−2 PV)")
            ->and(ftxFil($coup($type, 'distance'), 'Albrecht'))->toContain("{$tireur} tire sur Gobelin — et le manque")
            ->and(ftxFil($coup($type, 'distance', ['touches' => 2, 'boucliers' => 2]), 'Albrecht'))->toContain("Gobelin pare le tir de {$tireur}")
            ->and(ftxFil($coup($type, 'distance', ['degats' => 3, 'touches' => 3, 'cible_vaincue' => true]), 'Albrecht'))->toContain("{$tireur} terrasse Gobelin d'un tir !")
            ->and(ftxFil($coup($type, 'distance', ['degats' => 2, 'touches' => 2, 'reaction_monstre' => 'ignore_degats_attaque']), 'Albrecht'))
            ->toContain("{$tireur} tire sur Gobelin, mais le tir ne passe pas");

        // …et la mêlée garde SES phrases : un tir n'est pas un coup de plus.
        $melee = ftxFil($coup($type, 'corps_a_corps', ['degats' => 2, 'touches' => 2]), 'Albrecht');
        expect($melee)->toContain("{$tireur} touche Gobelin (−2 PV)")->and($melee)->not->toContain('tir');
        expect(ftxFil($coup($type, 'corps_a_corps'), 'Albrecht'))->toContain("{$tireur} manque Gobelin")->not->toContain('tir');
    }
});

it('EN JEU — le tir d\'Arbalète d\'un héros se lit « tire sur », la même attaque au contact se lit « touche »', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    Inventaire::create([
        'personnage_id' => $ctx['heros']->id, 'objet_id' => Objet::where('nom', 'Arbalète')->value('id'),
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
    ftxPlacerADistance($ctx['quete'], $ctx['instance'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 1)); // des crânes partout : le coup porte

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.portee', 'distance')->json('resultat');

    $fil = ftxFil($resultat);

    expect($fil)->toMatch('/Albrecht (tire sur Gobelin|terrasse Gobelin d\'un tir)/')
        ->and($fil)->not->toContain('Albrecht touche')
        ->and($fil)->not->toContain('Albrecht manque')
        ->and($fil)->not->toContain("pare l'assaut");

    // Le rejeu (journal) et le fil en direct disent la même chose : c'est le même formateur.
    expect(ftxFilRejoue($ctx))->toContain('tir');
});

it('EN JEU — une arme JETÉE (Hachette) se lit aussi comme un tir', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain']);
    $ctx['heros']->inventaire()->delete();
    Inventaire::create([
        'personnage_id' => $ctx['heros']->id, 'objet_id' => Objet::where('nom', 'Hachette')->value('id'),
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
    app(App\Partie\Equipement::class)->recalculerCombat($ctx['heros']->refresh());
    ftxPlacerADistance($ctx['quete'], $ctx['instance'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 1));

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.lancer.arme', 'Hachette')->json('resultat');

    expect(ftxFil($resultat))->toMatch('/Albrecht (tire sur Gobelin|terrasse Gobelin d\'un tir)/')
        ->and(ftxFil($resultat))->not->toContain('Albrecht touche');
});

// =====================================================================
// 2. L'ESQUIVE — une scène sur l'écran de table
// =====================================================================

/** Une salle d'une rangée, des lianes cachées en (2,0), le héros en (0,0) — le piège esquivé sur un bouclier. */
function ftxSceneLianes(): array
{
    $groupe = creerGroupe('table-esquive');
    $quete = Quete::create([
        'groupe_id' => $groupe->id, 'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Quête de test — esquive', 'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 6, 'hauteur' => 1,
        'grille' => [
            'largeur' => 6, 'hauteur' => 1, 'cases' => [array_fill(0, 6, 's')],
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 6, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [['x' => 2, 'y' => 0, 'piege_id' => Piege::where('nom', 'Piège de lianes')->value('id'), 'etat' => 'cache']],
            'mobilier' => [], 'terrain' => [], 'spawn_heros' => [['x' => 0, 'y' => 0]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);
    $heros = creerHeros(connecterJoueur('esquive'), $groupe, 'Albrecht', 1);
    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id, 'position_x' => 0, 'position_y' => 0, 'tombe' => false, 'a_joue' => false,
    ]);

    // Un second héros qui n'a pas joué garde le ROUND ouvert.
    $second = creerHeros(connecterJoueur('esquive2'), $groupe, 'Second', 2);
    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $second->id, 'position_x' => 0, 'position_y' => 0, 'tombe' => false, 'a_joue' => false,
    ]);

    return ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros];
}

it('met en SCÈNE une esquive de piège — le héros, le piège, le dé, « esquive — aucun dégât »', function () {
    $heros = creerHeros(connecterJoueur('borin'), creerGroupe('t-esq'), 'Borin', 1);
    $esquive = [
        'type' => 'piege_esquive', 'contexte' => 'deplacement',
        'piege' => ['nom' => 'Piège de lianes', 'x' => 2, 'y' => 0],
        'personnage' => ['id' => $heros->id, 'nom' => 'Borin'], 'faces' => ['bouclier_blanc'], 'touches' => 0,
    ];

    $scenes = app(SceneDeTable::class);

    // L'action seule…
    $scene = $scenes->depuisResultat($esquive, $heros)[0] ?? null;

    expect($scene)->not->toBeNull('une esquive n\'a aucune scène sur la table')
        ->and($scene['genre'])->toBe('piege')->toBeIn(SceneDeTable::GENRES)
        ->and($scene['titre'])->toContain('Piège de lianes')->toContain('esquivé')
        ->and($scene['acteurs'][0]['nom'])->toBe('Borin')
        ->and($scene['objets'][0]['nom'])->toBe('Piège de lianes')
        ->and($scene['objets'][0]['image_url'])->toBeString()->not->toBe('')
        ->and($scene['jet'])->not->toBeNull()
        ->and($scene['issue']['ton'])->toBe('succes')
        ->and($scene['issue']['libelle'])->toContain('Borin esquive')
        ->and(array_keys($scene))->toEqualCanonicalizing(['genre', 'titre', 'sous_titre', 'acteurs', 'jet', 'deplacement', 'figure', 'objets', 'issue']);

    // …et IMBRIQUÉE dans un déplacement (`pieges_esquives`) ou un désamorçage/franchissement raté
    // (`declenchement`), les deux seuls endroits où le moteur la publie vraiment.
    $titres = fn (array $resultat) => collect($scenes->depuisResultat($resultat, $heros))->pluck('titre')->all();

    expect($titres(['type' => 'deplacement', 'pieges_esquives' => [$esquive], 'pieges_declenches' => []]))->toBe(['Piège de lianes esquivé'])
        ->and($titres(['type' => 'franchissement', 'declenchement' => $esquive, 'reussi' => false]))->toContain('Piège de lianes esquivé');
});

it('EN JEU — le héros qui esquive les lianes en marchant déclenche UNE scène de table, dans le tampon de la route', function () {
    Event::fake([SceneTable::class]);
    $scene = ftxSceneLianes();

    // Un bouclier blanc sur le dé des lianes : esquivé.
    desFiges(array_fill(0, 12, 4));

    $resultat = app(ExecutionChoix::class)->executer(
        $scene['groupe']->fresh(), $scene['heros']->fresh(),
        ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'], ['x' => 4, 'y' => 0],
    )['resultat'];

    expect($resultat['pieges_esquives'])->toHaveCount(1);

    $genres = Event::dispatched(SceneTable::class)->map(fn (array $appel) => $appel[0]->scene)
        ->filter(fn (array $s) => $s['genre'] === 'piege')->values();

    expect($genres)->toHaveCount(1, 'l\'esquive se joue sous les yeux de la table sans qu\'une scène ne la montre')
        ->and($genres[0]['titre'])->toContain('esquivé')
        ->and($genres[0]['issue']['ton'])->toBe('succes');
});

// =====================================================================
// 3. LES FINS MUETTES — Évanescence, Étourdi, immunités à usage unique
// =====================================================================

it('EN JEU — l\'ÉVANESCENCE qui se rompt sur un jet de déplacement de 5+ se DIT (jet, sort, rejeu)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    $heros = $ctx['heros'];
    app(MoteurSorts::class)->appliquerConditionCatalogue($heros, 'Évanescent', Sort::where('nom', 'Évanescence')->firstOrFail());
    expect(app(MoteurSorts::class)->actionInterdite($heros->fresh()))->toBeTrue();

    // Le menu du tour lance le d6 de déplacement (le jet du départ de quête est effacé) : un 6 rompt le sort.
    $ctx['etatHeros']->update(['deplacement_tour' => null, 'detail_deplacement_tour' => null]);
    desFiges(array_fill(0, 20, 6));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);

    expect(app(MoteurSorts::class)->actionInterdite($heros->fresh()))->toBeFalse();

    $fil = ftxFilRejoue($ctx);
    expect($fil)->toContain('Albrecht : Évanescent (Évanescence) se dissipe : jet de déplacement de 6');
});

it('l\'ÉVANESCENCE conservée sur un petit jet ne dit rien — et rompre un héros qui ne l\'a pas non plus', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    $heros = $ctx['heros'];
    $sorts = app(MoteurSorts::class);

    // Pas évanescent : rompre ne peut rien annoncer.
    $sorts->rompreEvanescence($heros, 6);
    expect(ftxFilRejoue($ctx))->not->toContain('se dissipe');

    $sorts->appliquerConditionCatalogue($heros, 'Évanescent', Sort::where('nom', 'Évanescence')->firstOrFail());
    $ctx['etatHeros']->update(['deplacement_tour' => null, 'detail_deplacement_tour' => null]);
    desFiges(array_fill(0, 20, 2));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);

    expect($sorts->actionInterdite($heros->fresh()))->toBeTrue()
        ->and(ftxFilRejoue($ctx))->not->toContain('se dissipe');
});

it('EN JEU — l\'ÉTOURDI consommé par le tour perdu annonce sa FIN, à côté du tour passé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $heros->conditions()->attach(Condition::where('nom', 'Étourdi')->value('id'), ['duree' => 0, 'source' => 'sort_dread:Tourmente']);

    // Il termine son tour : la phase des monstres passe, le round suivant s'ouvre et lui prend son tour.
    desFiges(array_fill(0, 60, 6));
    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202)->json('resultat');

    expect($heros->fresh()->conditions()->where('nom', 'Étourdi')->exists())->toBeFalse();

    $fil = ftxFil($resultat);
    expect($fil)->toContain('est encore étourdi — il passe son tour')
        ->and($fil)->toContain('Albrecht : Étourdi prend fin : le tour perdu l\'a consommé');

    $annonce = collect($resultat['annonces_automatiques'] ?? [])->first(fn ($a) => ($a['condition'] ?? null) === 'Étourdi');
    expect($annonce)->not->toBeNull('la fin de l\'Étourdi n\'est pas dans le résultat de la résolution');
});

it('EN JEU — une immunité à usage unique CONSOMMÉE par le coup qu\'elle absorbe le dit (feu, puis sort annulé)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $sorts = app(MoteurSorts::class);

    $heros->conditions()->attach(Condition::where('nom', 'Insensible au feu')->value('id'), ['duree' => 0, 'source' => 'potion:Potion de résistance au feu']);
    $heros->conditions()->attach(Condition::where('nom', 'Résistance arcanique')->value('id'), ['duree' => 0, 'source' => 'potion:Potion de résistance à la magie']);

    expect($sorts->absorbeDegat($heros->fresh(), 'feu'))->toBeTrue()
        ->and($sorts->annuleProchainSortDegats($heros->fresh()))->toBeTrue()
        // Consommées : le second coup passerait.
        ->and($sorts->absorbeDegat($heros->fresh(), 'feu'))->toBeFalse();

    $fil = ftxFilRejoue($ctx);
    expect($fil)->toContain('Albrecht : Insensible au feu (Potion de résistance au feu) est consommé : le coup de feu est absorbé')
        ->and($fil)->toContain('Albrecht : Résistance arcanique (Potion de résistance à la magie) est consommé : le sort est annulé');
});

it('une immunité qui ne protège de rien ne se consomme pas, et ne dit rien', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $heros->conditions()->attach(Condition::where('nom', 'Insensible au feu')->value('id'), ['duree' => 0, 'source' => 'potion:Potion de résistance au feu']);

    expect(app(MoteurSorts::class)->absorbeDegat($heros->fresh(), 'froid'))->toBeFalse()
        ->and($heros->fresh()->conditions()->where('nom', 'Insensible au feu')->exists())->toBeTrue()
        ->and(ftxFilRejoue($ctx))->not->toContain('est consommé');
});

// =====================================================================
// 4. LE VENIN — affiché tant qu'il agit, pas un tour de plus
// =====================================================================

/** Un Gobelin au contact et un SECOND héros qui n'a pas joué : le round reste ouvert après le tour d'Albrecht. */
function ftxVeninAvecSecondHeros(): array
{
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    // Le Gobelin ne touche plus personne : on n'éprouve ici que l'affichage du venin déjà posé.
    $ctx['instance']->update(['etat' => 'vaincu']);

    $bob = connecterJoueur('bob');
    $second = creerHeros($bob, $ctx['groupe'], 'Brokk', 2);
    EtatPersonnageQuete::create([
        'quete_id' => $ctx['quete']->id, 'personnage_id' => $second->id,
        'position_x' => (int) $ctx['etatHeros']->position_x, 'position_y' => (int) $ctx['etatHeros']->position_y,
        'tombe' => false, 'a_joue' => false,
    ]);
    test()->actingAs($ctx['alice'], 'joueur');

    return $ctx;
}

it('EN JEU — le venin tombe à la fin du tour qu\'il couvrait, AVANT que les compagnons ne jouent', function () {
    $ctx = ftxVeninAvecSecondHeros();
    $heros = $ctx['heros'];

    // Posé en phase des monstres (duree 2), un tic de fin de round est déjà passé : reste 1.
    $heros->conditions()->attach(Condition::where('nom', 'Envenimé')->value('id'), ['duree' => 1, 'source' => 'venin']);

    desFiges(array_fill(0, 30, 6));
    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202)->json('resultat');

    // Brokk n'a pas encore joué : le round est ouvert, la phase des monstres n'a pas eu lieu.
    expect(EtatPersonnageQuete::where('personnage_id', $heros->id)->value('a_joue'))->toBeTruthy()
        ->and(EtatPersonnageQuete::where('personnage_id', '!=', $heros->id)->value('a_joue'))->toBeFalsy()
        ->and($heros->fresh()->conditions()->where('nom', 'Envenimé')->exists())
        ->toBeFalse('le venin reste affiché alors que le tour qu\'il couvrait est fini')
        ->and(ftxFil($resultat))->toContain('Albrecht : Envenimé prend fin');

    // L'état publié à la table ne le montre plus non plus.
    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $conditions = collect($etat['entites'] ?? [])->firstWhere('id', $heros->id)['conditions'] ?? [];
    expect(collect($conditions)->pluck('nom')->all())->not->toContain('Envenimé');
});

it('le venin posé AVANT que le héros ne joue ce round n\'a pas encore couvert son tour : il reste', function () {
    $ctx = ftxVeninAvecSecondHeros();
    $heros = $ctx['heros'];

    // Posé il y a un instant (duree 2, aucun tic de fin de round passé) : le prochain tour est à venir.
    $heros->conditions()->attach(Condition::where('nom', 'Envenimé')->value('id'), ['duree' => 2, 'source' => 'venin']);

    desFiges(array_fill(0, 30, 6));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect($heros->fresh()->conditions()->where('nom', 'Envenimé')->first()?->pivot->duree)->toBe(2);
});

it('une autre condition à compteur n\'est jamais retirée à la fin du tour par ce chemin — le poison suit son décompte', function () {
    $ctx = ftxVeninAvecSecondHeros();
    $heros = $ctx['heros'];
    $heros->conditions()->attach(Condition::where('nom', 'Empoisonné')->value('id'), ['duree' => 1, 'source' => 'piege:Aiguille']);

    desFiges(array_fill(0, 30, 6));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Le tic de poison a eu lieu ; la ligne attend le décompte de fin de round (Brokk n'a pas joué).
    expect($heros->fresh()->conditions()->where('nom', 'Empoisonné')->exists())->toBeTrue();
});
