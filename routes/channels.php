<?php

use App\Models\Groupe;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Canaux temps réel du jeu (doc 11 §7)
|--------------------------------------------------------------------------
| - `groupe.{identifiant}` : écran de table (narration, état partagé, MJ
|   réfléchit). L'identifiant est le code du groupe (docs/contrat-api.md) ;
|   il faut être membre du groupe (y contrôler au moins un personnage).
| - `joueur.{id}` : canal PRIVÉ de la manette — chaque téléphone reçoit
|   SON menu (menu.propose) ; seul le propriétaire peut s'y abonner.
| Les deux s'authentifient via le guard de session `joueur`.
*/

Broadcast::channel('groupe.{identifiant}', function ($joueur, string $identifiant) {
    return Groupe::query()
        ->where('identifiant', $identifiant)
        ->whereHas('personnages', fn ($requete) => $requete->where('joueur_id', $joueur->id))
        ->exists();
}, ['guards' => ['joueur']]);

Broadcast::channel('joueur.{joueurId}', function ($joueur, int $joueurId) {
    return (int) $joueur->id === $joueurId;
}, ['guards' => ['joueur']]);
