<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\CaseLibre;
use App\Partie\CreationHeros;
use App\Partie\FabriqueGrille;
use App\Partie\TamponAnnonces;

/**
 * LE BARDE DE REMPLACEMENT — lot E2, *Spirit Queen's Torment* p. 4 (G0053) : « If no one is playing the bard hero and a hero
 * dies, place the bard hero in the room with the dead hero. The player of the dead hero now controls the bard hero and can
 * immediately pick up any items held by the fallen hero. The bard hero may not take actions on the turn they appear. The
 * appearance of the bard hero in this way may only happen once during this quest series. » ; q. 3 : « Once Sigill has been
 * found, if a hero dies they may instead play the bard hero. »
 *
 * `campagne.barde_de_remplacement {si_drapeau?}`. Décisions de René (2026-11-27) : un barde NEUF de niveau 1, carte de classe
 * et équipement de départ (`CreationHeros`, le chemin du roster), au JOUEUR du mort ; une fois par campagne (drapeau de
 * campagne réservé `_barde_remplacant`) ; seulement quand un héros MEURT (modes heroic et standard — `MortHeroic::mourir()`) ;
 * et après le drapeau de l'auteur (« Sigill trouvé »). Personne ne joue déjà un barde dans le groupe, le mort compris.
 *
 * Il paraît à la case du mort (ou la plus proche, libre), entre au groupe et à la quête, ramasse au SAC tout ce que portait
 * le mort (« can immediately pick up any items ») et a déjà joué ce tour (« may not take actions on the turn they appear »).
 */
final class BardeDeRemplacement
{
    public const DRAPEAU = '_barde_remplacant';

    public const CLASSE = 'barde';

    /** Le barde paraîtra-t-il à la mort de ce héros ? Lu AVANT la mort (le groupe a encore son mort parmi ses vivants). */
    public function aRemplacer(Groupe $groupe, Quete $quete, EtatPersonnageQuete $etat): bool
    {
        $regle = data_get($groupe->campagneConstruite?->document, 'barde_de_remplacement');
        if (! is_array($regle) || ! $quete->estConstruite() || ! empty(((array) $groupe->drapeaux_campagne)[self::DRAPEAU])
            || $etat->position_x === null) {
            return false;
        }
        if (isset($regle['si_drapeau']) && ! $quete->aDrapeau((string) $regle['si_drapeau'])) {
            return false;
        }

        return ! $groupe->personnages()->wherePivot('actif', true)->whereNull('personnages.mort_le')
            ->where('personnages.classe', self::CLASSE)->exists();
    }

    /**
     * Le barde paraît (après que la ligne d'état du mort a quitté la quête) : rend l'annonce.
     *
     * @param  array{x: int, y: int}  $case  la case du mort
     * @return array<string, mixed>
     */
    public function faireParaitre(Groupe $groupe, Quete $quete, Personnage $mort, array $case, bool $affairesPerdues): array
    {
        $barde = app(CreationHeros::class)->creer((int) $mort->joueur_id, 'Barde', self::CLASSE);
        $groupe->personnages()->attach($barde->id, ['ordre_initiative' => (int) $groupe->personnages()->count() + 1, 'actif' => true]);
        $barde->update(['groupe_actif_id' => $groupe->id]);
        $groupe->update(['drapeaux_campagne' => [...(array) $groupe->drapeaux_campagne, self::DRAPEAU => true]]);
        if ($quete->participants() !== null) {
            $quete->update(['participants' => [...$quete->participants(), (int) $barde->id]]);
        }

        $grille = FabriqueGrille::pour($quete->fresh());
        $place = $grille->estTraversable($case['x'], $case['y']) ? $case : (CaseLibre::proche($grille, $case['x'], $case['y']) ?? $case);
        EtatPersonnageQuete::create([
            'quete_id' => $quete->id, 'personnage_id' => $barde->id,
            'position_x' => $place['x'], 'position_y' => $place['y'],
            'a_joue' => true, 'a_deplace' => true, 'a_agi' => true,
        ]);

        // « can immediately pick up any items held by the fallen hero » : au sac, sans action.
        $ramasses = 0;
        if (! $affairesPerdues) {
            $ramasses = $mort->inventaire()->update(['personnage_id' => $barde->id, 'emplacement' => 'sac']);
        }

        $payload = ['type' => 'barde_remplacant', 'mort' => (string) $mort->nom, 'personnage_id' => (int) $barde->id,
            'nom' => (string) $barde->nom, 'x' => $place['x'], 'y' => $place['y'], 'objets' => (int) $ramasses];
        app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'personnage', 'id' => $barde->id, 'nom' => $barde->nom]);

        return $payload;
    }
}
