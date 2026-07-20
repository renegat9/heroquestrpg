<?php

declare(strict_types=1);

namespace App\Partie;

use App\Http\Controllers\Api\TableController;
use App\Models\Carte;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\Images\BibliothequeImages;
use App\Partie\Narration\BibliothequeNarration;
use App\Partie\ResolveurTour;
use Illuminate\Support\Facades\Cache;

/**
 * Construit le payload « EtatGroupe » du contrat (docs/contrat-api.md) —
 * réutilisé par GET /api/groupes/{identifiant}/etat ET par le broadcast
 * `.groupe.etat` (EtatGroupeDiffuse) : une seule source de forme.
 *
 * En phase hub : quete/carte sont null, entites/initiative vides.
 */
final class EtatGroupe
{
    /** Clé du cache de l'indicateur « MJ réfléchit » (écrite par MjReflechit). */
    public static function cleMjReflechit(int $groupeId): string
    {
        return "partie:mj_reflechit:{$groupeId}";
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Groupe $groupe): array
    {
        $queteCourante = $groupe->phase === 'quete' ? $groupe->queteCourante : null;
        $quete = $queteCourante;

        // TPK (doc 05 §6, contrat) : au hub, la dernière quête ÉCHOUÉE reste
        // exposée (carte/entités comprises) tant qu'elle n'est ni reprise ni
        // remplacée — le bandeau « recharger / abandonner » de la table et
        // l'écran d'attente de la manette testent quete.etat === 'echouee'.
        // (L'ambiance sonore, elle, reste calculée sur la quête COURANTE :
        // sceneAmbiance rend 'defaite' au hub après un échec, pas 'boss'.)
        if ($quete === null && $groupe->phase === 'hub') {
            $derniere = $groupe->quetes()->orderByDesc('position_arc')->first();
            if ($derniere !== null && $derniere->etat === 'echouee') {
                $quete = $derniere;
            }
        }

        $narrateurActif = TableController::narrateurActif($groupe);
        $preambuleGroupe = [
            'id' => $groupe->id,
            'identifiant' => $groupe->identifiant,
            'nom' => $groupe->nom,
            'phase' => $groupe->phase,
            'or' => (int) $groupe->or,
            'etat' => $groupe->etat,
            'narrateur_actif' => $narrateurActif,
            // Scène sonore courante (boucle d'ambiance jouée par la table).
            'ambiance' => $this->sceneAmbiance($groupe, $queteCourante),
            // Illustration du lieu de repos (hub) — générée en arrière-plan
            // (null tant qu'absente → la table affiche le fond par défaut).
            'image_url' => app(BibliothequeImages::class)->urlDyn('hub', $groupe->id),
        ];

        // En phase hub : expose les statuts « prêt » des personnages actifs.
        if ($groupe->phase === 'hub') {
            $prets = Cache::get("partie:pret:{$groupe->id}", []);
            // On porte le NOM du héros (pas seulement l'id) : sinon la manette
            // d'un joueur affichait « Perso n°59 » pour les coéquipiers dont elle
            // n'a pas le roster.
            $preambuleGroupe['prets'] = $groupe->personnages()
                ->wherePivot('actif', true)
                ->get(['personnages.id', 'personnages.nom'])
                ->map(fn ($p) => [
                    'personnage_id' => (int) $p->id,
                    'nom' => $p->nom,
                    'pret' => (bool) ($prets[$p->id] ?? false),
                ])
                ->values()
                ->all();

            // Alliés recrutés (3.5) exposés au hub : la carte étant absente, les
            // recrues ne sont pas dans `entites` (filtrées sur position) — ce bloc
            // permet à la manette (panneau de recrutement) ET à la table d'afficher
            // les alliés déjà embauchés, et met à jour la liste en direct après un
            // recrutement (EtatGroupeDiffuse). Sert aussi au front à savoir qu'un
            // compagnon animal est déjà pris (un seul par groupe).
            $preambuleGroupe['mercenaires'] = $this->mercenairesRecrutes($groupe);

            // Prologue de campagne (prémisse + menace) : exposé au hub pour que
            // l'écran de table l'affiche/le relise — `auto` (true tant qu'aucune
            // quête n'a eu lieu) déclenche l'ouverture automatique au lancement.
            $premisse = data_get($groupe->plan_campagne, 'premisse');
            if (is_string($premisse) && $premisse !== '') {
                $preambuleGroupe['prologue'] = [
                    'texte' => $premisse,
                    'url' => app(BibliothequeNarration::class)->urlDynamiqueSiCache($premisse),
                    'menace' => data_get($groupe->plan_campagne, 'menace'),
                    'auto' => ! $groupe->quetes()->exists(),
                ];
            }
        }

        $derniereNarration = $this->derniereNarration($groupe);

        return [
            'groupe' => $preambuleGroupe,
            'quete' => $quete === null ? null : [
                'id' => $quete->id,
                'titre' => $quete->titre,
                'position_arc' => $quete->position_arc,
                'type_jalon' => $quete->type_jalon,
                'etat' => $quete->etat,
                // Illustration de scène de la quête (générée en arrière-plan).
                'image_url' => app(BibliothequeImages::class)->urlDyn('quete', $quete->id),
            ],
            'carte' => $this->carte($quete),
            'entites' => $quete === null ? [] : [...$this->heros($groupe, $quete), ...$this->allies($groupe), ...$this->monstres($quete)],
            'initiative' => $quete === null ? [] : $this->initiative($groupe, $quete),
            'narration' => $derniereNarration['texte'] ?? null,
            // Séquence de la dernière narration (Evenement.sequence) : sert au
            // client à ignorer une narration qui arriverait EN RETARD derrière
            // une plus récente déjà affichée (jobs asynchrones, ordre non garanti).
            'narration_sequence' => $derniereNarration['sequence'] ?? null,
            'mj_reflechit' => (bool) Cache::get(self::cleMjReflechit($groupe->id), false),
        ];
    }

    /**
     * Scène sonore courante, dérivée de l'état (pour la boucle d'ambiance de
     * la table). En quête : `boss` si un boss/sous-boss est actif, `combat`
     * s'il reste un monstre actif, sinon `exploration`. Au hub : `victoire`
     * après le boss final vaincu (fin de campagne), `defaite` après un TPK
     * (dernière quête échouée), sinon `hub`.
     */
    private function sceneAmbiance(Groupe $groupe, ?Quete $quete): string
    {
        if ($quete !== null) {
            // Seuls les monstres RÉVÉLÉS comptent pour l'ambiance (dormants = exploration).
            $actifs = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true);

            if ((clone $actifs)->whereHas('monstre', fn ($q) => $q->whereIn('tier', ['sous_boss', 'boss']))->exists()) {
                return 'boss';
            }

            return $actifs->exists() ? 'combat' : 'exploration';
        }

        $derniere = $groupe->quetes()->orderByDesc('position_arc')->first();

        if ($derniere !== null) {
            if ($derniere->etat === 'echouee') {
                return 'defaite';
            }
            if ($derniere->etat === 'terminee' && $derniere->type_jalon === 'boss_final') {
                return 'victoire';
            }
        }

        return 'hub';
    }

    /**
     * Carte jouable — cases + pièges CONNUS (détectés / désarmés /
     * déclenchés) + portes CONNUES : les pièges encore cachés et les portes
     * secrètes non révélées n'y figurent JAMAIS, la table ne doit pas les
     * montrer (contrat).
     *
     * @return array{largeur: int, hauteur: int, cases: list<list<string>>, pieges: list<array{x: int, y: int, etat: string, nom: string}>, portes: list<array{x: int, y: int, etat: string}>}|null
     */
    private function carte(?Quete $quete): ?array
    {
        $carte = $quete?->carte;

        if ($carte === null) {
            return null;
        }

        $portes = $this->portes($carte);
        // Une porte vit désormais sur une ARÊTE : aucune case 'p' à poser — les
        // cases restent sol/mur, la porte est rendue sur la cloison (x,y,cote).
        $cases = $carte->grille['cases'] ?? [];

        // Brouillard de guerre (chantier 2) : on ne dévoile que les salles
        // découvertes et ce qu'on atteint depuis elles par des portes OUVERTES.
        $salles = (array) ($carte->grille['salles'] ?? []);
        $decouvertes = array_values(array_unique(array_merge(
            [0], // la salle de départ est toujours visible (semée par DemarreurQuete)
            array_map('intval', (array) Cache::get(ResolveurTour::cleSallesDecouvertes((int) $quete->id), [])),
        )));
        $cases = $this->appliquerBrouillard($cases, $salles, $decouvertes, $portes);

        // Ne pas trahir par-dessus le brouillard une porte totalement masquée :
        // on ne garde que celles dont AU MOINS une des deux cases reste visible
        // (sol non brouillé) — on voit alors la porte au bord de l'exploré.
        $portes = array_values(array_filter($portes, function (array $p) use ($cases) {
            [$a, $b] = Grille::casesPorte($p);

            return (($cases[$a['y']][$a['x']] ?? 'b') !== 'b') || (($cases[$b['y']][$b['x']] ?? 'b') !== 'b');
        }));

        return [
            'largeur' => (int) $carte->largeur,
            'hauteur' => (int) $carte->hauteur,
            'cases' => $cases,
            'pieges' => $this->pieges($carte),
            'portes' => $portes,
        ];
    }

    /**
     * Brouillard de guerre : masque ('b') tout ce qui n'est pas encore vu — les
     * salles NON découvertes ET les couloirs derrière une porte fermée. Est
     * visible : les salles découvertes plus ce qu'on atteint depuis elles en
     * traversant des portes OUVERTES (une porte fermée bloque la vue au-delà) ;
     * l'intérieur d'une salle non découverte reste masqué même si sa porte est
     * ouverte (il faut y ENTRER pour la révéler — cf. decouvrirSalle). Purement
     * cosmétique : le moteur travaille toujours sur la carte réelle.
     *
     * @param  list<list<string>>  $cases
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     * @param  list<int>  $decouvertes
     * @param  list<array{x: int, y: int, etat: string}>  $portes
     * @return list<list<string>>
     */
    private function appliquerBrouillard(array $cases, array $salles, array $decouvertes, array $portes): array
    {
        if ($salles === []) {
            return $cases; // pas de métadonnées de salle (vieille carte) : rien à masquer
        }

        $hauteur = count($cases);
        $largeur = $hauteur > 0 ? count($cases[0]) : 0;

        // État des portes indexé par ARÊTE canonique : une porte NON ouverte
        // coupe la vue sur sa cloison (on voit la porte, pas ce qu'il y a
        // derrière) ; ouverte, le regard passe.
        $porteArete = [];
        foreach ($portes as $porte) {
            [$a, $b] = Grille::casesPorte($porte);
            $porteArete[Grille::cleArete($a['x'], $a['y'], $b['x'], $b['y'])] = (string) ($porte['etat'] ?? 'ouverte');
        }
        $porteFermeeEntre = fn (int $x1, int $y1, int $x2, int $y2): bool => (($porteArete[Grille::cleArete($x1, $y1, $x2, $y2)] ?? 'ouverte') !== 'ouverte');

        $salleDe = function (int $x, int $y) use ($salles): ?int {
            foreach ($salles as $i => $s) {
                if ($x >= $s['x'] && $x < $s['x'] + $s['largeur'] && $y >= $s['y'] && $y < $s['y'] + $s['hauteur']) {
                    return (int) $i;
                }
            }

            return null;
        };

        // Flood-fill des cases visibles depuis les salles découvertes, franchissant
        // les ARÊTES ouvertes (une porte fermée arrête la propagation).
        $visible = [];
        $file = [];

        foreach ($decouvertes as $i) {
            $s = $salles[$i] ?? null;
            if ($s === null) {
                continue;
            }
            for ($y = (int) $s['y']; $y < $s['y'] + $s['hauteur']; $y++) {
                for ($x = (int) $s['x']; $x < $s['x'] + $s['largeur']; $x++) {
                    if (($cases[$y][$x] ?? 'm') === 'm') {
                        continue; // mur intérieur : traité via le bord des cases visibles
                    }
                    if (! isset($visible["{$x},{$y}"])) {
                        $visible["{$x},{$y}"] = true;
                        $file[] = [$x, $y];
                    }
                }
            }
        }

        while ($file !== []) {
            [$x, $y] = array_pop($file);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $largeur || $ny >= $hauteur) {
                    continue;
                }
                if (($cases[$ny][$nx] ?? 'm') === 'm' || isset($visible["{$nx},{$ny}"])) {
                    continue;
                }
                // Porte fermée sur l'arête franchie → on ne voit pas au-delà.
                if ($porteFermeeEntre($x, $y, $nx, $ny)) {
                    continue;
                }
                // L'intérieur d'une salle NON découverte reste masqué (il faut y ENTRER).
                $r = $salleDe($nx, $ny);
                if ($r !== null && ! in_array($r, $decouvertes, true)) {
                    continue;
                }
                $visible["{$nx},{$ny}"] = true;
                $file[] = [$nx, $ny];
            }
        }

        // Passe finale : tout ce qui n'est pas visible passe en brouillard, SAUF
        // un mur qui borde une case visible (silhouette des salles/couloirs vus).
        for ($y = 0; $y < $hauteur; $y++) {
            for ($x = 0; $x < $largeur; $x++) {
                if (isset($visible["{$x},{$y}"])) {
                    continue;
                }
                if (($cases[$y][$x] ?? 'm') === 'm') {
                    $borde = false;
                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [1, -1], [-1, 1], [-1, -1]] as [$dx, $dy]) {
                        if (isset($visible[($x + $dx).','.($y + $dy)])) {
                            $borde = true;
                            break;
                        }
                    }
                    if (! $borde) {
                        $cases[$y][$x] = 'b';
                    }

                    continue;
                }
                $cases[$y][$x] = 'b';
            }
        }

        return $cases;
    }

    /**
     * Portes CONNUES de la carte (doc 14 §3.1/3.3) : une porte secrète NON
     * révélée est masquée (même règle que les pièges cachés). Le type de verrou
     * d'une porte verrouillée est exposé (icône cadenas côté table).
     *
     * @return list<array{x: int, y: int, etat: string, verrou?: string}>
     */
    private function portes(Carte $carte): array
    {
        return collect($carte->grille['portes'] ?? [])
            ->filter(fn (array $p) => ($p['etat'] ?? 'ouverte') !== MoteurPortes::ETAT_SECRETE || ($p['revele'] ?? false))
            ->map(function (array $p) {
                $porte = [
                    'x' => (int) $p['x'],
                    'y' => (int) $p['y'],
                    'cote' => (string) ($p['cote'] ?? 'e'), // arête EST ('e') ou SUD ('s')
                    'etat' => (string) ($p['etat'] ?? 'ouverte'),
                ];
                if (isset($p['verrou']['type'])) {
                    $porte['verrou'] = (string) $p['verrou']['type'];
                }

                return $porte;
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{x: int, y: int, etat: string, nom: string}>
     */
    private function pieges(Carte $carte): array
    {
        $connus = collect($carte->grille['pieges'] ?? [])
            ->filter(fn (array $entree) => in_array($entree['etat'] ?? null, [
                MoteurPieges::ETAT_DETECTE, MoteurPieges::ETAT_DESARME, MoteurPieges::ETAT_DECLENCHE,
            ], true));

        $noms = Piege::query()
            ->whereIn('id', $connus->pluck('piege_id')->filter()->unique())
            ->pluck('nom', 'id');

        $biblio = app(BibliothequeImages::class);

        return $connus
            ->map(fn (array $entree) => [
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                'etat' => (string) $entree['etat'],
                'nom' => $noms[$entree['piege_id']] ?? 'Piège',
                'image_url' => $biblio->urlPiege($entree['piege_id'] ?? null, $noms[$entree['piege_id']] ?? 'Piège'),
            ])
            ->values()
            ->all();
    }

    /**
     * Alliés recrutés actifs (3.5) : rendus comme entités `allie` sur la carte.
     *
     * @return list<array<string, mixed>>
     */
    private function allies(Groupe $groupe): array
    {
        return $groupe->mercenaires()
            ->where('etat', 'actif')
            ->whereNotNull('position_x')
            ->with('mercenaire')
            ->orderBy('id')
            ->get()
            ->map(fn (\App\Models\GroupeMercenaire $a) => [
                'type' => 'allie',
                'id' => $a->id,
                'nom' => $a->mercenaire->nom,
                'x' => $a->position_x,
                'y' => $a->position_y,
                'pv_body' => (int) $a->pv_body,
                'pv_body_max' => (int) $a->mercenaire->pv_body,
                'animal' => (bool) $a->mercenaire->animal,
            ])
            ->values()
            ->all();
    }

    /**
     * Alliés recrutés actifs, indépendamment de leur placement sur la carte
     * (contrairement à `allies()`, qui n'expose que ceux posés en quête). Sert
     * au hub : la manette liste les recrues et sait si un animal est déjà pris.
     *
     * @return list<array<string, mixed>>
     */
    private function mercenairesRecrutes(Groupe $groupe): array
    {
        return $groupe->mercenaires()
            ->where('etat', 'actif')
            ->with('mercenaire')
            ->orderBy('id')
            ->get()
            ->map(fn (\App\Models\GroupeMercenaire $a) => [
                'id' => $a->id,
                'mercenaire_id' => $a->mercenaire_id,
                'nom' => $a->mercenaire->nom,
                'type' => $a->mercenaire->type,
                'animal' => (bool) $a->mercenaire->animal,
                'pv_body' => (int) $a->pv_body,
                'pv_body_max' => (int) $a->mercenaire->pv_body,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function heros(Groupe $groupe, Quete $quete): array
    {
        $etats = $quete->etatsPersonnages()->get()->keyBy('personnage_id');

        return $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get()
            ->map(function (Personnage $p) use ($etats) {
                $etat = $etats->get($p->id);

                return [
                    'type' => 'heros',
                    'id' => $p->id,
                    'nom' => $p->nom,
                    'classe' => $p->classe,
                    'niveau' => (int) $p->niveau,
                    // Portrait unique du héros si généré, sinon image de classe.
                    'image_url' => app(BibliothequeImages::class)->urlHeros($p->id, $p->classe),
                    'x' => $etat?->position_x,
                    'y' => $etat?->position_y,
                    'pv_body' => (int) $p->pv_body,
                    'pv_body_max' => (int) $p->pv_body_max,
                    'pv_mind' => (int) $p->pv_mind,
                    'pv_mind_max' => (int) $p->pv_mind_max,
                    // Dés d'attaque / défense (équipement + talents inclus) : panneau
                    // de stats au clic sur l'ordre de jeu (table, C3).
                    'des_attaque' => (int) $p->des_attaque,
                    'des_defense' => (int) $p->des_defense,
                    'attribut_body' => (int) $p->attribut_body,
                    'attribut_mind' => (int) $p->attribut_mind,
                    'tombe' => (bool) ($etat?->tombe ?? false),
                    'conditions' => $this->conditionsHeros($p),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monstres(Quete $quete): array
    {
        return $quete->instancesMonstres()
            ->where('etat', 'actif') // un monstre vaincu quitte le plateau (plus sur les cartes manette/table)
            ->where('revele', true) // les monstres dormants (salle non découverte) restent cachés
            ->with('monstre')
            ->orderBy('id')
            ->get()
            ->map(fn (InstanceMonstre $i) => [
                'type' => 'monstre',
                'id' => $i->id,
                'nom' => $i->habillage['nom'] ?? $i->monstre->nom_base,
                // Portrait de boss dynamique si généré, sinon image du catalogue.
                'image_url' => app(BibliothequeImages::class)->urlMonstre($i->id, $i->monstre_id, $i->monstre->nom_base),
                'x' => $i->position_x,
                'y' => $i->position_y,
                // Emprise (3.9) : grandes figurines multi-cases (1×1 par défaut).
                'emprise' => $i->monstre->emprise(),
                'pv_body' => (int) $i->pv_body,
                // Max PROPRE à l'instance : boss adaptés à la taille du groupe + le
                // +1 PV élite (3.6) déjà intégré (repli catalogue pour lignes legacy).
                'pv_body_max' => $i->pvBodyMax(),
                // Dés effectifs (bonus élite inclus) : panneau de stats table (C3).
                'des_attaque' => $i->attaqueEffective(),
                'des_defense' => $i->defenseEffective(),
                'pv_mind' => (int) $i->pv_mind,
                'etat' => $i->etat,
                'elite' => (bool) $i->elite,
                'conditions' => $this->conditionsMonstre($i),
            ])
            ->values()
            ->all();
    }

    /**
     * Conditions actives d'un héros (pivot personnage_conditions).
     *
     * @return list<array{nom: string, duree: int}>
     */
    private function conditionsHeros(Personnage $personnage): array
    {
        return $personnage->conditions()
            ->get()
            ->map(fn (\App\Models\Condition $c) => [
                'nom' => $c->nom,
                'duree' => (int) $c->pivot->duree,
            ])
            ->values()
            ->all();
    }

    /**
     * Conditions actives d'un monstre (habillage.conditions JSON).
     *
     * @return list<array{nom: string, duree: int}>
     */
    private function conditionsMonstre(InstanceMonstre $instance): array
    {
        $conditions = [];

        foreach ((array) data_get($instance->habillage, 'conditions', []) as $cle => $valeur) {
            if ($valeur) {
                $conditions[] = ['nom' => (string) $cle, 'duree' => 0];
            }
        }

        return $conditions;
    }

    /**
     * Ordre du tour figé (C1) : héros par ordre d'initiative, monstres après.
     *
     * @return list<array{entite: string, id: int, nom: string, a_joue: bool, tombe: bool}>
     */
    private function initiative(Groupe $groupe, Quete $quete): array
    {
        $etats = $quete->etatsPersonnages()->get()->keyBy('personnage_id');

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get()
            ->map(fn (Personnage $p) => [
                'entite' => 'heros',
                'id' => $p->id,
                'nom' => $p->nom,
                'a_joue' => (bool) ($etats->get($p->id)?->a_joue ?? false),
                // Un héros tombé est sauté par le moteur (verifierInitiative) :
                // le client en a besoin pour désigner le VRAI acteur courant.
                'tombe' => (bool) ($etats->get($p->id)?->tombe ?? false),
            ]);

        $monstres = $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true) // les monstres dormants ne figurent pas dans l'initiative
            ->with('monstre')
            ->orderBy('id')
            ->get()
            ->map(fn (InstanceMonstre $i) => [
                'entite' => 'monstre',
                'id' => $i->id,
                'nom' => $i->habillage['nom'] ?? $i->monstre->nom_base,
                'a_joue' => false, // les monstres jouent en bloc après les héros (C2)
                'tombe' => false,
            ]);

        return [...$heros->values()->all(), ...$monstres->values()->all()];
    }

    /**
     * @return array{texte: string, sequence: int}|null
     */
    private function derniereNarration(Groupe $groupe): ?array
    {
        $evenement = Evenement::query()
            ->where('groupe_id', $groupe->id)
            ->where('type', 'narration')
            ->orderByDesc('sequence')
            ->first(['payload', 'sequence']);

        if ($evenement === null) {
            return null;
        }

        $payload = $evenement->payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        if (! isset($payload['texte'])) {
            return null;
        }

        return ['texte' => $payload['texte'], 'sequence' => (int) $evenement->sequence];
    }
}
