<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Des\LanceurDes;
use App\Engine\TypeFigurine;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * LE MJ JOUE UN HÉROS — la couture UNIQUE.
 *
 * Trois cartes de Zargon prennent la main d'un héros : *Possess* (High Mage, Wizards of Morcar :
 * « Zargon will move this figure on its next turn. The affected figure may not attack or cast
 * spells »), *Dominate* (Sorcier du Dread, *Against the Ogre Horde* p. 10 : « Zargon […] may move,
 * fight, cast spells, and perform any other action as the Dominated hero (including attacking other
 * heroes) ») et la *Malédiction du loup-garou* du Mage du Miroir (« Zargon controls them as a monster
 * for one turn », F7539 p. 10 — jouée par {@see MaledictionLoupGarou}). Elles ne diffèrent que par CE QUE
 * LE MJ A LE DROIT DE FAIRE ({@see ControleMj}) — jamais par la façon de marcher, de traverser un piège,
 * de frapper un autre héros. Cette classe est cette façon, écrite une seule fois ; chaque carte n'apporte
 * que son profil et SON MOMENT (Possession : le prochain tour du héros ; Domination : tout de suite, à la
 * phase du Sorcier ; le loup : la phase des monstres, « on Zargon's next turn »).
 *
 * L'ORDRE JOUÉ, comme le tour d'un héros — un déplacement, une action :
 *  1. si le profil le permet et qu'un sort offensif du répertoire du héros est disponible, avec un
 *     héros en vue : le sort EST l'action (le héros ne s'approche pas pour le lancer) ;
 *  2. sinon il AVANCE vers sa cible (le monstre le plus proche, ou l'autre héros le plus proche) de la
 *     distance que le profil dit, les pièges de la route se déclenchant (mouvement forcé,
 *     `MoteurPieges::repousserFigure()`). Un TIREUR (`ControleMj::$tir`, arme qui tire) s'arrête sur la
 *     PREMIÈRE case du trajet d'où il voit sa cible hors contact, et tire de là ;
 *  3. au contact d'un autre héros, et si le profil l'autorise, il le FRAPPE à l'arme — à MAINS NUES si
 *     l'arme refuse le contact (`Objet::interditAuContact()`) ; à distance, s'il tire et n'est pas au contact.
 *
 * ⚠ Le tir et le coup au contact lisent les MÊMES prédicats que le héros lui-même : `Objet::interditAuContact()`,
 * `portee: distance`, la Faveur « Deadeye » (`FaveursHopekins::figuresBloquentPour()`). Une arme `jetable` n'est
 * JAMAIS lancée par Zargon : c'est un choix du joueur, qui la perd ; elle ne tire donc pas.
 *
 * ⚠ Ce que Zargon ne fait PAS avec un héros dominé, écrit plutôt que tu : il ne se sert d'aucun objet ni
 * d'aucune capacité de classe, et le coup est celui de `personnage.des_attaque` — la colonne de l'équipement,
 * plafonnée par l'état de choc et par les conditions (`Apeuré`) — sans les talents ni les tags de maîtrise de
 * `ResolveurTour::frapper()`. `frapper()` ne prend qu'un MONSTRE : le coup entre héros garde son point de
 * passage ici (`frapperUnHeros()`), qui lit les mêmes prédicats, jamais une copie de son corps.
 * « Perform any other action » n'a ici aucun lecteur : un MJ qui choisirait entre vingt options sans
 * que personne ne le lui demande est la décision que ce projet refuse de confier à autre chose
 * qu'une politique écrite.
 *
 * ÉTENDRE : un nouveau client = un constructeur nommé de {@see ControleMj} + l'endroit qui décide DU
 * MOMENT. Si sa règle exige une capacité que le profil ne dit pas (jouer avec les stats d'un MONSTRE),
 * on ajoute le champ ET la branche de {@see self::jouer()} qui le lit — pas avant.
 */
final class HerosJoueParLeMj
{
    public function __construct(
        private readonly LanceurDes $des,
        private readonly MoteurSorts $sorts,
        private readonly MoteurDegats $degats,
        private readonly MoteurDread $dread,
        private readonly Equipement $equipement,
        private readonly FaveursHopekins $faveurs,
    ) {}

    /**
     * Joue le tour du héros pour le MJ, et rend la LISTE des actions jouées, dans l'ordre où elles se
     * sont produites. Jamais vide : un héros qui n'a rien pu faire rend une action `…_deplacement` sans
     * déplacement, la décision du moteur.
     *
     * ⚠ Cette méthode NE journalise PAS : c'est l'APPELANT qui porte l'événement (Possession journalise
     * son déplacement, la Domination range les actions DANS le payload du sort). Journalisées ici ET
     * imbriquées par l'appelant, elles se liraient deux fois au rejeu du fil.
     *
     * @return list<array<string, mixed>>
     */
    public function jouer(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        ControleMj $controle,
    ): array {
        $depart = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
        $victimes = $controle->cible === ControleMj::CIBLE_HEROS ? $this->herosAtteignables($quete, $personnage) : collect();
        // Les stats de FORME (le loup-garou joue avec celles du Loup géant) : lues une fois, au catalogue.
        $forme = $controle->forme !== null ? Monstre::where('nom_base', $controle->forme)->firstOrFail() : null;

        // 1. Le sort : l'action du tour, sans mouvement.
        if ($controle->sorts && $victimes->isNotEmpty()) {
            $sortLance = $this->lancerUnSort($quete, $controle, $personnage, $etat, $victimes);

            if ($sortLance !== null) {
                return [$sortLance];
            }
        }

        // 2. La marche.
        $but = $controle->cible === ControleMj::CIBLE_HEROS
            ? $this->plusProche($victimes->map(fn (EtatPersonnageQuete $v) => ['x' => (int) $v->position_x, 'y' => (int) $v->position_y, 'nom' => $v->personnage->nom, 'obj' => $v]), $depart)
            : $this->monstreLePlusProche($quete, $depart);

        $grille = FabriqueGrille::pour($quete, exceptPersonnageId: $personnage->id);
        $chemin = [];

        // Un TIREUR : un profil qui en a le droit (`ControleMj::$tir`), une arme qui tire (`tire()`), et pas de
        // forme sans arme (le loup-garou mord, il ne tire pas).
        $tireur = $controle->tir && $forme === null && $this->tire($this->armeEnMain($personnage));

        // Déjà AU CONTACT de sa cible : il ne marche pas. Sans cette garde, `cheminVersCaseAdjacente()` le faisait
        // CONTOURNER la cible jusqu'à une autre case voisine — un tireur au contact s'éloignait de deux pas pour
        // tirer de loin, alors que son arme refuse le contact (`inutilisable_adjacent`).
        if ($but !== null && ! $this->auContact($controle, $etat, $victimes, $but)) {
            $complet = $this->dread->cheminVersCaseAdjacente($grille, $depart['x'], $depart['y'], $but['x'], $but['y']) ?? [];
            $chemin = array_slice($complet, 0, $this->pas($controle, $personnage, $etat, $forme));

            // Le tireur s'arrête sur la PREMIÈRE case du trajet d'où il voit sa cible hors contact : il tire de là,
            // il ne marche pas jusqu'au corps à corps.
            if ($tireur) {
                $chemin = $this->jusquAuTir($controle, $grille, $personnage, $victimes, $depart, $chemin);
            }
        }

        $arrivee = $depart;
        $piegesCroises = [];

        if ($chemin !== [] && $quete->carte !== null) {
            if ($controle->pieges) {
                $piege = app(MoteurPieges::class)->repousserFigure($groupe, $quete->carte, $personnage, $etat, $chemin);
                $arrivee = $piege['arret'] ?? end($chemin);
                $piegesCroises = $piege['declenchements'];
            } else {
                // Le loup ne déclenche rien : il arrive au bout de sa route, sans s'arrêter sur un piège.
                $arrivee = end($chemin);
            }

            $etat->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);
        }

        $deplacement = [
            ...$this->enTete($controle, 'deplacement'),
            'personnage_id' => (int) $personnage->id,
            'personnage' => $personnage->nom,
            'de' => $depart,
            'vers' => ['x' => (int) $arrivee['x'], 'y' => (int) $arrivee['y']],
            ($controle->cible === ControleMj::CIBLE_HEROS ? 'vers_heros' : 'vers_monstre') => $but['nom'] ?? null,
            // Les pièges que cette marche forcée a croisés : ils n'étaient que journalisés
            // à part, donc absents du fil en direct (bâti sur le RÉSULTAT).
            'pieges_declenches' => $piegesCroises,
        ];

        // 3. Le coup. Au contact d'un autre héros — contact orthogonal, sauf pour un profil qui attaque en
        // diagonale (le loup-garou) : `Tchebychev`, la même distinction que `ResolveurTour::heroAuContact()` —,
        // ou, pour un TIREUR qui n'est pas au contact, à distance.
        $etat->refresh();
        $coup = null;

        if ($controle->attaque && ! $etat->tombe) {
            $adjacent = $victimes
                ->map(fn (EtatPersonnageQuete $v) => $v->fresh())
                ->filter(fn (?EtatPersonnageQuete $v) => $v !== null && ! $v->tombe && $v->position_x !== null
                    && $this->enContact($controle, (int) $etat->position_x, (int) $etat->position_y, (int) $v->position_x, (int) $v->position_y))
                ->sortBy(fn (EtatPersonnageQuete $v) => (int) $v->personnage->pv_body)
                ->first();

            if ($adjacent !== null) {
                $coup = [
                    ...$this->enTete($controle, 'attaque'),
                    ...$this->frapperUnHeros($personnage, $adjacent->personnage, $adjacent, ['controle' => $controle->nom], $forme),
                ];
            } elseif ($tireur) {
                $tir = $this->cibleDeTir(
                    $controle,
                    FabriqueGrille::pour($quete, exceptPersonnageId: $personnage->id),
                    $personnage,
                    ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y],
                    $victimes,
                );

                if ($tir !== null) {
                    $coup = [
                        ...$this->enTete($controle, 'attaque'),
                        ...$this->frapperUnHeros($personnage, $tir->personnage, $tir, ['controle' => $controle->nom], null, tirADistance: true),
                    ];
                }
            }
        }

        // Un TIR sans marche n'a pas de trajet à raconter : le fil et la table ne disent que le tir. Dans
        // tous les autres cas — marche, coup au contact, rien du tout — le déplacement est publié, comme avant.
        $sansMarche = (int) $arrivee['x'] === $depart['x'] && (int) $arrivee['y'] === $depart['y'];
        $tireSansMarche = $coup !== null && ($coup['portee'] ?? null) === 'distance' && $sansMarche;

        return [...($tireSansMarche ? [] : [$deplacement]), ...($coup !== null ? [$coup] : [])];
    }

    /**
     * Le héros est-il DÉJÀ au contact de sa cible ? Pour un héros dominé, la cible est un des autres héros debout ;
     * pour une Possession, le monstre le plus proche (`$but`).
     *
     * @param  Collection<int, EtatPersonnageQuete>  $victimes
     * @param  array{x: int, y: int}  $but
     */
    private function auContact(ControleMj $controle, EtatPersonnageQuete $etat, Collection $victimes, array $but): bool
    {
        $ax = (int) $etat->position_x;
        $ay = (int) $etat->position_y;

        if ($controle->cible === ControleMj::CIBLE_MONSTRE) {
            return $this->enContact($controle, $ax, $ay, $but['x'], $but['y']);
        }

        return $victimes->contains(fn (EtatPersonnageQuete $v) => $v->position_x !== null
            && $this->enContact($controle, $ax, $ay, (int) $v->position_x, (int) $v->position_y));
    }

    /**
     * Le contact d'un profil entre deux cases : orthogonal, sauf le loup-garou (« as a giant wolf », `diagonales`). La
     * même distinction que `ResolveurTour::heroAuContact()` — UN seul prédicat pour la garde de marche, le coup au
     * contact et le choix de la cible de tir, et c'est le point de passage `Grille::sontAdjacentes()`.
     */
    private function enContact(ControleMj $controle, int $ax, int $ay, int $bx, int $by): bool
    {
        return Grille::sontAdjacentes($ax, $ay, $bx, $by, $controle->diagonales);
    }

    /** L'arme que le héros tient dans la main droite (la seule qu'un coup de MJ emploie), ou `null` = mains nues. */
    private function armeEnMain(Personnage $personnage): ?Objet
    {
        return $personnage->inventaire()->where('emplacement', 'arme_principale')->with('objet')->first()?->objet;
    }

    /**
     * Une arme TIRE si elle porte à distance ET n'est pas jetable. Une arme `jetable` se lance, et Zargon ne lance
     * jamais : c'est le joueur qui la lance, et qui la perd. La Dague de jet magique (`portee: distance` ET `jetable`)
     * ne tire donc pas non plus, sous la main de Zargon.
     */
    private function tire(?Objet $arme): bool
    {
        return $arme !== null
            && ($arme->effet['portee'] ?? null) === 'distance'
            && ! (bool) ($arme->effet['jetable'] ?? false);
    }

    /**
     * La cible qu'un tir partant de `$case` atteint : hors contact (un voisin orthogonal se frappe, il ne se vise
     * pas) et en ligne de vue — figures comprises, sauf la Faveur « Deadeye » (`figuresBloquentPour()`, le point
     * unique que lit aussi le tir du héros). La plus faible de Body d'abord, comme le coup au contact.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $victimes
     * @param  array{x: int, y: int}  $case
     */
    private function cibleDeTir(ControleMj $controle, Grille $grille, Personnage $personnage, array $case, Collection $victimes): ?EtatPersonnageQuete
    {
        $figuresBloquent = $this->faveurs->figuresBloquentPour($personnage);

        return $victimes
            ->filter(fn (EtatPersonnageQuete $v) => ! $this->enContact($controle, $case['x'], $case['y'], (int) $v->position_x, (int) $v->position_y)
                && $grille->ligneDeVue($case['x'], $case['y'], (int) $v->position_x, (int) $v->position_y, figuresBloquent: $figuresBloquent))
            ->sortBy(fn (EtatPersonnageQuete $v) => (int) $v->personnage->pv_body)
            ->first();
    }

    /**
     * La marche d'un TIREUR s'arrête sur la première case du trajet (le départ compris) d'où une cible se tire.
     * Sans cible en vue en chemin, le trajet reste entier : il s'approche jusqu'au contact, et frappe alors à
     * mains nues si son arme refuse le contact (`Objet::interditAuContact()`) — il ne recule pas pour tirer.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $victimes
     * @param  array{x: int, y: int}  $depart
     * @param  list<array{x: int, y: int}>  $chemin
     * @return list<array{x: int, y: int}>
     */
    private function jusquAuTir(ControleMj $controle, Grille $grille, Personnage $personnage, Collection $victimes, array $depart, array $chemin): array
    {
        foreach ([$depart, ...$chemin] as $rang => $case) {
            if ($this->cibleDeTir($controle, $grille, $personnage, $case, $victimes) !== null) {
                return array_slice($chemin, 0, $rang);
            }
        }

        return $chemin;
    }

    /**
     * UN HÉROS FRAPPE UN AUTRE HÉROS à l'arme — le coup que le MJ porte avec un héros dont il a la main
     * (*Commandement*, *Dominate*). Point de passage unique : le *Commandement* avait sa propre copie
     * de ce calcul, que cette méthode remplace.
     *
     * Les dés d'attaque sont ceux de la colonne `des_attaque` (équipement, classe), ramenés à 1 par
     * l'ÉTAT DE CHOC et plafonnés par les conditions (`Apeuré` : « may ONLY USE 1 Attack die ») —
     * deux lectures que `ResolveurTour::frapper()` fait aussi, au moment du jet. Le défenseur lance
     * sa défense ordinaire (`desDefenseHeros()`, choc et plafonds compris) ; être attaqué réveille.
     *
     * `$tirADistance` : le coup est un TIR (arme `portee: distance`, cible hors contact en ligne de vue — le
     * caller a déjà décidé, `HerosJoueParLeMj::jouer()`). Le payload porte alors `portee: "distance"`. Hors tir,
     * un coup au contact d'une arme qui refuse le contact se donne à mains nues (voir plus haut).
     *
     * Rend le corps du payload SANS `type` : chaque appelant pose le sien, en littéral.
     *
     * @param  array<string, mixed>  $contexte  clés ajoutées au contexte de dégâts (écouteurs de `HerosVaSubirDegats`)
     * @param  Monstre|null  $forme  la FORME que l'agresseur emprunte (le loup-garou : le Loup géant). Ses dés
     *                               d'attaque remplacent ceux du héros — jamais l'état de choc ni le plafond de
     *                               *Apeuré*, qui sont ceux d'un HÉROS — et le coup compte comme un coup de
     *                               MONSTRE (source `attaque_monstre`), non comme un sort du Dread.
     * @return array<string, mixed>
     */
    public function frapperUnHeros(
        Personnage $agresseur,
        Personnage $victime,
        EtatPersonnageQuete $etatVictime,
        array $contexte = [],
        ?Monstre $forme = null,
        bool $tirADistance = false,
    ): array {
        if ($forme !== null) {
            // « Désigné » (*Dreadlights*) : toute créature en gagne un contre le héros qui le porte.
            $des = max(0, (int) $forme->attaque + $this->dread->bonusAttaqueContre($victime));
        } else {
            // À MAINS NUES au contact d'une arme qui refuse le contact (`inutilisable_adjacent`) : le profil
            // « mains nues » de `Equipement::desAttaqueAvec($p, null)`, celui que le héros lui-même prend — la
            // même décision que `ResolveurTour::frapper()`, lue sur le même prédicat, `Objet::interditAuContact()`.
            // Un tir garde son arme : `des_attaque` est alors le dé de l'arme tenue.
            $mainsNues = ! $tirADistance && ($this->armeEnMain($agresseur)?->interditAuContact() ?? false);
            $des = $agresseur->estEnChoc()
                ? 1
                : max(0, $mainsNues ? $this->equipement->desAttaqueAvec($agresseur, null) : (int) $agresseur->des_attaque);
            $plafond = $this->dread->plafondDesAttaque($agresseur);

            if ($plafond !== null) {
                $des = min($des, $plafond);
            }
        }

        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: $des,
            desDefense: $this->sorts->desDefenseHeros($victime),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $victime->pv_body,
        );

        $subis = $this->degats->infligerAHeros(
            $victime, $resultat->degats,
            $forme !== null ? MoteurDegats::SOURCE_ATTAQUE_MONSTRE : MoteurDegats::SOURCE_SORT_DREAD,
            ['par' => $agresseur->nom, ...$contexte],
        );
        $this->sorts->reveillerHeros($victime);

        if ((int) $victime->pv_body === 0 && $subis > 0) {
            $etatVictime->update(['tombe' => true]);
        }

        return [
            'personnage_id' => (int) $agresseur->id,
            'personnage' => $agresseur->nom,
            'cible' => ['personnage_id' => $victime->id, 'nom' => $victime->nom],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $subis,
            'pv_body_apres' => (int) $victime->pv_body,
            'cible_tombee' => (int) $victime->pv_body === 0 && $subis > 0,
            // Un tir se lit comme celui d'un héros (`JournalCombat::estUnTir()`) : le fil dit « tire sur ».
            ...($tirADistance ? ['portee' => 'distance'] : []),
            ...$resultat->pourJournal(),
        ];
    }

    /**
     * Les types d'action de CHAQUE profil, en LITTÉRAL — le registre `JournalCombat::TYPES` est
     * confronté aux sources par `'type' => '…'`, et un type bâti par concaténation lui échapperait.
     *
     * @return array{type: string}
     */
    private function enTete(ControleMj $controle, string $etape): array
    {
        return match ([$controle->nom, $etape]) {
            ['possession', 'deplacement'] => ['type' => 'possession_deplacement'],
            ['domination', 'deplacement'] => ['type' => 'domination_deplacement'],
            ['domination', 'attaque'] => ['type' => 'domination_attaque'],
            ['domination', 'sort'] => ['type' => 'domination_sort'],
            ['loup_garou', 'deplacement'] => ['type' => 'loup_deplacement'],
            ['loup_garou', 'attaque'] => ['type' => 'loup_attaque'],
            default => throw new \LogicException("Le profil « {$controle->nom} » ne joue pas l'étape « {$etape} »."),
        };
    }

    /** Combien de cases le MJ fait avancer ce héros (au moins une — le héros qui n'a jamais lancé de dé marche de sa base). */
    private function pas(ControleMj $controle, Personnage $personnage, EtatPersonnageQuete $etat, ?Monstre $forme = null): int
    {
        $base = max(1, (int) $personnage->deplacement_base);

        if ($controle->deplacement === ControleMj::DEPLACEMENT_BASE) {
            return $base;
        }

        if ($controle->deplacement === ControleMj::DEPLACEMENT_MONSTRE) {
            // Le déplacement du catalogue du monstre dont il prend la forme (Loup géant : 9), jamais celui du héros.
            if ($forme === null) {
                throw new \LogicException("Le profil « {$controle->nom} » marche à la vitesse d'une forme, sans forme.");
            }

            return max(1, (int) $forme->deplacement);
        }

        // « Full movement » : celui que le héros a LANCÉ pour son propre tour de ce round
        // (`deplacement_tour`, le total mémorisé par `MenuMoteur::deplacementDuTour()` — armure, bottes,
        // choc et menace déjà décidés). Le recalculer ici serait une seconde copie de cette règle.
        return max(1, (int) ($etat->deplacement_tour ?? $base));
    }

    /**
     * Les AUTRES héros que ce héros peut frapper : debout, pas cachés (« inattaquable » : Voile de
     * Brume, Invisibilité) — le même filtre que celui des monstres (`phaseMonstres()`).
     *
     * @return Collection<int, EtatPersonnageQuete>
     */
    private function herosAtteignables(Quete $quete, Personnage $personnage): Collection
    {
        return $quete->etatsPersonnages()->where('tombe', false)->with('personnage')->get()
            ->filter(fn (EtatPersonnageQuete $e) => (int) $e->personnage_id !== (int) $personnage->id
                && $e->personnage !== null
                && $e->position_x !== null
                && ! $this->sorts->estInattaquable($e->personnage))
            ->values();
    }

    /**
     * La cible la plus proche (Manhattan, comme le choix d'allié du Commandement), à égalité la première
     * dans l'ordre reçu.
     *
     * @param  Collection<int, array<string, mixed>>  $cibles
     * @param  array{x: int, y: int}  $depart
     * @return array<string, mixed>|null
     */
    private function plusProche(Collection $cibles, array $depart): ?array
    {
        return $cibles
            ->sortBy(fn (array $c) => abs($c['x'] - $depart['x']) + abs($c['y'] - $depart['y']))
            ->first();
    }

    /**
     * Le monstre actif et révélé le plus proche (Possession : « il avance vers le monstre actif le
     * plus proche et s'arrête à son contact »).
     *
     * @param  array{x: int, y: int}  $depart
     * @return array{x: int, y: int, nom: string}|null
     */
    private function monstreLePlusProche(Quete $quete, array $depart): ?array
    {
        $monstre = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)->orderBy('id')->get()
            ->filter(fn (InstanceMonstre $m) => $m->position_x !== null)
            ->sortBy(fn (InstanceMonstre $m) => abs((int) $m->position_x - $depart['x']) + abs((int) $m->position_y - $depart['y']))
            ->first();

        return $monstre === null ? null : [
            'x' => (int) $monstre->position_x,
            'y' => (int) $monstre->position_y,
            'nom' => $monstre->nomAffiche(),
        ];
    }

    /**
     * Le sort offensif : le premier sort de dégâts à cible UNIQUE que le héros connaît encore
     * (`personnage_sorts.disponible`), jeté sur l'autre héros en vue qui a le moins de PV de Body — celui
     * qu'on achève. Rend `null` quand rien ne s'y prête (le héros n'est pas lanceur, ses sorts sont épuisés,
     * il est aveuglé, personne n'est en vue) : le MJ retombe sur la marche et le coup.
     *
     * Un sort lancé sous contrôle est RÉELLEMENT dépensé (« chaque sort est lançable une fois par quête »)
     * et résolu par `ResolveurTour::lancerSortSousControleMj()` — la même résolution que si le joueur l'avait
     * lancé, y compris le tir ami que les dégâts d'un sort de héros sur un autre héros sont déjà.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $victimes
     * @return array<string, mixed>|null
     */
    private function lancerUnSort(
        Quete $quete,
        ControleMj $controle,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        Collection $victimes,
    ): ?array {
        if ($this->sorts->sortsInterdits($personnage)) {
            return null; // Grésil aveuglant : « may not […] cast spells »
        }

        $sort = $personnage->sorts()->orderBy('sorts.id')->get()
            ->first(fn (Sort $s) => (bool) $s->pivot->disponible
                && $s->type === 'degats'
                && data_get($s->effet, 'zone') === null
                && data_get($s->effet, 'rayon') === null
                && data_get($s->effet, 'ouvre_porte') === null);

        if ($sort === null) {
            return null;
        }

        $grille = FabriqueGrille::pour($quete, exceptPersonnageId: $personnage->id);

        $victime = $victimes
            ->filter(fn (EtatPersonnageQuete $v) => $grille->ligneDeVue(
                (int) $etat->position_x, (int) $etat->position_y, (int) $v->position_x, (int) $v->position_y,
                figuresBloquent: true,
            ))
            ->sortBy(fn (EtatPersonnageQuete $v) => (int) $v->personnage->pv_body)
            ->first();

        if ($victime === null) {
            return null;
        }

        try {
            $resultat = app(ResolveurTour::class)->lancerSortSousControleMj($quete, $personnage, $etat, $sort, $victime->personnage);
        } catch (ValidationException) {
            return null; // le résolveur refuse ce lancer (ligne de vue plus stricte…) : le MJ marche et frappe
        }

        $payload = [
            ...$this->enTete($controle, 'sort'),
            'personnage_id' => (int) $personnage->id,
            'personnage' => $personnage->nom,
            'sort' => $sort->nom,
            'cible' => ['personnage_id' => $victime->personnage->id, 'nom' => $victime->personnage->nom],
            'resultat' => $resultat,
        ];

        return $payload;
    }
}
