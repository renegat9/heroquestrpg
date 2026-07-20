<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Carte;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;

/**
 * Moteur des portes (doc 14 §3.1 portes secrètes + §3.3 portes à restriction) —
 * résolu en code, jamais par l'IA. L'ÉTAT des portes vit dans la grille JSON de
 * la carte (cartes.grille.portes), chaque entrée :
 *   {x, y, etat: ouverte|fermee|verrouillee|secrete, verrou?, revele?}
 *
 * Une porte NON `ouverte` est infranchissable et opaque (Grille::definirPortes).
 * `fermee` (correctifs E2) = simplement close, SANS verrou : n'importe quel héros
 * adjacent l'ouvre librement (action « Ouvrir la porte », interaction qui ne
 * consomme aucun créneau) — on s'arrête devant, on ouvre, on continue. C'est
 * l'état par défaut des portes inter-salles posées par AssembleurCarte.
 * Verrous gérés (décisions Vague 2) :
 *  - `cle`              : un héros adjacent possédant l'objet ouvre la porte ;
 *  - `monstres_vaincus` : ouverture auto quand toutes les instances désignées
 *                         sont vaincues (hook post-combat) ;
 *  - `levier`           : action « Actionner le levier » au contact d'un levier.
 * (« Traverser la Pierre » franchit déjà les murs ; le verrou `jet`-pour-forcer
 *  est reporté.)
 */
final class MoteurPortes
{
    /** Rayon (Manhattan) d'une fouille de zone — aligné sur MoteurPieges. */
    public const RAYON_FOUILLE = MoteurPieges::RAYON_FOUILLE;

    public const ETAT_OUVERTE = 'ouverte';

    /** Close mais SANS verrou : ouvrable librement par un héros adjacent (E2). */
    public const ETAT_FERMEE = 'fermee';

    public const ETAT_VERROUILLEE = 'verrouillee';

    public const ETAT_SECRETE = 'secrete';

    /** Une porte close sans verrou s'ouvre à la main, sans clé ni levier (E2). */
    public function ouvrableAMain(array $porte): bool
    {
        return ($porte['etat'] ?? self::ETAT_OUVERTE) === self::ETAT_FERMEE
            && ($porte['verrou']['type'] ?? null) === null;
    }

    /**
     * Portes brutes de la carte.
     *
     * @return list<array<string, mixed>>
     */
    public function portes(Carte $carte): array
    {
        return (array) ($carte->grille['portes'] ?? []);
    }

    /**
     * Met à jour (fusionne) l'entrée de porte d'index donné et persiste.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function changer(Carte $carte, int $index, array $attrs): void
    {
        $grille = $carte->grille;

        if (! isset($grille['portes'][$index])) {
            return;
        }

        $grille['portes'][$index] = array_merge($grille['portes'][$index], $attrs);
        $carte->update(['grille' => $grille]);
    }

    /**
     * Porte FERMÉE (verrouillée, ou secrète non révélée) orthogonalement
     * adjacente à (x, y), avec son index — null sinon.
     *
     * @return array{index: int, porte: array<string, mixed>}|null
     */
    public function porteFermeeAdjacente(Carte $carte, int $x, int $y): ?array
    {
        foreach ($this->portes($carte) as $index => $porte) {
            if (($porte['etat'] ?? self::ETAT_OUVERTE) === self::ETAT_OUVERTE) {
                continue;
            }
            // Porte = arête : ouvrable depuis L'UNE des deux cases qu'elle sépare.
            [$a, $b] = Grille::casesPorte($porte);
            if (($a['x'] === $x && $a['y'] === $y) || ($b['x'] === $x && $b['y'] === $y)) {
                return ['index' => $index, 'porte' => $porte];
            }
        }

        return null;
    }

    /** Leviers orthogonalement adjacents à (x, y). @return list<array{x: int, y: int, levier_id: string}> */
    public function leviersAdjacents(Carte $carte, int $x, int $y): array
    {
        $adjacents = [];

        foreach ((array) ($carte->grille['leviers'] ?? []) as $levier) {
            if (abs((int) $levier['x'] - $x) + abs((int) $levier['y'] - $y) === 1) {
                $adjacents[] = [
                    'x' => (int) $levier['x'],
                    'y' => (int) $levier['y'],
                    'levier_id' => (string) ($levier['levier_id'] ?? ''),
                ];
            }
        }

        return $adjacents;
    }

    /**
     * Fouille RÉUSSIE : révèle les portes SECRÈTES dans le rayon de fouille
     * (Manhattan) autour du fouilleur — elles passent `revele:true` + ouvertes.
     *
     * @return list<array{x: int, y: int}> portes révélées
     */
    public function revelerSecretesAutour(Groupe $groupe, Carte $carte, Personnage $personnage, int $x, int $y): array
    {
        $reveles = [];

        foreach ($this->portes($carte) as $index => $porte) {
            if (($porte['etat'] ?? null) !== self::ETAT_SECRETE || ($porte['revele'] ?? false)) {
                continue;
            }
            if (abs((int) $porte['x'] - $x) + abs((int) $porte['y'] - $y) > self::RAYON_FOUILLE) {
                continue;
            }

            $this->changer($carte, $index, ['revele' => true, 'etat' => self::ETAT_OUVERTE]);
            $reveles[] = ['x' => (int) $porte['x'], 'y' => (int) $porte['y']];
        }

        if ($reveles !== []) {
            Journal::ajouter($groupe, 'action', [
                'type' => 'portes_secretes_revelees',
                'portes' => $reveles,
            ], ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom]);
        }

        return $reveles;
    }

    /**
     * Ouvre la porte fermée d'index donné (verrou satisfait) — état persistant.
     * Journalise l'ouverture.
     *
     * @param  array<string, mixed>|null  $acteur
     */
    public function ouvrir(Groupe $groupe, Carte $carte, int $index, string $cause, ?array $acteur = null): void
    {
        $porte = $this->portes($carte)[$index] ?? null;

        if ($porte === null) {
            return;
        }

        $this->changer($carte, $index, ['etat' => self::ETAT_OUVERTE, 'revele' => true]);

        Journal::ajouter($groupe, 'action', [
            'type' => 'porte_ouverte',
            'cause' => $cause,
            'porte' => ['x' => (int) $porte['x'], 'y' => (int) $porte['y'], 'cote' => (string) ($porte['cote'] ?? 'e')],
        ], $acteur);
    }

    /**
     * Auto-ouverture des portes à verrou `monstres_vaincus` : toute porte
     * verrouillée dont TOUTES les instances désignées sont vaincues s'ouvre.
     * Appelé après chaque résolution de combat (hook post-action).
     *
     * @return list<array{x: int, y: int}> portes ouvertes ce passage
     */
    public function ouvrirParMonstresVaincus(Groupe $groupe, Quete $quete): array
    {
        $carte = $quete->carte;

        if ($carte === null) {
            return [];
        }

        $ouvertes = [];

        foreach ($this->portes($carte) as $index => $porte) {
            if (($porte['etat'] ?? null) === self::ETAT_OUVERTE) {
                continue;
            }
            if (($porte['verrou']['type'] ?? null) !== 'monstres_vaincus') {
                continue;
            }

            $instances = array_map('intval', (array) ($porte['verrou']['instances'] ?? []));

            if ($instances === []) {
                continue;
            }

            $restants = $quete->instancesMonstres()
                ->whereIn('id', $instances)
                ->where('etat', '!=', 'vaincu')
                ->count();

            if ($restants === 0) {
                $this->ouvrir($groupe, $carte, $index, 'monstres_vaincus');
                $ouvertes[] = ['x' => (int) $porte['x'], 'y' => (int) $porte['y']];
            }
        }

        return $ouvertes;
    }

    /**
     * Le héros possède-t-il l'objet-clé d'un verrou `cle` ?
     */
    public function possedeCle(Personnage $personnage, array $verrou): bool
    {
        $objetId = (int) ($verrou['objet_id'] ?? 0);

        return $objetId > 0 && $personnage->inventaire()->where('objet_id', $objetId)->exists();
    }
}
