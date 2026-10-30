<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * Les phrases du fil de combat pour la FOSSE LONGUE (*The Mage of the Mirror* F7539 p. 6) : la sortie hors du trou,
 * réussie ou non. Un TRAIT de {@see JournalCombat}, comme {@see LignesSablesMouvants} — pour partager `info()` et le
 * registre {@see JournalCombat::TYPES}, sans second formateur qui oublierait un type.
 *
 * Le type `sortie_fosse` est produit par {@see MoteurFosseLongue::resoudreSortie()} et déclaré dans
 * `JournalCombat::TYPES`. La chute et le malus n'ont pas de type à eux : ils se disent dans la ligne du piège
 * (`JournalCombat::piegeDeclenche()`), et le saut par le bout est un `franchissement` (`saut: long`).
 */
trait LignesFosseLongue
{
    /** Le héros se hisse hors du trou, ou n'y parvient pas : un dé rouge, le seuil publié par l'action. */
    private function sortieFosse(array $a, string $acteurNom): array
    {
        $trou = (string) ($a['piege'] ?? 'la fosse');
        $de = (int) ($a['de'] ?? 0);

        if (! empty($a['succes'])) {
            return [['texte' => "{$acteurNom} se hisse hors de {$trou} (dé {$de})", 'ton' => 'succes']];
        }

        $sur = implode(' ou ', array_map('intval', (array) ($a['sortie_sur'] ?? [])));

        return [['texte' => "{$acteurNom} n'arrive pas à se hisser hors de {$trou} (dé {$de}, il faut un {$sur})", 'ton' => 'echec']];
    }
}
