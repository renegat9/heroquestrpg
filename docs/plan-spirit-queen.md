# Plan — Définir entièrement l'extension *Spirit Queen's Torment*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> iront dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §Spirit Queen's Torment.
>
> **Source** : livret de quêtes officiel **G0053** (© 2023 Hasbro, 36 pages
> imprimées / 19 pages PDF), téléchargé le 2026-10-04 depuis
> `instructions.hasbro.com` (page produit *avalon-hill-heroquest-spirit-
> queens-torment-quest-pack*). Texte extrait page par page
> (`texte/G0053_en-us/`). Les stats des adversaires nommés sont données en
> PROSE, pas en tableau à colonnes (« stats of a Dread Warrior with 1
> additional Defend die », « 6 Body Points ») — **relues sur le rendu PNG**
> des pages imprimées 11 (Kavra) et 33 (Nelath) par prudence malgré
> l'absence de risque de désalignement de colonnes ; aucun écart avec le
> texte extrait. Les numéros ci-dessous sont les **pages imprimées**.

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : les règles de la boîte (p. 5), la Boutique de
l'Alchimiste (p. 3), 14 quêtes (p. 7-33), la liste des objets réutilisés du
jeu de base (p. 34).

**Il ne porte PAS de tableau de monstres** (aucune page « Monsters of... ») :
tous les monstres génériques viennent du bestiaire de base, et les trois
seuls adversaires chiffrés (Kavra, les statues animées, Nelath/la Reine des
Esprits) sont donnés comme des **variantes en prose** d'un monstre déjà
existant (Dread Warrior), jamais un nouveau type.

**Contenu physique (déduit du texte, aucune page « components » dédiée)** :
figurines réutilisées (Gargouille pour Kavra ET Nelath, Dread Warriors,
squelettes, momie, abomination, zombies), **9 cartes d'artefact** nommées,
des parchemins de sort (Tempest, Fire of Wrath, Courage), et **la carte de
héros Barde** — publiée avec cette boîte au sein du HasLab Mythic Tier
(`reference/18_extensions.md` §HasLab Mythic Tier, photo fournie par René
le 2026-08-11). Voir §1.

## 1. Déjà en place

| Élément du livret | Chez nous |
|---|---|
| **Le Barde est déjà jouable** (A2 D2 B5 Mi4, dague de départ, 3 sorts transcrits, bonus conditionnel d'armure) — sa carte est publiée avec **cette** boîte, ce qui sourçait déjà sa règle de remplacement posthume (ligne 1974 de `reference/18_extensions.md`) | `ClasseHerosSeeder`, Mythic Tier, sourcé 2026-08-11 (`reference/01_personnages.md` §4bis-4quater) |
| **9 des 9 artefacts nommés existent déjà au catalogue**, réutilisation du paquet officiel de 59 cartes : *Wizard's Staff* → **Bâton du Magicien** ; *Orc's Bane* → **Fléau des Orques** ; *Borin's Armor* → **Armure de Borin** ; *Wizard's Cloak* → **Cape du Magicien** ; *Spell Ring* → **Anneau de Sort** ; *Fortune's Longsword* → **Longue épée de Fortune** ; *Phantom Blade* → **Lame Fantôme** ; *Elixir of Life* → **Élixir de Vie** ; *Talisman of Lore* → **Talisman du Savoir** | `ObjetSeeder` — **aucun nouvel artefact à porter pour cette boîte**. Voir correction §2.1 (une des 9 n'était pas identifiée comme telle) |
| Toute la Boutique de l'Alchimiste (p. 3) : *Potion of Restoration* (300po), *Potion of Dexterity* (100po), *Venom Antidote* (300po), *Potion of Battle* (200po) | `ObjetSeeder` : **Potion de restauration** (300), **Potion de dextérité** (100), **Antidote au venin** (300), **Potion de bataille** (200) — prix et effets identiques, rien à ajouter |
| *Courage spell scroll* (q. 4 et q. 6) | **Courage** est déjà un sort héros du catalogue (élément feu) — le parchemin trouvé en jeu n'a besoin d'aucun nouveau sort |
| *Tempest spell scroll* (q. 7) | **Tourmente** est déjà un sort de Dread du catalogue — ⚠ trouvé ici comme PARCHEMIN DE HÉROS, pas comme sort de monstre : à vérifier s'il existe un équivalent héros de Tourmente ou si c'est une incohérence du livret (aucun sort héros « Tourmente » trouvé dans `SortSeeder` à ce jour, voir Q5) |
| Kavra, Nelath : « Dread Warrior » de base + variantes | `Guerrier du Chaos` (M6 A3 D4 B1 Mi3, `MonstreSeeder`) — les deux adversaires nommés dérivent de cette ligne, voir lot C |
| *Lightning Bolt, Ball of Flame, Command, Fear, Firestorm, Cloud of Dread, Sleep* (sorts de Dread cités) | **Les SEPT existent déjà** dans `SortDreadSeeder` : Éclair de Chaos, Boule de Flammes, Commandement, Frayeur, Tempête de feu, Nuée d'Effroi, Sommeil. **Aucun nouveau sort de Dread à porter pour cette boîte** — seulement de nouveaux porteurs (lot C) |
| Mind damage producteur côté monstre | `MoteurDegats::infligerMindAHeros()` (2026-09-06) — condition qui débloque le lot D (conversion Body→Mind) sans lequel cette boîte serait restée hors de portée il y a un mois |
| Contrôle d'un allié par un joueur, juste après son héros (« Controlling Allies », p. 4) | **Même question ouverte** que `docs/plan-ogre-horde.md` Q2 (allié animal joué par le joueur plutôt que le moteur) — cette boîte en donne une SECONDE occurrence sourcée (Sigill, Udren), preuve que ce n'est pas un besoin isolé à Ogre Horde |

## 2. Corrections à `reference/18_extensions.md`

La section Spirit Queen's Torment (lignes 1158-1286) contient **deux
erreurs factuelles** trouvées au recoupement avec le texte intégral du
livret, et une troisième à nuancer :

1. **La Rapière n'existe nulle part dans ce livret.** Le texte actuel dit :
   « équipé d'une Rapière (arme trouvée en jeu, cf. liste d'objets p. 34 —
   *pas* listée nommément mais le mécanisme suivant s'y réfère) ». La
   « liste d'objets p. 34 » (en réalité l'Artifact Reference, qui liste
   les 13 cartes réutilisées — voir §1) **ne contient aucune Rapière**, et
   le mot « rapier »/« rapière » n'apparaît **dans aucune des 19 pages**
   extraites du PDF (vérifié par recherche exhaustive). La seule source de
   ce détail est le paragraphe suivant du même document, explicitement
   marqué « source tierce, à confirmer » (bloodandspectacles.blogspot.com)
   — la citation « p. 34 » qui le précède est fausse et laisse croire à
   une source officielle là où il n'y en a aucune. À corriger : retirer la
   phrase « cf. liste d'objets p. 34 », le livret officiel ne dit RIEN sur
   l'arme de départ du Barde (cohérent avec « aucune fiche chiffrée »).
2. **« Statues de pierre animées (quêtes 10 et 13) » est inexact : la
   règle n'apparaît QUE dans la quête 10** (« Tower of Earth », p. 25 :
   « All monsters are stone statues and roll 1 additional Defend die,
   except the Gargoyle »). La quête 13 (« Tower of Fire », p. 31) ne
   mentionne ni statue ni dé de défense supplémentaire — elle porte une
   règle totalement différente (fournaises à désamorcer, voir lot F).
   Vérifié par recherche exhaustive du mot « statue » sur les 19 pages : une
   seule occurrence, p. 25.
3. **Les 9 artefacts nommés sont TOUS déjà au catalogue** (§1) — le texte
   actuel liste « 9 artefacts nommés trouvables en jeu » sans dire qu'ils
   sont déjà portés depuis le passage aux 59 cartes officielles. À
   enrichir plutôt qu'à corriger : c'est une bonne nouvelle qui change
   l'ampleur du chantier (zéro artefact à porter), pas une erreur du
   document.

## 3. Les règles à porter, par lots

### Lot A — Corrections de `reference/18` (ci-dessus). Aucun code.

### Lot B — Les quatre tours élémentaires, complétion libre (q. 10-13, p. 25-31)

| Élément | Citation | Chez nous |
|---|---|---|
| Quatre tours visitables dans l'ordre choisi par les joueurs, chacune gardant un artefact élémentaire, les quatre nécessaires pour débloquer la cinquième (finale) | « Each tower is empowered by an artifact. All four must be gained to access the final tower » (q. 11, p. 27). Terre → **Armure de Borin** (q. 10, p. 25) ; Eau → **Élixir de Vie** (q. 11, p. 27) ; Air → **Cape du Magicien** (q. 12, p. 29) ; Feu → **Anneau de Sort** (q. 13, p. 31) | Changement STRUCTUREL : le moteur suppose une progression strictement linéaire de quête en quête (`positionArc` séquentiel, `JalonsCampagne`). Une campagne à embranchement choisi par les joueurs demande une notion de « quêtes disponibles en parallèle avec prérequis cumulatifs » — rien d'équivalent au moteur actuel |
| Même famille que *Jungles of Delthrak* « Choose Your Path » (2 puis 3 embranchements, déjà recensé comme non porté dans `reference/18_extensions.md` ligne ~1441) | — | **Deux boîtes indépendantes documentent le même besoin** de campagne non linéaire. Un seul mécanisme à concevoir, pas deux — mais c'est le plus gros chantier structurel des deux boîtes combinées, largement au-delà d'un lot ordinaire |

⚠ Ce lot est fondamentalement différent des autres : il ne touche pas un
mot-clé d'effet isolé mais la forme même d'une campagne (linéaire vs
graphe). Vu l'ampleur, il mérite d'être tranché par René (Q1) **avant**
tout travail sur cette boîte plutôt qu'enterré dans un lot parmi d'autres.

### Lot C — Les adversaires nommés (variantes de Guerrier du Chaos)

| Nom | Stats dérivées | Règle propre |
|---|---|---|
| **Kavra** (sorcière brigande, q. 3, p. 11 — vérifié sur rendu PNG) | M6 A3 **D5** B1 Mi3 (Guerrier du Chaos +1 dé de défense) | « She may cast the Dread spells Lightning Bolt and Ball of Flame. On her first turn only, she may cast both spells simultaneously. » — Éclair de Chaos + Boule de Flammes, les deux déjà au catalogue. Le « cast deux sorts simultanément au premier tour » est une capacité ponctuelle non couverte (voir Q3) |
| **La Reine des Esprits / Nelath** (boss final, q. 14, p. 33 — vérifié sur rendu PNG) | M6 A3 D4 **B6** Mi3 (Guerrier du Chaos, Body porté à 6) | « May cast the following Dread spells: Command, Fear, Lightning Bolt, and Firestorm. She may cast two spells in a single turn. » — Commandement + Frayeur + Éclair de Chaos + Tempête de feu, les quatre déjà au catalogue. « Deux sorts par tour » : même manque que Kavra |
| Statues de pierre animées (q. 10 SEULEMENT — correction §2.2) | tout monstre de la quête +1 dé de défense, sauf la Gargouille | Variante de présentation scopée à une seule quête, pas un nouveau mot-clé — aucune rencontre procédurale n'a besoin de ce drapeau hors de cette quête précise, donc rien à construire côté catalogue |

**« Lancer deux sorts de Dread au même tour »** (Kavra au premier tour,
Nelath systématiquement) est la seule pièce manquante des deux profils : le
moteur de Dread (`MoteurDread`) suppose un sort par tour par instance,
cohérent avec la règle générale « un seul sort par tour » documentée pour
Ogre Horde. Une capacité nommée (`double_sort`, portée par l'instance ou
le palier) découplerait ce cas sans toucher la règle générale.

### Lot D — Dégâts convertis en Mind, zone de quête (q. 8-9, p. 21-23)

> « all monsters in this quest are spirits and all damage heroes take will
> affect their Mind Points instead of their Body Points. Heroes still deal
> Body Point and Mind Point damage normally to monsters. » (q. 8, p. 21,
> confirmé identique q. 9, p. 23)

`MoteurDegats::infligerMindAHeros()` existe depuis le 2026-09-06 (plan
glace) — **la condition bloquante d'hier est résolue**. Ce qui manque
encore : un AIGUILLAGE au niveau de la QUÊTE ENTIÈRE (pas d'un sort ou d'un
monstre précis) qui redirige TOUTE attaque de monstre vers Mind au lieu de
Body, pour la durée des quêtes 8 et 9. Candidat naturel : un drapeau porté
par le gabarit/l'arc de quête (`quetes.degats_mind_uniquement` ou
équivalent), lu au même point d'entrée que `infligerMindAHeros()` décide
déjà entre les deux jauges pour un sort donné — PAS un nouveau mot-clé par
monstre, un override contextuel qui s'applique à la résolution normale de
combat.

### Lot E — Ressource d'insubstantialité à charges (q. 14, p. 33)

> « Each hero may become insubstantial three times. Each use can be used to
> ignore any one source of damage, to pass through walls or solid objects
> on a hero's next move (they die if they end in a solid square), or to
> roll 2 additional movement dice. »

Nouvelle ressource par héros, à **durée de quête** (pas de campagne), 3
usages interchangeables entre trois effets déjà lisibles séparément
(annulation de dégâts type `soin_urgence`/réaction, franchissement de mur
type `franchit_mur` du sort Traverser la Pierre, 2 dés de mouvement
bonus). La nouveauté n'est AUCUN des trois effets pris seul — c'est le
**compteur partagé interchangeable**, jamais rencontré ailleurs dans le
jeu de base (le plus proche est une charge d'objet à USAGE UNIQUE, pas à
menu de choix). Scopé à la quête 14 uniquement dans le livret ; generaliser
ou non est affaire de Q4.

### Lot F — Terrain et déplacement (q. 10-13, p. 25-31)

| Élément | Citation | Chez nous |
|---|---|---|
| **Sol meuble, immobilisation probabiliste** (Tour de Terre, q. 10, p. 25) | « if the result of the movement dice is odd, they become stuck [...] and take 1 Body Point of damage. Once stuck, a hero may only roll 1 die for movement, and they only move if the result of the die is an even number. » | Nouveau comportement de terrain : la parité du jet de déplacement décide d'un blocage, pas un coût fixe. `App\Engine\MotsClesTerrain` ne connaît que `bloque_mouvement`/`bloque_vue`/`cout_deplacement` — aucun des trois ne capture une condition sur le RÉSULTAT du dé. Candidat de mot-clé nouveau si on veut le généraliser (voir Q4) |
| **Courants, coût doublé** (Tour d'Eau, q. 11, p. 27) | « Each square in these rooms takes 2 movement points to traverse » | **Déjà portable tel quel** — `cout_deplacement` existe, même mécanique que le terrain « sable/toile/jungle » de Jungles of Delthrak |
| **Courants, bonus de déplacement** (Tour d'Eau, q. 11, p. 27) | « A hero may move 2 extra squares when they first enter this area » | Terrain qui BONIFIE le déplacement plutôt que de le pénaliser — absent du vocabulaire fermé actuel (`cout_deplacement` ne prend que des valeurs ≥ coût normal). Premier cas d'un terrain AVANTAGEUX rencontré |
| **Vents de couloir, aléatoire dirigé** (Tour d'Air, q. 12, p. 29) | « If the roll result is a shield, they move 2 additional squares in the direction of their choice. If the roll result is a skull, they move 2 squares in the direction of Zargon's choice. » | Même mécanique que celle déjà recensée dans `reference/18_extensions.md` (« Zone à mouvement forcé partiellement aléatoire ») — confirmé par cette lecture, rien à corriger, juste à construire : un post-déplacement conditionnel après le mouvement normal |
| **Fournaise désamorçable, dégât périodique** (Tour de Feu, q. 13, p. 31) | « a hero can disarm the furnace as if it were a trap. Until this is done, a hero ending their turn in this room must roll 1 combat die, taking 1 Body Point of damage if they roll a skull. Monsters are used to the heat and do not take this damage. » | Mobilier qui se comporte comme un PIÈGE désamorçable (même verbe que `MoteurPieges`), mais qui inflige des dégâts À CHAQUE TOUR tant qu'il reste actif plutôt qu'au déclenchement une fois — absent du vocabulaire actuel des pièges, plus proche d'un terrain persistant que d'un piège classique |
| **Gargouille désamorçable au lieu de combattue** (Tour de Feu, q. 13, p. 31) | « The Gargoyle [...] can be destroyed through combat, or it can be disarmed as if it were a trap. » | Renforce le précédent du Lot E de *Spirit Queen* lui-même (résolution alternative du boss, ci-dessous) : une CRÉATURE ordinaire, pas seulement le boss final, admet ici une résolution non létale. Signal que « résolution alternative » n'est pas un besoin isolé au seul combat de boss |

### Lot G — Résolution alternative du combat de boss (q. 14, p. 33)

> « Though she can be defeated in combat, if a hero wants to remind her of
> her past as Nelath or shake her from her servitude to Zargon they may use
> their action to do so. That hero rolls 1 combat die. On a shield, the
> Spirit Queen is affected [...] and takes 1 Mind Point of damage. If the
> queen is reduced to 0 Mind Points in this manner, she breaks the
> enchantment [...] and thanks the heroes for saving her before fading. »

Deux chemins de résolution pour le même combat : dégâts de Body classiques
(mort), ou une action dédiée qui draine le Mind (1 dé, bouclier = 1 point)
jusqu'à 0 — « sauvée » plutôt que tuée, avec un texte de fin différent.
*Coût* : ce n'est PAS un sort ni un mot-clé d'effet — c'est une seconde
VOIE DE VICTOIRE pour une seule rencontre. `ClotureCampagne` connaît déjà
un champ `issue` à plusieurs valeurs (`victoire`, `abandon`...) : une
troisième valeur ou un simple drapeau `quetes.boss_epargne` (additif,
cohérent avec « ajouter une colonne ») suffirait à distinguer la narration
de fin sans toucher la mécanique de victoire elle-même — à condition que
la cible (combat de boss classique OU action spéciale) soit déclarée
d'avance par le gabarit, pas improvisée par l'IA (principe « le moteur
décide, l'IA narre »).

### Lot H — Remplacement posthume du Barde + alliés jouables

| Élément | Citation | Chez nous |
|---|---|---|
| **Remplacement posthume, une fois par campagne** | « If no one is playing the bard hero and a hero dies, place the bard hero in the room with the dead hero. The player of the dead hero now controls the bard hero [...] may not take actions on the turn they appear. [...] may only happen once during this quest series. » (p. 4) | Filet anti-TPK : classe de réserve activable uniquement par la mort d'un titulaire. Nécessite un compteur « déjà utilisé cette campagne » (colonne sur `groupes`, pas en cache) et un point d'ancrage à la résolution de mort d'un héros |
| **Contrôle d'un allié par le joueur, juste après son héros** | « a player controls the ally. The player takes their regular turn and then the ally's turn. [...] treated as a hero by all monsters and traps, but does not take a share of any treasure. » (p. 4) | **Identique à la question ouverte Q2 d'Ogre Horde** (allié animal joué par le joueur plutôt que le moteur) — deuxième occurrence sourcée, cette fois pour des PNJ de quête (Sigill, Udren) plutôt qu'un compagnon animal. Renforce l'intérêt de trancher Q2 une bonne fois plutôt que boîte par boîte |
| Échec de quête scripté si un PNJ meurt (Udren, q. 5) | « If Udren is killed before the special action [...] is taken, the heroes fail the quest! » | Scénarisé, propre à une quête jamais rejouée telle quelle — peut nourrir un futur gabarit « escorte/protection » mais n'a pas besoin de catalogue dédié aujourd'hui |

### Lot I — Ce que les 14 quêtes apportent au générateur

- **Fouille garantissant un vrai trésor**, même motif que dans *Prophecy of
  Telor* (q. 11, p. 27 : « If they draw a wandering monster or hazard card
  [...] continues drawing until they draw a card that isn't [one of
  those] ») — troisième occurrence du même besoin tous boîtes confondues
  (Ogre Horde, Telor, Spirit Queen). Voir `docs/plan-telor.md` Lot F ; un
  seul mécanisme à construire pour les trois.
- **Potion de fortune aléatoire** (q. 4, p. 13) : boire une potion
  anonyme tire son effet au dé de combat (bouclier blanc = soin 1d6 Body,
  crâne = *Potion de bataille*, bouclier noir = -1 Body). Structure
  réutilisable comme mobilier fouillable à table d'effet aléatoire, même
  famille que la Caisse de ravitaillement d'Ogre Horde.
- **Coffre piégé qui lance un sort de Dread** (q. 2 et q. 6 : « the Dread
  spell Ball of Flame is cast on that hero » si la fouille précède le
  désamorçage) — un TYPE de piège qui n'inflige pas des dégâts bruts mais
  déclenche un sort de Dread complet sur le fouilleur. Absent du
  vocabulaire actuel des pièges (`MotsClesPiege` connaît des dégâts/effets
  fixes, pas « lance ce sort du catalogue Dread »). Récurrent (2
  occurrences) : bon candidat de mot-clé si une autre boîte le confirme.
- **PNJ allié échangé contre objet** (aucune occurrence directe, mais
  « A hero can spend an action here to restore all of their lost Mind
  Points. Each hero can benefit from this effect only once » q. 8, p. 21)
  — foyer de restauration de Mind à usage unique par héros, variante du
  mobilier existant (Foyer de guérison de First Light soigne du Body) :
  candidat direct de mobilier `boite: spirit_queen`, même patron.

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | Les quatre tours élémentaires à ordre libre (Lot B) : construire le graphe de quêtes à prérequis cumulatifs (gros chantier, partagé avec *Jungles of Delthrak*), ou écarter cette structure et ne garder que les 4 artefacts/boss comme contenu (quêtes linéarisées dans un ordre fixe choisi par le gabarit) ? | **Écarter pour l'instant** : le gain (ordre choisi par les joueurs) est mineur au regard du coût (un second modèle de progression de campagne). Les 4 tours peuvent nourrir 4 quêtes linéaires ordinaires sans perdre leur contenu |
| **Q2** | Alliés jouables par le joueur (Sigill, Udren) juste après son héros plutôt que par le moteur : même arbitrage que Q2 d'Ogre Horde ? | Trancher **une seule fois pour les deux boîtes** — c'est exactement la même demande, formulée deux fois indépendamment par deux livrets différents |
| **Q3** | « Lancer deux sorts de Dread au même tour » (Kavra 1er tour, Nelath systématiquement) : capacité nommée par instance, ou assouplir la règle générale « un seul sort par tour » pour les profils `boss`/nommés ? | Capacité nommée (`double_sort`) — la règle générale a sa raison d'être (budget d'usages), l'assouplir globalement romprait l'équilibre partout ailleurs |
| **Q4** | Les mécaniques de terrain nouvelles (sol meuble probabiliste, courant bonus, insubstantialité à charges) : les généraliser en mots-clés réutilisables ailleurs, ou les garder scopées à cette seule boîte (`boite: spirit_queen` sur chaque entrée) ? | Scoper d'abord (`boite: spirit_queen`) — les généraliser sans un second cas d'usage réel serait inventer un vocabulaire pour un seul client, l'inverse de la règle « pas de clé décorative » |
| **Q5** | *Tempest* trouvé comme PARCHEMIN DE HÉROS (q. 7) alors que notre seule « Tourmente » est un sort de Dread côté MJ : incohérence du livret à assumer telle quelle (le héros apprend un sort normalement réservé au MJ), ou lecture alternative (un parchemin à usage unique qui imite l'effet, sans toucher au catalogue de sorts héros) ? | Lecture alternative : un OBJET consommable à effet one-shot (copie l'effet de Tourmente sans devenir un sort permanent du héros) — évite de faire apparaître un sort de Dread dans le répertoire héros, ce que rien d'autre dans le jeu ne fait |
| **Q6** | Le boss à résolution alternative (Lot G) : généraliser un champ `quetes.issue_alternative`/`boss_epargne` réutilisable pour de futurs boss « rachetables », ou le garder scopé à Nelath ? | Généraliser le CHAMP (coût nul, une colonne), mais ne construire qu'UNE narration (Nelath) tant qu'aucun second boss ne le demande |

## 5. Sources à demander (photos des cartes)

Comme pour Telor, très peu de dette photo — l'essentiel est sourcé inline :
- la carte du **Barde** publiée avec CETTE boîte, pour vérifier qu'elle ne
  diverge pas de la fiche Mythic Tier déjà transcrite (2026-08-11), et
  confirmer ou infirmer l'arme de départ (correction §2.1 : le livret ne
  la nomme pas) ;
- les 3 objets EXPLICITEMENT décrits comme des cartes propres à cette
  boîte sans effet donné dans le texte : ***Rabbit Boots***, ***Dust of
  Disappearance*** (q. 4, 6 — « Italicized terms [...] reference items with
  corresponding cards found in this quest pack »), et ***Anti-Poison
  Quill*** (q. 2) — aucun effet n'est décrit nulle part dans les 19 pages,
  seul le nom est donné en trouvaille ;
- le parchemin ***Fire of Wrath*** (q. 7, p. 19) : nom cité une seule fois
  comme trouvaille, aucun texte d'effet, absent de notre catalogue de
  sorts sous toute forme — ⚠ candidat non trouvé, pas un sort déjà porté
  sous un autre nom (vérifié).

Rien d'autre ne bloque : les lots C à I sont sourcés entièrement par le
livret, à l'exception des trois divergences nommées ci-dessus, qui
resteront `⚠ non trouvé` dans `config/cartes.php` jusqu'à une photo.

## 6. Ordre proposé

1. **Lot A** : corriger `reference/18` (Rapière fantôme, statues q. 10
   seulement, les 9 artefacts déjà portés). Aucun code.
2. **Lot C** (adversaires nommés) : petit, tous les sorts déjà au
   catalogue, seulement 2 lignes `monstres` + 1 capacité `double_sort`.
3. **Lot F** (terrain) : plusieurs petites pièces indépendantes, dont deux
   déjà couvertes par le vocabulaire existant (courant, vent) et deux
   nouvelles (sol meuble, courant bonus) — à scoper (Q4) avant de coder.
4. **Lot D** (dégâts→Mind) : débloqué par `infligerMindAHeros()`, petit
   chantier d'aiguillage contextuel.
5. **Lot G** (résolution alternative du boss) : dépend d'avoir d'abord
   statué sur le champ `issue`/`boss_epargne` (Q6).
6. **Lot H** (Barde posthume + alliés jouables) : la partie « alliés
   jouables » attend l'arbitrage Q2, commun avec Ogre Horde — ne pas la
   construire deux fois en parallèle sur les deux boîtes.
7. **Lot E** (insubstantialité à charges) et **Lot B** (tours
   élémentaires) selon Q1 et Q4 — les deux plus gros chantiers
   structurels, à ne lancer qu'après arbitrage explicite.
8. **Lot I** (gabarits) : la fouille garantie et le piège-qui-lance-un-
   sort sont les deux morceaux à fort rendement (mutualisables avec
   d'autres boîtes), le reste est du mobilier ponctuel à faible coût.

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN
JEU → données**, registres testés dans les deux sens, contrat d'API
d'abord, effets automatiques annoncés. Pest sur une **copie sqlite
jetable** ; `sauvegarder.sh` avant toute migration ; aucune purge de
`groupes`/`personnages`/`joueurs` ; redémarrer `queue` et `queue-jeu` après
le PHP. Une campagne d'agents (`campagne-agents`) sur le thème
`spirit_queen` clôt les lots C, D, F et G.
