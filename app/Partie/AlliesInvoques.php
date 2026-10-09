<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\MotsClesEquipement;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Inventaire;
use App\Models\Mercenaire;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;

/**
 * Alliés APPELÉS par un objet — le *Fangwarden Armlet* de Jungles of Delthrak
 * (livret F9907 p. 50) : « Use this magical armlet to call forth a Raptor animal
 * ally. If the Raptor is defeated, the armlet's power goes dormant. Its power
 * replenishes if the hero completes two quests without its assistance. This
 * artifact may only be used once per quest. »
 *
 * Trois états, chacun DURABLE (CLAUDE.md : rien en cache) :
 *  - l'allié est une ligne `groupe_mercenaires` marquée `invoque_par_objet_id` —
 *    jouée par son joueur comme tout allié, mais purgée en fin de quête
 *    ({@see self::purger()}) : « une fois par quête » appelle un Raptor NEUF à
 *    chaque quête, jamais un survivant plus un second ;
 *  - la DORMANCE vit sur la ligne d'inventaire du brassard
 *    (`inventaire.quetes_avant_reveil`) : elle suit l'objet quand on le donne ;
 *  - la cadence « une fois par quête » est la fenêtre d'usage ordinaire
 *    (`effet.frequence`, `MoteurCharges`).
 *
 * « Without its assistance » : un brassard dormant ne peut pas servir, donc
 * chaque quête TERMINÉE par son porteur compte ({@see self::terminerQuete()}).
 */
final class AlliesInvoques
{
    /** Rayon de recherche d'une case d'arrivée autour du héros. */
    private const RAYON = 4;

    public function estDormant(?Inventaire $ligne): bool
    {
        return $ligne !== null && (int) ($ligne->quetes_avant_reveil ?? 0) > 0;
    }

    /**
     * Pourquoi le brassard ne peut PAS servir maintenant, ou `null` s'il le peut.
     * Le menu ne propose l'option que si la réponse est `null`, et le résolveur
     * refuse avec la même phrase — un seul prédicat pour les deux.
     *
     * @param  array<string, mixed>  $effet
     */
    public function refus(Quete $quete, Personnage $personnage, Inventaire $ligne, array $effet): ?string
    {
        if ($this->estDormant($ligne)) {
            $n = (int) $ligne->quetes_avant_reveil;

            return "La puissance du brassard dort encore : {$n} quête".($n > 1 ? 's' : '').' à terminer pour la réveiller.';
        }

        if ($this->fiche($effet) === null) {
            return 'Aucune fiche d\'allié ne répond à cet objet.';
        }

        if ($this->caseLibre($quete, $personnage) === null) {
            return 'Aucune case libre à proximité pour accueillir l\'allié.';
        }

        return null;
    }

    /**
     * Pose l'allié près du héros. Les gardes sont rejouées ici : le menu a pu
     * changer entre sa génération et la soumission du choix.
     *
     * @param  array<string, mixed>  $effet
     * @return array<string, mixed> payload d'usage (`allie`)
     */
    public function appeler(Groupe $groupe, Quete $quete, Personnage $personnage, Inventaire $ligne, array $effet): array
    {
        $raison = $this->refus($quete, $personnage, $ligne, $effet);

        if ($raison !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['option_id' => $raison]);
        }

        $fiche = $this->fiche($effet);
        $case = $this->caseLibre($quete, $personnage);

        $allie = $this->poser($groupe, $fiche, $personnage, (int) $ligne->objet_id, $case['x'], $case['y']);

        return ['allie' => [
            'id' => (int) $allie->id,
            'nom' => (string) $fiche->nom,
            'x' => $case['x'],
            'y' => $case['y'],
        ]];
    }

    /**
     * LE point de passage de tout allié APPELÉ : une ligne `groupe_mercenaires`
     * marquée `invoque_par_objet_id`. Le Raptor (Fangwarden Armlet) et le Squelette
     * Hearthkin (Cor des Hearthkin) passent tous deux ici : c'est ce marqueur que
     * {@see purger()} lit en fin de quête, victoire ou échec, AVANT l'entretien.
     * Un squelette posé sans lui survivait à la quête et payait 10 po (2026-10-09).
     */
    public function poser(Groupe $groupe, Mercenaire $fiche, Personnage $recruteur, int $objetId, int $x, int $y): GroupeMercenaire
    {
        return GroupeMercenaire::create([
            'groupe_id' => $groupe->id,
            'mercenaire_id' => $fiche->id,
            'recruteur_personnage_id' => $recruteur->id,
            'invoque_par_objet_id' => $objetId,
            'pv_body' => (int) $fiche->pv_body,
            'position_x' => $x,
            'position_y' => $y,
            'etat' => 'actif',
        ]);
    }

    /**
     * Un allié appelé vient de tomber : l'objet qui l'a appelé s'endort.
     * Rend le payload à publier (journal + manette) ou `null` si l'allié n'a pas
     * été appelé par un objet dormant-capable.
     *
     * @return array{objet: string, quetes: int, personnage: string}|null
     */
    public function allieVaincu(GroupeMercenaire $allie): ?array
    {
        if ($allie->invoque_par_objet_id === null || $allie->recruteur_personnage_id === null) {
            return null;
        }

        $ligne = Inventaire::query()
            ->where('personnage_id', $allie->recruteur_personnage_id)
            ->where('objet_id', $allie->invoque_par_objet_id)
            ->with('objet', 'personnage')
            ->first();

        $clause = (array) ($ligne?->objet?->effet[MotsClesEquipement::APPELLE_ALLIE] ?? []);
        $quetes = (int) ($clause['dormance_quetes'] ?? 0);

        if ($ligne === null || $quetes <= 0) {
            return null;
        }

        $ligne->update(['quetes_avant_reveil' => $quetes]);

        return [
            'objet' => (string) $ligne->objet->nom,
            'quetes' => $quetes,
            'personnage' => (string) $ligne->personnage?->nom,
        ];
    }

    /**
     * Fin de quête RÉUSSIE : chaque héros qui l'a terminée rapproche d'un cran
     * le réveil de ses brassards dormants. Rend un payload par brassard
     * réveillé, pour que le journal le DISE.
     *
     * @return list<array{objet: string, personnage: string}>
     */
    public function terminerQuete(Groupe $groupe, Quete $quete): array
    {
        $reveilles = [];

        $herosIds = $quete->etatsPersonnages()->pluck('personnage_id');

        $lignes = Inventaire::query()
            ->whereIn('personnage_id', $herosIds)
            ->where('quetes_avant_reveil', '>', 0)
            ->with('objet', 'personnage')
            ->get();

        foreach ($lignes as $ligne) {
            $reste = max(0, (int) $ligne->quetes_avant_reveil - 1);
            $ligne->update(['quetes_avant_reveil' => $reste > 0 ? $reste : null]);

            if ($reste === 0) {
                $reveilles[] = [
                    'objet' => (string) $ligne->objet?->nom,
                    'personnage' => (string) $ligne->personnage?->nom,
                ];
            }
        }

        // UN événement par quête, publié au hub (`groupe.objets_reveilles`,
        // `EtatGroupe::annonceDeQuete()`) : un effet automatique que rien
        // n'annonce est injouable.
        if ($reveilles !== []) {
            Journal::ajouter($groupe, 'systeme', [
                'action' => 'objets_reveilles',
                'quete_id' => (int) $quete->id,
                'objets' => $reveilles,
            ]);
        }

        return $reveilles;
    }

    /** Fin de quête (victoire OU échec) : les alliés appelés quittent le jeu. */
    public function purger(Groupe $groupe): void
    {
        GroupeMercenaire::where('groupe_id', $groupe->id)->whereNotNull('invoque_par_objet_id')->delete();
    }

    /** @param  array<string, mixed>  $effet */
    private function fiche(array $effet): ?Mercenaire
    {
        $nom = (string) data_get($effet, MotsClesEquipement::APPELLE_ALLIE.'.mercenaire', '');

        return $nom === '' ? null : Mercenaire::where('nom', $nom)->first();
    }

    /**
     * La case libre la plus proche du héros (parcours pondéré de la grille, qui
     * écarte déjà figures, meubles et cases interdites à l'arrêt).
     *
     * @return array{x: int, y: int}|null
     */
    private function caseLibre(Quete $quete, Personnage $personnage): ?array
    {
        $etat = $quete->etatsPersonnages()->where('personnage_id', $personnage->id)->first();

        if ($etat === null || $etat->position_x === null || $etat->tombe || $quete->carte === null) {
            return null;
        }

        $grille = FabriqueGrille::pour($quete, exceptPersonnageId: $personnage->id);
        $candidates = $grille->casesAtteignables((int) $etat->position_x, (int) $etat->position_y, self::RAYON);

        if ($candidates === []) {
            return null;
        }

        // Plus proche d'abord, puis ordre de lecture : déterministe.
        $meilleure = null;
        foreach ($candidates as $cle => $chemin) {
            [$x, $y] = array_map('intval', explode(',', $cle));
            $rang = [count($chemin), $y, $x];

            if ($meilleure === null || $rang < $meilleure[0]) {
                $meilleure = [$rang, ['x' => $x, 'y' => $y]];
            }
        }

        return $meilleure[1];
    }
}
