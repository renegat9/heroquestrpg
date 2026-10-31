# Symboles des livrets — extraction

Les cartes de quête des livrets Hasbro dessinent chaque élément avec un **symbole** (une
tuile réduite, à la taille d'une case). Cet outil découpe ces symboles dans les PDF officiels
pour que la carte du jeu les affiche à la place des icônes génériques.

**Décision de René (2026-10-24)** : les symboles extraits (`.webp` seulement) entrent dans le
dépôt, sous `public/images/livrets/`. **Les PDF et les pages entières (rendus, planches
de contrôle) n'entrent jamais dans le dépôt** : ils restent dans `~/heroquest-livrets/` et
dans le scratchpad. Ce qui est commité ici, ce sont l'outil, le manifeste (des coordonnées,
pas des pixels) et le code qui les lit.

## Lancer

```sh
./image-tools/symboles-livrets.sh                    # tout le manifeste
./image-tools/symboles-livrets.sh --seul mobiliers/table   # un seul symbole
CHEMIN_LIVRETS=/chemin/livrets ./image-tools/symboles-livrets.sh
SORTIE=/autre/dossier ./image-tools/symboles-livrets.sh
```

- PDF lus dans `CHEMIN_LIVRETS` (défaut `../heroquest-livrets`), montés **en lecture seule**.
- Conteneur jetable `python:3.12-slim` (PyMuPDF + Pillow), `python -I`, script hors du dossier
  des données : les livrets sont des fichiers téléchargés, on ne les exécute pas.
- Idempotent : chaque symbole est recalculé à partir du PDF et écrasé à l'identique.
- Résumé final : `N extraits, M absents déclarés (liste), E erreurs`.

## Sortie et convention de nommage

`public/images/livrets/{type}/{cle}.webp`, où `cle` = `Str::slug()` du **nom de catalogue**,
libellés fixes compris (`portes/verrou-pierre.webp`, jamais `verrou_pierre`). Types :
`mobiliers`, `terrains`, `pieges`, `portes`, `leviers`, `escalier`, `dangers` (phase 1),
`monstres` (phase 2). Les portes ont pour clé l'état du moteur (`MoteurPortes::ETAT_*`) ; la
porte de pierre a pour libellé `verrou pierre`.

Par défaut un symbole est **monochrome** : trait recoloré en crème `#efe6d8` avec un liseré
sombre, fond transparent (blanc de la case, parchemin, ou la teinte claire dominante du cadre,
pour un sol peint ou un papier gris). Un symbole **en couleur** (tuile peinte, icône photographique)
garde ses couleurs : `"couleur": true`. Sans fichier, l'affichage garde l'icône actuelle.

## Le manifeste

`manifeste.json` : une entrée par symbole du catalogue, extrait ou absent.

| Champ | Sens |
|---|---|
| `type`, `cle`, `nom_catalogue` | rangement ; `cle` = `Str::slug(nom_catalogue)` |
| `livret` | nom du PDF, dans `CHEMIN_LIVRETS` |
| `page` | numéro de page **PDF** (1-based), pas la pagination imprimée |
| `cadre` | `[x0, y0, x1, y1]` en points, **repère d'affichage** (voir piège ci-dessous) |
| `rotation` | degrés, **horaires**, appliqués au rendu (0, 90, 180, 270) |
| `emprise` | `[largeur, hauteur]` en cases : le symbole est posé sur ce rapport (transparent autour) |
| `couleur` | `true` = garder les couleurs du livret |
| `seuil` | optionnel, défaut `28` : seuil bas de la force du trait. Le relever (`50` pour la bibliothèque) efface une marbrure du papier sans toucher au trait, sans rogner le cadre |
| `poussiere` | optionnel, `true` : retire les **composantes connexes** de moins de 0,2 % de la plus grande (poussière du papier, éclats d'encre). **Opt-in par symbole**, jamais global : un pointillé ou une hachure faite de petites composantes y perdrait son dessin (`trone`, `mare-dacide` et `escalier` changeaient entièrement, mesuré le 2026-10-24). Seul `portes/secrete` l'utilise |
| `source` | « F2847 p. 33 (PDF 17), « Tables » » — livret, page imprimée, légende |
| `note` | écart à signaler (même symbole qu'un autre, rotation, etc.) |
| `natif` | `{"xref": N, "boite_px": [x0, y0, x1, y1]}` : une case prise dans le **raster embarqué** `N`, à sa résolution native (sans rendu de page) |
| `disque` | **jetons de monstres seulement** : `[cx, cy, R]`, centre et rayon **extérieur** de l'anneau, en points d'affichage. Le jeton est ROND : pas d'`emprise`, pas de rognage (voir « Jetons de monstres ») |
| `absent` | à la place de `livret`/`cadre` : la **raison** de l'absence |

Une entrée `absent` n'a pas de fichier : c'est une décision écrite, pas un oubli. Une
absence se dit ainsi : « aucun livret ne dessine … », « figurine posée par le sort … », « à
reprendre » quand le symbole existe mais n'est pas encore isolé.

## Jetons de monstres (phases 2 et 3, 2026-10-29)

Les cartes de quête nomment chaque monstre errant par une étiquette, « Wandering Monster in this
Quest: Orc », et posent à côté une **tête dans un anneau vert** : c'est le jeton. Il vit dans
`public/images/livrets/monstres/{cle}.webp` (commité, même régime que les symboles de carte).

- **`nom_catalogue` d'un jeton = le nom que le livret donne à la figure** (2021 : `Orc`, `Dread Warrior`,
  `Ice Gremlin`…). Il ne suit PAS le catalogue français : `cle = Str::slug(nom)` reste la règle, et le
  lien catalogue → fichier est écrit dans `config/symboles_monstres.php`.
- **`config/symboles_monstres.php`** : `cles` (nom de catalogue → clé, quand la clé n'est pas le slug du
  nom : traduction, nom de 1989, figurine partagée) et `absents` (nom → raison). Un nom absent des deux
  reçoit `Str::slug(nom)`. `SymbolesMonstresTest` vérifie tout **dans les deux sens** : chaque monstre a un
  jeton ou une raison, chaque clé pointe sur un catalogue et un fichier, chaque `.webp` sert un monstre, et
  le manifeste déclare exactement les jetons utilisés.
- **Le disque.** `disque` = `[cx, cy, R]` ; `cadre` = le carré `[cx±1,2R, cy±1,2R]`. Le disque de rayon R garde
  **tout** (couleurs du livret : anneau vert, tête). La couronne jusqu'à 1,15 R ne garde que ce qui
  s'écarte du fond, ou ce qui est nettement plus sombre (le contour), et seulement s'il **touche** le
  disque : les oreilles de la tête dépassent l'anneau, un masque strictement circulaire les couperait ; une
  poussière du fond, isolée, part. Le fond est la teinte claire la plus fréquente hors de la couronne
  (parchemin beige, papier pâle du Frozen Horror).
- **L'ovale (phase 3).** `ovale` = `[cx, cy, rx, ry]` : le centre et les DEMI-AXES de l'anneau, en points
  d'affichage (`cadre` = `[cx±1,2rx, cy±1,2ry]`). Même traitement que le disque (`mettre_en_jeton()`, la distance
  mesurée en rayons normalisés, un seul point de passage) ; `rotation` (horaire) couche le grand axe à l'horizontale,
  l'image est rognée puis posée dans un cadre **2:1**, à 512 × 256 (`extraire_ovale()`). Le fichier est TOUJOURS
  horizontal : c'est le serveur qui décide s'il se couche à l'affichage (`symbole_rotation`, contrat-api).
  Un ovale va avec une figure 1×2 : `config/symboles_monstres.php` (`ovales`) le déclare par nom de catalogue, et
  `SymbolesMonstresTest` vérifie la liste dans les deux sens (chaque nom a un fichier 2:1, chaque fichier 2:1 est déclaré).
- **`rotation`** : une étiquette verticale (pages « rot 0 » des Frozen Horror, Dread Moon, Mage du
  Miroir, Jungles, Telor, Morcar, Ogre Horde, Spirit Queen) se lit de bas en haut, et sa tête tourne avec
  elle : `rotation: 90` (horaire). Le sens se mesure sur le texte (`get_text('dict')`, `line['dir']`, passé
  dans la matrice de rotation de la page), pas à l'œil. Une PLANCHE couchée (« Design Your Own », Ogre Horde p. 43,
  Morcar p. 43, Frozen Horror p. 39, Delthrak p. 26) se lit de la même façon : `rotation: 90`.
- **Mesure.** Un anneau se trouve par composante verte (`g − r > 30` et `g − b > 15`) près de l'étiquette
  « Wandering ». Le centre et le rayon viennent de la boîte de la composante (`R` = demi-largeur + ½ px).
  L'enveloppe des pixels verts (`g − r > 40`, `g − b > 25`) ne sert qu'aux anneaux coupés. **Quand la tête coupe
  l'anneau en haut et en bas** (Goblin de Spirit Queen, p. 5), la composante est un arc, non carré : le
  rayon est alors mesuré à l'enveloppe des pixels verts (`note` dans le manifeste).

### Pièges payés (jetons)

- **Deux repères, encore.** Les rectangles de `search_for` et de `get_text('words')` sont dans le repère
  NON pivoté. Pour les pages `/Rotate 90`, `page.rotation_matrix` les ramène à l'affichage (vérifié sur
  Kellar's Keep p. 5 : « Wandering Monster » tombe à x 298–401, y 877–891) ; `transformation_matrix`, non.
- **L'ordre des mots, pas la géométrie.** Une étiquette verticale ne se regroupe pas par ses coordonnées
  (le filtre « même ligne » tronquait « Wandering » seul). Les mots se suivent dans l'ordre de `get_text`.
- **Un Goblin de First Light (G0978) fait ~6 pt**, un Goblin de Spirit Queen ~20 pt : un filtre de taille
  fixe les rate. Les tailles sont relatives à la page.
- **Les étiquettes à deux noms** (« If the hero is disguised, Specter ; if not, Magus Guard », Dread Moon,
  PDF 10 et 12) n'ont pas de jeton propre : on prend l'anneau de l'étiquette à un seul nom (Magus Guard, PDF 11).
- **Le Frozen Horror n'a pas de parchemin** : le fond y est gris pâle. Une référence « beige » ne trouve rien
  (« aucun parchemin autour du jeton », trois erreurs avant la correction) : le fond est désormais la teinte
  claire la plus fréquente, quelle qu'elle soit.
- **La poussière de la couronne.** Une référence de fond juste ne suffit pas : des taches du papier, proches
  du dessin, restent dans la couronne. Seul ce qui touche le disque est gardé (la tête et ses oreilles).
- **Un ovale ne se mesure pas au remplissage des moments.** Les armes, cornes et poings qui dépassent gonflent
  la variance, et la boîte brute du Minotaure fait 28,7 × 43,7 pt contre un corps de 22,2 × 43,9 pt. On lit la
  BOÎTE DU CORPS après ouverture morphologique (les pointes fines sont écartées), et l'on vérifie à l'œil sur un
  agrandissement à 600 dpi.
- **Un seuil de vert ne tient pas une photo.** Le Dragon (planche First Light, un photographié) est fragmenté par le
  blanc du dessin : la composante verte donne 18 × 18 pt. On mesure la SILHOUETTE sombre remplie (`lum < 165`, trous
  comblés, ouverture de 3 pt) : le corps fait 44 × 22 pt, cornes et menton dépassent. Mesure à ±1 pt : à revérifier
  si René photographie la carte de monstre du Dragon.

### Sources de jetons, et ce qui reste dehors

- **Légendes nommées** (la figure est dessinée avec son nom) : Delthrak PDF 6 (Blightweaver, Goblin Archer,
  Skeleton Archer, Raptor, Skullblight ; Serpent, Giant Ape, Blightcrawler) et Ogre Horde PDF 19 (Orc Archer,
  Goblin Archer, Skeleton Archer, Ogre Warrior ; Ogre Lord, Ogre Champion, Ogre Commander). Les archers et le
  Blightweaver sont des jetons ronds ; les figures **1×2** ont leur OVALE (phase 3) : Ogre Champion, Ogre Commander
  et Ogre Lord (Ogre Horde PDF 19), Serpent et Giant Ape (Delthrak PDF 6), Giant Wolf (Mage of the Mirror PDF 7).
- **Planches « Design Your Own »** (dernière page, symboles étiquetés) : Ogre Horde PDF 43 (Ogre Lord, Champion,
  Commander, Warrior), Delthrak PDF 26, Mage of the Mirror PDF 20, Wizards of Morcar PDF 22 (Minotaur, PDF 22 : le
  Minotaure a son ovale ici), Frozen Horror PDF 20 (Frozen Horror), First Light PDF 23 (Dragon : ovale horizontal,
  le seul à ne pas être couché). Ogre Horde PDF 41 : le tableau « Monster Map Symbol » donne les mêmes symboles.
  Rise of the Dread Moon PDF 20 (René, 2026-10-31 : « les tokens sont affichés après les dernières quêtes ») : Dread
  Cultist, Elven Warrior, Elven Archer, Sir Ragnar, et le Dread Wraith en OVALE (Magrian, passé à deux cases). Wizards of Morcar PDF 22 : un jeton PAR lieutenant (Orc Warcaster,
  Necromancer, High Mage, Storm Master), l'Artificer et le Dreadshifter — la planche en dessine deux, « Dreadshifter »
  et « Dreadshifter (Chest) » ; c'est la version coffre qui est extraite, celle que le catalogue pose.
  Pas de planche dans Prophecy of Telor, Spirit Queen's Torment ni The Crypt of Perpetual Darkness ; Delthrak
  remplace la sienne par le tableau « Map Symbol » (PDF 26), déjà exploité.
- **Figures 1×2 dont le livret dessine un jeton ROND** : Ogre (Mage du Miroir, PDF 7 : légende, jeton rond de 1,05),
  Ogre guerrier (Ogre Horde PDF 19, 37, 41, 43 : rond partout) et Horreur des Glaces (planche Frozen Horror PDF 20,
  rapport 1,13). Le livret les dessine RONDS, alors que le catalogue les pose 1×2 : ils gardent le jeton rond
  (`disque`), et l'EMPRISE ne change pas. ⚠ Écart à trancher (René) : figure 1 case au livret, ou 2 au catalogue.
- **Blightcrawler** (Rampant putride, Deathspinner la Reine du Fléau) : jeton OVALE à la légende Delthrak PDF 6, mais
  figure 1×1 au catalogue. NON extrait : l'emprise est une règle de déplacement, à trancher, pas un détail d'image.
- **Carte, sous une lettre** : la gargouille de la quête 3 de base (F2847 PDF 7, lettre B : « a gargoyle that
  appears to be a stone statue ») est un jeton posé sur le plan ; sa note nomme la figure, le jeton est celui-là.
  ⚠ Les lieutenants de Morcar ont partagé un jeton générique `morcar-sorcerer` (« one of the Sorcerers », G1504
  PDF 19) jusqu'au 2026-10-31 : la planche PDF 22 dessine chacun sous son nom, le jeton commun a été retiré.
- **Les parois rouges de la carte** : le rouge franc n'est jamais du dessin, il est retiré dans le disque comme
  dans la couronne (une paroi bordeaux, sous la lettre B, restait dans le cercle).
- **Spawnling** (étiquette « Wandering Monster in the Quest », Delthrak PDF 7) n'est pas extrait : son fond est une **tuile carrée**, pas un disque. « Rejeton putride » reste donc « à reprendre ».
- **Hors catalogue** : Werewolf, Thief, Mercenary Swordsman, Arcane Mummy, Stone Sentry, Elite Dread Warrior,
  Reservoir Guardian : un jeton existe, aucun monstre du catalogue ne le porte, donc rien n'est extrait (un jeton
  sans monstre serait décoratif).

## Ajouter un symbole

1. **Trouver la page.** Les planches « Design Your Own Quest Adventures » (dernière page du
   livret) donnent tous les symboles, étiquetés ; à défaut, la légende « Components » ou
   « Dungeon Features » de la première page. `texte/<CODE>_en-us/pNN.txt` indique où chercher.
2. **Repérer le cadre** en points du repère d'affichage, en serrant le symbole **sans son
   voisin** (un texte ou une photo dans le cadre devient de l'encre et se retrouve sur la
   carte). Vérifier sur une planche de contrôle hors dépôt.
3. **Ajouter l'entrée** dans `manifeste.json` (ou la modifier), avec `source` et `note`.
4. **Extraire** : `./image-tools/symboles-livrets.sh --seul type/cle`, puis regarder le `.webp`.
5. **Tester** : `php artisan test tests/Feature/Symboles` (sqlite, jamais la base de jeu).
6. **Un jeton de monstre 1×2** : si le livret le dessine en OVALE (2:1, légende ou planche), c'est un `ovale` (mesure
   « L'ovale », ci-dessus) et son nom de catalogue entre dans `ovales` de `config/symboles_monstres.php`. Si le livret
   le dessine ROND, c'est un `disque` et l'emprise n'en tient pas compte : l'écart se signale, il ne se corrige pas ici.

## Pièges déjà payés

- **Deux repères.** PyMuPDF rend les pages « pivotées » (`/Rotate 90`) dans un repère
  d'affichage, mais `get_text('words')` renvoie les mots dans le repère NON pivoté : il faut
  les multiplier par `page.transformation_matrix`. Les cadres de ce manifeste sont dans le
  repère d'affichage (celui de `get_pixmap(clip=…)`).
- **Le voisin.** Le rognage se fait au trait : une fenêtre qui touche un texte ou une photo
  les emporte. Un cadre qui touche son bord est à revoir.
- **Le voisin qui touche le cadre, sur une page où les symboles s'empilent** (portes du
  Game System, F2847 p. 33) : chaque porte est à 12 pt de la suivante, et le cadre de
  `fermee`/`verrouillee` en prenait le HAUT (2 912 px, 18 % du battant — un second battant
  au bas de l'image, constaté le 2026-10-24). Le cadre s'arrête désormais entre les deux :
  `[416, 812, 440, 823.2]`, le battant allant de 814,0 à 822,3 pt, la porte suivante
  commençant à 825,1 pt. Mesure : `connected components` du rendu, avant de rogner.
- **La poussière n'est pas du bruit partout.** Une composante minuscule est du bruit dans
  une porte (`secrete` : quinze taches de 8 px au plus), mais un pointillé ou une hachure
  pour un autre symbole. D'où l'option `poussiere` par entrée, et non un filtre global :
  le filtre global changeait `trone`, `mare-dacide` et `escalier` entièrement.
- **Le sens d'une porte n'entre pas dans le manifeste.** Le dessin du livret est un battant
  COUCHÉ (porte de mur horizontal, nord-sud), donc `rotation: 0`. La porte qui relie l'est et
  l'ouest est tournée par le SERVEUR (`symbole_rotation: 90`, `MoteurPortes::rotationSymbole()`) :
  ne pas tourner l'image ici, le catalogue reste dans le sens du livret.
- **Les tuiles en couleur** (Frozen Horror, Delthrak, Mage) ne sont pas des traits : elles
  gardent leurs couleurs (`couleur: true`) et leur cadre doit tenir dans la tuile.
- **Les symboles à 2 orientations** : la `rotation` met le dessin dans le sens de l'emprise du
  catalogue, sans toucher au catalogue (Fournaise : briques debout, tournées pour 3×1).
- **Un raster natif est petit** : les trois tuiles entravantes sont un seul raster de 86×84 px
  dans le PDF ; une case y est donc floue. Une carte de quête à plat (F9907 p. 13) donnerait mieux,
  mais ses tuiles sont mêlées aux figurines : non repris.

## Tests

`tests/Feature/Symboles/SymbolesLivretsTest.php` : JSON valide, types connus, clés uniques et
`Str::slug()`, couverture du catalogue semé **dans les deux sens** (`MobilierSeeder`,
`TerrainSeeder`, `PiegeSeeder`, `DangerSeeder`), états de porte et libellés fixes, raison de
chaque absence, et présence des `.webp` quand le dossier n'est pas vide (il se tait sinon : le
repli sur l'icône est le comportement normal). Un dernier test refuse tout fichier qui ne soit
pas `.webp` dans `public/images/livrets/`.

`tests/Feature/Symboles/SymbolesMonstresTest.php` : la couverture des jetons de monstres **dans les deux
sens** (catalogue semé `MonstreSeeder` ↔ `config/symboles_monstres.php` ↔ `monstres/*.webp` ↔ manifeste), et la
forme de chaque jeton (cadre carré qui entoure le disque, couronne 1,15 R tenue dans le cadre).
