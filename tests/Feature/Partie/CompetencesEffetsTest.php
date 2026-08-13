<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Marche\CapaciteSac;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Couvre les nœuds d'arbre (doc 01 §6) dont l'effet est appliqué par du code
 * générique (CompetenceController::EFFETS_PASSIFS / CapaciteSac) mais qui
 * n'avaient pas encore de test dédié à leur mécanique de gameplay précise :
 * Solides épaules (Nain, capacité de sac), Pas léger (Elfe, déplacement),
 * Frénésie / Coup puissant (Barbare, combat) et Garde tenace (Nain, défense).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class]);
});

function idNoeudCompetence(string $classe, string $nom): int
{
    return (int) Competence::where('classe', $classe)->where('nom', $nom)->value('id');
}

it('Solides épaules (+2 capacité de sac) est dérivé sans colonne dédiée', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain', 'niveau' => 2, 'pv_body_max' => 7]);

    // Nain : PV Body max 7 ÷ 2 = 3, + bonus_sac racial (1) = 4 avant le nœud.
    expect(CapaciteSac::pour($hero))->toBe(4);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id,
        'competence_id' => idNoeudCompetence('nain', 'Solides épaules'),
    ])->assertCreated();

    // +2 du nœud, dérivé à chaque calcul — aucune colonne `personnages` modifiée.
    expect(CapaciteSac::pour($hero->fresh()))->toBe(6);
});

it('Pas léger (+1 déplacement) augmente l\'allonce exposée au menu de tour', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Aëlis', 1, ['classe' => 'elfe', 'niveau' => 2, 'deplacement_base' => 5]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id,
        'competence_id' => idNoeudCompetence('elfe', 'Pas léger'),
    ])->assertCreated();

    // Passif chiffré générique (EFFETS_PASSIFS) : colonne `deplacement_base` incrémentée directement.
    expect((int) $hero->fresh()->deplacement_base)->toBe(6);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    // Force un nouveau tour « vierge » pour que le d6 d'allonce soit relancé.
    $etat->update(['deplacement_tour' => null, 'a_joue' => false]);
    desFiges([3]); // d6 figé à 3
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $dep = collect($menu['options'])->firstWhere('type', 'deplacement');

    expect($dep['parametres']['base'])->toBe(6)
        ->and($dep['parametres']['de'])->toBe(3)
        ->and($dep['parametres']['portee'])->toBe(9); // 6 + 1d6(3)
});

it('Frénésie (+1 dé d\'attaque sous la moitié des PV de Body) s\'applique au combat', function () {
    $ctx = demarrerQueteAvecMonstre('Champion', [
        'classe' => 'barbare', 'niveau' => 2, 'des_attaque' => 2, 'pv_body_max' => 8,
    ]);

    // Le démarrage de quête soigne intégralement (P2, doc 01 §13) : on blesse
    // le héros APRÈS, pour simuler un combat déjà entamé.
    $ctx['heros']->update(['pv_body' => 3]); // 3*2=6 < 8 : sous la moitié

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $ctx['heros']->id,
        'competence_id' => idNoeudCompetence('barbare', 'Frénésie'),
    ])->assertCreated();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4)); // boucliers blancs partout : combat neutre, aucune complication

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.bonus_frenesie', 1)
        ->assertJsonPath('resultat.des_attaque_effectifs', 3); // 2 base + 1 Frénésie
});

it('Frénésie ne s\'applique PAS au-dessus de la moitié des PV de Body', function () {
    $ctx = demarrerQueteAvecMonstre('Champion', [
        'classe' => 'barbare', 'niveau' => 2, 'des_attaque' => 2, 'pv_body_max' => 8,
    ]);

    $ctx['heros']->update(['pv_body' => 4]); // 4*2=8, pas STRICTEMENT sous la moitié

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $ctx['heros']->id,
        'competence_id' => idNoeudCompetence('barbare', 'Frénésie'),
    ])->assertCreated();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4));

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.bonus_frenesie', 0)
        ->assertJsonPath('resultat.des_attaque_effectifs', 2);
});

it('Coup puissant relance une fois les dés d\'attaque ratés', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'niveau' => 2, 'des_attaque' => 1]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $ctx['heros']->id,
        'competence_id' => idNoeudCompetence('barbare', 'Coup puissant'),
    ])->assertCreated();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    // 1 dé d'attaque : blanc (raté) → relancé en crâne. Défense du Gobelin (1 dé) : blanc, ignoré.
    desFiges([4, 1, 4]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.touches', 1)
        ->assertJsonPath('resultat.degats', 1)
        ->assertJsonPath('resultat.faces_attaque.0', 'crane');
});

it('Garde tenace ajoute +1 dé de défense à la première attaque subie de la quête', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain', 'niveau' => 2, 'des_defense' => 2]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $ctx['heros']->id,
        'competence_id' => idNoeudCompetence('nain', 'Garde tenace'),
    ])->assertCreated();

    expect($ctx['etatHeros']->fresh()->garde_tenace_utilisee)->toBeFalse();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4)); // boucliers partout : combat neutre

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'attaque_monstre');

    expect($attaque['bonus_garde_tenace'])->toBe(1)
        ->and($ctx['etatHeros']->fresh()->garde_tenace_utilisee)->toBeTrue();
});

it('Garde tenace ne s\'applique plus après la première attaque de la quête', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain', 'niveau' => 2, 'des_defense' => 2]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $ctx['heros']->id,
        'competence_id' => idNoeudCompetence('nain', 'Garde tenace'),
    ])->assertCreated();

    // Déjà consommé plus tôt dans la quête (simulation d'une attaque précédente).
    $ctx['etatHeros']->update(['garde_tenace_utilisee' => true]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4));

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'attaque_monstre');

    expect($attaque['bonus_garde_tenace'])->toBe(0);
});

it('Sens aiguisés (+1 dé de Mind) s\'applique au jet « Fouiller » (contexte perception)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe', 'niveau' => 2, 'attribut_mind' => 3]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $ctx['heros']->id,
        'competence_id' => idNoeudCompetence('elfe', 'Sens aiguisés'),
    ])->assertCreated();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 10, 4));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.bonus_avantage_mind', 1)
        ->assertJsonPath('resultat.des_lances', 4); // 3 attribut + 1 avantage
});

it('Intimidation (+1 dé de Mind, contexte social_peur) s\'applique à un jet dédié', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'niveau' => 2, 'attribut_mind' => 1]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $ctx['heros']->id,
        'competence_id' => idNoeudCompetence('barbare', 'Intimidation'),
    ])->assertCreated();

    Cache::put(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id), [
        'personnage_id' => $ctx['heros']->id,
        'menu' => ['options' => [[
            'id' => 'intimider', 'libelle' => 'Intimider le sbire — jet de Mind', 'type' => 'jet',
            'jet' => ['attribut' => 'mind', 'difficulte' => 1, 'contexte' => 'social_peur'],
        ]]],
    ], now()->addMinutes(60));

    desFiges(array_fill(0, 10, 4));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'intimider'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.bonus_avantage_mind', 1)
        ->assertJsonPath('resultat.des_lances', 2); // 1 attribut + 1 avantage
});

it('Érudition (+1 dé de Mind, contexte savoir) s\'applique à un jet dédié', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien', 'niveau' => 2, 'attribut_mind' => 4]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $ctx['heros']->id,
        'competence_id' => idNoeudCompetence('magicien', 'Érudition'),
    ])->assertCreated();

    Cache::put(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id), [
        'personnage_id' => $ctx['heros']->id,
        'menu' => ['options' => [[
            'id' => 'dechiffrer', 'libelle' => 'Déchiffrer la rune — jet de Mind', 'type' => 'jet',
            'jet' => ['attribut' => 'mind', 'difficulte' => 1, 'contexte' => 'savoir'],
        ]]],
    ], now()->addMinutes(60));

    desFiges(array_fill(0, 10, 4));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'dechiffrer'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.bonus_avantage_mind', 1)
        ->assertJsonPath('resultat.des_lances', 5); // 4 attribut + 1 avantage
});

it('un jet de Mind sans nœud correspondant au contexte ne reçoit aucun avantage', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'niveau' => 1, 'attribut_mind' => 1]);

    Cache::put(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id), [
        'personnage_id' => $ctx['heros']->id,
        'menu' => ['options' => [[
            'id' => 'intimider', 'libelle' => 'Intimider le sbire — jet de Mind', 'type' => 'jet',
            'jet' => ['attribut' => 'mind', 'difficulte' => 1, 'contexte' => 'social_peur'],
        ]]],
    ], now()->addMinutes(60));

    desFiges(array_fill(0, 10, 4));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'intimider'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.bonus_avantage_mind', 0)
        ->assertJsonPath('resultat.des_lances', 1);
});

it('garde le bonus PERMANENT d\'un nœud quand on équipe quoi que ce soit', function () {
    // ⚠ Aucun nœud du catalogue n'est aujourd'hui dans ce cas — les neuf nœuds à
    // dés portent tous une `condition`. Le test fabrique donc le premier nœud
    // permanent à dés, précisément pour que la trappe reste fermée le jour où le
    // seeder en sèmera un : `recalculerCombat` ÉCRASE la colonne, et tant qu'il
    // ignorait l'arbre, le premier « équiper » venu effaçait le bonus en silence.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'barbare']);

    $noeud = Competence::create([
        'classe' => 'barbare', 'nom' => 'Bras d\'airain', 'type' => 'passif',
        'description' => "+1 dé d'attaque, toujours.",
        'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1],
    ]);
    $heros->update(['niveau' => 2]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $heros->id, 'competence_id' => $noeud->id,
    ])->assertCreated();

    // Le nœud est visible tout de suite : 1 dé de classe + 1.
    expect((int) $heros->fresh()->des_attaque)->toBe(2);

    // …et il SURVIT à l'équipement, qui reconstruit pourtant la colonne.
    $epee = Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Épée large')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'sac',
    ]);
    app(Equipement::class)->equiper($heros->fresh(), $epee);

    // L'arme REMPLACE les dés de classe (3), le nœud s'y AJOUTE (+1).
    expect((int) $heros->fresh()->des_attaque)->toBe(4);

    // …et au déséquipement on revient à la classe + le nœud, jamais à la classe
    // seule (c'est là que le bonus disparaissait).
    app(Equipement::class)->desequiper($heros->fresh(), $epee->fresh());

    expect((int) $heros->fresh()->des_attaque)->toBe(2);
});

it('ne compte JAMAIS un passif conditionnel dans la colonne', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Krogar', 1, ['classe' => 'nain']);
    $heros->update(['niveau' => 2]);

    // Garde tenace : « +1 dé de défense à la PREMIÈRE attaque du combat » — lu
    // en situation par ResolveurTour. Dans la colonne, il vaudrait pour toutes.
    $noeud = Competence::where('classe', 'nain')->where('nom', 'Garde tenace')->firstOrFail();
    $defenseAvant = (int) $heros->des_defense;

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $heros->id, 'competence_id' => $noeud->id,
    ])->assertCreated();

    expect((int) $heros->fresh()->des_defense)->toBe($defenseAvant);

    // Et un recalcul complet ne le fait pas apparaître non plus.
    app(Equipement::class)->recalculerCombat($heros->fresh());

    expect((int) $heros->fresh()->des_defense)->toBe($defenseAvant);
});
