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
> à playtester). **2 519 tests Pest.**

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
| **Alliés recrutables** (mercenaires & compagnons animaux) | ✅ | Recrutement au hub (`RecrutementHub`) : 4 par héros max, un seul animal, remise de la Potion de charme, recrutement offert par la faveur *Mercenary Guild*, entretien entre les quêtes. Les alliés jouent en fin de round et **les monstres les attaquent** |
| **Monstres à phases** & **lanceurs de Dread autonomes** | ✅ | Un seul mot-clé `phase_suivante` (Gruzbella, Gruulob…) ; sbires lanceurs de sorts par archétype (`archetype_lanceur`) |
| **Pièges magiques** | ✅ | Embrasement (*Fireburst*) et consorts, issus de *Wizards of Morcar* |
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

## 🧩 Extensions

Les boîtes d'extension officielles Avalon Hill (2021-2025) sont portées depuis leurs **livrets et cartes**, inventoriés et sourcés page par page dans [`reference/18_extensions.md`](reference/18_extensions.md). Rien n'est semé qu'un livret ou une carte ne source pas ; une boîte qu'on ne peut pas porter entièrement est **retirée par une phrase écrite**, jamais par omission.

### Thème de campagne : une boîte par groupe

Chaque campagne tire **une boîte thématique** qui fixe son bestiaire, son boss final, ses terrains et son mobilier. Le choix est une **rotation déterministe** sur l'id du groupe, puis il est **figé en base** (`groupes.theme_bestiaire`) au premier démarrage de quête : ajouter une boîte ne fait jamais changer de thème une campagne en cours. Le jeu de base n'est pas un thème — ses monstres sont le fond commun de toutes les quêtes. Liste et libellés : `DemarreurQuete::BOITES_THEMATIQUES` / `LIBELLES_BOITES`.

| Boîte | Créatures semées | Boss & adversaires notables | Ce que la boîte apporte au moteur |
|---|:---:|---|---|
| **Rise of the Dread Moon** | 6 | Ombre du Dread, Sir Ragnar ; Garde-mage | Monstres **éthérés** (`ethere` : traversent tout, seul un bouclier noir les touche) ; Cultiste, premier sbire lanceur de sorts |
| **The Mage of the Mirror** | 5 | Archimage elfe ; Loup géant, Ogre | Archers et guerriers elfes |
| **Against the Ogre Horde** | 14 | Seigneur ogre, Ogre commandant, Gruzbella (3 formes), Festral ; Doralf, Xenloth, Spawn of the Pit, Effigie gardienne | **Variante à distance** générique des monstres de base, caisses de ravitaillement, adversaire **tourelle** (`immunite_sorts`) |
| **Jungles of Delthrak** | 17 | Gruulob (2 formes), Gretzl, Demonspider, Demonape, Scion Fleur-Pourrie ; Deathspinner, Bakabri, Golem Cœur-de-Forge… | **Terrains entravants** (sable, toile, jungle), mobilier destructible (amas de cristal, cocon), traits `agile`, `racines_entravantes`, `venimeux`, `tacticien`, `spawn` |
| **The Frozen Horror** | 4 | Horreur des Glaces (ses 6 sorts fixes) ; Yéti, Ours polaire de guerre | **Terrains de glace** (glace glissante, glissières, rivière gelée, tunnels, crevasses…), étreinte du Yéti, vol du Gremlin |
| **First Light** | 1 | **Dragon** (grande taille, vol draconique, Boule de feu à volonté) | Rencontre finale draconique sur le bestiaire de base |
| **Wizards of Morcar** | 8 | **l'Artificière** (boss) ; Maître des orages, Haut mage, Nécromancien, Mage de guerre orque, Minotaure (sous-boss) | Les sorciers du Dread nommés et leurs sorts, Golems, Dreadshifters (embuscade), Haut Autel et Coffre du Dread |

**Désactivée (choix écrit)** — *Prophecy of Telor* : son seul boss sourcé, Fellmarak, ne meurt pas normalement (il fuit en quête 12, meurt par tirage en quête 13) et aucun de ces deux mécanismes n'existe encore. Seul son Sorcier du Dread générique est semé, comme sous-boss. Liste et raisons : `DemarreurQuete::BOITES_INCOMPLETES`.

**Pas (encore) de thème** — *Kellar's Keep*, *Return of the Witch Lord*, *Spirit Queen's Torment*, *The Crypt of Perpetual Darkness* : inventoriées dans le doc 18, sans bestiaire propre suffisant ou avec des mécaniques non portées. Quelques mécanismes isolés existent déjà (l'apparition « à la vue » du Dread Skull de la Crypte est testée en jeu mais **non semée**).

### Héros des extensions

**12 classes jouables** : les 4 du jeu de base (Barbare, Nain, Elfe, Magicien) et **8 classes d'extension**, chacune portée depuis sa **carte de personnage officielle** (fiches et capacités transcrites dans `reference/01_personnages.md` §4bis-4quater) :

| Classe | Source |
|---|---|
| Barde, Druide, Warlock | HasLab *Mythic Tier* (2021) |
| Rogue | *Rogue Heir of Elethorn* (Hero Collection) |
| Moine | *Path of the Wandering Monk* (Hero Collection) — 8 techniques |
| Chevalier | *Commander of the Guardian Knights* (Hero Collection) — bouclier de départ |
| Berserker, Explorateur | *Jungles of Delthrak* |

Sir Ragnar, héros retiré au plateau, apparaît comme figure de *Dread Moon* et comme allié nommé de *Wizards of Morcar*. Les règles qui encadrent tout ça : [`docs/regles/bestiaire-et-rencontres.md`](docs/regles/bestiaire-et-rencontres.md) (thèmes, boss, coûts mesurés) et [`docs/regles/sorts-heros.md`](docs/regles/sorts-heros.md) (classes lanceuses de sorts).

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

Au plateau, une carte d'équipement dit son effet en une phrase — *« This weapon allows you to attack diagonally »*, *« Two-handed »*, *« a 2 square movement penalty »*. Ici l'effet est une **donnée** : chaque carte est convertie en **statistiques** (prix, dés) + **mots-clés** d'un vocabulaire fermé, `App\Engine\MotsClesEquipement`. Le paquet porté est le **paquet OFFICIEL Hasbro**, photographié par René : 20 cartes d'équipement et 15 de potions, chacune tamponnée © 2021/2022/2023. Il a remplacé le 2026-08-15 la révision fan de Ye Olde Inn, dont l'auteur écrivait lui-même avoir changé « some item costs and functionality » — et douze pièces qu'elle ajoutait (arcs, fouet, canne, espadon…) ont quitté le catalogue avec elle. La conversion carte par carte est en `reference/16_armurerie.md` §2.1bis, les mots en `reference/19_mots_cles_effets.md` §9.

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

Le **bestiaire** suit la même discipline. Les monstres de base (les 8 cartes plus l'Abomination) viennent des **cartes monstre officielles** scannées par René (`reference/20_cartes_monstres.md`) : quand un paquet de fans les contredit, la carte gagne. Les **56 créatures d'extension** (voir [🧩 Extensions](#-extensions)) viennent des **livrets officiels** via `reference/18_extensions.md`.

Six **traits d'extension** sont dans le moteur depuis le 2026-08-10 : `agile` (ignore mobilier et figures, pas les murs), `racines_entravantes` (arrête net le héros qui entre au contact), `venimeux` (paralysie, sauf 5-6 sur un d6), `tacticien` (+1 dé contre une cible flanquée, puis décrochage), `spawn` (engendre la créature nommée par la capacité, qui **s'accroche** au héros en jeton cumulable — 1 PV automatique et indéfendable par fin de tour, que le **porteur ou un compagnon au contact** arrache, un par crâne) et `ethere` (traverse tout, mais ne s'arrête ni sur une case occupée ni dans une salle non découverte — et **seul un bouclier noir le touche**, sauf sort ou artefact). Porter le venin a obligé à câbler `deplacement_interdit`, une clé de condition qui n'avait **aucun lecteur** : un héros « Immobilisé » marchait normalement.

Les **artefacts**, eux, restent une conversion Ye Olde Inn : `sjeng-artefacts.pdf` (34 cartes, cinq sources officielles) — aucune photo du matériel officiel ne les couvre encore. Ils remplacent 7 artefacts inventés qui ne faisaient que monter la courbe des dés — 4, 5, puis 6 — là où un vrai artefact fait ce que rien d'autre ne fait : frapper deux fois un orque, blesser à coup sûr, porter des PV en plus. Dix-sept cartes sont portées, **17 ne le sont pas** et le doc 16 §9.1 dit pour chacune la mécanique qui lui manque (types de dégâts, téléportation, contrôle de monstre…). Un cinquième emplacement, `talisman`, accueille les quatre bijoux de classe.

Deux mécaniques ont été ouvertes le 2026-08-09 pour débloquer sept de ces cartes d'un coup. Les **charges** disent « cet exemplaire-ci a N utilisations » — ce que `quantite`, qui compte des exemplaires identiques, ne savait pas dire : un arc à quatre flèches est un seul objet utilisable quatre fois. À zéro il devient inerte, il ne disparaît pas. L'**économie de sorts** dit quand un sort épuisé revient : jusque-là un simple booléen remis à zéro par quête, que seuls deux nœuds de compétence savaient contourner et qu'aucun objet ne pouvait toucher.

**Les 69 cartes vivent dans `config/cartes.php`**, et un test les confronte au catalogue dans les deux sens : toute carte marquée portée doit exister en base, aucune arme/armure/artefact ne peut exister sans carte. « Le catalogue vient des cartes » n'est donc pas une affirmation de documentation mais une propriété testée. Le même registre alimente `GET /api/guide` : la page **/guide → onglet « Cartes sources »** montre aux joueurs la provenance de chaque pièce et les cartes du plateau qui n'ont pas encore de mécanique, chacune avec ce qui lui manque.

## 🧪 Tests

Suite **Pest** (moteur sous `tests/Unit/Engine`, jeu sous `tests/Feature`) — **2 519 tests** (88 699 assertions).

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
- Une campagne complète = **MariaDB et la bible Qdrant** — à sauvegarder ensemble, une base
  restaurée sans sa bible rend le RAG muet. `./image-tools/sauvegarder.sh` prend les deux.
- Les deux vivent dans un **dossier de l'hôte** (`CHEMIN_DONNEES`, par défaut
  `../heroquest-donnees`), **hors du dépôt** — pas dans un volume Docker nommé. Deux raisons :
  `docker compose down -v` ne retire que les volumes **nommés**, donc les données y survivent ;
  et hors du dépôt parce que `git clean -xfd` supprime aussi les fichiers ignorés.

## 🛣️ Reste à faire

**Court terme**

- **Équilibrage** : régler stats / prix / difficultés en playtest (la tactique du bot de test est limitée — un humain place mieux ses héros).
- **Assets** : (re)générer les illustrations / voix manquantes selon le budget API ; affiner les prompts de style (`config/images.php`) au besoin.
- **Restes du dernier relevé** (`docs/correctifs-restants.md` §0) : voix du narrateur muette sur l'issue `errant` d'un coffre ; épilogue de défaite qui oublie les quêtes gagnées ; narration décorrélée d'une action (**à reproduire avant de corriger**) ; porte sans option « Ouvrir » (à reconfirmer en jeu). L'onglet narrateur en arrière-plan > 1 min reste écarté **par choix** (piste : battement en Web Worker).

**Extensions** (voir [🧩 Extensions](#-extensions))

- *Prophecy of Telor* : construire la fuite (quête 12) et la mort par tirage (quête 13) de Fellmarak, puis rallumer le thème.
- *Kellar's Keep*, *Return of the Witch Lord*, *Spirit Queen's Torment*, *The Crypt of Perpetual Darkness* : pas encore de thème (plans dans `docs/plan-*.md`).

**Phase 2** (doc 00 §8) — déjà livrés : alliés recrutables, lanceurs de Dread autonomes, monstres à phases, pièges magiques. Restent :

- **Marchandage** : un seul marchand, tout au prix normal, **par décision** (René, 2026-09-12) — les quatre profils de `ProfilMarche` sont la matière première de la négociation, pas du code mort.
- **Économie dynamique** et **événements de ville dynamiques** : rien d'engagé.
- **Ramifications profondes** : la colonne `quetes.branche_active` existe mais rien ne l'écrit encore ; l'arc reste à ramification simple.
- **Succès à coût généralisé** : le moteur détecte déjà la réussite mixte des jets (`ReussiteMixte`, P4) ; l'étendre au-delà reste à faire.
