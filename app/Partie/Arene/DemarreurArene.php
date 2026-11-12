<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Events\EtatGroupeDiffuse;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\EtatGroupe;
use App\Partie\Grille;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use App\Support\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MODE ARÈNE, lot T1 — le démarrage d'un combat d'arène, pendant de `DemarreurQuete::demarrer()` (fermé à l'arène,
 * `EtapesCampagne::DEMARRAGE_QUETE`).
 *
 * Ce qu'il fait : le hall fixe (`HallArene`), les doublures du Challenger sur leur tuile de départ, le Defender sur la
 * sienne, l'état diffusé à la table et un menu par héros. Ce qu'il ne fait PAS, par construction : ni gabarit, ni
 * thème, ni jalon, ni deck de fouille, ni instantané, ni narration ni habillage par l'IA (AQ5 — un monstre garde son
 * nom de catalogue).
 *
 * Le Defender est donné par l'appelant (la composition à puissance d'équipe est le lot T3). Le tour est l'ACTIVATION
 * ALTERNÉE (lot T2) : le Defender ouvre le combat, puis chaque activation de héros reçoit sa réponse
 * (`ResolveurTour::ouvrirCombatArene()`, `relaisArene()`, `OrdreDuTour::acteurPour()`).
 */
final class DemarreurArene
{
    public function __construct(
        private readonly HallArene $hall,
        private readonly MoteurSorts $sorts,
        private readonly MoteurDread $dread,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /**
     * @param  list<int>  $defender  ids `monstres` du catalogue, un par figure
     */
    public function demarrer(Groupe $arene, array $defender): Quete
    {
        if (DB::table('groupes')->where('id', $arene->id)->value('mode') !== Groupe::MODE_ARENE) {
            throw RefusArene::pasUnGroupeDArene($arene);
        }

        if ($arene->phase !== 'hub') {
            throw ValidationException::withMessages(['groupe' => 'Ce combat d\'arène a déjà commencé.']);
        }

        $challenger = $arene->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->orderBy('personnages.id')
            ->get();

        if ($challenger->isEmpty()) {
            throw ValidationException::withMessages(['groupe' => 'Aucun combattant dans l\'arène.']);
        }

        // Seules des doublures combattent : un vrai héros n'entre jamais dans l'arène (plan §2).
        $vrai = $challenger->first(fn (Personnage $p) => ! $p->estDoublure());
        if ($vrai !== null) {
            throw RefusArene::pasUneDoublure($vrai);
        }

        $monstres = $this->monstres($defender);
        $carte = $this->hall->carte();

        // COFFRES DU TOURNOI (lot T5, F9528 p. 13 et plan p. 15) : deux coffres du catalogue, fouillables « adjacent or
        // diagonal » (`diagonale`, lu par `MoteurMobilier::adjacentAEmprise()`) ; leur fouille tire la table 2d6
        // (`ResolveurTour::tresorDuTournoi()`), puis le coffre quitte le plateau.
        $coffre = \App\Models\Mobilier::where('nom', 'Coffre')->value('id');
        if ($coffre !== null) {
            foreach (HallArene::COFFRES as [$c, $r]) {
                [$x, $y] = $this->hall->caseDeLaSalle($c, $r);
                $carte['mobilier'][] = ['mobilier_id' => (int) $coffre, 'x' => $x, 'y' => $y, 'l' => 1, 'h' => 1, 'salle' => 0,
                    'diagonale' => true, 'tresor_tournoi' => true];
            }
        }
        $positions = $this->placerDefender($monstres, $carte);

        // ALLIÉS (lot A4) : l'allié choisi au salon par chaque combattant, posé sur la tuile du Challenger après les héros.
        $allies = $challenger->filter(fn (Personnage $p) => $p->pivot->allie_arene_id !== null)->values();
        $catalogue = \App\Models\Mercenaire::whereIn('id', $allies->pluck('pivot.allie_arene_id'))->get()->keyBy('id');

        if (count($carte['spawn_heros']) < $challenger->count() + $allies->count()) {
            throw ValidationException::withMessages(['groupe' => 'Trop de combattants pour la tuile de départ du hall.']);
        }

        $quete = DB::transaction(function () use ($arene, $challenger, $monstres, $carte, $positions, $allies, $catalogue): Quete {
            $quete = Quete::create([
                'groupe_id' => $arene->id,
                'gabarit_id' => null,
                'titre' => 'Arène',
                'position_arc' => 1,
                'type_jalon' => 'normale',
                'objectif_majeur' => false,
                'etat' => 'en_cours',
                'or_initial' => 0,
                // Une seule salle, toujours découverte : pas de brouillard dans le hall.
                'salles_decouvertes' => [0],
                'tresors_fouilles' => [],
                'deck_fouille' => [],
                'salles_coffre' => [],
                'coffres_ouverts' => [],
            ]);

            Carte::create([
                'quete_id' => $quete->id,
                'largeur' => $carte['largeur'],
                'hauteur' => $carte['hauteur'],
                'grille' => $carte,
            ]);

            // TROPHÉES (lot T4) : le lot de la p. 14, mélangé, face cachée sur les six cases du plan.
            app(TropheesArene::class)->poser($quete);

            foreach ($monstres as $i => $monstre) {
                // Stats du CATALOGUE, sans adaptation à la taille du groupe ni tirage d'élite : en arène, l'équilibre
                // est la puissance d'équipe (lot T3), pas l'adaptation des PV.
                InstanceMonstre::create([
                    'quete_id' => $quete->id,
                    'monstre_id' => $monstre->id,
                    'pv_body' => (int) $monstre->pv_body,
                    'pv_body_max' => (int) $monstre->pv_body,
                    'pv_mind' => (int) $monstre->pv_mind,
                    'position_x' => $positions[$i]['x'],
                    'position_y' => $positions[$i]['y'],
                    'etat' => 'actif',
                    'elite' => false,
                    // Le Defender est caché derrière l'écran jusqu'au départ (p. 12) : au départ, il est révélé.
                    'revele' => true,
                ]);
            }

            foreach ($challenger as $i => $doublure) {
                $arene->personnages()->updateExistingPivot($doublure->id, ['ordre_initiative' => $i + 1]);
                $this->sorts->reinitialiserQuete($arene, $doublure);

                $quete->etatsPersonnages()->create([
                    'personnage_id' => $doublure->id,
                    'position_x' => $carte['spawn_heros'][$i]['x'],
                    'position_y' => $carte['spawn_heros'][$i]['y'],
                    'a_joue' => false,
                    'tombe' => false,
                ]);
            }

            // Un combat neuf, des alliés neufs : ceux d'un combat précédent n'existent plus.
            GroupeMercenaire::where('groupe_id', $arene->id)->delete();

            foreach ($allies as $j => $doublure) {
                $mercenaire = $catalogue->get((int) $doublure->pivot->allie_arene_id);
                if ($mercenaire === null) {
                    continue;
                }

                $case = $carte['spawn_heros'][$challenger->count() + $j];
                GroupeMercenaire::create([
                    'groupe_id' => $arene->id,
                    'mercenaire_id' => $mercenaire->id,
                    'recruteur_personnage_id' => $doublure->id,
                    'pv_body' => (int) $mercenaire->pv_body,
                    'position_x' => $case['x'],
                    'position_y' => $case['y'],
                    'etat' => 'actif',
                    'a_deplace' => false, 'a_agi' => false, 'a_joue' => false,
                ]);
            }

            $arene->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

            return $quete;
        });

        $this->dread->reinitialiserUsages($quete);

        // ACTIVATION ALTERNÉE (lot T2) : « the Defender team starts the first round » (livret F9528 p. 16) — une
        // figure du Defender s'active avant que le premier héros ne joue.
        app(ResolveurTour::class)->ouvrirCombatArene($arene->refresh(), $quete->refresh());

        Journal::ajouter($arene, 'systeme', [
            'action' => 'quete_demarree',
            'quete_id' => $quete->id,
            'titre' => $quete->titre,
            'arene' => true,
            'nb_monstres' => count($monstres),
            'nb_allies' => $allies->count(),
        ]);

        broadcast(new EtatGroupeDiffuse($arene, $this->etatGroupe->payload($arene->refresh())));

        foreach ($challenger as $doublure) {
            GenererMenu::dispatch($arene->id, (int) $doublure->joueur_id, (int) $doublure->id);
        }

        return $quete;
    }

    /**
     * MONSTER VS MONSTER (lot A9c) — le démarrage : le hall, le camp Defender sur la tuile est, le Challenger sur la
     * tuile ouest, chaque figure marquée de son camp. Ni héros, ni trophées, ni coffres (⚠ écart nommé : les trophées
     * et le trésor sont ramassés par des héros ou par « le » Defender ; entre deux camps de monstres, le livret n'en dit
     * rien). Le Defender a la main le premier (« the Defender team starts the first round »).
     */
    public function demarrerMonstres(Groupe $arene): Quete
    {
        $mvm = app(MonstresContreMonstres::class);
        $salon = $mvm->salon($arene);

        if (! $arene->estMonstresContreMonstres() || $salon['challenger'] === null || $salon['tour'] !== null
            || $salon['selection']['a'] === [] || $salon['selection']['b'] === []) {
            throw ValidationException::withMessages(['arene' => $mvm->motifLancement($arene)]);
        }

        if ($arene->phase !== 'hub' || $arene->quetes()->exists()) {
            throw ValidationException::withMessages(['groupe' => 'Ce combat d\'arène a déjà commencé.']);
        }

        $challenger = $salon['challenger'];
        $defender = $challenger === 'a' ? 'b' : 'a';
        $carte = $this->hall->carte();
        $camps = [
            $defender => ['monstres' => $this->monstres($salon['selection'][$defender]), 'tuile' => 'spawn_monstres'],
            $challenger => ['monstres' => $this->monstres($salon['selection'][$challenger]), 'tuile' => 'spawn_heros'],
        ];
        foreach ($camps as $camp => $c) {
            $camps[$camp]['positions'] = $this->placerDefender($c['monstres'], $carte, $c['tuile']);
        }

        $quete = DB::transaction(function () use ($arene, $carte, $camps, $defender): Quete {
            $quete = Quete::create([
                'groupe_id' => $arene->id, 'gabarit_id' => null, 'titre' => 'Monster vs Monster', 'position_arc' => 1,
                'type_jalon' => 'normale', 'objectif_majeur' => false, 'etat' => 'en_cours', 'or_initial' => 0,
                'salles_decouvertes' => [0], 'tresors_fouilles' => [], 'deck_fouille' => [], 'salles_coffre' => [],
                'coffres_ouverts' => [],
            ]);

            Carte::create(['quete_id' => $quete->id, 'largeur' => $carte['largeur'], 'hauteur' => $carte['hauteur'], 'grille' => $carte]);

            foreach ($camps as $camp => $c) {
                foreach ($c['monstres'] as $i => $monstre) {
                    InstanceMonstre::create([
                        'quete_id' => $quete->id, 'monstre_id' => $monstre->id,
                        'pv_body' => (int) $monstre->pv_body, 'pv_body_max' => (int) $monstre->pv_body,
                        'pv_mind' => (int) $monstre->pv_mind,
                        'position_x' => $c['positions'][$i]['x'], 'position_y' => $c['positions'][$i]['y'],
                        'etat' => 'actif', 'elite' => false, 'revele' => true, 'camp_arene' => (string) $camp,
                    ]);
                }
            }

            $arene->update(['phase' => 'quete', 'quete_courante_id' => $quete->id,
                'arene_mvm' => [...(array) $arene->arene_mvm, 'main' => $defender, 'gagnant' => null]]);

            return $quete;
        });

        $this->dread->reinitialiserUsages($quete);

        Journal::ajouter($arene, 'systeme', ['action' => 'quete_demarree', 'quete_id' => $quete->id, 'titre' => $quete->titre,
            'arene' => true, 'monster_vs_monster' => true]);
        broadcast(new EtatGroupeDiffuse($arene, $this->etatGroupe->payload($arene->refresh())));

        return $quete;
    }

    /**
     * @param  list<int>  $ids
     * @return list<Monstre>
     */
    private function monstres(array $ids): array
    {
        if ($ids === []) {
            throw ValidationException::withMessages(['defender' => 'Le Defender doit compter au moins une figure.']);
        }

        /** @var Collection<int, Monstre> $catalogue */
        $catalogue = Monstre::whereIn('id', array_unique($ids))->get()->keyBy('id');

        return array_map(function (int $id) use ($catalogue): Monstre {
            $monstre = $catalogue->get($id);
            if ($monstre === null) {
                throw ValidationException::withMessages(['defender' => "Monstre #{$id} absent du catalogue."]);
            }

            return $monstre;
        }, array_values($ids));
    }

    /**
     * Chaque figure du Defender sur sa tuile de départ : la première case de la tuile où TOUTE son emprise tient, sur
     * le sol, sans chevaucher une figure déjà posée. Les grandes figures d'abord, pour qu'aucune petite ne leur prenne
     * leur place.
     *
     * @param  list<Monstre>  $monstres
     * @param  array<string, mixed>  $carte
     * @return array<int, array{x: int, y: int}>
     */
    private function placerDefender(array $monstres, array $carte, string $tuile = 'spawn_monstres'): array
    {
        $ordre = array_keys($monstres);
        usort($ordre, fn (int $a, int $b) => ($monstres[$b]->emprise()['l'] * $monstres[$b]->emprise()['h'])
            <=> ($monstres[$a]->emprise()['l'] * $monstres[$a]->emprise()['h']) ?: $a <=> $b);

        $prises = [];
        $positions = [];

        foreach ($ordre as $i) {
            $e = $monstres[$i]->emprise();
            $place = null;

            foreach ($carte[$tuile] as $ancre) {
                $cellules = Grille::cellulesEmprise((int) $ancre['x'], (int) $ancre['y'], $e['l'], $e['h']);
                $libre = array_reduce($cellules, fn (bool $ok, array $c) => $ok
                    && ($carte['cases'][$c['y']][$c['x']] ?? 'm') === 's'
                    && ! isset($prises["{$c['x']},{$c['y']}"]), true);

                if ($libre) {
                    $place = $ancre;
                    foreach ($cellules as $c) {
                        $prises["{$c['x']},{$c['y']}"] = true;
                    }
                    break;
                }
            }

            if ($place === null) {
                throw ValidationException::withMessages(['defender' => 'Le Defender ne tient pas sur sa tuile de départ.']);
            }

            $positions[$i] = ['x' => (int) $place['x'], 'y' => (int) $place['y']];
        }

        ksort($positions);

        return $positions;
    }
}
