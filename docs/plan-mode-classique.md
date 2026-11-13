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
- **Menu** : la clé de cache devient (groupe, joueur, **héros**). Le serveur
  publie `heros_pilotes[]` et `heros_a_la_main` — la manette n'en déduit rien.
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

## 10. Questions pour René

- **Q1 — Règles du tour.** « Fidèle » va-t-il jusqu'à retirer nos écarts
  assumés en classique : déplacement à deux dés sans base, dague non perdue,
  pas de niveaux ni de talents, pas de forge ni d'entraînement, le marché
  réduit à l'armurerie ? Ma recommandation : **oui**, un profil de règles
  `classique` lu en un seul point, sinon le mode n'est fidèle qu'à moitié.
- **Q2 — Héros mort.** Au plateau, un héros mort est perdu et son joueur
  repart d'un héros neuf. « Le groupe n'est jamais défait » veut-il dire que
  le héros **revient** à la quête suivante (à quel prix ?), ou que la **place**
  est permanente et le héros remplaçable ?
- **Q3 — Or.** Bourse **par héros** comme au plateau, ou pot commun comme en
  campagne aujourd'hui ?
- **Q4 — Sortie.** En classique, garde-t-on le vote en salle de départ
  (décision du 2026-10-10) ou revient-on à la sortie case par case, que R3
  exige de toute façon quand la sortie n'est pas l'entrée ?
- **Q5 — Spectateurs.** Un spectateur voit-il **tout** ce que voit la table,
  ou seulement ce que le héros en jeu a découvert ? (Identique si la table
  est allumée ; différent à distance.)
- **Q6 — Ordre.** Commence-t-on par la manette multi-héros (M1–M2, utile dès
  maintenant) ou par la chaîne quête construite (K0–K4) ?
