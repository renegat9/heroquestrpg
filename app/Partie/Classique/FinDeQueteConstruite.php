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
        // Le document FIGÉ au départ de la quête (`quetes.document_construit`), jamais la campagne : l'auteur peut
        // l'avoir corrigée depuis, et la récompense promise à l'ouverture est celle qui se verse.
        $queteDoc = $quete->documentConstruit();

        $rangSuivant = (int) $quete->position_arc + 1;
        $groupe->update(['rang_quete' => $rangSuivant]);

        $parts = $this->parts($groupe, $queteDoc['recompense'] ?? null, $quete->participants());
        $bourses = app(Bourses::class);

        foreach ($parts as $part) {
            $bourses->verser($groupe, $part['or'], Personnage::find($part['personnage_id']));
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
