<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Fouille\DeckFouille;
use App\Partie\MoteurMobilier;
use App\Partie\Sauvegarde;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
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

    // MobilierSeeder inclus : sans lui aucun meuble n'est posé sur la carte, et
    // la fouille de mobilier n'aurait rien à fouiller.
    // ClasseHerosSeeder : c'est lui qui porte `tags_equipement`, et le choix de
    // l'artefact écarte ce qu'aucune classe ACTIVE ne pourrait porter. Sans ce
    // catalogue la règle tombe en « fail open » et les tests de réservation
    // passeraient sans rien vérifier.
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class]);
});

/**
 * Quête démarrée avec deux héros (le second empêche la phase des monstres de
 * s'enchaîner après l'action du premier).
 *
 * @return array{0: JoueurAuthentifiable, 1: Groupe, 2: Personnage, 3: Quete, 4: EtatPersonnageQuete}
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
        (int) Personnage::findOrFail($personnage)->joueur_id,
        (int) $personnage,
    );
}

// ---------------------------------------------------------------------------
// Construction du deck
// ---------------------------------------------------------------------------

it('bâtit le deck depuis la composition du gabarit, avec plus de cartes que de salles', function () {
    [, , , $quete] = demarrerFouille();

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
    [, $groupe, , $quete] = demarrerFouille();

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
    [, , , $quete] = demarrerFouille();

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
    [, $groupe, , $quete] = demarrerFouille();

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
    [, , $hero, $quete] = demarrerFouille();

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
    [, , , $quete] = demarrerFouille();

    expect($quete->salle_artefact)->not->toBeNull()
        ->and((int) $quete->salle_artefact)->not->toBe(0);

    $arme = Objet::find($quete->artefact_objet_id);
    expect($arme)->not->toBeNull()
        ->and($arme->rarete)->toBe('unique')
        ->and($arme->categorie)->toBeIn(['arme', 'armure']);
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
        ->whereHas('objet', fn ($q) => $q->where('rarete', 'unique')->whereIn('categorie', ['arme', 'armure']))
        ->count();

    expect($armes)->toBe(1);
});

it('rebrasse le deck à CHAQUE construction, jamais deux fois le même ordre', function () {
    [, $groupe, , $quete] = demarrerFouille();

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
    [, $groupe, $hero, $quete] = demarrerFouille();

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
    [, $groupe, , $quete] = demarrerFouille();

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
// Artefact RÉSERVÉ à une classe — écarté quand personne ne pourrait le porter
// ---------------------------------------------------------------------------

it('n\'attribue jamais un artefact réservé à un groupe SANS la classe', function () {
    [, $groupe, , $quete] = demarrerFouille();

    // demarrerFouille() crée deux héros ; on s'assure qu'aucun n'est barbare.
    $groupe->personnages()->update(['classe' => 'elfe']);

    $reserveBarbare = Objet::where('nom', 'Amulette du Nord')->firstOrFail();

    // Toutes les autres uniques sont déjà détenues : seule l'Amulette du Nord
    // reste disponible, et elle est réservée au barbare (`talisman_barbare`).
    // Sans barbare, elle est écartée → le coffre doit verser de l'or, plutôt
    // que de consommer l'unique artefact de la quête en butin mort.
    foreach (Objet::where('rarete', 'unique')->where('id', '!=', $reserveBarbare->id)->get() as $autre) {
        Inventaire::create([
            'personnage_id' => $groupe->personnages()->first()->id,
            'objet_id' => $autre->id, 'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }

    $choix = app(DeckFouille::class)->construire($quete->gabarit, $quete->carte->grille, $groupe, 1);

    expect($choix['artefact_objet_id'])->toBeNull(); // butin mort évité
});

it('attribue l\'artefact réservé dès qu\'un héros de la classe est actif', function () {
    [, $groupe, $hero, $quete] = demarrerFouille();

    $hero->update(['classe' => 'barbare']);

    $reserveBarbare = Objet::where('nom', 'Amulette du Nord')->firstOrFail();

    foreach (Objet::where('rarete', 'unique')->where('id', '!=', $reserveBarbare->id)->get() as $autre) {
        Inventaire::create([
            'personnage_id' => $hero->id,
            'objet_id' => $autre->id, 'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }

    $choix = app(DeckFouille::class)->construire($quete->gabarit, $quete->carte->grille, $groupe, 1);

    expect($choix['artefact_objet_id'])->toBe($reserveBarbare->id);
});

it('place un coffre derrière CHAQUE porte secrète, en plus de celui du fond', function () {
    [, $groupe, , $quete] = demarrerFouille();

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

it('rend le mobilier FOUILLABLE, une fois PAR HÉROS', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerFouille();
    $mm = app(MoteurMobilier::class);

    // Un meuble fouillable quelconque de la carte (le donjon en pose toujours).
    $grille = $quete->carte->grille;
    $fouillables = Mobilier::where('fouillable', true)->pluck('id');
    $index = collect($grille['mobilier'] ?? [])
        ->search(fn (array $m) => $fouillables->contains($m['mobilier_id']));

    expect($index)->not->toBeFalse('aucun meuble fouillable sur cette carte');
    $meuble = $grille['mobilier'][$index];

    // On se place À CÔTÉ : le meuble bloque le passage, on ne monte pas dessus.
    $etat->refresh();
    $etat->update([
        'position_x' => (int) $meuble['x'] + 1, 'position_y' => (int) $meuble['y'],
        'a_joue' => false, 'a_agi' => false, 'a_deplace' => false,
    ]);
    $quete->marquerSalleDecouverte((int) $meuble['salle']);

    expect($mm->fouillablesAdjacents($quete->carte, (int) $meuble['x'] + 1, (int) $meuble['y'], (int) $hero->id))
        ->not->toBeEmpty();

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];

    // ⚠ On vise l'option de CE meuble par son index, pas la première venue : le
    // héros peut se tenir au contact de DEUX pièces fouillables selon la carte
    // tirée, et fouiller l'autre laissait celle-ci ouverte — un échec qui ne
    // parlait que de la graine, pas de la règle testée.
    $option = collect($menu['options'])
        ->firstWhere('id', "fouiller_mobilier_{$index}");

    expect($option)->not->toBeNull('« Fouiller : <meuble> » absent du menu');

    test()->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'fouille_mobilier')
        // ⚠ Aucun `deck_restant` : le meuble tire dans SA table, il ne touche
        // pas au deck de la quête. L'annoncer apprenait au joueur une chose
        // fausse — relevé par la joueuse elfe en partie réelle (2026-08-17).
        ->assertJsonMissingPath('resultat.deck_restant');

    // UNE FOIS PAR HÉROS (décision de René, 2026-08-17) : le fouilleur a
    // dépensé la sienne…
    $carte = $quete->carte->fresh();
    $x = (int) $meuble['x'] + 1;
    $y = (int) $meuble['y'];

    // Seule LA pièce fouillée se ferme pour lui — une voisine, s'il y en a une,
    // reste offerte, et c'est correct.
    expect(collect($mm->fouillablesAdjacents($carte, $x, $y, (int) $hero->id))->pluck('index'))
        ->not->toContain($index);

    // …mais un COMPAGNON garde la sienne. C'est tout le changement : le premier
    // arrivé n'épuise plus la pièce pour le groupe, et l'or cesse de dépendre
    // de qui a atteint le meuble le premier.
    expect($mm->fouillablesAdjacents($carte, $x, $y, (int) $hero->id + 999))->not->toBeEmpty();
});

it('donne à CHAQUE meuble sa table de butin, avec une chance de ne rien trouver', function () {
    // Décision de René du 2026-08-17 : un meuble ne tire plus dans le deck de la
    // quête — un râtelier d'armes pouvait rendre une potion de soin — mais dans
    // SA table, et chaque table doit pouvoir ne rien donner.
    $mm = app(MoteurMobilier::class);

    foreach (Mobilier::where('fouillable', true)->get() as $type) {
        $table = (array) ($type->effet['fouille'] ?? []);

        expect($table)->not->toBeEmpty("{$type->nom} : fouillable sans table de butin.");

        // ⚠ `toContain()` de Pest prend une VALEUR en second argument, pas un
        // message — piège déjà payé ailleurs dans ce dépôt.
        $issues = array_column($table, 'issue');

        expect(in_array('rien', $issues, true))->toBeTrue(
            "{$type->nom} : aucune issue « rien » — un meuble qui donne toujours "
            .'quelque chose fait de l\'exploration une récolte.');

        foreach ($table as $entree) {
            expect((int) ($entree['poids'] ?? 0))->toBeGreaterThan(0,
                "{$type->nom} : une entrée sans poids ne sera jamais tirée.");
        }

        // Le tirage rend toujours une carte applicable, jamais une issue inconnue.
        for ($i = 0; $i < 20; $i++) {
            expect($mm->tirerButin($type)['issue'])->toBeIn(['tresor', 'objet', 'piege', 'rien']);
        }
    }

    // Le râtelier d'armes, l'exemple de René : des armes et des armures, jamais
    // une potion — et une chance réelle de repartir les mains vides.
    $ratelier = Mobilier::where('nom', 'Râtelier d\'armes')->firstOrFail();
    $categories = collect($ratelier->effet['fouille'])
        ->where('issue', 'objet')->pluck('categories')->flatten()->unique()->values()->all();

    expect($categories)->toEqualCanonicalizing(['arme', 'armure']);
});

it('fait mordre le tombeau et l\'établi, chacun par SON piège nommé', function () {
    // Décision de René (2026-08-17) : ces deux meubles se défendent. Le nom
    // voyage avec la carte, parce que lire « Piège de coffre » en ouvrant un
    // tombeau casserait la fiction — le barème, lui, est identique.
    $attendus = [
        'Tombeau' => 'Aiguille empoisonnée',
        'Établi d\'alchimiste' => 'Fiole de poison',
    ];

    foreach ($attendus as $meuble => $piege) {
        $type = Mobilier::where('nom', $meuble)->firstOrFail();
        $entree = collect($type->effet['fouille'])->firstWhere('issue', 'piege');

        expect($entree)->not->toBeNull("{$meuble} : aucune issue « piege ».")
            ->and($entree['piege'])->toBe($piege);

        // Le piège nommé doit EXISTER au catalogue, sinon la fouille se rabat en
        // silence sur le piège de coffre et le joueur lit un nom qui n'est pas
        // celui qu'on lui a promis.
        expect(App\Models\Piege::where('nom', $piege)->exists())->toBeTrue(
            "{$piege} : nommé par un meuble mais absent du catalogue de pièges.");
    }

    // ⚠ L'établi empoisonne À COUP SÛR, il ne cogne pas : c'est le seul piège du
    // catalogue sans branche `aleatoire`. Un dégât sec à la place viderait la
    // décision de René, et le poison n'est pas plus doux — 3 tours à 1 PV.
    $fiole = App\Models\Piege::where('nom', 'Fiole de poison')->firstOrFail();

    expect($fiole->effet['condition_appliquee'] ?? null)->toBe('Empoisonné')
        ->and($fiole->effet['aleatoire'] ?? null)->toBeNull()
        ->and($fiole->effet['degats_pv_body'] ?? 0)->toBe(0);

    // Les deux gardent leur chance de ne rien donner : le piège s'ajoute au
    // hasard, il ne le remplace pas.
    foreach (array_keys($attendus) as $meuble) {
        $issues = array_column(Mobilier::where('nom', $meuble)->firstOrFail()->effet['fouille'], 'issue');
        expect(in_array('rien', $issues, true))->toBeTrue("{$meuble} : le piège a mangé le « rien ».");
    }
});

it('ne paie le coffre de salle qu UNE FOIS : le second fouilleur pioche normalement', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerFouille();

    // Coffre ORDINAIRE (celui qu'ouvre une porte secrète), pas celui de
    // l'artefact : c'est lui qui payait chaque héros. Planté explicitement, le
    // donjon de test n'en produisant pas toujours.
    $salle = collect(array_keys($quete->carte->grille['salles']))
        ->first(fn ($s) => (int) $s !== (int) $quete->salle_artefact && (int) $s !== 0);
    $quete->update(['salles_coffre' => [(int) $salle]]);
    $quete->refresh();

    expect($quete->coffrePlein((int) $salle))->toBeTrue();

    deplacerVersSalle($quete, $etat, (int) $salle);
    $premier = fouiller();

    expect($premier['coffre'] ?? false)->toBeTrue()
        ->and($quete->fresh()->coffrePlein((int) $salle))->toBeFalse();

    // Le compagnon fouille la MÊME salle : il a droit à sa fouille (une par
    // héros), mais le coffre est VIDE — il pioche une carte du deck au lieu de
    // remporter une seconde fois le même butin. À quatre héros, ce coffre
    // payait quatre fois avant correctif (aligné sur le mobilier, décision de
    // René 2026-08-07).
    // Le premier a joué : l'initiative (figée pour la quête) passe au second.
    $etat->refresh();
    $etat->update(['a_joue' => true]);

    $second = Personnage::where('nom', 'Brunhilde')->firstOrFail();
    $etatSecond = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $second->id)->firstOrFail();

    deplacerVersSalle($quete->fresh(), $etatSecond, (int) $salle);

    $resultat = test()->actingAs(JoueurAuthentifiable::where('identifiant', 'bob')->firstOrFail(), 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->json('resultat');

    expect($resultat['coffre'] ?? false)->toBeFalse();
});
