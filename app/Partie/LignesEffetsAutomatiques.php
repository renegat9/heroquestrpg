<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * Les phrases du fil de combat pour les effets AUTOMATIQUES que le formateur
 * ne savait pas dire (verdict Morcar 2026-10-09 §1) : soins et sorts de soutien,
 * déplacements et réveils des monstres, capacités des boss, libération des
 * captifs, votes, portes…
 *
 * Un TRAIT de {@see JournalCombat}, pas une classe voisine : ces méthodes
 * partagent `info()` et le registre {@see JournalCombat::TYPES}, et une
 * seconde porte d'entrée aurait rouvert la faute qu'elles referment — deux
 * formateurs, dont l'un oublie un type.
 *
 * Règle d'écriture : une phrase LISIBLE qui nomme l'acteur, la cible et
 * l'EFFET (jamais « X lance Y » seul), avec le `ton` que l'icône de la manette
 * sait rendre (`degats`, `mort`, `subit`, `chute`, `pare`, `succes`, `echec`,
 * `tresor`, `info`).
 */
trait LignesEffetsAutomatiques
{
    /** @return list<array{texte: string, ton: string}> */
    private function soinAllie(array $a, string $acteurNom): array
    {
        $cible = (string) ($a['cible']['nom'] ?? 'un compagnon');
        $rendus = (int) ($a['soin_pv_body'] ?? 0);
        $de = isset($a['de']) ? ' (dé '.(int) $a['de'].')' : '';

        return [[
            'texte' => "{$acteurNom} soigne {$cible} : +{$rendus} PV de Body{$de}"
                .(isset($a['pv_body_apres']) ? " — {$cible} est à ".(int) $a['pv_body_apres'].' PV' : '')
                .(! empty($a['releve']) ? ' et se relève' : ''),
            'ton' => 'succes',
        ]];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function sortRecupere(array $a, string $acteurNom): array
    {
        $sort = (string) ($a['sort_recupere']['nom'] ?? 'un sort');

        // Concentration sacrifie le TOUR ; le Prix du pacte paie 1 PV de Body — la
        // phrase dit lequel des deux a été payé, c'est toute la différence.
        if (isset($a['pv_body_paye'])) {
            return [[
                'texte' => "{$acteurNom} paie ".(int) $a['pv_body_paye']." PV de Body et retrouve « {$sort} »"
                    .(isset($a['pv_body_apres']) ? ' ('.(int) $a['pv_body_apres'].' PV restants)' : ''),
                'ton' => 'subit',
            ]];
        }

        return [['texte' => "{$acteurNom} se concentre et retrouve « {$sort} » — son tour est sacrifié", 'ton' => 'succes']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function ecartDuBloc(array $a, string $acteurNom): array
    {
        return [$this->info("{$acteurNom} s'écarte du bloc qui s'abat"
            .(isset($a['vers']['x']) ? ' ('.(int) $a['vers']['x'].', '.(int) $a['vers']['y'].')' : ''))];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function vote(array $a, string $acteurNom, string $proposition): array
    {
        return [$this->info("{$acteurNom} {$proposition} — un vote du groupe est ouvert")];
    }

    /**
     * La poussée DIT pourquoi la figure ne recule pas (2026-10-17) : la phrase est bâtie
     * sur les ingrédients publiés par le résolveur — succès obtenus (`jet.succes`), requis
     * (`jet.difficulte`), issue (`jet.issue`) et `motif` — jamais sur une devinette.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function poussee(array $a, string $acteurNom): array
    {
        $cible = (string) ($a['cible']['nom'] ?? 'la créature');
        $succes = (int) ($a['jet']['succes'] ?? 0);
        $requis = (int) ($a['jet']['difficulte'] ?? 0);

        if (! empty($a['repoussee'])) {
            return [['texte' => "{$acteurNom} repousse {$cible} d'une case ({$succes} succès, {$requis} requis)", 'ton' => 'succes']];
        }

        // Le jet a atteint le requis, mais la case de recul est prise : rien ne bouge.
        if (($a['motif'] ?? null) === 'case_bloquee') {
            return [['texte' => "{$acteurNom} pousse {$cible}, mais la case derrière est occupée — {$cible} ne recule pas ({$succes} succès, {$requis} requis)", 'ton' => 'echec']];
        }

        // Le jet n'a pas atteint le requis. « Raté de peu » nomme le quasi-échec : c'est un
        // ÉCHEC sec (P4 tranchée le 2026-10-19), jamais une réussite à coût.
        $mixte = ($a['jet']['issue'] ?? null) === 'reussite_mixte' ? ', raté de peu' : '';

        return [['texte' => "{$acteurNom} s'arc-boute contre {$cible} : {$succes} succès sur {$requis}{$mixte} — {$cible} ne recule pas", 'ton' => 'echec']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function breuvageDissipe(array $a): array
    {
        return [$this->info((string) ($a['texte'] ?? "Un effet d'objet se dissipe"))];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function ouvrirPorte(array $a, string $acteurNom): array
    {
        return [['texte' => "{$acteurNom} ouvre une porte".(($a['cause'] ?? 'main') === 'cle' ? ' avec une clé' : ''), 'ton' => 'info']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function oracleSalle(array $a, string $acteurNom): array
    {
        $n = count((array) ($a['salles_revelees'] ?? []));

        return [[
            'texte' => "{$acteurNom} consulte l'Oracle : ".($n > 0
                ? $n.' salle'.($n > 1 ? 's se dévoilent' : ' se dévoile')
                : 'rien de plus à voir'),
            'ton' => 'info',
        ]];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function detacherRejetons(array $a, string $acteurNom): array
    {
        $cible = (string) ($a['cible']['nom'] ?? 'un compagnon');
        $n = (int) ($a['retires'] ?? 0);

        return [[
            'texte' => $n > 0
                ? "{$acteurNom} arrache {$n} rejeton".($n > 1 ? 's' : '')." à {$cible} — il en reste ".(int) ($a['restants'] ?? 0)
                : "{$acteurNom} s'acharne sur les rejetons de {$cible} — aucun ne lâche prise",
            'ton' => $n > 0 ? 'succes' : 'echec',
        ]];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function briserGlace(array $a): array
    {
        $qui = (string) ($a['personnage'] ?? 'Un héros');

        return [! empty($a['brisee'])
            ? ['texte' => "{$qui} fait voler le mur de glace en éclats", 'ton' => 'succes']
            : ['texte' => "{$qui} entame le mur de glace — il tient encore", 'ton' => 'info']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function allie(array $a, string $quoi): array
    {
        return [$this->info(((string) ($a['allie'] ?? 'Un allié')).' '.$quoi)];
    }

    /**
     * Un sbire sous le contrôle de la Baguette d'Os : il avance ou il frappe un autre monstre.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function sbireControle(array $a): array
    {
        $sbire = (string) ($a['nom'] ?? 'Un sbire');
        $maitre = (string) ($a['maitre'] ?? 'un héros');
        $cible = (string) ($a['cible']['nom'] ?? 'un monstre');

        if (empty($a['attaque'])) {
            return [$this->info("{$sbire}, aux ordres de {$maitre}, avance vers {$cible}")];
        }

        $degats = (int) ($a['degats'] ?? 0);

        if (! empty($a['cible_vaincue'])) {
            return [['texte' => "{$sbire}, aux ordres de {$maitre}, terrasse {$cible} !".$this->detailDes($a), 'ton' => 'mort']];
        }

        if ($degats > 0) {
            return [['texte' => "{$sbire}, aux ordres de {$maitre}, frappe {$cible} (−{$degats} PV)".$this->detailDes($a), 'ton' => 'degats']];
        }

        return [(int) ($a['touches'] ?? 0) === 0
            ? ['texte' => "{$sbire}, aux ordres de {$maitre}, manque {$cible}".$this->detailDes($a), 'ton' => 'echec']
            : ['texte' => "{$cible} pare le coup de {$sbire}".$this->detailDes($a), 'ton' => 'pare']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function captifLibere(array $a): array
    {
        $qui = (string) ($a['personnage'] ?? 'Un héros');
        $captif = (string) ($a['allie'] ?? 'le captif');

        return [[
            'texte' => ($a['mode'] ?? null) === 'escorte'
                ? "{$qui} libère {$captif} — il le prend en charge, à escorter jusqu'à la sortie"
                : "{$qui} libère {$captif} — il rejoint le groupe",
            'ton' => 'succes',
        ]];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function captifRepris(array $a): array
    {
        return [[
            'texte' => ((string) ($a['personnage'] ?? 'Son porteur')).' tombe : '
                .((string) ($a['allie'] ?? 'le captif')).' retombe aux mains de ses geôliers',
            'ton' => 'echec',
        ]];
    }

    /**
     * Le héros joué par le MJ (*Commandement*) : trois issues, un seul tour perdu pour lui.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function commandement(array $a): array
    {
        $qui = (string) ($a['personnage'] ?? 'Un héros');

        return match ($a['type'] ?? null) {
            'commandement_deplacement' => [['texte' => "{$qui}, commandé par le MJ, avance de force vers ".((string) ($a['vers_allié'] ?? 'un compagnon')), 'ton' => 'subit']],
            'commandement_attaque' => $this->commandementAttaque($a, $qui),
            default => [$this->info("{$qui} est commandé par le MJ — mais n'a personne à qui s'en prendre")],
        };
    }

    /** @return list<array{texte: string, ton: string}> */
    private function commandementAttaque(array $a, string $qui): array
    {
        $cible = (string) ($a['cible']['nom'] ?? 'un compagnon');
        $degats = (int) ($a['degats'] ?? 0);
        $des = $this->detailDes($a);

        if ($degats <= 0) {
            return [(int) ($a['touches'] ?? 0) === 0
                ? ['texte' => "{$qui}, commandé par le MJ, frappe {$cible} — et le manque{$des}", 'ton' => 'echec']
                : ['texte' => "{$cible} pare le coup de {$qui}, commandé par le MJ{$des}", 'ton' => 'pare']];
        }

        $lignes = [['texte' => "{$qui}, commandé par le MJ, frappe {$cible} (−{$degats} PV){$des}", 'ton' => 'subit']];

        if (! empty($a['cible_tombee'])) {
            $lignes[] = ['texte' => "{$cible} s'effondre !", 'ton' => 'chute'];
        }

        return $lignes;
    }

    /** @return list<array{texte: string, ton: string}> */
    private function deplacementMonstre(array $a): array
    {
        $monstre = (string) ($a['monstre'] ?? 'Un monstre');
        $n = count((array) ($a['chemin'] ?? []));
        $cases = $n.' case'.($n > 1 ? 's' : '');

        // Draconic Flight (First Light) : chaque jambe dit où elle en est dans le tour — « prend son vol »
        // avant l'action, « achève son vol » après (ou seule, si rien ne s'est joué sur le trajet).
        if (! empty($a['vol']) && $n > 0) {
            return [$this->info(match ($a['jambe'] ?? null) {
                1 => "{$monstre} prend son vol — {$cases}",
                2 => "{$monstre} achève son vol — {$cases}",
                default => "{$monstre} fond en vol et avance de {$cases}",
            })];
        }

        return [$this->info($n > 0
            ? "{$monstre} avance de {$cases}"
            : "{$monstre} ne peut pas avancer")];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function repliTireur(array $a): array
    {
        $n = count((array) ($a['chemin'] ?? []));

        return [$this->info(((string) ($a['monstre'] ?? 'Un tireur')).' recule'.($n > 0 ? " de {$n} case".($n > 1 ? 's' : '') : '').' pour garder ses distances')];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function monstreReveille(array $a): array
    {
        $des = empty($a['des_rupture']) ? '' : ' · dés '.implode(', ', (array) $a['des_rupture']);

        return [['texte' => ((string) ($a['monstre'] ?? 'Le monstre')).' se réveille — le sort est rompu'.$des, 'ton' => 'info']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function etreinteMaintenue(array $a): array
    {
        return [['texte' => ((string) ($a['monstre'] ?? 'Le monstre')).' resserre son étreinte sur '.((string) ($a['cible']['nom'] ?? 'sa proie')), 'ton' => 'subit']];
    }

    /**
     * La Frappe de zone d'un monstre : tous les héros au contact, chacun sa défense.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function frappeDeZone(array $a): array
    {
        $monstre = (string) ($a['monstre'] ?? 'Le monstre');
        $lignes = [['texte' => "{$monstre} frappe d'un grand coup tous les héros à son contact", 'ton' => 'subit']];

        foreach ((array) ($a['resultats'] ?? []) as $r) {
            $cible = (string) ($r['cible']['nom'] ?? 'un héros');
            $degats = (int) ($r['degats'] ?? 0);

            if (! empty($r['cible_tombee'])) {
                $lignes[] = ['texte' => "{$cible} s'effondre !", 'ton' => 'chute'];
            } elseif ($degats > 0) {
                $lignes[] = ['texte' => "{$cible} encaisse −{$degats} PV", 'ton' => 'subit'];
            } else {
                $lignes[] = ['texte' => "{$cible} pare le coup", 'ton' => 'pare'];
            }

            foreach ($this->lignesConditionsPosees($r, $cible) as $ligne) {
                $lignes[] = $ligne;
            }
        }

        return $lignes;
    }

    /**
     * Les conditions qu'un COUP de monstre a posées sur son héros (`conditions_posees`,
     * décidées par le serveur : voir `MoteurDread::conditionPosee()`) — « Brokk est
     * Envenimé — paralysé par le venin : … ». Le nom et la description viennent du
     * catalogue ; ici on ne fait que les rendre.
     *
     * @param  array<string, mixed>  $a  l'attaque (ou le résultat d'une cible de zone)
     * @return list<array{texte: string, ton: string}>
     */
    private function lignesConditionsPosees(array $a, string $cible): array
    {
        $lignes = [];

        foreach ((array) ($a['conditions_posees'] ?? []) as $condition) {
            if (! is_array($condition) || ($condition['nom'] ?? '') === '') {
                continue;
            }

            $description = trim((string) ($condition['description'] ?? ''), " \t\n\r.");
            $description = $description === '' ? '' : ' — '.mb_strtolower(mb_substr($description, 0, 1)).mb_substr($description, 1);

            $lignes[] = ['texte' => "{$cible} est {$condition['nom']}{$description}", 'ton' => 'subit'];
        }

        return $lignes;
    }

    /** @return list<array{texte: string, ton: string}> */
    private function capaciteDread(array $a): array
    {
        $monstre = (string) ($a['monstre'] ?? 'Le boss');
        $n = count((array) ($a['invoques'] ?? []));

        return [[
            'texte' => $n === 0
                ? "{$monstre} appelle des renforts — l'appel reste sans réponse"
                : "{$monstre} appelle des renforts : {$n} créature".($n > 1 ? 's' : '').' surgi'.($n > 1 ? 'ssent' : 't'),
            'ton' => $n === 0 ? 'echec' : 'degats',
        ]];
    }

    /**
     * Une figure CACHÉE paraît à la vue d'un héros (Dread Skull). Le tir qu'elle joue aussitôt
     * est une action imbriquée (`action`), étalée par `ligneAction()` juste après cette ligne.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function apparitionALaVue(array $a): array
    {
        $qui = isset($a['declencheur']['nom']) ? ' — '.$a['declencheur']['nom'].' vient de la voir' : '';

        return [['texte' => ((string) ($a['monstre'] ?? 'Une figure')).' paraît'.$qui.' : elle tire aussitôt', 'ton' => 'degats']];
    }

    /**
     * Le SILENCING SNARE de la Deathspinner : qui, sur qui, quel sort — « sans action ». Le sort
     * lui-même (dés, dégâts, chute) suit sur sa propre ligne, c'est le payload `sort_dread` habituel.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function filetSilencieux(array $a): array
    {
        return [['texte' => ((string) ($a['monstre'] ?? 'La créature')).' tisse son filet : '
            .((string) ($a['cible']['nom'] ?? 'un héros')).' finit son tour sous son regard — '
            .((string) ($a['sort'] ?? 'un sort')).', sans action', 'ton' => 'subit']];
    }

    /**
     * Le SOUFFLE DU CONSTRUIT du Golem de Forge : une ligne d'annonce, puis une par héros pris — les
     * dés rouges, ce qui a été annulé, ce qui reste.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function souffleDuConstruit(array $a): array
    {
        $monstre = (string) ($a['monstre'] ?? 'Le Golem');
        $resultats = array_values(array_filter((array) ($a['resultats'] ?? []), 'is_array'));
        $n = count($resultats);
        $lignes = [['texte' => "{$monstre} exhale son Souffle du Construit sur toute la salle : {$n} héros pris dans le souffle", 'ton' => 'subit']];

        foreach ($resultats as $r) {
            $cible = (string) ($r['cible']['nom'] ?? 'un héros');
            $degats = (int) ($r['degats'] ?? 0);
            $des = ' · dés rouges '.implode(', ', (array) ($r['des_rouges'] ?? []));
            $annules = (int) ($r['degats_annules'] ?? 0);

            if (! empty($r['cible_tombee'])) {
                $lignes[] = ['texte' => "{$cible} s'effondre sous le souffle (−{$degats} PV){$des}", 'ton' => 'chute'];
            } elseif ($degats > 0) {
                $lignes[] = ['texte' => "{$cible} encaisse −{$degats} PV".($annules > 0 ? " ({$annules} annulé".($annules > 1 ? 's' : '').' par les dés)' : '').$des, 'ton' => 'subit'];
            } else {
                $lignes[] = ['texte' => "{$cible} résiste au souffle : tout est annulé{$des}", 'ton' => 'pare'];
            }
        }

        return $lignes;
    }

    /**
     * La VRILLE que le Scion pose à la fin de son tour — ou l'absence de case libre à portée.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function vrillePosee(array $a): array
    {
        $monstre = (string) ($a['monstre'] ?? 'Le Scion');

        if (! is_array($a['vrille'] ?? null)) {
            return [$this->info("{$monstre} cherche où poser une Vrille — aucune case libre à sa portée")];
        }

        return [['texte' => "{$monstre} fait jaillir une Vrille ({$a['vrille']['nom']}) en ({$a['vrille']['x']}, {$a['vrille']['y']})", 'ton' => 'degats']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function spawn(array $a): array
    {
        return [['texte' => ((string) ($a['monstre'] ?? 'Le monstre')).' engendre '.((string) ($a['engendre']['nom'] ?? 'un rejeton')), 'ton' => 'degats']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function rejetonAccroche(array $a): array
    {
        $cible = (string) ($a['cible']['nom'] ?? 'un héros');
        $n = (int) ($a['jetons'] ?? 1);

        return [['texte' => ((string) ($a['monstre'] ?? 'Un rejeton'))." s'accroche à {$cible} : {$n} jeton".($n > 1 ? 's' : '').' lui rongent désormais le Body', 'ton' => 'subit']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function regeneration(array $a): array
    {
        return [$this->info(((string) ($a['monstre'] ?? 'Le monstre')).' se régénère : '.(int) ($a['pv_avant'] ?? 0).' → '.(int) ($a['pv_apres'] ?? 0).' PV')];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function volObjet(array $a): array
    {
        return [['texte' => ((string) ($a['monstre'] ?? 'Le voleur')).' dérobe '.((string) ($a['objet'] ?? 'un objet')).' à '
            .((string) ($a['cible']['nom'] ?? 'un héros')).' et s\'enfuit — à rattraper avant qu\'il ne disparaisse de la vue', 'ton' => 'subit']];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function objetPerdu(array $a): array
    {
        $cible = $a['cible']['nom'] ?? null;

        return [['texte' => ((string) ($a['objet'] ?? 'L\'objet volé')).($cible !== null ? " de {$cible}" : '').' est perdu pour de bon : '
            .((string) ($a['monstre'] ?? 'le voleur')).' est sorti de la vue des héros', 'ton' => 'echec']];
    }

    /**
     * La détection de la Potion de vision, seule à n'être dite par rien d'autre.
     * La fouille (ligne du `jet`) et l'Œil du mineur (popup du talent) se disent
     * déjà : pas de seconde phrase pour eux.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function detectionEnVue(array $a): array
    {
        if (($a['methode'] ?? null) !== 'clairvoyance') {
            return [];
        }

        $qui = (string) ($a['personnage'] ?? 'Un héros');
        $pieges = count((array) ($a['pieges'] ?? []));
        $portes = count((array) ($a['portes'] ?? []));
        $trouve = array_filter([
            $pieges > 0 ? $pieges.' piège'.($pieges > 1 ? 's' : '') : null,
            $portes > 0 ? $portes.' passage'.($portes > 1 ? 's' : '').' secret'.($portes > 1 ? 's' : '') : null,
        ]);

        return [['texte' => "{$qui} voit ".implode(' et ', $trouve).' (Potion de vision)', 'ton' => 'succes']];
    }

    // ------------------------------------------------------------------
    // Verdict Jungle 2026-10-10 §1 — les effets qui S'ÉCOULENT, S'ÉTEIGNENT ou
    // APPARAISSENT sans qu'aucune action ne les retourne.
    // ------------------------------------------------------------------

    /**
     * Une phrase DÉCIDÉE par le moteur (`texte`), rendue telle quelle : fin d'une
     * condition à durée, buff rompu. Jamais re-dérivée ici.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function conditionTerminee(array $a): array
    {
        return [$this->info((string) ($a['texte'] ?? 'Un effet prend fin'))];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function saignement(array $a): array
    {
        return [[
            'texte' => (string) ($a['texte'] ?? ((string) ($a['personnage'] ?? 'Un héros')).' saigne'),
            'ton' => ! empty($a['tombe']) ? 'chute' : ((int) ($a['degats'] ?? 0) > 0 ? 'subit' : 'info'),
        ]];
    }

    /** @return list<array{texte: string, ton: string}> */
    private function sortRegagne(array $a): array
    {
        return [[
            'texte' => (string) ($a['texte'] ?? ((string) ($a['personnage'] ?? 'Un héros')).' retrouve un sort'),
            'ton' => 'succes',
        ]];
    }

    /**
     * La nouvelle forme d'un monstre à phases : le NOM, puis ses statistiques
     * (« Attaque 3 → 4 dés, défense 4 → 5 dés, Body 2/2 ») quand le moteur les a
     * publiées. Sans elles, on ne sait pas pourquoi les dés de Gruulob ont changé.
     *
     * @param  array<string, mixed>  $p  `{avant, apres, stats?}`
     * @return list<array{texte: string, ton: string}>
     */
    private function lignePhase(array $p): array
    {
        $avant = (string) ($p['avant'] ?? 'La créature');
        $apres = (string) ($p['apres'] ?? 'une autre forme');
        $texte = "{$avant} vacille — et se relève sous une autre forme : {$apres} !";

        $stats = (array) ($p['stats'] ?? []);

        if ($stats !== []) {
            $a0 = (array) ($stats['avant'] ?? []);
            $a1 = (array) ($stats['apres'] ?? []);
            $morceaux = [];

            foreach (['attaque' => 'attaque', 'defense' => 'défense'] as $cle => $mot) {
                if (isset($a1[$cle])) {
                    $morceaux[] = isset($a0[$cle]) && (int) $a0[$cle] !== (int) $a1[$cle]
                        ? "{$mot} ".(int) $a0[$cle].' → '.(int) $a1[$cle].' dés'
                        : "{$mot} ".(int) $a1[$cle].' dés';
                }
            }

            if (isset($a1['pv_body'])) {
                $morceaux[] = 'Body '.(int) $a1['pv_body'].'/'.(int) ($a1['pv_body_max'] ?? $a1['pv_body']);
            }

            if ($morceaux !== []) {
                $texte .= ' ('.ucfirst(implode(', ', $morceaux)).')';
            }
        }

        return [$this->info($texte)];
    }

    /**
     * Le type `changement_phase` journalisé À PART par `MoteurDegats` : la même
     * annonce que la clé `changement_phase` d'une action, pour les appelants qui ne
     * la relaient pas (piège, terrain, faveur) et pour la reconnexion.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function changementPhaseJournalise(array $a): array
    {
        return $this->lignePhase((array) ($a['phase'] ?? $a['changement_phase'] ?? []));
    }

    /**
     * Une défense à usage unique d'un monstre, journalisée à part (le monstre y est
     * NOMMÉ, là où la clé `reaction_monstre` d'une action dit « la créature »).
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function reactionMonstreJournalisee(array $a): array
    {
        $nom = (string) ($a['nom'] ?? 'La créature');

        return [$this->info(match ($a['mecanique'] ?? null) {
            'ignore_degats_attaque' => "{$nom} ignore intégralement le coup — une défense à usage unique vient de jouer",
            'increvable_une_fois' => "{$nom} s'effondre… et tient debout à 1 PV, une seule fois",
            'sacrifice_de_vrille' => "{$nom} s'effondre… sacrifie ".((string) ($a['sacrifice']['nom'] ?? 'une de ses Vrilles'))." et tient debout à 1 PV",
            'jeton_ombre' => "Le coup est absorbé par un jeton d'ombre — {$nom} ne perd rien",
            default => "{$nom} active une défense à usage unique",
        })];
    }

    /**
     * La signature d'un doublon : ce que le PARENT d'une annonce autonome porte déjà
     * dans son propre JSON. `null` = ce type n'a pas de parent possible.
     *
     * @param  array<string, mixed>  $a
     */
    private function signatureDeDoublon(array $a): ?string
    {
        return match ($a['type'] ?? null) {
            'changement_phase' => '"avant":'.json_encode((string) ($a['phase']['avant'] ?? $a['changement_phase']['avant'] ?? ''), JSON_UNESCAPED_UNICODE)
                .',"apres":'.json_encode((string) ($a['phase']['apres'] ?? $a['changement_phase']['apres'] ?? ''), JSON_UNESCAPED_UNICODE),
            'reaction_monstre' => '"reaction_monstre":'.json_encode((string) ($a['mecanique'] ?? ''), JSON_UNESCAPED_UNICODE),
            default => null,
        };
    }

    /**
     * Les créatures d'une salle qui vient de se dévoiler — et le BOSS, qu'on ne peut
     * pas laisser entrer sans un mot.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function monstresReveles(array $a): array
    {
        return [[
            'texte' => (string) ($a['texte'] ?? 'Des créatures se dévoilent'),
            'ton' => ! empty($a['boss']) ? 'degats' : 'info',
        ]];
    }

    /**
     * La résolution d'un VOTE : le résultat et ce qu'il a fait (ou NON fait). Le
     * texte est décidé par `VoteGroupe`.
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function voteResolu(array $a): array
    {
        return [[
            'texte' => (string) ($a['texte'] ?? 'Le vote est clos'),
            'ton' => ! empty($a['applique']) ? 'succes' : 'info',
        ]];
    }

    /**
     * *Sens du piège* : l'explorateur est AVERTI (le piège reste caché).
     *
     * @return list<array{texte: string, ton: string}>
     */
    private function piegesPressentis(array $a, string $acteurNom): array
    {
        $pieges = array_values(array_filter((array) ($a['pieges_pressentis'] ?? []), 'is_array'));

        if ($pieges === []) {
            return [];
        }

        $cases = implode(' ; ', array_map(
            fn (array $p) => '('.(int) ($p['x'] ?? 0).', '.(int) ($p['y'] ?? 0).')',
            $pieges,
        ));

        return [$this->info(count($pieges) > 1
            ? "{$acteurNom} pressent ".count($pieges)." pièges cachés tout près — {$cases} (Sens du piège ; ils restent cachés)"
            : "{$acteurNom} pressent un piège caché tout près — {$cases} (Sens du piège ; il reste caché)")];
    }
}
