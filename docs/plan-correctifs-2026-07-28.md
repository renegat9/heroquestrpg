# Plan de correction — suites du test de jeu du 2026-07-27/28

Référence des constats : **`docs/verdict-test-jeu-2026-07-28.md`** → en réalité
`docs/verdict-test-jeu-2026-07-27.md`. Chaque lot renvoie aux sections §2.x de ce verdict.

**Règles communes à tous les lots :**
- Lire `CLAUDE.md` et la section du verdict correspondante **avant** de coder.
- **Ne modifier QUE les fichiers de son lot.** Les périmètres sont disjoints exprès : plusieurs
  agents travaillent en parallèle. Si une correction semble exiger un fichier d'un autre lot,
  **ne pas y toucher** et le signaler dans le rapport final.
- Le moteur déterministe fait autorité : ne jamais déplacer une décision de règle vers le front.
- Faire tourner les tests soi-même avant de rendre :
  ```bash
  docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -w /app \
    -e DB_CONNECTION=sqlite -e DB_DATABASE=/app/database/database.sqlite \
    composer:2 ./vendor/bin/pest
  ```
  Pour les lots front, en plus :
  ```bash
  docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -w /app \
    node:20-alpine sh -c "npm run build"
  ```
  **La suite doit rester verte (437 tests au départ).** Ajouter des tests pour ce qu'on corrige.
- Ne rien committer. Rapport final : ce qui a été changé, pourquoi, tests ajoutés, résultat des
  suites, et ce qui a été laissé de côté.

---

## Lot 1 — Persistance de l'état de quête (backend) · **le plus critique**

**Fichiers** : `app/Partie/EtatGroupe.php`, `app/Partie/ResolveurTour.php`,
`app/Partie/DemarreurQuete.php`, `app/Partie/Sauvegarde.php`, `app/Partie/MenuMoteur.php`,
`app/Partie/FabriqueGrille.php`, `app/Models/Quete.php`, une migration, tests.

1. **§2.16 — sortir l'exploration et la fouille du cache.** `partie:salles:{quete}` et
   `partie:tresor:{quete}` sont écrits avec un TTL de 6 h et **rien en base**
   (`DemarreurQuete:261`, `Sauvegarde:413`, `ResolveurTour:2498`, lu en `EtatGroupe:195-198` et
   `MenuMoteur:96`). Leur perte referme le brouillard sur les zones explorées et **fige tout le
   groupe** (0 case accessible côté client). C'est de l'état de partie durable : le persister en
   base (colonnes JSON sur `quetes`, p. ex. `salles_decouvertes` et `tresors_fouilles`).
   - Garder une compatibilité de lecture avec les quêtes en cours si c'est peu coûteux.
   - **Les snapshots doivent capturer et restaurer ces champs** (`Sauvegarde`) — ce n'est pas le cas
     aujourd'hui, donc une reprise perd l'exploration.
2. **§2.17 — un héros à terre doit bloquer tout le monde.** `FabriqueGrille::pour` l. 42 exclut les
   héros tombés (`&& ! $etat->tombe`) alors que `MenuMoteur::peutSeDeplacer` les compte. Un monstre
   s'est déplacé **sur** la case d'une héroïne à terre, ce qui l'a rendue **impossible à relever**
   (« une autre figure occupe sa case ») tout en laissant l'option affichée. Rendre l'occupation
   cohérente : un héros à terre occupe sa case (c'est l'intention documentée, `ResolveurTour:2207`
   « C4 : occupe sa case, relevable »).
   - Vérifier l'effet sur la ligne de vue : un corps au sol ne devrait pas bloquer la vue comme une
     figure debout. Si les deux usages divergent, séparer « occupe la case » de « bloque la vue ».
3. **§2.14 — messages techniques exposés au joueur.** `ResolveurTour:325` et `:1285` renvoient
   « Destination requise … **parametres.x** et **parametres.y** » directement dans l'UI. Les
   reformuler en français destiné au joueur (sans noms de variables).
4. **§2.5 — narration en contradiction avec le résultat.** Un jet de fouille peut être *réussi*
   (`issue: reussite`) tout en ne trouvant **rien** (`pieges_reveles: []`, `portes_revelees: []`) ;
   le MJ écrit alors une découverte inexistante. Ajouter au payload du jet un indicateur explicite
   de « découverte effective » que le skill Narration pourra exploiter (le lot 2 s'occupe du
   contexte IA ; ici on se limite à **produire** l'information).

## Lot 2 — Contexte IA et génération de menus (backend)

**Fichiers** : `app/Jobs/GenererMenu.php`, `app/Agent/Memoire/ContexteAssembleur.php`,
`app/Agent/Skills/MenuChoix.php`, `app/Agent/Skills/Narration.php`, tests.

1. **§2.4 — le menu propose des options que le moteur refuse.** `GenererMenu::fusionner` étape 2
   réinjecte **inconditionnellement** les options IA (`dialogue`/`action`/`jet`) alors que le menu
   moteur a déjà retiré les options d'action quand `a_agi` est vrai. Chaque clic renvoie alors 422
   « Tu as déjà agi ce tour. », **affiché à la place de la narration du MJ**. Filtrer les options IA
   par créneau réellement disponible, comme le fait `MenuMoteur::generer`.
2. **§2.6 — le MJ ignore ce qui vient d'apparaître et voit trop.** `ContexteAssembleur` l. 105
   envoie `monstres_actifs` **sans filtre `revele`** : le narrateur connaît les monstres jamais
   découverts (fuite d'information) et n'a aucun signal sur ceux qui viennent d'être révélés — à
   l'ouverture d'une porte il a décrit une salle vide devant trois monstres. Filtrer sur `revele`
   et distinguer ce qui vient d'apparaître.
3. **§2.6 bis — cibles d'attaque non révélées.** `MenuChoix::valider` accepte une `cible_id`
   correspondant à n'importe quel monstre **actif**, révélé ou non. Restreindre aux révélés.
4. **§2.11 — le code de partie fuit dans la fiction.** `ContexteAssembleur` l. 48-50 envoie
   `groupe.identifiant` (le slug d'URL avec son suffixe aléatoire) au LLM. Résultat constaté :
   campagne « Le Tombeau de Vardhul », identifiant `le-tombeau-de-vardhul-krmu`, **méchant généré
   « Vardhul Krmu »**. Retirer l'identifiant du contexte narratif (garder `nom` et `theme`).
5. **§2.3 bis — sorts mentaux et immunité.** Un sort *mental* lancé sur une cible à `pv_mind = 0`
   est **consommé pour la quête** et ne fait rien (`issue: immunise`), sans le moindre avertissement.
   Le moteur connaît l'immunité au moment de résoudre : la remonter **dans les cibles proposées**
   (drapeau par cible) pour que la manette puisse l'afficher. **Ne pas modifier le front** — le
   lot 3 s'en charge ; se contenter d'exposer la donnée et de documenter le champ ajouté.
6. **§2.5 (suite)** — côté skill `Narration`, exploiter l'indicateur de « découverte effective »
   produit par le lot 1 s'il est présent ; rester tolérant à son absence.

## Lot 3 — Manette (front)

**Fichiers** : `resources/css/manette.css`, `resources/js/components/manette/DeplacementSheet.vue`,
`resources/js/components/manette/CibleSheet.vue`, `resources/js/views/ManetteView.vue`.

1. **§2.2 — les boutons « Fermer » sont inatteignables.** `.scene-ctrl` (le rond « Hub »,
   `manette.css:280`) est en `position: fixed; bottom: 16px; left: 50%; **z-index: 200**`, la
   feuille `.dep-ov` (`DeplacementSheet.vue:163`) en `z-index: 70` avec `place-items: end center` :
   **même position**, le lien Hub passe devant. Sur téléphone, taper « Fermer » **quitte la partie**.
   De plus le « × » d'en-tête se rend **hors écran** (x≈471 sur 420 px de large). Corriger la
   superposition (le Hub doit passer derrière toute feuille ouverte, ou être masqué) et ramener le
   « × » dans le viewport. Vérifier aussi la feuille de détail d'un sort.
2. **§2.16 (volet client) — ne jamais immobiliser un héros à cause du brouillard.** Le BFS de
   `DeplacementSheet.vue:63-96` n'étend le déplacement que vers `cases[y][x] === 's'` ; quand la
   carte connue est incomplète, il renvoie **0 case accessible** et le héros ne peut plus bouger du
   tout. Rendre le repli robuste : au minimum autoriser les cases **adjacentes** au héros que le
   serveur juge franchissables. Le message « Aucune case accessible — tu es bloqué » doit rester
   réservé aux vrais blocages tactiques.
3. **§2.3 — « aucune cible en vue ».** Quand un sort offensif n'a **aucun monstre** en ligne de vue,
   `CibleSheet` n'affiche que les alliés sous un bandeau « ALLIÉS (TIR AMI) » : deux joueurs en ont
   conclu que la magie était cassée. Distinguer explicitement « aucune cible en vue (ligne de vue
   bouchée) » de « aucune cible du tout ».
4. **§2.11 cosmétique — « TIR AMI » sur les soins.** Peau de Pierre, Eau de Guérison, Courage et
   Soin du Corps déclenchent l'avertissement « **la cible subira l'effet comme un ennemi** », qui est
   faux pour un soin ou un buff — et impose deux clics de confirmation en pleine urgence. Ne
   l'afficher que pour les sorts réellement offensifs (`type` `degats`/`mental`).
5. **§2.3 bis (affichage)** — si le back expose un drapeau d'immunité par cible (lot 2), le rendre
   visible dans le sélecteur. **Rester tolérant à son absence** (le lot 2 tourne en parallèle).
6. **§2.17 (affichage)** — ne pas proposer un héros **à terre** comme cible de sort offensif.

## Lot 4 — Écran de table et reconnexion (front)

**Fichiers** : `resources/js/views/TableView.vue`, `resources/js/App.vue`,
`resources/js/composables/useApi.js`, `resources/js/store/game.js`, `resources/js/views/JoueurView.vue`.

1. **§2.1 — l'écran de table sature un cœur CPU.** Mesuré : **100 %** du thread principal, dont
   **59 s/60 s de recalcul de style + layout** sur ~13 500 nœuds ; temps de script 0,1 s, pas de
   fuite mémoire. **Témoin décisif** : la même page avec `animation: none` injecté tombe à **3 %** et
   redevient réactive. Coupables identifiés dans `TableView.vue` : `figpulse` (animation de
   `box-shadow`, l. 784/790), `flick` (opacité sur un grand `radial-gradient`, l. 799), `tspin`
   (l. 792), `table-eq` (l. 841), `table-voix-pulse` (l. 978), plus les `backdrop-filter: blur`.
   Conséquences vécues : poignée de main WebSocket qui échoue, fil de combat figé sur une ligne
   pendant 10 min, et **le narrateur ne peut plus cliquer son menu d'urgence ni ses Réglages**
   après ~1 h de partie.
   - Confiner les animations à `transform`/`opacity` sur des couches isolées (`will-change`,
     `contain: paint`), supprimer les animations de `box-shadow`, limiter les grands gradients
     animés. Envisager `@media (prefers-reduced-motion)`.
   - **Objectif chiffré : < 20 % de CPU** dans les mêmes conditions, page réactive.
   - Script de mesure fourni : `browser-shots/pilote/compare-cpu.mjs` et `temoin-animations.mjs`
     (voir leur en-tête pour l'invocation ; nécessite la stack up et un code de groupe).
2. **§2.15 — « session expirée » ne reconnecte pas.** L'overlay de `App.vue` (`.session-overlay`,
   émis par `useApi.js:80` sur 401) **avale tous les clics en silence** : le menu d'action reste
   visible dessous et d'apparence jouable. Pire, « Se reconnecter » renvoie sur `/joueur` qui
   s'affiche **pleinement connecté depuis l'état client en cache** (« Salut X », le groupe,
   « Reprendre la partie ») alors que `GET /api/moi` répond **401** — le joueur boucle
   indéfiniment. Seule une re-saisie de l'identifiant rétablit la session.
   - Sur 401 : purger l'état client d'authentification et **forcer le formulaire de connexion**.
   - L'overlay doit être explicite et ne pas laisser croire que l'écran dessous est jouable.
3. **§2.20 — en-tête de table périmé.** Après un retour au hub, la table affiche toujours
   « Quête 1 — … » et la barre d'initiative liste encore les héros **et le monstre**, alors que le
   corps dit « Le groupe se tient prêt au hub ».

## Lot 5 — Génération de carte

**Fichiers** : `app/Partie/AssembleurCarte.php`, tests.

1. **§2.12 — le 1er héros de l'initiative est encerclé au tour 1.** `spawn_heros` vaut
   `array_slice($this->interieur($cases, $salles[0]), 0, MAX_SPAWNS_HEROS)` (l. 177) :
   `interieur()` rend les cases en balayage ligne par ligne, donc les héros sont **alignés sur la
   rangée du haut**. Le héros de `spawn_heros[0]` est un **coin** dont les deux seuls voisins
   intérieurs sont `spawn[1]` et `spawn[3]` — et comme les places sont attribuées dans l'ordre
   d'initiative, **le premier joueur à jouer est systématiquement immobilisé au tour 1** quand la
   salle de départ est petite. Répartir les spawns pour qu'aucun héros ne soit enfermé.
2. **§2.12 — une case de porte est proposée au spawn.** `spawn_heros` contenait **(19,27)**, la case
   de la porte : un héros y démarre dans l'encadrement, ce qui bouche la ligne de vue de tout le
   groupe. Exclure les cases de porte des spawns.
3. **§2.12 bis — salle entièrement remplie de monstres.** La salle 2 (déclarée « 4×4 », soit
   **5 cases utiles**) a reçu **5 monstres** : plus **aucune case libre**, salle inaccessible, et une
   **seule case du donjon** adjacente à elle. Garantir un nombre de cases libres suffisant dans
   chaque salle peuplée (et vérifier qu'une salle reste pénétrable).
4. **Attention au vocabulaire** : la taille déclarée d'une salle **inclut son mur** (« 5×5 » ⇒ 9
   cases utiles, « 4×4 » ⇒ 5). Beaucoup de raisonnements faux viennent de là ; documenter
   explicitement dans le code.

---

## Hors périmètre — décisions à prendre par René, pas par les agents

- **§2.10 `GenererDetailQuete` est du code mort** (dispatché nulle part). Conséquences : titres
  génériques (« Quête 1 — Exploration simple ») et **objectif de quête jamais montré aux joueurs**,
  alors que la seule condition de victoire codée est « tuer tous les monstres ». Le brancher est un
  **choix de conception** (coût LLM, latence au démarrage), pas une correction de bug.
- **§2.19 équilibrage** : la quête 1 s'est révélée ingagnable pour 4 héros de niveau 1 **sans
  équipement de départ** — une seule Gargouille (3 PV, forte défense) a mis les quatre à terre.
  Leviers existants dans `config/jeu.php` (`forts_par_quete`, `seuil_cout_fort`,
  `taille_reference`) et/ou l'idée d'un équipement de départ. Ce sont des **valeurs de jeu**.
