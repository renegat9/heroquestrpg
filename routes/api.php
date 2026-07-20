<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChoixController;
use App\Http\Controllers\Api\ClotureController;
use App\Http\Controllers\Api\CompetenceController;
use App\Http\Controllers\Api\EquipementController;
use App\Http\Controllers\Api\GroupeController;
use App\Http\Controllers\Api\GuideController;
use App\Http\Controllers\Api\MarcheController;
use App\Http\Controllers\Api\MercenaireController;
use App\Http\Controllers\Api\PotionController;
use App\Http\Controllers\Api\SauvegardeController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\VoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API du jeu (SPA Vue, même origine — voir resources/js/composables/useApi.js)
|--------------------------------------------------------------------------
| Auth en session (login simple, cadre LAN — doc 11 §11) : les middlewares
| de session/CSRF sont ajoutés au groupe api dans bootstrap/app.php.
| La boucle d'un tour (doc 11 §4) : POST choix → moteur → jobs IA → Reverb.
*/

Route::post('/connexion', [AuthController::class, 'connexion']);
Route::post('/inscription', [AuthController::class, 'inscription']);

// Guide / compendium — données de référence en lecture seule (bestiaire,
// talents, équipements, sorts, pièges), PUBLIC : la page /guide s'ouvre depuis
// l'accueil sans compte ni groupe.
Route::get('/guide', [GuideController::class, 'index']);

// Routes table (narrateur sans compte) — publiques, hors guard auth:joueur.
// La session Laravel (cookie) identifie la table active.
Route::post('/table', [TableController::class, 'ouvrir']);
Route::post('/table/ping', [TableController::class, 'ping']);
Route::post('/table/lecture-terminee', [TableController::class, 'lectureTerminee']);
Route::post('/table/quitter', [TableController::class, 'quitter']);

// Lectures accessibles au joueur membre OU à la session de table (contrat
// §Autorisations) — hors auth:joueur pour permettre l'accès table sans compte.
// L'écran de table (narrateur sans compte) s'en sert pour se resynchroniser
// après un rechargement ; sinon il prenait un 401. Les écritures de ces phases
// restent réservées aux joueurs (POST/PUT/DELETE ci-dessous).
Route::get('/groupes/{identifiant}/etat', [GroupeController::class, 'etat']);
Route::get('/groupes/{identifiant}/marche', [MarcheController::class, 'etat']);
Route::get('/groupes/{identifiant}/votes', [VoteController::class, 'actif']);
Route::get('/groupes/{identifiant}/cloture', [ClotureController::class, 'etat']);

// Ouvrir / annuler la clôture : le bouton « Clôturer » est sur l'écran de
// TABLE (narrateur sans compte). Hors auth:joueur — l'autorisation table-OU-
// membre est faite dans le contrôleur (rien n'est appliqué avant la
// confirmation de TOUS les joueurs, qui, elle, reste réservée aux joueurs).
Route::post('/groupes/{identifiant}/cloture', [ClotureController::class, 'ouvrir']);
Route::delete('/groupes/{identifiant}/cloture', [ClotureController::class, 'annuler']);

// Ouvrir / annuler le marché : même règle que la clôture — le bouton « Ouvrir
// le marché » est sur l'écran de TABLE (narrateur sans compte). Rien n'est
// appliqué avant la confirmation de TOUS les joueurs (auth:joueur ci-dessous).
Route::post('/groupes/{identifiant}/marche', [MarcheController::class, 'ouvrir']);
Route::delete('/groupes/{identifiant}/marche', [MarcheController::class, 'annuler']);

// Reprise après TPK : le bouton « Recharger la quête » (bandeau quête échouée)
// est lui aussi sur l'écran de TABLE — autorisation membre-OU-table dans le
// contrôleur. La liste des snapshots reste réservée aux joueurs.
Route::post('/groupes/{identifiant}/reprise', [SauvegardeController::class, 'reprendre']);

// Démarrer la quête suivante : le bouton « Lancer la quête » est sur l'écran de
// TABLE (narrateur sans compte) → autorisation membre-OU-table dans le
// contrôleur (l'auto-démarrage « tous prêts + narrateur actif » reste, lui, via
// POST /pret côté joueurs). Entièrement moteur (App\Partie\DemarreurQuete).
Route::post('/groupes/{identifiant}/quetes', [GroupeController::class, 'demarrerQuete']);

Route::middleware('auth:joueur')->group(function () {
    Route::post('/deconnexion', [AuthController::class, 'deconnexion']);
    Route::get('/moi', [AuthController::class, 'moi']);

    // Roster joueur : créer un perso libre (sans l'engager dans un groupe).
    Route::post('/personnages', [GroupeController::class, 'creerPersonnage']);
    // Portrait unique d'un héros (génération IA à la demande).
    Route::post('/personnages/{id}/portrait', [GroupeController::class, 'genererPortrait']);

    // Groupes / campagnes (création → dispatch du squelette en job).
    Route::post('/groupes', [GroupeController::class, 'creer']);
    Route::post('/groupes/{identifiant}/joueurs', [GroupeController::class, 'rejoindre']);

    // (Démarrer la quête — POST /quetes — est table-OU-membre, déclaré plus haut.)

    // Statut « prêt » : déclenche la quête si tous prêts + narrateur actif.
    Route::post('/groupes/{identifiant}/pret', [GroupeController::class, 'pret']);

    // Choix de menu : validation contre le dernier menu proposé + résolution
    // moteur (ResolveurTour), puis narration/menus en jobs.
    Route::post('/groupes/{identifiant}/choix', [ChoixController::class, 'choisir']);
    // Rattrapage du menu courant du joueur (reconnexion).
    Route::get('/groupes/{identifiant}/menu', [ChoixController::class, 'menu']);
    // Boire une potion : action GRATUITE, à tout moment (hors créneaux de tour).
    Route::post('/groupes/{identifiant}/potions', [PotionController::class, 'boire']);

    // Arbres de compétences (montée de niveau par jalons, contrat) :
    // catalogue + acquisition d'un nœud (points dérivés du niveau).
    Route::get('/competences', [CompetenceController::class, 'catalogue']);
    Route::post('/groupes/{identifiant}/competences', [CompetenceController::class, 'acquerir']);

    // Équipement (doc 01 §7) — au hub : équiper/déséquiper une pièce du sac,
    // ses deltas de combat s'appliquent aux colonnes du héros (Equipement).
    Route::post('/groupes/{identifiant}/equipement', [EquipementController::class, 'equiper']);
    Route::delete('/groupes/{identifiant}/equipement', [EquipementController::class, 'desequiper']);

    // Recrutement d'alliés au hub (3.5) — catalogue + bourse commune.
    Route::get('/mercenaires', [MercenaireController::class, 'catalogue']);
    Route::post('/groupes/{identifiant}/mercenaires', [MercenaireController::class, 'recruter']);

    // Phase marché (doc 04 §5 — au hub uniquement) : paniers en cache,
    // application atomique quand TOUS les joueurs ont confirmé.
    // (ouvrir / annuler : routes table-OU-membre plus haut.)
    Route::put('/groupes/{identifiant}/marche/panier', [MarcheController::class, 'panier']);
    Route::post('/groupes/{identifiant}/marche/confirmation', [MarcheController::class, 'confirmer']);

    // Snapshots (contrat, doc 12 §4) : liste des points de reprise du moteur
    // (debut_quete / nouveau_tour). (POST reprise : route membre-OU-table plus haut.)
    Route::get('/groupes/{identifiant}/snapshots', [SauvegardeController::class, 'index']);

    // Votes de groupe (doc 05 §5) : un seul vote actif par groupe ; au hub,
    // le départ est libre avec sa part du pot commun.
    Route::post('/groupes/{identifiant}/votes', [VoteController::class, 'lancer']);
    Route::post('/groupes/{identifiant}/votes/bulletin', [VoteController::class, 'bulletin']);
    Route::post('/groupes/{identifiant}/depart', [VoteController::class, 'depart']);

    // Clôture de campagne (doc 05 §6) : fenêtre en cache, finalisation en
    // job (or réparti, équipements, résumé, historique, purge complète)
    // quand TOUS les joueurs ont confirmé.
    Route::put('/groupes/{identifiant}/cloture/repartition', [ClotureController::class, 'repartition']);
    Route::post('/groupes/{identifiant}/cloture/confirmation', [ClotureController::class, 'confirmer']);
});
