<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\JournalCombatDiffuse;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;
use Illuminate\Validation\ValidationException;

/**
 * VOILE D'OMBRE — *Cloak of Shadows* (Wizards of Morcar, *Spells of Darkness*,
 * carte © 2026 Hasbro, texte transcrit dans `reference/18_extensions.md`).
 *
 * « This spell summons a patch of darkness. Place the Cloak of Shadows tile on
 * the gameboard. Heroes and monsters on the tile may not attack or be attacked.
 * The darkness blocks line of sight into and through it. Place 3 shadow tokens
 * on this card. At the start of the spellcaster's turn, remove a shadow token.
 * The spell ends after the last shadow token is removed. »
 *
 * Une couche DURABLE de la carte, `carte.grille['ombre']` — jamais le cache :
 * une entrée par voile, `{x, y, l, h, jetons, lanceur_id}` (un rectangle, ancré
 * en haut à gauche). C'est le MÊME patron que `grille['glace']` (couche posée en
 * cours de quête par un sort, dédiée plutôt que dans un catalogue), et le même
 * principe de lecture UNIQUE :
 *
 *  - la VUE est lue par `FabriqueGrille::pour()` seule (via `cellules()`), qui
 *    pose les cases dans `Grille::occulter()` (« through ») ET
 *    `Grille::assombrir()` (« into ») ;
 *  - « ne peut ni attaquer ni être attaqué » est lu par `contient()` /
 *    `contientHeros()` / `contientMonstre()` — trois portes d'une même question,
 *    appelées par `MoteurSorts::attaqueInterdite()` (le héros qui frappe),
 *    `MoteurSorts::estInattaquable()` (le héros visé), `MenuMoteur::ciblesPourArme()`
 *    et `ResolveurTour::frapper()` (le monstre visé), `ResolveurTour::jouerMonstre()`
 *    (le monstre qui frappe).
 *
 * TAILLE — 3×2 cases, et c'est une mesure, pas une invention. Aucune carte ni
 * aucune règle ne la donne ; le livret G1504 p. 4 (page *Components*) montre
 * la pièce, deux cartons violets dont le grand mesure 72,2 × 108,1 pt à la
 * page, contre 54,2 × 108,4 pt pour la tuile Tremblement de terre — dont le
 * texte (p. 12) dit qu'elle « couvre 6 cases », soit 3 de long — et 225 pt
 * pour la tuile du Laboratoire de l'Artificier (6 cases de côté) : 36 pt la
 * case, donc le grand carton fait exactement 2×3. Le second, carré de 54 pt,
 * n'a aucun rôle nommé et n'est pas porté. L'orientation est au choix du
 * lanceur (3×2 ou 2×3).
 *
 * POSE — décision écrite : la carte dit seulement « place the tile on the
 * gameboard ». On exige donc, faute de portée imprimée, que le lanceur VOIE les
 * six cases, qu'elles soient du sol que le brouillard ne cache pas, sans
 * mur ni meuble bloquant ni porte close — des figures peuvent s'y tenir, la
 * carte l'envisage (« heroes and monsters on the tile »), le lanceur lui-même
 * compris.
 */
final class MoteurOmbre
{
    public const LARGEUR = 3;

    public const HAUTEUR = 2;

    /** « Place 3 shadow tokens on this card. » */
    public const JETONS = 3;

    /** Marqueur « le jeton de ce round est déjà retiré » (`etat.capacites_tour`). */
    public const MARQUEUR_TOUR = 'ombre_decompte';

    /** Plafond d'entrées de menu — les plus proches du lanceur d'abord. */
    public const MAX_ENTREES = 24;

    /**
     * Les voiles ACTIFS de la carte (jetons restants > 0).
     *
     * @return list<array{x: int, y: int, l: int, h: int, jetons: int, lanceur_id: ?int}>
     */
    public function voiles(?Carte $carte): array
    {
        $voiles = [];

        foreach ((array) ($carte?->grille['ombre'] ?? []) as $v) {
            if ((int) ($v['jetons'] ?? 0) > 0) {
                $voiles[] = [
                    'x' => (int) $v['x'], 'y' => (int) $v['y'],
                    'l' => (int) ($v['l'] ?? self::LARGEUR), 'h' => (int) ($v['h'] ?? self::HAUTEUR),
                    'jetons' => (int) $v['jetons'],
                    'lanceur_id' => isset($v['lanceur_id']) ? (int) $v['lanceur_id'] : null,
                ];
            }
        }

        return $voiles;
    }

    /**
     * Toutes les cases sous un voile actif — la SEULE liste que lit
     * `FabriqueGrille::pour()`.
     *
     * @return list<array{x: int, y: int}>
     */
    public function cellules(?Carte $carte): array
    {
        $cases = [];

        foreach ($this->voiles($carte) as $v) {
            foreach (self::casesDuRectangle($v['x'], $v['y'], $v['l'], $v['h']) as $c) {
                $cases[] = $c;
            }
        }

        return $cases;
    }

    /** @return list<array{x: int, y: int}> */
    public static function casesDuRectangle(int $x, int $y, int $l, int $h): array
    {
        $cases = [];

        for ($dy = 0; $dy < $h; $dy++) {
            for ($dx = 0; $dx < $l; $dx++) {
                $cases[] = ['x' => $x + $dx, 'y' => $y + $dy];
            }
        }

        return $cases;
    }

    /** Une case de cette quête est-elle sous un voile ? */
    public function contient(?Quete $quete, int $x, int $y): bool
    {
        foreach ($this->cellules($quete?->carte) as $c) {
            if ($c['x'] === $x && $c['y'] === $y) {
                return true;
            }
        }

        return false;
    }

    /** Ce héros se tient-il sous un voile ? (il ne peut ni attaquer ni être attaqué) */
    public function contientHeros(Personnage $personnage): bool
    {
        $etat = EtatPersonnageQuete::enQuete($personnage);

        if ($etat === null || $etat->position_x === null) {
            return false;
        }

        return $this->contient($etat->quete, (int) $etat->position_x, (int) $etat->position_y);
    }

    /** Ce monstre (emprise comprise) touche-t-il un voile ? */
    public function contientMonstre(Quete $quete, InstanceMonstre $instance): bool
    {
        if ($instance->position_x === null || $this->voiles($quete->carte) === []) {
            return false;
        }

        $e = $instance->monstre?->emprise() ?? ['l' => 1, 'h' => 1];

        foreach (self::casesDuRectangle((int) $instance->position_x, (int) $instance->position_y, (int) $e['l'], (int) $e['h']) as $c) {
            if ($this->contient($quete, $c['x'], $c['y'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les emplacements LÉGAUX du voile pour ce lanceur, du plus proche au plus
     * lointain, plafonnés à `MAX_ENTREES` — POINT DE PASSAGE UNIQUE du menu
     * (`MoteurSorts::entreesPoseOmbre()`) ET du résolveur (`poser()`), qui
     * revérifie contre cette même liste : deux lectures finiraient par diverger.
     *
     * @param  array{x: int, y: int}  $lanceur
     * @return list<array{x: int, y: int, l: int, h: int}>
     */
    public function emplacementsLegaux(Quete $quete, Grille $grille, array $lanceur): array
    {
        $carte = $quete->carte;
        $salles = (array) ($carte?->grille['salles'] ?? []);
        $decouvertes = $quete->sallesDecouvertes();
        $hauteurCarte = count((array) ($carte?->grille['cases'] ?? []));
        $largeurCarte = $hauteurCarte > 0 ? count((array) $carte->grille['cases'][0]) : 0;

        // Une case est posable si c'est du sol que rien ne barre (une figure
        // dessus n'est PAS un obstacle) et que le groupe en connaît le lieu.
        $posable = function (int $x, int $y) use ($grille, $salles, $decouvertes): bool {
            if (! ($grille->estTraversable($x, $y) || $grille->estOccupeeParFigure($x, $y))) {
                return false;
            }

            $salle = Salles::indexDe($salles, $x, $y);

            return $salle === null || in_array($salle, $decouvertes, true);
        };

        $candidats = [];

        foreach ([[self::LARGEUR, self::HAUTEUR], [self::HAUTEUR, self::LARGEUR]] as [$l, $h]) {
            for ($y = 0; $y + $h <= $hauteurCarte; $y++) {
                for ($x = 0; $x + $l <= $largeurCarte; $x++) {
                    $cases = self::casesDuRectangle($x, $y, $l, $h);

                    $ok = true;
                    foreach ($cases as $c) {
                        if (! $posable($c['x'], $c['y'])
                            || ! $grille->ligneDeVue($lanceur['x'], $lanceur['y'], $c['x'], $c['y'])) {
                            $ok = false;
                            break;
                        }
                    }

                    if ($ok) {
                        // Distance du centre du rectangle au lanceur : « le plus
                        // près d'abord », puis l'ordre de lecture (stable).
                        $distance = abs(($x + ($l - 1) / 2) - $lanceur['x']) + abs(($y + ($h - 1) / 2) - $lanceur['y']);
                        $candidats[] = ['x' => $x, 'y' => $y, 'l' => $l, 'h' => $h, 'distance' => $distance];
                    }
                }
            }
        }

        usort($candidats, static fn (array $a, array $b) => [$a['distance'], $a['y'], $a['x'], $a['l']]
            <=> [$b['distance'], $b['y'], $b['x'], $b['l']]);

        return array_map(
            static fn (array $c) => ['x' => $c['x'], 'y' => $c['y'], 'l' => $c['l'], 'h' => $c['h']],
            array_slice($candidats, 0, self::MAX_ENTREES),
        );
    }

    /**
     * Pose le voile — le résolveur n'accepte que l'un des emplacements que le
     * menu a pu offrir (revérifié ici contre l'état COURANT).
     *
     * @param  list<array{x: int, y: int}>  $cases  les six cases choisies
     * @return array<string, mixed>
     */
    public function poser(Quete $quete, Personnage $lanceur, EtatPersonnageQuete $etat, array $cases): array
    {
        $carte = $quete->carte;

        if ($carte === null || $etat->position_x === null) {
            throw ValidationException::withMessages(['option_id' => 'Voile d\'ombre : carte ou lanceur introuvable.']);
        }

        if ($this->voilesDe($carte, (int) $lanceur->id) !== []) {
            throw ValidationException::withMessages(['option_id' => 'Voile d\'ombre : le tien est déjà posé sur le plateau.']);
        }

        $xs = array_map(static fn ($c) => (int) $c['x'], $cases);
        $ys = array_map(static fn ($c) => (int) $c['y'], $cases);

        if ($cases === [] || count($cases) !== self::LARGEUR * self::HAUTEUR) {
            throw ValidationException::withMessages(['option_id' => 'Voile d\'ombre : six cases attendues.']);
        }

        $choisi = ['x' => min($xs), 'y' => min($ys), 'l' => max($xs) - min($xs) + 1, 'h' => max($ys) - min($ys) + 1];

        $grille = FabriqueGrille::pour($quete);
        $legal = false;

        foreach ($this->emplacementsLegaux($quete, $grille, ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y]) as $e) {
            if ($e === $choisi) {
                $legal = true;
                break;
            }
        }

        // Le rectangle annoncé doit aussi être EXACTEMENT les six cases reçues.
        $attendues = array_map(static fn ($c) => $c['x'].','.$c['y'],
            self::casesDuRectangle($choisi['x'], $choisi['y'], $choisi['l'], $choisi['h']));
        $recues = array_map(static fn ($c) => ((int) $c['x']).','.((int) $c['y']), $cases);
        sort($attendues);
        sort($recues);

        if (! $legal || $attendues !== $recues) {
            throw ValidationException::withMessages(['option_id' => 'Voile d\'ombre : emplacement illégal (hors de vue, sur un obstacle ou sur une zone inconnue).']);
        }

        $data = (array) $carte->grille;
        $data['ombre'] = [...array_values((array) ($data['ombre'] ?? [])), [
            ...$choisi, 'jetons' => self::JETONS, 'lanceur_id' => (int) $lanceur->id,
        ]];
        $carte->update(['grille' => $data]);

        return [
            'mode' => 'pose_ombre',
            'ombre' => [...$choisi, 'jetons' => self::JETONS],
            'cases' => $cases,
            'texte' => 'Un voile de ténèbres s\'étend sur le plateau ('.$choisi['l'].'×'.$choisi['h'].' cases, '
                .self::JETONS.' jetons) : nul ne peut y attaquer ni y être attaqué, et rien ne s\'y voit.',
        ];
    }

    /**
     * Début du tour du LANCEUR : « remove a shadow token ». Au dernier jeton le
     * voile est retiré de la carte. Idempotence : l'appelant ne l'invoque qu'au
     * moment où le tour s'ouvre (la garde `deplacement_tour` du menu).
     *
     * Annoncé — journal ET fil du combat — : un effet automatique que rien
     * n'annonce est injouable.
     *
     * @return array<string, mixed>|null `null` si ce héros n'a aucun voile actif
     */
    public function debutDeTour(Groupe $groupe, Quete $quete, Personnage $lanceur, ?EtatPersonnageQuete $etat = null): ?array
    {
        $carte = $quete->carte;

        if ($carte === null || $this->voilesDe($carte, (int) $lanceur->id) === []) {
            return null;
        }

        // Unicité PAR ROUND : le marqueur vit dans `capacites_tour`, que
        // `ouvrirNouveauTour()` remet à zéro. Un lanceur TOMBÉ (`$etat` absent)
        // n'a pas de tour à marquer — l'appelant ne passe qu'une fois par round.
        if ($etat !== null) {
            $duTour = (array) ($etat->capacites_tour ?? []);

            if (in_array(self::MARQUEUR_TOUR, $duTour, true)) {
                return null;
            }

            $etat->update(['capacites_tour' => array_values(array_unique([...$duTour, self::MARQUEUR_TOUR]))]);
        }

        $data = (array) $carte->grille;
        $restants = [];
        $dernier = null;

        foreach ((array) ($data['ombre'] ?? []) as $v) {
            if ((int) ($v['lanceur_id'] ?? 0) === (int) $lanceur->id && (int) ($v['jetons'] ?? 0) > 0) {
                $v['jetons'] = (int) $v['jetons'] - 1;
                $dernier = $v;

                if ($v['jetons'] <= 0) {
                    continue; // « The spell ends after the last shadow token is removed. »
                }
            }

            $restants[] = $v;
        }

        $data['ombre'] = $restants;
        $carte->update(['grille' => $data]);

        $jetons = (int) ($dernier['jetons'] ?? 0);
        $payload = [
            'type' => 'ombre_decompte',
            'personnage' => $lanceur->nom,
            'sort' => 'Voile d\'ombre',
            'jetons' => $jetons,
            'dissipee' => $jetons === 0,
            'texte' => $jetons === 0
                ? "Le voile d'ombre de {$lanceur->nom} se dissipe."
                : "Le voile d'ombre de {$lanceur->nom} s'amincit : {$jetons} jeton".($jetons > 1 ? 's' : '').' restant'.($jetons > 1 ? 's' : '').'.',
        ];

        $this->annoncer($groupe, $payload, $lanceur);

        return $payload;
    }

    /**
     * Un lanceur TOMBÉ n'ouvre plus de tour : son voile ne doit pas durer
     * jusqu'à la fin de la quête. Son jeton tombe à l'ouverture du round, au
     * rang où son tour aurait commencé (décision écrite : la carte ne prévoit
     * pas le cas).
     *
     * @return list<array<string, mixed>>
     */
    public function lanceursTombes(Groupe $groupe, Quete $quete): array
    {
        $annonces = [];

        foreach ($quete->etatsPersonnages()->where('tombe', true)->with('personnage')->get() as $etat) {
            if ($etat->personnage !== null
                && ($annonce = $this->debutDeTour($groupe, $quete->fresh(['carte']) ?? $quete, $etat->personnage)) !== null) {
                $annonces[] = $annonce;
            }
        }

        return $annonces;
    }

    /**
     * Ce que `EtatGroupe` publie : les voiles dont AU MOINS UNE case est vue, ce
     * qui est exactement le critère de la glace et des leviers (le brouillard).
     *
     * @param  list<list<string>>  $cases  grille DÉJÀ passée au brouillard
     * @return list<array{x: int, y: int, l: int, h: int, jetons: int, jetons_max: int, lanceur: ?string}>
     */
    public function publier(?Carte $carte, array $cases): array
    {
        $noms = Personnage::query()
            ->whereIn('id', collect($this->voiles($carte))->pluck('lanceur_id')->filter()->all())
            ->pluck('nom', 'id');

        $publies = [];

        foreach ($this->voiles($carte) as $v) {
            $vu = false;
            foreach (self::casesDuRectangle($v['x'], $v['y'], $v['l'], $v['h']) as $c) {
                if (($cases[$c['y']][$c['x']] ?? 'b') !== 'b') {
                    $vu = true;
                    break;
                }
            }

            if ($vu) {
                $publies[] = [
                    'x' => $v['x'], 'y' => $v['y'], 'l' => $v['l'], 'h' => $v['h'],
                    'jetons' => $v['jetons'], 'jetons_max' => self::JETONS,
                    'lanceur' => $v['lanceur_id'] !== null ? ($noms[$v['lanceur_id']] ?? null) : null,
                ];
            }
        }

        return $publies;
    }

    /** @return list<array<string, mixed>> */
    private function voilesDe(Carte $carte, int $lanceurId): array
    {
        return array_values(array_filter(
            $this->voiles($carte),
            static fn (array $v) => $v['lanceur_id'] === $lanceurId,
        ));
    }

    /**
     * Journal + fil du combat. ⚠ Best-effort sur la diffusion : appelé depuis la
     * composition d'un menu (début de tour), où une diffusion qui échoue ne doit
     * jamais laisser le joueur sans rien à jouer.
     *
     * @param  array<string, mixed>  $payload
     */
    private function annoncer(Groupe $groupe, array $payload, Personnage $lanceur): void
    {
        Journal::ajouter($groupe, 'action', $payload, ['type' => 'personnage', 'id' => $lanceur->id, 'nom' => $lanceur->nom]);

        try {
            $sequence = (int) Evenement::query()->where('groupe_id', $groupe->id)->max('sequence');
            broadcast(new JournalCombatDiffuse($groupe, [['texte' => (string) $payload['texte'], 'ton' => 'info']], $sequence));
        } catch (\Throwable) {
            // le journal fait foi ; le fil en direct est un confort
        }
    }
}
