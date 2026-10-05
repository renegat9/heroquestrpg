# Plan — Un escalier d'entrée pour chaque quête, et l'extraction qui y ramène le captif

> ⚠ **Document DATÉ du 2026-10-05**, comme les autres `docs/plan-*.md`. Les
> règles en vigueur iront dans `docs/regles/` (`carte-donjon.md`,
> `exploration-et-fouille.md`).

## Demande de René (2026-10-05)

« Il faudrait ajouter un escalier ou une porte d'entrée pour chaque quête et ça
clarifierait la réussite de la mission d'extraction où l'allié temporaire doit
être retourné à l'entrée pour finir. »

## Constat

- Aucune entrée n'existe sur la carte : les jalons `entree` des gabarits
  (`GabaritQueteSeeder`) sont narratifs ; les héros apparaissent sur
  `spawn_heros` (salle 0), et rien n'y reste.
- `quitter_donjon` (`MenuMoteur`, vote `VoteGroupe::TYPE_SORTIE`) s'offre
  **n'importe où** dès que l'objectif est accompli ou le donjon vidé.
- La mission « secourir » est accomplie **dès la libération** du captif
  (`Quete::objectifAccompli()`, `captifLibereEtVivant()`) — « la sortie suit
  le vote ordinaire » (`docs/regles/exploration-et-fouille.md`). Le livret dit
  pourtant **escorter** Gothar (Frozen Horror p. 19).

## Décisions

1. **Un escalier en colimaçon dans la salle de départ de CHAQUE quête** — le
   repère du plateau d'origine (la quête commence et finit à l'escalier). Une
   nouvelle couche de carte (`cartes.grille['escalier']`), posée par
   `AssembleurCarte` dans la salle 0, sur les cases de `spawn_heros` ou à
   côté. **2×2 comme sur le plateau**, mais **traversable** : on s'y tient,
   donc il ne retire aucune case libre à la salle (règle « connecté n'est pas
   jouable » tenue par construction). Toujours visible (salle 0 découverte).
2. **On ne quitte le donjon QUE par l'escalier** : `quitter_donjon` n'est
   proposé qu'à un héros **sur une case de l'escalier** (en plus des conditions
   actuelles : objectif accompli ou donjon vidé, pas de vote ouvert). Le vote
   reste celui d'aujourd'hui ; le groupe sort ensemble quand il passe.
   Le serveur publie la décision (option présente ou non), le client ne
   recalcule rien.
3. **Battre en retraite reste SANS condition** (René, 2026-08-21) : décrocher
   doit rester possible au pire moment, loin de l'escalier.
4. **Mission « secourir » = extraction** : l'objectif est accompli quand le
   captif **libéré et vivant se tient sur l'escalier** (un seul point de
   passage : `Quete::objectifAccompli()`). S'il meurt avant, la quête échoue
   (inchangé).
5. **Cartes déjà assemblées sans escalier** (campagnes EN COURS) : comportement
   d'aujourd'hui (sortie possible partout, captif accompli à la libération),
   par un repli écrit et testé — jamais une quête en cours rendue
   impossible à terminer.
6. Rendu : l'escalier se voit sur l'écran de table ET sur la manette (symbole
   et légende de carte), et la bannière d'objectif de la mission de sauvetage
   dit « ramener <captif> à l'escalier ». Jouable sans clé API.
