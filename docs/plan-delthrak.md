# Plan — Définir entièrement l'extension *Jungles of Delthrak*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> iront dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §Jungles of Delthrak (2024) — **ce plan ne modifie PAS cette section**
> (consigne explicite) : ses corrections vivent ici, au §2.
>
> **Source** : livret de quêtes officiel **F9907** (© 2024 Hasbro, 52 pages
> imprimées / 27 pages PDF), déjà téléchargé, extrait par ce travail (`pNN.txt`
> n'existait pas encore dans `texte/F9907_en-us/`). Les blocs de stats
> critiques (tableau des monstres p. 47, mots-clés p. 48-49) ont été **relus
> sur le rendu PNG** : tous confirment exactement le texte déjà extrait et
> déjà porté — **aucune erreur de colonne trouvée** sur ces deux pages. Les
> numéros ci-dessous sont les **pages imprimées**.

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : les règles de la boîte, 16 quêtes en **structure
ramifiée** (3 chemins A/B/C, jusqu'à 3 conclusions), le tableau des 9
monstres (p. 47), les 5 mots-clés de capacité (p. 48-49), et une planche de
symboles. C'est la suite directe de Kellar's Keep (mêmes nains réfugiés).

**Il ne porte PAS les cartes de jeu** : les fiches chiffrées de l'Explorateur
et du Berserker (⚠ non trouvées DANS CE LIVRET — mais voir §1, elles sont
déjà chez nous par une AUTRE source), les 6 artefacts nommés, les cartes de
potion de l'Alchimiste. Même limite que partout ailleurs dans doc 18.

## 1. Déjà en place — c'est l'essentiel du travail de ce plan

Le portage précédent (2026-08 à 2026-09) est **beaucoup plus avancé** que la
question du brief ne le laissait supposer. Vérifié par grep direct dans le
code, pas par le dire de `reference/18` :

### 1.1 Les deux classes de héros — ENTIÈREMENT jouables

⚠ **Correction du constat de départ** : `reference/18_extensions.md`
§Jungles of Delthrak dit (exact, pour CE livret) qu'aucune fiche chiffrée n'y
est trouvée. C'est vrai du livret de quêtes, mais **une autre source les a
données** : la numérisation de René du 2026-08-11 (dossier Drive
« Heroquest2021 », cartes de héros), documentée en
`reference/01_personnages.md` §4bis-4quater et dans le tableau de synthèse de
`reference/18_extensions.md` lui-même (ligne « Explorateur… ✅ A2 D2 B5 M5 »,
« Berserker… ✅ A3 D2 B7 M2 »). **Les deux classes sont en jeu** :

| Classe | Stats | Race/équipement | Capacités | Implémentation |
|---|---|---|---|---|
| Explorateur | A2 D2 B5 M5 (le seul 5/5 du jeu) | Nain, Hachette, `tags_equipement`: distance+bouclier | *Sens du piège*, *Crochetage*, *Sixième sens*, *Chasseur de trésor*, *Œil du prix* | `ClasseHerosSeeder:201`, `CompetenceSeeder:491`, `MoteurPieges::SANS_OUTILS`, `ResolveurTour` (10+ points de lecture) |
| Berserker | A3 D2 B7 M2 | Humain, Épée large, `talisman_berserker` | *Furie* (perd jusqu'à 2 PV Body pour une attaque bonus), *Frénésie sanguinaire* (balayage, 1×/quête), *Représailles* (riposte hors tour) | `ClasseHerosSeeder:194`, `CompetenceSeeder:452`, `MenuMoteur:1465/1493`, `ReactionEffet:45`, `MoteurReactions:192` |

Les deux ont leur race/déplacement (`database/migrations/2026_08_13_000001_race_des_classes.php`),
leur palier de montée (`MonteeNiveau.php:116-119`), leur équipement de départ
(`EquipementDepart.php:61-62`), leur style élémentaire (`StylesElementaires.php:140`).
**Rien à porter ici.**

### 1.2 Les 9 monstres du tableau (p. 47) — stats ET les 5 mots-clés, TOUS lus

Vérifié ligne à ligne contre le PNG (§ci-dessus) :

| Monstre (VF) | M/A/D/B/Mi relu sur PNG | Capacités (VF) | Correspond au livret |
|---|---|---|---|
| Rejeton putride (Spawnling) | 3/0/0/1/0 | `agile`, `s_accroche` | Agile ✓ ; **Venomous → réinterprété en `s_accroche`** (voir §2.3) |
| Tisseur putride (Blightweaver) | 7/2/2/1/2 | archétype `tisseur_fleau` (Canaliser l'Effroi + Étreinte des Ronces) | Dread Spells: Channel Dread, Creeping Grasp ✓ |
| Crâne putride (Skullblight) | 6/3/2/2/0 | `racines_entravantes` | Entangling Roots ✓ |
| Raptor | 8/3/2/2/3 | `tacticien` | Clever Tactician ✓ |
| Rampant putride (Blightcrawler) | 7/4/4/3/4 | `agile`, `venimeux`, `spawn` | Spawn, Agile, Venomous ✓ — **les trois mots-clés présents** |
| Serpent géant (Serpent) | 8/4/3/6/3 | `venimeux`, `spawn` | Spawn, Venomous ✓ |
| Singe géant (Giant Ape) | 8/4/3/7/5 | `agile` | Agile ✓ |
| Gobelin archer, Archer squelette | (génériques, `boite: null`) | variante à distance | Ranged ✓ — portés par le lot Archers d'Against the Ogre Horde |

Les 5 mots-clés ont chacun un LECTEUR dans `MoteurDread.php` (`appliquerVenin()`
l. 3200, `aCapacite($instance, 'agile')` l. 3299 et `ResolveurTour.php` l.
2810/8967, `tacticien` avec flanquement l. 87/665, `racines_entravantes` l.
2874, `spawn` avec paramètre `creature` l. 2815-2866). **Rien à porter sur
les 9 entrées de catalogue ni sur les 5 mots-clés** — c'est fait, testé, en
base.

### 1.3 Le thème `jungles_delthrak` — actif, mais SANS boss

`jungles_delthrak` figure dans `DemarreurQuete::BOITES_THEMATIQUES` (ligne
527) : la campagne peut déjà tourner sur ce thème. Son palier `sous_boss`
(Rampant putride, Serpent géant, Singe géant) alimente déjà le jalon
« Sous-boss » de `GabaritQueteSeeder` (`rencontre_finale.creatures`, aux
côtés du Troll et de l'Ogre). **Mais `rencontre_finale.archetypes` du jalon
« Confrontation finale » ne contient AUCUNE entrée Delthrak**
(`['seigneur_du_chaos', 'necromancien', 'maitre_tempetes', 'spectre_effroi',
'archimage_elfe', 'horreur_glacee']`) et **aucun monstre `jungles_delthrak`
n'a le tier `boss`** dans `MonstreSeeder`. C'est le trou central de ce plan
(lot A).

### 1.4 Venimeux (Venomous) — implémentation vérifiée correcte

« If a hero takes damage from a Venomous creature, they become paralyzed.
While paralyzed, they are unable to take their movement but may still
perform actions. […] roll one red die. On 5 or 6, they resist […] Otherwise,
place a venom token […] effect ends at the end of their next turn » (p. 48).
`MoteurDread::appliquerVenin()` n'applique le jet QUE si `$subis > 0`
(`ResolveurTour.php:9359`) — le dégât normal de combat est bien résolu EN
PLUS de la paralysie, pas remplacé par elle — et la condition `Envenimé`
(`ConditionSeeder`) ne bloque que le déplacement (`deplacement_interdit`),
jamais l'action, exactement la lettre du livret. **Correct, rien à changer.**
⚠ Précision à ajouter dans ce plan plutôt que dans `reference/18` : la phrase
« dégât = paralysie » de la section source est condensée au point de prêter à
confusion (on pourrait lire « pas de perte de PV, seulement la paralysie ») —
à reformuler si quelqu'un retouche cette section un jour.

## 2. Corrections à `reference/18_extensions.md` §Jungles of Delthrak

(Ce plan ne les applique PAS lui-même — consigne du brief. À appliquer par un
futur agent de doc, ou par René.)

1. **§1 Nouveaux héros jouables est obsolète.** Le texte dit « ⚠ Aucune fiche
   chiffrée pour ces deux classes… dans le livret de quêtes » — vrai du
   SEUL livret F9907, mais la phrase ne dit pas que la fiche existe ailleurs
   et qu'elle est DÉJÀ semée (§1.1 ci-dessus). Un lecteur pressé croit la
   classe non jouable. À corriger par un renvoi vers
   `reference/01_personnages.md` §4bis-4quater et le tableau de synthèse du
   même fichier (ligne « Héros jouables — recensement corrigé »).
2. **Le statut du Berserker** (« figure dans la liste des figurines […] pas
   confirmé aussi explicitement […] inféré par analogie ») est lui aussi
   dépassé par la même numérisation : sa carte existe, ce n'est plus une
   inférence. Même renvoi.
3. Aucune erreur trouvée sur le tableau des 9 monstres ni sur les 5
   mots-clés (§1.2) — la section est fiable sur ce point, contrairement à ce
   que le brief anticipait par précaution.
4. La section ne mentionne PAS que `jungles_delthrak` est déjà un thème actif
   ni qu'il n'a pas de boss (§1.3) — ce n'est pas une erreur (ce n'est pas le
   rôle de doc 18 de documenter l'état du code), mais un lecteur qui
   enchaînerait doc 18 → implémentation sans lire ce plan répéterait le
   travail de découverte.

## 3. Les règles à porter, sourcées — par LOT

### Lot A — Un boss pour le thème (le plus structurant)

Deux candidats nommés, tous deux avec un répertoire de sorts **déjà
entièrement sourcé dans notre catalogue** :

| Candidat | Quête | Stats | Sorts (VO → notre carte VF, TOUS déjà semés) | Spécificité |
|---|---|---|---|---|
| **Gretzl la Porte-Fléau** | 12A, boss final | Phase 1 : M6 A4\* D3 B5 Mi6 ; Phase 2 « Demonspider » : M8 A5\* D4 B4 Mi3 (Agile, Venomous) ; Phase 3 « Demonape » : M8 A6\* D2 B6 Mi1 (Agile) | *Creeping Grasp* → Étreinte des Ronces ; *Channel Dread* → Canaliser l'Effroi ; *Fear* → Frayeur | **3 phases** (mécanique manquante, voir ci-dessous) + 2 capacités réactives à usage unique (*Demon Wings* = ignore tous les dégâts d'une attaque ; *Dispel* = annule un sort la ciblant) |
| **Gruulob, Sorcier Gobelin Corrompu** | 8 | M6 A3 D4 B4 Mi5 → (0 PV) Forme Démoniaque M6 A4 D5 B3 Mi4 | *Creeping Grasp* → Étreinte des Ronces ; *Channel Dread* → Canaliser l'Effroi ; *Summon Orcs* → **Invocation d'orques** | **2 phases seulement** (plus simple), mais moins résistant (sous-boss plausible, pas boss) |

Les DEUX archétypes de lanceur sont du pur CÂBLAGE DE DONNÉES — les trois
sorts de chacun existent déjà (`SortDreadSeeder` : Étreinte des Ronces,
Canaliser l'Effroi, Frayeur, Invocation d'orques, tous semés). Ce qui manque
réellement :

- **Le mot-clé `phases` sur un monstre** — AUCUN lecteur trouvé dans tout le
  code (`grep` à vide). C'est exactement le chantier laissé ouvert par
  `docs/plan-ogre-horde.md` lot C (Gruzbella, Spawn of the Pit) : **les deux
  boîtes attendent le MÊME mécanisme**, non construit. Un lecteur qui le
  construit pour l'une sert l'autre sans travail supplémentaire — la raison
  de porter ce lot en coordination avec le plan Ogre Horde plutôt qu'en
  silo.
- **Les capacités réactives à usage unique « ignore les dégâts d'une
  attaque » / « annule un sort ciblant »** — même famille que *Resilience*/
  *Break* de Gruzbella (Ogre Horde) et *Demon Wings*/*Dispel* de Gretzl :
  DEUX boîtes, les mêmes deux formes de capacité. Un seul vocabulaire
  (`capacite_reactive_defense`: `ignore_degats_attaque` | `annule_sort`,
  usage `1x_quete`) servirait les deux bosses au lieu d'en inventer quatre
  variantes.
- Mesure de `cout`, selon la méthode déjà en vigueur (`docs/regles/bestiaire-et-rencontres.md`,
  « Body / (1.5 − Défense/6) », attaques d'un héros à 3 dés pour abattre) —
  à faire au moment du portage, pas ici (ne pas inventer un chiffre non
  mesuré).

**Bakabri le Cruel** (q. 15C, M8 A3\* D4 B4 Mi5, sorts *Creeping Grasp*,
*Channel Dread*, *Lightning Bolt*) a lui aussi 2 sorts déjà sourcés ; le
troisième (*Lightning Bolt*) ressemble à notre « Éclair de Chaos » sans
confirmation du texte de carte — ⚠ à vérifier avant de le câbler,
**troisième** candidat de lanceur si le thème a besoin de diversité au-delà
de Gretzl/Gruulob.

**Deathspinner la Reine du Fléau** (figurine Blightcrawler, q. 3, M6 A5 D4
B4 Mi4, *Channel Dread* à volonté) : le cast « à volonté » a déjà un
précédent (le Spectre d'effroi lance Channel Dread à volonté — archétype
existant) — portage immédiat si un sous-boss nommé manque de diversité, sans
attendre le mot-clé `phases`.

### Lot B — Terrain gênant de la jungle (petit, vocabulaire déjà là)

Trois types de terrain (sable/toile/jungle, 2 cases de coût de déplacement
chacun, p. 3-5) : le vocabulaire fermé `App\Engine\MotsClesTerrain`
(`bloque_mouvement` / `bloque_vue` / `cout_deplacement`) et la boucle unique
`FabriqueGrille::pour()` existent déjà et couvrent exactement ce besoin — **ce
lot n'attend aucun lecteur nouveau**, seulement 3 lignes de `TerrainSeeder`
avec `boite: 'jungles_delthrak'`, `cout_deplacement: 2`. Le plus petit lot du
rapport.

### Lot C — Terrain destructible (Crystal Cluster)

« Crystal Cluster […] can be destroyed as a monster without a defense, with 6
Body Points » (p. 3-5), bloque la ligne de vue tant qu'il existe, amplifie
*Channel Dread*. **Même brique que `docs/plan-wizards-of-morcar.md` lot A**
(Murs magiques, Haut Autel, Coffres du Dread) et que l'*Ice Wall* resté
inachevé de The Frozen Horror — un meuble avec PV + défense, lu au seul point
où `ResolveurTour` résout une attaque contre du mobilier. **Ne pas reporter
ce mécanisme deux fois** : construit une fois pour les deux boîtes de ce
rapport (voir le plan Morcar, Q4). L'amplification de *Channel Dread* reste
une règle propre à Delthrak, posée APRÈS le lecteur générique.

### Lot D — Mobilier « un socle, trois effets » (Basin)

*Pool of Water* (soigne 1 PV en fouille alternative) / *Crystal Cluster*
(ci-dessus) / *Bonfire* (1 PV sur un crâne au passage) partagent une seule
figurine de décor (« Basin », p. 3-5) réutilisée pour trois effets distincts
selon le contexte de pose. Aucun précédent de ce patron dans `MobilierSeeder`
aujourd'hui (chaque entrée a UN effet). À trancher : trois entrées de
catalogue distinctes avec le même visuel (le plus simple, cohérent avec
« une règle, un point de passage ») plutôt qu'un sélecteur d'effet sur une
entrée unique.

### Lot E — Cocoon et Grasping Vine Trap

- **Cocoon** : détruit par une action d'un héros adjacent, contient un butin
  PROGRESSIF (plus on attend, plus il grossit — à confirmer sur le texte
  exact, actuellement résumé dans `reference/18`). Variante de mobilier
  fouillable/destructible, dépend du lecteur du lot C.
- **Grasping Vine Trap** : invisible tant que non spécifiquement cherché
  (pas à la fouille ordinaire de salle — comme les pièges magiques de
  Wizards of Morcar, mais DÉTECTABLE, juste pas par le canal habituel),
  immobilise le héros jusqu'à destruction par une action (pas un
  désamorçage classique). Nouveau sous-cas de piège : « se retire par
  destruction, pas par un jet de compétence ».

### Lot F — Les trois modes de mort/difficulté (Standard/Heroic/Story)

Le plus gros chantier de la boîte, déjà chiffré comme tel par
`reference/18` (« chantier significatif »). Confirmé par l'audit du code :
**aucune trace** d'un état « incapacité récupérable » distinct de `tombe`
(`EtatPersonnageQuete.tombe`, relevable), ni de jeton crâne, ni de politique
de mort de groupe configurable — notre mort de hérosest aujourd'hui `tombe`
→ TPK si tout le monde tombe (`ClotureCampagne.php`), UNE SEULE politique, la
plus proche du mode « Heroic » du livret sans son minuteur à jeton. Porter
les trois modes demande : un champ `groupes.mode_mort` (3 valeurs), un état
« incapacité » distinct de `tombe` avec son propre minuteur, et une
régénération passive hors-combat pour le mode Story. Gros lot, à cadrer avec
René avant d'écrire (Q1 ci-dessous) — pas un prérequis des autres lots.

### Lot G — Campagne ramifiée (Choose Your Path)

16 quêtes, jusqu'à 3 embranchements et 3 conclusions, certains chemins
nécessitant qu'une quête optionnelle précédente ait été jouée. **Sans objet**
pour notre génération procédurale — nous NE rejouons aucune des 16 quêtes du
livret (le point d'adaptation central du brief). La seule chose portable :
l'IDÉE d'un graphe de gabarits avec conditions d'accès, à comparer aux
embranchements déjà esquissés par Spirit Queen's Torment (tours au choix
libre) avant d'inventer un second mécanisme. Proposé : **écarté comme
mécanique de boîte**, noté comme inspiration pour une future évolution du
générateur de campagne si le besoin se représente ailleurs (Q2).

### Lot H — Jetons de dégât différé (Spawnling) — déjà couvert autrement

`reference/09_bestiaire.md` documente `s_accroche` comme la réinterprétation
VOLONTAIRE du Rejeton putride (« le rejeton ne frappe pas, il S'ACCROCHE » —
un jeton sur la fiche du héros, 1 PV automatique et indéfendable par fin de
tour, cumulable, retiré par un crâne). C'est DÉJÀ un mécanisme de dégât
différé attaché au héros — **implémenté** (`MoteurDread.php:2892`,
`database/migrations/2026_08_10_000001_jetons_rejeton.php`). **Rien à faire
ici**, au-delà de vérifier que cette divergence (Venomous → s_accroche, plutôt
que la paralysie standard pour CETTE créature précise) est bien dans la
liste nommée de divergences testée dans les deux sens (à vérifier à
l'implémentation, pas constaté par ce plan faute d'avoir ouvert
`BestiaireSourceTest` à cette ligne précise).

### Lot I — Ordre de tour choisi par les joueurs

Règle optionnelle, coût mineur signalé par `reference/18` lui-même
(extension ciblée du planificateur de tour). Confirmé mineur, pas
d'approfondissement nécessaire ici.

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | Les trois modes de mort (Standard/Heroic/Story, lot F) : les porter tous les trois, ou seulement Heroic (le plus proche de notre `tombe` actuel) ? | Heroic d'abord — c'est celui qui demande le moins d'écart avec `tombe`/`ClotureCampagne` existants ; Story (régénération hors-combat, mort de groupe seulement si TOUS incapacités) et Standard (mort immédiate à 0 PV, plus dur que l'actuel) en option plus tard |
| **Q2** | La campagne ramifiée (lot G) : simple inspiration écartée, ou vaut-elle un chantier futur de « graphe de gabarits » partagé avec Spirit Queen's Torment ? | Écartée maintenant — aucune des deux boîtes n'a de lecteur aujourd'hui, inventer un graphe pour deux boîtes qui ne le réclament qu'en narration n'est pas prioritaire |
| **Q3** | Boss du thème (lot A) : Gretzl (3 phases, plus résistante) ou Gruulob (2 phases, plus simple) en premier, une fois le mot-clé `phases` construit (coordonné avec Ogre Horde) ? | Gretzl — c'est la seule qui donne au thème un boss VISIBLEMENT différent des Ogres/Seigneur, et ses 3 sorts sont déjà sourcés comme ceux de Gruulob |
| **Q4** | Le lecteur « mobilier destructible avec PV/défense » (lot C) : construit en commun avec `docs/plan-wizards-of-morcar.md` lot A (même brique, trois usages chacun) ? | Oui, voir la question jumelle posée dans le plan Morcar — un seul chantier pour les deux boîtes |
| **Q5** | Lot D (Basin à trois effets) : trois entrées de mobilier distinctes au même visuel, ou un sélecteur d'effet sur une entrée unique ? | Trois entrées distinctes — cohérent avec « une règle, un point de passage », pas de branchement conditionnel caché dans un lecteur |

## 5. Sources à demander (photos des cartes)

- Les 6 artefacts nommés (*Emberwrought Diadem*, *Bracers of the Wild*,
  *Fangwarden Armlet*, *Sapphire Skull*, *Girdle of Might*, *Emerald Heart of
  Delthrak*) — aucun n'est au catalogue aujourd'hui ;
- les 4 potions de l'Alchimiste (texte déjà résumé en clair dans
  `reference/18`, mais la carte fait foi pour le prix exact et les
  conditions d'usage) ;
- la carte *Lightning Bolt* de Bakabri, pour confirmer ou infirmer l'hypothèse
  « Éclair de Chaos » (§3 lot A) ;
- les cartes de classe Explorateur/Berserker elles-mêmes, si une divergence
  est un jour suspectée avec `reference/01_personnages.md` §4bis-4quater
  (aujourd'hui aucune raison de douter, simple précaution).

En attendant, le livret suffit à tous les lots B à I — aucun n'est bloqué par
l'absence de carte, contrairement au boss (lot A, bloqué seulement par le
mot-clé `phases` à construire, pas par une source manquante).

## 6. Ordre proposé

1. **Lot B** (terrain gênant) — aucun prérequis, le plus petit lot possible,
   fait tout de suite.
2. **Lot C + le lot A jumeau de `plan-wizards-of-morcar.md`** (mobilier
   destructible générique) — débloque Delthrak (Crystal Cluster), Morcar
   (Murs magiques, Haut Autel, Coffres du Dread) et potentiellement l'*Ice
   Wall* de la boîte de glace désactivée. Le lot le plus rentable des deux
   boîtes.
3. **Lot D** (Basin) et **lot E** (Cocoon, Grasping Vine Trap) — dépendent du
   lecteur du lot C.
4. **Lot A** (boss, `phases` + capacités réactives) — coordonné avec
   `docs/plan-ogre-horde.md` lot C (même mécanisme `phases`, même famille de
   capacités réactives pour Gruzbella/Gretzl). Le chantier qui donne au thème
   son identité.
5. **Lot F** (modes de mort) selon Q1 — le plus gros morceau, indépendant du
   reste, à ne pas laisser bloquer les autres lots.
6. **Lot I** (ordre de tour au choix) en fin de liste, mineur.
7. **Lot G** (campagne ramifiée) écarté par défaut (Q2).

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN JEU
→ données**, registres testés dans les deux sens, contrat d'API d'abord,
effets automatiques annoncés. Pest sur une **copie sqlite jetable** ;
`sauvegarder.sh` avant toute migration ; aucune purge de
`groupes`/`personnages`/`joueurs` ; redémarrer `queue` et `queue-jeu` après
le PHP. Une campagne d'agents (`campagne-agents`) sur le thème
`jungles_delthrak`, une fois le lot A fait, est ce qui trouvera ce que les
tests ne trouvent pas — comme pour Ogre Horde.

> **Mise à jour 2026-10-05 (René)** : les six artefacts ne sont PAS à
> photographier — leurs cartes sont reproduites dans le livret, p. 50
> (« Treasure and Artifact Reference »). Transcrits dans
> `reference/18_extensions.md` § Jungles of Delthrak, « Artefacts TRANSCRITS ».

> **Mise à jour 2026-10-09 (René, exécution — `docs/plan-delthrak-execution-2026-10-09.md`)** — statut par lot, à lire avec les décisions ci-dessus.
>
> - **Lot F (modes de mort) — PORTÉ EN PARTIE, par décision de René : le mode Story seul.** « Tombé, jamais mort » ; ce qui manquait au mode Story est en place et testé en jeu (`ModeStoryDelthrakTest`) : le relèvement par le Corps à l'ouverture du round, et le lanceur qui tombe se soigne d'un sort disponible. **Heroic** (jeton crâne) et **Standard** (mort permanente) sont **écartés**, pas reportés — la mort permanente est refusée. Q1 ci-dessus est tranchée dans ce sens. Décision d'interprétation : le livret ne dit pas quand le point revient ; c'est à l'ouverture du round (`docs/regles/vocabulaires-effets.md`).
> - **Lot A (boss) — GRUULOB PORTÉ.** Deux formes, mot-clé `phases` déjà construit, variante gobeline de *Summon Orcs* (« Invocation de gobelins »), `cout` 15 mesuré par la méthode du bestiaire, ajouté au pool du boss final (`GabaritQueteSeeder`). **Défaut trouvé** : la rotation du boss final tirait les FORMES suivantes (Demonspider, Demonape) ; corrigé pour les deux boss par `Monstre::nomsDeFormeSuivante()`. **Nommés, non portés** : le tir à distance « dans les deux formes » (même décision que Gretzl) et le « 1 dé d'attaque de plus pour tous les gobelins de la quête » (règle de quête, que la génération procédurale ne porte pas).
> - **Lot I (ordre de tour choisi par les joueurs) — REPORTÉ, pas écarté.** Le livret le dit optionnel, et `reference/18` le juge « mineur ». Il ne l'est pas : la décision C1 (`reference/03_combat.md`, l. 118 — initiative figée pour toute la quête) est levée à CHAQUE round par cette règle. Il faut (1) une décision collective des joueurs à chaque round, avec une règle pour le désaccord ; (2) un état de round stocké en base ; (3) une option de menu et son contrat ; (4) la place des alliés dans cet ordre (chantier 3a : un allié joue dans le tour de son héros). Décision de René à prendre avant tout chantier : lever C1 pour cette règle, ou la garder écartée.
> - **Lot G (campagne ramifiée) — ÉCARTÉE par René (2026-10-09).** Aucun graphe de gabarits pour cette boîte. L'idée — des quêtes à conditions d'accès, une quête complétée débloquant la suivante — est notée pour **Spirit Queen's Torment** (tours au choix libre), si le besoin revient là-bas. Notée aussi dans `reference/18_extensions.md`, § Jungles of Delthrak, 5.
> - **Migrations** : aucune. **Seeders à rejouer** (contenu seul, `updateOrCreate`) : `MonstreSeeder`, `SortDreadSeeder`, `GabaritQueteSeeder`.
