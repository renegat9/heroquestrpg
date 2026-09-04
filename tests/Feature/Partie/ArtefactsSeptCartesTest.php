<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\Combat;
use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDeterministe;
use App\Engine\ReactionEffet;
use App\Engine\TypeFigurine;
use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\MoteurReactions;
use App\Partie\Salles;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Les SEPT dernières cartes d'artefact portées (2026-09-04).
 *
 * Chacune attendait une mécanique et non un arbitrage : relance conditionnée à
 * la FACE, saut de piège au dé de combat, dé de déplacement supplémentaire,
 * téléportation de groupe, relance imposée à l'attaquant, renvoi d'un sort de
 * Dread, contrôle de monstres par un héros.
 *
 * ⚠ Chaque test doit ROUGIR sans son correctif — c'est la règle du dépôt, et
 * c'est ce qui distingue une carte portée d'une carte annoncée.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

/** Met une pièce au sac puis l'équipe par le vrai service (dés recalculés). */
function porterArtefact(Personnage $p, string $nom, ?string $slot = null): Inventaire
{
    $ligne = Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);

    app(Equipement::class)->equiper($p, $ligne, $slot);

    return $ligne->fresh();
}

/** Le menu courant du héros, tel que la manette le reçoit. */
function optionsMenu(int $groupeId, int $joueurId, int $herosId): array
{
    GenererMenu::dispatchSync($groupeId, $joueurId, $herosId);

    return (array) data_get(Cache::get(GenererMenu::cleMenu($groupeId, $joueurId)), 'menu.options', []);
}

/** Remplace la couche `pieges` de la carte. */
function poserPiegeArtefact(Quete $quete, int $x, int $y, string $nom, string $etat = 'detecte'): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = [[
        'x' => $x, 'y' => $y,
        'piege_id' => Piege::where('nom', $nom)->value('id'),
        'etat' => $etat,
    ]];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

// ---------------------------------------------------------------------------
// 1. Serre du Corbeau — « reroll any 1 Attack die that lands on a black shield »
// ---------------------------------------------------------------------------

it('ne relance QUE le bouclier noir, et un seul, là où Coup puissant relance tous les ratés', function () {
    // Volée de 3 : bouclier noir, bouclier blanc, bouclier noir.
    $volee = fn () => new LanceurDeterministe([6, 4, 6, 1, 4, 4, 4, 4]);

    // La Serre : UN dé, et seulement celui qui montre un bouclier noir. Le
    // quatrième nombre de la file (1 = crâne) est la relance.
    $serre = (new Combat($volee()))->resoudreAttaque(
        desAttaque: 3, desDefense: 0, typeDefenseur: TypeFigurine::Monstre, pvBodyDefenseur: 5,
        relanceFaceAttaque: FaceDeCombat::BouclierNoir, relanceFaceMaximum: 1,
    );

    expect($serre->touches)->toBe(1)
        ->and(array_map(fn ($f) => $f->value, $serre->facesAttaque))
        ->toBe(['crane', 'bouclier_blanc', 'bouclier_noir']);

    // Le mot-clé voisin, lui, relance TOUT ce qui a raté — bouclier blanc
    // compris. Les confondre aurait donné trois relances au lieu d'une.
    $puissant = (new Combat($volee()))->resoudreAttaque(
        desAttaque: 3, desDefense: 0, typeDefenseur: TypeFigurine::Monstre, pvBodyDefenseur: 5,
        relanceDesAttaqueRatee: PHP_INT_MAX,
    );

    expect($puissant->touches)->toBe(3);
});

it('ne relance JAMAIS la face qui touche : contre un éthéré, la Serre se tait', function () {
    // Contre un éthéré c'est le bouclier noir qui blesse. Relancer « les
    // boucliers noirs » y relancerait les réussites — la Serre doit se taire.
    $resultat = (new Combat(new LanceurDeterministe([6, 6, 1, 4, 4, 4])))->resoudreAttaque(
        desAttaque: 2, desDefense: 0, typeDefenseur: TypeFigurine::Monstre, pvBodyDefenseur: 5,
        defenseurEthere: true,
        relanceFaceAttaque: FaceDeCombat::BouclierNoir, relanceFaceMaximum: 1,
    );

    expect($resultat->touches)->toBe(2);
});

it('lit la relance sur L\'ARME EMPLOYÉE, pas sur son porteur', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    porterArtefact($heros, 'Serre du Corbeau', 'arme_principale');

    $options = optionsMenu($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    expect(collect($options)->firstWhere('id', 'attaquer'))->not->toBeNull();

    $des = (int) $heros->fresh()->des_attaque;
    // Tout en boucliers noirs, PUIS un crâne : la relance de la Serre est le
    // seul dé qui peut aller chercher ce crâne.
    desFiges([...array_fill(0, $des, 6), 1, ...array_fill(0, 12, 4)]);

    $faces = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->json('resultat.faces_attaque');

    expect($faces)->toContain('crane')
        ->and(collect($faces)->filter(fn ($f) => $f === 'crane')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 2. Bottes de Lièvre — « jump over one discovered trap per turn »
// ---------------------------------------------------------------------------

it('fait bondir par-dessus un piège qui N\'EST PAS une fosse, une fois par tour', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $heros->id)->firstOrFail();

    // Un alignement libre : le héros, le piège, la réception.
    $saut = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $px = (int) $etat->position_x + $dx;
        $py = (int) $etat->position_y + $dy;
        if (caseQueteLibre($quete, $px, $py) && caseQueteLibre($quete, $px + $dx, $py + $dy)) {
            $saut = ['x' => $px, 'y' => $py, 'vers' => ['x' => $px + $dx, 'y' => $py + $dy]];
            break;
        }
    }
    expect($saut)->not->toBeNull();

    // ⚠ Un piège à LANCES : notre franchissement ordinaire n'accepte que les
    // fosses, et c'est précisément ce que les bottes élargissent.
    poserPiegeArtefact($quete, $saut['x'], $saut['y'], 'Piège à lances');

    $options = optionsMenu($groupe->id, (int) $alice->id, (int) $heros->id);

    // Sans les bottes, RIEN : ni saut ordinaire (ce n'est pas une fosse), ni
    // saut bondissant. C'est la moitié du test qui prouve l'élargissement.
    expect(collect($options)->filter(fn ($o) => str_starts_with((string) $o['id'], 'franchir_'))->all())->toBe([]);

    $bottes = porterArtefact($heros, 'Bottes de Lièvre');

    $options = optionsMenu($groupe->id, (int) $alice->id, (int) $heros->id);
    $bond = collect($options)->firstWhere('id', "franchir_bottes_{$saut['x']}_{$saut['y']}");

    expect($bond)->not->toBeNull()
        ->and($bond['parametres']['bottes'])->toBe($bottes->id)
        // Aucun jet de Body annoncé : la carte demande un dé de combat.
        ->and($bond['jet'] ?? null)->toBeNull();

    desFiges([1]); // un crâne : tout sauf un bouclier noir réussit

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => $bond['id']])
        ->assertStatus(202)->json('resultat');

    expect($resultat['franchi'])->toBeTrue()
        ->and($resultat['des_lances'])->toBe(1)
        ->and($resultat['vers'])->toBe($saut['vers'])
        ->and($resultat['objet'])->toBe('Bottes de Lièvre');

    // « one discovered trap PER TURN » : la fenêtre est fermée, l'option part.
    $options = optionsMenu($groupe->id, (int) $alice->id, (int) $heros->id);
    expect(collect($options)->firstWhere('id', $bond['id']))->toBeNull();
});

it('échoue au bouclier noir, et le héros tombe dans le piège', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $heros->id)->firstOrFail();

    $saut = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $px = (int) $etat->position_x + $dx;
        $py = (int) $etat->position_y + $dy;
        if (caseQueteLibre($quete, $px, $py) && caseQueteLibre($quete, $px + $dx, $py + $dy)) {
            $saut = ['x' => $px, 'y' => $py];
            break;
        }
    }

    poserPiegeArtefact($quete, $saut['x'], $saut['y'], 'Fosse');
    porterArtefact($heros, 'Bottes de Lièvre');

    $options = optionsMenu($groupe->id, (int) $alice->id, (int) $heros->id);
    $bond = collect($options)->firstWhere('id', "franchir_bottes_{$saut['x']}_{$saut['y']}");
    expect($bond)->not->toBeNull();

    // Le saut ORDINAIRE reste offert sur une fosse : les bottes s'ajoutent, la
    // règle des autres héros ne bouge pas.
    expect(collect($options)->firstWhere('id', "franchir_{$saut['x']}_{$saut['y']}"))->not->toBeNull();

    desFiges([6, ...array_fill(0, 12, 4)]); // bouclier noir : la seule face qui rate

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => $bond['id']])
        ->assertStatus(202)->json('resultat');

    expect($resultat['franchi'])->toBeFalse()
        ->and($resultat['vers'])->toBe(['x' => $saut['x'], 'y' => $saut['y']]);
});

// ---------------------------------------------------------------------------
// 3. Bottes elfiques — un dé de plus, usure sur un DOUBLE (arbitrage de René)
// ---------------------------------------------------------------------------

it('ajoute un second dé de déplacement à l\'elfe, et s\'use quand les deux tombent pareil', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $elfe = creerHeros($alice, $groupe, 'Sylwen', 1, ['classe' => 'elfe']);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $elfe->id)->firstOrFail();

    $bottes = porterArtefact($elfe, 'Bottes elfiques');
    $base = (int) $elfe->fresh()->deplacement_base;

    // ⚠ Le lancement de la quête a DÉJÀ généré un menu, donc déjà lancé le dé
    // du tour — avant que les bottes ne soient chaussées. On rouvre le tour,
    // sans quoi on mesurerait le jet d'un elfe pieds nus.
    //
    // ⚠ Par le constructeur de requêtes, jamais par `$etat->update()` : le
    // modèle en mémoire croit déjà valoir `null` (c'est le JOB qui a écrit la
    // vraie valeur), donc Eloquent le juge propre et n'écrit rien.
    EtatPersonnageQuete::whereKey($etat->id)->update(['deplacement_tour' => null]);

    // Deux dés DIFFÉRENTS : les bottes tiennent, et le total les additionne.
    desFiges([2, 5, ...array_fill(0, 20, 4)]);
    optionsMenu($groupe->id, (int) $alice->id, (int) $elfe->id);

    expect((int) $etat->fresh()->deplacement_tour)->toBe($base + 7)
        ->and(Inventaire::find($bottes->id))->not->toBeNull();

    // Tour suivant : deux dés IDENTIQUES — les bottes rendent l'âme.
    EtatPersonnageQuete::whereKey($etat->id)
        ->update(['deplacement_tour' => null, 'a_joue' => false, 'a_deplace' => false]);
    desFiges([3, 3, ...array_fill(0, 20, 4)]);
    optionsMenu($groupe->id, (int) $alice->id, (int) $elfe->id);

    expect((int) $etat->fresh()->deplacement_tour)->toBe($base + 6)
        ->and(Inventaire::find($bottes->id))->toBeNull();

    // Et l'usure est DITE : une pièce qui disparaît sans un mot serait
    // l'effet automatique inannoncé que le projet refuse partout.
    expect($groupe->evenements()->where('type', 'systeme')->get()
        ->contains(fn ($e) => ($e->payload['type'] ?? null) === 'objet_use'))->toBeTrue();
});

it('réserve les Bottes elfiques à l\'elfe, et laisse les Bottes de Lièvre à tous', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $nain = creerHeros($alice, $groupe, 'Borin', 1, ['classe' => 'nain']);

    expect(fn () => porterArtefact($nain, 'Bottes elfiques'))
        ->toThrow(ValidationException::class);

    expect(porterArtefact($nain, 'Bottes de Lièvre')->emplacement)->toBe('bottes');
});

// ---------------------------------------------------------------------------
// 4. Anneau du Retour — « returns all heroes that the ring wearer can see »
// ---------------------------------------------------------------------------

it('ramène au départ le porteur ET les héros qu\'il voit, une seule fois', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $porteur = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $compagnon = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $spawns = $quete->carte->grille['spawn_heros'];
    $etatPorteur = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $porteur->id)->firstOrFail();
    $etatCompagnon = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $compagnon->id)->firstOrFail();

    // On les éloigne tous les deux du départ, côte à côte pour qu'ils se voient.
    $loin = caseAdjacenteLibre($quete, (int) $spawns[0]['x'], (int) $spawns[0]['y']);
    $etatPorteur->update(['position_x' => $loin['x'], 'position_y' => $loin['y']]);
    $voisin = caseAdjacenteLibre($quete, $loin['x'], $loin['y']);
    $etatCompagnon->update(['position_x' => $voisin['x'], 'position_y' => $voisin['y']]);

    $anneau = porterArtefact($porteur, 'Anneau du Retour');

    $options = optionsMenu($groupe->id, (int) $alice->id, (int) $porteur->id);
    $utiliser = collect($options)->firstWhere('id', 'utiliser_objet');
    $entree = collect($utiliser['parametres']['objets'] ?? [])
        ->firstWhere('inventaire_id', $anneau->id);

    expect($entree)->not->toBeNull()->and($entree['detail'])->toBe('Activer');

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet', 'parametres' => ['cle' => $entree['cle']],
    ])->assertStatus(202)->json('resultat');

    $depart = collect($spawns)->map(fn ($s) => [(int) $s['x'], (int) $s['y']])->all();

    expect(collect($resultat['ramenes'])->pluck('nom')->sort()->values()->all())
        ->toBe(['Albrecht', 'Brunhilde'])
        ->and([(int) $etatPorteur->fresh()->position_x, (int) $etatPorteur->fresh()->position_y])
        ->toBeIn($depart)
        ->and([(int) $etatCompagnon->fresh()->position_x, (int) $etatCompagnon->fresh()->position_y])
        ->toBeIn($depart)
        // Deux héros, deux cases : personne n'est empilé sur personne.
        ->and([(int) $etatPorteur->fresh()->position_x, (int) $etatPorteur->fresh()->position_y])
        ->not->toBe([(int) $etatCompagnon->fresh()->position_x, (int) $etatCompagnon->fresh()->position_y]);

    // « It can only be used once » — un TOTAL, pas une cadence : la charge est
    // consommée et l'option disparaît.
    expect((int) Inventaire::find($anneau->id)->charges)->toBe(0);
});

// ---------------------------------------------------------------------------
// 5. Bouclier de l'Aube — « force the attacking monster to reroll all Attack dice »
// ---------------------------------------------------------------------------

it('offre au PORTEUR de faire relancer le monstre, même quand c\'est un autre héros qui encaisse', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $victime = $ctx['heros'];

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $porteur = creerHeros($bob, $ctx['groupe'], 'Roland', 2, ['classe' => 'chevalier']);
    $ctx['quete']->etatsPersonnages()->create([
        'personnage_id' => $porteur->id, 'position_x' => $ctx['etatHeros']->position_x,
        'position_y' => (int) $ctx['etatHeros']->position_y,
    ]);
    porterArtefact($porteur, "Bouclier de l'Aube", 'arme_secondaire');

    $etatPorteur = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)
        ->where('personnage_id', $porteur->id)->firstOrFail();

    app(MoteurDegats::class)->infligerAHeros($victime, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, [
        'instance_id' => (int) $ctx['instance']->id, 'des_attaque' => 3, 'des_defense' => 2,
    ]);

    // ⚠ L'offre est déposée chez le PORTEUR, jamais chez la victime : « any one
    // hero », et sans condition d'adjacence contrairement à la Parade.
    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();

    $attente = $etatPorteur->fresh()->reaction_en_attente;
    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe(ReactionEffet::RELANCE_ATTAQUE)
        ->and($attente['victime_id'])->toBe($victime->id);

    $avant = (int) $victime->fresh()->pv_body;

    // La volée rejouée : trois crânes contre deux boucliers blancs → 1 dégât,
    // au lieu des 2 qu'on vient de rendre.
    desFiges([1, 1, 1, 4, 4, ...array_fill(0, 12, 4)]);

    $reaction = $this->actingAs($bob, 'joueur')
        ->postJson('/api/groupes/table-1/reaction', ['personnage_id' => $porteur->id, 'accepte' => true])
        ->assertOk()->json('reaction');

    expect($reaction['action'])->toBe(ReactionEffet::RELANCE_ATTAQUE)
        ->and($reaction['degats_annules'])->toBe(2)
        ->and($reaction['degats_relance'])->toBe(1)
        ->and((int) $victime->fresh()->pv_body)->toBe($avant + 1);

    // La fenêtre « une fois par quête » est fermée : un second coup ne la
    // rouvre pas.
    app(MoteurDegats::class)->infligerAHeros($victime, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, [
        'instance_id' => (int) $ctx['instance']->id, 'des_attaque' => 3, 'des_defense' => 2,
    ]);

    expect($etatPorteur->fresh()->reaction_en_attente)->toBeNull();
});

it('publie les DEUX volées du coup de monstre, sans quoi il n\'y aurait rien à relancer', function () {
    // Le contexte vient du vrai chemin (`resoudreAttaqueMonstre`) : sans
    // `des_attaque`/`des_defense`, le Bouclier de l'Aube n'aurait su que
    // recomposer sept bonus à la main — une seconde vérité, donc une dérive.
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    porterArtefact($ctx['heros'], "Bouclier de l'Aube", 'arme_secondaire');

    // Le héros passe : la phase des monstres suit, le gobelin est au contact.
    desFiges(array_fill(0, 80, 1)); // que des crânes : le coup portera
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $attente = $ctx['etatHeros']->fresh()->reaction_en_attente;

    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe(ReactionEffet::RELANCE_ATTAQUE)
        ->and($attente['contexte']['des_attaque'])->toBeGreaterThan(0)
        ->and($attente['contexte'])->toHaveKey('des_defense');
});

// ---------------------------------------------------------------------------
// 6. Bâton Ancien — « reflect any monster's spell back at the spellcaster »
// ---------------------------------------------------------------------------

it('renvoie le sort au lanceur ET à tous les monstres de sa salle', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    $alice = $ctx['alice'];
    $elfe = creerHeros($alice, $ctx['groupe'], 'Sylwen', 2, ['classe' => 'elfe']);
    $ctx['quete']->etatsPersonnages()->create([
        'personnage_id' => $elfe->id,
        'position_x' => (int) $ctx['etatHeros']->position_x,
        'position_y' => (int) $ctx['etatHeros']->position_y,
    ]);
    $baton = porterArtefact($elfe, 'Bâton Ancien');

    // Un second gobelin dans la salle du lanceur : la carte les prend TOUS.
    $lanceur = $ctx['instance'];
    $voisin = caseAdjacenteLibre($ctx['quete'], (int) $lanceur->position_x, (int) $lanceur->position_y);
    $comparse = $ctx['quete']->instancesMonstres()->create([
        'monstre_id' => $lanceur->monstre_id, 'pv_body' => 4, 'pv_mind' => 1,
        'etat' => 'actif', 'revele' => true,
        'position_x' => $voisin['x'], 'position_y' => $voisin['y'],
    ]);

    $victime = $ctx['heros'];
    app(MoteurDegats::class)->infligerAHeros($victime, 2, MoteurDegats::SOURCE_SORT_DREAD, [
        'sort' => 'Trait de Chaos', 'lanceur_id' => (int) $lanceur->id, 'des_degats' => 3,
    ]);

    $etatElfe = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)
        ->where('personnage_id', $elfe->id)->firstOrFail();

    $attente = $etatElfe->fresh()->reaction_en_attente;
    expect($attente)->not->toBeNull()->and($attente['action'])->toBe(ReactionEffet::REFLET_SORT);

    $avant = (int) $victime->fresh()->pv_body;
    $pvComparse = (int) $comparse->fresh()->pv_body;

    desFiges(array_fill(0, 60, 1)); // que des crânes : le renvoi porte partout

    $reaction = $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $elfe->id, 'accepte' => true,
    ])->assertOk()->json('reaction');

    expect($reaction['action'])->toBe(ReactionEffet::REFLET_SORT)
        ->and((int) $victime->fresh()->pv_body)->toBe($avant + 2)
        ->and(collect($reaction['reflet']['effets'])->pluck('instance_id')->all())
        ->toContain($lanceur->id, $comparse->id)
        ->and((int) $comparse->fresh()->pv_body)->toBeLessThan($pvComparse);

    // Cinq usages : le bâton en a dépensé un.
    expect((int) Inventaire::find($baton->id)->charges)->toBe(4);
});

it('renvoie aussi un sort de CONTRÔLE, qui ne blesse personne et n\'atteint donc jamais MoteurDegats', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    $elfe = creerHeros($ctx['alice'], $ctx['groupe'], 'Sylwen', 2, ['classe' => 'elfe']);
    $ctx['quete']->etatsPersonnages()->create([
        'personnage_id' => $elfe->id,
        'position_x' => (int) $ctx['etatHeros']->position_x,
        'position_y' => (int) $ctx['etatHeros']->position_y,
    ]);
    porterArtefact($elfe, 'Bâton Ancien');

    // La pose passe par la table, comme `MoteurDread::poserConditionHeros()`
    // le fait en privé : ce qu'on éprouve ici est le RETRAIT par le bâton.
    $victime = $ctx['heros'];
    $victime->conditions()->attach(
        Condition::where('nom', 'Endormi')->firstOrFail()->id,
        ['duree' => 0, 'source' => 'sort_dread:Sommeil'],
    );

    $ouverte = app(MoteurReactions::class)->proposerRefletControle($victime, [
        'sort' => 'Sommeil',
        'lanceur_id' => (int) $ctx['instance']->id,
        'condition' => 'Endormi',
    ]);

    expect($ouverte)->toBeTrue();

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $elfe->id, 'accepte' => true,
    ])->assertOk()->assertJsonPath('reaction.condition_annulee', 'Endormi');

    // Le héros dort de nouveau debout, et c'est le gobelin qui s'écroule.
    expect(app(MoteurDread::class)->herosSousCondition($victime->fresh(), 'Endormi'))->toBeFalse()
        ->and($ctx['instance']->fresh()->habillage['conditions']['endormi'] ?? null)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 7. Baguette d'Os — « control all skeletons in one room for one turn »
// ---------------------------------------------------------------------------

it('enrôle les squelettes de la salle, qui jouent à la suite du tour de leur maître', function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    $heros = $ctx['heros'];
    $ennemi = $ctx['instance'];

    // ⚠ Un orque de catalogue a 1 PV : le premier squelette le tuerait et le
    // second n'aurait plus rien à frapper. On l'épaissit pour que les DEUX
    // jouent — c'est le nombre d'actions qu'on mesure.
    $ennemi->update(['pv_body' => 6, 'pv_body_max' => 6]);

    $squelette = Monstre::where('nom_base', 'Squelette')->firstOrFail();
    $salles = (array) data_get($ctx['quete']->carte->grille, 'salles', []);
    $salle = Salles::indexDe($salles, (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    // Deux squelettes au contact de l'orque, dans la salle du héros.
    $places = [];
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $x = (int) $ennemi->position_x + $dx;
        $y = (int) $ennemi->position_y + $dy;
        if (count($places) < 2
            && caseQueteLibre($ctx['quete'], $x, $y)
            && Salles::indexDe($salles, $x, $y) === $salle) {
            $places[] = ['x' => $x, 'y' => $y];
        }
    }
    expect(count($places))->toBe(2);

    $sbires = collect($places)->map(fn ($place) => $ctx['quete']->instancesMonstres()->create([
        'monstre_id' => $squelette->id, 'pv_body' => 1, 'pv_mind' => 0,
        'etat' => 'actif', 'revele' => true,
        'position_x' => $place['x'], 'position_y' => $place['y'],
    ]));

    $baguette = porterArtefact($heros, "Baguette d'Os");

    $options = optionsMenu($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    $entree = collect(collect($options)->firstWhere('id', 'utiliser_objet')['parametres']['objets'] ?? [])
        ->firstWhere('inventaire_id', $baguette->id);

    // ⚠ La baguette ne VISE personne : elle fait changer de camp, elle ne
    // désigne pas une victime.
    expect($entree)->not->toBeNull()
        ->and($entree)->not->toHaveKey('cibles');

    $pvAvant = (int) $ennemi->fresh()->pv_body;

    desFiges(array_fill(0, 80, 1)); // que des crânes : les squelettes touchent

    $resultat = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet', 'parametres' => ['cle' => $entree['cle']],
    ])->assertStatus(202)->json('resultat');

    expect(collect($resultat['controles'])->pluck('instance_id')->sort()->values()->all())
        ->toBe($sbires->pluck('id')->sort()->values()->all());

    // ⚠ Ils n'ont PAS encore joué : le héros garde son déplacement (E1), et la
    // carte dit « à la suite du tour ». Les faire frapper à l'incantation les
    // enverrait devant un maître qui n'a pas bougé.
    expect($resultat)->not->toHaveKey('tour_sbires')
        ->and((int) $ennemi->fresh()->pv_body)->toBe($pvAvant);

    foreach ($sbires as $s) {
        expect((int) $s->fresh()->controle_par)->toBe($heros->id)
            ->and((bool) $s->fresh()->controle_agi)->toBeFalse();
    }

    // Le héros termine son tour : SES sbires jouent, un à un.
    $suite = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat');

    $actions = $suite['tour_sbires']['actions'] ?? [];

    expect($actions)->toHaveCount(2)
        // Ordre d'initiative des monstres, celui de la phase de Zargon.
        ->and(collect($actions)->pluck('instance_id')->all())
        ->toBe($sbires->pluck('id')->values()->all())
        ->and(collect($actions)->every(fn ($a) => ($a['attaque'] ?? false) === true))->toBeTrue()
        // Ils ont frappé l'orque, pas leur voisin de camp.
        ->and(collect($actions)->every(fn ($a) => $a['cible']['instance_id'] === $ennemi->id))->toBeTrue()
        ->and((int) $ennemi->fresh()->pv_body)->toBeLessThan($pvAvant);

    // « works only once per quest » : la fenêtre est fermée.
    $options = optionsMenu($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    $apres = collect(collect($options)->firstWhere('id', 'utiliser_objet')['parametres']['objets'] ?? [])
        ->firstWhere('inventaire_id', $baguette->id);

    expect($apres)->toBeNull();

    // ⚠ « for ONE turn » : le round rouvert, ils redeviennent des monstres —
    // et c'est l'ouverture du tour qui les libère, rien d'autre.
    foreach ($sbires as $s) {
        expect($s->fresh()->controle_par)->toBeNull();
    }
});

it('ne fait jouer un sbire QU\'UNE fois par round : enrôlé, il ne rejoue pas côté Zargon', function () {
    // Sans le filtre `whereNull('controle_par')` dans `phaseMonstres()`, le
    // squelette agirait deux fois dans le même round — dont une contre ceux
    // qu'il vient d'aider.
    $ctx = demarrerQueteAvecMonstre('Orque');
    $heros = $ctx['heros'];

    $squelette = Monstre::where('nom_base', 'Squelette')->firstOrFail();
    $place = caseAdjacenteLibre($ctx['quete'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);

    $sbire = $ctx['quete']->instancesMonstres()->create([
        'monstre_id' => $squelette->id, 'pv_body' => 1, 'pv_mind' => 0,
        'etat' => 'actif', 'revele' => true,
        'position_x' => $place['x'], 'position_y' => $place['y'],
    ]);

    porterArtefact($heros, "Baguette d'Os");
    $sbire->update(['controle_par' => $heros->id, 'controle_agi' => true]);

    $pvHeros = (int) $heros->fresh()->pv_body;

    desFiges(array_fill(0, 120, 1)); // que des crânes : tout coup porterait
    $suite = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat');

    // Le squelette enrôlé n'apparaît pas dans la phase des monstres.
    //
    // ⚠ On lit `monstre` (le nom affiché) et NON `instance_id` : les actions de
    // la phase de Zargon ne portent pas d'id, et une première version de ce
    // test cherchait donc une clé absente — elle passait avec ET sans le
    // filtre, ce qui est exactement la garantie vide que le dépôt refuse.
    $acteurs = collect($suite['tour_monstres']['actions'] ?? [])->pluck('monstre')->filter()->all();

    expect($acteurs)->not->toBeEmpty('la phase des monstres n\'a rien joué : le test ne prouverait rien')
        ->and($acteurs)->not->toContain($sbire->nomAffiche());

    // …et il est libéré à l'ouverture du round suivant.
    expect($sbire->fresh()->controle_par)->toBeNull()
        ->and((bool) $sbire->fresh()->controle_agi)->toBeFalse();

    // L'orque, lui, a bien frappé : la phase des monstres a joué.
    expect((int) $heros->fresh()->pv_body)->toBeLessThan($pvHeros);
});

// ---------------------------------------------------------------------------
// Régression : le fatal des Cendres du Phénix
// ---------------------------------------------------------------------------

it('ne plante plus quand les Cendres du Phénix roulent leur dé de destruction', function () {
    // `$payload += …` s'exécutait AVANT que `$payload` n'existe : `null + array`
    // est un TypeError, et l'artefact plantait à l'instant précis où il sauvait
    // un héros. Aucun test ne passait par cette branche.
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $cendres = porterArtefact($heros, 'Cendres du Phénix');

    $heros->update(['pv_body' => 1]);
    app(MoteurDegats::class)->infligerAHeros($heros, 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, [
        'instance_id' => (int) $ctx['instance']->id,
    ]);

    $attente = $ctx['etatHeros']->fresh()->reaction_en_attente;
    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe(ReactionEffet::PLANCHER_PV)
        ->and($attente['artefact'])->toBe($cendres->id);

    desFiges([5, ...array_fill(0, 8, 4)]); // 5 : « on a 5 or 6, this artifact is lost »

    $reaction = $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => true,
    ])->assertOk()->json('reaction');

    expect((int) $heros->fresh()->pv_body)->toBe(1)
        ->and($reaction['artefact_perdu'])->toBeTrue()
        ->and(Inventaire::find($cendres->id))->toBeNull();
});
