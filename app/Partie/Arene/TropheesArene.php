<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\TamponAnnonces;
use Random\Randomizer;

/**
 * MODE ARÈNE, lot T4 — les TUILES TROPHÉES (livret F9528 p. 13-15, `reference/18_extensions.md` §Mode tournoi).
 *
 * Point de passage UNIQUE de leur état (`quetes.trophees_arene`) : pose face cachée, ramassage, réserves d'équipe,
 * usage.
 *
 * - Le LOT est celui de la p. 14 : trois tuiles de combat (crâne blanc, bouclier blanc, bouclier noir — René,
 *   2026-11-09 : « le jeton à visage est le bouclier noir »), les mêmes en « x2 », deux potions +2, une riposte.
 *   Mélangées, posées face cachée sur les six cases du plan p. 15 ; les trois de trop restent de côté (« Place any
 *   remaining trophy tiles to the side »).
 * - RAMASSAGE : en FINISSANT son activation sur la case, sans action — un héros OU un allié du Challenger (décision de
 *   René, 2026-11-12) ; la tuile se révèle et rejoint la réserve de l'équipe, que tout membre peut utiliser, et que
 *   personne ne vole. Une grande figure ramasse tout ce qui est sous elle. Le héros ramasse aussi en finissant sa
 *   MANŒUVRE (`ManoeuvreSolo`), comme la figure seule du Defender en finissant sa marche.
 * - USAGE, première étape (René, 2026-11-09) : la POTION (+2 PV, jusqu'au maximum) et l'ÉLAN (« discard ANY trophy tile
 *   to add 2 squares to their movement ») — pour le Challenger, par le menu. Le Defender (décision de René, même date) :
 *   il boit une potion dès qu'une figure blessée s'active, et ne prend jamais l'élan.
 * - ⚠ Seconde étape, écrite et pas oubliée : les tuiles de COMBAT (« use it after rolling combat dice ») et la RIPOSTE
 *   (réaction après avoir subi des dégâts), des deux côtés, et la potion « avant de mourir » à 0 PV — ce sont des
 *   réactions en plein jet. Jusque-là, chacune de ces tuiles a déjà un usage : l'élan.
 */
final class TropheesArene
{
    public const CRANE = 'crane';

    public const CRANE_X2 = 'crane_x2';

    public const BOUCLIER_BLANC = 'bouclier_blanc';

    public const BOUCLIER_BLANC_X2 = 'bouclier_blanc_x2';

    public const BOUCLIER_NOIR = 'bouclier_noir';

    public const BOUCLIER_NOIR_X2 = 'bouclier_noir_x2';

    public const POTION = 'potion';

    public const RIPOSTE = 'riposte';

    /** Le lot de la p. 14 (neuf tuiles). */
    public const LOT = [
        self::CRANE, self::BOUCLIER_BLANC, self::BOUCLIER_NOIR,
        self::CRANE_X2, self::BOUCLIER_BLANC_X2, self::BOUCLIER_NOIR_X2,
        self::POTION, self::POTION, self::RIPOSTE,
    ];

    /** Vocabulaire fermé : le libellé de chaque tuile et ce qu'elle fait — publiés tels quels. */
    public const TUILES = [
        self::CRANE => ['libelle' => 'Crâne blanc', 'effet' => '+1 crâne à un jet d\'attaque, après le jet'],
        self::CRANE_X2 => ['libelle' => 'Crâne blanc ×2', 'effet' => '+2 crânes à un jet d\'attaque, après le jet'],
        self::BOUCLIER_BLANC => ['libelle' => 'Bouclier blanc', 'effet' => '+1 bouclier blanc à un jet de défense, après le jet'],
        self::BOUCLIER_BLANC_X2 => ['libelle' => 'Bouclier blanc ×2', 'effet' => '+2 boucliers blancs à un jet de défense, après le jet'],
        self::BOUCLIER_NOIR => ['libelle' => 'Bouclier noir', 'effet' => '+1 bouclier noir à un jet de défense, après le jet'],
        self::BOUCLIER_NOIR_X2 => ['libelle' => 'Bouclier noir ×2', 'effet' => '+2 boucliers noirs à un jet de défense, après le jet'],
        self::POTION => ['libelle' => 'Potion de soin +2', 'effet' => 'rend 2 points de Corps'],
        self::RIPOSTE => ['libelle' => 'Riposte', 'effet' => 'contre-attaque immédiate après avoir été blessé au contact'],
    ];

    /** Ce que TOUTE tuile peut faire en plus (p. 13, « Burst of Speed »). */
    public const ELAN = '+2 cases de déplacement (défausser n\'importe quelle tuile)';

    public const CASES_ELAN = 2;

    public const SOIN_POTION = 2;

    /** Les six cases de trophée du plan p. 15, en (colonne, rangée) de la salle centrale depuis 0. */
    public const CASES = [[0, 0], [8, 0], [0, 7], [8, 7], [4, 3], [4, 4]];

    /** Les familles de tuiles de COMBAT, de la plus petite à la plus grande : ce qu'elles ajoutent au jet. */
    public const CRANES = [self::CRANE => 1, self::CRANE_X2 => 2];

    public const BOUCLIERS_BLANCS = [self::BOUCLIER_BLANC => 1, self::BOUCLIER_BLANC_X2 => 2];

    public const BOUCLIERS_NOIRS = [self::BOUCLIER_NOIR => 1, self::BOUCLIER_NOIR_X2 => 2];

    public const CHALLENGER = 'challenger';

    public const DEFENDER = 'defender';

    public function __construct(private readonly HallArene $hall) {}

    /** Pose le lot, mélangé, face cachée sur les cases du plan. */
    public function poser(Quete $quete): void
    {
        $lot = app(Randomizer::class)->shuffleArray(self::LOT);
        $cases = [];

        foreach (self::CASES as $i => [$c, $r]) {
            [$x, $y] = $this->hall->caseDeLaSalle($c, $r);
            $cases[] = ['x' => $x, 'y' => $y, 'tuile' => $lot[$i]];
        }

        $quete->update(['trophees_arene' => [
            'cases' => $cases,
            self::CHALLENGER => [],
            self::DEFENDER => [],
        ]]);
    }

    /**
     * Fin d'activation sur une ou plusieurs cases : les tuiles qui s'y trouvent rejoignent la réserve du camp.
     *
     * @param  list<array{x: int, y: int}>  $cellules
     * @return array<string, mixed>|null l'annonce, ou `null` s'il n'y avait rien
     */
    public function ramasser(Groupe $groupe, Quete $quete, string $camp, string $qui, array $cellules): ?array
    {
        $etat = $quete->fresh()?->trophees_arene;
        if (! is_array($etat)) {
            return null;
        }

        $ici = array_map(fn (array $c) => "{$c['x']},{$c['y']}", $cellules);
        $prises = [];
        $restantes = [];

        foreach ($etat['cases'] as $case) {
            if (in_array("{$case['x']},{$case['y']}", $ici, true)) {
                $prises[] = $case['tuile'];
            } else {
                $restantes[] = $case;
            }
        }

        if ($prises === []) {
            return null;
        }

        $etat['cases'] = $restantes;
        $etat[$camp] = [...$etat[$camp], ...$prises];
        $quete->update(['trophees_arene' => $etat]);

        $payload = [
            'type' => 'trophee_ramasse',
            'camp' => $camp,
            'nom' => $qui,
            'tuiles' => array_map(fn (string $t) => self::TUILES[$t]['libelle'], $prises),
        ];
        // Un effet automatique que rien n'annonce est injouable : le fil en direct par le tampon générique.
        app(TamponAnnonces::class)->annoncer($groupe, $payload);

        return $payload;
    }

    /** Fin d'activation d'un héros : il ramasse sous lui. */
    public function ramasserHeros(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat): ?array
    {
        return $this->ramasser($groupe, $quete, self::CHALLENGER, $heros->nom,
            [['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y]]);
    }

    /**
     * Fin d'activation d'un ALLIÉ du Challenger : il ramasse sous lui, comme un héros — « Heroes and allies make up the
     * Challenger team » (F9528 p. 12). Décision de René, 2026-11-12. L'annonce porte le nom de l'allié.
     */
    public function ramasserAllie(Groupe $groupe, Quete $quete, GroupeMercenaire $allie, string $nom): ?array
    {
        if ($allie->position_x === null) {
            return null;
        }

        return $this->ramasser($groupe, $quete, self::CHALLENGER, $nom,
            [['x' => (int) $allie->position_x, 'y' => (int) $allie->position_y]]);
    }

    /** Fin d'activation d'un monstre : il ramasse sous TOUTE son emprise. */
    public function ramasserMonstre(Groupe $groupe, Quete $quete, InstanceMonstre $instance): ?array
    {
        if ($instance->etat !== 'actif') {
            return null;
        }

        $e = $instance->monstre?->emprise() ?? ['l' => 1, 'h' => 1];

        return $this->ramasser($groupe, $quete, self::DEFENDER, $instance->nomAffiche(),
            Grille::cellulesEmprise((int) $instance->position_x, (int) $instance->position_y, $e['l'], $e['h']));
    }

    /**
     * Retire UNE tuile `$tuile` de la réserve du camp. Refus si elle n'y est pas.
     */
    public function defausser(Quete $quete, string $camp, string $tuile): bool
    {
        $etat = $quete->fresh()?->trophees_arene;
        if (! is_array($etat)) {
            return false;
        }

        $i = array_search($tuile, $etat[$camp] ?? [], true);
        if ($i === false) {
            return false;
        }

        array_splice($etat[$camp], $i, 1);
        $quete->update(['trophees_arene' => $etat]);

        return true;
    }

    /** @return list<string> la réserve d'un camp */
    public function reserve(Quete $quete, string $camp): array
    {
        return array_values((array) ($quete->trophees_arene[$camp] ?? []));
    }

    /**
     * DEFENDER (décision de René, 2026-11-09) : une figure BLESSÉE qui s'active boit une potion de la réserve.
     *
     * @return array<string, mixed>|null l'annonce
     */
    public function potionDuDefender(Groupe $groupe, Quete $quete, InstanceMonstre $instance): ?array
    {
        $max = $instance->pvBodyMax();
        if ((int) $instance->pv_body >= $max || ! $this->defausser($quete, self::DEFENDER, self::POTION)) {
            return null;
        }

        $avant = (int) $instance->pv_body;
        $apres = min($max, $avant + self::SOIN_POTION);
        $instance->update(['pv_body' => $apres]);

        $payload = ['type' => 'trophee_potion', 'camp' => self::DEFENDER, 'nom' => $instance->nomAffiche(), 'soin' => $apres - $avant];
        app(TamponAnnonces::class)->annoncer($groupe, $payload);

        return $payload;
    }

    /**
     * La plus petite tuile de `$famille` dont l'apport `n` satisfait `$utile(n)`, présente dans la réserve du camp — ou
     * `null`. « La plus petite » : une tuile « x2 » n'est dépensée que si la simple ne suffit pas ou manque.
     *
     * @param  array<string, int>  $famille
     * @param  callable(int): bool  $utile
     */
    public function tuileUtile(Quete $quete, string $camp, array $famille, callable $utile): ?string
    {
        $reserve = $this->reserve($quete->fresh() ?? $quete, $camp);

        foreach ($famille as $tuile => $apport) {
            if (in_array($tuile, $reserve, true) && $utile($apport)) {
                return $tuile;
            }
        }

        return null;
    }

    /**
     * DEFENDER QUI ATTAQUE (décision de René, 2026-11-09) : il ajoute un crâne « quand le jet n'est pas déjà gagné » —
     * la cible ne tombe pas déjà, et la tuile ajoute bien des dégâts. Rend l'apport (0 si rien n'est dépensé), tuile
     * défaussée et annoncée.
     */
    public function cranesDuDefender(Groupe $groupe, Quete $quete, string $qui, int $touches, int $boucliers, int $degats, int $pvCible): int
    {
        if ($degats >= $pvCible) {
            return 0;
        }

        return $this->depenserPourCombat($groupe, $quete, self::DEFENDER, $qui, self::CRANES, fn (int $n) => $touches + $n > $boucliers);
    }

    /** DEFENDER QUI DÉFEND : il ajoute un bouclier noir dès que le coup le blesse. Même retour. */
    public function boucliersDuDefender(Groupe $groupe, Quete $quete, string $qui, int $degats): int
    {
        if ($degats <= 0) {
            return 0;
        }

        return $this->depenserPourCombat($groupe, $quete, self::DEFENDER, $qui, self::BOUCLIERS_NOIRS, fn (int $n) => true);
    }

    /**
     * Défausse la tuile choisie et l'annonce ; rend son apport.
     *
     * @param  array<string, int>  $famille
     */
    public function depenserPourCombat(Groupe $groupe, Quete $quete, string $camp, string $qui, array $famille, callable $utile): int
    {
        $tuile = $this->tuileUtile($quete, $camp, $famille, $utile);

        if ($tuile === null || ! $this->defausser($quete, $camp, $tuile)) {
            return 0;
        }

        $this->annoncerCombat($groupe, $camp, $qui, $tuile);

        return $famille[$tuile];
    }

    /** L'annonce d'une tuile de combat ou de riposte utilisée. */
    public function annoncerCombat(Groupe $groupe, string $camp, string $qui, string $tuile): void
    {
        app(TamponAnnonces::class)->annoncer($groupe, [
            'type' => 'trophee_combat',
            'camp' => $camp,
            'nom' => $qui,
            'tuile' => self::TUILES[$tuile]['libelle'],
            'effet' => self::TUILES[$tuile]['effet'],
        ]);
    }

    /**
     * La publication (`EtatGroupe.quete.trophees`) : les cases encore couvertes — FACE CACHÉE, sans la tuile —, et les
     * deux réserves, tuile par tuile, avec ce qu'elles font.
     *
     * @return array<string, mixed>|null
     */
    public function publier(Quete $quete): ?array
    {
        $etat = $quete->trophees_arene;
        if (! is_array($etat)) {
            return null;
        }

        $decrire = fn (string $t) => ['tuile' => $t, 'libelle' => self::TUILES[$t]['libelle'], 'effet' => self::TUILES[$t]['effet']];

        return [
            'cases' => array_map(fn (array $c) => ['x' => (int) $c['x'], 'y' => (int) $c['y']], $etat['cases'] ?? []),
            self::CHALLENGER => array_map($decrire, $etat[self::CHALLENGER] ?? []),
            self::DEFENDER => array_map($decrire, $etat[self::DEFENDER] ?? []),
            'elan' => self::ELAN,
        ];
    }
}
