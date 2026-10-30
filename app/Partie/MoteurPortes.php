<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Carte;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;

/**
 * Moteur des portes (doc 14 §3.1 portes secrètes + §3.3 portes à restriction) —
 * résolu en code, jamais par l'IA. L'ÉTAT des portes vit dans la grille JSON de
 * la carte (cartes.grille.portes), chaque entrée :
 *   {x, y, etat: ouverte|fermee|verrouillee|secrete, verrou?, revele?}
 *
 * Une porte NON `ouverte` est infranchissable et opaque (Grille::definirPortes).
 * `fermee` (correctifs E2) = simplement close, SANS verrou : n'importe quel héros
 * adjacent l'ouvre librement (action « Ouvrir la porte », interaction qui ne
 * consomme aucun créneau) — on s'arrête devant, on ouvre, on continue. C'est
 * l'état par défaut des portes inter-salles posées par AssembleurCarte.
 * Verrous gérés (décisions Vague 2) :
 *  - `cle`              : un héros adjacent possédant l'objet ouvre la porte ;
 *  - `monstres_vaincus` : ouverture auto quand toutes les instances désignées
 *                         sont vaincues (hook post-combat) ;
 *  - `levier`           : action « Actionner le levier » au contact d'un levier.
 * (« Traverser la Pierre » franchit déjà les murs ; le verrou `jet`-pour-forcer
 *  est reporté.)
 */
final class MoteurPortes
{
    /** Rayon (Manhattan) d'une fouille de zone — aligné sur MoteurPieges. */

    public const ETAT_OUVERTE = 'ouverte';

    /** Close mais SANS verrou : ouvrable librement par un héros adjacent (E2). */
    public const ETAT_FERMEE = 'fermee';

    public const ETAT_VERROUILLEE = 'verrouillee';

    public const ETAT_SECRETE = 'secrete';

    /**
     * MIROIR (The Mage of the Mirror, F7539 PDF 3 : « These standing mirrors are secret portals that lead to great treasure
     * and hidden rooms ») — un HABILLAGE du passage secret, pas une règle (René, 2026-10-29, plan des restes lot C) : sous le
     * thème `mage_du_miroir`, `AssembleurCarte` pose `habillage` sur la porte secrète au moment où il la crée, et la carte le
     * garde. Elle se trouve, s'ouvre et se franchit exactement comme tout passage secret ; seuls son nom (menu, légende) et
     * son dessin changent. Les cartes du livret ne la dessinent pas : elles l'écrivent « MIR » (F7539 PDF 17) — d'où aucun
     * symbole de livret, la teinte du client fait le repli.
     */
    public const HABILLAGE_MIROIR = 'miroir';

    /**
     * PORTE DE PIERRE (Against the Ogre Horde, livret F9528 p. 4, lot B) :
     * `verrou.type` sur une entrée `fermee` ordinaire — même modèle que
     * `cle`/`levier`/`monstres_vaincus` — jamais ouvrable à main nue
     * (`ouvrableAMain()` l'exclut déjà, aucun verrou `cle` ne correspond).
     * Résolue par `ResolveurTour::resoudreForcerPortePierre()` : « a hero
     * rolls their base Attack dice. If the roll result includes two skulls,
     * the heavy stone door swings open. » Posée UNIQUEMENT sur une arête de
     * BOUCLE par `AssembleurCarte::marquerPortesDePierre()` — jamais le seul
     * chemin vers l'objectif, par construction.
     */
    public const VERROU_PIERRE = 'pierre';

    /**
     * « The wizard rolls 1 Attack die, and therefore cannot open a stone
     * doorway. » Nombre minimum de dés d'attaque DE BASE (`des_attaque`,
     * valeur à mains nues de la classe — jamais l'arme) pour que le menu
     * propose la tentative (« le menu n'offre pas ce que le résolveur
     * refuse ») et pour que le résolveur l'accepte.
     */
    public const DES_MINIMUM_PORTE_PIERRE = 2;

    /**
     * PORTE À CONDITION (Kellar's Keep, F4543 p. 19 note A et p. 25 note D) : `verrou.type === VERROU_CONDITION` sur une
     * entrée `fermee` ordinaire, comme la porte de pierre. Le héros se tient à côté, lance DEUX dés rouges (UN seul pour le
     * Nain, quand la condition le dit), et la porte s'ouvre si le total remplit la condition du verrou. Un échec ferme le
     * tour du héros : « If they fail to open the door, their turn ends » (les deux textes).
     *
     * Posée UNIQUEMENT sur une arête de BOUCLE par `AssembleurCarte::marquerPortesDeCondition()`, au plus une par carte — la
     * même garde que la porte de pierre : les deux salles qu'elle relie sont déjà reliées par l'arbre couvrant, donc une
     * condition qu'un héros ne remplit jamais le laisse passer par l'autre route. Jamais le seul chemin, par construction.
     */
    public const VERROU_CONDITION = 'condition';

    /**
     * GRILLE DE FER (Mage of the Mirror, F7539 p. 4 « Portcullises x4 », quête 4 note B, quête 7 note D, quête 10 note G) :
     * `verrou.type` sur une entrée `fermee` ordinaire, comme la porte à condition. Close, elle bloque le passage ET la vue
     * (« Heroes and monsters cannot see through a portcullis until it is opened » — c'est déjà le cas de toute porte non
     * ouverte, `Grille::definirPortes()`). Deux voies d'ouverture :
     *  - la CLÉ : le héros au contact porte la « Clé de laiton » (`possedeCle()`, lecteur existant, `verrou.objet_id`) ;
     *  - la FORCE : deux dés rouges, total strictement sous le Corps de DÉPART (`FORCE_GRILLE`, précédent sourcé de la porte
     *    verrouillée de Kellar's Keep, F4543 p. 19 note A : « their starting number of Body Points »). Un échec ferme le tour.
     *
     * Posée UNIQUEMENT sur une arête de BOUCLE par `AssembleurCarte::marquerPortesDeGrille()`, au plus une par carte, sous le
     * thème `mage_du_miroir` : jamais le seul chemin, par construction.
     */
    public const VERROU_GRILLE = 'grille';

    /** Le nom du catalogue de la clé d'une grille (F7539 p. 6, « Brass Key »). Hors marché, unique, jamais dans le butin. */
    public const CLE_DE_LAITON = 'Clé de laiton';

    /**
     * Les verrous qu'une CLÉ ouvre (`possedeCle()`, `ouvrir_porte` « (clé) »). Une seule liste : le menu et le résolveur la lisent.
     */
    public const VERROUS_A_CLE = ['cle', self::VERROU_GRILLE];

    /**
     * La FORCE d'une grille : les paramètres de la porte verrouillée de Kellar's Keep, copiés tels quels — deux dés rouges,
     * strictement sous le Corps de départ, pas d'exemption. Une seule copie de la règle : `franchit()` et
     * `valeurDeComparaison()` la lisent, comme pour la porte à condition. Pas une entrée de `CONDITIONS` : `CONDITIONS` est tiré
     * au sort par `marquerPortesDeCondition()`, et y ajouter une ligne décalerait le tirage des donjons de Kellar's Keep.
     *
     * @var array{libelle: string, attribut: string, comparaison: string, des: int, nain_des: ?int}
     */
    public const FORCE_GRILLE = self::CONDITIONS['porte_verrouillee'];

    /**
     * Les deux portes à condition de Kellar's Keep, avec leurs paramètres SOURCÉS. Leur valeur de comparaison suit
     * l'attribut : le Corps se compare aux Corps de DÉPART (`pv_body_max` — « all Body and Mind Points are restored between
     * quests », F4543 p. 4, la quête commence à pleine santé), l'Esprit à l'Esprit ACTUEL (`pv_mind`).
     *
     * Copiés TELS QUELS dans `verrou` au placement : le résolveur et le menu ne relisent jamais cette table, ils lisent la
     * porte — une seule copie de la règle, dans la carte.
     *
     * @var array<string, array{libelle: string, attribut: string, comparaison: string, des: int, nain_des: ?int}>
     */
    public const CONDITIONS = [
        // F4543 p. 19 (quête 6, « The Great Citadel », note A) : « To open the door, a hero must stand next to it and roll 2
        // red dice. If they roll less than their starting number of Body Points, the door opens. » Le texte dit « less than » :
        // STRICT. Aucune exemption du Nain.
        'porte_verrouillee' => [
            'libelle' => 'porte verrouillée',
            'attribut' => 'corps', 'comparaison' => 'strict', 'des' => 2, 'nain_des' => null,
        ],
        // F4543 p. 25 (quête 9, « The East Gate », note D) : « roll 2 red dice. That hero may pass through the gate only if the
        // total number rolled is equal to or less than their current number of Mind Points. […] The dwarf needs to roll only 1
        // die. » Le texte dit « equal to or less than » : INCLUSIF.
        'porte_de_l_est' => [
            'libelle' => "porte de l'Est",
            'attribut' => 'esprit', 'comparaison' => 'inclusif', 'des' => 2, 'nain_des' => 1,
        ],
    ];

    /**
     * ROTATION du pictogramme d'une porte (`symbole_rotation`, `0` ou `90`) — la décision du serveur, publiée telle quelle
     * (René, 2026-10-24 : « la porte devrait être retournée de 90 degrés quand elle est reliée à l'est ou à l'ouest »).
     *
     * Le dessin du livret est une porte de MUR HORIZONTAL : son battant est couché, et la porte relie la case du NORD à
     * celle du SUD (F2847 p. 33 ; la pierre de F9528 p. 4 est dessinée de même). Une porte qui relie l'EST et l'OUEST,
     * percée dans un mur VERTICAL, se tourne donc de 90°. On lit les deux cases de la JONCTION (`casesPorte()`), et non le
     * nom de l'arête : elles ne partagent pas la même colonne exactement quand la porte relie l'est et l'ouest.
     *
     * @param  array{x: int, y: int, cote?: string}  $porte
     */
    public static function rotationSymbole(array $porte): int
    {
        [$a, $b] = Grille::casesPorte($porte);

        return $a['x'] !== $b['x'] ? 90 : 0;
    }

    /** La valeur que le jet doit atteindre pour CE héros : ses Corps de départ, ou son Esprit actuel (`CONDITIONS`). */
    public static function valeurDeComparaison(array $verrou, Personnage $heros): int
    {
        return $verrou['attribut'] === 'corps' ? (int) $heros->pv_body_max : (int) $heros->pv_mind;
    }

    /** Le nombre de dés que lance CE héros : le Nain n'en lance qu'un quand la condition le dit (`nain_des`). */
    public static function desPourHeros(array $verrou, Personnage $heros): int
    {
        return ($verrou['nain_des'] ?? null) !== null && $heros->classe === 'nain'
            ? (int) $verrou['nain_des']
            : (int) $verrou['des'];
    }

    /**
     * Le total franchit-il la condition ? `strict` : strictement sous la valeur ; `inclusif` : au plus égal. Le seul point de
     * passage de cette question, lu par le résolveur ET par le libellé du menu.
     */
    public static function franchit(array $verrou, int $total, int $valeur): bool
    {
        return $verrou['comparaison'] === 'strict' ? $total < $valeur : $total <= $valeur;
    }

    /**
     * Le libellé de l'option, DÉCIDÉ ici avec les chiffres du héros : « 2 dés rouges, total inférieur à ton Corps de départ
     * (8) » — le menu publie la condition, la manette ne la recalcule pas.
     */
    public static function libelleCondition(array $verrou, Personnage $heros): string
    {
        $des = self::desPourHeros($verrou, $heros);
        $nombre = $des === 1 ? '1 dé rouge' : "{$des} dés rouges";
        $valeur = self::valeurDeComparaison($verrou, $heros);
        $attribut = $verrou['attribut'] === 'corps' ? 'Corps de départ' : 'Esprit actuel';
        $condition = $verrou['comparaison'] === 'strict' ? "inférieur à ton {$attribut}" : "au plus égal à ton {$attribut}";

        return "{$nombre}, total {$condition} ({$valeur})";
    }

    /** Une porte close sans verrou s'ouvre à la main, sans clé ni levier (E2). */
    public function ouvrableAMain(array $porte): bool
    {
        return ($porte['etat'] ?? self::ETAT_OUVERTE) === self::ETAT_FERMEE
            && ($porte['verrou']['type'] ?? null) === null;
    }

    /**
     * Portes brutes de la carte.
     *
     * @return list<array<string, mixed>>
     */
    public function portes(Carte $carte): array
    {
        return (array) ($carte->grille['portes'] ?? []);
    }

    /**
     * Met à jour (fusionne) l'entrée de porte d'index donné et persiste.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function changer(Carte $carte, int $index, array $attrs): void
    {
        $grille = $carte->grille;

        if (! isset($grille['portes'][$index])) {
            return;
        }

        $grille['portes'][$index] = array_merge($grille['portes'][$index], $attrs);
        $carte->update(['grille' => $grille]);
    }

    /**
     * Toutes les portes FERMÉES qu'un héros voit et atteint depuis la case (x, y) —
     * verrouillées comprises — avec leur index, dans l'ordre des portes. Une porte
     * est retenue si son EMBRASURE (`Grille::caseEmbrasure()`) est orthogonalement
     * adjacente à (x, y) : la géométrie du passage, sans seconde copie.
     *
     * ⚠ UNE LISTE, jamais une seule porte. Un héros dans un coin peut toucher DEUX
     * portes closes ; l'ancienne méthode n'en rendait qu'une, le menu n'offrait
     * qu'elle, le harnais affichait l'autre « à portée », et un choix forgé sur
     * celle-ci était refusé (422, 2026-10-17). Menu et résolveur lisent CETTE liste
     * et seulement elle.
     *
     * ⚠ Une porte SECRÈTE non trouvée n'y figure jamais : pour le héros, c'est un
     * mur. Une Bénédiction de l'Oracle offerte à côté d'elle révélerait la salle
     * derrière un passage que personne n'a trouvé.
     *
     * « Adjacente » teste la case d'EMBRASURE, pas l'égalité à l'une des deux
     * `casesPorte()` (René, 2026-09-11 : une porte non ouverte bloque maintenant sa
     * case). Un héros qui s'arrête devant une porte close, depuis l'intérieur de la
     * salle, ne serait jamais sur aucune des deux cases de passage.
     *
     * @return list<array{index: int, porte: array<string, mixed>}>
     */
    public function portesFermeesAdjacentes(Carte $carte, int $x, int $y): array
    {
        return $this->portesAdjacentes(
            $carte, $x, $y,
            fn (array $porte) => ($porte['etat'] ?? self::ETAT_OUVERTE) !== self::ETAT_OUVERTE
                && ($porte['etat'] ?? null) !== self::ETAT_SECRETE,
        );
    }

    /**
     * LE scan unique des portes adjacentes à une case : seul le FILTRE change d'une
     * liste à l'autre. Deux boucles copiées auraient fini par diverger.
     *
     * @param  callable(array<string, mixed>): bool  $retenue
     * @return list<array{index: int, porte: array<string, mixed>}>
     */
    private function portesAdjacentes(Carte $carte, int $x, int $y, callable $retenue): array
    {
        $salles = (array) ($carte->grille['salles'] ?? []);
        $trouvees = [];

        foreach ($this->portes($carte) as $index => $porte) {
            if (! $retenue($porte)) {
                continue;
            }

            $embrasure = Grille::caseEmbrasure($porte, $salles);
            if (Grille::sontAdjacentes((int) $embrasure['x'], (int) $embrasure['y'], $x, $y)) {
                $trouvees[] = ['index' => $index, 'porte' => $porte];
            }
        }

        return $trouvees;
    }

    /**
     * Portes DE PIERRE (`verrou.type === VERROU_PIERRE`), non encore ouvertes,
     * adjacentes à la case — même géométrie et même liste que
     * `portesFermeesAdjacentes()`, avec un autre filtre. Une porte de pierre n'est
     * jamais `ouvrableAMain()` ni à clé : elle ne se mêle à aucune autre liste.
     *
     * @return list<array{index: int, porte: array<string, mixed>}>
     */
    public function portesDePierreAdjacentes(Carte $carte, int $x, int $y): array
    {
        return $this->portesAdjacentes(
            $carte, $x, $y,
            fn (array $porte) => ($porte['etat'] ?? self::ETAT_OUVERTE) !== self::ETAT_OUVERTE
                && ($porte['verrou']['type'] ?? null) === self::VERROU_PIERRE,
        );
    }

    /**
     * Portes À CONDITION (`verrou.type === VERROU_CONDITION`) non ouvertes, adjacentes à la case (x, y) — même géométrie et
     * même liste que `portesDePierreAdjacentes()`, avec le filtre de la condition. Lu par le menu ET par le résolveur.
     *
     * @return list<array{index: int, porte: array<string, mixed>}>
     */
    public function portesConditionAdjacentes(Carte $carte, int $x, int $y): array
    {
        return $this->portesAdjacentes(
            $carte, $x, $y,
            fn (array $porte) => ($porte['etat'] ?? self::ETAT_OUVERTE) !== self::ETAT_OUVERTE
                && ($porte['verrou']['type'] ?? null) === self::VERROU_CONDITION,
        );
    }

    /**
     * Grilles de fer NON ouvertes adjacentes à la case (x, y) — même géométrie et même liste que `portesConditionAdjacentes()`.
     * Lu par le menu (option `forcer_grille`) ET par le résolveur : une grille ne se force que depuis une case où elle est listée.
     *
     * @return list<array{index: int, porte: array<string, mixed>}>
     */
    public function portesGrilleAdjacentes(Carte $carte, int $x, int $y): array
    {
        return $this->portesAdjacentes(
            $carte, $x, $y,
            fn (array $porte) => ($porte['etat'] ?? self::ETAT_OUVERTE) !== self::ETAT_OUVERTE
                && ($porte['verrou']['type'] ?? null) === self::VERROU_GRILLE,
        );
    }

    /**
     * La direction de la porte VUE DEPUIS la case (x, y) — « au nord », « à l'ouest »… —
     * lue dans `Rayon::DIRECTIONS`, le seul vocabulaire cardinal. Null si l'embrasure
     * n'est pas orthogonalement voisine de (x, y). Le libellé d'un geste sur une porte
     * porte ce mot quand plusieurs portes sont offertes (2026-10-17).
     *
     * @param  array<string, mixed>  $porte
     */
    public function directionDepuis(Carte $carte, array $porte, int $x, int $y): ?string
    {
        $embrasure = Grille::caseEmbrasure($porte, (array) ($carte->grille['salles'] ?? []));

        return Rayon::phrasePour($embrasure['x'] - $x, $embrasure['y'] - $y);
    }

    /**
     * Le levier `$levierId` a-t-il encore une porte à ouvrir ?
     *
     * LE SEUL prédicat de « déjà forcé » : le menu (`MenuMoteur`) ne propose que
     * les leviers pour lesquels il est vrai, et le résolveur le relit pour refuser
     * le geste. Un levier forcé n'a pas d'état propre — sa porte, elle, est
     * ouverte, et c'est elle qui dit tout. Une seconde copie de cette règle aurait
     * laissé un levier déjà forcé proposé de nouveau (Morcar, 2026-10-09 : un
     * geste qui ne fait plus rien, offert par le menu).
     */
    public function levierAOuvrir(Carte $carte, string $levierId): bool
    {
        foreach ($this->portes($carte) as $porte) {
            if (($porte['verrou']['type'] ?? null) === 'levier'
                && (string) ($porte['verrou']['levier_id'] ?? '') === $levierId
                && ($porte['etat'] ?? null) !== self::ETAT_OUVERTE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Leviers orthogonalement adjacents à (x, y). `actionnable` : le levier a
     * encore une porte à ouvrir ({@see levierAOuvrir()}) — un levier déjà forcé
     * reste visible sur la carte, mais n'est plus une action proposée.
     *
     * @return list<array{x: int, y: int, levier_id: string, actionnable: bool}>
     */
    public function leviersAdjacents(Carte $carte, int $x, int $y): array
    {
        $adjacents = [];

        foreach ((array) ($carte->grille['leviers'] ?? []) as $levier) {
            if (Grille::sontAdjacentes((int) $levier['x'], (int) $levier['y'], $x, $y)) {
                $levierId = (string) ($levier['levier_id'] ?? '');

                $adjacents[] = [
                    'x' => (int) $levier['x'],
                    'y' => (int) $levier['y'],
                    'levier_id' => $levierId,
                    'actionnable' => $this->levierAOuvrir($carte, $levierId),
                ];
            }
        }

        return $adjacents;
    }

    /**
     * Fouille RÉUSSIE : révèle les portes SECRÈTES de la ZONE du fouilleur —
     * sa salle ou son couloir, en entier, sans rayon ni ligne de vue (René,
     * 2026-09-27, voir `ZoneFouille`) ; une porte en est si l'une de ses deux
     * cases y tombe. Elles passent `revele:true` + FERMÉES. Trouver n'est pas
     * franchir : il reste à les ouvrir.
     *
     * @return list<array{x: int, y: int}> portes révélées
     */
    public function revelerSecretesAutour(Groupe $groupe, Carte $carte, Personnage $personnage, ZoneFouille $zone): array
    {
        // ⚠ Remplacé le 2026-09-27 par la ZONE (salle ou couloir) — historique :
        // ⚠ LIGNE DE VUE ajoutée le 2026-09-18, en même temps que celle de
        // `MoteurPieges::revelerAutour()` — MÊME défaut, MÊME ligne, dans la
        // jumelle appelée deux lignes plus loin par la fouille de zone. René
        // n'avait signalé que le piège vu derrière une porte fermée ; corriger
        // le piège sans la porte secrète aurait laissé le défaut identique dans
        // le geste identique, à retrouver au prochain playtest.
        // `revelerSecretesEnVue()` filtrait déjà ainsi : la fouille n'avait
        // simplement jamais reçu de grille.
        return $this->revelerSecretes(
            $groupe, $carte, $personnage,
            fn (array $porte) => $zone->contientPorte($porte),
            'fouille',
        );
    }

    /**
     * `detection_portes_secretes` (Parler à la pierre du nain, Lecture des lieux
     * de l'explorateur) : les portes secrètes ORTHOGONALEMENT adjacentes se
     * révèlent d'elles-mêmes, sans jet et sans action.
     *
     * ⚠ Le pendant exact de l'Œil du mineur pour les pièges, et volontairement
     * de la même portée : à rayon de fouille, le talent rendrait la fouille de
     * zone inutile pour son porteur ; à une case, il récompense l'exploration
     * prudente sans la remplacer.
     *
     * Sans le talent : aucun effet, la méthode rend une liste vide.
     *
     * @return list<array{x: int, y: int}> portes révélées
     */
    public function detecterSecretesAdjacentes(Groupe $groupe, Carte $carte, Personnage $personnage, int $x, int $y): array
    {
        $noeud = app(Talents::class)->noeud($personnage, 'detection_portes_secretes');

        if ($noeud === null) {
            return [];
        }

        $reveles = $this->revelerSecretes(
            $groupe, $carte, $personnage,
            fn (array $porte) => Grille::sontAdjacentes((int) $porte['x'], (int) $porte['y'], $x, $y),
            'talent',
        );

        // Un talent qui s'active tout seul se VOIT (2026-09-25).
        if ($reveles !== []) {
            $nombre = count($reveles);
            app(AnnoncesTalents::class)->annoncer($personnage, $noeud, $nombre > 1
                ? "révèle {$nombre} portes secrètes adjacentes"
                : 'révèle une porte secrète adjacente');
        }

        return $reveles;
    }

    /**
     * POTION DE VISION (Elfe) : « see all secret doors […] within their line of
     * sight » (carte © 2023). Miroir exact de `MoteurPieges::revelerEnVue()` —
     * les deux moitiés de la potion doivent obéir à la même géométrie.
     *
     * @return list<array{x: int, y: int}>
     */
    public function revelerSecretesEnVue(Groupe $groupe, Carte $carte, Personnage $personnage, Grille $grille, int $x, int $y): array
    {
        return $this->revelerSecretes(
            $groupe, $carte, $personnage,
            fn (array $porte) => $grille->ligneDeVue($x, $y, (int) $porte['x'], (int) $porte['y']),
            'clairvoyance',
        );
    }

    /**
     * Le révélateur, dont seul le FILTRE change — factorisé quand la Potion de
     * vision a demandé une seconde géométrie. Deux copies de « une porte
     * secrète devient FERMÉE et révélée » auraient fini par diverger.
     *
     * @return list<array{x: int, y: int}>
     */
    private function revelerSecretes(Groupe $groupe, Carte $carte, Personnage $personnage, callable $filtre, string $methode): array
    {
        $reveles = [];

        foreach ($this->portes($carte) as $index => $porte) {
            if (($porte['etat'] ?? null) !== self::ETAT_SECRETE || ($porte['revele'] ?? false) || ! $filtre($porte)) {
                continue;
            }

            // ⚠ FERMÉE, pas ouverte (arbitrage de René, 2026-09-11 : « un passage
            // secret trouvé devrait l'afficher comme une porte fermée, on peut
            // maintenant interagir avec pour l'ouvrir »). Trouver un passage et
            // le franchir sont DEUX actes, comme au plateau — et la fouille
            // révélait jusqu'ici la salle derrière du même geste, puisque toute
            // ouverture de porte révèle sa salle. Un jet de Mind réussi donnait
            // donc la pièce ET son coffre sans qu'on ait à s'en approcher.
            //
            // `revele` reste posé : l'état n'est plus `secrete`, donc le
            // déguisement en mur de `EtatGroupe::portes()` ne s'applique plus
            // — mais le drapeau garde la trace de CE QUI a été trouvé, et c'est
            // lui qui distingue une porte découverte d'une porte ordinaire.
            $this->changer($carte, $index, ['revele' => true, 'etat' => self::ETAT_FERMEE]);
            $reveles[] = ['x' => (int) $porte['x'], 'y' => (int) $porte['y']];
        }

        if ($reveles !== []) {
            $payload = [
                'type' => 'portes_secretes_revelees',
                'methode' => $methode,
                'portes' => $reveles,
                'personnage' => $personnage->nom,
            ];

            Journal::ajouter($groupe, 'action', $payload, ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom]);

            // Même raison que `MoteurPieges::reveler()` : la Potion de vision est la
            // seule détection que rien d'autre ne raconte.
            if ($methode === 'clairvoyance') {
                app(TamponAnnonces::class)->ajouter($payload);
            }
        }

        return $reveles;
    }

    /**
     * Ouvre la porte fermée d'index donné (verrou satisfait) — état persistant.
     * Journalise l'ouverture.
     *
     * @param  array<string, mixed>|null  $acteur
     */
    public function ouvrir(Groupe $groupe, Carte $carte, int $index, string $cause, ?array $acteur = null): void
    {
        $porte = $this->portes($carte)[$index] ?? null;

        if ($porte === null) {
            return;
        }

        $this->changer($carte, $index, ['etat' => self::ETAT_OUVERTE, 'revele' => true]);

        // Un SEUIL large de 2 cases est fait de deux arêtes-portes côte à côte
        // (AssembleurCarte) : elles s'ouvrent ENSEMBLE, sinon le passage
        // resterait un goulot d'une case — exactement ce que l'élargissement
        // vise à supprimer.
        //
        // ⚠ Seulement les portes du MÊME seuil, pas toute la jonction. Un
        // couloir a DEUX seuils (un par salle) qui partagent le même
        // `jonction` : les grouper tous ouvrait les 4 portes d'un coup, donc
        // celles du bout opposé du couloir. Le groupe arrivait au bout d'un
        // corridor et trouvait la porte déjà ouverte, sans jamais la pousser —
        // et la salle d'en face restait NON révélée, ouverte mais noire
        // (constaté en partie réelle par René, 2026-08-07).
        foreach ($this->portes($carte) as $autre => $p) {
            if ($autre !== $index
                && $this->memeSeuil($porte, $p)
                && ($p['etat'] ?? null) !== self::ETAT_OUVERTE) {
                $this->changer($carte, $autre, ['etat' => self::ETAT_OUVERTE, 'revele' => true]);
            }
        }

        Journal::ajouter($groupe, 'action', [
            'type' => 'porte_ouverte',
            'cause' => $cause,
            'porte' => ['x' => (int) $porte['x'], 'y' => (int) $porte['y'], 'cote' => (string) ($porte['cote'] ?? 'e')],
        ], $acteur);
    }

    /**
     * Deux portes forment-elles le MÊME seuil (les 2 voies d'un passage large) ?
     *
     * Même jonction ET même ligne de front : deux portes `e` du même seuil
     * partagent leur `x` (elles sont l'une au-dessus de l'autre), deux portes
     * `s` partagent leur `y`. Les deux seuils d'un couloir partagent la
     * jonction mais PAS cette coordonnée — c'est ce qui les distingue.
     *
     * Dérivé de la géométrie plutôt que d'un champ ajouté : les cartes déjà
     * en base sont ainsi corrigées elles aussi, sans régénération.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function memeSeuil(array $a, array $b): bool
    {
        if (($a['jonction'] ?? null) === null || ($a['jonction'] ?? null) !== ($b['jonction'] ?? null)) {
            return false;
        }

        $cote = (string) ($a['cote'] ?? 'e');

        if ($cote !== (string) ($b['cote'] ?? 'e')) {
            return false;
        }

        // Porte EST : l'arête est verticale, le seuil s'étend en Y → même X.
        // Porte SUD : l'arête est horizontale, le seuil s'étend en X → même Y.
        return $cote === 'e'
            ? (int) $a['x'] === (int) $b['x']
            : (int) $a['y'] === (int) $b['y'];
    }

    /**
     * Auto-ouverture des portes à verrou `monstres_vaincus` : toute porte
     * verrouillée dont TOUTES les instances désignées sont vaincues s'ouvre.
     * Appelé après chaque résolution de combat (hook post-action).
     *
     * @return list<array{x: int, y: int, cote: string}> portes ouvertes ce passage
     */
    public function ouvrirParMonstresVaincus(Groupe $groupe, Quete $quete): array
    {
        $carte = $quete->carte;

        if ($carte === null) {
            return [];
        }

        $ouvertes = [];

        foreach ($this->portes($carte) as $index => $porte) {
            if (($porte['etat'] ?? null) === self::ETAT_OUVERTE) {
                continue;
            }
            if (($porte['verrou']['type'] ?? null) !== 'monstres_vaincus') {
                continue;
            }

            $instances = array_map('intval', (array) ($porte['verrou']['instances'] ?? []));

            if ($instances === []) {
                continue;
            }

            $restants = $quete->instancesMonstres()
                ->whereIn('id', $instances)
                ->where('etat', '!=', 'vaincu')
                ->count();

            if ($restants === 0) {
                $this->ouvrir($groupe, $carte, $index, 'monstres_vaincus');
                // On rend la porte COMPLÈTE (`cote` compris) : l'appelant en a
                // besoin pour révéler la salle derrière — une porte est une
                // arête, `x`/`y` seuls ne suffisent pas à retrouver ses deux cases.
                $ouvertes[] = [
                    'x' => (int) $porte['x'],
                    'y' => (int) $porte['y'],
                    'cote' => (string) ($porte['cote'] ?? 'e'),
                ];
            }
        }

        return $ouvertes;
    }

    /**
     * Le héros possède-t-il l'objet-clé d'un verrou `cle` ?
     */
    public function possedeCle(Personnage $personnage, array $verrou): bool
    {
        $objetId = (int) ($verrou['objet_id'] ?? 0);

        return $objetId > 0 && $personnage->inventaire()->where('objet_id', $objetId)->exists();
    }
}
