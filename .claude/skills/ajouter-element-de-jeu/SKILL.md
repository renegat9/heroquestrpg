---
name: ajouter-element-de-jeu
description: >-
  Utiliser quand on AJOUTE (ou importe depuis une extension) un élément de jeu :
  personnage/classe, monstre, objet, piège, sort, tuile, élément de carte de
  quête, gabarit de quête, compétence, condition. Checklist des dépendances
  transverses à NE PAS oublier — donnée catalogue, support moteur de l'effet,
  IMAGE, SON, rendu front, tests, docs — pour ce projet HeroQuest (Laravel +
  Vue). Déclencheurs : « ajoute un monstre/objet/sort/classe… », « intègre
  l'extension X », « nouvel ennemi/item/piège/héros ».
---

# Ajouter un élément de jeu — checklist transverse

But : ajouter du contenu sans rien oublier (surtout **images** et **sons**) et
**sans violer le principe fondateur**.

## ⚠ Garde-fou n°1 — le moteur fait foi, l'IA ne fait que rhabiller

Les catalogues sont des **données de référence seedées**. Le moteur n'interprète
qu'un **vocabulaire d'effets FIXE et codé en dur**. Donc :

- Un élément qui **réutilise un effet existant** = pure donnée (seeder). ✅
- Un élément avec un **effet/capacité inédit** = **ajouter d'abord la branche
  moteur + un test**, *puis* la donnée. ❌ Jamais d'effet inventé en donnée seule
  (le moteur ne saurait pas le résoudre).

Vocabulaire d'effets actuel (vérifier/étendre ici) :
- **Capacités de monstre** (`monstres.capacites`, JSON) → `app/Partie/MoteurDread.php`
  (`aCapacite()` : `regeneration`, `charge`, …).
- **Effets d'objet/sort** (`objets.effet`, `sorts.effet`, JSON) → `app/Partie/ResolveurTour.php`
  & `app/Partie/MoteurSorts.php` & `app/Partie/Marche/CapaciteSac.php`
  (`soin_pv_body`, `franchit_mur`, `bonus_des_attaque`, `bonus_des_defense`,
  `deplacement_multiplie`, `bonus_capacite_sac`, …).
- **Effets de piège** (`pieges.effet`, JSON) → `app/Partie/MoteurPieges.php`
  (`franchissable`, …).

Si l'effet voulu n'y est pas → coder la branche dans `app/Engine`/`app/Partie`
+ test Pest (`tests/Unit/Engine` ou `tests/Feature/Partie`) **avant** de seeder.

## 1. Donnée catalogue (toujours)

Éditer le bon seeder dans `database/seeders/` :

| Élément | Seeder | Champs clés |
|---|---|---|
| Classe / héros | `ClasseHerosSeeder` | `nom` (enum), PV, attributs, dés, déplacement, bonus_sac |
| Monstre | `MonstreSeeder` | `nom_base`, `tier` (base/sous_boss/boss), stats, `cout`, `capacites` |
| Objet | `ObjetSeeder` | `nom`, `categorie`, `rarete`, `prix_base`, `emplacement`, `effet` |
| Piège | `PiegeSeeder` | `nom`, `detectable`, `desarmable`, `usage`, `effet` |
| Sort | `SortSeeder` | `element`, `nom`, `type`, `difficulte_parchemin`, `effet` |
| Tuile (carte) | `TuileSeeder` | `type`, `theme`, `grille` (cases) |
| Gabarit de quête | `GabaritQueteSeeder` | structure, budget, butin |
| Compétence / condition / Dread / forge | `Competence`/`Condition`/`SortDread`/`ForgeAmelioration` Seeder | — |

> Un nouvel **objet = parchemin** d'un sort doit rester cohérent avec `SortSeeder`
> (un parchemin par sort). Un **monstre** porte un `tier` qui décide boss vs base.

Re-seed après édition (dev) :
```bash
docker compose exec app php artisan migrate:fresh --seed   # ⚠ efface les parties
# ou cibler : docker compose exec app php artisan db:seed --class=MonstreSeeder
```

## 2. 🖼 IMAGE (presque toujours)

- Le gabarit de prompt existe déjà par type dans **`config/images.php`**
  (`classe`, `monstre`, `objet`, `piege`, `sort` + dynamiques `boss/scene/hub/portrait`).
  La commande **itère le catalogue** → un nouveau row est couvert automatiquement.
- Générer : `docker compose exec app php artisan images:generer` (résumable ;
  `--type=monstres|objets|pieges|sorts|classes|tous`, `--force`). Asset →
  `public/images/catalogue/{type}/{id}-{slug}.png` (gitignored, régénérable).
- **Si c'est un TYPE d'élément inédit** (pas couvert par les types ci-dessus) :
  - ajouter un gabarit dans `config/images.php` ;
  - ajouter `relatif*`/`url*` dans `app/Partie/Images/BibliothequeImages.php`
    et l'itération dans `app/Console/Commands/GenererImages.php` ;
  - exposer `image_url` dans le bon payload (voir §4) ;
  - afficher via le composant `resources/js/components/ui/Vignette.vue`.

## 3. 🔊 SON (selon le type)

- **Monstre** → voix : dans **`config/barks.php`**, mapper l'archétype au profil
  de voix, et fournir `lignes` (attaque/touché/raté/mort) ; si **boss/sous-boss**,
  `lignes_boss` (placeholder `{nom}`). Puis :
  `docker compose exec app php artisan barks:generer`. Résolveur :
  `app/Partie/Audio/BanqueBarks.php`. Sans clé/asset → Web Speech lit le texte.
- **Nouvelle narration** (beat de quête) → `config/narration.php` (`repli`/`lancement`)
  puis `php artisan narration:generer`. Résolveur : `BibliothequeNarration`.
- **Ambiance** → scènes dans `audio-tools/lyria-ambiance.mjs` (voir `audio-tools/README.md`).

## 4. Payload serveur (`image_url` / `portrait_url`)

L'élément doit porter son URL d'image dans le payload que le front consomme —
via `app(\App\Partie\Images\BibliothequeImages::class)` (déjà branché pour les
types existants) :

- `app/Partie/EtatGroupe.php` — héros (`urlHeros`), monstres (`urlMonstre`),
  pièges (`urlPiege`), scène (`dyn quete`), hub (`dyn hub`).
- `app/Http/Controllers/Api/AuthController.php` (`/moi`) — `portrait_url` + sorts (`urlSort`).
- `app/Partie/Marche/PhaseMarche.php` — objets (`urlObjet`).
- `app/Partie/ClotureCampagne.php` — butin (`urlObjet`).

## 5. Front (rendu)

- Le rendu image-ou-icône passe par **`Vignette`** (repli sur l'icône Material
  Symbols si pas d'image). Vérifier l'onglet/écran concerné :
  manette (bandeau, `FicheTab`, `SacTab`, `SpellsTab`), table (`GroupPanel`,
  `DungeonMap`), `MarketTab`, `ClotureCampagneView`, `JoueurView` (roster).
- Icône de repli : maps dans `resources/js/store/game.js` (`CLASSES`,
  `CATEGORIE_ICONES`, `ELEMENTS`). Ajouter l'icône si nouveau type.
- Rebuild front : `docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -w /app node:20-alpine sh -c "npm run build"`.

## 6. Tests

- Effet moteur inédit → test dans `tests/Unit/Engine` (pur) ou `tests/Feature/Partie`.
- Les assertions de payload en `toBe(...)` doivent **ignorer `image_url`**
  (sinon elles cassent dès qu'un asset existe) — cf. le pattern dans
  `tests/Feature/Partie/PiegesTest.php` (`->map(fn ($p) => collect($p)->except('image_url')->all())`).
- Lancer la suite :
```bash
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -w /app \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/app/database/database.sqlite \
  composer:2 ./vendor/bin/pest
```

## 7. Docs

- `docs/contrat-api.md` si la forme d'un payload change (**source de vérité**).
- `reference/12_schema_donnees.md` si le schéma BD change.

## Récap « definition of done »
- [ ] Donnée seedée (effet dans le vocabulaire moteur, sinon branche moteur + test)
- [ ] Image générée (`images:generer`) **ou** type câblé (gabarit + résolveur + payload + Vignette)
- [ ] Son si monstre (barks) / narration (selon le cas)
- [ ] Payload expose `image_url`/`portrait_url`
- [ ] Front affiche l'élément (Vignette + icône de repli)
- [ ] Tests verts (payload `toBe` sans `image_url`)
- [ ] Docs à jour si schéma/contrat change
- [ ] Jouable **sans clé** (repli icône/texte/menu moteur) préservé
