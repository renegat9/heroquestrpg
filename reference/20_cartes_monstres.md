# 20 — Cartes de monstre officielles (scans de René, 2026-10-05)

**Source :** photographies de René de ses propres cartes de monstre, toutes
boîtes confondues (base 2021 et extensions), numérisées dans
`/home/reneg/heroquest-livrets/drive/ennemis/rendu/ennemies_p01.png` …
`ennemies_p41.png` (41 images, une carte par image ; PDF d'origine
`ennemies.pdf`, rendu à 200 dpi avec PyMuPDF). **Méthode : lecture directe
à l'image**, aucune transcription de mémoire. Les cinq champs relevés pour
chaque carte sont ceux imprimés sur la carte elle-même : *Movement Squares,
Attack Dice, Defend Dice, Body Points, Mind Points*, le texte de capacité
mot pour mot entre guillemets, et l'année de copyright Hasbro (qui date la
boîte quand le nom seul ne suffit pas). **41 cartes lues, 0 illisible.**

**Pourquoi ces cartes comptent plus qu'une PDF de fan.** Le *Rulebook* 2021
dit explicitement : *« All the information on these [monster] cards can
also be found in the monster chart on the Game Master's screen »* (LR p. 7)
— les cartes de monstre et l'écran du MJ portent **le même tableau**. Doc 16
§4.1-4.4 avait dû se rabattre sur `sjeng-monsters.pdf` (Ye Olde Inn, une
reproduction de fan) pour sourcer les 8 monstres de base, faute d'avoir ces
cartes sous la main — « ⚠ non trouvé dans le livret » y est resté jusqu'au
2026-08-09. Les scans de René **sont** ces cartes : une source plus directe
que le PDF de fan qu'on avait dû accepter à sa place. Voir la section finale
pour ce que la comparaison change.

Quatre images (p29-p32) ne sont **pas des monstres** : ce sont des cartes de
mercenaire déjà portées (`MercenaireSeeder`) — relevées quand même pour
vérifier le travail déjà fait.

⚠ Wizards of Morcar est transcrit en parallèle par un autre agent dans
`reference/18_extensions.md` — ce document n'y touche pas, et aucune des 41
cartes lues ici ne s'en est révélée issue (toutes datent 2021-2024, gamme
Avalon Hill).

---

## HeroQuest Game System (boîte de base, © 2021)

Composition physique confirmée par LR p. 4 : « 8 orcs, 6 goblins,
**3 abominations**, 4 Dread warriors, Dread sorcerer, gargoyle, 4 skeletons,
2 zombies, 2 mummies » — l'Abomination est comptée dans le MÊME inventaire
que les 7 autres, pas dans celui d'une extension.

| Carte (p.) | M/A/D/B/Mi | Texte de capacité | Chez nous | Verdict |
|---|---|---|---|---|
| Goblin (p12) | 10/2/1/1/1 | — | Gobelin 10/2/1/1/1 | ✅ identique |
| Skeleton (p04) | 6/2/2/1/0 | — | Squelette 6/2/2/1/0 | ✅ identique |
| Orc (p06) | 8/3/2/1/2 | — | Orque 8/3/2/1/2 | ✅ identique |
| Zombie (p02) | **5**/2/3/1/0 | — | Zombie 4/2/3/1/0 | ⚠ divergence Déplacement (carte 5, nous 4) — non déclarée |
| Mummy (p01) | 4/3/4/**2**/0 | — | Momie 4/3/4/**1**/0 | ⚠ divergence Body (carte 2, nous 1) — non déclarée |
| Dread Warrior (p03) | **7**/**4**/4/**3**/3 | — | Guerrier du Chaos 6/3/4/1/3 | ⚠ divergence Déplacement+Attaque+Body (carte 7/4/3, nous 6/3/1) — non déclarée. Nom 1989 « Chaos Warrior » = 2021 « Dread Warrior » (déjà documenté) |
| Gargoyle (p05) | 6/4/**5**/**3**/4 | — | Gargouille 6/4/4/1/4 | ⚠ divergence Défense+Body (carte 5/3, nous 4/1) — non déclarée |
| Abomination (p11) | 6/3/3/2/3 | — | **absente du catalogue** | ➕ monstre à ajouter — stats désormais SOURCÉES (étaient `⚠ non trouvé`, doc 18 note †) |

Fimir n'a pas de carte 2021 (confirmé : c'est un nom 1989 Milton Bradley que
le projet garde sciemment hors roster officiel — déjà documenté, rien de
neuf).

---

## The Frozen Horror (© 2022)

| Carte (p.) | M/A/D/B/Mi | Texte de capacité | Chez nous | Verdict |
|---|---|---|---|---|
| Frozen Horror (p08) | 8/5/4/6/4 | « Special Ability…Spellcaster » | Horreur des Glaces 8/5/4/6/4, `resistance_magique`, archétype `horreur_glacee`, grande taille | ✅ identique (la carte ne détaille ni la résistance magique ni la grande taille — déjà sourcées ailleurs dans doc 18) |
| Ice Gremlin (p09) | 10/2/3/3/3 | « Special Ability…Steal items » | Gremlin des glaces 10/2/3/3/3, `vol_objet` | ✅ identique |
| Yeti (p10) | 8/3/3/5/2 | « Special Ability…Hug attack » | Yéti 8/3/3/5/2, `etreinte` | ✅ identique |
| Polar Warbear (p28) | 6/**4/4**/3/6/2 | « Special Ability…Two attacks » | Ours polaire de guerre 6/4/3/6/2, `deux_attaques` | ✅ identique (confirme le 4+4 du livret contre le 3+3 du paquet de fan, déjà la valeur retenue) |

---

## The Mage of the Mirror (© 2023)

| Carte (p.) | M/A/D/B/Mi | Texte de capacité | Chez nous | Verdict |
|---|---|---|---|---|
| Elven Archer (p07, dupliquée p16) | 6/4(1)/2/3/2 | « Elven archers roll 4 combat dice when attacking non-adjacent targets… 1 Attack die attacking adjacent targets. » | Archer elfe 6/1(contact)/4(distance)/2/3/2 | ✅ identique |
| Elven Warrior (p14) | 6/4/3/3/2 | — | Guerrier elfe 6/4/3/3/2 | ✅ identique |
| Giant Wolf (p13) | 9/6/3/5/1 | — | Loup géant 9/6/3/5/1, grande taille, `charge` | ✅ identique |
| Ogre (p22) | 4/6/4/5/2 | — | Ogre 4/6/4/5/2, grande taille | ✅ identique |

Pas de carte scannée pour l'Archimage elfe (Sinestra) — c'est un boss de
quête nommé, pas une carte de monstre générique ; ses stats restent sourcées
du texte de quête (doc 18), cohérent avec le fait qu'aucun autre boss nommé
de quête (Doralf, Gruzbella, Sir Ragnar…) n'a de carte générique non plus.

---

## Rise of the Dread Moon (© 2023)

| Carte (p.) | M/A/D/B/Mi | Texte de capacité | Chez nous | Verdict |
|---|---|---|---|---|
| Dread Cultist (p17) | 7/2/2/1/2 | « …cast…once per quest: Dreadlights and Channel Dread. » | Cultiste du Dread 7/2/2/1/2, archétype `culte_effroi` (Feux de l'Effroi, Canaliser l'Effroi) | ✅ identique |
| Specter (p20) | 8/3/3/1/0 | « …undead, ethereal, and may cast…at will: Channel Dread. » | Spectre 8/3/3/1/0, `ethere`, archétype `spectre_hurlant` (Canaliser l'Effroi) | ✅ identique |
| Magus Guard (p21) | 8/4/4/3/3 | « …cast…once per quest: Ball of Flame and Tempest. » | Garde-mage 8/4/4/3/3, archétype `garde_magus` | ✅ identique |
| Assassin (p15) | 10/5/3/2/3 | « Each Assassin may attack diagonally. » | Assassin 10/5/3/2/3, `capacites: []` | ⚠ capacité imprimée ABSENTE chez nous : attaque en diagonale, non portée pour ce monstre |
| Dread Wraith (p19) | 9/6/**4**/5/5 | « …is ethereal and may cast…once per quest: Dreadlights, Channel Dread, Fear, and Summon Specters. » | Ombre du Dread 9/6/**3**/5/5, `ethere`, archétype `spectre_effroi` (les 4 sorts) | ⚠ divergence Défense **déjà déclarée** (`BestiaireSourceTest` — livret 4, nous 3, raison : viabilité du combat contre l'éthéré). La carte confirme le chiffre livret (4) — la divergence déclarée reste correcte et sa raison tient toujours |

Pas de carte scannée pour Sir Ragnar ni Magrian — bosses de quête nommés,
même raisonnement que Sinestra ci-dessus.

---

## Against the Ogre Horde (© 2024)

| Carte (p.) | M/A/D/B/Mi | Texte de capacité | Chez nous | Verdict |
|---|---|---|---|---|
| Ogre Warrior (p18) | 6/5/4/5/1 | — | Ogre guerrier 6/5/4/5/1, grande taille | ✅ identique |
| Ogre Champion (p33) | 6/5/4/6/1 | — | Ogre champion 6/5/4/6/1, grande taille | ✅ identique |
| Ogre Commander (p23) | 4/6/5/6/2 | — | Ogre commandant 4/6/5/6/2, grande taille, `charge` | ✅ identique |
| Ogre Lord (p24) | 4/6/6/10/5 | — | Seigneur ogre 4/6/6/10/5, grande taille, `frappe_de_zone`+`resistance_magique` | ✅ identique |
| Orc Archer (p25) | 8/3*/2/1/2 | « *Against non-adjacent targets. The Orc Archer rolls 1 Attack die against adjacent targets. » | Orque archer (dérivé) 8/1(contact)/3(distance)/2/1/2 | ✅ identique |
| Goblin Archer (p26, dupliquée p35) | 10/2*/1/1/1 | « *Against non-adjacent targets. The Goblin Archer rolls 1 Attack die against adjacent targets. » | Gobelin archer (dérivé) 10/1(contact)/2(distance)/1/1/1 | ✅ identique |

Pas de carte scannée pour Doralf, Gruzbella Hammerhand (3 phases) ni Spawn of
the Pit (2 phases) — tous des PNJ/boss de quête nommés sourcés du texte,
même raisonnement que ci-dessus.

---

## Jungles of Delthrak (© 2024)

| Carte (p.) | M/A/D/B/Mi | Texte de capacité | Chez nous | Verdict |
|---|---|---|---|---|
| Blightcrawler (p27) | 7/4/4/3/4 | « Spawn. Agile. Venomous. » | Rampant putride 7/4/4/3/4, `agile`+`venimeux`+`spawn` | ✅ identique |
| Blightweaver (p34) | 7/2/2/1/2 | « …cast…once per quest: Channel Dread and Creeping Grasp. » | Tisseur putride 7/2/2/1/2, archétype `tisseur_fleau` | ✅ identique |
| Raptor (p36) | 8/3/2/2/3 | « Clever Tactician. » | Raptor 8/3/2/2/3, `tacticien` | ✅ identique |
| Serpent (p37) | 8/4/3/6/3 | « Spawn. Venomous. » (PAS d'Agile) | Serpent géant 8/4/3/6/3, grande taille, `venimeux`+`spawn` (PAS d'agile non plus) | ✅ identique, y compris l'absence d'Agile |
| Skullblight (p39) | 6/3/2/2/0 | « Entangling Roots. » | Crâne putride 6/3/2/2/0, `racines_entravantes` | ✅ identique |
| Skeleton Archer (p40) | 6/2*/2/1/0 | « *Against non-adjacent targets… 1 Attack die against adjacent targets. » | Archer squelette (dérivé) 6/1(contact)/2(distance)/2/1/0 | ✅ identique |
| Giant Ape (p41) | 8/4/3/7/5 | « Agile. » | Singe géant 8/4/3/7/5, grande taille, `agile` | ✅ identique |
| Spawnling (p38) | 3/0/0/1/0 | « **Venomous. Agile.** If a hero ends their turn with a Spawnling tile on their card, they take 1 Body Point of damage for each Spawnling attached to them. This damage cannot be defended against. » (icônes araignée/serpent/mille-pattes) | Rejeton putride 3/0/0/1/0, `agile`+`s_accroche` (PAS de `venimeux`) | ⚠ capacité imprimée ABSENTE chez nous : `venimeux` manque sur le Rejeton putride. Sans effet pratique observable (Attaque 0 : il ne touche jamais au corps à corps pour déclencher le poison) mais la carte le dit mot pour mot |

Pas de carte scannée pour Gretzl la Porte-Fléau (3 phases) — boss de quête
nommé, même raisonnement.

---

## Mercenaires (pas des monstres — déjà portés, vérifiés ici)

Quatre cartes de mercenaire humain, © 2022 (le doc 01 §4quater datait sa
numérisation de 2023 pour la même liste — écart d'un an entre deux scans du
même matériel, sans conséquence puisque les cinq valeurs concordent).

| Carte (p.) | M/A/D/B/Mi | Coût / capacité | Chez nous | Verdict |
|---|---|---|---|---|
| The Crossbowman (p29) | 6/3/3/2/2 | 75 po — « Wields a crossbow » | Arbalétrier 6/3(contact)/3(distance)/3/2/2, 75 po | ✅ identique (nom carte « Crossbowman », doc 01 l'avait relevé « Arbalist » — même personnage, impression différente) |
| The Halberdier (p30) | 6/3/3/2/2 | 75 po — « Can make diagonal attacks » | Fauchard 6/3/3/2/2, `attaque_diagonale`, 75 po | ✅ identique (carte « Halberdier », doc 01 « Glaive » — même personnage) |
| The Scout (p31) | 9/2/3/2/2 | 50 po — « Dwarf-like ability to remove traps » | Éclaireur 9/2/3/2/2, 50 po | ✅ identique |
| The Swordsman (p32) | 5/4/5/2/2 | 100 po — pas de capacité | Estafier 5/4/5/2/2, 100 po | ✅ identique (carte « Swordsman », doc 01 « Striker » — même personnage) |

---

## À corriger dans le jeu

Classé par impact. Chaque entrée dit la correction proposée et, si une
question reste pour René, la pose explicitement.

**STATUT (2026-10-05) : les 6 entrées ci-dessous sont FAITES.** Décision de
René : « Valeurs des cartes, partout » — les cartes font foi, y compris le
Body (2-3) des monstres de base ; le principe « 1 seul Body pour tout
monstre de base » est ABANDONNÉ (`MonstreSeeder`, `BestiaireSourceTest`,
`docs/regles/bestiaire-et-rencontres.md`). L'Abomination rejoint `base`/
`boite:'base'` (comme les 7 autres, pas `boite:null`). L'Assassin porte
`attaque_diagonale` (nouveau 3ᵉ lecteur côté monstre,
`ResolveurTour::jouerMonstre()`, test EN JEU
`AttaqueDiagonaleMonstreTest`). Le Rejeton putride porte `venimeux`. `cout`
recalculé pour chaque ligne modifiée par la méthode mesurée (attaques-à-3-dés
pour abattre = Body / (1.5 − Défense/6)) — Momie 3→5, Guerrier du Chaos 3→6,
Gargouille 4→7, Abomination posée à 5. Aucun changement de palier (`tier`
reste `base` partout).

1. **Quatre des huit monstres de base divergent de `sjeng-monsters.pdf` sur
   une vraie carte officielle — à trancher avant tout le reste.**
   `reference/16_armurerie.md` §4.4 avait dû sourcer Gobelin/Squelette/
   Zombie/Orque/Fimir/Momie/Guerrier du Chaos/Gargouille sur
   `sjeng-monsters.pdf` (Ye Olde Inn, une reproduction de FAN), faute de
   mieux, avec deux recoupements qui restent valides (l'attaque de la
   momie à 3 dés, le Mind 0 des trois morts-vivants). Les cartes
   officielles que René vient de scanner **sont** les cartes que le
   *Rulebook* dit identiques au tableau de l'écran du MJ (LR p. 7) — une
   source plus proche de la table que le PDF de fan. Elles confirment le
   paquet de fan sur Goblin/Skeleton/Orc (3/8), mais divergent sur les
   4 autres :
   - **Zombie** : carte Déplacement **5**, nous (et le paquet de fan) **4**.
   - **Mummy** : carte Body **2**, nous (et le paquet de fan) **1**.
   - **Dread Warrior** (= Guerrier du Chaos) : carte Déplacement **7** /
     Attaque **4** / Body **3**, nous (et le paquet de fan) 6/3/1.
   - **Gargoyle** : carte Défense **5** / Body **3**, nous (et le paquet
     de fan) 4/1.
   - **Correction proposée** : aligner ces quatre lignes de
     `MonstreSeeder` sur les valeurs de la carte, migration + mise à jour
     des tests `BestiaireSourceTest` (qui verrouillent aujourd'hui les
     valeurs du paquet de fan).
   - ⚠ **Mais ça rouvre la décision d'équilibrage « tout monstre de base a
     1 seul Body »** (`docs/regles/bestiaire-et-rencontres.md`, justifiée
     comme « le design du jeu — les héros encaissent, les monstres
     tombent en un coup ») : avec les cartes officielles, la répartition
     réelle du plateau est **1/1/1/1/2/2/3/3**, pas 1 partout. Si les
     cartes l'emportent, cette règle d'équilibrage — et le recalibrage de
     `cout` qui l'accompagnait — doit être revue, pas seulement les quatre
     chiffres.
   - **Question pour René** : les scans sont-ils bien les mêmes cartes
     que celles qui ont servi à constituer `sjeng-monsters.pdf`, ou une
     édition/un tirage différent ? Et si les cartes l'emportent (ce que
     la hard rule « la carte est la source » suggère), reprend-on aussi
     la règle « 1 Body partout » ?

2. **L'Abomination est désormais sourcée et peut rejoindre le
   catalogue.** Carte « Abomination » © 2021, comptée dans l'inventaire de
   la boîte de base (LR p. 4 : « 3 abominations », dans la même liste que
   les 7 autres monstres de base) : 6/3/3/2/3, pas de texte de capacité.
   `MonstreSeeder` la laissait volontairement de côté (« doc 18 note † :
   ses stats ne sont chiffrées dans aucun livret, seulement dans une table
   de tournoi d'une autre boîte »). Ce n'est plus vrai : la carte donne le
   bloc complet.
   - **Correction proposée** : ajouter une ligne `Abomination`, `tier =>
     'base'`, `boite => 'base'` (la carte et LR p. 4 la rattachent à la
     boîte de base, pas à Kellar's Keep), `cout` à fixer par la même
     méthode que les autres monstres de base, `capacites => []`.
   - **Question pour René** : le commentaire actuel associe l'Abomination
     à Kellar's Keep via une table de tournoi d'une AUTRE boîte — est-ce
     une créature générique de la boîte de base (comme la carte et LR p. 4
     le suggèrent) qui a aussi un rôle renforcé dans Kellar's Keep, ou
     bien deux choses différentes qu'on a conflées ?

3. **L'Assassin perd sa diagonale.** Carte : « Each Assassin may attack
   diagonally. » `MonstreSeeder` lui donne `capacites => []`. Le mot-clé
   `attaque_diagonale` EXISTE déjà et est lu pour les mercenaires
   (`MenuMoteur.php:2436`, `ResolveurTour.php:11005`) et pour les armes
   (`effet['attaque_diagonale']`), mais **jamais pour un monstre** — il
   n'y a pas de lecteur côté `$instance->monstre->capacites` dans
   `ResolveurTour`/`MoteurDread`.
   - **Correction proposée** : ajouter `attaque_diagonale` aux capacités
     de l'Assassin, et un troisième point de lecture (monstre) au même
     mot-clé — même patron que les deux existants, pas une nouvelle
     mécanique. Ajouter `attaque_diagonale` à la liste `$implementees` de
     `BestiaireSourceTest`.

4. **Le Rejeton putride (Spawnling) est Venomous sur sa carte, pas chez
   nous.** Texte : « Venomous. Agile. » — nous n'avons que `agile` +
   `s_accroche`. Sans effet observable en jeu puisque son Attaque est 0
   (il ne touche jamais au corps à corps pour déclencher un jet de poison)
   — mais la carte le dit mot pour mot, et le registre « rien de non lu »
   vaut aussi pour une capacité sans conséquence pratique actuelle.
   - **Correction proposée** : ajouter `venimeux` à ses capacités pour que
     le catalogue cite la carte fidèlement ; aucun nouveau lecteur requis
     (le lecteur de `venimeux` existe déjà, il ne se déclenchera
     simplement jamais pour ce monstre tant que son Attaque reste à 0).

5. **Deux cartes de monstre portent un second nom déjà connu de nous sous
   un nom différent (Arbalist/Crossbowman, Glaive/Halberdier,
   Striker/Swordsman)** — mercenaires, stats identiques des deux côtés,
   aucune action requise, juste noté pour que la prochaine lecture de
   carte ne s'étonne pas de l'écart de nom.

6. **Deux paires de cartes scannées sont des doublons exacts** (Elven
   Archer p07/p16, Goblin Archer p26/p35) — probablement deux tirages
   (deux boîtes différentes réutilisant la même carte). Aucune divergence
   entre les deux exemplaires, aucune action requise.

Pas de divergence trouvée sur les 23 autres cartes de monstre (Frozen
Horror, Mage of the Mirror, Rise of the Dread Moon sauf Assassin, Against
the Ogre Horde, Jungles of Delthrak sauf le Rejeton putride) : tout ce qui
avait déjà été porté depuis `reference/18_extensions.md` tient face à la
carte elle-même, divergence déclarée de l'Ombre du Dread comprise.
