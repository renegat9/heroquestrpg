# Conception — Système de Personnages

> Document d'analyse. Les technologies seront décidées plus tard ; ici on raisonne uniquement sur les règles et les données. Les valeurs chiffrées sont des **propositions de départ à équilibrer en playtest**, pas des chiffres définitifs.

---

## 1. Philosophie de conception

Le personnage repose sur **deux statistiques seulement — Body et Mind** — pour rester simple et fidèle à HeroQuest. La profondeur ne vient pas d'une multiplication d'attributs, mais de **l'arbre de compétences propre à chaque héros**. Division du travail :

- **Body / Mind** → résolvent les jets bruts (réussir une action, résister, percevoir, convaincre).
- **Arbre de compétences** → porte l'identité et la spécialisation de chaque héros.

---

## 2. Point critique : attribut ≠ points de vie

C'est la décision structurante du système. Dans HeroQuest d'origine, « Body Points » et « Mind Points » sont des **jauges de vie**. Pour pouvoir *faire des jets* de Body et de Mind sans casser le système de vie, on **dédouble** chaque axe :

| Élément | Rôle | Évolue ? |
|---|---|---|
| **Attribut Body** | Nombre de dés roulés pour un jet physique | Monte avec les niveaux |
| **Attribut Mind** | Nombre de dés roulés pour un jet mental | Monte avec les niveaux |
| **Points de Body (PV physiques)** | Jauge de vie ; à 0 → le héros tombe | Baisse aux dégâts, se régénère |
| **Points de Mind (PV mentaux)** | Résistance à la peur, magie, corruption ; à 0 → état mental brisé | Baisse, se régénère |

À cela s'ajoutent les valeurs de combat HeroQuest classiques, **distinctes des attributs** :

| Valeur de combat | Rôle |
|---|---|
| **Dés d'Attaque** | Dés de combat roulés à l'attaque |
| **Dés de Défense** | Dés de combat roulés en défense |
| **Déplacement** | Valeur de base (selon le héros) **+ 1d6** par tour |

> En clair : un personnage a *attribut Body/Mind* (jets), *Points Body/Mind* (vie), *Attaque/Défense* (combat). Trois familles de chiffres qu'il ne faut pas confondre.

---

## 3. Système de jet de compétence

On réutilise les **dés de combat HeroQuest** (6 faces : 3 crânes, 2 boucliers blancs, 1 bouclier noir → un crâne sort 1 fois sur 2).

**Règle proposée :** le joueur lance un nombre de dés de combat égal à son attribut concerné (Body ou Mind). Chaque **crâne = 1 succès**. Le MJ fixe une **difficulté** = nombre de succès requis.

| Difficulté | Succès requis | Exemple |
|---|---|---|
| Facile | 1 | Forcer une porte branlante |
| Moyenne | 2 | Crocheter une serrure, convaincre un garde hésitant |
| Difficile | 3 | Désamorcer un piège complexe, déchiffrer une rune ancienne |
| Très difficile | 4+ | Exploit héroïque |

Probabilités indicatives (≈50 % de crâne par dé) : 3 dés → ~50 % d'atteindre 2 succès ; 4 dés → ~31 % d'atteindre 3 succès. À ajuster en playtest.

### Ce que couvre chaque attribut

- **Body** : force, agilité, endurance, discrétion physique, athlétisme, résistance physique.
- **Mind** : savoir, perception, volonté, persuasion / dialogue, résistance à la magie, intuition.

> C'est ainsi que les **choix de conversation** prennent vie : un échange social tendu se résout par un jet de Mind, modulé par des nœuds d'arbre (ex. *Intimidation* du Barbare).

---

## 4. Les quatre héros (profils de départ)

Valeurs de vie tirées du canon HeroQuest ; attributs de jet = proposition nouvelle.

> **La colonne « Attaque » est la valeur AVEC l'arme de départ**, pas une force innée.
> Comme au plateau, **l'attaque vient de l'arme équipée** (doc 03 §8) : à mains nues tout
> héros lance **1 dé**. Les 3/2/2/1 ci-dessous sont produits par l'équipement initial —
> Barbare épée large (3), Nain hachette (2, lançable) + trousse à outils, Elfe épée courte (2),
> Magicien dague (1). La **défense**, elle, vaut **2 pour tous** sans armure, et les pièces
> d'armure s'y **ajoutent** (casque +1, bouclier +1…).

| Héros | Body (PV) | Mind (PV) | Attr. Body | Attr. Mind | Attaque | Défense | Dépl. base | Identité |
|---|---|---|---|---|---|---|---|---|
| **Barbare** | 8 | 2 | 4 | 1 | 3 | 2 | 4 | Brute de combat |
| **Nain** | 7 | 3 | 3 | 2 | 2 | 2 | 3 | Robuste, technique (pièges) |
| **Elfe** | 6 | 4 | 2 | 3 | 2 | 2 | 5 | Polyvalent, magie légère |
| **Magicien** | 4 | 6 | 1 | 4 | 1 | 2 | 4 | Lanceur de sorts, fragile |

**L'Elfe est vérifié sur sa carte** (numérisation de René, 2026-08-11, © 2023
Hasbro) : *Attack 2 · Defend 2 · Body 6 · Mind 4 · Movement 2 Red Dice ·
Starting Weapon Shortsword · Starting Armor None*. Nos chiffres concordent, y
compris la lecture « attaque = avec l'arme de départ » du bandeau ci-dessus.
⚠ Les colonnes **Attr. Body / Attr. Mind** et **Dépl. base** restent **de
nous** : aucune carte ne les porte (le plateau lance 2 dés rouges sans base,
divergence actée doc 00).

### 4 bis. Les sept classes d'extension — TOUTES SOURCÉES (2026-08-11)

Cartes de personnage numérisées par René. C'est la même voie que les prix
d'équipement et le tableau des 8 monstres de base : composants cartonnés,
absents de tout PDF Hasbro, débloqués par la photo.

| Classe | Attaque | Défense | Body | Mind | Arme de départ | Armure | Provenance |
|---|---|---|---|---|---|---|---|
| **Barde** | 2 | 2 | 5 | 4 | Dague | — | Mythic Tier / Spirit Queen's Torment |
| **Druide** | 1 | 2 | 6 | 4 | Dague | Aucune | Mythic Tier / Against the Ogre Horde |
| **Warlock** | 2 | 2 | 4 | 5 | Baguette | Aucune | Mythic Tier / Prophecy of Telor |
| **Rogue** | 1 | 2 | 5 | 4 | Dague | — | Rogue Heir of Elethorn |
| **Moine** | 1\* | **3** | 6 | 4 | — (mains nues) | — | Path of the Wandering Monk |
| **Chevalier** | 2 | **3** | **7** | 2 | Épée courte | **Bouclier** | Commander of the Guardian Knights |
| **Berserker** | **3** | 2 | **7** | 2 | Épée large | — | Jungles of Delthrak |
| **Explorateur** | 2 | 2 | 5 | 5 | Hachette | — | Jungles of Delthrak |

Toutes à **2 dés rouges** de déplacement, comme les quatre de base.
\* Moine : « *When attacking unarmed, roll one additional Attack die* » — donc
**2 dés à mains nues**, et c'est là que tout son jeu se passe.

Trois observations qui pèsent sur l'équilibrage :

- Le **Chevalier** et le **Berserker** montent à **7 Body**, entre le Nain (7)
  et le Barbare (8) — mais le Chevalier ajoute **3 dés de défense** et un
  bouclier de départ, ce qu'aucun héros de base n'a. Le **Moine** aussi est à
  3, sans armure ni bouclier : ce sont les deux seules classes du jeu dont la
  défense de base dépasse 2.
- Le **Berserker** est la seule classe à **3 dés d'attaque** de base, et il
  paie en Mind (2, à égalité avec le Barbare et le Chevalier).
- L'**Explorateur** est le seul héros **équilibré 5/5** — et le seul, avec le
  Warlock, à avoir plus de Mind que de Body sans être magicien.

⚠ Le **Berserker était donné « probable, non confirmé »** par
`reference/18_extensions.md`, faute de carte : elle existe, il est jouable.

### 4 bis-2. Mouvement de base — la RACE, plus un trait de classe

⚠ **Entièrement de nous.** Les cartes officielles ne donnent que « 2 dés
rouges » sans socle : notre base + 1d6 est une divergence assumée (doc 00). Il
n'y a donc rien à sourcer ici — seulement une cohérence à tenir, et elle ne
l'était pas. L'**Explorateur, qui est un nain**, marchait à 5 quand le Nain
marche à 3 : plus vite que l'Elfe.

La **race est une colonne** (`classes_heros.race`, migration
`2026_08_13_000001`) et non plus un commentaire : le guide l'affiche sur chaque
fiche, et l'infobulle du déplacement dit d'où vient le chiffre (« 3 (Nain) +1 —
classe agile »). Sans elle, un Explorateur plus lent qu'un Rogue restait
inexplicable pour le joueur. Quatre valeurs : `humain` · `nain` · `elfe` ·
`halfling`.

Grille arbitrée par René le 2026-08-13 :

| Socle racial | | Trait de classe |
|---|---|---|
| **nain** 3 | | **+1** si la carte vend la classe comme AGILE |
| **halfling** 3 | | |
| **humain** 4 | | |
| **elfe** 5 | | |

| Classe | Race | Base | Agile | Total |
|---|---|---|---|---|
| Nain | nain | 3 | — | **3** |
| Warlock | halfling | 3 | — | **3** |
| Barbare · Magicien · Barde · Druide · Chevalier | humain | 4 | — | **4** |
| Explorateur | **nain** | 3 | +1 | **4** |
| Rogue · Moine · Berserker | humain | 4 | +1 | **5** |
| Elfe | elfe | 5 | — | **5** |

Deux invariants, tenus par un test : **aucun nain ne dépasse l'elfe**, **aucun
halfling ne dépasse un humain**. L'Explorateur reste un nain — simplement le
meilleur marcheur des siens.

### 4 ter. Capacités de classe — texte des cartes

**Les 17 capacités de carte sont PORTÉES et jouées** (2026-08-12) : Barde 1,
Rogue 3, Chevalier 3, Berserker 3, Explorateur 3, Moine 4 cartes recto-verso
(8 techniques). Druide et Warlock n'en ont pas — leurs cartes sont des **sorts**
(§répertoires de classe). Trois lectures de portage sont signalées à l'endroit
où elles se prennent : le plafond de la *Furie* (`pv_body - 1`), la seconde
frappe de l'*Ambidextrie* (résolue depuis que la main gauche existe : la dague
peut y être portée pour de bon), et *Parler à la Pierre* (réussite automatique, notre fouille cherchant
déjà pièges ET portes secrètes en une action).

**Chevalier** (© 2023) — les trois exigent le **bouclier** sauf la dernière :
- *Stalwart* : « Use this skill when your Body Points are reduced to 0 to
  instead reduce them to 1. Once per quest. **Requires shield.** »
- *Shield Block* : « Use this skill on an enemy's turn when **a hero next to
  you** takes damage to cancel that damage. Once per quest. **Requires
  shield.** »
- *Knight's Challenge* : « Use this skill when a Wandering Monster is revealed
  in the same room as you. You are now considered the treasure-searcher for the
  encounter. The Wandering Monster is placed next to you and immediately
  attacks you. Once per quest. »

**Berserker** (© 2024) — deux des trois exigent d'être **blessé** :
- *Enrage* : « As an action, you may lose up to 2 Body Points to immediately
  make an attack. Add additional Attack dice equal to the number of Body Points
  you lose. Once per quest. »
- *Retaliation* : « *Cannot be used unless you have 5 or fewer Body Points.*
  You may use this skill when you take damage from an adjacent monster.
  Immediately make an attack against that monster. Once per quest. »
- *Frenzy* : « *Cannot be used unless you have 3 or fewer Body Points.* As an
  action, a single sweeping attack against all monsters adjacent **and
  diagonal** to you. Once per quest. »

**Explorateur** (© 2024) — trois capacités tournées vers le **deck de trésor**
et les pièges :
- *Treasure Hunter* : « Whenever you draw a card from the treasure deck that
  rewards you with gold coins, you find an additional 25 gold coins. »
- *Danger Sense* : « Once per turn, when you draw a hazard card from the
  treasure deck, you may return that card to the bottom of the deck and draw a
  new card. »
- *Trapsmith* : « Once per turn, when you move onto a square adjacent to one or
  more traps, Zargon must alert you. Zargon does not place trap tiles on the
  board. The traps are still considered concealed and not triggered. »

**Rogue** (© 2022) et **Moine** (© 2024) : texte intégral dans
`reference/18_extensions.md`.

⚠ **Quatre de ces capacités sont des RÉACTIONS HORS TOUR** — *Shield Block*,
*Retaliation*, *Knight's Challenge*, et côté sorts *Dark Wings* / *Twisting
Torrent*. Toutes sont en jeu (`App\Partie\MoteurReactions`), y compris les deux
formes que le dispositif ne savait pas rendre au départ : proposer la réaction à
un AUTRE héros que la victime (*Shield Block*), et partir d'autre chose que d'un
coup encaissé (*Knight's Challenge*, déclenché par l'apparition d'un errant).
Détail des cinq actions dans `docs/contrat-api.md` §Réactions hors tour.

### 4 quater. Alliés mercenaires — les cinq officiels

Cartes numérisées (© 2023 Hasbro). ⚠ Nos trois mercenaires actuels — Archer
mercenaire, Hallebardier, Loup fidèle — sont **de nous** ; ceux-ci les
remplacent ou les complètent, au choix.

| Allié | Dépl. | Attaque | Défense | Body | Mind | Coût/quête | Capacité |
|---|---|---|---|---|---|---|---|
| **Scout** | 9 | 2 | 3 | 2 | 2 | 50 po | « has the Dwarf's ability to detect and disarm traps » |
| **Arbalist** | 6 | 3 | 3 | 2 | 2 | 75 po | arbalète ; **épée large au contact** |
| **Glaive** | 6 | 3 | 3 | 2 | 2 | 75 po | « can attack diagonally » |
| **Striker** | 5 | 4 | **5** | 2 | 2 | 100 po | — |
| **Ogre Mercenary** | 8 | 4 | 4 | **4** | 1 | 150 po | — |

Et **trois compagnons animaux** (fournis par René, 2026-08-12) :

| Animal | Dépl. | Attaque | Défense | Body | Mind | Capacité |
|---|---|---|---|---|---|---|
| **Loup** | 10 | 3 | 2 | **5** | 1 | attaque en diagonale |
| **Croc-sabre** | 10 | 2 | 3 | **5** | 1 | attaque en diagonale |
| **Raptor** | 8 | 2 | 2 | 3 | 3 | se déplace **avant et après** son attaque |

⚠ Les animaux sont bien plus **endurants** que les mercenaires humains — 5 PV
de Body contre 2 — et le paient en Mind (1), ce qui les rend fragiles aux sorts
mentaux. La règle « un seul compagnon animal par groupe » s'applique à eux
seuls. Leur **prix est la seule valeur de nous** : les cartes n'en portent pas,
il est calé sur l'échelle officielle (Éclaireur 50 → Ogre 150).

Le « *move before and after an attack* » du Raptor est **exactement** le second
mouvement du `tacticien`, déjà écrit pour les monstres de *Jungles of Delthrak*
— dont le raptor du bestiaire, qui porte le même trait.

Le **déplacement est en CASES**, pas en dés : les cartes d'allié disent
« Movement Squares » là où les cartes de héros disent « 2 Red Dice ». C'est
donc un mouvement **fixe**, ce que notre modèle sait déjà exprimer.

Trois remarques utiles au portage : tous sont à **2 Body** sauf l'Ogre (4) —
ce sont des figures qui tombent vite ; le **Striker** a **5 dés de défense**,
plus que n'importe quel héros ou monstre du jeu ; et l'**Arbalist** est
exactement notre `portee: distance` + arme de contact distincte, motif que
l'Archer elfe utilise déjà.

---

## 5. Progression : niveaux

Progression **par jalons** : aucune accumulation de points, rien ne se gagne au monstre. On monte de niveau en franchissant des **jalons de campagne**.

**Déclencheurs de montée** (cadence visant ~5 à 8 niveaux par campagne) :
- chaque **sous-boss** vaincu (fin d'acte, doc Quêtes §4),
- le **boss final**,
- certains **objectifs de quête majeurs** marqués par le gabarit.

> La cadence exacte se cale sur l'arc selon la longueur (lien avec la cadence des sous-boss, doc Quêtes §10).

**Gains au passage de niveau :**
- **+1 point de compétence** à dépenser dans l'arbre (gain principal) ;
- à certains paliers, **+1 Point de Body ou de Mind** selon la classe ;
- les **attributs Body/Mind** ne montent **que** via des nœuds dédiés de l'arbre — choix significatif, pas d'automatisme. **Aucun plafond** (P3) ; le MJ IA relève la difficulté en conséquence.

---

## 6. Grille de talents (3 colonnes × 3 lignes)

**Décision (René, 2026-08-23) — l'arbre devient une GRILLE.** Chaque classe a
**trois colonnes**, qui sont **ses** catégories (libellés libres, propres à la
classe), et **trois lignes** par colonne. Acquérir la ligne *n* exige la ligne
*n−1* **de la même colonne** ; la première ligne n'exige rien.

Trois types de nœuds, inchangés : **passif** (bonus permanent), **actif**
(capacité déclenchable), **déblocage** (accès à équipement/sort).

Ce que la grille remplace : une liste plate de 4 à 7 nœuds par classe, sans
thème ni arbitrage — avec 5 à 8 niveaux par campagne (§5), on achetait à peu
près tout ce qui existait. **Neuf cases pour quatre à sept points**, c'est le
choix qui redevient un choix : on descend une colonne, on renonce à une autre.
La dette « le barbare seul à 4 nœuds » (2026-08-22) est soldée du même coup.

> **Les trois nœuds d'une colonne sont des effets DIFFÉRENTS du même domaine**,
> et non le même chiffre qui grossit (décision de René) : la chaîne est un ordre
> d'acquisition, pas une montée en puissance.

> ⚠ **Chaque `effet.mecanique` a son lecteur déclaré** dans
> `App\Engine\MotsClesTalent`, et trois verrous automatisés l'imposent :
> le registre vérifié dans les deux sens, le lecteur déclaré confronté au
> fichier qui doit nommer la clé, et un test **en jeu** par mécanique
> (`GrilleTalentsTest`, `TalentsEnJeuTest`). Une mécanique sans preuve en partie
> n'est pas semée.
>
> ⚠ **Les talents se lisent par MÉCANIQUE, jamais par nom de nœud** (2026-08-23).
> Le moteur cherchait auparavant `'Garde tenace'`, `'Coup puissant'`,
> `'Intimidation'`, `'Réserve arcanique'`, `'Concentration'`, `'Désamorçage'`,
> `'Tir précis'` : une quinzaine de nœuds des classes d'extension portaient la
> bonne mécanique sous un autre nom et **ne faisaient rien**.

**Deux textes par nœud, tous deux affichés** : la `description` est la phrase de
jeu, écrite à la main (le gain, sa condition, sa cadence) ; l'**avantage
chiffré est DÉRIVÉ de l'effet** et jamais saisi, ce qui interdit à un talent de
promettre autre chose que ce qu'il fait.

Les **capacités de carte** des classes d'extension (§4ter) restent **hors
grille** : gratuites avec la figurine, elles ne coûtent aucun point.

| Classe | Colonne 1 | Colonne 2 | Colonne 3 |
|---|---|---|---|
| **Barbare** | **Furie** — Coup puissant · Frénésie · Sang qui bout | **Carrure** — Carrure · Cuir tanné · Colosse | **Terreur** — Intimidation · Regard qui glace · Fauchaison |
| **Nain** | **Mine** — Œil du mineur · Désamorçage · Parler à la pierre | **Forge** — Forge · Solides épaules · Marchandage | **Ténacité** — Garde tenace · Sang robuste · Ancré |
| **Elfe** | **Arcane elfique** — Première magie · Second élément · Chant runique | **Œil** — Sens aiguisés · Tir précis · Flèche perçante | **Grâce** — Pas léger · Esquive dansante · Fuite gracieuse |
| **Magicien** | **Écoles** — Écoles · Réserve arcanique · Puissance brute | **Érudition** — Érudition · Concentration · Contresort | **Escrime de fortune** — Cuir d'apprenti · Escrime de fortune · Corps entraîné |
| **Barde** | **Refrain** — Refrain vaillant · Rappel · Second couplet | **Verbe** — Beau parleur · Ballade apaisante · Mot qui blesse | **Marche** — Marche entraînante · Pas de danse · Havresac de ménestrel |
| **Druide** | **Sève** — Vigueur sylvestre · Écorce · Sève tenace | **Communion** — Communion · Appel de la forêt · Verbe ancien | **Sentier** — Pas de la forêt · Ronces complices · Regard de la bête |
| **Warlock** | **Pacte** — Pacte · Volonté noire · Prix du pacte | **Malédiction** — Contresort · Œil vitreux · Marque du damné | **Corruption** — Cuir d'initié · Réserve damnée · Chair impie |
| **Rogue** | **Ombre** — Pas léger · Esquive · Fuite calculée | **Lame** — Coup bas · Lame vénéneuse · Coup de grâce | **Butin** — Doigts de fée · Poches profondes · Receleur |
| **Moine** | **Discipline** — Souffle discipliné · Corps aguerri · Peau de fer | **Vent** — Course du vent · Pas suspendu · Souffle retenu | **Méditation** — Méditation · Poing de fer · Esprit clair |
| **Chevalier** | **Serment** — Serment · Garde haute · Rempart | **Prestance** — Prestance · Bannière · Appel au ralliement | **Croisade** — Bras d'acier · Charge du destrier · Barda du croisé |
| **Berserker** | **Rage** — Rage froide · Coup sauvage · Soif de sang | **Carcasse** — Carcasse · Cuir de guerre · Cicatrices | **Charge** — Charge · Élan · Poigne brute |
| **Explorateur** | **Traque** — Cartographe · Crochetage · Lecture des lieux | **Butin** — Fouineur · Œil du prix · Bourse pleine | **Endurance** — Endurance · Longue marche · Barda |

> **Décision (René, 2026-08-22) — la symétrie barbare/nain reste supprimée.**
> Les deux costauds étaient MIROIRS : chacun avait sa spécialité gratuite et
> payait celle de l'autre d'un point de compétence. Les deux ont **tout dès le
> niveau 1**, et *Maîtrise lourde* / *Poigne de forgeron* sont **retirés**.

> Détail des sorts → document séparé (`Sorts`), référencé par les nœuds de déblocage.
>
> **Forge (Nain)** : amélioration **permanente et attachée à l'objet** — elle survit aux échanges, à la répartition de fin de campagne et passe dans le **roster**. Réalisée **au hub, entre les quêtes**, contre de l'**or**. **Un objet n'est améliorable qu'une fois** ; le Nain peut en forger **plusieurs** différents. Les **artefacts** (objets de rareté Unique) ne sont **pas** améliorables. Catalogue & prix : **doc Market §4**.

---

## 7. Équipement et inventaire

### Emplacements équipés
Chaque héros porte un équipement réparti en **cinq** emplacements fixes,
distincts du sac à dos (`App\Partie\Equipement::SLOTS`) :

| Emplacement | Ce qu'il reçoit |
|---|---|
| **Arme principale** | une arme — la main droite |
| **Arme secondaire** | le bouclier **ou** une seconde arme à une main *(depuis le 2026-08-12)* |
| **Casque** | le casque *(slot propre depuis le 2026-08-08)* |
| **Armure** | cotte, plates, brassards, cape |
| **Talisman** | les bijoux d'artefact *(depuis le 2026-08-09)* |

**Les deux mains** (décision de René, 2026-08-12) — quatre tenues, et quatre
seulement :

1. deux armes à **une** main ;
2. une arme à **deux** mains, seule ;
3. une arme à une main **+ un bouclier** ;
4. une arme à une main, seule (ou rien).

⚠ La seconde arme **n'apporte aucun dé** : elle apporte un **choix**. Le menu
d'action offre une attaque *par arme* — chacune avec ses propres cibles légales,
puisque portée, diagonales et jet sont des propriétés de l'arme. C'est ce qui
rend l'*Ambidextrie* du Rogue littérale (« one additional attack **with a
dagger** ») et ce qui permet de porter une arme de mêlée et une arme de jet sans
rien reprendre au sac en plein combat. `objets.emplacement` reste la valeur
naturelle d'une pièce ; pour une arme à une main, l'emplacement devient un
**paramètre** (`Equipement::slotsPossibles()`).

Casque, armure et bouclier **se cumulent**, comme au plateau — « [Borin's Armor]
may be combined with the helmet and/or shield » (livret de règles p. 7) : un
héros complètement équipé atteint **6 dés de défense** (2 de base + 1 + 2 + 1).
Le casque partageait auparavant le slot `armure` : on plafonnait à 5, et le
casque n'était qu'un achat de dépannage qu'on jetait dès la première vraie
armure.

`des_attaque` **remplace** la valeur du porteur (l'arme fait l'attaque, doc 03
§8) ; `des_defense` **s'ajoute**. La colonne `des_attaque` est celle de la
**main droite** : frapper de la gauche n'en échange que la part de l'arme, tout
le reste (Forge, nœuds passifs, progression) étant conservé. Les talismans ne touchent ni l'un ni l'autre :
ils relèvent les **PV maximum**, d'où leur emplacement à part.

Stats pièce par pièce : doc Market §Catalogue. Provenance carte par carte :
`reference/16_armurerie.md` §2.2 et §9.1.

### Maîtrises d'équipement (qui peut porter quoi)

Chaque pièce d'arme ou d'armure porte **un tag de maîtrise** ; chaque classe en
autorise un ensemble **de base**, et les nœuds de type `deblocage` en ouvrent
d'autres, au prix d'un point de compétence. Profil retenu : **canon HeroQuest** —
le magicien est le seul vraiment bridé, comme au plateau.

| | armes | armures |
|---|---|---|
| **Barbare** | légère, courante, distance, **deux mains**, arc long | légère, bouclier |
| **Nain** | légère, courante, distance, arc court | légère, bouclier, **lourde** |
| **Elfe** | légère, courante, distance, arc long, arc court, érudit | légère, bouclier |
| **Magicien** | légère **seule**, plus érudit | **arcanique seule** (brassards, cape) |

Les tags portent les restrictions que les cartes énoncent classe par classe
(reference/16 §2.2) : sept ne suffisaient pas — l'arc long est refusé au nain,
l'arc court au barbare, la canne aux deux costauds, et deux protections sont
*réservées* au magicien. Chaque tag correspond exactement à une phrase de carte.

| Tag | Pièces | Phrase de carte | Ouvert par |
|---|---|---|---|
| `arme_legere` | Dague, Bâton, Fouet, Fronde | *(aucune restriction)* | base, toutes classes |
| `arme_erudit` | Canne | « not… by a Barbarian or Dwarf » | base : elfe, magicien |
| `arme_courante` | Épée courte/large/longue, Lance, Hachette, Rapière, Hallebarde, Masse, Fléau, **Fléau des Orques**, **Lame des Esprits** | « not… by a Wizard » | base sauf magicien → *Escrime de fortune* |
| `arme_distance` | Arbalète | « not… by a Wizard » | base sauf magicien |
| `arme_arc_long` | Arc long | « not… by a Wizard or Dwarf » | base : barbare, elfe |
| `arme_arc_court` | Arc court | « not… by a Wizard or Barbarian » | base : nain, elfe |
| `arme_deux_mains` | Hache de bataille, Espadon, Épée bâtarde | « not… by a Wizard or Elf » | base pour le **barbare** ET le **nain** |
| `armure_legere` | Casque, Cotte de mailles, **Armure de Borin** | « not… by a Wizard » | base sauf magicien → *Cuir d'apprenti* |
| `bouclier` | Bouclier | « not… by a Wizard » | base sauf magicien |
| `armure_lourde` | Armure de plates | « not… by a Wizard » | base pour le **nain** ET le **barbare** |
| `armure_magicien` | Brassards, Cape de protection | « may **only** be used by a Wizard » | base : magicien **seul** |
| `talisman_barbare` · `talisman_nain` · `talisman_elfe` · `talisman_magicien` | Amulette du Nord, Runes naines, Brassards elfiques, Capuche du Magister | « may be worn only by a… » | base : la classe nommée, **seule** |

⚠ `deux_mains` (interdit le bouclier) reste **orthogonal** au tag : le Bâton est
à deux mains ET `arme_legere`, donc jouable par le magicien. Les deux mots
répondent à deux questions différentes — *avec quoi* et *par qui*.

> **Symétrie des deux costauds** : chacun a sa spécialité gratuite et paie l'autre.
> Le barbare manie les armes à deux mains de naissance et achète l'armure lourde ;
> le nain porte l'armure lourde de naissance et achète les armes à deux mains.

Deux précisions qui évitent des contresens :

- **`deux_mains` n'est pas une maîtrise.** Il dit seulement « pas de bouclier
  avec » ; c'est le **tag** qui dit qui a le droit de porter. Le *Bâton des Sept
  Sceaux* est à deux mains **et** `arme_legere` : il reste l'artefact du magicien.
- **Aucune maîtrise déclarée = aucune restriction.** Si le catalogue des classes
  n'est pas semé, le moteur échoue *ouvert* : une donnée de référence manquante
  ne doit jamais verrouiller un héros hors de son propre équipement de départ.

Un objet **déjà équipé** n'est jamais retiré rétroactivement : la vérification
n'a lieu qu'au moment d'équiper.

Au **marché**, une pièce que le héros ne maîtrise pas porte un badge
« Non maîtrisé », mais reste **achetable** : la bourse est commune et le don entre
héros existe, donc acheter pour un coéquipier est un usage normal.

### 4 quinquies. Règles du DOS DES CARTES (René, 2026-08-22)

Dix mentions imprimées au dos des cartes de classe. Elles ne se déduisent
d'aucune autre donnée du jeu — ce sont des **faits de source**, au même titre
que la ligne de stats d'un monstre. Figées une par une dans `ReglesDeClasseTest`.

| Classe | Règle | Porté par |
|---|---|---|
| **Nain** | Désamorce les pièges **sans outils** — seul un **bouclier noir** fait échouer | `MoteurPieges::SANS_OUTILS` + résolution dédiée dans `ResolveurTour` |
| **Explorateur** | Idem | idem |
| **Berserker** | N'utilise **aucune arme à distance** | retrait du tag `arme_distance` |
| **Barde** | **+1 dé de défense** tant qu'il ne porte ni armure métallique ni bouclier | `bonus_des_defense_sans_metal` (capacité innée, déjà câblée) |
| **Chevalier** | Les armures **ne ralentissent pas** son mouvement | `Equipement::malusDeplacement()` |
| **Druide** | Aucune **armure métallique** | `objets.metallique` + `Equipement::SANS_METAL` |
| **Rogue** | Aucune armure métallique **ni bouclier** ; **commence avec la Bandoulière** | idem + `EQUIPEMENT_DEPART` |
| **Moine** | Ni armure ni bouclier ; **cinq armes nommées** (dague, arbalète, hachette, épée courte, bâton) | `classes_heros.objets_autorises` |
| **Magicien** | Ni armure ni arme large | déjà porté par ses tags |
| **Warlock** | Uniquement ce qu'un **magicien** peut manier | tags alignés + sa baguette |

**Deux colonnes ont dû naître**, et pour la même raison : un fait qu'on allait
déduire d'un autre qui ne s'y superpose que **par hasard, aujourd'hui**.

- `objets.metallique` — les tags disent le **poids** (`armure_legere` /
  `armure_lourde`), les cartes parlent de **matière**. Le catalogue ne contient
  que deux armures ordinaires, toutes deux métalliques : la déduction marcherait,
  et tomberait en silence à la première armure de cuir. Marqué sur la cotte, la
  plate, le casque **et l'Armure de Borin** (artefact).
  ⚠ Le **bouclier n'est PAS marqué** : les cartes le nomment séparément du métal,
  et le marquer retirerait au Druide un bouclier qu'elles ne lui interdisent pas.
- `classes_heros.objets_autorises` — hachette et épée courte partagent
  `arme_courante` avec l'épée large, l'épée longue et la rapière, interdites au
  Moine. **Aucune combinaison de tags** ne décrit sa liste. La liste blanche
  **remplace** le contrôle par tags quand elle est renseignée, et ne porte que
  sur les pièces exigeant une maîtrise — une potion n'est pas une arme.

⚠ Le désamorçage du Nain et de l'Explorateur est une **résolution différente**,
pas un bonus : un dé, une face perdante sur six. Leur appliquer le jet de Body
ordinaire aurait vidé la mention de sa substance — c'est leur savoir-faire qui
est décrit. Le Nain a d'ailleurs **perdu sa trousse à outils de départ**, devenue
inutile et coûteuse d'une place de sac.

### Sac à dos
- **Capacité = PV de Body *max* ÷ 2** (arrondi inférieur, sur le max et non les PV courants), **+1 pour le Nain** (bonus racial) : **Barbare 4, Nain 4, Elfe 3, Magicien 2**. Le nœud *Solides épaules* du Nain l'augmente encore.
- Le sac stocke les **armes et armures non équipées** ; les emplacements équipés ne comptent pas dans la capacité.
- Les **consommables** (potions, parchemins) et les **objets de quête** sont **illimités** : ils ne consomment jamais la capacité du sac.
- Au **ramassage d'un trésor** : l'objet va au sac s'il reste de la place ; **sac plein → il faut jeter un objet** pour le prendre (tension de gestion).

### Gérer son équipement = une action
Réorganiser son stuff coûte **l'action du tour** (voir doc Combat) :
- **Équiper / déséquiper** : déplacer un objet entre un emplacement équipé et le sac.
- **Jeter** un objet du sac (libère de la place).
- **Échanger avec un joueur adjacent** : transférer armes/armures entre les deux inventaires, dans la limite des capacités respectives.

L'interface affiche en parallèle les **objets équipés** et le **contenu du sac** des personnages concernés.

> **Implémenté (2026-07-30) — le don au hub.** L'échange existe désormais, mais
> **entre deux quêtes**, pas comme action de tour : `POST /groupes/{id}/dons`,
> depuis ses propres héros vers n'importe quel héros actif du groupe. La capacité
> du **receveur** est respectée ; celle du donneur peut être en dépassement (se
> délester est la façon de régulariser un sac saturé par un butin). Une pièce
> équipée se déséquipe d'abord. Les consommables se transfèrent par pile.
> Un **artefact circule** : il appartient au groupe, pas à son découvreur.
>
> L'échange **en pleine quête** (adjacence + coût d'action, tel que décrit
> ci-dessus) reste à faire, comme « équiper » en quête et « jeter un objet ».

### Or
- **À l'arrivée dans un groupe** : l'or **personnel** du personnage est **versé au pot commun** du groupe.
- **En campagne** : bourse **commune au groupe** (M3), gérée par le moteur (jamais inventée par l'IA) — voir doc Market.
- **Départ entre deux quêtes** : le personnage repart avec sa **part égale** du pot (pot ÷ membres présents).
- **À la clôture** : la bourse commune est **répartie entre les personnages** vers leur **bourse personnelle persistante** (roster), avec l'équipement (doc Session §6).

---

## 8. Potions et consommables

Effet immédiat à usage unique, simples à modéliser : soin (rend des Points de Body), restauration mentale, buffs temporaires (ex. +1 dé d'attaque pour un combat), antidote. Stockés comme objets d'inventaire avec un compteur d'usage.

---

## 9. Personnage vs Joueur (multijoueur)

Distinction importante pour l'architecture tablette-hôte + téléphones :

- Un **Joueur** est une personne (un appareil connecté).
- Un **Personnage** est l'entité de jeu (héros).
- Un Joueur **contrôle** un Personnage. Lien explicite à stocker.
- Les **alliés** (phase 2) sont des Personnages sans Joueur, ou assignés temporairement — à trancher.

---

## 10. Conditions / états

Une condition = **effet + durée + source**, attachée à un personnage **ou à un monstre**. Catalogue de base :

| État | Effet | Durée typique | Sources |
|---|---|---|---|
| **Empoisonné** | −1 PV de Body par tour | quelques tours | piège de coffre, attaque ; Nain *Sang robuste* résiste |
| **Étourdi** | perd son prochain tour | 1 tour | choc, capacité |
| **Apeuré** | −dés d'attaque ; ne peut avancer vers la menace | jusqu'à résistance | Dread *Frayeur* |
| **Endormi** | hors combat jusqu'à être réveillé/attaqué | jusqu'au réveil | sort *Sommeil*, Dread |
| **Commandé** | contrôlé un tour (agit pour l'ennemi) | 1 tour | Dread *Commandement* |
| **Ralenti** | déplacement réduit | quelques tours | capacité, terrain |
| **Immobilisé** | ne peut se déplacer (coincé) | jusqu'à libération | fosse (piège) |
| **Caché** | ne peut être attaqué | jusqu'à son prochain tour | sort *Voile de Brume* |
| **Renforcé** | +dés (attaque ou défense) | 1 combat / durée du sort | *Courage*, *Peau de Pierre*, potions |
| **Tombé** | hors de combat à 0 PV de Body ; relevable | jusqu'à relève ou fin de combat | dégâts (P1/C4) |

> Les **morts-vivants (Mind 0)** sont immunisés aux états **mentaux** — apeuré, endormi, commandé (doc Bestiaire).

---

## 11. Modèle de données conceptuel (agnostique)

Champs du **Personnage** (la techno viendra plus tard) :

| Champ | Type | Note |
|---|---|---|
| `id` | identifiant | unique |
| `nom` | texte | |
| `classe` | énum | Barbare / Nain / Elfe / Magicien |
| `niveau` | entier | progression par jalons |
| `attribut_body` | entier | dés de jet physiques |
| `attribut_mind` | entier | dés de jet mentaux |
| `pv_body_max` / `pv_body` | entier | jauge de vie physique |
| `pv_mind_max` / `pv_mind` | entier | jauge de vie mentale |
| `des_attaque` | entier | combat |
| `des_defense` | entier | combat |
| `deplacement_base` | entier | base de déplacement du héros ; total par tour = base + 1d6 |
| `equipement_equipe` | objet | arme principale, arme secondaire/bouclier, armure |
| `sac` | liste | armes/armures non équipées |
| `capacite_sac` | entier | dérivé : PV Body max ÷ 2 (arrondi inf.) + Nain +1 + bonus d'arbre |
| `sorts_connus` | liste | renvoie au doc Sorts |
| `competences_acquises` | liste | nœuds d'arbre débloqués |
| `conditions` | liste | états temporaires + durée |
| `joueur_id` | identifiant | qui contrôle ce personnage |
| `groupe_actif_id` | identifiant | groupe où le personnage est engagé (un seul actif à la fois) ; nul si au repos |
| `or` | entier | bourse **personnelle persistante** (roster) ; en campagne, l'or est commun au groupe (M3) |
| `historique` | relation | résumés des campagnes terminées (table dédiée) |

> Deux niveaux d'or : **commun au groupe** pendant une campagne (M3), **personnel au personnage** une fois réparti à la clôture (doc Session §6).

---

## 12. Périmètre

- **MVP** : Body/Mind (attribut séparé des PV) · jet de compétence par dés · 4 héros · arbre réduit (~6 nœuds) · équipement + or · potions · lien joueur↔personnage.
- **Phase 2** : alliés contrôlables · arbres étendus · conditions avancées · économie variant finement selon le lieu.

---

## 13. Décisions actées

1. **Mort (P1)** : à 0 PV de Body, le héros est **« tombé »** (occupe sa case), relevable par soin ou allié. **Mort définitive** s'il n'est pas relevé avant la fin du combat.
2. **Récupération (P2)** : PV et sorts **récupèrent intégralement entre les quêtes** ; les **potions** soignent en cours de quête. Pas de récupération par repos.
3. **Attributs (P3)** : **aucun plafond** ; le MJ IA monte la difficulté des jets pour conserver le défi.
4. **Réussite des jets (P4)** : **mixte** — selon le contexte, un quasi-échec donne un « succès à coût » ou un échec sec ; arbitrage du MJ IA.
5. **Inventaire (P5)** : **limité par emplacements**.
6. **Niveaux (P6)** : montée **liée à l'achèvement des quêtes** (~5 à 8 niveaux par campagne).
