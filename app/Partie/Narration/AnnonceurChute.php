<?php

declare(strict_types=1);

namespace App\Partie\Narration;

use App\Events\NarrationDiffusee;
use App\Models\EtatPersonnageQuete;
use App\Support\Journal;

/**
 * Diffuse la chute ou le relèvement d'un héros.
 *
 * Extrait dans un service pour que le MODÈLE n'ait à connaître ni le journal,
 * ni les événements de diffusion : `EtatPersonnageQuete` observe sa colonne
 * `tombe` et délègue ici (voir son `booted()` pour la raison du choix de
 * l'observateur plutôt que des huit appelants).
 *
 * ⚠ Muet quand la clé n'a aucun texte : `pourQuete()` retombe déjà sur
 * `config/narration.php`, donc `null` signifie qu'aucune variante n'est
 * définie nulle part — mieux vaut ne rien dire que diffuser une narration vide.
 */
final class AnnonceurChute
{
    public function __construct(private readonly BibliothequeNarration $narration) {}

    public function annoncer(EtatPersonnageQuete $etat, string $cle): void
    {
        $quete = $etat->quete;
        $groupe = $quete?->groupe;
        $heros = $etat->personnage;

        if ($groupe === null || $heros === null) {
            return;
        }

        $recit = $this->narration->pourQuete($quete, $cle, ['heros' => $heros->nom]);

        if ($recit === null) {
            return;
        }

        $evenement = Journal::ajouter($groupe, 'narration', [
            'texte' => $recit['texte'],
            'ambiance' => $recit['ambiance'],
        ]);

        broadcast(new NarrationDiffusee(
            $groupe,
            $recit['texte'],
            ambiance: $recit['ambiance'],
            queteId: $evenement->quete_id,
            url: $recit['url'],
            sequence: $evenement->sequence,
        ));
    }
}
