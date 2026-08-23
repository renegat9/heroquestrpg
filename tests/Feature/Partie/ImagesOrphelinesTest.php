<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\ClotureCampagne;
use App\Partie\Images\BibliothequeImages;
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

/**
 * Une illustration `dyn/{sousType}/{id}` est indexée sur une clé
 * AUTO-INCRÉMENTÉE, dont InnoDB recalcule le compteur à `max(id)+1` au
 * redémarrage. Une campagne purgée libérait donc ses ids EN LAISSANT SES
 * FICHIERS : le sujet suivant héritait de l'image du mort, sans la moindre
 * erreur — juste l'illustration de quelqu'un d'autre à l'écran. Mesuré le
 * 2026-08-22 (quetes à 43 pour des scènes jusqu'à 67, hub à 30 pour 89).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);

    // ⚠ ARBORESCENCE JETABLE, jamais le vrai `public/`. La commande balaie le
    // disque et interroge la BASE pour savoir ce qui est orphelin : sous Pest,
    // la base est une sqlite en mémoire quasi vide, donc TOUT fichier réel y
    // paraît orphelin. Branché sur le vrai dossier, un simple `pest` effaçait
    // les 148 illustrations de la machine (arrivé le 2026-08-22 — 118 Mo, non
    // récupérables : `public/images` est hors dépôt). La commande refuse
    // désormais l'environnement `testing` (voir PurgerImagesOrphelines), et ce
    // bac à sable est la seconde barrière.
    $GLOBALS['public_test'] = sys_get_temp_dir().'/hq-images-'.uniqid();
    mkdir($GLOBALS['public_test'], 0775, true);
    app()->usePublicPath($GLOBALS['public_test']);

    $GLOBALS['images_posees'] = [];
});

/** Pose un faux fichier sous `public/images/{relatif}.png` (bac à sable). */
function poserImage2(string $relatif): string
{
    $chemin = public_path("images/{$relatif}.png");

    if (! is_dir(dirname($chemin))) {
        mkdir(dirname($chemin), 0775, true);
    }

    file_put_contents($chemin, 'PIXELS');

    return $chemin;
}

/**
 * Pose un faux fichier d'illustration et le retient pour le ménage. Fonction
 * plutôt que closure sur `$this` : appelée hors contexte de test, `$this` n'y
 * serait pas lié et l'ajout au tableau se perdrait dans le proxy de Pest.
 */
function poserImage(string $sousType, int $id, string $ext = 'png'): string
{
    $chemin = public_path("images/dyn/{$sousType}/{$id}.{$ext}");

    if (! is_dir(dirname($chemin))) {
        mkdir(dirname($chemin), 0775, true);
    }

    file_put_contents($chemin, 'PIXELS');
    $GLOBALS['images_posees'][] = $chemin;

    return $chemin;
}

afterEach(function () {
    // Le bac à sable entier part : rien de ce qui a été écrit ici n'a de valeur.
    $racine = $GLOBALS['public_test'] ?? null;

    if ($racine !== null && is_dir($racine)) {
        exec('rm -rf '.escapeshellarg($racine));
    }
});

it('efface le PNG ET son jumeau .webp, celui que url() fait gagner', function () {
    $png = poserImage('quete', 999_001, 'png');
    $webp = poserImage('quete', 999_001, 'webp');

    expect(app(BibliothequeImages::class)->supprimerDyn('quete', 999_001))->toBeTrue()
        ->and(is_file($png))->toBeFalse()
        // Sans lui, `url()` continuerait de servir l'image effacée.
        ->and(is_file($webp))->toBeFalse();
});

it('la purge d’une campagne emporte hub, quêtes et monstres — mais PAS les héros', function () {
    ['groupe' => $groupe, 'quete' => $quete, 'instance' => $instance, 'heros' => $heros]
        = demarrerQueteAvecMonstre('Gobelin');

    $hub = poserImage('hub', (int) $groupe->id);
    $scene = poserImage('quete', (int) $quete->id);
    $portraitMonstre = poserImage('monstre', (int) $instance->id);
    $portraitHeros = poserImage('perso', (int) $heros->id);

    app(ClotureCampagne::class)->purger($groupe->fresh());

    expect(is_file($hub))->toBeFalse()
        ->and(is_file($scene))->toBeFalse()
        ->and(is_file($portraitMonstre))->toBeFalse()
        // ⚠ Le héros est DÉTACHÉ, pas supprimé : il repart au roster de son
        // joueur, et son portrait illustre encore quelqu'un de vivant.
        ->and(is_file($portraitHeros))->toBeTrue()
        ->and(Personnage::find($heros->id))->not->toBeNull()
        ->and(Groupe::find($groupe->id))->toBeNull()
        ->and(Quete::find($quete->id))->toBeNull();
});

it('la commande de balayage ne supprime rien sans --supprimer, et n’emporte que les orphelins', function () {
    ['groupe' => $groupe] = demarrerQueteAvecMonstre('Gobelin');

    $vivant = poserImage('hub', (int) $groupe->id);
    $orphelin = poserImage('hub', 999_002);

    app()['env'] = 'local'; // la commande refuse `testing` — voir son garde-fou

    $this->artisan('images:purger-orphelines')->assertSuccessful();

    expect(is_file($orphelin))->toBeTrue('inventaire seul : rien ne doit disparaître');

    $this->artisan('images:purger-orphelines', ['--supprimer' => true])->assertSuccessful();

    expect(is_file($orphelin))->toBeFalse()
        ->and(is_file($vivant))->toBeTrue('un sujet encore en base garde son image');
});

it('emporte une image de catalogue au VIEUX numéro, et garde celle du numéro courant', function () {
    app()['env'] = 'local';

    $objet = App\Models\Objet::query()->orderBy('id')->firstOrFail();
    $slug = App\Partie\Images\BibliothequeImages::slug($objet->nom);

    // ⚠ Le fichier porte `{id}-{slug}` : la migration du paquet Hasbro a
    // RENUMÉROTÉ le catalogue, laissant 22 fichiers que plus rien ne sert
    // (26,4 Mo constatés le 2026-08-22). L'id vit, le fichier est mort.
    $courant = poserImage2("catalogue/objets/{$objet->id}-{$slug}");
    $ancien = poserImage2("catalogue/objets/9997-{$slug}");
    $disparu = poserImage2('catalogue/objets/9998-piece-supprimee-du-catalogue');

    test()->artisan('images:purger-orphelines', ['--supprimer' => true])->assertSuccessful();

    expect(is_file($ancien))->toBeFalse()
        ->and(is_file($disparu))->toBeFalse()
        ->and(is_file($courant))->toBeTrue();
});

it('ne touche à RIEN quand la table est vide — une absence de preuve n’est pas une preuve', function () {
    app()['env'] = 'local';

    App\Models\Piege::query()->delete();
    $orphelinApparent = poserImage2('catalogue/pieges/9999-piege-fantome');

    test()->artisan('images:purger-orphelines', ['--supprimer' => true])->assertSuccessful();

    // Sans ce garde-fou, un catalogue non seedé effacerait TOUTES ses images.
    expect(is_file($orphelinApparent))->toBeTrue();
});
