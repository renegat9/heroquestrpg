<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\SceneTable;
use App\Models\Evenement;
use App\Models\Groupe;

/**
 * Tampon des scènes qui naissent PENDANT la résolution d'un tour, pour qu'elles
 * s'affichent APRÈS celle de l'action qui les a causées.
 *
 * ⚠ LE DÉFAUT QU'IL CORRIGE (question de René, 2026-09-14 : « quand une attaque
 * d'un monstre fait tomber un joueur, est-ce qu'on voit l'attaque et quand elle
 * ferme on voit le joueur à terre ? »). La réponse était NON, et l'ordre était
 * même inversé : la chute part d'un OBSERVATEUR de `EtatPersonnageQuete.tombe`,
 * donc au moment exact où les PV touchent zéro — c'est-à-dire AU MILIEU de la
 * résolution —, tandis que la scène de l'attaque n'est diffusée qu'une fois le
 * tour entier résolu, depuis `ChoixController`. La table montrait donc le héros
 * à terre, puis le coup qui l'y avait mis.
 *
 * ⚠ Pourquoi un tampon plutôt que déduire la chute du payload de l'attaque
 * (`cible_tombee`) : parce qu'un héros tombe aussi d'un piège, d'un poison, d'un
 * sort de Dread ou d'une réaction hors tour. L'observateur les attrape TOUS —
 * c'est sa raison d'être — et on ne va pas recopier cette couverture dans chaque
 * payload. On garde la source unique, on corrige seulement l'instant.
 *
 * ⚠ FILET : si personne ne vide le tampon (chute hors d'une requête `/choix`),
 * il se vide de lui-même à la fin de la requête. Une scène retenue et jamais
 * diffusée serait pire que l'ordre qu'on cherchait à corriger.
 */
final class TamponScenes
{
    /** @var list<array{groupe: Groupe, scene: array<string, mixed>, figure: string|null}> */
    private array $enAttente = [];

    private bool $filetArme = false;

    /**
     * @param  array<string, mixed>  $scene
     */
    public function ajouter(Groupe $groupe, array $scene, ?string $figure = null): void
    {
        $this->enAttente[] = ['groupe' => $groupe, 'scene' => $scene, 'figure' => $figure];

        if (! $this->filetArme) {
            $this->filetArme = true;
            app()->terminating(fn () => $this->vider());
        }
    }

    /**
     * Diffuse et oublie tout ce qui attend. Idempotent.
     *
     * @param  list<string>  $figuresEnMarche  `ResolveurTour::figuresEnMarche()` : une
     *                                         chute d'un héros qui a marché (piège en chemin)
     *                                         attend la fin de sa marche sur la table
     */
    public function vider(array $figuresEnMarche = []): void
    {
        $lot = $this->enAttente;
        $this->enAttente = [];

        foreach ($lot as $entree) {
            $scene = $entree['scene'];

            if ($entree['figure'] !== null && in_array($entree['figure'], $figuresEnMarche, true)) {
                $scene['figure'] = $entree['figure'];
            }

            broadcast(new SceneTable(
                $entree['groupe'],
                $scene,
                (int) Evenement::query()->where('groupe_id', $entree['groupe']->id)->max('sequence'),
            ));
        }
    }
}
