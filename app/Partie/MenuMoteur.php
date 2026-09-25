<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Deplacement;
use App\Engine\Des\LanceurDes;
use App\Engine\MotsClesEquipement;
use App\Engine\MotsClesSort;
use App\Engine\ResultatDeplacement;
use App\Events\SceneTable;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Marche\CapaciteSac;
use App\Partie\Votes\VoteGroupe;
use App\Support\Journal;
use Illuminate\Support\Facades\Log;

/**
 * Menu générique construit PAR LE MOTEUR depuis l'état exact — repli garanti
 * de la boucle de jeu (contrat : « l'API ne dépend jamais du LLM »).
 *
 * En quête : Se déplacer / Attaquer (UN bouton, cibles légales jointes) /
 * Désamorcer / Franchir (un bouton par piège DÉTECTÉ adjacent, doc 10 §4) /
 * Lancer un sort / Lire un parchemin / Utiliser un objet — TROIS options qui
 * portent chacune la LISTE de leurs sous-choix, cibles légales jointes par
 * entrée (2026-09-01 : un bouton par sort faisait un menu de quatorze options
 * là où le doc 13 §3.1 en veut deux à cinq) / Se concentrer
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
        private readonly OrdreDuTour $ordreDuTour,
        private readonly SceneDeTable $scenes,
    ) {}

    /**
     * Cases de `carte.grille['glace']` (Mur de Glace, plan glace phase 2)
     * ADJACENTES (orthogonal) à (x, y) — même patron que
     * `MoteurPortes::leviersAdjacents()`. Couche DÉDIÉE, distincte du
     * catalogue `terrains` : posée en cours de quête par
     * `MoteurDread::sortDreadMurDeGlace()`, jamais par
     * `AssembleurCarte::placerTerrains()`.
     *
     * @return list<array{x: int, y: int}>
     */
    private function glaceAdjacente(Carte $carte, int $x, int $y): array
    {
        $adjacentes = [];

        foreach ((array) ($carte->grille['glace'] ?? []) as $cellule) {
            if (abs((int) $cellule['x'] - $x) + abs((int) $cellule['y'] - $y) === 1) {
                $adjacentes[] = ['x' => (int) $cellule['x'], 'y' => (int) $cellule['y']];
            }
        }

        return $adjacentes;
    }

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
     * ⚠ La ligne est calculée par `Rayon`, comme chez le résolveur : ce cadran
     * de visée la marchait pour son compte, et il annonçait donc des ennemis
     * que le résolveur pouvait ne pas frapper au premier ajustement.
     *
     * @return array<string, int>
     */
    private function directionsDeRayon(Quete $quete, EtatPersonnageQuete $etat): array
    {
        $cadran = Rayon::cadran($quete, (int) $etat->position_x, (int) $etat->position_y);

        return array_map(
            static fn (array $vise) => $vise['monstres'],
            array_filter($cadran, static fn (array $vise) => $vise['monstres'] > 0),
        );
    }

    /**
     * « Fouiller — trésor » offerte ? Le héros doit être dans une SALLE (pas un
     * couloir) « vide » — aucun monstre actif révélé à l'intérieur — qui n'a pas
     * déjà été fouillée pour son trésor (une fouille par salle, doc 14 §3.2).
     */
    /**
     * L'option UNIQUE « Utiliser un objet » et la liste de ses consommables
     * (René, 2026-09-01) : potions et matériel dans la même entrée de menu.
     *
     * ⚠ Le CRÉNEAU dépend de l'objet, pas du type d'option. L'eau bénite
     * s'emploie « instead of attacking » (donc elle coûte l'action), les
     * chausse-trappes « no action required » et une potion se boit sans rien
     * dépenser. `creneauOption()` tranche sur le TYPE : il ne peut donc plus
     * décider seul pour une liste mixte. L'option porte `objet_libre`, et
     * `resoudreUsageObjet()` dépense l'action lui-même quand l'objet choisi
     * l'exige. Chaque ligne affiche son coût.
     *
     * ⚠ La liste se construit d'après les créneaux RESTANTS : un héros qui a
     * agi n'y trouve plus que le gratuit. La liste est la liste blanche, elle
     * ne doit jamais contenir ce que le résolveur refusera.
     *
     * ⚠ On FILTRE les potions d'une autre classe, on ne les badge pas — patron
     * de `MoteurReactions::soinsDisponibles()` : proposer ce que la résolution
     * refusera est pire que ne rien proposer. (`/moi` continue de badger, lui :
     * un héros a le droit de PORTER la potion d'un compagnon.)
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Cibles légales d'un objet ACTIVABLE, selon son `effet.cible`.
     *
     * Rend `['cibles' => …]` à fusionner dans l'entrée, ou `[]` pour un objet
     * qui ne vise personne — c'est ce qui fait qu'une Cape des Ombres part du
     * deuxième niveau du menu quand le Sceptre en ouvre un troisième
     * (« la profondeur suit la donnée », doc 13 §3.1).
     *
     * ⚠ LIGNE DE VUE exigée dans les deux cas, comme pour les sorts : « nécessaire
     * pour lancer un sort ou observer une cible » (LR p. 14). Saupoudrer de la
     * poudre sur un compagnon à travers un mur n'aurait pas plus de sens que de
     * le soigner.
     *
     * @return array<string, mixed>
     */
    /**
     * Y a-t-il, dans la salle du héros, une créature que cet artefact peut
     * enrôler ? — *Baguette d'Os* : « all skeletons in one room ».
     *
     * ⚠ La salle, et non la ligne de vue : c'est le texte de la carte, et la
     * différence est réelle — un couloir voit loin.
     *
     * ⚠ La famille se lit sur `monstres.nom_base`, le nom de CATALOGUE, jamais
     * celui que l'IA a donné à la créature : une baguette qui cesse de
     * reconnaître un squelette parce que la quête a été narrée serait le défaut
     * que l'Eau bénite et la Lame des Esprits ont déjà coûté.
     */
    private function sbiresCommandables(Quete $quete, Objet $objet, int $px, int $py): bool
    {
        $nomBase = mb_strtolower((string) data_get(
            $objet->effet, MotsClesEquipement::CONTROLE_MONSTRES.'.nom_base', '',
        ));

        $salles = (array) data_get($quete->carte?->grille, 'salles', []);
        $salle = Salles::indexDe($salles, $px, $py);

        if ($salle === null || $nomBase === '') {
            return false;
        }

        return $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)
            ->whereNull('controle_par')
            ->with('monstre')->get()
            ->contains(fn (InstanceMonstre $i) => $i->position_x !== null
                && mb_strtolower((string) $i->monstre?->nom_base) === $nomBase
                && Salles::indexDe($salles, (int) $i->position_x, (int) $i->position_y) === $salle);
    }

    private function ciblesObjet(
        Quete $quete,
        Personnage $personnage,
        string $cible,
        bool $tombeAdmis = false,
        ?Objet $objet = null,
    ): array {
        if ($cible === MotsClesSort::CIBLE_SOI) {
            return [];
        }

        $etat = $quete->etatsPersonnages()->where('personnage_id', $personnage->id)->first();

        if ($etat === null || $etat->position_x === null) {
            return [];
        }

        $grille = FabriqueGrille::pour($quete);
        $vue = fn (?int $x, ?int $y): bool => $x !== null && $grille->ligneDeVue(
            (int) $etat->position_x, (int) $etat->position_y, (int) $x, (int) $y, figuresBloquent: true,
        );

        if ($cible === MotsClesSort::CIBLE_MONSTRE) {
            $cibles = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)
                ->with('monstre')->get()
                ->filter(fn (InstanceMonstre $i) => $vue($i->position_x, $i->position_y))
                ->map(fn (InstanceMonstre $i) => ['id' => $i->id, 'type' => 'monstre', 'nom' => $i->nomAffiche()])
                ->values()->all();

            return $cibles === [] ? [] : ['cibles' => $cibles];
        }

        // ⚠ LA RESTRICTION DE CLASSE SUIT LA CIBLE, jamais le porteur qui
        // sort l'objet de son sac (René, 2026-09-11) : « le menu n'offre
        // jamais ce que le résolveur va refuser » (règle dure du projet), et
        // c'est `MoteurPotions::boire()` qui refusera si le DESTINATAIRE
        // n'a pas la classe requise — un magicien peut très bien PORTER une
        // Potion de rage guerrière réservée au Barbare (`/moi` la badge déjà
        // ainsi), il ne peut simplement pas la boire lui-même, ni la faire
        // boire à un voisin qui ne l'est pas davantage.
        $accessible = fn (?Personnage $candidat): bool => $candidat !== null
            && ($objet === null || $this->equipement->estAccessible($candidat, $objet));

        // `heros_adjacent` — le porteur OU un héros ORTHOGONALEMENT adjacent
        // (`Grille::sontAdjacentes()`, `$diagonales` par défaut à `false` —
        // même convention que `ResolveurTour::resoudreRelever()`). Distinct
        // de `heros` : pas toute la ligne de vue, seulement le contact.
        if ($cible === MotsClesSort::CIBLE_HEROS_ADJACENT) {
            $requete = $quete->etatsPersonnages()->with('personnage');

            if (! $tombeAdmis) {
                $requete->where('tombe', false);
            }

            $cibles = $requete->get()
                ->filter(fn (EtatPersonnageQuete $e) => $e->personnage !== null && $e->position_x !== null
                    && ($e->personnage_id === $personnage->id || $grille->sontAdjacentes(
                        (int) $etat->position_x, (int) $etat->position_y,
                        (int) $e->position_x, (int) $e->position_y,
                    ))
                    && $accessible($e->personnage))
                ->map(fn (EtatPersonnageQuete $e) => [
                    'id' => $e->personnage_id, 'type' => 'heros', 'nom' => $e->personnage->nom,
                ])->values()->all();

            return $cibles === [] ? [] : ['cibles' => $cibles];
        }

        // `heros` — le lanceur COMPRIS : il se voit toujours lui-même.
        //
        // ⚠ `$tombeAdmis` existe pour l'Élixir de Vie, seul artefact dont la
        // cible EST un héros à terre (« brings a dead hero back to life »).
        // Partout ailleurs un héros tombé n'est pas une cible légale, et
        // l'offrir serait proposer un soin que le résolveur refuserait.
        $requete = $quete->etatsPersonnages()->with('personnage');

        if (! $tombeAdmis) {
            $requete->where('tombe', false);
        }

        $cibles = $requete->get()
            ->filter(fn (EtatPersonnageQuete $e) => $e->personnage !== null
                && ($e->personnage_id === $personnage->id || $vue($e->position_x, $e->position_y))
                && $accessible($e->personnage))
            ->map(fn (EtatPersonnageQuete $e) => [
                'id' => $e->personnage_id, 'type' => 'heros', 'nom' => $e->personnage->nom,
            ])->values()->all();

        return $cibles === [] ? [] : ['cibles' => $cibles];
    }

    private function objetsDeMateriel(
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        int $px,
        int $py,
        bool $aAgi,
    ): array {
        $entrees = [];
        $grille = null;
        $equipement = $this->equipement;
        $charges = $this->charges;

        foreach ($personnage->inventaire()->with('objet')->orderBy('id')->get() as $ligne) {
            $objet = $ligne->objet;
            $effet = (array) ($objet?->effet ?? []);

            if ($objet === null) {
                continue;
            }

            if (! empty($effet['pose_chausse_trappes'])) {
                $entrees[] = [
                    'cle' => "objet:{$ligne->id}",
                    'inventaire_id' => $ligne->id,
                    'nom' => $objet->nom,
                    'detail' => 'Semer des chausse-trappes',
                    'cout' => 'gratuit',
                    'quantite' => (int) $ligne->quantite,
                ];
            }

            if (! empty($effet['enfume_monstre_adjacent'])) {
                $cibles = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)
                    ->with('monstre')->get()
                    ->filter(fn (InstanceMonstre $i) => self::monstreAuContact($i, $px, $py))
                    ->map(fn (InstanceMonstre $i) => ['id' => $i->id, 'type' => 'monstre', 'nom' => $i->nomAffiche()])
                    ->values()->all();

                if ($cibles !== []) {
                    $entrees[] = [
                        'cle' => "objet:{$ligne->id}",
                        'inventaire_id' => $ligne->id,
                        'nom' => $objet->nom,
                        'detail' => 'Enfume un monstre au contact',
                        'cout' => 'gratuit',
                        'quantite' => (int) $ligne->quantite,
                        'cibles' => $cibles,
                    ];
                }
            }

            // « Instead of attacking » : l'eau bénite coûte l'action, donc elle
            // quitte la liste dès que le héros a agi.
            if (! empty($effet['tue_creatures']) && ! $aAgi) {
                $noms = array_map('mb_strtolower', array_map('strval', (array) $effet['tue_creatures']));
                $grille ??= FabriqueGrille::pour($quete);

                $cibles = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)
                    ->with('monstre')->get()
                    ->filter(fn (InstanceMonstre $i) => $i->position_x !== null
                        && in_array(mb_strtolower((string) $i->monstre?->nom_base), $noms, true)
                        && $grille->ligneDeVue($px, $py, (int) $i->position_x, (int) $i->position_y))
                    ->map(fn (InstanceMonstre $i) => ['id' => $i->id, 'type' => 'monstre', 'nom' => $i->nomAffiche()])
                    ->values()->all();

                if ($cibles !== []) {
                    $entrees[] = [
                        'cle' => "objet:{$ligne->id}",
                        'inventaire_id' => $ligne->id,
                        'nom' => $objet->nom,
                        'detail' => "Asperger d'eau bénite — au lieu d'attaquer",
                        'cout' => 'action',
                        'quantite' => (int) $ligne->quantite,
                        'cibles' => $cibles,
                    ];
                }
            }

            // ARTEFACTS ACTIVABLES (2026-09-03) — Poudre d'Invisibilité, Cape
            // des Ombres, Sceptre de Télékinésie. Ils entrent dans la MÊME
            // liste que les potions, parce que c'est la même question pour le
            // joueur : « qu'est-ce que j'utilise ? ». Ce qui les distingue est
            // qu'ils ne sont pas des consommables — la Cape se porte, le
            // Sceptre aussi — et qu'ils VISENT.
            //
            // ⚠ La CHARGE est le garde-fou : « once per quest » est rendu par
            // `charges: 1`, remis à neuf entre deux quêtes. Sans le filtre sur
            // `disponible()`, l'option resterait offerte une fois la charge
            // épuisée et le résolveur répondrait non — l'anti-patron que le
            // projet traque partout.
            // ⚠ `utilisable()` et non `disponible()` : il pose LES DEUX questions,
            // les charges ET la fenêtre « une fois par quête ». Interroger la
            // seule charge laissait l'option debout après usage — un bouton qui
            // répond toujours non, l'anti-patron que le projet traque.
            if (! empty($objet->effet['activable']) && $charges->utilisable($ligne, $etat)
                && $equipement->estAccessible($personnage, $objet)) {
                // ⚠ La *Baguette d'Os* n'a rien à enrôler dans une salle sans
                // squelette : l'option disparaît plutôt que de rester un bouton
                // qui répond non. Elle ne VISE personne — elle fait passer de
                // camp les créatures présentes —, d'où une simple présence à
                // vérifier et aucune liste de cibles.
                if (! empty($objet->effet[MotsClesEquipement::CONTROLE_MONSTRES])
                    && ! $this->sbiresCommandables($quete, $objet, $px, $py)) {
                    continue;
                }

                $entrees[] = [
                    'cle' => "objet:{$ligne->id}",
                    'inventaire_id' => $ligne->id,
                    'nom' => $objet->nom,
                    'detail' => 'Activer',
                    'cout' => (string) ($objet->effet['cout'] ?? 'action'),
                    'quantite' => (int) $ligne->quantite,
                    // ⚠ Ce que la pièce fait, en clair : la liste de choix se
                    // consulte EN PLEIN TOUR, c'est-à-dire au moment où on a le
                    // plus besoin de savoir. Traduit côté serveur, comme partout.
                    'avantages' => MotsClesEquipement::avantages((array) $objet->effet),
                    // ⚠ `cibles` PAR ENTRÉE, comme pour les sorts : la Poudre
                    // vise un héros, le Sceptre un monstre, la Cape personne.
                    // Une liste au niveau de l'option serait fausse pour deux
                    // des trois.
                    ...$this->ciblesObjet(
                        $quete, $personnage, (string) ($objet->effet['cible'] ?? 'soi'),
                        tombeAdmis: ! empty($objet->effet['releve']),
                        objet: $objet,
                    ),
                ];

                continue;
            }

            // POTIONS — `MoteurPotions` n'accepte que la catégorie
            // `consommable` (et jamais un `activable`, déjà traité et sorti
            // par le `continue` ci-dessus), c'est le même filtre que
            // `/moi.consommables`.
            //
            // ⚠ CIBLE ADJACENTE (René, 2026-09-11) : `effet.cible` peut
            // désormais valoir `heros_adjacent` — tendre sa potion à un
            // voisin, pas seulement la boire soi-même. `ciblesObjet()` filtre
            // alors chaque candidat par `estAccessible()` : la restriction de
            // classe d'une carte suit qui VA BOIRE, jamais qui la sort du
            // sac (`MoteurPotions::boire()` la revérifie côté résolveur). Pour
            // `soi` — l'écrasante majorité des potions — `ciblesObjet()` ne
            // construit toujours rien, et c'est l'accessibilité du PORTEUR
            // qui décide, comme avant : comportement inchangé.
            if ($objet->categorie === 'consommable' && empty($objet->effet['activable'])) {
                $ciblePotion = (string) ($objet->effet['cible'] ?? MotsClesSort::CIBLE_SOI);
                $ciblesPotion = $this->ciblesObjet($quete, $personnage, $ciblePotion, objet: $objet);

                // « Le menu n'offre jamais ce que le résolveur va refuser » :
                // sur `soi`, seule l'accessibilité du porteur compte ; sur
                // `heros_adjacent`, l'entrée n'apparaît QUE s'il reste au
                // moins un destinataire légal (soi compris) — jamais un
                // bouton qui répondrait toujours non.
                $offrable = $ciblePotion === MotsClesSort::CIBLE_SOI
                    ? $equipement->estAccessible($personnage, $objet)
                    : $ciblesPotion !== [];

                if ($offrable) {
                    $entrees[] = [
                        'cle' => "objet:{$ligne->id}",
                        'inventaire_id' => $ligne->id,
                        'nom' => $objet->nom,
                        'detail' => 'Boire',
                        'cout' => 'gratuit',
                        'quantite' => (int) $ligne->quantite,
                        'avantages' => MotsClesEquipement::avantages((array) $objet->effet),
                        ...$ciblesPotion,
                    ];
                }
            }
        }

        if ($entrees === []) {
            return [];
        }

        return [[
            'id' => 'utiliser_objet',
            'libelle' => 'Utiliser un objet',
            'type' => 'objet_libre',
            'parametres' => ['objets' => $entrees],
        ]];
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
            return ['base' => $base, 'de' => null, 'des' => [], 'de_annule' => false, 'de_annule_par' => null, 'total' => $base];
        }

        // ⚠ AU TOUR DU HÉROS, et plus au début du round (2026-09-16). Les menus
        // sont recalculés pour TOUS les héros après chaque choix : sans cette
        // garde, les quatre dés partaient ensemble dès le premier menu du
        // round, et la scène « au début d'un tour de joueur » que René demandait
        // n'avait aucun instant à qui appartenir. La colonne `deplacement_tour`
        // reste la garde d'unicité : un menu recalculé pendant le tour ne
        // relance rien et n'annonce rien deux fois.
        $groupe = $etat->quete?->groupe;

        if ($etat->deplacement_tour === null && ! $etat->tombe && ! $etat->a_joue
            && $groupe !== null && $this->ordreDuTour->estSonTour($groupe, (int) $personnage->id)) {
            // Armure lourde : « 1 red die only for movement » (carte Plate
            // Mail officielle 2021). `Deplacement` savait annuler le d6 depuis
            // toujours, mais aucun appelant ne le lui avait jamais dit — il
            // n'avait donc jamais joué, et l'armure la plus chère n'avait que
            // des avantages.
            // BOTTES ELFIQUES : « an extra red die for movement ». Le dé
            // supplémentaire est lancé ICI, avec les autres, parce que c'est
            // ICI que le jet du tour est fixé et mémorisé — le joueur le voit
            // avant de choisir sa case, et le résolveur ne relance jamais.
            $bottes = $this->charges->pieceActive(
                $personnage, MotsClesEquipement::DE_DEPLACEMENT_SUPPLEMENTAIRE, $etat,
            );

            // RAQUETTES DE VITESSE : même point de passage que
            // `ResolveurTour::resoudreDeplacement()` — le menu ne doit jamais
            // annoncer une portée que le résolveur refuserait ensuite. Ajouté
            // au socle passé à `calculer()`, JAMAIS à `$base` lui-même : la
            // valeur publiée de `base` reste celle du personnage seul (contrat
            // §« L'Armure de plates FAIT PERDRE LE DÉ », 2026-09-24).
            $bonusRaquettes = $etat->quete !== null
                ? $this->equipement->bonusDeplacementActif($personnage, $etat->quete)
                : 0;

            // ⚠ MÊME appel que celui qui nomme la source : `deDeplacementAnnule()`
            // et `sourceDeDeplacementAnnule()` sortent tous deux de
            // `Equipement::detailDeDeplacementAnnule()`, jamais d'une seconde
            // recherche qui pourrait nommer une armure dont l'effet vient
            // d'être annulé (Chevalier, Allégée).
            $deAnnule = $this->equipement->deDeplacementAnnule($personnage);
            $deAnnulePar = $this->equipement->sourceDeDeplacementAnnule($personnage);

            $jet = (new Deplacement($this->des))->calculer(
                $base + $bonusRaquettes,
                $deAnnule,
                (int) (($bottes?->objet?->effet ?? [])[MotsClesEquipement::DE_DEPLACEMENT_SUPPLEMENTAIRE] ?? 0),
            );

            // Détail RÉEL du jet, persisté au lancer — une colonne, jamais un
            // cache (règle consolidée du projet) — pour que la face survive à
            // un menu régénéré plus tard dans le même tour. `deplacement_tour`
            // ne garde que le total ; c'était lui seul, et `de` se voyait
            // reconstitué comme `total − base`, faux dès qu'un malus mordait,
            // puis carrément absent quand le dé ne compte plus du tout
            // (contrat §« L'Armure de plates FAIT PERDRE LE DÉ », 2026-09-24).
            $etat->update([
                'deplacement_tour' => $jet->total,
                'detail_deplacement_tour' => [
                    'base' => $base,
                    'des' => $jet->des,
                    'de_annule' => $jet->deAnnule,
                    'de_annule_par' => $jet->deAnnule ? $deAnnulePar : null,
                ],
            ]);

            $this->annoncerDeplacement($groupe, $personnage, $etat, $jet, $base, $bonusRaquettes, $deAnnulePar);

            $this->userSurDesIdentiques($personnage, $etat, $bottes, $jet);

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
        $detail = $etat->detail_deplacement_tour;

        if ($detail === null) {
            // Compat : une ligne déjà en tour au moment où cette colonne est
            // apparue (ou un très ancien snapshot restauré) porte un total
            // mais pas de détail. On retombe sur l'ancienne reconstitution —
            // fausse dès qu'un malus mordait, muette sur un dé annulé —
            // plutôt que de perdre le tour en cours ; transitoire, un seul
            // tour au pire.
            $detail = [
                'base' => $base,
                'des' => array_values(array_filter([$total > $base ? $total - $base : null])),
                'de_annule' => false,
                'de_annule_par' => null,
            ];
        }

        return [
            'base' => (int) $detail['base'],
            'de' => $detail['des'][0] ?? null,
            'des' => array_values((array) $detail['des']),
            'de_annule' => (bool) $detail['de_annule'],
            'de_annule_par' => $detail['de_annule_par'] ?? null,
            'total' => $total,
        ];
    }

    /**
     * La portée ANNONCÉE d'un tour entamé à neuf : le total du jet, multiplié
     * (Vent Véloce, potion de vitesse), puis les cases EN PLUS de la potion de
     * dextérité — ajoutées après, « une potion de vitesse ne double pas le
     * bonus de l'autre ».
     *
     * ⚠ Miroir de `ResolveurTour::pointsDeplacement()`, et désormais lu aux
     * DEUX endroits qui l'annoncent : l'option `se_deplacer` et la scène de
     * début de tour. Écrite deux fois, la table aurait pu promettre 9 cases
     * quand le téléphone en offrait 14.
     *
     * @return array{multiplicateur: int, bonus: int, portee: int}
     */
    private function porteeDuTour(Personnage $personnage, int $totalTour): array
    {
        $multiplicateur = $this->sorts->multiplicateurDeplacement($personnage);
        $bonus = $this->sorts->bonusDes($personnage, 'bonus_deplacement');

        return [
            'multiplicateur' => $multiplicateur,
            'bonus' => $bonus,
            'portee' => $totalTour * $multiplicateur + $bonus,
        ];
    }

    /**
     * La SCÈNE de début de tour, sur l'écran de table (`genre: deplacement`).
     *
     * Émise ICI parce que c'est le seul instant où les dés RÉELS existent —
     * `$jet` vient tout juste d'être lancé, avant même sa persistance. Le
     * détail est désormais aussi en colonne (`detail_deplacement_tour`), mais
     * la scène reste construite depuis l'objet frais plutôt que de relire ce
     * qui vient d'être écrit.
     *
     * `deAnnulePar` (2026-09-24, contrat §« L'Armure de plates FAIT PERDRE LE
     * DÉ ») est le nom de la pièce qui annule le d6 — sorti du MÊME appel que
     * `$jet->deAnnule` chez l'appelant (`Equipement::detailDeDeplacementAnnule()`),
     * jamais recalculé ici.
     *
     * ⚠ Best-effort : une diffusion qui échoue ne doit jamais faire échouer la
     * composition du menu — le joueur resterait sans rien à jouer.
     */
    private function annoncerDeplacement(
        Groupe $groupe,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        ResultatDeplacement $jet,
        int $base,
        int $bonusEquipement,
        ?string $deAnnulePar,
    ): void {
        try {
            $portee = $this->porteeDuTour($personnage, $jet->total);

            broadcast(new SceneTable(
                $groupe,
                $this->scenes->deplacement($personnage->fresh() ?? $personnage, [
                    'base' => $base,
                    'des' => $jet->des !== [] ? $jet->des : array_filter([$jet->de]),
                    'bonus_equipement' => $bonusEquipement,
                    'de_annule' => $jet->deAnnule,
                    'de_annule_par' => $jet->deAnnule ? $deAnnulePar : null,
                    'total_jet' => $jet->total,
                    'multiplicateur' => $portee['multiplicateur'],
                    'bonus_potion' => $portee['bonus'],
                    'portee' => $portee['portee'],
                ]),
                (int) Evenement::query()->where('groupe_id', $groupe->id)->max('sequence'),
            ));
        } catch (\Throwable $e) {
            Log::warning('Scène de début de tour impossible.', [
                'personnage_id' => $personnage->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    /**
     * *Bottes elfiques* : « The boots wear out if the Elf rolls identical
     * numbers on any 3 dice. »
     *
     * ⚠ DEUX dés chez nous, pas trois (arbitrage de René, 2026-09-03) : notre
     * jet est socle + 1d6 et les bottes le portent à 2d6 ; exiger un triple sur
     * deux dés aurait donné des bottes éternelles. L'usure tombe donc une fois
     * sur six au lieu d'une sur trente-six — la règle est la même, sa cadence
     * suit le nombre de dés que nous lançons.
     *
     * ⚠ Elle est JOURNALISÉE : une pièce qui disparaît du sac sans un mot est
     * exactement l'effet automatique inannoncé que le projet refuse ailleurs.
     */
    private function userSurDesIdentiques(
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        ?Inventaire $ligne,
        ResultatDeplacement $jet,
    ): void {
        $effet = (array) ($ligne?->objet?->effet ?? []);

        if ($ligne === null
            || empty($effet[MotsClesEquipement::USURE_SUR_DES_IDENTIQUES])
            || count($jet->des) < 2
            || count(array_unique($jet->des)) !== 1) {
            return;
        }

        $groupe = $etat->quete?->groupe;
        $nom = $ligne->objet?->nom;

        $ligne->delete();

        if ($groupe !== null) {
            Journal::ajouter($groupe, 'systeme', [
                'type' => 'objet_use',
                'objet' => $nom,
                'des' => $jet->des,
            ], ['nom' => $personnage->nom]);
        }
    }

    /**
     * Le héros a-t-il au moins une case d'ARRIVÉE légale (donc un déplacement
     * réel possible) ? Sans carte/position, on suppose le déplacement possible
     * (ne jamais masquer à tort).
     *
     * ⚠ Reconstruisait jusqu'ici sa PROPRE boucle d'occupation — une copie de
     * `FabriqueGrille::pour()`, le point de passage unique de cette question
     * (doc CLAUDE.md « une règle, un point de passage ») — et traitait tout
     * héros ou mercenaire DEBOUT comme un mur. Or depuis le 2026-09-04 « on
     * peut traverser la case d'un allié, pas s'y arrêter » (LR p. 12, doc 16
     * §5) : le menu retirait donc « Se déplacer » à un héros encerclé
     * d'ALLIÉS que le résolveur, lui, aurait laissé passer (signalé en partie
     * réelle, 2026-09-11). `FabriqueGrille::pour(…, franchitAllies: true)` est
     * exactement l'appel que fait `ResolveurTour::resoudreDeplacer()`.
     *
     * ⚠ Ni un simple voisin : un héros dont les 4 cases adjacentes sont toutes
     * occupées (par des alliés, ou par des monstres pour un Rogue) peut
     * pourtant avoir une case libre à 2 pas, atteignable en les traversant.
     * `casesAtteignables()` (même parcours pondéré que le résolveur) explore
     * à travers les figures traversables tout en excluant leur case des
     * destinations — un simple test des 4 voisins sous-estimait la portée
     * réelle du résolveur.
     *
     * ⚠ MOBILITÉ DE COMBAT (Rogue) / Voile de Brume et Traverser la Pierre
     * sont relus ICI pour la même raison qu'un Rogue ne peut pas CLIQUER
     * au-delà d'un monstre sans le même calcul côté `EtatGroupe` : un talent
     * ou un buff qui lève les figures ou la roche pour le résolveur doit
     * lever le même mur pour le menu, sans quoi « Se déplacer » disparaît
     * derrière un obstacle que le clic suivant aurait pourtant accepté.
     */
    private function peutSeDeplacer(Quete $quete, Personnage $personnage, ?EtatPersonnageQuete $etat): bool
    {
        if ($etat === null || $etat->position_x === null || $etat->tombe || $quete->carte === null) {
            return true;
        }

        $grille = FabriqueGrille::pour(
            $quete,
            exceptPersonnageId: $personnage->id,
            traverseRoche: $this->sorts->traverseRoche($personnage),
            franchitAllies: true,
        );

        // Les figures seules, jamais le mobilier : même appel que
        // `ResolveurTour::resoudreDeplacement()`, sans quoi le menu offrirait
        // « Se déplacer » vers une case que seul un meuble traversé atteint.
        if ($this->sorts->mobiliteCombatDisponible($personnage)) {
            $grille->autoriserFranchissementFigures();
        }

        $pas = $this->pointsRestants($personnage, $etat);

        return $pas >= 1
            && $grille->casesAtteignables((int) $etat->position_x, (int) $etat->position_y, $pas) !== [];
    }

    /**
     * Publie `creneau` sur chaque option du menu (contrat « creneau — chaque
     * option dit ce qu'elle coûte », 2026-09-18) : la valeur que rend
     * `ResolveurTour::creneauOption()` pour son `type`, TELLE QUELLE.
     *
     * ⚠ UN SEUL POINT DE PASSAGE : cette méthode n'introduit AUCUNE table de
     * correspondance à elle — elle appelle celle de `ResolveurTour`. Recopier
     * le `match` ici referait exactement l'erreur qu'on corrige côté client
     * (`ActionTab.creneauConsomme()`, qui a menti trois fois en gardant sa
     * propre copie de cette règle). Les quatre points de sortie de `generer()`
     * passent tous par ici avant de rendre leurs options.
     *
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    private function avecCreneaux(array $options): array
    {
        return array_map(
            static fn (array $option) => $option + [
                'creneau' => ResolveurTour::creneauOption((string) ($option['type'] ?? '')),
            ],
            $options,
        );
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
                'options' => $this->avecCreneaux([
                    ['id' => 'attendre', 'libelle' => 'Attendre et observer', 'type' => 'attente'],
                    ['id' => 'continuer', 'libelle' => 'Continuer prudemment', 'type' => 'action'],
                ]),
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

        // CHUTE DE BLOCS (livret p. 14, 2026-09-24) : « the hero then decides
        // to move ahead or move back to an empty square ». Tant que ce choix
        // n'est pas fait, RIEN D'AUTRE n'est proposé — ni se déplacer (le
        // reste de l'allonce est déjà perdu), ni agir : ce court-circuite
        // TOUTE la suite de la méthode, exactement comme le hub plus haut.
        // `piege_a_ecarter` vit en COLONNE (`ResolveurTour::resoudreEcartDuBloc()`
        // l'efface), jamais en cache : un téléphone rechargé au mauvais
        // moment doit retrouver le choix en attente, pas une carte muette.
        if ($etat !== null && $etat->piege_a_ecarter !== null) {
            $attente = (array) $etat->piege_a_ecarter;

            return [
                'situation' => 'Le bloc de pierre s\'est refermé sur vous : vous devez vous écarter avant de poursuivre.',
                'options' => $this->avecCreneaux([[
                    'id' => 's_ecarter_du_bloc',
                    'libelle' => 'S\'écarter du bloc de pierre',
                    'type' => 's_ecarter_du_bloc',
                    'parametres' => [
                        'bloc' => ['x' => (int) ($attente['x'] ?? 0), 'y' => (int) ($attente['y'] ?? 0)],
                        // Liste blanche — le résolveur la relit sur CETTE option,
                        // jamais reconstruite depuis la colonne (un point de passage).
                        'cases' => array_values((array) ($attente['cases'] ?? [])),
                    ],
                ]]),
            ];
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
                : $this->porteeDuTour($personnage, $portee['total'])['portee'];

            $options[] = [
                'id' => 'se_deplacer',
                'libelle' => $etat?->deplacement_restant !== null ? 'Continuer à se déplacer' : 'Se déplacer',
                'type' => 'deplacement',
                'parametres' => [
                    'portee_base' => (int) $personnage->deplacement_base,
                    'base' => $portee['base'],
                    // `de` est désormais la face RÉELLEMENT tombée, jamais
                    // reconstituée (contrat §« L'Armure de plates FAIT PERDRE
                    // LE DÉ », 2026-09-24) : avant, une Armure de plates
                    // pouvait afficher « dé 3 » pour un vrai 5, ou faire
                    // disparaître le dé (`de: null`) sur un jet de 1 ou 2. Le
                    // dé compte ou non dans `portee` selon `de_annule` — il
                    // reste publié dans les deux cas, pour que l'écran le
                    // montre tomber puis, le cas échéant, le raye.
                    'de' => $portee['de'],
                    'des' => $portee['des'],                 // toutes les faces (2 avec les Bottes elfiques)
                    'de_annule' => $portee['de_annule'],      // DÉCISION : le d6 ne compte pas ce tour
                    'de_annule_par' => $portee['de_annule_par'], // nom de la pièce qui l'annule, null si le dé compte
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
            // BOTTES DE LIÈVRE : « To jump over one discovered trap per turn,
            // roll anything but a black shield on 1 combat die. »
            //
            // ⚠ Elles n'écrasent pas le saut ordinaire, elles s'y AJOUTENT —
            // deux boutons, comme le *Dragon bondissant*. Le saut de Body reste
            // offert sur les fosses : la fenêtre des bottes est d'un saut par
            // tour, et un héros qui l'a dépensée doit pouvoir sauter quand même.
            // ⚠ Et elles n'élargissent la cible QUE pour leur porteur : la
            // règle des autres héros ne bouge pas d'un pouce.
            $bottes = $etat !== null
                ? $this->charges->pieceActive($personnage, MotsClesEquipement::SAUT_PIEGE_DE_COMBAT, $etat)
                : null;

            foreach ($detectes as $adjacent) {
                $nomPiege = $adjacent['piege']?->nom ?? 'Piège';

                if ($bottes !== null) {
                    $options[] = [
                        'id' => "franchir_bottes_{$adjacent['x']}_{$adjacent['y']}",
                        'libelle' => "{$bottes->objet?->nom} — bondir par-dessus {$nomPiege}",
                        'type' => 'franchissement',
                        'parametres' => [
                            'piege' => ['x' => $adjacent['x'], 'y' => $adjacent['y']],
                            'cout' => ResolveurTour::COUT_FRANCHISSEMENT,
                            'bottes' => $bottes->id,
                        ],
                    ];
                }

                if ($this->pieges->estFosse($adjacent['piege'])) {
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

        // ── JETER (créneau INTERACTION, GRATUIT — révision René 2026-09-17) ──
        // « Jeter des items ne prend pas d'action, permettant d'en jeter
        // plusieurs dans le même tour. » `ResolveurTour::creneauOption()`
        // range déjà `jeter` en `interaction` : ce qui manquait ici, c'est de
        // sortir l'option de la garde `! $aAgi` du créneau ACTION ci-dessous.
        //
        // ⚠ Piège nommé par le plan : rester dans ce bloc rendrait le geste
        // gratuit pour le RÉSOLVEUR mais INVISIBLE au menu dès que le héros a
        // agi — une gratuité pour rien, alors que c'est justement APRÈS avoir
        // agi qu'on veut encore pouvoir se délester (le sac plein qui bloque
        // un ramassage se découvre après avoir frappé, pas avant).
        //
        // ⚠ La leçon d'`actionner_levier` (retiré des créneaux gratuits le
        // 2026-08-24 : un jet RETENTABLE sans coût se relance à l'infini dans
        // le même tour) NE S'APPLIQUE PAS ici : jeter RETIRE une pièce du sac
        // à CHAQUE geste, la suite est donc FINIE et DÉCROISSANTE — se
        // répéter est le but voulu de la règle, pas la faille que le levier
        // avait ouverte.
        $lignesInventaire = $etat !== null && $etat->position_x !== null
            ? $personnage->inventaire()->with('objet')->orderBy('id')->get()
            : collect();

        // ⚠ PAS `=== 'sac'` : une potion ou un parchemin vit en
        // `emplacement === 'consommable'` et serait sinon exclu des deux
        // gestes — précisément le cas d'usage du plan (« la potion du
        // barbare rejoint le magicien »). Chargée UNE seule fois : sert aussi
        // à « échanger » dans le créneau ACTION plus bas.
        $lignesJetables = $lignesInventaire->filter(fn ($l) => $l->objet !== null
            && ! in_array($l->emplacement, Equipement::SLOTS, true));

        if ($lignesJetables->isNotEmpty() && ! $actionInterdite
            && $etat !== null && $etat->position_x !== null) {
            $options[] = [
                'id' => 'jeter',
                'libelle' => 'Jeter un objet — définitif',
                'type' => 'jeter',
                'parametres' => [
                    'objets' => $lignesJetables->map(fn ($l) => [
                        'cle' => "objet:{$l->id}",
                        'inventaire_id' => (int) $l->id,
                        'nom' => $l->objet->nom,
                        // Publiée sur CHAQUE entrée (René 2026-09-17) : le
                        // payload affichait « ×3 » et détruisait un seul
                        // exemplaire — `quantite` est désormais le nombre
                        // réel, et sert aussi de `max` au palier de saisie.
                        'quantite' => (int) $l->quantite,
                    ])->values()->all(),
                ],
            ];
        }

        // ── Créneau ACTION (attaque, relever, désamorçage, sorts, fouille) ──
        if ((! $aAgi || $bonusAttaqueDisponible) && ! $actionInterdite
            && $etat !== null && $etat->position_x !== null) {
            // DUAL-WIELDING (règle de René, 2026-08-12) : un héros peut tenir
            // DEUX armes à une main, et la seconde n'apporte aucun dé — elle
            // apporte un CHOIX. Depuis 2026-09-18 (René, doc contrat-api
            // « Équiper, ranger et attaquer passent au sous-choix ») ce choix
            // n'est plus deux OPTIONS (`attaquer` / `attaquer_secondaire`) : une
            // seule option `attaquer` porte `parametres.armes[]`, une entrée
            // par arme en main — le patron du 2026-09-01 (« l'option ne doit
            // pas ÊTRE l'arme, elle doit PORTER la liste des armes »).
            //
            // ⚠ `cibles` reste PAR ENTRÉE, et c'est mécanique, pas du
            // mimétisme : l'arbalète voit toute la salle, l'épée touche ses
            // quatre voisines, l'épée longue ajoute les diagonales
            // (`attaque_diagonale`). Une liste commune au niveau de l'option
            // offrirait, avec la dague, une cible que seule la hallebarde
            // atteint — `ResolveurTour::resoudreAttaque()` revalide donc la
            // cible contre LES `cibles` DE L'ENTRÉE choisie, pas contre une
            // liste de l'option.
            $armes = $this->equipement->armesEnMain($personnage);
            $mainsNues = $armes === [];
            $armePrincipale = $armes[0]->objet ?? null;

            // Mains nues : une seule passe, sans arme — le Moine frappe ainsi,
            // et n'importe qui peut toujours cogner.
            $lignesArmes = $mainsNues ? [null] : $armes;
            $ciblesParArme = [];
            $armesAAttaquer = [];
            $armesALancer = [];

            foreach ($lignesArmes as $ligneArme) {
                $arme = $ligneArme?->objet;
                $slot = $ligneArme?->emplacement ?? 'arme_principale';
                $cibles = $this->ciblesPourArme($quete, $etat, $personnage, $arme);
                $ciblesParArme[$slot] = $cibles;

                $entree = [
                    'cle' => "arme:{$slot}",
                    'slot' => $slot,
                    'nom' => $arme->nom ?? 'Mains nues',
                    // Dés RÉELS avec CETTE arme (`desAttaqueAvec()`) : la
                    // colonne `des_attaque` du héros ne connaît que la main
                    // droite depuis le dual-wielding, publier la valeur par
                    // arme est ce qui évite à la manette de la re-dériver.
                    'des_attaque' => $this->equipement->desAttaqueAvec($personnage, $ligneArme),
                ];

                if ($cibles['attaquer'] !== []) {
                    $armesAAttaquer[] = [...$entree, 'cibles' => $cibles['attaquer']];
                }

                if ($cibles['lancer'] !== []) {
                    $armesALancer[] = [...$entree, 'cibles' => $cibles['lancer']];
                }
            }

            // ⚠ Le sous-choix ne s'ouvre QUE s'il y a vraiment un choix à
            // faire (doc contrat-api : « attaquer AVEC DEUX ARMES ») — mains
            // nues ou une seule arme en main restent la forme HISTORIQUE,
            // `parametres.arme` + `parametres.cibles` À PLAT sur l'option,
            // sans `cle` à fournir : la « profondeur suit la donnée » vaut
            // aussi d'un niveau à l'autre, pas seulement pour le ciblage.
            // `resoudreAttaque()` lit d'ailleurs déjà les deux formes.
            if (count($armesAAttaquer) === 1) {
                $options[] = [
                    'id' => 'attaquer',
                    'libelle' => 'Attaquer',
                    'type' => 'attaque',
                    'lancer' => false,
                    'parametres' => ['arme' => $armesAAttaquer[0]['slot'], 'cibles' => $armesAAttaquer[0]['cibles']],
                ];
            } elseif ($armesAAttaquer !== []) {
                $options[] = [
                    'id' => 'attaquer',
                    'libelle' => 'Attaquer',
                    'type' => 'attaque',
                    'lancer' => false,
                    'parametres' => ['armes' => $armesAAttaquer],
                ];
            }

            if (count($armesALancer) === 1) {
                $options[] = [
                    'id' => 'lancer',
                    // Une seule arme jetable : pas d'ambiguïté, le libellé la
                    // NOMME directement (comme avant le dual-wielding).
                    'libelle' => "Lancer {$armesALancer[0]['nom']} (perdue)",
                    'type' => 'attaque',
                    'lancer' => true,
                    'parametres' => ['arme' => $armesALancer[0]['slot'], 'cibles' => $armesALancer[0]['cibles']],
                ];
            } elseif ($armesALancer !== []) {
                $options[] = [
                    'id' => 'lancer',
                    // Lancer PERD l'arme : le libellé doit le dire — jamais
                    // habillé par l'IA — sinon le joueur se retrouve les mains
                    // vides sans l'avoir voulu. Générique ici (deux armes
                    // jetables à la fois), le nom de CHACUNE reste sur son
                    // entrée.
                    'libelle' => 'Lancer une arme (perdue)',
                    'type' => 'attaque',
                    'lancer' => true,
                    'parametres' => ['armes' => $armesALancer],
                ];
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
                    'options' => $this->avecCreneaux($options),
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

            // LIBÉRER DES ENTRAVES — *Étreinte des Ronces* (carte *Creeping
            // Grasp*) : « The targeted hero OR ANOTHER ADJACENT HERO can spend
            // an action to destroy the vines, freeing the ensnared hero. »
            //
            // ⚠ Soi-même OU un voisin, et c'est ce qui rend la carte jouable :
            // un héros entravé seul n'est pas condamné, il paie son action ;
            // entouré, un compagnon le tire de là sans que lui perde la sienne.
            // Même forme que `soin_allie` — UNE option qui porte ses cibles,
            // jamais une par voisin (`parametres.cibles` EST la liste blanche).
            $entraves = $quete->etatsPersonnages()
                ->with('personnage')
                ->get()
                ->filter(fn ($e) => $e->personnage !== null
                    && ! $e->tombe
                    && $e->position_x !== null
                    && abs((int) $e->position_x - (int) $etat->position_x) <= 1
                    && abs((int) $e->position_y - (int) $etat->position_y) <= 1
                    && $this->sorts->deplacementInterdit($e->personnage))
                ->values();

            if ($entraves->isNotEmpty()) {
                $options[] = [
                    'id' => 'liberer_entraves',
                    'libelle' => 'Détruire les entraves',
                    'type' => 'liberer_entraves',
                    'parametres' => [
                        'cibles' => $entraves->map(fn ($e) => [
                            'id' => (int) $e->personnage_id,
                            'nom' => (string) $e->personnage->nom,
                            'soi' => (int) $e->personnage_id === (int) $personnage->id,
                        ])->values()->all(),
                    ],
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
            // du tour. Depuis 2026-09-18 (René, doc contrat-api « Équiper,
            // ranger et attaquer passent au sous-choix ») CHAQUE geste est
            // UNE SEULE option portant `parametres.pieces[]`, au patron du
            // 2026-09-01 : « l'option ne doit pas ÊTRE la pièce, elle doit
            // PORTER la liste des pièces ». Mesure qui a motivé la
            // conversion : dix options d'équipement pour UNE SEULE pièce au
            // sac de Grom (une par pièce ET par main).
            //
            // Réutilise l'inventaire réel (`$lignesInventaire`, chargée avant
            // ce bloc pour servir aussi à « jeter », gratuit et hors de cette
            // garde) : « Équiper » les pièces d'équipement du sac, « Ranger »
            // celles portées.
            $portees = $lignesInventaire
                ->filter(fn ($l) => in_array($l->emplacement, Equipement::SLOTS, true))
                ->keyBy('emplacement')
                ->all();

            $piecesAEquiper = [];
            $piecesARanger = [];

            foreach ($lignesInventaire as $ligne) {
                $objet = $ligne->objet;
                if ($objet === null || ! in_array($objet->emplacement, Equipement::SLOTS, true)) {
                    continue;
                }

                if ($ligne->emplacement === 'sac') {
                    // ⚠ `Equipement::detailEquipabilite()` reste le point de
                    // passage unique avec le sac du hub (`/moi`) : il écarte
                    // les slots où monter la pièce ne changerait RIEN (deux
                    // exemplaires identiques — René, 2026-09-04) et nomme ce
                    // que chaque slot restant remplace. L'ENTRÉE, pas
                    // l'option, porte désormais ce filtre.
                    $detail = $this->equipement->detailEquipabilite($objet, $ligne, $portees);

                    if ($detail['slots_utiles'] === []) {
                        continue; // équiper ne changerait rien : pas d'entrée
                    }

                    $piecesAEquiper[] = [
                        'cle' => "piece:{$ligne->id}",
                        'inventaire_id' => (int) $ligne->id,
                        'nom' => $objet->nom,
                        // Une arme à UNE main garde DEUX slots utiles : c'est
                        // ce qui ouvre le troisième niveau côté manette (le
                        // choix de main), exactement comme une entrée qui
                        // porte des `cibles`.
                        'slots' => $detail['slots_utiles'],
                        'remplace' => $detail['remplace'],
                    ];
                } elseif (in_array($ligne->emplacement, Equipement::SLOTS, true)) {
                    $piecesARanger[] = [
                        'cle' => "piece:{$ligne->id}",
                        'inventaire_id' => (int) $ligne->id,
                        'nom' => $objet->nom,
                    ];
                }
            }

            if ($piecesAEquiper !== []) {
                $options[] = [
                    'id' => 'equiper',
                    'libelle' => 'Équiper',
                    'type' => 'equiper',
                    'parametres' => ['pieces' => $piecesAEquiper],
                ];
            }

            if ($piecesARanger !== []) {
                $options[] = [
                    'id' => 'ranger',
                    'libelle' => 'Ranger',
                    'type' => 'desequiper',
                    'parametres' => ['pieces' => $piecesARanger],
                ];
            }

            // ÉCHANGER — désormais la SÉANCE du canon (révision René
            // 2026-09-17), bidirectionnelle et multiple pour UNE action : doc
            // 01 §7 dit « transférer armes/armures ENTRE LES DEUX inventaires,
            // dans la limite des capacités RESPECTIVES ». La première
            // livraison appelait `DonObjet::donner()` un objet à la fois,
            // cible par cible — la forme du don au hub, pas celle de la
            // règle : deux sacs PLEINS qui échangent deux armures est légal
            // au canon, et pourtant AUCUN ordre d'application ne passerait un
            // contrôle pièce par pièce (le premier mouvement échoue toujours,
            // quel que soit le sens). `SeanceEchange::resoudre()` juge donc
            // le NET des deux sacs une seule fois côté résolveur ; le menu ne
            // fait plus que présenter les deux sacs côte à côte, PAR ALLIÉ
            // (`parametres.allies[]`, `cle: "heros:{id}"`) — le patron
            // d'adjacence reste celui de `relever` ci-dessus, Manhattan = 1.
            //
            // ⚠ `encombrant` est publié PAR PIÈCE (même filtre que
            // `CapaciteSac::occupation()` : seul l'emplacement `sac` compte,
            // un consommable jamais) pour que la manette affiche un total qui
            // bouge en direct SANS re-dériver cette règle — elle additionne
            // des entiers, elle ne juge jamais elle-même ce qui est
            // encombrant. La validation à la soumission reste entièrement
            // côté serveur (`SeanceEchange`) : cet aperçu peut se tromper.
            $voisins = $quete->etatsPersonnages()
                ->where('tombe', false)
                ->where('personnage_id', '!=', $personnage->id)
                ->with('personnage')
                ->get()
                ->filter(fn ($e) => $e->personnage !== null && $e->position_x !== null
                    && abs((int) $e->position_x - (int) $etat->position_x)
                        + abs((int) $e->position_y - (int) $etat->position_y) === 1);

            if ($voisins->isNotEmpty()) {
                $sacPublie = fn ($lignes) => $lignes->map(fn ($l) => [
                    'inventaire_id' => (int) $l->id,
                    'nom' => $l->objet->nom,
                    'quantite' => (int) $l->quantite,
                    'encombrant' => $l->emplacement === 'sac',
                ])->values()->all();

                $monSac = $sacPublie($lignesJetables);
                $maCapacite = [
                    'occupation' => CapaciteSac::occupation($personnage),
                    'max' => CapaciteSac::pour($personnage),
                ];

                $entreesEchange = $voisins->map(function ($e) use ($sacPublie, $monSac, $maCapacite) {
                    $sonSacLignes = $e->personnage->inventaire()->with('objet')->orderBy('id')->get()
                        ->filter(fn ($l) => $l->objet !== null && ! in_array($l->emplacement, Equipement::SLOTS, true));

                    return [
                        'cle' => "heros:{$e->personnage_id}",
                        'nom' => $e->personnage->nom,
                        'mon_sac' => $monSac,
                        'son_sac' => $sacPublie($sonSacLignes),
                        'ma_capacite' => $maCapacite,
                        'sa_capacite' => [
                            'occupation' => CapaciteSac::occupation($e->personnage),
                            'max' => CapaciteSac::pour($e->personnage),
                        ],
                    ];
                })->values()->all();

                $options[] = [
                    'id' => 'echanger',
                    'libelle' => 'Échanger avec un allié adjacent',
                    'type' => 'echanger',
                    'parametres' => ['allies' => $entreesEchange],
                ];
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

        // OBJETS — option UNIQUE « Utiliser un objet », HORS du bloc `! $aAgi`
        // ci-dessus (corrigé 2026-09-01). Les consommables gratuits en
        // héritaient et disparaissaient dès que le héros avait agi, ce qui
        // contredisait leur gratuité même : une potion se boit après avoir
        // frappé. La liste, elle, sait se restreindre au gratuit dans ce cas.
        if ($etat !== null && ! $actionInterdite
            && $etat->position_x !== null && $quete->carte !== null) {
            foreach ($this->objetsDeMateriel(
                $quete, $personnage, $etat,
                (int) $etat->position_x, (int) $etat->position_y, $aAgi,
            ) as $option) {
                $options[] = $option;
            }
        }

        // ⚠ PAS de garde `action_interdite` sur ce bloc entier : ouvrir une
        // porte et actionner un levier restent permis à l'ÉVANESCENT — « il ne
        // peut que bouger et ouvrir des portes », c'est le texte de la carte et
        // tout l'intérêt du sort. Seules les FOUILLES sont gardées, plus bas.
        if ($etat !== null && $etat->position_x !== null && $quete->carte !== null) {
            $px = (int) $etat->position_x;
            $py = (int) $etat->position_y;

            // ⚠ OUVRIR UNE PORTE EST HORS DU VERROU `! $aAgi`, et ce n'est pas
            // un détail : c'est une INTERACTION LIBRE (E2) qui ne consomme aucun
            // créneau — `ResolveurTour::creneauOption()` la range dans
            // `interaction`, et son propre commentaire promettait déjà « on peut
            // reprendre son déplacement juste après ».
            //
            // ⚠ Elle était pourtant enfermée dans le même `if (! $aAgi)` que les
            // fouilles et les jets, si bien qu'un héros qui ATTAQUAIT D'ABORD ne
            // voyait plus la porte devant lui — le moteur l'aurait acceptée, le
            // menu ne la proposait plus, et la manette refuse ce qui n'est pas
            // dans le dernier menu. Signalé en partie réelle le 2026-09-04.
            // C'est exactement le défaut de la Potion d'héroïsme, dont le second
            // coup dormait derrière le même verrou : un bloc entier gardé sur un
            // créneau que la moitié de son contenu ne consomme pas.
            //
            // Porte close adjacente : simplement fermée → ouverture libre (E2) ;
            // verrouillée à clé → seulement avec la clé.
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
        }

        // Tout ce qui suit COÛTE l'action — fouilles, leviers (depuis le
        // 2026-08-24), destruction de mobilier, poussée, épreuves — et reste
        // donc bien derrière `! $aAgi`.
        if (! $aAgi && $etat !== null) {
            if ($etat->position_x !== null && $quete->carte !== null) {
                $px = (int) $etat->position_x;
                $py = (int) $etat->position_y;

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

                // MUR DE GLACE (Ice Wall, plan glace phase 2) — l'option qui
                // manquait à `MoteurDread::endommagerMurDeGlace()` : écrite,
                // testée directement, mais aucune case n'était atteignable
                // depuis le menu (dette nommée par l'agent qui l'a écrite).
                // Couche DÉDIÉE `carte.grille['glace']` — jamais le catalogue
                // `terrains` : cette pose est un effet de sort en cours de
                // quête, posée et entretenue par `MoteurDread`, pas une entrée
                // du tirage statique de `AssembleurCarte::placerTerrains()`.
                //
                // ⚠ PAS de jet de Body (le mur n'oppose aucune défense
                // d'attribut) : `ResolveurTour::resoudreBriserGlace()` roule un
                // dé de COMBAT et n'accepte qu'un crâne, exactement le texte
                // de la carte — le précédent le plus proche pour la
                // CONSTRUCTION de l'option est la destruction de mobilier
                // juste au-dessus, pas son jet.
                foreach ($this->glaceAdjacente($quete->carte, $px, $py) as $cellule) {
                    $options[] = [
                        'id' => "briser_glace_{$cellule['x']}_{$cellule['y']}",
                        'libelle' => 'Frapper le mur de glace',
                        'type' => 'briser_glace',
                        'parametres' => ['x' => $cellule['x'], 'y' => $cellule['y']],
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
                    // ⚠ On ne propose pas une épreuve dont on SAIT qu'elle ne
                    // peut rien rendre à ce héros : la tentative coûte le
                    // créneau d'action ET se consomme pour de bon. Voir
                    // `MoteurEpreuves::offre()` pour les trois mécaniques
                    // concernées et pourquoi le résolveur, lui, ne refuse pas.
                    if (! $this->epreuves->offre($epreuve, $quete, $personnage)) {
                        continue;
                    }

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

            }

            if ($actionInterdite) {
                // Paralysé : ni fouille de zone, ni trésor, ni technique du
                // Moine. Le déplacement et les portes, eux, ont déjà été
                // traités au-dessus.
                $options[] = ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'];

                return ['situation' => 'Vous ne pouvez pas agir ce tour.', 'options' => $this->avecCreneaux($options)];
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

        // ⚠ Ni l'une ni l'autre tant qu'un VOTE est ouvert : les deux en
        // ouvrent un, et le résolveur refuse le second par un 422 « Un vote est
        // déjà en cours ». Constaté en partie réelle le 2026-08-30 — le menu
        // reproposait « Quitter le donjon » à chaque tour pendant que le vote
        // qu'il venait d'ouvrir attendait des bulletins, et le joueur se
        // heurtait au refus dix-sept fois de suite. C'est l'anti-patron que le
        // projet traque partout : le menu ne doit jamais offrir ce que le
        // résolveur refusera.
        $voteOuvert = VoteGroupe::enCours($quete->groupe_id);

        if (! $aJoue && $peutSortir && ! $voteOuvert) {
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
        if (! $aJoue && ! $voteOuvert) {
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
            'options' => $this->avecCreneaux($options),
        ];
    }
}
