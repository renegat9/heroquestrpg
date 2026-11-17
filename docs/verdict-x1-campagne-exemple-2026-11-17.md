# Verdict — lot X1 : la campagne d'exemple jouée par des agents (2026-11-16 → 17)

Document DATÉ (comme les autres `docs/verdict-*.md`) : il n'est pas mis à jour ensuite. Les règles qui en sortent
vivent dans `docs/regles/` et dans le contrat.

## La partie

- Campagne : `docs/exemples/campagne-le-guet-perdu.json`, ORIGINALE (plateau, quêtes, textes inventés), validée par le
  serveur sans refus et tenue par `CampagneExempleTest`.
- Groupe : Albrecht (barbare) et Brunehaut (naine), chacun joué par un agent Haiku (effort xhigh) sur les vraies routes
  de la manette (`hq.sh`, `vue.py`), table battante. Environ 125 tours d'agents à eux deux, ~42 minutes.
- **Résultat : campagne finie, deux quêtes gagnées, aucun héros tombé.** Tout ce que l'exemple met en jeu a joué en vrai :
  porte au verrou `note` ouverte au 2e tour du MJ ; fouille de trésor qui pose un drapeau de CAMPAGNE ; apparition à
  l'entrée d'une salle ; quête 2 démarrée HORS DU PLATEAU, entrée par la porte ; note `si_drapeau` lisant le drapeau de
  la quête 1 (+20 or) ; clé trouvée par une note, porte à clé ouverte avec elle ; bloc tombé au 6e tour du MJ ; fin
  `sortir` par la porte de sortie, héros un par un ; marché fermé avant la quête 2 ; salle qu'on ne fouille pas.

## Défauts relevés

| # | Relevé | Verdict | Suite |
|---|---|---|---|
| 1 | **Contact de mêlée À TRAVERS UN MUR en arête** : la momie en (17, 12) et Albrecht en (16, 12) se sont frappés, un mur `{16, 12, e}` entre eux. | **Défaut réel, grave** (cartes construites seulement : une carte générée a ses murs en CASES). `Grille::sontAdjacentes()`, point de passage de tout contact, est géométrique : il ne lit pas `areteBloquee()`, que le pas, la vue et les rayons lisent. | Lot suivant, à part : 73 appels, des sens différents (mêlée, aura, réactions, pièges). |
| 2 | `sortir_donjon` offert après une fouille de trésor, puis refusé « Tu as déjà agi ce tour ». | **Défaut réel** (le menu offrait ce que le résolveur refuse). | **Corrigé** : non offert quand l'action est prise (le même créneau que la fausse porte, indiscernable). Test `FinSortirTest`. |
| 3 | Une porte OUVERTE au verrou `note` gardait son verrou au survol de la table (« Porte ouverte — ne s'ouvre pas d'ici »). | Défaut d'écran. | **Corrigé** dans `DungeonGrid.vue` : le verrou n'est dit que d'une porte close (le type reste publié, la grille de fer ouverte s'en sert). |
| 4 | « La porte à clé ne s'ouvre que depuis sa case » ; « `embrasure: null` pour toutes les portes ». | **Pas un défaut** : une porte sur une ARÊTE se touche depuis l'une de ses deux cases (`docs/regles/carte-donjon.md`, murs en arêtes). Le harnais et le contrat ne le disaient pas. | **Corrigé côté harnais et contrat** : `vue.py` écrit « porte sur une arête : se tenir en… », README, forme `carte.portes[]` du contrat. |
| 5 | Objectif de la quête 2 « Ouvrir le reliquaire… » : pas de reliquaire sur la carte ; « l'autel » absent. | **Défaut de l'EXEMPLE** (mon texte) : le reliquaire est une SALLE, la chapelle n'a pas d'autel. | **Corrigé** : textes réécrits. |
| 6 | `vue.py` plante quand le héros n'a pas de case (hors plateau, sorti). | Défaut d'outillage. | **Corrigé**. |
| 7 | `attaquer {"cible": …}` → « Cible invalide… » ; une potion demande `cible_id`. | Clé inventée par l'agent ; le harnais ne documentait la forme qu'à `lancer_sort`. | README complété. Le message du serveur reste celui d'une cible absente : à reprendre si un humain s'y trompe. |
| 8 | « Fouille la zone : 1 piège et 1 passage », passage introuvable dans `carte.portes`. | **Non reproduit** : la fouille depuis le couloir est couverte (`PassageSecretEnAreteTest`). | À surveiller. |
| 9 | Menu de tour servi hors tour à la naine après sa fouille. | **Non reproduit** par « finir son tour, l'autre joue ». | Garde-fou `MenuHorsTourTest` ajouté. |
| 10 | « Fracasser » un coffre vide reste offert. | Voulu : fracasser dégage la case, butin ou pas. | — |
| 11 | Fouiller un trésor fait surgir un errant qui frappe. | Règle (carte « monstre errant » du paquet). | — |
| 12 | Après une fouille de zone (ou d'un meuble), le reste du déplacement disparaît ; après une fouille de trésor ou une attaque, il reste offert. | **Non vérifié** : peut-être la règle (`perd_deplacement` publié par option), peut-être une incohérence. | À vérifier contre `docs/regles/combat-et-tour.md`. |
