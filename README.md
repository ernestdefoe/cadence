# Cadence

**See a member's rhythm, not just their volume.**

An activity map on every profile — and, if you want it, a compact trace beside
every post and on user cards.

[![Flarum](https://img.shields.io/badge/Flarum-2.0-orange)](https://flarum.org)
[![Licence](https://img.shields.io/badge/licence-MIT-blue)](LICENSE)

---

## Why not just copy GitHub's graph

Because GitHub's has six problems, and on a forum they matter more than they do
on a code host.

- **It counts one undifferentiated thing.** Cadence separates discussions,
  replies, likes, reactions and best answers, and only offers the kinds a member
  actually has.
- **Its scale is relative to each person's own busiest day**, so someone with
  two posts all year and someone with two hundred produce similar-looking
  graphs. Cadence uses an absolute scale with a legend that states real numbers,
  so two profiles can be compared.
- **It shows a fixed year.** Someone who joined last month gets eleven months of
  grey, which reads as "inactive" when the truth is "new". Cadence starts at the
  join date.
- **Colour is the only encoding.** Cadence's levels differ in lightness as well
  as hue, and every square carries its real numbers for screen readers.
- **It's decoration.** Cadence's squares are buttons: press one and it tells you
  what happened that day.
- **It can't tell you when someone is around.** Cadence stores hourly, so a day
  is the *reader's* day rather than the server's.

## Where it shows

| Placement | Default |
| --- | --- |
| The full map on member profiles | On |
| A 26-week sparkline beside every post | Off |
| A sparkline on user cards | Off |

🚨 **The sparklines cost no extra requests.** They read an attribute that rides
along on the user the page had already loaded. A placement that appears once per
post cannot fetch its own data — thirty posts would be thirty requests, which is
how a shared host runs out of database connections and returns 500 for the whole
forum rather than for the decoration that caused it.

## Installation

```bash
composer require ernestdefoe/cadence
php flarum cache:clear
php flarum cadence:rebuild
```

That last command is worth running once. Cadence maintains its counts from
events as they happen, so on a forum with existing history it starts empty until
you rebuild — and a map that tells every member they have never done anything is
worse than no map.

```bash
php flarum cadence:rebuild
```

Rebuilds every bucket from your posts and likes. Safe to re-run.

🚨 **Reactions and best answers cannot be rebuilt** — neither records *when* it
happened, so those two accumulate from the day you install rather than
backfilling. Posts and likes rebuild exactly.

## Works alongside Calendar

`ernestdefoe/calendar` has a profile heatmap of its own. When Cadence is
installed and showing on profiles, Calendar stands its own one down, so nobody
ends up with two. Turn Cadence's profile placement off and Calendar's comes back.

## How it stays cheap

Activity is rolled up into one row per member, per hour, per kind. A profile
view is an indexed range scan over that member's own rows — it never aggregates
over `posts`, which on a forum of any size is a table scan per profile visit.

Hourly rather than daily is what lets the day boundary be decided when the map
is *read*, in the reader's timezone, instead of being baked into the data
forever at the server's.

## Requirements

- Flarum 2.0
- PHP 8.3+

## Licence

MIT.
