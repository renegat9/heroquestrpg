<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Events\EtatGroupeDiffuse;
use App\Events\NarrationDiffusee;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\EffetsGlobauxQuete;
use App\Partie\EtatGroupe;
use App\Partie\GardePersonnelle;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use App\Partie\Salles;
use App\Partie\Sauvegarde;
use App\Support\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MODE CLASSIQUE — démarre la quête d'une CAMPAGNE CONSTRUITE (`docs/plan-mode-classique.md` §4, §7).
 *
 * Le pendant de `DemarreurQuete::demarrer()` pour une quête qui n'est pas générée : la carte vient de
 * `CarteConstruite::produire()`, les monstres sont ceux que l'auteur a posés, à leur place, avec leurs valeurs de
 * catalogue. Rien n'est tiré, rien n'est habillé, aucun travail d'IA n'est lancé — le texte d'ouverture est celui de
 * l'auteur.
 *
 * Ce qui est REPRIS du démarrage ordinaire, parce que ce sont des règles du moteur et non de la génération : les héros
 * repartent à leurs points de vie maximaux, sorts rechargés ; les effets globaux et la garde personnelle des monstres
 * du catalogue s'établissent ; le premier round s'ouvre ; un instantané de début de quête est pris.
 *
 * Ce qui n'existe PAS encore ici, écrit pour ne pas être oublié :
 * - le paquet de trésor : il est vide, une fouille de salle ne trouve rien (il arrive avec les notes, lot K3) ;
 * - les alliés : `EtapesCampagne::CLASSIQUE` ferme le recrutement, donc aucun n'est à poser ;
 * - le monstre errant de la quête (lot K5).
 */
final class DemarreurQueteConstruite
{
    public function __construct(
        private readonly CarteConstruite $cartes,
        private readonly CatalogueConstructeur $catalogue,
        private readonly MoteurSorts $sorts,
        private readonly MoteurDread $dread,
        private readonly Sauvegarde $sauvegarde,
        private readonly EtatGroupe $etatGroupe,
    ) {}

    /** Pourquoi ce groupe classique ne peut pas partir en quête, ou `null`. Lu par `POST /pret` avant tout démarrage. */
    public function refusDeDepart(Groupe $groupe): ?string
    {
        $campagne = $groupe->campagneConstruite;

        if ($campagne === null) {
            return 'Ce groupe n\'a pas de campagne construite : choisissez-en une.';
        }

        if ((int) $groupe->rang_quete > count((array) ($campagne->document['quetes'] ?? []))) {
            return 'Toutes les quêtes de « '.$campagne->titre.' » ont été jouées : choisissez une autre campagne.';
        }

        // PRÉREQUIS DE HÉROS (`campagne.prerequis_heros`) : chaque classe exigée doit avoir un héros ACTIF dans le
        // groupe. Lu au DÉPART, quand le groupe est composé — pas à sa création, où il n'a que son fondateur.
        $exigees = array_map('strval', (array) ($campagne->document['prerequis_heros'] ?? []));
        if ($exigees !== []) {
            $presentes = $groupe->personnages()->wherePivot('actif', true)->pluck('classe')
                ->map(fn ($classe) => CatalogueConstructeur::slug((string) $classe))->all();
            $manquantes = array_values(array_diff($exigees, $presentes));

            if ($manquantes !== []) {
                $noms = collect($manquantes)->map(fn (string $slug) => $this->catalogue->index('classes')[$slug]->nom ?? $slug)->implode(', ');

                return '« '.$campagne->titre.' » exige un héros de classe : '.$noms.'.';
            }
        }

        return null;
    }

    public function demarrer(Groupe $groupe): Quete
    {
        if (! $groupe->estClassique()) {
            throw RefusClassique::pasUnGroupeClassique($groupe);
        }

        $campagne = $groupe->campagneConstruite;
        if ($campagne === null) {
            throw RefusClassique::sansCampagne($groupe);
        }

        $document = (array) $campagne->document;
        $rang = (int) $groupe->rang_quete;
        $queteDoc = $document['quetes'][$rang - 1] ?? null;
        if (! is_array($queteDoc)) {
            throw RefusClassique::campagneTerminee($groupe);
        }

        // Le document est REVALIDÉ ici, par le producteur : une campagne enregistrée sous une version plus souple du
        // format ne démarre pas sur une carte bancale — `CarteRefusee` nomme chaque refus.
        $produit = $this->cartes->produire($document, $queteDoc);
        $carte = $produit['carte'];

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get();

        if ($heros->isEmpty()) {
            throw ValidationException::withMessages(['groupe' => 'Aucun héros actif dans ce groupe : rien à démarrer.']);
        }
        if ($heros->count() > count($carte['spawn_heros'])) {
            throw ValidationException::withMessages([
                'groupe' => 'La salle de départ de cette quête n\'a que '.count($carte['spawn_heros']).' cases libres pour '.$heros->count().' héros.',
            ]);
        }

        $monstres = Monstre::whereIn('id', array_column($produit['monstres'], 'monstre_id'))->get()->keyBy('id');
        $cibles = array_map('intval', (array) data_get($queteDoc, 'fin.cibles', []));

        $quete = DB::transaction(function () use ($groupe, $heros, $queteDoc, $carte, $produit, $monstres, $cibles, $rang): Quete {
            $quete = Quete::create([
                'groupe_id' => $groupe->id,
                'gabarit_id' => null,
                'titre' => (string) $queteDoc['titre'],
                'position_arc' => $rang,
                'type_jalon' => 'normale',
                'objectif_majeur' => false,
                'etat' => 'en_cours',
                'or_initial' => $groupe->or,
                'salles_decouvertes' => [0],
                'tresors_fouilles' => [],
                'deck_fouille' => [],
                'salles_coffre' => [],
                'coffres_ouverts' => [],
                // La condition de fin de l'auteur : le type (lu par `Quete::objectif()`) et la consigne dite aux joueurs.
                'objectif_construit' => [
                    'type' => (string) data_get($queteDoc, 'fin.type'),
                    'libelle' => (string) data_get($queteDoc, 'fin.libelle'),
                ],
            ]);

            Carte::create([
                'quete_id' => $quete->id,
                'largeur' => $carte['largeur'],
                'hauteur' => $carte['hauteur'],
                'grille' => $carte,
            ]);

            foreach ($produit['monstres'] as $i => $pose) {
                $monstre = $monstres->get((int) $pose['monstre_id']);

                InstanceMonstre::create([
                    'quete_id' => $quete->id,
                    'monstre_id' => $monstre->id,
                    // Valeurs du catalogue, telles quelles : ni adaptation à la taille du groupe, ni élite tirée.
                    'pv_body' => (int) $monstre->pv_body,
                    'pv_body_max' => (int) $monstre->pv_body,
                    'pv_mind' => (int) $monstre->pv_mind,
                    'position_x' => (int) $pose['x'],
                    'position_y' => (int) $pose['y'],
                    'etat' => 'actif',
                    'elite' => false,
                    // Dormant tant que sa salle n'est pas découverte ; un monstre de la salle de départ est vu d'emblée.
                    'revele' => Salles::indexDe($carte['salles'], (int) $pose['x'], (int) $pose['y']) === 0,
                    // L'ordre de `produit['monstres']` est celui de `quete.monstres` : `fin.cibles` y lit ses index.
                    'objectif' => in_array($i, $cibles, true),
                ]);
            }

            foreach ($heros->values() as $i => $personnage) {
                /** @var Personnage $personnage */
                $groupe->personnages()->updateExistingPivot($personnage->id, ['ordre_initiative' => $i + 1]);
                $personnage->update([
                    'pv_body' => $personnage->pv_body_max,
                    'pv_mind' => $personnage->pv_mind_max,
                ]);
                $this->sorts->reinitialiserQuete($groupe, $personnage);
                $this->sorts->dissiperEffetsACompteur($personnage);

                $quete->etatsPersonnages()->create([
                    'personnage_id' => $personnage->id,
                    'position_x' => $carte['spawn_heros'][$i]['x'],
                    'position_y' => $carte['spawn_heros'][$i]['y'],
                    'a_joue' => false,
                    'tombe' => false,
                ]);
            }

            $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

            EffetsGlobauxQuete::etablir($quete);
            GardePersonnelle::etablir($quete, $carte);

            return $quete;
        });

        $this->dread->reinitialiserUsages($quete);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'quete_demarree',
            'quete_id' => $quete->id,
            'titre' => $quete->titre,
            'position_arc' => $rang,
            'type_jalon' => 'normale',
            'campagne_construite' => $campagne->slug,
            'nb_monstres' => count($produit['monstres']),
        ]);

        EffetsGlobauxQuete::annoncer($groupe, $quete);

        app(ResolveurTour::class)->ouvrirPremierRound($groupe, $quete);

        if ($quete->refresh()->etat === 'en_cours') {
            $this->sauvegarde->snapshotter($groupe->refresh(), Sauvegarde::ETIQUETTE_DEBUT_QUETE);
        }

        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe)));

        // Le TEXTE D'OUVERTURE est celui de l'auteur, lu à la table — jamais un récit d'IA.
        $ouverture = trim((string) ($queteDoc['ouverture'] ?? ''));
        if ($ouverture !== '') {
            $evenement = Journal::ajouter($groupe, 'narration', ['texte' => $ouverture]);
            broadcast(new NarrationDiffusee($groupe, $ouverture, queteId: $quete->id, sequence: $evenement->sequence));
        }

        foreach ($heros as $personnage) {
            GenererMenu::lancerPour($groupe, $personnage);
        }

        return $quete;
    }
}
