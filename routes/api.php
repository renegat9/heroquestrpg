<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChoixController;
use App\Http\Controllers\Api\ClotureController;
use App\Http\Controllers\Api\CompetenceController;
use App\Http\Controllers\Api\GroupeController;
use App\Http\Controllers\Api\MarcheController;
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

// Routes table (narrateur sans compte) — publiques, hors guard auth:joueur.
// La session Laravel (cookie) identifie la table active.
Route::post('/table', [TableController::class, 'ouvrir']);
Route::post('/table/ping', [TableController::class, 'ping']);
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

Route::middleware('auth:joueur')->group(function () {
    Route::post('/deconnexion', [AuthController::class, 'deconnexion']);
    Route::get('/moi', [AuthController::class, 'moi']);

    // Roster joueur : créer un perso libre (sans l'engager dans un groupe).
    Route::post('/personnages', [GroupeController::class, 'creerPersonnage']);

    // Groupes / campagnes (création → dispatch du squelette en job).
    Route::post('/groupes', [GroupeController::class, 'creer']);
    Route::post('/groupes/{identifiant}/joueurs', [GroupeController::class, 'rejoindre']);

    // Démarrer la quête suivante : carte assemblée, monstres au budget,
    // initiative figée — entièrement moteur (App\Partie\DemarreurQuete).
    Route::post('/groupes/{identifiant}/quetes', [GroupeController::class, 'demarrerQuete']);

    // Statut « prêt » : déclenche la quête si tous prêts + narrateur actif.
    Route::post('/groupes/{identifiant}/pret', [GroupeController::class, 'pret']);

    // Choix de menu : validation contre le dernier menu proposé + résolution
    // moteur (ResolveurTour), puis narration/menus en jobs.
    Route::post('/groupes/{identifiant}/choix', [ChoixController::class, 'choisir']);
    // Rattrapage du menu courant du joueur (reconnexion).
    Route::get('/groupes/{identifiant}/menu', [ChoixController::class, 'menu']);

    // Arbres de compétences (montée de niveau par jalons, contrat) :
    // catalogue + acquisition d'un nœud (points dérivés du niveau).
    Route::get('/competences', [CompetenceController::class, 'catalogue']);
    Route::post('/groupes/{identifiant}/competences', [CompetenceController::class, 'acquerir']);

    // Phase marché (doc 04 §5 — au hub uniquement) : paniers en cache,
    // application atomique quand TOUS les joueurs ont confirmé.
    Route::post('/groupes/{identifiant}/marche', [MarcheController::class, 'ouvrir']);
    Route::put('/groupes/{identifiant}/marche/panier', [MarcheController::class, 'panier']);
    Route::post('/groupes/{identifiant}/marche/confirmation', [MarcheController::class, 'confirmer']);
    Route::delete('/groupes/{identifiant}/marche', [MarcheController::class, 'annuler']);

    // Snapshots & reprise (contrat, doc 12 §4, doc 05 §6) : snapshots
    // automatiques du moteur (debut_quete / nouveau_tour) ; la reprise
    // restaure l'état vivant — le « recharger » après TPK.
    Route::get('/groupes/{identifiant}/snapshots', [SauvegardeController::class, 'index']);
    Route::post('/groupes/{identifiant}/reprise', [SauvegardeController::class, 'reprendre']);

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
