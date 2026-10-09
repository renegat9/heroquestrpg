<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\LanceurDes;
use App\Events\EtatGroupeDiffuse;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Jobs\GenererMenu;
use App\Jobs\HabillerMonstres;
use App\Models\Carte;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Mercenaire;
use App\Models\Monstre;
use App\Models\Parametre;
use App\Models\Quete;
use App\Partie\Fouille\DeckFouille;
use App\Partie\Marche\PhaseMarche;
use App\Partie\Narration\BibliothequeNarration;
use App\Support\Journal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Démarrage de la quête suivante (POST /api/groupes/{identifiant}/quetes) —
 * entièrement MOTEUR, sans aucun appel LLM (bascule 2026-08-18) : la
 * narration est résolue SYNCHRONEMENT (pack pré-généré de la quête, repli sur
 * config/narration.php) et les menus, eux aussi 100 % moteur, restent en jobs
 * pour la file `temps-reel`.
 *
 *  1. gabarit choisi selon le type de jalon (position dans l'arc, doc 06 §4 :
 *     jalon du squelette de campagne si présent, sinon boss_final à la
 *     dernière quête, normale ailleurs) ;
 *  2. carte assemblée depuis les tuiles (AssembleurCarte) ;
 *  3. monstres spawnés AU BUDGET : budget = score de puissance du groupe
 *     × escalade d'arc × facteur de jalon, dépensé en points de `cout` du
 *     bestiaire (doc 06 §2 — le moteur fixe la difficulté, P3) ;
 *  4. initiative figée pour toute la quête (C1) : héros dans l'ordre
 *     d'arrivée (pivot ordre_initiative renuméroté), monstres après ;
 *  5. etat_personnage_quete créé (positions de spawn, a_joue=false) ;
 *  6. héros actifs REMIS À PLEIN (P2, doc 01 §13 : récupération intégrale
 *     entre deux quêtes) — PV Body/Mind au max, sorts tous redisponibles
 *     (S5), buffs de sorts purgés, usage de Concentration réarmé (MoteurSorts) ;
 *  7. groupe passé en phase « quete », journal, broadcast `.groupe.etat`,
 *     narration résolue immédiatement, dispatch GenererMenu (un par héros
 *     actif).
 */
final class DemarreurQuete
{
    /** Mémoïsation de {@see self::parametres()} — voir sa docblock. */
    private ?Parametre $parametresCache = null;

    public function __construct(
        private readonly AssembleurCarte $assembleur,
        private readonly ScorePuissance $puissance,
        private readonly EtatGroupe $etatGroupe,
        private readonly MoteurSorts $sorts,
        private readonly MoteurDread $dread,
        private readonly Sauvegarde $sauvegarde,
        private readonly BibliothequeNarration $narration,
        private readonly LanceurDes $des,
        private readonly DeckFouille $deck,
        private readonly CadenceNiveaux $cadence,
    ) {}

    /**
     * Réglages globaux (panneau Réglages), mémoïsés pour la durée d'une seule
     * exécution de démarrer() — jusqu'à 5 lectures (pvAdapte() par monstre +
     * acheterMonstres()). Best-effort : table absente/base indisponible →
     * `null`, chaque site d'appel retombe alors sur son
     * `config('jeu.rencontres.X', défaut)` actuel via `?? config(...)`.
     */
    private function parametres(): ?Parametre
    {
        if ($this->parametresCache !== null) {
            return $this->parametresCache;
        }

        try {
            return $this->parametresCache = Parametre::actuel();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Variance élite (3.6) : à l'apparition, un monstre de BASE a une chance
     * fixe de devenir « élite » (bonus +1/+1/+1). Les sous-boss/boss ne sont
     * jamais élites (déjà calibrés par leur tier). Tirage via le lanceur
     * injectable → déterministe en test.
     */
    private function roulerElite(Monstre $monstre): bool
    {
        // Inactif (ou non-base) : ne consomme AUCUN dé → scénarios déterministes.
        if (! config('jeu.elite.actif') || ($monstre->tier ?? 'base') !== 'base') {
            return false;
        }

        return $this->des->d6() >= (int) config('jeu.elite.seuil_d6', 6);
    }

    /**
     * PV Body d'un monstre ADAPTÉS à la taille du groupe. Seuls les pivots
     * (boss / sous-boss) s'adaptent — la piétaille est déjà régulée par le budget.
     * pv = pv_catalogue × nb_héros / taille_reference, plancher à 40 % (un boss
     * reste un boss même en petit comité). Un boss à PV fixe (Seigneur 10 PV)
     * punissait les groupes de 2 ; à la référence 4, les PV catalogue sont inchangés.
     */
    private function pvAdapte(Monstre $monstre, int $nbHeros): int
    {
        $pv = (int) $monstre->pv_body;

        $bossPvAdaptatif = $this->parametres()?->rencontres_boss_pv_adaptatif
            ?? config('jeu.rencontres.boss_pv_adaptatif', true);

        if (! $bossPvAdaptatif
            || ! in_array($monstre->tier ?? 'base', ['boss', 'sous_boss'], true)) {
            return $pv;
        }

        $reference = max(1, (int) ($this->parametres()?->rencontres_taille_reference
            ?? config('jeu.rencontres.taille_reference', 4)));
        $adapte = (int) round($pv * max(1, $nbHeros) / $reference);

        return max($adapte, (int) ceil($pv * 0.4));
    }

    public function demarrer(Groupe $groupe): Quete
    {
        if ($groupe->phase !== 'hub') {
            throw ValidationException::withMessages([
                'groupe' => 'Une quête est déjà en cours : terminez-la avant d\'en démarrer une autre.',
            ]);
        }

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->orderBy('personnages.id')
            ->get();

        if ($heros->isEmpty()) {
            throw ValidationException::withMessages([
                'groupe' => 'Aucun héros actif dans le groupe : impossible de démarrer une quête.',
            ]);
        }

        // La phase de marché ne survit pas au départ, et on le DIT : elle
        // n'était refermée par personne, elle expirait toute seule six heures
        // plus tard, et les paniers non confirmés disparaissaient sans un mot.
        // `/pret` refuse désormais un panier en attente, donc plus rien de
        // confirmable ne se perd ici — mais un panier VIDE ou une phase ouverte
        // et jamais utilisée doit tout de même être refermée proprement.
        app(PhaseMarche::class)->fermerPourQuete($groupe);

        $positionArc = (int) $groupe->quetes()->count() + 1;
        $typeJalon = $this->typeJalon($groupe, $positionArc);
        $gabarit = $this->choisirGabarit($typeJalon, $groupe, $positionArc);
        // Graine de carte stable par (groupe, quête) : cartes différentes d'une
        // campagne/quête à l'autre, reproductible pour une même quête.
        // COMPTEUR DE PITIÉ du passage secret (René, 2026-08-27) : 50 % de base,
        // +10 points par carte qui n'en a pas eu, retour à 50 dès qu'on en pose
        // un. Un tirage à 50 % pur peut laisser une campagne entière sans le
        // moindre passage — et une telle série ne se lit pas comme du hasard :
        // le groupe conclut que la fonctionnalité n'existe pas et cesse de
        // fouiller. Au pire cinq cartes sèches, puis la certitude.
        $chance = (int) ($groupe->chance_passage_secret ?? AssembleurCarte::CHANCE_PASSAGE_SECRET);

        // Thème du bestiaire (René, 2026-09-06, phase 6a) : FIGÉ pour toute la
        // campagne, en pratique au PREMIER démarrage de quête — un groupe
        // existe avant d'avoir jamais joué, il n'y a pas d'autre ancrage.
        // Écrit UNE SEULE FOIS (jamais retouché si déjà rempli), exactement
        // l'arbitrage déjà pris pour `type_jalon`/`objectif_majeur` de la
        // quête, un cran plus haut : `BOITES_THEMATIQUES` va bientôt passer de
        // 4 à 5 entrées (réactivation de la boîte de glace) et un thème
        // recalculé à chaque quête changerait de modulo SOUS une campagne EN
        // COURS — passant de la jungle à la banquise entre deux portes, ce que
        // `themeBestiaire()` interdit déjà en commentaire pour le boss final.
        //
        // ⚠ Bestiaire MANUEL (2026-09-28) : rien à tirer, les boîtes ont été
        // cochées à la création — `theme_bestiaire` reste `null`.
        if ($groupe->theme_bestiaire === null && $groupe->boites_bestiaire === null) {
            $groupe->update(['theme_bestiaire' => $this->themeBestiaire((int) $groupe->id)]);
        }
        $bestiaire = BestiaireGroupe::duGroupe($groupe);

        // Deck de fouille + coffre à artefact : bâtis DEDANS l'assemblage,
        // via la fermeture ci-dessous, PAS après (René, 2026-09-18 — la
        // narration décrivait un coffre qu'aucune carte ne portait, quête 130).
        // `AssembleurCarte::assembler()` invoque cette fermeture juste après
        // que les salles/arêtes/portes soient stables, et AVANT de poser le
        // mobilier : c'est le seul point où `placerMobilier()` peut garantir
        // le Coffre par SA propre logique de plancher de cases jouables
        // (§2.12 ter), sans risquer de le poser sur une case qu'un monstre
        // occupera déjà (spawnsMonstres() ne tourne qu'en tout dernier). Le
        // câblage des récompenses reste entièrement celui de
        // `DeckFouille::construire()`, INCHANGÉ — cette fermeture ne fait que
        // lui fournir, au bon moment, la carte partielle (salles/arêtes/portes)
        // dont il a toujours eu besoin, et capture son résultat complet
        // (`$fouille`) pour la suite de cette méthode.
        $fouille = null;
        $carte = $this->assembleur->assembler(
            $gabarit, crc32($groupe->identifiant.':'.$positionArc), $chance, $bestiaire,
            function (array $cartePartielle) use ($gabarit, $groupe, $positionArc, $bestiaire, &$fouille): array {
                // `$bestiaire` : les 8 cartes de trésor de Wizards of Morcar
                // (lot F, 2026-10-06) ne rejoignent le deck que pour ce thème
                // — voir `DeckFouille::cartesMorcar()`.
                $fouille = $this->deck->construire($gabarit, $cartePartielle, $groupe, $positionArc, $bestiaire);

                return $fouille['salles_coffre'];
            },
        );

        // ⚠ Écrit AVANT tout le reste du démarrage : la suite peut lever (spawns
        // insuffisants, budget…), et un compteur qui ne monterait pas sur une
        // quête avortée rendrait la pitié muette exactement quand elle sert.
        $groupe->update(['chance_passage_secret' => ($carte['passage_secret'] ?? false)
            ? AssembleurCarte::CHANCE_PASSAGE_SECRET
            : min(100, $chance + AssembleurCarte::PALIER_PASSAGE_SECRET)]);
        $budget = $this->budgetRencontres($groupe, $positionArc, $typeJalon);
        $monstres = $this->acheterMonstres(
            $gabarit->structure ?? [], $budget, count($carte['spawn_monstres']), $positionArc, (int) $groupe->id, $bestiaire,
        );

        if (count($carte['spawn_heros']) < $heros->count()) {
            throw new RuntimeException('Carte assemblée trop petite pour les héros du groupe.');
        }

        $quete = DB::transaction(function () use ($groupe, $heros, $gabarit, $carte, $monstres, $positionArc, $typeJalon, $fouille) {
            $quete = Quete::create([
                'groupe_id' => $groupe->id,
                'gabarit_id' => $gabarit->id,
                'titre' => $this->titreQuete($groupe, $positionArc),
                'position_arc' => $positionArc,
                'type_jalon' => $typeJalon,
                // Troisième déclencheur de montée de niveau (doc 01 §5) : la
                // MARQUE vient du gabarit, la CADENCE de l'arc. Figé ici, au
                // démarrage, exactement comme `type_jalon` — le plan de
                // campagne est écrit par un job asynchrone, et relire la
                // cadence en fin de quête exposerait une quête commencée avant
                // son arrivée à voir la réponse changer sous elle.
                'objectif_majeur' => (bool) data_get($gabarit->structure, 'objectif_majeur', false)
                    && $this->cadence->estMajeure($groupe, $positionArc),
                'etat' => 'en_cours',
                'or_initial' => $groupe->or,
                // La salle de départ est déjà « connue » : elle est couverte par
                // la narration de lancement. Les suivantes seront décrites à la
                // première entrée (ResolveurTour).
                'salles_decouvertes' => [0],
                'tresors_fouilles' => [],
                'deck_fouille' => $fouille['deck'],
                'salle_artefact' => $fouille['salle_artefact'],
                'artefact_objet_id' => $fouille['artefact_objet_id'],
                'salles_coffre' => $fouille['salles_coffre'],
                'coffres_ouverts' => [],
            ]);

            Carte::create([
                'quete_id' => $quete->id,
                'largeur' => $carte['largeur'],
                'hauteur' => $carte['hauteur'],
                'grille' => $carte,
            ]);

            foreach ($monstres as $i => $monstre) {
                $px = $carte['spawn_monstres'][$i]['x'];
                $py = $carte['spawn_monstres'][$i]['y'];

                $elite = $this->roulerElite($monstre);

                // PV max de l'instance : boss/sous-boss adaptés à la taille du
                // groupe (pvAdapte), + bonus élite éventuel. Le courant démarre au max.
                $pvMax = $this->pvAdapte($monstre, $heros->count()) + ($elite ? InstanceMonstre::BONUS_ELITE : 0);

                $instanceCree = InstanceMonstre::create([
                    'quete_id' => $quete->id,
                    'monstre_id' => $monstre->id,
                    // Stats catalogue jamais altérées ; PV (adaptés + élite) portés par l'instance.
                    'pv_body' => $pvMax,
                    'pv_body_max' => $pvMax,
                    'pv_mind' => $monstre->pv_mind,
                    'position_x' => $px,
                    'position_y' => $py,
                    'etat' => 'actif',
                    'elite' => $elite,
                    // Dormant tant que sa salle n'est pas découverte ; les monstres
                    // de la salle de départ (rare) sont visibles d'emblée.
                    'revele' => Salles::indexDe($carte['salles'] ?? [], $px, $py) === 0,
                ]);

                // EMBUSCADE (Dreadshifter, Wizards of Morcar) : « place this
                // monster onto the board as the object it appears to be » — un
                // coffre sur sa case, la créature cachée jusqu'à ce qu'un héros
                // entre dans les 8 cases autour (`MoteurEmbuscade`).
                if ($monstre->aCapaciteEmbuscade()) {
                    $quete->unsetRelation('carte');
                    app(MoteurEmbuscade::class)->deguiser($quete, $instanceCree->load('monstre'));
                }
            }

            // Initiative figée pour toute la quête (C1) : renumérotation 1..n.
            foreach ($heros as $i => $personnage) {
                $groupe->personnages()->updateExistingPivot($personnage->id, ['ordre_initiative' => $i + 1]);

                // Récupération INTÉGRALE entre deux quêtes (P2, doc 01 §13) :
                // PV Body/Mind au max — pas de récupération par repos, seule la
                // transition de quête guérit ; les potions soignent EN quête.
                $personnage->update([
                    'pv_body' => $personnage->pv_body_max,
                    'pv_mind' => $personnage->pv_mind_max,
                ]);

                // Récupération par quête (S5/S6) : sorts disponibles, buffs
                // de sorts purgés, Concentration réarmée.
                $this->sorts->reinitialiserQuete($groupe, $personnage);

                $quete->etatsPersonnages()->create([
                    'personnage_id' => $personnage->id,
                    'position_x' => $carte['spawn_heros'][$i]['x'],
                    'position_y' => $carte['spawn_heros'][$i]['y'],
                    'a_joue' => false,
                    'tombe' => false,
                ]);
            }

            // Alliés recrutés (3.5) : instanciés sur les cases de spawn restantes
            // après les héros (juste à côté du groupe), PV réinitialisés.
            // ⚠ `etat = 'actif'` explicite depuis le chantier 1c (2026-10-06,
            // entretien des mercenaires) : un mercenaire PERSISTE maintenant
            // d'une quête à l'autre — sans ce filtre, une ligne `vaincu`
            // qu'une purge de fin de quête aurait manquée reviendrait à la
            // vie ici, remise à `'actif'` par l'update ci-dessous.
            $slot = $heros->count();
            foreach (GroupeMercenaire::where('groupe_id', $groupe->id)->where('etat', 'actif')
                ->with('mercenaire')->orderBy('id')->get() as $allie) {
                if (! isset($carte['spawn_heros'][$slot])) {
                    break; // pas de case de spawn libre : l'allié reste en réserve
                }

                $allie->update([
                    'pv_body' => (int) $allie->mercenaire->pv_body,
                    'position_x' => $carte['spawn_heros'][$slot]['x'],
                    'position_y' => $carte['spawn_heros'][$slot]['y'],
                    'etat' => 'actif',
                ]);
                $slot++;
            }

            // CAPTIF À SECOURIR (gabarit « secourir », chantier 3b,
            // 2026-10-04) : posé DANS la salle-artefact — même salle qu'un
            // coffre ordinaire, déjà calculée par `DeckFouille::construire()`
            // AVANT que cet objectif existe (`salleDuBoss() ?? salleLaPlus
            // Profonde()`) — jamais une seconde case choisie ici, une seule
            // règle pour désigner « la » salle qui compte. `etat: 'captif'` :
            // ni joué, ni contrôlé, tant qu'un héros ne l'a pas LIBÉRÉ
            // (`MenuMoteur` option `liberer_captif`,
            // `ResolveurTour::resoudreLibererCaptif()`).
            if (data_get($gabarit->structure, 'objectif') === 'secourir' && $fouille['salle_artefact'] !== null) {
                $captifMercenaire = Mercenaire::where('captif', true)->inRandomOrder()->first();
                $salle = (array) data_get($carte, "salles.{$fouille['salle_artefact']}");
                $case = $captifMercenaire === null ? null : $this->caseLibreDansSalle($carte, $salle);

                if ($captifMercenaire !== null && $case !== null) {
                    $captif = GroupeMercenaire::create([
                        'groupe_id' => $groupe->id,
                        'mercenaire_id' => $captifMercenaire->id,
                        'recruteur_personnage_id' => null,
                        'pv_body' => $captifMercenaire->pv_body,
                        'position_x' => $case['x'],
                        'position_y' => $case['y'],
                        'etat' => 'captif',
                    ]);

                    $quete->update(['captif_mercenaire_id' => $captif->id]);
                }
                // ⚠ Aucun captif sourcé disponible, ou salle sans case libre :
                // la quête reste jouable — `Quete::objectifAccompli()` tient
                // « secourir » pour accompli quand `captif` est null (voir son
                // docblock), jamais une mission silencieusement impossible.
            }

            $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

            return $quete;
        });

        // Usages de Dread réarmés pour cette nouvelle quête (MoteurDread).
        $this->dread->reinitialiserUsages($quete);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'quete_demarree',
            'quete_id' => $quete->id,
            'titre' => $quete->titre,
            'position_arc' => $positionArc,
            'type_jalon' => $typeJalon,
            'budget' => $budget,
            'nb_monstres' => count($monstres),
            // Trace de la fouille pour le débogage et la relecture d'une partie.
            // La salle-coffre reste HORS d'EtatGroupe : la table ne doit pas
            // savoir où chercher — le journal système n'est pas diffusé aux
            // écrans de jeu.
            'deck_taille' => count($fouille['deck']),
            'salle_artefact' => $fouille['salle_artefact'],
            'artefact_objet_id' => $fouille['artefact_objet_id'],
        ]);

        // Snapshot `debut_quete` (contrat « Snapshots & reprise ») : l'état
        // vivant complet, base du « recharger » après TPK (doc 05 §6).
        $this->sauvegarde->snapshotter($groupe->refresh(), Sauvegarde::ETIQUETTE_DEBUT_QUETE);

        // Toute mutation d'état → journal puis broadcast `.groupe.etat` (contrat).
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe)));

        // Cérémonie de lancement (tous prêts → c'est parti) : réplique scriptée
        // du narrateur, jouée IMMÉDIATEMENT (vraie voix si l'asset existe), AVANT
        // la narration d'ambiance de l'IA. Toujours disponible, sans LLM.
        // Journalisée (type narration, séquencée) : sans ça, cette cérémonie —
        // diffusée SANS file d'attente — pourrait devancer sur l'écran une
        // narration plus ANCIENNE mais encore en cours de génération (job lent
        // de la quête précédente, ex. le coup fatal d'un TPK) sans que le
        // client puisse détecter l'inversion.
        $ceremonie = $this->narration->lancement();
        $evenementCeremonie = Journal::ajouter($groupe, 'narration', ['texte' => $ceremonie['texte']]);
        broadcast(new NarrationDiffusee(
            $groupe, $ceremonie['texte'],
            ambiance: $ceremonie['ambiance'], queteId: $quete->id, url: $ceremonie['url'],
            sequence: $evenementCeremonie->sequence,
        ));

        // Mise en récit SYNCHRONE (bascule 2026-08-18, plus d'appel LLM en
        // cours de partie) : le texte est PIOCHÉ dans le pack pré-généré de la
        // quête, avec repli sur les répliques scriptées de config/narration.php
        // — exactement ce que faisait l'ancien job GenererNarration.
        broadcast(new MjReflechit($groupe, true));

        // Pas de placeholder pertinent ici : la cérémonie de lancement
        // (ci-dessus) est déjà l'accroche nominative, ce temps fort n'est que
        // l'ambiance d'entrée dans le donjon — un événement de GROUPE, sans
        // héros/monstre/objet/or à nommer.
        $recit = $this->narration->pourQuete($quete, 'quete_demarree');

        if ($recit === null) {
            // Filet du verrou B1 : rien à lire (pack ET repli scripté absents
            // pour cette clé) → on dégèle immédiatement, même logique que le
            // `finally` de l'ancien job sur échec.
            broadcast(new MjReflechit($groupe, false));
        } else {
            $evenementRecit = Journal::ajouter($groupe, 'narration', [
                'texte' => $recit['texte'],
                'ambiance' => $recit['ambiance'],
            ]);

            broadcast(new NarrationDiffusee(
                $groupe, $recit['texte'],
                ambiance: $recit['ambiance'], queteId: $quete->id, url: $recit['url'],
                sequence: $evenementRecit->sequence,
            ));
        }

        // Habillage IA des monstres spawnés (Q6) : renomme/redécrit les
        // instances sans toucher aux stats — best effort, sans bloquer le jeu.
        HabillerMonstres::dispatch($groupe->id, $quete->id);

        foreach ($heros as $personnage) {
            GenererMenu::dispatch($groupe->id, (int) $personnage->joueur_id, (int) $personnage->id);
        }

        return $quete;
    }

    /**
     * Titre affiché de la quête.
     *
     * Le PLAN DE CAMPAGNE porte déjà un titre narratif pour chaque jalon
     * (« Le Gardien Déchu du Seuil ») : on le reprend quand la position
     * correspond. Sinon on s'en tient à « Quête N ».
     *
     * On composait auparavant « Quête N — {nom du gabarit} », ce qui affichait
     * au joueur un libellé de MODÈLE interne — « Exploration simple », « Antre
     * du sous-boss », « Confrontation finale » —, et ignorait les titres que
     * l'IA avait écrits pour la campagne (signalé par René, 2026-08-07). Même
     * famille que le slug de groupe qui s'était glissé dans la fiction.
     */
    private function titreQuete(Groupe $groupe, int $positionArc): string
    {
        foreach ((array) data_get($groupe->plan_campagne, 'jalons', []) as $jalon) {
            if ((int) ($jalon['position'] ?? 0) === $positionArc && ! empty($jalon['titre'])) {
                return (string) $jalon['titre'];
            }
        }

        return "Quête {$positionArc}";
    }

    /**
     * Type du jalon courant — `JalonsCampagne`, point de passage unique : le
     * squelette de campagne s'il existe, sinon la MÊME cadence de sous-boss
     * que celle exigée de l'IA (le repli ne plaçait que le boss final, si
     * bien qu'une campagne sans clé d'API n'avait aucun sous-boss).
     */
    private function typeJalon(Groupe $groupe, int $positionArc): string
    {
        return app(JalonsCampagne::class)->type($groupe, $positionArc);
    }

    /**
     * Index de la salle contenant (x, y) dans la liste des salles assemblées,
     * ou null (couloir). Sert à décider la visibilité initiale des monstres.
     *
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     */
    /**
     * Un emplacement de quête « normale » sur
     * `RATIO_SECOURIR` devient une mission « secourir » (chantier 3b,
     * 2026-10-04) — jamais plus, et jamais si aucun profil de captif SOURCÉ
     * n'existe au catalogue (Gothar aujourd'hui ; cf. `MercenaireSeeder`) :
     * un gabarit sans contenu à poser serait une mission muette. Rotation
     * déterministe (graine du groupe + position d'arc), jamais `random_int`
     * — même discipline que `substituerVarianteDistance()` : une quête
     * recommencée ou reprise depuis un snapshot doit retrouver le MÊME
     * gabarit, pas en tirer un autre.
     *
     * ⚠ Simplification NOMMÉE : aucun filtre par thème de bestiaire ici (à la
     * différence des monstres/terrains). Gothar vient de *The Frozen Horror*,
     * mais rien n'empêche aujourd'hui une mission de sauvetage de tomber dans
     * un thème différent — l'IA l'habille (nom, récit) sans toucher au bloc
     * de stats, donc rien ne ROMPT, mais la couleur de boîte peut être
     * incohérente avec le thème tiré. À resserrer avec un filtre
     * `BestiaireGroupe::contient()` si une seconde occurrence sourcée
     * (le Prospecteur, la Princesse Millandriel) rend la généralisation
     * payante — cf. docs/regles/combat-et-tour.md.
     */
    private const RATIO_SECOURIR = 4;

    private function choisirGabarit(string $typeJalon, Groupe $groupe, int $positionArc): GabaritQuete
    {
        $candidats = GabaritQuete::query()->where('type_jalon', $typeJalon)->orderBy('id')->get();

        if ($candidats->isEmpty()) {
            $candidats = GabaritQuete::query()->orderBy('id')->get();
        }

        if ($candidats->isEmpty()) {
            throw new RuntimeException('Aucun gabarit de quête en base — seeder les gabarits avant de démarrer.');
        }

        $secourirs = $candidats->filter(fn (GabaritQuete $g) => data_get($g->structure, 'objectif') === 'secourir');
        $autres = $candidats->filter(fn (GabaritQuete $g) => data_get($g->structure, 'objectif') !== 'secourir');

        if ($secourirs->isNotEmpty() && Mercenaire::where('captif', true)->exists()
            && (crc32($groupe->identifiant) + $positionArc) % self::RATIO_SECOURIR === 0) {
            return $secourirs->first();
        }

        return $autres->first() ?? $candidats->first();
    }

    /**
     * Une case de SOL de la salle donnée, libre de toute figure de DÉPART
     * (héros ou monstre) — balayage déterministe ligne par ligne, jamais un
     * tirage au hasard : la même carte assemblée rend toujours la même case
     * (reprise, « Recommencer la quête »). `null` si la salle est inconnue ou
     * entièrement occupée — le captif de la mission « secourir » reste alors
     * simplement non posé, voir l'appelant.
     *
     * @param  array<string, mixed>  $carte
     * @param  array<string, mixed>  $salle  {x, y, largeur, hauteur}
     * @return array{x: int, y: int}|null
     */
    private function caseLibreDansSalle(array $carte, array $salle): ?array
    {
        if ($salle === [] || ! isset($salle['x'], $salle['y'], $salle['largeur'], $salle['hauteur'])) {
            return null;
        }

        $cases = (array) data_get($carte, 'cases', []);
        $occupees = [];

        foreach ([...(array) ($carte['spawn_heros'] ?? []), ...(array) ($carte['spawn_monstres'] ?? [])] as $p) {
            $occupees[$p['x'].','.$p['y']] = true;
        }

        for ($y = (int) $salle['y']; $y < (int) $salle['y'] + (int) $salle['hauteur']; $y++) {
            for ($x = (int) $salle['x']; $x < (int) $salle['x'] + (int) $salle['largeur']; $x++) {
                if (($cases[$y][$x] ?? null) === 's' && ! isset($occupees["{$x},{$y}"])) {
                    return ['x' => $x, 'y' => $y];
                }
            }
        }

        return null;
    }

    /**
     * Multiplicateur de `cout` pour une créature ÉTHÉRÉE (René, 2026-09-04).
     *
     * ⚠ Une éthérée ne se blesse à l'arme que sur un **bouclier noir** (1/6) au
     * lieu d'un crâne (3/6), pendant qu'elle pare toujours sur 1/6 : les dégâts
     * nets valent (attaque − défense)/6 au lieu de (3·attaque − défense)/6.
     * Mesuré sur l'Ombre du Dread à 5 dés d'attaque, cela la rend **6 fois** plus
     * longue à abattre qu'un bloc de stats identique non éthéré.
     *
     * ⚠ On ne facture pourtant PAS ×6, et c'est délibéré : le livret excepte
     * « sort ou artefact », que le moteur applique — un groupe qui a de la magie
     * la traverse comme n'importe quel monstre. ×6 lui donnerait le prix d'une
     * rencontre entière et la laisserait seule sur la carte, sans escorte. ×2
     * dit « elle vaut deux monstres de son bloc » : c'est une valeur de départ de
     * playtest, comme tous les `cout` du bestiaire, qui n'existent sur aucun
     * livret.
     */
    public const RATIO_COUT_ETHERE = 2.0;

    /**
     * Variante À DISTANCE générique (Against the Ogre Horde p. 8, Q6 — René
     * 2026-10-02 : « allons-y générique ») : « Zargon may place a standard
     * monster or a ranged version of that same monster type ». Le livret ne
     * chiffre AUCUNE proportion — c'est une décision de portage, pas une
     * lecture : UN emplacement sur `RATIO_VARIANTE_DISTANCE` d'un monstre de
     * base qui a une variante déclarée (`monstres.variante_distance_de`)
     * devient sa version à distance.
     *
     * ⚠ Par ROTATION déterministe (graine groupe + position d'arc + rang
     * d'achat de ce monstre dans la rencontre), JAMAIS par `random_int` —
     * même discipline que le choix du boss (`($graineGroupe + $positionArc) %
     * count`, plus haut) : une rencontre est un PLACEMENT, elle doit rester
     * identique si la quête est recommencée ou reprise depuis un snapshot.
     */
    public const RATIO_VARIANTE_DISTANCE = 3;

    /**
     * Les boîtes d'extension entre lesquelles tourne le THÈME d'une campagne
     * (René, 2026-09-04 : « une bonne diversité selon le thème »).
     *
     * ⚠ Il n'existait AUCUNE notion de thème dans la génération : elle ne
     * connaissait que `tier` et `cout`, si bien qu'une quête glacée et une quête
     * de jungle puisaient dans le même sac. Le thème ne venait que de
     * l'habillage IA — lequel RENOMME ce qui est déjà là et ne choisit jamais
     * quelle créature apparaît.
     *
     * Le jeu de base (`base`) n'y figure pas : ses huit cartes sont le fond
     * commun de toutes les quêtes, pas un thème parmi d'autres.
     */
    public const BOITES_THEMATIQUES = [
        'dread_moon',
        'mage_du_miroir',
        'horde_ogre',
        'jungles_delthrak',
        // ⚠ Rallumée le 2026-09-06, après que les cinq règles nommées par
        // `BOITES_INCOMPLETES` ont été portées. Passer de 4 à 5 thèmes change le
        // modulo de `themeBestiaire()` — c'est précisément pourquoi le thème est
        // désormais FIGÉ dans `groupes.theme_bestiaire` au démarrage : sans cette
        // colonne, une campagne en cours serait passée de la jungle à la banquise
        // entre deux quêtes.
        'horreur_des_glaces',
        // Ajoutée le 2026-09-30 (René) : même raison, modulo 5 → 6, même garde-fou
        // (la colonne figée protège toute campagne déjà en cours). Boss :
        // le Dragon (`MonstreSeeder`, `grande_taille`, `vol_draconique`,
        // `sort_a_volonte`) — voir `database/seeders/GabaritQueteSeeder.php`
        // (`rencontre_finale.creatures`) pour la rencontre finale.
        'first_light',
        // Ajoutée le 2026-10-08 (René, Q5 : « le thème entre quand les cinq
        // sorciers sont jouables »), modulo 6 → 7, même garde-fou : la colonne
        // figée protège toute campagne démarrée depuis le 2026-09-06, et la
        // migration `2026_10_08_150000_figer_theme_bestiaire_des_groupes_existants`
        // fige l'ancien modulo des groupes qui ont déjà joué sans l'avoir
        // écrit. Rencontre finale : la GARDIENNE (Artificière, seul boss de la
        // boîte — les quatre autres sorciers sont des SOUS-BOSS, les
        // « lieutenants de Morcar » du livret G1504) ; signatures de masse :
        // Golem, Dreadshifter (tier base), Minotaure (sous-boss). Voir
        // `docs/regles/bestiaire-et-rencontres.md` et
        // `database/seeders/GabaritQueteSeeder.php`.
        'wizards_of_morcar',
    ];

    /**
     * Boîtes DÉSACTIVÉES parce que leurs règles sont incomplètes (René,
     * 2026-09-04 : « je désactiverais pour le moment horreur des glaces vu qu'il
     * manque des règles »).
     *
     * ⚠ Elles sont déclarées ICI plutôt que simplement absentes de la liste du
     * dessus : sans cette entrée, le test qui exige qu'aucune créature d'un
     * palier ne soit inatteignable verrait l'Horreur des Glaces disparaître du
     * pool et le signalerait comme une régression. Écarter du contenu doit être
     * un choix ÉCRIT, avec sa raison — c'est la même discipline que les cartes
     * non portées de `config/cartes.php`, chacune nommant la mécanique qui lui
     * manque.
     *
     * @var array<string, string>
     */
    public const BOITES_INCOMPLETES = [
        // ⚠ `horreur_des_glaces` est sortie d'ici le 2026-09-06, quand les cinq
        // règles qu'elle nommait ont été portées : les trois sorts de son boss
        // (Gel de l'Esprit, Mur de Glace, Patinage — ses SIX sorts fixes sont
        // désormais tous là), l'étreinte du Yéti et le vol du Gremlin. Son
        // équipement reste écarté, mais il l'était déjà sur ses propres
        // mérites : sept cartes de glace restent des dettes NOMMÉES dans
        // `config/cartes.php`, ce qui n'a jamais empêché une boîte de tourner
        // — le thème et le boss sont ce qui était retiré, pas le matériel de
        // trésor.
        //
        // Le dispositif RESTE en place, et c'est voulu : une boîte se retire
        // par une phrase écrite qui dit POURQUOI, jamais par une absence
        // silencieuse de la liste ci-dessus. `SorciersNommesTest` vérifie les
        // deux sens — rien de désactivé ne peut être offert en thème, rien
        // d'offert ne peut être désactivé.
        //
        // `prophecy_telor` (chantier monstre à phases, 2026-10-04) : seul le
        // Sorcier du Dread générique (sous-boss, répertoire limité par
        // palier) est semé pour cette boîte — son BOSS sourcé, Fellmarak le
        // Roi Sorcier, ne meurt pas normalement (il fuit en quête 12, meurt
        // par tirage aléatoire en quête 13 — `docs/regles/bestiaire-et-
        // rencontres.md`) et n'a aucun des deux mécanismes construit. Semer
        // le thème sans boss jouable referait l'erreur déjà nommée pour
        // l'Horreur des Glaces : « un boss final à moitié écrit n'a rien à
        // faire en tête d'affiche » — ici, un boss qui n'a MÊME PAS de mort
        // ordinaire.
        'prophecy_telor' => 'Fellmarak (son seul boss sourcé) ne meurt pas '
            .'normalement — il fuit en quête 12, meurt par tirage aléatoire '
            .'en quête 13 — et aucun des deux mécanismes n\'est construit ; '
            .'le Sorcier du Dread générique reste seedé comme sous-boss.',
    ];

    /**
     * Thème du bestiaire d'un groupe — une boîte, pour toute la campagne.
     *
     * ⚠ Une ROTATION sur l'id du groupe, comme le boss final : deux groupes ne
     * descendent pas dans le même bestiaire, et le thème d'une campagne ne
     * change jamais en cours de route. C'est un PLACEMENT, au même titre que
     * `salle_artefact` — le tirer à chaque quête ferait passer le groupe de la
     * banquise à la jungle entre deux portes.
     */
    public function themeBestiaire(int $graineGroupe): string
    {
        return self::BOITES_THEMATIQUES[$graineGroupe % count(self::BOITES_THEMATIQUES)];
    }

    /**
     * Thème EFFECTIF d'un groupe — la valeur FIGÉE (`groupes.theme_bestiaire`,
     * phase 6a) si elle existe, sinon le calcul historique. Jamais `null`.
     *
     * ⚠ C'est le point de passage que tout appelant doit utiliser désormais
     * pour « quel est le thème DE CE GROUPE » — `acheterMonstres()` et
     * `AssembleurCarte::placerTerrains()` compris — plutôt que rappeler
     * `themeBestiaire((int) $groupe->id)` directement : ce dernier recalcule
     * la rotation sur la longueur ACTUELLE de `BOITES_THEMATIQUES`, qui va
     * passer de 4 à 5 entrées, exactement ce que la colonne existe pour
     * empêcher de faire dériver une campagne en cours.
     *
     * ⚠ Un groupe dont la colonne n'a pas encore été remplie (elle ne l'est
     * qu'au PROCHAIN démarrage de quête, cf. `demarrer()`) retombe ICI sur le
     * calcul historique — jamais sur `null` : une campagne déjà en cours au
     * moment où cette colonne arrive ne doit rien voir changer tant qu'elle
     * n'a pas rejoué.
     */
    public function themeBestiaireDuGroupe(Groupe $groupe): string
    {
        return $groupe->theme_bestiaire ?? $this->themeBestiaire((int) $groupe->id);
    }

    /**
     * Libellé HUMAIN de chaque entrée de `BOITES_THEMATIQUES` — le nom
     * OFFICIEL anglais de la boîte, tel qu'il figure sur `reference/18_extensions.md`
     * (titres de section), jamais une traduction inventée (René, 2026-09-24 :
     * « ne jamais semer une valeur que les livrets ne sourcent pas » couvre
     * aussi les LIBELLÉS, pas seulement les stats — aucun de ces noms n'a de
     * traduction française officielle publiée par Hasbro).
     *
     * ⚠ SEULE source du projet pour ce texte : `EtatGroupe` la lit pour ne
     * jamais laisser un CLIENT traduire un identifiant de boîte — même
     * discipline que `objectif_libelle` (le serveur publie la décision, pas
     * les ingrédients).
     *
     * ⚠ Registre testé DANS LES DEUX SENS (`ThemeBestiaireLibelleTest`) :
     * aucune entrée de `BOITES_THEMATIQUES` sans libellé ici, aucun libellé
     * ici qui ne soit une entrée de `BOITES_THEMATIQUES` — la même discipline
     * que `config/cartes.php` et `MotsCles*` (CLAUDE.md « une registry est
     * testée BOTH WAYS »).
     */
    public const LIBELLES_BOITES = [
        // reference/18_extensions.md ligne 796 : « Rise of the Dread Moon »
        'dread_moon' => 'Rise of the Dread Moon',
        // reference/18_extensions.md ligne 665 : « The Mage of the Mirror »
        'mage_du_miroir' => 'The Mage of the Mirror',
        // reference/18_extensions.md ligne 300 : « Against the Ogre Horde »
        'horde_ogre' => 'Against the Ogre Horde',
        // reference/18_extensions.md ligne 1238 : « Jungles of Delthrak »
        'jungles_delthrak' => 'Jungles of Delthrak',
        // reference/18_extensions.md ligne 504 : « The Frozen Horror »
        'horreur_des_glaces' => 'The Frozen Horror',
        // reference/18_extensions.md ligne 1536 : « First Light (2024) » —
        // l'année fait partie du TITRE de section imprimé par René dans le
        // document, jamais de la boîte elle-même (aucune autre entrée ne
        // porte son année, et Hasbro ne date pas le nom sur la boîte).
        'first_light' => 'First Light',
        // reference/18_extensions.md ligne 2400 : « Wizards of Morcar (2025) » —
        // même remarque que First Light : l'année est dans le titre de section,
        // pas dans le nom de la boîte.
        'wizards_of_morcar' => 'Wizards of Morcar',
    ];

    /**
     * Libellé lisible d'une boîte de bestiaire — jamais l'identifiant brut,
     * qui n'a de sens que pour le moteur. Repli sur l'identifiant lui-même
     * quand il manque à `LIBELLES_BOITES` (`fail open` : un thème mal
     * enregistré doit s'afficher plutôt que casser l'écran — les deux sens du
     * registre sont de toute façon verrouillés par un test).
     */
    public function libelleBoiteBestiaire(string $boite): string
    {
        return self::LIBELLES_BOITES[$boite] ?? $boite;
    }

    /**
     * COÛT EFFECTIF d'une créature dans le budget de rencontre — le seul calcul
     * qui fasse foi.
     *
     * ⚠ SEUL point de passage pour ce qu'on PAIE. Ne majorer que l'achat du boss
     * aurait laissé le **Spectre** — éthéré, tier base, acheté comme sbire
     * ordinaire — à son prix d'avant : le même défaut, un palier plus bas.
     *
     * ⚠ Mais il ne touche PAS au CLASSEMENT, et la distinction a été trouvée en
     * mesurant : les « forts » s'achètent du plus cher au moins cher, et le
     * leader de coût ferme la rencontre. Majorer le rang aurait donc **promu**
     * les éthérées au lieu de les rationner — le Spectre serait passé devant
     * tous les autres forts et se serait invité dans presque chaque quête,
     * exactement l'inverse du but. On trie sur `cout` brut (ce que la créature
     * VAUT) et on débite `coutEffectif()` (ce qu'elle COÛTE).
     */
    public function coutEffectif(Monstre $monstre): int
    {
        $capacites = (array) ($monstre->capacites ?? []);
        $ethere = in_array('ethere', $capacites, true) || array_key_exists('ethere', $capacites);

        return $ethere
            ? (int) ceil((int) $monstre->cout * self::RATIO_COUT_ETHERE)
            : (int) $monstre->cout;
    }

    /**
     * Monstres de base ayant une variante À DISTANCE déclarée (Q6), filtrés
     * par ce que CE bestiaire autorise (`BestiaireGroupe::autorise()`) et
     * indexés par le nom_base du monstre STANDARD — c'est la clé de lecture
     * de `substituerVarianteDistance()`.
     *
     * ⚠ Les variantes sont `boite: null` (génériques, disponibles dans tout
     * thème) donc `autorise()` les laisse toujours passer ; le filtre reste
     * ici pour ne jamais dépendre d'une hypothèse sur leur `boite` future.
     *
     * @return Collection<string, Monstre>
     */
    private function variantesDistanceParBase(BestiaireGroupe $bestiaire): Collection
    {
        return Monstre::query()->whereNotNull('variante_distance_de')->get()
            ->filter(fn (Monstre $v) => $bestiaire->autorise($v->boite))
            ->keyBy('variante_distance_de');
    }

    /**
     * Substitue, par ROTATION déterministe, un monstre STANDARD par sa
     * variante À DISTANCE déclarée — un emplacement sur
     * `RATIO_VARIANTE_DISTANCE` (voir sa doc). Rend `$standard` inchangé si :
     * il n'a pas de variante, la rotation ne tombe pas sur ce rang, ou la
     * variante est trop chère pour le budget restant (`$restant`) — jamais de
     * dépassement de budget au nom d'une substitution.
     *
     * `$occurrences` est un compteur PAR NOM DE BASE, incrémenté à chaque
     * appel pour ce nom : c'est le rang qui entre dans la rotation, exactement
     * comme `$positionArc` le fait pour le boss plus haut.
     *
     * @param  array<string, int>  $occurrences  passé par référence, monte à chaque appel
     */
    private function substituerVarianteDistance(
        Monstre $standard,
        Collection $variantes,
        int $graineGroupe,
        int $positionArc,
        array &$occurrences,
        int $restant,
    ): Monstre {
        $variante = $variantes->get($standard->nom_base);
        if ($variante === null) {
            return $standard;
        }

        $occurrences[$standard->nom_base] = ($occurrences[$standard->nom_base] ?? -1) + 1;
        $rang = $graineGroupe + $positionArc + $occurrences[$standard->nom_base];

        if ($rang % self::RATIO_VARIANTE_DISTANCE !== 0) {
            return $standard;
        }

        return $this->coutEffectif($variante) <= $restant ? $variante : $standard;
    }

    /**
     * Budget de rencontres en points de `cout` du bestiaire (doc 06 §2) :
     * score de puissance × escalade d'arc (+15 %/quête) × facteur de jalon.
     */
    private function budgetRencontres(Groupe $groupe, int $positionArc, string $typeJalon): int
    {
        $escalade = 1.0 + 0.15 * ($positionArc - 1);

        return (int) round($this->puissance->calculer($groupe) * $escalade * $this->facteurJalon($groupe, $typeJalon));
    }

    /**
     * Facteur de jalon appliqué au budget (sous-boss / boss ont plus de monstres
     * autour d'eux). Configurable + ADOUCI à bas niveau (§3) : à niveau moyen 1,
     * on part de `jalon_boss_debut` (moins de serviteurs autour du boss — un
     * groupe niveau 1 qui affronte déjà un boss, ex. campagne « très courte ») ;
     * on monte vers le facteur plein à partir de `jalon_boss_niveau_plein`.
     */
    private function facteurJalon(Groupe $groupe, string $typeJalon): float
    {
        if (! in_array($typeJalon, ['sous_boss', 'boss_final'], true)) {
            return 1.0;
        }

        $plein = $typeJalon === 'boss_final'
            ? (float) config('jeu.rencontres.jalon_boss_final', 1.5)
            : (float) config('jeu.rencontres.jalon_sous_boss', 1.25);

        $debut = (float) config('jeu.rencontres.jalon_boss_debut', 1.1);
        $niveauPlein = max(2, (int) config('jeu.rencontres.jalon_boss_niveau_plein', 3));

        // Rampe linéaire du niveau moyen 1 (→ $debut) au niveau plein (→ $plein).
        $t = min(1.0, max(0.0, ($this->niveauMoyen($groupe) - 1) / ($niveauPlein - 1)));

        return min($debut + ($plein - $debut) * $t, $plein);
    }

    /** Niveau moyen des héros ACTIFS du groupe (1.0 par défaut). */
    private function niveauMoyen(Groupe $groupe): float
    {
        $niveaux = $groupe->personnages()->wherePivot('actif', true)->pluck('niveau');

        return $niveaux->isEmpty() ? 1.0 : (float) $niveaux->avg();
    }

    /**
     * Dépense le budget en monstres du catalogue : la rencontre finale du
     * gabarit (tier sous_boss/boss) est achetée d'abord — toujours présente,
     * même si elle dépasse le budget — puis QUELQUES monstres FORTS (haut du
     * tier base), et enfin la MASSE de FAIBLES (bas du tier), dans la limite des
     * positions de spawn de la carte. Objectif de playtest : « beaucoup
     * d'ennemis faibles + quelques ennemis forts » (config `jeu.rencontres`).
     *
     * @param  array<string, mixed>  $structure
     * @param  ?BestiaireGroupe  $bestiaire  bestiaire du groupe (automatique ou
     *                          manuel, `BestiaireGroupe::duGroupe()`), transmis
     *                          par l'appelant — repli sur la rotation
     *                          automatique de `$graineGroupe` quand absent
     *                          (appel direct en test par réflexion).
     * @return list<Monstre>
     */
    private function acheterMonstres(array $structure, int $budget, int $maxSpawns, int $positionArc, int $graineGroupe = 0, ?BestiaireGroupe $bestiaire = null): array
    {
        $achats = [];
        $restant = $budget;
        // ⚠ Une SEULE résolution du thème pour toute la méthode — le pool du
        // boss final et le tri des monstres « forts » lisaient jusqu'ici
        // chacun leur propre `themeBestiaire($graineGroupe)`, deux appels pour
        // la même valeur. `$bestiaire` (déjà résolu par l'appelant depuis le
        // groupe) prévaut ; le repli ne sert qu'aux deux
        // tests qui invoquent cette méthode directement par réflexion avec un
        // simple entier.
        $bestiaire ??= BestiaireGroupe::auto($this->themeBestiaire($graineGroupe));

        $tierFinal = data_get($structure, 'rencontre_finale.tier');
        if (is_string($tierFinal)) {
            // POOL de sorciers nommés éligibles à la rencontre finale, TIRÉ AU
            // SORT (René, 2026-09-04 : « assigne un archétype à chaque gabarit
            // pour que les lanceurs nommés apparaissent »).
            //
            // ⚠ C'est une LISTE et pas une valeur unique, et c'est tout l'objet
            // du correctif. Le champ `archetype` existait au singulier depuis la
            // 3.8, fonctionnait, et **aucun gabarit ne l'avait jamais rempli** :
            // le repli prenait donc toujours le leader de coût du palier, si
            // bien que le Seigneur fermait TOUTES les quêtes et qu'aucun lanceur
            // nommé — Liche, Sorcier des Tempêtes, Ombre du Dread, Horreur des
            // Glaces, Archimage elfe — n'avait jamais été tiré en partie. C'est
            // la leçon des leviers, qui exigeaient des coordonnées qu'aucun
            // gabarit ne déclarait : un champ qui marche mais que personne ne
            // remplit est aussi muet qu'un champ sans lecteur.
            //
            // ⚠ Le singulier reste lu : une donnée de gabarit antérieure, ou un
            // test qui désigne UN adversaire précis, doivent continuer à valoir.
            $pool = data_get($structure, 'rencontre_finale.archetypes');
            $pool = is_array($pool) && $pool !== []
                ? $pool
                : array_filter([data_get($structure, 'rencontre_finale.archetype')], 'is_string');

            // ⚠ …ET une liste de CRÉATURES nommées, ajoutée le 2026-09-04 pour
            // réparer une régression que la rotation venait de créer. Le pool ne
            // se déclarait qu'en archétypes, or **seuls les lanceurs en ont un** :
            // sur les 13 sous-boss du bestiaire, DEUX pouvaient apparaître, et
            // les onze exclus étaient précisément les plus caractéristiques —
            // ceux qui pondent, empoisonnent, régénèrent. La rotation avait
            // troqué « toujours le même » contre « deux, et on perd les onze
            // autres ». Une brute n'a pas de répertoire ; elle doit pouvoir être
            // nommée telle quelle.
            $creatures = (array) data_get($structure, 'rencontre_finale.creatures', []);

            $final = null;
            if ($pool !== [] || $creatures !== []) {
                $candidats = Monstre::query()
                    ->where('tier', $tierFinal)
                    ->where(function ($q) use ($pool, $creatures) {
                        $q->whereIn('archetype_lanceur', $pool)->orWhereIn('nom_base', $creatures);
                    })
                    ->orderBy('id')->get()
                    // Bestiaire MANUEL : un FILTRE, avant toute préférence —
                    // une boîte non cochée n'entre jamais (2026-09-28).
                    ->filter(fn (Monstre $m) => $bestiaire->autorise($m->boite))
                    ->values();

                // ⚠ ROTATION, pas tirage — et la distinction est celle que le
                // projet fait déjà entre `salle_artefact` et le deck de fouille.
                // Le boss final est un **placement** : il doit rester le MÊME si
                // le groupe recommence la quête ou reprend un snapshot, sans
                // quoi « Recommencer » deviendrait un bouton pour changer
                // d'adversaire jusqu'à tomber sur le plus commode. Un
                // `random_int` le re-tirerait à chaque appel — et il rendait de
                // surcroît la suite de tests intermittente, ce qui est pire
                // qu'un test rouge.
                //
                // L'index combine la POSITION D'ARC (l'adversaire change d'un
                // jalon à l'autre) et l'ID DU GROUPE (deux groupes ne suivent
                // pas la même succession), ce qui donne de la variété sans
                // hasard. `orderBy('id')` fige l'ordre des candidats.
                // ⚠ Le THÈME resserre le pool avant la rotation, et ne le vide
                // jamais : s'il ne contient aucune créature de la boîte, on garde
                // le pool entier. Une préférence, pas un filtre — c'est ce qui
                // permet à une boîte pauvre en boss (la Horde ogre n'a que des
                // brutes) de rester jouable.
                $duTheme = $candidats->filter(fn (Monstre $m) => $bestiaire->contient($m->boite))->values();
                $candidats = $duTheme->isNotEmpty() ? $duTheme : $candidats;

                $final = $candidats->isEmpty()
                    ? null
                    : $candidats[($graineGroupe + $positionArc) % $candidats->count()];
            }

            // Repli : pool vide, ou aucun de ses archétypes porté par une
            // créature de ce palier → leader de coût du tier, le comportement
            // d'origine. Une donnée de référence absente ne doit jamais empêcher
            // une quête de démarrer.
            $final ??= Monstre::query()->where('tier', $tierFinal)->orderByDesc('cout')->orderBy('id')->get()
                ->first(fn (Monstre $m) => $bestiaire->autorise($m->boite));

            if ($final !== null) {
                $achats[] = $final;
                $restant = max(0, $restant - $this->coutEffectif($final));
            }
        }

        // Tier « base » partitionné en FAIBLES (bas coût) et FORTS (haut coût),
        // selon le seuil de config. On veut « beaucoup de faibles + quelques forts ».
        $seuil = (int) ($this->parametres()?->rencontres_seuil_cout_fort
            ?? config('jeu.rencontres.seuil_cout_fort', 3));
        /** @var Collection<int, Monstre> $base */
        // ⚠ `whereNull('variante_distance_de')` (Q6) : une variante à distance
        // n'entre PAS dans ce pool comme un monstre de plus — elle ne joue
        // qu'en SUBSTITUTION de son monstre standard (`substituerVarianteDistance()`,
        // plus bas), exactement comme le livret le décrit (« Zargon may place
        // a standard monster OR a ranged version »). Sans cette exclusion, les
        // deux auraient coexisté comme deux entrées indépendantes du
        // round-robin — un Gobelin ET un Gobelin archer auraient pu être
        // achetés dans la MÊME rencontre, ce qu'aucune carte ne décrit.
        $base = Monstre::query()->where('tier', 'base')->where('cout', '>', 0)
            ->whereNull('variante_distance_de')
            ->orderBy('cout')->orderBy('id')->get()
            ->filter(fn (Monstre $m) => $bestiaire->autorise($m->boite))
            ->values();
        $faibles = $base->filter(fn (Monstre $m) => (int) $m->cout <= $seuil)->values();       // coût croissant

        // ⚠ Les QUELQUES forts viennent du thème quand il en propose, la MASSE
        // de faibles non — et c'est exactement ainsi que les boîtes officielles
        // sont bâties : elles ajoutent quelques créatures signature au bestiaire
        // commun, elles ne le remplacent pas. Filtrer les faibles aurait donné
        // un donjon de Gremlins (la boîte des glaces n'a qu'une créature de tier
        // base) ; ne rien filtrer du tout ne montrait jamais la signature.
        $forts = $base->filter(fn (Monstre $m) => (int) $m->cout > $seuil)
            ->sortByDesc(fn (Monstre $m) => [$bestiaire->contient($m->boite) ? 1 : 0, (int) $m->cout])
            ->values();

        // Aucun « faible » défini (seuil mal réglé / bestiaire atypique) : tout le
        // tier base sert de masse, pour ne jamais bloquer la génération.
        if ($faibles->isEmpty()) {
            $faibles = $base->sortBy('cout')->values();
            $forts = collect();
        }
        // ⚠ Le plancher de réserve se lit sur le coût EFFECTIF : c'est ce qu'il
        // faudra vraiment payer pour garder un faible en fin de liste.
        $coutFaibleMin = (int) ($faibles->map(fn (Monstre $m) => $this->coutEffectif($m))->min() ?? 1);

        // 1) QUELQUES forts (haut de gamme), en gardant assez de budget ET
        //    d'emplacements pour la masse de faibles (on réserve ≥ 1 slot faible).
        $fortsSouhaites = (int) ($this->parametres()?->rencontres_forts_par_quete
            ?? config('jeu.rencontres.forts_par_quete', 1));
        $escaladeArc = (int) ($this->parametres()?->rencontres_forts_escalade_arc
            ?? config('jeu.rencontres.forts_escalade_arc', 0));
        if ($escaladeArc > 0) {
            $fortsSouhaites += intdiv(max(0, $positionArc - 1), $escaladeArc);
        }
        for ($i = 0; $i < $fortsSouhaites && count($achats) < $maxSpawns - 1; $i++) {
            // le plus fort abordable qui laisse encore de quoi payer un faible
            // ⚠ Un fort DÉJÀ acheté passe après les autres (2026-10-08, thème Morcar) :
            // `first()` sur une liste jamais consommée rachetait indéfiniment le
            // même (le Golem), si bien que le Dreadshifter — et en général tout
            // fort qui n'est pas le plus cher du thème — n'apparaissait JAMAIS
            // dès que le budget payait le premier. Le repli sur un doublon
            // garde le comportement d'avant quand il n'y a plus de choix.
            $abordables = $forts->filter(fn (Monstre $m) => $this->coutEffectif($m) <= $restant - $coutFaibleMin);
            $dejaAchetes = collect($achats)->pluck('id')->all();
            $fort = $abordables->first(fn (Monstre $m) => ! in_array($m->id, $dejaAchetes, true)) ?? $abordables->first();
            if ($fort === null) {
                break;
            }
            $achats[] = $fort;
            $restant -= $this->coutEffectif($fort);
        }

        // 2) La MASSE de faibles : round-robin sur les faibles (un peu de variété)
        //    tant que budget et emplacements le permettent → beaucoup d'ennemis
        //    individuellement peu dangereux.
        //
        // ⚠ C'est ICI, et seulement ici, qu'une variante À DISTANCE (Q6) peut
        // remplacer son monstre standard : Gobelin/Squelette/Orque sont tous
        // de tier `base` et sous le seuil « fort » par défaut, donc achetés
        // dans CETTE masse — jamais dans les « forts » ni la rencontre finale,
        // qu'aucune variante ne couvre.
        $varianteParBase = $this->variantesDistanceParBase($bestiaire);
        $occurrencesVariante = [];

        $n = $faibles->count();
        $curseur = 0;
        while ($n > 0 && count($achats) < $maxSpawns) {
            $achete = false;
            for ($k = 0; $k < $n; $k++) {
                $candidat = $this->substituerVarianteDistance(
                    $faibles[($curseur + $k) % $n], $varianteParBase, $graineGroupe, $positionArc, $occurrencesVariante, $restant,
                );
                if ($this->coutEffectif($candidat) <= $restant) {
                    $achats[] = $candidat;
                    $restant -= $this->coutEffectif($candidat);
                    $curseur = ($curseur + $k + 1) % $n;
                    $achete = true;
                    break;
                }
            }
            if (! $achete) {
                break; // plus rien d'abordable
            }
        }

        if ($achats === []) {
            throw new RuntimeException('Bestiaire vide ou budget nul : aucune rencontre générée.');
        }

        return $achats;
    }
}
