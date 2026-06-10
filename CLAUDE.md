# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

HeroQuest-based tabletop RPG with an AI game master ("MJ IA"). Self-hosted, internal project (LAN play between friends — no public deployment). **Game code is not written yet**: the repo contains a fresh Laravel skeleton, the design documents (`reference/`, in French), UI mockups (`reference/heroquest/` HTML/JSX prototypes, `reference/screenshots/`), and the Docker/deployment scaffolding. The next steps per `reference/00_synthese.md` §9: Laravel migrations + seeders for the catalogs, then a vertical prototype (one playable quest validating the AI-GM loop).

## Commands

```bash
./setup.sh                            # interactive install: writes .env, builds, starts, migrates + seeds
docker compose --profile dev up -d    # dev mode (adds phpMyAdmin on 127.0.0.1:8081 + Vite hot reload on 5173)
docker compose up -d                  # "prod" LAN mode
docker compose exec app php artisan   # any artisan command (migrate, test, etc.)
docker compose logs -f app queue      # follow the app and AI jobs
```

Engine tests are planned with **Pest** (`php artisan test` inside the `app` container). Front is Vue via Vite (`npm run dev` / `npm run build`, run in the `vite` container).

## Architecture

Stack (design doc 11): modular **Laravel monolith** + **Vue SPA** clients + **Reverb** (WebSocket) + **MariaDB** (exact game state) + **Qdrant** (RAG "bible"), all on one docker-compose host. Anthropic API for the LLM (`ANTHROPIC_API_KEY` in `.env` only, never in images).

**Founding principle (enforced everywhere): the deterministic engine is authoritative on all mechanics; the AI only narrates and proposes.** Dice, HP, combat, skill checks are resolved in code. AI outputs are constrained by JSON schemas (structured outputs) and then validated by the engine against the catalogs — reject/retry on invalid. Players never type free text: the loop is *AI narrates → AI generates a contextual choice menu → engine resolves the chosen option*.

Planned Laravel modules:
- **engine** — pure PHP rule classes (dice, combat, checks, event application), no HTTP plumbing, heavily tested. The authoritative core.
- **agent** — single GM agent with per-task skills (quest skeleton, quest detail, menus, market/combat narration). Prompt assembly + RAG retrieval + LLM calls, always run as queue jobs (the `queue` container), never blocking the API.
- **memory** — layered: living state always in context (MariaDB, exact), event journal + snapshots (MariaDB), per-group lore bible in Qdrant. One Qdrant collection, isolated per group via `group_id` payload filtering. When agent context fills past a threshold, a job flushes old events into Qdrant and compacts the rest.

Turn flow: phone (Vue) sends a menu choice → API validates legality via engine → engine resolves deterministically, updates state + journal → job dispatched for next narration/menu → result broadcast via Reverb (group channel `groupe.{id}` for the host "table" screen, private per-player channels for each phone's menu).

Multiplayer model: roles are views, not devices — any browser can be **host** (shared table screen, narration/TTS) or **player** (controller). One active session per player (new connection kicks the old one).

Data: catalogs (bestiary, items, spells, tiles, traps) are seeded reference data — the AI may reskin names/descriptions but never change effects. Full schema in `reference/12_schema_donnees.md`. A complete campaign = the `mariadb_data` **and** `qdrant_data` volumes; back up both together.

## Design documents (`reference/`, French — the source of truth for all game rules)

`00_synthese.md` is the index: per-domain key decisions (coded P1…, S1…, C1…, M1…, Q1…), cross-doc dependency map, open questions, and MVP vs Phase 2 scope. Docs 01–05 (characters, spells, combat, market, session) are fully decided; docs 06–10 (quests, memory, guardrails, bestiary, traps) have listed open questions — don't silently resolve those, surface them. All numeric values (stats, prices, difficulties) are explicitly starting proposals for playtesting. `reference/uploads/13_design_ui.md` and `reference/heroquest/` cover UI design.

## Security constraints (doc 11 §11–13)

- MariaDB, phpMyAdmin and Qdrant are never exposed outside the compose network (no published ports; phpMyAdmin binds 127.0.0.1 only).
- Simple auth is acceptable for LAN/VPN only; WAN exposure requires TLS proxy + hardened auth (VPN recommended instead).
