<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Evenement;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
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
 * LE FIL DE COMBAT — quatre défauts relevés en partie de vérification
 * (docs/verdict-test-jungle-2026-10-10.md, « Partie de vérification ciblée »).
 * Règle dure : « un effet automatique que rien n'annonce est injouable ».
 *
 *   1. une esquive de piège PENDANT un déplacement n'avait aucune ligne ;
 *   2. la POSE d'une condition par un coup de monstre (venin, étreinte) non plus ;
 *   3. un TIR de monstre se lisait comme un coup de mêlée ;
 *   4. le fil nommait le monstre par son nom de CATALOGUE, l'écran par son habillage.
 *
 * Le piège de ces tests : le fil se vérifie sur le RÉSULTAT ENTIER d'une action
 * (`depuisResultat($resultat)`), pas sur un sous-payload isolé — l'ancien test
 * d'esquive passait l'entrée seule et ne voyait jamais que le déplacement la
 * jetait.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

/** Le fil d'un résultat, d'un seul tenant. */
function filCible(array $resultat, string $acteur = 'Albrecht'): string
{
    return collect(app(JournalCombat::class)->depuisResultat($resultat, $acteur))->pluck('texte')->implode(' ¦ ');
}

// =====================================================================
// 1. Les pièges croisés en chemin
// =====================================================================

/** Les lianes esquivées, telles que `MoteurPieges::declencherSurCase()` les publie. */
function esquiveLianes(string $nom = 'Albrecht'): array
{
    return [
        'type' => 'piege_esquive', 'contexte' => 'deplacement',
        'piege' => ['nom' => 'Piège de lianes', 'x' => 2, 'y' => 0],
        'personnage' => ['id' => 1, 'nom' => $nom], 'faces' => ['bouclier_blanc'], 'touches' => 0,
    ];
}

it('dit l\'esquive d\'un piège croisé PENDANT un déplacement — le résultat entier, pas l\'entrée seule', function () {
    $deplacement = [
        'type' => 'deplacement', 'vers' => ['x' => 4, 'y' => 0],
        'pieges_declenches' => [], 'pieges_esquives' => [esquiveLianes()],
    ];

    expect(filCible($deplacement))->toContain('Piège de lianes jaillit', 'Albrecht esquive les lianes et poursuit sa route');
});

it('dit les esquives AVANT le piège qui clôt la course, et les deux', function () {
    $fosse = [
        'type' => 'piege_declenche', 'piege' => ['nom' => 'Fosse'], 'personnage' => ['id' => 1, 'nom' => 'Albrecht'],
        'degats' => 1, 'immobilise' => true,
    ];

    $texte = filCible(['type' => 'deplacement', 'pieges_esquives' => [esquiveLianes()], 'pieges_declenches' => [$fosse]]);

    expect($texte)->toContain('esquive les lianes', 'Fosse se déclenche sur Albrecht')
        ->and(mb_strpos($texte, 'esquive les lianes'))->toBeLessThan(mb_strpos($texte, 'Fosse se déclenche'));
});

it('REJEU — l\'esquive journalisée à part ET portée par son déplacement n\'est dite qu\'une fois', function () {
    $esquive = esquiveLianes('Grom');
    $deplacement = ['type' => 'deplacement', 'vers' => ['x' => 4, 'y' => 0], 'pieges_declenches' => [], 'pieges_esquives' => [$esquive]];

    $textes = array_column((new JournalCombat)->depuisEvenements([[$esquive, 'Grom'], [$deplacement, 'Grom']]), 'texte');

    expect(array_filter($textes, fn ($t) => str_contains($t, 'esquive les lianes')))->toHaveCount(1);
});

it('dit un piège croisé PAR SON TYPE : une téléportation n\'est pas « une fosse qui ne fait pas mal »', function () {
    $teleporte = [
        'type' => 'piege_teleporte', 'contexte' => 'deplacement',
        'piege' => ['nom' => 'Piège de téléportation', 'x' => 1, 'y' => 1],
        'personnage' => ['id' => 1, 'nom' => 'Albrecht'], 'destination' => ['x' => 9, 'y' => 9], 'degats' => 0,
    ];

    $texte = filCible(['type' => 'deplacement', 'pieges_declenches' => [$teleporte]]);

    expect($texte)->toContain('téléporte Albrecht')
        ->and($texte)->not->toContain('égratignure');
});

it('dit une fosse ignorée croisée en chemin, et une entrée sans type garde la phrase du piège de sol', function () {
    $ignore = ['type' => 'piege_ignore', 'piege' => 'Fosse', 'personnage' => 'Albrecht'];
    $ancien = ['piege' => ['nom' => 'Lames'], 'personnage' => ['nom' => 'Albrecht'], 'degats' => 1];

    expect(filCible(['type' => 'deplacement', 'pieges_declenches' => [$ignore]]))->toContain('traverse Fosse sans y tomber')
        ->and(filCible(['type' => 'deplacement', 'pieges_declenches' => [$ancien]]))->toContain('Lames se déclenche sur Albrecht');
});

it('dit les pièges croisés par un héros POSSÉDÉ — la marche forcée porte son piège', function () {
    $fosse = [
        'type' => 'piege_declenche', 'piege' => ['nom' => 'Fosse'], 'personnage' => ['id' => 1, 'nom' => 'Albrecht'],
        'degats' => 1, 'immobilise' => true,
    ];

    $texte = filCible([
        'type' => 'possession_deplacement', 'personnage' => 'Albrecht', 'de' => ['x' => 1, 'y' => 1],
        'vers' => ['x' => 2, 'y' => 1], 'vers_monstre' => 'Haut mage', 'pieges_declenches' => [$fosse],
    ]);

    expect($texte)->toContain('Albrecht est possédé', 'Fosse se déclenche sur Albrecht');
});

// =====================================================================
// 2. La POSE d'une condition par un coup de monstre
// =====================================================================

it('dit le venin posé : « Albrecht est Envenimé — … » dans le résultat de la vraie route', function () {
    // Un Gobelin rendu venimeux : les vraies créatures venimeuses portent aussi `spawn`/`agile`/
    // `tir_au_choix`, qui détourneraient leur tour — il s'agit ici du venin, pas de ce qu'elles font d'autre.
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['instance']->monstre->update(['capacites' => ['venimeux']]);
    $heros = $ctx['heros'];

    // Tout à 1 : trois crânes, aucun bouclier — le coup porte —, puis un 1 au dé du venin (< 5).
    desFiges(array_fill(0, 30, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 30, 1));

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))->firstWhere('type', 'attaque_monstre');

    // Ce test est celui du FIL : la pose se dit. Que la condition SURVIVE jusqu'au tour du héros est
    // épinglé à part (`VeninDureeTest`) — elle tombait dans cette même résolution avant le 2026-10-15.
    expect($attaque['venin'])->toBeTrue()
        ->and($attaque['conditions_posees'])->toHaveCount(1)
        ->and($attaque['conditions_posees'][0]['nom'])->toBe('Envenimé');

    $fil = filCible($reponse->json('resultat'));

    expect($fil)->toContain('Albrecht est Envenimé — paralysé par le venin')
        ->and($fil)->toContain('jusqu\'à la fin du tour suivant');
});

it('dit l\'étreinte posée par un coup du Yéti : « est Agrippé »', function () {
    $ctx = demarrerQueteAvecMonstre('Yéti');
    $heros = $ctx['heros'];

    desFiges(array_fill(0, 30, 1));
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);
    desFiges(array_fill(0, 30, 1));

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect($heros->fresh()->conditions()->where('nom', 'Agrippé')->exists())->toBeTrue()
        ->and(filCible($reponse->json('resultat')))->toContain('Albrecht est Agrippé — étreint par une créature');
});

it('ne dit AUCUNE condition quand le coup n\'en a posé aucune (venin résisté, coup manqué)', function () {
    $texte = filCible(['type' => 'attaque_monstre', 'monstre' => 'Serpent géant', 'venin' => false, 'conditions_posees' => [],
        'degats' => 2, 'touches' => 2, 'boucliers' => 0, 'cible' => ['nom' => 'Albrecht']]);

    expect($texte)->toContain('Serpent géant touche Albrecht (−2 PV)')
        ->and($texte)->not->toContain('est Envenimé');
});

it('dit chaque condition posée par un coup, sans en connaître aucune par son nom', function () {
    $texte = filCible(['type' => 'attaque_monstre', 'monstre' => 'Spectre', 'degats' => 1, 'touches' => 1, 'boucliers' => 0,
        'cible' => ['nom' => 'Albrecht'],
        'conditions_posees' => [['nom' => 'Glacé', 'description' => 'Ne peut plus courir.'], ['nom' => 'Marqué', 'description' => null]]]);

    expect($texte)->toContain('Albrecht est Glacé — ne peut plus courir')
        ->and($texte)->toContain('Albrecht est Marqué')
        ->and($texte)->not->toContain('Marqué —');
});

it('la FRAPPE DE ZONE publie et dit le venin posé sur chaque cible touchée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['instance']->monstre->update(['capacites' => ['frappe_de_zone', 'venimeux']]);
    $instance = $ctx['instance']->fresh()->load('monstre');
    $cibles = $ctx['quete']->etatsPersonnages()->with('personnage')->get();

    desFiges(array_fill(0, 30, 1)); // crânes partout, puis le dé du venin à 1

    $payload = app(MoteurDread::class)->frappeDeZone(
        $ctx['groupe'], $instance, $cibles, ['type' => 'monstre', 'id' => $instance->id, 'nom' => $instance->nomAffiche()],
    );

    expect($payload['resultats'][0]['venin'])->toBeTrue()
        ->and($payload['resultats'][0]['conditions_posees'][0]['nom'])->toBe('Envenimé')
        ->and(filCible($payload))->toContain('Albrecht est Envenimé');
});

// =====================================================================
// 3. Le TIR d'un monstre
// =====================================================================

it('dit « tire sur » quand le monstre attaque à DISTANCE, « touche » au contact', function () {
    $attaque = fn (string $portee, int $degats, int $touches = 1, int $boucliers = 0) => [
        'type' => 'attaque_monstre', 'monstre' => 'Gretzl', 'portee' => $portee,
        'degats' => $degats, 'touches' => $touches, 'boucliers' => $boucliers, 'cible' => ['nom' => 'Brokk'],
    ];

    expect(filCible($attaque('distance', 2, 2)))->toContain('Gretzl tire sur Brokk (−2 PV)')
        ->and(filCible($attaque('distance', 0, 0)))->toContain('Gretzl tire sur Brokk — et le manque')
        ->and(filCible($attaque('distance', 0, 2, 2)))->toContain('Brokk pare le tir de Gretzl')
        ->and(filCible($attaque('corps_a_corps', 2, 2)))->toContain('Gretzl touche Brokk (−2 PV)')
        ->and(filCible($attaque('corps_a_corps', 2, 2)))->not->toContain('tire');
});

it('une charge n\'est jamais un tir, même si le payload porte une portée', function () {
    $texte = filCible(['type' => 'charge', 'monstre' => 'Minotaure', 'portee' => 'distance', 'degats' => 2, 'touches' => 2,
        'cible' => ['nom' => 'Brokk']]);

    expect($texte)->toContain('Minotaure charge Brokk')->and($texte)->not->toContain('tire sur');
});

// =====================================================================
// 4. Le NOM du monstre : celui de l'écran, jamais celui du catalogue
// =====================================================================

it('le changement de forme d\'un monstre HABILLÉ dit son nom d\'habillage, puis la nouvelle forme du catalogue', function () {
    $ctx = demarrerQueteAvecMonstre('Gretzl la Porte-Fléau');
    $instance = $ctx['instance'];
    $instance->update(['habillage' => ['nom' => 'Le Cœur-Liane Éveillé']]);

    $degats = app(MoteurDegats::class);
    // Demon Wings joue d'abord (défense à usage unique), puis la forme change.
    $degats->infligerAMonstre($instance->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    $issue = $degats->infligerAMonstre($instance->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($issue['changement_phase']['avant'])->toBe('Le Cœur-Liane Éveillé')
        ->and($issue['changement_phase']['apres'])->toBe('Demonspider');

    $fil = filCible(['type' => 'attaque', 'degats' => 0, 'touches' => 0, 'cible' => ['nom' => 'Le Cœur-Liane Éveillé'],
        'changement_phase' => $issue['changement_phase']]);

    expect($fil)->toContain('Le Cœur-Liane Éveillé vacille — et se relève sous une autre forme : Demonspider')
        ->and($fil)->not->toContain('Gretzl la Porte-Fléau');
});

it('sans habillage, le changement de forme dit le nom du catalogue — c\'est aussi celui de l\'écran', function () {
    $ctx = demarrerQueteAvecMonstre('Gretzl la Porte-Fléau');
    $instance = $ctx['instance'];

    $degats = app(MoteurDegats::class);
    $degats->infligerAMonstre($instance->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    $issue = $degats->infligerAMonstre($instance->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($issue['changement_phase'])->toMatchArray(['avant' => 'Gretzl la Porte-Fléau', 'apres' => 'Demonspider'])
        ->and($instance->fresh()->nomAffiche())->toBe('Demonspider');
});

it('un monstre d\'ÉLITE garde son ★ dans l\'annonce, comme à l\'écran', function () {
    $ctx = demarrerQueteAvecMonstre('Spawn of the Pit');
    $ctx['instance']->update(['elite' => true]);

    $issue = app(MoteurDegats::class)->infligerAMonstre($ctx['instance']->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($issue['changement_phase']['avant'])->toBe('Spawn of the Pit ★')
        ->and($issue['changement_phase']['apres'])->toBe('Spawn of the Pit déchaîné ★');
});

it('l\'annonce AUTONOME du changement de forme (tampon) porte le même nom que l\'écran', function () {
    $ctx = demarrerQueteAvecMonstre('Gretzl la Porte-Fléau');
    $ctx['instance']->update(['habillage' => ['nom' => 'Le Cœur-Liane Éveillé']]);

    $degats = app(MoteurDegats::class);
    $degats->infligerAMonstre($ctx['instance']->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    $degats->infligerAMonstre($ctx['instance']->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    $evenement = Evenement::where('groupe_id', $ctx['groupe']->id)->get()
        ->first(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'changement_phase');

    expect($evenement->payload['nom'])->toBe('Le Cœur-Liane Éveillé')
        ->and(filCible($evenement->payload))->toContain('Le Cœur-Liane Éveillé vacille');
});

it('AUCUN payload ne nomme un monstre par son nom de catalogue : une seule source, nomAffiche()', function () {
    // Le motif de la faute : `'nom' => $instance->monstre->nom_base` dans un payload lu par le
    // fil. Les seuls usages légitimes de `nom_base` sont des COMPARAISONS, des RECHERCHES de
    // catalogue et les clés qui publient CE nom comme donnée de catalogue (`nom_base`), pas
    // comme étiquette. Les exceptions sont nommées, avec leur raison.
    $exceptions = [
        'EffetsGlobauxQuete.php' => 'le NOM DE LA SOURCE d\'un effet de quête (« Gruulob, All Goblins… ») : un concept de catalogue, pas une figure',
    ];

    $fautes = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Partie')));

    foreach ($it as $fichier) {
        if (! $fichier->isFile() || $fichier->getExtension() !== 'php' || array_key_exists($fichier->getFilename(), $exceptions)) {
            continue;
        }

        foreach (file($fichier->getPathname()) as $n => $ligne) {
            if (preg_match("/'(nom|monstre|avant|apres|cible|allie|source|titre|lanceur)'\s*=>[^,\n]*nom_base/", $ligne)) {
                $fautes[] = $fichier->getFilename().':'.($n + 1).' '.trim($ligne);
            }
        }
    }

    expect($fautes)->toBe([], 'un payload nomme un monstre par son nom de catalogue : utilise InstanceMonstre::nomAffiche()');
});
