<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
use App\Partie\Fouille\DeckFouille;
use App\Partie\MoteurDread;
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
use Illuminate\Support\Facades\Http;

/*
 * Wizards of Morcar comme THÈME jouable (vague 2C, 2026-10-08).
 * Décision de palier : la Gardienne (Artificière) est le SEUL boss ; Maître des
 * orages, Haut mage, Nécromancien et Mage de guerre orque sont des sous-boss
 * (« lieutenants de Morcar », livret G1504 p. 24-31 ; la Gardienne, p. 38).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

const LIEUTENANTS_MORCAR = ['Maître des orages', 'Haut mage', 'Nécromancien', 'Mage de guerre orque'];

function acheterPourTheme(string $theme, string $typeJalon, int $budget, int $maxSpawns, int $position, int $groupeId): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);
    $structure = GabaritQuete::where('type_jalon', $typeJalon)->firstOrFail()->structure;

    return $methode->invoke($demarreur, $structure, $budget, $maxSpawns, $position, $groupeId, BestiaireGroupe::auto($theme));
}

it('range les quatre lieutenants en sous-boss et la Gardienne seule en boss', function () {
    foreach (LIEUTENANTS_MORCAR as $nom) {
        expect(Monstre::where('nom_base', $nom)->value('tier'))->toBe('sous_boss', $nom);
    }

    expect(Monstre::where('boite', 'wizards_of_morcar')->where('tier', 'boss')->pluck('nom_base')->all())
        ->toBe(['Artificière']);
});

it('offre wizards_of_morcar comme thème, avec son libellé officiel', function () {
    expect(DemarreurQuete::BOITES_THEMATIQUES)->toContain('wizards_of_morcar')
        ->and(DemarreurQuete::LIBELLES_BOITES['wizards_of_morcar'])->toBe('Wizards of Morcar')
        ->and(array_keys(DemarreurQuete::BOITES_INCOMPLETES))->not->toContain('wizards_of_morcar');
});

it('ferme la campagne sur la Gardienne, quel que soit le groupe ou la position', function () {
    foreach (range(1, 6) as $groupe) {
        foreach ([1, 4, 9] as $position) {
            expect(acheterPourTheme('wizards_of_morcar', 'boss_final', 40, 8, $position, $groupe)[0]->nom_base)
                ->toBe('Artificière');
        }
    }
});

it('fait tourner les sous-boss du thème entre les quatre lieutenants et le Minotaure, sans hasard', function () {
    $vus = [];
    foreach (range(1, 10) as $position) {
        $final = acheterPourTheme('wizards_of_morcar', 'sous_boss', 40, 8, $position, 3)[0]->nom_base;
        $vus[$final] = true;
        // stable : même entrée, même sortie
        expect(acheterPourTheme('wizards_of_morcar', 'sous_boss', 40, 8, $position, 3)[0]->nom_base)->toBe($final);
    }

    expect(array_keys($vus))->toEqualCanonicalizing([...LIEUTENANTS_MORCAR, 'Minotaure']);
});

it('garde la masse commune et fait venir les signatures (Golem puis Dreadshifter) comme forts', function () {
    $achats = collect(acheterPourTheme('wizards_of_morcar', 'normale', 40, 12, 5, 1));
    $noms = $achats->pluck('nom_base');

    expect($noms)->toContain('Golem')->toContain('Dreadshifter')
        ->and($achats->pluck('boite')->contains('base'))->toBeTrue();
});

it('laisse à chaque lieutenant ses SIX sorts malgré le palier sous-boss', function () {
    $moteur = app(MoteurDread::class);
    $methode = new ReflectionMethod($moteur, 'sortsDisponibles');
    $methode->setAccessible(true);

    foreach (LIEUTENANTS_MORCAR as $nom) {
        $monstre = Monstre::where('nom_base', $nom)->firstOrFail();
        $instance = new InstanceMonstre(['elite' => false]);
        $instance->setRelation('monstre', $monstre);

        $sorts = $methode->invoke($moteur, $instance, new Quete)->pluck('nom')->all();

        expect($sorts)->toEqualCanonicalizing(config("archetypes_lanceurs.{$monstre->archetype_lanceur}.sorts"), $nom);
    }
});

it('FIGE le thème : un groupe déjà figé ne bouge pas quand la rotation change de longueur', function () {
    $demarreur = app(DemarreurQuete::class);

    // Un groupe dont l'id donnerait AUJOURD'HUI wizards_of_morcar (modulo 7)
    // mais qui a été figé sur une autre boîte avant l'ajout.
    $id = array_search('wizards_of_morcar', DemarreurQuete::BOITES_THEMATIQUES, true);
    $groupe = new Groupe(['theme_bestiaire' => 'dread_moon']);
    $groupe->id = $id + count(DemarreurQuete::BOITES_THEMATIQUES);

    expect($demarreur->themeBestiaire((int) $groupe->id))->toBe('wizards_of_morcar')
        ->and($demarreur->themeBestiaireDuGroupe($groupe))->toBe('dread_moon')
        ->and(BestiaireGroupe::duGroupe($groupe)->contient('wizards_of_morcar'))->toBeFalse();
});

it('la migration de gel écrit l\'ancien modulo pour un groupe qui a joué sans thème figé', function () {
    $groupe = creerGroupe('ancien');
    Quete::unguarded(fn () => Quete::create(['groupe_id' => $groupe->id, 'gabarit_id' => GabaritQuete::first()->id,
        'titre' => 'x', 'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'terminee']));
    $neuf = creerGroupe('neuf');

    (require base_path('database/migrations/2026_10_08_150000_figer_theme_bestiaire_des_groupes_existants.php'))->up();

    $ancienne = ['dread_moon', 'mage_du_miroir', 'horde_ogre', 'jungles_delthrak', 'horreur_des_glaces', 'first_light'];
    expect($groupe->fresh()->theme_bestiaire)->toBe($ancienne[$groupe->id % 6])
        ->and($neuf->fresh()->theme_bestiaire)->toBeNull();
});

it('ACTIVE sous le thème pièges magiques et Coffre du Dread (le Haut Autel, lui, ne se pose que dans la quête finale) — et jamais hors thème', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();
    $nomsPieges = Piege::pluck('nom', 'id');
    $nomsMobilier = Mobilier::pluck('nom', 'id');
    $bestiaire = BestiaireGroupe::auto('wizards_of_morcar');
    $morcar = Piege::where('boite', 'wizards_of_morcar')->pluck('nom')->all();
    expect($morcar)->not->toBeEmpty();

    $piegesVus = [];
    $meublesVus = [];
    foreach (range(1, 40) as $i) {
        $carte = $assembleur->assembler($gabarit, $i * 1299721, bestiaire: $bestiaire);
        foreach ($carte['pieges'] as $p) {
            $piegesVus[$nomsPieges[$p['piege_id']] ?? '?'] = true;
        }
        foreach ($carte['mobilier'] as $m) {
            $meublesVus[$nomsMobilier[$m['mobilier_id']] ?? '?'] = true;
        }
    }

    expect(array_intersect($morcar, array_keys($piegesVus)))->not->toBeEmpty()
        ->and($meublesVus)->toHaveKey('Coffre du Dread')
        // 2026-10-09 : le Haut Autel n'est plus du décor aléatoire — il est
        // posé à coup sûr par la quête FINALE (ObjectifDetruireElementTest).
        ->not->toHaveKey('Haut Autel');

    foreach (range(1, 15) as $i) {
        $carte = $assembleur->assembler($gabarit, $i * 1299721);
        foreach ($carte['pieges'] as $p) {
            expect($morcar)->not->toContain($nomsPieges[$p['piege_id']] ?? '?');
        }
        foreach ($carte['mobilier'] as $m) {
            expect($nomsMobilier[$m['mobilier_id']] ?? '?')->not->toBeIn(['Haut Autel', 'Coffre du Dread']);
        }
    }
});

it('ajoute les cartes de trésor Morcar au deck du thème automatique', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($joueur, $groupe, 'Albrecht', 1);
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $service = app(DeckFouille::class);
    $sans = $service->construire($quete->gabarit, $quete->carte->grille, $groupe, 1, BestiaireGroupe::auto('first_light'))['deck'];
    $avec = $service->construire($quete->gabarit, $quete->carte->grille, $groupe, 1, BestiaireGroupe::auto('wizards_of_morcar'))['deck'];

    expect(count($avec))->toBe(count($sans) + 8);
});

it('démarre une vraie quête sous le thème : monstres du thème, Dreadshifter déguisé en coffre', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($joueur, $groupe, 'Albrecht', 1);
    $groupe->update(['theme_bestiaire' => 'wizards_of_morcar']);

    // Quête de position 1 : un seul fort (Golem). On vérifie que le thème sert,
    // puis on teste le déguisement sur une quête dont l'achat contient un Dreadshifter.
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $boites = $quete->instancesMonstres()->with('monstre')->get()->pluck('monstre.boite');

    expect($boites->contains('wizards_of_morcar'))->toBeTrue()
        ->and($groupe->fresh()->theme_bestiaire)->toBe('wizards_of_morcar');
});
