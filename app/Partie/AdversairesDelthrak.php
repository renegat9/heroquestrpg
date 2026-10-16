<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\DeRouge;
use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;
use Illuminate\Support\Collection;

/**
 * Les capacités propres aux QUATRE ADVERSAIRES NOMMÉS de *Jungles of Delthrak* portés le
 * 2026-10-15 (René) — livret F9907, stats relues sur la page rendue :
 *
 *  - **Deathspinner la Reine du Fléau** (q. 3, p. 17) — *Silencing Snare* : `filetsSilencieux()` ;
 *  - **Golem Cœur-de-Forge** (q. 6, p. 23) — *Breath of the Construct* : `souffle()` ;
 *  - **Scion Fleur-Pourrie** (q. 14 voie B, p. 39) — une Vrille à chaque fin de tour
 *    (`vrilleEnFinDeTour()`) et le sacrifice d'une Vrille au coup fatal (`vrilleASacrifier()`,
 *    lu par `MoteurDegats::infligerAMonstre()`) ;
 *  - **Bakabri le Cruel** (q. 15 voie C, p. 41) — rien ici : `tir_au_choix` (Gruulob) et trois sorts
 *    déjà portés (`config/archetypes_lanceurs.php`).
 *
 * Même discipline que `MoteurTourelle` : chaque effet est LU à l'endroit qui le décide, ANNONCÉ
 * (journal + payload, rendus par `JournalCombat` et `SceneDeTable`), et tout état est DURABLE
 * (colonnes d'`instances_monstres`, jamais le cache). Les clés sont celles de
 * `App\Engine\MotsClesAdversaires`.
 */
final class AdversairesDelthrak
{
    /** Le nom sous lequel le Souffle se range dans `capacites_reactives_utilisees` (une fois par quête). */
    private const USAGE_SOUFFLE = 'souffle_du_construit';

    // ------------------------------------------------------------------
    // Deathspinner — Silencing Snare
    // ------------------------------------------------------------------

    /**
     * « Silencing Snare – Deathspinner may cast Channel Dread at will on any hero that ends their
     * turn within her line of sight (no action required). She cannot use this ability if she
     * would not be able to take her action (for example, when under the effects of the Tempest
     * spell). »
     *
     * Appelée au point où `ResolveurTour::resoudre()` constate qu'un héros VIENT de finir son tour
     * (`marquerCreneau()`, le même point que le coup de corne du Minotaure). Pour chaque porteuse
     * qui PEUT AGIR — ni endormie, ni paralysée, ni privée de son tour (la *Tempête* de la carte),
     * ni sous la *Baguette d'Os*, ni enfumée — et qui VOIT le héros (la règle des sorts de Dread :
     * ligne de vue, figures bloquantes), le sort part SANS action et SANS usage du budget : « at
     * will » (`MoteurDread::lancerSortSurHeros()`). Le sort est celui que la capacité NOMME.
     *
     * ⚠ Un héros tombé, caché (*Voile de Brume*) ou à 0 PV n'est pas visé : on ne lance pas un sort
     * sur ce qui n'est pas là.
     *
     * Chaque lancer est annoncé d'une ligne `filet_silencieux` (qui, sur qui, quel sort), puis du
     * payload `sort_dread` habituel — dés, dégâts, chute.
     *
     * @return list<array<string, mixed>>
     */
    public function filetsSilencieux(Groupe $groupe, Quete $quete, Personnage $heros, ?EtatPersonnageQuete $etat): array
    {
        if ($etat === null || $etat->tombe || $etat->position_x === null) {
            return [];
        }

        $porteuses = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)
            ->with('monstre')->orderBy('id')->get()
            ->filter(fn (InstanceMonstre $m) => is_array($m->capaciteParametree('filet_silencieux')))
            ->values();

        if ($porteuses->isEmpty() || app(MoteurSorts::class)->estInattaquable($heros)) {
            return [];
        }

        $dread = app(MoteurDread::class);
        $sorts = app(MoteurSorts::class);
        $payloads = [];

        foreach ($porteuses as $porteuse) {
            $nomSort = $porteuse->capaciteParametree('filet_silencieux')['sort'] ?? null;

            if (! is_string($nomSort) || $nomSort === ''
                || ! $dread->peutAgir($porteuse)
                || $sorts->monstreA($porteuse, MoteurSorts::MONSTRE_ENFUME)
                || (int) $heros->fresh()->pv_body <= 0
                || ! $dread->voitLeHeros($quete, $porteuse, $etat)) {
                continue;
            }

            $nomPorteuse = $porteuse->nomAffiche();
            $annonce = [
                'type' => 'filet_silencieux',
                'monstre' => $nomPorteuse,
                'instance_id' => (int) $porteuse->id,
                'cible' => ['personnage_id' => (int) $heros->id, 'nom' => $heros->nom],
                'sort' => $nomSort,
            ];
            Journal::ajouter($groupe, 'action', $annonce, ['type' => 'monstre', 'id' => $porteuse->id, 'nom' => $nomPorteuse]);

            $cast = $dread->lancerSortSurHeros($groupe, $quete, $porteuse, $nomSort, $etat->fresh());

            // Un sort absent du catalogue ne lève jamais en pleine phase : l'annonce reste, honnête.
            $payloads[] = $annonce;

            if ($cast !== null) {
                $payloads[] = $cast;
            }

            if ((int) $heros->fresh()->pv_body <= 0) {
                break; // tombé sous le premier filet : la suivante n'a plus personne à viser
            }
        }

        return $payloads;
    }

    // ------------------------------------------------------------------
    // Golem Cœur-de-Forge — Breath of the Construct
    // ------------------------------------------------------------------

    /**
     * « Breath Of the Construct – This attack inflicts 2 Body Points of damage to all heroes
     * located in the same room or corridor. Each affected hero then rolls two red dice. For each
     * 5 or 6 rolled, the damage is reduced by 1 point. » — « a special ability it can use ONCE as
     * an action » (F9907 p. 23).
     *
     * Une ACTION : elle remplace le reste du tour du Golem (`ResolveurTour::jouerMonstreEveille()`
     * la lit avant tout). Une SEULE fois par quête : `capacites_reactives_utilisees` (colonne, donc
     * dans le snapshot) porte la dépense.
     *
     * ⚠ QUAND — aucune carte ne le dit, c'est une politique de portage écrite : le Golem souffle
     * dès que le souffle toucherait au moins DEUX héros — ou le seul debout, pour un groupe réduit
     * à un héros, sans quoi la capacité n'existerait jamais pour lui. Jamais sur un seul héros
     * quand d'autres tiennent encore : un souffle unique gaspillé sur un seul n'est pas ce que la
     * carte offre.
     *
     * La zone est « the same room OR CORRIDOR » (`MoteurDread::casesDeSalleOuCouloir()`, la même
     * que la Nuée d'Effroi). Les dés sont des d6 BRUTS (`DeRouge`) : chaque 5 ou 6 retire 1 point,
     * plancher 0. Les dégâts passent par `MoteurDegats::infligerAHeros()` — réductions de talent et
     * réactions hors tour comprises, comme tout coup de monstre.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles  les héros debout et attaquables
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>|null
     */
    public function souffle(Groupe $groupe, Quete $quete, InstanceMonstre $golem, Collection $cibles, array $acteur): ?array
    {
        $param = $golem->capaciteParametree('souffle_du_construit');

        if (! is_array($param) || in_array(self::USAGE_SOUFFLE, (array) ($golem->capacites_reactives_utilisees ?? []), true)) {
            return null;
        }

        $dread = app(MoteurDread::class);
        $dansLaZone = $dread->herosDansLesCases(
            new \Illuminate\Database\Eloquent\Collection($cibles->all()),
            $dread->casesDeSalleOuCouloir($quete, $golem),
        );

        if ($dansLaZone->isEmpty() || $dansLaZone->count() < min(2, $cibles->count())) {
            return null;
        }

        $golem->consommerReaction(self::USAGE_SOUFFLE);

        $bruts = max(0, (int) ($param['degats'] ?? 2));
        $nbDes = max(0, (int) ($param['des_rouges'] ?? 2));
        $des = app(LanceurDes::class);
        $degatsService = app(MoteurDegats::class);
        $sorts = app(MoteurSorts::class);
        $nomGolem = $golem->nomAffiche();
        $resultats = [];

        foreach ($dansLaZone as $cible) {
            $personnage = $cible->personnage;

            $faces = [];
            $annules = 0;

            for ($i = 0; $i < $nbDes; $i++) {
                $face = $des->d6();
                $faces[] = $face;

                if (DeRouge::reussit($face)) {
                    $annules++;
                }
            }

            $degats = max(0, $bruts - $annules);

            $subis = $degatsService->infligerAHeros(
                $personnage, $degats, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
                ['monstre' => $nomGolem, 'instance_id' => (int) $golem->id],
            );
            $sorts->reveillerHeros($personnage);

            $tombe = (int) $personnage->fresh()->pv_body === 0 && $subis > 0;

            if ($tombe) {
                $cible->update(['tombe' => true]);
            }

            $resultats[] = [
                'cible' => ['personnage_id' => (int) $personnage->id, 'nom' => $personnage->nom],
                'des_rouges' => $faces,
                'degats_bruts' => $bruts,
                'degats_annules' => min($bruts, $annules),
                'degats' => $subis,
                'pv_body_apres' => (int) $personnage->fresh()->pv_body,
                'cible_tombee' => $tombe,
            ];
        }

        $payload = [
            'type' => 'souffle_du_construit',
            'monstre' => $nomGolem,
            'instance_id' => (int) $golem->id,
            'resultats' => $resultats,
        ];
        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    // ------------------------------------------------------------------
    // Scion Fleur-Pourrie — les Vrilles
    // ------------------------------------------------------------------

    /**
     * « At the end of the Bloomrot Scion's turn, place one Tendril in an unoccupied square as
     * close as possible to the Bloomrot Scion » (F9907 p. 39).
     *
     * « As close as possible » : la case libre la plus proche au sens du JEU — d'abord la distance
     * de Tchebychev à la case du Scion (le balayage diagonal compte pour un), puis le coût du
     * chemin, puis l'ordre de lecture, de quoi rester DÉTERMINISTE. Les cases candidates sont celles
     * où une figure peut réellement s'ARRÊTER (`Grille::casesAtteignables()` : ni mur, ni mobilier,
     * ni porte close, ni figure, ni mare, ni brasier) ; les autres monstres se traversent
     * (`franchitAllies`), de sorte qu'une Vrille déjà posée n'emmure pas la suivante. Aucune case
     * libre à portée (12 points de déplacement) : rien n'est posé, et la ligne le dit.
     *
     * Le Scion n'a pas de tour s'il ne peut pas agir (paralysé…) : alors aucune Vrille. Chaque
     * Vrille garde le Scion pour MAÎTRE (`dread_etat.maitre`) — c'est d'elle seule que le sacrifice
     * se sert. Posée APRÈS le tour du Scion, elle ne joue qu'au round suivant (la boucle de
     * `phaseMonstres()` a chargé sa liste avant).
     *
     * @return array<string, mixed>|null
     */
    public function vrilleEnFinDeTour(Groupe $groupe, Quete $quete, InstanceMonstre $scion): ?array
    {
        $param = $scion->capaciteParametree('vrille_en_fin_de_tour');

        if (! is_array($param) || ! is_string($param['creature'] ?? null)
            || $scion->position_x === null || ! app(MoteurDread::class)->peutAgir($scion)) {
            return null;
        }

        $modele = Monstre::query()->where('nom_base', $param['creature'])->first();

        if ($modele === null) {
            return null; // donnée absente : jamais une exception en pleine phase
        }

        $sx = (int) $scion->position_x;
        $sy = (int) $scion->position_y;
        $grille = FabriqueGrille::pour($quete, exceptInstanceId: $scion->id, franchitAllies: true);
        $meilleure = null; // [tchebychev, coût, y, x]

        foreach ($grille->casesAtteignables($sx, $sy, 12) as $cle => $chemin) {
            [$x, $y] = array_map(intval(...), explode(',', $cle));
            $score = [max(abs($x - $sx), abs($y - $sy)), $grille->coutChemin($chemin), $y, $x];

            if ($meilleure === null || $score < $meilleure) {
                $meilleure = $score;
            }
        }

        $acteur = ['type' => 'monstre', 'id' => $scion->id, 'nom' => $scion->nomAffiche()];
        $payload = [
            'type' => 'vrille_posee',
            'monstre' => $scion->nomAffiche(),
            'instance_id' => (int) $scion->id,
            'vrille' => null,
        ];

        if ($meilleure !== null) {
            $vrille = $quete->instancesMonstres()->create([
                'monstre_id' => $modele->id,
                'pv_body' => $modele->pv_body,
                'pv_body_max' => $modele->pv_body,
                'pv_mind' => $modele->pv_mind,
                'position_x' => $meilleure[3],
                'position_y' => $meilleure[2],
                'etat' => 'actif',
                'elite' => false,
                'revele' => true, // posée sous les yeux des héros : jamais un dormant
            ]);
            $vrille->poserEtatDread('maitre', (int) $scion->id);

            $payload['vrille'] = [
                'instance_id' => (int) $vrille->id,
                'nom' => $vrille->nomAffiche(),
                'x' => (int) $meilleure[3],
                'y' => (int) $meilleure[2],
            ];
        }

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * « If the Bloomrot Scion would be reduced to 0 body points, it may sacrifice one of its Tendrils
     * and instead be reduced to 1 Body Point » (F9907 p. 39, astérisque du Body).
     *
     * Lu par `MoteurDegats::infligerAMonstre()`, seul point de passage de la mort d'un monstre :
     * rend la Vrille sacrifiée (déjà retirée du plateau, état `vaincu`) ou `null` quand le Scion n'a
     * plus de Vrille — il meurt alors normalement. Politique de portage écrite (la carte dit « may »
     * et ne dit pas quand) : le Scion sacrifie TOUJOURS, au coup qui le tuerait — jamais gaspillée
     * sur une égratignure — et autant de fois qu'il lui reste de Vrilles, la carte ne bornant pas
     * le nombre de sacrifices. La plus ANCIENNE Vrille part la première (ordre d'id, déterministe).
     *
     * Sa Vrille est SACRIFIÉE, pas abattue : elle ne passe pas par `infligerAMonstre()` (pas de
     * récompense, pas de réaction de mort).
     *
     * @return array{instance_id: int, nom: string, x: int|null, y: int|null}|null
     */
    public function vrilleASacrifier(InstanceMonstre $scion): ?array
    {
        $param = $scion->capaciteParametree('vrille_en_fin_de_tour');
        $quete = $scion->quete;

        if (! $scion->aCapacite('sacrifice_de_vrille') || ! is_array($param)
            || ! is_string($param['creature'] ?? null) || $quete === null) {
            return null;
        }

        $vrille = $quete->instancesMonstres()->where('etat', 'actif')
            ->whereHas('monstre', fn ($q) => $q->where('nom_base', $param['creature']))
            ->orderBy('id')->get()
            ->first(fn (InstanceMonstre $v) => (int) $v->etatDread('maitre', 0) === (int) $scion->id);

        if ($vrille === null) {
            return null;
        }

        $sacrifice = [
            'instance_id' => (int) $vrille->id,
            'nom' => $vrille->nomAffiche(),
            'x' => $vrille->position_x === null ? null : (int) $vrille->position_x,
            'y' => $vrille->position_y === null ? null : (int) $vrille->position_y,
        ];

        $vrille->update(['pv_body' => 0, 'etat' => 'vaincu']);

        return $sacrifice;
    }
}
