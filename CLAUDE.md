# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

HeroQuest-based tabletop RPG with an AI game master ("MJ IA"). Self-hosted, internal project (LAN play between friends — no public deployment). Implemented so far: deterministic engine (`app/Engine`, Pest-tested), full DB layer (migrations/models/seeders per `reference/12_schema_donnees.md`), AI GM module (`app/Agent`: Anthropic client, per-task skills with JSON schemas, Qdrant bible client, queue jobs, Reverb events), game-loop services (`app/Partie`), REST API, and the Vue front ported from the `reference/heroquest/` mockups. The API/front/realtime contract lives in `docs/contrat-api.md` — change it there first. The whole MVP scope of `reference/00_synthese.md` §8 is covered (combat, checks, traps, spells, market, votes, levels, Dread/bosses, campaign closure, snapshots/reprise).

**AI GM is wired and verified live.** With `ANTHROPIC_API_KEY` (model `claude-sonnet-4-6`) the GM produces real skeletons/narration/menus/monster dressing; with `VOYAGE_API_KEY` (`voyage-3.5`, 1024-dim) the bible does real semantic RAG. The binding auto-selects `EmbeddingsVoyage` when the Voyage key is set, else `EmbeddingsNuls` (lexical, dev). **The game must stay playable without either key** — every AI job falls back (engine-built menus, neutral narration, catalog monster names). After changing `.env` keys, recreate the `app`/`queue`/`reverb` containers AND restart `web` (nginx caches the app upstream IP — a recreated `app` otherwise returns 502 until `web` restarts).

## Commands

```bash
./setup.sh                            # interactive install: writes .env, builds, starts, migrates + seeds
docker compose --profile dev up -d    # dev mode (adds phpMyAdmin on 127.0.0.1:8081 + Vite hot reload on 5173)
docker compose up -d                  # "prod" LAN mode
docker compose exec app php artisan   # any artisan command (migrate, test, etc.)
docker compose logs -f app queue      # follow the app and AI jobs
```

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

`browser-shots/shots.mjs` shoots accueil, /narrateur, /joueur, and /table/DEMO + /manette/DEMO (the game screens render with demo data when the API is unreachable/401 → `modeDemo`). PNGs (gitignored) land in `browser-shots/` — read them to check rendering. Pin the `playwright` npm version to the image tag. Watch for **CSS class collisions**: many SFC `<style>` blocks are global (not `scoped`), so generic class names like `.joueur` leak across views — prefix view-specific modifiers.

## Architecture

Stack (design doc 11): modular **Laravel monolith** + **Vue SPA** clients + **Reverb** (WebSocket) + **MariaDB** (exact game state) + **Qdrant** (RAG "bible"), all on one docker-compose host. Anthropic API for the LLM (`ANTHROPIC_API_KEY` in `.env` only, never in images).

**Founding principle (enforced everywhere): the deterministic engine is authoritative on all mechanics; the AI only narrates and proposes.** Dice, HP, combat, skill checks are resolved in code. AI outputs are constrained by JSON schemas (structured outputs) and then validated by the engine against the catalogs — reject/retry on invalid. Players never type free text: the loop is *AI narrates → AI generates a contextual choice menu → engine resolves the chosen option*.

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

## Security constraints (doc 11 §11–13)

- MariaDB, phpMyAdmin and Qdrant are never exposed outside the compose network (no published ports; phpMyAdmin binds 127.0.0.1 only).
- Simple auth is acceptable for LAN/VPN only; WAN exposure requires TLS proxy + hardened auth (VPN recommended instead).
