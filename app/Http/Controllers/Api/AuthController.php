<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Auth\JoueurAuthentifiable;
use App\Engine\MotsClesEquipement;
use App\Http\Controllers\Controller;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\PersonnageHistorique;
use App\Partie\Equipement;
use App\Partie\Forge;
use App\Partie\Images\BibliothequeImages;
use App\Partie\Marche\CapaciteSac;
use App\Partie\Talents;
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

        $personnages = $joueur->personnages()
            ->with(['competences:competences.id,competences.nom,competences.effet', 'sorts', 'groupeActif', 'inventaire.objet'])
            ->get(['id', 'nom', 'classe', 'niveau', 'groupe_actif_id', 'or',
                'pv_body', 'pv_body_max', 'pv_mind', 'pv_mind_max',
                'attribut_body', 'attribut_mind', 'des_attaque', 'des_defense']);

        // `supprimable` (DELETE /personnages/{id}, contrat) : « jamais joué »
        // se lit sur DEUX tables — en UNE requête chacune sur tout le roster,
        // pas une par personnage (`enQuete()` ci-dessous ne couvre, lui, que
        // la quête EN COURS).
        $idsDejaEnQuete = EtatPersonnageQuete::whereIn('personnage_id', $personnages->pluck('id'))
            ->distinct()->pluck('personnage_id');
        $idsAvecHistorique = PersonnageHistorique::whereIn('personnage_id', $personnages->pluck('id'))
            ->distinct()->pluck('personnage_id');

        // ⚠ Forge du Nain : « le serveur publie la DÉCISION, pas les
        // ingrédients » — calculé UNE FOIS pour tout le roster, jamais objet
        // par objet. Groupes où CE JOUEUR contrôle personnellement un
        // forgeron actif (même condition que `ForgeController::appliquer()` :
        // un de SES héros, actif dans le groupe, portant le nœud
        // `forge_amelioration`) — la manette ne doit donc jamais re-dériver
        // « ai-je un nain » ni « suis-je au hub » depuis `classe`/`phase`.
        $groupesAvecForgeronDuJoueur = $personnages
            ->filter(fn ($p) => $p->groupe_actif_id !== null && app(Talents::class)->a($p, 'forge_amelioration'))
            ->pluck('groupe_actif_id')
            ->unique();
        $forge = app(Forge::class);

        return [
            'id' => $joueur->id,
            'pseudo' => $joueur->pseudo,
            'identifiant' => $joueur->identifiant,
            'personnages' => $personnages
                ->map(function ($p) use ($idsDejaEnQuete, $idsAvecHistorique, $groupesAvecForgeronDuJoueur, $forge) {
                    $disponible = $p->groupe_actif_id === null;
                    // Les deux préalables de `ForgeController::appliquer()` avant
                    // même de regarder l'objet : le groupe est au hub, et LE
                    // JOUEUR QUI REGARDE (pas forcément CE personnage) contrôle
                    // un forgeron actif ici. `Forge::estForgeable()` part de ce
                    // booléen déjà tranché plutôt que de rejuger objet par objet.
                    $peutForgerIci = ! $disponible
                        && ($p->groupeActif->phase ?? null) === 'hub'
                        && $groupesAvecForgeronDuJoueur->contains($p->groupe_actif_id);
                    // L'état de quête sert aux fenêtres « une fois par quête /
                    // par tour » des capacités : lu UNE fois par héros, pas une
                    // fois par nœud.
                    $etatQuete = EtatPersonnageQuete::enQuete($p);
                    // ⚠ La DÉCISION « ce héros peut être supprimé », pas ses
                    // ingrédients : DELETE /personnages/{id} réévalue ces
                    // trois mêmes conditions côté serveur (contrat), et le
                    // roster ne doit JAMAIS re-dériver « jamais joué » en JS —
                    // même défaut que `sort_bonus_disponible`/`embrasure`.
                    // `enQuete()` ne regarde QUE la quête `en_cours` ; ici il
                    // faut TOUTE trace passée, d'où les deux ensembles calculés
                    // en amont (une requête chacun, pas une par personnage).
                    $supprimable = $disponible
                        && ! $idsDejaEnQuete->contains($p->id)
                        && ! $idsAvecHistorique->contains($p->id);

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
                        // ⚠ Point de passage UNIQUE. Cette ligne portait sa
                        // propre copie de la formule, et la copie ignorait
                        // comme l'autre que les capacités `innee` ne coûtent
                        // rien — deux exemplaires d'une règle assez simple pour
                        // que personne ne remarque l'une dériver.
                        'points_competence' => $p->pointsCompetence(),
                        // ⚠ La DÉCISION d'usage, pas ses ingrédients (René,
                        // 2026-09-14 : « afficher si une abileté est disponible
                        // ou non et pourquoi il n'est pas disponible quand
                        // c'est le cas »). Une manette qui relirait
                        // `effet.frequence` et `capacites_utilisees` en JS
                        // re-dériverait une règle serveur : c'est la classe de
                        // défaut la plus répétée du projet côté écran, et
                        // `Talents::fiche()` est le même point de passage que
                        // le `disponible()` du moteur.
                        'competences' => $p->competences
                            ->map(fn ($c) => ['id' => $c->id] + app(Talents::class)->fiche($p, $etatQuete, $c))
                            ->values()
                            ->all(),
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
                                    'avantages' => MotsClesEquipement::avantages((array) $l->objet->effet),
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
                                ] + $this->detailForge($forge, $l, $peutForgerIci))
                                ->values()
                                ->all(),
                            'armure' => with(
                                $p->inventaire->first(fn ($l) => $l->emplacement === 'armure' && $l->objet !== null),
                                fn ($l) => $l === null ? null : ['inventaire_id' => $l->id, 'nom' => $l->objet->nom,
                                    'avantages' => MotsClesEquipement::avantages((array) $l->objet->effet)]
                                    + $this->detailForge($forge, $l, $peutForgerIci),
                            ),
                            // Slot propre depuis le 2026-08-08 : le casque se
                            // CUMULE avec l'armure de corps, comme au plateau.
                            // Sans cette clé la manette affichait le héros
                            // tête nue et ne proposait pas de le déséquiper.
                            'casque' => with(
                                $p->inventaire->first(fn ($l) => $l->emplacement === 'casque' && $l->objet !== null),
                                fn ($l) => $l === null ? null : ['inventaire_id' => $l->id, 'nom' => $l->objet->nom,
                                    'avantages' => MotsClesEquipement::avantages((array) $l->objet->effet)]
                                    + $this->detailForge($forge, $l, $peutForgerIci),
                            ),
                            // Talisman (artefact de classe) : cinquième slot.
                            'talisman' => with(
                                $p->inventaire->first(fn ($l) => $l->emplacement === 'talisman' && $l->objet !== null),
                                fn ($l) => $l === null ? null : ['inventaire_id' => $l->id, 'nom' => $l->objet->nom,
                                    'avantages' => MotsClesEquipement::avantages((array) $l->objet->effet)]
                                    + $this->detailForge($forge, $l, $peutForgerIci),
                            ),
                            // Bottes : sixième slot (2026-09-04). Sans cette clé
                            // la manette chausserait le héros sans jamais le
                            // montrer ni permettre de le déchausser — exactement
                            // ce qui était arrivé au casque.
                            'bottes' => with(
                                $p->inventaire->first(fn ($l) => $l->emplacement === 'bottes' && $l->objet !== null),
                                fn ($l) => $l === null ? null : ['inventaire_id' => $l->id, 'nom' => $l->objet->nom,
                                    'avantages' => MotsClesEquipement::avantages((array) $l->objet->effet)]
                                    + $this->detailForge($forge, $l, $peutForgerIci),
                            ),
                            'sac' => $p->inventaire
                                ->filter(fn ($l) => $l->emplacement === 'sac' && $l->objet !== null)
                                ->map(function ($l) use ($p, $forge, $peutForgerIci) {
                                    $equipement = app(Equipement::class);
                                    $portees = $equipement->occupants($p);
                                    // Point de passage unique avec l'option `equiper` du menu
                                    // de quête (sous-choix, 2026-09-18) : les deux posent la
                                    // même question (« quels slots, et quoi remplacent-ils ? »)
                                    // via `Equipement::detailEquipabilite()`, jamais recalculée
                                    // deux fois.
                                    $detail = $equipement->detailEquipabilite($l->objet, $l, $portees);

                                    return [
                                        'inventaire_id' => $l->id,
                                        'nom' => $l->objet->nom,
                                        'categorie' => $l->objet->categorie,
                                        'rarete' => $l->objet->rarete,
                                        // ⚠ Ce que la pièce FAIT, en clair (René,
                                        // 2026-09-04 : « pouvoir voir le détail des
                                        // items »). Un objet n'a pas de description
                                        // écrite — son `effet` est la seule source de
                                        // vérité, et `MotsClesEquipement::avantages()`
                                        // la traduit plutôt que de la paraphraser.
                                        // Le vocabulaire d'affichage vit côté SERVEUR :
                                        // une table côté client dérive de la donnée
                                        // qu'elle décrit, et les talents l'ont déjà payé.
                                        'avantages' => MotsClesEquipement::avantages((array) $l->objet->effet),
                                        'quantite' => (int) $l->quantite,
                                        'equipable' => in_array($l->objet->emplacement, Equipement::SLOTS, true),
                                        // Emplacements POSSIBLES : deux pour une arme
                                        // à une main (main droite ou main gauche —
                                        // dual-wielding), un seul pour tout le reste.
                                        // Sans cette clé la manette ne pourrait pas
                                        // proposer le choix, et le second slot
                                        // n'existerait que pour l'API.
                                        'slots' => $equipement->slotsPossibles($l->objet),
                                        // ⚠ Les emplacements où monter la pièce
                                        // change VRAIMENT quelque chose, et ce que
                                        // chacun porte déjà (René, 2026-09-04 : le
                                        // sac affichait « Équiper Casque » à côté
                                        // de « Déséquiper Casque » sur un héros qui
                                        // en avait deux — le second échange ne
                                        // modifie rien, `equiper()` se contentant
                                        // de renvoyer l'occupant au sac).
                                        //
                                        // La décision est prise ICI, pas dans la
                                        // manette : le vocabulaire d'affichage vit
                                        // côté serveur, faute de quoi il dérive de
                                        // la règle qu'il décrit.
                                        'slots_utiles' => $detail['slots_utiles'],
                                        'remplace' => $detail['remplace'],
                                    ] + $this->detailForge($forge, $l, $peutForgerIci);
                                })
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
                                // ⚠ `effet` était déjà publié, mais BRUT : la
                                // manette n'avait aucun moyen d'en tirer une
                                // phrase, et une table de traduction côté client
                                // dérive de la donnée qu'elle décrit.
                                'avantages' => MotsClesEquipement::avantages((array) $l->objet->effet),
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
                        // Contrat DELETE /personnages/{id} : le bouton
                        // « Supprimer » du roster (JoueurView.vue) LIT ce
                        // booléen au lieu de re-dériver « libre + jamais
                        // joué » côté client — le serveur publie la décision.
                        'supprimable' => $supprimable,
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

    /**
     * Ce que `/moi` publie sur la Forge pour UNE ligne d'inventaire —
     * l'amélioration déjà posée (lisible même quand ce joueur n'a pas de nain :
     * René, « sans oublier d'afficher l'amélioration faite aux objets déjà
     * forgés ») et la DÉCISION « forgeable maintenant », jamais ses ingrédients
     * (rareté, phase, nœud du porteur — la manette ne les recombine pas).
     *
     * ⚠ `forge_catalogue` EST la liste blanche : c'est exactement l'ensemble
     * que `POST /forge` acceptera pour `amelioration_id` sur cette pièce
     * (même filtre, `Forge::ameliorationsApplicables()`) — jamais une pièce
     * offerte que le résolveur refuserait, jamais l'inverse (`ForgeTest`
     * confronte les deux sens).
     *
     * @return array{ameliorations: list<array<string, mixed>>, forgeable: bool, forge_catalogue: list<array<string, mixed>>}
     */
    private function detailForge(Forge $forge, Inventaire $ligne, bool $forgeronDisponible): array
    {
        $forgeable = $forge->estForgeable($ligne, $forgeronDisponible);

        return [
            'ameliorations' => collect($ligne->ameliorations ?? [])
                ->map(fn ($a) => [
                    'nom' => $a['nom'] ?? '',
                    'avantages' => MotsClesEquipement::avantages((array) ($a['effet'] ?? [])),
                ])
                ->values()
                ->all(),
            'forgeable' => $forgeable,
            'forge_catalogue' => $forgeable
                ? $forge->ameliorationsApplicables((string) $ligne->objet?->categorie)
                    ->map(fn ($a) => [
                        'id' => $a->id,
                        'nom' => $a->nom,
                        'prix' => (int) $a->prix,
                        'avantages' => MotsClesEquipement::avantages((array) $a->effet),
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }
}
