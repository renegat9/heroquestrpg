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
- **Le plateau est une DONNÉE de la campagne, pas du code** (révisé le
  2026-11-13, à l'ouverture de K1). `campagne.plateau` décrit des dimensions
  et des salles rectangulaires ; tout le reste est couloir. Une quête dit
  quelles salles sont ouvertes et ce qui s'y trouve ; elle ne redessine pas le
  plateau. Deux raisons : le format reste générique (une extension peut avoir
  un autre plateau, René peut dessiner le sien), et le dépôt ne livre aucun
  plateau tiré d'une boîte — seulement un plateau d'exemple **inventé**.
- **Géométrie : celle du MOTEUR, où un mur est une case** (décidé le
  2026-11-13 à l'ouverture de K2, René ayant délégué). Dans ce moteur
  `cases[y][x] === 'm'` est un mur, une salle est un intérieur entouré d'un
  **anneau de cases de mur**, et une porte occupe une case de cet anneau
  (`Grille::caseEmbrasure()`). Une salle du plateau `{x, y, l, h}` décrit donc
  son **intérieur** ; son anneau l'entoure ; est **couloir** toute case qui
  n'est ni un intérieur ni un anneau. Deux salles peuvent partager une case de
  mur, jamais moins.
  - Conséquence assumée : un plateau dessiné ainsi est plus grand qu'un
    plateau où les murs sont des traits. Passer aux murs en arêtes est le
    chantier de `docs/plan-murs-en-aretes.md` (six fichiers structurants, à
    faire « à froid ») : il n'est pas ouvert ici. Le jour où il l'est, seul
    `CarteConstruite` change, pas le format.
  - `depart` désigne le coin haut-gauche d'un escalier 2×2, dans une salle
    ouverte : c'est la salle de départ, la salle 0 du moteur. Le départ depuis
    un couloir ou une porte d'entrée (R2) vient avec le lot K5.
- **K1 ne porte pas les notes.** `notes[]` et `MotsClesQuete` entrent au lot
  K3, avec leurs lecteurs : un mot-clé que la validation accepterait sans que
  rien ne le joue serait une clé décorative. K1 = le contenu **statique**
  d'une quête (salles ouvertes, portes, blocs, mobilier, monstres, pièges,
  terrain, départ, monstre errant, textes, récompense).
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
  - **Vu à l'écran le 2026-11-13**, sur la vraie stack, par une partie de test à
    deux joueurs et deux héros chacun (`browser-shots/campagne/preparer-deux-heros.sh`,
    captures `browser-shots/m2-*.mjs`, partie nettoyée ensuite) :
    - au hub, le bandeau montre les deux héros, la pastille change le héros
      affiché, et UN appui sur « Prêt » prête les deux héros du joueur ;
    - en quête, le héros qui a la main porte « Son tour », l'autre pastille est
      verrouillée ; à la fin de son tour la manette bascule SEULE sur le second
      héros, avec sa fiche et son menu ;
    - aucun débordement à 412 ni à 360 px.
    - Défaut trouvé à l'écran et corrigé : entre l'état et l'arrivée du menu
      du héros suivant, la manette restait une à deux secondes sur le héros
      précédent, sans choix. Elle suit maintenant `a_la_main` dès que l'état
      le publie (mesuré : le bon héros à +2,1 s, ses choix à +2,7 s).
    - Au hub, les pastilles montrent l'icône de classe et non le portrait : le
      roster du hub ne publie pas d'image. Cosmétique, laissé tel quel.
  - ⚠ **Pas encore vu** : un héros CONFIÉ (pilote ≠ propriétaire) à l'écran —
    la partie de test faisait posséder ses deux héros à chaque joueur ; et la
    reprise d'une réaction après rechargement de la manette, réécrite au lot.
- **Reste de M2** : l'écran qui confie un héros (la route existe, aucun bouton
  ne l'appelle encore).
- **K1, le format d'une campagne construite — fait le 2026-11-13** (`bccf855`) :
  document de format 1, `CatalogueConstructeur` (une fonction de slug, un
  index par couche), `ValidationCampagne` (21 codes de refus, testés dans les
  deux sens), `CampagnesConstruites` (lister, lire, enregistrer, exporter avec
  empreinte, importer), tables `campagnes_construites` et
  `quetes_construites`, six routes sous `/api/constructeur`.
  - Tests : 89 dans `tests/Feature/Classique`. Suite complète dans la copie de
    l'agent : 3668 passés, 1 échec — la page d'accueil, faute de
    `public/build/` dans une copie neuve ; ce test passe dans le dossier servi.
  - Migration appliquée à la vraie base (sauvegarde relue d'abord) ; les
    routes répondent sur la stack : 21 meubles, 97 monstres, 16 pièges,
    18 terrains, 12 classes au catalogue.
  - **Laissé au lot K2**, signalé par l'agent : l'emprise d'un meuble n'est
    pas contrôlée (seule sa case d'ancrage l'est) ; deux éléments peuvent
    occuper la même case sans refus.
  - **Pas de route HTTP d'import** : l'import est un service, que l'écran du
    constructeur (B2) et le serveur MCP (P1) appelleront.
  - La validation (700 lignes) a été relue dans sa structure, pas ligne à
    ligne.
- **P1, le serveur MCP du constructeur — fait le 2026-11-13** (`6c70842`) :
  `laravel/mcp` 1.2.0 (seul ajout au verrou, aucun paquet existant ne bouge),
  `App\Mcp\ServeurConstructeur`, neuf outils (`catalogues`, `campagnes`,
  `campagne_lire`, `quete_lire`, `campagne_ecrire`, `quete_ecrire`, `valider`,
  `exporter`, `importer`), chacun branché sur le service que la route HTTP
  appelle. Trois méthodes ajoutées au service (`lireSommaire`, `lireQuete`,
  `ecrireQuete`). Un refus de validation est une réponse, pas une erreur de
  protocole.
  - Tests : 131 dans `tests/Feature/Classique`, dont un registre des outils
    dans les deux sens. Suite complète dans la copie de l'agent : 3710 passés,
    1 échec attendu (page d'accueil, pas de front construit dans une copie).
  - **Éprouvé sur la vraie stack** : `initialize`, `tools/list`, `campagnes`
    et `catalogues` par le transport réel — quatre lignes JSON-RPC valides,
    sortie d'erreur vide.
  - Lancement : `docker compose exec -T app php -d display_errors=stderr
    artisan mcp:start constructeur`. Le `-d display_errors=stderr` est
    nécessaire : sans lui, un avertissement PHP s'imprime sur la sortie
    standard et casse le protocole (mesuré par l'agent).
  - ⚠ **Après un `composer install --no-scripts`, lancer
    `php artisan package:discover`** : sans cela Laravel ne connaît pas le
    paquet, la commande `mcp` n'existe pas et les 28 tests du serveur
    échouent. Constaté en ramenant le lot dans le dossier servi.
  - **Pas encore éprouvé** : le raccordement depuis Claude Code lui-même ; le
    cas d'une exception non rattrapée avec `APP_DEBUG=true`, qui pourrait
    s'imprimer sur la sortie standard.
  - `composer` a signalé 36 avis de sécurité touchant 9 paquets déjà présents :
    non analysés, sans rapport avec ce lot.
- **K2, la carte d'une quête construite — fait le 2026-11-14** (`3dc58c5`,
  puis les jonctions) : `CarteConstruite::produire()` rend la forme exacte de
  `AssembleurCarte::assembler()` (parité des clés testée dans les deux sens)
  plus la liste des monstres posés ; `apercu()` rend la quête en texte.
  - Sept contrôles ajoutés à la validation : `anneau_hors_plateau`,
    `anneau_sur_salle`, `pose_sur_mur`, `depart_hors_salle`,
    `porte_dans_la_roche`, `mobilier_hors_salle`, `case_partagee`.
  - Le moteur la joue (testé) : chemin de l'escalier au couloir par une porte
    ouverte, aucun chemin porte fermée, salle fermée et bloc infranchissables,
    embrasure conforme à `Grille::caseEmbrasure()` pour les quatre côtés.
  - **Jonctions** : chaque porte porte sa jonction (`aretes`), sans quoi le
    sort « ouvrir une porte au choix » n'offrait aucune porte. Une porte sur
    un couloir ne relie que sa salle : la porte d'une salle inconnue vue du
    couloir n'est pas offerte au sort (limite écrite, à revoir en K5).
  - Tests : 182 dans `tests/Feature/Classique`. Suite complète sur l'état
    fusionné (K1 + P1 + K2) : 3761 verts.
  - `theme` des salles : `generique`. `spawn_monstres` vide : les monstres
    voyagent à part, posés par l'auteur.
  - **Limites connues, laissées au démarrage de quête ou à K5** : une porte
    qui donne sur un bloc est acceptée ; un meuble « adossé au mur » peut être
    posé au milieu d'une salle ; la jouabilité (salle sans case libre,
    objectif inatteignable) n'est pas contrôlée ; un piège sous un meuble ou
    sous l'escalier passe. Le démarrage d'une quête construite ne doit PAS
    passer par `DeckFouille::sallesACoffre()` : les coffres y sont posés par
    l'auteur, pas tirés derrière les portes secrètes.
  - Le code de K2 a été relu dans sa production des salles et des portes, pas
    en entier.
- **Première quête jouable (K0 + K4, et la condition de fin de K3) — fait le
  2026-11-14** :
  - `groupes.mode = classique` ; le registre `EtapesCampagne::CLASSIQUE`
    répond pour chaque étape (ouverte, ou fermée avec sa raison), testé dans
    les deux sens ; refus nommé `RefusClassique` ;
  - `POST /api/classique` ouvre un groupe sur une campagne construite, sans
    aucun travail d'IA ; bourse par héros (l'or reste sur le héros) ;
  - `DemarreurQueteConstruite` : la carte de l'auteur, ses monstres à leur
    case avec les valeurs du catalogue, son texte d'ouverture ; un seul
    aiguillage du démarrage dans le contrôleur ;
  - **condition de fin** `fin: {type: "vaincre", cibles, libelle}` — entrée au
    vocabulaire, lecteur sur `Quete::objectif()` / `objectifAccompli()`, test
    en jeu, puis la donnée ; la consigne est celle de l'auteur, le moteur ne
    nomme jamais une cible ;
  - **prérequis de héros** lus au départ (la partie simple du lot S1) ;
  - fin de quête : pas de montée de niveau, la campagne avance, la
    `recompense` de l'auteur va aux bourses des héros, annoncée
    (`recompense_quete`). `recompense` avait été acceptée au lot K1 sans
    lecteur : c'est réparé.
  - Tests : `PremiereQueteJouableTest` (6, par les vraies routes).
  - **Écarts provisoires, écrits au contrat** : sortie par le vote (décision
    Q4 : case par case → lot R3) ; paquet de trésor vide (K3) ; pas d'alliés,
    recrutement fermé ; monstre errant non lu (K5) ; le marché lit le pot
    commun, que rien n'alimente en classique.
  - **Vu à l'écran le 2026-11-14**, sur la vraie stack, avec une campagne
    d'essai INVENTÉE (`browser-shots/campagne/essai-classique.json`), écrite
    par le serveur MCP (`valider`, `campagne_ecrire`, `campagne_lire`), puis
    jouée par deux comptes (`preparer-classique.sh`, captures
    `classique-quete.mjs`, partie nettoyée et campagne d'essai retirée) :
    - la quête part par la route ordinaire « prêt » ; la table montre son
      titre, la consigne de l'auteur, le vestibule, l'escalier, le texte
      d'ouverture ; la manette a son menu ;
    - la porte sur le mur partagé s'ouvre ; la salle voisine se révèle avec
      la table à sa place et les deux gobelins à leurs cases, sous leur nom
      de catalogue ;
    - les monstres jouent : un gobelin contourne la table, passe la porte et
      attaque ; un nouveau tour s'ouvre ; aucune erreur aux journaux.
    - Défaut trouvé à l'écran et corrigé : la table affichait une boîte de
      bestiaire (« First Light ») à côté d'une campagne construite. Un groupe
      classique n'en a pas : `EtatGroupe` ne la publie plus, comme pour
      l'arène.
  - **Pas encore vu** : la fin de la quête à l'écran (cible abattue, vote de
    sortie, récompense annoncée) ; les deux salles restantes ; le piège du
    couloir ; la porte secrète.
- **L'écran qui ouvre un groupe classique — fait le 2026-11-14** :
  - page du joueur : un bouton « Mode classique » sur chaque héros libre, puis
    le nom du groupe et le choix de la campagne construite ;
  - le résumé d'une campagne publie les DÉCISIONS : `jouable` (au moins une
    quête) et `prerequis_heros` ; la page n'en déduit aucune. Le libellé d'une
    classe passe par la table des classes du front, comme partout ailleurs ;
  - **vu à l'écran** sur la vraie stack, avec trois campagnes d'essai
    inventées écrites par le serveur MCP : le brouillon sans quête est grisé
    et ne se choisit pas, la campagne jouable est présélectionnée, la
    troisième dit « exige Nain » ; l'ouverture mène à la manette du groupe ;
    aucun débordement à 412 px (`browser-shots/classique-ouvrir.mjs`).
  - **Défaut trouvé à l'écran et corrigé** : au hub, la manette d'un groupe
    classique affichait le panneau « Recruter un allié », une « bourse
    commune » et le motif « deux quêtes achevées », alors que le recrutement
    lui est fermé. Le serveur publie maintenant `groupe.recrutement_ouvert`,
    lu dans le registre des étapes par `Groupe::etapeOuverte()` — la même
    garde que la route. La manette ne teste pas le mode : le jour où le
    recrutement rouvre en classique, le panneau revient sans toucher au front.
  - **Pas encore fait** : l'écran de TABLE ne propose rien pour le mode
    classique (il s'ouvre par le code du groupe, comme toute table) ; aucun
    écran ne dit, au hub, quelle campagne joue le groupe ni à quelle quête il
    en est ; le marché du hub reste celui du pot commun.
- **Sortie case par case (R3, par l'escalier) — fait le 2026-11-14** :
  - un héros debout sur l'escalier, objectif accompli, quitte le donjon par
    l'option `sortir_escalier` ; le vote de sortie est fermé au classique
    (`EtapesCampagne`), l'écart provisoire est levé ;
  - un seul prédicat, `Quete::peutSortirParEscalier()`, lu par le menu, le
    résolveur et la consigne publiée ;
  - le héros sort de la quête par le chemin d'un joueur retiré en pleine
    quête : sa ligne d'état est supprimée. Tour, monstres et brouillard se
    déduisent des héros présents — aucun de leurs lecteurs n'a eu à apprendre
    un état « sorti ». La quête est gagnée quand plus aucun héros debout ne
    reste ;
  - défaut trouvé par le test en jeu et corrigé : le menu d'un héros déjà
    sorti était recalculé avec ceux du groupe, et l'option supposait sa ligne
    d'état présente ;
  - tests : deux cas en jeu dans `PremiereQueteJouableTest` (pas de sortie
    avant l'objectif ni hors de l'escalier ; deux héros qui sortent l'un après
    l'autre, la quête gagnée au départ du dernier, chaque sortie annoncée).
  - **Pas encore fait** : un héros sorti voit une manette sans menu, sans
    message qui lui dise qu'il attend les autres.
- **Entrer et sortir par une porte (R2, R3) — fait le 2026-11-14.** René :
  « dans certaines extensions ce sont des portes qui font l'entrée et la
  sortie en quête ». Le lot K5 n'a pas attendu :
  - un ACCÈS est un escalier ou une porte ; `quete.depart` accepte les deux,
    `quete.sortie` (nullable) déclare une sortie distincte de l'entrée ;
  - une porte d'accès n'est PAS une porte du donjon : son mur reste de la
    roche et elle n'entre pas dans `carte.portes`. Raison : le menu offre à
    l'ouverture toute porte fermée (main, clé, bénédiction, souris) — rangée
    là, elle finirait par s'ouvrir sur de la roche ;
  - `Quete::sortieDuDonjon()` : les cases d'où l'on quitte le donjon, figées
    au démarrage dans `quetes.acces`. Le prédicat de sortie les lit, quel que
    soit leur type ; l'option s'appelle « Quitter le donjon par l'escalier »
    ou « … par la porte de sortie » ;
  - `carte.acces` : le serveur publie les repères que l'escalier ne dessine
    pas déjà, sous le même brouillard — une porte de sortie dans une salle
    inconnue n'est pas montrée. La carte les dessine sur leur case de mur ;
  - tests : `AccesParPorteTest` (14) — carte, validation, publication, et une
    quête jouée où l'on entre par une porte et sort par une autre.
  - **Limite** : un escalier de sortie distinct est joué et publié, mais il
    n'y a toujours qu'UN escalier dans `carte.escalier` ; le second passe par
    `carte.acces`.
- **MURS EN ARÊTES — fait le 2026-11-14/15.** René : « si on veut respecter
  le plateau, il faudrait que les portes entre deux salles ne soient pas une
  case mais le bord commun entre deux cases ». La géométrie du lot K2 (anneau
  de cases de mur) est REMPLACÉE pour les quêtes construites. C'est le
  chantier de `docs/plan-murs-en-aretes.md`, mené autrement que prévu : les
  murs en arêtes S'AJOUTENT aux cases de roche au lieu de les remplacer.
  - **Pourquoi ajouter et non remplacer** : les cartes des campagnes en cours
    et le générateur ne sont pas touchés (une carte sans `murs` se comporte
    exactement comme avant) ; la roche reste pour les salles fermées et le
    dehors, ce qui garde son sens à Traverser la Pierre ; aucune migration.
    Les quatre questions ouvertes du plan de septembre sont ainsi tranchées.
  - **Étape 1, la grille** (`1d02650`) : `Grille::definirMurs()` ; UN test
    d'arête, `areteBloquee()` (ex-`porteBloqueEntre`, 36 appels renommés) —
    mur et porte close y passent ensemble, pour le pas, la vue, les rayons,
    les poussées ; `porteSurArete()` (une porte sans embrasure) ; la vue en
    diagonale s'arrête sur un coin fermé. Le plan craignait de devoir
    réécrire la ligne de vue : elle testait déjà l'arête à chaque pas.
  - **Étape 2, les lecteurs** : le brouillard s'arrête aux murs (il avait sa
    propre copie du test d'arête, sur les portes seules) ; une porte sur une
    arête est à portée depuis l'une de ses DEUX cases ; un passage secret non
    trouvé est publié comme un mur ; `carte.murs` publié sous le brouillard.
  - **Étape 3, les quêtes construites** : une salle est exactement ses
    cases, deux salles peuvent se toucher, une porte est le bord de sa case.
    Les refus `anneau_hors_plateau`, `anneau_sur_salle` et `pose_sur_mur`
    disparaissent. `AssembleurCarte` rend `murs: []` pour la parité des clés.
  - **Étape 4, l'écran** : murs en traits, portes à cheval sur leur bord,
    portes d'accès sur le côté de leur case.
  - **Tests** : `MursEnAretesTest` (6, dont la COMPARAISON demandée par le
    plan : 576 paires de cases, murs fins contre murs épais, sans
    divergence) ; `MursEnAretesEnJeuTest` (4, par les vraies routes) ; les
    tests de géométrie de la carte construite réécrits, aperçu compris.
  - **Vu à l'écran** sur la vraie stack, avec la campagne d'essai inventée
    redessinée (vestibule et salle des gardes accolés) : murs en traits,
    porte sur le bord commun, ouverte depuis sa case, salle voisine révélée
    avec sa table et ses gobelins ; aucune erreur aux journaux.
  - **Vu à l'écran le 2026-11-15**, en complément : la carte de déplacement
    de la manette dessine les murs en traits, la porte à cheval sur son bord
    et les cases atteignables ; une PORTE D'ENTRÉE est dessinée sur le côté de
    sa case, sur le trait du mur, à la table comme sur la manette
    (`browser-shots/classique-deplacement.mjs`). Le repère est discret.
  - **Pas fait** : le générateur garde ses murs en cases (son accolement
    reste plafonné, comme avant) ; une porte de SORTIE n'a pas été revue à
    l'écran dans la nouvelle géométrie (elle suit le même dessin que
    l'entrée, et les tests la couvrent).
- **Fait le 2026-11-15 — le marché sur la bourse du héros** (Q3) :
  - **Le défaut fermé** : neuf endroits écrivaient `groupes.or` chacun de son
    côté (fouille, épreuve, reddition, transmutation, prime, butin, marché,
    levée de malédiction). En classique, l'or trouvé en quête serait parti
    dans un pot que le marché ne lit pas — et le marché aurait débité un pot
    que rien n'alimente.
  - **`App\Partie\Bourses`**, le point de passage : `verser()` (au héros qui
    trouve ; partagé quand personne en particulier ne gagne), `payer()`,
    `solde()`, `partager()`, `publiees()`. `FinDeQueteConstruite` y passe
    aussi. `Bourses::HORS_PASSAGE` nomme, avec sa raison, chaque écriture qui
    reste à part (étapes fermées au classique, reprise d'instantané) ;
    `BoursesPointDePassageTest` scanne les sources dans les deux sens.
  - **Le marché** : un achat est payé par le héros qui le reçoit, une vente
    crédite celui qui possédait la pièce ; le garde-fou devient « la bourse
    de CHAQUE héros ≥ 0 » ; la confirmation d'un panier à découvert est
    refusée sur place ; `EtatMarche.bourses` publie solde et projection.
  - **Écrans** : la manette lit la bourse du héros affiché (marché et sac),
    l'achat va au héros affiché ; la table montre une pastille par héros.
  - **Vu à l'écran et joué sur la vraie stack** (campagne d'essai inventée,
    `browser-shots/classique-marche.mjs`, `HQ_AU_HUB=1 preparer-classique.sh`) :
    la manette affiche « 40 or · Brunehaut », un bouclier à 150 la met à
    −110, la confirmation est grisée avec sa phrase et le serveur la refuse
    (422) ; la table montre une pastille par héros (« 40 → −110 ») et plus
    d'or commun ; achat et vente appliqués : 300 → 150, 40 → 140, pot à 0.
  - **Le don d'or entre héros** (Q3, « échange possible entre les quêtes ») :
    `POST /groupes/{id}/dons-or`, au hub seulement, depuis un héros qu'on
    pilote vers n'importe quel héros actif — les règles du don d'objet.
    `Bourses::donner()` (atomique), inscrit au registre `EtapesCampagne::DONS`
    à côté de `DonObjet::donner()` ; `groupe.don_or_ouvert` publie la
    décision (`Bourses::donOuvert()`, la garde de la route). Sur la manette :
    « Donner de l'or » à côté de la bourse, onglet Sac. Joué par l'interface
    sur la vraie stack : 300 → 180 et 0 → 120.
- **Fait le 2026-11-15 — les passages secrets après les murs en arêtes**
  (René : « tu t'assureras que les passages secrets sont fonctionnels »).
  - **Ce qui marchait** : la fouille trouve le passage (depuis la salle comme
    depuis le couloir), il devient une porte fermée sur son arête, s'ouvre
    depuis sa case, révèle l'autre côté, se franchit en un pas.
  - **Deux défauts trouvés et corrigés**, tous deux pour une porte SUR ARÊTE
    seulement : le talent de détection sentait le passage depuis la case d'à
    côté et pas depuis la sienne ; la Potion de vision ne le voyait que depuis
    le côté où l'auteur avait écrit la porte. `MoteurPortes::toucheLArete()`
    est le prédicat commun du contact.
  - **Tests** : `PassageSecretEnAreteTest` (7, par les vraies routes).
  - **Joué sur la vraie stack dans les DEUX modes** : en classique (campagne
    d'essai inventée) — trouvé à la fouille, ouvert, couloir révélé ; en RPG
    (carte générée, passage forcé sur le groupe d'essai) — roche tant qu'il
    est caché, porte dans son embrasure une fois trouvé, salle cachée révélée
    et atteinte. Captures `browser-shots/classique/60…72`.
  - `docs/regles/carte-donjon.md` décrit enfin les murs en arêtes.
- **Fait le 2026-11-15 — lot K3 : le paquet de trésor, le monstre errant et
  les NOTES de quête** (« quand → alors »).
  - **Paquet de trésor** : une quête construite part avec les 24 cartes du
    plateau, mélangées (`DeckFouille::PAQUET_DE_TRESOR`, désormais la SEULE
    déclaration — le seeder la recopiait quatre fois). Avant, le paquet était
    vide et « Fouiller — trésor » ne trouvait jamais rien.
  - **Monstre errant** : celui que l'auteur désigne (`monstre_errant`), lu
    par `MonstreErrant::deLaQuete()` ; une quête qui n'en désigne aucun n'en
    a pas.
  - **Document figé** : `quetes.document_construit` garde la quête telle
    qu'elle était au départ ; notes, errant et récompense s'y lisent.
  - **Vocabulaire fermé** `App\Engine\MotsClesQuete` — déclencheurs
    `fouille_tresor`, `entree` (salle ou case), `ouverture_porte`, `mort` ;
    effets `texte`, `or`, `objet`, `apparition`, `degats`, `drapeau`.
    Lecteur unique `NotesDeQuete` ; les coutures du moteur ne font que
    signaler. `quand.si_drapeau` conditionne une note à un drapeau.
  - **Fin « drapeau »** : accomplie quand une note pose le drapeau désigné.
    Elle couvre F2 (trouver un objet, puis sortir) et F3 (atteindre un point)
    sans mot-clé de plus.
  - **Validation** : une note mal formée est refusée en la nommant ; un
    drapeau lu que personne ne pose aussi (`drapeau_jamais_pose`).
  - **Tests** : `NotesDeQueteTest` (11, par les vraies routes — chaque mot
    du vocabulaire y est joué), `MotsClesQueteTest` (registre dans les deux
    sens, refus de validation).
  - **Défaut trouvé en jouant, corrigé** : « la case libre la plus proche »
    traversait un mur. Une apparition dont la case était prise naissait dans
    le couloir, derrière le mur de la salle (le monstre errant avait le même
    défaut). `CaseLibre` n'accepte plus qu'une case JOIGNABLE en trois pas
    sans traverser mur, porte close ni roche ; et la validation refuse une
    apparition dessinée sur un meuble.
  - **Joué sur la vraie stack** (campagne d'essai inventée, trois notes) :
    la fouille du vestibule répond par sa note (texte lu au bandeau du maître
    du jeu, 60 po à la bourse du fouilleur, aucune carte tirée) ; l'entrée
    dans la salle des gardes lit son texte et fait surgir un troisième
    gobelin, dans la salle. Capture `browser-shots/classique/81-…`.
  - **Écarts écrits** : le réveil de figures inertes (E4) reste une capacité
    de monstre du catalogue, pas un effet de note ; D5–D9, E5, E6, E8 : lot K5.
- **Fait — lot P2 (2026-11-15)** : `quete_modifier` et `apercu`, le serveur MCP passe à onze outils.
  - `apercu` rend en texte la carte d'une quête, telle que `CarteConstruite::apercu()` la produit ; lecture seule.
  - `quete_modifier` applique `ajouter`, `retirer` et `remplacer` sur une copie de la quête, puis enregistre la campagne
    entière par `ecrireQuete()` : une opération fautive, nommée par son rang, ou un refus de validation n'écrit rien.
  - Les opérations vivent au SERVICE (`OperationsDeQuete`, par `CampagnesConstruites::modifierQuete()`), comme le
    contrat le demande : l'agent les avait laissées dans l'outil, déplacées à l'intégration. Une quête sans `notes`
    peut recevoir sa première note par `ajouter`.
  - Tests : `tests/Feature/Classique/Mcp/` (`OutilApercuTest`, `OutilQueteModifierTest`, registre et serveur à onze outils).
    Le raccordement réel depuis Claude Code n'est pas encore éprouvé, comme pour P1.
- **Fait — confier un héros depuis la manette** (2026-11-15, reste du lot M2) : au hub, le propriétaire d'un héros le
  confie à un joueur présent, ou le lui reprend ; un absent laisse son héros à un présent.
  - Serveur : `groupe.pilotes_possibles` publie la règle même de `PUT /pilote` (`PilotageHeros::pilotesPossibles()`, le même
    ensemble que `estMembre()`), et chaque entrée de `groupe.prets[]` porte `proprietaire_joueur_id` et `confie`, décidés
    ici et non re-dérivés par l'écran. Contrat : `docs/contrat-api.md`, section « Pilote d'un héros ».
  - Front : `ConfierHeros.vue`, dans le bloc « Prêt pour la quête » de la manette, pour les seuls héros dont ce joueur est
    propriétaire. « Reprendre » se lit sur `prets[]` (tous les héros du groupe), pas sur les héros pilotés.
  - Tests : `PilotageHerosTest` — `pilotes_possibles` = exactement les joueurs que `confier()` accepte (un étranger refusé),
    `proprietaire_joueur_id` et `confie` avant, après un confier et après la reprise, liste absente hors hub.
  - Non vérifié dans un navigateur : le clic et le rendu sur téléphone restent à voir sur la vraie stack.
- **Fait le 2026-11-16 — l'écran du constructeur (lots B1/B2)** : page
  publique `/constructeur` (lien sur l'accueil), outil de bureau.
  - **Campagne** : titre, slug, auteur, mention, classes exigées.
  - **Plateau** : on trace les salles à la souris ; id et dimensions se
    corrigent dans une liste. Renommer une salle met à jour les quêtes et les
    notes qui la citent.
  - **Quêtes** : liste ordonnée ; une carte où l'on pose, à l'outil, salles
    ouvertes, portes (sur le bord cliqué, normale ou secrète), blocs,
    meubles (rotation, emprise publiée par le serveur), monstres, pièges,
    terrain, cibles de fin, entrée et sortie (escalier ou porte) ; la fiche
    (ouverture, monstre errant, récompense, fin) ; les NOTES, champ par
    champ, avec « choisir sur la carte » pour une case ou une apparition.
  - **Le serveur décide** : validation en direct (débouncée), refus affichés
    avec leur chemin, quête par quête ; le vocabulaire (couches, côtés,
    déclencheurs, effets, emprise des meubles) vient du catalogue. Un slug
    déjà pris par une AUTRE campagne demande confirmation avant d'écraser.
  - **Défaut trouvé en l'éprouvant, corrigé** : la route HTTP changeait un
    texte vide en `null` (middleware Laravel) ; elle lit désormais le corps
    brut.
  - **Éprouvé dans un navigateur** (`browser-shots/constructeur.mjs`) : une
    campagne inventée tracée, posée, validée par le serveur et enregistrée
    telle que dessinée.
  - **Pas fait** : import d'un fichier depuis l'écran (le service existe,
    pas la route) ; aperçu « en jeu » d'une quête.
- **Vu à l'écran le 2026-11-16 — un héros confié** : Confier puis Reprendre
  depuis la manette du propriétaire ; le joueur qui reçoit voit les deux
  héros dans son bandeau (`browser-shots/classique-confier.mjs`,
  `classique-pilote-recu.mjs`). Reste : la manette du propriétaire qui a tout
  confié montre encore son héros et le bouton « Prêt ».
- **Fait le 2026-11-16 — les fausses portes de sortie** (René : « une quête
  avait 2 portes de sortie mais l'une était une fausse »). Clé facultative
  `fausses_sorties` ; indiscernables de la vraie avant l'essai (repère, option
  du menu, libellé, consigne) ; la pousser coûte l'action, ne fait pas sortir
  et la révèle pour tout le groupe. Outil « Fausse porte de sortie » dans le
  constructeur ; l'aperçu les marque `x`. `FaussesSortiesTest` ; joué sur la
  vraie stack (captures `97-…`, `98-…`).
  - **Défaut corrigé en passant** : en classique, la situation du menu disait
    encore « Rejoignez la salle de départ pour quitter le donjon — il
    manque : … », la consigne du vote de sortie d'une campagne.
    `Quete::rassemblementDepart()` est inactif quand on sort en personne.
- **Fait le 2026-11-16 — une porte de sortie dans un couloir** (René : « la
  porte de sortie peut aussi être dans un corridor ») : vraie ou fausse, sur
  une case de couloir, contre le bord du plateau, de la roche ou un bloc.
  L'entrée reste dans une salle. Tests dans `AccesParPorteTest` ; vu à
  l'écran (capture `99-…`).
- **Fait le 2026-11-16 — une porte d'entrée dans un couloir** (René : « la
  porte d'entrée peut aussi être sur un mur d'un corridoir ») : mêmes règles
  que la sortie de couloir. Les héros s'installent dans ce couloir, au plus
  près de la porte en pas (`CarteConstruite::spawnsCouloir()`). Aucune salle
  n'est alors « de départ » : `quetes.acces.salle_depart` vaut `null`, la
  salle 0 n'est que la première salle ouverte et `Quete::sallesDecouvertes()`
  ne la donne plus pour connue ; un monstre qui s'y tient dort. Le couloir
  d'entrée tient lieu de salle de départ : le brouillard s'ouvre depuis la
  case de la porte (`Quete::entreeEnCouloir()`, lu par
  `EtatGroupe::appliquerBrouillard()`) — sans quoi le groupe partirait dans
  le noir complet. Pas « le couloir où se tient un héros » : essayé, cela
  changeait le brouillard de toute partie (Traverser la Pierre). Sans sortie déclarée, on ressort par cette porte.
- **Fait le 2026-11-16 — les héros attendent hors du plateau** (René, après
  la relecture de *Kellar's Keep* : « respectons la règle hors plateau » ;
  F4543 : « the heroes line up outside the door »). Par une porte d'entrée,
  chaque héros démarre SANS CASE (`EtatPersonnageQuete::horsPlateau()`), son
  menu se réduit à « Entrer dans le donjon » (la case de la porte, une case de
  son déplacement) ou « Terminer le tour » ; une porte bouchée fait attendre.
  Une ligne sans case ne veut dire que « dehors » (un héros sorti n'a plus de
  ligne). Les lecteurs qui parcourent tous les héros ignorent ceux qui n'ont
  pas de case — la phase des monstres d'abord, par un seul point de passage
  (`ResolveurTour::ciblesDesMonstres()`, six copies auparavant) : un gobelin
  voisin de (0, 0) frappait le héros de dehors (test qui l'a reproduit).
- **Fait le 2026-11-16 — la fin « sortir »** (relecture de *Kellar's Keep* :
  « find the wooden exit door », l'objectif de presque toutes ses quêtes).
  `fin: {type: "sortir", libelle}` : la sortie est ouverte dès le départ
  (`Quete::sortieDisponible()`), l'objectif n'est jamais « accompli » en cours
  de quête (le bandeau ne ment pas), la quête est gagnée quand le dernier
  héros debout sort. « Trouver un objet puis sortir » reste la fin `drapeau`.
  Proposée dans le constructeur. Tests `FinSortirTest`.
- **Fait le 2026-11-16 — une porte d'accès mène hors du plateau**, en salle
  comme en couloir : son côté donne sur le bord du plateau, de la roche ou un
  bloc, sinon `porte_sans_bord` (*Kellar's Keep* : « on the edge of the
  gameboard »). Avant, une porte d'accès de salle pouvait donner sur un
  couloir et « sortir » à travers du sol. Les campagnes de test et celle du
  harnais posent un bloc derrière leurs portes.
- **Fait le 2026-11-16 — lot S1, quête solo et spectateurs** (§6). Format
  `quete.regles: {solo: classe}` (`regles` n'accepte que `solo` ; la classe
  doit être exigée par la campagne, sinon `solo_sans_prerequis`). Au départ,
  seul le premier héros de cette classe dans l'ordre du tour entre, figé dans
  `quetes.participants` (migration `2026_11_16_120000`, appliquée après
  sauvegarde relue ; `null` = tout le groupe). Les autres héros sont
  SPECTATEURS : pas de ligne d'état, donc ni tour ni menu (l'ordre du tour
  saute déjà un héros sans ligne), rien à risquer, et la récompense comme
  l'or partagé ne vont qu'aux participants (`Bourses::partager(parmi:)`).
  Publié `entites[].spectateur` ; manette : bandeau « Spectateur », l'ordre du
  tour et le fil, sans menu ; table : « Spectateur — ne joue pas cette
  quête ». Constructeur : « Quête solo », parmi les classes exigées. Tests
  `QueteSoloTest` ; vu à l'écran. Reste de S1 : la campagne grisée au choix
  quand le groupe n'a pas la classe exigée (aujourd'hui refusée au départ) —
  fait le même jour, entrée suivante.
- **Fait le 2026-11-16 — fin du lot S1 : choisir sa campagne au hub** (§6,
  §7). Il n'existait aucun moyen de changer de campagne : « finir une
  campagne ramène au choix » n'était qu'écrit. `App\Partie\Classique\
  ChoixCampagne` est le point de passage des prérequis de héros (liste, choix
  et départ) et de l'ouverture du choix. Décision écrite : le choix n'est
  ouvert qu'au hub, campagne FINIE, PAS COMMENCÉE (`rang_quete = 1`) ou
  disparue — une campagne en cours ne se quitte pas d'un geste. `GET
  /groupes/{id}/campagnes` publie chaque campagne avec `choisissable` et le
  `motif` du gris ; `PUT /groupes/{id}/campagne` lit la même règle, remet le
  groupe à la quête 1 (héros, or, équipement gardés) et efface les « prêt ».
  Le hub publie `groupe.classique` (campagne, quête N sur M, `choix_ouvert`).
  Défaut corrigé au passage : `depart_possible` ne lisait que la branche
  générée — « Prêt » était offert à un groupe classique sans la classe exigée,
  puis refusé ; `DemarreurQuete::refusDeDepart()` aiguille désormais seul, et
  « Lancer la quête » (table) ne passe plus outre les prérequis. Manette : bloc
  « Campagne » au hub (`ChoixCampagneHub.vue`). Tests `ChoixCampagneTest` ;
  vu à l'écran.
- **Fait le 2026-11-17 — le déplacement classique à deux dés** (décision Q1,
  jamais codée jusque-là). `Groupe::socleDeDeplacement()` : RPG `{base de
  classe, 1 dé}`, classique `{0, 2 dés}` — lu par les trois calculs du jet
  (jet du tour, relance de *Vision du futur*, repli du résolveur). « Un seul dé
  rouge » (plate, choc) barre en classique UN des deux dés, le dernier (lancé,
  rayé : le joueur voit ce qu'il aurait eu) ; les Bottes elfiques en ajoutent
  un ; Évanescence suit la règle du livret (somme ≥ 9). Publié
  `des_annules` (table et manette barrent ceux-là seulement) ; la phrase ne
  nomme pas une base nulle. Tests `DeplacementTest` (moteur),
  `DeplacementClassiqueTest`.
- **Décisions de René du 2026-11-17 sur les restes de K5** (réponses à la liste
  « ce qui reste ») :
  1. **R4, l'équipement confisqué** : oui. Source lue : *Rise of the Dread Moon*
     (F6646 p. 11) — « All their equipment and valuables have been confiscated »,
     et « Each hero may search this room to retrieve all their confiscated
     equipment and valuables » ; à mains nues, « A hero with no weapons rolls one
     Attack die » (F6646 p. 5). René : si seules armes et armures sont prises,
     le sac et l'or restent ; les sorts fonctionnent ; une note ou un coffre rend
     l'équipement. **Tranché le même jour : TOUT, comme la source** — armes,
     armures, sac et or ; chaque héros récupère ses affaires en fouillant la
     salle désignée.
  2. **R5 / F4, la charge** : le porteur lance UN dé de déplacement au lieu de
     deux ; il agit normalement ; s'il tombe, un autre héros la reprend sur sa
     case ; la quête est terminée dès que tous les héros encore vivants sont
     sortis. **Précisé par René** : en mode CLASSIQUE on lance DEUX dés de
     déplacement — ne pas confondre avec le mode RPG (base + 1d6). Le porteur en
     lance UN.
     ⚠ **Défaut relevé du même coup** : la décision Q1 (« déplacement : deux dés
     rouges, sans base de classe » en classique) n'a JAMAIS été codée —
     `MenuMoteur::deplacementDuTour()` lance « base + 1d6 » pour tout groupe.
     Premier lot à faire, avant la charge (qui en dépend).
  3. **Le brouillard des couloirs** : respecter le plateau ; une salle s'affiche
     dès qu'un héros est devant sa porte quand elle est ouverte. **Tranché** : les
     couloirs se dévoilent à VUE (ligne de vue des héros), les salles entières à
     l'ouverture de leur porte ; ouvrir une porte ne révèle plus tous les
     couloirs.
  - **Ordre retenu** : (a) le déplacement classique à deux dés (Q1, jamais
    codé) ; (b) R4 l'équipement confisqué ; (c) R5/F4 la charge ; (d) les
    couloirs à vue ; (e) R9 les monstres de l'auteur ; puis D6, E5, la salle
    tournante et la quête double ; K6 après la base ; la relecture de tous les
    livrets à la fin.
  4. **R9, les monstres de l'auteur** : proposer les deux — un monstre du
     catalogue promu sous-boss ou boss, ou un monstre créé par l'auteur.
  5. **Salle tournante et quête double** : les deux, au moment opportun.
  6. **K6, les mécaniques d'une seule boîte** : toutes, une fois la base finie.
  7. **D9 et la fidélité générale** : à la fin du travail, faire le tour de TOUS
     les livrets pour valider ce qui manque.
- **Fait le 2026-11-17 — lot X1, puis le contact à travers un mur.** La
  campagne d'exemple ORIGINALE (`docs/exemples/`) a été jouée par deux agents
  Haiku : finie, deux quêtes gagnées (verdict
  `docs/verdict-x1-campagne-exemple-2026-11-17.md`). Le défaut grave relevé —
  une momie et un barbare se frappant à travers un mur en arête — est corrigé
  à son point de passage : `Grille::sontAdjacentes(…, carte:)` coupe le contact
  par un mur ou une porte close sur l'arête ; tout appel de `app/` passe la
  carte (`ContactAtraversMurTest`, dans les deux sens) ; trois copies de la
  boucle d'emprise délèguent à `InstanceMonstre::touche()`.
- **Lot K5 : ce qui reste, nommément (2026-11-16, après K5i)** — « retirer du
  contenu est un choix écrit » :
  - **R4, l'équipement confisqué** jusqu'à un point de la carte (sorts
    interdits d'ici là) : il toucherait d'un coup les caractéristiques de combat
    (`Equipement::recalculerCombat()`), l'usage des objets et les sorts ; aucune
    règle générique écrite dans les livrets relus, seulement des notes de
    quêtes. À trancher avec René avant d'ouvrir le chantier.
  - **R5 et la charge de F4** : voir K5f — aucune valeur sourcée.
  - **D9, l'objet ramassé ou rapporté** : en classique, un objet de quête vient
    d'une note, qui pose déjà un drapeau au même moment ; le butin de fouille,
    lui, est tiré au hasard. Reporté faute de cas qu'une note ne couvre pas.
  - **D6, le passage du dernier héros** (effondrement derrière le groupe), **le
    reste d'E5** (poser un meuble ou une tuile en cours de quête), **la salle
    tournante d'E8** et **R9, le monstre saisi par l'auteur** (bloc de stats et
    sorts propres) : chacun touche la carte ou le catalogue en cours de quête ;
    un lot chacun, après X1.
  - Cosmétique relevé à l'écran (K5f) : le fil annonce la découverte d'une salle
    avant le texte de la note qui la provoque.
- **Fait le 2026-11-16 — lot K5i : la campagne ramifiée** (C2).
  `quete.suite` : des embranchements `{quete: slug | fin: true, si_drapeau?}`
  lus dans l'ordre à la VICTOIRE ; le premier retenu décide de la quête
  suivante (ou de la fin de la campagne), sinon l'ordre. Point de passage
  `FinDeQueteConstruite::rangSuivant()`. Validation : une destination, une
  autre quête de la campagne, un drapeau posable (quête ou campagne
  précédente), l'embranchement sans drapeau en dernier. `suite` était la
  dernière clé réservée : le code `cle_pas_encore_prise`, qui n'avait plus
  rien à refuser, est retiré (choix écrit). Constructeur : éditeur
  « Suite (campagne ramifiée) », fait par un agent Haiku, relu et vu à l'écran.
- **Fait le 2026-11-16 — lot K5h : les drapeaux de campagne** (C4). L'effet
  `drapeau` accepte `campagne: true` : posé sur le groupe
  (`groupes.drapeaux_campagne`, migration `2026_11_16_200000`, appliquée après
  sauvegarde relue), il survit à la quête. Lecteur unique `Quete::aDrapeau()` :
  la quête, puis la campagne. La validation accepte qu'une quête lise un
  drapeau posé « de campagne » par une quête PRÉCÉDENTE. Choisir une campagne
  les efface. Constructeur : case « de campagne » sur l'effet drapeau. C2 (la
  campagne ramifiée, clé réservée `suite`) s'appuiera dessus.
- **Fait le 2026-11-16 — lot K5g : la fouille de zone à résultat imposé**
  (D7). Déclencheur `fouille_zone {salle}` : quand un héros RÉUSSIT la fouille
  de la zone dans la salle, la note remplace ce que le jet aurait révélé
  (`resultat.issue = "note"`), comme la note de fouille de trésor — « rien »,
  ou `reveler_porte` d'une seule porte. Décision écrite : un jet raté ne
  trouve rien, note ou pas. Le fil dit « … fouille la zone… », la note dit le
  reste.
- **Fait le 2026-11-16 — lot K5f : la téléportation** (E8, sans la salle
  tournante). Effet de note `teleporter {x, y}` : le héros qui déclenche est
  posé sur la case (ou la plus proche libre, `CaseLibre`), la salle d'arrivée
  se révèle (`ResolveurTour::revelerSalleEn()`). Téléporté en pleine marche, il
  ne déclenche plus les cases suivantes de son trajet. Sans héros déclencheur
  ou sans case libre : rien, annoncé. La validation de la case d'arrivée est
  celle d'une apparition (`caseDArrivee()`, une seule copie).
  **Reporté, nommément — R5 et la « charge » de F4** : une charge qui réduit le
  déplacement demande une valeur (combien de cases, quelle règle de chute)
  qu'aucun livret relu ne donne ; `MoteurFardeau`, que le tableau §2.3 citait,
  est le jet du talisman maudit, pas une charge. Rien n'est semé sans source :
  à reprendre quand René tranchera la règle.
- **Fait le 2026-11-16 — lot K5e : les salles qu'on ne fouille pas** (fin de
  R6). `quete.regles.sans_fouille: [ids]` : aucune des quatre fouilles (zone,
  Parler à la pierre, trésor, trésor caché) n'est offerte dans ces salles, et
  le résolveur les refuse. Prédicat unique `Quete::fouilleInterditeEn()`.
  Refusé à la validation : une note qui attendrait la fouille de trésor d'une
  telle salle. Défaut corrigé au passage : `regles` était validé avant les
  salles ouvertes de SA quête. Constructeur : une case par salle ouverte.
- **Fait le 2026-11-16 — lot K5d : pièges introuvables, indésarmables**
  (R6, partie pièges). `quete.pieges[]` accepte `introuvable` et
  `indesarmable` (booléens) ; points de passage `MoteurPieges::trouvable()` et
  `desarmable()` — le drapeau de la quête OU la carte du catalogue. Défaut
  corrigé au passage : le résolveur du désamorçage ne revalidait pas
  `desarmable: "non"` (seul le menu le lisait). Reste de R6 : les zones non
  fouillables. Constructeur : deux cases sur l'outil piège.
- **Fait le 2026-11-16 — lot K5c : le marché fermé avant une quête** (R8).
  `quete.regles.marche_ferme: true` : tant que cette quête est la prochaine du
  groupe, le marché du hub ne s'ouvre pas. Point de passage
  `PhaseMarche::motifFermeture()`, lu par la route et publié au hub
  (`marche_ouvert` / `marche_motif`) ; la table masque « Ouvrir le marché » et
  dit pourquoi. Constructeur : case « Marché fermé avant la quête ».
- **Fait le 2026-11-16 — lot K5b : le bloc qui tombe et la porte secrète
  révélée** (E6, E5 en partie). Effets de note `bloc {x, y}` — le même
  obstacle permanent qu'une Chute de blocs déclenchée
  (`MoteurPieges::poserBloc()`, `ETAT_BLOC`) — et `reveler_porte {porte}`
  (`MoteurPortes::revelerParNote()`, une porte secrète devient close).
  Décision écrite : un bloc ne tombe que sur une case LIBRE ; sur une case
  prise, rien ne tombe et l'annonce le dit (un bloc posé ailleurs fermerait un
  autre chemin). La « porte factice » d'E6 n'a pas de mot à elle : une porte au
  verrou `note` qu'aucune note n'ouvre. Reste d'E5 : poser une tuile, un
  meuble.
- **Fait le 2026-11-16 — lot K5a : portes verrouillées et tour du MJ** (§2 —
  E6 en partie, D5). `portes[].verrou` offre à l'auteur les verrous que le
  moteur jouait déjà sur une carte générée : `cle` (un objet du catalogue),
  `pierre`, `condition` (`MoteurPortes::CONDITIONS`), plus `note` — la porte
  « que seul le maître du jeu ouvre » (`MoteurPortes::VERROU_NOTE`), qu'aucun
  geste de héros n'ouvre. Effet de note `ouvrir_porte` (ouvre et révèle,
  `ResolveurTour::ouvrirPorteParNote()`, sans signaler `ouverture_porte` : une
  note n'en déclenche pas une autre). Déclencheur `tour_du_mj {tour: N}` : au
  début du tour de Zargon, avant le premier monstre, à partir du N-ième
  (`quetes.tours_mj`, migration `2026_11_16_180000`, appliquée après sauvegarde
  relue). Au passage, ouvrir une porte PAR LA FORCE (pierre, condition)
  signale aussi `ouverture_porte` — avant, seule l'ouverture à la main le
  faisait. Constructeur : verrou à la pose d'une porte, cible « à partir du
  tour », effet « ouvrir une porte ». Reste d'E6 : bloc qui tombe, porte
  factice hors des accès. Tests `PortesVerrouilleesTest`.
- **Fait le 2026-11-16 — lot H1a, mode Heroic** (Q2, Q7). Règle lue au début
  du lot, comme Q7 l'exigeait : *Jungles of Delthrak* (F9907 p. 8-9) pour le
  jeton crâne, livret de règles de *First Light* (G0978, « Dead Heroes ») pour
  la mort — le livret de règles 2021 (F3649) n'a pas de texte extractible.
  `groupes.mode_mort` (`story` par défaut, `heroic`) choisi à la création ;
  en Heroic, pas de relèvement Story : le héros à terre prend le crâne à
  l'ouverture du round suivant et meurt à celle d'après
  (`App\Partie\Classique\MortHeroic`, point de passage unique de la mort).
  `personnages.mort_le` : définitif, la ligne n'est jamais supprimée ; le mort
  reste au groupe jusqu'au hub (publié `mort`), ne reçoit rien, puis quitte le
  groupe ; il n'est plus engageable (422) ; un TPK Heroic tue tous les héros à
  terre ; une reprise ne ressuscite personne. Migration
  `2026_11_16_140000`, appliquée après sauvegarde relue. Tests
  `MortHeroicTest` ; vu à l'écran (crâne, puis mort). Restent : H1b,
  l'équipement du mort (à ramasser dans la même salle ou le même couloir, volé
  sinon) ; H1c, la potion donnée par un compagnon à un héros à terre (au lieu
  du « relever » sans potion, écart du projet) et le lanceur qui se soigne en
  mourant.
- **Fait le 2026-11-16 — lot H1b, les affaires du mort** (G0978, « Dead
  Heroes »). À sa mort, si un compagnon debout se tient dans la même salle ou
  le même couloir (zone de la fouille), ses affaires restent au sol
  (`quetes.depouilles`, migration `2026_11_16_160000`, appliquée après
  sauvegarde relue) et tout héros de cette zone peut les ramasser sans action
  (`ramasser_depouille`, transfert par `DonObjet::transferer()`, or par
  `Bourses`) ; sinon un monstre les emporte — elles restent sur le mort, hors
  du jeu, rien n'est supprimé. Tests dans `MortHeroicTest`.
- **Fait le 2026-11-16 — lot H1c, relever par une potion** (F9907 p. 8). En
  classique, « relever » sans potion disparaît (écart du projet) : un
  compagnon au contact fait boire une potion de soin de son sac, au prix de
  son action, si aucun des deux ne touche un monstre ; le crâne tombe avec.
  Le lanceur qui se soigne en mourant était déjà tenu par la réaction
  « Rester debout ». Lot H1 terminé.
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
