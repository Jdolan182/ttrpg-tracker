# Turnkeeper

A system-agnostic encounter builder and combat tracker for tabletop RPGs (D&D 5e is only the default
content), going live at https://theturnkeeper.app. Its name comes from `APP_NAME`. The repo, folder,
browser-storage keys (`ttrpg-tracker:…`) and backup format marker keep the old "ttrpg-tracker" name on
purpose: renaming those would lose fights in progress and break existing backup files.
Planned later: a paid tier (see docs/roadmap.md).

## Stack

Laravel 12 + Inertia + Vue 3 (TypeScript, `<script setup>`) + Tailwind 3.4 + shadcn-vue components
(`resources/js/components/ui`), PostgreSQL 18, Reverb (websockets, for the live player view). Runs in Docker via
Sail inside WSL2 (Ubuntu); the project lives on the Linux filesystem at `~/projects/ttrpg-tracker`.

## Running it

Run these from WSL in the project folder (`sail` = `./vendor/bin/sail`):

- `sail up -d` starts the app (http://localhost), Postgres, Mailpit (http://localhost:8025), Reverb and a queue worker.
- `sail npm run dev` starts Vite with hot reload.
- `sail test` runs the PHP tests. The "deprecated" count they report comes from PHP 8.5 and isn't a failure.
- `sail npx eslint resources/js`, `sail npx prettier --write <files>`, `sail npm run typecheck` (vue-tsc) and `sail npm run build` check and build the frontend.
- `sail npm run test:js` runs the Vitest tests for the plain logic in `resources/js/lib` (`*.test.ts` next to the code). There are no UI tests; check UI changes in a browser.
- `tests/fixtures/player-view.json` holds cases both the PHP and the TS player view must match (PlayerViewParityTest and playerView.test.ts). Add a case there when the filtering rules change.
- `sail artisan migrate`. Avoid `migrate:fresh` on a database with real accounts in it.
- `sail artisan db:seed --class=SrdCreatureSeeder` loads the SRD monsters. Safe to re-run, and needed on deploy.
- Local test login (from `DatabaseSeeder`): test@example.com / `password`.

## How it's built

- Routes: [routes/web.php](routes/web.php). The tracker (`/`) and compendium (`/compendium`) are public, so
  guests can use them; anything that saves is behind `auth` and `verified`. Ownership is checked with policies in `app/Policies`.
- Accounts verify their email (`User implements MustVerifyEmail`). Until then they work like a guest in the tracker
  and compendium and can use their settings; a banner (`VerifyEmailBanner`) says so. Locally the emails land in
  Mailpit; a real mail provider is needed before real users sign up. Accounts from before verification existed were
  marked verified by a migration. The account emails' wording is in `AppServiceProvider::wordEmails()` and their look
  in [resources/views/vendor/mail](resources/views/vendor/mail) (only the changed templates are kept there).
- Running it for real: [docs/deploying.md](docs/deploying.md) and [deploy/env.production.example](deploy/env.production.example).
  One Hetzner Ubuntu 24.04 server (Caddy, PHP-FPM, Postgres, Reverb under systemd), set up by `deploy/server/setup.sh`
  and updated by `deploy/server/deploy.sh`; the server pulls from GitHub, so changes must be pushed to deploy.
  With an `https://` `APP_URL`, every generated link uses it (`AppServiceProvider::pinLinksToAppUrl()`). Errors go to
  Sentry when `SENTRY_LARAVEL_DSN` is set. "Send feedback" (footer and account menu) goes to `FEEDBACK_URL`, else emails `CONTACT_EMAIL`; `/privacy` is the
  plain-language privacy note, so keep it true when what's stored changes.
- Rate limits (`AppServiceProvider::limitRequests()`): sign-ups 5/hour and reset emails 5/minute per IP, and every
  change by a signed-in account 60/minute ("writes", on the whole web group; reads, guests and the live player
  view's own 300/minute are left out). `bootstrap/app.php` turns hitting one into a form error (`email`, or
  `throttle`, shown by `ThrottleNotice`). The seeded test account is only created locally.
- Data goes to the frontend through `toFrontend()` on the models, which is mirrored by the types in
  [resources/js/types/tracker.ts](resources/js/types/tracker.ts). Change both together.
- **Creatures** (`creatures` table): SRD rows have `user_id = null` and are read-only. Homebrew belongs to its owner.
  `Creature::visibleTo($user)` returns SRD plus the user's own. SRD data comes from
  [database/data/srd-creatures.json](database/data/srd-creatures.json) (SRD 5.1, CC-BY-4.0; keep the
  attribution), generated from the full SRD monster list in `database/data/source/` by
  `sail php database/data/convert-srd-monsters.php` (edit the mapping there, not the output, then re-run it and
  the seeder). The seeder validates every creature with the form's rules. `kind` is monster/npc/player.
- **Stats are generic**: an ordered list of `{label, value}`, never an object. Postgres `jsonb` reorders
  object keys, so order would be lost, and tests on jsonb data must not depend on key order.
- **Actions** have at most one kind of limit (`limitKind()` in `lib/encounter.ts`; the server enforces one with `prohibits`):
  `uses` + `per` (turn/round/encounter/day), `recharge` `{die, min}` (rolled at the start of its turn once spent),
  `cooldown` (rounds or dice, rolled on use), or `resource` + `cost` from one of the creature's `resources`
  (named pools `{name, max, per}`: legendary actions, spell slots, mana…). Keep the wording generic, never D&D-only.
  Action names are unique per creature, because combatants track them by name: `combatant.used[name]` is times used,
  1 while a recharge is spent, or cooldown rounds left; `combatant.spent[resource]` is what's spent of a pool.
  Recharge rolls go in the history as `recharged`/`not_recharged`, which players never see (DM-only log types).
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
  That's separate from saving. End combat and Reset clear it. `live` is never sent to the frontend as is:
  [app/Support/PlayerView.php](app/Support/PlayerView.php) drops hidden combatants and applies `enemy_hp`. It also
  filters the history (latest 150 entries): setup, hide/reveal entries and anything involving someone while they
  were hidden are removed, and enemy healing amounts are hidden unless HP is exact. Its
  TS mirror [resources/js/lib/playerView.ts](resources/js/lib/playerView.ts) covers the DM's own Player view and
  the same-computer `/player-view` window, fed through localStorage. Change both together.
  Broadcasts (`CampaignCombatChanged` on `private-campaign.{id}`) are only a ping; viewers then fetch the view
  over HTTP (`useCampaignCombat`), and poll every 5s when the socket is down.
- **Backups** ([app/Support/Backup.php](app/Support/Backup.php)): JSON with `format`/`version`. Your own
  creatures in full, SRD ones by name only; combatants point at creatures by `ref` (`c{id}`), never by
  database id. Import is all-or-nothing, checks limits first, validates like the forms, and reuses identical
  creatures you already have. The tracker builds the same file for the open fight in
  [resources/js/lib/backup.ts](resources/js/lib/backup.ts) (so guests can export); keep the two in step and
  bump `VERSION` if the shape changes. The saved encounters list is `/encounters` (`Encounters/List.vue`).
- The tracker page ([resources/js/pages/Encounters/Index.vue](resources/js/pages/Encounters/Index.vue)) is mostly layout.
  Its logic is in [resources/js/composables/tracker](resources/js/composables/tracker): `useUndo` (changes, history,
  undo), `useCombat` (turns, HP, death saves, concentration, conditions, order), `useEncounterFile` (save/open,
  browser storage, unsaved changes, links), `useLiveSync` (player view), all put together with the screen's own
  state by `useTracker`. The page calls `provideTracker(props)`; its parts in `components/tracker`
  (EncounterBar, TurnBar, InitiativeList, CombatantPanel…) take what they need with `useTrackerContext()`.
  - Its props are the saved encounters as ids and names only, plus one full `openEncounter` (from
    `?encounter=` or the most recent). Opening another one reloads only that prop. It also gets the DM's
    `campaigns` (with party ids, for the campaign picker and "Add party") and `newInCampaign`
    (`?new_in_campaign=`, from a campaign's New encounter button). The query is cleared once acted on.
  - The fight in progress is kept in `localStorage` ([resources/js/lib/trackerStorage.ts](resources/js/lib/trackerStorage.ts)),
    keyed per user or guest. When a guest registers, their fight is copied into the new account
    ([app/Actions/ImportGuestEncounter.php](app/Actions/ImportGuestEncounter.php)).
  - Every change to the fight goes through `change(label, fn)` so it can be undone, and records history with `addLog()`.
    Undo steps don't copy the history; they remember the newest entry. The exceptions clear the history and pass
    `replacesLog`: End combat (back to setup; HP, conditions and per-day uses carry on, per-turn/round/encounter
    uses come back) and Reset (as if the fight never happened). History types and wording are in [resources/js/lib/combatLog.ts](resources/js/lib/combatLog.ts).
  - During combat the order belongs to the DM: sort only on roll, Start combat or Sort, and keep the turn with
    whoever holds it (`keepingTurn`).
- Shared helpers:
  - [resources/js/lib/encounter.ts](resources/js/lib/encounter.ts): conditions (with icons), sides, dice, initiative
    (the creature's bonus, else DEX; ties go to the higher bonus), limits, and fight-state checks (`isOut`, `isDead`…).
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
