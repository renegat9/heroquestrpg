<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ÉTAT DE CHOC — un héros déjà en quête, tombé UNIQUEMENT parce que son Mind
 * était à zéro (`etat_personnage_quete.tombe = true` alors que
 * `personnages.pv_body > 0`), doit être RELEVÉ : René revient le 2026-10-01
 * sur son arbitrage du 2026-09-06 (« un héros à 0 Mind tombe ») — *Against
 * the Ogre Horde* p. 9 dit « they go into shock », pas « ils tombent ». Ce
 * héros reste donc DEBOUT (`tombe = false`), simplement plafonné à 1 dé
 * d'attaque / 2 de défense / sans d6 de mouvement tant que `pv_mind` reste à
 * zéro (`Personnage::estEnChoc()`, dérivé — rien à écrire pour ÇA).
 *
 * ⚠ `pv_body > 0` est la garde qui ISOLE la cause : un héros à 0 Body ET à 0
 * Mind est un tombé BODY (ou les deux à la fois), et reste tombé à juste
 * titre — cette migration ne touche QUE celui dont le seul zéro est le Mind.
 *
 * ⚠ Scopée aux quêtes EN COURS (`quetes.etat = 'en_cours'`) : une ligne
 * `tombe` d'une quête déjà close (terminée/échouée/abandonnée) est de
 * l'historique, jamais relue par le jeu — la toucher ne changerait rien et
 * risquerait de laisser croire à une correction rétroactive de parties
 * jouées.
 *
 * Non-destructive : une seule colonne booléenne, sur un sous-ensemble de
 * lignes identifié par une lecture, jamais une suppression ni une purge.
 * Idempotente : une ligne déjà `tombe = false` ne matche simplement plus le
 * `WHERE` au second passage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $idsEnChocSeulement = DB::table('personnages')
            ->where('pv_mind', 0)
            ->where('pv_body', '>', 0)
            ->pluck('id');

        if ($idsEnChocSeulement->isEmpty()) {
            return;
        }

        $queteIdsEnCours = DB::table('quetes')->where('etat', 'en_cours')->pluck('id');

        if ($queteIdsEnCours->isEmpty()) {
            return;
        }

        DB::table('etat_personnage_quete')
            ->whereIn('personnage_id', $idsEnChocSeulement)
            ->whereIn('quete_id', $queteIdsEnCours)
            ->where('tombe', true)
            ->update(['tombe' => false]);
    }

    /**
     * down() est un NO-OP DÉLIBÉRÉ : revenir en arrière remettrait `tombe`
     * à `true` sur des héros qui n'ont RIEN fait depuis pour mériter de
     * retomber — on ne sait plus, après coup, lesquels de ces `tombe = true`
     * sont réapparus par un autre chemin légitime (un vrai coup qui les a
     * fait tomber à 0 Body entre-temps) et lesquels l'up() a changés. Rejouer
     * l'ancienne règle fausse (« 0 Mind fait tomber ») serait de toute façon
     * recréer le défaut que cette migration corrige — rien à restaurer.
     */
    public function down(): void {}
};
