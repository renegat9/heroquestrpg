<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;
use App\Partie\Equipement;
use App\Partie\FaveursHopekins;
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

/*
 * WEAPON EXPERT — le JOUEUR choisit son type d'arme au moment où il obtient la faveur
 * (René, 2026-10-15 ; livret G1504 p. 22-23 : « Select a type of weapon […] when you acquire
 * this Boon »). La faveur et son porteur restent tirés au hasard ; le choix se fait AU HUB,
 * par les vraies routes : `/moi` publie la liste blanche (lue dans le catalogue), `PUT
 * /groupes/{id}/faveurs/weapon-expert` la revalide et écrit le choix en base, `POST /pret`
 * refuse au porteur de partir sans avoir choisi, et le bonus lit ce choix. Une faveur sans
 * choix (acquise avant ce changement) reste jouable : le premier type d'arme dont le héros
 * frappe devient sa spécialité (voir FaveursHopekinsTest).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, MercenaireSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Un héros au hub qui vient de recevoir Weapon Expert (type vierge) ; l'offre d'animal est close. */
function heroAvecWeaponExpert(string $classe = 'barbare'): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    // L'offre du compagnon animal n'est pas le sujet ici (voir CompagnonOffertTest).
    $groupe->update(['compagnon_offert' => 'refuse']);
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => $classe]);
    PersonnageFaveur::create(['personnage_id' => $heros->id, 'cle' => FaveursHopekins::WEAPON_EXPERT]);

    return compact('alice', 'groupe', 'heros');
}

/** La faveur Weapon Expert de CE héros, telle que `/moi` la publie. */
function faveurPubliee(Personnage $heros): array
{
    $moi = test()->getJson('/api/moi')->assertOk()->json('joueur.personnages');

    return collect(collect($moi)->firstWhere('id', $heros->id)['faveurs'])->firstWhere('cle', FaveursHopekins::WEAPON_EXPERT);
}

function armeNomme(string $nom): Objet
{
    return Objet::where('nom', $nom)->firstOrFail();
}

it('TYPE D\'ARME : une pièce est de son nom, ou de l\'arme ordinaire qu\'elle est (artefact) ou dont elle est la copie en os', function () {
    $e = app(Equipement::class);

    expect($e->typeDArme(armeNomme('Épée longue')))->toBe('Épée longue')
        ->and($e->typeDArme(armeNomme('Fléau des Orques')))->toBe('Épée courte')   // est_une
        ->and($e->typeDArme(armeNomme('Lame Fantôme')))->toBe('Dague')             // est_une
        ->and($e->typeDArme(armeNomme('Hache de bataille en os')))->toBe('Hache de bataille') // os_de
        ->and($e->typeDArme(null))->toBeNull()
        // Le point de passage des règles qui NOMMENT une arme le voit aussi.
        ->and($e->estArmeDeType(armeNomme('Hache de bataille en os'), 'Hache de bataille'))->toBeTrue()
        ->and($e->estArmeDeType(armeNomme('Hache de bataille'), 'Épée longue'))->toBeFalse();
});

it('CHOIX publié : la liste blanche vient du catalogue — armes d\'armurerie que le héros peut manier, ni artefact ni copie en os', function () {
    $ctx = heroAvecWeaponExpert('barbare');

    $faveur = faveurPubliee($ctx['heros']);
    $noms = collect($faveur['choix'])->pluck('nom');

    expect($faveur['specialite'])->toBeNull()
        // Les armes d'armurerie accessibles au barbare, dans l'ordre du prix…
        ->and($noms->first())->toBe('Dague')
        ->and($noms->all())->toContain('Épée longue', 'Hache de bataille', 'Arbalète', 'Bâton')
        // …jamais ce qu'il ne peut pas manier (Baguette : « only the warlock »)…
        ->and($noms->all())->not->toContain('Baguette')
        // …ni un artefact (il a SON type), ni une copie en os (elle est du type de sa base).
        ->and($noms->all())->not->toContain('Fléau des Orques', 'Hache de bataille en os', 'Lame Fantôme');

    // Chaque entrée de la liste EST une arme du catalogue.
    foreach ($faveur['choix'] as $c) {
        $objet = Objet::findOrFail($c['objet_id']);
        expect($objet->categorie)->toBe('arme')
            ->and($objet->rarete)->not->toBe('unique')
            ->and($objet->os_de)->toBeNull();
    }
});

it('CHOIX publié à un magicien : sa liste est la SIENNE, pas celle du barbare', function () {
    $ctx = heroAvecWeaponExpert('magicien');

    $noms = collect(faveurPubliee($ctx['heros'])['choix'])->pluck('nom')->all();

    expect($noms)->toContain('Dague', 'Bâton')
        ->and($noms)->not->toContain('Hache de bataille', 'Épée longue', 'Arbalète');
});

it('CHOISIR : le joueur écrit son type d\'arme en base, il s\'affiche sur la fiche, et le choix est DÉFINITIF', function () {
    $ctx = heroAvecWeaponExpert();
    $epee = armeNomme('Épée longue');

    $this->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
        'personnage_id' => $ctx['heros']->id, 'objet_id' => $epee->id,
    ])->assertOk()
        ->assertJsonPath('specialite', 'Épée longue')
        ->assertJsonPath('objet_id', $epee->id);

    // EN BASE, jamais en cache.
    expect(PersonnageFaveur::where('personnage_id', $ctx['heros']->id)->value('parametre'))->toBe((string) $epee->id);

    // La fiche publie la spécialité, et plus aucun choix.
    $faveur = faveurPubliee($ctx['heros']);
    expect($faveur['specialite'])->toBe('Épée longue')->and($faveur['choix'])->toBeNull();

    // « when you acquire this Boon » : on ne change pas de spécialité.
    $this->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
        'personnage_id' => $ctx['heros']->id, 'objet_id' => armeNomme('Dague')->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['objet_id']);
    expect(PersonnageFaveur::where('personnage_id', $ctx['heros']->id)->value('parametre'))->toBe((string) $epee->id);
});

it('CHOISIR revalide la liste blanche : artefact, copie en os, arme interdite à la classe, objet inconnu — tous refusés', function () {
    $ctx = heroAvecWeaponExpert('barbare');

    foreach (['Fléau des Orques', 'Hache de bataille en os', 'Baguette', 'Potion de soin'] as $nom) {
        $this->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
            'personnage_id' => $ctx['heros']->id, 'objet_id' => armeNomme($nom)->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['objet_id']);
    }

    $this->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
        'personnage_id' => $ctx['heros']->id, 'objet_id' => 999999,
    ])->assertStatus(422)->assertJsonValidationErrors(['objet_id']);

    expect(PersonnageFaveur::where('personnage_id', $ctx['heros']->id)->value('parametre'))->toBeNull();
});

it('CHOISIR : refusé hors du hub, pour un héros qui n\'est pas à soi, et pour un héros sans la faveur', function () {
    $ctx = heroAvecWeaponExpert();
    $epee = armeNomme('Épée longue');

    // Le héros d'un autre joueur.
    $bob = \App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $autre = creerHeros($bob, $ctx['groupe'], 'Brynn', 2);
    $this->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
        'personnage_id' => $autre->id, 'objet_id' => $epee->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);

    // Un héros à soi, mais sans la faveur.
    $sans = creerHeros($ctx['alice'], $ctx['groupe'], 'Sœlle', 3);
    $this->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
        'personnage_id' => $sans->id, 'objet_id' => $epee->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);

    // Pas en quête.
    $ctx['groupe']->update(['phase' => 'quete']);
    $this->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
        'personnage_id' => $ctx['heros']->id, 'objet_id' => $epee->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['phase']);
    // Et en quête, `/moi` ne propose plus de choix (la même décision que le PUT).
    expect(faveurPubliee($ctx['heros'])['choix'])->toBeNull();
});

it('PRÊT : le porteur ne part pas sans avoir choisi son type d\'arme — et une fois choisi, il peut', function () {
    $ctx = heroAvecWeaponExpert();

    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $ctx['heros']->id, 'pret' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['pret'])
        ->assertJsonPath('errors.pret.0', fn (string $m) => str_contains($m, 'Weapon Expert'));

    // Se retirer des prêts n'est jamais bloqué.
    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $ctx['heros']->id, 'pret' => false])->assertOk();

    $this->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
        'personnage_id' => $ctx['heros']->id, 'objet_id' => armeNomme('Hache de bataille')->id,
    ])->assertOk();

    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $ctx['heros']->id, 'pret' => true])->assertOk();
});

it('PRÊT : un type d\'arme sans choix possible ne bloque personne (pas de liste, pas de refus)', function () {
    $ctx = heroAvecWeaponExpert('moine');
    // Le moine n'a aucune des armes de l'armurerie hors sa liste blanche nominative : on la vide.
    \App\Models\ClasseHeros::where('nom', 'moine')->firstOrFail()->update(['objets_autorises' => ['Objet inexistant']]);

    expect(faveurPubliee($ctx['heros'])['choix'])->toBeNull();

    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $ctx['heros']->id, 'pret' => true])->assertOk();
});

it('BONUS : le choix fait au hub est LU par l\'attaque — +1 dé avec ce type, rien avec un autre', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $epeeCourte = armeNomme('Épée courte');
    Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $epeeCourte->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
    $ctx['instance']->update(['pv_body' => 50, 'pv_body_max' => 50]);

    // La faveur est reçue puis choisie au HUB : la phase est rouverte le temps du geste.
    PersonnageFaveur::create(['personnage_id' => $heros->id, 'cle' => FaveursHopekins::WEAPON_EXPERT]);
    $ctx['groupe']->fresh()->update(['phase' => 'hub']); // `fresh()` : le modèle du helper garde l'ancienne phase en mémoire.
    $this->putJson('/api/groupes/table-1/faveurs/weapon-expert', [
        'personnage_id' => $heros->id, 'objet_id' => $epeeCourte->id,
    ])->assertOk();
    $ctx['groupe']->fresh()->update(['phase' => 'quete']);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 20, 4));

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['des_attaque_effectifs'])->toBe((int) $heros->fresh()->des_attaque + 1);

    // En quête, la fiche publiée porte la spécialité (table et manette), sans liste de choix.
    $fiche = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites'))->firstWhere('type', 'heros');
    expect(collect($fiche['faveurs'])->firstWhere('cle', 'weapon_expert'))
        ->toMatchArray(['specialite' => 'Épée courte', 'choix' => null]);
});

it('BONUS : une arme d\'un AUTRE type que le choix ne reçoit rien, et le choix n\'est jamais re-lié', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => armeNomme('Épée courte')->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
    $ctx['instance']->update(['pv_body' => 50, 'pv_body_max' => 50]);
    $longue = armeNomme('Épée longue');
    PersonnageFaveur::create(['personnage_id' => $heros->id, 'cle' => FaveursHopekins::WEAPON_EXPERT, 'parametre' => (string) $longue->id]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 20, 4));

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['des_attaque_effectifs'])->toBe((int) $heros->fresh()->des_attaque)
        ->and(PersonnageFaveur::where('personnage_id', $heros->id)->value('parametre'))->toBe((string) $longue->id);
});

it('BONUS : le type est celui de l\'armurerie — artefact `est_une` et copie en os comptent, et une faveur liée à un ARTEFACT vaut pour tout son type', function () {
    $ctx = heroAvecWeaponExpert();
    $heros = $ctx['heros'];
    $faveurs = app(FaveursHopekins::class);

    // Choix : Hache de bataille. La copie en os (identique à son homonyme de l'armurerie) en profite.
    PersonnageFaveur::where('personnage_id', $heros->id)->update(['parametre' => (string) armeNomme('Hache de bataille')->id]);
    $heros->unsetRelation('faveurs');
    expect($faveurs->bonusArmeExperte($heros, armeNomme('Hache de bataille')))->toBe(1)
        ->and($faveurs->bonusArmeExperte($heros, armeNomme('Hache de bataille en os')))->toBe(1)
        ->and($faveurs->bonusArmeExperte($heros, armeNomme('Épée longue')))->toBe(0)
        ->and($faveurs->bonusArmeExperte($heros, null))->toBe(0);

    // REPLI / ancien lien : une faveur que l'ancien code avait liée à un ARTEFACT (le Fléau des Orques,
    // une épée courte) vaut désormais pour toutes les épées courtes — « a type of weapon ».
    PersonnageFaveur::where('personnage_id', $heros->id)->update(['parametre' => (string) armeNomme('Fléau des Orques')->id]);
    $heros->unsetRelation('faveurs');
    expect($faveurs->bonusArmeExperte($heros, armeNomme('Épée courte')))->toBe(1)
        ->and($faveurs->bonusArmeExperte($heros, armeNomme('Fléau des Orques')))->toBe(1)
        ->and($faveurs->bonusArmeExperte($heros, armeNomme('Dague')))->toBe(0);
});

it('REPLI : une faveur SANS choix reste jouable — le premier type d\'arme manié devient sa spécialité', function () {
    $ctx = heroAvecWeaponExpert();
    $heros = $ctx['heros'];
    $faveurs = app(FaveursHopekins::class);

    expect($faveurs->bonusArmeExperte($heros, armeNomme('Rapière')))->toBe(1);

    $heros->unsetRelation('faveurs');
    expect(PersonnageFaveur::where('personnage_id', $heros->id)->value('parametre'))->toBe((string) armeNomme('Rapière')->id)
        ->and($faveurs->bonusArmeExperte($heros, armeNomme('Rapière')))->toBe(1)
        ->and($faveurs->bonusArmeExperte($heros, armeNomme('Dague')))->toBe(0);
});

it('l\'effet publié ne promet plus « la première arme maniée » : il dit que le choix se fait en recevant la faveur', function () {
    expect(FaveursHopekins::effet(FaveursHopekins::WEAPON_EXPERT))
        ->toContain('choisissez')
        ->not->toContain('premier type');
});
