<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;
use App\Models\Quete;
use App\Partie\FaveursHopekins;
use App\Partie\MenuMoteur;
use App\Partie\MoteurEmbuscade;
use App\Partie\ResolveurTour;
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
 * LE CONTACT ORTHOGONAL, EN JEU (chantier 2026-10-23, décision de René).
 *
 * Le contact entre deux figures passe par UN point de passage,
 * `Grille::sontAdjacentes()` (livret 2021, p. 10 : devant, derrière, à côté —
 * pas en diagonale). Un allié en DIAGONALE ne compte donc plus : ni sa Bannière,
 * ni son Cri, ni son Regard, ni son soin, ni son entrave, ni ses liens. Un allié
 * À CÔTÉ compte comme avant.
 *
 * Les cas à HUIT cases restent à huit (armes longues, Hold the Line, embuscade,
 * errant) : ils sont écrits au point de passage, pas ici.
 *
 * Dés figés, résolveur réel. Ces tests doivent TOMBER si l'on remet la formule à
 * huit cases dans `ResolveurTour::detailChezLesVoisins()` ou dans les filtres du
 * menu (`MenuMoteur`) — c'est la preuve qu'ils mesurent quelque chose.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/**
 * Deux héros côte à côte dans une même quête, un monstre révélé. Créés AVANT la
 * quête : un héros ajouté après n'a pas d'état de quête.
 *
 * @return array{alice: mixed, groupe: Groupe, a: Personnage, b: Personnage, quete: Quete, etatA: EtatPersonnageQuete, etatB: EtatPersonnageQuete, instance: InstanceMonstre}
 */
function contactDuo(string $classeA, string $classeB): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $a = creerHeros($alice, $groupe, 'Roland', 1, ['classe' => $classeA, 'pv_body_max' => 20, 'pv_body' => 20]);
    $b = creerHeros($alice, $groupe, 'Albrecht', 2, ['classe' => $classeB, 'pv_body_max' => 20, 'pv_body' => 20]);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = $quete->etatsPersonnages()->where('personnage_id', $a->id)->firstOrFail();
    $etatB = $quete->etatsPersonnages()->where('personnage_id', $b->id)->firstOrFail();

    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update(['revele' => true]);

    return compact('alice', 'groupe', 'a', 'b', 'quete', 'etatA', 'etatB', 'instance');
}

/** Un monstre frappe une cible, par le résolveur, dés figés (le chemin public tire au sort). */
function contactFrappeMonstre(array $args, array $des): array
{
    desFiges($des);

    return (new ReflectionMethod(ResolveurTour::class, 'resoudreAttaqueMonstre'))
        ->invoke(app(ResolveurTour::class), ...$args);
}

/** Un héros frappe le monstre, par le résolveur, dés figés. */
function contactFrappeHeros(Groupe $groupe, Quete $quete, EtatPersonnageQuete $etat, Personnage $attaquant, InstanceMonstre $cible): array
{
    desFiges(array_fill(0, 40, 4));

    return app(ResolveurTour::class)->frapper($groupe, $quete, $etat, $attaquant, $cible);
}

/** Les cibles que l'option `$optionId` PUBLIE pour ce héros (sa liste blanche), [] si l'option n'est pas offerte. */
function contactCiblesDuMenu(Groupe $groupe, Personnage $personnage, string $optionId): array
{
    $menu = app(MenuMoteur::class)->generer($groupe->fresh(), $personnage->fresh());
    $option = collect($menu['options'] ?? [])->firstWhere('id', $optionId);

    return collect($option['parametres']['cibles'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** Rouvre les créneaux d'un héros : un menu se calcule sur un tour encore ouvert. */
function contactRouvrirTour(EtatPersonnageQuete $etat): void
{
    $etat->fresh()->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);
}

// =====================================================================
// BANNIÈRE — bonus_des_defense_allie_adjacent (la somme des voisins)
// =====================================================================

it('Bannière : le chevalier en DIAGONALE ne protège pas son compagnon ; à côté, il le protège', function () {
    $duo = contactDuo('chevalier', 'barbare');
    $compagnon = $duo['b'];
    $etatCompagnon = $duo['etatB'];
    $etatChevalier = $duo['etatA'];

    donnerTalent($duo['a'], 'Bannière');

    $xc = (int) $etatCompagnon->position_x;
    $yc = (int) $etatCompagnon->position_y;
    $base = (int) $compagnon->des_defense;
    $des = [1, 1, ...array_fill(0, 12, 4)]; // deux crânes ; défense en boucliers blancs

    $etatChevalier->update(['position_x' => $xc + 1, 'position_y' => $yc + 1]);
    $diagonale = contactFrappeMonstre([$duo['groupe'], $duo['instance'], $etatCompagnon->fresh(), 2, [], 'Gobelin'], $des);

    expect($diagonale['boucliers'])->toBe($base);

    $etatChevalier->update(['position_x' => $xc + 1, 'position_y' => $yc]);
    $cote = contactFrappeMonstre([$duo['groupe'], $duo['instance'], $etatCompagnon->fresh(), 2, [], 'Gobelin'], $des);

    expect($cote['boucliers'])->toBe($base + 1);
});

// =====================================================================
// CRI DE RALLIEMENT — bonus_des_attaque_allie_adjacent (la somme des voisins)
// =====================================================================

it('Cri de ralliement : le compagnon en DIAGONALE ne reçoit pas son dé ; à côté, il le reçoit', function () {
    $duo = contactDuo('chevalier', 'barbare');
    $xa = (int) $duo['etatA']->position_x;
    $ya = (int) $duo['etatA']->position_y;
    $frapper = fn () => contactFrappeHeros($duo['groupe'], $duo['quete'], $duo['etatB']->fresh(), $duo['b']->fresh(), $duo['instance']);

    // Référence : le barbare frappe sans Cri, en diagonale du chevalier.
    $duo['etatB']->update(['position_x' => $xa + 1, 'position_y' => $ya + 1]);
    $reference = $frapper();

    donnerTalent($duo['a'], 'Cri de ralliement');

    expect($frapper()['des_attaque_effectifs'])->toBe($reference['des_attaque_effectifs']);

    $duo['etatB']->update(['position_x' => $xa + 1, 'position_y' => $ya]);
    $cote = $frapper();

    expect($cote['des_attaque_effectifs'])->toBe($reference['des_attaque_effectifs'] + 1)
        ->and(collect($cote['modificateurs'] ?? [])->pluck('source')->all())->toContain('Cri de ralliement');
});

// =====================================================================
// REGARD QUI GLACE — malus_des_monstre_adjacent, porté par un VOISIN
// =====================================================================

it('Regard qui glace porté par un VOISIN : le monstre perd son dé seulement si le porteur est à côté', function () {
    $duo = contactDuo('barbare', 'barbare');
    $porteur = $duo['a'];
    $xc = (int) $duo['etatB']->position_x;
    $yc = (int) $duo['etatB']->position_y;

    // Attaque toute en crânes, défense toute en boucliers NOIRS : `touches` compte les dés d'attaque.
    $des = [1, 1, 1, 1, ...array_fill(0, 12, 6)];
    $frappe = fn () => contactFrappeMonstre([$duo['groupe'], $duo['instance'], $duo['etatB']->fresh(), 3, [], 'Gobelin'], $des);

    $duo['etatA']->update(['position_x' => $xc + 1, 'position_y' => $yc + 1]);
    $reference = $frappe();

    donnerTalent($porteur, 'Regard qui glace');

    expect($frappe()['touches'])->toBe($reference['touches']);

    $duo['etatA']->update(['position_x' => $xc + 1, 'position_y' => $yc]);

    expect($frappe()['touches'])->toBe($reference['touches'] - 1);
});

// =====================================================================
// SOIN D'ALLIÉ — soin_allie (menu + résolveur, liste publiée = liste blanche)
// =====================================================================

it('soin_allie : la Ballade ne vise qu\'un compagnon à côté — un blessé en DIAGONALE n\'est pas offert, et le résolveur le refuse', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barde = creerHeros($alice, $groupe, 'Lyr', 1, ['classe' => 'barde']);
    $aCote = creerHeros($alice, $groupe, 'Albrecht', 2);
    $enDiagonale = creerHeros($alice, $groupe, 'Bruno', 3);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatBarde = $quete->etatsPersonnages()->where('personnage_id', $barde->id)->firstOrFail();
    $etatCote = $quete->etatsPersonnages()->where('personnage_id', $aCote->id)->firstOrFail();
    $etatDiag = $quete->etatsPersonnages()->where('personnage_id', $enDiagonale->id)->firstOrFail();

    // Blessés APRÈS le démarrage de quête : celui-ci remet les PV au maximum.
    $aCote->update(['pv_body' => 2]);
    $enDiagonale->update(['pv_body' => 2]);

    donnerTalent($barde, 'Ballade apaisante');

    $xb = (int) $etatBarde->position_x;
    $yb = (int) $etatBarde->position_y;
    $etatCote->update(['position_x' => $xb + 1, 'position_y' => $yb]);
    $etatDiag->update(['position_x' => $xb + 1, 'position_y' => $yb + 1]);

    expect(contactCiblesDuMenu($groupe, $barde, 'soigner_allie'))
        ->toContain((int) $aCote->id)
        ->not->toContain((int) $enDiagonale->id);

    // La liste publiée est la liste blanche : une cible hors de la liste est refusée.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $barde->id);
    desFiges([4]);

    // La cible refusée vient de la LISTE du résolveur (clé `parametres`), pas d'une option absente.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'soigner_allie', 'parametres' => ['cible_id' => $enDiagonale->id],
    ])->assertStatus(422)->assertJsonValidationErrors(['parametres']);

    expect((int) $enDiagonale->fresh()->pv_body)->toBe(2);
});

// =====================================================================
// DÉTRUIRE LES ENTRAVES — liberer_entraves (soi-même OU voisin orthogonal)
// =====================================================================

it('Détruire les entraves : un compagnon ne libère le héros immobilisé qu\'à côté ; le héros se libère toujours lui-même', function () {
    $duo = contactDuo('barbare', 'barbare');
    $libre = $duo['a'];
    $tenu = $duo['b'];
    $tenu->conditions()->attach(Condition::where('nom', 'Immobilisé')->firstOrFail()->id, [
        'duree' => 3, 'source' => 'test contact',
    ]);

    $xt = (int) $duo['etatB']->position_x;
    $yt = (int) $duo['etatB']->position_y;

    $duo['etatA']->update(['position_x' => $xt + 1, 'position_y' => $yt + 1]);
    contactRouvrirTour($duo['etatA']);

    expect(contactCiblesDuMenu($duo['groupe'], $libre, 'liberer_entraves'))->not->toContain((int) $tenu->id);

    $duo['etatA']->update(['position_x' => $xt + 1, 'position_y' => $yt]);
    contactRouvrirTour($duo['etatA']);

    expect(contactCiblesDuMenu($duo['groupe'], $libre, 'liberer_entraves'))->toContain((int) $tenu->id);

    contactRouvrirTour($duo['etatB']);

    expect(contactCiblesDuMenu($duo['groupe'], $tenu, 'liberer_entraves'))->toContain((int) $tenu->id);
});

// =====================================================================
// TRANCHER LES LIENS — attaquer_liens (soi-même OU voisin orthogonal)
// =====================================================================

it('Trancher les liens : un compagnon ne tranche les liens magiques qu\'à côté ; le héros ligoté peut toujours s\'en défaire', function () {
    $duo = contactDuo('barbare', 'barbare');
    $libre = $duo['a'];
    $ligote = $duo['b'];
    $ligote->conditions()->attach(Condition::where('nom', 'Ligoté')->firstOrFail()->id, [
        'duree' => 3, 'source' => 'test contact',
    ]);

    $xl = (int) $duo['etatB']->position_x;
    $yl = (int) $duo['etatB']->position_y;

    $duo['etatA']->update(['position_x' => $xl + 1, 'position_y' => $yl + 1]);
    contactRouvrirTour($duo['etatA']);

    expect(contactCiblesDuMenu($duo['groupe'], $libre, 'attaquer_liens'))->not->toContain((int) $ligote->id);

    $duo['etatA']->update(['position_x' => $xl + 1, 'position_y' => $yl]);
    contactRouvrirTour($duo['etatA']);

    expect(contactCiblesDuMenu($duo['groupe'], $libre, 'attaquer_liens'))->toContain((int) $ligote->id);

    contactRouvrirTour($duo['etatB']);

    expect(contactCiblesDuMenu($duo['groupe'], $ligote, 'attaquer_liens'))->toContain((int) $ligote->id);
});

// =====================================================================
// LES EXCEPTIONS À HUIT CASES — écrites au point de passage (`true`), donc figées
// =====================================================================

it('Hold the Line : un monstre qui part d\'une case DIAGONALE au porteur déclenche la faveur — « the 8 spaces » (carte)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    PersonnageFaveur::create([
        'personnage_id' => $ctx['heros']->id, 'cle' => FaveursHopekins::HOLD_THE_LINE, 'parametre' => null,
    ]);
    $ctx['instance']->update(['pv_body' => 10, 'pv_body_max' => 10]);

    $hx = (int) $ctx['etatHeros']->position_x;
    $hy = (int) $ctx['etatHeros']->position_y;

    // Le monstre part d'une case DIAGONALE au porteur, puis s'éloigne.
    $avant = ['x' => $hx + 1, 'y' => $hy + 1];
    $ctx['instance']->update(['position_x' => $avant['x'] + 5, 'position_y' => $avant['y']]);

    desFiges([1]); // un crâne : le dégât part

    $actions = app(FaveursHopekins::class)->tenterHoldTheLine($ctx['groupe'], $ctx['quete'], $ctx['instance']->fresh(), $avant);

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['touche'])->toBeTrue();

    // Deux cases plus loin, rien : la frontière des 8 cases est celle de la carte.
    $loin = ['x' => $hx + 2, 'y' => $hy];
    $ctx['instance']->update(['position_x' => $loin['x'] + 5, 'position_y' => $loin['y']]);
    desFiges([1]);

    expect(app(FaveursHopekins::class)->tenterHoldTheLine($ctx['groupe'], $ctx['quete'], $ctx['instance']->fresh(), $loin))
        ->toBeEmpty();
});

it('embuscade du Dreadshifter : une case DIAGONALE au coffre le déclenche — « the 8 squares surrounding this object » (carte)', function () {
    $ctx = demarrerQueteAvecMonstre('Dreadshifter');
    $ds = $ctx['instance'];
    $ds->update(['revele' => false, 'position_x' => 20, 'position_y' => 20]);
    $ds->poserEtatDread('deguise', MoteurEmbuscade::DEGUISEMENT_COFFRE);

    $quete = $ctx['quete']->fresh();
    $embuscade = app(MoteurEmbuscade::class);

    expect($embuscade->declenchees($quete, [['x' => 21, 'y' => 21]])->pluck('id')->all())->toContain($ds->id)
        ->and($embuscade->declenchees($quete, [['x' => 22, 'y' => 20]])->pluck('id')->all())->not->toContain($ds->id);
});
