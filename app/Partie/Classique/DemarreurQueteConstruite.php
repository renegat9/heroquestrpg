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
use App\Partie\Fouille\DeckFouille;
use App\Partie\EffetsGlobauxQuete;
use App\Partie\EtatGroupe;
use App\Partie\GardePersonnelle;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use App\Partie\Salles;
use App\Partie\Sauvegarde;
use App\Support\Journal;
use Illuminate\Support\Collection;
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
 * LE PAQUET DE TRÉSOR est celui du plateau, mélangé (`DeckFouille::pourQueteConstruite()`) ; le MONSTRE ERRANT est
 * celui que l'auteur désigne (`MonstreErrant::deLaQuete()`). Le DOCUMENT de la quête est figé sur la quête
 * (`quetes.document_construit`) : notes, monstre errant et récompense se lisent là, jamais dans la campagne, que
 * l'auteur peut corriger pendant que des héros sont dans le donjon.
 *
 * Ce qui n'existe PAS encore ici, écrit pour ne pas être oublié :
 * - les alliés : posés par `PoseDesAllies` quand la campagne en offre (lot K6, `MercenairesCampagne`), jamais en solo.
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
        private readonly DeckFouille $deck,
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

        // APRÈS UN TPK, ou la mort de tous les héros (René, 2026-11-19 — F9907 p. 9 renvoie au livret de règles : « You
        // may rename your hero and play them as a new character in the next quest ») : le groupe garde son rang et la
        // quête perdue se rejoue ; il lui faut d'abord des héros vivants. Le motif dit comment revenir.
        if (! $groupe->personnages()->wherePivot('actif', true)->whereNull('personnages.mort_le')->exists()) {
            $quete = (array) (((array) ($campagne->document['quetes'] ?? []))[(int) $groupe->rang_quete - 1] ?? []);
            $titre = (string) ($quete['titre'] ?? '');

            $morts = $groupe->personnages()->whereNotNull('personnages.mort_le')->exists();

            return ($morts ? 'Tous les héros sont morts : chaque joueur crée un nouveau héros' : 'Le groupe n\'a plus de héros : chaque joueur choisit un héros')
                .' et rejoint le groupe avec le code '.$groupe->identifiant.'.'
                .($morts && $titre !== '' ? ' La quête « '.$titre.' » sera rejouée.' : '');
        }

        // PRÉREQUIS DE HÉROS (`campagne.prerequis_heros`) : chaque classe exigée doit avoir un héros ACTIF dans le
        // groupe. Lu au DÉPART, quand le groupe est composé — pas à sa création, où il n'a que son fondateur. La règle
        // est celle du choix de campagne (`ChoixCampagne`, qui grise la même campagne au hub).
        $motif = app(ChoixCampagne::class)->motifPrerequis($groupe, $campagne);
        if ($motif !== null) {
            return '« '.$campagne->titre.' » '.lcfirst($motif);
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

        // Le lancement par la TABLE (`POST /quetes`) passe outre les « prêt », jamais les prérequis de héros : une quête
        // solo d'une classe absente n'aurait personne à faire entrer.
        $motif = app(ChoixCampagne::class)->motifPrerequis($groupe, $campagne);
        if ($motif !== null) {
            throw ValidationException::withMessages(['groupe' => '« '.$campagne->titre.' » '.lcfirst($motif)]);
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

        // QUÊTE SOLO (lot S1, `regles.solo`) : seul le PREMIER héros de la classe désignée, dans l'ordre du tour, entre
        // (décision écrite : le livret dit « le Barbare », un groupe de deux barbares n'en envoie qu'un). Les autres
        // héros du groupe restent SPECTATEURS : pas de ligne d'état, rien à risquer ni à gagner. `null` = tout le groupe.
        $participants = null;
        $solo = data_get($queteDoc, 'regles.solo');
        if (is_string($solo)) {
            $elu = $heros->first(fn (Personnage $p) => CatalogueConstructeur::slug((string) $p->classe) === $solo);
            if ($elu === null) {
                $nom = $this->catalogue->index('classes')[$solo]->nom ?? $solo;
                throw ValidationException::withMessages(['groupe' => "Cette quête se joue seul, avec un héros de classe : {$nom} — il n'y en a aucun dans le groupe."]);
            }
            $participants = [(int) $elu->id];
            $heros = $heros->filter(fn (Personnage $p) => (int) $p->id === (int) $elu->id)->values();
        }
        // HORS PLATEAU (René, 2026-11-16 — *Kellar's Keep* : « the heroes line up outside the door ») : par une porte
        // d'entrée, les héros attendent DEHORS et entrent un à un ; aucune case de départ n'est à compter.
        $dehors = $produit['acces']['entree']['type'] === ValidationCampagne::ACCES_PORTE;
        if (! $dehors && $heros->count() > count($carte['spawn_heros'])) {
            throw ValidationException::withMessages([
                'groupe' => 'Le départ de cette quête n\'a que '.count($carte['spawn_heros']).' cases libres pour '.$heros->count().' héros.',
            ]);
        }

        return $this->fonder($groupe, $campagne->slug, $queteDoc, $rang, $produit, $heros, $participants, $dehors);
    }

    /**
     * QUÊTE DOUBLE (lot K5p, R10 — *The Frozen Horror* q. 9-10) : ouvre la SECONDE moitié pour les héros qui viennent
     * d'emprunter le passage. La même fondation que `demarrer()` — jamais une seconde copie —, sauf trois choses que le
     * livret dit : « Mind and Body Points are not restored when the heroes cross » (ni Corps, ni Esprit, ni sorts), l'ordre
     * du tour inchangé, et l'objectif, les participants et la récompense de la PRINCIPALE. Les deux lignes sont reliées
     * (`double_id`). Appelée par `QueteDouble::basculer()` seulement.
     *
     * @param  Collection<int, Personnage>  $heros
     */
    public function ouvrirMoitie(Groupe $groupe, Quete $principale, array $queteDoc, int $rang, Collection $heros): Quete
    {
        $produit = $this->cartes->produire((array) $groupe->campagneConstruite->document, $queteDoc);
        $dehors = $produit['acces']['entree']['type'] === ValidationCampagne::ACCES_PORTE;

        return $this->fonder($groupe, (string) $groupe->campagneConstruite->slug, $queteDoc, $rang, $produit, $heros,
            $principale->participants, $dehors, $principale);
    }

    /**
     * La FONDATION d'une quête construite : la ligne, sa carte, ses monstres, l'état de chaque héros, puis le premier
     * round, l'instantané, le texte d'ouverture et les menus. `$moitieDe` : la principale dont on ouvre la seconde moitié.
     *
     * @param  Collection<int, Personnage>  $heros
     * @param  list<int>|null  $participants
     */
    private function fonder(Groupe $groupe, string $slugCampagne, array $queteDoc, int $rang, array $produit, Collection $heros,
        ?array $participants, bool $dehors, ?Quete $moitieDe = null): Quete
    {
        $carte = $produit['carte'];
        // LES MERCENAIRES (lot K6) : au départ d'une NOUVELLE quête (pas d'une seconde moitié), chaque allié non payé pour
        // elle part, ou se renouvelle à moitié prix, selon la règle de la campagne.
        if ($moitieDe === null) {
            app(MercenairesCampagne::class)->auDepart($groupe, $rang);
        }
        $monstres = Monstre::whereIn('id', array_column($produit['monstres'], 'monstre_id'))->get()->keyBy('id');
        $cibles = array_map('intval', (array) data_get($queteDoc, 'fin.cibles', []));

        $paquet = $this->deck->pourQueteConstruite();

        $quete = DB::transaction(function () use ($groupe, $heros, $queteDoc, $carte, $produit, $monstres, $cibles, $rang, $paquet, $dehors, $participants, $moitieDe): Quete {
            $quete = Quete::create([
                'groupe_id' => $groupe->id,
                'gabarit_id' => null,
                'titre' => (string) $queteDoc['titre'],
                'position_arc' => $rang,
                'type_jalon' => 'normale',
                'objectif_majeur' => false,
                'etat' => 'en_cours',
                'or_initial' => $groupe->or,
                // La salle de départ, connue d'emblée — aucune quand on entre par une porte de couloir.
                'salles_decouvertes' => $produit['acces']['salle_depart'] === null ? [] : [0],
                'tresors_fouilles' => [],
                'deck_fouille' => $paquet['deck'],
                'salles_coffre' => $paquet['salles_coffre'],
                'document_construit' => $queteDoc,
                'drapeaux' => [],
                'notes_jouees' => [],
                'coffres_ouverts' => [],
                // La condition de fin de l'auteur : le type (lu par `Quete::objectif()`) et la consigne dite aux joueurs.
                // Quête double : la seconde moitié porte l'objectif de la principale (le bandeau le dit ; `objectifAccompli()`
                // le lit sur la principale).
                'objectif_construit' => $moitieDe !== null ? $moitieDe->objectif_construit : [
                    'type' => (string) data_get($queteDoc, 'fin.type'),
                    'libelle' => (string) data_get($queteDoc, 'fin.libelle'),
                    // Fin « drapeau » : le drapeau qu'une note doit poser (`Quete::OBJECTIF_DRAPEAU`).
                    'drapeau' => data_get($queteDoc, 'fin.drapeau'),
                ],
                // Par où l'on entre et par où l'on sort (escalier ou porte), figés ici : `Quete::sortieDuDonjon()`.
                'acces' => $produit['acces'],
                // Les héros engagés (lot S1) : `null` = tout le groupe ; une quête solo n'engage que son héros.
                'participants' => $participants,
                'double_id' => $moitieDe?->id,
            ]);
            $moitieDe?->update(['double_id' => $quete->id]);

            Carte::create([
                'quete_id' => $quete->id,
                'largeur' => $carte['largeur'],
                'hauteur' => $carte['hauteur'],
                'grille' => $carte,
            ]);

            foreach ($produit['monstres'] as $i => $pose) {
                $monstre = $monstres->get((int) $pose['monstre_id']);
                $docMonstre = (array) ($queteDoc['monstres'][$i] ?? []);
                $pvBody = isset($docMonstre['pv_body']) ? (int) $docMonstre['pv_body'] : (int) $monstre->pv_body;
                $pvMind = isset($docMonstre['pv_mind']) ? (int) $docMonstre['pv_mind'] : (int) $monstre->pv_mind;

                InstanceMonstre::create([
                    'quete_id' => $quete->id,
                    'monstre_id' => $monstre->id,
                    // Valeurs du catalogue, ou surcharges R9 de l'auteur : ni adaptation à la taille du groupe, ni élite tirée.
                    'pv_body' => $pvBody,
                    'pv_body_max' => $pvBody,
                    'pv_mind' => $pvMind,
                    'position_x' => (int) $pose['x'],
                    'position_y' => (int) $pose['y'],
                    'etat' => 'actif',
                    'elite' => false,
                    // Dormant tant que sa salle n'est pas découverte ; un monstre de la salle de départ est vu d'emblée
                    // (aucun quand on entre par une porte de couloir : il n'y a pas de salle de départ).
                    'revele' => $produit['acces']['salle_depart'] !== null
                        && Salles::indexDe($carte['salles'], (int) $pose['x'], (int) $pose['y']) === $produit['acces']['salle_depart'],
                    // L'ordre de `produit['monstres']` est celui de `quete.monstres` : `fin.cibles` y lit ses index.
                    'objectif' => in_array($i, $cibles, true),
                    'habillage' => InstanceMonstre::habillageDeQuete($docMonstre, $this->catalogue),
                ]);
            }

            foreach ($heros->values() as $i => $personnage) {
                /** @var Personnage $personnage */
                // Une quête solo ne renumérote pas l'ordre du tour : le groupe le retrouve tel quel à la quête suivante.
                // Quête double : « Mind and Body Points are not restored when the heroes cross » — ni l'ordre du tour, ni
                // les points, ni les sorts ne se remettent à neuf en passant d'une moitié à l'autre.
                if ($moitieDe === null) {
                    if ($participants === null) {
                        $groupe->personnages()->updateExistingPivot($personnage->id, ['ordre_initiative' => $i + 1]);
                    }
                    // PAS DE RESTAURATION (lot E1, `regles.sans_restauration`) : « Body and Mind Points are not restored when
                    // the heroes cross between Quests 11 and 12 » (F9907 p. 17). Seuls le Corps et l'Esprit restent ce qu'ils
                    // étaient ; les sorts reviennent comme au début de toute quête (choix écrit : le livret ne nomme qu'eux).
                    if ((data_get($queteDoc, 'regles.sans_restauration') ?? false) !== true) {
                        $personnage->update([
                            'pv_body' => $personnage->pv_body_max,
                            'pv_mind' => $personnage->pv_mind_max,
                        ]);
                    }
                    $this->sorts->reinitialiserQuete($groupe, $personnage);
                    $this->sorts->dissiperEffetsACompteur($personnage);
                }

                $quete->etatsPersonnages()->create([
                    'personnage_id' => $personnage->id,
                    // Sans case quand on entre par une porte : le héros attend devant elle (`Quete::entreeHorsPlateau()`).
                    'position_x' => $dehors ? null : $carte['spawn_heros'][$i]['x'],
                    'position_y' => $dehors ? null : $carte['spawn_heros'][$i]['y'],
                    'a_joue' => false,
                    'tombe' => false,
                ]);
            }

            $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

            // LES ALLIÉS (lot K6) : sur les cases de départ restantes, comme au RPG (`PoseDesAllies`) ; jamais en solo.
            app(MercenairesCampagne::class)->poserPourLaQuete($groupe, $quete, (array) ($carte['spawn_heros'] ?? []), $heros->count());

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
            'campagne_construite' => $slugCampagne,
            'nb_monstres' => count($produit['monstres']),
        ]);

        EffetsGlobauxQuete::annoncer($groupe, $quete);

        // LE BROUILLARD DES COULOIRS (2026-11-17) : ce que les héros voient en entrant — la colonne passe de `null` à une
        // liste, ce qui fait jouer ce brouillard à la quête (une quête démarrée avant garde l'ancien).
        app(CouloirsVus::class)->mettreAJour($quete->refresh());

        // LE DÉGUISEMENT (lot K6, `regles.deguisement`) : chaque héros sans armure interdite prend son jeton.
        app(Deguisement::class)->poserAuDepart($quete->refresh());

        // LA CHARGE (lot K5k, `quete.charge`) : posée au sol à sa case, avant l'instantané de départ.
        app(Charge::class)->poser($quete->refresh());
        // LE CAPTIF À ESCORTER (2026-11-20, `quete.captif`) : il attend à sa case qu'un héros le libère.
        app(Escorte::class)->poser($quete->refresh());

        // ÉQUIPEMENT CONFISQUÉ (lot K5j, `regles.confiscation`) : chaque héros qui ENTRE laisse tout à la consigne, AVANT
        // le premier round et l'instantané de départ (une reprise retrouve les héros les mains vides, et la consigne).
        $confiscation = app(Confiscation::class);
        if ($confiscation->salle($quete->refresh()) !== null) {
            foreach ($quete->etatsPersonnages()->with('personnage')->get() as $etatHeros) {
                if ($etatHeros->personnage !== null) {
                    app(\App\Partie\TamponAnnonces::class)->annoncer($groupe, $confiscation->confisquer($quete, $etatHeros->personnage));
                }
            }
        }

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
