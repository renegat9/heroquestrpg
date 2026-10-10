<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\JournalCombatDiffuse;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Events\SceneTable;
use App\Http\Controllers\Controller;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\ExecutionChoix;
use App\Partie\JournalCombat;
use App\Partie\MenuCourant;
use App\Partie\Narration\BibliothequeNarration;
use App\Partie\OrdreDuTour;
use App\Partie\ResolveurTour;
use App\Partie\SceneDeTable;
use App\Partie\TamponScenes;
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
     * Est-ce VRAIMENT le tour de ce héros ? Le contrôleur ne fait que DÉCIDER
     * S'IL PROPOSE ; le résolveur reste seul juge de ce qu'il accepte — et les
     * deux lisent désormais la même règle, `OrdreDuTour`.
     */
    private function estSonTour(Groupe $groupe, int $personnageId): bool
    {
        return app(OrdreDuTour::class)->estSonTour($groupe, $personnageId);
    }

    /**
     * POST /api/groupes/{identifiant}/choix
     *
     * ResolveurTour est injecté PAR MÉTHODE (pas au constructeur) : Laravel
     * met en cache l'instance du contrôleur sur la route entre les requêtes
     * d'un même process, et le lanceur de dés doit être résolu à CHAQUE
     * requête (les tests le re-bindent via desFiges()).
     */
    public function choisir(
        Request $request,
        string $identifiant,
        ResolveurTour $resolveur,
        JournalCombat $journalCombat,
        SceneDeTable $scenesDeTable,
    ): JsonResponse {
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
            // Sous-choix d'une option à liste (sort, parchemin, objet) : la
            // `cle` de l'entrée retenue. ⚠ Une seule clé pour les trois listes
            // plutôt qu'un paramètre par famille — `sort_id` ne suffirait pas,
            // le mode « ouvre une porte » du Génie produisant plusieurs entrées
            // pour le MÊME sort. Patron des `soins` réactifs (`potion:{id}`).
            'parametres.cle' => ['sometimes', 'string', 'max:64'],
            // ÉQUIPER (sous-choix, 2026-09-18) : la MAIN choisie quand
            // l'entrée retenue porte deux slots utiles (arme à une main).
            // ⚠ Borne large ici (juste les deux valeurs possibles) — la
            // vraie whitelist est `parametres.slots` DE L'ENTRÉE, revalidée
            // par `resoudreEquipement()` : deux pièces du même sac peuvent
            // avoir des slots utiles différents.
            'parametres.emplacement' => ['sometimes', Rule::in(['arme_principale', 'arme_secondaire'])],
            // Jeter une PILE (doc 01 §7) : combien d'exemplaires. ⚠ La borne
            // haute n'est PAS ici — elle dépend de la ligne en base, que
            // `resoudreJeter()` relit et compare. Ce `min:1` n'empêche que
            // l'absurde (0, négatif, texte) ; un champ numérique est la plus
            // facile des whitelists à contourner, et la vraie est côté moteur.
            'parametres.quantite' => ['sometimes', 'integer', 'min:1'],
            // SÉANCE D'ÉCHANGE : plusieurs pièces, dans les deux sens, pour
            // une seule action. ⚠ Sans ces règles, `validate()` ne rendrait
            // PAS la clé — il ne rend que ce qu'on lui a déclaré — et les
            // transferts arriveraient vides au résolveur, qui refuserait une
            // séance pourtant bien remplie. Le plafond borne la charge, pas la
            // légalité : `SeanceEchange` revalide chaque triplet (ligne
            // appartenant à l'un des deux héros, non équipée, destinataire
            // étant l'autre) et juge la capacité sur l'état FINAL.
            'parametres.transferts' => ['sometimes', 'array', 'max:50'],
            'parametres.transferts.*.inventaire_id' => ['required', 'integer', 'min:1'],
            'parametres.transferts.*.vers_personnage_id' => ['required', 'integer', 'min:1'],
            'parametres.transferts.*.quantite' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Le moteur fait autorité : seule une option du menu que CE joueur peut
        // jouer à l'instant est légale. `MenuCourant` le rend — et le recalcule
        // sur place si c'est son tour et que le menu a été consommé par la
        // résolution précédente : sans cela, un choix légal envoyé pendant la
        // régénération (1-2 s) était refusé à tort (Morcar, 2026-10-09).
        $cleMenu = GenererMenu::cleMenu($groupe->id, (int) $joueur->id);
        $dernierMenu = app(MenuCourant::class)->pour($groupe, (int) $joueur->id);

        if ($dernierMenu === null) {
            throw $this->refusSansMenu($groupe, (int) $joueur->id);
        }

        $option = collect($dernierMenu['menu']['options'] ?? [])
            ->first(fn ($o) => ($o['id'] ?? null) === $donnees['option_id']);

        if ($option === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Option illégale : elle ne figure pas dans le dernier menu proposé.',
            ]);
        }

        // ALLIÉ JOUÉ PAR SON JOUEUR (chantier 3a, 2026-10-04) : ce menu n'est
        // pas celui du héros, mais celui de l'allié qu'il contrôle — un
        // SECOND menu sur la MÊME manette, {@see App\Jobs\GenererMenu}. Route
        // vers `ResolveurTour::resoudreTourAllie()` plutôt que vers le grand
        // `match` du héros.
        if (($dernierMenu['allie_id'] ?? null) !== null) {
            return $this->choisirPourAllie(
                $groupe, (int) $dernierMenu['allie_id'], $option, $donnees['parametres'] ?? [],
                $resolveur, $journalCombat, $scenesDeTable, $cleMenu,
            );
        }

        $personnage = $this->personnageLegal($groupe, (int) $joueur->id, (int) $dernierMenu['personnage_id']);
        $acteur = ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom];

        // Le choix lui-même entre au journal (source de vérité rejouable).
        Journal::ajouter($groupe, 'choix', [
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'type' => $option['type'] ?? null,
        ], $acteur);

        // Résolution déterministe par le moteur (jamais par l'IA), journal de
        // combat, scènes, narration, menus suivants : un seul point de passage,
        // `ExecutionChoix` — la réponse à une *Vision du futur* reprend la même
        // séquence (2026-10-08).
        return response()->json(app(ExecutionChoix::class)->executer(
            $groupe, $personnage, $option, $donnees['parametres'] ?? [],
        ), 202);
    }

    /**
     * Le tour d'un ALLIÉ joué par son joueur (chantier 3a, 2026-10-04) —
     * reprend le squelette de {@see self::choisir()} (journal du choix,
     * journal de combat, scènes de table, menus suivants) mais route vers
     * {@see ResolveurTour::resoudreTourAllie()} au lieu du grand `match` du
     * héros. Pas de narration IA : un tour d'allié est purement mécanique
     * (déplacement/attaque), exactement comme un tour de monstre.
     *
     * @param  array<string, mixed>  $option  option DU DERNIER MENU (déjà validée contre le cache)
     * @param  array<string, mixed>  $parametres  paramètres envoyés par le client
     */
    private function choisirPourAllie(
        Groupe $groupe,
        int $allieId,
        array $option,
        array $parametres,
        ResolveurTour $resolveur,
        JournalCombat $journalCombat,
        SceneDeTable $scenesDeTable,
        string $cleMenu,
    ): JsonResponse {
        $allie = GroupeMercenaire::where('groupe_id', $groupe->id)->where('id', $allieId)->first();
        $quete = $groupe->phase === 'quete' ? $groupe->queteCourante : null;

        if ($allie === null || $quete === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Ce tour d\'allié n\'est plus valide — la quête a changé.',
            ]);
        }

        $nomAllie = $allie->mercenaire?->nom ?? 'Allié';
        $acteur = ['type' => 'allie', 'id' => $allie->id, 'nom' => $nomAllie];

        Journal::ajouter($groupe, 'choix', [
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'type' => $option['type'] ?? null,
        ], $acteur);

        $resultat = $resolveur->resoudreTourAllie($groupe, $quete, $allie, $option, $parametres);

        $sequence = (int) Evenement::query()->where('groupe_id', $groupe->id)->max('sequence');

        // Le contrôleur du captif/allié reste le meilleur acteur « nominal »
        // pour une scène — exactement le comportement d'hier (la phase alliée
        // automatique passait déjà le HÉROS dont le tour avait déclenché la
        // phase, jamais l'allié lui-même) : `SceneDeTable` lit `mercenaire_id`/
        // `allie_id` du PAYLOAD pour l'illustrer avec SON image, pas celle du
        // contrôleur — voir `attaqueDuGroupe()`.
        $controleur = $allie->recruteur;

        $lignes = $journalCombat->depuisResultat($resultat, $nomAllie);
        if ($lignes !== []) {
            broadcast(new JournalCombatDiffuse($groupe, $lignes, $sequence));
        }

        if ($controleur !== null) {
            foreach ($scenesDeTable->depuisResultat($resultat, $controleur, $resolveur->figuresEnMarche()) as $scene) {
                broadcast(new SceneTable($groupe, $scene, $sequence));
            }
        }

        app(TamponScenes::class)->vider($resolveur->figuresEnMarche());

        Cache::forget($cleMenu);

        foreach ($groupe->fresh()->personnages()->wherePivot('actif', true)->get() as $heros) {
            GenererMenu::dispatch($groupe->id, (int) $heros->joueur_id, (int) $heros->id);
        }

        return response()->json([
            'resultat' => $resultat,
            'des' => app(ExecutionChoix::class)->desUnilateraux($resultat),
        ], 202);
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

        // Le rattrapage passe par `MenuCourant` — le même point de passage que
        // `POST /choix` : le menu rendu ici est exactement celui que le choix
        // acceptera. Ce point refuse un menu périmé (héros tombé, initiative passée,
        // menu d'un autre acteur) et régénère sur place quand c'est le tour du
        // héros et que son menu manque. Les deux gardes historiques y vivent.
        $menu = app(MenuCourant::class)->pour($groupe, (int) $joueur->id);

        return $menu !== null
            ? response()->json([
                'menu' => $menu['menu'],
                'personnage_id' => $menu['personnage_id'],
                // ALLIÉ JOUÉ PAR SON JOUEUR (chantier 3a) : non-null quand ce
                // rattrapage sert le tour de l'allié contrôlé par ce héros —
                // absent des menus plus anciens déjà en cache, donc le client
                // doit tolérer sa non-présence (contrat).
                'allie_id' => $menu['allie_id'] ?? null,
            ])
            : response()->json(['menu' => null]);
    }

    /**
     * Le refus d'un choix (ou d'un aperçu) faute de menu jouable — DEUX messages,
     * parce que deux situations. C'est le tour du joueur et son menu manque : la
     * régénération a échoué ou le menu n'est pas encore là, le joueur doit
     * simplement réessayer (« le menu se met à jour »). Ce n'est pas son tour :
     * rien ne viendra, il attend la proposition du MJ.
     */
    private function refusSansMenu(Groupe $groupe, int $joueurId): ValidationException
    {
        $message = app(MenuCourant::class)->aLaMain($groupe, $joueurId)
            ? 'Le menu se met à jour — réessaie dans un instant.'
            : 'Aucun menu en attente pour ce joueur — attendez la proposition du MJ.';

        return ValidationException::withMessages(['option_id' => $message]);
    }


    /**
     * Le personnage appartient-il au joueur ET est-il actif dans ce groupe ?
     */
    /**
     * POST /api/groupes/{identifiant}/deplacement/apercu {x, y}
     *
     * APERÇU du trajet, avant de valider (René, 2026-09-17 : « que la figure
     * utilise le vrai chemin »). Le trajet n'est pas décoratif — les pièges
     * sont contrôlés case par case dessus — et le joueur ne désignait jusqu'ici
     * qu'une destination : il découvrait la route à l'animation.
     *
     * ⚠ Contrôleur volontairement SEC : tout le calcul vit dans
     * `ResolveurTour::apercuDeplacement()`, sur la grille que le déplacement
     * parcourra réellement. Le refaire ici (ou pire, en JS) recréerait la
     * dérive de miroir que ce projet paie en boucle.
     *
     * ⚠ Même garde que `choisir()` : l'aperçu passe par le DERNIER MENU proposé
     * à ce joueur. Sans lui, n'importe quel membre sonderait la carte à
     * n'importe quel moment — un aperçu reste une lecture, mais elle n'a de
     * sens que pour le héros dont c'est le tour.
     */
    public function apercuDeplacement(Request $request, string $identifiant, ResolveurTour $resolveur): JsonResponse
    {
        $groupe = Groupe::where('identifiant', $identifiant)->firstOrFail();
        $joueur = Auth::guard('joueur')->user();

        $donnees = $request->validate([
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
        ]);

        // Même point de passage que `choisir()` (`MenuCourant`) : un aperçu fait
        // pendant la régénération du menu ne doit pas échouer là où le choix réussit.
        $dernierMenu = app(MenuCourant::class)->pour($groupe, (int) $joueur->id);

        if ($dernierMenu === null) {
            throw $this->refusSansMenu($groupe, (int) $joueur->id);
        }

        $personnage = $this->personnageLegal($groupe, (int) $joueur->id, (int) $dernierMenu['personnage_id']);
        $quete = $groupe->phase === 'quete' && $groupe->quete_courante_id !== null
            ? Quete::find($groupe->quete_courante_id)
            : null;

        $etat = $quete === null ? null : EtatPersonnageQuete::where('quete_id', $quete->id)
            ->where('personnage_id', $personnage->id)
            ->first();

        if ($quete === null || $etat === null || $etat->position_x === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Aucun déplacement en cours : ce héros n\'est pas sur une carte.',
            ]);
        }

        return response()->json($resolveur->apercuDeplacement(
            $quete, $personnage, $etat, (int) $donnees['x'], (int) $donnees['y'],
        ));
    }

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
