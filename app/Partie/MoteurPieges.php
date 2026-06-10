<?php

declare(strict_types=1);

namespace App\Partie;

use App\Jobs\GenererNarration;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Piege;
use App\Support\Journal;

/**
 * Moteur des pièges (doc 10) — résolu en code, jamais par l'IA (l'IA ne fait
 * qu'habiller le nom/la description, l'effet du catalogue est inchangé).
 *
 * L'ÉTAT des pièges vit dans la grille JSON de la carte de la quête
 * (cartes.grille.pieges, posé par AssembleurCarte) : chaque entrée
 * {x, y, piege_id, etat} suit le cycle de vie doc 10 §2 :
 * `cache` → `detecte` (fouille réussie / Œil du mineur) → `desarme` /
 * `declenche` (marché dessus, désamorçage raté, chute au franchissement).
 *
 * Choix MVP (questions ouvertes doc 10 §10, départ playtest) :
 *  - dégâts = effet.degats_pv_body du catalogue (1 partout) ;
 *  - une fosse (effet.franchissable) IMMOBILISE : le déplacement s'arrête
 *    sur sa case ; persistante, elle reste en jeu (`detecte`) après
 *    déclenchement — les pièges à usage unique passent à `declenche` ;
 *  - la fouille réussie révèle les pièges cachés dans un RAYON de 3 cases
 *    (distance de Manhattan) autour du fouilleur ;
 *  - l'Œil du mineur (nœud nain) détecte les pièges ORTHOGONALEMENT
 *    adjacents, à chaque début d'action et après chaque déplacement.
 */
final class MoteurPieges
{
    /** Rayon (Manhattan) révélé par une fouille réussie — départ playtest. */
    public const RAYON_FOUILLE = 3;

    /** Nom exact du nœud nain de détection automatique (CompetenceSeeder). */
    public const NOEUD_OEIL_DU_MINEUR = 'Œil du mineur';

    public const ETAT_CACHE = 'cache';

    public const ETAT_DETECTE = 'detecte';

    public const ETAT_DESARME = 'desarme';

    public const ETAT_DECLENCHE = 'declenche';

    /**
     * Vérifie chaque case TRAVERSÉE par un déplacement de héros (chemin BFS,
     * arrivée incluse) : un piège CACHÉ sur le chemin se déclenche. Une fosse
     * (ou un héros tombé à 0 PV) interrompt le déplacement sur la case.
     *
     * @param  list<array{x: int, y: int}>  $chemin  étapes SANS la case de départ
     * @return array{arret: array{x: int, y: int}|null, declenchements: list<array<string, mixed>>}
     */
    public function controlerChemin(
        Groupe $groupe,
        Carte $carte,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $chemin,
    ): array {
        $declenchements = [];

        foreach ($chemin as $case) {
            $index = $this->indexPiegeCache($carte, (int) $case['x'], (int) $case['y']);

            if ($index === null) {
                continue;
            }

            $payload = $this->declencher($groupe, $carte, $index, $personnage, $etat, 'deplacement');
            $declenchements[] = $payload;

            // Fosse = immobilisé (perd le reste de son déplacement) ; un héros
            // tombé à 0 PV s'arrête aussi là où il tombe.
            if ($payload['immobilise'] || $payload['tombe']) {
                return ['arret' => ['x' => (int) $case['x'], 'y' => (int) $case['y']], 'declenchements' => $declenchements];
            }
        }

        return ['arret' => null, 'declenchements' => $declenchements];
    }

    /**
     * Déclenche le piège d'index donné sur un héros : effet du catalogue
     * (degats_pv_body), héros à 0 PV → tombe (cohérent avec le combat),
     * usage unique → `declenche` définitif, fosse persistante → reste en jeu
     * (`detecte` après déclenchement). Journal type action + narration en job.
     *
     * @return array<string, mixed> payload journalisé
     */
    public function declencher(
        Groupe $groupe,
        Carte $carte,
        int $index,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        string $contexte,
    ): array {
        $entree = $carte->grille['pieges'][$index];
        $piege = Piege::find($entree['piege_id']);

        $degats = (int) data_get($piege?->effet, 'degats_pv_body', 1);
        $pvApres = max(0, (int) $personnage->pv_body - $degats);
        $personnage->update(['pv_body' => $pvApres]);

        $tombe = $pvApres === 0;
        if ($tombe) {
            $etat->update(['tombe' => true]); // C4 : occupe sa case, relevable
        }

        // Persistant (fosse) : le piège reste en jeu, désormais visible de
        // tous ; usage unique : consommé définitivement.
        $persistant = $piege?->usage === 'persistant';
        $this->changerEtat($carte, $index, $persistant ? self::ETAT_DETECTE : self::ETAT_DECLENCHE);

        $payload = [
            'type' => 'piege_declenche',
            'contexte' => $contexte,
            'piege' => [
                'nom' => $piege?->nom ?? 'Piège',
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
            ],
            'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
            'degats' => $degats,
            'pv_body_apres' => $pvApres,
            'tombe' => $tombe,
            'immobilise' => $this->estFosse($piege),
        ];

        Journal::ajouter($groupe, 'action', $payload, [
            'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
        ]);

        GenererNarration::dispatch($groupe->id, $payload);

        return $payload;
    }

    /**
     * Fouille RÉUSSIE : révèle les pièges cachés dans un rayon de
     * RAYON_FOUILLE cases (Manhattan) autour du fouilleur.
     *
     * @return list<array{x: int, y: int, nom: string}> pièges révélés
     */
    public function revelerAutour(Groupe $groupe, Carte $carte, Personnage $personnage, int $x, int $y): array
    {
        return $this->reveler(
            $groupe,
            $carte,
            $personnage,
            fn (array $entree) => abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) <= self::RAYON_FOUILLE,
            'fouille',
        );
    }

    /**
     * Œil du mineur (nœud nain, CompetenceSeeder) : détection AUTOMATIQUE
     * des pièges orthogonalement adjacents au héros — appelée à chaque début
     * d'action et après chaque déplacement. Sans le nœud : aucun effet.
     *
     * @return list<array{x: int, y: int, nom: string}> pièges révélés
     */
    public function detecterAdjacents(Groupe $groupe, Carte $carte, Personnage $personnage, int $x, int $y): array
    {
        if (! $this->possedeOeilDuMineur($personnage)) {
            return [];
        }

        return $this->reveler(
            $groupe,
            $carte,
            $personnage,
            fn (array $entree) => abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) === 1,
            'oeil_du_mineur',
        );
    }

    /**
     * Pièges DÉTECTÉS orthogonalement adjacents à une position, avec leur
     * modèle de catalogue — base des options de menu Désamorcer / Franchir.
     *
     * @return list<array{index: int, x: int, y: int, piege: Piege|null}>
     */
    public function detectesAdjacents(Carte $carte, int $x, int $y): array
    {
        $adjacents = [];

        foreach ($carte->grille['pieges'] ?? [] as $index => $entree) {
            if (($entree['etat'] ?? null) !== self::ETAT_DETECTE) {
                continue;
            }
            if (abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) !== 1) {
                continue;
            }

            $adjacents[] = [
                'index' => $index,
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                'piege' => Piege::find($entree['piege_id']),
            ];
        }

        return $adjacents;
    }

    /** Entrée brute d'un piège par index (null si absente). */
    public function entree(Carte $carte, int $index): ?array
    {
        return $carte->grille['pieges'][$index] ?? null;
    }

    public function changerEtat(Carte $carte, int $index, string $etat): void
    {
        $grille = $carte->grille;
        $grille['pieges'][$index]['etat'] = $etat;
        $carte->update(['grille' => $grille]);
    }

    /** Une fosse = piège franchissable du catalogue (PiegeSeeder). */
    public function estFosse(?Piege $piege): bool
    {
        return isset($piege?->effet['franchissable']);
    }

    /**
     * Désamorçage réservé au Nain OU au porteur d'un objet à effet
     * `permet_desamorcage` (Trousse à outils, ObjetSeeder) — doc 10 §4.
     */
    public function peutDesamorcer(Personnage $personnage): bool
    {
        if ($personnage->classe === 'nain') {
            return true;
        }

        return $personnage->inventaire()
            ->with('objet')
            ->get()
            ->contains(fn ($ligne) => (bool) data_get($ligne->objet?->effet, 'permet_desamorcage', false));
    }

    public function possedeOeilDuMineur(Personnage $personnage): bool
    {
        return $personnage->competences()
            ->where('nom', self::NOEUD_OEIL_DU_MINEUR)
            ->exists();
    }

    /** Index du piège encore CACHÉ posé sur une case (null sinon). */
    private function indexPiegeCache(Carte $carte, int $x, int $y): ?int
    {
        foreach ($carte->grille['pieges'] ?? [] as $index => $entree) {
            if (($entree['etat'] ?? null) === self::ETAT_CACHE
                && (int) $entree['x'] === $x && (int) $entree['y'] === $y) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Passe à `detecte` tous les pièges CACHÉS retenus par le filtre ;
     * journalise la détection (les positions deviennent publiques).
     *
     * @param  callable(array): bool  $filtre
     * @return list<array{x: int, y: int, nom: string}>
     */
    private function reveler(Groupe $groupe, Carte $carte, Personnage $personnage, callable $filtre, string $methode): array
    {
        $reveles = [];

        foreach ($carte->grille['pieges'] ?? [] as $index => $entree) {
            if (($entree['etat'] ?? null) !== self::ETAT_CACHE || ! $filtre($entree)) {
                continue;
            }

            $this->changerEtat($carte, $index, self::ETAT_DETECTE);

            $reveles[] = [
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                'nom' => Piege::find($entree['piege_id'])?->nom ?? 'Piège',
            ];
        }

        if ($reveles !== []) {
            Journal::ajouter($groupe, 'action', [
                'type' => 'pieges_detectes',
                'methode' => $methode,
                'pieges' => $reveles,
            ], ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom]);
        }

        return $reveles;
    }
}
