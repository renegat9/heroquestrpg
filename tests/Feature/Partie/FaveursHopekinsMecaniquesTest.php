<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Inventaire;
use App\Models\Mercenaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;
use App\Partie\FaveursHopekins;
use App\Partie\MoteurSorts;
use App\Partie\RangementObjet;
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
 * Chaque faveur est CHOISIE au hub par son héros (René, 2026-10-16 : `PUT …/faveurs/lieu`), puis jouée par les
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

/** Id de la dernière quête achevée — le tour de hub qui s'ouvre après elle. */
function hopQuete($groupe): int
{
    return (int) $groupe->quetes()->where('etat', 'terminee')->orderByDesc('id')->value('id');
}

/**
 * Fait CHOISIR le lieu `$cle` au héros de rang `$rangHeros` (parmi les actifs, par id) par la VRAIE route, au hub.
 * Un seul choix par tour : si ce héros a déjà décidé à ce tour, une nouvelle quête gagnée rouvre le hub.
 *
 * @return array<string, mixed>
 */
function hopChoisir($groupe, string $cle, int $rangHeros = 0): array
{
    $heros = $groupe->personnages()->wherePivot('actif', true)->orderBy('personnages.id')->get()->values()[$rangHeros];
    $tour = FaveursHopekins::tourDeHub($groupe->fresh());

    if ($tour !== null && FaveursHopekins::visiteDecidee($heros, $tour)) {
        ouvrirTourDeHub($groupe);
    }

    return test()->putJson("/api/groupes/{$groupe->identifiant}/faveurs/lieu", [
        'personnage_id' => $heros->id,
        'lieu' => $cle,
    ])->assertOk()->json();
}

/** Les lieux que ce héros peut CHOISIR à ce tour (liste blanche de `FaveursHopekins::lieuxPour()`). */
function hopDisponibles(Groupe $groupe, Personnage $heros): array
{
    return collect(FaveursHopekins::lieuxPour($groupe, $heros))->where('disponible', true)->pluck('cle')->values()->all();
}

/** Donne à ce héros toutes les faveurs PERSISTANTES sauf celles de `$sauf` (les lignes directes, hors choix). */
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
    $groupe = jouerMorcar(creerGroupe());
    $groupe->update(['compagnon_offert' => 'refuse']);
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => $classe]);
    rendreGardien($groupe);

    return compact('alice', 'groupe', 'heros');
}

/** Fin de quête GAGNÉE sans butin. */
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

it('REGISTRE dans les deux sens : une faveur TENUE arrive en base, une seule à la fois ; rien n\'y arrive hors déclaration', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien('magicien');

    // Chaque faveur durable, choisie à son tour : chaque réception relâche la précédente (DURÉE du livret :
    // « A hero may only benefit from one boon at a time »). Le registre est donc lu un à un, pas cumulé.
    foreach (array_diff(FaveursHopekins::TOUTES, FaveursHopekins::INSTANTANEES) as $cle) {
        hopChoisir($groupe, $cle);

        expect(PersonnageFaveur::where('personnage_id', $heros->id)->pluck('cle')->all())->toBe([$cle]);
    }

    // …et la potion, instantanée, n'écrit AUCUNE ligne et ne relâche PAS la faveur tenue : son état est le sac.
    hopChoisir($groupe, FaveursHopekins::WITCHS_HUT);
    expect(PersonnageFaveur::whereNotIn('cle', FaveursHopekins::TOUTES)->count())->toBe(0)
        ->and(PersonnageFaveur::where('cle', FaveursHopekins::WITCHS_HUT)->count())->toBe(0)
        ->and(PersonnageFaveur::where('personnage_id', $heros->id)->pluck('cle')->all())
        ->toBe([FaveursHopekins::ARCANE_LIBRARY]);
});

// ---------------------------------------------------------------------------
// ÉLIGIBILITÉ — chaque carte dit qui peut la recevoir.
// ---------------------------------------------------------------------------

it('ÉLIGIBILITÉ : un barbare peut tout recevoir sauf la bibliothèque ; un magicien aussi cette dernière', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien('barbare');
    expect(hopDisponibles($groupe, $heros))->toBe(array_values(array_diff(FaveursHopekins::TOUTES, [FaveursHopekins::ARCANE_LIBRARY])));

    $groupe2 = creerGroupe('table-2');
    $mage = creerHeros(connecterJoueur('bob'), $groupe2, 'Aldric', 1, ['classe' => 'magicien']);
    expect(hopDisponibles($groupe2, $mage))->toBe(FaveursHopekins::TOUTES);
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

    // Un magicien qui connaît déjà les sept : la carte n'a plus rien à lui donner, elle ne lui est pas offerte.
    foreach ([...MoteurSorts::ELEMENTS, ...MoteurSorts::REPERTOIRES_OPTIONNELS] as $element) {
        $sorts->attacherElement($mage, $element);
    }
    expect($sorts->repertoiresSupplementaires($mage->fresh()))->toBe([])
        ->and(collect(FaveursHopekins::lieuxPour($groupe, $mage->fresh()))->firstWhere('cle', FaveursHopekins::ARCANE_LIBRARY))
        ->toMatchArray(['disponible' => false, 'motif' => 'Réservée aux lanceurs de sorts, à qui il reste un répertoire à prendre.']);
});

it('ÉLIGIBILITÉ : les compétences sont UNE par groupe ; la guilde, l\'auberge et la bibliothèque UNE par héros ; la potion, toujours', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $a = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);
    $b = creerHeros($alice, $groupe, 'Brynn', 2, ['classe' => 'magicien']);

    foreach ([FaveursHopekins::DEADEYE, FaveursHopekins::MERCENARY_GUILD, FaveursHopekins::DEWDROP_INN, FaveursHopekins::ARCANE_LIBRARY] as $cle) {
        PersonnageFaveur::create(['personnage_id' => $a->id, 'cle' => $cle]);
    }

    $pourA = collect(FaveursHopekins::lieuxPour($groupe->fresh(), $a->fresh()))->keyBy('cle');
    $pourB = collect(FaveursHopekins::lieuxPour($groupe->fresh(), $b->fresh()))->keyBy('cle');

    // « (1 available) » : Deadeye est tenue par Aldric, personne ne peut l'avoir — pas même Brynn.
    expect($pourB[FaveursHopekins::DEADEYE])->toMatchArray(['disponible' => false, 'motif' => 'Tenue par Aldric.']);

    // Sans cette mention : Aldric n'en reçoit pas une seconde ; Brynn, qui ne la tient pas, si.
    foreach ([FaveursHopekins::MERCENARY_GUILD, FaveursHopekins::DEWDROP_INN, FaveursHopekins::ARCANE_LIBRARY] as $cle) {
        expect($pourA[$cle])->toMatchArray(['disponible' => false, 'motif' => 'Vous la tenez déjà.'])
            ->and($pourB[$cle]['disponible'])->toBeTrue();
    }

    // Cumulable : tout le monde, toujours.
    expect($pourA[FaveursHopekins::WITCHS_HUT]['disponible'])->toBeTrue()
        ->and($pourB[FaveursHopekins::WITCHS_HUT]['disponible'])->toBeTrue();
});

it('ÉLIGIBILITÉ : sans la Potion de guérison au catalogue, le Witch\'s Hut n\'est pas offert (retrait écrit, pas un trou)', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    Objet::where('nom', FaveursHopekins::POTION_DE_LA_SORCIERE)->delete();

    expect(collect(FaveursHopekins::lieuxPour($groupe, $heros))->firstWhere('cle', FaveursHopekins::WITCHS_HUT))
        ->toMatchArray(['disponible' => false, 'motif' => 'La Potion de guérison n\'est pas au catalogue.']);
});

it('CHOIX : c\'est le héros que le JOUEUR désigne qui choisit — jamais un tirage, et chacun décide pour soi', function () {
    $alice = connecterJoueur('alice');
    $groupe = jouerMorcar(creerGroupe());
    $groupe->update(['compagnon_offert' => 'refuse']);
    $a = creerHeros($alice, $groupe, 'Aldric', 1);
    $b = creerHeros($alice, $groupe, 'Brynn', 2);
    rendreGardien($groupe);

    $r = hopChoisir($groupe, FaveursHopekins::PEACEKEEPER, rangHeros: 1);

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

    $r = hopChoisir($groupe, FaveursHopekins::DEWDROP_INN);

    $apres = hopMoi($heros);
    expect($apres['pv_body_max'])->toBe($avant['pv_body_max'] + 2)
        ->and($apres['pv_body'])->toBe($avant['pv_body'] + 2)
        // La capacité du sac est PV max ÷ 2 : dérivée, pas ajoutée — elle gagne une place d'office.
        ->and($apres['equipement']['capacite'])->toBe($avant['equipement']['capacite'] + 1)
        ->and(collect($apres['faveurs'])->pluck('cle')->all())->toBe([FaveursHopekins::DEWDROP_INN]);

    // Annoncé : le journal, le hub (nom + effet + ce qui a changé), la fiche.
    expect($r['detail'])->toContain((string) ($avant['pv_body_max'] + 2).' PV de Body maximum');
    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.hopekins');
    expect($hub['visites'][0])->toMatchArray(['lieu' => FaveursHopekins::DEWDROP_INN, 'libelle' => 'Dewdrop Inn'])
        ->and($hub['visites'][0]['effet'])->toBe(FaveursHopekins::EFFETS[FaveursHopekins::DEWDROP_INN])
        ->and($hub['visites'][0]['detail'])->toBe($r['detail']);
});

it('DEWDROP INN : le +2 survit à un talisman posé puis retiré, et la quête démarre au nouveau maximum', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    hopChoisir($groupe, FaveursHopekins::DEWDROP_INN);
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
    hopChoisir($groupe, FaveursHopekins::DEWDROP_INN);
    $apres = $heros->fresh()->pv_body_max;

    expect(collect(FaveursHopekins::lieuxPour($groupe, $heros->fresh()))->firstWhere('cle', FaveursHopekins::DEWDROP_INN)['disponible'])->toBeFalse()
        ->and(FaveursHopekins::bonusBodyMax($heros))->toBe(2)
        ->and((int) $heros->fresh()->pv_body_max)->toBe((int) $apres);
});

// ---------------------------------------------------------------------------
// WITCH'S HUT — « Gain 1 Potion of Healing » (cumulable)
// ---------------------------------------------------------------------------

it('WITCH\'S HUT : une Potion de guérison dans le sac à chaque fois — cumulable, sans ligne de faveur, annoncée', function () {
    ['groupe' => $groupe, 'heros' => $heros] = hopGroupeGardien();
    hopToutesSauf($heros, [FaveursHopekins::WITCHS_HUT]); // la potion reste la seule faveur tirable

    $r = hopChoisir($groupe, FaveursHopekins::WITCHS_HUT);
    $potions = fn () => collect(hopMoi($heros)['consommables'])->firstWhere('nom', FaveursHopekins::POTION_DE_LA_SORCIERE);

    expect($potions()['quantite'])->toBe(1)
        ->and($r['detail'])->toBe('Albrecht reçoit une Potion de guérison.');

    // « Can be gained multiple times » : tirable encore, et la quantité monte.
    hopChoisir($groupe, FaveursHopekins::WITCHS_HUT);
    expect($potions()['quantite'])->toBe(2)
        ->and(PersonnageFaveur::where('cle', FaveursHopekins::WITCHS_HUT)->count())->toBe(0);

    // Hors capacité du sac (un consommable ne compte jamais).
    $moi = hopMoi($heros);
    expect($moi['equipement']['occupation'])->toBe(0);

    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.hopekins');
    expect($hub['visites'][0])->toMatchArray(['lieu' => FaveursHopekins::WITCHS_HUT, 'libelle' => "Witch's Hut"])
        ->and($hub['visites'][0]['effet'])->toBe(FaveursHopekins::EFFETS[FaveursHopekins::WITCHS_HUT]);
});

it('WITCH\'S HUT : la potion reçue se BOIT en quête, par le menu — elle soigne vraiment', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    rendreGardien($ctx['groupe']);
    hopToutesSauf($ctx['heros'], [FaveursHopekins::WITCHS_HUT]);
    // Le choix du lieu se fait AU HUB, jamais en quête : la potion arrive ici comme le Witch's Hut la remet
    // (`RangementObjet::ranger()`), et c'est elle que ce test veut voir boire.
    RangementObjet::ranger(Objet::where('nom', FaveursHopekins::POTION_DE_LA_SORCIERE)->firstOrFail(), (int) $ctx['heros']->id, 1);

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
    hopChoisir($groupe, FaveursHopekins::MERCENARY_GUILD);

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
    Objet::where('nom', FaveursHopekins::POTION_DE_LA_SORCIERE)->delete(); // le hub ne tire plus rien : seul le recrutement tombé compte
    hopVictoire($ctx['groupe']);

    expect(GroupeMercenaire::whereKey($recrue->id)->exists())->toBeFalse()
        ->and(FaveursHopekins::mercenaireGratuitDisponible($ctx['heros']->fresh(), (int) $ctx['groupe']->id))->toBeTrue()
        ->and(hopDecision($ctx['heros'], 'Fauchard')['prix'])->toBe(0);
});

it('MERCENARY GUILD : le lieu choisi au hub après une quête gagnée est annoncé, et le recrutement est prêt à l\'emploi', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    jouerMorcar($ctx['groupe']);
    rendreGardien($ctx['groupe'], 1);
    hopVictoire($ctx['groupe']);

    // Au hub, Albrecht visite la guilde : c'est SON choix, pas un tirage.
    $r = hopChoisir($ctx['groupe'], FaveursHopekins::MERCENARY_GUILD);
    expect($r['detail'])->toContain('Albrecht peut engager gratuitement');

    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.hopekins');
    expect($hub['visites'][0])->toMatchArray(['personnage' => 'Albrecht', 'lieu' => FaveursHopekins::MERCENARY_GUILD, 'libelle' => 'Mercenary Guild'])
        ->and($hub['visites'][0]['detail'])->toContain('Albrecht peut engager gratuitement');
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

it('ARCANE LIBRARY : choisie au hub après une quête gagnée, annoncée avec ce qu\'il reste à faire', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    jouerMorcar($ctx['groupe']);
    rendreGardien($ctx['groupe'], 1);
    hopVictoire($ctx['groupe']);

    $r = hopChoisir($ctx['groupe'], FaveursHopekins::ARCANE_LIBRARY);
    expect($r['detail'])->toContain('choisira un répertoire de sorts supplémentaire');

    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.hopekins');
    expect($hub['visites'][0])->toMatchArray(['lieu' => FaveursHopekins::ARCANE_LIBRARY, 'libelle' => 'Arcane Library'])
        ->and($hub['visites'][0]['detail'])->toContain('choisira un répertoire de sorts supplémentaire');

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
