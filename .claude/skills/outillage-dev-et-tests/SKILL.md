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

⚠ **Sur une COPIE JETABLE, jamais sur `database/database.sqlite`** (René,
2026-09-12 — les groupes et personnages sont de la **production**) :

```bash
cp database/database.sqlite /tmp/essai.sqlite          # ⚠ la copie, pas l'original
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -v /tmp:/db -w /app \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/db/essai.sqlite \
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
- ⚠ **`toContain('x', 'mon message')` n'a PAS de paramètre message** : Pest lit le
  second argument comme une **seconde valeur attendue**. Pour un message, passer par
  `expect(...)->toBeTrue("message")` sur une condition explicite.
- ⚠ **Un test qui passe AUSSI sans le correctif ne prouve rien.** Le vérifier :
  `git stash push -q <fichier>` → relancer → il doit **échouer** → `git stash pop -q`.
  Payé le 2026-09-12 : un test « les monstres laissent de la place aux héros » comptait
  le sol **brut** au lieu des cases tenables, et passait dans les deux sens.
- ⚠ **Un test instable se MESURE avant de se corriger**, sinon on répare au hasard.
  Boucle de 10 à 12 exécutions en comptant les échecs, puis on cherche la cause —
  et on la LIT (`grep -A12 FAILED`) au lieu de la deviner. Sur `DeckFouilleTest`
  (3 échecs sur 10) deux causes empilées se cachaient derrière une troisième supposée.
- ⚠ **Un conteneur tué par un timeout SURVIT et corrompt la base du run suivant.**
  `timeout` tue le client `docker run`, pas le conteneur : il continue d'écrire dans le
  fichier sqlite qu'une exécution suivante vient de recopier — d'où une volée d'échecs
  sans rapport (équipement, compendium, LLM). Avant de conclure à une régression :
  `docker ps --filter ancestor=composer:2 -q | xargs -r docker kill`.
  ⚠ L'outil Bash plafonne à **10 minutes** ; la suite complète dure ~6 min, donc
  **une seule** suite par appel.

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

## ⚠ Sauvegarder — avant tout le reste

```bash
./image-tools/sauvegarder.sh              # MariaDB + bible Qdrant, rotation sur 10
./image-tools/sauvegarder.sh --verifier   # relit la dernière dans un MariaDB JETABLE
./image-tools/sauvegarder.sh --lister
```

Jusqu'au 2026-09-12 il n'existait **aucune sauvegarde** : 210 Mo de volume, zéro dump,
zéro script. Durcir les commandes ne faisait que réduire la probabilité d'un événement
**irréversible**. ⚠ Les **deux volumes ensemble** — une base restaurée sans sa bible rend
le RAG muet. ⚠ Et `--verifier` restaure dans un conteneur **jetable**, jamais dans celui du
jeu : une sauvegarde jamais relue n'est pas une sauvegarde, et la vérifier ne doit pas
risquer ce qu'elle protège.

## ⚠ Les commandes qui détruisent sont bloquées

`migrate:fresh`, `migrate:refresh`, `migrate:reset` et `db:wipe` **refusent** dès que la
base porte un groupe ou un personnage
(`AppServiceProvider::interdireLesCommandesDestructrices()`). `APP_ENV` vaut `local`, donc
la confirmation native de Laravel **ne se déclenchait jamais** — elle n'existe qu'en
`production`. Sortie de secours nommée : `HQ_AUTORISER_DESTRUCTION=1`, délibérément **pas**
`--force` (un agent ajoute `--force` par réflexe, il n'invente pas une variable
d'environnement). `partie:purger --supprimer` demande confirmation, et `--tout` exige de
**recopier le nombre de groupes**. ⚠ `testing` passe librement : `RefreshDatabase` a besoin
de `migrate:fresh`, et la suite tourne sur une sqlite jetable.

## Ménage après une session de test

⚠ **INTERDIT depuis le 2026-09-12** : `partie:purger --supprimer --tout`,
`migrate:fresh`, et tout `DELETE` sur `groupes` / `personnages` / `joueurs` de la base
réelle. Les campagnes durent des semaines et René veut les retrouver. Jusque-là je
terminais chaque session par une purge globale « pour laisser la maison propre » : la
propreté ne vaut pas la perte d'une campagne en cours.

```bash
docker compose exec app php artisan partie:purger            # inventaire, ne touche à rien
./browser-shots/campagne/nettoyer.sh                         # ⚠ CIBLÉ sur sa propre campagne
```

`partie:purger --supprimer` reste disponible mais **n'est plus la routine** : le nettoyage
d'une campagne de harnais passe par `nettoyer.sh`, qui ne vise que le groupe qu'il a créé.
Un changement sur des lignes existantes se fait par **migration**, jamais par re-seed
destructif — les seeders écrivent en `updateOrCreate` et ne purgent pas, donc `db:seed`
reste sûr (seule exception connue : `TuileSeeder`, qui purge exprès, et rien ne
référence `tuiles.id`).

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
- [ ] Sauvegarde prise avant toute opération qui touche la vraie base
- [ ] Workers redémarrés si du PHP a changé
- [ ] Front rebuild si du Vue a changé
- [ ] Suite Pest verte (conteneur jetable, pas `app`, **sur une copie sqlite**)
- [ ] Tout test neuf vérifié comme **échouant sans le correctif**
- [ ] Vérifié en vrai contre la stack seedée, pas seulement en test
- [ ] Campagne de test nettoyée via `nettoyer.sh` — **jamais** `--supprimer --tout`
