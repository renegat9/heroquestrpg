# Conception — Bestiaire

> Document d'analyse. Reprend les monstres de **HeroQuest**, adaptés à notre système. Valeurs = **base HeroQuest, à ajuster en playtest**. Les monstres sont **pilotés par un comportement scripté** (C2) et **habillés par le MJ IA** (Q6). Projet **interne** : le contenu HeroQuest est utilisé directement.

---

## 1. Vue d'ensemble

- Un monstre est un **bloc de stats du catalogue** ; le moteur le résout, le MJ IA le **renomme/redécrit** selon le thème (un *gobelin* → *écumeur des cryptes*) **sans changer les stats**.
- Pas d'attribut de jet (Body/Mind) comme les héros : un monstre n'a besoin que d'**Attaque, Défense, PV de Body, PV de Mind, Déplacement**.
- **Déplacement fixe** (pas de 1d6) — fidèle à HeroQuest et plus simple pour l'IA scriptée. Le héros, lui, utilise base + 1d6.

---

## 2. Bloc de stats d'un monstre

| Champ | Rôle |
|---|---|
| **Déplacement** | Cases par tour (valeur fixe) |
| **Attaque** | Dés de combat à l'attaque |
| **Défense** | Dés de combat en défense (compte les **boucliers noirs**) |
| **PV de Body** | Jauge de vie ; à 0 → vaincu |
| **PV de Mind** | Résistance aux **sorts mentaux** (jet de Mind du monstre) |
| **Coût** | Poids dans le **budget de rencontres** (adaptation de difficulté, doc Quêtes §2) |

> **Règle clé — Mind 0 (morts-vivants)** : un monstre à **PV de Mind = 0 est immunisé aux sorts mentaux** (il n'a pas d'esprit à affecter). Sans cette règle, le système de résistance (0 dé = 0 succès) en ferait à tort les cibles les plus faciles à contrôler.

---

## 3. Bestiaire de base

| Monstre | Dépl. | Attaque | Défense | PV Body | PV Mind | Note |
|---|---|---|---|---|---|---|
| **Gobelin** | 10 | 2 | 1 | 1 | 1 | Rapide, fragile, en nombre |
| **Orque** | 8 | 3 | 2 | 1 | 2 | Fantassin polyvalent |
| **Fimir** | 6 | 3 | 3 | 2 | 3 | Brute résistante |
| **Squelette** | 6 | 2 | 2 | 1 | 0 | Mort-vivant léger ; immunisé au mental |
| **Zombie** | 6 | 2 | 3 | 1 | 0 | Lent mais coriace ; immunisé au mental |
| **Momie** | 4 | 3 | 4 | 2 | 0 | Très défensive ; immunisée au mental |
| **Guerrier du Chaos** | 7 | 4 | 5 | 3 | 3 | Élite, redoutable des deux côtés |
| **Gargouille** | 6 | 4 | 5 | 3 | 4 | Le plus coriace du bestiaire de base |

---

## 4. Sous-boss & boss final

L'arc de campagne (doc Quêtes §4) jalonne la progression de **sous-boss** puis d'un **boss final**, construits comme des **blocs élites** :

- **Sous-boss** : version renforcée d'un monstre (PV de Body augmentés, +1 Attaque *ou* Défense, parfois **1 capacité**), ou un monstre haut de gamme (Guerrier du Chaos, Gargouille).
- **Boss final** : bloc **unique**, nettement supérieur (PV élevés, Attaque/Défense fortes, **1 à 2 capacités** signature, parfois des **sbires**).

Exemples (proposés, à équilibrer) :

| Type | Dépl. | Attaque | Défense | PV Body | PV Mind | Capacités |
|---|---|---|---|---|---|---|
| **Sous-boss « Champion »** | 7 | 4 | 4 | 5 | 3 | 1 |
| **Boss final « Seigneur »** | 7 | 5 | 5 | 10 | 5 | 1–2 (+ sbires) |

### Bibliothèque de capacités (définie, l'IA assigne)
- **Invocation** : fait apparaître des sbires de base.
- **Frappe de zone** : touche plusieurs cibles adjacentes.
- **Régénération** : récupère des PV de Body par tour.
- **Résistance magique** : +dés de défense contre les sorts.
- **Charge** : déplacement + attaque renforcée.

> Capacités **mécaniques et bornées** (le moteur les résout) ; l'IA choisit l'habillage, pas les effets.

### Sorts de Dread (magie ennemie)
Catalogue défini de **magie du Chaos**, pendant maléfique des sorts héros (doc Sorts). **Résolution identique au doc Sorts §5** : dégâts → PV de Body des héros (défense applicable) ; contrôle/mental → **jet de Mind du héros pour résister** (S2). Le **Mind des héros devient ainsi une vraie défense** : un Magicien (Mind 4) encaisse, un Barbare (Mind 1) est exposé.

| Sort de Dread | Effet | Palier |
|---|---|---|
| **Trait de Chaos** | Dégâts à distance sur un héros | Sous-boss |
| **Frayeur** | Un héros perd des dés d'attaque / recule (résiste au Mind) | Sous-boss |
| **Sommeil** | Endort un héros (résiste au Mind) | Sous-boss |
| **Tempête de feu** | Dégâts de zone sur les héros (défense applicable) | Sous-boss / boss |
| **Invocation de morts-vivants** | Fait apparaître squelettes / zombies | Boss |
| **Commandement** | Contrôle un héros un tour (résiste au Mind) | Boss |
| **Fuite** | Le lanceur se téléporte vers sa phase suivante | Boss |

**Répartition sur l'arc** : les **sous-boss** lancent déjà les sorts mineurs — la magie du Chaos n'est **pas réservée à la quête finale**. Le **boss final** ajoute les sorts vilains. L'intensité monte avec l'arc (doc Quêtes §4).

**Usages** : un lanceur dispose d'un nombre limité d'usages par rencontre, déclenchés par son comportement scripté (C2).

---

## 4bis. Les CARTES de sorts de Dread (source officielle, 2026-09-04)

> **Statut de source.** Les 29 sorts ci-dessous sont transcrits carte par carte
> depuis `dread_spells.pdf` — 30 photos de René (Channel Dread est photographié
> deux fois, en tirage 2023 et 2024), déposées dans le dossier Drive du projet
> [`1seESGzXRhVw7ijIPuRVisaE36BPPoJ53`](https://drive.google.com/drive/folders/1seESGzXRhVw7ijIPuRVisaE36BPPoJ53).
> Chaque carte porte son © Hasbro (2021 → 2024), c'est-à-dire sa boîte
> d'origine. **Cette section prime sur le tableau du §4**, qui devient un
> historique : les sept sorts qu'il listait étaient les nôtres, et l'un d'eux —
> le *Trait de Chaos* — n'existe sur **aucune** carte. Il a quitté le catalogue
> par migration le 2026-09-04, comme les cinq artefacts sans carte avant lui.
>
> Même discipline que le §2.1bis de la doc 16 : ce qui est écrit ici est
> opposable, ce qui n'y est pas ne se seede pas.

### Répartition par boîte (le © de la carte)

| © | Boîte | Cartes |
|---|---|---|
| 2021 | Base (Zargon) | Rust, Cloud of Dread, Command, Lightning Bolt, Summon Undead, Sleep, Ball of Flame, Tempest, Summon Orcs, Fear, Firestorm, Escape |
| 2022 | Against the Ogre Horde / Frozen Horror | Chill, Ice Wall, Skate, Ice Storm, Mind Freeze, Soothe |
| 2023 | Mage of the Mirror / Rise of the Dread Moon | Dispel, Mind Blast, Mirror Magic, Reanimation, Restore Dread, Summon Wolves, Werewolf's Curse, Channel Dread, Summon Specters, Dreadlights |
| 2024 | Jungles of Delthrak | Creeping Grasp, Channel Dread (retirage) |

### 4bis.1 — Les 29 cartes, texte par texte

Format : **titre de la carte** — texte de la carte (abrégé quand il se répète
mot pour mot d'une carte à l'autre) → *notre portage*, ou ⚠ *non porté* avec la
mécanique qui manque.

#### Dégâts

1. **Ball of Flame** (2021) — « This spell may be cast on any one hero,
   enveloping them in a ball of fire. It inflicts 2 Body Points of damage. The
   hero then rolls 2 red dice. For each 5 or 6 rolled, the damage is reduced by
   1 point. » → **Boule de Flammes** (`degats`, `degats_fixes: 2`,
   `resistance: des_rouges`, `des_resistance: 2`, `defense_applicable: false`,
   `type_degat: feu`). C'est **exactement** la carte héros du même nom
   (doc 16 §3bis) : même mécanique, même lecteur, l'autre bord de la table.
2. **Firestorm** (2021) — « creates a roomful of fire that inflicts 3 Body
   Points of damage on all heroes **and monsters** in the same room with the
   spellcaster. **The spellcaster is unaffected.** All victims immediately roll
   2 red dice. For each 5 or 6 rolled, the damage is reduced by 1 point. **Not
   used in corridors.** » → **Tempête de feu** (`zone: salle`,
   `degats_fixes: 3`, `des_resistance: 2`, `touche_monstres: true`,
   `epargne_lanceur: true`, `hors_couloir: true`, `type_degat: feu`).
   ⚠ Notre version d'avant frappait la case du lanceur + les 4 orthogonales
   avec 2 dés de combat : ni la bonne zone, ni la bonne résolution, et elle
   n'épargnait pas les monstres.
3. **Lightning Bolt** (2021) — « may be cast in a horizontal, vertical, or
   diagonal direction. The bolt travels in a straight line until it strikes a
   wall or closed door. It inflicts 2 Body Points of damage on all heroes or
   monsters that stand in its path. » → **Éclair de Chaos** (`rayon: true`,
   `degats_fixes: 2`, `touche_monstres: true`). Le lecteur existait déjà :
   `App\Partie\Rayon`, écrit pour le parchemin d'*Éclair* — même texte de carte,
   au mot près.
4. **Chill** (2022) — « causes 1 Body Point of damage to any one hero **or
   monster adjacent** to the spellcaster (**though not diagonally adjacent**).
   The victim **cannot defend** against the attack. » → **Morsure de Froid**
   (`portee: contact`, `degats_fixes: 1`, `defense_applicable: false`,
   `type_degat: froid`).
   ⚠ C'est cette carte qui donne enfin une **source** à `TypeDegat::FROID`,
   déclaré `SANS_SOURCE` depuis le 2026-08-xx. Conséquence à assumer plutôt
   qu'à cacher : le *Bracelet de Glace* et l'*Anneau de Chaleur* deviennent
   portables — ils ne le sont pas encore, et restent au registre des cartes.
5. **Channel Dread** (2023/2024) — « may be cast on any one hero to exhaust
   their lifeforce. Roll 1 red die. **For each monster adjacent to the caster
   that can cast this spell, add 1 point to the die total.** On 1, 2, or 3 =
   the hero resists. On 4 or 5 = the hero loses 1 Body Point. On 6+ = the hero
   loses 2 Body Points. » → **Canaliser l'Effroi** (`resistance: paliers_d6`,
   `paliers: {3: 0, 5: 1, 6: 2}`, `bonus_lanceurs_adjacents: true`).
   La carte la plus fréquente des extensions : sept adversaires nommés la
   portent (Blightweaver, Deathspinner, Gruulob, Arcane Mummy, Gretzl, Bakabri,
   Bloomrot Scion) et le Specter la lance **à volonté** (doc 18).
6. **Ice Storm** (2022) — « creates a blizzard of ice that affects an area
   2 squares wide by 2 squares long. Each monster and hero in that area is
   attacked separately by the spellcaster with 3 combat dice. **There is no
   chance to defend.** *Cannot be used in corridors.* » → **Tempête de Glace**
   (`zone: carre_2x2`, `des_degats: 3`, `defense_applicable: false`,
   `touche_monstres: true`, `hors_couloir: true`, `type_degat: froid`).
7. **Mind Freeze** (2022) — « ravages the mind of any hero. The hero rolls
   1 combat die for every Mind Point they possessed before the attack. If 1 or
   more white shields are rolled, the hero has 1 Mind point left. If no white
   shields are rolled, the hero has been reduced to zero Mind Points and goes
   into "shock". » → ⚠ **NON PORTÉ**. Il manque les **dégâts de Mind** :
   `MoteurDegats` ne connaît que le Body (c'est déjà la dette nommée de
   l'*Orbe du Ciel*, doc 16 §9.1), et « shock » est une règle du livret
   *Frozen Horror* que nous n'avons pas.

#### Contrôle

8. **Sleep** (2021) — « puts any one hero into a deep sleep. A sleeping hero is
   unable to move, attack, or defend themself. The spell can be broken
   immediately or on a future turn by the hero rolling 1 red die for each of
   their Mind Points. If a 6 is rolled, the spell is broken. » → **Sommeil**
   (condition *Endormi*, `resistance: rupture_6_par_mind`).
   ⚠ Correction de fond : nous en faisions un `jet_mind` **au lancer**, donc le
   sort pouvait échouer d'emblée et, une fois posé, ne se levait jamais tout
   seul. La carte dit l'inverse — il prend toujours, et c'est sa **poursuite**
   qui est contestée, à chaque tour du dormeur. C'est mot pour mot la règle que
   le sort *Sommeil* des héros a reçue le 2026-09-02 côté monstres : un seul
   lecteur, `rupture_6_par_mind`, désormais pour les deux bords.
9. **Command** (2021) — « puts any one hero under Zargon's control. […] the
   hero rolling 1 red die for each of their Mind Points. If a 6 is rolled, the
   spell is broken. However, until the spell is broken, Zargon, on their turn,
   can move the hero as a monster and attack other heroes. » → **Commandement**
   (condition *Commandé*, `resistance: rupture_6_par_mind`).
   ⚠ Notre `duree_tours: 1` disparaît : la carte ne donne **aucune** durée, elle
   donne une condition de rupture. Le pilotage existait déjà
   (`MoteurDread::jouerHerosSousCommandement()`).
10. **Fear** (2021) — « causes any one hero to become so fearful that they
    **may only use 1 Attack die**. The spell can be broken by the hero on a
    future turn by rolling 1 red die for each of their Mind Points. If a 6 is
    rolled, the spell is broken. » → **Frayeur** (condition *Apeuré*,
    `resistance: rupture_6_par_mind`).
    ⚠ « may only use 1 Attack die » est un **PLAFOND**, pas un malus : notre
    *Apeuré* portait `malus_des_attaque: 1`, ce qui ne coûtait qu'un dé au
    barbare qui en lance cinq. La condition porte désormais
    `des_attaque_max: 1`.
11. **Tempest** (2021) — « creates a small whirlwind that envelops one hero of
    your choice. That hero then **misses their next turn**. » → **Tourmente**
    (condition *Étourdi*, `resistance: aucune`).
    ⚠ La carte ne prévoit **aucun jet**, exactement comme sa jumelle côté héros
    (doc 16 §3bis). Et elle a réveillé une clé décorative : `perd_prochain_tour`
    vivait sur *Étourdi* **sans le moindre lecteur** depuis la création de la
    table — un héros étourdi jouait normalement. Le tour est sauté à
    l'ouverture du round (`ResolveurTour::ouvrirNouveauTour()`).
12. **Cloud of Dread** (2021) — « paralyzes **all heroes located in the same
    room or corridor**. A paralyzed hero is unable to move, attack, or defend
    themself. The spell can be broken at once or on a future turn by **each
    victim** rolling 1 red die for each of their Mind Points. By rolling a 6,
    the hero frees themself. » → **Nuée d'Effroi** (`zone: salle_ou_couloir`,
    condition *Paralysé*, `resistance: rupture_6_par_mind`). *Paralysé* portait
    déjà les trois interdits de la carte, mot pour mot.
13. **Mind Blast** (2023) — « paralyzes one hero **within the spellcaster's
    line of sight**. This hero cannot move or attack. **The hero defends with
    1 combat die.** […] rolls 1 red die for every Mind Point they currently
    have. If a 6 is rolled on any die, the spell is broken. » → **Choc Mental**
    (condition *Esprit brisé*, `resistance: rupture_6_par_mind`).
    ⚠ Condition **nouvelle**, et non *Paralysé* : la différence tient en un
    mot — le paralysé ne défend pas du tout, celui-ci défend à **1 dé**. D'où
    la clé `des_defense_max`, lue par `MoteurSorts::desDefenseHeros()`, le seul
    calcul de défense qui fasse foi.
14. **Dreadlights** (2023) — « surrounds any one hero in the spellcaster's line
    of sight with eerie tongues of ghostly light. **All monsters roll one
    additional Attack die when attacking the affected hero.** The spell can be
    broken at once or at the start of the hero's future turns by rolling 1 red
    die. **On a roll of 5 or 6**, the spell is broken. » → **Feux de l'Effroi**
    (condition *Désigné*, `resistance: rupture_5_6_un_de`).
    ⚠ Seule carte dont la rupture ne se joue **pas** sur le Mind : un seul dé,
    seuil 5-6. Un `rupture_6_par_mind` déguisé aurait rendu le sort presque
    impossible à lever pour un barbare (Mind 1) et trivial pour un magicien —
    l'inverse de ce que la carte écrit.
15. **Creeping Grasp** (2024) — « may be cast on any one hero to ensnare them
    with vines. Place a grasping vines tile under the targeted hero. They must
    roll 1 combat die. If they roll a skull, they suffer 1 Body Point of damage
    **and are restrained, unable to move from that square**. The targeted hero
    **or another adjacent hero can spend an action to destroy the vines**,
    freeing the ensnared hero. » → **Étreinte des Ronces**
    (`resistance: des_combat_crane`, `degats_fixes: 1`, condition *Immobilisé*).
    ⚠ Elle donne son premier producteur à *Immobilisé* et son premier lecteur à
    son `fin: liberation`, tous deux au catalogue et inertes depuis la création
    de la table. L'action de libération est l'option `liberer_entraves`
    (soi-même ou un voisin), taillée sur `soin_allie`.
16. **Werewolf's Curse** (2023) — « The hero rolls a red die. A roll of 6 means
    the spell has no effect. Any other result means the hero is now afflicted
    with the werewolf's curse. See the "Turning Heroes into Werewolves" section
    of the *Mage of the Mirror* Quest Book. » → ⚠ **NON PORTÉ**. La carte
    délègue toute sa règle à un livret que nous n'avons pas ; transformer un
    héros en monstre jouable par le MJ est une mécanique entière, pas un
    lecteur.
17. **Rust** (2021) — « causes any one metal sword or helmet to become so thin,
    brittle, and useless that it can never be used again. **Not effective
    against artifacts.** » → **Rouille** (`type: destruction`, `effet.detruit`).
    ⚠ Portée le 2026-09-04 sur arbitrage de René (« c'est correct qu'un joueur
    puisse perdre un objet »). C'est le **seul sort du paquet dont l'effet
    survit à la quête** : la pièce quitte l'inventaire pour de bon, et les dés
    sont recalculés par `Equipement::recalculerCombat()`, le même point de
    passage que l'arme lancée.
    ⚠ `objets.metallique` a changé de portée pour elle : la colonne ne marquait
    que les armures, elle marque désormais **toute pièce dont un lecteur peut
    lire la matière** — donc aussi les épées, haches et brassards. Les deux
    lecteurs historiques (accès des classes sans métal, dé du Barde) sont
    **bornés à la catégorie `armure`** : sans cela, marquer les épées aurait
    interdit au Druide et au Rogue toute arme de métal, une interdiction
    qu'aucune carte ne prononce.
    ⚠ **Une seule divergence, et elle est petite** : la carte énumère « sword or
    helmet », notre catalogue n'a aucune notion d'« épée », et une hache de
    bataille rouille exactement comme une épée longue. On lit donc « une pièce
    de **métal** en main ou sur la tête ». Le Bâton, la Baguette et l'Arbalète
    sont de bois : immunisés sans qu'aucune exception ne soit écrite.

#### Invocation

18. **Summon Undead** (2021) — « conjures up a group of undead to surround and
    protect the spellcaster. Roll 1 red die: on 1-2 = 4 skeletons ; on 3-4 =
    3 skeletons, 2 zombies ; on 5-6 = 2 zombies, 2 mummies. » →
    **Invocation de morts-vivants** (`table_d6`).
    ⚠ Notre version invoquait **2 squelettes**, point. La table du dé est ce qui
    fait la différence entre un renfort et une bascule de combat.
19. **Summon Orcs** (2021) — « on 1-3 = 4 orcs ; on 4-5 = 5 orcs ; on 6 =
    6 orcs. » → **Invocation d'orques** (`table_d6`).
20. **Summon Wolves** (2023) — « conjures up a number of giant wolves to attack
    the spellcaster's enemies. (Place the giant wolves adjacent to the
    spellcaster.) On 1-2 = 1 giant wolf ; 3-4 = 2 ; 5-6 = 3. » →
    **Invocation de loups** (`table_d6`, *Loup géant* du bestiaire, doc 18
    p. 693).
21. **Summon Specters** (2023) — « on 1-2 = 1 Specter ; on 3-4-5 = 2 Specters ;
    on 6 = 3 Specters. » → **Invocation de spectres** (`table_d6`, *Spectre*
    du bestiaire, doc 18 p. 823).
22. **Reanimation** (2023) — « enables the spellcaster to reanimate **all
    defeated skeletons, zombies, or mummies in the same room** as the
    spellcaster. These monsters rise from the floor, with all lost Body Points
    restored, and attack the heroes again. » → **Réanimation**
    (`reanime: [Squelette, Zombie, Momie]`, `zone: salle`). Nos instances
    vaincues restent en base (`etat != actif`) : il n'y avait rien à inventer,
    seulement à les relever.

#### Soin

23. **Soothe** (2022) — « The healing coolness of this spell restores **up to
    3 lost Body Points** to the spellcaster or any one monster. » →
    **Apaisement** (`type: soin`, `soin: 3`).
24. **Restore Dread** (2023) — « may be cast only on monsters. It restores **up
    to 6 lost Body Points** to either the spellcaster or any monster **within
    the spellcaster's line of sight**. » → **Restauration de l'Effroi**
    (`type: soin`, `soin: 6`, `ligne_de_vue: true`).

#### Déplacement / évasion

25. **Escape** (2021) — « allows the spellcaster to disappear and instantly
    teleport to a secret destination known only to Zargon. This safe place is
    marked on the quest map. » → **Fuite** (`type: fuite`).
    ⚠ Divergence assumée : nos donjons sont **procéduraux** et ne portent aucun
    refuge marqué. La destination est donc la case libre la **plus éloignée**
    des héros — même intention, seule lecture possible ici. Le nom français
    historique est conservé : la colonne `fuite_dread_utilisee` le porte déjà.
26. **Skate** (2022) — « enables the spellcaster to move quickly through icy
    caverns and corridors. The spellcaster may skate for up to 12 squares and
    may pass through heroes and monsters during movement. The spell lasts only
    one turn. » → ⚠ **NON PORTÉ**. Il faudrait un **mode de déplacement pour un
    monstre** : `franchit_figures` n'existe que sur le héros, et le déplacement
    des monstres est piloté par le moteur, sans buff qui le module.
27. **Ice Wall** (2022) — « creates up to 4 squares of solid ice. These squares
    block movement, but not line of sight. […] Each ice square lasts until the
    spellcaster dies, cancels the spell, or can no longer see the square, or
    until a cumulative total of 5 skulls are rolled in attacks on the ice
    square. » → ⚠ **NON PORTÉ**. Il faudrait un **terrain destructible avec
    compteur** : la couche `chausse_trappes` en est le plus proche parent, mais
    elle n'a ni PV, ni condition d'entretien liée à la vue du lanceur.

#### Réactions du MJ pendant le tour d'un héros

28. **Dispel** (2023) — « This **special** spell may be cast by a Dread
    spellcaster **during a hero's turn**. It is used in an attempt to cancel a
    spell cast by a hero. […] the Dread spellcaster rolls 1 red die and adds
    the result to their Mind Points. Then the hero does the same. If the Dread
    spellcaster's total is higher, the hero's spell is cancelled. » → ⚠ **NON
    PORTÉ**.
29. **Mirror Magic** (2023) — « may be cast by a Dread spellcaster during a
    hero's turn. This enables the spellcaster to **reflect any hero's spell
    back on them**. […] The hero then suffers the effect of the spell that was
    intended for the spellcaster. » → ⚠ **NON PORTÉ**.

    ⚠ Les deux manquent la **même** mécanique, et c'est pour cela qu'elles sont
    groupées : un **monstre qui agit hors de la phase des monstres**. Notre
    `MoteurReactions` est entièrement tourné vers les héros — il pose une offre
    sur un canal privé et attend une réponse de téléphone. Ici c'est le MJ qui
    réagit, sans joueur à consulter : la résolution serait synchrone, dans la
    requête du héros qui lance, mais le point d'entrée n'existe pas. La
    réflexion d'un sort (`Mirror Magic`) est en outre déjà nommée comme dette
    par la doc 16 §9.1 (*Dawnshield*, *Raven's Talon*).

### 4bis.2 — Ce que le portage change au §4

- Le **Trait de Chaos** n'existe pas. Il portait, à lui seul, la « priorité 2 »
  du choix de sort ; il est remplacé par trois cartes réelles — *Ball of
  Flame*, *Channel Dread* et *Lightning Bolt*.
- Les paliers restent notre grille (`sous_boss` / `boss`, un **minimum**), et
  restent de nous : aucune carte ne porte de rang. Ils traduisent la phrase du
  §4 — « les sous-boss lancent déjà les sorts mineurs, le boss final ajoute les
  sorts vilains ».
- Les **usages** restent les nôtres (2 par rencontre pour un sous-boss, 3 pour
  un boss). Les cartes disent « once per quest » par sort pour la plupart des
  porteurs (doc 18) ; nous gardons un budget global, qui produit la même rareté
  sans un compteur par sort et par instance.

### 4bis.3 — Qui lance quoi (répertoires sourcés)

Les répertoires de `config/archetypes_lanceurs.php` ne sont plus inventés : ils
transcrivent les porteurs officiels documentés en doc 18. Un archétype se
déclare **complet** et le **tier** de la créature qui le porte décide de ce
qu'elle en tire (`sorts_dread.palier`, un minimum).

| Archétype | Porteur au catalogue | Source | Sorts |
|---|---|---|---|
| `culte_effroi` | Cultiste du Dread (base) | Dread Cultist, Dread Moon | Feux de l'Effroi, Canaliser l'Effroi |
| `spectre_hurlant` | Spectre (base) | Specter, Dread Moon | Canaliser l'Effroi |
| `garde_magus` | Garde-mage (sous-boss) | Magus Guard, Dread Moon | Boule de Flammes, Tourmente |
| `spectre_effroi` | Ombre du Dread (boss) | Dread Wraith, Dread Moon | Feux de l'Effroi, Canaliser l'Effroi, Frayeur, Invocation de spectres |
| `tisseur_fleau` | Tisseur putride (base) | Blightweaver, Delthrak p. 47 | Canaliser l'Effroi, Étreinte des Ronces |
| `archimage_elfe` | Archimage elfe (boss) | Sinestra, Mage of the Mirror p. 30 | Invocation de loups, Choc Mental, Tempête de feu, Réanimation, Restauration de l'Effroi |
| `horreur_glacee` | Horreur des Glaces (boss) | Frozen Horror p. 37 | Morsure de Froid, Tempête de Glace, Apaisement, Choc Mental |
| `necromancien` | Liche (boss) | *nôtre* | Invocation de morts-vivants, Réanimation, Sommeil, Frayeur, Rouille, Canaliser l'Effroi |
| `maitre_tempetes` | Sorcier des Tempêtes (boss) | *nôtre* | Tempête de feu, Éclair de Chaos, Frayeur, Fuite |
| `chaman_orque` | Chamane Gobelin (sous-boss) | *nôtre* | Commandement, Frayeur, Sommeil, Invocation d'orques, Canaliser l'Effroi |

Les deux gabarits élites génériques — **Champion** (sous-boss) et **Seigneur**
(boss) — gardent une liste brute `monstres.sorts_dread` plutôt qu'un archétype :
ce ne sont pas des sorciers nommés mais des blocs de stats, et leur magie est un
assortiment, pas une identité.

⚠ Un archétype n'existe que si un monstre le **porte**. Un répertoire sans
porteur serait la configuration décorative que ce projet traque partout
ailleurs — `SortsDreadSourcesTest` le vérifie dans les deux sens.

⚠ **L'*Invocation de loups* a désormais son lanceur** (René, 2026-09-04 : « on
devrait créer un boss elfique qui utiliserait Invocation de loups »). Il n'a pas
fallu l'inventer : c'est **Sinestra, l'archemage**, boss final de la quête 9 de
*The Mage of the Mirror* (p. 30) — Move 8 · Attack 4 · Defend 4 · Body 4 ·
**Mind 9**, et son répertoire officiel cite *summon wolves*. Le catalogue porte
le TYPE (« Archimage elfe ») et l'IA l'habille, comme « Magrian » habille
l'Ombre du Dread. La carte dit bien « **GIANT** wolves », et c'est ce que la
donnée nomme — un test le verrouille, parce qu'une créature mal orthographiée
dans une `table_d6` fait silencieusement invoquer « n'importe quel monstre de
base ».

⚠ **Les lanceurs nommés APPARAISSENT enfin** (René, 2026-09-04). Un boss ne peut
entrer dans une quête que comme **rencontre finale**, et
`DemarreurQuete::acheterMonstres()` prend alors le **leader de coût** du palier
quand le gabarit ne nomme personne — or aucun des trois gabarits ne nommait
d'archétype. Le Seigneur (coût 20) fermait donc *toutes* les quêtes, et pas un
seul lanceur nommé n'avait jamais été tiré. C'est la leçon des leviers : le champ
`rencontre_finale.archetype` fonctionnait depuis la 3.8, il n'avait simplement
jamais été rempli.

Le correctif est un **POOL** (`rencontre_finale.archetypes`, une liste), parcouru
par **ROTATION sur (id du groupe + position d'arc)** — et non tiré au hasard. La
distinction est celle que le projet fait déjà entre `salle_artefact` et le deck
de fouille : un boss est un **placement**, il doit donc rester le même si le
groupe recommence la quête ou reprend un instantané, sans quoi « Recommencer »
deviendrait un bouton pour changer d'adversaire jusqu'au plus commode. La
rotation donne de la variété sans hasard : l'adversaire change d'un jalon à
l'autre, et deux groupes ne suivent pas la même succession. Une valeur unique par
gabarit aurait au contraire fait finir toutes les campagnes sur le même visage,
c'est-à-dire le défaut du pool de salles un cran plus haut.

| Gabarit | Palier | Pool |
|---|---|---|
| *Exploration* | — | **aucune rencontre finale** : c'est sa définition, pas un oubli |
| *Sous-boss* | `sous_boss` | `chaman_orque` · `garde_magus` |
| *Confrontation finale* | `boss` | `necromancien` · `maitre_tempetes` · `spectre_effroi` · `horreur_glacee` · `archimage_elfe` |

⚠ Le **Seigneur est DANS la rotation** (René, 2026-09-04) et non plus son
titulaire perpétuel. L'y mettre a demandé de lui donner un `archetype_lanceur` :
le pool se déclare en archétypes, donc un boss qui n'en porte pas ne peut plus
apparaître du tout. Le motif qui l'en tenait à l'écart — « ce n'est pas un
sorcier nommé mais un bloc de stats » — ne valait plus son prix dès lors qu'il
lui coûtait son existence en jeu. ⚠ Le **Champion**, lui, garde sa liste brute
délibérément : il reste le seul porteur en production du repli de
`repertoireSorts()`. Le singulier `archetype` reste lu, pour toute donnée de
gabarit antérieure.

### 4bis.3bis — Qui peut apparaître, et sous quel thème

⚠ **Le pool ne se déclarait qu'en ARCHÉTYPES, et seuls les lanceurs en ont un.**
Sur les 13 sous-boss du bestiaire, **deux** pouvaient apparaître — et les onze
exclus étaient les plus caractéristiques : la régénération du Troll, la double
attaque de l'Ours polaire, le venin et la ponte des créatures de Delthrak. La
rotation avait troqué « toujours le même » contre « deux, et on perd les onze
autres ». `rencontre_finale.creatures` nomme désormais les brutes directement, et
un test exige qu'**aucune créature d'un palier ne soit inatteignable** : l'écarter
doit être un choix écrit, pas un effet de bord de la sélection.

| Palier | Au catalogue | Atteignables (avant → après) |
|---|---|---|
| sous-boss | 13 | 2 → **13** |
| boss | 8 | 6 → **8** |

⚠ **Il n'existait AUCUNE notion de thème** : la génération ne connaissait que
`tier` et `cout`, si bien qu'une quête glacée et une quête de jungle puisaient
dans le même sac. Le thème ne venait que de l'habillage IA — lequel RENOMME ce
qui est déjà là et ne choisit jamais quelle créature apparaît. La donnée
existait pourtant, en **commentaire** : `MonstreSeeder` groupe ses créatures par
boîte depuis le portage de la doc 18. Elle devient la colonne `monstres.boite`.

`DemarreurQuete::themeBestiaire()` fait tourner une boîte **par groupe**, pour
toute la campagne — rotation sur l'id, comme le boss : on ne passe pas de la
banquise à la jungle entre deux portes. ⚠ `null` n'est pas un trou, il vaut
« aucune boîte » : nos propres blocs de stats (Troll, Champion, Seigneur, les
trois sorciers nommés) conviennent à tout thème, ce qui garantit qu'aucun pool
ne se vide.

⚠ **Le thème porte sur la rencontre finale et sur les quelques FORTS, jamais sur
la masse de faibles** — et c'est ainsi que les boîtes officielles sont bâties :
elles ajoutent quelques créatures signature au bestiaire commun, elles ne le
remplacent pas. Filtrer les faibles aurait donné un donjon de Gremlins (la boîte
des glaces n'a qu'une créature de tier `base`) ; ne rien filtrer ne montrait
jamais la signature. Mesuré :

| Thème | Sous-boss tirés |
|---|---|
| `mage_du_miroir` | Ogre, Loup géant |
| `horde_ogre` | Ogre guerrier, Ogre champion |
| `jungles_delthrak` | Singe géant, Rampant putride, Serpent géant |
| `dread_moon` | Garde-mage |

⚠ **`horreur_des_glaces` est DÉSACTIVÉE** (René, 2026-09-04 : « il manque des
règles »). Trois des six sorts de son boss ne sont pas portés — *Ice Wall*,
*Mind Freeze*, *Skate*, qui demandent respectivement du terrain destructible,
des dégâts de Mind et un mode de déplacement pour monstre —, l'étreinte du Yéti
et le vol du Gremlin non plus, et l'équipement de glace était déjà écarté. Le
**thème** et le **boss** sont retirés ; les créatures RESTENT au catalogue comme
blocs de stats, ce que le projet fait déjà pour les traits non portés de
Delthrak. La désactivation est DÉCLARÉE dans
`DemarreurQuete::BOITES_INCOMPLETES`, avec sa raison, et un test exige qu'une
boîte désactivée ne soit jamais proposée comme thème : sans cette entrée, le
contrôle de couverture aurait signalé l'Horreur des Glaces comme une régression.
Écarter du contenu est un choix écrit, pas un oubli.

⚠ Le levier d'intensité est `jeu.rencontres.forts_par_quete` (déjà réglable au
panneau) : c'est lui qui décide combien de créatures signature accompagnent le
fond commun.

### 4bis.4 — Le `cout` d'un boss, mesuré

`cout` n'a **qu'un seul lecteur** : `DemarreurQuete`, où le prix du boss est
retranché du budget de rencontre et où le reste achète l'escorte. Un boss plus
cher, c'est donc simplement **moins de sbires** — rien d'autre.

Classement par « nombre d'attaques de héros pour l'abattre » (faces de combat :
le héros touche sur un crâne, 3/6 ; un monstre ne pare que sur un bouclier noir,
1/6 ; héros de référence à 3 dés d'attaque) :

| Boss | Att | Déf | Body | tours/kill | `cout` |
|---|---|---|---|---|---|
| Seigneur ogre | 6 | 6 | 10 | 20,0 | 22 |
| **Seigneur** | 5 | 5 | 10 | **15,0** | **20** |
| Liche | 3 | 4 | 6 | 7,2 | 18 |
| Sorcier des Tempêtes | 3 | 3 | 5 | 5,0 | 17 |
| Ombre du Dread | 6 | 4 | 5 | **∞** | 17 |
| Archimage elfe | 4 | 4 | 4 | 4,8 | 17 |
| Horreur des Glaces | 5 | 4 | 6 | 7,2 | 16 |
| Ogre commandant | 6 | 5 | 6 | 9,0 | 15 |

**Verdict : 20 se tient.** Le Seigneur tombe exactement où son prix l'annonce,
entre le Seigneur ogre (22) et la Liche (18), et l'ordre coût ↔ endurance est
monotone sur tout le palier — **à une exception près**.

⚠ **L'ÉTHÉRÉ se paie plus cher, et c'est une règle, pas un chiffre à la main**
(René, 2026-09-04). Une créature éthérée ne se blesse à l'arme que sur un
**bouclier noir** (1/6) au lieu d'un crâne (3/6), pendant qu'elle pare toujours
sur 1/6 : les dégâts nets valent `(attaque − défense)/6` au lieu de
`(3·attaque − défense)/6`. Mesurée à 5 dés d'attaque, l'Ombre du Dread tient
**six fois** plus longtemps qu'un bloc identique non éthéré.

`DemarreurQuete::RATIO_COUT_ETHERE` vaut **×2** — pas ×6, et c'est délibéré : le
livret excepte « sort ou artefact », que le moteur applique, donc un groupe qui a
de la magie la traverse comme n'importe quel monstre. ×6 lui donnerait le prix
d'une rencontre entière et la laisserait seule sur la carte. Valeur de départ de
playtest, comme tous les `cout` du bestiaire — qui n'existent sur aucun livret.

⚠ **`coutEffectif()` est le seul point de passage de ce qu'on PAIE, et il ne
touche PAS au CLASSEMENT** — distinction trouvée en mesurant, pas en réfléchissant.
Les « forts » s'achètent du plus cher au moins cher et le leader de coût ferme la
rencontre : majorer le rang aurait donc **promu** les éthérées au lieu de les
rationner. Le Spectre serait passé devant tous les autres forts et se serait
invité dans presque chaque quête — l'inverse exact du but. On trie donc sur
`cout` brut (ce que la créature **vaut**) et on débite `coutEffectif()` (ce
qu'elle **coûte**).

⚠ Il vaut à **tous les paliers** : le **Spectre** (base, éthéré) passe de 5 à 10.
Ne majorer que le boss aurait laissé le même défaut un cran plus bas.

Mesure après coup — nombre d'attaques pour abattre l'Ombre du Dread, à 3 de
défense :

| dés d'attaque | 3 | 4 | 5 | 6 |
|---|---|---|---|---|
| tours | ∞ | 30,0 | 15,0 | 10,0 |

À 5 dés elle demande 15 attaques, exactement la fourchette du Seigneur — et son
coût effectif (34) dit qu'elle vaut plus que lui (20). Elle arrive donc avec une
escorte plus courte : à budget 70, sept sbires au lieu de la douzaine qu'un boss
ordinaire laisserait payer.

⚠ Le **Frozen Horror** est le seul porteur dont la carte cite six sorts dont
**trois ne sont pas portés** (*Ice Wall*, *Mind Freeze*, *Skate*) : son
répertoire ne retient donc que les trois autres, plus *Choc Mental*. Un
répertoire qui nommerait un sort absent du catalogue serait silencieusement
vide — `sortsDisponibles()` filtre sur ce qui existe.
---

## 5. Comportement

- **Scripté simple** (C2) : cible le plus proche / le plus faible ; un boss déclenche sa capacité quand elle est disponible.
- Le **moteur décide et résout** ; le MJ IA **narre** les actions sans les choisir mécaniquement.

### Monstres à DISTANCE — repli avant tir (René, 2026-08-23)

⚠ **Décision de portage, pas une règle** : aucun livret ne décrit d'IA, Zargon
joue ses monstres à vue. Ce qui la motive est en revanche dans les fiches — le
**Gobelin archer** frappe à 1 dé au contact pour 2 à distance, l'**Archer
squelette** 1 pour 2, l'**Archer elfe** **1 pour 4**. Cette parenthèse
« *Attack 4 (1 if adjacent)* » n'a de sens que si la créature cherche à ne pas
être au contact.

Or le moteur l'y poussait dans les deux cas : collé, il frappait en mêlée sans
jamais décrocher ; sans ligne de mire, il visait une case **adjacente** au héros
comme un corps-à-corps. Il se privait lui-même de son arme.

Désormais, avant de tirer :

1. **Au contact** → il recule le plus loin possible **dans sa salle**, sur une
   case qui garde une ligne de mire et ne le remet pas au contact d'un autre
   héros, puis il tire.
2. **Sans ligne de mire** → il gagne une position de tir, la plus éloignée
   possible, au lieu de refermer la distance.
3. **Encerclé, ou aucune case tirante hors contact** → il frappe sur place.
   Refus silencieux, jamais un tour perdu.

⚠ Le recul est **borné à la salle courante**, et c'est le garde-fou central :
sans borne, « le plus loin possible » fait de l'archer un kiteur qu'un héros de
mêlée ne rattrape jamais dans un couloir, et la quête s'enlise. En couloir (hors
de toute salle), il ne recule donc pas.

Le repli **ne consomme pas l'action** — bouger puis tirer, exactement comme un
corps-à-corps « s'approche PUIS frappe ». Il porte sa **propre ligne de journal**
(`repli_tireur`) et passe par `mouvementsAnime`, donc la table le voit glisser
au lieu de se téléporter : un effet automatique que rien n'annonce est
injouable. Et `portee` est désormais journalisé avec l'attaque, sans quoi le fil
de combat ne distinguait pas un tir d'un coup de mêlée.

---

## 6. Habillage par l'IA (Q6)

- Un même bloc sert plusieurs fictions : le *gobelin* peut devenir homme-rat, cultiste, gobelin selon le **thème** de la campagne.
- L'IA fixe **nom, description, ambiance** ; les **stats restent celles du bloc**.

---

## 7. Intégration

- **Combat** : Attaque/Défense en dés, PV de Body comme vie (docs Combat/Personnages).
- **Sorts** : PV de Mind = résistance mentale ; **Mind 0 = immunité** (doc Sorts, S2).
- **Quêtes** : sous-boss et boss final = jalons de l'arc (doc Quêtes §4).
- **Market** : monstres et boss laissent du **butin** (or, objets, butin unique de boss) selon le gabarit.

---

## 8. Périmètre

- **MVP** : les 8 monstres de base, gabarits de sous-boss / boss final, bibliothèque de capacités, **sorts de Dread sur les sous-boss et le boss final**.
- **Phase 2** : bestiaire élargi, **lanceurs de Dread autonomes** (acolytes, sorciers de rang), capacités avancées, boss à plusieurs phases.

---

## 9. Questions ouvertes à trancher

1. **Échelonnage des boss** : stats exactes de sous-boss / boss final selon la longueur de campagne.
2. **Taille de la bibliothèque** de capacités et de sorts de Dread au MVP.
3. **Déplacement des monstres** : on confirme le **fixe** (vs base + 1d6 des héros) ?
4. **Butin** : tables de butin par monstre, ou butin piloté par le gabarit de quête ?
5. **Usages de Dread par rencontre** : combien, et comment équilibrer face au Mind des héros ?
