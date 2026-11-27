<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Engine\MotsClesQuete;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\FabriqueGrille;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * LE RAPPEL DE LA REINE — lot E2, *Spirit Queen's Torment* q. 14 C (G0053 p. 33) : « Though she can be defeated in combat, if a
 * hero wants to remind her of her past as Nelath or shake her from her servitude to Zargon they may use their action to do
 * so. That hero rolls 1 combat die. On a shield, the Spirit Queen is affected by the message and takes 1 Mind Point of
 * damage. If the queen is reduced to 0 Mind Points in this manner, she breaks the enchantment placed upon her by Zargon and
 * thanks the heroes for saving her before fading into glimmering light. »
 *
 * Capacité de profil `rappelable` (`InstanceMonstre::CAPACITES_DE_PROFIL`). Décisions écrites :
 * - « a shield » : bouclier BLANC ou NOIR (René, 2026-11-27) ;
 * - le héros doit VOIR la figure (`Grille::ligneDeVue()`) : on parle à quelqu'un qu'on voit — le livret ne donne pas de portée ;
 * - apaisée, elle quitte le plateau à l'état `vaincu` (elle compte pour une fin `vaincre`), marquée `apaisee` dans son
 *   habillage, et la note `figure_apaisee` de l'auteur joue (le texte de la fin, un drapeau de campagne…).
 */
final class RappelDeLaReine
{
    public const CAPACITE = 'rappelable';

    public function __construct(private readonly LanceurDes $des) {}

    /** @return Collection<int, InstanceMonstre> les figures rappelables que ce héros voit */
    public function cibles(Quete $quete, EtatPersonnageQuete $etat): Collection
    {
        if (! $quete->estConstruite() || $etat->tombe || $etat->position_x === null) {
            return collect();
        }
        $figures = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)->whereNotNull('position_x')
            ->with('monstre')->orderBy('id')->get()->filter(fn (InstanceMonstre $i) => $i->aCapacite(self::CAPACITE));
        if ($figures->isEmpty()) {
            return collect();
        }
        $grille = FabriqueGrille::pour($quete);

        return $figures->filter(fn (InstanceMonstre $i) => $grille->ligneDeVue(
            (int) $etat->position_x, (int) $etat->position_y, (int) $i->position_x, (int) $i->position_y))->values();
    }

    /**
     * Le héros rappelle la figure à son passé : un dé de combat ; un bouclier lui ôte 1 point d'Esprit ; à 0, elle s'efface.
     *
     * @return array<string, mixed> le résultat moteur `rappel_figure`
     */
    public function rappeler(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $instanceId): array
    {
        $figure = $this->cibles($quete, $etat)->firstWhere('id', $instanceId)
            ?? throw ValidationException::withMessages(['option_id' => 'Personne à qui parler ici : la figure doit être en vue.']);

        $face = FaceDeCombat::depuisD6($this->des->d6());
        $touche = $face !== FaceDeCombat::Crane;
        $apaisee = false;

        if ($touche) {
            $mind = max(0, (int) $figure->pv_mind - 1);
            $figure->update(['pv_mind' => $mind]);
            if ($mind === 0) {
                $apaisee = true;
                $index = collect(NotesDeQuete::figuresPosees($quete))->search(fn (InstanceMonstre $i) => (int) $i->id === (int) $figure->id);
                $figure->update(['etat' => 'vaincu', 'position_x' => null, 'position_y' => null,
                    'habillage' => [...(array) $figure->habillage, 'apaisee' => true]]);
                if ($index !== false) {
                    app(NotesDeQuete::class)->signaler($quete->fresh(), MotsClesQuete::FIGURE_APAISEE, ['monstre' => (int) $index], $heros);
                }
            }
        }

        return ['type' => 'rappel_figure', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom,
            'figure' => $figure->nomAffiche(), 'instance_id' => (int) $figure->id, 'face' => $face->value,
            'touche' => $touche, 'mind_restant' => (int) $figure->fresh()->pv_mind, 'apaisee' => $apaisee];
    }
}
