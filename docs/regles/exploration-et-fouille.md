# Exploration, fouille et fin de quête

> Extrait de `CLAUDE.md` le 2026-09-06, **verbatim**. Ce sont les **règles en
> vigueur** — pas un document daté comme `docs/plan-*.md` / `docs/verdict-*.md`.
> Index : `CLAUDE.md` §« Règles établies ».

**Chests and the supply crate are searched AT CONTACT, never by searching the room** (René, 2026-10-02: « Je veux que la recherche de coffre ou de caisse se fassent seulement quand on est adjacent et non quand on cherche la salle »). "Fouiller — trésor" draws from the deck and nothing else; the designated chest's reward (artefact, gold, potion — `DeckFouille::carteCoffre()`) is taken by "Fouiller : Coffre" on a chest **of that room**, once for the group, and replaces that chest's ordinary table — the quest's chest IS that chest. The crate gives its 4 potions to the first hero who opens it, then is empty. ⚠ The opened state could no longer be **derived** from `tresors_fouilles` (a room searched no longer means a chest opened): it has its own column, `quetes.coffres_ouverts`, saved in snapshots, and `objectifAccompli('atteindre_et_recuperer')` reads it. ⚠ One fallback, deliberately kept: a designated room with **no physical chest at all** (furniture placement can refuse a piece to keep the floor playable) still pays its chest on the room search — otherwise "atteindre et récupérer" could become impossible. Older paragraphs below that speak of the room search paying the chest describe the rule before this date.

⚠ **Les « coffres renforcés » de Wizards of Morcar (livret p. 6, lot G, 2026-10-06) sont DÉJÀ la règle ci-dessus — aucun code neuf.** « Contents of chests are not found until the first time a hero **adjacent** to that chest searches the room. Heroes who are not adjacent draw from the treasure deck instead » décrit EXACTEMENT ce que fait ce projet depuis le 2026-10-02, pour TOUS les coffres, pas seulement ceux de cette boîte : adjacent → `MoteurMobilier::fouillablesAdjacents()` paie le meuble ; pas adjacent → « Fouiller — trésor » pioche le deck ordinaire. Les deux chemins coexistaient déjà, ils n'ont eu besoin d'aucune case Morcar-spécifique.

**Wizards of Morcar ajoute 8 cartes au deck de trésor** (livret §8, lot F, 2026-10-06), thème seul (`DeckFouille::cartesMorcar()`, `bestiaire?->contient('wizards_of_morcar')`) : Magical Trap (×2, référence le piège de sol « Piège d'embrasement » par son nom, résolu SANS défense sur le fouilleur seul — divergence nommée, la salle entière reste le privilège de la version posée sur la grille), Poison (×1, 1 dé de combat), et cinq potions de la boîte. « Nothing! » n'a aucune ligne dédiée : c'est déjà l'issue `rien` du deck.

**Searching & artefacts.** "Fouiller — trésor" draws a **search card** from a per-quest deck (`quetes.deck_fouille`, built at quest start from `gabarits_quete.structure.deck_fouille`, drawn **without replacement**). The composition mirrors the **board game's treasure deck**: 2 gems (35 gp), 2×25, 2×15, 2 jewels (50 gp), 2 pit traps, 2 arrow traps, 3 healing potions, one each of heroism/strength/defence, and **6 wandering monsters** — the commonest card by far, and they have **no cap**: since every card returns under the deck, a budget would have turned the deck's most frequent card into a blank. Each quest always summons the *same* creature (cheapest base-tier), the board's "quest wandering monster". Issues: `tresor` · `potion` · `artefact` · `errant` · `piege` · `rien`. Cards go **back under the deck** after being drawn, so it cycles instead of running out — with one search per hero per room, a 6-room dungeon at 4 players yields up to 24 draws, exactly the deck size. **One search per hero per room** (`Quete::aFouille()`, entries stored as `"{salle}:{personnage}"`): the first searcher no longer closes the room for everyone, each hero draws their own card as on the board. Loot goes to the **searcher** — gold to the common purse. Two healing items on purpose: the **Fiole de soin** found in the deck heals **1d6** (`soin_pv_body_de`, `unique` so it never reaches a stall), while the **Potion de soin** bought at market heals a fixed amount. A trap card **ends the turn** (`a_joue`), and the **Potion d'héroïsme** grants a *second attack* this turn (`etat.attaque_supplementaire`, same pattern as the magicien's Réserve arcanique) — not extra dice, since attack comes from the weapon here. Each card is self-contained (`{issue, or?, objet_id?}`), so drawing consumes **no die**. The deck is **reshuffled at every build AND at every snapshot restore** (`random_int` seed — never derived from the group, René's call 2026-08-05): it used to be seeded on `crc32("{identifiant}:{positionArc}:fouille")`, so a "Recommencer la quête" or a post-TPK reprise replayed the *same draw in the same order* and handed the group an ordered list of its own treasures, traps and wandering monsters. Restoring keeps the **composition** (the deck cycles, no card is ever lost) and re-rolls the **order**; `salle_artefact` / `salles_coffre` / `artefact_objet_id` stay pinned — those are map-bound *placements*, not draws, and re-rolling them would move the chest under the party's feet or grant a second unique weapon. Separately, one designated room — the **deepest** in the corridor tree (`quetes.salle_artefact`) — holds **at most one** `unique` weapon (`quetes.artefact_objet_id`); it consumes no card, and the hero who searches it gets the item. No unique weapon left (uniqueness is **per group**) → the chest pays `or_coffre` instead. Artefacts are **never** purchasable, sellable or forgeable. A full bag does **not** block the reward: the item is handed over in overflow with `sac_deborde: true` (refusing it would lose it forever), and `/moi` exposes `equipement.capacite`/`occupation` so the controller can show it.

**Retreating is now possible — and it is the only way out of a losing fight** (`VoteGroupe::TYPE_RETRAITE`, René's call 2026-08-21). `quitter_donjon` only ever said "we're done, let's go home": it is offered solely once the objective is met or the dungeon emptied. A party that was *losing* could therefore neither win nor leave — seen in a real campaign with two heroes down, two fragile survivors and the boss standing: the only mechanical exit was to fall entirely. `battre_en_retraite` has **no condition at all**, and that is the point — retreating must stay possible at the worst moment, or it is not a retreat.

It opens **one vote with three outcomes** rather than two votes, because retreating only makes sense if you know where to: `recommencer` restores the quest's opening snapshot (`Sauvegarde::redemarrerQuete`), `arreter` closes the campaign (`ClotureCampagne::arreterImmediatement`) — both already existed, but only on the narrator's emergency panel. ⚠ **Relative majority, and ANY tie continues**, including a tie between the two destructive outcomes: they erase or end everyone's game, so a minority must never impose them. ⚠ **Everything is said before anything is done** — narration *and* journal precede the action, because `arreterImmediatement()` purges the campaign: narrate after and there is no group left to journal against (the victory has the same trap for a different reason). A failed retreat (no opening snapshot) is caught and the game simply continues. ⚠ Since it has no gate, `recommencer` doubles as an unlimited undo — that is the accepted cost of the choice, not an oversight.

**Victory is the gabarit's objective.** `Quete::objectifAccompli()` finally reads `structure.objectif`, which no reader had ever touched: `vaincre_sous_boss` / `vaincre_boss_final` mean the instance of that `tier` is down, `atteindre_et_recuperer` means the deepest room's chest has been searched. An **unknown objective counts as accomplished** — data we cannot interpret must never lock a group inside its dungeon. `quitter_donjon` needs the objective **or**, as an anti-softlock fallback, a fully cleared dungeon (better to go home empty-handed than be trapped).

**« Fouiller la zone » couvre la salle ou le couloir du fouilleur, en entier** (René, 2026-09-27 : « la fouille de piège ou de passage secret se fait seulement dans la salle ou corridor actuel du joueur, sans tenir compte du line of sight » — la règle du livret, « the room or corridor you are in »). C'était un **rayon de 3 cases**, filtré depuis le 2026-09-18 par la **ligne de vue** parce qu'une fouille avait révélé un piège derrière une porte fermée. La zone règle ce cas mieux : derrière une porte, c'est une autre salle ou un couloir ; et elle supprime l'effet de bord de la vue — un meuble haut ou un recoin ne cache plus un piège de la pièce même. `ZoneFouille` est le point de passage unique, lu par `MoteurPieges::revelerAutour()` et `MoteurPortes::revelerSecretesAutour()` : une salle est son **rectangle, mur compris** (là où sont percées les embrasures), un couloir la **composante connexe** du sol hors de toute salle, et une porte appartient à la zone si **l'une de ses deux cases** y tombe — on trouve un passage des deux côtés du mur. ⚠ Le rectangle est testé salle par salle, jamais par `Salles::indexDe()` : deux salles accolées **partagent leur mur**, et `indexDe()` rendant la première, une porte percée dans ce mur serait restée introuvable depuis l'autre. ⚠ La **Potion de Vision** garde sa ligne de vue (`revelerEnVue()`) : elle voit à distance, d'une zone à l'autre ; c'est sa raison d'être.

**Every secret door has a chest behind it** (`quetes.salles_coffre`): finding a hidden passage used to buy only a shortcut. The room *behind* the door — the deeper of the junction's two — holds a chest, never the start room. A chest costs **no deck card**; the deepest room's yields the **unique weapon**, the others gold or a potion. Progression earns the artefact; a shortcut earns loot.

**A quest no longer ends by itself.** Killing the last monster used to close the quest on the spot — so a group that finished off the final guard lost everything it had not yet searched, artefact chest and secret doors included, while **nothing ever forced them to explore** (victory is a clear-out; the gabarit's `objectif` is decorative and unchecked). The engine now only flags `donjon_nettoye`, `MenuMoteur` offers **`quitter_donjon`** while no active monster remains, and choosing it opens a **group vote** (`VoteGroupe::TYPE_SORTIE`) — strict majority, a tie means you stay and keep searching, so nobody's looting gets cut short by a hurried companion. `ResolveurTour::terminerQuete()` is public and called by the vote's resolution. The option is a **free interaction**, not an action, and it disappears if a search spawns a wandering monster. Tests that assert end-of-quest *effects* use the `acheverLaQuete()` helper in `tests/Pest.php`.

⚠ **A cleared dungeon must still OPEN THE NEXT ROUND** (`ResolveurTour::ouvrirNouveauTour()`, 2026-08-15). `phaseMonstres()` is the *only* method that resets `a_joue`, and **three** "no active monster" paths short-circuited it straight into `donjonNettoye()` — in `resoudre()`, in `apresActionHeros()`, in `jouerFinDeRound()`. So the last hero to simply *end their turn* after the final monster fell locked the whole group in an empty dungeon, permanently: `GenererMenu` produces nothing for a hero who has played, and `quitter_donjon` is only offered while they have not — the single window to close the quest shut on itself. No menu, no error, nothing to do from a phone. Found in live play by both players independently. The round now closes first and the `donjon_nettoye` flag is set *after*; the opening (slots reset, `prochain_tour` buffs expired, durations decremented, journal, snapshot) is extracted so the cleared path can call it without a monster phase to play. ⚠ Two votes have **no timeout and no default ballot** (6 h TTL): proposing is not voting, and the proposer must cast their own — the exit vote stalled a whole session at 1/2 because nothing said so.

⚠ **A market cart does not survive the departure** — and now says so. `/pret` **422s** a player whose cart is non-empty and unconfirmed (application is atomic, so no gold is ever debited, but the purchases vanished silently when the quest started and took the market phase with it — a player reasonably read that as theft). The refusal targets **that player only**, never the group: blocking departure for everyone would rebuild the exit-vote trap, where one distracted player locks the others in. Quest start closes the phase **explicitly** (`marche_ferme_par_quete` + broadcast) instead of letting it expire in six hours.

**Every door-opening path reveals the room.** Opening a door shows the room *and its monsters*, as on the board — but that rule used to live only in the explicit `ouvrir_porte` action. A **lever** or a **guardian's death** (`ouvrirParMonstresVaincus`) opened the door and left the room dark with dormant monsters: the way was open and there was nothing to see, while `FabriqueGrille` still counted those monsters as occupying their cells, so the controller offered moves the engine then refused. `ResolveurTour::revelerDerriere()` is now called from **all three** paths; `revelerSalle()` is idempotent, so calling twice is free. Fog also keeps walls **a hero is touching** truthful (`EtatGroupe::appliquerBrouillard`) — a masked wall used to come back as `b`, indistinguishable from unknown floor.

⚠ **Le fil de combat disait « fouille en vain » pendant que le héros empochait sa trouvaille** (René, 2026-09-11 : « on gagne des items quand on cherche mais le message dit cherche en vain »). L'issue **`objet`** — celle du **MOBILIER** (`MoteurMobilier::tirerButin()`, le seul à rendre une pièce d'équipement ; le deck du coffre ne rend que de l'or, des potions et des artefacts) — manquait au `match` de `JournalCombat::fouille()` et tombait sur le `default`. ⚠ `ChoixController` la connaissait pourtant **déjà** (`'objet' => 'mobilier_objet'`) : la **narration** était juste, le **journal** mentait. Deux lecteurs d'une même issue, un seul tenu à jour.

⚠ **Un `match` sans cas et un `default` rassurant valent une issue muette qui se raconte à l'envers.** Contrairement à une clé sans lecteur, rien ne casse et aucun test ne rougit — le joueur lit simplement le contraire de ce qui s'est produit. Quand une issue s'ajoute quelque part, il faut chercher **tous** ses lecteurs.

**« En vain » n'est vrai que si rien n'était à prendre.** Deux cas distincts s'y cachaient et sont désormais nommés : `objet_indisponible` (le butin existait mais **personne du groupe ne pouvait l'utiliser** — la règle de l'étal, « un meuble ne rend plus une potion que personne sur place ne peut boire ») et `errant_indisponible` (aucun budget ne permettait de faire sortir le monstre errant). C'est la différence entre « il n'y avait rien » et « il y avait quelque chose, pas pour vous ».
⚠ **L'étal vend TOUTES les raretés, dans les quatre profils** (René, 2026-09-11, après avoir joué : « changer le marchand pour toujours tout pouvoir acheter »). Le filtre par rareté écartait mécaniquement toute protection sérieuse en dessous de la cité — la *Cotte de mailles* et l'*Armure de plates* sont `rare`, et **la rareté se déduit du PRIX** (`RareteButin`) : une armure chère devenait rare, donc invisible, sans que personne ne l'ait décidé. ⚠ Les **multiplicateurs** restent (village et marché noir à ×1.2) : c'est la couleur du lieu, et ce n'est pas ce qui bloquait. Les **stocks** aussi (3 exemplaires en peu commun, 1 en rare) — le signalement portait sur une rareté **absente du rayon**, jamais sur une quantité insuffisante de ce qui s'y trouvait. ⚠ Les objets `unique` restent exclus par une règle distincte, qu'on ne lève pas.
⚠ **Un SEUL marchand, qui vend tout au prix normal** (René, 2026-09-12 : « pour l'instant force l'utilisation d'un marchand qui vend tout au prix normal ; si on intègre la négociation on regardera pour différents types de marchands »). `PhaseMarche::ouvrir()` **ignore volontairement** le profil demandé — par le MJ IA comme par la requête — et prend `ProfilMarche::UNIQUE`. Les multiplicateurs de lieu passent tous à **1.0**. ⚠ Les quatre profils **restent déclarés** : ce n'est pas un oubli mais une réserve, ils seront la matière de la négociation, et les effacer obligerait à les réinventer. Un test vérifie que les quatre profils demandés donnent le même étal au même prix — sans lui, un prochain passage rebrancherait le paramètre en croyant réparer un bug.
**Une quête à BOSS met son coffre dans la salle du boss** (René, 2026-09-12 : « quand la mission est de tuer le boss, il faudrait avoir un coffre dans sa salle, contenant trésor ou artefact »). Jusque-là l'artefact allait dans la salle **la plus profonde du GRAPHE** (`salleLaPlusProfonde()`, BFS depuis la salle 0), alors que l'objectif vise la salle de **RENCONTRE FINALE** — les deux n'ont **aucune raison** d'être la même, et ne l'étaient pas.

⚠ **Le cas mesuré, en partie réelle (quête 99).** Objectif `vaincre_sous_boss`, boss en salle 6, artefact — l'*Anneau de Chaleur* — en salle 5. La salle 5 pendait au bout d'une arête d'**ARBRE** (`4-5`), donc **sans aucun autre accès**, et cette arête portait une porte `secrete` **jamais révélée**. Le groupe a tué sa cible en salle 6 et la quête s'est close sur une impasse jamais ouverte, où dormaient l'artefact et trois morts-vivants. ⚠ Et **rien, nulle part, ne disait qu'un artefact existait** : ce n'était pas un choix manqué, c'était un choix qu'on ne pouvait pas faire.

⚠ **La salle la plus profonde reste la règle hors quête à boss** (`atteindre_et_recuperer`), où récompenser l'exploration garde tout son sens. La nouvelle règle **précède** l'ancienne, elle ne la remplace pas. ⚠ Et la salle finale se désigne par la **même convention** que `AssembleurCarte::spawnsMonstres()` (« la DERNIÈRE, pour que `spawn_monstres[0]` y atterrisse ») : deux façons de la nommer divergeraient au premier changement de génération.

⚠ **Le deck de fouille ordinaire ne contient AUCUN artefact** — 24 cartes : trésors, potions, errants, pièges. Les artefacts ne viennent **que** des coffres désignés. Une quête où l'on n'ouvre pas le bon coffre est une quête **sans artefact**, quoi qu'on fouille par ailleurs.

⚠ **Un passage secret mène TOUJOURS à un coffre, et un coffre ne rend jamais rien**
(René, 2026-09-12 : « il faut quand même qu'un passage secret amène à un gain, c'est
toujours le cas ? » — question posée **après** le déplacement de l'artefact vers la
salle du boss, et elle méritait une mesure, pas un raisonnement). `sallesACoffre()`
ajoute, pour **chaque** porte `secrete`, la **PLUS PROFONDE** des deux salles que la
jonction relie — l'autre est du côté déjà exploré. Mesuré sur 25 cartes à HEAD :
**66 salles derrière un secret, 66 coffres, 0 manquant**, verrouillé par
`DeckFouilleTest` (« garantit qu'un passage secret mène TOUJOURS à un coffre »).
⚠ **Ne jamais exiger un coffre des DEUX côtés d'une arête secrète** : la première
version de cette mesure le faisait et annonçait 39 défauts sur 40, tous imaginaires.
⚠ Ce que le coffre **paie** a changé, en revanche : avant, la salle la plus profonde
était **souvent** celle du passage secret, donc le secret menait à l'**artefact** ;
depuis, en quête à boss, l'artefact part dans la salle du boss et le secret paie une
**potion ou de l'or doublé** (branche `! $estArtefact` de `carteCoffre()`). Le gain
reste réel, il est seulement moins rare. ⚠ Et les deux ne s'excluent pas : mesuré sur
40 cartes `vaincre_sous_boss`, la salle du boss se trouve **elle-même** derrière une
porte secrète dans **19 cas sur 40** — c'est exactement le cas de la quête 99
ci-dessus, et c'est pourquoi une porte secrète non trouvée peut coûter la quête
entière et non un bonus.

**Sly Storage — une armoire dans la salle fait tirer DEUX cartes** (FL-Q p. 7,
First Light, 2026-09-30 : « the first hero to draw a treasure card in a room
with a cupboard draws two, resolved in order »). `App\Partie\MoteurMobilier::salleContientType()`
dit si la salle courante porte un meuble « Armoire » encore debout (ni
détruit) — point de passage unique, réutilisable par toute règle qui se
déclenche par la PRÉSENCE d'un meuble plutôt que par son adjacence, à la
différence de `fouillablesAdjacents()`/`destructiblesAdjacents()` qui sont
géométriques. « Premier héros » se lit sur l'état durable EXISTANT, jamais une
colonne neuve (§2.16) : `Quete::tresorsFouilles()` dit déjà si la salle a reçu
au moins une fouille de trésor, et c'est interrogé AVANT que la fouille en
cours n'y soit elle-même inscrite.
⚠ **La seconde carte n'inscrit PAS une seconde entrée de `tresors_fouilles`** :
ce n'est pas une seconde fouille du héros — Fouineur (`fouille_supplementaire`,
Explorateur) compterait alors le bonus de l'armoire comme l'une de ses
fouilles supplémentaires — c'est le MEUBLE qui rend une carte de plus pour le
même geste.
⚠ **La seconde carte ne retombe jamais sur le coffre désigné** : `coffrePlein()`
a été lu une seule fois, avant que `marquerTresorFouille()` n'inscrive la
salle ; un second appel le trouverait déjà faux. Une salle à la fois coffre ET
armoire (ça arrive — le mobilier ordinaire se tire par-dessus la garantie de
coffre) rend donc son artefact/or de coffre sur la PREMIÈRE carte, et une carte
de deck ordinaire sur la seconde — jamais le coffre deux fois.
⚠ **« Résolues dans l'ordre » n'admet aucune exception, y compris quand la
première carte est un piège ou un monstre errant.** Un piège de carte ferme
déjà le tour du héros (`a_joue` posé dans `MoteurPieges::declencherEphemere()`),
mais ce n'est pas le héros qui agit une seconde fois : l'armoire rend sa
seconde carte dans le MÊME geste de fouille, donc elle se tire quand même — le
texte ne la conditionne à rien. Même chose pour un monstre errant : il surgit,
et la seconde carte se tire tout de suite après.
⚠ **Un effet automatique que rien n'annonce est injouable** : le payload porte
`armoire: true` sur la carte principale et `carte_armoire` (la seconde, de la
même forme qu'un `fouille_tresor` normal) ; `JournalCombat::ligneAction()` et
`SceneDeTable::depuisAction()` se RAPPELLENT eux-mêmes sur `carte_armoire` —
même patron que `declenchement`/`pieges_declenches` pour un piège imbriqué —
pour que la manette ET la table disent qu'une seconde carte est tombée, et
pourquoi.

⚠ **Faut-il un SECOND passage secret quand le premier tombe sur la salle objectif ?**
(René, 2026-09-12). La crainte est fondée : `sallesACoffre()` part de
`[$salleArtefact]` puis ajoute la plus profonde salle de chaque jonction secrète —
si les deux **coïncident**, l'ensemble se réduit à un seul coffre et le passage
secret n'apporte **rien de plus** que la route obligatoire. Mesuré sur 60 cartes par
objectif, en comptant les coffres réellement produits :

| objectif | 1 coffre | 2 | 3 | 4 | secret sans gain supplémentaire |
|---|---|---|---|---|---|
| `atteindre_et_recuperer` | 25 | 32 | 3 | — | **25 / 60** |
| `vaincre_sous_boss` | 4 | 41 | 15 | — | **4 / 60** |
| `vaincre_boss_final` | — | 27 | 31 | 2 | **0 / 60** |

⚠ **Réponse : non, on n'ajoute pas de second passage.** Le déplacement de l'artefact
vers la salle du boss a **déjà supprimé** l'effondrement là où il était massif :
l'artefact n'est plus dans la salle la plus profonde du graphe, donc il ne partage
plus le coffre du passage secret. Il ne reste que `atteindre_et_recuperer` (25/60) —
et là, le coffre effondré **EST l'objectif** (`objectifAccompli()` exige que
`salle_artefact` ait été fouillée) : le passage secret mène à la plus grosse
récompense de la quête, pas à rien. ⚠ Ajouter un second passage se heurterait à
trois arbitrages en vigueur : le taux est tenu à **50 % + compteur de pitié**
précisément pour que « il y a toujours un passage caché » ne devienne pas une règle
que les joueurs apprennent (2026-08-27) ; `groupes.chance_passage_secret` compte
**s'il y en a eu un**, pas combien, et deux passages rendraient « placé » ambigu ; et
le tirage consomme un pas de PRNG **dans les deux branches** pour que deux donjons de
même graine restent identiques — un second tirage conditionnel casserait ça.

**Caisse de ravitaillement** (*Supply Crate*, Against the Ogre Horde p. 5,
lot B) : « The first hero to search for treasure in a room containing one of
these chests will find 4 Potions of Healing. » Fouillée **au contact**
depuis le 2026-10-02 (René) : `ResolveurTour::resoudreFouilleMobilier()`
verse 4× *Potion de guérison* (`soin_pv_body_de: 6`, « roll 1 red die ») au
premier qui l'ouvre — « premier » lu sur `fouille_par` du meuble AVANT de
l'inscrire —, une caisse vide (`caisse_vide`) aux suivants. Un butin FIXE,
jamais un tirage. Caisse et coffre peuvent partager une salle (René : « ça
me dérange pas ») : ce sont deux meubles, deux fouilles au contact.

⚠ **PORTAGE : l'emprise au sol n'est pas sourcée.** Le livret ne chiffre
cette caisse que sur les plans de quête imprimés (non repris — donjons
générés) ; faute de mesure indépendante (règle de `MobilierSeeder`, doc 17
§1 : ne jamais inventer une emprise), `Caisse de ravitaillement` reprend
celle du *Coffre* — 1×1, difficulté de destruction 2 — par analogie
fonctionnelle (c'est un coffre) plutôt que par mesure.

**Mission « secourir » — un nouveau type d'objectif de quête** (chantier 3b,
2026-10-04 : généralise Gothar, *The Frozen Horror* quête 3, p. 19 — « *Any
monsters encountered attack only the Barbarian, as they are under orders to
capture Gothar alive. If the Barbarian dies, Gothar is automatically
captured.* » — même famille que le Prospecteur et la Princesse Millandriel
de *The Mage of the Mirror*, quêtes 4 et 10). `gabarits_quetes.structure.objectif
= 'secourir'` (gabarit « Mission de sauvetage », `GabaritQueteSeeder`,
`type_jalon: 'normale'`) : un **captif** sourcé (Gothar — Move 6 · Attack 1 ·
Defend 2 · Body 2 · Mind 4, Frozen Horror p. 19/37) est posé, à l'assemblage,
dans la **salle-artefact** — la MÊME salle qu'un coffre ordinaire
(`DeckFouille::construire()` : `salleDuBoss() ?? salleLaPlusProfonde()`),
jamais une seconde case choisie à part. Il existe comme une ligne
`groupe_mercenaires` dès le départ, `etat: 'captif'` — ni joué, ni contrôlé,
exactement comme un meuble, jusqu'à ce qu'un héros à son contact le LIBÈRE
(`MenuMoteur` option `liberer_captif`, créneau `tour` comme relever un
compagnon) : il devient alors un allié `'actif'` ordinaire, contrôlé par ce
héros — joué désormais par SON joueur, chantier 3a,
`docs/regles/combat-et-tour.md`.

**DEUX MODES depuis le chantier « captifs-jetons » (2026-10-05, décision de
René) : `mercenaires.mode_captif` — vocabulaire fermé à deux valeurs, lu
UNE SEULE FOIS, par `ResolveurTour::resoudreLibererCaptif()` (le reste du
moteur distingue les deux modes sur la seule valeur de `groupe_mercenaires.
etat`, jamais une seconde lecture de la colonne).**

- `'figurine'` (Gothar — Move 6 · Attack 1 · Defend 2 · Body 2 · Mind 4,
  Frozen Horror p. 19/37) : libéré, `etat` passe à `'actif'` — un allié
  ordinaire, contrôlé par son libérateur, qui se déplace case par case
  jusqu'à l'escalier.
- `'escorte'` (le Prospecteur, la Princesse Millandriel — *The Mage of the
  Mirror* p. 4 : « This tile represents the old prospector who acts as an
  ally and is controlled by the hero who finds him » / « Princess
  Millandriel[l]… acts as an ally and is controlled by the hero who finds
  her » — AUCUN bloc de stats dans tout le livret, ce sont des tuiles SANS
  carte) : libéré, `etat` passe à `'porte'` — il est PORTÉ par le héros
  libérateur, jamais une figurine : aucun tour, aucune case propre sur la
  grille, aucune cible pour les monstres. `position_x`/`position_y` ne sont
  plus jamais réécrits : ils restent la case d'ORIGINE. **S'il tombe**, le
  porteur est traité comme n'importe quel héros à terre — mais le captif,
  lui, est **REPRIS** : « monsters take the prospector to room D » (p. 23,
  généralisée à Millandriel) — `etat` revient à `'captif'`, SUR CETTE CASE
  D'ORIGINE, à libérer de nouveau. **Jamais un échec de quête** (contrairement
  au mode figurine, § ci-dessous) : un captif escorté n'a ni PV ni figurine,
  il ne peut donc jamais être « tué », seulement repris. Détecté et
  journalisé par `ResolveurTour::reprendreCaptifsPortes()`, au MÊME
  round-boundary que la détection de TPK (`ouvrirNouveauTour()` → même
  docblock, même raisonnement : ni l'un ni l'autre ne se vérifie coup par
  coup). `deplacement`/`attaque`/`defense`/`pv_body`/`pv_mind`/`prix` valent
  `0` dans `MercenaireSeeder` — pas une valeur sourcée, une colonne que
  `mode_captif: 'escorte'` fait sortir de tout calcul avant qu'elle n'y
  entre, jamais lue pour ce mode (CLAUDE.md, « ne jamais seeder une valeur
  que les livrets ne sourcent pas » : `0` n'est pas une stat, c'est
  l'absence déclarée de stat).

Rendu : un captif escorté disparaît purement et simplement de `entites`
(ni `type: 'captif'`, ni `type: 'allie'`) — il se voit sur le HÉROS qui le
porte (`entites[].captif_porte`, table et manette, badge partagé
`badgesFigure()`), jamais sur une case de la carte.

**Condition de victoire et d'échec** (`Quete::objectifAccompli()`,
`captifLibereEtVivant()`, `captifPerdu()` — point de passage unique, lu
partout où un objectif l'est : bannière de table/manette, `quitter_donjon`,
montée de niveau, fin de quête) : accompli dès que le captif est **libéré ET
vivant** — la sortie elle-même suit ensuite le vote ordinaire, comme tout
autre objectif, choix le plus fidèle au livret parmi les deux lus
(« escort » jusqu'à la sortie, ou « nettoyer la salle » à défaut — Gothar ne
nomme que l'escorte, retenue ici). **S'il meurt** une fois libéré — MODE
FIGURINE SEULEMENT, un captif escorté n'a ni PV ni figurine à perdre, voir
§ ci-dessus — (un monstre l'achève — les sorts de Dread visent encore les
seuls héros, limite déjà nommée), la quête **échoue immédiatement** —
`ResolveurTour::echouerSiCaptifPerdu()`/`echouerQuete()`, le MÊME point de
passage et la MÊME cérémonie qu'un TPK (retour au hub, alliés consommés,
snapshots conservés pour `/reprise`) : généralise « if the Barbarian dies,
Gothar is automatically captured » à toute mort du captif désigné
(`quetes.captif_mercenaire_id`, état durable, jamais en cache — la colonne
dit SANS AMBIGUÏTÉ lequel des alliés recrutés compte pour l'objectif). Un
gabarit SANS captif désigné (aucun profil sourcé disponible, ou salle sans
case libre à l'assemblage) tient l'objectif pour accompli d'emblée — même
prudence que tout objectif inconnu : jamais une mission silencieusement
impossible à remplir.

⚠ **Simplification nommée : pas de filtre par thème de bestiaire.** Le
tirage du gabarit (`DemarreurQuete::choisirGabarit()`, rotation
déterministe graine-groupe + position d'arc, `RATIO_SECOURIR = 4`, jamais si
`Mercenaire::where('captif', true)` est vide) ne vérifie pas que le thème de
la campagne est *The Frozen Horror* — Gothar peut apparaître, habillé par
l'IA, dans un donjon d'un autre thème. Rien ne ROMPT (le captif reste jouable
quel que soit le décor), mais la couleur de boîte peut être incohérente.
Scopé d'abord, comme le patron de ce projet le veut : à resserrer avec
`BestiaireGroupe::contient()` si une seconde fiche sourcée rend la
généralisation payante.

Visible sur la carte comme une entité `type: 'captif'` (`EtatGroupe::captifs()`)
— **caché tant que sa salle n'est pas découverte**, même garde que les
monstres dormants (`Salles::indexDe()`) : un marqueur visible sur une carte
encore noire serait le brouillard contourné par la porte de derrière. Une
fois libéré, il quitte ce flux et rejoint `EtatGroupe::allies()` comme
n'importe quel allié.

**On ne quitte le donjon QUE par l'escalier d'entrée, et la mission
« secourir » devient une vraie EXTRACTION** (chantier escalier-entrée,
2026-10-05, René : « ça clarifierait la réussite de la mission d'extraction
où l'allié temporaire doit être retourné à l'entrée pour finir »).
`AssembleurCarte` pose désormais un escalier 2×2 traversable dans la salle de
départ de chaque quête (→ `docs/regles/carte-donjon.md`). Deux conséquences :

1. **Tous réunis dans la SALLE DE DÉPART** (René, 2026-10-10, après le verdict
   Jungle — remplace « un seul héros sur l'escalier », qui faisait sortir tout le
   groupe y compris des héros à dix cases de l'escalier, et « tous sur
   l'escalier », abandonné le jour même). `quitter_donjon` (`MenuMoteur`,
   `VoteGroupe::TYPE_SORTIE`) n'est offert que si **tous les héros debout** sont
   dans la salle qui contient l'escalier d'entrée, **en plus** des conditions
   déjà en vigueur (objectif accompli ou donjon vidé, pas de vote ouvert). Les
   héros **tombés** (mode Story) ne bloquent pas. Point de passage UNIQUE :
   `Quete::rassemblementDepart()` (salle via `Carte::salleDepart()` →
   `Salles::indexDe()`), lu par le menu, par `ResolveurTour::resoudreQuitterDonjon()`
   (422) et par `EtatGroupe` (`quete.sortie`). Et **annoncé** : une fois la sortie
   ouverte, la bannière d'objectif (table + manette) et la `situation` du menu
   disent « Rejoignez la salle de départ pour quitter le donjon — il manque : X, Y »
   — phrase décidée par le serveur, jamais recalculée en JS.
   ⚠ **DIVERGENCE DÉLIBÉRÉE avec les règles officielles, décision de René du
   2026-10-10, qu'il ACCEPTE** : dans le livret, chaque héros quitte le donjon en
   marchant sur l'escalier ; chez nous, il suffit que tous les héros debout soient
   dans la salle de départ, puis le groupe vote la sortie. Ne pas « corriger » vers
   la règle du livret (test qui l'épingle : `EscalierTest.php`, « quitter le donjon
   est offert quand TOUS les héros debout… pas forcément sur l'escalier »).
   ⚠ **`battre_en_retraite` reste SANS AUCUNE condition** (René, 2026-08-21,
   rappelé explicitement le 2026-10-05) : la salle de départ ne s'applique QU'À
   `quitter_donjon`, jamais à la retraite — décrocher doit rester possible au
   pire moment, loin de l'escalier, sans quoi ce n'est plus une retraite.
2. `Quete::captifLibereEtVivant()` — et donc `objectifAccompli('secourir')` —
   exige désormais que le captif libéré et vivant (ou son porteur, en mode
   escorté) se tienne **sur une case de l'escalier**, pas seulement qu'il ait été
   libéré. ⚠ **Décision de René, 2026-10-10 : l'extraction n'est PAS assouplie en
   « salle de départ »** — seuls les HÉROS bénéficient de la règle ci-dessus ; les
   deux vérifications restent distinctes (`rassemblementDepart()` /
   `captifLibereEtVivant()`), et la bannière dit « Amenez X sur l'escalier d'entrée »
   (`quete.sortie.consigne_extraction`). C'est le choix du livret
   qu'on avait écarté le 2026-10-04 faute d'escalier à viser (« la sortie
   elle-même suit le vote ordinaire ») : Gothar doit être **escorté** (Frozen
   Horror p. 19), et l'escalier est désormais le point d'arrivée concret de
   cette escorte. `objectif_libelle` dit « … et le ramener vivant à
   l'escalier. » plutôt que « … à la sortie. ».

⚠ **Repli écrit et testé pour les cartes déjà assemblées SANS escalier**
(campagnes EN COURS dans la vraie base, `tests/Feature/Partie/EscalierTest.php`) :
`Carte::casesEscalier()` rend `[]` quand la couche est absente, et les DEUX
lecteurs ci-dessus retombent alors sur le comportement d'avant — sortie
possible n'importe où (aucune exigence de salle), mission accomplie dès la seule libération. Jamais une
migration rétroactive sur une carte déjà générée ; jamais une quête en cours
rendue impossible à terminer.

## Objectif « détruire un élément de la carte » (2026-10-09, décision de René)

**Type d'objectif générique `detruire_element`**, d'abord pour la quête finale de *Wizards of Morcar* (livret G1504 p. 39, quête 10 : « This room contains the Keeper […] as well as the High Altar. The High Altar may be attacked using normal combat and has 6 Body Points. It rolls four dice when defending. […] When the High Altar is destroyed, read the following […] Remove all remaining monsters from play. The quest is won. » ; « Destroy the High Altar to complete this quest. »). Réutilisable tel quel pour le *Crystal Cluster* de Delthrak.

- **Désignation DURABLE, portée par la carte** : une entrée de `cartes.grille.mobilier[]` porte `objectif: true` (posée à l'assemblage, en base — jamais en cache). Lecteur UNIQUE : `MoteurMobilier::elementObjectif()` (enveloppé par `Quete::elementObjectif()`). Aucune colonne, aucune migration. **L'objectif est une propriété de la carte, pas du gabarit** : « Confrontation finale » est commun à tous les thèmes, seule la quête finale de Morcar se gagne sur l'autel. `Quete::objectif()` rend donc `detruire_element` dès qu'un élément est désigné, et `objectifAccompli()` / `objectifLibelle()` (« Détruire : Haut Autel. ») restent le point de passage UNIQUE lu par la bannière table/manette, la montée de niveau et les gardes de sortie.
- **Registre** : `MoteurMobilier::ELEMENT_OBJECTIF_FINAL` (`boîte => nom du meuble`), testé dans les deux sens (`ObjectifDetruireElementTest`). `AssembleurCarte::placerMobilier()` pose l'élément **à coup sûr** dans la salle du boss (la dernière, celle de `spawn_monstres[0]` — même convention que `DeckFouille::salleDuBoss()`), AVANT tout autre meuble, avec la pose et le plancher de cases jouables du Coffre (§2.12 ter), sur 8 tentatives. Il est **retiré du tirage de décor aléatoire** : le Haut Autel qui traînait dans n'importe quelle salle d'une quête ordinaire du thème n'ouvrait sur rien.
- **Fin de quête (décision fidèle au livret)** : la chute de l'élément **gagne la quête sur le coup** (`ResolveurTour::gagnerParDestruction()` → `terminerQuete()`, le point de passage unique des fins de quête). **Pas de vote de sortie, pas d'escalier** : le livret dit « The quest is won », pas « les héros doivent ressortir ». « Remove all remaining monsters from play » = la quête terminée, ses monstres quittent le jeu avec elle (aucune instance modifiée, comme à toute victoire). Le coup court-circuite la fin de round (aucun monstre ne joue après la victoire). Jalon `boss_final` : butin, niveau, clôture de campagne comme d'habitude. La Gardienne n'a **pas** besoin d'être tuée ; la tuer ne gagne rien (son texte de mort, p. 39, n'est pas porté — non demandé).
- **Annonce** (jouable sans clé API) : texte de fin du livret **traduit**, scripté dans `config/narration.php` (`fin_objectif`, par nom de meuble, repli `defaut`), narré, journalisé (`systeme`/`objectif_detruit`), publié dans la réponse (`objectif_detruit: {nom, texte}`) et dit au fil de combat (`JournalCombat::attaqueMobilier()`). `ExecutionChoix` ne le redit pas.
- **Garde de sortie** : tant que l'élément tient, un donjon vidé n'ouvre PAS `quitter_donjon` (`Quete::donjonVideOuvreLaSortie()`, lu par `MenuMoteur` ET `resoudreQuitterDonjon()`) — le repli « mieux vaut rentrer bredouille » porterait le groupe à la sortie autel debout. `battre_en_retraite` reste sans condition.
- ⚠ **Repli écrit et testé** : une carte déjà assemblée SANS élément désigné (campagne EN COURS) garde l'objectif de son gabarit (`vaincre_boss_final`) et son repli « donjon vidé » — aucune migration rétroactive, aucune quête en cours rendue injouable.
- ⚠ **Mélange manuel de boîtes** : l'élément est posé dès que le bestiaire du groupe contient la boîte déclarée (thème automatique OU bestiaire manuel qui la coche). Un mélange Morcar + autre boîte donne donc une quête finale à gagner sur l'autel, quel que soit le boss tiré — choix assumé, la désignation étant portée par la carte.

## Mercenaires, entretien, Gardien et faveurs de Hopekins Rest (2026-10-08, décisions de René du 2026-10-06)

⚠ **Un allié recruté ne se consomme plus en fin de quête : il se paie.** Wizards of Morcar (livret p. 8-9) donne au mercenaire un **entretien de 10 po par quête**, et René l'a étendu à **tous** les groupes, pas seulement au thème `wizards_of_morcar`. Le recrutement n'ouvre qu'au statut de **Gardien**, débloqué quand **2 quêtes sont achevées** (`Groupe::estGardien()`, calculé en direct sur `quetes`, jamais une colonne) ; 4 mercenaires au plus par héros recruteur. L'entretien est réglé à la **victoire** seulement (`ResolveurTour::terminerQuete()` → `FaveursHopekins::reglerEntretien()`) : un TPK peut encore être défait par `POST /reprise`, et le facturer le ferait payer deux fois à la victoire suivante. Payés dans l'ordre d'embauche, le plus ancien d'abord ; un mercenaire non payé **quitte le groupe** et se réengage plein tarif. Les captifs scénarisés (`mercenaire.captif`) ne sont jamais recrutés contre de l'or : ils restent hors de cette économie et sont purgés en fin de quête comme avant.

⚠ **Ce qui s'annonce, et où.** Le prélèvement est publié au hub (`groupe.mercenaires_entretien` : combien par tête, qui est payé, qui part faute d'or, la bourse restante). Une annonce de fin de quête n'est valable que pour la **dernière quête achevée** (`quete_id`) : sans cette garde, une quête sans mercenaire afficherait l'entretien de la précédente. La même garde vaut pour la faveur, qui porte en plus son effet en clair (`faveur_effet`).

**Faveurs de Hopekins Rest** (livret p. 22-23, « Boons of Heroism ») — récompense **séparée, hors arbre de talents** (décision de René, 2026-10-06). À la fin d'une quête **réussie**, une fois Gardien, le groupe reçoit **une** faveur tirée au hasard parmi les cinq qu'aucun héros actif ne détient encore, remise à un héros actif tiré au hasard. Ni choix ni file d'attente : le tirage est décidé par le moteur, puis annoncé. Épuisé (les cinq déjà distribuées), rien ne tombe et rien n'est annoncé, comme un butin qui ne tombe pas. Les quatre autres faveurs du lieu (mercenaire gratuit, +2 PV de Body, +1 potion, répertoire de sorts) ne sont **pas** portées ici.

**Peacekeeper — qui reçoit, et quand.** La carte dit « you » : seul le héros qui frappe, sort ou allume la braise est crédité, et il est crédité d'**un monstre compté** par mise à mort réussie. Ne sont **jamais** crédités : un allié recruté, un piège, un sort du Dread, un monstre qui en frappe un autre, un sbire. Un monstre à phases qui passe à la forme suivante n'est pas vaincu : seule la mort réelle compte. La carte dit « at the end of that quest » : **l'or se verse à la fin d'une quête GAGNÉE**, 25 po par monstre compté pendant cette quête (`FaveursHopekins::reglerPeacekeeper()`, appelé par `ResolveurTour::terminerQuete()` seulement, avant l'entretien des mercenaires). Une quête perdue ne verse rien, et la reprise restaure le compteur avec le snapshot (2026-10-08 : aligné sur le texte de la carte ; l'écart de timing
qui était noté ici est clos).

**Hold the Line** est la seule exception nommée à « aucune attaque d'opportunité » : un monstre qui s'éloigne des huit cases du porteur subit un dé de combat, 1 PV fixe sur un crâne.

⚠ **Une faveur qui agit sans rien dire est injouable.** Peacekeeper et Hold the Line sont rendus au **fil de combat**, en direct (`faveurs_declenchees` dans le résultat de l'action, via `TamponFaveurs`) et à la reconnexion (journal `combat`). La fiche du héros porte chaque faveur avec son **nom et son effet** : en quête, dans la table (`EtatGroupe.entites[].faveurs`) ; au hub, sur la manette (`/moi`). Les deux viennent de `FaveursHopekins::publier()`, relu à chaque publication, jamais figé dans le journal.

## Levier déjà forcé ; fouille de zone : l'issue dit la recherche (Morcar, 2026-10-09)

- **Un levier dont la porte est ouverte n'est plus une action.** Il reste visible sur la carte ; le menu ne le propose plus, et le résolveur refuse le geste. Le prédicat est un seul : `MoteurPortes::levierAOuvrir()` (il existe au moins une porte `verrou: levier` avec ce `levier_id` qui n'est pas ouverte). Un levier n'a pas d'état propre : sa porte dit tout.
- **Une réussite nomme toutes les portes qu'elle ouvre**, jumelles de seuil comprises (`MoteurPortes::ouvrir()` ouvre un seuil large d'un coup). `portes_ouvertes` est décidé par la comparaison à l'état d'avant, jamais par la seule boucle sur la porte du levier.
- **Une fouille de zone (`fouiller`, `fouiller_pierre`) dit ce que la RECHERCHE a donné** : `issue: reussite` = quelque chose trouvé (`a_trouve: true`) ; `issue: rien` = le jet réussit et ne trouve rien (`succes: true`, `a_trouve: false`) ; `issue: echec` = le jet a raté. `succes` reste le résultat brut du dé. Le temps fort du narrateur suit : `rien` → `fouille_rien`. Avant, un jet réussi sans trouvaille portait `issue: reussite`, lu comme une découverte.
