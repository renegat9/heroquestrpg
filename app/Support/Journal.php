<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Evenement;
use App\Models\Groupe;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Écriture dans le journal d'événements rejouable (doc 07 §2).
 *
 * Centralise l'attribution du numéro de séquence (unique par groupe) —
 * utilisé par l'API (choix, jets, combats) et par les jobs IA (narration).
 */
class Journal
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $acteur
     */
    public static function ajouter(Groupe $groupe, string $type, array $payload, ?array $acteur = null): Evenement
    {
        // ⚠ DEUX ÉCRIVAINS À LA FOIS (2026-10-31) : un job IA (squelette de campagne, narration) et une action de
        // jeu lisent le même maximum et visent le même numéro ; l'index unique (groupe_id, sequence) refuse le
        // second, et le job mourait — le prologue d'une campagne s'est perdu ainsi (journal du 2026-10-31 03:51).
        // On retente au numéro suivant. Jamais le seul maximum relu : dans une transaction InnoDB, une lecture
        // simple peut rendre la même valeur périmée ; le numéro refusé + 1 avance à coup sûr.
        $sequence = (int) Evenement::query()
            ->where('groupe_id', $groupe->id)
            ->max('sequence') + 1;

        for ($essai = 1; ; $essai++) {
            try {
                return Evenement::create([
                    'groupe_id' => $groupe->id,
                    'quete_id' => $groupe->quete_courante_id,
                    'sequence' => $sequence,
                    'type' => $type,
                    'acteur' => $acteur,
                    'payload' => $payload,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($essai >= self::ESSAIS_MAX) {
                    throw $e;
                }

                $sequence = max($sequence + 1, (int) Evenement::query()->where('groupe_id', $groupe->id)->max('sequence') + 1);
            }
        }
    }

    /** Écrivains simultanés tolérés pour un même groupe avant de laisser l'erreur remonter. */
    private const ESSAIS_MAX = 10;
}
