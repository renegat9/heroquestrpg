# Plan — Définir entièrement l'extension *Prophecy of Telor*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> iront dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §Prophecy of Telor.
>
> **Source** : livret de quêtes officiel **G0052** (© 2023 Hasbro, 36 pages
> imprimées / 19 pages PDF), téléchargé le 2026-10-04 depuis
> `instructions.hasbro.com` (page produit *avalon-hill-heroquest-prophecy-of-
> telor-quest-pack*). Texte extrait page par page (`texte/G0052_en-us/`),
> **les quatre blocs de stats (Sorcier du Dread ×2, Fellmarak ×2) relus sur
> le rendu PNG** des pages imprimées 21, 25, 31 et 33 — colonnes bien
> alignées, aucun désaccord avec le texte extrait. Les numéros ci-dessous
> sont les **pages imprimées**.

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : les règles de la boîte (p. 5), la Boutique de
l'Alchimiste (p. 2), 13 quêtes (p. 7-33, la boîte la plus longue en nombre
de quêtes rencontrée jusqu'ici), la liste des objets réutilisés du jeu de
base (p. 34-35).

**Il ne porte PAS de tableau de monstres** (aucune page « Monsters of
Telor ») : tous les monstres génériques viennent du bestiaire de base, et
les deux seuls adversaires chiffrés (Sorcier du Dread, Fellmarak) ont leurs
stats **inline dans le texte de quête**, pas sur une carte à part — donc
rien de manquant côté stats, contrairement à ce qu'un tableau absent
suggérerait ailleurs.

**Contenu physique (déduit du texte, aucune page « components » dédiée)** :
figurines de Sorcier du Dread et de Fellmarak, le Talisman du Savoir (déjà
une carte du jeu de base), des parchemins de sort spécifiques à cette boîte
(trois modifiés à usages multiples, un normal), et **la carte de héros
Warlock** — absente du livret de quêtes (qui dit même « aucune nouvelle
classe », exact pour ce document précis) mais publiée avec cette boîte au
sein du **HasLab Mythic Tier** (`reference/18_extensions.md` §HasLab Mythic
Tier, photo fournie par René le 2026-08-11). Voir §1.

## 1. Déjà en place

| Élément du livret | Chez nous |
|---|---|
| **Le Warlock est déjà jouable** (M2 D2 B4 Mi5, baguette de départ, 3 sorts transcrits, règles d'armure du magicien) — sa carte est publiée avec **cette** boîte | `ClasseHerosSeeder`, Mythic Tier, sourcé 2026-08-11 (`reference/01_personnages.md` §4bis-4quater) |
| **KO non létal à 0 Body Point, sans mourir** (« they do not die but instead fall unconscious ») — la règle que le livret présente comme spécifique au porteur du talisman | **DÉJÀ le comportement universel de TOUT héros** : `tombe` (0 PV Body, relevable) est un état distinct de la mort depuis l'origine (`docs/regles/vocabulaires-effets.md`, `JournalCombat::chute`). Un héros `tombe` ne peut plus agir (`MenuMoteur` l'exclut), peut être relevé par un allié (potion/sort, réaction `soin_urgence`), et la quête n'échoue (`verdictDeChute()` → `tpk`) QUE quand le DERNIER héros debout tombe sans qu'aucune offre de sauvetage n'aboutisse. **Rien à construire ici** — voir correction §2.3 |
| 4 artefacts réutilisés du jeu de base : *Talisman of Lore*, *Elixir of Life*, *Ring of Fortitude*, *Rod of Telekinesis* | `ObjetSeeder` : **Talisman du Savoir** (`bonus_pv_mind_max: 1`), **Élixir de Vie**, **Anneau de Vigueur** (`bonus_pv_body_max: 1`), **Sceptre de Télékinésie** — les quatre existent déjà, prix et effets conformes |
| Toute la Boutique de l'Alchimiste (p. 2) : *Potion of Restoration* (300po), *Potion of Healing* (500po), *Potion of Lesser Healing* (200po), *Potion of Battle* (200po), *Potion of Magic* (400po) | `ObjetSeeder` : **Potion de restauration** (300), **Potion de guérison** (500), **Potion de soin mineur** (200), **Potion de bataille** (200), **Potion de magie** (400) — prix et effets identiques, rien à ajouter |
| *Rock Skin spell scroll* (q. 8) et *Courage spell scroll* (cité en passant p. 18 §Artifact Reference, absent des trouvailles mais le sort existe) | **Peau de Pierre** et **Courage** sont déjà deux sorts héros du catalogue (`SortSeeder`, éléments terre/feu) — le parchemin trouvé en jeu 8.F n'a besoin d'aucun nouveau sort, juste d'une instance d'objet |
| *Heal Body*, *Lightning Bolt*, *Water of Healing* (noms de carte) | Déjà portés comme sorts héros : **Soin du Corps**, **Éclair**, **Eau de Guérison** (`SortSeeder`), et déjà reliés carte→sort dans `config/cartes.php`. Ce qui manque VRAIMENT : voir lot C |
| *Lightning Bolt*, *Firestorm*, *Ball of Flame*, *Cloud of Dread*, *Command*, *Fear*, *Rust*, *Summon Orcs*, *Summon Undead*, *Escape* (sorts de Dread cités dans le texte) | **Les DIX existent déjà** dans `SortDreadSeeder` : Éclair de Chaos, Tempête de feu, Boule de Flammes, Nuée d'Effroi, Commandement, Frayeur, Rouille, Invocation d'orques, Invocation de morts-vivants, Fuite. **Aucun nouveau sort de Dread à porter pour cette boîte** — seulement de nouveaux PORTEURS (lot D, E) |
| Fellmarak déjà cité comme SOURCE d'une règle en vigueur | `config/archetypes_lanceurs.php`, commentaire de l'archétype `necromancien` : « Fellmarak le Roi Sorcier (*Firestorm, Rust, Command, Fear, Cloud of Dread*), doc 18 » — la Rouille lui doit sa place au catalogue depuis le 2026-09-04, avant même que cette boîte ait son propre plan |
| Mécanique de « phases » pour un boss increvable qui change de stats à 0 Body | **Pas encore chez nous** — mais déjà PLANIFIÉE : `docs/plan-ogre-horde.md` Lot C (mot-clé `monstres.phases`, pour Gruzbella/Spawn of the Pit). Fellmarak en est un second porteur, voir lot D |

## 2. Corrections et compléments à `reference/18_extensions.md`

La section Prophecy of Telor (lignes 1029-1155) est globalement fiable —
aucune invention trouvée au recoupement avec le texte intégral. Trois
points à corriger ou enrichir :

1. **Le bullet « KO non létal spécifique à un porteur » surestime le
   coût de portage.** Il dit : « changerait une règle fondamentale du
   moteur (mort à 0 Body) pour un seul héros marqué ». **Il n'existe pas de
   règle « mort à 0 Body »** dans le moteur actuel — `tombe` (recevable,
   non létal) est déjà le sort de TOUT héros à 0 Body, documenté en toutes
   lettres dans `docs/regles/vocabulaires-effets.md` (« Un héros à 0 Mind
   ne TOMBE plus [...] comme à 0 Body »). Le texte sous-entend une
   fausseté sur l'état actuel du moteur ; à corriger avant que quelqu'un
   bâtisse un second mécanisme à côté de celui qui existe déjà.
2. **Omission : le parchemin *Rock Skin* (quête 8, trouvaille F) n'est pas
   mentionné** dans la section « Nouveaux objets, artefacts, sorts », alors
   que les trois parchemins à usages multiples (Heal Body, Lightning Bolt,
   Water of Healing) le sont. *Rock Skin* est un parchemin ORDINAIRE
   (un seul usage), à ajouter à la liste par souci de complétude — il ne
   change rien au lot de travail, le sort existe déjà.
3. **Omission mineure : « Monsters increvables [...] (Zargon's Flame) »**
   gagnerait à préciser la source exacte des trois sorts retirés du deck
   pour la pioche de Fellmarak final (*Summon Orcs*, *Summon Undead*) et
   la condition de mort instantanée sur *Escape* — c'est un mécanisme à
   part entière (lot D), pas seulement une note sur les monstres invoqués.

## 3. Les règles à porter, par lots

### Lot A — Corrections de `reference/18` (ci-dessus). Aucun code.

### Lot B — Le Talisman du Savoir maudit

| Élément | Règle (citation) | Chez nous |
|---|---|---|
| **Porteur désigné, retrait impossible** | « One of the heroes must carry the Talisman of Lore [...] The bearer cannot remove the talisman. » (p. 5) ; tenter : « You feel a great heat [...] Lose 1 Body Point » (p. 5, 18) | Nouveau drapeau sur l'INSTANCE (pas la ligne catalogue, voir Q1) empêchant l'option « retirer » au menu d'équipement — conforme à la règle maison « le menu n'offre pas ce que le résolveur refuse » : on **n'expose pas** l'option punitive, on la retire (divergence assumée, voir Q2) |
| **Pression automatique à chaque DÉBUT de tour du porteur** | « At the beginning of each of the bearer's turns, they roll 2 red dice. If the total roll result is less than the bearer's current Mind Points, they [...] lose 1 Body Point. The bearer adds 1 point [...] for each hero in the same room or corridor [...], and 2 points [...] for each hero adjacent » (p. 18) | **Premier déclencheur de ce type au moteur** : un jet AUTOMATIQUE, sans action du joueur, à l'ouverture du tour d'un porteur d'objet précis (voir Q5). Lecture possible depuis le même seam que `verifierRocheMortelle()`/le début de tour, mais c'est une capacité NOUVELLE, pas une réutilisation |
| **KO non létal à 0 Body** | déjà couvert — aucun code (§1) |
| **Sorts Dread empruntés par le porteur** | « The bearer of the talisman knows two Dread spells: Lightning Bolt and Firestorm. Each time the hero casts one of these spells, they gain 1 Mind Point. Each spell may only be cast once per quest. » (p. 18) | Un héros accède temporairement à un répertoire hors classe (Éclair de Chaos + Tempête de feu, tous deux déjà au catalogue Dread) avec un gain de Mind au lancer. **Même manque que le Lot D d'Ogre Horde** (« le moteur de sorts suppose un répertoire fixe par classe ») — à co-concevoir plutôt qu'à dupliquer |
| **Défaite alternative narrée (« Rise of Fellmarak »)** | « If the bearer of the talisman is unconscious and all other heroes are dead, the quest is over » + texte dédié (p. 5, 18) | **Mécaniquement DÉJÀ couvert** : c'est exactement la condition de TPK existante (`verdictDeChute()` → `tpk` quand le dernier héros debout tombe). Ne manque qu'une **narration alternative** quand le porteur du talisman maudit est l'un des héros tombés — pur habillage IA, aucun nouveau mécanisme. Le payload sait déjà qui porte quoi |

### Lot C — Parchemins à usages multiples

| Carte | Citation | Usages |
|---|---|---|
| *Heal Body* (q. 11, trouvaille D) | « allowing it to be cast three times before the scroll crumbles to dust » | 3 |
| *Lightning Bolt* (q. 11, trouvaille F) | « modified to be cast three times before the scroll crumbles to dust » | 3 |
| *Water of Healing* (q. 13, trouvaille D) | « modified to be cast twice before crumbling to dust » | 2 |

Les trois sorts (Soin du Corps, Éclair, Eau de Guérison) existent déjà.
**Ce qui manque** : tout parchemin/objet à sort est aujourd'hui à usage
unique — aucune colonne de compteur. Nouvelle colonne additive
(`objets.usages_restants`, nullable, `null` = comportement actuel
inchangé), lue au moment de consommer le parchemin, décrémentée au lieu de
détruire l'objet tant qu'elle n'atteint pas 0. Trois instances d'objet
nommées (pas trois nouvelles lignes catalogue) portent la valeur de départ.

### Lot D — Fellmarak, le Roi Sorcier (boss à deux formes, p. 31, 33)

**Dépend du Lot C d'`docs/plan-ogre-horde.md`** (mot-clé `monstres.phases`) —
à ne construire qu'après, ou en même temps en coordination explicite,
plutôt que d'inventer un second mécanisme de changement de stats.

| Phase | Stats M/A/D/B/Mi | Règle propre |
|---|---|---|
| Quête 12 (« The Rise of Fellmarak », p. 31) | 8/5/4/6/6 | Connaît *Firestorm, Rust, Command, Fear, Cloud of Dread* — **les cinq existent déjà** (Tempête de feu, Rouille, Commandement, Frayeur, Nuée d'Effroi). « Fellmarak cannot be killed; when he reaches 0 Body Points, his crown melts [...] he screams and flees through [a secret door]. » → à 0 Body, PAS de passage à la phase 2 ici : il FUIT, la quête continue sans lui |
| Quête 13 (« Zargon's Flame », p. 33) | 8/5/4/**0**/6 | Enveloppé par « Zargon's Flame » (voir mécanique dédiée ci-dessous). « Remove the Dread spells Summon Orcs and Summon Undead from the Dread spell deck. On Fellmarak's turn, shuffle and draw one random Dread spell to cast. If Escape is drawn, the sorcerer is immediately destroyed by blazing fire. » |

**Mécanique « Zargon's Flame »** (p. 33, s'applique à Fellmarak phase 2 ET
aux monstres invoqués de cette seule quête) : une créature sans Body
Points ; à chaque coup porté, le MJ annonce secrètement 2 valeurs 1-6 et
lance 1 dé rouge — survit si le résultat correspond, sinon meurt ; *Eau de
Guérison* lancée dessus la prive de défense et réduit le MJ à une seule
valeur protectrice. **Nouvelle capacité nommée** (`mort_sur_tirage` ou
équivalent), scopée à cette seule rencontre — pas un mot-clé générique
candidat ailleurs dans le bestiaire actuel (voir Q6).

**Fellmarak quête 13 n'a pas de « mort normale »** : sa fin de vie est
conditionnée au tirage de *Fuite* (déjà au catalogue Dread) dans une pioche
réduite — un second mécanisme, distinct des « phases » du Lot C d'Ogre
Horde, à documenter comme tel plutôt que forcé dans le même mot-clé.

### Lot E — Sorcier du Dread (nouveau monstre générique, p. 21, 25)

| Apparition | Stats M/A/D/B/Mi | Sorts connus |
|---|---|---|
| Quête 7, « The Archives at Arborenis » (p. 21) | 8/4/4/3/4 | *Ball of Flame, Cloud of Dread, Command* |
| Quête 9, « Halls of the High Mages » (p. 25) | 8/4/4/3/4 | *Tempest, Fear* |

Même stat-line dans les deux apparitions (confirmé sur le rendu PNG) : un
**seul** nouveau `nom_base` candidat (« Sorcier du Dread »), tier à
déterminer (voir Q3 — Body 3/Mind 4 est un profil de sous-boss modeste),
avec un **archétype unique** regroupant les cinq sorts cités dans les deux
quêtes (Boule de Flammes, Nuée d'Effroi, Commandement, Tourmente, Frayeur —
**les cinq existent déjà**), filtré par palier à l'exécution comme tous les
autres archétypes. ⚠ *Nuée d'Effroi* et *Commandement* sont déclarés
palier `boss` dans `SortDreadSeeder` : si « Sorcier du Dread » est rangé
tier `sous_boss`, le filtre les retirerait silencieusement de son
répertoire en jeu — à trancher explicitement (Q3), pas en laissant le
filtre réduire la carte sans le dire.

**Gor-Lethim Kar** (démon de feu, quête 3, p. 11) — « It knows the Dread
spell Firestorm », **sans aucune stat chiffrée**. ⚠ Contrairement à
Fellmarak et au Sorcier du Dread, ce nom n'a **pas** de bloc de stats
inline dans le texte — rien à porter sans deviner. **Laissé de côté**, pas
de ligne catalogue inventée (règle « jamais une valeur non sourcée ») ; une
photo d'une éventuelle carte de monstre réglerait la question (§5).

### Lot F — Ce que les 13 quêtes apportent au générateur

- **Fouille garantissant un vrai trésor**, motif RÉCURRENT dans cette boîte
  (q. 7 salle E : « If they draw a wandering monster or hazard card, it
  does not take effect and is discarded. The hero continues drawing until
  they draw a card that isn't [one of those] ») : même mécanique que celle
  déjà recensée côté Ogre Horde (`reference/18_extensions.md`) — un filtre
  de retirage sur le deck de fouille, contextuel à une salle. Deux boîtes
  indépendantes documentent le même besoin : bon candidat pour un lot
  commun plutôt que deux implémentations.
- **Salle anti-magie** (quête 10, Arena of Misildia, p. 27 : « The whole
  arena is built with Kertz stone. No magic can exist within its walls
  [...] No spells, artifacts, or equipment may be used in the arena ») —
  une salle qui désactive sorts, artefacts ET équipement actif. ⚠ Plus
  large qu'un simple « bloque les sorts » : aucun mot-clé de terrain
  actuel ne couvre « équipement actif désactivé ». Candidat de terrain
  nouveau si on veut généraliser, ou salle scriptée isolée si on ne le
  fait pas (à trancher, faible urgence — une seule quête l'utilise et
  nous ne rejouons aucune quête telle quelle).
- **Tour à étages répétée** (quête 3, « The Great Stairwell », 12 niveaux
  sur une SEULE salle réutilisée, vidée et repeuplée à chaque étage) —
  structure intéressante pour un gabarit « ascension » mais très éloignée
  du modèle actuel (une quête = une carte assemblée une fois). Écarté pour
  l'instant par manque de point d'ancrage, pas par oubli.
- **Transformation forcée réversible de groupe** (quête 5 : tous les héros
  sauf le porteur changés en Orcs, 2 Body Points plafond, sorts interdits,
  jusqu'à un second rituel qui annule l'effet) — mécanique scénarisée
  entière (changement de stats ET de figurine ET de règles pour plusieurs
  héros simultanément, avec restauration automatique). **Écartée** : rien
  d'équivalent n'existe au moteur, le coût de construction dépasse très
  largement ce qu'une seule quête — jamais rejouée — justifie. Si René veut
  une mécanique de transformation générale plus tard, elle mérite son
  propre plan plutôt que de naître ici comme sous-produit.
- **PNJ combattant sous les stats d'un autre monstre** (Gawr, quête 11 :
  un gobelin allié utilise les stats de Gargouille) — négligeable, déjà
  couvert par la substitution de profil (alliés dressés sur un autre
  `nom_base`), sans rien à construire.

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | Le Talisman du Savoir maudit réutilise-t-il la MÊME ligne catalogue que le Talisman du Savoir actuel (+1 Mind max, sans malédiction), ou une variante dédiée ? | **Variante dédiée**, réservée à un arc de campagne Telor — sinon chaque campagne qui tire ce talisman en butin normal hérite d'une malédiction jamais demandée |
| **Q2** | Le retrait impossible : le menu n'offre simplement JAMAIS l'option (cohérent avec la règle « le menu n'offre pas ce que le résolveur refuse »), plutôt que l'exposer et punir d'1 PV comme le livret ? | **Oui, ne pas l'offrir** — déjà la norme du projet partout ailleurs, et ça rend la pénalité du livret inatteignable (divergence assumée, à nommer) |
| **Q3** | « Sorcier du Dread » : quel tier (il a Body 3/Mind 4, profil modeste, mais un répertoire qui inclut deux sorts palier `boss`) ? Fellmarak et lui deviennent-ils boss/sous-boss du thème `prophecy_telor` ? | Tier `sous_boss` pour le Sorcier du Dread avec un répertoire EXPLICITEMENT limité aux 3 sorts `base`/`sous_boss` (Boule de Flammes, Tourmente, Frayeur), les 2 sorts `boss` (Nuée d'Effroi, Commandement) réservés si on le promeut un jour ; Fellmarak **boss** de la boîte (seul boss chiffré de tout le livret) |
| **Q4** | Gor-Lethim Kar (démon sans stats) : laissé de côté jusqu'à une photo de carte, ou reskin provisoire d'un monstre existant ? | **Laissé de côté** — aucune valeur à inventer, la boîte tourne très bien sans lui (un seul passage, quête 3) |
| **Q5** | La pression automatique de début de tour (jet SANS action du joueur, déclenché par le port d'un objet précis) : un point d'ancrage générique à construire proprement pour de futures malédictions, ou un mécanisme isolé pour ce seul talisman ? | Construire le seam proprement — c'est la première capacité « automatique, par tour, liée à un objet porté » du moteur, et ce motif reviendra |
| **Q6** | La mort de Fellmarak (phase 2) par tirage aléatoire d'un sort Dread dans une pioche réduite : une capacité nommée scopée à lui seul, ou un mécanisme à généraliser pour d'autres « boss increvables sauf hasard » futurs ? | Capacité nommée, scopée — rien d'autre dans le bestiaire actuel n'a ce besoin, en faire un mot-clé générique serait prématuré |

## 5. Sources à demander (photos des cartes)

Contrairement aux autres boîtes portées jusqu'ici, Telor a **très peu** de
dette photo — presque tout est sourcé inline dans le texte de quête :
- la carte du **Warlock** publiée avec CETTE boîte, pour vérifier qu'elle
  ne diverge pas de la fiche Mythic Tier déjà transcrite (2026-08-11) ;
- une éventuelle carte de monstre pour **Gor-Lethim Kar** (démon de feu,
  quête 3), seul nom cité sans aucun chiffre — si elle existe physiquement
  dans cette boîte plutôt que d'être un monstre purement scénarisé sans
  figurine dédiée.

Rien d'autre ne bloque : les lots B à F sont sourcés entièrement par le
livret.

## 6. Ordre proposé

1. **Lot A** : corriger `reference/18` (KO non létal déjà en place, ajout
   Rock Skin, précision Zargon's Flame). Aucun code.
2. **Lot C** (parchemins à usages multiples) : petit, autonome, et
   débloque trois trouvailles de loot sans dépendance.
3. **Lot B** (Talisman maudit) : le cœur narratif de la boîte, et le
   PREMIER cas de déclencheur automatique par tour — vaut d'être fait
   avec soin avant que d'autres boîtes en aient besoin (Q5).
4. **Lot E** (Sorcier du Dread) : petit, tous les sorts déjà au catalogue,
   seulement une nouvelle ligne `monstres` + un archétype.
5. **Lot D** (Fellmarak) : après le Lot C d'Ogre Horde (`phases`) — à
   coordonner plutôt qu'à dupliquer le mot-clé.
6. **Lot F** (gabarits) selon l'envie — la fouille garantie est le seul
   morceau à fort rendement (mutualisable avec Ogre Horde), le reste est
   soit négligeable soit délibérément écarté.

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN
JEU → données**, registres testés dans les deux sens, contrat d'API
d'abord, effets automatiques annoncés. Pest sur une **copie sqlite
jetable** ; `sauvegarder.sh` avant toute migration ; aucune purge de
`groupes`/`personnages`/`joueurs` ; redémarrer `queue` et `queue-jeu` après
le PHP. Une campagne d'agents (`campagne-agents`) sur le thème
`prophecy_telor` clôt les lots B à E.
