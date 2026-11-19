<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LA QUÊTE DOUBLE (lot K5p, R10 — *The Frozen Horror* q. 9-10 : « they cannot enter Quest 10 until all the heroes have
 * moved onto the stairway […] Mind and Body Points are not restored […] When the heroes return to Quest 9, set out only
 * room A' […] Monsters they killed previously do not reappear »). Plateau et quêtes de `DocumentExemple`, INVENTÉS : la
 * première quête devient la principale, avec un escalier de passage en (7, 4) dans la `galerie` ; la seconde, « La crypte »,
 * devient sa seconde moitié, où l'on arrive par son départ en (5, 2).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagneDouble(): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['double'] = ['quete' => 'la-crypte', 'passage' => ['x' => 7, 'y' => 4]];
    $campagne['quetes'][1]['double'] = ['quete' => 'premiere-marche'];
    $campagne['quetes'][1]['fin'] = null;

    return $campagne;
}

function refusDouble(array $campagne): array
{
    return collect(app(ValidationCampagne::class)->valider($campagne))->map(fn ($r) => $r['chemin'].' '.$r['code'])->all();
}

function menuDouble(object $t, Personnage $heros): \Illuminate\Support\Collection
{
    Cache::forget(GenererMenu::cleDuHeros($t->groupe->refresh(), $heros));

    return collect($t->getJson("/api/groupes/{$t->code}/menu")->assertOk()->json('menu.options'));
}

it('valide une quête double, et nomme ce qui cloche', function () {
    expect(refusDouble(campagneDouble()))->toBe([]);

    $sansPassage = campagneDouble();
    unset($sansPassage['quetes'][0]['double']['passage']);
    expect(refusDouble($sansPassage))->toContain('quetes.0.double.passage '.ValidationCampagne::CLE_MANQUANTE);

    $avecFin = campagneDouble();
    $avecFin['quetes'][1]['fin'] = DocumentExemple::secondeQuete()['fin'];
    expect(refusDouble($avecFin))->toContain('quetes.1.fin '.ValidationCampagne::VALEUR_INVALIDE);

    $ailleurs = campagneDouble();
    $ailleurs['quetes'][1]['double']['quete'] = 'nulle-part';
    expect(refusDouble($ailleurs))->toContain('quetes.1.double.quete '.ValidationCampagne::DOUBLE_INVALIDE);

    $parSuite = campagneDouble();
    $parSuite['quetes'][0]['suite'] = [['quete' => 'la-crypte']];
    expect(refusDouble($parSuite))->toContain('quetes.0.suite.0.quete '.ValidationCampagne::DOUBLE_INVALIDE);
});

it('EN JEU : passer, basculer sans restaurer, revenir dans la salle du passage seule — morts restés morts', function () {
    app(CampagnesConstruites::class)->enregistrer(campagneDouble());
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $this->code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $this->groupe = Groupe::where('identifiant', $this->code)->firstOrFail();
    $this->postJson("/api/groupes/{$this->code}/quetes")->assertCreated();
    $principale = Quete::findOrFail($this->groupe->refresh()->quete_courante_id);

    // Ailleurs que sur l'escalier : rien. Sur l'escalier : le passage, nommé.
    expect(menuDouble($this, $heros)->pluck('id'))->not->toContain('passer_double');
    $principale->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 5]);
    expect(menuDouble($this, $heros)->firstWhere('id', 'passer_double')['libelle'] ?? null)->toBe('Emprunter le passage : La crypte');
    $heros->update(['pv_body' => 5]);

    $this->postJson("/api/groupes/{$this->code}/choix", ['option_id' => 'passer_double'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'passage_double')
        ->assertJsonPath('resultat.quete.etat', 'bascule');

    // LA SECONDE MOITIÉ : courante, reliée, le héros sur son départ, ses points NON restaurés ; la principale suspendue.
    $seconde = Quete::findOrFail($this->groupe->refresh()->quete_courante_id);
    expect($seconde->titre)->toBe('La crypte')
        ->and((int) $seconde->double_id)->toBe((int) $principale->id)
        ->and((int) $principale->refresh()->double_id)->toBe((int) $seconde->id)
        ->and($principale->etat)->toBe('en_cours')
        ->and((int) $heros->refresh()->pv_body)->toBe(5)
        ->and($seconde->etatsPersonnages()->firstOrFail()->only(['position_x', 'position_y']))->toBe(['position_x' => 5, 'position_y' => 2])
        // L'objectif est celui de la principale : son gobelin vit encore.
        ->and($seconde->objectifAccompli())->toBeFalse()
        ->and($seconde->objectif_construit['libelle'])->toBe($principale->objectif_construit['libelle']);
    // Ni sortie ni vote de sortie dans la seconde moitié : on en repart par où l'on est venu.
    expect(menuDouble($this, $heros)->pluck('id'))->toContain('passer_double')
        ->not->toContain('sortir_donjon')->not->toContain('quitter_donjon');

    // Un drapeau posé ici se lit dans la principale.
    $seconde->poserDrapeau('cle_de_cristal');
    expect($principale->refresh()->aDrapeau('cle_de_cristal'))->toBeTrue();

    // RETOUR : la principale reprend — seule la salle du passage découverte, le squelette de la crypte reste où il est.
    $principale->instancesMonstres()->update(['etat' => 'vaincu']);
    $this->postJson("/api/groupes/{$this->code}/choix", ['option_id' => 'passer_double'])->assertAccepted()
        ->assertJsonPath('resultat.quete.etat', 'bascule');
    $principale->refresh();
    $galerie = \App\Partie\Salles::indexDe($principale->carte->grille['salles'], 7, 4);
    expect((int) $this->groupe->refresh()->quete_courante_id)->toBe((int) $principale->id)
        ->and($principale->sallesDecouvertes())->toBe([$galerie])
        ->and($principale->instancesMonstres()->where('etat', 'actif')->count())->toBe(0)
        ->and(in_array($principale->etatsPersonnages()->firstOrFail()->only(['position_x', 'position_y']),
            [['position_x' => 7, 'position_y' => 4], ['position_x' => 8, 'position_y' => 4], ['position_x' => 7, 'position_y' => 5], ['position_x' => 8, 'position_y' => 5]], true))->toBeTrue();
});

it('la fin de l\'une clôt l\'autre, et la campagne saute la seconde moitié', function () {
    app(CampagnesConstruites::class)->enregistrer(campagneDouble());
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $this->code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $this->groupe = Groupe::where('identifiant', $this->code)->firstOrFail();
    $this->postJson("/api/groupes/{$this->code}/quetes")->assertCreated();
    $principale = Quete::findOrFail($this->groupe->refresh()->quete_courante_id);
    $principale->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 5]);
    menuDouble($this, $heros);
    $this->postJson("/api/groupes/{$this->code}/choix", ['option_id' => 'passer_double'])->assertAccepted();
    $seconde = Quete::findOrFail($this->groupe->refresh()->quete_courante_id);

    // Gagnée DEPUIS la seconde moitié : la récompense de la principale, et la campagne est finie (deux quêtes, la seconde
    // sautée) — rang 3.
    $resultat = app(ResolveurTour::class)->terminerQuete($this->groupe->refresh(), $seconde);

    expect($principale->refresh()->etat)->toBe('terminee')
        ->and($resultat['classique']['rang_suivant'])->toBe(3)
        ->and(array_sum(array_column($resultat['classique']['recompense'], 'or')))->toBe(10);
});
