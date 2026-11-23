<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\MoteurDegats;
use App\Partie\MoteurPieges;
use App\Partie\Salles;

/**
 * LE COFFRE PIÉGÉ DE L'AUTEUR (mode classique, lot E1, 2026-11-22) — point de passage unique de `quete.coffres_pieges`
 * `[{salle, piege?, degats?, toute_la_salle?}]`.
 *
 * Tous les livrets en ont (« If a hero searches for treasure before the trap is disarmed, they lose 2 Body Points »,
 * F2847 q. 5 ; F5815, G0053, G1798, F9907, F9528, F6646, G0052). La règle du plateau (`reference/16` §7.3, LR p. 16-20) :
 *  - la FOUILLE DES PIÈGES de la salle le trouve ({@see decouvrir()}) ;
 *  - une fois trouvé, il se DÉSAMORCE comme tout piège (Nain et Explorateur sans outils, trousse sinon ; le jet partagé
 *    `ResolveurTour::jetDesamorcage()`) ;
 *  - FOUILLER LE TRÉSOR avant de l'avoir désamorcé le DÉCLENCHE ({@see declencher()}), et le tour du fouilleur s'arrête ;
 *    la fouille rend ensuite ce que la salle contient (la note de l'auteur, sinon une carte).
 * Les dégâts sont ceux de l'auteur (`degats`, fixes), sinon ceux du piège de coffre nommé (`piege`, catalogue `pieges`,
 * `declencheur: ouverture_tresor` : 1 PV ou Empoisonné, poison, Boule de Flammes…). `toute_la_salle` : chaque héros de la
 * salle les subit (« all heroes in the room lose 2 Body Points », F5815 q. 4).
 *
 * État durable : `quetes.drapeaux._coffres_pieges` `{id de salle: decouvert|desamorce|declenche}` (clé réservée).
 */
final class CoffresPieges
{
    public const DRAPEAU = '_coffres_pieges';

    public const DECOUVERT = 'decouvert';

    public const DESAMORCE = 'desamorce';

    public const DECLENCHE = 'declenche';

    /** Le piège de coffre par défaut : celui du plateau (« Piège de coffre », catalogue `pieges`). */
    public const PIEGE_PAR_DEFAUT = 'piege-de-coffre';

    public function __construct(
        private readonly CatalogueConstructeur $catalogue,
        private readonly MoteurPieges $pieges,
        private readonly MoteurDegats $degats,
    ) {}

    /**
     * Le coffre piégé de cette salle, ENCORE dangereux (ni désamorcé ni déclenché) : `{doc, salle, etat}` ou `null`.
     *
     * @return array{doc: array<string, mixed>, salle: string, etat: ?string}|null
     */
    public function actif(Quete $quete, int $salleIndex): ?array
    {
        $id = (string) data_get($quete->carte?->grille, "salles.{$salleIndex}.id", '');
        if ($id === '' || ! $quete->estConstruite()) {
            return null;
        }
        foreach ((array) ($quete->documentConstruit()['coffres_pieges'] ?? []) as $doc) {
            if (is_array($doc) && ($doc['salle'] ?? null) === $id) {
                $etat = $this->etats($quete)[$id] ?? null;

                return in_array($etat, [self::DESAMORCE, self::DECLENCHE], true) ? null : ['doc' => $doc, 'salle' => $id, 'etat' => $etat];
            }
        }

        return null;
    }

    /** Trouvé par une fouille des pièges, et encore dangereux : le menu offre de le désamorcer. */
    public function aDesamorcer(Quete $quete, int $salleIndex): ?array
    {
        $coffre = $this->actif($quete, $salleIndex);

        return ($coffre['etat'] ?? null) === self::DECOUVERT ? $coffre : null;
    }

    /** Le nom du piège, tel qu'on le montre. */
    public function nom(array $coffre): string
    {
        return (string) ($this->piege($coffre)?->nom ?? 'Piège de coffre');
    }

    /**
     * La fouille des pièges de la salle le TROUVE. `{nom, salle}` s'il vient d'être trouvé, `null` sinon.
     *
     * @return array{nom: string, salle: string}|null
     */
    public function decouvrir(Quete $quete, int $salleIndex): ?array
    {
        $coffre = $this->actif($quete, $salleIndex);
        if ($coffre === null || $coffre['etat'] === self::DECOUVERT) {
            return null;
        }
        $this->marquer($quete, $coffre['salle'], self::DECOUVERT);

        return ['nom' => $this->nom($coffre), 'salle' => $coffre['salle']];
    }

    public function desamorcer(Quete $quete, string $salle): void
    {
        $this->marquer($quete, $salle, self::DESAMORCE);
    }

    /**
     * La fouille du trésor le DÉCLENCHE : le fouilleur (ou toute la salle) subit le piège, et son tour s'arrête. Rend le
     * payload `piege_declenche` à publier, ou `null` s'il n'y a rien à déclencher.
     *
     * @return array<string, mixed>|null
     */
    public function declencher(Groupe $groupe, Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat, int $salleIndex): ?array
    {
        $coffre = $this->actif($quete, $salleIndex);
        if ($coffre === null) {
            return null;
        }
        $this->marquer($quete, $coffre['salle'], self::DECLENCHE);
        $doc = $coffre['doc'];
        $piege = $this->piege($coffre);

        // TOUTE LA SALLE : chaque héros debout de la salle subit les dégâts de l'auteur, sans défense — la forme « zone »
        // du fil (`JournalCombat::piegeDeclenche()`, une ligne par victime). Le fouilleur s'arrête, pas les autres.
        if (($doc['toute_la_salle'] ?? false) === true) {
            $cibles = [];
            $grille = (array) ($quete->carte?->grille ?? []);
            foreach ($quete->etatsPersonnages()->with('personnage')->get() as $e) {
                if ($e->tombe || $e->position_x === null || $e->personnage === null
                    || Salles::indexDe((array) ($grille['salles'] ?? []), (int) $e->position_x, (int) $e->position_y) !== $salleIndex) {
                    continue;
                }
                $subis = $this->degats->infligerAHeros($e->personnage, (int) ($doc['degats'] ?? 1), MoteurDegats::SOURCE_PIEGE,
                    ['piege' => $piege?->nom, 'coffre' => true]);
                $tombe = (int) $e->personnage->fresh()->pv_body === 0 && $subis > 0;
                if ($tombe) {
                    $e->update(['tombe' => true]);
                }
                $cibles[] = ['personnage' => ['id' => $e->personnage->id, 'nom' => $e->personnage->nom], 'degats' => $subis, 'tombe' => $tombe];
            }
            $etat->refresh()->update(['a_joue' => true]);
            $this->pieges->signalerDeclenchement($quete, (int) $etat->position_x, (int) $etat->position_y, $personnage);

            return ['type' => 'piege_declenche', 'contexte' => 'fouille_tresor', 'ephemere' => true, 'zone' => true,
                'piege' => ['nom' => $this->nom($coffre)], 'cibles' => $cibles, 'fin_de_tour' => true];
        }

        // LE FOUILLEUR SEUL : le résolveur des pièges de coffre (`declencherEphemere()`, fin du tour comprise), avec les
        // dégâts FIXES de l'auteur à la place du tirage du catalogue quand il les écrit.
        if (isset($doc['degats']) && $piege !== null) {
            $piege = (clone $piege)->forceFill(['effet' => ['degats_pv_body' => (int) $doc['degats']]]);
        }

        return $this->pieges->declencherEphemere($groupe, $personnage, $etat, $piege, 'fouille_tresor', narrer: false);
    }

    private function piege(array $coffre): ?Piege
    {
        $slug = (string) ($coffre['doc']['piege'] ?? self::PIEGE_PAR_DEFAUT);

        return $this->catalogue->index('pieges')[$slug] ?? null;
    }

    /** @return array<string, string> */
    private function etats(Quete $quete): array
    {
        return (array) (((array) ($quete->relire('drapeaux')->drapeaux ?? []))[self::DRAPEAU] ?? []);
    }

    private function marquer(Quete $quete, string $salle, string $etat): void
    {
        $etats = $this->etats($quete); // relit `drapeaux` en base
        $drapeaux = (array) ($quete->drapeaux ?? []);
        $drapeaux[self::DRAPEAU] = [...$etats, $salle => $etat];
        $quete->update(['drapeaux' => $drapeaux]);
    }
}
