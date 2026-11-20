<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\DemarreurQuete;
use App\Partie\MoteurPotions;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * APRÈS LA MORT, ET LES POTIONS QUI NE FERAIENT RIEN (René, 2026-11-19, au retour du test de campagne à quatre héros) :
 * 1. « Respecter les règles de Delthrak selon le mode de jeu choisi » — F9907 p. 9 renvoie au livret de règles : « You
 *    may rename your hero and play them as a new character in the next quest » ; une quête perdue se rejoue.
 * 2. « Si le héros est mort, la figurine est enlevée » — un héros à terre est une tuile d'équipement : il ne bloque pas
 *    sa case et, relevé, prend la plus proche si elle est prise (F9907 p. 8).
 * 4. « Ne pas permettre de prendre une potion de soin, d'anti-venin ou tout autre effet positif si celui-ci ne fera pas
 *    effet. »
 * Par les VRAIES routes. Campagne `DocumentExemple`, INVENTÉE ; ses monstres sont mis hors de combat d'emblée.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, ConditionSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

/** Albrecht (alice) et Brunehaut (bruno), en quête 1, côte à côte en (2,4) et (3,4) ; c'est le tour d'Albrecht. */
function partieApresMort(object $test, string $modeMort = 'story'): array
{
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    $alice = connecterJoueur('alice');
    $bruno = connecterJoueur('bruno');
    $fiche = fn ($joueur, string $nom, string $classe) => Personnage::create([
        'joueur_id' => $joueur->id, 'nom' => $nom, 'classe' => $classe, 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $albrecht = $fiche($alice, 'Albrecht', 'barbare');
    $brunehaut = $fiche($bruno, 'Brunehaut', 'nain');
    $test->actingAs($alice, 'joueur');
    $code = $test->postJson('/api/classique', [
        'nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $albrecht->id, 'mode_mort' => $modeMort,
    ])->assertCreated()->json('groupe.identifiant');
    $test->actingAs($bruno, 'joueur')->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $brunehaut->id])->assertSuccessful();
    $test->actingAs($alice, 'joueur');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    $quete->etatsPersonnages()->where('personnage_id', $albrecht->id)->update(['position_x' => 2, 'position_y' => 4]);
    $quete->etatsPersonnages()->where('personnage_id', $brunehaut->id)->update(['position_x' => 3, 'position_y' => 4]);

    return compact('code', 'groupe', 'quete', 'alice', 'bruno', 'albrecht', 'brunehaut');
}

/** Les entrées d'« Utiliser un objet » du menu d'Albrecht, par nom. */
function objetsDuMenu(object $test, Groupe $groupe, string $code, Personnage $heros): \Illuminate\Support\Collection
{
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $option = collect($test->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'))
        ->firstWhere('id', 'utiliser_objet');

    return collect($option['parametres']['objets'] ?? [])->keyBy('nom');
}

function donner(Personnage $heros, string $objet): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => Objet::where('nom', $objet)->value('id'), 'emplacement' => 'sac', 'quantite' => 1,
    ]);
}

it('STORY, après un TPK : le groupe reste au même rang, le départ dit comment revenir, et le nouveau héros rejoint par le code', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'bruno' => $bruno, 'brunehaut' => $brunehaut] = partieApresMort($this);
    $quete->etatsPersonnages()->update(['tombe' => true]);

    expect(app(ResolveurTour::class)->verdictDeChute($groupe->refresh(), $quete->refresh()))->toBe(ResolveurTour::CHUTE_TPK);
    expect($groupe->refresh()->phase)->toBe('hub')->and((int) $groupe->rang_quete)->toBe(1);

    $motif = app(DemarreurQuete::class)->refusDeDepart($groupe);
    expect($motif)->toContain('Tous les héros sont morts')->toContain($code)->toContain('sera rejouée');

    // Le joueur ne lit plus le groupe — et /moi lui dit où son héros est mort.
    $this->actingAs($bruno, 'joueur');
    $this->getJson("/api/groupes/{$code}/etat")->assertForbidden();
    $mort = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->firstWhere('id', $brunehaut->id);
    expect($mort['mort'])->toBeTrue()->and($mort['mort_dans'])->toBe(['identifiant' => $code, 'nom' => 'Les Essayeurs']);

    // Un nouveau héros rejoint par le code : le motif « tous morts » tombe, le rang est le même.
    $neuf = Personnage::create([
        'joueur_id' => $bruno->id, 'nom' => 'Brunehilde', 'classe' => 'nain', 'niveau' => 1,
        'attribut_body' => 3, 'attribut_mind' => 3, 'pv_body_max' => 7, 'pv_body' => 7, 'pv_mind_max' => 3, 'pv_mind' => 3,
        'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $this->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $neuf->id])->assertSuccessful();
    expect((string) app(DemarreurQuete::class)->refusDeDepart($groupe->refresh()))->not->toContain('morts')
        ->and((int) $groupe->rang_quete)->toBe(1);
    $this->getJson("/api/groupes/{$code}/etat")->assertOk();
    $vivant = collect($this->getJson('/api/moi')->json('joueur.personnages'))->firstWhere('id', $neuf->id);
    expect($vivant)->not->toHaveKey('mort_dans');
});

it('un héros relevé reprend sa case, ou la plus proche libre si une figure l\'occupe', function () {
    ['quete' => $quete, 'brunehaut' => $brunehaut] = partieApresMort($this);
    $etat = fn () => EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $brunehaut->id)->firstOrFail();

    // Personne sur sa case : elle se relève là où elle est tombée.
    $etat()->update(['tombe' => true]);
    $etat()->update(['tombe' => false]);
    expect([(int) $etat()->position_x, (int) $etat()->position_y])->toBe([3, 4]);

    // Un gobelin s'est posé sur sa tuile : elle se relève à côté, jamais sous lui.
    $etat()->update(['tombe' => true]);
    InstanceMonstre::where('quete_id', $quete->id)->firstOrFail()->update(['etat' => 'actif', 'revele' => true, 'position_x' => 3, 'position_y' => 4]);
    $etat()->update(['tombe' => false]);
    $ici = [(int) $etat()->position_x, (int) $etat()->position_y];
    expect($ici)->not->toBe([3, 4])->not->toBe([2, 4])
        ->and(abs($ici[0] - 3) + abs($ici[1] - 4))->toBeLessThanOrEqual(3);
});

it('EN JEU, classique : une potion qui ne réparerait rien n\'est ni offerte ni bue ; blessé ou empoisonné, elle l\'est', function () {
    ['code' => $code, 'groupe' => $groupe, 'albrecht' => $albrecht, 'brunehaut' => $brunehaut] = partieApresMort($this);
    donner($albrecht, 'Potion de soin');
    donner($albrecht, 'Antidote au venin');
    donner($albrecht, 'Potion de force'); // un bonus : toujours offerte

    // Tout le monde à son maximum, personne d'empoisonné : seule la Potion de force reste.
    $objets = objetsDuMenu($this, $groupe, $code, $albrecht);
    expect($objets->keys()->all())->toContain('Potion de force')->not->toContain('Potion de soin')->not->toContain('Antidote au venin');

    // Brunehaut blessée, au contact : la potion de soin est offerte, pour ELLE seule.
    $brunehaut->update(['pv_body' => 5]);
    $soin = objetsDuMenu($this, $groupe, $code, $albrecht)->get('Potion de soin');
    expect(collect($soin['cibles'])->pluck('id')->all())->toBe([$brunehaut->id]);

    // Le résolveur refuse ce que le menu n'offre pas : la boire soi-même, à son maximum.
    $this->postJson("/api/groupes/{$code}/choix", [
        'option_id' => 'utiliser_objet', 'parametres' => ['cle' => $soin['cle'], 'cible_id' => $albrecht->id],
    ])->assertStatus(422);
    expect(app(MoteurPotions::class)->sansEffet($albrecht->fresh(), (array) Objet::where('nom', 'Potion de soin')->value('effet')))->toBeTrue();

    // Empoisonné, l'antidote revient.
    $albrecht->conditions()->attach(\App\Models\Condition::where('nom', 'Empoisonné')->value('id'), ['duree' => 0]);
    expect(objetsDuMenu($this, $groupe, $code, $albrecht)->keys()->all())->toContain('Antidote au venin');
});

it('hors classique, rien ne change : un héros sans groupe classique n\'a jamais de potion « sans effet »', function () {
    $heros = Personnage::create([
        'joueur_id' => connecterJoueur('alice')->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $groupe = creerGroupe('table-rpg');
    $heros->update(['groupe_actif_id' => $groupe->id]);

    expect(app(MoteurPotions::class)->sansEffet($heros->fresh(), ['soin_pv_body' => 4, 'cible' => 'heros_adjacent']))->toBeFalse();
});
