<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\FaceDeCombat;

/**
 * Résultat immuable d'un jet en opposition Mind contre Mind
 * ({@see OppositionMind}).
 */
final readonly class ResultatOppositionMind
{
    /**
     * @param  list<FaceDeCombat>  $facesAttaquant
     * @param  list<FaceDeCombat>  $facesDefenseur
     */
    public function __construct(
        public array $facesAttaquant,
        public array $facesDefenseur,
        public int $cranesAttaquant,
        public int $cranesDefenseur,
        public int $boucliersDefenseur,
        public FaceDeCombat $faceDefensive,
    ) {}

    /**
     * Crânes de l'attaquant NETS des boucliers du défenseur — *Mind Lock* (un tour
     * de gel par crâne net) et *Dominate* (au moins deux). Jamais négatif.
     */
    public function cranesNets(): int
    {
        return max(0, $this->cranesAttaquant - $this->boucliersDefenseur);
    }

    /**
     * L'écart de CRÂNES BRUTS, signé : positif quand le lanceur en fait plus que la
     * cible, négatif dans le cas inverse (*Mind Burst*). Nul = personne ne perd rien.
     */
    public function ecart(): int
    {
        return $this->cranesAttaquant - $this->cranesDefenseur;
    }

    /**
     * Les clés que tout payload d'opposition publie pour que le fil et la table
     * DESSINENT le jet (`JournalCombat::desDuJet()` lit exactement ces quatre
     * clés + `touches`/`boucliers`) — une seule forme pour les deux lectures, la
     * décision du moteur recopiée telle quelle, jamais redéduite côté client.
     *
     * `$brut` : *Mind Burst* compare des crânes aux crânes, donc la face qui
     * « compte » pour le défenseur est le CRÂNE et son compte est publié sous
     * `boucliers` (nom historique de la clé, voir `JournalCombat::desJetUnilateral()`) ;
     * *Mind Lock* et *Dominate* publient le bouclier du défenseur.
     *
     * @return array<string, mixed>
     */
    public function pourJournal(bool $brut = false): array
    {
        return [
            'faces_attaque' => array_map(fn (FaceDeCombat $f) => $f->value, $this->facesAttaquant),
            'faces_defense' => array_map(fn (FaceDeCombat $f) => $f->value, $this->facesDefenseur),
            'face_touchante' => FaceDeCombat::Crane->value,
            'face_defensive' => $brut ? FaceDeCombat::Crane->value : $this->faceDefensive->value,
            'touches' => $this->cranesAttaquant,
            'boucliers' => $brut ? $this->cranesDefenseur : $this->boucliersDefenseur,
        ];
    }
}
