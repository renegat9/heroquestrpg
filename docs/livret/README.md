# Livret de jeu — comment il se fabrique

Le livret existe en **deux formes tirées d'une seule source** : une page web
(`public/livret/index.html`, celle vers laquelle pointe l'accueil) et un PDF A4 de 42 pages
(`public/livret/HeroQuest-RPG-Livret-de-jeu.pdf`, à imprimer). Le corps du document est
généré **une seule fois** ; seules la feuille de style et les chemins d'images diffèrent —
les deux médias n'ayant presque aucune règle en commun, chacun a sa feuille autonome
(`CSS` pour l'impression, `CSS_WEB` pour l'écran) plutôt qu'une pile de surcharges.

Rien de tout cela n'est écrit à la main : il est
**généré**, et c'est ce qui l'empêche de mentir. Les tableaux — stats des 12 classes,
41 créatures, 105 objets, 31 sorts, pièges, mobilier, terrain, grilles de talents —
sont extraits de la **base réelle** ; les copies d'écran sont prises sur la **vraie
stack**, avec une campagne jouée pour de bon.

Les textes de règle, eux, sont écrits dans `generer.py` et adossés à `reference/01`
à `05` et `10`. Quand une règle change, c'est là qu'il faut la reprendre.

## Régénérer

```bash
# 0. les vignettes d'impression, tirées de public/images (incrémental)
#    ⚠ à refaire après CHAQUE images:generer : sans elles, une illustration neuve
#    n'entre jamais dans le livret, et rien ne le signale
./docs/livret/vignettes.sh

# 1. le catalogue, depuis la base
docker compose exec -T app php artisan tinker --execute="
  \$d = [];
  foreach (['classes_heros','monstres','objets','sorts','pieges','mobiliers','terrains','epreuves','competences'] as \$t) {
    \$d[\$t] = \Illuminate\Support\Facades\Schema::hasTable(\$t) ? \DB::table(\$t)->get() : [];
  }
  file_put_contents('/tmp/catalogue.json', json_encode(\$d, JSON_UNESCAPED_UNICODE));"
docker compose exec -T app cat /tmp/catalogue.json > /tmp/catalogue.json

# 2. la version WEB (public/livret/) + les sources d'impression (docs/livret/)
#    — une seule commande produit les deux, et recopie images et captures
python3 docs/livret/generer.py /tmp/catalogue.json

# 3. le PDF (deux passes + fusion, voir plus bas)
docker run --rm --network host -v "$PWD:/work" -w /work \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  mcr.microsoft.com/playwright:v1.48.0-jammy \
  bash -c 'npm i playwright@1.48.0 --no-save --no-audit --no-fund --silent \
           && node docs/livret/rendre-pdf.mjs'
mkdir -p public/livret
docker run --rm -v "$PWD:/w" -w /w -e HUID="$(id -u)" -e HGID="$(id -g)" alpine:3.20 sh -c '
  apk add --no-cache poppler-utils su-exec >/dev/null && su-exec "$HUID:$HGID" sh -c "
    pdfunite docs/livret/.couverture.pdf docs/livret/.interieur.pdf \
             public/livret/HeroQuest-RPG-Livret-de-jeu.pdf
    rm -f docs/livret/.couverture.pdf docs/livret/.interieur.pdf"'
```

Tout atterrit dans **`public/livret/`** — `index.html`, `img/`, `captures/`,
`manifest.json` et le PDF — d'où nginx le sert. La carte « Livret de jeu » de l'accueil
pointe sur `/livret/`, et la page web offre le PDF dans son bandeau collant.

⚠ Cette carte **ne s'affiche que si le livret existe** : un lien mort sur la page
d'accueil serait exactement la promesse non tenue que `CLAUDE.md` interdit. Sur une
installation neuve, il faut donc générer le livret une fois pour que l'entrée apparaisse.

## Refaire les captures

```bash
# écrans publics + guide (aucune partie nécessaire)
docker run --rm --network host -v "$PWD:/work" -w /work \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  mcr.microsoft.com/playwright:v1.48.0-jammy \
  bash -c 'npm i playwright@1.48.0 --no-save --no-audit --no-fund --silent \
           && node browser-shots/livret.mjs'

# écrans de jeu : monter une campagne SANS marquer personne prêt
LONGUEUR=courte ./browser-shots/campagne/preparer-livret.sh "Titre" "thème" \
  barbare:Grom nain:Borin elfe:Sylvaine magicien:Aldric
node browser-shots/livret-jeu.mjs <code> <identifiant> hub      # marché, sac, fiche, hub
#   … marquer prêt, jouer quelques tours (browser-shots/campagne/boucle.sh) …
node browser-shots/livret-jeu.mjs <code> <identifiant> quete    # carte, menu, déplacement
./browser-shots/campagne/nettoyer.sh                            # ⚠ toujours finir par là

# scènes de table (les popups) : il faut une quête DÉMARRÉE, donc preparer.sh et non -livret
LONGUEUR=tres_courte ./browser-shots/campagne/preparer.sh "Titre" "thème" \
  barbare:Grom nain:Borin elfe:Sylvaine magicien:Aldric
node browser-shots/livret-scenes.mjs <code>      # (conteneur Playwright) AVANT le déclencheur
docker cp browser-shots/livret-scenes.php heroquestrpg-app-1:/tmp/
docker compose exec -T -e CODE=<code> app php artisan tinker --execute="require '/tmp/livret-scenes.php';"
./browser-shots/campagne/nettoyer.sh
```

Les scènes passent par le **vrai** constructeur `SceneDeTable` et le vrai écran de table ;
seul le déclenchement est provoqué — attendre qu'un tour sorte trois crânes au bon moment
coûterait une heure de dés pour la même image. Le déclencheur pose donc les PV à la main
pour que les chiffres affichés restent cohérents, et choisit des créatures **illustrées** et
ordinaires : la première série avait pris « la plus robuste », c'est-à-dire le maître de la
quête, sans image. `TRACE=1` fait lister au script de capture chaque titre lu à l'écran.

⚠ Une seule vue **plein écran** (`70-scene-attaque`) pour le contexte ; les autres sont
**recadrées** sur la carte de scène. Réduit à une colonne A4, un écran de 1600 px ramène le
texte d'une scène à 4 pt.

Les captures servies vivent en `.webp` redimensionnés dans `browser-shots/livret/web/`
(1500 px pour un écran de table, 760 pour une manette, 900 pour une carte recadrée) :

```bash
docker run --rm -v "$PWD:/w" -w /w alpine:3.20 sh -c 'apk add --no-cache libwebp-tools >/dev/null
  cwebp -quiet -q 82 -resize 900 0 browser-shots/livret/71-scene-jet.png -o browser-shots/livret/web/71-scene-jet.webp'
```

## Pièges déjà payés

- **Le PDF faisait 104 Mo.** Chromium embarque le **bitmap décodé**, pas le fichier : les
  illustrations 1024×1024 de `public/images` pèsent autant qu'un poster. `generer.py` lit
  donc `docs/livret/img/`, des copies réduites à la taille d'impression (200 px pour une
  vignette de tableau, 420 px pour un portrait de classe). Résultat : 16 Mo.
- **La couverture est rendue à part.** L'option `margin` de `page.pdf()` **écrase**
  `@page :first { margin: 0 }`, et le pied de page de Chromium s'imprime sur **toutes** les
  pages sans exception. Deux passes, puis `pdfunite`.
- **Une capture de manette fait 412×915** : sans plafond de hauteur, elle occupe une page
  entière à elle seule. `fig()` déduit la classe `tel` du nom du fichier.
- **`try_files` renvoyait la SPA en 200 pour un PDF manquant.** Le garde-fou de l'accueil
  sondait le fichier par `HEAD` et lisait un 200 — donc il affichait la carte quoi qu'il
  arrive, et le lien mort était exactement ce qu'il devait empêcher. Mesuré en déplaçant le
  fichier, pas déduit. Deux correctifs : `location /livret/ { try_files $uri =404; }` dans
  `docker/nginx/default.conf` (le bon niveau), et un contrôle du **content-type** côté écran
  (la ceinture, pour que l'accueil ne dépende pas d'une conf qu'il ne contrôle pas).
  ⚠ Éditer `default.conf` **casse son bind mount** : `docker compose restart web` échoue,
  il faut `docker compose up -d --force-recreate web`.
- **Et le même piège revient intact quand la cible devient du HTML.** Sonder
  `/livret/index.html` ne discrimine rien : la SPA de repli répond 200 **et** `text/html`,
  exactement comme la vraie page. Le contrôle de content-type qui sauvait le cas PDF ne
  sert donc plus à rien ici. D'où `manifest.json` : un JSON ne peut pas être confondu avec
  la SPA, et il porte au passage la date de génération et la liste des chapitres. ⚠ Il a
  aussi fallu `index index.html` dans le bloc nginx — la directive `index` du serveur ne
  nomme que `index.php`, donc `/livret/` cherchait un `index.php` inexistant.
- **Le lien « Sommaire » ne menait nulle part**, signalé par René. Le gabarit à remplacer
  et l'ancre étaient le **même** `id="somm"` : la substitution du sommaire emportait donc
  la cible du lien. Rien ne cassait, le clic ne faisait simplement rien. Deux rôles, deux
  jetons — le marqueur est devenu un commentaire (`<!--GABARIT-SOMMAIRE-->`, avec une
  assertion s'il disparaît) et l'ancre vit sur la `<section>`.
- **Et les ancres de chapitre tombaient 600 px trop bas.** Une image `loading="lazy"` sans
  dimensions n'occupe **aucune place** tant qu'elle n'est pas chargée : on saute sur
  l'ancre, les captures au-dessus arrivent ensuite, et le titre visé descend. Le défaut
  grandit avec la position dans le document, donc il est invisible sur les premiers
  chapitres — d'où un test qui les parcourt **tous les seize**, pas un échantillon.
  `fig()` lit maintenant l'IHDR du PNG d'origine et pose `width`/`height`.
  ⚠ Ces attributs sont des **indications de présentation** : sans `height:auto` en CSS,
  l'impression aurait pris la hauteur intrinsèque (1800 px). La règle devait donc partir
  dans **les deux** feuilles, pas seulement celle de l'écran — corriger l'écran seul aurait
  cassé le PDF en silence.
  ⚠ **Et il est revenu par une règle neuve** (scènes de table, 2026-09-16) : `width:auto`
  sur `figure.scene img` annulait les attributs, et les cartes plus bas dans la page
  mesuraient **2 px** avant chargement. L'écran les pose en `width:100%` plafonné ; seule
  l'impression garde `auto`, parce que le plafond de hauteur exige que la largeur suive le
  ratio, et qu'elle charge tout avant de rendre.

- **Les vignettes n'avaient pas de recette.** Elles avaient été tirées une fois, à la main,
  et tout ce qui a été illustré ensuite restait hors du livret : 21 objets manquaient déjà
  quand les 26 artefacts des cartes officielles ont reçu leur image (2026-09-16). D'où
  `vignettes.sh`, incrémental, et l'étape 0 ci-dessus.
  ⚠ `_index()` range les vignettes **par id**. Les images d'objets retirés du catalogue
  restent dans `public/images` (Capuche du Magister, Runes naines…) et produisent donc des
  vignettes orphelines — sans effet tant qu'aucun id n'est recyclé.

## Ce qui n'est pas versionné

`docs/livret/img/` (vignettes dérivées de `public/images`), `browser-shots/livret/` (les
captures) et tout `public/livret/` (page web, images, captures, manifeste, PDF) sont
**régénérables** et ignorés par git — exactement
comme `public/images`, qui pèse 275 Mo sur le disque et zéro dans l'historique. Ce qui est
versionné, c'est ce qui permet de tout refaire : `generer.py`, `rendre-pdf.mjs`,
`vignettes.sh`, les scripts de capture et ce README.
