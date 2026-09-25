<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\DeRouge;
use App\Engine\ReactionEffet;

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
     * Les phases qu'un résultat de tour peut contenir, dans l'ordre où elles se
     * jouent.
     *
     * ⚠ PUBLIQUES parce que {@see SceneDeTable} parcourt le MÊME résultat pour
     * en tirer les scènes de l'écran de table. Deux listes séparées dériveraient
     * au premier type de phase ajouté — et c'est exactement ce qui s'est produit
     * avec `pieges_declenches` (au pluriel), couvert d'un seul côté : un héros
     * tombait dans une fosse, perdait ses PV et n'avait pas une ligne.
     */
    public const PHASES = ['tour_allies', 'tour_monstres'];

    /** Les deux clés sous lesquelles un piège peut être IMBRIQUÉ dans une action. */
    public const CLES_PIEGE = ['declenchement', 'pieges_declenches'];


    /**
     * Toutes les actions d'un résultat de tour, à plat et dans l'ordre : celle
     * du héros, puis celles des alliés, puis celles des monstres.
     *
     * Point de passage unique du parcours — voir {@see self::PHASES}.
     *
     * @param  array<string, mixed>  $resultat
     * @return list<array<string, mixed>>
     */
    public static function actionsDuTour(array $resultat): array
    {
        $actions = [$resultat];

        foreach (self::PHASES as $phase) {
            foreach ($resultat[$phase]['actions'] ?? [] as $action) {
                if (is_array($action)) {
                    $actions[] = $action;
                }
            }
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $resultat  résultat moteur d'un tour
     * @return list<array{texte: string, ton: string}>
     */
    public function depuisResultat(array $resultat, string $acteurNom): array
    {
        $lignes = [];

        // Action du héros, puis tour des alliés scriptés (3.5), puis celui des
        // monstres (C2) — étalés. Le parcours est partagé avec SceneDeTable.
        foreach (self::actionsDuTour($resultat) as $action) {
            foreach ($this->ligneAction($action, $acteurNom) as $ligne) {
                $lignes[] = $ligne;
            }
        }

        // Un talent qui s'active tout seul se VOIT (2026-09-25) : `talents_declenches`
        // vit au SOMMET du résultat (App\Partie\AnnoncesTalents couvre l'action
        // du héros ET la phase des monstres qui a pu suivre dans le même appel
        // à `resoudre()`), donc ces lignes s'ajoutent en dernier plutôt que
        // d'être réparties par action — c'est la file du popup, pas l'ordre du
        // fil, qui compte ici.
        foreach ((array) ($resultat['talents_declenches'] ?? []) as $declenche) {
            if (is_array($declenche)) {
                $lignes[] = $this->ligneTalent($declenche);
            }
        }

        return $lignes;
    }

    /**
     * Une entrée de `talents_declenches` → la ligne `ton: "talent"` du
     * contrat (docs/contrat-api.md §« Un talent qui s'active tout seul se
     * VOIT »). C'est cette ligne, et elle seule, qui porte le popup : la table
     * l'affiche pour tout héros, la manette seulement pour le sien
     * (`talent.personnage_id`).
     *
     * @param  array<string, mixed>  $declenche
     * @return array{texte: string, ton: string, talent: array<string, mixed>}
     */
    private function ligneTalent(array $declenche): array
    {
        $heros = (string) ($declenche['heros'] ?? 'Un héros');
        $talent = (string) ($declenche['talent'] ?? 'Un talent');
        $effet = (string) ($declenche['effet'] ?? '');

        return [
            'texte' => "{$talent} — {$heros} : {$effet}",
            'ton' => 'talent',
            'talent' => [
                'personnage_id' => (int) ($declenche['personnage_id'] ?? 0),
                'heros' => $heros,
                'nom' => $talent,
                'icone' => (string) ($declenche['icone'] ?? 'hub'),
                'effet' => $effet,
            ],
        ];
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
            // Pas d'attaque à deux camps ici : peut-être un jet UNILATÉRAL
            // (dé rouge, Mind, PIÈGE) publié directement sur cette action —
            // un sort à `resistance: des_rouges`/`jet_mind` ou un piège de sol
            // en action PRINCIPALE (`MoteurPieges`, `type: piege_declenche`)
            // porte ses dés au sommet du payload. `cible.nom` couvre les
            // sorts, `personnage.nom` couvre un piège (qui ne connaît pas de
            // `cible`) — sans ce repli, cet appel générique écraserait le nom
            // que `piegeDeclenche()` avait déjà résolu avec un `null`.
            $nomCible = $a['cible']['nom'] ?? $a['personnage']['nom'] ?? null;

            return $this->desJetUnilateral($a, $nomCible !== null ? (string) $nomCible : null);
        }

        // Qui frappe : le monstre a son propre nom, l'allié aussi ; sinon c'est
        // le héros qui agit. Qui encaisse : toujours `cible.nom`.
        $attaquant = match ($a['type'] ?? null) {
            'attaque_monstre' => (string) ($a['monstre'] ?? 'Le monstre'),
            'attaque_allie' => (string) ($a['allie'] ?? 'Allié'),
            default => $acteurNom,
        };

        return $this->avecModificateurs([
            'atk' => $atk,
            'def' => $def,
            'touchante' => (string) ($a['face_touchante'] ?? 'crane'),
            'defensive' => (string) ($a['face_defensive'] ?? 'bouclier_blanc'),
            'attaquant' => $attaquant,
            'defenseur' => isset($a['cible']['nom']) ? (string) $a['cible']['nom'] : null,
            'touches' => isset($a['touches']) ? (int) $a['touches'] : null,
            'boucliers' => isset($a['boucliers']) ? (int) $a['boucliers'] : null,
        ], $a);
    }

    /**
     * Un jet UNILATÉRAL — une seule volée, PERSONNE en face : la cible d'un
     * sort à dés rouges (`des_resistance` — `ResolveurTour::sortDegats()` —
     * ou `des_rouges`, même mécanique publiée sous un autre nom par
     * `MoteurDread::degatsInfliges()`, la Boule de Flammes du MJ vue de
     * l'autre côté de la table, « one rule, both sides »), un jet de Mind
     * (`mind_cible` + `faces` — `ResolveurTour::sortMental()` côté héros,
     * `MoteurDread::sortDreadControle()` côté Dread, MÊME enum de faces que le
     * combat), ou le dé d'un piège de sol (`faces` + `touches`, sans
     * `mind_cible` — `MoteurPieges`). Les trois étaient CALCULÉS, PUBLIÉS, et
     * DESSINÉS NULLE PART (René, 2026-09-24 : « pour les sorts d'attaque avec
     * un lancer de dés pour résister, on ne voit pas le lancer de dé ») —
     * `JetDes` ne savait lire que l'attaque à deux camps.
     *
     * Point de passage UNIQUE, lu par `self` (le fil) ET {@see SceneDeTable}
     * (la table, via `app(JournalCombat::class)`) : deux détections de cette
     * même forme auraient dérivé au premier sort de résistance ajouté — la
     * même leçon que `Salles::indexDe()`.
     *
     * ⚠ La face gagnante est TOUJOURS celle du MOTEUR, jamais redéduite ici :
     * un dé rouge réussit sur 5 OU 6 (`DeRouge::facesGagnantes()`), un jet de
     * Mind ou de piège réussit sur un crâne (`App\Engine\Des\FaceDeCombat`).
     * On ne fait que RECOPIER cette décision dans la forme que `JetDes.vue`
     * sait déjà comparer.
     *
     * @param  array<string, mixed>  $a  le payload qui porte l'une des trois formes ci-dessus
     * @param  string|null  $nomCible  la cible/victime, si l'appelant la connaît déjà (piège : `personnage`, pas `cible`)
     * @return array<string, mixed>|null null si aucun dé n'a été lancé (cible immunisée à Mind 0, pas de résistance)
     */
    public function desJetUnilateral(array $a, ?string $nomCible = null): ?array
    {
        $nomCible ??= isset($a['cible']['nom']) ? (string) $a['cible']['nom'] : null;

        $desRouges = array_values((array) ($a['des_resistance'] ?? $a['des_rouges'] ?? []));

        if ($desRouges !== []) {
            return $this->avecModificateurs([
                'atk' => [],
                'def' => $desRouges,
                'touchante' => null,
                'defensive' => DeRouge::facesGagnantes(),
                'attaquant' => null,
                'defenseur' => $nomCible,
                'touches' => null,
                'boucliers' => isset($a['degats_annules']) ? (int) $a['degats_annules'] : null,
                'libelle_def' => 'résiste',
            ], $a);
        }

        // Jet de MIND : `mind_cible` est le marqueur — présent même quand
        // AUCUN dé n'a été lancé (Mind 0 = immunisé), c'est ce qui distingue
        // ce cas de « pas de jet de Mind du tout » plutôt que `faces` vide.
        if (array_key_exists('mind_cible', $a)) {
            $faces = array_values((array) ($a['faces'] ?? []));

            if ($faces === []) {
                return null; // immunisé : aucun dé, rien à dessiner
            }

            return $this->avecModificateurs([
                'atk' => [],
                'def' => $faces,
                'touchante' => null,
                'defensive' => 'crane',
                'attaquant' => null,
                'defenseur' => $nomCible,
                'touches' => null,
                'boucliers' => isset($a['succes']) ? (int) $a['succes'] : null,
                'libelle_def' => 'résiste',
            ], $a);
        }

        // PIÈGE de sol : lui seul lance, sans défense en face — `faces` SANS
        // `mind_cible` le distingue du cas précédent (les deux publient une
        // clé `faces`, jamais ensemble).
        if (array_key_exists('faces', $a) && array_key_exists('touches', $a)) {
            $faces = array_values((array) $a['faces']);

            if ($faces === []) {
                return null;
            }

            return $this->avecModificateurs([
                'atk' => $faces,
                'def' => [],
                'touchante' => 'crane',
                'defensive' => null,
                'attaquant' => isset($a['piege']['nom']) ? (string) $a['piege']['nom'] : null,
                'defenseur' => $nomCible,
                'touches' => (int) $a['touches'],
                'boucliers' => null,
            ], $a);
        }

        // Un JET DE COMPÉTENCE de Mind (`ResolveurTour::resoudreJet()`) :
        // `attribut`/`issue`/`faces`, sans `mind_cible` (qui ne marque QUE les
        // sorts qui touchent une victime) ni `touches` (qui ne marque QUE les
        // pièges de sol). Sans cette branche, `avantage_jet_mind` (« +1 dé de
        // Mind ciblé ») modifiait un jet que le fil ne dessinait jamais — un
        // modificateur publié et rendu nulle part est le même défaut qu'un
        // payload muet. Le Body n'a pas ce talent : la garde reste sans risque.
        if (($a['attribut'] ?? null) === 'mind' && array_key_exists('faces', $a) && array_key_exists('issue', $a)) {
            $faces = array_values((array) $a['faces']);

            if ($faces === []) {
                return null;
            }

            return $this->avecModificateurs([
                'atk' => [],
                'def' => $faces,
                'touchante' => null,
                'defensive' => 'crane',
                'attaquant' => null,
                'defenseur' => $nomCible,
                'touches' => null,
                'boucliers' => ! empty($a['succes']) ? 1 : 0,
                'libelle_def' => 'tente',
            ], $a);
        }

        return null;
    }

    /**
     * Colle `modificateurs` (groupe 3, `docs/contrat-api.md` §« Un talent qui
     * s'active tout seul se VOIT ») sur un jet déjà mis en forme, QUAND le
     * payload moteur en porte. Point de passage unique : les DEUX formes de jet
     * (`desDuJet()` à deux camps, `desJetUnilateral()` à une volée) le
     * traversent, jamais une recopie locale.
     *
     * @param  array<string, mixed>  $des
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function avecModificateurs(array $des, array $a): array
    {
        if (isset($a['modificateurs']) && is_array($a['modificateurs']) && $a['modificateurs'] !== []) {
            $des['modificateurs'] = $a['modificateurs'];
        }

        return $des;
    }

    /**
     * Une réaction hors tour ACCEPTÉE : ce qu'elle a fait, et — pour un
     * artefact à plancher — ce que son dé de perte a décidé.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function reaction(array $a): array
    {
        if (empty($a['active'])) {
            return [];
        }

        $nom = (string) ($a['sort'] ?? 'Une réaction');
        $issue = $this->issueReaction($a);

        return [[
            'texte' => $issue !== null ? "{$nom} : {$issue}" : ($a['personnage'] ?? 'Un héros')." — {$nom}",
            'ton' => ! empty($a['artefact_perdu']) ? 'degats' : 'info',
        ]];
    }

    /**
     * CE QUE la réaction a changé, sans son nom — `null` si rien de chiffré.
     *
     * ⚠ Public : la scène de table (`SceneDeTable::depuisReaction()`) le reprend
     * tel quel. Deux formulations d'une même parade auraient dérivé.
     *
     * @param  array<string, mixed>  $a
     */
    public function issueReaction(array $a): ?string
    {
        $victime = (string) ($a['victime'] ?? 'le héros');
        $annules = (int) ($a['degats_annules'] ?? 0);

        $issue = match (true) {
            ($a['action'] ?? null) === ReactionEffet::PLANCHER_PV => "{$victime} reste à 1 PV",
            $annules > 0 => "{$annules} dégât".($annules > 1 ? 's' : '').' annulé'.($annules > 1 ? 's' : '')." pour {$victime}",
            default => null,
        };

        // Le dé de perte des Cendres du Phénix : « on a 5 or 6, this artifact
        // is lost ». René (2026-09-16) : « il faut s'assurer de valider après
        // utilisation si la carte reste ou est détruite » — on le dit.
        if ($issue !== null && isset($a['de_artefact'])) {
            $issue .= ' — dé '.(int) $a['de_artefact'].' : '.(! empty($a['artefact_perdu'])
                ? 'l\'artefact se consume'
                : 'l\'artefact est conservé');
        }

        return $issue;
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
            // Le déplacement reste MUET par principe (le fil raconterait
            // chaque pas). L'avertissement de *Sens du piège* qui vivait ici
            // (« X pressent 2 pièges tout près ») est CONVERGÉ vers le popup
            // `talents_declenches` depuis le 2026-09-25 : la même information,
            // annoncée deux fois, nommait le talent nulle part.
            'deplacement' => [],
            'jet' => $this->jet($a, $acteurNom),
            'desamorcage' => $this->desamorcage($a, $acteurNom),
            'franchissement' => $this->issueSimple($a, $acteurNom, 'franchit la fosse', 'chute dans la fosse'),
            'relever' => [$this->info(($a['libelle'] ?? "{$acteurNom} relève un compagnon"))],
            // ⚠ ÉQUIPER/RANGER ÉTAIENT MUETS (ils tombaient sur `default`) :
            // le fil se taisait sur un geste qui coûte pourtant l'action du
            // tour en pleine quête. Corrigé EN MÊME TEMPS que l'ajout
            // d'échanger/jeter, même règle — un effet que rien n'annonce est
            // injouable.
            'equiper' => [$this->info("{$acteurNom} équipe ".($a['objet'] ?? 'un objet'))],
            'desequiper' => [$this->info("{$acteurNom} range ".($a['objet'] ?? 'un objet'))],
            // ÉCHANGER / JETER (doc 01 §7, 2026-09-17) : jeter est le seul
            // geste du jeu qui détruit de la valeur sans rien rendre — le fil
            // doit le dire aussi clairement que la confirmation le demande
            // côté manette.
            // ⚠ CETTE LIGNE A MENTI À CHAQUE ÉCHANGE (trouvé en jouant à deux
            // le 2026-09-18, jamais par un test). Elle lisait `objet`/`vers`,
            // la forme du DON unidirectionnel de la première livraison ; depuis
            // que l'échange est une SÉANCE, le payload porte `avec`, `donne` et
            // `recu`. Les deux `??` tiraient donc systématiquement et le fil
            // annonçait « donne un objet à un allié » quoi qu'il se passe — un
            // effet annoncé sans être dit, ce qui coûte autant qu'un effet muet.
            // ⚠ La dérive est invisible au diff : le formateur et le résolveur
            // vivent dans deux fichiers, et un repli `??` transforme un champ
            // renommé en phrase plausible plutôt qu'en erreur.
            'echanger' => [$this->info($this->echange($a, $acteurNom))],
            'jeter' => [$this->info("{$acteurNom} jette ".($a['objet'] ?? 'un objet').' — définitif')],
            'attaque_allie' => $this->attaqueOffensive($a['allie'] ?? 'Allié', $a),
            'attaque_monstre' => $this->attaqueMonstre($a),
            'fouille_tresor', 'fouille_mobilier' => $this->fouille($a, $acteurNom),
            'actionner_levier' => $this->levier($a, $acteurNom),
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
            // ⚠ Un objet à charges se BRISE au dernier usage (René, 2026-09-16) :
            // sans cette ligne, l'arc disparaissait de la main de l'elfe sans
            // un mot — et redevenait trouvable dans les coffres sans que
            // personne sache qu'il était parti.
            'objet_detruit' => [$this->info(($a['objet'] ?? 'Un artefact').' de '.($a['personnage'] ?? 'un héros').' est épuisé et se brise')],
            // ⚠ LES RÉACTIONS ÉTAIENT MUETTES : elles retombaient sur `default`.
            // Les Cendres du Phénix sauvaient un héros, lançaient leur dé de
            // perte, et le fil n'en disait rien — ni le sauvetage, ni si
            // l'artefact restait ou se consumait.
            'reaction' => $this->reaction($a),
            'liberer_entraves' => [$this->info(
                ! empty($a['sur_soi'])
                    ? "{$acteurNom} s'arrache aux ronces"
                    : "{$acteurNom} taille les ronces qui retiennent ".($a['cible']['nom'] ?? 'son compagnon'),
            )],
            // Mur de Glace qui fond, ou qui vole en éclats — plan glace phase
            // 2 : sans cette ligne, une case bloquant un couloir disparaîtrait
            // du plateau sans qu'aucune manette ne le dise, et un joueur
            // verrait un passage s'ouvrir de lui-même.
            'glace_dissipee' => [$this->glaceDissipee($a)],
            default => [],
        };
    }

    /**
     * La SÉANCE d'échange, dans les deux sens (doc 01 §7). Une seule action,
     * donc une seule ligne — mais elle doit dire QUOI a circulé, sinon elle ne
     * vaut pas mieux que le silence.
     *
     * @param  array<string, mixed>  $a
     */
    private function echange(array $a, string $acteurNom): string
    {
        $allie = (string) ($a['avec'] ?? 'un allié');
        $donne = $this->pieces($a['donne'] ?? []);
        $recu = $this->pieces($a['recu'] ?? []);

        // Les trois cas réels : on donne, on prend, ou l'on troque. Le troc est
        // le seul que le don unidirectionnel ne savait pas dire.
        if ($donne !== '' && $recu !== '') {
            return "{$acteurNom} échange avec {$allie} : il donne {$donne}, il reçoit {$recu}";
        }

        if ($donne !== '') {
            return "{$acteurNom} donne {$donne} à {$allie}";
        }

        if ($recu !== '') {
            return "{$acteurNom} reçoit {$recu} de {$allie}";
        }

        // Le résolveur refuse une séance vide ; si la ligne arrive quand même,
        // elle le dit plutôt que d'inventer un transfert.
        return "{$acteurNom} ouvre son sac avec {$allie} — rien ne change de main";
    }

    /**
     * « Épée large », « 2 Fioles de soin », « Épée large et 2 Fioles de soin ».
     *
     * @param  mixed  $liste  list<array{objet: string, quantite: int}>
     */
    private function pieces(mixed $liste): string
    {
        $noms = [];

        foreach ((array) $liste as $piece) {
            $nom = (string) ($piece['objet'] ?? '');

            if ($nom === '') {
                continue;
            }

            // ⚠ « Fiole de soin ×2 », jamais « 2 Fioles de soin » : pluraliser
            // un nom de catalogue demanderait de connaître son nombre ET son
            // genre, qui ne sont nulle part en base — même piège que le genre
            // d'un héros, que `DonObjet` contourne déjà en tournant ses phrases
            // sans pronom. La notation `×` est en plus celle que le menu
            // emploie déjà pour une pile (`detail`), donc rien de neuf à lire.
            $quantite = (int) ($piece['quantite'] ?? 1);
            $noms[] = $quantite > 1 ? "{$nom} ×{$quantite}" : $nom;
        }

        if ($noms === []) {
            return '';
        }

        // Le dernier se rattache par « et » : une énumération à virgules seules
        // se lit mal dans un fil qu'on parcourt en pleine partie.
        $dernier = array_pop($noms);

        return $noms === [] ? $dernier : implode(', ', $noms).' et '.$dernier;
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

        // Mur de Glace (plan glace phase 2) : pas de victime, une POSE. Sans
        // cette ligne, une case bloquant le couloir apparaîtrait sur la table
        // sans que le fil du combat n'en dise un mot — le même défaut que le
        // piège muet de 2026-08-05.
        if (isset($a['cases']) && ! isset($a['resultats'])) {
            $compte = count((array) $a['cases']);

            return [[
                'texte' => $compte === 0
                    ? "{$acteurNom} — {$nom} : la glace ne prend nulle part"
                    : "{$acteurNom} — {$nom} : {$compte} case".($compte > 1 ? 's' : '').' de glace pleine se dresse'.($compte > 1 ? 'nt' : ''),
                'ton' => $compte === 0 ? 'echec' : 'info',
            ]];
        }

        // Patinage (plan glace phase 2) : le lanceur se déplace, il ne blesse
        // personne — l'arrivée est ce qui doit se lire.
        if (isset($a['arrivee'])) {
            return [$this->info("{$acteurNom} — {$nom} : patine sur ".($a['cases_franchies'] ?? 0).' case(s) et jaillit ailleurs')];
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

        // Gel de l'Esprit (plan glace phase 2) : une jauge de MIND, pas de
        // Body — la ligne générique plus bas lirait `degats` (absent ici,
        // la clé est `degats_mind`) et afficherait « sans dommage » sur un
        // héros au bord de l'évanouissement.
        if (isset($resultats[0]['pv_mind_apres'])) {
            $r = $resultats[0];
            $cible = $r['cible']['nom'] ?? 'un héros';

            if (! empty($r['cible_tombee'])) {
                return [['texte' => "{$nom} vide l'esprit de {$cible} — il s'effondre !", 'ton' => 'chute']];
            }

            return [[
                'texte' => "{$nom} fige l'esprit de {$cible} (Mind {$r['pv_mind_avant']} → {$r['pv_mind_apres']})",
                'ton' => 'subit',
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

            // Gardée (Forge du Nain) : l'armure absorbe le PREMIER état de ce
            // combat — ce n'est ni une résistance mentale ni un raté, et le
            // dire autrement (la branche générique juste en dessous parle de
            // « résiste ») ferait porter au Mind du héros un mérite qui
            // revient à sa pièce d'équipement.
            if (! empty($r['garde_par_forge'])) {
                $lignes[] = ['texte' => "{$cible} — Gardée absorbe le premier ".($a['condition'] ?? 'effet')." de {$nom}", 'ton' => 'pare'];

                continue;
            }

            // Sort de CONTRÔLE : il pose une condition, il ne blesse pas.
            if (array_key_exists('effet_applique', $r) && ! isset($r['degats'])) {
                $ligne = empty($r['effet_applique'])
                    ? ['texte' => "{$cible} résiste à {$nom}", 'ton' => 'pare']
                    : ['texte' => "{$cible} subit {$nom} — ".($a['condition'] ?? 'affecté'), 'ton' => 'subit'];

                // ⚠ LE JET DE MIND QUI DÉCIDE DE CETTE LIGNE N'ÉTAIT DESSINÉ
                // NULLE PART (René, 2026-09-24) : `ruptureSortDread()` lisait
                // déjà `faces` pour BRISER une condition après coup, en texte
                // brut — jamais au moment où le sort FRAPPE, qui est ici.
                if (($des = $this->desJetUnilateral($r, $cible)) !== null) {
                    $ligne['des'] = $des;
                }

                $lignes[] = $ligne;

                continue;
            }

            $degats = (int) ($r['degats'] ?? 0);

            if (! empty($r['cible_tombee'])) {
                $ligne = ['texte' => "{$nom} terrasse {$cible} !", 'ton' => 'chute'];
            } elseif ($degats > 0) {
                $ligne = ['texte' => "{$nom} frappe {$cible} (−{$degats} PV)", 'ton' => 'subit'];
            } else {
                $ligne = ['texte' => "{$cible} encaisse {$nom} sans dommage", 'ton' => 'pare'];
            }

            // Boule de Flammes du MJ (`des_rouges`, MoteurDread::degatsInfliges()) —
            // même défaut, même correctif que la version héros de ce sort :
            // « one rule, both sides » vaut aussi pour le silence qui allait
            // avec.
            if (($des = $this->desJetUnilateral($r, $cible)) !== null) {
                $ligne['des'] = $des;
            }

            $lignes[] = $ligne;
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
     * Un Mur de Glace fond (le lanceur ne le voit plus) ou vole en éclats
     * (5 crânes cumulés) — plan glace phase 2. Les deux raisons se lisent
     * différemment : la première n'est pas une victoire du groupe, la
     * seconde en est une.
     *
     * @param  array<string, mixed>  $a
     * @return array{texte: string, ton: string}
     */
    private function glaceDissipee(array $a): array
    {
        $compte = count((array) ($a['cases'] ?? []));
        $pluriel = $compte > 1 ? 's' : '';

        if (($a['raison'] ?? null) === 'brisee') {
            return ['texte' => "Le mur de glace se fissure et s'effondre en éclats — {$compte} case{$pluriel} de moins", 'ton' => 'succes'];
        }

        return ['texte' => ($a['monstre'] ?? 'La créature')." ne voit plus sa glace — {$compte} case{$pluriel} fond".($compte > 1 ? 'ent' : ''), 'ton' => 'info'];
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
        $forge = $this->suffixeForge($a);

        if (! empty($a['cible_vaincue'])) {
            return [['texte' => "{$attaquant} terrasse {$cible} !{$des}{$forge}", 'ton' => 'mort']];
        }
        if ($degats > 0) {
            return [['texte' => "{$attaquant} touche {$cible} (−{$degats} PV){$des}{$forge}", 'ton' => 'degats']];
        }

        // ⚠ MANQUÉ ≠ PARÉ. Le repli disait « pare » dans les deux cas, et un
        // joueur en concluait que l'armure adverse était trop bonne quand
        // c'étaient ses propres dés qui échouaient (constaté en partie réelle
        // le 2026-08-13 : « Gobelin pare l'assaut de Borin · 0 crâne »).
        return (int) ($a['touches'] ?? 0) === 0
            ? [['texte' => "{$attaquant} manque {$cible}{$des}{$forge}", 'ton' => 'echec']]
            : [['texte' => "{$cible} pare l'assaut de {$attaquant}{$des}{$forge}", 'ton' => 'pare']];
    }

    /**
     * Ce que la Forge du Nain vient de changer sur CE coup — Perforante
     * (boucliers annulés) et Cruelle (relance consommée). Un effet automatique
     * que rien n'annonce est injouable, et les deux jouent en silence côté
     * dés : sans cette ligne, un joueur verrait un bouclier de moins, ou un
     * jet retenté, sans jamais savoir pourquoi.
     *
     * @param  array<string, mixed>  $a
     */
    private function suffixeForge(array $a): string
    {
        $bouts = [];

        $annules = (int) ($a['boucliers_annules'] ?? 0);
        if ($annules > 0) {
            $bouts[] = "Perforante annule {$annules} bouclier".($annules > 1 ? 's' : '');
        }

        if (! empty($a['cruelle_relance'])) {
            $bouts[] = 'Cruelle relance un dé raté';
        }

        return $bouts === [] ? '' : ' · '.implode(' · ', $bouts);
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
        $lignes = $this->lignesSort($a, $acteurNom);

        // Un sort ÉPARGNÉ (Anneau de sort) restait allumé sur la manette sans
        // que le fil dise pourquoi : un effet automatique que rien n'annonce
        // est injouable (2026-09-25).
        //
        // ⚠ `sort_preserve === 'talent'` (garde_sort_qui_tue — Chant runique,
        // Appel de la forêt) N'EST PLUS dit ici depuis que ce même message
        // part comme popup `ton: talent` (`ResolveurTour::preserverSort()` →
        // `AnnoncesTalents`) : les deux auraient annoncé deux fois la même
        // chose. L'Anneau de sort n'est pas un talent, sa ligne reste seule ici.
        if (! empty($a['sort_preserve']) && $a['sort_preserve'] !== 'talent') {
            $par = $a['sort_preserve_par'] ?? ($a['sort_preserve'] === 'anneau_de_sort' ? 'l\'Anneau de sort' : null);
            $lignes[] = $this->info(($a['sort']['nom'] ?? 'Le sort').' reste disponible'.($par !== null ? " ({$par})" : ''));
        }

        return $lignes;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function lignesSort(array $a, string $acteurNom): array
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
    /**
     * LEVIER — le fil de combat n'en disait RIEN (2026-09-11). Un levier de
     * couloir ouvre souvent une porte hors de vue : sans ligne ici, un jet raté
     * et un jet réussi se ressemblent exactement à l'écran, et le groupe
     * s'éloigne d'un mécanisme qu'il aurait pu retenter.
     *
     * ⚠ Le forçage est RETENTABLE SANS LIMITE — c'est ce qui permet à une salle
     * de ne tenir qu'à ce levier sans jamais se refermer. Le dire fait partie du
     * message, sinon l'échec se lit comme un cul-de-sac.
     *
     * @param  array<string, mixed>  $a
     * @return list<array{texte: string, ton: string}>
     */
    private function levier(array $a, string $acteurNom): array
    {
        $jet = (array) ($a['jet'] ?? []);
        $ouvertes = (array) ($a['portes_ouvertes'] ?? []);

        if (($jet['issue'] ?? null) === 'echec') {
            return [[
                'texte' => "{$acteurNom} force le levier sans succès ("
                    .(int) ($jet['succes'] ?? 0).'/'.(int) ($jet['difficulte'] ?? 0)
                    .') — on peut réessayer',
                'ton' => 'rate',
            ]];
        }

        return [[
            'texte' => $ouvertes === []
                // Réussite sans porte à ouvrir : elle l'était déjà. Le dire
                // évite de chercher un effet qui a eu lieu au tour d'avant.
                ? "{$acteurNom} actionne le levier — le passage est déjà ouvert"
                : "{$acteurNom} actionne le levier : ".count($ouvertes)
                    .(count($ouvertes) > 1 ? ' portes s\'ouvrent' : ' porte s\'ouvre'),
            'ton' => $ouvertes === [] ? 'info' : 'tresor',
        ]];
    }

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
            // ⚠ `objet` MANQUAIT, et le `default` disait « fouille en vain »
            // pendant que le héros empochait la pièce (signalé par René en
            // partie réelle, 2026-09-11 : « on gagne des items quand on cherche
            // mais le message dit cherche en vain »). C'est l'issue du
            // MOBILIER (`MoteurMobilier::tirerButin()`) — le coffre du deck ne
            // rend que de l'or, des potions et des artefacts, jamais d'objet
            // d'équipement. `ChoixController` la connaissait pourtant déjà
            // (`'objet' => 'mobilier_objet'`) : la narration était juste, le
            // fil de combat mentait. Un `match` sans cas et un `default`
            // rassurant, c'est une issue muette qui se raconte à l'envers.
            'objet' => [[
                'texte' => "{$acteurNom} trouve ".($a['objet']['nom'] ?? 'un objet'),
                'ton' => 'tresor',
            ]],
            'errant' => [[
                'texte' => ($a['monstre']['nom'] ?? 'Un monstre').' surgit du coffre !',
                'ton' => 'subit',
            ]],
            'piege' => [],
            // ⚠ « En vain » n'est vrai QUE si rien n'était à prendre. Deux cas
            // distincts s'y cachaient : un meuble dont le butin existait mais
            // que PERSONNE du groupe ne pouvait utiliser (`objet_indisponible`,
            // règle de l'étal — « un meuble ne rend plus une potion que
            // personne sur place ne peut boire »), et un errant qu'aucun budget
            // ne permettait de faire sortir. Les dire, c'est la différence
            // entre « il n'y avait rien » et « il y avait quelque chose, pas
            // pour vous ».
            default => [$this->info(match (true) {
                ! empty($a['objet_indisponible']) => "{$acteurNom} ne trouve rien que le groupe puisse utiliser",
                ! empty($a['errant_indisponible']) => "{$acteurNom} fouille — un bruit, puis plus rien",
                default => "{$acteurNom} fouille en vain",
            })],
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

        $ligneDeclenchement = ['texte' => "{$piege} se déclenche sur {$nom} !", 'ton' => 'subit'];

        // Piège à lances (1 dé) / Chute de blocs (3 dés) : le jet était
        // calculé, publié (`faces`/`touches`), et dessiné nulle part — cette
        // méthode gère aussi bien le piège en action PRINCIPALE que celui
        // IMBRIQUÉ (`declenchement`/`pieges_declenches`), donc les deux
        // chemins récupèrent leurs dés ici, au même endroit.
        if (($des = $this->desJetUnilateral($a, $nom)) !== null) {
            $ligneDeclenchement['des'] = $des;
        }

        $lignes = [$ligneDeclenchement];

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
