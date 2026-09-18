<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\Equipement;
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
 * DEUX ARMES EN MAIN (dual-wielding, décision de René 2026-08-12).
 *
 * Quatre tenues, et quatre seulement : deux armes à une main · une arme à deux
 * mains · une arme à une main + un bouclier · une arme à une main seule.
 *
 * ⚠ La seconde arme n'apporte AUCUN dé. Elle apporte un CHOIX : le menu offre
 * une attaque par arme, chacune avec ses propres cibles légales — l'arbalète ne
 * vise pas les mêmes cases que l'épée.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/** Met un objet du catalogue au SAC du héros et rend sa ligne. */
function auSacDe(App\Models\Personnage $heros, string $nom): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'sac',
    ]);
}

it('accepte deux armes à une main, une dans chaque main', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $equipement = app(Equipement::class);

    $equipement->equiper($heros, auSacDe($heros, 'Épée large'));
    $equipement->equiper($heros, auSacDe($heros, 'Dague'), 'arme_secondaire');

    $mains = $heros->fresh()->inventaire()->whereIn('emplacement', ['arme_principale', 'arme_secondaire'])
        ->with('objet')->get()->pluck('objet.nom', 'emplacement')->all();

    expect($mains)->toBe(['arme_principale' => 'Épée large', 'arme_secondaire' => 'Dague']);

    // ⚠ La main gauche ne donne AUCUN dé : la colonne reste celle de l'épée.
    expect((int) $heros->fresh()->des_attaque)->toBe(3);
});

it('refuse une seconde arme quand la première se manie à deux mains', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $equipement = app(Equipement::class);

    $equipement->equiper($heros, auSacDe($heros, 'Hache de bataille')); // deux mains

    expect(fn () => $equipement->equiper($heros, auSacDe($heros, 'Dague'), 'arme_secondaire'))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    // …et le bouclier reste refusé, comme avant.
    expect(fn () => $equipement->equiper($heros, auSacDe($heros, 'Bouclier')))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('refuse une arme à DEUX mains tant que l\'autre main est occupée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $equipement = app(Equipement::class);

    $equipement->equiper($heros, auSacDe($heros, 'Dague'), 'arme_secondaire');

    expect(fn () => $equipement->equiper($heros, auSacDe($heros, 'Hache de bataille')))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('refuse d\'envoyer une arme à deux mains dans la main gauche', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];

    expect(fn () => app(Equipement::class)->equiper($heros, auSacDe($heros, 'Hache de bataille'), 'arme_secondaire'))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    // Et un bouclier ne va pas en main droite : il n'a qu'un emplacement.
    expect(app(Equipement::class)->slotsPossibles(Objet::where('nom', 'Bouclier')->firstOrFail()))
        ->toBe(['arme_secondaire'])
        ->and(app(Equipement::class)->slotsPossibles(Objet::where('nom', 'Dague')->firstOrFail()))
        ->toBe(['arme_principale', 'arme_secondaire']);
});

it('laisse l\'arme à une main cohabiter avec le bouclier, comme avant', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $equipement = app(Equipement::class);
    $defenseBase = (int) $heros->des_defense;

    $equipement->equiper($heros, auSacDe($heros, 'Épée large'));
    $equipement->equiper($heros, auSacDe($heros, 'Bouclier'));

    expect((int) $heros->fresh()->des_defense)->toBe($defenseBase + 1);
});

it('offre une SEULE option `attaquer` portant une entrée PAR ARME, chacune avec ses cibles', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $equipement = app(Equipement::class);

    $equipement->equiper($heros, auSacDe($heros, 'Épée large'));
    $equipement->equiper($heros, auSacDe($heros, 'Dague'), 'arme_secondaire');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];
    $options = collect($menu['options']);

    // Sous-choix (René, 2026-09-18) : UNE SEULE option `attaquer`, plus
    // `attaquer_secondaire` — la liste PORTE les deux armes.
    expect($options->pluck('id'))->not->toContain('attaquer_secondaire');
    $option = $options->firstWhere('id', 'attaquer');
    expect($option)->not->toBeNull();

    $armes = collect($option['parametres']['armes']);
    $droite = $armes->firstWhere('slot', 'arme_principale');
    $gauche = $armes->firstWhere('slot', 'arme_secondaire');

    expect($droite)->not->toBeNull()
        ->and($gauche)->not->toBeNull()
        // Le NOM de l'arme voyage sur l'ENTRÉE, plus sur le libellé de
        // l'option (générique, « Attaquer ») : deux cartes identiques
        // seraient un choix aveugle sans lui.
        ->and($droite['nom'])->toBe('Épée large')
        ->and($gauche['nom'])->toBe('Dague')
        ->and($droite['cle'])->toBe('arme:arme_principale')
        ->and($gauche['cle'])->toBe('arme:arme_secondaire')
        ->and($droite['cibles'])->not->toBeEmpty()
        ->and($gauche['cibles'])->not->toBeEmpty();
});

it('frappe avec les dés de l\'arme CHOISIE, gauche comme droite', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $equipement = app(Equipement::class);

    $equipement->equiper($heros, auSacDe($heros, 'Épée large')); // 3 dés
    $equipement->equiper($heros, auSacDe($heros, 'Dague'), 'arme_secondaire'); // 1 dé

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 40, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cle' => 'arme:arme_principale', 'cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.des_attaque_effectifs', 3);

    // Le tour du héros a été consommé : on le rend pour rejouer de l'autre main.
    $ctx['etatHeros']->fresh()->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 40, 4));

    // MÊME option `attaquer` : c'est `parametres.cle` qui choisit la main
    // gauche, plus un second identifiant d'option.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cle' => 'arme:arme_secondaire', 'cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.des_attaque_effectifs', 1);
});

it('LANCE l\'arme de la main gauche, et c\'est celle-là qui se perd', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $equipement = app(Equipement::class);

    $equipement->equiper($heros, auSacDe($heros, 'Épée large'));
    $dague = $equipement->equiper($heros, auSacDe($heros, 'Dague'), 'arme_secondaire');

    // Le gobelin s'éloigne : hors contact, la dague ne peut plus qu'être lancée.
    $ctx['instance']->update([
        'position_x' => (int) $ctx['etatHeros']->position_x,
        'position_y' => (int) $ctx['etatHeros']->position_y + 2,
    ]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];
    $options = collect($menu['options']);

    // Sous-choix : `lancer` reste une option À PART (l'arme est DÉTRUITE),
    // mais UNE SEULE — jamais `lancer_secondaire`. Une seule arme jetable ici
    // (l'épée large ne se lance pas) : pas d'ambiguïté, donc la forme HISTORIQUE
    // à plat (`parametres.arme` + `cibles`), pas de liste `armes[]` à choisir.
    expect($options->pluck('id'))->not->toContain('lancer_secondaire');
    $lancer = $options->firstWhere('id', 'lancer');
    expect($lancer)->not->toBeNull()
        ->and($lancer['parametres']['arme'])->toBe('arme_secondaire')
        ->and($lancer['libelle'])->toBe('Lancer Dague (perdue)');

    desFiges(array_fill(0, 40, 4));
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.lancer.arme', 'Dague');

    // ⚠ La dague est perdue, l'épée reste en main droite.
    expect(Inventaire::find($dague->id))->toBeNull()
        ->and($heros->fresh()->inventaire()->where('emplacement', 'arme_principale')->exists())->toBeTrue()
        ->and((int) $heros->fresh()->des_attaque)->toBe(3);
});

it('ne considère plus le Moine à MAINS NUES dès qu\'il tient une arme à gauche', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $heros = $ctx['heros'];

    // Rien en main : ses techniques à mains nues sont offertes.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];

    expect(collect($menu['options'])->pluck('id'))->toContain('style_poing');

    // Une dague en main GAUCHE : il n'est plus à mains nues.
    app(Equipement::class)->equiper($heros, auSacDe($heros, 'Dague'), 'arme_secondaire');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];

    expect(collect($menu['options'])->pluck('id'))->not->toContain('style_poing');
});

it('permet enfin à l\'AMBIDEXTRIE du Rogue de frapper de sa seconde arme', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'rogue']);
    $heros = $ctx['heros'];
    $equipement = app(Equipement::class);

    $equipement->equiper($heros, auSacDe($heros, 'Épée courte'));
    $equipement->equiper($heros, auSacDe($heros, 'Dague'), 'arme_secondaire');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 40, 4));

    // « when you attack with a shortsword or dagger you may make one additional
    // attack with a dagger » : la première frappe ouvre la seconde…
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cle' => 'arme:arme_principale', 'cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.ambidextrie', true);

    // …et la manette peut désormais la porter avec la DAGUE, littéralement —
    // TOUJOURS la même option `attaquer`, l'entrée main gauche reste dans sa
    // liste.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];
    $option = collect($menu['options'])->firstWhere('id', 'attaquer');

    expect($option)->not->toBeNull()
        ->and(collect($option['parametres']['armes'])->pluck('slot'))->toContain('arme_secondaire');

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cle' => 'arme:arme_secondaire', 'cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.des_attaque_effectifs', 1); // les dés de la dague
});
