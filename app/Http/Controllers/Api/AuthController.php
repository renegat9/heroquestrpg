<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\JoueurAuthentifiable;
use App\Http\Controllers\Controller;
use App\Models\Groupe;
use App\Partie\Equipement;
use App\Partie\Images\BibliothequeImages;
use App\Partie\Marche\CapaciteSac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Login simple identifiant / mot de passe (cadre interne, doc 11 §11).
 *
 * Sanctum n'étant pas installé, l'auth est en SESSION : la SPA Vue
 * (même origine) envoie les cookies + le jeton CSRF du blade — voir
 * resources/js/composables/useApi.js et bootstrap/app.php (middlewares
 * de session ajoutés au groupe api).
 */
class AuthController extends Controller
{
    /**
     * POST /api/inscription {pseudo, identifiant}
     *
     * Crée le compte joueur et connecte immédiatement (session). Pas de mot de
     * passe (jeu LAN entre amis). 422 si identifiant déjà pris.
     */
    public function inscription(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'pseudo' => ['required', 'string', 'max:120'],
            'identifiant' => ['required', 'string', 'alpha_dash', Rule::unique('joueurs', 'identifiant')],
        ]);

        $joueur = JoueurAuthentifiable::create([
            'pseudo' => $donnees['pseudo'],
            'identifiant' => $donnees['identifiant'],
        ]);

        Auth::guard('joueur')->login($joueur);
        $request->session()->regenerate();

        return response()->json(['joueur' => $this->profil()], 201);
    }

    /**
     * POST /api/connexion {identifiant}
     *
     * Connexion par NOM seul (jeu LAN) : on retrouve le joueur par son
     * identifiant, sinon par son pseudo (insensible à la casse). Aucun mot de
     * passe — adapté à un réseau local de confiance (doc 11 §11).
     */
    public function connexion(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'identifiant' => ['required', 'string'],
        ]);

        $nom = trim($donnees['identifiant']);

        $joueur = JoueurAuthentifiable::whereRaw('LOWER(identifiant) = ?', [mb_strtolower($nom)])->first()
            ?? JoueurAuthentifiable::whereRaw('LOWER(pseudo) = ?', [mb_strtolower($nom)])->first();

        if ($joueur === null) {
            throw ValidationException::withMessages([
                'identifiant' => 'Aucun joueur à ce nom — crée un compte.',
            ]);
        }

        Auth::guard('joueur')->login($joueur);

        $request->session()->regenerate();

        return response()->json(['joueur' => $this->profil()]);
    }

    /** POST /api/deconnexion */
    public function deconnexion(Request $request): JsonResponse
    {
        Auth::guard('joueur')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['deconnecte' => true]);
    }

    /** GET /api/moi — profil du joueur connecté (reprise / reconnexion). */
    public function moi(): JsonResponse
    {
        return response()->json(['joueur' => $this->profil()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profil(): array
    {
        /** @var JoueurAuthentifiable $joueur */
        $joueur = Auth::guard('joueur')->user();

        return [
            'id' => $joueur->id,
            'pseudo' => $joueur->pseudo,
            'identifiant' => $joueur->identifiant,
            'personnages' => $joueur->personnages()
                ->with(['competences:competences.id', 'sorts', 'groupeActif', 'inventaire.objet'])
                ->get(['id', 'nom', 'classe', 'niveau', 'groupe_actif_id', 'or',
                    'pv_body', 'pv_body_max', 'pv_mind', 'pv_mind_max',
                    'attribut_body', 'attribut_mind', 'des_attaque', 'des_defense'])
                ->map(function ($p) {
                    $disponible = $p->groupe_actif_id === null;

                    $data = [
                        'id' => $p->id,
                        'nom' => $p->nom,
                        'classe' => $p->classe,
                        'niveau' => (int) $p->niveau,
                        // Portrait unique du héros si généré, sinon image de classe
                        // (null si aucune → la manette/roster affiche l'icône).
                        'portrait_url' => app(BibliothequeImages::class)->urlHeros($p->id, $p->classe),
                        // PV persistants : la manette affiche bandeau + jauges au
                        // hub (hors quête, sans entité d'état EtatGroupe).
                        'pv_body' => (int) $p->pv_body,
                        'pv_body_max' => (int) $p->pv_body_max,
                        'pv_mind' => (int) $p->pv_mind,
                        'pv_mind_max' => (int) $p->pv_mind_max,
                        // Attributs + dés (fiche perso, doc 01 §4) : ne varient pas
                        // en quête (contrairement aux PV), donc pas besoin d'entité
                        // EtatGroupe — /moi suffit à la fiche, même en quête.
                        'attribut_body' => (int) $p->attribut_body,
                        'attribut_mind' => (int) $p->attribut_mind,
                        'des_attaque' => (int) $p->des_attaque,
                        'des_defense' => (int) $p->des_defense,
                        // Bourse PERSONNELLE persistante (part reçue à la clôture) :
                        // invisible jusqu'ici (ni roster ni fiche n'avaient `or`).
                        'or' => (int) $p->or,
                        // Points JAMAIS stockés (contrat) : (niveau − 1) − nœuds acquis.
                        'points_competence' => max(0, ((int) $p->niveau - 1) - $p->competences->count()),
                        'competences' => $p->competences->pluck('id')->values()->all(),
                        // Équipement réel (fiche/sac) : arme(s) + armure nommées,
                        // sac général à part — doc 01 §7 (emplacements). Chaque
                        // pièce ÉQUIPÉE porte son inventaire_id (pour déséquiper) ;
                        // chaque objet du sac porte `equipable` (pièce montable
                        // dans un slot — cf. Equipement::SLOTS, qui compte le
                        // `casque` à part depuis le 2026-08-08).
                        'equipement' => [
                            'armes' => $p->inventaire
                                ->filter(fn ($l) => in_array($l->emplacement, ['arme_principale', 'arme_secondaire'], true) && $l->objet !== null)
                                // `emplacement` exposé : un bouclier occupe
                                // `arme_secondaire` et s'affichait donc comme une
                                // seconde ARME, icône épées croisées comprise.
                                ->map(fn ($l) => [
                                    'inventaire_id' => $l->id,
                                    'nom' => $l->objet->nom,
                                    'emplacement' => $l->emplacement,
                                    'bouclier' => (bool) ($l->objet->effet['incompatible_deux_mains'] ?? false),
                                    'deux_mains' => (bool) ($l->objet->effet['deux_mains'] ?? false),
                                    // Les dés de CETTE arme : avec deux armes en
                                    // main, la colonne `des_attaque` ne dit plus
                                    // qu'une moitié de la vérité (elle ne connaît
                                    // que la main droite).
                                    'des_attaque' => (bool) ($l->objet->effet['incompatible_deux_mains'] ?? false)
                                        ? null
                                        : app(Equipement::class)->desAttaqueAvec($p, $l),
                                ])
                                ->values()
                                ->all(),
                            'armure' => with(
                                $p->inventaire->first(fn ($l) => $l->emplacement === 'armure' && $l->objet !== null),
                                fn ($l) => $l === null ? null : ['inventaire_id' => $l->id, 'nom' => $l->objet->nom],
                            ),
                            // Slot propre depuis le 2026-08-08 : le casque se
                            // CUMULE avec l'armure de corps, comme au plateau.
                            // Sans cette clé la manette affichait le héros
                            // tête nue et ne proposait pas de le déséquiper.
                            'casque' => with(
                                $p->inventaire->first(fn ($l) => $l->emplacement === 'casque' && $l->objet !== null),
                                fn ($l) => $l === null ? null : ['inventaire_id' => $l->id, 'nom' => $l->objet->nom],
                            ),
                            // Talisman (artefact de classe) : cinquième slot.
                            'talisman' => with(
                                $p->inventaire->first(fn ($l) => $l->emplacement === 'talisman' && $l->objet !== null),
                                fn ($l) => $l === null ? null : ['inventaire_id' => $l->id, 'nom' => $l->objet->nom],
                            ),
                            'sac' => $p->inventaire
                                ->filter(fn ($l) => $l->emplacement === 'sac' && $l->objet !== null)
                                ->map(fn ($l) => [
                                    'inventaire_id' => $l->id,
                                    'nom' => $l->objet->nom,
                                    'categorie' => $l->objet->categorie,
                                    'rarete' => $l->objet->rarete,
                                    'quantite' => (int) $l->quantite,
                                    'equipable' => in_array($l->objet->emplacement, Equipement::SLOTS, true),
                                    // Emplacements POSSIBLES : deux pour une arme
                                    // à une main (main droite ou main gauche —
                                    // dual-wielding), un seul pour tout le reste.
                                    // Sans cette clé la manette ne pourrait pas
                                    // proposer le choix, et le second slot
                                    // n'existerait que pour l'API.
                                    'slots' => app(Equipement::class)->slotsPossibles($l->objet),
                                ])
                                ->values()
                                ->all(),
                            // Charge du sac : la manette n'avait aucun moyen de
                            // la voir, alors qu'un butin de quête peut faire
                            // DÉBORDER (un artefact est remis même sac plein —
                            // le refuser le perdrait à jamais). `occupation` peut
                            // donc dépasser `capacite`.
                            'capacite' => CapaciteSac::pour($p),
                            'occupation' => CapaciteSac::occupation($p),
                            // Maîtrises du héros (doc 01 §7) : classe + nœuds
                            // `acces_equipement`. Rendues par le service qui fait
                            // AUSSI le contrôle, pour que le badge « non maîtrisé »
                            // de l'étal ne puisse pas diverger de la règle réelle.
                            'maitrises' => app(Equipement::class)->tagsAccessibles($p),
                        ],
                        // Consommables (potions) réels : la manette propose « Boire »
                        // à tout moment (action gratuite, canon) — POST /potions.
                        'consommables' => $p->inventaire
                            ->filter(fn ($l) => $l->objet !== null && $l->objet->categorie === 'consommable')
                            ->map(fn ($l) => [
                                'inventaire_id' => $l->id,
                                'nom' => $l->objet->nom,
                                'quantite' => (int) $l->quantite,
                                'effet' => $l->objet->effet,
                                // Trois potions officielles sont réservées au
                                // Barbare, deux à l'Elfe. On BADGE, on ne filtre
                                // pas : un héros a le droit de PORTER la potion
                                // d'un compagnon — le marché autorise déjà
                                // l'achat pour autrui. La manette grise « Boire ».
                                'utilisable' => app(Equipement::class)->estAccessible($p, $l->objet),
                            ])
                            ->values()
                            ->all(),
                        // Répertoire de sorts (contrat) : l'onglet Sorts de la
                        // manette s'en nourrit, disponibilité par quête comprise.
                        'sorts' => $p->sorts
                            ->map(fn ($s) => [
                                'sort_id' => $s->id,
                                'nom' => $s->nom,
                                'element' => $s->element,
                                'type' => $s->type,
                                'disponible' => (bool) $s->pivot->disponible,
                                'image_url' => app(BibliothequeImages::class)->urlSort($s->id, $s->nom),
                            ])
                            ->values()
                            ->all(),
                        'disponible' => $disponible,
                    ];

                    // Personnage engagé : expose le groupe avec narrateur_actif (contrat).
                    if (! $disponible && $p->groupeActif !== null) {
                        /** @var Groupe $g */
                        $g = $p->groupeActif;
                        $data['groupe'] = [
                            'identifiant' => $g->identifiant,
                            'nom' => $g->nom,
                            'phase' => $g->phase,
                            'narrateur_actif' => TableController::narrateurActif($g),
                        ];
                    }

                    return $data;
                })
                ->values()
                ->all(),
        ];
    }
}
