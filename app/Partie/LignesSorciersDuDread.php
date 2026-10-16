<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * Les phrases du fil de combat pour les TROIS SORTS DES SORCIERS DU DREAD d'*Against the Ogre Horde*
 * (F9528 p. 10-11) : *Mind Lock*, *Dominate*, *Mind Burst* — le jet en opposition, le gel, la
 * domination et chacune des actions que le MJ joue avec le héros dominé.
 *
 * Un TRAIT de {@see JournalCombat}, comme {@see LignesEffetsAutomatiques} : ces méthodes partagent
 * `info()`, `desDuJet()` et le registre {@see JournalCombat::TYPES}. Chaque effet de ces trois sorts est
 * AUTOMATIQUE — le jet des deux volées, les cartes prêtées, le tour perdu, la tentative de libération,
 * l'attaque d'un héros contre son compagnon — et « un effet automatique que rien n'annonce est
 * injouable » : le texte est composé ICI, d'après ce que le moteur a décidé, jamais redéduit des dés.
 *
 * Règle d'écriture identique à {@see LignesEffetsAutomatiques} : une phrase qui nomme l'acteur, la cible
 * et l'EFFET, avec le `ton` que la manette sait rendre (`degats`, `subit`, `chute`, `pare`, `succes`,
 * `echec`, `info`) ; et le bloc `des` quand des dés sont tombés, sous la forme que `JetDes.vue` lit déjà.
 */
trait LignesSorciersDuDread
{
    /**
     * Le jet en opposition de *Mind Lock* et de *Dominate* — UNE phrase par victime, avec les deux volées
     * dessinées. `$r` est l'entrée de `resultats[]` publiée par `MoteurDread::sortDreadControle()` ou
     * `sortDreadDomination()` (clé `cranes_nets`).
     *
     * @param  array<string, mixed>  $r
     * @return array{texte: string, ton: string, des?: array<string, mixed>}
     */
    private function ligneOpposition(array $r, string $nomSort, string $lanceurNom): array
    {
        $cible = (string) ($r['cible']['nom'] ?? 'un héros');
        $nets = (int) $r['cranes_nets'];
        $des = $this->desDuJet(['type' => 'sort_dread'] + $r, $lanceurNom);

        $ligne = match (true) {
            ! empty($r['contresort']['reussi']) => [
                'texte' => "{$cible} annule {$nomSort} grâce à son Contresort",
                'ton' => 'pare',
            ],
            ! empty($r['domine']) => [
                'texte' => "{$cible} est DOMINÉ par {$lanceurNom} ({$nets} crânes nets sur {$r['cranes_requis']} requis) — le maître du jeu joue {$cible} jusqu'à la fin de son tour",
                'ton' => 'chute',
            ],
            isset($r['tours_geles']) && ! empty($r['effet_applique']) => [
                'texte' => "{$cible} est GELÉ par {$nomSort} : {$r['tours_geles']} tour".((int) $r['tours_geles'] > 1 ? 's' : '')
                    .' perdu'.((int) $r['tours_geles'] > 1 ? 's' : '')." ({$nets} crâne".($nets > 1 ? 's' : '').' net'.($nets > 1 ? 's' : '').'), 1 dé de défense',
                'ton' => 'subit',
            ],
            isset($r['cranes_requis']) => [
                'texte' => "{$cible} résiste à {$nomSort} : {$nets} crâne".($nets > 1 ? 's' : '').' net'.($nets > 1 ? 's' : '')." — il en fallait {$r['cranes_requis']}",
                'ton' => 'pare',
            ],
            default => [
                'texte' => "{$cible} résiste à {$nomSort} : aucun crâne net",
                'ton' => 'pare',
            ],
        };

        if ($des !== null) {
            $ligne['des'] = $des;
        }

        return $ligne;
    }

    /**
     * *Mind Burst* : le jet des deux volées, puis ce que le perdant paie — Mind du héros, ou Mind du
     * Sorcier quand c'est le héros qui l'emporte (« lanceur compris »).
     *
     * @param  array<string, mixed>  $r
     * @return list<array<string, mixed>>
     */
    private function ligneMindBurst(array $r, string $nomSort, string $lanceurNom): array
    {
        $cible = (string) ($r['cible']['nom'] ?? 'un héros');
        $ecart = (int) $r['ecart_cranes'];
        $cranesSorcier = (int) ($r['touches'] ?? 0);
        $cranesHeros = (int) ($r['boucliers'] ?? 0);
        $des = $this->desDuJet(['type' => 'sort_dread'] + $r, $lanceurNom);
        $lignes = [];

        if ($ecart > 0) {
            $ligne = [
                'texte' => "{$lanceurNom} fait {$cranesSorcier} crâne".($cranesSorcier > 1 ? 's' : '')." contre {$cranesHeros} — {$cible} perd "
                    .(int) $r['degats_mind'].' point'.((int) $r['degats_mind'] > 1 ? 's' : '').' de Mind (Mind '
                    .(int) $r['pv_mind_cible_avant'].' → '.(int) $r['pv_mind_apres'].')'
                    .((int) ($r['mind_absorbe'] ?? 0) > 0 ? ' — '.(int) $r['mind_absorbe'].' absorbé par l\'Orbe céleste' : ''),
                'ton' => 'subit',
            ];
        } elseif ($ecart < 0) {
            $ligne = [
                'texte' => "{$cible} l'emporte : {$cranesHeros} crâne".($cranesHeros > 1 ? 's' : '')." contre {$cranesSorcier} — {$lanceurNom} subit "
                    .(int) $r['degats_mind_lanceur'].' point'.((int) $r['degats_mind_lanceur'] > 1 ? 's' : '').' de Mind (Mind '
                    .(int) $r['pv_mind_lanceur_avant'].' → '.(int) $r['pv_mind_lanceur_apres'].')',
                'ton' => 'succes',
            ];
        } else {
            $ligne = [
                'texte' => "Égalité de crânes ({$cranesSorcier} chacun) : {$lanceurNom} et {$cible} ne perdent aucun point de Mind",
                'ton' => 'pare',
            ];
        }

        if ($des !== null) {
            $ligne['des'] = $des;
        }

        $lignes[] = $ligne;

        if (! empty($r['entre_en_choc'])) {
            $lignes[] = ['texte' => "{$cible} a l'esprit vide — il entre en état de choc !", 'ton' => 'chute'];
        }

        if (! empty($r['lanceur_en_choc'])) {
            $lignes[] = ['texte' => "{$lanceurNom} a l'esprit vide — il entre en état de choc (1 dé d'attaque, 2 de défense) !", 'ton' => 'succes'];
        }

        return $lignes;
    }

    /**
     * Le tour d'un héros GELÉ par *Mind Lock* : tour perdu, une carte rendue, et — s'il lui reste des cartes —
     * sa tentative de libération (autant de dés de combat que de Mind, trois crânes ou plus).
     *
     * @param  array<string, mixed>  $a
     * @return list<array<string, mixed>>
     */
    private function verrouMentalTour(array $a): array
    {
        $nom = (string) ($a['nom'] ?? 'Le héros');
        $lanceur = (string) ($a['lanceur']['nom'] ?? 'son Sorcier');
        $t = is_array($a['tentative'] ?? null) ? $a['tentative'] : null;

        $des = null;

        if ($t !== null && ! empty($t['faces'])) {
            $des = [
                'atk' => [],
                'def' => array_values((array) $t['faces']),
                'touchante' => null,
                'defensive' => 'crane',
                'attaquant' => null,
                'defenseur' => $nom,
                'touches' => null,
                'boucliers' => (int) ($t['cranes'] ?? 0),
                'libelle_def' => ! empty($t['libere']) ? 'se libère' : 'tente',
            ];
        }

        if (($a['raison'] ?? null) === 'cartes_rendues') {
            return [['texte' => "{$nom} perd son tour gelé et rend sa dernière carte de Verrou mental — l'emprise de {$lanceur} cède", 'ton' => 'succes']];
        }

        if (! empty($a['libere'])) {
            $ligne = [
                'texte' => "{$nom} perd son tour gelé, rend une carte, puis brise l'emprise de {$lanceur} : ".(int) $t['cranes'].' crânes sur '
                    .(int) $t['des'].' dés ('.(int) $t['seuil'].' suffisent) — les cartes restantes sont rendues',
                'ton' => 'succes',
            ];
        } else {
            $restantes = (int) ($a['cartes_apres'] ?? 0);
            $ligne = [
                'texte' => "{$nom} perd son tour, gelé par {$lanceur} : il rend une carte ({$restantes} restante".($restantes > 1 ? 's' : '').')'
                    .($t !== null ? ' — '.(int) $t['cranes'].' crâne'.((int) $t['cranes'] > 1 ? 's' : '').' sur '.(int) $t['des'].' dé'.((int) $t['des'] > 1 ? 's' : '').', il en faut '.(int) $t['seuil'] : ''),
                'ton' => 'echec',
            ];
        }

        if ($des !== null) {
            $ligne['des'] = $des;
        }

        return [$ligne];
    }

    /**
     * Le Sorcier tombe : « the effects of that spell immediately end ».
     *
     * @param  array<string, mixed>  $a
     * @return list<array<string, mixed>>
     */
    private function verrouMentalLeve(array $a): array
    {
        $nom = (string) ($a['nom'] ?? 'Le héros');
        $lanceur = (string) ($a['lanceur']['nom'] ?? 'Le Sorcier');

        return [['texte' => "{$lanceur} est tombé : le Verrou mental de {$nom} cède aussitôt — il retrouve son tour et sa défense", 'ton' => 'succes']];
    }

    /**
     * La marche forcée d'un héros DOMINÉ.
     *
     * @param  array<string, mixed>  $a
     * @return list<array<string, mixed>>
     */
    private function dominationDeplacement(array $a): array
    {
        $nom = (string) ($a['personnage'] ?? 'Un héros');
        $vers = (string) ($a['vers_heros'] ?? '');
        $fait = ($a['de'] ?? null) !== ($a['vers'] ?? null);

        return [[
            'texte' => $vers === ''
                ? "{$nom}, dominé, n'a personne à qui s'en prendre"
                : ($fait ? "{$nom}, dominé, avance de force vers {$vers}" : "{$nom}, dominé, est déjà au contact de {$vers}"),
            'ton' => 'subit',
        ]];
    }

    /**
     * Le coup à l'arme qu'un héros dominé porte à un compagnon.
     *
     * @param  array<string, mixed>  $a
     * @return list<array<string, mixed>>
     */
    private function dominationAttaque(array $a): array
    {
        $nom = (string) ($a['personnage'] ?? 'Un héros');
        $cible = (string) ($a['cible']['nom'] ?? 'un compagnon');
        $degats = (int) ($a['degats'] ?? 0);

        $ligne = match (true) {
            ! empty($a['cible_tombee']) => ['texte' => "{$nom}, dominé, terrasse {$cible} ! (−{$degats} PV)", 'ton' => 'chute'],
            $degats > 0 => ['texte' => "{$nom}, dominé, frappe {$cible} (−{$degats} PV)", 'ton' => 'subit'],
            default => ['texte' => "{$nom}, dominé, frappe {$cible} — le coup est paré", 'ton' => 'pare'],
        };

        if (($des = $this->desDuJet($a + ['type' => 'domination_attaque'], $nom)) !== null) {
            $ligne['des'] = $des;
        }

        return [$ligne];
    }

    /**
     * Le sort de son répertoire qu'un héros dominé lance sur un compagnon — la ligne du lancer, puis celle de
     * ce que le sort a fait (le même arm que s'il l'avait lancé lui-même, avec LUI pour acteur).
     *
     * @param  array<string, mixed>  $a
     * @return list<array<string, mixed>>
     */
    private function dominationSort(array $a): array
    {
        $nom = (string) ($a['personnage'] ?? 'Un héros');
        $lignes = [[
            'texte' => "{$nom}, dominé, lance {$a['sort']} sur ".($a['cible']['nom'] ?? 'un compagnon'),
            'ton' => 'subit',
        ]];

        if (is_array($a['resultat'] ?? null)) {
            foreach ($this->ligneType($a['resultat'], $nom) as $ligne) {
                $lignes[] = $ligne;
            }
        }

        return $lignes;
    }
}
