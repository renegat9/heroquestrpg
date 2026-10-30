<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\LanceurDes;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Support\Journal;
use Illuminate\Validation\ValidationException;

/**
 * FOSSE LONGUE — *The Mage of the Mirror* (F7539 p. 6, « Long Pit Trap »), relu au livret :
 *
 * « If a hero wants to jump over this pit the long way, they must have at least three squares of movement left after
 * they move next to the pit. The hero then rolls one combat die. A black shield means the hero has jumped across safely,
 * using up three squares of movement. […] Any result other than a black shield means the hero falls into the pit,
 * suffering 2 Body Points of damage. On a subsequent turn, the hero must roll a 5 or 6 on a red die to climb out of the
 * pit. While in the pit, the hero rolls one less combat die when attacking or defending (but never less than one die). »
 *
 * ── Trois temps, tous DURABLES (`etat_personnage_quete.dans_la_fosse`, jamais le cache)
 *
 *  1. LE SAUT PAR LE BOUT (`sautsLongs()`, option `franchissement` avec `parametres.saut = 'long'`) — le grand côté de
 *     la fosse : depuis une case voisine d'un BOUT, à trois cases de déplacement au moins, un dé de combat ; bouclier
 *     noir = atterrissage de l'autre côté, 3 cases dépensées, la suite du mouvement reste. Sinon le héros tombe dans la
 *     PREMIÈRE case de fosse de sa ligne. Le PETIT côté ne passe pas par ici : c'est le saut ordinaire de la Fosse
 *     (`franchissable`, `ResolveurTour::resoudreFranchissement()`), réutilisé tel quel.
 *  2. LA CHUTE (`MoteurPieges::declencher()`) — 2 PV de Body par le catalogue ; la fosse reste ouverte, et le héros y
 *     reste (`dans_la_fosse`). Son tour s'achève, comme pour tout piège de sol.
 *  3. LA SORTIE (`optionsSortie()` / `resoudreSortie()`, option `sortie_fosse`) — à un tour suivant, le héros se hisse
 *     sur UNE case libre qui touche la fosse, au choix : un dé rouge, 5 ou 6 pour réussir, sinon il reste dans le trou.
 *     La sortie tient lieu de DÉPLACEMENT du tour ; l'action reste disponible (attaque, sort, objet).
 *
 * Pendant tout ce temps le héros lance UN dé de combat de moins en attaque ET en défense, jamais moins d'un
 * (`malusDesCombat()`, lu par `ResolveurTour::frapper()` et `MoteurSorts::desDefenseHerosDetail()` — les points de
 * passage du combat, pas une copie).
 *
 * ── Ce que le livret ne dit pas, tranché ici (à relire avant de « corriger »)
 *
 *  - LA POSE : deux cases de long (mesure sur la planche « Design Your Own », F7539 p. 39 : 2,04 : 1), liées par
 *    `groupe_id`. Chaque case est une entrée de `cartes.grille.pieges` : un héros qui marche sur l'une OU l'autre tombe.
 *  - LA RÉCEPTION du saut long ne peut pas être une case piégée armée : on n'y tomberait pas sans l'avoir subi.
 *  - LA CHUTE PAR LE PETIT CÔTÉ ou en marchant dedans : même conséquence que la chute du saut long (2 PV, le héros
 *    reste dans le trou). Le livret ne décrit que le grand côté ; on ne sépare pas deux chutes dans le même trou.
 *  - LA CASE DE SORTIE : au choix du héros parmi les cases libres qui touchent SA case de fosse. Aucune ne peut être
 *    une case piégée armée. Si aucune n'est libre, le menu ne propose que « Terminer le tour ».
 *  - LE COÛT DE LA SORTIE : tout le déplacement du tour (le livret ne chiffre rien, la sortie se fait à pied).
 *  - LE MALUS : `max(min(dés, 1), dés − malus)` — un héros qui n'a qu'un dé le garde. Le plafond de Frayeur reste le dernier mot.
 *  - NON TRAITÉ, nommé : la détection de l'Œil du mineur n'est pas appelée à la sortie (un pas hors de la fosse ne passe
 *    pas par `controlerChemin()`) ; un héros dans la fosse que la carte déplace de force (Hurricane) perd son état à la
 *    première lecture (`estEnFosse()` compare la position), mais la colonne garde la trace jusqu'à la prochaine chute.
 *
 * @see \App\Engine\MotsClesPiege  saut_long · dans_la_fosse
 */
final class MoteurFosseLongue
{
    /** Les types d'action que ce moteur rend — tous déclarés dans `JournalCombat::TYPES`. Le saut passe par `franchissement`. */
    public const TYPES = ['sortie_fosse'];

    /**
     * Les quatre sens orthogonaux, `[dx, dy]`. « the square across » est en face, jamais en diagonale.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const SENS = ['n' => [0, -1], 's' => [0, 1], 'e' => [1, 0], 'o' => [-1, 0]];

    /** @var array<string, string> */
    private const DIRECTIONS = ['n' => 'au nord', 's' => 'au sud', 'e' => "à l'est", 'o' => "à l'ouest"];

    public function __construct(private readonly LanceurDes $des) {}

    // =====================================================================
    // Lecture de la carte
    // =====================================================================

    /** Index de l'entrée de piège posée sur (x, y) dont l'état est dans `$etats`, ou `null`. */
    public static function indexAt(Carte $carte, int $x, int $y, array $etats): ?int
    {
        foreach ((array) ($carte->grille['pieges'] ?? []) as $index => $entree) {
            if ((int) $entree['x'] === $x && (int) $entree['y'] === $y && in_array($entree['etat'] ?? null, $etats, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Les cases d'une même FOSSE (même `groupe_id` que l'entrée `$index`) : `[{index, x, y}]`. `[]` pour un piège isolé.
     *
     * @return list<array{index: int, x: int, y: int}>
     */
    public static function groupeDe(Carte $carte, int $index): array
    {
        $entrees = (array) ($carte->grille['pieges'] ?? []);
        $groupe = $entrees[$index]['groupe_id'] ?? null;

        if ($groupe === null) {
            return [];
        }

        $membres = [];

        foreach ($entrees as $i => $entree) {
            if (($entree['groupe_id'] ?? null) === $groupe) {
                $membres[] = ['index' => (int) $i, 'x' => (int) $entree['x'], 'y' => (int) $entree['y']];
            }
        }

        return $membres;
    }

    /**
     * La case de fosse en (px, py) appartient-elle à une fosse dont la RÉCEPTION d'un saut court, depuis (hx, hy),
     * tomberait ? Oui quand la case d'atterrissage (2P − H) est une autre case de la MÊME fosse : le saut ordinaire
     * n'a de sens que par-dessus UNE case, jamais par-dessus une fosse qu'on longe. Lu par le menu ET le résolveur.
     */
    public static function sautCourtAtterritSurLaFosse(Carte $carte, int $px, int $py, int $hx, int $hy): bool
    {
        $entrees = (array) ($carte->grille['pieges'] ?? []);
        $groupe = null;

        foreach ($entrees as $entree) {
            if ((int) $entree['x'] === $px && (int) $entree['y'] === $py) {
                $groupe = $entree['groupe_id'] ?? null;
                break;
            }
        }

        if ($groupe === null) {
            return false;
        }

        $lx = 2 * $px - $hx;
        $ly = 2 * $py - $hy;

        foreach ($entrees as $entree) {
            if ((int) $entree['x'] === $lx && (int) $entree['y'] === $ly && ($entree['groupe_id'] ?? null) === $groupe) {
                return true;
            }
        }

        return false;
    }

    // =====================================================================
    // 1. Le saut par le bout
    // =====================================================================

    /**
     * Les sauts par le bout LÉGAUX depuis la case du héros : une entrée par bout de fosse abordable. UNE SEULE liste :
     * le menu en tire ses options, le résolveur la relit (`sautLongLegal()`) — « le menu ne propose jamais ce que le
     * résolveur refusera » tient parce que les deux appellent ceci.
     *
     * @return list<array{sens: string, depart: array{x: int, y: int}, piege: array{x: int, y: int}, vers: array{x: int, y: int}, coule: array{x: int, y: int}, index: int, cout: int, nom: string, degats: int, face: string, piege_id: int}>
     */
    public static function sautsLongs(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat): array
    {
        $carte = $quete->carte;

        if ($carte === null || $etat->position_x === null || $etat->tombe || self::estEnFosse($etat)) {
            return [];
        }

        $hx = (int) $etat->position_x;
        $hy = (int) $etat->position_y;
        $grille = FabriqueGrille::pour($quete, exceptPersonnageId: (int) $personnage->id);
        $sauts = [];

        foreach (self::SENS as $sens => [$dx, $dy]) {
            $ax = $hx + $dx;
            $ay = $hy + $dy;

            // Le bout abordé doit être une case de fosse CONNUE (détectée, ou déjà ouverte) : on ne saute pas ce qu'on ignore.
            $index = self::indexAt($carte, $ax, $ay, MoteurPieges::ETATS_CONNUS_ARMES);

            if ($index === null) {
                continue;
            }

            $piege = Piege::find($carte->grille['pieges'][$index]['piege_id']);

            if (data_get($piege?->effet, 'saut_long') === null) {
                continue;
            }

            $membres = self::groupeDe($carte, $index);

            if (count($membres) !== 2) {
                continue; // une fosse longue, ce sont DEUX cases : une seule ne porte pas le saut
            }

            $partenaire = $membres[0]['index'] === $index ? $membres[1] : $membres[0];

            // La fosse doit se PROLONGER dans le sens de l'élan : l'autre case est la suivante, pas le côté.
            if ($partenaire['x'] !== $ax + $dx || $partenaire['y'] !== $ay + $dy) {
                continue;
            }

            $vx = $partenaire['x'] + $dx;
            $vy = $partenaire['y'] + $dy;

            // La réception : du sol où l'on peut s'arrêter, aucune porte entre elle et la fosse, et pas une autre case armée.
            if (! $grille->estTraversable($vx, $vy)
                || $grille->arretInterdit($vx, $vy)
                || $grille->porteBloqueEntre($partenaire['x'], $partenaire['y'], $vx, $vy)
                || $grille->porteBloqueEntre($hx, $hy, $ax, $ay)
                || self::indexAt($carte, $vx, $vy, MoteurPieges::ETATS_ARMES) !== null) {
                continue;
            }

            // La case de CHUTE : la première de la ligne, sinon la seconde — jamais une case tenue par un autre héros.
            $chute = null;

            foreach ([['x' => $ax, 'y' => $ay, 'index' => $index], ['x' => $partenaire['x'], 'y' => $partenaire['y'], 'index' => $partenaire['index']]] as $candidat) {
                if (! self::caseOccupeeParHeros($quete, $candidat['x'], $candidat['y'], (int) $personnage->id)) {
                    $chute = $candidat;

                    break;
                }
            }

            if ($chute === null) {
                continue;
            }

            $sauts[] = [
                'sens' => $sens,
                'depart' => ['x' => $hx, 'y' => $hy],
                'piege' => ['x' => $ax, 'y' => $ay],
                'vers' => ['x' => $vx, 'y' => $vy],
                'coule' => ['x' => $chute['x'], 'y' => $chute['y']],
                'index' => $chute['index'],
                // Deux cases de fosse, plus la réception : « using up three squares of movement ».
                'cout' => count($membres) + 1,
                'nom' => (string) $piege?->nom,
                'degats' => (int) data_get($piege?->effet, 'degats_pv_body', 0),
                // La face qui fait réussir le saut, lue au catalogue (`saut_long.face`) par le résolveur.
                'face' => (string) data_get($piege?->effet, 'saut_long.face', ''),
                'piege_id' => (int) $piege?->id,
            ];
        }

        return $sauts;
    }

    /**
     * Les options `franchissement` (saut par le bout), une par bout abordable que le déplacement restant permet de payer.
     * « At least three squares of movement left after they move next to the pit » : le seuil est le coût, lu sur la liste.
     *
     * @return list<array<string, mixed>>
     */
    public static function optionsSautLong(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat, int $restant): array
    {
        $options = [];

        foreach (self::sautsLongs($quete, $personnage, $etat) as $saut) {
            if ($restant < $saut['cout']) {
                continue;
            }

            $options[] = [
                'id' => "franchir_long_{$saut['piege']['x']}_{$saut['piege']['y']}",
                // Le RISQUE est dit dans le libellé : une chute coûte des PV et le héros reste dans le trou.
                'libelle' => "Sauter par le bout de la {$saut['nom']} — un dé de combat, un bouclier noir pour réussir ; "
                    ."sinon vous tombez ({$saut['degats']} PV de Body) et restez dans la fosse",
                'type' => 'franchissement',
                'parametres' => [
                    'piege' => $saut['piege'],
                    'vers' => $saut['vers'],
                    'cout' => $saut['cout'],
                    'saut' => 'long',
                ],
            ];
        }

        return $options;
    }

    /**
     * Le saut par le bout que l'option désigne, revalidé contre la liste LIVE, ou `null` s'il n'est plus légal.
     *
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>|null
     */
    public static function sautLongLegal(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat, array $option): ?array
    {
        $piege = (array) data_get($option, 'parametres.piege', []);
        $vers = (array) data_get($option, 'parametres.vers', []);

        foreach (self::sautsLongs($quete, $personnage, $etat) as $saut) {
            if ($saut['piege']['x'] === (int) ($piege['x'] ?? -1) && $saut['piege']['y'] === (int) ($piege['y'] ?? -1)
                && $saut['vers']['x'] === (int) ($vers['x'] ?? -1) && $saut['vers']['y'] === (int) ($vers['y'] ?? -1)) {
                return $saut;
            }
        }

        return null;
    }

    // =====================================================================
    // 2. La sortie
    // =====================================================================

    /**
     * Les cases où le héros dans la fosse peut se hisser : libres, praticables, sans porte entre elles et lui, et jamais
     * une case piégée armée. Lu par le menu (`optionsSortie()`) et revalidé par `resoudreSortie()`.
     *
     * @return list<array{x: int, y: int, sens: string}>
     */
    public static function destinationsSortie(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat): array
    {
        if ($quete->carte === null || ! self::estEnFosse($etat)) {
            return [];
        }

        $grille = FabriqueGrille::pour($quete, exceptPersonnageId: (int) $personnage->id);
        $x = (int) $etat->position_x;
        $y = (int) $etat->position_y;
        $cases = [];

        foreach (self::SENS as $sens => [$dx, $dy]) {
            $vx = $x + $dx;
            $vy = $y + $dy;

            if (! $grille->estTraversable($vx, $vy)
                || $grille->arretInterdit($vx, $vy)
                || $grille->porteBloqueEntre($x, $y, $vx, $vy)
                || self::indexAt($quete->carte, $vx, $vy, MoteurPieges::ETATS_ARMES) !== null) {
                continue;
            }

            $cases[] = ['x' => $vx, 'y' => $vy, 'sens' => $sens];
        }

        return $cases;
    }

    /**
     * Les options `sortie_fosse`, une par case de sortie. Vide hors de la fosse, et une fois le déplacement du tour pris.
     *
     * @return list<array<string, mixed>>
     */
    public static function optionsSortie(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat): array
    {
        if ($etat->a_deplace || ! self::estEnFosse($etat)) {
            return [];
        }

        $piege = Piege::find((int) ($etat->dans_la_fosse['piege_id'] ?? 0));
        $sur = array_map('intval', (array) data_get($piege?->effet, 'dans_la_fosse.sortie_sur', []));
        $options = [];

        foreach (self::destinationsSortie($quete, $personnage, $etat) as $destination) {
            $options[] = [
                'id' => "sortir_fosse_{$destination['x']}_{$destination['y']}",
                'libelle' => 'Se hisser hors de la fosse '.self::DIRECTIONS[$destination['sens']]
                    .' — un dé rouge, '.implode(' ou ', $sur).' pour réussir',
                'type' => 'sortie_fosse',
                'parametres' => [
                    'vers' => ['x' => $destination['x'], 'y' => $destination['y']],
                    // La DÉCISION publiée : à quel résultat le dé rouge fait sortir le héros.
                    'sortie_sur' => $sur,
                ],
            ];
        }

        return $options;
    }

    /**
     * Le jet de sortie : UN dé rouge (d6), 5 ou 6 pour se hisser sur la case choisie. Un échec le laisse dans le trou ;
     * dans les deux cas, le déplacement du tour est pris. Le seuil se lit au CATALOGUE, jamais dans l'option.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    public function resoudreSortie(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $acteur,
    ): array {
        if (! self::estEnFosse($etat)) {
            throw ValidationException::withMessages([
                'option_id' => "{$personnage->nom} n'est pas dans la fosse.",
            ]);
        }

        if ($etat->a_deplace) {
            throw ValidationException::withMessages([
                'option_id' => 'Vous avez déjà bougé ce tour : on ne se hisse plus.',
            ]);
        }

        $vers = (array) data_get($option, 'parametres.vers', []);
        $destination = collect(self::destinationsSortie($quete, $personnage, $etat))
            ->first(fn (array $d) => $d['x'] === (int) ($vers['x'] ?? -1) && $d['y'] === (int) ($vers['y'] ?? -1));

        if ($destination === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Impossible de vous hisser par là : la case est occupée, fermée ou piégée.',
            ]);
        }

        $piege = Piege::find((int) ($etat->dans_la_fosse['piege_id'] ?? 0));
        $sur = array_map('intval', (array) data_get($piege?->effet, 'dans_la_fosse.sortie_sur', []));
        $de = $this->des->d6();
        $reussi = in_array($de, $sur, true);

        $payload = [
            'type' => 'sortie_fosse',
            'option_id' => $option['id'] ?? null,
            'libelle' => $option['libelle'] ?? null,
            'piege' => (string) ($piege?->nom ?? 'Fosse longue'),
            'depart' => ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y],
            'vers' => ['x' => $destination['x'], 'y' => $destination['y']],
            'de' => $de,
            'sortie_sur' => $sur,
            'succes' => $reussi,
        ];

        // La sortie EST le déplacement du tour : réussie ou non, il est pris.
        $etat->update(['deplacement_restant' => 0, 'a_deplace' => true]);

        if ($reussi) {
            $etat->update([
                'position_x' => $destination['x'],
                'position_y' => $destination['y'],
                'dans_la_fosse' => null,
            ]);
        }

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    // =====================================================================
    // Le malus, et l'état
    // =====================================================================

    /** Le héros est-il DANS la fosse ? La colonne ET la position doivent dire la même case. */
    public static function estEnFosse(EtatPersonnageQuete $etat): bool
    {
        $fosse = (array) ($etat->dans_la_fosse ?? []);

        return $fosse !== [] && $etat->position_x !== null
            && (int) ($fosse['x'] ?? -1) === (int) $etat->position_x
            && (int) ($fosse['y'] ?? -1) === (int) $etat->position_y;
    }

    /** Dés de combat retirés au héros dans la fosse (attaque comme défense) : 0 hors de la fosse. */
    public static function malusDesCombat(EtatPersonnageQuete $etat): int
    {
        if (! self::estEnFosse($etat)) {
            return 0;
        }

        $piege = Piege::find((int) ($etat->dans_la_fosse['piege_id'] ?? 0));

        return (int) data_get($piege?->effet, 'dans_la_fosse.malus_des_combat', 0);
    }

    /**
     * Le malus du héros, lu sur SON état de quête COURANTE — pour `desDefenseHerosDetail()`, qui ne reçoit que le héros.
     * 0 hors quête (au hub, aucun état à lire).
     */
    public static function malusDesCombatDuHeros(Personnage $personnage): int
    {
        $etat = $personnage->groupeActif?->queteCourante?->etatsPersonnages()
            ->where('personnage_id', $personnage->id)
            ->first();

        return $etat === null ? 0 : self::malusDesCombat($etat);
    }

    /** Le SEUL endroit où le malus est appliqué : jamais sous un dé, et jamais une hausse. */
    public static function appliquerMalus(int $des, int $malus): int
    {
        return $malus > 0 ? max(min($des, 1), $des - $malus) : $des;
    }

    /**
     * L'état tel que l'écran le lit (`entites[].dans_la_fosse`) : la DÉCISION — le malus, le seuil de sortie — et le
     * nom du trou. `null` hors de la fosse.
     *
     * @return array{nom: string, malus_des_combat: int, sortie_sur: list<int>}|null
     */
    public static function publier(?EtatPersonnageQuete $etat): ?array
    {
        if ($etat === null || ! self::estEnFosse($etat)) {
            return null;
        }

        $piege = Piege::find((int) ($etat->dans_la_fosse['piege_id'] ?? 0));

        return [
            'nom' => (string) ($piege?->nom ?? 'Fosse longue'),
            'malus_des_combat' => self::malusDesCombat($etat),
            'sortie_sur' => array_map('intval', (array) data_get($piege?->effet, 'dans_la_fosse.sortie_sur', [])),
        ];
    }

    /** Une AUTRE force occupe-t-elle la case ? (héros autre que `$sauf`, debout ou tombé) */
    private static function caseOccupeeParHeros(Quete $quete, int $x, int $y, int $sauf): bool
    {
        return $quete->etatsPersonnages()
            ->where('position_x', $x)
            ->where('position_y', $y)
            ->where('personnage_id', '!=', $sauf)
            ->exists();
    }
}
