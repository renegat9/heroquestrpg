# Plan — Définir entièrement l'extension *The Crypt of Perpetual Darkness*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> iront dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §The Crypt of Perpetual Darkness.
>
> **Source** : livret de quêtes officiel **G1798**, *Joe Manganiello's The
> Crypt of Perpetual Darkness* (© 2024/2025 Hasbro, 28 pages imprimées / 15
> pages PDF), téléchargé le 2026-10-04 depuis `instructions.hasbro.com` (page
> produit *avalon-hill-heroquest-joe-manganiello-s-the-crypt-of-perpetual-
> darkness-quest-pack*). Texte extrait page par page
> (`texte/G1798_en-us/p01.txt`…`p15.txt`) ; **chaque bloc de stats relu sur le
> rendu PNG** (`rendu/G1798_en-us_p03/12/13/14.png`) — aucun écart trouvé
> entre texte et image sur cette boîte, contrairement au risque signalé pour
> d'autres livrets. Les numéros ci-dessous sont les **pages imprimées**.

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**C'est la plus petite boîte rencontrée jusqu'ici** : « relativement courte et
proche du format "quête pure" » (reference/18 ligne 1479) — règles p. 4-5, dix
quêtes p. 6-25, épilogue + référence équipement p. 26-27. **Aucun monster
chart** (contrairement à *Against the Ogre Horde* ou *Rise of the Dread
Moon*) : zéro nouveau TYPE de monstre, uniquement des **adversaires nommés**
qui réutilisent des figurines et gabarits du jeu de base (Dread sorcerer,
squelette, momie) avec des stats données **en ligne** dans le texte de
quête. Aucune nouvelle classe jouable.

**Particularité notable : le livret imprime LUI-MÊME la page de cartes**
(« Artifact and Equipment Reference », p. 27) avec le texte intégral de
chaque objet — contrairement à *Rise of the Dread Moon*, qui ne faisait que
NOMMER ses 5 artefacts sans en citer le texte. Les **2 artefacts propres à
cette boîte** (*Crown of Shadows*, *Dragon Spear*) ont donc leur règle
complète **sourcée dans le livret**, pas seulement sur une carte non
photographiée — un cas rare où aucune photo n'est nécessaire pour les
seeder. Le reste de cette page (Ring of Return, Battle Axe, Magical Throwing
Dagger, Holy Water, Crossbow, Potion of Speed, 2 parchemins de sort) **n'a
rien de nouveau** : ce sont des cartes du système de base réimprimées.

**Il ne porte PAS** les cartes de monstre individuelles (une capacité non
écrite dans le texte de quête resterait **⚠ non trouvée**), ni les cartes de
potion de l'Alchimiste au-delà de ce qu'imprime p. 2-3 (prix et texte
complets pour les 4 potions vendues — également aucune photo nécessaire ici).

**Contenu physique** (déduit des notes de quête, pas de liste p. 2-3 dédiée
comme les autres boîtes) : figurines de squelette (minotaure), de momie
(naine), de Dread sorcerer (×3, pour la sorcière des marais, Kedrick Gilbane
et Venim), de rat (le familier), tuiles de pièges neufs, tuile d'antre du
dragon.

## 1. Déjà en place

**Aucune boîte `crypte_des_tenebres` n'existe** — pas de thème, pas de
monstre, pas de piège, pas de mobilier dédié (vérifié : `grep` sur
`Gilbane|Archaloneus|Buubhealxea|Venim|Dread Skull` dans `app/`, `database/`,
`config/` ne retourne aucune donnée de cette boîte). Ce qui EXISTE déjà et
que cette boîte peut réutiliser tel quel :

| Élément du livret | Chez nous |
|---|---|
| Potions réimprimées : *Potion of Restoration* (300 po), *Potion of Dexterity* [sic, en fait Potion de vitesse] (200 po), *Venom Antidote* (300 po), *Potion of Battle* (200 po) — toutes p. 2-3, **sans nouvelle formule** | Seedées à l'identique : `Potion de restauration` 300 po (`ObjetSeeder.php:836` — **déjà sourcée 300, pas 500**, via *Rise of the Dread Moon*/errata 2021 C3 ; la carte G1798 reconfirme 300 po **sur ses deux impressions**, 2021 et 2023, visibles côte à côte p. 2), `Potion de vitesse` 200 po (`:804`), `Antidote au venin` 300 po (`:809`), `Potion de bataille` 200 po (`:800`) |
| Équipement de base réimprimé p. 27 : *Battle Axe* 450 po, *Crossbow* 350 po, *Holy Water* 400 po | `Hache de bataille` 450 po (`ObjetSeeder.php:49`), `Arbalète` 350 po (`:177`), `Eau bénite` 400 po (`:208`) — prix identiques, rien à faire |
| *Magical Throwing Dagger* (p. 27, pas de prix affiché — objet trouvé seulement : « Always inflicts 1 Body Point of damage […] Monster cannot defend. […] lost once thrown ») | ⚠ Une arme DU MÊME NOM existe déjà (`Dague de jet magique`, `ObjetSeeder.php:326`, 700 po, `degats_fixes: 1, jetable: true`) mais sourcée sur une AUTRE carte (texte cité en commentaire : « may be thrown at any monster **or player** […] cannot be used on an adjacent target »). Même effet (1 dégât fixe, pas de jet de défense, perdue au lancer), texte de carte différent — pas une divergence à corriger, juste deux impressions du même objet. Rien à faire |
| *Ring of Return* (p. 27, pas de prix — objet trouvé : « returns all heroes the ring wearer can see to the starting point of the quest. Only once ») | ⚠ `Anneau du Retour` existe (`ObjetSeeder.php:615`, 1300 po, `cible: soi, ramene_heros_au_depart: true, charges: 1`) — le commentaire de la carte source cite **le même texte** (« wearer can see to the starting point of the quest. […] used once »). **À vérifier avant d'écrire quoi que ce soit** : `cible: soi` ramène-t-il **tout le groupe visible** (texte de carte) ou seulement le porteur ? Si le lecteur ne ramène que le porteur, c'est un défaut PRÉEXISTANT (pas propre à cette boîte) à corriger ailleurs, hors périmètre de ce plan — signalé en §4 Q1 |
| Sorts de Dread nécessaires aux 5 adversaires nommés (lot E) : *Lightning (Bolt)*, *Command*, *Sleep*, *Tempest*, *Rust*, *Escape*, *Fear*, *Cloud of Dread* | **Tous déjà au catalogue** : Éclair de Chaos, Commandement, Sommeil, Tourmente, Rouille, Fuite, Frayeur, Nuée d'Effroi (`database/seeders/SortDreadSeeder.php`, mappage `config/cartes.php:409-417`) — seul *Breathe Acid* (Venim, p. 25) n'a pas d'équivalent, voir lot E |
| « Substitution de monstre par couleur » si le stock d'un type manque (p. 4) | Sans objet pour un jeu numérique (reference/18 le dit déjà) — rien à faire |
| Révélation d'un piège à la vue (seam pour le lot C) | `MoteurPieges::revelerEnVue()` existe et sert déjà (appelé `ResolveurTour.php:3411`) — pas la même chose qu'une ATTAQUE immédiate, mais le déclenchement « vu → posé sur la carte » est déjà un point de passage |
| Monstre immobile à attaque distante (précédent pour le lot C) | **Pas encore construit** : *Guardian Effigy* (`docs/plan-ogre-horde.md` lot C) propose le même patron (`deplacement: 0` + `attaque_distance`) et n'est pas davantage porté — à ne pas dupliquer, voir lot C |
| Bonus d'attaque ciblé par nom de monstre (précédent pour le lot D) | `des_attaque_contre: ['noms' => [...], 'des' => N]` existe déjà sur au moins une arme (`ObjetSeeder.php` ~ligne 320, bonus contre Squelette/Zombie/Momie) — patron réutilisable tel quel pour *Dragon Spear* |

## 2. Corrections à `reference/18_extensions.md` (lot A)

Section très courte (lignes 1474-1584) et **sans erreur numérique trouvée** —
les 7 blocs de stats relus sur rendu PNG (sorcière des marais p. 11,
squelette de minotaure p. 13, Buubhealxea p. 15, roi Archaloneus p. 17,
momie-naine p. 21, Kedrick Gilbane p. 23, Venim p. 25) correspondent tous
exactement à ce que `reference/18` rapporte. Deux précisions à ajouter,
pas des corrections :

1. **Le prix de la Potion de restauration (300 po) est maintenant
   TRIPLEMENT sourcé** : la carte *Rise of the Dread Moon* (retenue le
   2026-10-01, `ObjetSeeder.php:840`), et les **deux impressions** (2021 et
   2023) de la carte *The Crypt of Perpetual Darkness* elle-même, visibles
   côte à côte p. 2-3 du livret. À ajouter comme référence supplémentaire
   dans `reference/18` et dans `docs/plan-errata-2021.md` §C3 — ce n'est pas
   un changement de valeur, juste une troisième source qui confirme la
   décision déjà prise.
2. **Vander l'elfe** (reference/18 ligne 1484-1487) cite correctement la
   page 9 pour « utilise les statistiques de la carte Elfe » — à vérifier :
   la page exacte confirmée par le découpage du PDF est bien **p. 9** (quête
   2, note X), cohérent avec ce qui est déjà écrit.

## 3. Les règles à porter, sourcées, par lots

### Lot B — Les 2 pièges neufs (p. 5)

| Piège | Règle (citation) | Chez nous |
|---|---|---|
| **Vignes agrippantes** (*Grasping Vines Trap*) | « Once a grasping vines trap is discovered, a hero may attempt to jump the trap or disarm it. […] if you step onto a grasping vines trap square, you automatically spring the trap […] roll 1 combat die. If you roll either a black or white shield, you have dodged […] If you roll a skull, you suffer 1 Body Point of damage **and are held in place** […] ends your turn. You or another adjacent hero can spend an action to destroy the vines » (p. 5) | **Aucune des deux boîtes qui la citent ne l'a construite** (vérifié : `PiegeSeeder` n'a aucune entrée « vigne/ronce »). Nouveau piège `detectable: true, desarmable: 'oui', usage: 'unique'` dans la forme, mais avec un effet de **maintien en place jusqu'à destruction par une action** — absent du vocabulaire actuel des pièges (qui connaît l'immobilisation temporaire mais pas « jusqu'à ce qu'un héros la détruise »). Candidat `boite: null` : partagé par *Jungles of Delthrak* ET cette boîte, donc générique d'emblée plutôt que dupliqué |
| **Mare d'acide** (*Acid Pool Trap*) | « Acid pools burn the skin of any hero who comes into contact with it. […] you must roll 1 combat die. On a skull, you suffer 1 Body Point of damage. The acid remains on the ground and affects any hero who moves onto it. Acid pools cannot be disarmed but can be jumped » (p. 5) | **Même forme que `Fosse des ténèbres`** (`PiegeSeeder.php:145`, `desarmable: 'non', usage: 'persistant'`, déjà sautable comme une fosse) — changer seulement le profil de dégâts (1 dé, 1 PV sur crâne, pas de variation selon l'armure). Le plus petit lot de ce plan |

### Lot C — Dread Skull (p. 5, réemployé quête 9 p. 23)

« As soon as a hero sees a Dread Skull, place it on the board. Immediately,
and on Zargon's turn, the Dread Skull makes a ranged attack with the
strength of 2 combat dice at a hero it can see with its green eye beams. The
skull has 1 Body Point, 0 Defend Dice, and disappears once it sustains
damage. »

- **« 1 Body Point, 0 Defend Dice, disparaît dès qu'il subit un dégât »
  n'est PAS une règle spéciale** : c'est le comportement normal d'une
  créature à 1 PV de Body — n'importe quel dégât ≥ 1 l'amène à 0. Rien à
  inventer de ce côté, juste une ligne `MonstreSeeder` avec `pv_body: 1,
  defense: 0`.
- Ce qui est neuf : (a) il apparaît sur la carte **seulement quand un héros
  le voit** — réutiliser `MoteurPieges::revelerEnVue()` comme déclencheur
  plutôt qu'un placement à l'assemblage ; (b) il **attaque immédiatement**
  à sa révélation, **hors tour normal** — même famille que les réactions
  hors tour déjà nommées dans `docs/regles/vocabulaires-effets.md` ; (c) il
  attaque aussi **chaque tour du MJ** tant qu'il vit — exactement le patron
  de *Guardian Effigy* (`docs/plan-ogre-horde.md` lot C : « Tourelle de
  salle : une créature à `deplacement` 0, une attaque à distance »), **non
  construit non plus**. Les deux adversaires partagent le même besoin
  structurel (monstre immobile, purement réactif) — à construire **une
  fois**, pas deux, cf. §4 Q2.

### Lot D — Les 2 artefacts (p. 27, texte complet, sourcé sans photo)

| Artefact | Citation complète | Donnée |
|---|---|---|
| **Crown of Shadows** | « Armor—This helmet gives you 1 extra Defend die and allows you to see normally in darkness, both magical and nonmagical. » | `categorie: armure, emplacement: casque` (ou équivalent), `effet: ['des_defense' => 1, 'vision_nocturne' => true]` — vérifier si un drapeau `vision_nocturne`/équivalent existe déjà (recherché pour l'Elfe/potion de givre, à confirmer avant d'en écrire un nouveau) |
| **Dragon Spear** | « Weapon—A long magical spear of dwarven make. When using it, roll 3 Attack dice, or roll 4 if attacking a dragon. This weapon may be used to attack diagonally. May not be used by the wizard. » | `effet: ['des_attaque' => 3, 'des_attaque_contre' => ['noms' => ['Dragon'], 'des' => 4], 'attaque_diagonale' => true]` — réutilise **exactement** le patron déjà en service (§1). Le nom `Dragon` existe déjà au catalogue (boss de `first_light`, `DemarreurQuete.php` commentaire : « Boss : le Dragon ») |

Aucune photo nécessaire : le livret cite le texte intégral, contrairement à
l'armurerie de base (doc 16 §2.1bis) et aux artefacts de *Rise of the Dread
Moon*.

### Lot E — Les 7 adversaires nommés (p. 11-25)

Tous réutilisent une figurine/gabarit du jeu de base (Dread sorcerer,
squelette, momie) avec des stats **propres**, sur le même patron que Sir
Ragnar/Magrian (*Rise of the Dread Moon*) ou Fellmarak (*Prophecy of
Telor*) : un nom d'habillage sur un bloc de stats distinct, pas une
nouvelle entrée de catalogue générique.

| Nom | Stats M/A/D/B/Mi | Sorts de Dread | Page |
|---|---|---|---|
| **Sorcière des Marais** (gabarit Dread sorcerer, quête 3) | 4/2/3/2/3 | Éclair de Chaos, Commandement, Sommeil, Tourmente, Rouille, Fuite | p. 11 |
| **Squelette de minotaure** (gabarit squelette, quête 4) | 6/3/3/2/0 | — | p. 13 |
| **Buubhealxea**, reine gobeline (quête 5) | 8/3/3/3/2 | Commandement, Frayeur, Fuite | p. 15 |
| **Roi Archaloneus**, seigneur momie (quête 6) | 6/4/4/4/3 | Sommeil, Rouille, Commandement | p. 17 |
| **Momie-naine** (quête 8) | 5/4/5/4/0 | — | p. 21 |
| **Kedrick Gilbane**, chevalier de la mort (boss, quête 9, gabarit Dread sorcerer) | 7/5/5/4/4 | Nuée d'Effroi, Frayeur, Tempête de feu, Commandement | p. 23 |
| **Venim**, le dragon (boss final, quête 10) | 7/5/5/4/4 | Nuée d'Effroi, Frayeur, Commandement, Sommeil, **Souffle Acide** | p. 25 |

- Tous les sorts sauf un existent déjà au catalogue (§1). Reste **Souffle
  Acide** (*Breathe Acid*) : « = Firestorm but with acid damage » (p. 25,
  quête 10, confirmé par rendu PNG). Deux options techniques : (a) une
  carte-sœur de Tempête de feu avec `type_degat: acide` au lieu de `feu` —
  même mécanique, donnée distincte ; (b) un paramètre de nature de dégât
  porté par l'INSTANCE du lanceur plutôt que par la carte. (a) est
  cohérent avec le reste du registre (une carte = une mécanique figée) ;
  (b) introduirait une première exception. **Recommandation : (a)**, posée
  en §4 Q3.
- **`App\Engine\TypeDegat` n'a PAS d'entrée `acide`** aujourd'hui (vérifié :
  aucune occurrence dans `TypeDegat.php`) — nouvelle entrée de vocabulaire
  fermé, nécessaire pour Souffle Acide **et** pour la Mare d'acide (lot B).
  Une seule entrée sert les deux, **vocabulaire avant tout le reste** (ordre
  maison).
- Venim est le **premier Dragon nommé jouable en combat** chez nous (le
  Dragon de `first_light` est un boss générique sans détail de sorts à ce
  jour) — à mettre en regard du lot D (*Dragon Spear*, bonus « contre
  Dragon ») : Venim devrait être une cible valide de ce bonus.

### Lot F — Obscurité magique (p. 25, antre de Venim)

« Any hero who cannot see in magical darkness has -2 combat dice the first
and second time they attack, and then they may attack as normal. The
monsters here do not suffer from this effect. »

Nouveau : un **compteur d'attaques par héros, borné à une zone/quête**
(« the first and second time »), pas un simple buff binaire. Dépend du lot D
pour l'exemption (*Crown of Shadows* donne la vision dans le noir
magique/non magique — un porteur ne décompte jamais ce malus). Zone =
l'antre du dragon (tuile dédiée posée à l'ouverture de la porte secrète,
quête 10 note C) — scénique, pas une case de terrain générique au sens de
`terrains` (pas de coût de déplacement, seulement un malus de combat), donc
plus proche d'un **état de salle** que d'un terrain.

### Lot G — La souris familière (p. 9, usage décrit p. 5 de `G1798` et réutilisé toute quête ultérieure)

« A hero can release the mouse in front of any closed door in any quest to
receive a telepathic message about what monsters and furniture lie behind
the door (if any). When the mouse is used once in this way, it scurries
off. »

Objet à **charge unique sur toute la CAMPAGNE** (pas la quête) — une
première chez nous : tous les objets à charges actuels (Serre du Corbeau,
Reagent Kit de *Rise of the Dread Moon*) se rechargent ou se consomment
**par quête**, jamais à l'échelle de la campagne entière. Effet : révèle
monstres + mobilier d'une salle fermée avant d'y entrer — proche d'une
fouille anticipée, mais sans ouvrir la porte ni déclencher les monstres.

### Lot H — Vander l'elfe, allié PNJ (p. 9, 11)

« Return Vander back to your hideout so he can translate the map. […]
Vander - Use the stats shown on the Elf card for Vander. »

- **Ce n'est pas un mercenaire** (pas de coût en or, pas de catalogue
  `MercenaireSeeder`) : un PNJ **scénarisé**, recruté par une quête de
  sauvetage (quête 2), qui rejoint le groupe en utilisant le bloc de stats
  de la **classe Elfe**. Rapprochement le plus net : l'allié animal
  gratuit d'*Against the Ogre Horde* (question Q2 de son plan, toujours
  ouverte) — même famille « allié gratuit, joué par **qui** ? » — à
  trancher UNE fois pour les deux boîtes plutôt que deux fois, §4 Q4.
- Classé (b) règle de quête : Vander n'a aucune vocation à exister hors
  d'un gabarit « sauvetage », donc pas une entrée de catalogue générique
  mais un **élément de quête** que l'IA sait poser (lot I).

### Lot I — Ce que les 10 quêtes apportent au générateur

- **Révélation conditionnelle par mouvement + poursuite** (quête 1, note B :
  fuite d'un PNJ vers une pièce) — faible, assimilable à de la narration ;
- **Récompense liée à la protection d'un PNJ en combat** (aucune occurrence
  dans cette boîte contrairement à *Rise of the Dread Moon* quête 5 — à
  l'inverse, ici les PNJ sont surtout des sources d'indices/objets) ;
- **Portes « gelées » tant qu'une condition n'est pas remplie** (quête 8,
  note B : « The door to the east wall […] is well and truly shut and
  cannot be opened », une porte scénique qui ne s'ouvre JAMAIS dans cette
  quête) — simple à poser dans un gabarit, aucune mécanique neuve ;
- **Trésor-objet de quête qui débloque une porte à serrure dédiée** (quête
  5, note X : « The door is locked. It can only be opened by the skeletal
  key located in Buubhealxea's treasure chest ») — même famille que
  l'amulette en étoile d'*Against the Ogre Horde* et le Lunar Charm de
  *Rise of the Dread Moon*, troisième occurrence du même patron « objet de
  quête = clé d'un passage », candidat solide à généraliser plutôt qu'à
  reporter une troisième fois ;
- **Glissade au sol vers une case fixe** (quête 10, note A : « slides all
  the way down onto the space marked "X" […] roll 2 combat dice to see if
  any damage is taken ») — variante locale de la Fosse des ténèbres (lot
  B), scénique à cette seule quête, (c) écarté par manque de généralité
  pour l'instant ;
- **Blacksmith/marchande protégée pour un objet unique** (quête 1, note C :
  fouille → objet garanti) — déjà couvert par le patron « premier à
  fouiller trouve X » déjà en service partout.

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | *Ring of Return* : le lecteur actuel de `ramene_heros_au_depart` ramène-t-il tout le groupe visible par le porteur (texte de carte, §1) ou seulement le porteur ? Si c'est ce dernier, faut-il corriger — sachant que l'objet est **déjà vendu/seedé** et peut être en possession de personnages réels ? | Vérifier le lecteur avant tout — si divergent, le signaler comme défaut préexistant plutôt que de le resourcer silencieusement sur cette boîte |
| **Q2** | Dread Skull (cette boîte) et Guardian Effigy (*Against the Ogre Horde*) partagent le même besoin : une créature `deplacement: 0` purement réactive à distance. Construire le patron UNE fois (mot-clé générique « tourelle »/« immobile ») avant de seeder l'un ou l'autre ? | Oui — exactement la discipline « une règle, un point de passage » |
| **Q3** | Souffle Acide (Venim) : carte-sœur de Tempête de feu avec `type_degat: acide` (une carte dupliquée, une donnée), ou un paramètre de nature de dégât porté par l'instance de monstre (une exception au registre figé) ? | Carte-sœur — cohérent avec le reste de `config/cartes.php`, pas de première exception |
| **Q4** | Vander (allié gratuit, stats Elfe, joué par le joueur) recoupe la question Q2 ouverte d'*Against the Ogre Horde* (allié animal gratuit, joué par le joueur ou par le moteur ?) : trancher les deux ensemble ? | Oui — la réponse à l'une répond à l'autre, un seul mécanisme d'« allié joué par un joueur » sert les deux boîtes |
| **Q5** | Cette boîte devient-elle un thème `crypte_tenebres` à part entière (6ᵉ/7ᵉ entrée de `BOITES_THEMATIQUES`), sachant qu'elle n'a **aucun monstre générique propre** — seulement des adversaires nommés réutilisant des gabarits du jeu de base ? Le principe « un thème a besoin d'un fond commun + quelques signatures » (`docs/plan-themes-bestiaire.md`) tient-il avec zéro créature de base dédiée ? | À trancher après le lot E : si les 7 adversaires nommés suffisent à « colorer » un arc (ils couvrent déjà boss + plusieurs sous-boss), rien n'empêche le thème — sinon, la boîte reste un réservoir de rencontres scriptées sans thème propre |

## 5. Sources à demander (photos des cartes)

Contrairement aux autres boîtes, **le besoin est minimal** : le livret
imprime lui-même le texte complet des 2 artefacts propres et de tout
l'équipement réemployé (p. 27), et le texte complet des 4 potions vendues
(p. 2-3). Il ne reste que :
- les cartes de monstre individuelles (une capacité éventuelle non écrite
  dans le texte de quête, sur le modèle d'*Against the Ogre Horde* Q5) ;
- la carte de la souris familière, si elle existe comme carte à part
  entière (le livret ne la nomme que dans le texte de quête, jamais comme
  composant avec symbole — possible qu'elle n'ait **aucune carte dédiée**).

Le livret suffit pour TOUS les lots B à I.

## 6. Ordre proposé

1. **Lot A** : préciser `reference/18` (troisième source du prix de la
   Potion de restauration). Aucun code — et pratiquement rien à corriger,
   cette section étant déjà fiable.
2. **Lot B** (2 pièges) : le plus petit chantier autonome, aucune
   dépendance.
3. **Lot D** (2 artefacts) : autonome, sourcé sans photo — à faire tôt,
   prérequis du lot F (exemption *Crown of Shadows*) et utile au lot E
   (*Dragon Spear* vs Venim).
4. **Lot E** (7 adversaires nommés) : ajouter d'abord `acide` au
   vocabulaire fermé de `TypeDegat` (sert aussi le lot B), puis seeder —
   aucune des 6 autres cartes de sort n'est à construire, déjà là.
5. **Lot C** (Dread Skull) après que le patron « tourelle immobile »
   (Q2) soit tranché avec *Against the Ogre Horde* — ne pas le construire
   deux fois.
6. **Lot F** (obscurité magique) après le lot D.
7. **Lot G** (souris) et **lot H** (Vander) en parallèle, puis **lot I**
   (gabarits) en continu — même discipline que pour les autres boîtes : pas
   un chantier isolé, un enrichissement au fil de l'eau des gabarits
   existants.

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN JEU
→ données**, registres testés dans les deux sens, contrat d'API d'abord,
effets automatiques annoncés. Pest sur une **copie sqlite jetable** ;
`sauvegarder.sh` avant toute migration ; **aucune purge de
`groupes`/`personnages`/`joueurs`, aucune commande qui écrit sur la vraie
base** ; redémarrer `queue` et `queue-jeu` après le PHP.
