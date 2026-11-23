<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Quete;

/**
 * LE CAPTIF À ESCORTER (René, 2026-11-20 : « fait en sorte que ça fonctionne » — Sir Ragnar, livret de base q. 2) — point
 * de passage unique de `quete.captif` : `{mercenaire: slug, x, y}`, un captif du catalogue des mercenaires (`captif`,
 * mode figurine).
 *
 * Le mécanisme des captifs du mode RPG, posé par l'auteur : au départ, la figure attend à sa case (`etat: captif`) ; un
 * héros au contact la LIBÈRE (`liberer_captif`) et elle devient SON allié — elle joue juste après lui, à sa vitesse
 * (`GroupeMercenaire::vitesse()` : un dé rouge pour Sir Ragnar), les monstres peuvent l'attaquer, elle meurt à 0 Corps.
 *
 * LA RÉCOMPENSE, comme le livret : « Sir Ragnar must be moved back to the stairs alive in order for the heroes to collect
 * the reward ». Quand le dernier héros quitte le donjon, on CONSTATE si le captif, vivant, se tient sur une case de sortie
 * ({@see constater()}, drapeau réservé `_captif_ramene`, état durable) ; la quête est gagnée dans tous les cas, mais
 * `FinDeQueteConstruite` ne verse la récompense que s'il l'est (et l'annonce sinon).
 */
final class Escorte
{
    /** Drapeau RÉSERVÉ de la quête : le captif a-t-il été ramené ? (posé à la sortie du dernier héros) */
    public const DRAPEAU = '_captif_ramene';

    /** Au départ : le captif de l'auteur attend à sa case, à libérer. */
    public function poser(Quete $quete): void
    {
        $doc = data_get($quete->documentConstruit(), 'captif');
        if (! is_array($doc) || ! isset($doc['mercenaire'], $doc['x'], $doc['y'])) {
            return;
        }
        $mercenaire = app(CatalogueConstructeur::class)->index('mercenaires')[(string) $doc['mercenaire']] ?? null;
        if (! $mercenaire instanceof Mercenaire) {
            return;
        }
        $captif = GroupeMercenaire::create([
            'groupe_id' => $quete->groupe_id,
            'mercenaire_id' => $mercenaire->id,
            'pv_body' => (int) $mercenaire->pv_body,
            'position_x' => (int) $doc['x'],
            'position_y' => (int) $doc['y'],
            'etat' => 'captif',
        ]);
        $quete->update(['captif_mercenaire_id' => $captif->id]);
    }

    /** La quête a-t-elle un captif d'auteur ? */
    public function aUnCaptif(Quete $quete): bool
    {
        return is_array(data_get($quete->documentConstruit(), 'captif'));
    }

    /** Vivant, libéré, et sur une case de sortie MAINTENANT ? */
    public function surLaSortie(Quete $quete): bool
    {
        $captif = $quete->captif;
        if ($captif === null || $captif->etat !== 'actif' || $captif->position_x === null) {
            return false;
        }
        foreach ($quete->sortieDuDonjon()['cases'] as $case) {
            if ((int) $case['x'] === (int) $captif->position_x && (int) $case['y'] === (int) $captif->position_y) {
                return true;
            }
        }

        return false;
    }

    /** Le dernier héros sort : on constate, une fois pour toutes, si le captif est ramené. */
    public function constater(Quete $quete): void
    {
        if (! $this->aUnCaptif($quete)) {
            return;
        }
        $drapeaux = (array) ($quete->relire('drapeaux')->drapeaux ?? []);
        $drapeaux[self::DRAPEAU] = $this->surLaSortie($quete);
        $quete->update(['drapeaux' => $drapeaux]);
    }

    /**
     * Lot E1 — UNE NOTE le libère (« If the Barbarian kills the Dread Warriors, Gothar is freed », F5815 q. 3) : il
     * devient l'allié du héros qui déclenche (ou du premier de l'ordre du tour), comme libéré au contact. Rend son nom,
     * ou `null` s'il n'attend plus.
     */
    public function liberer(Quete $quete, ?\App\Models\Personnage $heros): ?string
    {
        $captif = $quete->captif()->with('mercenaire')->first();
        $liberateur = $heros ?? $quete->groupe->personnages()->wherePivot('actif', true)->orderBy('groupe_personnages.ordre_initiative')->first();
        if ($captif === null || $captif->etat !== 'captif' || $liberateur === null) {
            return null;
        }
        $escorte = $captif->mercenaire?->estCaptifEscorte() ?? false;
        $captif->update(['etat' => $escorte ? 'porte' : 'actif', 'recruteur_personnage_id' => $liberateur->id]);
        if ($captif->mercenaire !== null) {
            app(NotesDeQuete::class)->signaler($quete, \App\Engine\MotsClesQuete::CAPTIF_LIBERE,
                ['captif' => CatalogueConstructeur::slug((string) $captif->mercenaire->nom)], $liberateur);
        }

        return $captif->mercenaire?->nom;
    }

    /**
     * Lot E1 — sa MORT fait-elle échouer la quête ? Seulement si l'auteur l'écrit (`captif.echec_si_mort` : « If Udren dies
     * before the heroes identify her, the heroes fail the quest! », G0053 q. 5). Sans cela, un captif mort retire la
     * récompense (Sir Ragnar), il ne fait pas perdre la quête.
     */
    public function echoueSiMort(Quete $quete): bool
    {
        return data_get($quete->documentConstruit(), 'captif.echec_si_mort') === true;
    }

    /**
     * Lot E1 — la quête se termine : le captif repart avec elle, sauf s'il PERSISTE (`captif.persiste` : Sigill, Udren —
     * « the heroes should control Udren, if she is alive », G0053 q. 6). Persistant et vivant, il reste un allié du groupe,
     * marqué `offert` (aucun entretien de mercenaire ne le congédie : `MercenairesCampagne::auDepart()`).
     */
    public function congedierEnFinDeQuete(Quete $quete): void
    {
        $captif = $quete->captif;
        if ($captif === null) {
            return;
        }
        if (data_get($quete->documentConstruit(), 'captif.persiste') === true && in_array($captif->etat, ['actif', 'porte'], true)) {
            $captif->update(['etat' => 'actif', 'offert' => true, 'position_x' => null, 'position_y' => null]);

            return;
        }
        $captif->delete();
    }

    /**
     * Lot E1 — au DÉPART d'une quête : un captif d'une quête précédente qui ne persiste pas (quête perdue, rejouée) n'a
     * rien à faire dans celle-ci. Un persistant est `offert` : il reste.
     */
    public function retirerLesRestes(\App\Models\Groupe $groupe): void
    {
        GroupeMercenaire::where('groupe_id', $groupe->id)->where('offert', false)
            ->whereHas('mercenaire', fn ($q) => $q->where('captif', true))
            ->delete();
    }

    /** `null` sans captif ; sinon, a-t-il été ramené (constaté à la sortie du dernier héros) ? */
    public function ramene(Quete $quete): ?bool
    {
        if (! $this->aUnCaptif($quete)) {
            return null;
        }

        return (bool) (((array) ($quete->drapeaux ?? []))[self::DRAPEAU] ?? false);
    }
}
