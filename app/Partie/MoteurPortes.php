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
     * ⚠ « Adjacente » teste désormais l'ADJACENCE GÉOMÉTRIQUE à la case
     * d'EMBRASURE (`Grille::caseEmbrasure()`), pas l'égalité à l'une des deux
     * `casesPorte()` (René, 2026-09-11 : une porte non ouverte bloque
     * maintenant sa case). L'ancien test supposait qu'on pouvait se TENIR sur
     * le seuil pour l'ouvrir ; ce n'est plus vrai QUE côté couloir, plus côté
     * salle — un héros qui vient d'entrer par un autre accès et s'arrête
     * juste devant une porte close, depuis l'INTÉRIEUR, ne serait jamais sur
     * aucune des deux `casesPorte()` (l'embrasure lui est fermée, et l'autre
     * cellule est souvent à plus d'un pas). Sans ce changement, une porte dont
     * l'embrasure tombe côté salle ne serait plus jamais ouvrable de
     * l'intérieur : personne ne pourrait jamais se tenir assez près pour
     * presser « Ouvrir la porte ».
     *
     * @return array{index: int, porte: array<string, mixed>}|null
     */
    public function porteFermeeAdjacente(Carte $carte, int $x, int $y): ?array
    {
        $reponse = null;
        $salles = (array) ($carte->grille['salles'] ?? []);

        foreach ($this->portes($carte) as $index => $porte) {
            if (($porte['etat'] ?? self::ETAT_OUVERTE) === self::ETAT_OUVERTE) {
                continue;
            }
            $embrasure = Grille::caseEmbrasure($porte, $salles);
            if (abs($embrasure['x'] - $x) + abs($embrasure['y'] - $y) !== 1) {
                continue;
            }

            // Une porte OUVRABLE l'emporte toujours. Rendre la première close
            // venue masquait une porte parfaitement ouvrable derrière une porte
            // SECRÈTE adjacente (l'appelant ne teste l'ouvrabilité qu'après) :
            // le héros se retrouvait sans option « Ouvrir la porte » devant une
            // porte qu'il voyait — la signature d'un groupe figé.
            if ($this->ouvrableAMain($porte)) {
                return ['index' => $index, 'porte' => $porte];
            }

            $reponse ??= ['index' => $index, 'porte' => $porte];
        }

        return $reponse;
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
     * (Manhattan) autour du fouilleur — elles passent `revele:true` + FERMÉES.
     * Trouver n'est pas franchir : il reste à les ouvrir.
     *
     * @return list<array{x: int, y: int}> portes révélées
     */
    public function revelerSecretesAutour(Groupe $groupe, Carte $carte, Personnage $personnage, Grille $grille, int $x, int $y): array
    {
        // ⚠ LIGNE DE VUE ajoutée le 2026-09-18, en même temps que celle de
        // `MoteurPieges::revelerAutour()` — MÊME défaut, MÊME ligne, dans la
        // jumelle appelée deux lignes plus loin par la fouille de zone. René
        // n'avait signalé que le piège vu derrière une porte fermée ; corriger
        // le piège sans la porte secrète aurait laissé le défaut identique dans
        // le geste identique, à retrouver au prochain playtest.
        // `revelerSecretesEnVue()` filtrait déjà ainsi : la fouille n'avait
        // simplement jamais reçu de grille.
        return $this->revelerSecretes(
            $groupe, $carte, $personnage,
            fn (array $porte) => abs((int) $porte['x'] - $x) + abs((int) $porte['y'] - $y) <= self::RAYON_FOUILLE
                && $grille->ligneDeVue($x, $y, (int) $porte['x'], (int) $porte['y']),
        );
    }

    /**
     * `detection_portes_secretes` (Parler à la pierre du nain, Lecture des lieux
     * de l'explorateur) : les portes secrètes ORTHOGONALEMENT adjacentes se
     * révèlent d'elles-mêmes, sans jet et sans action.
     *
     * ⚠ Le pendant exact de l'Œil du mineur pour les pièges, et volontairement
     * de la même portée : à rayon de fouille, le talent rendrait la fouille de
     * zone inutile pour son porteur ; à une case, il récompense l'exploration
     * prudente sans la remplacer.
     *
     * Sans le talent : aucun effet, la méthode rend une liste vide.
     *
     * @return list<array{x: int, y: int}> portes révélées
     */
    public function detecterSecretesAdjacentes(Groupe $groupe, Carte $carte, Personnage $personnage, int $x, int $y): array
    {
        $noeud = app(Talents::class)->noeud($personnage, 'detection_portes_secretes');

        if ($noeud === null) {
            return [];
        }

        $reveles = $this->revelerSecretes(
            $groupe, $carte, $personnage,
            fn (array $porte) => abs((int) $porte['x'] - $x) + abs((int) $porte['y'] - $y) === 1,
        );

        // Un talent qui s'active tout seul se VOIT (2026-09-25).
        if ($reveles !== []) {
            $nombre = count($reveles);
            app(AnnoncesTalents::class)->annoncer($personnage, $noeud, $nombre > 1
                ? "révèle {$nombre} portes secrètes adjacentes"
                : 'révèle une porte secrète adjacente');
        }

        return $reveles;
    }

    /**
     * POTION DE VISION (Elfe) : « see all secret doors […] within their line of
     * sight » (carte © 2023). Miroir exact de `MoteurPieges::revelerEnVue()` —
     * les deux moitiés de la potion doivent obéir à la même géométrie.
     *
     * @return list<array{x: int, y: int}>
     */
    public function revelerSecretesEnVue(Groupe $groupe, Carte $carte, Personnage $personnage, Grille $grille, int $x, int $y): array
    {
        return $this->revelerSecretes(
            $groupe, $carte, $personnage,
            fn (array $porte) => $grille->ligneDeVue($x, $y, (int) $porte['x'], (int) $porte['y']),
        );
    }

    /**
     * Le révélateur, dont seul le FILTRE change — factorisé quand la Potion de
     * vision a demandé une seconde géométrie. Deux copies de « une porte
     * secrète devient FERMÉE et révélée » auraient fini par diverger.
     *
     * @return list<array{x: int, y: int}>
     */
    private function revelerSecretes(Groupe $groupe, Carte $carte, Personnage $personnage, callable $filtre): array
    {
        $reveles = [];

        foreach ($this->portes($carte) as $index => $porte) {
            if (($porte['etat'] ?? null) !== self::ETAT_SECRETE || ($porte['revele'] ?? false) || ! $filtre($porte)) {
                continue;
            }

            // ⚠ FERMÉE, pas ouverte (arbitrage de René, 2026-09-11 : « un passage
            // secret trouvé devrait l'afficher comme une porte fermée, on peut
            // maintenant interagir avec pour l'ouvrir »). Trouver un passage et
            // le franchir sont DEUX actes, comme au plateau — et la fouille
            // révélait jusqu'ici la salle derrière du même geste, puisque toute
            // ouverture de porte révèle sa salle. Un jet de Mind réussi donnait
            // donc la pièce ET son coffre sans qu'on ait à s'en approcher.
            //
            // `revele` reste posé : l'état n'est plus `secrete`, donc le
            // déguisement en mur de `EtatGroupe::portes()` ne s'applique plus
            // — mais le drapeau garde la trace de CE QUI a été trouvé, et c'est
            // lui qui distingue une porte découverte d'une porte ordinaire.
            $this->changer($carte, $index, ['revele' => true, 'etat' => self::ETAT_FERMEE]);
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

        // Un SEUIL large de 2 cases est fait de deux arêtes-portes côte à côte
        // (AssembleurCarte) : elles s'ouvrent ENSEMBLE, sinon le passage
        // resterait un goulot d'une case — exactement ce que l'élargissement
        // vise à supprimer.
        //
        // ⚠ Seulement les portes du MÊME seuil, pas toute la jonction. Un
        // couloir a DEUX seuils (un par salle) qui partagent le même
        // `jonction` : les grouper tous ouvrait les 4 portes d'un coup, donc
        // celles du bout opposé du couloir. Le groupe arrivait au bout d'un
        // corridor et trouvait la porte déjà ouverte, sans jamais la pousser —
        // et la salle d'en face restait NON révélée, ouverte mais noire
        // (constaté en partie réelle par René, 2026-08-07).
        foreach ($this->portes($carte) as $autre => $p) {
            if ($autre !== $index
                && $this->memeSeuil($porte, $p)
                && ($p['etat'] ?? null) !== self::ETAT_OUVERTE) {
                $this->changer($carte, $autre, ['etat' => self::ETAT_OUVERTE, 'revele' => true]);
            }
        }

        Journal::ajouter($groupe, 'action', [
            'type' => 'porte_ouverte',
            'cause' => $cause,
            'porte' => ['x' => (int) $porte['x'], 'y' => (int) $porte['y'], 'cote' => (string) ($porte['cote'] ?? 'e')],
        ], $acteur);
    }

    /**
     * Deux portes forment-elles le MÊME seuil (les 2 voies d'un passage large) ?
     *
     * Même jonction ET même ligne de front : deux portes `e` du même seuil
     * partagent leur `x` (elles sont l'une au-dessus de l'autre), deux portes
     * `s` partagent leur `y`. Les deux seuils d'un couloir partagent la
     * jonction mais PAS cette coordonnée — c'est ce qui les distingue.
     *
     * Dérivé de la géométrie plutôt que d'un champ ajouté : les cartes déjà
     * en base sont ainsi corrigées elles aussi, sans régénération.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function memeSeuil(array $a, array $b): bool
    {
        if (($a['jonction'] ?? null) === null || ($a['jonction'] ?? null) !== ($b['jonction'] ?? null)) {
            return false;
        }

        $cote = (string) ($a['cote'] ?? 'e');

        if ($cote !== (string) ($b['cote'] ?? 'e')) {
            return false;
        }

        // Porte EST : l'arête est verticale, le seuil s'étend en Y → même X.
        // Porte SUD : l'arête est horizontale, le seuil s'étend en X → même Y.
        return $cote === 'e'
            ? (int) $a['x'] === (int) $b['x']
            : (int) $a['y'] === (int) $b['y'];
    }

    /**
     * Auto-ouverture des portes à verrou `monstres_vaincus` : toute porte
     * verrouillée dont TOUTES les instances désignées sont vaincues s'ouvre.
     * Appelé après chaque résolution de combat (hook post-action).
     *
     * @return list<array{x: int, y: int, cote: string}> portes ouvertes ce passage
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
                // On rend la porte COMPLÈTE (`cote` compris) : l'appelant en a
                // besoin pour révéler la salle derrière — une porte est une
                // arête, `x`/`y` seuls ne suffisent pas à retrouver ses deux cases.
                $ouvertes[] = [
                    'x' => (int) $porte['x'],
                    'y' => (int) $porte['y'],
                    'cote' => (string) ($porte['cote'] ?? 'e'),
                ];
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
