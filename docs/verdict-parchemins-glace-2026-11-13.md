# Verdict — les parchemins de glace joués en vrai (2026-11-13)

Document daté, non mis à jour ensuite : les règles en vigueur sont dans `docs/contrat-api.md` et `docs/regles/`.

## La partie

Lot 5 de `docs/plan-parchemins-glace.md`. Une campagne de harnais (`browser-shots/campagne/preparer.sh`, thème
`horreur_des_glaces`, bestiaire imposé, une magicienne et un barbare), jouée sur les **vraies routes** (`hq.sh`,
`vue.py`) avec une **scène préparée** (« un rôle ne se teste pas tout seul ») : les quatre parchemins de la boîte au
sac de la magicienne, trois monstres à son contact, une Fosse détectée, une plaque de Glace glissante de deux cases.

| Geste | Résultat |
|---|---|
| Menu | Morsure du Froid (une entrée), Tempête de Glace (une entrée par carré légal, nommée « carré en x,y : figures »), Pont de Glace (« Fosse en 53,29 », « Glace glissante en 54,27 (2 cases) »), Patinage |
| Pont de Glace sur la Fosse | `carte.terrain[]` publie la Glace magique `pont: true` avec sa phrase et son symbole de livret ; la fosse disparaît de `carte.pieges[]` ; fil : « Ysolde lit Pont de Glace : un pont de glace est posé — Fosse en 53,29 » |
| Le barbare s'arrête sur le pont | aucun piège déclenché, aucun jet |
| Morsure du Froid | les trois monstres orthogonaux perdent 1 PV sans défense et tombent |
| Tempête de Glace sur le carré du barbare et d'un orque | l'orque tombe (2 crânes), le barbare encaisse 1 PV (tir ami) ; fil : « Ysolde lance Tempête de Glace — 2 figures dans sa zone » |

Campagne nettoyée par `nettoyer.sh` ; la base réelle inchangée (3 groupes, 9 héros, 4 joueurs).

## Trouvé

- `vue.py` décrivait encore la Glace magique comme « décor — ancrage de sort du boss » : il lit maintenant
  `carte.terrain[].avantages`, la phrase décidée par le serveur, et ne garde sa table fixe qu'en repli.

## Livré avec le lot

Illustrations (et jumeaux webp) du Brassard de Glace, des parchemins Patinage, Morsure du Froid, Tempête de Glace,
Pont de Glace et des quatre sorts. Le guide n'a plus aucune carte grisée pour The Frozen Horror.

## Décisions prises en l'absence d'objection de René (2026-11-13)

Le Sol meuble (une salle entière) reste pontable d'un seul pont ; Piège de téléportation, Piège d'embrasement et Piège
de Boule de Flammes restent hors de la liste (jamais connus armés, ou jamais sur la carte) ; la Chambre forte de glace
reste hors du pont ; une figure, même à terre, sur un élément empêche de le couvrir.
