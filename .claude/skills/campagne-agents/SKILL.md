---
name: campagne-agents
description: >-
  Utiliser pour JOUER une campagne réelle avec des agents qui pilotent chacun un
  héros sur les vraies routes de la manette — la méthode de test qui trouve ce
  que la suite Pest ne trouve pas. Couvre la mise en place, les outils donnés aux
  agents, la consigne obligatoire et les pièges déjà payés. Déclencheurs :
  « joue une campagne », « teste en conditions réelles », « lance le harnais »,
  « fais jouer des agents », « browser-shots/campagne », « test à 4 joueurs ».
---

# Jouer une campagne avec des agents

**Playing a real campaign is a test method** (`browser-shots/campagne/`, README in the same folder). Sonnet agents each drive one hero over the **real** manette routes: `hq.sh <slot> etat|menu|moi|pret|choix|potion|reaction` (one cookie jar per player = one session), `vue.py` prints the board, `battement.sh` keeps the table heartbeat alive, `figurants.sh` passes the turns of heroes nobody is playing. Three campaigns found **ten** defects that 761 green tests did not: the menu served out of turn and to fallen heroes (above), Paralysé that forbade only spells, a zone spell asking for a target it ignores, a combat log conflating a miss with a parry, a Terreur spell that 422'd whenever its target *failed* the resistance roll, double journalling, a ghost spell in the live DB absent from the seeder, the guide dropping 15 spells, and the empty capacity description on the reaction sheet.

Two things it taught, both in the README. **A role does not test itself — prepare the situation.** The Knight's three reactions never fired across two whole campaigns because nothing ever arranged for a monster to strike a neighbour; they were finally validated by staging the board (and ⚠ `proposerDefi()` **excludes the searcher**, so a *neighbour* must draw the wandering-monster card). Same for the dwarf's traps — *Œil du mineur* is a purchasable tree node, not an innate, so a level-1 dwarf walks into pits, which is correct. **And an agent that stops freezes the group**: its hero never ends its turn, the round never closes, and nobody else can see why — give the agents no monitoring tools, and cover idle heroes with `figurants.sh`. The README also lists the three attributes that do **not** exist and return `null` in silence when you stage a scene by hand (`inventaire.equipe`, `quetes.fouilles_effectuees`, `Personnage::classeHeros`) — each one costs an hour of chasing a bug that isn't there.

## ⚠ Lire le README d'abord

**`browser-shots/campagne/README.md`** est la référence à jour : mise en place,
verbes de `hq.sh`, format des sous-choix, pièges. Il est maintenu avec le
harnais — cette skill l'amorce, elle ne le remplace pas et **ne le recopie pas**.

## Les trois choses à ne pas rater

1. **Le battement de la table expire en 30 s, en silence.** Sans
   `POST /api/table/ping` toutes les ~12 s, `narrateur_actif` retombe à `false`
   et la quête ne démarre pas — sans un message qui le dise.
   → `battement.sh`
2. **La consigne OBLIGATOIRE, mot pour mot, dans le prompt de chaque agent :**
   > Tu n'as que `vue.py`, `hq.sh` et `sleep`. **N'utilise AUCUN outil de
   > surveillance ni de sous-agent** : en démarrer un t'ARRÊTE au lieu de te
   > faire jouer. Pour attendre ton tour : `sleep 20` puis relance `vue.py`.

   Sans elle, deux agents sur quatre se sont mis en attente d'un moniteur et ont
   dû être relancés à la main (2026-08-13).
3. **Un agent qui s'arrête fige TOUT le groupe** : son héros ne finit jamais son
   tour, le round ne se ferme pas, et personne ne peut voir pourquoi. Couvrir les
   héros que personne ne joue avec `figurants.sh`.

## Outils
`hq.sh <slot> etat|menu|moi|pret|choix|reaction` · au hub
`marche|panier|confirmer|equiper|donner` · vote `votes|vote` ·
`vue.py <slot>` (situation + menu **avec ses sous-choix dépliés**) ·
`battement.sh` · `figurants.sh` · `preparer.sh` / `nettoyer.sh`.

⚠ **Un verbe manquant ne produit pas une erreur** : il produit un joueur paralysé
qui croit le serveur en panne. Sept verbes ont manqué jusqu'au 2026-08-17, chacun
a coûté une session.

## Préparer une situation — sinon elle n'arrive jamais
**Un rôle ne se teste pas tout seul.** Les trois réactions du Chevalier n'ont
jamais tiré sur deux campagnes entières parce que rien n'avait arrangé qu'un
monstre frappe un **voisin**. Il faut monter le plateau exprès.
⚠ `proposerDefi()` **exclut le fouilleur** : c'est un *voisin* qui doit tirer la
carte de monstre errant.

⚠ Trois attributs **n'existent pas** et rendent `null` en silence quand on monte
une scène à la main : `inventaire.equipe`, `quetes.fouilles_effectuees`,
`Personnage::classeHeros`. Chacun coûte une heure à chasser un bug qui n'existe pas.

## Après la partie
`./browser-shots/campagne/nettoyer.sh` (ciblé) — voir skill `outillage-dev-et-tests`.
Ce qui a été trouvé se consigne dans un `docs/verdict-*.md` **daté**, et les
corrections retenues remontent dans `docs/regles/` (règles en vigueur).

## Definition of done
- [ ] README relu (il change plus vite que cette skill)
- [ ] Battement de table actif pendant toute la partie
- [ ] Consigne « aucun outil de surveillance » donnée à chaque agent
- [ ] Héros non joués couverts par `figurants.sh`
- [ ] Défauts consignés, campagne purgée
