# Cap/visit activity links

The tracker uses the same `activity_id` columns, unique indexes and foreign keys as RS3-API. Both live polling and catch-up processing retain the original cap/visit record when its source activity is replayed, including after citadel reset changes. The existing one-record-per-member-per-cap-week constraint is retained.

## Existing database deployment

Pause cap/visit processors (including RS3-API workers if the database is shared), then run from the project root with the usual `TRACKER_DB_*` environment configuration:

```sh
php scripts/migrate_citadel_activity_links.php
```

Deploy the updated processors before resuming them. The migration also runs through `dbb_bootstrap_schema()` for installations using schema bootstrap. It is safe to rerun against a database already migrated by RS3-API.

Historical rows are linked only when member, clan, exact activity timestamp and rule purpose identify one source activity. Duplicate rows linked to that same activity are removed, keeping the earliest row ID. Unmatched or ambiguous history is retained with a null activity link. Deleting a source activity preserves the cap/visit history and clears its link.

## Regression test

Use a disposable MySQL/MariaDB server with permission to create databases:

```sh
CITADEL_TEST_DSN='mysql:unix_socket=/path/to/test.sock' php tests/citadel_activity_links.php
```

Optional credentials: `CITADEL_TEST_USER` and `CITADEL_TEST_PASSWORD`. The test creates and drops its own randomly named database. It covers reset changes, repeated polling, catch-up processing, weekly uniqueness, legacy duplicate cleanup, migration reruns, new weeks, source deletion and persistence of DST week boundaries.

## Guest citadel exclusion

Members with a `Guest` rank (case-insensitive, ignoring surrounding spaces) are excluded from cap/visit credit in live polling and catch-up processing. Their personal activities still receive rules, so catch-up does not repeatedly select them, but guest citadel activities are marked consumed with `is_announced = 1` and no announcement timestamp. Other guest activities and XP tracking continue normally. Guests cannot trigger cap-based rank-up checks.

The roster keeps guests visible with a **Not tracked** badge and excludes them from capping filters, uncapped counts and capping percentages. Player cap/visit status, cap history and weekly charts exclude existing guest credit using the current stored rank. Stored historical rows are not deleted; former members with non-guest ranks retain their chart history. No schema migration is required. If RS3-API workers share the database, their processors must also apply the guest exclusion; tracker changes do not update external workers.

```sh
CITADEL_TEST_DSN='mysql:unix_socket=/path/to/test.sock' php tests/citadel_guests.php
```

Uses the same disposable database setup and optional credentials as the activity-link test. Covers mixed-case guest ranks, normal and unranked members, replay, catch-up queue consumption, announcement suppression, rank-up guards, legacy guest records, guest-only clans and the real clan/player/history API responses. Player requests defer external refreshes, so no RuneScape or Discord calls are made.

## Timezone regression test

```sh
php tests/timezone_windows.php
```

No database is required. Covers Sydney, London, New York and Lord Howe DST
transitions, zones without DST, reset boundaries in skipped/repeated hours,
adjacent XP weeks and rank-up guard boundaries. Weekly resets use the clan's
local calendar; their UTC duration can change at DST transitions. Configure
`clans.timezone` with an IANA identifier such as `Australia/Sydney` when seasonal
changes are required. Fixed offsets such as `+10:00` do not follow DST.

These changes apply when calculating week boundaries; they do not rewrite
historical cap/visit rows or activity timestamps.
