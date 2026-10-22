# Changelog

Cadence — see a member's rhythm, not just their volume.

## [1.2.3] — 2026-10-22

### Fixed

- **Enabling Cadence on a big forum timed out** (a Cloudflare 502 on a forum
  of 568k posts). The history rebuild ran inside the "enable" click; now
  enabling returns at once and the rebuild runs in the background — on the
  queue if there is one, otherwise every minute from the scheduler, and while
  the settings page is open, which shows its progress. A rebuild that was
  interrupted, including a failed enable on 1.2.0–1.2.2, finishes on the next
  enable and repairs what was left half-written.
- **The rebuild is much faster.** On 500,000 posts and 15,000 members the
  whole rebuild went from 85 seconds to about 12, and the flames alone from 53
  seconds to 2, with identical results.
- **An empty column beside the discussion list with FoF Widgets.** Cadence's
  widgets were shown in FoF's right-hand column on every forum with
  fof/forum-widgets-core, could not be removed in its layout editor, and showed
  an empty box where a widget had nothing for that visitor. They now appear in
  the layout editor's available widgets and show only where you place them,
  only while Daily streaks is on, and only when they have something to show.
  If you used them, drag them into place again and save.

### Added

- `php flarum cadence:rebuild-streaks`: rebuild every flame with a progress
  bar; resumes an unfinished rebuild (`--fresh` starts over).

## [1.2.2] — 2026-10-21

### Fixed

- **Turning streaks off is one obvious switch.** With *Daily streaks* off,
  every other streak setting is hidden and the page says streaks are off, so
  nobody is left switching placements off one by one while a Streaks link
  stays behind. Nothing about streaks reaches the forum while it is off —
  now including the once-a-day check-in, which no longer records a timezone.

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
