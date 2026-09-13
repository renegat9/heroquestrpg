# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

HeroQuest-based tabletop RPG with an AI game master ("MJ IA"). Self-hosted, internal project (LAN play between friends — no public deployment). Implemented so far: deterministic engine (`app/Engine`, Pest-tested), full DB layer (migrations/models/seeders per `reference/12_schema_donnees.md`), AI GM module (`app/Agent`: Anthropic client, per-task skills with JSON schemas, Qdrant bible client, queue jobs, Reverb events), game-loop services (`app/Partie`), REST API, and the Vue front ported from the `reference/heroquest/` mockups. The API/front/realtime contract lives in `docs/contrat-api.md` — change it there first. The whole MVP scope of `reference/00_synthese.md` §8 is covered (combat, checks, traps, spells, market, votes, levels, Dread/bosses, campaign closure, snapshots/reprise).

**Added 2026-09-10 → 09-12**, each detailed in `docs/regles/`: a **`terrains` catalogue**
with its own closed vocabulary `App\Engine\MotsClesTerrain` (ice walls, bridges, chasms —
`bloque_mouvement` / `bloque_vue` / `cout_deplacement`, read by the single
`FabriqueGrille::pour()` loop, never a second one); **Mind damage with a real producer**
(`MoteurDegats::infligerMindAHeros()` — the readers had existed for months with nothing
feeding them); **The Frozen Horror** relit as a box theme; movement re-based on a
**weighted Dijkstra** (`Grille::parcoursPondere()`, `coutChemin()`, `pasAffordables()`)
while `distance()` stays **geometric** (range and adjacency); and a **door that occupies
its own cell** (`Grille::caseEmbrasure()`), which is what let an undiscovered secret
passage finally be painted as plain rock.

**Consolidated rule: every durable game state lives in the DB, never in cache.** The cache holds only ephemera — the current menu, the market phase, narrator presence. Exploration progress, searched rooms and the search deck all started life as cache keys with a TTL; losing one re-closed the fog over explored ground and **froze the whole group** (playtest verdict §2.16). Never reintroduce that pattern: add a column.

## Hard rules

These never leave context. Each was paid for in a real playtest. The file named
beside a rule says **why** — the bullet states the rule, the file states the
reason, and the two must never drift.

- **The deterministic engine is authoritative on all mechanics; the AI only narrates
  and dresses.** It never mints a menu option — every entry comes from `MenuMoteur`.
- **No decorative keys, ever.** The order is: entry in a closed vocabulary → reader on
  an existing seam → test **in play** → *then* the data. A key with no reader is a rule
  promised to the player and never kept. → `docs/regles/vocabulaires-effets.md`
- **Never seed a value the booklets or the cards don't source.** `⚠ non trouvé` beats a
  guess. → `reference/16_armurerie.md`, `reference/18_extensions.md`
- **A registry is tested BOTH WAYS**: nothing declared may be missing from the DB, and
  nothing may exist in the DB without a declaration (`config/cartes.php`,
  `App\Engine\MotsCles*`, `config/archetypes_lanceurs.php`). That is what makes "the
  cards are the source" a property instead of a claim.
- **Every durable game state lives in the DB, never in cache** — the consolidated rule
  above, in full. Add a column.
- **The menu never offers what the resolver will refuse**, and a list carried by an
  option **is** the whitelist the resolver re-validates. → `docs/regles/combat-et-tour.md`
- **One rule, one point of passage** (`Salles::indexDe()`, `FabriqueGrille::pour()`,
  `DifficulteBody::plafonnee()`, `ResolveurTour::frapper()`, `Equipement::estAccessible()`,
  and since 2026-09-11: `Grille::caseEmbrasure()` for a door's cell,
  `MoteurSorts::mobiliteCombatDisponible()`, `Equipement::recalculerCombat()`,
  `DeckFouille::sallesACoffre()`).
  Two copies of a rule too simple to notice drifting is this project's most repeated defect.
- **`sortBy([$f, $g])` is never a multi-key sort** — Laravel reads a callable there as a
  *comparator*, and it never errors. It bit twice. → `docs/regles/sorts-dread.md`
- **The server publishes the DECISION, not the ingredients.** A client that re-derives a
  server rule in JS drifts the day the rule moves — **five occurrences in one week**
  (2026-09-11/12), all in `DeplacementSheet.vue` / `ActionTab.vue`. `attaque_supplementaire`,
  `sort_bonus_disponible`, `franchit_figures` and `embrasure` are therefore computed
  server-side and sent **already decided**. → `docs/regles/front-manette-et-table.md`
- **Connected is not playable.** A procedural placement that only re-checks *connectivity*
  accepts a one-cell corridor four heroes cannot stand in — `salleResteConnexe()` was
  satisfied while half the party stood on the threshold, unable to act (real game,
  2026-09-12). Furniture placement and monster spawns both keep a **floor of free cells**.
  → `docs/regles/carte-donjon.md` §2.12 ter
- **One merchant, everything at normal price** (René, 2026-09-12), until negotiation
  exists: `PhaseMarche::ouvrir()` **deliberately ignores** the requested profile. The four
  profiles stay declared — they are the raw material of negotiation, not dead code, and a
  test pins that all four yield the same stall at the same price so a later pass cannot
  rewire the parameter believing it fixes a bug. → `docs/regles/exploration-et-fouille.md`
- **An automatic effect that nothing announces is unplayable.** Journal it, publish it in
  the payload, render it on a screen — a mute payload is the same defect as no payload.
- **The game must stay playable with NO API key**: engine menus, scripted narration,
  catalogue names, SVG emblems. A supported way to play, not a degraded mode.
- **Contract first** — change `docs/contrat-api.md` before the payload.
- **Restart `queue` / `queue-jeu` after ANY PHP change** (§Commands — it froze a playtest).
- **Every generated PNG needs its `.webp` twin** (`image-tools/webp.sh` after `images:generer`).
- **No demo mode** (§Commands). Always test against the real seeded stack.
- **NEVER destroy real game data** (René, 2026-09-12). Groups, characters and
  accounts in the MariaDB container are *production*: campaigns last for weeks and
  he wants them back. ❌ `partie:purger --supprimer --tout`, ❌ `migrate:fresh`,
  ❌ any `DELETE` on `groupes`/`personnages`/`joueurs`. Tests run on a **throwaway
  sqlite copy**, never the container's DB. A change to existing rows is a
  **migration**, not a destructive re-seed — seeders are `updateOrCreate` and
  purge nothing, so `db:seed` stays safe. A harness campaign is cleaned with
  `browser-shots/campagne/nettoyer.sh`, which targets *its own* group.
  ⚠ Say this explicitly in every agent brief, or an agent will purge in good faith.
- **Withdrawing content is a written choice, never an omission**: name it and say why
  (`DemarreurQuete::BOITES_INCOMPLETES`, the `manque` entries of `config/cartes.php`,
  the named divergence list of `BestiaireSourceTest`).

## Established rules — `docs/regles/`

The arbitrations behind the hard rules, moved out of this file **verbatim** on
2026-09-06 so they load on demand rather than every session. They are the rules
**in force** — unlike `docs/plan-*.md` / `docs/verdict-*.md`, which are dated
records and deliberately not updated. **Read the file that covers what you are
touching before touching it**: each paragraph names a defect that reached a real
table, and most of them look like a reasonable idea until you read why they lost.

| File | Read it before touching |
|---|---|
| `docs/regles/carte-donjon.md` | map generation, rooms/corridors/thresholds, furniture, traps, **terrain**, secret passage, map symbols & legend, the **tile pool** and room playability |
| `docs/regles/epreuves-et-attributs.md` | épreuves, `attribut_body` (push, smash, lever), difficulty ceiling |
| `docs/regles/exploration-et-fouille.md` | search deck & artefacts, chests (incl. the **boss-room chest** and what a secret passage pays), room reveal, quest end, retreat vote, market cart and the **single merchant** |
| `docs/regles/combat-et-tour.md` | menu & sub-choices, two-step targeting, turn slots, `GET /menu`, thrown weapon, monster conditions |
| `docs/regles/equipement-et-armurerie.md` | mastery tags, class card backs, the equipment deck conversion, two weapons, charges vs frequency, stall, gifts |
| `docs/regles/artefacts.md` | the 59 artefact cards, activable pieces, fidelity pass, poison, the dressing race |
| `docs/regles/sorts-heros.md` | racial movement, the 5 casting classes, Traverser la Pierre, the 12 spell cards, spell keywords |
| `docs/regles/sorts-dread.md` | the GM's magic: targeting, `palier`, invocation, the 29 cards, rupture rolls, Rust, usage counters |
| `docs/regles/bestiaire-et-rencontres.md` | sourced stat blocks, expansion traits, boss rotation, box themes, measured `cout` |
| `docs/regles/vocabulaires-effets.md` | `DureeEffet`, `RegainEffet`, `TypeDegat`, `MoteurDegats`, out-of-turn reactions, condition naming |
| `docs/regles/talents-et-capacites.md` | innate card capacities, the 3×3 talent grid, level cadence, the 21 mechanics and their readers |
| `docs/regles/narration-et-ia.md` | the AI builds the quest and no longer plays it, beats, quest opening, telemetry, Réglages, the B1 lock |
| `docs/regles/medias-images-et-audio.md` | illustrations & SVG emblems, webp twins, barks, narrator voice, ambiance |
| `docs/regles/front-manette-et-table.md` | what belongs on the phone vs the table screen, **which decisions the server publishes rather than the client re-deriving**, zoom & D-pad, room preview, objective banner, emergency menu |

## Skills — `.claude/skills/`

Procedures for the work that recurs. They point at the files above rather than
copying them. ⚠ Not to be confused with `app/Agent/Skills/` — those are the GM's
LLM tasks (SqueletteCampagne, RecitsQuete, HabillageMonstres).

| Skill | Use it when |
|---|---|
| `ajouter-element-de-jeu` | adding content: class, monster, item, trap, spell, tile, furniture, épreuve, competence |
| `porter-une-carte-officielle` | converting a printed card or a booklet stat block into catalogue + reader + test |
| `ajouter-mecanique-moteur` | a new effect keyword, talent mechanic, duration, regain, damage nature, reaction |
| `travailler-la-carte` | map generation, a grid layer, doors, fog, furniture placement, map symbols |
| `front-manette-et-table` | anything rendered on the phone or the table screen, and the payload feeding it |
| `medias-images-et-sons` | generating or fixing illustrations, barks, narrator voice, ambiance |
| `outillage-dev-et-tests` | running the stack, Pest, browser screenshots, purging a test session |
| `campagne-agents` | playing a real campaign with agents over the real routes, as a test method |

## Commands

```bash
./setup.sh                            # interactive install: writes .env, builds, starts, migrates + seeds
docker compose --profile dev up -d    # dev mode (adds phpMyAdmin on 127.0.0.1:8081 + Vite hot reload on 5173)
docker compose up -d                  # "prod" LAN mode
docker compose exec app php artisan   # any artisan command (migrate, test, etc.)
docker compose logs -f app queue      # follow the app and AI jobs
docker compose restart queue queue-jeu   # MANDATORY after any PHP change (see below)
./image-tools/sauvegarder.sh          # BACK UP MariaDB + the Qdrant bible (do this before anything risky)
./image-tools/sauvegarder.sh --verifier   # restores the last one into a throwaway MariaDB and counts rows
```

**Back up before anything that touches real data.** Until 2026-09-12 there was **no backup
at all** — 210 MB of volume, no dump, no script — so every hardening measure was only
lowering the odds of an **irreversible** event. `sauvegarder.sh` takes both volumes
together (a base restored without its bible leaves the RAG mute), keeps the last 10, and
`--verifier` proves the dump reloads by restoring it into a **throwaway** MariaDB and
comparing row counts — a backup never read back is not a backup.
`browser-shots/campagne/preparer.sh` now calls it first: the moment a harness campaign is
about to write to the real DB is exactly when the net is wanted.

⚠ **`migrate:fresh`, `migrate:refresh`, `migrate:reset` and `db:wipe` are REFUSED**
while the DB holds a group or a character (`AppServiceProvider::interdireLesCommandesDestructrices()`).
`APP_ENV` is `local`, so Laravel's own confirmation **never fired** — those four commands
wiped weeks of campaign without asking anything. The named way out is
`HQ_AUTORISER_DESTRUCTION=1`, deliberately **not** `--force`: an agent adds `--force` by
reflex when a command refuses, it does not invent an environment variable.
`partie:purger --supprimer` now asks, and `--tout` demands the **group count typed back**
— an agent answers "yes" to any closed question, it cannot guess a number it did not read.

**Restart the queue workers after every PHP change.** `app` reads the bind-mounted
code per request, but `queue`/`queue-jeu` are long-running `queue:work` daemons that
load classes **once at boot** — after a migration + code change they keep running the
*old* code against the *new* schema. That is not theoretical: it froze a whole
playtest (2026-08-05) when `GenererMenu` died on the renamed `mobilier.bloquant`
column, so no menu ever reached the controllers and both players sat on "Le maître du
jeu prépare la suite…" for 20 minutes with no error anywhere. `GenererMenu::failed()`
now publishes a minimal "Terminer le tour" menu so a dead job can never freeze a group
again — but that is a safety net, not a substitute for restarting the workers.

PHP, Composer and Node are NOT installed on the host — everything runs through containers. When the compose stack isn't up, use throwaway containers (these are the proven incantations):

```bash
# composer / artisan / Pest against sqlite (database/database.sqlite):
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -w /app \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/app/database/database.sqlite \
  composer:2 <composer …|php artisan …|./vendor/bin/pest …>
# front build:
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD:/app" -w /app \
  node:20-alpine sh -c "npm install && npm run build"
```

Tests are **Pest** (`./vendor/bin/pest`; engine suite under `tests/Unit/Engine`, run a single file by path). `composer.json` pins `platform.php` to 8.3 (the runtime image) — keep it when resolving deps.

**Visual/browser testing** (no Chrome on host — use the official Playwright image, which bundles Chromium + all system libs). With the stack up (`http://localhost`), screenshot key pages and inspect the PNGs:

```bash
docker run --rm --network host -v "$PWD:/work" -w /work \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  mcr.microsoft.com/playwright:v1.48.0-jammy \
  bash -c 'npm i playwright@1.48.0 --no-save --no-audit --no-fund --silent && node browser-shots/shots.mjs'
```

After `images:generer`, run `image-tools/webp.sh` — a missing twin breaks nothing and makes the screen thirty times heavier. → `docs/regles/medias-images-et-audio.md`

**No demo mode.** There is no fake-data fallback anywhere in the SPA anymore — every screen shows its real loading/error state when the API is unreachable or a group code doesn't exist, on purpose, so bugs surface instead of being silently masked. Always test against the real stack (`docker compose up -d`, seeded). `browser-shots/shots.mjs` registers a throwaway player, creates a character + group via the real API, then shoots accueil, /narrateur, /joueur, the manette, and the table screen — all real, no `/table/DEMO`-style routes. PNGs (gitignored) land in `browser-shots/` — read them to check rendering. Pin the `playwright` npm version to the image tag. Watch for **CSS class collisions**: many SFC `<style>` blocks are global (not `scoped`), so generic class names like `.joueur` leak across views — prefix view-specific modifiers.

**Cleaning up after a test session — a command, deliberately not a `est_test` flag.** `php artisan partie:purger` (inventory by default, `--supprimer` to act, `--tout` to also drop accounts and IA telemetry) resets the DB to "no game played", catalogues and settings untouched; `browser-shots/campagne/nettoyer.sh` is the targeted counterpart `preparer.sh` never had. ⚠ Both go through **`ClotureCampagne::purger()`** rather than deleting rows: that service also takes the phase caches, the group's **Qdrant bible** and its **illustrations** — a plain `DELETE` leaves all three behind. A flag was considered and refused (René, 2026-08-23): the harness plays the **real routes** on purpose, so a client-set flag is one tests forget, and a server-set one needs a test-only code path — exactly what "No demo mode" forbids. It would become another decorative key, and it would have prevented nothing: the harness campaigns were already purgeable, **nothing purged them**. The gap was a step, not an identity. ⚠ `BibleQdrant::groupesIndexes()` exists for the same reason as the orphan image sweep: group ids recycle, and a bible left behind by a failed best-effort purge would be **inherited** by a future campaign — the RAG feeding the AI a stranger's promises, silently. Measured 2026-08-23: 185 points across 46 vanished groups.

**Playing a real campaign is a test method.** Sonnet agents each drive one hero over the real controller routes (`browser-shots/campagne/`, README in the same folder). Three campaigns found ten defects that 761 green tests did not. → skill `campagne-agents`

## Architecture

Stack (design doc 11): modular **Laravel monolith** + **Vue SPA** clients + **Reverb** (WebSocket) + **MariaDB** (exact game state) + **Qdrant** (RAG "bible"), all on one docker-compose host. Anthropic or Gemini API for the LLM (`LLM_PROVIDER` default + live override via the Réglages panel; keys in `.env` only, never in images).

**Founding principle (enforced everywhere): the deterministic engine is authoritative on all mechanics; the AI only narrates and proposes.** Concretely, in `GenererMenu::fusionner()` the AI **never mints an option**: every entry of a menu comes from `MenuMoteur`, the AI only lends it a dressed `libelle`. It used to inject its own `dialogue`/`action`/`jet` options unchecked — which let it reuse a mechanical id the engine had just withdrawn (`fouiller_tresor` on an already-searched room: accepted by the controller, rejected deep in the resolver) and produced decorative options that failed silently because nothing resolved them. Flavour actions are to come back **anchored to elements the AI places on the map at creation time**, resolved by the engine the way levers already are.** Dice, HP, combat, skill checks are resolved in code. AI outputs are constrained by JSON schemas (structured outputs) and then validated by the engine against the catalogs — reject/retry on invalid. Players never type free text: the loop is *AI narrates → AI generates a contextual choice menu → engine resolves the chosen option*.

Laravel modules (all French naming, matching the design docs):
- **`app/Engine`** — pure PHP rule classes (dice, skill checks, combat, movement, mental spells), no HTTP/Eloquent. The authoritative core; dice roller is injectable and seedable (`LanceurDeterministe` for tests). Heavily Pest-tested — don't change behavior without updating `tests/Unit/Engine`.
- **`app/Agent`** — single GM agent: `AnthropicClient` (forced tool use), `Skills/` (one per task: SqueletteCampagne, DetailQuete, MenuChoix, Narration — each = JSON schema + prompt assembly + catalog validation with retry then hard-coded fallback), `Memoire/` (ContexteAssembleur, BibleQdrant with `group_id` payload filtering, `Embeddings` interface). Runs only in queue jobs (`app/Jobs`), never blocking the API.
- **`app/Partie`** — game-loop services orchestrating Engine + Models (quest start, map assembly from seeded tiles, encounter budget from group power score, turn resolution, scripted monsters).
- **`app/Models`** — French-named Eloquent models over the doc-12 schema; catalogs are seed-only reference data.

Turn flow: phone (Vue) sends a menu choice → API validates legality via engine → engine resolves deterministically, updates state + journal → job dispatched for next narration/menu → result broadcast via Reverb (group channel `groupe.{id}` for the host "table" screen, private per-player channels for each phone's menu).

Multiplayer model: roles are views, not devices — any browser can be **host** (shared table screen, narration/TTS) or **player** (controller).

Entry/session model (`docs/contrat-api.md` §Modèle de session): the **narrator/table** has no account — it opens a group by **code** (`POST /api/table`) and keeps a **heartbeat** (`POST /api/table/ping` ~every 15s → cache `table:active:{id}`, 30s TTL = "narrator active"). **Players** have accounts (register/login), a **roster** of characters (`/moi` → each character `disponible` or engaged with `groupe.narrateur_actif`), create a group **from a free character** (founder) or join by code. A quest starts when **all active members are marked ready** (`POST /groupes/{id}/pret`) **and** a narrator is active. Read routes (`/etat`, channels) accept a player member **or** the group's table session.

Data: catalogs (bestiary, items, spells, tiles, traps) are seeded reference data — the AI may reskin names/descriptions but never change effects. Full schema in `reference/12_schema_donnees.md`. A complete campaign = the `mariadb_data` **and** `qdrant_data` volumes; back up both together.

## Design documents (`reference/`, French — the source of truth for all game rules)

`00_synthese.md` is the index: per-domain key decisions (coded P1…, S1…, C1…, M1…, Q1…), cross-doc dependency map, open questions, and MVP vs Phase 2 scope. Docs 01–05 (characters, spells, combat, market, session) are fully decided; docs 06–10 (quests, memory, guardrails, bestiary, traps) have listed open questions — don't silently resolve those, surface them. All numeric values (stats, prices, difficulties) are explicitly starting proposals for playtesting. `reference/uploads/13_design_ui.md` and `reference/heroquest/` cover UI design.

**Docs 16–18 are a different kind of document: sourced extracts of the OFFICIAL Avalon Hill 2021 booklets**, downloaded from `instructions.hasbro.com` — 16 armoury/heroes/bestiary/rules, 17 furniture, 18 the thirteen expansion boxes. Every value cites its booklet and page; anything unverifiable carries `⚠ non trouvé` rather than a guess. **Never seed a value these docs don't source** — that rule is what keeps decorative keys from coming back. Three findings shape everything downstream: **prices and the 8 base monsters' stat table exist in no booklet** (they live on the equipment cards and the GM screen, cardboard components never digitised — René's photos are the only way in, and they arrived: monsters via the Sjeng deck, **prices via doc 16 §2.1bis**, 2026-08-14); the 2021 roster is Goblin/Orc/**Abomination**/**Dread Warrior**/Skeleton/Zombie/Mummy/Gargoyle, so our *Fimir* and *Chaos Warrior* are 1989 Milton Bradley names; and the expansions add only **4 confirmed playable classes** (Rogue, Monk, Bard, Explorer), **none with an official stat card**. Known deliberate divergences from the board: our movement is a per-class base + 1d6 where the board rolls 2 red dice with no base (René's decision, kept), and thrown weapons are lost where the board treats the dagger as a permanent ranged weapon.

## Security constraints (doc 11 §11–13)

- MariaDB, phpMyAdmin and Qdrant are never exposed outside the compose network (no published ports; phpMyAdmin binds 127.0.0.1 only).
- Simple auth is acceptable for LAN/VPN only; WAN exposure requires TLS proxy + hardened auth (VPN recommended instead).
