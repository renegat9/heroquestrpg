<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\EtatPersonnageQuete;
use App\Models\ForgeAmelioration;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Forge;
use App\Partie\JournalCombat;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\ForgeAmeliorationSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Forge du Nain (nœud d'arbre, doc 01 §6 + doc 04 §4) : améliore
 * DÉFINITIVEMENT un exemplaire d'équipement au hub, contre de l'or commun.
 * Les 6 améliorations du catalogue sont désormais TOUTES applicables
 * (2026-09-19) — Affûtée/Renforcée (bonus de dés) comme Perforante, Cruelle,
 * Allégée et Gardée, dont ce fichier prouve maintenant l'effet EN JEU.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        ForgeAmeliorationSeeder::class,
    ]);
});

/** Équipe l'objet nommé dans l'emplacement donné, sans passer par la maîtrise. */
function equiperPourForge(Personnage $heros, string $nomObjet, string $emplacement): Inventaire
{
    $heros->inventaire()->where('emplacement', $emplacement)->delete();

    $ligne = Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);

    app(Equipement::class)->recalculerCombat($heros->refresh());

    return $ligne->fresh();
}

/** Force et applique une amélioration de Forge sur une ligne, sans passer par l'API (déjà couvert ci-dessus). */
function forgerPourTest(Personnage $forgeron, Inventaire $ligne, string $nomAmelioration): Inventaire
{
    $groupe = $forgeron->groupeActif;
    $groupe->update(['or' => 5000]);

    $amelioration = ForgeAmelioration::where('nom', $nomAmelioration)->firstOrFail();

    return app(Forge::class)->appliquer($groupe->fresh(), $ligne, $amelioration);
}

function sacDeForge(Personnage $p, string $nomObjet, string $emplacement = 'sac'): Inventaire
{
    $objet = Objet::where('nom', $nomObjet)->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => $objet->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

function donneForge(Personnage $nain): void
{
    $nain->competences()->attach(Competence::where('classe', 'nain')->where('nom', 'Forge')->value('id'));
}

it('applique Affûtée (+1 dé d\'attaque) à une arme du sac, débite la bourse commune', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Épée courte'); // effet des_attaque: 2, dans le sac (non équipée)

    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id,
        'inventaire_id' => $ligne->id,
        'amelioration_id' => $affutee->id,
    ])->assertCreated()
        ->assertJsonPath('inventaire.ameliorations.0.nom', 'Affûtée')
        ->assertJsonPath('groupe.or', 500 - $affutee->prix);

    expect($groupe->fresh()->or)->toBe(500 - $affutee->prix)
        ->and($nain->fresh()->des_attaque)->toBe(3); // non équipée : pas d'effet immédiat sur les dés
});

it('applique immédiatement le bonus si la pièce est DÉJÀ équipée', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain', 'des_defense' => 2]);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Cotte de mailles', 'armure'); // équipée d'emblée, des_defense: 1

    $renforcee = ForgeAmelioration::where('nom', 'Renforcée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id,
        'inventaire_id' => $ligne->id,
        'amelioration_id' => $renforcee->id,
    ])->assertCreated();

    // 2 (base) + 1 (cotte de mailles) + 1 (Renforcée). L'ancienne attente était
    // 3 : le fixture pose la pièce directement dans le slot sans passer par
    // Equipement::equiper(), si bien que le +1 de la cotte elle-même n'était
    // jamais appliqué. Le recalcul complet (l'attaque venant désormais de
    // l'arme) compte toutes les pièces PORTÉES, ce qui corrige aussi ce trou.
    expect($nain->fresh()->des_defense)->toBe(4);
});

it('le bonus de Forge s\'applique à l\'équipement ultérieur d\'une pièce améliorée dans le sac', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Épée courte'); // effet des_attaque: 2, non équipée
    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertCreated();

    expect($nain->fresh()->des_attaque)->toBe(2); // toujours dans le sac, aucun effet

    (new Equipement)->equiper($nain, $ligne->fresh());

    // L'épée courte FIXE l'attaque à 2, puis Affûtée ajoute +1 → 3.
    // (Avant : 2 de classe + 2 d'objet + 1 = 5.) Le bonus de Forge suit bien
    // l'objet à l'équipement.
    expect($nain->fresh()->des_attaque)->toBe(3);
});

it('le Nain peut forger l\'équipement d\'un AUTRE héros actif du groupe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $barbare = creerHeros($bob, $groupe, 'Albrecht', 2, ['des_attaque' => 3]);
    $ligne = sacDeForge($barbare, 'Épée large', 'arme_principale'); // effet des_attaque: 3

    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id,
        'inventaire_id' => $ligne->id,
        'amelioration_id' => $affutee->id,
    ])->assertCreated();

    expect($barbare->fresh()->des_attaque)->toBe(4); // 3 base + 1, immédiat (équipée)
});

it('refuse sans le nœud Forge, hors du hub, ou sur un objet déjà amélioré', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 5000]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    $ligne = sacDeForge($nain, 'Épée courte');
    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    // Sans le nœud.
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422);

    donneForge($nain);

    // Une fois appliquée, refuse une seconde amélioration sur le même objet.
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertCreated();
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422);

    // Hors du hub : refusé.
    $groupe->update(['phase' => 'quete']);
    $autre = sacDeForge($nain, 'Épée large');
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $autre->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422);
});

it('refuse une bourse commune insuffisante et une catégorie incompatible (amélioration d\'armure sur une arme)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 10]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Épée courte');
    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    // Bourse commune insuffisante (10 < 120).
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422);

    $groupe->update(['or' => 5000]);
    $renforcee = ForgeAmelioration::where('nom', 'Renforcée')->firstOrFail(); // cible : armure

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $renforcee->id,
    ])->assertStatus(422);
});

it('refuse d\'améliorer un ARTEFACT (rareté unique)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 5000]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);

    // Un artefact est déjà au sommet de la courbe : la Forge n'y ajoute rien.
    $ligne = sacDeForge($nain, 'Lame des Esprits');
    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors('inventaire_id');

    expect($ligne->fresh()->ameliorations ?? [])->toBe([]);
});

// =====================================================================
// `/moi` PUBLIE LA DÉCISION (René, 2026-09-18 : « ajoute la modification dans
// les détails d'un objet qui peut être amélioré [...] sans oublier d'afficher
// l'amélioration faite aux objets déjà forgés »). Deux champs par ligne
// d'inventaire : `ameliorations` (déjà posées, lisibles) et `forgeable` — la
// DÉCISION elle-même, jamais ses ingrédients (rareté, phase, nœud du porteur)
// à recombiner côté manette. `forge_catalogue` EST la liste blanche que
// `POST /forge` acceptera pour `amelioration_id` sur cette pièce.
// =====================================================================

it('GET /api/forge rend un catalogue lisible (nom, cible, prix, effet traduit)', function () {
    connecterJoueur('alice');

    $catalogue = collect($this->getJson('/api/forge')->assertOk()->json('ameliorations'));

    expect($catalogue->pluck('nom')->all())->toContain('Affûtée', 'Renforcée');

    $affutee = $catalogue->firstWhere('nom', 'Affûtée');
    expect($affutee['cible'])->toBe('arme')
        ->and($affutee['prix'])->toBe(150)
        ->and($affutee['effet'])->toBe(['bonus_des_attaque' => 1]);
});

it('/moi publie forgeable=true et un catalogue compatible sur une pièce forgeable, confronté à POST /forge dans les deux sens', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Épée courte');

    $sac = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.equipement.sac'));
    $item = $sac->firstWhere('inventaire_id', $ligne->id);

    expect($item['forgeable'])->toBeTrue()
        ->and($item['ameliorations'])->toBe([])
        // Les 4 améliorations qui ne faisaient rien sont désormais réelles :
        // une arme non forgée se voit donc offrir les TROIS de sa catégorie
        // (Affûtée, Perforante, Cruelle), plus Renforcée/Allégée/Gardée pour
        // une armure.
        ->and(collect($item['forge_catalogue'])->pluck('nom')->all())->toBe(['Affûtée', 'Perforante', 'Cruelle']);

    $option = $item['forge_catalogue'][0];
    expect($option['prix'])->toBe(150)
        ->and($option['avantages'])->toBe(["+1 dé d'attaque"]);

    // Sens 1 : `forgeable` dit VRAI → POST accepte exactement l'option publiée.
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $option['id'],
    ])->assertCreated();

    // Sens 2 : relu ensuite, `forgeable` dit FAUX (déjà améliorée, un objet ne
    // l'est qu'une fois) — et POST refuse désormais la même requête.
    $sac2 = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.equipement.sac'));
    $item2 = $sac2->firstWhere('inventaire_id', $ligne->id);
    expect($item2['forgeable'])->toBeFalse()
        ->and($item2['forge_catalogue'])->toBe([])
        ->and($item2['ameliorations'])->toBe([['nom' => 'Affûtée', 'avantages' => ["+1 dé d'attaque"]]]);

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $option['id'],
    ])->assertStatus(422);
});

it('/moi passe forgeable à FAUX hors du hub, et POST refuse une option pourtant vue au hub', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Épée courte');

    $avant = collect($this->getJson('/api/moi')->json('joueur.personnages.0.equipement.sac'))
        ->firstWhere('inventaire_id', $ligne->id);
    expect($avant['forgeable'])->toBeTrue();
    $optionId = $avant['forge_catalogue'][0]['id'];

    $groupe->update(['phase' => 'quete']);

    $apres = collect($this->getJson('/api/moi')->json('joueur.personnages.0.equipement.sac'))
        ->firstWhere('inventaire_id', $ligne->id);
    expect($apres['forgeable'])->toBeFalse()
        ->and($apres['forge_catalogue'])->toBe([]);

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $optionId,
    ])->assertStatus(422);
});

it('/moi passe forgeable à FAUX pour un joueur qui n\'a pas de forgeron, même si le groupe en a un', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $barbare = creerHeros($bob, $groupe, 'Albrecht', 2, ['des_attaque' => 3]);
    $ligne = sacDeForge($barbare, 'Épée large', 'arme_principale');

    // BOB regarde SA propre épée, encore vierge : le groupe a bien un
    // forgeron (Dorin), mais BOB n'en contrôle aucun lui-même — exactement
    // ce que `POST /forge` vérifie (`personnage_id` doit être À LUI).
    test()->actingAs($bob, 'joueur');
    $arme = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.equipement.armes'))
        ->firstWhere('inventaire_id', $ligne->id);

    expect($arme['forgeable'])->toBeFalse()
        ->and($arme['ameliorations'])->toBe([]);
});

it('/moi passe forgeable à FAUX sur un artefact (rareté unique)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 5000]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Lame des Esprits');

    $item = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.equipement.sac'))
        ->firstWhere('inventaire_id', $ligne->id);

    expect($item['forgeable'])->toBeFalse()
        ->and($item['forge_catalogue'])->toBe([])
        ->and($item['ameliorations'])->toBe([]);
});

it('l\'amélioration déjà posée reste visible dans /moi même pour un joueur qui n\'a ni le nœud Forge ni de nain', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $barbare = creerHeros($bob, $groupe, 'Albrecht', 2, ['des_attaque' => 3]);
    $ligne = sacDeForge($barbare, 'Épée large', 'arme_principale');
    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    // Dorin (le nain d'Alice) forge l'épée d'Albrecht (le barbare de Bob) —
    // le Nain travaille l'équipement de ses compagnons, pas seulement le sien.
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertCreated();

    // BOB se connecte : ni le nœud Forge, ni de nain — il voit quand même
    // l'amélioration posée par Dorin sur SA propre épée.
    test()->actingAs($bob, 'joueur');
    $arme = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.equipement.armes'))
        ->firstWhere('inventaire_id', $ligne->id);

    expect($arme['ameliorations'])->toBe([['nom' => 'Affûtée', 'avantages' => ["+1 dé d'attaque"]]])
        ->and($arme['forgeable'])->toBeFalse();
});

// =====================================================================
// LES QUATRE AMÉLIORATIONS QUI NE FAISAIENT RIEN (2026-09-19) — Perforante,
// Cruelle, Allégée, Gardée. Chacune a maintenant un lecteur sur une couture
// EXISTANTE (voir App\Engine\MotsClesEquipement), et ce bloc en apporte la
// preuve EN JEU : par la vraie route d'attaque pour Perforante, sur le vrai
// résolveur pour Cruelle (le compteur « une fois par COMBAT » traverse
// plusieurs frappes), par la vraie phase des monstres pour Gardée.
// =====================================================================

it('Perforante annule un bouclier de la défense de la cible, et le fil de combat le dit', function () {
    $ctx = demarrerQueteAvecMonstre('Squelette');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = $ctx;

    $ligneArme = equiperPourForge($heros, 'Épée courte', 'arme_principale'); // des_attaque: 2
    forgerPourTest($heros, $ligneArme, 'Perforante');

    // Squelette : 2 dés de défense. SANS Perforante, 2 crânes − 2 boucliers
    // noirs = 0 dégât. AVEC (1 bouclier annulé), 1 dégât — qui suffit à
    // abattre le squelette (1 PV de son bloc catalogue).
    desFiges([1, 1, 6, 6, 6, 6]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $instance->id],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.boucliers_annules', 1)
        ->assertJsonPath('resultat.degats', 1)
        ->assertJsonPath('resultat.cible_vaincue', true);

    $lignes = app(JournalCombat::class)->depuisResultat($reponse->json('resultat'), $heros->nom);
    expect(collect($lignes)->pluck('texte')->implode(' | '))->toContain('Perforante annule 1 bouclier');
});

it('Perforante n\'annule rien quand la cible n\'a obtenu aucun bouclier (rien à annoncer)', function () {
    $ctx = demarrerQueteAvecMonstre('Squelette');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = $ctx;

    $ligneArme = equiperPourForge($heros, 'Épée courte', 'arme_principale');
    forgerPourTest($heros, $ligneArme, 'Perforante');

    // Défense sans le moindre bouclier noir : rien à annuler.
    desFiges([1, 1, 1, 1, 1, 1]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $instance->id],
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.boucliers_annules', 0);

    $lignes = app(JournalCombat::class)->depuisResultat($reponse->json('resultat'), $heros->nom);
    expect(collect($lignes)->pluck('texte')->implode(' | '))->not->toContain('Perforante');
});

it('Cruelle relance un dé d\'attaque raté, une fois par COMBAT — pas par tour, pas par quête', function () {
    $ctx = demarrerQueteAvecMonstre('Squelette');
    ['groupe' => $groupe, 'heros' => $heros, 'instance' => $instance, 'quete' => $quete, 'etatHeros' => $etat] = $ctx;
    $instance->update(['pv_body' => 5]); // survit à plusieurs coups, pour rejouer dans le même combat

    $ligneArme = equiperPourForge($heros, 'Épée courte', 'arme_principale'); // des_attaque: 2
    forgerPourTest($heros, $ligneArme, 'Cruelle');

    // ⚠ `ResolveurTour` reçoit son `LanceurDes` par injection de CONSTRUCTEUR :
    // le résoudre une seule fois puis appeler `desFiges()` ensuite figerait la
    // file du PREMIER coup sur le lanceur (réel, non déterministe) capturé
    // avant. Chaque coup résout donc l'instance À NOUVEAU, après avoir posé
    // ses dés — même règle que suivent déjà les tests qui passent par la
    // route HTTP (le contrôleur, lui, ne résout qu'après la requête).

    // 1er coup : deux boucliers blancs (ratés) à l'attaque, Cruelle consomme
    // sa fenêtre et relance UN dé — un crâne referme la volée. Défense du
    // squelette sans bouclier noir : le crâne passe intégralement.
    desFiges([4, 4, 1, 1, 1, 1]);
    $resultat1 = app(ResolveurTour::class)->frapper($groupe->fresh(), $quete, $etat->fresh(), $heros->fresh(), $instance->fresh());

    expect($resultat1['cruelle_relance'])->toBe(1)
        ->and($resultat1['touches'])->toBe(1)
        ->and($resultat1['degats'])->toBe(1);

    $ligneJournal = collect(app(JournalCombat::class)->depuisResultat($resultat1, $heros->nom))->pluck('texte')->implode(' | ');
    expect($ligneJournal)->toContain('Cruelle relance un dé raté');

    // 2e coup, MÊME combat (le squelette reste en vue) : la fenêtre est
    // fermée, la relance n'a PAS lieu — deux boucliers blancs restent deux
    // ratés, aucun crâne.
    desFiges([4, 4, 1, 1]);
    $resultat2 = app(ResolveurTour::class)->frapper($groupe->fresh(), $quete, $etat->fresh(), $heros->fresh(), $instance->fresh());

    expect($resultat2['cruelle_relance'])->toBe(0)
        ->and($resultat2['touches'])->toBe(0)
        ->and($resultat2['degats'])->toBe(0);

    // Fin du combat simulée (plus aucun monstre actif en vue) puis DÉBUT DE
    // TOUR : le même prédicat, et le même point de réarmement, que la
    // récupération des Styles Élémentaires du Moine.
    $instance->update(['etat' => 'vaincu']);
    $etat->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);
    app(MoteurSorts::class)->rythmerBuffsDeVue($quete, $etat->fresh());
    expect((array) $etat->fresh()->capacites_combat)->toBe([]);

    // Un nouveau combat commence : la fenêtre est réarmée.
    $instance->update(['etat' => 'actif']);
    desFiges([4, 4, 1, 1, 1, 1]);
    $resultat3 = app(ResolveurTour::class)->frapper($groupe->fresh(), $quete, $etat->fresh(), $heros->fresh(), $instance->fresh());

    expect($resultat3['cruelle_relance'])->toBe(1)
        ->and($resultat3['touches'])->toBe(1);
});

it('Allégée annule le malus de déplacement de l\'armure lourde — sur SON exemplaire seulement', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $groupe = $ctx['groupe'];
    $equipement = app(Equipement::class);

    $ligneArmure = equiperPourForge($heros, 'Armure de plates', 'armure'); // malus_deplacement: 2
    expect($equipement->malusDeplacement($heros->fresh()))->toBe(2);

    forgerPourTest($heros, $ligneArmure, 'Allégée');

    expect($equipement->malusDeplacement($heros->fresh()))->toBe(0);
});

it('Gardée absorbe le premier Apeuré d\'un combat — l\'armure protège, ce n\'est pas une résistance mentale', function () {
    $ctx = demarrerQueteAvecMonstre('Champion');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'quete' => $quete, 'instance' => $instance] = $ctx;

    // Frayeur (Dread, doc 09) : type contrôle, condition_appliquee Apeuré,
    // résistance par rupture — donc aucun jet de Mind initial, la seule
    // chose qui peut l'arrêter ici est Gardée.
    $instance->monstre->update(['sorts_dread' => ['Frayeur'], 'archetype_lanceur' => null]);
    app(App\Partie\MoteurDread::class)->reinitialiserUsagesInstance($instance->fresh(), $quete);

    $ligneBouclier = equiperPourForge($heros, 'Bouclier', 'arme_secondaire');
    forgerPourTest($heros, $ligneBouclier, 'Gardée');

    // Aucun jet ne doit être consommé par Frayeur elle-même (Gardée coupe
    // avant tout dé) : une volée généreuse couvre le reste de la phase.
    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $sort = collect($reponse->json('resultat.tour_monstres.actions'))->firstWhere('sort', 'Frayeur');

    expect($sort)->not->toBeNull()
        ->and($sort['resultats'][0]['garde_par_forge'] ?? null)->toBeTrue()
        ->and($sort['resultats'][0]['effet_applique'])->toBeFalse();

    expect($heros->fresh()->conditions()->where('nom', 'Apeuré')->exists())->toBeFalse();

    $lignes = app(JournalCombat::class)->depuisResultat($reponse->json('resultat'), $heros->nom);
    expect(collect($lignes)->pluck('texte')->implode(' | '))->toContain('Gardée absorbe le premier');
});

it('le registre EFFETS_SUPPORTES couvre exactement les clés des 6 améliorations semées', function () {
    // Les deux sens : chaque clé portée par le catalogue de Forge est
    // couverte (sinon `estSupportee()` la filtrerait, silencieusement, pour
    // une amélioration qu'on croirait pourtant avoir livrée), et le filtre
    // n'accepte rien que le catalogue ne porte pas vraiment.
    $clesCatalogue = collect(ForgeAmelioration::all())
        ->flatMap(fn (ForgeAmelioration $a) => array_keys((array) $a->effet))
        ->unique()
        ->values()
        ->all();

    expect($clesCatalogue)->not->toBeEmpty()
        ->and(array_diff($clesCatalogue, Forge::EFFETS_SUPPORTES))->toBe([])
        ->and(ForgeAmelioration::all()->every(fn (ForgeAmelioration $a) => app(Forge::class)->estSupportee($a)))->toBeTrue();
});
