# Conception — Quêtes & MJ IA (1/3) : Génération & structure

> Document d'analyse. Couvre la boucle de jeu du MJ IA, la génération des quêtes et des donjons, la ramification et la ville persistante. Voir aussi les docs *Mémoire* (2/3) et *Garde-fous* (3/3).

---

## 1. La boucle du MJ IA (menus seulement)

Interaction de base, en **menus seulement** (pas de texte libre) :

1. Le **MJ IA narre** la situation (sur la tablette, avec TTS/ambiance).
2. Le **MJ IA génère un menu de choix contextuels** pour les joueurs concernés : actions, répliques, tentatives (ex. *« Crocheter — jet de Body »*).
3. Le joueur **tape un bouton** sur son téléphone.
4. **Choix de groupe** → le menu devient un **vote** (système de vote, doc Session).
5. Le **moteur résout** (validité, dés, règles) ; le MJ IA **met le résultat en récit**.

> Principe : l'IA **propose des options bornées** que le moteur sait exécuter ; elle ne résout **jamais** une mécanique. Les options invalides sont filtrées avant affichage. C'est là que vivent les « choix d'action / conversation » du concept initial.

---

## 2. Génération de quête — hybride

- Des **gabarits** (templates) fixent la **structure** : objectif, jalons, points de décision, types de salles, budget de rencontres.
- Le **MJ IA remplit** : narration, PNJ, habillage, variations, menus de choix.
- Le **moteur assemble** le contenu mécanique depuis le **catalogue défini** (§5).

> L'hybride évite les quêtes incohérentes ou déséquilibrées : la structure est garantie, la créativité est encadrée.

### Génération en deux temps
1. **À la création du groupe** — le MJ IA génère un **squelette de campagne léger** : prémisse, **grande menace / boss final**, **jalons** (sous-boss) espacés selon la longueur, quelques **fils narratifs**. Figé dans la **bible** (doc Mémoire), il sert de fil rouge.
2. **Au hub, avant chaque quête** — le MJ IA génère le **détail jouable** juste-à-temps : carte (tuiles), rencontres **remplies au budget de difficulté courant**, PNJ, butin, menus. Guidé par le squelette + la bible + la **puissance du groupe** du moment.

> Le squelette assure la **cohérence** (préfiguration, arc qui tient) ; le juste-à-temps assure l'**adaptabilité** et ne génère **que ce qui est joué** — pas d'explosion combinatoire des branches.

### Adaptation à la difficulté (taille & stats du groupe)
À la génération de **chaque quête** (au hub), le **moteur** calcule un **score de puissance du groupe** à partir du **nombre de héros actifs**, de leurs **niveaux** et de leurs **stats/équipement**. Ce score pilote :
- le **budget de rencontres** : nombre et tier des monstres, puisés dans le bestiaire (chaque monstre a un **coût**) ;
- l'**échelle des boss** : un cran de PV / attaque / capacités selon la puissance ;
- les **récompenses** : butin et or ajustés pour garder l'économie équilibrée.

> **Garde-fou** : le **moteur fixe la difficulté** (le budget) ; l'IA ne fait que **remplir** ce budget avec des blocs définis et les habiller (Q6) — elle ne décide jamais de la difficulté. C'est la réalisation concrète de **P3**. Recalculé **à chaque quête**, il s'adapte aux arrivées/départs et à la montée en niveau.

---

## 3. Cartes / donjons — procédural depuis des tuiles

- Une **bibliothèque de tuiles modulaires** (salles, couloirs, portes, pièges, mobilier) sur **grille**.
- Le moteur assemble un **agencement valide** à partir du gabarit (taille, thème, densité).
- La grille reste **exploitable par le combat** (déplacement, ligne de vue, adjacence) — pas de carte « libre » qui casserait le tactique.

---

## 4. Arc de campagne & ramification

### Arc de campagne
La **longueur** choisie à la création (doc Session) fixe le nombre de quêtes et l'**escalade** :
- La campagne est rythmée par des **sous-boss** à intervalles réguliers (fins d'« actes »), jusqu'à une **dernière quête avec boss final**.
- Cadence proposée (à ajuster) :

| Longueur | Quêtes | Sous-boss | Final |
|---|---|---|---|
| Très courte | 1 | — | la quête unique = affrontement final |
| Courte | 3–5 | 1 (à mi-parcours) | boss final |
| Normale | 7–10 | ~tous les 3 (≈2) | boss final |
| Longue | 12–15 | ~tous les 4 (≈3) | boss final |
| Très longue | 17–20 | ~tous les 4–5 (≈3–4) | boss final |

- La **difficulté monte** vers chaque boss (cohérent avec P3 : pas de plafond d'attribut, le MJ IA relève la difficulté).
- Sous-boss et boss final sont des **blocs de stats du bestiaire**, habillés par l'IA selon le thème (Q6).

### Ramification
- Les gabarits contiennent des **points de décision / bifurcations** ; l'**issue d'un choix** (réussite/échec d'un jet, option retenue, PNJ épargné…) change le parcours et l'état du monde.
- Les branches **varient à l'intérieur de l'arc** mais **convergent vers les jalons de boss** : l'ossature (sous-boss, boss final) est garantie, le chemin entre eux varie.
- Le **journal** trace la branche prise ; la **bible** en conserve les conséquences (doc Mémoire) — base de la cohérence et de la ville persistante.

---

## 5. Contenu : catalogue défini, l'IA habille

- Monstres et objets proviennent d'un **catalogue défini** (blocs de stats) : **bestiaire** + catalogue d'équipement (doc Market).
- Le MJ IA **renomme et redécrit** librement (un *gobelin* devient un *écumeur des cryptes*) **sans toucher aux stats**.
- Les **monstres** viennent du **doc Bestiaire** (09) ; les **objets** du **doc Market**.

---

## 6. La ville persistante (lieu-relais / hub)

- Lieu où le groupe se retrouve **entre les quêtes** pour choisir sa prochaine action — résout le « lieu-relais » resté ouvert dans le doc Session.
- **Évolue** : se souvient des PNJ rencontrés, de la réputation du groupe, des conséquences des quêtes passées (doc Mémoire).
- On y déroule : **phase marché** (doc Market), gestion d'équipement, **arrivée de nouveaux joueurs** (doc Session), et le **choix de la quête suivante** (les branches passées influencent l'offre).

---

## 7. Intégration

- **Combat** : tuiles sur grille, comportement scripté des monstres (C2).
- **Market** : phase marché et catalogue d'objets au hub.
- **Session** : hub = point d'arrivée des joueurs ; choix de groupe = vote.
- **Personnages** : menus de jets s'appuient sur Body/Mind ; butin via le catalogue.
- **Mémoire** : branches, PNJ, réputation persistés dans la bible du groupe.

---

## 8. Décisions actées

1. **Génération (Q1)** : **hybride** — l'IA remplit des gabarits structurés.
2. **Cartes (Q2)** : **procédurales depuis une bibliothèque de tuiles** sur grille.
3. **Saisie (Q3)** : **menus seulement** — l'IA génère les choix contextuels, le joueur tape un bouton.
4. **Structure (Q4)** : **ramifiée** — l'issue des choix change le parcours.
5. **Hub (Q5)** : **ville persistante qui évolue**.
6. **Contenu (Q6)** : **catalogue défini**, l'IA habille (renomme/redécrit sans changer les stats).
7. **Génération en deux temps (Q10)** : **squelette** (prémisse, menace, jalons) à la **création**, stocké dans la bible ; **détail de chaque quête à la volée** au hub, calibré sur la puissance du groupe.
8. **Exploration — fouille (Phase 2, doc 14)** : **deux actions distinctes**. *Fouiller la zone* = un seul jet de Mind (diff 1) qui révèle pièges ET portes secrètes dans le rayon. *Fouiller — trésor* = action séparée à risque (table pondérée `structure.tresor_a_risque` : trésor / rien / monstre errant / piège éphémère), offerte dans une salle vide non encore fouillée. Le **monstre errant** ne sort que par *Fouiller — trésor* (jamais par *Fouiller la zone*) et est décompté d'un **budget errant dédié** (`structure.budget_errant`), distinct du budget de rencontre.
9. **Portes à restriction (Phase 2, doc 14)** : verrous `cle` (objet en inventaire, action *Ouvrir la porte* au contact), `monstres_vaincus` (ouverture auto post-combat), `levier` (action *Actionner le levier* au contact). Une porte non ouverte est infranchissable + opaque ; *Traverser la Pierre* franchit déjà les murs. Verrou `jet`-pour-forcer reporté.

---

## 9. Périmètre

- **MVP** : boucle narration→menu→résolution, gabarits de quête, assemblage de tuiles, arc de campagne (sous-boss + boss final), ramification simple, bestiaire de base, hub avec marché et choix de quête.
- **Phase 2** : ramifications profondes, gabarits riches, recrutement d'alliés au hub, événements de ville dynamiques.

---

## 10. Questions ouvertes à trancher

1. **Profondeur de ramification** : combien de bifurcations par quête avant que ça devienne ingérable ?
2. **Bibliothèque de tuiles** : jeu fixe au départ, ou extensible par thème (crypte, forêt, cité) ?
3. **Choix de la quête suivante** : imposé par la trame, ou sélection libre parmi des offres au hub ?
4. **Cadence exacte des sous-boss** par longueur de campagne (table §4 à affiner en playtest).
5. **Formule du score de puissance** et **coûts** des monstres dans le budget (à régler en playtest).
