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
midnight/weekday rollover, adjacent XP weeks and rank-up guard boundaries.
`clans.reset_weekday` and `clans.reset_time` are stored in the clan's **standard
time**, without DST. The reset instant stays on that schedule throughout the year;
only its displayed local time changes. For example, Sydney `20:00` means
`20:00 AEST` in winter and `21:00 AEDT` in summer. Lord Howe's adjustment is
30 minutes. The countdown, cap/visit processors and XP/rank-up windows all use
these same boundaries. Configure
`clans.timezone` with an IANA identifier such as `Australia/Sydney` when seasonal
changes are required. Fixed offsets such as `+10:00` do not follow DST.

These changes apply when calculating week boundaries; they do not rewrite
historical cap/visit rows or activity timestamps. Existing rows assigned using the
old daylight-adjusted wall-clock convention retain their stored week keys. Deploy
this helper with all cap/visit processors; external RS3-API workers sharing the
database must use the same standard-time convention.

## Missing activity icons

Player-feed images now use `api/activity_icon.php?id=<stored activity ID>`. That
endpoint and `scripts/check_activity_icons.php` use the same PHP resolver and
existing activity, boss, item-alias and item-cleanup JSON files. Local uploaded
icons take priority over Wiki downloads. A successful HTTP response containing
either bundled default image is still a fallback; copied defaults and invalid
image files are also rejected. Other tracker screens retain their existing icon
selection.

Wiki item lookups search file titles without regard to capitalisation. Names must
match the complete sequence of words (including numbers and qualifiers), ignoring
separators such as spaces and underscores and normalising curly apostrophes.
The resolver prefers `{item name} detail` and falls
back to the standard image; existing aliases ending in `Detail` also work. Search
results supply the actual filename and image URL, so mixed-case requests do not
depend on guessing the Wiki's capitalisation. Direct filenames remain a fallback
if search is unavailable. Search and downloads share the existing five-second
budget, and API errors remain retryable rather than confirming a missing item.
Item cache keys now share case/spacing variants and use a new version so existing
standard-image or negative caches are refreshed on the next lookup. Local uploads
and explicit activity mappings retain priority.

The shared database stores:

- `activity_icon_issues`: one issue per tracker source and normalized icon identity,
  with examples, lookup attempts, distinct activity count, timestamps, and
  `open`, `retrying`, or `resolved` status.
- `activity_icon_sightings`: unique issue/activity pairs, so repeated views,
  worker runs and regressions never inflate the count.
- `activity_icon_checks`: which stored activities each tracker has scanned.
- `activity_icon_workers`: last run and failure information for the admin page.

Matched activity mappings are grouped by their rule key. Unclassified activity
text and details retain numbers and wording so fixing one variant cannot hide
another. Item identities retain their source name independently of editable aliases.
No activity history is deleted or modified. Resolved issues remain available for
history and reopen if their icon falls back again.

### Deployment

1. Deploy all changed ClanTracker files together: the PHP helpers, endpoints and
   worker, including `api/_db.php`, the updated player API, `app.js`, `index.php`
   and `db_bootstrap.php`. Deploy
   `admin/missing_icons.php`, `admin/includes/layout.php`,
   `functions/icon_issues.php` and `setup/db_bootstrap.php` in RS3-API. Both apps
   must connect to the same database. Keep the two `icon_issues.php` copies identical.
2. Set a stable `TRACKER_ICON_SOURCE` in the tracker environment (default
   `tracker`). Give deployments with different icon assets different source
   names. The worker uses `TRACKER_CLAN_ID` when configured; otherwise it scans
   all clans. Use the same source/clan environment for PHP web requests and cron.
3. Create the additive tables with the regular tracker DB environment:

   ```sh
   php scripts/check_activity_icons.php --setup-only
   ```

   Both projects' normal schema bootstrap also ensures these tables. Do not use
   `BOOTSTRAP_FORCE`. No existing activity data needs migration. The PHP account
   must be able to write `cache/item-icons` and `cache/mapped-icons`.
4. Add a server cron job on the **tracker host**, where its actual assets and
   mappings live. For example, replacing the PHP, project and log paths:

   ```cron
   * * * * * /path/to/php /path/to/ClanTracker/scripts/check_activity_icons.php --limit=100 --seconds=45 >> /path/to/icon-checks.log 2>&1
   ```

   The worker is CLI-only and runs bounded batches with a per-source database
   lock. It scans previously unseen stored activities, including history and
   activities fetched by RS3-API, without waiting for profile views. Run the same
   command manually to start the initial backfill. The time budget stops between
   lookups; a lookup already in progress is allowed to finish.
5. Open **Missing Icons** in RS3 API admin. Filter by status, category, tracker or
   text, and sort by frequency or age. **Recheck** and **Recheck all unresolved**
   queue work for the next tracker run; they do not fetch images on the admin host.

Missing assets are rechecked every six hours; transient network failures retry
after five minutes. Explicit rechecks bypass the negative download cache.
Resolved issues are checked daily for regressions. A confirmed real icon removes
the issue from the default unresolved view on its next matching feed/worker
check. Newly introduced mapping rules can change an issue's identity; the queued
or periodic recheck of its saved sample resolves the older entry as well.
Cached valid Wiki images remain usable during upstream outages. Remote image
URLs in the administrator-maintained maps are cached and validated too; redirects
must stay on the configured host. Local SVG and raster images are supported;
remote images must be decodable raster images.

Failed issue logging never hides an otherwise valid feed image. The admin page
uses the existing login, CSRF protection and audit log, and indicates missing or
overdue background runs. Feed requests can only inspect stored activity IDs
within the configured clan; they cannot submit arbitrary issue records or URLs.

### Regression checks

```sh
php tests/activity_icons.php
ICON_TEST_DSN='mysql:unix_socket=/path/to/test.sock' php tests/icon_issues.php
```

The first test uses temporary files and simulated Wiki responses (no network).
The second creates and drops its own database on a disposable MySQL/MariaDB
server. Optional credentials are `ICON_TEST_USER` and `ICON_TEST_PASSWORD`.
It covers repeatable schema setup, deduplication, resolution, reopening, transient
failures, source isolation, concurrent rechecks, background scanning, deleted
source activities and overlapping workers.
