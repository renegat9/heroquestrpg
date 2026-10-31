<?php

declare(strict_types=1);

use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
 * JETONS DES ALLIÉS (René, 2026-10-30 : « Oui ») — le pendant de SymbolesMonstresTest.
 *
 * Un allié porte le jeton que le livret lui dessine (`config/symboles_allies.php`) : les quatre mercenaires de la légende de
 * The Frozen Horror, Sir Ragnar de Wizards of Morcar, les tuiles du Prospecteur et de la Princesse ; un allié qui EST une
 * figure du bestiaire reprend le jeton du monstre (le Raptor, le squelette). Registre, fichiers et manifeste se vérifient
 * dans les deux sens, et la publication est éprouvée sur l'état réel d'une quête.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
});

function jetonsAllies(): array
{
    return (array) config('symboles_allies.jetons');
}

function dossierLivrets(): string
{
    return public_path('images/livrets');
}

/** @return list<array<string, mixed>> */
function manifesteDesAllies(): array
{
    $manifeste = json_decode(file_get_contents(base_path('image-tools/symboles-livrets/manifeste.json')), true);
    $entrees = array_is_list($manifeste) ? $manifeste : ($manifeste['symboles'] ?? []);

    return array_values(array_filter($entrees, fn (array $e) => ($e['type'] ?? null) === 'allies'));
}

it('déclare chaque allié du catalogue : un jeton OU une raison écrite, jamais les deux, jamais un nom inconnu', function () {
    $this->seed(MercenaireSeeder::class);
    $catalogue = Mercenaire::query()->pluck('nom')->all();
    $jetons = array_keys(jetonsAllies());
    $absents = array_keys((array) config('symboles_allies.absents'));

    expect(array_intersect($jetons, $absents))->toBe([], 'un allié est à la fois jeton et absent');

    $declares = [...$jetons, ...$absents];
    sort($declares);
    sort($catalogue);
    expect($declares)->toBe($catalogue, 'le registre des jetons d\'alliés ne couvre pas exactement le catalogue');

    foreach ((array) config('symboles_allies.absents') as $nom => $raison) {
        expect(trim((string) $raison))->not->toBe('', "« {$nom} » : absence sans raison");
    }
});

it('donne à chaque jeton déclaré un fichier, et à chaque fichier d\'allié un allié — dans les deux sens', function () {
    $utilises = [];

    foreach (jetonsAllies() as $nom => $chemin) {
        expect(str_contains($chemin, '/'))->toBeTrue("« {$nom} » : « {$chemin} » n'est pas un type/clé");
        expect(is_file(dossierLivrets()."/{$chemin}.webp"))->toBeTrue("« {$nom} » : livrets/{$chemin}.webp absent");

        if (str_starts_with($chemin, 'allies/')) {
            $utilises[] = substr($chemin, strlen('allies/'));
        }
    }

    $fichiers = array_map(fn ($f) => $f->getFilenameWithoutExtension(), File::files(dossierLivrets().'/allies'));
    sort($fichiers);
    $utilises = array_values(array_unique($utilises));
    sort($utilises);
    expect($fichiers)->toBe($utilises, 'un jeton d\'allié n\'est porté par aucun allié, ou un allié n\'a pas son fichier');
});

it('extrait par le manifeste exactement les jetons propres aux alliés : un disque, ou une tuile pour les alliés `tuiles`', function () {
    $tuiles = array_map(fn (string $nom) => substr(jetonsAllies()[$nom], strlen('allies/')), (array) config('symboles_allies.tuiles'));
    $declares = [];

    foreach (manifesteDesAllies() as $e) {
        $declares[] = $e['cle'];
        $estTuile = ! empty($e['tuile']);

        expect($estTuile xor array_key_exists('disque', $e))->toBeTrue("« {$e['cle']} » : un disque OU une tuile");
        expect($estTuile)->toBe(in_array($e['cle'], $tuiles, true), "« {$e['cle']} » : marqué tuile au manifeste ≠ `tuiles` de la config");

        [$largeur, $hauteur] = getimagesize(dossierLivrets()."/allies/{$e['cle']}.webp");
        expect($largeur)->toBe($hauteur, "« {$e['cle']} » : un jeton d'allié est carré ({$largeur}×{$hauteur})");
    }

    $fichiers = array_map(fn ($f) => $f->getFilenameWithoutExtension(), File::files(dossierLivrets().'/allies'));
    sort($declares);
    sort($fichiers);
    expect($declares)->toBe($fichiers, 'le manifeste et le dossier des jetons d\'alliés ne désignent pas les mêmes jetons');
});

it('publie le jeton d\'un allié posé, la tuile d\'un captif, et rien pour un allié sans jeton', function () {
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, MercenaireSeeder::class]);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = $quete->etatsPersonnages()->where('personnage_id', $hero->id)->firstOrFail();
    $x = (int) $etat->position_x;
    $y = (int) $etat->position_y;

    // Dans la salle de départ, donc découverte : un captif n'est publié que là.
    foreach ([['Éclaireur', 'actif'], ['Loup', 'actif'], ['Le Prospecteur', 'captif']] as $i => [$nom, $etatAllie]) {
        $merc = Mercenaire::where('nom', $nom)->firstOrFail();
        GroupeMercenaire::create([
            'groupe_id' => $groupe->id, 'mercenaire_id' => $merc->id, 'recruteur_personnage_id' => $hero->id,
            'pv_body' => $merc->pv_body, 'etat' => $etatAllie, 'position_x' => $x, 'position_y' => $y,
        ]);
    }

    $entites = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites'));

    $eclaireur = $entites->first(fn ($e) => $e['type'] === 'allie' && $e['nom'] === 'Éclaireur');
    $loup = $entites->first(fn ($e) => $e['type'] === 'allie' && $e['nom'] === 'Loup');
    $prospecteur = $entites->first(fn ($e) => $e['type'] === 'captif' && $e['nom'] === 'Le Prospecteur');

    expect($eclaireur['symbole_url'])->toBe('/images/livrets/allies/scout.webp')
        ->and($eclaireur['symbole_forme'])->toBe('rond')
        ->and($eclaireur['symbole_rotation'])->toBe(0)
        ->and($loup)->toHaveKey('symbole_url')
        ->and($loup['symbole_url'])->toBeNull()
        ->and($loup['symbole_forme'])->toBeNull()
        ->and($prospecteur['symbole_url'])->toBe('/images/livrets/allies/prospector.webp')
        ->and($prospecteur['symbole_forme'])->toBe('tuile');
});

it('donne au Raptor apprivoisé et au squelette Hearthkin le jeton de la figure que leur texte nomme', function () {
    $biblio = app(App\Partie\Images\BibliothequeImages::class);

    expect($biblio->urlSymboleAllie('Raptor apprivoisé'))->toBe('/images/livrets/monstres/raptor.webp')
        ->and($biblio->urlSymboleAllie('Squelette Hearthkin'))->toBe('/images/livrets/monstres/skeleton.webp')
        ->and($biblio->urlSymboleAllie('Gothar'))->toBeNull();
});
