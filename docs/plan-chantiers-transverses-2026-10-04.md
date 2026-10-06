# Plan — Trois chantiers transverses aux extensions (2026-10-04)

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md`. Il fige
> les **décisions de René du 2026-10-04** sur les trois mécaniques qui
> débloquent plusieurs boîtes à la fois, et cadre leur exécution. Les plans par
> boîte (`docs/plan-<boîte>.md`) restent la source des règles citées ; ce
> document les arbitre. Les règles en vigueur iront dans `docs/regles/`.

## Décisions de René (2026-10-04)

| Sujet | Décision |
|---|---|
| **Mobilier destructible** | Recommandations retenues : **un seul** lecteur générique « mobilier à PV/défense », commun à Delthrak et Morcar (Delthrak Q4, Morcar Q4) ; le *Basin* de Delthrak = **trois entrées** distinctes (Q5). **Les Murs magiques de Morcar occupent UNE CASE** (meuble d'une case, pas une arête) — ils n'attendent donc plus `docs/plan-murs-en-aretes.md`. |
| **Monstre à phases** | Recommandations retenues : un mot-clé générique `phases` (Ogre Horde lot C, paliers déjà tranchés Q7 le 2026-10-02) ; **Gretzl** premier boss de Delthrak (Q3) ; le **métamorphe** de Kellar's Keep réutilise ce branchement (Q2) ; la mort de **Fellmarak** est une capacité nommée propre à lui (Telor Q6) ; **Sorcier du Dread** sous-boss à répertoire limité, Fellmarak boss (Telor Q3) ; Sir Ragnar et Magrian portés **avec** Ogre Horde (Dread Moon Q5). |
| **Alliés** | **Un allié est TOUJOURS joué par son joueur** — mercenaires, allié animal, allié de quête. Remplace la décision Ogre Horde Q2 du 2026-10-02 (« joué par le moteur ») sur ce seul point ; la gratuité de l'animal sous 4 joueurs reste. Tranche Crypt Q4 (Vander) et Spirit Queen Q2 (Sigill, Udren). |
| **Mission « secourir »** | **Nouveau type de mission** : secourir quelqu'un, qui devient un **allié temporaire**. Généralise le gabarit « libérer un captif » (Frozen Horror Q4 : Gothar ; Mage of the Mirror : le Prospecteur, la Princesse Millandriel). Remplace Morcar Q3 (Sir Ragnar) : un allié temporaire générique existe désormais. |

## Chantier 1 — Mobilier destructible générique

**Existant à réutiliser** : `MoteurMobilier::destructiblesAdjacents()`,
`estDetruite()`, `detruire()` — un meuble se « met en pièces » aujourd'hui par
**jet de Body** (2026-08-24), et `FabriqueGrille::pour()` l'écarte déjà dès
qu'il est détruit (seule boucle du mobilier). Ce chantier ajoute l'autre
manière de détruire : **l'attaquer**, comme un monstre, jusqu'à épuiser ses PV.

**À porter** (sources : `docs/plan-delthrak.md` lots C-E, `docs/plan-wizards-of-morcar.md` lots A, G) :
- vocabulaire fermé (PV, défense, ce que la destruction déclenche), lecteur
  unique, option de menu « attaquer le meuble » publiée décidée par le serveur ;
- Delthrak : *Crystal Cluster* (6 PV), *Basin* en trois entrées, *Cocoon*,
  *Grasping Vine Trap* selon leur plan ;
- Morcar : *Haut Autel* (6 PV), *Coffres du Dread* (1 PV), **Murs magiques =
  meuble d'UNE case**, 1 PV, bloquant le mouvement (et la vue si la source le
  dit) ; coffres renforcés (lot G).

## Chantier 2 — Monstre à phases

**Sources** : `docs/plan-ogre-horde.md` lot C (Gruzbella 3 formes, Spawn of the
Pit 2 formes, capacités à usage unique *Break*/*Resilience*/*Deflect*),
`docs/plan-delthrak.md` lot A (Gretzl), `docs/plan-telor.md` lots D-E
(Fellmarak, Sorcier du Dread), `docs/plan-dread-moon.md` lot I (Sir Ragnar,
Magrian), `docs/plan-kellars-keep.md` lot D (métamorphe, réutilise le
branchement).

**Cœur** : « à 0 Body, un monstre à phases ne meurt pas : il passe à la forme
suivante » — **un seul** point de passage pour la mort d'un monstre, lu par
tous les chemins de dégâts. Le changement de forme est **annoncé** (journal,
payload, écran de table).

## Chantier 3 — Alliés joués par leur joueur, et mission « secourir »

### 3a. Alliés joués par leur joueur
Aujourd'hui `ResolveurTour::phaseAllies()` joue tous les alliés (moteur) après
les héros. Désormais l'allié joue **dans le tour du héros qui le contrôle**
(`groupe_mercenaires.recruteur_personnage_id`), juste après lui, depuis **sa
manette** : un second menu, produit par `MenuMoteur` (déplacement, attaque —
les mêmes règles que le moteur applique aujourd'hui à l'allié ; ni porte ni
potion, Ogre Horde p. 9). Le serveur publie la décision (options légales),
le client ne re-dérive rien. Contrat API d'abord.
⚠ Un allié dont le contrôleur est tombé/absent : à traiter explicitement
(le contrôle passe à un autre joueur, ou l'allié attend) — une décision
nommée, jamais un allié figé qui bloque le tour.

### 3b. Mission « secourir »
Un nouveau type d'objectif de quête (`GabaritQueteSeeder`, lu par
`DemarreurQuete` et le générateur de carte) :
- un **captif** est placé dans une salle de la carte (élément posé à
  l'assemblage, comme un levier) ;
- un héros au contact le **libère** (option de menu) ; il devient un **allié
  temporaire** contrôlé par **le joueur de ce héros** (3a), pour le reste de
  la quête ;
- l'objectif est rempli quand le captif **sort** du donjon vivant (comme
  Gothar, Frozen Horror p. 19 : « escort » ; à défaut d'une sortie, quand la
  salle est nettoyée — à trancher dans le plan détaillé) ; s'il meurt,
  l'objectif échoue ;
- son profil vient d'une fiche **sourcée** quand le livret en donne une
  (Gothar, le Prospecteur, Millandriel) ; l'IA l'habille (nom, récit) sans
  toucher aux stats ;
- jouable **sans clé API** (nom de catalogue, narration scriptée).

## Ordre et exécution

1. **Chantier 1** et **chantier 3** en parallèle (agents Sonnet, périmètres
   distincts : carte/mobilier vs alliés/manette/gabarits).
2. **Chantier 2** après le chantier 1 (tous deux touchent la résolution
   d'attaque).
3. Suite Pest complète, puis seeders + migrations sur la vraie base après
   `./image-tools/sauvegarder.sh`, `docker compose restart queue queue-jeu`.
4. Une campagne d'agents (`campagne-agents`) pour valider en jeu réel.

## Décisions de René du 2026-10-05 (cartes de Wizards of Morcar et cartes de monstre reçues)

| Sujet | Décision |
|---|---|
| **Captifs-jetons** | Le Prospecteur et la Princesse Millandriel (*The Mage of the Mirror*) sont des **tuiles sans carte** (« acts as an ally and is controlled by the hero who finds him », p. 4) : nouveau mode de captif **escorté** — le héros libérateur le porte (aucune figurine, aucune stat) ; mission accomplie quand **ce héros** atteint l'escalier ; s'il **tombe**, le captif est repris et retourne dans sa cellule (« monsters take the prospector to room D », p. 23), à aller rechercher. Gothar reste un captif-figurine (carte), dont la mort échoue la quête. |
| **Murs magiques** | La carte *Wall of Stone* dit « covers **2 squares** not occupied by figures » : deux **cases**, pas une arête. **Retour à 2 cases** (annule le « une case » du 2026-10-04, pris quand on croyait le mur posé sur une arête). Bâtis sur le mobilier attaquable (1 PV, 6 dés de défense). *Wall of Ice* : Storm Master ; *Wall of Flame* : High Mage ; *Wall of Stone* : sort de héros (Spells of Protection). Sources : `reference/18_extensions.md` § Wizards of Morcar — cartes TRANSCRITES. |
