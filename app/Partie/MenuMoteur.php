<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;

/**
 * Menu générique construit PAR LE MOTEUR depuis l'état exact — repli garanti
 * de la boucle de jeu (contrat : « l'API ne dépend jamais du LLM »).
 *
 * En quête : Se déplacer / Attaquer (un bouton par monstre actif adjacent) /
 * Désamorcer / Franchir (un bouton par piège DÉTECTÉ adjacent, doc 10 §4) /
 * Lancer {sort} (un bouton par sort DISPONIBLE, cibles légales jointes) /
 * Utiliser un parchemin (un bouton par parchemin au sac) / Se concentrer
 * (magicien, nœud Concentration — doc 02, MoteurSorts) / Fouiller (jet de
 * Mind 1) / Attendre. Au hub : options d'attente neutres.
 * Toutes les options sont exécutables telles quelles par ResolveurTour.
 */
final class MenuMoteur
{
    public function __construct(
        private readonly MoteurPieges $pieges,
        private readonly MoteurSorts $sorts,
    ) {}

    /**
     * @return array{situation: string, options: list<array<string, mixed>>}
     */
    public function generer(Groupe $groupe, Personnage $personnage): array
    {
        $quete = $groupe->phase === 'quete' ? $groupe->queteCourante : null;

        if ($quete === null) {
            return [
                'situation' => 'Le groupe se prépare au hub.',
                'options' => [
                    ['id' => 'attendre', 'libelle' => 'Attendre et observer', 'type' => 'attente'],
                    ['id' => 'continuer', 'libelle' => 'Continuer prudemment', 'type' => 'action'],
                ],
            ];
        }

        $etat = $quete->etatsPersonnages()->where('personnage_id', $personnage->id)->first();

        $options = [[
            'id' => 'se_deplacer',
            'libelle' => 'Se déplacer',
            'type' => 'deplacement',
            'parametres' => ['portee_base' => (int) $personnage->deplacement_base],
        ]];

        // Une option d'attaque par monstre actif ADJACENT (exécutable telle quelle).
        if ($etat !== null && $etat->position_x !== null) {
            $adjacents = $quete->instancesMonstres()
                ->where('etat', 'actif')
                ->with('monstre')
                ->orderBy('id')
                ->get()
                ->filter(fn (InstanceMonstre $i) => $i->position_x !== null
                    && abs((int) $i->position_x - (int) $etat->position_x)
                        + abs((int) $i->position_y - (int) $etat->position_y) === 1);

            foreach ($adjacents as $instance) {
                $nom = $instance->habillage['nom'] ?? $instance->monstre->nom_base;
                $options[] = [
                    'id' => "attaquer_{$instance->id}",
                    'libelle' => "Attaquer {$nom}",
                    'type' => 'attaque',
                    'cible_id' => $instance->id,
                ];
            }

            // Relever un allié TOMBÉ adjacent (doc 03 §48 : « relevable —
            // soin/allié ») : sacrifie le tour, remet le héros debout à 1 PV.
            $allies = $quete->etatsPersonnages()
                ->where('tombe', true)
                ->where('personnage_id', '!=', $personnage->id)
                ->with('personnage')
                ->get()
                ->filter(fn ($e) => $e->position_x !== null
                    && abs((int) $e->position_x - (int) $etat->position_x)
                        + abs((int) $e->position_y - (int) $etat->position_y) === 1);

            foreach ($allies as $allie) {
                $options[] = [
                    'id' => "relever_{$allie->personnage_id}",
                    'libelle' => "Relever {$allie->personnage->nom}",
                    'type' => 'relever',
                    'cible_personnage_id' => (int) $allie->personnage_id,
                ];
            }

            // Pièges DÉTECTÉS adjacents (doc 10 §4) : Désamorcer — réservé au
            // Nain ou au porteur d'une trousse à outils (permet_desamorcage) —
            // et Franchir pour une fosse. Exécutables tels quels (ResolveurTour).
            if ($quete->carte !== null) {
                $detectes = $this->pieges->detectesAdjacents(
                    $quete->carte, (int) $etat->position_x, (int) $etat->position_y,
                );
                $peutDesamorcer = $detectes !== [] && $this->pieges->peutDesamorcer($personnage);

                foreach ($detectes as $adjacent) {
                    $nomPiege = $adjacent['piege']?->nom ?? 'Piège';

                    if ($peutDesamorcer) {
                        $options[] = [
                            'id' => "desamorcer_{$adjacent['x']}_{$adjacent['y']}",
                            'libelle' => "Désamorcer {$nomPiege} — jet de Body",
                            'type' => 'desamorcage',
                            'jet' => ['attribut' => 'body', 'difficulte' => ResolveurTour::DIFFICULTE_DESAMORCAGE],
                            'parametres' => ['piege' => ['x' => $adjacent['x'], 'y' => $adjacent['y']]],
                        ];
                    }

                    if ($this->pieges->estFosse($adjacent['piege'])) {
                        $options[] = [
                            'id' => "franchir_{$adjacent['x']}_{$adjacent['y']}",
                            'libelle' => "Franchir {$nomPiege} — jet de Body",
                            'type' => 'franchissement',
                            'jet' => ['attribut' => 'body', 'difficulte' => ResolveurTour::DIFFICULTE_FRANCHISSEMENT],
                            'parametres' => ['piege' => ['x' => $adjacent['x'], 'y' => $adjacent['y']]],
                        ];
                    }
                }
            }
        }

        // Sorts des héros (doc 02) : un bouton par sort disponible (cibles
        // légales jointes — tir ami compris, S3), parchemins du sac, et
        // « Se concentrer » (S6) quand le nœud magicien le permet.
        if ($etat !== null) {
            foreach ($this->sorts->options($groupe, $quete, $personnage) as $option) {
                $options[] = $option;
            }
        }

        $options[] = [
            'id' => 'fouiller',
            'libelle' => 'Fouiller les environs — jet de Mind',
            'type' => 'jet',
            'jet' => ['attribut' => 'mind', 'difficulte' => 1],
        ];
        $options[] = ['id' => 'attendre', 'libelle' => 'Attendre et observer', 'type' => 'attente'];

        return [
            'situation' => 'Vous progressez dans le donjon.',
            'options' => $options,
        ];
    }
}
