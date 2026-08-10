<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Grille;
use App\Partie\MoteurDread;
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

/**
 * Les mots-clés de capacité de *Jungles of Delthrak* (livret p. 48-49, règles
 * citées dans `reference/18_extensions.md`), portés le 2026-08-10.
 *
 * Un trait au catalogue ne prouve rien : `BestiaireSourceTest` vérifie qu'ils
 * sont DÉCLARÉS, ce fichier vérifie qu'ils AGISSENT. C'est la distinction que
 * le projet paie cher quand il l'oublie — `attaque_second_rang`, `jetable`, la
 * Potion d'héroïsme injouable.
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

// ---------------------------------------------------------------------------
// Agile — « ignore terrain gênant, mobilier et héros en se déplaçant »
// ---------------------------------------------------------------------------

it('ouvre le chemin au mobilier ET aux figures, sans ouvrir les murs', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $grille = Grille::depuisCarte($ctx['quete']->carte);

    // Une case de sol libre, et son voisin, pour y poser un obstacle.
    $cases = $ctx['quete']->carte->grille['cases'];
    [$x, $y] = [null, null];
    foreach ($cases as $j => $ligne) {
        foreach ($ligne as $i => $c) {
            if (in_array($c, ['s', 'p'], true)
                && in_array($cases[$j][$i + 1] ?? 'm', ['s', 'p'], true)
                && in_array($cases[$j][$i + 2] ?? 'm', ['s', 'p'], true)) {
                [$x, $y] = [$i, $j];
                break 2;
            }
        }
    }
    expect($x)->not->toBeNull('Pas d\'alignement de 3 cases de sol.');

    // Un meuble ET une figure sur la case voisine. On vise CETTE case : un
    // trajet plus long serait contourné par le BFS et ne prouverait rien.
    $grille->obstruer([['x' => $x + 1, 'y' => $y]]);
    $grille->occuper([['x' => $x + 1, 'y' => $y]]);
    expect($grille->chemin($x, $y, $x + 1, $y))->toBeNull();

    // Agile : mobilier et figures cessent de barrer.
    $grille->autoriserFranchissement();
    expect($grille->chemin($x, $y, $x + 1, $y))->not->toBeNull();

    // …mais pas les murs : une case hors carte reste inatteignable.
    expect($grille->chemin($x, $y, -1, -1))->toBeNull();
});

// ---------------------------------------------------------------------------
// Racines entravantes — « le mouvement du héros est stoppé net »
// ---------------------------------------------------------------------------

it('arrête le héros SUR la première case adjacente au gardien', function () {
    $ctx = demarrerQueteAvecMonstre('Crâne putride');
    $etat = $ctx['etatHeros'];
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Un couloir de 3 cases droit devant, gardien collé à la 2ᵉ.
    $cases = $ctx['quete']->carte->grille['cases'];
    $sol = fn ($x, $y) => in_array($cases[$y][$x] ?? 'm', ['s', 'p'], true);

    $axe = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if ($sol($hx + $dx, $hy + $dy) && $sol($hx + 2 * $dx, $hy + 2 * $dy) && $sol($hx + 3 * $dx, $hy + 3 * $dy)) {
            $axe = [$dx, $dy];
            break;
        }
    }
    expect($axe)->not->toBeNull('Pas de couloir droit de 3 cases.');
    [$dx, $dy] = $axe;

    // Le gardien se place PERPENDICULAIREMENT à la 2ᵉ case, pour être adjacent
    // au trajet sans le bloquer physiquement.
    $ctx['instance']->update([
        'position_x' => $hx + 2 * $dx + $dy,
        'position_y' => $hy + 2 * $dy + $dx,
    ]);

    if (! $sol((int) $ctx['instance']->position_x, (int) $ctx['instance']->position_y)) {
        $this->markTestSkipped('Géométrie de carte défavorable à ce tirage.');
    }

    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => 6]);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => $hx + 3 * $dx, 'y' => $hy + 3 * $dy],
    ])->assertStatus(202);

    // Il visait la 3ᵉ case ; les racines l'ont saisi. On n'exige pas une case
    // précise — le BFS choisit sa route — mais la RÈGLE : il n'est pas arrivé,
    // et il s'est arrêté AU CONTACT du gardien (dessus, pas avant : s'arrêter
    // une case plus tôt l'empêcherait de frapper la créature).
    $arrive = $etat->fresh();
    $gardien = $ctx['instance']->fresh();

    expect([(int) $arrive->position_x, (int) $arrive->position_y])
        ->not->toBe([$hx + 3 * $dx, $hy + 3 * $dy], 'les racines n\'ont pas arrêté le héros')
        ->and(abs((int) $gardien->position_x - (int) $arrive->position_x)
            + abs((int) $gardien->position_y - (int) $arrive->position_y))
        ->toBe(1, 'le héros doit s\'arrêter AU CONTACT du gardien');
});

// ---------------------------------------------------------------------------
// Venimeux — « paralysie, sauf 5-6 sur un dé rouge »
// ---------------------------------------------------------------------------

it('paralyse sur un jet raté, et le héros ne peut plus bouger', function () {
    $ctx = demarrerQueteAvecMonstre('Serpent géant');
    $heros = $ctx['heros'];

    // 1 → le jet de résistance échoue (il faut 5 ou 6). Le moteur est résolu
    // APRÈS `desFiges` : il capture le lanceur à sa construction.
    desFiges([1]);
    expect(app(MoteurDread::class)->appliquerVenin($ctx['instance'], $heros))->toBeTrue();

    $envenime = Condition::where('nom', 'Envenimé')->firstOrFail();
    expect($heros->fresh()->conditions()->whereKey($envenime->id)->exists())->toBeTrue();

    // `deplacement_interdit` était une clé de catalogue SANS lecteur : un héros
    // « immobilisé » marchait comme si de rien n'était. Elle mord désormais.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => (int) $ctx['etatHeros']->position_x, 'y' => (int) $ctx['etatHeros']->position_y + 1],
    ])->assertStatus(422);
});

it('laisse passer sur 5 ou 6, et ne s\'applique pas aux créatures sans venin', function () {
    $ctx = demarrerQueteAvecMonstre('Serpent géant');

    // ⚠ Le moteur capture le lanceur À SA CONSTRUCTION : il faut le résoudre
    // APRÈS avoir figé les dés, sinon on éprouve l'ancienne file.
    foreach ([5, 6] as $jet) {
        desFiges([$jet]);
        expect(app(MoteurDread::class)->appliquerVenin($ctx['instance'], $ctx['heros']))
            ->toBeFalse("un {$jet} doit résister");
    }

    // Un gobelin n'a pas de venin : aucun dé n'est même lancé (file vide).
    $ctx['instance']->update(['monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id]);
    desFiges([]);
    expect(app(MoteurDread::class)->appliquerVenin($ctx['instance']->fresh()->load('monstre'), $ctx['heros']))
        ->toBeFalse();
});

// ---------------------------------------------------------------------------
// Tacticien — « +1 dé contre une cible flanquée par un autre monstre »
// ---------------------------------------------------------------------------

it('ne voit un flanc que s\'il y a un SECOND monstre au contact', function () {
    $ctx = demarrerQueteAvecMonstre('Raptor');
    $dread = app(MoteurDread::class);
    $etat = $ctx['etatHeros'];

    // Seul au contact : le raptor n'est pas son propre flanc.
    expect($dread->cibleFlanquee($ctx['quete'], $ctx['instance'], $etat))->toBeFalse();

    // Un complice sur une autre case adjacente au héros.
    $libre = caseAdjacenteLibre($ctx['quete'], (int) $etat->position_x, (int) $etat->position_y);
    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 1, 'pv_mind' => 1,
        'position_x' => $libre['x'], 'position_y' => $libre['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    expect($dread->cibleFlanquee($ctx['quete'], $ctx['instance'], $etat->fresh()))->toBeTrue();
});

it('ignore un monstre VAINCU ou non révélé pour le flanc', function () {
    $ctx = demarrerQueteAvecMonstre('Raptor');
    $etat = $ctx['etatHeros'];
    $libre = caseAdjacenteLibre($ctx['quete'], (int) $etat->position_x, (int) $etat->position_y);

    $complice = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Gobelin')->firstOrFail()->id,
        'pv_body' => 1, 'pv_mind' => 1,
        'position_x' => $libre['x'], 'position_y' => $libre['y'],
        'etat' => 'vaincu', 'revele' => true,
    ]);

    $dread = app(MoteurDread::class);
    expect($dread->cibleFlanquee($ctx['quete'], $ctx['instance'], $etat))->toBeFalse();

    // Un dormant derrière une porte jamais ouverte ne flanque personne non plus.
    $complice->update(['etat' => 'actif', 'revele' => false]);
    expect($dread->cibleFlanquee($ctx['quete'], $ctx['instance'], $etat))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Éthéré — « touché seulement sur un bouclier noir, sauf sort ou artefact »
// ---------------------------------------------------------------------------

it('encaisse une arme ordinaire : seuls les boucliers noirs touchent', function () {
    $ctx = demarrerQueteAvecMonstre('Spectre');
    $pv = (int) $ctx['instance']->pv_body;

    // Épée large : 3 dés. Que des CRÂNES (1) — mortels contre n'importe qui
    // d'autre, inoffensifs contre un éthéré.
    Inventaire::create([
        'personnage_id' => $ctx['heros']->id,
        'objet_id' => Objet::where('nom', 'Épée large')->firstOrFail()->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
    app(Equipement::class)->recalculerCombat($ctx['heros']->refresh());

    desFiges(array_fill(0, 20, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 1));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.cible_etheree', true)
        ->assertJsonPath('resultat.touches', 0);

    expect((int) $ctx['instance']->fresh()->pv_body)->toBe($pv);
});

it('tombe sous une arme ARTEFACT, que la règle excepte', function () {
    $ctx = demarrerQueteAvecMonstre('Spectre');

    // « sauf via sort ou artefact » : la Lame des Esprits est `unique`, et
    // c'est en plus une lame anti-morts-vivants — le spectre en est un.
    Inventaire::create([
        'personnage_id' => $ctx['heros']->id,
        'objet_id' => Objet::where('nom', 'Lame des Esprits')->firstOrFail()->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
    app(Equipement::class)->recalculerCombat($ctx['heros']->refresh());

    desFiges(array_fill(0, 20, 1)); // crânes
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 1));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.cible_etheree', false);

    expect((int) $ctx['instance']->fresh()->pv_body)->toBe(0); // 1 PV, il tombe
});

it('ouvre murs, portes et figures au déplacement éthéré', function () {
    $ctx = demarrerQueteAvecMonstre('Spectre');
    $grille = Grille::depuisCarte($ctx['quete']->carte);

    // Une case de ROCHE : infranchissable pour tout le monde…
    $roche = null;
    foreach ($ctx['quete']->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if ($c === 'm') {
                $roche = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }
    expect($roche)->not->toBeNull();
    expect($grille->estTraversable($roche['x'], $roche['y']))->toBeFalse();

    // …sauf pour un éthéré, qui la traverse.
    $grille->autoriserEthere();
    expect($grille->estTraversable($roche['x'], $roche['y']))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Spawn — « crée un Spawnling adjacent, en alternative à son tour »
// ---------------------------------------------------------------------------

it('engendre un Rejeton adjacent, et la créature engendrée est celle de la capacité', function () {
    $ctx = demarrerQueteAvecMonstre('Serpent géant');
    $avant = $ctx['quete']->instancesMonstres()->where('etat', 'actif')->count();

    $ponte = app(MoteurDread::class)->pondre(
        $ctx['groupe'], $ctx['quete'], $ctx['instance'],
        ['type' => 'monstre', 'id' => $ctx['instance']->id, 'nom' => 'Serpent'],
    );

    expect($ponte)->not->toBeNull()
        // Pas un squelette : c'est tout l'intérêt d'avoir paramétré la créature.
        ->and($ponte['engendre']['nom'])->toBe('Rejeton putride');

    $rejeton = InstanceMonstre::findOrFail($ponte['engendre']['instance_id']);

    expect($ctx['quete']->instancesMonstres()->where('etat', 'actif')->count())->toBe($avant + 1)
        ->and(abs((int) $rejeton->position_x - (int) $ctx['instance']->position_x)
            + abs((int) $rejeton->position_y - (int) $ctx['instance']->position_y))
        ->toBe(1, 'le rejeton doit apparaître ADJACENT à son géniteur');
});

it('ne pond pas pour une créature sans la capacité', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    expect(app(MoteurDread::class)->pondre(
        $ctx['groupe'], $ctx['quete'], $ctx['instance'],
        ['type' => 'monstre', 'id' => $ctx['instance']->id, 'nom' => 'Gobelin'],
    ))->toBeNull();
});

// ---------------------------------------------------------------------------
// Double-action du tacticien — « peut bouger avant ET après son action »
// ---------------------------------------------------------------------------

it('décroche après avoir frappé, sauf si toute case libre est encore au contact', function () {
    $ctx = demarrerQueteAvecMonstre('Raptor');

    desFiges(array_fill(0, 30, 4)); // boucliers : personne ne tombe
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $raptor = $ctx['instance']->fresh();
    $heros = $ctx['quete']->etatsPersonnages()->where('tombe', false)->get();

    $auContact = fn (int $x, int $y) => $heros->contains(
        fn ($h) => abs((int) $h->position_x - $x) + abs((int) $h->position_y - $y) === 1,
    );

    if (! $auContact((int) $raptor->position_x, (int) $raptor->position_y)) {
        expect(true)->toBeTrue(); // il a décroché : c'est la règle appliquée

        return;
    }

    // Toujours au contact : ce n'est légitime QUE si aucune case libre
    // alentour ne l'aurait sorti du corps-à-corps. On le vérifie plutôt que
    // de l'admettre — sans quoi le test passerait même si le repli ne
    // tournait pas du tout.
    $grille = Grille::depuisCarte($ctx['quete']->carte);
    $echappatoire = false;

    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $x = (int) $raptor->position_x + $dx;
        $y = (int) $raptor->position_y + $dy;

        if ($grille->estTraversable($x, $y) && ! $auContact($x, $y)) {
            $echappatoire = true;
            break;
        }
    }

    expect($echappatoire)->toBeFalse('le tacticien avait une case de repli et ne l\'a pas prise');
});

// ---------------------------------------------------------------------------
// Rejetons — jetons cumulables, 1 PV automatique, arrachés par un compagnon
// ---------------------------------------------------------------------------

it('s\'accroche au lieu de frapper : la figurine devient un jeton', function () {
    $ctx = demarrerQueteAvecMonstre('Rejeton putride');
    $etat = $ctx['etatHeros'];

    expect((int) $etat->jetons_rejeton)->toBe(0);

    $accroche = app(MoteurDread::class)->accrocher(
        $ctx['groupe'], $ctx['instance'],
        $ctx['quete']->etatsPersonnages()->get(),
        ['type' => 'monstre', 'id' => $ctx['instance']->id, 'nom' => 'Rejeton'],
    );

    expect($accroche)->not->toBeNull()
        ->and((int) $etat->fresh()->jetons_rejeton)->toBe(1)
        // La figurine quitte le plateau : elle EST le jeton, désormais.
        ->and($ctx['instance']->fresh()->etat)->toBe('vaincu');
});

it('cumule les jetons et ronge 1 PV par jeton à la fin du tour', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $etat = $ctx['etatHeros'];
    $heros = $ctx['heros'];

    $etat->update(['jetons_rejeton' => 3]);
    $pv = (int) $heros->pv_body;

    // Que des boucliers : la phase des monstres qui suit ne fera aucun dégât.
    // Ce qui reste vient donc EXCLUSIVEMENT des jetons — et vaut exactement
    // leur nombre, sans jet d'attaque ni de défense.
    desFiges(array_fill(0, 40, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect((int) $heros->fresh()->pv_body)->toBe($pv - 3);
});

it('laisse aussi un COMPAGNON adjacent les arracher, un par crâne', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $porteur = creerHeros($alice, $groupe, 'Albrecht', 1);
    $sauveur = creerHeros($alice, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // L'ordre d'initiative est figé à la quête : le SAUVEUR doit être celui
    // dont c'est le tour, sinon le contrôleur refuse (à raison).
    $acteurId = (int) $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('etat.acteur.id');

    if ($acteurId !== $sauveur->id) {
        [$porteur, $sauveur] = [$sauveur, $porteur];
    }

    $etatPorteur = $quete->etatsPersonnages()->where('personnage_id', $porteur->id)->firstOrFail();
    $etatSauveur = $quete->etatsPersonnages()->where('personnage_id', $sauveur->id)->firstOrFail();

    $etatPorteur->update(['jetons_rejeton' => 3]);
    $contact = caseAdjacenteLibre($quete, (int) $etatPorteur->position_x, (int) $etatPorteur->position_y);
    $etatSauveur->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    // C'est le SAUVEUR qui joue, et l'option vise le JETON, pas son compagnon.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $sauveur->id);
    $option = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])
        ->firstWhere('type', 'detacher_rejetons');

    expect($option)->not->toBeNull('l\'option d\'arrachage doit être proposée au voisin');

    // 3 dés d'attaque, 2 crânes (1) et 1 bouclier (4) → 2 jetons arrachés :
    // le Rejeton a Body 1 et Défense 0, un crâne suffit à en détacher un.
    desFiges([1, 1, 4]);

    $this->postJson('/api/groupes/table-1/choix', [
        'personnage_id' => $sauveur->id, 'option_id' => $option['id'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.retires', 2)
        ->assertJsonPath('resultat.restants', 1);

    expect((int) $etatPorteur->fresh()->jetons_rejeton)->toBe(1);
});

it('laisse le PORTEUR arracher ses propres rejetons', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['etatHeros']->update(['jetons_rejeton' => 2]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $option = collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'])
        ->firstWhere('type', 'detacher_rejetons');

    expect($option)->not->toBeNull('le porteur doit pouvoir s\'en occuper lui-même')
        ->and($option['libelle'])->toContain('tes rejetons');

    // Que des crânes : les deux jetons partent (un par crâne).
    desFiges(array_fill(0, 10, 1));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.restants', 0);

    expect((int) $ctx['etatHeros']->fresh()->jetons_rejeton)->toBe(0);
});

it('refuse l\'arrachage À DISTANCE', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $acteur = creerHeros($alice, $groupe, 'Albrecht', 1);
    $loin = creerHeros($alice, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $etatLoin = $quete->etatsPersonnages()->where('personnage_id', $loin->id)->firstOrFail();
    $etatActeur = $quete->etatsPersonnages()->where('personnage_id', $acteur->id)->firstOrFail();

    // Porteur volontairement écarté : on arrache une bestiole accrochée sur soi
    // ou sur son voisin, on ne la tire pas d'une salle à l'autre.
    $etatLoin->update([
        'jetons_rejeton' => 2,
        'position_x' => (int) $etatActeur->position_x + 5,
        'position_y' => (int) $etatActeur->position_y + 5,
    ]);

    $this->postJson('/api/groupes/table-1/choix', [
        'personnage_id' => $acteur->id,
        'option_id' => "detacher_rejetons_{$loin->id}",
        'parametres' => ['personnage_id' => $loin->id],
    ])->assertStatus(422);

    expect((int) $etatLoin->fresh()->jetons_rejeton)->toBe(2);
});

it('expose le compteur dans l\'état du groupe, pour qu\'il SE VOIE', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['etatHeros']->update(['jetons_rejeton' => 2]);

    // Sans ça, un dégât automatique de 2 PV par tour frapperait sans que rien
    // ne l'annonce — et personne ne saurait qu'il faut venir les arracher.
    $heros = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites'))
        ->first(fn ($e) => ($e['type'] ?? null) === 'heros' && (int) $e['id'] === $ctx['heros']->id);

    expect($heros)->not->toBeNull();

    expect((int) ($heros['jetons_rejeton'] ?? 0))->toBe(2);
});
