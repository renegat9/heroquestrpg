<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\ForgeAmelioration;
use App\Models\Groupe;
use App\Models\Inventaire;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Forge du Nain (nœud d'arbre, doc 01 §6 + doc 04 §4) : améliore
 * DÉFINITIVEMENT un exemplaire d'équipement (`inventaire.ameliorations`),
 * réalisée AU HUB contre de l'or de la bourse commune.
 *
 * Les 6 améliorations du catalogue sont désormais TOUTES câblées (2026-09-19)
 * — Affûtée/Renforcée recopient un bonus de dés dans `des_attaque`/
 * `des_defense` (`Equipement::recalculerCombat()`) ; Perforante, Cruelle,
 * Allégée et Gardée se lisent EN SITUATION sur l'exemplaire forgé
 * (`Equipement::effetForge()`, `relanceCruelle()`, `ignorerPremierEtatDuCombat()`,
 * et `malusDeplacement()`) — voir le docbloc de chaque clé dans
 * `App\Engine\MotsClesEquipement`. {@see self::EFFETS_SUPPORTES} reste le
 * garde-fou : une FUTURE amélioration ajoutée au catalogue sans lecteur reste
 * filtrée ici plutôt que vendue comme si elle marchait.
 */
final class Forge
{
    /**
     * Clés d'effet de ForgeAmelioration dont la mécanique est câblée dans le
     * moteur — chacune avec un lecteur nommé dans `MotsClesEquipement`.
     *
     * PUBLIC : c'est le même filtre que lit `estSupportee()`, seul point de
     * passage entre le 422 d'`appliquer()` et la décision `forgeable` publiée
     * par `/moi` (`AuthController::detailForge()`) — jamais une seconde copie.
     *
     * ⚠ Contient aussi `frequence` : `estSupportee()` exige que TOUTES les
     * clés de l'effet soient couvertes, et Cruelle porte `frequence:
     * une_fois_par_combat` À CÔTÉ de `relance_de_attaque_rate` — l'omettre
     * aurait laissé Cruelle filtrée pour une clé pourtant lue
     * (`Equipement::relanceCruelle()`).
     */
    public const EFFETS_SUPPORTES = [
        'bonus_des_attaque', 'bonus_des_defense',
        'annule_boucliers_defense', 'relance_de_attaque_rate', 'frequence',
        'annule_malus_deplacement', 'ignore_premier_etat_du_combat',
    ];

    /** Catalogue déjà lu, gardé le temps d'une requête (`/moi` l'appelle par ligne de sac, pas par objet). */
    private array $catalogueParCible = [];

    /** Cette amélioration a-t-elle un effet dont la mécanique de combat est câblée ? */
    public function estSupportee(ForgeAmelioration $amelioration): bool
    {
        return array_diff(array_keys($amelioration->effet), self::EFFETS_SUPPORTES) === [];
    }

    /**
     * Améliorations RÉELLEMENT applicables (catégorie ET mécanique câblée) à
     * une catégorie d'objet. Les 6 du catalogue le sont toutes aujourd'hui
     * (cf. docblock de classe) ; le filtre par {@see self::EFFETS_SUPPORTES}
     * reste le garde-fou pour une future amélioration semée sans lecteur —
     * elle ne doit jamais atteindre un joueur comme une option qui marche.
     *
     * @return Collection<int, ForgeAmelioration>
     */
    public function ameliorationsApplicables(string $categorie): Collection
    {
        return $this->catalogueParCible[$categorie] ??= ForgeAmelioration::query()
            ->where('cible', $categorie)
            ->get()
            ->filter(fn (ForgeAmelioration $a) => $this->estSupportee($a))
            ->values();
    }

    /**
     * La DÉCISION « cette pièce est-elle forgeable, maintenant » — publiée par
     * `/moi`, jamais recalculée côté client (règle du projet : le serveur
     * publie la décision, pas les ingrédients). `$forgeronDisponible` porte
     * déjà les deux préalables que `ForgeController::appliquer()` vérifie
     * avant tout le reste : le groupe est au hub, et LE JOUEUR QUI REGARDE
     * contrôle un héros actif portant le nœud Forge — ni l'un ni l'autre ne se
     * lit sur l'objet, donc ni l'un ni l'autre n'est recalculable ici.
     *
     * ⚠ Ne dit rien de l'or : le prix varie par amélioration choisie
     * (`forge_catalogue` le porte), et la bourse commune est déjà publiée en
     * clair (`EtatGroupe.groupe.or`) — comparer deux entiers déjà publiés
     * n'est pas re-dériver une règle, c'est le même calcul que fait déjà
     * `RecrutementHub.vue` pour les mercenaires.
     */
    public function estForgeable(Inventaire $ligne, bool $forgeronDisponible): bool
    {
        if (! $forgeronDisponible) {
            return false;
        }

        $objet = $ligne->objet;

        if ($objet === null || $objet->rarete === 'unique') {
            return false;
        }

        if (($ligne->ameliorations ?? []) !== []) {
            return false;
        }

        return $this->ameliorationsApplicables((string) $objet->categorie)->isNotEmpty();
    }

    /**
     * Applique une amélioration à une ligne d'inventaire (arme/armure non
     * Unique, jamais déjà améliorée), débite la bourse commune du groupe.
     */
    public function appliquer(Groupe $groupe, Inventaire $ligne, ForgeAmelioration $amelioration): Inventaire
    {
        $objet = $ligne->objet;

        if ($objet === null || $objet->categorie !== $amelioration->cible) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$amelioration->nom} » ne peut être appliquée qu'à une pièce de catégorie {$amelioration->cible}.",
            ]);
        }

        if ($objet->rarete === 'unique') {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Un artefact (rareté Unique) ne peut pas être amélioré par la Forge.',
            ]);
        }

        if (($ligne->ameliorations ?? []) !== []) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » a déjà été amélioré : un objet ne l'est qu'une fois.",
            ]);
        }

        if (! $this->estSupportee($amelioration)) {
            throw ValidationException::withMessages([
                'amelioration_id' => "« {$amelioration->nom} » n'est pas encore disponible : sa mécanique de combat reste à implémenter.",
            ]);
        }

        if ((int) $groupe->or < $amelioration->prix) {
            throw ValidationException::withMessages([
                'amelioration_id' => 'La bourse commune ne couvre pas le prix de cette amélioration.',
            ]);
        }

        return DB::transaction(function () use ($groupe, $ligne, $amelioration) {
            $groupe->decrement('or', $amelioration->prix);

            $ligne->update(['ameliorations' => [[
                'nom' => $amelioration->nom,
                'effet' => $amelioration->effet,
            ]]]);

            // Déjà équipé : on laisse Equipement recalculer les dés du porteur
            // depuis son équipement complet, plutôt que d'appliquer un delta à la
            // main — l'attaque étant désormais un REMPLACEMENT par l'arme, un
            // delta isolé produirait une valeur fausse.
            if (in_array($ligne->emplacement, Equipement::SLOTS, true) && $ligne->personnage !== null) {
                app(Equipement::class)->recalculerCombat($ligne->personnage->refresh());
            }

            return $ligne->fresh();
        });
    }
}
