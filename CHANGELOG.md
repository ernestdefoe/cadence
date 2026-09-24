# Changelog

Cadence — see a member's rhythm, not just their volume.

## [Unreleased]

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
