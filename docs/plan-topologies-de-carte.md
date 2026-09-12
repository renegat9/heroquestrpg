# Plan — Varier la topologie des cartes de quête

> ⚠ **TODO NON PLANIFIÉ, daté du 2026-09-12.** René : « enregistre le fichier comme
> un todo, on ne le fera pas pour le moment ». Rien n'est implémenté, rien n'est
> décidé. Ce document est une **analyse de faisabilité** : il dit ce que ça coûterait,
> ce que ça casserait, et dans quel ordre le faire le jour où on s'y met.
>
> Comme les autres `docs/plan-*.md`, il n'est **pas tenu à jour**. Les règles en
> vigueur vivent dans `docs/regles/` — en particulier `carte-donjon.md`, dont il
> dépend étroitement.

Demande d'origine : « j'aimerais des variétés de génération de carte de quête », avec
quatre pistes nommées — enfilade sans couloir, étoile à leviers, anneau autour d'une
salle centrale, garder l'existant — et une invitation ouverte à en proposer d'autres.

---

## 1. Contexte — pourquoi la question se pose

Mesuré le 2026-09-12 (`docs/regles/carte-donjon.md`, §« Ce qui varie ») : les **plans**
varient réellement — 10 positions d'arc d'un vrai groupe donnent 10 signatures de
tuiles distinctes, 5 à 8 salles, 1 à 2 portes secrètes. Ce qui ne varie **jamais** :

- le **squelette** — arbre couvrant compact + boucles + couloirs, salle 0 au départ,
  dernière salle à la fin ;
- l'**objectif** — `choisirGabarit()` fait `orderBy('id')->first()` sur trois gabarits,
  un par type de jalon.

Autrement dit : le donjon change de *forme* mais jamais de *nature*. C'est cette
seconde couche que la demande vise.

---

## 2. La couture existe déjà, et elle est propre

`AssembleurCarte::assembler()` (ligne 131) appelle **une seule fois** :

```php
['grille' => $positionsGrille, 'aretes' => $aretes, 'passage_secret' => $passageSecret]
    = $this->construireArbre($n, $suivant, $chancePassageSecret);   // ligne 151
```

`construireArbre()` (ligne 440) rend exactement deux choses :
`positions[i] = [gx, gy]` (coordonnées sur une grille de slots 4-connexe) et
`aretes = [{parent, enfant, direction}]`. **Tout le reste de l'assembleur — slots
uniformes, pose des tuiles, couloirs, portes, mobilier, pièges, épreuves, terrain,
spawns — ne sait rien de la topologie** : il consomme `positions` et `aretes` sans
jamais supposer qu'ils forment un arbre.

> Une topologie, c'est donc précisément **une façon de produire `positions` +
> `aretes`**. Le point d'insertion est une ligne, pas une refonte.

**Vocabulaire fermé** attendu (règle dure du dépôt : vocabulaire → lecteur → test en
jeu → donnée), testé dans les deux sens comme `config/cartes.php` :
`arbre` · `enfilade` · `etoile` · `anneau`. Le gabarit le déclare dans
`structure.topologie` ; absent = `arbre`, donc les trois gabarits actuels ne bougent pas.

---

## 3. Deux préalables, dont un est une mécanique moteur

### 3.1 `choisirGabarit()` ne tire rien — bloquant

`DemarreurQuete::choisirGabarit()` (ligne 455) :

```php
return GabaritQuete::query()->where('type_jalon', $typeJalon)->orderBy('id')->first()
```

Semer un second gabarit `normale` **ne changerait rien** : il ne serait jamais choisi.
C'est la classe de défaut exacte du levier qui n'a jamais été placé — tout le mécanisme
écrit et testé, rien qui l'atteigne. À corriger **avant** d'ajouter la moindre donnée,
avec la **même graine que la carte** (`crc32($identifiant.':'.$positionArc)`) pour
rester reproductible.

Coût : ~10 lignes + un test.

### 3.2 Le verrou « tous les leviers » n'existe pas — c'est une mécanique

`MoteurPortes` connaît exactement trois verrous (docblock, ligne 17) : `cle`,
`monstres_vaincus`, `levier` — **un** levier, une porte. L'étoile demande « les K
leviers, puis la porte ». Il faut donc :

| Étape | Fichier | Nature |
|---|---|---|
| Entrée de vocabulaire `leviers` (pluriel) | `MoteurPortes` | vocabulaire fermé |
| Persistance de « ce levier a été actionné » | `cartes.grille.leviers[i]` | même patron que `mobilier[i].detruit` |
| Lecteur : ouvrir quand **tous** sont actionnés | `MoteurPortes` | lecteur |
| Publication de l'avancement (« 3 leviers sur 4 ») | `EtatGroupe` + `JournalCombat` | ⚠ sans ça la mécanique est **injouable** (règle dure) |
| Test **en jeu** | `tests/Feature/Partie/` | pas un test catalogue |

⚠ **Clé sur `levier_id`, jamais sur l'index.** C'est l'indexation positionnelle de
`mobilier[i].detruit` qui interdit aujourd'hui toute migration rétroactive du mobilier.
`levier_id` est déjà une chaîne unique : s'en servir.

Coût : la mécanique complète, ~1 journée avec les tests et la publication.

---

## 4. Topologie par topologie

### 4.1 `arbre` — l'existant, inchangé

Rien à faire : le comportement actuel devient une entrée nommée du vocabulaire.

### 4.2 `enfilade` — départ à un bout, fin à l'autre, sans couloir

**Faisable, effort moyen.** `positions` = une chaîne, coudée pour ne pas être une simple
ligne droite. `aretes` = les `n-1` liens consécutifs.

Le « sans couloir » repose sur `accolerSallesMitoyennes()` (ligne 599), qui fait déjà
exactement ça — mais **seulement sur les feuilles** :

```php
if ($enfant === 0 || ($degre[$enfant] ?? 0) !== 1) { continue; }
```

Dans une chaîne, toute salle intermédiaire a un degré 2 : **une seule salle serait
accolée**. Il faut une **passe en cascade** — accoler la salle *i* à *i-1* dans l'ordre
de la chaîne, chaque salle n'ayant qu'un prédécesseur. Le docblock explique pourquoi la
restriction « feuilles » existe (« décaler une salle qui a plusieurs arêtes désaligne
les couloirs de ses AUTRES jonctions ») — mais **dans une enfilade il n'y a plus
d'autres jonctions à désaligner**, puisqu'il n'y a plus de couloir. La restriction ne
s'applique pas à ce cas ; elle reste entière pour `arbre`.

⚠ **Deux conséquences à traiter, pas à découvrir en jeu :**

- **Aucune porte secrète possible.** `liaisonsSupplementaires()` ne relie que des salles
  **voisines sur la grille et non déjà reliées**. Dans une enfilade droite, les seules
  voisines sont les consécutives, déjà reliées → **zéro boucle, zéro secrète**. Or
  `CouloirsTest` verrouille « au moins une porte secrète par carte ». Deux issues :
  faire **serpenter** la chaîne pour qu'elle se replie et crée des adjacences (ce que
  `candidatLePlusCompact()` fait déjà pour l'arbre), ou déclarer que cette topologie n'en
  a pas et rendre le test dépendant de la topologie. **Le serpent est meilleur** : il
  garde la fouille utile.
- **`secretiserUneAreteDArbre()` choisit une FEUILLE.** Dans une enfilade, les deux
  feuilles sont la salle 0 (déjà exclue) et **la salle finale**. Cacher l'unique accès à
  la fin derrière une secrète non trouvée rend la quête infaisable. En `arbre` ce cas
  existe et a été accepté à son taux mesuré (4 à 10 fois sur 60) ; en enfilade il serait
  **systématique**. À exclure explicitement.

### 4.3 `anneau` — couronne extérieure, grande salle centrale à la fin

**Faisable, effort moyen.** La grille de slots étant 4-connexe, un anneau propre tient
sur un carré 3×3 privé de son centre :
`(0,0) (1,0) (2,0) (2,1) (2,2) (1,2) (0,2) (0,1)` — chaque cellule est 4-adjacente à la
suivante, et le centre `(1,1)` est adjacent aux quatre milieux de côté. Le cycle et
l'accès central sont directement représentables.

⚠ **Trois points à traiter :**

- **La grande tuile doit aller au CENTRE, pas au départ.** `plusGrandeTuileEnTete()`
  (ligne 2211) promeut la plus grande tuile en salle 0 pour une raison mesurée (§2.12 :
  le groupe y démarre empilé). Pour l'anneau, la salle centrale est la salle de fin et
  doit être la plus grande. Cette fonction devient **dépendante de la topologie**.
- **La salle finale doit rester le dernier index.** `spawnsMonstres()` (« la DERNIÈRE,
  pour que `spawn_monstres[0]` y atterrisse ») et `DeckFouille::salleDuBoss()`
  (`count($salles) - 1`) en dépendent tous les deux. Il suffit d'ordonner : anneau
  d'abord, centre en dernier. **Aucun de ces deux lecteurs ne bouge.**
- **« La salle la plus profonde » perd son sens.** `DeckFouille::salleLaPlusProfonde()`
  fait un BFS depuis la salle 0 : sur un anneau, toutes les salles sont à ± la même
  profondeur et le choix devient arbitraire. Sans importance pour une quête à boss
  (l'artefact va dans la salle du boss depuis le 2026-09-12), **mais à trancher** pour
  `atteindre_et_recuperer`.

L'accès au centre est naturellement une **porte secrète** ou un **levier** — les deux
existent déjà. Le centre étant adjacent à 4 salles de l'anneau, il reste **3 boucles
candidates** après la liaison principale : la contrainte « au moins une secrète » tient
toute seule, contrairement à l'enfilade.

### 4.4 `etoile` — moyeu central, une section par branche, K leviers pour la fin

**Faisable, mais la plus chère** — la seule qui exige la mécanique §3.2.

`positions` : centre `(0,0)` = salle 0 (départ), K branches de 1 à 2 salles en N/E/S/W,
salle finale au-delà d'une branche ou en second anneau.

**La bonne nouvelle** : `placerLeviers()` (ligne 1160) fait déjà **exactement** la
vérification difficile, et son docblock nomme l'invariant dur : « le levier ne doit
JAMAIS se trouver derrière la porte qu'il verrouille », vérifié par un **parcours réel**
(`Grille::casesAtteignables()` sur une grille où toutes les portes sont ouvertes sauf
celle qu'on verrouille) — et **les verrous déjà acceptés sont traités comme bloquants
pour les suivants**, ce qui est précisément ce qu'il faut pour K leviers devant tous
être atteignables sans la porte finale. La logique n'est pas à réécrire, seulement à
généraliser de 1 vers K.

⚠ **Point de conception à trancher** : que se passe-t-il si le groupe fouille une
branche sans actionner son levier ? Si la porte finale n'ouvre qu'à K/K, une branche
oubliée **bloque la quête**, et rien aujourd'hui ne dit *où* sont les leviers manquants.
La règle dure « un effet que rien n'annonce est injouable » impose l'affichage de
l'avancement (« 3 leviers sur 4 » — prévu au §3.2), mais pas du *où*. Piste : la carte
de la table montre déjà les leviers découverts, et un levier de **couloir** y est
toujours visible (arbitrage existant), contrairement à un levier de salle non découverte.

### 4.5 Pistes supplémentaires — « tout autre variation qui peut être le fun »

Par ordre de rapport intérêt / coût, **aucune n'exigeant de mécanique neuve** :

| Topologie | Idée | Ce qu'elle change au jeu | Coût |
|---|---|---|---|
| `deux_ailes` | Deux sous-arbres reliés par **une seule** salle-pont ; la finale au bout de l'aile opposée au départ | Le groupe doit choisir une aile, ou se séparer — la première vraie décision stratégique de plan | faible (deux appels à l'arbre existant + un lien) |
| `gantelet` | Un **long couloir** central, salles en épis de part et d'autre, finale au bout | Le couloir devient un espace de jeu (tirs en enfilade, retraite), pas un tuyau | faible |
| `spirale` | Enfilade qui s'enroule vers le centre, finale au cœur | Sensation de descente ; crée ses propres adjacences donc ses secrètes | moyen (variante d'`enfilade`) |
| `cul_de_sac` | Arbre normal, mais la finale **n'est pas** la plus profonde : une fausse piste longue et payante mène ailleurs | Récompense la fouille sans punir | faible |

⚠ `labyrinthe` (grille dense à boucles multiples) est **écarté** : sur des slots
uniformes il produit surtout des couloirs, et le brouillard rend un labyrinthe illisible
sur une manette à 38 px par case.

---

## 5. Ce que ça casse ailleurs — inventaire honnête

| Ce qui suppose un arbre | Fichier | Verdict |
|---|---|---|
| « au moins une porte secrète par carte » | `CouloirsTest` | ⚠ **tombe en enfilade droite** — à rendre dépendant de la topologie |
| `secretiserUneAreteDArbre()` choisit une feuille | `AssembleurCarte:777` | ⚠ en enfilade, la feuille **est** la salle finale — à exclure |
| `liaisonsSupplementaires()` (boucles) | `AssembleurCarte:849` | dépend d'adjacences non reliées : riche en anneau, **nulle** en enfilade droite |
| `accolerSallesMitoyennes()` feuilles seulement | `AssembleurCarte:599` | à généraliser en cascade **pour l'enfilade seule** |
| `plusGrandeTuileEnTete()` → salle 0 | `AssembleurCarte:2211` | devient dépendant de la topologie (anneau : la grande va au centre) |
| « la dernière salle est la finale » | `spawnsMonstres()`, `DeckFouille::salleDuBoss()` | **tient partout** si on ordonne les positions — ne rien toucher |
| « la salle la plus profonde » | `DeckFouille::salleLaPlusProfonde()` | perd son sens sur un anneau — à trancher pour `atteindre_et_recuperer` |
| Slots uniformes dimensionnés sur la plus grande tuile | `AssembleurCarte:154` | **tient partout**, aucune modification |
| Cartes déjà générées | `cartes.grille` en base | **aucune migration** : la carte est figée au démarrage de la quête, les quêtes en cours gardent la leur |

---

## 6. Découpe recommandée

Trois lots indépendants, chacun livrable et testable seul :

1. **Le socle** — vocabulaire `Topologie` (4 entrées), branchement à la ligne 151,
   `structure.topologie` dans le gabarit, `choisirGabarit()` qui tire, test de registre
   dans les deux sens. `arbre` seul implémenté : **aucun changement visible en jeu**, et
   c'est le but — le socle se prouve par l'absence de régression.
2. **`anneau` puis `enfilade`** — aucune mécanique neuve. L'anneau d'abord : il ne casse
   aucun invariant de secrète, donc il valide le socle sans le brouiller avec la question
   des boucles.
3. **La mécanique `leviers` (pluriel) puis `etoile`** — la seule qui touche
   `MoteurPortes`, `EtatGroupe`, `JournalCombat` et la manette.

Les pistes du §4.5 viennent après, chacune étant alors ~une centaine de lignes dans le
vocabulaire existant.

---

## 7. Vérification, le jour où on s'y met

- **Registre dans les deux sens** : toute entrée de `Topologie` a un constructeur, tout
  constructeur est déclaré — et tout `structure.topologie` semé existe au vocabulaire.
- **Invariants de génération, par topologie** : étendre `CouloirsTest` à une boucle sur
  les topologies (connexité depuis la salle 0 sans ouvrir de secrète, une seule porte par
  bord de salle, seuils d'une case, aucune salle noyée).
- **Jouabilité**, la mesure qui a servi au §2.12 ter : sur 60 cartes par topologie, toute
  salle laisse **≥ 4 cases** au groupe après mobilier et places de monstre.
- **En jeu, sans clé API** : une quête complète par topologie via `ZeroLlmEnQueteTest`,
  jusqu'à `objectifAccompli()` — le seul test qui prouve qu'une topologie est
  *finissable*.
- **Rendu aux deux échelles** : table (`1fr`) et manette (38 px), en particulier
  l'enfilade sans couloir (les salles se touchent : le contour doit rester lisible) et
  l'anneau (la salle centrale ne doit pas se confondre avec le brouillard).
- ⚠ **Jamais sur la vraie base** : tests sur copie sqlite jetable
  (`cp database/database.sqlite /tmp/x.sqlite`), jamais de purge de `groupes` /
  `personnages` / `joueurs`.

---

## 8. Recommandation, en une phrase

Le socle (§6.1) vaudrait d'être fait **même sans aucune topologie neuve**, parce qu'il
corrige `choisirGabarit()` — un défaut réel qui rendrait muette toute donnée ajoutée
ensuite. Puis `anneau` et `enfilade`, qui donnent deux sensations de donjon vraiment
différentes sans toucher au moteur. `etoile` en dernier : la plus belle des quatre, et
la seule qui demande d'ouvrir `MoteurPortes`.
