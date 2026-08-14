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
