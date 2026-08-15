<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/*
 * Équiper / déséquiper (doc 01 §7) : les deltas de combat de l'objet
 * s'appliquent aux colonnes du héros à l'équipement et sont révoqués au
 * déséquipement (même patron que les nœuds de compétence).
 */

beforeEach(function () {
    // ClasseHerosSeeder est indispensable : c'est lui qui porte `tags_equipement`,
    // les maîtrises accessibles par classe. Sans lui, Equipement échoue OUVERT
    // (aucune restriction) plutôt que de verrouiller le héros hors de son stuff.
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, CompetenceSeeder::class]);
});

function sacDe(Personnage $p, string $nomObjet): Inventaire
{
    $objet = Objet::where('nom', $nomObjet)->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => $objet->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
}

it('équipe une arme : elle FIXE les dés d\'attaque et passe en slot', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    $ligne = sacDe($heros, 'Épée large'); // effet des_attaque: 3

    (new Equipement)->equiper($heros, $ligne);

    // L'arme REMPLACE (doc 03 §8) : épée large = 3 dés, quelle que soit la
    // classe. Avant, elle s'ajoutait à la valeur de classe → 5.
    expect($heros->refresh()->des_attaque)->toBe(3)
        ->and($ligne->fresh()->emplacement)->toBe('arme_principale');
});

it('déséquipe une arme : les dés reviennent à la base, l\'objet retourne au sac', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    $ligne = sacDe($heros, 'Épée courte'); // des_attaque: 2

    $svc = new Equipement;
    $svc->equiper($heros, $ligne);
    expect($heros->refresh()->des_attaque)->toBe(2); // l'épée courte fixe 2

    $svc->desequiper($heros, $ligne->fresh());
    // Retour à MAINS NUES : 1 dé pour tous (base de classe), pas la valeur
    // d'avant équipement.
    expect($heros->refresh()->des_attaque)->toBe(1)
        ->and($ligne->fresh()->emplacement)->toBe('sac');
});

it('auto-swap : équiper une seconde arme remet la première au sac (capacité neutre)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    $courte = sacDe($heros, 'Épée courte'); // 2
    $large = sacDe($heros, 'Épée large');   // 3

    $svc = new Equipement;
    $svc->equiper($heros, $courte);
    $svc->equiper($heros, $large);

    expect($heros->refresh()->des_attaque)->toBe(3) // l'épée large fixe 3, la courte est révoquée
        ->and($courte->fresh()->emplacement)->toBe('sac')
        ->and($large->fresh()->emplacement)->toBe('arme_principale');
});

it('applique les dés de défense d\'une armure', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['des_defense' => 2]);
    $ligne = sacDe($heros, 'Cotte de mailles'); // des_defense: 1

    (new Equipement)->equiper($heros, $ligne);
    expect($heros->refresh()->des_defense)->toBe(3);
});

it('refuse un bouclier quand une arme à deux mains est équipée', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1); // barbare : deux mains d'emblée
    $hache = sacDe($heros, 'Hache de bataille'); // deux_mains
    $bouclier = sacDe($heros, 'Bouclier');       // incompatible_deux_mains

    $svc = new Equipement;
    $svc->equiper($heros, $hache);

    expect(fn () => $svc->equiper($heros, $bouclier))
        ->toThrow(ValidationException::class);
});

it('refuse une arme à deux mains à qui n\'y a pas droit — mais plus au barbare', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $svc = new Equipement;

    // Le barbare les manie de naissance : c'est sa signature.
    $barbare = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'barbare']);
    $svc->equiper($barbare, sacDe($barbare, 'Hache de bataille'));
    expect($barbare->refresh()->des_attaque)->toBe(4);

    // L'elfe, non — et aucun nœud de son arbre ne les lui ouvre.
    $elfe = creerHeros($alice, $groupe, 'Sylvaine', 2, ['classe' => 'elfe']);
    expect(fn () => $svc->equiper($elfe, sacDe($elfe, 'Hache de bataille')))
        ->toThrow(ValidationException::class);
});

it('refuse d\'équiper une armure lourde sans le nœud Maîtrise lourde, puis l\'autorise avec', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['des_defense' => 2]);
    $plates = sacDe($heros, 'Armure de plates');

    $svc = new Equipement;

    expect(fn () => $svc->equiper($heros, $plates))
        ->toThrow(ValidationException::class);

    $heros->competences()->attach(Competence::where('classe', 'barbare')->where('nom', 'Maîtrise lourde')->value('id'));

    $svc->equiper($heros, $plates->fresh());
    expect($heros->refresh()->des_defense)->toBe(4); // 2 + 2
});

it('refuse d\'équiper un objet du sac non montable (potion)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    // Une potion est en emplacement consommable, pas sac — on force une ligne sac
    // d'un objet-outil (Trousse à outils, emplacement sac) : non équipable.
    $ligne = sacDe($heros, 'Trousse à outils');

    expect(fn () => (new Equipement)->equiper($heros, $ligne))
        ->toThrow(ValidationException::class);
});

it('POST /equipement équipe au hub et renvoie les dés à jour ; refuse en quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    $ligne = sacDe($heros, 'Épée large');

    $this->postJson('/api/groupes/table-1/equipement', [
        'personnage_id' => $heros->id,
        'inventaire_id' => $ligne->id,
    ])->assertOk()->assertJsonPath('personnage.des_attaque', 3);

    // En quête : refus (l'endpoint REST reste hub-only ; en quête on équipe
    // via l'action du tour, cf. test suivant).
    $groupe->update(['phase' => 'quete']);
    $this->deleteJson('/api/groupes/table-1/equipement', [
        'personnage_id' => $heros->id,
        'inventaire_id' => $ligne->id,
    ])->assertStatus(422);
});

it('équipe en PLEINE QUÊTE via l\'action du tour (doc 01 §149) : dés à jour, action consommée', function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    // 2e héros : après l'action d'Albrecht le tour NE passe PAS aux monstres
    // (sinon le nouveau tour réinitialiserait les créneaux avant l'assertion).
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);
    $ligne = sacDe($heros, 'Épée large'); // effet des_attaque +3

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    $etat->update(['deplacement_tour' => 6, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    // Le menu propose « Équiper Épée large ».
    desFiges(array_fill(0, 20, 4));
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);
    $optionId = "equiper_{$ligne->id}";
    expect(collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id'))
        ->toContain($optionId);

    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $optionId])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'equiper')
        ->assertJsonPath('resultat.objet', 'Épée large');

    // Arme équipée (dés à jour) et ACTION consommée (a_agi). Le mouvement N'EST
    // PLUS forfait (on peut équiper PUIS se déplacer) et le tour ne se termine
    // que sur décision du joueur.
    expect($heros->fresh()->des_attaque)->toBe(3);
    $etat->refresh();
    expect($etat->a_agi)->toBeTrue()
        ->and($etat->a_deplace)->toBeFalse()
        ->and($etat->a_joue)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Maîtrises par classe (doc 01 §7) — profil « canon HeroQuest »
// ---------------------------------------------------------------------------

it('interdit au magicien l\'armure et les armes de mêlée courantes', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    $svc = new Equipement;

    foreach (['Épée courte', 'Casque', 'Cotte de mailles', 'Bouclier', 'Arbalète'] as $interdit) {
        expect(fn () => $svc->equiper($magicien, sacDe($magicien, $interdit)))
            ->toThrow(ValidationException::class, null, "« {$interdit} » aurait dû être refusé");
    }

    // Sa dague et son bâton restent les siens.
    $svc->equiper($magicien, sacDe($magicien, 'Dague'));
    expect($magicien->refresh()->des_attaque)->toBe(1);
});

it('laisse le magicien porter le Bâton : `deux_mains` n\'est pas une maîtrise', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    // Le bâton interdit le bouclier (« You may not use a shield when using this
    // weapon ») mais sa carte ne porte AUCUNE restriction de classe : tag
    // `arme_legere`, accessible à tous. Les deux notions sont orthogonales.
    //
    // ⚠ Il ne rend qu'UN dé, pas deux : c'est le chiffre de la carte officielle
    // (© 2021). Le paquet fan lui en donnait deux, ce qui en faisait la
    // meilleure arme du magicien ; il est désormais à égalité avec sa dague, et
    // n'apporte que la diagonale.
    (new Equipement)->equiper($magicien, sacDe($magicien, 'Bâton'));

    expect($magicien->refresh()->des_attaque)->toBe(1);
});

it('ouvre au magicien l\'armure légère via le nœud « Cuir d\'apprenti »', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien', 'des_defense' => 2]);
    $casque = sacDe($magicien, 'Casque');

    $svc = new Equipement;
    expect(fn () => $svc->equiper($magicien, $casque))
        ->toThrow(ValidationException::class);

    $magicien->competences()->attach(
        Competence::where('classe', 'magicien')->where('nom', "Cuir d'apprenti")->value('id'),
    );

    $svc->equiper($magicien, $casque->fresh());
    expect($magicien->refresh()->des_defense)->toBe(3);
});

it('laisse barbare, nain et elfe équiper armes courantes, distance et armure légère', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $svc = new Equipement;

    foreach (['barbare', 'nain', 'elfe'] as $i => $classe) {
        $heros = creerHeros($alice, $groupe, ucfirst($classe).$i, $i + 1, ['classe' => $classe]);

        $svc->equiper($heros, sacDe($heros, 'Épée large'));
        $svc->equiper($heros, sacDe($heros, 'Cotte de mailles'));

        expect($heros->refresh()->des_attaque)->toBe(3)
            ->and($heros->refresh()->des_defense)->toBe(3);

        // Symétrie des deux costauds : chacun a sa spécialité gratuite et paie
        // l'autre. Barbare = armes à deux mains libres, armure lourde au nœud ;
        // nain = l'inverse ; elfe = ni l'un ni l'autre.
        $hache = fn () => $svc->equiper($heros, sacDe($heros, 'Hache de bataille'));
        $plates = fn () => $svc->equiper($heros, sacDe($heros, 'Armure de plates'));

        if ($classe === 'barbare') {
            $hache();
            expect($heros->refresh()->des_attaque)->toBe(4);
            expect($plates)->toThrow(ValidationException::class);
        } elseif ($classe === 'nain') {
            expect($hache)->toThrow(ValidationException::class);
            $plates();
            // Les plates REMPLACENT la cotte (même emplacement, auto-swap) :
            // 2 de base + 2, et non un cumul des deux armures.
            expect($heros->refresh()->des_defense)->toBe(4);
        } else {
            expect($hache)->toThrow(ValidationException::class);
            expect($plates)->toThrow(ValidationException::class);
        }
    }
});

it('nomme le nœud à prendre quand il existe, et dit « hors de portée » sinon', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $svc = new Equipement;

    // Le magicien A un nœud pour l'armure légère → on le lui nomme.
    $magicien = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);
    expect(fn () => $svc->equiper($magicien, sacDe($magicien, 'Casque')))
        ->toThrow(ValidationException::class, null);

    try {
        $svc->equiper($magicien, sacDe($magicien, 'Casque'));
    } catch (ValidationException $e) {
        expect($e->errors()['inventaire_id'][0])->toContain("Cuir d'apprenti");
    }

    // Il n'a AUCUN nœud vers l'armure lourde → message « hors de portée ».
    try {
        $svc->equiper($magicien, sacDe($magicien, 'Armure de plates'));
    } catch (ValidationException $e) {
        expect($e->errors()['inventaire_id'][0])->toContain('hors de portée');
    }
});

it('laisse le NAIN porter l\'armure lourde sans aucun nœud', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain', 'des_defense' => 2]);

    // Le forgeron robuste du groupe : les plates sont son affaire, pas un talent
    // à acheter. Elles restaient pourtant fermées, faute d'un nœud « Maîtrise
    // lourde » qui n'existe que dans l'arbre du barbare.
    (new Equipement)->equiper($nain, sacDe($nain, 'Armure de plates'));

    expect($nain->refresh()->des_defense)->toBe(4); // 2 + 2
});

it('exige « Poigne de forgeron » au nain pour une arme à DEUX MAINS', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    $hache = sacDe($nain, 'Hache de bataille');

    $svc = new Equipement;

    // Les grosses armes restent la signature du barbare : le nain les paie d'un
    // point de compétence.
    expect(fn () => $svc->equiper($nain, $hache))
        ->toThrow(ValidationException::class);

    $nain->competences()->attach(
        Competence::where('classe', 'nain')->where('nom', 'Poigne de forgeron')->value('id'),
    );

    $svc->equiper($nain, $hache->fresh());
    expect($nain->refresh()->des_attaque)->toBe(4);
});

it('garde l\'ELFE et le MAGICIEN hors du lourd', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $svc = new Equipement;

    foreach (['elfe', 'magicien'] as $i => $classe) {
        $heros = creerHeros($alice, $groupe, ucfirst($classe), $i + 1, ['classe' => $classe]);

        expect(fn () => $svc->equiper($heros, sacDe($heros, 'Armure de plates')))
            ->toThrow(ValidationException::class)
            ->and(fn () => $svc->equiper($heros, sacDe($heros, 'Hache de bataille')))
            ->toThrow(ValidationException::class);
    }
});

it('n\'annonce pas un BOUCLIER comme une arme', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $svc = new Equipement;
    $svc->equiper($heros, sacDe($heros, 'Épée courte'));
    $svc->equiper($heros, sacDe($heros, 'Bouclier'));

    $portees = collect(test()->getJson('/api/moi')->assertOk()
        ->json('joueur.personnages.0.equipement.armes'))->keyBy('nom');

    // Un bouclier occupe `arme_secondaire` : il figurait donc parmi les « armes »,
    // libellé « Arme équipée » et icône épées croisées, exactement comme l'épée.
    expect($portees['Épée courte']['bouclier'])->toBeFalse()
        ->and($portees['Bouclier']['bouclier'])->toBeTrue()
        ->and($portees['Bouclier']['emplacement'])->toBe('arme_secondaire');
});
