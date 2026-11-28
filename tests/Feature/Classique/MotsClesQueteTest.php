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
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class, \Database\Seeders\MercenaireSeeder::class, \Database\Seeders\SortDreadSeeder::class]);
    $doc = DocumentExemple::campagne();
    // Lot K6 : `debloquer_mercenaire` désigne un mercenaire que la campagne offre.
    $doc['mercenaires'] = ['regime' => 'debloques', 'offerts' => ['eclaireur']];
    // 2026-11-20 : une figure `inactif`, que la note J éveille.
    $doc['quetes'][0]['monstres'][] = ['slug' => 'gobelin', 'x' => 9, 'y' => 2, 'inactif' => true];
    // Lot E2 : un chemin imposé, que la note T fait suivre aux dés.
    $doc['quetes'][0]['regles'] = ['chemin_impose' => ['porteur' => 'talisman-du-savoir', 'cases' => [['x' => 3, 'y' => 3], ['x' => 4, 'y' => 3]], 'arrets' => [1]], 'reserve_hors_plateau' => ['x' => 8, 'y' => 13]];
    // Lot E1 : un captif, que la note K libère.
    $doc['quetes'][0]['captif'] = ['mercenaire' => 'sir-ragnar-livret-de-base', 'x' => 8, 'y' => 4];
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
        // 2026-11-20 : la figure posée `inactif` qu'une note éveille (la Légion oubliée).
        ['repere' => 'J', 'quand' => ['declencheur' => 'ouverture_porte', 'cible' => ['porte' => 1]],
            'alors' => [['effet' => 'eveiller', 'monstres' => [1]]]],
        ['repere' => 'G', 'quand' => ['declencheur' => 'fouille_zone', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'texte', 'texte' => 'Rien.']]],
        ['repere' => 'H', 'quand' => ['declencheur' => 'dernier_heros_passe', 'cible' => ['case' => ['x' => 7, 'y' => 3]]],
            'alors' => [['effet' => 'bloc', 'x' => 7, 'y' => 3], ['effet' => 'detruire_objet', 'slug' => 'dague']]],
        ['repere' => 'F', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 3]],
            'alors' => [['effet' => 'ouvrir_porte', 'porte' => 1], ['effet' => 'bloc', 'x' => 8, 'y' => 7], ['effet' => 'reveler_porte', 'porte' => 2], ['effet' => 'teleporter', 'x' => 8, 'y' => 3]]],
        // Lot K6 (*Dread Moon*) : la réputation, l'offre, le mercenaire débloqué.
        ['repere' => 'I', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 4]],
            'alors' => [['effet' => 'reputation', 'jetons' => 1],
                ['effet' => 'offre', 'libelle' => 'Payer le passeur', 'cout' => ['or' => 200], 'alors' => [['effet' => 'reputation', 'jetons' => 1]]],
                ['effet' => 'debloquer_mercenaire', 'slug' => 'eclaireur']]],
        // Lot E1 : le jet à issues, l'objet tiré au hasard, le « sinon ».
        ['repere' => 'K', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 5]],
            'alors' => [['effet' => 'jet', 'issues' => [['de' => 1, 'a' => 3, 'alors' => [['effet' => 'degats', 'body' => 1]]], ['de' => 4, 'a' => 6, 'alors' => [['effet' => 'or', 'or' => 5]]]]],
                ['effet' => 'objet_au_hasard', 'parmi' => ['dague', 'bouclier']],
                ['effet' => 'objet', 'slug' => 'dague', 'sinon' => [['effet' => 'or', 'or' => 20]]],
                ['effet' => 'retirer', 'monstres' => [0]], ['effet' => 'retirer_meuble', 'mobilier' => 0],
                ['effet' => 'murer_porte', 'porte' => 0], ['effet' => 'retirer_bloc', 'x' => 11, 'y' => 7], ['effet' => 'liberer_captif'],
                ['effet' => 'arreter'], ['effet' => 'voler', 'or' => 25], ['effet' => 'errants'], ['effet' => 'declencher_piege', 'x' => 2, 'y' => 2]]],
        // Lot E2 (*Prophecy of Telor*) : le talisman.
        ['repere' => 'T', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 6]],
            'alors' => [['effet' => 'esprit', 'points' => 1], ['effet' => 'apprendre_sort_dread', 'sort' => 'eclair-de-chaos'],
                ['effet' => 'remplacer_objet', 'slug' => 'talisman-du-savoir', 'par' => 'talisman-du-savoir-maudit'],
                ['effet' => 'oublier_sorts_dread'], ['effet' => 'esprit_d_origine'], ['effet' => 'avancer_sur_chemin', 'des' => 2],
                ['effet' => 'metamorphoser', 'en' => 'orque', 'pv_body' => 2, 'sauf_declencheur' => true], ['effet' => 'rendre_forme']]],
        ['repere' => 'U', 'quand' => ['declencheur' => 'tous_aux_portes', 'cible' => ['portes' => [0, 1]]],
            'alors' => [['effet' => 'franchir_portes', 'portes' => [0, 1]]]],
        ['repere' => 'V', 'quand' => ['declencheur' => 'fardeau_rate', 'cible' => ['objet' => 'talisman-du-savoir-maudit']],
            'alors' => [['effet' => 'invoquer', 'sort' => 'invocation-dorques'], ['effet' => 'invoquer', 'sort' => 'invocation-dorques', 'x' => 8, 'y' => 3]]],
        ['repere' => 'W', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 7]],
            'alors' => [['effet' => 'en_reserve', 'monstres' => [['slug' => 'gobelin', 'nombre' => 2], ['slug' => 'orque']]]]],
        ['repere' => 'R', 'quand' => ['declencheur' => 'figure_apaisee', 'cible' => ['monstre' => 0]], 'alors' => [['effet' => 'texte', 'texte' => 'Elle remercie les héros.']]],
        ['repere' => 'S', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 8]],
            'alors' => [['effet' => 'soigner', 'mind' => 'tout', 'body' => 2], ['effet' => 'degats', 'body' => 2, 'tous_les_heros' => true],
                ['effet' => 'rejetons'], ['effet' => 'rejetons', 'nombre' => 4]]],
        // Lot E1 : les huit déclencheurs des extensions.
        ['repere' => 'L1', 'quand' => ['declencheur' => 'salle_videe', 'cible' => ['salle' => 'galerie']], 'alors' => [['effet' => 'texte', 'texte' => 'Vide.']]],
        ['repere' => 'L2', 'quand' => ['declencheur' => 'morts', 'cible' => ['monstres' => [0, 1]]], 'alors' => [['effet' => 'texte', 'texte' => 'Tous tombés.']]],
        ['repere' => 'L3', 'quand' => ['declencheur' => 'tous_entres', 'cible' => ['salle' => 'galerie']], 'alors' => [['effet' => 'texte', 'texte' => 'Tous là.']]],
        ['repere' => 'L4', 'quand' => ['declencheur' => 'salle_quittee', 'cible' => ['salle' => 'entree']], 'alors' => [['effet' => 'texte', 'texte' => 'Sortis.']]],
        ['repere' => 'L5', 'quand' => ['declencheur' => 'fin_de_tour', 'cible' => ['case' => ['x' => 8, 'y' => 3]]], 'alors' => [['effet' => 'texte', 'texte' => 'Arrêté.']]],
        ['repere' => 'L6', 'quand' => ['declencheur' => 'piege_declenche', 'cible' => ['salle' => 'entree']], 'alors' => [['effet' => 'texte', 'texte' => 'Clic.']]],
        ['repere' => 'L7', 'quand' => ['declencheur' => 'meuble_detruit', 'cible' => ['mobilier' => 0]], 'alors' => [['effet' => 'texte', 'texte' => 'Brisé.']]],
        // Lot E2 (Delthrak) : un cocon de la salle, et ce qu'il cache, un à la fois et dans l'ordre.
        ['repere' => 'L7b', 'une_fois' => false, 'quand' => ['declencheur' => 'meuble_detruit', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'en_ordre', 'etapes' => [['alors' => [['effet' => 'or', 'or' => 200]]], ['alors' => [['effet' => 'rejetons']]]]]]],
        ['repere' => 'L8', 'quand' => ['declencheur' => 'captif_libere', 'cible' => ['captif' => 'sir-ragnar-livret-de-base']], 'alors' => [['effet' => 'texte', 'texte' => 'Libre.']]],
        ['repere' => 'Y', 'quand' => ['declencheur' => 'objet_jete', 'cible' => ['objet' => 'dague']], 'alors' => [['effet' => 'texte', 'texte' => 'Englouti.']]],
        ['repere' => 'Z', 'quand' => ['declencheur' => 'heros_au_tour_du_mj', 'cible' => ['cases' => [['x' => 7, 'y' => 7], ['x' => 8, 'y' => 7]]],
            'si_sans_monstre' => 'entree'], 'alors' => [['effet' => 'texte', 'texte' => 'Un bruit de pas.']]],
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
