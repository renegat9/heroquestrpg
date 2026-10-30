<?php

declare(strict_types=1);

use App\Models\Epreuve;
use App\Models\Mobilier;
use App\Models\Piege;
use App\Models\Terrain;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;

/*
 * CHAQUE SYMBOLE DE LA CARTE A SON ICÔNE, ET RÉCIPROQUEMENT (René, 2026-08-27).
 *
 * `resources/js/components/carte/symboles.js` porte la table des icônes, lue à
 * la fois par le RENDU (`DungeonGrid`) et par la LÉGENDE (`LegendeCarte`).
 *
 * ⚠ Ce fichier confronte cette table au CATALOGUE, dans les deux sens — même
 * discipline que `MotsClesEpreuve`/`MotsClesTalent` pour les effets :
 *  - une entrée de catalogue sans icône retomberait sur le glyphe générique
 *    SANS ERREUR, et la légende annoncerait deux pièges sous le même symbole ;
 *  - une icône qui ne correspond plus à rien traîne dans la table et finit par
 *    donner son glyphe à une entrée renommée.
 *
 * ⚠ On ne teste PAS que le glyphe existe dans Material Symbols — la police est
 * distante, et un nom inconnu s'affiche en toutes lettres. C'est la seule chose
 * que ce test ne peut pas attraper ; elle se voit à l'écran.
 */

/** Clés d'une table `export const NOM = { 'clé': 'valeur', … };` de symboles.js. */
function iconesDeclarees(string $table): array
{
    $source = file_get_contents(base_path('resources/js/components/carte/symboles.js'));

    expect($source)->toContain("export const {$table} = {");

    $debut = strpos($source, "export const {$table} = {");
    $fin = strpos($source, '};', $debut);
    $bloc = substr($source, $debut, $fin - $debut);

    // ⚠ Les deux styles de guillemets : une clé qui porte une apostrophe
    // (« Râtelier d'armes ») est écrite en guillemets doubles. Ne lire que le
    // simple quote faisait passer le test pour les pièges et échouer pour le
    // mobilier — en accusant à tort la table d'être incomplète.
    preg_match_all('/^\s*(?:\'([^\']+)\'|"([^"]+)")\s*:/m', $bloc, $m);

    return array_map(fn (string $simple, string $double) => $simple !== '' ? $simple : $double, $m[1], $m[2]);
}

it('donne une icône à chaque PIÈGE du catalogue, et pas une de plus', function () {
    $this->seed(PiegeSeeder::class);

    $catalogue = Piege::pluck('nom')->all();
    $declarees = iconesDeclarees('PIEGE_ICONES');

    expect(array_diff($catalogue, $declarees))->toBe([], 'pièges sans icône propre')
        ->and(array_diff($declarees, $catalogue))->toBe([], 'icônes orphelines');
});

it('donne une icône à chaque ÉPREUVE du catalogue, et pas une de plus', function () {
    $this->seed(EpreuveSeeder::class);

    $catalogue = Epreuve::pluck('nom')->all();
    $declarees = iconesDeclarees('EPREUVE_ICONES');

    expect(array_diff($catalogue, $declarees))->toBe([], 'épreuves sans icône propre')
        ->and(array_diff($declarees, $catalogue))->toBe([], 'icônes orphelines');
});

it('donne une icône à chaque MEUBLE du catalogue, et pas une de plus', function () {
    $this->seed(MobilierSeeder::class);

    $catalogue = Mobilier::pluck('nom')->all();
    $declarees = iconesDeclarees('MOBILIER_ICONES');

    expect(array_diff($catalogue, $declarees))->toBe([], 'meubles sans icône propre')
        ->and(array_diff($declarees, $catalogue))->toBe([], 'icônes orphelines');
});

it('donne une teinte à chaque TERRAIN du catalogue, et pas une de plus', function () {
    // ⚠ Le défaut des leviers, mot pour mot (CLAUDE.md, 2026-08-27) : la couche
    // était PUBLIÉE (EtatGroupe.carte.terrain) mais dessinée NULLE PART — gratuit
    // tant qu'aucune case de glace n'était posée, un piège invisible le jour où
    // il y en a une. C'est ce test qui doit attraper le trou, comme il le fait
    // déjà pour le mobilier.
    $this->seed(TerrainSeeder::class);

    $catalogue = Terrain::pluck('nom')->all();
    $declarees = iconesDeclarees('TERRAIN_TEINTES');

    expect(array_diff($catalogue, $declarees))->toBe([], 'terrains sans teinte propre')
        ->and(array_diff($declarees, $catalogue))->toBe([], 'teintes orphelines');
});

it('donne une icône à chaque PLAQUE de terrain — bloc ou motif — et pas une de plus', function () {
    // Jungles of Delthrak (2026-10-24) : un terrain en bloc ou en motif se lit comme UNE plaque, qui porte une icône de REPLI
    // (`TERRAIN_ICONES`, posée sur la case d'ancre publiée par le serveur, sans pictogramme). Dans les deux sens : une plaque sans icône
    // retomberait en silence sur le repli ; une icône sans plaque ne désignerait rien.
    $this->seed(TerrainSeeder::class);

    $plaques = Terrain::query()
        // Une SALLE ENTIÈRE (`pose: salle`, lot H des restes, 2026-10-29) est une plaque `salle-N` : elle a une icône de repli.
        // Un couloir (`pose: couloirs`) pose des cases ISOLÉES, sans groupe : il n'en a pas.
        ->where(fn ($q) => $q->whereNotNull('motif')->orWhere('largeur', '>', 1)->orWhere('hauteur', '>', 1)->orWhere('pose', 'salle'))
        ->pluck('nom')->all();
    $declarees = iconesDeclarees('TERRAIN_ICONES');

    expect($plaques)->not->toBe([], 'aucune plaque au catalogue : le test serait vide')
        ->and(array_diff($plaques, $declarees))->toBe([], 'plaques sans icône')
        ->and(array_diff($declarees, $plaques))->toBe([], 'icônes orphelines');

    // Le rendu lit CETTE table, et ne la redéclare pas.
    $rendu = file_get_contents(base_path('resources/js/components/carte/DungeonGrid.vue'));
    expect(str_contains($rendu, 'TERRAIN_ICONES'))->toBeTrue('DungeonGrid ne lit pas TERRAIN_ICONES')
        ->and(str_contains($rendu, 'const TERRAIN_ICONES = {'))->toBeFalse('DungeonGrid redéclare la table des plaques');
});

it('couvre chaque ÉTAT DE PORTE par une illustration, et pas un de plus', function () {
    // ⚠ Les états viennent de `MoteurPortes::ETAT_*` et le contrat les publie
    // tels quels. Un état ajouté au moteur sans entrée de config retomberait en
    // silence sur l'emblème SVG ; une entrée que plus aucun état ne porte ferait
    // générer une image que rien n'irait jamais chercher.
    $reflet = new ReflectionClass(App\Partie\MoteurPortes::class);
    $etats = array_values(array_filter(
        $reflet->getConstants(),
        fn ($v, $c) => str_starts_with($c, 'ETAT_'),
        ARRAY_FILTER_USE_BOTH,
    ));

    $declares = array_keys((array) config('images.portes'));

    expect(array_diff($etats, $declares))->toBe([], 'états de porte sans illustration')
        ->and(array_diff($declares, $etats))->toBe([], 'illustrations orphelines');
});

it('publie une illustration pour le levier et pour chaque porte', function () {
    $biblio = app(App\Partie\Images\BibliothequeImages::class);

    // ⚠ Ces deux-là n'ont PAS de table de catalogue : leur accesseur ne prend
    // aucun id, et c'est pour ça qu'il est facile d'oublier de le brancher.
    // Ils sont TOTAUX — jamais `null`, l'emblème SVG prend le relais.
    expect($biblio->urlLevier())->not->toBeNull()
        ->and($biblio->urlPorte('verrouillee'))->not->toBeNull()
        // Un état inconnu ne doit pas rendre `null` : la porte existe quand même.
        ->and($biblio->urlPorte(null))->not->toBeNull()
        // ⚠ Deux états, deux images : une seule les rendrait indiscernables.
        ->and($biblio->urlPorte('fermee'))->not->toBe($biblio->urlPorte('secrete'));
});

it('fait lire la MÊME table au rendu et à la légende', function () {
    // ⚠ C'est l'invariant qui rend la légende fiable : si l'un des deux fichiers
    // se remettait à déclarer ses icônes en propre, la légende décrirait des
    // symboles que la carte ne dessine plus — et elle le ferait avec l'autorité
    // d'une légende.
    foreach (['DungeonGrid.vue', 'LegendeCarte.vue'] as $fichier) {
        $source = file_get_contents(base_path("resources/js/components/carte/{$fichier}"));

        expect(str_contains($source, "from './symboles.js'"))->toBeTrue("{$fichier} n'importe pas symboles.js")
            ->and(str_contains($source, 'const PIEGE_ICONES = {'))->toBeFalse("{$fichier} redéclare une table d'icônes")
            ->and(str_contains($source, 'const TERRAIN_TEINTES = {'))->toBeFalse("{$fichier} redéclare la table de terrain")
            // ⚠ L'assertion qui aurait attrapé le défaut des leviers AVANT qu'il
            // ne coûte un donjon verrouillé : une table déclarée dans
            // symboles.js mais jamais RÉFÉRENCÉE par le rendu ni la légende est
            // publiée pour rien — exactement ce qui est arrivé à `leviers`.
            ->and(str_contains($source, 'TERRAIN_TEINTES'))->toBeTrue("{$fichier} ne lit pas TERRAIN_TEINTES — le terrain resterait invisible");
    }
});

it('publie une silhouette pour les DANGERS mobiles, lue par le rendu ET par la légende (une couche publiée nulle part est muette)', function () {
    // Les dangers ont une icône unique (comme la glace) : pas de table à confronter au catalogue, mais la même règle
    // d'invariant — la silhouette est DÉCLARÉE dans symboles.js, et les deux composants la LISENT depuis ce fichier.
    // Une silhouette déclarée et jamais lue, ou lue sans être déclarée, est exactement la faute des leviers.
    $symboles = file_get_contents(base_path('resources/js/components/carte/symboles.js'));

    expect($symboles)->toContain("export const DANGER_ICONE = 'hexagon';");

    foreach (['DungeonGrid.vue', 'LegendeCarte.vue'] as $fichier) {
        $source = file_get_contents(base_path("resources/js/components/carte/{$fichier}"));

        expect(str_contains($source, 'DANGER_ICONE'))->toBeTrue("{$fichier} ne lit pas DANGER_ICONE");
    }

    // Le rendu comme la légende reçoivent la couche telle que le serveur la publie : `dangers`, jamais re-dérivée.
    expect(file_get_contents(base_path('resources/js/components/carte/DungeonGrid.vue')))->toContain('dangers: { type: Array')
        ->and(file_get_contents(base_path('resources/js/components/carte/LegendeCarte.vue')))->toContain('props.carte?.dangers');
});

it('le front TOURNE le pictogramme que le serveur décide, et la table transporte le champ jusqu\'au rendu', function () {
    // Le client ne calcule jamais la rotation : il lit `symbole_rotation`. La table reconstruit ses meubles champ par champ
    // (`mobilierVersDecor`) : sans ce champ, le râtelier posé couché retomberait debout, sur la table seulement.
    $jeu = file_get_contents(base_path('resources/js/store/game.js'));
    $grille = file_get_contents(base_path('resources/js/components/carte/DungeonGrid.vue'));
    $symbole = file_get_contents(base_path('resources/js/components/carte/SymboleCarte.vue'));

    expect(str_contains($jeu, 'symbole_rotation: m.symbole_rotation ?? 0'))->toBeTrue('mobilierVersDecor ne transporte pas symbole_rotation')
        ->and(str_contains($grille, ':tourne="f.symbole_rotation ?? 0"'))->toBeTrue('DungeonGrid ne passe pas la rotation au pictogramme')
        ->and(str_contains($symbole, 'rotate(90deg)'))->toBeTrue('SymboleCarte ne sait pas tourner un dessin');
});

it('une IMAGE ne fait jamais la taille d\'une case ni d\'une rangée : posée en absolu, et la table ne dimensionne plus ses colonnes en 1fr', function () {
    // Une image dans le flux gonflait la piste qui la portait (une rangée de la table passait de 73 à 119 px) ; une piste
    // `1fr` ne descend jamais sous la taille minimale de son contenu. Les deux défauts se lisent dans la source.
    $symbole = file_get_contents(base_path('resources/js/components/carte/SymboleCarte.vue'));
    $carte = file_get_contents(base_path('resources/js/components/table/DungeonMap.vue'));

    expect(str_contains($symbole, 'position: absolute'))->toBeTrue('le pictogramme entre dans le flux de la grille')
        ->and(str_contains($carte, 'repeat(${c}, 1fr)'))->toBeFalse('la table redimensionne ses colonnes en 1fr')
        ->and(str_contains($carte, 'repeat(${c}, minmax(0, 1fr))'))->toBeTrue('colonnes de la table sans minimum nul')
        ->and(str_contains($carte, 'repeat(${r}, minmax(0, 1fr))'))->toBeTrue('rangées de la table sans minimum nul');
});

it('le cadenas d\'une porte verrouillée se voit AUSSI avec un pictogramme : le livret dessine verrouillée comme fermée', function () {
    // `verrouillee.webp` a le cadre de `fermee` (manifeste) : sans badge, le verrou disparaîtrait dès qu'il y a un pictogramme.
    $grille = file_get_contents(base_path('resources/js/components/carte/DungeonGrid.vue'));

    expect(str_contains($grille, 'v-if="d.cadenas"'))->toBeTrue('le cadenas n\'est plus rendu')
        // La LIAISON du template, pas la classe : la règle CSS porte le même nom et survivrait à la suppression du badge.
        ->and(str_contains($grille, "'dg-door-lock--badge': d.symbole_url"))->toBeTrue('aucun badge de cadenas sur une porte à pictogramme');
});

it('le front TOURNE le battant d\'une PORTE que le serveur décide, et pose les JETONS de figure AU-DESSUS des plaques', function () {
    // La rotation d'une porte suit la même voie que celle d'un meuble : publiée, transportée, puis lue par `SymboleCarte`.
    // Les figures (héros, alliés, monstres) sont dans une couche plus haute que les plaques : sans elle, le pictogramme d'un
    // terrain recouvrirait le jeton. Les deux z-index se lisent dans la source.
    $grille = file_get_contents(base_path('resources/js/components/carte/DungeonGrid.vue'));

    expect(str_contains($grille, 'symbole_rotation: p.symbole_rotation ?? 0'))->toBeTrue('la porte ne transporte pas sa rotation jusqu\'au rendu')
        ->and(str_contains($grille, ':tourne="d.symbole_rotation"'))->toBeTrue('le battant d\'une porte n\'est pas tourné')
        ->and(str_contains($grille, 'class="ent-holder dg-jeton-holder"'))->toBeTrue('les figures ne sont pas dans leur couche de jetons')
        ->and(preg_match('/\.dg-jeton-holder \{[^}]*z-index: 4/', $grille))->toBe(1, 'les jetons ne passent pas au-dessus des plaques')
        ->and(preg_match('/\.dg-plaque-holder \{[^}]*z-index: 2/', $grille))->toBe(1, 'les plaques ne sont plus à z 2 : la comparaison serait fausse');
});

it('une figure est UN jeton posé par DungeonGrid, sur la table comme sur la manette : aucun second rendu, aucune superposition', function () {
    $table = file_get_contents(base_path('resources/js/components/table/DungeonMap.vue'));
    $manette = file_get_contents(base_path('resources/js/components/manette/DeplacementSheet.vue'));
    $grille = file_get_contents(base_path('resources/js/components/carte/DungeonGrid.vue'));

    expect(str_contains($table, 'class="fig'))->toBeFalse('la table redessine ses figures hors de DungeonGrid')
        ->and(str_contains($manette, '#cell'))->toBeFalse('la manette superpose encore des marqueurs sur la case')
        ->and(substr_count($grille, 'function disposerJetons('))->toBe(1, 'le placement des jetons a plus d\'un point de passage')
        ->and(str_contains($table, ':figures="entities"'))->toBeTrue('la table ne confie pas ses figures au socle')
        ->and(str_contains($manette, ':figures="figures"'))->toBeTrue('la manette ne confie pas ses figures au socle')
        ->and(str_contains($grille, 'class="jeton"'))->toBeTrue('le jeton n\'a pas son gabarit unique')
        ->and(str_contains($grille, '<Vignette'))->toBeTrue('le jeton n\'affiche pas ses images par Vignette');
});

it('le jeton du MONSTRE est transporté par le converseur de figures, et le pictogramme passe AVANT le portrait', function () {
    // `symbole_url` est décidé par le serveur (monstres de `EtatGroupe`) : le converseur de figures doit le transporter, et
    // le jeton le montrer en premier, sinon le livret ne serait jamais dessiné là où le portrait tenait sa place.
    $jeu = file_get_contents(base_path('resources/js/store/game.js'));
    $grille = file_get_contents(base_path('resources/js/components/carte/DungeonGrid.vue'));

    expect(str_contains($jeu, 'symbole_url: e.symbole_url ?? null'))->toBeTrue('entitesVersFigurines ne transporte pas le jeton du monstre')
        ->and(str_contains($grille, 'image: p.f.symbole_url ?? (props.portraits ? p.f.img : null)'))->toBeTrue('le jeton ne montre pas le pictogramme du monstre en premier')
        // La manette n'a pas de portrait à 38 px (règle du 2026-09-11) : `portraits` est le seul interrupteur.
        ->and(str_contains($grille, 'icone: props.portraits ? (p.f.ic ?? null) : null'))->toBeTrue('l\'icône de classe échappe au réglage des portraits');
});

it('le jeton OVALE : forme et rotation viennent du serveur, DungeonGrid le pose sur son emprise, SymboleCarte le tourne', function () {
    // Phase 3 (2026-10-29) : `symbole_forme` et `symbole_rotation` sont décidés par le serveur ; le client ne les recalcule pas.
    $jeu = file_get_contents(base_path('resources/js/store/game.js'));
    $grille = file_get_contents(base_path('resources/js/components/carte/DungeonGrid.vue'));
    $symbole = file_get_contents(base_path('resources/js/components/carte/SymboleCarte.vue'));

    expect(str_contains($jeu, 'symbole_forme: e.symbole_forme ?? null'))->toBeTrue('entitesVersFigurines ne transporte pas la forme du jeton')
        ->and(str_contains($jeu, 'symbole_rotation: e.symbole_rotation ?? 0'))->toBeTrue('entitesVersFigurines ne transporte pas la rotation du jeton')
        ->and(str_contains($grille, ':tourne="p.f.symbole_rotation ?? 0"'))->toBeTrue('le jeton ovale ne lit pas la rotation publiée')
        ->and(str_contains($grille, ':echelle="100"'))->toBeTrue('le jeton ovale ne remplit pas son ellipse')
        ->and(str_contains($grille, "f.symbole_forme === 'ovale'"))->toBeTrue('la forme ovale n\'est pas lue sur le champ publié')
        // Un ovale ne se pose jamais sur une emprise carrée : il reste rond.
        ->and(str_contains($grille, '(f.ew ?? 1) !== (f.eh ?? 1)'))->toBeTrue('un ovale sur une emprise carrée n\'est pas écarté')
        ->and(str_contains($grille, 'const JETON_OVALE = 0.85'))->toBeTrue('le jeton ovale n\'occupe pas 85 % de son emprise')
        ->and(str_contains($symbole, 'echelle: { type: Number, default: 80 }'))->toBeTrue('SymboleCarte n\'accepte pas d\'échelle')
        // La rotation vient de SymboleCarte, le mécanisme des meubles : DungeonGrid ne recopie jamais la transformation.
        ->and(str_contains($grille, 'rotate(90deg)'))->toBeFalse('DungeonGrid recopie la rotation au lieu de réutiliser SymboleCarte');
});
