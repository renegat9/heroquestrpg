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
| `absent` | à la place de `livret`/`cadre` : la **raison** de l'absence |

Une entrée `absent` n'a pas de fichier : c'est une décision écrite, pas un oubli. Une
absence se dit ainsi : « aucun livret ne dessine … », « figurine posée par le sort … », « à
reprendre » quand le symbole existe mais n'est pas encore isolé.

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
