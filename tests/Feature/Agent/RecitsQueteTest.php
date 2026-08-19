<?php

declare(strict_types=1);

use App\Agent\Skills\RecitsQuete;
use App\Models\Quete;
use App\Partie\Narration\BibliothequeNarration;
use App\Partie\Narration\TempsFort;
use Illuminate\Support\Facades\Http;

/**
 * Skill fusionné de pré-génération des récits (décision de René, 2026-08-18,
 * qui remplace `RecitsSalles` + `RecitsTempsForts` par un seul appel) : une
 * description PAR SALLE de la carte, plus les 3 temps forts qui ne dépendent
 * d'aucun tirage (`TempsFort::GENERES_PAR_QUETE`). Les 21 autres temps forts
 * viennent désormais UNIQUEMENT de `config/narration.php` — écrire sur mesure
 * les 5 issues possibles d'une fouille ne dit rien qu'une table fixe ne dise
 * aussi bien, pour ~5 500 tokens et 2-3 minutes de génération par quête.
 *
 * Ces tests portent sur le SKILL isolé (pas de quête/carte réelle en base) :
 * `prompt()`/`validerMetier()` ne lisent que `salles_a_decrire` et les
 * sections optionnelles de contexteEnTexte, donc un contexte fabriqué à la
 * main suffit — voir tests/Feature/Partie/GenererRecitsQueteTest.php pour le
 * pipeline complet (carte réelle assemblée, monstres habillés).
 */
function contexteSalles(array $sallesADecrire): array
{
    return [
        'groupe' => ['nom' => 'Les Lames du Crépuscule', 'theme' => 'Cryptes maudites sous la cité', 'ton' => 'héroïque classique'],
        'squelette' => null,
        'bible' => [],
        'salles_a_decrire' => $sallesADecrire,
    ];
}

function salleADecrire(int $id, array $extra = []): array
{
    return [...['salle' => $id, 'theme' => 'generique', 'profondeur' => 0, 'coffre' => false, 'mobilier' => [], 'monstres' => []], ...$extra];
}

/** Une variante de temps fort valide : longue, portant chacune des variables légales de la clé. */
function varianteTempsFortValide(string $cle, int $i): string
{
    $texte = "Récit numéro {$i} pour le temps fort {$cle}, assez long pour la validation du schéma.";
    foreach (TempsFort::variablesDe($cle) as $placeholder) {
        $texte .= " {{$placeholder}}";
    }

    return $texte;
}

/** Les 3 temps forts que cette quête écrit, chacun valide selon TempsFort::variablesDe(). */
function tempsFortsValides(): array
{
    return array_map(fn (string $cle) => [
        'cle' => $cle,
        'ambiance' => 'tension',
        'variantes' => [
            varianteTempsFortValide($cle, 1),
            varianteTempsFortValide($cle, 2),
            varianteTempsFortValide($cle, 3),
        ],
    ], TempsFort::GENERES_PAR_QUETE);
}

/**
 * Fake Anthropic répondant à l'outil UNIQUE `ecrire_recits_quete`, avec les
 * ids de salle extraits du bloc « SALLES À DÉCRIRE » du prompt (pour produire
 * une sortie sur mesure par test) et des temps forts valides par défaut — un
 * test qui vise les salles n'a pas besoin de fabriquer ses propres temps
 * forts, et réciproquement.
 */
function fakeRecitsQuete(Closure $fabriquerSalles, ?Closure $fabriquerTempsForts = null): void
{
    $fabriquerTempsForts ??= fn () => tempsFortsValides();

    Http::fake(function ($request) use ($fabriquerSalles, $fabriquerTempsForts) {
        $data = $request->data();

        if (str_contains($request->url(), 'anthropic') && ($data['tool_choice']['name'] ?? null) === 'ecrire_recits_quete') {
            $contenu = $data['messages'][0]['content'] ?? '';
            preg_match_all('/"salle":\s*(\d+)/', is_string($contenu) ? $contenu : json_encode($contenu), $m);
            $ids = array_values(array_unique(array_map('intval', $m[1])));

            return Http::response([
                'stop_reason' => 'tool_use',
                'content' => [['type' => 'tool_use', 'name' => 'ecrire_recits_quete', 'input' => [
                    'salles' => $fabriquerSalles($ids),
                    'temps_forts' => $fabriquerTempsForts(),
                ]]],
            ]);
        }

        return Http::response([], 200);
    });
    config()->set('services.anthropic.api_key', 'cle-test');
}

it('accepte une sortie complète : une description par salle demandée, et les 3 temps forts autorisés', function () {
    fakeRecitsQuete(fn ($ids) => array_map(fn ($id) => [
        'salle' => $id,
        'texte' => 'Une chambre de pierre humide où résonne un lointain écho, tapissée de toiles poussiéreuses.',
        'entree' => '{heros} pousse la porte et découvre la pièce voûtée.',
        'ambiance' => 'mystere',
    ], $ids));

    $sortie = app(RecitsQuete::class)->generer(contexteSalles([
        salleADecrire(0),
        salleADecrire(1, ['coffre' => true, 'mobilier' => ['Table'], 'monstres' => [['nom' => 'Écumeur des cryptes', 'nombre' => 2]]]),
    ]));

    expect(collect($sortie['salles'])->pluck('salle')->sort()->values()->all())->toBe([0, 1])
        ->and(collect($sortie['temps_forts'])->pluck('cle')->sort()->values()->all())
        ->toBe(collect(TempsFort::GENERES_PAR_QUETE)->sort()->values()->all());
});

it('rejette une variable dans le texte de salle : le texte figé doit le rester (voix pré-enregistrable)', function () {
    fakeRecitsQuete(fn ($ids) => array_map(fn ($id) => [
        'salle' => $id,
        'texte' => "Un {monstre} rôde ici, tapi dans l'ombre humide de la salle voûtée.",
        'entree' => '{heros} pousse la porte.',
        'ambiance' => 'tension',
    ], $ids));

    $sortie = app(RecitsQuete::class)->generer(contexteSalles([salleADecrire(0)]));

    // Rejeté à chaque tentative -> repli intégral (RecitsQuete::repli()) :
    // le texte vient de config/narration.php, jamais la variante fautive, et
    // aucun temps fort n'accompagne un repli (config/narration.php les porte déjà).
    expect($sortie['salles'][0]['texte'])->not->toContain('{monstre}')
        ->and($sortie['salles'][0]['texte'])->toBeIn(config('narration.repli.salle_decouverte.variantes'))
        ->and($sortie['temps_forts'])->toBe([]);
});

it("rejette une entree sans {heros} : la phrase d'entrée doit nommer l'arrivant", function () {
    fakeRecitsQuete(fn ($ids) => array_map(fn ($id) => [
        'salle' => $id,
        'texte' => 'Une chambre de pierre humide où résonne un lointain écho, tapissée de toiles poussiéreuses.',
        'entree' => "La porte grince et s'ouvre lentement sur l'obscurité.",
        'ambiance' => 'tension',
    ], $ids));

    $sortie = app(RecitsQuete::class)->generer(contexteSalles([salleADecrire(0)]));

    // Repli RecitsQuete::repli() : pas de phrase d'entrée générique (celle-là
    // ne sert que là où la voix enregistrée manque — CLAUDE.md).
    expect($sortie['salles'][0]['entree'])->toBe('');
});

it("rejette une entree portant une autre variable que {heros}", function () {
    fakeRecitsQuete(fn ($ids) => array_map(fn ($id) => [
        'salle' => $id,
        'texte' => 'Une chambre de pierre humide où résonne un lointain écho, tapissée de toiles poussiéreuses.',
        'entree' => '{heros} découvre un {objet} incroyable en poussant la porte.',
        'ambiance' => 'tension',
    ], $ids));

    $sortie = app(RecitsQuete::class)->generer(contexteSalles([salleADecrire(0)]));

    expect($sortie['salles'][0]['entree'])->toBe('');
});

it('rejette une couverture de salle incomplète', function () {
    // Une seule salle décrite sur les deux demandées, à chaque tentative.
    fakeRecitsQuete(fn ($ids) => [[
        'salle' => $ids[0] ?? 0,
        'texte' => 'Une chambre de pierre humide où résonne un lointain écho.',
        'entree' => '{heros} pousse la porte.',
        'ambiance' => 'mystere',
    ]]);

    $sortie = app(RecitsQuete::class)->generer(contexteSalles([salleADecrire(0), salleADecrire(1)]));

    // Repli : une entrée par salle réellement demandée, aucune oubliée.
    expect(collect($sortie['salles'])->pluck('salle')->sort()->values()->all())->toBe([0, 1]);
});

it('rejette une couverture de salle dupliquée', function () {
    // Deux entrées pour la salle 0, aucune pour la salle 1.
    fakeRecitsQuete(fn ($ids) => [
        ['salle' => 0, 'texte' => 'Une chambre de pierre humide où résonne un lointain écho.', 'entree' => '{heros} pousse la porte.', 'ambiance' => 'mystere'],
        ['salle' => 0, 'texte' => 'Une seconde description de la même salle, tout aussi longue et détaillée.', 'entree' => '{heros} y revient plus tard.', 'ambiance' => 'mystere'],
    ]);

    $sortie = app(RecitsQuete::class)->generer(contexteSalles([salleADecrire(0), salleADecrire(1)]));

    expect(collect($sortie['salles'])->pluck('salle')->sort()->values()->all())->toBe([0, 1]);
});

it("rejette une salle d'id inconnu, hors de la carte demandée", function () {
    fakeRecitsQuete(fn ($ids) => [
        ['salle' => 0, 'texte' => 'Une chambre de pierre humide où résonne un lointain écho.', 'entree' => '{heros} pousse la porte.', 'ambiance' => 'mystere'],
        ['salle' => 99, 'texte' => "Une salle qui n'existe nulle part sur cette carte précise.", 'entree' => '{heros} s\'y aventure.', 'ambiance' => 'mystere'],
    ]);

    // Une seule salle est réellement demandée : la 99 n'existe pas sur cette carte.
    $sortie = app(RecitsQuete::class)->generer(contexteSalles([salleADecrire(0)]));

    expect(collect($sortie['salles'])->pluck('salle')->all())->toBe([0]);
});

it("rejette un temps fort hors des 3 autorisés pour cette quête", function () {
    fakeRecitsQuete(
        fn ($ids) => array_map(fn ($id) => [
            'salle' => $id,
            'texte' => 'Une chambre de pierre humide où résonne un lointain écho.',
            'entree' => '{heros} pousse la porte.',
            'ambiance' => 'mystere',
        ], $ids),
        fn () => [
            ...array_slice(tempsFortsValides(), 0, 2),
            // « porte_ouverte » n'est PAS dans TempsFort::GENERES_PAR_QUETE :
            // ce temps fort ne vient que de config/narration.php désormais.
            ['cle' => 'porte_ouverte', 'ambiance' => 'tension', 'variantes' => [
                'La porte grince et s\'ouvre lentement sur le noir, révélant enfin le passage.',
                'Un grincement sourd, puis le battant cède et le passage se révèle aux héros.',
                'Le battant finit par céder, libérant le chemin vers la suite du donjon.',
            ]],
        ],
    );

    $sortie = app(RecitsQuete::class)->generer(contexteSalles([salleADecrire(0)]));

    // Rejeté à chaque tentative (clé hors du vocabulaire enum du schéma ET
    // hors GENERES_PAR_QUETE côté validerMetier) -> repli intégral : aucun
    // temps fort n'est produit ici, config/narration.php porte déjà les 24 clés.
    expect($sortie['temps_forts'])->toBe([]);
});

it("rejette une variable illégale pour la clé d'un temps fort (quete_demarree n'en accepte aucune)", function () {
    fakeRecitsQuete(
        fn ($ids) => array_map(fn ($id) => [
            'salle' => $id,
            'texte' => 'Une chambre de pierre humide où résonne un lointain écho.',
            'entree' => '{heros} pousse la porte.',
            'ambiance' => 'mystere',
        ], $ids),
        function () {
            $forts = tempsFortsValides();
            foreach ($forts as &$bloc) {
                if ($bloc['cle'] === 'quete_demarree') {
                    // {heros} figure dans le vocabulaire GLOBAL (TempsFort::VARIABLES)
                    // mais "quete_demarree" ne l'autorise pas (aucun héros précis
                    // n'ouvre la quête pour tout le groupe) — TempsFort::variablesDe().
                    $bloc['variantes'][0] = 'Le groupe de {heros} franchit le seuil du donjon, torches en main.';
                }
            }

            return $forts;
        },
    );

    $sortie = app(RecitsQuete::class)->generer(contexteSalles([salleADecrire(0)]));

    expect($sortie['temps_forts'])->toBe([]);
});

it('sans LLM joignable, retombe sur config/narration.php pour les salles et ne produit AUCUN temps fort', function () {
    config()->set('services.anthropic.api_key', null);
    Http::fake(['api.anthropic.com/*' => Http::response([], 500), '*' => Http::response([], 200)]);

    $sallesADecrire = collect(range(0, 5))->map(fn ($id) => salleADecrire($id, ['profondeur' => $id]))->all();

    $sortie = app(RecitsQuete::class)->generer(contexteSalles($sallesADecrire));

    expect($sortie['salles'])->toHaveCount(6);
    foreach ($sortie['salles'] as $entree) {
        expect($entree['texte'])->toBeIn(config('narration.repli.salle_decouverte.variantes'))
            ->and($entree['entree'])->toBe('');
    }

    // Voulu (CLAUDE.md / RecitsQuete::repli()) : config/narration.php porte
    // déjà les 24 clés de temps forts — en fabriquer ici ferait doublon avec
    // ce que BibliothequeNarration::pourQuete() sait déjà retrouver seule.
    expect($sortie['temps_forts'])->toBe([]);
});

/**
 * Règle de voix (CLAUDE.md, René 2026-08-18) : `BibliothequeNarration::salle()`
 * décide sur un FAIT — l'audio pré-généré existe-t-il pour ce texte figé ? —
 * jamais sur un réglage. Glisser {heros} dans un texte déjà enregistré
 * désynchroniserait la bande-son (on entendrait une phrase, on en lirait une
 * autre) ; à l'inverse, sans audio, la table lit de toute façon en voix de
 * navigateur, et nommer l'arrivant ne coûte alors plus rien.
 */
it("BibliothequeNarration::salle() sert le texte figé SEUL si sa bande-son existe, sinon entree+texte avec {heros} substitué", function () {
    $lib = app(BibliothequeNarration::class);
    $quete = new Quete();
    $quete->recits = ['salles' => ['0' => [
        'texte' => 'Texte figé, présent partout, jamais modifié pour rester enregistrable.',
        'entree' => '{heros} pousse la porte et découvre la pièce.',
        'ambiance' => 'mystere',
    ]]];

    // Pas d'audio pré-généré pour ce texte -> entree (substituée) + texte.
    $recit = $lib->salle($quete, 0, ['heros' => 'Albrecht']);
    expect($recit['texte'])->toBe('Albrecht pousse la porte et découvre la pièce. Texte figé, présent partout, jamais modifié pour rester enregistrable.')
        ->and($recit['url'])->toBeNull();

    // Un fichier audio existe pour le HASH du texte figé -> texte SEUL,
    // jamais la phrase d'entrée (qui désynchroniserait la voix enregistrée).
    $chemin = $lib->cheminDynamique('Texte figé, présent partout, jamais modifié pour rester enregistrable.');
    @mkdir(dirname($chemin['absolu']), 0775, true);
    file_put_contents($chemin['absolu'], 'faux-wav-de-test');

    try {
        $recit = $lib->salle($quete, 0, ['heros' => 'Albrecht']);
        expect($recit['texte'])->toBe('Texte figé, présent partout, jamais modifié pour rester enregistrable.')
            ->and($recit['url'])->toBe($chemin['url']);
    } finally {
        // Chemin réel sous public/, gitignoré mais pas nettoyé tout seul.
        @unlink($chemin['absolu']);
    }
});
