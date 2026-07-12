# Contrat API & temps réel — prototype vertical

> Contrat partagé entre le serveur (Laravel) et le front (Vue). Toute évolution
> se fait ici d'abord. Réfs : doc 11 §4 (flux d'un tour), §7 (canaux).

## Authentification

Session Laravel (cookie) — **connexion par NOM seul** (jeu LAN entre amis, pas de
mot de passe). `POST /api/connexion` `{identifiant}` retrouve le joueur par son
identifiant, sinon par son pseudo (insensible à la casse) → `{joueur: {id, pseudo}}`.
Routes protégées par middleware `auth` sauf connexion.

## Endpoints

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| POST | /api/connexion | {identifiant} | {joueur} (nom seul, sans mot de passe) |
| POST | /api/deconnexion | — | 204 |
| GET | /api/moi | — | {joueur, personnages: [...]} |
| POST | /api/groupes | {nom, theme, longueur, ton} | {groupe} + dispatch squelette |
| POST | /api/groupes/{identifiant}/joueurs | {personnage_id} ou {nom, classe} | {personnage} (rejoint le groupe) |
| GET | /api/groupes/{identifiant}/etat | — | **EtatGroupe** (voir ci-dessous) |
| POST | /api/groupes/{identifiant}/quetes | — | {quete} — démarre la quête suivante (assemble carte, spawn monstres, initiative) |
| POST | /api/groupes/{identifiant}/choix | {option_id, parametres?} | 202 — le moteur résout, l'état et la narration arrivent par Reverb |
| GET | /api/groupes/{identifiant}/menu | — | {menu, personnage_id} \| {menu: null} — rattrapage du menu courant (régénéré si c'est le tour du héros) |

## EtatGroupe (GET etat + broadcast `.groupe.etat`)

```json
{
  "groupe": {"identifiant": "...", "nom": "...", "phase": "hub|quete", "or": 0, "etat": "en_cours",
             "prologue": {"texte": "prémisse...", "url": "/audio/.../...wav|null",
                          "menace": {"nom": "...", "description": "..."}, "auto": true}},
  "quete": {"id": 1, "titre": "...", "type_jalon": "normale", "etat": "en_cours"} ,
  "carte": {"largeur": 12, "hauteur": 10, "cases": [["m","s","p"]],
            "portes": [{"x": 4, "y": 3, "etat": "ouverte|verrouillee", "verrou": "cle|monstres_vaincus|levier"}]},
  "entites": [
    {"type": "heros", "id": 1, "nom": "...", "classe": "nain", "x": 2, "y": 3,
     "pv_body": 6, "pv_body_max": 8, "pv_mind": 4, "pv_mind_max": 4, "tombe": false},
    {"type": "monstre", "id": 9, "nom": "<habillage IA ou nom_base>", "x": 5, "y": 4,
     "pv_body": 2, "pv_body_max": 2, "etat": "actif"}
  ],
  "initiative": [{"entite": "heros|monstre", "id": 1, "nom": "...", "a_joue": false, "tombe": false}],
  "narration": "dernier texte du MJ",
  "narration_sequence": 42,
  "mj_reflechit": false
}
```

`quete`/`carte`/`entites`/`initiative` sont `null`/`[]` en phase hub —
**sauf après un TPK** : tant que la dernière quête est `echouee` (ni reprise,
ni nouvelle quête), elle reste exposée (avec sa carte et ses entités) pour le
bandeau « recharger / abandonner » de la table et l'écran d'attente de la
manette, qui testent `quete.etat === "echouee"`.
**Révélation par salle** : les monstres d'une salle restent DORMANTS (absents de
`entites` et `initiative`, ne jouent pas) tant que la salle n'a pas été découverte
par un héros. À la première entrée dans une salle (déplacement ou sort *Traverser
la Pierre*), ses monstres sont révélés et le MJ décrit la salle (narration). Un
piège déclenché par un déplacement est lui aussi décrit (narration `piege_declenche`).
`groupe.prologue` (hub uniquement) porte la prémisse de campagne + la menace pour
l'écran de prologue de la table ; `auto` est vrai tant qu'aucune quête n'a eu lieu
(ouverture automatique au lancement). `url` = vraie voix de narrateur si générée,
sinon `null` → lecture Web Speech. Absent si aucun squelette de campagne.

`initiative[].tombe` (héros uniquement ; toujours `false` pour un monstre) :
un héros **tombé** est SAUTÉ par le moteur (`verifierInitiative`) — l'acteur
courant côté client est le premier de l'ordre avec `a_joue=false` **et**
`tombe=false`, jamais un héros à terre (sa manette affiche « à terre »,
pas un menu).

`narration_sequence` = numéro de séquence (journal) de la dernière narration —
**anti-inversion** : plusieurs narrations partent en jobs asynchrones de durées
différentes (cérémonie de lancement instantanée, narration IA sur file lente…) ;
rien ne garantit qu'elles arrivent dans l'ordre où elles ont été déclenchées. Le
client (store `setNarration`) ignore toute narration (`.narration.diffusee` comme
`EtatGroupe.narration`) dont la `sequence` est ≤ à la dernière déjà affichée,
plutôt que d'inverser l'ordre perçu (ex. narration de la quête suivante entendue
avant celle du coup fatal qui a provoqué le TPK).

## Canaux Reverb (préfixe d'événement = broadcastAs)

| Canal | Événement | Payload | Écouté par |
|---|---|---|---|
| `groupe.{identifiant}` (private) | `.narration.diffusee` | {texte, ambiance?, quete_id?, url?, sequence?} | table (joue `url` = vraie voix de narrateur si présente, sinon lit `texte` en Web Speech) — `sequence` ignorée si ≤ à la dernière affichée (anti-inversion) |
| `groupe.{identifiant}` | `.bark.diffuse` | {profil, evenement: "attaque\|touche\|rate\|mort", nom, texte?, url?} | table (joue `url` si présente, sinon lit `texte` en TTS) |
| `groupe.{identifiant}` | `.combat.journal` | {lignes: [{texte, ton}], sequence} | **manettes** — fil mécanique du tour (attaques, dégâts, chutes, tour des monstres/alliés, résultat de fouille) dérivé du résultat moteur, **aucun LLM** : comble le « combat instantané » où seule la table avait un retour (barks). `ton` ∈ `degats\|mort\|subit\|chute\|pare\|succes\|echec\|info` ; `sequence` (max `Evenement.sequence`) sert de garde-fou anti-rediffusion ; lot ignoré si `sequence` ≤ au dernier appliqué |
| `groupe.{identifiant}` | `.groupe.etat` | EtatGroupe | table + manettes |
| `groupe.{identifiant}` | `.mj.reflechit` | {actif} | table + manettes |
| `joueur.{id}` (private) | `.menu.propose` | {menu: {contexte, options: [{id, libelle, type: "action|dialogue|jet|attaque|deplacement", parametres}]}} | manette du joueur |

Un tour de héros = **deux créneaux** (doc 03 §28) : un **déplacement** et une
**action**, dans l'ordre choisi. Le menu n'offre que les créneaux encore libres,
plus « Terminer le tour » (`attendre`). Le tour ne passe au héros suivant / aux
monstres que lorsque les deux créneaux sont consommés ou via une action
terminante (concentration, relever, terminer). 

L'option `deplacement` (id `se_deplacer`) porte dans `parametres` l'allonce du
tour, **lancée une seule fois par tour et mémorisée** (doc 03 §3 : base + 1d6) :
`{base, de (résultat du d6), portee (cases max ce tour, Vent Véloce inclus)}`. La
manette affiche le dé puis une mini-carte tappable des cases accessibles ; le
choix part en `POST choix {option_id: "se_deplacer", parametres: {x, y}}`, que le
moteur revalide contre `portee` (réservé re-lancé en repli si absent).

Autorisations (routes/channels.php) : `groupe.{identifiant}` → le joueur a un
personnage actif dans ce groupe ; `joueur.{id}` → id === joueur connecté.

## Phase marché (doc 04 §5 — au hub uniquement)

La phase vit en cache serveur (comme les menus) ; rien n'est appliqué avant la
confirmation de TOUS les joueurs membres, puis application **atomique** en
transaction. Le MJ IA choisit le profil de lieu ; sans LLM, profil `bourg`.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/marche | {profil?} | ouvre la phase (422 si pas au hub ou déjà ouverte) — **membre OU table** (bouton sur l'écran de table, même règle que la clôture) |
| GET | /groupes/{identifiant}/marche | — | EtatMarche |
| PUT | /groupes/{identifiant}/marche/panier | {achats:[{objet_id,quantite}], ventes:[{inventaire_id}]} | remplace le panier du joueur, annule sa confirmation |
| POST | /groupes/{identifiant}/marche/confirmation | — | confirme ; si tous confirmés → application + clôture |
| DELETE | /groupes/{identifiant}/marche | — | annule la phase (rien appliqué) — **membre OU table** |

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

## Alliés — mercenaires (doc 14 §3.5 — au hub uniquement)

| Méthode | URL | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/mercenaires | {mercenaire_id} | recrute un allié contre l'or de la **bourse commune** (422 si pas au hub, or insuffisant, ou 2ᵉ compagnon animal) |

PNJ **scriptés** (hors roster), **consommés en fin de quête** (purgés à la
victoire comme à l'échec). Au démarrage de quête ils sont instanciés sur les
cases de spawn restantes, à côté des héros. Ils jouent en **phase dédiée**, juste
AVANT les monstres et **hors initiative des héros** : ils ciblent les monstres
(tir avec ligne de vue pour un allié à distance, sinon corps-à-corps). Réponse :
`{recrue:{id,nom,type,animal}, or}` ; broadcast `.groupe.etat`.

Dans **EtatGroupe.entites**, un allié actif apparaît avec `type:'allie'`
(`{id, nom, x, y, pv_body, pv_body_max, animal}`). La résolution d'un tour de
choix peut porter `resultat.tour_allies.actions` (déplacements/attaques alliées),
en regard de `resultat.tour_monstres.actions`.

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

## Portes & exploration (doc 14 §3.1/3.2/3.3 — tout passe par les menus)

L'état des portes vit dans la carte de la quête (`cartes.grille.portes` :
`{x, y, etat: "ouverte|verrouillee|secrete", verrou?, revele?}`). Une porte NON
`ouverte` est **infranchissable** (pathfinding) et **opaque** (ligne de vue) ; une
porte `ouverte` est traversable et transparente.

- **EtatGroupe.carte** gagne `portes: [{x, y, etat, verrou?}]` — les portes
  **secrètes non révélées n'y figurent jamais** (même règle que les pièges cachés)
  et leur case reste un mur ; une porte connue est rendue comme une porte (`p`),
  une `verrouillee` porte un cadenas (`verrou` = type du verrou).
- **Fouiller la zone** (option `fouiller`, type `jet`, Mind difficulté 1) : un seul
  jet réussi révèle dans le rayon de fouille les **pièges cachés** ET les **portes
  secrètes** (qui s'ouvrent). Echo : `pieges_reveles`, `portes_revelees`.
- **Verrous** (doc 14 §3.3) :
  - `cle` : option `ouvrir_porte` (id `ouvrir_porte_{x}_{y}`) au contact d'une porte
    verrouillée, offerte si le héros possède l'objet-clé → la porte s'ouvre (persistant) ;
  - `monstres_vaincus` : ouverture **automatique** quand les instances désignées sont
    vaincues (hook post-combat) — aucune action joueur ;
  - `levier` : option `actionner_levier` (id `actionner_levier_{x}_{y}`) au contact d'un
    levier (`cartes.grille.leviers`) → ouvre la/les porte(s) liée(s) par `verrou.levier_id`.
- **Fouiller — trésor** (option `fouiller_tresor`, type `fouille_tresor`) : action
  SÉPARÉE, offerte dans une **salle « vide »** (rencontres nettoyées) **non encore
  fouillée**. Tirage pondéré (gabarit `structure.tresor_a_risque`) → `issue` ∈
  `tresor` (or au groupe) / `rien` / `errant` (monstre du bestiaire instancié au
  contact, décompté d'un **budget errant dédié** `structure.budget_errant`, qui joue
  au tour des monstres) / `piege` (effet du « Piège de coffre » appliqué **tout de
  suite** au fouilleur, jamais posé sur la grille). Le monstre errant ne survient
  **que** par cette action (jamais par « Fouiller la zone »).

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

## Clôture de campagne (doc 05 §6)

Fenêtre de clôture (cache, comme le marché) ouverte : automatiquement à la
**victoire du boss final** (broadcast `.cloture.ouverte`), ou par un membre au
**hub**. À l'ouverture manuelle, l'`issue` est **dérivée de l'état de la
campagne** (jamais du seul corps de requête, pour qu'une fin gagnée/perdue ne
soit jamais mal étiquetée) : `victoire` si le boss final est vaincu, `echec` si
la dernière quête est **échouée** (TPK doc 05 §6 : l'or à partager est alors
`quetes.or_initial` de la quête échouée, plafonné à l'or restant), sinon
`abandon` (fin décidée saine, pot complet). Le drapeau `abandon: true` reste
réservé à une campagne réellement échouée (422 sinon). 422 si une quête est en cours.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/cloture | {abandon?: bool} | ouvre la fenêtre |
| GET | /groupes/{identifiant}/cloture | — | EtatCloture |
| PUT | /groupes/{identifiant}/cloture/repartition | {inventaire_id, personnage_id} | réassigne un équipement (annule les confirmations) |
| POST | /groupes/{identifiant}/cloture/confirmation | — | confirme ; tous confirmés → finalisation |
| DELETE | /groupes/{identifiant}/cloture | — | annule la fenêtre (rien appliqué) |

**EtatCloture** : `{issue: "victoire|echec|abandon", or_a_partager,
parts: [{personnage_id, nom, joueur_id, montant}] (parts égales, reste réparti
unité par unité aux premiers), equipements: [{inventaire_id, nom, categorie,
rarete, personnage_id}], confirmations: [{joueur_id, pseudo, confirme}]}`.

**Finalisation** (job, atomique côté données) :
1. réassignations d'équipement appliquées ; 2. or commun réparti vers
`personnages.or` ; 3. **résumé de campagne généré AVANT la purge** (skill MJ
`ResumeCampagne` depuis le journal ; repli sans LLM : résumé factuel — quêtes,
boss, or, issue) ; 4. une ligne `personnage_historique` par héros (groupe_nom,
theme, resume, issue, niveau_atteint, termine_le) ; 5. détachement
(`groupe_actif_id` null), **personnages remis à plein** (pv_body/pv_mind au
max, sorts tous `disponible`, conditions/buffs effacés — victoire, échec ou
abandon referment l'ardoise) puis **purge complète** : quetes, cartes,
instances_monstres, etat_personnage_quete, evenements, snapshots, caches de
phase, le groupe lui-même, et les points **Qdrant** du group_id (best-effort
si Qdrant est joignable). Broadcast final `.cloture.terminee`
({resumes: [{personnage_id, resume}]}) — les clients retournent à l'accueil.

**Groupe vide** (doc 05 §6) : quand le dernier joueur quitte (départ libre ou
retrait voté), même purge automatique, sans cérémonie ni résumé.

Broadcasts canal `groupe.{identifiant}` : `.cloture.ouverte` (EtatCloture),
`.cloture.maj` (EtatCloture), `.cloture.terminee`.

## Snapshots & reprise (doc 12 §4, doc 05 §6 TPK)

Le moteur **snapshotte automatiquement** l'état vivant dans la table
`snapshots` (`groupe_id, sequence_evenement, etat JSON`) : au **démarrage de
chaque quête** (étiquette `debut_quete`) et à chaque **nouveau tour** (étiquette
`nouveau_tour`, après la phase des monstres). L'état sérialisé contient tout ce
qu'il faut pour rejouer : groupe (or, phase, quete_courante_id), quête, carte
(grille + état des pièges), instances de monstres (PV, positions, états,
conditions), etat_personnage_quete, et pour chaque héros actif : PV, sorts
(disponible), conditions, inventaire (lignes + quantités). Rétention : les
snapshots d'une quête sont **purgés à la fin de la quête** (seul celui de
`debut_quete` de la quête courante et le dernier `nouveau_tour` sont
conservés pendant la quête — départ playtest).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| GET | /groupes/{identifiant}/snapshots | — | liste : [{id, etiquette, sequence_evenement, created_at}] |
| POST | /groupes/{identifiant}/reprise | {snapshot_id?} | restaure l'état (défaut : snapshot `debut_quete` de la dernière quête échouée — le « recharger » après TPK) — **membre OU table** (le bouton « Recharger la quête » est sur l'écran de table, même règle que la clôture) |

**Reprise** : 422 si une quête est en cours ET non échouée (on ne recharge pas
en pleine partie réussie) ; restauration atomique en transaction : l'état
vivant est réécrit depuis le snapshot, la quête repasse `en_cours`, le journal
reçoit un événement `systeme` `{action: "reprise", snapshot_id}` (le journal
n'est JAMAIS tronqué — source de vérité, doc 07), broadcast `.groupe.etat` +
re-dispatch narration/menus. Le TPK (doc 03/05) devient donc : quête `echouee`
→ le groupe **vote ou choisit** : `POST reprise` (recharger) ou
`POST cloture {abandon: true}` (abandonner).

## Sorts de Dread & capacités des boss (doc 09 §4 — tout dans le tour scripté)

Aucun nouvel endpoint : le **comportement scripté (C2)** des sous-boss/boss
s'enrichit. Priorité d'un lanceur à son tour : sort de Dread s'il reste des
**usages** (cache par instance et par rencontre — départ playtest : sous-boss
2, boss 3) et qu'une cible vaut le coup, sinon capacité, sinon
déplacement+attaque normal. Le moteur décide et résout ; l'IA ne fait que
narrer (les payloads de narration portent le détail).

**Sorts de Dread** (résolution identique aux sorts héros — le **jet de Mind du
héros** utilise son `attribut_mind`, S2 binaire) :
Trait de Chaos (2 dés à distance, défense applicable) ; Frayeur (résiste sinon
condition `frayeur` : −1 dé d'attaque 2 tours) ; Sommeil (résiste sinon
`endormi` : ne joue pas, une attaque subie le réveille) ; Tempête de feu (zone :
la case ciblée + adjacentes orthogonales, 2 dés chacun, défense applicable —
peut toucher plusieurs héros) ; Invocation de morts-vivants (2 squelettes sur
cases libres adjacentes au lanceur, 1×/rencontre) ; Commandement (résiste sinon
`commande` : à son prochain tour le héros est joué par le moteur — il attaque
l'allié adjacent sinon avance vers le plus proche allié) ; Fuite (le lanceur se
téléporte sur la case libre la plus éloignée des héros).

**Capacités** (`monstres.capacites` JSON) : Invocation (comme le sort, sbires
de base) ; Frappe de zone (l'attaque touche TOUS les héros adjacents, un jet
par cible) ; Régénération (+1 PV Body au début de son tour, plafonné) ;
Résistance magique (+2 dés de défense contre les sorts de dégâts des héros) ;
Charge (si hors contact et joignable : déplacement + attaque à +1 dé).

**EtatGroupe** : `entites` (héros ET monstres) gagnent
`conditions: [{nom, duree}]` — la table et la manette affichent les états ;
un héros `endormi`/`commande` voit son menu remplacé par un message d'état.

## Modèle de session : Narrateur (table) vs Joueur (compte)

Deux rôles d'entrée distincts (doc 11 §7).

### Narrateur / table — sans compte, par code
La table « tient » la partie en ligne. Pas de compte : on saisit le **code du
groupe**.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /api/table | {code} | ouvre une SESSION DE TABLE (cookie) pour ce groupe ; 404 si code inconnu. Réponse : {groupe: EtatGroupe} |
| POST | /api/table/ping | — | heartbeat : rafraîchit « table active » (cache `table:active:{groupe_id}`, TTL 30 s). À envoyer toutes les ~15 s |
| POST | /api/table/quitter | — | ferme la session de table |

**Narrateur actif** = `Cache::has('table:active:{groupe_id}')` (heartbeat frais).
C'est la condition pour qu'une partie soit jouable/reprenable.

### Joueur — compte + roster
| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /api/inscription | {pseudo, identifiant} | crée le compte et connecte ; 422 si identifiant pris (sans mot de passe) |
| POST | /api/connexion | {identifiant} | (existant) — nom seul |
| GET | /api/moi | — | {joueur, personnages: [...]} — chaque perso : `disponible` (pas de groupe), et si engagé `groupe: {identifiant, nom, phase, narrateur_actif}` ; `attribut_body/attribut_mind/des_attaque/des_defense` (fiche perso, invariants hors quête) ; `equipement: {armes: [nom…], armure: nom\|null, sac: [{inventaire_id, nom, categorie, rarete, quantite}]}` |
| POST | /api/personnages | {nom, classe, elements?} | crée un perso du roster (libre) |
| POST | /api/groupes | {nom, theme, longueur, ton?, personnage_id} | crée un groupe DEPUIS un perso LIBRE du joueur (le perso le rejoint comme fondateur) ; 422 si perso déjà engagé |
| POST | /api/groupes/{identifiant}/joueurs | {personnage_id} | rejoint par code avec un perso libre (existant, + accepte {nom,classe}) |

Le `personnages[].groupe.narrateur_actif` (bool) pilote le bouton « Reprendre »
côté joueur : on ne peut reprendre que si une table est active sur le groupe.

### Statut « prêt » et démarrage de quête (au hub)
Une nouvelle quête démarre quand **TOUS les joueurs membres sont prêts** ET
qu'un **narrateur est actif** (remplace le démarrage manuel par la table).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /api/groupes/{identifiant}/pret | {personnage_id, pret} | (dé)marque un perso prêt (cache `partie:pret:{groupe_id}`) ; si tous les membres actifs sont prêts ET narrateur actif → **démarre la quête** (DemarreurQuete) et réinitialise les statuts |

`EtatGroupe.groupe` gagne `narrateur_actif` (bool) et, au hub, `prets:
[{personnage_id, pret}]` pour l'affichage. Broadcast `.prets.maj` ({prets})
sur changement. (Le `POST /quetes` direct reste pour les tests/outillage.)

### Autorisations
Les routes de LECTURE/jeu d'un groupe (`/etat`, `/snapshots`, broadcasting
des canaux `groupe.{identifiant}`) acceptent **soit** un joueur membre (au moins
un perso actif), **soit** la session de table de ce groupe. Les actions de
joueur (choix, panier, vote, prêt…) exigent un joueur membre.

## Garanties

- **Le moteur fait autorité** : `choix` valide l'option contre le dernier menu
  proposé + l'état ; option illégale → 422.
- **L'API ne dépend jamais du LLM** : si le job IA échoue (pas de clé, erreur),
  repli (menu générique / narration neutre) — le jeu reste jouable.
- Toute mutation d'état passe par un événement journalisé (`evenements`) puis
  un broadcast `.groupe.etat`.
