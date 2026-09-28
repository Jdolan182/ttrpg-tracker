# TTRPG Tracker

A system-agnostic encounter builder and combat tracker for tabletop RPGs (D&D 5e is only the default
content). "TTRPG Tracker" is a placeholder name. Planned later: campaign features, a live player view,
export, and a paid tier.

## Stack

Laravel 12 + Inertia + Vue 3 (TypeScript, `<script setup>`) + Tailwind 3.4 + shadcn-vue components
(`resources/js/components/ui`), PostgreSQL 18, Reverb (installed, not used yet). Runs in Docker via
Sail inside WSL2 (Ubuntu); the project lives on the Linux filesystem at `~/projects/ttrpg-tracker`.

## Running it

Run these from WSL in the project folder (`sail` = `./vendor/bin/sail`):

- `sail up -d` starts the app (http://localhost), Postgres, Mailpit (http://localhost:8025), Reverb and a queue worker.
- `sail npm run dev` starts Vite with hot reload.
- `sail test` runs the PHP tests. The "deprecated" count they report comes from PHP 8.5 and isn't a failure.
- `sail npx eslint resources/js`, `sail npx prettier --write <files>` and `sail npm run build` check and build the frontend. There are no JS unit tests; check UI changes in a browser.
- `sail artisan migrate`. Avoid `migrate:fresh` on a database with real accounts in it.
- `sail artisan db:seed --class=SrdCreatureSeeder` loads the SRD monsters. Safe to re-run, and needed on deploy.
- Local test login (from `DatabaseSeeder`): test@example.com / `password`.

### Sharing with friends

`./scripts/share.sh` opens a temporary Cloudflare quick tunnel (random `https://….trycloudflare.com`
link, printed and written to `storage/logs/share.log`). While sharing it sets `APP_DEBUG=false`,
`TRUSTED_PROXIES=*` and `SHARE_MODE=true` (serve built assets instead of the Vite dev server), and
restores `.env` from `.env.before-share` when it stops. Stop it with Ctrl+C or `./scripts/share.sh stop`.
If `.env` is ever left with `SHARE_MODE`/`TRUSTED_PROXIES`, restore it from `.env.before-share`.
`cloudflared` is installed at `~/.local/bin/cloudflared`.

## How it's built

- Routes: [routes/web.php](routes/web.php). The tracker (`/`) and compendium (`/compendium`) are public, so
  guests can use them; anything that saves is behind `auth`. Ownership is checked with policies in `app/Policies`.
- Data goes to the frontend through `toFrontend()` on the models, which is mirrored by the types in
  [resources/js/types/tracker.ts](resources/js/types/tracker.ts). Change both together.
- **Creatures** (`creatures` table): SRD rows have `user_id = null` and are read-only. Homebrew belongs to its owner.
  `Creature::visibleTo($user)` returns SRD plus the user's own. SRD data comes from
  [database/data/srd-creatures.json](database/data/srd-creatures.json) (SRD 5.1, CC-BY-4.0; keep the
  attribution). `kind` is monster/npc/player.
- **Stats are generic**: an ordered list of `{label, value}`, never an object. Postgres `jsonb` reorders
  object keys, so order would be lost, and tests on jsonb data must not depend on key order.
- **Actions** have optional limits: `uses` + `per` (turn/round/encounter/day). Action names are unique per creature,
  because combatants count uses by name (`combatant.used[name]`).
- **Encounters** (`encounters` table) store the whole fight: `combatants` (a jsonb snapshot per
  combatant: side, initiative, HP, AC, conditions, uses), `round` (0 means setup, before "Start combat"),
  `active_index`, and `log` (the combat history, jsonb, capped at 1,000 entries).
  [app/Support/EncounterPayload.php](app/Support/EncounterPayload.php) validates saves and the guest import,
  including that every creature is visible to the user and that only player characters are on the player side.
- The tracker page ([resources/js/pages/Encounters/Index.vue](resources/js/pages/Encounters/Index.vue)):
  - Its props are the saved encounters as ids and names only, plus one full `openEncounter` (from
    `?encounter=` or the most recent). Opening another one reloads only that prop.
  - The fight in progress is kept in `localStorage` ([resources/js/lib/trackerStorage.ts](resources/js/lib/trackerStorage.ts)),
    keyed per user or guest. When a guest registers, their fight is copied into the new account
    ([app/Actions/ImportGuestEncounter.php](app/Actions/ImportGuestEncounter.php)).
  - Every change to the fight goes through `change(label, fn)` so it can be undone, and records history with `addLog()`.
    Undo steps don't copy the history; they remember the newest entry. Reset is the exception and passes
    `replacesLog`. History types and wording are in [resources/js/lib/combatLog.ts](resources/js/lib/combatLog.ts).
  - During combat the order belongs to the DM: sort only on roll, Start combat or Sort, and keep the turn with
    whoever holds it (`keepingTurn`).
- Shared helpers:
  - [resources/js/lib/encounter.ts](resources/js/lib/encounter.ts): conditions (with icons), sides, dice/initiative (d20 + DEX), limits.
  - [resources/js/lib/stats.ts](resources/js/lib/stats.ts): d20 modifiers. The user setting `stat_display` controls how stats show.
  - `plainCopy()` in [resources/js/lib/utils.ts](resources/js/lib/utils.ts): use it instead of `structuredClone` on
    Inertia props, which are reactive proxies that `structuredClone` can't copy.

## Conventions

- Match the surrounding code; comment the *why*, not the *what*.
- Commit messages: a subject plus a bulleted body. No `Co-Authored-By` or other AI attribution.
- Before committing, check that no `.env`, backup `.env.*` files, build output or secrets are staged.
