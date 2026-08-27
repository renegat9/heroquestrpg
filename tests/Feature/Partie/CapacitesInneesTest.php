<?php

declare(strict_types=1);

use App\Engine\MotsClesTalent;
use App\Models\Competence;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\CapacitesInnees;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Capacités INNÉES — les capacités de carte des 8 classes d'extension.
 *
 * Acquises d'emblée et gratuitement (au plateau, la carte vient avec la
 * figurine). Ce fichier teste leur LECTURE et leur comptage « once per quest » ;
 * les effets en jeu sont testés avec la mécanique qu'ils empruntent.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

it('n\'annonce aucune capacité de carte que rien n\'applique — et réciproquement', function () {
    // Même garde-fou que `ObjetsFonctionnelsTest`, dans les DEUX SENS : une
    // capacité est une phrase imprimée sur une carte et montrée au joueur. Sans
    // lecteur, c'est une promesse non tenue ; déclarée sans porteuse, c'est une
    // règle qui n'existe que sur le papier.
    $portees = [];

    foreach (Competence::where('innee', true)->get() as $carte) {
        $mecanique = $carte->effet['mecanique'] ?? null;

        // ⚠ `toHaveKey()` de Pest prend une VALEUR en second argument, pas un
        // message : on passe par `array_key_exists` + `toBeTrue()`.
        expect($mecanique)->toBeString("{$carte->nom} : capacité innée sans mécanique.")
            ->and(array_key_exists((string) $mecanique, MotsClesTalent::MECANIQUES))
            ->toBeTrue("{$carte->nom} : mécanique « {$mecanique} » sans lecteur déclaré.");

        $portees[$mecanique] = true;

        // Les techniques du Moine vivent DANS la carte de style : elles doivent
        // passer le même contrôle, sinon quatre cartes en cacheraient huit.
        foreach ((array) ($carte->effet['techniques'] ?? []) as $technique) {
            $interne = $technique['effet']['mecanique'] ?? null;

            expect(array_key_exists((string) $interne, MotsClesTalent::MECANIQUES))
                ->toBeTrue("{$technique['nom']} : technique sans lecteur déclaré.");

            $portees[$interne] = true;
        }
    }

    // ⚠ Le sens inverse — « aucune mécanique déclarée que rien ne porte » — a
    // déménagé dans `GrilleTalentsTest` avec le registre lui-même : depuis le
    // 2026-08-23 `MotsClesTalent` couvre AUSSI les nœuds de la grille, et
    // l'exiger ici rejetterait toute mécanique portée par un talent acheté.
    expect($portees)->not->toBeEmpty();
});

it('attache les capacités de carte à la création, sans coûter de point', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $rogue = creerHeros($alice, $groupe, 'Silfen', 1, ['classe' => 'rogue']);

    $innees = $rogue->competences()->where('innee', true)->pluck('nom')->sort()->values()->all();

    expect($innees)->toBe(['Ambidextrie', 'Frappe opportuniste', 'Mobilité de combat']);

    // ⚠ Elles ne consomment AUCUN point : le héros de niveau 1 garde toute sa
    // progression. Les compter comme achetées reviendrait à naître endetté.
    expect((int) $rogue->niveau)->toBe(1);
});

it('ne donne aucune capacité innée aux 4 classes historiques', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barbare = creerHeros($alice, $groupe, 'Krogar', 1, ['classe' => 'barbare']);

    // Aucune carte officielle n'en donne aux quatre de base — c'est l'écart
    // d'équilibrage assumé du lot, pas un oubli.
    expect($barbare->competences()->where('innee', true)->count())->toBe(0);
});

it('compte les capacités « une fois par quête », et seulement celles-là', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];

    $capacites = app(CapacitesInnees::class);

    // On lui donne les deux formes : un passif permanent et une « once per quest ».
    $permanent = Competence::where('classe', 'rogue')->where('nom', 'Mobilité de combat')->firstOrFail();
    $unique = Competence::where('classe', 'chevalier')->where('nom', 'Inébranlable')->firstOrFail();
    $heros->competences()->syncWithoutDetaching([$permanent->id, $unique->id]);

    expect($capacites->disponible($heros, $etat, 'plancher_pv'))->toBeTrue();

    $capacites->consommer($heros, $etat, 'plancher_pv');

    expect($capacites->disponible($heros, $etat->fresh(), 'plancher_pv'))->toBeFalse();

    // Le passif, lui, ne se consomme pas : `consommer` doit le laisser intact.
    $capacites->consommer($heros, $etat->fresh(), 'franchit_figures');

    expect($capacites->disponible($heros, $etat->fresh(), 'franchit_figures'))->toBeTrue();
});

it('ferme les capacités du Berserker tant qu\'il n\'est PAS assez blessé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];

    $frenesie = Competence::where('classe', 'berserker')->where('nom', 'Frénésie sanguinaire')->firstOrFail();
    $heros->competences()->syncWithoutDetaching([$frenesie->id]);

    $capacites = app(CapacitesInnees::class);

    // « Cannot be used unless you have 3 or fewer Body Points » : c'est un
    // PLAFOND, la capacité s'ouvre quand on est blessé.
    $heros->update(['pv_body' => 8]);
    expect($capacites->disponible($heros->fresh(), $etat, 'attaque_balayee'))->toBeFalse();

    $heros->update(['pv_body' => 3]);
    expect($capacites->disponible($heros->fresh(), $etat, 'attaque_balayee'))->toBeTrue();
});

it('LÉGER SUR SES PIEDS donne un dé de défense — et le retire sous le métal', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barde = creerHeros($alice, $groupe, 'Lyr', 1, ['classe' => 'barde']);

    $sorts = app(MoteurSorts::class);
    $base = (int) $barde->des_defense;

    // Sans métal ni bouclier : le bonus s'applique.
    expect($sorts->desDefenseHeros($barde))->toBe($base + 1);

    // Un bouclier au bras, et il tombe. ⚠ Ce n'est pas une interdiction : le
    // Barde PEUT le porter, il y perd simplement son dé.
    $bouclier = Objet::where('nom', 'Bouclier')->firstOrFail();
    Inventaire::create([
        'personnage_id' => $barde->id, 'objet_id' => $bouclier->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);

    expect($sorts->desDefenseHeros($barde->fresh()))->toBe($base);
});

it('donne à chaque classe le mouvement de sa RACE, plus un trait d\'agilité', function () {
    // ⚠ Ce socle est ENTIÈREMENT de nous — les cartes ne donnent que « 2 dés
    // rouges », sans base. Il n'y a donc rien à sourcer, seulement une
    // cohérence à tenir : elle ne l'était pas, l'EXPLORATEUR (un nain) marchait
    // à 5 quand le Nain marche à 3, soit plus vite que l'Elfe.
    //
    // Règle arbitrée par René le 2026-08-13 : socle racial (nain 3 · halfling 3
    // · humain 4 · elfe 5), +1 si la carte de la classe la vend AGILE.
    // ⚠ Le socle se DÉDUIT de `classes_heros.race` (colonne depuis le
    // 2026-08-13) : recopier douze chiffres à la main aurait laissé la grille
    // et les données diverger au premier ajout de classe.
    $socle = ['nain' => 3, 'halfling' => 3, 'humain' => 4, 'elfe' => 5];
    $agiles = ['rogue', 'moine', 'berserker', 'explorateur'];

    foreach (App\Models\ClasseHeros::all() as $classe) {
        // ⚠ `toHaveKey()` de Pest prend une VALEUR en second argument, pas un
        // message (piège déjà rencontré plus haut dans ce fichier).
        expect(array_key_exists((string) $classe->race, $socle))
            ->toBeTrue("{$classe->nom} : race « {$classe->race} » sans socle de mouvement déclaré.");

        $attendu = $socle[$classe->race] + (in_array($classe->nom, $agiles, true) ? 1 : 0);

        expect((int) $classe->deplacement_base)->toBe($attendu,
            "{$classe->nom} ({$classe->race}) : mouvement hors grille raciale.");
    }

    // Les deux invariants que la contradiction violait : aucun nain ne dépasse
    // l'elfe, aucun halfling ne dépasse un humain.
    $par = App\Models\ClasseHeros::all()->keyBy('nom');

    expect((int) $par['explorateur']->deplacement_base)->toBeLessThan((int) $par['elfe']->deplacement_base)
        ->and((int) $par['warlock']->deplacement_base)->toBeLessThan((int) $par['barbare']->deplacement_base);
});

it('donne une RACE à chaque classe, et une seule des quatre connues', function () {
    // La race n'était qu'un COMMENTAIRE avant le 2026-08-13 : le guide ne
    // pouvait pas l'afficher, et un Explorateur plus lent qu'un Rogue restait
    // inexplicable pour le joueur.
    foreach (App\Models\ClasseHeros::all() as $classe) {
        expect(['humain', 'nain', 'elfe', 'halfling'])->toContain((string) $classe->race);
    }

    $races = App\Models\ClasseHeros::all()->groupBy('race')->map->count();

    // Rappel de René (2026-08-13) : hors Warlock (halfling) et Explorateur
    // (nain), toutes les classes d'extension sont humaines.
    expect((int) ($races['halfling'] ?? 0))->toBe(1)
        ->and((int) ($races['nain'] ?? 0))->toBe(2)   // le Nain et l'Explorateur
        ->and((int) ($races['elfe'] ?? 0))->toBe(1);
});

it('charge la DESCRIPTION du nœud : une offre de réaction doit être lisible', function () {
    // Défaut trouvé en validation live (2026-08-14) : `noeud()` ne sélectionnait
    // que id/nom/effet. Les trois réactions du Chevalier arrivaient donc sur la
    // manette avec `description: null` — `ReactionSheet.vue` la rend, mais il
    // n'y avait rien à rendre. Le joueur lisait « Inébranlable » et un compte à
    // rebours, sans une ligne disant ce qu'accepter allait consommer, pour une
    // capacité qui ne sert QU'UNE FOIS par quête.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $chevalier = creerHeros($alice, $groupe, 'Roland', 1, ['classe' => 'chevalier']);

    $capacites = app(App\Partie\CapacitesInnees::class);

    foreach (['plancher_pv', 'annule_degats_voisin', 'defi_errant'] as $mecanique) {
        $noeud = $capacites->noeud($chevalier, $mecanique);

        expect($noeud)->not->toBeNull("Le chevalier devrait porter {$mecanique}.")
            ->and($noeud->description)->not->toBeNull(
                "{$noeud->nom} : description absente, l'offre de réaction serait muette.")
            ->and(trim((string) $noeud->description))->not->toBe('');
    }
});
