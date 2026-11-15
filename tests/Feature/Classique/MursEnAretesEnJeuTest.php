<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * MURS EN ARÊTES, EN JEU (René, 2026-11-14 : « les portes entre deux salles ne sont pas une case mais le bord commun
 * entre deux cases »). Une quête construite jouée par les VRAIES routes, sur le plateau INVENTÉ de `DocumentExemple` :
 * `entree` (x 2–6, y 2–5) et `galerie` (x 7–10, y 2–5) se touchent ; leur porte est le bord commun de (6, 3) et (7, 3).
 *
 *  1. Le BROUILLARD ne fuit pas à travers un mur qui n'occupe plus de case : ni vers le couloir, ni vers la salle voisine.
 *  2. L'état publie les MURS visibles et des portes SUR ARÊTE, sans embrasure.
 *  3. Une porte est à portée de main depuis SA case — pas depuis la case d'à côté.
 *  4. Ouverte, elle révèle la salle voisine, qu'on atteint en UN pas.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
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
});

/** Pose le héros sur une case et rend son menu, recalculé sur l'état présent. */
function menuDepuis(object $t, int $x, int $y): \Illuminate\Support\Collection
{
    $t->quete->etatsPersonnages()->where('personnage_id', $t->heros->id)->update(['position_x' => $x, 'position_y' => $y]);
    Cache::forget(GenererMenu::cleDuHeros($t->groupe, $t->heros));

    return collect($t->getJson("/api/groupes/{$t->code}/menu")->assertOk()->json('menu.options'));
}

it('le brouillard ne fuit pas à travers un mur en arête : ni le couloir ni la salle voisine ne sont vus', function () {
    $cases = $this->getJson("/api/groupes/{$this->code}/etat")->assertOk()->json('carte.cases');

    expect($cases[3][6])->toBe('s')        // `entree`, la salle de départ : vue
        ->and($cases[3][7])->toBe('b')      // `galerie`, collée à elle, derrière le mur : sous le brouillard
        ->and($cases[1][3])->toBe('b')      // le couloir, derrière la porte nord fermée
        ->and($cases[1][5])->toBe('b');     // le couloir qui longe le mur nord
});

it('publie les murs visibles et des portes sur arête, sans embrasure', function () {
    $carte = $this->getJson("/api/groupes/{$this->code}/etat")->assertOk()->json('carte');
    $murs = collect($carte['murs']);

    // Les murs d'`entree` : son bord nord et son bord commun avec `galerie` — sauf au droit des deux portes.
    expect($murs->contains(['x' => 2, 'y' => 1, 'cote' => 's']))->toBeTrue()
        ->and($murs->contains(['x' => 6, 'y' => 2, 'cote' => 'e']))->toBeTrue()
        ->and($murs->contains(['x' => 6, 'y' => 3, 'cote' => 'e']))->toBeFalse()
        // Rien de `galerie`, qu'on n'a pas vue : ni ses murs du fond, ni son passage secret.
        ->and($murs->contains(['x' => 10, 'y' => 3, 'cote' => 'e']))->toBeFalse()
        ->and($murs->contains(['x' => 9, 'y' => 5, 'cote' => 's']))->toBeFalse();

    $portes = collect($carte['portes']);
    expect($portes->pluck('arete')->unique()->all())->toBe([true])
        ->and($portes->pluck('embrasure')->unique()->all())->toBe([null])
        // Le passage secret n'est pas publié comme porte.
        ->and($portes->where('etat', 'secrete'))->toHaveCount(0);
});

it('offre d\'ouvrir une porte depuis SA case, pas depuis la case voisine', function () {
    expect(menuDepuis($this, 5, 3)->pluck('id'))->not->toContain('ouvrir_porte_6_3_e');
    expect(menuDepuis($this, 6, 2)->pluck('id'))->not->toContain('ouvrir_porte_6_3_e');

    $option = menuDepuis($this, 6, 3)->firstWhere('id', 'ouvrir_porte_6_3_e');
    expect($option)->not->toBeNull()
        ->and($option['parametres']['porte'])->toBe(['x' => 6, 'y' => 3, 'cote' => 'e']);
});

it('ouverte, la porte révèle la salle voisine, atteinte en un pas, et son passage secret reste un mur', function () {
    menuDepuis($this, 6, 3);
    $this->postJson("/api/groupes/{$this->code}/choix", ['option_id' => 'ouvrir_porte_6_3_e'])->assertAccepted();

    expect($this->quete->refresh()->sallesDecouvertes())->toContain(1);

    $carte = $this->getJson("/api/groupes/{$this->code}/etat")->assertOk()->json('carte');
    $murs = collect($carte['murs']);
    expect($carte['cases'][3][7])->toBe('s')   // `galerie` est vue
        ->and($carte['cases'][6][9])->toBe('b') // le couloir derrière son passage secret, non
        // Ses murs sont publiés ; le passage secret de (9, 5), non trouvé, l'est COMME un mur.
        ->and($murs->contains(['x' => 10, 'y' => 3, 'cote' => 'e']))->toBeTrue()
        ->and($murs->contains(['x' => 9, 'y' => 5, 'cote' => 's']))->toBeTrue()
        ->and(collect($carte['portes'])->where('etat', 'secrete'))->toHaveCount(0);

    // Un pas de (6, 3) à (7, 3) : la case de `galerie` est une destination du héros.
    $deplacer = menuDepuis($this, 6, 3)->firstWhere('id', 'se_deplacer');
    expect(collect($deplacer['parametres']['destinations'])->contains(fn ($d) => $d['x'] === 7 && $d['y'] === 3))->toBeTrue();
});
