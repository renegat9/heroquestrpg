<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\ForgeAmelioration;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Models\Quete;
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
 * et `deDeplacementAnnule()`) — voir le docbloc de chaque clé dans
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
     * CHEF-D'ŒUVRE (nain·Forge, pouvoir final, lot B, 2026-10-23) : le forgeron a-t-il son amélioration
     * GRATUITE ouverte à CE passage au hub ? — seul prédicat qui le dit : `/moi` (le prix publié à 0),
     * `ForgeController::appliquer()` (le débit sauté) et `appliquer()` le lisent ici, jamais en copie.
     *
     * Ouvert = le groupe est au hub, le héros a le nœud, et la quête qui précède ce hub n'est pas celle
     * où l'amélioration a déjà servi (`personnages.chef_oeuvre_quete_id`, en base). Pas de quête terminée
     * (hub d'avant la première quête) = pas de passage : le chantier tient un passage pour « la phase de
     * hub qui suit une quête ».
     */
    public function gratuiteOuverte(Personnage $forgeron, Groupe $groupe): bool
    {
        if ($groupe->phase !== 'hub' || ! app(Talents::class)->a($forgeron, 'forge_gratuite')) {
            return false;
        }

        $passage = $this->passageCourant($groupe);

        return $passage !== null && (int) ($forgeron->chef_oeuvre_quete_id ?? 0) !== $passage;
    }

    /**
     * L'identifiant de la quête qui vient de se terminer — le passage au hub en cours — ou `null` si
     * aucune n'est encore achevée. « Achevée » = terminée OU échouée : une défaite ramène aussi au hub.
     */
    public function passageCourant(Groupe $groupe): ?int
    {
        $quete = Quete::query()
            ->where('groupe_id', $groupe->id)
            ->whereIn('etat', ['terminee', 'echouee'])
            ->max('id');

        return $quete === null ? null : (int) $quete;
    }

    /**
     * Applique une amélioration à une ligne d'inventaire (arme/armure non
     * Unique, jamais déjà améliorée), débite la bourse commune du groupe.
     *
     * `$gratuitPour` : le forgeron dont le Chef-d'œuvre est ouvert — l'amélioration est alors GRATUITE
     * (aucun débit, aucun contrôle de bourse) et la marque du passage est posée sur ce héros, dans la
     * même transaction. Refusé si le prédicat {@see self::gratuiteOuverte()} ne l'ouvre pas.
     */
    public function appliquer(Groupe $groupe, Inventaire $ligne, ForgeAmelioration $amelioration, ?Personnage $gratuitPour = null): Inventaire
    {
        $objet = $ligne->objet;

        if ($gratuitPour !== null && ! $this->gratuiteOuverte($gratuitPour, $groupe)) {
            throw ValidationException::withMessages([
                'amelioration_id' => "Le Chef-d'œuvre de ce héros est déjà utilisé à ce passage au hub.",
            ]);
        }

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

        if ($gratuitPour === null && (int) $groupe->or < $amelioration->prix) {
            throw ValidationException::withMessages([
                'amelioration_id' => 'La bourse commune ne couvre pas le prix de cette amélioration.',
            ]);
        }

        return DB::transaction(function () use ($groupe, $ligne, $amelioration, $gratuitPour) {
            if ($gratuitPour !== null) {
                // Chef-d'œuvre : pas de débit ; la marque dit à quelle quête ce passage est fermé.
                $gratuitPour->update(['chef_oeuvre_quete_id' => $this->passageCourant($groupe)]);
            } else {
                $groupe->decrement('or', $amelioration->prix);
            }

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
