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
 * des payloads que le moteur journalise déjà.
 *
 * Chaque ligne : {texte, ton}. Le `ton` pilote l'icône/couleur côté manette
 * (voir resources/js/components/manette/ActionTab.vue) :
 *  - `degats`  : un héros/allié inflige des dégâts
 *  - `mort`    : une cible est vaincue
 *  - `subit`   : un héros encaisse des dégâts
 *  - `chute`   : un héros tombe (0 PV)
 *  - `pare`    : attaque parée (0 dégât)
 *  - `succes` / `echec` : issue d'un jet
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
        return match ($a['type'] ?? null) {
            'attaque' => $this->attaqueHeros($a, $acteurNom),
            'sort', 'parchemin' => $this->sort($a, $acteurNom),
            'jet' => $this->jet($a, $acteurNom),
            'desamorcage' => $this->desamorcage($a, $acteurNom),
            'franchissement' => $this->issueSimple($a, $acteurNom, 'franchit la fosse', 'chute dans la fosse'),
            'relever' => [$this->info(($a['libelle'] ?? "{$acteurNom} relève un compagnon"))],
            'attaque_allie' => $this->attaqueOffensive($a['allie'] ?? 'Allié', $a),
            'attaque_monstre' => $this->attaqueMonstre($a),
            'attaque_empechee' => [$this->info(($a['monstre'] ?? 'Le monstre').' ne peut pas frapper')],
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

        if (! empty($a['cible_vaincue'])) {
            return [['texte' => "{$attaquant} terrasse {$cible} !", 'ton' => 'mort']];
        }
        if ($degats > 0) {
            return [['texte' => "{$attaquant} touche {$cible} (−{$degats} PV)", 'ton' => 'degats']];
        }

        return [['texte' => "{$cible} pare l'assaut de {$attaquant}", 'ton' => 'pare']];
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

        if ($degats <= 0) {
            return [['texte' => "{$cible} pare l'assaut de {$monstre}", 'ton' => 'pare']];
        }

        $lignes = [['texte' => "{$monstre} touche {$cible} (−{$degats} PV)", 'ton' => 'subit']];
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

        if (! empty($a['cible_vaincue'])) {
            return [['texte' => "{$acteurNom} foudroie {$cible} d'un {$nom} !", 'ton' => 'mort']];
        }

        $degats = (int) ($a['degats'] ?? 0);
        if ($degats > 0 && $cible !== null) {
            return [['texte' => "{$acteurNom} lance {$nom} sur {$cible} (−{$degats} PV)", 'ton' => 'degats']];
        }

        $suffixe = $cible !== null ? " sur {$cible}" : '';

        return [['texte' => "{$acteurNom} lance {$nom}{$suffixe}", 'ton' => 'info']];
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
