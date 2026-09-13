# Livret de jeu — comment il se fabrique

`public/livret/HeroQuest-RPG-Livret-de-jeu.pdf` (42 pages A4) n'est pas écrit à la main : il est
**généré**, et c'est ce qui l'empêche de mentir. Les tableaux — stats des 12 classes,
41 créatures, 105 objets, 31 sorts, pièges, mobilier, terrain, grilles de talents —
sont extraits de la **base réelle** ; les copies d'écran sont prises sur la **vraie
stack**, avec une campagne jouée pour de bon.

Les textes de règle, eux, sont écrits dans `generer.py` et adossés à `reference/01`
à `05` et `10`. Quand une règle change, c'est là qu'il faut la reprendre.

## Régénérer

```bash
# 1. le catalogue, depuis la base
docker compose exec -T app php artisan tinker --execute="
  \$d = [];
  foreach (['classes_heros','monstres','objets','sorts','pieges','mobiliers','terrains','epreuves','competences'] as \$t) {
    \$d[\$t] = \Illuminate\Support\Facades\Schema::hasTable(\$t) ? \DB::table(\$t)->get() : [];
  }
  file_put_contents('/tmp/catalogue.json', json_encode(\$d, JSON_UNESCAPED_UNICODE));"
docker compose exec -T app cat /tmp/catalogue.json > /tmp/catalogue.json

# 2. le HTML + la couverture
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

Le PDF final atterrit dans **`public/livret/`** : nginx le sert tel quel
(`try_files $uri`), et c'est là que pointe la carte « Livret de jeu » de l'accueil.
⚠ Cette carte **ne s'affiche que si le fichier existe** (une requête `HEAD` au montage
de l'écran) : un lien mort sur la page d'accueil serait exactement la promesse non tenue
que `CLAUDE.md` interdit. Sur une installation neuve, il faut donc générer le livret une
fois pour que l'entrée apparaisse.

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
```

## Quatre pièges déjà payés

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

## Ce qui n'est pas versionné

`docs/livret/img/` (vignettes dérivées de `public/images`), `browser-shots/livret/` (les
captures) et `public/livret/` (le PDF) sont **régénérables** et ignorés par git — exactement
comme `public/images`, qui pèse 275 Mo sur le disque et zéro dans l'historique. Ce qui est
versionné, c'est ce qui permet de tout refaire : `generer.py`, `rendre-pdf.mjs`, les deux
scripts de capture et ce README.
