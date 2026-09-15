<?php

declare(strict_types=1);

use App\Engine\MotsClesTalent;
use App\Models\Competence;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\Talents;
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
 * DISPONIBILITÉ DES CAPACITÉS, ET SA RAISON (René, 2026-09-14 : « dans la fiche
 * du joueur, il faudrait afficher si une abileté est disponible ou non et
 * pourquoi il n'est pas disponible quand c'est le cas »).
 *
 * Ce qui se teste ici n'est pas « la fiche affiche un texte » mais le point de
 * passage : `Talents::fiche()` décide, `Talents::disponible()` n'est que son
 * booléen, et `/moi` publie la décision. Les trois doivent se contredire
 * IMPOSSIBLEMENT — une manette qui annonce « Disponible » sur une capacité que
 * le résolveur refuse est la classe de défaut la plus répétée du projet.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/** Le nœud catalogue portant ce nom (les capacités de carte y vivent aussi). */
function capaciteNommee(string $nom): Competence
{
    return Competence::where('nom', $nom)->firstOrFail();
}

/** L'état d'usage décidé pour ce héros, hors quête par défaut. */
function ficheCapacite(Personnage $heros, string $nom, ?EtatPersonnageQuete $etat = null): array
{
    return app(Talents::class)->fiche($heros, $etat, capaciteNommee($nom));
}

it('laisse un passif permanent sans fenêtre, sans cadence et sans raison', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain']);

    // *Carrure* : +1 PV de Body maximum, versé dans la colonne à l'acquisition.
    // Rien ne peut le fermer — il n'a ni fréquence, ni plafond, ni matériel.
    $fiche = ficheCapacite($ctx['heros'], 'Carrure', $ctx['etatHeros']);

    expect($fiche['statut'])->toBe('permanent')
        ->and($fiche['libelle'])->toBe('Toujours actif')
        ->and($fiche['cadence'])->toBeNull()
        ->and($fiche['raison'])->toBeNull();
});

it('ferme une capacité à fenêtre HORS QUÊTE, faute d\'un état où la dépenser', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Gerhardt', 1, ['classe' => 'berserker', 'pv_body' => 3, 'pv_body_max' => 8]);

    // Au hub, aucun `EtatPersonnageQuete` : la dépense n'aurait nulle part où
    // s'inscrire. Le dire est plus honnête que d'annoncer « Disponible » à un
    // héros qui ne peut rien lancer.
    $fiche = ficheCapacite($heros, 'Représailles');

    expect($fiche['statut'])->toBe('indisponible')
        ->and($fiche['raison'])->toBe('Utilisable en quête seulement')
        ->and($fiche['cadence'])->toBe('une fois par quête');
});

it('nomme la fenêtre CONSOMMÉE, quête ou tour', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker']);
    $talents = app(Talents::class);

    expect(ficheCapacite($ctx['heros'], 'Furie', $ctx['etatHeros'])['statut'])->toBe('disponible');

    $talents->marquerUtilisee($ctx['etatHeros'], 'Furie');
    $fiche = ficheCapacite($ctx['heros'], 'Furie', $ctx['etatHeros']->fresh());

    expect($fiche['statut'])->toBe('indisponible')
        ->and($fiche['raison'])->toBe('Déjà utilisée cette quête');

    // Même mécanisme, autre colonne : la fenêtre du TOUR se rouvre en fin de
    // round, et sa phrase doit le dire — sinon le joueur croit sa capacité
    // perdue pour la quête.
    $talents->marquerUtilisee($ctx['etatHeros'], 'Ambidextrie', 'capacites_tour');
    $fiche = ficheCapacite($ctx['heros'], 'Ambidextrie', $ctx['etatHeros']->fresh());

    expect($fiche['statut'])->toBe('indisponible')
        ->and($fiche['raison'])->toBe('Déjà utilisée ce tour')
        ->and($fiche['cadence'])->toBe('une fois par tour');
});

it('nomme le PLAFOND de PV et le chiffre du héros, et rouvre quand il est blessé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker', 'pv_body' => 8, 'pv_body_max' => 8]);

    // ⚠ PLAFOND, pas seuil : *Représailles* s'ouvre à 5 PV OU MOINS.
    $fiche = ficheCapacite($ctx['heros'], 'Représailles', $ctx['etatHeros']);

    expect($fiche['statut'])->toBe('indisponible')
        ->and($fiche['raison'])->toBe('Exige 5 PV de Body ou moins (tu en as 8)');

    $ctx['heros']->update(['pv_body' => 4]);
    $fiche = ficheCapacite($ctx['heros']->fresh(), 'Représailles', $ctx['etatHeros']);

    expect($fiche['statut'])->toBe('disponible')->and($fiche['raison'])->toBeNull();
});

it('nomme le BOUCLIER manquant — et `disponible()` le refuse désormais lui aussi', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'chevalier']);
    $heros = $ctx['heros'];
    $talents = app(Talents::class);

    // ⚠ Cette condition vivait dans un `MoteurReactions::bouclierSiRequis()`
    // privé, empilé APRÈS `disponible()` : le booléen du moteur disait « oui »
    // sur un chevalier les mains vides, et toute autre lecture le croyait.
    $fiche = ficheCapacite($heros, 'Inébranlable', $ctx['etatHeros']);

    expect($fiche['statut'])->toBe('indisponible')
        ->and($fiche['raison'])->toBe('Exige un bouclier équipé')
        ->and($talents->disponible($heros, $ctx['etatHeros'], 'plancher_pv'))->toBeFalse();

    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Bouclier')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);

    $fiche = ficheCapacite($heros->fresh(), 'Inébranlable', $ctx['etatHeros']);

    expect($fiche['statut'])->toBe('disponible')
        ->and($fiche['raison'])->toBeNull()
        ->and($talents->disponible($heros->fresh(), $ctx['etatHeros'], 'plancher_pv'))->toBeTrue();
});

it('dit « épuisée » AVANT « exige un bouclier » : l\'ordre des refus est une décision', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'chevalier']);

    app(Talents::class)->marquerUtilisee($ctx['etatHeros'], 'Inébranlable');

    // Envoyer le joueur fouiller son sac pour une capacité déjà dépensée serait
    // une réponse vraie et inutile.
    expect(ficheCapacite($ctx['heros'], 'Inébranlable', $ctx['etatHeros']->fresh())['raison'])
        ->toBe('Déjà utilisée cette quête');
});

it('/moi publie la DÉCISION pour chaque nœud, et rien que les clés du contrat', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker', 'pv_body' => 8, 'pv_body_max' => 8]);

    $entrees = test()->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.competences');

    expect($entrees)->not->toBeEmpty();

    foreach ($entrees as $entree) {
        // Le contrat, DANS LES DEUX SENS : aucune clé promise ne manque, et
        // aucune clé non promise ne s'invite (un ingrédient publié « au cas
        // où » finit toujours par être re-dérivé côté client).
        expect(array_keys($entree))->toEqualCanonicalizing(['id', 'statut', 'libelle', 'raison', 'cadence'])
            ->and($entree['statut'])->toBeIn(array_keys(Talents::STATUTS))
            ->and($entree['libelle'])->toBe(Talents::STATUTS[$entree['statut']]);

        // `raison` n'existe QUE sur un refus, et un refus en porte toujours une :
        // un « Indisponible » muet est le défaut que le projet nomme « effet
        // automatique que rien n'annonce ».
        expect($entree['raison'] === null)->toBe($entree['statut'] !== 'indisponible');
    }

    $representailles = collect($entrees)->firstWhere('id', capaciteNommee('Représailles')->id);

    expect($representailles['statut'])->toBe('indisponible')
        ->and($representailles['raison'])->toBe('Exige 5 PV de Body ou moins (tu en as 8)')
        ->and($representailles['cadence'])->toBe('une fois par quête');
});

it('déclare une phrase « épuisée » pour CHAQUE fréquence comptée, et pas une de plus', function () {
    $comptees = array_keys((new ReflectionClass(Talents::class))->getConstant('COMPTEURS'));
    $phrases = array_keys((new ReflectionClass(Talents::class))->getConstant('EPUISEES'));

    // Une fréquence qui se compte sans savoir le dire produirait un
    // « Indisponible » sans raison ; une phrase sans compteur serait la
    // décoration que ce projet chasse partout ailleurs.
    expect($phrases)->toEqualCanonicalizing($comptees);

    // Et toute fréquence déclarée, comptée ou non, a sa cadence lisible.
    foreach (array_keys(MotsClesTalent::FREQUENCES) as $frequence) {
        expect(MotsClesTalent::cadence($frequence))->not->toBeNull();
    }

    foreach ($comptees as $frequence) {
        expect(MotsClesTalent::FREQUENCES)->toHaveKey($frequence);
    }
});

it('couvre les trois statuts avec le catalogue SEEDÉ — aucun n\'est théorique', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'chevalier']);
    $heros = $ctx['heros'];
    $talents = app(Talents::class);

    // Toutes les compétences de la classe, pas seulement celles du héros :
    // un statut que le catalogue ne peut pas produire serait une branche morte.
    $statuts = Competence::where('classe', 'chevalier')->get()
        ->map(fn (Competence $c) => $talents->fiche($heros, $ctx['etatHeros'], $c)['statut'])
        ->unique()
        ->values()
        ->all();

    expect($statuts)->toEqualCanonicalizing(array_keys(Talents::STATUTS));
});
