<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\MenuChoix;
use App\Events\MenuPropose;
use App\Models\Groupe;
use App\Models\Personnage;
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
    ) {}

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
            $menu = $this->fusionner($menuGenerique, $skill->generer($contexte));
        } catch (Throwable $e) {
            Log::warning('Menu IA indisponible — repli sur le menu moteur.', [
                'groupe_id' => $groupe->id,
                'personnage_id' => $personnage->id,
                'erreur' => $e->getMessage(),
            ]);

            $menu = $menuGenerique;
        }

        // Dernier menu proposé : référence de validation de POST choix.
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
     * @return array<string, mixed>
     */
    private function fusionner(array $moteur, array $ia): array
    {
        $optionsIa = $ia['options'] ?? [];
        $fusion = [];
        $idsPris = [];

        // 1) Options mécaniques du moteur (autoritaires), libellé emprunté à l'IA.
        foreach ($moteur['options'] ?? [] as $opt) {
            if (! in_array($opt['type'] ?? null, self::TYPES_MECANIQUES, true)) {
                continue;
            }
            $equivalent = $this->equivalentIa($opt, $optionsIa);
            if ($equivalent !== null && isset($equivalent['libelle'])) {
                $opt['libelle'] = $equivalent['libelle'];
            }
            $fusion[] = $opt;
            $idsPris[$opt['id']] = true;
        }

        // 2) Options de couleur de l'IA (dialogue, action, jet) — jamais
        //    mécaniquement ambiguës : le moteur les résout génériquement.
        foreach ($optionsIa as $opt) {
            if (! in_array($opt['type'] ?? null, ['dialogue', 'action', 'jet'], true)) {
                continue;
            }
            $id = (string) ($opt['id'] ?? '');
            if ($id === '' || isset($idsPris[$id])) {
                $id = 'ia_'.count($fusion);
                $opt['id'] = $id;
            }
            $fusion[] = $opt;
            $idsPris[$id] = true;
            if (count($fusion) >= 7) {
                break;
            }
        }

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
        foreach ($optionsIa as $opt) {
            if (($opt['type'] ?? null) !== ($optMoteur['type'] ?? null)) {
                continue;
            }
            if (($optMoteur['type'] ?? null) === 'attaque'
                && (int) ($opt['cible_id'] ?? 0) !== (int) ($optMoteur['cible_id'] ?? -1)) {
                continue;
            }

            return $opt;
        }

        return null;
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
