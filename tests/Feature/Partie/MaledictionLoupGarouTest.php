<?php

declare(strict_types=1);

use App\Engine\MotsClesSortDread as Mot;
use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\JournalCombat;
use App\Partie\MaledictionLoupGarou;
use App\Partie\MoteurPotions;
use App\Partie\MoteurSorts;
use App\Partie\OrdreDuTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/*
 * LA MALÉDICTION DU LOUP-GAROU — *The Mage of the Mirror* (F7539 p. 10-11) : le sort de Dread *Werewolf's Curse*
 * (carte © 2023 : un dé rouge, le 6 annule), le jet de deux dés rouges à chaque début de tour, la forme de loup qui
 * joue au tour de Zargon, le retour, et le remède (Potion d'aconit, Potion de restauration supérieure).
 *
 * Chaque mécanique est JOUÉE par les vraies routes de la manette (`POST /api/groupes/table-1/choix`) : la phase des
 * monstres se déclenche comme en partie, par le « Terminer le tour » d'un héros. Les dés sont fixés (`desFiges()`) :
 * l'ordre de consommation est celui du moteur — le dé du sort, puis les deux dés du jet, puis les dés de la morsure
 * (attaque, puis défense), puis le jet suivant.
 *
 * Un monstre INERTE (actif, non révélé) maintient la phase des monstres sans jamais jouer ni consommer de dé :
 * c'est le moyen de tester le tour de Zargon sans lui donner d'autre dé à lancer que ceux qu'on veut voir.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

// ------------------------------------------------------------------
// Helpers (préfixés : les fichiers de test partagent l'espace des fonctions)
// ------------------------------------------------------------------

/** Dés servis à la suite, puis des boucliers blancs (4) : rien d'autre ne se joue au-delà. */
function loupDes(array $valeurs): void
{
    desFiges([...$valeurs, ...array_fill(0, 300, 4)]);
}

/** Pose la malédiction comme le ferait le sort de Dread (même source que `MoteurDread::poserConditionHeros()`). */
function loupMaudire(Personnage $heros): void
{
    $heros->conditions()->attach(Condition::where('nom', 'Malédiction du loup-garou')->firstOrFail()->id, [
        'duree' => 0, 'source' => 'sort_dread:Malédiction du loup-garou',
    ]);
}

/** Le héros est-il sous la condition ? */
function loupA(Personnage $heros, string $nom): bool
{
    return $heros->fresh()->conditions()->where('nom', $nom)->exists();
}

/** Les actions du tour des monstres d'une réponse (où remontent les jets et les gestes du loup). */
function loupActions(array $reponse): array
{
    return array_values((array) data_get($reponse, 'resultat.tour_monstres.actions', []));
}

/** La première action de ce type dans la liste, ou `null`. */
function loupAction(array $actions, string $type): ?array
{
    return collect($actions)->firstWhere('type', $type);
}

/**
 * Deux héros côte à côte (Albrecht puis Brunhild), une quête démarrée, un monstre INERTE. Le joueur d'Albrecht est alice,
 * celui de Brunhild bob.
 *
 * @return array<string, mixed>
 */
function loupDeuxHeros(): array
{
    $alice = connecterJoueur('alice');
    $bob = connecterJoueur('bob');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    $brunhild = creerHeros($bob, $groupe, 'Brunhild', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => false]);

    $etatAlbrecht = $quete->etatsPersonnages()->where('personnage_id', $albrecht->id)->firstOrFail();
    $etatBrunhild = $quete->etatsPersonnages()->where('personnage_id', $brunhild->id)->firstOrFail();
    $voisine = caseAdjacenteLibre($quete, (int) $etatAlbrecht->position_x, (int) $etatAlbrecht->position_y);
    $etatBrunhild->update(['position_x' => $voisine['x'], 'position_y' => $voisine['y']]);

    return compact('alice', 'bob', 'groupe', 'quete', 'albrecht', 'brunhild', 'etatAlbrecht', 'etatBrunhild');
}

/**
 * Un héros SEUL, une quête démarrée, un monstre inerte — le groupe n'a qu'un tour à jouer.
 *
 * @return array<string, mixed>
 */
function loupUnHeros(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => false]);

    return compact('alice', 'groupe', 'quete', 'albrecht');
}

/** Terminer le tour, comme la manette : la réponse entière. */
function loupAttendre(App\Auth\JoueurAuthentifiable $joueur): array
{
    return test()->actingAs($joueur, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->json();
}

// ------------------------------------------------------------------
// 1. Le sort : un Sorcier du Dread pose la malédiction, un 6 au dé rouge l'épargne
// ------------------------------------------------------------------

it('le sort de Dread : un 6 au dé rouge laisse le héros sans effet, et le sort est publié comme les autres', function () {
    $ctx = demarrerQueteAvecMonstre('Archimage elfe', ['classe' => 'barbare']);

    // Sinestra lance la carte : résolution ordinaire de la famille (`lancerSortDread()`), sur ce héros.
    loupDes([6]);
    $payload = app(App\Partie\MoteurDread::class)->lancerSortSurHeros(
        $ctx['groupe'], $ctx['quete'], $ctx['instance'], 'Malédiction du loup-garou', $ctx['etatHeros'],
    );

    expect($payload['type'])->toBe('sort_dread')
        ->and($payload['sort'])->toBe('Malédiction du loup-garou')
        ->and($payload['resultats'][0]['de_malediction'])->toBe(6)
        ->and($payload['resultats'][0]['effet_applique'])->toBeFalse()
        ->and(loupA($ctx['heros'], 'Malédiction du loup-garou'))->toBeFalse();
});

it('le sort de Dread : un autre résultat que 6 pose la malédiction, durable et sans compteur', function () {
    $ctx = demarrerQueteAvecMonstre('Archimage elfe', ['classe' => 'barbare']);

    loupDes([3]);
    $payload = app(App\Partie\MoteurDread::class)->lancerSortSurHeros(
        $ctx['groupe'], $ctx['quete'], $ctx['instance'], 'Malédiction du loup-garou', $ctx['etatHeros'],
    );

    expect($payload['resultats'][0]['de_malediction'])->toBe(3)
        ->and($payload['resultats'][0]['effet_applique'])->toBeTrue()
        ->and(loupA($ctx['heros'], 'Malédiction du loup-garou'))->toBeTrue();

    // Durable : sans compteur, et portée par le pivot, donc par le snapshot.
    $pivot = $ctx['heros']->fresh()->conditions()->where('nom', 'Malédiction du loup-garou')->first()->pivot;
    expect((int) $pivot->duree)->toBe(0);
});

it('le sort de Dread porté est au catalogue, dans le répertoire de Sinestra, et son dé est un dé rouge', function () {
    $sort = App\Models\SortDread::where('nom', 'Malédiction du loup-garou')->firstOrFail();

    expect($sort->effet['resistance'])->toBe(Mot::RESISTANCE_UN_DE_ROUGE_SIX)
        ->and($sort->effet['condition_appliquee'])->toBe('Malédiction du loup-garou')
        ->and($sort->palier)->toBe('sous_boss')
        ->and(config('archetypes_lanceurs.archimage_elfe.sorts'))->toContain('Malédiction du loup-garou');
});

// ------------------------------------------------------------------
// 2. Le jet de début de tour : deux dés rouges, 10 à 12 transforment
// ------------------------------------------------------------------

it('le héros maudit lance deux dés rouges : 2 à 9, il reste humain et joue son tour', function () {
    $ctx = loupDeuxHeros();
    loupMaudire($ctx['albrecht']);

    loupAttendre($ctx['alice']);
    loupDes([4, 5]); // 9 : le seuil de 10 n'est pas atteint
    $reponse = loupAttendre($ctx['bob']);

    $jet = loupAction(loupActions($reponse), 'malediction_jet');

    expect($jet)->not->toBeNull()
        ->and($jet['total'])->toBe(9)
        ->and($jet['transforme'])->toBeFalse()
        ->and(loupA($ctx['albrecht'], 'Forme de loup'))->toBeFalse()
        ->and(loupA($ctx['albrecht'], 'Malédiction du loup-garou'))->toBeTrue();
});

it('le héros maudit qui fait 10 à 12 se transforme : son tour est perdu, et le loup n\'a pas encore joué', function () {
    $ctx = loupDeuxHeros();
    loupMaudire($ctx['albrecht']);

    // Albrecht finit son tour ; Brunhild termine le round : la phase de Zargon (monstre inerte), puis le round suivant
    // s'ouvre et le jet d'Albrecht tombe à 11.
    loupAttendre($ctx['alice']);
    loupDes([5, 6]);
    $reponse = loupAttendre($ctx['bob']);

    $jet = loupAction(loupActions($reponse), 'malediction_jet');

    expect($jet)->not->toBeNull()
        ->and($jet['transforme'])->toBeTrue()
        ->and(loupA($ctx['albrecht'], 'Forme de loup'))->toBeTrue()
        // La forme est une figurine inerte pendant le tour des héros : aucun geste du loup n'est encore joué.
        ->and(loupAction(loupActions($reponse), 'loup_attaque'))->toBeNull()
        ->and(loupAction(loupActions($reponse), 'loup_reprend_forme'))->toBeNull();

    // Le tour d'Albrecht ne s'ouvre pas : il n'a plus de créneau, donc plus de menu.
    $etat = EtatPersonnageQuete::where('personnage_id', $ctx['albrecht']->id)->where('quete_id', $ctx['quete']->id)->firstOrFail();
    expect((bool) $etat->a_joue)->toBeTrue();
});

// ------------------------------------------------------------------
// 3. La forme de loup joue au tour de Zargon, par la couture HerosJoueParLeMj
// ------------------------------------------------------------------

it('au tour de Zargon le loup mord le héros à son contact avec 6 dés, puis reprend sa forme humaine', function () {
    $ctx = loupDeuxHeros();
    loupMaudire($ctx['albrecht']);

    loupAttendre($ctx['alice']);
    loupDes([5, 6]);
    loupAttendre($ctx['bob']);

    // Round suivant : Brunhild termine son tour. Le loup d'Albrecht joue (6 dés d'attaque de 1 = six crânes, puis
    // deux dés de défense de 6 = aucun bouclier), puis reprend forme ; le jet suivant (2 + 2) le laisse humain.
    loupDes([1, 1, 1, 1, 1, 1, 6, 6, 2, 2]);
    $reponse = loupAttendre($ctx['bob']);
    $actions = loupActions($reponse);

    $morsure = loupAction($actions, 'loup_attaque');

    expect($morsure)->not->toBeNull()
        ->and($morsure['personnage'])->toBe('Albrecht')
        ->and($morsure['cible']['nom'])->toBe('Brunhild')
        ->and($morsure['degats'])->toBe(6)
        ->and((int) $ctx['brunhild']->fresh()->pv_body)->toBe(2)
        ->and(loupAction($actions, 'loup_reprend_forme'))->not->toBeNull()
        ->and(loupA($ctx['albrecht'], 'Forme de loup'))->toBeFalse()
        ->and(loupA($ctx['albrecht'], 'Malédiction du loup-garou'))->toBeTrue();
});

it('la morsure est une attaque de MONSTRE (6 dés du Loup géant, source attaque_monstre), pas un sort du Dread', function () {
    $ctx = loupDeuxHeros();
    loupMaudire($ctx['albrecht']);

    loupAttendre($ctx['alice']);
    loupDes([5, 6]);
    loupAttendre($ctx['bob']);

    loupDes([1, 1, 1, 1, 1, 1, 6, 6, 2, 2]);
    $reponse = loupAttendre($ctx['bob']);

    // Six dés d'attaque : six touches (crânes), et le compte de dés que le fil publie.
    $morsure = loupAction(loupActions($reponse), 'loup_attaque');

    expect($morsure['touches'])->toBe(6)
        ->and($morsure['faces_attaque'])->toHaveCount(6);
});

it('le loup ne déclenche aucun piège sur sa route et ne frappe que des héros', function () {
    // La règle est dans la donnée du profil : un test qui la lit sans rien jouer se trompe de cible. On la vérifie
    // sur le profil lui-même, qui est ce que la couture lit.
    $profil = App\Partie\ControleMj::loupGarou();

    expect($profil->pieges)->toBeFalse()
        ->and($profil->cible)->toBe(App\Partie\ControleMj::CIBLE_HEROS)
        ->and($profil->forme)->toBe('Loup géant')
        ->and($profil->sorts)->toBeFalse()
        ->and($profil->diagonales)->toBeTrue();
});

// ------------------------------------------------------------------
// 4. Le groupe ne se fige jamais sur un loup
// ------------------------------------------------------------------

it('un héros SEUL transformé à l\'ouverture du round ne fige pas le groupe : son loup joue, reprend forme, et il rejoue', function () {
    $ctx = loupUnHeros();
    loupMaudire($ctx['albrecht']);

    loupDes([6, 6, 2, 3]);
    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->json();

    $actions = loupActions($reponse);

    expect(collect($actions)->where('type', 'malediction_jet')->pluck('transforme')->all())->toBe([true, false])
        ->and(loupAction($actions, 'loup_reprend_forme'))->not->toBeNull()
        ->and(loupA($ctx['albrecht'], 'Forme de loup'))->toBeFalse();

    // Le groupe n'est pas figé : Albrecht a un tour à jouer.
    expect(app(OrdreDuTour::class)->acteurActif($ctx['groupe']->fresh()))->not->toBeNull();
});

it('un donjon VIDÉ ne referme pas la quête tant que le loup n\'a pas repris sa forme', function () {
    $ctx = loupUnHeros();
    loupMaudire($ctx['albrecht']);

    // Plus aucun monstre actif : la branche « donjon nettoyé » de la fin de round.
    $ctx['quete']->instancesMonstres()->update(['etat' => 'vaincu']);

    loupDes([6, 6, 2, 3]);
    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->json();

    $actions = collect($reponse['resultat']['captifs_repris'] ?? [])
        ->merge(loupActions($reponse))
        ->values()->all();

    expect(loupAction($actions, 'loup_reprend_forme'))->not->toBeNull()
        ->and(loupA($ctx['albrecht'], 'Forme de loup'))->toBeFalse()
        ->and(app(OrdreDuTour::class)->acteurActif($ctx['groupe']->fresh()))->not->toBeNull();
});

// ------------------------------------------------------------------
// 5. Le remède : Potion d'aconit, Potion de restauration supérieure
// ------------------------------------------------------------------

/** Met une potion au sac de $porteur. */
function loupPotionAuSac(Personnage $porteur, string $nom): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $porteur->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
}

it('la Potion d\'aconit guérit la malédiction, et la potion est consommée', function () {
    $ctx = loupUnHeros();
    loupMaudire($ctx['albrecht']);
    $ligne = loupPotionAuSac($ctx['albrecht'], "Potion d'aconit");

    // Le menu l'offre au héros maudit, sur lui-même.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['albrecht']->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id));
    $option = collect($menu['menu']['options'] ?? [])->firstWhere('id', 'utiliser_objet');
    $entree = collect($option['parametres']['objets'] ?? [])->firstWhere('cle', "objet:{$ligne->id}");

    expect($entree)->not->toBeNull()
        ->and(collect($entree['cibles'] ?? [])->pluck('id')->all())->toContain($ctx['albrecht']->id);

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $ctx['albrecht']->id, 'cible_type' => 'heros'],
    ])->assertAccepted();

    expect(loupA($ctx['albrecht'], 'Malédiction du loup-garou'))->toBeFalse()
        ->and(Inventaire::find($ligne->id))->toBeNull();
});

it('la Potion de restauration supérieure guérit aussi la malédiction', function () {
    $ctx = loupUnHeros();
    loupMaudire($ctx['albrecht']);
    $ligne = loupPotionAuSac($ctx['albrecht'], 'Potion de restauration supérieure');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['albrecht']->id);

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $ctx['albrecht']->id, 'cible_type' => 'heros'],
    ])->assertAccepted();

    expect(loupA($ctx['albrecht'], 'Malédiction du loup-garou'))->toBeFalse();
});

it('un héros sous forme de loup ne boit rien : le résolveur refuse, comme le menu', function () {
    $ctx = loupUnHeros();
    loupMaudire($ctx['albrecht']);
    $ctx['albrecht']->conditions()->attach(Condition::where('nom', 'Forme de loup')->firstOrFail()->id, [
        'duree' => 0, 'source' => MaledictionLoupGarou::SOURCE_FORME,
    ]);
    $ligne = loupPotionAuSac($ctx['albrecht'], "Potion d'aconit");

    expect(fn () => app(MoteurPotions::class)->boire($ctx['albrecht']->fresh(), $ligne->fresh(), []))
        ->toThrow(ValidationException::class);
    expect(Inventaire::find($ligne->id))->not->toBeNull();
});

// ------------------------------------------------------------------
// 6. La fin de quête purge la forme, jamais la malédiction
// ------------------------------------------------------------------

it('une quête close au milieu du tour de Zargon ne porte pas la forme à la suivante, mais garde la malédiction', function () {
    $ctx = loupUnHeros();
    loupMaudire($ctx['albrecht']);
    $ctx['albrecht']->conditions()->attach(Condition::where('nom', 'Forme de loup')->firstOrFail()->id, [
        'duree' => 0, 'source' => MaledictionLoupGarou::SOURCE_FORME,
    ]);

    app(MoteurSorts::class)->reinitialiserQuete($ctx['groupe'], $ctx['albrecht']->fresh());

    expect(loupA($ctx['albrecht'], 'Forme de loup'))->toBeFalse()
        ->and(loupA($ctx['albrecht'], 'Malédiction du loup-garou'))->toBeTrue();
});

// ------------------------------------------------------------------
// 7. Le fil et la table disent chaque étape
// ------------------------------------------------------------------

it('chaque étape du loup a sa phrase du fil : le jet, la transformation, la morsure, le retour', function () {
    $jet = app(JournalCombat::class)->depuisResultat([
        'type' => 'malediction_jet', 'nom' => 'Albrecht', 'faces' => [5, 6], 'total' => 11, 'transforme' => true,
    ], 'Albrecht');
    $morsure = app(JournalCombat::class)->depuisResultat([
        'type' => 'loup_attaque', 'personnage' => 'Albrecht', 'cible' => ['nom' => 'Brunhild'], 'degats' => 6,
        'faces_attaque' => [1, 1, 1, 1, 1, 1], 'faces_defense' => [6, 6], 'touches' => 6, 'boucliers' => 0,
    ], 'Albrecht');
    $retour = app(JournalCombat::class)->depuisResultat([
        'type' => 'loup_reprend_forme', 'nom' => 'Albrecht',
    ], 'Albrecht');

    expect(collect($jet)->pluck('texte')->implode(' '))->toContain('se transforme en loup')
        ->and(collect($morsure)->pluck('texte')->implode(' '))->toContain('mord Brunhild')
        ->and(collect($retour)->pluck('texte')->implode(' '))->toContain('reprend forme humaine');
});

it('un monstre actif à son contact ne frappe PAS le loup : la forme est inattaquable pendant le tour de Zargon', function () {
    // Un Gobelin révélé et actif, au contact d'Albrecht — déjà sous forme de loup. Monstre et loup ne se frappent pas.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'barbare']);
    loupMaudire($ctx['heros']);
    $ctx['heros']->conditions()->attach(Condition::where('nom', 'Forme de loup')->firstOrFail()->id, [
        'duree' => 0, 'source' => MaledictionLoupGarou::SOURCE_FORME,
    ]);

    loupDes([]);
    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->json();

    $frappes = collect(loupActions($reponse))
        ->filter(fn (array $a) => ($a['type'] ?? null) === 'attaque_monstre' && ($a['cible']['personnage_id'] ?? null) === $ctx['heros']->id);

    expect($frappes)->toBeEmpty()
        ->and(loupA($ctx['heros'], 'Forme de loup'))->toBeFalse();
});

it('un compagnon ne peut pas tendre une potion à un loup : le menu ne le propose pas', function () {
    $ctx = loupDeuxHeros();
    loupMaudire($ctx['albrecht']);
    loupPotionAuSac($ctx['brunhild'], 'Potion de soin mineur');

    loupAttendre($ctx['alice']);
    loupDes([5, 6]);
    loupAttendre($ctx['bob']); // Albrecht se transforme : Brunhild a son tour, son loup voisin est sur la table.

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['bob']->id, (int) $ctx['brunhild']->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['bob']->id));
    $option = collect($menu['menu']['options'] ?? [])->firstWhere('id', 'utiliser_objet');
    $entree = collect($option['parametres']['objets'] ?? [])->first();

    expect($entree)->not->toBeNull()
        ->and(collect($entree['cibles'] ?? [])->pluck('id')->all())->not->toContain($ctx['albrecht']->id)
        ->and(collect($entree['cibles'] ?? [])->pluck('id')->all())->toContain($ctx['brunhild']->id);
});
