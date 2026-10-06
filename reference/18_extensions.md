# 18 — Inventaire des extensions HeroQuest (2021-2025, Avalon Hill)

Ce document recense le contenu mécanique (héros, monstres, objets, mobilier,
règles) des extensions HeroQuest parues chez Avalon Hill (relance 2021+),
en vue d'une intégration au moteur déterministe. **Source : livrets PDF
officiels téléchargés depuis `instructions.hasbro.com`** (livret de quêtes
et, quand il existe, livret de règles séparé). Aucune valeur n'est complétée
de mémoire — une case vide ou marquée « ⚠ » signale une donnée que le PDF
ne fournit pas ou un livret introuvable.

**Limite connue (déjà rencontrée pour `16_armurerie.md`) :** les livrets
renvoient fréquemment à des composants cartonnés (fiches de monstres,
plateaux de personnage) qui ne sont **pas** reproduits dans le PDF
d'instructions — celui-ci contient le texte des quêtes et parfois les
cartes, mais pas toujours les tableaux de caractéristiques. Quand un
monstre ou un héros est mentionné sans statistiques chiffrées dans le
texte, c'est noté explicitement plutôt que deviné.

Méthode : téléchargement de la page produit `instructions.hasbro.com`
(vérifiée sur les variantes `en-us`, `en-ca`, `en-gb` quand elles existent
— la même page ne sert pas toujours le même PDF), extraction du texte des
PDF liés avec `pypdf`, et rendu en image des pages contenant des tableaux
pour lecture visuelle quand le texte seul ne suffit pas.

---

## Kellar's Keep (2021)

**Source :** `HEROQUEST_KELLARS_KEEP.pdf` (livret de quêtes unique, réf.
F4543, 32 pages imprimées / 17 pages PDF), page produit `en-us` uniquement
(pas de variante `en-ca`/`en-gb` distincte pour cette boîte — seules
`de-de`, `fr-ca`, `fr-be`, `el-gr`, `it-it` existent en plus). Aucun livret
de règles séparé : la boîte « requires HeroQuest Game System to play » et
ne contient qu'un livret de quêtes (10 quêtes).

### 1. Nouveaux héros jouables

⚠ **Aucun.** Le contenu de la boîte (« 17 miniatures : 8 orcs, 6 gobelins,
3 abominations », tuiles, portes, 14 cartes) ne comprend ni fiche de
personnage ni classe de héros nouvelle. Les quêtes se jouent avec les 4
héros du jeu de base (Kellar's Keep, p. 4).

### 2. Nouveaux monstres

- **Abomination** — nouveau type de monstre (3 figurines dans la boîte),
  absent du bestiaire de base *sourcé par ce livret-ci* (⚠ corrigé le
  2026-10-05 : elle appartient en réalité au bestiaire standard de la
  réédition 2021 elle-même — voir §Composition physique, plus haut dans ce
  fichier, et §6 Tableau de synthèse). Aucune statistique chiffrée
  (Move/Attack/Defend/Body/Mind) n'apparaît dans CE livret de quêtes : le
  texte renvoie au « monster chart » du jeu de base sans le reproduire
  (Kellar's Keep, p. 4, 8, 14). René a depuis scanné la carte « Abomination »
  elle-même (© 2021, `reference/20_cartes_monstres.md`) : 6/3/3/2/3, aucune
  capacité — semée dans `MonstreSeeder` (`tier => 'base'`, `boite => 'base'`).
- **Squelettes des rois nains** (variante nommée, quête 5 « Hall of the
  Dwarven Kings ») — seul monstre de cette boîte avec un tableau de stats
  explicite dans le texte : **Move 6 · Attack 3 · Defend 4 · Body 2 ·
  Mind 0** (Kellar's Keep, p. 16-17). Ils ne bougent/n'attaquent qu'après
  qu'un premier squelette du groupe a été attaqué.
- **Gragor** (magicien Dread nommé, quête 6) — mêmes stats qu'un Dread
  warrior de base, + sorts *summon orcs, fear, rust, ball of flame,
  lightning bolt* (Kellar's Keep, p. 18-19).
- **Ograk** (chef orc nommé, quête 7) — mêmes stats qu'un Dread warrior de
  base ; peut apparaître par une porte secrète placée à tout moment par le
  MJ (Kellar's Keep, p. 20-21).
- **Borokk** (sorcier Dread nommé, quête 9) — mêmes stats qu'un Dread
  warrior, + capacité spéciale unique : à chaque tour, attaque l'esprit
  d'un héros dans la même salle/couloir et en ligne de vue en lançant
  **2 dés de combat** ; chaque crâne retire **1 point de Mind** à la
  cible (mort si Mind atteint 0, sauf Élixir de Vie) (Kellar's Keep, p.
  24-25).
- **Monstre métamorphe** (quête 9, non nommé) — se présente d'abord comme
  une abomination ; à chaque fois qu'il est tué, on mélange les cartes de
  monstres et on tire la nouvelle forme qu'il prend. Il n'est
  définitivement mort que si la carte tirée correspond à sa dernière forme
  (Kellar's Keep, p. 24-25).
- **Gargouille-statue** (piège, quêtes 1, 7, 10) — se fait passer pour une
  statue immobile ; ne peut être blessée tant qu'elle n'a pas bougé ou
  attaqué. Celle de la quête 10 (gardienne de Grin's Crag) est en plus
  **immunisée à tous les sorts** et a **4 Body Points** (au lieu des stats
  de base) (Kellar's Keep, p. 4, 20, 26-27).

Grande figurine : non précisé pour ces monstres (pas de mention explicite
de socle large dans le texte).

### 3. Nouveaux objets, artefacts, sorts

**Boutique de l'Alchimiste** (achats entre quêtes uniquement, Kellar's
Keep, p. 2) :
- *Potion of Restoration* — 500 po — restaure 1 Body Point et 1 Mind Point perdus.
- *Venom Antidote* — 300 po — soigne jusqu'à 2 Body Points de dégâts causés
  **uniquement** par les aiguilles/fléchettes empoisonnées.
- *Potion of Dexterity* — 100 po — ajoute 5 cases de déplacement au
  prochain jet, ou garantit un saut de fosse réussi ; 1 seule potion
  utilisable par tour même si plusieurs sont achetées.
- *Potion of Battle* — 200 po — permet de relancer une fois les dés
  d'attaque après un mauvais jet.

**Artefacts** (Kellar's Keep, p. 28-29 ; page 15 du PDF) :
- *Fire Ring* — protège des 2 prochains sorts de feu Dread subis, puis disparaît.
- *Magical Throwing Dagger* — inflige toujours 1 Body Point à un monstre visible
  quand elle est lancée (le monstre ne défend pas) ; perdue une fois lancée.
  (2 exemplaires trouvables, quête 2.)

**8 parchemins de sort** (utilisables par n'importe quel héros qui les
trouve, tirés au hasard, à usage unique — Kellar's Keep, p. 28-29) :
*Heal Body* (soigne jusqu'à 4 Body Points), *Tempest* (un monstre choisi
passe son prochain tour), *Ball of flame* (2 Body Points de dégâts, réduits
de 1 par 5/6 obtenu sur 2 dés rouges lancés par le monstre), *Courage*
(2 dés de combat supplémentaires à la prochaine attaque du porteur),
*Fire of wrath* (1 Body Point de dégâts sauf 5/6 sur 1 dé rouge),
*Sleep* (endort un monstre — inefficace sur momies/zombies/squelettes,
brisé si le monstre réussit un jet de Mind sur dés rouges),
*Rock skin* (1 dé de défense supplémentaire jusqu'au premier dégât subi),
*Genie* (ouvre une porte au choix ou attaque avec 5 dés de combat).

### 4. Nouveau mobilier, nouvelles tuiles

Kellar's Keep, p. 3-5 : **Iron Entrance Door** (porte d'entrée par le bord
du plateau, remplace l'escalier en colimaçon de départ), **Wooden Exit
Door** (porte de sortie par le bord du plateau), **Cloud of Dread** (nuage
violet qui aveugle les héros à l'intérieur), **Weapons Forge** (tuile
forge naine), **Four-Part Stone Map** (4 fragments de carte à collecter,
objectif de la boîte), **Trap Doors** (paire de trappes reliées, téléporte
instantanément d'une trappe à l'autre), **Cliff Corridor** (couloir-falaise
« Grin's Crag »), **Giant Stone Boulder** (rocher qui roule dans un
couloir), **Short/Long Stairway** (escaliers de 3/5 cases), **Statue**
(décor, sert de camouflage à une gargouille).

### 5. Nouvelles mécaniques de règle

- **Portes secrètes contrôlées par le MJ** (notes « B » quête 1) : trois
  portes secrètes ne peuvent pas être trouvées par une fouille normale ;
  le MJ (Zargon) choisit de les ouvrir à tout moment en début de tour.
  *Coût moteur :* rupture du modèle « porte secrète = trouvée par fouille
  uniquement » — demanderait un déclencheur scripté indépendant de l'action
  du joueur, proche d'un événement temporisé/aléatoire côté MJ IA.
- **Trappes appariées (téléportation)** : marcher sur l'une déplace
  instantanément sur l'autre, avec un coût (1 dé de combat, perte d'1 Body
  Point sur un crâne) et fin de tour immédiate. *Coût :* facile à modeler
  comme un couple de cases spéciales avec effet de téléportation + fin de
  tour, similaire aux pièges actuels.
- **Rocher qui roule** (quête 3) : après un déclencheur de position, le MJ
  lance 2 dés rouges chaque tour pour faire avancer un rocher qui bloque
  définitivement un couloir et blesse (5 dés de combat, pas de défense) les
  héros touchés. *Coût :* mécanique d'entité mobile autonome sur plusieurs
  tours, distincte des pièges ponctuels actuels — plus proche d'un mini
  monstre scripté sans IA de ciblage.
- **Fouille limitée « un seul piège trouvable à la fois »** (note B quête 3) :
  dans un couloir à pièges multiples, une fouille ne révèle que le piège le
  plus proche ; le suivant n'est trouvable qu'après résolution du premier.
  *Coût :* variante mineure de la fouille de pièges déjà implémentée
  (limiter le nombre de résultats par fouille).
- **Monstre métamorphe** (quête 9) : à chaque mort, tire aléatoirement une
  nouvelle forme parmi les cartes de monstres ; mort définitive seulement
  si deux formes tirées de suite sont identiques. *Coût :* nécessiterait un
  monstre spécial dont la mort déclenche un remplacement pondéré — pas
  couvert par le modèle actuel où un monstre mort est retiré.
- **Portes qui ne s'ouvrent qu'à une condition** (ex. porte verrouillée
  nécessitant un jet de 2 dés rouges ≤ Body Points restants, porte magique
  qui refuse de bouger tant qu'un gardien n'est pas mort) : plusieurs
  variantes de portes conditionnelles au-delà de « verrouillée / secrète ».
  *Coût :* généraliser le modèle de porte à une condition d'ouverture
  paramétrable (jet de caractéristique, mort d'un monstre précis) plutôt
  que le binaire actuel.
- **Attaque mentale directe (Borokk)** : un monstre inflige des dégâts de
  Mind avec ses propres dés de combat, hors du système de sorts existant.
  *Coût :* le moteur gère déjà les dégâts de Mind via les sorts mentaux ;
  il faudrait l'autoriser aussi comme capacité d'attaque physique d'un
  monstre (branchement supplémentaire dans la résolution de combat).

---

## Return of the Witch Lord (2021/2022)

**Source :** livret de quêtes unique (réf. F4193, 32 pages imprimées / 17
pages PDF). Deux fichiers différents servis selon la région — `en-us`
héberge `HEROQUEST_RETURN_OF_THE_WITCH_LORD.pdf` (44 Mo), `en-ca`/`en-gb`
héborgent `F4193UU00_INST_HEROQUEST_WITCH_LORD_F21.pdf` (418 Mo, même
pagination — 17 pages confirmées par comptage — vraisemblablement un export
haute résolution du même livret) : contenu textuel identique, pas de
second document. Comme Kellar's Keep, requiert le jeu de base et ne
contient qu'un livret de quêtes (10 quêtes) — aucun livret de règles séparé.

### 1. Nouveaux héros jouables

⚠ **Aucun.** Les 4 héros de base sont réutilisés ; la boîte ne contient que
16 figurines de monstres (8 squelettes, 4 momies, 4 zombies), pas de
nouvelle classe.

### 2. Nouveaux monstres

Aucun nouveau *type* de monstre (uniquement squelettes/zombies/momies déjà
au catalogue de base), mais plusieurs variantes nommées avec statistiques
explicites dans le texte des quêtes :

- **Spirit Riders** (variante de squelettes, quête 2) — **Move 8 · Attack 4
  · Defend 4 · Body 3 · Mind 3** (Witch Lord, p. 11).
- **Bellthor** (gargouille nommée, boss de quête 5) — **Move 6 · Attack 4 ·
  Defend 6 · Body 3 · Mind 3** ; ne bouge/n'attaque pas tant que tous les
  héros ne sont pas entrés dans la salle ; ne peut être blessée avant
  d'avoir attaqué ; souffle empoisonné (6 dés de combat, 1 Mind Point perdu
  par crâne, KO à 0 Mind au lieu de mort) ; explose à sa mort et assomme
  toute la salle — fin de quête scriptée sans issue (Witch Lord, p. 17).
- **Skulmar** (chef de la Légion Oubliée, quête 7) — **Move 8 · Attack 5 ·
  Defend 6 · Body 3 · Mind 4** ; tente de fuir vers l'escalier en spirale si
  très affaibli (Witch Lord, p. 21).
- **Doomguard** (gardes Dread élite, quêtes 9-10) — **Move 8 · Attack 4 ·
  Defend 6 · Body 3 · Mind 3** (Witch Lord, p. 25).
- **Kessandria, la Reine Sorcière** (boss de quête 9) — **Move 6 · Attack 4
  · Defend 6 · Body 3 · Mind 4** ; **immunisée à tous les sorts sauf ceux de
  feu** ; connaît *lightning bolt, tempest, fear, sleep, cloud of Dread* ;
  possède une *potion of speed* (12 cases de déplacement) ; fuit par une
  porte secrète si très affaiblie (Witch Lord, p. 25).
- **Le Seigneur Sorcier (Witch Lord)** — boss final, quête 10, stats
  **relevées** par rapport au jeu de base : **Move 10 · Attack 5 · Defend 6
  · Body 4 · Mind 5** ; ne peut être blessé que par 4 sources précises
  (Spirit Blade, sorts *fire of wrath*/*ball of flame*, Magical Throwing
  Dagger) ; connaît *summon undead, firestorm, tempest, lightning bolt,
  fear, command* (Witch Lord, p. 27).
- **4 statues d'orcs magiques** (quête 10) — ne bougent pas, n'attaquent
  pas, ne peuvent être blessées ; bloquent totalement le couloir (infranchissables) ;
  toute attaque contre elles **casse l'arme de l'attaquant** (y compris la
  dague magique ou l'arbalète), sauf la Spirit Blade qui est trop puissante
  pour casser (mais ne blesse pas la statue non plus) (Witch Lord, p. 27).

Grande figurine : non précisé.

### 3. Nouveaux objets, artefacts, sorts

**Boutique de l'Alchimiste** — identique à Kellar's Keep : *Potion of
Restoration* (500 po), *Potion of Dexterity* (100 po), *Venom Antidote*
(300 po), *Potion of Battle* (200 po) (Witch Lord, p. 2).

**5 artefacts** (Witch Lord, p. 7, 29) :
- *Magical Throwing Dagger* — identique à Kellar's Keep.
- *Dust of Disappearance* — permet de passer à travers les monstres
  rencontrés au tour suivant ; usage unique.
- *Anti-Poison Quill* — restaure les Body Points perdus par empoisonnement
  si utilisée immédiatement ; usage unique.
- *Rabbit Boots* — permet de sauter un piège découvert par tour (jet de 1 dé
  de combat, échoue seulement sur un bouclier noir, auquel cas le piège se
  déclenche normalement).
- *Arm Band of Healing* — restaure 2 Body Points une fois par quête ; peut
  être utilisé immédiatement si le porteur tombe à 0 Body Points.

**5 parchemins de sort** trouvables (utilisables par tout héros, usage
unique) : *Heal Body*, *Pass through rock* (traverse les murs sur tout le
déplacement du jet, danger de rester bloqué dans la roche massive indiquée
sur la carte), *Ball of flame*, *Courage*, *Fire of wrath* — les 4
derniers identiques en effet à ceux de Kellar's Keep.

**Objet notable :** *vial of holy water* (quête 2) — détruit un squelette,
zombie ou momie normal au contact ; usage unique, non repris comme carte
artefact séparée (texte de quête uniquement).

### 4. Nouveau mobilier, nouvelles tuiles

Witch Lord, p. 3-4 : **4 tuiles cercueils** (Coffins — tombeaux pouvant
contenir un mort-vivant, un trésor et/ou un piège), **Revolving Room**
(salle rotative recouvrant 2-3 salles), **Death Mist** (tuile de brume
mouvante), **Throne Room** (grande salle du trône), **Bone Pile** (décor),
**Iron Entrance Door**, **Wooden Exit Door**, 4 tuiles portes secrètes, 2
tuiles fosses, 6 cases bloquées.

### 5. Nouvelles mécaniques de règle

- **Salle rotative (Revolving Room)** : recouvre plusieurs salles ; à
  chaque sortie, un jet de 1 dé rouge détermine par quelle porte (1-4) le
  héros sort réellement. *Coût :* mécanique de sortie aléatoire déconnectée
  du choix du joueur — nécessiterait une résolution serveur qui retire le
  contrôle de la destination au joueur après son choix de sortie.
- **Death Mist (danger mobile autonome)** : tuile qui se déplace jusqu'à 6
  cases par tour du MJ dans un couloir défini, inflige 1 Body Point à tout
  héros traversé, insensible aux armes normales (seulement à un sort précis
  ou à un artefact précis). *Coût :* même famille que le rocher de Kellar's
  Keep — entité de terrain autonome multi-tours, absente du moteur actuel.
- **Immunité conditionnelle** (Kessandria immunisée à tous les sorts sauf
  le feu ; le Seigneur Sorcier blessable par seulement 4 sources nommées ;
  statues immunisées à tout mais qui cassent l'arme de l'attaquant ; Death
  Mist insensible aux armes) : plusieurs formes de résistance sélective
  bien au-delà du binaire actuel (vulnérable/invulnérable). *Coût :*
  généraliser la résolution de dégâts à une liste blanche/noire de sources
  valides par monstre, y compris un effet secondaire négatif pour
  l'attaquant (arme détruite).
- **Split-party scripté avec inventaire confisqué puis restitué** (quête 6) :
  deux héros démarrent séparés des deux autres, sans leur équipement/or (mis
  de côté puis récupérable dans un coffre), et ne peuvent agir tant qu'un
  déclencheur n'a pas eu lieu. *Coût :* rupture du modèle « tous les héros
  actifs dès le début de la quête » — demanderait un état d'activation
  différé par personnage et une confiscation/replacement d'inventaire
  scriptée, similaire en ampleur au système de dons d'objets mais côté MJ.
  proche d'un event scripté plutôt qu'une règle générique.
- **Fin de quête sans sortie (capture totale)** (quête 5) : la quête se
  termine par la capture scriptée du groupe (mort de Bellthor = gaz qui
  assomme tout le monde), sans état de victoire/sortie classique — enchaîne
  directement sur la quête suivante. *Coût :* le moteur suppose qu'une
  quête se termine par une sortie ou une victoire ; il faudrait un
  troisième état de fin « capturé, enchaîne sur la quête N+1 sans retour au
  hub ».

---

## Against the Ogre Horde (2024 ; annoncé en 2023)

**Source :** livret de quêtes unique, réf. F9528
(`F9528UU00_527014_HeroQuest_OGRE_HORDE_I.indd`), 44 pages PDF / pages
imprimées 1-43, page produit `en-us`. 10 quêtes, dont les 3 premières se
jouent dans un mode « tournoi » séparé (World's End Tournament). C'est de
loin la boîte la plus riche en mécaniques nouvelles rencontrée jusqu'ici.
Table de statistiques des monstres (« Monster Chart », Ogre Horde, p. 41)
et roster du tournoi (p. 16) **vérifiés visuellement sur le rendu PNG de la
page** — l'extraction texte brute désalignait les colonnes du tableau.

### 1. Nouveaux héros jouables

**Le Druide est le héros de cette boîte** — la liste de contenu (p. 3) nomme
une figurine « **Druid Hero** », et le héros existe chez nous (classe `druide`,
fiche et sorts sourcés sur le Mythic Tier, §HasLab Mythic Tier). ⚠ Corrigé le
2026-10-02 (lot A, `docs/plan-ogre-horde.md`) : cette section affirmait
« aucune classe identifiée ». Sa carte de personnage et ses cartes de sort ne
sont **pas dans le livret** (cartes physiques, « 29 game cards », p. 3) : on
présume qu'elles reprennent le Mythic Tier, sans l'avoir vérifié.

Les **3 loups** (p. 3) sont les **alliés animaux** de la boîte (p. 9), et non
des adversaires — même carte que notre *Loup* (`MercenaireSeeder`, © 2023) ?
⚠ non vérifié, la carte de cette boîte n'est pas photographiée.

### 2. Nouveaux monstres

**Table officielle** (« Ogres in Against the Ogre Horde — Monster Chart »,
Ogre Horde, p. 41 ; colonnes Movement/Attack/Defend/Body/Mind, confirmée
par rendu visuel de la page) :

| Monstre | Move | Attack | Defend | Body | Mind |
|---|---|---|---|---|---|
| Ogre Warrior | 6 | 5 | 4 | 5 | 1 |
| Ogre Champion | 6 | 5 | 4 | 6 | 1 |
| Ogre Commander | 4 | 6 | 5 | 6 | 2 |
| Ogre Lord | 4 | 6 | 6 | 10 | 5 |

Le roster du tournoi (p. 16, mode World's End Tournament) donne en plus une
version « équilibrage tournoi » des monstres déjà connus, cohérente avec
notre catalogue actuel pour le Gobelin (10/2/1/1/1) et l'Orque (8/3/2/1/2)
mais avec des variantes propres à ce mode pour les autres — Zombie **Move
5** notamment, contre 6 dans notre catalogue actuel — donc à ne pas prendre
comme remplacement, seulement comme référence de tournoi séparée
(Move/Mind/Defend/Body/Attack) : Squelette 6/0/2/1/2, Zombie 5/0/3/1/2,
Momie 4/0/4/2/3, **Abomination 6/3/3/2/3** (première fois, dans CE livret-ci,
que ce monstre de Kellar's Keep obtenait des stats chiffrées dans un livret
officiel — ⚠ **recoupé le 2026-10-05** : la carte « Abomination » elle-même
(© 2021, `reference/20_cartes_monstres.md`) donne EXACTEMENT le même chiffre,
cette fois sans réserve de prudence — table de tournoi et carte s'accordent),
Guerrier du Chaos (Dread Warrior) 7/3/4/3/4, Gargouille 6/4/5/3/4.

**Versions à distance (Ranged)** — nouvelle variante générique : Zargon
peut placer un squelette, un orque ou un gobelin « à distance » à la place
du modèle standard ; ce monstre lance ses dés d'attaque habituels contre
une cible non adjacente en ligne de vue, ou 1 seul dé si la cible est
adjacente (Ogre Horde, p. 8). Stats identiques au monstre standard dans le
roster du tournoi.

**Adversaires nommés (bosses) :**
- **Doralf** (chef pit-fighter, quête 2) — **Attack 6 · Defend 5 · Move 6 ·
  Body 7 · Mind 3** (Ogre Horde, p. 23).
- **Gruzbella Hammerhand**, Maître de Combat du tournoi (quête 3) — monstre
  **multi-phase** à 3 formes successives, chacune remplaçant la précédente
  quand les Body Points tombent à 0 (la figurine n'est jamais retirée avant
  la 3ᵉ phase) :
  - *Gruzbella la Confiante* — Attack 4 · Defend 6 · Move 5 · Body 5 · Mind 4
  - *Gruzbella la Déterminée* — Attack 5 · Defend 5 · Move 7 · Body 5 · Mind 4
  - *Gruzbella l'Imprudente* — Attack 6 · Defend 1 · Move 8 · Body 5 · Mind 4
  - 3 capacités à usage unique chacune, utilisables à tout moment sans
    action : **Break** (annule un sort actif sur elle), **Resilience**
    (ignore tous les dégâts d'une attaque), **Deflect** (redirige une
    attaque la ciblant vers un héros dans les 10 cases environnantes)
    (Ogre Horde, p. 25).
- **Xenloth le Mage Dread** (quête 9) — **Attack 2 · Defend 4 · Move 6 ·
  Body 1 · Mind 4** ; connaît *Mind Lock* et *Mind Burst*, 5 cartes de
  chaque (Ogre Horde, p. 37).
- **Ekur, l'Ogre Lord** (boss final, quête 9) — utilise les stats « Ogre
  Lord » du tableau ci-dessus. **Tograk** (quête 6) est un Ogre Commander.
- **Spawn of the Pit** (quête 1, dernière vague du tournoi ; exemple de la
  règle *Multi-Phase Enemies*, p. 6) — monstre à **deux phases** :
  - *Spawn of the Pit* — Attack 4 · Defend 3 · Move 6 · Body 4 · Mind 3
  - *Spawn of the Pit — Enraged* — Attack 5 · Defend 1 · Move 10 · Body 6 ·
    Mind 1 (adoptée quand la première phase tombe à 0 Body, la figurine
    restant sur le plateau).
- **Guardian Effigy** (quête 4, note D) — Attack 3 · Defend 5 · Move 0 ·
  Body 2 · Mind 0 ; effigie d'orque **immobile** gardant la porte de fer :
  chaque tour, une boule de feu à **3 dés d'attaque** sur un héros en ligne
  de vue ; **immunisée à tous les effets de sort** (p. 27).
- **Nexrael**, apprenti Sorcier du Dread (quête 6, note D) — Attack 3 ·
  Defend 4 · Move 8 · Body 1 · Mind 5 ; *Mind Burst*, réserve de 4 cartes
  (p. 31).
- **Festral**, Sorcier du Dread (quête 8) — Attack 4 · Defend 5 · Move 6 ·
  Body 3 · Mind 8 ; *Mind Burst*, *Mind Lock* et *Dominate*, 3 cartes de
  chaque ; ses Guerriers du Dread de garde lancent 5 dés d'attaque et 5 de
  défense (p. 35).

Blocs de stats **relus visuellement** sur le rendu PNG des pages le
2026-10-02 (l'extraction texte mélange les colonnes). Paliers arrêtés par
René (`docs/plan-ogre-horde.md` Q7) : boss Gruzbella et Festral ; sous-boss
Doralf, Spawn of the Pit, Xenloth et l'Effigie ; Nexrael au palier que
mesurera son `cout`.

⚠ **Capacités des ogres — DE NOUS, pas du livret.** Le livret ne donne aux
quatre ogres **aucune** capacité (p. 41 : stats seules). Notre `charge`
(Commandant), et `frappe_de_zone` + `resistance_magique` (Seigneur) sont des
mécaniques du moteur que nous leur avons attribuées. Leurs cartes de monstre
(physiques) n'ont été trouvées nulle part en ligne (recherche du 2026-10-02 :
Hasbro, Ye Olde Inn, BoardGameGeek — les seules transcriptions complètes
visent la version 1990 de Games Workshop/MB). René, Q5 : on les garde, et on
ne les présente pas comme sourcées.

Grande figurine : les Ogres (Warrior/Champion/Commander/Lord) occupent
vraisemblablement 2 cases comme dans le jeu de base (règle générique
« Large Monsters » ci-dessous s'applique), mais ⚠ non confirmé
explicitement pour chaque profil dans ce livret.

### 3. Nouveaux objets, artefacts, sorts

- **Bone Weapons** (armes en os) — identiques aux armes homonymes de
  l'armurerie de base, mais sans valeur en pièces d'or : ne s'achètent ni
  ne se vendent (Ogre Horde, p. 8).
- **Supply Crate** — le premier héros à fouiller une salle contenant ce
  coffre trouve 4 Potions de Soin, chacune restaurant 1 jet de dé rouge de
  Body Points (Ogre Horde, p. 5).
- **3 sorts Dread inédits**, lançables une fois par tour par un Sorcier
  Dread, jamais cumulables avec une attaque à l'arme le même tour (Ogre
  Horde, p. 10-11) :
  - *Mind Lock* — attaquant et défenseur lancent chacun autant de dés de
    combat que leurs Mind Points ; chaque crâne net de l'attaquant gèle la
    cible 1 tour (defend à 1 dé, aucune action) ; la cible tente de briser
    le sort en fin de tour gelé (3+ crânes sur un jet égal à ses Mind
    Points).
  - *Dominate* — même jet en opposition (Mind vs Mind) ; 2 crânes net ou
    plus = le MJ prend le contrôle complet du héros dominé pour un tour
    (mouvement + action, y compris attaquer d'autres héros).
  - *Mind Burst* — même jet en opposition ; la différence de crânes
    inflige des dégâts de **Mind Points** au perdant de l'opposition (peut
    donc blesser le lanceur si le défenseur fait mieux). Exemple du livret
    (p. 10) : un Sorcier à 5 Mind contre le magicien à 6, et « the Dread
    Sorcerer takes 2 Mind Points of damage ». ⚠ C'est le **premier
    producteur de dégâts de Mind côté MONSTRE** : il donne enfin une raison
    d'étendre l'état de choc aux monstres (« every creature »).
- ⚠ Coûts et détails des cartes Mercenaire ogre et Allié animal (voir
  mécaniques ci-dessous) non trouvés dans le texte — probablement sur des
  cartes physiques hors PDF.

### 4. Nouveau mobilier, nouvelles tuiles

Ogre Horde, p. 4-5 : **Stone Doorway** (dalle de pierre qui s'ouvre par un
jet des dés d'Attaque de base du héros, 2 crânes requis — reste ouverte le
reste de la quête ; le magicien, à 1 seul dé d'attaque, ne peut jamais
l'ouvrir seul), **Swinging Blade Trap** (piège de lame suspendue,
déclenché en case gold overlay, 2 dés d'attaque du MJ contre tout héros sur
une case marquée), **Pit of Darkness** (fosse plus profonde qu'une fosse
normale : dégâts de chute variables selon l'armure portée — 1 Body Point
sans armure/armure non-métallique, 2 avec armure métallique, 3 avec une
armure de plates), **Supply Crate**, **Throne**, **Double-door** (×2),
**Stone Doorway** (×4 en figurine cartonnée).

### 5. Nouvelles mécaniques de règle

- **Mouvement non menacé (Unthreatened Movement)** : sans monstre actif sur
  le plateau, un héros peut convertir chaque dé de mouvement en un 4 fixe
  plutôt que de lancer les dés. *Coût :* trivial — un simple raccourci
  optionnel côté résolution de déplacement, aucune donnée d'état
  supplémentaire.
- **Monstres de grande taille (Large Monsters)** : un monstre occupant 2
  cases peut attaquer toute créature dans les 10 cases qui l'entourent (et
  pas seulement les cases adjacentes). *Coût :* étendre le calcul de portée
  d'attaque au corps-à-corps pour les monstres à empreinte 2 cases —
  actuellement `MoteurCombat` suppose une portée adjacente standard.
- **Monstres multi-phases** : un monstre change de jeu de statistiques
  (parfois de nom) à 0 Body Points au lieu de mourir, sans perdre son statut
  de « même monstre » pour les effets de sorts ; un monstre soigné pendant
  une phase avancée ne revient pas à la phase précédente. *Coût :*
  changement substantiel — remplacer le modèle « monstre mort = retiré » par
  une machine à états (Body Points remis à un plafond de la nouvelle phase,
  jeu de dés remplacé en cours de combat), y compris pour des PNJ de type
  boss récurrent (Gruzbella).
- **Monstres à distance (Ranged variant)** : un même type de monstre existe
  en version « tir à distance » qui inflige ses dés d'attaque normaux à une
  cible non adjacente en ligne de vue (1 seul dé si la cible est adjacente).
  *Coût :* ajouter un mode d'attaque à portée pour des monstres du
  bestiaire de base qui n'en ont normalement pas, avec pénalité au contact —
  distinct du système d'armes à distance actuel qui est un attribut d'arme,
  pas de monstre.
- **Armes en os non économiques** : identiques à une arme existante mais
  sans prix, ni achetables ni vendables. *Coût :* négligeable — variante de
  catalogue avec `prix: null` et flag `non_echangeable`.
- **Mercenaires ogres (PNJ recrutables)** : un mercenaire disponible entre
  les quêtes, engagé contre un coût en or, agit sur le tour du héros qui
  l'a engagé jusqu'à la fin de la quête (ou sa mort/renvoi) ; peut être
  regardé pour la quête suivante à moitié prix ; ne peut porter ni objets ni
  or sauf mention contraire. *Coût :* nouvelle entité de jeu proche d'un
  héros simplifié contrôlé par un joueur mais hors du roster de personnage
  — état persistant inter-quêtes (engagé/renvoyé/mort), tour lié à un héros
  spécifique plutôt qu'un tour propre.
- **Alliés animaux** : recrutable gratuitement avant une quête si le groupe
  compte moins de 4 joueurs héros, contrôlé par le joueur qui l'a recruté,
  agit immédiatement après le tour de son héros allié, peut bouger/attaquer/
  défendre mais pas fouiller ni ouvrir de porte ni utiliser d'objets ; si le
  héros lié meurt, l'allié continue sous le contrôle du même joueur.
  *Coût :* même famille que les mercenaires — entité liée à un héros, tour
  scindé (deux « demi-tours » liés), condition d'apparition liée à la
  taille du groupe.
- **Points de Mind à 0 = état de choc** — **PORTÉ le 2026-10-01** (René, qui
  revient sur l'arbitrage du 2026-09-06 : « un héros à 0 Mind tombe »).
  Citation vérifiée, *Against the Ogre Horde* p. 9 (Hasbro PDF F9528, confirmée
  applicable à **toute créature** — héros, monstre, allié) : « When a creature
  reaches 0 Mind Points, they go into shock. While at 0 Mind Points, they can
  only roll one red movement die, 1 Attack die, and 2 Defend dice. (Armor,
  weapons, and artifacts do not increase the Attack or Defend dice while a
  hero is at 0 Mind Points.) The creature's Attack and Defend dice can be
  temporarily increased by some spells and spell scrolls. Additional Mind
  Points gained from certain artifacts may be lost. If the creature later
  restores Mind Points, they are no longer in shock and can roll dice as
  normal. A creature cannot go below 0 Mind Points. » État DÉRIVÉ
  (`Personnage::estEnChoc()`, pas une colonne), plafonné au moment du jet
  (`ResolveurTour::frapper()`, `MoteurSorts::desDefenseHerosDetail()`,
  `MenuMoteur::deplacementDuTour()`) — voir `docs/regles/sorts-dread.md`.
  ⚠ Portée SEULEMENT pour les HÉROS : aucun chemin ne réduit le Mind d'un
  monstre ou d'un allié (`GroupeMercenaire`) à ce jour, donc rien n'y lit
  `estEnChoc()` — pas de clé décorative, mais le point d'extension est nommé
  ici pour le jour où un producteur Mind côté monstre/allié apparaîtra. Les
  sorts type *Sleep* qui n'ont aucun effet sur les monstres sans Mind Points
  (ex. squelettes) restent une dette séparée, non couverte par ce lot.
- **World's End Tournament (mode de jeu séparé, quêtes 1-3)** : bataille par
  équipes (Challenger = héros + alliés, Defender = monstres) avec un
  système de **rounds d'activation** alterné (chaque camp active un membre
  non encore activé à tour de rôle, le camp à court d'effectifs enchaîne),
  un score de **puissance d'équipe** (1 + dé d'attaque le plus fort par
  héros) pour équilibrer les rencontres, des **jetons trophées** ramassés
  au sol et défaussables pour un bonus immédiat (vitesse, dé de combat
  bonus, soin, riposte gratuite), et une règle **combattant solo** qui
  donne une action bonus (mouvement ou 2 dés d'attaque) après chaque tour
  adverse dès que son équipe n'a plus qu'un membre. *Coût :* le plus gros
  chantier de cette boîte — un mode de combat entièrement différent du tour
  par tour classique (activation par équipe et non par personnage, jetons
  ramassables au sol avec effets à la carte, échelle de puissance pour
  générer un adversaire équilibré à la volée). Hors du modèle actuel
  « quête = donjon » ; s'apparenterait à un mini-jeu de combat autonome.
- **Fouille de trésor par jet de 2d6 avec table de résultats** (mode
  tournoi uniquement, Ogre Horde p. 13) : la fouille en tournoi ne pioche
  pas dans le deck habituel mais consulte une table 2-12 (pièges, or,
  arme). *Coût :* mineur si le mode tournoi n'est pas repris tel quel —
  sinon nécessiterait une table de résolution parallèle au deck de fouille
  existant.
- **Garde-fou optionnel « pas de blocage »** (Ogre Horde, p. 16) : règle
  maison suggérée pour éviter qu'un héros piégé dans une salle sans issue
  reste bloqué (tunnel de secours trouvable, allié animal qui prend sa
  place). *Coût :* nul, c'est une suggestion pour le MJ humain — pertinent
  seulement si le générateur de donjon peut produire une impasse, ce qui
  n'est plus le cas depuis la correction des boucles/portes secrètes.

---

## The Frozen Horror (2021/2022)

**Source :** livret de quêtes unique, réf. F5815, 40 pages imprimées / 21
pages PDF. Fichier initial 270 Mo — extraction texte directe impossible
(timeout), PDF **scindé en 8 tronçons** puis chaque tronçon extrait
séparément (`split2.py`) ; texte complet récupéré pour 20 pages sur 21 (la
page 20, « Design Your Own Quest », a échoué avec une erreur pypdf sur un
flux image déclaré trop long — page purement graphique, sans texte de
règle). 10 quêtes (3 solos + 5 de groupe + 1 quête double 9-10).

### 1. Nouveaux héros jouables

⚠ **Aucune nouvelle classe.** Le livret inclut un **Barbare alternatif**
(figurine + fiche de personnage bonus) mais précise explicitement
« *Their statistics are the same as the Barbarian in the HeroQuest Game
System* » (Frozen Horror, p. 9) — un simple doublon cosmétique, pas une
classe distincte ; un groupe ne peut avoir qu'un seul Barbare actif à la
fois.

### 2. Nouveaux monstres

**Monster Chart officiel** (Frozen Horror, p. 37) :

| Monstre | Move | Attack | Defend | Body | Mind |
|---|---|---|---|---|---|
| Frozen Horror (boss) | 8 | 5 | 4 | 6 | 4 |
| Ice Gremlin | 10 | 2 | 3 | 3 | 3 |
| Polar Warbear | 6 | 4/4 (2 attaques) | 3 | 6 | 2 |
| Yeti | 8 | 3 | 3 | 5 | 2 |

- **Frozen Horror** (grande figurine, boss final) — connaît 6 sorts Dread
  fixes (*Chill, Ice Storm, Ice Wall, Mind Freeze, Skate, Soothe*) **+ 6
  sorts Dread au choix du MJ** parmi tous ceux du jeu de base, sauf
  *Escape* (Frozen Horror, p. 37).
- **Ice Gremlin** — sur le tour du MJ, attaque **ou** vole un objet (jamais
  l'arme/armure/bouclier équipés) puis s'enfuit à pleine vitesse ; l'objet
  est perdu si aucun héros ne le voit au début du tour suivant du MJ.
- **Polar Warbear** — **2 attaques distinctes par tour** (paw + masse
  cloutée), réparties librement sur 1 ou 2 cibles adjacentes.
- **Yeti** — dès qu'il inflige au moins 1 Body Point, agrippe le héros
  dans une étreinte qui inflige 2 Body Points **automatiques** (sans jet de
  défense, sans action possible pour la victime) à chaque tour suivant du
  MJ, jusqu'à la mort du héros ou celle du Yeti — le Yeti ne peut alors
  faire aucune autre attaque.

**Adversaires nommés :**
- **Krag** (Guerrier Dread, quête 2) — Move 7 · Attack 5 · Defend 5 ·
  Body 4 · Mind 3 (Frozen Horror, p. 17).
- **Vilor** (Sorcier Dread, quête 5) — Move 8 · Attack 4 · Defend 3 ·
  Body 4 · Mind 5 ; connaît *Chill, Ice Storm, Lightning Bolt, Sleep,
  Tempest* (Frozen Horror, p. 23).
- **Kelvinos** (ancien héros Barbare, désormais mort-vivant, quête 7) —
  Move 5 · Attack 4 · Defend 4 · Body 4 · **Mind 0** (Frozen Horror, p. 27).
- **Gothar** (PNJ à escorter, quêtes 3) — Move 6 · Attack 1 · Defend 2 ·
  Body 2 · Mind 4 ; allié contrôlé par le Barbare une fois libéré, doit
  être ramené vivant à l'escalier en spirale.

**4 types de Mercenaires** (Mercenaries Chart, Frozen Horror, p. 37 ;
12 figurines au total) :

| Mercenaire | Coût/quête | Move | Attack | Defend | Body | Mind |
|---|---|---|---|---|---|---|
| Crossbowman | 75 po | 6 | 3 | 3 | 2 | 2 |
| Halberdier | 75 po | 6 | 3 | 3 | 2 | 2 |
| Scout | 50 po | 9 | 2 | 3 | 2 | 2 |
| Swordsman | 100 po | 5 | 4 | 5 | 2 | 2 |

Le Halberdier attaque en diagonale ; le Scout détecte/désamorce les pièges
comme un nain ; le Crossbowman passe à une épée large au contact.

Grande figurine : le Frozen Horror occupe plus d'une case (règle « Large
Monsters » ci-dessous s'applique explicitement à lui).

### 3. Nouveaux objets, artefacts, sorts

**Boutique de l'Alchimiste** — potions réservées au Barbare sauf mention
contraire (Frozen Horror, p. 3) :
- *Potion of Battle Rage* (400 po, Barbare seul) — 2 attaques par tour tant
  qu'un monstre reste en ligne de vue.
- *Potion of Rejuvenation* (500 po, tout héros) — restaure 1 jet de dé
  rouge de Body Points (plafonné au maximum de départ).
- *Potion of Icy Strength* (200 po, Barbare seul) — la prochaine attaque
  inflige le double des Body Points obtenus aux dés de combat.
- *Potion of Frost Skin* (300 po, Barbare seul) — +2 dés de défense tant
  qu'un monstre reste en ligne de vue.

**4 artefacts nommés** : *Amulet of the North*, *Ring of Warmth*,
*Armband of Ice*, *Snowshoes of Speed*. **6 parchemins de sort**, tirés au
hasard, utilisables par tout héros, usage unique (Frozen Horror, p. 11) —
⚠ Corrigé le 2026-10-05 : cette ligne disait « 3 artefacts... effets
détaillés sur cartes non capturées en texte » et « 6 parchemins non
nommés ». C'est inexact sur les deux points : il y a un **4ᵉ artefact**
(*Armband of Ice*, absent de `config/cartes.php` — à ajouter, voir
ci-dessous), et les 6 parchemins sont en fait **nommés et décrits en toutes
lettres** — texte extrait vide sur cette page (illustrations), d'où
l'erreur de lecture initiale. Les dix cartes sont reproduites en
intégralité dans le livret, page 35 (« Artifact Reference », page 18 du
PDF), relues sur le rendu PNG — voir transcription ci-dessous.

### Artefacts et parchemins TRANSCRITS — « Artifact Reference » (livret p. 35)

- **Amulet of the North** — « *This ancient magical item was made for
  Barbarian kings centuries ago. It may be worn only by a Barbarian. While
  worn, it adds 2 Body Points and 1 Mind Point to the Barbarian's totals.* »
- **Ring of Warmth** — « *This artifact grants its wearer immunity to the
  Chill spell, as well as to the effects of ice vaults and icy rivers.* »
- **Armband of Ice** — « *This powerful item grants its wearer immunity to
  Mind Freeze and Chill spells, plus immunity to damage from ice vaults and
  icy rivers for as long as it is worn. It also reduces the damage from an
  Ice Storm spell by 1 point for the armband wearer only.* » ⚠ Absent de
  `config/cartes.php` — les trois autres artefacts de cette page y sont
  (Amulette du Nord, Anneau de Chaleur, Raquettes de Vitesse), pas celui-ci.
- **Snowshoes of Speed** — « *These magical snowshoes add 2 squares to a
  hero's movement, and they also negate the effects of slippery ice for as
  long as they are worn. These can be used only on quests in cold, icy
  regions.* »
- **Spell Scroll — Chill** — « *This spell causes 1 Body Point of damage to
  any monster adjacent to the spellcaster (though not diagonally adjacent).
  The victim cannot defend against the attack. Scroll crumbles to dust once
  used.* »
- **Spell Scroll — Ice Storm** — « *This spell creates a blizzard of ice
  that affects an area 2 squares wide by 2 squares long. Each monster and
  hero in that area is attacked separately by the spellcaster with 3 combat
  dice. There is no chance to defend. Cannot be used in corridors. Scroll
  crumbles to dust once used.* »
- **Spell Scroll — Ice Bridge** — « *This spell creates a permanent bridge
  of Magic Ice tiles that enables heroes to cross over any pit, trap,
  chasm, crevasse, or icy square. Scroll crumbles to dust once used.* »
- **Spell Scroll — Psychic Recovery** — « *This spell restores all lost
  Mind Points to the spellcaster or any one hero the spellcaster chooses.
  Scroll crumbles to dust once used.* »
- **Spell Scroll — Skate** — « *This spell enables the spellcaster to move
  quickly through icy caverns and corridors. The spellcaster adds 6 to
  their red dice movement roll and may pass through monsters and heroes
  during movement. The spell lasts only one turn. Scroll crumbles to dust
  once used.* »
- **Spell Scroll — Warmth** — « *This spell bestows a healing warmth on the
  spellcaster or any one hero the spellcaster chooses. The warmth restores
  up to 3 lost Body Points. Scroll crumbles to dust once used.* »

→ chez nous : les 6 parchemins recoupent déjà le mécanisme générique
(dérivés des sorts, `config/cartes.php:296-311` — *Chill*/*Ice Storm* sont
des sorts du thème glace, *Psychic Recovery*≈Soin de l'Esprit, *Warmth*≈Soin
du Corps ; *Ice Bridge* et *Skate* recoupent des effets de terrain déjà
portés), rien à changer. Seul vrai trou : **Armband of Ice** n'a pas
d'entrée catalogue.

### 4. Nouveau mobilier, nouvelles tuiles

Beaucoup de décor thématique glace (Frozen Horror, p. 5-7) : **Ice Ledge**
(rebord de crevasse), **Slippery Ice** (case glissante placée seulement au
contact, jet de 1 dé de combat, bouclier blanc = chute et fin de tour
immédiate), **Magic Ice** (support du sort *Ice Bridge*/*Ice Wall*),
**Ice Tunnels** (paires de téléportation, très nombreuses dans cette
boîte), **Ice Slide** (glissière à sens unique, fin de tour, 1 Body Point
sur bouclier blanc), **Ice Vault** (salle qui inflige 1 Body Point par tour
passé dedans sur un skull), **Living Fog Room** (salle à monstres/leurres),
**Crystal Key Tile**, **Scepter Room**, **Bottomless Chasm Room** (fosse
mortelle sans limite), **Icy River** (coûte 2 cases de déplacement par
case, dégâts sur bouclier blanc), **Frozen Crypt Room**, **Cage Room**,
**Ice Gremlin Treasure Room**, **Seat of Power Room**, **Ice Cave
Entrance**.

### 5. Nouvelles mécaniques de règle

- **Clarification des jets de défense multiples** : un héros attaqué par
  plusieurs monstres distincts défend une fois par monstre attaquant, mais
  un monstre à attaques multiples (ex. Polar Warbear) ne provoque qu'**un
  seul** jet de défense quel que soit son nombre d'attaques (Frozen Horror,
  p. 9). ✅ Portée le 2026-10-04 avec la capacité `deux_attaques` de l'Ours
  (`ResolveurTour::deuxAttaques()` : sur une seule cible, une volée de 2×N dés
  contre un jet de défense).
- **Piège « Wandering Monster »**, **Stalactite Trap**, **Swinging Axe
  Trap** : trois pièges sans tuile physique, gérés uniquement par le texte
  de quête. *Coût :* négligeable, ce sont des variantes de pièges déjà
  supportés (dégâts fixes ou aléatoires, un seul avec embuscade de
  monstres attachée).
- **Salle de brouillard vivant (Living Fog Room)** : chaque attaque
  commence par un jet de 1 dé de combat — bouclier blanc seulement révèle
  un vrai monstre, sinon l'attaque est gaspillée sur un leurre. *Coût :*
  ajouter une couche de résolution « cible réelle vs illusion » avant la
  résolution de combat normale, spécifique à une salle.
- **Escorte de PNJ (Gothar)** : un allié contrôlé par un héros précis, doit
  survivre et atteindre une sortie pour valider la quête ; capturé
  automatiquement si le héros meurt. *Coût :* même famille que les PNJ
  alliés déjà rencontrés (Ogre Horde), mais avec condition de victoire liée
  à sa survie plutôt qu'à celle des héros.
- **Salle glissante avec risque de mort permanente (Bottomless Chasm /
  Ice Ledge)** : jets en cascade où un résultat défavorable répété fait
  tomber le héros dans un gouffre **sans retour possible** (perte définitive
  du personnage, pas une simple blessure). *Coût :* le moteur gère déjà la
  mort par combat/pièges, mais pas une perte de personnage par
  environnement pur sans jet d'attaque adverse — à vérifier contre la
  politique de mort permanente actuelle du moteur.
- **Cible fixe destructible (le Sceptre)** : un décor immobile avec ses
  propres « points de vie » implicites (détruit sur un skull), déclenchant
  une explosion de zone à sa destruction. *Coût :* modéliser un objet du
  décor attaquable séparément des monstres, avec un effet de zone à sa
  destruction — absent du modèle actuel où seuls les monstres et les héros
  sont des cibles valides.
- **Quête double reliant deux cartes (9 & 10)** avec passage par un
  escalier qui retire la figurine d'une carte pour la faire apparaître sur
  l'autre, sans restauration de Body/Mind entre les deux, et regénération
  partielle de la carte 9 au retour (seule la salle de résurgence est
  replacée, les monstres déjà vaincus ne réapparaissent pas). *Coût :*
  chantier significatif — le modèle actuel suppose une quête = une carte ;
  gérer un état de personnage qui bascule entre deux cartes ouvertes
  simultanément (même groupe, deux `salles_decouvertes` actives) est un
  changement structurel.
- **Mercenaires** : même mécanique que dans Against the Ogre Horde (PNJ
  loué à l'or, agit après le tour de son loueur, ne butine pas le trésor),
  ici avec un capital de 4 profils chiffrés et un rôle mixte (parfois
  adversaires contrôlés par le MJ selon la quête). *Coût :* voir Ogre
  Horde — potentiellement le même système, réutilisable entre boîtes.

---

## The Mage of the Mirror (2022/2023)

**Source :** livret de quêtes unique, réf. F7539, 40 pages imprimées / 21
pages PDF (244 Mo). Extraction directe interrompue après 10 pages ; le
reste (pages 11-21) a été récupéré en scindant le PDF (page 20, purement
graphique — légende « Design Your Own Quest » — a échoué sur une erreur de
décompression, sans perte de règle). 10 quêtes (3 solos jouables par un Elf
seul + 5 de groupe + 1 quête double 9-10).

### 1. Nouveaux héros jouables

⚠ **Aucune nouvelle classe.** La liste de contenu mentionne un miniature
« Elf hero » supplémentaire (doublon cosmétique du héros Elfe existant,
comme le Barbare alternatif de Frozen Horror) et un « Elven archmage »
(Sinestra, la méchante — statistiques de monstre, pas de héros). En
revanche, cette boîte introduit un **groupe de sorts alternatif réservé à
l'Elf** (voir mécaniques ci-dessous), qui change substantiellement son
gameplay sans changer sa fiche de personnage de base.

### 2. Nouveaux monstres

**Monster Chart officiel** (Mage of the Mirror, p. 37) :

| Monstre | Move | Attack | Defend | Body | Mind |
|---|---|---|---|---|---|
| Elven Archer* | 6 | 4 (1 si adjacent) | 2 | 3 | 2 |
| Elven Warrior | 6 | 4 | 3 | 3 | 2 |
| Ogre | 4 | 6 | 4 | 5 | 2 |
| Giant Wolf | 9 | 6 | 3 | 5 | 1 |

*Les Elven Archers lancent 4 dés d'attaque contre une cible non
adjacente en ligne de vue, 1 seul dé si la cible est adjacente.

**Adversaires nommés :**
- **High Alchemist** (quête 5) — Move 8 · Attack 3 · Defend 3 · Body 4 ·
  Mind 4 ; connaît *mind blast, restore Dread, summon wolves, werewolf's
  curse* (Mage of the Mirror, p. 23).
- **Tormuk** le nécromancien (quête 6) — Move 8 · Attack 4 · Defend 4 ·
  Body 6 · Mind 6 ; connaît *command, mirror magic, mind blast,
  reanimation, summon wolves, werewolf's curse* ; tient 2 archers elfes
  envoûtés qui rejoignent les héros une fois les autres monstres de la
  salle tués (Mage of the Mirror, p. 25).
- **Sinestra**, l'archemage (boss final, quête 9) — Move 8 · Attack 4 ·
  Defend 4 · Body 4 · **Mind 9** ; connaît *dispel, firestorm, mind blast,
  mirror magic, reanimation, restore Dread, summon wolves, werewolf's
  curse* (Mage of the Mirror, p. 33).
- **Gargouille lanceuse de sorts** (quête 8, non nommée) — stats de
  gargouille standard mais connaît *command* et *firestorm* (Mage of the
  Mirror, p. 29, note C).

Grande figurine : non précisé explicitement pour ces profils.

### 3. Nouveaux objets, artefacts, sorts

**Boutique de l'Alchimiste**, 3 potions réservées à l'Elf (Mage of the
Mirror, p. 2) :
- *Potion of Recall* (400 po) — récupère un sort déjà lancé pendant la
  quête en cours.
- *Potion of Vision* (500 po) — voit toutes les portes secrètes et pièges
  normaux en ligne de vue jusqu'au premier point de dégât subi.
- *Potion of Speed* (500 po) — 12 cases de déplacement et 2 attaques par
  tour jusqu'au premier point de dégât subi.
- *Potion of Superior Restoration* (800 po, tout héros) — restaure Body et
  Mind Points au niveau du début de quête ; guérit aussi la malédiction de
  loup-garou.

**6 artefacts nommés** : *Elven Boots*, *Elven Bracers*, *Elven Bow of
Vindication*, *Bone Wand*, *Ancient Staff*, *Sky Orb* (absorbe jusqu'à 4
Mind Points de dégâts via 4 jetons, puis devient inutile). **1 parchemin de
sort** (*Treasure Without Doom*, utilisable par tout héros).

**Système de sorts elfiques mis à jour** — 8 nouvelles cartes de sort
réservées à l'Elf, alternative au groupe de sorts standard : l'Elf en
choisit **3 sur 8** à utiliser pour la quête (Mage of the Mirror, p. 9).

### 4. Nouveau mobilier, nouvelles tuiles

Mage of the Mirror, p. 4-5 : **Portcullises** ×4 (grilles de fer,
invisibles tant que fermées, ouvertes par un piège précis, la clé de
laiton, ou la force), **Trap Doors** ×2 (téléportation par paires), **Sky
Orb and Sky Orb Tokens**, **Mirrors** (portails secrets vers des salles
cachées), **Inner Sanctum et Sanctum Wall** (salle du boss), **Quicksand**
(sables mouvants), **Long Pit Trap** (fosse longue, saut à 3 cases de
mouvement minimum), **Weapon Packs** et **Wolf Tokens** (pour la
malédiction de loup-garou), **Prospector** et **Princess Millandriel**
(tuiles de PNJ alliés), **Brass Key**.

### 5. Nouvelles mécaniques de règle

- **Malédiction du loup-garou** : un héros mordu par un loup-garou ou
  touché par le sort *werewolf's curse* doit, à chaque début de tour,
  lancer 2 dés rouges — 10 à 12 le transforme en loup contrôlé par le MJ
  pour le tour suivant du MJ (véritable monstre : ni portes, ni pièges, ni
  capacités de héros), avant de reprendre forme humaine et le contrôle du
  joueur en fin de tour du MJ ; guéri uniquement par une potion d'aconit ou
  la *Potion of Superior Restoration*. *Coût :* changement d'état
  temporaire majeur — le héros devient tour à tour joueur puis monstre
  contrôlé par le MJ, avec changement complet de jeu de règles ; le moteur
  actuel n'a pas de notion de « personnage qui bascule de camp ».
- **Groupe de sorts alternatif par classe** : une classe (ici l'Elf) peut
  choisir un deck de sorts différent du sien par défaut, avec un
  sous-ensemble choisi en début de quête (3 parmi 8) plutôt que la totalité
  du groupe. *Coût :* le moteur suppose un groupe de sorts fixe par classe
  à la création du personnage ; il faudrait un choix de sous-ensemble
  reconfigurable par quête.
- **Sables mouvants** : un jet de saut raté fait perdre au hasard 2 objets
  de l'inventaire du héros (armes/armures/potions/parchemins) et fige son
  tour ; le tour suivant, il ressort automatiquement de l'autre côté.
  *Coût :* mécanique de pénalité d'inventaire aléatoire liée à une case de
  décor — inédite, le moteur ne retire jamais d'objet aléatoirement hors
  contexte de vol (Ice Gremlin, Bone Weapons cassées).
- **Monstres envoûtés récupérables** : des monstres (les archers elfes de
  Tormuk) combattent d'abord contre les héros puis rejoignent leur camp une
  fois le sort brisé (mort de leur maître), passant sous le contrôle d'un
  joueur précis. *Coût :* changement de camp d'une entité déjà placée en
  cours de quête — proche de l'allié animal/PNJ mais partant du camp
  adverse.
- **Quête double 9-10 avec passage par miroir à sens unique** : les héros
  traversent d'une carte à l'autre via une tuile miroir (jamais de retour
  possible par le même chemin), les deux cartes restant simultanément «
  montées ». *Coût :* même famille que la quête double de Frozen Horror —
  deux cartes ouvertes en parallèle pour un même groupe.
- **Miroir vitrine (objet à distance visible mais non manipulable)** :
  un objet (l'Elven Bow of Vindication) est visible dans un miroir et
  récupérable seulement par un héros porteur d'un item précis (le
  lunarium) qui s'approche du miroir. *Coût :* condition d'obtention
  d'objet liée à la possession d'un autre objet spécifique — au-delà du
  modèle actuel où fouiller une salle suffit.

---

### Artefacts et parchemin TRANSCRITS — « Artifact Reference » (livret p. 35)

⚠ Corrigé le 2026-10-05 : `docs/plan-mage-du-miroir.md` §0 affirmait que le
livret « ne porte PAS... les 6 artefacts nommés, le parchemin *Treasure
Without Doom* ». C'est faux — les sept cartes sont **reproduites en
intégralité dans le livret lui-même**, page 35 (page 18 du PDF, rubrique
« Artifact Reference »), relues sur le rendu PNG (texte extrait vide sur
cette page : illustrations non-OCRisables, d'où l'erreur du premier passage).

- **Ancient Staff** — « *This magical staff enables the Elf to reflect any
  monster's spell back at the spellcaster. The spellcaster and all other
  monsters in the same room suffer the full effects of the spell, while the
  Elf and their companions are immune to the effects. The staff works only
  5 times, then it becomes useless.* »
- **Bone Wand** — « *This artifact enables any hero to control all skeletons
  in one room for one turn. They can move them and make them attack during
  this turn. The hero can make the skeletons attack each other or any other
  monsters in the room. The Bone Wand works only once per quest.* »
- **Elven Boots** — « *These boots grant the Elf an extra red die for
  movement. The Elf can roll 3 dice for movement either before or after
  taking an action. The boots wear out if the Elf rolls identical numbers on
  any 3 dice.* »
- **Elven Bow of Vindication** — « *Only an Elf may use this bow. An arrow
  fired from this bow hits and instantly kills any one monster within the
  Elf's line of sight, unless the monster rolls a black shield on 1 combat
  die. There are only 4 arrows with this bow and the bow is rendered useless
  once all of these arrows have been fired. This card can be used only in
  the Mage of the Mirror quest pack.* »
- **Elven Bracers** — « *These metallic wrist bands have magical powers. When
  placed on the Elf's wrists, these glowing artifacts greatly enhance the
  Elf's physical and mental abilities. The bracers add 2 extra Body Points
  and 1 extra Mind Point to the Elf's total. They can be worn only by the
  Elf.* »
- **Sky Orb** — « *The hero who possesses this orb may use it to absorb a
  total of 4 Mind Points of damage. Each time the hero would normally suffer
  the loss of 1 Mind Point, they instead hand Zargon one of the blue Sky Orb
  tokens and suffer no Mind Point damage. When all 4 tokens have been handed
  to Zargon, the Sky Orb is rendered useless.* »
- **Spell Scroll** — *Treasure Without Doom* — « *This spell scroll enables a
  hero to pick cards from the treasure deck, ignoring all wandering monster
  and hazard cards, until they pick a card showing gold, a potion, gems, or
  jewels. Alternatively, it can be used to open one chest without harm,
  disarming any trap on the chest. The scroll crumbles to dust after it is
  used.* »

→ chez nous : les sept sont déjà portées (`config/cartes.php:149-150,
181-183, 204, 330` — Brassards elfiques, Arc elfique de Vindication, Bâton
Ancien, Baguette d'Os, Bottes elfiques, Orbe Céleste, Trésor sans Péril) ;
cette transcription ne fait que fermer la source manquante, rien à changer
côté catalogue.

---

### Carte TRANSCRITE — Potion d'Aconit (photo de René, 2026-10-05)

Source : photo de la carte physique (© 2023 Hasbro), rangée dans
`~/heroquest-livrets/drive/photos-2026-10-05/wolfsbane_potion.jpg`. C'est le
remède à la malédiction du loup-garou (§ ci-dessus) ; aucune entrée au
catalogue à ce jour, aucun prix imprimé.

- **Wolfsbane Potion** — « *This potion may be used by any hero suffering
  from the werewolf's curse. It cures the hero. The potion may be used only
  once. Do not return this card to the deck.* »
  → chez nous : rien — attend la malédiction elle-même
  (`docs/plan-mage-du-miroir.md` lot B).

## Rise of the Dread Moon (2022/2023)

**Source :** livret de quêtes unique, réf. F6646, 40 pages imprimées / 21
pages PDF, entièrement extrait (`livret2.txt`, servi par `en-ca`/`en-gb` —
le fichier `en-us` distinct, `livret1.pdf`, a le même nombre de pages, donc
vraisemblablement le même contenu à une résolution différente ; non
ré-extrait). Suite directe de *The Mage of the Mirror* (même royaume
d'Elethorn). 10 quêtes. Boîte de très loin la plus dense en nouvelles
mécaniques transversales rencontrée jusqu'ici (avant Ogre Horde).

### 1. Nouveaux héros jouables

⚠ **Aucune nouvelle classe.** Personnage nommé notable : **Sir Ragnar**,
chevalier-gardien déchu, est un adversaire (boss de quête 9), pas un héros
jouable.

### 2. Nouveaux monstres

**Monster Chart officiel** (Rise of the Dread Moon, p. 36-37) :

| Monstre | Move | Attack | Defend | Body | Mind |
|---|---|---|---|---|---|
| Dread Cultist | 7 | 2 | 2 | 1 | 2 |
| Elven Archer* | 6 | 4 (1 si adjacent) | 2 | 3 | 2 |
| Elven Warrior | 6 | 4 | 3 | 3 | 2 |
| Assassin | 10 | 5 | 3 | 2 | 3 |
| Magus Guard | 8 | 4 | 4 | 3 | 3 |
| Specter | 8 | 3 | 3 | 1 | 0 |
| Dread Wraith | 9 | 6 | 4 | 5 | 5 |

- **Dread Cultist** — connaît *Dreadlights* et *Channel Dread*, chacun 1
  fois par quête.
- **Assassin** — attaque en diagonale.
- **Magus Guard** — connaît *Ball of Flame* et *Tempest*, chacun 1 fois
  par quête.
- **Specter** — mort-vivant et **éthéré** (voir mécanique dédiée), lance
  *Channel Dread* **à volonté**.
- **Dread Wraith** — éthéré, connaît *Dreadlights, Channel Dread, Fear,
  Summon Specters*, chacun 1 fois par quête ; grande figurine (« Large
  Monsters » s'applique).

**Adversaires nommés :**
- **Sir Ragnar** (boss, quête 9) — Move 5 · Attack 5 · Defend 5 · Body 4 ·
  Mind 4, avec une clause spéciale : **la première fois** que ses Body
  Points tombent à 0, ils sont ramenés à 1 au lieu de le tuer (Dread Moon,
  p. 30).
- **Magrian, le Dread Wraith** (boss final, quête 10) — mêmes stats que le
  Dread Wraith générique (9/6/4/5/5), rencontré **deux fois** : la première
  n'est qu'une image miroir qui vole en éclats une fois vaincue ; la
  seconde est le vrai combat, où il guérit 1 Body Point par tour tant que
  la Reine est captive, et dispose de **4 capacités à usage unique**,
  sans action : *Terror* (invulnérable aux sorts/attaques pour le reste du
  tour d'un héros ciblé), *Consume Magic* (annule un sort qui vient d'être
  lancé sur lui et regagne 2 Body Points), *Reflection* (redirige les
  dégâts qu'il vient de subir vers un héros en ligne de vue), *Shift
  Reality* (lance un sort Dread connu en début de tour du MJ) (Dread Moon,
  p. 32-33).

**4 Mercenaires elfiques** (Elven Mercenaries Chart, Dread Moon, p. 11),
débloqués progressivement au fil des quêtes plutôt que disponibles dès le
début :

| Mercenaire | Coût/quête | Move | Attack | Defend | Body | Mind |
|---|---|---|---|---|---|---|
| Striker | 100 po | 5 | 4 | 5 | 2 | 2 |
| Glaive | 75 po | 6 | 3 | 3 | 2 | 2 |
| Arbalist | 75 po | 6 | 3 | 3 | 2 | 2 |
| Scout | 50 po | 9 | 2 | 3 | 2 | 2 |

### 3. Nouveaux objets, artefacts, sorts

**Marché Souterrain** (remplace la boutique standard entre certaines
quêtes) — vend tous les objets de l'armurerie de base **plus** :
- *Caltrops* (100 po) — pose au sol sans action, 1 dé de combat pour
  continuer son mouvement (bouclier blanc) sinon le mouvement s'arrête.
- *Reagent Kit* (400 po) — permet à tout héros adjacent à un établi
  d'alchimiste de transformer un réactif en potion (le magicien n'en a pas
  besoin) ; épuisé après 5 usages.
- *Smoke Bomb* (100 po) — rend les héros invisibles au monstre adjacent
  ciblé jusqu'à son prochain tour.

**5 artefacts nommés** : *Raven's Talon*, *The Cloak of Shadows*, *The
Scales of Elethorn*, *Dawnshield*, *Phoenix Ash*. **Parchemins de sort**
utilisables par tout héros. **Deck d'alchimie** séparé (potions à
acheter, trouver ou fabriquer, dont la *Potion of Unforeseeable Fate* qui
active une carte aléatoire du deck — utilisable même à 0 Body Points en
espérant un soin).

### 4. Nouveau mobilier, nouvelles tuiles

Dread Moon, p. 3-5 : **Hideout** (planque, établi d'alchimiste inclus),
**Plaza** (grand espace ouvert qui annule les murs des salles qu'il
recouvre entièrement, désactive les monstres tant qu'aucun héros non
déguisé n'entre dans leur ligne de vue), **Strangers** (PNJ neutres qui
fuient si attaqués sans stats données), **Statues**, **Sorcerer's Table**
(soigne 1 Body + 1 Mind Point à la fouille pour l'Elf ou un porteur de
Lunar Charm), **Trap Doors**, **Lunar Charm** (jeton clé de quête), **Rack
(Arcane Prison)**, **Table**, **Cupboard**, **Disguise Token**,
**Reputation Token**, **Smoke Bomb token**.

### 5. Nouvelles mécaniques de règle

- **Monstres éthérés** : traversent héros/murs/objets solides (mais
  jamais dans une zone non découverte, jamais pour finir sur une case
  occupée), insensibles à tous les pièges y compris les caltrops posés par
  un héros ; une attaque de héros ne les touche que sur un **bouclier
  noir** (au lieu d'un crâne), sauf via sort ou artefact. ✅ **Porté**
  (capacité `ethere` : `Grille::autoriserEthere()` pour le déplacement,
  `ResolveurTour::frapper()` pour la touche ; l'immunité aux chausse-trappes
  a été corrigée le 2026-10-04, `docs/plan-correctifs-2026-10-04.md` C1).
- **Empowerment Dread Moon** (flag par quête) : tous les monstres
  lancent un dé d'attaque supplémentaire. *Coût :* trivial, un modificateur
  de quête appliqué à la résolution d'attaque de tous les monstres.
- **Système de déguisement** : un jeton conditionnel (arme légère
  uniquement, pas de sort, pas d'armure lourde) rend un héros ignoré par
  certains monstres/déclencheurs tant qu'il respecte des contraintes
  d'équipement et de comportement ; perdu automatiquement à la moindre
  infraction, récupérable en le reprenant en début de quête suivante.
  *Coût :* nouvel état de personnage avec des règles de validité
  croisées avec l'équipement porté (recalculé à chaque changement d'arme
  ou tentative de sort) — comparable en complexité à la maîtrise
  d'équipement par classe déjà implémentée, mais dynamique en cours de
  partie plutôt que figée à l'équipement.
- **Jetons de réputation** : monnaie secondaire gagnée en jouant les
  quêtes (1 par quête achevée, partagée par le groupe) ou par choix
  narratifs, dépensable pour des effets ponctuels (débloquer un
  mercenaire sans payer, obtenir un indice, intimider un PNJ) ou
  convertible en 250 po **à dépenser immédiatement** au marché. *Coût :*
  nouvelle ressource de groupe (pas individuelle) avec des points de
  dépense variés dispersés dans le texte de quête plutôt que dans un menu
  mécanique fixe — proche en esprit de l'or du groupe, mais avec des
  usages non-standards (conversion, sacrifice narratif) qu'un moteur
  d'options generique devrait exposer au cas par cas.
- **Planques (Hideouts)** : zone sûre où les tirages de cartes Monstre
  errant/Danger sont annulés, et où chaque héros peut une fois par quête
  répartir librement un jet de dé rouge entre soin de Body et de Mind
  Points. *Coût :* état de salle « sanctuaire » qui neutralise deux
  systèmes existants (deck de fouille errant, dégâts) — filtrage
  conditionnel du tirage de cartes selon la salle occupée.
- **Fabrication de potions (artisanat)** : un réactif consommable devient
  une potion choisie (parmi celles listées sur sa carte) via une action
  près d'un établi d'alchimiste ; le magicien le fait gratuitement, les
  autres ont besoin du Reagent Kit. *Coût :* chaîne de transformation
  d'objets (réactif → potion) avec un point d'ancrage géographique
  (établi), absente du modèle actuel où un objet trouvé est déjà l'objet
  final.
- **Piège de noyade en cascade (Pit Trap Drain)** : dans une case
  « voie d'eau », un double aux dés de mouvement empêche le déplacement
  normal et traîne le héros vers le piège de fosse le plus proche, sur une
  distance égale à la valeur du double. *Coût :* résolution de mouvement
  alternative déclenchée par le résultat du jet lui-même plutôt que par la
  case de destination — inhabituel, le moteur résout aujourd'hui le
  mouvement puis les effets de case, jamais l'inverse.
- **Portes-questions avec accumulation de succès** (quête 3) : plusieurs
  portes verrouillées cachent chacune un PNJ interrogeable (1 dé de
  combat, crâne = succès) ; au bout de 3 succès cumulés, la prochaine porte
  interrogée est automatiquement la bonne. *Coût :* mécanique de recherche
  d'information probabiliste avec compteur global de quête — nouveau type
  d'action hors combat/fouille/désamorçage.
- **Boss à phase fantôme puis réelle (Magrian)** : le premier
  affrontement contre un boss nommé est un leurre sans conséquence
  (image miroir), le second est le combat réel avec un tout autre jeu de
  capacités. *Coût :* similaire aux monstres multi-phases d'Ogre Horde,
  mais ici la première « forme » est un simulacre entièrement défait sans
  impact sur la seconde — nécessite de savoir qu'un monstre nommé peut
  apparaître deux fois dans la même quête avec un statut different (leurre
  vs réel).
- **Sac survivant à 0 Body Points nominal** (Sir Ragnar) : la première
  fois qu'un monstre nommé atteint 0 Body Points, ses points sont
  restaurés à 1 au lieu de déclencher sa mort. *Coût :* négligeable — un
  simple garde-fou sur la résolution de mort pour un monstre marqué
  « increvable une fois ».
- **Mercenaires déblocables progressivement**, payables en or **ou** en
  jeton de réputation (auquel cas ils restent définitivement acquis sans
  frais récurrent). *Coût :* même famille que le système de mercenaires
  d'Ogre Horde/Frozen Horror, avec une condition de déverrouillage
  narrative en plus du paiement, et un deuxième mode de paiement
  (ressource non-monétaire) à gérer.

---

### Cartes TRANSCRITES — réactifs d'alchimie (photos de René, 2026-10-05)

Source : photos des cartes physiques (© 2023 Hasbro), rangées dans
`~/heroquest-livrets/drive/photos-2026-10-05/`. Trois cartes du paquet
d'alchimie (artisanat de potions, `docs/plan-dread-moon.md` lot G) ; aucune
n'est au catalogue à ce jour.

- **Sacred Plant** — « *Elven healers have been using this plant medicinally
  for centuries. Consume at any time to restore 1 lost Mind Point. Do not
  return this card to the deck until the end of the quest.* » En gras :
  « ***Use as a reagent to craft 1x Potion of Restoration or 1x Holy
  Water.*** »
- **Mysterious Flower** — « *You uncover a rare and lovely elven plant that
  you know to be edible. Consume at any time to restore 1 lost Body Point. Do
  not return this card to the deck until the end of the quest.* » En gras :
  « ***Use as a reagent to craft 1x Potion of Dexterity or 1x Potion of
  Defense.*** »
- **Unidentified Ingredient** — « *A mysterious essence ground into fine
  powder. Use to craft potions. Do not return this card to the deck until
  the end of the quest.* » En gras : « ***Use as a reagent to craft 1x random
  potion by drawing one card from the alchemy deck.*** »

→ chez nous : les deux premiers sont aussi des **consommables** (1 Mind /
1 Body) portables sans l'artisanat ; le troisième n'a de sens qu'avec le
paquet d'alchimie. ⚠ Le reste du paquet (les potions d'alchimie elles-mêmes,
dont *Potion of Defense*) n'est pas encore photographié.

## Prophecy of Telor (2023)

**Source :** livret de quêtes unique, réf. G0052, 36 pages imprimées / 19
pages PDF, entièrement extrait. 13 quêtes — la boîte la plus longue en
nombre de quêtes rencontrée jusqu'ici, très narrative (un seul artefact
maudit porté par un héros fait toute la trame).

### 1. Nouveaux héros jouables

⚠ **Aucune nouvelle classe.** Aucun monster chart standard non plus (page
de fin absente) : tous les monstres génériques viennent du bestiaire de
base, seuls des Sorciers Dread nommés ont des stats inline dans le texte
de quête.

### 2. Nouveaux monstres

Pas de nouveau *type* de monstre. **Adversaires nommés :**
- **Gor-Lethim Kar**, démon de feu Dread (quête 3) — stats non chiffrées,
  connaît *Firestorm*.
- **Dread Sorcerer** (quête 7, non nommé) — Move 8 · Attack 4 · Defend 4 ·
  Body 3 · Mind 4 ; connaît *Ball of Flame, Cloud of Dread, Command*.
- **Dread Sorcerer** (quête 9, non nommé) — mêmes stats ; connaît *Tempest,
  Fear*.
- **Fellmarak, le Roi Sorcier** (boss récurrent, quêtes 12-13) —
  **deux profils différents** selon la quête :
  - Quête 12 : Move 8 · Attack 5 · Defend 4 · Body 6 · Mind 6 ; connaît
    *Firestorm, Rust, Command, Fear, Cloud of Dread* ; **ne peut pas être
    tué** à ce stade — à 0 Body Points, il s'enfuit par une porte secrète.
  - Quête 13 (combat final) : Move 8 · Attack 5 · Defend 4 · **Body 0** ·
    Mind 6, enveloppé par « Zargon's Flame » (voir mécanique dédiée) ;
    lance un sort Dread aléatoire chaque tour (*Summon Orcs* et *Summon
    Undead* retirés du deck) ; détruit instantanément si le sort *Escape*
    est tiré.
- **Squelettes/Momies "des hauts mages"** (quêtes 9-10) — stats de base
  mais avec seulement **2 Body Points** chacun (variante affaiblie).
- **Squelettes d'Abomination** (quête 5) — stats de squelette standard
  mais avec **3 Body Points**.
- **Monstres invoqués par « Zargon's Flame »** (quête 13) — attaquent et
  défendent normalement mais **n'ont pas de Body Points** : voir mécanique
  dédiée ci-dessous pour leur condition de mort.

### 3. Nouveaux objets, artefacts, sorts

**Boutique de l'Alchimiste** (Prophecy of Telor, p. 2) : *Potion of
Restoration* (300 po, 1 Body + 1 Mind), *Potion of Healing* (500 po, 1d6
Body), *Potion of Lesser Healing* (200 po, jusqu'à 2 Body), *Potion of
Battle* (200 po, relance tous les dés d'attaque), *Potion of Magic* (400
po, récupère jusqu'à 3 sorts déjà lancés cette quête).

Aucun nouvel artefact nommé — la boîte réutilise des artefacts du jeu de
base (*Elixir of Life*, *Ring of Fortitude*, *Rod of Telekinesis*,
*Talisman of Lore*). En revanche, plusieurs **parchemins de sort à usages
multiples** apparaissent, une variante inédite : *Heal Body* (3 usages
avant de tomber en poussière), *Lightning Bolt* (3 usages), *Water of
Healing* (2 usages) — au lieu de l'usage unique standard.
S'y ajoute un parchemin **ordinaire** (usage unique) : *Rock Skin*, trouvé
sur le bureau de Telor (quête 8, note F) — ajouté le 2026-10-04, la section
l'omettait.

### 4. Nouveau mobilier, nouvelles tuiles

⚠ Peu de mobilier nouveau dans le texte extrait au-delà des décors
narratifs (bibliothèques illusoires, autel, arène en pierre de Kertz qui
annule toute magie). Pas de section « Component Descriptions » dédiée
comme dans les autres boîtes — probablement parce que cette boîte
réutilise en grande partie les tuiles/portes du système de base et de
boîtes précédentes plutôt que d'en introduire de nouvelles.

### 5. Nouvelles mécaniques de règle

- **Artefact maudit porteur d'intrigue (Talisman of Lore)** : un héros
  désigné porte un artefact **impossible à retirer** ; à chaque début de
  son tour, il lance 2 dés rouges contre ses Mind Points actuels (+1 par
  allié dans la même salle/couloir, +2 par allié adjacent) — un échec lui
  coûte 1 Body Point. *Coût :* mécanique de pression continue liée à un
  objet porté, avec bonus dépendant de la position des autres héros —
  différent de tout ce que le moteur applique aujourd'hui (les jets sont
  déclenchés par une action, jamais automatiquement à chaque tour d'un
  porteur d'objet précis).
- **KO non létal spécifique à un porteur** : si les Body Points du porteur
  du talisman tombent à 0, il **tombe inconscient au lieu de mourir**
  (incapable d'agir, mais soignable par n'importe quel allié) ; les
  monstres ne peuvent pas le piller inconscient. *Coût :* état
  « inconscient récupérable » distinct de la mort, déjà partiellement
  couvert par le concept de KO à 0 Mind Point dans d'autres boîtes, mais
  ici c'est 0 **Body** Points qui déclenche l'inconscience plutôt que la
  mort. ⚠ Corrigé le 2026-10-04 : ce n'est **pas** une règle neuve chez
  nous — tout héros à 0 Body est déjà `tombe` (relevable, distinct de la
  mort ; `docs/regles/vocabulaires-effets.md`, `verdictDeChute()`).
- **Condition de défaite alternative (Rise of Fellmarak)** : si le porteur
  est inconscient et que tous les autres héros sont morts, la quête se
  termine en défaite scriptée plutôt que par un TPK classique. *Coût :*
  ajouter un état de fin de quête « défaite » distinct du TPK actuel,
  déclenché par une combinaison état-de-personnage plutôt que par la mort
  de tous.
- **Sorts Dread gagnés et rechargés par un héros** : le porteur apprend 2
  sorts Dread (*Lightning Bolt*, *Firestorm*), chacun lançable une fois par
  quête, et **gagne 1 Mind Point à chaque lancer**. *Coût :* un héros peut
  temporairement accéder à un sort hors de sa classe avec un effet
  secondaire de soin — le moteur de sorts suppose aujourd'hui un
  répertoire de sorts fixe par classe assigné à la création.
- **Transformation forcée réversible** (quête 5) : tous les héros sauf le
  porteur sont changés en Orcs (nouvelles stats d'attaque/défense, 2 Body
  Points plafond, sorts interdits) jusqu'à ce qu'un événement de quête
  inverse l'effet. *Coût :* changement temporaire complet de statistiques
  et de règles pour plusieurs héros simultanément, avec restauration
  automatique — plus large que l'état de choc à 0 Mind déjà supporté.
- **Monstres increvables sauf par un jet caché du MJ (Zargon's Flame)** :
  un monstre invoqué n'a pas de Body Points ; à chaque fois qu'il subit un
  coup, le MJ annonce secrètement 2 valeurs 1-6 et lance 1 dé rouge — le
  monstre survit si le résultat correspond, sinon il meurt ; un sort
  précis (*Water of Healing*) retire sa capacité de défense et limite le MJ
  à une seule valeur protectrice. *Coût :* mécanique probabiliste opaque
  côté MJ humain (choix caché puis jet) — difficile à automatiser
  fidèlement dans un moteur déterministe sans exposer ou dissimuler
  l'information différemment (un MJ IA n'a pas de "choix caché" naturel
  vis-à-vis du joueur, mais le moteur peut simuler ce jet côté serveur).
- **Déplacement forcé scripté** (quête 6) : le porteur est automatiquement
  déplacé de 9 cases sur un chemin fixe pendant 4 tours consécutifs,
  conservant son action normale. *Coût :* mouvement non choisi par le
  joueur, imposé par le scénario — le moteur de déplacement suppose
  aujourd'hui un choix de trajectoire du joueur (borné par le jet de dé).
- **PNJ combattant sous les stats d'un autre monstre** (Gawr, quête 11) —
  un gobelin allié utilise en réalité les statistiques d'une gargouille.
  *Coût :* négligeable, substitution de profil pour un PNJ déjà supporté
  par ailleurs (allié contrôlé par un joueur).
- **Fouille garantissant un vrai trésor** : certaines salles retirent du
  tirage les cartes Monstre errant/Danger et forcent à repiocher jusqu'à un
  résultat de trésor. *Coût :* mineur — un filtre de retirage sur le deck
  de fouille existant, contextuel à une salle précise.

---

## Spirit Queen's Torment (2023)

**Source :** livret de quêtes unique, réf. G0053, 36 pages imprimées / 19
pages PDF, entièrement extrait. 14 quêtes.

### 1. Nouveaux héros jouables

**Le Barde (Bard Hero)** — un **Orc** jouable, présenté dans le livret
comme un cinquième héros disponible pour toute la campagne (Spirit Queen's
Torment, p. 4 : « *Players may choose to play the bard hero for this quest
book* »).
⚠ **Aucune fiche chiffrée** (dés d'attaque/défense de base, Body, Mind,
déplacement) dans le livret de quêtes — ces valeurs vivent sur une carte de
personnage cartonnée absente du PDF. ✅ Obtenue depuis (scan de René,
2026-08-11 : **A2 D2 B5 M4**, Mythic Tier) : le Barde est jouable —
`reference/01_personnages.md` §4bis et §HasLab Mythic Tier ci-dessous.
Éléments confirmés par le livret : une règle de **remplacement posthume**
propre à cette boîte : si personne ne joue le Barde et qu'un héros meurt,
le Barde apparaît dans la salle du défunt, son joueur récupère les objets
du mort, et le Barde ne peut pas agir le tour de son apparition ; ce
remplacement ne peut se produire **qu'une seule fois** par campagne
(Spirit Queen's Torment, p. 4).
⚠ Corrigé le 2026-10-04 : cette section disait le Barde « équipé d'une
**Rapière** » d'après le livret (« liste d'objets p. 34 »). Le mot *rapier*
n'apparaît sur **aucune** des 19 pages du PDF G0053 : la rapière vient de la
source tierce ci-dessous et de la carte transcrite (§HasLab Mythic Tier).
« (source tierce, à confirmer) » — un blog de compte-rendu de partie
(bloodandspectacles.blogspot.com) décrit en plus, sans chiffres
officiels : une Rapière à **2 dés d'attaque, utilisable en diagonale**, et
**3 sorts** propres au Barde — *Sleep*, *Boost*, et un sort de soin
affectant **tous les héros d'une même salle** — présenté comme un profil
de soutien plutôt qu'un combattant. Aucune de ces valeurs n'a pu être
recoupée avec un document officiel ; à vérifier avant toute intégration.

### 2. Nouveaux monstres

⚠ **Aucun monster chart** dans ce livret (contrairement à Frozen Horror,
Ogre Horde, Mage of the Mirror, Dread Moon) : uniquement des monstres du
bestiaire de base, plus deux adversaires nommés avec stats inline :
- **Kavra** (sorcière brigande, quête 3) — figurine de gargouille ; stats
  d'un Dread Warrior **+1 dé de défense** ; connaît *Lightning Bolt* et
  *Ball of Flame*, les deux lançables **simultanément** à son premier tour
  seulement (Spirit Queen's Torment, p. 10).
- **La Reine des Esprits / Nelath** (boss final, quête 14) — figurine de
  gargouille ; stats d'un Dread Warrior avec **6 Body Points** ; connaît
  *Command, Fear, Lightning Bolt, Firestorm*, peut en lancer **deux par
  tour** ; combat à **résolution alternative** (voir mécaniques).
- **Statues de pierre animées** (quête 10 ; la quête 13 porte une règle
  de fournaise sans rapport) — tous les monstres
  d'une quête gagnent **+1 dé de défense** (sauf la Gargouille, qui garde
  ses stats normales).

### 3. Nouveaux objets, artefacts, sorts

**Boutique de l'Alchimiste** (Spirit Queen's Torment, p. 2) : *Potion of
Restoration* (300 po), *Potion of Dexterity* (100 po), *Venom Antidote*
(300 po), *Potion of Battle* (200 po) — mêmes effets que dans les boîtes
précédentes.

**9 artefacts nommés** trouvables en jeu : *Wizard's Staff*, *Orc's
Bane*, *Borin's Armor*, *Wizard's Cloak*, *Spell Ring* (contient le sort
*Ball of Flame*), *Fortune's Longsword*, *Phantom Blade*, et réutilisation
de *Elixir of Life* et *Talisman of Lore* du jeu de base — **4 d'entre eux
sont les « artefacts élémentaires »** (terre/eau/air/feu), un par tour
élémentaire, nécessaires ensemble pour accéder à la zone finale.

**Potion de fortune aléatoire** (quête 4, non nommée sur une carte) :
boire l'une des 3 potions trouvées sur une table tire au sort son effet
via 1 dé de combat — bouclier blanc = soigne 1d6 Body, crâne = effet
*Potion of Battle*, bouclier noir = perd 1 Body Point.

**4 trouvailles citées sans texte de carte** (*Rabbit Boots* q. 4/6,
*Dust of Disappearance* q. 4/6, *Anti-Poison Quill* q. 2, parchemin *Fire
of Wrath* q. 7) — ⚠ Corrigé le 2026-10-05 : ce ne sont pas des cartes
manquantes, ce sont les **mêmes cartes physiques** que *Return of the
Witch Lord* (texte intégral déjà transcrit ci-dessus § Return of the Witch
Lord), simplement réutilisées ici sans être réimprimées.

### 4. Nouveau mobilier, nouvelles tuiles

⚠ Peu de mobilier générique décrit à part — cette boîte investit plutôt
dans des **salles à mécanique unique par tour élémentaire** (voir
ci-dessous) que dans du mobilier réutilisable.

### 5. Nouvelles mécaniques de règle

- **Quatre tours élémentaires en parallèle, complétion libre** : les héros
  choisissent l'ordre dans lequel ils visitent les tours de Terre, Eau,
  Air et Feu (quêtes 10-13), chacune gardant son propre artefact, les
  quatre étant nécessaires pour débloquer la quête finale. *Coût :*
  changement structurel — le moteur suppose aujourd'hui une progression
  strictement linéaire de quête en quête ; une campagne à embranchement
  choisi par les joueurs demanderait une notion de « quêtes disponibles en
  parallèle avec prérequis cumulatifs ».
- **Compte à rebours de survie (Tour de l'Eau)** : les héros retiennent
  leur souffle, chaque tour est décompté sur la fiche, et la mort survient
  automatiquement au 7ᵉ tour sans source d'air trouvée. *Coût :* minuteur
  de quête indépendant des Body/Mind Points — un état de personnage qui
  tue sans jet de dé si une ressource de scène n'est pas obtenue à temps.
- **Dégâts convertis en Mind Points** (quêtes 8-9, « royaume des esprits ») :
  tous les monstres de la quête infligent leurs dégâts aux **Mind Points**
  du héros plutôt qu'à ses Body Points, sans changement de la table de
  résolution de combat elle-même. *Coût :* modéré — un simple aiguillage
  de la cible des dégâts (Body → Mind) au niveau de la quête entière,
  mais touche un point sensible : le moteur associe aujourd'hui la mort
  aux Body Points à 0, or ici c'est indirectement les Mind Points qui
  deviennent la jauge de survie.
- **Ressource d'insubstantialité à charges** (quête 14) : chaque héros
  dispose de 3 charges, dépensables pour ignorer une source de dégâts,
  traverser un mur ou obtenir +2 dés de mouvement pour un déplacement.
  *Coût :* nouvelle ressource par héros, à durée de quête, à trois usages
  interchangeables — pattern proche d'un « mana » générique jamais
  rencontré ailleurs dans le jeu de base.
- **Combat à résolution alternative (rédemption plutôt que mise à mort)** :
  contre la Reine des Esprits, un héros peut consacrer son action à un
  jet de 1 dé de combat (bouclier = 1 dégât de Mind) pour la libérer de
  l'emprise de Zargon au lieu de l'achever en combat classique ; à 0 Mind
  Points par cette voie, elle est « sauvée » plutôt que tuée, avec un texte
  de conclusion différent. *Coût :* embranchement de résolution de boss —
  deux chemins de victoire distincts pour le même combat, avec état
  narratif de fin de campagne conditionnel (mission `objectif` du gabarit
  devrait accepter plusieurs issues valides, pas seulement « vaincu »).
- **Remplacement posthume d'un héros par un PNJ dédié (le Barde)** :
  contrairement aux mercenaires (payants, temporaires), le Barde
  remplace **gratuitement et définitivement** un héros mort si personne ne
  le joue déjà, une seule fois par campagne. *Coût :* filet de sécurité
  anti-TPK partiel — nécessite une classe de héros « de réserve » activable
  uniquement par la mort d'un titulaire, distincte du roster normal.
- **Zone à mouvement forcé partiellement aléatoire** (Tour de l'Air,
  courants) : à la fin d'un mouvement dans un couloir, 1 dé de combat
  décide d'un déplacement bonus de 2 cases, dans une direction choisie par
  le joueur (bouclier) ou par le MJ (crâne). *Coût :* extension mineure de
  la résolution de mouvement — un post-déplacement conditionnel après le
  mouvement normal du joueur.
- **Puzzle d'ordre (gemmes arc-en-ciel)** : poser 3 gemmes dans un ordre
  précis pour désamorcer un mécanisme, sanctionné par des dégâts de zone
  en cas d'erreur. *Coût :* mineur — un type d'interaction « resoudre une
  énigme d'ordre » à ajouter au catalogue d'actions de salle, hors
  fouille/piège/porte.

---

## Jungles of Delthrak (2024)

**Source :** livret de quêtes unique, réf. F9907, 52 pages imprimées / 27
pages PDF, entièrement extrait. 16 quêtes en **structure ramifiée** (3
chemins distincts A/B/C selon les choix des joueurs, avec 3 conclusions
différentes). Suite directe de Kellar's Keep (mêmes nains réfugiés).

### 1. Nouveaux héros jouables

Le livret l'annonce explicitement : « *Jungles of Delthrak introduces two
playable hero classes available to choose before embarking on the quests.
These heroes can replace one of the four HeroQuest Game System heroes* »
(Jungles of Delthrak, p. 6).

- **L'Explorateur (Explorer)** — nom de classe confirmé dans le texte de
  quête lui-même (quête 8, note D : « *The Dwarf and Explorer succeed on a
  5 or a 6* », dans un couloir à lames tournantes résolu par un jet de dés
  rouges égal aux Mind Points, où Nain et Explorateur ont un seuil de
  réussite élargi).
- **Le Berserker** — présent dans la liste des figurines de la boîte
  (« *Berserkers* », p. 2-3) au même titre que « *Explorers* », mais son
  statut de classe jouable n'est **pas confirmé aussi explicitement** dans
  le texte de quête lui-même (contrairement à l'Explorateur) — inféré par
  analogie de contenu.

⚠ **Aucune fiche chiffrée** pour ces deux classes dans le livret de
quêtes — elles vivent sur des cartes de personnage cartonnées hors PDF.
✅ Obtenues depuis (scan de René, 2026-08-11 : Explorateur **A2 D2 B5 M5**,
Berserker **A3 D2 B7 M2**) : les deux classes sont jouables —
`reference/01_personnages.md` §4bis et le tableau de synthèse en fin de fichier.

### 2. Nouveaux monstres

**Monster Chart officiel** (« Monsters of the Jungle », Jungles of
Delthrak, p. 47) :

| Monstre | Move | Attack | Defend | Body | Mind | Capacité spéciale |
|---|---|---|---|---|---|---|
| Blightcrawler | 7 | 4 | 4 | 3 | 4 | Spawn, Agile, Venomous |
| Blightweaver | 7 | 2 | 2 | 1 | 2 | Sorts *Channel Dread*, *Creeping Grasp* |
| Giant Ape | 8 | 4 | 3 | 7 | 5 | Agile |
| Goblin Archer | 10 | 2 (1 adj.) | 1 | 1 | 1 | Ranged |
| Raptor | 8 | 3 | 2 | 2 | 3 | Clever Tactician |
| Serpent | 8 | 4 | 3 | 6 | 3 | Spawn, Venomous |
| Skeleton Archer | 6 | 2 (1 adj.) | 2 | 1 | 0 | Ranged |
| Skullblight | 6 | 3 | 2 | 2 | 0 | Entangling Roots |
| Spawnling | 3 | 0 | 0 | 1 | 0 | Venomous, Agile |

**5 mots-clés de capacité** définis en pages 48-49 : **Spawn** (le monstre
crée un Spawnling adjacent OU déplace tous ses Spawnlings actifs, en
alternative à chaque tour de héros) ; **Venomous** (dégât = paralysie,
jet de 1 dé rouge pour résister sur 5-6, sinon jeton venin jusqu'à la fin
du tour suivant) ; **Agile** (ignore terrain gênant/mobilier/héros en se
déplaçant) ; **Clever Tactician** (peut bouger avant *et* après son
action, +1 dé d'attaque contre une cible flanquée par un autre monstre) ;
**Entangling Roots** (un héros entrant dans une case adjacente au monstre
voit son mouvement stoppé net).

**Adversaires nommés :**
- **Deathspinner la Reine du Fléau** (figurine Blightcrawler, quête 3) —
  Move 6 · Attack 5 · Defend 4 · Body 4 · Mind 4 ; « Silencing Snare » —
  lance *Channel Dread* **à volonté** sur tout héros terminant son tour en
  ligne de vue.
- **Stone Sentry** (squelettes reskinnés, quête 6) — Move 6 · Attack 3 ·
  Defend 2 · Body 2 · Mind 0 ; version à distance lance des rochers (3 dés
  contre cible non adjacente, 1 contre adjacente).
- **Forgeheart Golem** (quête 6) — Move 8 · Attack 5 · Defend 5 · Body 5 ·
  Mind 0 ; capacité « Breath of the Construct » (usage unique, action) :
  2 Body Points de dégâts de zone à toute la salle/couloir, réduits de 1
  par 5/6 obtenu sur 2 dés rouges par chaque héros touché.
- **Gruulob, Sorcier Gobelin Corrompu** (quête 8) — Move 6 · Attack 3 ·
  Defend 4 · Body 4 · Mind 5 ; sorts *Creeping Grasp, Channel Dread,
  Summon Orcs* (invoque des Gobelins à la place) ; **multi-phase** : à 0
  Body Points, devient **Gruulob, Forme Démoniaque** — Move 6 · Attack 4 ·
  Defend 5 · Body 3 · Mind 4.
- **Arcane Mummy** (variante de Dread Warrior, quête 9) — Move 6 · Attack 4
  · Defend 4 · Body 4 · Mind 0 ; sorts *Channel Dread, Creeping Grasp, Ball
  of Flame*.
- **Reservoir Guardian** (figurine Blightweaver, quête 11A) — Move 6 ·
  Attack 5 · Defend 4 · Body 3 · Mind 0.
- **Gretzl la Porte-Fléau** (boss, quête 12A) — **3 phases** :
  - Phase 1 : Move 6 · Attack 4* · Defend 3 · Body 5 · Mind 6 ; sorts
    *Creeping Grasp, Channel Dread, Fear* ; capacités à usage unique
    *Demon Wings* (ignore tous les dégâts d'une attaque) et *Dispel*
    (annule un sort la ciblant).
  - Phase 2, « Demonspider » (Agile, Venomous) : Move 8 · Attack 5* ·
    Defend 4 · Body 4 · Mind 3.
  - Phase 3, « Demonape » (Agile) : Move 8 · Attack 6* · Defend 2 ·
    Body 6 · Mind 1.
  (*tir à distance possible en ligne de vue.)
- **Bakabri le Cruel** (figurine Goblin Warlock, quête 15C) — Move 8 ·
  Attack 3* · Defend 4 · Body 4 · Mind 5 ; sorts *Creeping Grasp, Channel
  Dread, Lightning Bolt*.
- **Bloomrot Scion** (boss final, quête 14B, Venomous, Entangling Roots) —
  Move 0 · Attack 5 (attaque de zone sur toutes les cases adjacentes et
  diagonales) · Defend 5 · **Body 12** · Mind 0 ; immunisé au sommeil et
  aux effets de saut de tour ; lance *Channel Dread* à volonté ; fait
  apparaître une **Tendril** (Move 6 · Attack 3 diagonale · Defend 2 ·
  Body 2 · Mind 0, Venomous, Entangling Roots) à la fin de chacun de ses
  tours ; à 0 Body Points, peut **sacrifier une Tendril active** pour
  revenir à 1 Body Point au lieu de mourir.

### 3. Nouveaux objets, artefacts, sorts

**Boutique de l'Alchimiste** (Jungles of Delthrak, p. 2) : *Potion of
Serpent's Blood* (50 po, retire la paralysie du venin), *Potion of
Healing* (500 po, 1d6 Body), *Potion of Elder Wisdom* (400 po, récupère 1
sort/compétence de héros déjà utilisé, 1 par quête), *Spiderstep Elixir*
(100 po, ignore fosses révélées/terrain gênant/mobilier/monstres jusqu'au
premier dégât subi).

**6 artefacts nommés** : *Emberwrought Diadem* (objectif de quête central,
+1 Body Point max et +1 dé de défense, incompatible casque), *Bracers of
the Wild* (+1 dé de défense, ignore terrain gênant/mobilier, +2 cases de
déplacement), *Fangwarden Armlet* (invoque un allié Raptor, 1 fois par
quête, se recharge après 2 quêtes sans l'utiliser), *Sapphire Skull* (+2
dés d'attaque contre un monstre en ligne de vue), *Girdle of Might* (+1 dé
d'attaque non-distance, inutilisable par le magicien), *Emerald Heart of
Delthrak* (gemme non-magique, 75 po).

### 4. Nouveau mobilier, nouvelles tuiles

Jungles of Delthrak, p. 3-5 : **Pool of Water / Crystal Cluster / Bonfire**
(un même socle « Basin » réutilisé pour trois effets différents — l'eau
soigne 1 Body Point en fouille alternative, le cristal bloque la ligne de
vue et amplifie *Channel Dread* mais peut être **détruit comme un monstre
sans défense** avec 6 Body Points, le feu inflige 1 Body Point sur un
crâne au passage), **Statue** (bloque la ligne de vue), **3 types de
terrain gênant** (sable/toile/jungle, 2 cases de mouvement par case
traversée), **Cocoon** (détruite par une action adjacente, contient butin
progressif), **Grasping Vine Trap** (invisible tant que non cherché,
immobilise le héros jusqu'à destruction par une action), **Spawnling
tiles**, **Stranger tiles**.

### 5. Nouvelles mécaniques de règle

- **Trois modes de difficulté (Standard / Heroic / Story)**, choisis avant
  la campagne : en Heroic, un héros à 0 Body Points est incapacité (pas
  mort) et reçoit un jeton crâne au début de son tour suivant — mort
  seulement s'il commence encore un tour avec ce jeton en main, sinon
  soignable normalement ; en Story, pas de jeton crâne, régénération
  passive d'un Body Point hors combat si un allié est valide, mort du
  groupe seulement si **tous** sont incapacités. Les lanceurs de sorts
  incapacités peuvent en plus se soigner eux-mêmes d'un sort disponible
  au moment de tomber, même hors de leur tour. *Coût :* chantier
  significatif — trois politiques de mort différentes à sélectionner par
  groupe/campagne, avec un état « incapacité récupérable » distinct de la
  mort et un minuteur (jeton crâne) suivi par personnage.
- **Choix de classe de héros en début de campagne** (2 classes en plus
  des 4 de base, remplaçant l'une d'elles) — déjà couvert par la fiche
  cartonnée manquante, mais notable comme **premier cas où le livret
  documente explicitement la substitution** plutôt que l'ajout pur.
- **Campagne à embranchements (Choose Your Path)** : plusieurs points de
  décision mènent à des quêtes différentes (2 puis 3 embranchements
  distincts, jusqu'à 3 conclusions), certains chemins n'étant accessibles
  que si une quête optionnelle précédente a été jouée. *Coût :* même
  famille que les tours au choix libre de Spirit Queen's Torment, en plus
  poussé — un graphe de quêtes avec conditions d'accès (quête complétée
  précédemment) plutôt qu'une simple liste ordonnée.
- **Terrain destructible avec ses propres points de vie** (Crystal
  Cluster : 6 Body Points, aucune défense, bloque la ligne de vue tant
  qu'il existe). *Coût :* même famille que le Sceptre de Frozen Horror —
  un élément de décor attaquable indépendamment des monstres/héros.
- **Jetons de dégâts différés (Spawnlings)** : un jeton posé sur la fiche
  d'un héros inflige 1 Body Point **automatique et indéfendable** à
  chaque fin de tour tant qu'il reste en sa possession, cumulable
  (plusieurs jetons = plusieurs dégâts). *Coût :* nouveau type de
  dégât périodique attaché à un héros plutôt qu'à une case ou un
  monstre — proche des conditions (poison) mais résolu en fin de tour du
  porteur et non par un jet de résistance.
- **Sacrifice pour survivre (Bloomrot Scion)** : un boss à 0 Body Points
  peut consommer une entité alliée déjà sur le plateau (une Tendril
  active) pour repasser à 1 Body Point au lieu de mourir. *Coût :*
  mécanique de sauvegarde conditionnée à l'existence d'une autre entité —
  le moteur devrait vérifier un état tiers (Tendril vivante) avant de
  finaliser la mort d'un monstre.
- **Ordre de tour choisi par les joueurs (règle optionnelle)** : les héros
  peuvent décider librement l'ordre dans lequel ils agissent au sein d'un
  round, le MJ jouant toujours en dernier. *Coût :* mineur — le moteur
  impose aujourd'hui un ordre de tour fixe par personnage ; le rendre
  reconfigurable par round est une extension ciblée du planificateur de
  tour.

---

### Artefacts TRANSCRITS — « Treasure and Artifact Reference » (livret p. 50)

⚠ Corrigé le 2026-10-05 (René) : le plan de cette boîte donnait les six
artefacts comme « à photographier ». Leurs cartes sont **reproduites dans le
livret lui-même**, page 50 (page 26 du PDF), relue sur le rendu PNG.

- **Emberwrought Diadem** — « *This ancient circlet is inset with a fiery red
  gem. It raises your maximum Body Points by 1 and grants you 1 additional
  Defend die. May not be combined with the helmet.* »
- **Bracers of the Wild** — « *Armor—These magical leather bracers grant you
  1 additional Defend die. While wearing these bracers, you move unaffected
  through squares containing furniture and hindering terrain. You may also add
  2 squares to your movement roll. May be combined with the helmet and/or
  shield.* »
- **Fangwarden Armlet** — « *Use this magical armlet to call forth a Raptor
  animal ally. If the Raptor is defeated, the armlet's power goes dormant. Its
  power replenishes if the hero completes two quests without its assistance.
  This artifact may only be used once per quest.* »
- **The Sapphire Skull** — « *This legendary relic is crafted from precious
  cobalt gemstone. It imbues you with poise and assurance. While wielding this
  artifact, roll 2 Attack dice against any one monster in your line of
  sight.* »
- **Girdle of Might** — « *This enchanted girdle grants you 1 additional
  Attack die on all non-ranged weapon attacks. May not be used by the
  wizard.* »
- **Emerald Heart of Delthrak** — « *This rare gemstone is prized by
  Delthrak's inhabitants and can be sold for 75 gold coins.* » (trésor-valeur)
- **Ancient Dwarven Relic** — « *You discover one of Delthrak's forgotten
  treasures! This piece of elegant dwarven artistry is worth 50 gold coins.* »
  (trésor-valeur)

Même page : la boîte réutilise ces cartes du jeu de base — *Battleaxe,
Borin's Armor, Chainmail, Crossbow, Elixir of Life, Fortune's Longsword, Holy
Water, Longsword, Phantom Blade, Ring of Fortitude, Shield, Wizard's Staff*.

→ chez nous : aucun de ces six artefacts n'est au catalogue. Presque tous
tombent sur des mots-clés existants (dé de défense, Body max, dé d'attaque
au contact, déplacement, franchissement du mobilier/terrain — le talent
`ignore_terrain_entravant` existe) ; l'*Armlet* appelle un **allié animal**
(Raptor) avec une recharge sur deux quêtes, à porter avec les alliés joués
par leur joueur.

## The Crypt of Perpetual Darkness (2024/2025, Joe Manganiello's)

**Source :** livret de quêtes unique, réf. G1798, 28 pages imprimées / 15
pages PDF, entièrement extrait. 10 quêtes. Boîte relativement courte et
proche du format « quête pure » (comme Kellar's Keep/Witch Lord) : peu de
nouvelles mécaniques transversales, surtout des adversaires nommés et deux
nouveaux pièges.

### 1. Nouveaux héros jouables

⚠ **Aucune.** Un PNJ allié récurrent, **Vander l'elfe**, rejoint le groupe
en quête 2 (prisonnier à délivrer) et « utilise les statistiques de la
carte Elfe » (Crypt of Perpetual Darkness, p. 9) — un allié contrôlé par
un joueur, pas une classe distincte.

### 2. Nouveaux monstres

⚠ **Aucun monster chart**, aucun nouveau type de monstre — uniquement des
adversaires nommés utilisant des figurines et gabarits de monstres du jeu
de base, avec stats inline :

- **La Sorcière des Marais** (figurine Dread sorcerer, quête 3) — Move 4 ·
  Attack 2 · Defend 3 · Body 2 · Mind 3 ; sorts *Lightning, Command,
  Sleep, Tempest, Rust, Escape*.
- **Squelette de minotaure** (figurine squelette, quête 4) — Move 6 ·
  Attack 3 · Defend 3 · Body 2 · Mind 0.
- **Buubhealxea, reine gobeline** (quête 5) — Move 8 · Attack 3 · Defend 3
  · Body 3 · Mind 2 ; sorts *Command, Fear, Escape*.
- **Roi Archaloneus**, seigneur momie (quête 6) — Move 6 · Attack 4 ·
  Defend 4 · Body 4 · Mind 3 ; sorts *Sleep, Rust, Command*.
- **Momie-naine** (quête 8) — Move 5 · Attack 4 · Defend 5 · Body 4 ·
  Mind 0.
- **Kedrick Gilbane**, chevalier de la mort (boss, quête 9, figurine
  Dread sorcerer) — Move 7 · Attack 5 · Defend 5 · Body 4 · Mind 4 ;
  sorts *Cloud of Dread, Fear, Firestorm, Command*.
- **Venim**, le dragon (boss final, quête 10) — Move 7 · Attack 5 ·
  Defend 5 · Body 4 · Mind 4 ; sorts *Cloud of Dread, Fear, Command,
  Sleep*, et *Breathe Acid* (= Firestorm mais dégâts d'acide) ; son antre
  est plongé dans une **obscurité magique** qui inflige -2 dés de combat
  aux deux premières attaques de tout héros sans vision nocturne (les
  monstres n'en souffrent pas).
- **Dread Skull** (piège/monstre hybride, réutilisable dans toute quête où
  il apparaît) — dès qu'un héros le voit, il est placé et attaque
  immédiatement à distance (2 dés de combat) « avec ses rayons oculaires
  verts » ; 1 Body Point, 0 dé de défense, **disparaît au premier dégât
  subi, quel qu'il soit**.

### 3. Nouveaux objets, artefacts, sorts

**2 artefacts nommés** (Crypt of Perpetual Darkness, p. 5, 26) :
- *Crown of Shadows* — casque, +1 dé de défense, vision normale dans le
  noir magique et non-magique.
- *Dragon Spear* — arme, 3 dés d'attaque (4 contre un dragon), utilisable
  en diagonale, interdite au magicien.

Boutique de l'Alchimiste : potions déjà rencontrées dans d'autres boîtes
(*Potion of Restoration* 300 po, *Potion of Dexterity*, *Venom Antidote*,
*Potion of Battle*) — aucune nouvelle formule.

**Objet utilitaire à usage unique : la souris familière.** Trouvée en
quête 2 (« *the mouse telepathically informs them that it is a wizard's
familiar* »), elle peut être relâchée devant une porte fermée dans
**n'importe quelle quête ultérieure** pour révéler par télépathie les
monstres et le mobilier de la salle derrière — puis elle s'enfuit,
définitivement consommée.

### 4. Nouveau mobilier, nouvelles tuiles

⚠ Peu de mobilier générique nommé au-delà des deux nouveaux pièges
ci-dessous et d'éléments de décor narratifs (taverne, forge naine, trône).

### 5. Nouvelles mécaniques de règle

- **Piège de vignes agrippantes (Grasping Vines Trap)** : identique à la
  version rencontrée dans Jungles of Delthrak — non marquée tant que non
  trouvée, jet de 1 dé de combat pour esquiver, sinon 1 Body Point et
  immobilisation jusqu'à destruction par une action (Crypt of Perpetual
  Darkness, p. 5). *Coût :* déjà couvert plus haut — mécanique désormais
  récurrente d'une boîte à l'autre, bon candidat pour une implémentation
  générique unique.
- **Mare d'acide (Acid Pool Trap)** : contact = jet de 1 dé de combat, 1
  Body Point sur un crâne ; **reste sur le plateau en permanence** et
  affecte quiconque marche dessus par la suite, non désamorçable mais
  sautable comme une fosse. *Coût :* mineur — variante de piège
  « permanent » (contrairement aux pièges à déclenchement unique
  habituels) : nécessite qu'une case de piège reste active après un
  premier déclenchement au lieu d'être consommée.
- **Monstre-piège à distance auto-déclenché (Dread Skull)** : apparaît dès
  qu'un héros le voit et attaque **immédiatement**, y compris hors du tour
  normal de qui que ce soit, avec 1 seul Body Point et 0 défense (meurt à
  la première égratignure). *Coût :* entité hybride entre piège et
  monstre — un déclenchement par simple ligne de vue (pas par déplacement
  sur une case) suivi d'une attaque immédiate hors séquence de tour,
  absent du modèle actuel qui ne réagit qu'aux actions du joueur.
- **Obscurité magique pénalisante** (antre du dragon) : tant qu'un héros
  n'a pas de vision nocturne, ses deux premières attaques dans la zone
  subissent -2 dés de combat ; les monstres n'en souffrent jamais. *Coût :*
  modificateur de zone temporaire sur le nombre de dés d'attaque, limité
  aux N premières attaques d'un héros donné — nécessite un compteur
  d'attaques par héros et par zone, pas seulement un buff/debuff binaire.
- **Substitution de monstre par couleur** (règle de secours, p. 5) : si le
  stock d'un type de monstre est épuisé, le MJ peut utiliser n'importe
  quel monstre « de la même couleur ». *Coût :* négligeable côté moteur
  numérique — la contrainte physique de figurines ne s'applique pas à un
  jeu digital, cette règle est donc sans objet une fois portée.
- **Familier à usage narratif unique** (la souris) : une action hors
  combat qui révèle par avance le contenu d'une salle avant d'y entrer,
  utilisable une seule fois sur toute la campagne. *Coût :* mineur —
  nouvelle action de reconnaissance qui lève le brouillard d'une salle
  adjacente sans y entrer, à greffer sur le système de brouillard existant.

---

## First Light (2024)

**Source :** un seul PDF trouvé, réf. G0978
(`avalon-hill-heroquest-first-light-game-system-board-game`), 30 pages
imprimées / 15 pages PDF, entièrement extrait (un feuillet illustré, page
13, a échoué à l'extraction pour cause de flux image trop volumineux —
contenu probablement décoratif, sans texte de règle repéré alentour).
⚠ **Seules `en-us` et `de-de` existent pour cette boîte** (`en-ca` et
`en-gb` renvoient une 404) — pas de variante cachant un second document
comme pour le jeu de base.

⚠ **Ce PDF est le *livret de règles* (« GAME SYSTEM RULEBOOK »)
uniquement.** First Light est un **coffret autoportant** : une nouvelle
édition boîte du système de base (mêmes figurines de héros/monstres, même
plateau, mêmes 6 actions, mêmes règles — le livret l'affirme noir sur
blanc : « *There are no rules changes from the 2021 HeroQuest Game
System* », p. 6) accompagnée d'un **livret de quêtes séparé de 10 quêtes**
(« Quest book featuring 10 quests », listé dans le contenu de la boîte)
qui n'est **pas publié séparément sur `instructions.hasbro.com`** — seule
la première quête (« The Border Fort of In-Gulden ») apparaît, citée en
exemple dans le livret de règles lui-même pour illustrer la mise en place.
⚠ **Les 9 quêtes restantes de First Light n'ont pas pu être dépouillées**
faute de livret de quêtes accessible.

> **Mise à jour 2026-09-27 — le livret de quêtes est en partie arrivé.**
> René en a photographié les pages **6-7** (chapitre de règles) et **20-21**
> (« Component Reference »). Ce qu'elles apportent est transcrit au §6
> ci-dessous. Les §1 à §5, établis sur le seul livret de règles, restent
> exacts **pour ce livret** : c'est le livret de quêtes qui porte les
> nouveautés, pas lui.

### 1. Nouveaux héros jouables

⚠ **Aucun.** Les 4 héros de base (Barbare, Nain, Elfe, Magicien), avec une
**divergence mineure confirmée** par rapport au jeu de base 2021 : « *The
dwarf's starting weapon is a handaxe instead of a shortsword* » (First
Light, p. 6) — seul changement d'équipement de départ documenté dans toute
cette boîte.

### 2. Nouveaux monstres

⚠ **Aucun nouveau type.** Le contenu de la boîte reprend exactement le
bestiaire du jeu de base (« *31 monster pieces: 8 orcs, 6 goblins, 3
abominations, 4 Dread warriors, 1 Dread sorcerer, 1 gargoyle, 4 skeletons,
2 zombies, 2 mummies* », First Light, p. 6) — confirmation utile en creux :
**l'Abomination fait bien partie du bestiaire standard de la réédition
2021**, ce qui explique pourquoi Kellar's Keep (même année) l'utilisait
sans en redonner les statistiques. ⚠ Une carte de monstre « Abomination »
apparaît dans une planche de vignettes miniatures du livret (p. 5, photo
de mise en place) avec un texte de fiche techniquement extractible
(« *3 2 3 3 6* ») mais **l'ordre des colonnes n'a pas pu être vérifié
visuellement** (la vignette est trop petite pour être lue sur le rendu de
la page, contrairement aux tableaux de tournoi d'Ogre Horde qui ont pu
être confirmés à l'image) — valeur **non retenue**, marquée non fiable
plutôt que présentée comme un fait.

### 3. Nouveaux objets, artefacts, sorts

- **Dragon** — 1 figurine et 1 carte de jeu inédites (« *Dragon miniature
  and game card* », p. 6), présentées comme la nouveauté matérielle
  principale de la boîte. ⚠ Aucune statistique ni règle d'utilisation
  trouvée dans le livret de règles extrait — probablement détaillée dans
  le livret de quêtes introuvable.
- Les cartes d'équipement, sorts élémentaires (Terre/Air/Feu/Eau) et sorts
  Dread visibles dans ce livret sont celles du jeu de base, réimprimées
  à l'identique (Genie, Heal Body, Courage, Veil of Mist, Firestorm,
  Battleaxe, Holy Water, etc.) — aucune nouveauté de contenu.

### 4. Nouveau mobilier, nouvelles tuiles

⚠ Le contenu de la boîte (portes, tuiles, mobilier) reprend à l'identique
la liste du jeu de base 2021 — aucune pièce inédite listée.

### 5. Nouvelles mécaniques de règle

⚠ **Aucune règle nouvelle** — le livret le confirme explicitement page 6.
Une **règle optionnelle** est toutefois formalisée pour la première fois
dans un livret officiel : le mobilier peut, au choix de la table, **bloquer
la ligne de vue et le déplacement** comme un mur, alors que dans le jeu de
base il n'a qu'une valeur d'ambiance (First Light, p. 8 : « *In the
original HeroQuest, furniture was used for atmosphere – it didn't obstruct
movement or line of sight. Zargon, if you and your table want a more
strategic game experience, you can treat furniture as though it blocks
line of sight and cannot be moved through.* »). *Coût :* option de
configuration simple — un flag « le mobilier bloque la vue/le passage »
appliqué globalement à la génération de carte et au calcul de ligne de
vue, sans changer la structure des données existantes.

### 6. Livret de quêtes — pages photographiées par René (2026-09-27)

**Source :** deux scans du livret de quêtes physique, déposés par René dans le
dossier Drive des sources officielles
(`https://drive.google.com/drive/folders/1seESGzXRhVw7ijIPuRVisaE36BPPoJ53`) :
`first_light_questbook_rules.pdf` (1 page PDF = pages imprimées **6 et 7**) et
`component_reference.pdf` (2 pages PDF = pages imprimées **20 et 21**). Les
citations `FL-Q p. N` renvoient au numéro imprimé. ⚠ Que les pages 20-21 soient
bien de ce livret se déduit du dépôt commun et de la mise en page identique ;
**aucune des deux pages ne porte le titre du livret**.

#### 6.1 Règles (FL-Q p. 6-7)

- **Bénédictions, malédictions et trésors** — effets accordés au fil des quêtes,
  notés sur la fiche du héros (p. 6).
  - *Oracle's Blessing* : au choix, demander à Zargon de **révéler une salle
    derrière une porte fermée adjacente**, OU, après un jet d'Attaque, de Défense
    ou de mouvement, **relancer TOUS les dés** en gardant obligatoirement le
    second résultat. Disparaît après usage.
  - *Oracle's Curse* : le jeton **Mark of Zargon** est posé sur la fiche du
    héros. Une fois par quête, Zargon peut le prendre et forcer le héros à
    **relancer tous les dés d'un jet**, puis **choisit** lequel des deux
    résultats est gardé ; le jeton revient sur la fiche au début de chaque
    quête. Levée : don de **800 po** à des œuvres charitables, entre deux
    quêtes, lors du passage à l'armurerie.
  - *Glyph Key Scroll* : à tout moment, sans action, **détruit un glyphe
    magique découvert** ; sans effet sur les glyphes « dérangés par le
    pillage ». Disparaît après usage. ⚠ La règle des glyphes eux-mêmes n'est
    pas sur ces pages.
  - *The Hearthkin Horn* : une action ; chaque héros place un squelette sur une
    case de sa salle ou de son couloir. Chaque héros contrôle le sien, qui joue
    après lui, **se défend sur les boucliers blancs** et quitte le jeu en fin
    de quête. Le cor tombe en poussière après usage.
    **Hearthkin Skeleton — Move 8 · Attack 2 · Defend 2 · Body 1 · Mind 0.**
- **Début et fin de quête** (p. 6) : les héros ne partent pas toujours de
  l'escalier en colimaçon. Des flèches **Enter / Exit** sur la carte de quête
  marquent l'entrée et la sortie ; en début de quête, les héros s'alignent
  devant la porte et demandent à Zargon de l'ouvrir.
- **Large Monsters** (p. 6) : un monstre de 2 cases peut attaquer toute créature
  sur les **10 cases** qui l'entourent (même règle qu'Ogre Horde, §5 de cette
  boîte-là).
- **Dread Spells** (p. 6) : un monstre peut lancer chaque sort qu'il connaît
  **une fois par quête**, même si un autre monstre a déjà lancé ce sort.
  Certains monstres ont des sorts **« at will »** : toujours disponibles, au
  prix d'une action.
- **Monsters Without Mind Points** (p. 7) : un sort qui affecte les Mind Points
  (ex. Sleep) ne peut viser un monstre sans Mind (ex. Zombies).
- **Unthreatened Movement** (p. 7) : sans monstre actif sur le plateau, chaque
  dé rouge de mouvement peut être compté comme un **4** au lieu d'être lancé
  (2 dés → 8 cases, 1 dé → 4 cases).
- **Item Exchange** (p. 7) : un héros peut passer potion, artefact, arme ou tout
  objet à un héros **adjacent**, si **aucun des deux n'est adjacent à un
  monstre** ; échanges libres entre les quêtes.
- **Double-Sided Gameboard** (p. 7) : le plateau a deux faces, la carte de quête
  dit laquelle utiliser.
- **Healing Hearth** (p. 7) : un héros qui devrait tirer une carte trésor dans
  une salle **avec une cheminée** peut, à la place, **récupérer 1 Body Point**.
- **Sly Storage** (p. 7) : le **premier** héros à tirer une carte trésor dans une
  salle **avec une armoire** en tire **deux**, résolues dans l'ordre.
- **Qwindrak the Warlock** (p. 7) : à chaque rencontre il prend une ou plusieurs
  formes (*Synchroforms*) aux statistiques propres, lance toujours ses sorts
  quelle que soit la forme ; vaincu, la forme quitte le plateau, les sorts
  actifs cessent, et les notes de quête disent la suite (nouvelle forme à la
  même place, vraie apparence, ou fuite).
  - *Dragon Form* : les notes de quête indiquent combien de **tuiles Skull**
    placer sous la figurine — elles marquent les Body Points déjà perdus par le
    Dragon.
  - *Draconic Flight* : le Dragon peut interrompre son mouvement pour agir puis
    le finir, et **traverser des cases occupées** (sans y finir).
  - **Qwindrak the Warlock — Move 6 · Attack 3 dés · Defend 6 dés · Body 3 ·
    Mind 6.** Source : photo de René (2026-09-30), notes de quête du livret
    First Light — tableau intitulé « *Qwindrak the Warlock* » ; ⚠ numéro de
    page et nom de la quête **non visibles** sur la photo. Le texte qui
    précède le tableau décrit la confrontation finale :
    - les monstres listés par la quête sont affrontés tour à tour (« shuffle
      the [deck] … deal a new faceup monster card » à chaque monstre vaincu,
      jusqu'au dernier — ⚠ début de phrase et mots du milieu illisibles sur la
      photo, reflet) ;
    - le dernier vaincu, Qwindrak prend sa **forme de Dragon** et souffle la
      **flamme du Dread** : chaque héros **dans la même salle** que lui lance
      **un dé rouge** ; sur **3 ou moins**, il perd **1 Body Point** ;
    - la forme de Dragon vaincue, la bataille est finie et les héros ont gagné.
  - **Dragon — Move 10 · Attack 5 dés · Defend 5 dés · Body 7 · Mind 6.**
    Source : photo de René (2026-09-30), **carte de monstre** « *Dragon* »,
    © 2024 Hasbro (First Light). Texte de la carte, intégral : « *The dragon
    uses Draconic Flight and may cast Ball of Flame at will.* »
    - *Draconic Flight* : règle du livret de quêtes p. 7, ci-dessus.
    - *Ball of Flame* : la carte de sort Dread du jeu de base (`09_bestiaire.md` l. 119, notre
      « Boule de Flammes ») ; **« at will »** = toujours disponible, au prix
      d'une action (FL-Q p. 6, *Dread Spells*, ci-dessus) — à l'opposé du
      « une fois par quête » des autres sorts de monstre.
    - ⚠ **Taille de la figurine non trouvée** : ni la carte ni les pages
      photographiées ne disent si le Dragon est un *Large Monster* (2 cases,
      FL-Q p. 6). Ne pas le supposer.
    - ⚠ **Combien de tuiles Skull** placer sous lui à l'apparition (*Dragon
      Form*, Body déjà perdus) : fixé par les notes de quête, non trouvé.
    - La **flamme du Dread** de la confrontation finale (un dé rouge par héros
      de la salle, 1 Body sur 3 ou moins) est une règle de la QUÊTE (notes de
      quête, ci-dessus), pas de la carte : elle ne suit pas le Dragon ailleurs.
  - Les autres **Synchroforms** de Qwindrak ne sont pas chiffrées.
  - *Première fouille de trésor* (même page) : texte à lire, purement narratif
    — l'œuf du SeerScale éclot, **Alousen**, fille d'Ethlarin, « la dernière
    du SeerScale », remercie les héros et s'envole. Aucun effet de jeu.
- *Tips for Zargon* (p. 7) : renvoie à « **A Message from Mentor** », p. 8
  (non photographiée).

#### 6.1bis Pages 8, 9 et 11 (FL-Q) — scan de René, 2026-09-30

**Source :** `first_light_questbook_rules_part2.pdf` (Drive des sources
officielles, 3 pages PDF = pages imprimées **8**, **9** et **11** ; p. 8 sans
numéro visible, déduite de l'ordre).

- **p. 8 — « A Message from Mentor »** : texte narratif uniquement (Zargon
  ancien apprenti de Mentor, la Reine-sorcière **Kessandria**, **Sir Ragnar**,
  l'orc **Ulag**). Aucune règle.
- **p. 9 — « Quest Map Guide »** :
  - couleurs des symboles de carte : **rouge** = pièges et mystères que les
    héros peuvent détecter en fouillant ; **vert clair** = pièges et dangers
    qu'ils ne peuvent **ni voir ni détecter** en fouillant ; **bleu** =
    éléments à interaction propre décrite dans les notes ; **vert foncé** =
    symboles de monstres ;
  - une note précédée d'une **lettre capitale** renvoie à la même lettre sur
    la carte ; un terme en *italique* = un objet à carte du pack ; un monstre
    à majuscule = une carte de monstre ; un texte « entre guillemets » est lu
    aux héros ;
  - symboles de monstres : Dragon, Gargoyle, Dread Warrior, Zombie, Goblin,
    Abomination, Mummy, Skeleton, Dread Sorcerer, Orc. ⚠ Le symbole du
    **Dragon est un ovale de 2 cases de large** — recoupe la photo de la
    figurine (René, 2026-09-30) : le Dragon est un **grand monstre**.
- **p. 11 — Quête 1, « The Border Fort of In-Gulden »** : carte complète
  (flèches **ENTER**/**EXIT**, tuiles Skull) et notes. Ce sont des
  **événements propres à cette quête**, non des règles générales :
  - *Ambushed Scouts* : chaque tuile Skull est un éclaireur tombé, sans effet
    sur le mouvement ; une action pour fouiller un éclaireur **adjacent** :
    1 dé rouge — 1-2 rien, 3-4 **15 po**, 5-6 **40 po** ; la première fouille
    révèle aussi une note (« …is a traitor and seeks the Night Egg ») ;
    la tuile est retirée après fouille ;
  - Sir Ragnar donne au groupe deux *Potions of Healing* au départ ;
  - **A** : passé le dernier héros, le plafond s'effondre — tuile double
    case bloquée sur les cases A, l'escalier retiré (pas de retour) ;
  - **B** : premier à fouiller le trésor, **200 po** et un *Wizard's Staff* ;
  - **C** : ouvrir l'une de ces portes ouvre **toutes** les portes C et place
    les monstres ;
  - **D** : le capitaine du Dread **Sha-Del** ; premier à fouiller, **300 po**
    et une note ensanglantée (l'armée de Kessandria cherche **Qwindrak** à
    Tuel-Vor) ; la note trouvée, la quête est accomplie et les héros peuvent
    sortir ;
  - **monstre errant de la quête : Goblin**.

#### 6.2 Component Reference (FL-Q p. 20-21)

Nom, type et icône (symbole de carte + illustration) de 30 composants — **aucune
règle, aucune emprise chiffrée** :

- **Tuiles** : Blocked-square, Double blocked-square, Dungeon grate, Eye of
  Mentor, Falling Block, Mark of Zargon, Pit trap, Secret door, Skull, Stairway
  (trois illustrations d'escalier).
- **Mobilier** : Alchemist's bench, Bookcase, Cupboard, Fireplace, Rack, Weapons
  Rack, Sorcerer's table, Table, Throne, Tomb, Treasure chest (onze types, les
  mêmes que la légende LQ p. 33 du jeu de base — voir `17_mobilier.md` §1).
- **Porte** : Dungeon door.
- **Monstres** : Abomination, Dread sorcerer, Dread warrior, Gargoyle, Goblin,
  Mummy, Skeleton, Zombie. ⚠ L'**Orc** n'y figure pas, alors que la boîte en
  contient 8 (p. 6 du livret de règles) ; la raison n'est pas donnée.

Ce que la page 20-21 **ne dit pas** et qui reste ⚠ non trouvé : l'effet d'**Eye
of Mentor** (peut-être lié à « A Message from Mentor », p. 8 — hypothèse non
vérifiée) et de **Dungeon grate**, ainsi que l'emprise de la **Fireplace**, du
**Rack** et de la **Sorcerer's table**. Mark of Zargon et Skull sont des
**jetons**, pas des tuiles de carte (§6.1).

---

## Rogue Heir of Elethorn (2022, gamme « Hero Collection »)

**Source :** ⚠ **livret non trouvé.** Contrairement aux 11 boîtes
précédentes, cette référence (F5814) appartient à la gamme **Hero
Collection** — un pack de figurines (« *2 finely-detailed miniatures, 12
game cards, and story card* », confirmé sur `consumercare.hasbro.com`) et
non un livret de quêtes complet. Vérification systématique menée avant de
conclure à l'absence :
- Aucune page `instructions.hasbro.com` n'existe pour ce produit, testée
  sur `en-us`, `en-ca` et `en-gb` (404 sur les trois, sur 4 variantes de
  slug essayées).
- La page produit `consumercare.hasbro.com` propose un bouton « Request
  Instructions » plutôt qu'un lien PDF — signe que ce pack n'a jamais eu
  de livret publié en téléchargement, seulement une notice à la demande.
- L'index interne du moteur de recherche d'`instructions.hasbro.com`
  (page de résultats capturée pour une autre boîte) liste bien toutes les
  autres extensions HeroQuest par leur slug, mais **aucune entrée ne
  contient « elethorn » ni « rogue »** — confirmation que ce produit n'est
  simplement pas indexé sur le site.

Le contenu ci-dessous provient donc **exclusivement de sources tierces**
(revues et articles spécialisés) et doit être vérifié contre une carte
physique avant toute intégration au moteur.

### 1. Nouveaux héros jouables

**Le Rogue (voleur/fine lame), classe « Elethorn »** — vendu en 2 sculpts
identiques en mécanique (un masculin, un féminin). « (source tierce, à
confirmer, via geekdad.com et zatu.com — aucune valeur officielle
trouvée) » :
- Commence avec une **Dague à 1 dé d'attaque** ; ne peut porter ni armure
  métallique ni bouclier.
- Équipement nommé : **Bandolier** — sert à la fois d'outil de
  désamorçage de pièges (comme une trousse à outils) et garantit d'avoir
  toujours une dague sous la main.
- 3 capacités spéciales citées avec leur texte (source tierce) :
  *Ambidextrous* (« permet une attaque supplémentaire à la dague la
  première fois que le héros attaque à la dague ou à l'épée courte »),
  *Opportunistic Striker* (« lance un dé de combat supplémentaire en
  attaquant un monstre adjacent à un autre héros »), *Combat Mobility*
  (« se déplace sans être vu à travers des cases occupées par des
  monstres »).
- ⚠ Dés d'attaque/défense de base, Body Points, Mind Points et
  déplacement chiffrés **non confirmés par une source fiable** — une
  synthèse de recherche non citée avançait Attack 1 / Defend 2 / Body 5 /
  Mind 4, mais faute de citation exacte d'une carte, cette valeur n'est
  **pas retenue** ici.

### 2-5. Monstres, objets, mobilier, mécaniques

⚠ Non trouvé — ce pack ne contient ni monstre, ni quête, ni mécanique de
règle nouvelle : uniquement les 2 figurines de héros, leurs cartes
d'équipement/capacités, et une carte d'histoire.

---

## Path of the Wandering Monk (2023, gamme « Hero Collection »)

**Source :** ⚠ **livret non trouvé** — même situation que Rogue Heir of
Elethorn : pack Hero Collection (F9527, « *2 highly detailed Monk figures
..., 7 game cards and a scroll* »), aucune page `instructions.hasbro.com`
sur aucune des 3 régions testées, aucune entrée dans l'index de recherche
du site.

### 1. Nouveaux héros jouables

**Le Moine (Monk)** — vendu en 2 sculpts. « (source tierce, à confirmer,
via geekdad.com) » :
- Ne peut porter ni armure ni bouclier ; peut manier **dague, arbalète,
  hachette, épée courte et bâton**.
- **1 dé d'attaque de base, 2 dés à mains nues.**
- Comparé aux autres héros, dispose de **+1 dé de défense et +1 Body
  Point** (base de comparaison non précisée par la source — probablement
  l'Elfe, le profil le plus proche).
- **4 « Styles Élémentaires »** (Elemental Styles), un jeu de capacités de
  combat à mains nues inédit dans le jeu — texte cité par la source
  tierce :
  - *Style de l'Air* — réussit automatiquement un saut de piège, ou
    attaque à mains nues tous les ennemis adjacents en une fois.
  - *Style de la Terre* — cherche pièges et portes secrètes en une seule
    action, ou lance 2 dés d'attaque supplémentaires à mains nues.
  - *Style de l'Eau* — scinde son déplacement avant et après son action,
    ou annule tous les dégâts subis ce tour.
  - *Style du Feu* — tire un rayon d'énergie en ligne droite ou diagonale
    jusqu'à un mur/porte fermée, infligeant 2 dégâts à tous les ennemis
    traversés ; ou inflige 1 dégât immédiat à un ennemi adjacent puis 2
    dégâts supplémentaires différés à la fin du tour suivant de cet
    ennemi.
- ⚠ Attack/Defend/Body/Mind/Move chiffrés absolus, non confirmés.

### 2-5. Monstres, objets, mobilier, mécaniques

⚠ Non trouvé — même limite que Rogue Heir of Elethorn : pack de héros pur,
sans contenu de donjon.

---

## HasLab Mythic Tier (2021) — Barde, Druide, Warlock

**Source :** ⚠ **aucun livret.** Le Mythic Tier est le palier haut du
financement participatif HasLab de la réédition 2021 : un lot de figurines
et de cartes, jamais accompagné d'un livret de quêtes propre. Hasbro Pulse
le décrit comme « *27 new miniatures […] and four completely new hero
characters* » (`old.hasbropulse.com`, billet « HeroQuest: HasLab Mythic
Tier First Look »).

⚠ **Recherche d'images de cartes menée et infructueuse** (2026-08-11), ce
qui est la raison d'être de cette section : sans elle, la même impasse
serait refaite. Testés sans succès — Cults3D (403 sur deux pages « + Cards »
pourtant annoncées comme des scans haute résolution), `heroquest.fandom.com`
(402), BoardGameGeek (403), et huit noms de fichiers plausibles chez
Sjeng / Ye Olde Inn (`sjeng-heroes.pdf`, `sjeng-hero-cards.pdf`… tous en 302,
avec `sjeng-equipment.pdf` à 200 comme témoin : **il n'existe pas de paquet
de cartes de héros** dans la série qui nous a servi pour l'armurerie, les
artefacts et le bestiaire). Le fil dédié de Ye Olde Inn (« Mythic Tier Bard,
Druid, Warlock character/spell card scans? ») existe mais reste sans scans
publiés, copyright invoqué.

### 1. Où vivent réellement les règles de ces trois héros

C'est le point qui **corrigeait une erreur de ce document**. Les figurines
sortent du Mythic Tier, mais les **cartes de personnage** ont été publiées
avec des boîtes de détail :

| Héros | Figurine | Carte de règles publiée avec |
|---|---|---|
| **Barde** (Orc) | Mythic Tier | *Spirit Queen's Torment* — d'où la règle de remplacement posthume, elle, sourcée (voir §Spirit Queen's Torment) |
| **Druide** | Mythic Tier | *Against the Ogre Horde* |
| **Warlock** (halfling) | Mythic Tier | *Prophecy of Telor* |

Les sections de ce document consacrées à ces deux boîtes concluent « aucune
nouvelle classe » : **c'est exact pour le livret de quêtes**, seul objet de
l'extraction, et faux pour la boîte. La note ‡ du tableau de synthèse
(« miniature Druid Hero, rôle non confirmé ») était le symptôme de cet
angle mort. Aucune de ces cartes n'est dans un PDF Hasbro — même limite
que l'armurerie et le tableau des 8 monstres de base.

### 2. Fiches de personnage — TRANSCRITES DE LA CARTE (2026-08-11)

Photos des trois cartes fournies par René, lues à l'écran. C'est la **même
voie que les prix d'équipement et le tableau des 8 monstres de base** : le
composant est cartonné, aucun PDF ne le porte, la photo est la seule entrée.
Mention de copyright visible : © 2021 Hasbro.

| | Attaque | Défense | Body | Mind | Déplacement | Arme de départ | Armure |
|---|---|---|---|---|---|---|---|
| **Barde** | 2 | 2 | 5 | 4 | 2 dés rouges | Dague | — |
| **Druide** | 1 | 2 | 6 | 4 | 2 dés rouges | Dague | Aucune |
| **Warlock** | 2 | 2 | 4 | 5 | 2 dés rouges | Baguette | Aucune |

⚠ **Les résumés tiers étaient faux sur plusieurs points**, ce qui justifie
après coup d'avoir refusé de les semer : le Druide était donné tantôt à
1 attaque / 2 défense (juste), tantôt à 2 / 1 avec 6 Mind (faux — Mind 4) ;
et *Shapeshift* était résumé comme accordant « +1 Body », que la carte ne
donne pas du tout.

**Texte des fiches :**

- **Barde** — « *You are the Bard. Your songs invigorate your allies. You
  prefer to stay light on your feet so when you are wearing no "metal" armor
  and carrying no shield you have 1 extra defend die. You have 3 bard spells
  at your disposal.* » ⇒ un **bonus conditionnel** de défense, pas une
  restriction : il peut porter du métal, il y perd le dé.
- **Druide** — « *You are the Druid, a woodland guardian and healer. […] A
  Druid hero may not wear metal armor.* » ⇒ interdiction ferme, elle.
- **Warlock** — « *You are the Warlock, a magical artillerist who has bonded
  with a sinister creature in winged form. […] The Warlock follows the same
  rules for wearing armor as the wizard.* » ⇒ se branche sur nos tags
  `armure_magicien` existants.

### 3. Sorts — texte intégral des cartes

**Barde** (3 sorts) :
- **Inspiring Tale** — « *This spell may be cast on any one hero, excluding
  yourself. The next time that hero attacks, they may roll 1 extra combat die.
  Regain this spell when any hero you can see, excluding yourself, rolls
  Defend dice that result in 2 white shields* »
- **Lullaby** — « *This spell puts one enemy into a deep sleep so they cannot
  move, attack, or defend themselves. The spell can be broken at once or on a
  future turn if the enemy rolls 1 red die for each of their Mind Points. If a
  6 is rolled, the spell is broken. May not be used against mummies, zombies,
  or skeletons* » ⇒ c'est notre *Sommeil*, avec la même exception Mind 0.
- **Healing Song** — « *You and all the heroes that you see restore up to 2
  lost Body Points each. The spell does not give a hero more than their
  starting number.* »
- **Rapier** (250 po) — « *Weapon - This long, slender sword allows you to roll
  2 Attack dice and may be used to attack diagonally. May not be used by the
  wizard* » ⇒ `attaque_diagonale`, tag hors magicien.

**Druide** (3 sorts) :
- **Shapeshift** — « *Shapeshift grants you 1 Defend dice and 1 extra Attack
  dice when attacking a monster that you are adjacent to. The spell is broken
  when the hero suffers 1 Body Point of damage. Regain this spell when your
  Body Points return to their starting number* »
- **Pixie** — « *This spell conjures up a pixie who does one of the following:
  restore up to 2 Body Points that have been lost to one hero or search. The
  pixie reveals all traps and secret doors in any location you can see* »
- **Lifeforce** — « *This spell may be cast on any one hero, including
  yourself. Its magical power immediately restores up to 4 lost Body Points,
  but it does not give a hero more than their starting number* »

**Warlock** (3 sorts) :
- **Fear** — « *This spell causes any one monster to become so fearful that
  their attacks are reduced to 1 combat die. The spell can be broken on a
  future turn by rolling 1 red die for each of their mind points. If a 6 is
  rolled, the spell is broken.* »
- **Dark Wings** — « *Cast this spell on an enemies turn after you have
  suffered damage. Reduce that damage to zero and move instantly to any
  unoccupied square you can see.* » ⇒ **réaction hors tour**, mécanique que
  le moteur n'a pas (tout y est résolu dans le tour de l'acteur).
- **Demonform** — « *The warlock ignores pit traps and may roll 1 extra combat
  die when they attack, until the spell is broken. The spell is broken when
  the hero suffers 1 Body Point of damage. Regain this spell when you reduce a
  monster's Body Points to zero.* »
- **Wand** (125 po) — « *This wand gives you 2 Attack dice against any monster
  you can see. It can be used only by the warlock.* » ⇒ arme **à distance**
  réservée par tag.

**Note de conception, valable pour les trois** : *Shapeshift* et *Demonform*
partagent une forme que notre `DureeEffet` ne connaît pas — un buff **rompu
par le premier point de dégât subi**, puis **regagné à une condition
d'événement** (revenir à son Body de départ, tuer un monstre, voir un allié
faire 2 boucliers blancs). Ce n'est ni un compteur de tours ni un de nos cinq
mots-clés : c'est un sixième type de durée, à déclarer et à ancrer avant de
porter ces classes (voir `reference/19_mots_cles_effets.md`).

---

## Path of the Wandering Monk — fiche et Styles TRANSCRITS (2026-08-11)

Complète le §Path of the Wandering Monk ci-dessus, qui ne disposait que d'un
résumé tiers. Photos fournies par René + photo publique des cartes (© 2024
Hasbro).

**Fiche de personnage** — Attaque **1\***, Défense **3**, Body **6**,
Mind **4**, déplacement **2 dés rouges**.
« *\*When attacking unarmed, roll one additional Attack die.* » ⇒ 2 dés à
mains nues. Le résumé tiers (« +1 défense et +1 Body par rapport au héros le
plus proche ») se vérifie : c'est **la meilleure défense du jeu**, à égalité
avec personne — les 4 classes de base sont toutes à 2.

**Règle des Styles Élémentaires** (carte *Using Elemental Styles*) :
- « *Each Elemental Style contains two techniques that can be found on either
  side of its card. Once per turn, choose one technique to activate. After you
  use a technique, that Elemental Style is exhausted.* »
- « *The Elemental Style of Fire cannot be used until you have exhausted Air,
  Earth, and Water.* »
- « *If there are no monsters in your line of sight at the start of your turn,
  recover all exhausted Elemental Styles.* » ⇒ la condition de recharge est
  **exactement notre `combatTermine()`** (aucun monstre actif et révélé),
  déjà écrite pour `fin_du_combat`.

**Les 8 techniques** (4 cartes recto-verso) :

| Élément | Technique | Texte |
|---|---|---|
| **Air** | *Eye of the Storm* | « As an action, make one unarmed attack against all adjacent enemies. This technique enables you to attack diagonally. » |
| **Air** | *Soaring Dragon* | « Automatically succeed when jumping over a trap. » |
| **Terre** | *Strength of Mountain* | « Roll 2 additional Attack dice on an unarmed attack. » |
| **Terre** | *Speak with Stone* | Chercher portes secrètes et pièges **en une seule action**. ⚠ nom fourni par René, libellé exact non photographié |
| **Eau** | *Tidal Surge* | « Activate this technique on your turn to split your total movement roll before and after your action. » |
| **Eau** | *Twisting Torrent* | « Activate this technique when you take damage to cancel that damage. » ⚠ libellé rapporté par deux sources concordantes, non photographié |
| **Feu** | *Burning Spirit* | « As an action, expel a beam of brilliant energy from your soul's core. This beam may be straight or diagonal and continues until it meets a wall or closed door. Each enemy in the beam takes 2 Body Points of damage. » |
| **Feu** | *Touch of Endless Inferno* | « As an action, inflict 1 Body Point of damage to any one adjacent enemy. The target takes an additional 2 Body Points of damage at the end of its next turn. » |

**Les 8 techniques sont donc connues.** Seul le verso Eau (*Twisting
Torrent*) n'a pas été lu sur une photo : son nom et son effet sont rapportés
par deux sources indépendantes et concordantes. Un recoupement utile confirme
au passage la fiche : une source décrit *Strength of Mountain* comme donnant
« **four** attack dice for an unarmed attack » — soit 2 à mains nues + 2, ce
qui vaut confirmation croisée du `1*` et de sa note de bas de carte.

*Burning Spirit* est un **rayon en ligne** qui traverse et frappe tout sur son
passage : aucune de nos zones d'effet ne fait ça (`monstres_zone` a d'ailleurs
été retiré faute de source). *Touch of Endless Inferno* est un **dégât différé
au tour suivant de la cible** — même famille que le jeton de Rejeton, à ceci
près qu'il expire.

---

## Rogue Heir of Elethorn — cartes TRANSCRITES (2026-08-11)

Photo publique des 5 cartes (© 2022 Hasbro).

**Fiche de personnage** — Attaque **1**, Défense **2**, Body **5**,
Mind **4**.

✅ **Confirmé sur image** le 2026-08-11 (numérisation de René) : la carte donne
exactement **A1 D2 B5 M4**, déplacement **2 dés rouges**, arme de départ
**Dague** — ce que la source tierce annonçait, au chiffre près. Elle
n'énonce en revanche **aucune restriction d'armure** : le « ni armure
métallique ni bouclier » ne vient que de sources tierces, et la carte
*Bandolier* établit seulement qu'il est « *always considered to be armed with a
dagger* ».

| Carte | Texte |
|---|---|
| **Combat Mobility** | « You may move unseen through spaces occupied by monsters. Do not discard after use. » |
| **Ambidextrous** | « Once per turn, when you attack with a shortsword or dagger you may make one additional attack with a dagger. Do not discard after use. » |
| **Opportunistic Striker** | « Once per turn, you may throw 1 extra combat die when attacking a monster next to another hero. Do not discard after use. » |
| **Bandolier** (300 po) | « Counts as a Tool Kit for disarming traps and you are always considered to be armed with a dagger. It can only be used by the Rogue. » |
| **Dagger** (25 po) | « Weapon—This sharp knife gives you the attack strength of 1 combat die. A dagger can also be thrown at any monster you can see but is lost once it is thrown. » |

Les trois capacités tombent sur des mécaniques **déjà écrites** :
*Combat Mobility* est `agile` (`Grille::autoriserFranchissement()`),
*Opportunistic Striker* est `tacticien` (le dé de flanquement, écrit pour les
monstres), *Ambidextrous* est le motif `attaque_supplementaire` de la Potion
d'héroïsme, et le *Bandolier* est `permet_desamorcage`. La carte **Dagger**
confirme en outre notre `jetable` mot pour mot — « *is lost once it is
thrown* » —, là où doc 16 notait cette perte comme une divergence assumée du
plateau : **ce n'en est pas une**.

---


## Commander of the Guardian Knights (2021, gamme « Hero Collection »)

**Source :** ⚠ **livret non trouvé** — troisième pack Hero Collection,
même situation que Rogue Heir of Elethorn et Path of the Wandering Monk.
Réf. F5903, « *2 highly-detailed Champion of the Guardian Knights figures
on 25mm bases […] and 12 game cards* ».

### 1. Nouveaux héros jouables

**Le Chevalier-Gardien (Commander of the Guardian Knights)** — vendu en
2 sculpts. « (source tierce, à confirmer) » : profil de tank, « *3 unique
powers and knight skills* », un paquet de cartes propre nommé **Knight
Skills**, et une capacité liée au port de la **cotte de mailles**.

⚠ **Aucun texte d'effet trouvé, pour aucun des trois pouvoirs** — seulement
leur existence. C'est la différence avec le Rogue et le Moine, dont les
capacités sont citées mot pour mot par les sources tierces : ici il n'y a
rien à porter, seulement un nom. Semer cette classe reviendrait à inventer
intégralement ce qu'elle fait.

### 2-5. Monstres, objets, mobilier, mécaniques

⚠ Non trouvé — pack de héros pur, comme les deux autres Hero Collection.

---

## Wizards of Morcar (2025)

**Source :** livret de quêtes unique, réf. G1504
(`heroquest-wizards-of-morcar-quest-pack-requires-heroquest-game-system`),
44 pages imprimées / 23 pages PDF, entièrement extrait, © 2025 Hasbro
(impression du 15/07/2025). **Réédition étendue d'une extension existante** :
le livret le dit noir sur blanc, « *This quest book contains five exciting
quests that were not in the original version of Wizards of Morcar. If you
want to play the original quests only, play quests 3, 4, 5, 6, and 9 in
sequence* » (p. 6) — les cinq quêtes d'origine sont 3/4/5/6/9, les cinq
nouvelles sont 1/2/7/8/10 (7 et 8 forment une « Double Quest » unique,
*The Dread Shrine of Eiretash* / *Halls of the Dead*). 64 cartes de jeu,
27 figurines, 39 pièces cartonnées. Tous les blocs de stats ci-dessous ont
été **relus sur le rendu PNG** des pages 8-9 (mercenaires) et 40-41 (tableau
des monstres) — l'extraction texte y désalignait les colonnes (ex. le
Arbalétrier ressortait en texte comme « 2 2 3 3 6 » alors que la carte lit
Mouvement 6 / Attaque 3 / Défense 3 / Corps 2 / Esprit 2).

### 1. Nouveaux héros jouables

⚠ **Aucune nouvelle classe.** Le **Magicien** inclus dans la boîte est une
réimpression : « *Their statistics are the same as the Wizard in the
HeroQuest Game System and can be used to replace that hero if the player
prefers* » (p. 10).

**Sir Ragnar** — PNJ récurrent, pas une classe à la création : traître
retourné en allié au fil de la campagne. Quête 2 : « *Ragnar cannot be
killed in this quest (he always has at least one Body Point left)* »
(p. 19). Quêtes 7-8 : « *Players choose who will control Ragnar. He moves
and acts just like a hero. He may open doors, search for treasure, secret
doors, and traps, and attempt to disarm traps* » (p. 35) — un héros
temporaire, contrôlé par un joueur. ⚠ **Aucun bloc de stats chiffré pour
Ragnar nulle part dans le livret** — il n'apparaît pas au tableau des
monstres (p. 41, 8 entrées seulement) ; vit sur sa carte, non incluse au
PDF.

⚠ **Corrigé le 2026-10-05** : la carte de Sir Ragnar a été reçue et transcrite
(§Wizards of Morcar — cartes TRANSCRITES, §5) — **M7 A3 D5 B6 Mi2**, aucun
texte de capacité propre (bloc de stats pur). Le reste du paragraphe
ci-dessus reste exact : la carte n'est toujours pas dans le PDF du livret,
c'est la numérisation de René qui l'apporte.

### 2. Nouveaux monstres

**Tableau des monstres officiel** (« Monsters », p. 41, relu sur PNG) :

| Monstre | Move | Attack | Defend | Body | Mind | Lore |
|---|---|---|---|---|---|---|
| Storm Master | 6 | 4 | 6 | 5 | 7 | commandant des éléments, a sacrifié sa raison |
| Orc Warcaster | 7 | 5 | 5 | 5 | 7 | commandante aguerrie, forte influence magique sur ses orques |
| Necromancer | 6 | 4 | 6 | 4 | 7 | sorcier tordu, expérimente sur les morts-vivants |
| High Mage | 5 | 5 | 5 | 4 | 8 | maîtrise redoutable des forces du Dread |
| Artificer | 6 | 4+2* | 3 | 5 | 8 | artisane magique, imprègne ses objets |
| Dreadshifters | 5 | 4 | 3 | 2 | 4 | se déguise en objet du quotidien, bondit par surprise |
| Golems | 5 | 4 | 5 | 3 | 0 | serviteur de pierre, loyauté inébranlable |
| Minotaur | 7 | 4 | 5 | 6 | 4 | enfermé dans un labyrinthe souterrain, rage mesurée |

*(\* tant que le sort *Hammer of Ruin* est actif sur l'Artificer.)*

**Les cinq Sorciers du Dread nommés** (« Evil Sorcerers », p. 10) — lieutenants
de Morcar, correspondant à cinq des huit lignes ci-dessus : **Fanrax the
Malicious** (Necromancer), **Zanrath, High Mage of Sarako** (High Mage),
**Boroush, the Storm Master** (Storm Master), **Nyashak, Orc Warcaster of the
Northern Clan** (Orc Warcaster), et **la Gardienne/Keeper** (Artificer,
antagoniste finale). « *Each Sorcerer may cast one spell per turn instead of
attacking. Each spell may only be used once per quest. At the beginning of a
new quest, each Sorcerer in that quest starts with a full set of six spells*»
(p. 10) — six sorts chacun, propres à chaque sorcier (« *only they may use
it* »), aucun nommé sauf les effets génériques de plateau (Murs magiques,
Éclair, Séisme — §5). ⚠ **Les 30 cartes de sort (5 × 6) ne sont pas dans le
PDF** — seuls trois effets de plateau sont décrits en toutes lettres.

**Statistiques alternatives, SCRIPTÉES par quête** (pas des entrées de
catalogue, des redéfinitions ponctuelles) : Guerriers du Dread d'élite de la
garde de Zanrath, quête 3, p. 24 (M7 A5 D5 B3 Mi3) ; squelettes « créations
spéciales » de Fanrax, quête 4, p. 26 (M6 A3 D3 B1 Mi0) ; orques d'élite de la
garde de Nyashak, quête 6, p. 30 (M8 A4 D3 B1 Mi2, arc : 2 dés à distance) ;
squelettes « constructions de fer », quête 10, p. 38 (M6 A3 D3 B1 Mi0) ;
les quatre corps « réveillés » des sorciers avant leur forme véritable,
quêtes 7-8, p. 34 (M6 A4 D4 B2 Mi5, sans sort).

**Capacité générique — Ambush** (p. 6) : un monstre à embuscade (les
Dreadshifters de cette boîte) est posé sur le plateau comme l'objet qu'il
imite (porte ou coffre). Quatre déclencheurs de révélation : un héros entre
dans l'une des 8 cases autour → remplacé par la figurine, agit aussitôt ; un
héros fouille les pièges de la salle → révélé ; un héros fouille le trésor
→ le mobilier est retiré, le monstre posé adjacent et attaque **aussitôt**
(un jet d'attaque immédiat, hors tour) ; au tour du MJ, tous les embusqués
encore cachés sont révélés et jouent normalement.

**Minotaure nommé — Brak-Fellorn** (quêtes 7-8, p. 33) : stats = ligne
Minotaur du tableau (7/4/5/6/4). « *Any wound that would reduce him to less
than 1 Body Point is ignored. He may be killed once his heart is
destroyed* » — cœur caché dans un labyrinthe séparé (tuiles Tunnel, §4),
détruit par une action d'un héros adjacent au coffre qui le contient ; une
fois le cœur détruit, il meurt normalement au prochain coup fatal.

**Golems, note de quête (p. 19)** : « *When these Golems defend, one black
shield rolled blocks all hits* » — règle PONCTUELLE à une quête (des statues
de gardien), pas une capacité générale du Golem (dont le tableau p. 41 donne
Defend 5 dés standard).

### 3. Nouveaux objets, artefacts, sorts

**Boutique de l'Alchimiste** (p. 2) : *Potion of Fire Resistance* (300 po,
immunité à la prochaine attaque de feu magique, sort ou piège), *Potion of
Magical Aptitude* (400 po, lancer 2 sorts connus au lieu d'1 pendant ce
tour), *Potion of Healing* (500 po, 1 dé rouge de Body), *Potion of Magic
Resistance* (300 po, annule les effets du prochain sort à dégâts lancé sur
le buveur).

**Trois nouveaux répertoires de sorts de héros**, noms seulement : *Spells of
Protection*, *Spells of Detection*, *Spells of Darkness* — remplacent un
répertoire existant d'un lanceur (l'Elfe garde le sien, le Magicien les
trois siens). ⚠ Aucun texte de carte.

**Trois artefacts nommés par le texte de quête**, jamais statués : *Urdyn the
Unmaker* (quête 3, salle E, p. 24), *Drakehide Cuirass* (quête 5, trésor de
Boroush, p. 28), *Elixir of Life* (quête 2, salle C, p. 18 — « *if this is
already owned by a hero, find 2 Potions of Healing instead* »). ⚠ Aucun
texte de carte pour les trois.

**Huit cartes de trésor** ajoutées au deck de base, plus une carte « Nothing!»
optionnelle (p. 6) — ⚠ aucun texte, seul le nombre est donné.

**Règle « Unearth an Artifact »** (p. 6) : tirage d'un artefact non déjà
possédé par un héros **et n'appartenant pas à cette extension** — les trois
artefacts ci-dessus en sont donc explicitement exclus, trouvables seulement
par leur note de quête nommée.

### 4. Nouveau mobilier, nouvelles tuiles

**Murs magiques** (*Wall of Ice / Wall of Flame / Wall of Stone*, sorts, p.
10-11) : « *This barrier may be placed across two squares on the gameboard
to form a solid, impassable wall that will remain on the board until it is
destroyed* […] *Barriers defend with 6 Defend dice* […] *If the wall takes 1
Body Point or more of damage, it is destroyed* » — **1 point de vie**, posé
sur une ARÊTE de deux cases (pas une case), détruit par n'importe quel dégât.

⚠ **Corrigé le 2026-10-05** : la carte confirme mot pour mot l'arête de DEUX
cases ci-dessus (« *covers two 2 squares* », relu sur les trois cartes de
mur — §Wizards of Morcar — cartes TRANSCRITES). **René a cependant décidé
le 2026-10-04** (`docs/plan-chantiers-transverses-2026-10-04.md`), avant
réception de ces cartes, que **chez nous les Murs magiques occupent UNE
SEULE case** (meuble d'une case, pas une arête) — ils n'attendent donc plus
`docs/plan-murs-en-aretes.md` et passent par le lecteur générique « mobilier
à PV/défense » (chantier 1, déjà construit, cf. `Haut Autel`/`Coffre du
Dread` déjà seedés dans `MobilierSeeder` sous `boite: 'wizards_of_morcar'`).
C'est une divergence ASSUMÉE du livret, pas une erreur de lecture : la carte
dit deux cases, le jeu en fait une. Les trois murs ne sont PAS un seul sort
commun aux cinq sorciers : *Wall of Ice* est un sort du **Storm Master**
(Boroush), *Wall of Flame* un sort du **High Mage** (Zanrath) — tous deux
dans les 30 cartes de Sorcier du Dread, §Wizards of Morcar — cartes
TRANSCRITES — et *Wall of Stone* est au contraire un **sort de héros**, dans
le répertoire *Spells of Protection* (« *You create a magical wall of
stone…* », phrasé à la première personne, contrairement aux deux murs du
Dread phrasés « *The Spellcaster…* »). Le carton *Magic Reference Chart* de
la boîte réunit bien les trois sous une seule règle générique (miniature
posée, 1 PV, 6 dés de défense), ce qui explique le texte groupé du livret de
quêtes — mais aucun des cinq Sorciers ne porte *Wall of Stone* : ⚠ cette
incohérence apparente (le livret nomme les trois murs au même paragraphe
« Spell Special Effects ») n'est pas résolue plus loin, faute d'accès au
texte complet des p. 10-11 au-delà de ce qui est cité ici.

**Tuile Éclair** (*Lightning Strike*, p. 10-11) : ligne droite depuis le
sorcier, touche tout ce qui est aligné jusqu'au premier mur ou mur magique
rencontré (qui annule le sort et celui-ci en même temps si un Séisme le
percute, p. 12).

**Tuile Séisme** (*Earthquake*, p. 12) : 6 cases liées entre elles, chacune
jouant comme une fosse ; on ne sort qu'en grimpant sur une case dégagée
adjacente à la zone ; si la zone rencontre un mur donnant sur une salle/un
couloir jouable, **le mur est BRISÉ** (jeton de mur brisé posé, salle non
explorée révélée aussitôt) — sinon rien ne se passe.

**Tuiles Tunnel** (p. 12-13, détaillées aussi p. 33) : passages souterrains
liant deux points du plateau. Entrer dans un tunnel téléporte sur une case
libre adjacente au tunnel apparié ; dans la quête-labyrinthe (7-8), on lance
2 dés rouges et choisit l'un des deux résultats pour viser le tunnel numéroté
correspondant.

**Tuile Voile d'ombre** (*Cloak of Shadows*) : zone qui gêne la vue, liée à
un sort de héros (texte sur la carte, non trouvé).

**Pièges magiques** : voir §5. **Tuiles de clé** (argent/or), **tuile de mur
brisé**, **tuile Autel/Coffre-cœur** (recto-verso), **tuile Laboratoire de
l'Artificier** — composants de plateau, pas de règle propre.

**Coffres renforcés** (« *Reinforced Chests* », tous les coffres de cette
boîte, p. 6) : le butin n'est trouvé que la première fois qu'un héros
**adjacent au coffre** fouille la salle ; un héros qui fouille sans être
adjacent tire une carte de trésor ordinaire à la place. La salle reste
fouillable une seule fois par héros, comme d'habitude.

**Coffres du Dread** (quêtes 7-8, p. 34) : 1 point de vie, 6 dés de défense,
**immunisés au feu**, leur destruction libère le sorcier correspondant.

**Haut Autel** (objectif final, quête 10, p. 39) : pas un monstre — 6 points
de vie, 4 dés de défense, attaqué au combat normal ; sa destruction termine
la quête et la campagne.

### 5. Nouvelles mécaniques de règle

- **Trois pièges magiques** (p. 2, détaillés p. 7 et 12) : jamais trouvables
  à la fouille, à usage unique. *Fireburst* : un héros termine son tour dans
  la salle → jeton posé → explose au DÉBUT du tour suivant du MJ (3 dés
  d'attaque de feu sur tous, héros et monstres, dans la salle) ; désamorcé
  si un héros présent défausse un sort *Tempest* ou tout sort d'Eau.
  *Hurricane* : en couloir, franchir la case marquée « X » repousse tous les
  personnages du couloir de 8 cases en arrière (ou jusqu'au premier mur/
  piège rencontré). *Teleport* : finir son mouvement sur la case « A »
  téléporte sur la case « B » ailleurs sur le plateau, et désoriente (fin de
  tour immédiate).
- **Statut de Gardien (« Warden ») et mercenaires** (p. 8-9, dès la quête 2
  terminée) : jusqu'à **4 mercenaires par héros**, quatre types — *Crossbowman*
  (M6 A3 D3 B2 Mi2, 75 po, tir à distance), *Halberdier* (M6 A3 D3 B2 Mi2,
  75 po, attaque en diagonale), *Scout* (M9 A2 D3 B2 Mi2, 50 po, désamorce
  comme le Nain), *Swordsman* (M5 A4 D5 B2 Mi2, 100 po) — relus sur PNG
  (p. 8-9). **Entretien de 10 po par quête** par mercenaire survivant, sinon
  il quitte le groupe et doit être réengagé plein tarif ; seul le Scout
  fouille/désamorce, aucun ne fouille le trésor ni n'utilise d'objet.
- **Faveurs de Hopekins Rest** (« Boons of Heroism », p. 22-23, dès Gardien) :
  au début de chaque quête, un héros visite UN lieu parmi neuf et reçoit sa
  faveur, une seule à la fois. Cinq accordent une **compétence de héros
  inédite** (noms seulement, aucun texte de carte) : *Dead Eye*, *Weapon
  Expert*, *Healing Hands*, *Hold the Line*, *Peacekeeper*. Les quatre autres
  sont mécaniques et sourcées en toutes lettres : engager un mercenaire
  gratuitement, +2 points de vie de Body maximum, +1 Potion de guérison
  (cumulable), un répertoire de sorts supplémentaire pour un lanceur. Après
  la quête 10, la récompense passe à 2 faveurs par quête, choisies parmi 2
  lieux.
- **Don d'objet en quête** (« Passing Items », p. 6) : un héros peut tendre
  une potion, un artefact, une arme ou tout autre objet à un allié sur une
  case adjacente, à condition qu'aucun des deux ne soit adjacent à un
  monstre.
- Monstres à 0 point d'Esprit immunisés aux sorts mentaux, Grands monstres,
  Déplacement non menacé (4 cases par dé sans monstre actif), portes
  d'entrée/sortie fléchées : formulations identiques aux autres boîtes 2021+,
  déjà couvertes ailleurs dans ce document.
- **Statut « insubmersible » récurrent** : Sir Ragnar (quête 2, ne peut pas
  mourir), la Gardienne (ne tombe jamais sous un certain seuil avant la
  scène scriptée de sa disparition), Brak-Fellorn (immunisé sous 1 PV tant
  que son cœur, caché ailleurs sur la carte, n'est pas détruit) — un même
  patron scripté trois fois : un gabarit de quête fige un plancher de PV ou
  une condition tierce avant d'autoriser la mort.
- **Quête 1, embranchement de dialogue** (p. 16) : arrêter Krothgar ou non
  mène à deux scènes différentes, convergeant sur le même objectif (vaincre
  les mercenaires, atteindre la sortie) — de la couleur narrative, pas une
  vraie divergence de chemin.

### 6. Les dix quêtes (résumé)

| # | Titre | Antagoniste / objectif | Neuf (N) / Originale (O) |
|---|---|---|---|
| 1 | In Service to Gold | mercenaires de la Guilde (combat de diversion) | N |
| 2 | The Mercenary Captain | Sir Ragnar (retournement scripté) | N |
| 3 | Tower of the High Mage | **Zanrath** | O |
| 4 | Crypt of the Necromancer | **Fanrax** | O |
| 5 | Eyrie of the Storm Master | **Boroush** | O |
| 6 | Lair of the Warcaster | **Nyashak** | O |
| 7-8 | The Dread Shrine of Eiretash / Halls of the Dead | Brak-Fellorn puis les 4 sorciers ressuscités (double quête, ressources non restaurées entre les deux) | N/O |
| 9 | The Final Conflict | les 4 sorciers, formes finales | O |
| 10 | High Altar of the Dread Shrine | la Gardienne, Brak-Fellorn reborn, le Haut Autel | N |

---

## Wizards of Morcar — cartes TRANSCRITES (2026-10-05)

Complète le §Wizards of Morcar (2025) ci-dessus, qui n'avait que le livret de
quêtes. **Source** : les 63 cartes de la boîte rendues en PNG HD
(`drive/wizards-of-morcar/rendu/`, une image = une carte, fournies par René
le 2026-10-05) + le carton *Magic Reference Chart* (identique à la p. 10 du
livret) — **64 fichiers**, toutes les cartes © 2026 Hasbro (un an après le
livret de quêtes © 2025 : réédition en deux temps). Chaque carte lue
individuellement et intégralement à l'outil Read, **relue une seconde fois
systématiquement** après une première passe où plusieurs lots avaient été
perdus par la limite de requêtes média sans message d'erreur explicite —
**aucune carte illisible**. Recoupées avec `database/seeders/SortSeeder.php`,
`SortDreadSeeder.php`, `MonstreSeeder.php`, `MercenaireSeeder.php`,
`ObjetSeeder.php`, `config/cartes.php`, `App\Engine\MotsCles*`.

### 1. Les cinq Sorciers du Dread — 30 sorts (6 par sorcier)

Confirme le livret mot pour mot (p. 10 : « *each Sorcerer in that quest
starts with a full set of six spells* ») — **chaque sorcier a exactement
6 sorts**, aucun nom ne se recoupe d'un sorcier à l'autre. Recoupement avec
le tableau des monstres (§Wizards of Morcar 2025, §2) : *Hammer of Ruin*
(ci-dessous) EST le sort qui explique la ligne « Artificer … Attack 4+2\* »
du tableau officiel — confirmé mot pour mot.

#### Storm Master — Boroush (p. 41 du livret : M6 A4 D6 B5 Mi7)

| Carte | Citation | → chez nous |
|---|---|---|
| **Lightning Strike** | « The Spellcaster channels the power of a lightning storm in a straight, orthogonal line of 6 squares. Use the lightning storm tile to determine who is hit. Anyone hit must defend normally against 3 combat dice. Resolve each attack separately. » | Zone rayon EXISTE (`MotsClesSortDread::ZONE_RAYON`, lue par `MoteurDread::meilleurRayon()`, déjà exploitée par notre « Éclair de Chaos ») — portage direct, il suffit de fixer `des_degats` à 3 au lieu de dégâts fixes. |
| **Wall of Ice** | « The Spellcaster creates a magical wall of ice which covers two squares unoccupied by figures. The wall has 1 Body Point and rolls 6 Defend dice. Discard when the wall is destroyed. » | Voir §2 ci-dessous — mobilier à PV/défense (chantier 1, déjà construit, `pv_body`/`defense_dice`), UNE case chez nous (décision René 2026-10-04) contre deux sur la carte. |
| **Thieving Wind** | « This spell must be targeted at one hero. The hero loses one piece of equipment chosen at random. Discard after use. » | Rien d'équivalent : `vol_objet` existe (Gremlin des glaces) mais c'est une capacité de monstre AU CONTACT, jamais un sort à portée illimitée qui retire un équipement choisi au hasard. Nouveau mot-clé de sort Dread à écrire. |
| **Hurricane** | « The Spellcaster must cast this spell on a character they can see who is in a straight line in front of them. That character is then forced back in a straight line of squares until they hit a wall, another figure, fall down a pit trap or trigger another trap. Discard after use. » | Recul forcé existe (`MoteurPieges`, Chute de blocs, `['sens' => 'reculer']`) mais c'est un PIÈGE, pas un sort, et sans la limite de 8 cases du piège homonyme du carton (§9) — carte de sort et piège partagent le nom mais pas le texte, à ne pas confondre en les portant. |
| **Earthquake** | « The Spellcaster splits the ground asunder in a straight, orthogonal line of 6 squares. Lay the Earthquake piece on the board to determine who is affected. All those caught will suffer 1 Body Point of damage as if they had fallen into a pit trap. Discard after use. » | Dégâts « comme une fosse » = piège `Fosse` existant (`PiegeSeeder`), zone rayon pour le ciblage — portage direct. À distinguer de la tuile Séisme du livret (§Wizards of Morcar 2025, §4 : 6 cases liées, brise les murs), un composant de plateau plus élaboré, probablement une variante scénarisée propre à une quête plutôt que ce sort générique. |
| **Blinding Sleet** | « The Spellcaster fills a room with blinding sleet. Characters in that room may not move, make ranged attacks or cast spells until the start of Zargon's next turn. Those characters can only attack and defend against adjacent enemies. Discard after use. » | Rien d'équivalent : nos debuffs de zone (Nuée d'Effroi : `Paralysé`, `RESISTANCE_RUPTURE_PAR_MIND`) bloquent tout, celui-ci bloque juste mouvement/tir/sorts en laissant le corps-à-corps intact — nouvelle condition à écrire si porté (ni `Paralysé`, ni `Ralenti` ne couvrent cette nuance). |

#### Necromancer — Fanrax the Malicious (p. 41 : M6 A4 D6 B4 Mi7)

| Carte | Citation | → chez nous |
|---|---|---|
| **Summon Mummy** | « This spell summons a mummy. Place a mummy in any square adjacent to the Spellcaster. Discard after use. » | `Mot::TYPE_INVOCATION` existe déjà (« Invocation de morts-vivants », table à d6) — ce sort-ci est plus simple (1 momie fixe, adjacente) : portage immédiat, pas de nouveau mot-clé. |
| **Skulls of Doom** | « The Spellcaster hurls a magical skull at any opponent they can see. The skull explodes into a fireball. Roll 2 Attack dice. The target may defend normally. Discard after use. » | Motif identique à notre « Boule de Flammes » (degats_fixes/des_degats, defense_applicable, type_degat feu) — portage direct avec `des_degats: 2`. |
| **Raise the Dead** | « Cast this spell after a monster has been killed (no action required). The monster is replaced with a skeleton which can move and attack immediately. Discard after use. » | **Sort RÉACTIF, sans coût d'action** — rien de comparable : tous nos `SortDread::TYPE_*` sont lancés comme une action du tour du sorcier (`MoteurDread::tourSorcier()`). Un sort qui se déclenche HORS tour, sur la mort d'un monstre, est un nouveau point d'accroche (même famille que `MoteurReactions::proposer()`, mais côté MJ). |
| **Fear** | « This spell causes a target to become so fearful that they may only use 1 Attack die. The spell can be broken by the target on a future turn by rolling 1 red die for each of their Mind Points. They must roll a 6 to break the spell. Discard once the spell is broken. » | Rupture « 1 dé rouge par Mind, besoin d'un 6 » = **exactement** `Mot::RESISTANCE_RUPTURE_PAR_MIND` (`rupture_6_par_mind`), déjà lu par `MoteurDread`. Il manque seulement la condition « plafonné à 1 dé d'attaque » — proche de `Ralenti` (Ralentissement) sans en être une copie exacte (celle-ci touche le déplacement, pas l'attaque). |
| **Death Bolt** | « Hurl this spell at one target within the Spellcaster's line of site to cause them to instantly lose 1 Body point. Discard after use. » | Dégât fixe non résistable = même motif que « Morsure de Froid » (`degats_fixes: 1`, `defense_applicable: false`), mais à distance au lieu d'au contact — portage direct avec `zone` changée. |
| **Call Skeletons** | « This spell summons up to 2 skeletons that appear immediately anywhere within sight of the Spellcaster. Discard after use. » | Même famille que « Invocation de morts-vivants » (`TYPE_INVOCATION`), plus simple (nombre fixe, pas de table à d6) — portage direct. |

#### High Mage — Zanrath, High Mage of Sarako (p. 41 : M5 A5 D5 B4 Mi8)

| Carte | Citation | → chez nous |
|---|---|---|
| **Wall of Flame** | « Cast this spell to create a magical wall of flame which covers two 2 squares not occupied by figures within the Spellcaster's line of sight. The wall has 1 Body Point and rolls 6 Defend dice. Discard when the wall is destroyed. » | Voir §2 — même mécanique que Wall of Ice, deuxième des trois murs. |
| **Strands of Binding** | « Cast this spell to fire magical tendrils from the Spellcaster's fingertips. They entangle one target who may not move or attack until the tendrils are destroyed. Tendrils have 1 Body point and roll 4 Defend dice. The target may defend against other attacks. Discard when the tendrils are destroyed. » | Famille « entité possédant ses propres PV/défense qu'un héros doit détruire pour se libérer », le MÊME patron que les Murs et les Coffres du Dread (chantier 1, mobilier à PV/défense) — mais appliqué à une IMMOBILISATION de figure plutôt qu'à un obstacle de case. Different de « Étreinte des Ronces » (résistance par jet de combat, pas de PV propres à détruire) : un sous-cas neuf du même patron générique. |
| **Rust** | « This spell causes any one piece of metal equipment to decay to the point of uselessness. It is not effective against artifacts. Remove the affected equipment from play. Discard after use. » | Notre « Rouille » (`SortDreadSeeder`) détruit TOUS les emplacements métalliques (`arme_principale`, `arme_secondaire`, `casque`) d'un coup, `epargne_artefacts: true` déjà partagé — cette carte-ci ne vise qu'UNE seule pièce choisie. Même `detruit.metallique`/`epargne_artefacts`, portée différente : à cadrer comme une variante « ciblée » plutôt qu'un nouveau mot-clé. |
| **Possess** | « The Spellcaster may cast this spell on one figure to affect it with a frightening terror. Zargon will move this figure on its next turn. The affected figure may not attack or cast spells. Discard after use. » | **Rien d'équivalent** : aucun mot-clé ne donne au MJ le contrôle du DÉPLACEMENT d'un héros sur son propre tour suivant. Capacité entièrement nouvelle — poserait une question de principe (« Le serveur publie la décision » : le déplacement imposé devrait être calculé et publié, jamais laissé au hasard côté client). |
| **Escape** | « The Spellcaster may disappear and instantly teleport to a secret destination known only to Zargon. This space is marked on the quest map. Discard after use. » | **Mot pour mot** notre « Fuite » (`SortDreadSeeder`, `teleportation: MoteurDread::FUITE_CASE_ELOIGNEE`) — déjà noté dans la section 2025 que nos donjons procéduraux n'ont pas de case marquée, d'où le choix de la case libre la plus éloignée. Rien à changer. |
| **Unlearn** | « The Spellcaster may pick one spell caster and force them to discard 1 spell card at random. The spell is removed from play for the duration of the quest. Discard after use. » | **Doublon exact de nom** avec la carte héros *Unlearn* du répertoire *Spells of Protection* (§3) — même effet, simple changement de perspective de phrase (« The Spellcaster may… » vs « You may… »). Rien chez nous ne force un lanceur à défausser un sort connu : nouveau mot-clé (anti-magie), à écrire une fois pour les deux decks. |

#### Orc Warcaster — Nyashak, Orc Warcaster of the Northern Clan (p. 41 : M7 A5 D5 B5 Mi7)

| Carte | Citation | → chez nous |
|---|---|---|
| **Call Orcs** | « The Spellcaster places up to 2 Orcs on spaces they can see. The Orcs may be taken from those not in play or from anywhere on the board. They may move and attack immediately unless they have already done so this turn. Discard after use. » | Même famille que « Invocation d'orques » (`TYPE_INVOCATION`, table à d6) mais SANS tirage aléatoire (nombre fixe, 2) et avec la possibilité de RÉUTILISER un orque déjà sur le plateau — nuance absente de nos invocations actuelles (qui créent toujours de nouvelles instances). |
| **Call Goblins** | « The Spellcaster places up to 4 Goblins on spaces they can see. The Goblins may be taken from those not in play or from anywhere on the board. They may move and attack immediately unless they have already done so this turn. Discard after use. » | Même remarque que Call Orcs, nombre 4 au lieu de 2. |
| **Spirit of Vengeance** | « This spell allows the Spellcaster to send an invisible spirit to attack any one character on the board. The spirit attacks once with 4 Attack dice. The character attacked defends as normal. The spirit then vanishes. Discard after use. » | Attaque sans ligne de vue déclarée (« any one character on the board ») : aucun de nos sorts à dégâts n'ignore la ligne de vue du lanceur — nuance neuve si portée telle quelle. Mécaniquement sinon : simple jet d'attaque à dégâts (`des_degats: 4`, `defense_applicable: true`), motif déjà connu. |
| **Shield of Protection** | « This spell allows the spellcaster and all Orcs in the same room to roll 1 extra combat die in defense until the start of spellcaster's next turn. May only be cast in a room. Discard after use. » | Buff de zone « salle entière » existe pour les héros (Conte inspirant, Chant de guérison — `zone: salle_du_lanceur`/`heros_en_vue`) mais aucun équivalent côté monstres aujourd'hui (nos buffs monstres sont individuels : `bonus_lanceurs_adjacents`). Nouveau : buff de défense de ZONE pour une faction de monstres. |
| **Sharpen Blades** | « This spell allows all Orcs in the same room as the Spellcaster to roll an extra Attack die for this turn only. May only be cast in a room. Discard after use. » | Même nouveauté que Shield of Protection, côté attaque. |
| **Orc Berserker** | « The Sorcerer chooses an Orc they can see to be filled with immense strength and vigor. The Orc moves and attacks twice on this turn only. This spell may not be cast on an Orc that has already moved or attacked. Discard after use. » | Rien chez nous ne donne un second tour complet (déplacement + attaque) à un AUTRE monstre — notre « Arrêt du temps » (sort de héros) donne un tour supplémentaire à SOI, jamais à une tierce figure. Nouvelle mécanique si portée. |

#### Artificer — the Keeper (p. 41 : M6 A4+2\* D3 B5 Mi8)

| Carte | Citation | → chez nous |
|---|---|---|
| **Beseech Dread Powers!** | « The spellcaster may immediately cast this spell in response to being reduced to 0 body points. Roll 1 red die. 1-2 Ignored. 3-5 Place a Gargoyle in the spellcaster's space. 6 The air chills. Each hero in the same room or corridor loses 2 body points. Discard after use. » | Même FAMILLE que `increvable_une_fois` (réaction à 0 PV, un seul point de passage `MoteurDegats::infligerAMonstre()`) mais un résultat complètement différent (table à d6, pas une survie) — bon candidat pour étendre ce même point de passage plutôt qu'en ouvrir un second. |
| **Scrolls of Morcar** | « The Spellcaster keeps this spell face up and places 3 shadow tokens on it. When the Spellcaster takes any amount of damage, remove 1 shadow token instead. The spell is broken after the last shadow token is removed. Discard after use. » | Bouclier à « charges » qui absorbe N coups quel que soit leur montant — proche de `immunite_degat` + `charges` (Anneau de Feu) mais celui-ci bloque TOUT dégât (pas une seule nature) pendant 3 coups : nuance à nommer (`absorbe_coups` plutôt que `immunite_degat`). |
| **Hammer of Ruin** | « The Spellcaster keeps this spell face up and may roll 2 extra combat dice when attacking. If an attack from the spellcaster does not result in the enemy losing at least 1 Body Point, the spell is broken. Discard after use. » | **C'est ce sort qui produit la ligne « Attack 4+2\* » du tableau des monstres** (§Wizards of Morcar 2025, §2) — confirmé mot pour mot. Buff d'attaque persistant à condition de résultat (se brise sur un coup manqué) : rien d'identique chez nous (nos buffs persistants expirent sur durée/déclencheur, jamais sur un ÉCHEC du porteur lui-même). |
| **Leach Life** | « The Spellcaster rolls 1 red die for each other figure in the same room or corridor. If the roll is equal to or greater than a target's Mind Points, they lose 1 Body Point and the Spellcaster recovers 1 Body Point. Discard after use. » | Vol de vie (inflige + soigne le lanceur du même montant) : rien d'équivalent dans nos sorts Dread (nos soins Dread, « Apaisement »/« Restauration de l'Effroi », ne sont jamais couplés à un dégât infligé). Nouveau motif. |
| **Conjure Golem** | « This spell conjures up a fearsome creature of stone that's dead set on protecting its creator. The Spellcaster places a Golem on a space they can see. Discard after use. » | Même famille que les invocations existantes (`TYPE_INVOCATION`), Golem à seeder (§Wizards of Morcar 2025, §2, M5 A4 D5 B3 Mi0, aucune ligne au catalogue aujourd'hui). |
| **Summon Dreadshifter** | « Once a normal object, now a creature of nightmares. The Spellcaster places a Dreadshifter on a space they can see. Discard after use. » | Même famille, Dreadshifter à seeder (capacité `embuscade`/Ambush, §5 ci-dessous, non encore portée). |

### 2. Murs magiques — qui les lance, et une incohérence apparente du livret

Détail déjà corrigé dans la section 2025 ci-dessus (⚠ Corrigé le
2026-10-05). Synthèse : **Wall of Ice** est un sort du **Storm Master**
(Boroush), **Wall of Flame** un sort du **High Mage** (Zanrath) — les deux
dans les 30 cartes transcrites §1. **Wall of Stone**, en revanche, n'est
PAS un sort de Sorcier du Dread : c'est une carte du répertoire de HÉROS
*Spells of Protection* (§3 ci-dessous), phrasée à la première personne
(« *You create…* ») contre « *The Spellcaster creates…* » pour les deux
murs du Dread. Les trois partagent la même règle générique sur le carton
*Magic Reference Chart* (§9), ce qui explique pourquoi le livret de quêtes
les cite ensemble au même paragraphe — mais seuls DEUX des trois profitent
réellement à un camp « adverse » ; le troisième est un outil défensif de
héros. Chez nous : mobilier à une case (`pv_body: 1`, `defense_dice: 6`),
lecteur déjà construit (chantier 1), aucun des trois n'est encore seedé.

### 3. Trois répertoires de sorts de héros — TRANSCRITS

Résout le ⚠ « Aucun texte de carte » de la section 2025, §3 : les trois
répertoires ont chacun exactement **3 sorts**.

#### Spells of Protection

| Carte | Citation | → chez nous |
|---|---|---|
| **Wall of Stone** | « You create a magical wall of stone which covers 2 squares not occupied by figures. The wall has 1 Body Point and 6 Defend dice. Discard when the wall is destroyed. » | Voir §2 — troisième mur, côté héros. Mobilier à PV/défense, lecteur déjà construit. |
| **Invisibility** | « Casting this spell makes you invisible until the start of your next turn. While invisible, you may not attack. You cannot be attacked and are immune to all spells. Discard after use. » | **Rien d'équivalent** : aucun mot-clé `invisible`/`invisibilite` n'existe dans `MotsClesSort`/`MotsClesEquipement` (le seul hit sur « invisible » dans le code est un commentaire sans rapport dans `Combat.php`). Notre plus proche parent est « Évanescence » (condition `Évanescent`, effet non chiffré ailleurs dans ce document) ou « Image double » (`image_miroir`) — aucun des deux ne couvre « ne peut être ni ciblé par une attaque, ni par un sort ». Nouvelle condition à écrire. |
| **Unlearn** | « You may pick one spell caster and force them to discard one spell card at random. The spell is removed from play for the duration of the Quest. Discard after use. » | Doublon de nom avec la carte Dread du High Mage (§1) — voir la note là-bas. |

#### Spells of Detection

| Carte | Citation | → chez nous |
|---|---|---|
| **Treasure Horde** | « You may cast this spell instead of drawing a treasure card to draw 3 treasure cards. You may shuffle any of the drawn cards back into the treasure deck and keep the rest. Discard after use. » | Rien d'équivalent sur `DeckFouille` : piocher plusieurs cartes de trésor et choisir lesquelles garder n'a pas de lecteur aujourd'hui. Nouveau. |
| **Future Sight** | « This spell may be cast at any time and does not take an action. You may re-roll all dice for any one attack, defense or movement roll. Discard after use. » | Proche de `relance_des_attaque` (Potion de bataille) mais BIEN plus large : celui-ci relance TOUS les dés (pas seulement les ratés), sur attaque, défense OU déplacement, et ne coûte aucune action. Nouvelle variante à nommer séparément (`relance_totale` ?), pas une simple extension de l'existant. |
| **Clairvoyance** | « You may ask Zargon to lay out the contents of one room anywhere on the board. If the room is empty, you may not try again. Discard after use. » | Rien d'équivalent : révéler le contenu d'une salle non explorée, à distance, sans s'y rendre. Nouveau — à cadrer avec « le serveur publie la décision » (quel contenu montrer sans révéler toute la carte au client). |

#### Spells of Darkness

| Carte | Citation | → chez nous |
|---|---|---|
| **Cloak of Shadows** | « This spell summons a patch of darkness. Place the Cloak of Shadows tile on the gameboard. Heroes and monsters on the tile may not attack or be attacked. The darkness blocks line of sight into and through it. Place 3 shadow tokens on this card. At the start of the spellcaster's turn, remove a shadow token. The spell ends after the last shadow token is removed. Discard this card. » | Résout le ⚠ de la section 2025, §4 (« Tuile Voile d'ombre… non trouvé ») : texte intégral désormais connu. `bloque_vue` existe déjà comme mot-clé de terrain/mobilier (`FabriqueGrille`) mais jamais pour une zone TEMPORAIRE posée par un sort de héros avec un compteur de jetons qui décroît — motif proche de « Mur de Glace » (compteur de crânes qui décroît, couche dédiée `carte.grille`) à réutiliser plutôt qu'à dupliquer. |
| **Chains of Darkness** | « You may cast this spell on one monster you can see. That monster may not move or attack until the start of your next turn. They may defend or cast spells. Discard after use. » | Ni `Paralysé`, ni `Ralenti`, ni `Étourdi` ne couvrent exactement « ne peut ni bouger ni attaquer, mais peut se défendre et lancer des sorts » — nuance de condition neuve. |
| **Arrows of the Night** | « This spell fires magical bolts at any monster you can see. Roll 2 Attack dice. The target defends with as many dice as they have Mind Points. Monsters with 0 Mind points may not roll defense. Discard after use. » | Défense = nombre de dés d'Esprit, et monstres à 0 Esprit ne défendent pas du tout : motif proche de notre `SortMental` (monstres à 0 Mind immunisés aux sorts MENTAUX) mais appliqué ici à un sort de DÉGÂTS, avec une défense variable inédite (`des_resistance` égal au Mind de la cible, pas une valeur fixe). Nouveau. |

### 4. Mercenaires — les 4 cartes CONFIRMENT le catalogue

Relues individuellement, les 4 cartes de mercenaires de cette boîte
égalent, valeur par valeur, `database/seeders/MercenaireSeeder.php` — rien
à porter, la section 2025 §1 du plan (`docs/plan-wizards-of-morcar.md`)
avait raison sur la seule foi du texte du livret, la carte confirme.

| Carte | Citation | → chez nous |
|---|---|---|
| **Swordsman** | Stats M5 A4 D5 B2 Mi2, prix 100 po, aucune capacité spéciale sur la carte. | = **Estafier** (`MercenaireSeeder`, M5 A4 D5 B2 Mi2, 100 po) — exact. |
| **Scout** | Stats M9 A2 D3 B2 Mi2, prix 50 po. « *Dwarf-like ability to remove traps.* » | = **Éclaireur** (M9 A2 D3 B2 Mi2, 50 po, `permet_desamorcage`) — exact. |
| **Halberdier** | Stats M6 A3 D3 B2 Mi2, prix 75 po. « *Can make diagonal attacks.* » | = **Fauchard** (M6 A3 D3 B2 Mi2, 75 po) — exact. |
| **Crossbowman** | Stats M6 A3 D3 B2 Mi2, prix 75 po. « *Wields a crossbow.* » | = **Arbalétrier** (M6 A3 D3 B2 Mi2, 75 po, à distance) — exact. |

### 5. Ennemis hors sorciers — Sir Ragnar, Dreadshifter, Golem, Minotaure

| Carte | Citation | → chez nous |
|---|---|---|
| **Sir Ragnar** | Stats M7 A3 D5 B6 Mi2, aucun texte de capacité sur la carte (bloc de stats pur, comme une fiche de héros). | ⚠ **Collision de nom** : `MonstreSeeder` porte DÉJÀ un « Sir Ragnar » — celui de *Rise of the Dread Moon* (`boite: 'dread_moon'`, tier boss, M5 A5 D5 B4 Mi4, `increvable_une_fois`, cout 14), un PERSONNAGE DIFFÉRENT (boss monstre, pas un allié jouable). Pas de conflit technique (`Monstre.nom_base` et `Mercenaire.nom` sont deux tables distinctes) mais une collision NARRATIVE réelle entre deux boîtes Hasbro. Celui de Morcar suit exactement le gabarit « captif » déjà construit (`Mercenaire::captif`, chantier 3b, cf. Gothar) : stats désormais connues, portable, à nommer distinctement (ex. « Sir Ragnar (Wizards of Morcar) ») pour éviter toute confusion de narration. |
| **Dreadshifter** | Stats M5 A4 D3 B2 Mi4 (= ligne du tableau officiel, exact). « *Dreadshifters in this expansion pack appear to be either a chest or a door. Place this monster onto the board as the object it appears to be. The first time a hero moves into the 8 squares surrounding this object, replace it with the corresponding Ambush monster miniature. It may move and attack immediately.* » | Déclencheur de la carte = SOUS-ENSEMBLE du texte du livret (§Wizards of Morcar 2025, §2, capacité *Ambush*, 4 déclencheurs) : la carte ne documente QUE le déclencheur « un héros entre dans les 8 cases alentour », le livret ajoute fouille de pièges/trésor et tour du MJ. Pas une contradiction, deux niveaux de détail. Rien de comparable au catalogue (`embuscade` reste à écrire, §Wizards of Morcar 2025 §5/lot C). |
| **Golem** | Stats M5 A4 D5 B3 Mi0 (= ligne du tableau officiel, exact), aucune capacité sur la carte. | Pas de divergence avec la note de quête p. 19 (« un bouclier noir bloque tout ») déjà documentée comme règle PONCTUELLE à une quête, pas une capacité générale — confirmé, le Golem générique n'a rien de spécial. |
| **Minotaur** | Stats M7 A4 D5 B6 Mi4 (= ligne du tableau officiel, exact). « *In addition to their own turn, a minotaur may immediately roll 2 Attack dice against a hero who ends their turn in one of the 10 spaces surrounding it. A minotaur may not gore if they are incapacitated.* » (capacité nommée **Gore**) | **Rien d'équivalent** : aucune réaction « hors tour, déclenchée par la fin du tour adjacent D'UN HÉROS » n'existe au catalogue (nos réactions existantes se déclenchent sur une attaque ou un dégât subi, jamais sur le simple fait de terminer son tour à portée). Nouvelle mécanique de monstre si porté. |

### 6. Artefacts — 2 des 3 cartes nommées par le texte de quête

| Carte | Citation | → chez nous |
|---|---|---|
| **Urdyn the Unmaker** | Arme, 2 dés d'attaque de combat normal, 4 dés contre un Dreadshifter ou un Golem spécifiquement. | ⚠ Rien d'équivalent : aucun artefact actuel ne porte un bonus CONDITIONNÉ au TYPE de monstre adverse (nos bonus conditionnels existent `au_contact`, jamais `type_monstre_cible`). Nouveau mot-clé si porté — et nécessite d'abord que Golem/Dreadshifter existent au catalogue. |
| **Drakehide Cuirass** | Armure non métallique, +1 dé de défense, fixe le déplacement à 8 cases (au lieu de lancer les dés), cumulable avec casque/bouclier, explicitement interdite au Magicien. | Le déplacement FIXE (au lieu d'un jet) n'a pas d'équivalent — nos classes ont toutes `deplacement_base + 1d6`, jamais un artefact qui REMPLACE le jet par une valeur fixe. Nouveau. |
| **Elixir of Life** | ⚠ Toujours **non trouvée** — absente des 2 cartes d'artefact rendues. Seul le texte de quête (p. 18 : « *if this is already owned by a hero, find 2 Potions of Healing instead* ») en parle. | Sans changement depuis la section 2025 — René n'a pas cette carte en photo. |

### 7. Faveurs de Hopekins Rest — les 5 compétences, TRANSCRITES

Résout le ⚠ de la section 2025, §5 (noms seulement). Les 5 cartes sont des
**compétences de héros** au format talent (une icône, un texte d'effet
permanent une fois acquis).

| Carte | Citation | → chez nous |
|---|---|---|
| **Peacekeeper** | « Keep track of the number of monsters you reduce to 0 Body Points for each quest. For your service, the Realm rewards you with 25 gold coins per monster defeated the end of that quest. » | Rien d'équivalent : aucune récompense en or indexée sur le nombre de monstres vaincus PAR UN HÉROS SPÉCIFIQUE pendant la quête — nos récompenses d'or sont actuellement globales au groupe. Nouveau. |
| **Hold the Line** | « Each time a monster on Zargon's turn moves away from the 8 spaces immediately surrounding you, roll a combat die. If you roll a skull, inflict 1 Body Point of damage on the retreating monster. » | Famille des réactions hors tour (`MoteurReactions`) mais déclenchée par le DÉPART d'un monstre d'une case adjacente plutôt qu'une attaque reçue — nouveau déclencheur, aucun équivalent « attaque d'opportunité sur repli » au catalogue. |
| **Deadeye** | « Monster and heroes do not block your line of sight when attacking or casting spells. » | Lève le blocage de ligne de vue par les FIGURES (héros/monstres, pas les murs/meubles) pour attaquer ou lancer un sort — nuance absente de `Grille::ligneDeVue()` aujourd'hui (nos monstres/héros bloquent la vue comme tout obstacle ; seul `Voile de Brume`/`franchit_figures` lève un blocage de MOUVEMENT, pas de VISÉE). Nouveau. |
| **Weapon Expert** | « Select a type of weapon (e.g., battleaxe, longsword, or crossbow) when you acquire this Boon. When attacking with a weapon of that type, roll 1 additional Attack dice. » | Même famille que les tags de maîtrise déjà portés par certaines classes (ex. `arme_warlock`, `arme_elfe`) mais choisi PAR LE JOUEUR à l'acquisition plutôt que figé par classe — nouveau mécanisme de sélection, portable sur le même seam de bonus d'attaque. |
| **Healing Hands** | « If a hero adjacent to you is reduced to 0 Body points, you may allow them to use one of your available healing potions instead of their own. » | Partage de ressource entre héros adjacents au moment critique (0 PV) — proche en esprit de « Passing Items » (don d'objet entre héros adjacents, déjà couvert par `SeanceEchange`) mais déclenché AUTOMATIQUEMENT à 0 PV plutôt que par une action volontaire. Nuance à traiter. |

### 8. Trésors — les 8 cartes + « Nothing! », TRANSCRITES

9 images pour 8 types de cartes (*Magical Trap* existe en 2 exemplaires
dans le paquet). Résout le ⚠ de la section 2025, §3.

| Carte | Citation | → chez nous |
|---|---|---|
| **Magical Trap** (×2 exemplaires) | « As you are searching the room, you set off a Fireburst trap. » (renvoie au piège magique *Fireburst* du carton, §9) | Résultat de fouille qui déclenche un piège SANS qu'aucun piège n'ait été physiquement posé — notre `DeckFouille` n'a pas d'équivalent « mauvaise pioche = piège ». Dépend du piège *Fireburst* (§9, non porté). |
| **Poison** | Jet de 1 dé de combat ; sur un crâne, perte de 1 PV de Body, sinon rien. | Proche du motif `PiegeSeeder` « Aiguille empoisonnée »/« Fiole de poison » (résistance par jet simple) — portable sur le même seam côté trésor plutôt que piège. |
| **Potion of Alchemy** | Transforme l'équipement du porteur en or : défausse une carte d'équipement, gagne 100 po. | Rien d'équivalent : aucune conversion objet → or au catalogue actuel. Nouveau. |
| **Potion of Charm** | Entre deux quêtes, engager jusqu'à 3 mercenaires à 25 po de moins chacun. | Remise ponctuelle sur le recrutement — nouveau, à cadrer avec le système d'entretien des mercenaires (§Wizards of Morcar 2025, §5, lot D). |
| **Potion of Magical Aptitude** | Lancer 2 sorts connus au lieu d'un pendant ce tour. | Confirme le texte déjà cité §Wizards of Morcar 2025 §3 — rien au vocabulaire de sort ne permet un second lancer dans le même tour aujourd'hui (lot F du plan). |
| **Potion of Magic Resistance** | Ignore les effets du PROCHAIN sort à dégâts lancé sur le buveur. | Aucun mot-clé « annule le prochain sort subi » au catalogue des conditions/effets — nouveau (lot F). |
| **Potion of Fire Resistance** | Complètement épargné par la prochaine attaque de feu magique, sort ou piège *Fireburst*. | `immunite_degat: 'feu'` existe déjà (Anneau de Feu) — portage immédiat avec `charges: 1`, consommé à l'usage comme toute potion. |
| **Nothing!** | « Despite a thorough search, you find nothing. » | Déjà couvert par notre deck de fouille (résultat « rien trouvé » existant). |

### 9. Carton *Magic Reference Chart*

Identique à la p. 10 du livret. Regroupe les règles génériques partagées
par plusieurs cartes plutôt que de les répéter : « *Wall of Ice. Wall of
Flame. Wall of Stone.* » (1 PV, 6 dés de défense, pose sur 2 cases libres —
règle commune aux trois murs du §2, quel que soit leur lanceur), le piège
*Fireburst* (jeton posé, explose au tour suivant du MJ, 3 dés d'attaque de
feu sur toute la salle), le piège *Hurricane Trap* (recul forcé de 8 cases
en couloir), le piège *Teleport Trap* (téléportation case A → case B,
désoriente). Rien ici ne contredit la section 2025, §5 (lot B, pièges
magiques) — le carton confirme simplement les trois pièges déjà cités.

---

## Tableau de synthèse

| Boîte | Année | Héros jouables | Monstres neufs (types) | Mécaniques neuves majeures |
|---|---|---|---|---|
| Kellar's Keep | 2021 | 0 | 1 (Abomination†) | Portes secrètes contrôlées par le MJ, trappes-téléporteurs, rocher roulant, monstre métamorphe |
| Return of the Witch Lord | 2021/22 | 0 | 0 (variantes nommées seulement) | Salle rotative, Death Mist mobile, immunités sélectives, split-party scripté |
| Against the Ogre Horde | 2023/24 | 0‡ | 4 (Ogre Warrior/Champion/Commander/Lord) | Mode Tournoi complet, mercenaires, alliés animaux, choc à 0 Mind, monstres multi-phases |
| The Frozen Horror | 2021/22 | 0 (Barbare alternatif = doublon) | 4 (Frozen Horror, Ice Gremlin, Polar Warbear, Yeti) | Terrain de glace (tunnels/glissades/rivière), mercenaires, quête double liée |
| The Mage of the Mirror | 2022/23 | 0 | 4 (Elven Archer/Warrior, Ogre, Giant Wolf) | Malédiction de loup-garou, spellbook elfique alternatif, quête double par miroir |
| Rogue Heir of Elethorn | 2022 | 1 (Rogue)⚠ | 0 | — (pack de héros seul) |
| Rise of the Dread Moon | 2022/23 | 0 | 7 (Dread Cultist, Elven Archer/Warrior, Assassin, Magus Guard, Specter, Dread Wraith) | Monstres éthérés, déguisements, jetons de réputation, planques, artisanat de potions |
| Path of the Wandering Monk | 2023 | 1 (Moine)⚠ | 0 | — (pack de héros seul) |
| Prophecy of Telor | 2023 | 0 | 0 (variantes nommées seulement) | Artefact maudit à jet automatique, KO non létal ciblé, transformation forcée réversible |
| Spirit Queen's Torment | 2023 | 1 (Barde)⚠ | 0 (variantes nommées seulement) | 4 tours au choix libre, dégâts convertis en Mind, résolution alternative de boss |
| Jungles of Delthrak | 2024 | 2 (Explorateur confirmé, Berserker probable)⚠ | 9 (Blightcrawler, Blightweaver, Giant Ape, Goblin/Skeleton Archer, Raptor, Serpent, Skullblight, Spawnling) | 3 modes de difficulté/mort, campagne ramifiée, terrain destructible, jetons de dégât différé |
| The Crypt of Perpetual Darkness | 2024/25 | 0 | 0 (variantes nommées seulement) | Piège d'acide permanent, monstre-piège auto-déclenché, obscurité magique pénalisante |
| First Light | 2024 | 0 | 0 (reprise intégrale du bestiaire de base) + Qwindrak the Warlock (Move 6 · Att 3 · Déf 6 · Body 3 · Mind 6) ; Dragon (Move 10 · Att 5 · Déf 5 · Body 7 · Mind 6, Draconic Flight, Ball of Flame à volonté) | Livret de règles : aucune. Livret de quêtes (scan p. 6-7, §6) : Oracle (bénédiction/malédiction), cor des Hearthkin, Healing Hearth, Sly Storage, sorts Dread 1×/quête par monstre, entrée/sortie fléchées |
| Wizards of Morcar | 2025 | 0 (réimpression du Magicien) ⚠ Sir Ragnar, allié nommé jouable 2 quêtes, stats désormais connues (carte, 2026-10-05) | 8 (Storm Master, Orc Warcaster, Necromancer, High Mage, Artificer, Dreadshifters, Golems, Minotaur) dont 5 Sorciers du Dread nommés, 30 sorts transcrits | Murs magiques destructibles (1 PV, carte = arête de 2 cases ⚠ corrigé le 2026-10-05 : UNE case chez nous, décision René 2026-10-04), 3 pièges magiques non fouillables, statut de Gardien + mercenaires à entretien (4 cartes confirmées identiques au catalogue), faveurs de Hopekins Rest (5 compétences transcrites), coffres renforcés (fouille à l'adjacence), 3 répertoires de sorts de héros transcrits (Protection/Détection/Ténèbres) |

† Statistiques chiffrées de l'Abomination non trouvées dans Kellar's Keep
lui-même ; confirmées comme faisant partie du bestiaire standard 2021 par
le contenu de boîte de *First Light*, chiffrées par la table de tournoi
d'*Against the Ogre Horde* (6/3/3/2/3). ⚠ **Sourcée sans réserve depuis le
2026-10-05** : carte « Abomination » elle-même (© 2021,
`reference/20_cartes_monstres.md`) — même chiffre, aucune capacité. Semée
dans `MonstreSeeder` (`tier => 'base'`, `boite => 'base'`).
‡ Contenu mentionne un miniature « Druid Hero » : **c'est bien un héros
jouable**, dont la carte de personnage est publiée avec cette boîte (voir
§HasLab Mythic Tier). Le livret de quêtes n'en dit rien, d'où le « 0 » de
cette colonne, qui ne compte que ce que le livret source.
⚠ = classe confirmée jouable par le texte mais **fiche chiffrée absente
du livret officiel** (ou aucun livret officiel trouvé).

### Héros jouables — recensement corrigé (2026-08-11)

La première passe n'en comptait que 4, faute d'avoir couvert le **Mythic
Tier** et le pack **Guardian Knights** (deux sections ajoutées depuis). Le
roster officiel complet est de **13 héros** — Barbare, Barde, Berserker,
Druide, Nain, Elfe, Explorateur, Chevalier, Moine, Rogue, Warlock,
Magicien, Sir Ragnar (retiré) —, dont **4 dans le jeu de base**, ce qui
laisse **9 candidats** et non 5.

Ils ne sont **pas au même stade**, et c'est la seule distinction qui
compte pour l'implémentation :

| Candidat | Capacités | Restrictions d'équipement | Fiche chiffrée |
|---|---|---|---|
| **Barde** | ✅ 3 sorts **transcrits** | ✅ bonus conditionnel (ni métal ni bouclier → +1 déf) | ✅ **A2 D2 B5 M4** |
| **Druide** | ✅ 3 sorts **transcrits** | ✅ pas d'armure métallique | ✅ **A1 D2 B6 M4** |
| **Warlock** | ✅ 3 sorts **transcrits** | ✅ règles d'armure du magicien | ✅ **A2 D2 B4 M5** |
| **Moine** | ✅ **8 techniques sur 8** | ⚠ énoncée par source tierce seulement | ✅ **A1\* D3 B6 M4** |
| **Rogue** | ✅ 3 capacités **transcrites** | ⚠ tierces | ✅ **A1 D2 B5 M4** |
| **Explorateur** | ✅ **3 capacités transcrites** | ⚠ rien | ✅ **A2 D2 B5 M5** |
| **Chevalier** | ✅ **3 capacités transcrites** | ✅ **bouclier de départ**, requis par 2 des 3 | ✅ **A2 D3 B7 M2** |
| **Berserker** | ✅ **3 capacités transcrites** | ⚠ rien | ✅ **A3 D2 B7 M2** |

**Les huit sont sourcées** depuis la numérisation de René (2026-08-11, dossier
Drive « Heroquest2021 » : 11 fichiers, cartes de personnage et de compétence).
Détail complet, capacité par capacité, dans `reference/01_personnages.md`
§4bis-4quater — c'est le document des personnages, sa place est là.

⚠ **Deux corrections que cette numérisation impose à ce document :**

1. Le **Berserker** y était « probable, non confirmé textuellement », inféré
   d'une liste de figurines. Sa carte existe : **A3 D2 B7 M2**, épée large, et
   trois compétences (*Enrage*, *Retaliation*, *Frenzy*). C'est une classe
   jouable, pas une hypothèse.
2. Le **Chevalier** était donné « 3 pouvoirs sans aucun texte », ce qui en
   interdisait le portage. Les trois sont lus : *Stalwart*, *Shield Block*,
   *Knight's Challenge*. La restriction d'armure supposée (« cotte de
   mailles ») était fausse — la carte donne un **bouclier de départ**, et deux
   compétences sur trois portent la mention « **Requires shield** ».

Reste ouvert : la **restriction d'équipement** du Rogue, du Moine, du Berserker
et de l'Explorateur, qu'aucune carte de personnage n'énonce (seules des sources
tierces l'affirment pour les deux premiers).

**Les cinq fiches sont désormais connues** (photos des cartes,
2026-08-11), et c'est ce qui débloque le portage — quatre lues sur image, la
cinquième (Rogue) rapportée par deux sources concordantes et signalée comme
telle. Le tableau ci-dessus a
remplacé une conclusion qui disait « aucune fiche chiffrée dans un PDF
Hasbro » : c'est toujours vrai des PDF, et c'est justement pourquoi la photo
était la seule voie — comme pour les prix d'équipement et le tableau des
8 monstres de base.

Reste à obtenir : les **restrictions d'équipement** (aucune carte de personnage
n'en porte, sauf le bouclier du Chevalier), et la photo du verso Eau du Moine
— *Twisting Torrent* n'est connu que par deux sources concordantes. `classes_heros` exige 8 valeurs par classe (`pv_body`,
`pv_mind`, `attr_body`, `attr_mind`, `des_attaque`, `des_defense`,
`deplacement_base`, `bonus_sac`) : les cartes en donnent 4 à 5, le reste
(`attr_*`, `bonus_sac`) suit nos propres conventions et doit être signalé
comme tel.

Ce qui était portable **sans aucune carte** — les capacités, qui sont des
*phrases* et non des chiffres — **l'a été le 2026-08-12** : les 17 capacités des
six classes qui en ont sont en jeu, techniques du Moine comprises
(`reference/01_personnages.md` §4ter). Le pari tenait : la plupart tombaient sur
des mécaniques déjà écrites (`tacticien` pour *Opportunistic Striker*, `agile`
pour *Combat Mobility*, `permet_desamorcage` pour le *Bandolier*), et les rares
qui manquaient — frappe balayée, rayon, dégât différé — ont été écrites une fois
puis partagées entre Berserker et Moine.

⚠ Restent ouvertes, et ce sont bien des **données** manquantes, pas du moteur :
les **restrictions d'équipement** du Rogue, du Moine, du Berserker et de
l'Explorateur, qu'aucune carte de personnage n'énonce.
