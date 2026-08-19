<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\JournalCombatDiffuse;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Http\Controllers\Controller;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\JournalCombat;
use App\Partie\Narration\BibliothequeNarration;
use App\Partie\ResolveurTour;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Réception d'un choix de menu (contrat docs/contrat-api.md ; doc 11 §4) :
 *
 *  1. le téléphone envoie {option_id, parametres?} ;
 *  2. l'API valide l'option contre le DERNIER MENU PROPOSÉ au joueur
 *     (mémorisé en cache par GenererMenu — garde-fou strict, doc 08 §2) :
 *     option absente du menu → 422 ;
 *  3. le MOTEUR résout (ResolveurTour : déplacement, attaque, jet…), met à
 *     jour l'état, journalise et diffuse `.groupe.etat` ;
 *  4. narration SYNCHRONE (bascule 2026-08-18, plus d'appel LLM en cours de
 *     partie : le texte est PIOCHÉ dans le pack pré-généré de la quête, repli
 *     sur les répliques scriptées de config/narration.php) puis dispatch des
 *     menus suivants — moteur seul, lui aussi sans LLM, désormais gratuit ;
 *  5. réponse 202, la suite arrive par Reverb (« le MJ réfléchit… » ne dure
 *     plus que le temps d'une LECTURE, plus celui d'une génération).
 */
class ChoixController extends Controller
{
    /**
     * Est-ce VRAIMENT le tour de ce héros ? Même règle que
     * `ResolveurTour::verifierInitiative()` : le premier de l'ordre figé qui
     * soit encore debout et n'ait pas joué.
     *
     * Dupliquer la lecture plutôt que d'exposer le résolveur est assumé — le
     * contrôleur ne fait que DÉCIDER S'IL PROPOSE, le résolveur reste seul
     * juge de ce qu'il accepte. Les deux doivent simplement dire « non » au
     * même moment.
     */
    private function estSonTour(Groupe $groupe, int $personnageId): bool
    {
        $etats = $groupe->queteCourante?->etatsPersonnages()->get();

        if ($etats === null) {
            return false;
        }

        foreach ($groupe->personnages()->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')->pluck('personnages.id') as $id) {
            $etat = $etats->firstWhere('personnage_id', $id);

            if ($etat === null || $etat->a_joue || $etat->tombe) {
                continue;
            }

            return (int) $id === $personnageId;
        }

        return false;
    }

    /**
     * POST /api/groupes/{identifiant}/choix
     *
     * ResolveurTour est injecté PAR MÉTHODE (pas au constructeur) : Laravel
     * met en cache l'instance du contrôleur sur la route entre les requêtes
     * d'un même process, et le lanceur de dés doit être résolu à CHAQUE
     * requête (les tests le re-bindent via desFiges()).
     */
    public function choisir(Request $request, string $identifiant, ResolveurTour $resolveur, JournalCombat $journalCombat): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'option_id' => ['required', 'string', 'max:64'],
            'parametres' => ['nullable', 'array'],
            'parametres.x' => ['sometimes', 'integer', 'min:0'],
            'parametres.y' => ['sometimes', 'integer', 'min:0'],
            'parametres.cible_id' => ['sometimes', 'integer', 'min:1'],
            // Sorts (doc 02) : type de la cible si un monstre et un héros
            // partagent le même id, et sort à récupérer (Concentration).
            'parametres.cible_type' => ['sometimes', Rule::in(['monstre', 'heros'])],
            'parametres.sort_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Le moteur fait autorité : seule une option du dernier menu proposé
        // à CE joueur est légale.
        $cleMenu = GenererMenu::cleMenu($groupe->id, (int) $joueur->id);
        $dernierMenu = Cache::get($cleMenu);

        if (! is_array($dernierMenu)) {
            throw ValidationException::withMessages([
                'option_id' => 'Aucun menu en attente pour ce joueur — attendez la proposition du MJ.',
            ]);
        }

        $option = collect($dernierMenu['menu']['options'] ?? [])
            ->first(fn ($o) => ($o['id'] ?? null) === $donnees['option_id']);

        if ($option === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Option illégale : elle ne figure pas dans le dernier menu proposé.',
            ]);
        }

        $personnage = $this->personnageLegal($groupe, (int) $joueur->id, (int) $dernierMenu['personnage_id']);
        $acteur = ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom];

        // Le choix lui-même entre au journal (source de vérité rejouable).
        Journal::ajouter($groupe, 'choix', [
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'type' => $option['type'] ?? null,
        ], $acteur);

        // Résolution déterministe par le moteur (jamais par l'IA).
        if ($groupe->phase === 'quete') {
            $resultat = $resolveur->resoudre($groupe, $personnage, $option, $donnees['parametres'] ?? []);

            // Journal de combat MÉCANIQUE diffusé à TOUTES les manettes (canal de
            // groupe) : sans ça, en « combat instantané » (pas de narration IA),
            // un joueur ne voit que ses PV bouger — les attaques subies, le tour
            // des monstres, le résultat d'une fouille restaient invisibles.
            $lignes = $journalCombat->depuisResultat($resultat, $personnage->nom);
            if ($lignes !== []) {
                broadcast(new JournalCombatDiffuse(
                    $groupe,
                    $lignes,
                    (int) Evenement::query()->where('groupe_id', $groupe->id)->max('sequence'),
                ));
            }
        } else {
            $resultat = [
                'type' => $option['type'] ?? 'action',
                'option_id' => $option['id'],
                'libelle' => $option['libelle'] ?? null,
            ];
        }

        // Un menu = un choix : il est consommé, un nouveau sera proposé.
        Cache::forget($cleMenu);

        // L'IA n'intervient que sur les actions NOTABLES. Un simple déplacement
        // (ou attente), sans changement de phase, reste 100 % moteur → tour
        // instantané : pas de narration, menus moteur seuls (pas d'appel LLM).
        $groupeFrais = $groupe->fresh();
        $triviale = in_array($resultat['type'] ?? null, ['deplacement', 'attente'], true)
            && $groupeFrais->phase === 'quete';

        // Combat (monstres révélés actifs) → tour instantané lui aussi : menu
        // moteur immédiat + barks pré-générés (texte/audio déjà faits) en guise
        // de retour, SANS attendre le LLM. L'IA reste réservée à l'exploration.
        $quete = $groupeFrais->phase === 'quete' ? $groupeFrais->queteCourante : null;
        $enCombat = $quete !== null && $quete->instancesMonstres()
            ->where('etat', 'actif')->where('revele', true)->exists();

        $instantane = $triviale || $enCombat;

        if (! $instantane) {
            // Verrou B1 (délibéré, cf. CLAUDE.md) : le joueur suivant attend que
            // le narrateur ait « parlé » — la TABLE l'éteint une fois la lecture
            // finie (POST /table/lecture-terminee). Depuis la bascule du
            // 2026-08-18 ce n'est plus une génération LLM qui tourne derrière,
            // seulement une pioche dans le pack pré-généré ou le repli scripté :
            // le verrou ne dure donc plus que le temps d'une lecture.
            broadcast(new MjReflechit($groupe, true));
            $this->narrer($groupe, $quete, $resultat, $personnage);
        }

        foreach ($groupe->personnages()->wherePivot('actif', true)->get() as $heros) {
            // Un menu ne coûte plus d'appel LLM (§2, moteur seul) : la boucle
            // reste volontairement sur TOUS les héros actifs (et pas seulement
            // celui dont c'est le tour) — c'est ce qui alimente le rattrapage
            // `GET /menu` de chacun, et restreindre pour un gain nul sur un
            // calcul devenu gratuit serait prendre un risque de régression pour
            // rien.
            GenererMenu::dispatch($groupe->id, (int) $heros->joueur_id, (int) $heros->id);
        }

        // 202 : le moteur a résolu, l'état et la narration arrivent par Reverb.
        // Le résultat moteur est renvoyé en echo (affichage immédiat des dés).
        return response()->json(['resultat' => $resultat], 202);
    }

    /**
     * GET /api/groupes/{identifiant}/menu — RATTRAPAGE du menu courant du joueur
     * (à la reconnexion : la manette s'abonne aux futurs `.menu.propose` mais a
     * raté celui déjà émis). Renvoie le menu en cache ; s'il est absent alors
     * que c'est le tour du héros (quête en cours, debout, n'a pas joué), le
     * régénère INSTANTANÉMENT (menu moteur, sans LLM) et le renvoie.
     */
    public function menu(Request $request, string $identifiant): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $cle = GenererMenu::cleMenu($groupe->id, (int) $joueur->id);
        $cache = Cache::get($cle);

        if ($groupe->phase === 'quete' && $groupe->quete_courante_id !== null) {
            $hero = $groupe->personnages()
                ->wherePivot('actif', true)
                ->where('joueur_id', $joueur->id)
                ->first();

            $etat = $hero === null ? null : EtatPersonnageQuete::query()
                ->where('quete_id', $groupe->quete_courante_id)
                ->where('personnage_id', $hero->id)
                ->first();

            $peutAgir = $etat !== null && ! $etat->a_joue && ! $etat->tombe
                && $this->estSonTour($groupe, $hero->id);

            // ⚠ La garde vaut aussi pour le menu DÉJÀ EN CACHE, et c'est ce que
            // la première version ratait : un menu mis en cache avant que le
            // héros ne tombe restait servi tant qu'il n'était pas CONSOMMÉ — et
            // un héros à terre ne consomme rien. Une joueuse est ainsi restée
            // trois tours avec un menu « Attaquer » pleinement cliquable, qui
            // répondait « Ce héros est tombé » à chaque fois (partie du
            // 2026-08-14). Périmé, le menu s'efface.
            if (! $peutAgir) {
                Cache::forget($cle);

                return response()->json(['menu' => null]);
            }

            // ⚠ L'ORDRE D'INITIATIVE, pas seulement « n'a pas encore joué ».
            // Sans cette garde (constatée en partie réelle le 2026-08-13 par
            // TROIS joueurs indépendamment), ce rattrapage servait un menu
            // complet et cliquable à un héros dont ce n'était pas le tour :
            // chaque action repartait en 422 « Ce n'est pas le tour de ce
            // héros ». La manette appelle ce point d'entrée au montage et à
            // chaque reconnexion — un joueur qui rechargeait son téléphone
            // pendant le tour d'un autre héritait donc d'un menu mort.
            //
            // C'est l'anti-patron que le projet traque partout ailleurs : le
            // menu ne doit jamais proposer ce que le résolveur refusera.
            if (! is_array($cache)) {
                GenererMenu::dispatchSync($groupe->id, (int) $joueur->id, (int) $hero->id);
                $cache = Cache::get($cle);
            }
        }

        return is_array($cache)
            ? response()->json(['menu' => $cache['menu'], 'personnage_id' => $cache['personnage_id']])
            : response()->json(['menu' => null]);
    }

    /**
     * Résolution SYNCHRONE de la narration d'un temps fort — remplace
     * `GenererNarration::dispatch()` depuis la bascule du 2026-08-18 (« l'IA
     * fabrique la quête, elle ne la joue plus ») : plus aucun appel LLM en
     * cours de partie, le texte est PIOCHÉ dans le pack pré-généré de la
     * quête (`BibliothequeNarration::pourQuete()`), avec repli sur les
     * répliques scriptées de config/narration.php. Reprend telle quelle la
     * diffusion (journal + `NarrationDiffusee`) que construisait l'ancien job.
     *
     * ⚠ Filet du verrou B1 : si AUCUN texte n'est trouvé (pack de quête et
     * repli scripté absents tous les deux pour cette clé), on dégèle
     * immédiatement « MJ réfléchit » — c'est exactement ce que faisait le
     * `finally` de `GenererNarration::handle()` sur échec ; le job a disparu
     * mais rien d'autre ne surveille plus le verrou, alors le filet doit
     * rester ICI.
     */
    private function narrer(Groupe $groupe, ?Quete $quete, array $resultat, Personnage $personnage): void
    {
        $cle = $this->cleTempsFort($resultat);
        $recit = app(BibliothequeNarration::class)
            ->pourQuete($quete, $cle, $this->remplacementsNarration($personnage, $resultat));

        if ($recit === null) {
            broadcast(new MjReflechit($groupe, false));

            return;
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

    /**
     * Mappe un résultat moteur vers la clé de temps fort narratif — portage
     * DIRECT de l'ancien `App\Agent\Skills\Narration::cleRepli()` : c'était le
     * repli pour quand le LLM était indisponible, c'est désormais la SEULE
     * route (plus de skill, plus de job IA), donc la seule autorité qui reste
     * sur cette correspondance.
     *
     * @param  array<string, mixed>  $resultat
     */
    private function cleTempsFort(array $resultat): string
    {
        return match ($resultat['type'] ?? null) {
            'quete_demarree' => 'quete_demarree',
            'salle_decouverte' => 'salle_decouverte',
            'piege_declenche' => 'piege_declenche',
            // Fouille — chaque ISSUE a son temps fort. Elles retombaient
            // toutes sur « progression » (« une salle de plus »), héritage du
            // temps où l'ancien repli n'avait que ce mot-là : trouver 25 pièces
            // d'or, réveiller un errant ou ne rien trouver du tout se
            // racontaient à l'identique. Constaté en partie réelle le
            // 2026-08-18 — les dix clés que la pré-génération produit
            // (`App\Partie\Narration\TempsFort`) n'étaient LUES par
            // personne, donc payées et jamais entendues.
            //
            // Vocabulaire d'issue commun au deck et au mobilier (CLAUDE.md) ;
            // une issue inconnue reste sur « progression » plutôt que
            // d'affirmer à tort qu'on n'a rien trouvé.
            'fouille_tresor' => match ($resultat['issue'] ?? null) {
                'tresor' => 'fouille_tresor',
                'potion' => 'fouille_potion',
                'artefact' => 'fouille_artefact',
                'errant' => 'fouille_errant',
                'piege' => 'fouille_piege',
                'rien' => 'fouille_rien',
                default => 'progression',
            },
            'fouille_mobilier' => match ($resultat['issue'] ?? null) {
                // Un meuble qui paie en or raconte la même chose qu'un coffre :
                // pas de `mobilier_tresor` à inventer pour ça.
                'tresor' => 'fouille_tresor',
                'objet' => 'mobilier_objet',
                'artefact' => 'fouille_artefact',
                'piege' => 'mobilier_piege',
                'rien' => 'mobilier_rien',
                default => 'progression',
            },
            'ouvrir_porte' => 'porte_ouverte',
            'actionner_levier' => 'levier_actionne',
            'reprise' => 'reprise',
            'deplacement' => 'deplacement',
            // Inatteignable en pratique via ce contrôleur (une attaque cible
            // toujours un monstre actif+révélé, donc $enCombat est vrai et
            // narrer() n'est jamais appelé) — conservé pour fidélité au
            // portage et au cas où un appelant futur réutilise cleTempsFort().
            'attaque' => ($resultat['degats'] ?? 0) > 0
                ? (($resultat['cible_vaincue'] ?? false) ? 'attaque_mort' : 'attaque_touche')
                : 'attaque_pare',
            default => ($resultat['quete']['etat'] ?? null) === 'terminee'
                ? 'victoire_quete'
                : match ($resultat['issue'] ?? null) {
                    'reussite' => 'reussite',
                    'reussite_mixte' => 'reussite_mixte',
                    'echec' => 'echec',
                    default => 'progression',
                },
        };
    }

    /**
     * Placeholders `{heros}`/`{monstre}`/`{objet}`/`{or}` déduits du résultat
     * moteur, au mieux — un placeholder sans valeur trouvée reste tel quel
     * (BibliothequeNarration::substituer), donc on n'inclut une clé QUE
     * quand le résultat la connaît vraiment plutôt que de la vider.
     *
     * @param  array<string, mixed>  $resultat
     * @return array<string, string|int>
     */
    private function remplacementsNarration(Personnage $personnage, array $resultat): array
    {
        $remplacements = ['heros' => $personnage->nom];

        $monstre = $resultat['cible']['nom'] ?? $resultat['monstre']['nom'] ?? null;
        if (is_string($monstre) && $monstre !== '') {
            $remplacements['monstre'] = $monstre;
        }

        $objet = $resultat['objet']['nom'] ?? null;
        if (is_string($objet) && $objet !== '') {
            $remplacements['objet'] = $objet;
        }

        if ((int) ($resultat['or'] ?? 0) > 0) {
            $remplacements['or'] = (int) $resultat['or'];
        }

        return $remplacements;
    }

    /**
     * Le personnage appartient-il au joueur ET est-il actif dans ce groupe ?
     */
    private function personnageLegal(Groupe $groupe, int $joueurId, int $personnageId): Personnage
    {
        $personnage = $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('personnages.id', $personnageId)
            ->where('joueur_id', $joueurId)
            ->first();

        if ($personnage === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Ce personnage n\'est pas un héros actif de ce groupe contrôlé par vous.',
            ]);
        }

        return $personnage;
    }
}
