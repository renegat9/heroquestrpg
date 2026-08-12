<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\MoteurDegats;
use App\Partie\StylesElementaires;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Les quatre STYLES ÉLÉMENTAIRES du Moine et leurs huit techniques.
 *
 * Trois règles de la carte *Using Elemental Styles*, trois rythmes : le style
 * s'épuise à l'usage, une seule activation par tour, et tout revient dès qu'il
 * n'y a plus de monstre en vue. Le Feu reste verrouillé tant que les trois
 * autres tiennent — c'est ce qui fait du Moine une classe qui monte en
 * puissance à mesure que le combat dure.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/** Le menu moteur du Moine. */
function menuMoine(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];
}

it('donne au Moine ses quatre cartes de style, et à personne d\'autre', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);

    $innees = $ctx['heros']->competences()->where('innee', true)->pluck('nom')->sort()->values()->all();

    expect($innees)->toBe(["Style de l'Air", "Style de l'Eau", 'Style de la Terre', 'Style du Feu']);
    expect(app(StylesElementaires::class)->cartes($ctx['heros']))->toHaveCount(4);
});

it('épuise le STYLE ENTIER dès qu\'une de ses deux techniques est jouée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine', 'des_attaque' => 2]);
    $styles = app(StylesElementaires::class);

    // Le Moine frappe à mains nues : aucune arme équipée, ses 2 dés de fiche.
    $ids = collect(menuMoine($ctx)['options'])->pluck('id');

    expect($ids)->toContain('style_poing')->toContain('frappe_balayee');

    desFiges(array_fill(0, 40, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'style_poing',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.style_mains_nues', 2);

    // La Terre tombe — et avec elle *Parler à la Pierre*, l'autre face.
    expect($styles->epuises($ctx['etatHeros']->fresh()))->toBe(['terre']);

    // « Once per turn » : plus AUCUN style activable ce tour, même intact.
    $ctx['etatHeros']->fresh()->update(['a_agi' => false, 'a_joue' => false]);
    $ids = collect(menuMoine($ctx)['options'])->pluck('id');

    expect($ids)->not->toContain('frappe_balayee')->not->toContain('style_poing');
});

it('verrouille le FEU tant que l\'Air, la Terre et l\'Eau ne sont pas épuisés', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $styles = app(StylesElementaires::class);
    $feu = Competence::where('classe', 'moine')->where('nom', 'Style du Feu')->firstOrFail();

    expect($styles->activable($ctx['heros'], $ctx['etatHeros'], $feu))->toBeFalse();

    $ctx['etatHeros']->update(['styles_epuises' => ['air', 'terre']]);

    expect($styles->activable($ctx['heros'], $ctx['etatHeros']->fresh(), $feu))->toBeFalse();

    $ctx['etatHeros']->update(['styles_epuises' => ['air', 'terre', 'eau']]);

    expect($styles->activable($ctx['heros'], $ctx['etatHeros']->fresh(), $feu))->toBeTrue();
});

it('récupère TOUS les styles quand plus aucun monstre n\'est en vue', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $ctx['etatHeros']->update(['styles_epuises' => ['air', 'terre', 'eau', 'feu']]);

    // Le gobelin est au contact : rien ne revient tant qu'il est là.
    app(StylesElementaires::class)->recupererSiHorsDeVue($ctx['quete'], $ctx['etatHeros']->fresh());

    expect($ctx['etatHeros']->fresh()->styles_epuises)->toHaveCount(4);

    // Il tombe : au début du tour suivant, tout revient.
    $ctx['instance']->update(['etat' => 'vaincu']);
    app(StylesElementaires::class)->recupererSiHorsDeVue($ctx['quete'], $ctx['etatHeros']->fresh());

    expect($ctx['etatHeros']->fresh()->styles_epuises)->toBe([]);
});

it('ŒIL DU CYCLONE balaie à mains nues, et se refuse une arme au poing', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine', 'des_attaque' => 2]);

    // Une arme en main : la technique n'est plus proposée (« unarmed »).
    Inventaire::create([
        'personnage_id' => $ctx['heros']->id,
        'objet_id' => Objet::where('nom', 'Épée courte')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'arme_principale',
    ]);

    expect(collect(menuMoine($ctx)['options'])->pluck('id'))
        ->not->toContain('frappe_balayee')
        ->not->toContain('style_poing');

    // …et le résolveur refuse même si on force l'option.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'frappe_balayee'])->assertStatus(422);
});

it('TORRENT TOURNOYANT annule le coup et épuise l\'Eau, hors du tour du Moine', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $heros = $ctx['heros'];
    $max = (int) $heros->pv_body_max;

    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);

    $attente = $ctx['etatHeros']->fresh()->reaction_en_attente;

    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe('annule_degats')
        ->and($attente['style'])->toBe('annule_degats');

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => true,
    ])->assertOk()->assertJsonPath('reaction.active', true);

    expect((int) $heros->fresh()->pv_body)->toBe($max)
        ->and(app(StylesElementaires::class)->epuises($ctx['etatHeros']->fresh()))->toBe(['eau']);

    // ⚠ Jouée hors tour, elle ne mange pas l'activation du tour à venir : le
    // « once per turn » régit ce qu'on fait de SON tour.
    expect((array) $ctx['etatHeros']->fresh()->capacites_tour)
        ->not->toContain(StylesElementaires::ACTIVATION_DU_TOUR);
});

it('VAGUE MONTANTE rend le déplacement restant après l\'action', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $etat = $ctx['etatHeros'];

    expect(collect(menuMoine($ctx)['options'])->pluck('id'))->toContain('style_vague');

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'style_vague'])->assertStatus(202);

    // Activer un style ne coûte AUCUN créneau : le tour est intact.
    $etat->refresh();
    expect((bool) $etat->a_agi)->toBeFalse()
        ->and((bool) $etat->a_deplace)->toBeFalse()
        ->and(app(StylesElementaires::class)->epuises($etat))->toBe(['eau']);

    // Mouvement entamé, puis une action : d'ordinaire le reste est confisqué.
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => 4]);

    desFiges(array_fill(0, 40, 4));
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    expect((int) $etat->fresh()->deplacement_restant)->toBe(4)
        ->and((bool) $etat->fresh()->a_deplace)->toBeFalse();
});

it('ESPRIT ARDENT traverse la ligne et brûle tout ce qui s\'y trouve', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $etat = $ctx['etatHeros'];

    // Le Feu s'ouvre : les trois autres styles sont tombés.
    $etat->update(['styles_epuises' => ['air', 'terre', 'eau']]);

    // Deux gobelins alignés à l'est du Moine — le rayon traverse le premier.
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $ligne = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if (caseQueteLibre($ctx['quete'], $hx + $dx, $hy + $dy)
            && caseQueteLibre($ctx['quete'], $hx + 2 * $dx, $hy + 2 * $dy)) {
            $ligne = [$dx, $dy];
            break;
        }
    }
    expect($ligne)->not->toBeNull('Pas de ligne droite libre pour le scénario.');
    [$dx, $dy] = $ligne;

    $ctx['instance']->update(['position_x' => $hx + $dx, 'position_y' => $hy + $dy, 'pv_body' => 3]);
    $second = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => $ctx['instance']->monstre_id,
        'pv_body' => 3, 'pv_body_max' => 3, 'pv_mind' => 0,
        'position_x' => $hx + 2 * $dx, 'position_y' => $hy + 2 * $dy,
        'etat' => 'actif', 'revele' => true,
    ]);

    $direction = match (true) {
        $dx === 1 => 'e', $dx === -1 => 'o', $dy === 1 => 's', default => 'n',
    };

    expect(collect(menuMoine($ctx)['options'])->pluck('id'))->toContain("rayon_{$direction}");

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "rayon_{$direction}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.degats', 2);

    // ⚠ Le rayon TRAVERSE les figures : « continues until it meets a wall or
    // closed door », jamais « jusqu'au premier ennemi ».
    expect((int) $ctx['instance']->fresh()->pv_body)->toBe(1)
        ->and((int) $second->fresh()->pv_body)->toBe(1)
        ->and(app(StylesElementaires::class)->epuises($etat->fresh()))->toContain('feu');
});

it('TOUCHER DU BRASIER brûle maintenant, puis achève à la fin du tour du monstre', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $ctx['etatHeros']->update(['styles_epuises' => ['air', 'terre', 'eau']]);
    $ctx['instance']->update(['pv_body' => 3, 'pv_body_max' => 3]);

    expect(collect(menuMoine($ctx)['options'])->pluck('id'))->toContain('toucher_brasier');

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'toucher_brasier',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.degats', 1)
        ->assertJsonPath('resultat.differe', 2);

    // 1 PV tout de suite, la braise en attente.
    expect((int) $ctx['instance']->fresh()->pv_body)->toBe(2)
        ->and((int) $ctx['instance']->fresh()->degat_differe)->toBe(2);

    // Le Moine termine son tour → phase des monstres → la braise tombe et
    // s'éteint (elle ne ronge pas tous les tours, contrairement au rejeton).
    desFiges(array_fill(0, 60, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $instance = $ctx['instance']->fresh();

    expect((int) $instance->pv_body)->toBe(0)
        ->and($instance->etat)->toBe('vaincu')
        ->and($instance->degat_differe)->toBeNull();
});
