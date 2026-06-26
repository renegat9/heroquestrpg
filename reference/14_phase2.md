# 14 — Feuille de route Phase 2 : combler les écarts canoniques HeroQuest

> **Document de synthèse.** Phase 2 est désormais cadrée comme **le rattrapage des mécaniques du jeu de plateau HeroQuest (jeu de base + extensions) qui touchent au cœur de l'expérience** et que le MVP (docs 00-13) ne couvre pas encore. Les extensions « maison » (au-delà du canon) et le contenu canonique plus périphérique sont reportés au **doc 15 (Phase 3)**. Ce document ne redéfinit aucune règle déjà actée au MVP ; il ajoute ce qui manque.

---

## 1. Méthode

Comparaison entre (a) le contenu réel des extensions HeroQuest — classiques (*Kellar's Keep*, *Return of the Witch Lord*, *Against the Ogre Horde*, *Wizards of Morcar*, *The Frozen Horror*) et gamme actuelle Avalon Hill/Hasbro (*Mage of the Mirror*, *Rise of the Dread Moon*, *Jungles of Delthrak*…) — et (b) les 13 documents de conception. Seuls les écarts qui **changent une boucle de jeu existante** (exploration, combat, alliés) sont retenus ici ; le reste va au doc 15.

> Rappel : le jeu de base et les deux premières extensions (*Kellar's Keep*, *Return of the Witch Lord*) sont déjà largement couverts par le MVP — 4 héros, 8 monstres, 4 pièges, 12 sorts, dés crâne/bouclier, bourse commune. Les écarts ci-dessous viennent surtout des extensions **suivantes**.

---

## 2. Vue d'ensemble

| # | Item | Extension d'origine | Domaine touché | Doc(s) à amender |
|---|---|---|---|---|
| 1 | Portes secrètes | Jeu de base | Pièges/exploration | 10, 12 |
| 2 | Recherche de trésor à risque + monstres errants | Jeu de base | Quêtes, Bestiaire | 06, 09, 12 |
| 3 | Portes à restriction d'ouverture (clé / monstres vaincus / levier / sort) | Jeu de base (portes de quête verrouillées) | Exploration, cartes | 06, 12 |
| 4 | Monstres à distance | *Against the Ogre Horde* | Bestiaire, Combat | 09, 03, 12 |
| 5 | Alliés = mercenaires + compagnon animal | *The Frozen Horror* / *Against the Ogre Horde* | Personnages, Session, Market | 01, 05, 04, 12 |
| 6 | Monstres élites par variance aléatoire | *Rise of the Dread Moon* | Quêtes (budget de rencontre) | 06, 09 |
| 7 | Capacités de monstre à choix tactique | *The Frozen Horror* | Bestiaire, Combat | 09, 03 |
| 8 | Sorciers ennemis nommés à deck dédié | *Wizards of Morcar* | Bestiaire (Dread) | 09, 12 |
| 9 | Grandes figurines / monstres multi-cases | *Against the Ogre Horde* | Cartes, Combat | 12, 03 |

---

## 3. Détail par item

### 3.1 Portes secrètes
**Canon** : un type de recherche distinct des pièges ; révèle un passage caché invisible jusque-là, jamais déclenché par le simple passage dessus.
**Adaptation proposée** : nouvelle action *Fouiller — portes secrètes*, au même titre que *Fouiller — pièges* (doc 03 §3). Le moteur tire une porte secrète pré-placée par le gabarit de quête (doc 06 §3) sur la case/zone fouillée.
**Impact** : `cartes.grille` (doc 12 §3) doit pouvoir porter une porte « masquée » distincte d'une porte normale ; ajout d'un état `revele BOOL`.
**Décision à trancher** : fusionner avec l'action *Fouiller* existante (un seul jet qui révèle pièges + portes secrètes) ou garder deux actions séparées comme le canon ? Vu le principe « menus seulement » (doc 06 §1), une seule action « Fouiller la zone » qui révèle tout semble plus fluide à l'écran — à valider.

### 3.2 Recherche de trésor à risque + monstres errants
**Canon** : fouiller une salle pour du trésor peut révéler un trésor, **ou déclencher un monstre errant qui attaque immédiatement**, ou ne rien donner. Risque/récompense, contrairement au butin déterministe du gabarit.
**Adaptation proposée** : à la fouille d'une salle « vide » (déjà nettoyée de ses rencontres prévues), tirer sur une table pondérée par le gabarit : trésor / rien / monstre errant (piochée dans le bestiaire, hors budget de rencontre normal — ou décompté d'un petit budget « errant » séparé).
**Impact** : nouvelle table dans `gabarits_quete.structure` (doc 12 §5) ; nouvel événement `type=evenement` dans le journal pour la fouille.
**Lien avec doc 06** : conserve « catalogue défini, l'IA habille » (Q6) — le tirage est mécanique, l'IA ne fait que narrer le résultat.

### 3.3 Portes à restriction d'ouverture
> Remplace l'ancienne idée « portes destructibles » : **attaquer une porte pour la briser n'est PAS canon HeroQuest** (validation : on ouvre les portes gratuitement ; les portes verrouillées sont des obstacles de quête — clé/outil/sort, pas du combat). On garde donc la mécanique **canonique** des portes de quête verrouillées, en la généralisant à des **restrictions optionnelles**.

**Canon** : certaines portes (quêtes) ne s'ouvrent qu'à une condition : une clé/objet, l'élimination d'un gardien, l'activation d'un levier, un sort de passage ; sinon elles bloquent.
**Adaptation proposée** : une porte porte un **verrou** déclaratif (posé par le gabarit de quête, doc 06). Tant que la condition n'est pas remplie, la case est **infranchissable** et le menu explique pourquoi ; quand elle l'est, la porte **s'ouvre** (état persistant). Types de verrou :
- **`cle`** — posséder un objet-clé (`objet_id`) dans l'inventaire.
- **`monstres_vaincus`** — s'ouvre quand le(s) monstre(s) désigné(s) sont vaincus (gardien, salle nettoyée, boss) — vérifié après chaque résolution de combat.
- **`levier`** — un interrupteur ailleurs ; action *Actionner le levier* (au contact) bascule la porte liée.
- **`sort`** — contourné par **Traverser la Pierre** (`franchit_mur`, déjà codé), sans ouvrir.
- *(optionnel, maison)* **`jet`** — forcer par un **jet de Body** (compétence, pas combat) ; à étiqueter *maison* si retenu.

**Impact** : partage le **modèle de porte avec 3.1** (portes secrètes) — activer le tableau `cartes.grille['portes']` (déjà produit par `AssembleurCarte` mais aujourd'hui **inutilisé**), avec `{x, y, etat: ouverte|verrouillee|secrete, verrou?}`. Une porte `verrouillee` = **obstacle** pour le pathfinding (déjà obstacle-aware, `Grille::chemin`). L'auto-ouverture (`monstres_vaincus`/`levier`) réutilise le hook post-combat existant. **Le pathfinding n'est PAS à créer** (contrairement à ce que sous-entendait l'ancien 3.3) ; ce qui manque est l'**état de porte**.

### 3.4 Monstres à distance
**Canon** : certains monstres (orcs, squelettes, gobelins armés d'arcs dans *Against the Ogre Horde*) attaquent à distance avec ligne de vue, mais avec un dé d'attaque en moins s'ils sont adjacents plutôt qu'à distance.
**Adaptation proposée** : ajouter un champ `portee ENUM(corps_a_corps, distance)` (et éventuellement `attaque_distance` distincte de `attaque`) sur `monstres` (doc 12 §5). Le comportement scripté (C2) des monstres à distance vise le héros visible le plus avantageux plutôt que le plus proche.
**Impact** : doc 09 (bestiaire) doit documenter quels monstres de base ou variantes sont concernés — au MVP aucun monstre n'a de portée, donc c'est une vraie extension du bloc de stats, pas juste une nouvelle entité.
> ⚠ **Prérequis manquant (validation code)** : la **ligne de vue n'existe PAS** dans le moteur — seule l'**adjacence** est gérée (`Grille::sontAdjacentes`). Une attaque à distance sans LoS serait incohérente. → Implémenter d'abord un **chantier « Ligne de vue »** (ex. `Grille::ligneDeVue()`, tracé + murs bloquants), prérequis commun à 3.4 et 3.9. Cet item est donc **plus coûteux** que « juste une stat ».

### 3.5 Alliés = mercenaires + compagnon animal — *tranche la question ouverte du précédent doc 14*
**Canon** : pas de « personnage joueur temporaire ». Deux formes scriptées et simples :
- **Mercenaire** : embauché contre or **avant une quête** (pas en cours de quête), type fixe au catalogue (archer, hallebardier, etc.), stats simples, comportement scripté comme un monstre allié — pas de contrôle joueur.
- **Compagnon animal** : un par groupe à effectif réduit, joue automatiquement juste après son contrôleur, mouvement fixe, **une seule action possible (attaquer)**, ne peut pas ouvrir de porte, porter d'objet ni agir autrement.

**Adaptation proposée** : nouvelle table `mercenaires` (catalogue, type ENUM, stats, prix) + `groupe_mercenaires` (pivot, instance active dans une quête). Recrutement au hub (doc 06 §6), payé sur la bourse commune (doc 04), comme un achat de service plutôt qu'un personnage. Insertion dans l'ordre d'initiative (doc 03 C1) à une position dédiée, juste après le héros qui l'a recruté — pas de slot de groupe au sens de doc 05 §3 (ce n'est pas un personnage du roster).
**Impact** : referme la question ouverte « alliés = PNJ scripté ou personnage joué ? » → **PNJ scripté**, ce qui évite tout l'impact sur le contrôle multijoueur, la taille de groupe et l'UI de contrôle de personnage envisagé dans le précédent doc 14.
> ⚠ **Validation code** : la boucle de tour suppose **uniquement héros + monstres**
> (l'initiative est *hero-only* : `groupe_personnages.ordre_initiative`,
> `ResolveurTour::verifierInitiative`). **Insérer un allié DANS l'initiative héros**
> (juste après son recruteur) est la voie **coûteuse** (nouveau type d'acteur +
> `etat_*_quete` + refonte de la détection de fin de phase). **Recommandation : jouer
> l'allié comme un « monstre allié » dans une PHASE DÉDIÉE** (réutilise la phase
> monstres + le pathfinding existant), pas dans l'ordre des héros — même rendu, coût
> bien moindre. Le recrutement réutilise la **bourse commune** (`PhaseMarche` + `groupes.or`).
**Reste ouvert** : un mercenaire est-il jetable (perdu à la fin de la quête, comme le canon) ou persiste-t-il d'une quête à l'autre comme un investissement ? Le canon ne le fait pas persister.

### 3.6 Monstres élites par variance aléatoire
**Canon** : à l'apparition d'un monstre, un jet optionnel peut le rendre « élite » (+1 Attaque, +1 Défense, +1 PV de Body), ajoutant de la variance individuelle en plus du budget global de rencontre.
**Adaptation proposée** : au moment de peupler une rencontre (doc 06 §2, calibré sur le score de puissance du groupe), le moteur tire, pour une fraction des monstres invoqués, un statut élite avec bonus fixe. Vient compléter — pas remplacer — l'adaptation par budget déjà actée (doc 06 §2, P3).
**Impact** : `instances_monstres` (doc 12 §3) gagne un flag `elite BOOL` avec bonus appliqué à la création de l'instance.

### 3.7 Capacités de monstre à choix tactique
**Canon** : certains monstres ont un choix d'action scripté conditionnel (ex. le Polar Warbear : deux attaques normales **ou** une attaque massive unique), pas juste « attaquer le plus proche ».
**Adaptation proposée** : enrichir le script C2 (doc 03) pour permettre, par monstre, une règle de décision simple (ex. « si PV de la cible > seuil, attaque massive ; sinon double attaque »). Reste **entièrement mécanique** — pas de décision confiée au LLM, cohérent avec C2 et le principe de robustesse (doc 08 §5).
**Impact** : `monstres.capacites` (JSON, doc 12 §5) accueille des règles conditionnelles simples en plus des capacités passives actuelles (Invocation, Frappe de zone, etc., doc 09 §4).

### 3.8 Sorciers ennemis nommés à deck dédié
**Canon** : au-delà des sorts de Dread génériques assignés à un sous-boss/boss, certaines extensions introduisent des **lanceurs nommés** (Nécromancien, Maître des tempêtes, Chaman orque…) chacun avec son **propre répertoire complet** de sorts thématiques, plutôt qu'une pioche dans un pool partagé.
**Adaptation proposée** : ajouter un type de bloc `archetype_lanceur` au catalogue (doc 12 §5), avec un sous-ensemble dédié de `sorts_dread` par archétype. L'IA habille le nom/l'apparence (Q6) ; le moteur assigne le répertoire complet de l'archétype choisi au sous-boss/boss concerné.
**Impact** : raffine doc 09 §4 sans le remplacer — le « palier » (sous-boss/boss) reste la mécanique de gating, l'archétype ajoute une saveur thématique cohérente sur toute la rencontre plutôt qu'un sort isolé.

### 3.9 Grandes figurines / monstres multi-cases
**Canon** : les monstres les plus imposants (Ogres) occupent **deux cases** du plateau, pas une seule comme tous les autres.
**Adaptation proposée** : `instances_monstres.position_x/position_y` (doc 12 §3) devient une zone (ex. position + orientation + emprise 1×2 ou 2×2) pour les monstres marqués `grande_taille`. Impacte adjacence, ligne de vue et déplacement — calculs déjà gérés par le moteur (doc 03 §12) mais à étendre pour une emprise > 1 case.
**Impact** : c'est l'item le plus coûteux techniquement de la liste (touche le cœur du calcul d'adjacence **et de la ligne de vue — qui reste à créer**, cf. 3.4) — à ne traiter qu'une fois les items 1-8 stabilisés, et idéalement **sur un seul boss pilote** plutôt qu'en généralisation immédiate.

---

## 4. Dépendances entre ces chantiers

- **Portes secrètes (3.1)** et **portes à restriction (3.3)** partagent le même point d'ancrage technique (modèle de porte dans `cartes.grille`, tableau `portes` à activer) — à traiter ensemble.
- **Ligne de vue (nouveau chantier prérequis)** : à implémenter **avant** monstres à distance (3.4) et grandes figurines (3.9) — le moteur ne gère aujourd'hui que l'adjacence.
- **Recherche de trésor à risque (3.2)** dépend de l'existence d'un petit budget de monstres errants distinct du budget de rencontre principal (doc 06 §2) — prérequis léger mais réel.
- **Sorciers nommés (3.8)** et **monstres à distance (3.4)** étendent tous deux le bloc de stats `monstres` — bonne occasion de revoir ce bloc une seule fois plutôt qu'en plusieurs passes.
- **Grandes figurines (3.9)** dépend de la **ligne de vue** (comme 3.4) et reste le plus risqué ; à isoler sur un boss pilote, après le chantier LoS.
- **Alliés (3.5)** n'a aucune dépendance avec les autres items de cette liste — peut démarrer en parallèle.

---

## 5. Priorisation suggérée

| Vague | Items | Pourquoi |
|---|---|---|
| **1** | 3.6 Monstres élites · 3.8 Sorciers nommés | Gains sûrs, **zéro géométrie**, le moteur est déjà ~compatible (effort faible) |
| **2** | 3.1 Portes secrètes · 3.3 Portes à restriction · puis 3.2 Trésor à risque | Modèle de porte **partagé** ; exploration |
| **3** | 3.7 Capacités de monstre à choix tactique | Profondeur de combat (le moteur a déjà priorités + charge) |
| **prérequis** | **Ligne de vue** (nouveau chantier) | Indispensable à 3.4 et 3.9 |
| **4** | 3.4 Monstres à distance | Après la ligne de vue |
| **5** | 3.9 Grandes figurines (boss pilote) | Le plus structurant — multi-cases + LoS |
| **//** | 3.5 Alliés (mercenaires + animal) | En parallèle ; **variante « allié scripté en phase dédiée »** (pas dans l'initiative héros) |

---

## 6. Questions ouvertes à trancher

1. **Portes secrètes** : une action de fouille unique (pièges + portes) ou deux actions distinctes comme le canon ?
2. **Portes à restriction** : quels types de verrou au MVP ? (reco : clé + monstres_vaincus + levier ; `sort` gratuit ; `jet`-pour-forcer = maison/plus tard.)
3. **Monstres errants** : budget dédié séparé du budget de rencontre principal, ou simple variante de butin sans nouveau monstre actif ?
4. **Mercenaires** : persistent-ils d'une quête à l'autre (investissement) ou sont-ils consommés comme le canon (perdus en fin de quête) ?
5. **Sorciers nommés** : combien d'archétypes au MVP de cette extension (3-4 comme *Wizards of Morcar*, ou moins pour commencer) ?
6. **Grandes figurines** : généraliser l'emprise multi-case dès l'implémentation, ou la réserver à un unique boss pilote pour limiter le risque ?

---

## Renvois

- Reste du contenu canonique (périphérique) et extensions maison → **doc 15 (Phase 3)**.
- Bestiaire de base, capacités, sorts de Dread → **doc 09**.
- Combat, comportement scripté (C2), adjacence/ligne de vue → **doc 03**.
- Cartes et tuiles → **doc 06 §3**, schéma → **doc 12 §3**.
- Session, alliés vs roster → **doc 05 §3**.
