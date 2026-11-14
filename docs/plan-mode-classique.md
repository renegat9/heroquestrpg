# Plan — Un mode Classique : campagnes construites, groupe permanent, MJ joué par le moteur

> ⚠ **Document DATÉ du 2026-11-13**, comme les autres `docs/plan-*.md`.
> Demande de René : un mode fidèle au jeu de plateau, où un groupe **permanent**
> enchaîne les quêtes d'une campagne **fixe**, où une manette peut jouer
> **plusieurs héros**, où certaines campagnes exigent un héros précis et se
> jouent avec des **spectateurs**, et où le maître du jeu est **entièrement
> géré par l'application**.
>
> Branche : `mode-classique`. Rien n'est codé à ce stade : ce plan est à valider.

## 0. Le périmètre, écrit une fois

| Ce que ce chantier livre | Ce qu'il ne livre pas |
|---|---|
| Le **vocabulaire** des mécaniques de quête fixe (déclencheurs, effets, règles de quête, conditions de fin) et leurs lecteurs moteur | Aucune quête d'un livret : ni plan, ni placement, ni texte, ni titre |
| Un **constructeur** de quêtes et de campagnes, avec import/export | Aucun fichier de campagne officielle dans le dépôt |
| Le **mode Classique** qui joue les campagnes sorties du constructeur | |
| Une ou deux **quêtes d'exemple originales**, pour valider la chaîne de bout en bout | |

Les livrets servent ici comme ils servent déjà à `reference/16`–`18` : de
**source des règles**. L'inventaire du §2 décrit des *familles de mécaniques*
avec le livret où elles apparaissent ; il ne décrit aucune quête. Ce que René
saisit ensuite dans le constructeur reste sur sa machine (fichiers d'export
hors dépôt, à côté de `~/heroquest-livrets`).

## 1. Ce qui existe déjà et que le mode réutilise

| Besoin | Déjà en place | Écart |
|---|---|---|
| Un mode de groupe | `groupes.mode` (`campagne`, `arene`), `Groupe::exigerCampagne()`, registre `EtapesCampagne` testé dans les deux sens | Ajouter `classique` ; chaque étape du registre doit répondre « que fait-elle à un groupe classique ? » |
| MJ sans IA | Le moteur joue déjà monstres, pièges, Dread ; le jeu est jouable sans clé API | En classique : **aucun** job d'IA (squelette, récits, habillage) — textes saisis dans le constructeur |
| Plusieurs héros par joueur | `POST /groupes/{id}/joueurs` accepte `personnage_ids` ; `GenererMenu` est lancé par (joueur, héros) | Le cache de menu est indexé par (groupe, **joueur**) : un seul menu par manette. La manette n'a pas de sélecteur de héros. À vérifier de bout en bout (§5) |
| Carte | `cartes.grille` en couches (cases, salles, portes, mobilier, pièges, terrain, leviers, dangers, escalier, spawns), lue par `FabriqueGrille::pour()` | Aujourd'hui **seul** `AssembleurCarte` (procédural) la produit. Il faut un second producteur, **même format**, depuis une quête construite |
| Catalogues | Bestiaire, mobilier, pièges, terrains, sorts de Dread, artefacts, symboles de carte | Rien à ajouter pour démarrer |
| Mécaniques de quête déjà portées | Monstres dormants, immunités sélectives, portes à condition, grilles et clé, trappes appariées, salle dangereuse, danger mobile, métamorphe, captif à escorter, boss à phases | Aujourd'hui posées **par tirage** ; il faut pouvoir les poser **par décision de l'auteur** |

## 2. Inventaire des mécaniques de quête fixe

Relevé sur les quatorze livrets de `~/heroquest-livrets` (base, onze
extensions, First Light et son livret de quêtes). Une ligne = une famille,
dite avec nos mots. « État » dit si un lecteur moteur existe.

### 2.1 Déclencheurs (« quand »)

| # | Déclencheur | Présent dans | État |
|---|---|---|---|
| D1 | Première **fouille de trésor** d'une salle (ou d'un meuble précis) | tous | lecteur partiel (deck de fouille, coffres) — pas de contenu **imposé** par salle |
| D2 | **Entrée** d'un héros dans une salle ou sur une case | tous | à faire |
| D3 | **Ouverture** d'une porte désignée | base, Witch Lord, Kellar's | à faire (les dormants en sont un cas) |
| D4 | **Mort** d'une figure nommée | tous | partiel (objectif « tuer le boss ») |
| D5 | **Début du tour du MJ**, à chaque tour ou à partir d'un tour donné | Witch Lord, Kellar's, Morcar | à faire |
| D6 | **Dernier héros passé** un point (effondrement derrière le groupe) | base | à faire |
| D7 | Fouille de **portes secrètes** ou de **pièges** à un endroit donné | base, Kellar's | à faire (résultat imposé : « rien », ou « un seul ») |
| D8 | Héros qui **termine son déplacement** dans une zone | Kellar's, Frozen Horror | fait pour le terrain et la salle dangereuse |
| D9 | Objet de quête **ramassé** ou **rapporté** | base, Kellar's, Witch Lord | à faire |

### 2.2 Effets (« alors »)

| # | Effet | État |
|---|---|---|
| E1 | **Lire un texte** à la table (note de salle, message d'ouverture, épilogue) | canal de narration existant ; texte à fournir par la quête |
| E2 | **Donner** or, objet du catalogue, artefact, potion au fouilleur | lecteurs existants ; à déclencher sur ordre |
| E3 | **Faire apparaître** des monstres (liste et cases fixes, ou « N par tour » tiré au dé) | invocation existante ; apparition scriptée à faire |
| E4 | **Réveiller** des figures inertes ; figure **invulnérable tant qu'elle n'a pas agi** | fait (dormants) |
| E5 | **Révéler** ou **poser** un élément : porte secrète, tuile, meuble, passage | partiel (passage secret) |
| E6 | **Fermer** un chemin : bloc tombé, porte factice, porte que seul le MJ ouvre | partiel (chute de blocs ; fausse porte à faire) |
| E7 | **Dégâts** ou **jet** imposé (dé de combat à l'entrée, piège de coffre avec valeur fixée) | lecteurs existants (`MoteurDegats`, `MoteurPieges`) |
| E8 | **Déplacer** un héros : téléportation, sortie tirée au dé (salle tournante), glissade | partiel (trappes appariées) ; salle tournante à faire |
| E9 | **Remplacer** une figure par une autre (métamorphe, boss à formes) | fait |
| E10 | **Marquer** un drapeau de quête, relu par un autre déclencheur ou par la condition de fin | à faire — c'est la pièce qui relie tout |

### 2.3 Règles de quête (valables toute la quête)

| # | Règle | Présent dans | État |
|---|---|---|---|
| R1 | **Monstre errant** désigné | tous | `MonstreErrant::deLaQuete()` — à alimenter par la quête |
| R2 | **Départ** ailleurs qu'à l'escalier (salle désignée, porte d'entrée en bois) | base, extensions | à faire (l'escalier est supposé en salle 0) |
| R3 | **Sortie** par un autre point que l'entrée (porte de sortie, escalier en colimaçon), héros retiré à l'arrivée | extensions | à faire (notre sortie = vote en salle de départ, décision du 2026-10-10 — voir Q4) |
| R4 | **Équipement confisqué** jusqu'à un point de la carte ; sorts interdits d'ici là | base | à faire |
| R5 | **Charge à porter** qui réduit le déplacement | base | `MoteurFardeau` existe — à brancher |
| R6 | Zones **non fouillables**, pièges **introuvables** ou **non désarmables** | plusieurs | partiel (`MotsClesPiege`) |
| R7 | **Quête solo** : un seul héros d'une classe donnée ; mercenaires interdits | Frozen Horror, Mage du Miroir | à faire (§6) |
| R8 | **Marché fermé** ou service interdit avant la quête | Dread Moon, Frozen Horror | à faire (drapeau lu par le hub) |
| R9 | **Monstre nommé** avec bloc de stats et sorts propres à la quête | tous | fait pour ceux du catalogue ; bloc **saisi par l'auteur** à faire |
| R10 | **Quête double** : deux plateaux enchaînés sans retour au hub | Frozen Horror, Mage du Miroir | à faire |
| R11 | **Tuiles en surimpression** qui redessinent une zone du plateau | Witch Lord et suivantes | partiel (terrain, tuiles du vivier) |

### 2.4 Conditions de fin et récompenses

| # | Condition | État |
|---|---|---|
| F1 | Tuer une figure nommée | fait |
| F2 | Trouver un objet, puis sortir | à faire |
| F3 | Atteindre un point (s'évader) | à faire |
| F4 | Ramener un captif ou une charge | captif fait ; charge à faire |
| F5 | Récompense : or **à partager**, or **par héros**, objet ; versée à la sortie ou à la campagne | or de quête existant ; règles de partage à faire |
| F6 | Échec : tous les héros tombés ; conséquence sur la suite | TPK existant |

### 2.5 Structure de campagne

| # | Élément | Présent dans | État |
|---|---|---|---|
| C1 | Suite **linéaire** de quêtes, texte d'ouverture par quête | tous | à faire |
| C2 | Campagne **ramifiée** (la quête suivante dépend d'un choix ou d'un résultat) | Delthrak | à faire (drapeaux E10 portés au niveau campagne) |
| C3 | **Prérequis de héros** pour toute la campagne ou pour certaines quêtes | Frozen Horror, Mage du Miroir, Dread Moon, Telor, Spirit Queen | à faire (§6) |
| C4 | **État reporté** d'une quête à l'autre (objet de quête conservé, figure qui ne peut être blessée qu'avec l'objet de la quête suivante) | base, Witch Lord | drapeaux de campagne |
| C5 | **Ressource de groupe** propre à une boîte (réputation, statut) | Dread Moon, Morcar | Gardien fait ; réputation à faire |

**Ce que l'inventaire montre** : presque tout se ramène à
`déclencheur → effets`, plus une petite liste de règles de quête et une
condition de fin. Une vingtaine de mots-clés couvrent l'essentiel ; le reste
(§2.6) est rare et se traite au cas par cas, après.

### 2.6 Reporté à une seconde vague, nommément

Règle de la maison (« retirer du contenu est un choix écrit ») : ces
mécaniques sont **reconnues**, pas oubliées, et hors de la première livraison.

- Déguisement et place publique (Dread Moon), obscurité magique (Crypte),
  tours élémentaires à ordre libre et dégâts convertis en Mind (Spirit Queen),
  glyphes (First Light) : chacune est une règle d'**une** boîte, avec son
  propre écran.
- Salle tournante (E8) et quête double (R10) : faisables, mais chacune touche
  la carte en cours de quête.
- Modes de mort au choix (Delthrak) et ordre de tour choisi par les joueurs.

## 3. Le format d'une quête et d'une campagne

Un document JSON versionné, validé par schéma **et** par le moteur contre les
catalogues (même discipline que les sorties d'IA : rejet nommé, jamais de clé
inconnue acceptée en silence).

```
campagne  { format, titre, auteur, mention, prerequis_heros[], quetes[], suite{} }
quete     { titre, ouverture, monstre_errant, regles{}, plateau{}, notes[], fin{}, recompense{} }
plateau   { salles_ouvertes[], portes[], blocs[], mobilier[], monstres[], pieges[],
            terrain[], depart{}, sortie{} }
note      { repere, quand{ declencheur, cible }, alors[ {effet, …} ], une_fois }
```

- **Vocabulaire fermé** : `App\Engine\MotsClesQuete` (déclencheurs D1–D9,
  effets E1–E10, règles R1–R9, fins F1–F5). Ordre imposé par le projet :
  entrée au vocabulaire → lecteur sur une couture existante → test **en jeu**
  → seulement alors l'option dans le constructeur. Un mot-clé que le
  constructeur propose et que rien ne lit serait une clé décorative.
- **Références par slug de catalogue**, jamais par id : c'est ce qui rend
  l'export portable d'une machine à l'autre. L'import refuse un slug inconnu
  en le nommant.
- **Plateau fixe** : le plateau classique est une donnée unique (26×19, ses 22
  salles et ses couloirs). Une quête dit quelles salles sont ouvertes et ce
  qui s'y trouve ; elle ne redessine pas le plateau.
- **`mention`** : champ libre de crédit et de copyright, affiché à l'ouverture
  de la campagne.

### Tables

- `campagnes_construites` (titre, auteur, mention, `document` JSON, version de
  format, empreinte) et `quetes_construites` (campagne, rang, `document`).
- `groupes.campagne_construite_id` + `groupes.rang_quete` : la progression est
  un état durable, donc en colonne.
- `quetes.drapeaux` (E10) et `groupes.drapeaux_campagne` (C4) : en colonne.
- `quetes.notes_jouees` : quelles notes ont déjà tiré — en colonne, jamais en cache.

## 4. Le producteur de carte et le moteur de notes

- **`App\Partie\Classique\CarteConstruite::produire()`** rend exactement la
  forme que rend `AssembleurCarte::assembler()`. Tout l'aval
  (`FabriqueGrille::pour()`, brouillard, menus, table, manette) reste
  inchangé. Un test compare les **clés** des deux producteurs, dans les deux
  sens : une couche ajoutée à l'un doit exister chez l'autre.
- **Garde-fous de jouabilité** appliqués à la validation, pas à la pose : le
  constructeur **signale** une salle sans case libre ou un objectif
  inatteignable, il ne déplace rien — l'auteur décide.
- **`App\Partie\Classique\NotesDeQuete`** : le point de passage **unique** des
  déclencheurs. `ResolveurTour`, `MoteurPortes`, la fouille et le tour du MJ
  appellent `NotesDeQuete::signaler($quete, $declencheur, $contexte)` ; lui
  seul lit les notes, marque `notes_jouees`, et délègue chaque effet au moteur
  qui le résout déjà (`MoteurDegats`, invocation, dormants, portes…).
- **Annonce** : chaque effet de note passe par `TamponAnnonces` et entre dans
  `JournalCombat::TYPES`. Un effet automatique muet est injouable.
- **Fouille** : en classique, la salle sans note pioche dans le paquet de
  trésor ; la salle avec note D1 rend ce que la note dit. Un seul point de
  passage décide lequel des deux s'applique.

## 5. Plusieurs héros sur une manette

- **Affectation** : `groupe_personnages.pilote_joueur_id` (nullable = le
  propriétaire). Un héros garde son propriétaire ; le **pilote** est celui qui
  le joue ce soir. C'est ce qui couvre les deux cas demandés : l'absent
  (son héros confié à un présent) et la table à deux joueurs.
- **Un seul prédicat** : `Groupe::piloteDe(Personnage)`, lu par
  `ChoixController::personnageLegal()`, `GenererMenu`, `MenuCourant` et les
  canaux. Aujourd'hui `joueur_id` est testé à une trentaine d'endroits dans
  les contrôleurs ; le lot M1 commence par les recenser et les faire passer
  par le prédicat.
- **Menu** : ~~la clé de cache devient (groupe, joueur, héros)~~ — **révisé à
  l'exécution** (2026-11-13). La case reste (groupe, **pilote**) : un seul
  acteur a la main à la fois, l'entrée nomme son héros, et `MenuCourant`
  rejette déjà une entrée qui ne désigne pas l'acteur. Changer la forme de la
  clé aurait touché 173 usages dans 85 fichiers de test pour rien. Ce qui
  change : le héros servi est celui qui **a la main** parmi les héros pilotés,
  et le menu d'attente d'un héros hors tour n'écrase plus celui de l'autre.
  Le serveur publiera `heros_pilotes[]` (M2) — la manette n'en déduit rien.
- **Manette** : un bandeau de héros en tête ; elle bascule seule sur le héros
  dont c'est le tour. Hors tour, on consulte les fiches librement.
- **Hub** : achats et équipement héros par héros, avec le même bandeau.
- **Prêt** : un joueur prêt l'est pour tous les héros qu'il pilote.
- **Reprise en main** : le propriétaire qui revient reprend son héros au hub,
  jamais en cours de quête.

## 6. Prérequis de héros, quêtes solo, spectateurs

- **Prérequis de campagne** (`prerequis_heros[]`, par classe) : la campagne
  n'est proposée que si le groupe contient la classe ; sinon elle apparaît
  grisée avec la raison (« exige un Barbare »). Décision publiée par le
  serveur.
- **Quête solo** (`regles.solo = {classe}`) : seul le héros désigné entre.
  Les autres héros du groupe ne sont pas posés, ne risquent rien et ne gagnent
  rien.
- **Spectateur** : une manette sans héros dans la quête affiche la carte, le
  journal et la fiche du héros en jeu, **sans menu**. Le serveur publie
  `role = spectateur` ; aucun job de menu n'est lancé pour elle, donc aucune
  manette n'attend un menu qui ne viendra pas.
- **Mercenaires** : interdits en solo par la règle de quête, pas par
  l'interface seule — le menu ne propose pas ce que le résolveur refusera.

## 7. Le groupe permanent

- Un groupe classique **n'est jamais clos** : `ClotureCampagne` lui est fermée
  (entrée au registre `EtapesCampagne`, refus nommé). Finir une campagne
  ramène au **choix de campagne**, héros, or et équipement conservés.
- **Héros mort** : voir Q2 — c'est le point où « fidèle » et « permanent » se
  contredisent.
- **Purge** : jamais automatique. Seul `partie:purger`, avec sa confirmation,
  peut retirer un groupe classique.
- **Or** : voir Q3.

## 8. Le constructeur

Un écran de bureau (pas la manette), route `/constructeur`.

- **Plateau** : le plateau fixe, salles cliquables (ouverte / fermée),
  palettes par couche tirées des catalogues (mobilier, monstres, pièges,
  terrain, portes, blocs), avec les symboles de carte déjà en service.
  Rotation, gomme, annuler/refaire.
- **Notes** : un repère (A, B, C…) posé sur une salle ou une case, puis
  « quand → alors » choisi dans des listes **publiées par le serveur** depuis
  `MotsClesQuete`. Aucune liste codée en dur côté client.
- **Fiche de quête** : titre, texte d'ouverture, monstre errant, règles,
  condition de fin, récompense.
- **Campagne** : ordre des quêtes, prérequis de héros, mention.
- **Validation** : le serveur rend la liste des refus (slug inconnu, note sans
  lecteur, objectif inatteignable) ; une campagne invalide ne peut pas être
  publiée dans le mode Classique.
- **Essai** : « jouer cette quête » dans un groupe d'essai jetable, purgé par
  son propre service à double verrou, comme l'arène.
- **Import / export** : un fichier `.json` par campagne. L'export est le
  document lui-même, plus version de format et empreinte.

## 9. Lots, dans l'ordre

| Lot | Contenu | Dépend de |
|---|---|---|
| **K0** | `groupes.mode = classique`, `estClassique()`, registre `EtapesCampagne` complété et testé dans les deux sens, aucun job d'IA | — |
| **K1** | Format + schéma + tables + import/export + validation contre les catalogues | K0 |
| **K2** | `CarteConstruite::produire()` sur le plateau fixe, test de parité des clés, démarrage d'une quête construite | K1 |
| **K3** | `MotsClesQuete` + `NotesDeQuete` : D1, D2, D3, D4 × E1, E2, E3, E4, E7, E10 ; fins F1–F3 ; récompense F5 | K2 |
| **K4** | Campagne linéaire : enchaînement, hub entre deux quêtes, groupe permanent (§7) | K3 |
| **M1** | Pilote : prédicat unique, clé de menu par héros, payload | K0 |
| **M2** | Manette multi-héros, hub compris | M1 |
| **S1** | Prérequis de héros, quête solo, spectateur | K4, M1 |
| **B1** | Constructeur : plateau et couches | K2 |
| **B2** | Constructeur : notes, fiche, campagne, validation, essai | K3, B1 |
| **K5** | Seconde série : D5–D9, E5, E6, E8, R2–R6, R8, R9, F4, C2, C4 | K4 |
| **X1** | Deux quêtes d'exemple **originales**, jouées par le harnais d'agents | B2 |
| **K6** | §2.6, une mécanique à la fois, chacune sur décision de René | K5 |

M1–M2 ne dépendent pas du reste et servent aussi le mode campagne actuel :
ils peuvent partir en premier.

## 8 bis. Un serveur MCP pour le constructeur (René, 2026-11-13)

> « Il faudrait un MCP pour le constructeur afin qu'une IA puisse interagir avec. »

Le constructeur a deux clients : l'écran (§8) et une IA, par le protocole MCP.
Les deux passent par les **mêmes services** — c'est la règle « une règle, un
point de passage » appliquée à l'édition : un outil MCP n'a ni sa validation
ni son format, il appelle ce que la route HTTP appelle.

| Outil | Ce qu'il fait | Service appelé |
|---|---|---|
| `catalogues` | Liste les slugs posables, par couche (mobilier, monstres, pièges, terrain, portes) et le vocabulaire `MotsClesQuete` | le même que la palette de l'écran |
| `plateau` | Rend le plateau fixe : salles, couloirs, cases, coordonnées | `PlateauClassique` |
| `campagnes` / `campagne` | Liste, lit, crée, renomme une campagne construite | `CampagnesConstruites` |
| `quete_lire` / `quete_ecrire` | Lit ou remplace le document d'une quête | idem |
| `quete_modifier` | Applique des opérations ciblées (ouvrir une salle, poser, retirer, ajouter une note) sans renvoyer tout le document | idem |
| `valider` | Rend la liste des refus du serveur, nommés | `ValidationQuete` |
| `apercu` | Rend le plateau de la quête en texte (grille de symboles) pour qu'une IA relise ce qu'elle a posé | `CarteConstruite::produire()` |
| `exporter` / `importer` | Le fichier `.json` d'une campagne | idem import/export de l'écran |

- **Aucun outil ne joue** : le MCP édite, il ne démarre pas de quête et ne
  touche à aucun groupe. Les données de partie restent hors de sa portée par
  construction, pas par prudence.
- **Accès — lot P0 tranché le 2026-11-13** (étude d'un agent, dans un dossier
  jetable ; le dépôt n'a pas été touché) :
  - **Paquet** : `laravel/mcp` `^1.2`. Vérifié par un `composer require` réel
    hors dépôt : une seule entrée au verrou, **aucun** paquet existant ne
    change de version, PHP 8.3 accepté.
  - **Transport** : **stdio**, lancé par
    `docker compose exec -T app php artisan mcp:start constructeur`. Ni port,
    ni jeton, ni réseau : la surface d'exposition est nulle, ce qui convient à
    un outil qui n'a de sens que sur la machine de René.
  - **HTTP** : seulement si un client distant le demande un jour ; alors un
    middleware maison (jeton porteur comparé à une variable du `.env`, refus si
    vide), **pas** `auth:joueur` — une IA s'y ferait passer pour un joueur.
  - **Pièges relevés, non encore éprouvés** : tout avertissement PHP écrit sur
    la sortie standard casse le protocole (lancer avec les erreurs sur la
    sortie d'erreur) ; une session MCP ouverte garde l'ancien code après un
    changement PHP, comme les workers de file ; une réponse d'outil trop longue
    est tronquée côté client, donc l'export d'une campagne se rend par quête.
- **Contenu** : l'outil est indifférent à ce qu'on y saisit, comme l'écran.
  Le périmètre du §0 ne change pas : aucune quête de livret n'entre dans le
  dépôt, et ce n'est pas Claude qui les saisit.

| Lot | Contenu | Dépend de |
|---|---|---|
| **P0** | Choix du paquet MCP, du transport et de l'authentification (étude) | — |
| **P1** | Serveur MCP : `catalogues`, `plateau`, `campagnes`, `quete_lire`, `quete_ecrire`, `valider`, `exporter`, `importer` | K1, P0 |
| **P2** | `quete_modifier`, `apercu` | K2, P1 |

## 9 bis. Avancement

- **M1, côté serveur — fait le 2026-11-13** (non committé) :
  - `groupe_personnages.pilote_joueur_id` ; `Groupe::piloteDe()`,
    `piloteDuPivot()` et `herosPilotesPar()` — la règle « colonne vide = le
    propriétaire » écrite **une** fois ;
  - menu, choix, aperçu de trajet, réactions et purge branchés sur le pilote ;
  - routes du hub (équipement, dons, forge, entraînement, talents, potions,
    faveurs, sorts, mercenaires, compagnon, « prêt ») : elles contrôlent le
    **pilote** ; marché, votes, clôture, sauvegardes et lecture de l'état
    contrôlent l'**appartenance** (`PilotageHeros::estMembre()` : piloter ou
    posséder un héros actif) ;
  - `PUT /api/groupes/{identifiant}/pilote` (`App\Partie\PilotageHeros`), au
    hub seulement, fermée à l'arène (`EtapesCampagne::PILOTAGE`) ;
  - `pilote_joueur_id` publié par héros dans `GET /etat` ;
  - un joueur qui quitte le groupe rend les héros qu'il pilotait.
  - Tests : `MenuPlusieursHerosTest` (3), `PilotageHerosTest` (10).
- **Qui décide, qui a une part — tranché le 2026-11-13.** René : « je te
  laisse gérer selon ce que tu comprends des règles du jeu et ce qui fait du
  sens ». Au plateau, le héros d'un absent est joué par un présent, qui décide
  pour lui. Deux notions, écrites une fois dans `PilotageHeros` :
  - **les joueurs à la table** (`joueursALaTable()`, ceux qui pilotent) :
    ils **votent** et font le **marché**. Un absent dont le héros est confié
    n'a ni bulletin ni panier, et ne bloque personne ;
  - **les propriétaires** (`proprietaires()`) : ils gardent leur **part** du
    pot, quittent le groupe ou en sont retirés avec **leurs** héros, et un
    groupe sans propriétaire est vide.
  Les deux copies de `membres()` (marché, votes) passent par là.
- **Laissé tel quel** : le « prêt » d'un héros vit en cache (4 h) — état
  d'avant-quête, pas un état de partie.
- **M2, la manette — fait le 2026-11-13** :
  - la manette suit le héros du menu reçu (`personnage_id`) ; bandeau de héros
    quand elle en pilote plusieurs, verrouillé pendant le tour d'un héros ;
    « prêt » envoyé pour chaque héros piloté ;
  - `EtatGroupe::prets()` : UN constructeur du roster du hub (il y en avait
    trois), qui publie `pilote_joueur_id` et `classe` — au hub `entites` est
    vide, c'est là que la manette lit qui pilote quoi ;
  - `GET /moi` → `heros_pilotes[]` : la fiche d'un héros confié, hors du
    roster ; les décisions de forge de `/moi` suivent le pilote.
  - Suite complète : 3580 tests verts.
  - ⚠ **Jamais vu à l'écran.** Ni le bandeau, ni son verrouillage, ni le rendu
    à 360 px. La reprise d'une réaction après rechargement a été réécrite et
    n'a pas été jouée. À vérifier par une partie de test à deux héros par
    manette avant de tenir le lot pour bon.
- **Reste de M2** : l'écran qui confie un héros (la route existe, aucun bouton
  ne l'appelle encore).
- **Leçon du 2026-11-13** : ce dossier est SERVI en direct par la stack. Une
  migration écrite ici manque à la vraie base tant qu'elle n'est pas
  appliquée, et le code qui la lit est déjà en service. Toute migration
  s'applique donc aussitôt écrite — sauvegarde relue d'abord — ou le travail
  se fait dans un dossier séparé.

## 10. Décisions de René (2026-11-13)

| # | Décision | Conséquence |
|---|---|---|
| **Q1** | **Oui** : un profil de règles `classique`, sans nos écarts. « Mais la dague se perd au lancer selon les règles, non ? » | Vérifié : **René a raison**. La carte d'équipement *Dagger* (`reference/16_armurerie.md` §2.1bis) dit qu'elle peut être lancée et qu'elle est **perdue** une fois lancée. La perte n'est donc pas un écart et **reste** en classique. La phrase de `CLAUDE.md` qui la range parmi les divergences date d'avant les photos des cartes : à corriger. Les écarts réellement retirés en classique : déplacement (deux dés rouges, sans base de classe), niveaux et talents, forge, entraînement |
| **Q2** | Le groupe n'est **jamais dissous**. À la création, on choisit le mode de mort : **Story** ou **Heroic** (*Jungles of Delthrak*) | `groupes.mode_mort`. **Story est déjà en service** (`ResolveurTour::relevementsStory()`, décision du 2026-10-09) : c'est la politique de tous les groupes aujourd'hui. **Heroic est à construire** (lot H1) : état « hors de combat » avec jeton crâne posé au début du tour suivant, mort si le tour d'après commence avec le jeton. Voir Q7 |
| **Q3** | **Bourse par héros**, échange possible **entre les quêtes** | En classique, l'or n'est pas versé au pot commun à l'arrivée ; `personnages.or` est la bourse. L'échange d'or passe par la séance d'échange du hub (`SeanceEchange`), jamais en quête |
| **Q4** | **Sortie case par case** (porte de sortie ou escalier) | R3 entre dans la première série. Le vote en salle de départ (2026-10-10) reste la règle du mode campagne et **n'existe pas** en classique : entrée au registre, refus nommé |
| **Q5** | **Pas de spoiler** : le spectateur voit ce que le héros voit | La manette spectatrice lit le même état que la manette du héros en jeu (brouillard compris), jamais l'état complet |
| **Q6** | « Fais ce qu'il te semble le mieux » | Ordre retenu : **M1 → M2** (manette multi-héros, utile dès maintenant), puis K0 → K4, S1, B1 → B2, H1, K5, X1 |

### Lot ajouté

| Lot | Contenu | Dépend de |
|---|---|---|
| **H1** | `groupes.mode_mort` (`story`, `heroic`), choisi à la création d'un groupe classique et publié ; lecteur Heroic (jeton crâne, minuteur par héros, en colonne), annoncé et journalisé | K0 |

### Q7 — Mort en Heroic : tranché par délégation (2026-11-13)

René n'a pas répondu à Q7 et m'a laissé décider. Décision : **suivre le
livret de règles**, sans rien inventer.

- Un héros mort en Heroic est **perdu**. Sa **place** dans le groupe reste :
  son joueur crée un héros neuf (équipement de départ de sa classe), qui la
  prend au hub suivant. Le groupe n'est donc jamais dissous, même sur une mort.
- Le sort de l'**équipement** du mort n'est sourcé dans aucun de nos
  documents (`reference/16` ne cite que l'*Élixir de Vie*). Il sera lu dans le
  livret de règles et dans la page des modes de *Jungles of Delthrak* **au
  début du lot H1**, puis porté tel qu'écrit. « ⚠ non trouvé » vaut mieux
  qu'une supposition : rien n'est codé avant cette lecture.
