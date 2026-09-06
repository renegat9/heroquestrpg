---
name: front-manette-et-table
description: >-
  Utiliser pour tout travail sur le FRONT Vue : la manette (téléphone du joueur)
  ou l'écran de table (narrateur), et le payload serveur qui les alimente.
  Couvre la répartition des rôles entre les deux écrans, les feuilles de choix,
  le rendu des options de menu, `EtatGroupe`, le contrat d'API et le temps réel.
  Déclencheurs : « la manette », « l'écran de table », « le narrateur »,
  « ça ne s'affiche pas », « le bouton est grisé/absent », « ajoute un onglet /
  une feuille », « EtatGroupe », « le payload », « Reverb / websocket ».
---

# Front — manette et écran de table

## Les deux écrans n'ont pas le même métier

- **La manette est pour AGIR.** Espace rare : une seule bande d'en-tête, et elle
  porte l'**objectif de quête** — ce sur quoi le joueur pilote chaque coup.
- **L'écran de table est pour le RÉCIT et la vue d'ensemble.** ⚠ **Le texte du MJ
  vit sur l'écran du narrateur SEUL** (arbitrage confirmé le 2026-09-05).
  Conséquence assumée : une partie jouée **sans écran de table** n'affiche aucune
  narration sur les téléphones. **Ne pas « corriger » ça en remettant une bande
  de récit sur la manette.**

`resources/js/components/manette/` · `table/` · `carte/` · `ui/` ·
`resources/js/views/` · `resources/js/store/game.js`

## Règles de rendu

- **`Vignette`** rend image-ou-icône (repli Material Symbols via `MSym`). Toute
  nouvelle entité affichée passe par elle, et son icône de repli se déclare dans
  `store/game.js`.
- ⚠ **Le vocabulaire d'affichage vit CÔTÉ SERVEUR** (`MotsClesTalent::$libelle`,
  `avantage` dérivé de `effet`). `store/game.js` a tenu sa propre table keyée sur
  des noms de colonnes : aucun talent n'affichait le moindre chiffre, et
  personne ne l'avait vu.
- ⚠ **Collisions CSS** : beaucoup de blocs `<style>` de SFC sont **globaux** (pas
  `scoped`) — une classe générique comme `.joueur` fuit d'une vue à l'autre.
  Préfixer les modificateurs propres à une vue.
- ⚠ **Taille des cellules et taille des glyphes vont ensemble** : `--dg-cell` a
  un compagnon `--dg-icone` posé par le parent. Un glyphe dimensionné en `%` se
  résout sur la *font-size héritée*, en `vw` sur la *largeur d'écran* — dans les
  deux cas il ne suit pas la cellule.
- ⚠ **`overflow: auto` masque un débordement** tant que le scroller est enfant
  direct : un conteneur de scroll a une taille minimale nulle, un `div` non.
  `grid-template-columns: minmax(0, 1fr)` à la racine.

## Le menu — la règle qui revient le plus

> **Le menu ne propose jamais ce que le résolveur refusera.**

- Une option peut **PORTER une liste** (`lancer_sort`, `lire_parchemin`,
  `utiliser_objet`, `attaquer` avec `parametres.cibles`). La réponse est **plate**
  (`{cle, cible_id?, cible_type?}`), et **cette liste EST la liste blanche** que
  le résolveur re-valide — sans quoi un client jouerait hors ligne de vue.
- **La profondeur suit la donnée** : le 3ᵉ niveau ne s'ouvre que si l'entrée
  choisie porte des `cibles`.
- Une entrée épuisée **reste affichée**, `disponible: false`, **grisée** — la
  cacher fait croire au joueur qu'il a perdu le sort.
- ⚠ **`ActionTab.creneauConsomme()` reflète `ResolveurTour::creneauOption()`** :
  garder les deux en phase, et rester tolérant à l'absence des drapeaux
  `a_joue` / `a_deplace` / `a_agi`.
- La manette est une **pile de feuilles** (`feuilles`) : chaque retour **nomme sa
  destination**, un tap sur le fond dépile un niveau, un menu neuf vide la pile.

## Contrat et temps réel

- ⚠ **`docs/contrat-api.md` d'abord** : c'est la source de vérité du payload,
  on la change **avant** le code.
- `EtatGroupe` est le payload de la table ; `/moi` celui du joueur. Une décision
  prise côté serveur s'y **publie** plutôt que de se re-dériver côté client
  (`slots_utiles`, `remplace`, `objectif_accompli`, `jetons_rejeton`).
- Canaux : `groupe.{id}` (table) et `joueur.{id}` (privé). Une offre en attente
  se publie **aussi** dans `EtatGroupe` : une manette rechargée perdrait sinon
  l'offre, et le joueur son pouvoir, sans rien à l'écran qui le dise.
- ⚠ **Un verrou serveur qui expire n'atteint aucun client** : `.groupe.etat` ne
  part qu'à une mutation, et personne ne mute pendant que tout le monde se croit
  gelé. Tout verrou a un **dégel client** ET une **relecture de `/etat`**.

## Lore
`docs/regles/front-manette-et-table.md` · `combat-et-tour.md`

## Definition of done
- [ ] `docs/contrat-api.md` à jour **avant** le payload
- [ ] Décision publiée par le serveur, pas re-dérivée côté client
- [ ] Option grisée plutôt que cachée quand elle est indisponible
- [ ] Classes CSS préfixées (les styles sont globaux)
- [ ] Vérifié en vrai : `npm run build` puis capture Playwright à 360 px **et** en table
