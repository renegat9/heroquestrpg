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
        $drapeaux = (array) ($quete->drapeaux ?? []);
        $drapeaux[self::DRAPEAU] = $this->surLaSortie($quete);
        $quete->update(['drapeaux' => $drapeaux]);
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
