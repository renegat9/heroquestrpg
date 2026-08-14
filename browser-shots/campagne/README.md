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
  carte `errant` sur le deck de fouille de sa salle.
- **Rogue / flanquement** — la *Frappe opportuniste* exige un ALLIÉ au contact
  de la cible : donner la consigne de rester groupés, ou placer les figures.
- **Warlock / fosses** — c'est *Forme démoniaque* qui donne
  `ignore_pieges_fosse`, pas *Ailes sombres*. Le préciser dans la consigne.

Les agents ont des durées de vie différentes : quand l'un s'arrête, son héros ne
joue plus et le tour du groupe se fige sur lui, sans que les autres puissent le
savoir. Prévoir de le relancer, ou faire jouer plusieurs héros au même agent.
