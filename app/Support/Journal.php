<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Evenement;
use App\Models\Groupe;

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
        $sequence = (int) Evenement::query()
            ->where('groupe_id', $groupe->id)
            ->max('sequence') + 1;

        return Evenement::create([
            'groupe_id' => $groupe->id,
            'quete_id' => $groupe->quete_courante_id,
            'sequence' => $sequence,
            'type' => $type,
            'acteur' => $acteur,
            'payload' => $payload,
        ]);
    }
}
