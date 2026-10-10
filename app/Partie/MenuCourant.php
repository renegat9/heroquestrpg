<?php

declare(strict_types=1);

namespace App\Partie;

use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Personnage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LE MENU QUE CE JOUEUR PEUT JOUER MAINTENANT — point de passage unique de
 * `GET /menu`, `POST /choix` et `POST /deplacement/apercu`.
 *
 * POURQUOI UN POINT UNIQUE. Après chaque action, `ExecutionChoix` consomme le
 * menu (`Cache::forget`) et confie la régénération à un JOB sur la file
 * `temps-reel`. Entre les deux il n'y a plus de menu en cache pendant une à deux
 * secondes (le worker dort entre deux passages, puis le calcul prend le reste).
 * Un choix LÉGAL envoyé dans cette fenêtre — « fouiller » ou « terminer le tour »
 * juste après un déplacement, le héros ayant encore son créneau d'action — était
 * refusé à tort : « Aucun menu en attente » (Morcar, 2026-10-09). La lecture de
 * `/menu` savait déjà régénérer sur place ; le choix ne le faisait pas, et il
 * n'avait donc pas la même vérité que la liste affichée.
 *
 * LA RÈGLE. Si c'est le tour du héros (ou de l'allié qu'il contrôle) et que son
 * menu manque, on le calcule ICI, maintenant, depuis l'état déjà committé
 * (`GenererMenu::dispatchSync()`) ; le choix est alors validé contre CE menu-là.
 * Le menu régénéré est la liste courante de l'état présent : une option légale
 * passe, une option qui ne l'est plus est refusée.
 *
 * ⚠ JAMAIS un menu périmé servi. Un menu en cache n'est rendu que s'il désigne
 * l'acteur qui a la main ce tour-ci (même héros, et même allié quand c'est le
 * tour de l'allié) ; sinon il est jeté. La liste du menu reste la liste blanche :
 * on n'accepte jamais une option qui ne figure pas dans le menu de l'instant.
 */
final class MenuCourant
{
    public function __construct(private readonly OrdreDuTour $ordreDuTour) {}

    /**
     * Le menu jouable par ce joueur à l'instant, ou `null`.
     *
     * Les leçons de terrain que cette règle porte (ne pas les perdre en la déplaçant) :
     *  - L'ORDRE D'INITIATIVE, pas seulement « n'a pas encore joué » (2026-08-13, trois
     *    joueurs indépendamment) : un rattrapage qui servait un menu complet à un héros
     *    dont ce n'était pas le tour repartait en 422 « Ce n'est pas le tour de ce héros ».
     *  - Le menu DÉJÀ EN CACHE est soumis à la même garde (2026-08-14) : un héros tombé ne
     *    consomme rien, son menu « Attaquer » restait cliquable trois tours.
     *  - L'ALLIÉ joué par son joueur (chantier 3a, 2026-10-04) : `OrdreDuTour::acteurActif()`
     *    dit QUI a la main, héros OU l'allié qu'il contrôle — même `personnage_id`, d'où le
     *    contrôle de `allie_id` en plus.
     *  - Un menu qui ne se joue pas ne doit JAMAIS être servi : le menu proposé est une
     *    liste blanche, et la liste blanche d'un autre instant n'est pas la vérité.
     *
     * @return array{menu: array<string, mixed>, personnage_id: int, allie_id?: int|null}|null
     */
    public function pour(Groupe $groupe, int $joueurId): ?array
    {
        $cle = GenererMenu::cleMenu($groupe->id, $joueurId);

        // Hors quête (hub) : pas d'ordre de tour, le menu en cache fait foi.
        if ($groupe->phase !== 'quete' || $groupe->quete_courante_id === null) {
            $cache = Cache::get($cle);

            return is_array($cache) ? $cache : null;
        }

        $hero = $this->herosDuJoueur($groupe, $joueurId);
        $acteur = $hero === null ? null : $this->ordreDuTour->acteurActif($groupe);

        // Pas son tour : aucun menu ne se joue, et celui qui traîne est périmé.
        if ($hero === null || $acteur === null || (int) $acteur['personnage_id'] !== (int) $hero->id) {
            Cache::forget($cle);

            return null;
        }

        $cache = Cache::get($cle);

        if (is_array($cache) && ! $this->designeLActeur($cache, $acteur)) {
            Cache::forget($cle);
            $cache = null;
        }

        if (! is_array($cache)) {
            $this->regenerer($groupe, $joueurId, (int) $hero->id);
            $cache = Cache::get($cle);
        }

        return is_array($cache) ? $cache : null;
    }

    /**
     * Le joueur a-t-il la main ce tour-ci (son héros, ou l'allié qu'il contrôle) ?
     * Sert à choisir le bon message quand aucun menu ne peut être rendu.
     */
    public function aLaMain(Groupe $groupe, int $joueurId): bool
    {
        if ($groupe->phase !== 'quete' || $groupe->quete_courante_id === null) {
            return false;
        }

        $hero = $this->herosDuJoueur($groupe, $joueurId);
        $acteur = $hero === null ? null : $this->ordreDuTour->acteurActif($groupe);

        return $acteur !== null && (int) $acteur['personnage_id'] === (int) $hero->id;
    }

    /** Le héros ACTIF de CE joueur dans ce groupe (un seul par joueur et groupe). */
    private function herosDuJoueur(Groupe $groupe, int $joueurId): ?Personnage
    {
        return $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $joueurId)
            ->first();
    }

    /**
     * Le menu en cache désigne-t-il l'acteur qui a la main ? Même héros, et même
     * allié (`allie_id`) quand c'est le tour de l'allié — un menu de héros ne sert
     * jamais au tour de son allié, et réciproquement.
     *
     * @param  array<string, mixed>  $cache
     * @param  array{type: string, personnage_id: int, allie_id?: int|null}  $acteur
     */
    private function designeLActeur(array $cache, array $acteur): bool
    {
        $allieAttendu = $acteur['type'] === 'allie' ? (int) $acteur['allie_id'] : null;
        $allieCache = isset($cache['allie_id']) ? (int) $cache['allie_id'] : null;

        return (int) ($cache['personnage_id'] ?? 0) === (int) $acteur['personnage_id']
            && $allieCache === $allieAttendu;
    }

    /**
     * Régénère le menu SUR PLACE. Un échec n'est pas une panne du choix : le job
     * a son `failed()` qui publie le menu de secours (« Terminer le tour »), et
     * on rend ce qu'il laisse — la partie ne se fige pas sur une exception.
     */
    private function regenerer(Groupe $groupe, int $joueurId, int $personnageId): void
    {
        try {
            GenererMenu::dispatchSync($groupe->id, $joueurId, $personnageId);
        } catch (Throwable $e) {
            Log::error('Régénération du menu à la demande échouée.', [
                'groupe_id' => $groupe->id,
                'joueur_id' => $joueurId,
                'personnage_id' => $personnageId,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
