<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\ClasseHeros;
use App\Models\Objet;
use App\Models\Personnage;
use Illuminate\Support\Facades\DB;

/**
 * LA NAISSANCE D'UN HÉROS — le seul endroit qui crée un héros aux valeurs de départ du catalogue (doc 01). Sorti de
 * `GroupeController` le 2026-11-27, quand le barde de remplacement de *Spirit Queen's Torment* (mode classique) a dû naître
 * en pleine quête par le même chemin qu'un héros créé au roster : deux copies auraient divergé au premier sort de départ.
 *
 * Parité HeroQuest de base (doc 02 §2) : le MAGICIEN reçoit les 9 sorts de ses 3 éléments, l'ELFE les 3 sorts de son
 * élément (ou 3 sorts elfiques) ; Barde, Druide, Warlock : leur répertoire fixe ; puis les capacités innées et
 * l'équipement de départ (`EquipementDepart::PAR_CLASSE`, les pièces d'équipement portées d'emblée).
 */
final class CreationHeros
{
    public function __construct(private readonly MoteurSorts $sorts) {}

    /**
     * @param  list<string>|null  $elements
     * @param  list<int>|null  $sortsElfiques
     */
    public function creer(
        int $joueurId,
        string $nom,
        string $classe,
        ?array $elements = null,
        ?array $sortsElfiques = null,
        string $mode = Personnage::MODE_CAMPAGNE,
    ): Personnage {
        $base = ClasseHeros::where('nom', $classe)->firstOrFail();

        // TRANSACTION : un héros naît en plusieurs gestes — la fiche, les sorts,
        // les capacités innées, l'équipement de départ. Qu'un seul échoue et la
        // ligne `personnages` restait, orpheline et injouable.
        return DB::transaction(function () use ($base, $joueurId, $nom, $classe, $elements, $sortsElfiques, $mode) {
            $personnage = Personnage::create([
                'joueur_id' => $joueurId,
                'mode' => $mode,
                'nom' => $nom,
                'classe' => $classe,
                'niveau' => 1,
                'attribut_body' => $base->attr_body,
                'attribut_mind' => $base->attr_mind,
                'pv_body_max' => $base->pv_body,
                'pv_body' => $base->pv_body,
                'pv_mind_max' => $base->pv_mind,
                'pv_mind' => $base->pv_mind,
                'des_attaque' => $base->des_attaque,
                'des_defense' => $base->des_defense,
                'deplacement_base' => $base->deplacement_base,
                'or' => 0,
            ]);

            // Voie elfique : les 3 sorts choisis REMPLACENT l'école de départ.
            if ($sortsElfiques !== null) {
                $this->sorts->fixerSortsElfiques($personnage, $sortsElfiques);
            } else {
                foreach (MoteurSorts::elementsDepart($classe, $elements) as $element) {
                    $this->sorts->attacherElement($personnage, $element);
                }
            }

            // Barde, Druide, Warlock : leurs 3 sorts sont FIXES, pas choisis.
            $this->sorts->attacherRepertoireClasse($personnage, $classe);

            app(CapacitesInnees::class)->attacherA($personnage);
            $this->equiperDepart($personnage, $classe);

            return $personnage;
        });
    }

    private function equiperDepart(Personnage $personnage, string $classe): void
    {
        $equipement = app(Equipement::class);

        foreach (EquipementDepart::PAR_CLASSE[$classe] ?? [] as $nomObjet) {
            $objet = Objet::where('nom', $nomObjet)->first();

            if ($objet === null) {
                continue; // catalogue incomplet (tests ciblés) : on n'échoue pas la création
            }

            $ligne = $personnage->inventaire()->create([
                'objet_id' => $objet->id,
                'emplacement' => 'sac',
            ]);

            // Les pièces d'équipement sont PORTÉES d'emblée ; le reste (trousse)
            // reste au sac, comme n'importe quel objet utilitaire.
            if (in_array($objet->emplacement, Equipement::SLOTS, true)) {
                $equipement->equiper($personnage->refresh(), $ligne);
            }
        }
    }
}
