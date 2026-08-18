<?php

declare(strict_types=1);

use App\Engine\RareteButin;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Partie\MoteurMobilier;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;

/**
 * La RARETÉ du butin (décision de René, 2026-08-17) : déduite du prix, et
 * pondérée par le niveau moyen du groupe.
 */
beforeEach(function () {
    // ⚠ `ClasseHerosSeeder` est indispensable : c'est lui qui porte
    // `tags_equipement`, et le contrôle d'accès est « fail open » quand aucune
    // classe n'est connue. Sans ce seeder, le filtre de maîtrise passerait sans
    // rien vérifier — le test aurait l'air vert en ne testant rien.
    $this->seed([
        SortSeeder::class, ObjetSeeder::class, MobilierSeeder::class, ClasseHerosSeeder::class,
    ]);
});

it('déduit la rareté du PRIX, sans exception hors artefacts et parchemins', function () {
    // Elle était posée à la main, ligne par ligne, et avait dérivé : Hachette
    // 200 po « commune » quand Baguette 125 était « peu commune », Cotte de
    // mailles 500 « rare » et Brassards 550 non. Un seuil qu'on relit vaut mieux
    // que trente-neuf valeurs qu'on recopie.
    foreach (Objet::whereIn('categorie', ['arme', 'armure', 'outil', 'consommable'])
        ->where('rarete', '!=', 'unique')->get() as $o) {
        expect($o->rarete)->toBe(RareteButin::pourPrix((int) $o->prix_base),
            "{$o->nom} ({$o->prix_base} po) : rareté incohérente avec son prix.");
    }

    // ⚠ Les PARCHEMINS gardent la leur : elle vient de la difficulté du sort,
    // un meilleur signal que le prix, qui n'en est que le reflet.
    expect(Objet::where('categorie', 'parchemin')->distinct()->pluck('rarete')->sort()->values()->all())
        ->toBe(['commun', 'peu_commun', 'rare']);
});

it('incline les chances vers le RARE quand le groupe monte, et sature au sommet', function () {
    $bas = RareteButin::poids(1);
    $haut = RareteButin::poids(8);

    expect($bas['commun'])->toBeGreaterThan($haut['commun'])
        ->and($haut['rare'])->toBeGreaterThan($bas['rare']);

    // Chaque tranche somme à 100 : les poids se lisent directement en %.
    foreach ([1, 3, 5, 7] as $niveau) {
        expect(array_sum(RareteButin::poids($niveau)))->toBe(100, "niveau {$niveau}");
    }

    // ⚠ Aucun plafond de niveau dans ce jeu (doc 01 §5) : la courbe SATURE au
    // lieu de déborder, sans quoi un groupe de niveau 20 sortirait de la table.
    expect(RareteButin::poids(20))->toBe(RareteButin::poids(7));
});

it('ne tire jamais une rareté ABSENTE du vivier', function () {
    // Un râtelier d'armes sans arme rare ne doit pas rendre « rien » une fois
    // sur trois à haut niveau : il doit rendre ce qu'il a.
    expect(RareteButin::tirer(['commun'], 8))->toBe('commun')
        ->and(RareteButin::tirer([], 8))->toBeNull();

    for ($i = 0; $i < 30; $i++) {
        expect(RareteButin::tirer(['commun', 'rare'], 4))->toBeIn(['commun', 'rare']);
    }
});

it('fait vraiment monter le rare dans le butin d\'un meuble', function () {
    $mm = app(MoteurMobilier::class);
    $coffre = Mobilier::where('nom', 'Coffre')->firstOrFail();

    $rares = fn (int $niveau) => collect(range(1, 400))
        ->map(fn () => $mm->tirerButin($coffre, $niveau))
        ->where('issue', 'objet')
        ->where('rarete', 'rare')
        ->count();

    // Statistique, donc volontairement LARGE : 2 % contre 35 % de chances de
    // rare, l'écart est massif — on ne teste pas le générateur, on teste que la
    // pente est branchée dans le bon sens.
    expect($rares(8))->toBeGreaterThan($rares(1),
        'le niveau du groupe n\'incline pas le butin vers le rare');
});

it('n\'offre PAS une potion de classe quand personne dans la quête ne peut la boire', function () {
    // Décision de René (2026-08-17). Trois potions sont réservées au Barbare,
    // deux à l'Elfe : sans cette garde, un groupe sans barbare passait sa
    // campagne à trouver des potions de rage guerrière — un butin ni jouable,
    // ni revendable en quête, et qui prend une place dans le sac.
    $mm = app(MoteurMobilier::class);
    $etabli = Mobilier::where('nom', 'Établi d\'alchimiste')->firstOrFail();

    $reserveesBarbare = Objet::where('tag_equipement', 'potion_barbare')->pluck('id')->all();
    expect($reserveesBarbare)->not->toBeEmpty();

    // Un groupe de NAIN et de MAGICIEN : ni l'un ni l'autre n'a `potion_barbare`.
    $sansBarbare = app(App\Partie\Equipement::class)->tagsAccessiblesAux(
        collect(['nain', 'magicien'])->map(fn ($c) => new App\Models\Personnage(['classe' => $c])),
    );

    $tires = collect(range(1, 250))
        ->map(fn () => $mm->tirerButin($etabli, 8, $sansBarbare))
        ->where('issue', 'objet')
        ->pluck('objet_id');

    expect($tires)->not->toBeEmpty('l\'établi n\'a rien rendu du tout : le filtre a trop coupé')
        ->and($tires->intersect($reserveesBarbare))->toBeEmpty(
            'une potion de barbare est tombée dans un groupe qui n\'en a pas');

    // ⚠ Fail open : sans tags connus, on n'écarte rien — une donnée de
    // référence manquante ne doit jamais appauvrir une partie.
    $sansFiltre = collect(range(1, 250))
        ->map(fn () => $mm->tirerButin($etabli, 8))
        ->where('issue', 'objet')
        ->pluck('objet_id');

    expect($sansFiltre->intersect($reserveesBarbare))->not->toBeEmpty(
        'le fail open ne fonctionne pas : rien ne sort sans liste de maîtrises');
});

it('laisse tomber la potion du barbare DÈS QU\'un barbare est là', function () {
    $mm = app(MoteurMobilier::class);
    $etabli = Mobilier::where('nom', 'Établi d\'alchimiste')->firstOrFail();

    $avecBarbare = app(App\Partie\Equipement::class)->tagsAccessiblesAux(
        collect(['nain', 'barbare'])->map(fn ($c) => new App\Models\Personnage(['classe' => $c])),
    );

    $tires = collect(range(1, 300))
        ->map(fn () => $mm->tirerButin($etabli, 8, $avecBarbare))
        ->where('issue', 'objet')
        ->pluck('objet_id');

    expect($tires->intersect(Objet::where('tag_equipement', 'potion_barbare')->pluck('id')->all()))
        ->not->toBeEmpty('le barbare est présent, sa potion devrait pouvoir tomber');
});
