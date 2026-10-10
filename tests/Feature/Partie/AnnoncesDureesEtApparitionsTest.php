<?php

declare(strict_types=1);

use App\Engine\DureeEffet;
use App\Engine\RegainEffet;
use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Sort;
use App\Partie\EtatGroupe;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use App\Partie\TamponAnnonces;
use App\Partie\Votes\VoteGroupe;
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

/*
 * Verdict Jungle 2026-10-10 §1 — les effets AUTOMATIQUES qui s'écoulent, s'éteignent
 * ou apparaissent sans qu'aucune action ne les retourne.
 *
 * Le registre `JournalCombat::TYPES` couvre les TYPES d'action ; ce qui lui échappait
 * est de l'autre famille : une DURÉE qui s'écoule (poison, buffs), un BUFF qui se
 * rompt, un SORT qui revient, un boss qui change de forme ou paraît, un VOTE qui se
 * clôt. Tous passent désormais par une seule porte, `TamponAnnonces::annoncer()`
 * (journal pour le rejeu + fil en direct), et ce fichier les joue sur la vraie route.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class, SortDreadSeeder::class]);
});

/** Le fil EN DIRECT d'un résultat. */
function dureeFilDirect(array $resultat, string $acteur = 'Albrecht'): array
{
    return collect(app(JournalCombat::class)->depuisResultat($resultat, $acteur))->pluck('texte')->all();
}

/** Le fil que verrait un joueur arrivé en retard. */
function dureeFilRejoue(array $ctx): array
{
    return collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['journal_combat'])->pluck('texte')->all();
}

/** Éloigne le monstre unique pour qu'une fin de tour ne soit pas polluée par ses coups. */
function dureeEloignerMonstre(array $ctx): void
{
    ouvrirToutesLesPortes($ctx['quete']);
    $quete = $ctx['quete']->fresh()->load('carte');
    $hx = (int) $ctx['etatHeros']->position_x;
    $hy = (int) $ctx['etatHeros']->position_y;
    $loin = null;
    $max = -1;

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            $d = abs($x - $hx) + abs($y - $hy);

            if ($c === 's' && $d > $max && caseQueteLibre($quete, $x, $y)) {
                [$loin, $max] = [['x' => $x, 'y' => $y], $d];
            }
        }
    }

    $ctx['instance']->update(['position_x' => $loin['x'], 'position_y' => $loin['y']]);
}

// =====================================================================
// 1. LA FAMILLE DES DURÉES — poison : tics, fin ; buffs : rupture, consommation
// =====================================================================

it('EN JEU — le POISON dit chaque tic (−1 PV, combien de tours restent) puis sa FIN, en direct ET au rejeu', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);
    dureeEloignerMonstre($ctx);
    $heros = $ctx['heros'];
    $heros->update(['pv_body' => 6, 'pv_body_max' => 8]);
    $heros->conditions()->attach(Condition::where('nom', 'Empoisonné')->value('id'), ['duree' => 2, 'source' => 'piege:Aiguille']);

    desFiges(array_fill(0, 300, 4));

    // Premier tour : le poison mord en fin de tour (créneau « tour »), reste un tour.
    $r1 = test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertAccepted()->json('resultat');
    $direct1 = implode(' ¦ ', dureeFilDirect($r1));

    expect($direct1)->toContain('Albrecht : Empoisonné — −1 PV (5/8, encore 1 tour)')
        ->and((int) $heros->fresh()->pv_body)->toBe(5);

    // Second tour : dernier tic, puis la durée est écoulée — la fin se DIT.
    $r2 = test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertAccepted()->json('resultat');
    $direct2 = implode(' ¦ ', dureeFilDirect($r2));

    expect($direct2)->toContain('Albrecht : Empoisonné — −1 PV (4/8, dernier tour)')
        ->and($direct2)->toContain('Albrecht : Empoisonné prend fin : la durée est écoulée')
        ->and($heros->fresh()->conditions()->where('nom', 'Empoisonné')->exists())->toBeFalse();

    // Le joueur qui arrive APRÈS lit les mêmes lignes (le journal les porte).
    $rejoue = implode(' ¦ ', dureeFilRejoue($ctx));
    expect($rejoue)->toContain('Empoisonné — −1 PV (5/8')->toContain('Empoisonné prend fin : la durée est écoulée');
});

it('EN JEU — la RUPTURE d\'un buff au premier dégât et sa CONSOMMATION se disent (Renforcé, nommé par son sort)', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    app(MoteurSorts::class)->attacherElement($heros, 'druide');

    $metamorphose = Sort::where('nom', 'Métamorphose')->firstOrFail();
    expect(DureeEffet::correspond($metamorphose->effet['duree'] ?? null, DureeEffet::PREMIER_DEGAT_SUBI))->toBeTrue();

    $heros->conditions()->attach(Condition::where('nom', 'Renforcé')->value('id'), ['duree' => 0, 'source' => 'sort:Métamorphose']);

    // Hors résolution (ici un dégât direct) : le tampon est FERMÉ, l'annonce part toute seule.
    $heros->update(['pv_body' => (int) $heros->pv_body - 1]);

    $rejoue = dureeFilRejoue($ctx);
    expect($rejoue)->toContain('Albrecht : Renforcé (Métamorphose) rompu : premier dégât subi')
        ->and($heros->fresh()->conditions()->where('nom', 'Renforcé')->exists())->toBeFalse();

    // Consommation : un buff « prochaine attaque » dépensé par le coup.
    $heros->conditions()->attach(Condition::where('nom', 'Renforcé')->value('id'), ['duree' => 0, 'source' => 'sort:Courage']);
    app(MoteurSorts::class)->expirerBuffs($heros->fresh(), DureeEffet::PROCHAINE_ATTAQUE);

    expect(dureeFilRejoue($ctx))->toContain('Albrecht : Renforcé (Courage) dépensé par son attaque');
});

it('EN JEU — une annonce posée PENDANT une résolution voyage dans le résultat (pas de diffusion en double)', function () {
    $tampon = app(TamponAnnonces::class);
    $tampon->vider();
    $tampon->ouvrir();

    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);
    $ctx['heros']->conditions()->attach(Condition::where('nom', 'Renforcé')->value('id'), ['duree' => 0, 'source' => 'sort:Courage']);
    app(MoteurSorts::class)->expirerBuffs($ctx['heros']->fresh(), DureeEffet::CE_TOUR);
    app(MoteurSorts::class)->expirerBuffs($ctx['heros']->fresh(), DureeEffet::PROCHAINE_ATTAQUE);

    $annonces = $tampon->vider();

    expect(collect($annonces)->pluck('type')->all())->toBe(['condition_terminee'])
        ->and(dureeFilDirect(['type' => 'attente', 'annonces_automatiques' => $annonces]))
        ->toBe(['Albrecht : Renforcé (Courage) dépensé par son attaque']);
});

it('EN JEU — Métamorphose : quand le Body revient au maximum, le sort REVIENT et le fil le dit', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    app(MoteurSorts::class)->attacherElement($heros, 'druide');

    $sortId = Sort::where('nom', 'Métamorphose')->value('id');
    DB::table('personnage_sorts')->where('personnage_id', $heros->id)->where('sort_id', $sortId)->update(['disponible' => false]);
    $heros->update(['pv_body' => (int) $heros->pv_body_max - 2]);
    $heros->refresh()->update(['pv_body' => (int) $heros->pv_body_max]);

    $dit = dureeFilRejoue($ctx);

    expect($dit)->toContain('Albrecht retrouve « Métamorphose » : son Body est revenu à son maximum')
        ->and((bool) DB::table('personnage_sorts')->where('personnage_id', $heros->id)->where('sort_id', $sortId)->value('disponible'))->toBeTrue();
});

it('EN JEU — la condition à durée d\'un MONSTRE (ralenti) qui tombe se dit aussi', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);
    app(MoteurSorts::class)->poserConditionMonstre($ctx['instance']->fresh(), MoteurSorts::MONSTRE_RALENTI, 1);

    app(MoteurSorts::class)->decrementerDureesMonstres($ctx['quete']->fresh());

    expect(implode(' ¦ ', dureeFilRejoue($ctx)))->toContain('n\'est plus ralenti : la durée est écoulée');
});

it('VOCABULAIRES — chaque déclencheur de DureeEffet a sa phrase de fin, chaque regain la sienne', function () {
    foreach (DureeEffet::toutes() as $motCle) {
        expect(DureeEffet::libelleFin($motCle))->toBeString("« {$motCle} » n'a pas de phrase de fin.");
    }

    foreach (RegainEffet::tous() as $evenement) {
        expect(RegainEffet::libelle($evenement))->toBeString("« {$evenement} » n'a pas de phrase de regain.");
    }
});

it('FAMILLE — CHAQUE déclencheur de DureeEffet, sur chaque buff du catalogue qui le déclare, annonce sa fin', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);
    $heros = $ctx['heros'];
    $renforce = Condition::where('nom', 'Renforcé')->value('id');
    $essayes = [];

    foreach (DureeEffet::toutes() as $declencheur) {
        // Les buffs du catalogue (sorts ET potions) qui déclarent ce déclencheur.
        $sources = [
            ...Sort::all()->filter(fn ($x) => DureeEffet::correspond($x->effet['duree'] ?? null, $declencheur))
                ->map(fn ($x) => ['sort:'.$x->nom, $x->nom])->all(),
            ...Objet::all()->filter(fn ($x) => DureeEffet::correspond($x->effet['duree'] ?? null, $declencheur))
                ->map(fn ($x) => ['potion:'.$x->nom, $x->nom])->all(),
        ];

        foreach ($sources as [$source, $nom]) {
            $avant = App\Models\Evenement::where('groupe_id', $ctx['groupe']->id)->count();
            $heros->conditions()->attach($renforce, ['duree' => 0, 'source' => $source]);

            app(MoteurSorts::class)->expirerBuffs($heros->fresh(), $declencheur);

            $textes = App\Models\Evenement::where('groupe_id', $ctx['groupe']->id)->orderBy('sequence')->get()
                ->slice($avant)->map(fn ($e) => $e->payload['texte'] ?? '')->all();

            expect(str_contains(implode(' ¦ ', $textes), "({$nom}) ".DureeEffet::libelleFin($declencheur)))
                ->toBeTrue("« {$declencheur} » sur « {$nom} » tombe en silence.");
            $essayes[$declencheur] = true;
        }
    }

    // Tous les déclencheurs que le catalogue porte ont été éprouvés (ceux qu'il ne porte pas n'ont rien à annoncer).
    expect(array_keys($essayes))->toContain(DureeEffet::PREMIER_DEGAT_SUBI, DureeEffet::PROCHAINE_ATTAQUE, DureeEffet::PROCHAINE_DEFENSE);
});

// =====================================================================
// 2. LE CHANGEMENT DE FORME D'UN BOSS — une fois, avec ses stats, à sa place
// =====================================================================

it('EN JEU — Gruulob change de forme : UNE phrase, après le coup, avec les nouvelles stats (attaque 3 → 4, défense 4 → 5)', function () {
    $ctx = demarrerQueteAvecMonstre('Gruulob, Sorcier Gobelin Corrompu', ['classe' => 'barbare']);
    $ctx['instance']->update(['pv_body' => 1, 'capacites_reactives_utilisees' => ['ignore_degats_attaque', 'increvable_une_fois']]);

    desFiges(array_fill(0, 40, 1)); // que des crânes

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $reponse = test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertAccepted();

    $resultat = $reponse->json('resultat');
    expect($resultat['changement_phase'])->toMatchArray(['avant' => 'Gruulob, Sorcier Gobelin Corrompu', 'apres' => 'Gruulob, Forme Démoniaque']);

    $isole = fn (array $lignes) => array_values(array_filter($lignes, fn ($t) => str_contains($t, 'vacille')));
    $attendu = 'Gruulob, Sorcier Gobelin Corrompu vacille — et se relève sous une autre forme : Gruulob, Forme Démoniaque !'
        .' (Attaque 3 → 4 dés, défense 4 → 5 dés, Body ';

    // En direct : UNE ligne, riche, et pas la ligne de repli.
    $direct = dureeFilDirect($resultat);
    expect($isole($direct))->toHaveCount(1)
        ->and($isole($direct)[0])->toStartWith($attendu)
        ->and(implode(' ¦ ', $direct))->not->toContain('Un effet automatique');

    // Au rejeu : UNE ligne aussi (l'événement autonome et le coup qui l'a provoqué ne se doublent pas)…
    $rejoue = dureeFilRejoue($ctx);
    expect($isole($rejoue))->toHaveCount(1)
        ->and($isole($rejoue)[0])->toStartWith($attendu)
        ->and(implode(' ¦ ', $rejoue))->not->toContain('Un effet automatique');

    // …et APRÈS la ligne du coup, jamais avant.
    $iPhase = array_search($isole($rejoue)[0], $rejoue, true);
    $iCoup = collect($rejoue)->search(fn ($t) => str_contains($t, 'Albrecht') && str_contains($t, 'Gruulob') && ! str_contains($t, 'vacille'));
    expect($iCoup)->not->toBeFalse()->and($iCoup)->toBeLessThan($iPhase);
});

it('REJEU — un changement de forme que l\'action ne relaie PAS (piège, terrain, faveur) se dit quand même, une fois', function () {
    $fil = new JournalCombat;
    $autonome = ['type' => 'changement_phase', 'instance_id' => 3, 'nom' => 'Gruulob', 'phase' => [
        'avant' => 'Gruulob A', 'apres' => 'Gruulob B',
    ]];

    $seul = array_column($fil->depuisEvenements([[$autonome, 'Le maître du jeu']]), 'texte');
    expect($seul)->toHaveCount(1)->and($seul[0])->toContain('Gruulob A vacille');

    // Avec un parent qui la porte déjà : le parent la dit, l'autonome se tait.
    $parent = ['type' => 'attaque', 'degats' => 1, 'cible' => ['nom' => 'Gruulob A'], 'changement_phase' => ['avant' => 'Gruulob A', 'apres' => 'Gruulob B']];
    $deux = array_column($fil->depuisEvenements([[$autonome, 'Albrecht'], [$parent, 'Albrecht']]), 'texte');
    expect(array_filter($deux, fn ($t) => str_contains($t, 'vacille')))->toHaveCount(1);

    // Les événements déjà ÉCRITS en base avant ce correctif (clé `changement_phase`, type `changement_phase`) ne se doublent pas non plus.
    $ancien = ['type' => 'changement_phase', 'nom' => 'Gruulob', 'changement_phase' => ['avant' => 'Gruulob A', 'apres' => 'Gruulob B']];
    expect(array_filter(array_column($fil->depuisEvenements([[$ancien, 'x']]), 'texte'), fn ($t) => str_contains($t, 'vacille')))->toHaveCount(1);
});

it('EN JEU — l\'APPARITION d\'un boss à l\'ouverture de sa salle se dit (Gruulob apparaît)', function () {
    $ctx = demarrerQueteAvecMonstre('Gruulob, Sorcier Gobelin Corrompu', ['classe' => 'barbare']);
    $quete = $ctx['quete']->fresh()->load('carte');

    // Une salle que le groupe n'a PAS encore vue, avec une case libre pour le boss.
    $salle = null;
    foreach ((array) $quete->carte->grille['salles'] as $i => $s) {
        if (in_array($i, $quete->sallesDecouvertes(), true)) {
            continue;
        }

        for ($y = $s['y']; $y < $s['y'] + $s['hauteur'] && $salle === null; $y++) {
            for ($x = $s['x']; $x < $s['x'] + $s['largeur'] && $salle === null; $x++) {
                if (caseQueteLibre($quete, $x, $y)) {
                    $salle = $i;
                    $ctx['instance']->update(['position_x' => $x, 'position_y' => $y, 'revele' => false]);
                }
            }
        }
    }
    expect($salle)->not->toBeNull('aucune salle inconnue — scénario invalide');

    $revele = new ReflectionMethod(ResolveurTour::class, 'revelerSalle');
    $revele->invoke(app(ResolveurTour::class), $ctx['groupe']->fresh(), $quete, $salle, $ctx['heros']);

    expect(dureeFilRejoue($ctx))->toContain('Gruulob, Sorcier Gobelin Corrompu apparaît !');
});

// =====================================================================
// 3. LES OBJETS — qui boit, ce que ça fait
// =====================================================================

function dureeBoire(array $ctx, string $nomPotion, ?int $cibleId = null): array
{
    $ligne = Inventaire::create([
        'personnage_id' => $ctx['heros']->id, 'objet_id' => Objet::where('nom', $nomPotion)->firstOrFail()->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $cibleId ?? $ctx['heros']->id, 'cible_type' => 'heros'],
    ])->assertAccepted()->json('resultat');
}

it('EN JEU — Fiole de soin : qui, combien de PV rendus (dé), où il en est', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);
    $ctx['heros']->update(['pv_body' => 2, 'pv_body_max' => 8]);
    desFiges([4, ...array_fill(0, 40, 4)]);

    $resultat = dureeBoire($ctx, 'Fiole de soin');
    $direct = dureeFilDirect($resultat);

    expect($direct)->toContain('Albrecht boit Fiole de soin : +4 PV de Body (dé 4) — Albrecht est à 6/8 PV');
    expect(dureeFilRejoue($ctx))->toContain('Albrecht boit Fiole de soin : +4 PV de Body (dé 4) — Albrecht est à 6/8 PV');
});

it('EN JEU — Potion de défense : la cible ET l\'effet (+2 dés de défense, jusqu\'à la prochaine défense)', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);

    $resultat = dureeBoire($ctx, 'Potion de défense');
    $direct = implode(' ¦ ', dureeFilDirect($resultat));

    expect($direct)->toContain('Albrecht boit Potion de défense : « Renforcé »')
        ->toContain('2')->toContain('défense')
        ->and($direct)->not->toContain('utilise Potion de défense');
});

it('EN JEU — Potion d\'héroïsme : la seconde attaque est annoncée ET jouable', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);

    $resultat = dureeBoire($ctx, 'Potion d\'héroïsme');
    expect(dureeFilDirect($resultat))->toContain('Albrecht boit Potion d\'héroïsme : une seconde attaque ce tour');

    // Elle FAIT quelque chose : après la première frappe, une seconde est offerte par le menu et acceptée.
    desFiges(array_fill(0, 30, 6));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertAccepted();

    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue();
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id));
    expect(collect($menu['menu']['options'] ?? [])->contains(fn ($o) => ($o['type'] ?? null) === 'attaque'))->toBeTrue();
});

// =====================================================================
// 4. LES VOTES — une ligne, et elle survit au retour au hub
// =====================================================================

it('EN JEU — « on continue » (retraite non appliquée) a une LIGNE au fil, rien d\'autre ne change', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);

    app(VoteGroupe::class)->lancerRetraite($ctx['groupe'], ['id' => $ctx['alice']->id]);
    $r = app(VoteGroupe::class)->voter($ctx['groupe']->fresh(), $ctx['alice'], 'continuer');

    expect($r['resultat']['applique'])->toBeFalse()
        ->and($r['resultat']['texte'])->toBe('Vote de retraite (0 recommencer, 0 arreter, 1 continuer) : on continue — rien ne change, la quête se poursuit');

    expect(dureeFilRejoue($ctx))->toContain($r['resultat']['texte']);
});

it('EN JEU — le vote de SORTIE appliqué : la ligne est écrite AVANT le retour au hub, et le hub la relit', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);
    $ctx['instance']->update(['etat' => 'vaincu', 'pv_body' => 0]);
    $queteId = $ctx['quete']->id;

    app(VoteGroupe::class)->lancerSortie($ctx['groupe'], ['id' => $ctx['alice']->id]);
    $r = app(VoteGroupe::class)->voter($ctx['groupe']->fresh(), $ctx['alice'], 'oui');

    expect($r['resultat']['applique'])->toBeTrue()
        ->and($r['resultat']['texte'])->toContain('le groupe quitte le donjon et rentre au hub');

    // Le groupe est au hub : le fil de la quête n'est plus lu…
    $etat = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    expect($etat['journal_combat'])->toBe([]);

    // …mais la résolution reste lisible dans l'état du hub (`groupe.vote_sortie`).
    expect($etat['groupe']['vote_sortie']['texte'] ?? null)->toBe($r['resultat']['texte'])
        ->and($etat['groupe']['vote_sortie']['quete_id'])->toBe($queteId);

    // Et l'événement de fil est bien rattaché à LA QUÊTE (pas orphelin), pour le rejeu.
    $evenement = App\Models\Evenement::where('groupe_id', $ctx['groupe']->id)->get()
        ->first(fn ($e) => ($e->payload['type'] ?? null) === 'vote_resolu');
    expect($evenement)->not->toBeNull()->and((int) $evenement->quete_id)->toBe($queteId);
});

// =====================================================================
// 5. Petites phrases
// =====================================================================

it('« Gobelin surgit du coffre » nomme le FOUILLEUR', function () {
    $lignes = array_column((new JournalCombat)->depuisResultat(
        ['type' => 'fouille_tresor', 'issue' => 'errant', 'monstre' => ['nom' => 'Gobelin']], 'Tamsin',
    ), 'texte');

    expect($lignes)->toBe(['Gobelin surgit du coffre que fouille Tamsin !']);
});

it('une défense à usage unique journalisée à part NOMME la créature, et ne double pas son parent', function () {
    $fil = new JournalCombat;
    $autonome = ['type' => 'reaction_monstre', 'instance_id' => 3, 'nom' => 'Gruzbella', 'mecanique' => 'ignore_degats_attaque'];

    expect(array_column($fil->depuisEvenements([[$autonome, 'Albrecht']]), 'texte'))
        ->toBe(['Gruzbella ignore intégralement le coup — une défense à usage unique vient de jouer']);

    $parent = ['type' => 'attaque', 'degats' => 0, 'cible' => ['nom' => 'Gruzbella'], 'reaction_monstre' => 'ignore_degats_attaque'];
    $textes = array_column($fil->depuisEvenements([[$autonome, 'Albrecht'], [$parent, 'Albrecht']]), 'texte');

    expect(array_filter($textes, fn ($t) => str_contains($t, 'ignore intégralement')))->toHaveCount(1);
});
