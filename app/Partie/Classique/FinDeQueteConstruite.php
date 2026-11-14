<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
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
        $document = (array) ($groupe->campagneConstruite?->document ?? []);
        $queteDoc = (array) ($document['quetes'][(int) $quete->position_arc - 1] ?? []);

        $rangSuivant = (int) $quete->position_arc + 1;
        $groupe->update(['rang_quete' => $rangSuivant]);

        $parts = $this->parts($groupe, $queteDoc['recompense'] ?? null);

        foreach ($parts as $part) {
            Personnage::whereKey($part['personnage_id'])->increment('or', $part['or']);
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
     * @return list<array{personnage_id: int, nom: string, or: int}>
     */
    private function parts(Groupe $groupe, mixed $recompense): array
    {
        $or = (int) data_get($recompense, 'or', 0);
        if ($or <= 0) {
            return [];
        }

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get(['personnages.id', 'personnages.nom']);

        if ($heros->isEmpty()) {
            return [];
        }

        $parHeros = data_get($recompense, 'partage') === 'par_heros';
        $base = $parHeros ? $or : intdiv($or, $heros->count());
        $reste = $parHeros ? 0 : $or % $heros->count();

        $parts = [];
        foreach ($heros->values() as $i => $h) {
            $montant = $base + ($i < $reste ? 1 : 0);
            if ($montant > 0) {
                $parts[] = ['personnage_id' => (int) $h->id, 'nom' => (string) $h->nom, 'or' => $montant];
            }
        }

        return $parts;
    }
}
