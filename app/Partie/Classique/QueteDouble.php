<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Events\EtatGroupeDiffuse;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\CaseLibre;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\ResolveurTour;
use App\Partie\Salles;
use App\Partie\Sauvegarde;
use App\Support\Journal;

/**
 * LA QUÊTE DOUBLE (mode classique, lot K5p, R10 — *The Frozen Horror* q. 9-10, *The Heart of Ice*) : « When a hero moves
 * onto the stairway to enter Quest 10, remove that hero's figure from the gameboard. Tell the players that they cannot
 * enter Quest 10 until all the heroes have moved onto the stairway. Once all heroes have moved onto the stairway, remove
 * the Quest 9 setup […] When the heroes return to Quest 9, set out only room A' until they explore other rooms. Monsters
 * they killed previously do not reappear. » Le jeu est SÉQUENTIEL : jamais deux cartes ouvertes à la fois.
 *
 * Point de passage unique du passage et de la bascule. La moitié quittée reste `en_cours` mais n'est plus la quête
 * courante (SUSPENDUE : sa consigne n'est pas rendue, ses monstres morts le restent) ; la fin de l'une clôt l'autre
 * (`Quete::booted()`). Les héros passés sont retenus dans `drapeaux._passes` de la moitié qu'ils quittent.
 */
final class QueteDouble
{
    private const PASSES = '_passes';

    /** Les héros qui ont déjà emprunté le passage de cette moitié, en attendant les autres. @return list<int> */
    public function passes(Quete $quete): array
    {
        return array_map('intval', (array) data_get($quete->drapeaux, self::PASSES, []));
    }

    /**
     * Le héros emprunte le passage : il quitte le plateau. Quand plus aucun héros debout n'y reste, la partie bascule.
     *
     * @return array<string, mixed> le résultat moteur (`type: passage_double`)
     */
    public function passer(Groupe $groupe, Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat): array
    {
        $drapeaux = (array) $quete->drapeaux;
        $drapeaux[self::PASSES] = array_values(array_unique([...$this->passes($quete), (int) $personnage->id]));
        $quete->update(['drapeaux' => $drapeaux]);
        $etat->delete();

        // Debout ET sur le plateau : un héros qui attend encore devant une porte d'entrée doit d'abord entrer et marcher.
        $restants = $quete->etatsPersonnages()->where('tombe', false)->count();

        $payload = [
            'type' => 'passage_double',
            'personnage_id' => $personnage->id,
            'nom' => $personnage->nom,
            'vers' => $quete->titreDeLAutreMoitie(),
            'restants' => $restants,
            // Lu par `ResolveurTour::resoudre()` : le héros n'a plus de ligne d'état ici, rien ne doit plus y écrire.
            'sorti' => true,
        ];

        if ($restants === 0) {
            $payload['quete'] = $this->basculer($groupe, $quete->refresh());
        }

        return $payload;
    }

    /**
     * Tous les héros debout sont passés : l'autre moitié devient la quête courante — créée au premier passage, reprise
     * ensuite. Rend `{etat: 'bascule', vers: titre, quete_id}`, porté par le résultat (`ResolveurTour` rend alors la
     * main sans finir le tour sur la moitié quittée).
     *
     * @return array<string, mixed>
     */
    public function basculer(Groupe $groupe, Quete $depuis): array
    {
        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get()
            ->filter(fn (Personnage $p) => in_array((int) $p->id, $this->passes($depuis), true))
            ->values();

        $drapeaux = (array) $depuis->drapeaux;
        unset($drapeaux[self::PASSES]);
        $depuis->update(['drapeaux' => $drapeaux]);

        $vers = $depuis->moitieJumelle();
        $premiere = $vers === null;
        if ($vers === null) {
            // PREMIER PASSAGE : la seconde moitié n'existe pas encore — elle naît de la même fondation qu'une quête.
            $slug = data_get($depuis->documentConstruit(), 'double.quete');
            $quetes = array_values((array) ($groupe->campagneConstruite?->document['quetes'] ?? []));
            $rang = null;
            foreach ($quetes as $i => $doc) {
                if (($doc['slug'] ?? null) === $slug) {
                    $rang = $i + 1;
                }
            }
            $vers = app(DemarreurQueteConstruite::class)->ouvrirMoitie($groupe, $depuis->principale(), (array) $quetes[$rang - 1], (int) $rang, $heros);
        } else {
            $this->reprendre($groupe, $vers, $heros);
        }

        Journal::ajouter($groupe, 'systeme', ['action' => 'quete_double_bascule', 'depuis' => $depuis->id, 'vers' => $vers->id]);

        return [
            'etat' => 'bascule', 'vers' => $vers->titre, 'quete_id' => $vers->id,
            // Le texte d'ouverture de la moitié créée a été lu à la table (`DemarreurQueteConstruite::fonder()`) : rien à
            // narrer par-dessus (`ExecutionChoix`).
            'narre' => $premiere && trim((string) data_get($vers->documentConstruit(), 'ouverture', '')) !== '',
        ];
    }

    /**
     * RETOUR dans une moitié déjà jouée : « set out only room A' until they explore other rooms. Monsters they killed
     * previously do not reappear. » La salle de l'arrivée seule est découverte, les couloirs vus oubliés, les monstres
     * vivants des autres salles recachés ; les héros paraissent sur les cases du passage.
     *
     * @param  \Illuminate\Support\Collection<int, Personnage>  $heros
     */
    private function reprendre(Groupe $groupe, Quete $quete, \Illuminate\Support\Collection $heros): void
    {
        $arrivee = $quete->casesDuPassage();
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $salle = $arrivee === [] ? null : Salles::indexDe($salles, $arrivee[0]['x'], $arrivee[0]['y']);

        $quete->update([
            'salles_decouvertes' => $salle === null ? [] : [$salle],
            'couloirs_vus' => [],
            // Lu par `Quete::sallesDecouvertes()` : la salle de départ n'est plus connue d'emblée.
            'drapeaux' => [...(array) $quete->drapeaux, '_reprise' => true],
        ]);
        foreach ($quete->instancesMonstres()->where('etat', 'actif')->get() as $monstre) {
            $ici = $monstre->position_x === null ? null : Salles::indexDe($salles, (int) $monstre->position_x, (int) $monstre->position_y);
            $monstre->update(['revele' => $salle !== null && $ici === $salle]);
        }

        foreach ($heros as $personnage) {
            $grille = FabriqueGrille::pour($quete->fresh());
            $place = null;
            foreach ($arrivee as $case) {
                if ($grille->estTraversable($case['x'], $case['y'])) { // une figure y est déjà : la case n'est pas traversable
                    $place = $case;
                    break;
                }
            }
            $place ??= $arrivee === [] ? null : CaseLibre::proche($grille, $arrivee[0]['x'], $arrivee[0]['y']);
            $quete->etatsPersonnages()->updateOrCreate(
                ['personnage_id' => $personnage->id],
                ['position_x' => $place['x'] ?? null, 'position_y' => $place['y'] ?? null, 'a_joue' => false, 'tombe' => false],
            );
        }

        $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

        app(\App\Partie\Classique\CouloirsVus::class)->mettreAJour($quete->refresh());
        app(ResolveurTour::class)->ouvrirPremierRound($groupe, $quete);
        app(Sauvegarde::class)->snapshotter($groupe->refresh(), Sauvegarde::ETIQUETTE_DEBUT_QUETE);
        broadcast(new EtatGroupeDiffuse($groupe, app(EtatGroupe::class)->payload($groupe)));

        foreach ($heros as $personnage) {
            GenererMenu::lancerPour($groupe, $personnage);
        }
    }
}
