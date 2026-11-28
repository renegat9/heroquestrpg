<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Salles;
use Illuminate\Validation\ValidationException;

/**
 * LES GESTES DE QUÊTE — lot E2, *Spirit Queen's Torment* (G0053) : une ACTION que l'auteur offre aux héros dans une salle.
 * - q. 8 B : « The fireplace glows with spectral blue flames. Any hero can spend an action here to restore all of their lost
 *   Mind Points. Each hero can benefit from this effect only once. » → `par_heros: true`, effet `soigner {mind: tout}` ;
 * - q. 7 D : trois gemmes à poser dans l'ordre de l'arc-en-ciel, « If done incorrectly, bolts of lightning strike each hero,
 *   inflicting 2 Body Points of damage » → `choix` (un par ordre), le bon pose un drapeau qui ferme le geste
 *   (`sauf_drapeau`). Réessai permis (René, 2026-11-27) : un mauvais ordre frappe, le geste reste.
 *
 * `quete.gestes[]` : `{libelle, salle, par_heros?, si_drapeau?, sauf_drapeau?, alors | choix: [{libelle, alors}]}`. Offert à un
 * héros debout dans la salle, qui n'a pas encore agi ; il coûte l'ACTION (créneau par défaut). Ses effets se jouent comme
 * ceux d'une note (`NotesDeQuete::jouerEffets()`), sous le repère « geste ». État durable : `quetes.drapeaux._gestes`
 * (`{index: [id des héros qui l'ont fait]}`), jamais le cache. Le menu n'offre que `disponibles()`, que le résolveur relit.
 */
final class GestesDeQuete
{
    private const ETAT = '_gestes';

    /**
     * Les gestes que ce héros peut faire MAINTENANT, un par choix : `{geste, choix, libelle}`.
     *
     * @return list<array{geste: int, choix: int|null, libelle: string}>
     */
    public function disponibles(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat): array
    {
        if (! $quete->estConstruite() || $etat->tombe || $etat->position_x === null) {
            return [];
        }
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $ici = Salles::indexDe($salles, (int) $etat->position_x, (int) $etat->position_y);
        if ($ici === null) {
            return [];
        }
        $faits = (array) data_get($quete->drapeaux, self::ETAT, []);
        $offerts = [];

        foreach ((array) ($quete->documentConstruit()['gestes'] ?? []) as $i => $geste) {
            if (($salles[$ici]['id'] ?? null) !== ($geste['salle'] ?? null)
                || (isset($geste['si_drapeau']) && ! $quete->aDrapeau((string) $geste['si_drapeau']))
                || (isset($geste['sauf_drapeau']) && $quete->aDrapeau((string) $geste['sauf_drapeau']))
                || (! empty($geste['par_heros']) && in_array((int) $heros->id, (array) ($faits[$i] ?? []), true))
                || (isset($geste['pres_du_meuble']) && ! $this->auContactDuMeuble($quete, $etat, (int) $geste['pres_du_meuble']))) {
                continue;
            }
            if (is_array($geste['choix'] ?? null)) {
                foreach ($geste['choix'] as $k => $choix) {
                    $offerts[] = ['geste' => (int) $i, 'choix' => (int) $k, 'libelle' => $geste['libelle'].' : '.$choix['libelle']];
                }
            } else {
                $offerts[] = ['geste' => (int) $i, 'choix' => null, 'libelle' => (string) $geste['libelle']];
            }
        }

        return $offerts;
    }

    /** Lot E2 (*First Light* q. 3 B : « an adjacent bookcase ») : le héros touche-t-il une case de ce meuble (debout) ? */
    private function auContactDuMeuble(Quete $quete, EtatPersonnageQuete $etat, int $index): bool
    {
        $m = (array) ($quete->carte?->grille['mobilier'][$index] ?? []);
        if ($m === [] || ! empty($m['detruit']) || ! isset($m['x'], $m['y'])) {
            return false;
        }
        foreach (Salles::cases(['x' => (int) $m['x'], 'y' => (int) $m['y'], 'l' => max(1, (int) ($m['l'] ?? 1)), 'h' => max(1, (int) ($m['h'] ?? 1))]) as $c) {
            if (\App\Partie\Grille::sontAdjacentes((int) $etat->position_x, (int) $etat->position_y, $c['x'], $c['y'], carte: $quete->carte)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le héros fait le geste (et le choix) : revalidé contre `disponibles()`, noté, puis ses effets se jouent.
     *
     * @return array<string, mixed> le résultat moteur `geste_de_quete`
     */
    public function faire(Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, int $geste, ?int $choix): array
    {
        $offert = collect($this->disponibles($quete, $heros, $etat))
            ->first(fn (array $o) => $o['geste'] === $geste && $o['choix'] === $choix)
            ?? throw ValidationException::withMessages(['option_id' => 'Ce geste n\'est pas à faire ici.']);

        $drapeaux = (array) $quete->fresh()->drapeaux;
        $drapeaux[self::ETAT][$geste] = array_values(array_unique([...(array) ($drapeaux[self::ETAT][$geste] ?? []), (int) $heros->id]));
        $quete->update(['drapeaux' => $drapeaux]);

        $doc = (array) ($quete->documentConstruit()['gestes'][$geste] ?? []);
        $effets = $choix === null ? (array) ($doc['alors'] ?? []) : (array) ($doc['choix'][$choix]['alors'] ?? []);
        $note = app(NotesDeQuete::class)->jouerEffets($quete->fresh(), $effets, 'geste', $heros);

        return ['type' => 'geste_de_quete', 'personnage_id' => (int) $heros->id, 'nom' => (string) $heros->nom,
            'libelle' => $offert['libelle'], 'note' => $note];
    }
}
