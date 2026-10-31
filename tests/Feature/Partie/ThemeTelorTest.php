<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
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
 * Prophecy of Telor comme THÈME jouable (chantier EXT-TELOR, 2026-10-23).
 * Boss : Fellmarak, le Roi Sorcier (quête 12, stats sourcées). Sous-boss : le Sorcier du Dread générique.
 * Fellmarak MEURT à 0 Body : écart assumé au livret (René, 2026-10-23), voir docs/plan-telor.md.
 * Le modèle des tests de thème est ThemeMorcarTest : même découpe, même réflexion sur acheterMonstres().
 */

const TELOR_FELLMARAK = 'Fellmarak le Roi Sorcier';

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

/** Achats d'une rencontre pour un thème donné (méthode privée → réflexion), comme `acheterPourTheme()`. */
function achatsTelor(string $theme, string $typeJalon, int $budget, int $maxSpawns, int $position, int $groupeId): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);
    $structure = GabaritQuete::where('type_jalon', $typeJalon)->firstOrFail()->structure;

    return $methode->invoke($demarreur, $structure, $budget, $maxSpawns, $position, $groupeId, BestiaireGroupe::auto($theme));
}

it('range Fellmarak en BOSS de sa boîte, et le Sorcier du Dread générique en sous-boss', function () {
    expect(Monstre::where('nom_base', TELOR_FELLMARAK)->value('tier'))->toBe('boss')
        ->and(Monstre::where('nom_base', TELOR_FELLMARAK)->value('boite'))->toBe('prophecy_telor')
        ->and(Monstre::where('nom_base', 'Sorcier du Dread')->value('tier'))->toBe('sous_boss');

    // Un seul boss dans la boîte : Fellmarak. Le sous-boss générique n'en est pas un.
    expect(Monstre::where('boite', 'prophecy_telor')->where('tier', 'boss')->pluck('nom_base')->all())
        ->toBe([TELOR_FELLMARAK]);
});

it('ouvre prophecy_telor comme thème, DERRIÈRE les sept thèmes précédents, hors de la liste des boîtes désactivées', function () {
    $boites = DemarreurQuete::BOITES_THEMATIQUES;

    expect($boites)->toContain('prophecy_telor')
        // Ajoutée APRÈS les sept thèmes précédents (index 7) : une boîte ajoutée plus tard se met derrière,
        // jamais devant — réordonner décalerait la rotation de tout groupe non figé.
        ->and(array_search('prophecy_telor', $boites, true))->toBe(7)
        ->and(array_keys(DemarreurQuete::BOITES_INCOMPLETES))->not->toContain('prophecy_telor')
        ->and(DemarreurQuete::LIBELLES_BOITES['prophecy_telor'])->toBe('Prophecy of Telor');
});

it('tire Fellmarak comme rencontre finale de TOUT groupe Telor, à toute position d\'arc', function () {
    foreach (range(0, 11) as $graine) {
        foreach ([1, 4, 9] as $position) {
            $achats = achatsTelor('prophecy_telor', 'boss_final', 40, 8, $position, $graine);

            expect($achats[0]->nom_base)->toBe(TELOR_FELLMARAK, "graine {$graine}, arc {$position}");
        }
    }
});

it('ne tire Fellmarak QUE sous son thème : aucune autre boîte ne le fait apparaître en rencontre finale', function () {
    foreach (DemarreurQuete::BOITES_THEMATIQUES as $theme) {
        if ($theme === 'prophecy_telor') {
            continue;
        }

        foreach (range(0, 11) as $graine) {
            $noms = collect(achatsTelor($theme, 'boss_final', 400, 8, 1, $graine))->pluck('nom_base');

            expect($noms)->not->toContain(TELOR_FELLMARAK, "thème {$theme}, graine {$graine}");
        }
    }
});

it('achète la rencontre finale même au-dessus du budget, et jamais une liste vide', function () {
    foreach ([1, 5, 40] as $budget) {
        $achats = achatsTelor('prophecy_telor', 'boss_final', $budget, 8, 1, 3);

        expect($achats)->not->toBeEmpty("budget {$budget}")
            ->and($achats[0]->nom_base)->toBe(TELOR_FELLMARAK, "budget {$budget}");
    }
});

it('le sous-boss du thème Telor est le Sorcier du Dread générique, à toute position d\'arc', function () {
    foreach (range(1, 6) as $position) {
        $sousBoss = achatsTelor('prophecy_telor', 'sous_boss', 40, 8, $position, 3)[0]->nom_base;

        expect($sousBoss)->toBe('Sorcier du Dread', "arc {$position}");
    }
});

it('l\'escorte d\'une campagne Telor vient du jeu de base : la boîte n\'a aucun monstre de tier base', function () {
    expect(Monstre::where('boite', 'prophecy_telor')->where('tier', 'base')->count())->toBe(0);

    // Hors finale, la rencontre se compose du tier base commun : c'est ce que font déjà les thèmes sans
    // monstre de base dans leur boîte (`first_light`).
    $escorte = collect(achatsTelor('prophecy_telor', 'normale', 40, 12, 1, 3));

    expect($escorte)->not->toBeEmpty()
        ->and($escorte->pluck('boite')->contains('base'))->toBeTrue();
});

it('le coût de Fellmarak suit la méthode mesurée : attaques pour l\'abattre, lues sur la droite des boss', function () {
    $f = Monstre::where('nom_base', TELOR_FELLMARAK)->firstOrFail();

    // Body / (1,5 − Défense/6) = 6 / (1,5 − 4/6) = 7,2 attaques ; droite des boss SANS le Seigneur (2026-10-31).
    $attaques = $f->pv_body / (1.5 - $f->defense / 6);

    expect(coutDroiteBoss($attaques))->toBe((int) $f->cout)
        ->and((int) $f->cout)->toBe(14);
});

it('FIGE le thème : un groupe figé sur une autre boîte ne bouge pas quand la rotation s\'allonge', function () {
    $demarreur = app(DemarreurQuete::class);

    // Un identifiant qui donnerait AUJOURD'HUI prophecy_telor (modulo 8), mais figé sur une boîte antérieure.
    $id = array_search('prophecy_telor', DemarreurQuete::BOITES_THEMATIQUES, true);
    $groupe = new Groupe(['theme_bestiaire' => 'dread_moon']);
    $groupe->id = $id + count(DemarreurQuete::BOITES_THEMATIQUES);

    expect($demarreur->themeBestiaire((int) $groupe->id))->toBe('prophecy_telor')
        ->and($demarreur->themeBestiaireDuGroupe($groupe))->toBe('dread_moon')
        ->and(BestiaireGroupe::duGroupe($groupe)->contient('prophecy_telor'))->toBeFalse();
});

it('une campagne tirée en Telor affronte Fellmarak en rencontre finale, et son thème est figé', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe('table-1', 1); // une seule quête : la première EST la finale
    creerHeros($joueur, $groupe, 'Albrecht', 1);
    $groupe->update(['theme_bestiaire' => 'prophecy_telor']);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $boss = $quete->instancesMonstres()->with('monstre')->get()
        ->filter(fn ($i) => $i->monstre->tier === 'boss')
        ->pluck('monstre.nom_base')->values()->all();

    expect($quete->gabarit->type_jalon)->toBe('boss_final')
        ->and($boss)->toBe([TELOR_FELLMARAK])
        ->and($groupe->fresh()->theme_bestiaire)->toBe('prophecy_telor');
});
