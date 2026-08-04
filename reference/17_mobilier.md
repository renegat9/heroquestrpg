# 17 — Mobilier et éléments spéciaux du plateau

> Document d'analyse, méthode identique à `16_armurerie.md`. **Rien n'est complété de
> mémoire** : une case vide ou marquée « ⚠ non établi par le livret » signale une
> donnée que les sources ci-dessous ne fournissent pas.

## 0. Sources et méthode

Deux livrets officiels HeroQuest (relance Avalon Hill 2021), déjà téléchargés et
extraits dans le dossier de travail :

- **RB** — livret de règles (`regles_base.pdf`/`.txt`, 24 pages imprimées = 24 pages
  PDF, numérotation identique). Couvre les règles génériques : mouvement, actions,
  pièges, fin de quête.
- **LQ** — livret de quêtes (`core_quest_book.pdf`/`quetes_base.txt`, 19 pages PDF).
  Contient les 14 quêtes de la boîte de base **et**, en pages finales, la planche
  « Design Your Own Quest Adventures » (légende complète des symboles de meuble,
  **page imprimée 33** — visible en texte à la fin de `quetes_base.txt` et en image
  dans `pageC-12.png`/les recadrages `crops/legend*.png`, `crops2/*.png` du dossier de
  travail). Les citations `LQ p. N` renvoient au **numéro imprimé en bas de page**
  (visible sur chaque planche), pas à l'index PDF — les deux ne coïncident plus après
  la fin de la 14ᵉ quête, le PDF sautant directement des dernières notes de quête
  (imprimée p. 31) à la planche-légende (imprimée p. 33).

**Le mobilier est presque exclusivement illustré**, pas décrit en prose : le livret de
règles ne donne son inventaire qu'une fois, sous forme de liste de contenu de boîte
(RB p. 4), et le livret de quêtes ne le nomme que quand une note de quête y accroche
un texte narratif (« *the alchemist's bench* », « *this treasure chest is empty* »).
**Aucune des deux sources ne donne jamais d'emprise en cases sous forme de nombre.**
Les emprises de ce document ont donc été établies par **comptage direct des cases de
grille sous l'icône du meuble**, sur les cartes de quête réellement imprimées (rendus
PNG à 150 dpi des pages LQ, recadrés et suréchantillonnés ×6 à ×9 pour aligner les
bords de l'icône sur les traits de grille — fichiers `crops2/q*.png` du dossier de
travail). C'est une mesure visuelle, pas une valeur écrite noir sur blanc : elle est
présentée comme telle dans le tableau §1, avec le niveau de confiance de chaque ligne
(mesure propre sur une icône identifiée par le texte d'une note, mesure propre sur une
icône identifiée seulement par sa forme, ou aucune mesure disponible).

## 1. Inventaire du mobilier

RB p. 4 donne l'inventaire de boîte, sous « GAME CONTENTS » : *« 15 furniture pieces:
2 tables, throne, alchemist's bench, 3 treasure chests, tomb, sorcerer's table,
2 bookcases, rack, fireplace, weapons rack, cupboard »*. Recomptées une par une, ces
15 pièces couvrent **11 types distincts** : table (×2), coffre (×3), bibliothèque
(×2), et 8 types en un seul exemplaire (trône, établi d'alchimiste, tombeau, table du
sorcier, portant/« rack », cheminée, râtelier d'armes, armoire). La planche-légende
LQ p. 33 (« Design Your Own Quest Adventures ») confirme très exactement ces 11 noms,
une icône chacun, dans les colonnes « Tables / Chests / … / Bookcase / Sorcerer's
table / Alchemist's bench / Throne / Fireplace / Cupboard / Tomb / Rack / Weapons
rack » — reproduites à l'identique dans le texte extrait de `quetes_base.txt`
(lignes 701-713).

Sur les cartes de quête réellement imprimées, chaque type mesuré directement occupe
un **rectangle plein de 1×1 ou de 1×2 cases** (jamais de forme en L, jamais plus de 2
cases) ; aucun ne dépasse ces deux gabarits sur les instances mesurées.

| Nom anglais (RB/LQ) | Nom français proposé | Emprise mesurée | Bloque le déplacement | Fouillable | Effet de jeu / confiance |
|---|---|---|---|---|---|
| Table | Table | **2 cases (2×1)** | ⚠ non écrit noir sur blanc (voir §3) | Via la fouille de la **salle** (RB p. 14), pas un objet séparé | Mesuré LQ p. 13 (Quête 5, salle D) sur l'icône à hachures de bois, unique dans tout le jeu de symboles — identification par la **forme**, aucune note de quête n'y accroche ce nom |
| Coffre au trésor (« Chest ») | Coffre | **1 case (1×1)** | ⚠ idem | Oui — cible narrative privilégiée des notes de quête | Mesuré LQ p. 13 (Quête 5, note C : « *this chest is filled with poisonous gas — it is a trap!* ») ; peut porter un piège coffre/meuble (§2, §3) |
| Trône | Trône | **1 case (1×1)** | ⚠ idem | Oui | Mesuré LQ p. 13 (Quête 5, note E) ; peut être un déclencheur scénarisé — « *the throne slides sideways, revealing a secret door* » (LQ p. 13) |
| Établi d'alchimiste | Établi d'alchimiste | **2 cases (1×2)** | ⚠ idem | Oui | Mesuré LQ p. 13 (Quête 5, note A : « *a half-filled flask sitting on the alchemist's bench* ») |
| Tombeau | Tombeau | **2 cases (1×2)** | ⚠ idem | Oui | Icône identifiée par une note (LQ p. 27, Quête 12, note D : « *this is the tomb of the Witch Lord* ») ; emprise mesurée proprement sur 2 occurrences distinctes de la même icône (LQ p. 25 et p. 27), toujours 1×2 — une 3ᵉ occurrence aperçue (LQ p. 27) n'a pas pu être mesurée, hors cadre du recadrage |
| Table du sorcier | Table du sorcier | ⚠ non établi par le livret | ⚠ | Probable (meuble comme les autres) | Icône repérée (rectangle orné, motif de gemmes/fermoir) mais jamais isolée assez proprement dans les cartes examinées pour un comptage de cases fiable |
| Bibliothèque | Bibliothèque | **2 cases (2×1)** | ⚠ idem | Oui | Icône identifiée par une note (LQ p. 27, Quête 12, note E : « *a magical staff hidden behind the bookcase* ») ; mesure propre sur cette même occurrence |
| « Rack » (générique) | Portant | ⚠ non établi par le livret | ⚠ | Probable | Aucune occurrence isolée trouvée sur les cartes examinées ; la légende LQ p. 33 le distingue explicitement du « Weapons rack » (deux icônes différentes) — nature exacte non décrite en texte |
| Cheminée | Cheminée / âtre | ⚠ non établi par le livret | ⚠ | Probable | Aucune occurrence trouvée sur les cartes examinées |
| Râtelier d'armes | Râtelier d'armes | **2 cases (1×2)** | ⚠ idem | Oui | Mesuré **exactement à la position** que désigne LQ p. 5 (Quête 1, note A : « *the weapons on this weapons rack are chipped, rusted, and broken* ») — la mesure la mieux corroborée du tableau (texte + position + grille alignée) |
| Armoire | Armoire | **2 cases (2×1)** | ⚠ idem | Oui | Icône identifiée par sa forme (rectangle uni, sans motif — correspond à la légende LQ p. 33) sur LQ p. 13 (Quête 5, salle D, deux exemplaires côte à côte) ; aucune note de quête n'y accroche ce nom dans cette instance |

**Lecture du tableau.** Huit des onze types (table, coffre, trône, établi
d'alchimiste, tombeau, bibliothèque, râtelier d'armes, armoire) ont été mesurés
directement, cases de grille comptées une à une contre les traits de la carte
imprimée ; trois (table du sorcier, portant, cheminée) n'ont **aucune mesure
indépendante** dans ce dossier — leur icône, sur la
planche-légende LQ p. 33, est dessinée dans le même style de rectangle allongé que
les pièces mesurées à 2 cases, ce qui est cohérent avec la même emprise, **mais ce
n'est qu'une analogie visuelle, pas une mesure** : ne pas coder ces trois emprises
comme acquises.

**Colonne « bloque le déplacement ».** Marquée ⚠ pour les onze types : voir §3, aucun
passage du livret de règles ne dit en toutes lettres qu'un héros ne peut pas entrer
sur la case d'un meuble.

## 2. Autres éléments spéciaux du plateau

### Escalier de départ (« Stairway »)

« As a hero, you normally begin and end a quest in the room marked with the
stairway (unless otherwise specified in the Quest Book). […] To safely complete a
quest, you must return to the stairway, for it is only there that you are truly free
from harm. » (RB p. 11). L'escalier est donc **le point d'entrée ET de sortie par
défaut** d'une quête — une position que le gabarit peut surcharger (« *unless
otherwise specified* »). Une règle annexe, propre aux fosses : un héros peut partager
sa case avec un autre héros ou un monstre uniquement « *when you are on the stairs or
in a pit trap* » (RB p. 11-12) — l'escalier est donc la seule case du plateau, piège
mis à part, où l'occupation exclusive ne s'applique pas. Sur les cartes de quête,
l'icône est un large éventail de degrés incurvés occupant un angle entier de la salle
de départ — visuellement sur plusieurs cases (2 à 3 selon les cartes examinées), mais
**aucune emprise chiffrée n'est donnée par le livret** ; ⚠ non établi.

### Portes normales et portes secrètes

Toutes les portes démarrent fermées ; une fois ouverte, une porte ne peut plus jamais
être refermée (RB p. 11). Les portes secrètes sont invisibles tant qu'elles n'ont pas
été trouvées par l'action *Search for Secret Doors* — « *You cannot discover a secret
door unless you search for one* » (RB p. 16) — après quoi elles deviennent des portes
ordinaires (ouvrables normalement, jamais re-cachables). Ce fonctionnement correspond
déjà à `MoteurPortes` et à `CouloirsTest` côté moteur (états `fermee` /
`verrouillee` / `secrete`, doc `06_quetes.md`).

### Les quatre pièges officiels

« There are four kinds of traps—pit traps, falling block traps, spear traps, and
chest/furniture traps. » (RB p. 16). Résumé (détails et divergences déjà couverts par
`reference/10_pieges.md` et `reference/16_armurerie.md` — non dupliqués ici) :

| Piège | Détection | Déclenchement si non détecté | Franchissement |
|---|---|---|---|
| Fosse (« pit trap ») | Fouille de pièges | 1 PV de Body, chute (RB p. 17) | Saut possible une fois détectée (RB p. 18-19) ; un piège de fosse **déclenché** reste sautable (RB p. 18) |
| Chute de blocs (« falling-block trap ») | Fouille de pièges | 3 dés de combat, dégâts par crâne (RB p. 18) | Une fois déclenché, **bloque le passage à jamais** — « *the trap space is now a permanent block in the game* » (RB p. 18) ; non sautable une fois sprung (RB p. 18) |
| Piège à lance (« spear trap ») | Fouille de pièges | 1 dé de combat, crâne = 1 PV de Body (RB p. 18) | Le piège est **détruit** une fois esquivé ou déclenché — « *the spear trap is now gone forever* » (RB p. 18) ; pas de pion de piège pour ce type (RB p. 18) |
| **Coffre/meuble piégé (« chest/furniture trap »)** | Fouille de pièges de la salle où se trouve le coffre/meuble | Fouiller le **trésor** avant d'avoir fouillé les **pièges** déclenche le piège du coffre/meuble et **termine le tour** (RB p. 18-19) | Se désamorce comme un piège ordinaire (RB p. 19), puis le coffre/meuble redevient fouillable normalement au tour suivant |

Le piège coffre/meuble est celui qui **rattache directement un piège à une pièce de
mobilier nommée** (« *the chest/furniture in question* », RB p. 18-19) — c'est le
lien mécanique explicite entre pièges et mobilier dans le livret de base ; voir §3
pour la mécanique exacte de fouille.

### Cases bloquées et roche solide

Deux notions distinctes, à ne pas confondre :

- **Case bloquée (« blocked-square tile »)** : un pion cartonné que Zargon pose « *as
  soon as it becomes visible to the hero* » — donc un élément **découvert
  progressivement**, comme un monstre ou une porte, et non connu d'avance du plateau.
  « *These tiles show where extra walls have been built. Neither heroes nor monsters
  can move through blocked squares.* » (RB p. 13, répété p. 17). Infranchissable pour
  les deux camps, explicitement.
- **Case doublement bloquée (« double-blocked squares »)** : symbole distinct sur la
  planche-légende LQ p. 33, dessiné comme deux cases de blocage accolées — nom
  explicite (« double »), mais **aucune description en prose** dans les deux
  livrets ; ⚠ l'emprise à 2 cases se déduit du nom et du dessin, non d'un texte.
- **Roche solide (« solid rock »)** : contrairement aux cases bloquées, ce n'est pas
  un pion révélé au fil de la partie mais une propriété **fixe et déjà visible** de la
  carte imprimée — « *Dark shaded areas on all quest maps are considered solid
  rock.* » (LQ p. 5, note de la Quête 1). C'est l'équivalent visuel de tout ce qui, sur
  une carte de quête, n'appartient à aucune salle ni aucun couloir : le hors-plan du
  donjon, jamais un obstacle à découvrir.

## 3. Règles associées

### Comment on fouille un meuble — au niveau de la salle, pas de l'objet

Point le plus important pour l'implémentation : **le livret de règles ne fait jamais
du mobilier une cible de fouille distincte.** L'action *Search for Treasure* se
déclare et se résout à l'échelle de la **salle entière** :

« Treasure is found only in rooms, not in corridors. A room may be searched by all
four heroes, but each individual hero may only search the room once and may do so
only on their own turn. […] Searching for treasure means you are looking around,
opening things, searching for interesting objects and gold coins, **regardless of
what square you are on in the room**. Do not move your hero figure when you search. »
(RB p. 14, répété p. 15)

Il n'existe donc, dans le livret, **aucune action « fouiller CE coffre » ou « fouiller
CETTE bibliothèque »** distincte de « fouiller la salle ». Ce qui rattache visuellement
une trouvaille à une pièce de mobilier précise, ce sont uniquement les **notes de
quête** — un texte scénarisé, écrit par le concepteur de la quête, qui décrit *où* dans
la salle se trouve l'objet trouvé (« *a half-filled flask sitting on the alchemist's
bench* », LQ p. 13) sans que ce soit une règle mécanique généralisable : la moitié des
notes de quête consultées ne nomment **aucun** meuble du tout, elles disent juste « ce
coffre contient X pièces d'or » (LQ p. 5, 9, 13, 17, 21…). Le mobilier est donc, dans
le jeu de plateau, **un habillage de la fouille de salle, pas un second système de
fouille**.

### Ce qu'on y trouve

RB p. 16, « More About Treasures » : « Treasure can be a variety of things, including
gold coins, magic spells, artifacts, and potions. » Deux sources possibles à la
fouille d'une salle (RB p. 14) :

1. **Trésor spécial** décrit dans les notes de quête (le cas le plus fréquent dans
   les 19 pages consultées) — trouvé une seule fois, par le premier héros qui fouille
   la salle, même si d'autres la fouillent ensuite (RB p. 14) ;
2. à défaut, **une carte piochée** dans le deck de trésor du plateau — dont environ la
   moitié sont des monstres errants ou des dangers (RB p. 14-15), remises dans le deck
   après tirage (contrairement aux cartes d'or/potion, conservées).

### Piège de meuble : un cas à part de la fouille

Un coffre ou un meuble piégé s'inscrit dans le même ordre que n'importe quel autre
piège de la salle : fouiller le **trésor** avant d'avoir fouillé les **pièges**
déclenche le piège du meuble et termine le tour (RB p. 18-19, cf. tableau §2). Une fois
désamorcé, le meuble « redevient » un objet de fouille ordinaire au tour suivant.

### Peut-on traverser un meuble ? — une zone d'ombre du livret

**Le livret de règles ne dit jamais explicitement qu'un héros ne peut pas se tenir sur
la case d'un meuble**, alors qu'il le dit noir sur blanc pour les cases bloquées
(« *Neither heroes nor monsters can move through blocked squares* », RB p. 13). Sur le
plateau physique, la question ne se pose pas : une pièce de mobilier est une figurine
en volume, on ne peut matériellement pas y poser un pion héros par-dessus — la règle
est **imposée par les composants**, jamais formulée en mots. Un moteur numérique n'a
pas cette contrainte physique de fait : il faudrait donc soit adopter par convention
la même règle que les cases bloquées (infranchissable pour les deux camps), soit
inventer une règle propre (par exemple : bloque le passage mais pas la ligne de vue,
ou l'inverse). **Aucune des deux options n'est « la » règle du livret — c'est un choix
de portage, pas une lecture.**

### Une fouille par héros et par salle

« A room may be searched by all four heroes, but each individual hero may only search
the room once » (RB p. 14) — exactement la règle déjà implémentée côté moteur
(`Quete::aFouille()`, entrées `"{salle}:{personnage}"`, cf. `CLAUDE.md` §Searching).
Aucun ajustement nécessaire de ce côté : le modèle de fouille du moteur correspond
déjà à la règle du plateau, y compris dans son grain (la salle, pas l'objet).

## 4. Ce que notre moteur ne sait pas faire

Lecture de `app/Partie/Grille.php`, `app/Partie/FabriqueGrille.php`,
`app/Partie/AssembleurCarte.php` et `app/Partie/Fouille/DeckFouille.php`.

- **`Grille` ne connaît que deux états de case.** Le constructeur prend
  `list<list<string>> $cases` documenté « m = mur, s = sol » ; `estTraversable()`
  compare `$this->cases[$y][$x] ?? 'm'` à `'s'`, sans état intermédiaire. Il n'y a
  aucune troisième valeur possible pour « sol, mais occupé par un meuble
  infranchissable-mais-fouillable ».

- **`FabriqueGrille::pour()` est la source UNIQUE de l'occupation dynamique**, et rien
  n'y alimente de mobilier. La méthode construit `$occupees` à partir de trois
  sources seulement : les héros debout (`etatsPersonnages()`, en excluant les
  tombés — ils s'enjambent), les instances de monstre `actif` (avec leur `emprise()`
  l×h), et les mercenaires alliés `actif`. Aucune quatrième boucle sur un éventuel
  `carte.grille.mobilier` n'existe — pour cause, cette clé n'existe nulle part dans le
  JSON de carte (confirmé par recherche texte sur `app/`, `resources/js/`,
  `reference/`, `docs/` : `mobilier` n'apparaît que dans `18_extensions.md` en
  prospective et dans `06_quetes.md` comme item de vision, jamais dans le code).

- **`AssembleurCarte::assembler()` a déjà, pour deux couches distinctes, exactement le
  patron qu'il faudrait reproduire pour le mobilier.** `leviers` (positions explicites
  du gabarit, via `placerLeviers()` qui lit `structure.leviers`) et `pieges`
  (mélange procédural de milieux de couloir et de cases de salle, via
  `placerPieges()`) sont tous deux retournés comme des **listes sœurs** de `cases` /
  `salles` / `portes`, au même niveau que ces dernières dans le tableau final. Le
  mobilier — comme les leviers, un choix de mise en scène du concepteur de quête
  plutôt qu'un tirage aléatoire, cf. §1 — se déclarerait très naturellement en
  `structure.mobilier` (position + type + orientation) et se retournerait comme
  `list<array{x, y, largeur, hauteur, mobilier_id, etat}>`, exactement comme
  `leviers`. Aujourd'hui, cette méthode et cette clé de retour n'existent pas.

- **La géométrie « grande emprise » existe déjà et n'attend qu'un appelant.**
  `Grille::cellulesEmprise(x, y, l, h)` — déjà utilisée pour les monstres de grande
  taille (l'Ogre en 1×2, doc `12_schema_donnees.md`) — calcule exactement les cases
  couvertes par un rectangle l×h ancré en haut-gauche ; `empriseLibre()`,
  `adjacenteAEmprise()` et `ligneDeVueEmprise()` en dérivent déjà l'occupation, le
  contact et la ligne de vue. Une table 2×1 ou un tombeau 1×2 (§1) réutiliserait ces
  quatre méthodes **sans une ligne de géométrie nouvelle** — il ne manque que la
  boucle côté `FabriqueGrille` qui pousserait les cases de chaque meuble dans
  `$occupees`, au même titre que celle qui existe déjà pour `$instance->monstre->emprise()`.

- **`DeckFouille` résout déjà « Fouiller — trésor » au grain de la salle, ce qui colle
  à la règle du plateau (§3) — mais ne donne au meuble aucune existence propre.** Le
  deck est unique par quête (`quetes.deck_fouille`), chaque carte est auto-suffisante
  (`{issue, or?, objet_id?}`), et la clé d'unicité de fouille est
  `"{salle}:{personnage}"` : rien dans cette structure ne référence un
  identifiant de meuble. Le manque n'est donc pas dans la mécanique de tirage —
  déjà conforme à RB p. 14 — mais dans l'absence d'un OBJET DE CARTE que l'IA
  pourrait désigner dans sa narration (« *vous fouillez l'établi d'alchimiste…* ») et
  que le plan de salle du contrôleur pourrait dessiner et rendre infranchissable.

- **Le piège coffre/meuble (RB p. 18-19, §2) n'est pas distingué des autres pièges
  côté moteur.** `MoteurPieges` ne référence, dans les recherches effectuées, qu'un
  `type` d'effet (`piege_declenche`, `pieges_detectes`) et aucun `mobilier_id` — la
  distinction « ce piège est un coffre/meuble » vs « ce piège est au sol dans un
  couloir » reste purement narrative aujourd'hui (habillage IA), jamais mécanique.

- **Aucun rendu front.** `resources/js/components/carte/DungeonGrid.vue` — le
  composant partagé qui dessine déjà portes, pièges et brouillard (`CLAUDE.md` §Map
  generation) — ne porte aucune couche « mobilier » : ni icône, ni légende, ni style
  de case dédié.

**En résumé** : la brique géométrique (emprise l×h, occupation, ligne de vue) existe
déjà et est éprouvée sur les grands monstres ; le patron de déclaration
gabarit → overlay de carte existe déjà et est éprouvé sur les leviers et les pièges.
Ce qui manque est **exclusivement** la troisième pièce — un catalogue `mobilier`
(nom, emprise, fouillable), une méthode `placerMobilier()` dans `AssembleurCarte`, la
boucle d'occupation correspondante dans `FabriqueGrille`, et le rendu front — pas une
nouvelle géométrie ni un nouveau modèle de fouille.
