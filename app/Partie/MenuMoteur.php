<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Deplacement;
use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
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
     * René, 2026-08-12) : le plateau lit 9+ sur 2 dés rouges, nous 4+ sur notre
     * unique d6.
     */
    private const RUPTURE_EVANESCENCE = 4;

    public function __construct(
        private readonly MoteurPieges $pieges,
        private readonly MoteurPortes $portes,
        private readonly MoteurMobilier $mobilier,
        private readonly MoteurSorts $sorts,
        private readonly LanceurDes $des,
        private readonly Equipement $equipement,
        private readonly MoteurCharges $charges,
    ) {}

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
     * « Fouiller — trésor » offerte ? Le héros doit être dans une SALLE (pas un
     * couloir) « vide » — aucun monstre actif révélé à l'intérieur — qui n'a pas
     * déjà été fouillée pour son trésor (une fouille par salle, doc 14 §3.2).
     */
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
                ->calculer($base, $this->equipement->valeurEffetPorte($personnage, 'malus_deplacement'));

            $etat->update(['deplacement_tour' => $jet->total]);

            // ÉVANESCENCE : « The hero moves unseen if they roll [bas] on their
            // movement dice ; if [haut] is rolled, the spell ends. » Le plateau
            // lance 2 dés rouges et rompt à 9+ ; nous lançons UN d6 et rompons
            // à 4+ (décision de René, 2026-08-12) — une chance sur deux, là où
            // le plateau est à un peu plus d'une sur quatre.
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
            $porteeEffective = $etat?->deplacement_restant !== null
                ? (int) $etat->deplacement_restant
                : $portee['total'] * $this->sorts->multiplicateurDeplacement($personnage);

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

        // ── Créneau ACTION (attaque, relever, désamorçage, sorts, fouille) ──
        if ((! $aAgi || $bonusAttaqueDisponible) && $etat !== null && $etat->position_x !== null) {
            $armePrincipale = $personnage->inventaire()->where('emplacement', 'arme_principale')->with('objet')->first()?->objet;
            // Arme longue (Bâton, Épée longue) : frappe aussi en DIAGONALE, sans
            // pénalité — « the attack is made and defended normally »
            // (reference/16_armurerie.md §6.2, livret de règles p. 14).
            $armeDiagonale = (bool) ($armePrincipale?->effet['attaque_diagonale'] ?? false);

            $adjacents = $quete->instancesMonstres()
                ->where('etat', 'actif')
                ->where('revele', true) // dormant (salle non découverte) = non ciblable (aligné sur ResolveurTour)
                ->with('monstre')
                ->orderBy('id')
                ->get()
                ->filter(fn (InstanceMonstre $i) => $i->position_x !== null
                    && self::monstreAuContact($i, (int) $etat->position_x, (int) $etat->position_y, $armeDiagonale));

            $armeADistance = ($armePrincipale?->effet['portee'] ?? null) === 'distance';
            // Arme JETABLE (dague, hache à main) : elle vise aussi à distance,
            // mais quitte la main du héros — d'où une option distincte.
            $armeJetable = (bool) ($armePrincipale?->effet['jetable'] ?? false);
            $idsAdjacents = $adjacents->pluck('id')->all();

            // Tir à distance (Arbalète, Tir précis) : monstres HORS contact mais
            // en ligne de vue dégagée, si le héros porte une arme à distance.
            $aDistance = collect();
            if ($armeADistance || $armeJetable) {
                $grille = FabriqueGrille::pour($quete, exceptPersonnageId: $personnage->id);
                $aDistance = $quete->instancesMonstres()
                    ->where('etat', 'actif')
                    ->where('revele', true)
                    ->with('monstre')
                    ->orderBy('id')
                    ->get()
                    ->filter(fn (InstanceMonstre $i) => $i->position_x !== null
                        && ! in_array($i->id, $idsAdjacents, true)
                        && $grille->ligneDeVue(
                            (int) $etat->position_x, (int) $etat->position_y,
                            (int) $i->position_x, (int) $i->position_y,
                            figuresBloquent: true,
                        ));
            }

            $idsADistance = $aDistance->pluck('id')->all();

            // Ciblage en DEUX TEMPS : une seule option « Attaquer » (et une
            // « Lancer »), les cibles légales jointes dans `parametres.cibles`
            // — la manette ouvre sa feuille de ciblage, comme pour les sorts.
            // Une option par monstre noyait le menu dès trois ennemis et
            // multipliait les identifiants mécaniques pour rien.
            //
            // ⚠ `parametres.cibles` est désormais la LISTE BLANCHE : c'était
            // l'identifiant d'option qui portait la légalité de la cible, et le
            // contrôleur la validait en validant l'option. `ResolveurTour`
            // vérifie donc l'appartenance, sinon on pourrait viser n'importe
            // quel monstre de la quête, hors portée et hors ligne de vue.
            $cibles = ['attaquer' => [], 'lancer' => []];

            foreach ($adjacents->concat($aDistance) as $instance) {
                $nomBase = $instance->monstre->nom_base;
                $nom = $instance->nomAffiche();
                $aPortee = in_array($instance->id, $idsADistance, true);
                $lance = $armeJetable && ! $armeADistance && $aPortee;

                $cibles[$lance ? 'lancer' : 'attaquer'][] = [
                    'id' => $instance->id,
                    'type' => 'monstre',
                    'nom' => $nom,
                    // Rappel du TYPE du catalogue quand le nom est un habillage
                    // IA → le joueur retrouve la fiche du bestiaire (guide).
                    'nom_base' => $nomBase,
                    'distance' => $aPortee,
                ];
            }

            if ($cibles['attaquer'] !== []) {
                $options[] = [
                    'id' => 'attaquer',
                    'libelle' => 'Attaquer',
                    'type' => 'attaque',
                    'lancer' => false,
                    'parametres' => ['cibles' => $cibles['attaquer']],
                ];
            }

            if ($cibles['lancer'] !== []) {
                $options[] = [
                    'id' => 'lancer',
                    // Lancer PERD l'arme : le libellé doit le dire, sinon le
                    // joueur se retrouve les mains vides sans l'avoir voulu.
                    'libelle' => "Lancer {$armePrincipale->nom} (perdue)",
                    'type' => 'attaque',
                    'lancer' => true,
                    'parametres' => ['lancer' => true, 'cibles' => $cibles['lancer']],
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
                    $options[] = [
                        'id' => "equiper_{$ligne->id}",
                        'libelle' => "Équiper {$objet->nom}",
                        'type' => 'equiper',
                        'parametres' => ['inventaire_id' => (int) $ligne->id],
                    ];
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
            && ($personnage->competences()->where('nom', 'Réserve arcanique')->exists()
                || $this->charges->pieceActive($personnage, 'second_sort_par_tour') !== null);

        // `action_interdite` (Paralysé, Évanescent) : le pendant de
        // `deplacement_interdit`. Un Évanescent MARCHE encore et ouvre les
        // portes — c'est tout l'intérêt du sort —, mais ne peut ni attaquer,
        // ni fouiller, ni désamorcer, ni lancer.
        $actionInterdite = $this->sorts->actionInterdite($personnage);

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
                foreach ($this->mobilier->fouillablesAdjacents($quete->carte, $px, $py) as $meuble) {
                    $options[] = [
                        'id' => "fouiller_mobilier_{$meuble['index']}",
                        'libelle' => "Fouiller : {$meuble['nom']}",
                        'type' => 'fouille_mobilier',
                        'parametres' => ['index' => $meuble['index'], 'nom' => $meuble['nom']],
                    ];
                }

                foreach ($this->portes->leviersAdjacents($quete->carte, $px, $py) as $levier) {
                    $options[] = [
                        'id' => "actionner_levier_{$levier['x']}_{$levier['y']}",
                        'libelle' => 'Actionner le levier',
                        'type' => 'actionner_levier',
                        'parametres' => ['levier' => ['x' => $levier['x'], 'y' => $levier['y'], 'levier_id' => $levier['levier_id']]],
                    ];
                }
            }

            $options[] = [
                'id' => 'fouiller',
                'libelle' => 'Fouiller la zone — jet de Mind',
                'type' => 'jet',
                'jet' => ['attribut' => 'mind', 'difficulte' => 1, 'contexte' => 'perception'],
            ];

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

        if (! $aJoue) {
            $options[] = ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'];
        }

        return [
            'situation' => $aJoue ? 'Tour terminé — au tour des autres héros.' : 'Vous progressez dans le donjon.',
            'options' => $options,
        ];
    }
}
