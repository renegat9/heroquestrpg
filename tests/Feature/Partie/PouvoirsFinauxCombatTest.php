<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\AnnoncesTalents;
use App\Partie\JournalCombat;
use App\Partie\MenuMoteur;
use App\Partie\MoteurDegats;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use App\Partie\SauteTourMonstre;
use App\Partie\Talents;
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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * LOT A DES POUVOIRS FINAUX — phase 2 (2026-10-23) : dix pouvoirs de combat et de conditions, joués en partie.
 *
 * Chaque pouvoir est exercé à travers le VRAI moteur : le menu (`MenuMoteur`), le choix par la route
 * (`POST …/choix`), la réaction par la route (`POST …/reaction`), ou — pour les effets qui ne sont pas une
 * action — le point de passage public qu'ils lisent (`MoteurDegats`, `ResolveurTour::frapper()`).
 *
 * Pour le Flétrissure et le Sang chaud, c'est aussi la preuve que l'effet n'est pas décoratif : chacun se lit au
 * point de passage des dégâts, pas ailleurs (`infligerAMonstre()`).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

// =====================================================================
// Helpers — préfixés `lotA` pour ne jamais heurter un autre fichier de test.
// =====================================================================

/** Le menu du héros tel que le moteur le calcule : aucune route, aucun cache. */
function lotAOptions(Groupe $groupe, Personnage $heros): array
{
    return app(MenuMoteur::class)->generer($groupe->fresh(), $heros->fresh())['options'];
}

/** @return list<string> */
function lotAIds(array $options): array
{
    return array_column($options, 'id');
}

/** Joue une option par la vraie route de choix (menu régénéré juste avant, comme la manette). */
function lotAChoisir(array $ctx, string $optionId, array $parametres = []): TestResponse
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => $optionId,
        'parametres' => $parametres,
    ]);
}

/** Rend le héros à nouveau disponible pour une seconde action du même tour. */
function lotARejouer(array $ctx): void
{
    $ctx['etatHeros']->fresh()->update(['a_joue' => false, 'a_agi' => false, 'a_deplace' => false]);
}

/**
 * Ramène une instance « vaincue » de la quête au jeu, sur la case donnée : un gobelin fraîchement posé.
 * `demarrerQueteAvecMonstre()` met les autres figures hors jeu ; on en reprend une pour peupler la scène.
 */
function lotAMonstreEn(Quete $quete, int $x, int $y, int $pv = 6): InstanceMonstre
{
    $instance = $quete->instancesMonstres()->where('etat', 'vaincu')->orderBy('id')->firstOrFail();

    $instance->update([
        'monstre_id' => Monstre::where('nom_base', 'Gobelin')->value('id'),
        'pv_body' => $pv,
        'pv_body_max' => $pv,
        'etat' => 'actif',
        'revele' => true,
        'position_x' => $x,
        'position_y' => $y,
        'habillage' => [],
        'degat_differe' => null,
        'degat_differe_personnage_id' => null,
    ]);

    return $instance->fresh()->load('monstre');
}

/** Le tir de l'arbalète en main droite — l'arme à distance que la Flèche traversante exige. */
function lotAEquiperArbalete(Personnage $heros): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Arbalète')->firstOrFail()->id,
        'emplacement' => 'arme_principale',
        'quantite' => 1,
    ]);
}

/** La clé de direction (`Rayon::DIRECTIONS`) du pas d'un axe cardinal de `placerHerosSurAxeDegage()`. */
function lotADirection(array $axe): string
{
    return match ([$axe['dx'], $axe['dy']]) {
        [1, 0] => 'e',
        [-1, 0] => 'o',
        [0, 1] => 's',
        default => 'n',
    };
}

/**
 * Deux héros côte à côte dans une même quête (la Bannière et le Cri se lisent entre voisins). Créés AVANT la quête :
 * un héros ajouté après n'a pas d'état de quête.
 *
 * @return array{alice: mixed, groupe: Groupe, a: Personnage, b: Personnage, quete: Quete, etatA: EtatPersonnageQuete, etatB: EtatPersonnageQuete, instance: InstanceMonstre}
 */
function lotADeuxHeros(string $classeA, string $classeB): array
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

/** Un coup de frappe de héros, joué sur le résolveur (le menu n'est pas en cause, seuls les dés comptent). */
function lotAFrapper(Groupe $groupe, Quete $quete, EtatPersonnageQuete $etat, Personnage $attaquant, InstanceMonstre $cible): array
{
    desFiges(array_fill(0, 40, 4));

    return app(ResolveurTour::class)->frapper($groupe, $quete, $etat, $attaquant, $cible);
}

// =====================================================================
// ESPRIT D'ACIER (moine · Méditation) — resistance_condition sur une LISTE de conditions
// =====================================================================

it("Esprit d'acier : résiste à Endormi, Paralysé et Apeuré, et à aucune autre — et le dit", function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'moine', 'pv_body_max' => 20, 'pv_body' => 20]);
    $heros = $ctx['heros'];
    $sort = Sort::firstOrFail();
    $sorts = app(MoteurSorts::class);

    // Sans le talent : les quatre conditions prennent.
    $heros->conditions()->detach();

    foreach (['Endormi', 'Paralysé', 'Apeuré', 'Étourdi'] as $nom) {
        $sorts->appliquerConditionCatalogue($heros, $nom, $sort);
    }

    expect($heros->fresh()->conditions->pluck('nom')->all())
        ->toContain('Endormi', 'Paralysé', 'Apeuré', 'Étourdi');

    // Avec le talent : les trois nommées ne prennent plus ; Étourdi, lui, prend toujours.
    $heros->conditions()->detach();
    donnerTalent($heros, "Esprit d'acier");
    app(AnnoncesTalents::class)->vider();

    foreach (['Endormi', 'Paralysé', 'Apeuré', 'Étourdi'] as $nom) {
        $sorts->appliquerConditionCatalogue($heros, $nom, $sort);
    }

    expect($heros->fresh()->conditions->pluck('nom')->all())->toBe(['Étourdi']);

    // Et la résistance s'annonce, sous le nom du talent.
    expect(collect(app(AnnoncesTalents::class)->vider())->pluck('talent')->all())
        ->toContain("Esprit d'acier");
});

it("Esprit d'acier : sa phrase de jeu énumère les trois conditions, avec la conjonction française", function () {
    $noeud = Competence::where('classe', 'moine')->where('nom', "Esprit d'acier")->firstOrFail();

    expect($noeud->avantage())->toContain('Endormi, Paralysé et Apeuré');
});

// =====================================================================
// ROC (nain) · ÉVANESCENCE (rogue) · BOUCLIER DU SERMENT (chevalier) — annule_degats acheté
// =====================================================================

it("Roc : le coup est proposé au porteur, défait à l'acceptation, et ne revient qu'une fois par quête", function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'nain', 'pv_body_max' => 20, 'pv_body' => 20]);
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];
    donnerTalent($heros, 'Roc');

    // Le coup est appliqué PUIS la question posée : le héros tombe à 17 avant tout choix.
    app(MoteurDegats::class)->infligerAHeros($heros, 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, ['instance_id' => $ctx['instance']->id]);

    $attente = $etat->fresh()->reaction_en_attente;

    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe('annule_degats')
        ->and($attente['capacite'])->toBe('Roc')
        ->and($heros->fresh()->pv_body)->toBe(17);

    // Accepté par la route : les PV reviennent, la proposition est consommée, la capacité est dépensée.
    test()->postJson('/api/groupes/table-1/reaction', ['accepte' => true, 'personnage_id' => $heros->id])->assertOk();

    expect($heros->fresh()->pv_body)->toBe(20)
        ->and($etat->fresh()->reaction_en_attente)->toBeNull()
        ->and((array) $etat->fresh()->capacites_utilisees)->toContain('Roc');

    // Un second coup : plus d'offre, et la fiche dit pourquoi.
    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, ['instance_id' => $ctx['instance']->id]);

    expect($etat->fresh()->reaction_en_attente)->toBeNull();

    $noeud = Competence::where('classe', 'nain')->where('nom', 'Roc')->firstOrFail();

    expect(app(Talents::class)->fiche($heros->fresh(), $etat->fresh(), $noeud)['raison'])
        ->toBe('Déjà utilisée cette quête');
});

it("Bouclier du serment : sa dépense ne touche pas le compteur de la Parade au bouclier", function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'chevalier', 'pv_body_max' => 20, 'pv_body' => 20]);
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];
    donnerTalent($heros, 'Bouclier du serment');

    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, ['instance_id' => $ctx['instance']->id]);

    expect($etat->fresh()->reaction_en_attente['capacite'])->toBe('Bouclier du serment');

    test()->postJson('/api/groupes/table-1/reaction', ['accepte' => true, 'personnage_id' => $heros->id])->assertOk();

    // Deux noms, deux compteurs : la Parade au bouclier n'est pas « déjà utilisée » pour autant.
    $parade = Competence::where('classe', 'chevalier')->where('nom', 'Parade au bouclier')->firstOrFail();

    expect((array) $etat->fresh()->capacites_utilisees)->toContain('Bouclier du serment')
        ->and((array) $etat->fresh()->capacites_utilisees)->not->toContain('Parade au bouclier')
        ->and(app(Talents::class)->fiche($heros->fresh(), $etat->fresh(), $parade)['raison'])
        ->not->toBe('Déjà utilisée cette quête');
});

// =====================================================================
// HURLEMENT (barbare) · MOT DE POUVOIR (barde) — saute_tour_monstre, deux portées
// =====================================================================

it('Hurlement : tous les monstres à ton contact perdent leur prochain tour, et seulement eux — une fois par quête', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Hurlement');

    $axe = placerHerosSurAxeDegage($ctx['quete'], $ctx['etatHeros'], 3);
    $etat = $ctx['etatHeros']->fresh();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Un monstre au contact, un autre à trois cases sur le même axe — hors contact.
    $proche = $ctx['instance'];
    $proche->update(['position_x' => $hx + $axe['dx'], 'position_y' => $hy + $axe['dy']]);
    $loin = lotAMonstreEn($ctx['quete'], $hx + 3 * $axe['dx'], $hy + 3 * $axe['dy']);

    expect(lotAIds(lotAOptions($ctx['groupe'], $ctx['heros']->fresh())))->toContain('hurler');

    $resultat = lotAChoisir($ctx, 'hurler')->assertStatus(202)->json('resultat');

    expect($resultat['type'])->toBe('saute_tour_monstre')
        ->and(array_column($resultat['cibles'], 'instance_id'))->toBe([$proche->id]);

    $sorts = app(MoteurSorts::class);

    expect($sorts->monstreA($proche->fresh(), MoteurSorts::MONSTRE_SAUTE_TOUR))->toBeTrue()
        ->and($sorts->monstreA($loin->fresh(), MoteurSorts::MONSTRE_SAUTE_TOUR))->toBeFalse();

    // Une fois par quête : le menu ne le propose plus, et le résolveur le refuse.
    lotARejouer($ctx);

    expect(lotAIds(lotAOptions($ctx['groupe'], $ctx['heros']->fresh())))->not->toContain('hurler');

    lotAChoisir($ctx, 'hurler')->assertStatus(422);
});

it('Hurlement : un monstre immunisé à la condition n\'est ni offert, ni compté', function () {
    $ctx = demarrerQueteAvecMonstre('Scion Fleur-Pourrie', ['classe' => 'barbare', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Hurlement');

    // Le Scion est au contact (demarrerQueteAvecMonstre le pose à côté du héros), mais IMMUNISÉ à `saute_tour`.
    expect(app(SauteTourMonstre::class)->immunise($ctx['instance']))->toBeTrue()
        ->and(lotAIds(lotAOptions($ctx['groupe'], $ctx['heros']->fresh())))->not->toContain('hurler');
});

it('Hurlement : le tour du monstre qui en est frappé est sauté, et le fil dit « passe son tour »', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Hurlement');

    $ctx['instance']->update(['revele' => true]);
    app(MoteurSorts::class)->poserConditionMonstre($ctx['instance'], MoteurSorts::MONSTRE_SAUTE_TOUR);

    // Le tour du monstre : la condition est consommée et le tour est sauté, sans attaque ni déplacement.
    $payload = (new ReflectionMethod(ResolveurTour::class, 'jouerMonstre'))
        ->invoke(app(ResolveurTour::class), $ctx['groupe'], $ctx['quete'], $ctx['instance']->fresh()->load('monstre'), new EloquentCollection);

    expect($payload['type'])->toBe('monstre_saute_tour')
        ->and(app(MoteurSorts::class)->monstreA($ctx['instance']->fresh(), MoteurSorts::MONSTRE_SAUTE_TOUR))->toBeFalse();

    // Le fil le dit, sans prétendre que c'est la tempête.
    $texte = collect((new JournalCombat)->depuisResultat($payload, 'Grom'))->pluck('texte')->implode(' ');

    expect($texte)->toContain('passe son tour')->not->toContain('tempête');
});

it('Mot de pouvoir : UN monstre que tu vois — la ligne de vue des sorts tranche, et un seul perd son tour', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barde', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Mot de pouvoir');

    $axe = placerHerosSurAxeDegage($ctx['quete'], $ctx['etatHeros'], 3);
    $etat = $ctx['etatHeros']->fresh();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Un monstre à trois cases, sur un axe dégagé : visible.
    $loin = $ctx['instance'];
    $loin->update(['position_x' => $hx + 3 * $axe['dx'], 'position_y' => $hy + 3 * $axe['dy']]);

    $mot = collect(lotAOptions($ctx['groupe'], $ctx['heros']->fresh()))->firstWhere('id', 'mot_de_pouvoir');

    expect($mot)->not->toBeNull()
        ->and(array_column($mot['parametres']['cibles'], 'id'))->toBe([$loin->id]);

    // Un monstre s'interpose, au contact : il coupe la vue vers le lointain, et devient la seule cible.
    $proche = lotAMonstreEn($ctx['quete'], $hx + $axe['dx'], $hy + $axe['dy']);

    $mot = collect(lotAOptions($ctx['groupe'], $ctx['heros']->fresh()))->firstWhere('id', 'mot_de_pouvoir');

    expect(array_column($mot['parametres']['cibles'], 'id'))->toBe([$proche->id]);

    // Une cible hors de la liste blanche : refusée.
    lotAChoisir($ctx, 'mot_de_pouvoir', ['cible_id' => $loin->id])->assertStatus(422);

    // La bonne cible : elle seule perd son tour.
    lotAChoisir($ctx, 'mot_de_pouvoir', ['cible_id' => $proche->id])->assertStatus(202);

    $sorts = app(MoteurSorts::class);

    expect($sorts->monstreA($proche->fresh(), MoteurSorts::MONSTRE_SAUTE_TOUR))->toBeTrue()
        ->and($sorts->monstreA($loin->fresh(), MoteurSorts::MONSTRE_SAUTE_TOUR))->toBeFalse();
});

// =====================================================================
// CRI DE RALLIEMENT (chevalier) — bonus_des_attaque_allie_adjacent, miroir de la Bannière
// =====================================================================

it('Cri de ralliement : +1 dé d\'attaque au compagnon à son contact, jamais au porteur, et il est nommé', function () {
    $duo = lotADeuxHeros('chevalier', 'barbare');
    $a = $duo['a'];
    $b = $duo['b'];
    $quete = $duo['quete'];
    $groupe = $duo['groupe'];
    $instance = $duo['instance'];

    // Le compagnon est au contact du chevalier : une case à côté, même ligne.
    $xa = (int) $duo['etatA']->position_x;
    $ya = (int) $duo['etatA']->position_y;
    $duo['etatB']->update(['position_x' => $xa + 1, 'position_y' => $ya]);

    $sansCri = lotAFrapper($groupe, $quete, $duo['etatB']->fresh(), $b->fresh(), $instance);
    $chevalierSansCri = lotAFrapper($groupe, $quete, $duo['etatA']->fresh(), $a->fresh(), $instance);

    donnerTalent($a, 'Cri de ralliement');

    $avecCri = lotAFrapper($groupe, $quete, $duo['etatB']->fresh(), $b->fresh(), $instance);

    expect($avecCri['des_attaque_effectifs'])->toBe($sansCri['des_attaque_effectifs'] + 1)
        ->and(collect($avecCri['modificateurs'] ?? [])->pluck('source')->all())->toContain('Cri de ralliement');

    // Le porteur ne se donne rien à lui-même.
    $lui = lotAFrapper($groupe, $quete, $duo['etatA']->fresh(), $a->fresh(), $instance);

    expect($lui['des_attaque_effectifs'])->toBe($chevalierSansCri['des_attaque_effectifs']);

    // Éloigné de cinq cases : plus rien.
    $duo['etatA']->update(['position_x' => $xa + 5]);

    $loin = lotAFrapper($groupe, $quete, $duo['etatB']->fresh(), $b->fresh(), $instance);

    expect($loin['des_attaque_effectifs'])->toBe($sansCri['des_attaque_effectifs']);
});

// =====================================================================
// SANG CHAUD (berserker) — soin_apres_kill, au point de passage de la mort réelle
// =====================================================================

it('Sang chaud : la mise à mort réelle rend 1 PV de Body, sans dépasser le maximum', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker', 'pv_body_max' => 10, 'pv_body' => 10]);
    donnerTalent($ctx['heros'], 'Sang chaud');
    $ctx['heros']->update(['pv_body' => 9]);   // la quête remet le héros à pleine santé : on pose les PV après
    $moteur = app(MoteurDegats::class);

    $moteur->infligerAMonstre($ctx['instance'], 99, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['heros']);

    expect($ctx['instance']->fresh()->etat)->toBe('vaincu')
        ->and($ctx['heros']->fresh()->pv_body)->toBe(10);

    // À pleins PV, abattre ne rend rien — et ne se dit pas.
    app(AnnoncesTalents::class)->vider();
    $autre = lotAMonstreEn($ctx['quete'], 1, 1);
    $moteur->infligerAMonstre($autre, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['heros']->fresh());

    expect($ctx['heros']->fresh()->pv_body)->toBe(10)
        ->and(collect(app(AnnoncesTalents::class)->vider())->where('talent', 'Sang chaud')->all())->toBe([]);
});

it('Sang chaud : ni un coup qui ne tue pas, ni un allié qui abat, ne rendent rien', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'berserker', 'pv_body_max' => 10, 'pv_body' => 10]);
    donnerTalent($ctx['heros'], 'Sang chaud');
    $ctx['heros']->update(['pv_body' => 5]);   // posé après le démarrage de la quête, qui remet à pleine santé
    $moteur = app(MoteurDegats::class);

    // Le monstre survit : pas de mise à mort, pas de soin.
    $survit = lotAMonstreEn($ctx['quete'], 1, 1, pv: 20);
    $moteur->infligerAMonstre($survit, 1, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $ctx['heros']);

    expect($ctx['heros']->fresh()->pv_body)->toBe(5);

    // Un allié abat : la mise à mort n'est pas la sienne.
    $abattu = lotAMonstreEn($ctx['quete'], 2, 2);
    $moteur->infligerAMonstre($abattu, 99, MoteurDegats::SOURCE_ATTAQUE_ALLIE, [], null);

    expect($ctx['heros']->fresh()->pv_body)->toBe(5);
});

// =====================================================================
// FLÉTRISSURE (warlock) — degat_differe_sur_sort, dans la colonne de la braise
// =====================================================================

it('Flétrissure : un sort qui blesse sans abattre AJOUTE 1 à la braise — une seule colonne, jamais deux', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'warlock', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Flétrissure');
    $moteur = app(MoteurDegats::class);
    $cible = lotAMonstreEn($ctx['quete'], 1, 1, pv: 10);

    $moteur->infligerAMonstre($cible, 2, MoteurDegats::SOURCE_SORT_HEROS, ['sort' => 'Trait de Feu'], $ctx['heros']);

    expect($cible->fresh()->pv_body)->toBe(8)
        ->and($cible->fresh()->degat_differe)->toBe(1)
        ->and($cible->fresh()->degat_differe_personnage_id)->toBe($ctx['heros']->id);

    // Une braise déjà allumée (le Toucher du Brasier du moine) : on AJOUTE, on ne pose pas une seconde braise.
    $cible->fresh()->update(['degat_differe' => 2]);
    $moteur->infligerAMonstre($cible->fresh(), 1, MoteurDegats::SOURCE_SORT_HEROS, ['sort' => 'Trait de Feu'], $ctx['heros']);

    expect($cible->fresh()->degat_differe)->toBe(3);
});

it('Flétrissure : un sort qui ABAT n\'ajoute rien — il n\'y a plus de tour à suivre', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'warlock', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Flétrissure');
    $cible = lotAMonstreEn($ctx['quete'], 1, 1, pv: 2);

    app(MoteurDegats::class)->infligerAMonstre($cible, 5, MoteurDegats::SOURCE_SORT_HEROS, ['sort' => 'Boule de Feu'], $ctx['heros']);

    expect($cible->fresh()->etat)->toBe('vaincu')
        ->and($cible->fresh()->degat_differe)->toBeNull();
});

it('Flétrissure : la braise tombe à la fin du tour du monstre, par la même machinerie que le Toucher du Brasier', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'warlock', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Flétrissure');
    $cible = lotAMonstreEn($ctx['quete'], 1, 1, pv: 10);

    app(MoteurDegats::class)->infligerAMonstre($cible, 2, MoteurDegats::SOURCE_SORT_HEROS, ['sort' => 'Trait de Feu'], $ctx['heros']);

    $payload = (new ReflectionMethod(ResolveurTour::class, 'consumerBraise'))
        ->invoke(app(ResolveurTour::class), $ctx['groupe'], $cible->fresh());

    expect($payload['type'])->toBe('braise')
        ->and($payload['degats'])->toBe(1)
        ->and($cible->fresh()->pv_body)->toBe(7)
        ->and($cible->fresh()->degat_differe)->toBeNull();
});

// =====================================================================
// FLÈCHE TRAVERSANTE (elfe) — tir_traversant, la géométrie de Rayon, une arme à distance en main
// =====================================================================

it('Flèche traversante : chaque ennemi de la ligne se défend, une fois par quête, à l\'arbalète en main', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Flèche traversante');
    lotAEquiperArbalete($ctx['heros']);

    $axe = placerHerosSurAxeDegage($ctx['quete'], $ctx['etatHeros'], 3);
    $etat = $ctx['etatHeros']->fresh();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $direction = lotADirection($axe);

    // Un ennemi au contact : l'arbalète ne tire pas au contact, il n'est pas visé.
    $contact = $ctx['instance'];
    $contact->update(['position_x' => $hx + $axe['dx'], 'position_y' => $hy + $axe['dy']]);
    // Deux ennemis plus loin sur la même ligne : ce sont eux que la flèche traverse.
    $deux = lotAMonstreEn($ctx['quete'], $hx + 2 * $axe['dx'], $hy + 2 * $axe['dy']);
    $trois = lotAMonstreEn($ctx['quete'], $hx + 3 * $axe['dx'], $hy + 3 * $axe['dy']);

    $fleche = collect(lotAOptions($ctx['groupe'], $ctx['heros']->fresh()))->firstWhere('id', "fleche_{$direction}");

    expect($fleche)->not->toBeNull()
        ->and($fleche['libelle'])->toContain('2 ennemis');

    $resultat = lotAChoisir($ctx, "fleche_{$direction}")->assertStatus(202)->json('resultat');

    expect($resultat['type'])->toBe('tir_traversant')
        ->and(count($resultat['frappes']))->toBe(2)
        ->and(collect($resultat['frappes'])->pluck('cible.instance_id')->sort()->values()->all())
        ->toBe([$deux->id, $trois->id]);

    // Une fois par quête.
    lotARejouer($ctx);

    expect(lotAIds(lotAOptions($ctx['groupe'], $ctx['heros']->fresh())))->not->toContain("fleche_{$direction}");

    lotAChoisir($ctx, "fleche_{$direction}")->assertStatus(422);
});

it('Flèche traversante : jamais offerte sans arme à distance en main, même avec des ennemis sur la ligne', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe', 'pv_body_max' => 20, 'pv_body' => 20]);
    donnerTalent($ctx['heros'], 'Flèche traversante');

    $axe = placerHerosSurAxeDegage($ctx['quete'], $ctx['etatHeros'], 3);
    $etat = $ctx['etatHeros']->fresh();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    lotAMonstreEn($ctx['quete'], $hx + 2 * $axe['dx'], $hy + 2 * $axe['dy']);

    $ids = lotAIds(lotAOptions($ctx['groupe'], $ctx['heros']->fresh()));

    expect(array_filter($ids, fn (string $id): bool => str_starts_with($id, 'fleche_')))->toBe([]);

    lotAChoisir($ctx, 'fleche_'.lotADirection($axe))->assertStatus(422);
});

it('le fil nomme les monstres du Hurlement et la direction de la Flèche', function () {
    $fil = new JournalCombat;

    $hurlement = collect($fil->depuisResultat([
        'type' => 'saute_tour_monstre', 'capacite' => 'Hurlement', 'portee' => 'contact',
        'cibles' => [['instance_id' => 1, 'nom' => 'Gobelin'], ['instance_id' => 2, 'nom' => 'Orc']],
    ], 'Grom'))->pluck('texte')->implode(' ');

    expect($hurlement)->toContain('Gobelin, Orc perdent leur prochain tour');

    $fleche = collect($fil->depuisResultat([
        'type' => 'tir_traversant', 'capacite' => 'Flèche traversante', 'direction' => 'e', 'cibles' => 2,
    ], 'Lirien'))->pluck('texte')->implode(' ');

    expect($fleche)->toContain('Flèche traversante')->toContain("à l'est")->toContain('2 ennemis');
});
