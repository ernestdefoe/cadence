# Changelog

Cadence — see a member's rhythm, not just their volume.

## [1.2.1] — 2026-10-20

### Fixed

- **Updating from 1.1.x failed** with "Unknown column 'day_ends_at'": the
  history rebuild ran before the migration that adds the column it writes.
  The column now comes with the streaks table; forums already on 1.2.0 get it
  added as before.

## [1.2.0] — 2026-10-20

### Added

- **Daily streaks.** A flame for every member who keeps coming back, fed once
  a day in the member's own timezone. The admin chooses what keeps it alight:
  contributing (posting, replying, liking, reacting) or visiting.
- **Built from your history.** On install, every member starts with the streak
  their past activity already earned, and runs that already ended fill the
  Hall of Flames.
- **Tiers and seasons** you design: days, colour, icon and glow, with a live
  preview, plus seasonal flames between dates you pick and a golden flame on
  birthdays from Calendar.
- **Freezes, grace and embers.** Milestones earn freezes that melt on their own
  to save a missed day; a long flame that goes out leaves embers, and a post
  within the window brings half of it back.
- **Everywhere people look:** a flame after names in posts, a streak line on
  user cards, a header flame with the month one tap away, a Streak tab on
  profiles (card, year, calendar, journal) and a /streaks leaderboard with
  Current, All-time and Hall of Flames, your rank, the rival just ahead and
  the community bonfire.
- **Widgets** (My flame, Top flames, Bonfire) for Bespoke and
  fof/forum-widgets-core, with an optional stock-sidebar fallback.
- **Alerts:** milestones, tiers, records, saved and revived flames as alerts
  and as a toast on the first page of the day (every day, important moments
  or never — each member's choice), and an "at risk" / "down to embers" alert
  and email from the hourly `cadence:streaks:settle` job.
- **Admin:** an overview (key figures, flames by tier, longest alight, recent
  events) and a flame sheet to give freezes, set, revive or reset any
  member's streak — every change journaled with who made it.
- `cadence:streaks:rebuild`; `cadence:rebuild` now rebuilds streaks too.

## [1.0.0] — 2026-09-23

Initial release.

### Added

- **An activity map on member profiles**, with an absolute scale, a window that
  starts at the join date, a switcher for activity kind, and days you can press
  to see what happened.
- **A 26-week sparkline** for posts and user cards, off by default, costing no
  extra requests.
- **`cadence:rebuild`** to backfill from existing posts and likes.
- **Defers to `ernestdefoe/calendar`'s heatmap** rather than stacking a second
  map on the same profile.
