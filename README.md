# HeroQuest RPG — JDR de table avec MJ IA

Jeu de rôle tabletop inspiré de **HeroQuest**, animé par un **maître de jeu IA** (« MJ IA »).
Projet **auto-hébergé, interne** : parties en **LAN entre amis**, pas de déploiement public.

**Principe fondateur :** le **moteur déterministe** fait foi sur toute la mécanique
(dés, PV, combat, jets, pièges, sorts). L'IA **narre et propose** seulement — ses
sorties sont contraintes par des schémas JSON puis validées par le moteur contre
les catalogues (rejet/retry, puis repli codé). **Les joueurs ne tapent jamais de
texte libre** : la boucle est *l'IA narre → l'IA propose un menu de choix → le
moteur résout l'option choisie*.

---

## 📊 Statut du projet

> **MVP complet et vérifié en partie réelle.** Le jeu se joue de bout en bout
> (campagne multi-quêtes, sous-boss, boss final, clôture), avec **narration IA
> (Claude ou Gemini, au choix)**, **voix TTS**, **musique d'ambiance** et
> **illustrations** générées. Reste surtout l'**équilibrage** (valeurs de départ
> à playtester). **224 tests Pest verts.**

| Domaine | Statut | Notes |
|---|:---:|---|
| Moteur de règles déterministe (`app/Engine`) | ✅ | Dés, jets, combat, déplacement, sorts mentaux — cœur autoritaire, dés injectables/seedables, fortement testé |
| Schéma + couche BD (`app/Models`, migrations, seeders) | ✅ | Catalogues (bestiaire, objets, sorts, tuiles, pièges) en données de référence |
| Boucle de jeu (`app/Partie`) | ✅ | Démarrage quête, carte, budget de rencontre, résolution de tour, monstres scriptés |
| Combat, jets, **pièges**, **sorts de héros** | ✅ | |
| **Marché** commun + **votes** de groupe | ✅ | Phase marché atomique (confirmation de tous) |
| **Montée de niveau** par jalon + arbre de compétences | ✅ | |
| **Sorts de Dread** + capacités de boss | ✅ | Régénération, sorts ennemis, charge |
| **Clôture de campagne** (victoire / échec / abandon) | ✅ | Issue **dérivée de l'état**, jamais mal étiquetée ; or réparti, historique, purge |
| **Snapshots & reprise** (après TPK / coupure) | ✅ | |
| MJ IA (`app/Agent`) — squelette, narration, menus, habillage | ✅ | Fournisseur **au choix** : **Anthropic (Claude)** ou **Google Gemini** via `LLM_PROVIDER` ; modèles configurables (`ANTHROPIC_MODEL`, `GEMINI_MODEL`). **Vérifié en live** sur les deux |
| RAG « bible » (Qdrant + embeddings) | ✅ | **Voyage AI** (`voyage-3.5`, 1024-dim) si `VOYAGE_API_KEY`, sinon repli lexical |
| **Illustrations** (`php artisan images:generer`) | ✅ | Catalogue **fixe** pré-généré (classes, monstres, objets, pièges, sorts) + **dynamiques en arrière-plan** : portraits de boss, scènes de quête, lieux de repos (hub), portrait unique par héros. Via **Gemini image**. **Sans clé/asset : repli sur les icônes** |
| API REST + temps réel (Reverb) | ✅ | Contrat dans [`docs/contrat-api.md`](docs/contrat-api.md) |
| Front Vue (accueil, narrateur, joueur, table, manette) | ✅ | Écrans vérifiés au navigateur (Playwright) |
| Modèle de session (narrateur par code + joueurs à compte) | ✅ | Heartbeat « narrateur actif » ; quête au statut « prêt » de tous |
| **Audio / voix** | ✅ | Narration MJ **lue en TTS** + **voix dédiée du narrateur** + **barks de monstres** (voix par archétype, répliques nommées de boss) + **musique d'ambiance** (hub/exploration/combat/boss, via Lyria). Audio pré-généré (`php artisan barks:generer`, `narration:generer`, `audio-tools/`) ; **sans clé/asset**, repli Web Speech (texte) et ambiance silencieuse |
| **Équilibrage** (stats, prix, difficultés) | 🧪 | Valeurs **de départ**, à régler en playtest |
| Déploiement public / WAN durci | 🚫 | Hors périmètre — LAN/VPN uniquement |

**Jouable sans aucune clé API :** chaque tâche IA a un repli (menus du moteur,
narration neutre, noms de monstres du catalogue), le RAG bascule en lexical, la
voix passe en Web Speech et **les illustrations retombent sur les icônes**. Les
clés n'améliorent que la **qualité narrative / sonore / visuelle**, jamais la mécanique.

---

## 🧱 Stack

Monolithe **Laravel** modulaire (PHP 8.3) + SPA **Vue 3** + **Reverb** (WebSocket)
+ **MariaDB** (état de jeu exact) + **Qdrant** (RAG), le tout sur un seul
`docker-compose`. LLM via l'**API Anthropic (Claude)** **ou** **Google Gemini**
(au choix). Tout tourne en conteneurs — **ni PHP, ni Node, ni navigateur requis
sur l'hôte**.

## 🚀 Démarrage rapide

```bash
./setup.sh                            # install interactif : écrit .env, build, démarre, migre + seed
docker compose --profile dev up -d    # mode dev (phpMyAdmin 127.0.0.1:8081 + Vite hot reload 5173)
docker compose up -d                  # mode « prod » LAN
docker compose exec app php artisan   # n'importe quelle commande artisan
docker compose logs -f app queue      # suivre l'app et les jobs IA
```

Clés (facultatives) dans `.env` uniquement, **jamais dans les images** :
- `LLM_PROVIDER` = `anthropic` (défaut) | `gemini` — fournisseur du **texte** du MJ.
- `ANTHROPIC_API_KEY` + `ANTHROPIC_MODEL` (défaut `claude-sonnet-4-6`).
- `GEMINI_API_KEY` — sert au **texte** (si `LLM_PROVIDER=gemini`, `GEMINI_MODEL`
  défaut `gemini-3.1-flash-lite`), au **TTS** (voix) et aux **images**
  (`GEMINI_IMAGE_MODEL` défaut `gemini-2.5-flash-image`).
- `VOYAGE_API_KEY` — RAG sémantique de la bible.

Après modification des clés : recréer les conteneurs `app`/`queue`/`queue-jeu`/`reverb`
**et redémarrer `web`** (nginx met en cache l'IP de l'app → 502 sinon).

**Génération des assets (hors-ligne, facultatif, avec `GEMINI_API_KEY`)** :

```bash
docker compose exec app php artisan images:generer    # illustrations du catalogue (résumable ; --type, --force)
docker compose exec app php artisan barks:generer      # voix des monstres (TTS)
docker compose exec app php artisan narration:generer  # voix du narrateur (TTS)
```

Boss, scènes, lieux de repos et portraits uniques se génèrent **automatiquement
en arrière-plan** pendant la partie. Tous les assets (`public/audio`, `public/images`)
sont **régénérables** et hors dépôt (gitignored).

## 🎮 Comment on joue

1. **Le narrateur** (la tablette / l'écran partagé) ouvre `/narrateur` et saisit le **code du groupe** — pas de compte, juste un *heartbeat* qui le marque « actif ».
2. **Chaque joueur** ouvre `/joueur`, se crée un compte, choisit un héros de son roster, et **crée un groupe** (depuis un héros libre) ou **rejoint** par code.
3. Quand **tous les membres sont « prêts »** et qu'un **narrateur est actif**, la quête démarre.
4. La table affiche carte + narration ; chaque téléphone est une **manette** qui propose le menu de choix du héros.

## 🧪 Tests

Suite **Pest** (moteur sous `tests/Unit/Engine`, jeu sous `tests/Feature`) — **224 tests verts**.

```bash
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -w /app \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/app/database/database.sqlite \
  composer:2 ./vendor/bin/pest
```

## 🗂️ Architecture (modules Laravel, nommage français)

- **`app/Engine`** — règles pures (dés, jets, combat, déplacement, sorts mentaux). Cœur autoritaire, dés injectables et seedables. Fortement testé.
- **`app/Agent`** — agent MJ unique : interface `ClientLLM` implémentée par `AnthropicClient` et `GeminiClient` (sortie structurée forcée — tool use / function calling), `Skills/` (une par tâche : squelette, détail de quête, menu, narration — schéma JSON + validation catalogue + repli), `Memoire/` (contexte, bible Qdrant, embeddings), `Audio/` (TTS Gemini), `Image/` (génération d'illustrations Gemini).
- **`app/Partie`** — services de boucle de jeu orchestrant Engine + Models ; `Images/` résout les illustrations (URL ou repli), `Audio/`/`Narration/` les sons.
- **`app/Models`** — Eloquent sur le schéma de la doc 12 ; catalogues = données de seed.

## 📚 Documentation

- **`docs/contrat-api.md`** — contrat API / front / temps réel (**source de vérité** ; à modifier en premier).
- **`reference/`** — documents de conception (français). `00_synthese.md` est l'index : décisions par domaine, dépendances, questions ouvertes, périmètre MVP vs Phase 2. Docs 01–05 décidés ; 06–10 ont des questions ouvertes à ne pas trancher en silence. Toutes les valeurs chiffrées sont des **propositions de départ**.
- **`CLAUDE.md`** — guide pour les agents de code (incantations Docker, gotchas).

## 🔒 Sécurité (doc 11)

- MariaDB, phpMyAdmin et Qdrant ne sont **jamais exposés** hors du réseau compose (phpMyAdmin bind 127.0.0.1 seulement).
- Auth simple **acceptable en LAN/VPN uniquement**. Exposition WAN → proxy TLS + auth durcie (VPN recommandé à la place).
- Une campagne complète = les volumes `mariadb_data` **et** `qdrant_data` — à sauvegarder ensemble.

## 🛣️ Reste à faire (court terme)

- **Équilibrage** : régler stats / prix / difficultés en playtest (la tactique du bot de test est limitée — un humain place mieux ses héros).
- **Assets** : (re)générer les illustrations / voix manquantes selon le budget API ; affiner les prompts de style (`config/images.php`) au besoin.
- Phase 2 (doc 00 §8) : alliés recrutables, marchandage, ramifications profondes, boss multi-phases, etc.
