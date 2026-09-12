<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\LanceurDes;
use App\Models\Condition;
use App\Models\Inventaire;
use App\Models\Personnage;
use Illuminate\Validation\ValidationException;

/**
 * Usage des potions / consommables.
 *
 * CANON HeroQuest : une potion se boit à TOUT MOMENT — y compris hors de son
 * tour, pendant le tour d'un monstre (ex. se soigner juste avant un coup
 * fatal) — et ne coûte PAS d'action. Le moteur applique l'effet de l'objet
 * (soin Body/Mind, antidote, buff chiffré) et consomme l'exemplaire.
 */
class MoteurPotions
{
    public function __construct(
        private readonly MoteurSorts $sorts,
        private readonly LanceurDes $des,
    ) {}

    /**
     * Boit une ligne d'inventaire consommable PORTÉE par $personnage, au
     * profit de $cible — un compagnon orthogonalement adjacent (René,
     * 2026-09-11), ou $personnage lui-même par défaut (comportement inchangé).
     *
     * ⚠ $personnage reste le PORTEUR : c'est son inventaire qui perd
     * l'exemplaire, et c'est lui qui doit être dans la manette au moment de
     * l'action (la fiole part de SON sac). $cible est celui qui BOIT — tous
     * les effets (soins, buffs, conditions, restauration de sorts) atterrissent
     * sur lui, jamais sur le porteur, et c'est SA classe que la carte
     * restreint (Krogar le Barbare peut tendre une Potion de rage guerrière à
     * Aldric le magicien adjacent, mais c'est Aldric qui but, donc c'est lui
     * que la carte refuse).
     *
     * @param  array<string, mixed>  $parametres  choix du joueur — `sort_ids`
     *                                            pour les potions qui rendent
     *                                            un nombre limité de sorts.
     * @return array<string, mixed> résumé moteur (effets appliqués)
     */
    public function boire(Personnage $personnage, Inventaire $ligne, array $parametres = [], ?Personnage $cible = null): array
    {
        $objet = $ligne->objet;

        if ($objet === null || $objet->categorie !== 'consommable') {
            throw ValidationException::withMessages([
                'inventaire_id' => "Cet objet n'est pas une potion / un consommable.",
            ]);
        }
        if ((int) $ligne->personnage_id !== (int) $personnage->id) {
            throw ValidationException::withMessages([
                'inventaire_id' => "Cette potion n'est pas dans votre inventaire.",
            ]);
        }

        $buveur = $cible ?? $personnage;

        // RESTRICTION DE CLASSE — trois potions officielles sont réservées au
        // Barbare et deux à l'Elfe (doc 16 §2.1bis). C'est ici qu'elle est
        // opposable, et nulle part ailleurs : un consommable ne passe jamais
        // par `Equipement::equiper()`, donc rien ne l'aurait contrôlé.
        //
        // ⚠ Elle porte sur $buveur, PAS sur $personnage : sans quoi tendre sa
        // potion à un voisin contournerait en silence une règle de carte
        // officielle dès que le porteur, lui, avait la bonne classe.
        if (! app(Equipement::class)->estAccessible($buveur, $objet)) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » n'est pas pour un {$buveur->classe}.",
            ]);
        }

        $effet = (array) $objet->effet;

        // L'état de quête sert désormais à quatre effets ; on le charge une
        // fois — celui du BUVEUR, pas du porteur : un buff/compteur « par
        // tour » appartient à qui va agir avec, pas à qui a tendu la fiole.
        // La potion se boit hors tour, donc il peut être absent (au hub).
        $etat = $buveur->groupeActif?->queteCourante?->etatsPersonnages()
            ->where('personnage_id', $buveur->id)->first();

        // « you may use only one potion per turn » (Potion de dextérité). ⚠ La
        // garde ne vaut QUE pour les potions marquées `une_par_tour` : brider
        // les quatorze autres inventerait une règle qu'aucune carte ne porte.
        if (! empty($effet['une_par_tour']) && $etat !== null
            && in_array($objet->nom, (array) ($etat->capacites_tour ?? []), true)) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » : une seule par tour.",
            ]);
        }

        $applique = [];

        // Soin Body / Mind — plafonné au maximum du héros. Tout ce qui suit
        // porte sur $buveur : c'est LUI qui encaisse l'effet, jamais le
        // porteur qui a sorti la fiole du sac.
        // Soin ALÉATOIRE (fiole de fouille) : 1d6 PV, plafonné au maximum.
        if (isset($effet['soin_pv_body_de'])) {
            $avant = (int) $buveur->pv_body;
            $de = $this->des->d6();
            $buveur->pv_body = min((int) $buveur->pv_body_max, $avant + $de);
            $applique['soin_pv_body'] = $buveur->pv_body - $avant;
            $applique['de'] = $de;
        }

        if (isset($effet['soin_pv_body'])) {
            $avant = (int) $buveur->pv_body;
            $buveur->pv_body = min((int) $buveur->pv_body_max, $avant + (int) $effet['soin_pv_body']);
            $applique['soin_pv_body'] = $buveur->pv_body - $avant;
        }
        if (isset($effet['soin_pv_mind'])) {
            $avant = (int) $buveur->pv_mind;
            $buveur->pv_mind = min((int) $buveur->pv_mind_max, $avant + (int) $effet['soin_pv_mind']);
            $applique['soin_pv_mind'] = $buveur->pv_mind - $avant;
        }

        // Potion de restauration supérieure : « restores any hero's Body and
        // Mind Points to the level they were at when the hero started the
        // Quest ». Chez nous c'est littéralement le MAXIMUM — `DemarreurQuete`
        // remet les deux jauges à leur plafond au lancement de chaque quête,
        // donc aucun état de départ n'a besoin d'être mémorisé.
        if (! empty($effet['restaure_jauges_depart'])) {
            $applique['soin_pv_body'] = (int) $buveur->pv_body_max - (int) $buveur->pv_body;
            $applique['soin_pv_mind'] = (int) $buveur->pv_mind_max - (int) $buveur->pv_mind;
            $buveur->pv_body = (int) $buveur->pv_body_max;
            $buveur->pv_mind = (int) $buveur->pv_mind_max;
        }

        $buveur->save();

        // Un soin RELÈVE, comme le sort (décision de René, 2026-08-06).
        //
        // Boire est une action gratuite que rien n'interdit à un héros à terre,
        // mais `tombe` n'était jamais touché ici : le compagnon vidait sa fiole,
        // remontait à 4 PV… et restait couché, alors que le même soin lancé en
        // SORT le remettait debout (`ResolveurTour::sortUtilitaire`). Deux
        // chemins pour un même effet ne doivent pas raconter deux règles.
        $this->releverSiSoigne($buveur);

        // Antidote — retire une condition nommée si présente.
        // Potion d'héroïsme : une ATTAQUE SUPPLÉMENTAIRE ce tour-ci — deux
        // attaques au lieu d'une, et non des dés en plus. Même patron que la
        // Réserve arcanique du magicien (un second sort), sur l'état de tour.
        if (! empty($effet['attaque_supplementaire']) && $etat !== null) {
            $etat->update(['attaque_supplementaire' => true]);
            $applique['attaque_supplementaire'] = true;
        }

        // Parchemin de Sorts : « restores all spells that Hero possessed at the
        // beginning of the quest ». À distinguer du nœud Concentration, qui n'en
        // récupère qu'UN — c'est toute la valeur de la carte.
        //
        // Un ENTIER borne le nombre rendu : Potion de magie (3), Potion de
        // rappel (1, Elfe). Le joueur choisit lesquels via `sort_ids` ; sans
        // choix on prend les premiers épuisés, parce qu'une potion qui ne
        // ferait rien faute de paramètre serait pire qu'un choix arbitraire.
        if (! empty($effet['restaure_sorts'])) {
            $applique['sorts_restaures'] = app(MoteurSorts::class)->restaurerSorts(
                $buveur,
                is_int($effet['restaure_sorts']) ? $effet['restaure_sorts'] : null,
                array_map('intval', (array) ($parametres['sort_ids'] ?? [])),
            );
        }

        if (isset($effet['retire_condition'])) {
            $condition = Condition::query()->where('nom', $effet['retire_condition'])->first();
            if ($condition !== null) {
                $buveur->conditions()->detach($condition->id);
                $applique['retire_condition'] = $effet['retire_condition'];
            }
        }

        // ⚠ `soin_source` (2026-09-03) : rendre EXACTEMENT ce qu'une source a
        // coûté, et non un forfait. La *Plume anti-poison* dit « restores ANY of
        // the owner's Body Points lost by poisoning » ; nous rendions 2, faute
        // de savoir combien le poison avait pris. `MoteurDegats` mémorise
        // désormais le cumul par source sur l'état de quête, ce qui rend la
        // carte littérale — et l'Antidote au venin avec elle.
        //
        // ⚠ Le cumul est REMIS À ZÉRO après usage : sans ça, une seconde plume
        // rendrait une seconde fois des PV déjà rendus.
        if (isset($effet['soin_source'])) {
            $applique['soin_source'] = $this->soignerParSource(
                $buveur, (string) $effet['soin_source'],
            );
        }

        // Buff — pour TOUTE potion qui porte une `duree`.
        //
        // La condition testait auparavant les deux clés de bonus chiffré, si
        // bien qu'une potion dont l'effet n'était pas un nombre de dés ne
        // posait aucun buff : la relance de la Potion de bataille, le
        // multiplicateur de la Force glaciale, les cases de la Dextérité, la
        // clairvoyance de la Vision n'auraient eu aucun support pour vivre ni
        // pour expirer. On s'appuie sur la réciproque de l'invariant de
        // `MotsClesEquipement::DUREE` : un effet qui déclare quand il s'arrête
        // est un effet qui dure.
        if (isset($effet['duree'])) {
            $applique['buff'] = $this->sorts->appliquerBuffPotion($buveur, $objet)->nom;
        }

        // Potion de vitesse bue APRÈS avoir entamé son mouvement.
        // `ResolveurTour::pointsDeplacement()` sort immédiatement quand
        // `deplacement_restant` est déjà posé : le multiplicateur n'y serait
        // jamais relu, et la potion disparaîtrait sans rien faire. On l'applique
        // donc au restant, sur-le-champ.
        if (! empty($effet['deplacement_multiplie']) && $etat?->deplacement_restant !== null) {
            $restant = (int) $etat->deplacement_restant * (int) $effet['deplacement_multiplie'];
            $etat->update(['deplacement_restant' => $restant]);
            $applique['deplacement_restant'] = $restant;
        }

        // Marque la potion « une par tour » comme bue. Compteur partagé avec les
        // capacités de carte : c'est le même besoin — une fenêtre d'un tour —
        // et il est remis à zéro au même endroit.
        if (! empty($effet['une_par_tour']) && $etat !== null) {
            $etat->update(['capacites_tour' => array_values(array_unique([
                ...(array) ($etat->capacites_tour ?? []), $objet->nom,
            ]))]);
        }

        // Consommation de l'exemplaire.
        if ((int) $ligne->quantite > 1) {
            $ligne->decrement('quantite');
        } else {
            $ligne->delete();
        }

        $buveur->refresh();

        return [
            'type' => 'potion',
            'objet' => $objet->nom,
            'personnage_id' => $buveur->id,
            // ⚠ Présent seulement quand la potion change de main : la table et
            // le journal doivent pouvoir dire « Krogar tend sa potion à Aldric »
            // plutôt que la confondre avec un soin sur soi. Absent partout
            // ailleurs — pas de changement de forme pour le cas `soi`.
            'porteur_id' => (int) $personnage->id === (int) $buveur->id ? null : $personnage->id,
            'effets' => $applique,
            'pv_body' => (int) $buveur->pv_body,
            'pv_body_max' => (int) $buveur->pv_body_max,
            'pv_mind' => (int) $buveur->pv_mind,
            'pv_mind_max' => (int) $buveur->pv_mind_max,
        ];
    }

    /**
     * Remet debout un héros à terre dont le soin vient de rouvrir une jauge.
     *
     * Même condition que le sort de soin (`ResolveurTour::sortUtilitaire`) : on
     * ne relève que si les PV Body repassent AU-DESSUS de zéro — un antidote ou
     * un soin de Mind ne suffit pas à faire tenir un corps sur ses jambes.
     *
     * Cherche l'état de quête du héros dans la quête COURANTE de son groupe : la
     * potion se boit hors tour, donc on ne peut pas compter sur un état déjà
     * chargé par le résolveur.
     */
    private function releverSiSoigne(Personnage $personnage): void
    {
        if ((int) $personnage->pv_body <= 0) {
            return;
        }

        $queteId = $personnage->groupeActif?->quete_courante_id;

        if ($queteId === null) {
            return; // au hub : personne n'est « tombé »
        }

        $personnage->etatsQuete()
            ->where('quete_id', $queteId)
            ->where('tombe', true)
            ->update(['tombe' => false]);
    }
    /**
     * Rend au héros tout ce qu'une SOURCE de dégâts lui a coûté dans la quête,
     * puis remet ce cumul à zéro.
     *
     * @return array{source: string, rendus: int}
     */
    private function soignerParSource(Personnage $personnage, string $source): array
    {
        $quete = $personnage->groupeActif?->queteCourante;
        $etat = $quete?->etatsPersonnages()->where('personnage_id', $personnage->id)->first();

        if ($etat === null) {
            return ['source' => $source, 'rendus' => 0];
        }

        $cumul = (array) ($etat->degats_subis ?? []);
        $perdus = (int) ($cumul[$source] ?? 0);

        if ($perdus <= 0) {
            return ['source' => $source, 'rendus' => 0];
        }

        $avant = (int) $personnage->pv_body;
        $personnage->update(['pv_body' => min((int) $personnage->pv_body_max, $avant + $perdus)]);

        unset($cumul[$source]);
        $etat->update(['degats_subis' => $cumul]);

        return ['source' => $source, 'rendus' => (int) $personnage->fresh()->pv_body - $avant];
    }

}
