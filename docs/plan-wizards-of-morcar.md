# Plan — Définir entièrement l'extension *Wizards of Morcar*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> iront dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §Wizards of Morcar (2025) — nouvellement écrite par ce même travail.
>
> **Source** : livret de quêtes officiel **G1504** (© 2025 Hasbro, 44 pages
> imprimées / 23 pages PDF), téléchargé le 2026-10-04 depuis
> `instructions.hasbro.com`. Texte extrait page par page, et **chaque bloc de
> stats relu sur le rendu PNG** des pages 8-9 (mercenaires) et 40-41 (tableau
> des monstres) — l'extraction texte y désalignait les colonnes dans l'ordre
> de lecture (ex. l'Arbalétrier ressortait en texte comme « 2 2 3 3 6 » quand
> la carte lit réellement Mouvement 6 / Attaque 3 / Défense 3 / Corps 2 /
> Esprit 2 — la mise en garde du brief n'était pas théorique). Les numéros
> ci-dessous sont les **pages imprimées**.
>
> Cette boîte n'avait **jamais été dépouillée** avant ce travail : il n'existe
> aucun autre plan ni aucune section `reference/18` antérieurs à mentionner.

> ## Cartes reçues le 2026-10-05
>
> René a scanné les **63 cartes** de la boîte + le carton *Magic Reference
> Chart* (64 fichiers), toutes lues et transcrites dans
> `reference/18_extensions.md` §**Wizards of Morcar — cartes TRANSCRITES
> (2026-10-05)**. Résumé lot par lot (lots du §3 ci-dessous) :
>
> - **Lot A (murs destructibles)** — **déjà construit** entre-temps
>   (`docs/plan-chantiers-transverses-2026-10-04.md`, chantier 1, commit
>   « Mobilier attaquable ») : `Haut Autel` et `Coffre du Dread` sont déjà
>   dans `MobilierSeeder` (`boite: 'wizards_of_morcar'`). Les cartes
>   confirment le texte des Murs magiques mot pour mot (arête de DEUX cases,
>   1 PV, 6 dés de défense) mais **René avait déjà tranché le 2026-10-04,
>   avant de les voir, qu'ils occupent UNE case chez nous** — une divergence
>   assumée, pas une lecture en attente. Nouveau : **Wall of Ice** est un
>   sort du **Storm Master**, **Wall of Flame** un sort du **High Mage** —
>   mais **Wall of Stone est un sort de HÉROS** (*Spells of Protection*), pas
>   un troisième sort de Sorcier du Dread. Ne reste plus qu'à seeder les
>   trois comme mobilier d'une case et à les relier à leurs sorts respectifs.
> - **Lot B (pièges magiques)** — le carton confirme *Fireburst*/*Hurricane
>   Trap*/*Teleport Trap* mot pour mot, rien de nouveau ; `detectable: false`
>   reste à introduire au vocabulaire.
> - **Lot C (*Ambush*/Dreadshifter)** — stats confirmées (M5 A4 D3 B2 Mi4,
>   = tableau officiel) et texte de capacité obtenu, mais **plus étroit** que
>   le livret : la carte ne documente qu'UN déclencheur (entrée dans les 8
>   cases alentour) sur les quatre du livret. Prêt à seeder dès que
>   `embuscade` existe.
> - **Lot D (statut de Gardien + entretien)** — rien de neuf : les 4 cartes
>   de mercenaires confirment nos stats à l'identique, Q1 reste entière.
> - **Lot E (faveurs de Hopekins Rest)** — **PORTÉ (2026-10-16)** ; au 2026-10-05 : **débloqué**, les 5 compétences
>   sont transcrites mot pour mot (Peacekeeper = or par monstre vaincu, Hold
>   the Line = attaque d'opportunité sur repli, Deadeye = les figures ne
>   bloquent plus la ligne de vue, Weapon Expert = bonus sur un type d'arme
>   choisi à l'acquisition, Healing Hands = partage de potion de soin à 0 PV
>   entre héros adjacents). Q2 n'attend plus les photos.
> - **Lot F (potions)** — les 3 potions attendues sont transcrites
>   (*Fire Resistance*, *Magical Aptitude*, *Magic Resistance*), **plus deux
>   non prévues au plan** : *Potion of Alchemy* (équipement → 100 po) et
>   *Potion of Charm* (rabais de recrutement de mercenaires) — à ajouter au
>   lot.
> - **Lot G (coffres renforcés)** — aucune carte dédiée (c'est une règle, pas
>   un objet) ; rien de neuf.
> - **Lot H (gabarits de quête)** — **Sir Ragnar a maintenant des stats**
>   (M7 A3 D5 B6 Mi2, carte sans texte de capacité). Combiné à la décision
>   transverse du 2026-10-04 (mission « secourir » générique), Q3 est
>   **résolue dans un sens différent de la recommandation d'origine** : ni
>   « écarté » ni laissé en l'état, Sir Ragnar est maintenant **portable**
>   via le même gabarit que Gothar (`Mercenaire::captif`). ⚠ Collision de nom
>   à gérer : `MonstreSeeder` porte déjà un « Sir Ragnar » différent (boss de
>   *Rise of the Dread Moon*, M5 A5 D5 B4 Mi4) — tables distinctes
>   (`monstres` vs `mercenaires`), pas de conflit technique, mais à nommer
>   distinctement pour la narration.
> - **Hors plan d'origine, débloqué en bloc** : les **30 cartes de sort des
>   cinq Sorciers du Dread** (6 chacun, aucune n'était connue) et les **9
>   sorts des trois répertoires de héros** (*Spells of Protection/
>   Detection/Darkness*, 3 chacun) sont intégralement transcrits. Ça change
>   la réponse à Q5 (thème de bestiaire) — voir §4 ci-dessous.
>
> Détail carte par carte, citations et « chez nous » : voir la section
> source. Ne change rien à la section 0-3 et 6 ci-dessous (dates et
> structure d'origine conservées) ; §4 et §5 sont mis à jour en conséquence.

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : les règles de la boîte (p. 2-13), le guide de symboles
et le prologue narratif (p. 14-15), dix quêtes (p. 16-39, dont une « double
quête » 7-8), la conclusion (p. 40), le tableau des 8 monstres (p. 41), et une
planche « dessinez votre propre quête » (p. 42-43).

**Fait notable** : ce n'est pas une boîte inédite mais une **réédition
étendue** — « *This quest book contains five exciting quests that were not in
the original version of Wizards of Morcar. If you want to play the original
quests only, play quests 3, 4, 5, 6, and 9 in sequence* » (p. 6). Les cinq
quêtes d'origine affrontent chacune UN sorcier nommé ; les cinq nouvelles
encadrent la campagne (ouverture, retournement de Sir Ragnar, finale en
double quête, conclusion).

**Il ne porte PAS les 64 cartes de jeu** : les 30 cartes de sort des cinq
sorciers (6 chacun), les cartes de potion (le texte des 4 est donné en clair,
§3, donc récupérable sans photo), les 3 répertoires de sorts de héros
(Protection/Détection/Ténèbres), les cartes des 4 mercenaires (texte
mécanique donné en clair aussi, mais **nos stats actuelles les égalent déjà**
— §1), les 5 compétences de Hopekins Rest, les 3 artefacts nommés par le
texte de quête (*Urdyn the Unmaker*, *Drakehide Cuirass*, *Elixir of Life*),
les 8 cartes de trésor ajoutées, et la carte de Sir Ragnar lui-même. Même
limite que partout ailleurs dans doc 18 : ⚠ non trouvé tant que René n'a pas
photographié ces cartes.

## 1. Déjà en place

| Élément du livret | Chez nous |
|---|---|
| **Les 4 mercenaires génériques (Crossbowman/Halberdier/Scout/Swordsman, p. 8-9) correspondent EXACTEMENT, valeur par valeur, à notre catalogue `MercenaireSeeder`** : Éclaireur (M9 A2 D3 B2 Mi2, 50 po) = Scout ; Arbalétrier (M6 A3 D3 B2 Mi2, 75 po, distance) = Crossbowman ; Fauchard (M6 A3 D3 B2 Mi2, 75 po, diagonale) = Halberdier ; Estafier (M5 A4 D5 B2 Mi2, 100 po) = Swordsman | Rien à porter sur les STATS. Le commentaire du seeder les attribue à « © 2023 Hasbro » — à vérifier si c'est le même paquet générique de mercenaires réimprimé dans plusieurs boîtes (question §4) |
| *Potion of Healing* (500 po, 1 dé rouge de Body) | `Potion de guérison`, `ObjetSeeder`, identique au prix et à l'effet près |
| Monstres à 0 Esprit immunisés aux sorts mentaux | `App\Engine\SortMental` (porté par Against the Ogre Horde) |
| Grands monstres (attaque sur les cases adjacentes y compris diagonales) | `grande_taille` + `Grille::adjacenteAEmprise()` |
| Déplacement non menacé (4 cases/dé sans monstre actif) | `MenuMoteur::deplacementDuTour()` |
| Portes d'entrée/sortie fléchées | portée par First Light |
| Don d'un objet À UN HÉROS ADJACENT, pas à un monstre adjacent, EN QUÊTE | `SeanceEchange` (chemin bidirectionnel en quête) — « Passing Items » (p. 6) semble déjà couvert, à confirmer par un test ciblé plutôt que supposé |
| Tirage d'un artefact aléatoire EXCLUANT ceux déjà possédés | `Fouille\DeckFouille::choisirArtefact()` — même principe que « Unearth an Artifact » (p. 6), il manque seulement l'exclusion **des artefacts propres à CETTE boîte** (sans objet tant qu'aucun des trois n'est seedé — §3) |
| Immunité à un type de dégât via charges (`immunite_degat`) | `MotsClesEquipement`, déjà porté pour l'Anneau de Feu (Kellar's Keep) — lecteur directement réutilisable pour la *Potion of Fire Resistance* |
| Terrain/case qui téléporte vers une case liée | `Tunnel de glace` (`TerrainSeeder`, `effet: ['teleportation' => true]`), **boîte des glaces désactivée** (`BOITES_INCOMPLETES`) mais le lecteur existe — réutilisable pour les Tuiles Tunnel |
| Recul forcé le long d'un couloir, direction = provenance | `MoteurPieges` (Chute de blocs : `['x'=>…, 'sens'=>'reculer']`) — même famille que *Hurricane Trap* |
| Mur = arête de DEUX cases plutôt qu'une case | **pas encore** : `docs/plan-murs-en-aretes.md` est le prérequis direct des Murs magiques (§3 plus bas) |

## 2. Section source écrite

`reference/18_extensions.md` §**Wizards of Morcar (2025)** est la nouvelle
section demandée par ce travail (insérée avant §Tableau de synthèse, avec sa
ligne dans le tableau). Elle couvre : héros (aucun, Sir Ragnar à part),
tableau des 8 monstres relu sur PNG, les 5 Sorciers du Dread, les
redéfinitions de stats scriptées par quête, la capacité **Ambush**, objets/
potions/artefacts/sorts, mobilier et tuiles (Murs magiques, Éclair, Séisme,
Tunnel, coffres renforcés, Haut Autel), les mécaniques nouvelles (pièges
magiques, statut de Gardien, faveurs de Hopekins Rest, don d'objet), et un
résumé des dix quêtes. S'y référer plutôt que recopier ici.

## 3. Les règles à porter, sourcées — par LOT

### Lot A — Murs destructibles génériques (le plus structurant)

**Trois boîtes réclament la MÊME brique**, qu'aucune n'a construite :

| Boîte | Élément | PV | Détail |
|---|---|---|---|
| Against the Ogre Horde *(déjà portée)* | — | — | rien de comparable |
| The Frozen Horror (`docs/plan-glace-et-degats-mind.md`) | *Ice Wall* | non chiffré au livret | boîte **désactivée**, un des 3 sorts non portés qui a coûté le thème |
| Jungles of Delthrak (`docs/plan-delthrak.md` §3, lot C) | *Crystal Cluster* | 6 PV | détruit « comme un monstre sans défense » |
| **Wizards of Morcar** | Murs magiques (Glace/Feu/Pierre) | **1 PV**, 6 dés de défense | posé sur une ARÊTE de 2 cases, bloque tout, détruit par ≥1 dégât |
| **Wizards of Morcar** | Haut Altar (objectif final) | 6 PV, 4 dés de défense | pas un monstre, un meuble attaquable |
| **Wizards of Morcar** | Coffres du Dread | 1 PV, 6 dés de défense, immunisés au feu | détruit → libère le sorcier lié |

Une seule mécanique — « élément de décor avec des points de vie et une
défense, attaquable au combat normal, retiré du plateau à 0 PV » — couvrirait
les SIX. C'est le lecteur manquant le plus rentable des deux boîtes de ce
rapport. **Dépend de `docs/plan-murs-en-aretes.md`** pour les Murs magiques
spécifiquement (ils bloquent une ARÊTE, pas une case) ; le Haut Autel et les
Coffres du Dread, eux, sont des MEUBLES ordinaires (`MobilierSeeder`,
`difficulte_destruction` existe déjà comme notion proche) et n'attendent
rien.

*Vocabulaire* : un champ `mobilier.pv_body` + `mobilier.defense_dice`
(nullable — absent = indestructible comme aujourd'hui), lu au seul point où
`ResolveurTour` résout une attaque contre du mobilier. *Test* : attaquer un
meuble à 0 PV le retire de la salle comme un monstre vaincu. *Donnée* : Haut
Autel et Coffre du Dread sont des `MobilierSeeder` ordinaires avec ces deux
champs ; les Murs magiques attendent l'arête.

### Lot B — Pièges magiques (non fouillables, à déclenchement différé)

| Piège | Règle (citation) | Chez nous |
|---|---|---|
| *Fireburst Trap* | « a Fireburst token […] remain until the beginning of Zargon's turn, when it will explode, attacking all heroes and monsters in the room with 3 Attack dice » | Déclenchement **au tour SUIVANT du MJ**, zone = toute la salle (hors et héros ET monstres) — rien de comparable aujourd'hui, nos pièges résolvent au moment du pas qui les déclenche |
| *Hurricane Trap* | « forced to move back 8 spaces along the corridor or until they hit a wall » | Recul forcé réutilise le patron `['sens' => 'reculer']` de Chute de blocs, mais sur 8 cases et TOUT le couloir d'un coup, pas un seul héros |
| *Teleport Trap* | « teleported to the space marked […] disoriented, and their turn ends » | Réutilise `effet: teleportation` (Tunnel de glace) + fin de tour immédiate |

Point commun aux trois : **« Magical traps cannot be found by searching »**
(p. 7) — un piège qui n'apparaît JAMAIS à la fouille, se déclenche par un
franchissement de case ou un tour de MJ, et n'est désamorçable que par un
sort précis (Fireburst) ou pas du tout. Nouveau sous-vocabulaire de
`PiegeSeeder` : `detectable: false` n'existe sur AUCUNE ligne actuelle — un
vrai trou de vocabulaire, pas une variante d'un champ déjà lu.

### Lot C — Capacité *Ambush* (monstre déguisé)

« Dreadshifters in this expansion pack appear to be either a chest or a
door » (p. 6) : posé comme du mobilier, révélé par quatre déclencheurs
différents (mouvement adjacent, fouille de pièges, fouille de trésor avec
attaque immédiate, tour du MJ). Aucun lecteur comparable aujourd'hui — le
plus proche est le changement d'état des portes secrètes
(`MoteurPortes::VERROU_PIERRE` et consorts), mais c'est un MONSTRE qui se
cache, pas une porte. Nouveau mot-clé de monstre `embuscade` + un marqueur
sur l'instance de mobilier qu'il remplace.

### Lot D — Statut de Gardien et mercenaires à entretien

Le catalogue de mercenaires est déjà bon (§1). Ce qui manque :
- **Déblocage après la quête 2** (« Once a hero has become a Warden ») —
  aujourd'hui les mercenaires sont ouverts dès le départ.
- **4 par héros** — aujourd'hui pas de plafond déclaré (à vérifier à
  l'implémentation).
- **Entretien de 10 po/quête par mercenaire survivant**, sinon il quitte le
  groupe et se réengage plein tarif — état DURABLE (ne pas le mettre en
  cache, règle du CLAUDE.md), une colonne sur `GroupeMercenaire`.
C'est une divergence de MODÈLE ÉCONOMIQUE avec notre système actuel (payé une
fois, gardé jusqu'à la mort), pas une case à cocher — à trancher avec René
(Q1).

### Lot E — Faveurs de Hopekins Rest

> **Statut 2026-10-16 : PORTÉ en entier** (les neuf lieux). Les cinq compétences l'étaient depuis
> 2026-10-06/08 ; *Weapon Expert* avec choix du joueur depuis le 2026-10-15 ; les quatre faveurs
> mécaniques (*Mercenary Guild*, *Dewdrop Inn*, *Witch's Hut*, *Arcane Library*) le 2026-10-16, chacune
> avec son lecteur réel et son test en jeu — voir « Décisions de René (2026-10-15) » en fin de document
> et `docs/regles/exploration-et-fouille.md` §Faveurs. Le texte ci-dessous est l'analyse d'origine.

Neuf lieux, un visitable par quête une fois Gardien : quatre sont mécaniques
et sourcés en clair (mercenaire gratuit, +2 PV Body max, +1 Potion de
guérison cumulable, répertoire de sorts supplémentaire) — portables tout de
suite, même famille que les potions et dons déjà dans le catalogue. Les cinq
autres accordent une **compétence de héros inédite** (*Dead Eye*, *Weapon
Expert*, *Healing Hands*, *Hold the Line*, *Peacekeeper*) dont **seul le nom**
est donné — ⚠ non trouvé, cartes à demander (§5). C'est un système de
progression PARALLÈLE à `CompetenceSeeder`/`Talents` (un don non lié au
niveau, lié à un lieu visité) : à cadrer avec René avant d'écrire quoi que ce
soit (Q2).

### Lot F — Nouvelles potions

| Potion | Effet | Lecteur |
|---|---|---|
| *Potion of Fire Resistance* (300 po) | immunité à la prochaine attaque de feu magique | `immunite_degat: 'feu'` existe déjà (Anneau de Feu) — portage immédiat, aucun nouveau mot-clé |
| *Potion of Magical Aptitude* (400 po) | lancer 2 sorts connus au lieu d'1 ce tour | nouveau : rien dans le vocabulaire de sort ne permet un second lancer dans le même tour aujourd'hui |
| *Potion of Magic Resistance* (300 po) | annule les effets du PROCHAIN sort à dégâts lancé sur le buveur | nouveau : aucun mot-clé « annule le prochain sort subi » au catalogue des conditions/effets |

### Lot G — Coffres renforcés

« Contents of chests are not found until the first time a hero **adjacent**
to that chest searches the room. Heroes who are not adjacent draw from the
treasure deck instead » (p. 6). `MoteurMobilier::fouillablesAdjacents()`
calcule déjà l'adjacence au mobilier pour la fouille normale — il manque la
branche « pas adjacent → tirage du deck de trésor plutôt que le butin fixe du
meuble », simple à brancher sur le seam existant.

### Lot H — Ce que les quêtes apportent au générateur

- **Hopekins Rest** est un second exemple de HUB entre quêtes avec des
  services nommés (après le marché) — à rapprocher de tout hub existant
  plutôt qu'à dupliquer.
- **Statut « insubmersible » scripté** (Sir Ragnar, la Gardienne,
  Brak-Fellorn) : un gabarit de quête fige un plancher de PV (jamais sous 1)
  ou une condition tierce (cœur détruit ailleurs) avant d'autoriser la mort.
  Même famille que le **sacrifice du Bloomrot Scion** (Delthrak, §3 de
  l'autre plan) : les deux boîtes réclament « vérifier un état tiers avant de
  finaliser la mort d'un monstre » — un seul lecteur gagnerait à servir aux
  deux.
- **Double quête 7-8** (ressources NON restaurées entre les deux) — à vérifier
  si un gabarit similaire existe déjà (le tableau de synthèse mentionne « quête
  double liée » pour The Frozen Horror et The Mage of the Mirror : précédent
  à relire avant d'inventer un mécanisme).
- **Sir Ragnar, allié temporaire jouable par un joueur** : contrairement à nos
  mercenaires/alliés (joués par le moteur), c'est un héros TEMPORAIRE contrôlé
  par un joueur humain pour 2 quêtes scriptées. Hors de portée d'une quête
  générée procéduralement (impossible à garantir sans scénario écrit) —
  proposé comme **écarté par une phrase**, pas porté (Q3).

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | Le modèle économique des mercenaires de cette boîte (Gardien dès la quête 2, 4 par héros, entretien de 10 po/quête) remplace-t-il notre modèle actuel (payant une fois, gardé jusqu'à la mort), ou les deux coexistent-ils par boîte ? | Aligner : la gratuité d'Ogre Horde pour l'allié animal (plan Ogre Horde, Q2/Q3) a déjà ouvert la porte à des règles de recrutement variables ; un entretien périodique est une colonne d'état, pas un chantier lourd |
| **Q2** | Les 5 compétences de Hopekins Rest (Dead Eye, Weapon Expert…) : un système de progression À PART (don lié à un lieu visité, pas au niveau), ou à fondre dans l'arbre de talents existant une fois les cartes photographiées ? → **tranchée le 2026-10-15 (en partie)** : *Weapon Expert* (choix du type d'arme) et les 4 faveurs mécaniques sont décidées ; *Dead Eye*, *Hold the Line*, *Peacekeeper* et *Healing Hands* restent ouvertes. Voir « Décisions de René (2026-10-15) ». **2026-10-16 : les neuf faveurs sont portées** (les quatre « ouvertes » l'étaient déjà ; ce qui reste ouvert est un choix de règle, voir « Exécution (2026-10-16) » en fin de document). | Attendre les photos avant de trancher la mécanique — nommer la question évite de la geler par défaut |
| **Q3** | Sir Ragnar, allié jouable par un joueur humain pendant 2 quêtes scriptées : écarté (notre modèle ne rejoue aucune quête du livret), ou un futur « allié temporaire joueur » générique pour une quête IA qui le proposerait ? | Écarté pour l'instant — aucun gabarit ne peut aujourd'hui garantir la scène qui l'introduit |
| **Q4** | Le lecteur « mobilier destructible avec PV/défense » (lot A) sert Wizards of Morcar (3 usages), Jungles of Delthrak (Crystal Cluster) ET relancerait potentiellement *Ice Wall* de la boîte de glace désactivée : construire une fois, générique, tout de suite ? | Oui — c'est la seule pièce qui débloque trois boîtes à la fois, le meilleur rapport effort/couverture du rapport |
| **Q5** | Cette boîte devient-elle un THÈME de bestiaire (`BOITES_THEMATIQUES`) ? Elle a 5 lanceurs nommés mais SEULEMENT 2 sorts sourcés par sorcier (les 3 génériques de plateau) sur 6 — les répertoires individuels sont ⚠ non trouvés | Pas avant les photos des 30 cartes de sort : un thème à sorciers muets (seulement Murs/Éclair/Séisme) serait plus pauvre que le fond commun actuel |

### Questions reformulées — cartes reçues le 2026-10-05

Les photos du §Cartes reçues ci-dessus tranchent ou déplacent trois des
cinq questions d'origine ; les deux autres restent entières.

| # | État au 2026-10-05 |
|---|---|
| **Q1** | **Toujours ouverte, inchangée.** Les cartes de mercenaires confirment les stats mais ne disent rien du modèle Gardien/entretien — c'est une règle du LIVRET (p. 8-9), pas une carte. Décision de René toujours attendue. |
| **Q2** | **Plus bloquée par l'absence de cartes** — les 5 compétences sont transcrites mot pour mot (`reference/18_extensions.md` §7). La question devient concrète : *Peacekeeper* (or par monstre vaincu en quête), *Hold the Line* (attaque d'opportunité sur un monstre qui recule), *Deadeye* (les figures ne bloquent plus la ligne de vue pour attaquer/lancer un sort), *Weapon Expert* (bonus sur un type d'arme choisi à l'acquisition — PAS figé par classe, contrairement aux tags de maîtrise actuels), *Healing Hands* (partage automatique de potion de soin à 0 PV). Aucune des 5 n'est un simple doublon d'un nœud de talent existant : à trancher — système à part ou nouvelles feuilles de l'arbre — avec le texte réel en main cette fois. → **même décision partielle, 2026-10-15** (voir « Décisions de René (2026-10-15) » en fin de document). |
| **Q3** | **Résolue, mais pas comme prévu.** La recommandation d'origine (« écarté ») est dépassée par la décision transverse du 2026-10-04 : un allié temporaire générique (« mission secourir ») existe désormais, et la carte de Sir Ragnar (M7 A3 D5 B6 Mi2, aucun texte de capacité) suffit à le porter par le même gabarit que Gothar. Reste à nommer : ⚠ collision avec le « Sir Ragnar » déjà seedé comme monstre boss de *Rise of the Dread Moon* (table différente, pas de conflit technique, mais à distinguer clairement dans la narration et le nom catalogue, ex. `Sir Ragnar (captif)` ou `Sir Ragnar — Garde mercenaire`). |
| **Q4** | **Tranchée par René le 2026-10-04, et déjà construite** (chantiers-transverses, commit « Mobilier attaquable ») — oui, lecteur générique construit une fois ; `Haut Autel` et `Coffre du Dread` sont déjà dans `MobilierSeeder`. Les Murs magiques restent à seeder (mobilier d'UNE case, décision déjà prise, plus besoin de `plan-murs-en-aretes.md`). |
| **Q5** | **Rouverte, en sens inverse de la recommandation d'origine.** Les 30 cartes de sort sont connues (6 par sorcier, aucun sorcier muet) et les 9 sorts de héros aussi — l'objection d'origine (« sorciers à 2 sorts sur 6 ») ne tient plus. Nouvelle question à trancher avec René : vu le volume de nouveaux mots-clés que ces 30+9 sorts réclament (sort réactif sans action — *Raise the Dead* —, contrôle du déplacement d'un héros par le MJ — *Possess* —, bouclier à jetons absorbant N coups — *Scrolls of Morcar* —, buff de zone pour une faction de monstres — *Shield of Protection*/*Sharpen Blades* —, etc.), le thème `wizards_of_morcar` est-il à construire d'un coup ou par vagues (walls + 1-2 sorciers d'abord) ? |

## 5. Sources à demander (photos des cartes)

**Mise à jour 2026-10-05 — reçues.** Les 63 cartes + le carton *Magic
Reference Chart* sont intégralement lus et transcrits
(`reference/18_extensions.md` §Wizards of Morcar — cartes TRANSCRITES).
Statut de chaque élément demandé dans la version d'origine de cette liste :

- ✅ les 5×6 cartes de sort des Sorciers du Dread — **reçues et transcrites**
  (30/30) ;
- ✅ les 5 cartes de compétence de Hopekins Rest — **reçues et transcrites**
  (5/5) ;
- ✅ les 3 répertoires de sorts de héros (Protection/Détection/Ténèbres) —
  **reçus et transcrits** (9/9) ;
- ✅ les 3 cartes d'artefact : **3 sur 3** (*Urdyn the Unmaker*,
  *Drakehide Cuirass*, *Elixir of Life*) — l'**`Elixir of Life` est trouvée**
  le 2026-10-15 au Drive racine (`artifacts_part2.pdf` p. 29, texte relu sur le
  rendu : « This small bottle of pearly liquid brings a dead hero back to life,
  restoring all of their Body and Mind Points. This potion can only be used
  once. ») ;
- ✅ la carte de Sir Ragnar — **reçue** (M7 A3 D5 B6 Mi2, aucun texte de
  capacité propre) ;
- ✅ les 8 cartes de trésor ajoutées + la carte « Nothing! » — **reçues**
  (9 images, *Magical Trap* en double exemplaire) ;
- ✅ les 4 cartes de mercenaire de cette boîte — **reçues, confirment à
  l'identique** `MercenaireSeeder` ;
- ✅ *(hors liste d'origine)* les 4 cartes des ennemis hors sorciers
  (Dreadshifter, Golem, Minotaure) — **reçues**, stats = tableau officiel
  déjà cité section 2025.

**Plus rien à demander** : la carte `Elixir of Life` a été trouvée au Drive
le 2026-10-15 — tout le reste de la liste d'origine est soldé. Le livret ne suffisait plus à rien bloquer :
les lots A (hors l'arête, tranchée par René sans attendre les cartes), B, C,
F et G étaient déjà couverts par le texte seul ; ce sont désormais **D et E**
qui peuvent avancer avec le texte réel des cartes plutôt que des noms.

## 6. Ordre proposé

1. **Lot A** (mobilier destructible générique) — débloque trois boîtes à la
   fois, à faire AVANT tout le reste de celle-ci. Les Murs magiques
   eux-mêmes attendent `docs/plan-murs-en-aretes.md` ; le Haut Autel et les
   Coffres du Dread n'attendent rien.
2. **Lot G** (coffres renforcés) et **lot F** (potions, hors *Magical
   Aptitude*/*Magic Resistance* qui ont besoin d'un nouveau mot-clé) — petits
   et autonomes.
3. **Lot B** (pièges magiques) et **lot C** (*Ambush*) — vocabulaire fermé
   nouveau, à tester en jeu avant toute donnée.
4. **Lot D** (statut de Gardien) et **lot E** (faveurs) selon Q1/Q2 — les
   photos des cartes de compétence conditionnent le lot E.
5. **Lot H** (gabarits de quête) en fond, au fil des autres lots — le
   lecteur « plancher de PV scripté » est à construire UNE fois pour cette
   boîte et Delthrak ensemble (voir `docs/plan-delthrak.md` §3).

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN JEU
→ données**, registres testés dans les deux sens, contrat d'API d'abord,
effets automatiques annoncés. Pest sur une **copie sqlite jetable** ;
`sauvegarder.sh` avant toute migration ; aucune purge de
`groupes`/`personnages`/`joueurs` ; redémarrer `queue` et `queue-jeu` après
le PHP.

> **Mise à jour 2026-10-05 — vérification des annexes du livret** : les
> 4 premières et 4 dernières pages du PDF relues à l'image — déjà largement
> couvertes par la relecture du jour (Magic Reference Chart, mercenaires,
> sorts, artefacts, faveurs, trésors, voir `reference/18_extensions.md`
> § Wizards of Morcar — cartes TRANSCRITES). Rien à ajouter ni à retirer.

## Décisions de René (2026-10-15)

| # | Décision | Conséquence |
|---|---|---|
| **Q2** | **Faveur *Weapon Expert*** : le joueur **choisit le type d'arme à l'obtention** (ni figé par classe, ni tiré au sort). | Reprend la version concrète de Q2 (table reformulée du 2026-10-05, §4). Le type choisi est un état **durable** de la faveur : une colonne en base, jamais en cache. |
| **Q2** | **Les 4 autres faveurs mécaniques** — mercenaire gratuit, +2 PV de Body max, +1 Potion de guérison cumulable, répertoire de sorts supplémentaire — **à PORTER**. | Portables sans nouvelle famille (lot E, comme les potions et les dons). Le mercenaire gratuit touche au modèle de recrutement, **Q1 toujours ouverte** : à coordonner avec le lot D. |
| **Q2** | **Le héros et la faveur restent tirés au hasard.** | Pas de choix du héros ni de la faveur. Lecture retenue : système à part (tirage au lieu visité), **pas** l'arbre de talents. ⚠ À confirmer si René voulait fondre la faveur dans l'arbre. |
| — | **Restent ouvertes** : *Dead Eye*, *Hold the Line*, *Peacekeeper*, *Healing Hands*. | Q2 n'est tranchée qu'**en partie**. **Exécution 2026-10-16** : les quatre faveurs mécaniques sont portées ; les quatre « ouvertes » n'avaient aucun défaut de portage — il reste des choix de règle (voir « Exécution »). |

### Exécution (2026-10-16) — les quatre faveurs mécaniques portées

| Faveur (lieu) | Carte (G1504 p. 23) | Ce qui est fait | Lecteur réel |
|---|---|---|---|
| **Mercenary Guild** | « Recruit a Mercenary without paying their cost. This Mercenary returns to the guild if you ever choose another boon. » | `mercenary_guild` : le prix d'engagement est 0, publié (`offert_par_faveur`) et appliqué par le **recrutement existant** ; un mercenaire (pas un animal) offert à la fois ; mort ou parti, le droit se rouvre. | `RecrutementHub::prixPour()` |
| **Dewdrop Inn** | « Raise your hero's max Body Points by 2. » | `dewdrop_inn` : +2 PV de Body max et courants, une fois par héros, par le point de passage des plafonds. | `Equipement::bonusJauges()` / `apresChangementDeJauges()` |
| **Witch's Hut** | « Gain 1 Potion of Healing. » (« Can be gained multiple times. ») | `witchs_hut`, instantanée : une Potion de guérison dans le sac, cumulable, sans ligne de faveur. | `RangementObjet::ranger()` + le menu des potions |
| **Arcane Library** | « …an additional set of spells that you are eligible to cast. This boon is only for spellcasting heroes. » | `arcane_library` : un répertoire de plus, choisi par le joueur au hub dans une liste blanche lue avec les règles des lanceurs. | `MoteurSorts::repertoiresSupplementaires()` + `attacherElement()` |

Le héros et la faveur restent **tirés au hasard** (décision de René), désormais **au dé injecté** et par un seul
point d'éligibilité (`FaveursHopekins::candidatsDeTirage()`) : les cinq compétences « (1 available) » → une par
groupe ; les trois faveurs sans cette mention → une par héros ; la potion → cumulable, jamais épuisée.

**Dead Eye, Hold the Line, Peacekeeper, Healing Hands — ce que Q2 leur reprochait.** Rien qui soit un défaut de
portage : Q2 est la question d'**architecture** (« système à part ou nouvelles feuilles de l'arbre »), pas un
reproche de comportement. Les quatre sont portées avec un lecteur réel et des tests en jeu (`FaveursHopekinsTest`) :
*Deadeye* aux cinq points de visée (`figuresBloquentPour()`), *Hold the Line* au tour des monstres
(`tenterHoldTheLine()`, un dé de combat, 1 PV fixe sur un crâne), *Peacekeeper* compté au point de passage de la mort
d'un monstre et versé à la fin d'une quête gagnée, *Healing Hands* par la réaction « Passing Items » à 0 PV.
**Restent des choix de règle, non tranchés ici** :
1. **Système à part (retenu depuis le 2026-10-06) ou arbre de talents** — ⚠ toujours « à confirmer » par René.
2. ~~**Faveur acquise une fois pour toutes (tirée au hasard) ou choisie à chaque quête.**~~ **TRANCHÉ le 2026-10-16**
   (décision de René, « Choisissons la faveur au hub si les extensions qui les utilisent sont utilisées ») : le choix
   au hub, par chaque héros, à chaque tour — voir « Exécution (2026-10-16) — le choix au hub » ci-dessous.
3. **Granularité de *Hold the Line*** : « each time a monster moves away » est lu une fois par tour de monstre
   (position de départ comparée à celle d'arrivée), non pas à chaque pas — un monstre qui s'écarte puis revient au contact
   dans le même tour n'est pas visé.
4. **L'entretien du mercenaire offert** : dû (« cost » = le prix d'engagement de la carte) ; une ligne dans
   `reglerEntretien()` l'en exempterait si René le veut.
5. **Le répertoire *elfique* n'est pas offert** comme « set » supplémentaire de la bibliothèque.

## Exécution (2026-10-16) — le choix au hub

Décision de René (2026-10-16) : « Choisissons la faveur au hub si les extensions qui les utilisent sont
utilisées. » Le livret (G1504 p. 21 : « each player may select a Boon of Heroism for their hero » ; p. 23 :
« At the start of each quest, a hero may select one location to visit and receive its Boon of Heroism (if
available). A hero may only benefit from one boon at a time. ») fait donc CHOISIR chaque joueur ; le tirage au
hasard de fin de quête est **retiré**.

| Élément | Décision | Où c'est écrit |
|---|---|---|
| **Quelles campagnes** | Seulement celles qui JOUENT Wizards of Morcar (bestiaire automatique ou boîtes cochées). Sans Morcar : aucune faveur nouvelle, rien d'affiché. Seule boîte qui porte Hopekins Rest / « Boon » dans les textes extraits des livrets officiels (`~/heroquest-livrets/texte/*`, grep : G1504 seul). | `FaveursHopekins::campagneAvecMorcar()`, `BestiaireGroupe::contient()` |
| **Quel tour** | Le hub qui suit une quête GAGNÉE, une fois Gardien. Il se ferme au départ de la quête suivante. Un tour = un choix par héros. | `FaveursHopekins::tourDeHub()` |
| **Qui** | Chaque héros ACTIF, pour SON héros, ou renonce (« may »). `POST /pret` bloque le héros qui n'a pas tranché, comme le type d'arme ; la table peut lancer sans `/pret`. | `FaveursHopekins::refusDeDepart()`, `GroupeController::pret()` |
| **La liste** | Publiée par le serveur : les neuf lieux, `disponible` et `motif` (« Tenue par Brynn. », « Vous la tenez déjà. », « Réservée aux lanceurs… »). La liste blanche est revalidée à la route. | `FaveursHopekins::lieuxPour()`, `/moi` → `visite_hopekins` |
| **Un lieu** | EST la faveur : il remplace celle que le héros tient (« one boon at a time »), dans la même opération. *Witch's Hut* ne relâche rien (instantanée). | `FaveursHopekins::choisirLieu()` → `remettre()` |
| **Les sous-choix** | *Weapon Expert* (type d'arme) et *Arcane Library* (répertoire) gardent leur choix, après le lieu, au hub. | inchangés (`PUT …/faveurs/weapon-expert`, `…/arcane-library`) |
| **État** | EN BASE : `personnage_visites_hopekins` (héros × quête → `lieu`, `null` = renonce). Aucun cache. | migration `2026_10_16_150000_visites_hopekins_tour_de_hub` |
| **Repli (campagnes en cours)** | `groupes.hopekins_quete_tranchee_id` ferme, pour chaque groupe, le tour déjà pourvu par l'ancien tirage : le choix s'ouvre au **hub suivant**. Les faveurs déjà tenues restent tenues. | même migration (pose la colonne et la valeur) |
| **Annonce** | `groupe.hopekins` (`visites` du tour, `en_attente`), rendu par `AnnonceHub` sur la table ET la manette. | `EtatGroupe::hopekinsHub()` |

**Écarts NOMMÉS, non portés** :
- **La double quête 7-8** (« may not visit the Alchemist's Shop or Hopekins Rest between quests », p. 17) : pas de
  quête numérotée dans le modèle (procédural ; la double quête est écartée, `docs/regles/bestiaire-et-rencontres.md`).
  Le hub reste ouvert entre toutes les quêtes.
- **La récompense de conclusion** (p. 21 : « When visiting the outpost, heroes may visit 2 locations and have a
  maximum of 2 Boons of Heroism each on all future quests ») : elle change la règle « une faveur à la fois » après la
  victoire finale. **Non portée — décision de René à prendre.**
- **Les faveurs d'une campagne sans Morcar, déjà tirées** : gardées (aucun retrait rétroactif).

**Tests** : `tests/Feature/Partie/FaveurHubChoixTest.php` (route réelle : hors Morcar, avec Morcar, liste blanche,
un choix par tour, remplacement, renoncement, prêt, Weapon Expert, cache vidée, repli, annonce, aucun tirage) ;
`FaveursHopekins{,Mecaniques,Duree}Test` rejouent chaque faveur par `PUT …/faveurs/lieu`.

**Harnais** : `browser-shots/campagne/hq.sh <slot> visite <lieu>|aucun` ; `/pret` répond 422 « peut visiter un
lieu de Hopekins Rest » tant que le héros n'a pas tranché (README §pret).

**À jouer en prod** : la migration `2026_10_16_150000` (colonne + table + repli) après `image-tools/sauvegarder.sh`,
puis `docker compose restart queue queue-jeu`.

## Exécution (2026-10-16, fin de campagne) — la récompense de conclusion : NON PORTÉE

**Texte exact** (G1504 p. 21, « Reward! », relu à l'image) : « When visiting the outpost, heroes may visit 2 locations
and have a maximum of 2 Boons of Heroism each on all future quests. » **Qui** : chaque héros (même lecture que « a hero
may select one location » p. 23). **Quand** : à une visite de l'avant-poste, donc au début des quêtes qui SUIVENT la
conclusion ; le livret n'ouvre aucune visite à la conclusion elle-même. **Combien de temps** : « all future quests ».
**Cumul** : elle lève « a hero may only benefit from one boon at a time » (p. 23) jusqu'à DEUX, pour les quêtes futures
seulement.

**Comment la fin est détectée** (inchangé depuis b43f2c7) : le Haut Autel détruit, ou le boss final vaincu, passe par
`ResolveurTour::terminerQuete()` → quête `boss_final` `terminee`, groupe en `hub`, puis
`ClotureCampagne::ouvrirVictoire()` ouvre la fenêtre `victoire`.

**Ce que la fin fait réellement** — mesuré par les routes réelles, dans un test jetable (3 tests, supprimé, rien conservé) :
- **A.** Après la victoire finale, `/moi` publie quand même un tour de Hopekins Rest à UN choix (neuf lieux) :
  `tourDeHub()` prend la quête finale (phase en base `hub`, Gardien, Morcar), la manette l'affiche (`auHub` lit
  `groupe.phase`), et la fenêtre de clôture est ouverte **en même temps**.
- **B.** `DELETE /cloture` (204), puis `PUT faveurs/lieu` à `null` (200), puis `POST /pret` (200) : une **quête 4** démarre,
  typée `boss_final`. `JalonsCampagne::type()` rend `boss_final` à toute position ≥ `nb_quetes_total`, et
  `DemarreurQuete::demarrer()` ne refuse pas un arc épuisé. La campagne ne finit donc pas en code : elle s'arrête à la clôture.
- **C.** `POST /cloture/confirmation` (finalisation) : le groupe est supprimé, le héros est détaché, et **sa faveur tenue
  (`dewdrop_inn`) survit** — `personnage_faveurs` n'a pas de `groupe_id` et la purge ne la touche pas.

**Verdict.** La récompense ne sert qu'aux quêtes qui suivent la conclusion. Une campagne close n'en a aucune : rien à
visiter, rien à plafonner. Il reste deux chemins, et aucun n'est une suite de jeu décidée : (1) la suite par annulation de
la fenêtre (B), qui relance des quêtes « boss final » par défaut de `JalonsCampagne::type()` ; (2) une NOUVELLE campagne
avec les mêmes héros, où une faveur survivante (C) agirait. Ce second chemin dépend d'une décision que René n'a pas prise :
les faveurs survivent-elles à la clôture ? La doc de clôture (« referme l'ardoise ») cite PV, sorts, conditions et buffs,
pas les faveurs. Sans cette réponse, la récompense serait une clé décorative : **elle n'est pas construite**. Aucune
migration, aucun seeder, aucun changement de route ni de payload.

**Décisions à prendre (René)**
1. Les faveurs survivent-elles à la clôture ? *Non* : la récompense est décorative, à ne pas porter. *Oui* : elle se porte sur
   la couture du hub (2 lieux à la visite de la quête suivante, `/pret`), avec un drapeau par héros.
2. Le tour publié après la victoire finale (mesure A) : à supprimer (le livret n'ouvre aucune visite après la conclusion), ou
   à garder si la réponse 1 est « oui ». Aujourd'hui il s'affiche sans effet dans la campagne qui se clôt.
3. La suite après la conclusion (mesure B) : un défaut (refuser `/pret` sur un arc épuisé), ou une suite voulue, à définir
   (quêtes normales, pas `boss_final`).
4. Si la récompense se porte : deux faveurs TENUES au maximum — laquelle est relâchée quand un héros en tient deux et en
   reçoit une troisième ? Le livret ne le dit pas.

### Suite (2026-10-16) — mesures A et B corrigées, décision 1 tranchée par René

**A (décision 2 : le tour après la victoire est supprimé).** `Groupe::victoireFinaleAcquise()` (boss final `terminee`,
arc épuisé) est lu par `FaveursHopekins::tourDeHub()` : plus de tour ni dans `/moi`, ni dans `/etat`, ni dans l'annonce, et
`PUT …/faveurs/lieu` refuse (422 `phase` : « La campagne est gagnée : Hopekins Rest ne reçoit plus de visite. »).

**B (décision 3 : un arc épuisé ne démarre plus de quête).** `DemarreurQuete::refusDeDepart()` : `POST /pret` refuse le
`pret: true` (422 `pret`, avant l'écriture du cache, donc aucun « prêt » ne reste en attente), `demarrer()` lève le même
motif (422 `groupe`) — la table et le membre sont refusés pareil. La clôture reste la **seule issue** ; `DELETE /cloture`
annule la fenêtre sans rouvrir le départ, `POST /cloture` la rouvre.

**Décision de René (2026-10-16), décision 1 tranchée : « Les faveurs de Hopekins ne survivent pas à la campagne ; aucune
récompense, car on ne suit pas l'histoire réelle. »** Conséquences, appliquées :
- la récompense de conclusion **n'est pas construite** (rien à porter, rien à plafonner) ;
- à la finalisation — toutes les issues, un seul point de passage, `ClotureCampagne::purger()` — chaque héros de la campagne
  (actif, ou parti en cours de route) voit ses faveurs relâchées par `FaveursHopekins::relacherCampagne()` : effets défaits
  (+2 PV de *Dewdrop Inn*, mercenaire offert, répertoire d'*Arcane Library*), puis lignes supprimées, **avant** la remise à
  plein des PV. *Witch's Hut* n'est pas une faveur tenue : sa potion reste au sac.
- **Données réelles, lecture seule (2026-10-16)** : `personnage_faveurs` ne contient **aucune ligne** en production ; le seul
  héros détaché n'a ni faveur ni pivot. **Aucune migration de données** : il n'y a rien à relâcher.
- Tests : `tests/Feature/Partie/FinDeCampagneTest.php` — A, B, C (victoire, abandon, arrêt d'urgence : faveur relâchée, PV
  max revenus ; une autre campagne intacte ; un héros parti relâché).

**Écarts NOMMÉS, non tranchés :**
- Un héros qui **quitte** une campagne EN COURS (`VoteGroupe::detacherJoueur()`) garde sa faveur jusqu'à la clôture de la
  campagne qu'il a quittée ; s'il rejoint une autre campagne avant, il l'y emporte. Relâcher au départ est une décision à
  prendre.
- Le choix du type d'arme ou du répertoire, s'il était en attente à la victoire, reste accepté par sa route (ce n'est pas un
  tour de Hopekins) ; la manette affiche encore le bouton « Prêt » après la victoire, qui répond désormais le message du
  serveur au lieu de partir. Masquer ce bouton côté front n'est pas fait.

## Exécution (2026-10-23) — « Prêt » après la victoire : déjà corrigé, vérifié (chantier `ext-restes`)

La note du 2026-10-16 (« la manette affiche encore le bouton « Prêt » après la victoire… Masquer ce bouton côté front n'est pas
fait ») est **périmée**. Corrigée le même jour dans le commit `18ee278` (« Fin de campagne verrouillée… ») : le serveur publie la
décision (`EtatGroupe` → `groupe.depart_possible`, `groupe.depart_motif`, au hub seulement), `ManetteView` et `TableView` masquent
« Prêt » et « Lancer la quête » sur `depart_possible === false` et affichent le motif. Testé par `FinDeCampagneTest` (D : /etat publie
le départ refusé après la victoire finale, fenêtre annulée comprise). Aucun changement ce jour.
