<?php

declare(strict_types=1);

use App\Engine\MotsClesQuete;
use App\Partie\Classique\CatalogueConstructeur;
use App\Partie\Classique\NotesDeQuete;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LE VOCABULAIRE DES NOTES DE QUÊTE, TENU DANS LES DEUX SENS (`App\Engine\MotsClesQuete`).
 *
 * « Pas de clé décorative » : un déclencheur ou un effet que la validation accepterait sans que rien ne le joue serait
 * une règle promise au joueur et jamais tenue. Ces tests scannent les sources :
 *  - chaque EFFET déclaré a sa méthode dans `NotesDeQuete`, et `NotesDeQuete` n'a pas d'effet non déclaré ;
 *  - chaque DÉCLENCHEUR déclaré est SIGNALÉ par une couture du moteur ;
 *  - la validation accepte exactement ce vocabulaire, et refuse le reste.
 * Le jeu réel de chaque mot est dans `NotesDeQueteTest`.
 */

it('chaque effet déclaré a son lecteur dans NotesDeQuete, et aucun lecteur n\'est orphelin', function () {
    $methodes = collect((new ReflectionClass(NotesDeQuete::class))->getMethods())
        ->map(fn (ReflectionMethod $m) => $m->getName())
        ->filter(fn (string $nom) => str_starts_with($nom, 'effet'))
        ->sort()->values()->all();

    $declares = collect(MotsClesQuete::EFFETS)->pluck('lecteur')->sort()->values()->all();

    expect($declares)->toBe($methodes);
});

it('chaque déclencheur déclaré est signalé par une couture du moteur', function () {
    $constantes = array_flip(array_filter((new ReflectionClass(MotsClesQuete::class))->getConstants(), 'is_string'));
    $signales = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $fichier) {
        if ($fichier->getExtension() !== 'php' || $fichier->getFilename() === 'NotesDeQuete.php') {
            continue;
        }

        preg_match_all('/->signaler\(\s*[^,]+,\s*MotsClesQuete::([A-Z_]+)/', file_get_contents($fichier->getPathname()), $m);
        foreach ($m[1] as $constante) {
            $signales[constant(MotsClesQuete::class.'::'.$constante)] = true;
        }
    }

    $declares = array_keys(MotsClesQuete::DECLENCHEURS);
    sort($declares);
    $trouves = array_keys($signales);
    sort($trouves);

    expect($trouves)->toBe($declares)
        // Chaque déclencheur est bien une constante nommée : aucune chaîne en dur dans les coutures.
        ->and(array_diff($declares, array_keys($constantes)))->toBe([]);
});

it('la validation accepte exactement ce vocabulaire : un mot inconnu est refusé en le nommant', function () {
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
    $valider = fn (array $note) => app(ValidationCampagne::class)->valider((function () use ($note) {
        $doc = DocumentExemple::campagne();
        $doc['quetes'][0]['notes'] = [$note];

        return $doc;
    })());

    $bonne = [
        'repere' => 'A',
        'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
        'alors' => [['effet' => 'or', 'or' => 10]],
    ];
    expect($valider($bonne))->toBe([]);

    $refus = $valider([...$bonne, 'quand' => ['declencheur' => 'pleine_lune', 'cible' => ['salle' => 'entree']]]);
    expect(array_column($refus, 'chemin'))->toBe(['quetes.0.notes.0.quand.declencheur']);

    $refus = $valider([...$bonne, 'alors' => [['effet' => 'tremblement', 'force' => 3]]]);
    expect(array_column($refus, 'chemin'))->toBe(['quetes.0.notes.0.alors.0.effet']);

    // Le constructeur publie le MÊME vocabulaire que celui que la validation lit.
    $vocabulaires = app(CatalogueConstructeur::class)->payload()['vocabulaires'];
    expect($vocabulaires['declencheurs_de_note'])->toBe(MotsClesQuete::DECLENCHEURS)
        ->and($vocabulaires['effets_de_note'])->toBe(MotsClesQuete::EFFETS);
});

it('une note complète, de chaque déclencheur et de chaque effet, passe la validation', function () {
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['notes'] = [
        ['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
            'alors' => [['effet' => 'texte', 'texte' => 'Un texte.'], ['effet' => 'or', 'or' => 10], ['effet' => 'drapeau', 'nom' => 'vu']]],
        ['repere' => 'B', 'quand' => ['declencheur' => 'entree', 'cible' => ['salle' => 'galerie'], 'si_drapeau' => 'vu'],
            'alors' => [['effet' => 'apparition', 'monstres' => [['slug' => 'gobelin', 'x' => 8, 'y' => 4]]]]],
        ['repere' => 'C', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 4, 'y' => 3]]],
            'alors' => [['effet' => 'degats', 'body' => 1]], 'une_fois' => false],
        ['repere' => 'D', 'quand' => ['declencheur' => 'ouverture_porte', 'cible' => ['porte' => 0]],
            'alors' => [['effet' => 'objet', 'slug' => 'dague', 'quantite' => 1]]],
        ['repere' => 'E', 'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]],
            'alors' => [['effet' => 'objet', 'slug' => 'dague']]],
        ['repere' => 'F', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 3]],
            'alors' => [['effet' => 'ouvrir_porte', 'porte' => 1], ['effet' => 'bloc', 'x' => 8, 'y' => 7], ['effet' => 'reveler_porte', 'porte' => 2], ['effet' => 'teleporter', 'x' => 8, 'y' => 3]]],
    ];

    expect(app(ValidationCampagne::class)->valider($doc))->toBe([]);

    // Chaque mot du vocabulaire figure dans ce document : aucun n'est resté sans exemple validé.
    $notes = collect($doc['quetes'][0]['notes']);
    expect($notes->pluck('quand.declencheur')->unique()->sort()->values()->all())->toBe(collect(array_keys(MotsClesQuete::DECLENCHEURS))->sort()->values()->all())
        ->and($notes->pluck('alors')->flatten(1)->pluck('effet')->unique()->sort()->values()->all())->toBe(collect(array_keys(MotsClesQuete::EFFETS))->sort()->values()->all());
});

it('refuse une note mal formée en nommant ce qui cloche', function (array $changement, string $chemin, string $code) {
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['notes'] = [array_replace_recursive([
        'repere' => 'A',
        'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
        'alors' => [['effet' => 'or', 'or' => 10]],
    ], $changement)];

    $refus = app(ValidationCampagne::class)->valider($doc);

    expect(collect($refus)->contains(fn (array $r) => $r['chemin'] === $chemin && $r['code'] === $code))
        ->toBeTrue('refus attendu « '.$code.' » en « '.$chemin.' » ; reçu : '.json_encode($refus, JSON_UNESCAPED_UNICODE));
})->with([
    'salle qui n\'est pas ouverte dans cette quête' => [['quand' => ['cible' => ['salle' => 'crypte']]], 'quetes.0.notes.0.quand.cible.salle', ValidationCampagne::SALLE_INCONNUE],
    'drapeau lu que personne ne pose' => [['quand' => ['si_drapeau' => 'jamais']], 'quetes.0.notes.0.quand.si_drapeau', ValidationCampagne::DRAPEAU_JAMAIS_POSE],
    'or nul' => [['alors' => [['or' => 0]]], 'quetes.0.notes.0.alors.0.or', ValidationCampagne::VALEUR_INVALIDE],
    'paramètre d\'un autre effet' => [['alors' => [['texte' => 'en trop']]], 'quetes.0.notes.0.alors.0.texte', ValidationCampagne::CLE_INCONNUE],
    'une_fois qui n\'est pas un booléen' => [['une_fois' => 'oui'], 'quetes.0.notes.0.une_fois', ValidationCampagne::TYPE_INVALIDE],
    'repère vide' => [['repere' => ' '], 'quetes.0.notes.0.repere', ValidationCampagne::VALEUR_INVALIDE],
]);

it('refuse un objet, un monstre ou une cible que la quête ne connaît pas', function (array $note, string $chemin, string $code) {
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['notes'] = [$note];

    $refus = app(ValidationCampagne::class)->valider($doc);

    expect(collect($refus)->contains(fn (array $r) => $r['chemin'] === $chemin && $r['code'] === $code))
        ->toBeTrue('refus attendu « '.$code.' » en « '.$chemin.' » ; reçu : '.json_encode($refus, JSON_UNESCAPED_UNICODE));
})->with([
    'objet hors catalogue' => [
        ['repere' => 'A', 'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]], 'alors' => [['effet' => 'objet', 'slug' => 'epee-de-nulle-part']]],
        'quetes.0.notes.0.alors.0.slug', ValidationCampagne::CATALOGUE_INCONNU,
    ],
    'monstre visé qui n\'est pas posé' => [
        ['repere' => 'A', 'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 7]], 'alors' => [['effet' => 'or', 'or' => 5]]],
        'quetes.0.notes.0.quand.cible.monstre', ValidationCampagne::CIBLE_INCONNUE,
    ],
    'porte visée qui n\'existe pas' => [
        ['repere' => 'A', 'quand' => ['declencheur' => 'ouverture_porte', 'cible' => ['porte' => 9]], 'alors' => [['effet' => 'or', 'or' => 5]]],
        'quetes.0.notes.0.quand.cible.porte', ValidationCampagne::CIBLE_INCONNUE,
    ],
    'apparition dans une salle fermée' => [
        ['repere' => 'A', 'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]], 'alors' => [['effet' => 'apparition', 'monstres' => [['slug' => 'gobelin', 'x' => 4, 'y' => 10]]]]],
        'quetes.0.notes.0.alors.0.monstres.0', ValidationCampagne::DANS_SALLE_FERMEE,
    ],
    'apparition dessinée sur un meuble' => [
        ['repere' => 'A', 'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]], 'alors' => [['effet' => 'apparition', 'monstres' => [['slug' => 'gobelin', 'x' => 4, 'y' => 4]]]]],
        'quetes.0.notes.0.alors.0.monstres.0', ValidationCampagne::CASE_PARTAGEE,
    ],
    'note sans effet' => [
        ['repere' => 'A', 'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]], 'alors' => []],
        'quetes.0.notes.0.alors', ValidationCampagne::VALEUR_INVALIDE,
    ],
]);

it('l\'aperçu d\'une quête relit ses notes : quand, sur quoi, et quels effets', function () {
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['notes'] = [
        ['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
            'alors' => [['effet' => 'texte', 'texte' => 'Un texte.'], ['effet' => 'drapeau', 'nom' => 'vu']]],
        ['repere' => 'C', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 4, 'y' => 3]], 'si_drapeau' => 'vu'],
            'alors' => [['effet' => 'degats', 'body' => 1]], 'une_fois' => false],
    ];

    $apercu = app(\App\Partie\Classique\CarteConstruite::class)->apercu($doc, $doc['quetes'][0]);
    $texte = is_array($apercu) ? json_encode($apercu, JSON_UNESCAPED_UNICODE) : (string) $apercu;

    expect($texte)->toContain('note A : fouille_tresor — salle entree → texte, drapeau')
        ->toContain('note C : entree — case (4, 3), si « vu » → degats (à chaque fois)');
});
