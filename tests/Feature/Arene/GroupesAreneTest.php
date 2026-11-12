<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\RefusArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\DB;

/**
 * MODE ARÈNE, lot A2 — le groupe d'arène : ouverture, engagement par doublure, purge à DOUBLE VERROU et ménage de
 * secours (`docs/plan-mode-arene.md` §3, §6).
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, SortSeeder::class, ObjetSeeder::class]);
    $this->joueur = connecterJoueur();
});

function herosAreneDe(string $nom, string $mode = 'arene'): Personnage
{
    $id = test()->postJson('/api/personnages', ['nom' => $nom, 'classe' => 'barbare', 'mode' => $mode])
        ->assertCreated()->json('personnage.id');

    return Personnage::findOrFail($id);
}

it('ouvre un combat d\'arène : un groupe en mode arène, au code propre', function () {
    $arene = app(GroupesArene::class)->ouvrir();

    expect($arene->estArene())->toBeTrue()
        ->and($arene->refresh()->mode)->toBe(Groupe::MODE_ARENE)
        ->and($arene->identifiant)->toStartWith('arene-');
});

it('engage un héros d\'arène par sa DOUBLURE, une seule par combat', function () {
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $heros = herosAreneDe('Champion');

    $doublure = $arenes->engager($arene, $heros);

    expect($doublure->doublure_de)->toBe($heros->id)
        ->and($doublure->groupe_actif_id)->toBe($arene->id)
        ->and($arene->personnages()->pluck('personnages.id')->all())->toBe([$doublure->id])
        ->and($heros->refresh()->groupe_actif_id)->toBeNull()
        ->and(fn () => $arenes->engager($arene, $heros))->toThrow(RefusArene::class);
});

it('refuse d\'engager un héros de campagne, une doublure, ou dans une campagne', function () {
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $campagne = creerGroupe();
    $heros = herosAreneDe('Champion');

    expect(fn () => $arenes->engager($arene, herosAreneDe('Aventurier', 'campagne')))->toThrow(RefusArene::class)
        ->and(fn () => $arenes->engager($campagne, $heros))->toThrow(RefusArene::class);

    $doublure = $arenes->engager($arene, $heros);
    expect(fn () => $arenes->engager($arenes->ouvrir(), $doublure))->toThrow(RefusArene::class)
        ->and($campagne->personnages()->count())->toBe(0);
});

it('ÉTANCHÉITÉ par le groupe d\'arène : engager, combattre, purger laisse le héros identique au bit près', function () {
    $arenes = app(GroupesArene::class);
    $heros = herosAreneDe('Champion');
    $avant = empreinteDuHeros($heros->id);

    $arene = $arenes->ouvrir();
    $doublure = $arenes->engager($arene, $heros);
    $doublure->update(['pv_body' => 0, 'or' => 30]);
    $doublure->inventaire()->delete();
    Inventaire::create(['personnage_id' => $doublure->id, 'objet_id' => DB::table('objets')->value('id'),
        'quantite' => 1, 'emplacement' => 'sac']);

    $arenes->purger($arene);

    expect(empreinteDuHeros($heros->id))->toBe($avant)
        ->and(Groupe::whereKey($arene->id)->exists())->toBeFalse()
        ->and(Personnage::whereNotNull('doublure_de')->count())->toBe(0)
        ->and(DB::table('groupe_personnages')->where('groupe_id', $arene->id)->count())->toBe(0);
});

it('VERROU 1 : la purge d\'arène refuse une campagne, et n\'y touche à rien', function () {
    $joueur = $this->joueur;
    $campagne = creerGroupe();
    $heros = creerHeros($joueur, $campagne, 'Aldric', 0);
    $avant = empreinteDuHeros($heros->id);

    expect(fn () => app(GroupesArene::class)->purger($campagne))->toThrow(RefusArene::class);

    // Même une instance trafiquée en mémoire ne passe pas : le mode est relu en base.
    $campagne->mode = Groupe::MODE_ARENE;
    expect(fn () => app(GroupesArene::class)->purger($campagne))->toThrow(RefusArene::class)
        ->and(Groupe::whereKey($campagne->id)->exists())->toBeTrue()
        ->and(empreinteDuHeros($heros->id))->toBe($avant);
});

it('VERROU 2 : la purge d\'arène refuse un groupe qui compte un VRAI héros, avant de supprimer quoi que ce soit', function () {
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $doublure = $arenes->engager($arene, herosAreneDe('Champion'));
    // Un vrai héros glissé dans le groupe d'arène, par n'importe quel chemin : le pivot…
    $vrai = herosAreneDe('Aventurier', 'campagne');
    $arene->personnages()->attach($vrai->id, ['ordre_initiative' => 1, 'actif' => true]);
    $avant = empreinteDuHeros($vrai->id);

    expect(fn () => $arenes->purger($arene))->toThrow(RefusArene::class)
        ->and(Groupe::whereKey($arene->id)->exists())->toBeTrue()
        ->and(Personnage::whereKey($doublure->id)->exists())->toBeTrue()
        ->and(empreinteDuHeros($vrai->id))->toBe($avant);

    // … ou `groupe_actif_id` seul.
    $arene->personnages()->detach($vrai->id);
    DB::table('personnages')->where('id', $vrai->id)->update(['groupe_actif_id' => $arene->id]);

    expect(fn () => $arenes->purger($arene))->toThrow(RefusArene::class)
        ->and(Personnage::whereKey($vrai->id)->exists())->toBeTrue();
});

it('arene:nettoyer liste sans rien toucher, puis ne supprime que les combats abandonnés et les doublures orphelines', function () {
    $arenes = app(GroupesArene::class);
    $heros = herosAreneDe('Champion');
    $campagne = creerGroupe();
    $aventurier = creerHeros($this->joueur, $campagne, 'Aldric', 0);

    $this->travel(-10)->hours();
    $vieux = $arenes->ouvrir();
    $arenes->engager($vieux, $heros);
    $orpheline = app(\App\Partie\Arene\Doublures::class)->doubler($heros);
    $this->travelBack();
    $recent = $arenes->ouvrir();
    $doublureRecente = $arenes->engager($recent, $heros);
    $campagne->forceFill(['updated_at' => now()->subDays(30)])->save();

    $this->artisan('arene:nettoyer')->assertSuccessful();
    expect(Groupe::whereKey($vieux->id)->exists())->toBeTrue()
        ->and(Personnage::whereKey($orpheline->id)->exists())->toBeTrue();

    $this->artisan('arene:nettoyer --supprimer')->assertSuccessful();

    expect(Groupe::whereKey($vieux->id)->exists())->toBeFalse()
        ->and(Personnage::whereKey($orpheline->id)->exists())->toBeFalse()
        ->and(Groupe::whereKey($recent->id)->exists())->toBeTrue()
        ->and(Personnage::whereKey($doublureRecente->id)->exists())->toBeTrue()
        ->and(Groupe::whereKey($campagne->id)->exists())->toBeTrue()
        ->and(Personnage::whereKey($aventurier->id)->exists())->toBeTrue()
        ->and(Personnage::whereKey($heros->id)->exists())->toBeTrue();
});
