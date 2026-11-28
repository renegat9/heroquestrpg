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

    /** Le recrutement est-il ouvert : des mercenaires (`mercenaires`), ou des compagnons animaux offerts (`compagnons_animaux`) ? */
    public function ouvert(Groupe $groupe): bool
    {
        return $this->regle($groupe) !== null || $this->compagnonsOfferts($groupe) !== [];
    }

    /**
     * LES COMPAGNONS ANIMAUX OFFERTS (lot E2, *Jungles of Delthrak* p. 9 ; *Against the Ogre Horde* p. 8-9) : « Animal allies
     * are faithful companions who can be recruited at no cost to accompany a hero on a quest, so long as there are fewer than
     * four hero players. The animal ally must be recruited before the quest begins. […] A hero can control only one animal
     * ally at a time. » Clé de campagne `compagnons_animaux: {offerts: [slugs]}` (des animaux du catalogue des mercenaires).
     * Décision de René (2026-11-27) : comme le livret — avant CHAQUE quête, un animal gratuit PAR HÉROS, pour cette quête.
     *
     * @return list<string>
     */
    public function compagnonsOfferts(Groupe $groupe): array
    {
        if (! $groupe->estClassique()) {
            return [];
        }

        return array_values((array) data_get($groupe->campagneConstruite?->document, 'compagnons_animaux.offerts', []));
    }

    /** CE mercenaire est-il un compagnon animal que la campagne offre gratuitement, MAINTENANT (moins de quatre héros) ? */
    public function animalOffert(Groupe $groupe, Mercenaire $mercenaire): bool
    {
        return $mercenaire->animal && ! $mercenaire->octroi_seul
            && in_array(CatalogueConstructeur::slug((string) $mercenaire->nom), $this->compagnonsOfferts($groupe), true)
            && $groupe->personnages()->wherePivot('actif', true)->whereNull('personnages.mort_le')->count() < self::SEUIL_COMPAGNON;
    }

    /** « so long as there are fewer than four hero players ». */
    public const SEUIL_COMPAGNON = 4;

    /** Le recruteur a-t-il déjà un compagnon animal pour la prochaine quête ? « A hero can control only one animal ally at a time. » */
    public function aSonCompagnon(Groupe $groupe, \App\Models\Personnage $recruteur): bool
    {
        return GroupeMercenaire::where('groupe_id', $groupe->id)->where('etat', 'actif')->where('offert', true)
            ->where('recruteur_personnage_id', $recruteur->id)->where('paye_jusqu_au_rang', '>=', self::rangAPayer($groupe))
            ->whereHas('mercenaire', fn ($q) => $q->where('animal', true))->exists();
    }

    /**
     * Les compagnons offerts repartent : à la FIN d'une quête gagnée (`FinDeQueteConstruite`), et au départ d'une quête de
     * rang `$rang` ceux d'une quête plus ancienne (une quête perdue garde le sien pour la rejouer). Jamais un captif qui
     * persiste (`offert` aussi, mais pas un animal).
     */
    public function renvoyerLesCompagnons(Groupe $groupe, ?int $avantLeRang = null): void
    {
        if ($this->compagnonsOfferts($groupe) === []) {
            return;
        }
        GroupeMercenaire::where('groupe_id', $groupe->id)->where('offert', true)->whereNull('invoque_par_objet_id')
            ->whereHas('mercenaire', fn ($q) => $q->where('animal', true)->where('captif', false))
            ->when($avantLeRang !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('paye_jusqu_au_rang')->orWhere('paye_jusqu_au_rang', '<', $avantLeRang)))
            ->delete();
    }

    /** Ce mercenaire est-il offert par la campagne (et, en `debloques`, débloqué) ? Un compagnon animal offert l'est aussi. */
    public function offre(Groupe $groupe, Mercenaire $mercenaire): bool
    {
        if ($this->animalOffert($groupe, $mercenaire)) {
            return true;
        }
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
        // Les compagnons offerts d'une quête plus ancienne ne la suivent pas : chacun se reprend avant la quête (lot E2).
        $this->renvoyerLesCompagnons($groupe, $rang);
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
            // `gardien` (*Wizards of Morcar* p. 8) : « as long as they are paid 10 gold coins at the end of each quest ».
            $demi = $regle['regime'] === 'gardien' ? self::ENTRETIEN_GARDIEN : intdiv((int) $allie->mercenaire?->prix, 2);
            if (in_array($regle['regime'], ['retenu_moitie', 'debloques', 'gardien'], true) && $allie->recruteur !== null && $bourses->solde($groupe, $allie->recruteur) >= $demi) {
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

    /** *Wizards of Morcar* p. 8 : « paid 10 gold coins at the end of each quest. If they are not paid for their services, they will leave. » */
    public const ENTRETIEN_GARDIEN = 10;

    /** *Wizards of Morcar* p. 8 : « Wardens may each hire up to four followers at any time between quests. » */
    public const MAX_PAR_GARDIEN = 4;

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
