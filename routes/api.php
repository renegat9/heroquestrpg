<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChoixController;
use App\Http\Controllers\Api\GroupeController;
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

Route::middleware('auth:joueur')->group(function () {
    Route::post('/deconnexion', [AuthController::class, 'deconnexion']);
    Route::get('/moi', [AuthController::class, 'moi']);

    // Groupes / campagnes (création → dispatch du squelette en job).
    Route::post('/groupes', [GroupeController::class, 'creer']);
    Route::post('/groupes/{identifiant}/joueurs', [GroupeController::class, 'rejoindre']);
    Route::get('/groupes/{identifiant}/etat', [GroupeController::class, 'etat']);

    // Choix de menu : validation + résolution moteur, puis narration/menu en jobs.
    Route::post('/groupes/{identifiant}/choix', [ChoixController::class, 'choisir']);
});
