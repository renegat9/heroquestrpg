<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurPortes;
use App\Partie\ZoneFouille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LE PASSAGE SECRET SUR UNE ARÊTE (René, 2026-11-15 : « tu t'assureras que les passages secrets sont fonctionnels suite
 * aux modifications aux portes sur une arête »), par les VRAIES routes puis par les trois révélateurs du moteur.
 *
 * Sur une carte à murs en arêtes, un passage secret n'occupe plus de case : c'est le bord commun de deux cases, peint
 * en mur tant que personne ne l'a trouvé. `MursEnAretesEnJeuTest` défend ce déguisement. Ici, la suite — ce qui se
 * passe quand on le TROUVE, par chacune des trois façons de le trouver :
 *
 *  1. la FOUILLE de zone le révèle depuis la salle comme depuis le couloir ; trouvé, il est publié comme une porte
 *     FERMÉE sur son arête, et le mur qui le déguisait n'est plus publié ;
 *  2. il s'ouvre depuis SA case (pas depuis la voisine), révèle ce qu'il cachait et se franchit en un pas ;
 *  3. le talent de DÉTECTION (« Parler à la pierre ») le sent quand on se tient sur l'une de ses deux cases — sa
 *     géométrie testait la case voisine d'une porte à embrasure, et ne voyait pas la porte dont on touche le bord ;
 *  4. la Potion de VISION le voit des deux côtés du mur — elle ne visait que la case où l'auteur a écrit la porte.
 *
 * La carte est celle de `DocumentExemple` (plateau, quête et textes INVENTÉS) : `galerie` (x 7–10, y 2–5) porte un
 * passage secret sur le bord sud de (9, 5), vers la case de couloir (9, 6).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());

    $alice = connecterJoueur('alice');
    $this->heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $this->code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $this->heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $this->postJson("/api/groupes/{$this->code}/quetes")->assertCreated();
    $this->groupe = Groupe::where('identifiant', $this->code)->firstOrFail();
    $this->quete = Quete::findOrFail($this->groupe->quete_courante_id);

    // Le gobelin de l'entrée est retiré de la partie : ces tests portent sur la porte, pas sur le combat.
    $this->quete->instancesMonstres()->update(['etat' => 'vaincu']);
});

/** Pose le héros sur une case et rend son menu, recalculé sur l'état présent. */
function menuSurCase(object $t, int $x, int $y): \Illuminate\Support\Collection
{
    $t->quete->etatsPersonnages()->where('personnage_id', $t->heros->id)->update(['position_x' => $x, 'position_y' => $y]);
    Cache::forget(GenererMenu::cleDuHeros($t->groupe, $t->heros));

    return collect($t->getJson("/api/groupes/{$t->code}/menu")->assertOk()->json('menu.options'));
}

/** Le passage secret de (9, 5, s) tel que la carte le porte en ce moment. */
function passageSecretDeLaGalerie(object $t): array
{
    return collect(app(MoteurPortes::class)->portes($t->quete->carte->fresh()))
        ->first(fn (array $p) => (int) $p['x'] === 9 && (int) $p['y'] === 5 && ($p['cote'] ?? null) === 's');
}

/** Entre dans `galerie` par sa porte, comme en jeu. */
function entrerDansLaGalerie(object $t): void
{
    menuSurCase($t, 6, 3);
    $t->postJson("/api/groupes/{$t->code}/choix", ['option_id' => 'ouvrir_porte_6_3_e'])->assertAccepted();
}

it('la fouille de la salle trouve le passage secret : il devient une porte fermée sur son arête, et le mur qui le déguisait disparaît', function () {
    entrerDansLaGalerie($this);

    // Avant : un mur comme les autres, aucune porte à ouvrir, même le nez dessus.
    $avant = $this->getJson("/api/groupes/{$this->code}/etat")->assertOk()->json('carte');
    expect(collect($avant['murs'])->contains(['x' => 9, 'y' => 5, 'cote' => 's']))->toBeTrue()
        ->and(collect($avant['portes'])->contains(fn ($p) => $p['x'] === 9 && $p['y'] === 5))->toBeFalse()
        ->and(menuSurCase($this, 9, 5)->pluck('id'))->not->toContain('ouvrir_porte_9_5_s');

    // On fouille depuis l'autre bout de la salle : la fouille couvre la salle ENTIÈRE.
    menuSurCase($this, 7, 2);
    desFiges([1, 4]); // Mind 2 dés : un crâne, réussite

    $this->postJson("/api/groupes/{$this->code}/choix", ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'reussite')
        ->assertJsonPath('resultat.portes_revelees.0.x', 9)
        ->assertJsonPath('resultat.portes_revelees.0.y', 5);

    expect(passageSecretDeLaGalerie($this))->toMatchArray(['etat' => 'fermee', 'revele' => true, 'arete' => true]);

    // Publié comme une PORTE fermée sur son arête — et plus comme un mur. Le couloir derrière reste dans le brouillard :
    // trouver n'est pas franchir.
    $apres = $this->getJson("/api/groupes/{$this->code}/etat")->assertOk()->json('carte');
    $porte = collect($apres['portes'])->first(fn ($p) => $p['x'] === 9 && $p['y'] === 5);

    expect($porte)->not->toBeNull()
        ->and($porte['cote'])->toBe('s')
        ->and($porte['etat'])->toBe('fermee')
        ->and($porte['arete'])->toBeTrue()
        ->and(collect($apres['murs'])->contains(['x' => 9, 'y' => 5, 'cote' => 's']))->toBeFalse()
        ->and($apres['cases'][6][9])->toBe('b');
});

it('trouvé, le passage s\'ouvre depuis SA case, révèle le couloir qu\'il cachait et se franchit en un pas', function () {
    entrerDansLaGalerie($this);
    app(MoteurPortes::class)->revelerSecretesAutour(
        $this->groupe, $this->quete->carte, $this->heros, ZoneFouille::de((array) $this->quete->carte->grille, 8, 3),
    );

    // À portée de main depuis la case dont il est le bord — pas depuis la voisine, ni depuis celle du dessus.
    expect(menuSurCase($this, 8, 5)->pluck('id'))->not->toContain('ouvrir_porte_9_5_s');
    expect(menuSurCase($this, 9, 4)->pluck('id'))->not->toContain('ouvrir_porte_9_5_s');
    expect(menuSurCase($this, 9, 5)->pluck('id'))->toContain('ouvrir_porte_9_5_s');

    // Fermé, on ne passe pas : (9, 6) n'est pas une destination.
    $avant = menuSurCase($this, 9, 5)->firstWhere('id', 'se_deplacer');
    expect(collect($avant['parametres']['destinations'])->contains(fn ($d) => $d['x'] === 9 && $d['y'] === 6))->toBeFalse();

    $this->postJson("/api/groupes/{$this->code}/choix", ['option_id' => 'ouvrir_porte_9_5_s'])->assertAccepted();

    expect(passageSecretDeLaGalerie($this)['etat'])->toBe('ouverte');

    // Le couloir se découvre, et la case d'en face est à UN pas.
    $carte = $this->getJson("/api/groupes/{$this->code}/etat")->assertOk()->json('carte');
    expect($carte['cases'][6][9])->not->toBe('b');

    $apres = menuSurCase($this, 9, 5)->firstWhere('id', 'se_deplacer');
    expect(collect($apres['parametres']['destinations'])->contains(fn ($d) => $d['x'] === 9 && $d['y'] === 6))->toBeTrue();

    // Et dans l'autre sens : depuis le couloir, la porte est à portée et la galerie à un pas.
    $grille = FabriqueGrille::pour($this->quete->fresh(), $this->heros->id);
    expect($grille->areteBloquee(9, 6, 9, 5))->toBeFalse()
        ->and($grille->ligneDeVue(9, 7, 9, 4))->toBeTrue();
});

it('la fouille du COULOIR le trouve aussi, et il s\'ouvre de ce côté-là', function () {
    $portes = app(MoteurPortes::class);

    // Le héros est dans le couloir, de l'autre côté du mur : sa zone de fouille est le couloir.
    $reveles = $portes->revelerSecretesAutour(
        $this->groupe, $this->quete->carte, $this->heros, ZoneFouille::de((array) $this->quete->carte->grille, 12, 6),
    );

    expect($reveles)->toBe([['x' => 9, 'y' => 5]])
        ->and(passageSecretDeLaGalerie($this))->toMatchArray(['etat' => 'fermee', 'revele' => true]);

    // À portée de main depuis la case de couloir qui touche son bord — et pas depuis celle d'à côté.
    $aPortee = fn (int $x, int $y) => collect($portes->portesFermeesAdjacentes($this->quete->carte->fresh(), $x, $y))
        ->contains(fn ($p) => (int) $p['porte']['x'] === 9 && (int) $p['porte']['y'] === 5);

    expect($aPortee(9, 6))->toBeTrue()
        ->and($aPortee(10, 6))->toBeFalse();
});

it('le talent de détection le sent depuis l\'une de ses deux cases, et pas depuis la case d\'à côté', function () {
    $portes = app(MoteurPortes::class);
    $nain = Personnage::create([
        'joueur_id' => $this->heros->joueur_id, 'nom' => 'Brunehaut', 'classe' => 'nain', 'niveau' => 1,
        'attribut_body' => 3, 'attribut_mind' => 3, 'pv_body_max' => 7, 'pv_body' => 7, 'pv_mind_max' => 3, 'pv_mind' => 3,
        'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $sentir = fn (int $x, int $y) => $portes->detecterSecretesAdjacentes($this->groupe, $this->quete->carte->fresh(), $nain->fresh(), $x, $y);

    // Sans le talent : rien, même le nez sur le mur.
    expect($sentir(9, 5))->toBe([]);

    donnerTalent($nain, 'Parler à la pierre');

    // À côté du passage, contre le même mur : on touche un mur, pas la porte.
    expect($sentir(8, 5))->toBe([])
        ->and($sentir(9, 4))->toBe([])
        ->and(passageSecretDeLaGalerie($this)['etat'])->toBe('secrete');

    // Sur la case dont le passage est le bord : il se révèle.
    expect($sentir(9, 5))->toBe([['x' => 9, 'y' => 5]])
        ->and(passageSecretDeLaGalerie($this))->toMatchArray(['etat' => 'fermee', 'revele' => true]);
});

it('le talent de détection le sent aussi depuis la case de couloir, de l\'autre côté', function () {
    $nain = Personnage::create([
        'joueur_id' => $this->heros->joueur_id, 'nom' => 'Brunehaut', 'classe' => 'nain', 'niveau' => 1,
        'attribut_body' => 3, 'attribut_mind' => 3, 'pv_body_max' => 7, 'pv_body' => 7, 'pv_mind_max' => 3, 'pv_mind' => 3,
        'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    donnerTalent($nain, 'Parler à la pierre');

    expect(app(MoteurPortes::class)->detecterSecretesAdjacentes($this->groupe, $this->quete->carte->fresh(), $nain->fresh(), 9, 6))
        ->toBe([['x' => 9, 'y' => 5]]);
});

it('la Potion de vision le voit depuis la salle comme depuis le couloir, mais pas à travers un autre mur', function () {
    $portes = app(MoteurPortes::class);
    $grille = fn () => FabriqueGrille::pour($this->quete->fresh(), $this->heros->id);
    $voir = fn (int $x, int $y) => $portes->revelerSecretesEnVue($this->groupe, $this->quete->carte->fresh(), $this->heros, $grille(), $x, $y);

    // Depuis `entree`, la salle voisine : la porte close et les murs coupent la vue.
    expect($voir(3, 4))->toBe([])
        ->and(passageSecretDeLaGalerie($this)['etat'])->toBe('secrete');

    // Depuis le COULOIR, face au mur : la case de couloir qui touche le passage est en vue.
    expect($voir(9, 7))->toBe([['x' => 9, 'y' => 5]])
        ->and(passageSecretDeLaGalerie($this))->toMatchArray(['etat' => 'fermee', 'revele' => true]);
});

it('la Potion de vision le voit depuis l\'intérieur de la salle', function () {
    $portes = app(MoteurPortes::class);

    expect($portes->revelerSecretesEnVue(
        $this->groupe, $this->quete->carte->fresh(), $this->heros, FabriqueGrille::pour($this->quete->fresh(), $this->heros->id), 7, 2,
    ))->toBe([['x' => 9, 'y' => 5]]);
});
