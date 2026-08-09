<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\MenuChoix;
use App\Events\MenuPropose;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Sort;
use App\Partie\MenuMoteur;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job IA : menu de choix contextuels d'un héros (doc 06 §1, étape 2).
 *
 * Le MOTEUR construit d'abord un menu générique exécutable depuis l'état
 * exact (MenuMoteur : Se déplacer / Attaquer / Fouiller / Attendre) ; le
 * skill MenuChoix tente de l'enrichir via le LLM. En cas d'échec (pas de
 * clé API, erreur, sortie invalide), le menu moteur sert de repli — l'API
 * ne dépend jamais du LLM, le joueur reçoit toujours un menu.
 *
 * Le menu retenu est MÉMORISÉ (cache, clé groupe+joueur) : c'est contre ce
 * dernier menu proposé que POST choix valide l'option (contrat, doc 08 §2).
 * Puis il est diffusé sur le canal PRIVÉ `joueur.{id}` (doc 11 §7).
 */
class GenererMenu implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Durée de vie du dernier menu proposé (séance de jeu). */
    public const TTL_MENU_MINUTES = 360;

    public function __construct(
        public readonly int $groupeId,
        public readonly int $joueurId,
        public readonly ?int $personnageId = null,
        // false → menu MOTEUR seul (instantané), sans appel LLM : utilisé pour
        // les tours triviaux (déplacement/attente) afin d'accélérer le jeu.
        public readonly bool $enrichir = true,
    ) {
        // File prioritaire « temps-reel » : le menu est ce que le joueur attend
        // pour jouer. Sans ça, il passait DERRIÈRE GenererNarration (TTS ~30 s)
        // et HabillerMonstres dans la file FIFO mono-worker → ~60 s d'attente.
        // Voir docker-compose.yml (worker dédié `queue-jeu`).
        $this->onQueue('temps-reel');
    }

    /** Clé du dernier menu proposé à un joueur dans un groupe. */
    public static function cleMenu(int $groupeId, int $joueurId): string
    {
        return "partie:menu:{$groupeId}:{$joueurId}";
    }

    public function handle(MenuChoix $skill, ContexteAssembleur $assembleur, MenuMoteur $menuMoteur): void
    {
        $groupe = Groupe::findOrFail($this->groupeId);
        $personnage = $this->personnage($groupe);

        // Menu générique du moteur : toujours exécutable, sert de repli.
        $menuGenerique = $menuMoteur->generer($groupe, $personnage);

        // Tour trivial (déplacement/attente) → menu moteur seul, aucun appel LLM.
        if (! $this->enrichir) {
            $this->publier($groupe, $personnage, $menuGenerique);

            return;
        }

        try {
            $contexte = $assembleur->assembler($groupe, extra: [
                'personnage' => [
                    'id' => $personnage->id,
                    'nom' => $personnage->nom,
                    'classe' => $personnage->classe,
                    'niveau' => $personnage->niveau,
                    'attribut_body' => $personnage->attribut_body,
                    'attribut_mind' => $personnage->attribut_mind,
                    'pv_body' => "{$personnage->pv_body}/{$personnage->pv_body_max}",
                    'pv_mind' => "{$personnage->pv_mind}/{$personnage->pv_mind_max}",
                ],
                'menu_moteur' => $menuGenerique,
            ]);

            // Le moteur reste autorité sur les options EXÉCUTABLES (déplacement,
            // attaque d'un monstre adjacent, désamorçage, sorts…) : on fusionne
            // le menu IA pour qu'il ne puisse jamais les omettre (sinon un héros
            // non-adjacent serait sans moyen d'approcher = softlock). L'IA
            // n'apporte que l'habillage des libellés et les options de couleur.
            $menu = $this->fusionner(
                $menuGenerique,
                $skill->generer($contexte),
                $this->creneauActionLibre($groupe, $personnage),
            );
        } catch (Throwable $e) {
            Log::warning('Menu IA indisponible — repli sur le menu moteur.', [
                'groupe_id' => $groupe->id,
                'personnage_id' => $personnage->id,
                'erreur' => $e->getMessage(),
            ]);

            $menu = $menuGenerique;
        }

        $this->publier($groupe, $personnage, $menu);
    }

    /**
     * Dernier filet : ce job est mort pour de bon (toutes tentatives épuisées).
     *
     * Le repli ci-dessus ne couvre QUE l'appel LLM. Tout ce qui le précède —
     * `MenuMoteur::generer()`, la résolution du personnage, la base — s'exécute
     * hors du try, si bien qu'une seule exception moteur ne laissait AUCUN menu :
     * la manette restait sur « Le maître du jeu prépare la suite… » indéfiniment,
     * sans erreur visible ni côté joueur ni côté table, et le groupe entier était
     * gelé — le menu est la seule chose qui rende la main. (Test de jeu du
     * 2026-08-05 : workers de file sur du code périmé après migration, colonne
     * `mobilier.bloquant` disparue, partie figée > 20 min avant diagnostic.)
     *
     * On publie donc un menu de SECOURS minimal : passer son tour reste toujours
     * possible, la partie repart. Volontairement sans dépendance (ni moteur, ni
     * IA, ni carte) : c'est le chemin qu'on emprunte précisément quand l'une
     * d'elles vient de casser.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('GenererMenu a échoué — publication du menu de secours.', [
            'groupe_id' => $this->groupeId,
            'joueur_id' => $this->joueurId,
            'personnage_id' => $this->personnageId,
            'erreur' => $e?->getMessage(),
        ]);

        try {
            $groupe = Groupe::findOrFail($this->groupeId);
            $personnage = $this->personnage($groupe);
        } catch (Throwable $secondaire) {
            Log::error('Menu de secours impossible — groupe ou personnage introuvable.', [
                'groupe_id' => $this->groupeId,
                'erreur' => $secondaire->getMessage(),
            ]);

            return;
        }

        $this->publier($groupe, $personnage, [
            'situation' => 'Le maître du jeu a perdu le fil. Reprenez la main : terminez ce tour, '
                .'la partie continue.',
            'options' => [
                ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'],
            ],
        ]);
    }

    /**
     * Mémorise le menu (référence de validation de POST choix) et le diffuse
     * sur le canal privé du joueur.
     *
     * @param  array<string, mixed>  $menu
     */
    private function publier(Groupe $groupe, Personnage $personnage, array $menu): void
    {
        // Appliqué ici (et non dans fusionner()) pour couvrir les TROIS
        // origines possibles du menu publié : moteur seul (tour trivial),
        // fusion moteur+IA, et repli moteur si l'IA échoue.
        $menu = $this->avecImmuniteMentale($menu);

        Cache::put(self::cleMenu($groupe->id, $this->joueurId), [
            'personnage_id' => $personnage->id,
            'menu' => $menu,
        ], now()->addMinutes(self::TTL_MENU_MINUTES));

        broadcast(new MenuPropose($this->joueurId, $groupe->id, $personnage->id, $menu));

    }

    /**
     * Types d'options dont l'exécution exige un ancrage mécanique précis
     * (id d'instance, coordonnées, sort) : ils viennent TOUJOURS du moteur.
     */
    private const TYPES_MECANIQUES = [
        'deplacement', 'attaque', 'desamorcage', 'franchissement',
        'sort', 'parchemin', 'concentration', 'relever',
        // Exploration (Vague 2) : ancrage précis (coordonnées de porte/levier,
        // table de trésor) → autoritaires, jamais omises par la fusion IA.
        'ouvrir_porte', 'actionner_levier', 'fouille_tresor', 'fouille_mobilier',
        // Équipement en quête (doc 01 §149) : ancré sur une ligne d'inventaire.
        'equiper', 'desequiper',
    ];

    /**
     * Fusionne le menu IA dans le menu moteur (doc 08 §2 : le moteur fait
     * autorité, l'IA habille). Les options mécaniques du moteur sont toujours
     * présentes (ré-habillées par un libellé IA équivalent quand il existe) ;
     * l'IA n'ajoute que des options de couleur (dialogue, action, jet
     * contextuel). Garantit qu'un héros peut toujours agir (déplacement…).
     *
     * @param  array<string, mixed>  $moteur
     * @param  array<string, mixed>  $ia
     * @param  bool  $creneauActionLibre  le créneau ACTION du tour est-il encore
     *                                    disponible (correctif §2.4) ? Si non, les
     *                                    options de couleur de l'IA (dialogue,
     *                                    action, jet — toutes mappées sur le
     *                                    créneau « action » par
     *                                    `ResolveurTour::creneauOption`) seraient
     *                                    systématiquement rejetées par le moteur
     *                                    (422 « Tu as déjà agi ce tour. ») : on ne
     *                                    les propose donc plus du tout, au lieu de
     *                                    les afficher indiscernables d'une option
     *                                    qui marcherait.
     * @return array<string, mixed>
     */
    private function fusionner(array $moteur, array $ia, bool $creneauActionLibre = true): array
    {
        $optionsIa = $ia['options'] ?? [];
        $fusion = [];
        $idsPris = [];

        // Identifiants que le MOTEUR propose ce tour-ci, tous types confondus :
        // seuls ceux-là peuvent être repris tels quels par l'IA.
        $idsMoteur = array_flip(array_column($moteur['options'] ?? [], 'id'));

        // 1) TOUTES les options du moteur (autoritaires), libellé emprunté à l'IA.
        //
        // Le filtre sur TYPES_MECANIQUES laissait tomber les autres — dont
        // `fouiller` (type `jet`), qui ne parvenait donc au joueur QUE si l'IA
        // pensait à le recopier. Une action du moteur ne doit dépendre de rien
        // d'autre que du moteur.
        foreach ($moteur['options'] ?? [] as $opt) {
            $equivalent = $this->equivalentIa($opt, $optionsIa);
            if ($equivalent !== null && isset($equivalent['libelle'])) {
                $opt['libelle'] = $equivalent['libelle'];
            }
            $fusion[] = $opt;
            $idsPris[$opt['id']] = true;
        }

        // 2) L'IA n'INVENTE plus d'action (décision de René).
        //
        // Elle injectait librement ses propres options `dialogue`/`action`/`jet`,
        // sans le moindre contrôle de légalité. Deux conséquences vécues en test :
        // une option pouvait reprendre un identifiant mécanique que le moteur
        // venait de retirer (« Fouiller — trésor » sur une salle déjà fouillée,
        // acceptée puis rejetée au fond du résolveur), et des options purement
        // décoratives (« Analyser les runes… ») échouaient en silence parce que
        // rien derrière ne les résolvait.
        //
        // Le contrat devient : le MOTEUR décide de ce qui est jouable, l'IA
        // habille les libellés (étape 1). Les actions de couleur reviendront
        // ancrées à des ÉLÉMENTS que l'IA pose sur la carte à sa création —
        // objets de décor interactifs, résolus par le moteur comme les leviers
        // le sont déjà. Tant que ces éléments n'existent pas, aucune option
        // n'est inventée à la volée.

        // 3) « Attendre » toujours disponible en dernier recours.
        if (! array_filter($fusion, fn ($o) => ($o['type'] ?? null) === 'attente')) {
            $fusion[] = ['id' => 'attendre', 'libelle' => 'Attendre et observer', 'type' => 'attente'];
        }

        return [
            'situation' => $ia['situation'] ?? $moteur['situation'] ?? null,
            'options' => $fusion,
        ];
    }

    /**
     * Cherche dans les options IA celle qui correspond mécaniquement à une
     * option moteur (même type + même cible/sort), pour en emprunter le libellé.
     *
     * @param  array<string, mixed>  $optMoteur
     * @param  list<array<string, mixed>>  $optionsIa
     * @return array<string, mixed>|null
     */
    private function equivalentIa(array $optMoteur, array $optionsIa): ?array
    {
        // L'attaque discriminait sur `cible_id` du temps où le moteur émettait
        // une option par monstre. Elle n'en émet plus qu'une, cibles jointes —
        // sauf « Lancer », qui partage le type `attaque`. Ce libellé-là n'est
        // JAMAIS emprunté : il porte une information mécanique — l'arme est
        // PERDUE — qu'une paraphrase de l'IA ferait disparaître.
        if (($optMoteur['type'] ?? null) === 'attaque' && ($optMoteur['id'] ?? null) !== 'attaquer') {
            return null;
        }

        foreach ($optionsIa as $opt) {
            if (($opt['type'] ?? null) !== ($optMoteur['type'] ?? null)) {
                continue;
            }

            return $opt;
        }

        return null;
    }

    /**
     * Le créneau ACTION du tour (doc 03 §28) est-il encore disponible pour ce
     * héros ? Relit `a_joue`/`a_agi` — les MÊMES colonnes que
     * `MenuMoteur::generer` et `ResolveurTour::creneauOption` — pour que la
     * fusion (§2.4) ne propose jamais une option de couleur IA que le moteur
     * refuserait avec « Tu as déjà agi ce tour. ». Au hub (pas de quête en
     * cours), il n'existe aucune notion de créneau : toujours libre.
     */
    private function creneauActionLibre(Groupe $groupe, Personnage $personnage): bool
    {
        $quete = $groupe->phase === 'quete' ? $groupe->queteCourante : null;

        if ($quete === null) {
            return true;
        }

        $etat = $quete->etatsPersonnages()->where('personnage_id', $personnage->id)->first();

        $aJoue = (bool) ($etat?->a_joue ?? false);
        $aAgi = $aJoue || (bool) ($etat?->a_agi ?? false);

        return ! $aAgi;
    }

    /**
     * Correctif §2.3 bis : signale, PAR CIBLE, qu'un sort MENTAL n'aurait
     * aucun effet (Mind à 0 = immunité totale, même règle que
     * `ResolveurTour::sortMental`) — sans empêcher de le lancer quand même
     * (le joueur reste libre de le faire, au prix du sort consommé pour rien,
     * comme aujourd'hui : on expose juste l'information manquante).
     *
     * Champ ajouté : chaque entrée de `parametres.cibles` d'une option
     * `sort`/`parchemin` dont le sort est de type `mental` reçoit un booléen
     * `immunise` (`true` = Mind 0, le sort n'aura aucun effet sur cette
     * cible). Absent pour les sorts non mentaux — la manette (lot 3) doit
     * rester tolérante à son absence.
     *
     * @param  array<string, mixed>  $menu
     * @return array<string, mixed>
     */
    private function avecImmuniteMentale(array $menu): array
    {
        $menu['options'] = array_map(function (array $option) {
            $parametres = $option['parametres'] ?? null;

            if (! in_array($option['type'] ?? null, ['sort', 'parchemin'], true)
                || ! is_array($parametres)
                || ! isset($parametres['cibles']) || ! is_array($parametres['cibles'])
                || ! isset($parametres['sort_id'])) {
                return $option;
            }

            $sort = Sort::find($parametres['sort_id']);
            if ($sort === null || $sort->type !== 'mental') {
                return $option;
            }

            $option['parametres']['cibles'] = array_map(
                fn (array $cible) => $cible + ['immunise' => $this->mindNul($cible)],
                $parametres['cibles'],
            );

            return $option;
        }, $menu['options'] ?? []);

        return $menu;
    }

    /**
     * Score de Mind nul (immunité aux sorts mentaux) pour une cible de menu
     * `{type: monstre|heros, id}` — monstre : `pv_mind` de l'INSTANCE (peut
     * différer du catalogue) ; héros : `attribut_mind`. Cible introuvable :
     * on ne prétend pas savoir → pas immunisé (n'invente jamais un blocage).
     *
     * @param  array<string, mixed>  $cible
     */
    private function mindNul(array $cible): bool
    {
        return match ($cible['type'] ?? null) {
            'monstre' => (int) (InstanceMonstre::find($cible['id'] ?? null)?->pv_mind ?? 1) === 0,
            'heros' => (int) (Personnage::find($cible['id'] ?? null)?->attribut_mind ?? 1) === 0,
            default => false,
        };
    }

    /**
     * Personnage ciblé, ou premier personnage actif du joueur dans ce groupe.
     */
    private function personnage(Groupe $groupe): Personnage
    {
        if ($this->personnageId !== null) {
            return Personnage::findOrFail($this->personnageId);
        }

        return $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $this->joueurId)
            ->firstOrFail();
    }
}
