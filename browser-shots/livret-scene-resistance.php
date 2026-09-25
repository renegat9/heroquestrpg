<?php

/*
 * SCÈNE DE TABLE DES DÉS DE RÉSISTANCE, pour le livret (2026-09-25) — le
 * complément d'un seul genre à `livret-scenes.php`, même patron que
 * `livret-scene-deplacement.php`. Boule de Feu : dés ROUGES de résistance
 * (5-6 annule 1 dégât), payload EXACT de `ResolveurTour::sortDegats()`
 * (branche `des_resistance`). Le VRAI constructeur `SceneDeTable`, seul le
 * déclenchement est provoqué.
 *
 *   node browser-shots/livret-scene-resistance.mjs <code>   (AVANT ce script)
 *   docker cp browser-shots/livret-scene-resistance.php heroquestrpg-app-1:/tmp/
 *   docker compose exec -T -e CODE=<code> app php artisan tinker \
 *     --execute="require '/tmp/livret-scene-resistance.php';"
 *
 * ⚠ N'écrit aucun PV de héros — seule la cible (un monstre) est ramenée à ses
 * PV de catalogue pour que le nombre affiché reste cohérent avec le coup.
 * Suppose un héros nommé « Aldric » dans le groupe (comme `livret-scenes.php`
 * suppose « Grom »/« Borin ») : celui de `preparer-livret.sh`/`preparer.sh`
 * avec le quatuor barbare/nain/elfe/magicien habituel.
 */

use App\Events\SceneTable;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\Images\BibliothequeImages;
use App\Partie\SceneDeTable;

$code = getenv('CODE') ?: throw new RuntimeException('CODE manquant');
$g = Groupe::where('identifiant', $code)->firstOrFail();
$q = Quete::where('groupe_id', $g->id)->latest('id')->firstOrFail();
$sc = app(SceneDeTable::class);
$aldric = $g->personnages()->where('nom', 'Aldric')->firstOrFail();

// Même filtre que livret-scenes.php : une créature ILLUSTRÉE plutôt que la
// première venue.
$images = app(BibliothequeImages::class);
$illustree = fn (InstanceMonstre $i) => ! str_contains(
    $images->urlMonstre($i->id, $i->monstre_id, $i->monstre?->nom_base), '/placeholder/');
$cible = InstanceMonstre::where('quete_id', $q->id)->where('etat', 'actif')->with('monstre')->get()
    ->filter($illustree)->first()
    ?? InstanceMonstre::where('quete_id', $q->id)->where('etat', 'actif')->firstOrFail();
$cible->update(['pv_body' => $cible->pv_body_max]);

$seq = fn () => (int) Evenement::query()->where('groupe_id', $g->id)->max('sequence');

sleep(6); // le temps que l'écran de table s'ouvre (le script de capture le fait AVANT)

// `id` est INDISPENSABLE : sans lui `SceneDeTable::sort()` ne peut pas
// retrouver l'illustration du sort (`BibliothequeImages::urlSort()` la
// range par id) et retombe sur une vignette introuvable — trouvé en
// regardant la première capture (2026-09-25).
$sortId = Sort::where('nom', 'Boule de Feu')->value('id');

$s = $sc->depuisResultat([
    'type' => 'sort', 'sort' => ['id' => $sortId, 'nom' => 'Boule de Feu', 'element' => 'feu'],
    'cible' => ['instance_id' => $cible->id, 'nom' => $cible->nomAffiche()],
    'degats_fixes' => 3, 'des_resistance' => [2, 5, 6], 'degats_annules' => 2, 'degats' => 1,
], $aldric)[0] ?? null;

if ($s === null) {
    throw new RuntimeException('aucune scène produite');
}

broadcast(new SceneTable($g, $s, $seq()));
echo '  → '.$s['titre'].' | '.$s['issue']['libelle']."\n";
sleep(9);
echo "fini\n";
