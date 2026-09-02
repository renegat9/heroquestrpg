<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;
use App\Partie\MoteurPotions;
use App\Jobs\GenererMenu;
use Illuminate\Support\Facades\Cache;

/*
 * LES POTIONS PASSENT PAR LE MENU (René, 2026-09-01).
 *
 * ⚠ `POST /potions` a été RETIRÉE. Boire est désormais une entrée de l'option
 * « Utiliser un objet », résolue par `/choix` comme tout le reste : une seule
 * voie, une seule validation, un seul journal.
 *
 * ⚠ Conséquence assumée, choisie en connaissance de cause : on ne boit plus
 * hors de son tour, ni au hub, ni de sa propre initiative à terre. Le cas
 * d'urgence reste couvert — quand un héros TOMBE, `MoteurReactions` lui propose
 * ses potions (`ReactionHorsTourTest`).
 *
 * Les tests qui éprouvent le MOTEUR (plafond, pile, antidote, buff, relève)
 * appellent `MoteurPotions::boire()` directement, comme le fait déjà
 * `PotionsOfficiellesTest` : ce sont des règles, pas des routes.
 */

beforeEach(function () {
    Http::fake();
    $this->seed([ClasseHerosSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class]);
});

function donnerConsommable(Personnage $perso, string $nom, int $quantite = 1): Inventaire
{
    $objet = Objet::where('nom', $nom)->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $perso->id,
        'objet_id' => $objet->id,
        'emplacement' => 'consommable',
        'quantite' => $quantite,
    ]);
}

it('soigne le héros sans menu ni tour (action gratuite, à tout moment)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['pv_body' => 2, 'pv_body_max' => 8]);
    $ligne = donnerConsommable($heros, 'Potion de soin'); // soin_pv_body 4

    $resultat = app(App\Partie\MoteurPotions::class)->boire($heros, $ligne);

    expect($resultat['objet'])->toBe('Potion de soin')
        ->and($resultat['pv_body'])->toBe(6);

    expect((int) $heros->fresh()->pv_body)->toBe(6)
        ->and(Inventaire::find($ligne->id))->toBeNull(); // exemplaire consommé
});

it('plafonne le soin au maximum de PV', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['pv_body' => 7, 'pv_body_max' => 8]);
    $ligne = donnerConsommable($heros, 'Potion de soin');

    $resultat = app(MoteurPotions::class)->boire($ligne->personnage, $ligne);

    expect($resultat['pv_body'])->toBe(8)
        ->and((int) $heros->fresh()->pv_body)->toBe(8);
});

it('décrémente la pile et garde la ligne s\'il en reste', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['pv_body' => 1, 'pv_body_max' => 8]);
    $ligne = donnerConsommable($heros, 'Potion de soin', 2);

    $resultat = app(MoteurPotions::class)->boire($ligne->personnage, $ligne);

    expect($resultat['pv_body'])->toBe(5)
        ->and((int) Inventaire::find($ligne->id)->quantite)->toBe(1);
});

it('retire la condition ciblée (antidote)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $empoisonne = Condition::where('nom', 'Empoisonné')->firstOrFail();
    $heros->conditions()->attach($empoisonne->id, ['duree' => 3, 'source' => 'piege:test']);
    $ligne = donnerConsommable($heros, 'Antidote au venin');

    app(MoteurPotions::class)->boire($ligne->personnage, $ligne);

    expect($heros->fresh()->conditions()->where('nom', 'Empoisonné')->exists())->toBeFalse();
});

it('applique le buff de la Potion de force (bonus de dés d\'attaque)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $ligne = donnerConsommable($heros, 'Potion de force'); // bonus_des_attaque 2

    app(MoteurPotions::class)->boire($ligne->personnage, $ligne);

    // Le bonus est relu depuis l'effet de l'objet via le système de buffs.
    expect(app(MoteurSorts::class)->bonusDes($heros->fresh(), 'bonus_des_attaque'))->toBe(2);
});

it('ne met JAMAIS la potion d\'un autre héros dans la liste', function () {
    // ⚠ La garde d'appartenance a changé de place : elle vivait dans
    // `PotionController`, elle vit maintenant dans la LISTE que porte l'option
    // « Utiliser un objet ». Une potion qui n'est pas à soi n'y figure pas,
    // donc sa clé n'appartient pas à la liste blanche et le résolveur la
    // refuse — même principe que les cibles d'une attaque.
    $this->seed([MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class]);

    $alice = connecterJoueur('alice');
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $persoBob = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $aMoi = donnerConsommable($heros, 'Potion de soin');
    $aBob = donnerConsommable($persoBob, 'Potion de soin');

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);

    $objets = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])
        ->firstWhere('id', 'utiliser_objet')['parametres']['objets'] ?? [];
    $cles = collect($objets)->pluck('cle');

    expect($cles)->toContain("objet:{$aMoi->id}")
        ->and($cles)->not->toContain("objet:{$aBob->id}");

    // Et la forcer par la route est un 422 : la liste EST la liste blanche.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$aBob->id}"],
    ])->assertStatus(422);
});

it('RELÈVE un héros à terre, exactement comme le sort de soin', function () {
    // Démarrer une quête demande la carte et le bestiaire, absents du
    // beforeEach de ce fichier (les autres tests n'en ont pas besoin).
    $this->seed([MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class]);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $hero->id)->firstOrFail();

    // À terre, Body à zéro. Boire reste permis : c'est une action gratuite que
    // rien n'interdit à un tombé.
    $etat->update(['tombe' => true]);
    $hero->update(['pv_body' => 0]);

    $ligne = donnerConsommable($hero, 'Potion de soin');

    app(MoteurPotions::class)->boire($ligne->personnage, $ligne);

    // Le soin remet debout — le SORT le faisait déjà, la potion non : deux
    // chemins pour un même effet racontaient deux règles (aligné 2026-08-06).
    expect((int) $hero->fresh()->pv_body)->toBeGreaterThan(0)
        ->and((bool) $etat->fresh()->tombe)->toBeFalse();
});

it('ne relève PAS sur un soin qui ne rouvre pas le Body (antidote)', function () {
    // Démarrer une quête demande la carte et le bestiaire, absents du
    // beforeEach de ce fichier (les autres tests n'en ont pas besoin).
    $this->seed([MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class]);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $hero->id)->firstOrFail();

    $etat->update(['tombe' => true]);
    $hero->update(['pv_body' => 0]);
    $hero->conditions()->attach(Condition::where('nom', 'Empoisonné')->firstOrFail()->id, ['duree' => 2]);

    // ⚠ Il n'existe PLUS de consommable qui retire une condition sans rendre
    // de Body : l'Antidote sec a cédé la place à l'Antidote au venin, dont la
    // carte officielle soigne 2 PV. On fabrique donc ici l'objet qui isole la
    // règle — sans quoi `releverSiSoigne()` n'aurait plus aucun témoin.
    $elixir = Objet::create([
        'nom' => 'Élixir de test (purge seule)', 'categorie' => 'consommable',
        'rarete' => 'commun', 'prix_base' => 10, 'emplacement' => 'consommable',
        'effet' => ['retire_condition' => 'Empoisonné'],
    ]);
    $ligne = Inventaire::create([
        'personnage_id' => $hero->id, 'objet_id' => $elixir->id,
        'emplacement' => 'consommable', 'quantite' => 1,
    ]);
    app(MoteurPotions::class)->boire($ligne->personnage, $ligne);

    // Le poison part, mais un corps à zéro ne se relève pas pour autant.
    expect((int) $hero->fresh()->pv_body)->toBe(0)
        ->and((bool) $etat->fresh()->tombe)->toBeTrue()
        ->and($hero->fresh()->conditions()->where('nom', 'Empoisonné')->exists())->toBeFalse();
});
