<?php

declare(strict_types=1);

namespace App\Jobs;

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
 * Menu de choix contextuels d'un héros (doc 06 §1, étape 2).
 *
 * Bascule 2026-08-18 (« l'IA fabrique la quête, elle ne la joue plus ») : le
 * menu est désormais TOUJOURS celui du moteur (MenuMoteur : Se déplacer /
 * Attaquer / Fouiller / Attendre…), sans aucun appel LLM — l'ancien
 * enrichissement IA (skill MenuChoix, fusion avec le menu moteur) a disparu
 * avec lui. Reste un JOB (et non un appel synchrone) : la file prioritaire
 * `temps-reel` continue de servir à ordonner sa publication devant les autres
 * jobs (habillage des monstres…) et à profiter de `failed()` comme filet
 * générique si la résolution du groupe/personnage explose.
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
    ) {
        // File prioritaire « temps-reel » : le menu est ce que le joueur attend
        // pour jouer. Sans ça, il passait DERRIÈRE l'ancien job GenererNarration
        // (TTS ~30 s, supprimé le 2026-08-18 — la narration est synchrone
        // désormais) et HabillerMonstres dans la file FIFO mono-worker →
        // ~60 s d'attente. Voir docker-compose.yml (worker dédié `queue-jeu`).
        $this->onQueue('temps-reel');
    }

    /** Clé du dernier menu proposé à un joueur dans un groupe. */
    public static function cleMenu(int $groupeId, int $joueurId): string
    {
        return "partie:menu:{$groupeId}:{$joueurId}";
    }

    public function handle(MenuMoteur $menuMoteur): void
    {
        $groupe = Groupe::findOrFail($this->groupeId);
        $personnage = $this->personnage($groupe);

        // Menu 100 % moteur (bascule 2026-08-18) : plus d'enrichissement IA à
        // tenter, plus de repli à prévoir ici — MenuMoteur::generer() EST déjà
        // le menu final.
        $this->publier($groupe, $personnage, $menuMoteur->generer($groupe, $personnage));
    }

    /**
     * Dernier filet : ce job est mort pour de bon (toutes tentatives épuisées).
     *
     * `handle()` n'a plus de try/catch (plus d'appel LLM à protéger depuis la
     * bascule 2026-08-18), mais `MenuMoteur::generer()` et la résolution du
     * personnage/groupe restent capables d'exploser — et une seule exception
     * moteur laissait alors AUCUN menu : la manette restait sur « Le maître du
     * jeu prépare la suite… » indéfiniment, sans erreur visible ni côté joueur
     * ni côté table, et le groupe entier était gelé — le menu est la seule
     * chose qui rende la main. (Test de jeu du 2026-08-05 : workers de file
     * sur du code périmé après migration, colonne `mobilier.bloquant`
     * disparue, partie figée > 20 min avant diagnostic.)
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
        $menu = $this->avecImmuniteMentale($menu);

        Cache::put(self::cleMenu($groupe->id, $this->joueurId), [
            'personnage_id' => $personnage->id,
            'menu' => $menu,
        ], now()->addMinutes(self::TTL_MENU_MINUTES));

        broadcast(new MenuPropose($this->joueurId, $groupe->id, $personnage->id, $menu));
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
