# Plan — Définir entièrement l'extension *Kellar's Keep*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> iront dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §Kellar's Keep.
>
> **Source** : livret de quêtes officiel **F4543** (© 2021 Hasbro, 32 pages
> imprimées / 17 pages PDF), téléchargé le 2026-10-04 depuis
> `instructions.hasbro.com` (page produit
> `avalon-hill-heroquest-kellars-keep-expansion-ages-14-and-up`). Texte
> extrait page par page (`texte/F4543_en-us/pNN.txt`), et **chaque bloc de
> stats relu sur le rendu PNG** (`rendu/F4543_en-us_pNN.png`) — confirmé
> identique au texte extrait sur toutes les pages vérifiées (p. 9, 15). Les
> numéros ci-dessous sont les **pages imprimées** (le livret pagine 1-32 ; le
> PDF n'a que 17 pages, chacune un spread de deux pages imprimées — p. ex. le
> fichier `p09.txt` porte les pages imprimées 16-17).

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : les règles de la boîte (p. 4-7), dix quêtes (p. 8-27),
la référence des artefacts (p. 28-29), et la planche de symboles (p. 30-31).

**Il ne porte PAS tout le contenu physique.** La page 4-5 annonce « **14
game cards** », mais la page Artifact Reference (p. 28-29) n'en montre que
**10** : Fire Ring, Magical Throwing Dagger, et 8 Spell Scrolls. Les **4
cartes manquantes** ne sont décrites nulle part dans le texte — l'hypothèse
la plus probable, par analogie avec les autres livrets (chaque page de
quête porte un bandeau « Wandering Monster in this Quest: X »), est un jeu
de cartes de rappel pour ce tirage ; rien ne le confirme. ⚠ **Non trouvé**,
à vérifier sur les cartes physiques (§5).

**Contenu physique (p. 4-5)** : 17 figurines (8 orcs, 6 gobelins, **3
abominations**), 2 portes de donjon, une planche cartonnée de tuiles (4
escaliers courts, 2 longs, couloir-falaise, rocher, 2 trappes, 2 fosses, 12
cases bloquées, forge, nuage de Dread, carte de pierre en 4 parties,
statue), 14 cartes de jeu.

**Aucune nouvelle classe de héros** (p. 6, confirmé) : les quêtes se jouent
avec les 4 héros de base.

## 1. Déjà en place — plus que le dernier audit ne le montrait

Avant d'écrire la moindre ligne, un passage par le catalogue réel a trouvé
que **l'essentiel de la boutique et des objets de cette boîte est déjà
porté**, sous des noms français, depuis le paquet *officiel* `potions.pdf` /
`sjeng-artefacts.pdf` (doc 16 §2.1bis) plutôt que depuis ce livret — ce
livret n'est qu'une SOURCE DE CONFIRMATION parmi deux pour ces pièces-là,
jamais la source primaire du catalogue. Rien de ce tableau n'est à faire.

| Élément du livret | Chez nous |
|---|---|
| **Boutique de l'Alchimiste**, p. 2 : *Potion of Restoration* 500 po (1 Body + 1 Mind), *Venom Antidote* 300 po, *Potion of Dexterity* 100 po, *Potion of Battle* 200 po | `ObjetSeeder` : Potion de restauration (300 po — voir §2 ci-dessous), Antidote au venin (300 po), Potion de dextérité (100 po), Potion de bataille (200 po). Les quatre reprennent le texte de carte **mot pour mot** |
| *Fire Ring* (p. 28-29) | `config/cartes.php` : « Fire Ring, paquet Kellar's Keep → Anneau de Feu » — **portée** |
| *Magical Throwing Dagger* (p. 6, 28-29) | `config/cartes.php` → Dague de jet magique — **portée** |
| 8 Spell Scrolls (p. 28-29) : Heal Body, Tempest, Ball of flame, Courage, Fire of wrath, Sleep, Rock skin, Genie | Les 8 existent comme SORTS DE HÉROS au catalogue officiel (doc 16 §3bis), avec `difficulte_parchemin` posé → un parchemin est automatiquement DÉRIVÉ de chaque sort (`ObjetSeeder` §parchemins, doc 02 §6) : **Soin du Corps**, **Tourmente**→vérifier(le nom exact porté est `Tempête`, élément air), **Boule de Feu**, **Courage**, **Trait de Feu**, **Sommeil**, **Peau de Pierre**, **Génie**. Les 8 scrolls du livret sont donc déjà jouables comme parchemins trouvables, sans un octet de code |
| État de choc à 0 Mind (« dead forever unless Elixir of Life », p. 25) | **Divergence assumée, déjà tranchée** : `Personnage::estEnChoc()` (2026-10-01) fait qu'un héros à 0 Mind entre en ÉTAT DE CHOC plutôt que de mourir — arbitrage pris sur la compilation d'erratas 2021 citant *Against the Ogre Horde* p. 9 (« every creature »), qui **écrase** la règle 2021 plus ancienne de cette boîte. Voir Q1 |
| Mind 0 → sorts mentaux sans effet | `App\Engine\SortMental` |
| Abomination | **Délibérément non semée** : `MonstreSeeder` porte un commentaire explicite (« Kellar's Keep : l'Abomination n'est PAS semée. Ses stats ne sont chiffrées dans aucun livret… On ne sème pas une valeur qu'aucune source n'assume ») — voir §2 et Q5, rien à changer sans photo |

Rien de la boutique, des artefacts ou des parchemins n'est donc un LOT de
travail pour cette boîte : c'est un constat, pas un chantier.

## 2. Corrections à `reference/18_extensions.md` §Kellar's Keep

1. **Erreur de citation, à corriger** : « **Artefacts** (Kellar's Keep,
   p. 15) » et « 8 parchemins de sort (…Kellar's Keep, p. 15) » citent la
   page du FICHIER PDF (`texte/F4543_en-us/p15.txt`), pas la page
   IMPRIMÉE. Vérifié sur le rendu PNG : le bandeau de bas de page dit
   « **Page 28** » (Conclusion) puis « **Page 29** » (Artifact Reference) —
   les deux citations doivent devenir **p. 28-29**. La page imprimée 15 est
   en fait au milieu de la quête 9 (« The East Gate », Borokk).
2. **Trou** : la quête 4 (« The Dwarven Forge », note B, p. 15) fait lancer
   le sort de Dread *Rust* à une abomination (« The abomination in this room
   knows the Dread spell rust. It can cast this spell on three separate
   turns »). C'est le PREMIER monstre de tier de base de cette boîte à
   lancer un sort de Dread — à ajouter, même si l'Abomination reste non
   semée (§1).
3. **Trou (nuance, pas une erreur)** : le document ne distingue pas le
   **nuage de Dread générique** (p. 4-5, description de composant : « when
   surrounded by this mysterious purple cloud, heroes cannot see anything »)
   de son usage réel en **quête 10** (note A, p. 26) qui est une mécanique
   bien plus riche : chaque héros qui entre y lance 1 dé rouge — 1/2 le
   renvoient dans le couloir, 3/4/5 le téléportent par une porte numérotée
   correspondante (occupée → traité comme un 6), 6 déclenche une attaque (1
   dé de combat, 1 PV si crâne, touche TOUS les héros de la salle). La
   description générique ne mentionne aucun de ces effets. Si le Nuage de
   Dread est porté un jour (hors scope ici, aucune autre boîte n'en a
   besoin), c'est la version de la quête 10 qu'il faut sourcer, pas la
   phrase de la page composants.
4. **Trou, citations manquantes** : trois mécaniques du §5 n'ont pas de
   numéro de page précis et devraient les porter : rocher roulant → **p.
   12** (quête 3, note A) ; trappes appariées → **p. 22** (quête 8, note C,
   et non une quête à part — reference/18 ne dit pas où elles sont) ; « un
   seul piège trouvable à la fois » → **p. 12** (même quête, note B, PAS une
   note distincte du rocher).
5. **Trou** : deux PORTES À CONDITION distinctes existent et méritent chacune
   sa citation (le §5 actuel les fusionne en un exemple flou) : quête 6
   « The Great Citadel » note A, p. 18 (porte verrouillée, 2 dés rouges ≤
   Body de départ, échec = fin de tour) ; quête 9 « The East Gate » note D,
   p. 24 (porte du Grand Portail, 2 dés rouges ≤ Mind ACTUEL, le nain ne
   lance qu'1 dé).
6. **À ajouter** : le tableau de synthèse (fin de fichier) donne
   « Statistiques chiffrées de l'Abomination non trouvées dans Kellar's
   Keep lui-même […] chiffrées uniquement (à prendre avec prudence) par la
   table de tournoi d'Against the Ogre Horde ». C'est exact et vérifié
   (`reference/18_extensions.md` L338-348 : **6/3/3/2/3** en
   Move/Attack/Defend/Body/Mind) — rien à corriger, mais ce plan s'appuie
   dessus pour Q5 et ne doit pas être lu comme une source différente.

## 3. Les règles à porter, sourcées — par lots

### Lot A — Corrections de §2 ci-dessus

Aucun code. `reference/18_extensions.md`.

### Lot B — Attaque mentale directe de monstre (Borokk)

| Élément | Règle citée | Chez nous |
|---|---|---|
| **Borokk**, sorcier Dread nommé (quête 9, p. 24) | « Borokk has the same stats as a Dread warrior […]. On each of his turns, he attacks the mind of any hero in the same room or corridor and in his line of sight. […] Borokk rolls **2 combat dice**. For each skull he rolls, the target […] loses 1 Mind Point. » | Même stats que **Guerrier du Chaos** (M6 A3 D4 B1 Mi3, `BestiaireSourceTest`). C'est une attaque PHYSIQUE qui inflige du Mind, **hors du système de sorts** (pas de charge, « on each of his turns ») — différent de *Mind Burst* (Against the Ogre Horde, lot D de son plan), qui EST un sort avec réserve de cartes. `MoteurDegats::infligerMindAHeros()` (2026-09-06) est le producteur, déjà là ; **aucun appelant monstre** aujourd'hui (seul `MoteurDread` l'appelle, pour les sorts). Nouvelle capacité `attaque_mind` (nombre de dés en paramètre, ici 2), lue au **même point de passage** que les autres attaques de monstre, `ResolveurTour::jouerMonstre()` / `resoudreAttaqueMonstre()`, en BRANCHE PARALLÈLE à l'attaque de Body plutôt qu'en remplacement — Borokk n'a pas d'autre mode d'attaque listé. Nouvelle constante `MoteurDegats::SOURCE_ATTAQUE_MIND_MONSTRE`, sœur de `SOURCE_ATTAQUE_MONSTRE` |
| 0 Mind | « they are dead forever unless they have an Elixir of Life » | **Écarté par notre règle courante** (état de choc, §1) — Q1 à confirmer formellement pour cette boîte précise, mais aucune raison technique de diverger de l'arbitrage du 2026-10-01 |

Nouveau monstre : **Borokk**, tier `sous_boss`, boîte `kellars_keep`, stats du
Guerrier du Chaos, capacité `attaque_mind` (2 dés). Zéro sort de Dread — ce
n'est pas un lanceur.

### Lot C — Monstres passifs jusqu'à déclencheur (mécanique partagée, 3 usages)

Trois créatures de cette boîte (et au moins une de *Return of the Witch
Lord*, voir ce plan) partagent la MÊME forme : ne bouge pas, n'attaque pas,
tant qu'un déclencheur précis ne s'est pas produit. C'est une mécanique à
écrire UNE fois.

| Élément | Règle citée | Chez nous |
|---|---|---|
| **Squelettes des rois nains** (quête 5, p. 17) | « the skeletons do not move or attack until one of the skeletons has been attacked. Then on Zargon's next turn, they all attack. […] MOVEMENT 6 ATTACK 3 DEFEND 4 BODY 2 MIND 0 » (relu sur rendu PNG, identique au texte) | Nouveau monstre **Squelette-roi nain** (tier `sous_boss`, boîte `kellars_keep`, stats ci-dessus — **divergent** de notre Squelette de base [6,2,2,1,0] : c'est voulu, le livret les dit « plus puissants »). Capacité `passif_jusqua_attaque` (le groupe entier partage l'état : UN squelette touché active TOUS les squelettes-rois de l'instance de rencontre, dès le tour suivant du MJ) |
| **Gargouille-statue** (quêtes 1, 7, 10 ; générique p. 4-5, 20, 26-27) | « The gargoyle cannot be harmed until it has either moved or attacked a hero. » (quête 1, note C, p. 9) ; « The gargoyle in this room is a stone statue that cannot harm anyone and cannot be harmed » (quête 7, note D, p. 21 — un leurre PUR, jamais activé) ; quête 10 : « immune to all spells and has 4 Body Points » (p. 27) | La Gargouille de base (M6 A4 D4 B1 Mi4) existe déjà. Capacité `passif_jusqua_attaque` réutilisée : invulnérable tant qu'elle n'a ni bougé ni attaqué — PAS une nouvelle capacité, le MÊME mot-clé que les squelettes-rois, lu une fois. Nouveau monstre **Gargouille gardienne** (tier `sous_boss`, boîte `kellars_keep`, pv_body 4 au lieu de 1, capacité `immunite_sorts` — voir ci-dessous) pour la quête 10 uniquement ; la variante « leurre pur » (quête 7) n'a besoin d'aucune stat divergente, juste de l'habillage IA sur une Gargouille normale placée immobile |
| **Immunité totale aux sorts** (Gargouille gardienne, p. 27) | « it is also immune to all spells » | Mot-clé NOUVEAU, `immunite_sorts` (booléen), distinct de `resistance_magique` (qui donne seulement +2 dés de défense contre un sort — `MoteurDread::BONUS_RESISTANCE_MAGIQUE`, lu ligne 599). Lu au point où un sort de héros choisit ses cibles légales, `MoteurSorts::ciblesLegales()` — « le menu n'offre pas ce que le résolveur refuse » : un sort ciblant cette créature ne doit même pas apparaître comme option valide |

### Lot D — Monstre métamorphe

| Élément | Règle citée | Chez nous |
|---|---|---|
| **Monstre métamorphe** (quête 9, note A, p. 24) | « This monster is a shapechanger and is currently in the form of an abomination. Every time it is killed, shuffle all the monster cards and take the top card. This is the new form the monster takes. […] The monster can be permanently killed only if the new card drawn matches the monster's most recent form. » | ⚠ **À co-concevoir avec le lot C (« phases ») du plan Against the Ogre Horde** (`docs/plan-ogre-horde.md`), PAS indépendamment : les deux mécaniques partagent le même principe — **0 Body ne retire pas l'instance**, elle change d'identité — et ce dernier n'est pas encore construit (vérifié : aucune des deux mécaniques n'a de lecteur aujourd'hui). La différence est le TIRAGE : phases = séquence fixe connue d'avance ; métamorphe = **tirage pondéré aléatoire sur tout le bestiaire disponible**, avec condition d'arrêt « deux tirages consécutifs identiques » plutôt qu'« épuisement d'une liste ». Même point de passage proposé : le SEUL endroit où une instance de monstre passe à 0 Body. Si les phases sont construites d'abord, le métamorphe en réutilise directement le branchement « ne pas retirer, remplacer l'identité », en changeant seulement la fonction qui choisit la forme suivante |

Voir Q2.

### Lot E — Portes et dangers de carte

| Élément | Règle citée | Chez nous |
|---|---|---|
| **Portes secrètes contrôlées par le MJ** (quête 1, note B, p. 9) | « These three secret doors are magically controlled and cannot be found by normal searching. Zargon may place one or more of these door(s) on the gameboard at the beginning of any of your turns. » | Rompt un invariant bien établi : chez nous, une porte secrète se trouve **uniquement** par fouille, détection de classe (nain/explorateur) ou Potion de Vision (`docs/regles/carte-donjon.md`). Une porte que le MJ ouvre à SON initiative, indépendamment de toute action du joueur, est un type de déclencheur absent du moteur (le plus proche : les pièges/mobiliers conditionnels d'`AssembleurCarte`, mais toujours déclenchés par une action du HÉROS). Recommandation : limiter la portée à une EMBUSCADE scriptée sur rencontre nommée (ambush liée à un sous-boss/boss, comme la porte de pierre d'Ogre Horde est limitée aux arêtes de boucle) plutôt qu'un nouvel état générique de porte. Voir Q6 |
| **Rocher qui roule** (quête 3, note A, p. 12) | « roll 2 red dice to see how far down the corridor the boulder rolls […]. The boulder eventually crashes into the wall […]. The passage is then blocked for the rest of the quest. Tell each hero hit by the boulder to roll 5 combat dice […] (No Defend dice are rolled.) » | ⚠ **Entité de terrain mobile autonome sur plusieurs tours du MJ** — famille identique au **Death Mist** de *Return of the Witch Lord* (`docs/plan-witch-lord.md` lot D) : aucune des deux n'existe aujourd'hui (confirmé : rien dans `app/Engine/MotsClesTerrain.php` ni `AssembleurCarte` ne modélise une case qui SE DÉPLACE d'elle-même au tour du MJ). Recommandation : concevoir **une seule** mécanique « danger mobile autonome » (position + vitesse + dégâts + condition d'arrêt, toutes deux paramétrables), jamais deux implémentations séparées — c'est la règle « un point de passage ». Voir Q3 |
| **Trappes appariées (téléportation)** (quête 8, note C, p. 22) | « Any hero or monster landing on one of these squares immediately moves to the other trap door square. […] any hero moving through it must roll 1 combat die. If they roll a skull, they lose 1 Body Point. […] the hero's or monster's turn is over. » | Plus simple que le rocher : une PAIRE de cases spéciales, sans mouvement autonome. Nouveau mot-clé terrain `teleporte_vers` (coordonnée de la case jumelle) dans `App\Engine\MotsClesTerrain`, lu par `FabriqueGrille::pour()` (le seul point de passage déjà nommé par les règles dures). Dégât et fin de tour immédiate suivent le même schéma que la Fosse |
| **Fouille limitée « un piège à la fois »** (quête 3, note B, p. 12) | « any hero who searches for traps finds only one trap—the one closest to them. After a discovered trap has been sprung or disarmed, the next trap can be found if searched for. » | Variante mineure de `MoteurPieges` : limiter le nombre de pièges révélés par une fouille de couloir à 1 (le plus proche), au lieu de tous les révéler. ⚠ Tension avec la règle déjà en place « couloirs d'une case, et un piège connu ne se foule plus gratuitement » (`docs/regles/carte-donjon.md`, 2026-09-27) qui a précisément ouvert la fouille à TOUS les pièges d'un coup pour éviter le contournement gratuit — à confirmer que cette boîte ne vient pas défaire un correctif tout juste posé |
| **Portes à condition** (quête 6 p. 18, quête 9 p. 24) | Body/Mind ≤ jet de 2 dés rouges, nain avantagé | Généraliser le modèle de porte binaire (`fermee`/`ouverte`/`secrete`/`verrou_pierre`, ce dernier posé par Ogre Horde) à un **jet paramétrable** : `verrou => ['attribut' => 'body'|'mind', 'des' => 2]`, comparé à l'attribut COURANT du héros qui tente — même point de passage, `MoteurPortes` |
| **Forge des nains** (quête 4, note D, p. 15) | « Any hero (except the dwarf) who ends their move in his room must immediately roll 1 combat die. If a skull is rolled, the hero loses 1 Body Point. » | Nouveau mobilier `Forge des nains` (boîte `kellars_keep`) avec un effet « dégât de fin de tour dans la salle, classe exemptée » — à vérifier si un seam existe déjà pour un effet de MOBILIER (pas de piège) qui frappe en fin de tour plutôt qu'au déclenchement d'une fouille ; sinon, nouveau mot-clé mobilier |

### Lot F — Habillage seul, sans nouvelle donnée

| Élément | Règle | Chez nous |
|---|---|---|
| **Gragor** (quête 6, p. 18-19) | Mêmes stats que Guerrier du Chaos + sorts *summon orcs, fear, rust, ball of flame, lightning bolt* | Guerrier du Chaos existant + `sorts_dread => ['Invocation d\'orques', 'Frayeur', 'Rouille', 'Boule de Flammes', 'Éclair de Chaos']` — **les cinq sorts existent déjà** au catalogue (`SortDreadSeeder`), AUCUN sort manquant. Nouvelle ligne `MonstreSeeder` ou simple habillage IA d'un Guerrier du Chaos existant selon qu'on veut le nommer de façon stable (recommandé : nouvelle ligne, cohérent avec le patron Nexrael/Xenloth d'Ogre Horde) |
| **Ograk** (quête 7, p. 20-21) | Mêmes stats que Guerrier du Chaos, sans sort — apparaît par une porte secrète placée par le MJ | **Habillage IA pur** d'un Guerrier du Chaos existant (comme Ekur/Tograk dans le plan Ogre Horde) ; son trait propre (porte secrète du MJ) est couvert par le lot E, pas par le monstre lui-même |

### Lot G — Mobilier et objectif de campagne

| Élément | Règle | Chez nous |
|---|---|---|
| **Carte de pierre en 4 parties** (objectif de boîte, p. 4-5, trouvée aux quêtes 4/5/6/7) | Un fragment par quête, collecté sur toute une CAMPAGNE de 10 quêtes fixes, condition de fin de boîte | ⚠ Ne correspond à AUCUN gabarit actuel (`atteindre_et_recuperer`, `vaincre_sous_boss`, `vaincre_boss_final` — tous des objectifs À L'INTÉRIEUR d'une seule quête). Collecter un fragment à CHAQUE quête d'un arc est un objectif de CAMPAGNE, pas de quête : hors scope du modèle actuel, qui construit des donjons procéduraux quête par quête sans fil conducteur d'objet. Pas un lot, un gabarit-objectif local « trouver et ramener un fragment » peut en revanche réutiliser `atteindre_et_recuperer` tel quel pour une quête isolée, sans la dimension campagne |
| **Caisse de ravitaillement**-like : rien ici, confusion à éviter | — | Pas d'élément propre à Kellar's Keep ; la Caisse de ravitaillement est une pièce d'*Against the Ogre Horde*, déjà portée — ne pas la reconfondre |
| **Statue** (décor, p. 4-5, 21) | Pur décor, sert de camouflage à une gargouille | Couvert par le mot-clé `passif_jusqua_attaque` du lot C ; pas de mobilier séparé nécessaire — la gargouille EST la statue tant qu'elle n'a pas agi |
| **Escaliers courts/longs, couloir-falaise** (p. 4-5) | Cosmétique : « each square counts as 1 space » | Rien à faire : tout couloir généré compte déjà ses cases normalement, ce n'est qu'un habillage visuel d'escalier |
| **Porte d'entrée en fer / porte de sortie en bois** (p. 4-5, 6-7) | Entrée/sortie par les bords du plateau plutôt que l'escalier en colimaçon | Nos donjons procéduraux n'ont pas d'objet « porte d'entrée » : les héros apparaissent directement en salle 0 (`AssembleurCarte`), sans porte franchie. C'est purement NARRATIF (l'IA peut dire « la porte de fer se referme derrière vous ») — **aucune mécanique à construire**, et la ligne « Entrée et sortie par une porte fléchée | First Light » de `docs/plan-ogre-horde.md` §1 est **optimiste** : vérifié, aucun code ne modélise de porte de bord de plateau nulle part dans le projet. À ne pas recopier comme un acquis |

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | Mind 0 chez Borokk : le livret 2021 dit « mort définitive sauf Élixir de Vie », notre règle actuelle (2026-10-01, Against the Ogre Horde p. 9, « every creature ») dit choc. Confirmer que la règle PLUS RÉCENTE l'emporte explicitement, y compris pour cette boîte plus ancienne ? | Oui — c'est déjà la logique de l'arbitrage du 2026-10-01 (compilation d'erratas, applicable à toute créature) ; rien à faire sauf le documenter ici |
| **Q2** | Monstre métamorphe : construire maintenant (tirage aléatoire jusqu'à répétition), ou attendre que le lot « phases » d'Against the Ogre Horde pose le branchement « 0 Body ne tue pas » qu'il réutiliserait entièrement ? | Attendre — éviter DEUX implémentations de « ne pas retirer à 0 Body » |
| **Q3** | Rocher roulant (Kellar's Keep) et Death Mist (Witch Lord) : une seule mécanique « danger mobile autonome » partagée, ou deux pièces séparées ? | Une seule — même famille, même coût d'implémentation, deux habillages |
| **Q4** | Portes secrètes contrôlées par le MJ : limiter à une embuscade scriptée sur rencontre nommée (petit scope), ou un vrai troisième état de porte générique (gros scope, tension avec le modèle « porte secrète = trouvée par fouille ») ? | Scope réduit — l'embuscade scriptée couvre l'usage réel du livret (3 portes, 1 quête) sans toucher au modèle général |
| **Q5** | Abomination : toujours aucune source primaire. Le SEUL chiffre existant (6/3/3/2/3) vient d'une table de tournoi d'une AUTRE boîte, explicitly « à prendre avec prudence » par sa propre source. Semer avec ce chiffre et une mention de provenance, ou continuer d'attendre une photo de carte ? | Continuer d'attendre — c'est déjà la décision actuelle du code, et la règle « non trouvé bat une estimation » s'applique particulièrement bien ici vu la mise en garde de la source elle-même |
| **Q6** | Les 4 cartes manquantes du compte « 14 game cards » (10 trouvées) : probablement des cartes de rappel des monstres rencontrés. Prioritaire dans la demande de photos ? | Oui, en même temps que les cartes de monstre (aucune des 3 figurines, gobelin/orc/abomination, n'a de carte stats dans CE livret) |

## 5. Sources à demander (photos des cartes)

Les **14 cartes** de la boîte, en priorité :
- les cartes de monstre (Abomination en premier — seule créature neuve de
  la boîte sans le moindre chiffre primaire) ;
- les 4 cartes non comptées dans les 10 de la page Artifact Reference —
  probablement des cartes de rappel de monstre, à confirmer ;
- toute carte de monstre nommé (Gragor, Ograk, Borokk) si elle diffère du
  Guerrier du Chaos de base, pour confirmer qu'« mêmes stats qu'un Dread
  warrior » est bien la totalité de l'information.

En attendant, le livret suffit aux lots B, C, D (partiellement), E, F : il
porte toutes leurs règles et tous leurs chiffres sauf l'Abomination.

## 6. Ordre proposé

1. **Lot A** : corriger `reference/18` (citation p. 15 → p. 28-29, ajout
   Abomination/Rust, nuance Nuage de Dread, citations manquantes). Aucun
   code.
2. **Lot C** (mécanique `passif_jusqua_attaque`) avant le lot B : c'est la
   plus petite pièce indépendante, et elle sert aussi la Gargouille
   gardienne et (potentiellement) Bellthor côté *Witch Lord*.
3. **Lot B** (Borokk, `attaque_mind`) : petit, autonome, premier producteur
   de Mind côté monstre HORS sorts de Dread.
4. **Lot E** : les quatre pièces de carte/porte, chacune autonome — sauf le
   rocher roulant, à séquencer AVEC `docs/plan-witch-lord.md` (Death Mist)
   selon Q3.
5. **Lot F** (habillage Gragor/Ograk) : trivial une fois le lot B posé.
6. **Lot D** (métamorphe) : après le lot « phases » d'Against the Ogre
   Horde, selon Q2.
7. **Lot G** : pas de code, juste la clarification de scope (objectif de
   campagne hors périmètre, entrée/sortie par porte = narratif seul).

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN JEU
→ données**, registres testés dans les deux sens, contrat d'API d'abord,
effets automatiques annoncés. Pest sur une **copie sqlite jetable** ;
`sauvegarder.sh` avant toute migration ; aucune purge de
`groupes`/`personnages`/`joueurs` ; redémarrer `queue` et `queue-jeu` après
le PHP.

## 7. Cette boîte peut-elle devenir un THÈME de bestiaire ?

Moins clairement que *Return of the Witch Lord* (`docs/plan-witch-lord.md`
§7). Rappel des règles arrêtées (`docs/plan-themes-bestiaire.md`) : un
thème ajoute des créatures SIGNATURE sur la rencontre finale et les
« forts », sans filtrer la masse commune ; `boite = null` reste toujours
éligible dans tous les thèmes.

**Ce qui pèse en sa faveur** : contrairement à Witch Lord, cette boîte a un
vrai créneau `tier = base` disponible — le Gobelin et l'Orque sont déjà
communs à tous les thèmes, et rien n'empêche l'Abomination de rejoindre ce
tier le jour où elle est sourcée.

**Ce qui lui manque, dans l'ordre d'impact** :
1. **Sa créature signature (l'Abomination) n'a aucune stat sourcée** (§1,
   Q5) — c'est la créature la plus citée de la boîte (3 figurines, monstre
   errant par défaut sur 6 des 10 quêtes) et elle est aujourd'hui
   INVISIBLE au bestiaire. Un thème `kellars_keep` sans Abomination serait
   un thème nain-contre-orques-et-gobelins ordinaire, sans rien qui le
   distingue visuellement des autres.
2. **Aucun boss clair.** Cette boîte n'a ni affrontement final nommé (la
   quête 10 se termine sur une gargouille gardienne, pas un boss narratif)
   ni lanceur de haut rang : Gragor (sous-boss probable, lot F) est le
   lanceur le plus fort de la boîte, et ce n'est qu'un Guerrier du Chaos
   habillé. Il faudrait soit promouvoir Gragor en boss de thème malgré son
   faible `cout`, soit emprunter un boss d'ailleurs — aucune des deux
   options n'est neutre.
3. **Le monstre métamorphe (lot D) dépend d'une mécanique non construite**
   (les « phases » d'Against the Ogre Horde) — tant qu'il n'existe pas, la
   boîte perd sa seule mécanique vraiment originale et se réduit à « des
   orques et des gobelins avec deux sorciers nommés ».

Conclusion : **pas un candidat solide à court terme**, contrairement à
Witch Lord. Le préalable n'est pas un simple lot technique mais une
**photo de carte** (l'Abomination) hors du contrôle du projet — à
documenter comme une attente, pas un refus.
