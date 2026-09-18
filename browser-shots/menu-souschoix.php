<?php

/*
 * ÉTAT POUR LA CAPTURE DU MENU EN SOUS-CHOIX (René, 2026-09-18).
 *
 * Les trois options converties ne se voient que si le héros a de quoi CHOISIR :
 * la profondeur suit la donnée, donc une seule arme en main garde l'option
 * d'attaque à plat et un sac vide ne produit aucune option « Équiper ». On pose
 * donc, sur la VRAIE campagne de harnais, le seul état qui les montre toutes :
 *
 *  - DEUX armes à une main équipées (une longue, une courte) → l'option
 *    « Attaquer » porte `armes[]`, et les deux entrées n'ont PAS les mêmes
 *    cibles (diagonale contre orthogonal) ;
 *  - un monstre amené au contact, sinon il n'y a personne à frapper ;
 *  - plusieurs pièces au sac, dont une arme à une main → l'option « Équiper »
 *    porte `pieces[]`, et cette entrée-là porte DEUX slots (choix de main).
 *
 * Même parti pris que `livret-scenes.php` : on provoque l'état, jamais le
 * rendu — la capture qui suit joue les vrais clics sur le vrai écran.
 */

use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Quete;

$groupe = Groupe::where('identifiant', getenv('CODE'))->firstOrFail();
$quete = Quete::findOrFail($groupe->quete_courante_id);
$grom = $groupe->personnages()->where('nom', 'Grom')->firstOrFail();

$etat = $quete->etatsPersonnages()->where('personnage_id', $grom->id)->firstOrFail();

$pose = function (string $nom, string $emplacement) use ($grom) {
    $objet = Objet::where('nom', $nom)->first();

    if ($objet === null) {
        echo "⚠ objet introuvable : {$nom}\n";

        return;
    }

    Inventaire::create([
        'personnage_id' => $grom->id,
        'objet_id' => $objet->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);

    echo "  {$nom} → {$emplacement}\n";
};

// Table rase de son inventaire, pour que la capture soit reproductible.
Inventaire::where('personnage_id', $grom->id)->delete();

echo "Équipé (deux mains occupées, portées DIFFÉRENTES) :\n";
$pose('Épée courte', 'arme_principale');
$pose('Dague', 'arme_secondaire');

echo "Au sac (dont une arme à une main → deux slots utiles) :\n";
$pose('Épée large', 'sac');
$pose('Casque', 'sac');

// Un monstre au contact : sans cible, aucune option d'attaque n'est émise.
$instance = $quete->instancesMonstres()->where('etat', 'actif')->first();

if ($instance !== null) {
    $instance->update([
        'revele' => true,
        'position_x' => (int) $etat->position_x + 1,
        'position_y' => (int) $etat->position_y,
    ]);
    echo "Monstre amené au contact en (".($etat->position_x + 1).",{$etat->position_y})\n";
} else {
    echo "⚠ aucun monstre actif : l'option d'attaque n'apparaîtra pas\n";
}

// Le menu en cache est PURGÉ, sinon `GET /menu` resservirait celui d'avant.
\Illuminate\Support\Facades\Cache::forget(
    \App\Jobs\GenererMenu::cleMenu($groupe->id, (int) $grom->joueur_id)
);

echo "prêt\n";
