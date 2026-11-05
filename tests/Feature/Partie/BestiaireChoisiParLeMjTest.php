<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GroupeController;
use App\Jobs\GenererSqueletteCampagne;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Quete;
use App\Partie\BestiaireGroupe;
use App\Partie\CatalogueExtensions;
use App\Partie\DemarreurQuete;
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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * LE BESTIAIRE AUTOMATIQUE CHOISI PAR LE MJ (René, 2026-11-05) : « donner la liste et une description des extensions à
 * l'agent IA afin qu'il choisisse des extensions selon le prompt donné, et si le prompt est trop vague, choisir 1, 2 ou
 * 3 extensions au hasard selon la longueur de la campagne ». Décisions du même jour : 1 / 1 / 2 / 3 / 3 boîtes tirées
 * selon la longueur ; le MJ en choisit 1 à 3 librement ; les boîtes retenues FILTRENT, comme le manuel.
 */

beforeEach(function () {
    // UN SEUL faux serveur pour tout le fichier : un second `Http::fake()` s'ajouterait APRÈS un premier fourre-tout,
    // qui répondrait vide à sa place. Le MJ répond selon `$GLOBALS['extensions_du_mj']` (`fakeMjExtensions()`).
    $GLOBALS['extensions_du_mj'] = null;
    Http::fake(function (Request $requete) {
        $data = $requete->data();
        $sorties = $GLOBALS['extensions_du_mj'];

        if ($sorties !== null && str_contains($requete->url(), 'anthropic')
            && ($data['tool_choice']['name'] ?? null) === 'proposer_squelette_campagne') {
            $extensions = $sorties[min($GLOBALS['appels_du_mj']++, count($sorties) - 1)];
            $nbQuetes = (int) Groupe::query()->latest('id')->value('nb_quetes_total');

            return Http::response([
                'stop_reason' => 'tool_use',
                'content' => [['type' => 'tool_use', 'name' => 'proposer_squelette_campagne', 'input' => squeletteAvecExtensions($nbQuetes, $extensions)]],
            ]);
        }

        return Http::response([], 200);
    });
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Un squelette que le moteur accepte, pour une campagne de `$nbQuetes` quêtes. */
function squeletteAvecExtensions(int $nbQuetes, array $extensions): array
{
    return [
        'premisse' => 'Une pourriture verte ronge la jungle, et les nains réfugiés appellent les héros à l\'aide.',
        'menace' => ['nom' => 'Le Fléau vert', 'description' => 'Une pourriture démoniaque qui ronge les racines du monde.'],
        'jalons' => [['position' => $nbQuetes, 'type' => 'boss_final', 'titre' => 'Le cœur du fléau', 'description' => 'Affronter la source de la pourriture.']],
        'fils_narratifs' => [['titre' => 'Les réfugiés', 'description' => 'Les nains cachent un secret ancien.']],
        'extensions' => $extensions,
    ];
}

/**
 * Le MJ IA répond au squelette par `$sorties` (une par appel, la dernière répétée). Les autres appels : réponse vide.
 *
 * @param  list<list<string>>  $sorties  les `extensions` rendues à chaque appel
 */
function fakeMjExtensions(array $sorties): void
{
    $GLOBALS['extensions_du_mj'] = $sorties;
    $GLOBALS['appels_du_mj'] = 0;
    config()->set('services.anthropic.api_key', 'cle-test');
}

/** Le prompt (système + utilisateur) de chaque appel du squelette. */
function promptsDuSquelette(): array
{
    return Http::recorded()
        ->filter(fn (array $paire) => ($paire[0]->data()['tool_choice']['name'] ?? null) === 'proposer_squelette_campagne')
        ->map(fn (array $paire) => $paire[0]->data()['system']."\n".json_encode($paire[0]->data()['messages'], JSON_UNESCAPED_UNICODE))
        ->values()->all();
}

function annoncesBestiaire(Groupe $groupe): array
{
    return Evenement::where('groupe_id', $groupe->id)->where('type', 'systeme')->get()
        ->filter(fn (Evenement $e) => ($e->payload['action'] ?? null) === 'bestiaire_de_campagne')
        ->map(fn (Evenement $e) => $e->payload)->values()->all();
}

it('tire à la création 1, 1, 2, 3 ou 3 boîtes selon la longueur : distinctes, parmi celles qui tournent', function () {
    connecterJoueur('alice');

    foreach (['tres_courte' => 1, 'courte' => 1, 'normale' => 2, 'longue' => 3, 'tres_longue' => 3] as $longueur => $attendu) {
        $this->postJson('/api/groupes', ['identifiant' => "g-{$longueur}", 'nom' => $longueur, 'theme' => 'Donjon classique', 'longueur' => $longueur])
            ->assertCreated();

        $groupe = Groupe::where('identifiant', "g-{$longueur}")->firstOrFail();

        expect($groupe->boites_bestiaire)->toHaveCount($attendu)
            ->and(array_unique($groupe->boites_bestiaire))->toHaveCount($attendu)
            ->and(array_diff($groupe->boites_bestiaire, DemarreurQuete::BOITES_THEMATIQUES))->toBe([])
            ->and($groupe->origine_boites)->toBe(BestiaireGroupe::MODE_TIRAGE)
            ->and($groupe->theme_bestiaire)->toBeNull();
    }
});

it('tient ses registres dans les deux sens : longueurs, descriptions des boîtes', function () {
    expect(array_keys(BestiaireGroupe::BOITES_TIREES_PAR_LONGUEUR))->toBe(array_keys(GroupeController::QUETES_PAR_LONGUEUR));

    $descriptions = (array) config('extensions.descriptions');
    expect(array_diff(DemarreurQuete::BOITES_THEMATIQUES, array_keys($descriptions)))->toBe([])
        ->and(array_diff(array_keys($descriptions), DemarreurQuete::BOITES_THEMATIQUES))->toBe([]);

    foreach ($descriptions as $boite => $texte) {
        expect(mb_strlen(trim($texte)))->toBeGreaterThan(40, "description de {$boite}");
    }
});

it('décrit chaque boîte avec son nom officiel et ses créatures du catalogue, sans les formes qu\'on ne rencontre qu\'en jeu', function () {
    $descriptions = app(CatalogueExtensions::class)->descriptions();

    expect(array_keys($descriptions))->toBe(DemarreurQuete::BOITES_THEMATIQUES)
        ->and($descriptions['jungles_delthrak'])->toStartWith('Jungles of Delthrak — ')
        ->toContain('Gretzl la Porte-Fléau')
        ->toContain('Scion Fleur-Pourrie')
        ->not->toContain('Demonspider')
        ->and($descriptions['horreur_des_glaces'])->toContain('Yéti')
        // Une créature imprimée dans deux boîtes est décrite dans les deux.
        ->and($descriptions['dread_moon'])->toContain('Archer elfe')
        ->and($descriptions['mage_du_miroir'])->toContain('Archer elfe');
});

it('EN JEU — le MJ choisit selon le thème : son choix remplace le tirage, filtre la quête, et il est annoncé', function () {
    $joueur = connecterJoueur('alice');
    fakeMjExtensions([['jungles_delthrak']]);

    // Une campagne d'une quête : la première est l'affrontement final.
    $this->postJson('/api/groupes', [
        'identifiant' => 'jungle', 'nom' => 'Les Lianes', 'longueur' => 'tres_courte',
        'theme' => 'Une expédition dans une jungle rongée par une pourriture démoniaque',
    ])->assertCreated();

    $groupe = Groupe::where('identifiant', 'jungle')->firstOrFail();

    expect($groupe->boites_bestiaire)->toBe(['jungles_delthrak'])
        ->and($groupe->origine_boites)->toBe(BestiaireGroupe::MODE_MJ)
        // Le choix du bestiaire n'est pas du fil rouge : il ne reste pas dans le squelette.
        ->and($groupe->plan_campagne)->not->toHaveKey('extensions')
        ->and($groupe->plan_campagne['premisse'])->toContain('jungle')
        ->and(annoncesBestiaire($groupe))->toBe([[
            'action' => 'bestiaire_de_campagne', 'mode' => 'mj', 'boites' => ['jungles_delthrak'],
            'libelle' => 'HeroQuest Game System + Jungles of Delthrak',
        ]]);

    // Ce que le MJ a lu : la liste décrite, et la consigne du choix ouvert.
    $prompt = promptsDuSquelette()[0];
    expect($prompt)->toContain('`jungles_delthrak` : Jungles of Delthrak')
        ->toContain('Gretzl la Porte-Fléau')
        ->toContain('`kellars_keep` : Kellar')
        ->toContain('que le THÈME du groupe évoque CLAIREMENT');

    // Un FILTRE, comme le manuel : une boîte que le MJ n'a pas retenue n'entre pas.
    expect(BestiaireGroupe::duGroupe($groupe)->autorise('horde_ogre'))->toBeFalse()
        ->and(BestiaireGroupe::duGroupe($groupe)->autorise('jungles_delthrak'))->toBeTrue();

    // La quête démarre par la vraie route : rien hors du jeu de base, de nos créatures et de la jungle ; le boss
    // final est un boss de la jungle.
    config(['services.anthropic.api_key' => null]);
    creerHeros($joueur, $groupe, 'Albrecht', 1);
    $reponse = $this->postJson('/api/groupes/jungle/quetes')->assertCreated();
    $quete = Quete::findOrFail($reponse->json('quete.id'));
    $monstres = $quete->instancesMonstres()->with('monstre')->get()->pluck('monstre');

    foreach ($monstres as $monstre) {
        expect(array_diff($monstre->boites(), ['base', 'jungles_delthrak']))->toBe([], "{$monstre->nom_base} ({$monstre->boite})");
    }
    expect($monstres->firstWhere('tier', 'boss')?->boite)->toBe('jungles_delthrak');

    // Publié : le mode, la liste, le libellé ; pas de « boîte tirée » de la rotation historique.
    $etat = $this->getJson('/api/groupes/jungle/etat')->assertOk();
    expect($etat->json('groupe.bestiaire_mode'))->toBe('mj')
        ->and($etat->json('groupe.bestiaire_boites'))->toBe(['jungles_delthrak'])
        ->and($etat->json('groupe.theme_bestiaire'))->toBeNull()
        ->and($etat->json('groupe.theme_bestiaire_libelle'))->toBe('HeroQuest Game System + Jungles of Delthrak')
        ->and(Groupe::find($groupe->id)->theme_bestiaire)->toBeNull();
});

it('thème trop vague : le MJ rend une liste vide, le tirage reste, et le prompt le lui avait nommé', function () {
    connecterJoueur('alice');
    Queue::fake();
    $this->postJson('/api/groupes', ['identifiant' => 'vague', 'nom' => 'Vague', 'theme' => 'Donjon classique', 'longueur' => 'normale'])
        ->assertCreated();
    $groupe = Groupe::where('identifiant', 'vague')->firstOrFail();
    $tirage = $groupe->boites_bestiaire;

    fakeMjExtensions([[]]);
    app()->call([new GenererSqueletteCampagne($groupe->id), 'handle']); // la file est simulée : le job, à la main
    $groupe->refresh();

    $libelles = array_map(fn (string $b) => app(DemarreurQuete::class)->libelleBoiteBestiaire($b), $tirage);
    expect($tirage)->toHaveCount(2)
        ->and($groupe->boites_bestiaire)->toBe($tirage)
        ->and($groupe->origine_boites)->toBe(BestiaireGroupe::MODE_TIRAGE)
        ->and($groupe->plan_campagne)->not->toBeNull()
        ->and(promptsDuSquelette()[0])->toContain('tirées au hasard, '.implode(', ', $libelles))
        ->and(annoncesBestiaire($groupe)[0]['mode'])->toBe('tirage');
});

it('sans clé API : le squelette n\'est pas écrit, le tirage reste et il est annoncé', function () {
    connecterJoueur('alice');
    $this->postJson('/api/groupes', ['identifiant' => 'sans-cle', 'nom' => 'Sans clé', 'theme' => 'Une jungle', 'longueur' => 'longue'])
        ->assertCreated();
    $groupe = Groupe::where('identifiant', 'sans-cle')->firstOrFail();

    expect($groupe->plan_campagne)->toBeNull()
        ->and($groupe->boites_bestiaire)->toHaveCount(3)
        ->and($groupe->origine_boites)->toBe(BestiaireGroupe::MODE_TIRAGE)
        ->and(annoncesBestiaire($groupe))->toHaveCount(1)
        ->and(annoncesBestiaire($groupe)[0]['boites'])->toBe($groupe->boites_bestiaire);
});

it('une quête démarrée pendant que le MJ écrivait garde le tirage : la rencontre a été achetée dedans', function () {
    $joueur = connecterJoueur('alice');
    Queue::fake();
    $this->postJson('/api/groupes', ['identifiant' => 'presse', 'nom' => 'Pressés', 'theme' => 'Une jungle', 'longueur' => 'courte'])
        ->assertCreated();
    $groupe = Groupe::where('identifiant', 'presse')->firstOrFail();
    $tirage = $groupe->boites_bestiaire;
    $autre = collect(DemarreurQuete::BOITES_THEMATIQUES)->first(fn (string $b) => ! in_array($b, $tirage, true));

    creerHeros($joueur, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/presse/quetes')->assertCreated();
    expect(BestiaireGroupe::choixOuvertAuMj($groupe->fresh()))->toBeFalse();

    fakeMjExtensions([[$autre]]);
    app()->call([new GenererSqueletteCampagne($groupe->id), 'handle']); // la file est simulée : le job, à la main
    $groupe->refresh();

    expect($groupe->boites_bestiaire)->toBe($tirage)
        ->and($groupe->origine_boites)->toBe(BestiaireGroupe::MODE_TIRAGE)
        // Le prompt ne lui offrait plus le choix.
        ->and(promptsDuSquelette()[0])->toContain('Rends `extensions` VIDE');

    // Et l'écriture elle-même refuse, même appelée directement.
    expect(BestiaireGroupe::adopterChoixDuMj($groupe, [$autre]))->toBeFalse()
        ->and($groupe->fresh()->boites_bestiaire)->toBe($tirage);
});

it('un bestiaire COCHÉ par les joueurs ne change jamais : le MJ écrit pour lui, et rien n\'est annoncé', function () {
    connecterJoueur('alice');
    fakeMjExtensions([['jungles_delthrak']]);

    $this->postJson('/api/groupes', [
        'identifiant' => 'ogres', 'nom' => 'Ogres', 'theme' => 'Une jungle', 'longueur' => 'courte', 'bestiaire_boites' => ['horde_ogre'],
    ])->assertCreated();
    $groupe = Groupe::where('identifiant', 'ogres')->firstOrFail();

    expect($groupe->boites_bestiaire)->toBe(['horde_ogre'])
        ->and($groupe->origine_boites)->toBeNull()
        ->and(BestiaireGroupe::duGroupe($groupe)->mode)->toBe(BestiaireGroupe::MODE_MANUEL)
        ->and(annoncesBestiaire($groupe))->toBe([])
        ->and(promptsDuSquelette()[0])->toContain('la campagne joue avec le jeu de base et Against the Ogre Horde');
});

it('refuse une extension citée deux fois, et retente', function () {
    connecterJoueur('alice');
    fakeMjExtensions([['kellars_keep', 'kellars_keep'], ['kellars_keep']]);

    $this->postJson('/api/groupes', ['identifiant' => 'nains', 'nom' => 'Nains', 'theme' => 'Une forteresse naine assiégée', 'longueur' => 'courte'])
        ->assertCreated();
    $groupe = Groupe::where('identifiant', 'nains')->firstOrFail();

    expect(promptsDuSquelette())->toHaveCount(2)
        ->and($groupe->boites_bestiaire)->toBe(['kellars_keep'])
        ->and($groupe->origine_boites)->toBe(BestiaireGroupe::MODE_MJ);
});

it('laisse les campagnes d\'AVANT en rotation historique : une préférence, jamais un filtre', function () {
    $groupe = creerGroupe('ancienne');
    $groupe->update(['theme_bestiaire' => 'horde_ogre']);
    $bestiaire = BestiaireGroupe::duGroupe($groupe->fresh());

    expect($bestiaire->mode)->toBe(BestiaireGroupe::MODE_AUTO)
        ->and($bestiaire->estFiltre())->toBeFalse()
        ->and($bestiaire->autorise('jungles_delthrak'))->toBeTrue()
        ->and($bestiaire->libelle())->toBe('Against the Ogre Horde')
        ->and(BestiaireGroupe::choixOuvertAuMj($groupe))->toBeFalse();
});
