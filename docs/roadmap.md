# Roadmap

Decisions agreed for the next stretch of work, in build order. Update this as things ship or change.

## 1. Plans and limits (done)

- Every account has a plan: `free` or `pro`. Subscriptions stay hidden for now, with no prices or upgrade
  prompts anywhere in the app. Stripe can come later and just switch the plan.
- Limits live in [config/plans.php](../config/plans.php) so they're easy to change. Free plan:

  | What | Free limit |
  |---|---|
  | Homebrew creatures and player characters | 25 |
  | Saved encounters | 10 |
  | Campaigns you run | 2 |
  | Players per campaign | 6 |
  | Campaigns you've joined as a player | 3 |

- Pro is effectively unlimited for now, with high safety caps, until pricing is decided.
- At a limit, the action is refused with a friendly message and no prices, e.g. "You've reached the free
  limit of 25 creatures. Delete one to make room."
- Plans are switched with an artisan command (for the owner and friends testing): `php artisan plan:set`.

## 2. Campaigns (done)

- Player accounts are normal accounts: anyone can run their own campaigns and also play in other people's.
- The DM creates a campaign (name, description; images later), builds the party's player characters,
  and puts encounters in the campaign. The tracker gets an "Add party" button.
- Players join through an invite link (sign up or log in, then join) and claim one of the characters the
  DM made.
- Players see the campaign page (details, the party, later the DM's images) and the active combat.
- Per-campaign setting for what players see of enemy HP: bands by default (Healthy, Bloodied, Down),
  exact numbers, or nothing.

## 3. Player view

- Players see a campaign's encounter while it's in active combat: from Start combat until combat ends
  (a new End combat button, or Reset). Setup is never shown.
- The tracker's Player view button switches the DM's screen to what players see. Opened in a second
  window (e.g. on a TV), it updates live as the DM runs the fight.
- Outside campaigns, and for guests, Player view works in a second window on the same computer, with no
  login or server needed. Players on their own devices need a campaign.
- Hidden combatants never reach players' devices: the server removes them (and applies the HP setting)
  before anything is broadcast. Live updates go over Reverb.
- View-only for now. Players entering initiative and updating their own character comes later.

## 4. Export and import

- For backups of your own creatures and encounters, as a JSON file in our own format. No importing from
  other sites.
- Guests can export a fight but not import (importing saves to an account).

## Later

- Players controlling their own character from their phone (initiative, HP, conditions).
- Campaign images, session notes, and a campaign-wide history (move the encounter `log` to its own
  `encounter_events` table at that point).
- Subscriptions with Stripe (Laravel Cashier), a pricing page and a public landing page.
- Encounter difficulty (2014/2024 5e XP budgets) as a 5e rules option.
