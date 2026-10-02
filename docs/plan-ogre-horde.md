# Plan — Définir entièrement l'extension *Against the Ogre Horde*

> ⚠ **Document DATÉ du 2026-10-02**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> iront dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §Against the Ogre Horde.
>
> **Source** : livret de quêtes officiel **F9528** (© 2024 Hasbro, 44 pages),
> téléchargé le 2026-10-01 depuis `instructions.hasbro.com` (page produit
> *avalon-hill-heroquest-against-the-ogre-horde-quest-pack*). Texte extrait page
> par page, et **chaque bloc de stats relu sur le rendu PNG** (l'extraction
> texte mélange les colonnes). Les numéros ci-dessous sont les **pages
> imprimées**.

## Décisions de René (2026-10-02)

| # | Décision | Conséquence dans les lots |
|---|---|---|
| **Q1** Tournoi | **Plan dédié** : `docs/plan-tournoi-worlds-end.md`, avec ses propres questions TQ1-TQ5 | Le lot F devient ce plan |
| **Q2** Allié animal | **Proposé au début d'une campagne** dont le groupe compte **moins de 4 joueurs** | Lot E. ⚠ Interprétation à confirmer à l'implémentation : proposé **une fois**, à la création du groupe, **gratuitement** (comme au livret, « at no cost »), et il accompagne le groupe pendant **toute la campagne**, faute de quoi « au début d'une campagne » n'aurait pas de sens. Il garde notre règle d'un animal par groupe et reste joué par le moteur (le contrôle par le joueur n'a pas été demandé) |
| **Q3** Mercenaire ogre | **Même fonctionnement que les autres alliés** : pas de reconduction à moitié prix | Rien à faire : `MercenaireSeeder` et le hub le traitent déjà ainsi. La règle du livret est écartée par cette phrase |
| **Q4** *Dominate* | **Oui** : le moteur joue le héros dominé pendant un tour | Lot D, en dernier |
| **Q5** Capacités des ogres | Recherche faite (Hasbro, Ye Olde Inn, BoardGameGeek, annonces) : **aucun texte des cartes 2023 en ligne**. Les seules transcriptions complètes visent la version 1990 (Games Workshop/MB). Conformément à la consigne, **on n'y touche plus** | `charge`, `frappe_de_zone` et `resistance_magique` restent, mais sont notés comme **capacités DE NOUS** dans `reference/18` (lot A) : le livret n'en donne aucune |
| **Q6** Archers | **Générique** : une règle « variante à distance » utilisable par tous les thèmes | Lot B |
| **Q7** Paliers | **Confirmé** : boss Gruzbella et Festral ; sous-boss Doralf, Spawn of the Pit, Xenloth et l'Effigie ; Nexrael au palier que mesurera son `cout` | Lot C |

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : les règles de la boîte (p. 4-16), dix quêtes (p. 21-39),
le tableau des ogres (p. 41) et la planche de symboles (p. 42).

**Il ne porte PAS les 29 cartes de jeu** (p. 3 : « 29 game cards ») : cartes
de monstre (4 ogres, 3 archers), mercenaires ogres, loups (alliés animaux),
héros Druide et ses sorts, cartes de sort de Dread *Mind Lock*, *Dominate* et
*Mind Burst*. Tout ce qui n'est écrit que sur ces cartes reste **⚠ non
trouvé** tant que René ne les a pas photographiées. C'est la même limite que
pour l'armurerie (doc 16 §2.1bis).

**Contenu physique (p. 2-3)** : 28 figurines (4 profils d'ogre, archers
gobelins, orques et squelettes, 3 loups, Druide), un trône, deux doubles
portes, des portes de pierre, deux planches de tuiles, le *World's End
Tournament*.

## 1. Déjà en place

| Élément du livret | Chez nous |
|---|---|
| Les 4 ogres, stats p. 41 (M/A/D/B/Mi : Guerrier 6/5/4/5/1, Champion 6/5/4/6/1, Commandant 4/6/5/6/2, Seigneur 4/6/6/10/5) | `MonstreSeeder`, boîte `horde_ogre`, figés par `BestiaireSourceTest` |
| Boîte jouable en thème de bestiaire | `DemarreurQuete::BOITES_THEMATIQUES` |
| *Large Monsters* (p. 6) : attaque sur les 10 cases autour | `grande_taille` + `Grille::adjacenteAEmprise(..., diagonales)` |
| *Unthreatened Movement* (p. 6) : sans monstre actif, chaque dé vaut 4 | First Light, `MenuMoteur::deplacementDuTour()` |
| Entrée et sortie par une porte fléchée (p. 6) | First Light |
| **État de choc à 0 Mind** (p. 9) | porté le 2026-10-01 (`Personnage::estEnChoc()`), **héros seulement** |
| Sorts mentaux sans effet sur un monstre à 0 Mind (p. 9) | `App\Engine\SortMental` |
| Archers à distance : dés normaux en tir, 1 dé au contact (p. 8) | `Gobelin archer`, `Archer squelette` (rangés dans la boîte `jungles_delthrak`) |
| Druide jouable | classe `druide` (Mythic Tier), sorts sourcés |
| Ogre mercenaire, Loup | `MercenaireSeeder` (cartes © 2023) |
| Chute de blocs (quête 10, note B) | piège `Chute de blocs` |
| Trône | mobilier `Trône` |

## 2. Corrections de nos sources, avant tout le reste (lot A)

`reference/18_extensions.md` §Against the Ogre Horde contient quatre erreurs
ou trous :

1. Le Druide y est noté « aucune classe identifiée ». Or la p. 3 liste bien
   un « **Druid Hero** », et le héros existe chez nous.
2. Il manque cinq blocs de stats, relus visuellement :
   - *Spawn of the Pit* (p. 6) : A4 D3 M6 B4 Mi3 ;
   - *Spawn of the Pit — Enraged* : A5 D1 M10 B6 Mi1 ;
   - *Guardian Effigy* (p. 27) : A3 D5 M0 B2 Mi0 ;
   - *Nexrael* (p. 31) : A3 D4 M8 B1 Mi5 ;
   - *Festral* (p. 35) : A4 D5 M6 B3 Mi8.
3. Les capacités de nos ogres (`charge` au Commandant ; `frappe_de_zone` et
   `resistance_magique` au Seigneur) **ne viennent pas du livret**, qui n'en
   donne aucune. Elles sont soit sur les cartes de monstre (non
   photographiées), soit de nous. **À sourcer ou à déclarer comme
   divergence** dans la liste nommée de `BestiaireSourceTest`. Ne pas les
   laisser passer pour sourcées.
4. Le *Mind Burst* fait aussi perdre du Mind **au lanceur** quand le
   défenseur gagne le jet (exemple p. 10 : « the Dread Sorcerer takes
   2 Mind Points of damage »). C'est le **premier producteur de dégâts de
   Mind côté monstre** : le choc des monstres, laissé de côté le 2026-10-01
   faute de producteur, a désormais une raison d'exister (lot D).

## 3. Les règles à porter, sourcées

### Lot B — Éléments de carte (p. 4-5, 8)

| Élément | Règle (citation abrégée) | Chez nous |
|---|---|---|
| **Porte de pierre** (*Stone Doorway*) | « a hero rolls their **base** Attack dice. If the roll includes **two skulls**, the heavy stone door swings open » ; elle reste ouverte jusqu'à la fin de la quête ; le magicien (1 dé) ne peut jamais l'ouvrir | Nouvel état de porte. « base » se lit `personnages.des_attaque`, qui **est** déjà l'attaque à mains nues (`ClasseHerosSeeder`). Le menu ne propose l'ouverture qu'à qui lance ≥ 2 dés (règle « le menu n'offre pas ce que le résolveur refuse »). Passe par `MoteurPortes` et `Grille::caseEmbrasure()` |
| **Lame balancière** (*Swinging Blade Trap*) | Case de déclenchement (dorée) + cases de lame (blanches/rouges) ; 2 dés d'attaque du MJ contre **chaque** héros sur une case de lame, défense normale ; détectée seulement en fouillant la salle de la case dorée ; le **Nain la désarme automatiquement** une fois découverte (confirmé par Hasbro) ; avec une trousse : 1 dé de combat, bouclier = désarmée, crâne = déclenchée | Premier piège à **plusieurs cases**. Il faut un nouveau mot-clé de piège (zone de lame) dans le vocabulaire fermé, et une variante de résolution du désamorçage qui ne passe pas par `JetCompetence` |
| **Fosse des ténèbres** (*Pit of Darkness*) | Comme une fosse, sauf : **non désamorçable** (on la saute) ; dégâts de chute selon l'armure : 1 PV sans armure **ou armure non métallique**, 2 avec du métal, 3 avec la plate ; on en sort au tour suivant s'il y a une case libre | Variante de `Fosse`. Lit `objets.metallique` (les Brassards de cuir comptent donc pour 1, errata B1) et la plate (`deplacement_sans_d6`, ou un drapeau plus net) |
| **Caisse de ravitaillement** (*Supply Crate*) | « The first hero to search for treasure in a room containing one of these chests will find **4 Potions of Healing** » (1 dé rouge de Body chacune) | Mobilier fouillable dont la table de butin est fixe. La *Potion de guérison* (`soin_pv_body_de: 6`) existe déjà |
| **Armes en os** (*Bone Weapons*) | « identical to weapons of the same name […] but have **no gold coin value** and cannot be bought or sold » | Variantes du catalogue : mêmes effets, `prix_base: 0` et un drapeau d'invendabilité **lu** par le marché et le don. Pas de nouvelle stat. Le livret en nomme deux : la hache de bataille en os (table de fouille du tournoi, p. 13) et l'épée longue en os (q. 4, note C) |
| **Double porte** | Composant, sans règle propre | Rendu seulement (emprise de 2 cases) ; pas de règle à inventer. ⚠ Nos portes font une case : ce point attend la décision « murs en arêtes » (`docs/plan-murs-en-aretes.md`) |
| **Archers** | « Zargon may place a standard monster **or** a ranged version of that same monster type » (squelettes, orques, gobelins) | Ajouter l'**Orque archer** (M8, A3 en tir / 1 au contact, D2, B1, Mi2, selon la règle p. 8). Décider de la boîte des archers (question Q6) |

### Lot C — Ennemis à plusieurs phases et personnages nommés (p. 6, 21-37)

**Mécanique générique** (p. 6) : « Some powerful foes adopt **new
statistics** as the heroes battle them. A monster who changes statistics is
still considered **the same monster** for game effects such as spells. A
multi-phase enemy who receives healing **will not revert** to a previous
phase. »
- À 0 Body, le monstre n'est pas retiré : il adopte la phase suivante (la
  figurine reste, les sorts actifs restent, l'instance reste la même).
- Un soin ne fait jamais revenir à une phase précédente.
- Il faut un mot-clé `phases` sur le monstre, lu au **seul** point où une
  instance passe à 0 Body. Seule la dernière phase meurt.
- ⚠ « Zargon, do not reveal […] multiple sets of statistics » : la phase
  suivante doit être une **surprise** pour les joueurs. Le payload ne publie
  donc que la phase courante, mais le **changement** de phase s'annonce
  (journal + scène), sans quoi c'est un effet automatique muet.

**Personnages nommés** (tous relus sur le rendu PNG) :

| Nom | Stats A/D/M/B/Mi | Règles propres | Rôle proposé chez nous |
|---|---|---|---|
| **Gruzbella Hammerhand** (q. 3) | Confiante 4/6/5/5/4 → Déterminée 5/5/7/5/4 → Imprudente 6/1/8/5/4 | 3 capacités, **une fois chacune**, sans action : *Break* (met fin à un sort actif sur elle), *Resilience* (ignore tous les dégâts d'une attaque), *Deflect* (redirige l'attaque qui la vise vers un héros dans ses 10 cases). Vaincue, elle s'incline et paie **1000 po** | **Boss** de la boîte (aujourd'hui « pauvre en boss » : le Seigneur ogre est seul). ⚠ Elle n'est pas maléfique (p. 2) : une défaite **sans mort** se raconte bien (duel d'honneur) |
| **Spawn of the Pit** (q. 1) | 4/3/6/4/3 → *Enraged* 5/1/10/6/1 | 2 phases | Sous-boss |
| **Doralf**, pit fighter ogre (q. 2) | 6/5/6/7/3 | — | **Sous-boss** (René, 2026-10-02) — sa résistance (~10,5 attaques de héros) est celle d'un boss, mais il n'a aucune capacité : une brute très dure |
| **Guardian Effigy** (q. 4) | 3/5/0/2/0 | Immobile ; chaque tour, une boule de feu à 3 dés sur un héros en vue ; **immunisée à tous les sorts** | Tourelle de salle : une créature à `deplacement` 0, une attaque à distance, et l'immunité aux sorts (à vérifier dans le vocabulaire) |
| **Ekur**, Seigneur ogre (q. 9) | stats du tableau p. 41 | — | Nom d'habillage du `Seigneur ogre` (l'IA renomme déjà) |
| **Tograk**, Commandant ogre (q. 6) | stats du tableau | — | Idem |
| **Nexrael** (q. 6) | 3/4/8/1/5 | *Mind Burst*, réserve de 4 cartes | Lanceur de Dread apprenti (lot D) |
| **Xenloth** (q. 9) | 2/4/6/1/4 | *Mind Lock* et *Mind Burst*, 5 cartes de chaque | Lanceur de Dread (lot D) |
| **Festral** (q. 8) | 4/5/6/3/8 | *Mind Burst*, *Mind Lock*, *Dominate*, 3 cartes de chaque ; sa garde de Guerriers du Dread passe à 5 dés d'attaque et 5 de défense | Lanceur de Dread de boss (lot D) |

### Lot D — La magie des Sorciers du Dread (p. 10-11)

**Règles générales** : un seul sort par tour ; un sort se lance sur
n'importe quelle cible **en ligne de vue** ; « if a Dread Sorcerer casts a
spell on their turn, they may take their movement but **may not make a
weapon attack** in the same turn » ; « If a Dread Sorcerer has an active
spell and is killed, the effects of that spell **immediately end** » ; le
nombre de lancers est une **réserve de cartes** propre à chaque lanceur.
Nos compteurs d'usage de sorts de Dread (`docs/regles/sorts-dread.md`)
portent déjà cette idée de réserve.

Les trois sorts reposent sur un **jet en opposition Mind contre Mind** :
attaquant et défenseur lancent chacun autant de dés de combat que leurs
points de Mind. C'est un mot-clé de résistance **nouveau**.

| Sort | Effet |
|---|---|
| **Mind Burst** | Celui qui fait le plus de crânes inflige à l'autre des dégâts de **Mind** égaux à l'écart, **lanceur compris**. Égalité : rien. Héros → `MoteurDegats::infligerMindAHeros()` (déjà là). Lanceur → premier dégât de Mind sur un **monstre** : il faut un producteur côté monstre, puis le choc des monstres (« every creature ») |
| **Mind Lock** | Chaque crâne du lanceur gèle la cible **1 tour** (1 dé de défense, aucune action). À la fin de chacun de ses tours gelés, le héros tente de se libérer : autant de dés que son Mind, **3 crânes** ou plus libèrent. C'est une rupture d'une **nouvelle forme** : seuil en crânes, et non un 6 |
| **Dominate** | 2 crânes nets ou plus : « Zargon may immediately take that hero's full movement and action […] including attacking other heroes » ; le sort finit à la fin du tour du lanceur. Chez nous, le **moteur** jouerait le héros comme il joue un monstre. Décision Q4 |

Ces trois cartes vont dans le registre `config/cartes.php` (section `dread`),
testé dans les deux sens. Une carte non portée y nomme la mécanique qui lui
manque.

### Lot E — Alliés et mercenaires de la boîte (p. 8-9)

| Règle officielle | Chez nous aujourd'hui |
|---|---|
| **Allié animal** : recruté **gratuitement** si le groupe compte **moins de 4 joueurs** ; **contrôlé par le joueur** qui l'a recruté ; joue **juste après son héros** ; peut bouger, attaquer et se défendre, mais ni ouvrir une porte ni boire une potion | Payant (prix de nous, `MercenaireSeeder`) ; un animal par groupe ; joué par le **moteur** (`phaseAllies()`), après tous les héros |
| Un héros peut, par une action, **donner une de ses potions** à un allié animal adjacent (si aucun des deux n'est au contact d'un monstre) | Absent |
| Si le héros meurt, l'allié continue sous le contrôle du même joueur | Sans objet tant que l'allié est joué par le moteur |
| **Mercenaire ogre** : **un seul** disponible entre deux quêtes ; joue au tour du héros qui l'a engagé ; on le **garde** pour la quête suivante à **moitié prix** ; s'il meurt, son prix redevient plein | Consommé en fin de quête (`GroupeMercenaire`), sans reconduction |
| Les monstres attaquent les alliés, qui se défendent au bouclier blanc (errata 2021, Hasbro) | `phaseAllies()` : les monstres **ne ciblent pas** les alliés (« hors périmètre v1 ») |

Décisions Q2 et Q3.

### Lot F — Le *World's End Tournament* (p. 12-17, quêtes 1-3)

Un **mode de combat à part**, et le plus gros chantier de la boîte :
- deux équipes, Challenger et Defender, avec des **rounds d'activation
  alternés** (le Defender commence, chaque camp active à tour de rôle un
  membre qui n'a pas encore joué) ;
- une **puissance d'équipe** pour l'équilibrage (1 + les dés de la
  meilleure attaque, par héros ; roster des monstres p. 16-17) ;
- des **jetons trophées** à ramasser au sol : *Burst of Speed* (+2 cases),
  *Riposte* (contre-attaque quand on est blessé), potions de soin, dés de
  combat bonus ;
- le **combattant seul** reçoit une manœuvre bonus après chaque tour
  adverse ;
- une fouille par table **2d6** (pièges, or, hache en os) ;
- la mort en tournoi : le héros est hors jeu pour la quête et ses objets
  restent au sol ;
- variante « monstres contre monstres ».

Rien de cela n'entre dans le modèle « quête = donjon généré ». Décision Q1.

### Lot G — Ce que les quêtes apportent au générateur

Les dix quêtes sont des donjons **imprimés** et nous n'en jouons aucun. Leurs
**objectifs**, eux, peuvent devenir des gabarits de quête
(`GabaritQueteSeeder`), que l'IA choisit à la création :
- **Tenir la salle** (q. 5) : « clear the room of monsters and be the sole
  occupants of the room for one hero's full turn » ;
- **Remonter à la surface** (q. 10) : départ au fond, salles qui
  s'**activent** d'elles-mêmes (1d6 : 1-5 une salle, 6 deux salles), sortie
  par la tuile de surface ;
- **Couloirs d'alerte** (q. 6) : dans certains couloirs, 1d6 pendant le tour
  du MJ ; sur 1-2, la salle centrale s'active ;
- **Le Puits du Dread** (q. 8) : jeter un objet de quête dans une case
  d'abîme (le pont, l'abîme mortel). Notre terrain *Rebord de crevasse* est
  le parent le plus proche ;
- **Trésors-valeurs** (émeraudes, diamants, couronne, figurine de chat) :
  de l'or sous un autre nom, ce que fait déjà la fouille.

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | Le *World's End Tournament* : (a) écarté par une phrase écrite, (b) un mode « arène » à part, plus tard, (c) une épreuve « arène » posée dans un donjon (un combat par vagues, sans le système d'activation) | **(a) maintenant**, (c) plus tard si l'envie vient : (b) est un second jeu |
| **Q2** | L'allié animal : règles officielles (**gratuit** sous 4 héros, **joué par le joueur**, juste après son héros) ou nos règles actuelles (payant, joué par le moteur) ? | La gratuité et l'ordre de jeu sont simples à aligner ; le **contrôle par le joueur** change la manette (un second menu). À trancher séparément |
| **Q3** | Le mercenaire ogre : reconduction à **moitié prix** d'une quête à l'autre ? | Oui : c'est une colonne d'état durable (pas de cache), et c'est petit |
| **Q4** | *Dominate* : le moteur joue le héros dominé (déplacement + attaque contre ses alliés) pendant un tour ? | À porter **en dernier** dans le lot D. C'est la seule carte qui fait agir un héros sans son joueur |
| **Q5** | Les capacités de nos ogres (`charge`, `frappe_de_zone`, `resistance_magique`) : photos des **cartes de monstre** pour les sourcer, ou divergence nommée ? | Demander les photos, avec celles des autres cartes de la boîte (§5) |
| **Q6** | Les archers (gobelin, orque, squelette) : boîte `jungles_delthrak` seulement, ou aussi `horde_ogre` ? `monstres.boite` n'a qu'une valeur | Une règle générique « variante à distance » (p. 8) plutôt qu'une boîte : les deux thèmes y puisent |
| **Q7** | Gruzbella **boss** de la boîte, Doralf et le Spawn **sous-boss**, les trois sorciers comme lanceurs de Dread du thème ? | Oui : c'est ce qui donne une identité à la boîte, aujourd'hui quatre ogres sans magie |

**Q7 — en partie tranchée (René, 2026-10-02)** : **Doralf est sous-boss**. Le reste suit la mesure de résistance de `cout` (nombre d'attaques de héros à 3 dés pour l'abattre) : **boss** Gruzbella (~21, comme le Seigneur ogre) et Festral (lanceur, comme l'Archimage elfe) ; **sous-boss** Spawn of the Pit (~8,5), Xenloth, l'Effigie gardienne (~3) ; Nexrael au palier que son `cout` mesuré lui donnera. ⚠ Une campagne n'a qu'**un** boss (dernière quête) mais jusqu'à **quatre** sous-boss (`JalonsCampagne::nbSousBossAttendu()`) : ce sont les sous-boss qui donnent sa couleur à un arc ogre.

## 5. Sources à demander (photos des cartes)

Les **29 cartes** de la boîte, en priorité :
- les 4 cartes de monstre ogre et les 3 archers (capacités, Q5) ;
- les cartes du **mercenaire ogre** de cette boîte (« ogre mercenaries »,
  amis de Gruzbella), pour vérifier si elles diffèrent de notre *Ogre
  mercenaire* © 2023 (8/4/4/4/1, 150 po) ;
- la carte du **Loup** de cette boîte (même vérification) ;
- les cartes de sort *Mind Lock*, *Dominate* et *Mind Burst* (le livret
  donne la règle complète, mais la carte fait foi) ;
- les cartes du Druide, si elles diffèrent du Mythic Tier.

En attendant, le livret suffit aux lots B, C, D et G : il porte toutes leurs
règles et toutes leurs stats.

## 6. Ordre proposé

1. **Lot A** : corriger `reference/18` (Druide, 5 blocs de stats, origine des
   capacités, *Mind Burst* côté lanceur). Aucun code.
2. **Lot B** : la porte de pierre, la fosse des ténèbres, la caisse de
   ravitaillement et les armes en os sont petits et autonomes. La lame
   balancière est le plus gros morceau du lot (piège à plusieurs cases).
3. **Lot C** : le moteur multi-phases, puis Gruzbella, le Spawn, Doralf et
   l'Effigie (selon Q7).
4. **Lot D** : le jet Mind contre Mind, *Mind Burst* (et le choc des
   monstres), *Mind Lock*, puis *Dominate* (selon Q4) ; les trois sorciers.
5. **Lot E** selon Q2/Q3, **lot G** (gabarits), **lot F** selon Q1.

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN JEU
→ données**, registres testés dans les deux sens, contrat d'API d'abord,
effets automatiques annoncés. Pest sur une **copie sqlite jetable** ;
`sauvegarder.sh` avant toute migration ; aucune purge de
`groupes`/`personnages`/`joueurs` ; redémarrer `queue` et `queue-jeu` après
le PHP. Une campagne d'agents (`campagne-agents`) sur le thème `horde_ogre`
clôt les lots B à D : c'est elle qui trouve ce que les tests ne trouvent
pas.
