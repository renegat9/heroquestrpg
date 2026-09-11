# Plan — Le mur devient une ARÊTE, pas une case

> ⚠ **Document DATÉ du 2026-09-11.** Instantané de décision, comme les autres
> `docs/plan-*.md` : il n'est pas tenu à jour. **Décidé par René, à faire À
> FROID, après sa session de jeu** — surtout pas la veille d'une partie.

---

## 1. Le besoin, et pourquoi il remonte à la racine

René, après avoir joué : « tu mets toujours une case pour relier 2 salles, mais
dans le jeu original il n'y en a pas », puis, en voyant le correctif partiel :
**« les murs devraient être le contour et non 1 case »**.

La seconde phrase explique la première. Dans notre modèle, un mur est une **case**
(`cases[y][x] === 'm'`). Il s'ensuit mécaniquement que :

- deux salles voisines sont **toujours** séparées par au moins une case — c'est le
  mur lui-même, il ne peut pas être plus mince qu'une case ;
- le **contour d'une salle** doit être un dispositif séparé (ajouté le 2026-09-11,
  `.dg-room-outline`), alors qu'au plateau le contour EST le mur ;
- l'accolement de salles (`accolerSallesMitoyennes()`) ne peut que faire **partager**
  cette case de mur, jamais la supprimer. D'où le plafond mesuré : **17 % des
  jonctions accolées, et 15 cartes sur 60 sans aucune**.

Au plateau HeroQuest, un mur est un **trait imprimé entre deux cases**. Les portes,
chez nous, sont **déjà** des arêtes (`{x, y, cote}`) : le mécanisme existe, il n'est
simplement pas utilisé pour les murs.

## 2. Portée mesurée (2026-09-11)

Six fichiers applicatifs lisent la case `'m'`, et ce sont les plus structurants :

| Fichier | Ce qu'il en fait |
|---|---|
| `app/Partie/Grille.php` | `estTraversable()`, `estRoche()`, **`ligneDeVue()`** |
| `app/Partie/MoteurDread.php` | trajets et repositionnement des monstres |
| `app/Partie/AssembleurCarte.php` | la grille **naît entièrement en `'m'`** puis se creuse |
| `app/Partie/EtatGroupe.php` | le **brouillard** s'étend de case en case et s'arrête aux murs |
| `resources/js/components/carte/DungeonGrid.vue` | `TUILES = { m: 'wall', … }` |
| `database/seeders/TuileSeeder.php` | ⚠ **les formes de salles sont des matrices de cases** |

⚠ **Le poste le plus risqué est la LIGNE DE VUE.** Elle échantillonne aujourd'hui
les **cases traversées** par le rayon ; en modèle d'arêtes elle doit tester les
**arêtes franchies**. Ce n'est pas un portage, c'est un autre algorithme — et
c'est lui qui décide si un archer peut tirer. Toute erreur ici se voit en partie,
pas en test.

⚠ **Le catalogue de tuiles devra être ré-autorisé.** Chaque salle seedée décrit sa
forme en cases `m`/`s`. En modèle d'arêtes, la forme devient un rectangle plus un
jeu d'arêtes murées.

## 3. Ce que ça débloque, une fois fait

- Deux salles peuvent **réellement** partager un mur : l'accolement cesse d'être
  plafonné à 17 %, et « par défaut » devient possible.
- Le contour de salle n'est plus un dispositif à part : **le mur EST le contour**,
  et `.dg-room-outline` disparaît.
- La carte gagne des cases : une grille de même taille porte plus de jeu.
- `Grille::porteBloqueEntre()` et le blocage de mur deviennent **le même mécanisme**
  — un seul point de passage pour « cette arête est-elle franchissable ? ».

## 4. Ordre recommandé

1. `Grille` : introduire les arêtes murées et **refaire `ligneDeVue()`** sur les
   arêtes, en gardant l'ancienne implémentation en parallèle pour comparer les deux
   sur des milliers de tirs aléatoires. C'est le seul poste où une régression ne se
   verrait pas autrement.
2. `AssembleurCarte` : générer des arêtes plutôt que creuser des cases.
3. `TuileSeeder` : ré-exprimer les formes.
4. `EtatGroupe` : brouillard sur arêtes.
5. Front : dessiner les murs en traits ; **retirer `.dg-room-outline`**, devenu
   redondant.
6. Alors seulement : lever le plafond de l'accolement.

## 5. À trancher avant de commencer

1. **Une case peut-elle être « pleine roche »** (hors salle, hors couloir) ou tout
   devient-il sol + arêtes ? Le donjon a besoin d'un « dehors ».
2. **Traverser la Pierre** fait marcher DANS la roche. Sans cases de roche, que
   traverse le sort ? C'est sa règle entière qui est en jeu.
3. Le **mobilier adossé au mur** (`adosse_au_mur`) se calcule aujourd'hui contre des
   cases `m` voisines.
4. Faut-il une **migration des cartes en cours** ou accepte-t-on que les quêtes
   déjà générées restent dans l'ancien modèle jusqu'à leur fin ?

## 6. Vérification

- ⚠ **La ligne de vue se valide par COMPARAISON**, pas par quelques cas : rejouer
  des dizaines de milliers de paires (tireur, cible) sur les deux implémentations
  et exiger l'identité, ou la liste explicite des divergences voulues.
- `CouloirsTest` (connectivité), `LigneDeVueTest`, `DeplacementTest`,
  `BrouillardTest`, `SallesPubliquesTest` doivent rester verts.
- Et une **campagne réelle** via la skill `campagne-agents` : c'est la méthode qui a
  trouvé, cette semaine encore, ce que des centaines de tests verts n'avaient pas vu.
