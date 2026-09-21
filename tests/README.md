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

Optional credentials: `CITADEL_TEST_USER` and `CITADEL_TEST_PASSWORD`. The test creates and drops its own randomly named database. It covers reset changes, repeated polling, catch-up processing, weekly uniqueness, legacy duplicate cleanup, migration reruns, new weeks and source deletion.
