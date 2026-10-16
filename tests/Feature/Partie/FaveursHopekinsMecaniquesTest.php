<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\GroupeMercenaire;
use App\Models\Inventaire;
use App\Models\Mercenaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;
use App\Partie\FaveursHopekins;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Les QUATRE faveurs MÉCANIQUES de Hopekins Rest (livret G1504 p. 23, René 2026-10-15) :
 *   - Mercenary Guild : « Recruit a Mercenary without paying their cost » ;
 *   - Dewdrop Inn     : « Raise your hero's max Body Points by 2 » ;
 *   - Witch's Hut     : « Gain 1 Potion of Healing » (cumulable) ;
 *   - Arcane Library  : « an additional set of spells that you are eligible to cast »
 *                       (« only for spellcasting heroes »).
 * Le héros et la faveur restent tirés au hasard (au dé injecté). Chaque faveur est jouée par les
 * vraies routes : /moi, /etat, /mercenaires, /equipement, /pret, /faveurs/arcane-library, /choix.
 * Les cinq compétences sont dans `FaveursHopekinsTest`.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, MercenaireSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

// ---------------------------------------------------------------------------
// Aides — propres à ce fichier (un fichier de test ne doit pas dépendre des fonctions d'un autre).
// ---------------------------------------------------------------------------

/** Les chiffres d de dé qui font tirer l'INDICE `$indice` parmi `$n` à `OubliSorts::indiceAleatoire()`. */
function hopDes(int $indice, int $n): array
{
    $chiffres = max(1, (int) ceil(log(max($n, 2), 6)));
    $des = [];

    for ($i = $chiffres - 1; $i >= 0; $i--) {
        $des[] = intdiv($indice, 6 ** $i) % 6 + 1;
    }

    return $des;
}

/** Id de la dernière quête achevée — ce que `terminerQuete()` passe à l'attribution. */
function hopQuete($groupe): int
{
    return (int) $groupe->quetes()->where('etat', 'terminee')->orderByDesc('id')->value('id');
}

/**
 * Force le tirage de fin de quête sur `$cle` (et le héros de rang `$rangHeros` parmi les éligibles)
 * en servant au moteur les dés qui y mènent — le tirage est au dé injecté, donc rejouable.
 *
 * @return array<string, mixed>
 */
function hopTirer($groupe, string $cle, int $rangHeros = 0): array
{
    $heros = $groupe->personnages()->wherePivot('actif', true)->orderBy('personnages.id')->get();
    $candidats = app(FaveursHopekins::class)->candidatsDeTirage($heros);
    $cles = array_keys($candidats);
    $rang = array_search($cle, $cles, true);

    expect($rang)->not->toBeFalse(); // « $cle » doit être tirable parmi $cles

    $des = [
        ...(count($cles) > 1 ? hopDes((int) $rang, count($cles)) : []),
        ...($candidats[$cle]->count() > 1 ? hopDes($rangHeros, $candidats[$cle]->count()) : []),
    ];

    desFiges([...$des, ...array_fill(0, 12, 4)]);

    $resultat = app(FaveursHopekins::class)->attribuerFaveurDeFinDeQuete($groupe->fresh(), hopQuete($groupe));

    expect($resultat)->not->toBeNull()
        ->and($resultat['faveur'])->toBe($cle);

    return $resultat;
}

/** Donne à ce héros toutes les faveurs PERSISTANTES sauf celles de `$sauf` (les lignes directes, hors tirage). */
function hopToutesSauf(Personnage $heros, array $sauf = []): void
{
    foreach (array_diff(FaveursHopekins::TOUTES, FaveursHopekins::INSTANTANEES, $sauf) as $cle) {
        PersonnageFaveur::create(['personnage_id' => $heros->id, 'cle' => $cle]);
    }
}

/** Le héros tel que `/moi` le publie. */
function hopMoi(Personnage $heros): array
{
    $moi = test()->getJson('/api/moi')->assertOk()->json('joueur.personnages');

    return collect($moi)->firstWhere('id', $heros->id);
}

function hopFaveurPubliee(Personnage $heros, string $cle): ?array
{
    return collect(hopMoi($heros)['faveurs'])->firstWhere('cle', $cle);
}

/** Un groupe de hub, GARDIEN, dont l'offre du compagnon animal est close (pas le sujet ici). */
function hopGroupeGardien(string $classe = 'barbare'): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['compagnon_offert' => 'refuse']);
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => $classe]);
    rendreGardien($groupe);

    return compact('alice', 'groupe', 'heros');
}

/** Fin de quête GAGNÉE sans butin (le dé ne sert qu'au tirage de faveur). */
function hopVictoire($groupe): array
{
    $quete = $groupe->fresh()->queteCourante;
    $quete->gabarit->update(['structure' => [...(array) $quete->gabarit->structure, 'butin' => ['or_base' => 0]]]);

    return acheverLaQuete($groupe->fresh());
}

// ---------------------------------------------------------------------------
// VOCABULAIRE — registre testé dans les deux sens.
// ---------------------------------------------------------------------------

it('NEUF faveurs, un seul registre : TOUTES, LIBELLES et EFFETS se répondent, et la potion est la carte du livret', function () {
    expect(FaveursHopekins::TOUTES)->toHaveCount(9)
        ->and(array_keys(FaveursHopekins::LIBELLES))->toEqualCanonicalizing(FaveursHopekins::TOUTES)
        ->and(array_keys(FaveursHopekins::EFFETS))->toEqualCanonicalizing(FaveursHopekins::TOUTES)
        ->and(array_diff(FaveursHopekins::COMPETENCES, FaveursHopekins::TOUTES))->toBe([])
        ->and(array_diff(FaveursHopekins::INSTANTANEES, FaveursHopekins::TOUTES))->toBe([]);

    // « Gain 1 Potion of Healing » = la carte officielle du même nom (registre des cartes), au catalogue.
    $carte = collect(config('cartes.potions.cartes'))->firstWhere('carte', 'Potion of Healing');
    expect($carte['objet'])->toBe(FaveursHopekins::POTION_DE_LA_SORCIERE)
        ->and(Objet::where('nom', FaveursHopekins::POTION_DE_LA_SORCIERE)->value('categorie'))->toBe('consommable');
});

it('REGISTRE dans les deux sens : tout ce qui est déclaré et durable arrive en base, rien n\'y arrive hors déclaration', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien('magicien');

    foreach (array_diff(FaveursHopekins::TOUTES, FaveursHopekins::INSTANTANEES) as $cle) {
        hopTirer($groupe, $cle);
    }

    // Déclaré → en base (hors instantanées)…
    expect(PersonnageFaveur::where('personnage_id', $heros->id)->pluck('cle')->all())
        ->toEqualCanonicalizing(array_values(array_diff(FaveursHopekins::TOUTES, FaveursHopekins::INSTANTANEES)));

    // …et la potion, instantanée, n'écrit AUCUNE ligne : son état est le sac.
    hopTirer($groupe, FaveursHopekins::WITCHS_HUT);
    expect(PersonnageFaveur::whereNotIn('cle', FaveursHopekins::TOUTES)->count())->toBe(0)
        ->and(PersonnageFaveur::where('cle', FaveursHopekins::WITCHS_HUT)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// ÉLIGIBILITÉ — chaque carte dit qui peut la recevoir.
// ---------------------------------------------------------------------------

it('ÉLIGIBILITÉ : un barbare peut tout recevoir sauf la bibliothèque ; un magicien aussi cette dernière', function () {
    ['groupe' => $groupe] = hopGroupeGardien('barbare');
    $heros = $groupe->personnages()->get();

    $cles = array_keys(app(FaveursHopekins::class)->candidatsDeTirage($heros));
    expect($cles)->toBe(array_values(array_diff(FaveursHopekins::TOUTES, [FaveursHopekins::ARCANE_LIBRARY])));

    $groupe2 = creerGroupe('table-2');
    $mage = creerHeros(connecterJoueur('bob'), $groupe2, 'Aldric', 1, ['classe' => 'magicien']);
    expect(array_keys(app(FaveursHopekins::class)->candidatsDeTirage(collect([$mage]))))->toBe(FaveursHopekins::TOUTES);
});

it('ÉLIGIBILITÉ : « only for spellcasting heroes » — et seulement s\'il reste un répertoire à prendre', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $sorts = app(MoteurSorts::class);

    $mage = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);
    $barde = creerHeros($alice, $groupe, 'Lys', 2, ['classe' => 'barde']);
    $elfe = creerHeros($alice, $groupe, 'Elwen', 3, ['classe' => 'elfe']);
    $sorts->attacherRepertoireClasse($barde, 'barde');
    $sorts->attacherElement($elfe, 'eau');

    // Le magicien (aucun sort) : les quatre écoles et les trois optionnels.
    expect($sorts->repertoiresSupplementaires($mage))->toBe(['feu', 'eau', 'terre', 'air', 'protection', 'detection', 'tenebres'])
        // Le Barde n'a pas les ÉCOLES (il a son répertoire de classe) : les trois optionnels, ouverts aux cinq lanceurs.
        ->and($sorts->repertoiresSupplementaires($barde))->toBe(['protection', 'detection', 'tenebres'])
        // L'Elfe : tout sauf ce qu'il connaît déjà.
        ->and($sorts->repertoiresSupplementaires($elfe))->toBe(['feu', 'terre', 'air', 'protection', 'detection', 'tenebres']);

    // Un non-lanceur n'a rien à prendre.
    $barbare = creerHeros($alice, $groupe, 'Grondin', 4);
    expect($sorts->repertoiresSupplementaires($barbare))->toBe([]);

    // Un magicien qui connaît déjà les sept : la carte n'a plus rien à lui donner, elle ne lui est pas tirée.
    foreach ([...MoteurSorts::ELEMENTS, ...MoteurSorts::REPERTOIRES_OPTIONNELS] as $element) {
        $sorts->attacherElement($mage, $element);
    }
    $candidats = app(FaveursHopekins::class)->candidatsDeTirage(collect([$mage->fresh()]));
    expect($sorts->repertoiresSupplementaires($mage->fresh()))->toBe([])
        ->and($candidats)->not->toHaveKey(FaveursHopekins::ARCANE_LIBRARY);
});

it('ÉLIGIBILITÉ : les compétences sont UNE par groupe ; la guilde, l\'auberge et la bibliothèque UNE par héros ; la potion, toujours', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $a = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);
    $b = creerHeros($alice, $groupe, 'Brynn', 2, ['classe' => 'magicien']);

    foreach ([FaveursHopekins::DEADEYE, FaveursHopekins::MERCENARY_GUILD, FaveursHopekins::DEWDROP_INN, FaveursHopekins::ARCANE_LIBRARY] as $cle) {
        PersonnageFaveur::create(['personnage_id' => $a->id, 'cle' => $cle]);
    }

    $candidats = app(FaveursHopekins::class)->candidatsDeTirage(collect([$a, $b]));

    // « (1 available) » : Deadeye est pris, personne ne peut l'avoir.
    expect($candidats)->not->toHaveKey(FaveursHopekins::DEADEYE);

    // Sans cette mention : le héros qui la détient n'en reçoit pas une seconde, l'autre oui.
    foreach ([FaveursHopekins::MERCENARY_GUILD, FaveursHopekins::DEWDROP_INN, FaveursHopekins::ARCANE_LIBRARY] as $cle) {
        expect($candidats[$cle]->pluck('id')->all())->toBe([$b->id]);
    }

    // Cumulable : tout le monde, toujours.
    expect($candidats[FaveursHopekins::WITCHS_HUT]->pluck('id')->all())->toBe([$a->id, $b->id]);
});

it('ÉLIGIBILITÉ : sans la Potion de guérison au catalogue, le Witch\'s Hut n\'est pas tirable (retrait écrit, pas un trou)', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    Objet::where('nom', FaveursHopekins::POTION_DE_LA_SORCIERE)->delete();

    expect(app(FaveursHopekins::class)->candidatsDeTirage(collect([$heros])))->not->toHaveKey(FaveursHopekins::WITCHS_HUT);
});

it('TIRAGE au dé injecté : le héros comme la faveur se tirent avec les dés du moteur, donc rejouables', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['compagnon_offert' => 'refuse']);
    $a = creerHeros($alice, $groupe, 'Aldric', 1);
    $b = creerHeros($alice, $groupe, 'Brynn', 2);
    rendreGardien($groupe);

    $r = hopTirer($groupe, FaveursHopekins::PEACEKEEPER, rangHeros: 1);

    expect($r['personnage_id'])->toBe($b->id)
        ->and(PersonnageFaveur::where('personnage_id', $b->id)->pluck('cle')->all())->toBe([FaveursHopekins::PEACEKEEPER])
        ->and(PersonnageFaveur::where('personnage_id', $a->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// DEWDROP INN — « Raise your hero's max Body Points by 2 »
// ---------------------------------------------------------------------------

it('DEWDROP INN : +2 PV de Body maximum (et courants), la place du sac suit, annoncé au hub — par le point de passage des plafonds', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    $avant = hopMoi($heros);

    $r = hopTirer($groupe, FaveursHopekins::DEWDROP_INN);

    $apres = hopMoi($heros);
    expect($apres['pv_body_max'])->toBe($avant['pv_body_max'] + 2)
        ->and($apres['pv_body'])->toBe($avant['pv_body'] + 2)
        // La capacité du sac est PV max ÷ 2 : dérivée, pas ajoutée — elle gagne une place d'office.
        ->and($apres['equipement']['capacite'])->toBe($avant['equipement']['capacite'] + 1)
        ->and(collect($apres['faveurs'])->pluck('cle')->all())->toBe([FaveursHopekins::DEWDROP_INN]);

    // Annoncé : le journal, le hub (nom + effet + ce qui a changé), la fiche.
    expect($r['detail'])->toContain((string) ($avant['pv_body_max'] + 2).' PV de Body maximum');
    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.faveur_hopekins');
    expect($hub['faveur'])->toBe('dewdrop_inn')
        ->and($hub['faveur_effet'])->toBe(FaveursHopekins::EFFETS[FaveursHopekins::DEWDROP_INN])
        ->and($hub['detail'])->toBe($r['detail']);
});

it('DEWDROP INN : le +2 survit à un talisman posé puis retiré, et la quête démarre au nouveau maximum', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    hopTirer($groupe, FaveursHopekins::DEWDROP_INN);
    $base = hopMoi($heros)['pv_body_max'];

    $amulette = Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => Objet::where('nom', 'Amulette du Nord')->firstOrFail()->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    $this->postJson('/api/groupes/table-1/equipement', ['personnage_id' => $heros->id, 'inventaire_id' => $amulette->id])->assertOk();
    expect(hopMoi($heros)['pv_body_max'])->toBe($base + 2); // le talisman : +2 de plus.

    $this->deleteJson('/api/groupes/table-1/equipement', ['personnage_id' => $heros->id, 'inventaire_id' => $amulette->id])->assertOk();
    expect(hopMoi($heros)['pv_body_max'])->toBe($base); // retiré : on retombe sur le +2 de l'auberge, pas sur 8.

    // En quête (route réelle) : la figure est au maximum de son nouveau plafond.
    oublierQuetesFictivesDeGardien($groupe);
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $fiche = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites'))->firstWhere('type', 'heros');
    expect($fiche['pv_body_max'])->toBe($base)->and($fiche['pv_body'])->toBe($base);
});

it('DEWDROP INN : une seule fois par héros — la ligne unique n\'ajoute jamais un second +2', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    hopTirer($groupe, FaveursHopekins::DEWDROP_INN);
    $apres = $heros->fresh()->pv_body_max;

    expect(app(FaveursHopekins::class)->candidatsDeTirage(collect([$heros->fresh()])))->not->toHaveKey(FaveursHopekins::DEWDROP_INN)
        ->and(FaveursHopekins::bonusBodyMax($heros))->toBe(2)
        ->and((int) $heros->fresh()->pv_body_max)->toBe((int) $apres);
});

// ---------------------------------------------------------------------------
// WITCH'S HUT — « Gain 1 Potion of Healing » (cumulable)
// ---------------------------------------------------------------------------

it('WITCH\'S HUT : une Potion de guérison dans le sac à chaque fois — cumulable, sans ligne de faveur, annoncée', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    hopToutesSauf($heros, [FaveursHopekins::WITCHS_HUT]); // la potion reste la seule faveur tirable

    $r = hopTirer($groupe, FaveursHopekins::WITCHS_HUT);
    $potions = fn () => collect(hopMoi($heros)['consommables'])->firstWhere('nom', FaveursHopekins::POTION_DE_LA_SORCIERE);

    expect($potions()['quantite'])->toBe(1)
        ->and($r['detail'])->toBe('Albrecht reçoit une Potion de guérison.');

    // « Can be gained multiple times » : tirable encore, et la quantité monte.
    hopTirer($groupe, FaveursHopekins::WITCHS_HUT);
    expect($potions()['quantite'])->toBe(2)
        ->and(PersonnageFaveur::where('cle', FaveursHopekins::WITCHS_HUT)->count())->toBe(0);

    // Hors capacité du sac (un consommable ne compte jamais).
    $moi = hopMoi($heros);
    expect($moi['equipement']['occupation'])->toBe(0);

    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.faveur_hopekins');
    expect($hub['faveur_libelle'])->toBe("Witch's Hut")->and($hub['faveur_effet'])->toBe(FaveursHopekins::EFFETS[FaveursHopekins::WITCHS_HUT]);
});

it('WITCH\'S HUT : la potion reçue se BOIT en quête, par le menu — elle soigne vraiment', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    rendreGardien($ctx['groupe']);
    hopToutesSauf($ctx['heros'], [FaveursHopekins::WITCHS_HUT]);
    hopTirer($ctx['groupe'], FaveursHopekins::WITCHS_HUT);

    $ctx['heros']->update(['pv_body' => 2, 'pv_body_max' => 8]);
    $ligne = collect(hopMoi($ctx['heros'])['consommables'])->firstWhere('nom', 'Potion de guérison');

    desFiges([4, ...array_fill(0, 40, 4)]);
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne['inventaire_id']}", 'cible_id' => $ctx['heros']->id, 'cible_type' => 'heros'],
    ])->assertAccepted();

    expect((int) $ctx['heros']->fresh()->pv_body)->toBe(6)
        ->and(Inventaire::where('personnage_id', $ctx['heros']->id)->where('objet_id', Objet::where('nom', 'Potion de guérison')->value('id'))->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// MERCENARY GUILD — « Recruit a Mercenary without paying their cost »
// ---------------------------------------------------------------------------

/** La décision de recrutement publiée au hub pour ce héros et ce mercenaire. */
function hopDecision(Personnage $heros, string $nomMercenaire): array
{
    $mercenaire = Mercenaire::where('nom', $nomMercenaire)->firstOrFail();
    $offres = test()->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.recrutement.offres');

    return collect(collect($offres)->firstWhere('mercenaire_id', $mercenaire->id)['decisions'])->firstWhere('personnage_id', $heros->id);
}

it('MERCENARY GUILD : le recrutement est publié GRATUIT, se prend par la route de recrutement sans toucher à la bourse, et le droit est consommé', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    hopToutesSauf($heros, [FaveursHopekins::MERCENARY_GUILD]);
    $groupe->update(['or' => 0]);
    hopTirer($groupe, FaveursHopekins::MERCENARY_GUILD);

    // Annoncé au porteur : la fiche dit qu'un recrutement est offert.
    expect(hopFaveurPubliee($heros, FaveursHopekins::MERCENARY_GUILD)['recrutement_offert'])->toBeTrue();

    // La DÉCISION publiée : prix 0, offert par la faveur, recrutable avec une bourse vide. Un animal n'est pas « un mercenaire ».
    $fauchard = hopDecision($heros, 'Fauchard');
    expect($fauchard)->toMatchArray(['prix' => 0, 'prix_catalogue' => 75, 'rabais_po' => 75, 'offert_par_faveur' => true, 'recrutable' => true]);
    $loup = hopDecision($heros, 'Loup');
    expect($loup['offert_par_faveur'])->toBeFalse()->and($loup['recrutable'])->toBeFalse();

    $reponse = $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => Mercenaire::where('nom', 'Fauchard')->value('id'), 'personnage_id' => $heros->id,
    ])->assertStatus(201);

    $recrue = GroupeMercenaire::findOrFail($reponse->json('recrue.id'));
    expect($reponse->json('recrue.offert_par_faveur'))->toBeTrue()
        ->and($reponse->json('or'))->toBe(0)
        ->and((int) $groupe->fresh()->or)->toBe(0)
        ->and((int) $recrue->recruteur_personnage_id)->toBe($heros->id)
        // L'entretien reste dû : ce n'est pas le compagnon animal « at no cost ».
        ->and($recrue->offert)->toBeFalse();

    // EN BASE : la faveur retient l'allié engagé à son titre. Le droit est consommé.
    expect((int) PersonnageFaveur::where('personnage_id', $heros->id)->where('cle', FaveursHopekins::MERCENARY_GUILD)->value('parametre'))->toBe((int) $recrue->id)
        ->and(hopFaveurPubliee($heros, FaveursHopekins::MERCENARY_GUILD)['recrutement_offert'])->toBeFalse();

    // Le suivant se paie plein tarif (et la bourse vide le refuse), à prix catalogue publié.
    expect(hopDecision($heros, 'Fauchard'))->toMatchArray(['prix' => 75, 'offert_par_faveur' => false, 'recrutable' => false]);
    $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => Mercenaire::where('nom', 'Fauchard')->value('id'), 'personnage_id' => $heros->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['mercenaire_id']);
});

it('MERCENARY GUILD : seul le porteur recrute gratuitement — son compagnon de table paie plein tarif', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    $autre = creerHeros($alice, $groupe, 'Brynn', 2);
    PersonnageFaveur::create(['personnage_id' => $heros->id, 'cle' => FaveursHopekins::MERCENARY_GUILD]);
    $groupe->update(['or' => 0]);

    expect(hopDecision($heros, 'Éclaireur')['prix'])->toBe(0)
        ->and(hopDecision($autre, 'Éclaireur'))->toMatchArray(['prix' => 50, 'offert_par_faveur' => false, 'recrutable' => false]);

    $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => Mercenaire::where('nom', 'Éclaireur')->value('id'), 'personnage_id' => $autre->id,
    ])->assertStatus(422);
});

it('MERCENARY GUILD : plafond de quatre par héros, et la Potion de charme n\'est pas consommée par un recrutement offert', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    PersonnageFaveur::create(['personnage_id' => $heros->id, 'cle' => FaveursHopekins::MERCENARY_GUILD]);
    Personnage::whereKey($heros->id)->update(['recrutements_a_rabais' => 3, 'rabais_recrutement_po' => 25]);
    $groupe->update(['or' => 0]);

    $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => Mercenaire::where('nom', 'Fauchard')->value('id'), 'personnage_id' => $heros->id,
    ])->assertStatus(201);
    expect((int) $heros->fresh()->recrutements_a_rabais)->toBe(3); // la potion attend le recrutement PAYANT suivant.

    // Quatre engagés : le plafond du Gardien joue aussi pour l'offert.
    GroupeMercenaire::where('groupe_id', $groupe->id)->delete();
    foreach (range(1, 4) as $_) {
        GroupeMercenaire::create([
            'groupe_id' => $groupe->id, 'mercenaire_id' => Mercenaire::where('nom', 'Fauchard')->value('id'),
            'recruteur_personnage_id' => $heros->id, 'pv_body' => 2, 'etat' => 'actif',
        ]);
    }
    $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => Mercenaire::where('nom', 'Éclaireur')->value('id'), 'personnage_id' => $heros->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);
});

it('MERCENARY GUILD : l\'entretien reste dû ; l\'allié offert parti faute d\'or rouvre le droit, et un nouveau est offert', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    PersonnageFaveur::create(['personnage_id' => $heros->id, 'cle' => FaveursHopekins::MERCENARY_GUILD]);
    $groupe->update(['or' => 0]);
    $merc = fn (string $nom) => ['mercenaire_id' => Mercenaire::where('nom', $nom)->value('id'), 'personnage_id' => $heros->id];

    $this->postJson('/api/groupes/table-1/mercenaires', $merc('Fauchard'))->assertStatus(201);

    // Fin de quête avec 10 po : l'allié offert PAIE son entretien comme les autres.
    $groupe->update(['or' => 10]);
    $entretien = app(FaveursHopekins::class)->reglerEntretien($groupe->fresh(), hopQuete($groupe));
    expect($entretien['cout_total'])->toBe(10)
        ->and(collect($entretien['payes'])->pluck('nom')->all())->toBe(['Fauchard'])
        ->and(hopDecision($heros, 'Fauchard')['offert_par_faveur'])->toBeFalse(); // toujours en poste : le droit reste consommé.

    // Fin de quête à sec : il s'en va…
    $groupe->update(['or' => 0]);
    $entretien = app(FaveursHopekins::class)->reglerEntretien($groupe->fresh(), hopQuete($groupe));
    expect(collect($entretien['partis'])->pluck('nom')->all())->toBe(['Fauchard']);

    // …le droit se rouvre : un autre mercenaire gratuit, au choix.
    expect(hopDecision($heros, 'Estafier'))->toMatchArray(['prix' => 0, 'offert_par_faveur' => true, 'recrutable' => true]);
    $this->postJson('/api/groupes/table-1/mercenaires', $merc('Estafier'))->assertStatus(201);
    expect((int) $groupe->fresh()->or)->toBe(0);
});

it('MERCENARY GUILD : tombé en quête (purgé en fin de quête gagnée), le mercenaire offert est remplacé gratuitement', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    rendreGardien($ctx['groupe']);
    PersonnageFaveur::create(['personnage_id' => $ctx['heros']->id, 'cle' => FaveursHopekins::MERCENARY_GUILD]);
    $recrue = GroupeMercenaire::create([
        'groupe_id' => $ctx['groupe']->id, 'mercenaire_id' => Mercenaire::where('nom', 'Fauchard')->value('id'),
        'recruteur_personnage_id' => $ctx['heros']->id, 'pv_body' => 2, 'etat' => 'vaincu',
    ]);
    PersonnageFaveur::where('personnage_id', $ctx['heros']->id)->update(['parametre' => (string) $recrue->id]);

    // Vaincu : l'allié ne sert plus, et la fin de quête le purge (comme tout mercenaire mort).
    hopToutesSauf($ctx['heros'], [FaveursHopekins::MERCENARY_GUILD]);
    Objet::where('nom', FaveursHopekins::POTION_DE_LA_SORCIERE)->delete(); // aucune faveur tirable : rien ne vient troubler ce test
    hopVictoire($ctx['groupe']);

    expect(GroupeMercenaire::whereKey($recrue->id)->exists())->toBeFalse()
        ->and(FaveursHopekins::mercenaireGratuitDisponible($ctx['heros']->fresh(), (int) $ctx['groupe']->id))->toBeTrue()
        ->and(hopDecision($ctx['heros'], 'Fauchard')['prix'])->toBe(0);
});

it('MERCENARY GUILD : un recrutement tiré en FIN DE QUÊTE est annoncé et prêt à l\'emploi au hub', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    rendreGardien($ctx['groupe']);
    hopToutesSauf($ctx['heros'], [FaveursHopekins::MERCENARY_GUILD]);
    Objet::where('nom', FaveursHopekins::POTION_DE_LA_SORCIERE)->delete();

    $resultat = hopVictoire($ctx['groupe']);

    expect($resultat['faveur_hopekins']['faveur'])->toBe('mercenary_guild');
    // Les quêtes fictives du statut de Gardien sont NÉES APRÈS la vraie (ids croissants) : on les retire pour
    // que la vraie redevienne la dernière achevée — la seule dont le hub relit l'annonce — puis on rend Gardien.
    oublierQuetesFictivesDeGardien($ctx['groupe']);
    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.faveur_hopekins');
    expect($hub['faveur_libelle'])->toBe('Mercenary Guild')
        ->and($hub['faveur_effet'])->toBe(FaveursHopekins::EFFETS[FaveursHopekins::MERCENARY_GUILD])
        ->and($hub['detail'])->toContain('Albrecht peut engager gratuitement');
    rendreGardien($ctx['groupe']);
    expect(hopDecision($ctx['heros'], 'Fauchard')['offert_par_faveur'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// ARCANE LIBRARY — « an additional set of spells that you are eligible to cast »
// ---------------------------------------------------------------------------

/** Un magicien au hub (feu, eau, terre) qui vient de recevoir la bibliothèque. */
function hopMageAvecBibliotheque(string $classe = 'magicien'): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['compagnon_offert' => 'refuse']);
    $heros = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => $classe]);

    if ($classe === 'magicien') {
        foreach (['feu', 'eau', 'terre'] as $element) {
            app(MoteurSorts::class)->attacherElement($heros, $element);
        }
    }

    PersonnageFaveur::create(['personnage_id' => $heros->id, 'cle' => FaveursHopekins::ARCANE_LIBRARY]);

    return compact('alice', 'groupe', 'heros');
}

it('ARCANE LIBRARY : /moi publie la LISTE BLANCHE — ce que le lanceur peut prendre en plus, avec les noms des sorts', function () {
    ['heros' => $heros] = hopMageAvecBibliotheque();

    $faveur = hopFaveurPubliee($heros, FaveursHopekins::ARCANE_LIBRARY);

    expect($faveur['repertoire'])->toBeNull()
        ->and(collect($faveur['choix'])->pluck('element')->all())->toBe(['air', 'protection', 'detection', 'tenebres'])
        ->and(collect($faveur['choix'])->firstWhere('element', 'protection')['sorts'])
        ->toEqualCanonicalizing(['Mur de Pierre', 'Invisibilité', 'Désapprentissage']);
});

it('ARCANE LIBRARY : le joueur choisit au hub, les trois sorts arrivent en base, le choix est définitif — et la quête les offre', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = hopMageAvecBibliotheque();
    $avant = $heros->sorts()->count();

    $this->putJson('/api/groupes/table-1/faveurs/arcane-library', ['personnage_id' => $heros->id, 'element' => 'protection'])
        ->assertOk()
        ->assertJsonPath('element', 'protection')
        ->assertJsonPath('faveurs.0.repertoire', 'protection');

    // EN BASE : le répertoire choisi, et les trois sorts de plus (jamais un remplacement : « additional »).
    expect(PersonnageFaveur::where('personnage_id', $heros->id)->value('parametre'))->toBe('protection')
        ->and($heros->sorts()->count())->toBe($avant + 3)
        ->and($heros->sorts()->where('element', 'feu')->count())->toBe(3);

    // La fiche : le répertoire pris, plus aucun choix à faire.
    $faveur = hopFaveurPubliee($heros, FaveursHopekins::ARCANE_LIBRARY);
    expect($faveur['repertoire'])->toBe('protection')->and($faveur['choix'])->toBeNull();
    expect(collect(hopMoi($heros)['sorts'])->pluck('nom')->all())->toContain('Mur de Pierre', 'Invisibilité');

    // Définitif : « when you acquire ».
    $this->putJson('/api/groupes/table-1/faveurs/arcane-library', ['personnage_id' => $heros->id, 'element' => 'detection'])
        ->assertStatus(422)->assertJsonValidationErrors(['element']);

    // Et le sort est LANÇABLE : la quête (route réelle) l'offre au menu du lanceur.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $sorts = collect(collect($menu['options'])->firstWhere('id', 'lancer_sort')['parametres']['sorts'])->pluck('nom')->all();
    expect(collect($sorts)->contains(fn ($n) => str_starts_with($n, 'Invisibilité') || str_starts_with($n, 'Mur de Pierre')))->toBeTrue();
});

it('ARCANE LIBRARY : la liste blanche est revalidée — répertoire déjà connu, école interdite à la classe, inconnu, héros sans la faveur, hors hub', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = hopMageAvecBibliotheque();
    $put = fn (int $id, string $element) => $this->putJson('/api/groupes/table-1/faveurs/arcane-library', ['personnage_id' => $id, 'element' => $element]);

    // Déjà connu : la reprendre ne donnerait rien.
    $put($heros->id, 'feu')->assertStatus(422)->assertJsonValidationErrors(['element']);
    // Hors catalogue des répertoires, ou réservé à une autre voie (elfique : 3 sorts parmi 8, autre écran).
    $put($heros->id, 'barde')->assertStatus(422)->assertJsonValidationErrors(['element']);
    $put($heros->id, 'elfique')->assertStatus(422)->assertJsonValidationErrors(['element']);
    $put($heros->id, 'ombre_fantome')->assertStatus(422)->assertJsonValidationErrors(['element']);

    // Un héros sans la faveur (même joueur).
    $autre = creerHeros($alice, $groupe, 'Brynn', 2, ['classe' => 'magicien']);
    $put($autre->id, 'air')->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);

    // Le héros d'un autre joueur.
    $bob = \App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $etranger = creerHeros($bob, $groupe, 'Corin', 3, ['classe' => 'magicien']);
    PersonnageFaveur::create(['personnage_id' => $etranger->id, 'cle' => FaveursHopekins::ARCANE_LIBRARY]);
    $put($etranger->id, 'air')->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);

    // Rien n'a bougé en base.
    expect(PersonnageFaveur::where('personnage_id', $heros->id)->value('parametre'))->toBeNull();

    // Pas en quête : le choix est une affaire du hub, et `/moi` n'offre alors plus de liste.
    $groupe->update(['phase' => 'quete']);
    $put($heros->id, 'air')->assertStatus(422)->assertJsonValidationErrors(['phase']);
    expect(hopFaveurPubliee($heros, FaveursHopekins::ARCANE_LIBRARY)['choix'])->toBeNull();
});

it('ARCANE LIBRARY : les règles des lanceurs tiennent — un Barde ne prend que les optionnels, jamais une école élémentaire', function () {
    ['heros' => $heros] = hopMageAvecBibliotheque('barde');
    app(MoteurSorts::class)->attacherRepertoireClasse($heros, 'barde');

    expect(collect(hopFaveurPubliee($heros, FaveursHopekins::ARCANE_LIBRARY)['choix'])->pluck('element')->all())
        ->toBe(['protection', 'detection', 'tenebres']);

    $this->putJson('/api/groupes/table-1/faveurs/arcane-library', ['personnage_id' => $heros->id, 'element' => 'feu'])
        ->assertStatus(422)->assertJsonValidationErrors(['element']);
    $this->putJson('/api/groupes/table-1/faveurs/arcane-library', ['personnage_id' => $heros->id, 'element' => 'tenebres'])->assertOk();

    // Le répertoire de classe est intact, le nouveau s'y ajoute.
    expect($heros->sorts()->pluck('element')->unique()->sort()->values()->all())->toBe(['barde', 'tenebres']);
});

it('ARCANE LIBRARY : « Prêt ! » est refusé au porteur tant qu\'il n\'a pas choisi — un seul geste le lève, et un non-lanceur n\'est pas bloqué', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = hopMageAvecBibliotheque();

    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $heros->id, 'pret' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['pret'])
        ->assertJsonPath('errors.pret.0', fn (string $m) => str_contains($m, 'Arcane Library'));

    // Se retirer des prêts n'est jamais bloqué.
    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $heros->id, 'pret' => false])->assertOk();

    $this->putJson('/api/groupes/table-1/faveurs/arcane-library', ['personnage_id' => $heros->id, 'element' => 'air'])->assertOk();
    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $heros->id, 'pret' => true])->assertOk();

    // Un non-lanceur à qui la ligne aurait été donnée (héritage, données) : aucun choix possible, aucun blocage.
    $barbare = creerHeros($alice, $groupe, 'Grondin', 2);
    PersonnageFaveur::create(['personnage_id' => $barbare->id, 'cle' => FaveursHopekins::ARCANE_LIBRARY]);
    expect(hopFaveurPubliee($barbare, FaveursHopekins::ARCANE_LIBRARY)['choix'])->toBeNull();
    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $barbare->id, 'pret' => true])->assertOk();
});

it('ARCANE LIBRARY : tirée en FIN DE QUÊTE, annoncée au hub avec ce qu\'il reste à faire', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    rendreGardien($ctx['groupe']);
    hopToutesSauf($ctx['heros'], [FaveursHopekins::ARCANE_LIBRARY]);
    Objet::where('nom', FaveursHopekins::POTION_DE_LA_SORCIERE)->delete();

    $resultat = hopVictoire($ctx['groupe']);

    expect($resultat['faveur_hopekins']['faveur'])->toBe('arcane_library');
    oublierQuetesFictivesDeGardien($ctx['groupe']); // la vraie quête redevient la dernière achevée
    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.faveur_hopekins');
    expect($hub['faveur_libelle'])->toBe('Arcane Library')
        ->and($hub['detail'])->toContain('choisira un répertoire de sorts supplémentaire');

    // La liste est là, au hub, pour son porteur.
    expect(hopFaveurPubliee($ctx['heros'], FaveursHopekins::ARCANE_LIBRARY)['choix'])->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// FICHE — nom + effet de chacune, en quête (table) comme au hub (manette)
// ---------------------------------------------------------------------------

it('FICHE : chaque faveur persistante publie son nom et son effet, en quête comme au hub', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    foreach ([FaveursHopekins::MERCENARY_GUILD, FaveursHopekins::DEWDROP_INN, FaveursHopekins::ARCANE_LIBRARY] as $cle) {
        PersonnageFaveur::create(['personnage_id' => $ctx['heros']->id, 'cle' => $cle]);
    }

    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $fiche = collect($etat['entites'])->firstWhere('type', 'heros')['faveurs'];

    foreach ([FaveursHopekins::MERCENARY_GUILD, FaveursHopekins::DEWDROP_INN, FaveursHopekins::ARCANE_LIBRARY] as $cle) {
        $entree = collect($fiche)->firstWhere('cle', $cle);
        expect($entree['libelle'])->toBe(FaveursHopekins::LIBELLES[$cle])
            ->and($entree['effet'])->toBe(FaveursHopekins::EFFETS[$cle]);
    }

    // En quête, le choix n'est PAS ouvert (hub seulement) : la table n'en reçoit pas la liste.
    expect(collect($fiche)->firstWhere('cle', FaveursHopekins::ARCANE_LIBRARY)['choix'])->toBeNull();
});
