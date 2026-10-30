# Carte du donjon — génération, mobilier, symboles

> Extrait de `CLAUDE.md` le 2026-09-06, **verbatim**. Ce sont les **règles en
> vigueur** — pas un document daté comme `docs/plan-*.md` / `docs/verdict-*.md`.
> Index : `CLAUDE.md` §« Règles établies ».

**Map generation.** `app/Partie/AssembleurCarte.php` lays rooms on a 2D branchy tree of uniform slots. A **threshold is ONE cell** — the board only has single-square doorways (René's call, 2026-08-08). Thresholds used to be 2 cells wide so a tank couldn't block the shooter's line of sight (playtest 2026-07-31, a magicien mute for 8 turns); but the official game solves that case **differently**, with the **diagonal attack** — "two heroes at once may attack a monster blocking a doorway" (LR p. 14, `reference/16_armurerie.md` §6.2), a rule the engine already applies (`attaque_diagonale`). A corridor therefore carries **2 doors, one per room**, sharing a `jonction` id; `MoteurPortes::ouvrir()` opens only the **threshold pushed**, never the far end — grouping the whole junction meant the party reached the end of a corridor to find the door already open, on a room still unrevealed. The corridor itself may keep a second lane: it widens the **corridor**, never the threshold — and since 2026-09-27 only **one corridor in two** has it (see « Couloirs d'une case » below). The room pool must stay **varied** (≥ 6 distinct shapes): it had shrunk to 3 shapes each duplicated, because `TuileSeeder` used `create()` and was re-seeded — every room in a dungeon looked identical and players lost track of where they were. It now purges before seeding. Rooms are placed **compactly** (`candidatLePlusCompact()`: most adjacent placed rooms first, then closest to the centroid) rather than at random. A randomly-spread tree almost never folds back on itself, so it offers no pair of adjacent-but-unconnected rooms — and without one, **no loop and no secret door are possible at all**. Combined with a floor of **5 rooms** in every gabarit (a 3-room polyomino has exactly 2 adjacencies, so a cycle is geometrically impossible; 4 rooms only manage it 59 % of the time), this used to guarantee **at least one secret door per quest** — René's requirement, **lifted by him on 2026-09-27** (« de 0 à 2 passages secrets par quête », see below); compact placement and the 5-room floor are still what make loops, and so the hidden room, possible at all.

Beyond the spanning tree, `liaisonsSupplementaires()` adds **extra edges between grid-adjacent unconnected rooms** — loops, roughly one per two rooms — all **ordinary** since 2026-09-27 (they used to alternate, every other one `secrete`, the first always). Two reasons: a pure tree has a single route between any two rooms, and secret doors had **nothing to open** (so "Fouiller la zone" only ever found traps). ⚠ **This invariant is DEAD** — it read « hard invariant, tested: a room is never reachable only through a secret door — a failed search must never strand the group », and it was **removed** on 2026-08-24 by the arbitration below (« A room may now hang on a single SECRET DOOR »). It is kept here struck through rather than deleted because it is the reasonable-looking idea that lost, and because it stayed written here as a live invariant for nineteen days while the code did the opposite: measured 2026-09-12, **20 to 22 maps in 60** carry a room reachable only through a secret door, and the objective room itself is in that case **4 to 10 times in 60** depending on the gabarit. `CouloirsTest` asserts only that **at least one** secret door exists, never this. Room counts are drawn uniformly across the gabarit's `salles.min..max` (widened to 5-value spreads so quest size actually varies).

**0 à 2 passages secrets par quête, chacun vers une salle cachée** (René, 2026-09-27 : « est-ce qu'on peut avoir de 0 à 2 passages secrets par quête ? […] quand c'est le cas je ferais un aléatoire entre 1 et 2 », puis « je ne tiens pas à ce que le 2e passage soit une boucle »). Mesuré le même jour sur 200 cartes par gabarit, l'alternance des boucles en posait **1 à 3, jamais zéro** (normale 60/35/5 %, boss final 22/60/18 %) — et avec la salle cachée par-dessus, la fouille trouvait presque toujours quelque chose. Désormais les boucles sont **toutes ordinaires**, et **le tirage de la salle cachée décide de tout** (50 %, compteur de pitié inchangé, voir plus bas) : s'il ne pose rien, la carte n'a **aucune** porte secrète ; sinon `secretiserUneAreteDArbre()` cache **une ou deux** feuilles distinctes (une seule si le donjon n'en offre qu'une), chacune avec son coffre (`DeckFouille::sallesACoffre()` lisait déjà toutes les jonctions secrètes). Le garde-fou « une seule arête d'arbre secrète » devient « au plus deux » ; l'interdit de **chaîne** tient toujours par construction — une feuille n'a pas d'enfant, donc aucune salle cachée n'est derrière une autre. `CouloirsTest` vérifie « 0 sans tirage, 1 ou 2 avec, autant de salles cachées que de passages, et les trois issues existent ».

`accolerSallesMitoyennes()` slides some **leaf** rooms flush against their parent so the two share a wall: the existing carver then produces a length-1 corridor — i.e. a plain threshold, a door straight from one room into the next (~1 per dungeon). **Leaves only**: shifting a room along its edge axis moves its perpendicular median, and every corridor is straight only because medians align — a leaf has just one edge, so nothing breaks. The shift is abandoned if it would overlap another room.

**Traps sit in rooms as well as corridors**, never in the **start room** (a trap under the party at turn 1 is suffered, not played) — candidates are shuffled as one pool, since keeping corridor midpoints first put 58 of 61 traps in corridors and left every room perfectly safe. Only heroes trigger them: `MoteurPieges::declencher()` takes a `Personnage`, so a monster cannot be passed — a creature standing on a trap is an ambush, not a bug. ⚠ **Never on a door cell** (2026-09-27): `interieur()` counts the embrasure — the wall cell a corridor carved — as room floor, so a trap could sit under a closed door, and a Falling Block there walled the threshold for good. `placerPieges()` now excludes both `Grille::casesPorte()` of every door, as furniture already excludes thresholds.

**`detectable: false` — un piège qui ne se trouve JAMAIS par la fouille** (Wizards of Morcar, livret G1504 §5/§9, lot B, 2026-10-06). La colonne `pieges.detectable` existait depuis la création de la table **sans aucun lecteur** — un booléen décoratif, toujours `true` sur les sept pièges déjà seedés. `MoteurPieges::reveler()` (fouille, Œil du mineur, Potion de Vision partagent ce révélateur privé) et `piegesCachesAdjacents()` (Sens du piège) l'excluent désormais : un piège `detectable: false` reste `cache` quelle que soit la méthode de détection, et seul le pas qui marche dessus le déclenche. Trois pièges de la boîte en portent : le **Piège de téléportation** (paire A/B posée par `AssembleurCarte::placerPiegesMorcar()`, même patron que les Tunnels de glace), le **Piège de l'ouragan** (couloir seulement, repousse tous les héros du couloir à l'opposé du piège, jusqu'à 8 cases ou jusqu'au premier mur/meuble/piège) et le **Piège d'embrasement** (s'amorce en silence, explose au DÉBUT du tour suivant du MJ — `MoteurPieges::explosionsFireburstEnAttente()`, appelée en tête de `ResolveurTour::phaseMonstres()` — sur toute la salle ou le couloir, héros ET monstres, défense normale). Les trois ont leur propre pose (`placerPiegesMorcar()`), à part du tirage générique de `placerPieges()` — même raison que la Lame balançoire. ⚠ **Rendu front NON FAIT** : les trois nouveaux types de déclenchement (`piege_teleporte`/`piege_bourrasque`/`piege_amorce`/`piege_explosion`, voir `docs/contrat-api.md` §Wizards of Morcar) n'ont ni symbole dédié ni entrée dans les listes d'état front (`store/game.js` `PIEGE_ETATS`, `LegendeCarte.vue`, `DeplacementSheet.vue`) — la mécanique tourne, l'affichage reste à faire.

**Couloirs d'une case, et un piège connu ne se foule plus gratuitement** (René, 2026-09-27 : « ne faudrait-il pas aussi des corridors à 1 case de large, car sinon il est plutôt facile de contourner les trappes plutôt que de les désamorcer ou sauter par-dessus »). Le piège de couloir tombe sur la voie rapide, et la voie parallèle le longeait sur toute sa longueur : détecté, il se contournait d'un pas de côté. ⚠ Mais le vrai trou était ailleurs, et rétrécir seul n'aurait rien réglé : `MoteurPieges::controlerChemin()` ne déclenchait que les pièges **cachés**, donc un piège **détecté** se foulait sans rien subir — dans un couloir d'une case, on aurait remplacé « contourner » par « marcher dessus ». Trois changements, dans cet ordre : (1) un piège détecté **se déclenche** quand on marche dessus, comme s'il était caché ; le trajet (`ResolveurTour::cheminDuHeros()`, point de passage unique de l'aperçu et de la résolution) l'**évite** quand un détour est payable, sinon le traverse et l'aperçu le signale — viser sa case, c'est choisir d'y marcher. (2) La **Chute de blocs se saute** tant qu'elle n'est pas tombée (livret p. 14, doc 16 §7.3) ; `MoteurPieges::estFranchissable()` dit ce qui se saute, `estFosse()` ne désigne plus que la fosse (persistante) — Forme démoniaque et Potion de dextérité parlent de la fosse seule. Un saut raté sur une Chute de blocs laisse le héros **sur le bloc**, avec le même choix d'écart qu'en marchant (les Bottes de Lièvre sautaient déjà tout piège découvert et tombaient dans ce trou sans écart). (3) `creuserArete()` ne creuse la voie parallèle qu'**un couloir sur deux** (`CHANCE_VOIE_UNIQUE`, tiré pour chaque arête, mitoyenne comprise, pour garder la suite du PRNG indépendante de la géométrie) — le livret dit « one or two squares wide » (LR p. 11). ⚠ **Un tirage, pas « les couloirs piégés sont étroits »** : ce serait un signal appris (« couloir étroit = piège »), le défaut même que le mélange des candidats de `placerPieges()` avait corrigé. ⚠ **Jamais de Chute de blocs dans un couloir à voie unique** (René, même jour) : tombée, elle en ferait un mur permanent, et sur une arête sans boucle les salles au-delà — objectif compris — deviendraient inaccessibles. Fosse ou Piège à lances seulement là ; en salle et en couloir double, rien ne change. `CouloirsTest` vérifie qu'une voie parallèle est entière ou absente (jamais une alcôve où contourner quand même), que les deux largeurs existent, et qu'aucune Chute de blocs ne tombe en voie unique. ⚠ Corollaire assumé : dans un couloir d'une case on se bat en file indienne — l'attaque en diagonale ne sert qu'aux seuils. ⚠ **Même jour, `fosse_ouverte`** (René : « avoir un état différent sur la carte pour identifier un piège détecté sans être déclenché », puis « afficher les pièges détectés en semi-transparent ») : une Fosse déclenchée **repassait à `detecte`** — le trou reste, donc « persistante » — et se dessinait exactement comme un piège détecté intact, légende « désamorçable au contact » comprise, alors qu'une fosse ouverte ne se désamorce pas (doc 16 §7.3). Elle a désormais son état, `MoteurPieges::ETAT_FOSSE_OUVERTE` : armée (y marcher fait tomber), sautable, **jamais désamorçable** — le menu ne l'offre pas, le résolveur le refuse. `detecte` veut dire **jamais déclenché**, sans exception, et se dessine **semi-transparent** (on devine le piège sous le sol) ; la fosse ouverte est un trou **opaque cerclé d'ambre**, distinct du cratère gris d'un piège dépensé (`declenche`). ⚠ Un état de piège s'ajoute dans **les quatre** listes front (`PIEGE_ETATS` de `store/game.js`, sa copie d'`ApercuSalle.vue`, `ETATS_PIEGE` de `LegendeCarte.vue` et de `DeplacementSheet.vue`) — c'est la leçon du `bloc`, un cran plus loin. Les quêtes déjà en cours gardent leurs fosses ouvertes sous `detecte` : rien ne permet de les reconnaître après coup.

Doors are drawn once, in the shared `resources/js/components/carte/DungeonGrid.vue` (both the table map and the controller's movement sheet). Every door renders its two **jambs** — an open door used to be a 2px dashed line at 50% opacity, invisible on the controller's 22px cells, and a playtester twice mistook an ally's cell for a threshold. The leaf now only conveys state: gold closed, absent when open, violet for a revealed secret door. All these properties are locked by `CouloirsTest`.

**Room furniture blocks — on two independent axes.** The grid knew only `m` (wall) and `s` (floor); the board is furnished, and those pieces bar the way as much as they dress the room. `mobiliers` is a seeded catalogue (8 types whose footprint was **measured** on the official quest maps, since no booklet ever states one, plus the fireplace on 2026-10-16 — a base-box piece (RB p. 4), its Healing Hearth rule alone being First Light; the table of the sorcerer and the rack stay out — their drawing flags are not sourced). **Footprints since 2026-10-16 (René): bookcase, cupboard, weapons rack and fireplace occupy 3 squares (1×3); table, tomb (lying down, as drawn) and alchemist's bench occupy 6 (3×2)** — decisions taken on drawings read at a scale of ≈ 0.9 (a bookcase drawn at 2.7 squares is 3 real), not measurements; the sorcerer's table is decided at 3 squares but is NOT in the catalogue. Shipped as migration `2026_10_16_130000_mobilier_trois_cases` (the same file, completed before it was played); a quest already in flight keeps the footprint it was posed with, because `cartes.grille.mobilier[]` stores its own `l`×`h`, and `FabriqueGrille` never re-reads the catalogue size. `AssembleurCarte` lays a third `carte.mobilier` layer on the exact pattern of `leviers`/`pieges` — no new cell type, no tile migration. `FabriqueGrille::pour()` holds the **single** furniture loop in the whole engine; a second path would let movement, targeting and line of sight drift apart. Footprints reuse `cellulesEmprise()`, already written for large figures.

Movement-blocking and sight-blocking are **separate flags** (`bloque_mouvement`, `bloque_vue`): you see over a table, not through a bookcase. A **third** joined them on 2026-08-21, `adosse_au_mur`: placement drew a random floor cell and flipped a coin for orientation, never looking at walls — measured over twelve real dungeons, a bookcase followed the wall **four times in ten** and a cupboard **one in six**, the rest jutting perpendicular into the room like a shelf divider, or floating outright. A declared-mural piece now requires a wall **along its long axis** (a wall at the *end* of a bookcase pins it, it doesn't back it) and is otherwise **not placed at all** — same refusal as the connectivity check. ⚠ **Never derive this from `bloque_vue`.** The three tall pieces happen to be the three that belong against a wall, and "tall, so it occludes *and* leans" is tempting — but a **pillar** blocks line of sight and stands in the *middle* of a room (René, before it bit). Deriving it would either glue the pillar to a wall or, worse, refuse to place it where it makes sense. Exactly why `bloque_mouvement` and `bloque_vue` were split in the first place: independent facts don't share a column. Splitting the column was not enough — furniture used to land in the same `$occupees` set that `figuresBloquent` tests, so a table went on stopping arrows by that other route. `Grille` therefore keeps **three** sets: `$occupees` (figures only — heroes, monsters, allies, the only one `figuresBloquent` consults), `$obstacles`, `$opaques`. Furniture is scenery, not a figure: it occludes like a wall, unconditionally. The split by **height** (bookcase/weapons rack/cupboard occlude; table/chest/throne/bench/tomb don't) is a **named divergence**, kept by René on 2026-10-01 knowing both sources (`docs/plan-errata-2021.md` C2): Hasbro rules that furniture does **not** block line of sight in the base game, and First Light p. 8 offers an **optional** rule where **all** furniture blocks it. Don't "fix" it toward either. The blocking of **movement**, on the other hand, is now **sourced** — Hasbro confirmed furniture is impassable (`reference/17_mobilier.md` §3).

The hard invariant is connectivity: a badly placed piece cuts access to a chest or a lever and freezes the group. Placement re-checks reachability from a **single** threshold and abandons the piece otherwise (a multi-source BFS misses pockets — every threshold counts as reached merely by being its own source, so a piece sealing one into a 2-cell pocket passes). It rejects ~2.4 placements per dungeon; never in corridors, never in room 0, never on a trap (which would be both invisible and untriggerable). **Each searchable piece has ITS OWN loot table, and is searched once PER HERO** (`mobiliers.effet.fouille`, René's call 2026-08-17 — it overturned both halves of the previous rule). A piece used to draw a card from the *quest's* search deck, once for the whole group: a weapons rack could hand out a healing potion, it burned a card the room searches would have drawn, and the first hero to arrive closed the piece for everyone. Now the chest pays often and can yield a weapon, the throne gives gold only, the alchemist's bench only flasks, the **bookcase is the only piece that yields SCROLLS** (which makes it the caster's furniture), the weapons rack yields weapons and armour. ⚠ **Every table carries a `rien` outcome**, and a test demands it — a piece that always gave something would turn exploration into harvesting and stop gold being a resource. Two pieces **bite**: the tomb (`Aiguille empoisonnée`, 1 Body or poison) and the alchemist's bench (`Fiole de poison` — the catalogue's only trap with no `aleatoire` branch: it poisons outright, which is *harsher*, 3 turns at 1 Body, though resistible where a flat hit never is). Draws are **weighted**, which is what lets a chest pay often and a throne disappoint half the time; and when the issue is `objet` the piece is picked in **two steps** (`App\Engine\RareteButin`, René's call 2026-08-17) — the **rarity** first, weighted by the group's **average level** (commun/peu_commun/rare going 70/28/2 at level 1 to 20/45/35 at level 7+), then the piece uniformly inside it. It used to be uniform over the whole pool: a bench handed out an 800 gp potion as often as a 100 gp one, and a level-8 party kept finding daggers. ⚠ There is **no level cap** (doc 01 §5), so the curve **saturates** at the last band; and only rarities actually **present** in the pool are weighted — a rack with no rare weapon yields what it has rather than nothing. ⚠ The pool also **drops anything nobody present could use** (René's call 2026-08-17): three potions are barbarian-only and two elf-only, so a party without a barbarian spent the campaign finding Battle Rage — loot that is neither playable nor sellable mid-quest, and that eats a bag slot. The filter crosses `tag_equipement` with the masteries of the heroes **engaged in the quest** (`Equipement::tagsAccessiblesAux()`), the same rule the class artefacts already used and now sharing its home with them; an empty list means **no restriction** (fail open). ⚠ Rarity itself is now **derived from price** (≤150 commun · ≤400 peu_commun · above rare) instead of hand-set per row, where it had drifted (a 200 gp hatchet "commun" while a 125 gp wand was "peu_commun"); only artefacts (`unique` is a status, not a price band) and scrolls (rarity = spell difficulty) keep a declared value. issues reuse the deck's vocabulary (`tresor`/`objet`/`piege`/`rien`) so `appliquerButin()` applies them without knowing their origin. ⚠ The **Explorer's Sixth Sense is rewired onto this path**: its card says "when you draw a trap card, put it back and draw another" — it speaks of the *gesture*, not the deck, and leaving it out would have silently nerfed it exactly when furniture became dangerous; on a weighted table, re-drawing is re-rolling. ⚠ `fouille_par` stacks hero ids like `quetes.tresors_fouilles` does for rooms; the legacy boolean `fouille` is still read and still means "spent for everyone", so a quest already in flight keeps its pieces closed rather than refunding a search already used. ⚠ `MobilierSeeder` **keys on `nom` and no longer purges**: `cartes.grille.mobilier[]` stores a `mobilier_id`, so re-seeding mid-campaign used to orphan every piece of an in-flight quest — neither searchable nor blocking, without a single error. And a furniture search publishes **no `deck_restant`**: it never touched the deck.

**Mobilier ATTAQUABLE AU COMBAT — a THIRD way to clear an obstacle, next to the search and the Body-roll smash** (`mobiliers.pv_body`/`defense_dice`, 2026-10-04, René: "un seul lecteur générique « mobilier à PV/défense », commun à Delthrak et Morcar"). `difficulte_destruction` (above) covers one Body roll, limited to one attempt per hero; some sourced pieces are instead fought exactly like a monster — Crystal Cluster ("can be destroyed as a monster […] with 6 Body Points […] cannot defend", *Jungles of Delthrak* F9907 p. 4-5) and the Wizards of Morcar's High Altar / Dread Chest ("may be attacked using normal combat", "rolls 6 Defend dice", G1504 p. 2-3/35/39). The attacker's `personnage.des_attaque` (the column already carrying class/weapon/talents, not `frapper()`'s conditional combat bonuses — nothing in these sources evokes Furie or flanking against a piece of furniture) meets `App\Engine\Combat::resoudreAttaque()` head-on against `defense_dice`, with **no retry limit** — a monster doesn't tire of being hit, and neither does this. ⚠ **Defends with WHITE shields** (`TypeFigurine::Heros`), the one colour a source actually states (Morcar's magical walls, same page: "Barriers defend […] counting the white shields scored") — applied to all three pieces since nothing contradicts it, and the Crystal Cluster has no dice to roll anyway (`defense_dice: 0` is "cannot defend", a real value, not an absence). Current PV live in `cartes.grille.mobilier[i].pv_restants`, lazily initialised to the catalogue's `pv_body` on the first hit — same pattern as `detruit`: state of the GAME, never the catalogue. `MenuMoteur` offers `attaquer_mobilier_{index}` only when `pv_body` is not null (mutually exclusive by data with `detruire_mobilier_{index}`, no source describing both a Body roll AND a combat pool for the same piece); `EtatGroupe.carte.mobilier[]` publishes `pv_body`/`defense_dice`/`pv_restants` — the DECISION, already decremented, never recalculated client-side — `null` for an ordinary piece that carries no health bar. Journalled and staged on the table screen exactly like a blow against a monster (`JournalCombat::attaqueMobilier()`, `SceneDeTable::attaqueMobilier()`), the piece as defender. ⚠ **Stops at the map**: ceasing to block movement/sight and the journal line are this generic reader's whole job — a quest-specific trigger (the Dread Chest's sorcerer lurching to life, the Altar's quest-winning fall) is a gabarit's business, out of scope here.
⚠ **Named gap, partly closed (2026-10-06)**: Morcar's Magic Walls (Wall of Ice/Flame/Stone, same 1 PV / 6 defend dice shape, deliberately **ONE cell** by René's call rather than the deferred `docs/plan-murs-en-aretes.md`) were **not seeded** — nothing cast them, and a catalogue row with no producer is exactly the "reader without a producer" trap this project names and avoids elsewhere (mind damage sat unproduced for months). **Wall of Stone now has its producer**: it is a HERO spell (*Spells of Protection*, one of the three optional repertoires, `docs/regles/sorts-heros.md`), and `MoteurMobilier::poserMurMagique()` is the single point of passage that appends it — live, during the quest — to the exact `carte.grille['mobilier']` array `FabriqueGrille::pour()` already loops; no new blocking/combat reader, only the pose. `AssembleurCarte::MOBILIER_POSE_EN_QUETE` keeps it (and its two still-unseeded siblings) OUT of the generic room-dressing draw, or the same uniform-random `placerMobilier()` that already seeds Haut Autel/Coffre du Dread as dressing would plant an ownerless wall nobody cast. **Wall of Ice (Storm Master) and Wall of Flame (High Mage) remain unseeded**: they are Dread Sorcerer spells, wave 2's job — the catalogue rows would still have no caster. The Basin's other two faces (*Pool of Water*, a room-search alternative that heals; *Bonfire*, damage on crossing without blocking) are likewise deferred: neither is a combat destruction at all, and each needs its own small vocabulary (a search-time choice, a "never end your turn here" terrain rule) that this generic reader does not cover.

⚠ **A furniture entry posed DURING the quest, not at assembly, can land outside any room** — a corridor cell has no `salle` index, same family of bug as the corridor levers (2026-08-27, "on en déduisait la salle par les coordonnées"). `EtatGroupe::mobilier()` used to publish a piece only when its *room* was discovered; it now falls back to the cell's own fog state when `salle` is `null`. Anyone adding a second "posed mid-quest" furniture layer should read this before assuming room-based fog is enough.

**One silhouette per family on the map, and a legend behind an info button** (René, 2026-08-27). The board carries six families of marker, and they share a room: figures are **round**, traps **square**, épreuves a gold **lozenge**, levers a blue **octagon**, furniture a filled block over its footprint. The épreuve marker first shipped as a 72 % gold disc — the exact silhouette of a hero token, and on the table it read as a fourth figurine standing between two heroes. ⚠ **The `leviers` layer was published NOWHERE**, so no lever was ever drawn on either map. That cost nothing while none were ever placed; it costs a locked dungeon now that forcing one takes a Body roll and a room may hang on that door alone — the option only appears on contact, and nothing said where to go looking. Levers carry no room index (`{x, y, levier_id}`, the original format), so the fog filter **derives** it from the coordinates; a **corridor** lever is always shown, since a corridor has no room index and is never "discovered". Its published `difficulte` goes through `DifficulteBody::plafonnee()` exactly as `MenuMoteur` does — publishing the raw value would print "difficulty 3" on a map whose menu offers 2. ⚠ **Illustrations go in the legend, never on the map**: at 22 px on the controller a picture turns to mush, and it would erase the silhouette, which is what the player reads at a glance — the shape says the *family*, the image says the *content*. Traps and épreuves had published an `image_url` all along and it was displayed **nowhere**; **furniture had none at all** — no prompt template, no generation, no field — though a piece is searched and smashed exactly as a trap is disarmed. `config/images.php` gains the `mobilier` template, `BibliothequeImages::urlMobilier()` the accessor, `images:generer --type=mobiliers` the iteration, `EtatGroupe.carte.mobilier[]` the field, and `PlaceholderController` its emblem (a top slab on two legs — a chest or a throne would have named ONE piece where the emblem stands in for all eight). **Levers and doors are illustrated too** (2026-08-29): they are the only two room elements with **no catalogue table** — a lever is just an id in the grid, a door an edge — so they are named like the **classes**, by a fixed label (`catalogue/leviers/levier.png`, `catalogue/portes/{etat}.png`) rather than `{id}-{slug}`; there is no row to number. ⚠ **One image per door STATE** (closed, open, locked, secret): the state is what carries the information, and a single picture would make a barred door and a hidden passage indistinguishable. `config('images.portes')` is keyed on `MoteurPortes::ETAT_*` and confronted with it **both ways** by a test — a state added to the engine with no illustration would fall silently back to the SVG emblem, and an orphan entry would generate a picture nothing ever fetches. Icons live in `resources/js/components/carte/symboles.js`, read by **both** the renderer (`DungeonGrid`) and the legend (`LegendeCarte`): a legend kept apart from the rendering goes stale at the first symbol added, and then it lies with a legend's authority. `SymbolesCarteTest` confronts that table with the catalogue **both ways** for traps, épreuves and furniture, and checks that both components import the one file. The legend lists only what is **on this map** — a legend describing seven traps for a room holding one reads as documentation, and stops being opened.

⚠ **A room may now hang on a single SECRET DOOR** (René, 2026-08-24) — objective room and artefact chest included. `AssembleurCarte::secretiserUneAreteDArbre()` makes **one** spanning-tree edge secret (a leaf, never the one leaving room 0) and **drops any loop that would serve that room** — without that last step the feature fired in **5 dungeons out of 40**, because compact placement gives most leaves a second connection. ⚠ It then fires **one dungeon in two** (René, 2026-08-27): an eligible leaf always exists, so without a draw it landed in 40/40 and « there is always a hidden passage » would have become a rule players learn — and systematic sweeping with it. ⚠ The rate is not fixed but a **pity counter** carried by the group (`groupes.chance_passage_secret`, written by `DemarreurQuete` on every quest start): **50 %** base, **+10 points** per map that came up dry, back to **50** as soon as one is placed. A pure 50 % draw can leave a whole campaign without a single passage, and such a streak does not read as chance — the party concludes the feature does not exist and stops searching. The worst case is bounded by construction (50→60→70→80→90→100, so never more than five dry maps); measured over 300 maps: 57 % carry one, longest dry streak 4. ⚠ The draw is taken **before** the edge is chosen and consumes a PRNG step **in both branches**: drawing only on the secretising path would make the number sequence diverge per branch, and two dungeons of the same seed would stop being identical. ⚠ The counter lives in a **column, never in cache** — losing it would silently reset the campaign to 50 %, which is exactly what it exists to prevent; and `assembler()` returns `passage_secret` because the caller **cannot** deduce it from the doors (a supplementary link is `secrete` too, and hides nothing). The old invariant « never dependent on a secret door » is **removed** from `CouloirsTest`: its justification (« a failed search would strand the group ») is obsolete since « Fouiller la zone » is offered **every turn with no limit** and `battre_en_retraite` (2026-08-21) has **no condition**. ⚠ Consequence to state, not hide: `donjon_nettoye` counts active monsters **revealed or not**, so an undiscovered room keeps `quitter_donjon` closed — the way out is the retreat vote, and a quest can now be lost for not having searched.

**Une couche TERRAIN, et le sol cesse d'être neutre** (`terrains`, `cartes.grille['terrain']`, 2026-09-06). Les quatre couches existantes — pièges, leviers, mobilier, épreuves — répondent toutes à « qu'y a-t-il ICI ? ». Aucune ne répondait à « que **coûte** cette case, et que se passe-t-il quand je la traverse ou que j'y reste ? ». Sept tuiles sourcées de *The Frozen Horror* (doc 18 §4) : glace glissante, glissière, rivière gelée, tunnel, chambre forte, glace magique, rebord de crevasse. Posée sur le patron **exact** de `placerMobilier()` — aucun type de case nouveau, aucune migration de tuile — et lue dans la **même** méthode `FabriqueGrille::pour()`, jamais une seconde : deux boucles feraient diverger déplacement, ciblage et ligne de vue. ⚠ Un terrain n'est pas une figure : `obstruer()` / `occulter()`, **jamais** `occuper()`.

⚠ **Son vocabulaire d'effets est FERMÉ, comme les autres** : `App\Engine\MotsClesTerrain`
(2026-09-10). Il a pourtant vécu deux jours sans figurer dans **aucune** liste de
vocabulaires — ni `CLAUDE.md`, ni la skill `ajouter-element-de-jeu`, ni ici — alors que
ces listes sont précisément ce qu'on consulte pour savoir si un effet doit passer par la
case « mécanique d'abord ». Corrigé le 2026-09-12. ⚠ Une liste de vocabulaires fermés
**incomplète est pire qu'absente** : elle donne l'assurance d'avoir vérifié. L'inscrire
aux trois endroits fait partie de la création d'un vocabulaire, au même titre que son
test dans les deux sens (`TerrainEnJeuTest`).

⚠ **Le gabarit dit COMBIEN, le thème dit LESQUELS.** Les sept tuiles portent `terrains.boite = 'horreur_des_glaces'`, et `placerTerrains()` ne retient que `boite === null` (convient à tout thème, comme pour le bestiaire) ou la boîte du groupe. Sans cette seconde moitié, déclarer `structure.terrains` aurait mis une **rivière gelée dans une quête de jungle** : le thème serait resté une affaire de bestiaire pendant que le sol, lui, aurait été glacé partout.

**LA JUNGLE DE DELTHRAK : cinq terrains, un cocon, un piège** (livret F9907 p. 4-5, relu à l'image, 2026-10-09 ; contrat : `docs/contrat-api.md` §« Jungles of Delthrak — la carte »). Tout sous `boite = jungles_delthrak`, rien sans ce thème.
- **Terrain gênant** (sable, toile, jungle : « costs heroes and other creatures, including monsters, 2 squares of movement »). Trois entrées, **une** règle (le livret ne les distingue que par l'image) : `cout_deplacement: 2` + `effet.entravant`. ⚠ `entravant` est un **fait** et pas un coût : trois sources le lèvent — **Agile** (`Grille::autoriserFranchissement()`), le talent `ignore_terrain_entravant` (*Ronces complices*, **étendu** : il ne coupait que racines et chausse-trappes), et les *Bracers of the Wild* (agent artefacts) — **sans** lever la Rivière gelée, qui coûte 2 aussi. UN point de levée (`Grille::ignorerTerrainEntravant()`), UN point de question côté héros (`MoteurSorts::terrainEntravantIgnore()`, relu par le résolveur ET par `EtatGroupe`, qui publie la décision à la manette). Le miroir de coût de `DeplacementSheet.vue` applique la décision, il ne la recalcule pas.
- **Le Basin en trois entrées** (René, Q5) : *Pool of Water* → **Mare**, *Brasier*, et l'*Amas de cristal* (mobilier attaquable, inchangé). Mare et Brasier sont des **terrains**, pas du mobilier : ni l'un ni l'autre ne bloque (« may move through », « does not block line of sight »), et un effet de franchissement qui ne bloque rien se lit où se lisent déjà la rivière et la glissière — le mobilier est la couche de ce qui occupe ou bloque. Le critère « bloque-t-il ? » tranche, pas le nom de la figurine.
- **`interdit_arret`** (Mare, Brasier : « may not end their turn occupying the same space ») : on passe, on ne finit pas dessus — **toute créature**. Un jeu de cases à part dans `Grille` (`interdireArret()`), jamais `$alliees` (les figures bloquent la vue, pas une mare) ; **UNE** question de destination, `Grille::arretInterdit()` (figures **et** terrain), relue par `casesAtteignables()`, le résolveur du héros, le choix de case du monstre (`derniereCaseOuSArreter()`, charge, vol draconique), l'allié. ⚠ Une troncature (piège, racines…) peut encore laisser un héros sur une Mare : c'est un arrêt subi, pas une destination choisie — nommé, non corrigé.
- **Brasier** : « Any creature who moves through » lance 1 dé de combat, crâne = −1 Body, nature `feu` (le « Bonfire » du livret : « le feu inflige 1 Body Point », `reference/18` §4). Chaque case entrée passe d'abord par `MoteurSorts::absorbeDegat($heros, 'feu')` : seule une immunité au **feu** l'arrête — l'Anneau de Feu (une charge par case couverte), la Chair impie, la potion de résistance au feu. L'**Anneau de Chaleur** couvre le **froid** : il ne protège **pas** du Brasier. Il emprunte le lecteur de la Rivière gelée (`ResolveurTour::saignerSurRiviere()` : `jet_des_combat` + `sur` + `degats_pv_body`, un jet par case entrée, jamais d'arrêt) — la donnée change la face (`crane`), pas un second lecteur. Différence voulue : le **monstre** brûle (`blesserMonstreSurLeChemin()`), la rivière l'épargne (`ignore_par_monstres`). Chaque jet est publié (`terrain_jets`) et annoncé, dégâts nuls compris ; une case couverte par une immunité ne lance aucun dé et n'est **pas** journalisée (écart à la règle « un effet automatique s'annonce », constaté en vérifiant ce paragraphe). ⚠ **Non porté : l'allié mercenaire** (aucun point de passage de dégâts d'allié ; nommé, pas oublié).
- **Mare** : « If a hero searches for treasure in an area containing a pool of water, they may choose to restore 1 lost Body Point instead of drawing from the treasure deck. » Option `boire_mare` au même endroit que `fouiller_tresor`, sous les mêmes conditions, **et seulement si le héros a perdu un point** (le menu n'offre pas ce que le résolveur refuse). Elle dépense la fouille du héros dans la salle, ne tire aucune carte et laisse le coffre désigné aux compagnons. Lue en un point : `MoteurTerrain::soinALaFouille()`.
- **Plancher de jouabilité** : une Mare/un Brasier ne coupe rien, mais personne n'y finit son tour — `placerTerrains()` tient donc le plancher `CASES_JOUABLES_MINIMUM` sur les cases **restantes** (mobilier bloquant + mares/brasiers déjà posés retirés), jamais un plafond de nombre ; la pose est abandonnée plutôt que forcée.
- **Jamais sur un PARVIS** (partie de vérification du 2026-10-10) : une Mare ou un Brasier n'est **jamais** posé sur une case orthogonalement voisine d'une porte (ses deux cases), d'un levier ou d'une épreuve — c'est de là qu'on les ouvre, tire ou tente, au contact. La porte (21,31) n'avait que le Brasier (21,30) pour parvis : la salle était connexe, la porte inouvrable, et rien ne le disait. Le plancher compte les cases, pas leur **place** ; un terrain gênant (qu'on peut tenir) reste permis, un terrain bloquant passe déjà par la BFS. `TerrainJungleTest` épingle l'invariant sur 60 cartes.
- **Cocon** (mobilier, `effet.detruit_par_action`) : « A hero adjacent to a cocoon can spend an action to destroy it. Cocoons block line of sight and cannot be moved through. » **Quatrième voie** de destruction, distincte du jet de Body, du combat à PV et de la fouille : une action, **aucun jet**, aucune tentative à compter. ⚠ **Aucun butin inventé** : « may contain treasure or deadly surprises » relève des notes d'une quête imprimée, pas de la règle du composant ; le « butin progressif » de l'ancien plan n'a aucune source. `mobiliers.effet` a désormais un vocabulaire fermé (`App\Engine\MotsClesMobilier`, testé dans les deux sens).
- **Piège de lianes** (*Grasping Vine Trap*) : piège de sol ordinaire pour la fouille, le saut et le désamorçage (le livret n'en dit pas d'autre). Sur le pas : 1 dé de combat ; **bouclier** → rien, le héros continue (`piege_esquive`, jamais dans `declenchements` — le résolveur ferme le tour de tout héros qui en a un) ; **crâne** → −1 PV, condition *Immobilisé*, tour clos, état de piège `retient`. La sortie est l'action **déjà existante** « Détruire les entraves » (*Étreinte des Ronces*, mêmes mots sur la carte) : un seul résolveur, qui retire en plus le piège (`MoteurPieges::libererLianes()`). ⚠ `usage: unique`, **jamais** `persistant` : `estFosse()` (Forme démoniaque, Potion de dextérité) lit `persistant` + `franchissable`, et des lianes n'en sont pas une.

**LES SABLES MOUVANTS : le premier terrain qui BLOQUE la marche** (*The Mage of the Mirror* F7539 p. 29, quête 8 note C, relu au livret, René 2026-10-15 ; contrat : `docs/contrat-api.md` §« The Mage of the Mirror — les sables mouvants »). Un terrain du catalogue, `boite = mage_du_miroir`, lu par la **même** `FabriqueGrille::pour()` — pas une couche à part : `bloque_mouvement` ferme la marche, et la clé `saut_par_dessus` range en plus la case parmi celles où l'on ne finit pas son mouvement (`Grille::arretInterdit()`). Donc ni passage ni arrêt pour le Dijkstra. Trois clés du vocabulaire fermé (`saut_par_dessus: 2`, `saut_reussi_sur: bouclier_noir`, `enlise: 2`), toutes lues par `MoteurSables` — c'est lui, pas `ResolveurTour`, qui porte la règle.
- **Le saut** est une option du MENU moteur (`sauter_sables_{x}_{y}`, créneau de mouvement), offerte depuis une case voisine **seulement** s'il y a, dans ce sens, exactement 2 cases de sable puis une case de réception libre (`MoteurSables::sauts()` — le menu et le résolveur lisent la MÊME liste : « le menu ne propose jamais ce que le résolveur refusera ») et de quoi payer 3 points. Le long de la bande (file de plus de 2 cases) il n'est pas offert : le livret ne décrit pas de saut par-dessus plus de deux cases. Un dé de combat ; bouclier noir = atterrissage en face ; tout autre résultat = le héros s'enlise sur la première case de sable libre de sa ligne.
- **L'enlisement est durable** : `etat_personnage_quete.enlise` (colonne, jamais le cache ; porté par le snapshot). Phase `defausse` : menu FORCÉ, une option qui republie la liste blanche après chaque pick — le héros CHOISIT ses deux objets (le livret dit « any two items » ; `reference/18` et le plan disaient « au hasard », corrigé). Le dernier abandon clôt le tour. Phase `sables`, au tour suivant : menu FORCÉ, un seul geste, se hisser sur la case d'en face, ce qui clôt le tour. Le résolveur refuse tout autre geste (422).
- **Ce que le livret ne dit pas, tranché et écrit dans `MoteurSables`** : (1) le saut coûte **3** points (2 cases de sable + la réception), par analogie avec la Fosse (héros → fosse → réception = 2) ; (2) un « objet » est toute **unité** d'inventaire, **portée ou non** — le livret nomme armes et armures, et un héros qui porte tout sur le dos doit pouvoir payer (plus large que l'action `jeter`, volontairement) ; l'or n'est pas un objet ; (3) **moins de deux objets** : il ne peut pas « avoid death » — il tombe à **0 PV, à terre et relevable** (la mort définitive est refusée dans ce jeu), et garde l'objet qu'il avait, le sacrifice ne l'ayant pas sauvé ; (4) case de sortie occupée : il reste enlisé (menu = « Terminer le tour »), rien n'est dit ; (5) les monstres : le sable leur est infranchissable comme à tous, rien n'est dit de ce qu'ils y font.
- **Placement — l'écart assumé au livret** : l'imprimé *coupe* la salle en deux (« Dividing the room ») et chaque héros doit sauter. Ici `placerTerrains()` ne coupe **jamais** : une bande de 2 rangées collée à un mur, un contournement d'une ou deux cases, donc un saut qui est un **raccourci risqué**, pas un péage (« connexe n'est pas jouable », §2.12 ter). Comptage par `structure.terrains.sables` (`min`/`max` de BANDES, 0..1 aux quatre gabarits) ; jamais salle 0, jamais sur une autre couche, jamais sur un PARVIS (porte, levier, épreuve) ; il reste une rangée de sol de chaque côté et au moins une ligne de saut réelle ; plancher `CASES_JOUABLES_MINIMUM` sur les cases restantes et connexité depuis UN seuil (`salleResteConnexe()`) — pose **abandonnée** plutôt que forcée. Posée en **dernier**, après les tunnels : une carte sans ce thème consomme exactement les mêmes tirages qu'avant. `SablesMouvantsTest` l'épingle sur 60 graines.
- **Publication** : `carte.terrain[]` (brouillard inchangé, `avantages` dits par le serveur) et `entites[].enlise` (`{a_defausser, vers, terrain}`), d'où un badge table ET manette. Teinte `sables` (hachure **croisée**, les autres terrains n'en ont qu'une) dans `symboles.js` + légende.
- **Non traité, nommé** : un héros enlisé qu'une condition de contrôle (*Commandé*, *Possédé*) déplace de force quitte la case sans payer ; la réception d'un saut ne contrôle pas les pièges cachés (comme la fosse).

**Le MUR DE GLACE est enfin publié, et le trajet se voit avant d'être joué** (2026-09-17). La couche `cartes.grille['glace']` — posée en cours de quête par le sort du boss, jamais par `placerTerrains()` — était lue par `FabriqueGrille::pour()` (elle remplit `$obstacles`) et publiée **nulle part** : invisible sur les deux cartes, inconnue du calcul d'accessibilité de la manette, elle faisait refuser une destination que le joueur avait le droit de croire libre. C'est **mot pour mot** la faute des leviers de 2026-08-27, avec une variante à retenir : une couche posée **en cours de partie** échappe à la checklist d'assemblage. `EtatGroupe.carte.glace` la publie désormais (avec `cranes`, 5 la brisent), filtrée par le même brouillard que les leviers et le terrain, et `DungeonGrid` la rend en **bloc plein glacé** — la silhouette du mobilier bloquant, parce qu'elle se lit pareil.

**Le VOILE D'OMBRE : une zone durable qui coupe la vue ET les coups** (*Cloak of Shadows*, Wizards of Morcar, 2026-10-08). Couche DÉDIÉE `carte.grille['ombre']` — un rectangle 3×2 (ou 2×3) par voile, `{x, y, l, h, jetons, lanceur_id}` — posée en cours de quête par `MoteurOmbre::poser()`, sur le patron exact de la glace (couche de sort, pas un catalogue). Deux règles, deux lectures uniques. **La vue** : `FabriqueGrille::pour()` seule, qui range les cases dans `$opaques` (« through ») ET dans `Grille::assombrir()` (« into » : une extrémité sous l'ombre ne se voit pas, et comme `ligneDeVue()` est symétrique une figure dedans ne voit rien dehors) — jamais dans `$obstacles` (on marche dessous) ni `$occupees` (ce n'est pas une figure). **« Ne peut ni attaquer ni être attaqué »** : `MoteurOmbre::contient*()`, lu par `MoteurSorts::attaqueInterdite()` (le prédicat unique de la vague 1, qui dit désormais la VRAIE cause via `raisonAttaqueInterdite()`), `estInattaquable()`, `ciblesPourArme()`/`ciblesBalayees()`/`frapper()` pour le monstre visé, `Rayon::cases()` (le rayon traverse, il ne frappe pas ce qui est dessous) et `jouerMonstre()` (`monstre_dans_l_ombre` : il peut marcher, pas frapper). ⚠ **La taille est MESURÉE** sur le livret G1504 p. 4 (le grand carton fait 2×3 cases à 36 pt la case, par comparaison avec la tuile Tremblement de terre et celle du Laboratoire) : aucune carte ne la donne. Compteur de 3 jetons, un de moins au début du tour du lanceur (marqueur `ombre_decompte` dans `capacites_tour`, remis à zéro chaque round ; un lanceur tombé le perd à l'ouverture du round). Publiée dans `EtatGroupe.carte.ombre` AVEC son compteur, rendue en zone sombre translucide + chapelet de pastilles (`DungeonGrid`), légende dédiée. Détail et décisions : `docs/regles/sorts-heros.md`.

⚠ **Le TRAJET a des conséquences, il se montre donc avant d'être joué** (René, 2026-09-17 : « que la figure utilise le vrai chemin »). `MoteurPieges::controlerChemin()` contrôle les pièges **case par case** sur le chemin, or le joueur ne désignait qu'une destination : la route était choisie par le serveur et découverte à l'animation. `POST deplacement/apercu` rend le chemin **exact**, calculé par `ResolveurTour::grilleDeplacement()` — la grille même que le déplacement parcourra — et la manette le dessine avec les pièges DÉJÀ connus qu'il traverse. ⚠ Il ne rejoue ni les troncatures ni le contrôle des pièges : un trajet écourté à l'écran dirait « il y a un piège caché ici », et l'aperçu deviendrait un détecteur gratuit. ⚠ Et il ne consomme rien — `pointsDeplacement(..., consommer: false)` : regarder où l'on irait ne doit pas brûler le buff de Vent Véloce.

⚠ **La Rivière gelée a fait passer le déplacement en COÛT PONDÉRÉ** (2026-09-06). `casesAtteignables()` et `chemin()` sont désormais un parcours de Dijkstra ; **`distance()` reste géométrique**, et cette distinction est tout. Elle sert à la **portée** et à l'**adjacence** : la flèche d'un arbalétrier n'est pas ralentie par la glace, et deux cases voisines restent adjacentes même si l'une coûte 2. Les confondre aurait **raccourci la portée de toutes les armes à distance** dès qu'une case coûteuse traînait sur la ligne — un défaut qui ne se serait vu qu'en partie, sur un tir qui rate sans raison. ⚠ Corollaire trouvé dans la foulée : tout ce qui budgétait en `count($chemin)` comptait des **cases** là où il fallait des **points** (`MoteurDread` pour la charge des monstres, le déplacement des alliés) — d'où `Grille::coutChemin()` et `Grille::pasAffordables()`, et un seul point de passage pour la question.

⚠ **La manette tenait son PROPRE parcours, à coût uniforme.** `DeplacementSheet.vue` refait en JS le calcul des cases atteignables, et ne consultait pas `cout_deplacement` que le serveur publiait pourtant. Elle aurait éclairé une case de rivière comme atteignable et le serveur l'aurait refusée — un refus sans explication, exactement ce que « le menu ne propose jamais ce que le résolveur refusera » interdit. Le miroir client est pondéré à son tour. **Tout miroir client d'une règle serveur est une seconde copie de cette règle** : il dérive dès que la règle bouge, et il faut le chercher.

**AUCUN LEVIER N'AVAIT JAMAIS ÉTÉ POSÉ** (corrigé le 2026-09-06, arbitrage de René). `placerLeviers()` exigeait que le **gabarit** fournisse `x`, `y` et `levier_id` tout faits — or la carte est générée à l'exécution, aucun gabarit ne pouvait connaître ces coordonnées, la méthode rendait `[]` à tous les coups, et le verrou des portes avait la même maladie. Pendant ce temps **toute la consommation fonctionnait** : `MoteurPortes::leviersAdjacents()`, l'option `actionner_levier`, `resoudreActionnerLevier()`, la publication filtrée par le brouillard. Du code écrit, testé, et jamais atteint une seule fois en jeu — la forme la plus coûteuse du défaut « un champ qui marche mais que personne ne remplit ». La pose est désormais **procédurale**, comme les quatre autres couches.

⚠ **Les leviers ne sont PAS du contenu thématique** (René, 2026-09-06 : « les leviers devraient être disponibles pour toute quête, alors que les éléments de froid ne devraient être présents que si la thématique est liée »). Pas de colonne `boite`, pas de filtrage — dans tous les gabarits. C'est la distinction entre du **mobilier de donjon**, qui appartient au jeu, et du **matériel de boîte**, qui appartient à un thème.

⚠ **L'invariant du levier** : il doit être atteignable depuis la salle de départ **sans franchir la porte qu'il verrouille**, sinon il faut le levier pour aller au levier. Vérifié par un parcours réel, la porte retirée du graphe, sur des dizaines de graines — comme `terrainCasseraitConnexite()` le fait pour le terrain, et on **renonce au verrou** plutôt que de le forcer. En revanche une salle **peut** tenir à un seul levier : le forcer demande un jet de Body réessayable sans limite, ce qui l'empêche de jamais se refermer.
**Un passage secret TROUVÉ est une porte FERMÉE, pas une porte ouverte** (René, 2026-09-11 : « un passage secret trouvé devrait l'afficher comme une porte fermée, on peut maintenant interagir avec pour l'ouvrir »). `MoteurPortes::revelerSecretes()` écrivait `ETAT_OUVERTE` : **trouver et franchir étaient un seul acte**. Or toute ouverture de porte révèle la salle derrière — donc un unique jet de Mind rendait la porte, la pièce ET son coffre, sans qu'on ait eu à s'en approcher. Les trois chemins de révélation (fouille, `detection_portes_secretes` du nain et de l'explorateur, Potion de Vision) passent par la **même** méthode et changent ensemble. `revele` reste posé : l'état n'est plus `secrete`, donc le déguisement en mur ne s'applique plus, mais le drapeau garde la trace de ce qui a été **trouvé**.

⚠ **Et avant d'être trouvé, c'est un MUR — pas un trou** (régression signalée en partie réelle le 2026-09-10). Les murs de ce jeu sont des **cases**, jamais des arêtes, et une porte secrète relie deux cases de **sol**. La retirer du payload, comme le faisait le correctif du 2026-09-04, ne laissait plus rien à dessiner entre elles : le front lisait deux sols voisins et affichait **une ouverture**, que `Grille::porteBloqueEntre()` refusait pourtant de laisser franchir. Le cas qui le rend visible est celui des **liaisons supplémentaires** (`AssembleurCarte::liaisonsSupplementaires()`, les boucles) : les deux salles qu'elles relient ont chacune leur accès normal, donc les deux côtés sont déjà explorés — **le brouillard n'a rien à masquer, et l'affichage de la porte était la seule chose qui disait « c'est bloqué »**. Elle est désormais publiée en `mur`, sans `secrete`, sans `revele`, sans `verrou`, sans image. ⚠ `'mur'` n'est **pas** un état de `MoteurPortes` : c'est un déguisement posé à la publication, jamais écrit en base — ce qui laisse intact le test qui confronte les états de porte à leurs images dans les deux sens. Le rendu est une arête **pleine et opaque**, sans jambages ni battant doré : un mur qui ne ressemble à aucun autre mur serait une pancarte « cherche ici ».
⚠ **Ce déguisement est RETIRÉ le lendemain** (2026-09-11, voir le paragraphe ci-dessous « La porte bloque sa CASE ») : une fois que la porte bloque une vraie case plutôt qu'une seule arête, cette case PEUT se peindre en roche pour de vrai — plus besoin d'un état `'mur'` fabriqué à la publication. Le principe qui a motivé ce paragraphe (« un passage secret non trouvé doit se lire comme un mur, pas comme un trou ») reste entier ; seule son implémentation change.

⚠ **`levier_id` n'est PAS publié** (René, 2026-09-11). Il l'était, alors que la **porte** n'annonce que le *type* de son verrou (`verrou: 'levier'`) et jamais lequel l'ouvre : le joueur lisait des identifiants qui ne se raccordaient à rien — **la moitié d'un appariement, pire que zéro ou que deux**. On découvre quel levier ouvre quelle porte **en l'actionnant**, retentable sans limite. L'appariement reste serveur, où il est re-validé : il n'a jamais eu besoin de passer par le client. ⚠ Le test correspondant vérifie l'**absence** de la clé — c'est la seule façon de verrouiller une décision de *ne pas dire* quelque chose, sans quoi un futur passage la republierait en croyant combler un oubli.

**Les salles s'ACCOLENT par défaut — le couloir n'est plus l'entre-deux systématique** (René, 2026-09-11, après avoir joué : « tu mets toujours une case pour relier 2 salles, mais dans le jeu original il n'y en a pas »). `AssembleurCarte::accolerSallesMitoyennes()` glissait déjà les salles-**feuilles** mur contre mur avec leur parente, produisant un seuil direct au lieu d'un couloir — mais seulement **une feuille sur deux** (tirage `$suivant() % 2`), **jamais** sur une arête déjà **secrète**, et jamais sur la salle 0. Le tirage disparaît : chaque feuille éligible est désormais accolée, ce qui en fait la **norme** plutôt qu'une surprise ponctuelle — mesuré sur 200 cartes du gabarit courant, 22,7 % des arêtes de l'arbre couvrant (celles réellement candidates) le deviennent, et aucune carte ne perd la totalité de ses couloirs. Deux garde-fous restent **inchangés, pour les raisons d'origine** : jamais la salle 0 (elle accueille tout le groupe et sert de repère), et `chevaucheUneSalle()` — on **renonce**, jamais on ne force un accolement qui ferait empiéter deux salles. Le troisième, l'exclusion des arêtes **secrètes**, est **levé** : depuis le correctif du 2026-09-10 ci-dessus (une porte secrète non trouvée se peint comme un mur ordinaire), un passage dérobé directement dans un mur partagé entre deux salles voisines n'a plus besoin d'un couloir pour se cacher — c'est même **plus fidèle au plateau**, où un passage secret est typiquement un pan de mur entre deux pièces, pas la porte d'un cul-de-sac. La restriction « feuilles seulement » **reste**, elle, purement géométrique : décaler une salle qui a plusieurs arêtes désaligne les couloirs de ses AUTRES jonctions (toute la géométrie des slots uniformes repose sur l'alignement des médianes) — hors périmètre d'une correction de comptage de portes.

⚠ **Une jonction mitoyenne ne pousse plus qu'UNE porte, pas deux** (même date). `creuserArete()` perçait jusqu'ici la sortie de la salle gauche ET l'entrée de la salle droite même quand les deux salles étaient déjà mur contre mur — deux battants encadrant l'unique case de seuil que produit l'accolement, quand le plateau n'en montre qu'un. La porte côté **parent** (celle qui porte déjà la restriction — verrou, secrète) est seule conservée ; l'arête qu'aurait porté l'ancienne porte enfant reste simplement absente de `portes[]`, ce qui suffit à la rendre franchissable sans restriction (`Grille::porteBloqueEntre()` ne bloque que ce qui est déclaré). `placerLeviers()`, qui indexait la porte-parent de chaque arête par `2 * $indexArete` (deux portes par arête, pas fixe), aurait dérivé dès la première jonction mitoyenne rencontrée : `assembler()` note désormais la position RÉELLE de chaque porte-parent au moment où il la pousse, et la transmet toute faite plutôt que de la recalculer.

**Le contour de salle a été posé le 2026-09-11, puis RETIRÉ le jour même** (René). Il répondait à « on ne voit pas où une salle s'arrête » — mais entre-temps la roche avait pris sa propre teinte (voir plus haut : brouillard 0.10 < roche 0.115 < page 0.16 < sol 0.26-0.30), et c'est ce contraste qui fait tout le travail. Le trait, posé sur l'anneau de mur donc dans le sombre, n'ajoutait plus grand-chose. ⚠ Retiré AVEC sa variable `--dg-cell`, dont il était le seul lecteur : une variable CSS que personne ne lit est la même décoration qu'une clé sans lecteur. Les deux reviennent en quelques lignes si le besoin se représente — c'est écrit ici pour qu'on sache que l'absence est un choix, pas un oubli.

**La porte bloque désormais sa CASE, en plus de son arête** (René, 2026-09-11, après avoir joué : « si on laisse 1 case d'épaisseur pour les murs, est-ce qu'on peut faire en sorte que la porte soit centrale à sa case, bloquant l'entrée dans sa case tant que la porte n'est pas ouverte ? Présentement la porte est sur le rebord d'une des 2 extrémités et je trouve ça déséquilibré »). Les murs restent des **cases** (ce n'est PAS le chantier « murs en arêtes », `docs/plan-murs-en-aretes.md`, décidé mais reporté à froid — n'y touche pas) ; ce qui change est que l'arête d'une porte n'était protégée que d'UN côté. Diagnostic établi sur une carte réelle : pour la porte `(29,30)` côté `e`, la colonne x=30 reste un MUR à la ligne au-dessus (y=29) — c'est donc `(30,30)`, PAS `(29,30)`, qui est l'**embrasure**, la case que le mur a réellement cédée pour ouvrir le seuil. Or `AssembleurCarte::creuserArete()` ne pousse qu'**une seule porte par bout de jonction** (« un seuil fait une case », 2026-08-08) : l'arête `29|30` (le pas venu du couloir) était bien bloquée, mais l'arête `30|31` (le pas venu de L'INTÉRIEUR de la salle) ne l'a jamais été — **rien n'empêchait un héros de se tenir dans une embrasure close**, en y entrant par la salle. C'est exactement ce que corrige `Grille::caseEmbrasure()`, alimentée par `carte.salles` (mur compris) : parmi les deux `casesPorte()` d'une porte, celle qui tombe sur l'ANNEAU DE MUR d'une salle (son bord, x ou y sur l'une des quatre limites du rectangle — jamais son intérieur, qui appartiendrait trivialement au rectangle sans jamais avoir été un mur) est l'embrasure. `Grille::estTraversable()`/`ligneDeVue()` la traitent alors exactement comme elles traitent déjà `'m'` : inoccupable et opaque tant que l'état n'est pas `ouverte`, DES DEUX CÔTÉS — un jeu de cases DE PLUS à consulter (`$porteParCase`), l'algorithme de ligne de vue ne change pas. `ouverte`, elle redevient un sol ordinaire : on la traverse, on voit à travers, et la salle derrière se révèle (comme toute ouverture — arbitrage confirmé, René 2026-09-11). Aucun coût de déplacement n'est introduit : l'embrasure est déjà du sol, déjà foulée. ⚠ **Corollaire nécessaire, pas optionnel** : `MoteurPortes::porteFermeeAdjacente()` testait jusqu'ici « le héros se TIENT sur l'une des deux `casesPorte()` » — un test qui suppose qu'on PEUT s'y tenir. Puisque ce n'est plus vrai côté embrasure, il teste désormais l'**adjacence géométrique** à l'embrasure (distance de Manhattan = 1) : sans ce changement, toute porte dont l'embrasure tombe côté salle aurait cessé d'être ouvrable depuis l'intérieur — personne n'aurait jamais pu s'approcher assez près.

⚠ **Le passage secret non trouvé devient une case de ROCHE, pas un mur déguisé** (même date, remplace le paragraphe « et avant d'être trouvé, c'est un MUR » ci-dessus). Une fois que la porte bloque une vraie case, cette case peut se peindre en roche POUR DE VRAI : `EtatGroupe::carte()` force `cases[embrasure.y][embrasure.x] = 'm'` pour toute porte `secrete` non `revele`, **avant** l'application du brouillard (pour qu'elle en suive exactement les mêmes règles d'affichage — silhouette d'un mur qui borde une case visible, mur qu'un héros touche…), et `portes()` ne publie **plus aucune entrée** pour elle — ni `mur`, ni rien. Le déguisement `etat: 'mur'` du 2026-09-10 est **retiré**, avec le battant `.dg-door.mur` de `DungeonGrid.vue` qui le dessinait : la case fait tout le travail, il n'y a plus de troisième état d'affichage à maintenir. ⚠ Conséquence attendue et VOULUE, pas un oubli : l'ancienne « distinction résiduelle assumée » (un mur de roche n'a aucune entrée dans `portes[]`, un mur-déguisement en avait une) **disparaît** — la porte secrète non trouvée est maintenant rigoureusement indiscernable d'un mur, y compris en comptant les entrées de `portes[]`. `PorteSecreteAffichageTest` le vérifie dans les deux sens.

**Le rendu se déplace de l'arête vers la case** (même date). `DungeonGrid.vue` dessinait le battant en pourcentage sur la CLOISON entre deux cases ; il se dessine maintenant CENTRÉ dans la case d'embrasure, comme un bloc de mobilier (occupant le plus clair de la case, orienté selon `cote` pour indiquer quel mur est percé). L'embrasure est publiée par le serveur (`EtatGroupe::portes()` ajoute `embrasure: {x, y}` à chaque entrée, via `Grille::caseEmbrasure()`) plutôt que recalculée côté client — sans quoi la manette (`DeplacementSheet.vue`, qui tient son PROPRE parcours pour la surbrillance des cases accessibles) et la table auraient chacune porté leur copie de cette règle géométrique, vouées à diverger. `DeplacementSheet.vue` exclut désormais du BFS toute case d'embrasure non ouverte, en plus de l'arête qu'il bloquait déjà — le même correctif que côté serveur, pour la même raison : « le menu ne propose jamais ce que le résolveur refusera ». L'ancienne fusion des « deux voies d'un même seuil » (`DungeonGrid.vue`) est retirée avec le rendu par arête qui la justifiait : chaque porte occupe maintenant sa propre case, il n'y a plus deux battants à confondre en un.

⚠ **Effet de bord trouvé en testant, pas cherché : une porte close bloquait déjà la vue en ligne droite, mais PAS en diagonale** (2026-09-11). `ligneDeVue()` « coupe le coin » sur une diagonale parfaite (« ne franchit aucune arête de porte, seulement un coin de mur », voir son docblock) — un pas diagonal ne croise donc JAMAIS l'arête que `porteBloqueEntre()` sait tester. Une porte fermée bloquait donc une ligne de vue orthogonale, mais un rayon en diagonale passant PAR sa case pouvait la traverser sans jamais être vu par ce test-là — une vraie fuite, présente depuis toujours, révélée seulement une fois que ce chantier a demandé « quelles cases sont opaques ? » plutôt que « quelles arêtes sont bloquées ? ». Le blocage par CASE la referme d'un coup : `bloqueVue()` teste maintenant l'embrasure comme elle teste `'m'`, sur CHAQUE case intermédiaire du tracé, quel que soit l'angle d'arrivée. Deux lecteurs en dépendaient sans le savoir et ont dû être mis à jour pour la même raison, « une seule méthode pour la question » : `App\Partie\Rayon::cases()` (Esprit Ardent, Éclair — « continues until it meets a wall or closed door ») répétait SON PROPRE test `estRoche() || porteBloqueEntre()` plutôt que d'appeler `estTraversable()` (un rayon traverse les FIGURES, qu'`estTraversable()` bloquerait) ; il consulte désormais aussi `Grille::porteFermeeSurCase()`, rendue publique pour ça. Et `MoteurDread` a perdu, dans le même mouvement, un test qui ne construisait jamais son scénario de manière fiable : une salle qui vient d'être générée a TOUTES ses portes fermées, donc « une case visible mais hors de la salle » n'existait qu'à la faveur de cette fuite diagonale — `DreadTest` ouvre maintenant explicitement une porte de la salle avant de chercher une case au-delà, au lieu de la trouver par accident.
**La porte occupe SA CASE, elle n'est plus une arête** (René, 2026-09-11 : « la porte est sur le rebord d'une des 2 extrémités et je trouve ça déséquilibré »). Un mur fait une case d'épaisseur, et cette case est **percée** à la ligne de la porte pour faire l'embrasure — mais la porte se dessinait sur une **arête** de cette embrasure, donc collée à une face du mur au lieu d'en occuper le milieu. Elle est désormais **centrée dans sa case**, et cette case est **inoccupable et opaque** tant que la porte n'est pas ouverte. ⚠ **Aucune distance ne change** : l'embrasure était déjà du sol et déjà foulée. ⚠ `Grille::caseEmbrasure()` est le **point de passage unique** qui désigne laquelle des deux cases d'une porte est l'embrasure — celle qui tombe sur l'**anneau de mur** d'une salle. Deux copies de ce calcul dériveraient sans qu'on le remarque : c'est le défaut le plus répété de ce projet.

⚠ **Corollaire, et c'est lui qui était cassé** : une porte close interdit l'entrée dans sa case **des DEUX côtés**. L'ancien blocage d'arête ne fermait qu'un côté — un héros venant du couloir pouvait marcher dans une embrasure fermée et s'y planter, porte close devant lui.

⚠ **Une porte OUVERTE laisse voir et passer, et sa salle se révèle** (René, 2026-09-11 : « dès qu'une porte est ouverte on affiche son contenu »).

⚠ **Deux portes closes au contact d'un même héros : une option PAR porte** (partie réelle, 2026-10-17). Un héros dans un coin entre deux portes voyait une seule « Ouvrir la porte » : l'autre, annoncée « à portée » par le harnais, était refusée sur un choix forgé (422). Les portes closes atteintes depuis la case sont lues en UN point, `MoteurPortes::portesFermeesAdjacentes()` (embrasure à distance 1 ; une secrète non trouvée n'y figure jamais — c'est un mur). Le menu offre une option par porte (`ouvrir_porte_{x}_{y}_{cote}`, la Bénédiction de l'Oracle et la porte de pierre pareil) ; le résolveur, `ResolveurTour::porteDesignee()`, n'accepte que la porte que la liste contient. **Le libellé nomme la porte par sa direction** (« Ouvrir la porte au nord », « … à l'ouest (clé) », « Bénédiction de l'Oracle — révéler la salle à l'ouest », « Pousser la porte de pierre au sud — … ») **seulement quand plusieurs portes sont offertes ce tour** ; une seule porte garde son libellé d'origine. La direction vient de `MoteurPortes::directionDepuis()`, lue dans la table cardinale de `Rayon::DIRECTIONS` (`Rayon::phrasePour()`) : un seul vocabulaire. Le serveur la décide, l'IA ne l'habille jamais, et la publication ne réécrit pas `libelle`. Tests : `PorteDoubleAdjacenteTest`.

⚠ **UNE PORTE CLOSE A TOUJOURS ÉTÉ TRAVERSABLE DU REGARD EN DIAGONALE**, depuis la première implémentation des portes — trouvé le 2026-09-11 en portant ce changement. `ligneDeVue()` « coupe le coin » sur une diagonale parfaite : le pas diagonal ne croise **aucune arête testable**, donc le test de porte n'était jamais atteint. Le blocage par case le referme gratuitement. ⚠ Et `DreadTest::« ne choisit pas Tempête de feu »` **ne passait QUE grâce à cette fuite** : une salle fraîchement générée a toutes ses portes fermées, donc son scénario « cible en vue mais hors zone » était irréalisable autrement. Un test vert pour la mauvaise raison — la variété la plus insidieuse, et la seule que ni la suite ni la relecture n'attrapent.

⚠ `Rayon::cases()` (Esprit Ardent, Éclair) refaisait **son propre** test de blocage et a dû être recâblé sur `Grille::porteFermeeSurCase()`. Un rayon qui traverse une porte que le regard ne traverse pas est la même incohérence, un cran plus loin.

**Le passage secret est une case de ROCHE qui devient une PORTE dans la même case** (René, 2026-09-11). Non découvert : sa case est peinte `'m'` dans `carte.cases`, et la porte **n'a aucune entrée** dans `portes[]` — indiscernable d'un mur, y compris en comptant les entrées. ⚠ Ceci **REMPLACE** le déguisement `etat: 'mur'` du 2026-09-10, supprimé : la « distinction résiduelle assumée » d'alors (un mur de roche n'a pas d'entrée dans `portes`, ce mur-là en avait une) **disparaît**. Découvert : porte **fermée** dans la même case, à ouvrir. C'est du code retiré, pas ajouté.
⚠ **Un monstre n'apparaît QUE sur une case vide** (René, 2026-09-11, après avoir joué : « les monstres ne devraient pas apparaître dans les meubles et les portes fermées », puis « spawn seulement dans les cases vides »). Le défaut était une **asymétrie de signature**, pas un calcul faux : `spawnsHeros($cases, $salle, $portes)` recevait les portes et évitait donc les embrasures, tandis que `spawnsMonstres($cases, $salles)` ne recevait **rien de tel** — il ne pouvait pas éviter ce qu'on ne lui donnait pas. Et `interieur()` ne teste qu'une chose : « est-ce du sol ». ⚠ Les spawns sont pourtant calculés **après** la pose de toutes les couches : la donnée était disponible, simplement pas transmise, et `DemarreurQuete` pose ensuite la créature **sans re-valider**.

**Sous le thème Mage du Miroir, le passage secret est un MIROIR** (René, 2026-10-29, plan des restes lot C ; F7539 PDF 3 : « These standing mirrors are secret portals that lead to great treasure and hidden rooms »). Un **habillage**, pas une règle : `AssembleurCarte` pose `habillage: 'miroir'` (`MoteurPortes::HABILLAGE_MIROIR`) sur toute porte secrète créée sous ce thème, et la carte le garde — la publication et le menu lisent la PORTE, jamais le thème (une campagne qui changerait de thème ne repeindrait pas ses cartes). Il se trouve, s'ouvre, se franchit et paie exactement comme tout passage secret ; le menu dit « Ouvrir le miroir ». ⚠ **Aucun symbole de livret** : les cartes du livret ne dessinent pas le miroir, elles l'écrivent « MIR » (F7539 PDF 17), et les images de la p. 5 sont des miroirs DE QUÊTE (la princesse, l'arc elfique). Un miroir fermé ne prête donc jamais le symbole de la porte fermée (`BibliothequeImages::urlSymbolePorte()` cherche `portes/miroir`, absent → `null`) : le client le peint d'une teinte d'argent, avec sa ligne de légende. Tests : `MiroirsTest`.

⚠ `casesDuDecor()` est le **point de passage unique** qui recense ce qui occupe une case — embrasures, mobilier (son **emprise entière** `l`×`h`, pas seulement son ancre), pièges, leviers, épreuves, terrain. Deux recensements divergeraient au premier ajout de couche, et une couche neuve oubliée là se verrait **en partie**, sous la forme d'un monstre dans une table. ⚠ Le filtre s'applique **avant** le calcul de la réserve de cases libres (§2.12 bis), sinon on réserverait des cases déjà prises et une salle meublée se remplirait quand même à ras bord.

**§2.12 ter — une salle doit rester JOUABLE, pas seulement connexe** (René,
2026-09-12, après une partie à 4 personnages : « il arrivait souvent que les salles
étaient trop petites ou trop encombrées pour que tous les joueurs puissent agir ; on
a usé de l'option de détruire les meubles quand on pouvait, mais des fois ce n'est
pas possible »). ⚠ L'échappatoire « détruire le meuble » **n'existe pas toujours** :
le **Tombeau** porte `difficulte_destruction` à `null`, indestructible par décision
(« un bloc de pierre »), et `MoteurMobilier` filtre en `whereNotNull` — 41 tombeaux
sur 428 meubles posés en 60 cartes. La correction devait donc se faire à la
**génération**, pas au jeu.

Deux causes distinctes, chacune mesurée sur 60 cartes avant correction :

1. ⚠ **`placerMobilier()` tirait 0..3 meubles SANS regarder l'aire de la salle.** Son
   seul garde-fou était `salleResteConnexe()` — qui ne dit que « on peut encore
   circuler » : **un boyau d'une case de large le satisfait**, et quatre héros n'y
   tiennent pas. Une salle de 5 cases pouvait recevoir trois meubles. Mesuré : **29
   salles sur 390 tombaient sous 6 cases libres, jusqu'à 2**. Le plancher
   `CASES_JOUABLES_MINIMUM` (= `RESERVE_CASES_LIBRES + 2`, les quatre du groupe plus
   deux pour ce qu'il vient combattre) refuse maintenant la pose qui franchirait le
   seuil. ⚠ C'est un plancher sur les **cases restantes**, jamais un plafond sur le
   **nombre** de meubles : une emprise vaut 1 ou 2 cases, et compter les meubles
   laisserait passer deux emprises de 2 là où trois emprises de 1 seraient refusées.
   Coût réel : 24 meubles en moins sur 428 — la variété du décor ne bouge pas.
2. ⚠ **La réserve de monstres rétrécissait quand la salle rétrécissait.** Le plafond
   était `min(RESERVE_CASES_LIBRES, intdiv($interieur, 2))` — « la moitié de la salle »
   — donc une salle de 5 cases gardait **2** cases au groupe et en offrait **3** aux
   monstres : exactement l'inverse de ce que §2.12 bis voulait obtenir. Il devient
   `min(RESERVE_CASES_LIBRES, $interieur - 1)` : **la salle dicte la taille du
   combat**, pas le combat qui chasse le groupe de la salle. Une petite salle reçoit
   donc peu de monstres au lieu de peu de héros, et le budget de rencontre reporte le
   reste ailleurs — ce qu'il sait déjà faire, puisqu'il répartit sur `$listes`. Le
   « au moins une place de monstre » de l'ancien plafond est **conservé** par le
   `- 1` : sans lui une petite salle deviendrait un couloir décoratif que rien ne
   défend. Mesuré après : **toute salle laisse au moins 4 cases au groupe** (minimum
   observé avant : 2).

⚠ **Le tirage `% 4` reste consommé même quand le plancher le ramène à 0** : sauter le
`suivant()` ferait diverger la suite PRNG selon la taille de la salle, et deux donjons
de même graine cesseraient d'être identiques — même précaution que le tirage du
passage secret.

⚠ **Aucune migration rétroactive, et c'est un choix.** `MoteurMobilier` marque une
pièce détruite **à son index dans la grille** (`$grille['mobilier'][$index]['detruit']`)
plutôt que de la retirer — précisément pour ne pas décaler les index. Retirer des
meubles d'une carte déjà générée réattribuerait donc les drapeaux `detruit` et l'état
de fouille aux mauvaises pièces. Le correctif vaut pour la **prochaine carte
assemblée**, et une quête en cours garde ses salles encombrées.

**§2.12 quater — une FIGURE IMMOBILE ne se pose qu'en salle, hors parvis, plancher tenu** (René,
2026-10-15 : « tourelle en SALLE seulement, jamais en couloir »). Un monstre ordinaire qui gêne se
déplace ou tombe ; le *Guardian Effigy*, le *Dread Skull* et le *Bloomrot Scion* (`deplacement: 0`,
`Monstre::estImmobile()`) restent où on les pose jusqu'à ce qu'on les détruise : une tourelle posée dans
un couloir d'une case bloquerait le passage, posée sur le seul parvis d'une porte elle la rendrait
inouvrable — « connexe n'est pas jouable » (§2.12 ter), cette fois avec une figure.
- `AssembleurCarte::casesPourFiguresFixes()` rend `spawn_figures_fixes`, un SOUS-ENSEMBLE de
  `spawn_monstres` : une case de salle (jamais la salle 0, jamais un couloir — les spawns de monstres n'en
  sortent d'ailleurs jamais), hors du **parvis** (`AssembleurCarte::parvis()`, extrait de `placerTerrains()` :
  UN corps pour les terrains `interdit_arret` et pour les figures fixes), la salle restant **connexe** depuis
  un seuil une fois la case prise, mobilier compris (`salleResteConnexe()`), et **au moins
  `CASES_JOUABLES_MINIMUM` cases libres** une fois mobilier ET figure posés — un plancher, jamais un plafond.
- `DemarreurQuete::positionsDesMonstres()` pose la figure sur une case de cette liste en ÉCHANGEANT sa case
  avec celle du monstre qui s'y trouvait (de préférence dans la même salle : le boss reste dans la salle du
  trône) ; `acheterMonstres(…, figuresFixesPossibles)` n'achète **aucune** figure immobile quand la liste est
  vide — la rotation passe à l'adversaire suivant plutôt que de poser une tourelle n'importe où. Aujourd'hui
  au plus UNE figure immobile par rencontre (le palier final ; aucune créature de palier `base` n'est à la fois
  immobile et achetable).
- La clé `spawn_figures_fixes` est INTERNE : jamais publiée par `EtatGroupe::carte()` (le placement ne dit
  rien au joueur que la carte ne montre déjà). Les cartes assemblées avant elle n'en portent pas : lecture
  `?? []`, donc aucune figure immobile n'est achetée pour elles — une quête en cours n'est pas touchée.
- Le PRNG du donjon n'est pas déplacé : `spawnsMonstres()` est appelé au même endroit de la séquence (après
  l'escalier, qui ne tire pas), la clé neuve ne consomme rien — deux donjons de même graine restent
  identiques. `AdversairesNommesTest` épingle l'invariant sur 60 graines.

**La plus petite tuile passe d'un intérieur 2×2 à 2×3** (René, 2026-09-12 :
« remplace la 2x2 par 2x3 »). Les deux correctifs ci-dessus rendaient cette salle
*jouable* — 1 monstre, 4 cases au groupe — sans l'agrandir : quatre héros y tenaient
debout et rien de plus, un par face du monstre, zéro manœuvre. Elle passe de 4 à
**6 cases** d'intérieur, soit `4x5` hors-tout (`mmpm`/`mssm`/`pssp`/`mssm`/`mmpm`).

⚠ **Le choix était entre l'agrandir et lui interdire les monstres** (en faire un
placard à fouiller, ce qu'elle est au plateau). L'agrandissement l'emporte parce
qu'il ne crée **aucun nouveau cas** : une salle qui ne peut pas être défendue serait
une deuxième espèce de salle, avec son propre chemin dans `spawnsMonstres()` et sa
propre raison d'exister à expliquer. ⚠ Et la **variété est préservée** : `4x5` reste
une forme distincte des huit autres — c'est la seule tuile large de 4 — donc le
vivier garde ses 9 formes et respecte le plancher de 6 (playtest 2026-07-31).

⚠ **Les quatre `p` du patron sont décoratifs**, comme sur toutes les tuiles :
l'assembleur perce sur `intdiv($w, 2)` / `intdiv($h, 2)`, soit la colonne 2 et la
ligne 2 — toutes deux dans l'intérieur, ce qui est la condition que le commentaire
de `TuileSeeder` exige (« intérieurs PLEINS uniquement »). Mesuré après
remplacement : sol observé **7 à 10 cases** (contre 5 à 8), tuile toujours placée
107 fois sur 120 cartes, et le **minimum de cases laissées au groupe dans TOUTE
salle du donjon passe de 4 à 5**. ⚠ `TuileSeeder` **purge avant de semer** et rien
ne référence `tuiles.id` — changer un patron prend effet au prochain `db:seed`, sans
migration.

**Le vivier de salles, mesuré** (2026-09-12, 60 graines × 3 gabarits). Neuf tuiles
`type=salle` dans `TuileSeeder`, **toutes réellement placées** — aucune tuile morte.
⚠ Le sol observé n'est **pas** l'intérieur de la tuile : les `p` du vivier sont
**ignorés**, l'assembleur perce ses portes sur la **médiane du slot** (commentaire de
`TuileSeeder`, « intérieurs PLEINS uniquement »), et chaque case de porte percée
devient du sol franchissable. D'où `sol observé = intérieur + nombre de portes
réellement percées (1 à 4)`.

| hors-tout | intérieur | sol observé | où elle tombe |
|---|---|---|---|
| 9×7 | 7×5 = 35 | 36–39 | **salle de départ**, quasi exclusivement |
| 9×6 (thème `boss`) | 7×4 = 28 | 29–30 | **salle finale**, exclusivement |
| 9×5 | 7×3 = 21 | 22–25 | courante |
| 7×7 | 5×5 = 25 | 26–29 | courante |
| 7×6 | 5×4 = 20 | 21–24 | courante |
| 7×5 | 5×3 = 15 | 16–19 | courante |
| 5×7 | 3×5 = 15 | 16–19 | courante |
| 5×5 | 3×3 = 9 | 10–13 | courante |
| 4×5 | 2×3 = 6 | 7–10 | courante — la plus petite |
| 5×4 | 3×2 = 6 | 7–10 | courante — la transposée de la précédente |

⚠ **Trois faits structurels que la liste seule ne dit pas.** (1) La **plus grande**
tuile est réservée de fait à la **salle 0** : 40 fois sur 60 en `vaincre_sous_boss`,
60 sur 60 en `vaincre_boss_final`, et **une seule fois ailleurs** sur 180 cartes. Le
groupe y démarre empilé, c'est cohérent — mais cela veut dire que les salles
**qu'on joue** plafonnent à 9×5 / 7×7, jamais 9×7. (2) La salle **finale** reçoit
toujours la tuile `boss`, la seule à n'avoir qu'**une porte** — une entrée unique,
voulue. (3) Les salles ordinaires se répartissent à peu près uniformément, et la
**plus petite en représente 16 %** (52 sur 330) : c'est elle qui portait tout le
problème de §2.12 ter, et ce n'était pas un cas marginal — d'où son remplacement,
au paragraphe suivant.

⚠ **L'assembleur ne fait AUCUNE rotation** (René, 2026-09-12 : « est-ce que ça permet
aussi d'avoir des salles 3x2 ? » — non, pas sans un second patron). Il lit
`largeur`/`hauteur` tels que semés, et pose la tuile telle quelle. Le vivier le disait
déjà de lui-même : `5×7` et `7×5` y figurent **séparément** alors que l'une est la
transposée de l'autre. Une orientation qu'on veut voir sortir doit donc être **semée**.
La transposée `5×4` (intérieur 3×2) est ajoutée pour cette raison — mesurée à 78 poses
sur 120 cartes, sol observé 7 à 10 comme sa jumelle, et le minimum de cases laissées
au groupe dans toute salle reste **5**.

⚠ **Trois tests ont cédé au changement de vivier sans qu'aucune règle ne bouge** — ils
tenaient par la géométrie tirée à une graine, pas par la propriété qu'ils annonçaient.
Chacun a été **renforcé**, jamais relâché :
- `CouloirsTest` listait les états de porte comme « `fermee` ou `secrete`, rien
  d'autre ». C'était vrai avant les **leviers procéduraux**, qui verrouillent une
  porte : la liste survivait parce qu'aucun levier ne tombait sur CETTE graine. Elle
  accepte désormais `verrouillee` **et exige que chaque verrou nomme un levier
  présent sur la carte** (`verrou.type = 'levier'`, `verrou.levier_id`).
- Le « contrôle négatif » du mobilier bloquant vérifiait que la **PREMIÈRE** case de
  sol hors meuble est traversable. Son propre commentaire disait « hors figure », mais
  le filtre ne retirait que les meubles : la case retenue pouvait porter un monstre,
  que `FabriqueGrille` obstrue légitimement. Il demande maintenant qu'**au moins une**
  le soit — ce qui est exactement l'intention (« la grille ne bloque pas tout ») et ne
  dépend d'aucun ordre de parcours.
- `DreadTest` n'ouvrait qu'**une** porte de la salle du boss pour poser « en vue mais
  hors zone » ; la porte retenue donnait désormais sur un couloir hors ligne de vue. Il
  les ouvre toutes — ça n'affecte en rien ce que le test affirme (le boss renonce à
  Tempête de feu parce que la cible est hors de sa SALLE).

⚠ **Et un quatrième était déjà instable avant tout ça** : « fait surgir un errant à
CHAQUE carte » échouait **3 fois sur 10**, mesuré. Deux défauts empilés. D'abord
`deplacerVersSalle()` posait le héros sur `($salle['x'], $salle['y'])` — le coin du
**rectangle**, donc un **mur** ; `spawnErrant()` cherchant une case libre en anneaux
autour du héros, ces anneaux débordaient hors de la salle (il se pose désormais sur la
**médiane**, que l'assembleur garantit intérieure). Ensuite et surtout, le test
fouillait les salles 0, 1, 2 en dur : **une salle à coffre ne pioche pas dans le deck**,
elle rend son coffre — la carte `errant` empilée n'était jamais tirée et l'issue
revenait `artefact`. Il choisit maintenant trois salles **hors `salles_coffre`**, lu
sur la quête plutôt que figé. Vérifié 12 fois de suite : vert.

**Ce qui varie d'une carte à l'autre, et ce qui ne varie jamais** (mesuré 2026-09-12,
question de René : « les cartes sont-elles tout le temps générées de la même façon ? »).

⚠ **La carte est rigoureusement DÉTERMINISTE par (groupe, numéro de quête)** : la
graine vaut `crc32($groupe->identifiant.':'.$positionArc)` (`DemarreurQuete`).
Vérifié : deux appels rendent une carte identique au caractère près ; un autre groupe
à la même position rend une carte différente. **Recommencer une quête redonne donc le
même donjon.** ⚠ C'est l'inverse du choix pris pour le **deck de fouille** le
2026-08-05 (dé-graine du groupe, « une reprise ne doit pas rendre la pioche connue »),
et l'asymétrie est volontaire : la carte est **stockée en base** et doit survivre
telle quelle à une reprise de snapshot — `salle_artefact` / `salles_coffre` y sont
épinglées pour la même raison. Une carte re-tirée déplacerait le coffre sous les pieds
du groupe.

Ce qui **varie** (10 positions d'arc d'un vrai groupe, gabarit `normale`) : **10
signatures de tuiles distinctes sur 10**, 5 à 8 salles, 3 à 9 meubles, 1 à 2 pièges,
0 à 1 levier, 1 à 2 épreuves, 1 à 2 portes secrètes.

Ce qui **ne varie jamais** :
- le **squelette** — arbre couvrant + boucles + couloirs, **salle 0 = départ**,
  **dernière salle = rencontre finale** ;
- le **thème du bestiaire**, figé pour toute la campagne dans
  `groupes.theme_bestiaire` (arbitrage du 2026-09-06, pour que l'ajout d'une 5ᵉ boîte
  ne change pas le modulo sous une campagne en cours) ;
- l'**objectif**, et c'est le point le moins évident. ⚠ **`choisirGabarit()` ne tire
  RIEN** : il fait `where('type_jalon', $type)->orderBy('id')->first()`. Il existe
  exactement **trois** gabarits, un par type de jalon (`normale` →
  `atteindre_et_recuperer`, `sous_boss`, `boss_final`), donc le gabarit est
  entièrement déterminé par la position dans l'arc. **Toute quête ordinaire a le même
  objectif.**

⚠ **Piège latent, à dire avant qu'il ne coûte** : semer un second gabarit `normale`
ne changerait rien — il ne serait **jamais choisi**, `orderBy('id')->first()` prenant
toujours le même. C'est la classe de défaut exacte du levier qui n'a jamais été placé
(tout le mécanisme écrit et testé, rien qui l'atteigne). Rendre le gabarit **tirable**
(graine `crc32($identifiant.':'.$positionArc)`, la même que la carte, pour rester
reproductible) est le préalable à toute variété d'objectifs — mais seul, ce
changement n'a **aucun effet visible** tant qu'il n'y a qu'un gabarit par jalon.

**Les pièges de sol tirent enfin les TROIS types du livret de Zargon, pas
toujours la Fosse** (contrat « Les trois pièges de sol, enfin tels que le
livret les décrit », 2026-09-24). `AssembleurCarte::placerPieges()` posait
jusque-là **le premier piège du catalogue pour CHAQUE piège** —
`Piege::orderBy('id')->value('id')`, soit la Fosse. Mesuré sur toutes les
cartes en base : 6 pièges, 6 fosses ; la Chute de blocs et le Piège à lances
n'étaient **jamais** posés, et même posée, la Chute de blocs n'aurait rien
bloqué (`bloque_passage` n'avait **aucun lecteur**). Chaque piège posé tire
désormais son type parmi les pièges **de sol** du catalogue (jamais un piège
de coffre/meuble, déclencheur `ouverture_tresor`), par le PRNG déterministe
de l'assemblage — même point de passage, même raison de le consommer dans
les deux branches, que le tirage du passage secret plus haut.

⚠ **Les valeurs sont désormais SOURCÉES, livret p. 14** (photo de René,
2026-09-24, « Pit / Spear / Falling Block Traps », recoupée par
`reference/16_armurerie.md` §716 et `reference/17_mobilier.md` §121) — le
catalogue les avait toutes aplaties à « 1 PV ». Fosse : 1 PV de Body, la
tuile reste en jeu. Piège à lances : **1 dé de combat** (un crâne = 1 PV) et
pas de tuile pour lui — « there are no spear trap tiles » — donc **détruit**
au déclenchement. Chute de blocs : **3 dés de combat, aucune défense**, 1 PV
par crâne, et la case devient un **bloc de pierre permanent**. ⚠ **« Their
turn immediately ends » vaut pour les TROIS, sans exception** :
`MoteurPieges::controlerChemin()` arrête maintenant TOUJOURS la course en
dur dès qu'un piège caché se déclenche — avant cette date, seule la fosse
(`immobilise`) ou une chute à 0 PV l'imposaient, et un piège à lances ou une
chute de blocs laissaient le héros continuer sa marche en pleine hémorragie,
déplacement et action encore intacts.

**Le bloc tombé est un OBSTACLE, jamais un trou** — il bloque le passage ET
la vue, comme un mur, lu par la boucle **unique** de `FabriqueGrille::pour()`
(jamais une seconde, même raison que le mobilier, le terrain et le mur de
glace ci-dessus) : `MoteurPieges::ETAT_BLOC` alimente `$obstacles` et
`$opaques`, jamais `$occupees` — ce n'est pas une figure. Le héros qui se
retrouve dessus **choisit** : avancer ou reculer, au plus deux cases
(`MoteurPieges::casesEcart()`) — reculer vise la case d'où il venait, libre
par construction (il vient d'en partir, dans la même résolution de tour) ;
avancer vise la case suivante dans son sens de marche, seulement si
`Grille::estTraversable()` l'accepte, la même case de réception qu'un saut de
fosse. Le livret laisse le joueur s'isoler du groupe s'il choisit d'avancer —
la manette **avertit**, elle n'empêche pas. Tant que ce choix n'est pas fait,
le menu du héros ne contient **que** `s_ecarter_du_bloc` : c'est
`piege_a_ecarter` (colonne de `etat_personnage_quete`, **jamais** le cache —
la règle consolidée de `CLAUDE.md`, « chaque état durable vit en base ») qui
porte l'attente, sur le patron exact de `reaction_en_attente`, pour qu'un
téléphone rechargé retrouve le choix en attente plutôt qu'un menu muet. Une
fois le choix posé, le tour se termine, comme pour les deux autres pièges.

⚠ **Trois copies front du vocabulaire des états de piège, et `bloc` manquait
aux TROIS** (même date) — à ne pas reproduire au prochain état ajouté.
`PIEGE_ETATS` de `resources/js/store/game.js` écartait silencieusement le
bloc de pierre dans `piegesVersMarqueurs()` (« un état inconnu est ignoré ») :
il restait invisible sur l'écran de table alors qu'il bloquait déjà le
passage côté moteur. La copie locale — même nom, même trou, jamais réimportée
de `game.js` — de `ApercuSalle.vue` portait exactement le même manque.
`DeplacementSheet.vue` avait un troisième point d'atterrissage : ses cases
bloquantes (`mobilierOccupe`, déjà à trois sources — mobilier, terrain, mur
de glace) seraient restées sourdes au bloc, et la manette aurait
surbrillancé — et laissé taper — une case que `FabriqueGrille::pour()` bloque
désormais. **Un état de piège ajouté se cherche dans les trois listes, pas
une seule** — c'est la même classe de défaut, un cran plus loin, que « le
rendu se déplace de l'arête vers la case » avait déjà payée pour les portes ;
la leçon ne s'est pas généralisée toute seule d'un vocabulaire à l'autre.

## Against the Ogre Horde — lot B, les éléments de carte (livret F9528 p. 4-5, 2026-10-02)

**`pieges.boite` / `mobiliers.boite`, une colonne de plus** (migration
`boite_pieges_et_mobiliers`) : même sémantique que `terrains.boite`
(`null` = toutes les boîtes, une valeur réserve l'entrée au thème de
bestiaire du groupe). `AssembleurCarte` la lit **au même point de passage**
que `Terrain::boite` (`$bestiaire?->contient($x->boite)`), jamais une
seconde fois — une Lame balançoire ou une Caisse de ravitaillement posée sur
une carte sans ce thème serait exactement le défaut que `terrains.boite`
corrigeait pour *The Frozen Horror*.

**Porte de pierre** (*Stone Doorway*, p. 4) : « a hero rolls their base
Attack dice. If the roll result includes two skulls, the heavy stone door
swings open. Once […] opened, it remains open for the remainder of the
quest. […] the wizard rolls 1 Attack die, and therefore cannot open a stone
doorway. » Un ÉTAT DE PORTE de plus (`verrou.type = MoteurPortes::VERROU_PIERRE`
sur une entrée `fermee` ordinaire, même modèle que `cle`/`levier`/
`monstres_vaincus`), jamais une couche neuve. « Base » se lit
`personnages.des_attaque` — la valeur à MAINS NUES de la classe
(`ClasseHerosSeeder`), jamais l'arme en main — et le menu n'offre
`forcer_porte_pierre` qu'à qui en lance au moins 2 (`MoteurPortes::DES_MINIMUM_PORTE_PIERRE`),
« le menu n'offre pas ce que le résolveur refuse ».

⚠ **Placement — jamais le seul chemin vers l'objectif.** Un groupe réduit à
un seul magicien (1 dé) ne peut JAMAIS forcer une porte de pierre : en poser
une sur une arête de l'ARBRE COUVRANT risquerait de sceller la seule route
vers l'objectif pour un tel groupe. La règle retenue l'exclut PAR
CONSTRUCTION plutôt que par un calcul de connexité a posteriori :
`AssembleurCarte::marquerPortesDePierre()` ne pose une porte de pierre que
sur une arête de BOUCLE (`liaisonsSupplementaires()`, drapeau `boucle`
propagé de l'arête à sa porte dans `assembler()`) — par définition, les deux
salles qu'une boucle relie sont DÉJÀ connectées par l'arbre, donc la bloquer
ne peut jamais couper la seule route. Au plus UNE porte de pierre par carte
(fréquence non sourcée par le livret — décision de portage, volontairement
conservatrice). ⚠ **UN seul bout du passage** est de pierre (René,
2026-10-03 : « On va garder 1 porte en pierre seulement et non les deux ») :
on marquait les deux portes de la jonction, et il fallait pousser deux dalles
pour un même passage, là où le livret ne parle que d'une porte. L'autre bout
reste une porte ordinaire.

**Lame balançoire** (*Swinging Blade Trap*, p. 4-5) : « triggers if a hero
moves onto a square with the gold overlay […]. A huge blade swings down […],
slicing any heroes on one of the squares marked with a white or red blade
symbol. Zargon rolls 2 Attack dice, and any affected heroes roll Defend dice
as normal. » Le PREMIER piège du jeu à occuper PLUSIEURS cases : une entrée
`pieges[]` ordinaire gagne un champ `zone: [{x,y}, ...]` (posé par
`AssembleurCarte::placerLameBalanciere()`, jamais par `placerPieges()` — son
vivier l'exclut explicitement via `effet.zone_lames`, une case tirée au
hasard ne sachant pas valider une géométrie). `MoteurPieges::declencherZone()`
est le SECOND producteur de dégâts de piège, à côté de `declencher()` :
il frappe CHAQUE héros actuellement sur une case de la zone avec sa propre
défense, jamais un seul (sans défense, comme le Piège à lances/la Chute de
blocs). Le piège reste ARMÉ après coup — rien dans le texte ne limite son
usage — donc aucun changement d'état n'est écrit.

⚠ **Piège latent évité : le déclencheur n'a pas encore sa position en base**
au moment où `controlerChemin()` appelle `declencherZone()` — `resoudreDeplacement()`
n'écrit l'arrêt qu'APRÈS. Lire sa colonne lui aurait fait manquer SA PROPRE
lame. `declencherZone()` prend donc un `$positionDeclencheur` explicite,
réservé à ce chemin (`null` pour un désamorçage raté, où le désamorceur agit
depuis une case ADJACENTE et sa colonne est déjà exacte).

⚠ **PORTAGE : la forme de la zone n'est pas sourcée.** Le livret ne la donne
que sur le plan imprimé d'une quête précise (un dessin, jamais un texte) —
nos donjons sont générés, pas imprimés. `effet.zone_lames` du catalogue pose
une ligne RELATIVE de 3 cases (verticale par défaut), que `placerLameBalanciere()`
oriente (horizontale ou verticale, tirée au PRNG) selon ce qui tient dans la
salle — jamais en couloir, jamais sous `CASES_JOUABLES_MINIMUM` cases
libres (§2.12 ter, « connected is not playable » étendu à « une zone de
lame ne doit jamais couvrir le plancher de cases jouables d'une salle »).
Si aucune salle n'offre de pose valide, on renonce : au plus une lame par
carte.

**Désamorçage de la lame — une procédure DÉDIÉE** (`effet.desarmage_special
= 'lame_balanciere'`, lu par `MenuMoteur::generer()` pour le libellé et
`ResolveurTour::resoudreDesamorcageLameBalanciere()` pour la résolution) :
« The dwarf may automatically disarm a swinging blade trap once it has been
discovered » — succès GARANTI, aucun dé, à la différence du désamorçage
« sans outils » (Nain/Explorateur, un dé, échec sur bouclier noir seulement)
qui reste la règle pour TOUS LES AUTRES pièges. « Any other hero with a tool
kit may attempt to disarm […] roll one combat die. If they roll a shield,
they successfully disarm the trap. If they roll a skull, the trap is
immediately triggered. » — UN SEUL dé de COMBAT, jamais un `JetCompetence`.
⚠ Échec = déclenchement de la ZONE ENTIÈRE, SANS EXCEPTION : le texte ne
connaît pas le nœud « Désamorçage » qui adoucit l'échec sur les autres
pièges.

**Fosse des ténèbres** (*Pit of Darkness*, p. 5) : variante de la Fosse —
même cycle, même `franchissable` (jet de Body pour sauter une fois
détectée) — sauf deux points, sourcés : « Pits of darkness cannot be
disarmed » (`desarmable: 'non'` au catalogue, désormais une vraie garde lue
par `MenuMoteur::generer()` ET revérifiée par `ResolveurTour::resoudreDesamorcage()`
— l'option n'apparaît jamais et le résolveur la refuserait de toute façon),
et des dégâts de chute selon l'ARMURE PORTÉE au moment de la chute plutôt
qu'un montant fixe : « Heroes wearing no armor or only non-metal armor take
1 Body Point […]. Heroes wearing metal armor take 2 […], unless they're
wearing plate mail, in which case they take 3. » `effet.degats_selon_armure`
(lu par `MoteurPieges::declencher()`) consulte `Equipement::porteArmureDePlates()`
/`porteArmureMetallique()` — deux méthodes NEUVES, distinctes de
`porteMetalOuBouclier()` (qui compte aussi le bouclier, une lecture que le
texte de CETTE chute ne veut pas : « wearing metal ARMOR »). Les Brassards
(cuir, errata B1, 2026-10-01) comptent donc pour 1, pas 2.

⚠ **PORTAGE : `deplacement_sans_d6` réutilisé pour une seconde raison.**
Aucun marqueur dédié n'existe au catalogue pour « c'est la plate » (ni nom,
ni tag) ; `deplacement_sans_d6` est la SEULE pièce à le porter
(`ObjetSeeder`, commentaire « SEUL dé »), donc un marqueur fonctionnellement
unique à l'Armure de plates — une décision de portage, documentée plutôt que
silencieuse.

⚠ **Climb-out, un cas déjà couvert par simplification.** « A hero may climb
out of a pit of darkness on their next turn if there is a free square on any
one side » : notre Fosse n'a jamais modélisé de héros littéralement « coincé »
dans le trou — tomber coûte des PV et le tour se termine (livret p. 14), mais
rien n'empêche mécaniquement le héros d'agir normalement au tour suivant.
La Fosse des ténèbres hérite de cette même simplification ; la nuance du
livret y est donc triviale chez nous, pas un gap introduit par ce travail.

**Un escalier d'entrée dans chaque salle de départ, et la sortie passe
désormais PAR LUI** (chantier escalier-entrée, 2026-10-05, René : « il
faudrait ajouter un escalier ou une porte d'entrée pour chaque quête et ça
clarifierait la réussite de la mission d'extraction »). `AssembleurCarte::placerEscalier()`
pose un bloc **2×2** (comme sur le plateau) dans la salle 0, sur un bloc de
sol intérieur hors case de porte, le plus proche du centre de la salle —
géométrique, sans tirage PRNG, puisque rien d'autre n'est jamais posé dans la
salle 0. ⚠ **TRAVERSABLE**, à la différence de toutes les couches qui le
précèdent (mobilier, terrain, glace) : on s'y TIENT, donc il ne retire aucune
case libre — « connecté n'est pas jouable » (§2.12 ter) tenu PAR CONSTRUCTION
plutôt que vérifié après coup. Il ne bloque ni le mouvement ni la vue et ne
coûte rien à traverser : `FabriqueGrille::pour()` n'a donc rien à en lire,
seul le moteur de MENU (`MenuMoteur`) et l'objectif de la mission « secourir »
(`Quete::captifLibereEtVivant()`, voir `docs/regles/exploration-et-fouille.md`)
le consultent, via `Carte::casesEscalier()`/`surEscalier()` — point de passage
unique de « cette case est-elle l'escalier ? », pour ne pas répéter l'erreur
de `Grille::caseEmbrasure()` qu'on corrigeait déjà deux fois.

**Le groupe démarre SUR l'escalier** (René, 2026-10-05 : « on devrait faire
commencer les joueurs sur l'escalier ou adjacent si plus que 4
joueurs/alliés »). `AssembleurCarte::spawnsDepuisEscalier()` met les quatre
marches en tête de `spawn_heros`, puis le reste de la salle du plus proche au
plus loin (distance de Tchebychev : la diagonale du bloc compte comme
adjacente) ; héros puis alliés prennent les places dans cet ordre
(`DemarreurQuete`). ⚠ L'espacement de `spawnsHeros()` (verdict §2.12 : ne
jamais encercler un héros par ses compagnons au tour 1) ne vaut plus pour ces
quatre premiers, et c'est sans danger : un héros traverse la case d'un autre
(`franchitAllies`), il ne lui faut qu'une case d'arrivée libre.

**La sortie ne passe plus par les marches** (René, 2026-10-10, divergence délibérée avec le livret, acceptée) : la salle qui contient l'escalier est la « salle de départ » (`Carte::salleDepart()`), et le groupe peut voter la sortie dès que tous les héros debout y sont → `docs/regles/exploration-et-fouille.md`.

⚠ **Repli pour les cartes assemblées AVANT ce chantier** (campagnes EN COURS
dans la vraie base) : `grille['escalier']` y est absente, `casesEscalier()`
rend `[]`, et chaque lecteur retombe sur le comportement d'avant plutôt que de
rendre une quête en cours impossible à terminer — jamais une migration
rétroactive sur une carte déjà générée, même précaution que §2.12 ter pour le
mobilier.

Rendu : silhouette DÉDIÉE (teinte dorée discrète sur l'emprise 2×2, pas un
bloc plein — l'escalier se traverse, à la différence du mobilier bloquant ou
d'un mur de glace qui disent tous deux « on ne passe pas par là »).
`ESCALIER_ICONE` (`'stairs'`) dans `symboles.js`, lue par `DungeonGrid` (table
ET manette, prop `stairs`) et par `LegendeCarte` — même patron que le levier
et le mur de glace (un seul motif, pas de table de catalogue : l'escalier
n'est pas une entité seedée).

**DANGERS MOBILES AUTONOMES — le rocher roulant et la brume de la mort** (2026-10-23 ; décision de René du 2026-10-15, Q3/Q6 de *Kellar's Keep* et *Witch Lord* : **une seule mécanique**). Catalogue `dangers` (`DangerSeeder`, deux lignes, sourcées à la lettre) ; état durable `cartes.grille['dangers']` (jamais en cache) ; vocabulaire fermé `App\Engine\MotsClesDanger` (`DangerMobileTest` le confronte aux données et aux lecteurs DANS LES DEUX SENS). Un point de passage par question : `MoteurDanger::avancer()` (le tour du MJ, en tête de `ResolveurTour::phaseMonstres()` **et** dans la branche « donjon nettoyé » qui la saute — Zargon joue encore sans monstre), `franchissementDuHeros()` (le passage), `frapperAvecArme()` / `detruireParSort()` (la destruction), `cellulesBloquees()` (le seul point de lecture de la couche pour `FabriqueGrille::pour()`).
- **Le rocher** (*Kellar's Keep* q. 3 note A) : posé en `attente` sur une case déclencheur A de couloir nu ; il tombe quand **le DERNIER héros debout** a franchi A — « franchi » = A figure dans le trajet sans en être l'arrivée (un héros qui s'arrête sur A ne l'a pas franchie), la liste des franchissements est durable (`franchi`). Chaque tour du MJ suivant il roule de **la somme de deux dés rouges** le long de sa ligne ; chaque héros sur une case traversée lance **5 dés de combat, sans défense**, un crâne = 1 PV (`MoteurDanger::toucher()`). Il s'écrase au coin E (le mur au-delà) : `etat: bloc`, et la case est un **obstacle permanent, mouvement ET vue**, comme la Chute de blocs (`FabriqueGrille`, précédent `ETAT_BLOC`). `arret: mur` est lu : sans lui il ne s'immobilise pas. `chemin: ligne` est lu : un autre trajet ne roule pas.
- **La pose du rocher** (`AssembleurCarte::poseRocher()`) : une ligne droite de **3 à 6 cases de couloir nu** dont le dernier coin E est fermé par un mur. Refusée si fermer E coupe une route : toute case accessible AVANT doit rester accessible APRÈS (`accesPreserve()`, sur `Grille::casesAtteignables()` avec obstacles de mobilier et de terrain — jamais une seconde BFS). ⚠ C'est la règle « un rocher qui bouche un couloir ne coupe JAMAIS l'accès à une salle obligatoire » (`CHANCE_DANGER` tirée, `ESSAIS_ROCHER` candidats, sinon rien).
- **La brume** (*Witch Lord* q. 3 note A) : posée au départ sur une case de couloir nu. Chaque tour du MJ elle glisse d'**au plus 6 cases de couloir** (jamais dans une salle) vers le héros debout le plus proche (distance géométrique, `Grille::distance()`), sans revenir sur une case dans le même tour ; elle **passe sur** chaque héros qu'elle traverse (−1 PV, **sans dé**) et ne **termine jamais** sur un héros (elle s'arrête avant). Elle ne BLOQUE ni le mouvement ni la vue : ce n'est pas une figure (une figure muraillerait un couloir d'une case sans issue, et aucune source ne dit que les héros la contournent). Elle ignore les monstres (« It does not affect monsters »).
- **La destruction, par deux voies seulement** : la **Lame des Esprits** au contact orthogonal (option `frapper_danger_{index}`, `ResolveurTour::resoudreFrapperDanger()`) et la **Tempête** (sort `cible: monstre` au catalogue : la brume entre dans sa liste de cibles, `MoteurSorts::ciblesLegales()`, identifiant 1-basé, résolu par `cibleSort()` et `sortMental()`). Une autre arme ou un autre sort ne la vise pas : `danger_sans_effet` (au contact) ou aucun candidat (au menu). La liste vient du catalogue (`dangers.effet.destruction`), jamais recopiée.
- **Thème** : le rocher n'est posé que sous `kellars_keep`, la brume sous `witch_lord` (`dangers.boite`, « le gabarit dit combien, le thème dit lesquels »). Le tirage est fait **en fin d'assembleur, après les spawns**, et **une seule fois par danger du thème** (posé ou non) : une carte d'un autre thème n'en consomme aucun, et les cartes existantes ne bougent pas.
- **Non sourcé, écrit comme décision** : la fréquence (`CHANCE_DANGER = 50`) ; les PV de la brume (**⚠ non trouvé** : « destroyed » se lit comme un coup qualifiant) ; la direction de la brume (le livret ne la dit pas) ; le rocher qui passe les monstres sans les toucher ; dégâts **hors** `SOURCES_REACTIVES` (un danger de décor, comme le poison : aucune carte ne dit qu'un héros annule un passage). Écarté : l'« un piège à la fois » de la note B (*Kellar's Keep* q. 3), déjà écarté le 2026-10-23.
- **Publication** : `EtatGroupe.carte.dangers` (filtrée par le brouillard, `attente` et `detruit` jamais publiés), silhouette **hexagone** `DANGER_ICONE` (`symboles.js`, lue par `DungeonGrid` et `LegendeCarte`), rocher arrêté en bloc plein (`BLOC_ICONE`, comme la Chute de blocs). Contrat : `docs/contrat-api.md` § « Dangers mobiles autonomes ». Aucune image générée (l'orchestrateur).

## TRAPPES DE TÉLÉPORTATION — un portail au contact (EXT-CARTE2, 2026-10-23)

⚠ **Le TUNNEL DE GLACE (Frozen Horror) téléportait SANS RIEN DIRE** jusqu'au 2026-10-24 : le payload portait `teleportation`, mais `JournalCombat::jetsDeTerrain()` ne le lisait que pour une trappe (avec dé de passage). Le fil dit désormais « X s'engouffre dans un tunnel de glace et ressort à l'autre bout » — test dans `TerrainEnJeuTest`.

**Sources** : *The Mage of the Mirror* F7539 p. 5 (composant : « two visually unconnected rooms via an unseen tunnel. When landing on one trap door, a hero moves instantly to the other »), p. 15 note A ; *Kellar's Keep* F4543 p. 22 note C. Le texte est **le même** dans les deux boîtes : « Any hero or monster landing on one of these squares immediately moves to the other trap door square […] After a hero moves through it, they roll one combat die. If a skull is rolled, the hero loses 1 Body Point. After moving from one trap door square to the other, the hero's or monster's turn is over. » Aucun paramètre ne diffère : **une ligne** de catalogue (`terrains`, « Trappe de téléportation », `boite: mage_du_miroir`, `boites_partagees: [kellars_keep]`), un seul lecteur.

- **Un PORTAIL, pas un tunnel** : `effet.teleportation: 'contact'`. Le Tunnel de glace garde `teleportation: true` (arrivée seule : « traverser n'est pas s'arrêter »). **Deux valeurs, deux moments** : ne jamais les confondre dans une nouvelle tuile.
- **La grille** : `FabriqueGrille::pour()` (seul point de passage) pose les portails (`Grille::definirPortails()`). `Grille::parcoursPondere()` ne développe **jamais** un portail, et y entrer = arriver sur la **jumelle**, à coût nul, sans pas de plus. `entrees` garde la case d'où l'on a marché sur le portail : c'est elle qui permet de reconstruire le chemin. Le chemin vers une jumelle **s'achève sur l'entrée** (`coutChemin()` ne compte pas la jumelle) ; la jumelle figure parmi les destinations, la trappe jamais.
- **Le héros** : `ResolveurTour::resoudreDeplacement()` ramène une destination jumelle à l'entrée (l'arrivée réelle), puis `teleporterSiTunnel()` (garde : jumelle découverte, libre), puis `passageDeTrappe()` : **un dé de combat** (`deCombat()`), un crâne = `degats_au_passage` PV, imputés comme le terrain (source terrain, jamais une réaction). La marche s'achève (`finTourPiegeSol`, `restantApres = 0`). Payload : `teleportation: {de, vers, passage: {nom, jet, degats, retenus}}`.
- **Le monstre** : `marcheVersLaCible()` porte un monstre dont la marche s'achève sur l'entrée sur la jumelle (même garde) ; `jouerMonstre()` clôt son tour **sans frappe**. Aucun dé.
- **Les alliés** (mercenaires) : `Grille::fermerPortails()`, appelée par `DeplacementHeros::grilleAllie()` (les quatre lectures de leur déplacement y passent). Un allié **ne franchit pas** une trappe : la case-portail lui est fermée. **Décision, non sourcée** : le texte ne parle que de héros et de monstres. Sans elle, un allié arriverait sur la jumelle sans dé, sans fin de tour et sans annonce, son déplacement posant sa position sur la destination demandée.
- **L'annonce** : `JournalCombat::jetsDeTerrain()` (héros : le franchissement, le dé, la fin de tour) et `LignesEffetsAutomatiques::deplacementMonstre()` (monstre). Aucun nouveau type d'action : les champs vivent dans `deplacement` et `deplacement_monstre`, `JournalCombat::TYPES` est inchangé.
- **La pose** (`AssembleurCarte::placerTerrains()`, après les sables ; `paireTrappePosable()`). Règle écrite :
  1. **deux SALLES distinctes**, jamais la salle 0, jamais la salle qui contient l'élément-objectif ;
  2. **chaque extrémité est atteignable depuis le départ sans franchir** une porte verrouillée, secrète, de pierre ou à levier (BFS, cases d'embrasure fermées) : la trappe ne contourne jamais un verrou ;
  3. **jamais sur un parvis** (voisine orthogonale d'une porte, d'un levier ou d'une épreuve) ; plancher `CASES_JOUABLES_MINIMUM` ; connexité de la salle **avec l'extrémité posée comme mur** (un portail ne se traverse pas à pied) ;
  4. une paire refusée est **abandonnée**, jamais remplacée ; une trappe n'est **jamais tirée seule** (exclue du pool ordinaire). Le gabarit dit COMBIEN (`terrains.trappes`, 0..1 sur les quatre gabarits), le thème dit SI (`boites_partagees`). Le tirage a lieu **après** les sables : les cartes sans trappe ne bougent pas.
- **⚠ Piège trouvé par le test de génération (graine 35, Mage du Miroir)** : l'origine du BFS d'accès était la première case intérieure de la salle 0 — or une case d'embrasure de porte verrouillée au levier se trouve **dans** la salle 0. Partir de là franchissait le verrou par l'autre côté. Règle : l'origine est une case **libre** de la salle de départ.
- **Non exercée, écrite** : la règle de salle d'objectif. `MoteurMobilier::ELEMENT_OBJECTIF_FINAL` ne déclare un élément que pour Wizards of Morcar (le Haut Autel) : sous les deux boîtes, aucune carte n'en porte un. La règle tient si une carte en porte un ; aucun test ne le voit aujourd'hui.
- **Non testé** : une grande figure (emprise supérieure à une case) qui franchit une trappe.
- **Tests** : `TrappeTeleportationTest` (passage avec et sans crâne, jumelle occupée, jumelle découverte, graphe des destinations, tunnel de glace inchangé, monstre, annonce) ; `TrappesGenerationTest` (thèmes, paires entières, un seul enregistrement pour deux boîtes, deux salles, verrous, parvis, COMBIEN).
## SALLES DANGEREUSES ET PORTES À CONDITION (Kellar's Keep, Spirit Queen's Torment — 2026-10-23)

**Salle dangereuse** (`MotsClesMobilier::salle_dangereuse`, lecteur `MoteurMobilier::salleDangereuseDe()`). Deux meubles :
- **Forge des nains** (F4543 p. 15, quête 4 note D) : « any hero (except the dwarf) who ends their move in his room must
  immediately roll 1 combat die. If a skull is rolled, the hero loses 1 Body Point. » → `moment: fin_deplacement`,
  `exempte: nain`, `desamorcable: false`. Frappée par `ResolveurTour::resoudreDeplacement()`, à l'écriture de la position :
  un héros qui ne fait que traverser la salle ne roule pas.
- **Fournaise** (G0053 p. 31, quête 13 note A) : « a hero ending their turn in this room must roll 1 combat die […] Monsters
  are used to the heat and do not take this damage. » → `moment: fin_tour`, `exempte: null`, `desamorcable: true`. Frappée
  dans la fermeture du tour (`resoudre()`, même point que le coup de corne), jamais à la fin d'un déplacement.

Règles de portage : **un dé par salle et par moment**, quel que soit le nombre de meubles qui la chauffent (« a hero … must roll
1 combat die », au singulier) ; un crâne = 1 PV de Body, **sans défense** (`MoteurPieges::resoudreDesCombat()`, le dé du piège à
lances, un seul producteur) ; les monstres ne passent jamais par là. Chaque jet est annoncé par le tampon générique
(`TamponAnnonces`, type `salle_dangereuse`), même sans crâne. Un héros à 0 PV ne se fait plus frapper.

**Désamorçage de la fournaise** : la même couture que le piège (`ResolveurTour::jetDesamorcage()`, extrait pour que les deux
partagent le jet — sans outils pour le Nain et l'Explorateur, un jet de Body sinon). Un échec n'a pas d'autre conséquence :
la fournaise brûle déjà à chaque tour tant qu'elle n'est pas désarmée. L'état `desarme` est écrit dans `cartes.grille.mobilier[i]`.
**La forge ne se désamorce pas** (le livret n'en parle pas) : aucune option.

**Porte à condition** (`MoteurPortes::VERROU_CONDITION`, `CONDITIONS`). Deux portes, copiées telles quelles dans `verrou` :
- **Porte verrouillée** (F4543 p. 19 note A) : deux dés rouges, la porte s'ouvre si le total est **strictement inférieur** au
  Corps de **départ** (`pv_body_max` : « all Body and Mind Points are restored between quests »). Pas d'exemption.
- **Porte de l'Est** (F4543 p. 25 note D) : « equal to or less than their current number of Mind Points » — **inclusif**, contre
  l'Esprit **actuel** (`pv_mind`) ; le Nain ne lance **qu'un** dé.
Un échec ferme le tour (`$finTourPorteCondition`, joint au calcul de créneau à côté de `$finTourPiegeSol` et `$finTourSables`) ;
la réussite est une interaction libre, comme toute porte, et révèle la salle derrière.

**Placement — jamais le seul chemin** : `AssembleurCarte::marquerPortesDeCondition()` ne pose une porte à condition QUE sur une
arête de BOUCLE (`boucle`, jamais l'arbre couvrant), au plus UNE par carte, et seulement dans la boîte `kellars_keep` — aucun
tirage consommé hors de ce thème. C'est la garde de la porte de pierre (`marquerPortesDePierre()`) reprise mot pour mot : une
condition qu'un héros ne remplit jamais (un Esprit à 0) le laisse passer par l'autre route.

⚠ **Lecture des textes** : la porte verrouillée dit « less than » (strict), la porte de l'Est « equal to or less than » (inclusif) —
le portage suit les textes, pas une symétrie. Les emprises des deux meubles (1×1) sont des analogies, non mesurées (`MobilierSeeder`).

## LA GRILLE DE FER — une porte qui se force ou s'ouvre à la clé (Mage of the Mirror, lot F, 2026-10-29)

**Sources** : *The Mage of the Mirror* F7539 p. 4 (« Portcullises x4 » : « Some of these massive iron gates open when the heroes
spring a certain trap, while others are unlocked by the brass key or forced open by brute strength. Heroes and monsters cannot
see through a portcullis until it is opened. »), p. 6 (« Brass Key » : « used to open a portcullis in several quests. The brass
key tile is given to the hero who finds the key. »), quête 7 notes C-D (la clé dans le râtelier d'armes de la salle C, la grille
en salle D : « The portcullis does not open unless a hero has the brass key from room "C." »), quête 10 note G (« a hero must roll
less than their Body Points on two red dice to force the portcullis open. Each hero may try once per turn until the portcullis
opens. »). Décisions : `docs/plan-restes-extensions-2026-10-29.md`, lot F (F1 : la force vaut pour TOUTE grille ; F2 : la clé quitte
le sac en fin de quête). Contrat : `docs/contrat-api.md` § « Mage of the Mirror — grille de fer et clé de laiton ».

**Un état de porte de plus, pas une couche** : `verrou.type = MoteurPortes::VERROU_GRILLE` sur une entrée `fermee`, sur le patron exact
de la porte à condition (`VERROU_CONDITION`) et de la porte de pierre (`VERROU_PIERRE`). Close, elle bloque le passage **et la vue** :
c'est déjà le cas de toute porte non ouverte (`Grille::definirPortes()`, `bloqueVue()`) ; la grille n'a donc pas de règle de vue propre.

**Placement — jamais le seul chemin.** `AssembleurCarte::marquerPortesDeGrille()` ne pose une grille QUE sur une arête de BOUCLE (`boucle`,
jamais l'arbre couvrant), au plus UNE par carte, et SEULEMENT sous le thème `mage_du_miroir` (la garde précède le premier tirage : hors de
ce thème, la suite PRNG ne bouge pas). `GrilleDeFerTest` vérifie, sur 60 graines, qu'ôter la grille ne coupe aucune salle.

**La voie FORCE (F1)** : le héros au contact lance DEUX dés rouges ; un total strictement sous son Corps de DÉPART (`pv_body_max`) ouvre
la grille. Le seuil se lit sur `MoteurPortes::FORCE_GRILLE`, copie de la porte verrouillée de Kellar's Keep (F4543 p. 19 note A : « roll
2 red dice. If they roll less than their **starting** number of Body Points, the door opens. If they fail to open the door, their turn
ends. ») — même mécanique, donc même choix : le DÉPART, pas l'actuel. Un échec **ferme le tour** (`finTourPorteForcee`), comme la porte à
condition : la quête 10 dit seulement « once per turn », lecture compatible, retenue celle du précédent le plus proche. Pas d'exemption du
Nain (le livret n'en parle pas). Le menu n'offre `forcer_grille` qu'à une grille adjacente et close. `ResolveurTour::resoudreForcerPorte()`
est le SEUL point de passage du jet, partagé avec la porte à condition ; seule change la liste des portes offertes.

**La voie CLÉ** : le lecteur existant — `verrou.type` dans `MoteurPortes::VERROUS_A_CLE` (`cle` et `grille`) et `MoteurPortes::possedeCle()` —,
avec `verrou.objet_id` = la « Clé de laiton » du catalogue. Option `ouvrir_porte` « (clé) », ni jet ni tour fermé.

**Le producteur de la clé (la première fouille d'un râtelier d'armes)** : `AssembleurCarte::salleDeLaCle()` choisit UNE salle — jamais la salle
de départ ni une salle que la grille sépare — ATTEIGNABLE depuis le départ SANS franchir un verrou (la grille, la pierre, la condition, un
levier, un gardien, une porte secrète) : `accessiblesSansVerrou()`, le test que la trappe de téléportation partage. Un râtelier garanti
(`objet_fixe: "Clé de laiton"`, comme l'élément-objectif dans la salle du boss) y est posé. `ResolveurTour::resoudreFouilleMobilier()` donne la
clé à la PREMIÈRE fouille de ce râtelier (`$premierAuMeuble`), EN PLUS de sa table ordinaire (quête 7 note C : « finds a tool kit in the
weapons rack. […] This hero also finds a brass key. »). Si aucune salle ne passe, la grille garde seulement la voie force.

**Fin de quête (F2)** : la clé quitte le sac à `terminerQuete()` et à `echouerQuete()` (`Equipement::purgerObjetsDeQuete()`, lecteur de
`MotsClesEquipement::OBJET_DE_QUETE`). ⚠ Écart de lecture, noté et non tranché ici : la quête 4 note B dit « take the brass key tile back »
au moment où la grille s'ouvre ; le plan (F2) garde la clé jusqu'à la fin de la quête. La clé ne sert qu'à CETTE carte : le choix ne change
pas la jouabilité, il ne change que le moment où le sac la rend.

**Symbole** : aucun symbole de herse dans `image-tools/symboles-livrets/manifeste.json`. `BibliothequeImages::urlSymbolePorte()` rend `null`
pour une grille (clé `verrou_grille`, non extraite) : la manette et la table gardent l'icône Material `fence`. Sans fichier, le repli est
l'icône — jamais le symbole de la porte fermée.

**Non testé, nommé** : la pose d'une clé quand la salle candidate est pleine (le râtelier cède au plancher de cases jouables) ; la quête
double (écartée) ; la Bénédiction de l'Oracle et la souris familière, qui révèlent la salle derrière une grille comme derrière une porte
close (même liste que la porte de pierre, par précédent).

## TERRAINS EN PLAQUES — blocs fixes et motifs tirés (Jungles of Delthrak, 2026-10-24)

Demande de René (2026-10-24), après comparaison avec la carte de la quête 1 (F9907 p. 13, PDF p07) : « ajuste pour pouvoir
mettre des terrains à plusieurs cases. La statue bloque la ligne de vue. La jungle et la toile pourraient avoir différents
patterns tirés au hasard ? ». Contrat : `docs/contrat-api.md` § « PLAQUES DE TERRAIN ». Migration `2026_10_24_150000_terrains_multicases`
(additive : `largeur`, `hauteur`, `motif`).

**Ce que le livret montre, et ce qu'on en porte** (rendus relus à l'image, PDF p07, p09, p13, p15, p16, p20, p21, p25 ; symboles p. 51) :

- **Mare = bloc 2×2** : le bassin rond de la quête 1 (F9907 p. 13) et le symbole « Pool of Water » (p. 51) occupent deux cases de côté.
- **Brasier = bloc 2×2** : le symbole « Bonfire » (p. 51) est un socle de la MÊME taille que « Pool of Water » et « Crystal Cluster »
  (2×2). ⚠ Le brief posait le 2×2 sur la quête 1, où les cases **terracotta** sont des **Grasping Vines** (pièges, même couleur que
  le symbole p. 51), pas des brasiers : la source du 2×2 est donc le SYMBOLE, pas la carte.
- **Statue = une case** (symbole p. 51, petit carré), bloque la vue (texte p. 4-5) — et le passage, comme tout meuble
  (`reference/17_mobilier.md` §3). Ni fouillable ni destructible : aucune source ne le dit.
- **Jungle, Toile, Sable = plaques.** `App\Engine\FormesTerrain::MOTIFS['plaque']` : neuf formes, chacune avec sa source écrite.
  Une ligne horizontale de **trois** n'est relevée que pour le SABLE (F9907 p. 29, PDF p15) ; une ligne de trois de jungle ou de
  toile n'apparaît sur aucun rendu consulté — elle est pourtant tirée, grâce au sable. Non retenu : un 3×3 ou un anneau de huit
  autour d'un cocon (la case centrale est masquée par l'objet) ; le L de trois ne figure que dans une orientation.
  ⚠ **Le Sable change de traitement** (il était une case isolée) : il se prête au même traitement, et le livret le montre en
  plaques (carré 2×2 p. 25, ligne de trois p. 29). Signalé ici parce que le brief demandait de ne pas le changer sans le dire.

**Une pose est ENTIÈRE ou n'est pas** (`AssembleurCarte::placerTerrains()`, `positionsDeTerrain()`). Un bloc (`largeur`×`hauteur`)
ou un motif (`motif`) tient sur du sol LIBRE de SA salle, hors salle 0, hors parvis (porte, levier, épreuve). Une moitié de bloc ou de
plaque n'est jamais posée : la pose est abandonnée, sans repli. Le stockage reste PAR CASE dans `cartes.grille['terrain']`, avec un
`groupe_id` commun (`bloc-N` ou `motif-N`, sur le patron de `paire_id`).

**Le plancher de jouabilité ne compte que ce qu'on ne peut pas TENIR** (René, 2026-10-24, précision au brief). Seuls les terrains
**interdits d'arrêt** (Mare, Brasier : 4 cases pour un bloc 2×2) et les terrains **bloquant le mouvement** retirent des cases au
plancher `CASES_JOUABLES_MINIMUM`. Une plaque de **Jungle, de Toile ou de Sable** se franchit ET se tient : elle ne retire AUCUNE case
libre, quelle que soit sa taille. Épinglé par deux tests (`TerrainPlaquesTest`) : une plaque de quatre cases est posée dans une salle de
4×2 (huit cases, plancher 6) sur 80 graines ; la Mare, dans cette même salle, n'est jamais posée.

**Le tirage du motif est consommé AVANT toute vérification** (comme le tirage de densité des meubles, §2.12 ter) : la suite PRNG ne
dépend ni de la place restante dans la salle, ni du plancher. `>> 15` : bits de poids fort, comme `melanger()` et les bandes de sable.
Une carte sans plaque (The Frozen Horror, le thème de glace) consomme exactement les mêmes tirages qu'avant.

**`terrains.min/max` compte des UNITÉS, pas des cases.** Une case isolée, un bloc ou un motif comptent pour UN. Les gabarits ne sont
pas modifiés : `min 1..2` veut toujours dire une à deux poses. ⚠ Conséquence assumée : une quête de Delthrak peut recevoir une plaque de
quatre cases là où elle recevait quatre cases isolées.

**UN JET PAR BRASIER TRAVERSÉ, pas par case du bloc** (« Any creature who moves through the bonfire must roll 1 combat die »,
F9907 p. 4-5). `ResolveurTour::saignerSurRiviere()` — le lecteur que le héros ET le monstre empruntent — retient les groupes déjà joués
pendant CE trajet : le premier jet est celui de la case d'entrée. Une case SANS `groupe_id` (Rivière gelée, carte ancienne) lance comme
avant. Une immunité au feu (Anneau de Feu) n'est consultée qu'UNE fois par brasier, donc une seule charge. « May not end their turn
occupying the same space as it » vaut pour chaque case du bloc : `interdit_arret` est déjà posé case par case par `FabriqueGrille::pour()`.

**Publication et rendu.** `EtatGroupe::terrain()` publie `groupe_id` et `ancre` ; `ancre` est vrai sur UNE case par groupe — celle du
groupe la plus proche du centre de sa boîte, parmi les cases VISIBLES (`marquerAncresDePlaques()`). `DungeonGrid.vue` dessine UNE icône
par plaque (`TERRAIN_ICONES`, `symboles.js`), jamais une icône par case. Le client ne recalcule ni le groupe, ni le centre, ni la forme.
La légende reprend la même icône pour le nom. ⚠ Rendu NON VÉRIFIÉ À L'ÉCRAN dans ce chantier (aucune pile lancée depuis le worktree) :
la lisibilité à 38 px reste à regarder en partie réelle.

**Une carte assemblée AVANT ce chantier** n'a pas de `groupe_id` : chaque case se lit et se joue comme avant. Aucune migration de carte.
Les terrains des cartes en cours gardent leur forme ; seule la prochaine carte assemblée pose des plaques.

**Mutations relevées** (`TerrainPlaquesTest`, une à la fois, restaurées après) : retirer le comptage par groupe (`saignerSurRiviere()`)
fait tomber TROIS tests — le brasier 2×2 du héros, celui du monstre, deux brasiers distincts ; réduire le bloc à une case
(`positionsDeTerrain()`) fait tomber TROIS tests — la Mare refusée au plancher, le bloc entier, et les cartes réelles de Delthrak
(une Mare posée hors de son bloc y est détectée).

**Affichage des plaques, corrigé après vérification à l'écran (2026-10-24).** Une quête Delthrak réelle, capturée sur la table et
la manette, a montré trois défauts que les tests ne voyaient pas : (1) **aucune icône de plaque n'apparaissait** — `.dg-plaque-holder`
n'avait ni `position` ni `z-index`, et le fond de `.dg-cell` (positionnée) la recouvrait ; même pile que le mobilier désormais ;
(2) **jungle, toile et sable partageaient la teinte `gene`** et étaient identiques à l'écran — une teinte chacun (`jungle` hachure
verte, `toile` fils fins pâles, `sable` mouchetis ocre ; la hachure croisée épaisse reste aux Sables mouvants) ; (3) **l'icône d'un
bloc 2×2 tombait dans son coin** — le serveur publie `etendue` sur l'ancre quand les cases visibles remplissent leur rectangle, et
l'icône s'y centre (`docs/contrat-api.md`). Leçon : un rendu se vérifie à l'écran, un test de payload ne voit pas une icône masquée.

## LES TERRAINS DES TOURS — lot H des restes (Spirit Queen's Torment, livret G0053 p. 13-15, 2026-10-29)

Quatre terrains, tous `boite = spirit_queen` (`TerrainSeeder`), posés seulement sous ce thème et jamais dans la salle de départ.
Aucun ne bloque la marche ni la vue : le plancher de jouabilité (`CASES_JOUABLES_MINIMUM`) et la connectivité ne sont pas en jeu.
Sources : quête 10 note B (sol meuble, p. 13), quête 11 notes B et C (courants, p. 14), quête 12 (vents, p. 15) — relues au texte.

**La POSE** (`terrains.pose`, migration `2026_10_29_140000`) : `salle` = toutes les cases LIBRES d'une salle tirée (une plaque
`salle-N`, jamais la salle 0), `couloirs` = chaque case de couloir libre de la carte (hors de toute salle), SANS groupe. Le gabarit dit
COMBIEN (`structure.terrains.<clé>.min/max`, clés `courants`, `sol_meuble`, `courants_porteurs` pour les salles ; `vents` pour les
couloirs) ; le thème dit LESQUELS. Placé EN FIN de `placerTerrains()` : une carte sans le thème ne consomme aucun tirage de plus.
Une case déjà tenue par une autre couche (porte, piège, meuble, épreuve, levier, terrain) reste à sa couche.

**Dosage non sourcé, écrit comme tel** : le nombre de salles par carte (0..1 pour chaque clé) et le choix « tous les couloirs
ou aucun » (`vents` 0..1) sont des valeurs de gabarit, pas du livret. Le livret dit « these rooms » et « the hallways » ; il ne dit pas
combien de salles d'une quête sont couvertes.

### H1 — Courant contraire (q. 11 note B)

« Each square in these rooms takes 2 movement points to traverse. Monsters are unaffected. » Le coût 2 est le `cout_deplacement` ; le
« monsters are unaffected » est la clé EXISTANTE `ignore_par_monstres` (la Rivière gelée), lue en un seul point, `FabriqueGrille::pour()`.
Aucune clé nouvelle : la règle était déjà tenue. Les **alliés** (mercenaires) paient le coût : ils sont du côté des héros, et le texte
n'exempte que les monstres. Test : `CourantsContrairesTest` (4 tests) ; mutation : le lecteur retiré fait tomber le test des monstres.

### H2 — Sol meuble (q. 10 note B) — `arret_sur_impair` (valeur = PV de Body)

« If the result of the movement dice is odd, they become stuck in the first square of their movement and take 1 Body Point of damage.
Once stuck, a hero may only roll 1 die for movement, and they only move if the result of the die is an even number. »

Traduction de nos dés (écart assumé : base de classe + 1d6, le livret lance deux dés rouges sans base) :
- **« the movement dice » = le PREMIER d6 du jet du tour** (`detail_deplacement_tour.des[0]`, `DeplacementHeros::deDeplacementQuiCompte()`).
  Les bottes elfiques ajoutent des dés sans changer celui-ci (comme Évanescence). Un dé ANNULÉ (armure lourde) ou un mouvement FIXE ne compte
  pas : ni arrêt, ni libération. Le jet « sans menace » (4) est pair.
- **Arrêt** : une marche qui ENTRE dans le sol meuble sur un dé impair s'arrête sur la PREMIÈRE case de sol meuble. Elle fonctionne comme
  un portail (`Grille::parcoursPondere()`) : on y entre, on n'en repart pas. Un héros qui part DÉJÀ dans la salle n'avance que d'une case de
  plus dans la salle, s'il en a le coût ; il sort librement vers l'extérieur.
- **Dégât** : 1 PV de Body (`arret_sur_impair`), source `terrain`, annoncé (`enlisement_sol`). La case d'arrivée RÉELLE décide : un piège ou une
  racine qui arrête avant la case n'enlise pas.
- **Durée** : `etat_personnage_quete.enlise_sol` (colonne, migration `2026_10_29_150000`, porté par le snapshot). Un enlisé dont le dé est
  IMPAIR ne bouge pas ce tour (`destinations` vides, `resoudreDeplacement` refuse) ; un enlisé dont le dé est PAIR se dégage et marche de
  base + d6 : il marche du tour, et l'état tombe au premier pas. « Only roll 1 die » n'a aucun effet chez nous : le d6 est déjà UN dé, la base
  n'en est pas un. Le dé pair ne « coûte » rien de plus.
- **Ce qui ne s'applique pas** : un monstre et un allié ne sont jamais arrêtés (« When a HERO moves »). Une poussée de vent n'est pas une
  marche : elle ne tire aucun dé de déplacement et n'enlise personne. Un héros enlisé qui ne se déplace pas reste enlisé jusqu'à son
  prochain pas.
- Test : `SolMeubleTest` (10 tests, routes réelles). Mutations : case d'arrêt retirée du parcours, activation retirée, arrêt à l'arrivée
  retiré — chacune fait tomber le test correspondant.

### H3 — Courant porteur (q. 11 note C) — `bonus_entree` (valeur = gain en cases)

« Currents in these rooms or halls make it easier for heroes to swim. A hero may move 2 extra squares when they first enter this area. »

Un GAIN de budget, pas un coût négatif (le Dijkstra refuse les arêtes négatives). Le parcours porte un STATUT du bonus par case :
`D` (pas encore entré dans un courant porteur), `P<gain>` (bonus pris pendant CE déplacement), `S` (le héros part déjà dans un courant :
il n'y gagne rien). Une clé de `couts`, `parents` et `entrees` est un ÉTAT (`case|statut`), jamais une case seule.
- **Une fois par déplacement** : le gain est pris à la première entrée depuis l'extérieur. Deux courants traversés de suite ne donnent
  que 2 points : le texte dit « when they first enter this area », et un gain cumulé donnerait un déplacement sans borne (la recherche
  n'en sortirait pas — mutation testée).
- **L'entrée se paie sur le budget NORMAL** ; le gain n'est crédité qu'une fois dans la case. Le budget d'un état est `budget + gain(statut)`.
- **Le reste du tour** : `restant_apres = restant + gain(trajet PARCOURU) − coût(trajet parcouru)`. Un piège qui arrête avant la case ne donne
  rien. Le gain se publie (`payload.bonus_courant`, « profite du courant porteur : +N cases »).
- **La meilleure route** pour une case : celle qui laisse le plus de points restants (coût − gain minimal, puis coût minimal). Le budget ne
  filtre les états que s'il existe un courant sur la carte : sans courant, `chemin()` et `casesAtteignables()` rendent exactement ce qu'ils
  rendaient. Un refus hors budget reste « hors de portée » (le plus court chemin est rendu), jamais « inaccessible ».
- **Monstres et alliés** : leur grille n'active jamais le gain (`Grille::activerBonusEntree()` n'est appelé que par `DeplacementHeros::grille()`).
- **Écart nommé** : le livret dit « rooms or halls » ; seules les SALLES portent le courant porteur. Un couloir est un chemin, son dosage n'est
  pas sourcé, et une zone de couloir se découpe. À trancher si René le veut.
- Tests : `CourantPorteurGrilleTest` (8 tests, sans base) et `CourantPorteurTest` (9 tests, routes et aperçu). Mutations : activation
  retirée, budget du résolveur ignoré, reste sans gain, lecteur retiré, gain cumulé (explosion détectée).

### H4 — Vents de couloir (q. 12 note, p. 15) — `pousse_heros` / `pousse_zargon`, dans `sur` par face

« Strong air currents move through the hallways. When a hero ends their movement in a hallway, they must roll 1 combat die. If the roll
result is a shield, they move 2 additional squares in the direction of their choice. If the roll result is a skull, they move 2 squares
in the direction of Zargon's choice. »

- **Le dé** : UN dé de combat, tiré quand le mouvement SE TERMINE sur un couloir venté (`ResolveurTour::ventDeFinDeMouvement()`). Ni en
  passant, ni pour un monstre. Un seul dé par déplacement : la poussée n'enchaîne pas un second dé, même sur un autre vent.
- **Bouclier = blanc OU noir** : le livret G0978 écrit « either a black or white shield » quand il veut les deux ; sans couleur, c'est « a shield ».
  Le moteur les lit sous la même clé (`sur.bouclier_blanc` et `sur.bouclier_noir` → `pousse_heros`).
- **Le HÉROS choisit** (bouclier) : un choix EN ATTENTE, `etat.vent_choix` (colonne, migration `2026_10_29_160000`), menu FORCÉ. Une option
  par direction légale ; la liste blanche est décidée AU DÉ et revalidée au choix contre la géométrie du moment. Creneau `interaction` :
  la poussée est la suite du mouvement, elle ne consomme aucun créneau.
- **Zargon choisit** (crâne) : déterministe, `MoteurVents::directionDeZargon()` — la direction dont la case d'arrivée est la plus proche d'un
  MONSTRE VIVANT (distance de Manhattan, égalité E, O, S, N). Sans monstre vivant : la première direction légale dans cet ordre. Immédiat.
- **La géométrie** (`MoteurVents::poussee()`, règles TRANCHÉES) : 2 cases au plus, une à une. La poussée s'arrête AVANT un mur, un meuble, une
  porte fermée, un portail, une case inconnue (brouillard) et toute figure — héros, monstre, allié (`estOccupeeParFigure`). Elle FINIT sur la
  dernière case où l'on peut s'arrêter (`arretInterdit` : Mare, Brasier se traversent sans y finir). Une poussée partielle est légale ; une
  direction sans aucune case d'arrêt n'est jamais offerte. Ordre fixe E, O, S, N.
- **Les cases traversées** : les pièges se déclenchent au pas (`MoteurPieges::controlerChemin()`, la règle existante ; un arrêt dur termine la
  poussée ; une chute de blocs ouvre le choix d'écart). La rivière et le brasier blessent (`saignerSurRiviere()`, un jet par case).
  **Écarts nommés** : la glace (arrêt, chute), le sol meuble et le courant ne s'appliquent PAS à une poussée — ils règlent une MARCHE (un dé de
  déplacement, une glissière, un budget). Une poussée ne tire aucun dé de déplacement.
- **Dosage** : chaque case de couloir LIBRE de la carte porte le vent (le livret : « all the hallways »), ou aucune (gabarit `vents` 0..1).
  Un couloir est une case hors de toute salle. Un couloir occupé par une porte, un piège, un meuble, une épreuve ou un levier garde sa couche.
- Test : `VentsDeCouloirTest` (12 tests, routes réelles) et `MoteurVentsTest` (12 tests, sans base). Mutations : Zargon sans distance, revalidation
  retirée, figure qui n'arrête pas, dé retiré, bouclier noir compté comme crâne — chacune fait tomber son test.

### Lecteurs et registre

`App\Engine\MotsClesTerrain` gagne `arret_sur_impair`, `bonus_entree`, `pousse_heros`, `pousse_zargon`. Chaque clé est lue par une couture
déclarée ; `TerrainEnJeuTest` confronte le registre au catalogue dans les deux sens. Les faces de dé restent le registre fermé de
`FaceDeCombat` (`bouclier_blanc`, `bouclier_noir`, `crane`), jamais un mot du vocabulaire terrain.
