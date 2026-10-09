# Sorts des héros

> Extrait de `CLAUDE.md` le 2026-09-06, **verbatim**. Ce sont les **règles en
> vigueur** — pas un document daté comme `docs/plan-*.md` / `docs/verdict-*.md`.
> Index : `CLAUDE.md` §« Règles établies ».

**Movement is a RACIAL floor plus an agility trait** (`classes_heros.race`, René's call 2026-08-13). Four races — `humain` · `nain` · `elfe` · `halfling` — and a base of **nain 3 · halfling 3 · humain 4 · elfe 5**, **+1** where the class card sells the figure as agile (rogue, moine, berserker, explorateur). Outside the Warlock (halfling) and the Explorateur (dwarf), every extension class is human. ⚠ The grid is **entirely ours**: the cards give "2 red dice" with no base at all, so there is nothing to source here — only a consistency to hold, and it was not held. The **Explorateur**, a dwarf, walked at 5 where the Nain walks at 3, i.e. faster than the Elf. Race had lived as a **comment** until then: nothing in the DB or on any screen said what a class was, so a player watching an Explorer fall behind a Rogue could not possibly guess why. It is now a column, `/api/guide` prints it beside the class, and `CapacitesInneesTest` **derives** each expected value from `race` instead of re-listing twelve numbers — hand-copied, the grid and the data drift apart at the next class added.

**Five classes cast spells, and the Elf has TWO paths** (`MoteurSorts::LANCEURS`, doc 01 §4bis, René's call 2026-08-11). Barde, Druide and Warlock joined magicien and elfe — their cards *are* spells, which is exactly why those three carry no innate card capacity. Each gets a **class repertoire** (`REPERTOIRES_CLASSE`, keyed on `sorts.element`: the column is reused rather than adding a table for three rows). The **Elf** chooses at creation between an elemental school and **3 spells** from the `elfique` repertoire (8 in catalogue, Mage of the Mirror). Both paths weigh the same — three spells either way — only the freedom of the pick changes. They never mix: both would give six spells, neither would leave a caster with no magic. `PUT /groupes/{id}/sorts-elfiques` re-chooses the repertoire (**hub only**), and is **refused** to an elf who took a school — a school is definitive, and its spells are what the tree unlocks. Saying nothing still takes the historic path (default school), which is what keeps old clients, seeders and test helpers working.

**Traverser la Pierre is a movement MODE, not a teleport — and it is cast on ANY hero in line of sight.** ⚠ Its card arrived on 2026-09-02 (René's transcription, doc 16 §3bis) and falsified our target: "This spell may be cast on any one hero in your line of sight, **including yourself**", where we carried `cible: soi` — a porting choice taken when doc 16 §3 still read "⚠ non trouvé" for this spell, and one that made it the only member of its own scroll list not to be `heros` (*Peau de Pierre*, same list, always was). Nothing else had to be wired, and that is what made the switch safe: `traverseRoche()` reads the buff **on its bearer**, and `ce_tour` expires at the end of **that bearer's** turn, not the caster's — so a buffed ally keeps the movement mode for their own turn. The card's "once per quest" **confirms** rule S5 rather than constraining it (every spell is once per quest via `personnage_sorts.disponible`) — no second grammar for what the pivot already says. Its "during their **next movement**" is ported as `ce_tour`: the two coincide in all three real cases (self-cast — acting without having moved keeps the full allowance —, an ally yet to play, an ally already played whose buff crosses the round), and the residual gap is stated rather than hidden: a bearer who ends a turn **without moving** loses it, where the card would keep it. Casting it buffs the target for the turn (`franchit_mur`, `duree: ce_tour`); `Grille::autoriserLaRoche()` then lets *that hero's* pathfinding cross rock **and closed doors** — figures and furniture still block, and line of sight is untouched (you don't see through walls). Ending the movement in rock makes the hero **fall** (`tombe`, 0 PV): our engine has no instant death, and being down *inside a wall* is fatal in practice since reaching them needs the same spell (René's call, 2026-08-06). Entering an unexplored room this way **reveals it, monsters included, without opening the door** — `decouvrirSalle()` already funnels through `revelerSalle()`, so the fourth reveal path costs nothing new. It used to be a 2-cell teleport over a single wall, riskless, needing a free exit square. The spell has **no** movement cost: charging the allowance would make it unusable, since the movement *is* the effect — which is why the `cout` keyword introduced for it was removed again rather than left without a user.

**The 12 SPELL CARDS are sourced too** (René's photos, 2026-09-02 — four PDFs in the project Drive, transcribed in `reference/16_armurerie.md` §3bis). Doc 16 §3 had carried "⚠ non trouvé" for 8 of the 12 spells since day one: the LR booklet shows one example card per element, the rest live only on cardboard. First finding, and it was not a given: **our 12 spells and their 4 elements match the cards one for one** — nothing to recut. Four were already exact (*Rock Skin*, *Heal Body*, *Water of Healing*, *Genie*), and the cards **settle §10's doubt about the two healing spells**: there really are two, in two different elements, with the same effect — our catalogue was right where we suspected ourselves of duplicating.

⚠ **Three spells were wrong, each in a different way.** *Courage* was missing **half its card** — "+2 dice on the next attack" was there, "the spell is broken the moment a monster is no longer in the hero's line of sight" was not, so the buff outlived the fight and waited for the next one; the keyword and its reader already existed (`plus_de_monstre_en_vue`), so `effet.duree` now accepts a **list** of triggers, first one wins (`DureeEffet::correspond()` is the single comparison point — it was `===` on two sites, where a composite duration would have been silently ignored and the buff would have expired **never**). *Voile de Brume* was **a different spell entirely**: we applied `inattaquable` (untargetable for a whole round) where the card says "move unseen **through spaces occupied by monsters**" — word for word the Rogue's *Mobilité de combat*, whose `franchit_figures` mechanic was already written; "unseen" is the colour of the passage, not an immunity, exactly as read on the Rogue's card. *Tempête* carried a `jet_mind` **the card does not have**, which made it weakest against precisely what it exists to slow down — a high-Mind boss; hence `resistance: aucune`, a word that had to be written because the key's ABSENCE falls back to `jet_mind` and therefore cannot say "no roll".

⚠ **A pre-existing defect fell out of the third fix**: with figures erased from the grid, nothing stopped a hero from **ending** their move on an occupied square — two figures stacked. The Rogue's talent had carried that hole since it was written; the smoke bomb held the same interdiction for its own account ("on traverse la fumée, on ne s'y arrête pas"), and it now holds for both.

⚠ **Two more were ported the same day, and these change balance** (René: "il faut respecter les pouvoirs originaux"). **Boule de Feu / Trait de Feu** now inflict a **FIXED** amount (2, resp. 1) that the *target* reduces by rolling that many **raw d6** — each 5 or 6 cancels a point (`resistance: des_rouges` + `des_resistance`, `defense_applicable: false` since the red dice **replace** the parry). We rolled combat dice with a normal defence: same range, different odds, and the randomness on the wrong side — it is the target who resists, not the caster who aims. ⚠ The boss's **Résistance magique** (+2 defence dice against spells) had no defence roll left to live in, so it becomes that many **extra red dice**; doing nothing would have silently switched the capacity off against the only two fire spells in the game. ⚠ Stated consequence: a damage talent adds **before** the reduction, so the dice can eat it — but they are fixed in number, so two dice cancel at most 2 of 3 and the talent now *guarantees* the last point; "fully parried" no longer exists once a bonus is in play. **Sommeil** always lands, and it is its *continuation* that is contested: the monster tries to break free **at once**, then **at the start of each of its turns**, rolling 1 d6 per Mind Point — a single **6** wakes it (`resistance: rupture_6_par_mind`). Both halves had been wrong: a single `jet_mind` at cast could make the spell fail outright, and once asleep a monster **never woke on its own** — only by being attacked. ⚠ It rolls at the *start* of its turn and plays that turn if it wakes: a sleeper that wakes should not also lose the turn it just won back. ⚠ And a sleeper **no longer parries** (René, same day: "après tout, il dort"): "cannot **defend itself**" is applied in `InstanceMonstre::apresConditions()`, on the very line of *Paralysé* — whose card carries the identical sentence — rather than at the three call sites, so hero blow, spell and ally attack all get it through `defenseEffective()`. The ordering holds by itself: the wake-up is posted *after* resolution, so the blow lands on a defenceless sleeper and then wakes it. Nothing on that card is left unported.

**Spells carry three more keyword vocabularies** (`App\Engine\MotsClesSort`, same reference doc): `cible` (`soi` · `heros` · `monstre` · `monstres_zone`) and `resistance` (`jet_mind`), plus the booleans `defense_applicable`, `saute_tour` and `ouvre_porte`. All are now **read**, not merely described. Three notes that bite. **Line of sight is required for EVERY spell**, not just offensive ones — "nécessaire pour lancer un sort ou observer une cible" (LR p. 14, `reference/16_armurerie.md` §6.4); the filter used to run only on the damage/mental branch, so a hero could be healed or buffed **through walls, across the whole dungeon, inside a room never explored**. The caster always sees themselves, so they stay targetable — "may be cast on any one hero, **including yourself**" (Heal Body, LR p. 8), which is also why `heros_ou_soi` was removed: it returned exactly the same list as `heros` and covered no rule at all. **Rule in force since 2026-10-09 (René's decision):** for a **single-target** damage or mental spell, `cible` **restricts** — the legal list follows the card, so `monstre` lists monsters only and `heros` heroes only (*Ball of Flame* and *Fire of Wrath* read « any one monster »; *Sleep*, *Tempest* and *Chains of Darkness* name one monster). Friendly fire survives only for **zone** and **ray** spells (`zone`, `rayon`), which touch every figure of their area and have no list to choose from (doc 02 §5, S3, precised in `reference/02_sorts.md` §10). Before that date every damage or mental spell listed monsters *and* heroes, so a lone magician was offered Boule de Feu on himself. The single point is `MoteurSorts::ciblesLegales()`; the resolver validates against the same list, so it refuses what the menu no longer offers; and `cout: deplacement_du_tour` had no reader at all, so `franchirMur()` walked the hero through the wall and left their whole allowance intact — Traverser la Pierre was **free** despite its own docblock saying it "vaut son déplacement". An unknown `resistance` now **422s loudly** rather than silently resolving with the wrong rule. `MotsClesSort::NON_IMPLEMENTES` lists words a catalog may carry that the engine does **not** apply (`monstres_zone`, `invocation_ephemere`) — **no spell uses either any more**, and a test forbids it. Checking them against the sourced booklets showed they were never debts but **data errors**: the official text is "**un monstre choisi** passe son prochain tour" (Tempest, Kellar's Keep p. 28-29 — never an area spell), and Genie is "opens a door of your choice **or** attacks with 5 combat dice" — no summon at all. Both were fixed at the source like `attaque_second_rang` before them. **Tempête now makes the monster skip its whole turn** (`saute_tour`), where the old `empeche_attaque` blocked only the attack and let it close the distance — the spell merely delayed by one turn a blow it then landed in contact.

**Three OPTIONAL repertoires joined the choice (Wizards of Morcar, livret G1504
p. 11, 2026-10-06): `protection` · `detection` · `tenebres`.** "These may
replace existing sets of spells that a spellcaster can draw on (but Elf and
Wizard still have one and three sets of spells respectively). Spellcasters
may change their spells between quests." The mechanism is **not** a second
choice system: `MoteurSorts::remplacerElement()` reuses the exact pivot
swap `fixerSortsElfiques()` already did for the Elf (detach the old element,
`attacherElement()` the new one in bulk) — generalised to the **five**
casting classes rather than the Elf alone, since the card makes no exception
for anyone. A new route, `PUT /groupes/{id}/sorts-repertoire` (**hub only**,
`SortsElfiquesController::rechoisirRepertoire()`), takes `{personnage_id,
element_actuel, nouveau_repertoire}`; it is deliberately a **sibling** of
`sorts-elfiques` rather than a parameter bolted onto it, because the Elf's
own endpoint carries an invariant (a school is definitive) that has nothing
to do with swapping an *optional* repertoire in and out. Since each of the
three carries exactly three spells, there is nothing to pick **within** a
repertoire (unlike the Elf's eight-candidates-for-three-slots) — attaching
is the whole mechanic.

⚠ **Seven of the nine sourced cards are ported (2026-10-08); two remain a named
debt.** *Unlearn* (catalogue: *Désapprentissage* since migration `2026_10_08_110000`; the English name is kept in `config/cartes.php`, section `sorts_heros`) is ported: `effet.oublie_sort` with `cible: lanceur_dread`
(`MotsClesSort::CIBLE_LANCEUR_DREAD`). The forgotten spell is a **durable row per
quest** in `sorts_oublies_de_quete` (`OubliSorts`), not a flag on
`personnage_sorts.disponible` — that flag always comes back at
`DemarreurQuete::reinitialiserQuete()`, which is exactly why it could not carry
"for the duration of the Quest". One mechanism, two readers: a Sorcier's
repertoire (`MoteurDread::sortsOubliables()`, read by `sortsDisponibles()`), and
a hero's spells (`MoteurSorts::sortsOubliablesHeros()`, read by `options()` where
the spell is greyed, never castable). The High Mage's Dread copy (wave 2) will
target a hero through the second reader without a new table. The card's
"at random" is an **unbiased** draw on the injected d6 by rejection
(`ResolveurTour::indiceAleatoire()`), never `random_int()`, so a fixed-dice test
stays reproducible — and bounded, so a roller that always repeats a rejected face
cannot hang the game. A Sorcier with its whole repertoire forgotten is no longer
a legal target.

*Clairvoyance* is ported: `effet.vision_salle`. The server offers **one entry per
room the group has not discovered** (`MoteurSorts::entreesVisionSalle()`), named
by the door's own bearing ("au est, à 2 cases", measured to the room's median) — and
the SCROLL gets the same entries under `lire_parchemin`, `cle` `parchemin:{inventaire_id}:salle:{index}`
(2026-10-08: the scroll loop fell through to a base entry with no `mode` nor `salle`,
so the resolver refused every scroll of Clairvoyance) — and it is
**never** named by its contents — an empty room must not be distinguishable from a full
one in the menu. The resolver (`ResolveurTour::visionSalleSort()`) shows **only
the chosen room**: its monsters by name and the count of its traps. It writes
nothing to the fog nor to `salles_decouvertes` — information, not exploration —
and the result reaches the table as a scene (`SceneDeTable::sort()`, "Vision à
distance") and the journal. ⚠ "If the room is empty, you may not try again" is
held by the card's own "Discard after use" (S5, once per quest): an empty room
spends the spell exactly like a full one, and no second room is offered in the
same cast. Stated, not hidden: a room is "empty" when it holds no monster alive
and no trap — furniture and chests do not count, the card does not say what it
means by "contents".

**Future Sight → « Vision du futur » is ported (2026-10-08), and it is a reaction, not a spell you cast.**
"This spell may be cast at any time and does not take an action. You may re-roll all
dice for any one attack, defense or movement roll. Discard after use." René's decision:
the reroll is offered **right after the roll** — the result is shown, the server waits for
the answer **before applying it**, and if the player rerolls, all the dice of that roll are
rerolled and the new result applies. **Two sources, one offer** (2026-10-08,
`MoteurReactions::sourceVisionDuFutur()`, the only reader of the source): the hero who
KNOWS the spell, or who CARRIES its scroll `Parchemin : Vision du futur` in the bag. The
grimoire comes first (`disponible` spent, a spell forgotten for the quest does not count);
the scroll leaves the bag when the reroll is **accepted**, never when refused, and a
scroll that has gone between the offer and the answer refuses the reroll, so the action
replays with its original roll. Effect key `relance_jet`; it has **no menu entry**
(neither `lancer_sort` nor `lire_parchemin` — "does not take an action", and the menu never
offers what the resolver would refuse). It extends the existing out-of-turn mechanism
(`MoteurReactions`, `reaction_en_attente`, private channel, `POST /reaction`, 45 s window,
refuse by default) rather than writing a second one: action `relance_jet`, three kinds of
roll in the `jet` field (`docs/regles/vocabulaires-effets.md`).
Own roll only (the card does not name another hero's; decision, stated). Three decisions:

- **Attack — really suspended.** The hero's own request is the one place the game can
  wait. `ResolveurTour::frapper()` throws `JetEnAttente` between the roll and its
  application, `resoudre()` catches it OUTSIDE the transaction (nothing was written: no
  damage, no kill, no loot, no slot), and the offer is deposited with the action to replay
  (`reprise`: option, parameters, rolls already fallen). The answer replays it through
  `ExecutionChoix` — the sequence `/choix` follows, extracted for the occasion — with
  `Combat::resoudreAttaque(facesAttaqueImposees, facesDefenseImposees)`: the seen roll on
  refusal, fresh dice for the hero's volée ONLY on acceptance (the monster's defence is
  already fallen and kept). A sweep numbers its rolls (`rangFrappe`), each suspendable on its own.
  The offer sits before the Oracle's Curse and every post-roll modifier: those apply to the
  RETAINED result. ⚠ The Vindication arrow and the Magic Throwing Dagger have no attack roll
  to reroll: not offered.
- **Movement — the d6 of the turn.** Rolled when the hero's turn opens
  (`MenuMoteur::deplacementDuTour()`); the offer follows at once, `resoudre()` refuses every
  other action while it waits, and the roll's effects (Elven Boots wear, Evanescence
  rupture) move to `MenuMoteur::effetsDuJet()`, applied to the RETAINED roll on the answer.
  Nothing to offer when no die counts: armour that cancels it, shock, Drakehide's fixed 8,
  an unthreatened table (the die is 4 without being rolled).
- **Defence — applied, then undone.** The monster phase resolves inside another player's
  request, in a transaction: nothing can suspend it for a phone round-trip. The blow is
  applied, the question asked, and acceptance gives the HP back (`defaireLeCoup()`) and
  rerolls only the hero's defence dice against the monster's attack as it fell
  (`faces_attaque` kept in the context) — the Oracle's Blessing seam. Offered only on
  melee/ranged blows of a monster (the only rolls whose volée can be replayed) and only when
  the blow hurt. ⚠ Priority in `proposer()`: after the full cancels (*Dark Wings*,
  *Twisting Torrent*, the Staff's reflection, *Dawnshield*, the Oracle), before the HP floors
  — a hero holds ONE offer at a time.

**Never a frozen group.** A late answer is a REFUSAL (not a 422 like the other reactions:
dropping the suspended action would lose it for good); a silent phone is covered by
`rattraperExpiration()`, which REPLAYS the action with the original roll (refusal by
default, the spell kept). `relance_jet` is in `ACTIONS_RELEVANTES`: a rerolled defence can
bring a downed hero back, so the TPK waits for it. Named gaps: defence rolls against a Dread
spell, a trap or a friendly spell; the attacks of an ally or a mercenary.

**Cloak of Shadows → « Voile d'ombre » is ported (2026-10-08).** "Heroes and monsters on
the tile may not attack or be attacked. The darkness blocks line of sight into and through
it. Place 3 shadow tokens on this card. At the start of the spellcaster's turn, remove a
shadow token. The spell ends after the last shadow token is removed." A durable layer,
`carte.grille['ombre']` (`MoteurOmbre`), one rectangle per veil: `{x, y, l, h, jetons, lanceur_id}`.

- **Size: 3×2 (either orientation), MEASURED, not invented.** No card nor rule says it.
  The booklet's Components page (G1504 p. 4) shows two purple pieces; the large one measures
  72.2 × 108.1 pt on the page, against 54.2 × 108.4 pt for the Earthquake tile ("covers 6
  squares", p. 12 — 3 long) and 225 pt for the Artificer's Laboratory tile (6 squares wide):
  36 pt a square, so exactly 2 × 3. The second piece, a 54 pt square, has no named role and
  is not ported.
- **Placement: a written decision.** The card only says "place the tile on the gameboard".
  Every one of the six cells must be floor the caster can SEE, not hidden by the fog, with
  no wall, blocking furniture or closed door; figures may stand on it (the card expects it)
  and so may the caster. One entry per legal emplacement, the closest first, 24 at most
  (`MoteurOmbre::emplacementsLegaux()` — the menu and `poser()` read the same list, the
  resolver revalidates against the current state). One veil per caster.
- **Sight, one reader.** `FabriqueGrille::pour()` puts the cells in `$opaques` ("through") AND
  `Grille::assombrir()` ("into": an endpoint under the veil is not seen, and since the line
  of sight is symmetric a figure inside sees nothing outside). Not in `$obstacles` — you
  walk under it — nor in `$occupees`. Spells need the line of sight, so none can target
  into or out of the veil.
- **"May not attack or be attacked", one predicate.** `MoteurOmbre::contient()` /
  `contientHeros()` / `contientMonstre()`: the hero under it → `MoteurSorts::attaqueInterdite()`
  (the single predicate of the wave-1 attack filter, now with `raisonAttaqueInterdite()` for
  the true cause), so the menu offers no attack and `resoudre()` / `frapper()` refuse; a
  hero or ally under it is never a monster's target (`estInattaquable()`,
  `alliesCiblables()`); a monster under it is no weapon's target (`ciblesPourArme()`,
  `ciblesBalayees()`, `frapper()`) nor a ray's (`Rayon::cases()` skips the cells — the ray
  crosses, the veil cuts sight, not the trajectory) and does not strike (`jouerMonstre()`
  returns `monstre_dans_l_ombre` — it may still walk). Named gap: the strikes of an ally or a
  mercenary, from or into the veil.
- **Countdown.** At the start of the caster's turn, once per round (marker `ombre_decompte`
  in `capacites_tour`, reset each round — not `deplacement_tour`, which a blocked hero never
  rolls); a FALLEN caster opens no turn, so his token drops when the round opens (the card
  does not foresee it: decision). At the last token the veil leaves the layer. Every token is
  announced (`ombre_decompte`: journal + combat line). Published in `EtatGroupe.carte.ombre`
  with its counter; table, controller and legend render it.
- The scroll of the spell reads too (one entry per emplacement, each carrying its
  `inventaire_id`). Future Sight's scroll is never read from the menu, but it IS played
  by the reaction (2026-10-08, see *Future Sight* above): a hero who carries it is offered
  the reroll exactly as one who knows the spell.

**Wall of Stone (Spells of Protection) is a magical BARRIER, not a buff** —
"You create a magical wall of stone which covers 2 squares not occupied by
figures. The wall has 1 Body Point and 6 Defend dice." The wall occupies
**TWO** cells, as the card says — René's decision of 2026-10-05, which **annuls**
his earlier "one cell" of 2026-10-04 (taken when the wall was believed to sit on
an edge). A wall is still ONE furniture entry, so a single lost Body Point
destroys the whole wall, and `FabriqueGrille` blocks it through its `l`/`h`
footprint (2×1 or 1×2, the pair the player chose). It is built entirely on the
**attackable furniture** seam
(`mobiliers.pv_body`/`defense_dice`, the "third way to clear an obstacle"):
`MoteurMobilier::poserMurMagique()` is the single point of passage that
**appends** a new entry to `carte.grille['mobilier']` **during the quest**,
the exact array `FabriqueGrille::pour()` already loops for every piece of
furniture in the engine — so blocking movement **and** sight, and being
attackable with `attaquablesAdjacents()`/`infligerDegats()`, cost nothing
new; only the *pose* is new code. `MobilierSeeder` gains "Mur de Pierre"
(`bloque_vue: true`, unlike the Altar/Dread Chest's `false` — the card calls
itself "a solid, impassable wall", a wall, not low furniture) but **not**
"Mur de Glace"/"Mur de Feu": those two are the Storm Master's and High
Mage's Dread spells (wave 2), and seeding a row with no spell to cast it
would be the same trap as above. `AssembleurCarte::MOBILIER_POSE_EN_QUETE`
excludes all three names from the generic room-dressing draw — without it,
the very same uniform-random `placerMobilier()` that already seeds
*Haut Autel*/*Coffre du Dread* as ordinary dressing would plant an
ownerless, uncast wall in a random room the day the theme is active.

Casting it offers **one menu entry per free PAIR of orthogonally contiguous
cells**, the first one adjacent to the caster (`MoteurSorts::entreesPoseMurMagique()`:
four neighbours × three onward cells, the caster excluded — twelve pairs on an open
floor), never a cible-less base entry a player could click with nothing chosen.
The SCROLL `Parchemin : Mur de Pierre` offers the same pairs under `lire_parchemin`
(2026-10-08), from the same generator with `cle` `parchemin:{inventaire_id}:mur:…`, and
the same resolver point (`poserMurMagiqueSort()`) — before this, its scroll fell through
to a base entry with no `cases`, and a scroll that could not place its wall was a button
that did nothing.
The pair travels in the option (`parametres.cases`), and `poserMurMagique()` refuses
anything that is not two contiguous cells. Placing a wall is a **choice of cells**,
not a choice of target. No connectivity check runs at cast time, on purpose: this
is a player's live tactical choice, not a generation-time placement, and the
cardboard piece goes wherever the player puts it, for better or worse.
⚠ **A wall in a corridor has no room index**, and `EtatGroupe::mobilier()`
used to publish furniture only by "is its *room* discovered" — exactly the
bug the corridor levers paid for in 2026-08-27 ("on en déduisait la salle par
les coordonnées"). It now falls back to the cell's own fog state when
`salle` is `null`, the same fix, one layer later.

**"If a Lightning Strike or Earthquake meets a magical wall, both spells are
cancelled" (carton p. 10) is named here and ported nowhere**: those two are
Storm Master Dread spells, wave 2's job. The wall's *shape* is already in
place for whoever writes that reader — a wall is one entry in
`carte.grille['mobilier']`, destroyable, with a cell the ray/line code can
test for — but the cancellation rule itself is not written.

**Two new condition-effect booleans, read like `inattaquable`/`action_interdite`
before them: `attaque_interdite` and `immunite_sorts`.** *Invisibility*
(Protection) — "makes you invisible until the start of your next turn. While
invisible, you may not attack. You cannot be attacked and are immune to all
spells." — reuses the **abandoned** "Caché" condition row (it had carried
`inattaquable` with no producer since Voile de Brume stopped posing it on
2026-09-02) rather than inventing a new one, and extends its `effet` with
the two new keys: `attaque_interdite` is read at the **one** choke-point of
a strike, `ResolveurTour::frapper()` — melee, ranged, thrown, Furie, every
variant funnels through it, so one guard covers all of them; `immunite_sorts`
is read in `MoteurSorts::ciblesLegales()`, where it drops the bearer from
**any** spell's legal targets, friendly or hostile. ⚠ Named gap: only a
hero-cast spell's targeting is filtered — a Dread spellcaster's target
selection is not rewired by this pass, so a sorcerer could still choose an
invisible hero. *Chains of Darkness* (Darkness) — "may not move or attack
until the start of your next turn. They may defend or cast spells." — reuses
the *same* `attaque_interdite` key (plus `deplacement_interdit`, already
read) on a **new** condition, "Enchaîné" (hero side, tir ami — no spell reaches a hero with it since 2026-10-09) and a **new**
monster condition, `MoteurSorts::MONSTRE_ENCHAINE` (`enchaine`). It is
checked in `ResolveurTour::jouerMonstre()` **after** the Dread-spell attempt
and before the movement/approach code — deliberately, since `saute_tour`
would have blocked "may … cast spells" too had it been reused instead of a
sibling key; defense is left untouched on purpose, "may defend" being the
engine's default when nothing says otherwise. *Arrows of the Night*
(Darkness) — "The target defends with as many dice as they have Mind
Points. Monsters with 0 Mind points may not roll defense." — is a **new**
`resistance` value, `MotsClesSort::RESISTANCE_DES_MIND`, read in
`ResolveurTour::sortDegats()`: an ordinary combat roll (shields counted as
always — black for a monster defender, white for a hero, per `Engine\Combat`)
where only the defender's **dice count** is substituted for `pv_mind`, never
a binary save and never the red-dice replacement the fire spells use.
*Trésor convoité* (Detection's **Treasure Horde**) — "draw 3 treasure cards.
You may shuffle any of the drawn cards back … and keep the rest." — draws
**exactly three**, applies whichever are `tresor`/`potion`/`objet`/
`artefact`, and auto-returns the rest (trap/wandering-monster/nothing)
**unresolved** to the bottom of the deck: the same automatic "may" resolution
`piocherAvecSixiemeSens()` already uses, since a card that can only hurt has
one rational answer. It is **not** *Trésor sans Péril*: that one draws
*until* a gain, ignoring hazards; this one promises three chances, not a
gain — it can come up empty.


**Invisibility closes ONE door on attacks, and the menu and the resolver read the same predicate (2026-10-08).** `MenuMoteur::generer()` filters the whole hero menu once, after every return path of `genererBrut()`, and `ResolveurTour::resoudre()` refuses the same options before any variant runs, both through `MenuMoteur::estAttaqueDuHeros()` (`TYPES_ATTAQUE_HEROS`: `attaque`, `attaque_balayee`, `rayon`, `degat_differe`, `attaquer_mobilier`). That list is the whole hero strike family — weapons, throws, Furie, the Moine's blows and the Berserker's sweep, the Fire style's ray and touch, and a strike on a piece of furniture. The ally's own menu (`genererMenuAllie()`) is **not** filtered: "you may not attack" binds the invisible hero, not the figure they control, and the resolver does not refuse the ally's blow. `ResolveurTour::frapper()` keeps its guard underneath.

**The repertoire swap has a screen, and the server decides what it offers (2026-10-08).** `GET /api/moi` publishes `repertoires: {remplacables, offerts}` per hero (`MoteurSorts::repertoiresChangeables()`): the known elements except the scroll element `parchemin`, and the optional repertoires the hero does not hold yet. The grimoire (`SpellsTab`, hub only) lists them as they come. `remplacerElement()` now refuses two things it used to take silently: a repertoire the hero already holds (`attacherElement()` is idempotent, so taking it again would **lose** the old one without a word), and a scroll as the element to replace.
