<?php

declare(strict_types=1);

use App\Engine\DureeEffet;
use App\Models\Objet;
use App\Models\Sort;
use App\Partie\EtatGroupe;
use App\Partie\JournalCombat;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Jobs\GenererMenu;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * « Un effet automatique que rien n'annonce est injouable » — verdict Morcar
 * 2026-10-09 §1 et §2.
 *
 * La cause racine n'était pas une liste de cas oubliés mais DEUX portes
 * silencieuses :
 *   1. `JournalCombat::ligneType()` finissait par `default => []` : tout type
 *      d'action sans arm était muet, et rien ne l'écrivait nulle part ;
 *   2. le REJEU (`journal_combat` de /etat) ne relisait que les événements
 *      `combat`, alors que soins, buffs, leviers, fouilles et déplacements de
 *      monstres sont journalisés `action`/`jet`.
 *
 * Ce fichier ferme les deux : un registre `JournalCombat::TYPES` testé DANS LES
 * DEUX SENS contre les sources, puis des cas JOUÉS sur la vraie route.
 */

// =====================================================================
// 1. LE REGISTRE — complétude, dans les deux sens
// =====================================================================

/** Les `'type' => '…'` de `app/Partie` qui ne sont PAS une action rendue au fil. */
const NON_ACTIONS_ANNONCES = [
    // Genre d'une FIGURE (acteur, cible, trajet animé) — jamais une action.
    'monstre', 'heros', 'personnage', 'allie', 'captif',
    // Verrou de porte (`verrou.type`), tuiles, cible de soin d'urgence, sous-objet de payload.
    'levier', 'competence', 'potion', 'potion_aide', 'artefact', 'objet_use', 'objet_libre',
    // Questions de VOTE (VoteGroupe) : l'écran de vote les rend, pas le fil.
    'retrait_joueur', 'choix_groupe',
    // Sous-payloads de SCÈNE (`MoteurDegats::infligerAMonstre()`), dits par leurs CLÉS dans
    // `ligneAction()` (`changement_phase`, `reaction_monstre`) — jamais comme une action.
    'changement_phase', 'reaction_monstre',
];

/** Types qui n'apparaissent pas en littéral : les options narratives que `resoudreNarratif()` recopie, et `equiper`/`desequiper` (ternaire). */
const TYPES_DYNAMIQUES_ANNONCES = ['attente', 'action', 'equiper', 'desequiper'];

function typesLitterauxDesSources(): array
{
    $exclus = ['MenuMoteur.php', 'EtatGroupe.php', 'AssembleurCarte.php', 'JournalCombat.php',
        'LignesEffetsAutomatiques.php', 'SceneDeTable.php'];
    $trouves = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Partie')));

    foreach ($it as $fichier) {
        if (! $fichier->isFile() || $fichier->getExtension() !== 'php' || in_array($fichier->getFilename(), $exclus, true)) {
            continue;
        }

        preg_match_all("/'type' => '([a-z_]+)'/", (string) file_get_contents($fichier->getPathname()), $m);

        foreach ($m[1] as $type) {
            $trouves[$type][] = $fichier->getFilename();
        }
    }

    return $trouves;
}

it('REGISTRE (sens 1) : tout type d\'action des sources est annoncé OU déclaré muet avec sa raison', function () {
    $inconnus = array_diff(
        array_keys(typesLitterauxDesSources()),
        array_keys(JournalCombat::TYPES),
        NON_ACTIONS_ANNONCES,
    );

    expect(array_values($inconnus))->toBe([], 'Types d\'action SANS phrase ni raison de se taire : ajoute-les à JournalCombat::TYPES (arm de ligneType() ou chaîne = raison).');
});

it('REGISTRE (sens 2) : tout type déclaré existe vraiment dans les sources', function () {
    $presents = array_keys(typesLitterauxDesSources());
    $morts = array_diff(array_keys(JournalCombat::TYPES), $presents, TYPES_DYNAMIQUES_ANNONCES);

    expect(array_values($morts))->toBe([], 'Types déclarés mais produits par AUCUN code : retire-les du registre.');
});

it('REGISTRE : chaque raison de se taire est une vraie phrase, jamais un drapeau', function () {
    foreach (JournalCombat::TYPES as $type => $statut) {
        if ($statut !== true) {
            expect(strlen($statut))->toBeGreaterThan(30, "« {$type} » est muet sans raison écrite.");
        }
    }
});

/** Un payload minimal et RÉALISTE par type annoncé : assez pour que la phrase se construise. */
function fixturesDesTypesAnnonces(): array
{
    $cible = ['personnage_id' => 3, 'nom' => 'Grom'];
    $monstre = ['instance_id' => 7, 'nom' => 'Gobelin'];
    $porte = ['x' => 4, 'y' => 5, 'cote' => 'e'];

    return [
        'attaque' => ['degats' => 1, 'cible' => $monstre],
        'attaque_balayee' => ['cibles' => 2, 'capacite' => 'Frappe balayée'],
        'style' => ['technique' => 'Poing de feu'],
        'rayon' => ['technique' => 'Esprit Ardent', 'touches' => []],
        'degat_differe' => ['degats' => 2, 'cible' => $monstre],
        'braise' => ['monstre' => 'Gobelin', 'degats' => 2],
        'deplacement' => ['terrain_jets' => [['terrain' => 'Brasier', 'degats' => 1]]],
        'jet' => ['libelle' => 'Forcer', 'succes' => true],
        'desamorcage' => ['succes' => true],
        'franchissement' => ['succes' => true],
        'sort' => ['sort' => ['nom' => 'Trait de Feu'], 'cible' => $monstre, 'degats' => 1],
        'parchemin' => ['sort' => ['nom' => 'Courage'], 'cible' => $cible, 'condition' => 'Renforcé', 'source' => 'sort:Courage'],
        'concentration' => ['sort_recupere' => ['nom' => 'Boule de Feu']],
        'sacrifice_sort' => ['sort_recupere' => ['nom' => 'Boule de Feu'], 'pv_body_paye' => 1, 'pv_body_apres' => 3],
        'soin_allie' => ['cible' => $cible, 'de' => 4, 'soin_pv_body' => 4, 'pv_body_apres' => 8, 'releve' => true],
        'liberer_entraves' => ['cible' => $cible, 'sur_soi' => false, 'lianes_detruites' => true],
        'relever' => ['libelle' => 'Albrecht relève Grom'],
        'regain_corps' => ['nom' => 'Grom'],
        'equiper' => ['objet' => 'Épée large'],
        'desequiper' => ['objet' => 'Épée large'],
        'usage_objet' => ['objet' => 'Fiole'],
        'echanger' => ['avec' => 'Grom', 'donne' => [['objet' => 'Fiole', 'quantite' => 1]], 'recu' => []],
        'jeter' => ['objet' => 'Fiole'],
        'attaque_mobilier' => ['mobilier' => 'Coffre', 'degats' => 1],
        'fouille_tresor' => ['issue' => 'tresor', 'or' => 20],
        'fouille_mobilier' => ['issue' => 'errant', 'monstre' => ['nom' => 'Gobelin']],
        'boire_mare' => ['soin' => 1],
        'detruire_par_action' => ['mobilier' => 'Cocon'],
        'actionner_levier' => ['jet' => ['issue' => 'echec', 'succes' => 0, 'difficulte' => 2]],
        'forcer_porte_pierre' => ['reussi' => true, 'cranes' => 2],
        'ouvrir_porte' => ['cause' => 'main', 'porte' => $porte],
        'oracle_salle' => ['salles_revelees' => [2]],
        'poussee' => ['cible' => $monstre, 'jet' => ['succes' => 2, 'difficulte' => 2], 'repoussee' => true],
        'briser_glace' => ['personnage' => 'Albrecht', 'x' => 1, 'y' => 1, 'brisee' => true],
        'detacher_rejetons' => ['cible' => $cible, 'retires' => 1, 'restants' => 1],
        's_ecarter_du_bloc' => ['vers' => ['x' => 2, 'y' => 3]],
        'retraite' => ['vote' => ['id' => 1]],
        'sortie' => ['vote' => ['id' => 1]],
        'jet_en_attente' => ['personnage' => 'Albrecht'],
        'reaction' => ['active' => true, 'sort' => 'Parade'],
        'objet_detruit' => ['objet' => 'Arc', 'personnage' => 'Sylvan'],
        'piege_declenche' => ['piege' => ['nom' => 'Fosse'], 'personnage' => ['nom' => 'Grom'], 'degats' => 1],
        'piege_ignore' => ['piege' => 'Fosse', 'personnage' => 'Grom'],
        'piege_esquive' => ['piege' => ['nom' => 'Lianes'], 'personnage' => ['nom' => 'Grom']],
        'piege_amorce' => ['piege' => ['nom' => 'Embrasement'], 'personnage' => ['nom' => 'Grom']],
        'piege_explosion' => ['piege' => ['nom' => 'Embrasement'], 'cibles' => []],
        'piege_teleporte' => ['piege' => ['nom' => 'Téléportation'], 'personnage' => ['nom' => 'Grom']],
        'piege_bourrasque' => ['piege' => ['nom' => 'Ouragan'], 'personnage' => ['nom' => 'Grom'], 'repousses' => []],
        'piege_desarme_embrasement' => ['piege' => ['nom' => 'Embrasement'], 'personnage' => ['nom' => 'Grom']],
        // La détection ne se dit que pour la Potion de vision : la fouille et l'Œil du mineur se disent ailleurs.
        'pieges_detectes' => ['methode' => 'clairvoyance', 'personnage' => 'Elwen', 'pieges' => [['x' => 1, 'y' => 1, 'nom' => 'Fosse']]],
        'portes_secretes_revelees' => ['methode' => 'clairvoyance', 'personnage' => 'Elwen', 'portes' => [['x' => 1, 'y' => 1]]],
        'attaque_allie' => ['allie' => 'Raptor', 'degats' => 1, 'cible' => $monstre],
        'deplacement_allie' => ['allie' => 'Raptor', 'vers' => ['x' => 1, 'y' => 1]],
        'attente_allie' => ['allie' => 'Raptor'],
        'captif_libere' => ['personnage' => 'Grom', 'allie' => 'Prospecteur', 'mode' => 'escorte'],
        'captif_repris' => ['personnage' => 'Grom', 'allie' => 'Prospecteur'],
        'sbire_controle' => ['maitre' => 'Albrecht', 'nom' => 'Squelette', 'attaque' => false, 'cible' => $monstre],
        'attaque_monstre' => ['monstre' => 'Gobelin', 'degats' => 1, 'cible' => $cible],
        'deplacement_monstre' => ['monstre' => 'Gobelin', 'chemin' => [['x' => 1, 'y' => 1]]],
        'repli_tireur' => ['monstre' => 'Archer', 'chemin' => [['x' => 1, 'y' => 1]]],
        'terrain_monstre' => ['monstre' => 'Gobelin', 'jets' => [['terrain' => 'Brasier', 'degats' => 1]]],
        'monstre_saute_tour' => ['monstre' => 'Gobelin'],
        'monstre_paralyse' => ['monstre' => 'Gobelin'],
        'monstre_endormi' => ['monstre' => 'Gobelin'],
        'monstre_reveille' => ['monstre' => 'Gobelin', 'des_rupture' => [6]],
        'monstre_enfume' => ['monstre' => 'Gobelin'],
        'monstre_enchaine' => ['monstre' => 'Sorcier'],
        'monstre_dans_l_ombre' => ['monstre' => 'Gobelin'],
        'etreinte_maintenue' => ['monstre' => 'Yéti', 'cible' => $cible],
        'vol_draconique' => ['monstre' => 'Dragon'],
        'frappe_de_zone' => ['monstre' => 'Ogre', 'resultats' => [['cible' => $cible, 'degats' => 1]]],
        'charge' => ['monstre' => 'Minotaure', 'cible' => $cible, 'degats' => 2],
        'capacite_dread' => ['monstre' => 'Gargouille', 'capacite' => 'invocation', 'invoques' => [['monstre' => 'Squelette']]],
        'spawn' => ['monstre' => 'Gruulob', 'engendre' => ['nom' => 'Spawnling']],
        'rejeton_accroche' => ['monstre' => 'Rejeton', 'cible' => $cible, 'jetons' => 1],
        'regeneration' => ['monstre' => 'Troll', 'pv_avant' => 3, 'pv_apres' => 4],
        'vol_objet' => ['monstre' => 'Gremlin', 'objet' => 'Fiole', 'cible' => $cible],
        'objet_perdu' => ['monstre' => 'Gremlin', 'objet' => 'Fiole', 'cible' => $cible],
        'embuscade' => ['monstre' => 'Dreadshifter'],
        'actions_composites' => ['monstre' => 'Troll', 'actions' => [['type' => 'regeneration', 'monstre' => 'Troll', 'pv_avant' => 1, 'pv_apres' => 2]]],
        'sort_dread' => ['sort' => 'Foudroiement', 'lanceur' => ['nom' => 'Maître des orages'], 'resultats' => [['cible' => $cible, 'degats' => 2]]],
        'sort_dread_annule' => ['sort' => 'Sommeil'],
        'rupture_sort_dread' => ['nom' => 'Grom', 'condition' => 'Endormi', 'rompu' => true],
        'effet_dread' => ['texte' => 'Le marteau se brise'],
        'effet_global_quete' => ['texte' => 'Tous les gobelins…'],
        'conditions_levees' => ['levees' => [['nom' => 'Grom', 'condition' => 'Aveuglé']]],
        'tour_perdu' => ['nom' => 'Grom'],
        'heros_endormi' => ['personnage' => 'Grom'],
        'possession_deplacement' => ['personnage' => 'Grom'],
        'commandement_sans_effet' => ['personnage' => 'Grom'],
        'commandement_sans_cible' => ['personnage' => 'Grom'],
        'commandement_deplacement' => ['personnage' => 'Grom', 'vers_allié' => 'Albrecht'],
        'commandement_attaque' => ['personnage' => 'Grom', 'cible' => $cible, 'degats' => 1],
        'ombre_decompte' => ['texte' => 'Le voile s\'amincit.'],
        'glace_dissipee' => ['monstre' => 'Gobelin', 'cases' => [['x' => 1, 'y' => 1]]],
        'faveur_hold_the_line' => ['personnage' => 'Grom', 'monstre' => 'Gobelin', 'touche' => true],
        'faveur_peacekeeper' => ['personnage' => 'Grom', 'monstre' => 'Gobelin'],
    ];
}

it('REGISTRE : chaque type ANNONCÉ rend au moins une ligne, chaque type MUET n\'en rend aucune', function () {
    $fixtures = fixturesDesTypesAnnonces();
    $fil = new JournalCombat;

    foreach (JournalCombat::TYPES as $type => $statut) {
        if ($statut === true) {
            expect(array_key_exists($type, $fixtures))->toBeTrue("Aucun payload de test pour le type annoncé « {$type} ».");
            $lignes = $fil->depuisResultat(['type' => $type] + $fixtures[$type], 'Albrecht');

            expect($lignes)->not->toBeEmpty("Le type « {$type} » est déclaré annoncé mais rend ZÉRO ligne.");

            // …et PAS la ligne de repli : elle existe pour qu'un oubli ne soit jamais muet,
            // pas pour passer pour une phrase écrite.
            foreach ($lignes as $ligne) {
                expect($ligne['texte'])->not->toContain('Un effet automatique vient de se produire', "« {$type} » n'a pas d'arm dans ligneType().");
            }
        } else {
            expect($fil->depuisResultat(['type' => $type], 'Albrecht'))
                ->toBeEmpty("Le type « {$type} » est déclaré muet mais rend une ligne.");
        }
    }

    // …et la table des cas de test ne garde pas de fantôme.
    expect(array_diff(array_keys($fixtures), array_keys(JournalCombat::TYPES)))->toBe([]);
});

it('un type INCONNU n\'est jamais le silence : une ligne de repli le dit', function () {
    $lignes = (new JournalCombat)->depuisResultat(['type' => 'effet_jamais_vu'], 'Albrecht');

    expect($lignes)->toHaveCount(1)
        ->and($lignes[0]['texte'])->toContain('effet_jamais_vu');
});

it('CLÉS DE RÉSULTAT : toute liste d\'actions que le résolveur range est parcourue par le fil', function () {
    $source = (string) file_get_contents(base_path('app/Partie/ResolveurTour.php'));
    preg_match_all("/\\\$(?:resultat|payload)\\['((?:tour_|coups_|captifs_|annonces_)[a-z_]+)'\\]\\s*=/", $source, $m);

    $connues = [...JournalCombat::PHASES, ...JournalCombat::LISTES_PLATES];

    expect(array_values(array_diff(array_unique($m[1]), $connues)))
        ->toBe([], 'Une liste d\'actions est rangée dans le résultat sans que JournalCombat::actionsDuTour() la parcoure.');
});

it('chaque liste du résultat est réellement parcourue (phases, listes plates, imbriquées)', function () {
    $fil = new JournalCombat;
    $monstre = ['type' => 'monstre_enfume', 'monstre' => 'Gobelin'];

    foreach ([...JournalCombat::PHASES, ] as $phase) {
        expect($fil->depuisResultat(['type' => 'attente', $phase => ['actions' => [$monstre]]], 'A'))->toHaveCount(1, $phase);
    }

    foreach (JournalCombat::LISTES_PLATES as $liste) {
        expect($fil->depuisResultat(['type' => 'attente', $liste => [$monstre]], 'A'))->toHaveCount(1, $liste);
    }

    // L'action que l'embusqué joue tout de suite, et les embuscades d'un déplacement.
    expect($fil->depuisResultat(['type' => 'embuscade', 'monstre' => 'Dreadshifter', 'action' => $monstre], 'A'))->toHaveCount(2)
        ->and($fil->depuisResultat(['type' => 'deplacement', 'embuscades' => [['type' => 'embuscade', 'monstre' => 'D', 'action' => $monstre]]], 'A'))->toHaveCount(2);
});

it('DureeEffet::libelle() dit chaque mot-clé de durée', function () {
    foreach (DureeEffet::toutes() as $motCle) {
        expect(DureeEffet::libelle($motCle))->toBeString("« {$motCle} » n'a pas de phrase.");
    }

    expect(DureeEffet::libelle(2))->toBe('pendant 2 tours')
        ->and(DureeEffet::libelle(['prochaine_attaque', 'plus_de_monstre_en_vue']))
        ->toBe('jusqu\'à sa prochaine attaque ou tant qu\'un monstre est en vue')
        ->and(DureeEffet::libelle(null))->toBeNull();
});


// =====================================================================
// 2. EN JEU — la vraie route, puis le rejeu d'un joueur qui arrive en retard
// =====================================================================

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class, \Database\Seeders\SortDreadSeeder::class]);
});

/** Les textes du fil EN DIRECT pour ce résultat. */
function annonceFilDirect(array $resultat, string $acteur = 'Albrecht'): array
{
    return collect(app(JournalCombat::class)->depuisResultat($resultat, $acteur))->pluck('texte')->all();
}

/** Les textes que verrait un joueur arrivé en retard (`journal_combat` de l'état). */
function annonceFilRejoue(array $ctx): array
{
    return collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['journal_combat'])->pluck('texte')->all();
}

/** Lance un sort de héros par la VRAIE route de la manette. */
function annonceLancer(array $ctx, string $nomSort, array $cible): \Illuminate\Testing\TestResponse
{
    $sort = Sort::where('nom', $nomSort)->firstOrFail();
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$sort->id}", ...$cible],
    ])->assertAccepted();
}

function annonceMagicien(string $element = 'eau'): array
{
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'magicien']);
    app(MoteurSorts::class)->attacherElement($ctx['heros'], $element);

    return $ctx;
}

it('EN JEU — un SOIN dit ce qu\'il a rendu, en direct ET au rejeu (il était journalisé `action`, donc absent)', function () {
    $ctx = annonceMagicien();
    $ctx['heros']->update(['pv_body' => 3]);

    $reponse = annonceLancer($ctx, 'Eau de Guérison', ['cible_id' => $ctx['heros']->id, 'cible_type' => 'heros']);
    $reponse->assertJsonPath('resultat.soin', 4)->assertJsonPath('resultat.releve', false);

    $direct = annonceFilDirect($reponse->json('resultat'));
    expect($direct)->toContain('Albrecht lance Eau de Guérison sur Albrecht : +4 PV de Body (7 PV)');

    // Le joueur qui se connecte après le sort doit lire la MÊME phrase.
    expect(annonceFilRejoue($ctx))->toContain('Albrecht lance Eau de Guérison sur Albrecht : +4 PV de Body (7 PV)');
});

it('EN JEU — un BUFF dit la condition posée ET quand elle prend fin (Voile de Brume)', function () {
    $ctx = annonceMagicien();

    $reponse = annonceLancer($ctx, 'Voile de Brume', ['cible_id' => $ctx['heros']->id, 'cible_type' => 'heros']);
    $payload = $reponse->json('resultat');

    expect($payload['condition'])->toBe('Vaporeux')
        ->and($payload['duree_texte'])->toBeString();

    $attendu = "Albrecht lance Voile de Brume — Vaporeux, {$payload['duree_texte']}";
    expect(annonceFilDirect($payload))->toContain($attendu)
        ->and(annonceFilRejoue($ctx))->toContain($attendu);
});

it('EN JEU — SOMMEIL rompu sur-le-champ : le payload ET le fil disent « endormi… puis libéré », pas « endormi »', function () {
    $ctx = annonceMagicien();
    $ctx['instance']->update(['pv_mind' => 2]);

    desFiges([2, 6]); // le second dé de rupture réveille aussitôt
    $reponse = annonceLancer($ctx, 'Sommeil', ['cible_id' => $ctx['instance']->id, 'cible_type' => 'monstre']);

    // Le contraire est le défaut du verdict : `effet_applique: true` + `condition: Endormi`
    // à côté d'un `rupture_immediate: true`.
    $reponse->assertJsonPath('resultat.rupture_immediate', true)
        ->assertJsonPath('resultat.effet_applique', false)
        ->assertJsonPath('resultat.condition', 'Endormi');

    $direct = annonceFilDirect($reponse->json('resultat'));
    expect(implode(' ¦ ', $direct))->toContain('subit Sommeil (Endormi)… puis s\'en libère aussitôt');
    expect(implode(' ¦ ', annonceFilRejoue($ctx)))->toContain('puis s\'en libère aussitôt');
    expect(app(MoteurSorts::class)->monstreA($ctx['instance']->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeFalse();
});

it('EN JEU — SOMMEIL qui tient : le monstre est dit endormi, et il l\'est', function () {
    $ctx = annonceMagicien();
    $ctx['instance']->update(['pv_mind' => 2]);

    desFiges([2, 3]);
    $reponse = annonceLancer($ctx, 'Sommeil', ['cible_id' => $ctx['instance']->id, 'cible_type' => 'monstre']);

    $reponse->assertJsonPath('resultat.effet_applique', true)->assertJsonPath('resultat.rupture_immediate', false);
    expect(implode(' ¦ ', annonceFilDirect($reponse->json('resultat'))))->toContain('s\'endort sous Sommeil');
    expect(app(MoteurSorts::class)->monstreA($ctx['instance']->fresh(), MoteurSorts::MONSTRE_ENDORMI))->toBeTrue();
});

it('EN JEU — BOULE DE FEU : « résiste » seulement si rien ne passe, et un SEUL jeu de dés dit la vérité', function () {
    $ctx = annonceMagicien('feu');
    $ctx['instance']->update(['pv_body' => 5, 'pv_body_max' => 5]);

    desFiges([5, 2]); // un 5 : 1 dégât annulé sur 2, 1 PV passe
    $reponse = annonceLancer($ctx, 'Boule de Feu', ['cible_id' => $ctx['instance']->id, 'cible_type' => 'monstre']);
    $reponse->assertJsonPath('resultat.degats', 1)->assertJsonPath('des.libelle_def', 'résiste en partie');

    // Les deux jeux de faces sont LE MÊME jet ; `defensive` n'est pas un jet, c'est l'ensemble des
    // faces qui comptent (5 ou 6) — le contrat le dit.
    expect($reponse->json('des.def'))->toBe($reponse->json('resultat.des_resistance'))
        ->and($reponse->json('des.defensive'))->toBe([5, 6]);
});

it('EN JEU — un monstre qui AVANCE et un monstre qui se RÉVEILLE sont dits (phase des monstres)', function () {
    $ctx = demarrerQueteAvecMonstre('Orque', ['classe' => 'barbare']);
    ouvrirToutesLesPortes($ctx['quete']);

    // L'orque loin : le plus éloigné des cases libres de la carte.
    $quete = $ctx['quete']->fresh()->load('carte');
    $hx = (int) $ctx['etatHeros']->position_x;
    $hy = (int) $ctx['etatHeros']->position_y;
    $loin = null;
    $max = -1;

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            $d = abs($x - $hx) + abs($y - $hy);

            if ($c === 's' && $d > $max && caseQueteLibre($quete, $x, $y)) {
                [$loin, $max] = [['x' => $x, 'y' => $y], $d];
            }
        }
    }

    $ctx['instance']->update(['position_x' => $loin['x'], 'position_y' => $loin['y'], 'pv_mind' => 1]);

    // Endormi + un 6 : il se réveille au début de SON tour et joue dans la foulée.
    app(MoteurSorts::class)->poserConditionMonstre($ctx['instance']->fresh(), MoteurSorts::MONSTRE_ENDORMI);
    desFiges([6, ...array_fill(0, 300, 4)]);

    $reponse = test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertAccepted();
    $actions = collect($reponse->json('resultat.tour_monstres.actions'));

    $types = $actions->pluck('type')->values()->all();
    $reveil = array_search('monstre_reveille', $types, true);
    $suite = array_search('deplacement_monstre', $types, true);

    expect($reveil)->not->toBeFalse('le réveil est RETOURNÉ (il n\'était que journalisé)')
        ->and($suite)->not->toBeFalse()
        ->and($reveil)->toBeLessThan($suite); // l'ordre où cela s'est produit

    $direct = implode(' ¦ ', annonceFilDirect($reponse->json('resultat')));
    expect($direct)->toContain('se réveille — le sort est rompu')->toContain('avance de');
    expect(implode(' ¦ ', annonceFilRejoue($ctx)))->toContain('avance de');
});

// ---- Les sorts du Maître des orages : un seul en-tête, le lanceur nommé, en direct et au rejeu

function annonceSorcier(string $sort): array
{
    $ctx = demarrerQueteAvecMonstre('Maître des orages');
    $ctx['instance']->monstre->update(['sorts_dread' => [$sort], 'archetype_lanceur' => null, 'capacites' => ['sorts_uniques']]);
    $ctx['instance']->update(['pv_body_max' => (int) $ctx['instance']->monstre->pv_body]);
    $ctx['instance']->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($ctx['instance'], $ctx['quete']);
    $ctx['instance']->refresh();

    return $ctx;
}

function annonceAligner(array $ctx, int $longueur = 8): void
{
    ouvrirToutesLesPortes($ctx['quete']);
    $carte = $ctx['quete']->carte;
    $grille = $carte->grille;
    $grille['mobilier'] = [];
    $carte->update(['grille' => $grille]);
    $ctx['quete']->refresh();
    $cases = $ctx['quete']->carte->grille['cases'];

    foreach ($cases as $y => $ligne) {
        for ($x = 0; $x + $longueur <= count($ligne); $x++) {
            if (count(array_filter(array_slice($ligne, $x, $longueur), fn ($c) => $c === 's')) === $longueur) {
                $ctx['instance']->update(['position_x' => $x, 'position_y' => $y]);
                $ctx['etatHeros']->update(['position_x' => $x + 1, 'position_y' => $y]);

                return;
            }
        }
    }

    throw new RuntimeException('Aucune rangée libre — scénario invalide.');
}

function annoncePhaseSorcier(array $ctx): array
{
    desFiges(array_fill(0, 300, 1));

    return test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat');
}

it('EN JEU — TOUS les sorts du Maître des orages portent le MÊME en-tête « <lanceur> — <sort> » (Foudroiement ET Tremblement de terre)', function (string $sort) {
    $ctx = annonceSorcier($sort);
    annonceAligner($ctx);
    $resultat = annoncePhaseSorcier($ctx);
    $sorcier = $ctx['instance']->fresh()->nomAffiche();

    $lance = collect($resultat['tour_monstres']['actions'])->firstWhere('sort', $sort);
    expect($lance['lanceur']['nom'])->toBe($sorcier);

    // En direct, l'acteur du fil est le HÉROS qui vient de jouer : l'en-tête ne doit pas le nommer.
    $direct = annonceFilDirect($resultat, 'Albrecht');
    expect($direct)->toContain("{$sorcier} — {$sort}");
    expect(implode(' ¦ ', $direct))->not->toContain("Albrecht — {$sort}");

    // Au rejeu l'acteur de l'événement EST le sorcier : même en-tête, quel que soit le sort.
    expect(annonceFilRejoue($ctx))->toContain("{$sorcier} — {$sort}");
})->with(['Foudroiement', 'Tremblement de terre']);

it('EN JEU — Ouragan (héros projeté) et Vent voleur (pièce arrachée) sont dits, et au rejeu', function () {
    // Ouragan
    $ctx = annonceSorcier('Ouragan');
    annonceAligner($ctx);
    $resultat = annoncePhaseSorcier($ctx);
    $dit = implode(' ¦ ', annonceFilDirect($resultat));
    expect($dit)->toContain('Ouragan balaie Albrecht sur')->toContain('case(s)')
        ->and(implode(' ¦ ', annonceFilRejoue($ctx)))->toContain('Ouragan balaie Albrecht sur');
});

it('EN JEU — Vent voleur dit la pièce ARRACHÉE (pas « rongée »)', function () {
    $ctx = annonceSorcier('Vent voleur');
    $ctx['heros']->inventaire()->create(['objet_id' => Objet::where('nom', 'Épée longue')->value('id'), 'emplacement' => 'arme_principale']);

    $resultat = annoncePhaseSorcier($ctx);
    $dit = implode(' ¦ ', annonceFilDirect($resultat));

    expect($dit)->toContain('Vent voleur arrache à Albrecht')->toContain('perdu — définitivement')
        ->and($dit)->not->toContain('ronge');
    expect(implode(' ¦ ', annonceFilRejoue($ctx)))->toContain('Vent voleur arrache à Albrecht');
});

// =====================================================================
// 3. LE REJEU ne dit pas deux fois ce qu'un payload parent porte déjà
// =====================================================================

it('REJEU — un piège journalisé à part ET imbriqué dans son déplacement n\'est dit qu\'une fois', function () {
    $piege = [
        'type' => 'piege_declenche', 'piege' => ['nom' => 'Fosse'], 'personnage' => ['id' => 1, 'nom' => 'Grom'],
        'degats' => 1, 'pv_body_apres' => 7, 'tombe' => false,
    ];
    $deplacement = ['type' => 'deplacement', 'pieges_declenches' => [$piege], 'vers' => ['x' => 2, 'y' => 2]];

    $lignes = (new JournalCombat)->depuisEvenements([[$piege, 'Grom'], [$deplacement, 'Grom']]);
    $textes = array_column($lignes, 'texte');

    expect(array_keys(array_filter($textes, fn ($t) => str_contains($t, 'Fosse se déclenche'))))->toHaveCount(1);
});

it('REJEU — deux coups STRICTEMENT identiques mais indépendants se disent tous les deux', function () {
    $coup = ['type' => 'attaque_monstre', 'monstre' => 'Gobelin', 'degats' => 0, 'touches' => 0, 'cible' => ['nom' => 'Grom']];

    $textes = array_column((new JournalCombat)->depuisEvenements([[$coup, 'Gobelin'], [$coup, 'Gobelin']]), 'texte');

    expect($textes)->toBe(['Gobelin manque Grom', 'Gobelin manque Grom']);
});

it('un effet qu\'aucune action ne retourne (objet volé perdu de vue) est annoncé par le tampon générique', function () {
    app(\App\Partie\TamponAnnonces::class)->ajouter([
        'type' => 'objet_perdu', 'monstre' => 'Gremlin des glaces', 'objet' => 'Fiole de soin', 'cible' => ['personnage_id' => 1, 'nom' => 'Grom'],
    ]);

    $resultat = ['type' => 'attente', 'annonces_automatiques' => app(\App\Partie\TamponAnnonces::class)->vider()];

    expect(annonceFilDirect($resultat))->toBe(['Fiole de soin de Grom est perdu pour de bon : Gremlin des glaces est sorti de la vue des héros']);
    expect(app(\App\Partie\TamponAnnonces::class)->vider())->toBe([]);
});
