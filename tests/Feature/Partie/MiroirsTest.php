<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\GabaritQuete;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\MoteurPortes;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * MIROIRS (The Mage of the Mirror, F7539 PDF 3 : « These standing mirrors are secret portals that lead to great treasure
 * and hidden rooms ») — plan des restes, lot C, René 2026-10-29 : un HABILLAGE du passage secret, sans règle neuve.
 *
 * Décidé à l'assemblage (`AssembleurCarte`, thème `mage_du_miroir`), gardé par la porte (`habillage`), lu par la
 * publication (`EtatGroupe::portes()`) et par le menu (« Ouvrir le miroir »). Les cartes du livret ne dessinent pas le
 * miroir (« MIR », F7539 PDF 17) : un miroir fermé ne prête jamais le symbole de la porte fermée.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, MobilierSeeder::class]);
});

/** Les portes secrètes de dix cartes assemblées sous ce thème, passage secret forcé. */
function portesSecretesSousTheme(?string $theme): array
{
    $gabarit = GabaritQuete::query()->where('type_jalon', 'normale')->firstOrFail();
    $bestiaire = $theme === null ? null : BestiaireGroupe::auto($theme);
    $secretes = [];

    foreach (range(1, 10) as $i) {
        $carte = app(AssembleurCarte::class)->assembler($gabarit, $i * 7919, 100, $bestiaire);

        foreach ($carte['portes'] as $porte) {
            if (($porte['etat'] ?? '') === MoteurPortes::ETAT_SECRETE) {
                $secretes[] = $porte;
            }
        }
    }

    return $secretes;
}

/** Quête démarrée, la carte réduite à UNE porte au contact du premier héros. */
function queteAvecPorteMiroir(array $porte): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = $quete->etatsPersonnages()->where('personnage_id', $hero->id)->firstOrFail();

    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['portes'] = [['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y, 'cote' => 'e', ...$porte]];
    $carte->update(['grille' => $grille]);

    return [$alice, $groupe, $hero, $quete];
}

it('habille en MIROIR tout passage secret assemblé sous le thème Mage du Miroir', function () {
    $secretes = portesSecretesSousTheme('mage_du_miroir');

    expect($secretes)->not->toBeEmpty();

    foreach ($secretes as $porte) {
        expect($porte['habillage'] ?? null)->toBe(MoteurPortes::HABILLAGE_MIROIR);
    }
});

it('n\'habille AUCUNE porte hors de ce thème', function () {
    foreach ([null, 'witch_lord'] as $theme) {
        $secretes = portesSecretesSousTheme($theme);

        expect($secretes)->not->toBeEmpty();

        foreach ($secretes as $porte) {
            expect($porte)->not->toHaveKey('habillage');
        }
    }
});

it('publie l\'habillage d\'un miroir trouvé, sans lui prêter le symbole de la porte fermée', function () {
    queteAvecPorteMiroir(['etat' => MoteurPortes::ETAT_FERMEE, 'revele' => true, 'habillage' => MoteurPortes::HABILLAGE_MIROIR]);

    $porte = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.portes.0');

    expect($porte['habillage'])->toBe('miroir')
        ->and($porte['etat'])->toBe('fermee')
        ->and($porte['symbole_url'])->toBeNull();
});

it('publie habillage = null sur une porte ordinaire, qui garde son symbole de livret', function () {
    queteAvecPorteMiroir(['etat' => MoteurPortes::ETAT_FERMEE]);

    $porte = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.portes.0');

    expect($porte)->toHaveKey('habillage')
        ->and($porte['habillage'])->toBeNull()
        ->and($porte['symbole_url'])->toBe('/images/livrets/portes/fermee.webp');
});

it('ne publie toujours pas un miroir NON trouvé : c\'est un mur, comme tout passage secret', function () {
    queteAvecPorteMiroir(['etat' => MoteurPortes::ETAT_SECRETE, 'revele' => false, 'habillage' => MoteurPortes::HABILLAGE_MIROIR]);

    expect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.portes'))->toBe([]);
});

it('propose « Ouvrir le miroir » — la même option qu\'une porte — et l\'ouvre', function () {
    [$alice, $groupe, $hero] = queteAvecPorteMiroir(['etat' => MoteurPortes::ETAT_FERMEE, 'revele' => true, 'habillage' => MoteurPortes::HABILLAGE_MIROIR]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $option = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])
        ->first(fn ($o) => ($o['type'] ?? null) === 'ouvrir_porte');

    expect($option)->not->toBeNull()
        ->and($option['libelle'])->toStartWith('Ouvrir le miroir');

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $option['id']])->assertStatus(202);

    $porte = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.portes.0');

    expect($porte['etat'])->toBe('ouverte')
        ->and($porte['habillage'])->toBe('miroir');
});
