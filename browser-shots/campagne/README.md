# Harnais de test — campagne jouée par des agents

Chaque agent joue UN héros sur les **vraies routes de la manette** (mêmes
endpoints que le front Vue) : génération des menus par le MJ IA, résolution par
le moteur, initiative, réactions hors tour. Un pot de cookies par joueur.

## Mise en place
1. `curl -s -c jar-N.txt http://localhost/` (obtenir le cookie XSRF)
2. `POST /api/inscription {pseudo, identifiant}`
3. `POST /api/personnages {nom, classe, elements?|sorts_elfiques?}`
4. le fondateur : `POST /api/groupes {nom, theme, longueur, personnage_id}`
   les autres : `POST /api/groupes/{code}/joueurs {personnage_id}`
5. table : `POST /api/table {code}` **puis un battement toutes les ~12 s**
   (`POST /api/table/ping`) — voir le piège 1.
6. tous : `POST /api/groupes/{code}/pret {personnage_id, pret:true}`

## Outils donnés aux agents
- `vue.py <slot>` — situation du héros : PV, alliés, monstres visibles,
  conditions (avec leur SOURCE), **leviers visibles et portes verrouillées**,
  **terrain proche** (thème glace), destinations atteignables (en **points**,
  pas en cases — voir plus bas), menu **avec ses sous-choix dépliés**, et pour
  un sort ses dés/soin/durée.
- `hq.sh <slot> etat|menu|moi|pret|choix|reaction`
- **au hub** : `hq.sh <slot> marche|panier <json>|confirmer|equiper <inv>|donner <inv> <perso>`
- **vote** : `hq.sh <slot> votes|vote <option_id>`

⚠ **Une option peut PORTER une liste** depuis le 2026-09-01 (doc 13 §3.1, « 2
à 5 options claires ») : `lancer_sort`, `lire_parchemin` et `utiliser_objet`
remplacent une option par sort, par parchemin et par potion — un magicien de
niveau 1 avait quatorze options, dont neuf sorts. On répond alors **à plat** :
`choix lancer_sort '{"cle":"sort:12","cible_id":7,"cible_type":"monstre"}'`, la
`cle` étant celle de l'entrée dans `parametres.sorts[]`. **C'est elle la liste
blanche** : le serveur 422 tout ce qui n'y est pas. `vue.py` déplie les listes
et `pilote.py` sait y descendre depuis le **2026-09-02** — avant ça, tous deux
ne lisaient que `parametres.cibles`, donc voyaient un menu SANS AUCUN SORT et
faisaient jouer un lanceur muet, sans la moindre erreur.

⚠ **`POST /potions` n'existe plus** (2026-09-01) : on boit par `/choix`, via
`utiliser_objet`. Le verbe `hq.sh potion` a donc été retiré le 2026-09-02 — il
appelait une route disparue et rendait un 404 muet, exactement le « joueur
paralysé qui croit le serveur en panne » décrit plus bas.

⚠ Ces sept verbes ont manqué au harnais jusqu'au **2026-08-17**, et chacun a
coûté une session : sans `vote`, le barbare ne pouvait pas conclure la quête
qu'il venait de proposer de quitter ; sans `marche`, l'elfe est arrivée devant
la boutique sans rien pour l'ouvrir — et `menu` renvoie toujours `null` au hub,
cette route ne servant qu'en quête. Un agent ne dispose QUE de ce qu'on lui
donne : un verbe manquant ne produit pas une erreur, il produit un joueur
paralysé qui croit le serveur en panne.

⚠ **Leviers** (posés dans TOUTE quête depuis le 2026-09-06 — avant, aucun ne
l'avait jamais été) et **terrain de glace** (thème `horreur_des_glaces`
seulement) suivent la même logique, réparée le 2026-09-10 avant qu'une session
ne la paye :

- `vue.py` liste désormais, inconditionnellement, les **leviers visibles**
  (`levier_id`, difficulté du jet de Body, distance) et les **portes
  verrouillées** (type de verrou, distance). ⚠ **L'API ne publie NULLE PART
  quel `levier_id` ouvre quelle porte** — `EtatGroupe::portes()` ne renvoie que
  le TYPE du verrou (`verrou: "levier"`), jamais son `levier_id` ;
  `ResolveurTour::resoudreActionnerLevier()` retrouve l'appariement lui-même,
  côté serveur seulement. Un agent (comme un joueur humain) ne peut donc pas
  savoir À L'AVANCE lequel des leviers visibles ouvre telle porte : `vue.py`
  liste les candidats, `pilote.py` en vise un au hasard — retentable sans
  limite, jamais pire qu'un essai perdu.
- `actionner_levier` **n'a pas de verbe dédié** dans `hq.sh` et n'en a pas
  besoin : comme `ouvrir_porte`, c'est une option PAR levier adjacent (id
  `actionner_levier_{x}_{y}`, **uniquement au contact**) dont les `parametres`
  sont déjà fixés côté serveur dans le dernier menu envoyé — le résolveur ne
  lit jamais ceux que le client soumettrait. `hq.sh <slot> choix
  actionner_levier_X_Y` **sans troisième argument** suffit. ⚠ Ce n'est PAS une
  option à liste façon `lancer_sort` (elle n'a pas de sous-choix à plat) —
  une supposition initiale à corriger si elle revient.
- `pilote.py` vise maintenant le levier : porte close ouvrable en priorité,
  puis — seulement si une porte verrouillée par levier reste sans autre porte
  connue — le levier visible le plus proche. Il faut plusieurs tours pour
  l'atteindre (l'option n'apparaît qu'au contact), c'est attendu.
- **Le déplacement se compte en POINTS, pas en cases**, depuis la Rivière
  gelée (coût 2 pour ENTRER dans une case de rivière). `vue.py` calcule
  désormais ses destinations par un Dijkstra pondéré, MIROIR de
  `Grille::casesAtteignables()` côté serveur et de `DeplacementSheet.vue` côté
  manette, au lieu d'une BFS à coût uniforme qui surbrillançait des cases que
  le serveur refusait ensuite. `pilote.py` ne recalcule rien lui-même : il se
  fie aux destinations que `vue.py` propose.
- Un **Tunnel de glace** téléporte : la case d'arrivée (`rep.vers`) peut
  différer de la case demandée — `pilote.py` l'annonce (`[TUNNEL DE GLACE]`)
  au lieu d'y voir une anomalie. Une **Glace glissante**/**Glissière de
  glace** peut finir le tour immédiatement sur un bouclier blanc
  (`rep.terrain.fin_tour`) — c'est la règle, annoncée de même, pas une erreur
  silencieuse.

## ⚠ Consigne OBLIGATOIRE à donner aux agents
> Tu n'as que `vue.py`, `hq.sh` et `sleep`. **N'utilise AUCUN outil de
> surveillance ni de sous-agent** : en démarrer un t'ARRÊTE au lieu de te faire
> jouer. Pour attendre ton tour : `sleep 20` puis relance `vue.py`.

Sans cette phrase, deux agents sur quatre se sont mis en attente d'un moniteur
et ont dû être relancés à la main (2026-08-13).

## Pièges déjà payés
1. **Le heartbeat de la table expire en 30 s**, en silence. Sans lui,
   `narrateur_actif` retombe à false et la quête ne démarre pas — sans qu'aucun
   message ne le dise.
2. `POST /pret` exige `personnage_id` ET `pret` ; sans le premier il répond 200
   sans rien marquer.
3. **Il n'existe AUCUN champ `revele` sur un monstre** dans `/etat` : l'API
   n'envoie que les monstres déjà révélés. Filtrer dessus rend l'agent aveugle
   (coûté plusieurs tours à trois joueurs).
4. Le **mobilier** bloque le mouvement : un BFS qui ne lit que les cases `s`/`p`
   propose des destinations que le serveur refuse.
5. Les sessions expirent : prévoir `POST /api/connexion {identifiant}` pour
   reprendre la main sur une partie longue.

## Ce qu'il faut PRÉPARER pour éprouver un rôle

Deux campagnes ont montré qu'un rôle ne se teste pas en espérant qu'il se
présente. À monter explicitement avant de lancer les agents :

- **Nain / pièges** — l'**Œil du mineur est un NŒUD D'ARBRE**, pas une capacité
  innée : un nain de niveau 1 ne l'a PAS et marche dans les fosses. Le lui
  accorder, puis poser une fosse **à deux cases** (à une case, il marche dessus
  avant de la détecter). Chaîne validée le 2026-08-14 : arrêt du déplacement à
  une case, `pieges_reveles`, options `desamorcer_X_Y` ET `franchir_X_Y`,
  désamorçage réussi puis piège `desarme`.
- **Chevalier / réactions** — il faut qu'un monstre ATTAQUE : placer une
  créature au contact avant la phase des monstres, sinon *Parade au bouclier* et
  *Inébranlable* ne se proposent jamais. Pour *Défi du chevalier*, empiler une
  carte `errant` sur le deck de fouille de sa salle — mais **ce n'est PAS le
  chevalier qui doit fouiller** : `proposerDefi()` exclut le fouilleur, et à
  raison, puisque l'errant surgit déjà à son contact. Faire fouiller un VOISIN.
  Les trois capacités validées le 2026-08-14 : l'errant sauté de (17,29) à
  (19,28) puis attaque immédiate ; 2 dégâts sur le voisin rendus (le chevalier
  n'encaisse rien à sa place) ; PV 0 → 1 avec relevé du héros.
- **Rogue / flanquement** — la *Frappe opportuniste* exige un ALLIÉ au contact
  de la cible : donner la consigne de rester groupés, ou placer les figures.
- **Warlock / fosses** — c'est *Forme démoniaque* qui donne
  `ignore_pieges_fosse`, pas *Ailes sombres*. Le préciser dans la consigne.

Les agents ont des durées de vie différentes : quand l'un s'arrête, son héros ne
joue plus et le tour du groupe se fige sur lui, sans que les autres puissent le
savoir. Prévoir de le relancer, ou faire jouer plusieurs héros au même agent.

## Le vote de sortie : deux gestes, pas un

**Proposer n'est pas voter.** « Quitter le donjon » ouvre un `VoteGroupe` dont
les bulletins partent VIDES — le proposeur doit déposer le sien comme tout le
monde. Le vote tient **6 heures** et ne s'auto-résout pas : il n'a ni timeout
court ni voix par défaut, contrairement au verrou « MJ réfléchit » (30 s) ou aux
offres de réaction (`rattraperExpiration`). C'est voulu pour une vraie table,
mais ça pardonne mal en test.

⚠ `hq.sh` n'a eu de verbe de vote qu'à partir du **2026-08-15**, et son absence a
coûté une campagne entière : le barbare avait lancé le vote et ne pouvait
matériellement pas le conclure, le groupe a tourné vingt minutes dans un donjon
vide. Les deux verbes :

```bash
./hq.sh 1 votes        # question, options, décompte (exprimés / attendus)
./hq.sh 1 vote oui     # déposer son bulletin
```

⚠ Le vote n'apparaît **ni dans `/etat` ni dans `/moi`** : il a sa propre route
(`GET /groupes/{id}/votes`). La manette réelle la rattrape au montage, donc un
joueur qui recharge son téléphone voit bien la feuille de vote — mais un agent
qui ne connaît que `etat` et `menu` ne saura jamais qu'on l'attend.

## Les noms de colonnes qui font perdre une heure

En montant une scène à la main (tinker), quatre attributs n'existent PAS et
reviennent `null` sans la moindre erreur — on croit alors avoir trouvé un bug :

| on écrit | la colonne réelle est | ce qu'on croit à tort |
|---|---|---|
| `$inventaire->equipe` | `inventaire.emplacement` (`sac` ou le nom du slot) | « rien n'est équipé » |
| `$quete->fouilles_effectuees` | `quetes.tresors_fouilles` (`"{salle}:{perso}"`) | « personne n'a fouillé » |
| `$personnage->classeHeros` | `classe()` / `classe_id` | « le héros n'a pas de classe » |
| `$quete->salles_explorees` | `quetes.salles_decouvertes` (liste d'index) | « le groupe n'explore rien » |

Et deux préconditions muettes bloquent `fouiller_tresor` sans rien dire : le
héros doit être DANS les bornes d'une salle (un couloir n'offre pas l'option) et
la salle ne doit contenir aucun monstre actif **révélé**.

---

## ⚠ Tout ce qu'un agent fabrique doit appartenir au GROUPE DE HARNAIS

`nettoyer.sh` est **ciblé** : il ne reprend que le groupe de `groupe.txt` et les
comptes de ses héros. Il ne sait donc rien d'un personnage créé **de côté, sur
un compte existant** — et un tel personnage survit à tous les ménages.

Mesuré le 2026-09-18 : pour illustrer le bouton « Supprimer (créé par erreur) »
sur la capture `10-roster` du livret, un agent a créé un héros *Test* sur le
roster d'un **vrai joueur**. `nettoyer.sh` a purgé sa campagne et rapporté un
état « propre » — le héros, lui, est resté sur le compte de René, qui l'a
découvert en relisant le compte de personnages. L'agent a même annoncé ce total
gonflé comme son état de départ, donc comme une référence.

**La règle pour les briefs** : un agent ne crée JAMAIS de personnage, de compte
ni de groupe hors du harnais monté par `preparer.sh`. Si une capture demande un
héros dans un état particulier, il se pose sur un héros **du harnais** — c'est
précisément ce que fait `livret-scenes.php` en posant des PV à la main plutôt
qu'en fabriquant une créature.

⚠ Et le contrôle qui l'aurait vu : **compter les personnages AVANT de commencer**,
puis après `nettoyer.sh`. Un écart est un résidu, quel que soit ce que le script
rapporte — il ne peut affirmer que ce qu'il a lui-même créé.
