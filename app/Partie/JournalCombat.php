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
 * Chaque ligne : {texte, ton, des?}. `des` porte le JET qui a produit la ligne
 * — les deux volées et la face gagnante de chacune — pour que le fil serve
 * d'historique consultable (manette : ActionTab.vue). Le `ton` pilote
 * l'icône/couleur côté manette
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

        // Les DÉS du jet, attachés à la ligne qui décrit le coup (la première :
        // les suivantes sont des conséquences — chute, piège imbriqué). C'est
        // ce qui donne l'HISTORIQUE : le fil garde ses jets, là où l'overlay de
        // la manette ne montrait le sien que 3 secondes, et seulement pour SA
        // propre action — un joueur n'avait jamais vu un seul dé du monstre qui
        // le frappait.
        $des = $this->desDuJet($a, $acteurNom);

        if ($des !== null && $lignes !== []) {
            $lignes[0]['des'] = $des;
        }

        return $lignes;
    }

    /**
     * Le jet complet d'une action — les deux volées, et QUELLE FACE compte pour
     * chacune.
     *
     * Les faces seules ne sont pas affichables : un dé n'est un succès que
     * relativement à qui le lance (voir `ResultatAttaque::pourJournal()`). Le
     * moteur publie donc `face_touchante` / `face_defensive` avec chaque
     * payload, et cette méthode ne fait que les recopier en y joignant les deux
     * NOMS — sans eux, la manette affichait deux rangées de dés sans dire
     * laquelle appartenait à qui.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>|null null si aucun dé n'a été lancé
     */
    private function desDuJet(array $a, string $acteurNom): ?array
    {
        $atk = array_values((array) ($a['faces_attaque'] ?? []));
        $def = array_values((array) ($a['faces_defense'] ?? []));

        if ($atk === [] && $def === []) {
            return null;
        }

        // Qui frappe : le monstre a son propre nom, l'allié aussi ; sinon c'est
        // le héros qui agit. Qui encaisse : toujours `cible.nom`.
        $attaquant = match ($a['type'] ?? null) {
            'attaque_monstre' => (string) ($a['monstre'] ?? 'Le monstre'),
            'attaque_allie' => (string) ($a['allie'] ?? 'Allié'),
            default => $acteurNom,
        };

        return [
            'atk' => $atk,
            'def' => $def,
            'touchante' => (string) ($a['face_touchante'] ?? 'crane'),
            'defensive' => (string) ($a['face_defensive'] ?? 'bouclier_blanc'),
            'attaquant' => $attaquant,
            'defenseur' => isset($a['cible']['nom']) ? (string) $a['cible']['nom'] : null,
            'touches' => isset($a['touches']) ? (int) $a['touches'] : null,
            'boucliers' => isset($a['boucliers']) ? (int) $a['boucliers'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function ligneType(array $a, string $acteurNom): array
    {
        return match ($a['type'] ?? null) {
            'attaque' => $this->attaqueHeros($a, $acteurNom),
            // Techniques du Moine : le fil doit dire ce que le style vient de
            // faire, sinon un Feu dépensé ressemblerait à un tour perdu.
            'style' => [$this->info(($a['technique'] ?? 'Une technique').' — '.$acteurNom.' prend sa garde')],
            'rayon' => $this->rayon($a, $acteurNom),
            'degat_differe' => [[
                'texte' => "{$acteurNom} embrase ".($a['cible']['nom'] ?? 'la cible')." (−{$a['degats']} PV) — la braise achèvera son œuvre",
                'ton' => 'degats',
            ]],
            'braise' => [[
                'texte' => ($a['monstre'] ?? 'La créature').' est consumée par la braise (−'.($a['degats'] ?? 0).' PV)'
                    .(! empty($a['vaincu']) ? ' — elle tombe !' : ''),
                'ton' => ! empty($a['vaincu']) ? 'mort' : 'degats',
            ]],
            // Frappe balayée : la ligne ANNONCE la salve, les frappes qui
            // suivent la détaillent cible par cible.
            'attaque_balayee' => [$this->info(
                "{$acteurNom} — ".($a['capacite'] ?? 'frappe balayée')." : {$a['cibles']} ennemi".
                (((int) ($a['cibles'] ?? 0)) > 1 ? 's' : '').' au contact',
            )],
            'sort', 'parchemin' => $this->sort($a, $acteurNom),
            // Le déplacement est MUET par principe (le fil raconterait chaque
            // pas). Une seule chose s'y dit : l'avertissement du *Sens du
            // piège*. À la table, Zargon prévient à voix haute — tout le monde
            // l'entend, mais aucune tuile n'est posée pour autant.
            'deplacement' => $this->alertePiege($a, $acteurNom),
            'jet' => $this->jet($a, $acteurNom),
            'desamorcage' => $this->desamorcage($a, $acteurNom),
            'franchissement' => $this->issueSimple($a, $acteurNom, 'franchit la fosse', 'chute dans la fosse'),
            'relever' => [$this->info(($a['libelle'] ?? "{$acteurNom} relève un compagnon"))],
            'attaque_allie' => $this->attaqueOffensive($a['allie'] ?? 'Allié', $a),
            'attaque_monstre' => $this->attaqueMonstre($a),
            'fouille_tresor', 'fouille_mobilier' => $this->fouille($a, $acteurNom),
            'piege_declenche' => $this->piegeDeclenche($a, $acteurNom),
            'monstre_saute_tour' => [$this->info(($a['monstre'] ?? 'Le monstre').' est pris dans la tempête — il passe son tour')],
            'monstre_paralyse' => [$this->info(($a['monstre'] ?? 'Le monstre').' est paralysé par la flamme — il ne peut ni bouger, ni frapper, ni parer')],
            'monstre_endormi' => [$this->info(($a['monstre'] ?? 'Le monstre').' dort')],
            'heros_endormi' => [$this->info(($a['personnage'] ?? $acteurNom).' est endormi — tour sauté')],
            // ⚠ LA MAGIE DU MJ ÉTAIT MUETTE. `sort_dread` n'avait aucun cas
            // ici et tombait au `default` : le boss lançait, un héros perdait
            // ses PV, et le fil du combat n'en disait pas un mot. C'est le même
            // défaut que le piège marché de 2026-08-05, et la même règle qu'il
            // enfreint — un effet automatique que rien n'annonce est injouable.
            'sort_dread' => $this->sortDread($a, $acteurNom),
            'sort_dread_annule' => [$this->info("{$acteurNom} amorce ".($a['sort'] ?? 'un sort').' — sans effet')],
            'rupture_sort_dread' => $this->ruptureSortDread($a),
            'tour_perdu' => [$this->info(($a['nom'] ?? 'Le héros').' est encore étourdi — il passe son tour')],
            'liberer_entraves' => [$this->info(
                ! empty($a['sur_soi'])
                    ? "{$acteurNom} s'arrache aux ronces"
                    : "{$acteurNom} taille les ronces qui retiennent ".($a['cible']['nom'] ?? 'son compagnon'),
            )],
            default => [],
        };
    }

    /**
     * Un sort de Dread — la seule famille d'actions qui puisse frapper cinq
     * héros, n'en frapper aucun, soigner, invoquer ou faire disparaître son
     * lanceur. Une ligne d'annonce, puis une ligne par victime.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function sortDread(array $a, string $acteurNom): array
    {
        $nom = $a['sort'] ?? 'un sort';
        $lignes = [];

        // Soin, invocation, réanimation, fuite : une seule ligne suffit, et
        // elle doit dire ce qui vient de changer sur le plateau.
        if (isset($a['soin'])) {
            $cible = ! empty($a['sur_soi']) ? 'lui-même' : ($a['cible']['nom'] ?? 'un des siens');

            return [$this->info("{$acteurNom} — {$nom} : soigne {$cible} (+{$a['soin']} PV)")];
        }

        if (isset($a['invoques'])) {
            $compte = count((array) $a['invoques']);

            return [[
                'texte' => $compte === 0
                    ? "{$acteurNom} — {$nom} : l'appel reste sans réponse"
                    : "{$acteurNom} — {$nom} : {$compte} créature".($compte > 1 ? 's' : '').' surgi'.($compte > 1 ? 'ssent' : 't'),
                'ton' => $compte === 0 ? 'echec' : 'degats',
            ]];
        }

        if (isset($a['releves'])) {
            $compte = count((array) $a['releves']);

            return [[
                'texte' => $compte === 0
                    ? "{$acteurNom} — {$nom} : aucun mort ne se relève"
                    : "{$acteurNom} — {$nom} : {$compte} mort".($compte > 1 ? 's' : '').' se relève'.($compte > 1 ? 'nt' : '').' !',
                'ton' => $compte === 0 ? 'echec' : 'mort',
            ]];
        }

        if (isset($a['vers'])) {
            return [$this->info("{$acteurNom} — {$nom} : il se dérobe et disparaît")];
        }

        $resultats = (array) ($a['resultats'] ?? []);

        // *Rouille* : la seule ligne du fil qui annonce une perte DÉFINITIVE.
        // Elle doit se lire comme telle — un joueur qui verrait « −1 dé » sans
        // savoir pourquoi chercherait la panne pendant trois tours.
        if (isset($resultats[0]['objet_detruit'])) {
            return [[
                'texte' => "{$nom} ronge ".($resultats[0]['cible']['nom'] ?? 'un héros')
                    .' : '.$resultats[0]['objet_detruit'].' tombe en poussière — définitivement',
                'ton' => 'mort',
            ]];
        }

        // ⚠ La ligne d'annonce n'apparaît qu'à partir de DEUX victimes : sur une
        // seule, elle doublerait la ligne suivante sans rien ajouter.
        if (count($resultats) > 1) {
            $lignes[] = $this->info("{$acteurNom} — {$nom} : ".count($resultats).' héros pris dans le sort');
        }

        foreach ($resultats as $r) {
            $cible = $r['cible']['nom'] ?? 'un héros';

            if (! empty($r['absorbe'])) {
                $lignes[] = ['texte' => "{$cible} absorbe {$nom}", 'ton' => 'pare'];

                continue;
            }

            // Sort de CONTRÔLE : il pose une condition, il ne blesse pas.
            if (array_key_exists('effet_applique', $r) && ! isset($r['degats'])) {
                $lignes[] = empty($r['effet_applique'])
                    ? ['texte' => "{$cible} résiste à {$nom}", 'ton' => 'pare']
                    : ['texte' => "{$cible} subit {$nom} — ".($a['condition'] ?? 'affecté'), 'ton' => 'subit'];

                continue;
            }

            $degats = (int) ($r['degats'] ?? 0);

            if (! empty($r['cible_tombee'])) {
                $lignes[] = ['texte' => "{$nom} terrasse {$cible} !", 'ton' => 'chute'];
            } elseif ($degats > 0) {
                $lignes[] = ['texte' => "{$nom} frappe {$cible} (−{$degats} PV)", 'ton' => 'subit'];
            } else {
                $lignes[] = ['texte' => "{$cible} encaisse {$nom} sans dommage", 'ton' => 'pare'];
            }
        }

        foreach ((array) ($a['monstres_touches'] ?? []) as $m) {
            $lignes[] = [
                'texte' => ($m['monstre'] ?? 'Une créature').' est prise dans '.$nom.' (−'.($m['degats'] ?? 0).' PV)'
                    .(! empty($m['vaincu']) ? ' — elle tombe !' : ''),
                'ton' => ! empty($m['vaincu']) ? 'mort' : 'degats',
            ];
        }

        return $lignes === [] ? [$this->info("{$acteurNom} lance {$nom}")] : $lignes;
    }

    /**
     * La tentative de rupture jouée au début du tour d'un héros.
     *
     * ⚠ Elle se dit même quand elle ÉCHOUE, et c'est tout l'intérêt : sans
     * cette ligne, un héros endormi verrait passer trois rounds sans savoir
     * qu'on lance des dés pour lui à chaque fois.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function ruptureSortDread(array $a): array
    {
        $nom = $a['nom'] ?? 'Le héros';
        $condition = $a['condition'] ?? 'le sort';
        $des = empty($a['faces']) ? '' : ' · '.implode(', ', (array) $a['faces']);

        return [empty($a['rompu'])
            ? ['texte' => "{$nom} ne parvient pas à briser {$condition}{$des}", 'ton' => 'echec']
            : ['texte' => "{$nom} brise {$condition} !{$des}", 'ton' => 'succes']];
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

        // ⚠ MANQUÉ ≠ PARÉ. Le repli disait « pare » dans les deux cas, et un
        // joueur en concluait que l'armure adverse était trop bonne quand
        // c'étaient ses propres dés qui échouaient (constaté en partie réelle
        // le 2026-08-13 : « Gobelin pare l'assaut de Borin · 0 crâne »).
        return (int) ($a['touches'] ?? 0) === 0
            ? [['texte' => "{$attaquant} manque {$cible}{$des}", 'ton' => 'echec']]
            : [['texte' => "{$cible} pare l'assaut de {$attaquant}{$des}", 'ton' => 'pare']];
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
            // Même distinction côté monstre : un héros lisait « je pare »
            // quand la créature l'avait simplement manqué.
            return (int) ($a['touches'] ?? 0) === 0
                ? [['texte' => "{$monstre} manque {$cible}{$des}", 'ton' => 'echec']]
                : [['texte' => "{$cible} pare l'assaut de {$monstre}{$des}", 'ton' => 'pare']];
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
     * *Esprit Ardent* : un rayon qui traverse. Une ligne par ennemi brûlé, plus
     * l'annonce — sans quoi trois monstres perdraient des PV sans explication.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function rayon(array $a, string $acteurNom): array
    {
        $lignes = [$this->info("{$acteurNom} projette ".($a['technique'] ?? 'un rayon'))];

        foreach ((array) ($a['touches'] ?? []) as $touche) {
            $lignes[] = [
                'texte' => empty($touche['vaincu'])
                    ? "{$touche['nom']} est traversé (−{$touche['degats']} PV)"
                    : "{$touche['nom']} est réduit en cendres !",
                'ton' => empty($touche['vaincu']) ? 'degats' : 'mort',
            ];
        }

        return $lignes;
    }

    /**
     * *Sens du piège* (Explorateur) : la seule ligne qu'un déplacement produise.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function alertePiege(array $a, string $acteurNom): array
    {
        $nombre = count($a['pieges_pressentis'] ?? []);

        if ($nombre === 0) {
            return [];
        }

        return [[
            'texte' => "{$acteurNom} pressent {$nombre} piège".($nombre > 1 ? 's' : '').' tout près — il s\'arrête net',
            'ton' => 'info',
        ]];
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

        // Épreuve : le jet ne disait que « réussi ». Ce qu'elle avait DONNÉ
        // vivait dans le payload et n'était rendu nulle part — deux « Dalle
        // descellée » réussies ont versé 100 pièces chacune en silence, et une
        // « Inscription menaçante » n'avait rien à dissiper sans le dire
        // (partie du 2026-09-04). Même classe de défaut que les sorts de Dread.
        if (isset($a['epreuve'])) {
            return $this->epreuve($a, $acteurNom);
        }

        $libelle = $a['libelle'] ?? 'un jet';

        return [[
            'texte' => "{$acteurNom} — {$libelle} : ".(! empty($a['succes']) ? 'réussi' : 'échoué'),
            'ton' => ! empty($a['succes']) ? 'succes' : 'echec',
        ]];
    }

    /**
     * Une épreuve : le jet, puis CE QU'ELLE A RENDU.
     *
     * ⚠ Une réussite dit toujours quelque chose, « rien ne vient » compris.
     * Trois des six mécaniques peuvent aboutir dans le vide (dissiper chez un
     * héros sain, soigner un groupe intact, désamorcer une salle déjà purgée) ;
     * `MoteurEpreuves::offre()` cesse désormais de les proposer, mais un menu
     * périmé peut encore les atteindre — et un effet muet est indiscernable
     * d'une panne.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function epreuve(array $a, string $acteurNom): array
    {
        $nom = (string) $a['epreuve'];
        $reussi = ! empty($a['succes']);

        $lignes = [[
            'texte' => "{$acteurNom} — {$nom} : ".($reussi ? 'réussi' : 'échoué'),
            'ton' => $reussi ? 'succes' : 'echec',
        ]];

        if (! $reussi) {
            return $lignes;
        }

        $gains = [];

        if ((int) ($a['or'] ?? 0) > 0) {
            $gains[] = $a['or'].' pièces d\'or pour la bourse';
        }

        if (isset($a['objet']['nom'])) {
            $gains[] = $a['objet']['nom'].(empty($a['sac_deborde']) ? '' : ' (sac plein — en dépassement)');
        }

        $soignes = (array) ($a['soin_groupe'] ?? []);
        if ($soignes !== []) {
            $total = array_sum(array_map(fn ($s) => (int) ($s['soin_pv_body'] ?? 0), $soignes));
            $gains[] = "{$total} PV de Body rendus au groupe";
        }

        $conditions = array_filter((array) ($a['retire_condition'] ?? []));
        if ($conditions !== []) {
            $gains[] = 'dissipe '.implode(', ', $conditions);
        }

        $desarmes = count((array) ($a['desarme_pieges_salle'] ?? []));
        if ($desarmes > 0) {
            $gains[] = $desarmes.' piège'.($desarmes > 1 ? 's' : '')
                .' désamorcé'.($desarmes > 1 ? 's' : '').' dans la salle';
        }

        $lignes[] = $gains === []
            ? ['texte' => "{$nom} : rien ne vient.", 'ton' => 'info']
            : ['texte' => "{$nom} — ".implode(' · ', $gains), 'ton' => 'tresor'];

        return $lignes;
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
