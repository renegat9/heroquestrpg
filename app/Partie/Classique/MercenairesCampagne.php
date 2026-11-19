<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Quete;
use App\Partie\Bourses;
use App\Partie\TamponAnnonces;

/**
 * LES MERCENAIRES D'UNE CAMPAGNE CLASSIQUE (lot K6 ; René, 2026-11-19 : « chaque campagne choisit sa règle »).
 *
 * Clé de campagne `mercenaires: {regime, offerts}` :
 * - `par_quete` — *The Frozen Horror* p. 6 : « hired by a hero before a quest begins […] The gold must be paid before the
 *   quest begins. Mercenaries may be hired for any group quest in this quest pack, but not for the solo quests. » Payé
 *   pour UNE quête ; congédié au départ de la suivante s'il n'est pas engagé de nouveau.
 * - `retenu_moitie` — *Against the Ogre Horde* p. 8 : « Heroes may retain that mercenary's aid in following quests by
 *   paying half the mercenary's cost per quest. » Au départ d'une nouvelle quête, son recruteur paie la moitié du prix,
 *   ARRONDIE AU-DESSOUS (*Dread Moon* p. 11 l'écrit : « half the mercenary's cost (rounded down) » ; *Ogre Horde* ne dit
 *   pas), ou il part.
 * - `debloques` — *Rise of the Dread Moon* p. 8-11 : rendus disponibles par une note de quête (lot suivant, avec la
 *   réputation) ; gardés ensuite à moitié prix, comme `retenu_moitie`.
 * Jamais de statut de Gardien ni de plafond (« A hero can control as many Mercenaries as they can afford to hire ») ;
 * l'or vient de la bourse du recruteur (`Bourses`). Aucun allié n'entre dans une quête solo.
 *
 * Point de passage unique : lu par `RecrutementHub` (qui peut recruter, quoi, à quel prix) et par le départ d'une quête
 * construite (`auDepart()`, puis `PoseDesAllies`).
 */
final class MercenairesCampagne
{
    /** @return array{regime: string, offerts: list<string>}|null */
    public function regle(Groupe $groupe): ?array
    {
        if (! $groupe->estClassique()) {
            return null;
        }
        $regle = data_get($groupe->campagneConstruite?->document, 'mercenaires');

        return is_array($regle) ? $regle : null;
    }

    public function ouvert(Groupe $groupe): bool
    {
        return $this->regle($groupe) !== null;
    }

    /** Ce mercenaire est-il offert par la campagne (et, en `debloques`, débloqué) ? */
    public function offre(Groupe $groupe, Mercenaire $mercenaire): bool
    {
        $regle = $this->regle($groupe);
        if ($regle === null) {
            return false;
        }
        $slug = CatalogueConstructeur::slug((string) $mercenaire->nom);

        return in_array($slug, (array) $regle['offerts'], true)
            && ($regle['regime'] !== 'debloques' || in_array($slug, $this->debloques($groupe), true));
    }

    /** Les mercenaires débloqués d'une campagne `debloques` (`groupes.drapeaux_campagne._mercenaires`). @return list<string> */
    public function debloques(Groupe $groupe): array
    {
        return array_values((array) data_get($groupe->drapeaux_campagne, '_mercenaires', []));
    }

    /**
     * Au DÉPART d'une quête de rang `$rang` : chaque allié recruté qui n'est pas payé pour elle est congédié
     * (`par_quete`), ou renouvelé à moitié prix sur la bourse de son recruteur (`retenu_moitie`). Annoncé au fil.
     */
    public function auDepart(Groupe $groupe, int $rang): void
    {
        $regle = $this->regle($groupe);
        if ($regle === null) {
            return;
        }
        $bourses = app(Bourses::class);

        foreach (GroupeMercenaire::where('groupe_id', $groupe->id)->where('etat', 'actif')->where('offert', false)
            ->whereNull('invoque_par_objet_id')->with(['mercenaire', 'recruteur'])->orderBy('id')->get() as $allie) {
            if ((int) ($allie->paye_jusqu_au_rang ?? 0) >= $rang) {
                continue;
            }
            $demi = intdiv((int) $allie->mercenaire?->prix, 2);
            if (in_array($regle['regime'], ['retenu_moitie', 'debloques'], true) && $allie->recruteur !== null && $bourses->solde($groupe, $allie->recruteur) >= $demi) {
                $bourses->payer($groupe, $demi, $allie->recruteur);
                $allie->update(['paye_jusqu_au_rang' => $rang]);
                app(TamponAnnonces::class)->annoncer($groupe, ['type' => 'mercenaire_retenu', 'mercenaire' => $allie->mercenaire?->nom,
                    'nom' => $allie->recruteur->nom, 'or' => $demi]);

                continue;
            }
            app(TamponAnnonces::class)->annoncer($groupe, ['type' => 'mercenaire_congedie', 'mercenaire' => $allie->mercenaire?->nom,
                'nom' => $allie->recruteur?->nom]);
            $allie->delete();
        }
    }

    /** Débloque un mercenaire (régime `debloques`, campagne) ; rend son nom, ou `null` s'il n'est pas offert. */
    public function debloquer(Groupe $groupe, string $slug): ?string
    {
        $regle = $this->regle($groupe);
        if ($regle === null || ! in_array($slug, (array) $regle['offerts'], true)) {
            return null;
        }
        $drapeaux = (array) $groupe->fresh()->drapeaux_campagne;
        $drapeaux['_mercenaires'] = array_values(array_unique([...(array) ($drapeaux['_mercenaires'] ?? []), $slug]));
        $groupe->update(['drapeaux_campagne' => $drapeaux]);

        return (string) (app(CatalogueConstructeur::class)->index('mercenaires')[$slug]->nom ?? $slug);
    }

    /** Embauché contre un jeton : « remains with the hero […] in following quests, no payment required ». */
    public const PAYE_A_VIE = 65535;

    /** Le rang de la quête que le prochain départ ouvrira : celui pour lequel on engage au hub. */
    public static function rangAPayer(Groupe $groupe): int
    {
        return (int) $groupe->rang_quete;
    }

    /** La prochaine quête est-elle une quête SOLO ? Alors aucun allié n'y entre, et rien ne s'engage pour elle. */
    public static function prochaineSolo(Groupe $groupe): bool
    {
        $doc = (array) ($groupe->campagneConstruite?->document['quetes'][(int) $groupe->rang_quete - 1] ?? []);

        return data_get($doc, 'regles.solo') !== null;
    }

    /** Un hook à `Quete` près : ne sert qu'à garder la signature lisible pour l'appelant. */
    public function poserPourLaQuete(Groupe $groupe, Quete $quete, array $spawn, int $premierLibre): void
    {
        if (! $this->ouvert($groupe) || $quete->participants() !== null) {
            return;
        }
        app(\App\Partie\PoseDesAllies::class)->poser($groupe, $quete, $spawn, $premierLibre);
    }
}
