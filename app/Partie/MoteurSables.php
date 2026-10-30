<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Terrain;
use App\Support\Journal;
use Illuminate\Validation\ValidationException;

/**
 * SABLES MOUVANTS — *The Mage of the Mirror* (F7539), quête 8 « The Gathering
 * Storm », note C, p. 29, relu au livret :
 *
 * « Dividing the room is a quicksand pit. To reach the other side of the
 * quicksand pit, a hero must stand adjacent to it and try to jump over the
 * quicksand. The hero rolls one combat die in the attempt. If a black shield is
 * rolled, the hero successfully lands on the square directly across from the
 * square they left. Any other result means the hero lands in the quicksand and
 * starts to sink. To avoid death, tell the hero to immediately discard any two
 * items (weapons, armor, potions, scrolls, etc.). This ends the hero's turn. On
 * the hero's next turn, the hero climbs out of the quicksand onto the square
 * across from the square they left. This ends the hero's turn. »
 *
 * UN TERRAIN du catalogue (`terrains`, `effet.saut_par_dessus` / `saut_reussi_sur`
 * / `enlise`, vocabulaire fermé {@see \App\Engine\MotsClesTerrain}), jamais une
 * couche à part : c'est `FabriqueGrille::pour()` — la seule boucle du terrain —
 * qui le fait bloquer le mouvement (colonne `bloque_mouvement`) et interdire
 * l'arrêt ; ce fichier n'ajoute que les RÈGLES qu'aucune colonne ne porte.
 *
 * ── Trois temps, tous DURABLES (`etat_personnage_quete.enlise`, jamais le cache)
 *
 *  1. LE SAUT (`resoudreSaut`) — option de menu `saut_sables`, offerte seulement
 *     depuis une case voisine du sable, avec une case de réception LIBRE en face
 *     (`sauts()` : le menu et le résolveur lisent la MÊME liste, le client ne
 *     recalcule rien). Un dé de combat : bouclier noir = le héros atterrit sur la
 *     case d'en face ; tout autre résultat = il s'enlise.
 *  2. LA DÉFAUSSE (`resoudreDefausse`) — phase `defausse` : le menu est FORCÉ, une
 *     seule option, « abandonne un objet », répétée jusqu'à deux. Le héros
 *     CHOISIT (livret : « any two items » — René, 2026-10-15 ; `reference/18` et
 *     le plan écrivaient « au hasard », c'était faux). Dernier objet jeté : le
 *     tour s'achève. À court d'objets, il ne peut pas « avoid death » : il TOMBE
 *     à 0 PV (la mort définitive est refusée dans ce jeu — René, 2026-10-15).
 *  3. LA SORTIE (`resoudreSortie`) — phase `sables` : à son tour suivant, le seul
 *     choix du héros est de se hisser sur la case d'en face. Le tour s'achève.
 *
 * ── Ce que le livret ne dit pas, tranché ici (à relire avant de « corriger »)
 *
 *  - COÛT du saut : 3 points de déplacement (2 cases de sable + la réception),
 *    par analogie avec la Fosse (`ResolveurTour::COUT_FRANCHISSEMENT` : héros →
 *    fosse → réception = 2). Le saut fait partie du mouvement.
 *  - « Items » : toute UNITÉ d'inventaire du héros, PORTÉE OU NON — le livret
 *    nomme les armes et les armures. C'est plus large que l'action `jeter`
 *    (sac et consommables seulement) à dessein : un héros dont tout le matériel
 *    est sur le dos doit pouvoir payer, et c'est lui qui choisit quoi. Une pile
 *    compte pour autant d'objets qu'elle a d'exemplaires. L'or n'est pas un objet.
 *  - Case où il S'ENLISE : la première case de sable de sa ligne sans personne
 *    (héros même tombé) ; s'il n'y en a pas, le saut n'est pas offert.
 *  - Case de SORTIE occupée au moment de sortir : il reste enlisé (menu =
 *    « Terminer le tour ») jusqu'à ce qu'elle se libère. Rien n'est dit.
 *  - Monstres : le sable leur est infranchissable comme à tout le monde
 *    (`bloque_mouvement`) ; rien n'est dit non plus de ce qu'ils y font.
 *
 * @see \App\Engine\MotsClesTerrain  saut_par_dessus · saut_reussi_sur · enlise
 */
final class MoteurSables
{
    /** Les types d'action que ce moteur rend — tous déclarés dans `JournalCombat::TYPES`. */
    public const TYPES = ['saut_sables', 'defausse_sables', 'sortie_sables'];

    /** Phase 1 : le héros doit encore abandonner `a_defausser` objets. */
    public const PHASE_DEFAUSSE = 'defausse';

    /** Phase 2 : tout est payé, il se hissera hors des sables à son tour suivant. */
    public const PHASE_SABLES = 'sables';

    /**
     * Les quatre sens du saut. Orthogonaux seulement : « the square directly
     * across » (livret) est en face, jamais en diagonale.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const SENS = ['n' => [0, -1], 's' => [0, 1], 'e' => [1, 0], 'o' => [-1, 0]];

    public function __construct(
        private readonly LanceurDes $des,
        private readonly Equipement $equipement,
    ) {}

    // =====================================================================
    // Lecture de la carte — qui est du sable, et quelles lignes de saut existent
    // =====================================================================

    /**
     * Les cases de SABLE de la carte : toute entrée de `grille['terrain']` dont le
     * type porte `saut_par_dessus`. Lue par la CLÉ du vocabulaire, jamais par le
     * nom : un second terrain qui se franchit d'un saut serait traité pareil.
     *
     * @return array<string, array{nom: string, terrain_id: int, effet: array<string, mixed>}> clé « x,y »
     */
    public function cases(Carte $carte): array
    {
        $entrees = (array) ($carte->grille['terrain'] ?? []);

        if ($entrees === []) {
            return [];
        }

        $catalogue = Terrain::query()
            ->whereIn('id', array_values(array_unique(array_column($entrees, 'terrain_id'))))
            ->get(['id', 'nom', 'effet'])
            ->keyBy('id');

        $cases = [];

        foreach ($entrees as $entree) {
            $type = $catalogue->get($entree['terrain_id'] ?? null);

            if ($type !== null && isset(((array) $type->effet)['saut_par_dessus'])) {
                $cases[((int) $entree['x']).','.((int) $entree['y'])] = [
                    'nom' => (string) $type->nom,
                    'terrain_id' => (int) $type->id,
                    'effet' => (array) $type->effet,
                ];
            }
        }

        return $cases;
    }

    /**
     * Les sauts LÉGAUX depuis la case du héros : une entrée par sens où, à partir
     * de la case voisine, on trouve EXACTEMENT `saut_par_dessus` cases de sable
     * consécutives suivies d'une case de réception libre.
     *
     * ⚠ UNE SEULE liste : le menu en tire ses options, le résolveur la relit pour
     * revalider le choix. « Le menu ne propose jamais ce que le résolveur
     * refusera » tient parce que les deux appellent ceci.
     *
     * Une bande de 2 cases de profondeur ne se saute donc que dans le sens de sa
     * PROFONDEUR : depuis le bout de la bande, le long de sa longueur, la file de
     * sable est plus longue que 2 et le saut n'est pas offert (le livret ne
     * décrit pas de saut par-dessus plus de deux cases).
     *
     * @return list<array{sens: string, sable: array{x: int, y: int}, cases: list<array{x: int, y: int}>, vers: array{x: int, y: int}, coule: array{x: int, y: int}, nom: string, terrain_id: int, effet: array<string, mixed>}>
     */
    public function sauts(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat): array
    {
        $carte = $quete->carte;

        if ($carte === null || $etat->position_x === null || $etat->tombe || $etat->enlise !== null) {
            return [];
        }

        $sables = $this->cases($carte);

        if ($sables === []) {
            return [];
        }

        $grille = FabriqueGrille::pour($quete, exceptPersonnageId: (int) $personnage->id);
        $x = (int) $etat->position_x;
        $y = (int) $etat->position_y;
        $sauts = [];

        foreach (self::SENS as $sens => [$dx, $dy]) {
            $file = [];
            $cx = $x + $dx;
            $cy = $y + $dy;

            while (isset($sables["{$cx},{$cy}"])) {
                $file[] = ['x' => $cx, 'y' => $cy];
                $cx += $dx;
                $cy += $dy;
            }

            if ($file === []) {
                continue;
            }

            $type = $sables["{$file[0]['x']},{$file[0]['y']}"];

            if (count($file) !== (int) $type['effet']['saut_par_dessus']) {
                continue;
            }

            $derniere = $file[count($file) - 1];

            // La case de réception : du SOL, sans figure, où l'on peut s'arrêter,
            // et qu'aucune porte ne sépare de la dernière case de sable.
            if (! $grille->estTraversable($cx, $cy)
                || $grille->arretInterdit($cx, $cy)
                || $grille->porteBloqueEntre($derniere['x'], $derniere['y'], $cx, $cy)) {
                continue;
            }

            $coule = null;

            foreach ($file as $case) {
                if ($this->caseLibreDeHeros($quete, $case['x'], $case['y'], (int) $personnage->id)) {
                    $coule = $case;
                    break;
                }
            }

            if ($coule === null) {
                continue; // rien où s'enliser : le saut raté n'aurait nulle part où atterrir
            }

            $sauts[] = [
                'sens' => $sens,
                'sable' => $file[0],
                'cases' => $file,
                'vers' => ['x' => $cx, 'y' => $cy],
                'coule' => $coule,
                'nom' => $type['nom'],
                'terrain_id' => $type['terrain_id'],
                'effet' => $type['effet'],
            ];
        }

        return $sauts;
    }

    /** Aucun héros — debout OU tombé — ne se trouve sur cette case (sa propre position exceptée). */
    private function caseLibreDeHeros(Quete $quete, int $x, int $y, int $sauf): bool
    {
        return ! $quete->etatsPersonnages()
            ->where('position_x', $x)
            ->where('position_y', $y)
            ->where('personnage_id', '!=', $sauf)
            ->exists();
    }

    /**
     * Les options de menu `saut_sables`, une par ligne de saut LÉGALE que les
     * points de déplacement restants permettent de payer.
     *
     * @return list<array<string, mixed>>
     */
    public function optionsDeSaut(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat, int $restant): array
    {
        $options = [];

        foreach ($this->sauts($quete, $personnage, $etat) as $saut) {
            $cout = count($saut['cases']) + 1;

            if ($restant < $cout) {
                continue;
            }

            $face = $this->faceRequise($saut['effet']);

            $options[] = [
                'id' => "sauter_sables_{$saut['sable']['x']}_{$saut['sable']['y']}",
                // Le RISQUE est dit dans le libellé : un échec coûte deux objets et deux tours, c'est
                // l'information qu'un joueur doit avoir sous les yeux AVANT de toucher.
                'libelle' => "Sauter par-dessus les {$saut['nom']} — un dé de combat, ".self::libelleFace($face)
                    .' pour réussir ; sinon vous vous enlisez et abandonnez '.(int) ($saut['effet']['enlise'] ?? 0).' objets',
                'type' => 'saut_sables',
                'parametres' => [
                    'sable' => $saut['sable'],
                    'vers' => $saut['vers'],
                    'sens' => $saut['sens'],
                    'cout' => $cout,
                    // La DÉCISION, pas l'ingrédient : ce qu'un échec coûte, dit par le serveur.
                    'enlise' => (int) ($saut['effet']['enlise'] ?? 0),
                ],
            ];
        }

        return $options;
    }

    // =====================================================================
    // 1. Le saut
    // =====================================================================

    /**
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @param  int  $restant  points de déplacement restants du tour (calculés par l'appelant : `pointsDeplacement()` est privé au résolveur)
     * @return array<string, mixed>
     */
    public function resoudreSaut(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        int $restant,
        array $acteur,
    ): array {
        $demande = (array) data_get($option, 'parametres.sable', []);
        $saut = null;

        foreach ($this->sauts($quete, $personnage, $etat) as $candidat) {
            if ($candidat['sable']['x'] === (int) ($demande['x'] ?? -1) && $candidat['sable']['y'] === (int) ($demande['y'] ?? -1)) {
                $saut = $candidat;
                break;
            }
        }

        if ($saut === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Impossible de sauter : il n\'y a plus de case libre où atterrir de l\'autre côté des sables.',
            ]);
        }

        $cout = count($saut['cases']) + 1;

        if ($restant < $cout) {
            throw ValidationException::withMessages([
                'option_id' => "Pas assez de déplacement restant pour franchir les sables mouvants ({$cout} cases).",
            ]);
        }

        $requise = $this->faceRequise($saut['effet']);
        $face = $this->des->desCombat(1)[0];
        $reussi = $face === $requise;

        $payload = [
            'type' => 'saut_sables',
            'option_id' => $option['id'] ?? null,
            'libelle' => $option['libelle'] ?? null,
            'terrain' => $saut['nom'],
            'terrain_id' => $saut['terrain_id'],
            'sens' => $saut['sens'],
            'depart' => ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y],
            'en_face' => $saut['vers'],
            'cout' => $cout,
            // Le jet : un dé de combat, et la face qui FAIT réussir est celle du
            // catalogue — `JournalCombat::desJetUnilateral()` s'en sert pour dessiner le dé.
            'des_lances' => 1,
            'faces' => [$face->value],
            'face_requise' => $requise->value,
            'succes' => $reussi,
            'franchi' => $reussi,
        ];

        if ($reussi) {
            $restantApres = max(0, $restant - $cout);

            $etat->update([
                'position_x' => $saut['vers']['x'],
                'position_y' => $saut['vers']['y'],
                'deplacement_restant' => $restantApres,
                'a_deplace' => $restantApres <= 0,
            ]);

            $payload['vers'] = $saut['vers'];
            $payload['deplacement_restant'] = $restantApres;

            Journal::ajouter($groupe, 'jet', $payload, $acteur);

            return $payload;
        }

        // ÉCHEC : « the hero lands in the quicksand and starts to sink ».
        $aDefausser = (int) ($saut['effet']['enlise'] ?? 0);
        $disponibles = $this->nombreDObjets($personnage);

        $etat->update([
            'position_x' => $saut['coule']['x'],
            'position_y' => $saut['coule']['y'],
            'deplacement_restant' => 0,
            'a_deplace' => true,
            'enlise' => [
                'phase' => $disponibles >= $aDefausser ? self::PHASE_DEFAUSSE : self::PHASE_SABLES,
                'a_defausser' => $disponibles >= $aDefausser ? $aDefausser : 0,
                'sable' => $saut['coule'],
                'vers' => $saut['vers'],
                'terrain' => $saut['nom'],
            ],
        ]);

        $payload['vers'] = $saut['coule'];
        $payload['deplacement_restant'] = 0;
        $payload['a_defausser'] = $disponibles >= $aDefausser ? $aDefausser : 0;
        $payload['tombe'] = false;

        if ($disponibles < $aDefausser) {
            // Pas de quoi payer : « to avoid death » est hors d'atteinte. La mort
            // définitive est refusée dans ce jeu (René, 2026-10-15) — il tombe,
            // 0 PV, à terre et relevable, exactement comme dans la roche
            // (`ResolveurTour::verifierRocheMortelle()`). Son tour s'achève avec.
            $this->faireTomber($personnage, $etat);
            $payload['tombe'] = true;
            $payload['objets_disponibles'] = $disponibles;
            $payload['fin_de_tour'] = true;
        }

        Journal::ajouter($groupe, 'jet', $payload, $acteur);

        return $payload;
    }

    // =====================================================================
    // 2. La défausse
    // =====================================================================

    /**
     * Les objets que le héros peut abandonner : une entrée par ligne
     * d'inventaire, PORTÉE OU NON (voir l'en-tête, « Items »). `cle` est la
     * chaîne opaque que la manette renvoie (`objet:{id}`), même format que
     * `jeter`.
     *
     * @return list<array{cle: string, inventaire_id: int, nom: string, quantite: int, porte: bool}>
     */
    public function objetsDefaussables(Personnage $personnage): array
    {
        $equipement = app(Equipement::class);

        return $personnage->inventaire()->with('objet')->orderBy('id')->get()
            // Une pièce INAMOVIBLE portée ne va pas aux sables : ni proposée, ni acceptée (`resoudreDefausse()` relit cette liste).
            ->filter(fn (Inventaire $l) => $l->objet !== null && (int) $l->quantite > 0 && $equipement->retirable($l))
            ->map(fn (Inventaire $l) => [
                'cle' => "objet:{$l->id}",
                'inventaire_id' => (int) $l->id,
                'nom' => (string) $l->objet->nom,
                'quantite' => (int) $l->quantite,
                'porte' => in_array($l->emplacement, Equipement::SLOTS, true),
                // Ce que la manette affiche sous le nom : une arme en main n'est pas une babiole du sac.
                'detail' => in_array($l->emplacement, Equipement::SLOTS, true) ? 'porté' : null,
            ])
            ->values()
            ->all();
    }

    /** Le nombre d'OBJETS du héros : une pile compte pour autant d'exemplaires qu'elle en a. */
    public function nombreDObjets(Personnage $personnage): int
    {
        return array_sum(array_column($this->objetsDefaussables($personnage), 'quantite'));
    }

    /**
     * Abandonne UN objet aux sables (ou, faute d'objet, sombre) — appelée deux
     * fois de suite par le menu forcé de la phase `defausse`.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    public function resoudreDefausse(
        Groupe $groupe,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $parametres,
        array $acteur,
    ): array {
        $enlise = (array) $etat->enlise;

        if (($enlise['phase'] ?? null) !== self::PHASE_DEFAUSSE || (int) ($enlise['a_defausser'] ?? 0) < 1) {
            throw ValidationException::withMessages(['option_id' => 'Il n\'y a rien à abandonner aux sables.']);
        }

        $reelles = $this->objetsDefaussables($personnage);
        $payload = [
            'type' => 'defausse_sables',
            'option_id' => $option['id'] ?? null,
            'libelle' => $option['libelle'] ?? null,
            'terrain' => (string) ($enlise['terrain'] ?? 'Sables mouvants'),
            'objet' => null,
            'tombe' => false,
            'fin_de_tour' => false,
        ];

        // Plus rien à abandonner : le héros ne peut pas « avoid death » — il sombre.
        if ($reelles === []) {
            $this->faireTomber($personnage, $etat);
            $etat->update(['enlise' => [...$enlise, 'phase' => self::PHASE_SABLES, 'a_defausser' => 0]]);

            $payload['a_defausser'] = 0;
            $payload['restantes'] = 0;
            $payload['tombe'] = true;
            $payload['fin_de_tour'] = true;

            Journal::ajouter($groupe, 'action', $payload, $acteur);

            return $payload;
        }

        // LA LISTE BLANCHE est celle de l'OPTION du dernier menu, revalidée contre
        // l'inventaire réel : ni une clé forgée, ni une ligne qui a bougé depuis.
        $cle = (string) ($parametres['cle'] ?? '');
        $entree = collect((array) data_get($option, 'parametres.objets', []))
            ->first(fn ($e) => $cle !== '' && (string) ($e['cle'] ?? '') === $cle);

        if ($entree === null) {
            throw ValidationException::withMessages([
                'parametres' => 'Choix requis : parametres.cle doit désigner un des objets proposés.',
            ]);
        }

        $ligne = $personnage->inventaire()->with('objet')
            ->whereKey((int) ($entree['inventaire_id'] ?? 0))->first();

        if ($ligne === null || (int) $ligne->quantite < 1) {
            throw ValidationException::withMessages(['parametres' => 'Cet objet n\'est plus en votre possession.']);
        }

        $nom = (string) $ligne->objet?->nom;
        $porte = in_array($ligne->emplacement, Equipement::SLOTS, true);

        if ((int) $ligne->quantite > 1) {
            $ligne->decrement('quantite');
        } else {
            $ligne->delete();
        }

        // Une pièce PORTÉE qui s'en va change les dés et les jauges du héros : même
        // recalcul que pour l'arme lancée (`ResolveurTour::consommerArmeLancee()`).
        if ($porte) {
            $this->equipement->recalculerCombat($personnage->refresh());
        }

        $restantes = (int) $enlise['a_defausser'] - 1;
        $payload['objet'] = $nom;
        $payload['porte'] = $porte;
        $payload['restantes'] = $restantes;
        $payload['a_defausser'] = $restantes;

        if ($restantes <= 0) {
            // « This ends the hero's turn. »
            $etat->update(['enlise' => [...$enlise, 'phase' => self::PHASE_SABLES, 'a_defausser' => 0]]);
            $payload['fin_de_tour'] = true;
        } elseif ($this->nombreDObjets($personnage) === 0) {
            // Il en reste à payer et il n'a plus rien (une potion bue entre-temps,
            // hors menu, a pu le vider) : « to avoid death » est hors d'atteinte.
            $this->faireTomber($personnage, $etat);
            $etat->update(['enlise' => [...$enlise, 'phase' => self::PHASE_SABLES, 'a_defausser' => 0]]);
            $payload['a_defausser'] = 0;
            $payload['restantes'] = 0;
            $payload['tombe'] = true;
            $payload['fin_de_tour'] = true;
        } else {
            $etat->update(['enlise' => [...$enlise, 'a_defausser' => $restantes]]);
        }

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    // =====================================================================
    // 3. La sortie
    // =====================================================================

    /**
     * La case où le héros enlisé se hissera, si elle est LIBRE ; `null` sinon.
     * Le menu l'offre, le résolveur la relit.
     *
     * @return array{x: int, y: int}|null
     */
    public function sortieLibre(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat): ?array
    {
        $enlise = (array) $etat->enlise;
        $vers = (array) ($enlise['vers'] ?? []);

        if (($enlise['phase'] ?? null) !== self::PHASE_SABLES || ! isset($vers['x'], $vers['y']) || $quete->carte === null) {
            return null;
        }

        $grille = FabriqueGrille::pour($quete, exceptPersonnageId: (int) $personnage->id);
        $x = (int) $vers['x'];
        $y = (int) $vers['y'];

        return $grille->estTraversable($x, $y) && ! $grille->arretInterdit($x, $y)
            ? ['x' => $x, 'y' => $y]
            : null;
    }

    /**
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
        $enlise = (array) $etat->enlise;

        if (($enlise['phase'] ?? null) !== self::PHASE_SABLES) {
            throw ValidationException::withMessages([
                'option_id' => 'Vous devez d\'abord abandonner aux sables les objets qu\'ils vous demandent.',
            ]);
        }

        $vers = $this->sortieLibre($quete, $personnage, $etat);

        if ($vers === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Vous ne pouvez pas sortir des sables : la case en face est occupée.',
            ]);
        }

        $depart = (array) ($enlise['sable'] ?? []);

        $etat->update([
            'position_x' => $vers['x'],
            'position_y' => $vers['y'],
            'deplacement_restant' => 0,
            'a_deplace' => true,
            'enlise' => null,
        ]);

        $payload = [
            'type' => 'sortie_sables',
            'option_id' => $option['id'] ?? null,
            'libelle' => $option['libelle'] ?? null,
            'terrain' => (string) ($enlise['terrain'] ?? 'Sables mouvants'),
            'depart' => ['x' => (int) ($depart['x'] ?? 0), 'y' => (int) ($depart['y'] ?? 0)],
            'vers' => $vers,
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    // =====================================================================
    // Le menu forcé
    // =====================================================================

    /**
     * Le menu d'un héros ENLISÉ, ou `null` s'il ne l'est pas. Court-circuite tout
     * le reste de `MenuMoteur::generer()` (comme le fait la Chute de blocs
     * déclenchée) : la règle du livret ne laisse AUCUN autre choix.
     *
     * @return array{situation: string, options: list<array<string, mixed>>}|null
     */
    public function menuForce(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat): ?array
    {
        if ($etat->enlise === null || $etat->tombe) {
            return null;
        }

        $enlise = (array) $etat->enlise;
        $nom = (string) ($enlise['terrain'] ?? 'Sables mouvants');

        // Son tour est fini (dernier objet abandonné) : il attend le suivant.
        if ($etat->a_joue) {
            return [
                'situation' => "Tour terminé — vous vous enlisez dans les {$nom} ; vous vous hisserez dehors à votre prochain tour.",
                'options' => [],
            ];
        }

        if (($enlise['phase'] ?? null) === self::PHASE_DEFAUSSE) {
            $reste = (int) ($enlise['a_defausser'] ?? 0);
            $objets = $this->objetsDefaussables($personnage);

            return [
                'situation' => $objets === []
                    ? "Vous vous enlisez dans les {$nom} et vous n'avez plus rien à abandonner : vous sombrez."
                    : "Vous vous enlisez dans les {$nom} ! Pour ne pas sombrer, abandonnez {$reste} objet".($reste > 1 ? 's' : '').' de votre choix.',
                'options' => [[
                    'id' => 'defausser_sables',
                    'libelle' => $objets === []
                        ? 'Sombrer — vous n\'avez plus rien à abandonner'
                        : "Abandonner un objet aux sables ({$reste} à abandonner)",
                    'type' => 'defausse_sables',
                    'parametres' => [
                        'restantes' => $reste,
                        // La liste blanche, relue par `resoudreDefausse()` sur CETTE option.
                        'objets' => $objets,
                    ],
                ]],
            ];
        }

        $vers = $this->sortieLibre($quete, $personnage, $etat);

        if ($vers === null) {
            return [
                'situation' => "Vous êtes enlisé dans les {$nom} et la case en face est occupée : vous ne pouvez pas en sortir tant qu'elle l'est.",
                'options' => [['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente']],
            ];
        }

        return [
            'situation' => "Vous êtes enlisé dans les {$nom} : c'est le moment de vous hisser de l'autre côté.",
            'options' => [[
                'id' => 'sortir_des_sables',
                'libelle' => "Se hisser hors des {$nom}",
                'type' => 'sortie_sables',
                'parametres' => ['vers' => $vers],
            ]],
        ];
    }

    // =====================================================================
    // Publication
    // =====================================================================

    /**
     * L'état d'enlisement tel que l'écran le lit (`entites[].enlise`) : la
     * DÉCISION, pas la colonne brute — ce qui reste à payer et où il ressortira.
     *
     * @return array{a_defausser: int, vers: array{x: int, y: int}, terrain: string}|null
     */
    public static function publier(?EtatPersonnageQuete $etat): ?array
    {
        if ($etat?->enlise === null) {
            return null;
        }

        $enlise = (array) $etat->enlise;

        return [
            'a_defausser' => (int) ($enlise['a_defausser'] ?? 0),
            'vers' => [
                'x' => (int) ($enlise['vers']['x'] ?? 0),
                'y' => (int) ($enlise['vers']['y'] ?? 0),
            ],
            'terrain' => (string) ($enlise['terrain'] ?? 'Sables mouvants'),
        ];
    }

    // =====================================================================
    // Outils
    // =====================================================================

    /** À terre, à 0 PV, relevable — le même état que dans la roche ou au fond d'une fosse. */
    private function faireTomber(Personnage $personnage, EtatPersonnageQuete $etat): void
    {
        $etat->update(['tombe' => true]);
        $personnage->update(['pv_body' => 0]);
    }

    /** La face qui fait réussir le saut, lue sur le CATALOGUE (`saut_reussi_sur`). */
    private function faceRequise(array $effet): FaceDeCombat
    {
        return FaceDeCombat::tryFrom((string) ($effet['saut_reussi_sur'] ?? ''))
            ?? throw new \LogicException('Un terrain à saut_par_dessus doit déclarer saut_reussi_sur (une face de FaceDeCombat).');
    }

    /** « un bouclier noir » — la face, dite comme le joueur la voit. */
    public static function libelleFace(FaceDeCombat $face): string
    {
        return match ($face) {
            FaceDeCombat::Crane => 'un crâne',
            FaceDeCombat::BouclierBlanc => 'un bouclier blanc',
            FaceDeCombat::BouclierNoir => 'un bouclier noir',
        };
    }
}
