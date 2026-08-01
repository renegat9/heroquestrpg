# Verdict — test de jeu complet, 4 joueurs (agents Sonnet) via Playwright

> **État des correctifs (2026-07-28).** Les constats ci-dessous ont été traités —
> voir `docs/plan-correctifs-2026-07-28.md` pour le découpage. Suite Pest :
> **445 tests verts** (437 au départ, +8 dédiés à ces correctifs).
>
> | Constat | État |
> |---|---|
> | §2.1 écran de table à 100 % CPU | **corrigé** — mesuré à **7 %**, style+layout 59,2 s → **0,4 s**, page réactive en 10 ms |
> | §2.2 « Fermer » recouvert par le Hub | **corrigé** — le Hub disparaît dès qu'une feuille est ouverte ; « × » ramené dans le viewport |
> | §2.3 « aucune cible en vue » | **corrigé** — message explicite quand la ligne de vue est bouchée |
> | §2.3 bis immunité mentale | **corrigé** — drapeau `immunise` par cible, exposé par le menu |
> | §2.4 options refusées par le moteur | **corrigé** — les options IA ne sont plus injectées si le créneau d'action est consommé |
> | §2.5 narration vs résultat | **corrigé** — le payload du jet distingue « réussi » de « a trouvé quelque chose » |
> | §2.6 MJ qui voit les monstres non révélés | **corrigé** — filtre `revele` posé (0 monstre exposé pour 11 dormants), et les noms de ce qui vient d'apparaître sont transmis au narrateur |
> | §2.11 slug dans la fiction | **corrigé** — `groupe.identifiant` retiré du contexte IA |
> | §2.11 « TIR AMI » sur les soins | **corrigé** — l'avertissement ne s'affiche plus que pour les sorts offensifs |
> | §2.12 héros encerclé au tour 1 | **corrigé** — spawns espacés, cases de porte exclues, salle de départ = la plus grande |
> | §2.12 bis salle remplie à 100 % | **corrigé** — réserve de cases libres garantie dans chaque salle peuplée |
> | §2.14 messages techniques | **corrigé** — reformulés pour le joueur |
> | §2.15 session expirée sans reconnexion | **corrigé** — l'état client est purgé, l'overlay n'est plus refermable |
> | §2.16 exploration en cache | **corrigé** — persistée en base (`quetes.salles_decouvertes` / `tresors_fouilles`), snapshots inclus |
> | §2.17 héros à terre | **corrigé côté menu** — « Relever » n'est plus proposé si la case est occupée ; le menu ne bloque plus le déplacement à tort. **La règle de fond reste inchangée** (un corps ne bloque pas la case) : c'est une décision de conception explicitement testée, à trancher par René |
> | §2.20 en-tête de table périmé | **corrigé** — au hub : titre « Hub » et barre d'initiative masquée (vérifié dans l'état exact du bug) |
> | §2.10 `GenererDetailQuete` mort, §2.19 équilibrage | *hors périmètre — décisions de conception* |

**Date** : 2026-07-27
**Stack** : reconstruite et redémarrée intégralement avant le test (`docker compose down` / `build` /
`up`), **2 migrations en retard appliquées** (`garde_tenace_utilisee`, `bonus_sort_utilise`),
front rebuild, `optimize:clear`.
**IA** : `LLM_PROVIDER=gemini`, modèle `gemini-3.5-flash-lite` (surcharge en base), RAG Voyage actif,
TTS de narration active. Appel LLM de contrôle avant le test : OK.
**Suite Pest** : **437 tests / 6 640 assertions — verte** (92 s).

**Dispositif** : conteneur Playwright persistant (`hq-driver`) exposant 5 sessions navigateur
nommées (`table`, `p1`…`p4`) pilotées en HTTP ; **un agent Sonnet 5 par manette**, orchestrateur sur
l'écran de table, **chaque constat d'agent recoupé en base ou dans le code** avant d'être retenu.
Outillage réutilisable dans `browser-shots/pilote/` : `server.mjs` (sessions persistées sur disque,
survivent au redémarrage du conteneur), `hq`, `montour`, `choisir`, `BRIEFING.md`, `etat.php`,
et les scripts de mesure `compare-cpu.mjs`, `temoin-animations.mjs`, `fuite-table.mjs`,
`cloture-urgence.mjs`.

**Partie jouée** : campagne « Les Caveaux de Grisombre » (longueur *courte*), 4 comptes créés depuis
l'UI réelle, 4 héros (Barbare / Nain / Elfe / Magicien), groupe fondé + 3 `rejoindre` par code,
table ouverte par code, départ par les 4 boutons « Prêt ». **4 tours joués** : exploration,
détection et ouverture de porte, révélation d'une salle et de 3 monstres, combat (un monstre tué,
un héros descendu à 3/7 PV, un soin), puis **clôture de campagne par le menu d'urgence du narrateur**.

---

## 1. Ce qui marche

- **Parcours d'entrée complet sans accroc** : inscription → création de personnage (y compris le
  choix d'éléments de magie, 1 pour l'elfe / 3 pour le magicien) → fondation de campagne →
  3 `rejoindre` par code → ouverture de la table par code → 4 « Prêt » → démarrage automatique.
- **Squelette de campagne IA** : prémisse, menace (« Malagor le Maître des Ombres ») et prologue
  générés, affichés en modale sur la table.
- **Habillage IA des monstres** : les 11 instances reçoivent nom + description cohérents avec le
  thème sans que les stats du catalogue bougent.
- **Latence excellente** : menu IA du joueur actif en **1 à 3 s** (file prioritaire) ; menus moteur
  des non-actifs en 20-100 ms. Le vieux problème « premier menu ~8 s » n'existe plus.
- **Moteur de jeu solide** — aucun des 4 joueurs n'a trouvé d'incohérence mécanique :
  révélation de salle à l'ouverture de porte, phase des monstres, attaque/défense avec détail des
  dés, dégâts, mort d'un monstre retiré de l'initiative, soin (Eau de Guérison +2 PV vérifié en
  base), buffs, jets de Mind, portée de déplacement (base + dé), blocage par murs/portes/figurines.
  La séquence de PV 7→5→7→5 de Thora correspondait exactement à l'affichage à chaque étape.
- **Garde-fou « une action par tour » strictement respecté côté serveur** (c'est l'UI qui ne suit
  pas, cf. §2.2).
- **Fil de combat mécanique sur la manette** : lisible, complet, coloré par ton
  (« Abomination des Brumes touche Thora (−2 PV) · 3 crânes / 1 bouclier »).
- **Onglet Sorts ↔ menu Action parfaitement cohérents** : les 9 sorts d'Aldric et les 3 de Sylvaine
  apparaissent à l'identique aux deux endroits, aucun sort « fantôme ». Annuler un ciblage ne
  consomme ni le sort ni l'action.
- **Clôture de campagne par le menu d'urgence : fonctionnelle de bout en bout** — groupe supprimé,
  purge complète vérifiée (quêtes / cartes / instances\_monstres / événements / snapshots = 0),
  4 entrées `personnages_historiques` créées, **4 personnages rendus au roster** (`groupe_actif` nul).
- **Images de quête** générées à la volée, **TTS de narration** produit (13-16 s, asynchrone).
- **Barks** diffusés en combat (`BarkDiffuse`).

## 2. Problèmes constatés

### 2.1 BLOQUANT — l'écran de table consomme 100 % d'un cœur et ne répond plus

Mesuré au CDP, même partie, même navigateur, en parallèle :

| Page | CPU thread principal | style+layout | nœuds DOM | répond ? |
|---|---|---|---|---|
| **Table** | **100 %** | 59,2 s / 60 s | 13 466 | **NON (> 10 s)** |
| Manette | 11 % | 2,1 s / 60 s | 186 | oui (14 ms) |

Temps de script : **0,1 s**. Ce n'est ni du JS, ni une fuite mémoire (tas stable ~10 Mo, nœuds
stables, écouteurs collectés par le GC). C'est **exclusivement du recalcul de style + layout**.

**Test témoin décisif** — même page, même état, animations CSS neutralisées à l'injection :

```
AVEC animations : CPU 100 % | style+layout 29,5 s / 30 s | 13 458 nœuds | thread : NON (>10 s)
SANS animations : CPU   3 % | style+layout  0,0 s / 30 s | 13 466 nœuds | thread : oui (8 ms)
```

**Cause** : les animations CSS infinies de `TableView.vue` s'appliquent sur un arbre de ~13 500
nœuds et forcent un recalcul de style global à chaque frame. Coupables identifiés :
`figpulse` (animation de `box-shadow`, l. 784/790), `flick` (opacité sur un grand `radial-gradient`,
l. 799), `tspin` (l. 792), `table-eq` (l. 841), `table-voix-pulse` (l. 978), plus les
`backdrop-filter: blur` en surimpression.

**Conséquences observées en jeu** — c'est ça le vrai problème, pas la métrique :
- La poignée de main WebSocket échoue au chargement (`WebSocket is closed before the connection is
  established`, ×3 en console) : le thread sature avant la fin du handshake. Une page de table
  fraîche ouverte pendant la partie n'a reçu **aucune trame** en 150 s.
- Le **fil des événements de la table est resté bloqué sur une seule ligne périmée** pendant
  ~10 min (captures `tc-05`, `tc-06`, `tc-07`) alors que les manettes affichaient les 8 lignes du
  combat issues de la **même** diffusion `combat.journal`.
- Deux captures consécutives de la table **strictement identiques octet pour octet**
  (`tc-03`/`tc-04`, 750 459 octets) : l'écran partagé était gelé pendant que la partie avançait.
- **Le narrateur ne peut plus cliquer sur son propre écran** : après ~1 h, un clic sur
  `button.status-urgence` (menu d'urgence) échoue en timeout de 10 s alors que l'élément est bien
  dans le DOM ; le bouton Réglages est dans le même état. Les deux commandes de secours deviennent
  inatteignables exactement quand une partie dure assez longtemps pour qu'on en ait besoin.
  *(J'ai dû passer par une page de table à animations neutralisées pour pouvoir clôturer.)*
- Après ~30 min : 116 % d'un cœur en continu, 685 Mo de RSS pour cet onglet.

> Nuance honnête : mesuré en Chromium **headless sans GPU**, ce qui gonfle le coût de compositing.
> Mais l'écart table/manette (100 % vs 11 %) et surtout le témoin animations (100 % → 3 %) sont
> indépendants du GPU : c'est bien le recalcul de style sur 13 500 nœuds.
> Pistes : confiner les animations à `transform`/`opacity` sur des couches isolées
> (`will-change`, `contain: paint`), et ne rendre en DOM que la portion révélée de la grille
> (60 × 55 = 3 300 cases aujourd'hui, dont ~150 de salles).

### 2.2 BLOQUANT (manette tactile) — les boutons de fermeture sont inatteignables

Signalé indépendamment par **3 joueurs sur 4**, confirmé dans le CSS : `.scene-ctrl` (le rond
« Hub », `resources/css/manette.css:280`) est en `position: fixed; bottom: 16px; left: 50%;
**z-index: 200**`, alors que la feuille de déplacement `.dep-ov` (`DeplacementSheet.vue:163`) est en
`z-index: 70` avec `place-items: end center` — **exactement au même endroit**. Le lien Hub est peint
par-dessus le bouton « Fermer ».

Sur un téléphone (la cible de la manette), taper « Fermer » tape en réalité le `<RouterLink to="/">`
et **quitte la partie**. Même recouvrement sur la feuille de détail d'un sort. Le joueur d'Aldric a
mesuré en plus que le petit « × » d'en-tête **se rend à x = 471 sur un écran large de 420 px**, donc
totalement hors écran, et le joueur de Bram a constaté qu'`Échap` ne ferme pas non plus. Seul
contournement : taper le fond assombri en haut de l'écran.

### 2.3 GÊNANT — rien n'explique « aucune cible en vue » (revu à la baisse en manche 2)

> **Correction apportée en manche 2.** Ce point était classé bloquant après la manche 1, sur la foi
> de deux joueurs qui n'ont jamais vu un seul monstre dans leur tiroir de ciblage. La manche 2 montre
> que **le ciblage fonctionne correctement dès qu'une ligne de vue existe, y compris en diagonale** :
> placée en (26,27), hors de l'axe bouché par la naine, l'elfe a obtenu
> `Guerrier Squelette — PV 1/1` et `Pillard des Cryptes — PV 1/1` en tête de liste, les alliés étant
> relégués dans une section séparée « ⚠ ALLIÉS (TIR AMI) ». **La distinction monstres / alliés est
> même bien faite.** Le défaut réel est donc uniquement l'**absence d'explication** quand la ligne de
> vue est bouchée — pas une magie offensive cassée. Sévérité ramenée de bloquant à gênant.

Constaté par les deux lanceurs de sorts, puis vérifié en base sur les menus en cache alors que
**2 monstres révélés étaient à 2-3 cases** :

```
Lancer Génie        -> [heros:Thora, heros:Sylvaine, heros:Aldric, heros:Bram]
Lancer Tempête      -> [heros:Thora, heros:Sylvaine, heros:Aldric, heros:Bram]
Lancer Boule de Feu -> [heros:Thora, heros:Sylvaine, heros:Aldric, heros:Bram]
Lancer Trait de Feu -> [heros:Thora, heros:Sylvaine, heros:Aldric, heros:Bram]
```

**Le moteur n'a pas tort** : `MoteurSorts::ciblesLegales` construit bien `monstres + héros` pour les
sorts `degats`/`mental`, puis applique `filtrerLigneDeVue(..., figuresBloquent: true)`. La salle 2
n'a qu'**une porte d'une case** (42,17) et **Thora se tient dedans** (42,18) : elle bouche la seule
ligne de vue de tout le groupe.

Le problème est le **rendu de cette situation** : au lieu d'un état « aucune cible en vue », la
manette propose le sort, ouvre le tiroir de ciblage et n'y affiche **que les alliés** sous un
bandeau ⚠ « ALLIÉS (TIR AMI) ». Les deux joueurs concernés en ont conclu que la magie offensive
était cassée — et sur 4 tours, **Sylvaine n'a lancé aucun sort** et Aldric aucun sort offensif.
Effet de configuration : dans un couloir ou une porte d'une case, dès que le tank est au contact,
les casters n'ont plus rien à viser, **sans qu'aucun écran ne l'explique**.

Correctif suggéré : distinguer explicitement « aucune cible en vue (ligne de vue bouchée par un
allié) » de « aucune cible du tout », plutôt que de présenter une liste d'alliés seuls.

### 2.3 bis GÊNANT — un sort mental peut être lancé sur une cible immunisée, et il est consommé

Découvert en manche 2. Sylvaine se place pour avoir enfin un angle, obtient bien deux monstres dans
son tiroir de ciblage, et lance **Tempête** (sort `mental`, **1×/quête**) sur le premier de la
liste, un **Guerrier Squelette**. Résultat moteur :

```json
{"type":"sort","libelle":"Lancer Tempête","sort":{"nom":"Tempête","type":"mental"},
 "cible":{"instance_id":191,"nom":"Guerrier Squelette"},
 "mind_cible":0,"issue":"immunise","succes":0}
```

Le squelette a `pv_mind = 0` : il est **immunisé aux sorts mentaux**. Le sort est parti, a été
**consommé pour la quête**, et n'a strictement rien fait. Sur les 6 monstres révélés, **2 sont dans
ce cas** (les deux Guerriers Squelettes) — et rien dans le tiroir de ciblage ne les distingue des
4 autres : ni grisé, ni pictogramme, ni `pv_mind` affiché (seuls les PV de Body le sont).

C'est d'autant plus piégeux que le moteur, lui, connaît parfaitement l'immunité — il la calcule au
moment de résoudre. Il suffirait de la remonter au ciblage.

### 2.4 GÊNANT — le menu propose des options que le moteur refuse (aucun grisé)

`GenererMenu::fusionner()` (étape 2) réinjecte **inconditionnellement** les options « de couleur »
de l'IA (`dialogue` / `action` / `jet`), alors que le menu moteur (étape 1) a correctement retiré
les options d'action quand le créneau est consommé (`a_agi`). Après son action, le héros voit donc
toujours des options d'action, rendues avec la classe `choice` (jamais `disabled`, pas d'attribut
`disabled` en DOM) — **visuellement indiscernables d'options qui marcheraient**.

Chaque clic renvoie 422 « Tu as déjà agi ce tour. », affiché **en lieu et place de la narration du
MJ** (`store.setNarration(e.message)`), en texte brut sans couleur ni icône d'erreur. Le joueur
d'Aldric a dû faire **3 clics à l'aveugle** avant de trouver la seule option qui marchait
(« Attendre et observer ») ; le joueur de Thora a constaté que le message **reste affiché au clic
suivant, pourtant valide**, ce qui brouille l'attribution de l'erreur.

Occurrences relevées et corrélées aux 422 nginx : `explorer_cavite` (Thora), `analyser_glyphes` ×2
puis `franchir_seuil` (Aldric). Correctif : filtrer les options IA par créneau disponible dans
`fusionner()`, comme le fait déjà `MenuMoteur::generer`.

### 2.5 GÊNANT — la narration contredit le résultat mécanique

Signalé par **3 joueurs sur 4**. Diagnostic vérifié sur l'événement #3812 :

```json
{"type":"jet","option_id":"fouiller","succes":2,"issue":"reussite",
 "pieges_reveles":[],"portes_revelees":[]}
```

Le **jet** est réussi, mais la fouille **ne trouve rien**. Le journal mécanique dit « rien de
suspect » (il lit les tableaux vides) ; la narration IA reçoit `issue: reussite` et écrit *« elle
décèle des indices cruciaux que les ténèbres cherchaient à dissimuler »*. Les deux ont raison sur
des choses différentes — mais à la table, le MJ annonce une découverte qui n'existe pas.
Correctif : distinguer « jet réussi » de « découverte » dans le contexte passé au skill Narration.

Cas plus gênant relevé par le joueur d'Aldric : après un clic **refusé** (422) sur « Analyser les
glyphes anciens », la narration a changé toute seule quelques secondes plus tard pour décrire un
examen de glyphes *réussi*, sans aucune entrée correspondante au journal.

### 2.6 GÊNANT — le narrateur ignore les monstres qui viennent d'apparaître

À l'ouverture de la porte (ev. #3829-3832), 3 monstres sont révélés en base et apparaissent sur la
carte. La narration diffusée : *« une vaste salle oubliée se dévoile enfin… révélant des ténèbres
étouffantes »* — **aucune mention des trois créatures**.

Cause dans `ContexteAssembleur` (l. 105) : `monstres_actifs` liste **tous** les monstres actifs de
la quête, `where('etat','actif')` **sans filtre `revele`**, avec seulement `instance_id`/`nom`/`pv_body`.
Le MJ n'a donc (a) aucun signal sur ce qui vient d'être révélé, et (b) connaît les 11 monstres de la
quête y compris ceux jamais découverts — **fuite d'information** vers le narrateur.
Effet de bord sur le même contexte : `MenuChoix::valider()` accepte une option `attaque` dont la
`cible_id` est n'importe quel monstre **actif, révélé ou non** (l. 135-158).

### 2.7 GÊNANT — l'épilogue affiche « VICTOIRE » et des « Héros n°103 »

Après la clôture d'urgence, l'écran de fin affiche **« CAMPAGNE ACHEVÉE · VICTOIRE »**, le titre
« La Lumière Revient » et une médaille — alors que le texte d'épilogue juste en dessous dit que les
compagnons ont dû *« abandonner leur mission au cœur des profondeurs avant d'avoir pu triompher »*.
Les héros y sont nommés **« HÉROS N°103 »**, **« HÉROS N°100 »** (identifiants bruts), et les
4 épilogues sont **le même paragraphe répété**. Capture : `cl-03-apres-cloture.png`.

Une seule racine : la clôture d'urgence saute la diffusion `.cloture.ouverte`, donc l'écran arrive
sur `.cloture.terminee` sans `parts` ni `issue`.
- `nomDuHeros()` (`ClotureCampagneView.vue:158`) ne trouve rien dans `parts` et retombe sur
  `` `Héros n°${personnageId}` ``.
- `issueCloture(undefined)` retombe sur `ISSUES_CLOTURE.victoire`
  (`store/game.js:1015` : `ISSUES_CLOTURE[…] ?? ISSUES_CLOTURE.victoire`).

### 2.8 GÊNANT — spécificités de classe invisibles, et goulot d'étranglement inexpliqué

- **Le Nain n'a jamais eu d'option de piège ou de désamorçage.** Sur toute la partie (fouille,
  détection et ouverture de porte), aucun bouton ne distinguait la « spécialiste des pièges » des
  autres héros ; la porte s'est ouverte sans étape de vérification. *(Aucun coffre n'a été atteint,
  donc le scénario piège complet n'a pas pu être testé — à revoir dans un test plus long.)*
- **Le Barbare n'a jamais pu atteindre le contact.** Pendant 3 tours il n'a eu que
  « Se déplacer / Fouiller / Terminer le tour », bloqué derrière ses alliés dans un couloir d'une
  case. Rien à l'écran n'explique pourquoi, et **il n'existe aucun moyen de dépasser un allié**
  (confirmé : le BFS de `DeplacementSheet.vue` retire toute case de `occupees`). Le joueur a dû
  inspecter le DOM pour comprendre. Suggestion retenue de sa part : afficher « X bloque le
  passage » quand aucune case accessible ne rapproche de l'ennemi.

### 2.9 GÊNANT — rythme : deux tours complets sans le moindre événement

La carte assemblée fait **60 × 55 = 3 300 cases pour 5 salles** (~150 cases de salle, soit 4,5 % de
la surface) ; la salle la plus proche est à ~10 cases du départ. Il a fallu **2 tours complets de
4 héros** avant la première porte et le premier monstre, entièrement occupés par des déplacements et
des « Fouiller la zone : rien de suspect ».
*(Les ~45 min réelles sont dues au temps de réflexion des agents, pas au moteur — la latence serveur
est de 1-3 s. Le constat porte sur le nombre de **tours vides**.)*

### 2.10 GÊNANT — `GenererDetailQuete` est du code mort : pas de titre ni d'objectif IA

`app/Jobs/GenererDetailQuete.php` n'est **dispatché nulle part** (aucune référence dans `app/`,
`routes/`, `tests/`). Le seul chemin réel est `DemarreurQuete::demarrer`, qui fixe
`'titre' => "Quête {$positionArc} — {$gabarit->nom}"`.

Conséquence : l'écran de table affiche en très gros **« Quête 1 — Exploration simple »** pendant
toute la partie, et l'`introduction` / `objectif` / `butin` rédigés par l'IA (prévus par le skill
`DetailQuete`) ne sont jamais produits. **Les joueurs n'ont à aucun moment connu l'objectif de la
quête.**

Aggravant : la **seule** condition de victoire codée est
`! $quete->instancesMonstres()->where('etat','actif')->exists()` — 3 points d'appel identiques
(`ResolveurTour` l. 241, 1859, 2611). Autrement dit **il faut tuer tous les monstres de la carte**,
sans qu'aucun écran ne le dise jamais. Combiné au titre générique et à l'absence d'objectif, un
groupe de joueurs n'a littéralement aucun moyen de savoir ce qu'on attend de lui.

### 2.11 GÊNANT — le code de groupe fuit dans le contexte IA et pollue les noms générés

`ContexteAssembleur` (l. 48-50) envoie au LLM **`groupe.identifiant`** — le slug d'URL avec son
suffixe aléatoire — en plus de `groupe.nom`. Démonstration en manche 2 :

```
nom du groupe  : Le Tombeau de Vardhul
identifiant    : le-tombeau-de-vardhul-krmu
menace générée : Vardhul Krmu          ← le suffixe aléatoire devenu nom de méchant
```

Le code de partie n'a aucune raison d'entrer dans le contexte narratif, et il en ressort
directement dans la fiction.

### 2.12 GÊNANT — le 1er héros de l'initiative est encerclé au tour 1 (salle de départ 5×5)

Repéré par le joueur de Bram en manche 2, vérifié sur la grille : au tout premier tour, le menu du
barbare ne proposait **aucune option « Se déplacer »**. Ce n'est pas un bug d'affichage —
`MenuMoteur::peutSeDeplacer` renvoie bien faux.

La salle de départ annoncée **5×5** n'a qu'un **intérieur traversable de 3×3** (le contour est du
mur, la taille déclarée inclut le mur) :

```
  y=25   #  #  #  #  #
  y=26   #  .  .  .  #      ← Bram(16,26) Thora(17,26) Sylvaine(18,26)
  y=27   #  .  .  .  .      ← Aldric(16,27)  ·  (19,27) = la porte
  y=28   #  .  .  .  #
  y=29   #  #  #  #  #
```

9 cases utiles pour 4 héros. Le spawn les range **en ligne sur la rangée du haut**, si bien que le
héros de `spawn_heros[0]` — un **coin**, dont les deux seuls voisins intérieurs sont `spawn[1]` et
`spawn[3]` — se retrouve enfermé. Et comme les positions sont attribuées dans l'ordre d'initiative,
c'est **systématiquement le premier joueur à jouer** qui perd son déplacement du tour 1, avant que
quiconque ait pu bouger.

À noter aussi : `spawn_heros` contient **la case de la porte** `(19,27)`, ce qui placerait un héros
dans l'encadrement dès le départ — précisément la configuration qui bouche la ligne de vue de tout
le groupe (cf. §2.3).

*(Non bloquant : le héros peut agir, et dès le tour 2 les alliés ont libéré les cases. Mais c'est le
tout premier tour de la toute première quête, et il commence par « tu ne peux pas bouger », sans
explication.)*

**Le même mécanisme frappe en plein combat, et là c'est plus grave.** Plus tard dans la quête,
l'elfe s'est retrouvée figée en (26,27), dans le couloir d'**une seule case** menant à la salle :

```
  ouest  (25,27) : franchissable, OCCUPÉE par Aldric
  est    (27,27) : franchissable, OCCUPÉE par Thora
  nord   (26,26) : MUR
  sud    (26,28) : MUR
```

Quatre voisins orthogonaux bloqués → **aucune option « Se déplacer »** pendant deux tours d'affilée.
Elle n'a pas pu exécuter l'ordre d'avancer, et a supposé (à tort) que c'était le monstre en diagonale
qui la bloquait — **rien à l'écran n'indique la vraie raison**.

Aggravant : l'ordre d'initiative joue contre la marche en file. Le barbare joue **premier** alors
qu'il est en queue de file et donc bloqué par ses alliés ; le temps qu'ils avancent, son tour est
passé. Résultat, dans un couloir d'une case le tank ne peut **jamais** rattraper la tête du groupe.
Il n'existe par ailleurs aucune action d'échange de place entre alliés (confirmé : le BFS de
`DeplacementSheet.vue` retire toute case de `occupees`).

**Effet net mesuré sur la quête 2** — c'est le constat de *jeu* le plus important du test. La salle
n'ayant qu'une entrée d'une case, la naine s'est retrouvée seule au contact, **encerclée sur ses
4 côtés** (3 monstres + une alliée), donc incapable de bouger et réduite à frapper avec ses 2 dés.
Pendant **8 tours consécutifs**, un groupe de 4 héros a combattu **à un seul combattant**, avec :
- le **barbare** (8 PV, **3 dés d'attaque** — le plus gros dégât du groupe) immobile 5 tours durant
  dans le couloir, sans jamais atteindre le contact ;
- l'**elfe** figée deux tours sans option de déplacement ;
- le **magicien** cantonné dans une alcôve, sans ligne de vue.

Le combat n'a redémarré que quand la naine a tué un monstre adjacent et pris sa case, libérant la
file d'une seule position — au rythme d'**un cran par round**. Aucun écran n'explique rien de tout
cela : les trois joueurs bloqués voyaient juste « Se déplacer » disparaître de leur menu.

### 2.12 bis BLOQUANT (génération de carte) — une salle entièrement remplie de monstres

Toujours sur la quête 2, la salle 2 est déclarée **4×4** et le budget de rencontres y place
**5 monstres**. Or son intérieur réellement traversable ne fait que **5 cases** :

```
      27 28 29 30 31
y=14   #  #  #  #  #
y=15   #  #  m  m  #
y=16   #  #  m  m  #
y=17   #  #  #  m  #      ← 5 cases utiles, 5 monstres : 100 % d'occupation
y=18   #  #  .  .  #      ← couloir d'approche
```

**Aucune case libre : il est impossible d'entrer dans cette salle.** Le groupe ne peut engager les
monstres que depuis le couloir en (30,18), et cette case n'est adjacente qu'à **un seul** monstre —
celui de (30,17). Quatre héros doivent donc tuer 5 monstres, dont **les deux seuls adversaires
sérieux de la quête** (Sentinelle de Pierre 3 PV et Hantise des Abysses 3 PV élite), strictement
**un contre un**, à travers un goulot d'une case.

Le placement ne vérifie visiblement pas qu'il reste de la place pour les héros. C'est le même défaut
de comptage que §2.12 (la taille déclarée d'une salle inclut son mur, donc « 4×4 » ⇒ 5 cases utiles
et « 5×5 » ⇒ 9), mais poussé jusqu'à rendre une salle inaccessible.

Vérification de l'adjacence, qui donne la mesure exacte du problème :

```
Depuis (30,18) : nord (30,17) franchissable ← unique contact
                 est  (31,18) MUR
Depuis (29,18) : nord (29,17) MUR           ← aucun contact possible
```

**Une seule case du donjon touche la salle, et elle ne touche qu'un seul monstre.** Quatre héros
doivent donc tuer cinq monstres en 1 contre 1 strict, les trois autres réduits à faire la queue.
L'elfe postée en (29,18) ne peut ni frapper (mur au nord) ni lancer de sort : **ses deux sorts
offensifs sont épuisés** — ils sont *1×/quête*, et l'un des deux a été gaspillé sur la cible
immunisée du §2.3 bis. Sur toute la quête, elle aura donc disposé de **deux** attaques magiques,
dont une annulée sans avertissement.

### 2.13 GÊNANT — rien n'indique depuis quelle case une porte s'ouvre

En manche 2, le groupe a passé **~4 tours à piétiner** devant la salle 1 sans réussir à l'ouvrir.

La règle du moteur est pourtant correcte : `MoteurPortes::porteFermeeAdjacente` traite la porte
comme une **arête**, ouvrable depuis **l'une ou l'autre des deux cases qu'elle sépare**
(`Grille::casesPorte`). Pour la porte « côté est de (25,27) », ces deux cases sont (25,27) et
(26,27). Or les quatre héros s'étaient alignés sur la rangée y=26, qui *paraît* mener à la porte
mais bute sur un mur en (26,26) : **aucun n'était sur l'une des deux cases**, donc aucun n'a eu
l'option — et rien à l'écran n'expliquait pourquoi.

Il a fallu que l'orchestrateur lise la grille côté serveur et donne la coordonnée exacte pour
débloquer la situation. Un joueur humain n'a pas ce recours : la carte affiche bien un glyphe de
porte, mais ni la case d'où l'actionner, ni le fait que la rangée voisine est une impasse.
*(La règle elle-même est correcte, c'est sa lisibilité qui manque.)*

### 2.14 GÊNANT — des messages d'erreur techniques remontent tels quels au joueur

En cliquant « Traverser la Pierre » sans avoir désigné de destination, le magicien a vu s'afficher
dans son interface, à la place de la narration :

> « Destination requise de l'autre côté du mur : **parametres.x** et **parametres.y**. »

Le message expose des **noms de variables internes de l'API**. Il vient de
`ResolveurTour.php:1285` (et son jumeau l. 325, « Destination requise : parametres.x et
parametres.y (entiers). ») : ce sont des messages de validation destinés au développeur, renvoyés
tels quels dans une `ValidationException` que la manette affiche au joueur via
`store.setNarration(e.message)` — le même chemin que le « Tu as déjà agi ce tour. » du §2.4.

Ces sorts à ciblage spatial devraient soit ne pas être proposés tant que la cible n'est pas
choisie, soit ouvrir directement leur sélecteur de destination.

### 2.15 BLOQUANT — le parcours « session expirée » ne reconnecte pas et laisse la manette morte

Après une pause de la partie, la manette d'un joueur a affiché l'overlay :

> 🔒 **Session expirée** — « Ta session a expiré (longue pause). Reconnecte-toi puis choisis
> "Reprendre la partie" — tu reviendras là où le groupe en était. » · [Plus tard] [Se reconnecter]

Trois problèmes s'enchaînent :

1. **L'overlay bloque tous les clics en silence.** Le menu d'action reste affiché dessous,
   entièrement lisible et d'apparence jouable (« Attaquer Brute des Profondeurs », « Terminer le
   tour »…). Un clic ne produit **rien** — aucun message, aucun retour. Playwright révèle la cause :
   `<div class="session-overlay"> … intercepts pointer events`.
2. **Le bouton « Se reconnecter » ne réauthentifie pas.** Il renvoie sur `/joueur`, qui affiche un
   écran pleinement connecté — « Salut Bram — voici tes héros », le nom du groupe, « Narrateur
   actif », et un bouton **« Reprendre la partie »** — alors que le serveur répond **401 à
   `GET /api/moi`**. Cet écran est rendu depuis l'**état client en cache**, pas depuis le serveur.
3. **Résultat : « Reprendre la partie » ramène sur la manette… avec l'overlay toujours là.** Le
   joueur boucle indéfiniment entre un roster qui le dit connecté et une manette qui le dit expiré.

Seule une **vraie re-saisie de l'identifiant** dans le formulaire de connexion rétablit la session ;
l'overlay disparaît alors et les actions repassent (vérifié : attaque enregistrée juste après).

Journal serveur à l'appui :
```
POST /api/groupes/.../choix   401   (referer: /manette/...)   ← le clic part mais est rejeté
GET  /api/moi                 401   (referer: /joueur)        ← alors que l'écran dit « connecté »
GET  /api/competences         401
```

C'est bloquant pour une partie du samedi soir : il suffit d'une pause un peu longue pour qu'un
joueur soit sorti du jeu sans pouvoir y revenir par le parcours que le jeu lui propose.

### 2.16 BLOQUANT — la progression d'exploration vit dans le cache : sa perte fige tout le groupe

Le plus grave du test, découvert en pilotant la fin de la quête 2 : les quatre héros se sont
retrouvés **incapables de se déplacer**, le panneau affichant « Aucune case accessible — tu es
bloqué. Ferme et termine ton tour. » alors que le barbare avait **7 points de déplacement**, `a_deplace = 0`,
et une case libre et traversable juste à côté (vérifié moteur : `estTraversable(30,19) = true`).

**Chaîne de causalité, vérifiée bout en bout :**

1. La liste des salles découvertes est stockée **uniquement dans le cache**, avec un TTL de 6 h :
   ```
   DemarreurQuete.php:261   Cache::put(cleSallesDecouvertes($quete->id), [0], now()->addMinutes(360));
   Sauvegarde.php:413       Cache::put(cleSallesDecouvertes($queteId),   [0], now()->addMinutes(360));
   ResolveurTour.php:2498   Cache::put($cle, $vues, now()->addMinutes(360)); // « durée d'une séance »
   ```
   **Rien n'est écrit en base.** (Même chose pour `partie:tresor:{id}`.)
2. La clé a disparu : `Cache::get('partie:salles:62')` → **absent** (store `database` ; toutes les
   clés `partie:*` des quêtes 61 et 62 avaient disparu ensemble).
3. `EtatGroupe.php:195-198` retombe donc sur `$decouvertes = [0]` — **seule la salle de départ**.
4. `appliquerBrouillard` referme le brouillard sur tout le reste. Le payload serveur renvoie
   la zone occupée par le groupe entièrement en `b` :
   ```
   y=15..21, x=28..31 :  b b b b     ← couloir où se tiennent les 4 héros
   y=26 (salle de départ) :  s s s m s
   ```
5. Côté client, le BFS de `DeplacementSheet.vue:63-96` n'étend le déplacement que vers une case
   `cases[y][x] === 's'`. Aucun voisin connu ⇒ **0 case accessible** ⇒ héros immobilisé.

**Conséquence** : une partie perd son avancement d'exploration et le groupe est figé sur place, sans
aucun moyen de continuer — ni de comprendre pourquoi, le message parlant d'un blocage tactique
(« tu es bloqué ») alors que le problème est un état serveur perdu.

Deux défauts distincts à corriger :
- **Persistance** : l'exploration (et la fouille de trésor) sont de l'**état de partie durable**, pas
  du cache. Elles devraient vivre en base, comme le reste de la quête — d'autant que les snapshots
  de reprise ne les capturent pas non plus.
- **Robustesse client** : que la carte connue soit incomplète ne devrait jamais empêcher un héros de
  bouger vers une case que le serveur juge traversable. Le repli devrait au minimum autoriser les
  cases adjacentes occupées par le héros lui-même ou déjà foulées.

### 2.17 GÊNANT — un héros à terre bloque ses alliés mais pas les monstres, qui lui marchent dessus

Première chute de héros du test (l'elfe, encaissant 4 crânes / 0 bouclier de la Sentinelle de
Pierre). La mécanique elle-même est **très bien faite** : l'écran du héros à terre est explicite
(« **À terre** — Tu es tombé au combat, seul un allié peut te relever »), et l'option
« **Relever Sylvaine Feuille-Vive** » apparaît bien chez l'alliée adjacente.

Mais l'occupation de la case est traitée de **deux façons contradictoires** :

| Code | Héros à terre compté comme occupant ? |
|---|---|
| `MenuMoteur::peutSeDeplacer` (l. 162-166) | **oui** — il bloque ses alliés |
| `FabriqueGrille::pour` (l. 42, `&& ! $etat->tombe`) | **non** — il ne bloque pas les monstres |

Résultat constaté en jeu : la Sentinelle de Pierre s'est déplacée **sur la case de l'elfe à terre**
— deux figurines en (30,16) simultanément. Dans le même temps, la naine, bloquée par le corps de sa
propre alliée et par un mur en (29,17), n'avait **aucun chemin** vers le monstre.

C'est l'inverse exact de l'intention documentée (`ResolveurTour.php:2207` : « C4 : occupe sa case,
relevable » ; CLAUDE.md : « empêche le blocage d'un couloir par une figure tombée »). En l'état, un
héros à terre gêne son équipe et avantage l'adversaire.

**Et ça se referme en impasse.** Le moteur refuse ensuite le relevage, avec un message d'ailleurs
très clair :

> « Impossible de le relever : une autre figure occupe sa case. »

La chaîne complète est donc : le monstre monte sur la case de l'héroïne à terre (autorisé par
`FabriqueGrille`) → elle devient **impossible à relever** (refusé par `resoudreRelever`) → et
**l'option « Relever » reste affichée dans le menu**, échouant à chaque clic. L'héroïne ne peut
revenir en jeu que si le monstre meurt ou se déplace de lui-même.

Détail supplémentaire relevé au fil de combat : la Sentinelle **continue d'attaquer l'elfe déjà à
0 PV** (« Sentinelle de Pierre touche Sylvaine Feuille-Vive (−1 PV) ») — elle reste une cible valide
alors qu'elle est hors de combat.

### 2.18 COSMÉTIQUE

- **Bandeau « TIR AMI » sur les sorts bénéfiques** : Peau de Pierre, Eau de Guérison et Courage
  déclenchent la même alerte que les sorts offensifs — *« [Cible] subira l'effet comme un ennemi.
  Confirmer la cible ? »* — alors que ce sont des soins/buffs. Le bandeau est un habillage générique
  de « la liste ne contient que des héros », pas une détection de risque par sort.
- **Badge de condition « Renforcé 0t »** après Peau de Pierre. *Fausse alerte vérifiée* : `duree = 0`
  signifie « jusqu'à une condition de fin, jamais décrémentée » (`MoteurSorts` l. 483-498, la purge
  ne touche que `duree >= 1`). Le buff est bien actif ; c'est **l'affichage « 0t » qui est trompeur**
  (se lit comme « expiré »). Au passage, Courage et Peau de Pierre partagent la même condition
  catalogue « Renforcé » (id 9, `bonus_des: attaque_ou_defense_selon_source`), désambiguïsée par le
  pivot `source` — correct, mais l'UI n'indique pas laquelle des deux est active.
- **Fuite du nom de catalogue** : les libellés d'attaque affichent
  `Attaquer Abomination des Brumes (Fimir)` et `Attaquer Brute de Grisombre (Orque)` — le nom de
  base du bestiaire à côté de l'habillage IA, ce qui casse le reskin. *(Le fil de combat, lui,
  n'affiche que les noms d'habillage.)*
- **Journal incohérent** : une option narrative produit **deux événements de type `choix`** (celui
  du contrôleur puis celui de `resoudreNarratif`) au lieu de `choix` + `action`.
- **Libellés à géométrie variable** : la même action est reformulée d'un tour à l'autre
  (« Se déplacer » / « Franchir le seuil et avancer dans la pièce ») avec parfois une icône
  incohérente (`touch_app` sur une option de déplacement).
- **Hub au démarrage** : bourse commune à 0 or et inventaires vides, donc les 3 mercenaires
  s'affichent tous « Or insuffisant » dès le premier écran — lisible comme une fonctionnalité
  cassée. *(Conforme aux docs : les dés d'attaque/défense sont intrinsèques à la classe,
  l'équipement est purement additif. C'est un problème de présentation, pas de règles.)*
- **Aucune info sur les monstres** côté manette : cliquer l'avatar d'un monstre dans la barre
  d'initiative ne fait rien, on ne connaît jamais ses PV.
- **Gap latent** (non déclenché, trouvé en instruisant §2.4) : `MenuChoix::valider()` ne vérifie que
  `isset($option['jet'])`, jamais son contenu, alors que `resoudreJet` exige
  `attribut ∈ {body, mind}` et `difficulte ∈ [1..4]` sous peine de 422.

### 2.19 ÉQUILIBRAGE — la quête 1 est ingagnable pour 4 héros de niveau 1

**Issue réelle de la manche 2 : anéantissement total du groupe (4/4 à terre), quête `echouee`**,
après avoir tué **10 des 11 monstres**. Le dernier — une **Sentinelle de Pierre** (Gargouille,
3 PV, forte défense) — a mis les quatre héros à terre à elle seule, sans jamais descendre sous 1 PV.

Chronologie du duel final : l'elfe tombe (4 crânes / 0 bouclier d'un coup), la naine tombe, le
barbare tombe malgré un soin, puis le magicien. Sur ses dernières attaques, le barbare
(**3 dés**, le meilleur du groupe) a fait `touches: 0` puis `touches: 0` ; la Boule de Feu du
magicien a été entièrement parée (`des_degats: 2, touches: 1, degats: 0`).

Facteurs cumulés, tous déjà décrits plus haut :
- Les héros démarrent **sans aucun équipement** (§2.12 cosmétique) : dés d'attaque de base seuls.
- La géométrie impose le **1 contre 1** (§2.12 bis) : jamais plus d'un héros au contact.
- Les **sorts sont 1×/quête** : 2 attaques magiques pour l'elfe sur toute la quête, dont une
  annulée par une immunité non signalée (§2.3 bis).
- Un héros à terre **bloque ses alliés** et devient **irrécupérable** sous le monstre (§2.17).

Ce n'est pas un bug isolé mais le produit de tous les précédents. À noter pour le réglage :
`config/jeu.php` expose `forts_par_quete`, `seuil_cout_fort` et `taille_reference` — un « fort » à
3 PV et forte défense dès la **quête 1**, contre des héros nus, est manifestement trop.

*(Le parcours d'échec, lui, fonctionne bien : narration de fin juste et spécifique, écran de manette
clair — crâne + « Le destin du groupe se décide à la table… » —, retour au hub, or conservé, et la
table propose bien **« Recharger la quête »** et **« Abandonner la campagne »**.)*

### 2.20 COSMÉTIQUE — l'en-tête de la table reste sur la quête échouée

Après le retour au hub, l'écran de table affiche toujours en gros
« **Quête 1 — Exploration simple** » et la barre d'initiative liste encore BRA/THO/SYL/ALD **et
SEN** (le monstre), alors que le corps de l'écran dit « Le groupe se tient prêt au hub ».

## 3. Mesures

| Mesure | Valeur |
|---|---|
| Suite Pest | 437 tests, 6 640 assertions, vertes, 92 s |
| Menu IA (joueur actif, file `queue-jeu`) | 1-3 s |
| Menu moteur (joueurs non actifs) | 20-100 ms |
| TTS narration dynamique | 13-16 s, asynchrone |
| CPU écran de table | 100 % d'un cœur (**3 % sans animations CSS**) |
| CPU manette | 11 % |
| Nœuds DOM table / manette | 13 466 / 186 |
| Carte assemblée | 60 × 55 cases, 5 salles, 8 portes, 11 monstres |
| Tours joués | 4 (dont 2 sans aucun événement) |

## 4. Déroulé des deux manches

| | Manche 1 « Les Caveaux de Grisombre » | Manche 2 « Le Tombeau de Vardhul » |
|---|---|---|
| Pilotage | 4 agents Sonnet, un par manette | 4 agents puis orchestrateur (limite de session atteinte) |
| Tours joués | 4 | ~25 |
| Issue | arrêtée volontairement, puis clôture d'urgence | **échec — anéantissement total (4/4)** |
| Monstres | 1 tué sur 11 | **10 tués sur 11** |
| Couverture ajoutée | onboarding, exploration, porte, 1er combat, clôture | pièges, trésor, 2 salles, chute de héros, relevage, fin de quête en échec |

## 5. Priorités suggérées

1. **§2.16** — l'état d'exploration en cache : sa perte fige tout le groupe, sans recours ni
   explication. C'est le seul défaut qui rend une partie **irrécupérable**.
2. **§2.1** — l'écran de table est le cœur du produit et devient inutilisable sur la durée. Le
   témoin animations donne un correctif à coût faible et gain immédiat (100 % → 3 %).
3. **§2.15** — le parcours « session expirée » ne reconnecte pas : un joueur sort du jeu sans
   pouvoir y revenir par le chemin que le jeu lui propose.
4. **§2.2** — un `z-index` : un joueur qui tape « Fermer » quitte la partie.
5. **§2.12 / 2.12 bis / 2.17** — la géométrie et l'occupation des cases : salles remplies à 100 %,
   héros encerclés dès le tour 1, héros à terre qui bloque ses alliés mais pas les monstres. Ce
   sont eux qui, cumulés, rendent la quête 1 ingagnable (**§2.19**).
6. **§2.4** — griser les options non jouables ; supprime aussi l'écrasement de la narration.
7. **§2.3 / 2.3 bis**, puis **§2.7**, **§2.10**, **§2.5**, **§2.6**, **§2.11**, **§2.14** —
   lisibilité de ce que le MJ raconte, propose et refuse.
