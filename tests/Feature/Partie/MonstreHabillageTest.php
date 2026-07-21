<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Habillage IA des monstres (Q6) : le MJ renomme un monstre du catalogue (mêmes
 * stats). L'état ET le menu exposent alors le TYPE DE BASE à côté du nom d'habillage,
 * pour relier le monstre à sa fiche du guide (« Écumeur des cryptes (Gobelin) »).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('expose le nom d\'habillage ET le type de base (nom_base) dans l\'état partagé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $proie->update(['habillage' => ['nom' => 'Écumeur des cryptes', 'description' => 'Une ombre rôdeuse des tunnels.']]);

    $entite = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites'))
        ->first(fn ($e) => $e['type'] === 'monstre' && $e['id'] === $proie->id);

    expect($entite)->not->toBeNull()
        ->and($entite['nom'])->toBe('Écumeur des cryptes')       // habillage IA
        ->and($entite['nom_base'])->toBe($proie->monstre->nom_base); // type du catalogue (guide)
});

it('rappelle le type de base dans l\'option d\'attaque quand le monstre est renommé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $proie->update(['position_x' => $cible['x'], 'position_y' => $cible['y'],
        'habillage' => ['nom' => 'Écumeur des cryptes', 'description' => 'Une ombre rôdeuse.']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $option = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])
        ->firstWhere('id', "attaquer_{$proie->id}");

    expect($option)->not->toBeNull()
        ->and($option['libelle'])->toBe("Attaquer Écumeur des cryptes ({$proie->monstre->nom_base})");
});

it('n\'ajoute PAS de type entre parenthèses pour un monstre non renommé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $proie->update(['position_x' => $cible['x'], 'position_y' => $cible['y'], 'habillage' => null]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $option = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])
        ->firstWhere('id', "attaquer_{$proie->id}");

    expect($option['libelle'])->toBe("Attaquer {$proie->monstre->nom_base}");
});
