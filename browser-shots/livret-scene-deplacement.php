<?php

/*
 * SCÈNE DE TABLE DU DÉ DE DÉPLACEMENT, pour le livret (2026-09-24) — le
 * complément d'un seul genre à `livret-scenes.php`, qui n'en changeait aucun.
 * Un agent précédent n'a pas réussi à capturer le dé rouge BARRÉ (Armure de
 * plates) sur l'écran de table : la diffusion partait, mais la capture
 * tombait hors de la fenêtre d'affichage — un vrai tour ne dure que le temps
 * du jet, et il faut ouvrir l'écran de table AVANT de le déclencher, pas
 * après. Ici comme dans `livret-scenes.php` : le VRAI constructeur
 * `SceneDeTable::deplacement()`, seul le déclenchement est provoqué.
 *
 *   node browser-shots/livret-scene-deplacement.mjs <code>   (AVANT ce script)
 *   docker cp browser-shots/livret-scene-deplacement.php heroquestrpg-app-1:/tmp/
 *   docker compose exec -T -e CODE=<code> app php artisan tinker \
 *     --execute="require '/tmp/livret-scene-deplacement.php';"
 *
 * ⚠ N'écrit aucun PV (contrairement à livret-scenes.php) : `deplacement()` ne
 * lit que le nom et la classe du héros pour l'élision du titre, jamais ses PV.
 */

use App\Events\SceneTable;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Quete;
use App\Partie\SceneDeTable;

$code = getenv('CODE') ?: throw new RuntimeException('CODE manquant');
$g = Groupe::where('identifiant', $code)->firstOrFail();
Quete::where('groupe_id', $g->id)->latest('id')->firstOrFail(); // juste pour vérifier qu'une quête existe
$sc = app(SceneDeTable::class);
$grom = $g->personnages()->where('nom', 'Grom')->firstOrFail();

$seq = fn () => (int) Evenement::query()->where('groupe_id', $g->id)->max('sequence');

sleep(6); // le temps que l'écran de table s'ouvre (le script de capture le fait AVANT)

$s = $sc->deplacement($grom, [
    'base' => 4, 'des' => [2], 'bonus_equipement' => 0,
    'de_annule' => true, 'de_annule_par' => 'Armure de plates',
    'total_jet' => 4, 'multiplicateur' => 1, 'bonus_potion' => 0, 'portee' => 4,
]);
broadcast(new SceneTable($g, $s, $seq()));
echo '  → '.$s['titre'].' | '.$s['issue']['libelle']."\n";
sleep(9);
echo "fini\n";
