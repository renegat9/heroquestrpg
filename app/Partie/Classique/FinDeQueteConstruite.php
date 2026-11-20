<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Bourses;
use App\Partie\TamponAnnonces;

/**
 * MODE CLASSIQUE — ce qu'une quête construite GAGNÉE règle (`docs/plan-mode-classique.md` §7). Appelé par
 * `ResolveurTour::terminerQuete()`, le point de passage unique d'une quête gagnée, et par lui seul.
 *
 * 1. LA CAMPAGNE AVANCE : `groupes.rang_quete` passe à la quête suivante. Une quête perdue ne passe pas par ici — le
 *    groupe la rejoue.
 * 2. LA RÉCOMPENSE de l'auteur (`quete.recompense`) est versée aux BOURSES DES HÉROS (décision de René, 2026-11-13 :
 *    bourse par héros en classique), jamais au pot commun :
 *    - `par_heros` : chaque héros actif reçoit le montant ;
 *    - `groupe` : le montant est partagé également entre les héros actifs ; le reste de la division va, une pièce
 *      chacun, aux premiers héros dans l'ordre du tour (décision écrite : le livret ne dit pas qui garde le reste).
 *    Elle est ANNONCÉE (`recompense_quete`) : un effet automatique que rien n'annonce est injouable.
 */
final class FinDeQueteConstruite
{
    /**
     * @return array{rang_suivant: int, recompense: list<array{personnage_id: int, nom: string, or: int}>}
     */
    public function regler(Groupe $groupe, Quete $quete): array
    {
        // QUÊTE DOUBLE (lot K5p) : gagnée depuis l'une ou l'autre moitié, c'est la PRINCIPALE qui dit la récompense, les
        // participants et la suite.
        $quete = $quete->principale();

        // Le document FIGÉ au départ de la quête (`quetes.document_construit`), jamais la campagne : l'auteur peut
        // l'avoir corrigée depuis, et la récompense promise à l'ouverture est celle qui se verse.
        $queteDoc = $quete->documentConstruit();

        $rangSuivant = $this->rangSuivant($groupe, $quete);
        $groupe->update(['rang_quete' => $rangSuivant]);

        // LE CAPTIF À ESCORTER (2026-11-20, livret de base q. 2 : « must be moved back to the stairs alive in order for the
        // heroes to collect the reward ») : pas ramené, pas de récompense — et on le dit.
        $captifPerdu = app(Escorte::class)->ramene($quete) === false;
        $parts = $captifPerdu ? [] : $this->parts($groupe, $queteDoc['recompense'] ?? null, $quete->participants());
        if ($captifPerdu && ($queteDoc['recompense'] ?? null) !== null) {
            app(TamponAnnonces::class)->annoncer($groupe, [
                'type' => 'recompense_perdue',
                'quete_id' => $quete->id,
                'raison' => 'captif',
            ]);
        }
        $bourses = app(Bourses::class);

        foreach ($parts as $part) {
            $bourses->verser($groupe, $part['or'], Personnage::find($part['personnage_id']));
        }

        // LA RÉPUTATION (lot K6, *Dread Moon* : « one reputation token at the end of each quest ») : `recompense.reputation`.
        $jetons = (int) data_get($queteDoc, 'recompense.reputation', 0);
        if ($jetons > 0) {
            app(Reputation::class)->ajuster($groupe, $jetons, 'fin_de_quete');
        }

        if ($parts !== []) {
            app(TamponAnnonces::class)->annoncer($groupe, [
                'type' => 'recompense_quete',
                'quete_id' => $quete->id,
                'parts' => $parts,
                'total' => array_sum(array_column($parts, 'or')),
            ]);
        }

        return ['rang_suivant' => $rangSuivant, 'recompense' => $parts];
    }

    /**
     * LA QUÊTE SUIVANTE (lot K5i, C2 — campagne ramifiée) : le premier embranchement de `quete.suite` dont le drapeau
     * est posé (`Quete::aDrapeau()`, quête puis campagne), ou qui n'en demande pas, décide — `quete` : le rang de cette
     * quête dans la campagne ; `fin` : au-delà de la dernière (la campagne est finie, le choix de campagne se rouvre).
     * Sans embranchement retenu : la quête suivante dans l'ordre. Le document FIGÉ de la quête dit les embranchements ;
     * la campagne actuelle dit les rangs (un slug disparu entre-temps retombe sur l'ordre).
     */
    public function rangSuivant(Groupe $groupe, Quete $quete): int
    {
        $quete = $quete->principale();
        $quetes = array_values((array) ($groupe->campagneConstruite?->document['quetes'] ?? []));
        // QUÊTE DOUBLE (lot K5p) : on n'entre dans une seconde moitié QUE par son passage — l'ordre linéaire la saute (une
        // `suite` ne peut pas la viser : la validation le refuse).
        $parDefaut = (int) $quete->position_arc + 1;
        while (isset($quetes[$parDefaut - 1]) && self::estSecondeMoitie($quetes, $parDefaut - 1)) {
            $parDefaut++;
        }

        foreach ((array) ($quete->documentConstruit()['suite'] ?? []) as $branche) {
            $condition = $branche['si_drapeau'] ?? null;
            if ($condition !== null && ! $quete->aDrapeau((string) $condition)) {
                continue;
            }
            if (($branche['fin'] ?? false) === true) {
                return count($quetes) + 1;
            }
            foreach ($quetes as $rang => $doc) {
                if (($doc['slug'] ?? null) === ($branche['quete'] ?? null)) {
                    return $rang + 1;
                }
            }

            return $parDefaut;
        }

        return $parDefaut;
    }

    /** La quête de ce rang (index 0) est-elle la SECONDE moitié d'une quête double (son autre moitié la précède) ? */
    private static function estSecondeMoitie(array $quetes, int $index): bool
    {
        $vise = data_get($quetes[$index], 'double.quete');
        foreach ($quetes as $j => $autre) {
            if ($j < $index && ($autre['slug'] ?? null) === $vise && data_get($autre, 'double.quete') === ($quetes[$index]['slug'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Qui reçoit combien. Une récompense absente, ou nulle, ne verse rien.
     *
     * @param  list<int>|null  $participants
     * @return list<array{personnage_id: int, nom: string, or: int}>
     */
    private function parts(Groupe $groupe, mixed $recompense, ?array $participants): array
    {
        // Quête SOLO (lot S1) : seuls les participants reçoivent — un spectateur « ne gagne rien ».
        return app(Bourses::class)->partager(
            $groupe,
            (int) data_get($recompense, 'or', 0),
            chacun: data_get($recompense, 'partage') === 'par_heros',
            parmi: $participants,
        );
    }
}
