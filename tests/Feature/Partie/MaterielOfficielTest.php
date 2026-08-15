<?php

declare(strict_types=1);

use App\Models\Inventaire;
use App\Models\Objet;
use App\Jobs\GenererMenu;
use App\Partie\MoteurSorts;
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
 * Les quatre objets de MATÉRIEL des cartes officielles (doc 16 §2.1bis) — ceux
 * qui ne sont ni une arme, ni une armure, ni une potion.
 *
 * Chacun a fallu lui écrire une mécanique entière : une couche de carte pour
 * les chausse-trappes, une condition de monstre pour la bombe fumigène, une
 * mort sans jet pour l'eau bénite, une arme virtuelle pour la bandoulière.
 * C'est ce que ce fichier éprouve — la carte est portée, ou elle ne l'est pas.
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
});

/**
 * Régénère le menu du héros — `ChoixController` refuse toute option absente du
 * DERNIER menu proposé, et c'est précisément la garde que ces cartes doivent
 * franchir : un objet dont le menu ne parle pas est injouable.
 */
function menuPour(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return (array) (Illuminate\Support\Facades\Cache::get(
        GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id)
    )['menu']['options'] ?? []);
}

/** Pose l'objet nommé dans le sac du héros et rend la ligne d'inventaire. */
function materielAuSac(int $personnageId, string $nom): Inventaire
{
    $objet = Objet::where('nom', $nom)->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $personnageId,
        'objet_id' => $objet->id,
        'emplacement' => $objet->emplacement === 'consommable' ? 'consommable' : 'sac',
        'quantite' => 1,
    ]);
}

it('sème des chausse-trappes sur sa case, sans dépenser son action', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['heros' => $heros, 'quete' => $quete, 'etatHeros' => $etat] = $ctx;
    $ligne = materielAuSac($heros->id, 'Chausse-trappes');

    expect(collect(menuPour($ctx))->pluck('id'))->toContain("poser_chausse_trappes_{$ligne->id}");

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "poser_chausse_trappes_{$ligne->id}",
    ])->assertAccepted();

    $tuiles = (array) data_get($quete->fresh()->carte->grille, 'chausse_trappes', []);

    expect($tuiles)->toHaveCount(1)
        ->and((int) $tuiles[0]['x'])->toBe((int) $etat->position_x)
        ->and((int) $tuiles[0]['y'])->toBe((int) $etat->position_y);

    // « Use anytime on your turn, no action required » : le créneau d'action
    // reste libre, et l'exemplaire est perdu.
    expect((bool) $etat->fresh()->a_agi)->toBeFalse()
        ->and(Inventaire::find($ligne->id))->toBeNull();
});

it('enfume un monstre au contact : il cesse d\'occuper sa case, et perd son tour', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['heros' => $heros, 'quete' => $quete, 'instance' => $instance] = $ctx;
    $ligne = materielAuSac($heros->id, 'Bombe fumigène');
    menuPour($ctx);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "fumigene_{$ligne->id}",
        'parametres' => ['cible_id' => $instance->id],
    ])->assertAccepted();

    expect(app(MoteurSorts::class)->monstreA($instance->fresh(), MoteurSorts::MONSTRE_ENFUME))->toBeTrue();

    // La case du monstre n'est plus occupée : c'est la SEULE boucle
    // d'occupation du moteur, donc mouvement et ligne de vue tombent ensemble.
    $grille = App\Partie\FabriqueGrille::pour($quete->fresh());
    expect($grille->estTraversable((int) $instance->position_x, (int) $instance->position_y))->toBeTrue();
});

it('refuse de TERMINER son mouvement sur la case d\'un monstre enfumé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['instance' => $instance] = $ctx;
    app(MoteurSorts::class)->poserConditionMonstre($instance, MoteurSorts::MONSTRE_ENFUME);

    // « move unseen THROUGH the monster's space » : traverser n'est pas s'y
    // arrêter. Sans cette garde, deux figurines se retrouveraient empilées —
    // le monstre étant sorti de `$occupees`, le BFS l'accepterait volontiers.
    menuPour($ctx);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => (int) $instance->position_x, 'y' => (int) $instance->position_y],
    ])->assertStatus(422);
});

it('tue un mort-vivant à l\'eau bénite, et ne fait rien à un orque', function () {
    $ctx = demarrerQueteAvecMonstre('Squelette');
    ['heros' => $heros, 'instance' => $instance] = $ctx;
    $ligne = materielAuSac($heros->id, 'Eau bénite');
    menuPour($ctx);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "eau_benite_{$ligne->id}",
        'parametres' => ['cible_id' => $instance->id],
    ])->assertAccepted();

    expect($instance->fresh()->etat)->toBe('vaincu')
        ->and((int) $instance->fresh()->pv_body)->toBe(0)
        ->and(Inventaire::find($ligne->id))->toBeNull();
});

it('refuse l\'eau bénite sur une créature qui n\'est pas morte-vivante', function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    ['heros' => $heros, 'instance' => $instance] = $ctx;
    $ligne = materielAuSac($heros->id, 'Eau bénite');

    // ⚠ Le test porte sur `nom_base`, le nom de CATALOGUE. L'IA habille les
    // monstres à chaque quête : si l'eau bénite lisait le nom affiché, elle
    // cesserait de reconnaître un squelette dès la première partie narrée.
    $instance->update(['habillage' => ['nom' => 'Squelette hurlant']]);
    menuPour($ctx);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "eau_benite_{$ligne->id}",
        'parametres' => ['cible_id' => $instance->id],
    ])->assertStatus(422);

    expect($instance->fresh()->etat)->toBe('actif');
});

it('rend la Bandoulière « toujours armé d\'une dague » pour l\'Ambidextrie du Rogue', function () {
    ['heros' => $rogue] = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'rogue']);

    // Le Rogue tient une épée courte : sa capacité exige nommément une dague,
    // donc sans bandoulière elle ne part pas.
    $equipement = app(App\Partie\Equipement::class);
    $equipement->equiper($rogue, materielAuSac($rogue->id, 'Épée courte'));

    expect($equipement->compteCommeArme($rogue->fresh(), 'Dague'))->toBeFalse();

    materielAuSac($rogue->id, 'Bandoulière');

    expect($equipement->compteCommeArme($rogue->fresh(), 'Dague'))->toBeTrue();
});

it('donne aussi à la Bandoulière le désamorçage de la trousse', function () {
    ['heros' => $rogue] = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'rogue']);

    expect(app(App\Partie\MoteurPieges::class)->peutDesamorcer($rogue))->toBeFalse();

    // « Counts as a Tool Kit for disarming traps » : elle reste au SAC, elle ne
    // s'équipe pas — et `peutDesamorcer` balaie tout l'inventaire.
    materielAuSac($rogue->id, 'Bandoulière');

    expect(app(App\Partie\MoteurPieges::class)->peutDesamorcer($rogue->fresh()))->toBeTrue();
});
