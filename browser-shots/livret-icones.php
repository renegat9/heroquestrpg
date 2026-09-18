<?php

/*
 * ÉTAT POUR LES QUATRE CAPTURES PÉRIMÉES PAR LA CORRECTION D'ICÔNES
 * (René, 2026-09-18) : « Équiper » est passé du cintre partagé avec
 * « Attaquer » (swords) à `checkroom` ; « Ranger » de la boîte partagée avec
 * « Utiliser un objet » (backpack) à `archive` ; et les ARMES du sous-choix
 * d'attaque (`ChoixListeSheet.vue`, liste `armes[]`) sont passées de
 * `backpack` à `swords`.
 *
 * Un seul état sert les quatre figures (30, 32, 34, 16) : Grom porte DEUX
 * armes à une main de portées différentes (Rapière — diagonale — et Épée
 * courte), a de quoi ÉQUIPER/RANGER en plus dans son sac (Épée large, Casque),
 * une PILE de 3 Fioles de soin (palier de quantité de « jeter »), et un
 * monstre au contact pour que l'option « Attaquer » existe et qu'un combat
 * réel puisse être joué.
 *
 * Même parti pris que livret-echange.php / menu-souschoix.php : on provoque
 * l'état à la main sur la VRAIE campagne de harnais (jamais une campagne
 * réelle), la capture qui suit joue les VRAIS gestes sur le VRAI écran.
 *
 *   docker cp browser-shots/livret-icones.php heroquestrpg-app-1:/tmp/
 *   docker compose exec -T -e CODE=<code> app php artisan tinker --execute="require '/tmp/livret-icones.php';"
 */

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\FabriqueGrille;
use App\Partie\RangementObjet;
use Illuminate\Support\Facades\Cache;

$code = getenv('CODE') ?: throw new RuntimeException('CODE manquant');
$g = Groupe::where('identifiant', $code)->firstOrFail();
$q = Quete::where('groupe_id', $g->id)->latest('id')->firstOrFail();
$grom = $g->personnages()->where('nom', 'Grom')->firstOrFail();
$etat = $q->etatsPersonnages()->where('personnage_id', $grom->id)->firstOrFail();

// Table rase de son inventaire, pour que la capture soit reproductible.
Inventaire::where('personnage_id', $grom->id)->delete();

$equip = app(Equipement::class);

$rapiere = Objet::where('nom', 'Rapière')->firstOrFail();       // 2 dés, diagonale
$courte = Objet::where('nom', 'Épée courte')->firstOrFail();    // 2 dés, orthogonale seule
$large = Objet::where('nom', 'Épée large')->firstOrFail();      // au sac : de quoi "Équiper"
$casque = Objet::where('nom', 'Casque')->firstOrFail();         // au sac : une 2e pièce équipable
$fiole = Objet::where('nom', 'Fiole de soin')->firstOrFail();   // pile > 1 : palier de "jeter"

$ligneRapiere = RangementObjet::ranger($rapiere, $grom->id, 1);
$equip->equiper($grom, $ligneRapiere->fresh(), 'arme_principale');

$ligneCourte = RangementObjet::ranger($courte, $grom->id, 1);
$equip->equiper($grom, $ligneCourte->fresh(), 'arme_secondaire');

RangementObjet::ranger($large, $grom->id, 1);
RangementObjet::ranger($casque, $grom->id, 1);
RangementObjet::ranger($fiole, $grom->id, 3);

// Un monstre actif amené au contact ORTHOGONAL — accessible aux deux armes
// (la diagonale ajoute des cibles, elle n'en retire aucune).
$gx = (int) $etat->position_x;
$gy = (int) $etat->position_y;
$instance = $q->instancesMonstres()->where('etat', 'actif')->first();
$instance ?? throw new RuntimeException('aucun monstre actif dans cette quête');
$instance->update(['revele' => true, 'position_x' => $gx + 1, 'position_y' => $gy]);

// Grom n'a pas encore agi ce tour : c'est ce qui met "Attaquer"/"Équiper"/
// "Ranger" dans le menu (créneau ACTION, `! $aAgi`).
$etat->update(['a_joue' => false, 'a_agi' => false, 'tombe' => false]);

// Le menu en cache date d'avant ces changements : le purger force GET /menu à
// le recalculer (moteur seul, sans LLM) sur l'état qu'on vient d'écrire.
Cache::forget(GenererMenu::cleMenu($g->id, (int) $grom->joueur_id));

echo "Grom en ({$gx},{$gy}) — Rapière (principale) + Épée courte (secondaire)\n";
echo 'Sac : Épée large + Casque (équipables) + Fiole de soin ×3'.PHP_EOL;
echo "Monstre #{$instance->id} amené en (".($gx + 1).",{$gy})\n";
echo "prêt\n";
