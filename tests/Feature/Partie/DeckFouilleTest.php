<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Fouille\DeckFouille;
use App\Partie\Sauvegarde;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Deck de cartes de fouille + coffre à artefact (doc 04 §4/§6, doc 14 §3.2).
 *
 * Deux mécaniques à la HeroQuest introduites ensemble : la fouille pioche une
 * carte SANS REMISE dans un deck bâti au démarrage (composition garantie, plus
 * seulement probable), et la salle la plus profonde abrite au plus UNE arme
 * unique — la première source de butin en nature du projet.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class]);
});

/**
 * Quête démarrée avec deux héros (le second empêche la phase des monstres de
 * s'enchaîner après l'action du premier).
 *
 * @return array{0: JoueurAuthentifiable, 1: \App\Models\Groupe, 2: \App\Models\Personnage, 3: Quete, 4: EtatPersonnageQuete}
 */
function demarrerFouille(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    return [$alice, $groupe, $hero, $quete, $etat];
}

/** Fouille la salle courante et rend le payload `resultat`. */
function fouiller(): array
{
    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->json('resultat');
}

/**
 * Rend la salle `$salle` fouillable par le héros : on l'y téléporte, on la
 * marque découverte, et on lui rend son action (le menu est régénéré). Évite
 * d'avoir à traverser le donjon pour atteindre le coffre, qui est par
 * construction la salle la plus profonde.
 */
function deplacerVersSalle(Quete $quete, EtatPersonnageQuete $etat, int $salle): void
{
    $s = $quete->carte->grille['salles'][$salle];

    // refresh() indispensable : sur une instance chargée AVANT l'action, les
    // drapeaux de tour valent encore false en mémoire, donc update() ne les
    // voit pas « sales » et n'écrit rien — le héros resterait a_agi en base.
    $etat->refresh();
    $etat->update([
        'position_x' => (int) $s['x'],
        'position_y' => (int) $s['y'],
        'a_joue' => false,
        'a_agi' => false,
        'a_deplace' => false,
    ]);
    $quete->marquerSalleDecouverte($salle);

    // Sans menu à jour, « Fouiller — trésor » serait refusée comme illégale
    // (le moteur n'accepte que les options du DERNIER menu proposé).
    $groupe = $quete->groupe;
    $personnage = $etat->personnage_id;
    GenererMenu::dispatchSync(
        $groupe->id,
        (int) \App\Models\Personnage::findOrFail($personnage)->joueur_id,
        (int) $personnage,
    );
}

// ---------------------------------------------------------------------------
// Construction du deck
// ---------------------------------------------------------------------------

it('bâtit le deck depuis la composition du gabarit, avec plus de cartes que de salles', function () {
    [, , , $quete, ] = demarrerFouille();

    $deck = $quete->deckFouille();
    $nbSalles = count($quete->carte->grille['salles']);

    // Deck du JEU DE PLATEAU : 24 cartes, toujours plus que le nombre de salles.
    expect(count($deck))->toBeGreaterThan($nbSalles);

    $parIssue = collect($deck)->countBy('issue');
    expect($parIssue['tresor'] ?? 0)->toBe(8)     // 2 gemmes + 2×25 + 2×15 + 2 bijoux
        ->and($parIssue['potion'] ?? 0)->toBe(6)  // 3 soin + héroïsme + force + défense
        ->and($parIssue['errant'] ?? 0)->toBe(6)  // la carte la plus fréquente du plateau
        ->and($parIssue['piege'] ?? 0)->toBe(4);  // 2 trous + 2 volées de flèches

    // Les montants sont ceux des cartes, pas une valeur unique.
    expect(collect($deck)->where('issue', 'tresor')->pluck('or')->unique()->sort()->values()->all())
        ->toBe([15, 25, 35, 50]);

    // Chaque carte est AUTO-SUFFISANTE : montant et identité figés.
    foreach ($deck as $carte) {
        if ($carte['issue'] === 'potion') {
            expect(Objet::find($carte['objet_id'])?->categorie)->toBe('consommable');
        }
        if ($carte['issue'] === 'piege') {
            expect($carte['variante'])->toBeIn(['trou', 'fleches']);
        }
    }
});

it('rend un deck de MÊME composition mais d\'ordre différent à chaque construction', function () {
    [, $groupe, , $quete, ] = demarrerFouille();

    $service = app(DeckFouille::class);
    $gabarit = $quete->gabarit;
    $carte = $quete->carte->grille;

    $a = $service->construire($gabarit, $carte, $groupe, 1);
    $b = $service->construire($gabarit, $carte, $groupe, 1);

    // Ce test vérifiait l'INVERSE jusqu'au 2026-08-05 : la graine dérivait du
    // groupe et de la position d'arc, donc « rejouer une partie redonne la même
    // pioche ». Décision de René : le deck se rebrasse à chaque partie, reprises
    // comprises — sinon un « Recommencer la quête » ou une reprise après TPK
    // livre au groupe la liste ordonnée de ses trésors, pièges et errants.
    $composition = fn (array $r) => collect($r['deck'])
        ->map(fn (array $c) => json_encode($c))->sort()->values()->all();

    expect($composition($a))->toEqual($composition($b))
        ->and($a['deck'])->not->toEqual($b['deck']);
});

it('remet la carte piochée SOUS le paquet : le deck cycle, il ne s\'épuise pas', function () {
    [, , , $quete, ] = demarrerFouille();

    $avant = count($quete->deckFouille());

    empilerCarteFouille($quete, ['issue' => 'rien', 'marqueur' => 'A']);
    fouiller();

    $apres = $quete->fresh()->deckFouille();

    // Règle du plateau : la carte repart dessous. Avec une fouille par héros ET
    // par salle, le deck serait sinon vidé sur un grand donjon.
    expect(count($apres))->toBe($avant + 1)
        ->and(end($apres)['marqueur'] ?? null)->toBe('A');
});

it('rétrograde en « rien » quand le deck est épuisé', function () {
    [, $groupe, , $quete, ] = demarrerFouille();

    $quete->update(['deck_fouille' => []]);

    $resultat = fouiller();

    expect($resultat['issue'])->toBe('rien')
        ->and($resultat['deck_vide'])->toBeTrue()
        ->and((int) $groupe->fresh()->or)->toBe(0);
});

// ---------------------------------------------------------------------------
// Butin en nature
// ---------------------------------------------------------------------------

it('range la potion trouvée dans les consommables du FOUILLEUR, hors capacité de sac', function () {
    [, , $hero, $quete, ] = demarrerFouille();

    $potion = Objet::where('nom', 'Potion de soin')->firstOrFail();
    empilerCarteFouille($quete, ['issue' => 'potion', 'objet_id' => $potion->id]);

    $resultat = fouiller();

    expect($resultat['issue'])->toBe('potion')
        ->and($resultat['objet']['nom'])->toBe('Potion de soin')
        ->and($resultat)->not->toHaveKey('sac_deborde'); // un consommable ne pèse pas

    $ligne = Inventaire::where('personnage_id', $hero->id)->where('objet_id', $potion->id)->firstOrFail();
    expect($ligne->emplacement)->toBe('consommable')
        ->and((int) $ligne->quantite)->toBe(1);
});

it('EMPILE une seconde potion identique au lieu de créer une ligne', function () {
    [, , $hero, $quete, $etat] = demarrerFouille();

    $potion = Objet::where('nom', 'Potion de soin')->firstOrFail();

    empilerCarteFouille($quete, ['issue' => 'potion', 'objet_id' => $potion->id]);
    fouiller();

    // Autre salle : une salle ne se fouille qu'une fois.
    deplacerVersSalle($quete, $etat, 1);
    empilerCarteFouille($quete->fresh(), ['issue' => 'potion', 'objet_id' => $potion->id]);
    fouiller();

    $lignes = Inventaire::where('personnage_id', $hero->id)->where('objet_id', $potion->id)->get();
    expect($lignes)->toHaveCount(1)
        ->and((int) $lignes->first()->quantite)->toBe(2);
});

// ---------------------------------------------------------------------------
// Coffre à artefact
// ---------------------------------------------------------------------------

it('désigne une salle-coffre qui n\'est jamais la salle de départ, et lui attribue une arme unique', function () {
    [, , , $quete, ] = demarrerFouille();

    expect($quete->salle_artefact)->not->toBeNull()
        ->and((int) $quete->salle_artefact)->not->toBe(0);

    $arme = Objet::find($quete->artefact_objet_id);
    expect($arme)->not->toBeNull()
        ->and($arme->rarete)->toBe('unique')
        ->and($arme->categorie)->toBe('arme');
});

it('remet l\'artefact au fouilleur du coffre SANS consommer de carte du deck', function () {
    [, , $hero, $quete, $etat] = demarrerFouille();

    $salle = (int) $quete->salle_artefact;
    $arme = Objet::findOrFail($quete->artefact_objet_id);
    $deckAvant = count($quete->deckFouille());

    deplacerVersSalle($quete, $etat, $salle);
    $resultat = fouiller();

    expect($resultat['issue'])->toBe('artefact')
        ->and($resultat['coffre'])->toBeTrue()
        ->and($resultat['objet']['nom'])->toBe($arme->nom)
        // Le coffre désigné est un BONUS NET : la pioche est intacte.
        ->and(count($quete->fresh()->deckFouille()))->toBe($deckAvant);

    $ligne = Inventaire::where('personnage_id', $hero->id)->where('objet_id', $arme->id)->firstOrFail();
    expect($ligne->emplacement)->toBe('sac');
});

it('ne donne qu\'UN SEUL artefact, même en fouillant toutes les salles', function () {
    [, , $hero, $quete, $etat] = demarrerFouille();

    foreach (array_keys($quete->carte->grille['salles']) as $salle) {
        deplacerVersSalle($quete->fresh(), $etat, (int) $salle);
        fouiller();
    }

    // Les ARMES uniques : la fiole de soin du deck est aussi `unique` (hors
    // étal), elle ne doit pas être comptée comme un artefact.
    $armes = Inventaire::where('personnage_id', $hero->id)
        ->whereHas('objet', fn ($q) => $q->where('rarete', 'unique')->where('categorie', 'arme'))
        ->count();

    expect($armes)->toBe(1);
});

it('rebrasse le deck à CHAQUE construction, jamais deux fois le même ordre', function () {
    [, $groupe, , $quete, ] = demarrerFouille();

    // La graine dérivait de crc32("{identifiant}:{positionArc}:fouille") : deux
    // constructions pour le même groupe et la même quête donnaient un ordre
    // identique, donc un butin connu d'avance dès la seconde tentative.
    $ordres = [];
    for ($i = 0; $i < 5; $i++) {
        $ordres[] = json_encode(
            app(DeckFouille::class)->construire($quete->gabarit, $quete->carte->grille, $groupe, 1)['deck']
        );
    }

    expect(array_unique($ordres))->toHaveCount(5);
});

it('exclut du tirage une arme unique déjà possédée par un héros du groupe', function () {
    [, $groupe, $hero, $quete, ] = demarrerFouille();

    $dejaLa = Objet::findOrFail($quete->artefact_objet_id);
    Inventaire::create([
        'personnage_id' => $hero->id, 'objet_id' => $dejaLa->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    $suivant = app(DeckFouille::class)->construire($quete->gabarit, $quete->carte->grille, $groupe, 1);

    expect($suivant['artefact_objet_id'])->not->toBeNull()
        ->and($suivant['artefact_objet_id'])->not->toBe($dejaLa->id);
});

it('verse `or_coffre` quand aucune arme unique n\'est disponible', function () {
    [, $groupe, , $quete, $etat] = demarrerFouille();

    $salle = (int) $quete->salle_artefact;
    poserCoffreArtefact($quete, $salle, null); // toutes les uniques déjà trouvées

    deplacerVersSalle($quete->fresh(), $etat, $salle);
    $resultat = fouiller();

    expect($resultat['issue'])->toBe('tresor')
        ->and($resultat['coffre'])->toBeTrue()
        ->and($resultat['or'])->toBe(90) // gabarit « normale »
        ->and((int) $groupe->fresh()->or)->toBe(90);
});

it('remet l\'artefact MÊME sac plein, en dépassement signalé', function () {
    [, , $hero, $quete, $etat] = demarrerFouille();

    // Sac saturé de bric-à-brac non consommable.
    $babiole = Objet::where('categorie', '!=', 'consommable')->where('rarete', 'commun')->firstOrFail();
    for ($i = 0; $i < 12; $i++) {
        Inventaire::create([
            'personnage_id' => $hero->id, 'objet_id' => $babiole->id,
            'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }

    $salle = (int) $quete->salle_artefact;
    $arme = Objet::findOrFail($quete->artefact_objet_id);

    deplacerVersSalle($quete, $etat, $salle);
    $resultat = fouiller();

    // Refuser l'objet le perdrait à jamais : on le remet et on le signale.
    expect($resultat['issue'])->toBe('artefact')
        ->and($resultat['sac_deborde'])->toBeTrue()
        ->and(Inventaire::where('personnage_id', $hero->id)->where('objet_id', $arme->id)->exists())->toBeTrue();
});

it('ne remet PAS deux fois l\'artefact quand un second héros fouille la même salle', function () {
    [, $groupe, $hero, $quete, $etat] = demarrerFouille();

    $salle = (int) $quete->salle_artefact;
    $arme = Objet::findOrFail($quete->artefact_objet_id);

    deplacerVersSalle($quete, $etat, $salle);
    expect(fouiller()['issue'])->toBe('artefact');

    // La fouille est « une par héros et par salle » : le compagnon peut fouiller
    // le MÊME coffre. L'unicité étant par groupe (elle n'était vérifiée qu'à la
    // construction du deck), il repartait avec un second exemplaire — constaté
    // en test de jeu 2026-08-05. Il touche désormais l'or du coffre.
    $second = $groupe->personnages()->where('personnages.id', '!=', $hero->id)->firstOrFail();
    $carte = app(DeckFouille::class)->carteCoffre($quete->fresh(), $salle);

    expect($carte['issue'])->toBe('tresor')
        ->and($carte['coffre'])->toBeTrue()
        ->and($carte)->not->toHaveKey('objet_id')
        ->and(Inventaire::where('personnage_id', $second->id)->where('objet_id', $arme->id)->exists())
        ->toBeFalse();

    // …et l'exemplaire déjà trouvé reste bien le seul du groupe.
    $exemplaires = Inventaire::whereIn('personnage_id', $groupe->personnages()->pluck('personnages.id'))
        ->where('objet_id', $arme->id)->count();
    expect($exemplaires)->toBe(1);
});

// ---------------------------------------------------------------------------
// Persistance (§2.16 : plus aucun état de jeu durable en cache)
// ---------------------------------------------------------------------------

it('fait surgir un errant à CHAQUE carte, sans plafond', function () {
    [, , , $quete, $etat] = demarrerFouille();

    $avant = $quete->instancesMonstres()->where('etat', 'actif')->count();

    // La carte errant est la plus fréquente du deck (6 sur 24) et elle revient
    // sous le paquet : un budget qui s'épuise en aurait fait une carte blanche.
    foreach ([0, 1, 2] as $tour) {
        deplacerVersSalle($quete->fresh(), $etat, $tour);
        empilerCarteFouille($quete->fresh(), ['issue' => 'errant']);
        $resultat = fouiller();

        expect($resultat['issue'])->toBe('errant')
            ->and($resultat)->not->toHaveKey('errant_indisponible');
    }

    expect($quete->fresh()->instancesMonstres()->where('etat', 'actif')->count())->toBe($avant + 3);
});

it('restaure le deck et les salles fouillées depuis un snapshot `nouveau_tour`', function () {
    [, $groupe, , $quete, $etat] = demarrerFouille();

    // Une salle fouillée, puis snapshot de ce tour-là.
    empilerCarteFouille($quete, ['issue' => 'rien']);
    fouiller();

    $sauvegarde = app(Sauvegarde::class);
    $snapshot = $sauvegarde->snapshotter($groupe->fresh(), Sauvegarde::ETIQUETTE_NOUVEAU_TOUR);

    $deckAuSnapshot = $quete->fresh()->deckFouille();
    $fouilleesAuSnapshot = $quete->fresh()->tresorsFouilles();
    expect($fouilleesAuSnapshot)->toHaveCount(1);

    // On continue de jouer : une seconde salle fouillée.
    deplacerVersSalle($quete->fresh(), $etat, 1);
    fouiller();
    expect($quete->fresh()->tresorsFouilles())->toHaveCount(2);

    $sauvegarde->restaurer($groupe->fresh(), $snapshot);

    // La reprise rend l'état DE CE TOUR-LÀ : elle rouvrait auparavant toutes
    // les salles à la fouille (farm d'or, et duplication d'artefact).
    expect($quete->fresh()->tresorsFouilles())->toEqual($fouilleesAuSnapshot);

    // Le deck, lui, est REMÉLANGÉ : même composition, ordre rebrassé (décision
    // de René, 2026-08-05 — une reprise ne doit pas rendre la pioche connue).
    $deckRepris = $quete->fresh()->deckFouille();
    expect($deckRepris)->toHaveCount(count($deckAuSnapshot));

    $signature = fn (array $deck) => collect($deck)
        ->map(fn (array $c) => json_encode($c))->sort()->values()->all();

    expect($signature($deckRepris))->toEqual($signature($deckAuSnapshot));
});

it('remélange le deck à la reprise : même composition, ordre différent', function () {
    [, $groupe, , $quete, ] = demarrerFouille();

    $sauvegarde = app(Sauvegarde::class);
    $snapshot = $sauvegarde->snapshotter($groupe->fresh(), Sauvegarde::ETIQUETTE_NOUVEAU_TOUR);
    $deckAuSnapshot = $quete->fresh()->deckFouille();

    // Sur un deck de 24 cartes, retomber sur le même ordre est de l'ordre de
    // 1/24! — plusieurs reprises rendent le faux positif impossible en pratique.
    $ordresVus = [];
    for ($i = 0; $i < 5; $i++) {
        $sauvegarde->restaurer($groupe->fresh(), $snapshot);
        $ordresVus[] = json_encode($quete->fresh()->deckFouille());
    }

    expect(array_unique($ordresVus))->toHaveCount(5)
        ->and($ordresVus)->not->toContain(json_encode($deckAuSnapshot));
});

it('rend l\'artefact re-trouvable UNE SEULE FOIS après une reprise en début de quête', function () {
    [, $groupe, $hero, $quete, $etat] = demarrerFouille();

    $salle = (int) $quete->salle_artefact;
    $arme = Objet::findOrFail($quete->artefact_objet_id);

    deplacerVersSalle($quete, $etat, $salle);
    fouiller();
    expect(Inventaire::where('personnage_id', $hero->id)->where('objet_id', $arme->id)->count())->toBe(1);

    app(Sauvegarde::class)->redemarrerQuete($groupe->fresh());

    // L'inventaire est purgé par la restauration : l'artefact redevient à
    // prendre, dans la même salle, en un seul exemplaire.
    $quete = $quete->fresh();
    expect($quete->tresorsFouilles())->toBeEmpty()
        ->and((int) $quete->salle_artefact)->toBe($salle);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    deplacerVersSalle($quete, $etat, $salle);
    fouiller();

    expect(Inventaire::where('personnage_id', $hero->id)->where('objet_id', $arme->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Artefact à MAÎTRISE LOURDE (nœud barbare) — jouable grâce au don entre héros
// ---------------------------------------------------------------------------

it('n\'attribue jamais une arme à maîtrise lourde à un groupe SANS barbare', function () {
    [, $groupe, , $quete, ] = demarrerFouille();

    // demarrerFouille() crée deux héros ; on s'assure qu'aucun n'est barbare.
    $groupe->personnages()->update(['classe' => 'elfe']);

    $fendoir = Objet::where('nom', 'Fendoir des Titans')->firstOrFail();

    // Toutes les autres uniques sont déjà détenues : seul le Fendoir reste
    // disponible. Sans barbare, il est écarté → le coffre doit verser de l'or.
    foreach (Objet::where('rarete', 'unique')->where('id', '!=', $fendoir->id)->get() as $autre) {
        Inventaire::create([
            'personnage_id' => $groupe->personnages()->first()->id,
            'objet_id' => $autre->id, 'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }

    $choix = app(DeckFouille::class)->construire($quete->gabarit, $quete->carte->grille, $groupe, 1);

    expect($choix['artefact_objet_id'])->toBeNull(); // butin mort évité
});

it('attribue l\'arme à maîtrise lourde dès qu\'un barbare est actif', function () {
    [, $groupe, $hero, $quete, ] = demarrerFouille();

    $hero->update(['classe' => 'barbare']);

    $fendoir = Objet::where('nom', 'Fendoir des Titans')->firstOrFail();

    foreach (Objet::where('rarete', 'unique')->where('id', '!=', $fendoir->id)->get() as $autre) {
        Inventaire::create([
            'personnage_id' => $hero->id,
            'objet_id' => $autre->id, 'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }

    $choix = app(DeckFouille::class)->construire($quete->gabarit, $quete->carte->grille, $groupe, 1);

    expect($choix['artefact_objet_id'])->toBe($fendoir->id);
});

it('place un coffre derrière CHAQUE porte secrète, en plus de celui du fond', function () {
    [, $groupe, , $quete, ] = demarrerFouille();

    $carte = $quete->carte->grille;
    $choix = app(DeckFouille::class)->construire($quete->gabarit, $carte, $groupe, 1);

    // Trouver un passage caché ne rapportait rien — juste un raccourci. Le
    // coffre est ce qui paie la fouille.
    $aretes = $carte['aretes'];
    $attendues = collect($carte['portes'])->where('etat', 'secrete')->pluck('jonction')->unique()
        ->map(fn ($j) => $aretes[$j] ?? null)->filter()
        ->map(fn ($a) => [(int) $a['a'], (int) $a['b']]);

    foreach ($attendues as [$a, $b]) {
        expect(array_intersect([$a, $b], $choix['salles_coffre']))->not->toBeEmpty();
    }

    // La salle du fond en garde un, et jamais la salle de départ.
    expect($choix['salles_coffre'])->toContain($choix['salle_artefact'])
        ->and($choix['salles_coffre'])->not->toContain(0);
});

it('rend or ou potion dans un coffre ordinaire, l\'arme unique n\'étant que dans celui du fond', function () {
    [, , $hero, $quete, $etat] = demarrerFouille();

    // Une salle à coffre qui n'est PAS celle de l'artefact.
    $autre = collect(range(1, count($quete->carte->grille['salles']) - 1))
        ->first(fn ($s) => $s !== (int) $quete->salle_artefact);
    $quete->update(['salles_coffre' => [$autre]]);

    deplacerVersSalle($quete->fresh(), $etat, (int) $autre);
    $resultat = fouiller();

    // L'arme unique récompense la progression jusqu'au fond, pas un raccourci.
    expect($resultat['coffre'])->toBeTrue()
        ->and($resultat['issue'])->toBeIn(['tresor', 'potion'])
        ->and($resultat['issue'])->not->toBe('artefact');

    if ($resultat['issue'] === 'potion') {
        expect(Inventaire::where('personnage_id', $hero->id)->exists())->toBeTrue();
    }
});

it('donne à CHAQUE héros sa fouille dans une même salle', function () {
    [, $groupe, $albrecht, $quete, $etat] = demarrerFouille();
    $brunhilde = $groupe->personnages()->where('nom', 'Brunhilde')->firstOrFail();

    empilerCarteFouille($quete, ['issue' => 'tresor', 'or' => 15]);
    fouiller();

    // Le premier fouilleur ne ferme plus la salle : au plateau, chaque héros
    // tire sa propre carte de trésor.
    expect($quete->fresh()->aFouille(0, (int) $albrecht->id))->toBeTrue()
        ->and($quete->fresh()->aFouille(0, (int) $brunhilde->id))->toBeFalse();

    // …mais lui ne peut pas recommencer.
    deplacerVersSalle($quete->fresh(), $etat, 0);
    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(422);
});
