<?php

declare(strict_types=1);

use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Sort;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Les parchemins au sac (René, 2026-11-06 : « le nain a gagné un parchemin mais il ne le voit pas dans son sac »). Ni
 * rangés au sac (`emplacement: consommable`) ni potions (`categorie: parchemin`), ils ne paraissaient qu'au menu.
 */

beforeEach(function () {
    Http::fake();
    $this->seed([ClasseHerosSeeder::class, SortSeeder::class, ObjetSeeder::class]);
});

function donnerParchemin(App\Models\Personnage $heros, string $sort): Inventaire
{
    $objet = Objet::where('categorie', 'parchemin')->get()
        ->first(fn (Objet $o) => (int) data_get($o->effet, 'sort_id') === (int) Sort::where('nom', $sort)->value('id'));

    return Inventaire::create(['personnage_id' => $heros->id, 'objet_id' => $objet->id, 'emplacement' => 'consommable', 'quantite' => 1]);
}

it('publie les parchemins du héros sur /moi, avec ce qu\'en coûte la lecture à un non-lanceur', function () {
    $joueur = connecterJoueur('rene');
    $nain = creerHeros($joueur, creerGroupe(), 'petite barbe', 1, ['classe' => 'nain', 'attribut_mind' => 2]);
    $ligne = donnerParchemin($nain, 'Luciole');

    $perso = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->firstWhere('id', $nain->id);

    expect($perso['parchemins'])->toHaveCount(1)
        ->and($perso['parchemins'][0]['inventaire_id'])->toBe($ligne->id)
        ->and($perso['parchemins'][0]['nom'])->toBe('Parchemin : Luciole')
        ->and($perso['parchemins'][0]['lecture'])
        ->toBe('Se lit en quête, pendant ton tour : Actions → « Lire un parchemin » — jet de Mind : 2 crânes sur 2 dés, perdu si raté')
        ->and($perso['parchemins'][0]['avantages'])->not->toBeEmpty()
        // Ni potion, ni pièce du sac : une seule liste le montre.
        ->and(collect($perso['consommables'])->pluck('inventaire_id'))->not->toContain($ligne->id);
});

it('dit qu\'un lanceur de sorts lit sans jet — la difficulté est celle que le résolveur lit', function () {
    $joueur = connecterJoueur('rene');
    $magicien = creerHeros($joueur, creerGroupe(), 'Panoramix', 1, ['classe' => 'magicien']);
    donnerParchemin($magicien, 'Luciole');

    $perso = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->firstWhere('id', $magicien->id);

    expect($perso['parchemins'][0]['lecture'])->toEndWith('— sans jet, tu lances des sorts')
        ->and(App\Partie\MoteurSorts::difficulteLecture($magicien, Sort::where('nom', 'Luciole')->first()))->toBeNull();
});
