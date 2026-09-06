---
name: outillage-dev-et-tests
description: >-
  Utiliser pour LANCER, TESTER ou INSPECTER le projet : démarrer la stack Docker,
  exécuter Pest, construire le front, prendre des captures navigateur, purger une
  session de test. PHP/Composer/Node ne sont PAS installés sur l'hôte — tout
  passe par des conteneurs. Déclencheurs : « lance les tests », « fais tourner
  l'appli », « prends une capture », « le front n'est pas à jour », « purge la
  base », « docker », « pest », « playwright », « ça ne se met pas à jour ».
---

# Outillage — lancer, tester, inspecter

Les commandes de base sont dans `CLAUDE.md` §Commands. Cette skill ajoute les
pièges qui coûtent une session quand on ne les connaît pas.

## ⚠ Redémarrer les workers après TOUT changement PHP

```bash
docker compose restart queue queue-jeu
```

`app` relit le code bind-monté à chaque requête ; `queue` et `queue-jeu` sont des
démons `queue:work` qui chargent les classes **une fois au boot**. Après une
migration + un changement de code ils font tourner l'**ancien** code contre le
**nouveau** schéma. Ce n'est pas théorique : ça a figé un playtest entier
(2026-08-05), deux joueurs bloqués 20 minutes sur « Le maître du jeu prépare la
suite… », **sans une erreur nulle part**.

## Tests — Pest, dans un conteneur jetable

```bash
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -w /app \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/app/database/database.sqlite \
  composer:2 ./vendor/bin/pest                       # toute la suite
#   … ./vendor/bin/pest tests/Feature/Partie/DreadTest.php    # un seul fichier
#   … ./vendor/bin/pest --filter="nom du test"
```

- ⚠ **Ne pas lancer les tests d'API dans le conteneur `app`** : ils rendent des
  **419** (CSRF/APP_KEY). Conteneur jetable, et `php artisan key:generate` si besoin.
- ⚠ **`composer.json` épingle `platform.php` à 8.3** (l'image runtime) — le garder
  en résolvant des dépendances.
- Helpers de `tests/Pest.php` à réutiliser plutôt que de remonter une scène à la
  main : `creerGroupe` · `creerHeros` · `demarrerQueteAvecMonstre` · `desFiges`
  (dés déterministes) · `donnerTalent` · `empilerCarteFouille` ·
  `poserCoffreArtefact` · `ouvrirToutesLesPortes` · `acheverLaQuete`.
- ⚠ Un test dont le résultat dépend du **boss auto-sélectionné** doit **épingler**
  le boss : la rotation a fini par tomber sur l'Ombre du Dread, éthérée, contre
  qui un crâne ne fait rien.
- ⚠ Une assertion de payload en `toBe(...)` doit **ignorer `image_url`**, sinon
  elle casse dès qu'un asset existe.

## Front

```bash
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -w /app \
  node:20-alpine sh -c "npm install && npm run build"
```

## Captures navigateur

Pas de Chrome sur l'hôte — image Playwright officielle, **version npm épinglée
au tag de l'image**. Stack démarrée (`http://localhost`), puis lire les PNG.

```bash
docker run --rm --network host -v "$PWD:/work" -w /work \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  mcr.microsoft.com/playwright:v1.48.0-jammy \
  bash -c 'npm i playwright@1.48.0 --no-save --no-audit --no-fund --silent && node browser-shots/shots.mjs'
```

⚠ **Pas de mode démo** : aucun repli de données factices dans la SPA. Toujours
tester contre la vraie stack seedée — c'est exprès, pour que les bugs se voient.

## Ménage après une session de test

```bash
docker compose exec app php artisan partie:purger            # liste seulement
docker compose exec app php artisan partie:purger --supprimer
docker compose exec app php artisan partie:purger --supprimer --tout   # + comptes + télémétrie IA
./browser-shots/campagne/nettoyer.sh                         # ciblé sur la campagne courante
```

⚠ Les deux passent par **`ClotureCampagne::purger()`**, jamais par un `DELETE` :
ce service emporte aussi les caches de phase, la **bible Qdrant** du groupe et
ses **illustrations**. Les ids de groupe se recyclent — une bible oubliée serait
**héritée** par une campagne future, le RAG servant à l'IA les promesses d'un
inconnu (mesuré : 185 points appartenant à 46 groupes disparus).

## Après un changement de `.env`
Recréer les conteneurs `app`/`queue`/`reverb` **et redémarrer `web`** : nginx
cache l'IP de l'upstream et rend des 502 tant qu'il n'a pas redémarré.
(Les réglages du panneau « Réglages » s'appliquent, eux, **à chaud**.)

## Definition of done
- [ ] Workers redémarrés si du PHP a changé
- [ ] Front rebuild si du Vue a changé
- [ ] Suite Pest verte (conteneur jetable, pas `app`)
- [ ] Vérifié en vrai contre la stack seedée, pas seulement en test
- [ ] Session de test purgée via `partie:purger` / `nettoyer.sh`
