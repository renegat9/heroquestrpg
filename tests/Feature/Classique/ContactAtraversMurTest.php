<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Grille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LE CONTACT NE PASSE PAS UN MUR EN ARÊTE (relevé en partie d'agents le 2026-11-17, lot X1 : une momie et un barbare se
 * sont frappés de part et d'autre d'un mur). Carte de `DocumentExemple`, INVENTÉE : `entree` (x 2–6) et `galerie`
 * (x 7–10) se touchent ; leur bord commun est un MUR, sauf la porte (6, 3) côté est.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

it('coupe le contact par un mur ou une porte close, le rend par une porte ouverte, et laisse le contact géométrique sans carte', function () {
    $carte = new Carte(['grille' => [
        'murs' => [['x' => 6, 'y' => 4, 'cote' => 'e']],
        'portes' => [['x' => 6, 'y' => 3, 'cote' => 'e', 'etat' => 'fermee', 'arete' => true]],
    ]]);

    expect(Grille::sontAdjacentes(6, 4, 7, 4))->toBeTrue()                       // sans carte : la géométrie seule
        ->and(Grille::sontAdjacentes(6, 4, 7, 4, carte: $carte))->toBeFalse()    // le mur
        ->and(Grille::sontAdjacentes(6, 3, 7, 3, carte: $carte))->toBeFalse()    // la porte close
        ->and(Grille::sontAdjacentes(6, 5, 6, 4, carte: $carte))->toBeTrue()     // le long du mur : rien ne coupe
        // Diagonale : par (7, 4) le mur coupe, par (6, 5)… puis (6,5)-(7,5) libre : un coin libre suffit.
        ->and(Grille::sontAdjacentes(6, 4, 7, 5, true, carte: $carte))->toBeTrue()
        // Diagonale de (6,3) à (7,4) : par (7,3) la porte close coupe, par (6,4) le mur coupe → aucun coin libre.
        ->and(Grille::sontAdjacentes(6, 3, 7, 4, true, carte: $carte))->toBeFalse();

    $carte->grille = [...$carte->grille, 'portes' => [['x' => 6, 'y' => 3, 'cote' => 'e', 'etat' => 'ouverte', 'arete' => true]]];
    expect(Grille::sontAdjacentes(6, 3, 7, 3, carte: $carte))->toBeTrue();
});

it('EN JEU : de part et d\'autre du mur, ni le héros ni le gobelin ne s\'attaquent', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['monstres'][0] = ['slug' => 'gobelin', 'x' => 7, 'y' => 4];
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    $quete->etatsPersonnages()->update(['position_x' => 6, 'position_y' => 4]);
    $gobelin = InstanceMonstre::where('quete_id', $quete->id)->firstOrFail();
    $gobelin->update(['revele' => true]);

    expect($gobelin->fresh()->touche(6, 4))->toBeFalse();

    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $options = collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'));
    expect($options->pluck('type'))->not->toContain('attaque');

    // Le tour du MJ : le gobelin ne frappe pas à travers le mur.
    $pv = (int) $heros->fresh()->pv_body;
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'attendre'])->assertAccepted();
    $frappes = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()
        ->filter(fn ($e) => str_contains(json_encode($e->payload), '"defenseur"') && str_contains(json_encode($e->payload), 'Albrecht'));
    expect($frappes)->toBeEmpty()
        ->and((int) $heros->fresh()->pv_body)->toBe($pv);
});

/*
 * GARDE-FOU, DANS LES DEUX SENS : tout appel de `Grille::sontAdjacentes()` dans `app/` passe `carte:` — sans elle, un mur
 * en arête ne coupe pas le contact (le défaut de la partie du 2026-11-17). Les seules exceptions sont NOMMÉES, avec leur
 * raison ; une exception qui n'existe plus fait échouer le test (la liste ne peut pas couvrir un appel disparu).
 */
const CONTACT_SANS_CARTE = [
    // Géométrie d'un coup DÉJÀ porté : venait-il d'une case diagonale ? Ce n'est pas un test de contact.
    'ResolveurTour.php::enDiagonale' => 2,
];

it('chaque contact dans app/ passe la carte, sauf les exceptions nommées', function () {
    $sansCarte = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $fichier) {
        if ($fichier->getExtension() !== 'php' || $fichier->getFilename() === 'Grille.php') {
            continue;
        }
        $source = file_get_contents($fichier->getPathname());
        preg_match_all('/sontAdjacentes\(/', $source, $appels, PREG_OFFSET_CAPTURE);
        foreach ($appels[0] as [, $debut]) {
            $ligne = substr($source, strrpos(substr($source, 0, $debut), "\n") + 1, 200);
            if (str_starts_with(ltrim($ligne), '*') || str_starts_with(ltrim($ligne), '//') || str_contains($ligne, 'function sontAdjacentes')) {
                continue;
            }
            // La parenthèse fermante de l'appel.
            $profondeur = 1;
            for ($i = $debut + strlen('sontAdjacentes('); $profondeur > 0; $i++) {
                $profondeur += ($source[$i] === '(') - ($source[$i] === ')');
            }
            if (str_contains(substr($source, $debut, $i - $debut), 'carte:')) {
                continue;
            }
            preg_match_all('/function\s+(\w+)\s*\(/', substr($source, 0, $debut), $fonctions);
            $cle = $fichier->getFilename().'::'.(end($fonctions[1]) ?: '?');
            $sansCarte[$cle] = ($sansCarte[$cle] ?? 0) + 1;
        }
    }
    ksort($sansCarte);
    $attendu = CONTACT_SANS_CARTE;
    ksort($attendu);

    expect($sansCarte)->toBe($attendu);
});
