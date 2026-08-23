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
  conditions (avec leur SOURCE), destinations atteignables, menu, et pour un
  sort ses dés/soin/durée.
- `hq.sh <slot> etat|menu|moi|pret|choix|potion|reaction`
- **au hub** : `hq.sh <slot> marche|panier <json>|confirmer|equiper <inv>|donner <inv> <perso>`
- **vote** : `hq.sh <slot> votes|vote <option_id>`

⚠ Ces sept verbes ont manqué au harnais jusqu'au **2026-08-17**, et chacun a
coûté une session : sans `vote`, le barbare ne pouvait pas conclure la quête
qu'il venait de proposer de quitter ; sans `marche`, l'elfe est arrivée devant
la boutique sans rien pour l'ouvrir — et `menu` renvoie toujours `null` au hub,
cette route ne servant qu'en quête. Un agent ne dispose QUE de ce qu'on lui
donne : un verbe manquant ne produit pas une erreur, il produit un joueur
paralysé qui croit le serveur en panne.

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
