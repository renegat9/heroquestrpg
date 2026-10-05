# Plan — Compléter l'extension *The Frozen Horror*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> vivent dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §The Frozen Horror.
>
> **Source** : livret de quêtes officiel **F5815** (© 2021/2022 Hasbro, 40
> pages imprimées / 21 pages PDF), téléchargé le 2026-10-04 depuis
> `instructions.hasbro.com` (page produit *avalon-hill-hero-quest-the-frozen-
> horror-quest-pack*). Fichier 270 Mo, extraction texte page par page
> (`texte/F5815_en-us/p01.txt`…`p21.txt`) puis **chaque bloc de stats et
> chaque règle citée relus sur le rendu PNG** (`rendu/F5815_en-us_pNN.png`,
> pages 3, 4, 5 rendues ce jour) — l'extraction texte désaligne les colonnes
> et scinde les doubles-pages. Les numéros ci-dessous sont les **pages
> imprimées**, vérifiées par rendu là où c'est marqué « relu PNG ».
>
> ⚠ **Ce plan ne reprend PAS `docs/plan-glace-et-degats-mind.md`**, qui a déjà
> porté : le terrain de glace (catalogue `terrains`, 7 tuiles, Dijkstra
> pondéré), les dégâts de Mind (`MoteurDegats::infligerMindAHeros()`), les 3
> sorts manquants du boss, l'étreinte du Yéti et le vol du Gremlin, et a
> rallumé la boîte comme thème. Ici : un **audit de ce qui reste** après ce
> plan — et il reste nettement moins qu'annoncé dans le brief initial : la
> relecture complète du livret montre que les **mercenaires**, les
> **potions**, les **3 artefacts nommés** et le **répertoire de sorts du
> boss** sont déjà intégralement portés. Ce qui reste : la **quête double
> liée (9 & 10)**, deux **divergences de fidélité non déclarées** trouvées en
> relisant le livret en entier (immunité des monstres au terrain, mécanique
> de l'Ours polaire), et des **corrections de citation** dans `reference/18`.

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : la liste des composants et symboles (p. 2-7), les
règles de la boîte (p. 8-11), dix quêtes — 3 solos + 5 de groupe + 1 quête
double 9-10 (p. 12-33), la conclusion (p. 34-35), le tableau des monstres et
des mercenaires (p. 37), la planche de symboles pour quêtes maison (p. 39).

**Il ne porte PAS les 35 cartes de jeu** (p. 2 : « 35 game cards ») : cartes
de monstre, de mercenaire, les 3 artefacts nommés et les 6 parchemins de
sort non nommés. Pour les potions et les stats de monstre/mercenaire, le
**texte du livret suffit** (il les chiffre en toutes lettres, p. 2 et p. 37)
— seuls les 3 artefacts nommés dépendraient en théorie de leur carte, mais
ils sont **déjà portés** par une autre source (§1).

**Contenu physique (p. 2-3)** : 2 portes plastique, 3 planches de tuiles
cartonnées, bloc de fiches de personnage, 12 figurines de mercenaires (4
profils), figurines de monstre (Gremlin, Ours polaire, Yéti, Horreur des
Glaces).

## 1. Déjà en place — vérifié dans le code ce jour

| Élément du livret | Chez nous | Preuve |
|---|---|---|
| 4 monstres (Gremlin des glaces, Yéti, Ours polaire de guerre, Horreur des Glaces), stats p. 37 | `MonstreSeeder` (boîte `horreur_des_glaces`) | `database/seeders/MonstreSeeder.php:314-329` |
| Boîte jouable en thème de bestiaire, boss + 2 sous-boss **dans les pools tirables** | `DemarreurQuete::BOITES_THEMATIQUES` (`horreur_des_glaces`), `BOITES_INCOMPLETES` **vide** | `app/Partie/DemarreurQuete.php:523-541,558-574` ; rencontre finale `GabaritQueteSeeder.php:238` (archétype `horreur_glacee`) ; sous-boss `GabaritQueteSeeder.php:158` (`Ours polaire de guerre`, `Yéti`) |
| **Les 6 sorts Dread FIXES du boss** (« *Chill, Ice Storm, Ice Wall, Mind Freeze, Skate, Soothe* », p. 37) | `config/archetypes_lanceurs.php` `horreur_glacee`, 7 entrées (les 6 + Choc Mental au titre des « 6 au choix du MJ ») | `config/archetypes_lanceurs.php:131-153` |
| Terrain de glace, 7 tuiles à règle, lecteurs câblés | `App\Engine\MotsClesTerrain`, `ResolveurTour::tronquerSurGlace()/saignerParTerrain()/saignerSurRiviere()/teleporterSiTunnel()` | `app/Engine/MotsClesTerrain.php`, `app/Partie/ResolveurTour.php:3081-3393` |
| Étreinte du Yéti, vol du Gremlin | `MoteurDread::victimeDeLetreinte()/voler()` | `app/Partie/MoteurDread.php:2929-3170` |
| **Mind à 0 = état de choc** (p. 9, cité en entier ci-dessous §2) | `Personnage::estEnChoc()` | porté par `docs/plan-errata-2021.md` C1 (2026-10-01), qui est **revenu** sur l'arbitrage « chute » du 2026-09-06 — `docs/plan-glace-et-degats-mind.md` §5.1 est donc **périmé** sur ce point précis |
| **4 types de mercenaires de la boîte** (p. 37 : Crossbowman 75po, Halberdier 75po, Scout 50po, Swordsman 100po) | `MercenaireSeeder` : Arbalétrier (75po), Fauchard (75po), Éclaireur (50po), Estafier (100po) — **stats identiques, M/A/D/B/Mi et prix au point près** | `database/seeders/MercenaireSeeder.php:39-59` ; voir §1bis |
| **4 potions de la boutique** (p. 2) | `config/cartes.php` → `potions`, « Toutes portées » | `config/cartes.php:90-113` (Potion de rage guerrière, de régénération, de force glaciale, de peau de givre) |
| **3 artefacts nommés** (Amulet of the North, Ring of Warmth, Snowshoes of Speed) | `config/cartes.php` → `artefacts`, section « Portés » | `config/cartes.php:144` (Amulette du Nord), et portés au plan glace (Anneau de Chaleur, Raquettes de Vitesse) |
| 6 parchemins non nommés, tirage aléatoire, utilisables par tout héros | Couvert par le mécanisme générique de parchemins (19 cartes dérivées des sorts) | `config/cartes.php:296-311` — rien de spécifique à sourcer, le livret ne nomme aucun des six |
| Krag, Vilor, Kelvinos, Gothar — stats nommées | Citées et sourcées dans `reference/18_extensions.md` | confirmées ce jour contre le rendu, voir §2 |

### 1bis. Les mercenaires de la boîte sont déjà portés — et c'est confirmé croisé

Le livret lui-même confirme que les mercenaires sont un pool **partagé entre
boîtes** : *The Mage of the Mirror* p. 9 dit « Mercenaries may be hired […]
if they are available to you (**such as by owning the Frozen Horror Quest
Pack**) ». Notre `MercenaireSeeder` n'a **pas** de colonne `boite` sur les
mercenaires (contrairement aux monstres/terrains/mobiliers) : c'est
exactement ce que le livret décrit — un seul pool, pas un pool par boîte.
Rien à faire ici ; c'est une confirmation, pas un chantier.

⚠ **Une demi-règle manque** : « the cost to hire them is for one quest only
[…] they must pay the Mercenary's cost for each quest » (p. 11) et « A
Mercenary is controlled by the hero who hired them […] if a hero dies on a
quest, any Mercenary hired by that hero continues on the quest » — à vérifier
contre `GroupeMercenaire` (probablement déjà la règle, puisque c'est le
fonctionnement par défaut d'un engagement à la quête), pas un chantier de ce
plan.

## 2. Corrections à `reference/18_extensions.md` §The Frozen Horror

1. **Citation à corriger** : « Clarification des jets de défense multiples…
   (Frozen Horror, **p. 11**) » → c'est **p. 9**, règle 5 « Rule
   Clarifications » (relu sur `rendu/F5815_en-us_p05.png`, colonne de
   droite). Citation exacte : « *A hero rolls defend dice once for each
   attacking monster. […] A hero attacked by a monster with multiple attacks
   (such as the Polar Warbear), however, gets only 1 defend roll against
   that monster per turn, no matter how many of the monster's attacks are
   directed at the hero.* »
2. **Règle manquante dans la section** : le livret source **lui-même**,
   indépendamment d'*Against the Ogre Horde*, l'état de choc à 0 Mind — p. 9
   (relu PNG), section « 4. Mind Points » : « *When a hero reaches zero Mind
   Points, they are not dead but in shock. […] They roll only 1 red die to
   move, attack with only 1 combat die, and defend with only 2 combat dice.*
   » `docs/regles/sorts-dread.md` ne cite que la source *Against the Ogre
   Horde* p. 9 (doc 16 compilation d'erratas) ; *Frozen Horror* la porte tout
   autant et mérite sa propre citation, puisque c'est la boîte que ce plan
   couvre.
3. Section 5 de `reference/18` (nouvelles mécaniques) ne mentionne nulle
   part l'**immunité explicite des monstres** aux trois tuiles dangereuses —
   voir la divergence §3, lot A.

## 3. Ce qui reste réellement — par LOT

### Lot A — Deux divergences de fidélité NON déclarées (trouvées en relisant le livret en entier)

| # | Constat | Citation (relue PNG où noté) | Ce que ça demande |
|---|---|---|---|
| **A1** | **Les monstres sont explicitement IMMUNISÉS** à trois des quatre tuiles dangereuses du terrain de glace, et **interdits** d'une quatrième — notre couche `terrains` ne fait aujourd'hui **aucune** distinction héros/monstre : `FabriqueGrille::pour()` construit un seul tableau de coûts (`app/Partie/FabriqueGrille.php:229-232`) consommé identiquement par `Grille::casesAtteignables()/coutChemin()` pour un héros (`ResolveurTour.php:894,1045`) **et** pour un monstre (`ResolveurTour.php:9058,9560`, `MoteurDread.php:3334,3462`). Concrètement : un monstre qui traverse une Rivière gelée paie aujourd'hui 2 cases comme un héros et peut subir ses dégâts (`saignerSurRiviere()` ne filtre que sur `$personnage`, mais **rien n'empêche l'appel côté monstre d'exister ailleurs** — à vérifier précisément au lot), et une Glissière de glace ne lui est pas interdite. | *Slippery Ice* (p. 4, relu PNG) : « *Monsters are not affected by slippery ice.* » — *Ice Slide* (p. 5, relu PNG) : « *Monsters cannot move onto ice slide squares.* » — *Ice Vault* (p. 6, relu PNG) : « *Monsters are not affected by the heat-draining property of this room.* » — *Icy River* (p. 6, relu PNG) : « *Monsters suffer neither movement penalties nor damage from the icy river.* » | Un lecteur unique ne suffit plus : `FabriqueGrille::pour()` doit produire soit deux tableaux de coûts (héros/monstre), soit un coût conditionnel lu avec le type d'occupant — **une règle, un point de passage** à réinterroger, puisque le point de passage actuel ne sait pas qui le traverse. Pour l'Ice Slide, il faut en plus une **interdiction de case** (pas seulement un coût), sur le modèle de ce qu'`estTraversable()` fait déjà pour un mur, mais conditionné au type d'occupant. |
| **A2** | **L'Ours polaire de guerre n'implémente pas sa capacité sourcée.** La carte/le tableau dit : « *The Polar Warbear attacks once with its mighty paw and once with its spiked mace. Two attacks can be made against one opponent or one attack can be made against each of two different opponents* » (p. 37) — le joueur MJ choisit librement la répartition, 1 ou 2 cibles, à chaque tour. Notre moteur lui donne à la place `choix_attaque` (`MonstreSeeder.php:203-206`) : une mécanique **à nous**, conçue pour un autre monstre (3.7, « coup massif unique si cible robuste, double compte si cible affaiblie, décision 100% moteur »), qui ne répartit JAMAIS sur deux cibles et retire au MJ le choix que la carte accorde. | p. 37 (relu PNG — tableau des monstres), citation ci-dessus | **Question pour René (Q1 §4)** : porter la vraie mécanique (attaque sur 1 OU 2 cibles adjacentes, décision moteur ou IA habillage — jamais un choix libre du joueur, cf. « le moteur résout tout ») demande un nouveau mot-clé `attaques_multiples_cibles` (nombre d'attaques, cibles possibles = adjacentes), distinct de `choix_attaque`. Le lot est petit : une capacité, un lecteur, pas de nouvelle donnée de carte. |

### Lot B — La quête double 9 & 10 (« The Heart of Ice »)

**Mécanique officielle, citée en entier** (p. 31, relu dans le texte
extrait, cohérent avec le rendu des pages voisines) :

> « *Zargon, Quests 9 and 10 are actually one double-sized quest. Notes A
> through F refer to the Quest 9 map; notes G through M refer to the Quest
> 10 map. The heroes will be moving back and forth between these two
> quests. Mind and Body Points are not restored when the heroes cross
> between Quests 9 and 10. Reset the gameboard (as described in Note A) when
> the heroes cross between quests.* »

Et le mécanisme de passage (note A, p. 31) : « *This is the spiral stairway
that leads to room "G" in Quest 10. When a hero moves onto the stairway to
enter Quest 10, remove that hero's figure from the gameboard. […] Once all
heroes have moved onto the stairway, remove the Quest 9 setup from the
board. When the heroes return to Quest 9, set out only room "A'" until they
explore other rooms. Monsters they killed previously do not reappear.* »

C'est le **même chantier structurel** que `reference/18_extensions.md`
nomme déjà pour cette boîte (§5, « Quête double reliant deux cartes ») et
pour *The Mage of the Mirror* (quête miroir 9-10, voir
`docs/plan-mage-du-miroir.md` §3 lot C) : **notre modèle suppose une quête =
une carte**. Les deux boîtes partagent exactement le même besoin :

- deux cartes **simultanément ouvertes** pour un même groupe (deux
  `salles_decouvertes` actives, ou une carte double avec un pont logique
  entre deux sous-grilles) ;
- un état de personnage qui **bascule** entre les deux sans restauration de
  Body/Mind au passage ;
- un **retour partiel** : à l'aller, la carte 9 est démontée ; au retour, la
  consigne officielle est de ne remonter que la salle de jonction (« set out
  only room "A'" ») et de ne jamais refaire réapparaître un monstre déjà
  vaincu ailleurs sur la carte.

⚠ Ce chantier **dépasse le périmètre d'une seule boîte** : il mérite un plan
dédié qui couvre les deux cas à la fois (Frozen Horror ET Mage of the
Mirror), plutôt que d'être résolu deux fois en divergeant. Voir §4 Q2.

### Lot C — Ce que les dix quêtes apportent au générateur (catégorie b)

Les dix quêtes sont des donjons **imprimés** et nous n'en jouons aucun tel
quel. Ce qu'elles donnent au générateur procédural :

| Élément de quête | Ce qu'il apporte |
|---|---|
| **Escorte d'un PNJ captif** (Gothar, quête 3, p. 19 : « *Any monsters encountered attack only the Barbarian, as they are under orders to capture Gothar alive. If the Barbarian dies, Gothar is automatically captured.* ») | Un **gabarit d'objectif** réutilisable : libérer un allié captif et le ramener à la sortie, les monstres ciblant le héros en priorité, capture automatique si le héros meurt. **Même famille que le Prospecteur / la Princesse Millandriel de *The Mage of the Mirror*** (`docs/plan-mage-du-miroir.md` §3 lot D) — un seul gabarit `objectif: liberer_captif` couvrirait les deux boîtes plutôt que d'en écrire deux. Aucun lecteur de ce type n'existe aujourd'hui (`GabaritQueteSeeder` n'a pas d'objectif de cette famille — seuls `vaincre_sous_boss`/`vaincre_boss`/exploration existent, à vérifier précisément) |
| **Salle de brouillard vivant** (Living Fog Room, q. 7, p. 27) : « *On a roll of a black shield or skull, the hero is confused and attacks a fog likeness. […] Only on a roll of a white shield does the hero see a real monster.* » | Déjà nommée dette dans `reference/18` et `docs/plan-glace-et-degats-mind.md` §Phase 4 (« hors périmètre, aucune couture n'existe ») — confirmé toujours vrai, rien de neuf |
| **Cible destructible avec explosion de zone** (le Sceptre, q. 8, p. 29) : « *a hero must be adjacent to it, attack it, and roll at least one skull […] The scepter explodes when it is destroyed, inflicting 2 Body Points of damage to all in the room.* » | Dette déjà nommée (plan glace §Phase 4) — confirmée, un décor-cible n'existe pas dans le modèle actuel (seuls héros et monstres sont des cibles valides) |
| **Rebord de crevasse à mort permanente** (q. 4, note E, p. 21, citée en entier) : jets en cascade, un second bouclier noir fait tomber le héros « *never to be seen again* » | Dette déjà nommée et **confirmée hors périmètre** (le moteur n'a aucune mort permanente, arbitrage de René) — rien de neuf, la citation complète confirme juste que `terrains.effet` du Rebord de crevasse (`decor: true`, sans mécanique) est le bon choix et pas un oubli |
| Trésors chiffrés par quête (or, potions, parchemins aléatoires) | Déjà couvert par le deck de fouille générique — rien à sourcer boîte par boîte |

### Lot D — Petite dette résiduelle de la couche terrain (déjà identifiée, pas nouvelle)

`docs/plan-glace-et-degats-mind.md` §Ce qui reste cite encore comme
« portées » : Chaleur, Anneau de Chaleur, Raquettes de Vitesse — **confirmé
exact** (§1 ci-dessus). Restent, au registre `config/cartes.php`, Pont de
Glace et Patinage côté HÉROS, derrière le ciblage de zone héros
(`MotsClesSort::CIBLE_MONSTRES_ZONE`) — **mécanique transversale, pas
spécifique à cette boîte**, hors périmètre de ce plan (elle débloquerait
aussi Morsure du Froid et le Brassard de Glace, des sorts non liés à Frozen
Horror seul).

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | L'Ours polaire de guerre (A2) : porter sa vraie capacité (2 attaques, 1 ou 2 cibles) à la place de `choix_attaque`, ou documenter `choix_attaque` comme divergence assumée dans `BestiaireSourceTest` ? | Porter : c'est une carte sourcée avec un texte clair, et `choix_attaque` n'est utilisé que par ce seul monstre aujourd'hui — rien d'autre n'en dépend |
| **Q2** | La quête double 9-10 (lot B) : un plan dédié commun à Frozen Horror ET Mage of the Mirror (deux cartes ouvertes simultanément, bascule de personnage sans restauration), ou porté une fois pour chaque boîte séparément ? | Plan commun — c'est littéralement le même mécanisme (texte quasi identique dans les deux livrets), et le dupliquer romprait « une règle, un point de passage » |
| **Q3** | L'immunité des monstres au terrain dangereux (A1) : un chantier qui touche `FabriqueGrille::pour()`, donc le point de passage central de toute la couche terrain — à traiter isolément ou avec la Rivière Gelée déjà posée comme le poste le plus risqué du plan glace ? | Isolément, et vite : c'est un défaut de règle **en l'état actuel du jeu**, pas une nouvelle fonctionnalité — un monstre placé sur une case de glace aujourd'hui se comporte hors des règles sourcées |
| **Q4** | Le gabarit « libérer un captif » (lot C, Gothar / Prospecteur / Princesse) : vaut-il la peine pour deux boîtes seulement, ou on attend une troisième occurrence avant de l'écrire comme gabarit générique ? | L'écrire maintenant : les deux occurrences ont déjà la même forme exacte (capture automatique à la mort du héros, ciblage prioritaire des monstres sur l'escorteur), ce qui est le signe qu'une généralisation ne devinerait rien |

## 5. Sources à demander (photos des cartes)

Les **35 cartes** de la boîte — en priorité basse, puisque §1 montre que
l'essentiel est déjà sourcé autrement :
- les cartes des 4 monstres et des 4 mercenaires, pour confirmer si elles
  portent des capacités au-delà du texte déjà capturé (notamment l'Ours
  polaire, Q1) ;
- les 6 parchemins non nommés — sans objet : notre mécanisme générique de
  parchemins ne demande pas de les nommer individuellement.

## 6. Ordre proposé

1. **Lot A1** (immunité monstre/terrain) — un défaut de règle actif
   aujourd'hui dans une boîte déjà jouable, à corriger en priorité.
2. **Lot A2** (Ours polaire) selon Q1 — petit, autonome.
3. **§2** (corrections `reference/18`) — aucun code, à faire en même temps
   que la relecture.
4. **Lot B** (quête double) selon Q2 — le plus gros morceau, probablement un
   plan séparé partagé avec Mage of the Mirror.
5. **Lot C** (gabarit captif) selon Q4, une fois le second cas (Mage of the
   Mirror) confirmé dans le même plan.

Chaque lot suit l'ordre maison : vocabulaire fermé → lecteur → test EN JEU →
données, registres testés dans les deux sens, `sauvegarder.sh` avant toute
migration, aucune purge de `groupes`/`personnages`/`joueurs`, redémarrer
`queue` et `queue-jeu` après le PHP.
