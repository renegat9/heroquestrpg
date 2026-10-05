# Plan — Compléter l'extension *The Mage of the Mirror*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> vivent dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §The Mage of the Mirror.
>
> **Source** : livret de quêtes officiel **F7539** (© 2023 Hasbro, 40 pages
> imprimées / 21 pages PDF), téléchargé le 2026-10-04 depuis
> `instructions.hasbro.com` (page produit *avalon-hill-heroquest-the-mage-
> of-the-mirror-quest-pack*). Fichier 207 Mo, extraction texte intégrale
> (`texte/F7539_en-us/p01.txt`…`p21.txt`) puis **chaque bloc de stats et
> chaque citation relus sur le rendu PNG** (`rendu/F7539_en-us_pNN.png`,
> pages 6, 12, 13, 17 rendues ce jour) — nécessaire : la relecture a trouvé
> **trois erreurs de page** et **une mauvaise affectation de mécanique**
> dans nos propres sources, détaillées ci-dessous.
>
> Cette boîte est le thème `mage_du_miroir` — **déjà active**
> (`DemarreurQuete::BOITES_THEMATIQUES`), avec son boss (Sinestra,
> archétype `archimage_elfe`) et ses deux sous-boss (Ogre, Loup géant) **déjà
> dans les pools tirables**. La relecture complète du livret montre, comme
> pour Frozen Horror, que la boîte est **beaucoup plus avancée qu'un premier
> coup d'œil au brief ne le suggérait** : les potions, les 6 artefacts
> nommés et — surprise — le **répertoire de sorts elfique alternatif (3 sur
> 8)** sont déjà intégralement portés. Ce qui reste : la **malédiction du
> loup-garou**, la **quête double par miroir**, le **mobilier propre à la
> boîte** (grilles de fer, trappes, sables mouvants, miroirs), et une
> **mauvaise affectation de `grande_taille`** trouvée en relisant le livret
> en entier.

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : la liste des composants et symboles (p. 2-7), les
règles de la boîte (p. 8-11), dix quêtes — 3 solos + 5 de groupe + 1 quête
double 9-10 (p. 12-33), la conclusion et la référence d'artefacts (p. 34-35),
le tableau des monstres (p. 37), la planche de symboles pour quêtes maison
(p. 39).

**Il ne porte PAS les 35 cartes de jeu** (p. 3 : « 35 game cards ») : cartes
de monstre, les 6 artefacts nommés, le parchemin *Treasure Without Doom*, et
surtout les **8 cartes de sort elfique** elles-mêmes (le livret ne donne que
la règle de sélection « 3 sur 8 », jamais le texte des huit sorts — ceux-ci
viennent d'ailleurs : voir §1, ils sont déjà au catalogue depuis le portage
général des sorts, doc 02 §6).

**Contenu physique (p. 2-3)** : 2 portes plastique, 4 portcullis (grilles de
fer), 2 trappes de téléportation, miroirs + supports, mur et salle du
sanctuaire, figurines (2 archers elfes, 2 guerriers elfes, archimage elfe,
elfe héros, ogre, loups géants), mobilier « spécialement conçu pour le monde
elfique » (tombeau, établi d'alchimiste, cheminée, trône, bibliothèques,
coffres).

## 1. Déjà en place — vérifié dans le code ce jour

| Élément du livret | Chez nous | Preuve |
|---|---|---|
| 4 monstres (Archer elfe, Guerrier elfe, Ogre, Loup géant), stats p. 37 | `MonstreSeeder` (boîte `mage_du_miroir`) | `database/seeders/MonstreSeeder.php:285-307` |
| Boîte jouable en thème, boss + sous-boss **dans les pools tirables** | `DemarreurQuete::BOITES_THEMATIQUES` (`mage_du_miroir`) | `app/Partie/DemarreurQuete.php:525` ; rencontre finale `GabaritQueteSeeder.php:238` (archétype `archimage_elfe`) ; sous-boss `GabaritQueteSeeder.php:158-159` (`Ogre`, `Loup géant`) |
| **Sinestra sourcée comme boss**, Move 8 · Attack 4 · Defend 4 · Body 4 · **Mind 9** (p. 33, voir correction §2) | `archetype_lanceur: archimage_elfe` sur `Archimage elfe` | `database/seeders/MonstreSeeder.php:305-307` |
| **5 des 8 sorts Dread de Sinestra** (*summon wolves, mind blast, firestorm, reanimation, restore Dread*) | `config/archetypes_lanceurs.php` `archimage_elfe` | `config/archetypes_lanceurs.php:97-117` — les 3 manquants (*dispel, mirror magic, werewolf's curse*) sont nommément écartés, voir §3 lot B |
| **Répertoire de sorts elfique alternatif — « 3 sur 8 » (p. 9)** | `MoteurSorts::REPERTOIRE_ELFIQUE`, choix à la création **ou** rechoix hub via `PUT /groupes/{id}/sorts-elfiques` | `app/Partie/MoteurSorts.php:126-147,304-325` ; route `routes/api.php:168` — **c'était annoncé « manquant » dans le brief de ce plan ; ce n'est pas le cas**, à vérifier dans tout futur audit avant de le relister |
| **4 potions de la boutique** (p. 2) | `config/cartes.php` → `potions`, « Toutes portées » | `config/cartes.php:90-113` (Potion de rappel, de vision, de vitesse, de restauration supérieure) |
| **6 artefacts nommés + le parchemin** | `config/cartes.php` → `artefacts`/`parchemins`, section « Portés » | `config/cartes.php:149-150,181-183,204,330` (Brassards elfiques, Arc elfique de Vindication, Bâton Ancien, Baguette d'Os, Bottes elfiques, Orbe Céleste, Trésor sans Péril) |
| Mercenaires génériques utilisables dans cette boîte (p. 9 : « *such as by owning the Frozen Horror Quest Pack* ») | `MercenaireSeeder`, pool partagé sans colonne `boite` | voir `docs/plan-frozen-horror.md` §1bis |
| Mind à 0 = état de choc (p. 9, même texte que Frozen Horror) | `Personnage::estEnChoc()` | `docs/plan-errata-2021.md` C1 |

## 2. Corrections à `reference/18_extensions.md` §The Mage of the Mirror

Quatre erreurs trouvées en relisant le livret en entier et en rendant les
pages concernées en PNG — toutes vérifiées visuellement, pas seulement sur
le texte extrait (qui s'est montré fiable ailleurs mais ne doit jamais faire
foi seul pour une page imprimée, conformément à la consigne).

1. **High Alchemist** : la section dit « p. 22 ». La page imprimée réelle
   est **p. 23** (`rendu/F7539_en-us_p12.png`, confirmé — le tableau de
   stats et « The high alchemist knows the following Dread spells » sont
   dans la colonne de droite marquée « Page 23 »).
2. **Tormuk** : la section dit « p. 24 ». La page imprimée réelle est
   **p. 25** (`rendu/F7539_en-us_p13.png`, confirmé de la même façon).
3. **Sinestra** : la section dit « p. 30 ». La page imprimée réelle est
   **p. 33** (`rendu/F7539_en-us_p17.png`, confirmé — son bloc de stats et
   son répertoire sont dans « QUEST 9 NOTES continued: F. », qui déborde de
   la page des notes A-E (p. 31) jusqu'à la page suivante). Le même « p. 30 »
   erroné est recopié dans le commentaire de test
   `tests/Feature/Partie/BestiaireSourceTest.php` (environ ligne 90) — à
   corriger au même moment, pour que code et doc cessent de se citer
   l'un l'autre avec la même erreur.
4. **Gargouille lanceuse de sorts** (quête 8) : la section dit « p. 28 ».
   D'après le texte extrait (non re-rendu en PNG, moins critique : ce n'est
   pas un bloc de stats, juste deux noms de sort), la page serait plutôt
   **p. 29** — à confirmer par rendu avant de corriger `reference/18` si
   quelqu'un s'appuie dessus pour une citation exacte.

**Correction de fond, plus importante que les quatre ci-dessus** — voir le
lot A : `reference/18` dit « Grande figurine : non précisé explicitement
pour ces profils », ce qui est **resté exact** pour `reference/18`, mais le
code, lui, a tranché silencieusement et mal (§3 lot A).

## 3. Ce qui reste réellement — par LOT

### Lot A — `grande_taille` posée sur le MAUVAIS monstre (trouvé en relisant le livret en entier)

Notre `MonstreSeeder` porte ce commentaire sur l'Ogre : « Aligné sur la
fiche officielle de *The Mage of the Mirror* (doc 18) » et lui donne
`grande_taille: ['l' => 1, 'h' => 2]` (`database/seeders/MonstreSeeder.php:212-214`).
Le Loup géant, lui, n'a **aucun** `grande_taille`
(`database/seeders/MonstreSeeder.php:290-291`).

Or la règle « Large Monsters » du livret (p. 10, relue sur
`rendu/F7539_en-us_p06.png`) ne nomme, dans **toute la boîte**, qu'**un
seul** exemple de grande figurine — et ce n'est pas l'Ogre :

> « *10. Large Monsters — When a monster takes up more than one square
> (**the giant wolf** in this quest pack, for instance), that monster can
> attack anyone in any adjacent square (including diagonally), even if the
> monster's figure is facing away from the target.* »

Aucune quête, aucune note de salle, aucun passage du livret ne mentionne
l'Ogre comme grande figurine. `reference/18_extensions.md` avait d'ailleurs
**correctement** laissé la question ouverte (« non précisé explicitement
pour ces profils ») — c'est le code qui a surclaimé une source qu'il n'avait
pas relue en entier.

**Ce que ça demande** : déplacer `grande_taille` de `Ogre` vers
`Loup géant` dans `MonstreSeeder` (migration de données, pas de schéma —
`updateOrCreate` par nom), corriger le commentaire qui cite `reference/18`
à tort, et vérifier `BestiaireSourceTest` (qui fige vraisemblablement ces
valeurs). ⚠ Avant de trancher : la question n'est pas à 100 % fermée (voir
Q1 §4) — le livret ne *contredit* pas explicitement un Ogre à une case, il
*ne confirme* qu'un Loup géant à deux ; il est possible que l'édition 2023
donne aussi l'Ogre en grande figurine sans que la règle générale le
rementionne (elle ne cite qu'un exemple, « for instance »). Les photos de
la boîte physique (taille comparée des deux figurines) trancheraient avec
certitude.

### Lot B — La malédiction du loup-garou (p. 10-11, *Werewolf's Curse*)

Dette déjà nommée dans `config/cartes.php` (carte `Werewolf's Curse`,
`manque: 'TRANSFORMATION D'UN HÉROS EN MONSTRE'`) et dans
`docs/regles/sorts-dread.md` — mais notée comme attendant « une section du
livret que nous n'avons pas ». **Nous l'avons désormais.** Règle complète,
citée en entier (p. 10-11, relue sur `rendu/F7539_en-us_p06.png`) :

> « *If a hero is affected by a werewolf's curse spell or injured by the
> attack of a werewolf, the hero is cursed as a werewolf to switch between
> hero form and wolf form. At the start of every turn, the hero must roll
> two red dice to see if they transform into a wolf. A roll of 2 through 9
> means the hero remains in hero form […]. A roll of 10 through 12 means
> the hero transforms into wolf form and Zargon controls them as a monster
> for one turn […]. When a hero transforms into a wolf, replace their
> figure with a wolf tile. All of their possessions are left in the square
> […]. This wolf is a true monster, with no hero abilities and all the
> abilities of monsters (moves on Zargon's turn, attacks as a giant wolf,
> unaffected by traps or pits, cannot open doors, etc.). At the end of
> Zargon's turn, the wolf transforms back into the hero and returns to the
> player's control. […] The hero must roll for this transformation each
> turn until they are cured by drinking a wolfsbane potion (or the potion
> of superior restoration […]).* »

⚠ **Note p. 11, à ne pas perdre** : « *Giant wolves are considered
werewolves only in Quest 7* » — le *Loup géant* du bestiaire n'est donc PAS
systématiquement un loup-garou ; la malédiction ne vient que du sort/de la
morsure.

**Ce que ça demande** : c'est un changement d'état majeur, déjà qualifié de
tel par `reference/18` (« le héros devient tour à tour joueur puis monstre
contrôlé par le MJ ») — le moteur n'a aujourd'hui aucune notion de
personnage qui bascule de camp en cours de partie. Décomposé :
- un **jet obligatoire en début de chaque tour de héros maudit** (2 dés
  rouges, seuil 10-12) — nouveau point d'entrée dans la boucle de tour ;
- à la transformation : le héros **sort du contrôle joueur**, une figurine
  de loup le remplace, son inventaire est **déposé au sol** (même mécanisme
  que la chute d'objet déjà utilisé ailleurs — à identifier précisément au
  lot, candidat : le dépôt d'objet volé du Gremlin, `MoteurDread.php:3040`
  et suivantes, qui déplace déjà une ligne d'inventaire plutôt que de la
  recréer) ;
- pendant la phase monstre, le loup est joué par `MoteurDread` **comme un
  Loup géant** (stats déjà au catalogue) — mais piloté par un `personnage_id`
  au lieu d'un `InstanceMonstre` ordinaire, ce qui ne colle à aucun des deux
  modèles actuels tel quel ;
- fin de tour MJ : retour automatique au contrôle joueur, figurine héros
  restaurée ;
- guérison : Potion d'Aconit (à vérifier si elle existe déjà — nom anglais
  *Wolfsbane Potion*, absente de la liste des 15 potions portées au §1,
  donc **probablement manquante**, à confirmer) ou Potion de Restauration
  Supérieure (déjà portée, §1).

C'est le plus gros chantier mécanique de cette boîte — **plus gros que la
quête double** parce qu'il invente une troisième catégorie d'acteur (ni
héros-joueur, ni monstre-moteur classique) là où le projet n'en connaît que
deux.

### Lot C — La quête double par miroir (9 & 10, *Silvermane's Lair*)

**Même chantier structurel que la quête double 9-10 de *The Frozen Horror***
(`docs/plan-frozen-horror.md` §3 lot B) — à traiter dans un plan commun aux
deux boîtes (voir §4 Q2 de ce même plan, repris ici). Règle officielle,
citée en entier (p. 31, relue dans le texte extrait) :

> « *Zargon, Quests 9 and 10 are one double-sized quest. Notes A through F
> refer to the Quest 9 map while notes G through K refer to the Quest 10
> map. The heroes move back and forth between these two quests. Mind and
> Body Points are not restored when the heroes cross between Quests 9 and
> 10. Since the two quests use different parts of the gameboard, leave the
> Quest 9 rooms set up when the heroes enter the mirror and cross over to
> Quest 10.* »

Le passage lui-même (note B, p. 31, relue dans le texte extrait) :

> « *Place the mirror with the image of Princess Millandriell on the square
> marked "MIR" […]. When the hero with lunarium moves adjacent to the
> mirror, the mirror turns black. […] Tell the players that any hero can
> now pass through the mirror into the Realm of Reflection. (A hero who
> enters the mirror is placed on the square marked "G" in Quest 10. That
> hero can continue to move if they have movement left.) **Heroes cannot
> return to room "B" via the mirror.*** »

**Différence notable avec Frozen Horror** : ici le passage est **à sens
unique** (contrairement à l'escalier en spirale de Frozen Horror, qui sert
dans les deux sens) et **conditionné à la possession d'un objet** (le
flacon de lunarium — « *the hero with lunarium* »). Le plan commun devra
donc prévoir un passage paramétrable : bidirectionnel ou sens unique, libre
ou conditionné à un objet porté.

### Lot D — Mobilier et pièges propres à la boîte (p. 4-6)

Rien n'est seedé aujourd'hui pour cette boîte (`grep 'mage_du_miroir'
database/seeders/MobilierSeeder.php database/seeders/PiegeSeeder.php` : zéro
résultat, vérifié ce jour) — contrairement aux terrains de Frozen Horror,
**tout ce lot est vierge**.

| Élément | Règle (citation) | Couture à réutiliser |
|---|---|---|
| **Trappes de téléportation** (*Trap Doors* ×2, p. 5) | « *Both of the trap doors are linked by a tunnel. Any hero or monster landing on one of these squares immediately moves to the other trap door square. The connecting tunnel is dangerous. After a hero moves through it, they roll one combat die. If a skull is rolled, the hero loses 1 Body Point.* » (relu, cohérent sur toutes les quêtes qui l'emploient, ex. p. 15, 19, 31, 33) | **Quasi-identique au Tunnel de glace de Frozen Horror** (`Terrain::teleportation`, `ResolveurTour::teleporterSiTunnel()`) — SEULE différence : un dégât de passage (1 dé de combat, sur crâne) que le Tunnel de glace n'a pas. Candidat à une ligne `terrains`, `boite: mage_du_miroir`, `effet: {teleportation: true, jet_des_combat: 1, sur: {crane: {degats_pv_body: 1}}}` — réutilise le lecteur existant sans y toucher si son vocabulaire accepte déjà la combinaison (à vérifier : `MotsClesTerrain::VOCABULAIRE` déclare `teleportation` et `jet_des_combat` séparément, jamais ensemble à ce jour) |
| **Portcullis** (grilles de fer ×4, p. 4) | « *Some of these massive iron gates open when the heroes spring a certain trap, while others are unlocked by the brass key or forced open by brute strength. Heroes and monsters cannot see through a portcullis until it is opened.* » + (quête 10, note G) « *a hero must roll less than their Body Points on two red dice to force the portcullis open* » | Trois voies d'ouverture : (a) déclenchée par un piège spécifique → un piège qui ouvre une porte nommée, neuf ; (b) par une **clé spécifique** (Brass Key, p. 6) → proche de `leviers`/clé de cristal, jamais implémenté non plus ; (c) **à la force** → **quasi-identique à la Porte de pierre d'*Against the Ogre Horde*** (`MoteurPortes::VERROU_PIERRE`, `ResolveurTour::resoudreForcerPortePierre()`), sauf un jet différent (2 dés rouges < Body, pas une attaque à dés de base) — un second type de verrou sur le même état de porte plutôt qu'un mécanisme neuf. Bloque la vue tant que fermée, comme un mur, pas comme une porte ordinaire : à vérifier contre `Grille::ligneDeVue()` |
| **Sables mouvants** (*Quicksand*, p. 29) | Citée en entier : « *a hero must stand adjacent to it and try to jump over […]. The hero rolls one combat die. If a black shield is rolled, the hero successfully lands […]. Any other result means the hero lands in the quicksand […]. To avoid death, tell the hero to immediately discard any two items […]. This ends the hero's turn. On the hero's next turn, the hero climbs out […].* » | **Dette déjà nommée par `reference/18`** (« mécanique de pénalité d'inventaire aléatoire […] inédite ») — confirmée : le saut réutilise le vocabulaire `franchissable` des fosses (`PiegeSeeder.php:28`, jet + difficulté), mais la **perte de 2 objets choisis au hasard dans l'inventaire** n'a aucun précédent (le vol du Gremlin cible un objet choisi par le MJ, pas le hasard ; la Rouille détruit un objet désigné). Mécanique neuve à isoler |
| **Fosse longue** (*Long Pit Trap*, p. 6) | Citée en entier : nécessite **≥ 3 cases de déplacement restantes** pour tenter le saut ; bouclier noir = franchi (coûte 3 cases) ; sinon chute (2 PV de Body), sortie le tour suivant sur un jet de 5-6 au dé rouge, **-1 dé de combat** (jamais moins de 1) tant qu'on y est piégé | Variante de `Fosse` (`PiegeSeeder.php:28`) avec trois ajouts : seuil de mouvement minimum pour tenter le saut, pénalité de combat pendant l'enlisement, jet de sortie spécifique (5-6, pas « case libre ») |
| **Miroirs génériques** (*Mirrors*, p. 5) | « *These standing mirrors are secret portals that lead to great treasure and hidden rooms.* » — DISTINCTS du miroir de quête 9/10 | Habillage d'un **passage secret déjà existant** (`Grille::caseEmbrasure()`, `AssembleurCarte` §passage secret) plutôt qu'une nouvelle mécanique — un miroir est un décor, pas une règle neuve, tant qu'il ne s'agit pas du miroir-portail spécifique de la quête double |
| **Sanctuaire intérieur + mur** (*Inner Sanctum and Sanctum Wall*, p. 5) | Salle du boss, aucune règle propre (« *the archmage Sinestra commands her minions […] from her center of power in this room* ») | Pur décor — même traitement que « Seat of Power Room » (Frozen Horror) : aucune mécanique à inventer |
| **Clé de laiton** (*Brass Key*, p. 6) | Clé à objet unique, ouvre un portcullis précis | Même famille que la Clé de cristal de Frozen Horror (déjà nommée « proche de `leviers` » par le plan glace, jamais implémentée) — les deux pourraient partager un mécanisme de « clé nommée ouvre une porte désignée » |

### Lot E — Ce que les dix quêtes apportent au générateur (catégorie b)

| Élément de quête | Ce qu'il apporte |
|---|---|
| **Le Prospecteur et la Princesse Millandriel** (alliés captifs, quêtes 4 et 10) | **Même gabarit « libérer un captif » que Gothar** (Frozen Horror quête 3) — voir `docs/plan-frozen-horror.md` §3 lot C et Q4. Détail propre à cette boîte (quête 10, p. 33) : « *The brass key opens the portcullis, but if the heroes don't have the key, a hero must roll less than their Body Points on two red dice to force the portcullis open.* » — réutilise le verrou de force du lot D |
| **Monstres envoûtés récupérables** (les 2 archers elfes de Tormuk, quête 6, p. 25) | « *Once the heroes have killed the other monsters in this room, Tormuk's spell is broken. If the elven archers are still alive, they fight on the heroes' side for the remainder of this quest only.* » — dette déjà nommée par `reference/18` (« changement de camp d'une entité déjà placée, proche de l'allié animal/PNJ mais partant du camp adverse ») — confirmée, rien de neuf |
| **Salles de monstres figés jusqu'à déclenchement** (quête 1, note C/D) | Monstres immobiles et non ciblables tant qu'un coffre piégé n'est pas ouvert — à vérifier si notre mécanique de piège « embuscade » (déjà utilisée pour les monstres errants) couvre déjà ce cas ou s'il faut un état `dormant` distinct |

### Lot F — Sorts Dread partagés, déjà déclarés ailleurs

*Dispel* et *Mirror Magic* (connus par Sinestra et Tormuk) attendent la même
mécanique manquante que `config/cartes.php` nomme déjà : **un monstre qui
agit hors de la phase des monstres** (`MoteurReactions` ne parle qu'aux
héros). C'est un chantier transversal (il débloquerait aussi ces deux cartes
pour *Rise of the Dread Moon*) — **hors périmètre de ce plan**, à traiter le
jour où un agent ouvre ce chantier générique plutôt que boîte par boîte.

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | `grande_taille` (lot A) : la déplacer de l'Ogre vers le Loup géant sur la seule foi de la règle générale du livret (« for instance »), ou attendre une photo des deux figurines pour trancher si l'Ogre est *aussi* grand ? | Déplacer maintenant sur le Loup géant (c'est la seule figurine que le livret nomme explicitement comme grande, et notre Ogre n'a aujourd'hui **aucune** source pour sa taille), et rouvrir si une photo contredit |
| **Q2** | La quête double par miroir (lot C) : même plan commun que celui proposé pour Frozen Horror (`docs/plan-frozen-horror.md` Q2), avec un passage paramétrable (sens unique + objet requis ici, bidirectionnel et libre là-bas) ? | Oui — les deux boîtes ne demandent pas tout à fait le même passage, raison de plus pour l'écrire une fois, proprement paramétré, plutôt que deux fois à moitié |
| **Q3** | La malédiction du loup-garou (lot B) : un chantier à part entière avant ou après la quête double ? C'est le plus gros morceau de cette boîte, sans précédent dans le projet (un personnage qui bascule de camp) | Après la quête double : la quête double est partagée avec une autre boîte (effet de levier), la malédiction est isolée à celle-ci |
| **Q4** | Potion d'Aconit (*Wolfsbane Potion*), citée comme remède à la malédiction mais absente des 15 potions déjà portées : carte manquante à demander en photo, ou couverte autrement (ex. la Potion de Restauration Supérieure suffit comme unique remède) ? | Demander la carte : le livret la nomme comme un remède **distinct**, moins cher et plus ciblé que la Restauration Supérieure (800 po, usage généraliste) |

## 5. Sources à demander (photos des cartes)

- Les cartes des 4 monstres (Archer/Guerrier elfe, Ogre, Loup géant) — pour
  trancher Q1 avec certitude (taille de figurine) ;
- la **Potion d'Aconit** (*Wolfsbane Potion*), citée par la règle mais
  absente de notre liste de 15 potions portées (Q4) ;
- les 8 cartes de sort elfique elles-mêmes, pour vérifier que les 8 entrées
  déjà au catalogue (`MoteurSorts::REPERTOIRE_ELFIQUE`) correspondent
  texte pour texte — le livret ne donne que la règle de sélection, jamais
  le texte des sorts.

## 6. Ordre proposé

1. **§2** (corrections `reference/18`, y compris le commentaire de test) —
   aucun code, à faire en premier.
2. **Lot A** (`grande_taille`) selon Q1 — petit, autonome, corrige un défaut
   actif dès aujourd'hui (un Ogre qui occupe deux cases sans source, un Loup
   géant qui n'en occupe qu'une alors que c'est lui le grand monstre sourcé).
3. **Lot D** (mobilier/pièges) — autonome, sur le patron déjà posé par
   Frozen Horror et Against the Ogre Horde (porte de pierre, tunnel de
   téléportation, fosse variante). Le plus gros sous-chantier (Trappes) est
   presque gratuit : même lecteur que le Tunnel de glace.
4. **Lot C** (quête double) selon Q2, en commun avec Frozen Horror.
5. **Lot B** (malédiction du loup-garou) selon Q3, en dernier — le plus
   risqué, le seul qui invente une troisième catégorie d'acteur.
6. **Lot E** (gabarits de quête) une fois Frozen Horror et Mage of the
   Mirror comparés côte à côte (Q4 du plan Frozen Horror).

Chaque lot suit l'ordre maison : vocabulaire fermé → lecteur → test EN JEU →
données, registres testés dans les deux sens, `sauvegarder.sh` avant toute
migration, aucune purge de `groupes`/`personnages`/`joueurs`, redémarrer
`queue` et `queue-jeu` après le PHP.
