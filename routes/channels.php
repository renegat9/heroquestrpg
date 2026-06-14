<?php

use App\Models\Groupe;
use Illuminate\Http\Request;
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
|   il faut être membre du groupe (y contrôler au moins un personnage) OU
|   être la session de table de ce groupe (contrat §Autorisations).
| - `joueur.{id}` : canal PRIVÉ de la manette — chaque téléphone reçoit
|   SON menu (menu.propose) ; seul le propriétaire peut s'y abonner.
*/

Broadcast::channel('groupe.{identifiant}', function ($joueur, string $identifiant) {
    // Joueur membre : au moins un personnage actif dans ce groupe.
    if ($joueur !== null) {
        return Groupe::query()
            ->where('identifiant', $identifiant)
            ->whereHas('personnages', fn ($requete) => $requete->where('joueur_id', $joueur->id))
            ->exists();
    }

    return false;
}, ['guards' => ['joueur']]);

/*
 * Canal groupe pour la session de table (narrateur sans compte).
 * L'auth broadcasting ne passe pas par le guard joueur quand la table
 * s'y connecte — on vérifie la session Laravel côté requête.
 */
Broadcast::channel('groupe.{identifiant}', function ($user, string $identifiant, Request $request) {
    return $request->session()->get('table_groupe') === $identifiant;
});

Broadcast::channel('joueur.{joueurId}', function ($joueur, int $joueurId) {
    return (int) $joueur->id === $joueurId;
}, ['guards' => ['joueur']]);
