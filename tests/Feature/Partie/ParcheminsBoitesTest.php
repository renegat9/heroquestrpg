<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Epreuve;
use App\Models\EtatPersonnageQuete;
use App\Models\Objet;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Les PARCHEMINS d'une boîte d'extension ne sortent du butin que sous son thème (René, 2026-11-13), comme les artefacts
 * de la même boîte. La source est le registre (`config/cartes.php`, `parchemins`, clé `paquet`), lue par
 * `ObjetSeeder::boitesDesParchemins()` — jamais une seconde liste.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, EpreuveSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

it('lie chaque parchemin d\'une boîte d\'extension du registre à sa boîte, et rien d\'autre', function () {
    $attendus = collect(config('cartes.parchemins.cartes'))
        ->filter(fn ($c) => isset($c['sort'], ObjetSeeder::BOITE_DU_PAQUET[$c['paquet'] ?? '']))
        ->mapWithKeys(fn ($c) => ["Parchemin : {$c['sort']}" => ObjetSeeder::BOITE_DU_PAQUET[$c['paquet']]])
        ->sortKeys()->all();

    expect($attendus)->toMatchArray([
        'Parchemin : Chaleur' => 'horreur_des_glaces',
        'Parchemin : Patinage' => 'horreur_des_glaces',
        'Parchemin : Morsure du Froid' => 'horreur_des_glaces',
        'Parchemin : Tempête de Glace' => 'horreur_des_glaces',
        'Parchemin : Pont de Glace' => 'horreur_des_glaces',
        // Artifact Reference de The Frozen Horror (F5815 p. 35) ; carte de The Mage of the Mirror (F7539 p. 6).
        'Parchemin : Récupération Psychique' => 'horreur_des_glaces',
        'Parchemin : Trésor sans Péril' => 'mage_du_miroir',
    ]);

    $boites = Objet::where('categorie', 'parchemin')->where('nom', 'like', 'Parchemin : %')->whereNotNull('boite')
        ->pluck('boite', 'nom')->sortKeys()->all();

    expect($boites)->toBe($attendus)
        ->and(Objet::where('nom', 'Parchemin : Boule de Feu')->value('boite'))->toBeNull('un parchemin du jeu de base reste tirable partout')
        ->and(Objet::where('nom', 'Parchemin : Éclair')->value('boite'))->toBeNull('Éclair paraît dans plusieurs boîtes : partout');
});

/** Tire un parchemin au Grimoire à demi calciné, vivier réduit aux parchemins Frozen Horror. */
function tirerParcheminDeBoite(?array $boites): array
{
    Objet::query()->where('categorie', 'parchemin')->where(fn ($q) => $q->whereNull('boite')->orWhere('boite', '!=', 'horreur_des_glaces'))
        ->update(['rarete' => 'unique']);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    if ($boites !== null) {
        $groupe->update(['boites_bestiaire' => $boites]);
    }

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    $grille = $quete->carte->grille;
    $grille['epreuves'] = [[
        'x' => (int) $etat->position_x,
        'y' => (int) $etat->position_y,
        'epreuve_id' => Epreuve::where('nom', 'Grimoire à demi calciné')->value('id'),
        'salle' => 0,
        'tentee_par' => [],
    ]];
    $quete->carte->update(['grille' => $grille]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    desFiges([1, 1, 1, 1]); // réussite du jet de Mind

    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'epreuve_0'])
        ->assertStatus(202)->json('resultat');
}

it('ne tire jamais un parchemin Frozen Horror hors de son thème', function () {
    expect(tirerParcheminDeBoite(['witch_lord'])['objet_indisponible'] ?? false)->toBeTrue();
});

it('tire un parchemin Frozen Horror sous son thème', function () {
    $resultat = tirerParcheminDeBoite(['horreur_des_glaces']);

    expect($resultat['objet_indisponible'] ?? false)->toBeFalse()
        ->and(Objet::where('nom', $resultat['objet']['nom'] ?? '')->value('boite'))->toBe('horreur_des_glaces');
});
