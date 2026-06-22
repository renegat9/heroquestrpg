<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Canaux temps réel du jeu (doc 11 §7)
|--------------------------------------------------------------------------
| - `groupe.{identifiant}` : canal PUBLIC (état partagé, narration, MJ, prêts,
|   marché/vote/clôture, barks). Public car l'écran de TABLE (narrateur sans
|   compte) doit l'écouter, et Reverb/Pusher refusent un invité sur un canal
|   privé AVANT toute autorisation — un narrateur sans compte ne pourrait donc
|   jamais s'y abonner. Les events correspondants diffusent déjà sur un
|   `Channel` public (app/Events/*) ; l'accès est borné par le code de groupe
|   (cadre LAN, doc 11 §11). Aucun secret par joueur n'y transite.
| - `joueur.{id}` : canal PRIVÉ de la manette — chaque téléphone reçoit SON
|   menu (.menu.propose). Réservé au joueur authentifié propriétaire.
|
| NB : un canal public ne se déclare pas ici (pas d'autorisation) — seul le
| canal privé `joueur.{id}` a besoin d'un callback d'autorisation.
*/

Broadcast::channel('joueur.{joueurId}', function ($joueur, int $joueurId) {
    return (int) $joueur->id === $joueurId;
}, ['guards' => ['joueur']]);
