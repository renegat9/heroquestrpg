<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\MoteurPotions;
use App\Partie\MoteurSorts;
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
use Illuminate\Support\Facades\Http;

/*
 * BUFFS « TANT QU'UN MONSTRE EST EN VUE » — AU MOMENT, PAS AU TOUR SUIVANT (2026-10-17).
 *
 * Courage (reference/16 §3bis) : « The spell is broken THE MOMENT a monster is no
 * longer in the hero's line of sight. » Les potions du Barbare disent « as soon as ».
 * Le déclencheur `plus_de_monstre_en_vue` existait, mais n'était évalué qu'au DÉBUT DU
 * TOUR du porteur : un compagnon qui gardait Courage le gardait pendant tout le round
 * suivant, alors que le gobelin était mort depuis le premier coup.
 *
 * Fixture : une carte 7×7 entièrement en sol (la vue est claire, sans porte ni mur),
 * Albrecht au contact d'un gobelin (Body 1), Brunhilde plus loin mais qui le VOIT.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : les parchemins dérivent des sorts.
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/**
 * @return array{alice: \App\Auth\JoueurAuthentifiable, groupe: \App\Models\Groupe, albrecht: \App\Models\Personnage,
 *     brunhilde: \App\Models\Personnage, quete: Quete, etatAlbrecht: EtatPersonnageQuete,
 *     etatBrunhilde: EtatPersonnageQuete, gobelin: InstanceMonstre}
 */
function queteDeVueMinimale(array $albrechtAttrs = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, $albrechtAttrs);

    $bob = \App\Auth\JoueurAuthentifiable::create([
        'pseudo' => 'brunhilde', 'identifiant' => 'brunhilde', 'mot_de_passe' => 'secret',
    ]);
    $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $taille = 7;
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de vue', 'position_arc' => 1, 'type_jalon' => 'normale',
        'etat' => 'en_cours', 'or_initial' => 0,
    ]);
    Carte::create([
        'quete_id' => $quete->id, 'largeur' => $taille, 'hauteur' => $taille,
        'grille' => [
            'largeur' => $taille, 'hauteur' => $taille,
            'cases' => array_fill(0, $taille, array_fill(0, $taille, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $taille, 'hauteur' => $taille,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'mobilier' => [],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);
    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    $etatAlbrecht = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $albrecht->id, 'position_x' => 3, 'position_y' => 3,
    ]);
    // (4,1) : Brunhilde ne touche pas le gobelin, et voit (4,3) par (4,2), libre.
    $etatBrunhilde = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $brunhilde->id, 'position_x' => 4, 'position_y' => 1,
    ]);

    $gobelin = InstanceMonstre::create([
        'quete_id' => $quete->id, 'monstre_id' => Monstre::where('nom_base', 'Gobelin')->value('id'),
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 1, 'position_x' => 4, 'position_y' => 3,
        'etat' => 'actif', 'elite' => false, 'revele' => true,
    ]);

    return compact('alice', 'groupe', 'albrecht', 'brunhilde', 'quete', 'etatAlbrecht', 'etatBrunhilde', 'gobelin');
}

/** Albrecht frappe le gobelin (le menu d'abord, comme la manette). */
function albrechtFrappe(array $f): array
{
    GenererMenu::dispatchSync($f['groupe']->id, (int) $f['alice']->id, (int) $f['albrecht']->id);

    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $f['gobelin']->id],
    ])->assertStatus(202)->json('resultat');
}

it('Courage tombe AU MOMENT où le dernier monstre en vue meurt — même porté par un COMPAGNON qui a DÉJÀ JOUÉ', function () {
    // Le défaut réel : `rythmerBuffsDeVue()` ne faisait tomber un buff de vue qu'au
    // tour d'un héros qui n'avait encore rien joué (garde d'idempotence du réarmement
    // de Rage, appliquée aussi à la chute). Brunhilde a fini son tour AVANT qu'Albrecht
    // n'abatte le gobelin : son Courage restait jusqu'au round suivant.
    $f = queteDeVueMinimale(['classe' => 'barbare']);
    app(MoteurSorts::class)->appliquerBuff($f['brunhilde'], Sort::where('nom', 'Courage')->firstOrFail());
    EtatPersonnageQuete::whereKey($f['etatBrunhilde']->id)->update(['a_joue' => true]);

    expect($f['brunhilde']->conditions()->where('nom', 'Renforcé')->exists())
        ->toBeTrue('précondition : Courage est posé sur Brunhilde');
    expect(app(MoteurSorts::class)->monstreEnVue($f['quete']->fresh(), $f['etatBrunhilde']->fresh()))
        ->toBeTrue('précondition : le gobelin est VU de Brunhilde');

    desFiges(array_fill(0, 40, 1)); // crânes : le coup d'Albrecht touche, le gobelin (Body 1) tombe
    $resultat = albrechtFrappe($f);

    expect($f['gobelin']->fresh()->etat)->toBe('vaincu', 'précondition : le gobelin est mort');

    // Le buff de Brunhilde n'attend ni son tour ni le round suivant : il tombe dans la résolution même.
    expect($f['brunhilde']->conditions()->where('nom', 'Renforcé')->exists())->toBeFalse();

    $tombees = collect($resultat['annonces_automatiques'] ?? [])
        ->where('type', 'condition_terminee')->where('condition', 'Renforcé');

    expect($tombees)->toHaveCount(1)
        ->and($tombees->first()['personnage'])->toBe('Brunhilde')
        ->and($tombees->first()['pourquoi'])->toContain('plus aucun monstre en vue');
});

it('la Potion de rage tombe avec le dernier monstre en vue, et EMPORTE la seconde frappe qu\'elle armait', function () {
    $f = queteDeVueMinimale(['classe' => 'barbare']);
    $ligne = $f['albrecht']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Potion de rage guerrière')->firstOrFail()->id, 'emplacement' => 'sac',
    ]);
    app(MoteurPotions::class)->boire($f['albrecht'], $ligne->fresh());

    expect((bool) $f['etatAlbrecht']->fresh()->attaque_supplementaire)
        ->toBeTrue('précondition : la rage arme la seconde frappe');

    desFiges(array_fill(0, 40, 1));
    $resultat = albrechtFrappe($f);

    expect($f['gobelin']->fresh()->etat)->toBe('vaincu', 'précondition : le gobelin est mort');
    expect($f['albrecht']->conditions()->where('nom', 'Renforcé')->exists())->toBeFalse();

    // Sans monstre en vue, une seconde frappe n'a plus de cible : le drapeau retombe avec la potion.
    expect((bool) $f['etatAlbrecht']->fresh()->attaque_supplementaire)->toBeFalse();

    $tombees = collect($resultat['annonces_automatiques'] ?? [])
        ->where('type', 'condition_terminee')->where('personnage', 'Albrecht');
    expect($tombees)->toHaveCount(1);
});
