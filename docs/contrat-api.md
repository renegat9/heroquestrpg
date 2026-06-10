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

## Pièges (doc 10 — tout passe par les menus, pas de nouvel endpoint)

Cycle : **caché** (placé à l'assemblage) → **détecté** (action Fouiller réussie
sur la zone ; auto pour un héros adjacent possédant le nœud *Œil du mineur*) →
**désamorcé** / **franchi** / **déclenché**. L'état des pièges vit dans la carte
de la quête.

- **Déclenchement** : un héros qui entre sur la case d'un piège **caché**
  (déplacement traversant inclus) le déclenche : effet immédiat (−1 PV Body de
  départ, doc 10 §6), `fosse` = immobilisé (le déplacement s'arrête sur la
  case), `piege_a_lances`/`chute_de_blocs` à usage unique. Journal + narration.
- **Désamorcer** (option de menu si adjacent à un piège détecté) : jet de Body
  difficulté 1, réservé au Nain OU à un porteur de la Trousse à outils ; échec
  → le piège se déclenche sur le désamorceur (choix MVP, question ouverte n°3).
- **Franchir une fosse détectée** (option de menu si adjacente) : jet de Body
  difficulté 2 (départ playtest) ; échec = chute (effet de la fosse).
- **EtatGroupe.carte** gagne `pieges: [{x, y, etat: "detecte|desarme|declenche",
  nom}]` — les pièges **cachés n'y figurent jamais** (la table ne les montre
  pas). EtatGroupe.entites héros gagne `niveau`.

## Montée de niveau (doc 01 §5 — par jalons)

Déclencheur : quête `sous_boss` ou `boss_final` **terminée** (victoire). Chaque
héros actif : **+1 niveau** ; à chaque niveau **pair**, +1 PV max (Body pour
barbare/nain, Mind pour elfe/magicien — départ playtest). Les **points de
compétence ne sont pas stockés** : `points_competence = (niveau − 1) −
nb de nœuds acquis` (dérivé, toujours juste).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/competences | {personnage_id, competence_id} | acquiert un nœud d'arbre (422 : pas son héros, classe différente, prérequis manquant, aucun point) |
| GET | /api/moi | — | personnages enrichis : `niveau, points_competence, competences: [ids acquis]` |
| GET | /api/competences | — | catalogue des arbres : `[{id, classe, nom, type, effet, prerequis_id}]` |

À l'acquisition, les effets **passifs chiffrés** du nœud (`effet` JSON :
`attribut_body/attribut_mind/des_attaque/des_defense/pv_body_max/pv_mind_max/
deplacement_base/bonus_sac` +n) sont appliqués au personnage ; les nœuds
`actif`/`deblocage` sont seulement enregistrés (résolution ultérieure). *Œil du
mineur* (Nain) est lu par le moteur de pièges dès acquisition.

Broadcast canal `groupe.{identifiant}` : `.niveau.monte`
({personnages: [{id, nom, niveau, points_competence, gains: [...]}]}) émis à la
clôture victorieuse d'une quête à jalon, avant `.groupe.etat`.

## Sorts des héros (doc 02 — tout par les menus)

**Connaissance par éléments** (connaître un élément = ses 3 sorts, pivot
`personnage_sorts.disponible`) : le **Magicien** choisit **2 éléments** à la
création (`POST joueurs` accepte `elements: ["feu","eau"]` — défaut feu+eau) ;
l'**Elfe** gagne 1 élément en acquérant *Première magie* (`POST competences`
accepte alors `element` — défaut eau) puis un autre via *Second élément* ;
Barbare/Nain : parchemins seulement. Les nœuds *Écoles* du Magicien débloquent
les éléments restants (même mécanique `element`).

**Récupération (S5/S6)** : chaque sort est lançable **1×/quête** ; tout
redevient disponible au démarrage d'une quête ; aucun repos en cours de quête.
*Concentration* (nœud Magicien) : option de menu « Se concentrer » si le nœud
est acquis, qu'un sort est épuisé et qu'elle n'a pas servi cette quête —
sacrifie le tour, récupère UN sort au choix (`parametres: {sort_id}`).

**Résolution (moteur, jamais l'IA)** — options de menu `type: "sort"`
(`parametres: {sort_id, cible?}`) proposées au héros en quête :
- `degats` (Boule de Feu 2 dés, Trait de Feu 1 dé, Génie 4 dés — départ
  playtest) : dés de combat vs défense de la cible (règles de combat de base) ;
  **tir ami possible (S3)** : les héros figurent dans les cibles légales.
- `mental` (Sommeil, Tempête) : jet de Mind de la cible, binaire (S2), Mind 0
  immunisé ; effet = condition (`endormi` : hors combat jusqu'à attaque ;
  `tempete` : n'attaque pas à son prochain tour).
- `utilitaire` : Soin du Corps / Eau de Guérison +4 PV Body (plafonné au max) ;
  Courage +2 dés d'attaque (prochaine attaque) ; Peau de Pierre +2 dés de
  défense (fin du combat) ; Voile de Brume inattaquable (prochain tour) ;
  Vent Véloce déplacement ×2 (ce tour) ; Traverser la Pierre franchit un mur
  (vaut le déplacement). Les effets temporaires vivent en
  `personnage_conditions` (durée en tours) et sont appliqués par le moteur.

**Parchemins (S1/S4)** : option `type: "parchemin"` (`parametres:
{inventaire_id, cible?}`) si le héros en a au sac — lanceur (magicien/elfe) :
réussite auto ; non-lanceur : jet de Mind à la difficulté du sort (1-3) ;
**consommé dans tous les cas**, échec = gaspillé.

`GET /api/moi` : chaque personnage expose `sorts: [{sort_id, nom, element,
type, disponible}]` (et l'onglet Sorts de la manette s'en nourrit ; rafraîchi
aussi via `.groupe.etat` → re-GET).

## Garanties

- **Le moteur fait autorité** : `choix` valide l'option contre le dernier menu
  proposé + l'état ; option illégale → 422.
- **L'API ne dépend jamais du LLM** : si le job IA échoue (pas de clé, erreur),
  repli (menu générique / narration neutre) — le jeu reste jouable.
- Toute mutation d'état passe par un événement journalisé (`evenements`) puis
  un broadcast `.groupe.etat`.
