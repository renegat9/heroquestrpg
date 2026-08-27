<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Deplacement;
use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;

/**
 * Menu générique construit PAR LE MOTEUR depuis l'état exact — repli garanti
 * de la boucle de jeu (contrat : « l'API ne dépend jamais du LLM »).
 *
 * En quête : Se déplacer / Attaquer (UN bouton, cibles légales jointes) /
 * Désamorcer / Franchir (un bouton par piège DÉTECTÉ adjacent, doc 10 §4) /
 * Lancer {sort} (un bouton par sort DISPONIBLE, cibles légales jointes) /
 * Utiliser un parchemin (un bouton par parchemin au sac) / Se concentrer
 * (magicien, nœud Concentration — doc 02, MoteurSorts) / Fouiller (jet de
 * Mind 1) / Attendre. Au hub : options d'attente neutres.
 * Toutes les options sont exécutables telles quelles par ResolveurTour.
 */
final class MenuMoteur
{
    /**
     * Jet de déplacement à partir duquel l'Évanescence se rompt (décision de
     * René, 2026-08-12) : le plateau lit 9+ sur 2 dés rouges — un peu plus
     * d'une chance sur quatre — et nous 5+ sur notre unique d6, soit une sur
     * trois. C'est l'approximation la plus proche que permet un seul dé.
     */
    private const RUPTURE_EVANESCENCE = 5;

    public function __construct(
        private readonly MoteurPieges $pieges,
        private readonly MoteurPortes $portes,
        private readonly MoteurMobilier $mobilier,
        private readonly MoteurEpreuves $epreuves,
        private readonly MoteurSorts $sorts,
        private readonly LanceurDes $des,
        private readonly Equipement $equipement,
        private readonly MoteurCharges $charges,
        private readonly CapacitesInnees $capacites,
        private readonly Talents $talents,
        private readonly StylesElementaires $styles,
    ) {}

    /**
     * Monstres qu'un héros peut tenter de REPOUSSER d'une case (2026-08-24).
     *
     * Conditions cumulées, et chacune évite une option morte :
     *  - le monstre est RÉVÉLÉ et actif — on ne bouscule pas ce qu'on ne voit pas ;
     *  - il est au CONTACT (orthogonal) du héros ;
     *  - ce héros ne l'a pas déjà tenté (`habillage.repousse_par`, une tentative
     *    par héros comme la fouille) ;
     *  - la case de recul est libre POUR TOUTE L'EMPRISE de la figure — une
     *    gargouille de 2×2 a besoin de quatre cases, et proposer une poussée
     *    que le résolveur refusera est exactement ce que le projet interdit.
     *
     * @return list<array{instance_id: int, nom: string, difficulte: int}>
     */
    private function ciblesRepoussables(Quete $quete, Personnage $personnage, int $px, int $py): array
    {
        $cibles = [];

        foreach ($quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)->with('monstre')->get() as $instance) {
            if ($instance->position_x === null) {
                continue;
            }

            $ix = (int) $instance->position_x;
            $iy = (int) $instance->position_y;

            if (abs($ix - $px) + abs($iy - $py) !== 1) {
                continue;
            }

            $deja = array_map('intval', (array) data_get($instance->habillage, 'repousse_par', []));

            if (in_array((int) $personnage->id, $deja, true)) {
                continue;
            }

            // Recul : le prolongement de l'axe héros → monstre.
            $vx = $ix - $px;
            $vy = $iy - $py;

            $emprise = $instance->monstre->emprise();
            $grille = FabriqueGrille::pour($quete, exceptInstanceId: (int) $instance->id);

            $libre = true;
            foreach ($grille->cellulesEmprise($ix + $vx, $iy + $vy, (int) $emprise['l'], (int) $emprise['h']) as $cellule) {
                if (! $grille->estTraversable((int) $cellule['x'], (int) $cellule['y'])) {
                    $libre = false;
                    break;
                }
            }

            if (! $libre) {
                continue;
            }

            $cibles[] = [
                'instance_id' => (int) $instance->id,
                'nom' => $instance->nomAffiche(),
                'difficulte' => DifficulteBody::plafonnee($quete, (int) $instance->monstre->pv_body),
            ];
        }

        return $cibles;
    }

    /**
     * Déplacement du tour : lance le d6 (base + 1d6, doc 03 §3) la PREMIÈRE fois
     * du tour et mémorise le total sur l'état (réutilisé pour les régénérations
     * de menu et la résolution). Rien n'est relancé si déjà fixé.
     *
     * @return array{base: int, de: int|null, total: int}
     */
    /**
     * Un héros en (hx,hy) est-il au contact de l'instance, en tenant compte de
     * l'emprise des grandes figurines (3.9) ? Le contact vaut dès que le héros
     * jouxte n'importe quelle case de l'emprise.
     *
     * `$diagonale` élargit le voisinage de 4 à 8 cases pour les ARMES LONGUES
     * (Bâton, Épée longue). L'asymétrie est voulue et canonique : le héros à
     * l'arme longue frappe en diagonale, mais le monstre ne riposte JAMAIS en
     * diagonale — le livret qualifie cette case de « safe » (livret de règles
     * p. 14, cf. reference/16_armurerie.md §6.2). D'où le défaut à `false` :
     * tous les appels côté monstre restent orthogonaux.
     *
     * ⚠ `ResolveurTour::heroAuContact()` porte la MÊME règle et doit rester en
     * phase — le menu propose, le résolveur revalide.
     */
    private static function monstreAuContact(InstanceMonstre $instance, int $hx, int $hy, bool $diagonale = false): bool
    {
        $e = $instance->monstre->emprise();

        for ($dy = 0; $dy < $e['h']; $dy++) {
            for ($dx = 0; $dx < $e['l']; $dx++) {
                $ex = abs(((int) $instance->position_x + $dx) - $hx);
                $ey = abs(((int) $instance->position_y + $dy) - $hy);

                // Tchebychev (8 voisins) pour une arme longue, Manhattan (4) sinon.
                if (($diagonale ? max($ex, $ey) : $ex + $ey) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Cibles légales d'UNE arme donnée (`null` = à mains nues), séparées en
     * « attaquer » et « lancer ».
     *
     * Extrait du bloc d'action le 2026-08-12, parce que le dual-wielding en
     * demande une passe par arme : la portée, les diagonales et le jet sont des
     * propriétés de l'ARME, pas du héros.
     *
     * ⚠ `parametres.cibles` est la LISTE BLANCHE : c'était l'identifiant
     * d'option qui portait la légalité de la cible, et le contrôleur la validait
     * en validant l'option. `ResolveurTour` vérifie donc l'appartenance, sinon
     * on pourrait viser n'importe quel monstre de la quête, hors portée et hors
     * ligne de vue.
     *
     * @return array{attaquer: list<array<string, mixed>>, lancer: list<array<string, mixed>>}
     */
    private function ciblesPourArme(Quete $quete, EtatPersonnageQuete $etat, Personnage $personnage, ?Objet $arme): array
    {
        // Arme longue (Bâton, Épée longue) : frappe aussi en DIAGONALE, sans
        // pénalité — « the attack is made and defended normally »
        // (reference/16_armurerie.md §6.2, livret de règles p. 14).
        $diagonale = (bool) ($arme?->effet['attaque_diagonale'] ?? false);
        $aDistanceArme = ($arme?->effet['portee'] ?? null) === 'distance';
        // Arme JETABLE (dague, hachette) : elle vise aussi à distance, mais
        // quitte la main du héros — d'où une option distincte.
        $jetable = (bool) ($arme?->effet['jetable'] ?? false);

        $actives = $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true) // dormant (salle non découverte) = non ciblable (aligné sur ResolveurTour)
            ->with('monstre')
            ->orderBy('id')
            ->get();

        $adjacents = $actives->filter(fn (InstanceMonstre $i) => $i->position_x !== null
            && self::monstreAuContact($i, (int) $etat->position_x, (int) $etat->position_y, $diagonale));
        $idsAdjacents = $adjacents->pluck('id')->all();

        // Tir à distance (Arbalète, Tir précis) : monstres HORS contact mais en
        // ligne de vue dégagée, si l'arme porte.
        $aDistance = collect();

        if ($aDistanceArme || $jetable) {
            $grille = FabriqueGrille::pour($quete, exceptPersonnageId: $personnage->id);
            $aDistance = $actives->filter(fn (InstanceMonstre $i) => $i->position_x !== null
                && ! in_array($i->id, $idsAdjacents, true)
                && $grille->ligneDeVue(
                    (int) $etat->position_x, (int) $etat->position_y,
                    (int) $i->position_x, (int) $i->position_y,
                    figuresBloquent: true,
                ));
        }

        $idsADistance = $aDistance->pluck('id')->all();
        $cibles = ['attaquer' => [], 'lancer' => []];

        foreach ($adjacents->concat($aDistance) as $instance) {
            $aPortee = in_array($instance->id, $idsADistance, true);
            $lance = $jetable && ! $aDistanceArme && $aPortee;

            $cibles[$lance ? 'lancer' : 'attaquer'][] = [
                'id' => $instance->id,
                'type' => 'monstre',
                'nom' => $instance->nomAffiche(),
                // Rappel du TYPE du catalogue quand le nom est un habillage
                // IA → le joueur retrouve la fiche du bestiaire (guide).
                'nom_base' => $instance->monstre->nom_base,
                'distance' => $aPortee,
            ];
        }

        return $cibles;
    }

    /**
     * Directions de rayon qui touchent AU MOINS un ennemi, avec leur compte —
     * *Esprit Ardent*. Un rayon lancé dans le vide serait un Style du Feu
     * dépensé pour rien, et le Feu ne s'ouvre qu'une fois par combat.
     *
     * ⚠ Le résolveur recalcule la ligne : ceci n'est qu'un cadran de visée.
     *
     * @return array<string, int>
     */
    private function directionsDeRayon(Quete $quete, EtatPersonnageQuete $etat): array
    {
        $grille = FabriqueGrille::pour($quete);
        $monstres = $quete->instancesMonstres()
            ->where('etat', 'actif')->where('revele', true)->with('monstre')->get();
        $trouvees = [];

        foreach (ResolveurTour::DIRECTIONS_RAYON as $code => [$dx, $dy]) {
            $x = (int) $etat->position_x;
            $y = (int) $etat->position_y;
            $ennemis = 0;

            while (true) {
                $sx = $x + $dx;
                $sy = $y + $dy;

                if ($grille->estRoche($sx, $sy) || $grille->porteBloqueEntre($x, $y, $sx, $sy)) {
                    break;
                }

                $ennemis += $monstres->filter(function (InstanceMonstre $i) use ($sx, $sy) {
                    $e = $i->monstre->emprise();

                    return $i->position_x !== null
                        && $sx >= (int) $i->position_x && $sx < (int) $i->position_x + $e['l']
                        && $sy >= (int) $i->position_y && $sy < (int) $i->position_y + $e['h'];
                })->count();

                $x = $sx;
                $y = $sy;
            }

            if ($ennemis > 0) {
                $trouvees[$code] = $ennemis;
            }
        }

        return $trouvees;
    }

    /**
     * « Fouiller — trésor » offerte ? Le héros doit être dans une SALLE (pas un
     * couloir) « vide » — aucun monstre actif révélé à l'intérieur — qui n'a pas
     * déjà été fouillée pour son trésor (une fouille par salle, doc 14 §3.2).
     */
    /**
     * Options des trois objets de MATÉRIEL des cartes officielles.
     *
     * Chacune reprend le créneau que sa carte énonce : l'eau bénite s'emploie
     * « instead of attacking » (donc `objet`, créneau action), les deux autres
     * « no action required » / « anytime during your movement » (donc
     * `objet_libre`, interaction gratuite).
     *
     * @return list<array<string, mixed>>
     */
    private function objetsDeMateriel(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat, int $px, int $py): array
    {
        $options = [];
        $grille = null;

        foreach ($personnage->inventaire()->with('objet')->get() as $ligne) {
            $effet = (array) ($ligne->objet?->effet ?? []);

            if (! empty($effet['pose_chausse_trappes'])) {
                $options[] = [
                    'id' => "poser_chausse_trappes_{$ligne->id}",
                    'libelle' => 'Semer des chausse-trappes',
                    'type' => 'objet_libre',
                    'parametres' => ['inventaire_id' => $ligne->id],
                ];
            }

            if (! empty($effet['enfume_monstre_adjacent'])) {
                $cibles = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)
                    ->with('monstre')->get()
                    ->filter(fn (InstanceMonstre $i) => self::monstreAuContact($i, $px, $py))
                    ->map(fn (InstanceMonstre $i) => ['id' => $i->id, 'nom' => $i->nomAffiche()])
                    ->values()->all();

                if ($cibles !== []) {
                    $options[] = [
                        'id' => "fumigene_{$ligne->id}",
                        'libelle' => 'Lancer la bombe fumigène',
                        'type' => 'objet_libre',
                        'parametres' => ['inventaire_id' => $ligne->id, 'cibles' => $cibles],
                    ];
                }
            }

            if (! empty($effet['tue_creatures'])) {
                $noms = array_map('mb_strtolower', array_map('strval', (array) $effet['tue_creatures']));
                $grille ??= FabriqueGrille::pour($quete);

                $cibles = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)
                    ->with('monstre')->get()
                    ->filter(fn (InstanceMonstre $i) => $i->position_x !== null
                        && in_array(mb_strtolower((string) $i->monstre?->nom_base), $noms, true)
                        && $grille->ligneDeVue($px, $py, (int) $i->position_x, (int) $i->position_y))
                    ->map(fn (InstanceMonstre $i) => ['id' => $i->id, 'nom' => $i->nomAffiche()])
                    ->values()->all();

                if ($cibles !== []) {
                    $options[] = [
                        'id' => "eau_benite_{$ligne->id}",
                        'libelle' => "Asperger d'eau bénite",
                        'type' => 'objet',
                        'parametres' => ['inventaire_id' => $ligne->id, 'cibles' => $cibles],
                    ];
                }
            }
        }

        return $options;
    }

    private function salleFouillableTresor(Quete $quete, ?EtatPersonnageQuete $etat): bool
    {
        if ($etat === null || $etat->position_x === null || $quete->carte === null) {
            return false;
        }

        $salles = (array) data_get($quete->carte->grille, 'salles', []);
        $salle = null;
        $index = null;

        foreach ($salles as $i => $s) {
            if ((int) $etat->position_x >= (int) $s['x'] && (int) $etat->position_x < (int) $s['x'] + (int) $s['largeur']
                && (int) $etat->position_y >= (int) $s['y'] && (int) $etat->position_y < (int) $s['y'] + (int) $s['hauteur']) {
                $salle = $s;
                $index = (int) $i;
                break;
            }
        }

        if ($salle === null) {
            return false; // couloir : pas de fouille de trésor
        }

        // Comme au plateau : CHAQUE héros fouille une fois par salle, et tire
        // sa propre carte. La fouille était close pour tout le groupe dès le
        // premier — les autres n'avaient jamais leur chance.
        if ($etat->personnage_id !== null && $quete->aFouille($index, (int) $etat->personnage_id)) {
            return false;
        }

        // Salle « vide » : aucun monstre actif révélé dans ses limites.
        $occupee = $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true)
            ->whereBetween('position_x', [(int) $salle['x'], (int) $salle['x'] + (int) $salle['largeur'] - 1])
            ->whereBetween('position_y', [(int) $salle['y'], (int) $salle['y'] + (int) $salle['hauteur'] - 1])
            ->exists();

        return ! $occupee;
    }

    /**
     * Points de déplacement encore disponibles ce tour (E1) — LECTURE SEULE :
     * le restant mémorisé si le mouvement est entamé, sinon le total du tour
     * (Vent Véloce inclus, mais JAMAIS consommé ici : c'est le résolveur qui
     * consomme le buff au premier pas).
     */
    private function pointsRestants(Personnage $personnage, ?EtatPersonnageQuete $etat): int
    {
        if ($etat?->deplacement_restant !== null) {
            return (int) $etat->deplacement_restant;
        }

        return $this->deplacementDuTour($personnage, $etat)['total']
            * $this->sorts->multiplicateurDeplacement($personnage);
    }

    private function deplacementDuTour(Personnage $personnage, ?EtatPersonnageQuete $etat): array
    {
        $base = (int) $personnage->deplacement_base;

        if ($etat === null) {
            return ['base' => $base, 'de' => null, 'total' => $base];
        }

        if ($etat->deplacement_tour === null && ! $etat->tombe && ! $etat->a_joue) {
            // Armure lourde : « a 2 square movement penalty » (carte Plate Mail).
            // `Deplacement` savait appliquer un malus depuis toujours, mais
            // aucun appelant ne le lui avait jamais dit — il n'avait donc
            // jamais joué, et l'armure la plus chère n'avait que des avantages.
            $jet = (new Deplacement($this->des))
                ->calculer($base, $this->equipement->malusDeplacement($personnage));

            $etat->update(['deplacement_tour' => $jet->total]);

            // ÉVANESCENCE : « The hero moves unseen if they roll an 8 or lower
            // on their red movement dice. If a 9, 10, 11, or 12 is rolled, the
            // spell ends. » Le plateau lance 2 dés rouges ; nous lançons UN d6
            // et rompons à 5+ (décision de René, 2026-08-12) — une chance sur
            // trois, contre un peu plus d'une sur quatre au plateau.
            //
            // ⚠ C'est bien le JET DU TOUR qui décide, pas le déplacement
            // effectif : le sort tient ou tombe avant que le héros n'ait fait
            // un pas.
            if ($jet->de >= self::RUPTURE_EVANESCENCE) {
                $this->sorts->rompreEvanescence($personnage);
            }
        }

        $total = $etat->deplacement_tour ?? $base;

        return ['base' => $base, 'de' => $total > $base ? $total - $base : null, 'total' => $total];
    }

    /**
     * Le héros a-t-il AU MOINS une case orthogonale accessible (donc un
     * déplacement réel possible) ? On reconstruit le plateau du moteur — même
     * occupation que ResolveurTour::grille (autres héros, monstres actifs avec
     * emprise, alliés) et portes/murs de la carte — puis on teste les 4 voisins :
     * l'ensemble atteignable est vide SSI aucun voisin n'est traversable. Sans
     * carte/position, on suppose le déplacement possible (ne jamais masquer à tort).
     */
    private function peutSeDeplacer(Quete $quete, Personnage $personnage, ?EtatPersonnageQuete $etat): bool
    {
        if ($etat === null || $etat->position_x === null || $etat->tombe || $quete->carte === null) {
            return true;
        }

        $grille = Grille::depuisCarte($quete->carte);

        $occupees = [];
        foreach ($quete->etatsPersonnages()->get() as $autre) {
            // Un allié TOMBÉ ne bloque pas le passage — on l'enjambe (même
            // règle que FabriqueGrille, cf. HerosTombeTest). Il était compté
            // ici, si bien que le menu retirait « Se déplacer » à un héros
            // qu'en réalité le moteur aurait laissé avancer (§2.17).
            if ($autre->personnage_id !== $personnage->id && $autre->position_x !== null && ! $autre->tombe) {
                $occupees[] = ['x' => (int) $autre->position_x, 'y' => (int) $autre->position_y];
            }
        }
        foreach ($quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get() as $instance) {
            if ($instance->position_x !== null) {
                $e = $instance->monstre->emprise();
                $occupees = array_merge($occupees, $grille->cellulesEmprise(
                    (int) $instance->position_x, (int) $instance->position_y, $e['l'], $e['h'],
                ));
            }
        }
        foreach (GroupeMercenaire::where('groupe_id', $quete->groupe_id)->where('etat', 'actif')->get() as $allie) {
            if ($allie->position_x !== null) {
                $occupees[] = ['x' => (int) $allie->position_x, 'y' => (int) $allie->position_y];
            }
        }
        $grille->occuper($occupees);

        $x = (int) $etat->position_x;
        $y = (int) $etat->position_y;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            if ($grille->estTraversable($x + $dx, $y + $dy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{situation: string, options: list<array<string, mixed>>}
     */
    public function generer(Groupe $groupe, Personnage $personnage): array
    {
        $quete = $groupe->phase === 'quete' ? $groupe->queteCourante : null;

        if ($quete === null) {
            return [
                'situation' => 'Le groupe se prépare au hub.',
                'options' => [
                    ['id' => 'attendre', 'libelle' => 'Attendre et observer', 'type' => 'attente'],
                    ['id' => 'continuer', 'libelle' => 'Continuer prudemment', 'type' => 'action'],
                ],
            ];
        }

        $etat = $quete->etatsPersonnages()->where('personnage_id', $personnage->id)->first();

        // Tour = deux créneaux (doc 03 §28) : un DÉPLACEMENT + une ACTION. On
        // n'offre que les créneaux ENCORE LIBRES, plus « Terminer le tour ».
        // Une action TERMINANTE (relever, concentration, « Terminer le tour »)
        // pose a_joue sans consommer les deux créneaux : on la traite comme si
        // les DEUX étaient pris, sinon le menu proposait encore des actions
        // fantômes alors que le tour est fini.
        // Styles Élémentaires du Moine : la recharge se lit « au début de ton
        // tour », donc avant de bâtir le menu — sinon le joueur verrait ses
        // styles revenir seulement après avoir agi.
        if ($etat !== null) {
            $this->styles->recupererSiHorsDeVue($quete, $etat);

            // Même crochet de début de tour pour les buffs adossés à la VUE
            // (potions de rage guerrière et de peau de givre). Le menu et le
            // résolveur doivent y répondre pareil, sinon le menu annonce une
            // seconde attaque que la résolution refuse.
            $this->sorts->rythmerBuffsDeVue($quete, $etat);
        }

        $aJoue = (bool) ($etat?->a_joue ?? false);
        $aDeplace = $aJoue || (bool) ($etat?->a_deplace ?? false);
        $aAgi = $aJoue || (bool) ($etat?->a_agi ?? false);
        $options = [];

        // ── Créneau DÉPLACEMENT (base + 1d6 lancé une fois/tour et mémorisé) ──
        // On masque « Se déplacer » quand le héros est TOTALEMENT bloqué (aucune
        // case orthogonale traversable : murs / portes fermées / figures) — sinon
        // c'était une option morte (0 case) qui forçait « Terminer le tour ». Le
        // plateau est celui du moteur (occupation identique à ResolveurTour).
        // `deplacement_interdit` (Envenimé, Immobilisé) : la clé vivait dans le
        // catalogue des conditions sans AUCUN lecteur — un héros « immobilisé »
        // se déplaçait comme si de rien n'était. Câblée le 2026-08-10, en même
        // temps que le venin des créatures de Jungles of Delthrak.
        if (! $aDeplace && ! $this->sorts->deplacementInterdit($personnage)
            && $this->peutSeDeplacer($quete, $personnage, $etat)) {
            $portee = $this->deplacementDuTour($personnage, $etat);

            // Déplacement FRACTIONNÉ (E1) : si le héros a DÉJÀ entamé son
            // mouvement ce tour, la portée offerte est le RESTANT ; sinon le total
            // du tour (Vent Véloce inclus, appliqué au 1er pas côté résolveur).
            // ⚠ Miroir exact de `ResolveurTour::pointsDeplacement()`, bonus de
            // la Potion de dextérité compris : une portée annoncée plus courte
            // que la portée réelle rend le bonus invisible, une portée plus
            // longue offre une destination que le résolveur refusera.
            $porteeEffective = $etat?->deplacement_restant !== null
                ? (int) $etat->deplacement_restant
                : $portee['total'] * $this->sorts->multiplicateurDeplacement($personnage)
                    + $this->sorts->bonusDes($personnage, 'bonus_deplacement');

            $options[] = [
                'id' => 'se_deplacer',
                'libelle' => $etat?->deplacement_restant !== null ? 'Continuer à se déplacer' : 'Se déplacer',
                'type' => 'deplacement',
                'parametres' => [
                    'portee_base' => (int) $personnage->deplacement_base,
                    'base' => $portee['base'],
                    'de' => $portee['de'],          // résultat du d6 (null si Armure de plates)
                    'portee' => $porteeEffective,    // cases restantes ce tour
                ],
            ];
        }

        // Pièges DÉTECTÉS adjacents (doc 10 §4) — partagés entre créneaux :
        // Franchir une fosse = DÉPLACEMENT, Désamorcer = ACTION.
        $detectes = ($etat !== null && $etat->position_x !== null && $quete->carte !== null)
            ? $this->pieges->detectesAdjacents($quete->carte, (int) $etat->position_x, (int) $etat->position_y)
            : [];

        // Sauter par-dessus une fosse fait partie du MOUVEMENT (E3) : l'option
        // n'apparaît que s'il reste assez de points pour payer le saut.
        if (! $aDeplace && $this->pointsRestants($personnage, $etat) >= ResolveurTour::COUT_FRANCHISSEMENT) {
            foreach ($detectes as $adjacent) {
                if ($this->pieges->estFosse($adjacent['piege'])) {
                    $nomPiege = $adjacent['piege']?->nom ?? 'Piège';
                    $options[] = [
                        'id' => "franchir_{$adjacent['x']}_{$adjacent['y']}",
                        'libelle' => "Sauter par-dessus {$nomPiege} — jet de Body",
                        'type' => 'franchissement',
                        'jet' => ['attribut' => 'body', 'difficulte' => ResolveurTour::DIFFICULTE_FRANCHISSEMENT],
                        'parametres' => [
                            'piege' => ['x' => $adjacent['x'], 'y' => $adjacent['y']],
                            'cout' => ResolveurTour::COUT_FRANCHISSEMENT,
                        ],
                    ];

                    // DRAGON BONDISSANT (Moine, Style de l'Air) — « Automatically
                    // succeed when jumping over a trap. » Le même saut, sans le
                    // risque, au prix du Style de l'Air : deux boutons, parce
                    // que dépenser un style pour un saut qu'on aurait réussi
                    // doit rester un choix.
                    $dragon = $this->styles->sourceActivable($personnage, $etat, 'saut_piege_automatique');

                    if ($dragon !== null) {
                        $options[] = [
                            'id' => "franchir_dragon_{$adjacent['x']}_{$adjacent['y']}",
                            'libelle' => "{$dragon['nom']} — franchir {$nomPiege} à coup sûr",
                            'type' => 'franchissement',
                            'jet' => ['attribut' => 'body', 'difficulte' => ResolveurTour::DIFFICULTE_FRANCHISSEMENT],
                            'parametres' => [
                                'piege' => ['x' => $adjacent['x'], 'y' => $adjacent['y']],
                                'cout' => ResolveurTour::COUT_FRANCHISSEMENT,
                                'style' => 'saut_piege_automatique',
                            ],
                        ];
                    }
                }
            }
        }

        // Attaque BONUS : la Potion d'héroïsme et le Fléau des Orques posent
        // `attaque_supplementaire`, une seconde frappe au-delà du créneau
        // d'action. `ResolveurTour` l'acceptait déjà (`$bonusHeroisme`) mais le
        // MENU ne la proposait pas — et le contrôleur refuse toute option
        // absente du dernier menu : l'effet de la potion était donc
        // inatteignable par le jeu normal. Même traitement que la Réserve
        // arcanique du magicien, plus bas.
        $bonusAttaqueDisponible = $etat !== null && $aAgi && ! $aJoue
            && (bool) ($etat->attaque_supplementaire ?? false);

        // `action_interdite` (Paralysé, Évanescent) : le pendant de
        // `deplacement_interdit`. ⚠ Il ne gardait QUE le bloc des sorts jusqu'au
        // 2026-08-14 — un héros Paralysé attaquait, fouillait et désamorçait
        // normalement, et n'était privé que de sa magie. Constaté en partie
        // réelle : Alaric, paralysé par la Flamme hypnotique d'une coéquipière,
        // a tué un gobelin de son tour. La règle était pourtant écrite dans le
        // docblock d'`actionInterdite()` — « attaquer, fouiller, désamorcer,
        // lancer » — et dans la condition elle-même.
        //
        // Un Évanescent, lui, MARCHE encore et ouvre les portes : c'est tout
        // l'intérêt du sort, et c'est pourquoi la garde porte sur l'ACTION et
        // jamais sur le déplacement.
        $actionInterdite = $this->sorts->actionInterdite($personnage);

        // ── Créneau ACTION (attaque, relever, désamorçage, sorts, fouille) ──
        if ((! $aAgi || $bonusAttaqueDisponible) && ! $actionInterdite
            && $etat !== null && $etat->position_x !== null) {
            // DUAL-WIELDING (règle de René, 2026-08-12) : un héros peut tenir
            // DEUX armes à une main, et la seconde n'apporte aucun dé — elle
            // apporte un CHOIX. D'où une option d'attaque par arme, et non plus
            // une seule : « attaquer avec l'arme A », « attaquer avec l'arme B ».
            //
            // ⚠ Les cibles légales ne sont pas les mêmes d'une arme à l'autre :
            // l'arbalète voit toute la salle, l'épée touche ses quatre voisines,
            // l'épée longue ajoute les diagonales. Chaque option porte donc SA
            // liste blanche, recalculée pour SON arme.
            $armes = $this->equipement->armesEnMain($personnage);
            $mainsNues = $armes === [];
            $armePrincipale = $armes[0]->objet ?? null;

            // Mains nues : une seule passe, sans arme — le Moine frappe ainsi,
            // et n'importe qui peut toujours cogner.
            $lignesArmes = $mainsNues ? [null] : $armes;
            $ciblesParArme = [];

            foreach ($lignesArmes as $ligneArme) {
                $arme = $ligneArme?->objet;
                $slot = $ligneArme?->emplacement ?? 'arme_principale';
                $cibles = $this->ciblesPourArme($quete, $etat, $personnage, $arme);
                $ciblesParArme[$slot] = $cibles;

                // Suffixe seulement s'il y a un choix à faire : « Attaquer »
                // reste « Attaquer » quand une seule arme est en main.
                $suffixe = count($lignesArmes) > 1 && $arme !== null ? " — {$arme->nom}" : '';
                $second = $slot === 'arme_secondaire';

                if ($cibles['attaquer'] !== []) {
                    $options[] = [
                        'id' => $second ? 'attaquer_secondaire' : 'attaquer',
                        'libelle' => "Attaquer{$suffixe}",
                        'type' => 'attaque',
                        'lancer' => false,
                        'parametres' => ['arme' => $slot, 'cibles' => $cibles['attaquer']],
                    ];
                }

                if ($cibles['lancer'] !== []) {
                    $options[] = [
                        'id' => $second ? 'lancer_secondaire' : 'lancer',
                        // Lancer PERD l'arme : le libellé doit le dire, sinon le
                        // joueur se retrouve les mains vides sans l'avoir voulu.
                        'libelle' => "Lancer {$arme->nom} (perdue)",
                        'type' => 'attaque',
                        'lancer' => true,
                        'parametres' => ['arme' => $slot, 'lancer' => true, 'cibles' => $cibles['lancer']],
                    ];
                }
            }

            // Les capacités qui frappent (Furie, Force de la Montagne) partent
            // de la PREMIÈRE arme en main — la main droite s'il y en a une.
            $cibles = $ciblesParArme[array_key_first($ciblesParArme)] ?? ['attaquer' => [], 'lancer' => []];

            // FURIE (Berserker) — « As an action, you may lose up to 2 Body
            // Points to immediately make an attack. Add additional Attack dice
            // equal to the number of Body Points you lose. »
            //
            // « Up to 2 » est un vrai choix, donc une option par montant : le
            // joueur doit pouvoir payer 1 quand il ne peut plus payer 2. Elles
            // portent le type `attaque`, ce qui leur donne gratuitement la
            // feuille de ciblage, la liste blanche des cibles et le créneau
            // d'action — une Furie reste une attaque, simplement payée.
            //
            // ⚠ Rien au-dessus de `pv_body - 1` : voir `payerLaFurie()`, on ne
            // s'assomme pas soi-même avant de frapper.
            if ($cibles['attaquer'] !== []
                && $this->capacites->disponible($personnage, $etat, 'sacrifice_pv_pour_des')) {
                $noeud = $this->capacites->noeud($personnage, 'sacrifice_pv_pour_des');
                $plafond = min((int) ($noeud->effet['max'] ?? 2), (int) $personnage->pv_body - 1);

                for ($pv = 1; $pv <= $plafond; $pv++) {
                    $options[] = [
                        'id' => "furie_{$pv}",
                        'libelle' => "Furie — sacrifier {$pv} PV pour +{$pv} dé".($pv > 1 ? 's' : ''),
                        'type' => 'attaque',
                        'lancer' => false,
                        'parametres' => ['furie' => $pv, 'cibles' => $cibles['attaquer']],
                    ];
                }
            }

            // FRÉNÉSIE SANGUINAIRE (Berserker) — « a single sweeping attack
            // against all monsters adjacent AND diagonal to you ». Aucune cible
            // à choisir : elle prend tout ce qui touche le héros, d'où un type
            // à part et pas de `parametres.cibles`. Offerte seulement s'il y a
            // quelque chose à balayer — sinon c'est un bouton qui gaspille une
            // capacité « once per quest » sur du vide.
            $balayee = $this->styles->sourceActivable($personnage, $etat, 'attaque_balayee');

            // « Make one UNARMED attack » (Œil du Cyclone) : sans cette garde,
            // le Moine armé verrait un bouton que le résolveur refuse.
            if ($balayee !== null
                && (empty($balayee['effet']['mains_nues']) || $mainsNues)) {
                $diagonales = (bool) ($balayee['effet']['diagonales'] ?? true);

                // Recompté avec la portée de la CAPACITÉ, jamais avec celle de
                // l'arme : le balayage touche les diagonales même à la dague.
                $balayees = $quete->instancesMonstres()
                    ->where('etat', 'actif')
                    ->where('revele', true)
                    ->with('monstre')
                    ->get()
                    ->filter(fn (InstanceMonstre $i) => $i->position_x !== null
                        && self::monstreAuContact($i, (int) $etat->position_x, (int) $etat->position_y, $diagonales))
                    ->count();

                if ($balayees > 0) {
                    $options[] = [
                        'id' => 'frappe_balayee',
                        'libelle' => "{$balayee['nom']} — frapper ".($balayees > 1 ? "les {$balayees} ennemis au contact" : "l'ennemi au contact"),
                        'type' => 'attaque_balayee',
                    ];
                }
            }

            // STYLE DU FEU (Moine) — les deux techniques verrouillées jusqu'à
            // ce que l'Air, la Terre et l'Eau soient tombés. `sourceActivable`
            // porte le verrou : ici, il suffit de proposer.
            $rayon = $this->styles->sourceActivable($personnage, $etat, 'rayon');

            if ($rayon !== null) {
                foreach ($this->directionsDeRayon($quete, $etat) as $direction => $ennemis) {
                    [, , $libelleDirection] = ResolveurTour::DIRECTIONS_RAYON[$direction];

                    $options[] = [
                        'id' => "rayon_{$direction}",
                        'libelle' => "{$rayon['nom']} — rayon {$libelleDirection} ({$ennemis} ennemi".($ennemis > 1 ? 's' : '').')',
                        'type' => 'rayon',
                        'parametres' => ['direction' => $direction],
                    ];
                }
            }

            // TOUCHER DU BRASIER : « any one ADJACENT enemy » — le contact du
            // héros, jamais la portée de son arme. On repart donc des cibles
            // « à mains nues », qui sont exactement les quatre voisines.
            $auContact = array_values(array_filter(
                $this->ciblesPourArme($quete, $etat, $personnage, null)['attaquer'],
                static fn (array $cible) => empty($cible['distance']),
            ));

            $brasier = $auContact !== []
                ? $this->styles->sourceActivable($personnage, $etat, 'degat_differe')
                : null;

            if ($brasier !== null) {
                $options[] = [
                    'id' => 'toucher_brasier',
                    'libelle' => "{$brasier['nom']} — brûler un ennemi au contact",
                    'type' => 'degat_differe',
                    'parametres' => ['cibles' => $auContact],
                ];
            }

            // FORCE DE LA MONTAGNE (Moine, Style de la Terre) — « Roll 2
            // additional Attack dice on an unarmed attack. » Une option
            // d'attaque de plus, comme la Furie : le type `attaque` lui donne la
            // feuille de ciblage et la liste blanche, `parametres.style` porte
            // la technique. À mains nues seulement, donc jamais offerte armé.
            // « à mains nues » = AUCUNE des deux mains armée (un bouclier ne
            // compte pas : il ne frappe pas).
            $poing = $mainsNues
                ? $this->styles->sourceActivable($personnage, $etat, 'bonus_des_attaque_mains_nues')
                : null;

            if ($poing !== null && $cibles['attaquer'] !== []) {
                $bonus = (int) ($poing['effet']['valeur'] ?? 2);

                $options[] = [
                    'id' => 'style_poing',
                    'libelle' => "{$poing['nom']} — frapper à mains nues (+{$bonus} dés)",
                    'type' => 'attaque',
                    'lancer' => false,
                    'parametres' => [
                        'style' => 'bonus_des_attaque_mains_nues',
                        'cibles' => $cibles['attaquer'],
                    ],
                ];
            }

            // Tout ce qui suit est une action ORDINAIRE : hors bonus d'attaque,
            // le créneau doit encore être libre. Le héros garde toutefois le
            // droit de terminer son tour sans dépenser la frappe offerte.
            if ($bonusAttaqueDisponible) {
                $options[] = ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'];

                return [
                    'situation' => 'Une attaque supplémentaire vous est offerte ce tour.',
                    'options' => $options,
                ];
            }

            // Attaquer les REJETONS accrochés (Jungles of Delthrak, règle de
            // retrait précisée par René le 2026-08-10) : « un héros portant des
            // jetons peut les attaquer, et un héros adjacent à un autre héros
            // portant des jetons peut les attaquer aussi, en ciblant le JETON et
            // non le joueur ».
            //
            // Donc SOI-MÊME (distance 0) ou un voisin au CONTACT (distance 1) —
            // jamais à distance : on arrache une bestiole accrochée, on ne la
            // tire pas d'une salle à l'autre.
            $porteurs = $quete->etatsPersonnages()
                ->where('jetons_rejeton', '>', 0)
                ->with('personnage')
                ->get()
                ->filter(fn ($p) => abs((int) $p->position_x - (int) $etat->position_x)
                    + abs((int) $p->position_y - (int) $etat->position_y) <= 1);

            foreach ($porteurs as $porteur) {
                $soi = (int) $porteur->personnage_id === (int) $etat->personnage_id;

                $options[] = [
                    'id' => "detacher_rejetons_{$porteur->personnage_id}",
                    'libelle' => $soi
                        ? "Arracher tes rejetons (×{$porteur->jetons_rejeton})"
                        : "Arracher les rejetons de {$porteur->personnage?->nom} (×{$porteur->jetons_rejeton})",
                    'type' => 'detacher_rejetons',
                    'parametres' => ['personnage_id' => (int) $porteur->personnage_id],
                ];
            }

            // `soin_allie` (Ballade apaisante du barde, Appel au ralliement du
            // chevalier) : 1d6 PV à un héros AU CONTACT, une fois par quête.
            //
            // ⚠ Une seule option qui PORTE SES CIBLES, jamais une par voisin :
            // c'est la règle du ciblage en deux temps (`parametres.cibles` EST
            // la liste blanche que le résolveur revalide). Le blessé le plus
            // bas d'abord — c'est lui qu'on vient soigner.
            if ($this->talents->disponible($personnage, $etat, 'soin_allie')) {
                $blesses = $quete->etatsPersonnages()
                    ->where('personnage_id', '!=', $personnage->id)
                    ->with('personnage')
                    ->get()
                    ->filter(fn ($e) => $e->position_x !== null
                        && abs((int) $e->position_x - (int) $etat->position_x) <= 1
                        && abs((int) $e->position_y - (int) $etat->position_y) <= 1
                        && $e->personnage !== null
                        && (int) $e->personnage->pv_body < (int) $e->personnage->pv_body_max)
                    ->sortBy(fn ($e) => (int) $e->personnage->pv_body)
                    ->values();

                if ($blesses->isNotEmpty()) {
                    $options[] = [
                        'id' => 'soigner_allie',
                        'libelle' => 'Soigner un compagnon à ton contact (1d6 PV)',
                        'type' => 'soin_allie',
                        'parametres' => [
                            'cibles' => $blesses->map(fn ($e) => [
                                'id' => (int) $e->personnage_id,
                                'nom' => (string) $e->personnage->nom,
                                'pv_body' => (int) $e->personnage->pv_body,
                                'pv_body_max' => (int) $e->personnage->pv_body_max,
                                'tombe' => (bool) $e->tombe,
                            ])->values()->all(),
                        ],
                    ];
                }
            }

            // Relever un allié TOMBÉ adjacent (doc 03 §48) : sacrifie le tour.
            $allies = $quete->etatsPersonnages()
                ->where('tombe', true)
                ->where('personnage_id', '!=', $personnage->id)
                ->with('personnage')
                ->get()
                ->filter(fn ($e) => $e->position_x !== null
                    && abs((int) $e->position_x - (int) $etat->position_x)
                        + abs((int) $e->position_y - (int) $etat->position_y) === 1);

            foreach ($allies as $allie) {
                // §2.17 — un tombé n'occupe PAS sa case (règle assumée : on
                // l'enjambe), donc une autre figure a pu s'y installer. Dans ce
                // cas `ResolveurTour::resoudreRelever` refuse (« une autre
                // figure occupe sa case ») : ne proposons pas une action que le
                // moteur rejettera à coup sûr. Constaté en partie réelle — un
                // monstre campait sur le corps et le bouton restait cliquable,
                // échouant à chaque fois sans issue.
                $libre = FabriqueGrille::pour($quete, exceptPersonnageId: (int) $allie->personnage_id)
                    ->estTraversable((int) $allie->position_x, (int) $allie->position_y);

                if (! $libre) {
                    continue;
                }

                $options[] = [
                    'id' => "relever_{$allie->personnage_id}",
                    'libelle' => "Relever {$allie->personnage->nom}",
                    'type' => 'relever',
                    'cible_personnage_id' => (int) $allie->personnage_id,
                ];
            }

            // Désamorcer un piège détecté (Nain / trousse à outils).
            if ($detectes !== [] && $this->pieges->peutDesamorcer($personnage)) {
                foreach ($detectes as $adjacent) {
                    $nomPiege = $adjacent['piege']?->nom ?? 'Piège';
                    $options[] = [
                        'id' => "desamorcer_{$adjacent['x']}_{$adjacent['y']}",
                        'libelle' => "Désamorcer {$nomPiege} — jet de Body",
                        'type' => 'desamorcage',
                        'jet' => ['attribut' => 'body', 'difficulte' => ResolveurTour::DIFFICULTE_DESAMORCAGE],
                        'parametres' => ['piege' => ['x' => $adjacent['x'], 'y' => $adjacent['y']]],
                    ];
                }
            }

            // Équiper / ranger une pièce en pleine quête (doc 01 §149) = action
            // du tour. Réutilise l'inventaire réel : « Équiper » les pièces
            // d'équipement du sac, « Ranger » celles portées.
            foreach ($personnage->inventaire()->with('objet')->orderBy('id')->get() as $ligne) {
                $objet = $ligne->objet;
                if ($objet === null || ! in_array($objet->emplacement, Equipement::SLOTS, true)) {
                    continue;
                }

                if ($ligne->emplacement === 'sac') {
                    // Une arme à UNE main se porte à droite OU à gauche : une
                    // option par main, sinon le dual-wielding n'existerait qu'au
                    // hub. Les autres pièces gardent leur bouton unique.
                    $slots = $this->equipement->slotsPossibles($objet);

                    foreach ($slots as $slot) {
                        $main = count($slots) > 1
                            ? ($slot === 'arme_principale' ? ' (main droite)' : ' (main gauche)')
                            : '';

                        // La main droite garde l'identifiant historique
                        // `equiper_{id}` : c'est le geste ordinaire, et tout ce
                        // qui l'appelait déjà continue de marcher.
                        $options[] = [
                            'id' => $slot === 'arme_secondaire' ? "equiper_{$ligne->id}_gauche" : "equiper_{$ligne->id}",
                            'libelle' => "Équiper {$objet->nom}{$main}",
                            'type' => 'equiper',
                            'parametres' => ['inventaire_id' => (int) $ligne->id, 'emplacement' => $slot],
                        ];
                    }
                } elseif (in_array($ligne->emplacement, Equipement::SLOTS, true)) {
                    $options[] = [
                        'id' => "desequiper_{$ligne->id}",
                        'libelle' => "Ranger {$objet->nom}",
                        'type' => 'desequiper',
                        'parametres' => ['inventaire_id' => (int) $ligne->id],
                    ];
                }
            }
        }

        // Sorts / parchemins / concentration = créneau ACTION. Réserve arcanique
        // (nœud magicien) : un SECOND sort reste proposé même après avoir déjà
        // agi ce tour, tant que ce bonus n'a pas encore été consommé.
        // …ou par la Baguette de Rappel, qui accorde le même second sort sans
        // coûter de point de compétence. Le menu doit connaître les DEUX
        // sources : le résolveur accepte l'objet, mais le contrôleur refuse
        // toute option absente du dernier menu — c'est exactement le trou par
        // lequel la seconde attaque de la Potion d'héroïsme était devenue
        // injouable.
        $bonusReserveArcaniqueDisponible = $etat !== null && $aAgi
            && ! (bool) ($etat->bonus_sort_utilise ?? false)
            && ($this->talents->a($personnage, 'sort_supplementaire_par_tour')
                || $this->charges->pieceActive($personnage, 'second_sort_par_tour') !== null);

        if ($etat !== null && ! $aAgi && ! $actionInterdite) {
            foreach ($this->sorts->options($groupe, $quete, $personnage) as $option) {
                $options[] = $option;
            }
        } elseif ($bonusReserveArcaniqueDisponible) {
            // Bonus déjà consommé le créneau action normal : seul un second
            // SORT connu (pas un parchemin/la concentration) reste proposable.
            foreach ($this->sorts->options($groupe, $quete, $personnage) as $option) {
                if (($option['type'] ?? null) === 'sort') {
                    $options[] = $option;
                }
            }
        }

        // ⚠ PAS de garde `action_interdite` sur ce bloc entier : ouvrir une
        // porte et actionner un levier restent permis à l'ÉVANESCENT — « il ne
        // peut que bouger et ouvrir des portes », c'est le texte de la carte et
        // tout l'intérêt du sort. Seules les FOUILLES sont gardées, plus bas.
        if (! $aAgi && $etat !== null) {
            // Ouvrir une porte verrouillée par CLÉ au contact (héros porteur).
            // Actionner un levier au contact (ouvre la porte liée).
            if ($etat->position_x !== null && $quete->carte !== null) {
                $px = (int) $etat->position_x;
                $py = (int) $etat->position_y;

                // Porte close adjacente : simplement fermée → ouverture libre
                // (E2) ; verrouillée à clé → seulement avec la clé. Ouvrir est
                // une INTERACTION : elle ne consomme aucun créneau, on peut
                // reprendre son déplacement juste après.
                $porte = $this->portes->porteFermeeAdjacente($quete->carte, $px, $py);

                if ($porte !== null) {
                    $p = $porte['porte'];
                    $avecCle = ($p['verrou']['type'] ?? null) === 'cle'
                        && $this->portes->possedeCle($personnage, $p['verrou']);

                    if ($this->portes->ouvrableAMain($p) || $avecCle) {
                        $cote = (string) ($p['cote'] ?? 'e');
                        $options[] = [
                            'id' => "ouvrir_porte_{$p['x']}_{$p['y']}_{$cote}",
                            'libelle' => $avecCle ? 'Ouvrir la porte (clé)' : 'Ouvrir la porte',
                            'type' => 'ouvrir_porte',
                            'parametres' => ['porte' => ['x' => (int) $p['x'], 'y' => (int) $p['y'], 'cote' => $cote]],
                        ];
                    }
                }

                // Mobilier fouillable au contact (doc 17) : un coffre, un
                // tombeau, une armoire s'ouvrent — ce n'est pas du décor. Une
                // seule fois pour le groupe : c'est un objet, pas une table de
                // trésor. Créneau ACTION, comme la fouille de salle.
                foreach (($actionInterdite ? [] : $this->mobilier->fouillablesAdjacents($quete->carte, $px, $py, (int) $personnage->id)) as $meuble) {
                    $options[] = [
                        'id' => "fouiller_mobilier_{$meuble['index']}",
                        'libelle' => "Fouiller : {$meuble['nom']}",
                        'type' => 'fouille_mobilier',
                        'parametres' => ['index' => $meuble['index'], 'nom' => $meuble['nom']],
                    ];
                }

                // LEVIER — jet de BODY depuis le 2026-08-24 (décision de René),
                // et non plus une interaction gratuite.
                //
                // ⚠ C'est le principal emploi de `attribut_body` : un levier est
                // toujours là, quand un piège détecté au contact et une fosse
                // sur le trajet sont des accidents. Trois nœuds de la grille
                // (*Colosse*, *Ancré*, *Corps aguerri*) n'avaient jusqu'ici
                // presque rien à faire tourner.
                //
                // ⚠ RETENTABLE sans limite, contrairement aux épreuves : c'est
                // exactement ce qui autorise une salle à ne tenir qu'à ce levier
                // sans jamais se sceller. Le prix est l'action dépensée, tour
                // après tour.
                foreach ($this->portes->leviersAdjacents($quete->carte, $px, $py) as $levier) {
                    $difficulte = DifficulteBody::plafonnee($quete, (int) ($levier['difficulte'] ?? 2));

                    // ⚠ Le TYPE d'option reste `actionner_levier` : un jet de
                    // Body n'a ni contexte ni relance à gagner de `resoudreJet`,
                    // et le basculer en `jet` aurait cassé la narration
                    // (`ChoixController` mappe ce type sur le temps fort
                    // `levier_actionne`), l'icône de la manette et les tests. Le
                    // jet est décrit ici pour le libellé, et lancé par
                    // `resoudreActionnerLevier()`.
                    $options[] = [
                        'id' => "actionner_levier_{$levier['x']}_{$levier['y']}",
                        'libelle' => "Forcer le levier — jet de Body (difficulté {$difficulte})",
                        'type' => 'actionner_levier',
                        'jet' => ['attribut' => 'body', 'difficulte' => $difficulte],
                        'parametres' => ['levier' => ['x' => $levier['x'], 'y' => $levier['y'], 'levier_id' => $levier['levier_id']]],
                    ];
                }

                // MOBILIER DESTRUCTIBLE — l'obstacle qu'on fracasse (2026-08-24).
                // ⚠ Une pièce FOUILLABLE détruite rend une dernière fouille à son
                // destructeur : c'est le troc, on ouvre le passage et on rafle le
                // fond, mais plus personne ne la fouillera.
                foreach ($this->mobilier->destructiblesAdjacents($quete->carte, $px, $py, (int) $personnage->id) as $meuble) {
                    $difficulte = DifficulteBody::plafonnee($quete, (int) $meuble['type']->difficulte_destruction);

                    $options[] = [
                        'id' => "detruire_mobilier_{$meuble['index']}",
                        'libelle' => "Fracasser : {$meuble['nom']} — jet de Body (difficulté {$difficulte})",
                        'type' => 'jet',
                        'jet' => ['attribut' => 'body', 'difficulte' => $difficulte],
                        'parametres' => ['mobilier' => $meuble['index'], 'nom' => $meuble['nom']],
                    ];
                }

                // REPOUSSER UN ENNEMI (2026-08-24) — troisième emploi de
                // `attribut_body`, et le seul qui déplace une figure sans la
                // frapper.
                //
                // ⚠ La difficulté est les PV de Body du CATALOGUE de la
                // créature : 1 pour un gobelin, 10 pour un Seigneur ogre. Jamais
                // ses PV courants — un boss blessé n'est pas plus facile à
                // bousculer — ni son nom affiché, que l'habillage IA rebaptise.
                // Le plafond du groupe la ramène ensuite dans le jouable : on ne
                // promet pas « jamais un boss », on promet « il faut un colosse
                // et de la chance ».
                foreach ($this->ciblesRepoussables($quete, $personnage, $px, $py) as $poussee) {
                    $options[] = [
                        'id' => "repousser_{$poussee['instance_id']}",
                        'libelle' => "Repousser : {$poussee['nom']} — jet de Body (difficulté {$poussee['difficulte']})",
                        'type' => 'poussee',
                        'jet' => ['attribut' => 'body', 'difficulte' => $poussee['difficulte']],
                        'parametres' => ['instance_id' => $poussee['instance_id']],
                    ];
                }

                // ÉPREUVES — les ancrages à JET D'ATTRIBUT (2026-08-24).
                //
                // ⚠ Elles réutilisent `type: 'jet'`, et c'est tout l'intérêt :
                // l'avantage de contexte (`avantage_jet_mind`) et la relance
                // (`relance_jet_mind_rate`) arrivent gratuitement. C'est par
                // elles que le moteur émet enfin des jets `savoir` et
                // `social_peur`, sans quoi six talents de la grille ne se
                // déclenchent jamais.
                foreach ($this->epreuves->adjacentes($quete->carte, $px, $py, (int) $personnage->id) as $epreuve) {
                    $difficulte = $epreuve['attribut'] === 'body'
                        ? DifficulteBody::plafonnee($quete, $epreuve['difficulte'])
                        : $epreuve['difficulte'];

                    $attribut = $epreuve['attribut'] === 'body' ? 'Body' : 'Mind';

                    $options[] = [
                        'id' => "epreuve_{$epreuve['index']}",
                        'libelle' => "{$epreuve['nom']} — jet de {$attribut} (difficulté {$difficulte})",
                        'type' => 'jet',
                        'jet' => [
                            'attribut' => $epreuve['attribut'],
                            'difficulte' => $difficulte,
                            'contexte' => $epreuve['contexte'],
                        ],
                        'parametres' => [
                            'epreuve' => $epreuve['index'],
                            'nom' => $epreuve['nom'],
                            'description' => $epreuve['description'],
                        ],
                    ];
                }

                // Objets de MATÉRIEL des cartes officielles. Sans option ici,
                // ils seraient injouables : `ChoixController` refuse toute
                // option absente du dernier menu — c'est exactement le défaut
                // qu'avait la Potion d'héroïsme, acceptée par le résolveur mais
                // jamais offerte.
                foreach ($this->objetsDeMateriel($quete, $personnage, $etat, $px, $py) as $option) {
                    $options[] = $option;
                }
            }

            if ($actionInterdite) {
                // Paralysé : ni fouille de zone, ni trésor, ni technique du
                // Moine. Le déplacement et les portes, eux, ont déjà été
                // traités au-dessus.
                $options[] = ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'];

                return ['situation' => 'Vous ne pouvez pas agir ce tour.', 'options' => $options];
            }

            $options[] = [
                'id' => 'fouiller',
                'libelle' => 'Fouiller la zone — jet de Mind',
                'type' => 'jet',
                'jet' => ['attribut' => 'mind', 'difficulte' => 1, 'contexte' => 'perception'],
            ];

            // PARLER À LA PIERRE (Moine, Style de la Terre) : la même fouille,
            // mais qui ne peut pas rater. Voir `ResolveurTour::resoudreJet()`
            // pour la décision de portage — notre fouille cherche déjà pièges
            // ET portes secrètes en une action.
            $pierre = $this->styles->sourceActivable($personnage, $etat, 'fouille_complete');

            if ($pierre !== null) {
                $options[] = [
                    'id' => 'fouiller_pierre',
                    'libelle' => "{$pierre['nom']} — fouiller sans risque d'échec",
                    'type' => 'jet',
                    'jet' => ['attribut' => 'mind', 'difficulte' => 1, 'contexte' => 'perception'],
                    'parametres' => ['style' => 'fouille_complete'],
                ];
            }

            // Fouiller — trésor (doc 14 §3.2) : action SÉPARÉE, table
            // risque/récompense, offerte dans une salle « vide » non encore
            // fouillée (rencontres prévues nettoyées).
            if ($this->salleFouillableTresor($quete, $etat)) {
                $options[] = [
                    'id' => 'fouiller_tresor',
                    'libelle' => 'Fouiller — trésor',
                    'type' => 'fouille_tresor',
                ];
            }
        }

        // Terminer le tour tant qu'il RESTE un créneau (renonce au reste). Une
        // fois le tour joué (a_joue), aucune option : le tour est fini.
        // Donjon nettoyé : proposer de rentrer. La quête ne s'arrête plus d'elle
        // -même à la mort du dernier monstre — les héros gardent la main pour
        // fouiller ce qu'ils n'avaient pas encore vu (coffre à artefact, portes
        // secrètes), et c'est un vote de groupe qui clôt.
        //
        // L'option DISPARAÎT si un monstre errant surgit d'une fouille : il faut
        // d'abord le régler.
        // Gratuit comme une interaction : le combat est fini, il n'y a plus rien
        // à faire de son action — exiger un créneau libre n'ajouterait qu'un
        // tour d'attente. Seul un tour TERMINÉ ferme l'option.
        $peutSortir = $quete->objectifAccompli()
            // REPLI anti-blocage : un donjon entièrement vidé libère la sortie
            // même si l'objectif reste hors d'atteinte (coffre inaccessible,
            // boss disparu d'une carte malformée). Mieux vaut rentrer bredouille
            // qu'être enfermé à vie.
            || ! $quete->instancesMonstres()->where('etat', 'actif')->exists();

        if (! $aJoue && $peutSortir) {
            $options[] = [
                'id' => 'quitter_donjon',
                'libelle' => 'Quitter le donjon — proposer au groupe',
                'type' => 'sortie',
            ];
        }

        // BATTRE EN RETRAITE — sans aucune condition, et c'est tout l'intérêt
        // (René, 2026-08-21). `quitter_donjon` ci-dessus exige l'objectif
        // accompli ou le donjon vidé : il dit « on a fini », pas « ça tourne
        // mal ». Un groupe en train de perdre ne pouvait donc ni gagner ni
        // partir — constaté en campagne réelle, deux héros à terre et le boss
        // debout, la seule issue mécanique étant de tomber entièrement.
        // Décrocher doit rester possible au pire moment, sinon ce n'est pas une
        // retraite. Interaction libre comme la sortie : proposer ne coûte pas
        // son tour, et un héros qui a déjà joué garde le droit de le proposer
        // au prochain.
        if (! $aJoue) {
            $options[] = [
                'id' => 'battre_en_retraite',
                'libelle' => 'Battre en retraite — proposer au groupe',
                'type' => 'retraite',
            ];
        }

        // VAGUE MONTANTE (Moine, Style de l'Eau) — « Activate this technique on
        // your turn to split your total movement roll before and after your
        // action. » Chez nous, agir après avoir ENTAMÉ son mouvement confisque
        // le reste (règle de René, 2026-08-07) : la technique lève exactement
        // cette confiscation. Gratuite en créneau, donc offerte tant que le
        // tour n'est pas fini — et inutile une fois qu'on a agi, d'où le filtre.
        $vague = $etat !== null && ! $aJoue && ! $aAgi
            ? $this->styles->sourceActivable($personnage, $etat, 'deplacement_scinde')
            : null;

        if ($vague !== null) {
            $options[] = [
                'id' => 'style_vague',
                'libelle' => "{$vague['nom']} — garder ton déplacement après avoir agi",
                'type' => 'style',
                'parametres' => ['style' => 'deplacement_scinde'],
            ];
        }

        if (! $aJoue) {
            $options[] = ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'];
        }

        return [
            'situation' => $aJoue ? 'Tour terminé — au tour des autres héros.' : 'Vous progressez dans le donjon.',
            'options' => $options,
        ];
    }
}
