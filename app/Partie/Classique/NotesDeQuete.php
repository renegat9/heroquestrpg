<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\MotsClesQuete;
use App\Events\NarrationDiffusee;
use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Bourses;
use App\Partie\CaseLibre;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurDegats;
use App\Partie\MoteurPieges;
use App\Partie\MoteurPortes;
use App\Partie\RangementObjet;
use App\Partie\ResolveurTour;
use App\Partie\TamponAnnonces;
use App\Support\Journal;
use LogicException;

/**
 * LES NOTES D'UNE QUÊTE CONSTRUITE — le point de passage UNIQUE de « quand → alors »
 * (`docs/plan-mode-classique.md` §4, lot K3 ; vocabulaire fermé : `App\Engine\MotsClesQuete`).
 *
 * Les coutures du moteur ne lisent jamais une note : elles SIGNALENT ce qui vient de se produire
 * ({@see signaler()}) — une salle fouillée, une case foulée, une porte ouverte, une figure tombée. Ce lecteur, et lui
 * seul, retrouve les notes concernées, vérifie leurs conditions, les marque jouées, applique chaque effet par le
 * moteur qui le résout déjà (`Bourses`, `RangementObjet`, `MoteurDegats`…) et l'ANNONCE : un effet automatique que rien
 * n'annonce est injouable.
 *
 * Tout ce qu'il lit est FIGÉ sur la quête au départ (`quetes.document_construit`), tout ce qu'il écrit est une colonne
 * (`quetes.notes_jouees`, `quetes.drapeaux`). Rien en cache. Une quête générée n'a pas de notes : sans effet.
 *
 * Décisions écrites :
 * - **une fois** par défaut (`une_fois: true`) : la note marquée jouée ne tire plus. Elle est marquée AVANT ses
 *   effets — un effet qui re-signale (une apparition tuée sur-le-champ) ne la fait pas boucler.
 * - **la fouille** : une salle dont une note tire sur CETTE fouille rend ce que la note dit, et ne pioche pas de carte
 *   de trésor (LR p. 14 : la carte se tire « sans trésor spécial prévu par le livret »). Une fois le trésor spécial
 *   pris, la salle n'en a plus : les fouilles suivantes piochent comme partout. Le livret ne tranche pas ce second
 *   cas ; l'auteur qui veut que chaque héros trouve le sien écrit `une_fois: false`.
 * - **le héros qui déclenche** reçoit l'or et l'objet, subit les dégâts. Une figure qui tombe sans qu'un héros ne
 *   l'ait achevée (un piège) n'a pas de déclencheur : l'or est alors partagé (`Bourses::verser()` sans bénéficiaire),
 *   l'objet va au premier héros dans l'ordre du tour, les dégâts ne touchent personne.
 * - **une apparition** se pose sur la case écrite ; si elle est prise, sur la plus proche (`CaseLibre`).
 */
final class NotesDeQuete
{
    public function __construct(private readonly CatalogueConstructeur $catalogue) {}

    /**
     * Quelque chose vient de se produire : joue les notes que cela déclenche, dans l'ordre où l'auteur les a écrites.
     *
     * @param  string  $declencheur  une clé de `MotsClesQuete::DECLENCHEURS`
     * @param  array<string, mixed>  $contexte  ce qui s'est produit, dans les termes des cibles : `salle` (index de
     *                                          salle du moteur), `case` (`{x, y}`), `porte` (index), `monstre` (index)
     * @return list<array<string, mixed>> les notes jouées, telles qu'annoncées
     */
    public function signaler(Quete $quete, string $declencheur, array $contexte, ?Personnage $heros = null): array
    {
        if (! isset(MotsClesQuete::DECLENCHEURS[$declencheur])) {
            throw new LogicException("Déclencheur de note inconnu : « {$declencheur} » (voir MotsClesQuete::DECLENCHEURS).");
        }

        if (! $quete->estConstruite()) {
            return [];
        }

        $jouees = [];

        foreach ((array) ($quete->documentConstruit()['notes'] ?? []) as $index => $note) {
            if (data_get($note, 'quand.declencheur') !== $declencheur
                || ! $this->cibleAtteinte($quete, (array) data_get($note, 'quand.cible', []), $contexte)) {
                continue;
            }

            $uneFois = (bool) ($note['une_fois'] ?? true);

            if ($uneFois && in_array($index, (array) $quete->notes_jouees, true)) {
                continue;
            }

            if (! $this->conditionRemplie($quete, (array) $note, $heros)) {
                continue;
            }

            if ($uneFois) {
                $quete->update(['notes_jouees' => [...(array) $quete->notes_jouees, $index]]);
            }

            $jouees[] = $this->jouer($quete, (array) $note, $declencheur, $heros);
        }

        return $jouees;
    }

    /**
     * Une note tirerait-elle sur cet événement ? Lecture seule — pour décider AVANT d'agir (la fouille choisit entre la
     * note et la carte de trésor) sans rien marquer.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function attend(Quete $quete, string $declencheur, array $contexte, ?Personnage $heros = null): bool
    {
        if (! $quete->estConstruite()) {
            return false;
        }

        foreach ((array) ($quete->documentConstruit()['notes'] ?? []) as $index => $note) {
            if (data_get($note, 'quand.declencheur') !== $declencheur
                || ! $this->cibleAtteinte($quete, (array) data_get($note, 'quand.cible', []), $contexte)) {
                continue;
            }

            if (($note['une_fois'] ?? true) && in_array($index, (array) $quete->notes_jouees, true)) {
                continue;
            }

            if ($this->conditionRemplie($quete, (array) $note, $heros)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La condition d'une note — UN seul endroit, lu par `signaler()` et `attend()` : `si_drapeau` (le drapeau est posé) et,
     * depuis le lot K6, `sauf_drapeau` (il ne l'est PAS — la fouille du trésor d'un autel dont le glyphe n'a pas été
     * détruit). `Quete::aDrapeau()` les lit, quête puis campagne.
     */
    private function conditionRemplie(Quete $quete, array $note, ?Personnage $heros = null): bool
    {
        $si = data_get($note, 'quand.si_drapeau');
        $sauf = data_get($note, 'quand.sauf_drapeau');
        // DÉGUISEMENT (lot K6, *Dread Moon*) : `si_deguise` — le héros qui déclenche est (true) ou n'est pas (false)
        // déguisé : « the Thief steals 25 gold unless that hero is disguised », le bloc qui tombe sous un héros à découvert.
        $deguise = data_get($note, 'quand.si_deguise');
        $estDeguise = $heros !== null && (bool) ($quete->etatsPersonnages()->where('personnage_id', $heros->id)->value('deguise') ?? false);

        return ($si === null || $quete->aDrapeau((string) $si))
            && ($sauf === null || ! $quete->aDrapeau((string) $sauf))
            && ($deguise === null || ($heros !== null && $estDeguise === (bool) $deguise));
    }

    /** Le rang d'une figure parmi les monstres POSÉS par l'auteur (`quete.monstres`), ou `null` si elle n'en est pas. */
    public function rangDuMonstre(Quete $quete, InstanceMonstre $instance): ?int
    {
        if (! $quete->estConstruite()) {
            return null;
        }

        // Les figures de l'auteur sont créées les premières, dans l'ordre du document, en une transaction
        // (`DemarreurQueteConstruite`) : les N plus petits identifiants de la quête. Un errant, une apparition, une
        // invocation naissent après.
        $poses = count((array) ($quete->documentConstruit()['monstres'] ?? []));
        $rang = $quete->instancesMonstres()->orderBy('id')->limit($poses)->pluck('id')->search($instance->id);

        return $rang === false ? null : (int) $rang;
    }

    /**
     * La cible écrite par l'auteur est-elle celle de l'événement ? Une cible n'a qu'UNE clé (la validation l'impose).
     *
     * @param  array<string, mixed>  $cible
     * @param  array<string, mixed>  $contexte
     */
    private function cibleAtteinte(Quete $quete, array $cible, array $contexte): bool
    {
        if (array_key_exists('salle', $cible)) {
            $index = $contexte['salle'] ?? null;

            // L'`id` de l'auteur est porté par la salle de la carte (`CarteConstruite`) : le moteur, lui, compte en index.
            return $index !== null
                && data_get($quete->carte?->grille, "salles.{$index}.id") === $cible['salle'];
        }

        if (array_key_exists('case', $cible)) {
            return isset($contexte['case'])
                && (int) data_get($cible, 'case.x') === (int) $contexte['case']['x']
                && (int) data_get($cible, 'case.y') === (int) $contexte['case']['y'];
        }

        // D5 (lot K5a) : le N-ième tour du MJ, ou n'importe lequel après lui — `une_fois` dit s'il tire une fois ou à chaque.
        if (array_key_exists('tour', $cible)) {
            return isset($contexte['tour']) && (int) $contexte['tour'] >= (int) $cible['tour'];
        }

        foreach (['porte', 'monstre'] as $cle) {
            if (array_key_exists($cle, $cible)) {
                return isset($contexte[$cle]) && (int) $cible[$cle] === (int) $contexte[$cle];
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $note
     * @return array<string, mixed>
     */
    /** Le repère de la note en train de se jouer — lu par `effetOffre()`, qui garde la trace de qui l'a déclarée. */
    private string $repereEnCours = '';

    private function jouer(Quete $quete, array $note, string $declencheur, ?Personnage $heros): array
    {
        $effets = [];
        $this->repereEnCours = (string) ($note['repere'] ?? '');

        foreach ((array) ($note['alors'] ?? []) as $effet) {
            $nom = (string) ($effet['effet'] ?? '');
            $lecteur = MotsClesQuete::EFFETS[$nom]['lecteur']
                ?? throw new LogicException("Effet de note inconnu : « {$nom} » (voir MotsClesQuete::EFFETS).");

            $effets[] = ['effet' => $nom, ...$this->{$lecteur}($quete, (array) $effet, $heros)];
        }

        $payload = [
            'type' => 'note_de_quete',
            'repere' => (string) ($note['repere'] ?? ''),
            'declencheur' => $declencheur,
            'personnage' => $heros?->nom,
            'effets' => $effets,
        ];

        app(TamponAnnonces::class)->annoncer(
            $quete->groupe,
            $payload,
            $heros === null ? null : ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom],
        );

        return $payload;
    }

    // ─── Les effets : un lecteur par mot de `MotsClesQuete::EFFETS` ───────────────────────────────────────────────

    /** E1 — le texte est lu à la table (le bandeau du maître du jeu), et gardé au journal. */
    private function effetTexte(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $texte = trim((string) ($effet['texte'] ?? ''));

        if ($texte !== '') {
            $evenement = Journal::ajouter($quete->groupe, 'narration', ['texte' => $texte]);
            broadcast(new NarrationDiffusee($quete->groupe, $texte, queteId: $quete->id, sequence: $evenement->sequence));
        }

        return ['texte' => $texte];
    }

    /** E2 — l'or va à la bourse du héros qui déclenche (`Bourses`) ; sans lui, il est partagé. */
    /**
     * Joue une liste d'effets hors d'une note qui se déclenche — ceux d'une OFFRE acceptée (`Reputation::accepter()`) —, par
     * les mêmes lecteurs, annoncés comme une note sous le même repère. Rend le résultat (`note_de_quete`).
     *
     * @param  list<array<string, mixed>>  $effets
     * @return array<string, mixed>
     */
    public function jouerEffets(Quete $quete, array $effets, string $repere, ?Personnage $heros): array
    {
        return $this->jouer($quete, ['repere' => $repere, 'alors' => $effets], 'offre', $heros);
    }

    /** C5 (lot K6) — des jetons de réputation pour le groupe (ou en moins, jamais sous zéro). */
    private function effetReputation(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $n = (int) ($effet['jetons'] ?? 0);

        return ['jetons' => $n, 'total' => app(Reputation::class)->ajuster($quete->groupe, $n, 'note')];
    }

    /** C5 (lot K6) — l'offre devient un choix au menu ; rien ne se paie ni ne se joue avant qu'un héros l'accepte. */
    private function effetOffre(Quete $quete, array $effet, ?Personnage $heros): array
    {
        app(Reputation::class)->declarer($quete, $effet, $this->repereEnCours);

        return ['libelle' => (string) ($effet['libelle'] ?? '')];
    }

    /** Lot K6 — un mercenaire de la campagne devient engageable (`MercenairesCampagne::debloquer()`). */
    private function effetDebloquerMercenaire(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $nom = app(MercenairesCampagne::class)->debloquer($quete->groupe, (string) ($effet['slug'] ?? ''));

        return ['mercenaire' => $nom];
    }

    private function effetOr(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $or = max(0, (int) ($effet['or'] ?? 0));
        $bourses = app(Bourses::class);

        // PARTAGE (lot E1) : « divided among all surviving heroes » — les héros de la quête, vivants (`Bourses::partager()`,
        // la règle de la récompense de fin : le reste aux premiers dans l'ordre du tour). `par_heros` : autant à chacun.
        return match ($effet['partage'] ?? null) {
            'groupe' => ['or' => $or, 'partage' => 'groupe', 'parts' => $bourses->verser($quete->groupe, $or)],
            'par_heros' => ['or' => $or, 'partage' => 'par_heros', 'parts' => array_merge([], ...array_map(
                fn (array $part) => $bourses->verser($quete->groupe, $part['or'], Personnage::find($part['personnage_id'])),
                $bourses->partager($quete->groupe, $or, chacun: true, parmi: $quete->participants()),
            ))],
            default => ['or' => $or, 'parts' => $bourses->verser($quete->groupe, $or, $heros)],
        };
    }

    /** E2 — l'objet du catalogue entre dans le sac du héros qui déclenche, même plein : c'est un butin de quête. */
    private function effetObjet(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $objet = $this->catalogue->index('objets')[(string) ($effet['slug'] ?? '')] ?? null;
        $receveur = $heros ?? $quete->groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->first();

        if (! $objet instanceof Objet || $receveur === null) {
            return ['objet' => null];
        }

        if (! empty($effet['si_absent_du_groupe'])) {
            $dejaPossede = $quete->groupe->personnages()
                ->wherePivot('actif', true)
                ->whereHas('inventaire', fn ($q) => $q->where('objet_id', $objet->id))
                ->exists();
            if ($dejaPossede) {
                return ['objet' => null, 'deja_possede' => true];
            }
        }

        $quantite = max(1, (int) ($effet['quantite'] ?? 1));
        $deborde = ! RangementObjet::peutRanger($receveur, $objet, $quantite);
        RangementObjet::ranger($objet, (int) $receveur->id, $quantite);

        return [
            'objet' => ['id' => $objet->id, 'nom' => $objet->nom],
            'quantite' => $quantite,
            'receveur' => $receveur->nom,
            ...($deborde ? ['sac_deborde' => true] : []),
        ];
    }

    /** E3 — des monstres du catalogue apparaissent, révélés, sur les cases écrites (ou la plus proche si prise). */
    /**
     * E4 — les figures `inactif` désignées s'éveillent (René, 2026-11-20 ; *Witch Lord* q. 7, porte B : « the magical ice
     * shatters and the Forgotten Legion moves to attack on Zargon's turn »). Le réveil des dormants
     * (`MoteurDormants::eveillerParNote()`), annoncé au fil ; elles jouent à la phase suivante du MJ.
     */
    private function effetEveiller(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $poses = count((array) ($quete->documentConstruit()['monstres'] ?? []));
        $instances = $quete->instancesMonstres()->orderBy('id')->limit($poses)->get()->values();
        $figures = collect((array) ($effet['monstres'] ?? []))
            ->map(fn ($i) => $instances->get((int) $i))
            ->filter(fn ($m) => $m !== null && $m->etat === 'actif');
        $fils = app(\App\Partie\MoteurDormants::class)->eveillerParNote($figures->values());
        foreach ($fils as $fil) {
            app(TamponAnnonces::class)->annoncer($quete->groupe, $fil, null);
        }

        return ['eveilles' => array_column($fils, 'monstre')];
    }

    private function effetApparition(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $catalogue = $this->catalogue->index('monstres');
        $apparus = [];
        $poses = (array) ($effet['monstres'] ?? []);
        if (! empty($effet['nombre_d6'])) {
            $nb = app(\App\Engine\Des\LanceurDes::class)->d6();
            $poses = array_slice($poses, 0, $nb);
        }

        foreach ($poses as $pose) {
            $monstre = $catalogue[(string) ($pose['slug'] ?? '')] ?? null;

            if ($monstre === null) {
                continue;
            }

            // La grille est relue à chaque figure : celle qui vient de naître occupe sa case.
            $place = CaseLibre::iciOuProche(FabriqueGrille::pour($quete->fresh()), (int) $pose['x'], (int) $pose['y']);

            if ($place === null) {
                // Aucune case libre alentour : la figure ne paraît pas, et l'annonce ne la nomme pas.
                $apparus[] = ['sans_place' => true];

                continue;
            }

            $pvBody = isset($pose['pv_body']) ? (int) $pose['pv_body'] : (int) $monstre->pv_body;
            $pvMind = isset($pose['pv_mind']) ? (int) $pose['pv_mind'] : (int) $monstre->pv_mind;
            $instance = $quete->instancesMonstres()->create([
                'monstre_id' => $monstre->id,
                'pv_body' => $pvBody,
                'pv_body_max' => $pvBody,
                'pv_mind' => $pvMind,
                'position_x' => $place['x'],
                'position_y' => $place['y'],
                'etat' => 'actif',
                'elite' => false,
                'revele' => true, // posée sous les yeux des héros
                'habillage' => InstanceMonstre::habillageDeQuete((array) $pose, $this->catalogue),
            ]);

            // Le nom AFFICHÉ de la figure (`InstanceMonstre::nomAffiche()`), jamais le nom de catalogue.
            $apparus[] = ['nom' => $instance->nomAffiche(), 'instance_id' => $instance->id, 'x' => $place['x'], 'y' => $place['y']];
        }

        return ['monstres' => $apparus];
    }

    /** E7 — des points de Corps perdus par le héros qui déclenche (ou tous ceux de sa salle si `toute_la_salle`), par `MoteurDegats` (réductions comprises). */
    private function effetDegats(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $body = max(0, (int) ($effet['body'] ?? 0));

        if ($heros === null || $body === 0) {
            return ['degats' => 0];
        }

        // Lot K5n (*Quest for the Spirit Blade*, q. 13) : `sur_jet` (et `avec_casque` si le héros porte un Casque)
        // lance 1d6 rouge ; en dessous du seuil, le héros esquive l'éboulement sans perdre de point de Corps.
        $jet = null;
        if (isset($effet['sur_jet'])) {
            $seuil = (int) $effet['sur_jet'];
            if (isset($effet['avec_casque'])
                && $heros->inventaire()->where('equipe', true)->whereHas('objet', fn ($q) => $q->where('nom', 'Casque'))->exists()) {
                $seuil = (int) $effet['avec_casque'];
            }
            $jet = app(\App\Engine\Des\LanceurDes::class)->d6();
            if ($jet < $seuil) {
                return ['degats' => 0, 'nom' => $heros->nom, 'jet' => $jet];
            }
        }

        // Lot K5o (*Belorn's Mine*, q. 8 note A ; *Grin's Crag*, q. 10 note A) : `toute_la_salle: true` blesse tous les
        // héros debout présents dans la même salle que le héros qui déclenche.
        if (! empty($effet['toute_la_salle'])) {
            $cibles = $this->herosDansMemeSalle($quete, $heros);
            $total = 0;
            $touches = [];
            foreach ($cibles as $cible) {
                $s = (int) app(MoteurDegats::class)->infligerAHeros($cible, $body, MoteurDegats::SOURCE_PIEGE, ['note_de_quete' => true]);
                $total += $s;
                $touches[] = ['nom' => $cible->nom, 'degats' => $s];
            }

            return ['degats' => $total, 'nom' => $heros->nom, 'cibles' => $touches, ...($jet !== null ? ['jet' => $jet] : [])];
        }

        $subis = app(MoteurDegats::class)->infligerAHeros($heros, $body, MoteurDegats::SOURCE_PIEGE, ['note_de_quete' => true]);

        return ['degats' => (int) $subis, 'nom' => $heros->nom, ...($jet !== null ? ['jet' => $jet] : [])];
    }

    /**
     * Tous les héros debout dans la même salle que `$heros` (ou `[$heros]` s'il est en couloir).
     *
     * @return list<Personnage>
     */
    private function herosDansMemeSalle(Quete $quete, Personnage $heros): array
    {
        $etatHeros = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->first();
        if ($etatHeros === null || $etatHeros->horsPlateau()) {
            return [$heros];
        }
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $salleIdx = \App\Partie\Salles::indexDe($salles, (int) $etatHeros->position_x, (int) $etatHeros->position_y);
        if ($salleIdx === null) {
            return [$heros];
        }

        $cibles = [];
        foreach ($quete->etatsPersonnages()->where('tombe', false)->with('personnage')->orderBy('id')->get() as $etat) {
            if ($etat->personnage !== null && ! $etat->horsPlateau()
                && \App\Partie\Salles::indexDe($salles, (int) $etat->position_x, (int) $etat->position_y) === $salleIdx) {
                $cibles[] = $etat->personnage;
            }
        }

        return $cibles !== [] ? $cibles : [$heros];
    }

    /** E10 — le drapeau est posé sur la quête (`quetes.drapeaux`) ; il ne s'annonce pas, il se LIT. */
    private function effetDrapeau(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $nom = (string) ($effet['nom'] ?? '');
        // Lot K5h : `campagne: true` le pose sur le GROUPE, d'une quête à l'autre ; sinon sur la quête.
        if (($effet['campagne'] ?? false) === true) {
            $quete->poserDrapeauDeCampagne($nom);

            return ['nom' => $nom, 'campagne' => true];
        }
        $quete->poserDrapeau($nom);

        return ['nom' => $nom];
    }

    /**
     * E6 (lot K5a) — la porte `porte` de `quete.portes` s'ouvre, quel que soit son verrou, et les salles qu'elle borde se
     * révèlent : `ResolveurTour::ouvrirPorteParNote()`, la couture des ouvertures sans héros. Déjà ouverte : rien.
     * Elle ne SIGNALE pas `ouverture_porte` : une note n'en déclenche pas une autre.
     */
    private function effetOuvrirPorte(Quete $quete, array $effet, ?Personnage $heros): array
    {
        // LA PORTE DE SORTIE verrouillée (René, 2026-11-20) : `Classique\VerrouDeSortie`, son seul point de passage.
        if (($effet['porte'] ?? null) === 'sortie') {
            return ['porte' => 'sortie', 'ouverte' => app(VerrouDeSortie::class)->deverrouiller($quete)];
        }
        $index = (int) ($effet['porte'] ?? -1);

        return ['porte' => $index, 'ouverte' => app(ResolveurTour::class)->ouvrirPorteParNote($quete, $index)];
    }

    /**
     * E6 (lot K5b) — un bloc tombe sur la case écrite, s'il est LIBRE (ni figure, ni meuble, ni bloc, ni embrasure de
     * porte close : `Grille::estTraversable()`). Décision écrite : sur une case prise, rien ne tombe — un bloc posé
     * ailleurs que là où l'auteur l'a dessiné pourrait fermer un autre chemin — et l'annonce le dit.
     */
    private function effetBloc(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $x = (int) ($effet['x'] ?? -1);
        $y = (int) ($effet['y'] ?? -1);
        $carte = $quete->carte;
        $libre = $carte !== null && FabriqueGrille::pour($quete->fresh())->estTraversable($x, $y);

        if ($libre) {
            app(MoteurPieges::class)->poserBloc($carte, $x, $y);
        }

        return ['x' => $x, 'y' => $y, 'tombe' => $libre];
    }

    /** E5 (lot K5b) — la porte secrète `porte` de `quete.portes` est révélée à tous ; déjà connue : rien. */
    private function effetRevelerPorte(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $index = (int) ($effet['porte'] ?? -1);
        $carte = $quete->carte;

        return ['porte' => $index, 'revelee' => $carte !== null && app(MoteurPortes::class)->revelerParNote($carte, $index)];
    }

    /**
     * E8 (lot K5f) — le héros qui déclenche est emporté sur la case écrite, ou la plus proche libre (`CaseLibre`, comme
     * une apparition) ; la salle d'arrivée se révèle (`ResolveurTour::revelerSalleEn()`). Sans héros dans le donjon, ou
     * sans case libre alentour : rien.
     */
    private function effetTeleporter(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $etat = $heros === null ? null : $quete->etatsPersonnages()->where('personnage_id', $heros->id)->first();
        if ($etat === null || $etat->horsPlateau()) {
            return ['teleporte' => false, 'nom' => $heros?->nom];
        }

        $cibleX = (int) ($effet['x'] ?? -1);
        $cibleY = (int) ($effet['y'] ?? -1);
        $jet = null;

        // Lot K5o (*Grin's Crag* q. 10 note A ; *The Cold Halls* q. 2 note A) : `destinations_d6` lance 1d6 rouge pour
        // choisir par quelle porte / case le héros ressort de la salle tournante ou du Nuage de la Terreur.
        if (is_array($effet['destinations_d6'] ?? null)) {
            $jet = app(\App\Engine\Des\LanceurDes::class)->d6();
            $dest = $effet['destinations_d6'][(string) $jet] ?? null;
            if (is_array($dest) && isset($dest['x'], $dest['y'])) {
                $cibleX = (int) $dest['x'];
                $cibleY = (int) $dest['y'];
            }
        }

        $place = CaseLibre::iciOuProche(
            FabriqueGrille::pour($quete->fresh(), exceptPersonnageId: (int) $heros->id),
            $cibleX,
            $cibleY,
        );
        if ($place === null) {
            return ['teleporte' => false, 'nom' => $heros->nom, ...($jet !== null ? ['jet' => $jet] : [])];
        }

        $etat->update(['position_x' => $place['x'], 'position_y' => $place['y']]);
        app(ResolveurTour::class)->revelerSalleEn($quete, $place['x'], $place['y'], $heros);

        return ['teleporte' => true, 'nom' => $heros->nom, 'x' => $place['x'], 'y' => $place['y'], ...($jet !== null ? ['jet' => $jet] : [])];
    }

    /**
     * E9 (lot K5o, *The Cold Halls* q. 2 note D) — détruit l'objet `slug` dans l'inventaire du héros qui déclenche (ou de
     * tous les héros présents dans sa salle si `toute_la_salle: true`), et recalcule ses statistiques de combat.
     */
    private function effetDetruireObjet(Quete $quete, array $effet, ?Personnage $heros): array
    {
        $objet = $this->catalogue->index('objets')[(string) ($effet['slug'] ?? '')] ?? null;
        if (! $objet instanceof Objet || $heros === null) {
            return ['detruits' => []];
        }

        $cibles = ! empty($effet['toute_la_salle']) ? $this->herosDansMemeSalle($quete, $heros) : [$heros];
        $detruits = [];

        foreach ($cibles as $cible) {
            $supprimes = $cible->inventaire()->where('objet_id', $objet->id)->delete();
            if ($supprimes > 0) {
                app(\App\Partie\Equipement::class)->recalculerCombat($cible->fresh());
                $detruits[] = ['personnage' => $cible->nom, 'objet' => $objet->nom];
            }
        }

        return ['objet' => $objet->nom, 'detruits' => $detruits];
    }
}
