<?php

/*
 * SÉANCE D'ÉCHANGE + PALIER DE QUANTITÉ DE « JETER » POUR LE LIVRET — le déclencheur.
 *
 * Les deux gestes (doc plan-echange-et-jeter, René 2026-09-17 ; contrat-api.md
 * §« Gérer son inventaire EN QUÊTE ») n'existent QUE pendant le tour d'un héros
 * qui n'a pas encore agi, avec un allié ORTHOGONALEMENT ADJACENT et des lignes
 * d'inventaire à montrer des deux côtés. Attendre qu'une vraie partie place
 * deux héros côte à côte avec le bon sac coûterait une heure de dés — le même
 * choix que `livret-scenes.php` : on pose l'état à la main, sur la VRAIE
 * campagne de harnais, et la manette qui capture ensuite (`livret-echange.mjs`)
 * joue les VRAIS gestes (clics, steppers) sur le VRAI écran.
 *
 * État provoqué :
 *  - Grom (tour en cours, n'a pas agi) et Borin déplacés l'un à côté de l'autre
 *    (Manhattan = 1) sur une case traversable de la carte déjà assemblée ;
 *  - le sac de Grom reçoit une pièce ENCOMBRANTE non équipée (Épée large,
 *    emplacement « sac ») et un CONSOMMABLE empilé (3 Fioles de soin,
 *    emplacement « consommable », hors capacité) — le contraste que la
 *    séance d'échange doit montrer, et la pile qui ouvre le palier de
 *    quantité de « jeter » ;
 *  - le sac de Borin reçoit sa propre pièce encombrante (Bouclier), pour que
 *    l'échange ait quelque chose à faire circuler DANS LES DEUX SENS ;
 *  - le menu en cache de Grom est purgé, pour que `GET /menu` le régénère
 *    (moteur seul, sans LLM) sur l'état qu'on vient d'écrire plutôt que de
 *    servir un menu calculé avant.
 *
 * ⚠ NE JAMAIS pointer ce script sur une vraie campagne : il écrit des positions
 * et des lignes d'inventaire à la main. Réservé à une campagne de harnais
 * montée par browser-shots/campagne/preparer.sh puis nettoyée par nettoyer.sh.
 *
 *   docker cp browser-shots/livret-echange.php heroquestrpg-app-1:/tmp/
 *   docker compose exec -T -e CODE=<code> app php artisan tinker --execute="require '/tmp/livret-echange.php';"
 */

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Objet;
use App\Models\Quete;
use App\Partie\FabriqueGrille;
use App\Partie\RangementObjet;
use Illuminate\Support\Facades\Cache;

$code = getenv('CODE') ?: throw new RuntimeException('CODE manquant');
$g = Groupe::where('identifiant', $code)->firstOrFail();
$q = Quete::where('groupe_id', $g->id)->latest('id')->firstOrFail();

$heros = fn (string $nom) => $g->personnages()->where('nom', $nom)->firstOrFail();
$grom = $heros('Grom');
$borin = $heros('Borin');

$etatGrom = $q->etatsPersonnages()->where('personnage_id', $grom->id)->firstOrFail();
$etatBorin = $q->etatsPersonnages()->where('personnage_id', $borin->id)->firstOrFail();

// Grom n'a pas encore agi : c'est ce qui met « Échanger » dans son menu — cette
// option vit dans le créneau ACTION (`! $aAgi`), au même titre qu'équiper.
$etatGrom->update(['a_joue' => false, 'a_agi' => false, 'tombe' => false]);
$etatBorin->update(['tombe' => false]);

// Borin ADJACENT à Grom, Manhattan = 1, sur une case traversable — le spawn de
// départ ne le garantit pas (les héros arrivent groupés, pas forcément en
// croix), donc on la choisit à la main, comme livret-scenes.php pose les PV.
$grille = FabriqueGrille::pour($q);
$gx = (int) $etatGrom->position_x;
$gy = (int) $etatGrom->position_y;
$voisin = null;
foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
    $cx = $gx + $dx;
    $cy = $gy + $dy;
    if ($grille->estTraversable($cx, $cy)) {
        $voisin = [$cx, $cy];
        break;
    }
}
$voisin ?? throw new RuntimeException('aucune case libre adjacente à Grom');
$etatBorin->update(['position_x' => $voisin[0], 'position_y' => $voisin[1]]);

// Sacs — RangementObjet::ranger() reproduit exactement le geste de jeu (achat,
// butin) : un consommable s'empile en emplacement « consommable » (hors
// capacité), le reste range une ligne par exemplaire en emplacement « sac »
// (ENCOMBRANTE). Pas de ligne Inventaire posée à la main : ce serait déjà une
// seconde version de la règle que `RangementObjet` porte.
$epee = Objet::where('nom', 'Épée large')->firstOrFail();
$fiole = Objet::where('nom', 'Fiole de soin')->firstOrFail();
$bouclier = Objet::where('nom', 'Bouclier')->firstOrFail();

RangementObjet::ranger($epee, $grom->id, 1);     // encombrante, non équipée
RangementObjet::ranger($fiole, $grom->id, 3);    // consommable, pile > 1 : palier de quantité de « jeter »
RangementObjet::ranger($bouclier, $borin->id, 1); // encombrante côté Borin : de quoi échanger dans les DEUX sens

// Le menu en cache de Grom date d'AVANT ces changements (préparer.sh a déjà
// fait tourner GenererMenu à l'ouverture de la quête) : le purger force
// GET /menu à le recalculer — moteur seul, instantané, pas de LLM — sur l'état
// qu'on vient d'écrire.
Cache::forget(GenererMenu::cleMenu($g->id, (int) $grom->joueur_id));

echo "Grom en ({$gx},{$gy}), Borin déplacé en ({$voisin[0]},{$voisin[1]})\n";
echo 'Sac de Grom : Épée large ×1 (sac) + Fiole de soin ×3 (consommable)'.PHP_EOL;
echo 'Sac de Borin : Bouclier ×1 (sac)'.PHP_EOL;
echo "prêt\n";
