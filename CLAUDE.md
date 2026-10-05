# TTRPG Tracker

A system-agnostic encounter builder and combat tracker for tabletop RPGs (D&D 5e is only the default
content). "TTRPG Tracker" is a placeholder name. Planned later: a paid tier (see docs/roadmap.md).

## Stack

Laravel 12 + Inertia + Vue 3 (TypeScript, `<script setup>`) + Tailwind 3.4 + shadcn-vue components
(`resources/js/components/ui`), PostgreSQL 18, Reverb (websockets, for the live player view). Runs in Docker via
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
- **Campaigns** (`campaigns` table) belong to their DM (`user_id`). Players are normal accounts in the
  `campaign_user` pivot, which also holds the character they claimed (`character_id`, one player per character).
  The party is the DM's player creatures with that `campaign_id`; encounters join the same way. Deleting a
  campaign keeps both. Players join at `/join/{invite_token}` (guests log in or register and come back via
  `url.intended`); resetting the token kills the old link. `enemy_hp` (bands/exact/hidden) is for the player view.
- **Player view**: players see a campaign's fight only during combat (round ≥ 1). While a campaign encounter is
  in combat, the tracker sends it (debounced) to `campaigns.combat.update`, which stores it in `campaigns.live`.
  That's separate from saving. End combat clears it. `live` is never sent to the frontend as is:
  [app/Support/PlayerView.php](app/Support/PlayerView.php) drops hidden combatants and applies `enemy_hp`. It also
  filters the history (latest 150 entries): setup, hide/reveal entries and anything involving someone while they
  were hidden are removed, and enemy healing amounts are hidden unless HP is exact. Its
  TS mirror [resources/js/lib/playerView.ts](resources/js/lib/playerView.ts) covers the DM's own Player view and
  the same-computer `/player-view` window, fed through localStorage. Change both together.
  Broadcasts (`CampaignCombatChanged` on `private-campaign.{id}`) are only a ping; viewers then fetch the view
  over HTTP (`useCampaignCombat`), and poll every 5s when the socket is down. In share mode, friends can't reach
  Reverb through the tunnel, so they rely on that polling.
- **Backups** ([app/Support/Backup.php](app/Support/Backup.php)): JSON with `format`/`version`. Your own
  creatures in full, SRD ones by name only; combatants point at creatures by `ref` (`c{id}`), never by
  database id. Import is all-or-nothing, checks limits first, validates like the forms, and reuses identical
  creatures you already have. The tracker builds the same file for the open fight in
  [resources/js/lib/backup.ts](resources/js/lib/backup.ts) (so guests can export); keep the two in step and
  bump `VERSION` if the shape changes. The saved encounters list is `/encounters` (`Encounters/List.vue`).
- The tracker page ([resources/js/pages/Encounters/Index.vue](resources/js/pages/Encounters/Index.vue)):
  - Its props are the saved encounters as ids and names only, plus one full `openEncounter` (from
    `?encounter=` or the most recent). Opening another one reloads only that prop. It also gets the DM's
    `campaigns` (with party ids, for the campaign picker and "Add party") and `newInCampaign`
    (`?new_in_campaign=`, from a campaign's New encounter button). The query is cleared once acted on.
  - The fight in progress is kept in `localStorage` ([resources/js/lib/trackerStorage.ts](resources/js/lib/trackerStorage.ts)),
    keyed per user or guest. When a guest registers, their fight is copied into the new account
    ([app/Actions/ImportGuestEncounter.php](app/Actions/ImportGuestEncounter.php)).
  - Every change to the fight goes through `change(label, fn)` so it can be undone, and records history with `addLog()`.
    Undo steps don't copy the history; they remember the newest entry. End combat (which puts everything back as if no fight happened) is the exception and passes
    `replacesLog`. History types and wording are in [resources/js/lib/combatLog.ts](resources/js/lib/combatLog.ts).
  - During combat the order belongs to the DM: sort only on roll, Start combat or Sort, and keep the turn with
    whoever holds it (`keepingTurn`).
- Shared helpers:
  - [resources/js/lib/encounter.ts](resources/js/lib/encounter.ts): conditions (with icons), sides, dice/initiative (d20 + DEX), limits.
  - [resources/js/lib/stats.ts](resources/js/lib/stats.ts): d20 modifiers. The user setting `stat_display` controls how stats show.
  - `plainCopy()` in [resources/js/lib/utils.ts](resources/js/lib/utils.ts): use it instead of `structuredClone` on
    Inertia props, which are reactive proxies that `structuredClone` can't copy.

## Plans and limits

- Each user has a `plan` (`free`/`pro`). Limits are in [config/plans.php](config/plans.php); edit the numbers there.
  `php artisan plan:set {email} [plan]` shows or changes an account's plan. Subscriptions are hidden: never
  show prices or plan names in the UI, only usage ("12 of 25 creatures").
- Anything that creates something with a limit calls `Limits::ensureCanCreate($user, 'key')`
  ([app/Support/Limits.php](app/Support/Limits.php)) first. Edits never count. Usage is shared to pages as `limits`.
- What's planned next, and the decisions behind it, is in [docs/roadmap.md](docs/roadmap.md).

## Conventions

- Match the surrounding code; comment the *why*, not the *what*.
- Commit messages: a subject plus a bulleted body. No `Co-Authored-By` or other AI attribution.
- Before committing, check that no `.env`, backup `.env.*` files, build output or secrets are staged.
