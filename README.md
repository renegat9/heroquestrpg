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
> à playtester). **564 tests Pest verts.**

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

Une campagne neuve démarre à **0 or** : les héros partent avec leur arme de classe et le premier butin se gagne au donjon — le marché ne prend son sens qu'après la première quête.

### ⏳ Durées d'effet

Un buff (sort ou potion) déclare **quand il s'arrête** via sa clé `effet.duree`. Six mots-clés, et rien d'autre — voir `reference/19_mots_cles_effets.md` et `App\Engine\DureeEffet` :

| mot-clé | prend fin… |
|---|---|
| `prochaine_attaque` | quand le porteur attaque |
| `prochaine_defense` | quand le porteur se défend |
| `premier_degat_subi` | au premier dégât réellement encaissé (parer sans perdre de PV ne le consomme pas) |
| `ce_tour` | à la fin du tour du porteur (n'atteint pas la phase des monstres) |
| `prochain_tour` | au début de son prochain tour (couvre donc la phase des monstres) |
| `fin_du_combat` | quand plus aucun monstre n'est **engagé** (actif *et* révélé) — pas quand le donjon est vidé |

Un **entier** à la place d'un mot-clé signifie tout autre chose : un décompte en **tours**, décrémenté en fin de round (c'est ce que porte `conditions.duree_defaut` — Empoisonné 3 tours). Ajouter un mot-clé sans câbler son déclencheur crée un effet qui ne s'arrête jamais.

Trois autres vocabulaires de sort (`App\Engine\MotsClesSort`) : **`cible`** (`soi`, `heros`, `monstre`, `monstres_zone`) et **`resistance`** (`jet_mind`). `MotsClesSort::NON_IMPLEMENTES` recense à part les mots qu'un catalogue peut porter mais que le moteur n'applique pas encore — le guide ne les affiche pas, pour ne jamais promettre une règle absente.

### ⚔️ Équipement : des cartes du plateau aux mots-clés

Au plateau, une carte d'équipement dit son effet en une phrase — *« This weapon allows you to attack diagonally »*, *« Two-handed »*, *« a 2 square movement penalty »*. Ici l'effet est une **donnée** : chaque carte est convertie en **statistiques** (prix, dés) + **mots-clés** d'un vocabulaire fermé, `App\Engine\MotsClesEquipement`. Le paquet porté est celui de **Ye Olde Inn** (`sjeng-equipment.pdf`, 26 cartes retenues sur 27) — une révision assumée du jeu de base, pas le paquet officiel. La conversion carte par carte est en `reference/16_armurerie.md` §2.2, les mots en `reference/19_mots_cles_effets.md` §9.

| famille | mots-clés |
|---|---|
| statistiques | `des_attaque` (**remplace** la valeur du porteur), `des_defense` (**s'ajoute**) |
| portée | `attaque_diagonale`, `portee: distance`, `inutilisable_adjacent`, `jetable` |
| mains / corps | `deux_mains`, `incompatible_deux_mains`, `malus_deplacement` |
| artefacts | `degats_fixes`, `des_attaque_contre`, `attaque_double_contre`, `bonus_pv_body_max`, `bonus_pv_mind_max` |
| charges | `charges`, `tue_sauf_bouclier_noir` |
| nature du dégât | `type_degat` (côté sort), `immunite_degat` (côté objet) |
| économie de sorts | `restaure_sorts`, `second_sort_par_tour`, `sort_non_epuise`, `sort_non_epuise_sur_bouclier_noir` |
| outil | `permet_desamorcage` |
| consommables | `soin_pv_body`, `soin_pv_body_de`, `soin_pv_mind`, `bonus_des_attaque`, `bonus_des_defense`, `attaque_supplementaire`, `condition_appliquee`, `retire_condition`, `duree` |

**Les restrictions de classe** ne passent **pas** par un mot-clé, exprès : *« May not be used by a Wizard »*, *« …by a Wizard or Elf »*, *« May **only** be used by a Wizard »* sont portées par le couple `tag_equipement` × `classes_heros.tags_equipement` — la règle est dite une fois, côté classe, plutôt que répétée sur chaque pièce. Onze tags couvrent les sept exclusions distinctes du paquet, dont `armure_magicien` pour les deux protections réservées au magicien : sa première armure, lui qui restait sinon à 2 dés de défense toute la campagne.

Les pièces d'armure se **cumulent**, comme au plateau : le casque a son propre emplacement depuis le 2026-08-08, donc casque + armure de corps + bouclier montent bien à **6 dés de défense** (LR p. 7). Une règle du paquet reste **non portée** et c'est délibéré : le *dual-wielding* demanderait que l'emplacement devienne un paramètre d'API, et aucune carte ne dit ce que la seconde arme rapporte.

Un test verrouille le vocabulaire **dans les deux sens** : aucune clé de catalogue hors du vocabulaire (une règle annoncée que personne n'applique), et aucun mot déclaré que plus aucun objet ne porte (une règle qui n'existe que sur le papier).

Le **bestiaire** suit la même discipline. Les 8 monstres de base viennent des cartes monstre — une table que les livrets renvoyaient à l'écran du MJ, jamais numérisé — et deux passages des livrets la recoupent. Les **23 créatures d'extension** (Dread Moon, Mage of the Mirror, Frozen Horror, Ogre Horde, Jungles of Delthrak) viennent, elles, des **livrets officiels** via `reference/18_extensions.md` : quand les cartes de fans les contredisent, le livret gagne. ⚠ Au plateau, tout monstre de base a **1 point de Body** — le gobelin comme la gargouille : le bestiaire est nettement plus fragile depuis le 2026-08-09, et c'est le design du jeu.

Les **artefacts** viennent du même endroit : `sjeng-artefacts.pdf` (34 cartes, cinq sources officielles). Ils remplacent 7 artefacts inventés qui ne faisaient que monter la courbe des dés — 4, 5, puis 6 — là où un vrai artefact fait ce que rien d'autre ne fait : frapper deux fois un orque, blesser à coup sûr, porter des PV en plus. Dix-sept cartes sont portées, **17 ne le sont pas** et le doc 16 §9.1 dit pour chacune la mécanique qui lui manque (types de dégâts, téléportation, contrôle de monstre…). Un cinquième emplacement, `talisman`, accueille les quatre bijoux de classe.

Deux mécaniques ont été ouvertes le 2026-08-09 pour débloquer sept de ces cartes d'un coup. Les **charges** disent « cet exemplaire-ci a N utilisations » — ce que `quantite`, qui compte des exemplaires identiques, ne savait pas dire : un arc à quatre flèches est un seul objet utilisable quatre fois. À zéro il devient inerte, il ne disparaît pas. L'**économie de sorts** dit quand un sort épuisé revient : jusque-là un simple booléen remis à zéro par quête, que seuls deux nœuds de compétence savaient contourner et qu'aucun objet ne pouvait toucher.

**Les 61 cartes vivent dans `config/cartes.php`**, et un test les confronte au catalogue dans les deux sens : toute carte marquée portée doit exister en base, aucune arme/armure/artefact ne peut exister sans carte. « Le catalogue vient des cartes » n'est donc pas une affirmation de documentation mais une propriété testée. Le même registre alimente `GET /api/guide` : la page **/guide → onglet « Cartes sources »** montre aux joueurs la provenance de chaque pièce et les cartes du plateau qui n'ont pas encore de mécanique, chacune avec ce qui lui manque.

## 🧪 Tests

Suite **Pest** (moteur sous `tests/Unit/Engine`, jeu sous `tests/Feature`) — **619 tests verts**.

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
- **`reference/`** — documents de conception (français). `00_synthese.md` est l'index : décisions par domaine, dépendances, questions ouvertes, périmètre MVP vs Phase 2. Docs 01–05 décidés ; 06–10 ont des questions ouvertes à ne pas trancher en silence. Toutes les valeurs chiffrées sont des **propositions de départ**. Docs **16–18** sont des extraits sourcés des livrets officiels Avalon Hill 2021 (ne jamais semer une valeur qu'ils ne sourcent pas — le §2 du doc 16 sépare explicitement ce que les *livrets* attestent de ce que les *cartes équipement* portent) ; **`19_mots_cles_effets.md`** fixe les vocabulaires d'effet : durées, sorts, équipement.
- **`CLAUDE.md`** — guide pour les agents de code (incantations Docker, gotchas).

## 🔒 Sécurité (doc 11)

- MariaDB, phpMyAdmin et Qdrant ne sont **jamais exposés** hors du réseau compose (phpMyAdmin bind 127.0.0.1 seulement).
- Auth simple **acceptable en LAN/VPN uniquement**. Exposition WAN → proxy TLS + auth durcie (VPN recommandé à la place).
- Une campagne complète = les volumes `mariadb_data` **et** `qdrant_data` — à sauvegarder ensemble.

## 🛣️ Reste à faire (court terme)

- **Équilibrage** : régler stats / prix / difficultés en playtest (la tactique du bot de test est limitée — un humain place mieux ses héros).
- **Assets** : (re)générer les illustrations / voix manquantes selon le budget API ; affiner les prompts de style (`config/images.php`) au besoin.
- Phase 2 (doc 00 §8) : alliés recrutables, marchandage, ramifications profondes, boss multi-phases, etc.
