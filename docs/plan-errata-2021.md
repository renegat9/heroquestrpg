# Plan — Errata HeroQuest 2021 (compilation Ye Olde Inn) appliqués au jeu

> ⚠ **Document DATÉ du 2026-10-01**, comme les autres `docs/plan-*.md` : un
> instantané d'audit, pas tenu à jour. Les règles en vigueur iront dans
> `docs/regles/` une fois tranchées.
>
> **Source** : *Hero Quest 2021 Remake: Errata Compilation*, Ye Olde Inn,
> `forum.yeoldeinn.com/viewtopic.php?f=143&t=5834` — récapitulatif de
> HispaZargon (2 messages anglais + 1 espagnol), **dernière mise à jour
> 30/03/2026**. Les messages postérieurs (jusqu'au 16/09/2026) ont été lus :
> seulement des coquilles de carte dans la quête en ligne *Return of the
> Witch Queen*, aucune règle.

## 0. Décisions de René (2026-10-01) et état

| Point | Décision | Fait |
|---|---|---|
| B1 à B4 | Tous faits | migrations `2026_10_01_000001` (Brassards), `…000003` (Vent Véloce), `…000004` (type d'arme) |
| B2, question du Moine | Pas tranchée explicitement : on suit la décision officielle, le Moine manie les trois artefacts | `Equipement::estArmeDeType()` |
| C1, Mind 0 | **On corrige** : l'état de choc officiel remplace la chute (AtOH p. 9, texte exact dans `docs/regles/sorts-dread.md`) | porté |
| C2, mobilier et vue | **On garde notre fonctionnement**, en divergence nommée | doc 17 §3, `MobilierSeeder`, `carte-donjon.md` |
| C3, Potion de restauration | **On corrige** : 300 po | migration `…000002` |
| C4, rupture | **On corrige** : on lance `pv_mind`, les points de Mind actuels | `MoteurSorts::tenterRuptureHeros()` |

## 1. Ce que vaut la compilation

C'est une liste **communautaire** et elle le dit : noir = erreur certaine,
bleu = probable, rouge = interprétation qui attend Hasbro. Elle n'a de force
que là où elle rapporte une **confirmation officielle** (tweet du compte
« Zargon », Discord Avalon Hill, réimpression corrigée). C'est le seul tri
retenu ici : une confirmation officielle peut changer notre moteur, une
interprétation de forum non.

Environ **70 % des entrées ne nous concernent pas** : portes manquantes,
cases mal grisées, icônes absentes, nombre de figurines ou de portes de la
boîte insuffisant, notes de quête incohérentes. Nos donjons sont générés et
nous ne jouons aucune carte imprimée.

## 2. Déjà conforme (audit du 2026-10-01, aucun travail)

| Errata / clarification officielle | Où c'est déjà juste |
|---|---|
| *Courage* : la prochaine attaque seulement, rompu si plus aucun monstre en vue | `SortSeeder` (`prochaine_attaque` + `plus_de_monstre_en_vue`) |
| *Handaxe* interdite au magicien | tag `arme_courante`, absent des classes magicien et warlock |
| *Crossbow* : une case en diagonale n'est pas adjacente, on peut donc y tirer | `Grille::sontAdjacentes()` orthogonal par défaut + `ResolveurTour` (`inutilisable_adjacent`) |
| Bouclier en bois, casque en métal ; *Rust* sans effet sur les artefacts | `objets.metallique` + `epargne_artefacts` (`SortDreadSeeder`) |
| Barde : 3 dés de défense, 2 avec une armure de métal ou un bouclier | base 2 + `Equipement::porteMetalOuBouclier()` (voir aussi B1) |
| Moine : 2 dés à mains nues | `ClasseHerosSeeder` (`des_attaque` 2) |
| *Shapeshift* : +1 attaque **et** +1 défense, cumulable avec l'équipement | `SortSeeder` *Métamorphose* |
| *Demonform* : +1 dé valable aussi pour la baguette | `bonus_des_attaque` sans `condition_bonus_attaque` (`MoteurSorts::bonusDes()`) |
| Rogue : démarre avec la Bandoulière | `EquipementDepart::PAR_CLASSE` |
| *Opportunistic Striker* : vaut aussi à distance, sans Bandoulière, adjacence orthogonale seulement | `ResolveurTour` (`bonus_des_attaque_flanc`, `cibleFlanqueeParUnHeros()`) |
| *Ambidextrous* : utilisable avec une seule arme, les deux attaques avec la même | `ResolveurTour::ambidextrie()` — voir B3 : notre « divergence assumée » **est** la règle officielle |
| Styles élémentaires : un par tour ; *Twisting Torrent* activable pendant le tour d'un monstre | `StylesElementaires::activable()` |
| *Channel Dread* : lanceurs adjacents, pas en diagonale | `MoteurDread` (distance de Manhattan = 1) |
| *Raven's Talon* : relance d'**un** dé | texte anglais « any 1 Attack die » ; l'erratum espagnol est une erreur de traduction |
| Étreinte du Yéti seulement si ≥ 1 PV infligé | `MonstreSeeder` (`etreinte`) |
| *Entangling Roots* : ne se déclenche que si **le héros** entre en case adjacente | `racines_entravantes` |
| Rejetons (Spawnlings) : dégâts à la fin du tour du héros qui les porte | `ResolveurTour::rongerParRejetons()` |
| *Danger Sense* : les cartes de piège seulement, jamais le monstre errant | `repiocher_carte_piege` (issue `piege`) |
| Désamorçage raté : les dégâts **du piège**, pas 1 PV | `MoteurPieges::declencher(..., 'desamorcage_rate')` |
| Mobilier infranchissable | `bloque_mouvement` partout (voir B3 pour la source) |
| Les monstres ne bloquent pas la vue quand on révèle ce que voit le héros | `Grille::ligneDeVue()` : `figuresBloquent = false` par défaut |
| Arbalétrier mercenaire : épée large au contact | on garde le texte de la carte : l'erratum est « rouge », sans confirmation |

Les errata **espagnols** (traductions de *Courage*, *Swift Wind*, *Genie*,
*Rust*, *Fear*, *Channel Dread*, *Raven's Talon*…) ne nous touchent pas :
nos cartes sont transcrites depuis les photos **anglaises**. Ils restent
utiles comme liste des phrases ambiguës à relire dans nos libellés français.

## 3. À corriger — ça s'applique et c'est sourcé

### B1. Les Brassards ne sont pas en métal ⭐ priorité

Carte officielle (citée dans `ObjetSeeder`) : « *These **hardened leather**
bracers give you 1 extra Defend die.* » Pourtant `ObjetSeeder` les sème
`metallique => true`, et `ReglesDeClasseTest` le fige
(`toContain(... 'Brassards')`). La compilation va dans le même sens :
Hasbro confirme que le magicien peut porter les Brassards, et la communauté
les cite comme **l'**armure non métallique du Barde.

Effets du défaut aujourd'hui :
- le **Druide** et le **Rogue** se les voient **refuser**
  (`Equipement::estAccessible()`, `SANS_METAL`) ;
- le **Barde** qui les porte **perd** son dé de défense supplémentaire
  (`porteMetalOuBouclier()`).

Travail :
1. Migration : `UPDATE objets SET metallique = 0 WHERE nom = 'Brassards'`.
   C'est le catalogue, pas des données de partie. Lancer
   `sauvegarder.sh` avant, comme toujours.
2. `ObjetSeeder` : `metallique => false`, avec la citation « hardened
   leather ».
3. Tests : retourner l'assertion de `ReglesDeClasseTest`, et ajouter trois
   cas : le druide accède aux Brassards, le rogue aussi, et le barde garde
   son 3ᵉ dé avec.
4. Recalculer les dés des héros existants qui les portent
   (`Equipement::recalculerCombat()`), dans la même migration ou au
   prochain chargement selon ce que le code prévoit déjà.
5. Corriger le docblock de `porteMetalOuBouclier()`, qui parle encore des
   « brassards du magicien (`armure_magicien`) ».
6. Redémarrer `queue` et `queue-jeu`.

### B2. Le type d'arme des artefacts

Confirmations officielles : *Orc's Bane* est une **épée courte**, *Phantom
Blade* une **dague**. La carte *Raven's Talon* se décrit elle-même comme
« *this **dagger*** ». Chez nous, deux règles comparent des **noms** :
- *Ambidextrie* du Rogue : `armes => ['Dague', 'Épée courte']`
  (`CompetenceSeeder`). Sans Bandoulière, une dague artefact ne déclenche
  rien ;
- liste blanche du Moine (`objets_autorises`) : les trois artefacts en
  sont exclus, alors que la règle officielle fait du *Fléau des Orques*
  une épée courte.

Travail, dans l'ordre de `ajouter-mecanique-moteur` (vocabulaire → lecteur
→ test en jeu → données) :
1. Une clé du vocabulaire fermé (`MotsClesEquipement`), par exemple
   `est_une` (`'Dague'` / `'Épée courte'`), lue par **un seul** point de
   passage, `Equipement::estArmeDeType(Objet, string)`.
   ⚠ **Ne pas réutiliser `compte_comme_arme`** : c'est la règle de la
   Bandoulière (« toujours considéré armé », la pièce compte dans le sac).
   Ici, l'arme ne compte que si elle est **en main**.
2. Brancher ce lecteur dans `ambidextrie()` et dans le contrôle de liste
   blanche d'`Equipement::estAccessible()`.
3. Données : *Fléau des Orques* → `Épée courte`, *Lame Fantôme* et *Serre
   du Corbeau* → `Dague`.
4. ❓ **René** : le Moine peut-il manier ces trois artefacts ? C'est ce
   qu'implique la règle officielle. Si la réponse est non, seule la moitié
   *Ambidextrie* est faite, et l'exclusion est écrite comme divergence
   nommée.

### B3. Corriger la documentation (des sources existent maintenant)

- **Mobilier infranchissable** : `MobilierSeeder` et la migration
  `create_mobiliers_table` disent « aucun livret ne le dit, choix de
  portage ». Il y a maintenant deux sources : la confirmation Hasbro
  rapportée par la compilation, et la règle optionnelle de First Light
  p. 8 (`reference/18_extensions.md` §First Light 5).
- **`bloque_vue`** : le docblock de `MobilierSeeder` dit qu'« aucun des
  deux livrets officiels ne traite JAMAIS de la ligne de vue du mobilier ».
  C'est faux depuis First Light p. 8. À réécrire quelle que soit la
  décision C2.
- **`ResolveurTour::ambidextrie()`** : la « divergence assumée » (seconde
  frappe avec l'arme équipée) est en fait la **décision officielle**
  d'Avalon Hill. À requalifier, pas à supprimer.
- **Incohérence sur l'état de choc** : `docs/regles/sorts-dread.md` dit que
  l'état de choc attend « le livret », alors que
  `reference/18_extensions.md` (§Frozen Horror, « Points de Mind à 0 = état
  de choc ») en cite déjà la règle chiffrée. Corriger selon C1.

### B4. « La prochaine fois qu'il se déplace » (petit)

*Swift Wind* (« *the next time they move* ») et la *Potion of Speed*
(« prochain mouvement ») utilisent la durée `ce_tour`. Le bonus est donc
**perdu** si le porteur termine son tour sans avoir bougé, par exemple le
lanceur qui se cible lui-même **après** avoir marché. Le
`consommerBuffs('deplacement_multiplie')` se déclenche déjà au déplacement.
Il manque seulement une durée qui n'expire pas en fin de tour. Ajouter
`DureeEffet::PROCHAIN_DEPLACEMENT`, son déclencheur (le même point de
consommation), un test en jeu, puis changer les deux données.

## 4. Décisions de René

### C1. Mind 0 : tomber, ou l'état de choc officiel ?

- **Officiel** : un personnage à 0 Mind est **en état de choc** (*Frozen
  Horror*, *Against the Ogre Horde* p. 9). Il n'a plus qu'1 dé rouge de
  déplacement, 1 dé d'attaque et 2 de défense, et **l'équipement ne les
  augmente plus**. Hasbro confirme que la règle s'applique à toutes les
  créatures (héros, monstres, alliés). Les règles du Mind ont aussi été
  révisées dans la quête en ligne officielle *Into the Northlands*, que nous
  n'avons pas (⚠ non trouvé : téléchargement officiel à récupérer).
- **Chez nous** : arbitrage de René du 2026-09-06, un héros à 0 Mind
  **tombe** comme à 0 Body (`MoteurDegats::infligerMindAHeros()`).
- **Options** :
  - (a) garder la chute et l'écrire comme **divergence nommée** dans
    `docs/regles/sorts-dread.md` ;
  - (b) porter l'état de choc : une condition, plus une couche de plafond
    dans `recalculerCombat()` / `desDefenseHeros()` / `Deplacement`. Cela
    réglerait du même coup la dette de *Mind Freeze*.

### C2. Le mobilier et la ligne de vue

Trois versions s'opposent :
- **Base officielle** : Hasbro (tweet) dit que le mobilier **ne bloque
  pas** la vue. First Light p. 8 le confirme : « *in the original
  HeroQuest, furniture […] didn't obstruct movement or line of sight* ».
- **Règle optionnelle de First Light** : **tout** le mobilier bloque la vue
  et le passage.
- **Chez nous** : une solution hybride. Tout le mobilier bloque le
  passage, mais seuls les meubles **hauts** (bibliothèque, râtelier,
  armoire) bloquent la vue. C'est un choix de portage explicitement non
  sourcé.

Options :
- (a) adopter la règle optionnelle de First Light en entier
  (`bloque_vue = true` partout) ;
- (b) revenir à la base (`bloque_vue = false` partout) ;
- (c) garder l'hybride comme **divergence nommée**, avec les deux sources
  citées.

`bloque_vue` est relu depuis le catalogue par `FabriqueGrille::pour()`.
Une migration du catalogue suffit donc, et les cartes en cours en profitent
sans y toucher.

### C3. Prix de la Potion of Restoration : 500 ou 300 po ?

Notre carte © 2022 (*Kellar's Keep* / *Witch Lord*) dit **500**. Selon la
compilation, Hasbro l'a ramenée à **300** sur la carte *Alchemy* de *Rise
of the Dread Moon*. ⚠ Il faut une **photo** de cette carte avant de toucher
au prix (« never seed a value the cards don't source »). Si elle confirme :
migration `prix_base` 500 → 300, et `§2.1bis` de doc 16 cite les deux
impressions.

### C4. Rupture des sorts de Dread : quel Mind ?

Les cartes disent « *1 red die for each of their Mind Points* », et *Mind
Blast* précise « ***currently** have* » (la compilation insiste sur ce
mot). Nous lançons **`attribut_mind`**, notre attribut d'épreuve (3-4),
choix documenté dans `sorts-dread.md`. Les cartes parlent de la **jauge**
`pv_mind`, valeur actuelle. Ce choix ne pesait rien tant que personne ne
perdait de Mind. Maintenant que *Mind Freeze* fait de vrais dégâts de Mind,
c'est une vraie question : un héros à l'esprit entamé doit-il se libérer
moins facilement ?

### C5. Brassards + Cape du Magicien (faible)

La communauté **suppose** qu'on peut les cumuler, sans confirmation
officielle. Chez nous, les deux occupent l'emplacement `armure`.
Recommandation : ne rien changer sans demande.

## 5. Non applicable (pour mémoire)

- **Cartes de quête imprimées** : portes, couleurs, icônes, figurines en
  nombre insuffisant, notes incohérentes (Telor, Arène de Misildia, Venin…).
- **Contenu non porté** (la clarification est à reprendre **le jour** où
  il l'est) :
  - *Venim* : stats officielles M9 A6 D6 B5 Mi4, pas celles de Kedrick ;
  - *Deathspinner* : 6 cases, *Spawn* / *Agile* / *Venomous* ;
  - *Gretzl* : garde ses capacités sous toutes ses formes ;
  - *Minotaur Gore* ;
  - sorts de *Wizards of Morcar* : *Call Skeletons* agit le tour même ;
  - *Twist Wood*, *Ogre Grog*, *Sapphire Skull* (action, pas une arme) ;
  - pièges *Swinging Blade* ;
  - *Sorcerer's Table* et *Fireplace* : absentes du catalogue `mobiliers`.
- **Alliés** : « ils défendent avec les boucliers blancs, comme des
  héros ». Sans objet tant que les monstres ne ciblent pas les alliés
  (`phaseAllies()` : hors périmètre v1). La règle sera à appliquer **le
  jour** où ce ciblage arrivera.

## 6. Ordre proposé

1. **B1 Brassards** : sourcé, petit, et il corrige trois classes.
2. **B3 documentation** : sans risque, elle prépare C1 et C2.
3. **C1 à C4** : réponses de René.
4. **B2 type d'arme** (après la réponse sur le Moine), puis **B4**.

Pour chaque lot : Pest sur une **copie sqlite jetable**, jamais la base du
conteneur ; `sauvegarder.sh` avant toute migration ; aucune commande
destructrice sur `groupes`/`personnages`/`joueurs` ; redémarrer
`queue` / `queue-jeu` après le PHP.
