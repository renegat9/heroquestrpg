<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Evenement;
use App\Models\GabaritQuete;
use App\Models\Inventaire;
use App\Models\Mercenaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MenuMoteur;
use App\Partie\MoteurPotions;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/*
 * Potion de charme / potion d'alchimie (Wizards of Morcar, lot 1b, 2026-10-08).
 *
 * Potion of Charm — carte relue à l'image : « Drink this potion between quests
 * when you want to hire Mercenaries. You may hire up to three Mercenaries for
 * 25 gold coins each less than normal. Discard after use. »
 *
 * Tout passe par les vraies routes et par le moteur : boire AU HUB (route
 * dédiée), trois recrutements à rabais consommés UN par UN, le prix plein
 * ensuite, la fiole jamais bue ne baisse rien, et la fiole refusée en quête.
 * Pas de remise tant qu'on POSSÈDE la fiole : c'était la première version,
 * fausse, et ces tests l'interdisent.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, MercenaireSeeder::class,
        SortDreadSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Donne au héros une ligne d'inventaire (une fiole, une pièce…). */
function charmeDonner(Personnage $hero, string $nomObjet, string $emplacement): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $hero->id,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

/** Les payloads du journal du groupe, décodés (une seule forme, quel que soit le cast). */
function charmeJournal(int $groupeId): array
{
    return Evenement::where('groupe_id', $groupeId)->get()
        ->map(fn ($e) => is_string($e->payload) ? json_decode($e->payload, true) : $e->payload)
        ->all();
}

it('la Potion de charme se boit au hub : trois recrutements à rabais, et la fiole est consommée', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);
    $fiole = charmeDonner($hero, 'Potion de charme', 'consommable');

    $this->postJson('/api/groupes/table-1/potions/boire-au-hub', [
        'personnage_id' => $hero->id,
        'inventaire_id' => $fiole->id,
    ])->assertOk()
        ->assertJsonPath('potion.objet', 'Potion de charme')
        ->assertJsonPath('potion.effets.rabais_recrutement.po', 25)
        ->assertJsonPath('potion.effets.rabais_recrutement.restants', 3)
        ->assertJsonPath('personnage.rabais_recrutement.restants', 3);

    // L'état est DURABLE, sur le héros — jamais en cache.
    expect($hero->fresh()->recrutements_a_rabais)->toBe(3)
        ->and((int) $hero->fresh()->rabais_recrutement_po)->toBe(25)
        ->and(Inventaire::whereKey($fiole->id)->exists())->toBeFalse();

    // Annoncé : une entrée de journal dit ce qui vient de se passer.
    expect(collect(charmeJournal($groupe->id))->pluck('action'))->toContain('potion_bue_au_hub');
});

it('chaque recrutement consomme UN rabais : 25 po de moins, puis le prix plein', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);
    $groupe->update(['or' => 1000]);
    $fiole = charmeDonner($hero, 'Potion de charme', 'consommable');

    $this->postJson('/api/groupes/table-1/potions/boire-au-hub', [
        'personnage_id' => $hero->id,
        'inventaire_id' => $fiole->id,
    ])->assertOk();

    $fauchard = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $prix = (int) $fauchard->prix;
    $or = 1000;

    // Trois recrutements à rabais, puis un au prix plein : le QUATRIÈME,
    // celui que la potion ne couvre pas, est aussi le dernier permis (4 par héros).
    foreach ([$prix - 25, $prix - 25, $prix - 25, $prix] as $dû) {
        $this->postJson('/api/groupes/table-1/mercenaires', [
            'mercenaire_id' => $fauchard->id,
            'personnage_id' => $hero->id,
        ])->assertStatus(201)
            ->assertJsonPath('or', $or - $dû);

        $or -= $dû;
    }

    expect($hero->fresh()->recrutements_a_rabais)->toBe(0)
        ->and($groupe->fresh()->mercenaires()->count())->toBe(4)
        ->and((int) $groupe->fresh()->or)->toBe(1000 - 3 * ($prix - 25) - $prix);

    // Annoncé : le recrutement dit le prix catalogue, la remise et le prix payé.
    $recrutements = collect(charmeJournal($groupe->id))->where('action', 'mercenaire_recrute')->values();
    expect($recrutements)->toHaveCount(4)
        ->and($recrutements[0]['rabais'])->toBe(25)
        ->and($recrutements[0]['prix'])->toBe($prix - 25)
        ->and($recrutements[0]['prix_catalogue'])->toBe($prix)
        ->and($recrutements[3]['rabais'])->toBe(0)
        ->and($recrutements[3]['prix'])->toBe($prix);
});

it('sans potion bue, un recrutement se paie au prix plein — posséder la fiole ne baisse rien', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);
    $groupe->update(['or' => 1000]);
    charmeDonner($hero, 'Potion de charme', 'consommable');

    $fauchard = Mercenaire::where('nom', 'Fauchard')->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => $fauchard->id,
        'personnage_id' => $hero->id,
    ])->assertStatus(201)
        ->assertJsonPath('or', 1000 - (int) $fauchard->prix);

    expect($hero->fresh()->recrutements_a_rabais)->toBe(0);
});

it('la Potion de charme est refusée EN QUÊTE, par le service comme par la route', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->value('id'),
        'titre' => 'Quête en cours',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
    ]);
    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    $fiole = charmeDonner($hero, 'Potion de charme', 'consommable');

    expect(fn () => app(MoteurPotions::class)->boire($hero->fresh(), $fiole))
        ->toThrow(ValidationException::class);

    $this->postJson('/api/groupes/table-1/potions/boire-au-hub', [
        'personnage_id' => $hero->id,
        'inventaire_id' => $fiole->id,
    ])->assertStatus(422);

    expect(Inventaire::whereKey($fiole->id)->exists())->toBeTrue()
        ->and($hero->fresh()->recrutements_a_rabais)->toBe(0);
});

it('une potion de quête ne se boit pas au hub : la route dédiée la refuse et garde la fiole', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $fiole = charmeDonner($hero, 'Potion de force', 'consommable');

    $this->postJson('/api/groupes/table-1/potions/boire-au-hub', [
        'personnage_id' => $hero->id,
        'inventaire_id' => $fiole->id,
    ])->assertStatus(422);

    expect(Inventaire::whereKey($fiole->id)->exists())->toBeTrue();
});

it('le menu de quête n\'offre jamais la Potion de charme, mais offre une potion ordinaire', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    charmeDonner($hero, 'Potion de charme', 'consommable');
    // Témoin : une potion SANS `cible` (donc soi-même, pas de compagnon à
    // côté exigé) doit, elle, rester offerte en quête. Les potions à
    // `heros_adjacent` ne le sont que s'il y a un destinataire légal.
    charmeDonner($hero, "Potion d'alchimie", 'consommable');

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    // `generer()` rend `{situation, options}` : les options sont sous `options`.
    $menu = app(MenuMoteur::class)->generer($groupe->fresh(), $hero->fresh());
    $offertes = collect($menu['options'])->where('id', 'utiliser_objet')
        ->flatMap(fn (array $option) => $option['parametres']['objets'] ?? [])
        ->pluck('nom');

    expect($offertes)->not->toContain('Potion de charme')
        ->toContain("Potion d'alchimie");
});

it('la Potion d\'alchimie transmute la première pièce d\'équipement du buveur en or pour le groupe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 0]);

    $piece = charmeDonner($hero, 'Armure de plates', 'sac');
    $fiole = charmeDonner($hero, "Potion d'alchimie", 'consommable');

    $resultat = app(MoteurPotions::class)->boire($hero->fresh(), $fiole);

    expect($resultat['effets']['transmute'])->toBe(['piece' => 'Armure de plates', 'or' => 100])
        ->and(Inventaire::whereKey($piece->id)->exists())->toBeFalse()
        ->and((int) $groupe->fresh()->or)->toBe(100);
});

it('la Potion d\'alchimie sans pièce d\'équipement ne rend rien — et le dit', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 0]);

    $fiole = charmeDonner($hero, "Potion d'alchimie", 'consommable');

    $resultat = app(MoteurPotions::class)->boire($hero->fresh(), $fiole);

    expect($resultat['effets']['transmute'])->toBe(['piece' => null, 'or' => 0])
        ->and((int) $groupe->fresh()->or)->toBe(0);
});
