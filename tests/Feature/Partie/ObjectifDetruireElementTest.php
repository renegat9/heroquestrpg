<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Mobilier;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\EtatGroupe;
use App\Partie\MenuMoteur;
use App\Partie\MoteurMobilier;
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
 * OBJECTIF « DÉTRUIRE UN ÉLÉMENT » (René, 2026-10-09) — la quête finale de
 * Wizards of Morcar se gagne en détruisant le Haut Autel (G1504 p. 39 :
 * « When the High Altar is destroyed […] Remove all remaining monsters from
 * play. The quest is won. »). Type générique, réutilisable (Crystal Cluster).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/**
 * Quête minimale 7×7 : un héros en (3,3), l'élément désigné en (4,3), et un
 * second héros-sans-rôle inutile — seul compte le gabarit `Confrontation finale`.
 */
function queteElementObjectif(bool $designe = true, array $herosAttrs = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);
    $type = Mobilier::where('nom', 'Haut Autel')->firstOrFail();

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('nom', 'Confrontation finale')->firstOrFail()->id,
        'titre' => 'Quête de test', 'position_arc' => 1, 'type_jalon' => 'boss_final',
        'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 7, 'hauteur' => 7,
        'grille' => [
            'largeur' => 7, 'hauteur' => 7,
            'cases' => array_fill(0, 7, array_fill(0, 7, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'epreuves' => [],
            'mobilier' => [[
                'mobilier_id' => $type->id, 'x' => 4, 'y' => 3, 'l' => 1, 'h' => 1, 'salle' => 0,
                ...($designe ? ['objectif' => true] : []),
            ]],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);
    $etat = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id, 'position_x' => 3, 'position_y' => 3,
    ]);

    return ['groupe' => $groupe, 'heros' => $heros, 'quete' => $quete->fresh()->load('carte'), 'etat' => $etat];
}

function frapperElement(array $ctx, array $des): array
{
    desFiges($des);

    return app(ResolveurTour::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), [
        'id' => 'attaquer_mobilier_0', 'libelle' => 'Attaquer', 'type' => 'attaquer_mobilier',
        'creneau' => 'action',
        'parametres' => ['mobilier' => 0, 'nom' => 'Haut Autel'],
    ]);
}

// ─── Registre : testé DANS LES DEUX SENS ─────────────────────────────────

it('chaque élément-objectif déclaré existe au catalogue, attaquable, de la bonne boîte', function () {
    foreach (MoteurMobilier::ELEMENT_OBJECTIF_FINAL as $boite => $nom) {
        $meuble = Mobilier::where('nom', $nom)->first();

        expect($meuble)->not->toBeNull()
            ->and($meuble->boite)->toBe($boite)
            ->and($meuble->pv_body)->not->toBeNull();
    }
});

// ─── Assemblage : posé à coup sûr dans la salle du boss ──────────────────

it('pose le Haut Autel à coup sûr dans la salle du boss de la quête FINALE du thème Morcar', function () {
    $assembleur = app(AssembleurCarte::class);
    $final = GabaritQuete::where('type_jalon', 'boss_final')->firstOrFail();
    $autel = Mobilier::where('nom', 'Haut Autel')->firstOrFail();
    $bestiaire = BestiaireGroupe::auto('wizards_of_morcar');

    foreach (range(1, 40) as $i) {
        $carte = $assembleur->assembler($final, $i * 1299721, bestiaire: $bestiaire);
        $designes = collect($carte['mobilier'])->filter(fn ($m) => ! empty($m['objectif']))->values();

        expect($designes)->toHaveCount(1)
            ->and($designes[0]['mobilier_id'])->toBe($autel->id)
            ->and($designes[0]['salle'])->toBe(count($carte['salles']) - 1)
            // Un seul Haut Autel sur la carte : jamais de décor aléatoire en plus.
            ->and(collect($carte['mobilier'])->where('mobilier_id', $autel->id))->toHaveCount(1);
    }
});

it('ne pose JAMAIS de Haut Autel ailleurs : quête ordinaire du thème, quête finale hors thème', function () {
    $assembleur = app(AssembleurCarte::class);
    $autel = Mobilier::where('nom', 'Haut Autel')->firstOrFail();
    $normale = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();
    $final = GabaritQuete::where('type_jalon', 'boss_final')->firstOrFail();

    foreach (range(1, 25) as $i) {
        foreach ([
            $assembleur->assembler($normale, $i * 1299721, bestiaire: BestiaireGroupe::auto('wizards_of_morcar')),
            $assembleur->assembler($final, $i * 1299721, bestiaire: BestiaireGroupe::auto('first_light')),
            $assembleur->assembler($final, $i * 1299721),
        ] as $carte) {
            expect(collect($carte['mobilier'])->where('mobilier_id', $autel->id))->toBeEmpty()
                ->and(collect($carte['mobilier'])->filter(fn ($m) => ! empty($m['objectif'])))->toBeEmpty();
        }
    }
});

// ─── Lecteur : objectif, libellé, verdict ────────────────────────────────

it('l\'élément désigné devient l\'objectif de la quête, avec son libellé, non accompli', function () {
    $ctx = queteElementObjectif();
    $quete = $ctx['quete'];

    expect($quete->objectif())->toBe('detruire_element')
        ->and($quete->objectifLibelle())->toBe('Détruire : Haut Autel.')
        ->and($quete->objectifAccompli())->toBeFalse()
        ->and($quete->donjonVideOuvreLaSortie())->toBeFalse();

    $etat = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    expect($etat['quete']['objectif'])->toBe('detruire_element')
        ->and($etat['quete']['objectif_libelle'])->toBe('Détruire : Haut Autel.')
        ->and($etat['quete']['objectif_accompli'])->toBeFalse();
});

it('REPLI : une carte sans élément désigné (campagne en cours) garde l\'objectif du gabarit', function () {
    $quete = queteElementObjectif(designe: false)['quete'];

    expect($quete->objectif())->toBe('vaincre_boss_final')
        ->and($quete->donjonVideOuvreLaSortie())->toBeTrue();
});

it('un donjon vidé n\'ouvre PAS la sortie tant que l\'élément tient (menu) — la retraite reste ouverte', function () {
    $ctx = queteElementObjectif();

    $methode = new ReflectionMethod(MenuMoteur::class, 'generer');
    $ids = collect($methode->invoke(app(MenuMoteur::class), $ctx['groupe']->fresh(), $ctx['heros']->fresh())['options'])
        ->pluck('id')->all();

    expect($ids)->toContain('attaquer_mobilier_0')
        ->and($ids)->not->toContain('quitter_donjon')
        ->and($ids)->toContain('battre_en_retraite');

    // Même décision côté résolveur : le menu n'est jamais le seul rempart.
    expect(fn () => app(ResolveurTour::class)->resoudre($ctx['groupe']->fresh(), $ctx['heros']->fresh(), [
        'id' => 'quitter_donjon', 'libelle' => 'Quitter', 'type' => 'sortie', 'creneau' => 'tour',
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ─── En jeu : la chute de l'élément gagne la quête ───────────────────────

it('un coup qui ne détruit pas l\'élément ne termine rien', function () {
    $ctx = queteElementObjectif();

    // 3 crânes, 4 dés de défense tous blancs (4) : tout est paré.
    $r = frapperElement($ctx, [1, 1, 1, 4, 4, 4, 4]);

    expect($r['type'])->toBe('attaque_mobilier')
        ->and($r['detruit'])->toBeFalse()
        ->and($r)->not->toHaveKey('objectif_detruit');
    expect($ctx['groupe']->fresh()->phase)->toBe('quete')
        ->and($ctx['quete']->fresh()->etat)->toBe('en_cours');
});

it('détruire l\'élément GAGNE la quête sur le coup : texte de fin, journal, hub, clôture de campagne', function () {
    $ctx = queteElementObjectif(herosAttrs: ['des_attaque' => 6]);

    // 6 crânes (1), 4 dés de défense noirs (6) = aucun bouclier blanc : 6 PV d'un coup.
    $r = frapperElement($ctx, [1, 1, 1, 1, 1, 1, 6, 6, 6, 6]);

    expect($r['detruit'])->toBeTrue()
        ->and($r['objectif_detruit']['nom'])->toBe('Haut Autel')
        ->and($r['objectif_detruit']['texte'])->toContain('Le Haut Autel se fend en deux')
        ->and($r['quete']['etat'])->toBe('terminee');

    $groupe = $ctx['groupe']->fresh();
    expect($groupe->phase)->toBe('hub')
        ->and($groupe->quete_courante_id)->toBeNull()
        ->and($ctx['quete']->fresh()->etat)->toBe('terminee')
        ->and($ctx['quete']->fresh()->objectifAccompli())->toBeTrue();

    $actions = \App\Models\Evenement::where('groupe_id', $groupe->id)->where('type', 'systeme')
        ->get()->pluck('payload.action')->all();
    expect($actions)->toContain('objectif_detruit')->toContain('quete_terminee');

    $narrations = \App\Models\Evenement::where('groupe_id', $groupe->id)->where('type', 'narration')
        ->get()->pluck('payload.texte')->implode(' ');
    expect($narrations)->toContain('Les débris du Haut Autel jonchent le sol');

    // Jalon boss_final : la fenêtre de clôture de campagne s'ouvre, comme à toute victoire finale.
    expect(\App\Models\Evenement::where('groupe_id', $groupe->id)->get()->pluck('payload.action')->all())
        ->toContain('cloture_ouverte');
});
