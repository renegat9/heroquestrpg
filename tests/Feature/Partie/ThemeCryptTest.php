<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Models\Monstre;
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
 * The Crypt of Perpetual Darkness comme THÈME jouable (chantier EXT-CRYPTE, 2026-10-23 — René, « Oui » : thème ALLUMÉ).
 * Boss : VENIM LE DRAGON (quête 10). Sous-boss : les six lanceurs et gardes de la Crypte, réservés à leur thème
 * (`reserve_a_son_theme`). Le modèle est `ThemeTelorTest` : même découpe, même réflexion sur `acheterMonstres()`.
 */
const CRYPTE_THEME = 'crypte_tenebres';

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

/** Achats d'une rencontre pour un thème donné (méthode privée → réflexion), comme dans `ThemeTelorTest`. */
function achatsCrypte(string $theme, string $typeJalon, int $budget, int $maxSpawns, int $position, int $groupeId): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);
    $structure = GabaritQuete::where('type_jalon', $typeJalon)->firstOrFail()->structure;

    return $methode->invoke($demarreur, $structure, $budget, $maxSpawns, $position, $groupeId, BestiaireGroupe::auto($theme));
}

/** Les créatures de la Crypte (optionnellement d'un tier), lues sur le catalogue — jamais recopiées. */
function creaturesCrypte(?string $tier = null): array
{
    return Monstre::where('boite', CRYPTE_THEME)->when($tier !== null, fn ($q) => $q->where('tier', $tier))
        ->pluck('nom_base')->all();
}

it('ouvre la Crypte comme thème, en entrée 10 de la rotation (Spirit Queen derrière elle), avec son libellé', function () {
    $boites = DemarreurQuete::BOITES_THEMATIQUES;

    expect(array_search(CRYPTE_THEME, $boites, true))->toBe(10)
        ->and($boites[9])->toBe('kellars_keep')
        ->and(array_keys(DemarreurQuete::BOITES_INCOMPLETES))->not->toContain(CRYPTE_THEME)
        ->and(DemarreurQuete::LIBELLES_BOITES[CRYPTE_THEME])->toBe('The Crypt of Perpetual Darkness');
});

it('tire VENIM comme rencontre finale de TOUT groupe de la Crypte, à toute position d\'arc', function () {
    foreach (range(0, 11) as $graine) {
        foreach ([1, 4, 9] as $position) {
            $achats = achatsCrypte(CRYPTE_THEME, 'boss_final', 40, 8, $position, $graine);

            expect($achats[0]->nom_base)->toBe('Venim le Dragon', "graine {$graine}, arc {$position}");
        }
    }
});

it('ne tire Venim QUE sous la Crypte : aucune autre boîte ne le fait apparaître en rencontre finale', function () {
    foreach (DemarreurQuete::BOITES_THEMATIQUES as $theme) {
        if ($theme === CRYPTE_THEME) {
            continue;
        }

        foreach (range(0, 11) as $graine) {
            $noms = collect(achatsCrypte($theme, 'boss_final', 400, 8, 1, $graine))->pluck('nom_base');

            expect($noms)->not->toContain('Venim le Dragon', "thème {$theme}, graine {$graine}");
        }
    }
});

it('les sous-boss de la Crypte sortent SOUS la Crypte, et jamais ailleurs', function () {
    $sousBossCrypte = creaturesCrypte('sous_boss');

    expect($sousBossCrypte)->toHaveCount(7);

    $sousCrypte = collect(range(0, 11))
        ->flatMap(fn (int $g) => collect(achatsCrypte(CRYPTE_THEME, 'sous_boss', 40, 8, 1, $g))->pluck('nom_base'))
        ->unique()->all();

    expect(array_intersect($sousBossCrypte, $sousCrypte))->not->toBeEmpty('aucun sous-boss de la Crypte n\'est tiré sous la Crypte.');

    foreach (DemarreurQuete::BOITES_THEMATIQUES as $theme) {
        if ($theme === CRYPTE_THEME) {
            continue;
        }

        // La rotation indexe le pool par (graine + arc) % taille : il faut balayer assez de graines pour atteindre TOUT le
        // pool, dont les dernières entrées (les créatures de la Crypte, ajoutées en fin d'identifiants) — douze graines ne
        // suffisent pas, et c'est ce que l'essai de la réserve a montré.
        foreach (range(0, 120) as $graine) {
            $noms = collect(achatsCrypte($theme, 'sous_boss', 400, 8, 1, $graine))->pluck('nom_base')->all();

            expect(array_intersect($sousBossCrypte, $noms))->toBe([], "thème {$theme}, graine {$graine} : un sous-boss de la Crypte est sorti.");
        }
    }
});

it('la Crypte n\'a AUCUN monstre de tier base, et chacune de ses créatures est réservée à son thème', function () {
    expect(Monstre::where('boite', CRYPTE_THEME)->where('tier', 'base')->count())->toBe(0);

    foreach (Monstre::where('boite', CRYPTE_THEME)->get() as $m) {
        expect($m->reserveASonTheme())->toBeTrue($m->nom_base);
    }

    // Et hors de la Crypte, rien ne porte la réserve : la capacité ne fuit pas vers les autres boîtes.
    expect(Monstre::where('boite', '!=', CRYPTE_THEME)->get()->filter(fn (Monstre $m) => $m->reserveASonTheme())->count())->toBe(0);
});

it('l\'escorte d\'une campagne de la Crypte vient du jeu de base, comme celle de toute boîte sans base', function () {
    $escorte = collect(achatsCrypte(CRYPTE_THEME, 'normale', 40, 12, 1, 3));

    expect($escorte)->not->toBeEmpty()
        ->and($escorte->pluck('boite')->contains('base'))->toBeTrue();
});

it('FIGE le thème : un groupe figé sur une autre boîte ne bouge pas quand la rotation s\'allonge', function () {
    $demarreur = app(DemarreurQuete::class);

    $id = array_search(CRYPTE_THEME, DemarreurQuete::BOITES_THEMATIQUES, true);
    $groupe = new \App\Models\Groupe(['theme_bestiaire' => 'dread_moon']);
    $groupe->id = $id + count(DemarreurQuete::BOITES_THEMATIQUES);

    expect($demarreur->themeBestiaire((int) $groupe->id))->toBe(CRYPTE_THEME)
        ->and($demarreur->themeBestiaireDuGroupe($groupe))->toBe('dread_moon')
        ->and(BestiaireGroupe::duGroupe($groupe)->contient(CRYPTE_THEME))->toBeFalse();
});

it('le coût de Venim suit la méthode mesurée, sur la droite des boss', function () {
    $v = Monstre::where('nom_base', 'Venim le Dragon')->firstOrFail();
    $attaques = $v->pv_body / (1.5 - $v->defense / 6);

    expect((int) round(20 + 0.5 * ($attaques - 15)))->toBe((int) $v->cout);
});
