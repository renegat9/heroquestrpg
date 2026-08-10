<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * Formateur MÉCANIQUE du journal de combat (aucun LLM).
 *
 * Transforme le résultat d'un tour (App\Partie\ResolveurTour::resoudre) en
 * lignes courtes en français, diffusées à TOUTES les manettes via
 * App\Events\JournalCombatDiffuse. Comble le trou du « combat instantané » :
 * sans narration IA ni bark (table-only), un joueur de manette ne voyait que
 * ses PV bouger. Ce journal restitue attaques, dégâts, chutes, tours des
 * monstres/alliés et résultats de jets/fouilles — de façon purement dérivée
 * des payloads que le moteur journalise déjà. Les lignes d'attaque/sort portent
 * aussi le DÉTAIL DES DÉS (crânes touchés / boucliers parés — C1) quand le
 * payload les fournit, pour que chaque jet soit lisible sur la table.
 *
 * Chaque ligne : {texte, ton}. Le `ton` pilote l'icône/couleur côté manette
 * (voir resources/js/components/manette/ActionTab.vue) :
 *  - `degats`  : un héros/allié inflige des dégâts
 *  - `mort`    : une cible est vaincue
 *  - `subit`   : un héros encaisse des dégâts
 *  - `chute`   : un héros tombe (0 PV)
 *  - `pare`    : attaque parée (0 dégât)
 *  - `succes` / `echec` : issue d'un jet
 *  - `tresor`  : butin de fouille (or, potion, artefact)
 *  - `info`    : déplacement, effet neutre
 */
final class JournalCombat
{
    /**
     * @param  array<string, mixed>  $resultat  résultat moteur d'un tour
     * @return list<array{texte: string, ton: string}>
     */
    public function depuisResultat(array $resultat, string $acteurNom): array
    {
        $lignes = [];

        foreach ($this->ligneAction($resultat, $acteurNom) as $ligne) {
            $lignes[] = $ligne;
        }

        // Tour des alliés scriptés (3.5), puis tour des monstres (C2) — étalés.
        foreach (['tour_allies', 'tour_monstres'] as $phase) {
            foreach ($resultat[$phase]['actions'] ?? [] as $action) {
                if (! is_array($action)) {
                    continue;
                }
                foreach ($this->ligneAction($action, $acteurNom) as $ligne) {
                    $lignes[] = $ligne;
                }
            }
        }

        return $lignes;
    }

    /**
     * Une action (héros, allié ou monstre) → 0..2 lignes.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function ligneAction(array $a, string $acteurNom): array
    {
        $lignes = $this->ligneType($a, $acteurNom);

        // Piège IMBRIQUÉ (`declenchement`) : coffre piégé, désamorçage raté,
        // franchissement raté. Aucun de ces payloads n'affichait quoi que ce
        // soit — le héros perdait des PV sans la moindre ligne.
        if (is_array($a['declenchement'] ?? null)) {
            foreach ($this->piegeDeclenche($a['declenchement'], $acteurNom) as $ligne) {
                $lignes[] = $ligne;
            }
        }

        // …et les pièges marchés PENDANT un déplacement, qui arrivent sous une
        // clé DIFFÉRENTE et au PLURIEL (`pieges_declenches`, un chemin pouvant
        // en croiser plusieurs). Le correctif précédent n'avait couvert que le
        // singulier : un héros tombait dans une fosse, perdait ses PV et se
        // retrouvait immobilisé sans une seule ligne au fil du combat, alors
        // que le coffre piégé de son compagnon, lui, était bien journalisé
        // (test de jeu 2026-08-05 — Krogar, Fosse en 25,42, −1 PV muet).
        foreach ((array) ($a['pieges_declenches'] ?? []) as $declenchement) {
            if (! is_array($declenchement)) {
                continue;
            }

            foreach ($this->piegeDeclenche($declenchement, $acteurNom) as $ligne) {
                $lignes[] = $ligne;
            }
        }

        return $lignes;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function ligneType(array $a, string $acteurNom): array
    {
        return match ($a['type'] ?? null) {
            'attaque' => $this->attaqueHeros($a, $acteurNom),
            'sort', 'parchemin' => $this->sort($a, $acteurNom),
            'jet' => $this->jet($a, $acteurNom),
            'desamorcage' => $this->desamorcage($a, $acteurNom),
            'franchissement' => $this->issueSimple($a, $acteurNom, 'franchit la fosse', 'chute dans la fosse'),
            'relever' => [$this->info(($a['libelle'] ?? "{$acteurNom} relève un compagnon"))],
            'attaque_allie' => $this->attaqueOffensive($a['allie'] ?? 'Allié', $a),
            'attaque_monstre' => $this->attaqueMonstre($a),
            'fouille_tresor', 'fouille_mobilier' => $this->fouille($a, $acteurNom),
            'piege_declenche' => $this->piegeDeclenche($a, $acteurNom),
            'monstre_saute_tour' => [$this->info(($a['monstre'] ?? 'Le monstre').' est pris dans la tempête — il passe son tour')],
            'monstre_endormi' => [$this->info(($a['monstre'] ?? 'Le monstre').' dort')],
            'heros_endormi' => [$this->info(($a['personnage'] ?? $acteurNom).' est endormi — tour sauté')],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function attaqueHeros(array $a, string $acteurNom): array
    {
        return $this->attaqueOffensive($acteurNom, $a);
    }

    /**
     * Attaque d'un héros OU d'un allié contre un monstre (même forme de payload).
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function attaqueOffensive(string $attaquant, array $a): array
    {
        $cible = $a['cible']['nom'] ?? 'la cible';
        $degats = (int) ($a['degats'] ?? 0);
        $des = $this->detailDes($a);

        if (! empty($a['cible_vaincue'])) {
            return [['texte' => "{$attaquant} terrasse {$cible} !{$des}", 'ton' => 'mort']];
        }
        if ($degats > 0) {
            return [['texte' => "{$attaquant} touche {$cible} (−{$degats} PV){$des}", 'ton' => 'degats']];
        }

        return [['texte' => "{$cible} pare l'assaut de {$attaquant}{$des}", 'ton' => 'pare']];
    }

    /**
     * Détail des dés (correctifs C1) : « · 3 ⚔ / 1 🛡 » — crânes touchés vs
     * boucliers du défenseur, quand le payload les porte (attaques et sorts de
     * dégâts). Vide sinon (jets, effets neutres).
     *
     * @param  array<string, mixed>  $a
     */
    private function detailDes(array $a): string
    {
        if (! array_key_exists('touches', $a) || ! array_key_exists('boucliers', $a)) {
            return '';
        }

        $touches = (int) $a['touches'];
        $boucliers = (int) $a['boucliers'];

        return " · {$touches} crâne".($touches > 1 ? 's' : '')
            ." / {$boucliers} bouclier".($boucliers > 1 ? 's' : '');
    }

    /**
     * Attaque d'un monstre contre un héros (le héros ENCAISSE).
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function attaqueMonstre(array $a): array
    {
        $monstre = $a['monstre'] ?? 'Le monstre';
        $cible = $a['cible']['nom'] ?? 'un héros';
        $degats = (int) ($a['degats'] ?? 0);
        $des = $this->detailDes($a);

        if ($degats <= 0) {
            return [['texte' => "{$cible} pare l'assaut de {$monstre}{$des}", 'ton' => 'pare']];
        }

        $lignes = [['texte' => "{$monstre} touche {$cible} (−{$degats} PV){$des}", 'ton' => 'subit']];
        if (! empty($a['cible_tombee'])) {
            $lignes[] = ['texte' => "{$cible} s'effondre !", 'ton' => 'chute'];
        }

        return $lignes;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function sort(array $a, string $acteurNom): array
    {
        $nom = $a['sort']['nom'] ?? 'un sort';
        $cible = $a['cible']['nom'] ?? null;
        $des = $this->detailDes($a);

        if (! empty($a['cible_vaincue'])) {
            return [['texte' => "{$acteurNom} foudroie {$cible} d'un {$nom} !{$des}", 'ton' => 'mort']];
        }

        $degats = (int) ($a['degats'] ?? 0);
        if ($degats > 0 && $cible !== null) {
            return [['texte' => "{$acteurNom} lance {$nom} sur {$cible} (−{$degats} PV){$des}", 'ton' => 'degats']];
        }

        // Un sort de DÉGÂTS qui n'en fait aucun a été PARÉ : il faut le dire, et
        // montrer les dés. La ligne se contentait de « Aldric lance Trait de Feu
        // sur X » — indiscernable d'un sort utilitaire, et le joueur ne pouvait
        // pas savoir s'il avait raté, été paré, ou rien fait du tout. Constaté
        // en test de jeu (2026-08-10) : deux Boules de Feu sur un troll, aucune
        // trace de ce qui s'était passé, là où l'attaque du monstre affichait
        // « (−2 PV) · 3 crânes / 1 bouclier ».
        if ($cible !== null && isset($a['faces_attaque'])) {
            return [['texte' => "{$cible} encaisse le {$nom} d'{$acteurNom} sans dommage{$des}", 'ton' => 'pare']];
        }

        $suffixe = $cible !== null ? " sur {$cible}" : '';

        return [['texte' => "{$acteurNom} lance {$nom}{$suffixe}{$des}", 'ton' => 'info']];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function jet(array $a, string $acteurNom): array
    {
        // Fouille de zone : restitue ce qui a été révélé (auparavant muet).
        if (($a['option_id'] ?? null) === 'fouiller') {
            if (empty($a['succes'])) {
                return [['texte' => "{$acteurNom} fouille la zone : rien", 'ton' => 'echec']];
            }
            $pieges = count($a['pieges_reveles'] ?? []);
            $portes = count($a['portes_revelees'] ?? []);
            $trouve = array_filter([
                $pieges > 0 ? "{$pieges} piège".($pieges > 1 ? 's' : '') : null,
                $portes > 0 ? "{$portes} passage".($portes > 1 ? 's' : '') : null,
            ]);

            return [[
                'texte' => $trouve === []
                    ? "{$acteurNom} fouille la zone : rien de suspect"
                    : "{$acteurNom} fouille : ".implode(' et ', $trouve).' !',
                'ton' => 'succes',
            ]];
        }

        $libelle = $a['libelle'] ?? 'un jet';

        return [[
            'texte' => "{$acteurNom} — {$libelle} : ".(! empty($a['succes']) ? 'réussi' : 'échoué'),
            'ton' => ! empty($a['succes']) ? 'succes' : 'echec',
        ]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function desamorcage(array $a, string $acteurNom): array
    {
        return $this->issueSimple($a, $acteurNom, 'désamorce le piège', 'déclenche le piège en le manipulant');
    }

    /**
     * « Fouiller — trésor » : une ligne par issue de carte. Le fil de combat
     * n'en affichait AUCUNE — un héros qui perdait 1 PV sur un coffre piégé
     * n'avait strictement aucune explication.
     *
     * L'issue `piege` ne produit rien ici : le payload imbriqué
     * `declenchement` est repris par piegeDeclenche() juste après.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function fouille(array $a, string $acteurNom): array
    {
        $lignes = match ($a['issue'] ?? null) {
            'tresor' => [[
                'texte' => "{$acteurNom} déniche ".(int) ($a['or'] ?? 0).' pièces d\'or',
                'ton' => 'tresor',
            ]],
            'potion' => [[
                'texte' => "{$acteurNom} trouve ".($a['objet']['nom'] ?? 'une potion'),
                'ton' => 'tresor',
            ]],
            'artefact' => [[
                'texte' => "{$acteurNom} met la main sur ".($a['objet']['nom'] ?? 'un artefact').' !',
                'ton' => 'tresor',
            ]],
            'errant' => [[
                'texte' => ($a['monstre']['nom'] ?? 'Un monstre').' surgit du coffre !',
                'ton' => 'subit',
            ]],
            'piege' => [],
            default => [$this->info("{$acteurNom} fouille en vain")],
        };

        if (! empty($a['sac_deborde'])) {
            $lignes[] = $this->info('Sac plein : '.($a['objet']['nom'] ?? 'l\'objet').' déborde — à équiper ou à écouler au marché');
        }

        return $lignes;
    }

    /**
     * Piège déclenché — coffre piégé (`ephemere`) ET pièges de couloir, qui
     * étaient muets eux aussi.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function piegeDeclenche(array $a, string $acteurNom): array
    {
        $nom = $a['personnage']['nom'] ?? $acteurNom;
        $piege = $a['piege']['nom'] ?? 'Un piège';
        $degats = (int) ($a['degats'] ?? 0);

        $lignes = [['texte' => "{$piege} se déclenche sur {$nom} !", 'ton' => 'subit']];

        if ($degats > 0) {
            $lignes[] = [
                'texte' => "{$nom} encaisse −{$degats} PV",
                'ton' => ! empty($a['tombe']) ? 'chute' : 'degats',
            ];
        }

        if (! empty($a['condition_appliquee'])) {
            $lignes[] = ['texte' => "{$nom} est ".$a['condition_appliquee'], 'ton' => 'echec'];
        }

        if ($degats === 0 && empty($a['condition_appliquee'])) {
            $lignes[] = $this->info("{$nom} s'en tire sans une égratignure");
        }

        return $lignes;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function issueSimple(array $a, string $acteurNom, string $reussite, string $echec): array
    {
        $ok = ! empty($a['succes']) || ($a['issue'] ?? null) === 'reussi';

        return [[
            'texte' => $acteurNom.' '.($ok ? $reussite : $echec),
            'ton' => $ok ? 'succes' : 'echec',
        ]];
    }

    /** @return array{texte: string, ton: string} */
    private function info(string $texte): array
    {
        return ['texte' => $texte, 'ton' => 'info'];
    }
}
