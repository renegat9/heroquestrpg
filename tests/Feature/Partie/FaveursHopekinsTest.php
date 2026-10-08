<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;
use App\Models\Quete;
use App\Partie\EtatGroupe;
use App\Partie\FaveursHopekins;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Support\Journal;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Faveurs de Hopekins Rest (livret G1504 p. 22-23, Wizards of Morcar) —
 * chantier 1c, décision de René du 2026-10-06 : récompense de quête séparée,
 * HORS arbre de talents. Les CINQ compétences transcrites
 * (`reference/18_extensions.md` §Wizards of Morcar — cartes TRANSCRITES, §7)
 * et le tirage de fin de quête (`FaveursHopekins::attribuerFaveurDeFinDeQuete()`).
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

/** Octroie une faveur directement (contourne le tirage de fin de quête, hors sujet ici). */
function donnerFaveur(Personnage $p, string $cle, ?string $parametre = null): PersonnageFaveur
{
    return PersonnageFaveur::create(['personnage_id' => $p->id, 'cle' => $cle, 'parametre' => $parametre]);
}

/** Même rôle que `equipeArbalete()` (AttaqueDistanceHerosTest) : arme à distance, sans passer par le flux d'équipement. */
function equiperArbaleteFaveurs(Personnage $p): Inventaire
{
    $objet = Objet::where('nom', 'Arbalète')->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $p->id, 'objet_id' => $objet->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
}

/** Arme de contact ORDINAIRE, posée directement en main (comme Weapon Expert la trouve). */
function equiperEpeeCourteFaveurs(Personnage $p): Inventaire
{
    $objet = Objet::where('nom', 'Épée courte')->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $p->id, 'objet_id' => $objet->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
}

// ---------------------------------------------------------------------------
// VOCABULAIRE — registre testé dans les deux sens.
// ---------------------------------------------------------------------------

it('TOUTES, LIBELLES et EFFETS déclarent exactement les mêmes 5 clés', function () {
    expect(FaveursHopekins::TOUTES)->toHaveCount(5)
        ->and(array_keys(FaveursHopekins::LIBELLES))->toEqualCanonicalizing(FaveursHopekins::TOUTES)
        ->and(array_keys(FaveursHopekins::EFFETS))->toEqualCanonicalizing(FaveursHopekins::TOUTES);
});

// ---------------------------------------------------------------------------
// DEADEYE — « Monster and heroes do not block your line of sight when
// attacking or casting spells. »
// ---------------------------------------------------------------------------

it('DEADEYE laisse tirer à travers une figure interposée, que le menu refuse sans la faveur', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    equiperArbaleteFaveurs($ctx['heros']);
    $hx = (int) $ctx['etatHeros']->position_x;
    $hy = (int) $ctx['etatHeros']->position_y;

    $cases = $ctx['quete']->carte->grille['cases'];
    $sol = fn ($x, $y) => in_array($cases[$y][$x] ?? 'm', ['s', 'p'], true);
    $trio = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if ($sol($hx + $dx, $hy + $dy) && $sol($hx + 2 * $dx, $hy + 2 * $dy)) {
            $trio = [['x' => $hx + $dx, 'y' => $hy + $dy], ['x' => $hx + 2 * $dx, 'y' => $hy + 2 * $dy]];
            break;
        }
    }
    expect($trio)->not->toBeNull('Pas d\'alignement droit sol pour le scénario.');
    [$inter, $spot] = $trio;

    $ctx['instance']->update(['position_x' => $spot['x'], 'position_y' => $spot['y']]);
    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Orque')->value('id'),
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 0,
        'position_x' => $inter['x'], 'position_y' => $inter['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    // SANS la faveur : refusé (même scénario que AttaqueDistanceHerosTest).
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id]])
        ->assertStatus(422);

    // AVEC la faveur : le menu liste la cible, et l'attaque passe.
    donnerFaveur($ctx['heros'], FaveursHopekins::DEADEYE);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];
    $option = collect($menu['options'])->firstWhere('id', 'attaquer');
    expect(collect($option['parametres']['cibles'])->pluck('id'))->toContain($ctx['instance']->id);

    desFiges(array_fill(0, 20, 4)); // boucliers partout : combat neutre
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id]])
        ->assertStatus(202)
        ->assertJsonPath('resultat.portee', 'distance');
});

// ---------------------------------------------------------------------------
// WEAPON EXPERT — « Select a type of weapon […]. When attacking with a
// weapon of that type, roll 1 additional Attack dice. »
// ---------------------------------------------------------------------------

it('WEAPON EXPERT ajoute 1 dé d\'attaque avec le type d\'arme lié, et se lie à la PREMIÈRE arme maniée sans faveur choisie', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $epee = equiperEpeeCourteFaveurs($ctx['heros']);
    $ctx['instance']->update(['pv_body' => 50, 'pv_body_max' => 50]); // survit au coup

    donnerFaveur($ctx['heros'], FaveursHopekins::WEAPON_EXPERT); // parametre encore null

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4)); // boucliers partout : dégâts neutres, seul le compte de dés importe

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['des_attaque_effectifs'])->toBe((int) $ctx['heros']->fresh()->des_attaque + 1);

    $faveur = PersonnageFaveur::where('personnage_id', $ctx['heros']->id)->where('cle', FaveursHopekins::WEAPON_EXPERT)->firstOrFail();
    expect((int) $faveur->parametre)->toBe($epee->objet_id);
});

it('WEAPON EXPERT ne bonifie PAS un type d\'arme différent de celui déjà lié', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    equiperEpeeCourteFaveurs($ctx['heros']); // combat au contact, arme réellement maniée
    $ctx['instance']->update(['pv_body' => 50, 'pv_body_max' => 50]);

    // Déjà liée à une AUTRE arme (une Épée longue, jamais équipée ici).
    $autreArme = Objet::where('nom', 'Épée longue')->firstOrFail();
    donnerFaveur($ctx['heros'], FaveursHopekins::WEAPON_EXPERT, (string) $autreArme->id);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 4));

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat');

    expect($resultat['des_attaque_effectifs'])->toBe((int) $ctx['heros']->fresh()->des_attaque);
});

// ---------------------------------------------------------------------------
// HEALING HANDS — « If a hero adjacent to you is reduced to 0 Body points,
// you may allow them to use one of your available healing potions instead
// of their own. »
// ---------------------------------------------------------------------------

it('HEALING HANDS offre la potion d\'un VOISIN porteur, et la consomme dans SON sac', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $tombe = $ctx['heros'];
    $tombe->update(['pv_body' => 2]);

    // Deuxième héros, adjacent, porteur de la faveur et d'une potion.
    $aidant = creerHeros($ctx['alice'], $ctx['groupe'], 'Sœlle', 2);
    $case = caseAdjacenteLibre($ctx['quete'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);
    EtatPersonnageQuete::create([
        'personnage_id' => $aidant->id, 'quete_id' => $ctx['quete']->id,
        'position_x' => $case['x'], 'position_y' => $case['y'], 'tombe' => false,
    ]);
    donnerFaveur($aidant, FaveursHopekins::HEALING_HANDS);
    $potionAidant = Inventaire::create([
        'personnage_id' => $aidant->id,
        'objet_id' => Objet::where('nom', 'Potion de soin')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'sac',
    ]);

    // Le tombé n'a AUCUNE potion à lui.
    app(MoteurDegats::class)->infligerAHeros($tombe->fresh(), 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);
    $ctx['etatHeros']->fresh()->update(['tombe' => true]);

    $attente = $ctx['etatHeros']->fresh()->reaction_en_attente;
    expect($attente['action'])->toBe('soin_urgence')
        ->and(collect($attente['soins'])->pluck('cle')->all())->toBe(["potion_aide:{$potionAidant->id}"])
        ->and($attente['soins'][0]['aidant_personnage_id'])->toBe($aidant->id);

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $tombe->id, 'accepte' => true,
        'soin' => "potion_aide:{$potionAidant->id}",
    ])->assertOk()->assertJsonPath('reaction.debout', true);

    expect((int) $tombe->fresh()->pv_body)->toBeGreaterThan(0)
        ->and((bool) $ctx['etatHeros']->fresh()->tombe)->toBeFalse()
        // La potion a quitté LE SAC DE L'AIDANT, jamais celui du tombé.
        ->and(Inventaire::find($potionAidant->id))->toBeNull();
});

it('HEALING HANDS n\'offre rien si le voisin n\'a PAS la faveur (juste une potion)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $tombe = $ctx['heros'];
    $tombe->update(['pv_body' => 2]);

    $voisin = creerHeros($ctx['alice'], $ctx['groupe'], 'Sœlle', 2);
    $case = caseAdjacenteLibre($ctx['quete'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);
    EtatPersonnageQuete::create([
        'personnage_id' => $voisin->id, 'quete_id' => $ctx['quete']->id,
        'position_x' => $case['x'], 'position_y' => $case['y'], 'tombe' => false,
    ]);
    // PAS de faveur ici.
    Inventaire::create([
        'personnage_id' => $voisin->id,
        'objet_id' => Objet::where('nom', 'Potion de soin')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'sac',
    ]);

    app(MoteurDegats::class)->infligerAHeros($tombe->fresh(), 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
        ['monstre' => 'Gobelin', 'instance_id' => $ctx['instance']->id]);
    $ctx['etatHeros']->fresh()->update(['tombe' => true]);

    // Aucun soin disponible (ni pour lui, ni par un voisin sans la faveur) :
    // pas de proposition déposée.
    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();
});

// ---------------------------------------------------------------------------
// HOLD THE LINE — « Each time a monster on Zargon's turn moves away from the
// 8 spaces immediately surrounding you, roll a combat die. If you roll a
// skull, inflict 1 Body Point of damage on the retreating monster. »
// ---------------------------------------------------------------------------

it('HOLD THE LINE inflige 1 dégât sur un crâne quand le monstre quitte les 8 cases autour du porteur', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    donnerFaveur($ctx['heros'], FaveursHopekins::HOLD_THE_LINE);
    $ctx['instance']->update(['pv_body' => 10, 'pv_body_max' => 10]);

    $avant = ['x' => (int) $ctx['instance']->position_x, 'y' => (int) $ctx['instance']->position_y];
    // Le monstre « s'éloigne » : déplacé à une case qui n'est plus dans les 8
    // autour du héros.
    $ctx['instance']->update(['position_x' => $avant['x'] + 5, 'position_y' => $avant['y']]);

    desFiges([1]); // 1 = crâne (FaceDeCombat::depuisD6)

    $actions = app(FaveursHopekins::class)->tenterHoldTheLine(
        $ctx['groupe'], $ctx['quete'], $ctx['instance']->fresh(), $avant,
    );

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['touche'])->toBeTrue()
        ->and($actions[0]['degats'])->toBe(1)
        ->and((int) $ctx['instance']->fresh()->pv_body)->toBe(9);
});

it('HOLD THE LINE ne tente rien si le monstre reste dans les 8 cases, ni sans la faveur', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $avant = ['x' => (int) $ctx['instance']->position_x, 'y' => (int) $ctx['instance']->position_y];

    // Sans la faveur : aucune tentative, même en s'éloignant franchement.
    $ctx['instance']->update(['position_x' => $avant['x'] + 5]);
    expect(app(FaveursHopekins::class)->tenterHoldTheLine($ctx['groupe'], $ctx['quete'], $ctx['instance']->fresh(), $avant))->toBe([]);

    // Avec la faveur, mais le monstre reste adjacent (ne « quitte » rien).
    donnerFaveur($ctx['heros'], FaveursHopekins::HOLD_THE_LINE);
    $ctx['instance']->update(['position_x' => $avant['x']]); // retour au contact
    expect(app(FaveursHopekins::class)->tenterHoldTheLine($ctx['groupe'], $ctx['quete'], $ctx['instance']->fresh(), $avant))->toBe([]);
});

/** Compteur PEACEKEEPER du héros pour CETTE quête (`etat_personnage_quete.monstres_vaincus`). */
function compteurPeacekeeper(Quete $quete, Personnage $heros): int
{
    return (int) EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $heros->id)
        ->value('monstres_vaincus');
}

/** Victoire SANS butin de gabarit : l'or qui bouge est celui de la faveur seule. */
function victoireSansButin(Groupe $groupe): array
{
    $quete = $groupe->fresh()->queteCourante;
    $quete->gabarit->update(['structure' => [...(array) $quete->gabarit->structure, 'butin' => ['or_base' => 0]]]);

    return acheverLaQuete($groupe->fresh());
}

// ---------------------------------------------------------------------------
// PEACEKEEPER — « 25 gold coins per monster defeated. »
// ---------------------------------------------------------------------------

it('PEACEKEEPER compte le monstre achevé pour la quête, sans rien encaisser à la mise à mort', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    equiperEpeeCourteFaveurs($ctx['heros']);
    $ctx['instance']->update(['pv_body' => 1, 'pv_body_max' => 1]);
    $ctx['groupe']->update(['or' => 100]);

    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 1)); // crânes partout : coup fatal garanti, rien ne pare

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.cible_vaincue', true);

    expect((int) $ctx['groupe']->fresh()->or)->toBe(100)
        ->and(compteurPeacekeeper($ctx['quete'], $ctx['heros']))->toBe(1);
});

it('PEACEKEEPER ne compte rien sans la faveur, ni quand le monstre survit', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    equiperEpeeCourteFaveurs($ctx['heros']);
    $ctx['instance']->update(['pv_body' => 1, 'pv_body_max' => 1]);
    $ctx['groupe']->update(['or' => 100]);
    // PAS de faveur.

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 1));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    expect((int) $ctx['groupe']->fresh()->or)->toBe(100);
});

// ---------------------------------------------------------------------------
// PEACEKEEPER — câblage au POINT DE PASSAGE UNIQUE (2026-10-08). Avant cette
// date, seuls DEUX des douze chemins de `MoteurDegats::infligerAMonstre()`
// créditaient la faveur (l'arme et le sort à cible unique, testés juste
// au-dessus via l'API). Les tests qui suivent prouvent que le câblage a bougé
// au point de passage lui-même : un chemin qui n'avait JAMAIS été relié
// (l'eau bénite) crédite désormais, un changement de phase ne crédite
// JAMAIS (ce n'est pas une mort), et les chemins sans héros identifiable
// (allié, piège) restent délibérément muets.
// ---------------------------------------------------------------------------

it('PEACEKEEPER compte aussi un chemin qui n\'avait JAMAIS été câblé avant (eau bénite)', function () {
    $ctx = demarrerQueteAvecMonstre('Zombie');
    $ctx['groupe']->update(['or' => 100]);
    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);

    // Même point de passage que `ResolveurTour::resoudreEauBenite()` —
    // appelé directement pour isoler le lecteur plutôt que de rebâtir tout
    // le flux d'objet consommable.
    $resultat = app(MoteurDegats::class)->infligerAMonstre(
        $ctx['instance'], (int) $ctx['instance']->pv_body, MoteurDegats::SOURCE_EAU_BENITE, [], $ctx['heros'],
    );

    expect($resultat['vaincu'])->toBeTrue()
        ->and((int) $ctx['groupe']->fresh()->or)->toBe(100)
        ->and(compteurPeacekeeper($ctx['quete'], $ctx['heros']))->toBe(1);
});

it('PEACEKEEPER ne compte PAS un changement de phase — seulement la mort réelle qui suit', function () {
    $ctx = demarrerQueteAvecMonstre('Spawn of the Pit');
    $ctx['groupe']->update(['or' => 100]);
    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);
    $degats = app(MoteurDegats::class);

    // Premier coup fatal : adopte sa forme déchaînée — « still considered
    // the same monster », donc PAS vaincu, donc PAS d'or malgré la faveur.
    $r1 = $degats->infligerAMonstre($ctx['instance'], 99, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['heros']);
    expect($r1['vaincu'])->toBeFalse()
        ->and($r1['changement_phase'])->not->toBeNull()
        ->and((int) $ctx['groupe']->fresh()->or)->toBe(100);

    // Sa dernière phase meurt pour de vrai : l'or tombe ENFIN, une seule fois.
    $r2 = $degats->infligerAMonstre(
        $ctx['instance']->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['heros'],
    );
    expect($r2['vaincu'])->toBeTrue()
        ->and((int) $ctx['groupe']->fresh()->or)->toBe(100)
        ->and(compteurPeacekeeper($ctx['quete'], $ctx['heros']))->toBe(1);
});

it('PEACEKEEPER ne compte PAS un monstre achevé par un allié ou un piège — « you » désigne le héros, jamais un auxiliaire', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['groupe']->update(['or' => 100]);
    $ctx['instance']->update(['pv_body' => 1, 'pv_body_max' => 1]);
    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);

    // Un allié achève le monstre : aucun héros n'est transmis en 5e
    // argument (décision de portage, voir MoteurDegats::infligerAMonstre()).
    $resultatAllie = app(MoteurDegats::class)->infligerAMonstre(
        $ctx['instance'], 1, MoteurDegats::SOURCE_ATTAQUE_ALLIE, ['allie' => 'Mercenaire'],
    );
    expect($resultatAllie['vaincu'])->toBeTrue()
        ->and((int) $ctx['groupe']->fresh()->or)->toBe(100);

    // Un piège achève un second monstre, même absence d'auteur.
    $second = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => $ctx['instance']->monstre_id,
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 0,
        'position_x' => (int) $ctx['instance']->position_x, 'position_y' => (int) $ctx['instance']->position_y,
        'etat' => 'actif', 'revele' => true,
    ]);
    app(MoteurDegats::class)->infligerAMonstre($second, 1, MoteurDegats::SOURCE_PIEGE, ['piege' => 'Fosse']);

    expect((int) $ctx['groupe']->fresh()->or)->toBe(100);
});

// ---------------------------------------------------------------------------
// ATTRIBUTION DE FIN DE QUÊTE — tirage (décision d'interprétation du
// chantier) : UNE faveur parmi les 5 non détenues, à un héros actif au hasard.
// ---------------------------------------------------------------------------

/** Id de la dernière quête achevée — ce que `terminerQuete()` passe à l'attribution. */
function derniereQueteTerminee(Groupe $groupe): int
{
    return (int) $groupe->quetes()->where('etat', 'terminee')->orderByDesc('id')->value('id');
}

it('ATTRIBUTION n\'offre rien avant le statut de Gardien', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    expect($groupe->estGardien())->toBeFalse()
        ->and(app(FaveursHopekins::class)->attribuerFaveurDeFinDeQuete($groupe, 1))->toBeNull();
});

it('ATTRIBUTION tire une des 5 faveurs pour un héros actif, une fois Gardien, et épuise le pool', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);

    $vues = [];
    foreach (range(1, 5) as $_) {
        $resultat = app(FaveursHopekins::class)->attribuerFaveurDeFinDeQuete($groupe, derniereQueteTerminee($groupe));
        expect($resultat)->not->toBeNull()
            ->and($resultat['personnage_id'])->toBe($heros->id)
            ->and(FaveursHopekins::TOUTES)->toContain($resultat['faveur']);
        $vues[] = $resultat['faveur'];
    }

    // Les 5 faveurs, chacune UNE fois (jamais deux fois le même héros/la même clé).
    expect($vues)->toEqualCanonicalizing(FaveursHopekins::TOUTES)
        ->and(PersonnageFaveur::where('personnage_id', $heros->id)->count())->toBe(5);

    // Épuisé : plus rien à tirer.
    expect(app(FaveursHopekins::class)->attribuerFaveurDeFinDeQuete($groupe, derniereQueteTerminee($groupe)))->toBeNull();
});

it('ATTRIBUTION est journalisée et republiée au hub (groupe.faveur_hopekins)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);

    app(FaveursHopekins::class)->attribuerFaveurDeFinDeQuete($groupe, derniereQueteTerminee($groupe));

    $hub = app(EtatGroupe::class)->payload($groupe->fresh());
    expect($hub['groupe']['faveur_hopekins'])->not->toBeNull()
        ->and($hub['groupe']['faveur_hopekins']['action'])->toBe('faveur_hopekins');
});

it('visible sur la fiche du héros (EtatGroupe.entites[].faveurs)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    donnerFaveur($ctx['heros'], FaveursHopekins::DEADEYE);

    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $moi = collect($etat['entites'])->firstWhere('type', 'heros');

    expect(collect($moi['faveurs'])->pluck('cle')->all())->toBe([FaveursHopekins::DEADEYE])
        ->and($moi['faveurs'][0]['libelle'])->toBe('Deadeye');
});

// ---------------------------------------------------------------------------
// ANNONCES (2026-10-08) — un effet automatique que rien n'annonce est
// injouable. Le fil de combat rend Peacekeeper et Hold the Line (en direct ET
// à la reconnexion), le hub annonce l'entretien et la faveur de la DERNIÈRE
// quête seulement, la fiche porte chaque faveur avec son effet.
// ---------------------------------------------------------------------------

it('la fiche publie le nom ET l\'effet de chaque faveur, en quête (EtatGroupe) comme au hub (/moi)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);

    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $fiche = collect($etat['entites'])->firstWhere('type', 'heros');
    expect($fiche['faveurs'])->toBe([[
        'cle' => 'peacekeeper',
        'libelle' => 'Peacekeeper',
        'effet' => FaveursHopekins::EFFETS[FaveursHopekins::PEACEKEEPER],
    ]]);

    $moi = $this->getJson('/api/moi')->assertOk()->json();
    $perso = collect($moi['joueur']['personnages'])->firstWhere('id', $ctx['heros']->id);
    expect($perso['faveurs'])->toBe($fiche['faveurs']);
});

it('PEACEKEEPER est annoncé au fil : dans le résultat de l\'action (en direct) ET au journal de combat (reconnexion)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    equiperEpeeCourteFaveurs($ctx['heros']);
    $ctx['instance']->update(['pv_body' => 1, 'pv_body_max' => 1]);
    $ctx['groupe']->update(['or' => 100]);
    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 20, 1));

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.faveurs_declenchees.0.type', 'faveur_peacekeeper');

    $direct = collect(app(JournalCombat::class)->depuisResultat($reponse->json('resultat'), 'Albrecht'))->pluck('texte');
    expect($direct->contains(fn (string $t) => str_contains($t, 'Peacekeeper') && str_contains($t, '25 po à la fin')))->toBeTrue();

    $reconnexion = collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['journal_combat'])->pluck('texte');
    expect($reconnexion->contains(fn (string $t) => str_contains($t, 'Peacekeeper')))->toBeTrue();
});

it('PEACEKEEPER compte le héros qui a allumé la BRAISE quand c\'est la braise qui achève la cible', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $ctx['etatHeros']->update(['styles_epuises' => ['air', 'terre', 'eau']]);
    $ctx['instance']->update(['pv_body' => 3, 'pv_body_max' => 3]);
    $ctx['groupe']->update(['or' => 100]);
    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'toucher_brasier', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.degats', 1);

    // Le premier coup (3 → 2) ne tue pas : rien n'est versé, et la braise porte son auteur.
    expect((int) $ctx['instance']->fresh()->degat_differe_personnage_id)->toBe((int) $ctx['heros']->id)
        ->and((int) $ctx['groupe']->fresh()->or)->toBe(100);

    // Fin du tour du héros : la braise tombe (2 PV), achève la cible, et Peacekeeper compte CE héros.
    desFiges(array_fill(0, 60, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect($ctx['instance']->fresh()->etat)->toBe('vaincu')
        ->and($ctx['instance']->fresh()->degat_differe_personnage_id)->toBeNull()
        ->and((int) $ctx['groupe']->fresh()->or)->toBe(100)
        ->and(compteurPeacekeeper($ctx['quete'], $ctx['heros']))->toBe(1);
});

it('HOLD THE LINE est rendu au fil, qu\'il touche (crâne) ou rate', function () {
    $base = [
        'type' => 'faveur_hold_the_line', 'action' => 'hold_the_line',
        'personnage' => 'Albrecht', 'monstre' => 'Gobelin', 'face' => 1,
        'touche' => true, 'degats' => 1, 'pv_body_apres' => 0, 'vaincu' => true,
    ];
    $rendu = fn (array $a) => app(JournalCombat::class)
        ->depuisResultat(['tour_monstres' => ['actions' => [$a]]], 'Albrecht');

    $touche = $rendu($base);
    $rate = $rendu(array_merge($base, [
        'face' => 4, 'touche' => false, 'degats' => 0, 'vaincu' => false, 'pv_body_apres' => 1,
    ]));

    expect($touche[0]['texte'])->toContain('Hold the Line')->toContain('il tombe')
        ->and($touche[0]['ton'])->toBe('mort')
        ->and($rate[0]['texte'])->toContain('aucun crâne')
        ->and($rate[0]['ton'])->toBe('info');
});

it('ANNONCES du hub : entretien et faveur ne valent que pour la DERNIÈRE quête achevée, effet compris', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe);
    $quete = derniereQueteTerminee($groupe);

    Journal::ajouter($groupe, 'systeme', [
        'action' => 'mercenaire_entretien', 'quete_id' => $quete,
        'cout_par_mercenaire' => 10, 'cout_total' => 10, 'or_restant' => 90,
        'payes' => [['id' => 1, 'nom' => 'Fauchard']], 'partis' => [],
    ]);
    Journal::ajouter($groupe, 'systeme', [
        'action' => 'faveur_hopekins', 'quete_id' => $quete, 'faveur' => FaveursHopekins::DEADEYE,
        'faveur_libelle' => 'Deadeye', 'personnage_id' => $heros->id, 'personnage' => 'Albrecht',
    ]);

    $hub = app(EtatGroupe::class)->payload($groupe->fresh())['groupe'];
    expect($hub['mercenaires_entretien']['payes'][0]['nom'])->toBe('Fauchard')
        ->and($hub['faveur_hopekins']['faveur_effet'])->toBe(FaveursHopekins::EFFETS[FaveursHopekins::DEADEYE]);

    // Une quête de plus, achevée SANS entretien ni faveur : le hub ne relit pas l'annonce précédente.
    rendreGardien($groupe, 1);
    $suivant = app(EtatGroupe::class)->payload($groupe->fresh())['groupe'];
    expect($suivant['mercenaires_entretien'])->toBeNull()
        ->and($suivant['faveur_hopekins'])->toBeNull();
});

it('la REPRISE garde l\'auteur de la braise : la mise à mort qu\'elle achève crédite encore Peacekeeper', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine']);
    $ctx['instance']->update(['degat_differe' => 2, 'degat_differe_personnage_id' => $ctx['heros']->id]);

    $snapshot = app(\App\Partie\Sauvegarde::class)->snapshotter($ctx['groupe']->fresh(), 'nouveau_tour');
    app(\App\Partie\Sauvegarde::class)->restaurer($ctx['groupe']->fresh(), $snapshot);

    expect((int) $ctx['instance']->fresh()->degat_differe)->toBe(2)
        ->and((int) $ctx['instance']->fresh()->degat_differe_personnage_id)->toBe((int) $ctx['heros']->id);
});

// ---------------------------------------------------------------------------
// PEACEKEEPER — l'or se verse à la FIN d'une quête GAGNÉE (2026-10-08).
// ---------------------------------------------------------------------------

it('PEACEKEEPER paie 25 po par monstre compté à la FIN d\'une quête gagnée, et l\'annonce au hub', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['groupe']->update(['or' => 100]);
    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);

    app(MoteurDegats::class)->infligerAMonstre($ctx['instance'], 1, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['heros']);
    expect((int) $ctx['groupe']->fresh()->or)->toBe(100)
        ->and(compteurPeacekeeper($ctx['quete'], $ctx['heros']))->toBe(1);

    $resultat = victoireSansButin($ctx['groupe']);

    expect($resultat['peacekeeper']['or_total'])->toBe(25)
        ->and((int) $ctx['groupe']->fresh()->or)->toBe(125);

    // Annoncé au hub comme l'entretien : la DERNIÈRE quête achevée, le détail par héros.
    $hub = app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['groupe'];
    expect($hub['peacekeeper']['or_total'])->toBe(25)
        ->and($hub['peacekeeper']['versements'][0])->toMatchArray(['nom' => 'Albrecht', 'monstres' => 1, 'or' => 25]);
});

it('PEACEKEEPER ne verse RIEN sur une quête PERDUE : le TPK ne touche pas la bourse', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['groupe']->update(['or' => 100]);
    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);

    app(MoteurDegats::class)->infligerAMonstre($ctx['instance'], 1, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['heros']);
    expect(compteurPeacekeeper($ctx['quete'], $ctx['heros']))->toBe(1);

    // Le dénouement d'un TPK est `echouerQuete()`, jamais `terminerQuete()`.
    $groupe = $ctx['groupe']->fresh();
    (new ReflectionMethod(\App\Partie\ResolveurTour::class, 'echouerQuete'))
        ->invoke(app(\App\Partie\ResolveurTour::class), $groupe, $ctx['quete']->fresh(), 'TPK');

    expect($ctx['quete']->fresh()->etat)->toBe('echouee')
        ->and((int) $groupe->fresh()->or)->toBe(100);
});

it('PEACEKEEPER ne paie PAS un héros qui n\'a rien compté, même si son groupe a gagné', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['groupe']->update(['or' => 100]);
    donnerFaveur($ctx['heros'], FaveursHopekins::PEACEKEEPER);

    $resultat = victoireSansButin($ctx['groupe']);

    expect($resultat['peacekeeper'] ?? null)->toBeNull()
        ->and((int) $ctx['groupe']->fresh()->or)->toBe(100);
});
