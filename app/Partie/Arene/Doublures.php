<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Models\Personnage;
use Illuminate\Support\Facades\DB;

/**
 * MODE ARÈNE, lot A1 — le héros n'entre jamais dans l'arène, sa DOUBLURE y entre (`docs/plan-mode-arene.md` §2).
 *
 * Le moteur écrit directement dans les lignes d'un héros à des dizaines d'endroits (PV, potions, dague lancée, Rouille,
 * sorts, conditions, or…). Interdire chaque écriture en arène demanderait un garde-fou par site, et le prochain site
 * l'oublierait. L'arène fait donc combattre une COPIE : une nouvelle ligne `personnages`, avec la copie de ses lignes
 * filles. Tout le combat s'applique à elle ; le vrai héros n'est jamais écrit. L'étanchéité tient par construction.
 *
 * Point de passage UNIQUE de la création ET de la suppression d'une doublure. Les deux registres ci-dessous sont
 * testés DANS LES DEUX SENS (`tests/Feature/Arene/DoubluresTest.php`) : une colonne de `personnages` ou une table
 * liée à `personnages` ajoutée demain sans y être déclarée casse le test — c'est ce qui force la décision « copié ou
 * pas » au lieu de la laisser au hasard.
 */
final class Doublures
{
    /** Colonnes de `personnages` recopiées telles quelles : ce qu'EST le héros (classe, niveau, attributs, dés). */
    public const COLONNES_COPIEES = [
        'joueur_id', // la doublure se pilote depuis la manette de son joueur ; le roster l'exclut (`horsDoublures()`)
        'nom',
        'classe',
        'niveau',
        'attribut_body',
        'attribut_mind',
        'pv_body_max',
        'pv_mind_max',
        'des_attaque',
        'des_defense',
        'deplacement_base',
        'entraine_body', // l'entraînement est déjà dans les attributs ; la marque suit pour que la fiche reste cohérente
        'entraine_mind',
    ];

    /**
     * Colonnes de `personnages` JAMAIS recopiées, chacune avec sa raison — un retrait est un choix écrit.
     *
     * @var array<string, string>
     */
    public const COLONNES_NON_COPIEES = [
        'id' => 'nouvelle ligne',
        'created_at' => 'nouvelle ligne',
        'updated_at' => 'nouvelle ligne',
        'doublure_de' => 'posée à l\'id du héros source',
        'groupe_actif_id' => 'état de campagne : la doublure n\'appartient à aucune campagne (plan §2)',
        'pv_body' => 'RAFRAÎCHIE : entre à PV pleins, quel que soit l\'état du héros en campagne (AQ2)',
        'pv_mind' => 'RAFRAÎCHIE : entre à Mind plein (AQ2)',
        'or' => 'jamais copié : l\'or ne sort ni n\'entre dans l\'arène (plan §2, §5)',
        'benediction_oracle' => 'état de campagne (plan §2)',
        'malediction_oracle' => 'état de campagne, levé contre de l\'or au marché (plan §2)',
        'chef_oeuvre_quete_id' => 'état de campagne : la forge du hub',
        'recrutements_a_rabais' => 'état de campagne : le recrutement de mercenaires au hub',
        'rabais_recrutement_po' => 'état de campagne : le recrutement de mercenaires au hub',
    ];

    /**
     * Tables filles recopiées : l'équipement porté, les sorts (RECHARGÉS), les talents (capacités innées comprises,
     * elles vivent dans le même pivot).
     */
    public const TABLES_COPIEES = [
        'inventaire',
        'personnage_sorts',
        'personnage_competences',
    ];

    /**
     * Tables liées à `personnages` JAMAIS recopiées, chacune avec sa raison.
     *
     * @var array<string, string>
     */
    public const TABLES_NON_COPIEES = [
        'personnage_conditions' => 'RAFRAÎCHIE : la doublure entre sans conditions (AQ2)',
        'etat_personnage_quete' => 'état de la quête en cours du héros ; l\'arène aura le sien',
        'personnage_historique' => 'palmarès de campagne',
        'groupe_personnages' => 'appartenance au groupe de campagne (plan §2)',
        'personnage_visites_hopekins' => 'état de campagne : le tour de hub de Hopekins Rest',
        'personnage_faveurs' => 'faveurs de Hopekins Rest : récompenses de CAMPAGNE (or de Peacekeeper, guilde de '
            .'mercenaires, auberge…) ; non copiées tant que René n\'a pas tranché lesquelles valent en arène',
        'groupe_mercenaires' => 'référence (recruteur), pas une ligne du héros : un allié appartient à un groupe',
        'instances_monstres' => 'référence (dégât différé, contrôle), pas une ligne du héros',
    ];

    /**
     * Crée la doublure de `$source` : même héros, rafraîchi (PV et Mind au maximum, sorts rechargés, sans
     * conditions), avec son équipement et ses talents du moment, sans or ni état de campagne.
     *
     * Refuse de doubler une doublure : une copie de copie aurait pour source une ligne qui va disparaître.
     */
    public function doubler(Personnage $source): Personnage
    {
        if ($source->estDoublure()) {
            throw RefusArene::doublureDeDoublure($source);
        }

        return DB::transaction(function () use ($source): Personnage {
            // Relue en base : l'instance reçue peut porter des attributs non sauvegardés. On ne recopie que l'état
            // persistant, et on ne l'écrit jamais.
            $ligne = (array) DB::table('personnages')->where('id', $source->id)->first();

            $attributs = array_intersect_key($ligne, array_flip(self::COLONNES_COPIEES));
            $attributs['doublure_de'] = $source->id;
            $attributs['pv_body'] = $ligne['pv_body_max'];
            $attributs['pv_mind'] = $ligne['pv_mind_max'];

            $doublure = new Personnage;
            $doublure->forceFill($attributs)->save();

            foreach (self::TABLES_COPIEES as $table) {
                $lignes = DB::table($table)->where('personnage_id', $source->id)->orderBy('id')->get();

                foreach ($lignes as $fille) {
                    $copie = (array) $fille;
                    unset($copie['id']);
                    $copie['personnage_id'] = $doublure->id;

                    if ($table === 'personnage_sorts') {
                        $copie['disponible'] = true; // RAFRAÎCHIE : sorts rechargés (AQ2)
                    }

                    DB::table($table)->insert($copie);
                }
            }

            return $doublure->refresh();
        });
    }

    /**
     * Supprime une doublure et, par les `cascadeOnDelete` existants, toutes ses lignes filles.
     *
     * Refuse une ligne qui n'est pas une doublure : ce chemin ne peut pas supprimer un vrai héros, même par erreur
     * d'identifiant (plan §6, premier des deux verrous ; le second, `groupes.mode`, vient au lot A2).
     */
    public function supprimer(Personnage $doublure): void
    {
        // Relu en base : une instance dont on aurait posé `doublure_de` à la main sans la sauver ne passe pas.
        $source = DB::table('personnages')->where('id', $doublure->id)->value('doublure_de');

        if ($source === null) {
            throw RefusArene::pasUneDoublure($doublure);
        }

        DB::transaction(fn () => Personnage::whereKey($doublure->id)->whereNotNull('doublure_de')->delete());
    }
}
