<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\RecitsQuete;
use App\Models\Carte;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Quete;
use App\Partie\Narration\BibliothequeNarration;
use App\Events\NarrationDiffusee;
use App\Support\Journal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job IA : PRÉ-GÉNÈRE tout le texte narratif de la quête, une fois pour
 * toutes au démarrage (décision de René, 2026-08-18 : « l'IA fabrique la
 * quête, elle ne la joue plus »). Une quête coûtait ~145 appels LLM (une
 * narration à chaque action + un menu par héros à chaque action) ; le pack
 * écrit ici (`quetes.recits`) remplace tout ça — le moteur pioche dedans
 * SANS appel LLM en cours de partie (`BibliothequeNarration::pourQuete()` /
 * `::salle()`, `Quete::recitSalle()` / `::recitsTempsFort()`).
 *
 * Chaîné après {@see HabillerMonstres} : les récits de salle doivent citer
 * les monstres avec leur nom HABILLÉ, donc après que l'habillage a été
 * appliqué aux instances (pas avant — sinon on décrit « le Gobelin » dans un
 * donjon où tout le monde ensuite l'appelle « l'Écumeur des cryptes »).
 *
 * Best-effort INTÉGRAL : chaque skill est appelé indépendamment et sa moitié
 * du pack est ÉCRITE AUSSITÔT OBTENUE. ⚠ Assembler les deux moitiés puis
 * écrire une seule fois à la fin ne suffisait pas, et c'est le réel qui l'a
 * montré (2026-08-18) : un `try/catch` protège d'une exception, pas d'un
 * TIMEOUT de worker, qui tue le processus sans rien laisser écrire. Les
 * descriptions de salles, obtenues en ~30 s, étaient jetées parce que la
 * génération des temps forts qui suivait dépassait la minute — deux fois de
 * suite, l'appel étant refacturé à chaque tentative. Ce qui manque
 * retombe sur le repli scripté de `config/narration.php`
 * (`BibliothequeNarration::pourQuete()`/`::repli()` le font déjà tout
 * seuls dès que la clé/salle correspondante est absente du pack) — ce job
 * ne doit JAMAIS bloquer une partie, la quête est jouable dès son
 * démarrage, avant même que ce job n'ait fini.
 */
class GenererRecitsQuete implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * ⚠ Bien au-delà du `--timeout=120` du worker (docker-compose), que ce
     * `$timeout` de job surclasse : les temps forts, ce sont 24 clés × 3
     * variantes en un seul appel, soit 2 à 3 minutes de génération. Doit
     * rester INFÉRIEUR au `retry_after` de la file (config/queue.php), sinon
     * le job est ré-réservé et rejoué pendant qu'il tourne encore.
     */
    public int $timeout = 600;

    public function __construct(
        public readonly int $groupeId,
        public readonly int $queteId,
    ) {}

    public function handle(RecitsQuete $skill, ContexteAssembleur $assembleur, BibliothequeNarration $narration): void
    {
        $groupe = Groupe::find($this->groupeId);
        $quete = Quete::find($this->queteId);

        if ($groupe === null || $quete === null) {
            return;
        }

        $quete->update(['recits' => $this->genererRecits($skill, $assembleur, $groupe, $quete)]);

        $this->ouvrirLaQuete($groupe, $quete->fresh(), $narration);

        // Pré-génération de la vraie voix de narrateur pour les descriptions
        // de salle (les seules FIXES, sans variable — cf. RecitsQuete).
        // Chaîné plutôt que fait ici : protège le quota Gemini TTS (100/j,
        // CLAUDE.md) d'un blocage de CE job derrière une synthèse audio, et
        // laisse GenererVoixQuete gérer sa propre logique de reprise/arrêt.
        GenererVoixQuete::dispatch($quete->id);
    }

    /**
     * Diffuse l'OUVERTURE de la quête, une fois le pack écrit.
     *
     * ⚠ Elle ne pouvait pas être diffusée plus tôt, et c'est tout le problème
     * qu'on corrige ici : `DemarreurQuete` joue `quete_demarree` au démarrage,
     * alors que ce job ne rend que des dizaines de secondes plus tard. La
     * colonne `recits` était donc TOUJOURS vide à cet instant, et le temps fort
     * retombait systématiquement sur la variante générique. Constaté en
     * campagne réelle (2026-08-20) : sur deux quêtes, le texte d'ouverture
     * écrit par l'IA — « Sous les Montagnes de Gorrim, un tombeau ancestral
     * longtemps scellé vient de s'ouvrir… » — n'a jamais été lu, et la
     * description de la salle de départ non plus (la salle 0 étant semée comme
     * déjà découverte, `revelerSalle()` sort aussitôt sans rien dire).
     *
     * Le groupe recevait ainsi, au seul moment censé planter le donjon, un
     * texte générique et rien d'autre.
     *
     * La cérémonie scriptée de `DemarreurQuete` reste inchangée et joue
     * toujours immédiatement : c'est le lever de rideau. Ceci est la mise en
     * place, qui arrive quand elle est prête.
     *
     * Silencieux si le pack est vide (échec du skill) : la cérémonie générique
     * a déjà parlé, la répéter ne dirait rien de neuf.
     */
    private function ouvrirLaQuete(Groupe $groupe, Quete $quete, BibliothequeNarration $narration): void
    {
        if (($quete->recits['salles'] ?? []) === [] && ($quete->recits['temps_forts'] ?? []) === []) {
            return;
        }

        foreach ([
            $quete->recitsTempsFort('quete_demarree') === []
                ? null
                : $narration->pourQuete($quete, 'quete_demarree'),
            $narration->salle($quete, 0),
        ] as $recit) {
            if ($recit === null) {
                continue;
            }

            $evenement = Journal::ajouter($groupe, 'narration', [
                'texte' => $recit['texte'],
                'ambiance' => $recit['ambiance'],
            ]);

            broadcast(new NarrationDiffusee(
                $groupe,
                $recit['texte'],
                ambiance: $recit['ambiance'],
                queteId: $evenement->quete_id,
                url: $recit['url'],
                sequence: $evenement->sequence,
            ));
        }
    }

    /**
     * Le pack complet de la quête, en UN SEUL appel : une description par
     * salle, plus les trois temps forts qui lui appartiennent.
     *
     * Deux appels séparés jusqu'au 2026-08-18 — le second réécrivait les 24
     * temps forts, dont les cinq issues de fouille. Or une fouille est un
     * TIRAGE : l'IA ne peut pas savoir laquelle sortira, et écrire sur mesure
     * ce qu'une table fixe dit aussi bien coûtait ~5 500 tokens de sortie et
     * deux à trois minutes par quête (décision de René). Ces clés-là viennent
     * désormais de `config/narration.php`, vers laquelle
     * `BibliothequeNarration::pourQuete()` retombe déjà d'elle-même.
     *
     * @return array{salles: array<string, mixed>, temps_forts: array<string, mixed>}
     */
    private function genererRecits(
        RecitsQuete $skill,
        ContexteAssembleur $assembleur,
        Groupe $groupe,
        Quete $quete,
    ): array {
        $vide = ['salles' => [], 'temps_forts' => []];
        $carte = $quete->carte;

        if ($carte === null) {
            return $vide; // pas de carte assemblée (ne devrait pas arriver post-DemarreurQuete).
        }

        $salles = (array) data_get($carte->grille, 'salles', []);

        if ($salles === []) {
            return $vide;
        }

        try {
            $contexte = $assembleur->assembler($groupe, extra: [
                'salles_a_decrire' => $this->sallesADecrire($quete, $carte, $salles),
            ]);
            $sortie = $skill->generer($contexte);
        } catch (Throwable $e) {
            Log::warning('Récits de quête impossibles — repli intégral sur la narration scriptée.', [
                'quete_id' => $quete->id,
                'erreur' => $e->getMessage(),
            ]);

            return $vide;
        }

        return [
            'salles' => $this->packSalles($sortie),
            'temps_forts' => $this->packTempsForts($sortie),
        ];
    }

    /**
     * @param  array<string, mixed>  $sortie
     * @return array<string, array{texte: string, entree: string, ambiance: string}>
     */
    private function packSalles(array $sortie): array
    {
        $pack = [];

        foreach ($sortie['salles'] ?? [] as $entree) {
            $texte = trim((string) ($entree['texte'] ?? ''));
            $id = (int) ($entree['salle'] ?? -1);

            if ($id < 0 || $texte === '') {
                continue;
            }

            // Clé STRING : `Quete::recitSalle()` lit `recits.salles.{$salle}`
            // via data_get — on l'écrit en chaîne pour que la forme stockée
            // corresponde EXACTEMENT au contrat documenté (`{"0": {...}}`).
            $pack[(string) $id] = [
                'texte' => $texte,
                // Phrase nommant l'arrivant : servie UNIQUEMENT là où la voix
                // enregistrée du narrateur n'existe pas (cf.
                // `BibliothequeNarration::salle()`).
                'entree' => trim((string) ($entree['entree'] ?? '')),
                'ambiance' => (string) ($entree['ambiance'] ?? 'mystere'),
            ];
        }

        return $pack;
    }

    /**
     * @param  array<string, mixed>  $sortie
     * @return array<string, array{ambiance: string, variantes: list<string>}>
     */
    private function packTempsForts(array $sortie): array
    {
        $pack = [];

        foreach ($sortie['temps_forts'] ?? [] as $entree) {
            $cle = (string) ($entree['cle'] ?? '');
            $variantes = array_values(array_filter(
                (array) ($entree['variantes'] ?? []),
                fn ($v) => is_string($v) && trim($v) !== '',
            ));

            if ($cle === '' || $variantes === []) {
                continue;
            }

            $pack[$cle] = [
                'ambiance' => (string) ($entree['ambiance'] ?? 'tension'),
                'variantes' => $variantes,
            ];
        }

        return $pack;
    }

    /**
     * Contexte par salle envoyé au skill : id, thème (tuile), profondeur
     * dans l'arbre de couloirs, mobilier présent (noms de catalogue),
     * monstres présents (noms HABILLÉS, comptés), présence d'un coffre.
     *
     * @param  list<array<string, mixed>>  $salles  `carte.grille.salles`
     * @return list<array<string, mixed>>
     */
    private function sallesADecrire(Quete $quete, Carte $carte, array $salles): array
    {
        $profondeurs = $this->profondeurs($carte, count($salles));
        $mobilierParSalle = $this->mobilierParSalle($carte);
        $monstresParSalle = $this->monstresParSalle($quete, $salles);

        $sortie = [];
        foreach ($salles as $id => $s) {
            $sortie[] = [
                'salle' => (int) $id,
                'theme' => (string) ($s['theme'] ?? 'generique'),
                'profondeur' => $profondeurs[(int) $id] ?? 0,
                'coffre' => $quete->estSalleCoffre((int) $id),
                'mobilier' => $mobilierParSalle[(int) $id] ?? [],
                'monstres' => $monstresParSalle[(int) $id] ?? [],
            ];
        }

        return $sortie;
    }

    /**
     * Distance (en nombre de portes) de chaque salle à la salle 0, par BFS
     * sur `carte.grille.aretes` (l'arbre de couloirs assemblé par
     * `AssembleurCarte` — + les boucles ajoutées par `liaisonsSupplementaires`,
     * traitées ici comme des arêtes normales : la profondeur qui compte pour
     * le récit est la distance la plus courte réellement praticable, pas
     * seulement celle de l'arbre de génération).
     *
     * @return array<int, int>
     */
    private function profondeurs(Carte $carte, int $nbSalles): array
    {
        $aretes = (array) data_get($carte->grille, 'aretes', []);
        $adjacence = array_fill(0, max($nbSalles, 1), []);

        foreach ($aretes as $arete) {
            $a = (int) ($arete['a'] ?? -1);
            $b = (int) ($arete['b'] ?? -1);

            if ($a < 0 || $b < 0 || $a >= $nbSalles || $b >= $nbSalles) {
                continue;
            }

            $adjacence[$a][] = $b;
            $adjacence[$b][] = $a;
        }

        $profondeurs = [0 => 0];
        $file = [0];

        while ($file !== []) {
            $courante = array_shift($file);

            foreach ($adjacence[$courante] ?? [] as $voisine) {
                if (! isset($profondeurs[$voisine])) {
                    $profondeurs[$voisine] = $profondeurs[$courante] + 1;
                    $file[] = $voisine;
                }
            }
        }

        return $profondeurs;
    }

    /**
     * Noms de catalogue du mobilier posé dans chaque salle
     * (`carte.grille.mobilier`, qui porte déjà l'index de salle).
     *
     * @return array<int, list<string>>
     */
    private function mobilierParSalle(Carte $carte): array
    {
        $entrees = (array) data_get($carte->grille, 'mobilier', []);

        if ($entrees === []) {
            return [];
        }

        $noms = Mobilier::query()->pluck('nom', 'id');

        $parSalle = [];
        foreach ($entrees as $entree) {
            if (! isset($entree['salle'])) {
                continue;
            }

            $nom = $noms->get((int) ($entree['mobilier_id'] ?? 0));

            if ($nom !== null) {
                $parSalle[(int) $entree['salle']][] = $nom;
            }
        }

        return $parSalle;
    }

    /**
     * Monstres actifs de la quête, groupés par salle et par nom HABILLÉ
     * (compté). `InstanceMonstre` ne porte pas de colonne `salle` — sa
     * position (x, y) est projetée sur `carte.grille.salles` avec la même
     * règle de containment que `DemarreurQuete::salleDe()` /
     * `EtatGroupe::appliquerBrouillard()` / `ResolveurTour::salleDeCase()` :
     * les trois sont privées à leur classe, d'où cette 4e implémentation
     * locale plutôt qu'un appel croisé.
     *
     * ⚠ AUCUN filtre `revele` ici, à dessein — même règle que
     * `HabillerMonstres` (qui habille tous les blocs `actif`, dormants
     * compris) : ce contenu est fabriqué UNE FOIS, côté serveur, et n'est
     * exposé au joueur qu'au moment où sa salle est révélée
     * (`BibliothequeNarration::salle()`). Le filtrer ici ne protégerait
     * aucune fuite d'information, et priverait la salle d'une description
     * fidèle à ce qu'elle contient.
     *
     * @param  list<array<string, mixed>>  $salles
     * @return array<int, list<array{nom: string, nombre: int}>>
     */
    private function monstresParSalle(Quete $quete, array $salles): array
    {
        $instances = InstanceMonstre::query()
            ->where('quete_id', $quete->id)
            ->where('etat', 'actif')
            ->with('monstre')
            ->get();

        $comptes = [];
        foreach ($instances as $instance) {
            $id = $this->salleDeCase($salles, (int) $instance->position_x, (int) $instance->position_y);

            if ($id === null) {
                continue; // en couloir : ne devrait pas arriver (spawns posés en salle), robustesse.
            }

            $nom = (string) ($instance->habillage['nom'] ?? $instance->monstre?->nom_base ?? 'Créature');
            $comptes[$id][$nom] = ($comptes[$id][$nom] ?? 0) + 1;
        }

        $sortie = [];
        foreach ($comptes as $id => $parNom) {
            foreach ($parNom as $nom => $nombre) {
                $sortie[$id][] = ['nom' => $nom, 'nombre' => $nombre];
            }
        }

        return $sortie;
    }

    /**
     * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $salles
     */
    private function salleDeCase(array $salles, int $x, int $y): ?int
    {
        foreach ($salles as $i => $s) {
            if ($x >= (int) $s['x'] && $x < (int) $s['x'] + (int) $s['largeur']
                && $y >= (int) $s['y'] && $y < (int) $s['y'] + (int) $s['hauteur']) {
                return (int) $i;
            }
        }

        return null;
    }

}
