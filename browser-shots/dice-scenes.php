<?php

/*
 * DÉCLENCHEUR de vérification visuelle — dés de résistance / Mind / piège
 * (2026-09-24). Même méthode que browser-shots/livret-scenes.php : payloads
 * de la forme EXACTE du moteur, passés au VRAI SceneDeTable, diffusés en
 * réel sur la campagne de harnais. Rien n'est fabriqué côté RENDU — seul le
 * déclenchement l'est, pour ne pas attendre une heure de dés au bon moment.
 *
 * Une SEULE scène par appel (SCENE=1|2|3), synchronisée par dice-scenes.mjs
 * via des fichiers `.trig-*` — pas de sleep() ici, l'orchestrateur (bash,
 * côté hôte) contrôle le tempo en attendant que la table soit prête.
 *
 *   docker cp <ce fichier> heroquestrpg-app-1:/tmp/dice-scenes.php
 *   docker compose exec -T -e CODE=<code> -e SCENE=1 app php artisan tinker --execute="require '/tmp/dice-scenes.php';"
 */

use App\Events\SceneTable;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Partie\SceneDeTable;

$code = getenv('CODE') ?: throw new RuntimeException('CODE manquant');
$scene = getenv('SCENE') ?: throw new RuntimeException('SCENE manquant');
$g = Groupe::where('identifiant', $code)->firstOrFail();
$q = Quete::where('groupe_id', $g->id)->latest('id')->firstOrFail();
$sc = app(SceneDeTable::class);

$heros = $g->personnages()->orderBy('id')->get();
$lanceur = $heros->first();
$victime = $heros->last();

$boss = InstanceMonstre::where('quete_id', $q->id)->with('monstre')->first();

$seq = fn () => (int) Evenement::query()->where('groupe_id', $g->id)->max('sequence');
$go = function (?array $s) use ($g, $seq) {
    if ($s === null) {
        echo "  (aucune scène)\n";

        return;
    }
    broadcast(new SceneTable($g, $s, $seq()));
    echo '  → '.$s['titre'].' | '.$s['issue']['libelle']."\n";
};

match ($scene) {
    // 1. Boule de Flammes (héros) — dés ROUGES de résistance, chaque 5/6
    //    annule 1 dégât. Payload EXACT de ResolveurTour::sortDegats()
    //    (branche desRouges).
    '1' => $go($sc->depuisResultat([
        'type' => 'sort', 'sort' => ['nom' => 'Boule de Flammes', 'element' => 'feu'],
        'cible' => ['instance_id' => $boss->id, 'nom' => $boss->nomAffiche()],
        'degats_fixes' => 3, 'des_resistance' => [2, 5, 6], 'degats_annules' => 2, 'degats' => 1,
    ], $lanceur)[0] ?? null),

    // 2. Sort du MJ (Sommeil) contre un héros — jet de MIND, au moment où il
    //    FRAPPE. Payload EXACT de MoteurDread::sortDreadControle() (branche
    //    jet_mind, un `resultats[]` par victime).
    '2' => $go($sc->depuisResultat([
        'type' => 'sort_dread', 'sort' => 'Sommeil', 'condition' => 'Endormi',
        'resultats' => [[
            'cible' => ['personnage_id' => $victime->id, 'nom' => $victime->nom],
            'mind_cible' => 2, 'succes' => 0, 'difficulte' => 1,
            'faces' => ['bouclier_blanc', 'bouclier_noir'], 'effet_applique' => true,
        ]],
    ], $lanceur)[0] ?? null),

    // 3. Piège de sol — Chute de blocs (3 dés de combat, 1 PV par crâne).
    //    Payload FABRIQUÉ selon la forme du contrat (`faces`/`touches`) :
    //    l'autre agent n'a pas encore câblé MoteurPieges au moment de cette
    //    vérification.
    '3' => $go($sc->depuisResultat([
        'type' => 'piege_declenche', 'piege' => ['nom' => 'Chute de blocs'],
        'personnage' => ['id' => $victime->id, 'nom' => $victime->nom],
        'degats' => 2, 'faces' => ['crane', 'crane', 'bouclier_blanc'], 'touches' => 2,
    ], $victime)[0] ?? null),

    default => throw new RuntimeException("SCENE inconnue : {$scene}"),
};
