<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Bourses;
use App\Partie\TamponAnnonces;

/**
 * LA RÉPUTATION ET LES OFFRES (mode classique, lot K6 — *Rise of the Dread Moon* p. 8 ; René, 2026-11-19 : « tout »).
 *
 * « Zargon will award one reputation token at the end of each quest for the players to share. These tokens can be
 * redeemed at various times. Zargon must declare when the heroes have the opportunity to spend a reputation token, per the
 * quest notes. Zargon does not have to reveal what the heroes are spending it on until they have spent it. […] Heroes may
 * also spend one reputation token to hire an elven mercenary (if one is available to them) instead of paying their listed
 * cost. »
 *
 * - Les jetons : `groupes.jetons_reputation`, le compteur du GROUPE (« to share ») — gagnés par la récompense d'une quête
 *   (`recompense.reputation`) ou un effet de note `reputation`, dépensés par une OFFRE ou une embauche.
 * - Une OFFRE (effet de note `offre`) est le choix que l'auteur déclare : un libellé (neutre, si l'auteur le veut — « does
 *   not have to reveal »), un coût (`or` de la bourse du héros, `reputation` du groupe) et des effets joués quand un héros
 *   l'accepte. Les offres d'un même `groupe` s'excluent (« choose one elven mercenary to unlock »). État en base
 *   (`quetes.drapeaux._offres`), jamais le cache ; le menu n'en offre que de payables, le résolveur revalide.
 */
final class Reputation
{
    private const OFFRES = '_offres';

    public function jetons(Groupe $groupe): int
    {
        return (int) Groupe::whereKey($groupe->id)->value('jetons_reputation');
    }

    /** Ajoute (ou retire, jamais sous zéro) des jetons. Annoncé au fil. */
    public function ajuster(Groupe $groupe, int $n, string $raison): int
    {
        $apres = max(0, $this->jetons($groupe) + $n);
        Groupe::whereKey($groupe->id)->update(['jetons_reputation' => $apres]);
        $groupe->jetons_reputation = $apres;
        // Une NOTE dit déjà son gain dans sa propre ligne (`note_de_quete`) : pas de seconde annonce.
        if ($n !== 0 && $raison !== 'note') {
            app(TamponAnnonces::class)->annoncer($groupe, ['type' => 'reputation', 'variation' => $n, 'total' => $apres, 'raison' => $raison]);
        }

        return $apres;
    }

    // ─── Les offres ───────────────────────────────────────────────────────────────────────────────────────────────

    /** Une note déclare une offre : elle devient disponible (une seule fois par note). */
    public function declarer(Quete $quete, array $offre, string $repere): void
    {
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::OFFRES][] = [
            'repere' => $repere,
            'libelle' => (string) ($offre['libelle'] ?? 'Une offre'),
            'cout' => (array) ($offre['cout'] ?? []),
            'alors' => array_values((array) ($offre['alors'] ?? [])),
            'groupe' => isset($offre['groupe']) ? (string) $offre['groupe'] : null,
            'prise' => false,
        ];
        $quete->update(['drapeaux' => $drapeaux]);
    }

    /**
     * Les offres que CE héros peut accepter maintenant : déclarées, non prises, payables (or de sa bourse, jetons du
     * groupe). Le menu et le résolveur lisent ceci. @return array<int, array<string, mixed>> indexées
     */
    public function payables(Quete $quete, Personnage $heros): array
    {
        $groupe = $quete->groupe;
        $libres = [];
        foreach ((array) data_get($quete->drapeaux, self::OFFRES, []) as $i => $offre) {
            if (! empty($offre['prise']) || $groupe === null) {
                continue;
            }
            $or = (int) ($offre['cout']['or'] ?? 0);
            $jetons = (int) ($offre['cout']['reputation'] ?? 0);
            if (app(Bourses::class)->solde($groupe, $heros) >= $or && $this->jetons($groupe) >= $jetons) {
                $libres[(int) $i] = $offre;
            }
        }

        return $libres;
    }

    /**
     * Le héros accepte l'offre `$index` : il paie, les offres de son groupe se ferment, ses effets se jouent. Rend le
     * résultat moteur.
     *
     * @return array<string, mixed>
     */
    public function accepter(Quete $quete, Personnage $heros, int $index): array
    {
        $offre = $this->payables($quete, $heros)[$index]
            ?? throw \Illuminate\Validation\ValidationException::withMessages(['option_id' => 'Cette offre n\'est plus à prendre.']);
        $groupe = $quete->groupe;

        app(Bourses::class)->payer($groupe, (int) ($offre['cout']['or'] ?? 0), $heros);
        if ((int) ($offre['cout']['reputation'] ?? 0) > 0) {
            $this->ajuster($groupe, -(int) $offre['cout']['reputation'], 'offre');
        }

        $drapeaux = (array) $quete->fresh()->drapeaux;
        foreach ((array) ($drapeaux[self::OFFRES] ?? []) as $i => $autre) {
            if ($i === $index || ($offre['groupe'] !== null && ($autre['groupe'] ?? null) === $offre['groupe'])) {
                $drapeaux[self::OFFRES][$i]['prise'] = true;
            }
        }
        $quete->update(['drapeaux' => $drapeaux]);

        $joue = app(NotesDeQuete::class)->jouerEffets($quete->fresh(), (array) $offre['alors'], (string) $offre['repere'], $heros);

        return ['type' => 'offre_prise', 'personnage_id' => (int) $heros->id, 'nom' => $heros->nom,
            'libelle' => $offre['libelle'], 'cout' => $offre['cout'], 'note' => $joue];
    }
}
