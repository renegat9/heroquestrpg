<?php

declare(strict_types=1);

namespace App\Partie\Narration;

use App\Events\NarrationDiffusee;
use App\Models\EtatPersonnageQuete;
use App\Partie\SceneDeTable;
use App\Partie\TamponScenes;
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

        // SCÈNE de chute/relèvement pour l'écran de table (.table.scene) : la
        // figure en grand, au moment qui compte le plus d'une partie.
        //
        // ⚠ MISE EN TAMPON, pas diffusée ici. Cet observateur se déclenche au
        // moment exact où les PV touchent zéro — AU MILIEU de la résolution du
        // tour —, alors que la scène de l'attaque ne part qu'une fois le tour
        // entier résolu. Diffuser tout de suite montrait le héros à terre AVANT
        // le coup qui l'y avait mis (René, 2026-09-14). Le tampon rend l'ordre
        // au récit : l'attaque, puis la chute.
        //
        // ⚠ AVANT le retour anticipé ci-dessous : la scène ne doit pas dépendre
        // de l'existence d'une variante de narration. Deux promesses distinctes,
        // deux conditions distinctes — les accrocher l'une à l'autre est
        // exactement ce qui avait rendu la carte d'ouverture de quête invisible.
        app(TamponScenes::class)->ajouter(
            $groupe,
            app(SceneDeTable::class)->chute($heros, $cle === 'heros_tombe'),
            'heros:'.$heros->id,
        );

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
