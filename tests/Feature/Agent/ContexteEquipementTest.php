<?php

declare(strict_types=1);

use App\Agent\Memoire\ContexteAssembleur;
use App\Models\Inventaire;
use App\Models\Objet;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Contexte de narration (correctifs §5) : le MJ IA reçoit l'équipement RÉEL des
 * héros — il ne doit plus décrire d'objet inventé (« sa hache » sac vide). La
 * bible Qdrant est indisponible ici (Http::fake) : l'assembleur dégrade
 * proprement (extraits vides) et l'état vivant reste complet.
 */

beforeEach(function () {
    Http::fake();
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, SortSeeder::class]);
});

it("expose l'équipement porté et le sac réels des héros dans l'état vivant", function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'barbare']);

    // Une arme équipée (dans son emplacement naturel) + un objet au sac.
    $arme = Objet::where('emplacement', 'arme_principale')->firstOrFail();
    $auSac = Objet::where('emplacement', 'sac')->first() ?? Objet::where('id', '!=', $arme->id)->firstOrFail();

    Inventaire::create(['personnage_id' => $hero->id, 'objet_id' => $arme->id, 'emplacement' => 'arme_principale', 'quantite' => 1]);
    Inventaire::create(['personnage_id' => $hero->id, 'objet_id' => $auSac->id, 'emplacement' => 'sac', 'quantite' => 1]);

    $contexte = app(ContexteAssembleur::class)->assembler($groupe->fresh());
    $equip = $contexte['etat_vivant']['heros'][0]['equipement'];

    expect($equip['porte']['arme_principale'])->toBe($arme->nom)
        ->and($equip['sac'])->toContain($auSac->nom);
});

it("décrit un héros les mains vides quand il ne porte rien (aucun objet inventé)", function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Nu', 1, ['classe' => 'magicien']); // sans inventaire

    $contexte = app(ContexteAssembleur::class)->assembler($groupe->fresh());
    $equip = $contexte['etat_vivant']['heros'][0]['equipement'];

    expect($equip['porte'])->toBe([])
        ->and($equip['sac'])->toBe([]);
});
