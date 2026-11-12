<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Mercenaire;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\ManoeuvreSolo;
use App\Partie\Arene\PuissanceArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/**
 * MODE ARÈNE, lot A4 — les ALLIÉS du Challenger (livret F9528 p. 12 : « Heroes and allies make up the Challenger
 * team ») : un allié gratuit par combattant, choisi au salon, compté dans la puissance, posé sur la tuile du
 * Challenger, joué par son joueur à sa propre activation — et le Defender lui répond.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
        MercenaireSeeder::class,
    ]);
});

/** Un salon : Alice et son héros d'arène engagé ; renvoie [joueur, doublure, code]. */
function salonAlliesAlice(): array
{
    $alice = connecterJoueur('alice');
    $id = test()->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->assertCreated()->json('personnage.id');
    $code = test()->postJson('/api/arene', ['personnage_id' => $id])->assertCreated()->json('groupe.identifiant');
    $doublure = Personnage::where('doublure_de', $id)->firstOrFail();

    return [$alice, $doublure, $code];
}

function allieAreneNomme(string $nom): Mercenaire
{
    return Mercenaire::where('nom', $nom)->firstOrFail();
}

it('le salon publie les alliés engageables, sans les octrois ni les captifs', function () {
    [, , $code] = salonAlliesAlice();

    $allies = collect($this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene.allies'));

    expect($allies->pluck('nom')->all())->toContain('Éclaireur', 'Ogre mercenaire', 'Loup')
        ->not->toContain('Squelette Hearthkin', 'Gothar')
        ->and($allies->firstWhere('nom', 'Arbalétrier')['puissance'])->toBe(4);
});

it('choisir un allié l\'ajoute à la puissance du Challenger et fait retomber le « prêt »', function () {
    [$alice, $doublure, $code] = salonAlliesAlice();
    $arenes = app(GroupesArene::class);
    $avant = app(PuissanceArene::class)->duChallenger($doublure->groupeActif ?? App\Models\Groupe::where('identifiant', $code)->firstOrFail());
    $this->postJson("/api/arene/{$code}/pret", ['pret' => true])->assertOk();

    $ogre = allieAreneNomme('Ogre mercenaire');
    $salon = $this->putJson("/api/arene/{$code}/allie", ['mercenaire_id' => $ogre->id])->assertOk()->json('groupe.arene');

    expect($salon['puissance_challenger'])->toBe($avant + 5)
        ->and($salon['combattants'][0]['allie'])->toBe(['mercenaire_id' => $ogre->id, 'nom' => 'Ogre mercenaire', 'puissance' => 5])
        ->and($salon['combattants'][0]['pret'])->toBeFalse();

    // Retirer l'allié rend la puissance d'avant.
    $salon = $this->putJson("/api/arene/{$code}/allie", ['mercenaire_id' => null])->assertOk()->json('groupe.arene');
    expect($salon['puissance_challenger'])->toBe($avant)->and($salon['combattants'][0]['allie'])->toBeNull();
});

it('refuse un allié hors liste blanche', function () {
    [, , $code] = salonAlliesAlice();

    $this->putJson("/api/arene/{$code}/allie", ['mercenaire_id' => allieAreneNomme('Squelette Hearthkin')->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['mercenaire_id']);
});

it('au lancement, l\'allié naît sur la tuile du Challenger, contrôlé par le combattant de son joueur', function () {
    [, $doublure, $code] = salonAlliesAlice();
    $loup = allieAreneNomme('Loup');
    $this->putJson("/api/arene/{$code}/allie", ['mercenaire_id' => $loup->id])->assertOk();
    $arene = App\Models\Groupe::where('identifiant', $code)->firstOrFail();

    $quete = app(DemarreurArene::class)->demarrer($arene->refresh(), [(int) Monstre::where('nom_base', 'Gobelin')->value('id')]);

    $allie = GroupeMercenaire::where('groupe_id', $arene->id)->firstOrFail();
    $tuile = collect(app(App\Partie\Arene\HallArene::class)->tuileDeDepart(ouest: true))->map(fn ($c) => "{$c['x']},{$c['y']}");
    expect($allie->mercenaire_id)->toBe($loup->id)
        ->and($allie->recruteur_personnage_id)->toBe($doublure->id)
        ->and($allie->pv_body)->toBe((int) $loup->pv_body)
        ->and($tuile->all())->toContain("{$allie->position_x},{$allie->position_y}");

    $hero = EtatPersonnageQuete::where('quete_id', $quete->id)->firstOrFail();
    expect([$hero->position_x, $hero->position_y])->not->toBe([$allie->position_x, $allie->position_y]);
});

it('l\'allié a sa propre activation : après le héros, le joueur le joue, et le Defender lui répond', function () {
    [$alice, $doublure, $code] = salonAlliesAlice();
    $this->putJson("/api/arene/{$code}/allie", ['mercenaire_id' => allieAreneNomme('Éclaireur')->id])->assertOk();
    $arene = App\Models\Groupe::where('identifiant', $code)->firstOrFail();
    $gobelin = (int) Monstre::where('nom_base', 'Gobelin')->value('id');
    $quete = app(DemarreurArene::class)->demarrer($arene->refresh(), [$gobelin, $gobelin, $gobelin]);
    $jetons = fn () => InstanceMonstre::where('quete_id', $quete->id)->where('active_ce_round', true)->count();
    expect($jetons())->toBe(1);

    // Le héros joue : un gobelin répond. Puis la manette d'Alice sert le menu de son ALLIÉ.
    jouerEnArene($alice, $code, 'attendre')->assertSuccessful();
    expect($jetons())->toBe(2);

    $this->actingAs($alice, 'joueur');
    $menu = $this->getJson("/api/groupes/{$code}/menu")->assertOk()->json();
    $allie = GroupeMercenaire::where('groupe_id', $arene->id)->firstOrFail();
    expect($menu['allie_id'] ?? null)->toBe($allie->id);

    $attente = collect($menu['menu']['options'])->firstWhere('type', 'attente_allie');
    expect($attente)->not->toBeNull();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => $attente['id']])->assertSuccessful();

    // Son activation finie, le Defender répond (encore un jeton) — puis le Challenger est à court : le Defender
    // enchaîne et rouvre le round.
    expect($allie->refresh()->a_joue)->toBeFalse()
        ->and($jetons())->toBe(1);
});

it('un héros qui a encore un allié debout n\'est pas un combattant seul', function () {
    [, $doublure, $code] = salonAlliesAlice();
    $this->putJson("/api/arene/{$code}/allie", ['mercenaire_id' => allieAreneNomme('Éclaireur')->id])->assertOk();
    $arene = App\Models\Groupe::where('identifiant', $code)->firstOrFail();
    $quete = app(DemarreurArene::class)->demarrer($arene->refresh(), [(int) Monstre::where('nom_base', 'Gobelin')->value('id')]);

    $manoeuvres = app(ManoeuvreSolo::class);
    expect($manoeuvres->challengerSolo($arene->refresh(), $quete))->toBeNull();

    GroupeMercenaire::where('groupe_id', $arene->id)->update(['etat' => 'vaincu']);
    expect($manoeuvres->challengerSolo($arene->refresh(), $quete)?->personnage_id)->toBe($doublure->id);
});

it('la revanche garde l\'allié choisi et recrée sa figure', function () {
    [, , $code] = salonAlliesAlice();
    $ogre = allieAreneNomme('Ogre mercenaire');
    $this->putJson("/api/arene/{$code}/allie", ['mercenaire_id' => $ogre->id])->assertOk();
    $arene = App\Models\Groupe::where('identifiant', $code)->firstOrFail();
    $quete = app(DemarreurArene::class)->demarrer($arene->refresh(), [(int) Monstre::where('nom_base', 'Gobelin')->value('id')]);
    $quete->update(['etat' => 'terminee']);
    $arene->update(['phase' => 'hub', 'quete_courante_id' => null]);

    app(GroupesArene::class)->rejouer($arene->refresh());

    expect(GroupeMercenaire::where('groupe_id', $arene->id)->count())->toBe(0)
        ->and($this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene.combattants.0.allie.mercenaire_id'))->toBe($ogre->id);
});
