<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * Les phrases du fil de combat pour les SABLES MOUVANTS (*The Mage of the Mirror*
 * p. 29) : le saut, l'abandon d'un objet, la sortie. Un TRAIT de
 * {@see JournalCombat} comme {@see LignesEffetsAutomatiques}, pour la même raison :
 * partager `info()` et le registre {@see JournalCombat::TYPES}, sans second
 * formateur qui oublierait un type.
 *
 * Les trois types sont produits par {@see MoteurSables} et déclarés dans
 * `JournalCombat::TYPES` : un saut raté qui enlise, un objet qui disparaît du sac
 * ou un héros qui tombe sans qu'une ligne le dise seraient des effets automatiques
 * muets.
 */
trait LignesSablesMouvants
{
    /** @return list<array{texte: string, ton: string}> */
    private function sautSables(array $a, string $acteurNom): array
    {
        $terrain = (string) ($a['terrain'] ?? 'les sables mouvants');
        $face = $this->faceDit((string) ($a['faces'][0] ?? ''));

        if (! empty($a['franchi'])) {
            return [[
                'texte' => "{$acteurNom} bondit par-dessus {$terrain} — {$face} : il se reçoit de l'autre côté",
                'ton' => 'succes',
            ]];
        }

        if (! empty($a['tombe'])) {
            return [
                ['texte' => "{$acteurNom} retombe dans {$terrain} — {$face} : il s'enlise", 'ton' => 'echec'],
                [
                    'texte' => "{$acteurNom} n'a pas de quoi payer les sables ("
                        .(int) ($a['objets_disponibles'] ?? 0).' objet seulement) : il sombre et tombe à 0 PV',
                    'ton' => 'chute',
                ],
            ];
        }

        $n = (int) ($a['a_defausser'] ?? 0);

        return [[
            'texte' => "{$acteurNom} retombe dans {$terrain} — {$face} : il s'enlise et doit abandonner {$n} objet".($n > 1 ? 's' : '').' pour ne pas sombrer',
            'ton' => 'echec',
        ]];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function defausseSables(array $a, string $acteurNom): array
    {
        $terrain = (string) ($a['terrain'] ?? 'les sables mouvants');

        if (! empty($a['tombe'])) {
            return [
                [
                    'texte' => ($a['objet'] ?? null) !== null
                        ? "{$acteurNom} abandonne {$a['objet']} aux sables — ce n'est pas assez"
                        : "{$acteurNom} n'a plus rien à abandonner aux sables",
                    'ton' => 'info',
                ],
                ['texte' => "{$acteurNom} sombre dans {$terrain} : il tombe à 0 PV", 'ton' => 'chute'],
            ];
        }

        $reste = (int) ($a['restantes'] ?? 0);

        return [[
            'texte' => "{$acteurNom} abandonne ".($a['objet'] ?? 'un objet')." aux {$terrain}"
                .($reste > 0
                    ? " — encore {$reste} à abandonner"
                    : ' — son tour s\'achève, il se hissera dehors au prochain'),
            'ton' => 'subit',
        ]];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function sortieSables(array $a, string $acteurNom): array
    {
        return [[
            'texte' => "{$acteurNom} se hisse hors de ".($a['terrain'] ?? 'les sables mouvants').', de l\'autre côté',
            'ton' => 'succes',
        ]];
    }

    /** La face d'un dé de combat, dite comme le joueur la voit. */
    private function faceDit(string $face): string
    {
        return match ($face) {
            'crane' => 'un crâne',
            'bouclier_blanc' => 'un bouclier blanc',
            'bouclier_noir' => 'un bouclier noir',
            default => 'un dé de combat',
        };
    }
}
