---
name: ajouter-element-de-jeu
description: >-
  Utiliser quand on AJOUTE (ou importe depuis une extension) un élément de jeu :
  personnage/classe, monstre, objet, piège, sort, tuile, mobilier, épreuve,
  gabarit de quête, compétence, condition, mercenaire. Checklist des dépendances
  transverses à NE PAS oublier — carte source, donnée catalogue, support moteur
  de l'effet, IMAGE (+ jumeau webp), SON, payload, rendu front, tests, docs.
  Déclencheurs : « ajoute un monstre/objet/sort/classe… », « intègre
  l'extension X », « nouvel ennemi/item/piège/héros/meuble/épreuve ».
---

# Ajouter un élément de jeu — checklist transverse

But : ajouter du contenu sans rien oublier (surtout **images**, **sons** et
**payload**) et **sans violer le principe fondateur**.

## ⚠ Garde-fou n°1 — le moteur fait foi, l'IA ne fait que rhabiller

Les catalogues sont des **données de référence seedées**. Le moteur n'interprète
qu'un **vocabulaire d'effets FERMÉ** :

- Un élément qui **réutilise un effet existant** = pure donnée (seeder). ✅
- Un élément avec un **effet inédit** = **mécanique d'abord** (vocabulaire →
  lecteur → test en jeu), *puis* la donnée. ❌ Jamais d'effet inventé en donnée
  seule : le moteur ne saurait pas le résoudre, et le joueur lirait une promesse
  jamais tenue. → skill **`ajouter-mecanique-moteur`**

Vocabulaires fermés (`app/Engine/`) : `MotsClesEquipement` · `MotsClesSort` ·
`MotsClesSortDread` · `MotsClesTalent` · `MotsClesEpreuve` · `DureeEffet` ·
`RegainEffet` · `TypeDegat` · `ReactionEffet`.

## ⚠ Garde-fou n°2 — rien n'est inventé, tout est sourcé

Une arme, une armure, une potion, un artefact, un sort ou un bloc de stats de
monstre vient d'une **carte** ou d'un **livret**, et s'inscrit au registre
`config/cartes.php` (testé **dans les deux sens**).
→ skill **`porter-une-carte-officielle`**

## 1. Donnée catalogue (toujours)

| Élément | Seeder | Champs clés |
|---|---|---|
| Classe / héros | `ClasseHerosSeeder` | `nom`, `race`, PV, attributs, dés, déplacement, `tags_equipement`, `objets_autorises` |
| Monstre | `MonstreSeeder` | `nom_base`, `tier`, `boite`, stats, `cout`, `capacites` |
| Objet | `ObjetSeeder` | `nom`, `categorie`, `prix_base` (⚠ la **rareté se déduit du prix**), `emplacement`, `tag_equipement`, `metallique`, `effet` |
| Piège | `PiegeSeeder` | `nom`, `detectable`, `desarmable`, `usage`, `effet` |
| Sort | `SortSeeder` | `element`, `nom`, `type`, `difficulte_parchemin`, `cible`, `resistance`, `effet` |
| Sort de Dread | `SortDreadSeeder` | `nom`, `palier`, `effet` |
| Mobilier | `MobilierSeeder` | emprise, `bloque_mouvement`, `bloque_vue`, `adosse_au_mur`, `difficulte_destruction`, `effet.fouille` |
| Épreuve | `EpreuveSeeder` | attribut, difficulté, `effet`, `exige_placement` |
| Tuile | `TuileSeeder` | `type`, `theme`, `grille` |
| Gabarit de quête | `GabaritQueteSeeder` | `structure` (`objectif`, `objectif_majeur`, `deck_fouille`, `rencontre_finale`), budget |
| Compétence | `CompetenceSeeder` | `categorie`/`colonne`/`rang` (grille 3×3) ou `innee`, `effet.mecanique`, `description` |
| Condition / Forge / Mercenaire | `Condition`/`ForgeAmelioration`/`Mercenaire` Seeder | — |

⚠ **Les seeders écrivent en `updateOrCreate` et ne purgent pas** (une purge
détacherait ce que les héros possèdent déjà). Donc **retirer une ligne du seeder
ne supprime rien en base** : il faut une **migration** — et elle doit emporter
les lignes d'inventaire, sinon l'objet reste dans un sac, ni équipable, ni
vendable, ni affichable. ⚠ `MobilierSeeder` **clé sur `nom`** exprès : la grille
stocke un `mobilier_id`, re-seeder en purgeant orphelinerait chaque meuble d'une
quête en cours, ni fouillable ni bloquant, **sans une seule erreur**.

⚠ **Le butin filtre ce que personne ne peut utiliser** : `RareteButin` pondère
par le niveau du groupe, et le pool retire ce qu'aucun héros **engagé** ne
pourrait porter (`Equipement::tagsAccessiblesAux()`). Un `tag_equipement`
qu'aucune classe ne possède rend la pièce **inatteignable** — un test le refuse.

```bash
docker compose exec app php artisan db:seed --class=MonstreSeeder
```

## 2. 🖼 IMAGE (presque toujours)

- Gabarits dans **`config/images.php`** : `classe` · `monstre` · `objet` ·
  `piege` · `epreuve` · `mobilier` · `levier` · `porte` · `sort` (+ dynamiques
  `boss`/`scene`/`hub`/`portrait`). La commande **itère le catalogue** → une
  ligne neuve est couverte toute seule.
- ⚠ **TYPE inédit** : gabarit + accesseur `BibliothequeImages::url*()` +
  itération dans `GenererImages` + champ dans le payload + rendu `Vignette`.
- ⚠ Un élément **sans table catalogue** (levier, porte) se nomme par un libellé
  fixe, pas par `{id}-{slug}`.

```bash
docker compose exec app php artisan images:generer --type=monstres
./image-tools/webp.sh      # ⚠ OBLIGATOIRE : sans jumeau, l'écran est 30× plus lourd
```
→ skill **`medias-images-et-sons`**

## 3. 🔊 SON (selon le type)

- **Monstre** → `config/barks.php` (profil de voix, `lignes`, `lignes_boss` avec
  `{nom}`) puis `barks:generer`. Résolveur `BanqueBarks`.
- **Temps fort de narration** → `config/narration.php` (`repli`) puis
  `narration:generer`. ⚠ Le vocabulaire est **fermé** :
  `App\Partie\Narration\TempsFort` — une clé produite mais routée nulle part est
  du texte payé et jamais lu.

## 4. Payload serveur

L'élément doit porter son `image_url` là où le front le consomme, via
`BibliothequeImages` : `app/Partie/EtatGroupe.php` (héros, monstres, pièges,
mobilier, épreuves, scène, hub) · `AuthController` (`/moi`) ·
`Marche/PhaseMarche.php` · `ClotureCampagne.php`.
⚠ **Le contrat d'abord** : `docs/contrat-api.md`.

## 5. Front

- Rendu image-ou-icône par **`Vignette`** (repli Material Symbols).
- Icône de repli à déclarer dans `resources/js/store/game.js` (`CLASSES`,
  `CATEGORIE_ICONES`, `ELEMENTS`, `CONDITIONS`…).
- Élément posé sur la carte → **silhouette** dans
  `resources/js/components/carte/symboles.js` (lu par le rendu **et** la légende).
  → skills **`front-manette-et-table`**, **`travailler-la-carte`**

## 6. Tests

- Effet inédit → test **en jeu** (`tests/Feature/Partie/`), pas seulement catalogue.
- Registres deux sens : `CartesSourcesTest` · `BestiaireSourceTest` ·
  `SortsDreadSourcesTest` · `GrilleTalentsTest` · `SymbolesCarteTest` ·
  `EpreuvesCatalogueTest`.
- ⚠ Une assertion `toBe(...)` sur un payload doit **ignorer `image_url`**
  (`collect($p)->except('image_url')`), sinon elle casse dès qu'un asset existe.
→ skill **`outillage-dev-et-tests`** pour la commande

## 7. Docs

- `docs/contrat-api.md` si la forme d'un payload change (**source de vérité**).
- `reference/12_schema_donnees.md` si le schéma BD change.
- `reference/16_armurerie.md` / `18_extensions.md` si la source est une carte.

## Récap « definition of done »
- [ ] Source citée (carte/livret) et inscrite à `config/cartes.php` si applicable
- [ ] Donnée seedée avec un effet du vocabulaire fermé (sinon mécanique **d'abord**)
- [ ] Migration si un élément **sort** du catalogue (inventaires compris)
- [ ] Image générée **+ jumeau webp**, ou type câblé de bout en bout
- [ ] Son si monstre (barks) / temps fort déclaré dans `TempsFort`
- [ ] Payload expose `image_url`, contrat d'API à jour
- [ ] Front affiche l'élément (Vignette + icône, silhouette si sur la carte)
- [ ] Tests verts, registres deux sens compris
- [ ] Jouable **sans clé API** (repli icône / texte / menu moteur) préservé
