# Contrat API & temps réel — prototype vertical

> Contrat partagé entre le serveur (Laravel) et le front (Vue). Toute évolution
> se fait ici d'abord. Réfs : doc 11 §4 (flux d'un tour), §7 (canaux).

## Authentification

Session Laravel (cookie) — login simple interne. `POST /api/connexion`
`{identifiant, mot_de_passe}` → `{joueur: {id, pseudo}}`. Routes protégées par
middleware `auth` sauf connexion.

## Endpoints

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| POST | /api/connexion | {identifiant, mot_de_passe} | {joueur} |
| POST | /api/deconnexion | — | 204 |
| GET | /api/moi | — | {joueur, personnages: [...]} |
| POST | /api/groupes | {nom, theme, longueur, ton} | {groupe} + dispatch squelette |
| POST | /api/groupes/{identifiant}/joueurs | {personnage_id} ou {nom, classe} | {personnage} (rejoint le groupe) |
| GET | /api/groupes/{identifiant}/etat | — | **EtatGroupe** (voir ci-dessous) |
| POST | /api/groupes/{identifiant}/quetes | — | {quete} — démarre la quête suivante (assemble carte, spawn monstres, initiative) |
| POST | /api/groupes/{identifiant}/choix | {option_id, parametres?} | 202 — le moteur résout, l'état et la narration arrivent par Reverb |

## EtatGroupe (GET etat + broadcast `.groupe.etat`)

```json
{
  "groupe": {"identifiant": "...", "nom": "...", "phase": "hub|quete", "or": 0, "etat": "en_cours"},
  "quete": {"id": 1, "titre": "...", "type_jalon": "normale", "etat": "en_cours"} ,
  "carte": {"largeur": 12, "hauteur": 10, "cases": [["m","s","p"]]},
  "entites": [
    {"type": "heros", "id": 1, "nom": "...", "classe": "nain", "x": 2, "y": 3,
     "pv_body": 6, "pv_body_max": 8, "pv_mind": 4, "pv_mind_max": 4, "tombe": false},
    {"type": "monstre", "id": 9, "nom": "<habillage IA ou nom_base>", "x": 5, "y": 4,
     "pv_body": 2, "pv_body_max": 2, "etat": "actif"}
  ],
  "initiative": [{"entite": "heros|monstre", "id": 1, "nom": "...", "a_joue": false}],
  "narration": "dernier texte du MJ",
  "mj_reflechit": false
}
```

`quete`/`carte`/`entites`/`initiative` sont `null`/`[]` en phase hub.

## Canaux Reverb (préfixe d'événement = broadcastAs)

| Canal | Événement | Payload | Écouté par |
|---|---|---|---|
| `groupe.{identifiant}` (private) | `.narration.diffusee` | {texte} | table |
| `groupe.{identifiant}` | `.groupe.etat` | EtatGroupe | table + manettes |
| `groupe.{identifiant}` | `.mj.reflechit` | {actif} | table + manettes |
| `joueur.{id}` (private) | `.menu.propose` | {menu: {contexte, options: [{id, libelle, type: "action|dialogue|jet|attaque|deplacement", parametres}]}} | manette du joueur |

Autorisations (routes/channels.php) : `groupe.{identifiant}` → le joueur a un
personnage actif dans ce groupe ; `joueur.{id}` → id === joueur connecté.

## Phase marché (doc 04 §5 — au hub uniquement)

La phase vit en cache serveur (comme les menus) ; rien n'est appliqué avant la
confirmation de TOUS les joueurs membres, puis application **atomique** en
transaction. Le MJ IA choisit le profil de lieu ; sans LLM, profil `bourg`.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/marche | {profil?} | ouvre la phase (422 si pas au hub ou déjà ouverte) |
| GET | /groupes/{identifiant}/marche | — | EtatMarche |
| PUT | /groupes/{identifiant}/marche/panier | {achats:[{objet_id,quantite}], ventes:[{inventaire_id}]} | remplace le panier du joueur, annule sa confirmation |
| POST | /groupes/{identifiant}/marche/confirmation | — | confirme ; si tous confirmés → application + clôture |
| DELETE | /groupes/{identifiant}/marche | — | annule la phase (rien appliqué) |

**EtatMarche** : `{profil, multiplicateur, inventaire: [{objet_id, nom, categorie,
rarete, prix, stock}], paniers: [{joueur_id, pseudo, achats: [...], ventes: [...],
confirme, inventaire: [{inventaire_id, personnage_id, objet_id, nom, categorie,
rarete, emplacement, quantite, revente}]}], total_projete, or_courant}` — le
`inventaire` de chaque panier liste ce que ce joueur peut vendre (héros actifs),
avec le prix de revente M1 déjà calculé. Prix d'achat = prix_base × multiplicateur
du profil (doc 04 §3) ; revente = 50 % du prix marchand courant (M1) ; rareté
`unique` jamais en stock. Garde-fous à l'application : total ≥ 0, stock, objets
vendus réellement possédés, **capacité de sac** (PV Body max ÷ 2 arrondi
inférieur + bonus_sac de classe — doc 01) respectée pour chaque personnage.

Précisions serveur : stocks de départ playtest par rareté (commun = illimité,
peu_commun = 3, rare = 1) ; chaque ligne d'achat accepte un `personnage_id`
optionnel (un des héros du joueur — son premier par défaut) ; les achats non
consommables vont au **sac**, les consommables s'empilent hors capacité.

Broadcasts canal `groupe.{identifiant}` : `.marche.ouvert` (EtatMarche),
`.marche.maj` (EtatMarche, à chaque panier/confirmation), `.marche.finalise`
({applique: bool}) suivi de `.groupe.etat`.

## Votes de groupe (doc 05 §5)

Un seul vote actif par groupe (cache + journal). Types MVP : `retrait_joueur`
(en quête ; le joueur visé ne vote pas ; majorité requise, **égalité = il
reste**) et `choix_groupe` (question + options posées par le MJ ou un joueur ;
résolution à complétude des votants, majorité simple — **égalité = la première
option au décompte stable** (ordre de déclaration), choix déterministe MVP à
raffiner en playtest).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/votes | {type, question?, options?: [{id, libelle}], cible_joueur_id?} | lance le vote (422 si un vote est actif) |
| POST | /groupes/{identifiant}/votes/bulletin | {option_id} | vote du joueur ; à complétude → résolution |
| GET | /groupes/{identifiant}/votes | — | vote actif ou null |

Résolution `retrait_joueur` : option `oui` majoritaire → le joueur quitte le
groupe avec **sa part de l'or d'avant la quête** (`quetes.or_initial` ÷ membres,
doc 05 §5) versée à son personnage ; personnages détachés (groupe_actif_id null).
Hors quête, pas de vote : `POST /groupes/{identifiant}/depart` (part du pot
commun ÷ membres présents).

Broadcasts canal `groupe.{identifiant}` : `.vote.lance` ({vote}), `.vote.maj`
({decompte, exprimes, attendus}), `.vote.resultat` ({option_id, applique}) puis
`.groupe.etat` si l'état a changé.

## Garanties

- **Le moteur fait autorité** : `choix` valide l'option contre le dernier menu
  proposé + l'état ; option illégale → 422.
- **L'API ne dépend jamais du LLM** : si le job IA échoue (pas de clé, erreur),
  repli (menu générique / narration neutre) — le jeu reste jouable.
- Toute mutation d'état passe par un événement journalisé (`evenements`) puis
  un broadcast `.groupe.etat`.
